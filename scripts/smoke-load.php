<?php
/**
 * Smoke-test loader for Alegra Connector.
 *
 * Bootstraps a minimal, portable set of WordPress stubs (no dependency on the
 * untracked temp_pkg/ harness) and loads the plugin to assert the critical
 * autoloader, Logger, billing and 2.3.0 invoicing paths.
 *
 * Used by scripts/smoke-test.sh. Exits non-zero if any assertion fails.
 *
 * Usage:  php scripts/smoke-load.php
 */

declare(strict_types=1);

// Resolve the plugin root from the env var set by smoke-test.sh, falling back
// to <plugin_root>/scripts/ when run directly.
$plugin_root = getenv('ALEGRA_PLUGIN_ROOT');
if ($plugin_root === false || $plugin_root === '') {
    $plugin_root = dirname(__DIR__);
}
$plugin_root = rtrim($plugin_root, '/\\') . DIRECTORY_SEPARATOR;

if (!is_file($plugin_root . 'alegra-connector.php')) {
    fwrite(STDERR, "FATAL: alegra-connector.php not found at $plugin_root\n");
    exit(10);
}

$failures = [];

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  PASS  $name\n";
    } else {
        echo "  FAIL  $name  $detail\n";
        $failures[] = $name;
    }
}

function file_source(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function strip_php_comments(string $src): string
{
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    return (string) preg_replace('![ \t]*//.*$!m', '', (string) $src);
}

/**
 * Extract the verbatim source of a method via reflection. Works for private
 * methods too, so signature/body assertions do not need a live instance.
 */
function method_source(string $class, string $method): string
{
    if (!class_exists($class)) {
        return '';
    }
    try {
        $ref = new ReflectionMethod($class, $method);
    } catch (\Throwable $e) {
        return '';
    }
    $file = $ref->getFileName();
    if ($file === false || !is_file($file)) {
        return '';
    }
    $lines = file($file);
    if (!is_array($lines)) {
        return '';
    }
    $length = $ref->getEndLine() - $ref->getStartLine() + 1;
    return implode('', array_slice($lines, $ref->getStartLine() - 1, $length));
}

echo "=== Alegra Connector smoke-test ===\n";
echo "Plugin root: $plugin_root\n";
echo "PHP:         " . PHP_VERSION . "\n\n";

// ---- Assertion 1: explicit require_once of Logger must NOT be present ----
echo "[1] Refactor check: explicit require_once of logger/Logger/Logger.php is GONE\n";
$main_src = file_source($plugin_root . 'alegra-connector.php');
$main_src_stripped = strip_php_comments($main_src);
$has_fragile_require = (bool) preg_match(
    '/require_once\s+__DIR__\s*\.\s*[\'"]\s*\/?logger\/Logger\/Logger\.php\s*[\'"]\s*;/',
    $main_src_stripped
);
check(
    'no fragile require_once of logger/Logger/Logger.php',
    !$has_fragile_require,
    '— the fragile explicit require_once that broke 2.1.7 must be removed (historical mentions in comments are OK)'
);

// ---- Bootstrap: portable WordPress stubs ----
echo "\n[2] Loading plugin with mocked WP (portable stubs)...\n";

if (!defined('ABSPATH')) {
    define('ABSPATH', $plugin_root);
}
// Constants referenced from class-constant declarations at autoload time.
foreach (['HOUR_IN_SECONDS' => 3600, 'MINUTE_IN_SECONDS' => 60, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'WP_DEBUG' => true] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

// In-memory option + transient stores so behavioural assertions can drive them.
$GLOBALS['alegra_smoke_options'] = [];
$GLOBALS['alegra_smoke_transients'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
function register_activation_hook($file, $callback) { return; }
function register_deactivation_hook($file, $callback) { return; }
function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file) { return 'http://localhost/wp-content/plugins/alegra-connector/'; }
function plugin_basename($file) { return 'alegra-connector/' . basename($file); }
function load_plugin_textdomain($domain, $deprecated = false, $path = false) { return true; }
function is_admin() { return false; }
function current_time($type = 'mysql', $gmt = 0) { return date('Y-m-d H:i:s'); }
function wp_json_encode($data, $flags = 0, $depth = 512) { return (string) json_encode($data, $flags, $depth); }
function wp_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/alegra-smoke', 'path' => sys_get_temp_dir() . '/alegra-smoke']; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0777, true); }
function esc_html__($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return $text; }
function wp_die($message = '', $title = '', $args = []) { return; }
function deactivate_plugins($plugin) { return; }
function flush_rewrite_rules($hard = true) { return; }
function wp_clear_scheduled_hook($hook) { return true; }
function wp_schedule_event($timestamp, $recurrence, $hook) { return true; }
function wp_next_scheduled($hook) { return false; }

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['alegra_smoke_options'])
        ? $GLOBALS['alegra_smoke_options'][$name]
        : $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['alegra_smoke_options'][$name] = $value;
    return true;
}
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (!array_key_exists($name, $GLOBALS['alegra_smoke_options'])) {
        $GLOBALS['alegra_smoke_options'][$name] = $value;
    }
    return true;
}
function delete_option($name)
{
    unset($GLOBALS['alegra_smoke_options'][$name]);
    return true;
}
function get_transient($name)
{
    return $GLOBALS['alegra_smoke_transients'][$name] ?? false;
}
function set_transient($name, $value, $expiration = 0)
{
    $GLOBALS['alegra_smoke_transients'][$name] = $value;
    return true;
}
function delete_transient($name)
{
    unset($GLOBALS['alegra_smoke_transients'][$name]);
    return true;
}

