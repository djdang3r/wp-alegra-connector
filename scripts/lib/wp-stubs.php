<?php
/**
 * WordPress + WooCommerce stub layer for the execution harness.
 *
 * This is NOT a test framework. It is the minimum "real enough" substrate that
 * lets the plugin's own code run unmodified: options, transients, meta, a hook
 * registry, WP_Error, and lightweight WC_Order / WC_Product / WC_Order_Refund
 * classes plus a fake $wpdb.
 *
 * Everything here is intentionally in the global namespace because that is what
 * the plugin (which was written for real WordPress) expects to call.
 *
 * Used by scripts/exec-test.php via scripts/exec-test.sh.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

if (!defined('ABSPATH')) {
    // Point ABSPATH at a throwaway tree that carries the wp-admin/includes
    // stubs the plugin require_once()s (upgrade.php for Schema, media/file/image
    // for image imports). The plugin locates its own files via __DIR__, so this
    // does not affect it.
    $alegra_abspath = sys_get_temp_dir() . '/alegra-exec-abspath';
    if (!is_dir($alegra_abspath . '/wp-admin/includes')) {
        @mkdir($alegra_abspath . '/wp-admin/includes', 0777, true);
    }
    foreach (['upgrade.php', 'media.php', 'file.php', 'image.php'] as $alegra_stub_inc) {
        $alegra_stub_path = $alegra_abspath . '/wp-admin/includes/' . $alegra_stub_inc;
        if (!is_file($alegra_stub_path)) {
            @file_put_contents($alegra_stub_path, "<?php\n");
        }
    }
    define('ABSPATH', $alegra_abspath . '/');
}

// ---------------------------------------------------------------------------
// Test clock seam (T3.1/T3.2 harness).
//
// Production code calls the global `microtime()`. The harness also declares a
// namespaced `microtime()` in Alegra\Connector\Admin and Alegra\Connector\Sync
// (scripts/lib/ns-microtime.php) that delegates here, so a test can make the
// wall-clock budget deterministic without sleeping. When the fake is null the
// namespaced functions fall back to the real `\microtime()`.
// ---------------------------------------------------------------------------

$GLOBALS['alegra_test_fake_microtime'] = null;
$GLOBALS['alegra_test_fake_microtime_step'] = 0.0;

function alegra_test_microtime(bool $as_float = false): string|float
{
    if ($GLOBALS['alegra_test_fake_microtime'] !== null) {
        $now = (float) $GLOBALS['alegra_test_fake_microtime'];
        $GLOBALS['alegra_test_fake_microtime'] = $now + (float) ($GLOBALS['alegra_test_fake_microtime_step'] ?? 0.0);
        return $as_float ? $now : (string) $now;
    }
    return \microtime($as_float);
}

/**
 * dbDelta stub. Schema::migrate() is the only caller. Counting the calls is
 * how the AC-06 test proves a second migrate() does zero schema work.
 */
function dbDelta($queries = '', $execute = true)
{
    $GLOBALS['alegra_dbdelta_calls'] = ($GLOBALS['alegra_dbdelta_calls'] ?? 0)
        + (is_array($queries) ? count($queries) : 1);
    return [];
}
foreach ([
    'HOUR_IN_SECONDS'   => 3600,
    'MINUTE_IN_SECONDS' => 60,
    'DAY_IN_SECONDS'    => 86400,
    'WEEK_IN_SECONDS'   => 604800,
    'WP_DEBUG'          => true,
    'WP_CONTENT_DIR'    => sys_get_temp_dir() . '/alegra-exec-content',
] as $alegra_stub_const => $alegra_stub_value) {
    if (!defined($alegra_stub_const)) {
        define($alegra_stub_const, $alegra_stub_value);
    }
}

// ---------------------------------------------------------------------------
// Global in-memory stores
// ---------------------------------------------------------------------------

$GLOBALS['wp_options']    = [];
$GLOBALS['wp_transients'] = [];
$GLOBALS['wp_postmeta']   = [];
$GLOBALS['wp_usermeta']   = [];
$GLOBALS['wp_posts']      = [];
$GLOBALS['wp_users']      = [];
$GLOBALS['wp_terms']      = [];
$GLOBALS['wp_term_meta']  = [];
$GLOBALS['wp_actions']    = [];
$GLOBALS['wp_filters']    = [];
$GLOBALS['wp_did_action'] = [];
$GLOBALS['alegra_db']     = [];
$GLOBALS['wp_cron']       = [];

/**
 * Race hook used by the concurrency test (T6).
 *
 * When set to ['fired' => false, 'fn' => callable], the first option/transient
 * read or write that touches a lock fires $fn exactly once, simulating a second
 * worker being scheduled in at the critical moment. This is what lets a
 * single-threaded PHP process reproduce the non-atomic lock race.
 */
$GLOBALS['alegra_race'] = null;

/**
 * Optional observer invoked by the wp_send_json_* stubs JUST BEFORE they throw.
 *
 * Real WordPress terminates the request (wp_die() -> die()), and die() does NOT
 * run finally blocks. The harness emulates the send by throwing an exception —
 * and exceptions DO run finally. This seam lets a test photograph the world at
 * the exact instant of the send (e.g. is the sync lock still held?), which is
 * what makes the "release before send" contract a real prove-it-catch.
 */
$GLOBALS['alegra_test_json_observer'] = null;

function alegra_stub_race_fire(): void
{
    if (!is_array($GLOBALS['alegra_race']) || !empty($GLOBALS['alegra_race']['fired'])) {
        return;
    }
    $GLOBALS['alegra_race']['fired'] = true;
    $fn = $GLOBALS['alegra_race']['fn'];
    if (is_callable($fn)) {
        $fn();
    }
}

// ---------------------------------------------------------------------------
// Hook registry
// ---------------------------------------------------------------------------

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['wp_actions'][$hook][$priority][] = ['cb' => $callback, 'args' => $accepted_args];
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return add_action($hook, $callback, $priority, $accepted_args);
}

function has_action($hook, $callback = false)
{
    if (!isset($GLOBALS['wp_actions'][$hook])) {
        return false;
    }
    if ($callback === false) {
        return true;
    }
    foreach ($GLOBALS['wp_actions'][$hook] as $bucket) {
        foreach ($bucket as $entry) {
            if ($entry['cb'] === $callback) {
                return true;
            }
        }
    }
    return false;
}

