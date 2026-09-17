<?php
/**
 * Execution test harness for Alegra Connector.
 *
 * Unlike scripts/smoke-load.php (which greps method names and checks return-type
 * declarations), this suite ACTUALLY RUNS the plugin's flows against a stubbed
 * WordPress/WooCommerce and a mocked Alegra API. If the code fatals, sends the
 * wrong payload, or creates a duplicate fiscal document, these tests fail.
 *
 * Dependency-free: no PHPUnit, no composer, no vendor/.
 *
 * Usage:  php scripts/exec-test.php
 *         bash scripts/exec-test.sh   (wraps php / docker)
 */

$plugin_root = getenv('ALEGRA_PLUGIN_ROOT');
if ($plugin_root === false || $plugin_root === '') {
    $plugin_root = dirname(__DIR__);
}
$plugin_root = rtrim($plugin_root, '/\\') . DIRECTORY_SEPARATOR;
$GLOBALS['alegra_plugin_root'] = $plugin_root;

if (!is_file($plugin_root . 'alegra-connector.php')) {
    fwrite(STDERR, "FATAL: alegra-connector.php not found at $plugin_root\n");
    exit(10);
}

require __DIR__ . '/lib/wp-stubs.php';
require __DIR__ . '/lib/alegra-mock.php';
require __DIR__ . '/lib/test-framework.php';

require $plugin_root . 'alegra-connector.php';

use Alegra\Connector\API\Client;
use Alegra\Connector\Logger\Logger;
use Alegra\Connector\State_Sync;
use Alegra\Connector\Sync\Controller;
use Alegra\Connector\Sync\Orders;
use Alegra\Connector\Sync\Products;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function make_logger(): Logger
{
    return new Logger();
}

function make_api(?Logger $logger = null): Client
{
    return new Client($logger);
}

function make_orders(?Logger $logger = null): Orders
{
    $logger = $logger ?? make_logger();
    return new Orders(new Client($logger), $logger);
}

function make_products(?Logger $logger = null): Products
{
    $logger = $logger ?? make_logger();
    return new Products(new Client($logger), $logger);
}

function make_controller(?Logger $logger = null): Controller
{
    $logger = $logger ?? make_logger();
    return new Controller(new Client($logger), $logger);
}

/**
 * Wire the production refund owner hook (State_Sync::register_hooks()'s
 * woocommerce_order_refunded listener) without the method's static "registered"
 * guard, so each test gets a clean hook registry.
 */
function register_refund_owner_hook(): void
{
    add_action('woocommerce_order_refunded', static function ($order_id, $refund_id = 0): void {
        State_Sync::handle_refund((int) $order_id, (int) $refund_id);
    }, 10, 2);
}

/**
 * Build an order + invoice + refund world for the refund tests.
 *
 * @return array{0:WC_Order,1:WC_Order_Refund,2:string,3:string,4:string}
 */
function build_refund_world(float $order_total, float $refund_amount, int $order_id, int $refund_id): array
{
    $invoice_id = '1nv-' . $order_id;
    $contact_id = 'c0n-' . $order_id;
    $item_id = '1t3m-' . $order_id;

    alegra_mock_seed_invoice($invoice_id, [
        'total' => $order_total,
        'balance' => $order_total,
        'status' => 'open',
        'items' => [[
            'id' => $item_id,
            'name' => 'Widget',
            'price' => $order_total,
            'quantity' => 1,
        ]],
    ]);

    $refund = alegra_make_refund($refund_id, [
        'total' => $refund_amount,
        'items' => [new WC_Order_Item([
            'product_id' => 10,
            'name' => 'Widget',
            'quantity' => 1,
            'subtotal' => $refund_amount,
            'total' => $refund_amount,
        ])],
    ]);

    $order = alegra_make_order($order_id, [
        'total' => $order_total,
        'meta' => [
            '_alegra_invoice_id' => $invoice_id,
            '_billing_alegra_contact_id' => $contact_id,
        ],
        'refunds' => [$refund],
        'items' => [new WC_Order_Item([
            'product_id' => 10,
            'name' => 'Widget',
            'quantity' => 1,
            'subtotal' => $order_total,
            'total' => $order_total,
        ])],
    ]);

    return [$order, $refund, $invoice_id, $contact_id, $item_id];
}

echo "=== Alegra Connector execution-test harness ===\n";
echo "Plugin root: $plugin_root\n";
echo "PHP:         " . PHP_VERSION . "\n\n";

// ===========================================================================
// T1 — Product push
// ===========================================================================
echo "T1 — Product push (Products::sync_to_alegra)\n";

TestRunner::test('T1.1 default tax class (empty string) does not throw and emits no tax key', function (): void {
    alegra_test_reset();
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '100', 'tax_class' => '']);

    $result = make_products()->sync_to_alegra(wc_get_product(10));
    TestRunner::assertFalse(is_wp_error($result), 'sync must not error');

    $req = alegra_mock_last_request('POST', '/items');
    TestRunner::assertTrue($req !== null, 'POST /items must have been sent');
    $body = $req['body'] ?? [];
    TestRunner::assertArrayNotHasKey('tax', $body, 'default tax class must not send a tax key');
});

TestRunner::test('T1.2 mapped tax class sends the mapped Alegra tax id', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_tax_mapping', ['iva19' => 'tax-19-uuid']);
    alegra_make_product(11, ['name' => 'Taxed', 'sku' => 'SKU-2', 'regular_price' => '119', 'tax_class' => 'iva19']);

    $result = make_products()->sync_to_alegra(wc_get_product(11));
    TestRunner::assertFalse(is_wp_error($result), 'sync must not error');

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertArrayHasKey('tax', $body, 'mapped tax class must emit a tax key');
    TestRunner::assertSame('tax-19-uuid', $body['tax'][0]['id'] ?? null, 'tax id must be the mapped one');
});

TestRunner::test('T1.3 POST /items body matches the documented item schema', function (): void {
    alegra_test_reset();
    alegra_make_product(12, ['name' => 'Schema Item', 'sku' => 'SKU-3', 'regular_price' => '50', 'tax_class' => '']);

    make_products()->sync_to_alegra(wc_get_product(12));
    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];

    TestRunner::assertSame('Schema Item', $body['name'] ?? null, 'name');
    TestRunner::assertSame('SKU-3', $body['reference'] ?? null, 'reference');
    TestRunner::assertSame('product', $body['type'] ?? null, 'type must be the WRITE enum value `product`, not the READ value `simple`');
    TestRunner::assertSame(1, $body['price'][0]['idPriceList'] ?? null, 'price[0].idPriceList');
    TestRunner::assertEquals(50.0, $body['price'][0]['price'] ?? null, 'price[0].price');
    TestRunner::assertSame('unit', $body['inventory']['unit'] ?? null, 'inventory.unit');
    TestRunner::assertArrayHasKey('unitCost', $body['inventory'] ?? [], 'inventory.unitCost is documented obligatorio');
    TestRunner::assertTrue(is_numeric($body['inventory']['unitCost'] ?? null), 'inventory.unitCost must be numeric');
    TestRunner::assertArrayHasKey('initialQuantity', $body['inventory'] ?? [], 'inventory.initialQuantity');
});

// The documented WRITE enum for item `type` (POST/PUT /items) is
// product|service|variantParent|kit. `simple` is only the READ value returned
// by GET /items — sending it on create was the original bug. This test mirrors
// the schema so a regression back to `simple` (or any non-write value) fails.
TestRunner::test('T1.4 POST /items payload conforms to the documented write enum', function (): void {
    alegra_test_reset();
    alegra_make_product(13, ['name' => 'Enum Item', 'sku' => 'SKU-4', 'regular_price' => '10', 'tax_class' => '']);

    make_products()->sync_to_alegra(wc_get_product(13));
    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];

    $write_enum = ['product', 'service', 'variantParent', 'kit'];
    TestRunner::assertTrue(
        in_array($body['type'] ?? null, $write_enum, true),
        'type must be one of the documented write values (' . implode(', ', $write_enum) . '), got: ' . var_export($body['type'] ?? null, true)
    );
    TestRunner::assertNotSame('simple', $body['type'] ?? null, '`simple` is a READ-only enum value and must never be sent on create');

    foreach (['unit', 'unitCost', 'initialQuantity'] as $field) {
        TestRunner::assertArrayHasKey($field, $body['inventory'] ?? [], 'inventory.' . $field . ' is documented obligatorio');
    }
});

TestRunner::test('T1.5 unitCost is sourced from cost meta and falls back to 0', function (): void {
    alegra_test_reset();
    alegra_make_product(14, ['name' => 'Costed', 'sku' => 'SKU-5', 'regular_price' => '80', 'tax_class' => '']);
    update_post_meta(14, '_wc_cog_cost', '12.5');

    make_products()->sync_to_alegra(wc_get_product(14));
    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertEquals(12.5, $body['inventory']['unitCost'] ?? null, 'unitCost must come from _wc_cog_cost');

    // No cost meta → documented-valid default 0 (the docs require the field to
    // be present and numeric, not > 0).
    alegra_test_reset();
    alegra_make_product(15, ['name' => 'No cost', 'sku' => 'SKU-6', 'regular_price' => '5', 'tax_class' => '']);
    make_products()->sync_to_alegra(wc_get_product(15));
    $fallback = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertEquals(0.0, $fallback['inventory']['unitCost'] ?? null, 'unitCost must fall back to 0 when no cost meta exists');
});

// ===========================================================================
// T2 — Cron sync
// ===========================================================================
echo "\nT2 — Cron sync (Controller::run_cron_sync)\n";

TestRunner::test('T2.1 cron sync completes, reaches the done heartbeat and sets last_sync', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);

    make_controller()->run_cron_sync();

    TestRunner::assertTrue(get_transient('alegra_connector_last_sync') !== false, 'alegra_connector_last_sync must be set');

    $done = false;
    foreach ($GLOBALS['wp_transients'] as $key => $value) {
        if (strpos((string) $key, 'alegra_run_') === 0 && is_array($value) && ($value['step'] ?? '') === 'done') {
            $done = true;
        }
    }
    TestRunner::assertTrue($done, 'cron sync must reach the "done" heartbeat');
    TestRunner::assertTrue(alegra_mock_count('GET', '/items') >= 1, 'cron sync must have pulled items');
});

// ===========================================================================
// T3 — Invoice creation
// ===========================================================================
echo "\nT3 — Invoice creation (Orders::create_invoice)\n";

TestRunner::test('T3.1 CO invoice payload is complete and is created as a DRAFT', function (): void {
    alegra_test_reset();
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '119']);
    update_post_meta(10, '_alegra_item_id', '1t3m-co');

    alegra_make_order(500, [
        'total' => 119.0,
        'currency' => 'COP',
        'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'buyer@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-co'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 119, 'total' => 119])],
    ]);

    $order = wc_get_order(500);
    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');

    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertArrayHasKey('client', $body, 'client');
    TestRunner::assertArrayHasKey('items', $body, 'items');
    TestRunner::assertArrayHasKey('date', $body, 'date');
    TestRunner::assertArrayHasKey('dueDate', $body, 'dueDate');
    TestRunner::assertArrayHasKey('numberTemplate', $body, 'numberTemplate');
    TestRunner::assertArrayNotHasKey('stamp', $body, 'invoices must NEVER request a stamp');
    TestRunner::assertArrayNotHasKey('paymentForm', $body, 'invoices must not send the CO paymentForm');
    TestRunner::assertSame('draft', $body['status'] ?? null, 'invoices default to draft');
    TestRunner::assertSame('c0n-co', $body['client']['id'] ?? null, 'client must reference the resolved contact');

    TestRunner::assertTrue(!empty($body['items']), 'items must not be empty');
    foreach ($body['items'] as $i => $item) {
        TestRunner::assertArrayHasKey('id', $item, "items[$i] must carry an Alegra id (obligatory)");
    }

    TestRunner::assertSame('1t3m-co', $order->get_meta('_alegra_invoice_id', true) === '' ? null : $body['items'][0]['id'], 'invoice line links the item');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_invoice_id', true) !== '', '_alegra_invoice_id must be persisted');
});

TestRunner::test('T3.2 an OPEN invoice status setting is honoured and no stamp is ever sent', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_invoice_status', 'open');
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '20']);
    update_post_meta(10, '_alegra_item_id', '1t3m-mx');

    alegra_make_order(501, [
        'total' => 20.0,
        'currency' => 'MXN',
        'payment_method' => 'stripe',
        'billing' => ['country' => 'MX', 'email' => 'mx@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-mx'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 20, 'total' => 20])],
    ]);

    make_orders()->create_invoice(wc_get_order(501));
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertArrayNotHasKey('stamp', $body, 'no invoice must request a stamp');
    TestRunner::assertArrayNotHasKey('paymentForm', $body, 'no invoice must send the CO paymentForm');
    TestRunner::assertSame('open', $body['status'] ?? null, 'the open setting must be honoured');
});