require $plugin_root . 'alegra-connector.php';

check(
    'plugin loads without fatals (singleton instantiated)',
    class_exists('Alegra\\Connector\\Alegra_Connector'),
    '— the plugin bootstrap must survive on a mocked WP environment'
);

// ---- Assertion 3: critical Logger class is reachable via autoloader ----
echo "\n[3] Autoloader reachability: Alegra\\Connector\\Logger\\Logger\n";
$logger_class_exists = class_exists('Alegra\\Connector\\Logger\\Logger', false);
if (!$logger_class_exists) {
    // Force autoload
    $logger_class_exists = class_exists('Alegra\\Connector\\Logger\\Logger');
}
check(
    'Alegra\\Connector\\Logger\\Logger is loaded',
    $logger_class_exists,
    '— the autoloader MUST resolve this class without an explicit require_once'
);

// ---- Assertion 4: critical main class is reachable ----
echo "\n[4] Autoloader reachability: Alegra\\Connector\\Alegra_Connector\n";
$main_class_exists = class_exists('Alegra\\Connector\\Alegra_Connector');
check(
    'Alegra\\Connector\\Alegra_Connector is loaded',
    $main_class_exists,
    '— the singleton must instantiate'
);

// ---- Assertion 5: regression — Logger file actually exists on disk ----
echo "\n[5] Regression check: logger/Logger/Logger.php exists on disk\n";
check(
    'logger/Logger/Logger.php is present',
    is_file($plugin_root . 'logger/Logger/Logger.php'),
    '— this is the file the 2.1.7 activation fatal could not find'
);

// ---- Assertion 6: regression — logger directory structure intact ----
echo "\n[6] Regression check: logger/ directory structure\n";
$dirs_ok = is_dir($plugin_root . 'logger')
    && is_dir($plugin_root . 'logger/Logger')
    && is_file($plugin_root . 'logger/Logger/Logger.php')
    && is_file($plugin_root . 'logger/Logger/index.php')
    && is_file($plugin_root . 'logger/index.php');
check(
    'logger/ tree complete (index.php placeholders + Logger.php)',
    $dirs_ok,
    '— every required file in the logger/ tree must be present'
);

// ---- Assertion 7: token-log leak regression (2.1.9) ----
echo "\n[7] Regression check: error_log in get_auth_header() must be REMOVED\n";
$api_src = file_source($plugin_root . 'includes/API/Client.php');
$api_stripped = strip_php_comments($api_src);
$has_error_log_in_auth = (bool) preg_match(
    '/function\s+get_auth_header\s*\([^)]*\)\s*:\s*string\s*\{[^}]*error_log/s',
    $api_stripped
);
check(
    'no error_log inside get_auth_header() body',
    !$has_error_log_in_auth,
    '— the error_log that leaked token length must be removed (regression guard)'
);