function remove_action($hook, $callback, $priority = 10)
{
    if (!isset($GLOBALS['wp_actions'][$hook][$priority])) {
        return false;
    }
    foreach ($GLOBALS['wp_actions'][$hook][$priority] as $i => $entry) {
        if ($entry['cb'] === $callback) {
            unset($GLOBALS['wp_actions'][$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}

function do_action($hook, ...$args)
{
    $GLOBALS['wp_did_action'][$hook] = ($GLOBALS['wp_did_action'][$hook] ?? 0) + 1;
    if (empty($GLOBALS['wp_actions'][$hook])) {
        return;
    }
    ksort($GLOBALS['wp_actions'][$hook]);
    foreach ($GLOBALS['wp_actions'][$hook] as $entries) {
        foreach ($entries as $entry) {
            $cb = $entry['cb'];
            if (is_callable($cb)) {
                call_user_func_array($cb, $args);
            }
        }
    }
}

function apply_filters($hook, $value, ...$args)
{
    if (empty($GLOBALS['wp_filters'][$hook])) {
        return $value;
    }
    ksort($GLOBALS['wp_filters'][$hook]);
    foreach ($GLOBALS['wp_filters'][$hook] as $entries) {
        foreach ($entries as $entry) {
            $cb = $entry['cb'];
            if (is_callable($cb)) {
                $value = call_user_func_array($cb, array_merge([$value], $args));
            }
        }
    }
    return $value;
}

function did_action($hook)
{
    return (int) ($GLOBALS['wp_did_action'][$hook] ?? 0);
}

// ---------------------------------------------------------------------------
// Options + transients
// ---------------------------------------------------------------------------

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['wp_options'])
        ? $GLOBALS['wp_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null)
{
    $old = $GLOBALS['wp_options'][$name] ?? false;
    $GLOBALS['wp_options'][$name] = $value;
    // WordPress fires update_option_{$option} after the write; the Consumidor
    // Final invalidation hooks (AC-25) depend on it.
    do_action("update_option_{$name}", $old, $value, $name);
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists($name, $GLOBALS['wp_options'])) {
        // Real add_option() fails on the UNIQUE option_name index.
        alegra_stub_race_fire();
        return false;
    }
    $GLOBALS['wp_options'][$name] = $value;
    // The row is committed atomically; a concurrent add_option would now lose.
    alegra_stub_race_fire();
    return true;
}

function delete_option($name)
{
    unset($GLOBALS['wp_options'][$name]);
    return true;
}

function get_transient($name)
{
    $value = $GLOBALS['wp_transients'][$name] ?? false;
    alegra_stub_race_fire();
    return $value;
}

function set_transient($name, $value, $expiration = 0, $autoload = null)
{
    $GLOBALS['wp_transients'][$name] = $value;
    return true;
}

function delete_transient($name)
{
    unset($GLOBALS['wp_transients'][$name]);
    return true;
}

// ---------------------------------------------------------------------------
// Meta
// ---------------------------------------------------------------------------

function get_post_meta($post_id, $key = '', $single = false)
{
    $post_id = (int) $post_id;
    $store = $GLOBALS['wp_postmeta'][$post_id] ?? [];
    if ($key === '') {
        return $store;
    }
    if (!array_key_exists($key, $store)) {
        return $single ? '' : [];
    }
    return $single ? $store[$key] : [$store[$key]];
}

function update_post_meta($post_id, $key, $value, $prev = '')
{
    $GLOBALS['wp_postmeta'][(int) $post_id][$key] = $value;
    return true;
}

function delete_post_meta($post_id, $key, $value = '')
{
    unset($GLOBALS['wp_postmeta'][(int) $post_id][$key]);
    return true;
}

function get_user_meta($user_id, $key = '', $single = false)
{
    $user_id = (int) $user_id;
    $store = $GLOBALS['wp_usermeta'][$user_id] ?? [];
    if ($key === '') {
        return $store;
    }
    if (!array_key_exists($key, $store)) {
        return $single ? '' : [];
    }
    return $single ? $store[$key] : [$store[$key]];
}

function update_user_meta($user_id, $key, $value, $prev = '')
{
    $GLOBALS['wp_usermeta'][(int) $user_id][$key] = $value;
    return true;
}

function delete_user_meta($user_id, $key, $value = '')
{
    unset($GLOBALS['wp_usermeta'][(int) $user_id][$key]);
    return true;
}

function get_term_meta($term_id, $key = '', $single = false)
{
    $store = $GLOBALS['wp_term_meta'][(int) $term_id] ?? [];
    if ($key === '') {
        return $store;
    }
    if (!array_key_exists($key, $store)) {
        return $single ? '' : [];
    }
    return $single ? $store[$key] : [$store[$key]];
}

function update_term_meta($term_id, $key, $value)
{
    $GLOBALS['wp_term_meta'][(int) $term_id][$key] = $value;
    return true;
}

// ---------------------------------------------------------------------------
// Escaping / i18n / sanitization
// ---------------------------------------------------------------------------

function __($text, $domain = '') { return $text; }
function _e($text, $domain = '') { echo $text; }
function _n($single, $plural, $number, $domain = '') { return ((int) $number === 1) ? $single : $plural; }
function esc_html__($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return $text; }
function esc_html_e($text, $domain = '') { echo $text; }
function esc_attr_e($text, $domain = '') { echo $text; }
// Real HTML escaping: the harness must be able to prove a template does NOT
// emit a raw payload. (WordPress's esc_html()/esc_attr() do exactly this.)
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($text) { return (string) $text; }
function wp_kses_post($text) { return (string) $text; }
function wp_strip_all_tags($text, $remove_breaks = false) { return strip_tags((string) $text); }
function sanitize_text_field($text)
{
    if (is_array($text)) { return ''; }
    return trim(strip_tags((string) $text));
}
function sanitize_textarea_field($text)
{
    if (is_array($text)) { return ''; }
    return trim(strip_tags((string) $text));
}
function sanitize_key($text) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $text)); }
function map_deep($value, $callback)
{
    if (is_array($value)) {
        return array_map(static fn($item) => map_deep($item, $callback), $value);
    }
    return is_callable($callback) ? $callback($value) : $value;
}
function sanitize_title($title, $fallback_title = '', $context = 'save')
{
    $title = strtolower(trim((string) $title));
    $title = preg_replace('/[^a-z0-9]+/', '-', $title);
    $title = trim((string) $title, '-');
    return $title !== '' ? $title : (string) $fallback_title;
}
function wp_unslash($value)
{
    if (is_array($value)) { return array_map('wp_unslash', $value); }
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_json_encode($data, $flags = 0, $depth = 512) { return (string) json_encode($data, $flags, $depth); }
function wp_parse_url($url) { return parse_url((string) $url); }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals); }
function size_format($bytes, $decimals = 0) { return (string) $bytes; }
function human_time_diff($from, $to = 0) { return '1 min'; }
function selected($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? " selected='selected'" : ''; if ($echo) { echo $r; } return $r; }
function absint($n) { return abs((int) $n); }
function wp_parse_args($args, $defaults = [])
{
    if (is_object($args)) { $args = get_object_vars($args); }
    if (!is_array($args)) { $args = []; }
    return array_merge($defaults, $args);
}
function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Time / URLs / misc
// ---------------------------------------------------------------------------

function current_time($type = 'mysql', $gmt = 0)
{
    if ($gmt) {
        return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
    }
    $offset = (int) ($GLOBALS['alegra_test_gmt_offset'] ?? 0);
    if ($offset === 0) {
        return $type === 'timestamp' ? time() : date('Y-m-d H:i:s');
    }
    $ts = time() + $offset * 3600;
    return $type === 'timestamp' ? $ts : gmdate('Y-m-d H:i:s', $ts);
}
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function rest_url($path = '', $scheme = 'rest') { return 'https://example.test/wp-json/' . ltrim((string) $path, '/'); }
function add_query_arg(...$args)
{
    if (is_array($args[0] ?? null)) {
        $query = $args[0];
        $url = (string) ($args[1] ?? '');
    } else {
        $query = [(string) ($args[0] ?? '') => $args[1] ?? ''];
        $url = (string) ($args[2] ?? '');
    }

    $parts = parse_url($url) ?: [];
    $existing = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $existing);
    }
    $merged = array_merge($existing, $query);

    $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.test') . ($parts['path'] ?? '');
    return $base . '?' . http_build_query($merged);
}
function wp_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/alegra-exec-uploads', 'path' => sys_get_temp_dir() . '/alegra-exec-uploads']; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0777, true); }
function wp_create_nonce($action = -1) { return 'nonce'; }
function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
function is_admin() { return (bool) ($GLOBALS['alegra_test_is_admin'] ?? false); }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function wp_get_current_user() { return new WP_User(1, ['user_login' => 'tester', 'user_email' => 'tester@example.test']); }
function get_current_user_id() { return 1; }
function current_user_can($cap) { return $GLOBALS['alegra_test_caps'][$cap] ?? true; }
function wp_die($message = '', $title = '', $args = [])
{
    // Emulate WordPress terminating the request, but ONLY when a test opts in;
    // every existing caller relies on wp_die() returning in the harness.
    if (!empty($GLOBALS['alegra_test_wp_die_throws'])) {
        throw new Alegra_Test_Die((string) $message, is_array($args) ? $args : []);
    }
    return;
}
function wp_raise_memory_limit($context = 'admin') { return ''; }

/**
 * Admin-menu registration capture (no real menu is built in the harness).
 */
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null)
{
    $GLOBALS['alegra_test_admin_pages'][] = ['type' => 'menu', 'slug' => $menu_slug, 'cap' => $capability, 'callback' => $callback, 'title' => $menu_title];
}
function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null)
{
    $GLOBALS['alegra_test_admin_pages'][] = ['type' => 'submenu', 'parent' => $parent_slug, 'slug' => $menu_slug, 'cap' => $capability, 'callback' => $callback, 'title' => $menu_title];
}

