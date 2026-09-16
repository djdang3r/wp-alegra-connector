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
    $GLOBALS['alegra_race']   = null;
    $GLOBALS['alegra_next_post_id'] = 1000;
    $GLOBALS['alegra_next_term_id'] = 500;

    alegra_mock_reset();

    // Base options every flow needs.
    $GLOBALS['wp_options']['alegra_connector_api_url'] = ALEGRA_MOCK_BASE;
    $GLOBALS['wp_options']['alegra_connector_email'] = 'harness@example.test';
    $GLOBALS['wp_options']['alegra_connector_token'] = 'harness-token';
    $GLOBALS['wp_options']['alegra_connector_stamp_enabled'] = true;
    $GLOBALS['wp_options']['alegra_connector_sync_method'] = 'both';
    $GLOBALS['wp_options']['alegra_connector_push_orders_enabled'] = true;
    $GLOBALS['wp_options']['alegra_connector_push_products_enabled'] = false;
    $GLOBALS['wp_options']['alegra_connector_customer_resolution_mode'] = 'auto';
    $GLOBALS['wp_options']['alegra_connector_dry_run'] = false;
    $GLOBALS['wp_options']['alegra_connector_auto_complete_order'] = true;
    $GLOBALS['wp_options']['alegra_connector_sync_images'] = false;
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
