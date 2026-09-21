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

function make_customers(?Logger $logger = null): \Alegra\Connector\Sync\Customers
{
    $logger = $logger ?? make_logger();
    return new \Alegra\Connector\Sync\Customers(new Client($logger), $logger);
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
    // DIAN e-invoicing is OUT OF SCOPE: a normal invoice never carries the
    // CO/DIAN fields (no stamp, no paymentForm, no DIAN paymentMethod catalog).
    TestRunner::assertArrayNotHasKey('paymentForm', $body, 'the plugin never sends the DIAN paymentForm');
    TestRunner::assertArrayNotHasKey('paymentMethod', $body, 'the plugin never sends a DIAN paymentMethod catalog on an invoice');
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
    TestRunner::assertArrayNotHasKey('paymentForm', $body, 'no invoice must send the DIAN paymentForm');
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
    // The Consumidor Final lookup fails (API down), so it can neither be
    // resolved nor safely created. The plugin must abort instead of POSTing a
    // client-less credit note.
    alegra_mock_fail('GET', '/contacts', 500, ['message' => 'API caída']);

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
    TestRunner::assertSame('PERSON_ENTITY', $body['kindOfPerson'] ?? null, 'CO contacts must send kindOfPerson (FE schema requires it)');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $body['regime'] ?? null, 'CO contacts must send regime (FE schema requires it)');
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
    TestRunner::assertSame('PERSON_ENTITY', $payload['kindOfPerson'] ?? null, 'CO contacts must send kindOfPerson');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $payload['regime'] ?? null, 'CO contacts must send regime');
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
    // Deliveries now require the shared secret embedded in the registered URL.
    update_option('alegra_connector_webhook_token', 'replay-token');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);
    $auth = ['token' => 'replay-token'];

    $body = json_encode(['subject' => 'unknown-event', 'message' => ['id' => 'x']]);

    $first = $receiver->handle(new WP_REST_Request($body, [], $auth));
    TestRunner::assertSame(200, $first->get_status(), 'the first delivery must be accepted');
    TestRunner::assertFalse((bool) (($first->get_data())['duplicate'] ?? false), 'the first delivery must not be flagged as a duplicate');

    $second = $receiver->handle(new WP_REST_Request($body, [], $auth));
    TestRunner::assertSame(200, $second->get_status(), 'the replay must still be acked (so Alegra stops retrying)');
    TestRunner::assertTrue((bool) (($second->get_data())['duplicate'] ?? false), 'the identical replay must be flagged as a duplicate');

    // A different body is not a replay.
    $third = $receiver->handle(new WP_REST_Request(json_encode(['subject' => 'unknown-event', 'message' => ['id' => 'y']]), [], $auth));
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
    TestRunner::assertSame('PERSON_ENTITY', $co_payload['kindOfPerson'] ?? null, 'CO must send kindOfPerson');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $co_payload['regime'] ?? null, 'CO must send regime');
    TestRunner::assertArrayHasKey('identificationObject', $co_payload, 'CO sends identificationObject');
    TestRunner::assertSame('CC', $co_payload['identificationObject']['type'] ?? null, 'CO identificationObject.type');
});

TestRunner::test('T9.11 AC-52 "Run now" queues a dedicated one-off and the dead dispatcher is gone', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains(
        "wp_schedule_single_event(time(), 'alegra_connector_cron_sync_now')",
        $admin,
        'Run now must queue a dedicated one-off (never touch the recurring hook)'
    );
    TestRunner::assertStringNotContains("'alegra_manual_run'", $admin, 'the unregistered recurrence must be gone');

    $controller = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'includes/Sync/Controller.php');
    TestRunner::assertStringContains(
        "add_action('alegra_connector_cron_sync_now'",
        $controller,
        'the dedicated one-off hook must dispatch the same callback'
    );

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

// ===========================================================================
// T17 — Customers & Contacts batch 2 (CO fiscal fields, CF creation, import)
// ===========================================================================
echo "\nT17 — Customers & contacts batch 2\n";

TestRunner::test('T17.1 the CO contact payload always carries regime + kindOfPerson and honours the configured values', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_company_country', 'CO');
    $user = alegra_make_user(9, ['user_email' => 'co@example.test', 'display_name' => 'Ana Perez'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_first_name'            => 'Ana',
        'billing_last_name'             => 'Perez',
    ]);

    $payload = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertFalse(is_wp_error($payload), 'the payload must build');
    TestRunner::assertSame('PERSON_ENTITY', $payload['kindOfPerson'] ?? null, 'default kindOfPerson');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $payload['regime'] ?? null, 'default regime');
    TestRunner::assertArrayHasKey('nameObject', $payload, 'a natural person sends nameObject');

    // Merchant overrides are honoured (allowlisted enum values), and the name
    // shape follows kindOfPerson (nameObject only for PERSON_ENTITY).
    update_option(\Alegra\Connector\Billing_Fields::OPTION_KIND_OF_PERSON, 'LEGAL_ENTITY');
    update_option(\Alegra\Connector\Billing_Fields::OPTION_REGIME, 'COMMON_REGIME');
    $override = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertSame('LEGAL_ENTITY', $override['kindOfPerson'] ?? null, 'configured kindOfPerson');
    TestRunner::assertSame('COMMON_REGIME', $override['regime'] ?? null, 'configured regime');
    TestRunner::assertArrayNotHasKey('nameObject', $override, 'a legal entity must NOT send nameObject');
    TestRunner::assertArrayHasKey('name', $override, 'a legal entity sends a flat name');

    // Garbage never reaches Alegra.
    update_option(\Alegra\Connector\Billing_Fields::OPTION_KIND_OF_PERSON, 'NOT_A_KIND');
    update_option(\Alegra\Connector\Billing_Fields::OPTION_REGIME, 'NOT_A_REGIME');
    $fallback = \Alegra\Connector\Billing_Fields::build_contact_payload($user);
    TestRunner::assertSame('PERSON_ENTITY', $fallback['kindOfPerson'] ?? null, 'invalid kindOfPerson falls back');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $fallback['regime'] ?? null, 'invalid regime falls back');
});

TestRunner::test('T17.2 a CO contact create succeeds against an e-invoicing account (regime/kindOfPerson required)', function (): void {
    alegra_test_reset();
    $cf = seed_consumidor_final();
    // Model a Colombian account WITH electronic invoicing: POST /contacts 400s
    // unless regime + kindOfPerson are present.
    alegra_mock_set_contact_fiscal_required(true);
    alegra_make_user(1, ['user_email' => 'buyer@example.test', 'display_name' => 'Ana Perez'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_first_name'            => 'Ana',
        'billing_last_name'             => 'Perez',
    ]);
    $order = make_invoice_order(700, 1, 'buyer@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'the contact create must be attempted once');
    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertTrue((string) ($invoice['client']['id'] ?? '') !== $cf, 'the invoice must NOT fall back to Consumidor Final when the FE contact is accepted');
});

TestRunner::test('T17.3 the fallback note names the API reason when the contact create failed', function (): void {
    alegra_test_reset();
    seed_consumidor_final();
    alegra_make_user(1, ['user_email' => 'buyer@example.test', 'display_name' => 'Ana Perez'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_first_name'            => 'Ana',
        'billing_last_name'             => 'Perez',
    ]);
    $order = make_invoice_order(701, 1, 'buyer@example.test');
    alegra_mock_fail('POST', '/contacts', 400, ['message' => 'Alegra rechazó el contacto de prueba']);

    make_orders()->create_invoice($order);

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('Alegra rechazó el contacto de prueba', $notes, 'the note must name the API error');
    TestRunner::assertStringContains('no se pudo crear el contacto', $notes, 'the note must say the create failed');
    TestRunner::assertStringNotContains('no tiene', $notes, 'an API failure must NOT be mislabelled as missing data');
});

TestRunner::test('T17.4 Consumidor Final is auto-created when missing and is idempotent', function (): void {
    alegra_test_reset();
    // No CF seeded: the account does not have it.
    alegra_make_user(2, ['user_email' => 'nodata@example.test']);
    $order = make_invoice_order(702, 2, 'nodata@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'the CF contact must be created once');

    $created = null;
    foreach ($GLOBALS['alegra_mock_state']['contacts'] as $contact) {
        if ((string) ($contact['identificationObject']['number'] ?? '') === '222222222222') {
            $created = $contact;
        }
    }
    TestRunner::assertTrue($created !== null, 'the created contact must be the CF identification');
    TestRunner::assertSame('PERSON_ENTITY', $created['kindOfPerson'] ?? null, 'CF kindOfPerson');
    TestRunner::assertSame('SIMPLIFIED_REGIME', $created['regime'] ?? null, 'CF regime');

    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame((string) $created['id'], (string) ($invoice['client']['id'] ?? ''), 'the invoice must use the created CF');

    // Second resolution is served from cache: no second create.
    $again = \Alegra\Connector\Consumidor_Final::get_id();
    TestRunner::assertSame((string) $created['id'], (string) $again, 'CF resolves to the created id');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'CF creation must be idempotent (no duplicate)');
});

TestRunner::test('T17.5 the import dedups by identification before creating a WC user', function (): void {
    alegra_test_reset();
    alegra_make_user(1, ['user_email' => 'old@example.test', 'display_name' => 'Old Name'], [
        'billing_alegra_identification' => '900123456',
    ]);
    alegra_mock_seed_contact('c-900', [
        'email' => 'new@example.test',
        'name' => 'New Name',
        'identificationObject' => ['type' => 'CC', 'number' => '900123456'],
    ]);

    $result = make_customers()->import_from_alegra();

    TestRunner::assertSame(1, count($GLOBALS['wp_users']), 'no duplicate WC user may be created');
    TestRunner::assertSame(1, (int) ($result['updated'] ?? 0), 'the existing user must be updated, not imported');
    TestRunner::assertSame('c-900', (string) get_user_meta(1, 'alegra_contact_id', true), 'the existing user must be linked');
    // BUG 6: "Alegra gana" pulls the identity too.
    TestRunner::assertSame('new@example.test', (string) $GLOBALS['wp_users'][1]->user_email, 'the Alegra email must be pulled');
    TestRunner::assertSame('New Name', (string) $GLOBALS['wp_users'][1]->display_name, 'the Alegra name must be pulled');
});

TestRunner::test('T17.6 contacts without an email are counted as skipped, not errors', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('c-ok', ['email' => 'ok@example.test', 'name' => 'Ok']);
    alegra_mock_seed_contact('c-noemail', ['name' => 'No Email']);

    $result = make_customers()->import_from_alegra();

    TestRunner::assertSame(1, (int) ($result['imported'] ?? 0), 'the contact with an email must import');
    TestRunner::assertSame(1, (int) ($result['skipped'] ?? 0), 'the contact without an email must be skipped');
    TestRunner::assertSame(0, (int) ($result['errors'] ?? 0), 'a legitimate skip must NOT be counted as an error');
});