/**
 * Nonce / redirect stubs. A test can make the referer check fail by setting
 * $GLOBALS['alegra_test_referer_ok'] = false (combined with wp_die throwing).
 */
function wp_verify_nonce($nonce, $action = -1)
{
    return (($GLOBALS['alegra_test_referer_ok'] ?? true) === false) ? false : 1;
}
function check_admin_referer($action = -1, $query_arg = '_wpnonce', $die = true)
{
    if (($GLOBALS['alegra_test_referer_ok'] ?? true) === false) {
        if ($die) {
            wp_die('Are you sure you want to do this?');
        }
        return false;
    }
    return 1;
}
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
{
    $field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="nonce" />';
    if ($echo) { echo $field; }
    return $field;
}
function wp_safe_redirect($location, $status = 302)
{
    throw new Alegra_Test_Redirect((string) $location, (int) $status);
}
function wp_redirect($location, $status = 302)
{
    throw new Alegra_Test_Redirect((string) $location, (int) $status);
}

/**
 * Thrown by the wp_send_json_* stubs to emulate WordPress's wp_die() exit.
 *
 * Real wp_send_json_success()/error() print JSON and terminate the request.
 * Returning instead would let an AJAX handler keep running past its response
 * (and, in the dry-run tests, persist state after it already answered). Tests
 * that invoke an AJAX handler must catch this exception to read the payload.
 */
class Alegra_Test_JSON_Response extends \Exception
{
    public bool $success;
    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(bool $success, array $payload)
    {
        parent::__construct('wp_send_json response');
        $this->success = $success;
        $this->payload = $payload;
    }
}

/**
 * Thrown by wp_die() when a test opts in, to emulate the request ending.
 */
class Alegra_Test_Die extends \Exception
{
    /** @var array<string, mixed> */
    public array $args;

    public function __construct(string $message, array $args = [])
    {
        parent::__construct($message);
        $this->args = $args;
    }
}

/**
 * Thrown by wp_safe_redirect()/wp_redirect() so a handler's redirect target is
 * observable without the harness actually exiting.
 */
class Alegra_Test_Redirect extends \Exception
{
    public string $location;
    public int $status;

    public function __construct(string $location, int $status = 302)
    {
        parent::__construct('redirect to ' . $location);
        $this->location = $location;
        $this->status = $status;
    }
}

function wp_send_json_success($data = null, $status_code = null)
{
    $payload = is_array($data) ? $data : ['data' => $data];
    if (is_callable($GLOBALS['alegra_test_json_observer'] ?? null)) {
        ($GLOBALS['alegra_test_json_observer'])(true, $payload);
    }
    throw new Alegra_Test_JSON_Response(true, $payload);
}
function wp_send_json_error($data = null, $status_code = null)
{
    $payload = is_array($data) ? $data : ['data' => $data];
    if (is_callable($GLOBALS['alegra_test_json_observer'] ?? null)) {
        ($GLOBALS['alegra_test_json_observer'])(false, $payload);
    }
    throw new Alegra_Test_JSON_Response(false, $payload);
}
function wp_send_json($data, $status_code = null)
{
    $payload = is_array($data) ? $data : ['data' => $data];
    if (is_callable($GLOBALS['alegra_test_json_observer'] ?? null)) {
        ($GLOBALS['alegra_test_json_observer'])(true, $payload);
    }
    throw new Alegra_Test_JSON_Response(true, $payload);
}
function deactivate_plugins($plugin) { return; }
function flush_rewrite_rules($hard = true) { return; }
function load_plugin_textdomain($domain, $deprecated = false, $path = false) { return true; }
function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/alegra-connector/'; }
function plugin_basename($file) { return 'alegra-connector/' . basename($file); }

/**
 * get_file_data() — minimal WP core-compatible header parser.
 *
 * WP core reads the plugin/theme file headers from disk. The plugin derives
 * ALEGRA_CONNECTOR_VERSION from its `Version:` header, so the harness needs a
 * faithful stub (the real function is defined in wp-includes/functions.php and
 * is always present when WordPress boots the plugin).
 */
function get_file_data($file, $default_headers = [], $context = '')
{
    $contents = is_file($file) ? (string) @file_get_contents($file) : '';
    $data = [];
    foreach ((array) $default_headers as $field => $regex) {
        $pattern = '/^[ \t\/*#@]*' . preg_quote((string) $regex, '/') . ':(.*)$/mi';
        if (preg_match($pattern, $contents, $m)) {
            $data[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
        } else {
            $data[$field] = '';
        }
    }
    return $data;
}
function register_activation_hook($file, $callback) { return; }
function register_deactivation_hook($file, $callback) { return; }
function register_rest_route($ns, $route, $args = []) { return true; }
function add_settings_section(...$args) { return; }
function register_setting(...$args) { return; }
function add_settings_field(...$args) { return; }

/**
 * Settings errors — a faithful in-memory model. WordPress persists them in a
 * transient and `settings_errors()` consumes (clears) it after rendering.
 */
function add_settings_error($setting, $code, $message, $type = 'error')
{
    $GLOBALS['wp_settings_errors'][] = [
        'setting' => (string) $setting,
        'code'    => (string) $code,
        'message' => (string) $message,
        'type'    => (string) $type,
    ];
}

function get_settings_errors($setting = '', $sanitize = false)
{
    $all = $GLOBALS['wp_settings_errors'] ?? [];
    if ($setting === '') {
        return $all;
    }
    return array_values(array_filter(
        $all,
        static fn($error) => ($error['setting'] ?? '') === (string) $setting
    ));
}

function settings_errors($setting = '', $hide_on_update = false)
{
    foreach (get_settings_errors($setting) as $error) {
        echo $error['message'];
    }
    $GLOBALS['wp_settings_errors'] = [];
}
function wp_cache_delete($key, $group = '') { return true; }

// ---------------------------------------------------------------------------
// Cron — a faithful in-memory model of WP's cron array
//
// Structure mirrors WP core:
//   [ timestamp => [ hook => [ md5(serialize(args)) => {schedule, args, interval} ] ] ]
//
// This is what lets the suite prove that "Run now"/"skip" do not destroy the
// recurrence and that the init self-heal restores a missing event.
// ---------------------------------------------------------------------------

function _get_cron_array()
{
    return $GLOBALS['wp_cron'] ?? [];
}

function _set_cron_array($cron)
{
    $GLOBALS['wp_cron'] = is_array($cron) ? $cron : [];
    return true;
}

function wp_get_schedules()
{
    return [
        'hourly' => ['interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly'],
        'daily'  => ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'],
    ];
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false)
{
    $timestamp = (int) $timestamp;
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));

    if (isset($GLOBALS['wp_cron'][$timestamp][$hook][$key])) {
        return false;
    }

    $GLOBALS['wp_cron'][$timestamp][$hook][$key] = [
        'schedule' => $recurrence,
        'args'     => $args,
        'interval' => 0,
    ];
    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false)
{
    $timestamp = (int) $timestamp;
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));

    // WP refuses a duplicate within 10 minutes.
    $existing = wp_next_scheduled($hook, $args);
    if ($existing !== false && abs($existing - $timestamp) < 600) {
        return false;
    }

    $GLOBALS['wp_cron'][$timestamp][$hook][$key] = [
        'schedule' => false,
        'args'     => $args,
        'interval' => 0,
    ];
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));
    $next = false;

    foreach (($GLOBALS['wp_cron'] ?? []) as $timestamp => $hooks) {
        if (!isset($hooks[$hook][$key])) {
            continue;
        }
        if ($next === false || (int) $timestamp < $next) {
            $next = (int) $timestamp;
        }
    }

    return $next;
}

