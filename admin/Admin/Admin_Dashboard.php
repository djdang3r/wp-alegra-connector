<?php
/**
 * Admin Dashboard
 *
 * @package Alegra\Connector\Admin
 */

declare(strict_types=1);

namespace Alegra\Connector\Admin;

use Alegra\Connector\API;
use Alegra\Connector\HPOS;
use Alegra\Connector\Logger;
use Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Dashboard
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_alegra_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_alegra_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_alegra_import_csv', [$this, 'ajax_import_csv']);
        add_action('wp_ajax_alegra_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_alegra_download_logs', [$this, 'ajax_download_logs']);
        add_action('wp_ajax_alegra_save_mapping', [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_alegra_sync_single', [$this, 'ajax_sync_single']);
        add_action('wp_ajax_alegra_record_payment', [$this, 'ajax_record_payment']);
        add_action('wp_ajax_alegra_import_from_api', [$this, 'ajax_import_from_api']);
        add_action('wp_ajax_alegra_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_alegra_bulk_sync', [$this, 'ajax_bulk_sync']);
        add_action('wp_ajax_alegra_get_invoice_pdf', [$this, 'ajax_get_invoice_pdf']);
        add_action('wp_ajax_alegra_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_alegra_check_endpoints', [$this, 'ajax_check_endpoints']);
        add_action('wp_ajax_alegra_sync_progress', [$this, 'ajax_sync_progress']);
        add_action('wp_ajax_alegra_sync_start', [$this, 'ajax_sync_start']);
        add_action('wp_ajax_alegra_sync_page', [$this, 'ajax_sync_page']);
        add_action('wp_ajax_alegra_import_single', [$this, 'ajax_import_single']);
        add_action('wp_ajax_alegra_bulk_import', [$this, 'ajax_bulk_import']);
        add_action('wp_ajax_alegra_cleanup_placeholders', [$this, 'ajax_cleanup_placeholders']);
        add_action('wp_ajax_alegra_sync_pending_orders', [$this, 'ajax_sync_pending_orders']);
        add_action('wp_ajax_alegra_sync_pending_start', [$this, 'ajax_sync_pending_start']);
        add_action('wp_ajax_alegra_sync_pending_page', [$this, 'ajax_sync_pending_page']);
        add_action('wp_ajax_alegra_cancel_sync', [$this, 'ajax_cancel_sync']);
        add_action('wp_ajax_alegra_register_webhooks', [$this, 'ajax_register_webhooks']);
        add_action('wp_ajax_alegra_delete_webhooks', [$this, 'ajax_delete_webhooks']);
        add_action('wp_ajax_alegra_cleanup_duplicate_images', [$this, 'ajax_cleanup_duplicate_images']);
        add_action('wp_ajax_alegra_monitor_status', [$this, 'ajax_monitor_status']);
        add_action('wp_ajax_alegra_kill_run', [$this, 'ajax_kill_run']);
        add_action('wp_ajax_alegra_kill_all', [$this, 'ajax_kill_all']);
        add_action('wp_ajax_alegra_clear_kill_switch', [$this, 'ajax_clear_kill_switch']);
        add_action('wp_ajax_alegra_approve_push', [$this, 'ajax_approve_push']);
        add_action('wp_ajax_alegra_reject_push', [$this, 'ajax_reject_push']);
        add_action('wp_ajax_alegra_push_queue_status', [$this, 'ajax_push_queue_status']);
        add_action('wp_ajax_alegra_rebuild_image_index', [$this, 'ajax_rebuild_image_index']);
        add_action('wp_ajax_alegra_save_sync_directions', [$this, 'ajax_save_sync_directions']);
        add_action('wp_ajax_alegra_image_index_stats', [$this, 'ajax_image_index_stats']);
        add_action('wp_ajax_alegra_wizard_advance', [$this, 'ajax_wizard_advance']);
        add_action('wp_ajax_alegra_wizard_skip', [$this, 'ajax_wizard_skip']);
        add_action('wp_ajax_alegra_run_cron_now', [$this, 'ajax_run_cron_now']);
        add_action('wp_ajax_alegra_skip_cron_next', [$this, 'ajax_skip_cron_next']);
        add_action('wp_ajax_alegra_unschedule_cron', [$this, 'ajax_unschedule_cron']);
        add_action('wp_ajax_alegra_change_cron_frequency', [$this, 'ajax_change_cron_frequency']);
    }

    /**
     * Null-safe logger
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) return;
        switch ($level) {
            case 'info': $this->logger->info($message, $context); break;
            case 'warning': $this->logger->warning($message, $context); break;
            case 'error': $this->logger->error($message, $context); break;
        }
    }

    private function get_product_by_alegra_id(int $alegra_id): ?int
    {
        global $wpdb;
        $pid = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id' AND meta_value=%d LIMIT 1", $alegra_id));
        return $pid ? (int)$pid : null;
    }

    /**
     * Download and attach image from Alegra to WooCommerce product
     */
    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 15);
        if (is_wp_error($tmp)) {
            error_log('[Alegra] Image download failed for product ' . $product_id . ': ' . $tmp->get_error_message());
            return;
        }

        $file_array = [
            'name' => 'alegra-' . $product_id . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $product_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        } else {
            error_log('[Alegra] Image sideload failed: ' . $attachment_id->get_error_message());
        }

        @unlink($tmp);
    }

    public function add_admin_menu(): void
    {
        add_menu_page(
            __('Alegra Connector', 'alegra-connector'),
            __('Alegra Connector', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector',
            [$this, 'render_dashboard'],
            'dashicons-update',
            30
        );

        add_submenu_page(
            'alegra-connector',
            __('Dashboard', 'alegra-connector'),
            __('Dashboard', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'alegra-connector',
            __('Estad sticas', 'alegra-connector'),
            __('Estad sticas', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-stats',
            [$this, 'render_statistics_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Productos', 'alegra-connector'),
            __('Productos', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-products',
            [$this, 'render_products_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Clientes', 'alegra-connector'),
            __('Clientes', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-customers',
            [$this, 'render_customers_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Pedidos', 'alegra-connector'),
            __('Pedidos', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-orders',
            [$this, 'render_orders_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Mapeo de Campos', 'alegra-connector'),
            __('Mapeo', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-mapping',
            [$this, 'render_mapping_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Logs', 'alegra-connector'),
            __('Logs', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-logs',
            [$this, 'render_logs_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Importar', 'alegra-connector'),
            __('Importar', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-import',
            [$this, 'render_import_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Configuración', 'alegra-connector'),
            __('Configuración', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Documentación', 'alegra-connector'),
            __('Documentación', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-docs',
            [$this, 'render_docs_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Monitor', 'alegra-connector'),
            __('Monitor', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-monitor',
            [$this, 'render_monitor_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Cola de Push', 'alegra-connector'),
            __('Cola Push', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-push-queue',
            [$this, 'render_push_queue_page']
        );

        add_submenu_page(
            'alegra-connector',
            __('Asistente', 'alegra-connector'),
            __('Asistente', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-wizard',
            [$this, 'render_wizard_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('alegra_connector_settings', 'alegra_connector_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('alegra_connector_settings', 'alegra_connector_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_api_url', [
            'sanitize_callback' => function ($value) {
                $url = sanitize_url($value);
                return $url ?: 'https://api.alegra.com/api/v1';
            },
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_frequency', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_method', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_push_orders_enabled', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_push_products_enabled', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_currency', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_log_retention_days', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_conflict_resolution', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_products', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_customers', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_orders', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_categories', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_inventory_source', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_warehouse_id', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_warehouse_enabled', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_payment_account_id', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_payment_term_id', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_auto_complete_order', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_images', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_inactive_products', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_images_mode', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_webhook_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_field_mapping', [
            'sanitize_callback' => function ($value) {
                return is_array($value) ? map_deep($value, 'sanitize_text_field') : [];
            },
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_tax_mapping', [
            'sanitize_callback' => function ($value) {
                return is_array($value) ? map_deep($value, 'sanitize_text_field') : [];
            },
        ]);

        // Mapping settings group
        register_setting('alegra_connector_mapping', 'alegra_connector_field_mapping');
        register_setting('alegra_connector_mapping', 'alegra_connector_tax_mapping');

        add_settings_section('alegra_connector_connection', __('Conexi n con Alegra', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_sync_settings', __('Sincronización', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_currency_section', __('Moneda', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_warehouse_section', __('Bodegas', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_advanced', __('Avanzado', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'alegra-connector') === false) {
            return;
        }

        wp_enqueue_style('alegra-connector-admin', ALEGRA_CONNECTOR_URL . 'admin/assets/css/admin.css', [], ALEGRA_CONNECTOR_VERSION);
        wp_enqueue_script('alegra-connector-admin', ALEGRA_CONNECTOR_URL . 'admin/assets/js/admin.js', ['jquery'], ALEGRA_CONNECTOR_VERSION, true);

        wp_localize_script('alegra-connector-admin', 'alegraConnector', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alegra_connector_nonce'),
            'strings' => [
                'testing' => __('Probando conexi n...', 'alegra-connector'),
                'success' => __('Conexi n exitosa', 'alegra-connector'),
                'error' => __('Error de conexi n', 'alegra-connector'),
                'syncing' => __('Sincronizando...', 'alegra-connector'),
                'importing' => __('Importando...', 'alegra-connector'),
            ],
        ]);
    }

    public function render_dashboard(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta p gina.', 'alegra-connector'));
        }

        $is_connected = (bool) get_option('alegra_connector_connection_tested');
        $company_name = esc_html(get_option('alegra_connector_company_name', ''));
        $last_sync = get_transient('alegra_connector_last_sync');
        $stats = $this->get_sync_stats();
        $header_color = 'indigo';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-dashboard.php';
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta p gina.', 'alegra-connector'));
        }

        $connected = (bool) get_option('alegra_connector_connection_tested');
        $alegra_currencies = [];
        $alegra_warehouses = [];
        $alegra_bank_accounts = [];
        $alegra_terms = [];

        if ($connected && $this->api) {
            $cr = $this->api->get_currencies();
            if (!is_wp_error($cr)) $alegra_currencies = $cr;
            $wr = $this->api->get_warehouses();
            if (!is_wp_error($wr)) $alegra_warehouses = $wr;
            $ba = $this->api->get_bank_accounts();
            if (!is_wp_error($ba)) $alegra_bank_accounts = $ba;
            $tm = $this->api->get_terms();
            if (!is_wp_error($tm)) $alegra_terms = $tm;
        }

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-settings.php';
    }

    public function render_docs_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        $doc_file = ALEGRA_CONNECTOR_PATH . 'docs/DOCUMENTACION.md';
        $content = '';
        if (file_exists($doc_file)) {
            $content = file_get_contents($doc_file);
        }

        $header_color = 'indigo';
        include ALEGRA_CONNECTOR_PATH . 'templates/admin-docs.php';
    }

    public function render_monitor_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }
        include ALEGRA_CONNECTOR_PATH . 'templates/admin-monitor.php';
    }

    public function render_push_queue_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }
        include ALEGRA_CONNECTOR_PATH . 'templates/admin-push-queue.php';
    }

    public function render_wizard_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }
        include ALEGRA_CONNECTOR_PATH . 'templates/admin-wizard.php';
    }

    public function render_logs_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta p gina.', 'alegra-connector'));
        }

        $log_files = $this->logger ? $this->logger->get_log_files() : [];
        $log_entries = $this->logger ? $this->logger->get_logs(200) : [];
        $system_logs = $this->logger ? $this->logger->get_system_logs(50) : [];

        // Log Statistics
        $log_stats = ['total' => 0, 'info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0, 'today' => 0, 'system' => count($system_logs)];
        foreach ($log_entries as $entry) {
            $log_stats['total']++;
            $lv = strtolower($entry['level'] ?? '');
            if (isset($log_stats[$lv])) $log_stats[$lv]++;
            if (strpos($entry['timestamp'] ?? '', date('Y-m-d')) === 0) $log_stats['today']++;
        }

        $header_color = 'purple';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-logs.php';
    }

    public function render_import_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta p gina.', 'alegra-connector'));
        }

        $header_color = 'green';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-import.php';
    }

    public function render_mapping_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta p gina.', 'alegra-connector'));
        }

        $field_mapping = get_option('alegra_connector_field_mapping', []);
        $tax_mapping = get_option('alegra_connector_tax_mapping', []);
        $connected = (bool) get_option('alegra_connector_connection_tested');

        $alegra_taxes = [];
        $alegra_price_lists = [];
        $alegra_categories = [];

        if ($connected && $this->api) {
            $t = $this->api->get_taxes();
            if (!is_wp_error($t)) { $alegra_taxes = $t; }
            $p = $this->api->get_price_lists();
            if (!is_wp_error($p)) { $alegra_price_lists = $p; }
            $c = $this->api->get_item_categories();
            if (!is_wp_error($c)) { $alegra_categories = $c; }
        }

        $header_color = 'indigo';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-mapping.php';
    }

    public function render_statistics_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        // Default period: last 30 days
        $end = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : date('Y-m-d');
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-d', strtotime('-30 days'));
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30d';

        $stats = $this->get_statistics_data($start, $end, $period);
        extract($stats);
        $header_color = 'blue';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-statistics.php';
    }

    private function get_statistics_data(string $start, string $end, string $period = '30d'): array
    {
        global $wpdb;
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

        $revenue = ['completed' => 0, 'processing' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'total' => 0];
        $order_counts = ['completed' => 0, 'processing' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'total' => 0];
        $payment_methods = [];
        $daily_sales = [];
        $top_products = [];
        $recent_orders = [];

        $orders_table = HPOS::get_orders_table();
        $meta_table = HPOS::get_order_meta_table();

        if (HPOS::is_enabled()) {
            $order_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT o.id, o.status, om.meta_value as total
                 FROM {$orders_table} o
                 LEFT JOIN {$meta_table} om ON o.id=om.order_id AND om.meta_key='_order_total'
                 WHERE o.type='shop_order'
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 ORDER BY o.date_created_gmt DESC
                 LIMIT 5000",
                $start, $end . ' 23:59:59'
            ));
        } else {
            $order_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_status, pm_total.meta_value as total
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID=pm_total.post_id AND pm_total.meta_key='_order_total'
                 WHERE p.post_type='shop_order'
                 AND p.post_date >= %s AND p.post_date <= %s
                 ORDER BY p.post_date DESC
                 LIMIT 5000",
                $start, $end . ' 23:59:59'
            ));
        }

        if (!is_array($order_rows)) $order_rows = [];

        $order_ids = [];
        foreach ($order_rows as $row) {
            $order_ids[] = (int) $row->id ?? (int) $row->ID;
            $status = str_replace('wc-', '', $row->status ?? $row->post_status);
            $total = (float) ($row->total ?? 0);

            $revenue['total'] += $total;
            $order_counts['total']++;

            if (array_key_exists($status, $revenue)) {
                $revenue[$status] += $total;
                $order_counts[$status]++;
            }

            $date_field = $row->date_created_gmt ?? $row->post_date ?? '';
            $date = substr($date_field, 0, 10);
            if ($date) {
                if (!isset($daily_sales[$date])) $daily_sales[$date] = ['count' => 0, 'total' => 0];
                $daily_sales[$date]['count']++;
                $daily_sales[$date]['total'] += $total;
            }
        }

        // Load recent 5 orders fully for display
        $recent_ids = array_slice($order_ids, 0, 5);
        foreach ($recent_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            $recent_orders[] = [
                'id' => $oid,
                'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '',
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'total' => (float) $order->get_total(),
                'status' => $order->get_status(),
            ];
        }

        // Top products via SQL aggregation (orders within period)
        if (HPOS::is_enabled()) {
            $top_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_item_name, SUM(oi_meta.meta_value) as total_qty, SUM(oim_total.meta_value) as total_rev, COUNT(DISTINCT oi.order_id) as order_count
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_meta ON oi.order_item_id=oi_meta.order_item_id AND oi_meta.meta_key='_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id=oim_total.order_item_id AND oim_total.meta_key='_line_total'
                 INNER JOIN {$orders_table} o ON oi.order_id=o.id AND o.type='shop_order'
                 WHERE o.date_created_gmt >= %s AND o.date_created_gmt <= %s AND oi.order_item_type='line_item'
                 GROUP BY oi.order_item_name
                 ORDER BY total_qty DESC
                 LIMIT 10",
                $start, $end . ' 23:59:59'
            ));
        } else {
            $top_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_item_name, SUM(oi_meta.meta_value) as total_qty, SUM(oim_total.meta_value) as total_rev, COUNT(DISTINCT oi.order_id) as order_count
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_meta ON oi.order_item_id=oi_meta.order_item_id AND oi_meta.meta_key='_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id=oim_total.order_item_id AND oim_total.meta_key='_line_total'
                 INNER JOIN {$wpdb->posts} p ON oi.order_id=p.ID AND p.post_type='shop_order'
                 WHERE p.post_date >= %s AND p.post_date <= %s AND oi.order_item_type='line_item'
                 GROUP BY oi.order_item_name
                 ORDER BY total_qty DESC
                 LIMIT 10",
                $start, $end . ' 23:59:59'
            ));
        }

        if (is_array($top_rows)) {
            foreach ($top_rows as $tr) {
                $top_products[$tr->order_item_name] = [
                    'qty' => (int) $tr->total_qty,
                    'total' => (float) $tr->total_rev,
                    'orders' => (int) $tr->order_count,
                ];
            }
        }

        // Payment methods via SQL
        if (HPOS::is_enabled()) {
            $pm_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value as method, COUNT(*) as cnt, SUM(pm_total.meta_value) as total
                 FROM {$orders_table} o
                 INNER JOIN {$meta_table} pm ON o.id=pm.order_id AND pm.meta_key='_payment_method_title'
                 LEFT JOIN {$meta_table} pm_total ON o.id=pm_total.order_id AND pm_total.meta_key='_order_total'
                 WHERE o.type='shop_order' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 GROUP BY pm.meta_value",
                $start, $end . ' 23:59:59'
            ));
        } else {
            $pm_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value as method, COUNT(*) as cnt, SUM(pm_total.meta_value) as total
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_payment_method_title'
                 LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID=pm_total.post_id AND pm_total.meta_key='_order_total'
                 WHERE p.post_type='shop_order' AND p.post_date >= %s AND p.post_date <= %s
                 GROUP BY pm.meta_value",
                $start, $end . ' 23:59:59'
            ));
        }

        if (is_array($pm_rows)) {
            foreach ($pm_rows as $pmr) {
                $label = $pmr->method ?: 'Unknown';
                $payment_methods[$label] = ['count' => (int) $pmr->cnt, 'total' => (float) $pmr->total];
            }
        }

        // Previous period revenue (SQL)
        $interval = strtotime($end) - strtotime($start) + 86400;
        $prev_start = date('Y-m-d', strtotime($start) - $interval);
        $prev_end = date('Y-m-d', strtotime($start) - 86400);

        if (HPOS::is_enabled()) {
            $prev_revenue = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(pm.meta_value), 0) FROM {$orders_table} o
                 INNER JOIN {$meta_table} pm ON o.id=pm.order_id AND pm.meta_key='_order_total'
                 WHERE o.type='shop_order' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s",
                $prev_start, $prev_end . ' 23:59:59'
            ));
        } else {
            $prev_revenue = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(pm.meta_value), 0) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total'
                 WHERE p.post_type='shop_order' AND p.post_date >= %s AND p.post_date <= %s",
                $prev_start, $prev_end . ' 23:59:59'
            ));
        }

        $growth = 0;
        if ($prev_revenue > 0 && $revenue['total'] > 0) {
            $growth = round((($revenue['total'] - $prev_revenue) / $prev_revenue) * 100, 1);
        }

        $abandoned_count = $order_counts['pending'] + $order_counts['cancelled'];
        $abandoned_value = $revenue['pending'] + $revenue['cancelled'];
        $abandoned_rate = $order_counts['total'] > 0 ? round(($abandoned_count / $order_counts['total']) * 100, 1) : 0;
        $conversion_rate = $order_counts['total'] > 0 ? round(($order_counts['completed'] / $order_counts['total']) * 100, 1) : 0;
        $avg_order = $order_counts['completed'] > 0 ? $revenue['completed'] / $order_counts['completed'] : 0;

        ksort($daily_sales);

        // Customers (SQL, accurate)
        $total_customers = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID=um.user_id AND um.meta_key='{$wpdb->prefix}capabilities'
             WHERE um.meta_value LIKE '%\"customer\"%'"
        );
        $new_customers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered <= %s",
            $start, $end . ' 23:59:59'
        ));

        // Sync stats (SQL, accurate and unlimited)
        $orders_total = HPOS::is_enabled()
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table} WHERE type='shop_order'")
            : (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'");

        $sync_stats = [
            'products_synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id'"),
            'products_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"),
            'orders_synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_alegra_invoice_id'"),
            'orders_total' => $orders_total,
            'customers_synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='alegra_contact_id' AND meta_value > 0"),
            'customers_total' => $total_customers,
        ];

        return compact(
            'revenue', 'order_counts', 'payment_methods', 'daily_sales',
            'top_products', 'sync_stats', 'new_customers', 'start', 'end', 'period',
            'currency_symbol', 'growth', 'prev_revenue', 'abandoned_count', 'abandoned_value',
            'abandoned_rate', 'conversion_rate', 'avg_order', 'recent_orders'
        );
    }

    public function render_products_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;

        // Check for single product detail view
        $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        if ($product_id > 0) {
            $this->render_product_detail($product_id);
            return;
        }

        global $wpdb;

        // Read filter and search params
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : 'all';

        // Build WHERE clauses for search and filter
        $where_filter = '';
        $post_type_clause = '';
        $show_variations = false;

        if ($post_type_filter === 'variable') {
            $post_type_clause = "AND (SELECT t.slug FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' LIMIT 1) = 'variable'";
        } elseif ($post_type_filter === 'simple') {
            $post_type_clause = "AND (p.post_type='product') AND (SELECT t.slug FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' LIMIT 1) IS NULL OR (SELECT t.slug FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' LIMIT 1) = 'simple')";
        } elseif ($post_type_filter === 'variations') {
            $post_type_clause = "AND p.post_type='product_variation'";
            $show_variations = true;
        } else {
            $post_type_clause = "AND p.post_type='product'";
        }

        if ($filter === 'synced') {
            $where_filter = "AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id')";
        } elseif ($filter === 'pending') {
            $where_filter = "AND p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id')";
        }

        $where_search = '';
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            if ($show_variations) {
                $where_search = $wpdb->prepare(
                    "AND (p.post_title LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s) OR p.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s))",
                    $like, $like, $like
                );
            } else {
                $where_search = $wpdb->prepare(
                    "AND (p.post_title LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s))",
                    $like, $like
                );
            }
        }

        // Direct SQL for reliable count with filters
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE 1=1 {$post_type_clause} AND p.post_status='publish' {$where_filter} {$where_search}"
        );
        $total_pages = (int) ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;

        // Get products with all needed data in a single query (avoids 20+ wc_get_product calls)
        // Use DISTINCT to avoid duplicate rows from multiple postmeta entries
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_parent,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) as sku,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_price' LIMIT 1) as price,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_alegra_item_id' LIMIT 1) as alegra_id,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_thumbnail_id' LIMIT 1) as thumbnail_id,
                    (SELECT t.slug FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                     INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                     WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' LIMIT 1) as product_type,
                    (SELECT post_title FROM {$wpdb->posts} WHERE ID=p.post_parent LIMIT 1) as parent_title
             FROM {$wpdb->posts} p
             WHERE 1=1 {$post_type_clause} AND p.post_status='publish' {$where_filter} {$where_search}
             ORDER BY p.ID DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));

        $products = [];
        foreach ((array) $rows as $row) {
            $thumb_id = (int) ($row->thumbnail_id ?: 0);
            $thumb_url = '';
            $thumb_alt = '';
            if ($thumb_id > 0) {
                $thumb_src = wp_get_attachment_image_src($thumb_id, 'thumbnail');
                if ($thumb_src && is_array($thumb_src)) {
                    $thumb_url = $thumb_src[0];
                }
                $thumb_alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                if (empty($thumb_alt)) {
                    $thumb_alt = $row->post_title;
                }
            }
            $products[] = [
                'id' => (int) $row->ID,
                'name' => $row->post_title,
                'sku' => $row->sku ?: '',
                'price' => (float) ($row->price ?: 0),
                'type' => $row->product_type ?: 'simple',
                'parent_id' => (int) $row->post_parent,
                'parent_title' => $row->parent_title ?: '',
                'alegra_id' => (int) ($row->alegra_id ?: 0),
                'thumbnail_id' => $thumb_id,
                'thumbnail_url' => $thumb_url,
                'thumbnail_alt' => $thumb_alt,
            ];
        }

        // Sync stats - only count main products (exclude variations)
        $synced_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key='_alegra_item_id' AND p.post_type='product'"
        );
        $variable_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID=tr.object_id 
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id 
             INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id 
             WHERE p.post_type='product' AND p.post_status='publish' AND tt.taxonomy='product_type' AND t.slug='variable'"
        );
        $variation_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status='publish'"
        );
        $synced_variations = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key='_alegra_item_id' AND p.post_type='product_variation'"
        );

        $header_color = 'green';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-products.php';
    }

    private function render_product_detail(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_die(esc_html__('Producto no encontrado.', 'alegra-connector'));
        }

        $alegra_id = (int) get_post_meta($product_id, '_alegra_item_id', true);
        $alegra_data = null;
        $alegra_error = null;

        if ($alegra_id > 0 && get_option('alegra_connector_connection_tested') && $this->api) {
            $result = $this->api->get_item($alegra_id);
            if (is_wp_error($result)) {
                $alegra_error = sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message());
            } else {
                $alegra_data = $result;
            }
        }

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-product-detail.php';
    }

    public function render_customers_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        global $wpdb;

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;

        // Single customer detail
        $customer_id = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        if ($customer_id > 0) {
            $this->render_customer_detail($customer_id);
            return;
        }

        $args = [
            'role' => 'customer',
            'number' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
        ];

        $customers = get_users($args);
        $total_users = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID=um.user_id AND um.meta_key='{$wpdb->prefix}capabilities'
             WHERE um.meta_value LIKE '%\"customer\"%'"
        );
        $total_pages = (int) ceil($total_users / $per_page);

        $synced_customers = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='alegra_contact_id' AND meta_value > 0"
        );

        $header_color = 'teal';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-customers.php';
    }

    private function render_customer_detail(int $customer_id): void
    {
        $customer = get_userdata($customer_id);
        if (!$customer) {
            wp_die(esc_html__('Cliente no encontrado.', 'alegra-connector'));
        }

        $alegra_id = (int) get_user_meta($customer_id, 'alegra_contact_id', true);
        $alegra_data = null;
        $alegra_error = null;

        if ($alegra_id > 0 && get_option('alegra_connector_connection_tested') && $this->api) {
            $result = $this->api->get_contact($alegra_id);
            if (is_wp_error($result)) {
                $alegra_error = sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message());
            } else {
                $alegra_data = $result;
            }
        }

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-customer-detail.php';
    }

    public function render_orders_page(): void
    {
        global $wpdb;

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;

        // Single order detail
        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($order_id > 0) {
            $this->render_order_detail($order_id);
            return;
        }

        $args = [
            'limit' => $per_page,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $orders = wc_get_orders($args);
        $orders_table = HPOS::get_orders_table();
        if (HPOS::is_enabled()) {
            $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table} WHERE type='shop_order'");
        } else {
            $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'");
        }
        $total_pages = (int) ceil($total_orders / $per_page);

        $synced_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_alegra_invoice_id' AND meta_value > 0");
        $payment_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_alegra_payment_id' AND meta_value > 0");

        $header_color = 'amber';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-orders.php';
    }

    private function render_order_detail(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Pedido no encontrado.', 'alegra-connector'));
        }

        $alegra_invoice_id = (int) get_post_meta($order_id, '_alegra_invoice_id', true);
        $alegra_invoice_number = get_post_meta($order_id, '_alegra_invoice_number', true);
        $alegra_payment_id = (int) get_post_meta($order_id, '_alegra_payment_id', true);
        $alegra_data = null;
        $alegra_error = null;

        // Make API available for templates via local variable (cleaner than $GLOBALS)
        $alegra_api = $this->api;

        if ($alegra_invoice_id > 0 && get_option('alegra_connector_connection_tested') && $this->api) {
            $result = $this->api->get_invoice($alegra_invoice_id);
            if (is_wp_error($result)) {
                $alegra_error = sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message());
            } else {
                $alegra_data = $result;
            }
        }

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-order-detail.php';
    }

    public function ajax_test_connection(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $token = sanitize_text_field($_POST['token'] ?? '');

        if (empty($email) || empty($token)) {
            wp_send_json_error(['message' => __('Email y token son requeridos.', 'alegra-connector')]);
        }

        $this->log('info', 'Connection test started', ['email' => $email]);

        // Save credentials
        update_option('alegra_connector_email', $email);
        update_option('alegra_connector_token', $token);

        // Quick pre-flight: verify the API URL is reachable
        $base_url = rtrim((string) get_option('alegra_connector_api_url', 'https://api.alegra.com/api/v1'), '/');
        $preflight = wp_remote_head($base_url . '/company', [
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($email . ':' . $token),
            ],
        ]);

        if (is_wp_error($preflight)) {
            update_option('alegra_connector_connection_tested', false);
            $this->log('error', 'Connection test: network unreachable', ['error' => $preflight->get_error_message()]);
            wp_send_json_error([
                'message' => __('No se pudo contactar el servidor de Alegra.', 'alegra-connector'),
                'code' => 'network_error',
                'http_code' => 0,
            ]);
        }

        if (!is_wp_error($preflight)) {
            $result = $this->api->test_connection($email, $token);
        } else {
            $result = $preflight;
        }

        if (is_wp_error($result)) {
            update_option('alegra_connector_connection_tested', false);
            $code = $result->get_error_code();
            $msg = $result->get_error_message();
            $data = $result->get_error_data();
            $http_code = is_array($data) ? ($data['code'] ?? 0) : 0;

            $this->log('error', 'Connection test failed', [
                'error_code' => $code, 'http_code' => $http_code, 'message' => $msg,
            ]);

            wp_send_json_error([
                'message' => $msg,
                'code' => $code,
                'http_code' => $http_code,
            ]);
        }

        // Save connection info
        if (isset($result['company'])) {
            update_option('alegra_connector_company_name', sanitize_text_field($result['company']));
        }
        if (isset($result['country'])) {
            update_option('alegra_connector_company_country', sanitize_text_field($result['country']));
        }
        if (isset($result['email'])) {
            update_option('alegra_connector_company_email', sanitize_text_field($result['email']));
        }
        if (isset($result['items_count'])) {
            update_option('alegra_connector_items_count', (int) $result['items_count']);
        }
        if (isset($result['contacts_count'])) {
            update_option('alegra_connector_contacts_count', (int) $result['contacts_count']);
        }
        update_option('alegra_connector_connection_tested', true);

        $diagnostics = $result['diagnostics'] ?? [];
        update_option('alegra_connector_diagnostics', $diagnostics);

        $this->log('info', 'Connection test successful', ['company' => $result['company'] ?? 'Unknown', 'diagnostics' => $diagnostics]);

        wp_send_json_success([
            'message' => __('Conexión exitosa', 'alegra-connector'),
            'company' => $result['company'] ?? '',
            'country' => $result['country'] ?? '',
            'email' => $result['email'] ?? '',
            'items_count' => $result['items_count'] ?? 0,
            'contacts_count' => $result['contacts_count'] ?? 0,
            'diagnostics' => $diagnostics,
        ]);
    }

    public function ajax_sync_now(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        $sync_type = sanitize_text_field($_POST['sync_type'] ?? 'all');
        $sync_controller = new Sync\Controller($this->api, $this->logger);

        if ($sync_type === 'all') {
            $sync_controller->run_cron_sync();
            wp_send_json_success(['message' => __('Sincronización completa.', 'alegra-connector')]);
        }
        // For specific types, redirect to chunked sync via JS (handled by ajax_sync_start + ajax_sync_page)
        wp_send_json_success(['message' => 'ok', 'use_chunked' => true, 'type' => $sync_type]);
    }

    /**
     * AJAX: Start chunked sync - get EXACT total via metadata=true
     */
    public function ajax_sync_start(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $type = sanitize_text_field($_POST['sync_type'] ?? 'products');
        $this->api->reload_credentials();

        // Get exact total via metadata=true (single API call!)
        $total = 0;
        if ($type === 'customers') {
            $resp = $this->api->get('/contacts', ['metadata' => 'true', 'limit' => 1, 'type' => 'client']);
            if (!is_wp_error($resp) && isset($resp['metadata']['total'])) {
                $total = (int) $resp['metadata']['total'];
            }
        } else {
            $resp = $this->api->get('/items', ['metadata' => 'true', 'limit' => 1]);
            if (!is_wp_error($resp) && isset($resp['metadata']['total'])) {
                $total = (int) $resp['metadata']['total'];
            }
        }

        $per_page = 30;
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        $state = [
            'type' => $type, 'page' => 0, 'per_page' => $per_page,
            'total_pages' => $total_pages, 'total_items' => $total,
            'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        ];
        set_transient('alegra_batch_state', $state, 600);

        wp_send_json_success([
            'total_pages' => $total_pages, 'total_items' => $total, 'per_page' => $per_page,
        ]);
    }

    /**
     * Process one page of chunked sync
     */
    public function ajax_sync_page(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $state = get_transient('alegra_batch_state');
        if (!$state) wp_send_json_error(['message' => 'No batch in progress']);

        $page = ((int) ($state['page'] ?? 0)) + 1;
        $type = $state['type'];
        $per_page = 30; // Max allowed by Alegra API

        // Lock the per-type sync so cron + this chunked AJAX can't both pull.
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($type);
        if ($lock === false) {
            wp_send_json_error(['message' => __('Another sync is in progress. Please wait.', 'alegra-connector')]);
        }
        try {
        $this->api->reload_credentials();
        @set_time_limit(60);
        wp_raise_memory_limit();

        if ($type === 'products') {
            $start = ($page - 1) * $per_page;
            // Build API params. Filter by status if user only wants active products.
            $api_params = ['start' => $start, 'limit' => $per_page, 'mode' => 'advanced'];
            if (!get_option('alegra_connector_sync_inactive_products', false)) {
                $api_params['status'] = 'active';
            }
            $items = $this->api->get('/items', $api_params);
            if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()]); }

            $products_sync = new Sync\Products($this->api, $this->logger);
            foreach ($items as $item) {
                $alegra_id = (int) ($item['id'] ?? 0);
                $item_type = $item['type'] ?? 'simple';

                // Skip variants - imported with their parent
                if ($item_type === 'variant') { continue; }

                // Use Products class for proper import (handles variable, images, etc.)
                // Already-linked products will be updated, new ones created
                $r = $products_sync->import_single_item_public($item);
                if ($r === true) { $state['imported']++; }
                elseif ($r === 'updated') { $state['updated']++; }
                elseif ($r === 'skipped') { continue; }
                else { $state['errors']++; }
            }
            $is_last = count($items) < $per_page;
        } elseif ($type === 'customers') {
            $contacts_start = ($page - 1) * $per_page;
            $items = $this->api->get('/contacts', ['start' => $contacts_start, 'limit' => $per_page, 'type' => 'client']);
            if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()]); }

            $customers_sync = new Sync\Customers($this->api, $this->logger);
            foreach ($items as $item) {
                $alegra_id = (int)($item['id'] ?? 0);
                $contact_name = $item['name'] ?? '';
                $email = $item['email'] ?? '';

                if (empty($email)) {
                    $state['skipped'] = ($state['skipped'] ?? 0) + 1;
                    continue;
                }

                $existing_user_id = email_exists($email);
                if ($existing_user_id) {
                    update_user_meta($existing_user_id, 'alegra_contact_id', $alegra_id);
                    $customers_sync->import_single_contact_public($item);
                    $state['updated']++;
                } else {
                    $name_parts = explode(' ', $contact_name, 2);
                    $uid = wp_insert_user([
                        'user_email' => $email, 'user_login' => $email,
                        'first_name' => $name_parts[0] ?? '', 'last_name' => $name_parts[1] ?? '',
                        'role' => 'customer',
                    ]);
                    if (!is_wp_error($uid)) {
                        update_user_meta($uid, 'alegra_contact_id', $alegra_id);
                        $customers_sync->import_single_contact_public($item);
                        $state['imported']++;
                    } else { $state['errors']++; }
                }
            }
            $is_last = count($items) < $per_page;
        } else {
            $items = $this->api->get_item_categories(['page' => $page, 'limit' => $per_page]);
            if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()]); }
            foreach ($items as $item) {
                $t = wp_insert_term($item['name'] ?? '', 'product_cat', ['description' => $item['description'] ?? '']);
                if (!is_wp_error($t)) {
                    update_term_meta($t['term_id'], 'alegra_category_id', (int)($item['id'] ?? 0));
                    $state['imported']++;
                } else { $state['errors']++; }
            }
            $is_last = count($items) < $per_page;
        }

        $state['page'] = $page;
        $item_count = is_array($items) ? count($items) : 0;
        if ($is_last) {
            $state['total_pages'] = $page;
            $state['total_items'] = (($page - 1) * $per_page) + $item_count;
        }

        $processed = $state['imported'] + $state['updated'] + $state['errors'] + ($state['skipped'] ?? 0);
        $tp = (int)($state['total_pages'] ?? 0);
        $done = $is_last || ($tp > 0 && $page >= $tp) || empty($items);

        // Restore original sync_images setting when sync completes, otherwise keep state
        if ($done) {
            delete_transient('alegra_batch_state');
        } else {
            set_transient('alegra_batch_state', $state, 600);
        }

        $pct = $tp > 0 ? min(100, round(($page / $tp) * 100)) : 0;
        wp_send_json_success([
            'page' => $page, 'total_pages' => $tp, 'total_items' => (int)($state['total_items'] ?? 0),
            'imported' => $state['imported'], 'updated' => $state['updated'], 'skipped' => $state['skipped'] ?? 0, 'errors' => $state['errors'],
            'processed' => $processed, 'percent' => $pct, 'done' => $done,
            'message' => sprintf('%d/%d items — Pag. %d/%d', $processed, (int)($state['total_items'] ?? 0), $page, $tp),
        ]);
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
        }
    }
    public function ajax_import_csv(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('No se encontr  archivo CSV.', 'alegra-connector')]);
        }

        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Error al subir archivo.', 'alegra-connector')]);
        }

        $import_type = sanitize_text_field($_POST['import_type'] ?? 'products');

        $result = $this->process_csv_import($file['tmp_name'], $import_type);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        wp_send_json_success([
            'message' => sprintf(__('Importaci n completada: %d items', 'alegra-connector'), $result['count']),
            'data' => $result,
        ]);
    }

    public function ajax_clear_logs(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        if ($this->logger === null) {
            wp_send_json_error(['message' => __('Logger no inicializado.', 'alegra-connector')]);
        }

        $retention = (int) get_option('alegra_connector_log_retention_days', 30);
        $count = $this->logger->clear_old_logs($retention);

        wp_send_json_success(['message' => sprintf(__('%d logs eliminados.', 'alegra-connector'), $count)]);
    }

    public function ajax_download_logs(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos.', 'alegra-connector'));
        }

        $log_file = sanitize_text_field($_GET['log_file'] ?? '');
        $this->logger->download_log($log_file);
    }

    public function ajax_save_mapping(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $field_mapping = isset($_POST['field_mapping']) ? (array) $_POST['field_mapping'] : [];
        $tax_mapping = isset($_POST['tax_mapping']) ? (array) $_POST['tax_mapping'] : [];

        update_option('alegra_connector_field_mapping', map_deep($field_mapping, 'sanitize_text_field'));
        update_option('alegra_connector_tax_mapping', map_deep($tax_mapping, 'sanitize_text_field'));

        $this->logger->info('Field mapping saved');

        wp_send_json_success(['message' => __('Mapeo guardado.', 'alegra-connector')]);
    }

    public function ajax_record_payment(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $order_id = (int) ($_POST['order_id'] ?? 0);
        if ($order_id <= 0) {
            wp_send_json_error(['message' => __('ID de pedido inv lido.', 'alegra-connector')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'alegra-connector')]);
        }

        $alegra_invoice_id = (int) get_post_meta($order_id, '_alegra_invoice_id', true);
        if ($alegra_invoice_id <= 0) {
            wp_send_json_error(['message' => __('Primero crea la factura en Alegra.', 'alegra-connector')]);
        }

        // Check if payment already recorded
        $existing_payment = (int) get_post_meta($order_id, '_alegra_payment_id', true);
        if ($existing_payment > 0) {
            wp_send_json_error(['message' => __('El pago ya est  registrado en Alegra.', 'alegra-connector')]);
        }

        $account_id = (int) get_option('alegra_connector_payment_account_id', 0);
        if ($account_id <= 0) {
            wp_send_json_error(['message' => __('Configura una cuenta bancaria en Ajustes > Avanzado.', 'alegra-connector')]);
        }

        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
        $payment_data = [
            'date' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d') : date('Y-m-d'),
            'bankAccount' => ['id' => $account_id],
            'invoices' => [
                [
                    'id' => $alegra_invoice_id,
                    'amount' => (float) $order->get_total(),
                ],
            ],
            'paymentMethod' => $orders_sync->getPaymentMethodForGateway($order->get_payment_method()),
        ];

        $result = $this->api->create_payment($payment_data);

        if (is_wp_error($result)) {
            $this->logger->error('Payment recording failed', [
                'order_id' => $order_id,
                'error' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message()),
            ]);
            wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        $payment_id = (int) ($result['id'] ?? 0);
        update_post_meta($order_id, '_alegra_payment_id', $payment_id);
        if (!empty($result['number'])) {
            update_post_meta($order_id, '_alegra_payment_number', $result['number']);
        }

        $order->add_order_note(sprintf(
            __('Pago Alegra #%s registrado.', 'alegra-connector'),
            $result['number'] ?? $payment_id
        ));

        $this->logger->info('Payment recorded in Alegra', [
            'order_id' => $order_id,
            'payment_id' => $payment_id,
            'invoice_id' => $alegra_invoice_id,
        ]);

        wp_send_json_success([
            'message' => __('Pago registrado en Alegra.', 'alegra-connector'),
            'payment_id' => $payment_id,
        ]);
    }

    public function ajax_sync_single(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $entity_type = sanitize_text_field($_POST['entity_type'] ?? '');
        $entity_id = (int) ($_POST['entity_id'] ?? 0);

        if ($entity_id <= 0) {
            wp_send_json_error(['message' => __('ID invalido.', 'alegra-connector')]);
        }

        $sync_controller = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);

        switch ($entity_type) {
            case 'product': $result = $sync_controller->sync_entity('product', $entity_id, 'update'); break;
            case 'customer': $result = $sync_controller->sync_entity('customer', $entity_id, 'update'); break;
            case 'order': $result = $sync_controller->sync_entity('order', $entity_id, 'create'); break;
            default: wp_send_json_error(['message' => __('Tipo de entidad desconocido.', 'alegra-connector')]);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        wp_send_json_success(['message' => __('Sincronización completada.', 'alegra-connector'), 'data' => $result]);
    }

    public function ajax_export_csv(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die();

        $type = sanitize_text_field($_GET['export_type'] ?? 'products');

        if ($type === 'products') {
            $products = wc_get_products(['limit' => 500, 'status' => 'publish']);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="alegra-productos-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'sku', 'price', 'stock', 'type', 'alegra_id', 'sync_status']);
            foreach ($products as $p) {
                $ai = (int) get_post_meta($p->get_id(), '_alegra_item_id', true);
                fputcsv($out, [
                    $p->get_name(), $p->get_sku(), $p->get_price(), $p->get_stock_quantity(),
                    $p->get_type(), $ai > 0 ? $ai : '', $ai > 0 ? 'Sincronizado' : 'Pendiente',
                ]);
            }
            fclose($out);
        } else {
            $customers = get_users(['role' => 'customer', 'number' => 500]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="alegra-clientes-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'email', 'phone', 'alegra_id', 'sync_status']);
            foreach ($customers as $c) {
                $ai = (int) get_user_meta($c->ID, 'alegra_contact_id', true);
                fputcsv($out, [
                    $c->display_name, $c->user_email, get_user_meta($c->ID, 'billing_phone', true),
                    $ai > 0 ? $ai : '', $ai > 0 ? 'Sincronizado' : 'Pendiente',
                ]);
            }
            fclose($out);
        }
        exit;
    }

    public function ajax_bulk_sync(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        $type = sanitize_text_field($_POST['entity_type'] ?? '');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (empty($ids)) wp_send_json_error(['message' => __('Seleccióna al menos un elemento.', 'alegra-connector')]);

        $sync_controller = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);
        $synced = 0; $errors = 0;

        foreach ($ids as $id) {
            $action = $type === 'order' ? 'create' : 'update';
            $r = $sync_controller->sync_entity($type, $id, $action);
            is_wp_error($r) ? $errors++ : $synced++;
        }

        wp_send_json_success(['message' => sprintf(__('%d sincronizados, %d errores.', 'alegra-connector'), $synced, $errors)]);
    }

    public function ajax_import_single(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        $type = sanitize_text_field($_POST['entity_type'] ?? '');
        $wc_id = (int) ($_POST['entity_id'] ?? 0);

        if ($wc_id <= 0) wp_send_json_error(['message' => __('ID invalido.', 'alegra-connector')]);

        if ($type === 'product') {
            $alegra_id = (int) get_post_meta($wc_id, '_alegra_item_id', true);
            if ($alegra_id <= 0) wp_send_json_error(['message' => __('Producto no vinculado a Alegra.', 'alegra-connector')]);
            $sync = new Sync\Products($this->api, $this->logger);
            $result = $sync->sync_single_item_by_alegra_id($alegra_id);
        } elseif ($type === 'customer') {
            $alegra_id = (int) get_user_meta($wc_id, 'alegra_contact_id', true);
            if ($alegra_id <= 0) wp_send_json_error(['message' => __('Cliente no vinculado a Alegra.', 'alegra-connector')]);
            $sync = new Sync\Customers($this->api, $this->logger);
            $result = $sync->sync_single_contact_by_alegra_id($alegra_id);
        } else {
            wp_send_json_error(['message' => __('Tipo desconocido.', 'alegra-connector')]);
        }

        if ($result === false) {
            wp_send_json_error(['message' => __('No se pudo importar desde Alegra.', 'alegra-connector')]);
        }

        wp_send_json_success(['message' => $result === 'updated' ? __('Actualizado desde Alegra.', 'alegra-connector') : __('Importado desde Alegra.', 'alegra-connector')]);
    }

    public function ajax_bulk_import(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        $type = sanitize_text_field($_POST['entity_type'] ?? '');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (empty($ids)) wp_send_json_error(['message' => __('Seleccióna al menos un elemento.', 'alegra-connector')]);

        $synced = 0; $errors = 0; $skipped = 0;

        foreach ($ids as $wc_id) {
            if ($type === 'product') {
                $alegra_id = (int) get_post_meta($wc_id, '_alegra_item_id', true);
                if ($alegra_id <= 0) { $skipped++; continue; }
                $sync = new Sync\Products($this->api, $this->logger);
                $r = $sync->sync_single_item_by_alegra_id($alegra_id);
            } elseif ($type === 'customer') {
                $alegra_id = (int) get_user_meta($wc_id, 'alegra_contact_id', true);
                if ($alegra_id <= 0) { $skipped++; continue; }
                $sync = new Sync\Customers($this->api, $this->logger);
                $r = $sync->sync_single_contact_by_alegra_id($alegra_id);
            } else {
                continue;
            }

            if ($r === false) $errors++;
            elseif ($r === 'skipped') $skipped++;
            else $synced++;
        }

        $msg = sprintf(__('%d actualizados, %d errores.', 'alegra-connector'), $synced, $errors);
        if ($skipped > 0) $msg .= ' ' . sprintf(__('%d omitidos (sin vincular).', 'alegra-connector'), $skipped);

        wp_send_json_success(['message' => $msg]);
    }

    /**
     * Delete users created with placeholder emails from a previous buggy version.
     * These users have @placeholder.local emails and no real Alegra contact.
     */
    public function ajax_cleanup_placeholders(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        global $wpdb;

        $user_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@placeholder.local'"
        );

        if (empty($user_ids)) {
            wp_send_json_success(['message' => __('No se encontraron usuarios con email placeholder para limpiar.', 'alegra-connector'), 'count' => 0]);
        }

        $count = 0;
        foreach ($user_ids as $uid) {
            $deleted = wp_delete_user((int) $uid);
            if ($deleted) $count++;
        }

        $this->log('info', 'Cleaned up placeholder customers', ['count' => $count]);

        wp_send_json_success([
            'message' => sprintf(__('%d usuarios placeholder eliminados.', 'alegra-connector'), $count),
            'count' => $count,
        ]);
    }

    /**
     * Batch-sync pending WC orders to Alegra invoices
     */
    /**
     * Cancel a running chunked sync by deleting the batch state transient.
     * Also restores the original sync_images setting if it was overridden.
     */
    public function ajax_cancel_sync(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        set_transient('alegra_sync_cancelled', 1, 120);
        delete_transient('alegra_batch_state');
        wp_send_json_success(['message' => 'Sync cancelled']);
    }

    public function ajax_register_webhooks(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $secret = sanitize_text_field($_POST['webhook_secret'] ?? '');
        if (empty($secret)) {
            wp_send_json_error(['message' => __('Webhook Secret es requerido.', 'alegra-connector')]);
        }

        update_option('alegra_connector_webhook_secret', $secret);

        $webhook_url = rest_url('alegra-connector/v1/webhook');
        $events = API\Client::get_webhook_events();
        $created = [];
        $errors = [];

        foreach ($events as $event) {
            $result = $this->api->create_webhook_subscription($event, $webhook_url);
            if (is_wp_error($result)) {
                $errors[] = $event . ': ' . $result->get_error_message();
            } elseif (isset($result['id'])) {
                $created[] = ['id' => $result['id'], 'event' => $event];
            }
        }

        if (!empty($created)) {
            update_option('alegra_connector_webhook_subscriptions', $created);
        }

        $message = sprintf(__('%d webhooks registrados.', 'alegra-connector'), count($created));
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('%d errores: %s', 'alegra-connector'), count($errors), implode(', ', $errors));
        }

        wp_send_json_success([
            'message' => $message,
            'created' => count($created),
            'errors' => $errors,
        ]);
    }

    public function ajax_delete_webhooks(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $subscriptions = (array) get_option('alegra_connector_webhook_subscriptions', []);
        $deleted = 0;
        $errors = [];

        foreach ($subscriptions as $sub) {
            $id = $sub['id'] ?? '';
            if (empty($id)) continue;
            $result = $this->api->delete_webhook_subscription((string) $id);
            if (is_wp_error($result)) {
                $errors[] = $sub['event'] . ': ' . $result->get_error_message();
            } else {
                $deleted++;
            }
        }

        delete_option('alegra_connector_webhook_subscriptions');

        $message = sprintf(__('%d webhooks eliminados.', 'alegra-connector'), $deleted);
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('Errores: %s', 'alegra-connector'), implode(', ', $errors));
        }

        wp_send_json_success(['message' => $message, 'deleted' => $deleted]);
    }

    public function ajax_sync_pending_orders(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        @set_time_limit(120);
        wp_raise_memory_limit();

        $orders_sync = new Sync\Orders($this->api, $this->logger);
        $result = $orders_sync->sync_recent(30);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $synced = (int) ($result['synced'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);

        wp_send_json_success([
            'message' => sprintf(__('%d facturas creadas, %d errores.', 'alegra-connector'), $synced, $errors),
            'synced' => $synced, 'errors' => $errors,
        ]);
    }

    public function ajax_sync_pending_start(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $orders = wc_get_orders([
            'limit' => 100,
            'status' => ['processing', 'completed', 'on-hold'],
            'return' => 'ids',
        ]);

        $pending = [];
        foreach ($orders as $oid) {
            if (!get_post_meta($oid, '_alegra_invoice_id', true)) {
                $pending[] = (int) $oid;
            }
        }

        $state = [
            'order_ids' => $pending,
            'total' => count($pending),
            'processed' => 0,
            'synced' => 0,
            'errors' => 0,
        ];
        set_transient('alegra_pending_invoice_batch', $state, 600);

        wp_send_json_success(['total' => count($pending)]);
    }

    public function ajax_sync_pending_page(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        @set_time_limit(60);
        wp_raise_memory_limit();

        $state = get_transient('alegra_pending_invoice_batch');
        if (!$state || empty($state['order_ids'])) {
            delete_transient('alegra_pending_invoice_batch');
            wp_send_json_error(['message' => 'No pending orders']);
        }

        $orders_sync = new Sync\Orders($this->api, $this->logger);
        $batch = array_splice($state['order_ids'], 0, 10);

        foreach ($batch as $oid) {
            $order = wc_get_order($oid);
            if (!$order) { $state['errors']++; continue; }
            $result = $orders_sync->create_invoice_with_payment($order);
            if (is_wp_error($result)) {
                $state['errors']++;
            } else {
                $state['synced']++;
            }
            $state['processed']++;
        }

        $done = empty($state['order_ids']);
        if ($done) {
            delete_transient('alegra_pending_invoice_batch');
        } else {
            set_transient('alegra_pending_invoice_batch', $state, 600);
        }

        $pct = $state['total'] > 0 ? min(100, round(($state['processed'] / $state['total']) * 100)) : 0;
        wp_send_json_success([
            'processed' => $state['processed'],
            'total' => $state['total'],
            'synced' => $state['synced'],
            'errors' => $state['errors'],
            'percent' => $pct,
            'done' => $done,
            'message' => sprintf('%d/%d facturas — %d ok, %d errores', $state['processed'], $state['total'], $state['synced'], $state['errors']),
        ]);
    }

    public function ajax_get_invoice_pdf(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die();

        $order_id = (int) ($_GET['order_id'] ?? 0);
        if ($order_id <= 0) wp_die(__('Pedido invalido.', 'alegra-connector'));

        $alegra_invoice_id = (int) get_post_meta($order_id, '_alegra_invoice_id', true);
        if ($alegra_invoice_id <= 0) wp_die(__('Este pedido no tiene factura en Alegra.', 'alegra-connector'));

        $result = $this->api->get_invoice_pdf($alegra_invoice_id);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));

        $pdf_url = $result['pdf'] ?? '';
        if (empty($pdf_url)) wp_die(__('No se pudo obtener el PDF de Alegra.', 'alegra-connector'));

        wp_redirect($pdf_url);
        exit;
    }

    public function ajax_disconnect(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        // 1. Activate kill switch IMMEDIATELY so any in-flight request stops
        \Alegra\Connector\Kill_Switch::activate('user_disconnected');

        // 2. Clean up webhook subscriptions in Alegra (best-effort, no fatal error if it fails)
        $subscriptions = (array) get_option('alegra_connector_webhook_subscriptions', []);
        foreach ($subscriptions as $sub) {
            $id = $sub['id'] ?? '';
            if (!empty($id) && $this->api) {
                try {
                    $this->api->delete_webhook_subscription((string) $id);
                } catch (\Throwable $e) {
                    $this->log('warning', 'Failed to delete webhook subscription', [
                        'subscription_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        delete_option('alegra_connector_webhook_subscriptions');

        // 3. Clear ALL alegra_* transients (1 query, efficient)
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_alegra\\_%'
             OR option_name LIKE '_transient_timeout_alegra\\_%'
             ESCAPE '\\\\'"
        );

        // 4. Clear ALL cron events with alegra prefix
        $cron_hooks = ['alegra_connector_cron_sync', 'alegra_connector_process_webhook'];
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // 5. Update visible options (PRESERVE credentials, mappings, config)
        update_option('alegra_connector_connection_tested', false);
        update_option('alegra_connector_company_name', '');
        update_option('alegra_connector_company_country', '');
        update_option('alegra_connector_company_email', '');
        update_option('alegra_connector_disconnected_at', current_time('mysql'));

        $this->log('info', 'Disconnected from Alegra (kill switch active, all resources cleaned)');

        wp_send_json_success([
            'message' => __('Desconectado de Alegra. Procesos detenidos, configuracion preservada.', 'alegra-connector'),
            'cleaned' => [
                'transients_cleared' => true,
                'cron_cleared' => count($cron_hooks),
                'webhooks_deleted' => count($subscriptions),
            ],
        ]);
    }

    public function ajax_sync_progress(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        $progress = get_transient('alegra_sync_progress');
        if ($progress === false) {
            wp_send_json_success(['done' => true, 'message' => '']);
        }
        wp_send_json_success($progress);
    }

    /**
     * AJAX: Get current monitor status (running processes, cron, history).
     */
    public function ajax_monitor_status(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $running = \Alegra\Connector\Runs::currently_running();
        $recent = \Alegra\Connector\Runs::recent(15);
        $cron_events = \Alegra\Connector\Runs::get_cron_events();
        $heartbeats = \Alegra\Connector\Heartbeat::get_batch(array_column((array) $running, 'id'));

        $running_data = [];
        foreach ($running as $run) {
            $hb = $heartbeats[(int) $run->id] ?? [];
            $running_data[] = [
                'id' => (int) $run->id,
                'type' => $run->run_type,
                'started_at' => $run->started_at,
                'started_by' => $run->started_by,
                'items_done' => (int) $run->items_done,
                'total_items' => (int) $run->total_items,
                'step' => $hb['step'] ?? '',
                'message' => $hb['message'] ?? '',
                'memory_mb' => isset($hb['memory_mb']) ? (float) $hb['memory_mb'] : null,
                'cpu_load' => isset($hb['cpu_load']) ? (float) $hb['cpu_load'] : null,
            ];
        }

        $recent_data = [];
        foreach ($recent as $run) {
            $recent_data[] = [
                'id' => (int) $run->id,
                'type' => $run->run_type,
                'status' => $run->status,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'items_done' => (int) $run->items_done,
                'total_items' => (int) $run->total_items,
                'items_failed' => (int) $run->items_failed,
                'memory_mb' => $run->memory_peak_mb ? (float) $run->memory_peak_mb : null,
                'error' => $run->error_summary,
            ];
        }

        $cron_data = [];
        foreach ($cron_events as $ev) {
            $cron_data[] = [
                'hook' => $ev['hook'],
                'next_run' => $ev['next_run'],
                'next_run_human' => $ev['next_run_human'],
                'schedule' => $ev['schedule'],
            ];
        }

        wp_send_json_success([
            'running' => $running_data,
            'cron' => $cron_data,
            'recent' => $recent_data,
            'kill_switch_active' => \Alegra\Connector\Kill_Switch::is_active(),
            'kill_switch_reason' => \Alegra\Connector\Kill_Switch::reason(),
        ]);
    }

    /**
     * AJAX: Request a running process to stop.
     */
    public function ajax_kill_run(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $run_id = (int) ($_POST['run_id'] ?? 0);
        if ($run_id <= 0) wp_send_json_error(['message' => __('ID invalido.', 'alegra-connector')]);

        \Alegra\Connector\Runs::request_stop($run_id);
        \Alegra\Connector\Heartbeat::clear($run_id);

        $this->log('info', 'Run stop requested by user', ['run_id' => $run_id]);
        wp_send_json_success(['message' => __('El proceso se detendra en su siguiente verificacion.', 'alegra-connector')]);
    }

    /**
     * AJAX: Emergency stop - activate kill switch + clear all.
     */
    public function ajax_kill_all(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        // Activate kill switch
        \Alegra\Connector\Kill_Switch::activate('emergency_stop');

        // Clear all batch transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_alegra\\_%' OR option_name LIKE '_transient_timeout_alegra\\_%' ESCAPE '\\\\'");

        // Clear cron
        $cron_hooks = ['alegra_connector_cron_sync', 'alegra_connector_process_webhook'];
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // Mark all running as killed
        $wpdb->query("UPDATE {$wpdb->prefix}alegra_runs SET status = 'killed', finished_at = NOW() WHERE status = 'running'");

        $this->log('warning', 'Emergency stop activated by user');
        wp_send_json_success(['message' => __('Todos los procesos han sido detenidos. El plugin quedara desconectado.', 'alegra-connector')]);
    }

    /**
     * AJAX: Clear the kill switch (re-enable the plugin).
     *
     * Clears the kill switch transient and resets the per-request cache so
     * the dashboard immediately reflects the active state.
     */
    public function ajax_clear_kill_switch(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        \Alegra\Connector\Kill_Switch::deactivate();
        \Alegra\Connector\Kill_Switch::reset_cache();

        $this->log('info', 'Kill switch cleared by user from dashboard');

        wp_send_json_success([
            'message' => __('Plugin reactivado. Las operaciones volveran a funcionar.', 'alegra-connector'),
        ]);
    }

    /**
     * AJAX: Approve a pending push queue item and send it to Alegra.
     */
    public function ajax_approve_push(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $queue_id = (int) ($_POST['queue_id'] ?? 0);
        if ($queue_id <= 0) wp_send_json_error(['message' => __('ID invalido.', 'alegra-connector')]);

        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}alegra_push_queue WHERE id = %d AND status = 'pending'",
            $queue_id
        ));

        if (!$item) {
            wp_send_json_error(['message' => __('Item no encontrado o ya procesado.', 'alegra-connector')]);
        }

        // Execute the push
        $result = $this->execute_queued_push($item);

        if (is_wp_error($result)) {
            \Alegra\Connector\Push_Queue::mark_applied($queue_id, $result->get_error_message());
            \Alegra\Connector\Push_Queue::log_push(
                $item->entity_type,
                (int) $item->entity_id,
                $item->action,
                json_decode($item->payload_json ?? '{}', true) ?: [],
                $result,
                null,
                get_current_user_id()
            );
            wp_send_json_error(['message' => sprintf(__('Error al enviar a Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        \Alegra\Connector\Push_Queue::mark_applied($queue_id);
        \Alegra\Connector\Push_Queue::log_push(
            $item->entity_type,
            (int) $item->entity_id,
            $item->action,
            json_decode($item->payload_json ?? '{}', true) ?: [],
            $result,
            200,
            get_current_user_id()
        );

        $this->log('info', 'Push queue item approved and sent to Alegra', [
            'queue_id' => $queue_id,
            'entity_type' => $item->entity_type,
            'entity_id' => $item->entity_id,
        ]);

        wp_send_json_success(['message' => __('Enviado a Alegra correctamente.', 'alegra-connector')]);
    }

    /**
     * AJAX: Reject a pending push queue item.
     */
    public function ajax_reject_push(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $queue_id = (int) ($_POST['queue_id'] ?? 0);
        if ($queue_id <= 0) wp_send_json_error(['message' => __('ID invalido.', 'alegra-connector')]);

        $ok = \Alegra\Connector\Push_Queue::reject($queue_id, get_current_user_id());
        if (!$ok) {
            wp_send_json_error(['message' => __('No se pudo rechazar el item.', 'alegra-connector')]);
        }

        $this->log('info', 'Push queue item rejected', ['queue_id' => $queue_id]);
        wp_send_json_success(['message' => __('Operacion rechazada. No se enviara a Alegra.', 'alegra-connector')]);
    }

    /**
     * AJAX: Get current push queue status (counts of pending items by type).
     */
    public function ajax_push_queue_status(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $pending = \Alegra\Connector\Push_Queue::get_pending(500);
        $counts = [
            'total' => count($pending),
            'product' => 0,
            'customer' => 0,
            'order' => 0,
            'payment' => 0,
        ];
        foreach ($pending as $item) {
            $key = isset($item->entity_type) ? $item->entity_type : '';
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        wp_send_json_success(['counts' => $counts]);
    }

    /**
     * AJAX: Save sync directions configuration.
     */
    /**
     * AJAX: Wizard - advance to next step.
     */
    public function ajax_wizard_advance(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $step = (int) ($_POST['step'] ?? 1);
        $step = max(1, min(5, $step));
        update_user_meta(get_current_user_id(), 'alegra_wizard_step', $step);
        if ($step >= 5) {
            update_user_meta(get_current_user_id(), 'alegra_wizard_done', true);
        }
        wp_send_json_success();
    }

    /**
     * AJAX: Wizard - skip completely.
     */
    public function ajax_wizard_skip(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();
        update_user_meta(get_current_user_id(), 'alegra_wizard_done', true);
        wp_send_json_success();
    }

    /**
     * AJAX: Run a cron hook immediately.
     *
     * Schedules a single event for the hook at the current timestamp and triggers
     * WP's cron spawn so the next request will execute it.
     */
    public function ajax_run_cron_now(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        @set_time_limit(0);
        wp_raise_memory_limit();

        $hook = sanitize_text_field($_POST['hook'] ?? '');
        if (empty($hook) || !preg_match('/^[a-zA-Z0-9_]+$/', $hook)) {
            wp_send_json_error(['message' => __('Hook invalido.', 'alegra-connector')]);
        }

        // Only allow hooks that belong to this plugin
        if (strpos($hook, 'alegra') !== 0) {
            wp_send_json_error(['message' => __('Solo se permiten hooks de Alegra.', 'alegra-connector')]);
        }

        // Schedule the hook to run on the next cron tick (immediately).
        // Remove any pending duplicate first to avoid queueing multiple executions.
        wp_clear_scheduled_hook($hook);
        wp_schedule_event(time() - 1, 'alegra_manual_run', $hook);
        spawn_cron();

        $this->log('info', 'Cron hook triggered manually', ['hook' => $hook]);

        wp_send_json_success([
            'message' => sprintf(
                __('Hook "%s" ejecutado. Revisa el Monitor en unos segundos para ver el progreso.', 'alegra-connector'),
                $hook
            ),
        ]);
    }

    /**
     * AJAX: Skip the next scheduled execution of a cron hook.
     *
     * Removes the next pending event(s) for the hook. If there are more events
     * scheduled in the future, those will remain. The plugin will reschedule
     * itself on the next run, so this effectively pushes the next run forward.
     */
    public function ajax_skip_cron_next(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $hook = sanitize_text_field($_POST['hook'] ?? '');
        if (empty($hook) || !preg_match('/^[a-zA-Z0-9_]+$/', $hook)) {
            wp_send_json_error(['message' => __('Hook invalido.', 'alegra-connector')]);
        }

        if (strpos($hook, 'alegra') !== 0) {
            wp_send_json_error(['message' => __('Solo se permiten hooks de Alegra.', 'alegra-connector')]);
        }

        $timestamp = (int) ($_POST['timestamp'] ?? 0);
        if ($timestamp <= 0) {
            wp_send_json_error(['message' => __('Timestamp invalido.', 'alegra-connector')]);
        }

        // Verify the event exists before unscheduling
        $event = wp_get_scheduled_event($hook);
        if (!$event) {
            // Try with the exact timestamp and any args
            $unscheduled = wp_unschedule_event($timestamp, $hook);
            if (!$unscheduled) {
                wp_send_json_error(['message' => __('No se encontro el evento programado.', 'alegra-connector')]);
            }
        } else {
            wp_unschedule_event($timestamp, $hook);
        }

        $this->log('info', 'Cron event skipped by user', ['hook' => $hook, 'timestamp' => $timestamp]);

        wp_send_json_success([
            'message' => sprintf(
                __('Proxima ejecucion de "%s" saltada. Se reprogramara en el siguiente ciclo.', 'alegra-connector'),
                $hook
            ),
        ]);
    }

    /**
     * AJAX: Remove ALL scheduled executions of a cron hook.
     *
     * This stops the cron entirely. Useful when the user wants to disable a
     * scheduled task permanently (the plugin will not auto-reschedule it
     * unless it re-registers the schedule elsewhere).
     */
    public function ajax_unschedule_cron(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $hook = sanitize_text_field($_POST['hook'] ?? '');
        if (empty($hook) || !preg_match('/^[a-zA-Z0-9_]+$/', $hook)) {
            wp_send_json_error(['message' => __('Hook invalido.', 'alegra-connector')]);
        }

        if (strpos($hook, 'alegra') !== 0) {
            wp_send_json_error(['message' => __('Solo se permiten hooks de Alegra.', 'alegra-connector')]);
        }

        $cleared = wp_clear_scheduled_hook($hook);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, [], 'alegra');
        }

        $this->log('info', 'Cron hook unscheduled by user', ['hook' => $hook, 'cleared' => $cleared]);

        wp_send_json_success([
            'message' => sprintf(
                __('Todas las programaciones de "%s" eliminadas.', 'alegra-connector'),
                $hook
            ),
        ]);
    }

    /**
     * AJAX: Change cron frequency (re-schedules with new interval).
     */
    public function ajax_change_cron_frequency(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $hook = sanitize_text_field($_POST['hook'] ?? '');
        $frequency = (int) ($_POST['frequency'] ?? 0);
        $allowed = [5, 15, 30, 60];

        if (empty($hook) || !preg_match('/^[a-zA-Z0-9_]+$/', $hook)) {
            wp_send_json_error(['message' => __('Hook invalido.', 'alegra-connector')]);
        }

        if (strpos($hook, 'alegra') !== 0) {
            wp_send_json_error(['message' => __('Solo se permiten hooks de Alegra.', 'alegra-connector')]);
        }

        if (!in_array($frequency, $allowed, true)) {
            wp_send_json_error(['message' => __('Frecuencia invalida. Use 5, 15, 30 o 60.', 'alegra-connector')]);
        }

        // Clear all existing schedules for this hook
        wp_clear_scheduled_hook($hook);

        // Schedule with new frequency
        $schedule_name = 'alegra_connector_' . $frequency . 'min';
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + 60, $schedule_name, $hook);
        }

        // Update the option that drives schedule_cron()
        update_option('alegra_connector_sync_frequency', $frequency);

        // Trigger a re-schedule via the plugin's own mechanism
        do_action('update_option_alegra_connector_sync_frequency', $frequency);

        $this->log('info', 'Cron frequency changed', ['hook' => $hook, 'frequency' => $frequency]);

        wp_send_json_success([
            'message' => sprintf(
                __('Frecuencia de "%s" cambiada a %d minutos.', 'alegra-connector'),
                $hook,
                $frequency
            ),
        ]);
    }

    public function ajax_save_sync_directions(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $allowed = ['disabled', 'manual', 'auto'];
        $types = ['product', 'customer', 'order', 'payment'];

        foreach ($types as $type) {
            $val = sanitize_text_field($_POST['direction_' . $type] ?? 'disabled');
            if (!in_array($val, $allowed, true)) $val = 'disabled';
            update_option('alegra_connector_push_direction_' . $type, $val);
        }

        wp_send_json_success(['message' => __('Configuración de direcciones guardada.', 'alegra-connector')]);
    }

    /**
     * Execute a queued push: performs the actual API call to Alegra.
     *
     * @param object $item Queue row.
     * @return array|\WP_Error
     */
    private function execute_queued_push(object $item)
    {
        $payload = json_decode($item->payload_json ?? '{}', true) ?: [];
        $entity_type = $item->entity_type;
        $entity_id = (int) $item->entity_id;
        $action = $item->action;

        switch ($entity_type) {
            case 'order':
                $order = wc_get_order($entity_id);
                if (!$order) return new \WP_Error('not_found', 'Pedido no encontrado');
                $sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
                if ($action === 'complete') {
                    return $sync->create_invoice_with_payment($order);
                } elseif ($action === 'create') {
                    return $sync->create_invoice($order);
                } elseif ($action === 'cancel') {
                    return $sync->void_invoice($order);
                }
                return new \WP_Error('unknown_action', 'Acción desconocida: ' . $action);

            case 'customer':
                $user = get_userdata($entity_id);
                if (!$user) return new \WP_Error('not_found', 'Cliente no encontrado');
                $sync = new \Alegra\Connector\Sync\Customers($this->api, $this->logger);
                return $sync->sync_to_alegra($user);

            case 'product':
                $product = wc_get_product($entity_id);
                if (!$product) return new \WP_Error('not_found', 'Producto no encontrado');
                $sync = new \Alegra\Connector\Sync\Products($this->api, $this->logger);
                return $sync->sync_to_alegra($product);

            case 'payment':
                $order = wc_get_order($entity_id);
                if (!$order) return new \WP_Error('not_found', 'Pedido no encontrado');
                $sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
                return $sync->create_payment_for_order($order);

            default:
                return new \WP_Error('unknown_type', 'Tipo desconocido: ' . $entity_type);
        }
    }

    public function do_async_import(string $type): void
    {
        switch ($type) {
            case 'products':
                $sync = new Sync\Products($this->api, $this->logger);
                if ($this->api) $sync->import_from_alegra();
                $sync->sync_all();
                break;
            case 'customers':
                $sync = new Sync\Customers($this->api, $this->logger);
                if ($this->api) $sync->import_from_alegra();
                $sync->sync_all();
                break;
            case 'categories':
                $sync = new Sync\Categories($this->api, $this->logger);
                if ($this->api) $sync->import_from_alegra();
                $sync->sync_all();
                break;
        }
    }

    public function do_async_sync_all(): void
    {
        $sc = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);
        $sc->run_cron_sync();
        set_transient('alegra_sync_progress', ['done' => true, 'message' => __('Sincronización completa.', 'alegra-connector')], 60);
    }

    public function ajax_check_endpoints(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        // Force reload credentials from DB
        $this->api->reload_credentials();

        $endpoints = [];

        $items = $this->api->get_items(['limit' => 5]);
        $endpoints['items'] = is_wp_error($items) ? $items->get_error_message() : (is_array($items) && count($items) > 0 ? 'OK: ' . count($items) . ' items' : 'vacio');

        $contacts = $this->api->get_contacts(['limit' => 5]);
        $endpoints['contacts'] = is_wp_error($contacts) ? $contacts->get_error_message() : (is_array($contacts) && count($contacts) > 0 ? 'OK: ' . count($contacts) . ' contacts' : 'vacio');

        $invoices = $this->api->get_invoices(['limit' => 5]);
        $endpoints['invoices'] = is_wp_error($invoices) ? $invoices->get_error_message() : (is_array($invoices) && count($invoices) > 0 ? 'OK: ' . count($invoices) . ' invoices' : 'vacio');

        $taxes = $this->api->get_taxes();
        $endpoints['taxes'] = is_wp_error($taxes) ? $taxes->get_error_message() : (is_array($taxes) && count($taxes) > 0 ? 'OK: ' . count($taxes) . ' taxes' : 'vacio');

        $this->log('info', 'Endpoint check completed', $endpoints);

        wp_send_json_success(['endpoints' => $endpoints]);
    }

    public function ajax_import_from_api(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        @set_time_limit(300);
        wp_raise_memory_limit();

        if (!get_option('alegra_connector_connection_tested')) {
            wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
        }

        $import_type = sanitize_text_field($_POST['import_type'] ?? '');
        if (!in_array($import_type, ['products', 'customers', 'categories'])) {
            wp_send_json_error(['message' => __('Tipo de importacion invalido.', 'alegra-connector')]);
        }

        // Lock the per-type sync so cron + this AJAX import can't both pull.
        // Customers::import_from_alegra has its own internal lock too; this
        // outer lock protects the Products and Categories code paths and
        // gives consistent UX (single error message) across all types.
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($import_type);
        if ($lock === false) {
            wp_send_json_error(['message' => __('Another sync is in progress. Please wait.', 'alegra-connector')]);
        }
        try {
            $sync_controller = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);
            $result = $sync_controller->import_from_alegra($import_type);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
            }

            $imported = (int) ($result['imported'] ?? 0);
            $updated = (int) ($result['updated'] ?? 0);

            wp_send_json_success([
                'message' => sprintf(__('Importación completada: %d nuevos, %d actualizados.', 'alegra-connector'), $imported, $updated),
                'data' => $result,
            ]);
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
        }
    }

    private function get_sync_stats(): array
    {
        global $wpdb;

        $product_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
        );

        $orders_table = HPOS::get_orders_table();
        if (HPOS::is_enabled()) {
            $order_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$orders_table} WHERE type='shop_order' AND status IN ('wc-processing','wc-completed','wc-pending','wc-on-hold')"
            );
        } else {
            $order_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-processing','wc-completed','wc-pending','wc-on-hold')"
            );
        }

        $user_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID=um.user_id AND um.meta_key='{$wpdb->prefix}capabilities' WHERE um.meta_value LIKE '%customer%'"
        );

        $cat_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'"
        );

        return [
            'products' => $product_count,
            'customers' => $user_count,
            'orders' => $order_count,
            'categories' => $cat_count,
        ];
    }

    private function process_csv_import(string $file_path, string $type): array|\WP_Error
    {
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', __('Archivo CSV no encontrado.', 'alegra-connector'));
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new \WP_Error('cannot_open', __('No se pudo abrir el archivo.', 'alegra-connector'));
        }

        $count = 0;
        $errors = [];
        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);
            return new \WP_Error('invalid_csv', __('Archivo CSV inv lido.', 'alegra-connector'));
        }

        $headers = array_map('trim', $headers);
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count($row) !== count($headers)) {
                $errors[] = sprintf(
                    /* translators: %d: line number */
                    __('L nea %d: n mero de columnas no coincide con el encabezado.', 'alegra-connector'),
                    $line
                );
                continue;
            }

            $data = array_combine($headers, $row);
            if ($data === false) {
                $errors[] = sprintf(
                    /* translators: %d: line number */
                    __('L nea %d: error al procesar la fila.', 'alegra-connector'),
                    $line
                );
                continue;
            }

            if ($type === 'products') {
                $imported = $this->import_product_from_csv($data);
                if ($imported) {
                    $count++;
                } else {
                    $errors[] = sprintf(
                        /* translators: %d: line number */
                        __('L nea %d: no se pudo importar el producto.', 'alegra-connector'),
                        $line
                    );
                }
            }
        }

        fclose($handle);

        $this->logger->info('CSV import completed', [
            'type' => $type,
            'count' => $count,
            'errors' => count($errors),
        ]);

        return ['count' => $count, 'errors' => $errors];
    }

    private function import_product_from_csv(array $data): bool
    {
        $name = sanitize_text_field($data['name'] ?? '');
        if (empty($name)) {
            return false;
        }

        $product_id = wp_insert_post([
            'post_title' => $name,
            'post_content' => sanitize_text_field($data['description'] ?? ''),
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($product_id) || !$product_id) {
            return false;
        }

        $sku = sanitize_text_field($data['reference'] ?? '');
        $price = sanitize_text_field($data['price'] ?? '');

        if (!empty($sku)) {
            update_post_meta($product_id, '_sku', $sku);
        }
        if (!empty($price)) {
            update_post_meta($product_id, '_regular_price', $price);
            update_post_meta($product_id, '_price', $price);
        }

        return true;
    }

    /**
     * Clean up duplicate product images that were created before
     * the URL normalization fix was applied.
     */
    public function ajax_cleanup_duplicate_images(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        @set_time_limit(120);
        wp_raise_memory_limit();

        global $wpdb;

        // Find all attachments with _alegra_image_url meta
        $attachments = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_parent
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = '_alegra_image_url'"
        );

        if (empty($attachments)) {
            wp_send_json_success([
                'message' => __('No hay imagenes de Alegra para limpiar.', 'alegra-connector'),
                'removed' => 0,
            ]);
        }

        // Group by normalized URL + parent to find duplicates
        $by_normalized = [];
        foreach ($attachments as $att) {
            $parsed = wp_parse_url($att->meta_value);
            if ($parsed === false || empty($parsed['host'])) {
                continue;
            }
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
            $host = $parsed['host'];
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $path = isset($parsed['path']) ? $parsed['path'] : '';
            $normalized = $scheme . '://' . $host . $port . $path;

            $key = $att->post_parent . '|' . $normalized;
            if (!isset($by_normalized[$key])) {
                $by_normalized[$key] = [];
            }
            $by_normalized[$key][] = $att->post_id;
        }

        $removed = 0;
        $updated_galleries = 0;
        foreach ($by_normalized as $key => $ids) {
            if (count($ids) <= 1) {
                continue;
            }
            // Keep the first ID, delete the rest
            $keep_id = (int) $ids[0];
            $remove_ids = array_map('intval', array_slice($ids, 1));
            $remove_ids = array_filter($remove_ids, function($id) use ($keep_id) {
                return $id !== $keep_id;
            });

            // Verify the kept attachment is actually attached to the product
            $parent_id = (int) explode('|', $key)[0];
            $keep_parent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
                $keep_id
            ));
            if ($keep_parent !== $parent_id) {
                wp_update_post(['ID' => $keep_id, 'post_parent' => $parent_id]);
            }

            // Update gallery meta to remove duplicates
            $gallery = get_post_meta($parent_id, '_product_image_gallery', true);
            if (!empty($gallery)) {
                $gallery_ids = array_filter(
                    explode(',', $gallery),
                    function($id) use ($remove_ids) {
                        return !in_array((int) $id, $remove_ids, true);
                    }
                );
                update_post_meta($parent_id, '_product_image_gallery', implode(',', $gallery_ids));
                $updated_galleries++;
            }

            // Update featured image if it points to a duplicate
            $featured = (int) get_post_meta($parent_id, '_thumbnail_id', true);
            if (in_array($featured, $remove_ids, true)) {
                update_post_meta($parent_id, '_thumbnail_id', $keep_id);
            }

            // Delete the duplicates
            foreach ($remove_ids as $remove_id) {
                wp_delete_attachment($remove_id, true);
                $removed++;
            }
        }

        $this->log('info', 'Duplicate images cleaned up', [
            'removed' => $removed,
            'galleries_updated' => $updated_galleries,
        ]);

        wp_send_json_success([
            'message' => sprintf(
                _n(
                    'Se elimino %d imagen duplicada.',
                    'Se eliminaron %d imagenes duplicadas.',
                    $removed,
                    'alegra-connector'
                ),
                $removed
            ),
            'removed' => $removed,
            'galleries_updated' => $updated_galleries,
        ]);
    }

    /**
     * AJAX: Rebuild image dedup index for existing attachments.
     * Backfills _alegra_image_url_hash for attachments that have _alegra_image_url.
     */
    public function ajax_rebuild_image_index(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        @set_time_limit(120);
        wp_raise_memory_limit();

        global $wpdb;

        // Find attachments with _alegra_image_url but missing _alegra_image_url_hash
        $results = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = '_alegra_image_url'
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta} pm2
                 WHERE pm2.post_id = pm.post_id AND pm2.meta_key = '_alegra_image_url_hash'
             )"
        );

        $backfilled = 0;
        foreach ($results as $row) {
            $url = $row->meta_value;
            // Normalize URL before hashing (strip query params)
            $parts = wp_parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                continue;
            }
            $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = isset($parts['path']) ? $parts['path'] : '';
            $normalized = $scheme . '://' . $host . $port . $path;
            $hash = md5($normalized);

            update_post_meta((int) $row->post_id, '_alegra_image_url_hash', $hash);
            // Also update the URL meta to the normalized version if needed
            if ($normalized !== $url) {
                update_post_meta((int) $row->post_id, '_alegra_image_url', $normalized);
            }
            $backfilled++;
        }

        $this->log('info', 'Image index rebuilt', ['backfilled' => $backfilled]);

        wp_send_json_success([
            'message' => sprintf(
                _n(
                    'Se reindexo %d imagen. Las imagenes duplicadas seran detectadas automaticamente.',
                    'Se reindexaron %d imagenes. Las imagenes duplicadas seran detectadas automaticamente.',
                    $backfilled,
                    'alegra-connector'
                ),
                $backfilled
            ),
            'backfilled' => $backfilled,
        ]);
    }

    /**
     * AJAX: Get image index statistics (for the dashboard card).
     */
    public function ajax_image_index_stats(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        global $wpdb;
        $total_attachments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $indexed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_image_url_hash'"
        );
        $without_hash = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_image_url'"
        ) - $indexed;

        wp_send_json_success([
            'total_attachments' => $total_attachments,
            'indexed_with_hash' => max(0, $indexed),
            'missing_hash' => max(0, $without_hash),
            'coverage_pct' => $total_attachments > 0 ? round(($indexed / $total_attachments) * 100, 1) : 100,
        ]);
    }
}