TestRunner::test('T3.3 a payment on an existing DRAFT invoice opens it before paying', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', 'ba-1');

    // The invoice was created earlier (e.g. by woocommerce_new_order) as a draft.
    alegra_mock_seed_invoice('1nv-draft', [
        'status' => 'draft',
        'total' => 10.0,
        'balance' => 10.0,
        'items' => [['id' => '1t3m-d', 'name' => 'Widget', 'price' => 10, 'quantity' => 1]],
    ]);

    $order = alegra_make_order(502, [
        'total' => 10.0,
        'currency' => 'COP',
        'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'draft@example.test'],
        'meta' => [
            '_alegra_invoice_id'          => '1nv-draft',
            '_billing_alegra_contact_id'  => 'c0n-draft',
        ],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'the existing invoice must not be re-created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/1nv-draft/open'), 'a draft invoice must be opened before the payment');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be recorded');
});

// ===========================================================================
// T4 — Refund -> credit note
// ===========================================================================
echo "\nT4 — Refund to credit note (State_Sync::handle_refund)\n";

TestRunner::test('T4.1 full refund creates EXACTLY ONE credit note even with the legacy status hook fired', function (): void {
    alegra_test_reset();
    [$order, $refund] = build_refund_world(100.0, 100.0, 500, 700);

    register_refund_owner_hook();
    // Public_ registers the WC -> Alegra hooks (and, in the fixed code, must NOT
    // register the refund status hook that caused the duplicate credit note).
    $logger = make_logger();
    $public = new \Alegra\Connector\Public\Public_(new Client($logger), $logger);

    do_action('woocommerce_order_refunded', 500, 700);
    // WC fires this second action for a full refund; the legacy handler is gone.
    do_action('woocommerce_order_status_refunded', 500);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'a full refund must create exactly ONE credit note');

    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    TestRunner::assertArrayHasKey('client', $body, 'credit note must carry a client');
    TestRunner::assertArrayHasKey('invoices', $body, 'credit note must carry invoices[]');
    TestRunner::assertSame('1nv-500', $body['invoices'][0]['id'] ?? null, 'credit note must reference the invoice');
    TestRunner::assertEquals(100.0, $body['invoices'][0]['amount'] ?? null, 'invoices[0].amount must equal the credit-note total');

    $items_total = 0.0;
    foreach (($body['items'] ?? []) as $item) {
        $items_total += (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 0);
    }
    TestRunner::assertEquals(100.0, round($items_total, 2), 'items total must equal the credited amount');
    TestRunner::assertEquals(100.0, (float) $order->get_meta('_alegra_credited_amount', true), 'cumulative credited amount must be tracked');
});

TestRunner::test('T4.2 partial refund credits only the refunded amount', function (): void {
    alegra_test_reset();
    build_refund_world(100.0, 30.0, 501, 701);
    register_refund_owner_hook();

    do_action('woocommerce_order_refunded', 501, 701);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'one credit note');
    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    TestRunner::assertEquals(30.0, $body['invoices'][0]['amount'] ?? null, 'must credit only 30, not the full invoice');
    TestRunner::assertEquals(30.0, $body['items'][0]['price'] ?? null, 'partial line price must be the refund amount');
    TestRunner::assertEquals(1, $body['items'][0]['quantity'] ?? null, 'partial line quantity must be 1');
});

TestRunner::test('T4.3 a second refund exceeding the invoice total is rejected (cap)', function (): void {
    alegra_test_reset();
    $invoice_id = '1nv-502';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 100.0, 'balance' => 100.0, 'status' => 'open',
        'items' => [['id' => '1t3m-502', 'name' => 'Widget', 'price' => 100, 'quantity' => 1]],
    ]);
    $refund1 = alegra_make_refund(801, ['total' => 60.0]);
    $refund2 = alegra_make_refund(802, ['total' => 50.0]);
    $order = alegra_make_order(502, [
        'total' => 100.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id, '_billing_alegra_contact_id' => 'c0n-502'],
        'refunds' => [$refund1],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 502, 801);
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'first refund creates one credit note');

    // The second refund arrives later: now 60 + 50 = 110 > 100 order total.
    $order->add_refund($refund2);
    $result = State_Sync::handle_refund(502, 802);
    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'over-cap refund must be rejected');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'over-cap refund must NOT create a credit note');
});

TestRunner::test('T4.4 calling handle_refund twice with the same refund id is idempotent', function (): void {
    alegra_test_reset();
    build_refund_world(100.0, 30.0, 503, 901);
    register_refund_owner_hook();

    do_action('woocommerce_order_refunded', 503, 901);
    $second = State_Sync::handle_refund(503, 901);

    TestRunner::assertFalse(is_wp_error($second), 'the second call must short-circuit cleanly');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'the same refund id must only create ONE credit note');
});

TestRunner::test('T4.5 Public_ must NOT register the duplicate refund status hook', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $public = new \Alegra\Connector\Public\Public_(new Client($logger), $logger);

    TestRunner::assertFalse(
        has_action('woocommerce_order_status_refunded', [$public, 'on_order_refunded']),
        'Public_ must not hook woocommerce_order_status_refunded (State_Sync owns refunds)'
    );

    register_refund_owner_hook();
    TestRunner::assertTrue(has_action('woocommerce_order_refunded') !== false, 'State_Sync owner hook must be wired');
});

TestRunner::test('T4.6 refund with a missing contact meta falls back to Consumidor Final (never an empty client id)', function (): void {
    alegra_test_reset();
    $cf = seed_consumidor_final();

    $invoice_id = '1nv-504';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 40.0, 'balance' => 40.0, 'status' => 'open',
        'items' => [['id' => '1t3m-504', 'name' => 'Widget', 'price' => 40, 'quantity' => 1]],
    ]);
    $refund = alegra_make_refund(902, ['total' => 40.0]);
    // Deliberately NO _billing_alegra_contact_id: the contact meta is missing.
    alegra_make_order(504, [
        'total' => 40.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id],
        'refunds' => [$refund],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 504, 902);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'the fallback must still create the credit note');
    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    $client_id = (string) ($body['client']['id'] ?? '');
    TestRunner::assertTrue($client_id !== '', 'a refund credit note must NEVER be POSTed with an empty client id');
    TestRunner::assertSame($cf, $client_id, 'a missing contact meta must resolve to Consumidor Final');
});

TestRunner::test('T4.7 refund with an unresolvable client aborts with customer_unresolved and does NOT POST', function (): void {
    alegra_test_reset();
    // No Consumidor Final contact seeded, so get_id() cannot resolve.

    $invoice_id = '1nv-505';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 25.0, 'balance' => 25.0, 'status' => 'open',
        'items' => [['id' => '1t3m-505', 'name' => 'Widget', 'price' => 25, 'quantity' => 1]],
    ]);
    $refund = alegra_make_refund(903, ['total' => 25.0]);
    alegra_make_order(505, [
        'total' => 25.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id],
        'refunds' => [$refund],
    ]);

    register_refund_owner_hook();
    $result = State_Sync::handle_refund(505, 903);

    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'an unresolvable client must abort');
    TestRunner::assertSame('customer_unresolved', $result->get_error_code(), 'error code must be customer_unresolved');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'no credit note may be POSTed without a client');
});

// ===========================================================================
// T5 — Contact resolution
// ===========================================================================
echo "\nT5 — Contact resolution (Orders::ensure_customer_synced)\n";

function seed_consumidor_final(): string
{
    $id = '22222222-2222-2222-2222-222222222222';
    alegra_mock_seed_contact($id, [
        'name' => 'Consumidor Final',
        'kindOfPerson' => 'PERSON_ENTITY',
        'regime' => 'SIMPLIFIED_REGIME',
        'identificationObject' => ['type' => 'CC', 'number' => '222222222222'],
    ]);
    return $id;
}

function make_invoice_order(int $order_id, int $user_id, string $email): WC_Order
{
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '10']);
    update_post_meta(10, '_alegra_item_id', '1t3m-5');
    return alegra_make_order($order_id, [
        'total' => 10.0,
        'currency' => 'COP',
        'billing' => ['country' => 'CO', 'email' => $email],
        'customer_id' => $user_id,
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 10, 'total' => 10])],
    ]);
}

TestRunner::test('T5.1 a NIT contact sends identificationObject (with dv) and nameObject, never extra fiscal fields', function (): void {
    alegra_test_reset();
    alegra_make_user(1, ['user_email' => 'acme@example.test', 'display_name' => 'Acme SA'], [
        'billing_alegra_idtype'         => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv'             => '1',
        'billing_first_name'            => 'Acme',
        'billing_last_name'             => 'SA',
    ]);
    $order = make_invoice_order(600, 1, 'acme@example.test');

    make_orders()->create_invoice($order);

    $contact = alegra_mock_last_request('POST', '/contacts');
    TestRunner::assertTrue($contact !== null, 'a contact must be created');
    $body = $contact['body'] ?? [];
    TestRunner::assertArrayHasKey('identificationObject', $body, 'identificationObject');
    TestRunner::assertSame('NIT', $body['identificationObject']['type'] ?? null, 'identificationObject.type');
    TestRunner::assertSame('900123456', $body['identificationObject']['number'] ?? null, 'identificationObject.number');
    TestRunner::assertSame('1', $body['identificationObject']['dv'] ?? null, 'identificationObject.dv');
    TestRunner::assertArrayNotHasKey('kindOfPerson', $body, 'kindOfPerson must not be sent');
    TestRunner::assertArrayNotHasKey('regime', $body, 'regime must not be sent');
    TestRunner::assertArrayHasKey('nameObject', $body, 'CO contacts use nameObject');
    TestRunner::assertArrayNotHasKey('name', $body, 'a contact must NOT also send name');

    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertTrue((string) ($invoice['client']['id'] ?? '') !== '', 'invoice must reference the created contact');
});

TestRunner::test('T5.2 a CC contact uses nameObject (never name)', function (): void {
    alegra_test_reset();
    alegra_make_user(3, ['user_email' => 'jane@example.test', 'display_name' => 'Jane Doe', 'first_name' => 'Jane', 'last_name' => 'Doe'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_first_name'            => 'Jane',
        'billing_last_name'             => 'Doe',
    ]);
    $order = make_invoice_order(601, 3, 'jane@example.test');

    make_orders()->create_invoice($order);

    $body = alegra_mock_last_request('POST', '/contacts')['body'] ?? [];
    TestRunner::assertArrayHasKey('nameObject', $body, 'a contact uses nameObject');
    TestRunner::assertArrayNotHasKey('name', $body, 'a contact must NOT also send name');
    TestRunner::assertSame('Jane', $body['nameObject']['firstName'] ?? null, 'nameObject.firstName');
    TestRunner::assertSame('Doe', $body['nameObject']['lastName'] ?? null, 'nameObject.lastName');
});

TestRunner::test('T5.3 no billing data falls back to Consumidor Final and does NOT create a contact', function (): void {
    alegra_test_reset();
    $cf = seed_consumidor_final();
    alegra_make_user(2, ['user_email' => 'nodata@example.test']);
    $order = make_invoice_order(602, 2, 'nodata@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'must not create a contact');
    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame($cf, $invoice['client']['id'] ?? null, 'invoice must use Consumidor Final');
});

TestRunner::test('T5.4 require_data mode returns no fallback and aborts the invoice', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_customer_resolution_mode', 'require_data');
    seed_consumidor_final();
    alegra_make_user(2, ['user_email' => 'nodata@example.test']);
    $order = make_invoice_order(603, 2, 'nodata@example.test');

    $result = make_orders()->create_invoice($order);

    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'require_data must abort');
    TestRunner::assertSame('customer_unresolved', $result->get_error_code(), 'error code must be customer_unresolved');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no invoice may be created');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'no contact may be created');
});

TestRunner::test('T5.5 always_generic mode always uses Consumidor Final', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_customer_resolution_mode', 'always_generic');
    $cf = seed_consumidor_final();
    alegra_make_user(1, ['user_email' => 'acme@example.test'], [
        'billing_alegra_idtype' => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv' => '1',
    ]);
    $order = make_invoice_order(604, 1, 'acme@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'always_generic must not create a contact');
    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame($cf, $invoice['client']['id'] ?? null, 'invoice must use Consumidor Final');
});

TestRunner::test('T5.6 an unintentional Consumidor Final fallback (auto, missing data) adds an actionable order note', function (): void {
    alegra_test_reset();
    seed_consumidor_final();
    alegra_make_user(2, ['user_email' => 'nodata@example.test']);
    $order = make_invoice_order(605, 2, 'nodata@example.test');

    make_orders()->create_invoice($order);

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('Consumidor Final', $notes, 'the fallback must be surfaced in an order note');
    TestRunner::assertStringContains('no tiene tipo de documento ni número de documento', $notes, 'the note must name the missing field(s)');
    TestRunner::assertStringContains('vuelve a facturar', $notes, 'the note must be actionable');
});