function wp_get_scheduled_event($hook, $args = [], $timestamp = null)
{
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));

    if ($timestamp !== null) {
        $event = $GLOBALS['wp_cron'][(int) $timestamp][$hook][$key] ?? null;
        return $event === null
            ? false
            : (object) array_merge(['hook' => $hook, 'timestamp' => (int) $timestamp], $event);
    }

    $next = wp_next_scheduled($hook, $args);
    if ($next === false) {
        return false;
    }
    $event = $GLOBALS['wp_cron'][$next][$hook][$key] ?? null;
    return $event === null
        ? false
        : (object) array_merge(['hook' => $hook, 'timestamp' => $next], $event);
}

function wp_unschedule_event($timestamp, $hook, $args = [], $wp_error = false)
{
    $timestamp = (int) $timestamp;
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));

    if (!isset($GLOBALS['wp_cron'][$timestamp][$hook][$key])) {
        return false;
    }

    unset($GLOBALS['wp_cron'][$timestamp][$hook][$key]);
    if (empty($GLOBALS['wp_cron'][$timestamp][$hook])) {
        unset($GLOBALS['wp_cron'][$timestamp][$hook]);
    }
    if (empty($GLOBALS['wp_cron'][$timestamp])) {
        unset($GLOBALS['wp_cron'][$timestamp]);
    }
    return true;
}

function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false)
{
    $args = is_array($args) ? $args : [];
    $key = md5(serialize($args));
    $cleared = 0;

    foreach (array_keys($GLOBALS['wp_cron'] ?? []) as $timestamp) {
        if (isset($GLOBALS['wp_cron'][$timestamp][$hook][$key])) {
            unset($GLOBALS['wp_cron'][$timestamp][$hook][$key]);
            $cleared++;
        }
        if (isset($GLOBALS['wp_cron'][$timestamp][$hook]) && empty($GLOBALS['wp_cron'][$timestamp][$hook])) {
            unset($GLOBALS['wp_cron'][$timestamp][$hook]);
        }
        if (isset($GLOBALS['wp_cron'][$timestamp]) && empty($GLOBALS['wp_cron'][$timestamp])) {
            unset($GLOBALS['wp_cron'][$timestamp]);
        }
    }

    return $cleared;
}

function wp_get_schedule($hook, $args = [])
{
    $event = wp_get_scheduled_event($hook, $args);
    return $event ? ($event->schedule ?: false) : false;
}

function spawn_cron($gmt_time = 0) { return true; }
function wc_add_notice($message, $type = 'success')
{
    $GLOBALS['wc_notices'][] = ['message' => (string) $message, 'type' => (string) $type];
}
function wc_get_notices($type = '')
{
    $notices = $GLOBALS['wc_notices'] ?? [];
    if ($type === '') {
        return $notices;
    }
    return array_values(array_filter($notices, static fn($n) => ($n['type'] ?? '') === (string) $type));
}
function wc_clear_notices() { $GLOBALS['wc_notices'] = []; }
function get_post($post_id) { return $GLOBALS['wp_posts'][(int) $post_id] ?? null; }
function wp_is_post_revision($post_id) { return false; }
function get_post_type($post_id) { return $GLOBALS['wp_posts'][(int) $post_id]->post_type ?? ''; }
function wp_insert_post($data)
{
    $id = (int) ($GLOBALS['alegra_next_post_id'] ?? 1000);
    $GLOBALS['alegra_next_post_id'] = $id + 1;
    $obj = (object) $data;
    $obj->ID = $id;
    $GLOBALS['wp_posts'][$id] = $obj;
    return $id;
}
function wp_update_post($data)
{
    $id = (int) (is_array($data) ? ($data['ID'] ?? 0) : ($data->ID ?? 0));
    if (isset($GLOBALS['wp_posts'][$id])) {
        $fields = is_array($data) ? $data : get_object_vars($data);
        foreach ($fields as $k => $v) { $GLOBALS['wp_posts'][$id]->$k = $v; }
    }
    return $id;
}
function get_attached_file($attachment_id) { return ''; }
function set_post_thumbnail($post_id, $thumb_id) { return true; }
function download_url($url, $timeout = 300) { return new WP_Error('http_request_failed', 'download disabled in harness'); }
function media_handle_sideload($file_array, $post_id, $desc = null) { return new WP_Error('unsupported', 'sideload disabled in harness'); }

// Terms -----------------------------------------------------------------
function get_term($term_id, $taxonomy = '')
{
    $term_id = (int) $term_id;
    return $GLOBALS['wp_terms'][$term_id] ?? null;
}
function get_terms($args = [])
{
    // Count the unindexed alegra_category_id termmeta lookups the old
    // assign_product_category() did once per product (AC-63).
    if (is_array($args) && !empty($args['meta_query'])) {
        foreach ((array) $args['meta_query'] as $mq) {
            if (is_array($mq) && ($mq['key'] ?? '') === 'alegra_category_id') {
                $GLOBALS['alegra_category_lookups'] = ($GLOBALS['alegra_category_lookups'] ?? 0) + 1;
                break;
            }
        }
    }

    $name = is_array($args) ? ($args['name'] ?? null) : null;
    $out = [];
    foreach ($GLOBALS['wp_terms'] as $term) {
        if ($name !== null && $term->name !== $name) { continue; }
        $out[] = $term;
    }
    return $out;
}
function get_the_terms($post_id, $taxonomy)
{
    $out = [];
    foreach (($GLOBALS['wp_terms'] ?? []) as $term) {
        if (($term->taxonomy ?? '') === $taxonomy) {
            $out[] = $term;
        }
    }
    return $out ?: false;
}
function term_exists($term, $taxonomy = '', $parent = null) { return false; }function wp_insert_term($term, $taxonomy, $args = [])
{
    $id = (int) ($GLOBALS['alegra_next_term_id'] ?? 500);
    $GLOBALS['alegra_next_term_id'] = $id + 1;
    $GLOBALS['wp_terms'][$id] = new WP_Term($id, (string) $term, $taxonomy);
    return ['term_id' => $id, 'term_taxonomy_id' => $id];
}
function get_ancestors($object_id, $taxonomy = '') { return []; }
function wp_set_object_terms($object_id, $terms, $taxonomy = '', $append = false) { return is_array($terms) ? $terms : [$terms]; }
function wp_get_object_terms($object_id, $taxonomy, $args = []) { return []; }