// ---- Assertion 8: webhook delete-item tombstone regression (2.1.9) ----
echo "\n[8] Regression check: handle_delete_item() must NOT call delete_post_meta for _alegra_item_id\n";
$hooks_src = file_source($plugin_root . 'includes/Webhooks/Handlers.php');
preg_match(
    '/function\s+handle_delete_item\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s',
    $hooks_src,
    $matches
);
$handle_delete_body = $matches[1] ?? '';
$has_meta_delete = (bool) preg_match(
    "/delete_post_meta\s*\([^)]*_alegra_item_id/s",
    $handle_delete_body
);
check(
    'handle_delete_item() writes tombstone instead of deleting _alegra_item_id meta',
    !$has_meta_delete,
    '— the webhook handler must use Tombstone_Manager::create() (regression guard)'
);

// ---- Assertion 9: customers pagination regression (2.2.0) ----
echo "\n[9] Regression check: Customers::sync_all() must NOT use 'number' => -1\n";
$customers_src = file_source($plugin_root . 'includes/Sync/Customers.php');
$customers_stripped = strip_php_comments($customers_src);
$has_unbounded_fetch = (bool) preg_match(
    "/sync_all\s*\([^)]*\)\s*:[^{]*\{[^}]*'number'\s*=>\s*-1/s",
    $customers_stripped
);
check(
    'Customers::sync_all() paginates instead of fetching all customers',
    !$has_unbounded_fetch,
    '— must use number + paged to avoid OOM on large stores (regression guard)'
);

// ---- Assertion 10: LOCK_NB regression (2.2.0) ----
echo "\n[10] Regression check: LOCK_NB must NOT be used in Logger::write()\n";
$logger_src = file_source($plugin_root . 'logger/Logger/Logger.php');
$has_lock_nb = (bool) preg_match('/flock\s*\(\s*\$handle\s*,\s*LOCK_EX\s*\|\s*LOCK_NB\s*\)/', $logger_src);
check(
    'logger uses LOCK_EX (blocking), not LOCK_NB',
    !$has_lock_nb,
    '— non-blocking flock silently drops logs under contention (regression guard)'
);

// ---- Assertion 11: uninstall.php log cleanup (2.2.0) ----
echo "\n[11] Regression check: uninstall.php must clean up log directory\n";
$uninstall_src = file_source($plugin_root . 'uninstall.php');
$has_log_cleanup = (bool) preg_match(
    "/alegra-logs|'alegra-logs'|rmdir_recursive\s*\(\s*\\\$log_dir/s",
    $uninstall_src
);
check(
    'uninstall.php removes wp-content/uploads/alegra-logs/',
    $has_log_cleanup,
    '— log directory must be cleaned on plugin uninstall (privacy + disk space)'
);

// ---- Assertion 12: rate-limit transient autoload (2.2.0) ----
echo "\n[12] Regression check: rate-limit transient must use autoload='no'\n";
$api_src_2 = file_source($plugin_root . 'includes/API/Client.php');
$has_autoload_no = (bool) preg_match(
    "/set_transient\s*\(\s*'alegra_connector_rate_limit'\s*,\s*\\\$rate_limit\s*\+\s*1\s*,\s*60\s*,\s*'no'\s*\)/",
    $api_src_2
);
check(
    'rate-limit transient uses autoload=no (4th arg)',
    $has_autoload_no,
    '— operational transients must not bloat wp_options autoload cache'
);

// =====================================================================
// 2.3.0 coverage
// =====================================================================

// ---- 13: Billing_Fields class + catalog ----
echo "\n[13] Billing_Fields class + 11-field catalog\n";
$billing_class = 'Alegra\\Connector\\Billing_Fields';
$billing_ok = class_exists($billing_class);
check('Billing_Fields class loads', $billing_ok, '— the billing catalog holder must be autoloadable');
$catalog = $billing_ok ? $billing_class::CATALOG : [];
check(
    'catalog has exactly 11 fields',
    count($catalog) === 11,
    '— expected 11 (got ' . count($catalog) . ')'
);

