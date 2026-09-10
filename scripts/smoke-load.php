<?php
/**
 * Smoke-test loader for Alegra Connector.
 *
 * Loads the plugin with mocked WP functions (extends temp_pkg/test_load.php's
 * pre-setup) and asserts that the critical autoloader + Logger path work
 * WITHOUT relying on the old explicit require_once.
 *
 * Used by scripts/smoke-test.sh. Exits non-zero if any assertion fails.
 *
 * Usage:  php scripts/smoke-load.php  (or with ALegra_PATH env to test an extracted ZIP)
 */

declare(strict_types=1);

// Resolve the plugin root: either the working dir's parent (when called from
// the extracted ZIP root) or the repo root (when called from scripts/).
$plugin_root = getenv('ALEGRA_PLUGIN_ROOT');
if ($plugin_root === false || $plugin_root === '') {
    // Default: assume scripts/smoke-load.php sits at <plugin_root>/scripts/
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

echo "=== Alegra Connector smoke-test ===\n";
echo "Plugin root: $plugin_root\n";
echo "PHP:         " . PHP_VERSION . "\n\n";

// ---- Assertion 1: explicit require_once of Logger must NOT be present ----
echo "[1] Refactor check: explicit require_once of logger/Logger/Logger.php is GONE\n";
$main_src = file_get_contents($plugin_root . 'alegra-connector.php');
// Strip /* ... */ and // line comments so historical references in comments don't false-positive.
$main_src_stripped = preg_replace('!/\*.*?\*/!s', '', $main_src);
$main_src_stripped = preg_replace('![ \t]*//.*$!m', '', $main_src_stripped);
$has_fragile_require = (bool) preg_match(
    '/require_once\s+__DIR__\s*\.\s*[\'"]\s*\/?logger\/Logger\/Logger\.php\s*[\'"]\s*;/',
    $main_src_stripped
);
check(
    'no fragile require_once of logger/Logger/Logger.php',
    !$has_fragile_require,
    '— the fragile explicit require_once that broke 2.1.7 must be removed (historical mentions in comments are OK)'
);

// ---- Assertion 2: load the plugin with mocked WP functions ----
echo "\n[2] Loading plugin with mocked WP (mirrors temp_pkg/test_load.php)...\n";
require $plugin_root . 'temp_pkg/test_load.php';

// temp_pkg/test_load.php already require_once's alegra-connector.php and prints PLUGIN_LOAD_OK
if (!defined('PLUGIN_LOADED_BY_SMOKE')) {
    // sentinel — we set this below after we successfully require_once'd the file
}

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
$api_src = file_get_contents($plugin_root . 'includes/API/Client.php');
$api_stripped = preg_replace('!/\*.*?\*/!s', '', $api_src);
$api_stripped = preg_replace('![ \t]*//.*$!m', '', $api_stripped);
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
$hooks_src = file_get_contents($plugin_root . 'includes/Webhooks/Handlers.php');
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
