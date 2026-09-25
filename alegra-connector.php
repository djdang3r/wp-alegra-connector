<?php
/**
 * Plugin Name: Alegra Connector
 * Plugin URI: https://github.com/djdang3r/wp-alegra-connector
 * Description: WooCommerce - Alegra integration plugin for bidirectional synchronization of products, customers, orders, and categories.
 * Version: 2.5.1
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

/**
 * Read the plugin's `Version` header.
 *
 * Single source of truth for the plugin version: the constant below is derived
 * from this header so the two can never drift again. The stale constant
 * (header 2.3.10 vs constant 2.3.7) made every upgrade serve cached JS/CSS, so
 * new features never loaded until the cache was busted by hand.
 *
 * @param string $file Absolute path to the main plugin file.
 * @return string Version string, or '' when the header cannot be read.
 */
function alegra_connector_plugin_version(string $file): string
{
    if (function_exists('get_file_data')) {
        $data = get_file_data($file, ['Version' => 'Version']);
        $version = trim((string) ($data['Version'] ?? ''));
        if ($version !== '') {
            return $version;
        }
    }

    // Safe fallback: parse the header directly when get_file_data() is absent
    // (e.g. the dependency-free execution-test harness).
    if (preg_match('/^[ \t\/*#@]*Version:\s*(.+?)\s*$/mi', (string) @file_get_contents($file), $m)) {
        return trim($m[1]);
    }

    return '';
}