TestRunner::test('T5.7 always_generic mode does NOT add the Consumidor Final note (intentional)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_customer_resolution_mode', 'always_generic');
    seed_consumidor_final();
    // Even a customer with NO identification must not be noted: the merchant
    // chose the generic consumer on purpose.
    alegra_make_user(2, ['user_email' => 'nodata@example.test']);
    $order = make_invoice_order(606, 2, 'nodata@example.test');

    make_orders()->create_invoice($order);

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringNotContains('Consumidor Final', $notes, 'always_generic must never add the fallback note');
});

// ===========================================================================
// T6 — Concurrency (the lock)
// ===========================================================================
echo "\nT6 — Lock concurrency (Controller::acquire_lock)\n";

TestRunner::test('T6.1 a held lock blocks a second sequential acquire', function (): void {
    alegra_test_reset();
    $a = Controller::acquire_lock('seq', 300);
    $b = Controller::acquire_lock('seq', 300);

    TestRunner::assertTrue($a !== false, 'first acquire wins');
    TestRunner::assertFalse($b, 'second acquire must lose');

    Controller::release_lock('seq', $a);
    $c = Controller::acquire_lock('seq', 300);
    TestRunner::assertTrue($c !== false, 'lock must be reacquirable after release');
});

TestRunner::test('T6.2 a stale (expired) lock is reclaimed', function (): void {
    alegra_test_reset();
    add_option('alegra_lock_stale', ['token' => 'old', 'expires' => time() - 10], '', 'no');

    $token = Controller::acquire_lock('stale', 300);

    TestRunner::assertTrue($token !== false, 'expired lock must be reclaimed');
    $stored = get_option('alegra_lock_stale');
    TestRunner::assertSame($token, $stored['token'] ?? null, 'the reclaimed lock must carry the new token');
});

TestRunner::test('T6.3 two INTERLEAVED acquire_lock calls: exactly one wins', function (): void {
    alegra_test_reset();

    $wins = [];
    $GLOBALS['alegra_race'] = [
        'fired' => false,
        'fn' => static function () use (&$wins): void {
            // "Worker B" runs at the critical moment of worker A's acquire.
            $wins[] = Controller::acquire_lock('race', 300);
        },
    ];

    $a = Controller::acquire_lock('race', 300);
    $GLOBALS['alegra_race'] = null;
    $wins[] = $a;

    $nonFalse = array_values(array_filter($wins, static fn ($w) => $w !== false));
    TestRunner::assertSame(1, count($nonFalse), 'exactly ONE worker may win the interleaved acquire');
});

// ===========================================================================
// T7 — Invoice failure handling + reduced billing catalog
// ===========================================================================
echo "\nT7 — Plain invoice error handling + reduced billing catalog\n";

TestRunner::test('T7.1 a 400 from /invoices surfaces as an error and persists no invoice id', function (): void {
    alegra_test_reset();
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '119']);
    update_post_meta(10, '_alegra_item_id', '1t3m-t7');

    $order = alegra_make_order(700, [
        'total' => 119.0,
        'currency' => 'COP',
        'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'buyer@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-t7'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 119, 'total' => 119])],
    ]);

    alegra_mock_fail('POST', '/invoices', 400, [
        'message' => 'La factura no se pudo crear',
    ]);

    $result = make_orders()->create_invoice($order);

    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'the API call must surface an error');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_invoice_id', true), 'a failed POST must not persist an invoice id');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the 400 must not be retried into a duplicate');
});

TestRunner::test('T7.2 the billing catalog is reduced to the identification and the payload carries no extra fiscal fields', function (): void {
    $keys = array_keys(\Alegra\Connector\Billing_Fields::CATALOG);
    sort($keys);
    TestRunner::assertSame(['dv', 'identification', 'idtype'], $keys, 'the catalog must only hold the identification fields');

    alegra_test_reset();
    $user = alegra_make_user(9, ['user_email' => 'co@example.test', 'display_name' => 'Ana Perez'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_first_name'            => 'Ana',
        'billing_last_name'             => 'Perez',
    ]);

    $payload = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertFalse(is_wp_error($payload), 'the payload must build');
    TestRunner::assertArrayNotHasKey('kindOfPerson', $payload, 'kindOfPerson must never be sent');
    TestRunner::assertArrayNotHasKey('regime', $payload, 'regime must never be sent');
    TestRunner::assertArrayNotHasKey('stamp', $payload, 'a contact payload must never carry stamp');
    TestRunner::assertSame('CC', $payload['identificationObject']['type'] ?? null, 'CO sends identificationObject.type');
    TestRunner::assertSame('1234567890', $payload['identificationObject']['number'] ?? null, 'CO sends identificationObject.number');
});

// ===========================================================================
// T8 — Security batch 1a (AC-29, AC-30, AC-32, AC-67)
// ===========================================================================
echo "\nT8 — Security batch 1a\n";

TestRunner::test('T8.1 AC-29 remote Alegra error text is stripped of HTML before it reaches a sink', function (): void {
    alegra_test_reset();
    alegra_mock_fail('GET', '/items', 400, [
        'message' => '<img src=x onerror=alert(1)>Alegra rechazó la factura',
    ]);

    $result = make_api()->get_items();
    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'a 400 must surface a WP_Error');
    $message = $result->get_error_message();
    TestRunner::assertStringNotContains('<', $message, 'the message must not carry raw HTML');
    TestRunner::assertStringNotContains('onerror', $message, 'the message must not carry event handlers');
    TestRunner::assertStringContains('Alegra rechazó', $message, 'the human-readable text must survive');

    // The three admin templates + shared JS must inject the message with .text().
    foreach ([
        'templates/admin-import.php',
        'templates/admin-monitor.php',
        'admin/assets/js/admin.js',
    ] as $rel) {
        $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . $rel);
        TestRunner::assertStringNotContains('$(\'<div class="ac-notice ', $src, "$rel must not build the notice from a concatenated HTML string");
        TestRunner::assertStringContains('.text(msg)', $src, "$rel must inject the notice with .text(msg)");
    }
});

TestRunner::test('T8.2 AC-30 an empty masked-secret submission keeps the stored token; a new one replaces it', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_token', 'stored-token-123');

    $keep = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_masked_secret('', 'alegra_connector_token');
    TestRunner::assertSame('stored-token-123', $keep, 'an empty submission must keep the stored token');

    $replace = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_masked_secret('new-token-456', 'alegra_connector_token');
    TestRunner::assertSame('new-token-456', $replace, 'a non-empty submission must replace the token');

    // The settings page must not echo the stored token back into the HTML.
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringNotContains(
        'name="alegra_connector_token" value="<?php echo esc_attr(get_option(',
        $tpl,
        'the token must not be rendered into the page value attribute'
    );
    TestRunner::assertStringContains(
        'name="alegra_connector_token" value=""',
        $tpl,
        'the token field must render an empty value'
    );
});

TestRunner::test('T8.3 AC-32 a replayed identical webhook is ignored the second time', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $body = json_encode(['subject' => 'unknown-event', 'message' => ['id' => 'x']]);

    $first = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(200, $first->get_status(), 'the first delivery must be accepted');
    TestRunner::assertFalse((bool) (($first->get_data())['duplicate'] ?? false), 'the first delivery must not be flagged as a duplicate');

    $second = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(200, $second->get_status(), 'the replay must still be acked (so Alegra stops retrying)');
    TestRunner::assertTrue((bool) (($second->get_data())['duplicate'] ?? false), 'the identical replay must be flagged as a duplicate');

    // A different body is not a replay.
    $third = $receiver->handle(new WP_REST_Request(json_encode(['subject' => 'unknown-event', 'message' => ['id' => 'y']])));
    TestRunner::assertFalse((bool) (($third->get_data())['duplicate'] ?? false), 'a different body must not be treated as a replay');
});

TestRunner::test('T8.4 AC-67 CSV cells starting with a formula trigger are neutralised', function (): void {
    $safe = [\Alegra\Connector\Admin\Admin_Dashboard::class, 'csv_safe_cell'];
    TestRunner::assertSame("'=cmd|'/c calc'!A0", $safe('=cmd|\'/c calc\'!A0'), '= must be prefixed');
    TestRunner::assertSame("'+123", $safe('+123'), '+ must be prefixed');
    TestRunner::assertSame("'-123", $safe('-123'), '- must be prefixed');
    TestRunner::assertSame("'@SUM(A1)", $safe('@SUM(A1)'), '@ must be prefixed');
    TestRunner::assertSame("'\tTAB", $safe("\tTAB"), 'TAB must be prefixed');
    TestRunner::assertSame('Widget normal', $safe('Widget normal'), 'ordinary text must be untouched');
    TestRunner::assertSame('', $safe(''), 'empty stays empty');

    // The export must route every cell through the helper.
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("array_map([self::class, 'csv_safe_cell']", $src, 'exports must apply csv_safe_cell');
});

// ===========================================================================
// T9 — Correctness batch 1b (AC-25/26/38/40/41/42/46/48/49/51/52)
// ===========================================================================
echo "\nT9 — Correctness batch 1b\n";

TestRunner::test('T9.1 AC-26/AC-38 a disabled field is neither required nor sent', function (): void {
    alegra_test_reset();

    // dv disabled: a NIT without a verification digit must validate.
    update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, [
        'idtype' => 1, 'identification' => 1,
    ]);
    $values = [
        'idtype'         => 'NIT',
        'identification' => '900123456',
        'dv'             => '',
    ];
    $validated = \Alegra\Connector\Billing_Fields::validate($values);
    TestRunner::assertFalse(is_wp_error($validated), 'a disabled dv must not be required');

    // With dv ENABLED the same input must be rejected.
    update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, [
        'idtype' => 1, 'identification' => 1, 'dv' => 1,
    ]);
    $rejected = \Alegra\Connector\Billing_Fields::validate($values);
    TestRunner::assertInstanceOf(\WP_Error::class, $rejected, 'an enabled dv must be required for a NIT');
    TestRunner::assertSame('invalid_dv', $rejected->get_error_code(), 'error code');

    // A disabled dv must never reach the payload.
    update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, [
        'idtype' => 1, 'identification' => 1,
    ]);
    $order = alegra_make_order(900, [
        'total' => 10.0, 'currency' => 'COP',
        'billing' => ['country' => 'CO', 'email' => 'x@example.test', 'first_name' => 'Jane', 'last_name' => 'Doe'],
        'meta' => [
            '_billing_alegra_idtype'         => 'NIT',
            '_billing_alegra_identification' => '900123456',
            '_billing_alegra_dv'             => '7',
        ],
    ]);
    $payload = \Alegra\Connector\Billing_Fields::build_guest_contact_payload($order);
    TestRunner::assertFalse(is_wp_error($payload), 'the payload must build');
    TestRunner::assertArrayNotHasKey('dv', $payload['identificationObject'] ?? [], 'a disabled dv must not be sent');
});

TestRunner::test('T9.2 AC-38 a disabled field is never saved', function (): void {
    alegra_test_reset();
    update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, [
        'idtype' => 1, 'dv' => 1,
    ]);

    $_POST = ['billing_alegra_identification' => '1234567890'];
    $ref = new ReflectionMethod(\Alegra\Connector\Billing_Fields::class, 'save_registration');
    $ref->setAccessible(true);
    $ref->invoke(null, 55);
    $_POST = [];

    TestRunner::assertSame('', (string) get_user_meta(55, 'billing_alegra_identification', true), 'a disabled field must not be persisted');
});

TestRunner::test('T9.3 AC-40 the customers count is not the products count when the customers lock is held', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);
    update_option('alegra_connector_sync_customers', true);
    alegra_mock_seed_item('item-a', ['name' => 'A', 'reference' => 'A', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 10]]]);
    alegra_mock_seed_item('item-b', ['name' => 'B', 'reference' => 'B', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 20]]]);

    // Hold the customers lock so its block never assigns $customers_result.
    Controller::acquire_lock('alegra_sync_running_customers', 300);

    make_controller()->run_cron_sync();

    $message = '';
    foreach ($GLOBALS['wp_transients'] as $key => $value) {
        if (strpos((string) $key, 'alegra_run_') === 0 && is_array($value) && ($value['step'] ?? '') === 'done') {
            $message = (string) ($value['message'] ?? '');
        }
    }

    TestRunner::assertTrue(preg_match('/Completado: (\d+) productos, (\d+) clientes/', $message, $m) === 1, 'the done heartbeat must carry the counts: ' . $message);
    TestRunner::assertTrue((int) ($m[1] ?? 0) > 0, 'products must have been imported');
    TestRunner::assertSame(0, (int) ($m[2] ?? -1), 'the customers count must be 0 when the lock is held (not the products count)');
});

