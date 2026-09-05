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
            $result = $this->api->update_contact((int) $alegra_id, $data);
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
                $this->logger->info('Customer created in Alegra', [
                    'customer_id' => $customer->ID,
                    'alegra_id' => $result['id'],
                ]);
            }
        }

        return $result;
    }

    private function resolve_duplicate(int $alegra_id, array $data, \WP_User $customer): array|\WP_Error
    {
        $conflict_resolution = get_option('alegra_connector_conflict_resolution', 'alegra_wins');

        // Always link the WC customer to the existing Alegra contact
        update_user_meta($customer->ID, 'alegra_contact_id', $alegra_id);

        if ($conflict_resolution === 'woocommerce_wins') {
            $result = $this->api->update_contact($alegra_id, $data);
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

    private function find_existing_contact(array $data): ?int
    {
        // Try to find by email first
        $email = $data['email'] ?? '';
        if (!empty($email)) {
            $contacts = $this->api->get_contacts(['email' => $email, 'limit' => 30]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                foreach ($contacts as $contact) {
                    if (isset($contact['email']) && strcasecmp($contact['email'], $email) === 0) {
                        return (int) $contact['id'];
                    }
                }
            }
        }

        // Try by identification (NIT/RFC) as fallback
        $identification = $data['identification'] ?? '';
        if (!empty($identification)) {
            $contacts = $this->api->get_contacts(['identification' => $identification, 'limit' => 30]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                foreach ($contacts as $contact) {
                    if (isset($contact['identification']) && $contact['identification'] === $identification) {
                        return (int) $contact['id'];
                    }
                }
            }
        }

        return null;
    }

    public function sync_all(): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0];

        $args = [
            'role' => 'customer',
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => -1,
        ];

        $customers = get_users($args);

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

        $this->logger->info('Customers sync completed', $result);

        return $result;
    }

    public function import_from_alegra(int $page = 1, int $per_page = 30): array|\WP_Error
    {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0];
        $current_page = $page;
        $max_pages = 200;
        set_time_limit(300);

        for ($p = 1; $p <= $max_pages; $p++) {
            if (get_transient('alegra_sync_cancelled')) {
                delete_transient('alegra_sync_cancelled');
                $this->logger->info('Customers import cancelled by user');
                break;
            }

            set_transient('alegra_sync_progress', [
                'type' => 'customers',
                'current_page' => $p,
                'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'message' => sprintf(__('Procesando clientes... Página %d', 'alegra-connector'), $p),
            ], 120);

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
                $r = $this->import_single_contact($contact);
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
            }

            if (count($alegra_contacts) < $per_page) break;
            $current_page++;
        }

        set_transient('alegra_sync_progress', [
            'type' => 'customers',
            'current_page' => $current_page,
            'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'done' => true,
            'message' => sprintf(__('Completado: %d importados, %d actualizados', 'alegra-connector'), $result['imported'], $result['updated']),
        ], 60);

        $this->logger->info('Customers import from Alegra completed', $result);

        return $result;
    }

    private function import_single_contact(array $contact): bool|string
    {
        $alegra_id = $contact['id'];
        $email = $contact['email'] ?? '';

        if (empty($email)) {
            $this->logger->info('Contact without email skipped (required for WooCommerce)', ['alegra_id' => $alegra_id, 'name' => $contact['name'] ?? '']);
            return 'skipped';
        }

        // Prevent re-entrant sync: skip WC→Alegra hooks while we're importing
        $was_syncing = \Alegra\Connector\Public\Public_::is_syncing();
        if (!$was_syncing) {
            \Alegra\Connector\Public\Public_::set_syncing(true);
        }

        try {
            $existing_user_id = email_exists($email);

            if ($existing_user_id) {
                update_user_meta($existing_user_id, 'alegra_contact_id', $alegra_id);
                $this->update_customer_from_alegra($existing_user_id, $contact);
                $this->populate_wc_lookup($existing_user_id);
                return 'updated';
            }

        $name_parts = $this->parse_name($contact['name'] ?? '');

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
    }

    private function prepare_customer_data(\WP_User $customer): array
    {
        $data = [
            'name' => $customer->display_name ?: $customer->first_name . ' ' . $customer->last_name,
            'email' => $customer->user_email,
            'phonePrimary' => get_user_meta($customer->ID, 'billing_phone', true) ?: '',
            'address' => [
                'address' => get_user_meta($customer->ID, 'billing_address_1', true) ?: '',
                'city' => get_user_meta($customer->ID, 'billing_city', true) ?: '',
            ],
            'type' => 'client',
        ];

        $nit = get_user_meta($customer->ID, 'billing_nit', true);
        if (!empty($nit)) {
            $data['identification'] = $nit;
        }

        $company = get_user_meta($customer->ID, 'billing_company', true);
        if (!empty($company)) {
            $data['business'] = $company;
        }

        return $data;
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
            return new \WP_Error('not_linked', 'Customer not linked to Alegra');
        }

        $result = $this->api->delete_contact((int) $alegra_id);

        if (!is_wp_error($result)) {
            delete_user_meta($customer_id, 'alegra_contact_id');
            $this->logger->info('Customer deleted from Alegra', [
                'customer_id' => $customer_id,
                'alegra_id' => $alegra_id,
            ]);
        }

        return $result;
    }

    public function sync_single_contact_by_alegra_id(int $alegra_id): bool|string
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