TestRunner::test('T17.7 the cron customer import is not self-blocked by its own lock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_customers', true);
    alegra_mock_seed_contact('c-cron', ['email' => 'cron@example.test', 'name' => 'Cron User']);

    make_controller()->run_cron_sync();

    TestRunner::assertTrue(get_user_by('email', 'cron@example.test') !== false, 'the cron must actually import the customer (the inner lock must not conflict with the outer one)');
});

// ===========================================================================
// T18 — Orders/Invoices/Payments/Credit-notes batch 3 (BUG 1-9)
// ===========================================================================

/**
 * Concatenate the plugin's log files (written under the stubbed uploads dir).
 */
function alegra_read_log(): string
{
    $out = '';
    foreach (glob(sys_get_temp_dir() . '/alegra-exec-uploads/alegra-logs/*.log') ?: [] as $f) {
        $out .= (string) file_get_contents($f);
    }
    return $out;
}

function alegra_clear_log(): void
{
    foreach (glob(sys_get_temp_dir() . '/alegra-exec-uploads/alegra-logs/*.log') ?: [] as $f) {
        @unlink($f);
    }
}

/** Seed a WC tax rate so the invoice tax resolver can derive its percentage. */
function alegra_seed_wc_tax_rate(int $rate_id, float $percentage, string $class = ''): void
{
    $GLOBALS['wc_tax_rates'][$rate_id] = [
        'tax_rate'       => number_format($percentage, 4, '.', ''),
        'tax_rate_class' => $class,
    ];
}

TestRunner::test('T18.1 BUG 1 a partial refund on a MULTI-line invoice sends items[].id', function (): void {
    alegra_test_reset();
    $invoice_id = '1nv-b1';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 100.0, 'balance' => 100.0, 'status' => 'open',
        'items' => [
            ['id' => '1t3m-a', 'name' => 'A', 'price' => 60, 'quantity' => 1],
            ['id' => '1t3m-b', 'name' => 'B', 'price' => 40, 'quantity' => 1],
        ],
    ]);
    $refund = alegra_make_refund(1101, ['total' => 30.0]);
    alegra_make_order(1100, [
        'total' => 100.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id, '_billing_alegra_contact_id' => 'c0n-b1'],
        'refunds' => [$refund],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 1100, 1101);

    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    TestRunner::assertTrue(!empty($body['items'][0]['id']), 'the partial-refund line MUST carry an items[].id (obligatory)');
    TestRunner::assertTrue(
        ($body['items'][0]['id'] ?? '') !== '1t3m-a' && ($body['items'][0]['id'] ?? '') !== '1t3m-b',
        'a multi-line refund must NOT reuse a single invoice line id'
    );
    TestRunner::assertEquals(30.0, $body['items'][0]['price'] ?? null, 'the line price is the refund amount');
    TestRunner::assertEquals(30.0, $body['invoices'][0]['amount'] ?? null, 'invoices[0].amount matches the refund');

    $resolved = false;
    foreach (alegra_mock_requests('GET', '/items') as $r) {
        if (($r['query']['reference'] ?? '') === 'alegra-connector-adjustment') { $resolved = true; }
    }
    foreach (alegra_mock_requests('POST', '/items') as $r) {
        if (($r['body']['reference'] ?? '') === 'alegra-connector-adjustment') { $resolved = true; }
    }
    TestRunner::assertTrue($resolved, 'the shared generic "Ajuste" item must be resolved by reference');
});

TestRunner::test('T18.2 BUG 1 the shared generic "Ajuste" item is find-or-created exactly once', function (): void {
    alegra_test_reset();
    $invoice_id = '1nv-b3';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 100.0, 'balance' => 100.0, 'status' => 'open',
        'items' => [
            ['id' => 'a', 'name' => 'A', 'price' => 60, 'quantity' => 1],
            ['id' => 'b', 'name' => 'B', 'price' => 40, 'quantity' => 1],
        ],
    ]);
    $r1 = alegra_make_refund(1201, ['total' => 30.0]);
    $order = alegra_make_order(1200, [
        'total' => 100.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id, '_billing_alegra_contact_id' => 'c0n-b3'],
        'refunds' => [$r1],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 1200, 1201);

    $r2 = alegra_make_refund(1202, ['total' => 30.0]);
    $order->add_refund($r2);
    State_Sync::handle_refund(1200, 1202);

    TestRunner::assertSame(2, alegra_mock_count('POST', '/credit-notes'), 'two credit notes');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/items'), 'the generic item must be created exactly once');
    $bodies = alegra_mock_requests('POST', '/credit-notes');
    TestRunner::assertSame(
        $bodies[0]['body']['items'][0]['id'] ?? null,
        $bodies[1]['body']['items'][0]['id'] ?? null,
        'both refunds must reference the same generic item'
    );
});

TestRunner::test('T18.3 BUG 1 a single-line partial refund reuses the invoice line id', function (): void {
    alegra_test_reset();
    $invoice_id = '1nv-b4';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 100.0, 'balance' => 100.0, 'status' => 'open',
        'items' => [['id' => '1t3m-solo', 'name' => 'Widget', 'price' => 100, 'quantity' => 1]],
    ]);
    $refund = alegra_make_refund(1203, ['total' => 40.0]);
    alegra_make_order(1204, [
        'total' => 100.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id, '_billing_alegra_contact_id' => 'c0n-b4'],
        'refunds' => [$refund],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 1204, 1203);

    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    TestRunner::assertSame('1t3m-solo', $body['items'][0]['id'] ?? null, 'a single-line refund reuses the line id');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/items'), 'no generic item is needed for a single-line refund');
    TestRunner::assertEquals(40.0, $body['items'][0]['price'] ?? null, 'the reused line carries the refund amount');
});

TestRunner::test('T18.4 BUG 1 a full refund: invoices[].amount equals the credit-note total', function (): void {
    alegra_test_reset();
    $invoice_id = '1nv-b2';
    alegra_mock_seed_invoice($invoice_id, [
        'total' => 119.0, 'balance' => 119.0, 'status' => 'open',
        'items' => [[
            'id' => '1t3m-b2', 'name' => 'Widget', 'price' => 100, 'quantity' => 1,
            'tax' => [['id' => '22222222-0000-0000-0000-000000000001']],
        ]],
    ]);
    $refund = alegra_make_refund(1102, ['total' => 119.0]);
    alegra_make_order(1103, [
        'total' => 119.0,
        'meta' => ['_alegra_invoice_id' => $invoice_id, '_billing_alegra_contact_id' => 'c0n-b2'],
        'refunds' => [$refund],
    ]);

    register_refund_owner_hook();
    do_action('woocommerce_order_refunded', 1103, 1102);

    $body = alegra_mock_last_request('POST', '/credit-notes')['body'] ?? [];
    $total = 0.0;
    foreach (($body['items'] ?? []) as $item) {
        $line = (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 0);
        foreach ((array) ($item['tax'] ?? []) as $tax) {
            $line += $line * (alegra_mock_tax_percentage((string) ($tax['id'] ?? '')) / 100);
        }
        $total += $line;
    }
    TestRunner::assertEquals(119.0, round($total, 2), 'the credit-note total must be 119 (100 + 19% IVA)');
    TestRunner::assertEquals(
        round($total, 2),
        (float) ($body['invoices'][0]['amount'] ?? 0),
        'invoices[].amount must equal the credit-note total (required when stamping)'
    );
});

TestRunner::test('T18.5 BUG 2 shipping, fees and a partial refund share ONE generic "Ajuste" item', function (): void {
    alegra_test_reset();
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '100']);
    update_post_meta(10, '_alegra_item_id', '1t3m-one');

    $order = alegra_make_order(1130, [
        'total' => 130.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'one@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-one'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 100])],
        'shipping_items' => [new WC_Order_Item(['name' => 'Envío', 'quantity' => 1, 'subtotal' => 10, 'total' => 10])],
        'fee_items' => [new WC_Order_Item(['name' => 'Manejo', 'quantity' => 1, 'subtotal' => 20, 'total' => 20])],
    ]);

    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];

    TestRunner::assertCount(3, $body['items'] ?? [], 'product + shipping + fee');
    $ship_id = (string) ($body['items'][1]['id'] ?? '');
    $fee_id  = (string) ($body['items'][2]['id'] ?? '');
    TestRunner::assertTrue($ship_id !== '' && $ship_id === $fee_id, 'shipping and fee lines must share ONE generic item id');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/items'), 'only ONE generic item is created for shipping + fees');
    $created = alegra_mock_requests('POST', '/items')[0]['body'] ?? [];
    TestRunner::assertSame('alegra-connector-adjustment', $created['reference'] ?? null, 'the shared generic item reference');
});

TestRunner::test('T18.6 BUG 2 an order WITH shipping and a coupon: the invoice total equals the order total', function (): void {
    alegra_test_reset();
    alegra_seed_wc_tax_rate(1, 19.0);
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '100']);
    update_post_meta(10, '_alegra_item_id', '1t3m-coupon');

    // A 20% coupon: line subtotal 100 -> total 80, tax 15.20. Shipping 10 + 1.90.
    // WC order total = 95.20 + 11.90 = 107.10.
    $order = alegra_make_order(1140, [
        'total' => 107.10, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'coupon@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-coupon'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 80, 'taxes' => ['total' => [1 => 15.2]]])],
        'shipping_items' => [new WC_Order_Item(['name' => 'Envío', 'quantity' => 1, 'subtotal' => 10, 'total' => 10, 'taxes' => ['total' => [1 => 1.9]]])],
    ]);

    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];

    TestRunner::assertEquals(20.0, (float) ($body['items'][0]['discount'] ?? 0), 'the coupon maps to a 20% line discount');
    TestRunner::assertEquals(
        (float) $order->get_total(),
        (float) ($result['total'] ?? 0),
        'the invoice total must equal the WC order total (coupon + shipping + tax)'
    );
});

TestRunner::test('T18.7 BUG 4 void_invoice sends `cause`, never `reason`', function (): void {
    alegra_test_reset();
    make_api()->void_invoice('1nv-void', 'motivo de prueba');

    $body = alegra_mock_last_request('POST', '/invoices/1nv-void/void')['body'] ?? [];
    TestRunner::assertSame('motivo de prueba', $body['cause'] ?? null, 'the documented void body field is `cause`');
    TestRunner::assertArrayNotHasKey('reason', $body, '`reason` is not a documented field');
});

