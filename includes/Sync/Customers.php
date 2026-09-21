<?php
/**
 * Customers Sync Handler
 *
 * @package Alegra\Connector\Sync
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Customers
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function sync_to_alegra(\WP_User $customer): array|\WP_Error
    {
        $alegra_id = get_user_meta($customer->ID, 'alegra_contact_id', true);

        $data = $this->prepare_customer_data($customer);

        if (!empty($alegra_id)) {
            $result = $this->api->update_contact((string) $alegra_id, $data);
            // Write Gate: nothing reached Alegra. Return the marker without a
            // false "updated" log.
            if (API\Client::write_was_blocked($result)) {
                return $result;
            }
            $this->logger->info('Customer updated in Alegra', [
                'customer_id' => $customer->ID,
                'alegra_id' => $alegra_id,
            ]);
            return $result;
        }

        // No linked Alegra ID - check for duplicates by email/NIT
        $existing_alegra_id = $this->find_existing_contact($data);

        if ($existing_alegra_id) {
            $result = $this->resolve_duplicate($existing_alegra_id, $data, $customer);
        } else {
            $result = $this->api->create_contact($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_user_meta($customer->ID, 'alegra_contact_id', $result['id']);
                \Alegra\Connector\Entity_Map::map('contact', (string) $result['id'], 'customer', (int) $customer->ID);
                $this->logger->info('Customer created in Alegra', [
                    'customer_id' => $customer->ID,
                    'alegra_id' => $result['id'],
                ]);
            }
        }

        return $result;
    }

    private function resolve_duplicate(string $alegra_id, array $data, \WP_User $customer): array|\WP_Error
    {
        $conflict_resolution = get_option('alegra_connector_conflict_resolution', 'alegra_wins');

        // Always link the WC customer to the existing Alegra contact
        update_user_meta($customer->ID, 'alegra_contact_id', $alegra_id);
        \Alegra\Connector\Entity_Map::map('contact', (string) $alegra_id, 'customer', (int) $customer->ID);

        if ($conflict_resolution === 'woocommerce_wins') {
            $result = $this->api->update_contact($alegra_id, $data);
            if (API\Client::write_was_blocked($result)) {
                return $result;
            }
            $this->logger->info('Duplicate customer: WC data overwrote Alegra', [
                'customer_id' => $customer->ID,
                'alegra_id' => $alegra_id,
            ]);
        } else {
            // alegra_wins (default): fetch full Alegra data and pull it into WC
            $alegra_contact = $this->api->get_contact($alegra_id);
            if (!is_wp_error($alegra_contact) && is_array($alegra_contact)) {
                $this->update_customer_from_alegra($customer->ID, $alegra_contact);
            }
            $this->logger->info('Duplicate customer: Alegra data overwrote WC', [
                'customer_id' => $customer->ID,
                'alegra_id' => $alegra_id,
            ]);
            $result = ['id' => $alegra_id, 'linked' => true];
        }

        return $result;
    }

    private function find_existing_contact(array $data): ?string
    {
        // Try to find by email first. AC-50: `email` is not a documented
        // listContacts filter, so use `query` and verify EVERY candidate
        // (never trust the first row when the filter may be ignored).
        $email = $data['email'] ?? '';
        if (!empty($email)) {
            $contacts = $this->api->get_contacts(['query' => $email, 'limit' => 30]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                $matches = [];
                foreach ($contacts as $contact) {
                    if (isset($contact['id'], $contact['email']) && strcasecmp((string) $contact['email'], $email) === 0) {
                        $matches[] = (string) $contact['id'];
                    }
                }
                // Only link when the match is unambiguous; otherwise fall
                // through to the identification lookup below.
                if (count($matches) === 1) {
                    return $matches[0];
                }
            }
        }

        // Try by identification (NIT/RFC) as fallback.
        // 2.3.0: prepare_customer_data() returns the Billing_Fields payload, which
        // nests the document as identificationObject.{type,number,dv} with no
        // top-level `identification`. Read BOTH shapes so NIT dedup keeps working.
        $identification = '';
        if (!empty($data['identification'])) {
            $identification = (string) $data['identification'];
        } elseif (!empty($data['identificationObject']['number'])) {
            $identification = (string) $data['identificationObject']['number'];
        }

        $id_type = '';
        if (!empty($data['identificationObject']['type'])) {
            $id_type = strtoupper((string) $data['identificationObject']['type']);
        }

        if ($identification !== '') {
            $contact = null;

            // Prefer the typed lookup when we know the document type.
            if ($id_type !== '') {
                $dv = !empty($data['identificationObject']['dv'])
                    ? (string) $data['identificationObject']['dv']
                    : null;
                $contact = $this->api->find_contact_by_identification($id_type, $identification, $dv);
            }

            // Fallback / legacy: plain identification search.
            if ($contact === null) {
                $contacts = $this->api->get_contacts(['identification' => $identification, 'limit' => 30]);
                if (!is_wp_error($contacts) && !empty($contacts)) {
                    foreach ($contacts as $c) {
                        $number = '';
                        if (!empty($c['identification'])) {
                            $number = (string) $c['identification'];
                        } elseif (!empty($c['identificationObject']['number'])) {
                            $number = (string) $c['identificationObject']['number'];
                        }
                        if ($number === $identification) {
                            $contact = $c;
                            break;
                        }
                    }
                }
            }

            if (is_array($contact) && isset($contact['id'])) {
                return (string) $contact['id'];
            }
        }

        return null;
    }

    public function sync_all(): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0, 'pages' => 0];
        $page = 1;
        $per_page = 100;

        while (true) {
            $args = [
                'role' => 'customer',
                'orderby' => 'ID',
                'order' => 'ASC',
                'number' => $per_page,
                'paged' => $page,
            ];

            $customers = get_users($args);

            if (empty($customers)) break;

            foreach ($customers as $customer) {
                $sync_result = $this->sync_to_alegra($customer);
                if (is_wp_error($sync_result)) {
                    $result['errors']++;
                    $this->logger->error('Failed to sync customer', [
                        'customer_id' => $customer->ID,
                        'error' => $sync_result->get_error_message(),
                    ]);
                } else {
                    $result['synced']++;
                }
            }

            $result['pages'] = $page;

            if (count($customers) < $per_page) break;
            $page++;
        }

        $this->logger->info('Customers sync completed', $result);

        return $result;
    }

    public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array|\WP_Error
    {
        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Customers import skipped: kill switch active');
            return new \WP_Error('kill_switch_active', __('El plugin está desconectado o desactivado', 'alegra-connector'));
        }

        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public('customers');
        if ($lock === false) {
            $this->logger->info('Customers import skipped: another sync is running');
            return new \WP_Error('sync_in_progress', __('Ya hay una sincronización en curso.', 'alegra-connector'));
        }

        try {
            // BUG 4: `skipped` is tracked separately from `errors`. A contact
            // without an email is a legitimate skip (WooCommerce requires an
            // email), not an error; counting it as an error inflated the count
            // the merchant sees with no explanation.
            $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
            $current_page = $page;
            $max_pages = 200;
            set_time_limit(300);

            for ($p = 1; $p <= $max_pages; $p++) {
                if (get_transient('alegra_sync_cancelled')) {
                    delete_transient('alegra_sync_cancelled');
                    $this->logger->info('Customers import cancelled by user');
                    break;
                }

                // Re-check the kill switch every page so an in-flight run stops.
                if (\Alegra\Connector\Kill_Switch::is_active()) {
                    $this->logger->info('Customers import stopped: kill switch active');
                    break;
                }

                // Per-run stop (Monitor "Detener").
                if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                    $this->logger->info('Customers import stopped by user');
                    break;
                }

                // AC-83: throttle the progress write (every 5th page) and use a
                // TTL longer than a run so the admin UI never sees it expire
                // mid-import.
                if ($p === 1 || $p % 5 === 0) {
                    set_transient('alegra_sync_progress', [
                        'type' => 'customers',
                        'current_page' => $p,
                        'items_processed' => $result['imported'] + $result['updated'] + $result['skipped'] + $result['errors'],
                        'imported' => $result['imported'],
                        'updated' => $result['updated'],
                        'message' => sprintf(__('Procesando clientes... Página %d', 'alegra-connector'), $p),
                    ], 600);
                }

                $alegra_contacts = $this->api->get_contacts([
                    'start' => ($current_page - 1) * $per_page,
                    'limit' => $per_page,
                    'type' => 'client',
                ]);

                if (is_wp_error($alegra_contacts)) {
                    delete_transient('alegra_sync_progress');
                    if ($current_page === 1) return $alegra_contacts;
                    break;
                }

                if (empty($alegra_contacts)) break;

                foreach ($alegra_contacts as $contact) {
                    if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                        $this->logger->info('Customers import stopped by user mid-page');
                        break 2;
                    }
                    $r = $this->import_single_contact($contact);
                    if ($r === true) $result['imported']++;
                    elseif ($r === 'updated') $result['updated']++;
                    elseif ($r === 'skipped') $result['skipped']++;
                    else $result['errors']++;
                }

                if (count($alegra_contacts) < $per_page) break;
                $current_page++;
            }

            set_transient('alegra_sync_progress', [
                'type' => 'customers',
                'current_page' => $current_page,
                'items_processed' => $result['imported'] + $result['updated'] + $result['skipped'] + $result['errors'],
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
                'done' => true,
                'message' => sprintf(
                    __('Completado: %d importados, %d actualizados, %d omitidos, %d errores', 'alegra-connector'),
                    $result['imported'],
                    $result['updated'],
                    $result['skipped'],
                    $result['errors']
                ),
            ], 60);

            $this->logger->info('Customers import from Alegra completed', $result);

            return $result;
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public('customers', $lock);
        }
    }

    private function import_single_contact(array $contact): bool|string
    {
        $alegra_id = $contact['id'];
        $email = $contact['email'] ?? '';

        if (empty($email)) {
            $this->logger->info('Contact without email skipped (required for WooCommerce)', ['alegra_id' => $alegra_id, 'name' => $this->contact_display_name($contact)]);
            return 'skipped';
        }

        // Prevent re-entrant sync: skip WC→Alegra hooks while we're importing
        $was_syncing = \Alegra\Connector\Public\Public_::is_syncing();
        if (!$was_syncing) {
            \Alegra\Connector\Public\Public_::set_syncing(true);
        }

        try {
            $existing_user_id = email_exists($email);

            // BUG 5: also match by identification before creating a user. A
            // contact whose email changed (or a customer who registered with a
            // different email but the same cédula) would otherwise create a
            // duplicate WC user AND a duplicate link.
            if (!$existing_user_id) {
                $identification = $this->contact_identification($contact);
                if ($identification !== '') {
                    $existing_user_id = $this->find_user_by_identification($identification);
                }
            }

            if ($existing_user_id) {
                update_user_meta($existing_user_id, 'alegra_contact_id', $alegra_id);
                // AC-07: indexed mapping at link time.
                \Alegra\Connector\Entity_Map::map('contact', (string) $alegra_id, 'customer', (int) $existing_user_id);
                $this->update_customer_from_alegra($existing_user_id, $contact);
                $this->populate_wc_lookup($existing_user_id);
                return 'updated';
            }

        $name_parts = $this->parse_name($this->contact_display_name($contact));

        $user_id = wp_insert_user([
            'user_email' => $email,
            'user_login' => $email,
            'first_name' => $name_parts['first'],
            'last_name' => $name_parts['last'],
            'role' => 'customer',
        ]);

        if (is_wp_error($user_id)) {
            $this->logger->error('Failed to create customer from Alegra', [
                'alegra_id' => $alegra_id,
                'error' => $user_id->get_error_message(),
            ]);
            return false;
        }

        update_user_meta($user_id, 'alegra_contact_id', $alegra_id);
        \Alegra\Connector\Entity_Map::map('contact', (string) $alegra_id, 'customer', (int) $user_id);
        $this->update_customer_from_alegra($user_id, $contact);
        $this->populate_wc_lookup($user_id);

        $this->logger->info('Customer imported from Alegra', [
            'alegra_id' => $alegra_id,
            'user_id' => $user_id,
        ]);

        return true;
        } finally {
            // Release re-entrancy guard if we set it
            if (!$was_syncing) {
                \Alegra\Connector\Public\Public_::set_syncing(false);
            }
        }
    }

    private function update_customer_from_alegra(int $user_id, array $contact): void
    {
        $address = $contact['address'] ?? [];

        if (!empty($address['address'])) {
            update_user_meta($user_id, 'billing_address_1', $address['address']);
            update_user_meta($user_id, 'shipping_address_1', $address['address']);
        }

        if (!empty($address['city'])) {
            update_user_meta($user_id, 'billing_city', $address['city']);
            update_user_meta($user_id, 'shipping_city', $address['city']);
        }

        $phone = $contact['phonePrimary'] ?? $contact['mobile'] ?? $contact['phoneSecondary'] ?? '';
        if (!empty($phone)) {
            update_user_meta($user_id, 'billing_phone', $phone);
        }

        if (!empty($contact['identification'])) {
            update_user_meta($user_id, 'billing_nit', $contact['identification']);
        }

        if (isset($contact['observations'])) {
            update_user_meta($user_id, 'alegra_notes', $contact['observations']);
        }

        // Mirror the identification Alegra returns. Normalize enums to uppercase.
        $id_obj = $contact['identificationObject'] ?? null;
        if (is_array($id_obj)) {
            if (!empty($id_obj['type'])) {
                update_user_meta($user_id, 'billing_alegra_idtype', strtoupper((string) $id_obj['type']));
            }
            if (!empty($id_obj['number'])) {
                update_user_meta($user_id, 'billing_alegra_identification', (string) $id_obj['number']);
            }
            if (!empty($id_obj['dv'])) {
                update_user_meta($user_id, 'billing_alegra_dv', (string) $id_obj['dv']);
            }
        }

        // BUG 6: `conflict_resolution = alegra_wins` must actually pull the
        // IDENTITY (name + email), not just the address/phone/document.
        $name = $this->contact_display_name($contact);
        if ($name !== '') {
            $parts = $this->parse_name($name);
            update_user_meta($user_id, 'billing_first_name', $parts['first']);
            update_user_meta($user_id, 'billing_last_name', $parts['last']);
            if (function_exists('wp_update_user')) {
                wp_update_user([
                    'ID'           => $user_id,
                    'first_name'   => $parts['first'],
                    'last_name'    => $parts['last'],
                    'display_name' => $name,
                ]);
            }
        }

        $email = (string) ($contact['email'] ?? '');
        if ($email !== '' && (!function_exists('is_email') || is_email($email))) {
            // Never steal another user's email. When it is free (or already
            // ours) sync it; user_login is intentionally left untouched.
            $owner = function_exists('email_exists') ? email_exists($email) : false;
            if (!$owner || (int) $owner === $user_id) {
                update_user_meta($user_id, 'billing_email', $email);
                if (function_exists('wp_update_user')) {
                    wp_update_user(['ID' => $user_id, 'user_email' => $email]);
                }
            }
        }
    }

    /**
     * Extract the identification number from an Alegra contact (structured or
     * legacy flat shape).
     */
    private function contact_identification(array $contact): string
    {
        $id_obj = $contact['identificationObject'] ?? null;
        if (is_array($id_obj) && isset($id_obj['number']) && (string) $id_obj['number'] !== '') {
            return (string) $id_obj['number'];
        }

        return (string) ($contact['identification'] ?? '');
    }

    /**
     * Find a WC user whose identification meta matches, so an import never
     * creates a duplicate user when only the email changed.
     *
     * Checks the new `billing_alegra_identification` key first, then the legacy
     * `billing_nit` key. Returns 0 when no user matches.
     */
    private function find_user_by_identification(string $identification): int
    {
        if ($identification === '') {
            return 0;
        }

        foreach (['billing_alegra_identification', 'billing_nit'] as $meta_key) {
            $users = get_users([
                'meta_key'   => $meta_key,
                'meta_value' => $identification,
                'number'     => 1,
                'fields'     => 'ID',
            ]);
            if (empty($users)) {
                continue;
            }

            $first = $users[0];
            $id = is_object($first) ? (int) ($first->ID ?? 0) : (int) $first;
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Resolve a display name from an Alegra contact.
     *
     * Alegra is inconsistent across surfaces: the webhook payload documents
     * `name` as an OBJECT ({firstName, secondName, lastName, secondLastName}
     * or {fullname}), the contacts API historically returned a flat string
     * `name`, and Colombia uses `nameObject`. Accept every shape so a real
     * delivery never triggers an "Array to string conversion" warning (or a
     * TypeError from parse_name()).
     */
    private function contact_display_name(array $contact): string
    {
        foreach (['name', 'nameObject'] as $key) {
            $value = $contact[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            } elseif (is_array($value)) {
                $flat = $this->flatten_name_object($value);
                if ($flat !== '') {
                    return $flat;
                }
            }
        }

        return '';
    }

    /**
     * Flatten an Alegra name object into a single string.
     *
     * Prefers `fullname` when present (docs: "puede contener solamente
     * fullname"); otherwise joins the documented name parts.
     *
     * @param array<string, mixed> $obj
     */
    private function flatten_name_object(array $obj): string
    {
        $full = trim((string) ($obj['fullname'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        $parts = array_filter([
            (string) ($obj['firstName'] ?? ''),
            (string) ($obj['secondName'] ?? ''),
            (string) ($obj['lastName'] ?? ''),
            (string) ($obj['secondLastName'] ?? ''),
        ], static fn($v) => $v !== '');

        return trim(implode(' ', $parts));
    }

    private function prepare_customer_data(\WP_User $customer): array
    {
        if (class_exists('\Alegra\Connector\Billing_Fields')) {
            $payload = \Alegra\Connector\Billing_Fields::build_contact_payload($customer);
            if (!is_wp_error($payload)) {
                return $payload;
            }

            // Minimal payload for customers without billing data. The invoice
            // flow uses Consumidor Final; this keeps the customer-sync flow
            // working for legacy/partial records. The shape is owned by
            // Billing_Fields so there is a single contact-payload builder.
            return \Alegra\Connector\Billing_Fields::build_minimal_contact_payload($customer);
        }

        return [
            'name'     => $customer->display_name ?: $customer->user_email,
            'email'    => $customer->user_email,
            'type'     => 'client',
        ];
    }

    private function parse_name(string $full_name): array
    {
        $parts = explode(' ', $full_name, 2);
        return [
            'first' => $parts[0] ?? '',
            'last' => $parts[1] ?? '',
        ];
    }

    public function delete_from_alegra(int $customer_id): array|\WP_Error
    {
        $alegra_id = get_user_meta($customer_id, 'alegra_contact_id', true);

        if (empty($alegra_id)) {
            return new \WP_Error('not_linked', __('El cliente no está vinculado con Alegra', 'alegra-connector'));
        }

        $result = $this->api->delete_contact((string) $alegra_id);

        // Write Gate: the contact was NOT deleted in Alegra, so the local link
        // and mapping must survive.
        if (!is_wp_error($result) && !API\Client::write_was_blocked($result)) {
            delete_user_meta($customer_id, 'alegra_contact_id');
            // AC-60: drop the indexed mapping.
            \Alegra\Connector\Entity_Map::remove('contact', (string) $alegra_id, 'customer');
            $this->logger->info('Customer deleted from Alegra', [
                'customer_id' => $customer_id,
                'alegra_id' => $alegra_id,
            ]);
        }

        return $result;
    }

    public function sync_single_contact_by_alegra_id(string $alegra_id): bool|string
    {
        $contact = $this->api->get_contact($alegra_id);
        if (is_wp_error($contact) || !is_array($contact)) {
            return false;
        }
        return $this->import_single_contact($contact);
    }

    /**
     * Public wrapper for webhook queue processing
     */
    public function import_single_contact_public(array $contact): bool|string
    {
        return $this->import_single_contact($contact);
    }

    /**
     * Populate WooCommerce customer lookup table so customers appear in WC > Customers.
     * Uses WooCommerce's own DataStore method which handles REPLACE INTO correctly.
     */
    private function populate_wc_lookup(int $user_id): void
    {
        if (!class_exists('\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore')) {
            return;
        }

        try {
            \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::update_registered_customer($user_id);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to populate WC customer lookup', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

}