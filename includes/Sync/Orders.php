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

    public function create_invoice(\WC_Order $order, ?string $status_override = null): array|\WP_Error
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

            $data = $this->prepare_invoice_data($order, $status_override);
            if (is_wp_error($data)) {
                return $data;
            }

            // AC-14: idempotency pre-search. If a previous POST committed in
            // Alegra but its response was lost (timeout/reset), there is no
            // _alegra_invoice_id. Look for an invoice whose observations carry
            // this WC order marker before creating a duplicate.
            $client_id = (string) ($data['client']['id'] ?? '');
            $existing_invoice = $this->find_existing_invoice($order, $client_id);
            if ($existing_invoice !== null) {
                $existing_id = (string) ($existing_invoice['id'] ?? '');
                if ($existing_id !== '') {
                    $this->persist_invoice_result($order, $existing_id, $existing_invoice);
                    $order->add_order_note(sprintf(
                        __('[Alegra] Se encontró una factura existente (#%s) para este pedido; no se creó una nueva.', 'alegra-connector'),
                        $existing_id
                    ));
                    $this->logger->warning('Recovered pre-existing Alegra invoice (idempotency pre-search)', [
                        'order_id'   => $order_id,
                        'invoice_id' => $existing_id,
                    ]);
                    return ['id' => $existing_id, 'already_exists' => true, 'recovered' => true];
                }
            }

            $result = $this->api->create_invoice($data);

            if (is_wp_error($result)) {
                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }

            if (isset($result['id'])) {
                $this->persist_invoice_result($order, (string) $result['id'], $result);
                $order->add_order_note(sprintf(
                    __('Factura Alegra #%s creada.', 'alegra-connector'),
                    (string) $order->get_meta('_alegra_invoice_number', true)
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

    /**
     * Persist an Alegra invoice id/number on the order.
     *
     * Used on success and when the idempotency pre-search recovers an existing
     * invoice (AC-14).
     *
     * @param array<string, mixed> $invoice The Alegra invoice payload.
     */
    private function persist_invoice_result(\WC_Order $order, string $invoice_id, array $invoice): void
    {
        $order->update_meta_data('_alegra_invoice_id', $invoice_id);

        $invoice_number = $invoice_id;
        if (isset($invoice['numberTemplate']['fullNumber'])) {
            $invoice_number = (string) $invoice['numberTemplate']['fullNumber'];
        } elseif (isset($invoice['numberTemplate']['number'])) {
            $invoice_number = (string) $invoice['numberTemplate']['number'];
        } elseif (isset($invoice['number'])) {
            $invoice_number = (string) $invoice['number'];
        }
        $order->update_meta_data('_alegra_invoice_number', $invoice_number);
        $order->save();

        // AC-07: write the indexed mapping so a later lookup never scans
        // wp_postmeta.meta_value (O(N²) on a large catalog).
        \Alegra\Connector\Entity_Map::map('invoice', $invoice_id, 'order', (int) $order->get_id());
    }

    /**
     * Find an Alegra invoice that belongs to this WC order (AC-14).
     *
     * GET /invoices supports `client_id` but NOT an observations/query filter
     * (see get_invoices.md), so we filter server-side by client and scan the
     * returned page for the "Pedido WooCommerce #<id>" marker written by
     * prepare_invoice_data().
     *
     * @return array<string, mixed>|null
     */
    private function find_existing_invoice(\WC_Order $order, string $client_id): ?array
    {
        $params = [
            'limit'           => 30,
            'order_field'     => 'date',
            'order_direction' => 'DESC',
        ];
        if ($client_id !== '') {
            $params['client_id'] = $client_id;
        }

        $invoices = $this->api->get_invoices($params);
        if (is_wp_error($invoices) || !is_array($invoices)) {
            return null;
        }

        $order_id = (int) $order->get_id();
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $observations = (string) ($invoice['observations'] ?? '');
            // Anchor the id so "#123" never matches "#1234".
            if ($observations !== '' && preg_match('/Pedido WooCommerce #(\d+)/', $observations, $m)) {
                if ((int) $m[1] === $order_id) {
                    return $invoice;
                }
            }
        }

        return null;
    }

    /**
     * Find an Alegra payment already applied to an invoice (AC-14).
     *
     * GET /payments supports `client_id`; we match on the invoice association
     * returned in each payment.
     *
     * @return array<string, mixed>|null
     */
    private function find_existing_payment(string $invoice_id, string $client_id): ?array
    {
        if ($invoice_id === '') {
            return null;
        }

        $params = ['limit' => 30, 'order_direction' => 'DESC'];
        if ($client_id !== '') {
            $params['client_id'] = $client_id;
        }

        $payments = $this->api->get_payments($params);
        if (is_wp_error($payments) || !is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $invoices = $payment['invoices'] ?? [];
            if (!is_array($invoices)) {
                continue;
            }
            foreach ($invoices as $inv) {
                $inv_id = is_array($inv) ? (string) ($inv['id'] ?? '') : (string) $inv;
                if ($inv_id !== '' && $inv_id === $invoice_id) {
                    return $payment;
                }
            }
        }

        return null;
    }

    public function create_invoice_with_payment(\WC_Order $order): array|\WP_Error
    {
        // A payment is recorded separately (POST /payments) after the invoice.
        // Alegra only allows a payment on an OPEN invoice, so when a bank
        // account is configured the invoice is created open instead of the
        // configured draft status.
        $will_record_payment = (string) get_option('alegra_connector_payment_account_id', '') !== ''
            && (string) $order->get_meta('_alegra_payment_id', true) === '';

        $invoice_result = $this->create_invoice($order, $will_record_payment ? 'open' : null);

        if (is_wp_error($invoice_result)) {
            return $invoice_result;
        }

        // AC-72: in dry-run mode create_invoice() returns ['dry_run' => true]
        // with no `id`; reading $invoice_result['id'] unguarded produced a
        // warning and an empty invoice id. Bail out before touching it.
        if (!isset($invoice_result['id']) || (string) $invoice_result['id'] === '') {
            return $invoice_result;
        }

        $existing_payment_id = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment_id !== '') {
            return $invoice_result;
        }

        // Alegra only accepts a payment on an OPEN invoice. When the invoice
        // was created earlier as a draft (the default, e.g. by the
        // `woocommerce_new_order` hook) open it before applying the payment.
        if ($will_record_payment && !empty($invoice_result['already_exists'])) {
            $this->ensure_invoice_open((string) $invoice_result['id']);
        }

        // AC-14: recover a payment Alegra committed but whose response was lost.
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        $existing_payment = $this->find_existing_payment((string) $invoice_result['id'], $client_id);
        if ($existing_payment !== null) {
            $order->update_meta_data('_alegra_payment_id', (string) ($existing_payment['id'] ?? ''));
            if (!empty($existing_payment['number'])) {
                $order->update_meta_data('_alegra_payment_number', $existing_payment['number']);
            }
            $order->save();
            $order->add_order_note(sprintf(
                __('[Alegra] Se encontró un pago existente (#%s) para esta factura; no se registró uno nuevo.', 'alegra-connector'),
                $existing_payment['number'] ?? $existing_payment['id'] ?? ''
            ));
            $this->logger->warning('Recovered pre-existing Alegra payment (idempotency pre-search)', [
                'order_id'   => $order->get_id(),
                'payment_id' => $existing_payment['id'] ?? 'unknown',
            ]);
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

    /**
     * Open a draft invoice so a payment can be applied to it.
     *
     * No-op when the invoice is already open or cannot be read; failures are
     * logged so the payment attempt still surfaces Alegra's own error.
     */
    private function ensure_invoice_open(string $invoice_id): void
    {
        if ($invoice_id === '') {
            return;
        }

        $invoice = $this->api->get_invoice($invoice_id);
        if (is_wp_error($invoice) || !is_array($invoice)) {
            return;
        }
        if ((string) ($invoice['status'] ?? '') !== 'draft') {
            return;
        }

        $opened = $this->api->open_invoice($invoice_id);
        if (is_wp_error($opened) && $this->logger) {
            $this->logger->warning('Could not open a draft invoice before recording a payment', [
                'invoice_id' => $invoice_id,
                'error'      => $opened->get_error_message(),
            ]);
        }
    }

    /**
     * Create a credit note for the whole order (legacy entry point).
     *
     * Naming-drift collapse (root cause of AC-04): this used to be a SECOND,
     * independent credit-note implementation with its own idempotency key
     * (`_alegra_credit_note_id`) and its own payload shape. Together with the
     * per-refund path it let one full refund issue two credit notes. It now
     * delegates to the single implementation, create_credit_note_for_refund(),
     * so every caller shares one payload builder, one cumulative cap and one
     * idempotency key.
     */
    public function create_credit_note(\WC_Order $order): array|\WP_Error
    {
        $order_id = (int) $order->get_id();

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '') {
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        // Credit whatever has not been credited yet. The cumulative
        // _alegra_credited_amount marker (maintained by the shared
        // implementation) makes this idempotent: a second call credits 0.
        $credited_before = (float) $order->get_meta('_alegra_credited_amount', true);
        $amount = round((float) $order->get_total() - $credited_before, 2);
        if ($amount <= 0) {
            return new \WP_Error(
                'credit_note_already_issued',
                __('Ya se emitió una nota de crédito para este pedido.', 'alegra-connector')
            );
        }

        return $this->create_credit_note_for_refund(
            $order_id,
            $amount,
            sprintf(__('Reembolso de pedido #%d - WooCommerce', 'alegra-connector'), $order_id),
            0
        );
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
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        // Idempotency: this specific refund was already credited.
        // Naming-drift collapse: State_Sync (the refund owner) and this class
        // used two different per-refund keys for the same concept, so their
        // guards never composed. Unify on State_Sync::REFUND_META_FMT.
        $refund_meta_key = sprintf(\Alegra\Connector\State_Sync::REFUND_META_FMT, $refund_id);
        if ($refund_id > 0) {
            $stored = $order->get_meta($refund_meta_key, true);
            if (!empty($stored)) {
                return ['id' => (string) $stored, 'already_exists' => true];
            }
        }

        $invoice = $this->api->get_invoice($invoice_id);

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
            // credit note total matches the refund (no over-crediting).
            $items = [[
                'name'        => __('Reembolso parcial', 'alegra-connector'),
                'description' => $reason !== '' ? $reason : __('Reembolso parcial', 'alegra-connector'),
                'price'       => round($amount, 2),
                'quantity'    => 1,
            ]];
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
            'date'     => date('Y-m-d'),
            'client'   => ['id' => $client_id],
            'invoices' => [[
                'id'     => $invoice_id,
                'amount' => round($amount, 2),
            ]],
            'items'    => $items,
        ];

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

        // Dry Run: the credit note was NOT created. Do not mark the refund as
        // credited, do not store a refund meta id, and do not claim success.
        if (API\Client::is_dry_run_response($result)) {
            $order->add_order_note(__('Alegra (modo de prueba): no se creó la nota de crédito. Desactiva el modo de prueba para facturar de verdad.', 'alegra-connector'));
            if ($this->logger) {
                $this->logger->warning('Credit note for refund skipped (dry run)', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'amount'    => $amount,
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
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        $result = $this->api->void_invoice(
            $alegra_invoice_id,
            sprintf(__('Cancelada desde WooCommerce - Pedido #%d', 'alegra-connector'), $order->get_id())
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

    private function prepare_invoice_data(\WC_Order $order, ?string $status_override = null): array|\WP_Error
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

        // AC-10: invoice lines require an Alegra item id; abort if unresolvable.
        $items = $this->prepare_invoice_items($order);
        if (is_wp_error($items)) {
            return $items;
        }

        // Alegra creates the invoice as a draft when `status` is omitted and no
        // payments are sent. The default is therefore draft so the merchant can
        // review it; an OPEN invoice is only forced when a payment is applied.
        $status = $status_override ?? (string) get_option('alegra_connector_invoice_status', 'draft');
        if (!in_array($status, ['draft', 'open'], true)) {
            $status = 'draft';
        }

        $data = [
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : date('Y-m-d'),
            'dueDate' => $this->calculate_due_date($order),
            'client' => $client_data,
            'items' => $items,
            'currency' => ['code' => $order->get_currency() ?: get_option('alegra_connector_currency', 'COP')],
            'observations' => sprintf(__('Pedido WooCommerce #%d', 'alegra-connector'), $order->get_id()),
            'status' => $status,
        ];

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
        // AC-50: `email` is NOT a documented listContacts filter (the documented
        // ones are `query` and `identification`). Use `query` and verify EVERY
        // returned candidate; an undocumented param can be ignored by the API,
        // which would otherwise return the first N contacts and false-match.
        $email = $customer ? $customer->user_email : $order->get_billing_email();
        if ($email !== '') {
            $contacts = $this->api->get_contacts(['query' => $email, 'limit' => 30]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                $matches = [];
                foreach ($contacts as $c) {
                    if (isset($c['id']) && isset($c['email']) && strcasecmp((string) $c['email'], $email) === 0) {
                        $matches[] = (string) $c['id'];
                    }
                }

                if (count($matches) === 1) {
                    $this->persist_contact_id($order, $customer, $matches[0]);
                    return $matches[0];
                }

                if (count($matches) > 1 && $this->logger) {
                    // Ambiguous: several contacts share this email. Do not pick
                    // arbitrarily — fall through to the identification lookup.
                    $this->logger->warning('Multiple Alegra contacts share this email; deferring to identification', [
                        'order_id' => $order_id,
                        'email'    => $email,
                        'matches'  => count($matches),
                    ]);
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

                // Step 3b — legacy billing_nit lookup (registered customers
                // only). Customers::update_customer_from_alegra() writes
                // billing_nit to USER meta; the guest branch used to read an
                // order meta key (`billing_nit`, no underscore) that nothing
                // ever writes, so it was dead (AC-76).
                $nit = $customer ? (string) get_user_meta($customer->ID, 'billing_nit', true) : '';
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

                // Steps 4-5 — build the payload and create the contact.
                // AC-26: validate() (not has_critical_data()) so a LEGAL_ENTITY
                // with an enabled-but-empty `company` is rejected here instead of
                // producing a nameless contact that Alegra answers with a 400.
                $values = $this->collect_billing_values($order, $customer);
                $validation = \Alegra\Connector\Billing_Fields::validate($values);
                if (!is_wp_error($validation)) {
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
                // Visibility: in `auto` mode this fallback is UNINTENTIONAL, so
                // the merchant must learn that the invoice went to the generic
                // consumer instead of the customer. `always_generic` returned
                // early above (intentional choice — no note) and `require_data`
                // is excluded here (it aborts instead of falling back).
                if ($mode === 'auto') {
                    $this->add_consumidor_final_fallback_note($order, $customer);
                }
                return (string) $cf;
            }
            $this->logger->error('Consumidor Final could not be resolved', ['order_id' => $order_id]);
        } else {
            $this->logger->error('Customer data required but could not be resolved', ['order_id' => $order_id]);
        }
        return '';
    }

    /**
     * Add an order note when the Consumidor Final fallback is UNINTENTIONAL
     * (resolution mode `auto`) because the customer is missing identification.
     *
     * Returns early — no note — when no catalog field is actually missing, so a
     * fallback caused by an API failure is never mislabelled as missing data.
     */
    private function add_consumidor_final_fallback_note(\WC_Order $order, ?\WP_User $customer): void
    {
        $missing = $this->missing_billing_field_labels($order, $customer);
        if ($missing === []) {
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: the billing field(s) the customer is missing, e.g. "número de documento". */
            __('Alegra: la factura se emitirá a nombre del Consumidor Final porque el cliente no tiene %s registrado. Si necesitas la factura a nombre del cliente, agrega su cédula/NIT y vuelve a facturar.', 'alegra-connector'),
            implode(' ni ', $missing)
        ));
    }

    /**
     * Labels of the enabled catalog fields that are empty for this order's
     * customer, lowercased for inline use in a sentence. Empty when every
     * critical field is present.
     *
     * @return list<string>
     */
    private function missing_billing_field_labels(\WC_Order $order, ?\WP_User $customer): array
    {
        $values = $this->collect_billing_values($order, $customer);
        if (\Alegra\Connector\Billing_Fields::has_critical_data($values)) {
            return [];
        }

        $labels = [];
        foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
            if (!\Alegra\Connector\Billing_Fields::is_field_enabled($key)) {
                continue;
            }
            // `dv` is only required for a NIT; skip it for every other id type.
            if ($key === 'dv' && ($values['idtype'] ?? '') !== 'NIT') {
                continue;
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                $labels[] = strtolower((string) ($field['label'] ?? $key));
            }
        }

        return $labels;
    }

    /**
     * Persist the resolved Alegra contact id on the order and, when present,
     * on the customer.
     *
     * Contact-id meta naming (deliberately left as-is; renaming without a
     * migration would orphan data on live stores):
     * - order:  `_billing_alegra_contact_id`
     * - user:   `alegra_contact_id`
     * - term:   `alegra_category_id`
     * The three storages are different entity types; the prefix drift is
     * historical and every read/write uses the same key for its entity.
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
            // AC-38: a disabled field is never collected.
            if (!\Alegra\Connector\Billing_Fields::is_field_enabled($key)) {
                $values[$key] = '';
                continue;
            }
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

    /**
     * Coupon/discount for a line, as a percentage of its pre-discount subtotal.
     *
     * Alegra's `items[].discount` is a percentage (post_invoices.md), and the
     * line `price` must NOT include the discount. Returns 0 when there is none.
     */
    private static function line_discount_percent(float $subtotal, float $line_total): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = (($subtotal - $line_total) / $subtotal) * 100;
        if ($discount <= 0) {
            return 0.0;
        }

        return round($discount, 4);
    }

    private function prepare_invoice_items(\WC_Order $order): array|\WP_Error
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            // AC-10: `items[].id` is obligatory for Alegra invoices. An id-less
            // line is either a 400 or a free-text line that breaks inventory.
            // Refuse to build the invoice rather than send a malformed one.
            if ($alegra_item_id === '') {
                return new \WP_Error(
                    'invoice_item_unlinked',
                    sprintf(
                        /* translators: %s: WooCommerce product name */
                        __('No se pudo vincular el producto "%s" con un ítem de Alegra. Sincroniza el producto antes de facturar.', 'alegra-connector'),
                        $item_obj->get_name()
                    )
                );
            }

            $subtotal = (float) $item_obj->get_subtotal();
            $line_total = (float) $item_obj->get_total();
            $quantity = (int) $item_obj->get_quantity();
            $price = (float) ($subtotal / max(1, $quantity));

            $item_data = [
                'id' => $alegra_item_id,
                'name' => $item_obj->get_name(),
                'price' => $price,
                'quantity' => $quantity,
            ];

            // AC-48: map the per-line coupon/discount to Alegra's `discount`
            // (a percentage, per post_invoices.md). Without it the invoice total
            // is HIGHER than the WooCommerce order total. `price` stays
            // pre-discount; Alegra applies the percentage.
            $discount = self::line_discount_percent($subtotal, $line_total);
            if ($discount > 0) {
                $item_data['discount'] = $discount;
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

    /**
     * Resolve the Alegra item id for a WC product/variation.
     *
     * Order: stored meta → SKU lookup → push the product to Alegra (which
     * creates/links the item and stores `_alegra_item_id`). Returns '' when the
     * item cannot be resolved.
     */
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

        // Last resort: push the product (or variation) so Alegra assigns an id.
        $target_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($target_id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($target_id);
            if ($product instanceof \WC_Product) {
                $sync = new Products($this->api, $this->logger);
                $result = $sync->sync_to_alegra($product);
                if (!is_wp_error($result) && isset($result['id'])) {
                    return (string) $result['id'];
                }
                if ($this->logger) {
                    $this->logger->warning('Could not push product to resolve Alegra item id', [
                        'product_id' => $target_id,
                        'error'      => is_wp_error($result) ? $result->get_error_message() : 'missing id',
                    ]);
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

        // AC-18: the poll issues one HTTP call per order. Without a lock two
        // overlapping cron ticks duplicate every call. Wrap it in the same
        // per-type mutex the other sync steps use.
        $lock = self::acquire_poll_lock();
        if ($lock === false) {
            $this->logger->info('Invoice status poll skipped: another poll is running');
            return $result;
        }

        try {
            // AC-18: a sane, configurable batch. The API cost is high (one
            // request per order) so the default is deliberately lower than the
            // old 50. At a 5-min cron this is 288 * 20 = 5.7k calls/day vs the
            // old ~14k.
            $limit = (int) get_option('alegra_connector_orders_poll_batch', 20);
            if ($limit < 1) {
                $limit = 20;
            }

            // AC-18: fetch IDs + filter in SQL. `return => ids` avoids
            // hydrating full WC_Order objects just to read one meta.
            $query = [
                'limit'   => $limit,
                'status'  => ['processing', 'pending', 'on-hold'],
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
                'meta_query' => [[
                    'key'     => '_alegra_invoice_id',
                    'value'   => '',
                    'compare' => '!=',
                ]],
            ];

            $order_ids = wc_get_orders($query);
            $should_complete = get_option('alegra_connector_auto_complete_order', true);

            foreach ((array) $order_ids as $order_id) {
                // Re-check the kill switch so an in-flight poll stops.
                if (\Alegra\Connector\Kill_Switch::is_active()) {
                    $this->logger->info('Invoice status poll stopped: kill switch active');
                    break;
                }

                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }
                $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
                if ($invoice_id === '') {
                    continue;
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
                    if ($order->get_status() !== 'completed') {
                        $order->add_order_note(sprintf(
                            __('[Alegra] Factura #%s pagada. Pedido completado automaticamente.', 'alegra-connector'),
                            $invoice['numberTemplate']['fullNumber'] ?? $invoice_id
                        ));
                        $order->update_status('completed');
                        $result['completed']++;
                    }
                }
            }
        } finally {
            self::release_poll_lock($lock);
        }

        return $result;
    }

    private static function acquire_poll_lock(): string|false
    {
        return Controller::acquire_lock('alegra_sync_running_orders', 300);
    }

    private static function release_poll_lock(string $token): void
    {
        Controller::release_lock('alegra_sync_running_orders', $token);
    }
}