TestRunner::test('T18.8 BUG 2 shipping + fees are invoiced and the total matches the order', function (): void {
    alegra_test_reset();
    alegra_seed_wc_tax_rate(1, 19.0);
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '100']);
    update_post_meta(10, '_alegra_item_id', '1t3m-ship');

    $order = alegra_make_order(1120, [
        'total' => 136.85, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'ship@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-ship'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 100, 'taxes' => ['total' => [1 => 19.0]]])],
        'shipping_items' => [new WC_Order_Item(['name' => 'Envío', 'quantity' => 1, 'subtotal' => 10, 'total' => 10, 'taxes' => ['total' => [1 => 1.9]]])],
        'fee_items' => [new WC_Order_Item(['name' => 'Manejo', 'quantity' => 1, 'subtotal' => 5, 'total' => 5, 'taxes' => ['total' => [1 => 0.95]]])],
    ]);

    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];

    TestRunner::assertCount(3, $body['items'] ?? [], 'product + shipping + fee = 3 lines');
    TestRunner::assertEquals(10.0, (float) ($body['items'][1]['price'] ?? 0), 'the shipping line carries the shipping total');
    TestRunner::assertEquals(5.0, (float) ($body['items'][2]['price'] ?? 0), 'the fee line carries the fee total');
    TestRunner::assertTrue(!empty($body['items'][1]['id']) && !empty($body['items'][2]['id']), 'shipping/fee lines require an id');
    TestRunner::assertEquals(136.85, (float) ($result['total'] ?? 0), 'the invoice total must equal the WC order total');
});

TestRunner::test('T18.9 BUG 3 an unmapped WC tax is derived from the Alegra catalog (not dropped)', function (): void {
    alegra_test_reset();
    alegra_seed_wc_tax_rate(1, 19.0);
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '119']);
    update_post_meta(10, '_alegra_item_id', '1t3m-tax');

    $order = alegra_make_order(1130, [
        'total' => 119.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'tax@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-tax'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 100, 'taxes' => ['total' => [1 => 19.0]]])],
    ]);

    // No alegra_connector_tax_mapping is configured (the default).
    $result = make_orders()->create_invoice($order);
    TestRunner::assertFalse(is_wp_error($result), 'invoice creation must succeed');
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];

    TestRunner::assertSame(
        '22222222-0000-0000-0000-000000000001',
        $body['items'][0]['tax'][0]['id'] ?? null,
        'the tax must be derived from the Alegra catalog, not silently dropped'
    );
    TestRunner::assertEquals(119.0, (float) ($result['total'] ?? 0), 'the invoice total must include the tax');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/taxes'), 'an existing tax must be reused, not created');
});

TestRunner::test('T18.10 BUG 3 a missing Alegra tax is created once and reused', function (): void {
    alegra_test_reset();
    // Empty catalog: no tax with this percentage exists yet.
    $GLOBALS['alegra_mock_state']['taxes'] = [];
    alegra_seed_wc_tax_rate(1, 5.0);

    foreach ([1141, 1142] as $order_id) {
        alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '105']);
        update_post_meta(10, '_alegra_item_id', '1t3m-tax5');
        $order = alegra_make_order($order_id, [
            'total' => 105.0, 'currency' => 'COP', 'payment_method' => 'bacs',
            'billing' => ['country' => 'CO', 'email' => "t{$order_id}@example.test"],
            'meta' => ['_billing_alegra_contact_id' => 'c0n-tax5'],
            'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 100, 'total' => 100, 'taxes' => ['total' => [1 => 5.0]]])],
        ]);
        make_orders()->create_invoice($order);
    }

    TestRunner::assertSame(1, alegra_mock_count('POST', '/taxes'), 'the missing tax must be created exactly once');
    $created = alegra_mock_last_request('POST', '/taxes')['body'] ?? [];
    TestRunner::assertEquals(5.0, (float) ($created['percentage'] ?? 0), 'the created tax must match the WC rate');

    $bodies = alegra_mock_requests('POST', '/invoices');
    TestRunner::assertSame(
        $bodies[0]['body']['items'][0]['tax'][0]['id'] ?? null,
        $bodies[1]['body']['items'][0]['tax'][0]['id'] ?? null,
        'both invoices must reference the same (created) tax'
    );
});

TestRunner::test('T18.11 BUG 5 a failed payment is logged and surfaced in an order note', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    update_option('alegra_connector_payment_account_id', 'acct-1');
    alegra_mock_fail('POST', '/payments', 400, ['code' => 400, 'message' => 'La cuenta bancaria no existe']);

    alegra_mock_seed_invoice('1nv-1140', [
        'status' => 'open', 'total' => 50.0, 'balance' => 50.0,
        'items' => [['id' => 'x', 'price' => 50, 'quantity' => 1]],
    ]);
    $order = alegra_make_order(1140, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => 'pay@example.test'],
        'meta' => ['_alegra_invoice_id' => '1nv-1140', '_billing_alegra_contact_id' => 'c0n-pay'],
    ]);

    $result = make_orders()->create_invoice_with_payment($order);
    TestRunner::assertFalse(is_wp_error($result), 'the invoice itself must succeed');

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('NO se pudo registrar', $notes, 'an order note must explain the payment failure');
    TestRunner::assertStringContains('La cuenta bancaria no existe', $notes, 'the note must carry the real reason');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), 'no payment id may be stored on failure');
    TestRunner::assertStringContains('Payment recording failed', alegra_read_log(), 'the failure must be logged at error level');
});

TestRunner::test('T18.12 BUG 6 ajax_record_payment opens a DRAFT invoice before paying', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', 'acct-1');
    alegra_mock_seed_invoice('1nv-draft2', [
        'status' => 'draft', 'total' => 20.0, 'balance' => 20.0,
        'items' => [['id' => 'x', 'price' => 20, 'quantity' => 1]],
    ]);
    alegra_make_order(1150, [
        'total' => 20.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'meta' => ['_alegra_invoice_id' => '1nv-draft2'],
    ]);

    $_POST['order_id'] = 1150;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn() => $admin->ajax_record_payment());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the payment must succeed');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/1nv-draft2/open'), 'a draft invoice must be opened before paying');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be recorded');
});

TestRunner::test('T18.13 BUG 7 an automatic order sync failure adds an order note', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '10']);
    update_post_meta(10, '_alegra_item_id', '1t3m-auto');
    alegra_mock_fail('POST', '/invoices', 400, ['code' => 400, 'message' => 'Cliente inválido']);

    alegra_make_order(1160, [
        'total' => 10.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'billing' => ['country' => 'CO', 'email' => 'auto@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-auto'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 10, 'total' => 10])],
    ]);

    $logger = make_logger();
    $public = new \Alegra\Connector\Public\Public_(new Client($logger), $logger);
    do_action('woocommerce_new_order', 1160);

    $order = wc_get_order(1160);
    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('no se pudo sincronizar el pedido', $notes, 'the failure must be surfaced on the order');
    TestRunner::assertStringContains('Cliente inválido', $notes, 'the note must carry the real reason');
});

TestRunner::test('T18.14 BUG 8 a payment account of "0" is treated as unconfigured', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '0');
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '10']);
    update_post_meta(10, '_alegra_item_id', '1t3m-zero');

    $order = alegra_make_order(1170, [
        'total' => 10.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => 'zero@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-zero'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 10, 'total' => 10])],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be attempted with account "0"');
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame('draft', $body['status'] ?? null, 'without a payment the configured draft status applies');
});

TestRunner::test('T18.15 BUG 9 the settings text states the plugin does NOT emit to the DIAN', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains('NO emite', $tpl, 'the settings text must state the plugin does not emit');
    TestRunner::assertStringContains('DIAN', $tpl, 'the settings text must mention the DIAN');
    TestRunner::assertStringContains('stamp.generateStamp', $tpl, 'the settings text must name the missing stamp');
});

// ===========================================================================
// T19 — Orchestration batch 4 (webhook auth, cron recurrence, per-run stop)
// ===========================================================================
echo "\nT19 — Orchestration batch 4\n";

/**
 * All recurring (schedule-bearing) events for a hook, as
 * [{timestamp, schedule}, ...].
 */
function alegra_recurring_events(string $hook): array
{
    $out = [];
    foreach (_get_cron_array() as $timestamp => $hooks) {
        foreach (($hooks[$hook] ?? []) as $event) {
            if (!empty($event['schedule'])) {
                $out[] = ['timestamp' => (int) $timestamp, 'schedule' => (string) $event['schedule']];
            }
        }
    }
    return $out;
}

/** Timestamps of the single (non-recurring) events for a hook. */
function alegra_single_events(string $hook): array
{
    $out = [];
    foreach (_get_cron_array() as $timestamp => $hooks) {
        foreach (($hooks[$hook] ?? []) as $event) {
            if (empty($event['schedule'])) {
                $out[] = (int) $timestamp;
            }
        }
    }
    return $out;
}

TestRunner::test('T19.1 BUG 1 an unauthenticated webhook is rejected with 401', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'secret-abc');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);
    $body = json_encode(['subject' => 'edit-item', 'message' => ['id' => 'it-1']]);

    $noToken = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(401, $noToken->get_status(), 'a delivery with no token must be rejected');

    $wrong = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'nope']));
    TestRunner::assertSame(401, $wrong->get_status(), 'a wrong token must be rejected');

    // Fail closed when no token is configured at all: there can be no
    // legitimate subscription yet, so an event must not be trusted.
    delete_option('alegra_connector_webhook_token');
    $unconfigured = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(401, $unconfigured->get_status(), 'with no token configured the endpoint must fail closed');

    // The exact token is accepted.
    update_option('alegra_connector_webhook_token', 'secret-abc');
    $ok = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'secret-abc']));
    TestRunner::assertSame(200, $ok->get_status(), 'the correct token must be accepted');
});

TestRunner::test('T19.2 BUG 1 the registration handshake (empty body) is acked with 2XX', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    // Alegra POSTs an EMPTY body to verify the URL and requires a 2XX within 5s,
    // otherwise the subscription is never created. No token is required for the
    // handshake: an empty body carries nothing to process.
    $handshake = $receiver->handle(new WP_REST_Request(''));
    TestRunner::assertSame(200, $handshake->get_status(), 'the registration handshake must return 2XX');
    TestRunner::assertTrue((bool) (($handshake->get_data())['handshake'] ?? false), 'the handshake must be flagged');
});