TestRunner::test('T9.4 AC-41 a payment-method change after the baseline still syncs', function (): void {
    alegra_test_reset();
    alegra_make_user(7, ['user_email' => 'pm@example.test'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
    ]);
    $order = alegra_make_order(910, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'customer_id' => 7,
        'meta' => ['_alegra_invoice_id' => '1nv-910'],
    ]);

    State_Sync::handle_payment_method_change(910);
    TestRunner::assertSame('bacs', (string) $order->get_meta('_alegra_last_payment_method', true), 'the baseline must be durable order meta');
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/1nv-910'), 'the first save must not sync');

    // Simulate the old 300s transient expiring, then a later change.
    $GLOBALS['wp_transients'] = [];
    $baseline = (string) $order->get_meta('_alegra_last_payment_method', true);
    alegra_make_order(910, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'cod', 'customer_id' => 7,
        'meta' => ['_alegra_invoice_id' => '1nv-910', '_alegra_last_payment_method' => $baseline],
    ]);

    State_Sync::handle_payment_method_change(910);
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/1nv-910'), 'a change made after the baseline must still sync');
});

TestRunner::test('T9.5 AC-42 a cached Consumidor Final id does not hit the API', function (): void {
    alegra_test_reset();
    $cf = seed_consumidor_final();

    TestRunner::assertSame($cf, \Alegra\Connector\Consumidor_Final::get_id(), 'CF resolves');
    $calls = alegra_mock_count('GET', '/contacts');

    $again = \Alegra\Connector\Consumidor_Final::resolve();
    TestRunner::assertSame($cf, $again, 'resolve must return the cached id');
    TestRunner::assertSame($calls, alegra_mock_count('GET', '/contacts'), 'resolve must not call the API when cached');
});

TestRunner::test('T9.6 AC-46 a missing/zero Alegra price does not wipe the WC price', function (): void {
    alegra_test_reset();
    $product = alegra_make_product(30, ['name' => 'Keep', 'sku' => 'K', 'regular_price' => '50', 'tax_class' => '']);

    alegra_call_private(make_products(), 'update_product_from_alegra', $product, [
        'id' => 'item-30', 'name' => 'Keep', 'status' => 'active', 'price' => [],
    ]);
    TestRunner::assertSame('50', (string) $product->get_regular_price(), 'a zero/missing price must not overwrite the WC price');

    alegra_call_private(make_products(), 'update_product_from_alegra', $product, [
        'id' => 'item-30', 'name' => 'Keep', 'status' => 'active',
        'price' => [['idPriceList' => 1, 'price' => 75]],
    ]);
    TestRunner::assertSame('75', (string) $product->get_regular_price(), 'a usable price must still update');
});

TestRunner::test('T9.7 AC-48 a coupon discount is mapped so the invoice total matches the order total', function (): void {
    alegra_test_reset();
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '100']);
    update_post_meta(10, '_alegra_item_id', '1t3m-disc');

    $order = alegra_make_order(920, [
        'total' => 90.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'd@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-disc'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 90])],
    ]);

    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');

    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertEquals(100.0, $body['items'][0]['price'] ?? null, 'price stays pre-discount');
    TestRunner::assertEquals(10.0, $body['items'][0]['discount'] ?? null, 'a 10% coupon must map to items[].discount');

    TestRunner::assertEquals(90.0, (float) ($result['total'] ?? 0), 'the invoice total must equal the WC order total');
    TestRunner::assertEquals(90.0, (float) $order->get_total(), 'order total');
});

TestRunner::test('T9.8 AC-51 the inventory pull requests mode=advanced', function (): void {
    alegra_test_reset();
    make_products()->sync_inventory_from_alegra();

    $req = alegra_mock_last_request('GET', '/items');
    TestRunner::assertTrue($req !== null, 'an items request must be sent');
    TestRunner::assertSame('advanced', $req['query']['mode'] ?? null, 'the inventory pull must request advanced mode');
});

TestRunner::test('T9.9 AC-25 the Consumidor Final cache is invalidated on settings change and delete', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Consumidor_Final::register_invalidation_hooks();
    $cf = seed_consumidor_final();

    TestRunner::assertSame($cf, \Alegra\Connector\Consumidor_Final::get_id(), 'CF resolves and caches');
    TestRunner::assertTrue(\Alegra\Connector\Consumidor_Final::is_consumidor_final($cf), 'the id is cached');

    // (b) a settings change drops the cache.
    update_option('alegra_connector_consumidor_final_manual_override', true);
    TestRunner::assertFalse(\Alegra\Connector\Consumidor_Final::is_consumidor_final($cf), 'a settings change must drop the CF cache');

    // Re-resolve, then (a) a delete-client webhook drops it again.
    TestRunner::assertSame($cf, \Alegra\Connector\Consumidor_Final::get_id(), 'CF resolves again');
    $logger = make_logger();
    $handlers = new \Alegra\Connector\Webhooks\Handlers(new Client($logger), $logger);
    $handlers->process_event('delete-client', ['client' => ['id' => $cf]]);
    TestRunner::assertFalse(\Alegra\Connector\Consumidor_Final::is_consumidor_final($cf), 'a delete-client webhook must drop the CF cache');
});

TestRunner::test('T9.10 the identification shape is gated on the account country and no extra fiscal fields are sent', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_company_country', 'MX');
    $user = alegra_make_user(8, ['user_email' => 'mx@example.test', 'display_name' => 'MX Person'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
    ]);

    $payload = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertFalse(is_wp_error($payload), 'the payload must build');
    TestRunner::assertArrayNotHasKey('kindOfPerson', $payload, 'non-CO must not send kindOfPerson');
    TestRunner::assertArrayNotHasKey('identificationObject', $payload, 'non-CO must not send identificationObject');
    TestRunner::assertArrayNotHasKey('regime', $payload, 'non-CO must not send regime');
    TestRunner::assertSame('1234567890', $payload['identification'] ?? null, 'non-CO uses the flat identification');

    update_option('alegra_connector_company_country', 'CO');
    $co_payload = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertArrayNotHasKey('kindOfPerson', $co_payload, 'CO must not send kindOfPerson');
    TestRunner::assertArrayNotHasKey('regime', $co_payload, 'CO must not send regime');
    TestRunner::assertArrayHasKey('identificationObject', $co_payload, 'CO sends identificationObject');
    TestRunner::assertSame('CC', $co_payload['identificationObject']['type'] ?? null, 'CO identificationObject.type');
});

TestRunner::test('T9.11 AC-52 "Run now" schedules a single event and the dead dispatcher is gone', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains('wp_schedule_single_event(time(), $hook)', $admin, 'Run now must schedule a single event');
    TestRunner::assertStringNotContains("'alegra_manual_run'", $admin, 'the unregistered recurrence must be gone');

    $main = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'alegra-connector.php');
    TestRunner::assertStringNotContains("add_action('alegra_manual_run'", $main, 'the dead alegra_manual_run dispatcher must be removed');
});

// ===========================================================================
// T10 — Batch 1c: lifecycle, data integrity and dead features
// ===========================================================================
echo "\nT10 — Batch 1c (lifecycle, imports, dead features)\n";

TestRunner::test('T10.1 AC-27 find_orphans fetches the Alegra category list once, not per term', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 40; $i++) {
        alegra_mock_seed_category('cat-' . $i, ['name' => 'Cat ' . $i]);
    }
    $t1 = wp_insert_term('Ventas', 'product_cat');
    update_term_meta($t1['term_id'], 'alegra_category_id', 'cat-1');
    $t2 = wp_insert_term('Hogar', 'product_cat');
    update_term_meta($t2['term_id'], 'alegra_category_id', 'cat-2');
    $t3 = wp_insert_term('Borrada', 'product_cat');
    update_term_meta($t3['term_id'], 'alegra_category_id', 'cat-999');

    $categories = new \Alegra\Connector\Sync\Categories(make_api(), make_logger());
    $orphans = $categories->find_orphans();

    TestRunner::assertCount(1, $orphans, 'only the term whose Alegra category is gone is an orphan');
    TestRunner::assertSame('cat-999', $orphans[0]['alegra_id'] ?? null, 'the orphan is the deleted category');

    // Count EVERY category request, list or per-item. 40 categories at 30/page
    // = 2 paginated calls; the old code added one call per WC term (3 more).
    $calls = 0;
    foreach ($GLOBALS['alegra_mock_requests'] as $req) {
        if ($req['method'] === 'GET' && strpos((string) $req['path'], '/item-categories') === 0) {
            $calls++;
        }
    }
    TestRunner::assertSame(2, $calls, 'find_orphans must paginate, not call per term (got ' . $calls . ' calls)');
});

TestRunner::test('T10.2 AC-45 an imported variation gets its attributes and SKU', function (): void {
    alegra_test_reset();
    $parent_alegra = 'item-parent-1';
    $child_alegra = 'item-child-1';

    alegra_mock_seed_item($parent_alegra, [
        'name' => 'Camiseta',
        'reference' => 'CAM',
        'type' => 'variantParent',
        'status' => 'active',
        'price' => [['idPriceList' => '1', 'price' => 70000]],
        'variantAttributes' => [
            ['id' => '1', 'name' => 'Color', 'options' => [['id' => '1', 'value' => 'Rojo'], ['id' => '2', 'value' => 'Verde']]],
            ['id' => '2', 'name' => 'Talla', 'options' => [['id' => '4', 'value' => 'XS'], ['id' => '5', 'value' => 'M']]],
        ],
        'itemVariants' => [['id' => $child_alegra]],
    ]);
    alegra_mock_seed_item($child_alegra, [
        'id' => $child_alegra,
        'name' => 'Camiseta / Rojo / XS',
        'reference' => 'CAM-ROJO-XS',
        'type' => 'variant',
        'status' => 'active',
        'price' => [['idPriceList' => '1', 'price' => 70000]],
        'variantAttributes' => [
            ['id' => '1', 'name' => 'Color', 'options' => [['id' => '1', 'value' => 'Rojo']]],
            ['id' => '2', 'name' => 'Talla', 'options' => [['id' => '4', 'value' => 'XS']]],
        ],
    ]);

    make_products()->import_from_alegra();

    $variation_id = 0;
    $parent_wc_id = 0;
    foreach ($GLOBALS['wp_posts'] as $post) {
        if (($post->post_type ?? '') === 'product_variation') {
            $variation_id = (int) $post->ID;
        }
        if (($post->post_type ?? '') === 'product') {
            $parent_wc_id = (int) $post->ID;
        }
    }

    TestRunner::assertTrue($variation_id > 0, 'a product_variation post must be created');
    TestRunner::assertSame('Rojo', get_post_meta($variation_id, 'attribute_color', true), 'the Color attribute must be set');
    TestRunner::assertSame('XS', get_post_meta($variation_id, 'attribute_talla', true), 'the Talla attribute must be set');
    TestRunner::assertSame('CAM-ROJO-XS', get_post_meta($variation_id, '_sku', true), 'the variation SKU must be set');

    $attrs = get_post_meta($parent_wc_id, '_product_attributes', true);
    TestRunner::assertTrue(
        is_array($attrs) && isset($attrs['color'], $attrs['talla']),
        'the parent must register the color/talla attributes so the variation is selectable'
    );
});

// Alegra models a variable product as ONE `variantParent` carrying
// `variantAttributes` (min 1) + one `itemVariants` entry per variation. Children
// are separate `variant` items created BY Alegra; `subitems` is kit-only and
// `variant` is not in the WRITE enum. The mock validates the payload, so a
// regression to the old variantParent+subitems / standalone type=variant shape
// fails here.
echo "\nT14 — Variable product push (variantParent + variantAttributes + itemVariants)\n";

TestRunner::test('T14.1 a variable product push creates a variantParent with variantAttributes + itemVariants', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [
            ['id' => 'opt-rojo', 'value' => 'Rojo'],
            ['id' => 'opt-verde', 'value' => 'Verde'],
        ],
    ]);
    alegra_make_variable_product(30, [
        'color' => ['name' => 'Color', 'options' => ['Rojo', 'Verde']],
    ], [
        31 => ['name' => 'Camiseta Rojo', 'sku' => 'CAM-R', 'regular_price' => '10', 'variation_attributes' => ['attribute_color' => 'Rojo'], 'manage_stock' => true, 'stock' => 3],
        32 => ['name' => 'Camiseta Verde', 'sku' => 'CAM-V', 'regular_price' => '10', 'variation_attributes' => ['attribute_color' => 'Verde'], 'manage_stock' => true, 'stock' => 5],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);
    update_option('alegra_connector_warehouse_enabled', true);
    update_option('alegra_connector_warehouse_id', '3');

    $result = make_products()->sync_to_alegra(wc_get_product(30));
    TestRunner::assertFalse(is_wp_error($result), 'push must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    $req = alegra_mock_last_request('POST', '/items');
    TestRunner::assertTrue($req !== null, 'POST /items must be sent');
    $body = $req['body'] ?? [];

    TestRunner::assertSame('variantParent', $body['type'] ?? null, 'type must be the WRITE enum value variantParent');
    TestRunner::assertArrayNotHasKey('subitems', $body, 'subitems is a kit-only field and must never be sent for a variantParent');
    TestRunner::assertCount(1, $body['variantAttributes'] ?? [], 'exactly one variantAttributes entry');
    TestRunner::assertSame('attr-color', $body['variantAttributes'][0]['id'] ?? null, 'the attribute must reference the EXISTING Alegra attribute id');
    $option_ids = array_map(static fn ($o) => $o['id'] ?? null, $body['variantAttributes'][0]['options'] ?? []);
    sort($option_ids);
    TestRunner::assertSame(['opt-rojo', 'opt-verde'], $option_ids, 'the attribute options must reference EXISTING Alegra option ids');

    TestRunner::assertCount(2, $body['itemVariants'] ?? [], 'one itemVariants entry per WC variation');
    TestRunner::assertSame('3', $body['itemVariants'][0]['inventory']['warehouses'][0]['id'] ?? null, 'create sends the configured warehouse');
    TestRunner::assertSame(3, $body['itemVariants'][0]['inventory']['warehouses'][0]['initialQuantity'] ?? null, 'create sends the per-variation initialQuantity');

    // Child ids returned by the response are mapped back onto the variations.
    TestRunner::assertTrue((string) get_post_meta(30, '_alegra_item_id', true) !== '', 'the parent must be mapped');
    TestRunner::assertTrue((string) get_post_meta(31, '_alegra_item_id', true) !== '', 'variation 31 must be mapped to a child id');
    TestRunner::assertTrue((string) get_post_meta(32, '_alegra_item_id', true) !== '', 'variation 32 must be mapped to a child id');
    TestRunner::assertNotSame(
        (string) get_post_meta(31, '_alegra_item_id', true),
        (string) get_post_meta(32, '_alegra_item_id', true),
        'each variation must map to a distinct child id'
    );
});

