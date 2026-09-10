<?php
/**
 * Sync Controller
 *
 * @package Alegra\Connector\Sync
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Heartbeat;
use Alegra\Connector\Kill_Switch;
use Alegra\Connector\Logger;
use Alegra\Connector\Runs;

if (!defined('ABSPATH')) {
    exit;
}

class Controller
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;
    private Products $products;
    private Customers $customers;
    private Orders $orders;
    private Categories $categories;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;

        $this->products = new Products($api, $logger);
        $this->customers = new Customers($api, $logger);
        $this->orders = new Orders($api, $logger);
        $this->categories = new Categories($api, $logger);
    }

    /**
     * Register the cron sync hook - call this explicitly
     */
    public function register_cron_hook(): void
    {
        if (!has_action('alegra_connector_cron_sync', [$this, 'run_cron_sync'])) {
            add_action('alegra_connector_cron_sync', [$this, 'run_cron_sync']);
        }
    }

    public function run_cron_sync(): void
    {
        // Kill switch guard — fast (<0.1ms) thanks to static cache
        if (Kill_Switch::is_active()) {
            $this->logger->info('Cron sync skipped: kill switch active', [
                'reason' => Kill_Switch::reason(),
            ]);
            return;
        }

        $this->logger->info('Starting cron synchronization (Alegra → WC only)');

        // Wrap entire run in Runs::track for the Monitor
        Runs::track('cron_sync_all', function ($run_id) {
            $this->run_cron_sync_inner($run_id);
        }, 'cron');
    }

    private function run_cron_sync_inner(int $run_id): void
    {
        $result = [
            'products' => 0,
            'customers' => 0,
            'orders' => 0,
            'categories' => 0,
            'errors' => [],
        ];
        $step = 0;
        $total_steps = 4;

        // PULL only: Alegra → WC
        if (get_option('alegra_connector_sync_products', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'products', 'message' => __('Importando productos desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during products');
                return;
            }
            $lock = $this->acquire_sync_lock('products');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped products: another sync is running');
            } else {
                try {
                    $import_result = $this->products->import_from_alegra();
                } finally {
                    $this->release_sync_lock('products', $lock);
                }
            }
            if (isset($import_result) && !is_wp_error($import_result)) {
                $result['products'] = ($import_result['imported'] ?? 0) + ($import_result['updated'] ?? 0);
            } elseif (isset($import_result) && is_wp_error($import_result)) {
                $result['errors']['products'] = $import_result->get_error_message();
            }
        }

        if (get_option('alegra_connector_sync_customers', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'customers', 'message' => __('Importando clientes desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during customers');
                return;
            }
            $lock = $this->acquire_sync_lock('customers');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped customers: another sync is running');
            } else {
                try {
                    $import_result = $this->customers->import_from_alegra();
                } finally {
                    $this->release_sync_lock('customers', $lock);
                }
            }
            if (isset($import_result) && !is_wp_error($import_result)) {
                $result['customers'] = ($import_result['imported'] ?? 0) + ($import_result['updated'] ?? 0);
            } elseif (isset($import_result) && is_wp_error($import_result)) {
                $result['errors']['customers'] = $import_result->get_error_message();
            }
        }

        if (get_option('alegra_connector_sync_categories', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'categories', 'message' => __('Importando categorias desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during categories');
                return;
            }
            $lock = $this->acquire_sync_lock('categories');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped categories: another sync is running');
            } else {
                try {
                    $categories_result = $this->categories->import_from_alegra();
                } finally {
                    $this->release_sync_lock('categories', $lock);
                }
            }
            if (isset($categories_result) && !is_wp_error($categories_result)) {
                $result['categories'] = ($categories_result['imported'] ?? 0) + ($categories_result['updated'] ?? 0);
            } elseif (isset($categories_result) && is_wp_error($categories_result)) {
                $result['errors']['categories'] = $categories_result->get_error_message();
            }
        }

        // Always run polling for invoice statuses
        if (get_option('alegra_connector_sync_orders', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'orders', 'message' => __('Verificando estado de facturas en Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            $poll_result = $this->orders->poll_invoice_statuses();
            $result['orders'] = ($poll_result['completed'] ?? 0);
        }

        update_transient('alegra_connector_last_sync', time());

        Heartbeat::set($run_id, ['step' => 'done', 'message' => sprintf(
            __('Completado: %d productos, %d clientes, %d ordenes, %d categorias', 'alegra-connector'),
            $result['products'], $result['customers'], $result['orders'], $result['categories']
        ), 'step_num' => $total_steps, 'total_steps' => $total_steps]);
        Runs::update_progress($run_id, $total_steps, $total_steps);

        $this->logger->info('Cron synchronization completed', $result);
    }

    public function sync_entity(string $type, int $id, string $action): array|\WP_Error
    {
        $this->logger->info('Syncing entity', ['type' => $type, 'id' => $id, 'action' => $action]);

        switch ($type) {
            case 'product':
                return $this->sync_single_product($id, $action);
            case 'customer':
                return $this->sync_single_customer($id, $action);
            case 'order':
                return $this->sync_single_order($id, $action);
            case 'category':
                return $this->sync_single_category($id, $action);
            default:
                return new \WP_Error('unknown_type', 'Unknown entity type: ' . $type);
        }
    }

    private function sync_single_product(int $id, string $action): array|\WP_Error
    {
        $product = wc_get_product($id);
        if (!$product) {
            return new \WP_Error('not_found', 'Product not found');
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->products->sync_to_alegra($product);
            case 'delete':
                return $this->products->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_customer(int $id, string $action): array|\WP_Error
    {
        $customer = get_userdata($id);
        if (!$customer) {
            return new \WP_Error('not_found', 'Customer not found');
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->customers->sync_to_alegra($customer);
            case 'delete':
                return $this->customers->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_order(int $id, string $action): array|\WP_Error
    {
        $order = wc_get_order($id);
        if (!$order) {
            return new \WP_Error('not_found', 'Order not found');
        }

        switch ($action) {
            case 'create':
                return $this->orders->create_invoice($order);
            case 'complete':
                return $this->orders->create_invoice_with_payment($order);
            case 'refund':
                return $this->orders->create_credit_note($order);
            case 'cancel':
                return $this->orders->void_invoice($order);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_category(int $id, string $action): array|\WP_Error
    {
        $term = get_term($id, 'product_cat');
        if (!$term) {
            return new \WP_Error('not_found', 'Category not found');
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->categories->sync_to_alegra($term);
            case 'delete':
                return $this->categories->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    public function import_from_alegra(string $type): array|\WP_Error
    {
        switch ($type) {
            case 'products':
                return $this->products->import_from_alegra();
            case 'customers':
                return $this->customers->import_from_alegra();
            case 'categories':
                return $this->categories->import_from_alegra();
            default:
                return new \WP_Error('unknown_type', 'Unknown import type: ' . $type);
        }
    }

    /**
     * Try to acquire a transient lock for the given sync type.
     *
     * Returns a unique token string on success, or false if another process
     * already holds the lock. TTL is 300 seconds (5 min) so a crashed run
     * cannot block future runs forever.
     *
     * @param string $type One of: 'products', 'customers', 'categories'.
     * @return string|false Token on success, false if locked.
     */
    private function acquire_sync_lock(string $type): string|false
    {
        $key = "alegra_sync_running_{$type}";
        $existing = get_transient($key);
        if ($existing !== false) {
            return false;
        }
        $token = wp_generate_password(20, false);
        set_transient($key, $token, 300);
        $winner = get_transient($key);
        if ($winner !== $token) {
            return false;
        }
        return $token;
    }

    /**
     * Release a transient lock — only if the token matches.
     *
     * This prevents a stale process from accidentally releasing a fresh lock
     * after a 5-min TTL turnover.
     *
     * @param string $type  Sync type (same key as acquire).
     * @param string $token Token from acquire_sync_lock().
     */
    private function release_sync_lock(string $type, string $token): void
    {
        $key = "alegra_sync_running_{$type}";
        $current = get_transient($key);
        if ($current === $token) {
            delete_transient($key);
        }
    }

    /**
     * Public wrapper for acquire_sync_lock — for use from other sync classes.
     */
    public static function acquire_sync_lock_public(string $type): string|false
    {
        return (new self(null, null))->acquire_sync_lock($type);
    }

    /**
     * Public wrapper for release_sync_lock — for use from other sync classes.
     */
    public static function release_sync_lock_public(string $type, string $token): void
    {
        (new self(null, null))->release_sync_lock($type, $token);
    }
}