TestRunner::test('T19.3 BUG 2 registration counts the NESTED subscription id', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $_POST = [];
    $response = null;
    try {
        $admin->ajax_register_webhooks();
    } catch (Alegra_Test_JSON_Response $e) {
        $response = $e;
    }
    $_POST = [];

    $expected = count(Client::get_webhook_events());
    TestRunner::assertTrue($response !== null && $response->success, 'registration must succeed');
    TestRunner::assertSame($expected, (int) ($response->payload['creados'] ?? -1), 'every event must be counted as created');
    TestRunner::assertSame(0, (int) ($response->payload['ya_existian'] ?? -1), 'nothing existed before');
    TestRunner::assertSame(0, (int) ($response->payload['errores'] ?? -1), 'a first registration must have no errors');
    TestRunner::assertSame($expected, count((array) get_option('alegra_connector_webhook_subscriptions', [])), 'every subscription must be stored');

    // The registered URL must carry the shared secret.
    $req = alegra_mock_last_request('POST', '/webhooks/subscriptions');
    TestRunner::assertTrue($req !== null, 'a subscription POST must be sent');
    $url = (string) ($req['body']['url'] ?? '');
    TestRunner::assertStringContains('token=', $url, 'the registered URL must embed the token');
    $token = (string) get_option('alegra_connector_webhook_token', '');
    TestRunner::assertTrue($token !== '', 'a token must be generated');
    TestRunner::assertStringContains($token, $url, 'the URL token must match the stored token');
});

TestRunner::test('T19.3b BUG 2 re-registering counts "Ya existe" as already registered, not an error', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    // First registration: everything is created.
    $first = alegra_capture_json(fn() => $admin->ajax_register_webhooks());
    $events = count(Client::get_webhook_events());
    TestRunner::assertSame($events, (int) ($first->payload['creados'] ?? -1), 'the first run must create every subscription');

    // Second registration: Alegra answers 400 "Ya existe una suscripción con
    // el mismo evento y URL" for all of them. That is success, not failure.
    $second = alegra_capture_json(fn() => $admin->ajax_register_webhooks());

    TestRunner::assertTrue($second->success, 'a re-register must still be a success response');
    TestRunner::assertSame(0, (int) ($second->payload['creados'] ?? -1), 'nothing new must be created');
    TestRunner::assertSame($events, (int) ($second->payload['ya_existian'] ?? -1), 'every event must count as already registered');
    TestRunner::assertSame(0, (int) ($second->payload['errores'] ?? -1), 'an already-registered subscription is NOT an error');

    $message = (string) ($second->payload['message'] ?? '');
    TestRunner::assertStringContains('0 webhooks registrados', $message, 'the message must report the created count');
    TestRunner::assertStringContains((string) $events . ' ya existían', $message, 'the message must report the already-registered count');
    TestRunner::assertStringContains('0 errores', $message, 'the message must report zero errors');

    // The local list must keep the ids so the DELETE flow can still remove
    // them: an "already exists" 400 carries no id, so dropping them would
    // orphan the remote subscriptions.
    $stored = (array) get_option('alegra_connector_webhook_subscriptions', []);
    TestRunner::assertSame($events, count($stored), 'the stored subscription list must survive a re-register');
    foreach ($stored as $sub) {
        TestRunner::assertTrue(!empty($sub['id']), 'every stored subscription must keep its id for DELETE');
    }
});

TestRunner::test('T19.3c BUG 2 a genuine failure is still counted as an error', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');
    // An invalid URL (documented 400) must NOT be mistaken for "already
    // exists": the narrow match must let it through as an error.
    alegra_mock_fail('POST', '/webhooks/subscriptions', 400, ['error' => 'La URL ingresada no es válida']);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $response = alegra_capture_json(fn() => $admin->ajax_register_webhooks());

    TestRunner::assertSame(0, (int) ($response->payload['ya_existian'] ?? -1), 'an invalid URL is not an already-registered subscription');
    TestRunner::assertSame(count(Client::get_webhook_events()), (int) ($response->payload['errores'] ?? -1), 'every genuine failure must count as an error');
});

TestRunner::test('T19.4 BUG 3 "Run now" does not destroy the recurring schedule', function (): void {
    alegra_test_reset();
    $hook = 'alegra_connector_cron_sync';
    // Place the recurrence well beyond WP's 10-minute duplicate window.
    wp_schedule_event(time() + 3600, 'alegra_connector_15min', $hook);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $_POST = ['hook' => $hook];
    $response = null;
    try {
        $admin->ajax_run_cron_now();
    } catch (Alegra_Test_JSON_Response $e) {
        $response = $e;
    }
    $_POST = [];

    TestRunner::assertTrue($response !== null && $response->success, 'run now must succeed');
    $recurring = alegra_recurring_events($hook);
    TestRunner::assertSame(1, count($recurring), 'the recurring event must survive Run now');
    TestRunner::assertSame('alegra_connector_15min', $recurring[0]['schedule'] ?? null, 'the recurrence keeps its interval');
    TestRunner::assertSame(1, count(alegra_single_events('alegra_connector_cron_sync_now')), 'Run now must queue exactly one dedicated one-off');
});

TestRunner::test('T19.5 BUG 4 "skip" moves the run forward and keeps the recurrence', function (): void {
    alegra_test_reset();
    $hook = 'alegra_connector_cron_sync';
    update_option('alegra_connector_sync_frequency', 15);
    $scheduled = time() + 300;
    wp_schedule_event($scheduled, 'alegra_connector_15min', $hook);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $_POST = ['hook' => $hook, 'timestamp' => $scheduled];
    $response = null;
    try {
        $admin->ajax_skip_cron_next();
    } catch (Alegra_Test_JSON_Response $e) {
        $response = $e;
    }
    $_POST = [];

    TestRunner::assertTrue($response !== null && $response->success, 'skip must succeed');
    $recurring = alegra_recurring_events($hook);
    TestRunner::assertSame(1, count($recurring), 'skip must NOT delete the recurrence');
    TestRunner::assertTrue(
        ($recurring[0]['timestamp'] ?? 0) > $scheduled,
        'skip must move the next run forward (was ' . $scheduled . ', now ' . ($recurring[0]['timestamp'] ?? 0) . ')'
    );
});

TestRunner::test('T19.6 the init self-heal restores a missing schedule (and respects an explicit stop)', function (): void {
    alegra_test_reset();
    $hook = 'alegra_connector_cron_sync';
    update_option('alegra_connector_sync_method', 'cron');
    wp_clear_scheduled_hook($hook);
    TestRunner::assertFalse(wp_next_scheduled($hook), 'precondition: no schedule');

    \Alegra\Connector\Alegra_Connector::get_instance()->maybe_self_heal_cron();
    TestRunner::assertTrue(wp_next_scheduled($hook) !== false, 'self-heal must restore the schedule');
    TestRunner::assertSame(1, count(alegra_recurring_events($hook)), 'self-heal must create a recurring event');

    // An explicit "remove all" must not be undone.
    wp_clear_scheduled_hook($hook);
    update_option('alegra_connector_cron_disabled', 1);
    \Alegra\Connector\Alegra_Connector::get_instance()->maybe_self_heal_cron();
    TestRunner::assertFalse(wp_next_scheduled($hook), 'self-heal must respect the explicit stop');

    // real-time / disabled methods must never schedule a periodic pull.
    delete_option('alegra_connector_cron_disabled');
    update_option('alegra_connector_sync_method', 'real-time');
    \Alegra\Connector\Alegra_Connector::get_instance()->maybe_self_heal_cron();
    TestRunner::assertFalse(wp_next_scheduled($hook), 'real-time must not schedule a periodic pull');
});

TestRunner::test('T19.7 BUG 6 the per-run stop halts the products import loop', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-a', ['name' => 'A', 'reference' => 'A', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 10]]]);
    alegra_mock_seed_item('it-b', ['name' => 'B', 'reference' => 'B', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 20]]]);

    // Control: with no stop flag the items import.
    $normal = make_products()->import_from_alegra(1, 30, 0);
    TestRunner::assertTrue(($normal['imported'] + $normal['updated']) > 0, 'the control import must import items');

    // With a per-run stop flag set, the loop must abort before pulling.
    alegra_test_reset();
    alegra_mock_seed_item('it-a', ['name' => 'A', 'reference' => 'A', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 10]]]);
    alegra_mock_seed_item('it-b', ['name' => 'B', 'reference' => 'B', 'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 20]]]);
    \Alegra\Connector\Runs::request_stop(77);
    $stopped = make_products()->import_from_alegra(1, 30, 77);

    TestRunner::assertSame(0, (int) $stopped['imported'] + (int) $stopped['updated'], 'a stopped run must import nothing');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'a stopped run must not even pull a page');
});

TestRunner::test('T19.8 BUG 6 the per-run stop halts the customers and categories loops', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('c-1', ['name' => 'One', 'email' => 'one@example.test']);
    alegra_mock_seed_category('cat-1', ['name' => 'Cat 1']);

    \Alegra\Connector\Runs::request_stop(88);
    $customers = make_customers()->import_from_alegra(1, 30, 88);
    TestRunner::assertSame(0, (int) $customers['imported'] + (int) $customers['updated'], 'a stopped customer import must import nothing');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'a stopped customer import must not pull');

    $categories = new \Alegra\Connector\Sync\Categories(make_api(), make_logger());
    $cat = $categories->import_from_alegra(88);
    TestRunner::assertSame(0, (int) $cat['imported'] + (int) $cat['updated'], 'a stopped category import must import nothing');
});

TestRunner::test('T19.9 BUG 1 a forged "paid" invoice webhook cannot complete an order (re-fetch)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-forge', ['status' => 'open', 'balance' => 100, 'total' => 100, 'number' => 'FV-1']);
    alegra_make_order(5000, [
        'status' => 'processing',
        'meta' => ['_alegra_invoice_id' => 'inv-forge'],
    ]);

    $logger = make_logger();
    $handlers = new \Alegra\Connector\Webhooks\Handlers(new Client($logger), $logger);

    // The payload claims the invoice is paid, but Alegra reports it open.
    $handlers->process_event('new-invoice', ['invoice' => ['id' => 'inv-forge', 'status' => 'paid', 'balance' => 0]]);
    TestRunner::assertNotSame('completed', wc_get_order(5000)->get_status(), 'a forged payload must not complete the order');

    // Once Alegra really reports it paid, the same handler completes it.
    alegra_mock_seed_invoice('inv-forge', ['status' => 'paid', 'balance' => 0, 'total' => 100, 'number' => 'FV-1']);
    $handlers->process_event('new-invoice', ['invoice' => ['id' => 'inv-forge', 'status' => 'paid', 'balance' => 0]]);
    TestRunner::assertSame('completed', wc_get_order(5000)->get_status(), 'a genuinely paid invoice must complete the order');
});

// ===========================================================================
// T20 — Webhook receiver vs the DOCUMENTED payload shapes
//   https://developer.alegra.com/docs/descripci%C3%B3n-general.md
//   https://developer.alegra.com/reference/post_webhooks-subscriptions.md
// ===========================================================================
echo "\nT20 — Webhook receiver vs documented payloads\n";

/**
 * Run $fn with PHP warnings/notices collected instead of emitted. A non-empty
 * return means the code raised a diagnostic (e.g. "Array to string conversion")
 * that must fail the test: a real Alegra delivery must never warn.
 *
 * @return string[]
 */