TestRunner::test('T14.2 the attribute catalog is fetched once and existing attributes/options are not re-created', function (): void {
    alegra_test_reset();
    // Alegra stores "Color" (mixed case); WC uses "COLOR" — must match, not create.
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo'], ['id' => 'opt-verde', 'value' => 'Verde']],
    ]);
    alegra_mock_seed_variant_attribute('attr-size', [
        'name' => 'Talla',
        'options' => [['id' => 'opt-xs', 'value' => 'XS'], ['id' => 'opt-m', 'value' => 'M']],
    ]);
    alegra_make_variable_product(40, [
        'color' => ['name' => 'COLOR', 'options' => ['Rojo', 'Verde']],
        'size' => ['name' => 'Talla', 'options' => ['XS', 'M']],
    ], [
        41 => ['variation_attributes' => ['attribute_color' => 'Rojo', 'attribute_size' => 'XS'], 'regular_price' => '10'],
        42 => ['variation_attributes' => ['attribute_color' => 'Verde', 'attribute_size' => 'M'], 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(40));
    TestRunner::assertFalse(is_wp_error($result), 'push must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    TestRunner::assertCount(1, alegra_mock_requests('GET', '/variant-attributes'), 'the catalog must be fetched exactly once, not per variation');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/variant-attributes'), 'an existing attribute (matched case-insensitively) must not be created');
    TestRunner::assertCount(0, alegra_mock_requests('PUT', '/variant-attributes/attr-color'), 'an existing option must not trigger an update');
});

TestRunner::test('T14.3 a missing Alegra attribute is created once and referenced by the parent', function (): void {
    alegra_test_reset();
    alegra_make_variable_product(50, [
        'color' => ['name' => 'Color', 'options' => ['Rojo', 'Verde']],
    ], [
        51 => ['variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10'],
        52 => ['variation_attributes' => ['attribute_color' => 'Verde'], 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(50));
    TestRunner::assertFalse(is_wp_error($result), 'push must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    $creates = alegra_mock_requests('POST', '/variant-attributes');
    TestRunner::assertCount(1, $creates, 'the missing attribute must be created exactly once');
    TestRunner::assertSame('Color', $creates[0]['body']['name'] ?? null, 'the created attribute carries the WC label');
    $created_options = array_map(static fn ($o) => $o['value'] ?? null, $creates[0]['body']['options'] ?? []);
    TestRunner::assertSame(['Rojo', 'Verde'], $created_options, 'all options are created with the attribute');

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertTrue(!empty($body['variantAttributes'][0]['id']), 'the parent must reference the newly created attribute id');
    TestRunner::assertNotSame('', (string) ($body['variantAttributes'][0]['options'][0]['id'] ?? ''), 'the parent must reference the newly created option ids');
});

TestRunner::test('T14.4 an uncreatable variation attribute fails the whole push before any item write', function (): void {
    alegra_test_reset();
    alegra_mock_fail('POST', '/variant-attributes', 400, ['code' => 31002, 'message' => 'boom']);
    alegra_make_variable_product(60, [
        'material' => ['name' => 'Material', 'options' => ['Algodón']],
    ], [
        61 => ['variation_attributes' => ['attribute_material' => 'Algodón'], 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(60));
    TestRunner::assertTrue(is_wp_error($result), 'the push must fail when a variation attribute cannot be created');
    TestRunner::assertSame('variant_attribute_create_failed', $result->get_error_code(), 'the failure must be explicit');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/items'), 'no parent may be created after an attribute failure');
});

TestRunner::test('T14.5 a variation with an unresolvable value fails with a descriptive error', function (): void {
    alegra_test_reset();
    // The parent declares only Rojo/Verde, but the variation uses Azul.
    alegra_make_variable_product(65, [
        'color' => ['name' => 'Color', 'options' => ['Rojo', 'Verde']],
    ], [
        66 => ['variation_attributes' => ['attribute_color' => 'Azul'], 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(65));
    TestRunner::assertTrue(is_wp_error($result), 'a variation whose value is not in the attribute must fail');
    TestRunner::assertSame('variable_product_attribute_missing', $result->get_error_code(), 'the failure must be explicit');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/items'), 'nothing is written when a variation cannot be mapped');
});

TestRunner::test('T14.6 the update path sends a valid variantParent and never re-sends inventory', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo'], ['id' => 'opt-verde', 'value' => 'Verde']],
    ]);
    alegra_make_variable_product(70, [
        'color' => ['name' => 'Color', 'options' => ['Rojo', 'Verde']],
    ], [
        71 => ['variation_attributes' => ['attribute_color' => 'Rojo'], 'manage_stock' => true, 'stock' => 3, 'regular_price' => '10'],
        72 => ['variation_attributes' => ['attribute_color' => 'Verde'], 'manage_stock' => true, 'stock' => 5, 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);
    update_post_meta(70, '_alegra_item_id', 'parent-1');
    update_post_meta(71, '_alegra_item_id', 'child-1');
    update_post_meta(72, '_alegra_item_id', 'child-2');
    update_option('alegra_connector_warehouse_enabled', true);
    update_option('alegra_connector_warehouse_id', '3');

    $result = make_products()->sync_to_alegra(wc_get_product(70));
    TestRunner::assertFalse(is_wp_error($result), 'update must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    $req = alegra_mock_last_request('PUT', '/items/parent-1');
    TestRunner::assertTrue($req !== null, 'PUT /items/parent-1 must be sent');
    $body = $req['body'] ?? [];
    TestRunner::assertSame('variantParent', $body['type'] ?? null, 'update must keep type=variantParent');
    TestRunner::assertArrayNotHasKey('subitems', $body, 'subitems must never be sent');
    TestRunner::assertCount(2, $body['itemVariants'] ?? [], 'update re-emits every variation');
    TestRunner::assertSame('child-1', $body['itemVariants'][0]['id'] ?? null, 'an existing variation is referenced by its Alegra child id');
    TestRunner::assertArrayNotHasKey('inventory', $body['itemVariants'][0] ?? [], 'update must never re-send inventory (R3 hotfix)');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/items'), 'update must not create a second parent');
});

TestRunner::test('T14.7 a variable product with no variations fails cleanly', function (): void {
    alegra_test_reset();
    alegra_make_product(80, ['name' => 'Empty', 'sku' => 'EMPTY', 'type' => 'variable', 'regular_price' => '10', 'children' => []]);
    update_post_meta(80, '_product_attributes', ['color' => ['name' => 'Color', 'value' => 'Rojo', 'is_variation' => 1, 'is_taxonomy' => 0]]);

    $result = make_products()->sync_to_alegra(wc_get_product(80));
    TestRunner::assertTrue(is_wp_error($result), 'a variable product without variations must fail');
    TestRunner::assertSame('variable_product_no_variations', $result->get_error_code(), 'the failure must be explicit');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/items'), 'nothing is created');
});

TestRunner::test('T14.8 more than 100 variations is refused before any write', function (): void {
    alegra_test_reset();
    $variations = [];
    for ($i = 0; $i < 101; $i++) {
        $variations[1000 + $i] = ['variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10'];
    }
    alegra_make_variable_product(85, ['color' => ['name' => 'Color', 'options' => ['Rojo']]], $variations, ['name' => 'Big', 'sku' => 'BIG', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(85));
    TestRunner::assertTrue(is_wp_error($result), 'more than 100 variations must be refused');
    TestRunner::assertSame('variable_product_too_many_variants', $result->get_error_code(), 'the failure must be explicit');
    TestRunner::assertCount(0, alegra_mock_requests('POST', '/items'), 'nothing is created');
});

TestRunner::test('T14.9 a non-inventariable variation gets no inventory block', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo'], ['id' => 'opt-verde', 'value' => 'Verde']],
    ]);
    alegra_make_variable_product(90, [
        'color' => ['name' => 'Color', 'options' => ['Rojo', 'Verde']],
    ], [
        91 => ['variation_attributes' => ['attribute_color' => 'Rojo'], 'manage_stock' => false, 'regular_price' => '10'],
        92 => ['variation_attributes' => ['attribute_color' => 'Verde'], 'manage_stock' => true, 'stock' => 7, 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);
    update_option('alegra_connector_warehouse_enabled', true);
    update_option('alegra_connector_warehouse_id', '3');

    $result = make_products()->sync_to_alegra(wc_get_product(90));
    TestRunner::assertFalse(is_wp_error($result), 'push must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertArrayNotHasKey('inventory', $body['itemVariants'][0] ?? [], 'a non-inventariable variation must not carry inventory');
    TestRunner::assertArrayHasKey('inventory', $body['itemVariants'][1] ?? [], 'an inventariable variation must carry inventory');
});

TestRunner::test('T14.10 child ids are recovered via GET /items?variantParent_id when the response omits them', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_mock_variant_children_in_response'] = false;
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo']],
    ]);
    alegra_make_variable_product(95, [
        'color' => ['name' => 'Color', 'options' => ['Rojo']],
    ], [
        96 => ['variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10'],
    ], ['name' => 'Camiseta', 'sku' => 'CAM', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(95));
    TestRunner::assertFalse(is_wp_error($result), 'push must not error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
    TestRunner::assertTrue((string) get_post_meta(96, '_alegra_item_id', true) !== '', 'the variation must be mapped from the GET fallback');
    TestRunner::assertTrue(count(alegra_mock_requests('GET', '/items')) > 0, 'the plugin must fall back to GET /items');
    $GLOBALS['alegra_mock_variant_children_in_response'] = true;
});

TestRunner::test('T10.4 AC-64 the CSV export paginates past 500 rows', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 600; $i++) {
        alegra_make_product($i, ['name' => 'P' . $i, 'sku' => 'S' . $i, 'regular_price' => '10']);
    }
    $rows = \Alegra\Connector\Admin\Admin_Dashboard::collect_export_rows('products');
    TestRunner::assertSame(600, count($rows), 'all 600 products must be exported (got ' . count($rows) . ')');

    for ($i = 1; $i <= 600; $i++) {
        alegra_make_user(10000 + $i, ['user_email' => 'u' . $i . '@example.test', 'display_name' => 'U' . $i]);
    }
    $customer_rows = \Alegra\Connector\Admin\Admin_Dashboard::collect_export_rows('customers');
    TestRunner::assertSame(600, count($customer_rows), 'all 600 customers must be exported (got ' . count($customer_rows) . ')');
});

TestRunner::test('T10.5 AC-37 the dead push-queue subsystem is gone', function (): void {
    TestRunner::assertFalse(
        file_exists($GLOBALS['alegra_plugin_root'] . 'includes/Push_Queue.php'),
        'Push_Queue.php must be removed'
    );
    TestRunner::assertFalse(
        file_exists($GLOBALS['alegra_plugin_root'] . 'templates/admin-push-queue.php'),
        'the push-queue template must be removed'
    );

    $schema = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'includes/Schema.php');
    TestRunner::assertFalse(
        (bool) preg_match('/CREATE TABLE[^;]*alegra_push_queue/i', $schema),
        'the queue table must not be created'
    );
    TestRunner::assertFalse(
        (bool) preg_match('/CREATE TABLE[^;]*alegra_pull_queue/i', $schema),
        'the dead pull-queue table must not be created'
    );
    TestRunner::assertFalse(
        (bool) preg_match('/CREATE TABLE[^;]*alegra_push_log/i', $schema),
        'the dead push-log table must not be created'
    );
    TestRunner::assertStringNotContains('create_push_queue_table', $schema, 'the queue table creator must be gone');

    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains('alegra_push_queue', $admin, 'no admin code may query the queue table');
    TestRunner::assertStringNotContains('render_push_queue_page', $admin, 'the push-queue page must be removed');
});

TestRunner::test('T10.6 AC-65/AC-66 dead async methods and the dead webhook hook are gone', function (): void {
    foreach (['admin/Admin/Admin_Dashboard.php', 'alegra-connector.php', 'uninstall.php'] as $rel) {
        $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . $rel);
        TestRunner::assertStringNotContains('do_async_import', $src, "$rel must not reference do_async_import");
        TestRunner::assertStringNotContains('do_async_sync_all', $src, "$rel must not reference do_async_sync_all");
        TestRunner::assertStringNotContains('alegra_connector_process_webhook', $src, "$rel must not reference the dead webhook hook");
    }
});

TestRunner::test('T10.7 AC-16 the previously dead settings now take effect', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'woocommerce');
    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertTrue(!empty($result['skipped']), 'inventory_source=woocommerce must skip the stock pull');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'no item fetch when WooCommerce owns inventory');

    alegra_test_reset();
    update_option('alegra_connector_field_mapping', [
        'default_unit'       => 'kg',
        'regular_price_list' => '7',
        'default_status'     => 'inactive',
    ]);
    update_option('alegra_connector_warehouse_enabled', true);
    update_option('alegra_connector_warehouse_id', 'wh-1');
    alegra_make_product(30, ['name' => 'Mapped', 'sku' => 'M1', 'regular_price' => '10', 'stock' => 4, 'manage_stock' => true]);
    make_products()->sync_to_alegra(wc_get_product(30));

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertSame('kg', $body['inventory']['unit'] ?? null, 'default_unit must be used');
    TestRunner::assertSame(7, $body['price'][0]['idPriceList'] ?? null, 'regular_price_list must be used');
    TestRunner::assertSame('inactive', $body['status'] ?? null, 'default_status must be used');
    TestRunner::assertSame('wh-1', $body['inventory']['warehouses'][0]['id'] ?? null, 'the configured warehouse must be sent');
});

