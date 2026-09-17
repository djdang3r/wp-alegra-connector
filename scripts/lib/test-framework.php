<?php
/**
 * Tiny dependency-free test runner + world reset + object factories.
 *
 * No PHPUnit, no composer. Just assertions, a pass/fail counter, and the
 * helpers the execution tests need to build a deterministic world.
 */

declare(strict_types=1);

final class TestRunner
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $current = '';

    public static function test(string $name, callable $fn): void
    {
        self::$current = $name;
        $before = self::$failed;
        try {
            $fn();
        } catch (\Throwable $e) {
            self::fail(sprintf('threw %s: %s @ %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        }
        $mark = (self::$failed === $before) ? 'ok  ' : 'FAIL';
        echo sprintf("  [%s] %s\n", $mark, $name);
    }

    public static function assertTrue(bool $cond, string $msg): void
    {
        if ($cond) { self::$passed++; } else { self::fail($msg); }
    }

    public static function assertFalse(bool $cond, string $msg): void
    {
        self::assertTrue(!$cond, $msg);
    }

    public static function assertSame(mixed $expected, mixed $actual, string $msg): void
    {
        if ($expected === $actual) {
            self::$passed++;
        } else {
            self::fail($msg . ' (expected ' . self::dump($expected) . ', got ' . self::dump($actual) . ')');
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $msg): void
    {
        if ($expected == $actual) {
            self::$passed++;
        } else {
            self::fail($msg . ' (expected ' . self::dump($expected) . ', got ' . self::dump($actual) . ')');
        }
    }

    public static function assertNotSame(mixed $unexpected, mixed $actual, string $msg): void
    {
        if ($unexpected !== $actual) {
            self::$passed++;
        } else {
            self::fail($msg . ' (both ' . self::dump($actual) . ')');
        }
    }

    public static function assertArrayHasKey(mixed $key, array $array, string $msg): void
    {
        if (array_key_exists($key, $array)) {
            self::$passed++;
        } else {
            self::fail($msg . ' (missing key ' . self::dump($key) . '; keys: ' . implode(',', array_keys($array)) . ')');
        }
    }

    public static function assertArrayNotHasKey(mixed $key, array $array, string $msg): void
    {
        if (!array_key_exists($key, $array)) {
            self::$passed++;
        } else {
            self::fail($msg . ' (unexpected key ' . self::dump($key) . ')');
        }
    }

    public static function assertCount(int $expected, array $array, string $msg): void
    {
        self::assertSame($expected, count($array), $msg);
    }

    public static function assertInstanceOf(string $class, mixed $value, string $msg): void
    {
        self::assertTrue($value instanceof $class, $msg . ' (got ' . get_debug_type($value) . ')');
    }

    public static function assertStringContains(string $needle, string $haystack, string $msg): void
    {
        self::assertTrue(str_contains($haystack, $needle), $msg . ' (needle ' . self::dump($needle) . ' not in ' . self::dump($haystack) . ')');
    }

    public static function assertStringNotContains(string $needle, string $haystack, string $msg): void
    {
        self::assertTrue(!str_contains($haystack, $needle), $msg . ' (unexpected needle ' . self::dump($needle) . ')');
    }

    public static function fail(string $msg): void
    {
        self::$failed++;
        self::$failures[] = self::$current . ' :: ' . $msg;
        echo "        -> $msg\n";
    }

    private static function dump(mixed $v): string
    {
        if (is_bool($v)) { return $v ? 'true' : 'false'; }
        if (is_null($v)) { return 'null'; }
        if (is_scalar($v)) { return (string) $v; }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($v);
    }

    public static function summary(): int
    {
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        if (self::$failed === 0) {
            echo sprintf("EXEC-TEST OK: %d assertions passed, 0 failed\n", self::$passed);
            echo str_repeat('=', 60) . "\n";
            return 0;
        }
        echo sprintf("EXEC-TEST FAILED: %d passed, %d failed\n", self::$passed, self::$failed);
        foreach (self::$failures as $f) {
            echo "  - $f\n";
        }
        echo str_repeat('=', 60) . "\n";
        return 1;
    }
}

// ---------------------------------------------------------------------------
// World reset
// ---------------------------------------------------------------------------

function alegra_test_reset(): void
{
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
    $GLOBALS['wc_orders']     = [];
    $GLOBALS['wc_products']   = [];
    $GLOBALS['wc_tax_rates']  = [];
    $GLOBALS['alegra_race']   = null;
    $GLOBALS['alegra_next_post_id'] = 1000;
    $GLOBALS['alegra_next_term_id'] = 500;
    $GLOBALS['alegra_entity_map'] = [];
    $GLOBALS['alegra_postmeta_scans'] = 0;
    $GLOBALS['alegra_category_lookups'] = 0;
    $GLOBALS['alegra_dbdelta_calls'] = 0;

    alegra_mock_reset();

    // Base options every flow needs.
    $GLOBALS['wp_options']['alegra_connector_api_url'] = ALEGRA_MOCK_BASE;
    $GLOBALS['wp_options']['alegra_connector_email'] = 'harness@example.test';
    $GLOBALS['wp_options']['alegra_connector_token'] = 'harness-token';
    $GLOBALS['wp_options']['alegra_connector_invoice_status'] = 'draft';
    $GLOBALS['wp_options']['alegra_connector_sync_method'] = 'cron';
    $GLOBALS['wp_options']['alegra_connector_push_orders_enabled'] = false;
    $GLOBALS['wp_options']['alegra_connector_push_products_enabled'] = false;
    $GLOBALS['wp_options']['alegra_connector_customer_resolution_mode'] = 'auto';
    $GLOBALS['wp_options']['alegra_connector_dry_run'] = false;
    $GLOBALS['wp_options']['alegra_connector_auto_complete_order'] = true;
    $GLOBALS['wp_options']['alegra_connector_sync_images'] = false;

    // Configured store: every billing field enabled. Mirrors the Group A
    // seeding the settings sanitizer performs on an install. A test that needs
    // a DISABLED field overrides this option explicitly.
    $billing_enabled = [];
    foreach (\Alegra\Connector\Billing_Fields::CATALOG as $bf_key => $bf_field) {
        $billing_enabled[$bf_key] = 1;
    }
    $GLOBALS['wp_options']['alegra_connector_billing_field_catalog_enabled'] = $billing_enabled;
}

// ---------------------------------------------------------------------------
// Factories
// ---------------------------------------------------------------------------

function alegra_make_user(int $id, array $data = [], array $meta = []): WP_User
{
    $user = new WP_User($id, $data);
    $GLOBALS['wp_users'][$id] = $user;
    foreach ($meta as $k => $v) {
        update_user_meta($id, $k, $v);
    }
    return $user;
}

function alegra_make_product(int $id, array $data = []): WC_Product_Simple
{
    $product = new WC_Product_Simple($id, $data);
    $GLOBALS['wc_products'][$id] = $product;
    return $product;
}

function alegra_make_variation(int $id, int $parent_id, array $data = []): WC_Product_Variation
{
    $data['parent_id'] = $parent_id;
    $variation = new WC_Product_Variation($id, $data);
    $GLOBALS['wc_products'][$id] = $variation;
    return $variation;
}

/**
 * Build a variable WC product with custom (non-taxonomy) variation attributes.
 *
 * @param array<string, array{name:string, options:string[]}> $attributes  e.g. ['color' => ['name' => 'Color', 'options' => ['Rojo','Verde']]]
 * @param array<int, array> $variations  variation id => variation data (needs
 *        `variation_attributes` like ['attribute_color' => 'Rojo']).
 */
function alegra_make_variable_product(int $id, array $attributes, array $variations, array $data = []): WC_Product_Simple
{
    $children = [];
    foreach ($variations as $variation_id => $variation_data) {
        alegra_make_variation((int) $variation_id, $id, $variation_data);
        $children[] = (int) $variation_id;
    }

    $product_attributes = [];
    $position = 0;
    foreach ($attributes as $key => $definition) {
        $product_attributes[$key] = [
            'name' => (string) ($definition['name'] ?? $key),
            'value' => implode(' | ', (array) ($definition['options'] ?? [])),
            'position' => $position++,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 0,
        ];
    }

    $data['type'] = 'variable';
    $data['children'] = $children;
    $product = alegra_make_product($id, $data);
    update_post_meta($id, '_product_attributes', $product_attributes);

    return $product;
}

function alegra_make_order(int $id, array $data = []): WC_Order
{
    $order = new WC_Order($id, $data);
    $GLOBALS['wc_orders'][$id] = $order;
    return $order;
}

function alegra_make_refund(int $id, array $data = []): WC_Order_Refund
{
    $refund = new WC_Order_Refund($id, $data);
    $GLOBALS['wc_orders'][$id] = $refund;
    return $refund;
}

function alegra_call_private(object $object, string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($object, $args);
}

/**
 * Invoke an admin AJAX handler and capture the wp_send_json_* response it
 * emits. The stub throws Alegra_Test_JSON_Response (see wp-stubs.php) instead
 * of exiting, so the payload is observable.
 */
function alegra_capture_json(callable $fn): Alegra_Test_JSON_Response
{
    try {
        $fn();
    } catch (Alegra_Test_JSON_Response $e) {
        return $e;
    }
    throw new \RuntimeException('the handler emitted no wp_send_json_* response');
}