function alegra_php_diagnostics(callable $fn): array
{
    $diags = [];
    set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$diags): bool {
        $diags[] = $str . ' @ ' . $file . ':' . $line;
        return true;
    }, E_ALL & ~E_DEPRECATED);
    try {
        $fn();
    } finally {
        restore_error_handler();
    }
    return $diags;
}

/** The verbatim documented `new-item` payload (nulls included). */
function alegra_documented_item_payload(): array
{
    return [
        'subject' => 'new-item',
        'message' => [
            'item' => [
                'id' => '865',
                'name' => 'Un Ítem / S',
                'description' => 'Descripción del Item',
                'reference' => '423424134213',
                'itemCategory' => ['id' => '19'],
                'price' => [['id' => '123', 'price' => 1], ['id' => '124', 'price' => 2]],
                'inventory' => [
                    'unit' => 'unit',
                    'availableQuantity' => 1,
                    'unitCost' => 1,
                    'initialQuantity' => 1,
                    'warehouses' => [['id' => '10']],
                ],
                'category' => ['id' => '234'],
                'tax' => [],
                'status' => 'active',
                'customFields' => [['id' => '6'], ['id' => '5'], ['id' => '1']],
                'type' => 'variant',
                'variantAttributes' => null,
                'itemVariants' => null,
                'subitems' => null,
            ],
        ],
    ];
}

/** The verbatim documented `new-client` payload (object name, array type). */
function alegra_documented_client_payload(): array
{
    return [
        'subject' => 'new-client',
        'message' => [
            'client' => [
                'id' => '774',
                'name' => [
                    'firstName' => 'Primer Nombre',
                    'secondName' => 'Segundo Nombre',
                    'lastName' => 'Primer Apellido',
                    'secondLastName' => 'Segundo Apellido',
                ],
                'phonePrimary' => '+432432234',
                'phoneSecondary' => '+534234523',
                'mobile' => '+443242323123',
                'email' => 'uncorreo@correo.com',
                'type' => ['client', 'provider'],
                'fax' => 'unFax',
                'identification' => '3211233',
                'address' => [
                    'zipCode' => '050013',
                    'department' => 'Antioquia',
                    'country' => 'Colombia',
                    'address' => 'Una Dirección',
                    'city' => 'Abriaquí',
                ],
            ],
        ],
    ];
}

TestRunner::test('T20.1 the handshake (empty body) is 2XX, fast, token-free and side-effect free', function (): void {
    alegra_test_reset();
    // No token configured on purpose: the handshake must not depend on auth.
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $start = microtime(true);
    $res = $receiver->handle(new WP_REST_Request(''));
    $elapsed = microtime(true) - $start;

    TestRunner::assertSame(200, $res->get_status(), 'the handshake must return 2XX');
    TestRunner::assertTrue((bool) (($res->get_data())['handshake'] ?? false), 'the handshake must be flagged');
    TestRunner::assertTrue($elapsed < 5.0, 'the handshake must return well inside the 5s budget');
    TestRunner::assertSame([], $GLOBALS['wp_transients'], 'the handshake must not write a replay transient');
    TestRunner::assertSame([], alegra_mock_requests(), 'the handshake must not call the Alegra API');
});

TestRunner::test('T20.2 the documented item payload (null variants) never warns and is acked', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    alegra_mock_seed_item('865', alegra_documented_item_payload()['message']['item']);

    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $res = null;
    $body = json_encode(alegra_documented_item_payload());
    $diags = alegra_php_diagnostics(function () use ($receiver, $body, &$res): void {
        $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    });

    TestRunner::assertSame([], $diags, 'the documented item payload must not raise a PHP warning/notice');
    TestRunner::assertSame(200, $res->get_status(), 'the item delivery must be acked with 2XX');
    // The handler re-fetches by id (never trusts the unsigned body).
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items/865'), 'the item must be re-fetched from the API');
});

TestRunner::test('T20.3 a variantParent with null variantAttributes/itemVariants/subitems imports cleanly', function (): void {
    alegra_test_reset();
    $item = alegra_documented_item_payload()['message']['item'];
    $item['id'] = 'vp-1';
    $item['type'] = 'variantParent';
    alegra_mock_seed_item('vp-1', $item);

    $logger = make_logger();
    $products = new \Alegra\Connector\Sync\Products(new Client($logger), $logger);

    $result = null;
    $diags = alegra_php_diagnostics(function () use ($products, &$result): void {
        $result = $products->sync_single_item_by_alegra_id('vp-1');
    });

    TestRunner::assertSame([], $diags, 'null variant lists must not raise a PHP warning/notice');
    TestRunner::assertTrue($result === true, 'the variantParent must import (there are no children to create)');
});

TestRunner::test('T20.4 the documented client payload (object name, array type) imports cleanly', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('774', alegra_documented_client_payload()['message']['client']);

    $logger = make_logger();
    $customers = new \Alegra\Connector\Sync\Customers(new Client($logger), $logger);

    $result = null;
    $diags = alegra_php_diagnostics(function () use ($customers, &$result): void {
        $result = $customers->sync_single_contact_by_alegra_id('774');
    });

    TestRunner::assertSame([], $diags, 'the object `name` must not raise an array-to-string warning');
    TestRunner::assertTrue($result === true, 'the documented contact must import');

    $user = get_user_by('email', 'uncorreo@correo.com');
    TestRunner::assertTrue($user !== false, 'a WC user must be created for the documented contact');
    if ($user !== false) {
        $first = (string) get_user_meta($user->ID, 'billing_first_name', true);
        TestRunner::assertStringNotContains('Array', $first, 'the first name must not degrade to the literal "Array"');
        TestRunner::assertSame('Primer', $first, 'the first name must come from name.firstName');
    }
});

TestRunner::test('T20.5 a client name object with only `fullname` imports cleanly', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('775', [
        'name' => ['fullname' => 'Solo Nombre Completo'],
        'email' => 'full@example.com',
        'type' => ['client'],
    ]);

    $logger = make_logger();
    $customers = new \Alegra\Connector\Sync\Customers(new Client($logger), $logger);

    $result = null;
    $diags = alegra_php_diagnostics(function () use ($customers, &$result): void {
        $result = $customers->sync_single_contact_by_alegra_id('775');
    });

    TestRunner::assertSame([], $diags, 'the fullname shape must not raise a PHP warning/notice');
    TestRunner::assertTrue($result === true, 'the fullname contact must import');
    $user = get_user_by('email', 'full@example.com');
    TestRunner::assertTrue($user !== false, 'a WC user must be created');
    if ($user !== false) {
        TestRunner::assertSame('Solo', (string) get_user_meta($user->ID, 'billing_first_name', true), 'the first name must come from fullname');
    }
});

TestRunner::test('T20.6 an unknown/unsupported subject is acked with 2XX', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $body = json_encode(['subject' => 'new-bill', 'message' => ['bill' => ['id' => 'b-1']]]);
    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $res->get_status(), 'an unhandled subject must be acked, never rejected');
});

TestRunner::test('T20.7 a malformed body or scalar message is acked with 2XX (never counts toward deletion)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $notJson = $receiver->handle(new WP_REST_Request('not json', [], ['token' => 'tok']));
    TestRunner::assertSame(200, $notJson->get_status(), 'a non-JSON body must be acked');

    $noSubject = $receiver->handle(new WP_REST_Request('{"foo":"bar"}', [], ['token' => 'tok']));
    TestRunner::assertSame(200, $noSubject->get_status(), 'a body without subject must be acked');

    $arraySubject = $receiver->handle(new WP_REST_Request('{"subject":["x"],"message":[]}', [], ['token' => 'tok']));
    TestRunner::assertSame(200, $arraySubject->get_status(), 'an array subject must be acked');

    $scalarMessage = $receiver->handle(new WP_REST_Request('{"subject":"new-item","message":"oops"}', [], ['token' => 'tok']));
    TestRunner::assertSame(200, $scalarMessage->get_status(), 'a scalar message must be acked');
});

TestRunner::test('T20.8 a handler exception is acked with 2XX, not a 5xx', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    // A null API makes the item handler throw (call on null). The receiver must
    // swallow it and ack, so Alegra does not count a failure.
    $receiver = new \Alegra\Connector\Webhooks\Receiver(null, $logger);

    $body = json_encode(['subject' => 'new-item', 'message' => ['item' => ['id' => '865']]]);
    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $res->get_status(), 'a handler exception must be acked with 2XX');
    TestRunner::assertFalse((bool) (($res->get_data())['processed'] ?? true), 'the response must flag the failed processing');
});

TestRunner::test('T20.9 a replayed delivery is acked with 2XX', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $body = json_encode(['subject' => 'new-bill', 'message' => ['bill' => ['id' => 'b-1']]]);
    $first = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    $second = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));

    TestRunner::assertSame(200, $first->get_status(), 'the first delivery must be acked');
    TestRunner::assertSame(200, $second->get_status(), 'the replay must be acked so Alegra stops retrying');
    TestRunner::assertTrue((bool) (($second->get_data())['duplicate'] ?? false), 'the replay must be flagged');
});

TestRunner::test('T20.10 an unauthorized delivery is the ONLY non-2XX (401)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    $body = json_encode(alegra_documented_item_payload());
    $res = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(401, $res->get_status(), 'a delivery without the token must be 401');
});

// ===========================================================================
// T21 — Product import filters (docs/sdd/import-filters)
// ===========================================================================
echo "\nT21 — Product import filters\n";

TestRunner::test('T21.1 no filters keeps the historical page params', function (): void {
    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $params = alegra_call_private($admin, 'build_item_filter_params', [], true);
    TestRunner::assertSame(['status' => 'active'], $params, 'no filters on the page must yield only the implicit status=active');

    $metadata_params = alegra_call_private($admin, 'build_item_filter_params', [], false);
    TestRunner::assertSame([], $metadata_params, 'the metadata call must not add the implicit status');

    update_option('alegra_connector_sync_inactive_products', true);
    TestRunner::assertSame([], alegra_call_private($admin, 'build_item_filter_params', [], true), 'with inactive products enabled the implicit status is dropped');
});

TestRunner::test('T21.2 each filter maps to its documented Alegra query param', function (): void {
    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $f = alegra_call_private($admin, 'sanitize_item_filters', [
        'idItemCategory' => 'cat-9',
        'status'         => 'inactive',
        'inventariable'  => '1',
        'query'          => 'camisa',
        'type'           => 'kit',
    ]);
    $params = alegra_call_private($admin, 'build_item_filter_params', $f, true);
    TestRunner::assertSame('cat-9', $params['idItemCategory'] ?? null, 'category maps to idItemCategory');
    TestRunner::assertSame('inactive', $params['status'] ?? null, 'an explicit status wins over the default');
    TestRunner::assertSame('true', $params['inventariable'] ?? null, 'inventariable must be the string true');
    TestRunner::assertSame('camisa', $params['query'] ?? null, 'query maps to query');
    TestRunner::assertSame('kit', $params['type'] ?? null, 'kit maps to type');

    // variantParent is not a documented `type` filter value: never sent.
    $vp = alegra_call_private($admin, 'build_item_filter_params', ['type' => 'variantParent'], true);
    TestRunner::assertArrayNotHasKey('type', $vp, 'variantParent must not be sent to the API');
});