TestRunner::test('T10.8 AC-28/AC-57 uninstall drops every table, deletes the missed options and is multisite-aware', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'uninstall.php');

    foreach (['alegra_tombstones', 'alegra_runs', 'alegra_entity_map'] as $table) {
        TestRunner::assertStringContains($table, $src, "uninstall must drop $table");
    }
    foreach ([
        'alegra_connector_schema_version',
        'alegra_connector_consumidor_final_contact_id',
        'alegra_kill_switch',
        'alegra_connector_dry_run',
        'alegra_connector_invoice_status',
        'alegra_connector_push_category_strategy',
        'alegra_connector_import_category_parent',
        'alegra_connector_customer_resolution_mode',
        'alegra_connector_log_suffix',
        'alegra_connector_disconnected_reason',
    ] as $option) {
        TestRunner::assertStringContains("delete_option('$option')", $src, "uninstall must delete $option");
    }

    TestRunner::assertStringContains('get_sites(', $src, 'uninstall must loop every site on multisite');
    TestRunner::assertStringContains('switch_to_blog(', $src, 'uninstall must switch per site');
});

// ===========================================================================
// T11 — Performance and scale (batch 2)
// ===========================================================================
echo "\nT11 — Performance and scale (batch 2)\n";

TestRunner::test('T11.1 AC-06 a second Schema::migrate() issues zero dbDelta', function (): void {
    alegra_test_reset();

    \Alegra\Connector\Schema::migrate();
    $first = (int) ($GLOBALS['alegra_dbdelta_calls'] ?? 0);
    TestRunner::assertTrue($first > 0, 'the first migrate must create the tables');

    \Alegra\Connector\Schema::migrate();
    TestRunner::assertSame($first, (int) ($GLOBALS['alegra_dbdelta_calls'] ?? 0), 'a second migrate must issue zero dbDelta');
});

TestRunner::test('T11.2 AC-07 importing items writes the entity map; later lookups skip postmeta', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 3; $i++) {
        alegra_mock_seed_item('item-' . $i, ['name' => 'P' . $i, 'reference' => 'SKU-' . $i, 'type' => 'simple', 'price' => 100]);
    }

    $result = make_products()->import_from_alegra();
    TestRunner::assertTrue(!is_wp_error($result), 'import must not error');

    $map_rows = array_values(array_filter(
        $GLOBALS['alegra_entity_map'],
        static fn ($r) => $r['wc_entity_type'] === 'product'
    ));
    TestRunner::assertSame(3, count($map_rows), 'three product mappings must be written');

    $GLOBALS['alegra_postmeta_scans'] = 0;
    $found = \Alegra\Connector\Entity_Map::find_wc_id('item', 'item-2', 'product');
    TestRunner::assertTrue($found !== null, 'the mapped product must resolve');
    TestRunner::assertSame(0, (int) $GLOBALS['alegra_postmeta_scans'], 'a mapped lookup must not scan postmeta');
});

TestRunner::test('T11.3 AC-19 the product import resumes from the persisted cursor', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_products_import_cursor', 90, false);
    alegra_mock_seed_item('item-1', ['name' => 'P1', 'reference' => 'SKU-1', 'type' => 'simple']);

    make_products()->import_from_alegra();

    $req = alegra_mock_last_request('GET', '/items');
    TestRunner::assertTrue($req !== null, 'the import must fetch items');
    TestRunner::assertSame('90', (string) ($req['query']['start'] ?? ''), 'the import must resume from the cursor, not page 1');
});

TestRunner::test('T11.4 AC-19 a bounded run persists the cursor for the next run', function (): void {
    alegra_test_reset();
    for ($i = 0; $i < 30; $i++) {
        alegra_mock_seed_item('bulk-' . $i, ['name' => 'B' . $i, 'reference' => 'B-' . $i, 'type' => 'simple']);
    }
    update_option('alegra_connector_import_max_pages', 1, false);

    $result = make_products()->import_from_alegra();
    TestRunner::assertTrue(!is_wp_error($result), 'import must not error');
    TestRunner::assertSame(30, (int) get_option('alegra_connector_products_import_cursor', 0), 'the cursor must point at the next page');
});

TestRunner::test('T11.5 AC-24 the rate-limit window is 150 and resets after the window', function (): void {
    alegra_test_reset();
    $client = make_api();

    $ref = new ReflectionClass(Client::class);
    TestRunner::assertSame(150, $ref->getConstant('RATE_LIMIT_PER_MIN'), 'the documented limit is 150/min');

    for ($i = 0; $i < 150; $i++) {
        alegra_call_private($client, 'increment_rate_limit');
    }
    TestRunner::assertTrue(alegra_call_private($client, 'is_rate_limited'), '150 requests fill the window');

    $w = (array) get_option('alegra_connector_rate_window', []);
    $w['start'] = time() - 61;
    update_option('alegra_connector_rate_window', $w, false);

    TestRunner::assertFalse(alegra_call_private($client, 'is_rate_limited'), 'the window must reset once 60s elapse');
});

TestRunner::test('T11.6 AC-63 N products sharing a category issue ONE lookup', function (): void {
    alegra_test_reset();
    $cat = ['id' => 'cat-1', 'name' => 'Ropa'];
    for ($i = 1; $i <= 4; $i++) {
        alegra_mock_seed_item('c-' . $i, ['name' => 'P' . $i, 'reference' => 'C-' . $i, 'type' => 'simple', 'itemCategory' => $cat]);
    }
    $GLOBALS['alegra_category_lookups'] = 0;

    make_products()->import_from_alegra();

    TestRunner::assertSame(1, (int) $GLOBALS['alegra_category_lookups'], 'four products sharing a category must resolve it with one lookup');
});

// ===========================================================================
// T12 — Auto vs manual order uploads
// ===========================================================================
echo "\nT12 — Auto vs manual order uploads (push_orders_enabled is the only gate)\n";

/**
 * Build a fresh world (user + order that create_invoice() can process) and a
 * Public_ instance wired with the given outbound push setting.
 *
 * @return array{0:int,1:\Alegra\Connector\Public\Public_}
 */
function build_public_for_orders(bool $auto_upload, int $order_id, string $sync_method = 'cron'): array
{
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', $auto_upload);
    update_option('alegra_connector_sync_method', $sync_method);
    alegra_make_user(1, ['user_email' => 'auto@example.test', 'display_name' => 'Auto SA'], [
        'billing_alegra_idtype'         => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv'             => '1',
        'billing_first_name'            => 'Auto',
        'billing_last_name'             => 'SA',
    ]);
    make_invoice_order($order_id, 1, 'auto@example.test');
    $logger = make_logger();
    $public = new \Alegra\Connector\Public\Public_(new Client($logger), $logger);
    return [$order_id, $public];
}

TestRunner::test('T12.1 manual is the default and Public_ registers no order hooks when the option is absent', function (): void {
    alegra_test_reset();
    TestRunner::assertFalse(
        (bool) get_option('alegra_connector_push_orders_enabled', false),
        'push_orders_enabled must default to false (manual)'
    );

    // Simulate a fresh install where the option was never stored.
    unset($GLOBALS['wp_options']['alegra_connector_push_orders_enabled']);
    $logger = make_logger();
    $public = new \Alegra\Connector\Public\Public_(new Client($logger), $logger);

    TestRunner::assertFalse(has_action('woocommerce_new_order', [$public, 'on_new_order']), 'no auto hook when the option is absent');
    TestRunner::assertFalse(has_action('woocommerce_payment_complete', [$public, 'on_payment_complete']), 'no payment hook when the option is absent');
});

TestRunner::test('T12.2 auto OFF: firing the WC order hooks creates ZERO invoices', function (): void {
    [$order_id, $public] = build_public_for_orders(false, 700);
    TestRunner::assertFalse(has_action('woocommerce_new_order', [$public, 'on_new_order']), 'the new-order hook must not be registered when auto is off');

    do_action('woocommerce_new_order', $order_id);
    do_action('woocommerce_payment_complete', $order_id);
    do_action('woocommerce_order_status_completed', $order_id);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'auto off must create no invoice');
});

TestRunner::test('T12.3 auto ON: firing the WC order hooks creates EXACTLY ONE invoice', function (): void {
    [$order_id, $public] = build_public_for_orders(true, 701);
    TestRunner::assertTrue(has_action('woocommerce_new_order', [$public, 'on_new_order']) !== false, 'the new-order hook must be registered when auto is on');

    do_action('woocommerce_new_order', $order_id);
    do_action('woocommerce_payment_complete', $order_id);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'auto on must create exactly one invoice');
});

TestRunner::test('T12.4 auto OFF: the manual path still creates one invoice', function (): void {
    [$order_id] = build_public_for_orders(false, 702);

    // Exactly what Admin_Dashboard::ajax_sync_single() does for an order.
    $result = make_controller()->sync_entity('order', $order_id, 'create');

    TestRunner::assertFalse(is_wp_error($result), 'the manual invoice must succeed');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'manual invoicing must work with auto off');
});

TestRunner::test('T12.5 regression: sync_method=cron no longer suppresses the auto upload', function (): void {
    [$order_id] = build_public_for_orders(true, 703, 'cron');

    do_action('woocommerce_new_order', $order_id);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), "push_orders_enabled=true must upload even when sync_method='cron'");
});

TestRunner::test('T12.6 regression: sync_method=disabled no longer suppresses the auto upload', function (): void {
    [$order_id] = build_public_for_orders(true, 704, 'disabled');

    do_action('woocommerce_new_order', $order_id);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), "push_orders_enabled=true must upload even when sync_method='disabled'");
});

TestRunner::test('T12.7 sync_method gates the INBOUND cron: disabled skips the run', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_method', 'disabled');
    update_option('alegra_connector_sync_products', true);

    make_controller()->run_cron_sync();

    TestRunner::assertFalse(get_transient('alegra_connector_last_sync') !== false, 'disabled must skip the cron pull');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'disabled must not pull items');
});

// ===========================================================================
// T13 — R2/R3 hotfix: the whole `inventory` object only on CREATE
//
// R2: never re-send `initialQuantity` on UPDATE (it reset current stock).
// R3: never send a PARTIAL `inventory` on UPDATE either — the docs mark
//     unit/unitCost/initialQuantity as obligatorios when the object is
//     present, and `PUT /items/{id}` is a partial update ("solo enviar los
//     campos que cambiarán"), so the safe payload omits `inventory` entirely.
// ===========================================================================
echo "\nT13 — R2/R3 hotfix (inventory on create only)\n";

TestRunner::test('T-hotfix-1 create sends inventory.initialQuantity with the WC stock', function (): void {
    alegra_test_reset();
    alegra_make_product(40, [
        'name' => 'New', 'sku' => 'NEW-1', 'regular_price' => '25',
        'stock' => 7, 'manage_stock' => true,
    ]);

    $result = make_products()->sync_to_alegra(wc_get_product(40));
    TestRunner::assertFalse(is_wp_error($result), 'create must not error');

    $req = alegra_mock_last_request('POST', '/items');
    TestRunner::assertTrue($req !== null, 'POST /items must have been sent');
    $body = $req['body'] ?? [];
    TestRunner::assertArrayHasKey('initialQuantity', $body['inventory'] ?? [], 'create must send inventory.initialQuantity');
    TestRunner::assertSame(7, $body['inventory']['initialQuantity'] ?? null, 'create must send the WC stock as initialQuantity');
    TestRunner::assertArrayHasKey('unitCost', $body['inventory'] ?? [], 'create must send inventory.unitCost (obligatorio)');
    TestRunner::assertSame('product', $body['type'] ?? null, 'create must use the write enum value product');
});

