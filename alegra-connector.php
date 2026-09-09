<?php
/**
 * Plugin Name: Alegra Connector
 * Plugin URI: https://github.com/example/alegra-connector
 * Description: WooCommerce - Alegra integration plugin for bidirectional synchronization of products, customers, orders, and categories.
 * Version: 2.1.8
 * Author: Script Develop
 * Author URI: https://scriptdevelop.com.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alegra-connector
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 10.8
 */

declare(strict_types=1);

namespace Alegra\Connector;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('ALEGRA_CONNECTOR_VERSION', '2.1.8');
define('ALEGRA_CONNECTOR_PATH', plugin_dir_path(__FILE__));
define('ALEGRA_CONNECTOR_URL', plugin_dir_url(__FILE__));
define('ALEGRA_CONNECTOR_BASENAME', plugin_basename(__FILE__));
define('ALEGRA_CONNECTOR_API_URL', 'https://api.alegra.com/api/v1');
define('ALEGRA_CONNECTOR_FILE', __FILE__);

/**
 * Declare WooCommerce compatibility with modern features.
 *
 * This must happen before_woocommerce_init to prevent
 * the "incompatible with WooCommerce features" warning.
 */
add_action('before_woocommerce_init', function (): void {
    if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
});

/**
 * PSR-4 Autoloader for Alegra\Connector\* classes.
 *
 * Resolution order (priority top-to-bottom):
 *   1. Dedicated fast-path for the Logger namespace — fixes the regression
 *      that caused the 2.1.7 activation fatal on shared hosting where the
 *      lowercase `logger/` directory was missed by the generic candidates.
 *   2. includes/<Subdir>/ (Webhooks, Sync, API).
 *   3. Generic fallbacks: includes/, admin/, public/, logger/.
 *
 * History: previously had an explicit `require_once __DIR__ . '/logger/Logger/Logger.php';`
 * at the top of the file as a workaround. That fragile include was removed in
 * 2.1.8 — the Logger fast-path below replaces it without the side effect of
 * throwing a hard fatal if the file is missing.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Alegra\\Connector\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $relative_path = str_replace('\\', '/', $relative_class) . '.php';

    // Fast-path for the Logger namespace. Must come first to guarantee correct
    // resolution on hosting environments where the lowercase `logger/`
    // directory was historically missed by the generic candidates loop.
    if (strpos($class, 'Alegra\\Connector\\Logger\\') === 0) {
        $candidate = __DIR__ . '/logger/' . $relative_path;
        if (file_exists($candidate)) {
            require_once $candidate;
            return;
        }
    }

    // Search includes/<Subdir>/ (e.g., Webhooks/, Sync/, API/).
    // NOTE: 'Logger' was removed from this list — handled by the fast-path above.
    $subdirs = ['Webhooks', 'Sync', 'API'];
    foreach ($subdirs as $subdir) {
        $subdir_path = __DIR__ . '/includes/' . $subdir . '/' . $relative_path;
        if (file_exists($subdir_path)) {
            require_once $subdir_path;
            return;
        }
    }

    // Generic fallbacks across the plugin's top-level directories.
    $candidates = [
        __DIR__ . '/includes/' . $relative_path,
        __DIR__ . '/admin/' . $relative_path,
        __DIR__ . '/public/' . $relative_path,
        __DIR__ . '/logger/' . $relative_path,
    ];

    foreach ($candidates as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/**
 * Main Plugin Class
 */
final class Alegra_Connector
{
    /**
     * Single instance
     */
    private static ?Alegra_Connector $instance = null;