TestRunner::test('T21.3 invalid filter input degrades to defaults', function (): void {
    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $f = alegra_call_private($admin, 'sanitize_item_filters', [
        'status'        => 'hack',
        'type'          => '<script>',
        'inventariable' => 'x',
    ]);
    TestRunner::assertSame('default', $f['status'], 'invalid status degrades to default');
    TestRunner::assertSame('', $f['type'], 'invalid type degrades to empty');
    TestRunner::assertFalse($f['inventariable'], 'invalid inventariable degrades to false');
});

TestRunner::test('T21.4 ajax_sync_start totals only the filtered category', function (): void {
    alegra_test_reset();
    $cat = ['id' => 'cat-9', 'name' => 'Ropa'];
    for ($i = 1; $i <= 5; $i++) {
        alegra_mock_seed_item('in-' . $i, ['name' => 'In ' . $i, 'itemCategory' => $cat, 'status' => 'active']);
    }
    for ($i = 1; $i <= 3; $i++) {
        alegra_mock_seed_item('out-' . $i, ['name' => 'Out ' . $i, 'itemCategory' => ['id' => 'cat-1', 'name' => 'Otros'], 'status' => 'active']);
    }

    $_POST['sync_type'] = 'products';
    $_POST['filters'] = json_encode(['idItemCategory' => 'cat-9']);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn() => $admin->ajax_sync_start());
    unset($_POST['sync_type'], $_POST['filters']);

    TestRunner::assertTrue($resp->success, 'the response must be a success envelope');
    TestRunner::assertSame(5, (int) ($resp->payload['total_items'] ?? 0), 'the total must count only the filtered category');
});

TestRunner::test('T21.5 ajax_sync_page discards non-variantParent items for the variant filter', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('simple-1', ['name' => 'Simple', 'type' => 'simple', 'status' => 'active']);
    alegra_mock_seed_item('parent-1', ['name' => 'Parent', 'type' => 'variantParent', 'status' => 'active', 'variantAttributes' => [], 'itemVariants' => []]);

    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 2,
        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'filters' => ['type' => 'variantParent'],
    ], 600);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn() => $admin->ajax_sync_page());

    TestRunner::assertTrue($resp->success, 'the page must respond successfully');
    TestRunner::assertSame(1, (int) ($resp->payload['skipped'] ?? 0), 'the simple item must be skipped');
    TestRunner::assertSame(1, (int) ($resp->payload['imported'] ?? 0), 'the variantParent must be imported');
});

TestRunner::test('T21.6 the categories endpoint paginates across pages', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    for ($i = 1; $i <= 35; $i++) {
        alegra_mock_seed_category('cat-' . $i, ['name' => 'Cat ' . $i]);
    }

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn() => $admin->ajax_get_item_categories());

    TestRunner::assertTrue($resp->success, 'the endpoint must succeed');
    TestRunner::assertSame(35, count($resp->payload['categories'] ?? []), 'all 35 categories must be returned');
    TestRunner::assertFalse((bool) ($resp->payload['has_more'] ?? true), 'no more pages remain');
});

TestRunner::test('T21.7 the categories endpoint caps at 10 pages and flags more', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    for ($i = 1; $i <= 305; $i++) {
        alegra_mock_seed_category('cat-' . $i, ['name' => 'Cat ' . $i]);
    }

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn() => $admin->ajax_get_item_categories());

    TestRunner::assertSame(300, count($resp->payload['categories'] ?? []), 'the endpoint caps at 300 categories');
    TestRunner::assertTrue((bool) ($resp->payload['has_more'] ?? false), 'more pages must be flagged');
});

// ===========================================================================
// T22 — Preserve WooCommerce fields on import (import-filters)
// ===========================================================================
echo "\nT22 — Preserve WooCommerce fields on import\n";

TestRunner::test('T22.1 the setting preserves the WooCommerce description on update', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_import_preserve_fields', ['description']);
    alegra_make_product(80, ['name' => 'WC N', 'description' => 'WC D', 'regular_price' => '10', 'sku' => 'P-80']);
    update_post_meta(80, '_alegra_item_id', 'item-80');

    make_products()->import_single_item_public([
        'id' => 'item-80', 'name' => 'Alegra N', 'description' => 'Alegra D', 'reference' => 'P-80',
        'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 10]],
    ]);

    $product = wc_get_product(80);
    TestRunner::assertSame('WC D', $product->get_description(), 'the WC description must be preserved');
    TestRunner::assertSame('Alegra N', $product->get_name(), 'a non-listed field must still update');
});

TestRunner::test('T22.2 the setting preserves name, price and sku', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_import_preserve_fields', ['name', 'price', 'sku']);
    alegra_make_product(81, ['name' => 'WC N', 'regular_price' => '10', 'sku' => 'P-81']);
    update_post_meta(81, '_alegra_item_id', 'item-81');

    make_products()->import_single_item_public([
        'id' => 'item-81', 'name' => 'Alegra N', 'reference' => 'ALG-81',
        'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 99]],
    ]);

    $product = wc_get_product(81);
    TestRunner::assertSame('WC N', $product->get_name(), 'the WC name must be preserved');
    TestRunner::assertSame('10', $product->get_regular_price(), 'the WC price must be preserved');
    TestRunner::assertSame('P-81', $product->get_sku(), 'the WC sku must be preserved');
});

TestRunner::test('T22.3 with no setting the import overwrites everything (default)', function (): void {
    alegra_test_reset();
    alegra_make_product(82, ['name' => 'WC N', 'description' => 'WC D', 'regular_price' => '10', 'sku' => 'P-82']);
    update_post_meta(82, '_alegra_item_id', 'item-82');

    make_products()->import_single_item_public([
        'id' => 'item-82', 'name' => 'Alegra N', 'description' => 'Alegra D', 'reference' => 'ALG-82',
        'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 99]],
    ]);

    $product = wc_get_product(82);
    TestRunner::assertSame('Alegra D', $product->get_description(), 'without a setting the description is overwritten');
    TestRunner::assertSame('Alegra N', $product->get_name(), 'without a setting the name is overwritten');
    TestRunner::assertSame('99', $product->get_regular_price(), 'without a setting the price is overwritten');
});

TestRunner::test('T22.4 a new product ignores the setting and gets all Alegra data', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_import_preserve_fields', ['description', 'name', 'price', 'sku']);
    $product = alegra_make_product(90, ['name' => 'WC N', 'description' => 'WC D', 'regular_price' => '10', 'sku' => 'P-90']);

    alegra_call_private(make_products(), 'update_product_from_alegra', $product, [
        'id' => 'item-90', 'name' => 'Alegra N', 'description' => 'Alegra D', 'reference' => 'ALG-90',
        'status' => 'active', 'price' => [['idPriceList' => 1, 'price' => 99]],
    ], true);

    TestRunner::assertSame('Alegra N', $product->get_name(), 'a new product gets the Alegra name');
    TestRunner::assertSame('Alegra D', $product->get_description(), 'a new product gets the Alegra description');
    TestRunner::assertSame('99', $product->get_regular_price(), 'a new product gets the Alegra price');
    TestRunner::assertSame('ALG-90', $product->get_sku(), 'a new product gets the Alegra sku');
});

TestRunner::test('T22.5 sanitize_preserve_fields whitelists keys', function (): void {
    alegra_test_reset();
    $clean = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_preserve_fields(
        ['description', 'bogus', '<script>', 'name', 'name']
    );
    TestRunner::assertSame(['description', 'name'], $clean, 'only whitelisted unique keys survive');
    TestRunner::assertSame([], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_preserve_fields('nope'), 'a non-array yields []');
});

// ===========================================================================
// T23 — Guest order customer resolution (no WP_User)
// ===========================================================================
echo "\nT23 — Guest order customer resolution\n";

TestRunner::test('T23.1 a guest order (get_user() === false) does not fatal and falls back to Consumidor Final', function (): void {
    alegra_test_reset();
    $cf = seed_consumidor_final();
    // customer_id 0: WC_Order::get_user() returns false (not WP_User), which is
    // what used to be passed to the ?WP_User parameters and fataled.
    $order = make_invoice_order(700, 0, 'guest@example.test');

    $result = make_orders()->create_invoice($order);

    TestRunner::assertFalse(is_wp_error($result), 'a guest order must be invoiced without a TypeError');
    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame($cf, $invoice['client']['id'] ?? null, 'the guest order must use Consumidor Final');
});

TestRunner::test('T23.2 a guest order with billing data on the order creates a contact', function (): void {
    alegra_test_reset();
    seed_consumidor_final();
    // The catalog fields are opt-in; enable the two required ones.
    update_option(\Alegra\Connector\Billing_Fields::OPTION_ENABLED, ['idtype' => 1, 'identification' => 1]);

    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '10']);
    update_post_meta(10, '_alegra_item_id', '1t3m-5');
    $order = alegra_make_order(701, [
        'total' => 10.0, 'currency' => 'COP',
        'billing' => ['country' => 'CO', 'email' => 'guest2@example.test', 'first_name' => 'Juan', 'last_name' => 'Perez'],
        'customer_id' => 0,
        // Guest fiscal data lives on the ORDER meta (prefixed with _).
        'meta' => [
            '_billing_alegra_idtype'         => 'CC',
            '_billing_alegra_identification' => '1020304050',
        ],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 10, 'total' => 10])],
    ]);

    make_orders()->create_invoice($order);

    $body = alegra_mock_last_request('POST', '/contacts')['body'] ?? [];
    TestRunner::assertSame('1020304050', (string) ($body['identificationObject']['number'] ?? ''), 'the guest identification must reach Alegra');
});

// ===========================================================================
// T24 — Open a draft invoice
// ===========================================================================
echo "\nT24 — Open a draft invoice\n";

TestRunner::test('T24.1 ensure_invoice_open opens a draft and returns the open invoice', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-draft', ['status' => 'draft', 'balance' => 100]);

    $result = make_orders()->ensure_invoice_open('inv-draft');

    TestRunner::assertFalse(is_wp_error($result), 'opening must not error');
    TestRunner::assertSame('open', (string) ($result['status'] ?? ''), 'the returned invoice must be open');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/inv-draft/open'), 'exactly one open call');
});

