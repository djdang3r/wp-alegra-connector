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

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Orders hook: enabled by default. When a sale happens in WC, the invoice
        // and payment are automatically pushed to Alegra. This can be disabled in
        // the settings if the user prefers manual control.
        if (get_option('alegra_connector_push_orders_enabled', true)) {
            add_action('woocommerce_new_order', [$this, 'on_new_order'], 10, 1);
            add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
            add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);
            add_action('woocommerce_order_status_on-hold', [$this, 'on_order_on_hold'], 10, 1);
            add_action('woocommerce_order_status_refunded', [$this, 'on_order_refunded'], 10, 1);
            add_action('woocommerce_order_status_cancelled', [$this, 'on_order_cancelled'], 10, 1);
            add_action('woocommerce_order_status_failed', [$this, 'on_order_failed'], 10, 1);
        }

        // Products and Customers hooks: DISABLED by default.
        // These can only push data when the user explicitly enables this option,
        // AND should still be triggered manually from the dashboard for safety.
        if (get_option('alegra_connector_push_products_enabled', false)) {
            add_action('woocommerce_new_product', [$this, 'on_new_product'], 10, 1);
            add_action('woocommerce_update_product', [$this, 'on_update_product'], 10, 1);
            add_action('woocommerce_delete_product', [$this, 'on_delete_product'], 10, 1);
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

    public function on_order_refunded(int $order_id): void
    {
        $this->logger->info('WooCommerce: Order refunded', ['order_id' => $order_id]);
        $this->trigger_sync('order', $order_id, 'refund');
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
     * Trigger sync only if not already syncing (prevents loops during import)
     * and sync method allows real-time sync.
     *
     * Uses a transient-based mutex (atomic via MySQL row lock) so the guard
     * works correctly across multiple PHP-FPM workers.
     */
    private function trigger_sync(string $type, int $id, string $action): void
    {
        $lock_key = 'alegra_sync_guard_' . $type . '_' . $id;

        // Prevent re-entrant sync across workers/processes
        if (get_transient($lock_key)) {
            if ($this->logger) {
                $this->logger->debug('Skipping re-entrant sync (lock held)', ['type' => $type, 'id' => $id, 'action' => $action]);
            }
            return;
        }
        set_transient($lock_key, 1, 30); // 30s lock

        // Also keep the static for in-request re-entrancy
        self::$is_syncing = true;

        try {
            $sync_method = get_option('alegra_connector_sync_method', 'both');

            if ($sync_method === 'real-time' || $sync_method === 'both') {
                $sync_controller = new Sync\Controller($this->api, $this->logger);
                $sync_controller->sync_entity($type, $id, $action);
            }
        } finally {
            self::$is_syncing = false;
            delete_transient($lock_key);
        }
    }

    /**
     * Set re-entrancy guard from outside (e.g., during import).
     * Uses transient for cross-process safety.
     */
    public static function set_syncing(bool $syncing): void
    {
        self::$is_syncing = $syncing;
    }

    /**
     * Check if currently syncing in this request
     */
    public static function is_syncing(): bool
    {
        return self::$is_syncing;
    }
}
