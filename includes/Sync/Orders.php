<?php
/**
 * Orders Sync Handler
 *
 * Converts WooCommerce orders to Alegra invoices with:
 * - Inventory sync on order completion
 * - Credit notes for refunds
 * - Number templates for proper invoice numbering
 * - Payment terms for due dates
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\HPOS;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Orders
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function create_invoice(\WC_Order $order): array|\WP_Error
    {
        $order_id = (int) $order->get_id();
        $lock_key = 'alegra_invoice_lock_' . $order_id;

        // Anti-duplication mutex: prevent two concurrent requests creating duplicate invoices.
        // Atomic via add_option()'s UNIQUE index (real compare-and-swap).
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            $this->logger->warning('Invoice creation already in progress for this order', [
                'order_id' => $order_id,
            ]);
            return new \WP_Error(
                'invoice_in_progress',
                __('Ya hay una creacion de factura en curso para este pedido. Espera unos segundos.', 'alegra-connector')
            );
        }

        try {
            $alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);

            if ($alegra_id !== '') {
                $this->logger->info('Order already has Alegra invoice', [
                    'order_id' => $order_id,
                    'alegra_id' => $alegra_id,
                ]);
                return ['id' => $alegra_id, 'already_exists' => true];
            }

            $data = $this->prepare_invoice_data($order);
            if (is_wp_error($data)) {
                return $data;
            }

            $result = $this->api->create_invoice($data);

            if (is_wp_error($result)) {
                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                $error_data = $result->get_error_data();
                $http_code  = is_array($error_data) ? (int) ($error_data['code'] ?? 0) : 0;
                $message    = $result->get_error_message();
                if ($http_code === 400 && preg_match('/stamp|emisi[oó]n|DIAN/i', $message)) {
                    $order->add_order_note(sprintf(
                        __('[Alegra] La DIAN rechazó la emisión de la factura: %s', 'alegra-connector'),
                        $message
                    ));
                }
            }

            if (!is_wp_error($result) && isset($result['id'])) {
                $order->update_meta_data('_alegra_invoice_id', (string) $result['id']);
                $invoice_number = $result['number'] ?? $result['id'];
                if (isset($result['numberTemplate']['fullNumber'])) {
                    $invoice_number = $result['numberTemplate']['fullNumber'];
                }
                $order->update_meta_data('_alegra_invoice_number', (string) $invoice_number);
                $order->save();
                // Add note to WC order
                $order->add_order_note(sprintf(
                    __('Factura Alegra #%s creada.', 'alegra-connector'),
                    $invoice_number
                ));
                $this->logger->info('Invoice created in Alegra', [
                    'order_id' => $order_id,
                    'invoice_id' => $result['id'],
                    'number' => $result['number'] ?? 'N/A',
                ]);
            }

            return $result;
        } finally {
            // Always release lock, even on error
            Controller::release_lock($lock_key, $token);
        }
    }

    public function create_invoice_with_payment(\WC_Order $order): array|\WP_Error
    {
        $invoice_result = $this->create_invoice($order);

        if (is_wp_error($invoice_result)) {
            return $invoice_result;
        }

        $existing_payment_id = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment_id !== '') {
            return $invoice_result;
        }

        $payment_data = $this->prepare_payment_data($order, (string) $invoice_result['id']);

        if (!empty($payment_data)) {
            $payment_result = $this->api->create_payment($payment_data);
            if (!is_wp_error($payment_result)) {
                $order->update_meta_data('_alegra_payment_id', (string) ($payment_result['id'] ?? ''));
                if (!empty($payment_result['number'])) {
                    $order->update_meta_data('_alegra_payment_number', $payment_result['number']);
                }
                $order->save();
                $order->add_order_note(sprintf(
                    __('Pago Alegra #%s registrado.', 'alegra-connector'),
                    $payment_result['number'] ?? $payment_result['id'] ?? ''
                ));
                $this->logger->info('Payment recorded in Alegra', [
                    'order_id' => $order->get_id(),
                    'payment_id' => $payment_result['id'] ?? 'unknown',
                ]);
            }
        }

        return $invoice_result;
    }

    public function create_credit_note(\WC_Order $order): array|\WP_Error
    {
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);

        if ($alegra_invoice_id === '') {
            return new \WP_Error('no_invoice', 'Order has no linked Alegra invoice');
        }

        // Idempotency: never issue a second credit note for the same order.
        // State_Sync::handle_refund() (the refund owner) records its per-refund
        // keys AND the cumulative cap; either marker means a credit note exists.
        $existing_credit_note = (string) $order->get_meta('_alegra_credit_note_id', true);
        if ($existing_credit_note !== '') {
            return ['id' => $existing_credit_note, 'already_exists' => true];
        }
        $credited_before = (float) $order->get_meta('_alegra_credited_amount', true);
        if ($credited_before > 0) {
            return new \WP_Error(
                'credit_note_already_issued',
                __('Ya se emitió una nota de crédito para este pedido.', 'alegra-connector')
            );
        }

        $invoice = $this->api->get_invoice($alegra_invoice_id);
        if (!is_wp_error($invoice) && is_array($invoice)) {
            $emission = (string) ($invoice['emission_status'] ?? '');
            if ($emission === 'PENDING' || $emission === '') {
                return new \WP_Error(
                    'invoice_not_stamped',
                    __('No se puede crear la nota de crédito: la factura original no está emitida ante la DIAN.', 'alegra-connector')
                );
            }
        }

        $refunds = $order->get_refunds();
        if (!empty($refunds)) {
            $items = [];
            foreach ($refunds as $refund) {
                $refund_items = $this->prepare_credit_note_items_from_refund($refund);
                $items = array_merge($items, $refund_items);
            }
            $observation = sprintf(
                __('Reembolso parcial de pedido #%d - WooCommerce', 'alegra-connector'),
                $order->get_id()
            );
        } else {
            $items = $this->prepare_credit_note_items($order);
            $observation = sprintf(
                __('Reembolso total de pedido #%d - WooCommerce', 'alegra-connector'),
                $order->get_id()
            );
        }

        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 0);
        }

        // Cumulative cap: never credit more than the original invoice total.
        $invoice_total = (!is_wp_error($invoice) && isset($invoice['total']))
            ? (float) $invoice['total']
            : (float) $order->get_total();
        if ($credited_before + $total > $invoice_total + 0.01) {
            return new \WP_Error(
                'credit_note_exceeds_invoice',
                __('El monto de la nota de crédito supera el total de la factura.', 'alegra-connector')
            );
        }

        // Resolve the client — required by POST /credit-notes.
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        if ($client_id === '') {
            $cf = \Alegra\Connector\Consumidor_Final::get_id();
            $client_id = $cf !== false ? (string) $cf : '';
        }
        if ($client_id === '') {
            return new \WP_Error(
                'customer_unresolved',
                __('No se pudo resolver el cliente para la nota de crédito.', 'alegra-connector')
            );
        }

        $data = [
            'client' => ['id' => $client_id],
            'invoices' => [
                [
                    'id'     => $alegra_invoice_id,
                    'amount' => round($total, 2),
                ],
            ],
            'date' => date('Y-m-d'),
            'dueDate' => date('Y-m-d'),
            'observations' => $observation,
            'items' => $items,
        ];

        if (get_option('alegra_connector_stamp_enabled', true)) {
            $data['stamp'] = ['generateStamp' => true];
        }

        $result = $this->api->create_credit_note($data);

        if (!is_wp_error($result)) {
            $cn_id = $result['id'] ?? '';
            $order->update_meta_data('_alegra_credit_note_id', (string) $cn_id);
            // Maintain the cumulative cap so a later refund cannot over-credit.
            $order->update_meta_data('_alegra_credited_amount', $credited_before + $total);
            $order->save();
            $this->logger->info('Credit note created in Alegra', [
                'order_id' => $order->get_id(),
                'credit_note_id' => $cn_id,
            ]);
        }

        return $result;
    }

    /**
     * Create a credit note for a WooCommerce refund.
     *
     * Idempotent: tracks the cumulative credited amount in _alegra_credited_amount
     * and refuses to over-credit the original invoice.
     *
     * @param int    $order_id   The WC order id.
     * @param float  $amount     The refund amount (positive).
     * @param string $reason     Optional reason, becomes the item description.
     * @param int    $refund_id  Optional WC refund id, for idempotency.
     * @return array|\WP_Error   The Alegra credit note, or an error.
     */
    public function create_credit_note_for_refund(int $order_id, float $amount, string $reason = '', int $refund_id = 0): array|\WP_Error
    {
        if ($amount <= 0) {
            return new \WP_Error(
                'invalid_amount',
                __('El monto del reembolso debe ser mayor a cero.', 'alegra-connector')
            );
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('invalid_order', __('Pedido no válido.', 'alegra-connector'));
        }

        $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($invoice_id === '') {
            return new \WP_Error('no_invoice', 'Order has no linked Alegra invoice');
        }

        // Idempotency: this specific refund was already credited.
        $refund_meta_key = '_alegra_credit_note_for_refund_' . $refund_id;
        if ($refund_id > 0) {
            $stored = $order->get_meta($refund_meta_key, true);
            if (!empty($stored)) {
                return ['id' => (string) $stored, 'already_exists' => true];
            }
        }

        $invoice = $this->api->get_invoice($invoice_id);
        if (!is_wp_error($invoice) && is_array($invoice)) {
            $emission = (string) ($invoice['emission_status'] ?? '');
            if ($emission === 'PENDING' || $emission === '') {
                return new \WP_Error(
                    'invoice_not_stamped',
                    __('No se puede crear la nota de crédito: la factura original no está emitida ante la DIAN.', 'alegra-connector')
                );
            }
        }

        // Cumulative cap: never credit more than the original invoice total.
        $credited = (float) $order->get_meta('_alegra_credited_amount', true);
        $invoice_total = (!is_wp_error($invoice) && isset($invoice['total']))
            ? (float) $invoice['total']
            : (float) $order->get_total();
        if ($credited + $amount > $invoice_total + 0.01) {
            return new \WP_Error(
                'refund_exceeds_invoice',
                __('El monto acumulado de reembolsos supera el total de la factura.', 'alegra-connector')
            );
        }

        $is_full_refund = $amount >= ($invoice_total - 0.01);

        if ($is_full_refund && !is_wp_error($invoice) && is_array($invoice) && !empty($invoice['items']) && is_array($invoice['items'])) {
            // Full refund: reuse the original invoice lines verbatim.
            $items = [];
            foreach ($invoice['items'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $items[] = array_filter([
                    'id'          => $line['id']          ?? null,
                    'name'        => $line['name']        ?? null,
                    'description' => $line['description'] ?? null,
                    'price'       => $line['price']       ?? null,
                    'quantity'    => $line['quantity']    ?? null,
                    'tax'         => $line['tax']         ?? null,
                    'discount'    => $line['discount']    ?? null,
                ], static fn($v) => $v !== null);
            }
        } else {
            // Partial refund: a single line for exactly the refunded amount, so the
            // credit note total matches the refund (no DIAN over-crediting).
            $items = [[
                'name'        => __('Reembolso parcial', 'alegra-connector'),
                'description' => $reason !== '' ? $reason : __('Reembolso parcial', 'alegra-connector'),
                'price'       => round($amount, 2),
                'quantity'    => 1,
            ]];
        }

        $data = [
            'date'     => date('Y-m-d'),
            'client'   => ['id' => (string) $order->get_meta('_billing_alegra_contact_id', true)],
            'invoices' => [[
                'id'     => $invoice_id,
                'amount' => round($amount, 2),
            ]],
            'items'    => $items,
        ];

        if (get_option('alegra_connector_stamp_enabled', true)) {
            $data['stamp'] = ['generateStamp' => true];
        }

        $result = $this->api->create_credit_note($data);

        if (is_wp_error($result)) {
            if ($this->logger) {
                $this->logger->error('Credit note for refund failed', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'error'     => $result->get_error_message(),
                ]);
            }
            return $result;
        }

        $cn_id = (string) ($result['id'] ?? '');

        $order->update_meta_data('_alegra_credited_amount', $credited + $amount);
        if ($refund_id > 0) {
            $order->update_meta_data($refund_meta_key, $cn_id);
        }
        $order->save();

        $order->add_order_note(sprintf(
            __('Nota de crédito Alegra #%s creada por reembolso de %s.', 'alegra-connector'),
            $cn_id,
            number_format_i18n($amount, 2)
        ));

        if ($this->logger) {
            $this->logger->info('Credit note for refund created in Alegra', [
                'order_id'       => $order_id,
                'refund_id'      => $refund_id,
                'credit_note_id' => $cn_id,
                'amount'         => $amount,
            ]);
        }

        return $result;
    }

    public function void_invoice(\WC_Order $order): array|\WP_Error
    {
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);

        if ($alegra_invoice_id === '') {
            return new \WP_Error('no_invoice', 'Order has no linked Alegra invoice');
        }

        $result = $this->api->void_invoice(
            $alegra_invoice_id,
            sprintf('Cancelled via WooCommerce - Order #%d', $order->get_id())
        );

        if (!is_wp_error($result)) {
            $order->add_order_note(sprintf(
                __('Factura Alegra #%s anulada.', 'alegra-connector'),
                $alegra_invoice_id
            ));
            $this->logger->info('Invoice voided in Alegra', [
                'order_id' => $order->get_id(),
                'invoice_id' => $alegra_invoice_id,
            ]);
        }

        return $result;
    }

    public function sync_recent(int $days = 7): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0];

        $args = [
            'limit' => 100,
            'status' => ['processing', 'completed'],
            'date_after' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
        ];

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            $alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);
            if ($alegra_id !== '') {
                continue;
            }

            $sync_result = $this->create_invoice($order);
            if (is_wp_error($sync_result)) {
                $result['errors']++;
            } else {
                $result['synced']++;
            }
        }

        $this->logger->info('Orders sync completed', $result);

        return $result;
    }

    private function prepare_invoice_data(\WC_Order $order): array|\WP_Error
    {
        // Sync customer first if needed
        $customer_alegra_id = $this->ensure_customer_synced($order);
        if ($customer_alegra_id === '') {
            return new \WP_Error(
                'customer_unresolved',
                __('No se pudo resolver el cliente en Alegra ni usar el Consumidor Final. La factura no se creó.', 'alegra-connector')
            );
        }

        $client_data = $this->build_client_data($order, $customer_alegra_id);

        // Country must be resolved BEFORE being used (PHP 8+ safe + correct logic).
        $country = (string) $order->get_billing_country();

        $data = [
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : date('Y-m-d'),
            'dueDate' => $this->calculate_due_date($order),
            'client' => $client_data,
            'items' => $this->prepare_invoice_items($order),
            'currency' => ['code' => $order->get_currency() ?: get_option('alegra_connector_currency', 'COP')],
            'observations' => sprintf(__('Pedido WooCommerce #%d', 'alegra-connector'), $order->get_id()),
            'status' => 'open',
        ];

        // Payment method mapping
        $payment = $this->map_payment_method($order);
        if ($payment) {
            // paymentForm is only for Colombia; other countries just use paymentMethod
            $data['paymentMethod'] = $payment['method'];
            if ($country === 'CO') {
                $data['paymentForm'] = $payment['form'];
            }
        }

        // Country-specific fields
        if ($country === 'CO') {
            $data['type'] = 'NATIONAL';
        }

        // Number template (auto-detect preferred)
        $template = $this->get_preferred_number_template();
        if ($template !== null && $template !== '') {
            $data['numberTemplate'] = ['id' => $template];
        }

        // Payment term
        $term = $this->get_default_payment_term();
        if ($term !== '') {
            $data['term'] = $term;
        }

        // Warehouse
        $warehouse = (string) get_option('alegra_connector_warehouse_id', '');
        if ($warehouse !== '' && get_option('alegra_connector_warehouse_enabled')) {
            $data['warehouse'] = $warehouse;
        }

        // Issue the e-invoice to DIAN (required for Colombian e-invoicing)
        if (get_option('alegra_connector_stamp_enabled', true) && $country === 'CO') {
            $data['stamp'] = ['generateStamp' => true];
        }

        return $data;
    }

    /**
     * Resolve the Alegra contact for an order.
     *
     * 7-step algorithm:
     *  1. Cached alegra_contact_id (user_meta or order_meta) -> return
     *  2. Lookup by email in Alegra -> link + return
     *  3. Lookup by identification (new format) OR legacy billing_nit -> link + return
     *  4. Build the payload from billing_alegra_* (registered) or _billing_alegra_* (guest).
     *     If critical data is missing -> step 6
     *  5. POST /contacts (with 2039 retry) -> persist + return. On failure -> step 6
     *  6. Consumidor Final fallback -> persist + return
     *  7. (persist happens in each branch)
     *
     * @return string The resolved Alegra contact id, or '' on total failure.
     */
    private function ensure_customer_synced(\WC_Order $order): string
    {
        $order_id = (int) $order->get_id();
        $customer = $order->get_user();

        // Customer resolution mode: auto (default) | always_generic | require_data.
        // `require_data` is enforced at checkout and disables the Consumidor
        // Final fallback here (see step 6).
        $mode = (string) get_option('alegra_connector_customer_resolution_mode', 'auto');

        // Mode: always use the generic client (Consumidor Final).
        if ($mode === 'always_generic') {
            $cf = \Alegra\Connector\Consumidor_Final::get_id();
            if ($cf !== false && $cf !== '') {
                $this->persist_contact_id($order, $customer, (string) $cf);
                return (string) $cf;
            }
            return '';
        }

        // Step 1 — cached contact id (order meta, then user meta).
        $cached = (string) $order->get_meta('_billing_alegra_contact_id', true);
        if ($cached !== '') {
            return $cached;
        }
        if ($customer) {
            $cached = (string) get_user_meta($customer->ID, 'alegra_contact_id', true);
            if ($cached !== '') {
                $order->update_meta_data('_billing_alegra_contact_id', $cached);
                $order->save();
                return $cached;
            }
        }

        // Step 2 — lookup by email.
        $email = $customer ? $customer->user_email : $order->get_billing_email();
        if ($email !== '') {
            $contacts = $this->api->get_contacts(['email' => $email, 'limit' => 5]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                foreach ($contacts as $c) {
                    if (isset($c['id']) && isset($c['email']) && strcasecmp((string) $c['email'], $email) === 0) {
                        $found = (string) $c['id'];
                        $this->persist_contact_id($order, $customer, $found);
                        return $found;
                    }
                }
            }
        }

        // Steps 3-5 run under an atomic lock to avoid duplicate contact creation.
        $lock_key = 'alegra_contact_create_lock_' . $order_id;
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            usleep(500000);
            // Re-check cache once after the concurrent request had time to persist.
            $cached = (string) $order->get_meta('_billing_alegra_contact_id', true);
            if ($cached !== '') {
                return $cached;
            }
            // Fall through to the Consumidor Final fallback.
        } else {
            try {
                // Step 3a — lookup by identification (new format).
                $idtype = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_idtype', true) : (string) $order->get_meta('_billing_alegra_idtype', true);
                $idnum  = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_identification', true) : (string) $order->get_meta('_billing_alegra_identification', true);
                $dv     = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_dv', true) : (string) $order->get_meta('_billing_alegra_dv', true);
                if ($idtype !== '' && $idnum !== '') {
                    $contact = $this->api->find_contact_by_identification($idtype, $idnum, $dv !== '' ? $dv : null);
                    if ($contact && isset($contact['id'])) {
                        $found = (string) $contact['id'];
                        $this->persist_contact_id($order, $customer, $found);
                        return $found;
                    }
                }

                // Step 3b — legacy billing_nit lookup.
                $nit = $customer ? (string) get_user_meta($customer->ID, 'billing_nit', true) : (string) $order->get_meta('billing_nit');
                if ($nit !== '') {
                    $contacts = $this->api->get_contacts(['identification' => $nit, 'limit' => 5]);
                    if (!is_wp_error($contacts) && !empty($contacts)) {
                        foreach ($contacts as $c) {
                            if (isset($c['id'])) {
                                $found = (string) $c['id'];
                                $this->persist_contact_id($order, $customer, $found);
                                return $found;
                            }
                        }
                    }
                }

                // Steps 4-5 — build the payload and create the contact (only with critical data).
                $values = $this->collect_billing_values($order, $customer);
                if (\Alegra\Connector\Billing_Fields::has_critical_data($values)) {
                    $payload = $customer
                        ? \Alegra\Connector\Billing_Fields::build_contact_payload($customer)
                        : \Alegra\Connector\Billing_Fields::build_guest_contact_payload($order);
                    if (!is_wp_error($payload)) {
                        $result = $this->api->create_contact_with_2039_retry($payload);
                        if (!is_wp_error($result) && isset($result['id'])) {
                            $found = (string) $result['id'];
                            $this->persist_contact_id($order, $customer, $found);
                            return $found;
                        }
                        if (is_wp_error($result)) {
                            $this->logger->warning('Contact creation failed, falling back to Consumidor Final', [
                                'order_id' => $order_id,
                                'error'    => $result->get_error_message(),
                            ]);
                        }
                    }
                }
            } finally {
                Controller::release_lock($lock_key, $token);
            }
        }

        // Step 6 — Consumidor Final fallback. Disabled in `require_data` mode so
        // the invoice aborts with `customer_unresolved` instead of silently
        // invoicing a generic consumer.
        if ($mode !== 'require_data') {
            $cf = \Alegra\Connector\Consumidor_Final::get_id();
            if ($cf !== false && $cf !== '') {
                $this->persist_contact_id($order, $customer, (string) $cf);
                $this->logger->info('Using Consumidor Final for order', ['order_id' => $order_id]);
                return (string) $cf;
            }
            $this->logger->error('Consumidor Final could not be resolved', ['order_id' => $order_id]);
        } else {
            $this->logger->error('Customer data required but could not be resolved', ['order_id' => $order_id]);
        }
        return '';
    }

    /**
     * Persist the resolved Alegra contact id on the order and, when present,
     * on the customer.
     */
    private function persist_contact_id(\WC_Order $order, ?\WP_User $customer, string $contact_id): void
    {
        $order->update_meta_data('_billing_alegra_contact_id', $contact_id);
        $order->save();
        if ($customer) {
            update_user_meta($customer->ID, 'alegra_contact_id', $contact_id);
        }
    }

    /**
     * Collect the catalog billing values for an order, keyed by catalog key.
     *
     * Reads from user_meta for registered customers, or from the order meta
     * (prefixed with `_`) for guests.
     *
     * @return array<string, string>
     */
    private function collect_billing_values(\WC_Order $order, ?\WP_User $customer): array
    {
        $values = [];
        foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
            $meta_key = (string) $field['meta_key'];
            $values[$key] = $customer
                ? (string) get_user_meta($customer->ID, $meta_key, true)
                : (string) $order->get_meta('_' . $meta_key, true);
        }

        return $values;
    }

    private function build_client_data(\WC_Order $order, string $customer_alegra_id): array
    {
        return ['id' => $customer_alegra_id];
    }

    private function calculate_due_date(\WC_Order $order): string
    {
        // Try to get payment term from Alegra
        $term_id = (string) get_option('alegra_connector_payment_term_id', '');
        if ($term_id !== '') {
            $term_data = $this->api->get_term($term_id);
            if (!is_wp_error($term_data) && isset($term_data['days'])) {
                $days = (int) $term_data['days'];
                return date('Y-m-d', strtotime("+{$days} days"));
            }
        }

        return date('Y-m-d', strtotime('+15 days'));
    }

    private function prepare_invoice_items(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $price = (float) ($item_obj->get_subtotal() / max(1, $item_obj->get_quantity()));
            $quantity = (int) $item_obj->get_quantity();

            $item_data = [
                'name' => $item_obj->get_name(),
                'price' => $price,
                'quantity' => $quantity,
            ];

            if ($alegra_item_id !== '') {
                $item_data['id'] = $alegra_item_id;
            }

            $tax_ids = $this->map_item_taxes($item_obj);
            if (!empty($tax_ids)) {
                $item_data['tax'] = [];
                foreach ($tax_ids as $tid) {
                    $item_data['tax'][] = ['id' => $tid];
                }
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function resolve_item_alegra_id(int $product_id, int $variation_id): string
    {
        if ($variation_id > 0) {
            $alegra_id = (string) get_post_meta($variation_id, '_alegra_item_id', true);
            if ($alegra_id !== '') return $alegra_id;
        }

        if ($product_id > 0) {
            $alegra_id = (string) get_post_meta($product_id, '_alegra_item_id', true);
            if ($alegra_id !== '') return $alegra_id;

            $sku = get_post_meta($product_id, '_sku', true);
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $found_id = (string) $items[0]['id'];
                    update_post_meta($product_id, '_alegra_item_id', $found_id);
                    return $found_id;
                }
            }
        }

        return '';
    }

    private function prepare_payment_data(\WC_Order $order, string $invoice_id): array
    {
        $account_id = (string) get_option('alegra_connector_payment_account_id', '');
        if ($account_id === '') {
            return [];
        }

        return [
            'date' => date('Y-m-d'),
            'bankAccount' => ['id' => $account_id],
            'invoices' => [
                [
                    'id' => $invoice_id,
                    'amount' => (float) $order->get_total(),
                ],
            ],
            'paymentMethod' => $this->get_payment_method_code($order),
        ];
    }

    private function prepare_credit_note_items(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $item_data = [
                'name' => $item_obj->get_name(),
                'quantity' => (int) $item_obj->get_quantity(),
                'price' => (float) ($item_obj->get_subtotal() / max(1, $item_obj->get_quantity())),
            ];

            if ($alegra_item_id !== '') {
                $item_data['id'] = $alegra_item_id;
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function prepare_credit_note_items_from_refund(\WC_Order_Refund $refund): array
    {
        $items = [];

        foreach ($refund->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();
            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $refund_qty = abs((int) $item_obj->get_quantity());
            $refund_total = abs((float) $item_obj->get_total());
            $refund_price = $refund_qty > 0 ? $refund_total / $refund_qty : 0;

            $item_data = [
                'name' => $item_obj->get_name(),
                'quantity' => $refund_qty,
                'price' => $refund_price,
            ];

            if ($alegra_item_id !== '') {
                $item_data['id'] = $alegra_item_id;
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function get_preferred_number_template(): ?string
    {
        $templates = $this->api->get_number_templates(['documentType' => 'invoice']);
        if (is_wp_error($templates) || empty($templates)) {
            return null;
        }

        // Prefer the electronic invoice template
        foreach ($templates as $tpl) {
            if (($tpl['isElectronic'] ?? false) === true && ($tpl['documentType'] ?? '') === 'invoice') {
                return (string) $tpl['id'];
            }
        }

        // Fallback: the default invoice template
        foreach ($templates as $tpl) {
            if (($tpl['isDefault'] ?? false) === true) {
                return (string) $tpl['id'];
            }
        }

        return null;
    }

    private function get_default_payment_term(): string
    {
        return (string) get_option('alegra_connector_payment_term_id', '');
    }

    private function map_payment_method(\WC_Order $order): ?array
    {
        $method = $order->get_payment_method();

        $mappings = $this->get_payment_gateway_mappings();

        foreach ($mappings as $slug => $data) {
            if (strpos($method, $slug) !== false) {
                return $data;
            }
        }

        return ['form' => 'CREDIT', 'method' => 'transfer'];
    }

    private function get_payment_method_code(\WC_Order $order): string
    {
        $method = $order->get_payment_method();
        $mappings = $this->get_payment_gateway_code_mappings();

        foreach ($mappings as $slug => $code) {
            if (strpos($method, $slug) !== false) {
                return $code;
            }
        }

        return 'transfer';
    }

    /**
     * Maps WooCommerce payment gateway slugs to Alegra payment methods.
     * Covers all major gateways in Latin America.
     */
    public function getPaymentMethodForGateway(string $gateway_slug): string
    {
        $codes = $this->get_payment_gateway_code_mappings();

        foreach ($codes as $slug => $code) {
            if (strpos($gateway_slug, $slug) !== false) {
                return $code;
            }
        }

        return 'cash';
    }

    /**
     * Payment gateway → Alegra form/method mapping
     */
    private function get_payment_gateway_mappings(): array
    {
        return [
            'bacs' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'cod' => ['form' => 'CASH', 'method' => 'cash'],
            'cheque' => ['form' => 'CREDIT', 'method' => 'check'],
            'paypal' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'stripe' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'mercadopago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woocommerce-mercado-pago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woo-mercado-pago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'payu' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'epayco' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woocommerce_payments' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'square' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'razorpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'payfast' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'dlocal' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'ebanx' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woo-wompi' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'openpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'clip' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'culqi' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'pse' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'efecty' => ['form' => 'CASH', 'method' => 'cash'],
            'baloto' => ['form' => 'CASH', 'method' => 'cash'],
            'oxxo' => ['form' => 'CASH', 'method' => 'cash'],
            'spei' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'webpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'flow' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'khipu' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'mach' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'servipag' => ['form' => 'CASH', 'method' => 'cash'],
            'safetypay' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'multicaja' => ['form' => 'CASH', 'method' => 'cash'],
            'billetera' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'transferencia' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'nequi' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'daviplata' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'bancolombia' => ['form' => 'CREDIT', 'method' => 'transfer'],
        ];
    }

    /**
     * Payment gateway → Alegra payment method code
     */
    private function get_payment_gateway_code_mappings(): array
    {
        return [
            'bacs' => 'transfer',
            'cod' => 'cash',
            'cheque' => 'check',
            'paypal' => 'transfer',
            'stripe' => 'credit-card',
            'mercadopago' => 'credit-card',
            'woocommerce-mercado-pago' => 'credit-card',
            'woo-mercado-pago' => 'credit-card',
            'payu' => 'credit-card',
            'epayco' => 'credit-card',
            'woocommerce_payments' => 'credit-card',
            'square' => 'credit-card',
            'razorpay' => 'credit-card',
            'payfast' => 'credit-card',
            'dlocal' => 'credit-card',
            'ebanx' => 'credit-card',
            'wompi' => 'credit-card',
            'openpay' => 'credit-card',
            'clip' => 'credit-card',
            'culqi' => 'credit-card',
            'pse' => 'transfer',
            'efecty' => 'cash',
            'baloto' => 'cash',
            'oxxo' => 'cash',
            'spei' => 'transfer',
            'webpay' => 'credit-card',
            'flow' => 'transfer',
            'khipu' => 'transfer',
            'mach' => 'transfer',
            'servipag' => 'cash',
            'safetypay' => 'transfer',
            'multicaja' => 'cash',
            'nequi' => 'transfer',
            'daviplata' => 'transfer',
            'bancolombia' => 'transfer',
        ];
    }

    private function map_item_taxes(\WC_Order_Item $item): array
    {
        $tax_data = $item->get_taxes();
        if (empty($tax_data['total'])) {
            return [];
        }

        $tax_mapping = (array) get_option('alegra_connector_tax_mapping', []);
        $ids = [];

        foreach (array_keys($tax_data['total']) as $rate_id) {
            if (isset($tax_mapping[$rate_id])) {
                $mapped = (string) $tax_mapping[$rate_id];
                if ($mapped !== '') {
                    $ids[$mapped] = $mapped;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Poll Alegra for invoice status changes and update linked WC orders.
     * Replaces the webhook-based approach for Alegra → WC sync.
     * Called by the cron sync job, respecting auto_complete_order setting.
     */
    public function poll_invoice_statuses(): array
    {
        $result = ['checked' => 0, 'completed' => 0, 'errors' => 0];

        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Invoice status poll skipped: kill switch active');
            return $result;
        }

        $orders = wc_get_orders([
            'limit' => 50,
            'status' => ['processing', 'pending', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $order_ids = [];
        foreach ($orders as $o) {
            $alegra_id = (string) $o->get_meta('_alegra_invoice_id', true);
            if ($alegra_id !== '') {
                $order_ids[$o->get_id()] = $alegra_id;
            }
        }

        $should_complete = get_option('alegra_connector_auto_complete_order', true);

        foreach ($order_ids as $order_id => $invoice_id) {
            // Re-check the kill switch so an in-flight poll stops.
            if (\Alegra\Connector\Kill_Switch::is_active()) {
                $this->logger->info('Invoice status poll stopped: kill switch active');
                break;
            }

            $result['checked']++;

            $invoice = $this->api->get_invoice($invoice_id);
            if (is_wp_error($invoice)) {
                $result['errors']++;
                continue;
            }

            $status = $invoice['status'] ?? '';
            $balance = (float) ($invoice['balance'] ?? 0);

            if ($status === 'paid' && $balance <= 0 && $should_complete) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_status() !== 'completed') {
                    $order->add_order_note(sprintf(
                        __('[Alegra] Factura #%s pagada. Pedido completado automaticamente.', 'alegra-connector'),
                        $invoice['numberTemplate']['fullNumber'] ?? $invoice_id
                    ));
                    $order->update_status('completed');
                    $result['completed']++;
                }
            }
        }

        return $result;
    }
}