TestRunner::test('T24.2 ensure_invoice_open is a no-op when the invoice is already open', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-open', ['status' => 'open', 'balance' => 100]);

    $result = make_orders()->ensure_invoice_open('inv-open');

    TestRunner::assertFalse(is_wp_error($result), 'a no-op must not error');
    TestRunner::assertSame('open', (string) ($result['status'] ?? ''), 'the invoice stays open');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-open/open'), 'no open call for an already-open invoice');
});

TestRunner::test('T24.3 ajax_open_invoice opens the order draft invoice', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-draft2', ['status' => 'draft', 'balance' => 50]);
    alegra_make_order(800, ['meta' => ['_alegra_invoice_id' => 'inv-draft2', '_alegra_invoice_number' => 'v1']]);

    $_POST['order_id'] = 800;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn() => $admin->ajax_open_invoice());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the handler must succeed');
    TestRunner::assertSame('open', (string) ($resp->payload['status'] ?? ''), 'the payload must report open');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/inv-draft2/open'), 'the open call must be sent');
    TestRunner::assertSame('open', (string) wc_get_order(800)->get_meta('_alegra_invoice_status', true), 'the cached status must be updated');
});

TestRunner::test('T24.4 persist_invoice_status caches the status and skips redundant saves', function (): void {
    alegra_test_reset();
    $order = alegra_make_order(810, []);
    $orders = make_orders();

    $orders->persist_invoice_status($order, ['status' => 'draft']);
    TestRunner::assertSame('draft', (string) $order->get_meta('_alegra_invoice_status', true), 'draft must be cached');

    $orders->persist_invoice_status($order, ['status' => 'open']);
    TestRunner::assertSame('open', (string) $order->get_meta('_alegra_invoice_status', true), 'open must replace draft');

    // An empty status must not clobber the cached value.
    $orders->persist_invoice_status($order, []);
    TestRunner::assertSame('open', (string) $order->get_meta('_alegra_invoice_status', true), 'an empty status is ignored');
});

// ===========================================================================
// T25 — Payments: "Facturar" registers the payment (Fase 1 + Fase 2)
//
// Spec:   docs/sdd/payments/spec.md   (REQ-MAN-1..4, REQ-PAY-1..2)
// Design: docs/sdd/payments/design.md (§1 causa raíz, §2 fuente de datos)
// ===========================================================================
echo "\nT25 — Facturar registra el pago (Fase 1+2)\n";

/**
 * A user create_invoice() can resolve to an Alegra contact.
 */
function make_payable_user(int $id = 1, string $email = 'pay@example.test'): void
{
    alegra_make_user($id, ['user_email' => $email, 'display_name' => 'Pay SA'], [
        'billing_alegra_idtype'         => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv'             => '1',
        'billing_first_name'            => 'Pay',
        'billing_last_name'             => 'SA',
    ]);
}

/**
 * An order create_invoice() can process. `$total` drives both the order total
 * and the single line item so the mock invoice balance matches.
 */
function make_payable_order(int $order_id, int $user_id, string $email, float $total = 10.0, array $data = []): WC_Order
{
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => (string) $total]);
    update_post_meta(10, '_alegra_item_id', '1t3m-5');
    return alegra_make_order($order_id, array_merge([
        'total' => $total,
        'currency' => 'COP',
        'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => $email],
        'customer_id' => $user_id,
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => $total, 'total' => $total])],
    ], $data));
}

/**
 * Click the order-detail "Facturar" button: the real AJAX handler, so reverting
 * Admin_Dashboard.php:2216 to 'create' breaks these tests.
 */
function click_facturar(int $order_id): Alegra_Test_JSON_Response
{
    $_POST['entity_type'] = 'order';
    $_POST['entity_id'] = $order_id;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    try {
        return alegra_capture_json(fn() => $admin->ajax_sync_single());
    } finally {
        unset($_POST['entity_type'], $_POST['entity_id']);
    }
}

function click_bulk_facturar(array $ids): Alegra_Test_JSON_Response
{
    $_POST['entity_type'] = 'order';
    $_POST['ids'] = $ids;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    try {
        return alegra_capture_json(fn() => $admin->ajax_bulk_sync());
    } finally {
        unset($_POST['entity_type'], $_POST['ids']);
    }
}

TestRunner::test('T-MAN-1a "Facturar" on a PAID order posts exactly ONE payment with WC data', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    $order = make_payable_order(2001, 1, 'pay@example.test', 150.0, [
        'payment_method' => 'mercadopago',
        'payment_method_title' => 'Mercado Pago',
        'date_paid' => new \DateTime('2026-09-20'),
    ]);

    $resp = click_facturar(2001);

    TestRunner::assertTrue($resp->success, 'the manual action must succeed');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the invoice must be created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'exactly one payment must be posted');

    $payment = alegra_mock_last_request('POST', '/payments')['body'] ?? [];
    TestRunner::assertSame('5', (string) ($payment['bankAccount']['id'] ?? ''), 'the configured account id must be sent');
    TestRunner::assertSame(150.0, (float) ($payment['invoices'][0]['amount'] ?? 0), 'the amount must be the WC order total');
    TestRunner::assertSame('2026-09-20', (string) ($payment['date'] ?? ''), 'the date must be the WC date_paid');
    TestRunner::assertSame('credit-card', (string) ($payment['paymentMethod'] ?? ''), 'Mercado Pago must map to credit-card');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_payment_id', true) !== '', '_alegra_payment_id must be stored');
});

TestRunner::test('T-MAN-1b "Facturar" on an UNPAID order creates the invoice, posts NO payment and warns', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    $order = make_payable_order(2002, 1, 'pay@example.test', 50.0, [
        'status' => 'on-hold',
        'payment_method' => 'bacs',
    ]);

    $resp = click_facturar(2002);

    TestRunner::assertTrue($resp->success, 'the manual action must succeed');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the invoice must still be created');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'an unpaid order must not post a payment');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), 'no payment id may be stored');
    TestRunner::assertStringContains('no figura pagado', implode("\n", $order->get_notes()), 'a note must explain why no payment was posted');
});

TestRunner::test('T-MAN-1c "Facturar" twice on an invoiced+paid order posts no duplicate payment', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    $order = alegra_make_order(2003, [
        'total' => 50.0,
        'status' => 'processing',
        'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-1', '_alegra_payment_id' => 'pay-1'],
    ]);

    $resp = click_facturar(2003);

    TestRunner::assertTrue($resp->success, 'the action must succeed');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no invoice may be re-created');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be re-posted');
    TestRunner::assertSame('pay-1', (string) $order->get_meta('_alegra_payment_id', true), 'the existing payment id must be kept');
});

TestRunner::test('T-MAN-2a bulk "Facturar seleccionados" posts one payment per paid order', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    make_payable_order(3001, 1, 'pay@example.test', 10.0, ['payment_method' => 'mercadopago']);
    make_payable_order(3002, 1, 'pay@example.test', 20.0, ['payment_method' => 'mercadopago']);

    $resp = click_bulk_facturar([3001, 3002]);

    TestRunner::assertTrue($resp->success, 'the bulk action must succeed');
    TestRunner::assertSame(2, alegra_mock_count('POST', '/invoices'), 'one invoice per order');
    TestRunner::assertSame(2, alegra_mock_count('POST', '/payments'), 'one payment per paid order');
    TestRunner::assertTrue((string) wc_get_order(3001)->get_meta('_alegra_payment_id', true) !== '', 'order 3001 must store its payment id');
    TestRunner::assertTrue((string) wc_get_order(3002)->get_meta('_alegra_payment_id', true) !== '', 'order 3002 must store its payment id');
});

TestRunner::test('T-MAN-2b bulk with one paid and one unpaid order posts a single payment', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    make_payable_order(3011, 1, 'pay@example.test', 10.0, ['payment_method' => 'mercadopago']);
    make_payable_order(3012, 1, 'pay@example.test', 10.0, ['status' => 'on-hold', 'payment_method' => 'bacs']);

    click_bulk_facturar([3011, 3012]);

    TestRunner::assertSame(2, alegra_mock_count('POST', '/invoices'), 'both invoices must be created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'only the paid order must post a payment');
});

TestRunner::test('T-MAN-3 "Facturar pendientes" (sync_recent) posts a payment for each paid order', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    make_payable_order(3021, 1, 'pay@example.test', 10.0, ['payment_method' => 'mercadopago']);
    make_payable_order(3022, 1, 'pay@example.test', 20.0, ['payment_method' => 'mercadopago']);
    make_payable_order(3023, 1, 'pay@example.test', 30.0, ['payment_method' => 'mercadopago']);

    $result = make_orders()->sync_recent(30);

    TestRunner::assertFalse(is_wp_error($result), 'sync_recent must not error');
    TestRunner::assertSame(3, alegra_mock_count('POST', '/invoices'), 'one invoice per order');
    TestRunner::assertSame(3, alegra_mock_count('POST', '/payments'), 'one payment per paid order');
});

TestRunner::test('T-MAN-4a a payment already recorded is never re-posted', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-4a', ['status' => 'open', 'balance' => 10.0, 'total' => 10.0]);
    $order = alegra_make_order(4001, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-4a', '_alegra_payment_id' => 'pay-1'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be re-posted');
    TestRunner::assertSame('pay-1', (string) $order->get_meta('_alegra_payment_id', true), 'the existing id must be kept');
});

TestRunner::test('T-MAN-4b a pre-existing Alegra payment is recovered without re-posting', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-4b', ['status' => 'open', 'balance' => 100.0, 'total' => 100.0]);
    alegra_mock_seed_payment('pay-existing', [
        'number' => 'P-EXISTING',
        'invoices' => [['id' => 'inv-4b', 'amount' => 100.0]],
    ]);
    $order = alegra_make_order(4002, [
        'total' => 100.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-4b', '_billing_alegra_contact_id' => 'c0n-4b'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no new payment may be posted');
    TestRunner::assertSame('pay-existing', (string) $order->get_meta('_alegra_payment_id', true), 'the found payment id must be stored');
});

TestRunner::test('T-PAY-1a a paid order with a NULL date_paid falls back to today with a warning', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    $order = make_payable_order(5001, 1, 'pay@example.test', 30.0, [
        'payment_method' => 'mercadopago',
        // date_paid intentionally omitted → null
    ]);

    make_orders()->create_invoice_with_payment($order);

    $payment = alegra_mock_last_request('POST', '/payments')['body'] ?? [];
    TestRunner::assertSame(date('Y-m-d'), (string) ($payment['date'] ?? ''), 'a missing paid date must fall back to today');
    TestRunner::assertStringContains('Payment date missing', alegra_read_log(), 'the fallback must be logged as a warning');
});

