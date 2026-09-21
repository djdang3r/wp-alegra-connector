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
        add_action('admin_init', [$this, 'maybe_redirect_wizard']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_alegra_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_alegra_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_alegra_import_csv', [$this, 'ajax_import_csv']);
        add_action('wp_ajax_alegra_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_alegra_download_logs', [$this, 'ajax_download_logs']);
        add_action('wp_ajax_alegra_save_mapping', [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_alegra_sync_single', [$this, 'ajax_sync_single']);
        add_action('wp_ajax_alegra_record_payment', [$this, 'ajax_record_payment']);
        add_action('wp_ajax_alegra_open_invoice', [$this, 'ajax_open_invoice']);
        add_action('wp_ajax_alegra_import_from_api', [$this, 'ajax_import_from_api']);
        add_action('wp_ajax_alegra_sync_inventory', [$this, 'ajax_sync_inventory']);
        add_action('wp_ajax_alegra_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_alegra_bulk_sync', [$this, 'ajax_bulk_sync']);
        add_action('wp_ajax_alegra_get_invoice_pdf', [$this, 'ajax_get_invoice_pdf']);
        add_action('wp_ajax_alegra_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_alegra_check_endpoints', [$this, 'ajax_check_endpoints']);
        add_action('wp_ajax_alegra_sync_progress', [$this, 'ajax_sync_progress']);
        add_action('wp_ajax_alegra_sync_start', [$this, 'ajax_sync_start']);
        add_action('wp_ajax_alegra_sync_page', [$this, 'ajax_sync_page']);
        add_action('wp_ajax_alegra_get_item_categories', [$this, 'ajax_get_item_categories']);
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
        add_action('wp_ajax_alegra_rebuild_image_index', [$this, 'ajax_rebuild_image_index']);
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

    private function get_product_by_alegra_id(string $alegra_id): ?int
    {
        global $wpdb;
        // Alegra ids are UUID strings (VARCHAR(36)); %d truncated them to 0.
        $pid = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id' AND meta_value=%s LIMIT 1", $alegra_id));
        return $pid ? (int)$pid : null;
    }

    /**
     * Download and attach image from Alegra to WooCommerce product
     */
    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        // SSRF guard (AC-68): only fetch https URLs from Alegra's CDN.
        if (!Sync\Products::is_allowed_image_url($image_url)) {
            $this->log('warning', 'Blocked image download from a non-allowlisted host', [
                'product_id' => $product_id,
                'host' => (string) (wp_parse_url($image_url)['host'] ?? ''),
            ]);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 15);
        if (is_wp_error($tmp)) {
            $this->log('warning', 'Image download failed for product ' . $product_id, ['error' => $tmp->get_error_message()]);
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
            $this->log('warning', 'Image sideload failed for product ' . $product_id, ['error' => $attachment_id->get_error_message()]);
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
            __('Estadísticas', 'alegra-connector'),
            __('Estadísticas', 'alegra-connector'),
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
            __('Asistente', 'alegra-connector'),
            __('Asistente', 'alegra-connector'),
            'manage_woocommerce',
            'alegra-connector-wizard',
            [$this, 'render_wizard_page']
        );
    }

    /**
     * Sanitize a masked secret field. The value is never echoed back into the
     * settings form, so an empty submission means "keep the stored value"
     * (not "clear it"). A non-empty submission replaces it.
     */
    public static function sanitize_masked_secret(string $value, string $option): string
    {
        $value = sanitize_text_field($value);
        return $value === '' ? (string) get_option($option, '') : $value;
    }

    /**
     * Neutralise CSV formula injection (AC-67). Excel/Sheets treat a cell that
     * starts with =, +, -, @, TAB or CR as a formula; prefixing a single quote
     * forces it to be read as text.
     */
    public static function csv_safe_cell(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return $value;
        }
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    public function register_settings(): void
    {
        // Alegra migrated all IDs to UUID (VARCHAR(36)) on 2025-01-06.
        // intval() truncates a UUID to 0 on save, silently breaking config.
        // This sanitizer accepts a UUID or a legacy numeric id and preserves the
        // previous value when the input is neither (rejecting garbage).
        $alegra_id_sanitizer = function ($value, $option_name) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            $is_uuid = (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
            if ($is_uuid || ctype_digit($value)) {
                return $value;
            }
            return (string) get_option($option_name, '');
        };

        register_setting('alegra_connector_settings', 'alegra_connector_email', ['sanitize_callback' => 'sanitize_email']);
        // The token is masked in the UI (never echoed). An empty submission means
        // "keep the stored token", not "clear it" — otherwise re-saving any other
        // setting would wipe the credential.
        register_setting('alegra_connector_settings', 'alegra_connector_token', [
            'sanitize_callback' => fn($value) => self::sanitize_masked_secret((string) $value, 'alegra_connector_token'),
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_api_url', [
            'sanitize_callback' => function ($value) {
                $url = sanitize_url($value);
                return $url ?: 'https://api.alegra.com/api/v1';
            },
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_frequency', ['sanitize_callback' => 'intval']);
        // `sync_method` selects the INBOUND (Alegra → WooCommerce) method only.
        // Allowlist it so garbage cannot silently disable the pull, and default
        // to the UI default ('cron', periodic) for installs with no stored value.
        register_setting('alegra_connector_settings', 'alegra_connector_sync_method', [
            'sanitize_callback' => function ($value) {
                $allowed = ['cron', 'both', 'real-time', 'disabled'];
                return in_array($value, $allowed, true) ? $value : 'cron';
            },
            'default' => 'cron',
        ]);
        // Outbound order uploads. Manual (false) is the default; only an explicit
        // opt-in enables automatic invoicing.
        register_setting('alegra_connector_settings', 'alegra_connector_push_orders_enabled', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_push_products_enabled', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_currency', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_log_retention_days', ['sanitize_callback' => 'intval']);
        register_setting('alegra_connector_settings', 'alegra_connector_conflict_resolution', ['sanitize_callback' => 'sanitize_text_field']);
        // Colombia contact fiscal fields. Allowlisted so a bad value can never
        // be POSTed to Alegra (which would 400 the contact create).
        register_setting('alegra_connector_settings', \Alegra\Connector\Billing_Fields::OPTION_KIND_OF_PERSON, [
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_text_field((string) $value));
                return in_array($value, \Alegra\Connector\Billing_Fields::KIND_OF_PERSONS, true) ? $value : '';
            },
        ]);
        register_setting('alegra_connector_settings', \Alegra\Connector\Billing_Fields::OPTION_REGIME, [
            'sanitize_callback' => function ($value) {
                $value = strtoupper(sanitize_text_field((string) $value));
                return in_array($value, \Alegra\Connector\Billing_Fields::REGIMES, true) ? $value : 'SIMPLIFIED_REGIME';
            },
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_products', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_customers', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_orders', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_categories', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_inventory_source', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('alegra_connector_settings', 'alegra_connector_warehouse_id', [
            'sanitize_callback' => fn($v) => $alegra_id_sanitizer($v, 'alegra_connector_warehouse_id'),
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_warehouse_enabled', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_payment_account_id', [
            'sanitize_callback' => fn($v) => $alegra_id_sanitizer($v, 'alegra_connector_payment_account_id'),
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_payment_term_id', [
            'sanitize_callback' => fn($v) => $alegra_id_sanitizer($v, 'alegra_connector_payment_term_id'),
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_auto_complete_order', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_images', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_inactive_products', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('alegra_connector_settings', 'alegra_connector_sync_images_mode', ['sanitize_callback' => 'sanitize_text_field']);
        // Same masking rule as the API token: empty submission keeps the stored secret.
        register_setting('alegra_connector_settings', 'alegra_connector_webhook_secret', [
            'sanitize_callback' => fn($value) => self::sanitize_masked_secret((string) $value, 'alegra_connector_webhook_secret'),
        ]);
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
        register_setting('alegra_connector_settings', 'alegra_connector_customer_resolution_mode', [
            'sanitize_callback' => function ($value) {
                $allowed = ['auto', 'always_generic', 'require_data'];
                return in_array($value, $allowed, true) ? $value : 'auto';
            },
            'default' => 'auto',
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_invoice_status', [
            'sanitize_callback' => function ($value) {
                $allowed = ['draft', 'open'];
                return in_array($value, $allowed, true) ? $value : 'draft';
            },
            'default' => 'draft',
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_dry_run', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        // Product push category strategy (read by Products::resolve_alegra_category_id()).
        register_setting('alegra_connector_settings', 'alegra_connector_push_category_strategy', [
            'sanitize_callback' => function ($value) {
                $allowed = ['first', 'deepest', 'specific'];
                return in_array($value, $allowed, true) ? $value : 'deepest';
            },
            'default' => 'deepest',
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_push_category_id', ['sanitize_callback' => 'intval']);
        // Parent term for categories imported from Alegra (read by Categories/Products).
        register_setting('alegra_connector_settings', 'alegra_connector_import_category_parent', ['sanitize_callback' => 'intval']);
        // WooCommerce fields to preserve when updating existing products from
        // Alegra. Read by Products::resolve_preserve_fields(); the Products
        // modal can override it per run.
        register_setting('alegra_connector_settings', 'alegra_connector_import_preserve_fields', [
            'sanitize_callback' => [self::class, 'sanitize_preserve_fields'],
            'default' => [],
        ]);
        // AC-19/AC-18 performance knobs. These were read with hardcoded defaults
        // but never written (dead config); register them so the Avanzado tab can
        // actually set them. Clamped so a bad value cannot break an import.
        register_setting('alegra_connector_settings', 'alegra_connector_import_time_budget', [
            'sanitize_callback' => fn($v) => max(30, min(600, (int) $v)),
            'default' => 240,
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_import_max_pages', [
            'sanitize_callback' => fn($v) => max(0, (int) $v),
            'default' => 0,
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_orders_poll_batch', [
            'sanitize_callback' => fn($v) => max(1, min(100, (int) $v)),
            'default' => 20,
        ]);
        // Manual Consumidor Final override (read by Consumidor_Final::get_id()).
        register_setting('alegra_connector_settings', 'alegra_connector_consumidor_final_manual_override', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_consumidor_final_manual_id', [
            'sanitize_callback' => fn($v) => $alegra_id_sanitizer($v, 'alegra_connector_consumidor_final_manual_id'),
        ]);

        // Per-field billing toggles. Group A (identification) is force-enabled
        // here so the identification is always collected.
        register_setting('alegra_connector_settings', \Alegra\Connector\Billing_Fields::OPTION_ENABLED, [
            'sanitize_callback' => function ($value) {
                $enabled = [];
                if (is_array($value)) {
                    foreach ($value as $key => $on) {
                        if ($on && isset(\Alegra\Connector\Billing_Fields::CATALOG[$key])) {
                            $enabled[$key] = 1;
                        }
                    }
                }
                foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
                    if (($field['group'] ?? '') === 'A') {
                        $enabled[$key] = 1;
                    }
                }
                return $enabled;
            },
            'default' => [],
        ]);

        // Seed the required (Group A) fields whenever the catalog is empty so
        // the identification is collected out of the box instead of silently
        // rendering no fields at checkout. Once saved, the sanitizer always
        // keeps Group A, so this only ever writes on an unconfigured install.
        $current_billing_fields = get_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, []);
        if (!is_array($current_billing_fields) || empty($current_billing_fields)) {
            $seed = [];
            foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
                if (($field['group'] ?? '') === 'A') {
                    $seed[$key] = 1;
                }
            }
            update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, $seed);
        }

        // Mapping settings group
        register_setting('alegra_connector_mapping', 'alegra_connector_field_mapping');
        register_setting('alegra_connector_mapping', 'alegra_connector_tax_mapping');

        add_settings_section('alegra_connector_connection', __('Conexión con Alegra', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_sync_settings', __('Sincronización', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_currency_section', __('Moneda', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_warehouse_section', __('Bodegas', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_billing_section', __('Datos de facturación', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
        add_settings_section('alegra_connector_advanced', __('Avanzado', 'alegra-connector'), fn() => null, 'alegra_connector_settings');
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'alegra-connector') === false) {
            return;
        }

        wp_enqueue_style('alegra-connector-admin', ALEGRA_CONNECTOR_URL . 'admin/assets/css/admin.css', [], ALEGRA_CONNECTOR_VERSION);
        wp_enqueue_script('alegra-connector-admin', ALEGRA_CONNECTOR_URL . 'admin/assets/js/admin.js', ['jquery'], ALEGRA_CONNECTOR_VERSION, true);

        // AC-55: Chart.js is vendored locally and enqueued ONLY on the
        // statistics page. It used to be a raw <script src> to cdn.jsdelivr.net
        // (third-party request from the admin, no SRI, no local fallback).
        if (strpos($hook, 'alegra-connector-stats') !== false) {
            wp_enqueue_script(
                'alegra-connector-chartjs',
                ALEGRA_CONNECTOR_URL . 'admin/assets/js/vendor/chart.min.js',
                [],
                ALEGRA_CONNECTOR_VERSION,
                true
            );
        }

        wp_localize_script('alegra-connector-admin', 'alegraConnector', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alegra_connector_nonce'),
            'strings' => self::get_script_strings(),
        ]);

        // AC-35d: register the script translations so any .json catalogs
        // generated from the .pot are actually loaded for admin.js.
        wp_set_script_translations('alegra-connector-admin', 'alegra-connector', ALEGRA_CONNECTOR_PATH . 'languages');
    }

    /**
     * AC-35d: every user-facing JS string, localizable from one place.
     *
     * Strings that need interpolation use printf-style placeholders and are
     * rendered by the small format() helper in admin.js.
     *
     * @return array<string, string>
     */
    private static function get_script_strings(): array
    {
        return [
            // Generic
            'unknownError'          => __('Error desconocido', 'alegra-connector'),
            'error'                 => __('Error', 'alegra-connector'),
            'ok'                    => __('OK', 'alegra-connector'),
            'testing'               => __('Probando conexión...', 'alegra-connector'),
            'success'               => __('Conexión exitosa', 'alegra-connector'),
            'connectionError'       => __('Error de conexión', 'alegra-connector'),
            'syncing'               => __('Sincronizando...', 'alegra-connector'),
            'importing'             => __('Importando...', 'alegra-connector'),
            'networkErrorLabel'     => __('Error de red', 'alegra-connector'),

            // Connection
            'emailTokenRequired'    => __('Email y token son requeridos', 'alegra-connector'),
            'testConnection'        => __('Probar Conexión', 'alegra-connector'),
            'connectedTo'           => __('Conectado a %s', 'alegra-connector'),
            'connected'             => __('Conectado', 'alegra-connector'),
            'company'               => __('Empresa:', 'alegra-connector'),
            'country'               => __('País:', 'alegra-connector'),
            'endpoints'             => __('Endpoints:', 'alegra-connector'),
            'timeout'               => __('Timeout: el servidor de Alegra no responde', 'alegra-connector'),
            'networkError'          => __('Error de red (%s)', 'alegra-connector'),
            'checkUrl'              => __('Verifica la URL base y tu conexión.', 'alegra-connector'),

            // Sync
            'fetchingData'          => __('Trayendo datos...', 'alegra-connector'),
            'gettingCount'          => __('Obteniendo conteo de Alegra...', 'alegra-connector'),
            'elapsed'               => __('Tiempo: %1$dm %2$ds', 'alegra-connector'),
            'syncAll'               => __('Sincronizar Todo', 'alegra-connector'),
            'syncInventory'         => __('Sincronizar inventario', 'alegra-connector'),
            'syncCancelled'         => __('Sincronización cancelada', 'alegra-connector'),
            'completed'             => __('Completado', 'alegra-connector'),
            'retry'                 => __('Reintentar', 'alegra-connector'),
            'startError'            => __('Error al iniciar', 'alegra-connector'),
            'phase1Total'           => __('[Fase 1] Total: %1$s items en %2$s páginas — Página 1', 'alegra-connector'),
            'processing'            => __('Procesando...', 'alegra-connector'),
            'phase1'                => __('[Fase 1] %s', 'alegra-connector'),
            'importedLabel'         => __('importados', 'alegra-connector'),
            'updatedLabel'          => __('actualizados', 'alegra-connector'),
            'skippedLabel'          => __('omitidos', 'alegra-connector'),
            'errorsLabel'           => __('errores', 'alegra-connector'),
            'phase2CompletedErrors' => __('[Fase 2] Completado con errores', 'alegra-connector'),
            'phase2Completed'       => __('[Fase 2] Completado', 'alegra-connector'),
            'syncSummary'           => __('Total: %1$s items | %2$s nuevos, %3$s actualizados, %4$s omitidos', 'alegra-connector'),
            'syncSummaryErrors'     => __(', %s errores', 'alegra-connector'),
            'syncDoneErrors'        => __('Importación completada con errores: %1$s items procesados. %2$s importados, %3$s actualizados, %4$s errores. Revisa el log para más detalle.', 'alegra-connector'),
            'syncDone'              => __('%1$s items procesados. %2$s importados, %3$s actualizados.', 'alegra-connector'),
            'retrying'              => __('Reintentando (%1$s/%2$s)...', 'alegra-connector'),
            'selectOneType'         => __('Selecciona al menos un tipo', 'alegra-connector'),

            // Logs / token / connection management
            'confirmClearLogs'      => __('¿Eliminar logs antiguos?', 'alegra-connector'),
            'logsDeleted'           => __('Logs eliminados', 'alegra-connector'),
            'hideToken'             => __('Ocultar token', 'alegra-connector'),
            'showToken'             => __('Mostrar token', 'alegra-connector'),
            'hide'                  => __('Ocultar', 'alegra-connector'),
            'show'                  => __('Mostrar', 'alegra-connector'),
            'confirmDisconnect'     => __('¿Desconectar de Alegra? Deberás volver a probar la conexión.', 'alegra-connector'),
            'disconnecting'         => __('Desconectando...', 'alegra-connector'),
            'disconnect'            => __('Desconectar', 'alegra-connector'),
            'verifying'             => __('Verificando...', 'alegra-connector'),
            'verifyEndpoints'       => __('Verificar Endpoints', 'alegra-connector'),

            // Webhooks
            'registering'           => __('Registrando...', 'alegra-connector'),
            'webhookSecretRequired' => __('Debes configurar un Webhook Secret primero', 'alegra-connector'),
            'registerWebhooks'      => __('Registrar webhooks en Alegra', 'alegra-connector'),
            'webhooksRegistered'    => __('Webhooks registrados', 'alegra-connector'),
            'confirmDeleteWebhooks' => __('¿Eliminar todas las suscripciones de webhooks en Alegra?', 'alegra-connector'),
            'deleting'              => __('Eliminando...', 'alegra-connector'),
            'deleteWebhooks'        => __('Eliminar webhooks en Alegra', 'alegra-connector'),
            'webhooksDeleted'       => __('Webhooks eliminados', 'alegra-connector'),

            // Images / bulk / single sync
            'confirmCleanupImages'  => __('Esto eliminará todos los attachments de imagen duplicados en productos, basándose en la URL normalizada. Las imágenes que quedaron únicas se conservarán. ¿Continuar?', 'alegra-connector'),
            'cleaning'              => __('Limpiando...', 'alegra-connector'),
            'cleanupImages'         => __('Limpiar imágenes duplicadas', 'alegra-connector'),
            'imagesDeleted'         => __('Imágenes duplicadas eliminadas', 'alegra-connector'),
            'confirmRecordPayment'  => __('¿Registrar pago en Alegra?', 'alegra-connector'),
            'confirmOpenInvoice'    => __('¿Abrir esta factura en Alegra? Dejará de estar en borrador y quedará contabilizada.', 'alegra-connector'),
            'invoiceOpened'         => __('Factura abierta en Alegra', 'alegra-connector'),
            'selectOneItem'         => __('Selecciona al menos un elemento', 'alegra-connector'),
            'sending'               => __('Enviando...', 'alegra-connector'),
            'fetching'              => __('Trayendo...', 'alegra-connector'),
            'updatedSingle'         => __('Actualizado', 'alegra-connector'),
            'confirmImportFromApi'  => __('¿Traer %s desde Alegra? Esto puede crear o actualizar registros en WooCommerce.', 'alegra-connector'),
            'downloading'           => __('Descargando...', 'alegra-connector'),
            'importedSingle'        => __('Importado', 'alegra-connector'),
            'selectedOne'           => __('seleccionado', 'alegra-connector'),
            'selectedMany'          => __('seleccionados', 'alegra-connector'),

            // Pending invoices batch
            'countingPending'       => __('Contando pedidos pendientes...', 'alegra-connector'),
            'invoicePending'        => __('Facturar pendientes', 'alegra-connector'),
            'cancelled'             => __('Cancelado', 'alegra-connector'),
            'noPendingOrders'       => __('No hay pedidos pendientes por facturar', 'alegra-connector'),
            'invoicingPending'      => __('Facturando %s pedidos pendientes...', 'alegra-connector'),
            'invoicedLabel'         => __('facturados', 'alegra-connector'),
            'invoicesCreated'       => __('%1$s facturas creadas. %2$s errores.', 'alegra-connector'),

            // CSV import page
            'uploadImport'          => __('Subir e Importar', 'alegra-connector'),
            'selectCsv'             => __('Selecciona un archivo CSV', 'alegra-connector'),
            'importError'           => __('Error en la importación', 'alegra-connector'),
            'importCompleted'       => __('Importación completada', 'alegra-connector'),
            'importingType'         => __('Importando %s...', 'alegra-connector'),
            'confirmImportApi'      => __('¿Estás seguro de importar desde Alegra? %s?', 'alegra-connector'),

            // Dashboard kill-switch
            'confirmReactivate'     => __('¿Reactivar el plugin ahora?', 'alegra-connector'),
            'reactivating'          => __('Reactivando...', 'alegra-connector'),
            'reactivateNow'         => __('Reactivar ahora', 'alegra-connector'),

            // Monitor page
            'statusRunning'         => __('Corriendo', 'alegra-connector'),
            'statusCompleted'       => __('Completado', 'alegra-connector'),
            'statusFailed'          => __('Fallido', 'alegra-connector'),
            'statusCancelled'       => __('Cancelado', 'alegra-connector'),
            'statusKilled'          => __('Detenido', 'alegra-connector'),
            'noActiveProcesses'     => __('No hay procesos activos.', 'alegra-connector'),
            'noCronTasks'           => __('No hay tareas cron programadas.', 'alegra-connector'),
            'noRecentHistory'       => __('Sin historial reciente.', 'alegra-connector'),
            'thHook'                => __('Hook', 'alegra-connector'),
            'thNextRun'             => __('Próxima ejecución', 'alegra-connector'),
            'thFrequency'           => __('Frecuencia', 'alegra-connector'),
            'thActions'             => __('Acciones', 'alegra-connector'),
            'thType'                => __('Tipo', 'alegra-connector'),
            'thStatus'              => __('Estado', 'alegra-connector'),
            'thStart'               => __('Inicio', 'alegra-connector'),
            'thEnd'                 => __('Fin', 'alegra-connector'),
            'thItems'               => __('Items', 'alegra-connector'),
            'thMemory'              => __('Mem.', 'alegra-connector'),
            'thError'               => __('Error', 'alegra-connector'),
            'run'                   => __('Ejecutar', 'alegra-connector'),
            'runNow'                => __('Ejecutar ahora', 'alegra-connector'),
            'skipNext'              => __('Saltar la próxima ejecución', 'alegra-connector'),
            'removeAll'             => __('Eliminar todas las programaciones', 'alegra-connector'),
            'memory'                => __('Memoria', 'alegra-connector'),
            'stopProcess'           => __('Detener este proceso', 'alegra-connector'),
            'confirmStopProcess'    => __('¿Estás seguro de detener este proceso?', 'alegra-connector'),
            'confirmEmergencyStop'  => __('Esto detendrá TODOS los procesos del plugin y lo desconectará. ¿Continuar?', 'alegra-connector'),
            'stopping'              => __('Deteniendo...', 'alegra-connector'),
            'stopAll'               => __('Detener Todos los Procesos', 'alegra-connector'),
            'confirmSkipCron'       => __('¿Saltar la próxima ejecución de esta tarea cron?', 'alegra-connector'),
            'confirmRemoveCron'     => __('¿Eliminar TODAS las programaciones de esta tarea cron?', 'alegra-connector'),
            'taskSkipped'           => __('Tarea saltada', 'alegra-connector'),
            'taskRemoved'           => __('Tarea eliminada', 'alegra-connector'),
            'hookExecuted'          => __('Hook ejecutado', 'alegra-connector'),

            // Wizard
            'wizardError'           => __('Error al guardar progreso', 'alegra-connector'),
            'confirmSkipWizard'     => __('¿Saltar el asistente? Puedes volver a iniciarlo desde Dashboard.', 'alegra-connector'),

            // Statistics charts
            'chartSales'            => __('Ventas', 'alegra-connector'),
            'chartOrders'           => __('Pedidos', 'alegra-connector'),
            'chartCompleted'        => __('Completado', 'alegra-connector'),
            'chartProcessing'       => __('Procesando', 'alegra-connector'),
            'chartPending'          => __('Pendiente', 'alegra-connector'),
            'chartCancelled'        => __('Cancelado', 'alegra-connector'),
            'chartRefunded'         => __('Reembolsado', 'alegra-connector'),

            // Product import filters (modal in the Products page). Only the
            // strings consumed by admin.js live here; the modal labels are
            // rendered server-side in templates/admin-products.php.
            'importFilterLoading'   => __('Cargando categorías...', 'alegra-connector'),
            'importFilterNoCats'    => __('No se encontraron categorías en Alegra.', 'alegra-connector'),
            'importFilterCatError'  => __('No se pudieron cargar las categorías.', 'alegra-connector'),
        ];
    }

    public function render_dashboard(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'alegra-connector'));
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
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'alegra-connector'));
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

    /**
     * Redirect the wizard page before any output when it is no longer needed.
     *
     * The template used to call wp_safe_redirect() from inside the page
     * callback, which runs AFTER wp-admin/admin-header.php has already sent the
     * document — producing "headers already sent" and a broken redirect. Doing
     * it on admin_init (before output) fixes both.
     */
    public function maybe_redirect_wizard(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if ($page !== 'alegra-connector-wizard') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $wizard_done = (bool) get_user_meta(get_current_user_id(), 'alegra_wizard_done', true);
        $is_connected = (bool) get_option('alegra_connector_connection_tested');
        if ($wizard_done || $is_connected) {
            wp_safe_redirect(admin_url('admin.php?page=alegra-connector'));
            exit;
        }
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
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'alegra-connector'));
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
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'alegra-connector'));
        }

        $header_color = 'green';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-import.php';
    }

    public function render_mapping_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'alegra-connector'));
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

        // AC-58: aggregate revenue/counts in SQL. The old code materialised up
        // to 5,000 order rows into PHP just to sum them, and silently
        // under-reported on a larger store.
        $range = [$start, $end . ' 23:59:59'];
        if (HPOS::is_enabled()) {
            $status_expr = "REPLACE(o.status,'wc-','')";
            $date_expr = 'o.date_created_gmt';
            $from = "{$orders_table} o LEFT JOIN {$meta_table} om ON o.id=om.order_id AND om.meta_key='_order_total'";
            $where = "o.type='shop_order' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";

            $status_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$status_expr} AS status, COUNT(*) AS cnt, COALESCE(SUM(om.meta_value),0) AS total
                 FROM {$from} WHERE {$where} GROUP BY status",
                ...$range
            ));
            $daily_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE({$date_expr}) AS d, COUNT(*) AS cnt, COALESCE(SUM(om.meta_value),0) AS total
                 FROM {$from} WHERE {$where} GROUP BY d",
                ...$range
            ));
            $recent_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT o.id FROM {$orders_table} o WHERE o.type='shop_order'
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 ORDER BY o.date_created_gmt DESC LIMIT 5",
                ...$range
            ));
        } else {
            $status_expr = "REPLACE(p.post_status,'wc-','')";
            $from = "{$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} om ON p.ID=om.post_id AND om.meta_key='_order_total'";
            $where = "p.post_type='shop_order' AND p.post_date >= %s AND p.post_date <= %s";

            $status_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$status_expr} AS status, COUNT(*) AS cnt, COALESCE(SUM(om.meta_value),0) AS total
                 FROM {$from} WHERE {$where} GROUP BY status",
                ...$range
            ));
            $daily_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(p.post_date) AS d, COUNT(*) AS cnt, COALESCE(SUM(om.meta_value),0) AS total
                 FROM {$from} WHERE {$where} GROUP BY d",
                ...$range
            ));
            $recent_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='shop_order'
                 AND p.post_date >= %s AND p.post_date <= %s
                 ORDER BY p.post_date DESC LIMIT 5",
                ...$range
            ));
        }

        foreach ((array) $status_rows as $row) {
            $status = (string) ($row->status ?? '');
            $total = (float) ($row->total ?? 0);
            $count = (int) ($row->cnt ?? 0);

            $revenue['total'] += $total;
            $order_counts['total'] += $count;

            if (array_key_exists($status, $revenue)) {
                $revenue[$status] += $total;
                $order_counts[$status] += $count;
            }
        }

        foreach ((array) $daily_rows as $row) {
            $date = substr((string) ($row->d ?? ''), 0, 10);
            if ($date !== '') {
                $daily_sales[$date] = ['count' => (int) $row->cnt, 'total' => (float) $row->total];
            }
        }

        // Load recent 5 orders fully for display
        foreach ((array) $recent_ids as $oid) {
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
            'orders_synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meta_table} WHERE meta_key='_alegra_invoice_id'"),
            'orders_total' => $orders_total,
            'customers_synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='alegra_contact_id' AND meta_value != ''"),
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

        // AC-58: EXISTS instead of a scalar correlated subquery. The old
        // COUNT ran a 3-join subquery once per product row just to paginate.
        if ($post_type_filter === 'variable') {
            $post_type_clause = "AND p.post_type='product' AND EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable')";
        } elseif ($post_type_filter === 'simple') {
            $post_type_clause = "AND p.post_type='product' AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable')";
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
                'alegra_id' => (string) ($row->alegra_id ?: ''),
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
        $connected = (bool) get_option('alegra_connector_connection_tested');

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-products.php';
    }

    private function render_product_detail(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_die(esc_html__('Producto no encontrado.', 'alegra-connector'));
        }

        $alegra_id = (string) get_post_meta($product_id, '_alegra_item_id', true);
        $alegra_data = null;
        $alegra_error = null;

        if ($alegra_id !== '' && $alegra_id !== null && get_option('alegra_connector_connection_tested') && $this->api) {
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
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='alegra_contact_id' AND meta_value != ''"
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

        $alegra_id = (string) get_user_meta($customer_id, 'alegra_contact_id', true);
        $alegra_data = null;
        $alegra_error = null;

        if ($alegra_id !== '' && $alegra_id !== null && get_option('alegra_connector_connection_tested') && $this->api) {
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
        $meta_table = HPOS::get_order_meta_table();
        if (HPOS::is_enabled()) {
            $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table} WHERE type='shop_order'");
        } else {
            $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'");
        }
        $total_pages = (int) ceil($total_orders / $per_page);

        $synced_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meta_table} WHERE meta_key='_alegra_invoice_id' AND meta_value != ''");
        $payment_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meta_table} WHERE meta_key='_alegra_payment_id' AND meta_value != ''");

        $header_color = 'amber';

        include ALEGRA_CONNECTOR_PATH . 'templates/admin-orders.php';
    }

    private function render_order_detail(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Pedido no encontrado.', 'alegra-connector'));
        }

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        $alegra_invoice_number = $order->get_meta('_alegra_invoice_number', true);
        $alegra_payment_id = (string) $order->get_meta('_alegra_payment_id', true);
        $alegra_data = null;
        $alegra_error = null;

        // Make API available for templates via local variable (cleaner than $GLOBALS)
        $alegra_api = $this->api;

        if ($alegra_invoice_id !== '' && $alegra_invoice_id !== null && get_option('alegra_connector_connection_tested') && $this->api) {
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

        // Overwriting the stored API credentials is an admin-level action, not a
        // shop-manager one (AC-33).
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $token = sanitize_text_field($_POST['token'] ?? '');

        // The token field is masked; fall back to the stored token when empty.
        if ($token === '') {
            $token = (string) get_option('alegra_connector_token', '');
        }

        if (empty($email) || empty($token)) {
            wp_send_json_error(['message' => __('Email y token son requeridos.', 'alegra-connector')]);
        }

        $this->log('info', 'Connection test started', ['email' => $email]);

        // Save credentials (never overwrite the token with an empty value).
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
        update_option('alegra_connector_diagnostics', $diagnostics, false);

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
        wp_send_json_success(['message' => __('ok', 'alegra-connector'), 'use_chunked' => true, 'type' => $sync_type]);
    }

    /**
     * AJAX: pull inventory from Alegra (Alegra → WooCommerce stock).
     *
     * Manual counterpart of the documented `alegra_sync_inventory_from_alegra`
     * action. The pull enforces the inventory_source / kill-switch / lock /
     * cancellation gates itself.
     */
    public function ajax_sync_inventory(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $result = (new Sync\Controller($this->api, $this->logger))->run_inventory_sync();

        if (!empty($result['skipped'])) {
            wp_send_json_error(['message' => __('La copia de inventario está desactivada: la fuente de inventario es WooCommerce.', 'alegra-connector')]);
        }
        if (!empty($result['locked'])) {
            wp_send_json_error(['message' => __('Ya hay una sincronización en curso. Intenta de nuevo en unos segundos.', 'alegra-connector')]);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products whose stock was updated */
                __('Inventario sincronizado: %d productos actualizados.', 'alegra-connector'),
                (int) ($result['updated'] ?? 0)
            ),
            'updated' => (int) ($result['updated'] ?? 0),
        ]);
    }

    /**
     * Sanitize the product-import filters posted from the Products modal.
     *
     * Invalid input degrades to "all" and never throws. Shape:
     *   [idItemCategory => string, status => default|active|inactive,
     *    inventariable => bool, query => string, type => ''|simple|kit|variantParent]
     *
     * @param array<string,mixed> $raw
     * @return array{idItemCategory:string,status:string,inventariable:bool,query:string,type:string}
     */
    private function sanitize_item_filters(array $raw): array
    {
        $status = isset($raw['status']) ? sanitize_text_field((string) $raw['status']) : 'default';
        if (!in_array($status, ['default', 'active', 'inactive'], true)) {
            $status = 'default';
        }

        $type = isset($raw['type']) ? sanitize_text_field((string) $raw['type']) : '';
        if (!in_array($type, ['', 'simple', 'kit', 'variantParent'], true)) {
            $type = '';
        }

        return [
            'idItemCategory' => isset($raw['idItemCategory']) ? sanitize_text_field((string) $raw['idItemCategory']) : '',
            'status'         => $status,
            'inventariable'  => filter_var($raw['inventariable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'query'          => isset($raw['query']) ? sanitize_text_field((string) $raw['query']) : '',
            'type'           => $type,
        ];
    }

    /**
     * Whitelist the WooCommerce fields that must NOT be overwritten on import.
     *
     * Used as the `sanitize_callback` of `alegra_connector_import_preserve_fields`
     * (Ajustes → Sincronización) and for the per-run value from the modal.
     *
     * @param mixed $value
     * @return array<int,string> Subset of description|name|price|images|inventory|sku
     */
    public static function sanitize_preserve_fields($value): array
    {
        $allowed = ['description', 'name', 'price', 'images', 'inventory', 'sku'];
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $field) {
            $field = sanitize_key((string) $field);
            if (in_array($field, $allowed, true)) {
                $out[] = $field;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Translate sanitized filters into Alegra `GET /items` query params.
     *
     * The implicit `status=active` (driven by sync_inactive_products) is only
     * added when $include_default_status is true. The metadata call in
     * ajax_sync_start passes false so that, with no filters, the total is
     * byte-for-byte the same as before this feature.
     *
     * @param array{idItemCategory?:string,status?:string,inventariable?:bool,query?:string,type?:string} $f
     * @return array<string,string>
     */
    private function build_item_filter_params(array $f, bool $include_default_status = true): array
    {
        $p = [];

        if (($f['idItemCategory'] ?? '') !== '') {
            $p['idItemCategory'] = (string) $f['idItemCategory'];
        }

        if (in_array($f['status'] ?? 'default', ['active', 'inactive'], true)) {
            $p['status'] = (string) $f['status'];
        } elseif ($include_default_status && !get_option('alegra_connector_sync_inactive_products', false)) {
            // Preserves the pre-existing behaviour on the page loop.
            $p['status'] = 'active';
        }

        if (!empty($f['inventariable'])) {
            // The API documents a boolean; send the literal "true" (a PHP bool
            // would serialize to "1" through http_build_query()).
            $p['inventariable'] = 'true';
        }

        if (($f['query'] ?? '') !== '') {
            $p['query'] = (string) $f['query'];
        }

        // `simple` and `kit` are the documented `type` filter values.
        // `variantParent` is handled client-side in ajax_sync_page.
        if (in_array($f['type'] ?? '', ['simple', 'kit'], true)) {
            $p['type'] = (string) $f['type'];
        }

        return $p;
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

        // Product-import filters (empty for the other entity types / legacy calls).
        $raw_filters = $_POST['filters'] ?? '';
        if (is_string($raw_filters) && $raw_filters !== '') {
            $decoded = json_decode(wp_unslash($raw_filters), true);
        } elseif (is_array($raw_filters)) {
            $decoded = $raw_filters;
        } else {
            $decoded = [];
        }
        $filters = $this->sanitize_item_filters(is_array($decoded) ? $decoded : []);

        // Get exact total via metadata=true (single API call!)
        $total = 0;
        if ($type === 'customers') {
            $resp = $this->api->get('/contacts', ['metadata' => 'true', 'limit' => 1, 'type' => 'client']);
            if (!is_wp_error($resp) && isset($resp['metadata']['total'])) {
                $total = (int) $resp['metadata']['total'];
            }
        } else {
            // The metadata total must reflect the explicit filters, but NOT the
            // implicit default status, to preserve the historical total.
            $params = ['metadata' => 'true', 'limit' => 1] + $this->build_item_filter_params($filters, false);
            $resp = $this->api->get('/items', $params);
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
            'filters' => $filters,
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
        if (!$state) wp_send_json_error(['message' => __('No hay un proceso de sincronización en curso.', 'alegra-connector')]);

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
            $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
            // Build API params. With no filters this is exactly the historical
            // ['start','limit','mode'] + implicit status=active.
            $api_params = ['start' => $start, 'limit' => $per_page, 'mode' => 'advanced']
                + $this->build_item_filter_params($filters, true);
            $items = $this->api->get('/items', $api_params);
            if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()]); }

            $products_sync = new Sync\Products($this->api, $this->logger);
            foreach ($items as $item) {
                // UUID string — never cast an Alegra id to int.
                $alegra_id = (string) ($item['id'] ?? '');
                $item_type = $item['type'] ?? 'simple';

                // Skip variants - imported with their parent
                if ($item_type === 'variant') { continue; }

                // Client-side `variantParent` filter: the API does not document
                // this value for the `type` query param, so the whole catalog is
                // walked and non-variantParent items are discarded here.
                if (($filters['type'] ?? '') === 'variantParent' && $item_type !== 'variantParent') {
                    $state['skipped'] = ($state['skipped'] ?? 0) + 1;
                    continue;
                }

                // Use Products class for proper import (handles variable, images, etc.)
                // Already-linked products will be updated, new ones created. The
                // field exclusion (Ajustes) applies only to existing products.
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
                // UUID string — never cast an Alegra id to int.
                $alegra_id = (string) ($item['id'] ?? '');
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
                    // UUID string — an (int) cast stored 0 for every category.
                    update_term_meta($t['term_id'], 'alegra_category_id', (string) ($item['id'] ?? ''));
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

        // Clear the batch state when the run is done; otherwise persist it so
        // the next page can resume.
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
            'message' => sprintf(__('%d/%d items — Pág. %d/%d', 'alegra-connector'), $processed, (int)($state['total_items'] ?? 0), $page, $tp),
        ]);
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
        }
    }

    /**
     * List Alegra item categories for the product-import filter modal.
     *
     * Paginated with `start`/`limit` (max 30 per the documented endpoint,
     * https://developer.alegra.com/reference/get_item-categories). Walks up to
     * 10 pages and reports whether more remain.
     */
    public function ajax_get_item_categories(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        if (!get_option('alegra_connector_connection_tested')) {
            wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
        }

        $this->api->reload_credentials();

        $categories = [];
        $start = 0;
        $per_page = 30;
        $has_more = false;

        for ($page = 0; $page < 10; $page++) {
            $batch = $this->api->get_item_categories(['start' => $start, 'limit' => $per_page]);
            if (is_wp_error($batch)) {
                if ($page === 0) {
                    wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $batch->get_error_message())]);
                }
                break;
            }
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $category) {
                if (!is_array($category) || empty($category['id'])) {
                    continue;
                }
                $categories[] = [
                    'id'   => (string) $category['id'],
                    'name' => (string) ($category['name'] ?? ''),
                ];
            }
            if (count($batch) < $per_page) {
                break;
            }
            $start += $per_page;
            // A full page on the last allowed iteration means more remain.
            if ($page === 9) {
                $has_more = true;
            }
        }

        wp_send_json_success([
            'categories' => $categories,
            'has_more'   => $has_more,
        ]);
    }

    public function ajax_import_csv(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        // The import creates PUBLISHED products, so it needs a shop-management
        // capability — `upload_files` is held by Authors (AC-33).
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('No se encontró el archivo CSV.', 'alegra-connector')]);
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
            'message' => sprintf(__('Importación completada: %d items', 'alegra-connector'), $result['count']),
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

        // AC-81: these arrays can grow with configuration; keep them out of the
        // autoloaded `alloptions` payload (3rd arg = autoload 'no').
        update_option('alegra_connector_field_mapping', map_deep($field_mapping, 'sanitize_text_field'), false);
        update_option('alegra_connector_tax_mapping', map_deep($tax_mapping, 'sanitize_text_field'), false);

        $this->logger->info('Field mapping saved');

        wp_send_json_success(['message' => __('Mapeo guardado.', 'alegra-connector')]);
    }

    /**
     * AJAX: open a draft invoice in Alegra from the order detail page.
     *
     * Used when the merchant configured invoices as `draft` and now wants an
     * existing one to become an open/issued invoice (without recording a
     * payment). No-op when the invoice is already open.
     */
    public function ajax_open_invoice(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order) {
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'alegra-connector')]);
        }

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '') {
            wp_send_json_error(['message' => __('El pedido no tiene una factura de Alegra vinculada.', 'alegra-connector')]);
        }

        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
        $result = $orders_sync->ensure_invoice_open($alegra_invoice_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        $status = (string) ($result['status'] ?? 'open');
        if ($status === 'draft') {
            // Alegra accepted the call but the invoice is still a draft.
            wp_send_json_error(['message' => __('Alegra no abrió la factura; sigue en borrador.', 'alegra-connector')]);
        }

        $orders_sync->persist_invoice_status($order, $result);

        $order->add_order_note(sprintf(
            /* translators: %s: Alegra invoice number or id */
            __('[Alegra] Factura #%s abierta (ya no es borrador).', 'alegra-connector'),
            (string) $order->get_meta('_alegra_invoice_number', true) ?: $alegra_invoice_id
        ));

        wp_send_json_success([
            'message' => __('Factura abierta en Alegra.', 'alegra-connector'),
            'status'  => $status,
        ]);
    }

    public function ajax_record_payment(): void
    {
        check_ajax_referer('alegra_connector_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $order_id = (int) ($_POST['order_id'] ?? 0);
        if ($order_id <= 0) {
            wp_send_json_error(['message' => __('ID de pedido inválido.', 'alegra-connector')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'alegra-connector')]);
        }

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '' || $alegra_invoice_id === null) {
            wp_send_json_error(['message' => __('Primero crea la factura en Alegra.', 'alegra-connector')]);
        }

        // Check if payment already recorded
        $existing_payment = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment !== '' && $existing_payment !== null) {
            wp_send_json_error(['message' => __('El pago ya está registrado en Alegra.', 'alegra-connector')]);
        }

        $account_id = (string) get_option('alegra_connector_payment_account_id', '');
        if ($account_id === '' || $account_id === '0') {
            wp_send_json_error(['message' => __('Configura una cuenta bancaria en Ajustes > Avanzado.', 'alegra-connector')]);
        }

        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);

        // BUG 6: a payment requires an OPEN invoice. The invoice may be a draft
        // (the default), so open it first — exactly like the auto path does.
        $opened = $orders_sync->ensure_invoice_open($alegra_invoice_id);
        if (!is_wp_error($opened)) {
            $orders_sync->persist_invoice_status($order, $opened);
        }

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

        // Dry Run: the payment was NOT recorded. Do not save an empty payment id,
        // do not add a success note, and tell the UI explicitly.
        if (API\Client::is_dry_run_response($result)) {
            $this->logger->warning('Payment recording skipped (dry run)', [
                'order_id'   => $order_id,
                'invoice_id' => $alegra_invoice_id,
            ]);
            wp_send_json_success([
                'dry_run' => true,
                'message' => __('Modo de prueba activo: el pago NO se registró en Alegra.', 'alegra-connector'),
            ]);
        }

        // Alegra payment ids are UUID strings.
        $payment_id = (string) ($result['id'] ?? '');
        $order->update_meta_data('_alegra_payment_id', $payment_id);
        if (!empty($result['number'])) {
            $order->update_meta_data('_alegra_payment_number', $result['number']);
        }
        $order->save();

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
            // REQ-MAN-1: "Facturar" uses the payment-capable path. Whether a
            // payment is posted is decided by $order->is_paid() inside
            // create_invoice_with_payment(), not by this action.
            case 'order': $result = $sync_controller->sync_entity('order', $entity_id, 'complete'); break;
            default: wp_send_json_error(['message' => __('Tipo de entidad desconocido.', 'alegra-connector')]);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
        }

        wp_send_json_success(['message' => __('Sincronización completada.', 'alegra-connector'), 'data' => $result]);
    }

    /**
     * Collect every exportable row for the given type, paginated to completion
     * (AC-64). The previous implementation silently capped at 500 rows.
     *
     * @return array<int, array<int, string>>
     */
    public static function collect_export_rows(string $type): array
    {
        $per_page = 500;
        $rows = [];

        if ($type === 'products') {
            $page = 1;
            while (true) {
                $products = wc_get_products([
                    'limit'  => $per_page,
                    'page'   => $page,
                    'status' => 'publish',
                ]);
                if (empty($products)) {
                    break;
                }
                foreach ($products as $p) {
                    $ai = (string) get_post_meta($p->get_id(), '_alegra_item_id', true);
                    $rows[] = array_map([self::class, 'csv_safe_cell'], [
                        $p->get_name(), $p->get_sku(), $p->get_price(), $p->get_stock_quantity(),
                        // UUID strings are never > 0 — compare to '' instead.
                        $p->get_type(), $ai !== '' ? $ai : '', $ai !== '' ? 'Sincronizado' : 'Pendiente',
                    ]);
                }
                if (count($products) < $per_page) {
                    break;
                }
                $page++;
            }
            return $rows;
        }

        $offset = 0;
        while (true) {
            $customers = get_users([
                'role'   => 'customer',
                'number' => $per_page,
                'offset' => $offset,
            ]);
            if (empty($customers)) {
                break;
            }
            foreach ($customers as $c) {
                $ai = (string) get_user_meta($c->ID, 'alegra_contact_id', true);
                $rows[] = array_map([self::class, 'csv_safe_cell'], [
                    $c->display_name, $c->user_email, get_user_meta($c->ID, 'billing_phone', true),
                    // UUID strings are never > 0 — compare to '' instead.
                    $ai !== '' ? $ai : '', $ai !== '' ? 'Sincronizado' : 'Pendiente',
                ]);
            }
            if (count($customers) < $per_page) {
                break;
            }
            $offset += $per_page;
        }

        return $rows;
    }

    public function ajax_export_csv(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die();

        $type = sanitize_text_field($_GET['export_type'] ?? 'products');
        $is_products = ($type === 'products');

        header('Content-Type: text/csv; charset=utf-8');
        header(
            'Content-Disposition: attachment; filename="'
            . ($is_products ? 'alegra-productos-' : 'alegra-clientes-') . date('Y-m-d') . '.csv"'
        );

        $out = fopen('php://output', 'w');
        if ($is_products) {
            fputcsv($out, ['name', 'sku', 'price', 'stock', 'type', 'alegra_id', 'sync_status']);
        } else {
            fputcsv($out, ['name', 'email', 'phone', 'alegra_id', 'sync_status']);
        }
        foreach (self::collect_export_rows($type) as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
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
            // REQ-MAN-2: bulk "Facturar seleccionados" must also register the
            // payment of each paid order, so it uses the complete path too.
            $action = $type === 'order' ? 'complete' : 'update';
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
            $alegra_id = (string) get_post_meta($wc_id, '_alegra_item_id', true);
            if ($alegra_id === '' || $alegra_id === null) wp_send_json_error(['message' => __('Producto no vinculado a Alegra.', 'alegra-connector')]);
            $sync = new Sync\Products($this->api, $this->logger);
            $result = $sync->sync_single_item_by_alegra_id($alegra_id);
        } elseif ($type === 'customer') {
            $alegra_id = (string) get_user_meta($wc_id, 'alegra_contact_id', true);
            if ($alegra_id === '' || $alegra_id === null) wp_send_json_error(['message' => __('Cliente no vinculado a Alegra.', 'alegra-connector')]);
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
                $alegra_id = (string) get_post_meta($wc_id, '_alegra_item_id', true);
                if ($alegra_id === '' || $alegra_id === null) { $skipped++; continue; }
                $sync = new Sync\Products($this->api, $this->logger);
                $r = $sync->sync_single_item_by_alegra_id($alegra_id);
            } elseif ($type === 'customer') {
                $alegra_id = (string) get_user_meta($wc_id, 'alegra_contact_id', true);
                if ($alegra_id === '' || $alegra_id === null) { $skipped++; continue; }
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
        // Calls wp_delete_user(): requires the capability to delete users (AC-33).
        if (!current_user_can('delete_users')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

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
     * Cancel a running chunked sync by deleting the batch state transient.
     */
    public function ajax_cancel_sync(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        set_transient('alegra_sync_cancelled', 1, 120);
        delete_transient('alegra_batch_state');
        wp_send_json_success(['message' => __('Sincronización cancelada', 'alegra-connector')]);
    }

    public function ajax_register_webhooks(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
        }

        $secret = sanitize_text_field($_POST['webhook_secret'] ?? '');
        // The field is masked, so an empty value means "use the stored secret".
        if ($secret === '') {
            $secret = (string) get_option('alegra_connector_webhook_secret', '');
        }
        if (empty($secret)) {
            wp_send_json_error(['message' => __('Webhook Secret es requerido.', 'alegra-connector')]);
        }

        update_option('alegra_connector_webhook_secret', $secret);

        // Embed the shared secret in the URL: Alegra cannot sign deliveries, so
        // this is the only credential that can authenticate a delivery. The
        // Receiver enforces it with hash_equals().
        $webhook_url = \Alegra\Connector\Webhooks\Receiver::registration_url();
        $events = API\Client::get_webhook_events();
        $created = [];
        $already = [];
        $errors = [];

        foreach ($events as $event) {
            $result = $this->api->create_webhook_subscription($event, $webhook_url);
            if (is_wp_error($result)) {
                if (self::is_webhook_already_registered($result)) {
                    // Alegra answers 400 "Ya existe una suscripción con el
                    // mismo evento y URL" when the subscription is already
                    // there. Re-registering right after an update is the
                    // expected action, so this is success — not an error.
                    $already[] = $event;
                    continue;
                }
                $errors[] = $event . ': ' . $result->get_error_message();
                continue;
            }

            // Documented 200 shape (post_webhooks-subscriptions): the id is
            // nested at {message, subscription:{id,event,url}}. The old code
            // read $result['id'], so every success was counted as a failure.
            // Tolerate the flat shape too in case the API ever inlines it.
            $subscription = is_array($result) ? ($result['subscription'] ?? null) : null;
            $id = '';
            if (is_array($subscription) && !empty($subscription['id'])) {
                $id = (string) $subscription['id'];
            } elseif (is_array($result) && !empty($result['id'])) {
                $id = (string) $result['id'];
            }

            if ($id !== '') {
                $created[] = [
                    'id' => $id,
                    'event' => (string) ($subscription['event'] ?? $event),
                ];
            } else {
                $errors[] = $event . ': ' . __('respuesta sin id de suscripción', 'alegra-connector');
            }
        }

        // Persist every subscription we know about, not only the newly created
        // ones. The DELETE flow deletes by id, and a 400 "already exists"
        // carries no id, so dropping the previously stored entry would orphan
        // the remote subscription and make it impossible to remove.
        $subscriptions = $this->merge_webhook_subscriptions($created, $already);
        if (!empty($subscriptions)) {
            // AC-81: the subscription list grows with configuration; do not
            // autoload it on every request.
            update_option('alegra_connector_webhook_subscriptions', $subscriptions, false);
        }

        $message = sprintf(
            __('%d webhooks registrados, %d ya existían, %d errores.', 'alegra-connector'),
            count($created),
            count($already),
            count($errors)
        );
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('Errores: %s', 'alegra-connector'), implode(', ', $errors));
        }

        wp_send_json_success([
            'message' => $message,
            'creados' => count($created),
            'ya_existian' => count($already),
            'errores' => count($errors),
        ]);
    }

    /**
     * Whether a create_webhook_subscription() failure is the documented
     * "already exists" response.
     *
     * Matched NARROWLY against the documented Spanish message
     * (post_webhooks-subscriptions, 400 existingSubscription) so a genuine
     * failure — invalid URL, auth, 5xx — is never swallowed. The check is
     * accent-safe by matching only the unaccented prefix of the phrase.
     */
    private static function is_webhook_already_registered(\WP_Error $error): bool
    {
        if (stripos($error->get_error_message(), 'ya existe una suscripci') === false) {
            return false;
        }

        $data = $error->get_error_data();
        $code = is_array($data) ? (int) ($data['code'] ?? 0) : 0;
        return $code === 0 || $code === 400;
    }

    /**
     * Build the local subscription list from this run's registrations plus the
     * ones already stored, keyed by event so a partial re-register never drops
     * an id the DELETE flow needs.
     *
     * The id of an "already exists" subscription is recovered from the API
     * listing (GET /webhooks/subscriptions) when it is unknown locally.
     *
     * @param array<int, array{id:string, event:string}> $created
     * @param array<int, string>                          $already
     * @return array<int, array{id:string, event:string}>
     */
    private function merge_webhook_subscriptions(array $created, array $already): array
    {
        $by_event = [];
        foreach ((array) get_option('alegra_connector_webhook_subscriptions', []) as $sub) {
            if (is_array($sub) && !empty($sub['event'])) {
                $by_event[(string) $sub['event']] = $sub;
            }
        }
        foreach ($created as $sub) {
            $by_event[(string) $sub['event']] = $sub;
        }

        $unknown = array_filter($already, static fn($event) => empty($by_event[$event]['id']));
        if (!empty($unknown)) {
            $remote = $this->remote_webhook_ids_by_event();
            foreach ($unknown as $event) {
                if (!empty($remote[$event])) {
                    $by_event[$event] = ['id' => $remote[$event], 'event' => $event];
                }
            }
        }

        return array_values($by_event);
    }

    /**
     * Remote subscription ids keyed by event. Best-effort: any failure (or an
     * unexpected shape) yields an empty map rather than aborting registration.
     *
     * @return array<string, string>
     */
    private function remote_webhook_ids_by_event(): array
    {
        $result = $this->api->get_webhook_subscriptions();
        if (is_wp_error($result) || !is_array($result)) {
            return [];
        }

        $list = $result['subscriptions'] ?? (isset($result[0]) && is_array($result[0]) ? $result : []);
        $map = [];
        foreach ((array) $list as $sub) {
            if (is_array($sub) && !empty($sub['id']) && !empty($sub['event'])) {
                $map[(string) $sub['event']] = (string) $sub['id'];
            }
        }
        return $map;
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
        $remaining = [];
        $dry_run_skipped = 0;

        foreach ($subscriptions as $sub) {
            $id = $sub['id'] ?? '';
            if (empty($id)) {
                $remaining[] = $sub;
                continue;
            }
            $result = $this->api->delete_webhook_subscription((string) $id);
            if (is_wp_error($result)) {
                $errors[] = $sub['event'] . ': ' . $result->get_error_message();
                $remaining[] = $sub;
            } elseif (API\Client::is_dry_run_response($result)) {
                // Dry Run: nothing was deleted in Alegra; keep it locally.
                $dry_run_skipped++;
                $remaining[] = $sub;
            } else {
                $deleted++;
            }
        }

        // Only drop the subscriptions that were ACTUALLY deleted.
        if (!empty($remaining)) {
            update_option('alegra_connector_webhook_subscriptions', $remaining, false);
        } else {
            delete_option('alegra_connector_webhook_subscriptions');
        }

        if ($dry_run_skipped > 0) {
            wp_send_json_success([
                'dry_run' => true,
                'message' => __('Modo de prueba activo: no se eliminó ningún webhook en Alegra.', 'alegra-connector'),
                'deleted' => $deleted,
            ]);
        }

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
            // AC-34: read order meta through the CRUD API. get_post_meta() is
            // empty under HPOS, so every order was classified "pending".
            $order = wc_get_order($oid);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            if ((string) $order->get_meta('_alegra_invoice_id', true) === '') {
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
            wp_send_json_error(['message' => __('No hay pedidos pendientes', 'alegra-connector')]);
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
            'message' => sprintf(__('%d/%d facturas — %d ok, %d errores', 'alegra-connector'), $state['processed'], $state['total'], $state['synced'], $state['errors']),
        ]);
    }

    public function ajax_get_invoice_pdf(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_die();

        $order_id = (int) ($_GET['order_id'] ?? 0);
        if ($order_id <= 0) wp_die(__('Pedido invalido.', 'alegra-connector'));

        $order = wc_get_order($order_id);
        if (!$order) wp_die(__('Pedido invalido.', 'alegra-connector'));

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '' || $alegra_invoice_id === null) wp_die(__('Este pedido no tiene factura en Alegra.', 'alegra-connector'));

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
        // Clears the whole integration config: admin-level (AC-33).
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

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
        $cron_hooks = ['alegra_connector_cron_sync'];
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
        $cron_hooks = ['alegra_connector_cron_sync'];
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

        // AC-61: no unbounded execution-time override here. The hook is
        // scheduled as a one-off event, so this request only enqueues it — it
        // does not run the sync inline. The sync itself is bounded by the
        // import time budget and resumes from a cursor.
        $hook = sanitize_text_field($_POST['hook'] ?? '');
        if (empty($hook) || !preg_match('/^[a-zA-Z0-9_]+$/', $hook)) {
            wp_send_json_error(['message' => __('Hook invalido.', 'alegra-connector')]);
        }

        // AC-52: an explicit allowlist of this plugin's own cron hooks. A
        // `strpos($hook, 'alegra') === 0` prefix check is too loose ("alegrax"
        // matches), and the old manual-run recurrence was never registered.
        $allowed_hooks = ['alegra_connector_cron_sync'];
        if (!in_array($hook, $allowed_hooks, true)) {
            wp_send_json_error(['message' => __('Solo se permiten hooks de Alegra.', 'alegra-connector')]);
        }

        // Queue a ONE-OFF under a DEDICATED hook, then trigger WP's cron spawn
        // so the next request executes it. This never touches the recurring
        // event (the old wp_clear_scheduled_hook() removed it permanently), and
        // it dodges WP's 10-minute duplicate suppression that would otherwise
        // swallow a single event scheduled under the recurring hook itself.
        wp_schedule_single_event(time(), 'alegra_connector_cron_sync_now');
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

        // "Skip" only makes sense for the periodic sync, whose interval we know.
        // Restricting the allowlist also stops a stray hook from being
        // rescheduled on the wrong cadence.
        if ($hook !== 'alegra_connector_cron_sync') {
            wp_send_json_error(['message' => __('Solo se puede saltar la sincronización periódica.', 'alegra-connector')]);
        }

        $timestamp = (int) ($_POST['timestamp'] ?? 0);
        if ($timestamp <= 0) {
            wp_send_json_error(['message' => __('Timestamp invalido.', 'alegra-connector')]);
        }

        // Move the next run forward — never delete the recurrence. WP only
        // regenerates a recurring event when it fires, so wp_unschedule_event()
        // on a future occurrence silently killed the periodic sync. Re-register
        // a fresh recurring event one interval from now instead.
        $frequency = (int) get_option('alegra_connector_sync_frequency', 15);
        if (!in_array($frequency, [5, 15, 30, 60], true)) {
            $frequency = 15;
        }
        $schedule_name = 'alegra_connector_' . $frequency . 'min';
        $next_run = time() + ($frequency * MINUTE_IN_SECONDS);

        wp_clear_scheduled_hook($hook);
        wp_schedule_event($next_run, $schedule_name, $hook);

        $this->log('info', 'Cron event skipped by user', ['hook' => $hook, 'timestamp' => $timestamp, 'next_run' => $next_run]);

        wp_send_json_success([
            'message' => sprintf(
                __('Proxima ejecucion de "%s" movida al siguiente ciclo.', 'alegra-connector'),
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

        // Record the user's intent so the init self-heal does not resurrect a
        // schedule the merchant explicitly removed. schedule_cron() clears it.
        if ($hook === 'alegra_connector_cron_sync') {
            update_option('alegra_connector_cron_disabled', 1, false);
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

        // Update the option that drives schedule_cron(). update_option() itself
        // fires `update_option_alegra_connector_sync_frequency`, which
        // schedule_cron() is hooked to, so an explicit do_action() here
        // scheduled the cron a second time (AC-78).
        update_option('alegra_connector_sync_frequency', $frequency);

        $this->log('info', 'Cron frequency changed', ['hook' => $hook, 'frequency' => $frequency]);

        wp_send_json_success([
            'message' => sprintf(
                __('Frecuencia de "%s" cambiada a %d minutos.', 'alegra-connector'),
                $hook,
                $frequency
            ),
        ]);
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
            return new \WP_Error('invalid_csv', __('Archivo CSV inválido.', 'alegra-connector'));
        }

        $headers = array_map('trim', $headers);
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count($row) !== count($headers)) {
                $errors[] = sprintf(
                    /* translators: %d: line number */
                    __('Línea %d: número de columnas no coincide con el encabezado.', 'alegra-connector'),
                    $line
                );
                continue;
            }

            $data = array_combine($headers, $row);
            if ($data === false) {
                $errors[] = sprintf(
                    /* translators: %d: line number */
                    __('Línea %d: error al procesar la fila.', 'alegra-connector'),
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
                        __('Línea %d: no se pudo importar el producto.', 'alegra-connector'),
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
        // Calls wp_delete_attachment(): requires the capability to delete posts (AC-33).
        if (!current_user_can('delete_posts')) {
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