// ---- 14: groups partition the catalog ----
echo "\n[14] Groups A/B/C partition the catalog\n";
$group_counts = ['A' => 0, 'B' => 0, 'C' => 0];
$unknown_group = [];
foreach ($catalog as $key => $field) {
    $g = (string) ($field['group'] ?? '');
    if (!isset($group_counts[$g])) {
        $unknown_group[] = $key . ':' . $g;
        continue;
    }
    $group_counts[$g]++;
}
check(
    'every field belongs to a known group and counts sum to 11 (A=5, B=3, C=3)',
    $unknown_group === [] && $group_counts['A'] === 5 && $group_counts['B'] === 3 && $group_counts['C'] === 3,
    '— unknown: ' . implode(', ', $unknown_group) . '; counts: ' . json_encode($group_counts)
);

// ---- 15: is_field_enabled reads the catalog option ----
echo "\n[15] Billing_Fields::is_field_enabled() reads alegra_connector_billing_field_catalog_enabled\n";
update_option('alegra_connector_billing_field_catalog_enabled', ['idtype' => 1]);
$enabled_read = class_exists($billing_class) && $billing_class::is_field_enabled('idtype') === true;
$disabled_read = class_exists($billing_class) && $billing_class::is_field_enabled('mobile') === false;
update_option('alegra_connector_billing_field_catalog_enabled', []);
check(
    'enabled field returns true and unset field returns false',
    $enabled_read && $disabled_read,
    '— the option key is alegra_connector_billing_field_catalog_enabled'
);

// ---- 16: Consumidor_Final class + verified constants ----
echo "\n[16] Consumidor_Final class + 5 verified constants\n";
$cf_class = 'Alegra\\Connector\\Consumidor_Final';
$cf_ok = class_exists($cf_class);
check('Consumidor_Final class loads', $cf_ok, '— fallback contact resolver must be autoloadable');
$cf_consts_ok = $cf_ok
    && $cf_class::IDENTIFICATION === '222222222222'
    && $cf_class::IDENTIFICATION_TYPE === 'CC'
    && $cf_class::KIND_OF_PERSON === 'PERSON_ENTITY'
    && $cf_class::REGIME === 'SIMPLIFIED_REGIME'
    && $cf_class::NAME === 'Consumidor Final';
check(
    'constants match CC / 222222222222 / PERSON_ENTITY / SIMPLIFIED_REGIME / Consumidor Final',
    $cf_consts_ok,
    '— the verified Consumidor Final payload must not drift'
);

// ---- 17: State_Sync class + register_hooks callable ----
echo "\n[17] State_Sync class + register_hooks()\n";
$ss_class = 'Alegra\\Connector\\State_Sync';
check(
    'State_Sync loads and register_hooks() is callable',
    class_exists($ss_class) && is_callable([$ss_class, 'register_hooks']),
    '— refunds/profile/payment-method hooks must be wired'
);

// ---- 18: Checkout_Integration class + detect() + schema path ----
echo "\n[18] Checkout_Integration class + detect() + condition schema path\n";
$ci_class = 'Alegra\\Connector\\Checkout_Integration';
check(
    'Checkout_Integration loads and detect() is callable',
    class_exists($ci_class) && is_callable([$ci_class, 'detect']),
    '— Blocks/legacy detection entry point must exist'
);
$condition_src = method_source($ci_class, 'condition_for');
$schema_path_ok = $condition_src !== ''
    && str_contains($condition_src, "'customer'")
    && str_contains($condition_src, "'address'")
    && !str_contains($condition_src, 'additional_fields');
check(
    'condition_for() resolves under customer.address, not checkout.additional_fields',
    $schema_path_ok,
    '— address-location conditionals never match checkout.additional_fields'
);

// ---- 19: Orders::ensure_customer_synced signature ----
echo "\n[19] Orders::ensure_customer_synced() accepts a WC_Order\n";
$orders_class = 'Alegra\\Connector\\Sync\\Orders';
$orders_ok = class_exists($orders_class);
$ensure_sig_ok = false;
if ($orders_ok) {
    try {
        $m = new ReflectionMethod($orders_class, 'ensure_customer_synced');
        $params = $m->getParameters();
        $type = $params[0]->getType() ?? null;
        $ensure_sig_ok = $type instanceof ReflectionNamedType && $type->getName() === 'WC_Order';
    } catch (\Throwable $e) {
        $ensure_sig_ok = false;
    }
}
check(
    'ensure_customer_synced() exists and its first parameter is typed WC_Order',
    $ensure_sig_ok,
    '— order-based (HPOS-safe) signature is required'
);