// Users -----------------------------------------------------------------
function get_userdata($user_id)
{
    $user_id = (int) $user_id;
    return $GLOBALS['wp_users'][$user_id] ?? false;
}
function get_users($args = [])
{
    $users = array_values($GLOBALS['wp_users']);

    // Support the documented meta_key / meta_value lookup used to dedupe
    // imported customers by identification.
    if (!empty($args['meta_key'])) {
        $meta_key = (string) $args['meta_key'];
        $meta_value = $args['meta_value'] ?? null;
        $users = array_values(array_filter($users, static function ($user) use ($meta_key, $meta_value) {
            $stored = $GLOBALS['wp_usermeta'][(int) $user->ID][$meta_key] ?? null;
            if ($stored === null) { return false; }
            if ($meta_value === null) { return true; }
            return (string) $stored === (string) $meta_value;
        }));
    }

    $number = (int) ($args['number'] ?? -1);
    $offset = (int) ($args['offset'] ?? 0);
    if ($number > 0) {
        $users = array_slice($users, $offset, $number);
    }

    if (($args['fields'] ?? '') === 'ID') {
        return array_map(static fn($user) => (int) $user->ID, $users);
    }

    return $users;
}
function get_user_by($field, $value)
{
    foreach ($GLOBALS['wp_users'] as $user) {
        if ($field === 'email' && $user->user_email === $value) { return $user; }
        if ($field === 'login' && $user->user_login === $value) { return $user; }
        if ($field === 'id' && (int) $user->ID === (int) $value) { return $user; }
    }
    return false;
}
function email_exists($email)
{
    foreach ($GLOBALS['wp_users'] as $user) {
        if (strcasecmp((string) $user->user_email, (string) $email) === 0) {
            return (int) $user->ID;
        }
    }
    return false;
}
function is_email($email)
{
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}
function wp_insert_user($data)
{
    $data = is_array($data) ? $data : (array) $data;
    $email = (string) ($data['user_email'] ?? '');
    $login = (string) ($data['user_login'] ?? $email);

    if ($email !== '' && email_exists($email)) {
        return new WP_Error('existing_user_email', 'Sorry, that email address is already used!');
    }
    foreach ($GLOBALS['wp_users'] as $user) {
        if ($user->user_login === $login) {
            return new WP_Error('existing_user_login', 'Sorry, that username already exists!');
        }
    }

    $id = 1;
    foreach (array_keys($GLOBALS['wp_users']) as $existing) {
        if ((int) $existing >= $id) { $id = (int) $existing + 1; }
    }

    $first = (string) ($data['first_name'] ?? '');
    $last = (string) ($data['last_name'] ?? '');
    $display = (string) ($data['display_name'] ?? trim($first . ' ' . $last));

    $GLOBALS['wp_users'][$id] = new WP_User($id, [
        'user_login'   => $login,
        'user_email'   => $email,
        'display_name' => $display,
        'first_name'   => $first,
        'last_name'    => $last,
    ]);

    return $id;
}
function wp_update_user($data)
{
    $data = is_array($data) ? $data : (array) $data;
    $id = (int) ($data['ID'] ?? 0);
    if ($id <= 0 || !isset($GLOBALS['wp_users'][$id])) {
        return new WP_Error('invalid_user_id', 'Invalid user ID.');
    }

    $user = $GLOBALS['wp_users'][$id];

    if (isset($data['user_email'])) {
        $email = (string) $data['user_email'];
        $owner = email_exists($email);
        if ($owner && (int) $owner !== $id) {
            return new WP_Error('existing_user_email', 'Sorry, that email address is already used!');
        }
        $user->user_email = $email;
    }

    foreach (['user_login', 'display_name', 'first_name', 'last_name'] as $field) {
        if (isset($data[$field])) {
            $user->{$field} = (string) $data[$field];
        }
    }

    return $id;
}

// ---------------------------------------------------------------------------
// WP_Error / WP_User / WP_Term
// ---------------------------------------------------------------------------

class WP_Error
{
    private string $code;
    private string $message;
    private mixed $data;

    public function __construct(string $code = '', string $message = '', mixed $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
    public function get_error_codes(): array { return [$this->code]; }
    public function get_error_messages(): array { return [$this->message]; }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

class WP_User
{
    public int $ID;
    public string $user_login;
    public string $user_email;
    public string $display_name;
    public string $first_name;
    public string $last_name;

    public function __construct(int $id, array $data = [])
    {
        $this->ID = $id;
        $this->user_login = (string) ($data['user_login'] ?? 'user' . $id);
        $this->user_email = (string) ($data['user_email'] ?? 'user' . $id . '@example.test');
        $this->display_name = (string) ($data['display_name'] ?? $this->user_login);
        $this->first_name = (string) ($data['first_name'] ?? '');
        $this->last_name = (string) ($data['last_name'] ?? '');
    }

    public function __get($name)
    {
        return null;
    }
}

/**
 * WordPress's WC_DateTime extends DateTime and adds a date() alias for format().
 * The plugin calls $order->get_date_created()->date('Y-m-d').
 */
class WC_DateTime extends DateTime
{
    public function date($format = 'Y-m-d H:i:s'): string
    {
        return $this->format((string) $format);
    }
}

class WP_Term
{
    public int $term_id;
    public string $name;
    public string $taxonomy;
    public string $description = '';

    public function __construct(int $id, string $name, string $taxonomy = '')
    {
        $this->term_id = $id;
        $this->name = $name;
        $this->taxonomy = $taxonomy;
    }
}

// ---------------------------------------------------------------------------
// REST API stubs (used by the webhook Receiver tests)
// ---------------------------------------------------------------------------

class WP_REST_Request
{
    private string $body = '';
    /** @var array<string,string> */
    private array $headers = [];
    /** @var array<string,mixed> Query-string params (e.g. ?token=...). */
    private array $params = [];

    public function __construct(string $body = '', array $headers = [], array $params = [])
    {
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
        $this->params = $params;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Real WP merges URL query params with parsed body params. The webhook
     * token arrives in the query string, so model both.
     */
    public function get_param(string $key): mixed
    {
        if (array_key_exists($key, $this->params)) {
            return $this->params[$key];
        }
        $decoded = json_decode($this->body, true);
        if (is_array($decoded) && array_key_exists($key, $decoded)) {
            return $decoded[$key];
        }
        return null;
    }

    public function get_query_params(): array
    {
        return $this->params;
    }
}

class WP_REST_Response
{
    public mixed $data;
    public int $status;

    public function __construct(mixed $data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_status(): int
    {
        return $this->status;
    }

    public function get_data(): mixed
    {
        return $this->data;
    }
}

// ---------------------------------------------------------------------------
// WooCommerce stubs
// ---------------------------------------------------------------------------

class WC_Order_Item
{
    public int $product_id = 0;
    public int $variation_id = 0;
    public string $name = '';
    public float $quantity = 1.0;
    public float $subtotal = 0.0;
    public float $total = 0.0;
    public array $taxes = ['total' => []];

    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) { $this->$k = $v; }
        }
    }

    public function get_product_id(): int { return $this->product_id; }
    public function get_variation_id(): int { return $this->variation_id; }
    public function get_name(): string { return $this->name; }
    public function get_quantity(): float { return $this->quantity; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total(): float { return $this->total; }
    public function get_taxes(): array { return $this->taxes; }
    public function get_id(): int { return $this->product_id; }
}

class WC_Product
{
    protected int $id;
    protected string $type = 'simple';
    protected array $data = [];

    public function __construct(int $id = 0, array $data = [])
    {
        $this->id = $id;
        $this->data = $data;
        $this->type = (string) ($data['type'] ?? 'simple');
    }

    public function get_id(): int { return $this->id; }
    public function get_name(): string { return (string) ($this->data['name'] ?? 'Product ' . $this->id); }
    public function get_sku(): string { return (string) ($this->data['sku'] ?? ''); }
    public function get_description(): string { return (string) ($this->data['description'] ?? ''); }
    public function get_short_description(): string { return ''; }
    public function get_regular_price(): string { return (string) ($this->data['regular_price'] ?? '0'); }
    public function get_sale_price(): string { return (string) ($this->data['sale_price'] ?? ''); }
    public function get_price(): string { return (string) ($this->data['price'] ?? $this->get_regular_price()); }
    public function get_tax_class(): string { return (string) ($this->data['tax_class'] ?? ''); }
    public function get_stock_quantity(): ?int { return isset($this->data['stock']) ? (int) $this->data['stock'] : null; }
    public function get_manage_stock(): bool { return (bool) ($this->data['manage_stock'] ?? false); }
    public function get_stock_status(): string { return (string) ($this->data['stock_status'] ?? 'instock'); }
    public function get_backorders(): string { return (string) ($this->data['backorders'] ?? 'no'); }
    public function get_image_id(): int { return (int) ($this->data['image_id'] ?? 0); }
    public function get_category_ids(): array { return (array) ($this->data['category_ids'] ?? []); }
    public function get_children(): array { return (array) ($this->data['children'] ?? []); }
    public function get_parent_id(): int { return (int) ($this->data['parent_id'] ?? 0); }
    public function get_attributes(): array { return (array) ($this->data['attributes'] ?? []); }
    /**
     * Variation attribute selections, mirroring WC_Product::get_variation_attributes()
     * (`['attribute_color' => 'Rojo']` for a variation; the parent returns the
     * configured value set).
     */
    public function get_variation_attributes(): array { return (array) ($this->data['variation_attributes'] ?? []); }
    public function get_type(): string { return $this->type; }
    public function is_type($type): bool
    {
        if ($type === 'simple') {
            return in_array($this->type, ['simple'], true);
        }
        return $this->type === $type;
    }
    public function set_regular_price($p) { $this->data['regular_price'] = $p; return $this; }
    public function set_name($n) { $this->data['name'] = $n; return $this; }
    public function set_description($d) { $this->data['description'] = $d; return $this; }
    public function set_status($s) { $this->data['status'] = $s; return $this; }
    public function set_catalog_visibility($v) { return $this; }
    public function set_stock_quantity($q) { $this->data['stock'] = $q; return $this; }
    public function set_manage_stock($m) { $this->data['manage_stock'] = (bool) $m; return $this; }
    public function set_stock_status($s) { $this->data['stock_status'] = $s; return $this; }
    public function set_backorders($b) { $this->data['backorders'] = $b; return $this; }
    public function set_sku($s) { $this->data['sku'] = $s; return $this; }
    public function save() { return $this->id; }
}

class WC_Product_Simple extends WC_Product {}

class WC_Product_Variation extends WC_Product
{
    public function __construct(int $id = 0, array $data = [])
    {
        $data['type'] = 'variation';
        parent::__construct($id, $data);
    }
}

// Marker class so `class_exists('WooCommerce')` is true in the harness (the
// activation defaults are only written when WooCommerce is active).
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}

class WC_Order
{
    protected int $id;
    protected array $meta = [];
    protected array $items = [];
    protected array $shipping_items = [];
    protected array $fee_items = [];
    protected array $refunds = [];
    protected float $total = 0.0;
    protected float $subtotal = 0.0;
    protected float $total_refunded = 0.0;
    protected float $shipping_total = 0.0;
    protected float $shipping_tax = 0.0;
    protected array $billing = [];
    protected string $payment_method = '';
    protected string $payment_method_title = '';
    protected string $currency = 'COP';
    protected string $status = 'processing';
    protected ?\DateTime $date_created = null;
    protected ?\DateTime $date_paid = null;
    protected int $customer_id = 0;
    protected ?WP_User $user = null;
    protected array $notes = [];
    protected array $payment_tokens = [];