// Plugin constants
define('ALEGRA_CONNECTOR_VERSION', alegra_connector_plugin_version(__FILE__) ?: '0.0.0');
define('ALEGRA_CONNECTOR_PATH', plugin_dir_path(__FILE__));
define('ALEGRA_CONNECTOR_URL', plugin_dir_url(__FILE__));
define('ALEGRA_CONNECTOR_BASENAME', plugin_basename(__FILE__));
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

        // Self-heal the periodic schedule if it is ever missing (old "Run now"/
        // "skip" destroyed it; a plugin conflict or manual DB edit can too).
        add_action('init', [$this, 'maybe_self_heal_cron'], 20);
        // Same self-heal for the hourly payment retry sweep.
        add_action('init', [$this, 'maybe_self_heal_payment_reconcile'], 21);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // DB migrations on plugins_loaded (idempotent via dbDelta)
        add_action('plugins_loaded', [\Alegra\Connector\Schema::class, 'migrate'], 5);

        // Option migration (REQ-CFG-2/4). Idempotent via a version guard; seeds
        // push_customers_enabled from push_products_enabled on upgrade so the
        // Phase-1 regression (absent -> false) cannot silently stop the
        // automatic customer pushes an existing install already relied on.
        add_action('plugins_loaded', [\Alegra\Connector\Write_Gate::class, 'maybe_migrate'], 5);

        // NOTE: Checkout_Integration::register() and State_Sync::register_hooks()
        // are wired from on_plugins_loaded() AFTER the WooCommerce guard (AC-17),
        // so they are never registered when WooCommerce is inactive.

        // Tombstone hook: track products deleted in WC
        add_action('before_delete_post', [\Alegra\Connector\Tombstone_Manager::class, 'on_post_delete']);

        // Self-check fallback: if the critical Logger class is still not
        // resolvable after everything else has loaded (typical when the
        // lowercase `logger/` directory was missing from the release ZIP),
        // surface a clear Spanish admin notice. Replaces the 2.1.7 behavior
        // of crashing with a fatal.
        //
        // AC-53: this MUST NOT auto-deactivate. A missing class is a
        // deployment problem the administrator has to see and fix; silently
        // disabling a fiscal integration because of a false positive (an
        // autoloader ordering issue, a transient file-permission glitch)
        // removes the store's invoicing without warning and re-runs on every
        // request until it sticks. Notice only.
        //
        // class_exists() is called with the default $autoload = true so the
        // registered autoloader gets a chance to load the class. With
        // $autoload = false the check reported a false negative for a class
        // that WOULD load, which is exactly what could trigger the old
        // deactivation.
        //
        // Priority 999 — runs after every other plugins_loaded listener.
        add_action('plugins_loaded', function (): void {
            if (class_exists(\Alegra\Connector\Logger\Logger::class)) {
                return;
            }
            add_action('admin_notices', static function (): void {
                if (!current_user_can('activate_plugins')) {
                    return;
                }
                echo '<div class="notice notice-error"><p>';
                echo esc_html__(
                    'Alegra Connector: no se pudo cargar la clase Logger. El directorio logger/ parece estar incompleto en esta instalación. El plugin no puede registrar eventos hasta que reinstales el plugin completo o contactes al soporte.',
                    'alegra-connector'
                );
                echo '</p></div>';
            });
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
        // Logger first - everyone depends on it. It has no WooCommerce
        // dependency, so it must load even when WC is missing (otherwise the
        // priority-999 self-check below would wrongly deactivate the plugin).
        $this->init_logger();

        // REQ-LOG-06: surface a logger write failure to the admin. Registered
        // before the WooCommerce guard, like the logger itself, so the notice
        // appears even when WC is missing.
        add_action('admin_notices', [Logger\Logger::class, 'render_write_failure_notice']);

        // Daily retention pruning + entity-map reconciliation (AC-22/AC-60).
        // No WooCommerce dependency; always registered.
        Maintenance::register_hooks();

        // AC-17: hard guard. This plugin loads before WooCommerce (alphabetical),
        // so if WC is missing every `wc_*`/`WC_*` call in templates and AJAX
        // handlers would fatal (white screen / HTTP 500). Bail out early with a
        // notice and load no sync/admin components.
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__(
                    'Alegra Connector requiere WooCommerce activo. La sincronización está desactivada hasta que actives WooCommerce.',
                    'alegra-connector'
                );
                echo '</p></div>';
            });
            return;
        }

        // API client next
        $this->init_api();

        // Sync controller
        $this->init_sync();

        // Billing fields + checkout integration (2.3.0)
        \Alegra\Connector\Checkout_Integration::register();

        // State sync: refunds, profile updates, payment method changes (2.3.0)
        \Alegra\Connector\State_Sync::register_hooks();

        // Consumidor Final cache invalidation on settings change (AC-25)
        \Alegra\Connector\Consumidor_Final::register_invalidation_hooks();

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
            'alegra_connector_sync_method' => 'cron',
            'alegra_connector_push_orders_enabled' => false,
            'alegra_connector_push_products_enabled' => false,
            // Independent customer toggle (REQ-CFG-4). Fresh installs default to
            // false; the upgrade migration seeds it from push_products_enabled
            // so an existing install that relied on the shared hook keeps
            // pushing customers.
            'alegra_connector_push_customers_enabled' => false,
            // Controllable payment sweep (REQ-CFG-1). Default true preserves the
            // hourly retry sweep on an install that never toggled it.
            'alegra_connector_payment_reconcile_enabled' => true,
            'alegra_connector_payment_reconcile_batch' => 20,
            'alegra_connector_sync_products' => false,
            'alegra_connector_sync_customers' => false,
            'alegra_connector_sync_orders' => false,
            'alegra_connector_sync_categories' => false,
            'alegra_connector_sync_images' => true,
            'alegra_connector_sync_images_mode' => 'favorite',
            'alegra_connector_sync_inactive_products' => false,
            'alegra_connector_currency' => 'COP',
            'alegra_connector_log_retention_days' => 30,
            // T3.1.a: wall-clock budget for one chunked page (Traer desde Alegra).
            'alegra_connector_chunked_page_budget' => 20,
            'alegra_connector_allowed_image_hosts_extra' => [],
            // 2.5.1: permissive by default so images always download. ON enforces
            // the allowlist above (plus the always-on SSRF guard).
            'alegra_connector_restrict_image_hosts' => false,
            'alegra_connector_conflict_resolution' => 'alegra_wins',
            'alegra_connector_inventory_source' => 'alegra',
            // Independent gate for the Alegra -> WC stock pull. It no longer
            // rides on sync_products: a merchant who uses Alegra as the
            // inventory source but does not import products still gets stock.
            'alegra_connector_inventory_sync_enabled' => true,
            'alegra_connector_customer_resolution_mode' => 'auto',
            'alegra_connector_invoice_status' => 'draft',
            'alegra_connector_payment_account_id' => '',
            'alegra_connector_dry_run' => false,
            // Webhook event selection. All 12 documented events are registered
            // by default; the merchant can narrow the list in the Avanzado tab.
            'alegra_connector_webhook_selected_events' => \Alegra\Connector\API\Client::get_webhook_events(),
        ];

        // AC-81: options that are only ever read in admin/cron context must not
        // be pulled into `alloptions` on every frontend request. Options read by
        // the frontend (push flags, dry-run, currency, API credentials, billing
        // catalog, company country) keep the default autoload.
        $non_autoload = [
            'alegra_connector_version',
            'alegra_connector_sync_frequency',
            'alegra_connector_sync_products',
            'alegra_connector_sync_customers',
            'alegra_connector_sync_orders',
            'alegra_connector_sync_categories',
            'alegra_connector_inventory_sync_enabled',
            'alegra_connector_log_retention_days',
            'alegra_connector_sync_inactive_products',
            // Only read by the chunked import handler (admin/AJAX).
            'alegra_connector_chunked_page_budget',
            // Only read while importing product images (admin/cron).
            'alegra_connector_allowed_image_hosts_extra',
            'alegra_connector_restrict_image_hosts',
            // Only read in admin/cron context (REQ-CFG-1).
            'alegra_connector_payment_reconcile_enabled',
            'alegra_connector_payment_reconcile_batch',
            // Migration guard: never read on the frontend.
            'alegra_connector_gate_migration_version',
            // Only read in admin/REST context (webhook registration/receiver).
            'alegra_connector_webhook_selected_events',
            'alegra_connector_webhook_events_migration_version',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value, '', in_array($key, $non_autoload, true) ? 'no' : 'yes');
            }
        }

        // Seed the gate options the defaults loop above cannot cover (the
        // migration version) and preserve an existing install's push choice
        // (REQ-CFG-4). Idempotent.
        \Alegra\Connector\Write_Gate::maybe_migrate();

        // Create/upgrade the schema immediately on activation (AC-06). The
        // plugins_loaded migration hook has already fired by the time an
        // activation hook runs, so without this the tables would only be
        // created on the next request.
        Schema::migrate();

        // Schedule cron on activation
        $this->schedule_cron();
        Maintenance::schedule();
        $this->maybe_self_heal_payment_reconcile();

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
        // 1. Activate kill switch so any in-flight request stops.
        //    The kill switch is an OPTION (see Kill_Switch), so the transient
        //    sweep below cannot wipe it.
        Kill_Switch::activate('plugin_deactivated');

        // 2. Count THEN clear ALL alegra_* transients (1 query, efficient).
        //    Count first — otherwise it is always 0 after the DELETE.
        global $wpdb;
        $cleaned_transients = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_alegra\\_%' ESCAPE '\\\\'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_alegra\\_%'
             OR option_name LIKE '_transient_timeout_alegra\\_%'
             ESCAPE '\\\\'"
        );

        // 3. Clear ALL cron events with alegra prefix
        $cron_hooks = ['alegra_connector_cron_sync', Maintenance::CRON_HOOK, 'alegra_connector_payment_reconcile'];
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // 4. Build deactivation summary from the pre-delete counts
        $cleaned_count = [
            'transients' => $cleaned_transients,
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

        // The merchant is actively (re)configuring the schedule, so lift the
        // explicit-stop flag set by the Monitor's "remove all" action.
        delete_option('alegra_connector_cron_disabled');

        // Clear any previously-scheduled events to avoid stale schedules
        wp_clear_scheduled_hook($hook);

        // `sync_method` controls the INBOUND (Alegra → WooCommerce) sync only.
        // 'real-time' is webhooks-only and 'disabled' means nothing automatic,
        // so neither schedules the periodic cron. Outbound order uploads are
        // governed by `alegra_connector_push_orders_enabled`, never by this.
        $sync_method = (string) get_option('alegra_connector_sync_method', 'cron');
        if (in_array($sync_method, ['cron', 'both'], true) && !wp_next_scheduled($hook)) {
            wp_schedule_event(time() + 60, $schedule, $hook);
        }

        // Keep the daily maintenance event present (AC-22).
        Maintenance::schedule();
    }

    /**
     * Self-heal the periodic sync schedule.
     *
     * Restores the recurring event if it is missing while the merchant expects
     * a periodic pull (sync_method = cron|both). Safety net for any code path
     * that clears the hook (the old destructive "Run now"/"skip", a plugin
     * conflict, or a manual DB edit).
     *
     * Cost: wp_next_scheduled() calls _get_cron_array() -> the core `cron`
     * option, and `cron` is autoloaded, so it is already in the alloptions cache loaded
     * on every request — no extra DB query. The schedule is only written when
     * it is actually missing, and never after the merchant explicitly removed
     * it from the Monitor ("remove all" sets alegra_connector_cron_disabled).
     */
    public function maybe_self_heal_cron(): void
    {
        $hook = 'alegra_connector_cron_sync';
        if (wp_next_scheduled($hook) !== false) {
            return;
        }

        if (get_option('alegra_connector_cron_disabled', false)) {
            return;
        }

        $sync_method = (string) get_option('alegra_connector_sync_method', 'cron');
        if (!in_array($sync_method, ['cron', 'both'], true)) {
            return;
        }

        $frequency = (int) get_option('alegra_connector_sync_frequency', 15);
        if (!in_array($frequency, [5, 15, 30, 60], true)) {
            $frequency = 15;
        }

        wp_schedule_event(time() + 60, 'alegra_connector_' . $frequency . 'min', $hook);
    }

    /**
     * Self-heal the hourly payment reconciliation schedule (REQ-REC-4).
     *
     * Safety net for a missing event (a fresh install, a plugin conflict or a
     * manual DB edit). The sweep enforces the kill switch, the global lock and
     * the cancellation transient itself, so scheduling it unconditionally is
     * safe; disabling it is done through `alegra_connector_payment_reconcile_enabled`.
     */
    public function maybe_self_heal_payment_reconcile(): void
    {
        $hook = 'alegra_connector_payment_reconcile';
        if (wp_next_scheduled($hook) !== false) {
            return;
        }

        wp_schedule_event(time() + 300, 'hourly', $hook);
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