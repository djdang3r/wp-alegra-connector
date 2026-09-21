<?php
/**
 * Public Hooks and REST API
 *
 * @package Alegra\Connector\Public
 */

declare(strict_types=1);

namespace Alegra\Connector\Public;

use Alegra\Connector\API;
use Alegra\Connector\Logger;
use Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class Public_
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    /**
     * Re-entrancy guard: prevents hooks from firing during import/sync operations
     */
    private static bool $is_syncing = false;

    /**
     * AC-84: the push-hook enablement options are read ONCE here, when the
     * object is built on `plugins_loaded`. Toggling them in wp-admin during the
     * same request does not retroactively register/unregister hooks; the new
     * state applies from the next request. That is intentional — registering
     * hooks mid-request is unpredictable — so the options are not re-evaluated.
     */
    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Orders hook: DISABLED by default (manual mode). Only when the merchant
        // enables this option does a WC sale push the invoice (and, on payment,
        // the payment) to Alegra automatically. This option is the ONLY switch
        // for outbound order uploads; the inbound `sync_method` option must not
        // gate it (that overlap silently disabled the toggle).
        if (get_option('alegra_connector_push_orders_enabled', false)) {
            add_action('woocommerce_new_order', [$this, 'on_new_order'], 10, 1);
            add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
            add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);
            add_action('woocommerce_order_status_on-hold', [$this, 'on_order_on_hold'], 10, 1);
            // Refunds are owned exclusively by State_Sync::handle_refund()
            // (woocommerce_order_refunded). Hooking status_refunded here too
            // issued a SECOND credit note for a full refund.
            add_action('woocommerce_order_status_cancelled', [$this, 'on_order_cancelled'], 10, 1);
            add_action('woocommerce_order_status_failed', [$this, 'on_order_failed'], 10, 1);
        }

        // Payment reconciliation: ALWAYS active, independent of push_orders_enabled.
        // It never creates invoices; it only records the payment on an invoice that
        // was already linked. `on_order_paid_reconcile` is deliberately a distinct
        // method from `on_payment_complete`, which stays inside the gate (T12.1).
        add_action('woocommerce_payment_complete', [$this, 'on_order_paid_reconcile'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_paid_reconcile'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_paid_reconcile'], 10, 1);

        // Product hooks: DISABLED by default. They push only when the merchant
        // explicitly enables the products option.
        if (get_option('alegra_connector_push_products_enabled', false)) {
            add_action('woocommerce_new_product', [$this, 'on_new_product'], 10, 1);
            add_action('woocommerce_update_product', [$this, 'on_update_product'], 10, 1);
            add_action('woocommerce_delete_product', [$this, 'on_delete_product'], 10, 1);
        }

        // Customer hooks: an INDEPENDENT toggle (REQ-CFG-4). Enabling products
        // must never enable customers on its own.
        if (get_option('alegra_connector_push_customers_enabled', false)) {
            add_action('woocommerce_new_customer', [$this, 'on_new_customer'], 10, 1);
            add_action('woocommerce_update_customer', [$this, 'on_update_customer'], 10, 1);
        }
    }

    /**
     * Permission callback for sync endpoint - requires admin capability
     */
    public function sync_permission_check(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void
    {
        register_rest_route('alegra-connector/v1', '/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_sync_request'],
            'permission_callback' => [$this, 'sync_permission_check'],
        ]);
    }

    /**
     * Handle manual sync request via REST API
     */
    public function handle_sync_request(\WP_REST_Request $request): \WP_REST_Response
    {
        return \Alegra\Connector\Write_Gate::run_explicit(fn () => $this->handle_sync_request_impl($request));
    }

    private function handle_sync_request_impl(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();

        if (!isset($params['action'])) {
            return new \WP_REST_Response(['error' => 'Missing action parameter'], 400);
        }

        $this->logger->info('REST sync request received', [
            'action' => sanitize_text_field($params['action']),
        ]);

        switch ($params['action']) {
            case 'sync_product':
                return $this->sync_product($params);
            case 'sync_customer':
                return $this->sync_customer($params);
            case 'sync_order':
                return $this->sync_order($params);
            default:
                return new \WP_REST_Response(['error' => 'Unknown action'], 400);
        }
    }

    // ============================
    // REST Sync Handlers
    // ============================

    private function sync_product(array $params): \WP_REST_Response
    {
        if (!isset($params['product_id'])) {
            return new \WP_REST_Response(['error' => 'Missing product_id'], 400);
        }

        $product_id = (int) $params['product_id'];
        $product = wc_get_product($product_id);

        if (!$product) {
            return new \WP_REST_Response(['error' => 'Product not found'], 404);
        }

        $this->logger->info('Syncing product to Alegra', ['product_id' => $product_id]);

        $sync = new Sync\Products($this->api, $this->logger);
        $result = $sync->sync_to_alegra($product);

        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    private function sync_customer(array $params): \WP_REST_Response
    {
        if (!isset($params['customer_id'])) {
            return new \WP_REST_Response(['error' => 'Missing customer_id'], 400);
        }

        $customer_id = (int) $params['customer_id'];
        $customer = get_userdata($customer_id);

        if (!$customer) {
            return new \WP_REST_Response(['error' => 'Customer not found'], 404);
        }

        $this->logger->info('Syncing customer to Alegra', ['customer_id' => $customer_id]);

        $sync = new Sync\Customers($this->api, $this->logger);
        $result = $sync->sync_to_alegra($customer);

        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    private function sync_order(array $params): \WP_REST_Response
    {
        if (!isset($params['order_id'])) {
            return new \WP_REST_Response(['error' => 'Missing order_id'], 400);
        }

        $order_id = (int) $params['order_id'];
        $order = wc_get_order($order_id);

        if (!$order) {
            return new \WP_REST_Response(['error' => 'Order not found'], 404);
        }

        $this->logger->info('Syncing order to Alegra', ['order_id' => $order_id]);

        $sync = new Sync\Orders($this->api, $this->logger);
        $result = $sync->create_invoice($order);

        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    // ============================
    // WooCommerce Event Handlers (Real-Time Sync WC → Alegra)
    // ============================

    public function on_new_product(int $product_id): void
    {
        $this->logger->info('WooCommerce: New product', ['product_id' => $product_id]);
        $this->trigger_sync('product', $product_id, 'create');
    }

    public function on_update_product(int $product_id): void
    {
        // Skip if we're the one updating (anti-loop)
        if (get_transient('alegra_updating_product_' . $product_id)) {
            return;
        }
        $this->logger->info('WooCommerce: Product updated', ['product_id' => $product_id]);
        $this->trigger_sync('product', $product_id, 'update');
    }

    public function on_delete_product(int $product_id): void
    {
        $this->logger->info('WooCommerce: Product deleted', ['product_id' => $product_id]);
        $this->trigger_sync('product', $product_id, 'delete');
    }

    public function on_new_order(int $order_id): void
    {
        $this->logger->info('WooCommerce: New order', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'create');
    }

    public function on_order_completed(int $order_id): void
    {
        $this->logger->info('WooCommerce: Order completed', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'complete');
    }

    public function on_order_cancelled(int $order_id): void
    {
        $this->logger->info('WooCommerce: Order cancelled', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'cancel');
    }

    public function on_payment_complete(int $order_id): void
    {
        $this->logger->info('WooCommerce: Payment complete', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'complete');
    }

    public function on_order_on_hold(int $order_id): void
    {
        $this->logger->info('WooCommerce: Order on-hold', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'create');
    }

    public function on_order_failed(int $order_id): void
    {
        $this->logger->info('WooCommerce: Order failed', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'cancel');
    }

    /**
     * Always-active payment reconciliation (REQ-REC-1..3, REQ-REC-5).
     *
     * Fires when WooCommerce marks an order as paid (`payment_complete`, or the
     * processing/completed status). When the invoice was uploaded earlier but the
     * payment was not final yet, this attaches the payment to the EXISTING
     * invoice. It never creates an invoice: with no linked invoice it does
     * nothing, so manual mode keeps creating invoices only by hand.
     */
    public function on_order_paid_reconcile(int $order_id): void
    {
        if (self::$is_syncing) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        $payment_id = (string) $order->get_meta('_alegra_payment_id', true);

        // Guard exacto (REQ-REC-2): factura vinculada Y sin pago Y pagado.
        if ($invoice_id === '' || $payment_id !== '' || !$order->is_paid()) {
            return;
        }

        $account = (string) get_option('alegra_connector_payment_account_id', '');
        if (in_array($account, ['', '0'], true)) {
            $order->add_order_note(__(
                '[Alegra] El pedido está pagado y tiene factura, pero no hay cuenta de destino configurada; el pago NO se registró. Configurala en Ajustes > Avanzado.',
                'alegra-connector'
            ));
            if ($this->logger) {
                $this->logger->warning('Payment reconciliation skipped: no payment account configured', [
                    'order_id'   => $order_id,
                    'invoice_id' => $invoice_id,
                ]);
            }
            return;
        }

        $orders = new Sync\Orders($this->api, $this->logger);
        $orders->reconcile_payment_only($order);
    }

    public function on_new_customer(int $customer_id): void
    {
        $this->logger->info('WooCommerce: New customer', ['customer_id' => $customer_id]);
        $this->trigger_sync('customer', $customer_id, 'create');
    }

    public function on_update_customer(int $customer_id): void
    {
        $this->logger->info('WooCommerce: Customer updated', ['customer_id' => $customer_id]);
        $this->trigger_sync('customer', $customer_id, 'update');
    }

    /**
     * Trigger sync only if not already syncing (prevents loops during import).
     *
     * Outbound (WC → Alegra) pushes are gated exclusively by the per-entity
     * push options at hook-registration time (see __construct). This method
     * must NOT consult `sync_method`: that option controls the inbound
     * (Alegra → WC) method only, and reading it here made the push toggles
     * silently ineffective whenever the method was 'cron' or 'disabled'.
     *
     * Uses an atomic lock (add_option UNIQUE index) so the guard works
     * correctly across multiple PHP-FPM workers.
     */
    private function trigger_sync(string $type, int $id, string $action): void
    {
        // AC-43: honour the cross-process import flag, not just the per-request
        // static. During a long import in worker A, a concurrent WC save in
        // worker B must not push the product being imported.
        if (get_transient('alegra_import_in_progress')) {
            if ($this->logger) {
                $this->logger->debug('Skipping sync: an import is in progress', ['type' => $type, 'id' => $id]);
            }
            return;
        }

        $lock_key = 'alegra_sync_guard_' . $type . '_' . $id;

        // Prevent re-entrant sync across workers/processes
        $token = Sync\Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            if ($this->logger) {
                $this->logger->debug('Skipping re-entrant sync (lock held)', ['type' => $type, 'id' => $id, 'action' => $action]);
            }
            return;
        }

        // Also keep the static for in-request re-entrancy
        self::$is_syncing = true;

        try {
            $sync_controller = new Sync\Controller($this->api, $this->logger);
            $result = $sync_controller->sync_entity($type, $id, $action);

            // BUG 7: the auto path used to discard the result, so a failed order
            // push was only logged. Surface the real reason on the order. No
            // note on success (avoids noise on every order event).
            if (is_wp_error($result) && $type === 'order') {
                $order = wc_get_order($id);
                if ($order instanceof \WC_Order) {
                    $order->add_order_note(sprintf(
                        /* translators: %s: the error Alegra returned. */
                        __('Alegra: no se pudo sincronizar el pedido con Alegra (%s). Revisá la configuración y volvé a intentarlo desde el panel.', 'alegra-connector'),
                        $result->get_error_message()
                    ));
                }
                if ($this->logger) {
                    $this->logger->error('Automatic order sync failed', [
                        'order_id' => $id,
                        'action'   => $action,
                        'error'    => $result->get_error_message(),
                    ]);
                }
            }
        } finally {
            self::$is_syncing = false;
            Sync\Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * Set re-entrancy guard from outside (e.g., during import).
     * Uses transient for cross-process safety.
     */
    public static function set_syncing(bool $syncing): void
    {
        self::$is_syncing = $syncing;

        // AC-43: the docblock promised cross-process safety; make it real.
        // Importers call this so other PHP-FPM workers skip their WC hooks.
        if ($syncing) {
            set_transient('alegra_import_in_progress', 1, 300);
        } else {
            delete_transient('alegra_import_in_progress');
        }
    }

    /**
     * Check if currently syncing in this request
     */
    public static function is_syncing(): bool
    {
        return self::$is_syncing;
    }
}