    /**
     * Plugin components
     */
    private ?Admin\Admin_Dashboard $admin = null;
    private ?Public\Public_ $public = null;
    private ?Logger\Logger $logger = null;
    private ?API\Client $api = null;
    private ?Sync\Controller $sync_controller = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void
    {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 1);
        add_action('init', [$this, 'load_textdomain']);
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('update_option_alegra_connector_sync_frequency', [$this, 'schedule_cron']);
        add_action('update_option_alegra_connector_sync_method', [$this, 'schedule_cron']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Bridge hook: runs the actual sync hook when "Run now" is triggered.
        // We pass the target hook name via a transient so this single dispatcher
        // can invoke any alegra_* hook.
        add_action('alegra_manual_run', function ($target_hook) {
            if (is_string($target_hook) && strpos($target_hook, 'alegra') === 0) {
                do_action($target_hook);
            }
        });

        // DB migrations on plugins_loaded (idempotent via dbDelta)
        add_action('plugins_loaded', [\Alegra\Connector\Schema::class, 'migrate'], 5);

        // Tombstone hook: track products deleted in WC
        add_action('before_delete_post', [\Alegra\Connector\Tombstone_Manager::class, 'on_post_delete']);

        // Self-check fallback: if the critical Logger class is still not
        // resolvable after everything else has loaded (typical when the
        // lowercase `logger/` directory was missing from the release ZIP),
        // surface a clear Spanish admin notice AND auto-deactivate the
        // plugin. Replaces the 2.1.7 behavior of crashing with a fatal.
        // Priority 999 — runs after every other plugins_loaded listener.
        add_action('plugins_loaded', function (): void {
            if (class_exists(\Alegra\Connector\Logger\Logger::class, false)) {
                return;
            }
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__(
                    'Alegra Connector: no se pudo cargar la clase Logger. El directorio logger/ parece estar incompleto en esta instalación. El plugin fue desactivado para evitar errores. Por favor, reinstala el plugin completo o contacta al soporte.',
                    'alegra-connector'
                );
                echo '</p></div>';
            });
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(ALEGRA_CONNECTOR_BASENAME);
            }
        }, 999);
    }

    /**
     * Register custom cron schedules
     */
    public function register_cron_schedules(array $schedules): array
    {
        $intervals = [5, 15, 30, 60];
        foreach ($intervals as $minutes) {
            $key = 'alegra_connector_' . $minutes . 'min';
            if (!isset($schedules[$key])) {
                $schedules[$key] = [
                    'interval' => $minutes * MINUTE_IN_SECONDS,
                    'display' => sprintf(
                        /* translators: %d: number of minutes */
                        esc_html__('Every %d minutes (Alegra Connector)', 'alegra-connector'),
                        $minutes
                    ),
                ];
            }
        }
        return $schedules;
    }

    /**
     * Plugins loaded hook
     */
    public function on_plugins_loaded(): void
    {
        // Logger first - everyone depends on it
        $this->init_logger();

        // API client next
        $this->init_api();

        // Sync controller
        $this->init_sync();

        // Public facing (for REST API + WooCommerce hooks) - needed for all requests
        $this->init_public();

        // Admin only when in admin context
        if (is_admin()) {
            $this->init_admin();
        }
    }

    /**
     * Load plugin textdomain for internationalization
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'alegra-connector',
            false,
            dirname(ALEGRA_CONNECTOR_BASENAME) . '/languages/'
        );
    }

    /**
     * Initialize admin components
     */
    private function init_admin(): void
    {
        $this->admin = new Admin\Admin_Dashboard($this->api, $this->logger);
    }

    /**
     * Initialize public facing components
     */
    private function init_public(): void
    {
        $this->public = new Public\Public_($this->api, $this->logger);
        $this->init_webhooks();
    }

    /**
     * Initialize webhook receiver
     */
    private function init_webhooks(): void
    {
        $receiver = new Webhooks\Receiver($this->api, $this->logger);
        $receiver->register_route();
    }

    /**
     * Initialize logger
     */
    private function init_logger(): void
    {
        $this->logger = new Logger\Logger();
    }

    /**
     * Initialize API client
     */
    private function init_api(): void
    {
        $this->api = new API\Client($this->logger);
    }

    /**
     * Initialize sync controller
     */
    private function init_sync(): void
    {
        $this->sync_controller = new Sync\Controller($this->api, $this->logger);
        $this->sync_controller->register_cron_hook();
    }

    /**
     * Plugin activation
     */
    public function activate(): void
    {
        // Check WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(ALEGRA_CONNECTOR_BASENAME);
            wp_die(
                esc_html__('Alegra Connector requiere WooCommerce instalado y activo.', 'alegra-connector'),
                esc_html__('Error de Activacion', 'alegra-connector'),
                ['back_link' => true]
            );
        }

        // Set default options
        $defaults = [
            'alegra_connector_version' => ALEGRA_CONNECTOR_VERSION,
            'alegra_connector_sync_frequency' => 15,
            'alegra_connector_sync_method' => 'both',
            'alegra_connector_push_orders_enabled' => true,
            'alegra_connector_push_products_enabled' => false,
            'alegra_connector_sync_products' => false,
            'alegra_connector_sync_customers' => false,
            'alegra_connector_sync_orders' => false,
            'alegra_connector_sync_categories' => false,
            'alegra_connector_sync_images' => true,
            'alegra_connector_sync_images_mode' => 'favorite',
            'alegra_connector_sync_inactive_products' => false,
            'alegra_connector_currency' => 'COP',
            'alegra_connector_log_retention_days' => 30,
            'alegra_connector_conflict_resolution' => 'alegra_wins',
            'alegra_connector_inventory_source' => 'alegra',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Schedule cron on activation
        $this->schedule_cron();

        // Log activation
        if ($this->logger) {
            $this->logger->info('Plugin activated successfully');
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     *
     * Exhaustive cleanup: kill switch + ALL transients + ALL cron schedules + REST routes.
     * Preserves user options (credentials, mappings, logs) for reactivation.
     */
    public function deactivate(): void
    {
        // 1. Activate kill switch so any in-flight request stops
        Kill_Switch::activate('plugin_deactivated');

        // 2. Clear ALL alegra_* transients (1 query, efficient)
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_alegra\\_%'
             OR option_name LIKE '_transient_timeout_alegra\\_%'
             ESCAPE '\\\\'"
        );

        // 3. Clear ALL cron events with alegra prefix
        $cron_hooks = ['alegra_connector_cron_sync', 'alegra_connector_process_webhook'];
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // 4. Count cleaned items for deactivation summary
        $cleaned_count = [
            'transients' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_alegra\\_%' ESCAPE '\\\\'"
            ),
            'cron_jobs' => count($cron_hooks),
            'timestamp' => current_time('mysql'),
        ];
        set_transient('alegra_deactivation_summary', $cleaned_count, 300);

        // 5. Log deactivation
        if ($this->logger) {
            $this->logger->info('Plugin deactivated', $cleaned_count);
        }

        flush_rewrite_rules();
    }

    /**
     * Schedule cron for periodic sync
     */
    public function schedule_cron(): void
    {
        $frequency = (int) get_option('alegra_connector_sync_frequency', 15);
        $allowed = [5, 15, 30, 60];
        if (!in_array($frequency, $allowed, true)) {
            $frequency = 15;
        }
        $hook = 'alegra_connector_cron_sync';
        $schedule = 'alegra_connector_' . $frequency . 'min';

        // Clear any previously-scheduled events to avoid stale schedules
        wp_clear_scheduled_hook($hook);

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + 60, $schedule, $hook);
        }
    }

    /**
     * Get API client instance
     */
    public function get_api(): API\Client
    {
        return $this->api;
    }

    /**
     * Get logger instance
     */
    public function get_logger(): Logger\Logger
    {
        return $this->logger;
    }

    /**
     * Get sync controller instance
     */
    public function get_sync_controller(): Sync\Controller
    {
        return $this->sync_controller;
    }

    /**
     * Get admin dashboard instance
     */
    public function get_admin(): Admin\Admin_Dashboard
    {
        return $this->admin;
    }
}

// Initialize plugin
function alegra_connector(): Alegra_Connector
{
    return Alegra_Connector::get_instance();
}

// Start the plugin
alegra_connector();