TestRunner::test('T-hotfix-2 update does NOT send inventory.initialQuantity', function (): void {
    alegra_test_reset();
    alegra_make_product(41, [
        'name' => 'Existing', 'sku' => 'EX-1', 'regular_price' => '30',
        'stock' => 3, 'manage_stock' => true,
    ]);
    update_post_meta(41, '_alegra_item_id', 'item-41');

    $result = make_products()->sync_to_alegra(wc_get_product(41));
    TestRunner::assertFalse(is_wp_error($result), 'update must not error');

    $req = alegra_mock_last_request('PUT', '/items/item-41');
    TestRunner::assertTrue($req !== null, 'PUT /items/item-41 must have been sent');
    $body = $req['body'] ?? [];
    TestRunner::assertArrayNotHasKey('inventory', $body, 'update must NOT send an inventory object at all (R3)');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/items'), 'an update must not create a new item');
});

TestRunner::test('T-hotfix-3 update still sends name, price, tax, category and unit (no regression)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_tax_mapping', ['iva19' => 'tax-h3']);
    $term = wp_insert_term('Hogar', 'product_cat');
    update_term_meta($term['term_id'], 'alegra_category_id', 'cat-h3');

    alegra_make_product(42, [
        'name' => 'Regress', 'sku' => 'RG-1', 'regular_price' => '44',
        'tax_class' => 'iva19', 'category_ids' => [$term['term_id']],
        'stock' => 9, 'manage_stock' => true,
    ]);
    update_post_meta(42, '_alegra_item_id', 'item-42');

    make_products()->sync_to_alegra(wc_get_product(42));

    $body = alegra_mock_last_request('PUT', '/items/item-42')['body'] ?? [];
    TestRunner::assertSame('Regress', $body['name'] ?? null, 'name must survive the update');
    TestRunner::assertEquals(44.0, $body['price'][0]['price'] ?? null, 'price must survive the update');
    TestRunner::assertSame('tax-h3', $body['tax'][0]['id'] ?? null, 'tax must survive the update');
    TestRunner::assertSame('cat-h3', $body['itemCategory']['id'] ?? null, 'the commercial category must be sent under itemCategory');
    TestRunner::assertArrayNotHasKey('category', $body, 'the accounting category must not receive the item-category id');
    TestRunner::assertArrayNotHasKey('inventory', $body, 'update must omit inventory entirely (R3): a partial {unit} is undocumented');
});

// A variation has no standalone WRITE type — it only exists as an `itemVariants`
// entry on its `variantParent`. Pushing a variation must therefore sync the
// PARENT as a variantParent, never create a standalone `type=variant` item.
TestRunner::test('T-hotfix-4 a variation push syncs the parent as variantParent, never a standalone type=variant', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo']],
    ]);
    alegra_make_variable_product(50, [
        'color' => ['name' => 'Color', 'options' => ['Rojo']],
    ], [
        51 => ['name' => 'Parent - Rojo', 'sku' => 'VAR-CREATE', 'variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10', 'stock' => 4, 'manage_stock' => true],
    ], ['name' => 'Parent', 'sku' => 'PAR-1', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(51));
    TestRunner::assertFalse(is_wp_error($result), 'a variation push must sync its parent: ' . (is_wp_error($result) ? $result->get_error_message() : ''));

    $writes = array_values(array_filter($GLOBALS['alegra_mock_requests'], static function ($r) {
        return in_array($r['method'], ['POST', 'PUT'], true) && strpos($r['path'], '/items') === 0;
    }));
    TestRunner::assertCount(1, $writes, 'exactly one item write (the parent variantParent)');
    TestRunner::assertSame('variantParent', $writes[0]['body']['type'] ?? null, 'the write must be a variantParent');
    foreach ($writes as $write) {
        TestRunner::assertNotSame('variant', $write['body']['type'] ?? null, 'type=variant must never be sent on write');
    }
});

// R3 regression guard: the exact UPDATE payload key set must never contain
// `inventory`. This is the assertion that fails if anyone reintroduces either
// the old partial `{unit}` or the R2 `initialQuantity` on update.
TestRunner::test('T-hotfix-5 UPDATE payload has no inventory key (simple) and a variation update targets the parent', function (): void {
    alegra_test_reset();
    alegra_make_product(70, [
        'name' => 'R3 simple', 'sku' => 'R3-S', 'regular_price' => '15',
        'stock' => 11, 'manage_stock' => true,
    ]);
    update_post_meta(70, '_alegra_item_id', 'item-r3-s');
    make_products()->sync_to_alegra(wc_get_product(70));
    $simple = alegra_mock_last_request('PUT', '/items/item-r3-s')['body'] ?? [];
    TestRunner::assertArrayNotHasKey('inventory', $simple, 'simple UPDATE must not carry inventory');

    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-verde', 'value' => 'Verde']],
    ]);
    alegra_make_variable_product(80, [
        'color' => ['name' => 'Color', 'options' => ['Verde']],
    ], [
        81 => ['name' => 'R3 parent - Verde', 'sku' => 'R3-V', 'variation_attributes' => ['attribute_color' => 'Verde'], 'regular_price' => '10', 'stock' => 5, 'manage_stock' => true],
    ], ['name' => 'R3 parent', 'sku' => 'R3-P', 'regular_price' => '10']);
    update_post_meta(80, '_alegra_item_id', 'parent-r3');
    update_post_meta(81, '_alegra_item_id', 'child-r3');

    $variation_result = make_products()->sync_to_alegra(wc_get_product(81));
    TestRunner::assertFalse(is_wp_error($variation_result), 'a variation update must sync its parent: ' . (is_wp_error($variation_result) ? $variation_result->get_error_message() : ''));
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/items/child-r3'), 'a variation update must never be sent to the child id');
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/items/parent-r3'), 'the variation update must target the parent');
    $parent_body = alegra_mock_last_request('PUT', '/items/parent-r3')['body'] ?? [];
    TestRunner::assertArrayNotHasKey('inventory', $parent_body['itemVariants'][0] ?? [], 'update must not re-send per-variant inventory');
});

// ===========================================================================
// T15 — Dry Run must never persist fake success
//
// Client::request() blocks write verbs and returns ['dry_run' => true, ...].
// That marker is an ARRAY, so an is_wp_error()-only check treats it as success.
// Each test proves the caller does NOT persist state when the marker comes back.
// ===========================================================================
echo "\nT15 — Dry Run correctness (no ghost state)\n";

TestRunner::test('T15.1 refund credit note under dry run does not mark the refund credited', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    [$order] = build_refund_world(100.0, 100.0, 600, 900);
    register_refund_owner_hook();

    $result = State_Sync::handle_refund(600, 900);

    TestRunner::assertTrue(\Alegra\Connector\API\Client::is_dry_run_response($result), 'the dry-run marker must be returned unchanged');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_credited_amount', true), '_alegra_credited_amount must NOT be written');
    TestRunner::assertSame('', (string) $order->get_meta(sprintf(State_Sync::REFUND_META_FMT, 900), true), 'the refund credit-note meta must NOT be written');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'no credit note may be POSTed in dry run');

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('modo de prueba', $notes, 'an honest dry-run note must be added');
    TestRunner::assertStringNotContains('creada por reembolso', $notes, 'the false "credit note created" note must NOT be added');
});

TestRunner::test('T15.2 ajax_record_payment under dry run does not save a payment id nor report success', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    update_option('alegra_connector_payment_account_id', 'acct-1');
    $order = alegra_make_order(960, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'meta' => ['_alegra_invoice_id' => '1nv-960'],
    ]);

    $_POST['order_id'] = 960;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn() => $admin->ajax_record_payment());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the response must be a success envelope');
    TestRunner::assertSame(true, $resp->payload['dry_run'] ?? null, 'the response must be flagged dry_run');
    TestRunner::assertStringContains('NO se registró', (string) ($resp->payload['message'] ?? ''), 'the message must say the payment was NOT registered');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), '_alegra_payment_id must NOT be written');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be POSTed in dry run');
    TestRunner::assertStringNotContains('registrado', implode("\n", $order->get_notes()), 'no false success note may be added');
});

TestRunner::test('T15.3 payment-method change under dry run does not claim the update happened', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    alegra_make_user(7, ['user_email' => 'pm@example.test'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
    ]);
    $order = alegra_make_order(970, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'cod', 'customer_id' => 7,
        'meta' => ['_alegra_invoice_id' => '1nv-970', '_alegra_last_payment_method' => 'bacs'],
    ]);

    State_Sync::handle_payment_method_change(970);

    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/1nv-970'), 'the PUT must be blocked by dry run');
    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringNotContains('actualizado en Alegra', $notes, 'the false "updated" note must NOT be added');
    TestRunner::assertStringContains('modo de prueba', $notes, 'an honest dry-run note must be added');
});

TestRunner::test('T15.4 ajax_delete_webhooks under dry run keeps the local subscription list', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    $subs = [
        ['id' => 'wh-1', 'event' => 'new-invoice'],
        ['id' => 'wh-2', 'event' => 'new-client'],
    ];
    update_option('alegra_connector_webhook_subscriptions', $subs, false);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn() => $admin->ajax_delete_webhooks());

    TestRunner::assertSame(true, $resp->payload['dry_run'] ?? null, 'the response must be flagged dry_run');
    TestRunner::assertSame(0, $resp->payload['deleted'] ?? null, 'nothing may be counted as deleted');
    TestRunner::assertSame(0, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-1'), 'no DELETE may be sent in dry run');
    TestRunner::assertSame($subs, get_option('alegra_connector_webhook_subscriptions', null), 'the local subscription list must be preserved');
});

TestRunner::test('T15.5 variable product dry run returns the marker, not a misleading error', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo']],
    ]);
    alegra_make_variable_product(90, [
        'color' => ['name' => 'Color', 'options' => ['Rojo']],
    ], [
        91 => ['name' => 'Dry parent - Rojo', 'sku' => 'DRY-V', 'variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10', 'stock' => 4, 'manage_stock' => true],
    ], ['name' => 'Dry parent', 'sku' => 'DRY-P', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(91));

    TestRunner::assertFalse(is_wp_error($result), 'a dry-run variable push must NOT return a WP_Error: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
    TestRunner::assertTrue(\Alegra\Connector\API\Client::is_dry_run_response($result), 'the dry-run marker must be returned');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/items'), 'no item may be POSTed in dry run');
});

// --- T15.6 — the same four paths with dry run OFF persist normally ----------

TestRunner::test('T15.6a refund credit note with dry run OFF still marks the refund credited', function (): void {
    alegra_test_reset();
    [$order] = build_refund_world(100.0, 100.0, 601, 901);
    register_refund_owner_hook();

    $result = State_Sync::handle_refund(601, 901);

    TestRunner::assertFalse(is_wp_error($result), 'the credit note must be created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'one credit note must be POSTed');
    TestRunner::assertEquals(100.0, (float) $order->get_meta('_alegra_credited_amount', true), '_alegra_credited_amount must be written');
    TestRunner::assertStringContains('creada por reembolso', implode("\n", $order->get_notes()), 'the real success note must be added');
});

TestRunner::test('T15.6b ajax_record_payment with dry run OFF persists the payment normally', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', 'acct-1');
    $order = alegra_make_order(961, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'meta' => ['_alegra_invoice_id' => '1nv-961'],
    ]);

    $_POST['order_id'] = 961;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn() => $admin->ajax_record_payment());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the response must be a success envelope');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be POSTed');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_payment_id', true) !== '', '_alegra_payment_id must be written');
    TestRunner::assertStringContains('registrado', implode("\n", $order->get_notes()), 'the real success note must be added');
});

TestRunner::test('T15.6c payment-method change with dry run OFF updates Alegra and notes it', function (): void {
    alegra_test_reset();
    alegra_make_user(7, ['user_email' => 'pm@example.test'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
    ]);
    $order = alegra_make_order(971, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'cod', 'customer_id' => 7,
        'meta' => ['_alegra_invoice_id' => '1nv-971', '_alegra_last_payment_method' => 'bacs'],
    ]);

    State_Sync::handle_payment_method_change(971);

    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/1nv-971'), 'the invoice must be updated');
    TestRunner::assertStringContains('actualizado en Alegra', implode("\n", $order->get_notes()), 'the real success note must be added');
});