TestRunner::test('T-PAY-1b an order total different from the invoice balance is reported, not silently adjusted', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-100', ['status' => 'open', 'total' => 100.0, 'balance' => 80.0]);
    $order = alegra_make_order(5002, [
        'total' => 100.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-100', '_billing_alegra_contact_id' => 'c0n-100'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    $payment = alegra_mock_last_request('POST', '/payments')['body'] ?? [];
    TestRunner::assertSame(100.0, (float) ($payment['invoices'][0]['amount'] ?? 0), 'the payment must use the WC total, not the balance');
    TestRunner::assertStringContains('no coincide', implode("\n", $order->get_notes()), 'the discrepancy must be surfaced in a note');
    TestRunner::assertStringContains('differs from invoice balance', alegra_read_log(), 'the discrepancy must be logged');
});

TestRunner::test('T-PAY-1c the payment carries the gateway title in observations', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    $order = make_payable_order(5003, 1, 'pay@example.test', 10.0, [
        'payment_method' => 'mercadopago',
        'payment_method_title' => 'Mercado Pago',
    ]);

    make_orders()->create_invoice_with_payment($order);

    $payment = alegra_mock_last_request('POST', '/payments')['body'] ?? [];
    TestRunner::assertStringContains('Mercado Pago', (string) ($payment['observations'] ?? ''), 'observations must mention the gateway title');
});

TestRunner::test('T-PAY-2a both method resolvers agree and every value is a valid Alegra enum', function (): void {
    alegra_test_reset();
    $orders = make_orders();
    $enum = ['cash', 'check', 'transfer', 'deposit', 'credit-card', 'debit-card'];

    $mp_order = alegra_make_order(6001, ['payment_method' => 'mercadopago']);
    $private_mp = alegra_call_private($orders, 'get_payment_method_code', $mp_order);
    $public_mp = $orders->getPaymentMethodForGateway('mercadopago');
    TestRunner::assertSame('credit-card', $private_mp, 'Mercado Pago must map to credit-card');
    TestRunner::assertSame('credit-card', $public_mp, 'the public resolver must agree');
    TestRunner::assertTrue(in_array($private_mp, $enum, true), 'credit-card must be in the Alegra enum');

    $unknown_order = alegra_make_order(6002, ['payment_method' => 'gateway-desconocido']);
    $private_unknown = alegra_call_private($orders, 'get_payment_method_code', $unknown_order);
    $public_unknown = $orders->getPaymentMethodForGateway('gateway-desconocido');
    TestRunner::assertSame('transfer', $private_unknown, 'an unknown gateway must fall back to transfer');
    TestRunner::assertSame($private_unknown, $public_unknown, 'both resolvers must return the SAME fallback');
    TestRunner::assertTrue(in_array($private_unknown, $enum, true), 'transfer must be in the Alegra enum');
});

TestRunner::test('T-PAY-2b an unknown gateway still posts the payment with the transfer fallback', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    update_option('alegra_connector_payment_account_id', '5');
    make_payable_user();
    $order = make_payable_order(6003, 1, 'pay@example.test', 10.0, [
        'payment_method' => 'pasarela-inventada-xyz',
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'an unknown gateway must not block the payment');
    $payment = alegra_mock_last_request('POST', '/payments')['body'] ?? [];
    TestRunner::assertSame('transfer', (string) ($payment['paymentMethod'] ?? ''), 'the fallback must be transfer');
    TestRunner::assertStringContains('Unknown payment gateway', alegra_read_log(), 'the unmapped gateway must be logged');
});

TestRunner::test('T-DRAFT-1a a draft invoice is opened before the payment is posted', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-draft-pay', ['status' => 'draft', 'total' => 10.0, 'balance' => 10.0]);
    $order = alegra_make_order(7001, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-draft-pay', '_billing_alegra_contact_id' => 'c0n-draft'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/inv-draft-pay/open'), 'the draft must be opened before paying');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be posted');

    $open_pos = null;
    $pay_pos = null;
    foreach (alegra_mock_requests() as $i => $req) {
        if ($req['method'] === 'POST' && $req['path'] === '/invoices/inv-draft-pay/open') { $open_pos = $i; }
        if ($req['method'] === 'POST' && $req['path'] === '/payments') { $pay_pos = $i; }
    }
    TestRunner::assertTrue($open_pos !== null && $pay_pos !== null && $open_pos < $pay_pos, 'the invoice must be opened before the payment POST');
});

TestRunner::test('T-DRAFT-1b an already-open invoice is not re-opened before paying', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-open-pay', ['status' => 'open', 'total' => 10.0, 'balance' => 10.0]);
    $order = alegra_make_order(7002, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-open-pay', '_billing_alegra_contact_id' => 'c0n-open'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-open-pay/open'), 'an open invoice must not be re-opened');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must still be posted');
});

// ===========================================================================
// T26 — Reconciliación del pago posterior (Fase 3+4)
//
// Spec:   docs/sdd/payments/spec.md   (REQ-REC-1..6)
// Design: docs/sdd/payments/design.md (§5 hooks siempre-activos, §6 barrido)
// ===========================================================================
echo "\nT26 — Reconciliación del pago posterior (Fase 3+4)\n";

function make_public(?Logger $logger = null): \Alegra\Connector\Public\Public_
{
    $logger = $logger ?? make_logger();
    return new \Alegra\Connector\Public\Public_(new Client($logger), $logger);
}

/**
 * An order that ALREADY has an invoice in Alegra and no payment recorded.
 */
function make_reconcilable_order(int $order_id, string $invoice_id, array $data = []): WC_Order
{
    alegra_mock_seed_invoice($invoice_id, ['status' => 'open', 'total' => 10.0, 'balance' => 10.0]);
    return alegra_make_order($order_id, array_merge([
        'total' => 10.0,
        'status' => 'processing',
        'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => $invoice_id],
    ], $data));
}

TestRunner::test('T-REC-1 reconcile hooks are registered OUTSIDE the push_orders_enabled gate', function (): void {
    alegra_test_reset();
    unset($GLOBALS['wp_options']['alegra_connector_push_orders_enabled']); // fresh install: option absent
    $public = make_public();

    TestRunner::assertTrue(has_action('woocommerce_payment_complete', [$public, 'on_order_paid_reconcile']) !== false, 'payment_complete must reconcile');
    TestRunner::assertTrue(has_action('woocommerce_order_status_processing', [$public, 'on_order_paid_reconcile']) !== false, 'processing must reconcile');
    TestRunner::assertTrue(has_action('woocommerce_order_status_completed', [$public, 'on_order_paid_reconcile']) !== false, 'completed must reconcile');
    TestRunner::assertFalse(has_action('woocommerce_new_order', [$public, 'on_new_order']), 'manual mode must not create invoices');
    TestRunner::assertFalse(has_action('woocommerce_payment_complete', [$public, 'on_payment_complete']), 'the gated method must stay unregistered (T12.1)');
});

TestRunner::test('T-REC-2a no linked invoice: the hook neither creates one nor pays', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    alegra_make_order(8001, ['total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago']);

    do_action('woocommerce_order_status_processing', 8001);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'manual reconciliation must never create an invoice');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no invoice means no payment');
});

TestRunner::test('T-REC-2b an already-paid order is not paid twice', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8002, 'inv-rec-2b', ['meta' => ['_alegra_invoice_id' => 'inv-rec-2b', '_alegra_payment_id' => 'pay-1']]);

    do_action('woocommerce_order_status_processing', 8002);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'a recorded payment must not be duplicated');
});

TestRunner::test('T-REC-2c an unpaid order is not reconciled', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8003, 'inv-rec-2c', ['status' => 'on-hold']);

    do_action('woocommerce_payment_complete', 8003);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'is_paid() is the authority');
});

TestRunner::test('T-REC-3a status processing reconciles a payment onto an existing invoice', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    $order = make_reconcilable_order(8010, 'inv-rec-3a');

    do_action('woocommerce_order_status_processing', 8010);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'the invoice already exists');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be registered');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_payment_id', true) !== '', 'the payment id must be stored');
});

TestRunner::test('T-REC-3b payment_complete and processing together post exactly ONE payment', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8011, 'inv-rec-3b');

    do_action('woocommerce_payment_complete', 8011);
    do_action('woocommerce_order_status_processing', 8011);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'two triggers must yield one payment');
});

TestRunner::test('T-REC-3c status completed reconciles the payment too', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8012, 'inv-rec-3c', ['status' => 'completed']);

    do_action('woocommerce_order_status_completed', 8012);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'completed is a paid status');
});

TestRunner::test('T-REC-5 manual reconciliation with an existing invoice only records the payment', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8020, 'inv-rec-5');

    do_action('woocommerce_order_status_processing', 8020);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no invoice may be created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be attached');
});

TestRunner::test('T-REC-6 the hook and the sweep cannot double-pay the same order', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    make_reconcilable_order(8030, 'inv-rec-6');

    do_action('woocommerce_order_status_processing', 8030); // hook path
    make_controller()->run_payment_reconcile();              // sweep path, same order

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'hook + sweep must yield one payment');
});

TestRunner::test('T-REC-4a the sweep pays paid orders whose payment was missed', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_reconcilable_order(8101, 'inv-rec-4a-1');
    make_reconcilable_order(8102, 'inv-rec-4a-2');

    $result = make_controller()->run_payment_reconcile();

    TestRunner::assertSame(2, alegra_mock_count('POST', '/payments'), 'each pending order must be paid');
    TestRunner::assertSame(2, (int) ($result['reconciled'] ?? 0), 'the result must report reconciled=2');
});

TestRunner::test('T-REC-4b the sweep is idempotent (running twice posts one payment)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_reconcilable_order(8111, 'inv-rec-4b');
    $controller = make_controller();

    $controller->run_payment_reconcile();
    $controller->run_payment_reconcile();

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'a second sweep must not re-pay');
});

TestRunner::test('T-REC-4c the kill switch stops the sweep', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_reconcilable_order(8121, 'inv-rec-4c');
    \Alegra\Connector\Kill_Switch::activate('test');

    $result = make_controller()->run_payment_reconcile();

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'the kill switch must stop the sweep');
    TestRunner::assertSame('skipped_kill_switch', (string) ($result['skipped'] ?? ''), 'the result must report the kill switch');
});

TestRunner::test('T-REC-4d the global lock prevents two simultaneous sweeps', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_reconcilable_order(8131, 'inv-rec-4d');
    $token = Controller::acquire_lock('alegra_payment_reconcile', 300);

    $result = make_controller()->run_payment_reconcile();
    Controller::release_lock('alegra_payment_reconcile', (string) $token);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'a held lock must stop the sweep');
    TestRunner::assertSame('skipped_locked', (string) ($result['skipped'] ?? ''), 'the result must report the lock');
});

TestRunner::test('T-REC-4e the sweep ignores paid orders without an invoice', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_make_order(8141, ['total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago']);

    make_controller()->run_payment_reconcile();

    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'the sweep must not create invoices');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no invoice means no payment');
});

exit(TestRunner::summary());