    public function __construct(int $id = 0, array $data = [])
    {
        $this->id = $id;
        $this->total = (float) ($data['total'] ?? 0.0);
        $this->subtotal = (float) ($data['subtotal'] ?? $this->total);
        $this->total_refunded = (float) ($data['total_refunded'] ?? 0.0);
        $this->shipping_total = (float) ($data['shipping_total'] ?? 0.0);
        $this->shipping_tax = (float) ($data['shipping_tax'] ?? 0.0);
        $this->billing = (array) ($data['billing'] ?? []);
        $this->payment_method = (string) ($data['payment_method'] ?? '');
        $this->payment_method_title = (string) ($data['payment_method_title'] ?? '');
        $this->currency = (string) ($data['currency'] ?? 'COP');
        $this->status = (string) ($data['status'] ?? 'processing');
        $this->customer_id = (int) ($data['customer_id'] ?? 0);
        $this->meta = (array) ($data['meta'] ?? []);
        $this->items = (array) ($data['items'] ?? []);
        $this->shipping_items = (array) ($data['shipping_items'] ?? []);
        $this->fee_items = (array) ($data['fee_items'] ?? []);
        $this->refunds = (array) ($data['refunds'] ?? []);
        $this->date_created = $data['date_created'] ?? new WC_DateTime();
        $this->date_paid = $data['date_paid'] ?? null;
        if (isset($data['user']) && $data['user'] instanceof WP_User) {
            $this->user = $data['user'];
        } elseif ($this->customer_id > 0) {
            $u = get_userdata($this->customer_id);
            $this->user = $u instanceof WP_User ? $u : null;
        }
    }

