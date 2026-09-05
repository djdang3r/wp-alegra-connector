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
            $import_result = $this->products->import_from_alegra();
            if (!is_wp_error($import_result)) {
                $result['products'] = ($import_result['imported'] ?? 0) + ($import_result['updated'] ?? 0);
            } else {
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
            $import_result = $this->customers->import_from_alegra();
            if (!is_wp_error($import_result)) {
                $result['customers'] = ($import_result['imported'] ?? 0) + ($import_result['updated'] ?? 0);
            } else {
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
            $categories_result = $this->categories->import_from_alegra();
            if (!is_wp_error($categories_result)) {
                $result['categories'] = ($categories_result['imported'] ?? 0) + ($categories_result['updated'] ?? 0);
            } else {
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
}