// ---- 20: build_client_data returns only an id ----
echo "\n[20] Orders::build_client_data() returns ONLY an id\n";
$build_src = method_source($orders_class, 'build_client_data');
$build_ok = $build_src !== ''
    && (bool) preg_match("/return\s*\[\s*'id'\s*=>\s*\\\$customer_alegra_id\s*\]/", $build_src)
    && !str_contains($build_src, 'identification')
    && !str_contains($build_src, 'kindOfPerson');
check(
    'build_client_data() emits only [\'id\' => ...] and never inline client data',
    $build_ok,
    '— invoices must reference the synced contact id, not duplicate the payload'
);

// ---- 21: create_credit_note_for_refund arity ----
echo "\n[21] Orders::create_credit_note_for_refund() arity\n";
$cn_arity_ok = false;
if ($orders_ok) {
    try {
        $m = new ReflectionMethod($orders_class, 'create_credit_note_for_refund');
        $cn_arity_ok = $m->getNumberOfParameters() === 4 && $m->getNumberOfRequiredParameters() === 2;
    } catch (\Throwable $e) {
        $cn_arity_ok = false;
    }
}
check(
    'create_credit_note_for_refund(int, float, string =, int =) — 4 params / 2 required',
    $cn_arity_ok,
    '— refund idempotency relies on the 4th argument'
);

// ---- 22: Schema migration method ----
echo "\n[22] Schema::maybe_migrate_alegra_id_columns()\n";
$schema_class = 'Alegra\\Connector\\Schema';
check(
    'Schema::maybe_migrate_alegra_id_columns() exists',
    class_exists($schema_class) && method_exists($schema_class, 'maybe_migrate_alegra_id_columns'),
    '— UUID migration entry point must exist'
);

// ---- 23: alegra_id columns are VARCHAR(36) ----
echo "\n[23] alegra_id columns declared VARCHAR(36) in the DDL\n";
$schema_src = file_source($plugin_root . 'includes/Schema.php');
$varchar_count = preg_match_all('/alegra_id\s+VARCHAR\(36\)/', $schema_src);
check(
    'at least 4 alegra_id columns are VARCHAR(36)',
    $varchar_count >= 4,
    '— tombstones/pull_queue/push_log/entity_map must hold UUIDs (found ' . $varchar_count . ')'
);

// ---- 24: get_preferred_number_template returns ?string ----
echo "\n[24] Orders::get_preferred_number_template() returns ?string\n";
$template_ret_ok = false;
if ($orders_ok) {
    try {
        $type = (new ReflectionMethod($orders_class, 'get_preferred_number_template'))->getReturnType();
        $template_ret_ok = $type instanceof ReflectionNamedType
            && $type->getName() === 'string'
            && $type->allowsNull();
    } catch (\Throwable $e) {
        $template_ret_ok = false;
    }
}
check(
    'get_preferred_number_template() is typed ?string',
    $template_ret_ok,
    '— no template must be representable as null'
);

// ---- 25: the 3 new options are registered ----
echo "\n[25] The 3 new options are registered\n";
$admin_src = file_source($plugin_root . 'admin/Admin/Admin_Dashboard.php');
$opts = ['alegra_connector_customer_resolution_mode', 'alegra_connector_stamp_enabled', 'alegra_connector_dry_run'];
$missing_opts = [];
foreach ($opts as $opt) {
    if (!str_contains($admin_src, "register_setting('alegra_connector_settings', '$opt'")) {
        $missing_opts[] = $opt;
    }
}
check(
    'customer_resolution_mode, stamp_enabled and dry_run are registered',
    $missing_opts === [],
    '— missing: ' . implode(', ', $missing_opts)
);

// ---- 26: require_data is enforced ----
echo "\n[26] require_data mode is enforced in ensure_customer_synced()\n";
$ensure_src = method_source($orders_class, 'ensure_customer_synced');
check(
    'ensure_customer_synced() branches on require_data',
    str_contains($ensure_src, 'require_data'),
    '— require_data must disable the Consumidor Final fallback'
);