    public function get_id(): int { return $this->id; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ($single ? '' : []); }
    public function meta_exists($key) { return array_key_exists($key, $this->meta); }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; return $this; }
    public function delete_meta_data($key) { unset($this->meta[$key]); return $this; }
    public function save() { return $this->id; }
    public function get_items($type = 'line_item')
    {
        if ($type === 'shipping') { return $this->shipping_items; }
        if ($type === 'fee') { return $this->fee_items; }
        return $this->items;
    }
    public function get_total(): float { return $this->total; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total_refunded(): float { return $this->total_refunded; }
    public function get_shipping_total(): float { return $this->shipping_total; }
    public function get_shipping_tax(): float { return $this->shipping_tax; }
    public function is_paid(): bool { return in_array($this->status, ['processing', 'completed'], true); }
    public function get_refunds(): array { return $this->refunds; }

    public function get_billing_country(): string { return (string) ($this->billing['country'] ?? ''); }
    public function get_billing_email(): string { return (string) ($this->billing['email'] ?? ''); }
    public function get_billing_first_name(): string { return (string) ($this->billing['first_name'] ?? ''); }
    public function get_billing_last_name(): string { return (string) ($this->billing['last_name'] ?? ''); }
    public function get_billing_phone(): string { return (string) ($this->billing['phone'] ?? ''); }
    public function get_billing_address_1(): string { return (string) ($this->billing['address_1'] ?? ''); }
    public function get_billing_city(): string { return (string) ($this->billing['city'] ?? ''); }
    public function get_billing_state(): string { return (string) ($this->billing['state'] ?? ''); }
    public function get_billing_postcode(): string { return (string) ($this->billing['postcode'] ?? ''); }
    public function get_payment_method(): string { return $this->payment_method; }
    public function get_payment_method_title(): string { return $this->payment_method_title; }
    public function get_transaction_id(): string { return (string) ($this->meta['_transaction_id'] ?? ''); }
    public function get_currency(): string { return $this->currency; }
    public function get_status(): string { return $this->status; }
    public function update_status($status) { $this->status = (string) $status; return true; }
    public function get_date_created(): ?\DateTime { return $this->date_created; }
    public function get_date_paid(): ?\DateTime { return $this->date_paid; }
    public function set_refunds(array $refunds) { $this->refunds = $refunds; return $this; }
    public function add_refund(WC_Order_Refund $refund) { $this->refunds[] = $refund; return $this; }
    public function get_customer_id(): int { return $this->customer_id; }
    // Mirrors WC_Order::get_user(): a WP_User for a registered customer and
    // `false` for a guest (customer_id 0). Returning null hid a production
    // TypeError where `false` was passed to a `?WP_User` parameter.
    public function get_user() { return $this->user ?? false; }
    public function get_payment_tokens(): array { return $this->payment_tokens; }
    public function add_order_note($note, $is_customer_note = 0, $added_by_user = false)
    {
        $this->notes[] = (string) $note;
        return count($this->notes);
    }
    public function get_notes(): array { return $this->notes; }
}

class WC_Order_Refund extends WC_Order
{
    public function __construct(int $id = 0, array $data = [])
    {
        parent::__construct($id, $data);
        $this->total = (float) ($data['total'] ?? 0.0);
    }
}

/**
 * Minimal WC_Tax stub. `_get_tax_rate($rate_id)` returns the seeded rate array
 * (`['tax_rate' => '19.0000', 'tax_rate_class' => '']`) so the invoice tax
 * resolver can derive a percentage from a WC rate id.
 */
class WC_Tax
{
    public static function _get_tax_rate($rate_id, $output = 'ARRAY_A'): array
    {
        $rates = $GLOBALS['wc_tax_rates'] ?? [];
        return $rates[(int) $rate_id] ?? [];
    }
}

// WC accessor functions -------------------------------------------------
function wc_get_order($order_id)
{
    $order_id = (int) $order_id;
    return $GLOBALS['wc_orders'][$order_id] ?? false;
}
function wc_get_product($product_id)
{
    $product_id = (int) $product_id;
    return $GLOBALS['wc_products'][$product_id] ?? false;
}

/**
 * Recursively evaluate a WP-style meta_query against a stubbed WC_Order.
 *
 * Mirrors WP_Meta_Query semantics closely enough for the harness: a `= ''`
 * clause matches only an existing empty row, while `NOT EXISTS` matches a
 * missing key — the same distinction the real query has to handle.
 */
function alegra_stub_meta_query_matches($order, array $meta_query): bool
{
    $relation = strtoupper((string) ($meta_query['relation'] ?? 'AND'));
    $clauses = array_filter($meta_query, static fn ($k) => $k !== 'relation', ARRAY_FILTER_USE_KEY);

    if ($clauses === []) {
        return true;
    }

    foreach ($clauses as $clause) {
        if (!is_array($clause)) {
            continue;
        }
        $nested = isset($clause['relation']) || (isset($clause[0]) && is_array($clause[0]));
        $ok = $nested
            ? alegra_stub_meta_query_matches($order, $clause)
            : alegra_stub_meta_clause_matches($order, $clause);

        if ($relation === 'OR') {
            if ($ok) { return true; }
        } elseif (!$ok) {
            return false;
        }
    }

    return $relation !== 'OR';
}

function alegra_stub_meta_clause_matches($order, array $clause): bool
{
    $key = (string) ($clause['key'] ?? '');
    if ($key === '' || !method_exists($order, 'meta_exists')) {
        return false;
    }

    $compare = strtoupper((string) ($clause['compare'] ?? '='));
    $value   = (string) ($clause['value'] ?? '');
    $exists  = (bool) $order->meta_exists($key);
    $actual  = (string) $order->get_meta($key, true);

    switch ($compare) {
        case 'NOT EXISTS':
            return !$exists;
        case 'EXISTS':
            return $exists;
        case '!=':
            return $exists && $actual !== $value;
        case 'LIKE':
            return $exists && stripos($actual, trim($value, '%')) !== false;
        case '=':
        default:
            return $exists && $actual === $value;
    }
}

function wc_get_orders($args = [])
{
    $orders = array_values($GLOBALS['wc_orders'] ?? []);

    if (!empty($args['meta_key'])) {
        $mk = (string) $args['meta_key'];
        $mv = array_key_exists('meta_value', $args) ? (string) $args['meta_value'] : null;
        $orders = array_values(array_filter($orders, static function ($o) use ($mk, $mv) {
            if (!method_exists($o, 'get_meta')) { return false; }
            $val = $o->get_meta($mk, true);
            if ($val === '' || $val === null) { return false; }
            return $mv === null ? true : (string) $val === $mv;
        }));
    }

    if (!empty($args['meta_query']) && is_array($args['meta_query'])) {
        $mq = $args['meta_query'];
        $orders = array_values(array_filter($orders, static function ($o) use ($mq) {
            return alegra_stub_meta_query_matches($o, $mq);
        }));
    }

    if (!empty($args['status'])) {
        $statuses = array_map('strval', (array) $args['status']);
        $orders = array_values(array_filter($orders, static function ($o) use ($statuses) {
            return in_array((string) $o->get_status(), $statuses, true);
        }));
    }

    if (!empty($args['customer_id'])) {
        $cid = (int) $args['customer_id'];
        $orders = array_values(array_filter($orders, static fn ($o) => (int) $o->get_customer_id() === $cid));
    }

    if (!empty($args['limit'])) {
        $offset = (int) ($args['offset'] ?? 0);
        $orders = array_slice($orders, $offset, (int) $args['limit']);
    }

    if (($args['return'] ?? '') === 'ids') {
        return array_map(static fn ($o) => $o->get_id(), $orders);
    }

    return $orders;
}
function wc_get_products($args = [])
{
    $products = array_values($GLOBALS['wc_products'] ?? []);
    $limit = (int) ($args['limit'] ?? -1);
    $page = max(1, (int) ($args['page'] ?? 1));
    if ($limit > 0) {
        $products = array_slice($products, ($page - 1) * $limit, $limit);
    }
    return $products;
}
function wc_get_product_id_by_sku($sku)
{
    foreach (($GLOBALS['wc_products'] ?? []) as $product) {
        if ($product->get_sku() === (string) $sku) { return $product->get_id(); }
    }
    return 0;
}

// Minimal order-status + price helpers so the admin templates can be rendered
// in the harness (the orders list badge test includes templates/admin-orders.php).
function wc_get_order_statuses()
{
    return [
        'wc-pending'    => 'Pendiente de pago',
        'wc-processing' => 'Procesando',
        'wc-on-hold'    => 'En espera',
        'wc-completed'  => 'Completado',
        'wc-cancelled'  => 'Cancelado',
        'wc-refunded'   => 'Reembolsado',
        'wc-failed'     => 'Fallido',
    ];
}

function wc_get_order_status_name($status)
{
    $status = str_replace('wc-', '', (string) $status);
    $statuses = [
        'pending'    => 'Pendiente de pago',
        'processing' => 'Procesando',
        'on-hold'    => 'En espera',
        'completed'  => 'Completado',
        'cancelled'  => 'Cancelado',
        'refunded'   => 'Reembolsado',
        'failed'     => 'Fallido',
    ];
    return $statuses[$status] ?? $status;
}

function wc_price($price, $args = [])
{
    return '$' . number_format((float) $price, 2, '.', ',');
}

function wp_date($format, $timestamp = null, $timezone = null)
{
    return date((string) $format, $timestamp === null ? time() : (int) $timestamp);
}

// ---------------------------------------------------------------------------
// Fake $wpdb
// ---------------------------------------------------------------------------

class Alegra_Mock_Wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';
    public string $usermeta = 'wp_usermeta';
    public string $posts = 'wp_posts';
    public string $users = 'wp_users';
    public string $termmeta = 'wp_termmeta';
    public int $insert_id = 0;

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args) {
            $a = $args[$i] ?? null;
            $i++;
            if ($a === null) { return 'NULL'; }
            if ($m[0] === '%d') { return (string) (int) $a; }
            if ($m[0] === '%f') { return (string) (float) $a; }
            return "'" . addslashes((string) $a) . "'";
        }, (string) $query);
    }

    public function get_var($query, $x = null, $y = null)
    {
        $query = (string) $query;

        if (strpos($query, 'option_name') !== false && preg_match("/option_name\s*=\s*'([^']+)'/", $query, $m)) {
            return array_key_exists($m[1], $GLOBALS['wp_options'])
                ? (string) $GLOBALS['wp_options'][$m[1]]
                : null;
        }

        if (strpos($query, 'alegra_entity_map') !== false && preg_match_all("/'([^']*)'/", $query, $m)) {
            $vals = $m[1];
            if (count($vals) >= 3) {
                [$type, $alegra_id, $wc_type] = $vals;
                foreach (($GLOBALS['alegra_entity_map'] ?? []) as $row) {
                    if ($row['alegra_type'] === $type && $row['alegra_id'] === $alegra_id && $row['wc_entity_type'] === $wc_type) {
                        return (string) $row['wc_entity_id'];
                    }
                }
            }
            return null;
        }

        if (preg_match('/FROM\s+\S*postmeta/i', $query) && strpos($query, 'meta_value') !== false) {
            $GLOBALS['alegra_postmeta_scans'] = ($GLOBALS['alegra_postmeta_scans'] ?? 0) + 1;
            return $this->scan_meta('wp_postmeta', $query);
        }

        if (preg_match('/FROM\s+\S*usermeta/i', $query) && strpos($query, 'meta_value') !== false) {
            $GLOBALS['alegra_postmeta_scans'] = ($GLOBALS['alegra_postmeta_scans'] ?? 0) + 1;
            return $this->scan_meta('wp_usermeta', $query);
        }

        // wp_alegra_runs: `SELECT status FROM … WHERE id = N` (Runs::status).
        if (strpos($query, 'alegra_runs') !== false
            && preg_match('/SELECT\s+status/i', $query)
            && preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $query, $m)) {
            foreach (($GLOBALS['alegra_db']['wp_alegra_runs'] ?? []) as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $m[1]) {
                    return isset($row['status']) ? (string) $row['status'] : null;
                }
            }
            return null;
        }

        // wp_alegra_tombstones: `SELECT reason … WHERE alegra_type='…' AND
        // alegra_id='…' AND resurrected_at IS NULL` (Tombstone_Manager::exists_with_reason).
        if (strpos($query, 'alegra_tombstones') !== false
            && preg_match("/alegra_type\s*=\s*'([^']*)'/", $query, $mt)
            && preg_match("/alegra_id\s*=\s*'([^']*)'/", $query, $mi)) {
            $table = $this->prefix . 'alegra_tombstones';
            foreach (($GLOBALS['alegra_db'][$table] ?? []) as $row) {
                $resurrected = $row['resurrected_at'] ?? null;
                if ($resurrected !== null) { continue; }
                if (($row['alegra_type'] ?? '') === $mt[1] && (string) ($row['alegra_id'] ?? '') === $mi[1]) {
                    return (string) ($row['reason'] ?? 'manual_wc');
                }
            }
            return null;
        }

        return null;
    }

    private function scan_meta(string $table, string $query): ?string
    {
        $store = $table === 'wp_postmeta' ? ($GLOBALS['wp_postmeta'] ?? []) : ($GLOBALS['wp_usermeta'] ?? []);
        if (!preg_match("/meta_key\s*=\s*'([^']+)'/", $query, $mk) || !preg_match("/meta_value\s*=\s*'([^']+)'/", $query, $mv)) {
            return null;
        }
        foreach ($store as $entity_id => $meta) {
            if (isset($meta[$mk[1]]) && (string) $meta[$mk[1]] === $mv[1]) {
                return (string) $entity_id;
            }
        }
        return null;
    }

    public function get_row($query, $output = null, $y = null) { return null; }

    public function get_col($query, $x = 0)
    {
        $rows = $this->get_results($query);
        $out = [];
        foreach ((array) $rows as $row) {
            $vars = is_object($row) ? get_object_vars($row) : (array) $row;
            $out[] = array_values($vars)[0] ?? null;
        }
        return $out;
    }

    public function get_results($query, $output = null)
    {
        $query = (string) $query;

        if (strpos($query, 'alegra_category_id') !== false && strpos($query, 'termmeta') !== false) {
            $GLOBALS['alegra_category_lookups'] = ($GLOBALS['alegra_category_lookups'] ?? 0) + 1;
            $rows = [];
            foreach (($GLOBALS['wp_term_meta'] ?? []) as $term_id => $meta) {
                if (isset($meta['alegra_category_id']) && (string) $meta['alegra_category_id'] !== '') {
                    $rows[] = (object) ['term_id' => $term_id, 'meta_value' => (string) $meta['alegra_category_id']];
                }
            }
            return $rows;
        }

        // wp_alegra_runs: supports status / run_type / started_at< filters and LIMIT
        // (currently_running, recent, mark_abandoned).
        if (strpos($query, 'alegra_runs') !== false) {
            $rows = $GLOBALS['alegra_db']['wp_alegra_runs'] ?? [];
            if (preg_match("/status\s*=\s*'([^']+)'/", $query, $m)) {
                $rows = array_values(array_filter($rows, fn ($r) => ($r['status'] ?? '') === $m[1]));
            }
            if (preg_match("/run_type\s*=\s*'([^']+)'/", $query, $m)) {
                $rows = array_values(array_filter($rows, fn ($r) => ($r['run_type'] ?? '') === $m[1]));
            }
            if (preg_match("/started_at\s*<\s*'([^']+)'/", $query, $m)) {
                $rows = array_values(array_filter($rows, fn ($r) => ($r['started_at'] ?? '') < $m[1]));
            }
            if (preg_match('/LIMIT\s+(\d+)/i', $query, $m)) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            return array_map(static fn ($r) => (object) $r, $rows);
        }

        return [];
    }

    public function query($query)
    {
        $query = (string) $query;

        // wp_alegra_runs: `UPDATE … SET status='stale' … WHERE status='running'
        // AND started_at < cutoff LIMIT N` (Runs::mark_stale). Must honour the
        // cutoff, otherwise it would mark freshly-started runs stale.
        if (stripos($query, 'alegra_runs') !== false && stripos($query, 'UPDATE') !== false) {
            $set_status = null;
            if (preg_match("/SET\s+status\s*=\s*'([^']+)'/i", $query, $m)) { $set_status = $m[1]; }
            $cutoff = null;
            if (preg_match("/started_at\s*<\s*'([^']+)'/", $query, $m)) { $cutoff = $m[1]; }
            $limit = 50;
            if (preg_match('/LIMIT\s+(\d+)/i', $query, $m)) { $limit = (int) $m[1]; }

            $n = 0;
            foreach (($GLOBALS['alegra_db']['wp_alegra_runs'] ?? []) as $i => $row) {
                if (($row['status'] ?? '') !== 'running') { continue; }
                if ($cutoff !== null && !(($row['started_at'] ?? '') < $cutoff)) { continue; }
                if ($set_status !== null) {
                    $GLOBALS['alegra_db']['wp_alegra_runs'][$i]['status'] = $set_status;
                }
                if (++$n >= $limit) { break; }
            }
            return $n;
        }

        if (stripos($query, 'alegra_entity_map') !== false) {
            if (stripos($query, 'insert into') !== false && preg_match('/VALUES\s*\((.*?)\)\s*ON DUPLICATE/is', $query, $m)) {
                $parts = array_map('trim', str_getcsv($m[1], ',', "'"));
                if (count($parts) >= 4) {
                    $this->entity_map_put([
                        'alegra_type' => (string) $parts[0],
                        'alegra_id' => (string) $parts[1],
                        'wc_entity_type' => (string) $parts[2],
                        'wc_entity_id' => (int) $parts[3],
                    ]);
                    return 1;
                }
            }
            if (stripos($query, 'delete from') !== false) {
                if (preg_match("/alegra_type\s*=\s*'([^']*)'\s+AND\s+alegra_id\s*=\s*'([^']*)'\s+AND\s+wc_entity_type\s*=\s*'([^']*)'/i", $query, $m)) {
                    $GLOBALS['alegra_entity_map'] = array_values(array_filter(
                        $GLOBALS['alegra_entity_map'],
                        static fn ($r) => !($r['alegra_type'] === $m[1] && $r['alegra_id'] === $m[2] && $r['wc_entity_type'] === $m[3])
                    ));
                    return 1;
                }
                if (preg_match("/wc_entity_type\s*=\s*'([^']*)'\s+AND\s+wc_entity_id\s*=\s*(\d+)/i", $query, $m)) {
                    $GLOBALS['alegra_entity_map'] = array_values(array_filter(
                        $GLOBALS['alegra_entity_map'],
                        static fn ($r) => !($r['wc_entity_type'] === $m[1] && (int) $r['wc_entity_id'] === (int) $m[2])
                    ));
                    return 1;
                }
            }
            return 1;
        }

        return 1;
    }

    private function entity_map_put(array $row): void
    {
        foreach (($GLOBALS['alegra_entity_map'] ?? []) as $i => $existing) {
            if ($existing['alegra_type'] === $row['alegra_type']
                && $existing['alegra_id'] === $row['alegra_id']
                && $existing['wc_entity_type'] === $row['wc_entity_type']) {
                $GLOBALS['alegra_entity_map'][$i] = $row;
                return;
            }
        }
        $GLOBALS['alegra_entity_map'][] = $row;
    }

    public function insert($table, $data = [], $format = null)
    {
        $table = (string) $table;
        $next_id = count($GLOBALS['alegra_db'][$table] ?? []) + 1;
        if (str_ends_with($table, 'alegra_runs')) {
            $data['id'] = $next_id;
        }
        $GLOBALS['alegra_db'][$table][] = $data;
        $this->insert_id = $next_id;
        return 1;
    }

    public function update($table, $data = [], $where = [], $format = null, $where_format = null)
    {
        $table = (string) $table;
        if (empty($GLOBALS['alegra_db'][$table]) || !is_array($GLOBALS['alegra_db'][$table])) {
            return 1;
        }
        foreach ($GLOBALS['alegra_db'][$table] as $i => $row) {
            $match = true;
            foreach ((array) $where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) { $match = false; break; }
            }
            if ($match) {
                $GLOBALS['alegra_db'][$table][$i] = array_merge((array) $row, $data);
            }
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new Alegra_Mock_Wpdb();
$wpdb = $GLOBALS['wpdb'];