TestRunner::test('T15.6d ajax_delete_webhooks with dry run OFF deletes and clears the list', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_subscriptions', [['id' => 'wh-1', 'event' => 'new-invoice']], false);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn() => $admin->ajax_delete_webhooks());

    TestRunner::assertSame(1, $resp->payload['deleted'] ?? null, 'the webhook must be counted as deleted');
    TestRunner::assertSame(1, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-1'), 'the DELETE must be sent');
    TestRunner::assertSame(null, get_option('alegra_connector_webhook_subscriptions', null), 'the local option must be cleared');
});

TestRunner::test('T15.6e variable product with dry run OFF still creates the variantParent', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-verde', 'value' => 'Verde']],
    ]);
    alegra_make_variable_product(92, [
        'color' => ['name' => 'Color', 'options' => ['Verde']],
    ], [
        93 => ['name' => 'Real parent - Verde', 'sku' => 'REAL-V', 'variation_attributes' => ['attribute_color' => 'Verde'], 'regular_price' => '10', 'stock' => 5, 'manage_stock' => true],
    ], ['name' => 'Real parent', 'sku' => 'REAL-P', 'regular_price' => '10']);

    $result = make_products()->sync_to_alegra(wc_get_product(93));

    TestRunner::assertFalse(is_wp_error($result), 'the variantParent must be created: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
    TestRunner::assertSame(1, alegra_mock_count('POST', '/items'), 'exactly one item write (the parent)');
    TestRunner::assertSame('variantParent', alegra_mock_last_request('POST', '/items')['body']['type'] ?? null, 'the write must be a variantParent');
});

// ===========================================================================
// T16 — Products batch 1: itemCategory, inventory_source, stock landing, pull
//
// Bug 1: the push sent the /item-categories id under `category` (Alegra's
//        ACCOUNTING category); the commercial category is `itemCategory`.
//        The import read `category` and created WC terms named after accounts.
// Bug 2: the import wrote stock ignoring `alegra_connector_inventory_source`.
// Bug 3: `_manage_stock` was never set (WC ignores `_stock` without it) and
//        `_stock_status` was never written (WC does not derive it).
// Bug 4: `sync_inventory_from_alegra()` had no production caller.
// ===========================================================================
echo "\nT16 — Products batch 1 (itemCategory, inventory_source, stock, pull)\n";

TestRunner::test('T16.1 push sends the commercial category as itemCategory, never as the accounting category', function (): void {
    alegra_test_reset();
    // Unique term id: resolve_alegra_category_id() caches per WC term id in a
    // process-static array, so reusing the default 500 leaks a previous test.
    $GLOBALS['alegra_next_term_id'] = 9101;
    $term = wp_insert_term('Ropa', 'product_cat');
    update_term_meta($term['term_id'], 'alegra_category_id', 'cat-com');
    alegra_make_product(60, [
        'name' => 'CatPush', 'sku' => 'CP-1', 'regular_price' => '10',
        'category_ids' => [$term['term_id']],
    ]);

    make_products()->sync_to_alegra(wc_get_product(60));

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertSame('cat-com', $body['itemCategory']['id'] ?? null, 'the commercial category must be sent under itemCategory');
    TestRunner::assertArrayNotHasKey('category', $body, 'the accounting `category` field must not receive the item-category id');
});

TestRunner::test('T16.2 a variantParent push also uses itemCategory, never category', function (): void {
    alegra_test_reset();
    alegra_mock_seed_variant_attribute('attr-color', [
        'name' => 'Color',
        'options' => [['id' => 'opt-rojo', 'value' => 'Rojo']],
    ]);
    $GLOBALS['alegra_next_term_id'] = 9102;
    $term = wp_insert_term('Ropa', 'product_cat');
    update_term_meta($term['term_id'], 'alegra_category_id', 'cat-var');
    alegra_make_variable_product(61, [
        'color' => ['name' => 'Color', 'options' => ['Rojo']],
    ], [
        62 => ['name' => 'P - Rojo', 'sku' => 'VP-1', 'variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10'],
    ], ['name' => 'VarParent', 'sku' => 'VP-P', 'regular_price' => '10', 'category_ids' => [$term['term_id']]]);

    make_products()->sync_to_alegra(wc_get_product(61));

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertSame('cat-var', $body['itemCategory']['id'] ?? null, 'the variantParent must send the commercial category under itemCategory');
    TestRunner::assertArrayNotHasKey('category', $body, 'the variantParent must not send the accounting category');
});

TestRunner::test('T16.3 import reads itemCategory and ignores the accounting category', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('item-ic', [
        'name' => 'ImpCat', 'reference' => 'IC-1', 'type' => 'simple',
        'itemCategory' => ['id' => 'cat-com', 'name' => 'Ropa'],
        'category'     => ['id' => 'acc-1', 'name' => 'Ventas'],
    ]);

    make_products()->import_from_alegra();

    $names = array_map(static fn ($t) => $t->name, array_values($GLOBALS['wp_terms']));
    TestRunner::assertTrue(in_array('Ropa', $names, true), 'the itemCategory name must create/assign a WC term');
    TestRunner::assertFalse(in_array('Ventas', $names, true), 'the accounting category must NOT create a WC term');
});

TestRunner::test('T16.4 with inventory_source=woocommerce an import does not write stock or manage_stock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'woocommerce');
    // manage_stock=true is the regression trigger: the old code wrote the
    // Alegra quantity whenever the product managed stock, ignoring the setting.
    alegra_make_product(70, ['name' => 'WC wins', 'sku' => 'WCW-1', 'regular_price' => '10', 'stock' => 5, 'manage_stock' => true]);
    update_post_meta(70, '_alegra_item_id', 'item-wcw');

    make_products()->import_single_item_public([
        'id' => 'item-wcw', 'name' => 'WC wins', 'reference' => 'WCW-1', 'type' => 'simple',
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => 99],
    ]);

    $product = wc_get_product(70);
    TestRunner::assertTrue($product->get_manage_stock(), 'manage_stock must stay untouched when WooCommerce owns inventory');
    TestRunner::assertSame(5, $product->get_stock_quantity(), 'the WC stock must not be overwritten');
});

TestRunner::test('T16.5 with inventory_source=alegra an inventariable item lands manage_stock + stock + status', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(71, ['name' => 'Alegra wins', 'sku' => 'ALW-1', 'regular_price' => '10']);
    update_post_meta(71, '_alegra_item_id', 'item-alw');

    make_products()->import_single_item_public([
        'id' => 'item-alw', 'name' => 'Alegra wins', 'reference' => 'ALW-1', 'type' => 'simple',
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => 12],
    ]);

    $product = wc_get_product(71);
    TestRunner::assertTrue($product->get_manage_stock(), '_manage_stock must be enabled (WC ignores _stock otherwise)');
    TestRunner::assertSame(12, $product->get_stock_quantity(), '_stock must receive the Alegra quantity');
    TestRunner::assertSame('instock', $product->get_stock_status(), '_stock_status must be set explicitly (WC does not derive it)');
});

TestRunner::test('T16.6 a service (no inventory key) disables manage_stock and never writes stock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(72, ['name' => 'Service', 'sku' => 'SRV-1', 'regular_price' => '10', 'stock' => 7, 'manage_stock' => true]);
    update_post_meta(72, '_alegra_item_id', 'item-srv');

    make_products()->import_single_item_public([
        'id' => 'item-srv', 'name' => 'Service', 'reference' => 'SRV-1', 'type' => 'service',
    ]);

    $product = wc_get_product(72);
    TestRunner::assertFalse($product->get_manage_stock(), 'a service must not manage stock');
    TestRunner::assertSame(7, $product->get_stock_quantity(), 'a service must not touch the existing stock value');
});

TestRunner::test('T16.7 a null/absent availableQuantity never becomes 0', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    // manage_stock=false: the fix must ENABLE it (inventariable item) without
    // writing a quantity, proving the null branch is the new behaviour.
    alegra_make_product(73, ['name' => 'NullQty', 'sku' => 'NQ-1', 'regular_price' => '10', 'stock' => 4, 'manage_stock' => false]);
    update_post_meta(73, '_alegra_item_id', 'item-nq');

    make_products()->import_single_item_public([
        'id' => 'item-nq', 'name' => 'NullQty', 'reference' => 'NQ-1', 'type' => 'simple',
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => null],
    ]);

    $product = wc_get_product(73);
    TestRunner::assertSame(4, $product->get_stock_quantity(), 'a null availableQuantity must not become 0');
    TestRunner::assertTrue($product->get_manage_stock(), 'the item is inventariable, so manage_stock is enabled even without a quantity');

    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(74, ['name' => 'AbsentQty', 'sku' => 'AQ-1', 'regular_price' => '10', 'stock' => 6, 'manage_stock' => true]);
    update_post_meta(74, '_alegra_item_id', 'item-aq');
    make_products()->import_single_item_public([
        'id' => 'item-aq', 'name' => 'AbsentQty', 'reference' => 'AQ-1', 'type' => 'simple',
        'inventory' => ['unit' => 'unit', 'unitCost' => 1],
    ]);
    TestRunner::assertSame(6, wc_get_product(74)->get_stock_quantity(), 'an absent availableQuantity must not become 0');
});

TestRunner::test('T16.8 stock lands on the variation object, never on the variable parent', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_variable_product(80, [
        'color' => ['name' => 'Color', 'options' => ['Rojo']],
    ], [
        81 => ['name' => 'Parent - Rojo', 'sku' => 'VAR-1', 'variation_attributes' => ['attribute_color' => 'Rojo'], 'regular_price' => '10'],
    ], ['name' => 'Parent', 'sku' => 'PAR-1', 'regular_price' => '10', 'stock' => 3, 'manage_stock' => true]);

    $parent = wc_get_product(80);
    $variation = wc_get_product(81);

    alegra_call_private(make_products(), 'update_product_from_alegra', $parent, [
        'id' => 'parent-80', 'name' => 'Parent', 'status' => 'active',
        'price' => [['idPriceList' => 1, 'price' => 10]],
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => 99],
    ]);
    TestRunner::assertFalse($parent->get_manage_stock(), 'the variable parent must not manage stock');
    TestRunner::assertSame(3, $parent->get_stock_quantity(), 'the variable parent stock must be untouched');

    alegra_call_private(make_products(), 'update_product_from_alegra', $variation, [
        'id' => 'child-81', 'name' => 'Parent - Rojo', 'status' => 'active',
        'price' => [['idPriceList' => 1, 'price' => 10]],
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => 8],
    ]);
    TestRunner::assertTrue($variation->get_manage_stock(), 'the variation must manage stock');
    TestRunner::assertSame(8, $variation->get_stock_quantity(), 'the variation stock must receive the quantity');
    TestRunner::assertSame('instock', $variation->get_stock_status(), 'the variation stock status must be set');
});

TestRunner::test('T16.9 the cron pulls inventory only when inventory_source=alegra', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('item-1', ['name' => 'P1', 'reference' => 'SKU-1', 'type' => 'simple']);

    make_controller()->run_cron_sync();
    // One GET for the products import + one for the dedicated inventory pull.
    TestRunner::assertSame(2, alegra_mock_count('GET', '/items'), 'the cron must run the inventory pull when Alegra owns inventory');

    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);
    update_option('alegra_connector_inventory_source', 'woocommerce');
    alegra_mock_seed_item('item-1', ['name' => 'P1', 'reference' => 'SKU-1', 'type' => 'simple']);

    make_controller()->run_cron_sync();
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items'), 'the cron must NOT run the inventory pull when WooCommerce owns inventory');
});

TestRunner::test('T16.10 the inventory pull aborts when the kill switch is active', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    $result = make_products()->sync_inventory_from_alegra();
    \Alegra\Connector\Kill_Switch::deactivate();

    TestRunner::assertTrue(!empty($result['skipped']), 'the pull must report skipped');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'no items may be fetched');
});

TestRunner::test('T16.11 the documented alegra_sync_inventory_from_alegra action is registered and runs the pull', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    $logger = make_logger();
    $controller = new Controller(new Client($logger), $logger);
    $controller->register_cron_hook();

    TestRunner::assertTrue(has_action('alegra_sync_inventory_from_alegra') !== false, 'the inventory action must be registered');
    TestRunner::assertTrue(has_action('alegra_connector_cron_sync') !== false, 'the cron hook must stay registered');

    do_action('alegra_sync_inventory_from_alegra');
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items'), 'the action must run the inventory pull');
});

TestRunner::test('T16.12 a negative Alegra quantity is clamped to 0 and never propagated to WC', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(75, ['name' => 'Neg', 'sku' => 'NEG-1', 'regular_price' => '10']);
    update_post_meta(75, '_alegra_item_id', 'item-neg');

    make_products()->import_single_item_public([
        'id' => 'item-neg', 'name' => 'Neg', 'reference' => 'NEG-1', 'type' => 'simple',
        'inventory' => ['unit' => 'unit', 'unitCost' => 1, 'availableQuantity' => -3],
    ]);

    $product = wc_get_product(75);
    TestRunner::assertSame(0, $product->get_stock_quantity(), 'a negative quantity must be clamped to 0');
    TestRunner::assertSame('outofstock', $product->get_stock_status(), 'a clamped quantity must be out of stock');
});

exit(TestRunner::summary());
