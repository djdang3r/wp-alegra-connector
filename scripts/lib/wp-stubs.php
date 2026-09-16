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
    define('ABSPATH', dirname(__DIR__, 2) . '/');
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

/**
 * Race hook used by the concurrency test (T6).
 *
 * When set to ['fired' => false, 'fn' => callable], the first option/transient
 * read or write that touches a lock fires $fn exactly once, simulating a second
 * worker being scheduled in at the critical moment. This is what lets a
 * single-threaded PHP process reproduce the non-atomic lock race.
 */
$GLOBALS['alegra_race'] = null;

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
function esc_html__($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return $text; }
function esc_html_e($text, $domain = '') { echo $text; }
function esc_html($text) { return (string) $text; }
function esc_attr($text) { return (string) $text; }
function esc_textarea($text) { return (string) $text; }
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
function selected($a, $b, $echo = true) { $r = ((string) $a === (string) $b) ? " selected='selected'" : ''; if ($echo) { echo $r; } return $r; }
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
    return $type === 'timestamp' ? time() : date('Y-m-d H:i:s');
}
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/alegra-exec-uploads', 'path' => sys_get_temp_dir() . '/alegra-exec-uploads']; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0777, true); }
function wp_create_nonce($action = -1) { return 'nonce'; }
function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
function is_admin() { return false; }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function wp_get_current_user() { return new WP_User(1, ['user_login' => 'tester', 'user_email' => 'tester@example.test']); }
function get_current_user_id() { return 1; }
function current_user_can($cap) { return true; }
function wp_die($message = '', $title = '', $args = []) { return; }
function deactivate_plugins($plugin) { return; }
function flush_rewrite_rules($hard = true) { return; }
function load_plugin_textdomain($domain, $deprecated = false, $path = false) { return true; }
function plugin_dir_path($file) { return rtrim(dirname($file), '/\\') . '/'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/alegra-connector/'; }
function plugin_basename($file) { return 'alegra-connector/' . basename($file); }
function register_activation_hook($file, $callback) { return; }
function register_deactivation_hook($file, $callback) { return; }
function register_rest_route($ns, $route, $args = []) { return true; }
function add_settings_section(...$args) { return; }
function register_setting(...$args) { return; }
function add_settings_field(...$args) { return; }
function wp_cache_delete($key, $group = '') { return true; }
function wp_clear_scheduled_hook($hook) { return true; }
function wp_schedule_event($timestamp, $recurrence, $hook) { return true; }
function wp_schedule_single_event($timestamp, $hook, $args = []) { return true; }
function spawn_cron($gmt_time = 0) { return true; }
function wp_next_scheduled($hook) { return false; }
function _get_cron_array() { return []; }
function wc_add_notice($message, $type = 'success') { return; }
function wc_get_notices($type = '') { return []; }
function wc_clear_notices() { return; }
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
    $name = is_array($args) ? ($args['name'] ?? null) : null;
    $out = [];
    foreach ($GLOBALS['wp_terms'] as $term) {
        if ($name !== null && $term->name !== $name) { continue; }
        $out[] = $term;
    }
    return $out;
}
function term_exists($term, $taxonomy = '', $parent = null) { return false; }
function wp_insert_term($term, $taxonomy, $args = [])
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
    return array_values($GLOBALS['wp_users']);
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

    public function __construct(string $body = '', array $headers = [])
    {
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
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
    public function get_image_id(): int { return (int) ($this->data['image_id'] ?? 0); }
    public function get_category_ids(): array { return (array) ($this->data['category_ids'] ?? []); }
    public function get_children(): array { return (array) ($this->data['children'] ?? []); }
    public function get_parent_id(): int { return (int) ($this->data['parent_id'] ?? 0); }
    public function get_attributes(): array { return (array) ($this->data['attributes'] ?? []); }
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
    public function set_sku($s) { $this->data['sku'] = $s; return $this; }
    public function save() { return $this->id; }
}

class WC_Product_Simple extends WC_Product {}

class WC_Order
{
    protected int $id;
    protected array $meta = [];
    protected array $items = [];
    protected array $refunds = [];
    protected float $total = 0.0;
    protected float $subtotal = 0.0;
    protected float $total_refunded = 0.0;
    protected array $billing = [];
    protected string $payment_method = '';
    protected string $currency = 'COP';
    protected string $status = 'processing';
    protected ?\DateTime $date_created = null;
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
        $this->billing = (array) ($data['billing'] ?? []);
        $this->payment_method = (string) ($data['payment_method'] ?? '');
        $this->currency = (string) ($data['currency'] ?? 'COP');
        $this->status = (string) ($data['status'] ?? 'processing');
        $this->customer_id = (int) ($data['customer_id'] ?? 0);
        $this->meta = (array) ($data['meta'] ?? []);
        $this->items = (array) ($data['items'] ?? []);
        $this->refunds = (array) ($data['refunds'] ?? []);
        $this->date_created = $data['date_created'] ?? new WC_DateTime();
        if (isset($data['user']) && $data['user'] instanceof WP_User) {
            $this->user = $data['user'];
        } elseif ($this->customer_id > 0) {
            $u = get_userdata($this->customer_id);
            $this->user = $u instanceof WP_User ? $u : null;
        }
    }

    public function get_id(): int { return $this->id; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ($single ? '' : []); }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; return $this; }
    public function delete_meta_data($key) { unset($this->meta[$key]); return $this; }
    public function save() { return $this->id; }
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_total(): float { return $this->total; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total_refunded(): float { return $this->total_refunded; }
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
    public function get_currency(): string { return $this->currency; }
    public function get_status(): string { return $this->status; }
    public function update_status($status) { $this->status = (string) $status; return true; }
    public function get_date_created(): ?\DateTime { return $this->date_created; }
    public function set_refunds(array $refunds) { $this->refunds = $refunds; return $this; }
    public function add_refund(WC_Order_Refund $refund) { $this->refunds[] = $refund; return $this; }
    public function get_customer_id(): int { return $this->customer_id; }
    public function get_user(): ?WP_User { return $this->user; }
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
function wc_get_orders($args = []) { return $GLOBALS['wc_orders'] ?? []; }
function wc_get_products($args = []) { return []; }
function wc_get_product_id_by_sku($sku)
{
    foreach (($GLOBALS['wc_products'] ?? []) as $product) {
        if ($product->get_sku() === (string) $sku) { return $product->get_id(); }
    }
    return 0;
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
    public int $insert_id = 0;

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
        if (strpos((string) $query, 'option_name') !== false && preg_match("/option_name\s*=\s*'([^']+)'/", (string) $query, $m)) {
            return array_key_exists($m[1], $GLOBALS['wp_options'])
                ? (string) $GLOBALS['wp_options'][$m[1]]
                : null;
        }
        return null;
    }

    public function get_row($query, $output = null, $y = null) { return null; }
    public function get_results($query, $output = null) { return []; }
    public function query($query) { return 1; }

    public function insert($table, $data = [], $format = null)
    {
        $table = (string) $table;
        $GLOBALS['alegra_db'][$table][] = $data;
        $this->insert_id = count($GLOBALS['alegra_db'][$table]);
        return 1;
    }

    public function update($table, $data = [], $where = [], $format = null, $where_format = null)
    {
        return 1;
    }
}

$GLOBALS['wpdb'] = new Alegra_Mock_Wpdb();
$wpdb = $GLOBALS['wpdb'];