// ---- 27: no order-data access via get_post_meta ----
echo "\n[27] HPOS honesty: no get_post_meta(\$order_id, ...) order-data access\n";
$orders_src = file_source($plugin_root . 'includes/Sync/Orders.php');
$legacy_order_meta = preg_match_all('/get_post_meta\s*\(\s*\$order(?:_id|->get_id\(\))\b/', $orders_src);
check(
    'Orders.php never reads order data through get_post_meta(\$order_id, ...)',
    $legacy_order_meta === 0,
    '— order meta must go through the WC CRUD API (found ' . $legacy_order_meta . ')'
);

// ---- 28: create_credit_note uses the plural invoices array ----
echo "\n[28] create_credit_note() uses the plural 'invoices' array\n";
$credit_src = method_source($orders_class, 'create_credit_note');
check(
    "create_credit_note() sends 'invoices' => [...]",
    str_contains($credit_src, "'invoices'"),
    '— the Alegra credit-note API expects an invoices array (the old singular key failed)'
);

// ---- 29: create_credit_note_for_refund caps via _alegra_credited_amount ----
echo "\n[29] create_credit_note_for_refund() caps via _alegra_credited_amount\n";
$refund_cn_src = method_source($orders_class, 'create_credit_note_for_refund');
check(
    'refund credit notes track the cumulative _alegra_credited_amount cap',
    str_contains($refund_cn_src, '_alegra_credited_amount'),
    '— must refuse to over-credit the original invoice'
);

// ---- 30: Products::assign_product_category appends ----
echo "\n[30] Products::assign_product_category() appends the Alegra category\n";
$products_src = file_source($plugin_root . 'includes/Sync/Products.php');
$assign_ok = (bool) preg_match(
    "/wp_set_object_terms\s*\(\s*\\\$product_id\s*,\s*\[[^\]]*\]\s*,\s*'product_cat'\s*,\s*true\s*\)/",
    $products_src
);
check(
    "assign_product_category() calls wp_set_object_terms(..., 'product_cat', true)",
    $assign_ok,
    '— append=true preserves the merchant manual categorization'
);

// ---- 31: update_customer_from_alegra mirrors kindofperson ----
echo "\n[31] update_customer_from_alegra() mirrors billing_alegra_kindofperson\n";
$customers_src_2 = file_source($plugin_root . 'includes/Sync/Customers.php');
check(
    "Customers::update_customer_from_alegra() writes billing_alegra_kindofperson",
    str_contains($customers_src_2, 'billing_alegra_kindofperson'),
    '— the 2.3.0 billing fields must be mirrored on customer pull'
);

// ---- 32: new admin settings section ----
echo "\n[32] New admin settings section renders\n";
check(
    "add_settings_section('alegra_connector_billing_section', ...) is registered",
    str_contains($admin_src, "add_settings_section('alegra_connector_billing_section'"),
    '— the Facturación electrónica section must exist in settings'
);

// ---- 33: wp_set_object_terms append flag is true ----
echo "\n[33] wp_set_object_terms append flag is true\n";
$append_ok = (bool) preg_match(
    "/wp_set_object_terms\s*\([^;]*'product_cat'\s*,\s*true\s*\)/s",
    $products_src
);
check(
    "product_cat term assignment passes append=true (4th arg)",
    $append_ok,
    '— re-imports must add, not replace, categories'
);

// ---- 34: dry-run guard in Client::request() ----
echo "\n[34] Dry-run guard exists in Client::request()\n";
$request_src = method_source('Alegra\\Connector\\API\\Client', 'request');
$dry_run_ok = str_contains($request_src, 'alegra_connector_dry_run')
    && str_contains($request_src, 'DRY RUN');
check(
    "Client::request() blocks non-GET verbs when alegra_connector_dry_run is on",
    $dry_run_ok,
    '— Dry Run must short-circuit all write verbs at the single choke point'
);

// ---- Done ----
echo "\n";
if ($failures) {
    echo "=== SMOKE-TEST FAILED: " . count($failures) . " assertion(s) ===\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "=== SMOKE-TEST OK: all assertions passed ===\n";
exit(0);
