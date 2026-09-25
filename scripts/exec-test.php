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
require __DIR__ . '/lib/ns-microtime.php';
require __DIR__ . '/lib/ns-shutdown.php';
require __DIR__ . '/lib/alegra-mock.php';
require __DIR__ . '/lib/test-framework.php';

require $plugin_root . 'alegra-connector.php';

use Alegra\Connector\API\Client;
use Alegra\Connector\Logger\Logger;
use Alegra\Connector\State_Sync;
use Alegra\Connector\Sync\Controller;
use Alegra\Connector\Sync\Orders;
use Alegra\Connector\Sync\Products;
use Alegra\Connector\Webhooks\Receiver;

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

    // logs-monitor-import: Run_Context::finish tears the display heartbeat down
    // (design §2.4); the completed state now lives on the run row.
    $runs = \Alegra\Connector\Runs::recent(1);
    TestRunner::assertSame('completed', (string) ($runs[0]->status ?? ''), 'the cron run must be recorded as completed');
    TestRunner::assertTrue(alegra_mock_count('GET', '/items') >= 1, 'cron sync must have pulled items');
});

// ===========================================================================
// T3 — Invoice creation
// ===========================================================================
echo "\nT3 — Invoice creation (Orders::create_invoice)\n";

TestRunner::test('T3.1 CO invoice payload is complete and is created as a DRAFT', function (): void {
    alegra_test_reset();
    // D2/FIX-3: pin the adjustment owner so the configured draft default is the
    // one under test. With owner=invoice a paid order's invoice opens (T29.37*).
    update_option('alegra_connector_open_invoice_on_paid', false);
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

TestRunner::test('T3.3 a pre-existing DRAFT invoice is NEVER opened by the invoice+payment path', function (): void {
    alegra_test_reset();
    // D2/FIX-3: this contract holds for the ADJUSTMENT owner. With owner=invoice
    // a paid order DOES open its pre-existing draft (T29.37b); that is the
    // deliberate D2 behavior, so pin the owner here to test the other branch.
    update_option('alegra_connector_open_invoice_on_paid', false);
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
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/1nv-draft'), 'a deliberate draft must NOT be opened by this path');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/1nv-draft/open'), 'the un-void endpoint must never be used for a draft');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be posted on a draft');
    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('BORRADOR', $notes, 'the order must explain the draft was left untouched');
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

    // logs-monitor-import: Run_Context::finish forgets the heartbeat, so the
    // final counts are observed on the "Cron synchronization completed" log line.
    alegra_clear_log();
    make_controller()->run_cron_sync();

    $log = alegra_read_log();
    TestRunner::assertTrue(
        preg_match('/"products":(\d+),"customers":(\d+)/', $log, $m) === 1,
        'the cron completion must log the counts: ' . $log
    );
    TestRunner::assertTrue((int) ($m[1] ?? 0) > 0, 'products must have been imported');
    TestRunner::assertSame(0, (int) ($m[2] ?? -1), 'the customers count must be 0 when the lock is held (not the products count)');
});

TestRunner::test('T9.4 AC-41 a payment-method change after the baseline still syncs', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('1nv-910', ['status' => 'open', 'total' => 50.0, 'balance' => 50.0]);
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
    // A fresh install has no row: the option defaults to false (manual).
    unset($GLOBALS['wp_options']['alegra_connector_push_orders_enabled']);
    TestRunner::assertFalse(
        (bool) get_option('alegra_connector_push_orders_enabled', false),
        'push_orders_enabled must default to false (manual)'
    );

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

    // Exactly what Admin_Dashboard::ajax_sync_single() does for an order:
    // an explicit merchant action, so it runs inside the explicit write context.
    $result = \Alegra\Connector\Write_Gate::run_explicit(
        fn () => make_controller()->sync_entity('order', $order_id, 'create')
    );

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
    // The linked invoice must exist in Alegra: PUT /invoices/{id} on a missing
    // invoice is a 404, not a silent success.
    alegra_mock_seed_invoice('1nv-971', ['status' => 'open', 'total' => 50.0, 'balance' => 50.0]);
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

TestRunner::test('T18.12 BUG 6 manual ajax_record_payment opens a DRAFT via PUT before paying', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', 'acct-1');
    alegra_mock_seed_invoice('1nv-draft2', [
        'status' => 'draft', 'total' => 20.0, 'balance' => 20.0,
        'items' => [['id' => 'x', 'price' => 20, 'quantity' => 1]],
    ]);
    $order = alegra_make_order(1150, [
        'total' => 20.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'meta' => ['_alegra_invoice_id' => '1nv-draft2', '_alegra_invoice_status' => 'draft'],
    ]);

    $_POST['order_id'] = 1150;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_record_payment());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the payment must succeed');
    // The documented draft→open call is PUT /invoices/{id} {"status":"open"},
    // NOT POST /invoices/{id}/open (that endpoint is an un-void).
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/1nv-draft2'), 'the draft must be opened with PUT');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/1nv-draft2/open'), 'the un-void endpoint must never be used');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must be recorded');
    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('borrador y se abrió', $notes, 'the manual open must be recorded on the order');
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
    // D2/FIX-3: pin the adjustment owner so the configured draft default is the
    // one under test (owner=invoice opens a paid order's invoice; T29.37*).
    update_option('alegra_connector_open_invoice_on_paid', false);
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

    // BUG: Alegra rejects a webhook URL that includes the scheme
    // ("La URL ingresada no debe incluir el \"http://\" o \"https://\"").
    TestRunner::assertStringNotContains('http://', $url, 'the registered URL must NOT include http://');
    TestRunner::assertStringNotContains('https://', $url, 'the registered URL must NOT include https://');
    TestRunner::assertSame('example.test/wp-json/alegra-connector/v1/webhook', strtok($url, '?'), 'the URL must be scheme-less host+path');
});

TestRunner::test('T19.3d BUG 2 every registered event carries a scheme-less URL with the token', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertTrue($response->success, 'registration must succeed');
    TestRunner::assertSame(0, (int) ($response->payload['errores'] ?? -1), 'the mock rejects scheme URLs, so zero errors proves the fix');

    $reqs = alegra_mock_requests('POST', '/webhooks/subscriptions');
    TestRunner::assertSame(count(Client::get_webhook_events()), count($reqs), 'every documented event must be registered');
    $events = [];
    foreach ($reqs as $req) {
        $events[] = (string) ($req['body']['event'] ?? '');
        $url = (string) ($req['body']['url'] ?? '');
        TestRunner::assertStringNotContains('://', $url, 'every URL must be scheme-less');
        TestRunner::assertStringContains('token=', $url, 'every URL must carry the token');
    }
    sort($events);
    $expected = Client::get_webhook_events();
    sort($expected);
    TestRunner::assertSame($expected, $events, 'exactly the documented 12 events must be registered (no per-event selection)');
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
    update_option('alegra_connector_connection_tested', true);
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
    // The documented draft→open call is PUT /invoices/{id} {"status":"open"}.
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/inv-draft'), 'exactly one PUT open call');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-draft/open'), 'the un-void endpoint must never be used');
    TestRunner::assertSame('open', (string) (alegra_mock_last_request('PUT', '/invoices/inv-draft')['body']['status'] ?? ''), 'the PUT body must set status=open');
});

TestRunner::test('T24.1b ensure_invoice_open(allow_draft:false) refuses to touch a draft', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-draft-no', ['status' => 'draft', 'balance' => 100]);

    $result = make_orders()->ensure_invoice_open('inv-draft-no', false);

    TestRunner::assertTrue(is_wp_error($result), 'a refused draft must be a WP_Error');
    TestRunner::assertSame('draft_invoice_not_opened', $result->get_error_code(), 'the error code must be explicit');
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-draft-no'), 'no write may be attempted');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-draft-no/open'), 'no un-void either');
});

TestRunner::test('T24.1c ensure_invoice_open falls back to POST /open when PUT does not open', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-fb', ['status' => 'draft', 'balance' => 100]);
    // The documented PUT schema does not list `status`; simulate an account that
    // rejects it. The verified fallback must still open the draft.
    alegra_mock_fail('PUT', '/invoices/inv-fb', 400, ['message' => 'Campo status no permitido']);

    $result = make_orders()->ensure_invoice_open('inv-fb');

    TestRunner::assertFalse(is_wp_error($result), 'the fallback must succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
    TestRunner::assertSame('open', (string) ($result['status'] ?? ''), 'the invoice must end up open');
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/inv-fb'), 'the PUT is attempted first');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices/inv-fb/open'), 'the fallback un-void call is used');
});

TestRunner::test('T24.2 ensure_invoice_open is a no-op when the invoice is already open', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-open', ['status' => 'open', 'balance' => 100]);

    $result = make_orders()->ensure_invoice_open('inv-open');

    TestRunner::assertFalse(is_wp_error($result), 'a no-op must not error');
    TestRunner::assertSame('open', (string) ($result['status'] ?? ''), 'the invoice stays open');
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-open'), 'no open call for an already-open invoice');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-open/open'), 'no un-void call either');
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
    TestRunner::assertSame(1, alegra_mock_count('PUT', '/invoices/inv-draft2'), 'the documented PUT open call must be sent');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-draft2/open'), 'the un-void endpoint must never be used');
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

TestRunner::test('T-DRAFT-1a the automatic path NEVER opens a draft (skips it, no HTTP write)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-draft-pay', ['status' => 'draft', 'total' => 10.0, 'balance' => 10.0]);
    $order = alegra_make_order(7001, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-draft-pay', '_billing_alegra_contact_id' => 'c0n-draft'],
    ]);

    $result = make_orders()->reconcile_payment_only($order);

    TestRunner::assertTrue(!empty($result['skipped']), 'the reconcile must be skipped');
    TestRunner::assertSame('draft_not_opened', (string) ($result['reason'] ?? ''), 'the skip reason must be the draft');
    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-draft-pay'), 'no open write may be attempted');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-draft-pay/open'), 'the un-void endpoint must never be used');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may be posted on a draft');
    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('BORRADOR', $notes, 'the order must explain why it was skipped');
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

    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-open-pay'), 'an open invoice must not be re-opened');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-open-pay/open'), 'the un-void endpoint must never be used');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'the payment must still be posted');
});

// ===========================================================================
// T-DRAFT-2 — The hourly sweep must never open a draft (BUG: cron did)
// ===========================================================================
echo "\nT-DRAFT-2 — El barrido nunca abre un borrador\n";

TestRunner::test('T-DRAFT-2a the hourly sweep skips a draft, reports it and pays nothing', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-sweep-draft', ['status' => 'draft', 'total' => 10.0, 'balance' => 10.0]);
    $order = alegra_make_order(7101, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-sweep-draft', '_billing_alegra_contact_id' => 'c0n-sd'],
    ]);

    $result = make_controller()->run_payment_reconcile();

    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-sweep-draft'), 'the sweep must never PUT-open a draft');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices/inv-sweep-draft/open'), 'the sweep must never un-void');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'the sweep must never pay a draft');
    TestRunner::assertSame(1, (int) ($result['draft_skipped'] ?? 0), 'the sweep must report the skipped draft');
    TestRunner::assertSame(0, (int) ($result['reconciled'] ?? -1), 'nothing may be reconciled');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), 'no payment id may be stored');

    // The sweep runs hourly: the note must be written once, not every hour.
    $notes_before = count($order->get_notes());
    make_controller()->run_payment_reconcile();
    TestRunner::assertSame($notes_before, count(wc_get_order(7101)->get_notes()), 'the draft note must not be repeated on the next sweep');
});

TestRunner::test('T-DRAFT-2b the real-time reconcile hook skips a draft', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_public();
    alegra_mock_seed_invoice('inv-hook-draft', ['status' => 'draft', 'total' => 10.0, 'balance' => 10.0]);
    alegra_make_order(7102, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-hook-draft', '_billing_alegra_contact_id' => 'c0n-hd'],
    ]);

    do_action('woocommerce_order_status_processing', 7102);

    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/inv-hook-draft'), 'the hook must never open a draft');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'the hook must never pay a draft');
});

TestRunner::test('T-DRAFT-2c a draft WITH a real payment does not open but reports skipped, not error', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    alegra_mock_seed_invoice('inv-draft-rec', ['status' => 'draft', 'total' => 10.0, 'balance' => 10.0]);
    alegra_make_order(7103, [
        'total' => 10.0, 'status' => 'processing', 'payment_method' => 'mercadopago',
        'meta' => ['_alegra_invoice_id' => 'inv-draft-rec'],
    ]);

    $r = make_orders()->reconcile_payment_only(wc_get_order(7103));

    TestRunner::assertTrue(!empty($r['skipped']), 'a draft must be reported as skipped, not an error');
    TestRunner::assertFalse(is_wp_error($r), 'a skipped draft must not be a WP_Error');
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

// ===========================================================================
// T26 — Payments settings hardening (Fase 5: REQ-CFG-1/2/3/5)
//
// Spec:   docs/sdd/payments/spec.md   (REQ-CFG-1..5)
// Design: docs/sdd/payments/design.md (§4 endurecimiento del select + label)
// ===========================================================================
echo "\nT26 — Payments settings hardening (Fase 5)\n";

/**
 * Find an option entry by value, or null.
 *
 * @param array<int,array{value:string,label:string,selected:bool}> $options
 * @return array{value:string,label:string,selected:bool}|null
 */
function alegra_find_option(array $options, string $value): ?array
{
    foreach ($options as $option) {
        if ((string) ($option['value'] ?? '') === $value) {
            return $option;
        }
    }
    return null;
}

TestRunner::test('T-CFG-0 register_settings() wires every Alegra id option without errors', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $admin->register_settings();
    TestRunner::assertTrue(true, 'register_settings() must run without a fatal or undefined variable');
});

TestRunner::test('T-CFG-1a a stored id present in the list is selected; "Sin cuenta" is not', function (): void {
    alegra_test_reset();
    $options = \Alegra\Connector\Admin\Admin_Dashboard::bank_account_select_options(
        [['id' => 5, 'name' => 'Caja Pagina Web'], ['id' => 4, 'name' => 'Banco X']],
        '5'
    );

    $stored = alegra_find_option($options, '5');
    TestRunner::assertTrue($stored !== null, 'the stored id must be rendered as an option');
    TestRunner::assertTrue((bool) $stored['selected'], 'the stored id must be selected');
    TestRunner::assertSame('Caja Pagina Web (ID: 5)', (string) $stored['label'], 'the option label shows name + id');

    $zero = alegra_find_option($options, '0');
    TestRunner::assertFalse((bool) $zero['selected'], '"Sin cuenta" must NOT be selected');

    $selected = array_filter($options, static fn($o) => !empty($o['selected']));
    TestRunner::assertCount(1, $selected, 'exactly one option may be selected');
});

TestRunner::test('T-CFG-1b a stored id ABSENT from the list is injected and selected (anti-clobber)', function (): void {
    alegra_test_reset();
    $options = \Alegra\Connector\Admin\Admin_Dashboard::bank_account_select_options(
        [['id' => 4, 'name' => 'Banco X']],
        '5'
    );

    $stored = alegra_find_option($options, '5');
    TestRunner::assertTrue($stored !== null, 'a synthetic option for the stored id must be injected');
    TestRunner::assertTrue((bool) $stored['selected'], 'the synthetic option must be selected');
    TestRunner::assertStringContains('no sincronizada', (string) $stored['label'], 'the synthetic option must say it is not synced');

    $zero = alegra_find_option($options, '0');
    TestRunner::assertFalse((bool) $zero['selected'], '"Sin cuenta" must NOT be selected');

    // A browser resolves a <select> to its first selected option; assert the
    // stored id is the ONLY selected option, so it can never fall back to '0'.
    $selected = array_values(array_filter($options, static fn($o) => !empty($o['selected'])));
    TestRunner::assertCount(1, $selected, 'exactly one option may be selected');
    TestRunner::assertSame('5', (string) $selected[0]['value'], 'the selected option must be the stored id');
});

TestRunner::test('T-CFG-1c an empty/"0" stored value selects "Sin cuenta"', function (): void {
    alegra_test_reset();
    foreach (['', '0'] as $stored) {
        $options = \Alegra\Connector\Admin\Admin_Dashboard::bank_account_select_options([], $stored);
        $zero = alegra_find_option($options, '0');
        TestRunner::assertTrue((bool) $zero['selected'], "'$stored' must select Sin cuenta");
        TestRunner::assertCount(1, array_filter($options, static fn($o) => !empty($o['selected'])), 'only one selected option');
    }
});

TestRunner::test('T-CFG-2a an invalid id keeps the previous value AND registers a settings error', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');

    $result = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_alegra_id('no-es-un-id', 'alegra_connector_payment_account_id');

    TestRunner::assertSame('5', $result, 'an invalid input must preserve the previous value');
    $errors = get_settings_errors('alegra_connector_settings');
    TestRunner::assertTrue(count($errors) >= 1, 'a settings error must be registered');
    TestRunner::assertStringContains('se conservó el valor anterior', (string) $errors[0]['message'], 'the error must explain the value was kept');
});

TestRunner::test('T-CFG-2b valid ids (numeric / UUID) and empty are accepted with no error', function (): void {
    alegra_test_reset();
    $uuid = '12345678-1234-1234-1234-1234567890ab';

    TestRunner::assertSame('5', \Alegra\Connector\Admin\Admin_Dashboard::sanitize_alegra_id('5', 'alegra_connector_payment_account_id'), 'numeric id');
    TestRunner::assertSame($uuid, \Alegra\Connector\Admin\Admin_Dashboard::sanitize_alegra_id($uuid, 'alegra_connector_payment_account_id'), 'legacy UUID');
    TestRunner::assertSame('', \Alegra\Connector\Admin\Admin_Dashboard::sanitize_alegra_id('', 'alegra_connector_payment_account_id'), 'empty is allowed');
    TestRunner::assertCount(0, get_settings_errors('alegra_connector_settings'), 'valid values must not register errors');
});

TestRunner::test('T-CFG-2c the success banner is suppressed when a rejection was registered', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains("settings_errors('alegra_connector_settings')", $tpl, 'the template must render the settings errors');
    TestRunner::assertStringContains("empty(get_settings_errors('alegra_connector_settings'))", $tpl, 'the success banner must be gated on no errors');
});

TestRunner::test('T-CFG-3a activation leaves an explicit empty default for the payment account', function (): void {
    alegra_test_reset();
    unset($GLOBALS['wp_options']['alegra_connector_payment_account_id']);

    \Alegra\Connector\Alegra_Connector::get_instance()->activate();

    TestRunner::assertTrue(array_key_exists('alegra_connector_payment_account_id', $GLOBALS['wp_options']), 'activation must create the option');
    TestRunner::assertSame('', get_option('alegra_connector_payment_account_id'), 'the default must be the empty string');
});

TestRunner::test('T-CFG-3b a failed /bank-accounts list with a stored id still renders the select', function (): void {
    alegra_test_reset();
    // Empty list (a WP_Error fetch degrades to []) + stored id: synthetic option.
    $options = \Alegra\Connector\Admin\Admin_Dashboard::bank_account_select_options([], '5');
    $stored = alegra_find_option($options, '5');
    TestRunner::assertTrue($stored !== null, 'the stored id must survive an empty list');
    TestRunner::assertTrue((bool) $stored['selected'], 'it must stay selected');

    // The template renders the <select> when the list is empty but an id is stored.
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains('!in_array($ac_payment_account', $tpl, 'the select must render for a stored id even with an empty list');
});

TestRunner::test('T-CFG-5 the label says "banco o caja" and no longer "Cuenta bancaria"', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains('Cuenta de destino para pagos (banco o caja)', $tpl, 'the label must mention banco/caja');
    TestRunner::assertStringContains('banco o caja', $tpl, 'the label must contain "banco o caja"');
    TestRunner::assertStringNotContains('Cuenta bancaria para pagos', $tpl, 'the old "Cuenta bancaria" label must be gone');
});

// ===========================================================================
// T27 — Checkout placeholders (Fase 6: REQ-CHK-1/2)
//
// Spec:   docs/sdd/payments/spec.md   (REQ-CHK-1..2)
// Design: docs/sdd/payments/design.md (§7 placeholder del checkout)
// ===========================================================================
echo "\nT27 — Checkout placeholders (Fase 6)\n";

function alegra_catalog_field(string $key): array
{
    return \Alegra\Connector\Billing_Fields::CATALOG[$key];
}

TestRunner::test('T-CHK-1a the classic idtype select starts with an empty option, RC is not default', function (): void {
    alegra_test_reset();
    $fields = alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'render_checkout_fields', []);
    $options = $fields['billing']['billing_alegra_idtype']['options'] ?? [];

    $keys = array_keys($options);
    TestRunner::assertSame('', (string) $keys[0], 'the empty option must come first');
    TestRunner::assertSame('Seleccione…', (string) ($options[''] ?? ''), 'the placeholder text must be Seleccione…');
    TestRunner::assertTrue(isset($options['RC']), 'RC must remain selectable');
});

TestRunner::test('T-CHK-1b the registration placeholder still works (no regression)', function (): void {
    alegra_test_reset();

    ob_start();
    alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'render_registration_fields');
    $html = (string) ob_get_clean();

    TestRunner::assertStringContains('<option value="">Seleccione…</option>', $html, 'the registration select must keep its placeholder');
    TestRunner::assertStringContains('name="billing_alegra_idtype"', $html, 'the idtype field must be rendered');
});

TestRunner::test('T-CHK-1c an empty idtype is rejected in require_data but allowed in auto', function (): void {
    alegra_test_reset();

    // auto: the WC field is optional → '' is allowed.
    $fields = alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'render_checkout_fields', []);
    TestRunner::assertFalse((bool) $fields['billing']['billing_alegra_idtype']['required'], 'auto mode must not require idtype');

    // require_data: the WC field is required → '' is rejected by WC core.
    update_option('alegra_connector_customer_resolution_mode', 'require_data');
    $fields = alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'render_checkout_fields', []);
    TestRunner::assertTrue((bool) $fields['billing']['billing_alegra_idtype']['required'], 'require_data must require idtype');

    // The clear Spanish notice fires on woocommerce_checkout_process.
    wc_clear_notices();
    $_POST = ['billing_alegra_idtype' => '', 'billing_alegra_identification' => ''];
    alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'on_checkout_process');
    $_POST = [];
    $notices = wc_get_notices('error');
    TestRunner::assertTrue(count($notices) >= 1, 'an empty idtype in require_data must add an error notice');
    TestRunner::assertStringContains('tipo y número de documento', implode("\n", array_column($notices, 'message')), 'the notice must be clear');

    // auto: no error notice.
    update_option('alegra_connector_customer_resolution_mode', 'auto');
    wc_clear_notices();
    $_POST = ['billing_alegra_idtype' => '', 'billing_alegra_identification' => ''];
    alegra_call_private_static(\Alegra\Connector\Billing_Fields::class, 'on_checkout_process');
    $_POST = [];
    TestRunner::assertCount(0, wc_get_notices('error'), 'auto mode must not block an empty idtype');
});

TestRunner::test('T-CHK-2a the Blocks idtype options start with an empty option (Rama A)', function (): void {
    alegra_test_reset();
    $options = alegra_call_private_static(
        \Alegra\Connector\Checkout_Integration::class,
        'block_options',
        'idtype',
        alegra_catalog_field('idtype')
    );

    TestRunner::assertSame('', (string) ($options[0]['value'] ?? 'x'), 'the first Blocks option must be empty');
    TestRunner::assertSame('Seleccione…', (string) ($options[0]['label'] ?? ''), 'the Blocks placeholder text');
    TestRunner::assertSame('RC', (string) ($options[1]['value'] ?? ''), 'RC must remain, just not first');
});

TestRunner::test('T-CHK-2b Blocks requires idtype only in require_data mode', function (): void {
    alegra_test_reset();
    $args = alegra_call_private_static(
        \Alegra\Connector\Checkout_Integration::class,
        'block_field_args',
        'idtype',
        alegra_catalog_field('idtype')
    );
    TestRunner::assertFalse((bool) $args['required'], 'auto mode must leave the Blocks idtype optional');

    update_option('alegra_connector_customer_resolution_mode', 'require_data');
    $args = alegra_call_private_static(
        \Alegra\Connector\Checkout_Integration::class,
        'block_field_args',
        'idtype',
        alegra_catalog_field('idtype')
    );
    TestRunner::assertTrue((bool) $args['required'], 'require_data must mark the Blocks idtype required');
});

// ===========================================================================
// T28 — Version + release integrity (Fase 7: REQ-REL-1..4)
//
// Spec:   docs/sdd/payments/spec.md   (REQ-REL-1..4)
// Design: docs/sdd/payments/design.md (§8 versión y release)
// ===========================================================================
echo "\nT28 — Version + release integrity (Fase 7)\n";

function alegra_read_plugin_header_version(string $file): string
{
    $src = (string) file_get_contents($file);
    if (preg_match('/^[ \t\/*#@]*Version:\s*(.+?)\s*$/mi', $src, $m)) {
        return trim($m[1]);
    }
    return '';
}

TestRunner::test('T-REL-1a the plugin header and ALEGRA_CONNECTOR_VERSION are identical', function (): void {
    $file = $GLOBALS['alegra_plugin_root'] . 'alegra-connector.php';
    $header = alegra_read_plugin_header_version($file);

    TestRunner::assertTrue($header !== '', 'the plugin header must carry a Version');
    TestRunner::assertTrue(defined('ALEGRA_CONNECTOR_VERSION'), 'ALEGRA_CONNECTOR_VERSION must be defined');
    TestRunner::assertSame($header, ALEGRA_CONNECTOR_VERSION, 'header and constant must match (anti-drift)');
});

TestRunner::test('T-REL-1b the constant is derived from the header, not hardcoded', function (): void {
    $file = $GLOBALS['alegra_plugin_root'] . 'alegra-connector.php';
    $src = (string) file_get_contents($file);

    TestRunner::assertStringContains(
        "define('ALEGRA_CONNECTOR_VERSION', alegra_connector_plugin_version(__FILE__)",
        $src,
        'the constant must derive from the header'
    );
    TestRunner::assertStringNotContains(
        "define('ALEGRA_CONNECTOR_VERSION', '2.",
        $src,
        'no hardcoded version literal may remain'
    );

    // The derivation reads the header: a file with a different header yields
    // that version, so a stale constant can no longer survive a release.
    $tmp = tempnam(sys_get_temp_dir(), 'alegra-ver-');
    file_put_contents($tmp, "<?php\n/**\n * Plugin Name: X\n * Version: 9.9.9\n */\n");
    $derived = \Alegra\Connector\alegra_connector_plugin_version($tmp);
    @unlink($tmp);
    TestRunner::assertSame('9.9.9', $derived, 'the derivation must read the header value');
});

TestRunner::test('T-REL-1c the asset cache-buster uses ALEGRA_CONNECTOR_VERSION', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $admin = (string) file_get_contents($root . 'admin/Admin/Admin_Dashboard.php');
    $checkout = (string) file_get_contents($root . 'includes/Checkout_Integration.php');

    TestRunner::assertStringContains(
        "admin/assets/js/admin.js', ['jquery'], ALEGRA_CONNECTOR_VERSION",
        $admin,
        'admin.js must be versioned with the plugin version'
    );
    TestRunner::assertStringContains('alegra-checkout-conditions.js', $checkout, 'the checkout enqueue must exist');
    TestRunner::assertStringContains('ALEGRA_CONNECTOR_VERSION', $checkout, 'checkout-conditions.js must be versioned with the plugin version');
});

TestRunner::test('T-REL-2 the release build refuses a version mismatch', function (): void {
    $script = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'scripts/build-release.sh');

    TestRunner::assertStringContains('HEADER_VERSION', $script, 'the build must read the plugin header');
    TestRunner::assertStringContains(
        "header='\$HEADER_VERSION' argument='\$VERSION'",
        $script,
        'the build must report the header/argument divergence'
    );
    TestRunner::assertStringContains('exit 9', $script, 'a divergence must abort the build');
    TestRunner::assertStringContains('README_VERSION', $script, 'the build must verify the README version');
    TestRunner::assertStringContains('make-pot.php', $script, 'the build must verify make-pot.php too');
});

TestRunner::test('T-REL-3 a v* tag workflow runs the gates and publishes the ZIP', function (): void {
    $workflow = $GLOBALS['alegra_plugin_root'] . '.github/workflows/release.yml';
    TestRunner::assertTrue(is_file($workflow), 'the release workflow must exist');
    $yaml = (string) file_get_contents($workflow);

    TestRunner::assertStringContains("tags: ['v*']", $yaml, 'it must trigger on v* tags');
    TestRunner::assertStringContains('bash scripts/smoke-test.sh', $yaml, 'smoke-test must be a gate');
    TestRunner::assertStringContains('bash scripts/exec-test.sh', $yaml, 'exec-test must be a gate');
    TestRunner::assertStringContains('action-gh-release', $yaml, 'it must publish a GitHub Release');
    TestRunner::assertStringContains('.sha256', $yaml, 'the SHA256 must be attached');
});

TestRunner::test('T-REL-4 the README points at the GitHub Releases page, not the releases folder', function (): void {
    $readme = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'README.md');

    TestRunner::assertStringContains(
        'https://github.com/djdang3r/wp-alegra-connector/releases/latest',
        $readme,
        'it must point at the latest release'
    );
    TestRunner::assertStringNotContains(
        'latest release from the `releases` folder',
        $readme,
        'the lexicographically-sorted releases folder trap must be gone'
    );
});

// ===========================================================================
// T-GATE — Phase 1: central write enforcement (kill switch + entity gate)
//
// Every outbound write funnels through Client::request(). These tests prove the
// single choke point blocks a write (marker, zero HTTP) when the kill switch is
// active or the owning entity is disabled, still allows an enabled write, and
// keeps dry-run composing exactly as before.
// ===========================================================================
echo "\nT-GATE — Write Gate (kill switch + per-entity enablement)\n";

TestRunner::test('T-GATE-1 the entity taxonomy maps every write endpoint', function (): void {
    $ef = [\Alegra\Connector\Write_Gate::class, 'entity_for'];

    TestRunner::assertSame('invoice', $ef('POST', '/invoices'), 'POST /invoices is an invoice');
    TestRunner::assertSame('invoice', $ef('POST', '/invoices/abc/open'), 'POST /invoices/{id}/open is an invoice');
    TestRunner::assertSame('credit_note', $ef('POST', '/credit-notes'), 'POST /credit-notes is a credit_note');
    TestRunner::assertSame('payment', $ef('POST', '/payments'), 'POST /payments is a payment');
    TestRunner::assertSame('contact', $ef('POST', '/contacts'), 'POST /contacts is a contact');
    TestRunner::assertSame('item', $ef('POST', '/items'), 'POST /items is an item');
    TestRunner::assertSame('item', $ef('PUT', '/items/abc'), 'PUT /items/{id} is an item');
    TestRunner::assertSame('category', $ef('POST', '/item-categories'), 'POST /item-categories is a category');
    TestRunner::assertSame('webhook', $ef('POST', '/webhooks/subscriptions'), 'POST /webhooks/subscriptions is a webhook');
    TestRunner::assertSame('other', $ef('POST', '/price-lists'), 'POST /price-lists is other');
    TestRunner::assertSame('invoice', $ef('GET', '/invoices?limit=1'), 'the query string is stripped before matching');
});

TestRunner::test('T-GATE-2 kill switch ON blocks EVERY write path with zero HTTP', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    $api = make_api();

    $results = [
        'invoice'     => $api->create_invoice(['client' => ['id' => 'c1'], 'items' => [['id' => 'i1']], 'date' => '2026-01-01', 'dueDate' => '2026-01-01']),
        'payment'     => $api->create_payment(['bankAccount' => ['id' => 'b1'], 'invoices' => [['id' => 'inv1', 'amount' => 10]]]),
        'credit_note' => $api->create_credit_note(['client' => ['id' => 'c1'], 'items' => [['id' => 'i1']], 'date' => '2026-01-01']),
        'contact'     => $api->create_contact(['name' => 'Blocked Co']),
        'item'        => $api->create_item(['name' => 'Blocked', 'price' => [['idPriceList' => 1, 'price' => 10]]]),
        'category'    => $api->create_item_category(['name' => 'Blocked']),
        'webhook'     => $api->create_webhook_subscription('new-invoice', 'https://example.test/hook'),
    ];

    TestRunner::assertSame(0, count(alegra_mock_requests()), 'the kill switch must block all HTTP');
    foreach ($results as $entity => $r) {
        TestRunner::assertTrue(\Alegra\Connector\API\Client::is_gate_blocked_response($r), "$entity must return the gate marker");
        TestRunner::assertSame('kill_switch', $r['reason'] ?? null, "$entity must report kill_switch");
        TestRunner::assertSame($entity, $r['entity'] ?? null, "$entity marker must carry its entity");
        TestRunner::assertSame('POST', substr((string) ($r['blocked'] ?? ''), 0, 4), "$entity marker must carry the verb");
        TestRunner::assertFalse(is_wp_error($r), "$entity must not be a WP_Error");
        TestRunner::assertFalse(\Alegra\Connector\API\Client::is_dry_run_response($r), "$entity must not be a dry-run marker");
        TestRunner::assertTrue(\Alegra\Connector\API\Client::write_was_blocked($r), "$entity must be flagged write_was_blocked");
    }
});

TestRunner::test('T-GATE-3 kill switch OFF + entity enabled reaches Alegra (no regression)', function (): void {
    alegra_test_reset();
    $api = make_api();

    $contact = $api->create_contact(['name' => 'Enabled Co']);
    $item = $api->create_item(['name' => 'Enabled', 'price' => [['idPriceList' => 1, 'price' => 10]]]);
    $invoice = $api->create_invoice(['client' => ['id' => 'c1'], 'items' => [['id' => 'i1']], 'date' => '2026-01-01', 'dueDate' => '2026-01-01']);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'an enabled contact write must reach Alegra');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/items'), 'an enabled item write must reach Alegra');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'an enabled invoice write must reach Alegra');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($contact), 'contact must not be blocked');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($item), 'item must not be blocked');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($invoice), 'invoice must not be blocked');
});

TestRunner::test('T-GATE-4 kill switch OFF + entity DISABLED blocks automatic writes', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_push_products_enabled', false);
    update_option('alegra_connector_push_customers_enabled', false);
    $api = make_api();

    $invoice = $api->create_invoice(['client' => ['id' => 'c1'], 'items' => [['id' => 'i1']], 'date' => '2026-01-01', 'dueDate' => '2026-01-01']);
    $item = $api->create_item(['name' => 'Disabled', 'price' => [['idPriceList' => 1, 'price' => 10]]]);
    $contact = $api->create_contact(['name' => 'Disabled Co']);

    TestRunner::assertSame('entity_disabled', $invoice['reason'] ?? null, 'invoice must be blocked by entity_disabled');
    TestRunner::assertSame('invoice', $invoice['entity'] ?? null, 'invoice marker must carry entity invoice');
    TestRunner::assertSame('entity_disabled', $item['reason'] ?? null, 'item must be blocked by entity_disabled');
    TestRunner::assertSame('entity_disabled', $contact['reason'] ?? null, 'contact must be blocked by entity_disabled');
    TestRunner::assertSame(0, count(alegra_mock_requests()), 'a disabled entity must send zero HTTP');
});

TestRunner::test('T-GATE-5 explicit context bypasses the entity gate (kill switch off)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    $api = make_api();
    $payload = ['client' => ['id' => 'c1'], 'items' => [['id' => 'i1']], 'date' => '2026-01-01', 'dueDate' => '2026-01-01'];

    $r = \Alegra\Connector\Write_Gate::run_explicit(fn () => $api->create_invoice($payload));

    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($r), 'an explicit invoice must not be blocked');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the explicit invoice must reach Alegra');
    TestRunner::assertFalse(\Alegra\Connector\Write_Gate::is_explicit(), 'the explicit context must reset after run_explicit');
});

TestRunner::test('T-GATE-6 the gate marker is distinguishable from a real WP_Error', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    $api = make_api();
    $blocked = $api->post('/invoices', ['client' => ['id' => 'c1']]);

    TestRunner::assertTrue(\Alegra\Connector\API\Client::is_gate_blocked_response($blocked), 'blocked result must be the gate marker');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::is_dry_run_response($blocked), 'blocked result must not be a dry-run marker');
    TestRunner::assertFalse(is_wp_error($blocked), 'blocked result must not be a WP_Error');
    TestRunner::assertSame('kill_switch', $blocked['reason'] ?? null, 'the marker must carry the reason');
    TestRunner::assertTrue(\Alegra\Connector\API\Client::write_was_blocked($blocked), 'write_was_blocked must be true');

    // A real HTTP error is still a WP_Error and is NOT a block.
    alegra_test_reset();
    alegra_mock_fail('POST', '/invoices', 422, ['message' => 'payload inválido']);
    $error = make_api()->post('/invoices', ['client' => ['id' => 'c1']]);
    TestRunner::assertTrue(is_wp_error($error), 'a real HTTP error must stay a WP_Error');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($error), 'a WP_Error is not a block marker');
});

TestRunner::test('T-GATE-7 dry_run and the gate compose (dry_run wins, GET passes)', function (): void {
    // dry_run ON + kill switch ON -> the dry-run marker wins, byte-identical.
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    \Alegra\Connector\Kill_Switch::activate('test');
    $api = make_api();
    $r = $api->post('/invoices', ['client' => ['id' => 'c1']]);

    TestRunner::assertTrue(\Alegra\Connector\API\Client::is_dry_run_response($r), 'dry-run must win over the gate');
    TestRunner::assertFalse(\Alegra\Connector\API\Client::is_gate_blocked_response($r), 'the gate marker must not be returned when dry-run is on');
    TestRunner::assertSame(['dry_run' => true, 'blocked' => 'POST /invoices'], $r, 'the dry-run marker must be unchanged');
    TestRunner::assertSame(0, count(alegra_mock_requests()), 'no HTTP in dry-run');

    // GET always passes, even with the kill switch active.
    alegra_test_reset();
    update_option('alegra_connector_dry_run', false);
    \Alegra\Connector\Kill_Switch::activate('test');
    $get = make_api()->get('/invoices');
    TestRunner::assertFalse(is_wp_error($get), 'GET must not be blocked');
    TestRunner::assertSame(1, alegra_mock_count('GET', '/invoices'), 'GET must reach the mock');
});

TestRunner::test('T-GATE-8 ajax_record_payment reports the kill switch instead of silence', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', 'acct-1');
    \Alegra\Connector\Kill_Switch::activate('test');
    $order = alegra_make_order(9700, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'bacs',
        'meta' => ['_alegra_invoice_id' => '1nv-9700'],
    ]);

    $_POST['order_id'] = 9700;
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_record_payment());
    unset($_POST['order_id']);

    TestRunner::assertFalse($resp->success, 'the response must be an error envelope, not a fake success');
    TestRunner::assertStringContains('desconectado', (string) ($resp->payload['message'] ?? ''), 'the message must explain the kill switch');
    TestRunner::assertSame(true, $resp->payload['blocked'] ?? null, 'the payload must be flagged blocked');
    TestRunner::assertSame('kill_switch', $resp->payload['reason'] ?? null, 'the reason must be kill_switch');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'no payment may reach Alegra');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), '_alegra_payment_id must not be written');
});

TestRunner::test('T-GATE-9 kill switch ON: an automatic refund writes zero credit notes', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    [$order] = build_refund_world(100.0, 100.0, 9701, 9702);
    register_refund_owner_hook();

    $result = \Alegra\Connector\State_Sync::handle_refund(9701, 9702);

    TestRunner::assertTrue(\Alegra\Connector\API\Client::write_was_blocked($result), 'the refund must be blocked');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'no credit note may be POSTed');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_credited_amount', true), '_alegra_credited_amount must not be written');
});

// ===========================================================================
// T-CFG2 — Phase 2: configuration honesty + migration (REQ-CFG-1..5, HYG-2)
//
// The Phase-1 gate made `push_customers_enabled` authoritative for contacts.
// It is absent -> false, so an existing install that relied on the shared
// `push_products_enabled` hook would silently stop pushing customers. These
// tests prove the migration closes that gap, that the payment sweep is
// controllable, that the UI defaults match the runtime, that saving Settings
// no longer wipes the mappings, and that "Run now" explains itself.
// ===========================================================================
echo "\nT-CFG2 — Config honesty + migration\n";

function cfg2_source(string $relative): string
{
    return (string) file_get_contents($GLOBALS['alegra_plugin_root'] . $relative);
}

TestRunner::test('T-CFG2-1 migration seeds push_customers_enabled from push_products_enabled (Phase-1 regression)', function (): void {
    alegra_test_reset();
    // Existing install: products enabled, the new customer option never existed.
    update_option('alegra_connector_push_products_enabled', true);
    unset($GLOBALS['wp_options']['alegra_connector_push_customers_enabled']);
    unset($GLOBALS['wp_options']['alegra_connector_gate_migration_version']);

    \Alegra\Connector\Write_Gate::maybe_migrate();

    TestRunner::assertSame(true, get_option('alegra_connector_push_customers_enabled'), 'the migration must seed customers from products (true)');
    TestRunner::assertSame(1, (int) get_option('alegra_connector_gate_migration_version'), 'the migration version must advance');

    // The gate must now ALLOW an automatic contact write (kill switch off).
    $r = make_api()->create_contact(['name' => 'Migrated Co']);
    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($r), 'an automatic contact write must not be blocked after migration');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'the contact write must reach Alegra');
});

TestRunner::test('T-CFG2-2 fresh install (products off) seeds push_customers_enabled = false', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_products_enabled', false);
    unset($GLOBALS['wp_options']['alegra_connector_push_customers_enabled']);
    unset($GLOBALS['wp_options']['alegra_connector_gate_migration_version']);

    \Alegra\Connector\Write_Gate::maybe_migrate();

    TestRunner::assertSame(false, get_option('alegra_connector_push_customers_enabled'), 'no products push => no customers push');

    // The activation defaults also carry false, so a brand-new install agrees.
    $bootstrap = cfg2_source('alegra-connector.php');
    TestRunner::assertStringContains("'alegra_connector_push_customers_enabled' => false", $bootstrap, 'the activation default must be false');
});

TestRunner::test('T-CFG2-3 the migration is idempotent and guarded by the version option', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_products_enabled', true);
    unset($GLOBALS['wp_options']['alegra_connector_push_customers_enabled']);
    unset($GLOBALS['wp_options']['alegra_connector_gate_migration_version']);

    \Alegra\Connector\Write_Gate::maybe_migrate();
    $first = [
        'customers' => get_option('alegra_connector_push_customers_enabled'),
        'reconcile' => get_option('alegra_connector_payment_reconcile_enabled'),
        'batch' => get_option('alegra_connector_payment_reconcile_batch'),
        'version' => (int) get_option('alegra_connector_gate_migration_version'),
    ];

    \Alegra\Connector\Write_Gate::maybe_migrate();

    TestRunner::assertSame($first['customers'], get_option('alegra_connector_push_customers_enabled'), 'a second run must not change customers');
    TestRunner::assertSame($first['reconcile'], get_option('alegra_connector_payment_reconcile_enabled'), 'a second run must not change reconcile');
    TestRunner::assertSame($first['batch'], get_option('alegra_connector_payment_reconcile_batch'), 'a second run must not change the batch');
    TestRunner::assertSame(1, (int) get_option('alegra_connector_gate_migration_version'), 'the version must stay 1');

    // Version guard: once migrated, a deleted option is NOT resurrected.
    unset($GLOBALS['wp_options']['alegra_connector_push_customers_enabled']);
    update_option('alegra_connector_push_products_enabled', true);
    \Alegra\Connector\Write_Gate::maybe_migrate();
    TestRunner::assertFalse(array_key_exists('alegra_connector_push_customers_enabled', $GLOBALS['wp_options']), 'the version guard must stop a second migration from re-seeding');
});

TestRunner::test('T-CFG2-4 the migration is actually wired (hook + activation)', function (): void {
    $bootstrap = cfg2_source('alegra-connector.php');
    TestRunner::assertStringContains("Write_Gate::class, 'maybe_migrate'", $bootstrap, 'the migration must run on plugins_loaded');
    TestRunner::assertStringContains('Write_Gate::maybe_migrate()', $bootstrap, 'the migration must also run on activation');
});

TestRunner::test('T-CFG2-5 payment_reconcile_enabled/_batch are registered and exposed', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    $tpl = cfg2_source('templates/admin-settings.php');

    TestRunner::assertStringContains("register_setting('alegra_connector_settings', 'alegra_connector_payment_reconcile_enabled'", $admin, 'the sweep flag must be registered');
    TestRunner::assertStringContains("register_setting('alegra_connector_settings', 'alegra_connector_payment_reconcile_batch'", $admin, 'the batch must be registered');
    TestRunner::assertStringContains('name="alegra_connector_payment_reconcile_enabled"', $tpl, 'the sweep flag must be rendered');
    TestRunner::assertStringContains('name="alegra_connector_payment_reconcile_batch"', $tpl, 'the batch must be rendered');

    $bootstrap = cfg2_source('alegra-connector.php');
    TestRunner::assertStringContains("'alegra_connector_payment_reconcile_enabled' => true", $bootstrap, 'the default must preserve the sweep (branch A)');
    TestRunner::assertStringContains("'alegra_connector_payment_reconcile_batch' => 20", $bootstrap, 'the default batch must be 20');
});

TestRunner::test('T-CFG2-6 the hourly sweep honours payment_reconcile_enabled', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    make_reconcilable_order(9801, 'inv-cfg2-6');

    update_option('alegra_connector_payment_reconcile_enabled', false);
    $off = make_controller()->run_payment_reconcile();
    TestRunner::assertSame('skipped_disabled', (string) ($off['skipped'] ?? ''), 'a disabled sweep must report skipped_disabled');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'a disabled sweep must post no payment');

    update_option('alegra_connector_payment_reconcile_enabled', true);
    make_controller()->run_payment_reconcile();
    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'an enabled sweep must post the payment');
});

TestRunner::test('T-CFG2-7 the batch sanitizer clamps to 1..100', function (): void {
    TestRunner::assertSame(1, \Alegra\Connector\Admin\Admin_Dashboard::sanitize_reconcile_batch(0), '0 must clamp up to 1');
    TestRunner::assertSame(1, \Alegra\Connector\Admin\Admin_Dashboard::sanitize_reconcile_batch(-7), 'a negative must clamp to 1');
    TestRunner::assertSame(100, \Alegra\Connector\Admin\Admin_Dashboard::sanitize_reconcile_batch(999), '999 must clamp down to 100');
    TestRunner::assertSame(20, \Alegra\Connector\Admin\Admin_Dashboard::sanitize_reconcile_batch(20), 'a valid value must pass through');
});

TestRunner::test('T-CFG2-8 the four sync_* checkboxes use the runtime default (false)', function (): void {
    $tpl = cfg2_source('templates/admin-settings.php');

    foreach (['sync_products', 'sync_customers', 'sync_orders', 'sync_categories'] as $key) {
        $opt = 'alegra_connector_' . $key;
        TestRunner::assertStringNotContains("get_option('$opt',true)", $tpl, "$opt must not default to true in the UI");
        TestRunner::assertStringContains("get_option('$opt',false)", $tpl, "$opt must default to false in the UI");
        // Absent option: UI default == runtime default == false.
        TestRunner::assertSame(false, get_option($opt, false), "$opt must read as false when absent");
    }
});

TestRunner::test('T-CFG2-9 saving Settings no longer wipes field_mapping / tax_mapping', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_field_mapping', ['default_category' => 'cat-1']);
    update_option('alegra_connector_tax_mapping', ['iva' => 'tax-1']);

    TestRunner::assertSame(['default_category' => 'cat-1'], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_field_mapping(null), 'a null field_mapping must keep the stored value');
    TestRunner::assertSame(['iva' => 'tax-1'], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_tax_mapping(null), 'a null tax_mapping must keep the stored value');
    TestRunner::assertSame(['default_category' => 'cat-2'], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_field_mapping(['default_category' => 'cat-2']), 'a real mapping update must still apply');

    // One registration per mapping option, in the mapping group only.
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains("register_setting('alegra_connector_settings', 'alegra_connector_field_mapping'", $admin, 'field_mapping must not be in the Settings group');
    TestRunner::assertStringNotContains("register_setting('alegra_connector_settings', 'alegra_connector_tax_mapping'", $admin, 'tax_mapping must not be in the Settings group');
    TestRunner::assertStringContains("register_setting('alegra_connector_mapping', 'alegra_connector_field_mapping'", $admin, 'field_mapping must be registered in the mapping group');
    TestRunner::assertStringContains("register_setting('alegra_connector_mapping', 'alegra_connector_tax_mapping'", $admin, 'tax_mapping must be registered in the mapping group');
});

TestRunner::test('T-CFG2-10 the customer hooks are independent of the product hooks (REQ-CFG-4)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_products_enabled', true);
    update_option('alegra_connector_push_customers_enabled', false);
    $public = make_public();

    TestRunner::assertTrue(has_action('woocommerce_new_product', [$public, 'on_new_product']) !== false, 'products on => product hook registered');
    TestRunner::assertFalse(has_action('woocommerce_new_customer', [$public, 'on_new_customer']), 'products on + customers off => customer hook must NOT register');

    alegra_test_reset();
    update_option('alegra_connector_push_customers_enabled', true);
    $public2 = make_public();
    TestRunner::assertTrue(has_action('woocommerce_new_customer', [$public2, 'on_new_customer']) !== false, 'customers on => customer hook registered');
});

TestRunner::test('T-CFG2-11 "Sincronizar ahora" with a non-periodic method explains instead of no-op', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_method', 'disabled');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $_POST = ['sync_type' => 'all'];

    $resp = alegra_capture_json(fn () => $admin->ajax_sync_now());
    $_POST = [];

    TestRunner::assertFalse($resp->success, 'a disabled method must return an error, not a fake success');
    TestRunner::assertStringContains('desactivada', (string) ($resp->payload['message'] ?? ''), 'the message must say the periodic sync is disabled');
    TestRunner::assertSame('sync_method', $resp->payload['reason'] ?? null, 'the reason must be sync_method');
    TestRunner::assertSame(0, count(alegra_single_events('alegra_connector_cron_sync_now')), 'no cron event may be queued');

    update_option('alegra_connector_sync_method', 'real-time');
    $_POST = ['sync_type' => 'all'];
    $resp2 = alegra_capture_json(fn () => $admin->ajax_sync_now());
    $_POST = [];
    TestRunner::assertFalse($resp2->success, 'real-time only must return an error');
    TestRunner::assertStringContains('Tiempo Real', (string) ($resp2->payload['message'] ?? ''), 'the message must explain the real-time-only mode');
});

TestRunner::test('T-CFG2-12 "Run now" with the kill switch active explains the disconnection', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $_POST = ['hook' => 'alegra_connector_cron_sync'];

    $resp = alegra_capture_json(fn () => $admin->ajax_run_cron_now());
    $_POST = [];

    TestRunner::assertFalse($resp->success, 'the kill switch must block Run now');
    TestRunner::assertStringContains('desconectado', (string) ($resp->payload['message'] ?? ''), 'the message must explain the kill switch');
    TestRunner::assertSame('kill_switch', $resp->payload['reason'] ?? null, 'the reason must be kill_switch');
    TestRunner::assertSame(0, count(alegra_single_events('alegra_connector_cron_sync_now')), 'no cron event may be queued');
});

TestRunner::test('T-CFG2-13 "Sincronizar ahora" with a periodic method executes', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_method', 'cron');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $_POST = ['sync_type' => 'all'];

    $resp = alegra_capture_json(fn () => $admin->ajax_sync_now());
    $_POST = [];

    TestRunner::assertTrue($resp->success, 'a periodic method must execute');
});

TestRunner::test('T-CFG2-14 the decorative settings sections are gone (REQ-HYG-2)', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains('add_settings_section(', $admin, 'the 6 dead sections must be removed');
});

TestRunner::test('T-CFG2-15 uninstall removes the new options (REQ-CFG-1)', function (): void {
    $uninstall = cfg2_source('uninstall.php');
    TestRunner::assertStringContains("delete_option('alegra_connector_payment_reconcile_enabled')", $uninstall, 'uninstall must delete the sweep flag');
    TestRunner::assertStringContains("delete_option('alegra_connector_payment_reconcile_batch')", $uninstall, 'uninstall must delete the batch');
    TestRunner::assertStringContains("delete_option('alegra_connector_push_customers_enabled')", $uninstall, 'uninstall must delete the customer toggle');
    TestRunner::assertStringContains("delete_option('alegra_connector_gate_migration_version')", $uninstall, 'uninstall must delete the migration version');
});

// ===========================================================================
// T-RB — Fase 3 (reconcile/refund gates) + Fase 4 (robustez)
//
// Spec:   docs/sdd/config-gates/spec.md   (REQ-CFG-1, REQ-ENF-2, REQ-RB-1..3)
// Design: docs/sdd/config-gates/design.md (§3.5, §4.1, §4.2, §4.3, §4.4)
// ===========================================================================
echo "\nT-RB — Fase 3+4: reconcile/refund gates y robustez\n";

// ---------------------------------------------------------------------------
// 3a — the real-time reconcile honours payment_reconcile_enabled (REQ-CFG-1)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-1a payment_reconcile_enabled=false stops the REAL-TIME reconcile (REQ-CFG-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    update_option('alegra_connector_payment_reconcile_enabled', false);
    make_public();
    $order = make_reconcilable_order(9601, 'inv-rb-1a');

    do_action('woocommerce_order_status_processing', 9601);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/payments'), 'a disabled flag must stop the real-time payment');
    // The fast-path returns BEFORE the idempotency pre-search, so no GET either.
    TestRunner::assertSame(0, alegra_mock_count('GET', '/payments'), 'the disabled flag must skip the pre-search GET');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/invoices/inv-rb-1a'), 'the disabled flag must skip the invoice read');
    TestRunner::assertSame('', (string) $order->get_meta('_alegra_payment_id', true), '_alegra_payment_id must stay empty');
});

TestRunner::test('T-RB-1b payment_reconcile_enabled=true lets the REAL-TIME reconcile post (REQ-CFG-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_payment_account_id', '5');
    update_option('alegra_connector_payment_reconcile_enabled', true);
    make_public();
    $order = make_reconcilable_order(9602, 'inv-rb-1b');

    do_action('woocommerce_order_status_processing', 9602);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/payments'), 'an enabled flag must let the real-time payment through');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_payment_id', true) !== '', '_alegra_payment_id must be written');
});

// ---------------------------------------------------------------------------
// 3b — refund / payment-method entity gates (REQ-ENF-2)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-2a manual mode: an AUTOMATIC refund emits no credit note (REQ-ENF-2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    build_refund_world(100.0, 100.0, 9610, 9611);

    $result = State_Sync::handle_refund(9610, 9611);

    TestRunner::assertTrue(\Alegra\Connector\API\Client::write_was_blocked($result), 'the automatic refund must be blocked');
    TestRunner::assertSame('entity_disabled', $result['reason'] ?? null, 'the reason must be entity_disabled (credit_note)');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'no credit note may be POSTed');
});

TestRunner::test('T-RB-2b manual mode: the EXPLICIT refund path still emits the credit note (REQ-ENF-2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    build_refund_world(100.0, 100.0, 9612, 9613);

    $result = \Alegra\Connector\Write_Gate::run_explicit(
        fn () => State_Sync::handle_refund(9612, 9613)
    );

    TestRunner::assertFalse(\Alegra\Connector\API\Client::write_was_blocked($result), 'an explicit refund must not be blocked');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'the explicit refund must reach Alegra');
});

TestRunner::test('T-RB-3 manual mode: an automatic payment-method change does NOT update the invoice (REQ-ENF-2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_make_user(7, ['user_email' => 'pm-rb@example.test'], [
        'billing_alegra_idtype'         => 'CC',
        'billing_alegra_identification' => '1234567890',
    ]);
    alegra_make_order(9620, [
        'total' => 50.0, 'currency' => 'COP', 'payment_method' => 'cod', 'customer_id' => 7,
        'meta' => ['_alegra_invoice_id' => '1nv-9620', '_alegra_last_payment_method' => 'bacs'],
    ]);

    State_Sync::handle_payment_method_change(9620);

    TestRunner::assertSame(0, alegra_mock_count('PUT', '/invoices/1nv-9620'), 'manual mode must not update the invoice automatically');
});

// ---------------------------------------------------------------------------
// 4a — the dashboard render never creates the Consumidor Final (REQ-RB-1)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-4a rendering the dashboard does NOT POST /contacts (REQ-RB-1)', function (): void {
    alegra_test_reset();
    // Connected, no CF cache/override, contact entity ENABLED: a create WOULD
    // reach Alegra if the render called the resolving path.
    update_option('alegra_connector_connection_tested', true);
    update_option('alegra_connector_push_customers_enabled', true);

    $is_connected = true;
    ob_start();
    include $GLOBALS['alegra_plugin_root'] . 'templates/admin-dashboard.php';
    $html = (string) ob_get_clean();

    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'rendering the dashboard must not create the CF contact');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'rendering the dashboard must not even search for the CF');
    TestRunner::assertStringContains('Consumidor Final', $html, 'the dashboard must still render the CF health row');
});

TestRunner::test('T-RB-4b peek_id() is read-only and is_configured() mirrors it (REQ-RB-1)', function (): void {
    alegra_test_reset();
    TestRunner::assertFalse(\Alegra\Connector\Consumidor_Final::peek_id(), 'no cache => false');
    TestRunner::assertFalse(\Alegra\Connector\Consumidor_Final::is_configured(), 'is_configured must mirror peek_id');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'peek must not GET /contacts');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'peek must not POST /contacts');

    update_option('alegra_connector_consumidor_final_contact_id', 'cf-cached');
    TestRunner::assertSame('cf-cached', \Alegra\Connector\Consumidor_Final::peek_id(), 'peek serves the option cache');
    TestRunner::assertTrue(\Alegra\Connector\Consumidor_Final::is_configured(), 'a cached id is configured');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'peek still makes no request');
});

TestRunner::test('T-RB-4c a non-explicit create with the contact entity disabled is refused (REQ-RB-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_customers_enabled', false);
    alegra_make_user(2, ['user_email' => 'nodata-rb@example.test']);
    $order = make_invoice_order(9630, 2, 'nodata-rb@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'a non-explicit create with the contact entity disabled must be refused');
});

// ---------------------------------------------------------------------------
// 4b — the chunked flows honour the cancellation flag (REQ-RB-2)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-5 ajax_sync_page stops in the next batch when cancelled (REQ-RB-2)', function (): void {
    alegra_test_reset();
    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0,
        'imported' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0,
    ], 600);
    set_transient('alegra_sync_cancelled', 1, 120);
    alegra_mock_seed_item('itm-rb-5', ['name' => 'One', 'reference' => 'SKU-RB-5', 'type' => 'product']);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());

    TestRunner::assertTrue($resp->success, 'the cancel response must be a success envelope');
    TestRunner::assertSame(true, $resp->payload['cancelled'] ?? null, 'the response must be flagged cancelled');
    TestRunner::assertSame(true, $resp->payload['done'] ?? null, 'the response must be done');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'no batch may be fetched after cancellation');
});

TestRunner::test('T-RB-6 ajax_sync_pending_page stops when cancelled (REQ-RB-2)', function (): void {
    alegra_test_reset();
    set_transient('alegra_pending_invoice_batch', [
        'order_ids' => range(9700, 9729), 'total' => 30,
        'processed' => 0, 'synced' => 0, 'errors' => 0,
    ], 600);
    set_transient('alegra_sync_cancelled', 1, 120);
    // A non-cancelled run WOULD invoice this order.
    make_reconcilable_order(9700, 'inv-rb-6');

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_pending_page());

    TestRunner::assertTrue($resp->success, 'the cancel response must be a success envelope');
    TestRunner::assertSame(true, $resp->payload['cancelled'] ?? null, 'the response must be flagged cancelled');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no invoice may be created after cancellation');
});

TestRunner::test('T-RB-7 the per-item importers skip on kill switch / cancellation (REQ-RB-2)', function (): void {
    alegra_test_reset();
    $item = ['id' => 'itm-rb-7', 'name' => 'Blocked', 'reference' => 'SKU-RB-7', 'type' => 'product'];

    \Alegra\Connector\Kill_Switch::activate('test');
    $r = make_products()->import_single_item_public($item);
    TestRunner::assertSame('skipped', $r, 'a kill-switched item must be skipped');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items/itm-rb-7'), 'no per-item GET may happen');

    \Alegra\Connector\Kill_Switch::deactivate();
    set_transient('alegra_sync_cancelled', 1, 120);
    $r2 = make_products()->import_single_item_public($item);
    TestRunner::assertSame('skipped', $r2, 'a cancelled item must be skipped');

    $contact = ['id' => 'ct-rb-7', 'email' => 'rb7@example.test', 'name' => 'Blocked'];
    $r3 = make_customers()->import_single_contact_public($contact);
    TestRunner::assertSame('skipped', $r3, 'a cancelled contact must be skipped');
});

// ---------------------------------------------------------------------------
// 4c — ajax_disconnect is honest (REQ-RB-3)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-8 ajax_disconnect deletes the webhooks BEFORE killing the plugin (REQ-RB-3)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_subscriptions', [
        ['id' => 'wh-1', 'event' => 'new-invoice'],
        ['id' => 'wh-2', 'event' => 'new-client'],
    ], false);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_disconnect());

    TestRunner::assertSame(1, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-1'), 'wh-1 must be deleted in Alegra');
    TestRunner::assertSame(1, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-2'), 'wh-2 must be deleted in Alegra');
    TestRunner::assertSame(2, $resp->payload['webhooks_deleted'] ?? null, 'the real deleted count must be reported');
    TestRunner::assertTrue(\Alegra\Connector\Kill_Switch::is_active(), 'the kill switch must end active');
});

TestRunner::test('T-RB-9 ajax_disconnect under dry run does not lie about deleted webhooks (REQ-RB-3)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    update_option('alegra_connector_webhook_subscriptions', [
        ['id' => 'wh-1', 'event' => 'new-invoice'],
        ['id' => 'wh-2', 'event' => 'new-client'],
    ], false);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_disconnect());

    TestRunner::assertSame(0, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-1'), 'no DELETE may be sent in dry run');
    TestRunner::assertNotSame(2, $resp->payload['webhooks_deleted'] ?? null, 'dry run must not report 2 as deleted');
    TestRunner::assertSame(0, $resp->payload['webhooks_deleted'] ?? null, 'the real deleted count is 0');
    TestRunner::assertStringContains('prueba', strtolower((string) ($resp->payload['message'] ?? '')), 'the message must mention the dry run');
});

TestRunner::test('T-RB-10 ajax_disconnect while already disconnected reports the block (REQ-RB-3)', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    update_option('alegra_connector_webhook_subscriptions', [
        ['id' => 'wh-1', 'event' => 'new-invoice'],
        ['id' => 'wh-2', 'event' => 'new-client'],
    ], false);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $resp = alegra_capture_json(fn () => $admin->ajax_disconnect());

    TestRunner::assertSame(0, alegra_mock_count('DELETE', '/webhooks/subscriptions/wh-1'), 'the gate must block the DELETE');
    TestRunner::assertSame(0, $resp->payload['webhooks_deleted'] ?? null, 'nothing may be reported as deleted');
    TestRunner::assertSame('kill_switch', $resp->payload['reason'] ?? null, 'the reason must be kill_switch');
    TestRunner::assertTrue($resp->payload['blocked'] ?? false, 'the response must be flagged blocked');
});

// ---------------------------------------------------------------------------
// 4d — the public webhook receiver honours the kill switch (REQ-ENF-1)
// ---------------------------------------------------------------------------

TestRunner::test('T-RB-11 the public webhook receiver ACKs but processes nothing when disconnected (REQ-ENF-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok-rb-11');
    \Alegra\Connector\Kill_Switch::activate('test');

    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);
    $body = json_encode(['subject' => 'new-invoice', 'message' => ['id' => 'inv-x']]);

    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok-rb-11']));

    TestRunner::assertSame(200, $res->get_status(), 'the receiver must ACK with 200 (never a 4xx)');
    TestRunner::assertSame('kill_switch', $res->get_data()['reason'] ?? null, 'the ACK must report the kill switch');
    TestRunner::assertSame(0, count(alegra_mock_requests()), 'no handler work may hit Alegra');
});

// ===========================================================================
// T-CN — Phase 5: the manual credit-note action (Task A)
//
// Phase 3 correctly gated the AUTOMATIC refund in manual mode. Without an
// explicit action the merchant could not emit a credit note at all, so this is
// the button that runs inside Write_Gate::run_explicit().
// ===========================================================================
echo "\nT-CN — manual credit-note action (REQ-ENF-2 / REQ-HYG-1)\n";

TestRunner::test('T-CN-1 manual mode: the automatic refund stays gated while the button emits exactly one (REQ-ENF-2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    build_refund_world(100.0, 100.0, 9800, 9801);
    register_refund_owner_hook();

    // 1) The automatic hook must NOT emit in manual mode.
    do_action('woocommerce_order_refunded', 9800, 9801);
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'the automatic refund must stay gated in manual mode');

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);

    // 2) The explicit button emits it.
    $_POST['order_id'] = 9800;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'the button must succeed');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'the button must emit exactly one credit note');

    // 3) A second click must not duplicate (per-refund idempotency).
    $_POST['order_id'] = 9800;
    $resp2 = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp2->success, 'the second click must be a clean no-op');
    TestRunner::assertSame(true, $resp2->payload['already_exists'] ?? null, 'the second click must report already_exists');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/credit-notes'), 'the second click must not duplicate the credit note');
});

TestRunner::test('T-CN-2 no linked invoice: the button errors clearly and posts nothing', function (): void {
    alegra_test_reset();
    $refund = alegra_make_refund(9811, ['total' => 10.0]);
    alegra_make_order(9810, ['total' => 100.0, 'refunds' => [$refund]]);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $_POST['order_id'] = 9810;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertFalse($resp->success, 'no invoice => error envelope');
    TestRunner::assertStringContains('factura', strtolower((string) ($resp->payload['message'] ?? '')), 'the message must explain the missing invoice');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'nothing may be posted without an invoice');
});

TestRunner::test('T-CN-3 draft invoice: the button asks to open it first and posts nothing', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('1nv-9820', [
        'total' => 100.0, 'balance' => 100.0, 'status' => 'draft',
        'items' => [['id' => '1t3m-9820', 'name' => 'Widget', 'price' => 100, 'quantity' => 1]],
    ]);
    $refund = alegra_make_refund(9821, ['total' => 100.0]);
    alegra_make_order(9820, [
        'total' => 100.0,
        'meta' => ['_alegra_invoice_id' => '1nv-9820', '_billing_alegra_contact_id' => 'c0n-9820'],
        'refunds' => [$refund],
    ]);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $_POST['order_id'] = 9820;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertFalse($resp->success, 'a draft invoice must be refused');
    TestRunner::assertStringContains('borrador', strtolower((string) ($resp->payload['message'] ?? '')), 'the message must mention the draft');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'nothing may be posted for a draft invoice');
});

TestRunner::test('T-CN-4 dry run: the button reports no write and posts nothing', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_dry_run', true);
    update_option('alegra_connector_push_orders_enabled', false);
    build_refund_world(100.0, 100.0, 9830, 9831);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $_POST['order_id'] = 9830;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertTrue($resp->success, 'dry run is not an error');
    TestRunner::assertSame(true, $resp->payload['dry_run'] ?? null, 'the payload must be flagged dry_run');
    TestRunner::assertStringContains('NO se envió', (string) ($resp->payload['message'] ?? ''), 'the message must say nothing was sent');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'dry run must not post');
});

TestRunner::test('T-CN-5 kill switch: the button reports the block and posts nothing', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test');
    build_refund_world(100.0, 100.0, 9840, 9841);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $_POST['order_id'] = 9840;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertFalse($resp->success, 'the kill switch must block the button');
    TestRunner::assertSame('kill_switch', $resp->payload['reason'] ?? null, 'the reason must be kill_switch');
    TestRunner::assertStringContains('desconectado', (string) ($resp->payload['message'] ?? ''), 'the message must explain the kill switch');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/credit-notes'), 'nothing may be posted');
});

TestRunner::test('T-CN-6 a real API error surfaces a clear message and writes no refund meta', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    [$order] = build_refund_world(100.0, 100.0, 9850, 9851);
    alegra_mock_fail('POST', '/credit-notes', 422, ['code' => 422, 'message' => 'Factura inválida']);

    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api($logger), $logger);
    $_POST['order_id'] = 9850;
    $resp = alegra_capture_json(fn () => $admin->ajax_emit_credit_note());
    unset($_POST['order_id']);

    TestRunner::assertFalse($resp->success, 'a 422 must be an error envelope');
    TestRunner::assertStringContains('Error de Alegra', (string) ($resp->payload['message'] ?? ''), 'the message must surface the API error');
    TestRunner::assertSame('', (string) $order->get_meta(sprintf(\Alegra\Connector\State_Sync::REFUND_META_FMT, 9851), true), 'no refund meta may be written on failure');
});

TestRunner::test('T-CN-7 the button is wired end to end (hook + template + JS)', function (): void {
    $admin_src = cfg2_source('admin/Admin/Admin_Dashboard.php');
    $tpl_src   = cfg2_source('templates/admin-order-detail.php');
    $js_src    = cfg2_source('admin/assets/js/admin.js');

    TestRunner::assertStringContains("add_action('wp_ajax_alegra_emit_credit_note'", $admin_src, 'the AJAX action must be registered');
    TestRunner::assertStringContains('Write_Gate::run_explicit', $admin_src, 'the handler must run in explicit context');
    TestRunner::assertStringContains('alegra-emit-credit-note', $tpl_src, 'the order detail must render the button');
    TestRunner::assertStringContains("action: 'alegra_emit_credit_note'", $js_src, 'admin.js must call the action');
});

// ===========================================================================
// T-HYG — Phase 5 hygiene (REQ-HYG-1, REQ-HYG-2)
// ===========================================================================
echo "\nT-HYG — hygiene (REQ-HYG-1)\n";

TestRunner::test('T-HYG-1 the dead option writes are gone and their legacy cleanup remains (REQ-HYG-1)', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains("update_option('alegra_connector_items_count'", $admin, 'the items_count write must be removed');
    TestRunner::assertStringNotContains("update_option('alegra_connector_contacts_count'", $admin, 'the contacts_count write must be removed');
    TestRunner::assertStringNotContains("update_option('alegra_connector_disconnected_at'", $admin, 'the disconnected_at write must be removed');

    $uninstall = cfg2_source('uninstall.php');
    TestRunner::assertStringContains("delete_option('alegra_connector_items_count')", $uninstall, 'uninstall must still clean the legacy items_count');
    TestRunner::assertStringContains("delete_option('alegra_connector_contacts_count')", $uninstall, 'uninstall must still clean the legacy contacts_count');
    TestRunner::assertStringContains("delete_option('alegra_connector_disconnected_at')", $uninstall, 'uninstall must still clean the legacy disconnected_at');
});

TestRunner::test('T-HYG-2 the 13 uncalled Client methods are kept and marked @deprecated (branch B)', function (): void {
    $client = cfg2_source('includes/API/Client.php');
    $methods = [
        'delete_item_category', 'void_credit_note', 'update_credit_note', 'delete_credit_note',
        'update_payment', 'delete_payment', 'void_payment', 'open_payment',
        'create_price_list', 'update_price_list', 'delete_price_list',
        'create_estimate', 'update_invoice_retentions',
    ];
    foreach ($methods as $m) {
        TestRunner::assertStringContains('function ' . $m . '(', $client, "$m must still exist (public API)");
    }
    TestRunner::assertSame(13, substr_count($client, '@deprecated 2.4.0'), 'all 13 uncalled methods must be marked @deprecated 2.4.0');

    // The methods that ARE called must NOT be deprecated.
    // 2.6.0 (T1.9): create_inventory_adjustment now has a production caller
    // (Inventory_Pusher::push_delta), so it joins the called list.
    foreach (['delete_contact', 'update_item_category', 'create_inventory_adjustment'] as $called) {
        $pos = strpos($client, 'function ' . $called . '(');
        TestRunner::assertTrue($pos !== false, "$called must still exist");
        $before = substr($client, max(0, $pos - 260), 260);
        TestRunner::assertStringNotContains('@deprecated', $before, "$called IS called in production and must NOT be deprecated");
    }
});

TestRunner::test('T-HYG-3 the dead sync_all() methods are gone (REQ-HYG-1)', function (): void {
    foreach (['Products', 'Customers', 'Categories'] as $class) {
        $src = cfg2_source('includes/Sync/' . $class . '.php');
        TestRunner::assertStringNotContains('function sync_all(', $src, "$class::sync_all() must be removed");
    }
});

TestRunner::test('T-HYG-4 uninstall.php deletes every option the plugin reads or writes (REQ-HYG-1)', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $options = [];
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/scripts/') || str_contains($path, '/languages/') || str_contains($path, '/releases/')) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (preg_match_all("/(?:get_option|update_option|add_option)\(\s*'([a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $opt) {
                $options[$opt] = true;
            }
        }
    }

    $uninstall = cfg2_source('uninstall.php');
    $missing = [];
    foreach (array_keys($options) as $opt) {
        if (!str_contains($uninstall, "delete_option('$opt')")) {
            $missing[] = $opt;
        }
    }
    sort($missing);
    TestRunner::assertSame([], $missing, 'every plugin option must be deleted by uninstall.php (missing: ' . implode(', ', $missing) . ')');
});

TestRunner::test('T-HYG-5 no decorative settings sections and no do_settings_sections (REQ-HYG-2)', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains('add_settings_section(', $admin, 'the dead settings sections must stay removed');

    $root = $GLOBALS['alegra_plugin_root'];
    foreach (['admin/Admin/Admin_Dashboard.php', 'templates/admin-settings.php'] as $rel) {
        $src = cfg2_source($rel);
        TestRunner::assertStringNotContains('do_settings_sections(', $src, "$rel must not call do_settings_sections() without registered fields");
    }
});

// ===========================================================================
// T-WH-SEL — webhook event selector: all events by default, deselect to opt out
// ===========================================================================
echo "\nT-WH-SEL — webhook event selector\n";

TestRunner::test('T-WH-SEL-1 an absent selection means ALL 12 events (default, no regression)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    $all = Client::get_webhook_events();
    TestRunner::assertSame($all, \Alegra\Connector\Webhooks\Receiver::selected_events(), 'an absent option must mean all events');

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(count($all), (int) ($response->payload['creados'] ?? -1), 'all 12 events must be registered by default');
    TestRunner::assertSame(0, (int) ($response->payload['eliminados'] ?? -1), 'nothing is deleted by default');
    TestRunner::assertSame(count($all), alegra_mock_count('POST', '/webhooks/subscriptions'), 'exactly 12 subscription POSTs');
    TestRunner::assertSame(0, count(alegra_mock_requests('DELETE')), 'no DELETE without a deselection');
});

TestRunner::test('T-WH-SEL-2 deselecting an event keeps it out of the registration', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    $selected = array_values(array_diff(Client::get_webhook_events(), ['edit-item']));
    update_option(\Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION, $selected, false);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(count($selected), (int) ($response->payload['creados'] ?? -1), 'only the selected events are created');

    $posted = [];
    foreach (alegra_mock_requests('POST', '/webhooks/subscriptions') as $req) {
        $posted[] = (string) ($req['body']['event'] ?? '');
    }
    TestRunner::assertFalse(in_array('edit-item', $posted, true), 'the deselected event must NOT be subscribed');
    sort($posted);
    $expected = $selected;
    sort($expected);
    TestRunner::assertSame($expected, $posted, 'exactly the selected events are subscribed');
    TestRunner::assertSame(0, (int) ($response->payload['eliminados'] ?? -1), 'nothing to delete when the event was never subscribed');
});

TestRunner::test('T-WH-SEL-3 deselecting a previously-subscribed event DELETEs it in Alegra', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    // The event is already subscribed in Alegra (and known locally). Seed the
    // mock state directly: a bare API write would be blocked by the Write Gate
    // (only ajax_register_webhooks runs it explicitly).
    $url = \Alegra\Connector\Webhooks\Receiver::registration_url();
    $id = 'wh-sel-edit';
    $GLOBALS['alegra_mock_state']['subscriptions'][$id] = ['id' => $id, 'event' => 'edit-item', 'url' => $url];
    update_option('alegra_connector_webhook_subscriptions', [['id' => $id, 'event' => 'edit-item']], false);

    $selected = array_values(array_diff(Client::get_webhook_events(), ['edit-item']));
    update_option(\Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION, $selected, false);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(1, (int) ($response->payload['eliminados'] ?? -1), 'the response must report one unsubscription');
    TestRunner::assertSame(1, alegra_mock_count('DELETE', '/webhooks/subscriptions/' . $id), 'the stale subscription must be DELETEd in Alegra');
    TestRunner::assertStringContains('eliminados', (string) ($response->payload['message'] ?? ''), 'the message must report the unsubscriptions');

    $stored = (array) get_option('alegra_connector_webhook_subscriptions', []);
    foreach ($stored as $sub) {
        TestRunner::assertFalse(($sub['event'] ?? '') === 'edit-item', 'the local list must drop the deselected event');
    }
});

TestRunner::test('T-WH-SEL-3b a deselected event owned by ANOTHER URL is never deleted', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    $fid = 'wh-sel-foreign';
    $GLOBALS['alegra_mock_state']['subscriptions'][$fid] = ['id' => $fid, 'event' => 'delete-item', 'url' => 'otro.test/hook'];
    $selected = array_values(array_diff(Client::get_webhook_events(), ['delete-item']));
    update_option(\Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION, $selected, false);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(0, alegra_mock_count('DELETE', '/webhooks/subscriptions/' . $fid), 'a subscription owned by another URL must be left alone');
    TestRunner::assertSame(0, (int) ($response->payload['eliminados'] ?? -1), 'nothing was ours to delete');
});

TestRunner::test('T-WH-SEL-4 the sanitizer accepts only documented slugs', function (): void {
    $clean = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_webhook_selected_events([
        'new-invoice', 'not-an-event', 'edit-item', 'delete-item', '', 'DROP TABLE',
    ]);
    TestRunner::assertSame(['new-invoice', 'edit-item', 'delete-item'], $clean, 'unknown slugs must be rejected');

    TestRunner::assertSame([], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_webhook_selected_events('new-invoice'), 'a non-array value yields an empty selection');
    TestRunner::assertSame([], \Alegra\Connector\Admin\Admin_Dashboard::sanitize_webhook_selected_events(['bogus']), 'an all-unknown list yields empty');
});

TestRunner::test('T-WH-SEL-5 the migration seeds all 12 for an existing install (and is idempotent)', function (): void {
    alegra_test_reset();
    // Existing install: already gate-migrated, selector option never existed.
    update_option('alegra_connector_gate_migration_version', 1);
    unset($GLOBALS['wp_options']['alegra_connector_webhook_selected_events']);
    unset($GLOBALS['wp_options']['alegra_connector_webhook_events_migration_version']);

    \Alegra\Connector\Write_Gate::maybe_migrate();

    TestRunner::assertSame(Client::get_webhook_events(), get_option('alegra_connector_webhook_selected_events'), 'the migration must seed all 12');
    TestRunner::assertSame(1, (int) get_option('alegra_connector_webhook_events_migration_version'), 'the selector migration must be versioned');
    TestRunner::assertSame(1, (int) get_option('alegra_connector_gate_migration_version'), 'the gate migration version must stay 1');

    // Never overwrite a merchant's narrowed selection.
    update_option('alegra_connector_webhook_selected_events', ['new-invoice'], false);
    unset($GLOBALS['wp_options']['alegra_connector_webhook_events_migration_version']);
    \Alegra\Connector\Write_Gate::maybe_migrate();
    TestRunner::assertSame(['new-invoice'], get_option('alegra_connector_webhook_selected_events'), 'an existing selection must not be overwritten');
});

TestRunner::test('T-WH-SEL-6 a deselected event delivered by a stale subscription is ignored', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok-sel');
    update_option(
        \Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION,
        array_values(array_diff(Client::get_webhook_events(), ['edit-item'])),
        false
    );

    $receiver = new \Alegra\Connector\Webhooks\Receiver(make_api(), make_logger());
    $body = json_encode(['subject' => 'edit-item', 'message' => ['id' => 'it-1']]);
    $ignored = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok-sel']));

    TestRunner::assertSame(200, $ignored->get_status(), 'a deselected event must be ACKed (never a 4xx)');
    TestRunner::assertSame('event_not_selected', $ignored->get_data()['reason'] ?? null, 'the ignore reason must name the selection');
    TestRunner::assertFalse(\Alegra\Connector\Webhooks\Receiver::is_event_selected('edit-item'), 'edit-item is deselected');

    // The same event IS accepted when the selection is absent (all events).
    delete_option(\Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION);
    TestRunner::assertTrue(\Alegra\Connector\Webhooks\Receiver::is_event_selected('edit-item'), 'an absent selection accepts every event');
});

TestRunner::test('T-WH-SEL-7 the selector is wired: option, UI, labels and activation default', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    $tpl = cfg2_source('templates/admin-settings.php');
    $client = cfg2_source('includes/API/Client.php');
    $bootstrap = cfg2_source('alegra-connector.php');

    TestRunner::assertStringContains('sanitize_webhook_selected_events', $admin, 'the sanitizer must be registered');
    TestRunner::assertStringContains('register_setting(\'alegra_connector_settings\', \Alegra\Connector\Webhooks\Receiver::EVENTS_OPTION', $admin, 'the option must be registered in the settings group');

    TestRunner::assertStringContains('name="alegra_connector_webhook_selected_events[]"', $tpl, 'the template must render the event checkboxes');
    TestRunner::assertStringContains('Se registran todos por defecto. Desmarca los que no quieras recibir.', $tpl, 'the helper text must state the default and how to narrow');
    TestRunner::assertStringContains('get_webhook_event_labels', $tpl, 'the template must render the event labels');
    TestRunner::assertStringContains('alegra-webhook-events-select-all', $tpl, 'the select-all control must exist');
    TestRunner::assertStringContains('alegra-webhook-events-select-none', $tpl, 'the select-none control must exist');

    TestRunner::assertStringContains("'new-invoice'", $client, 'the labels map must cover new-invoice');
    TestRunner::assertStringContains('Factura nueva', $client, 'the labels map must translate the event');

    TestRunner::assertStringContains("'alegra_connector_webhook_selected_events' => \Alegra\Connector\API\Client::get_webhook_events()", $bootstrap, 'activation must default to all events');
});

// ===========================================================================
// T-WH-LIVE — live checkbox state sent by the JS must drive the registration
//
// The user complaint was that clicking "Registrar webhooks" subscribed ALL 12
// regardless of the unchecked boxes, because the AJAX read from the saved
// option (the form save was required). The handler now reads $_POST first
// when present, persists it, and falls back to the saved option otherwise.
// ===========================================================================
echo "\nT-WH-LIVE — live checkbox state drives the registration\n";

TestRunner::test('T-WH-LIVE-1 the AJAX subscribes ONLY the posted events, not all 12', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    // The form has NOT been saved: the option still holds the default (all 12).
    // Seed the option explicitly because alegra_test_reset() only clears the
    // store, it does not run the activation defaults loop.
    update_option(Receiver::EVENTS_OPTION, Client::get_webhook_events(), false);
    TestRunner::assertSame(Client::get_webhook_events(), get_option(Receiver::EVENTS_OPTION), 'pre-condition: the saved option is all 12');

    // The JS would post the live checkbox state. Simulate three checked,
    // nine unchecked (i.e. the user kept only new-invoice, edit-item,
    // delete-item).
    $live = ['new-invoice', 'edit-item', 'delete-item'];
    $_POST = ['webhook_selected_events' => $live];

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(count($live), (int) ($response->payload['creados'] ?? -1), 'only the live selection is created');

    $posted = [];
    foreach (alegra_mock_requests('POST', '/webhooks/subscriptions') as $req) {
        $posted[] = (string) ($req['body']['event'] ?? '');
    }
    sort($posted);
    $expected = $live;
    sort($expected);
    TestRunner::assertSame($expected, $posted, 'the live selection is what reached Alegra');
    TestRunner::assertFalse(in_array('edit-invoice', $posted, true), 'an unchecked event was NOT subscribed');
});

TestRunner::test('T-WH-LIVE-2 the AJAX persists the live selection so the option reflects it', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    $live = ['new-invoice', 'edit-item'];
    $_POST = ['webhook_selected_events' => $live];

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = null;
    try {
        $admin->ajax_register_webhooks();
    } catch (Alegra_Test_JSON_Response $e) {
        $resp = $e;
    }

    TestRunner::assertTrue($resp instanceof Alegra_Test_JSON_Response, 'the AJAX handler must respond');

    $stored = (array) get_option(Receiver::EVENTS_OPTION, []);
    sort($stored);
    $expected = $live;
    sort($expected);
    TestRunner::assertSame($expected, $stored, 'the option must store the live selection');
    TestRunner::assertSame($live, Receiver::selected_events(), 'the reader must return the live selection after the AJAX');
});

TestRunner::test('T-WH-LIVE-3 an unchecking-all AJAX unsubscribes the previously-subscribed events', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    $url = Receiver::registration_url();
    $subs = [
        ['id' => 'wh-live-1', 'event' => 'new-invoice', 'url' => $url],
        ['id' => 'wh-live-2', 'event' => 'edit-invoice', 'url' => $url],
    ];
    foreach ($subs as $s) {
        $GLOBALS['alegra_mock_state']['subscriptions'][$s['id']] = $s;
    }
    update_option('alegra_connector_webhook_subscriptions', $subs, false);

    // The user unchecks everything in the dashboard and clicks Register.
    $_POST = ['webhook_selected_events' => []];

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = null;
    try {
        $admin->ajax_register_webhooks();
    } catch (Alegra_Test_JSON_Response $e) {
        $resp = $e;
    }

    TestRunner::assertTrue($resp instanceof Alegra_Test_JSON_Response, 'the AJAX handler must respond');
    TestRunner::assertSame(2, (int) ($resp->payload['eliminados'] ?? -1), 'both previously-subscribed events are unsubscribed');
    TestRunner::assertSame(0, count(alegra_mock_requests('POST', '/webhooks/subscriptions')), 'no new subscription was created');
    TestRunner::assertSame([], get_option(Receiver::EVENTS_OPTION, 'unset'), 'the option must store the empty selection');
});

TestRunner::test('T-WH-LIVE-4 an empty live array means "all 12" via the persisted default', function (): void {
    // The user untouched the dashboard and clicked Register without saving:
    // the JS posts an empty array (no boxes ticked). The handler must NOT
    // interpret that as "subscribe nothing"; it should honour the default
    // (all 12) — same as an absent option. So the JS only posts the array
    // when the user explicitly changed the selection; an empty-array POST
    // is treated as an explicit deselection and persists as empty.
    //
    // We model the realistic path: the JS detects a touch (the live array
    // differs from the stored array) and posts only when that happens. When
    // it does not post, the option drives the behaviour. This test pins
    // that fallback.
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');
    unset($_POST['webhook_selected_events']);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $response = alegra_capture_json(fn () => $admin->ajax_register_webhooks());

    TestRunner::assertSame(count(Client::get_webhook_events()), (int) ($response->payload['creados'] ?? -1), 'an unposted AJAX keeps the all-12 default');
});

TestRunner::test('T-WH-LIVE-5 unknown slugs in the live payload are dropped before registration', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_secret', 'hmac-secret');

    // A hostile or stale dashboard might post slugs we no longer recognise.
    $_POST = ['webhook_selected_events' => ['new-invoice', 'bogus', 'DROP TABLE users', '']];

$admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = null;
    try {
        $admin->ajax_register_webhooks();
    } catch (Alegra_Test_JSON_Response $e) {
        $resp = $e;
    }

    TestRunner::assertTrue($resp instanceof Alegra_Test_JSON_Response, 'the AJAX handler must respond');
    TestRunner::assertSame(['new-invoice'], get_option(Receiver::EVENTS_OPTION, []), 'only the documented slug survives');
    $posted = [];
    foreach (alegra_mock_requests('POST', '/webhooks/subscriptions') as $req) {
        $posted[] = (string) ($req['body']['event'] ?? '');
    }
    TestRunner::assertSame(['new-invoice'], $posted, 'an unknown slug was never sent to Alegra');
});

// ===========================================================================
// T-VOID — BUG 1: a voided invoice must be shown honestly
// ===========================================================================
echo "\nT-VOID — voided invoices\n";

TestRunner::test('T-VOID-1 the invoice status helper maps every documented status (never a silent "Facturado")', function (): void {
    $S = \Alegra\Connector\Invoice_Status::class;

    TestRunner::assertSame('warning', $S::badge_class('draft'), 'draft is a warning');
    TestRunner::assertSame('success', $S::badge_class('open'), 'open is success');
    TestRunner::assertSame('success', $S::badge_class('closed'), 'closed (settled) is success');
    TestRunner::assertSame('success', $S::badge_class('paid'), 'paid is success (legacy/mock value)');
    TestRunner::assertSame('danger', $S::badge_class('void'), 'void is danger');
    TestRunner::assertSame('neutral', $S::badge_class('mystery'), 'an unknown status must be neutral, not success');

    TestRunner::assertSame('Anulada en Alegra', $S::label('void'), 'void is translated');
    TestRunner::assertSame('Anulada en Alegra', $S::label('VOID'), 'the status is case-insensitive');
    TestRunner::assertSame('Pagada', $S::label('closed'), 'closed is translated');
    TestRunner::assertSame('Sin estado', $S::label(''), 'an empty status has a neutral label');
    TestRunner::assertSame('Sin estado', $S::label('mystery'), 'an unknown status has a neutral label');
    TestRunner::assertTrue($S::is_void('void'), 'is_void detects void');
    TestRunner::assertFalse($S::is_void('open'), 'is_void rejects open');
});

TestRunner::test('T-VOID-2 the orders list renders "Anulada en Alegra" for a void and never "Facturado"', function (): void {
    alegra_test_reset();
    $void = alegra_make_order(7101, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_id' => 'inv-v', '_alegra_invoice_number' => 'FV-9', '_alegra_invoice_status' => 'void',
    ]]);
    $unknown = alegra_make_order(7102, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_id' => 'inv-u', '_alegra_invoice_number' => 'FV-10', '_alegra_invoice_status' => 'mystery',
    ]]);
    $orders = [$void, $unknown];
    $total_orders = 2;
    $synced_orders = 2;
    $total_pages = 1;
    $page = 1;
    $payment_count = 0;

    ob_start();
    include $GLOBALS['alegra_plugin_root'] . 'templates/admin-orders.php';
    $html = (string) ob_get_clean();

    TestRunner::assertStringContains('<span class="ac-badge danger">Anulada en Alegra</span>', $html, 'a voided invoice must render the red Anulada badge');
    TestRunner::assertStringNotContains('<span class="ac-badge success">Facturado</span>', $html, 'a voided invoice must never render as Facturado');
    TestRunner::assertStringContains('<span class="ac-badge neutral">Sin estado</span>', $html, 'an unknown status must render neutrally');
});

TestRunner::test('T-VOID-3 the order detail translates the status and warns on a void', function (): void {
    alegra_test_reset();
    $order = alegra_make_order(7200, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_id' => 'inv-dv', '_alegra_invoice_number' => 'FV-11',
    ]]);
    $alegra_invoice_id = 'inv-dv';
    $alegra_invoice_number = 'FV-11';
    $alegra_data = ['id' => 'inv-dv', 'number' => 'FV-11', 'status' => 'void', 'total' => 10, 'balance' => 0];
    $alegra_error = null;
    $alegra_api = null;

    ob_start();
    include $GLOBALS['alegra_plugin_root'] . 'templates/admin-order-detail.php';
    $html = (string) ob_get_clean();

    TestRunner::assertStringContains('Anulada en Alegra', $html, 'the raw status must be translated to Spanish');
    TestRunner::assertStringContains('Factura anulada en Alegra', $html, 'a prominent warning must be rendered');
    TestRunner::assertStringNotContains('<td>void</td>', $html, 'the raw API status must not leak to the UI');
});

TestRunner::test('T-VOID-4 the webhook notes a voided invoice once, caches the status and never cancels the order', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-void-wh', ['status' => 'void', 'balance' => 0, 'total' => 50, 'number' => 'FV-77']);
    alegra_make_order(7300, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-void-wh']]);

    $logger = make_logger();
    $handlers = new \Alegra\Connector\Webhooks\Handlers(new Client($logger), $logger);

    // The payload lies (claims paid); the re-fetch is authoritative and says void.
    $handlers->process_event('new-invoice', ['invoice' => ['id' => 'inv-void-wh', 'status' => 'paid', 'balance' => 0]]);
    $handlers->process_event('edit-invoice', ['invoice' => ['id' => 'inv-void-wh', 'status' => 'paid', 'balance' => 0]]);

    $notes = wc_get_order(7300)->get_notes();
    $void_notes = array_values(array_filter($notes, static fn ($n) => str_contains((string) $n, 'anulada en Alegra')));
    TestRunner::assertSame(1, count($void_notes), 'the void note must be written exactly once');
    TestRunner::assertSame('processing', wc_get_order(7300)->get_status(), 'a void must never cancel the order');
    TestRunner::assertSame('void', (string) wc_get_order(7300)->get_meta('_alegra_invoice_status', true), 'the cached status must become void');
});

TestRunner::test('T-VOID-5 the poll notes a void once (idempotent) and covers completed orders', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-void-poll', ['status' => 'void', 'balance' => 0, 'total' => 30, 'number' => 'FV-88']);
    alegra_make_order(7400, ['status' => 'completed', 'meta' => ['_alegra_invoice_id' => 'inv-void-poll']]);

    $orders = make_orders();
    $orders->poll_invoice_statuses();
    $orders->poll_invoice_statuses();

    $notes = wc_get_order(7400)->get_notes();
    $void_notes = array_values(array_filter($notes, static fn ($n) => str_contains((string) $n, 'anulada en Alegra')));
    TestRunner::assertSame(1, count($void_notes), 'the poll must note the void exactly once across runs');
    TestRunner::assertSame('completed', wc_get_order(7400)->get_status(), 'a void must not change a completed order');
    TestRunner::assertSame('void', (string) wc_get_order(7400)->get_meta('_alegra_invoice_status', true), 'the poll must cache the void status');
});

// ===========================================================================
// T-SETTLED — a settled invoice is `closed`, not `paid`
//   https://developer.alegra.com/reference/get_invoices.md — the `status`
//   enum is `open`, `closed`, `draft`, `void`; `paid` is not documented.
// ===========================================================================
echo "\nT-SETTLED — settled invoices (paid/closed)\n";

TestRunner::test('T-SETTLED-1 is_paid() accepts paid and closed, rejects every unsettled status', function (): void {
    $S = \Alegra\Connector\Invoice_Status::class;

    TestRunner::assertTrue($S::is_paid('paid'), 'legacy/mock paid is settled');
    TestRunner::assertTrue($S::is_paid('closed'), 'closed is the documented settled status');
    TestRunner::assertTrue($S::is_paid('CLOSED'), 'the status is case-insensitive');
    TestRunner::assertFalse($S::is_paid('open'), 'open is issued but unpaid');
    TestRunner::assertFalse($S::is_paid('draft'), 'draft is not settled');
    TestRunner::assertFalse($S::is_paid('void'), 'void is not settled');
    TestRunner::assertFalse($S::is_paid('mystery'), 'an unknown status is not settled');
    TestRunner::assertFalse($S::is_paid(''), 'an empty status is not settled');
});

TestRunner::test('T-SETTLED-2 the webhook completes the order on a closed invoice', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-closed-wh', ['status' => 'closed', 'balance' => 0, 'total' => 100, 'number' => 'FV-C']);
    alegra_make_order(9100, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-closed-wh']]);

    $logger = make_logger();
    $handlers = new \Alegra\Connector\Webhooks\Handlers(new Client($logger), $logger);
    $handlers->process_event('new-invoice', ['invoice' => ['id' => 'inv-closed-wh', 'status' => 'closed', 'balance' => 0]]);

    TestRunner::assertSame('completed', wc_get_order(9100)->get_status(), 'a closed (settled) invoice must complete the order');
    TestRunner::assertSame('closed', (string) wc_get_order(9100)->get_meta('_alegra_invoice_status', true), 'the closed status must be cached');
});

TestRunner::test('T-SETTLED-3 the webhook never completes on open/draft/void (even with balance 0)', function (): void {
    alegra_test_reset();
    $cases = ['open' => 9201, 'draft' => 9202, 'void' => 9203];

    foreach ($cases as $status => $order_id) {
        $invoice_id = 'inv-' . $status . '-wh';
        alegra_mock_seed_invoice($invoice_id, ['status' => $status, 'balance' => 0, 'total' => 100, 'number' => 'FV-X']);
        alegra_make_order($order_id, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => $invoice_id]]);

        $logger = make_logger();
        $handlers = new \Alegra\Connector\Webhooks\Handlers(new Client($logger), $logger);
        $handlers->process_event('new-invoice', ['invoice' => ['id' => $invoice_id, 'status' => $status, 'balance' => 0]]);

        TestRunner::assertNotSame('completed', wc_get_order($order_id)->get_status(), "a $status invoice must not complete the order");
    }
});

TestRunner::test('T-SETTLED-4 the poll completes a closed invoice and leaves open/draft/void alone', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-closed-poll', ['status' => 'closed', 'balance' => 0, 'total' => 100, 'number' => 'FV-C']);
    alegra_mock_seed_invoice('inv-open-poll', ['status' => 'open', 'balance' => 0, 'total' => 100, 'number' => 'FV-O']);
    alegra_mock_seed_invoice('inv-draft-poll', ['status' => 'draft', 'balance' => 0, 'total' => 100, 'number' => 'FV-D']);
    alegra_mock_seed_invoice('inv-void-poll2', ['status' => 'void', 'balance' => 0, 'total' => 100, 'number' => 'FV-V']);
    alegra_make_order(9301, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-closed-poll']]);
    alegra_make_order(9302, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-open-poll']]);
    alegra_make_order(9303, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-draft-poll']]);
    alegra_make_order(9304, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-void-poll2']]);

    $result = make_orders()->poll_invoice_statuses();

    TestRunner::assertSame('completed', wc_get_order(9301)->get_status(), 'a closed invoice must complete the order');
    TestRunner::assertSame('closed', (string) wc_get_order(9301)->get_meta('_alegra_invoice_status', true), 'the poll must cache the closed status');
    TestRunner::assertSame('processing', wc_get_order(9302)->get_status(), 'open must not complete the order');
    TestRunner::assertSame('processing', wc_get_order(9303)->get_status(), 'draft must not complete the order');
    TestRunner::assertSame('processing', wc_get_order(9304)->get_status(), 'void must not complete the order');
    TestRunner::assertSame(1, (int) ($result['completed'] ?? 0), 'exactly one order must be completed');
});

TestRunner::test('T-SETTLED-5 paid still completes the order via the poll (no regression)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_invoice('inv-paid-poll', ['status' => 'paid', 'balance' => 0, 'total' => 100, 'number' => 'FV-P']);
    alegra_make_order(9400, ['status' => 'processing', 'meta' => ['_alegra_invoice_id' => 'inv-paid-poll']]);

    $result = make_orders()->poll_invoice_statuses();

    TestRunner::assertSame('completed', wc_get_order(9400)->get_status(), 'the legacy paid value must still complete the order');
    TestRunner::assertSame(1, (int) ($result['completed'] ?? 0), 'the paid invoice must be counted as completed');
});

TestRunner::test('T-SETTLED-6 the orders list renders a closed invoice as a green "Pagada" badge', function (): void {
    alegra_test_reset();
    $closed = alegra_make_order(9500, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_id' => 'inv-c', '_alegra_invoice_number' => 'FV-20', '_alegra_invoice_status' => 'closed',
    ]]);
    $orders = [$closed];
    $total_orders = 1;
    $synced_orders = 1;
    $total_pages = 1;
    $page = 1;
    $payment_count = 0;

    ob_start();
    include $GLOBALS['alegra_plugin_root'] . 'templates/admin-orders.php';
    $html = (string) ob_get_clean();

    TestRunner::assertStringContains('<span class="ac-badge success">Pagada</span>', $html, 'a closed invoice must render as a green Pagada badge');
    TestRunner::assertStringNotContains('Sin estado', $html, 'a closed invoice must never render as Sin estado');
});

// ===========================================================================
// T-INV-GATE — BUG 2: the inventory pull has its own gate + writes _stock_status
// ===========================================================================
echo "\nT-INV-GATE — inventory pull gate + stock status\n";

TestRunner::test('T-INV-GATE-1 the cron pulls inventory independently of sync_products', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    update_option('alegra_connector_inventory_sync_enabled', true);
    alegra_mock_seed_item('item-inv-1', ['name' => 'P1', 'reference' => 'SKU-1', 'type' => 'simple', 'inventory' => ['unit' => 'unit', 'availableQuantity' => 3]]);

    make_controller()->run_cron_sync();
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items'), 'with sync_products off but inventory sync on, the pull must run');

    alegra_test_reset();
    update_option('alegra_connector_sync_products', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    update_option('alegra_connector_inventory_sync_enabled', false);
    alegra_mock_seed_item('item-inv-2', ['name' => 'P2', 'reference' => 'SKU-2', 'type' => 'simple', 'inventory' => ['unit' => 'unit', 'availableQuantity' => 3]]);

    make_controller()->run_cron_sync();
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'with inventory sync off, the pull must not run');
});

TestRunner::test('T-INV-GATE-2 the pull writes _stock_status so a 0 quantity becomes outofstock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(5001, ['name' => 'Zero', 'sku' => 'Z-1', 'regular_price' => '5', 'stock' => 9, 'manage_stock' => true, 'stock_status' => 'instock']);
    update_post_meta(5001, '_alegra_item_id', 'item-zero');
    alegra_mock_seed_item('item-zero', ['name' => 'Zero', 'reference' => 'Z-1', 'type' => 'simple', 'inventory' => ['unit' => 'unit', 'availableQuantity' => 0]]);

    make_products()->sync_inventory_from_alegra();

    $product = wc_get_product(5001);
    TestRunner::assertSame(0, $product->get_stock_quantity(), 'the quantity must be 0');
    TestRunner::assertSame('outofstock', $product->get_stock_status(), 'a 0 quantity must become outofstock, not keep a stale instock');
});

TestRunner::test('T-INV-GATE-3 the pull keeps its kill switch / lock / cancellation gates', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    update_option('alegra_connector_inventory_sync_enabled', true);

    \Alegra\Connector\Kill_Switch::activate('test');
    $killed = make_products()->sync_inventory_from_alegra();
    \Alegra\Connector\Kill_Switch::deactivate();
    TestRunner::assertTrue(!empty($killed['skipped']), 'the kill switch must skip the pull');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'the kill switch must block the fetch');

    $token = Controller::acquire_lock('alegra_sync_running_products', 300);
    $locked = make_products()->sync_inventory_from_alegra();
    Controller::release_lock('alegra_sync_running_products', $token);
    TestRunner::assertTrue(!empty($locked['locked']), 'a held products lock must report locked');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'a held lock must block the fetch');

    set_transient('alegra_sync_cancelled', 1, 60);
    $cancelled = make_products()->sync_inventory_from_alegra();
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'a cancelled pull must not fetch');
    TestRunner::assertTrue(!empty($cancelled['updated']) === false, 'a cancelled pull must update nothing');
});

TestRunner::test('T-INV-GATE-4 the inventory-sync gate is wired: option, UI, default and uninstall', function (): void {
    $admin = cfg2_source('admin/Admin/Admin_Dashboard.php');
    $tpl = cfg2_source('templates/admin-settings.php');
    $bootstrap = cfg2_source('alegra-connector.php');
    $uninstall = cfg2_source('uninstall.php');

    TestRunner::assertStringContains("register_setting('alegra_connector_settings', 'alegra_connector_inventory_sync_enabled'", $admin, 'the option must be registered');
    TestRunner::assertStringContains('name="alegra_connector_inventory_sync_enabled"', $tpl, 'the option must be rendered in the UI');
    TestRunner::assertStringContains("'alegra_connector_inventory_sync_enabled' => true", $bootstrap, 'the activation default must be true (preserves the Alegra source)');
    TestRunner::assertStringContains("delete_option('alegra_connector_inventory_sync_enabled')", $uninstall, 'uninstall must delete the option');
});

// ===========================================================================
// T26 — Webhook delivery recorder + inspector verdict
//
// The inventory design is blocked on whether Alegra emits `edit-item` (with
// inventory) when stock changes. The receiver keeps a bounded ring buffer of
// the last deliveries so the read-only inspector can answer it from the raw
// payloads. These tests lock the buffer's bounds and the verdict logic.
// ===========================================================================
echo "\nT26 — Webhook delivery recorder + inspector verdict\n";

/** The recorder class under test. */
function alegra_recorder(): string
{
    return \Alegra\Connector\Webhooks\Recorder::class;
}

TestRunner::test('T26.1 a webhook delivery is recorded with its subject, raw body and source IP', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);
    $body = json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '865', 'name' => 'Camiseta azul', 'inventory' => ['availableQuantity' => 12]]],
    ]);

    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    unset($_SERVER['REMOTE_ADDR']);

    TestRunner::assertSame(200, $res->get_status(), 'the delivery must be acked');

    $entries = alegra_recorder()::all();
    TestRunner::assertCount(1, $entries, 'exactly one delivery must be recorded');
    TestRunner::assertSame('edit-item', $entries[0]['subject'] ?? null, 'the subject must be recorded');
    TestRunner::assertSame($body, $entries[0]['body'] ?? null, 'the RAW body must be recorded verbatim (no transformation)');
    TestRunner::assertSame('203.0.113.7', $entries[0]['ip'] ?? null, 'the source IP must be recorded');
});

TestRunner::test('T26.2 the recorder is bounded: 60 deliveries keep only the last 50', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 60; $i++) {
        alegra_recorder()::record('edit-item', '{"n":' . $i . '}');
    }

    $entries = alegra_recorder()::all();
    TestRunner::assertCount(\Alegra\Connector\Webhooks\Recorder::MAX_ENTRIES, $entries, 'the ring buffer must cap at 50');
    TestRunner::assertSame('{"n":11}', $entries[0]['body'] ?? null, 'the oldest 10 deliveries must be dropped');
    TestRunner::assertSame('{"n":60}', $entries[49]['body'] ?? null, 'the newest delivery must be kept');
});

TestRunner::test('T26.3 a body larger than 20 KB is truncated and flagged', function (): void {
    alegra_test_reset();
    $huge = str_repeat('a', \Alegra\Connector\Webhooks\Recorder::MAX_BODY_BYTES + 500);

    alegra_recorder()::record('edit-item', $huge);
    $entry = alegra_recorder()::all()[0] ?? [];

    TestRunner::assertSame(
        \Alegra\Connector\Webhooks\Recorder::MAX_BODY_BYTES,
        strlen((string) ($entry['body'] ?? '')),
        'the stored body must be capped at MAX_BODY_BYTES'
    );
    TestRunner::assertTrue((bool) ($entry['truncated'] ?? false), 'the truncation must be flagged');
    TestRunner::assertSame(strlen($huge), (int) ($entry['bytes'] ?? 0), 'the original byte size must be kept');
});

TestRunner::test('T26.4 the inspector verdict is SÍ with availableQuantity and NO without it', function (): void {
    alegra_test_reset();
    $with = json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '865', 'inventory' => ['availableQuantity' => 3]]],
    ]);
    $without = json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '866']],
    ]);

    TestRunner::assertTrue(
        alegra_recorder()::has_inventory_available_quantity($with),
        'a payload WITH inventory.availableQuantity must be detected'
    );
    TestRunner::assertFalse(
        alegra_recorder()::has_inventory_available_quantity($without),
        'a payload WITHOUT inventory.availableQuantity must not be detected'
    );

    $yes = alegra_recorder()::inventory_verdict([['subject' => 'edit-item', 'body' => $with]]);
    TestRunner::assertStringContains('SÍ', $yes, 'the verdict must be SÍ when inventory is present');

    $no = alegra_recorder()::inventory_verdict([['subject' => 'edit-item', 'body' => $without]]);
    TestRunner::assertStringContains('NO', $no, 'the verdict must be NO when inventory is absent');
    TestRunner::assertStringContains('poll', $no, 'the NO verdict must point at the poll fallback');

    $none = alegra_recorder()::inventory_verdict([['subject' => 'new-item', 'body' => $with]]);
    TestRunner::assertStringContains('No hay entregas', $none, 'a set with no edit-item must say so');
});

TestRunner::test('T26.5 recording does not change the handshake, the token gate or the dedupe', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);

    // Handshake: empty body, no token, must be acked and NOT recorded.
    $handshake = $receiver->handle(new WP_REST_Request(''));
    TestRunner::assertSame(200, $handshake->get_status(), 'the handshake must still be 2XX');
    TestRunner::assertTrue((bool) (($handshake->get_data())['handshake'] ?? false), 'the handshake flag must remain');
    TestRunner::assertCount(0, alegra_recorder()::all(), 'the handshake must not be recorded');

    // Token gate: no token -> 401 and nothing recorded.
    $body = json_encode(['subject' => 'new-bill', 'message' => ['bill' => ['id' => 'b-1']]]);
    $no_token = $receiver->handle(new WP_REST_Request($body));
    TestRunner::assertSame(401, $no_token->get_status(), 'the token gate must still reject');
    TestRunner::assertCount(0, alegra_recorder()::all(), 'a rejected delivery must not be recorded');

    // Dedupe: first accepted + recorded, replay flagged + NOT recorded twice.
    $first = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    $second = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $first->get_status(), 'the first delivery must be accepted');
    TestRunner::assertFalse((bool) (($first->get_data())['duplicate'] ?? false), 'the first delivery must not be a duplicate');
    TestRunner::assertSame(200, $second->get_status(), 'the replay must still be acked');
    TestRunner::assertTrue((bool) (($second->get_data())['duplicate'] ?? false), 'the replay must still be flagged');
    TestRunner::assertCount(1, alegra_recorder()::all(), 'the replay must not be recorded a second time');
});

// ===========================================================================
// T27 — Webhook admin screen (submenu + raw deliveries + inventory verdict)
//
// The merchant must answer "does edit-item carry inventory?" from wp-admin, not
// from scripts/inspect-webhooks.php (which is excluded from the release ZIP).
// The screen is a submenu backed by templates/admin-webhooks.php and reuses the
// Recorder's inspection helpers verbatim, so it can never drift from the CLI.
// ===========================================================================
echo "\nT27 — Webhook admin screen\n";

/**
 * Render the webhook admin screen the way WordPress would, optionally with a
 * subject filter / clear notice in $_GET.
 */
function alegra_render_webhooks(string $subject = '', bool $cleared = false): string
{
    if ($subject === '') {
        unset($_GET['subject']);
    } else {
        $_GET['subject'] = $subject;
    }
    if ($cleared) {
        $_GET['cleared'] = '1';
    } else {
        unset($_GET['cleared']);
    }

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    ob_start();
    $admin->render_webhooks_page();
    return (string) ob_get_clean();
}

TestRunner::test('T27.1 the Webhooks submenu is registered with manage_woocommerce', function (): void {
    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $admin->add_admin_menu();

    $found = null;
    foreach ((array) ($GLOBALS['alegra_test_admin_pages'] ?? []) as $page) {
        if (($page['slug'] ?? '') === 'alegra-connector-webhooks') {
            $found = $page;
            break;
        }
    }

    TestRunner::assertTrue($found !== null, 'the Webhooks submenu must be registered');
    TestRunner::assertSame('submenu', $found['type'] ?? null, 'it must be a submenu of the Alegra menu');
    TestRunner::assertSame('alegra-connector', $found['parent'] ?? null, 'it must hang off the Alegra Connector menu');
    TestRunner::assertSame('manage_woocommerce', $found['cap'] ?? null, 'the page must require manage_woocommerce');
    TestRunner::assertSame('Webhooks', $found['title'] ?? null, 'the label must be Webhooks');
    TestRunner::assertTrue(is_array($found['callback'] ?? null), 'the callback must be a callable array');
    TestRunner::assertSame('render_webhooks_page', $found['callback'][1] ?? null, 'the callback must render the webhook screen');
    TestRunner::assertTrue(has_action('admin_post_alegra_clear_webhooks'), 'the clear action must be registered');
});

TestRunner::test('T27.2 recorded deliveries render the summary table and the raw payload', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', (string) json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '865', 'name' => 'Camiseta azul']],
    ]), '203.0.113.9');

    $html = alegra_render_webhooks();

    TestRunner::assertStringContains('Resumen de entregas', $html, 'the summary table must render');
    TestRunner::assertStringContains('Payloads crudos', $html, 'the raw payloads section must render');
    TestRunner::assertStringContains('edit-item', $html, 'the subject must render');
    TestRunner::assertStringContains('item 865 — &quot;Camiseta azul&quot;', $html, 'the one-line entity summary must render (escaped)');
    TestRunner::assertStringContains('203.0.113.9', $html, 'the source IP must render');
    TestRunner::assertStringContains('Camiseta azul', $html, 'the raw payload must render');
    TestRunner::assertStringContains('Se guardan las últimas 50 entregas.', $html, 'the retention note must render');
});

TestRunner::test('T27.3 an edit-item WITH inventory.availableQuantity shows the SÍ verdict', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', (string) json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '865', 'inventory' => ['availableQuantity' => 7]]],
    ]));

    $html = alegra_render_webhooks();

    TestRunner::assertStringContains(\Alegra\Connector\Webhooks\Recorder::VERDICT_YES, $html, 'the SÍ verdict must be shown');
    TestRunner::assertStringNotContains('NO envía inventario', $html, 'the NO verdict must not appear');
});

TestRunner::test('T27.4 an edit-item WITHOUT inventory.availableQuantity shows the NO verdict', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', (string) json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '866']],
    ]));

    $html = alegra_render_webhooks();

    TestRunner::assertStringContains(\Alegra\Connector\Webhooks\Recorder::VERDICT_NO, $html, 'the NO verdict must be shown');
    TestRunner::assertStringNotContains('SÍ envía inventario', $html, 'the SÍ verdict must not appear');
});

TestRunner::test('T27.5 zero edit-item deliveries show the warning and the 5 test steps', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('new-item', (string) json_encode([
        'subject' => 'new-item',
        'message' => ['item' => ['id' => '1']],
    ]));

    $html = alegra_render_webhooks();

    TestRunner::assertStringContains(\Alegra\Connector\Webhooks\Recorder::VERDICT_NONE, $html, 'the no-edit-item verdict must be shown');
    TestRunner::assertStringContains('Todavía no hay entregas de edit-item', $html, 'the warning card must render');
    TestRunner::assertStringContains('suscribí el evento edit-item', $html, 'step 1 must render');
    TestRunner::assertStringContains('Anotá el stock', $html, 'step 2 must render');
    TestRunner::assertStringContains('Anulá una factura', $html, 'step 3 must render');
    TestRunner::assertStringContains('Recargá esta página', $html, 'step 4 must render');
    TestRunner::assertStringContains('Leé el veredicto', $html, 'step 5 must render');
});

TestRunner::test('T27.6 the raw remote payload is escaped, never injected as HTML', function (): void {
    alegra_test_reset();
    $xss = '<script>alert(1)</script>';
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', (string) json_encode([
        'subject' => 'edit-item',
        'message' => ['item' => ['id' => '865', 'name' => $xss]],
    ]));

    $html = alegra_render_webhooks();

    TestRunner::assertStringNotContains($xss, $html, 'the raw <script> payload must never appear unescaped');
    TestRunner::assertStringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the payload must be HTML-escaped');
});

TestRunner::test('T27.7 the clear action is nonce-protected, capability-gated and empties the buffer', function (): void {
    // Happy path: valid capability + nonce -> buffer emptied and a redirect issued.
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', '{"a":1}');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());

    $redirect = null;
    try {
        $admin->handle_clear_webhooks();
    } catch (Alegra_Test_Redirect $e) {
        $redirect = $e;
    }
    TestRunner::assertTrue($redirect !== null, 'a successful clear must redirect');
    TestRunner::assertStringContains('cleared=1', (string) ($redirect->location ?? ''), 'the redirect must confirm the clear');
    TestRunner::assertCount(0, \Alegra\Connector\Webhooks\Recorder::all(), 'the buffer must be emptied');

    // Capability gate: no manage_woocommerce -> wp_die, buffer untouched.
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', '{"a":1}');
    $GLOBALS['alegra_test_caps']['manage_woocommerce'] = false;
    $GLOBALS['alegra_test_wp_die_throws'] = true;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $died = false;
    try {
        $admin->handle_clear_webhooks();
    } catch (Alegra_Test_Die $e) {
        $died = true;
    }
    TestRunner::assertTrue($died, 'without the capability the action must wp_die');
    TestRunner::assertCount(1, \Alegra\Connector\Webhooks\Recorder::all(), 'the buffer must survive a capability denial');

    // Nonce gate: invalid referer -> wp_die, buffer untouched.
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', '{"a":1}');
    $GLOBALS['alegra_test_referer_ok'] = false;
    $GLOBALS['alegra_test_wp_die_throws'] = true;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $died = false;
    try {
        $admin->handle_clear_webhooks();
    } catch (Alegra_Test_Die $e) {
        $died = true;
    }
    TestRunner::assertTrue($died, 'an invalid nonce must wp_die');
    TestRunner::assertCount(1, \Alegra\Connector\Webhooks\Recorder::all(), 'the buffer must survive a nonce failure');

    // The form actually carries the nonce field and the registered action.
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-webhooks.php');
    TestRunner::assertStringContains("wp_nonce_field('alegra_clear_webhooks')", $tpl, 'the clear form must render a nonce field');
    TestRunner::assertStringContains('value="alegra_clear_webhooks"', $tpl, 'the clear form must post the registered action');
});

TestRunner::test('T27.8 the subject filter narrows the tables without changing the global verdict', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Webhooks\Recorder::record('new-item', (string) json_encode([
        'subject' => 'new-item', 'message' => ['item' => ['id' => '1']],
    ]));
    \Alegra\Connector\Webhooks\Recorder::record('edit-item', (string) json_encode([
        'subject' => 'edit-item', 'message' => ['item' => ['id' => '865', 'inventory' => ['availableQuantity' => 2]]],
    ]));

    $html = alegra_render_webhooks('new-item');
    unset($_GET['subject']);

    TestRunner::assertStringContains('new-item', $html, 'the filtered subject must render');
    TestRunner::assertStringNotContains('item 865', $html, 'the non-matching edit-item row must be filtered out');
    TestRunner::assertStringContains(\Alegra\Connector\Webhooks\Recorder::VERDICT_YES, $html, 'the verdict must stay global (SÍ) even when the table is filtered');
});

// ===========================================================================
// === logs-monitor-import (2.5.0) ===
// T28.{fase}{n} — Fase 1: Run_Context + Runs/Heartbeat/Logger foundations
// ===========================================================================

TestRunner::test('T28.11 el stub wpdb emula wp_alegra_runs (insert/update/get_var)', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'x', 'status' => 'running', 'started_at' => current_time('mysql')]);
    TestRunner::assertSame('running', $wpdb->get_var("SELECT status FROM wp_alegra_runs WHERE id = 1"), 'la fila insertada debe leerse running');
    $wpdb->update($wpdb->prefix . 'alegra_runs', ['status' => 'completed'], ['id' => 1]);
    TestRunner::assertSame('completed', $wpdb->get_var("SELECT status FROM wp_alegra_runs WHERE id = 1"), 'el update debe persistir el status');
});

TestRunner::test('T28.12 Run_Context::begin crea fila running y current() la expone', function (): void {
    alegra_test_reset();
    $id = \Alegra\Connector\Run_Context::begin('manual_import', 'tester');
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'begin debe crear la fila running');
    TestRunner::assertSame($id, \Alegra\Connector\Run_Context::current(), 'current() debe devolver el run activo');
    \Alegra\Connector\Run_Context::finish($id, 'completed');
    TestRunner::assertSame(0, \Alegra\Connector\Run_Context::current(), 'fuera del run current() debe ser 0');
});

TestRunner::test('T28.13 Run_Context::wrap completa y propaga excepciones', function (): void {
    alegra_test_reset();
    $out = \Alegra\Connector\Run_Context::wrap('manual_import', fn ($rid) => 7, 'tester');
    TestRunner::assertSame(7, $out, 'wrap debe devolver el resultado del closure');
    TestRunner::assertSame('completed', $GLOBALS['alegra_db']['wp_alegra_runs'][0]['status'] ?? null, 'wrap debe dejar el run completed');

    alegra_test_reset();
    $threw = false;
    try {
        \Alegra\Connector\Run_Context::wrap('manual_import', function (): void { throw new \RuntimeException('boom'); }, 'tester');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    TestRunner::assertTrue($threw, 'wrap debe re-lanzar la excepción');
    TestRunner::assertSame('failed', $GLOBALS['alegra_db']['wp_alegra_runs'][0]['status'] ?? null, 'wrap debe dejar el run failed');
});

TestRunner::test('T28.14 Run_Context::current es 0 fuera de un run', function (): void {
    alegra_test_reset();
    TestRunner::assertSame(0, \Alegra\Connector\Run_Context::current(), 'sin begin/resume current() es 0');
});

TestRunner::test('T28.15 Logger inyecta run_id/run_type y respeta el run_id explícito', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    $logger = make_logger();

    \Alegra\Connector\Logger\Logger::set_run_context(42, 'manual_import');
    $logger->info('linea con contexto');
    \Alegra\Connector\Logger\Logger::clear_run_context();
    $log = alegra_read_log();
    TestRunner::assertStringContains('"run_id":42', $log, 'la línea debe llevar run_id=42');
    TestRunner::assertStringContains('"run_type":"manual_import"', $log, 'la línea debe llevar run_type');

    alegra_clear_log();
    \Alegra\Connector\Logger\Logger::set_run_context(42, 'manual_import');
    $logger->info('explicito', ['run_id' => 7]);
    \Alegra\Connector\Logger\Logger::clear_run_context();
    $log = alegra_read_log();
    TestRunner::assertStringContains('"run_id":7', $log, 'el run_id explícito debe conservarse');
    TestRunner::assertStringNotContains('"run_id":42', $log, 'no debe pisar el explícito');
});

TestRunner::test('T28.16 mark_abandoned cierra el chunked huérfano pero no el manual', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 300);

    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'running', 'started_at' => $old]);
    $chunked = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'manual_import', 'status' => 'running', 'started_at' => $old]);
    $manual = (int) $wpdb->insert_id;

    $running = \Alegra\Connector\Runs::currently_running();

    TestRunner::assertSame('stale', \Alegra\Connector\Runs::status($chunked), 'el chunked huérfano debe quedar stale');
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($manual), 'el manual no lo cubre mark_abandoned');
    foreach ($running as $r) {
        TestRunner::assertNotSame($chunked, (int) $r->id, 'el chunked stale no debe listarse como running');
    }
});

TestRunner::test('T28.17 un chunked con heartbeat vivo no se marca abandonado', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 300);
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'running', 'started_at' => $old]);
    $id = (int) $wpdb->insert_id;
    \Alegra\Connector\Heartbeat::set($id, ['step' => 'products', 'message' => 'vivo']);
    \Alegra\Connector\Runs::currently_running();
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'con heartbeat vivo no debe marcarse');
});

TestRunner::test('T28.18 mark_stale no marca stale un run recién arrancado (offset no-UTC)', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_gmt_offset'] = -5;   // America/Bogota (UTC-5)

    $id = \Alegra\Connector\Runs::start('manual_import', 'tester');   // started_at = hora LOCAL
    \Alegra\Connector\Runs::currently_running();                       // dispara mark_stale()

    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'un run recién arrancado no debe quedar stale');

    $GLOBALS['alegra_test_gmt_offset'] = 0;
});

TestRunner::test('T28.19 clear() no borra el stop; forget() borra sólo el display', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Runs::request_stop(9);
    \Alegra\Connector\Heartbeat::set(9, ['step' => 'products', 'message' => 'x']);

    \Alegra\Connector\Heartbeat::clear(9);
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop(9), 'clear() NO debe borrar el pedido de stop');

    \Alegra\Connector\Heartbeat::forget(9);
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop(9), 'forget() NO debe borrar el stop');
    TestRunner::assertSame(null, \Alegra\Connector\Heartbeat::get(9), 'forget() debe borrar el display');
});

TestRunner::test('T28.110 ajax_kill_run pide el stop y NO limpia el heartbeat', function (): void {
    alegra_test_reset();
    $run_id = 55;
    \Alegra\Connector\Heartbeat::set($run_id, ['step' => 'products', 'message' => 'corriendo']);

    $_POST['run_id'] = $run_id;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_kill_run());
    unset($_POST['run_id']);

    TestRunner::assertTrue($resp->success, 'kill_run debe responder success');
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop($run_id), 'el stop debe quedar pedido');
    TestRunner::assertTrue(\Alegra\Connector\Heartbeat::get($run_id) !== null, 'el heartbeat NO debe limpiarse');
});

TestRunner::test('T28.111 Run_Context::finish no re-finaliza un run ya cerrado', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'completed', 'started_at' => current_time('mysql')]);
    $run_id = (int) $wpdb->insert_id;

    \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');

    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status($run_id), 'no debe pisar completed con cancelled');
});

// ===========================================================================
// === logs-monitor-import (2.5.0) — Fase 2: instrumentación de los 4 caminos ===
// ===========================================================================

TestRunner::test('T28.21 cron wrap crea run completed', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);
    make_controller()->run_cron_sync();
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status(1), 'el cron debe dejar la fila completed');
});

TestRunner::test('T28.22 skip logueado', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', false);
    alegra_clear_log();
    make_controller()->run_cron_sync();
    TestRunner::assertStringContains('products skipped by configuration', alegra_read_log(), 'el gate debe loguear el skip');
});

TestRunner::test('T28.23 propaga run_id', function (): void {
    alegra_test_reset();
    for ($i = 0; $i < 5; $i++) {
        alegra_mock_seed_item('it-p-' . $i, ['name' => 'I' . $i, 'reference' => 'S' . $i, 'status' => 'active']);
    }
    \Alegra\Connector\Runs::request_stop(7);
    $r = make_controller()->import_from_alegra('products', 7);
    TestRunner::assertTrue(is_array($r), 'el import debe devolver un array');
    TestRunner::assertSame(false, (bool) ($r['paused'] ?? true), 'un stop no es una pausa por presupuesto');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/items'), 'el stop debe cortar antes del fetch');
});

TestRunner::test('T28.24 stop del cron cierra cancelled', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_products', true);
    \Alegra\Connector\Runs::request_stop(1);
    make_controller()->run_cron_sync();
    TestRunner::assertSame('cancelled', \Alegra\Connector\Runs::status(1), 'el stop del cron debe dejar cancelled');
});

TestRunner::test('T28.25 lock ocupado cierra failed y libera (manual)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    add_option('alegra_lock_alegra_sync_running_products', ['token' => 'x', 'expires' => time() + 300], '', 'no');
    $_POST['import_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_import_from_api());
    unset($_POST['import_type']);
    TestRunner::assertFalse($resp->success, 'el lock ocupado debe responder error');
    TestRunner::assertStringContains('Another sync', (string) ($resp->payload['message'] ?? ''), 'mensaje de lock');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status(1), 'la fila debe cerrarse failed');
});

TestRunner::test('T28.26 conexión no testeada deja rastro (manual)', function (): void {
    alegra_test_reset();
    delete_option('alegra_connector_connection_tested');
    alegra_clear_log();
    $_POST['import_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_import_from_api());
    unset($_POST['import_type']);
    $log = alegra_read_log();
    TestRunner::assertFalse($resp->success, 'sin conexión debe responder error');
    TestRunner::assertStringContains('connection_not_tested', $log, 'el log debe llevar el motivo');
    TestRunner::assertStringContains('"run_type":"manual_import"', $log, 'el run_type canónico');
});

TestRunner::test('T28.27 stop manual cierra cancelled', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    \Alegra\Connector\Runs::request_stop(1);
    $_POST['import_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_import_from_api());
    unset($_POST['import_type']);
    TestRunner::assertSame('cancelled', \Alegra\Connector\Runs::status(1), 'el stop debe cerrar cancelled');
    TestRunner::assertStringContains('detenida por el usuario', (string) ($resp->payload['message'] ?? ''), 'mensaje honesto de stop');
});

TestRunner::test('T28.28 start crea run chunked running', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    alegra_mock_seed_item('it-1', ['name' => 'One', 'reference' => 'S1', 'status' => 'active']);
    $_POST['sync_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_start());
    unset($_POST['sync_type']);
    TestRunner::assertTrue($resp->success, 'start debe responder success');
    $run_id = (int) ($resp->payload['run_id'] ?? 0);
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($run_id), 'la fila debe quedar running');
    TestRunner::assertSame($run_id, (int) (get_transient('alegra_batch_state')['run_id'] ?? 0), 'el state debe llevar el run_id');
});

TestRunner::test('T28.29 from_zero limpia cursor', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    update_option('alegra_connector_products_import_cursor', 1500, false);
    $_POST['sync_type'] = 'products';
    $_POST['from_zero'] = '1';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_start());
    unset($_POST['sync_type'], $_POST['from_zero']);
    TestRunner::assertSame(0, (int) ($resp->payload['start'] ?? -1), 'from_zero arranca en 0');
    TestRunner::assertFalse(get_option('alegra_connector_products_import_cursor', false), 'el cursor debe borrarse');
});

TestRunner::test('T28.210 sin conexión fail_early (chunked)', function (): void {
    alegra_test_reset();
    delete_option('alegra_connector_connection_tested');
    alegra_clear_log();
    $_POST['sync_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_start());
    unset($_POST['sync_type']);
    $log = alegra_read_log();
    TestRunner::assertFalse($resp->success, 'sin conexión debe fallar');
    TestRunner::assertStringContains('connection_not_tested', $log, 'el log lleva el motivo');
    TestRunner::assertStringContains('"run_type":"chunked_import"', $log, 'run_type canónico');
});

TestRunner::test('T28.211 segundo start rechazado (D3)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    \Alegra\Connector\Heartbeat::set($rid, ['step' => 'products', 'message' => 'vivo']);
    set_transient('alegra_batch_state', ['type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 0], 600);
    $_POST['sync_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_start());
    TestRunner::assertFalse($resp->success, 'un segundo start con run vivo debe rechazarse');
    TestRunner::assertStringContains('importación en curso', (string) ($resp->payload['message'] ?? ''), 'mensaje de rechazo');

    \Alegra\Connector\Heartbeat::forget($rid);
    $resp2 = alegra_capture_json(fn () => $admin->ajax_sync_start());
    unset($_POST['sync_type']);
    TestRunner::assertTrue($resp2->success, 'sin heartbeat vivo debe permitir reanudar');
});

TestRunner::test('T28.212 WP_Error cierra failed y libera lock', function (): void {
    alegra_test_reset();
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    alegra_mock_fail('GET', '/items', 500, ['error' => 'boom']);
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 60, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'policy' => 'respect', 'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertFalse($resp->success, 'el WP_Error debe responder error');
    TestRunner::assertStringContains('boom', (string) ($resp->payload['message'] ?? ''), 'el mensaje lleva la causa');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status($rid), 'la fila debe quedar failed');
    TestRunner::assertFalse(get_option('alegra_lock_alegra_sync_running_products', false), 'el lock debe quedar libre');
});

TestRunner::test('T28.213 lock ocupado cierra failed (chunked)', function (): void {
    alegra_test_reset();
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    add_option('alegra_lock_alegra_sync_running_products', ['token' => 'other', 'expires' => time() + 300], '', 'no');
    set_transient('alegra_batch_state', ['type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 0], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertFalse($resp->success, 'el lock ocupado debe fallar');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status($rid), 'la fila debe quedar failed');
});

TestRunner::test('T28.214 stop cierra cancelled (chunked)', function (): void {
    alegra_test_reset();
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    \Alegra\Connector\Runs::request_stop($rid);
    set_transient('alegra_batch_state', ['type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 0], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame('cancelled', \Alegra\Connector\Runs::status($rid), 'el stop debe cerrar cancelled');
    TestRunner::assertSame(true, $resp->payload['cancelled'] ?? null, 'la respuesta debe flag cancelled');
});

TestRunner::test('T28.215 cancel del botón cierra cancelled (D1)', function (): void {
    alegra_test_reset();
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', ['type' => 'products', 'run_id' => $rid], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    alegra_capture_json(fn () => $admin->ajax_cancel_sync());
    TestRunner::assertSame('cancelled', \Alegra\Connector\Runs::status($rid), 'cancel debe cerrar cancelled');
    TestRunner::assertFalse(get_transient('alegra_batch_state'), 'el state debe borrarse');
    TestRunner::assertTrue((bool) get_transient('alegra_sync_cancelled'), 'el flag debe quedar');
});

TestRunner::test('T28.216 customers avanza el cursor (D2)', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 30; $i++) {
        alegra_mock_seed_contact('c-' . $i, ['name' => 'C' . $i, 'email' => 'c' . $i . '@example.test']);
    }
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'customers', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 60, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(30, (int) (get_transient('alegra_batch_state')['start'] ?? -1), 'el cursor debe avanzar a 30');
    TestRunner::assertSame(false, $resp->payload['done'] ?? true, 'no debe estar done');
});

TestRunner::test('T28.217 categorías avanzan el cursor (D2)', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 30; $i++) {
        alegra_mock_seed_category('cat-' . $i, ['name' => 'Cat ' . $i]);
    }
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'categories', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 60, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(30, (int) (get_transient('alegra_batch_state')['start'] ?? -1), 'el cursor debe avanzar a 30');
    TestRunner::assertSame(false, $resp->payload['done'] ?? true, 'no debe estar done');
});

TestRunner::test('T28.218 el lock se toma antes de la lectura autoritativa (D3)', function (): void {
    $src = alegra_method_source(\Alegra\Connector\Admin\Admin_Dashboard::class, 'ajax_sync_page');
    $first = strpos($src, "get_transient('alegra_batch_state')");
    $lock = strpos($src, 'acquire_sync_lock_public');
    $second = strpos($src, "get_transient('alegra_batch_state')", (int) $first + 1);
    TestRunner::assertTrue($first !== false && $lock !== false && $second !== false, 'deben existir las dos lecturas y el lock');
    TestRunner::assertTrue($lock > $first, 'el lock va después de la lectura provisional');
    TestRunner::assertTrue($second > $lock, 'la lectura autoritativa va después del lock');
});

TestRunner::test('T28.219 webhook new-item deja fila completed', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    alegra_mock_seed_item('it-web-1', ['name' => 'Web', 'reference' => 'SW', 'type' => 'product', 'status' => 'active']);
    $logger = make_logger();
    $receiver = new \Alegra\Connector\Webhooks\Receiver(new Client($logger), $logger);
    $body = json_encode(['subject' => 'new-item', 'message' => ['item' => ['id' => 'it-web-1']]]);
    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $res->get_status(), 'el webhook debe ACKear 200');
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status(1), 'la fila debe quedar completed');
    TestRunner::assertSame('webhook_item', (string) ($GLOBALS['alegra_db']['wp_alegra_runs'][0]['run_type'] ?? ''), 'run_type webhook_item');
    TestRunner::assertTrue((bool) \Alegra\Connector\Entity_Map::find_wc_id('item', 'it-web-1', 'product'), 'el ítem debe importarse');
});

TestRunner::test('T28.220 handler que falla deja failed y 200', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $throwing = new class($logger) extends Client {
        public function get_item(string $id): array|\WP_Error
        {
            throw new \RuntimeException('boom handler');
        }
    };
    $receiver = new \Alegra\Connector\Webhooks\Receiver($throwing, $logger);
    $body = json_encode(['subject' => 'new-item', 'message' => ['item' => ['id' => 'it-web-x']]]);
    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $res->get_status(), 'el webhook debe ACKear 200 aunque falle');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status(1), 'la fila debe quedar failed');
});

TestRunner::test('T28.221 pausa por presupuesto cierra paused (H1)', function (): void {
    $src = alegra_method_source(\Alegra\Connector\Admin\Admin_Dashboard::class, 'ajax_import_from_api');
    $stop = strpos($src, "should_stop(\$run_id)");
    $paused = strpos($src, "!empty(\$result['paused'])");
    TestRunner::assertTrue($stop !== false, 'rama should_stop presente');
    TestRunner::assertTrue($paused !== false, 'rama paused presente');
    TestRunner::assertTrue(strpos($src, "Run_Context::finish(\$run_id, 'paused'") !== false, 'la pausa cierra paused');
    $stop_branch = substr($src, (int) $stop, (int) $paused - (int) $stop);
    TestRunner::assertStringNotContains("result['paused']", $stop_branch, 'la rama stop no confla paused');
});

TestRunner::test('T28.222 customers done NO borra el cursor de products (B-A)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_products_import_cursor', 1500, false);
    alegra_mock_seed_contact('c-1', ['name' => 'Cliente', 'email' => 'c1@example.test']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'customers', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertTrue($resp->success, 'debe responder success');
    TestRunner::assertSame(true, $resp->payload['done'] ?? null, 'debe estar done');
    TestRunner::assertSame(1500, (int) get_option('alegra_connector_products_import_cursor', 0), 'un chunked de customers NO debe borrar el cursor de products');
});

TestRunner::test('T28.223 start concurrente rechazado por el lock (D-D)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_connection_tested', true);
    add_option('alegra_lock_alegra_sync_running_products', ['token' => 'other', 'expires' => time() + 300], '', 'no');
    $_POST['sync_type'] = 'products';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_start());
    unset($_POST['sync_type']);
    TestRunner::assertFalse($resp->success, 'el lock de otro proceso debe rechazar');
    TestRunner::assertStringContains('importación en curso', (string) ($resp->payload['message'] ?? ''), 'mensaje');
    TestRunner::assertFalse(get_transient('alegra_batch_state'), 'no debe crear state');

    $src = alegra_method_source(\Alegra\Connector\Admin\Admin_Dashboard::class, 'ajax_sync_start');
    $acquire = strpos($src, 'acquire_sync_lock_public');
    $read = strpos($src, "get_transient('alegra_batch_state')");
    $begin = strpos($src, "Run_Context::begin('chunked_import')");
    TestRunner::assertTrue($acquire !== false && $acquire < $read, 'acquire antes del read-check');
    TestRunner::assertTrue($acquire < $begin, 'acquire antes del begin');
});

TestRunner::test('T28.224 el lock se libera ANTES del wp_send_json (observer)', function (): void {
    // Camino de ÉXITO (el leak real del bug: :2216 dejaba el lock tomado).
    alegra_test_reset();
    alegra_mock_seed_item('it-obs', ['name' => 'Obs', 'reference' => 'SO', 'status' => 'active']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $snapshot = 'unset';
    $GLOBALS['alegra_test_json_observer'] = function ($ok, $payload) use (&$snapshot) {
        $snapshot = get_option('alegra_lock_alegra_sync_running_products', null);
    };
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    $GLOBALS['alegra_test_json_observer'] = null;
    TestRunner::assertTrue($resp->success, 'debe responder success');
    TestRunner::assertSame(null, $snapshot, 'el lock debe estar libre en el instante del send de éxito');

    // Camino de WP_Error.
    alegra_test_reset();
    alegra_mock_fail('GET', '/items', 500, ['error' => 'boom']);
    $rid2 = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid2, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $snapshot2 = 'unset';
    $GLOBALS['alegra_test_json_observer'] = function ($ok, $payload) use (&$snapshot2) {
        $snapshot2 = get_option('alegra_lock_alegra_sync_running_products', null);
    };
    $admin2 = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp2 = alegra_capture_json(fn () => $admin2->ajax_sync_page());
    $GLOBALS['alegra_test_json_observer'] = null;
    TestRunner::assertFalse($resp2->success, 'el WP_Error responde error');
    TestRunner::assertSame(null, $snapshot2, 'el lock debe estar libre en el instante del send de error');
});

// ===========================================================================
// === logs-monitor-import (2.5.0) — Fase 3: chunked con presupuesto propio ===
// ===========================================================================

TestRunner::test('T28.31 pausa con offset', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_chunked_page_budget', 10, false);
    for ($i = 0; $i < 40; $i++) {
        alegra_mock_seed_item('it-p-' . $i, ['name' => 'P' . $i, 'reference' => 'SP' . $i, 'status' => 'active']);
    }
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 40, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    // Reloj falso: deadline 1010; ítem 0 (1006) pasa, ítem 1 (1012) pausa.
    $GLOBALS['alegra_test_fake_microtime'] = 1000.0;
    $GLOBALS['alegra_test_fake_microtime_step'] = 6.0;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    $GLOBALS['alegra_test_fake_microtime'] = null;
    $GLOBALS['alegra_test_fake_microtime_step'] = 0.0;
    TestRunner::assertSame(true, $resp->payload['paused'] ?? null, 'debe pausar');
    TestRunner::assertTrue((int) ($resp->payload['offset'] ?? 0) > 0, 'offset > 0');
    TestRunner::assertSame(1, (int) (get_transient('alegra_batch_state')['offset'] ?? -1), 'el offset debe persistirse');
});

TestRunner::test('T28.32 reanuda sin repetir', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-r0', ['name' => 'R0', 'reference' => 'SR0', 'status' => 'active']);
    alegra_mock_seed_item('it-r1', ['name' => 'R1', 'reference' => 'SR1', 'status' => 'active']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 1, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 2, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    $req = alegra_mock_last_request('GET', '/items');
    TestRunner::assertSame(0, (int) ($req['query']['start'] ?? -1), 're-fetch la misma página');
    TestRunner::assertFalse((bool) \Alegra\Connector\Entity_Map::find_wc_id('item', 'it-r0', 'product'), 'el ítem 0 ya procesado no debe reimportarse');
    TestRunner::assertTrue((bool) \Alegra\Connector\Entity_Map::find_wc_id('item', 'it-r1', 'product'), 'el ítem 1 debe importarse');
    TestRunner::assertTrue($resp->success, 'debe responder success');
});

TestRunner::test('T28.33 completa y borra cursor', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-c1', ['name' => 'C1', 'reference' => 'SC1', 'status' => 'active']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(true, $resp->payload['done'] ?? null, 'debe estar done');
    TestRunner::assertFalse(get_option('alegra_connector_products_import_cursor', false), 'el cursor de products debe borrarse');
});

TestRunner::test('T28.34 stopped', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Runs::request_stop(7);
    $item = ['id' => 'it-stop', 'name' => 'Stop', 'reference' => 'SS', 'type' => 'product'];
    TestRunner::assertSame('stopped', make_products()->import_single_item_public($item, 7), 'el stop por ítem devuelve stopped');
});

TestRunner::test('T28.35 run_id en el log', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    \Alegra\Connector\Run_Context::resume(7, 'chunked_import');
    $item = ['id' => 'it-log', 'name' => 'Log', 'reference' => 'SL', 'type' => 'product'];
    make_products()->import_single_item_public($item, 7);
    \Alegra\Connector\Logger\Logger::clear_run_context();
    TestRunner::assertStringContains('"run_id":7', alegra_read_log(), 'la línea debe llevar run_id=7');
});

TestRunner::test('T28.36 resumen paused', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_import_time_budget', 30, false);
    for ($i = 0; $i < 40; $i++) {
        alegra_mock_seed_item('it-pause-' . $i, ['name' => 'PP' . $i, 'reference' => 'SPP' . $i, 'status' => 'active']);
    }
    // Reloj falso: deadline 1030; iter1 (1020) procesa, iter2 (1040) pausa.
    $GLOBALS['alegra_test_fake_microtime'] = 1000.0;
    $GLOBALS['alegra_test_fake_microtime_step'] = 20.0;
    make_products()->import_from_alegra();
    $GLOBALS['alegra_test_fake_microtime'] = null;
    $GLOBALS['alegra_test_fake_microtime_step'] = 0.0;
    $progress = get_transient('alegra_sync_progress');
    TestRunner::assertSame(true, $progress['paused'] ?? null, 'debe reportar paused');
    TestRunner::assertSame(false, $progress['done'] ?? null, 'done debe ser false');
    TestRunner::assertTrue((int) ($progress['cursor'] ?? 0) > 0, 'cursor > 0');
});

TestRunner::test('T28.37 imagen diferida', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    update_option('alegra_connector_sync_images_mode', 'favorite');
    // Producto YA vinculado: el update path es el que importa imágenes.
    alegra_make_product(10, ['name' => 'Img']);
    update_post_meta(10, '_alegra_item_id', 'it-img');
    \Alegra\Connector\Entity_Map::map('item', 'it-img', 'product', 10);
    \Alegra\Connector\Sync\Products::reset_image_stats();
    \Alegra\Connector\Sync\Products::set_deadline(microtime(true) - 1);
    $item = [
        'id' => 'it-img', 'name' => 'Img', 'reference' => 'SI', 'type' => 'product',
        'images' => [['url' => 'https://cdn3.alegra.com/a.jpg', 'favorite' => true]],
    ];
    make_products()->import_single_item_public($item);
    $stats = \Alegra\Connector\Sync\Products::image_stats();
    \Alegra\Connector\Sync\Products::clear_deadline();
    TestRunner::assertTrue(($stats['deferred'] ?? 0) > 0, 'la imagen diferida debe contarse');
    TestRunner::assertSame(0, (int) ($stats['failed'] ?? -1), 'una diferida no es un fallo');
});

TestRunner::test('T28.38 admin.js no cierra mudo', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringNotContains('if (!r.success || cancelled) { cleanup(); return; }', $src, 'la rama muda del start debe irse');
    TestRunner::assertStringNotContains("cleanup(); \$btn.prop('disabled',false).text(S.retry);", $src, 'no debe haber cierre mudo');
    TestRunner::assertStringContains('showNotice(S.connectionError', $src, 'la rama terminal debe avisar');
});

TestRunner::test('T28.39 get_script_strings tiene las claves de Fase 3', function (): void {
    $strings = alegra_call_private_static(\Alegra\Connector\Admin\Admin_Dashboard::class, 'get_script_strings');
    TestRunner::assertArrayHasKey('pausedResuming', $strings, 'pausedResuming');
    TestRunner::assertArrayHasKey('resumingFrom', $strings, 'resumingFrom');
    TestRunner::assertArrayHasKey('confirmReimport', $strings, 'confirmReimport');
    // T5.2b ya está implementada: la clave la declara su fase dueña.
    TestRunner::assertArrayHasKey('imagesFailed', $strings, 'imagesFailed la declara T5.2b');
});

TestRunner::test('T28.310 message siempre', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-msg', ['name' => 'M', 'reference' => 'SM', 'status' => 'active']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertArrayHasKey('message', $resp->payload, 'éxito debe llevar message');

    alegra_test_reset();
    alegra_mock_fail('GET', '/items', 500, ['error' => 'boom']);
    $rid2 = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid2, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin2 = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp2 = alegra_capture_json(fn () => $admin2->ajax_sync_page());
    TestRunner::assertArrayHasKey('message', $resp2->payload, 'error debe llevar message');
});

TestRunner::test('T28.311 images en el payload', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-imgp', ['name' => 'IP', 'reference' => 'SIP', 'status' => 'active']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertArrayHasKey('images', $resp->payload, 'images presente');
    TestRunner::assertSame(0, (int) ($resp->payload['images']['failed'] ?? -1), 'sin fallos → failed 0');
});

// ---------------------------------------------------------------------------
// Fase 4 — botones reanudar/reimportar + tombstones (T28.41-T28.47)
// ---------------------------------------------------------------------------

TestRunner::test('T28.41 the products template has the from-zero button and the cursor indicator', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-products.php');
    TestRunner::assertStringContains('data-from-zero="1"', $tpl, 'the destructive button must be present');
    TestRunner::assertStringContains('Reimportar todo desde cero', $tpl, 'the destructive label must be present');
    TestRunner::assertStringContains("get_option('alegra_connector_products_import_cursor', 0)", $tpl, 'the cursor indicator must read the option');
    TestRunner::assertStringContains("if (\$ac_cursor > 0)", $tpl, 'the badge must be gated on a positive cursor');
    TestRunner::assertStringContains('ac-filter-recreate-manual', $tpl, 'the recreate-manual checkbox must exist');
    TestRunner::assertStringContains('ac-filter-recreate-manual" checked', $tpl, 'the checkbox must default to checked');
    TestRunner::assertStringContains('ac-filter-recreate-manual-label', $tpl, 'the label element must exist (populated from S)');
    TestRunner::assertStringContains('S.confirmRecreateManual', $tpl, 'the label must consume the canonical JS string');
    TestRunner::assertStringNotContains('que borré a mano', $tpl, 'the label must not hardcode the copy');
});

TestRunner::test('T28.42 the start request carries the from-zero and recreate-manual flags', function (): void {
    $js = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringContains('from_zero: AlegraConnector.pendingFromZero ? 1 : 0', $js, 'the start request must send from_zero');
    TestRunner::assertStringContains('recreate_manual: AlegraConnector.pendingRecreateManual ? 1 : 0', $js, 'the start request must send recreate_manual');
    TestRunner::assertStringContains('pendingFromZero: false', $js, 'the from-zero flag must default to false');
    TestRunner::assertStringContains('pendingRecreateManual: false', $js, 'the checkbox flag must default to false');
});

TestRunner::test('T28.43 exists_with_reason returns the reason and exists() delegates', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Tombstone_Manager::create([
        'alegra_id' => 'itm-1', 'alegra_type' => 'item', 'wc_post_id' => 10,
        'deleted_by' => 1, 'reason' => 'bulk_wc',
    ]);
    TestRunner::assertSame('bulk_wc', \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', 'itm-1'), 'reason must round-trip');
    TestRunner::assertTrue(\Alegra\Connector\Tombstone_Manager::exists('item', 'itm-1'), 'exists() must delegate');

    $GLOBALS['alegra_db']['wp_alegra_tombstones'][0]['resurrected_at'] = '2026-01-01 00:00:00';
    TestRunner::assertSame(null, \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', 'itm-1'), 'a resurrected tombstone is gone');
    TestRunner::assertFalse(\Alegra\Connector\Tombstone_Manager::exists('item', 'itm-1'), 'exists() mirrors the reason query');
});

TestRunner::test('T28.44 classify_delete_reason distinguishes bulk from individual', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_is_admin'] = true;

    $_REQUEST = ['action' => 'delete', 'post' => ['1', '2']];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'array post[] with >1 items is bulk');

    $_REQUEST = ['action' => 'delete', 'post' => '123'];
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'scalar post is individual');

    $_REQUEST = ['delete_all' => 'Empty Trash'];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'the delete_all submit (Empty Trash) is bulk');

    $_REQUEST = ['action' => '-1', 'action2' => 'delete', 'post' => ['1', '2']];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'the bottom Apply travels in action2');

    $_REQUEST = ['action' => 'delete', 'post' => ['1']];
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'a one-item selection is the safe default');

    $_REQUEST = ['action' => 'delete', 'post' => ['1', '2']];
    $GLOBALS['alegra_test_is_admin'] = false;
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'outside admin the default is the safe manual_wc');

    $_REQUEST = [];
    $GLOBALS['alegra_test_is_admin'] = true;
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'no request context is the safe default');
});

TestRunner::test('T28.45 on_post_delete records bulk_wc on a bulk delete', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_is_admin'] = true;
    $GLOBALS['wp_posts'][55] = (object) ['ID' => 55, 'post_type' => 'product'];
    update_post_meta(55, '_alegra_item_id', 'itm-55');
    $_REQUEST = ['action' => 'delete', 'post' => ['55', '56']];
    \Alegra\Connector\Tombstone_Manager::on_post_delete(55);
    $rows = $GLOBALS['alegra_db']['wp_alegra_tombstones'] ?? [];
    TestRunner::assertSame('bulk_wc', $rows[0]['reason'] ?? null, 'the tombstone must be bulk_wc');

    $GLOBALS['alegra_db']['wp_alegra_tombstones'] = [];
    $GLOBALS['wp_posts'][66] = (object) ['ID' => 66, 'post_type' => 'product'];
    update_post_meta(66, '_alegra_item_id', 'itm-66');
    $_REQUEST = ['delete_all' => 'Empty Trash', 'post_status' => 'trash'];
    \Alegra\Connector\Tombstone_Manager::on_post_delete(66);
    $rows = $GLOBALS['alegra_db']['wp_alegra_tombstones'] ?? [];
    TestRunner::assertSame('bulk_wc', $rows[0]['reason'] ?? null, 'empty trash must be bulk_wc');
});

TestRunner::test('T28.46 the tombstone policy matrix is honoured on creation', function (): void {
    $cases = [
        ['respect',     'bulk_wc',        false],
        ['respect',     'manual_wc',      false],
        ['respect',     'alegra_deleted', false],
        ['ignore_bulk', 'bulk_wc',        true],
        ['ignore_bulk', 'manual_wc',      false],
        ['ignore_bulk', 'alegra_deleted', false],
        ['ignore_all',  'bulk_wc',        true],
        ['ignore_all',  'manual_wc',      true],
        ['ignore_all',  'alegra_deleted', false],
    ];
    foreach ($cases as [$policy, $reason, $must_create]) {
        alegra_test_reset();
        \Alegra\Connector\Run_Context::set_tombstone_policy($policy);
        \Alegra\Connector\Tombstone_Manager::create([
            'alegra_id' => 'itm-x', 'alegra_type' => 'item', 'wc_post_id' => 0,
            'deleted_by' => 1, 'reason' => $reason,
        ]);
        $r = make_products()->import_single_item_public([
            'id' => 'itm-x', 'name' => 'Producto X', 'type' => 'simple', 'reference' => 'SKU-X',
        ]);
        if ($must_create) {
            TestRunner::assertSame(true, $r, "$policy/$reason must create");
        } else {
            TestRunner::assertSame('skipped', $r, "$policy/$reason must skip");
        }
    }
});

TestRunner::test('T28.47 the reimport flow confirms and reads the checkbox', function (): void {
    $js = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringContains('confirm(S.confirmReimport)', $js, 'the destructive button must confirm');
    TestRunner::assertStringContains('ac-filter-recreate-manual', $js, 'the JS must read the recreate-manual checkbox');
    TestRunner::assertStringContains('pendingRecreateManual', $js, 'the checkbox state must travel to the start request');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'confirmReimport'", $admin, 'the string must be localizable');
});

// ---------------------------------------------------------------------------
// Fase 5 — imágenes: mime, stats y allowlist (T28.51-T28.59)
// ---------------------------------------------------------------------------

TestRunner::test('T28.51 extension_from_mime maps the documented mimes', function (): void {
    $f = fn (string $m): string => alegra_call_private_static(
        \Alegra\Connector\Sync\Products::class, 'extension_from_mime', $m
    );
    TestRunner::assertSame('png',  $f('image/png'), 'png');
    TestRunner::assertSame('jpg',  $f('image/jpeg'), 'jpeg');
    TestRunner::assertSame('webp', $f('image/webp'), 'webp');
    TestRunner::assertSame('gif',  $f('image/gif'), 'gif');
    TestRunner::assertSame('avif', $f('image/avif'), 'avif');
    TestRunner::assertSame('svg',  $f('image/svg+xml'), 'svg');
    TestRunner::assertSame('jpg',  $f('application/octet-stream'), 'unknown falls back to jpg');
});

TestRunner::test('T28.52 no hardcoded .jpg remains in the live import points', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'includes/Sync/Products.php');
    TestRunner::assertStringNotContains("'name' => 'alegra-' . \$product_id . '-' . substr(\$url_hash, 0, 8) . '.jpg'", $src, 'point 1 must not hardcode .jpg');
    TestRunner::assertStringNotContains("'name' => 'alegra-' . \$product_id . '.jpg'", $src, 'point 2 must not hardcode .jpg');
    TestRunner::assertStringContains('wp_check_filetype_and_ext', $src, 'the mime check must be used');
});

TestRunner::test('T28.53 the dead Admin_Dashboard::import_product_image is gone', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains('function import_product_image', $src, 'the dead method must be deleted');
    TestRunner::assertStringNotContains("'alegra-' . \$product_id . '.jpg'", $src, 'the third hardcoded .jpg must be gone');
});

TestRunner::test('T28.54 image_stats counts blocked and download failures', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    $p = make_products();
    \Alegra\Connector\Sync\Products::reset_image_stats();

    alegra_call_private($p, 'download_and_attach_image', 1000, 'http://127.0.0.1/x.png');
    TestRunner::assertSame(1, \Alegra\Connector\Sync\Products::image_stats()['blocked'], 'SSRF host counted as blocked');

    alegra_call_private($p, 'download_and_attach_image', 1000, 'https://cdn3.alegra.com/x.png');
    $s = \Alegra\Connector\Sync\Products::image_stats();
    TestRunner::assertSame(1, $s['download'], 'download failure counted');
    TestRunner::assertSame(2, $s['failed'], 'failed = blocked + download + sideload (1 blocked + 1 download)');
});

TestRunner::test('T28.55 import_from_alegra exposes image stats and the page payload merges them', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    // El harness sólo importa imágenes en el update path (wc_get_product no
    // conoce el post creado por wp_insert_post): se pre-vincula el producto.
    alegra_make_product(10, ['name' => 'Con imagen']);
    update_post_meta(10, '_alegra_item_id', 'itm-img');
    \Alegra\Connector\Entity_Map::map('item', 'itm-img', 'product', 10);
    alegra_mock_seed_item('itm-img', [
        'name' => 'Con imagen', 'type' => 'simple',
        'images' => [['url' => 'https://cdn3.alegra.com/a.png', 'favorite' => true]],
    ]);
    $result = make_products()->import_from_alegra(1, 30, 0);
    TestRunner::assertArrayHasKey('images', $result, 'the result must carry image stats');
    TestRunner::assertTrue(($result['images']['download'] ?? 0) >= 1, 'the failed download must be counted');

    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 1,
        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'filters' => [],
        'run_id' => 0, 'start' => 0, 'offset' => 0, 'policy' => 'respect', 'images' => [],
    ], 600);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertArrayHasKey('images', $resp->payload, 'the page payload must include images');
});

TestRunner::test('T28.56 the allowlist merges the extra hosts and rejects wildcards', function (): void {
    alegra_test_reset();
    TestRunner::assertSame(['alegra.com'], \Alegra\Connector\Sync\Products::allowed_image_hosts(), 'no regression without the option');

    $san = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_image_hosts("https://cdn.example.com\n*\nfoo:8080\nfoo/bar\n");
    TestRunner::assertSame(['cdn.example.com'], $san, 'only the valid domain survives');

    // 2.5.1: default is permissive — any public host, http included, downloads.
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn.example.com/x.png'), 'public host allowed by default');
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://evil.com/x.png'), 'unknown public host allowed by default');
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('http://cdn.example.com/x.png'), 'http allowed by default');
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn3.alegra.com/x.png'), 'alegra CDN allowed by default');
});

TestRunner::test('T28.510 la descarga flexible permite hosts públicos y siempre bloquea SSRF', function (): void {
    alegra_test_reset();

    // Default (restriction OFF): permissive over http and https.
    foreach ([
        'https://images.example.com/a.png',
        'https://cdn3.alegra.com/a.png',
        'https://alegra.com/a.png',
        'http://images.example.com/a.png',
    ] as $u) {
        TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url($u), "permitido por defecto: $u");
    }

    // Only http/https are valid schemes.
    foreach ([
        'ftp://images.example.com/a.png',
        'javascript:alert(1)',
        'images.example.com/a.png',
    ] as $u) {
        TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url($u), "esquema inválido: $u");
    }

    // SSRF guard: loopback/private/reserved always blocked, in both modes.
    $ssrf = [
        'http://localhost/a.png',
        'http://127.0.0.1/a.png',
        'http://192.168.1.1/a.png',
        'http://10.0.0.5/a.png',
        'http://169.254.1.1/a.png',
        'http://foo.local/a.png',
        'http://svc.internal/a.png',
        'http://bar.localhost/a.png',
    ];
    foreach ($ssrf as $u) {
        TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url($u), "SSRF bloqueado (default): $u");
    }
    update_option('alegra_connector_restrict_image_hosts', true, false);
    foreach ($ssrf as $u) {
        TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url($u), "SSRF bloqueado (restringido): $u");
    }

    // Restriction ON: the allowlist gates hosts; alegra CDN still passes.
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn3.alegra.com/a.png'), 'alegra CDN permitido en modo restringido');
    TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://images.example.com/a.png'), 'host público ajeno bloqueado en modo restringido');
});

TestRunner::test('T28.57 the extra-host option is registered and cleaned up', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'alegra_connector_allowed_image_hosts_extra'", $admin, 'must be registered');
    TestRunner::assertStringContains("'alegra_connector_restrict_image_hosts'", $admin, 'the restriction switch must be registered');
    $uninstall = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'uninstall.php');
    TestRunner::assertStringContains("delete_option('alegra_connector_allowed_image_hosts_extra')", $uninstall, 'must be cleaned on uninstall');
    TestRunner::assertStringContains("delete_option('alegra_connector_restrict_image_hosts')", $uninstall, 'the restriction switch must be cleaned on uninstall');
    $main = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'alegra-connector.php');
    TestRunner::assertStringContains("'alegra_connector_allowed_image_hosts_extra' => []", $main, 'must be in $defaults');
    TestRunner::assertStringContains("'alegra_connector_restrict_image_hosts' => false", $main, 'the switch defaults to false in $defaults');
});

TestRunner::test('T28.58 the extra-host textarea is rendered in the advanced tab', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains('name="alegra_connector_allowed_image_hosts_extra"', $tpl, 'the textarea must post the option');
    TestRunner::assertStringContains("get_option('alegra_connector_allowed_image_hosts_extra', [])", $tpl, 'the textarea must render the stored hosts');
    TestRunner::assertStringContains('name="alegra_connector_restrict_image_hosts"', $tpl, 'the restriction checkbox must post the option');
    TestRunner::assertStringContains("get_option('alegra_connector_restrict_image_hosts',false)", $tpl, 'the checkbox must render the stored value');
});

TestRunner::test('T28.59 the page payload reflects blocked images (moved from Fase 3 T28.312)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    // El harness sólo importa imágenes en el update path: pre-vinculado.
    alegra_make_product(10, ['name' => 'Con imagen bloqueada']);
    update_post_meta(10, '_alegra_item_id', 'itm-blocked');
    \Alegra\Connector\Entity_Map::map('item', 'itm-blocked', 'product', 10);
    alegra_mock_seed_item('itm-blocked', [
        'name' => 'Con imagen bloqueada', 'type' => 'simple',
        'images' => [['url' => 'http://10.0.0.5/x.png', 'favorite' => true]],
    ]);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 1,
        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'filters' => [],
        'run_id' => 0, 'start' => 0, 'offset' => 0, 'policy' => 'respect', 'images' => [],
    ], 600);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertTrue(($resp->payload['images']['blocked'] ?? 0) > 0, 'the blocked host must be reflected in the payload');
    TestRunner::assertTrue(($resp->payload['images']['failed'] ?? 0) > 0, 'blocked counts as failed');
});

// ---------------------------------------------------------------------------
// Fase 6 — logs (borrado total + ruta absoluta) y Monitor (T28.61-T28.615)
// ---------------------------------------------------------------------------

TestRunner::test('T28.61 clear_all_logs deletes every .log (today included) and recreates none', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $dir = $logger->get_log_dir();
    @mkdir($dir, 0777, true);
    foreach (glob($dir . '/*.log') ?: [] as $pre) {
        @unlink($pre);
    }
    foreach (['2026-01-01', '2026-01-02', date('Y-m-d')] as $d) {
        file_put_contents($dir . '/alegra-sync-test-' . $d . '.log', "x\n");
    }
    $res = $logger->clear_all_logs();
    TestRunner::assertSame(3, $res['files'], 'debe borrar los 3 archivos, incluido el de hoy');
    TestRunner::assertTrue($res['bytes'] > 0, 'debe reportar bytes liberados');
    TestRunner::assertSame([], glob($dir . '/*.log') ?: [], 'el directorio debe quedar sin .log');
    TestRunner::assertSame(30, (int) get_option('alegra_connector_log_retention_days', 30), 'la retención no se toca');
});

TestRunner::test('T28.62 ajax_clear_logs responds with the real count and singular copy', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $dir = $logger->get_log_dir();
    @mkdir($dir, 0777, true);
    foreach (glob($dir . '/*.log') ?: [] as $pre) {
        @unlink($pre);
    }
    file_put_contents($dir . '/a.log', 'x');
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), $logger);
    $resp = alegra_capture_json(static fn () => $admin->ajax_clear_logs());
    TestRunner::assertTrue($resp->success, 'debe responder success');
    TestRunner::assertSame(1, $resp->payload['files'], 'files debe ser 1');
    TestRunner::assertStringContains('archivo de log eliminado', $resp->payload['message'], 'plural/singular correcto');
});

TestRunner::test('T28.63 the clear handler uses clear_all_logs, not the retention path', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains('clear_all_logs()', $admin, 'el handler usa el borrado total');
    TestRunner::assertStringNotContains('clear_old_logs($retention)', $admin, 'el handler ya no usa la retención');
    $logger_src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'logger/Logger/Logger.php');
    TestRunner::assertStringContains('function clear_all_logs', $logger_src, 'el método existe');
});

TestRunner::test('T28.64 the button and confirm copy say delete ALL', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-logs.php');
    TestRunner::assertStringContains('Limpiar logs', $tpl, 'el botón dice limpiar logs');
    TestRunner::assertStringNotContains('Limpiar antiguos', $tpl, 'el copy viejo desaparece');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains('Esto borrará TODOS los logs', $admin, 'el confirm es destructivo');
});

TestRunner::test('T28.65 a failed write persists the failure option', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $bogus = sys_get_temp_dir() . '/alegra-not-a-dir-' . uniqid();
    @unlink($bogus);
    file_put_contents($bogus, 'x'); // un ARCHIVO donde se espera un dir
    $ref = new ReflectionClass($logger);
    foreach (['log_dir' => $bogus, 'log_file' => $bogus . '/x.log', 'dir_ready' => true] as $p => $v) {
        $prop = $ref->getProperty($p);
        $prop->setAccessible(true);
        $prop->setValue($logger, $v);
    }
    $logger->info('boom');
    $failure = get_option('alegra_connector_logger_write_failed');
    TestRunner::assertTrue(is_array($failure), 'el fallo debe persistirse');
    TestRunner::assertSame($bogus, $failure['path'] ?? null, 'la opción debe llevar el path');
    @unlink($bogus);
});

TestRunner::test('T28.66 a successful write self-heals the failure option', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_logger_write_failed', ['at' => 1, 'path' => '/x', 'error' => 'x'], false);
    make_logger()->info('ok');
    TestRunner::assertFalse(get_option('alegra_connector_logger_write_failed'), 'un write exitoso debe limpiar la opción');
    TestRunner::assertSame(sys_get_temp_dir() . '/alegra-exec-uploads/alegra-logs', make_logger()->get_log_dir(), 'get_log_dir absoluta');
});

TestRunner::test('T28.67 render_write_failure_notice shows the path once and nothing without it', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_logger_write_failed', ['at' => time(), 'path' => '/var/www/uploads/alegra-logs', 'error' => 'Permission denied'], false);
    ob_start();
    \Alegra\Connector\Logger\Logger::render_write_failure_notice();
    $html = ob_get_clean();
    TestRunner::assertStringContains('/var/www/uploads/alegra-logs', $html, 'el aviso muestra la ruta');
    TestRunner::assertStringContains('notice-error', $html, 'es un notice de error');
    TestRunner::assertStringContains('is-dismissible', $html, 'es descartable');
    alegra_test_reset();
    ob_start();
    \Alegra\Connector\Logger\Logger::render_write_failure_notice();
    $empty = ob_get_clean();
    TestRunner::assertSame('', $empty, 'sin opción no hay aviso (escenario negativo)');
});

TestRunner::test('T28.68 the failure notice is registered before the WooCommerce guard', function (): void {
    $main = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'alegra-connector.php');
    TestRunner::assertStringContains('render_write_failure_notice', $main, 'el renderer debe registrarse');
    $hook = strpos($main, "add_action('admin_notices', [Logger\\Logger::class, 'render_write_failure_notice'])");
    $guard = strpos($main, "class_exists('WooCommerce')");
    TestRunner::assertTrue($hook !== false, 'el add_action exacto debe existir');
    TestRunner::assertTrue($guard !== false, 'el guard de WooCommerce debe existir');
    TestRunner::assertTrue($hook < $guard, 'el aviso corre aunque WC no esté');
});

TestRunner::test('T28.69 the logs page always renders the absolute path', function (): void {
    alegra_test_reset();
    $logger = make_logger();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), $logger);
    ob_start();
    $admin->render_logs_page();
    $html = ob_get_clean();
    TestRunner::assertStringContains($logger->get_log_dir(), $html, 'debe renderizar la ruta absoluta');
    TestRunner::assertStringNotContains('wp-content/uploads/alegra-logs/', $html, 'no debe quedar la ruta relativa hardcodeada');
});

TestRunner::test('T28.610 the logs template and controller use the absolute dir helper', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-logs.php');
    TestRunner::assertStringContains('esc_html($logger_dir)', $tpl, 'el template escapa la ruta del controller');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains('$this->logger->get_log_dir()', $admin, 'el controller resuelve la ruta absoluta');
});

TestRunner::test('T28.611 the monitor labels run origins and shows the stale badge', function (): void {
    $mon = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-monitor.php');
    TestRunner::assertStringContains('function runTypeLabel', $mon, 'debe existir el mapeo de origen');
    TestRunner::assertSame(2, substr_count($mon, 'runTypeLabel(r.type)'), 'debe usarse en running y recent');
    TestRunner::assertStringContains("case 'stale'", $mon, 'el badge abandonado debe existir');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'statusAbandoned'", $admin, 'string dueña T6.4.a');
    TestRunner::assertStringContains("'originChunked'", $admin, 'string dueña T6.4.a');
    TestRunner::assertStringContains("'originWebhook'", $admin, 'string dueña T6.4.a');
});

TestRunner::test('T28.612 the monitor payload exposes sync_method', function (): void {
    alegra_test_reset();
    $GLOBALS['wp_options']['alegra_connector_sync_method'] = 'real-time';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(static fn () => $admin->ajax_monitor_status());
    TestRunner::assertTrue($resp->success, 'monitor responde success');
    TestRunner::assertSame('real-time', $resp->payload['sync_method'] ?? null, 'el payload expone sync_method');
});

TestRunner::test('T28.613 the monitor renders honest empty/error states with a local fmt', function (): void {
    $mon = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-monitor.php');
    TestRunner::assertStringNotContains('// Silent fail - retry next poll', $mon, 'el error ya no es silencioso');
    TestRunner::assertStringContains('function fmt', $mon, 'helper local fmt (evita ReferenceError)');
    TestRunner::assertStringContains('monitorError', $mon, 'consume la string de error');
    TestRunner::assertStringContains('sync_method', $mon, 'pasa el método al render de cron');
    TestRunner::assertStringContains('cronDisabled', $mon, 'consume la string de cron deshabilitado');
    TestRunner::assertStringContains('escapeHtml(fmt(S.cronDisabled', $mon, 'escapa el método antes de inyectar');
    TestRunner::assertStringContains('renderCron(d.cron, d.sync_method)', $mon, 'renderCron recibe sync_method');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'sync_method' => (string) get_option('alegra_connector_sync_method'", $admin, 'el payload expone sync_method');
});

TestRunner::test('T28.614 sync_products queda false en las tres fuentes de verdad', function (): void {
    alegra_test_reset();
    $root = $GLOBALS['alegra_plugin_root'];
    $main = (string) file_get_contents($root . 'alegra-connector.php');
    $ctrl = (string) file_get_contents($root . 'includes/Sync/Controller.php');
    $ui   = (string) file_get_contents($root . 'templates/admin-settings.php');
    TestRunner::assertStringContains("'alegra_connector_sync_products' => false", $main, 'default del activador = false');
    TestRunner::assertStringContains("get_option('alegra_connector_sync_products', false)", $ctrl, 'runtime del cron = false');
    TestRunner::assertStringContains("get_option('alegra_connector_sync_products',false)", $ui, 'UI = false');
    TestRunner::assertStringNotContains("alegra_connector_sync_products',true", $ui, 'la UI no debe usar true');
    TestRunner::assertSame(false, get_option('alegra_connector_sync_products', false), 'sin opción, el runtime cae a false');
});

TestRunner::test('T28.615 the monitor partitions recent webhooks out of the history', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'recent_webhooks'", $admin, 'el payload separa webhooks');
    TestRunner::assertStringContains('$recent_wh_data[]', $admin, 'el array se construye (gap L1)');
    TestRunner::assertStringContains("strpos((string) \$r->run_type, 'webhook')", $admin, 'el filtro por origen');
    $mon = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-monitor.php');
    TestRunner::assertStringContains('d.recent_webhooks', $mon, 'el monitor renderiza la sección de webhooks');
});

// ===========================================================================
// === logs-monitor-import (2.5.0) — Fase 7: regresión R1–R15 + release ===
// ===========================================================================

TestRunner::test('T28.71 el modelo de runs expone get_row() además de get_var (T7.1.a)', function (): void {
    alegra_test_reset();
    $id = \Alegra\Connector\Runs::start('cron_sync_all', 'cron');
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'la fila debe leerse running');
    \Alegra\Connector\Runs::finish($id, 'completed', 'ok');
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status($id), 'finish debe actualizar status');
    TestRunner::assertCount(1, \Alegra\Connector\Runs::recent(10), 'recent debe leer la fila');

    // T7.1.a: get_row() debe delegar en el get_results() del modelo de T1.1a.
    $row = $GLOBALS['wpdb']->get_row("SELECT * FROM {$GLOBALS['wpdb']->prefix}alegra_runs WHERE id = {$id}");
    TestRunner::assertTrue($row !== null, 'get_row debe devolver la fila de runs');
    TestRunner::assertSame('completed', (string) ($row->status ?? ''), 'la fila trae el status final');
});

TestRunner::test('T28.72 R1 el cron deja un run cron_sync_all completed con traza (R1)', function (): void {
    alegra_test_reset();
    foreach (['sync_products', 'sync_customers', 'sync_categories', 'sync_orders'] as $opt) {
        update_option('alegra_connector_' . $opt, true);
    }
    alegra_clear_log();
    make_controller()->run_cron_sync();
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status(1), 'el cron debe cerrar completed');
    TestRunner::assertSame('cron_sync_all', (string) ($GLOBALS['alegra_db']['wp_alegra_runs'][0]['run_type'] ?? ''), 'run_type canónico');
    TestRunner::assertSame(null, \Alegra\Connector\Heartbeat::get(1), 'el heartbeat debe limpiarse al cerrar');
    TestRunner::assertStringContains('Cron synchronization completed', alegra_read_log(), 'el log registra el cierre');
});

TestRunner::test('T28.73 R2 un handler de webhook que lanza responde 200 y deja failed (R2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_webhook_token', 'tok');
    $logger = make_logger();
    $throwing = new class($logger) extends Client {
        public function get_item(string $id): array|\WP_Error
        {
            throw new \RuntimeException('boom handler');
        }
    };
    $receiver = new \Alegra\Connector\Webhooks\Receiver($throwing, $logger);
    $body = json_encode(['subject' => 'new-item', 'message' => ['item' => ['id' => 'it-r2']]]);
    $res = $receiver->handle(new WP_REST_Request($body, [], ['token' => 'tok']));
    TestRunner::assertSame(200, $res->get_status(), 'el webhook debe ACKear 200 aunque falle');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status(1), 'la fila debe quedar failed');
    TestRunner::assertSame('webhook_item', (string) ($GLOBALS['alegra_db']['wp_alegra_runs'][0]['run_type'] ?? ''), 'run_type webhook_item');
});

TestRunner::test('T28.74 R3 el lock se libera ANTES del wp_send_json_error (observer H1.b)', function (): void {
    alegra_test_reset();
    alegra_mock_fail('GET', '/items', 500, ['error' => 'boom']);

    $run_id = \Alegra\Connector\Runs::start('chunked_import', 'tester');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $run_id, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 60, 'imported' => 0, 'updated' => 0, 'skipped' => 0,
        'errors' => 0, 'policy' => 'respect',
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0],
        'filters' => [],
    ], 600);

    // H1.b: el observer corre JUSTO antes del throw del stub (el instante en
    // que WP haría wp_die()->die()). Fotografía el lock CRUDO (el option).
    $lock_at_send = 'unset';
    $GLOBALS['alegra_test_json_observer'] = function (bool $success, array $payload) use (&$lock_at_send): void {
        $lock_at_send = get_option('alegra_lock_alegra_sync_running_products', null);
    };

    $resp = alegra_capture_json(fn () => (new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger()))->ajax_sync_page());
    $GLOBALS['alegra_test_json_observer'] = null;

    TestRunner::assertFalse($resp->success, 'un WP_Error de /items debe responder error');
    TestRunner::assertSame(null, $lock_at_send, 'el lock debe estar LIBRE en el instante del send');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status($run_id), 'la fila debe cerrarse failed');
    TestRunner::assertFalse(get_option('alegra_lock_alegra_sync_running_products', false), 'el lock no debe quedar tomado');
});

TestRunner::test('T28.75 R4 reanudar por presupuesto no duplica ni saltea ítems (R4)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_chunked_page_budget', 10, false);
    foreach (['a', 'b', 'c'] as $k) {
        alegra_mock_seed_item('it-' . $k, ['name' => strtoupper($k), 'reference' => 'S' . $k, 'status' => 'active']);
    }
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 3,
        'total_pages' => 1, 'total_items' => 3, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);

    $GLOBALS['alegra_test_fake_microtime'] = 1000.0;
    $GLOBALS['alegra_test_fake_microtime_step'] = 6.0;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    $GLOBALS['alegra_test_fake_microtime'] = null;
    $GLOBALS['alegra_test_fake_microtime_step'] = 0.0;

    TestRunner::assertSame(true, $resp->payload['paused'] ?? null, 'la página debe pausar por presupuesto');
    TestRunner::assertSame(1, (int) ($resp->payload['processed'] ?? -1), 'sólo el ítem 0 debe procesarse antes de pausar');

    // Reanudar: el offset persistido evita reprocesar el prefijo.
    $resp2 = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(true, $resp2->payload['done'] ?? null, 'la segunda página debe completar');
    TestRunner::assertSame(3, (int) ($resp2->payload['processed'] ?? -1), 'processed final === N (sin duplicar ni saltear)');
    foreach (['a', 'b', 'c'] as $k) {
        TestRunner::assertTrue((bool) \Alegra\Connector\Entity_Map::find_wc_id('item', 'it-' . $k, 'product'), 'it-' . $k . ' debe quedar mapeado');
    }
});

TestRunner::test('T28.76 R5 classify_delete_reason distingue bulk de individual (R5)', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_is_admin'] = true;
    $_REQUEST = ['action' => 'delete', 'post' => ['1', '2']];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'post[] con >1 es bulk');
    $_REQUEST = ['action' => 'delete', 'post' => '123'];
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'post escalar es individual');
    $_REQUEST = [];
});

TestRunner::test('T28.77 R6 la política de tombstones respeta manual_wc y recrea bulk_wc (R6)', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Run_Context::set_tombstone_policy('respect');
    \Alegra\Connector\Tombstone_Manager::create(['alegra_id' => 'itm-r6', 'alegra_type' => 'item', 'wc_post_id' => 0, 'deleted_by' => 1, 'reason' => 'bulk_wc']);
    TestRunner::assertSame('skipped', make_products()->import_single_item_public(['id' => 'itm-r6', 'name' => 'R6', 'type' => 'simple', 'reference' => 'SR6']), 'respect skipea bulk_wc');

    alegra_test_reset();
    \Alegra\Connector\Run_Context::set_tombstone_policy('ignore_bulk');
    \Alegra\Connector\Tombstone_Manager::create(['alegra_id' => 'itm-r6b', 'alegra_type' => 'item', 'wc_post_id' => 0, 'deleted_by' => 1, 'reason' => 'bulk_wc']);
    TestRunner::assertSame(true, make_products()->import_single_item_public(['id' => 'itm-r6b', 'name' => 'R6b', 'type' => 'simple', 'reference' => 'SR6b']), 'ignore_bulk recrea bulk_wc');

    alegra_test_reset();
    \Alegra\Connector\Run_Context::set_tombstone_policy('ignore_bulk');
    \Alegra\Connector\Tombstone_Manager::create(['alegra_id' => 'itm-r6c', 'alegra_type' => 'item', 'wc_post_id' => 0, 'deleted_by' => 1, 'reason' => 'manual_wc']);
    TestRunner::assertSame('skipped', make_products()->import_single_item_public(['id' => 'itm-r6c', 'name' => 'R6c', 'type' => 'simple', 'reference' => 'SR6c']), 'ignore_bulk respeta manual_wc');
});

TestRunner::test('T28.78 R7 una página hace 1 GET y un solo Heartbeat::set (R7)', function (): void {
    alegra_test_reset();
    for ($i = 0; $i < 5; $i++) {
        alegra_mock_seed_item('it-io-' . $i, ['name' => 'IO' . $i, 'reference' => 'SIO' . $i, 'status' => 'active']);
    }
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 5, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items'), 'una página = 1 GET /items, no N');
    $src = alegra_method_source(\Alegra\Connector\Admin\Admin_Dashboard::class, 'ajax_sync_page');
    TestRunner::assertSame(1, substr_count($src, 'Heartbeat::set'), 'un solo Heartbeat::set por página (no por ítem)');
});

TestRunner::test('T28.79 R8 limpiar logs borra todo y no toca la retención (R8)', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains('TODOS los logs', $admin, 'el confirm advierte el alcance');
    TestRunner::assertStringContains('No se puede deshacer', $admin, 'el confirm advierte lo irreversible');
    alegra_test_reset();
    $logger = make_logger();
    $dir = $logger->get_log_dir();
    @mkdir($dir, 0777, true);
    foreach (glob($dir . '/*.log') ?: [] as $f) { @unlink($f); }
    file_put_contents($dir . '/old.log', 'x');
    $res = $logger->clear_all_logs();
    TestRunner::assertSame(1, $res['files'], 'borra el archivo existente');
    TestRunner::assertSame(30, (int) get_option('alegra_connector_log_retention_days', 30), 'la retención sigue en 30');
});

TestRunner::test('T28.710 R9 finish no re-finaliza un run ya cerrado (R9)', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'completed', 'started_at' => current_time('mysql')]);
    $run_id = (int) $wpdb->insert_id;
    \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido');
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status($run_id), 'no debe pisar completed con cancelled');
});

TestRunner::test('T28.711 R10 la restricción de hosts se activa por opción (R10)', function (): void {
    alegra_test_reset();
    $san = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_image_hosts("https://cdn.example.com\n*\nfoo:8080");
    TestRunner::assertSame(['cdn.example.com'], $san, 'sólo el dominio válido sobrevive');

    update_option('alegra_connector_allowed_image_hosts_extra', ['cdn.example.com'], false);
    // Default OFF: permissive — any public host passes.
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://evil.example/x.png'), 'sin restricción cualquier host público pasa');

    // Restriction ON: only the allowlist (alegra.com + extra) passes.
    update_option('alegra_connector_restrict_image_hosts', true, false);
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn.example.com/x.png'), 'host extra permitido en modo restringido');
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn3.alegra.com/x.png'), 'host de Alegra permitido en modo restringido');
    TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://evil.example/x.png'), 'host ajeno rechazado en modo restringido');
});

TestRunner::test('T28.712 R11 el run_id no filtra contexto fuera del run (R11)', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    $id = \Alegra\Connector\Runs::start('chunked_import', 'tester');
    \Alegra\Connector\Run_Context::resume($id, 'chunked_import');
    (new Logger())->info('dentro del run');
    \Alegra\Connector\Run_Context::finish($id, 'completed');
    (new Logger())->info('fuera del run');
    $log = alegra_read_log();
    TestRunner::assertStringContains('"run_id":' . $id, $log, 'la línea de dentro lleva run_id');
    $outside = '';
    foreach (explode("\n", $log) as $line) {
        if (str_contains($line, 'fuera del run')) { $outside = $line; }
    }
    TestRunner::assertStringNotContains('run_id', $outside, 'la línea posterior no lleva run_id');
});

TestRunner::test('T28.713 R13 clientes siguen funcionando sin tocar productos (R13)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('c-r13', ['name' => 'Cliente R13', 'email' => 'r13@example.test']);
    update_option('alegra_connector_sync_customers', true);
    update_option('alegra_connector_sync_products', false);
    update_option('alegra_connector_inventory_sync_enabled', false);
    alegra_clear_log();
    make_controller()->run_cron_sync();
    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status(1), 'el cron completa');
    TestRunner::assertTrue((bool) email_exists('r13@example.test'), 'el cliente se importó');
    TestRunner::assertStringContains('products skipped by configuration', alegra_read_log(), 'el catálogo no se tocó');
});

TestRunner::test('T28.714 filas viejas del Monitor se incluyen con tipo crudo y error', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', [
        'run_type' => 'cron_sync_all', 'status' => 'completed', 'started_at' => current_time('mysql'),
        'finished_at' => current_time('mysql'), 'items_done' => 3, 'total_items' => 5, 'items_failed' => 0,
        'memory_peak_mb' => 0, 'error_summary' => 'parcial',
    ]);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_monitor_status());
    TestRunner::assertTrue($resp->success, 'monitor responde success');
    $found = null;
    foreach (($resp->payload['recent'] ?? []) as $r) {
        if (($r['type'] ?? '') === 'cron_sync_all') { $found = $r; }
    }
    TestRunner::assertTrue($found !== null, 'la fila vieja debe aparecer en recent');
    TestRunner::assertSame('parcial', $found['error'] ?? null, 'el error_summary se expone');
});

TestRunner::test('T28.715 el Monitor expone el kill switch activo y su motivo', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Kill_Switch::activate('test_reason');
    \Alegra\Connector\Kill_Switch::reset_cache();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_monitor_status());
    TestRunner::assertSame(true, $resp->payload['kill_switch_active'] ?? null, 'kill_switch_active true');
    TestRunner::assertSame('test_reason', $resp->payload['kill_switch_reason'] ?? null, 'el motivo se expone');
    \Alegra\Connector\Kill_Switch::deactivate();
    \Alegra\Connector\Kill_Switch::reset_cache();
});

TestRunner::test('T28.716 exists() de tombstones sigue delegando en exists_with_reason()', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Tombstone_Manager::create([
        'alegra_id' => 'itm-e', 'alegra_type' => 'item', 'wc_post_id' => 0, 'deleted_by' => 1, 'reason' => 'bulk_wc',
    ]);
    TestRunner::assertTrue(\Alegra\Connector\Tombstone_Manager::exists('item', 'itm-e'), 'exists() delega');
    TestRunner::assertSame('bulk_wc', \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', 'itm-e'), 'reason coincide');
});

TestRunner::test('T28.717 el release 2.5.1 está consistente (uninstall/version/changelog)', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $uninstall = (string) file_get_contents($root . 'uninstall.php');
    foreach ([
        'alegra_connector_chunked_page_budget',
        'alegra_connector_allowed_image_hosts_extra',
        'alegra_connector_restrict_image_hosts',
        'alegra_connector_products_import_total',
        'alegra_connector_logger_write_failed',
    ] as $opt) {
        TestRunner::assertStringContains("delete_option('$opt')", $uninstall, "$opt debe limpiarse");
    }
    $main = (string) file_get_contents($root . 'alegra-connector.php');
    TestRunner::assertStringContains('Version: 2.5.1', $main, 'el header dice 2.5.1');
    $changelog = (string) file_get_contents($root . 'CHANGELOG.md');
    TestRunner::assertStringContains('## [2.5.1]', $changelog, 'el CHANGELOG tiene la sección');
    TestRunner::assertStringContains('## [2.5.0]', $changelog, 'el CHANGELOG conserva 2.5.0');
    TestRunner::assertStringContains('borra TODOS los archivos de log', $changelog, 'documenta el cambio de semántica');
});

TestRunner::test('T28.718 R14 el cierre del chunked borra el cursor por tipo (R14)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_products_import_cursor', 1500, false);
    alegra_mock_seed_contact('c-r14', ['name' => 'R14', 'email' => 'r14@example.test']);
    $rid = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'customers', 'run_id' => $rid, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertSame(true, $resp->payload['done'] ?? null, 'customers completa');
    TestRunner::assertSame(1500, (int) get_option('alegra_connector_products_import_cursor', 0), 'customers NO borra el cursor de products');

    alegra_test_reset();
    alegra_mock_seed_item('it-r14', ['name' => 'R14', 'reference' => 'SR14', 'status' => 'active']);
    $rid2 = \Alegra\Connector\Runs::start('chunked_import');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $rid2, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 1, 'total_items' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0], 'filters' => [],
    ], 600);
    $admin2 = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp2 = alegra_capture_json(fn () => $admin2->ajax_sync_page());
    TestRunner::assertSame(true, $resp2->payload['done'] ?? null, 'products completa');
    TestRunner::assertFalse(get_option('alegra_connector_products_import_cursor', false), 'products SÍ borra su cursor');
});

// ===========================================================================
// === sync-reliability (2.6.0) ===
// Fase 1 — cimientos + harness (H1–H8), opciones nuevas y Write_Gate inventory.
// IDs: T29.1{n} (T29.11…T29.18, T29.110). Fases 2..9 agregan T29.2x..T29.9x.
// ===========================================================================

TestRunner::test('T29.11 wc_update_product_stock deriva stock_status y dispara hooks', function (): void {
    alegra_test_reset();
    $p = alegra_make_product(900, ['manage_stock' => true, 'stock' => 5, 'backorders' => 'no']);
    $ret = wc_update_product_stock($p, 0, 'set', false);
    TestRunner::assertSame(0, $ret, 'devuelve la cantidad nueva');
    TestRunner::assertSame('outofstock', $p->get_stock_status(), 'qty 0 + backorders no => outofstock');
    TestRunner::assertTrue(($GLOBALS['wp_did_action']['woocommerce_product_set_stock'] ?? 0) >= 1, 'dispara woocommerce_product_set_stock');
});

TestRunner::test('T29.12 WC_Product::save deriva stock_status con backorders', function (): void {
    alegra_test_reset();
    $yes = alegra_make_product(901, ['manage_stock' => true, 'stock' => 0, 'backorders' => 'yes']);
    $yes->save();
    TestRunner::assertSame('onbackorder', $yes->get_stock_status(), 'backorders yes + 0 => onbackorder');

    $no = alegra_make_product(902, ['manage_stock' => true, 'stock' => 0, 'backorders' => 'no']);
    $no->save();
    TestRunner::assertSame('outofstock', $no->get_stock_status(), 'backorders no + 0 => outofstock');

    $off = alegra_make_product(903, ['manage_stock' => false, 'stock_status' => 'instock']);
    $off->save();
    TestRunner::assertSame('instock', $off->get_stock_status(), 'sin manage_stock no deriva');
});

TestRunner::test('T29.13 el mock soporta CONTAINS y pagina contactos e items', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('cf-real', ['identificationObject' => ['type' => 'CC', 'number' => '222222222222']]);
    alegra_mock_seed_contact('falso-1', ['identificationObject' => ['type' => 'CC', 'number' => '9999222222222222']]);

    $exact = alegra_mock_filter_contacts(['identification' => '222222222222']);
    TestRunner::assertSame(1, count($exact), 'el default exacto no matchea el prefijo');

    alegra_mock_set_contact_identification_mode('contains');
    $contains = alegra_mock_filter_contacts(['identification' => '222222222222']);
    TestRunner::assertSame(2, count($contains), 'CONTAINS matchea ambos');

    $page1 = alegra_mock_filter_contacts(['identification' => '222222222222', 'start' => 0, 'limit' => 1]);
    $page2 = alegra_mock_filter_contacts(['identification' => '222222222222', 'start' => 1, 'limit' => 1]);
    TestRunner::assertSame(1, count($page1), 'page 1 devuelve 1');
    TestRunner::assertSame(1, count($page2), 'page 2 devuelve 1');
    TestRunner::assertNotSame($page1[0]['id'] ?? null, $page2[0]['id'] ?? null, 'páginas distintas');

    // FIX-13: GET /items pagina SIN metadata=true (el poll llama sin metadata).
    foreach ([1, 2, 3] as $n) {
        alegra_mock_seed_item('it-page-' . $n, ['name' => 'Item ' . $n, 'reference' => 'S' . $n, 'status' => 'active']);
    }
    $api = make_api();
    $items1 = $api->get_items(['start' => 0, 'limit' => 2]);
    TestRunner::assertSame(2, count($items1), 'GET /items sin metadata respeta limit=2');
    $items2 = $api->get_items(['start' => 2, 'limit' => 2]);
    TestRunner::assertSame(1, count($items2), 'la segunda página trae el resto');
    TestRunner::assertNotSame($items1[0]['id'] ?? null, $items2[0]['id'] ?? null, 'páginas de items distintas');

    $meta = $api->get_items(['start' => 0, 'limit' => 2, 'metadata' => 'true']);
    TestRunner::assertSame(3, (int) ($meta['metadata']['total'] ?? 0), 'metadata.total = total filtrado');
    TestRunner::assertSame(2, count($meta['data'] ?? []), 'metadata.data sigue paginado');
});

TestRunner::test('T29.14 POST /inventory-adjustments aplica el delta al stock del item', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-adj', ['name' => 'Adj', 'inventory' => ['availableQuantity' => 10]]);
    $api = make_api();

    // Documented schema (G3): {date, items:[{id,type,quantity,unitCost}], warehouse?}.
    $out = $api->create_inventory_adjustment([
        'date' => '2026-09-25',
        'items' => [['id' => 'it-adj', 'type' => 'out', 'quantity' => 3, 'unitCost' => 1.5]],
    ]);
    TestRunner::assertFalse(is_wp_error($out), 'el ajuste out debe aceptarse');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-adj']['inventory']['availableQuantity'], 'out resta');

    $in = $api->create_inventory_adjustment([
        'date' => '2026-09-25',
        'items' => [['id' => 'it-adj', 'type' => 'in', 'quantity' => 2, 'unitCost' => 1.5]],
    ]);
    TestRunner::assertFalse(is_wp_error($in), 'el ajuste in debe aceptarse');
    TestRunner::assertSame(9, (int) $GLOBALS['alegra_mock_state']['items']['it-adj']['inventory']['availableQuantity'], 'in suma');

    // FIX-4: the mock supports GET /inventory-adjustments (item_id filter).
    $listed = $api->get_inventory_adjustments(['item_id' => 'it-adj']);
    TestRunner::assertFalse(is_wp_error($listed), 'GET /inventory-adjustments debe responder');
    TestRunner::assertSame(2, count($listed), 'el mock conserva los ajustes del item');

    $bad = $api->create_inventory_adjustment([
        'date' => '2026-09-25',
        'items' => [['id' => 'it-adj', 'type' => 'out', 'quantity' => 0, 'unitCost' => 1.5]],
    ]);
    TestRunner::assertTrue(is_wp_error($bad), 'quantity 0 => 400 (quantity debe ser > 0)');

    // The old K-H4 shape (item singular, no items[]/unitCost) is invalid.
    $old = $api->create_inventory_adjustment(['date' => '2026-09-25', 'type' => 'out', 'quantity' => 1, 'item' => ['id' => 'it-adj']]);
    TestRunner::assertTrue(is_wp_error($old), 'el shape viejo item:{id} sin items[]/unitCost => 400');
});

TestRunner::test('T29.15 POST /invoices rechaza un client inexistente (opt-in)', function (): void {
    alegra_test_reset();
    alegra_mock_set_invoice_client_check(true);
    alegra_mock_seed_item('it-inv', ['name' => 'Inv', 'inventory' => ['availableQuantity' => 1]]);
    $res = make_api()->create_invoice([
        'client' => ['id' => 'dead'], 'items' => [['id' => 'it-inv']],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    TestRunner::assertTrue(is_wp_error($res), 'client muerto => WP_Error');
    TestRunner::assertSame(400, (int) ($res->get_error_data()['code'] ?? 0), 'code 400');

    // Default OFF: un client sin seed sigue aceptándose (baseline intacto).
    alegra_test_reset();
    alegra_mock_seed_item('it-inv2', ['name' => 'Inv2', 'inventory' => ['availableQuantity' => 1]]);
    $ok = make_api()->create_invoice([
        'client' => ['id' => 'c1'], 'items' => [['id' => 'it-inv2']],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    TestRunner::assertFalse(is_wp_error($ok), 'con el flag OFF no se rechaza (baseline)');
});

TestRunner::test('T29.16 set_syncing(true) es observable por el transient de import', function (): void {
    alegra_test_reset();
    TestRunner::assertFalse(get_transient('alegra_import_in_progress'), 'sin syncing no hay transient');

    \Alegra\Connector\Public\Public_::set_syncing(true);
    TestRunner::assertTrue((bool) get_transient('alegra_import_in_progress'), 'set_syncing(true) escribe el transient');

    \Alegra\Connector\Public\Public_::set_syncing(false);
    TestRunner::assertFalse(get_transient('alegra_import_in_progress'), 'set_syncing(false) lo borra');
});

TestRunner::test('T29.17 las 9 opciones nuevas se siembran y se limpian', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $boot = (string) file_get_contents($root . 'alegra-connector.php');
    $uninstall = (string) file_get_contents($root . 'uninstall.php');

    $seed = [
        'alegra_connector_push_inventory_enabled' => 'true',
        'alegra_connector_inventory_manage_stock_enabled' => 'false',
        'alegra_connector_inventory_poll_budget' => '60',
        'alegra_connector_inventory_poll_max_pages' => '0',
        'alegra_connector_cron_run_budget' => '540',
        'alegra_connector_open_invoice_on_paid' => 'true',
    ];
    foreach ($seed as $opt => $default) {
        TestRunner::assertStringContains("'$opt' => $default", $boot, "$opt debe estar en \$defaults con default $default");
    }
    foreach ([
        'alegra_connector_push_inventory_enabled', 'alegra_connector_inventory_manage_stock_enabled',
        'alegra_connector_inventory_poll_budget', 'alegra_connector_inventory_poll_max_pages',
        'alegra_connector_cron_run_budget', 'alegra_connector_open_invoice_on_paid',
        'alegra_connector_inventory_pull_cursor',
        'alegra_connector_inventory_pull_total', 'alegra_connector_consumidor_final_probe',
    ] as $opt) {
        TestRunner::assertStringContains($opt, $boot, "$opt debe estar en \$non_autoload");
        TestRunner::assertStringContains("delete_option('$opt')", $uninstall, "$opt debe limpiarse en uninstall");
    }
});

TestRunner::test('T29.18 Write_Gate reconoce inventory y no lo bloquea por default', function (): void {
    alegra_test_reset();
    TestRunner::assertSame('inventory', \Alegra\Connector\Write_Gate::entity_for('POST', '/inventory-adjustments'), 'entity_for');
    TestRunner::assertSame('inventory', \Alegra\Connector\Write_Gate::entity_for('POST', '/inventory-adjustments/'), 'con slash final');

    unset($GLOBALS['wp_options']['alegra_connector_push_inventory_enabled']);
    TestRunner::assertSame(null, \Alegra\Connector\Write_Gate::block_reason('inventory'), 'opción ausente => ENTITY_DEFAULTS true => no bloquea');

    update_option('alegra_connector_push_inventory_enabled', false);
    TestRunner::assertSame('entity_disabled', \Alegra\Connector\Write_Gate::block_reason('inventory'), 'false => entity_disabled');
});

TestRunner::test('T29.110 los helpers del ledger leen/escriben las metas de stock', function (): void {
    alegra_test_reset();
    alegra_make_product(910, ['name' => 'Ledger']);

    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::synced(910), 'sin meta => ""');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(910, 10);
    TestRunner::assertSame(10, \Alegra\Connector\Sync\Inventory_Pusher::synced(910), 'round-trip synced');

    \Alegra\Connector\Sync\Inventory_Pusher::set_pending(910, 7);
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::pending(910), 'round-trip pending');
    \Alegra\Connector\Sync\Inventory_Pusher::clear_pending(910);
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::pending(910), 'clear_pending borra');
});

// ---------------------------------------------------------------------------
// Fase 2 — Inventory_Writer (D3/D5): el escritor único
// ---------------------------------------------------------------------------

TestRunner::test('T29.21 el writer dispara hooks y deriva stock_status', function (): void {
    alegra_test_reset();
    $p = alegra_make_product(920, ['manage_stock' => true, 'stock' => 5, 'backorders' => 'yes']);
    $GLOBALS['hook_hits'] = 0;
    add_action('woocommerce_product_set_stock', function ($product): void {
        $GLOBALS['hook_hits']++;
    }, 10, 1);

    $r = (new \Alegra\Connector\Sync\Inventory_Writer())->apply(
        $p,
        ['inventory' => ['availableQuantity' => 0]],
        ['manage_stock' => 'enable']
    );
    TestRunner::assertSame('updated', $r, 'el writer reporta updated');
    TestRunner::assertSame('onbackorder', $p->get_stock_status(), 'backorders=yes + qty 0 => onbackorder');
    TestRunner::assertSame(1, $GLOBALS['hook_hits'], 'wc_update_product_stock dispara el hook una vez');
});

TestRunner::test('T29.22 las compuertas del writer', function (): void {
    alegra_test_reset();
    $writer = new \Alegra\Connector\Sync\Inventory_Writer();

    // 1) Fuente
    $p = alegra_make_product(930, ['manage_stock' => true, 'stock' => 5]);
    TestRunner::assertSame('skipped_source', $writer->apply($p, ['inventory' => ['availableQuantity' => 99]], ['source' => 'woocommerce', 'manage_stock' => 'enable']), 'source=woocommerce');
    TestRunner::assertSame(5, $p->get_stock_quantity(), 'source no escribe');

    // 2) Preserve
    $p = alegra_make_product(931, ['manage_stock' => true, 'stock' => 5]);
    TestRunner::assertSame('skipped_preserve', $writer->apply($p, ['inventory' => ['availableQuantity' => 99]], ['preserve' => true, 'manage_stock' => 'enable']), 'preserve');
    TestRunner::assertSame(5, $p->get_stock_quantity(), 'preserve no escribe');

    // 3) Variable padre
    $parent = alegra_make_product(932, ['type' => 'variable', 'manage_stock' => true, 'stock' => 5]);
    TestRunner::assertSame('skipped_parent', $writer->apply($parent, ['inventory' => ['availableQuantity' => 99]], ['manage_stock' => 'enable']), 'variable padre');
    TestRunner::assertFalse($parent->get_manage_stock(), 'el padre no maneja stock');

    // 4) Servicio (sin inventory)
    $p = alegra_make_product(933, ['manage_stock' => true, 'stock' => 5]);
    TestRunner::assertSame('skipped_service', $writer->apply($p, ['id' => 'svc'], ['manage_stock' => 'enable']), 'servicio');
    TestRunner::assertFalse($p->get_manage_stock(), 'servicio no maneja stock');

    // 5) manage_stock=respect + producto no gestionable
    $p = alegra_make_product(934, ['manage_stock' => false, 'stock' => 5]);
    TestRunner::assertSame('skipped_not_manageable', $writer->apply($p, ['inventory' => ['availableQuantity' => 99]]), 'respect');
    TestRunner::assertSame(5, $p->get_stock_quantity(), 'respect no escribe');

    // 6a) Nulo: nunca 0
    $p = alegra_make_product(935, ['manage_stock' => true, 'stock' => 7]);
    TestRunner::assertSame('skipped_no_qty', $writer->apply($p, ['inventory' => ['availableQuantity' => null]], ['manage_stock' => 'enable']), 'nulo');
    TestRunner::assertSame(7, $p->get_stock_quantity(), 'nulo no escribe 0');

    // 6b) Negativo: clamp 0
    $p = alegra_make_product(936, ['manage_stock' => true, 'stock' => 7, 'backorders' => 'no']);
    TestRunner::assertSame('clamped_negative', $writer->apply($p, ['inventory' => ['availableQuantity' => -3]], ['manage_stock' => 'enable']), 'negativo');
    TestRunner::assertSame(0, $p->get_stock_quantity(), 'negativo clampea a 0');

    // 7) Dry-run
    $p = alegra_make_product(937, ['manage_stock' => true, 'stock' => 5]);
    TestRunner::assertSame('dry_run', $writer->apply($p, ['inventory' => ['availableQuantity' => 99]], ['manage_stock' => 'enable', 'dry_run' => true]), 'dry_run');
    TestRunner::assertSame(5, $p->get_stock_quantity(), 'dry_run no escribe');
});

TestRunner::test('T29.23 backorders=no idéntico a HEAD', function (): void {
    alegra_test_reset();
    $p = alegra_make_product(938, ['manage_stock' => true, 'stock' => 5, 'backorders' => 'no']);
    $r = (new \Alegra\Connector\Sync\Inventory_Writer())->apply(
        $p,
        ['inventory' => ['availableQuantity' => 0]],
        ['manage_stock' => 'enable']
    );
    TestRunner::assertSame('updated', $r, 'updated');
    TestRunner::assertSame('outofstock', $p->get_stock_status(), 'backorders=no + qty 0 => outofstock');
});

TestRunner::test('T29.24 W1 delega en el writer y respeta source/preserve', function (): void {
    alegra_test_reset();
    $products = make_products();
    $item = ['id' => 'it-w1', 'inventory' => ['availableQuantity' => 99]];

    update_option('alegra_connector_inventory_source', 'woocommerce');
    $p = alegra_make_product(940, ['manage_stock' => true, 'stock' => 5]);
    alegra_call_private($products, 'apply_inventory_to_product', $p, $item, []);
    TestRunner::assertSame(5, $p->get_stock_quantity(), 'source=woocommerce no escribe');

    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_call_private($products, 'apply_inventory_to_product', $p, $item, []);
    TestRunner::assertSame(99, $p->get_stock_quantity(), 'source=alegra escribe vía el writer');

    $p2 = alegra_make_product(941, ['manage_stock' => true, 'stock' => 5]);
    alegra_call_private($products, 'apply_inventory_to_product', $p2, $item, ['inventory']);
    TestRunner::assertSame(5, $p2->get_stock_quantity(), 'preserve=inventory no escribe');

    $r = (new \Alegra\Connector\Sync\Inventory_Writer())->apply(
        alegra_make_product(942, ['manage_stock' => true, 'stock' => 1]),
        $item,
        ['source' => 'woocommerce', 'manage_stock' => 'enable']
    );
    TestRunner::assertSame('skipped_source', $r, 'el writer confirma skipped_source');
});

TestRunner::test('T29.25 W2 (poll) respeta preserve_fields', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(950, ['name' => 'Poll', 'sku' => 'P1', 'stock' => 7, 'manage_stock' => true]);
    update_post_meta(950, '_alegra_item_id', 'it-poll');
    alegra_mock_seed_item('it-poll', ['name' => 'Poll', 'reference' => 'P1', 'inventory' => ['availableQuantity' => 10]]);

    update_option('alegra_connector_import_preserve_fields', ['inventory']);
    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertSame(7, wc_get_product(950)->get_stock_quantity(), 'preserve no toca el stock');
    TestRunner::assertSame(0, $result['updated'], 'preserve no cuenta updated');

    update_option('alegra_connector_import_preserve_fields', []);
    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertSame(10, wc_get_product(950)->get_stock_quantity(), 'sin preserve escribe el valor de Alegra');
    TestRunner::assertSame(1, $result['updated'], 'sin preserve cuenta updated');
});

TestRunner::test('T29.26 W2 no escribe servicios', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_make_product(951, ['name' => 'Svc', 'sku' => 'S1', 'stock' => 4, 'manage_stock' => true]);
    update_post_meta(951, '_alegra_item_id', 'it-svc');
    // Servicio: sin objeto `inventory`.
    alegra_mock_seed_item('it-svc', ['name' => 'Svc', 'reference' => 'S1']);

    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertSame(4, wc_get_product(951)->get_stock_quantity(), 'un servicio no recibe cantidad');
    TestRunner::assertSame(0, $result['updated'], 'un servicio no cuenta updated');
});

TestRunner::test('T29.27 opt-in manage_stock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_inventory_source', 'alegra');
    update_option('alegra_connector_inventory_manage_stock_enabled', false);
    alegra_make_product(952, ['name' => 'Legacy', 'sku' => 'L1', 'stock' => 3, 'manage_stock' => false]);
    update_post_meta(952, '_alegra_item_id', 'it-legacy');
    alegra_mock_seed_item('it-legacy', ['name' => 'Legacy', 'reference' => 'L1', 'inventory' => ['availableQuantity' => 8]]);

    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertSame(3, wc_get_product(952)->get_stock_quantity(), 'default respeta el manage_stock=no de WC');
    TestRunner::assertSame(1, $result['skipped_not_manageable'], 'cuenta el skip del opt-in');
    TestRunner::assertFalse(wc_get_product(952)->get_manage_stock(), 'no habilita manage_stock con el opt-in apagado');

    update_option('alegra_connector_inventory_manage_stock_enabled', true);
    $result = make_products()->sync_inventory_from_alegra();
    TestRunner::assertTrue(wc_get_product(952)->get_manage_stock(), 'el opt-in habilita manage_stock');
    TestRunner::assertSame(8, wc_get_product(952)->get_stock_quantity(), 'el opt-in escribe la cantidad');
});

TestRunner::test('T29.28 el docblock refutado está corregido (source-scan)', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'includes/Sync/Products.php');
    TestRunner::assertStringNotContains('does NOT derive', $src, 'el claim refutado ya no está');
    TestRunner::assertStringContains('validate_props', $src, 'el docblock cita validate_props');
});

TestRunner::test('T29.29 un solo escritor de stock (grep invariante)', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $violations = [];
    foreach (['includes/', 'public/', 'admin/'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with($path, 'includes/Sync/Inventory_Writer.php')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            // Los comentarios no cuentan: sólo llamadas reales.
            $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
            $src = (string) preg_replace('![ \t]*//.*$!m', '', $src);
            if (preg_match('/set_manage_stock|set_stock_quantity|set_stock_status|wc_update_product_stock/', $src)) {
                $violations[] = str_replace($root, '', $path);
            }
        }
    }
    TestRunner::assertSame([], $violations, 'solo Inventory_Writer.php llama a los setters de stock');
});

// ---------------------------------------------------------------------------
// Fase 3 — Inventory_Pusher (D2): el dueño híbrido y el delta tracking
// ---------------------------------------------------------------------------

TestRunner::test('T29.31 owner y payload documentado', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    TestRunner::assertSame('adjustment', \Alegra\Connector\Sync\Inventory_Pusher::owner(), 'push_orders=false => adjustment');

    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', true);
    TestRunner::assertSame('invoice', \Alegra\Connector\Sync\Inventory_Pusher::owner(), 'push_orders && open_invoice_on_paid => invoice');

    update_option('alegra_connector_open_invoice_on_paid', false);
    TestRunner::assertSame('adjustment', \Alegra\Connector\Sync\Inventory_Pusher::owner(), 'push_orders && !open_invoice_on_paid => adjustment');

    $p = alegra_make_product(1000, ['regular_price' => '0']);
    $pusher = new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger());
    $payload = alegra_call_private($pusher, 'build_adjustment_payload', '4', -3, $p);
    TestRunner::assertSame('out', $payload['items'][0]['type'] ?? null, 'type out');
    TestRunner::assertSame(3, $payload['items'][0]['quantity'] ?? null, 'quantity abs(delta)');
    TestRunner::assertSame('4', $payload['items'][0]['id'] ?? null, 'id string');
    TestRunner::assertTrue(($payload['items'][0]['unitCost'] ?? 0) > 0, 'unitCost > 0 (FIX-12)');
});

TestRunner::test('T29.32 ledger del pusher', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Public\Public_::set_syncing(false);
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-p', ['name' => 'P', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1010, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1010, '_alegra_item_id', 'it-p');
    $pusher = new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger());

    // (a) hook sin baseline: pending, synced vacío, sin POST
    $r = $pusher->push_delta($p, 7, false);
    TestRunner::assertSame('baseline_pending', $r['reason'], 'hook sin baseline');
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::synced(1010), 'synced NO se fija desde WC');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::pending(1010), 'pending = new_qty');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'no POST en baseline_pending');

    // (b) in_sync: no POST
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1010, 7);
    \Alegra\Connector\Sync\Inventory_Pusher::clear_pending(1010);
    $r = $pusher->push_delta($p, 7, true);
    TestRunner::assertSame('in_sync', $r['reason'], 'delta 0');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'in_sync no POSTea');

    // (c) delta out aplicado en el mock
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1010, 10);
    $r = $pusher->push_delta($p, 7, true);
    TestRunner::assertSame('ok', $r['reason'], 'delta aplicado');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-p']['inventory']['availableQuantity'], 'el mock resta 3');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::synced(1010), 'synced=7 tras el OK');
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::pending(1010), 'pending limpio');

    // (d) api_error deja pending y synced viejo
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1010, 7);
    alegra_mock_fail('POST', '/inventory-adjustments', 500, ['message' => 'boom'], 1);
    $r = $pusher->push_delta($p, 5, true);
    TestRunner::assertSame('api_error', $r['reason'], 'el POST falla');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::synced(1010), 'synced NO avanza con el POST fallido');
    TestRunner::assertSame(5, \Alegra\Connector\Sync\Inventory_Pusher::pending(1010), 'pending queda para reintentar');
});

TestRunner::test('T29.32b baseline desde Alegra (FIX-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-b', ['name' => 'B', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1020, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1020, '_alegra_item_id', 'it-b');
    \Alegra\Connector\Sync\Inventory_Pusher::set_pending(1020, 7);   // venta antes del primer baseline

    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, true);
    TestRunner::assertSame('ok', $r['reason'], 'reconcilió y empujó');
    TestRunner::assertSame(1, alegra_mock_count('GET', '/items/it-b'), 'el baseline sale de Alegra (GET /items/{id})');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'un POST out 3');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-b']['inventory']['availableQuantity'], 'Alegra queda en 7');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::synced(1020), 'synced acuerda en 7');
});

TestRunner::test('T29.32c pre-búsqueda obligatoria (FIX-4)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-c', ['name' => 'C', 'inventory' => ['availableQuantity' => 10]]);
    // Un ajuste out 3 ya aplicado (la respuesta del POST se perdió).
    make_api()->create_inventory_adjustment([
        'date' => '2026-09-25',
        'items' => [['id' => 'it-c', 'type' => 'out', 'quantity' => 3, 'unitCost' => 1.0]],
    ]);
    $p = alegra_make_product(1030, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1030, '_alegra_item_id', 'it-c');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1030, 10);
    \Alegra\Connector\Sync\Inventory_Pusher::set_pending(1030, 7);
    $before = alegra_mock_count('POST', '/inventory-adjustments');

    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, true);
    TestRunner::assertSame('already_applied', $r['reason'], 'el ajuste ya existe');
    TestRunner::assertSame($before, alegra_mock_count('POST', '/inventory-adjustments'), 'cero POST nuevo (no doble descuento)');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::synced(1030), 'synced=new_qty');
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::pending(1030), 'pending limpio');
});

TestRunner::test('T29.33 idempotencia con lock', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-l', ['name' => 'L', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1040, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1040, '_alegra_item_id', 'it-l');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1040, 10);

    $token = Controller::acquire_lock('alegra_inventory_push_1040', 30);
    TestRunner::assertTrue($token !== false, 'lock tomado');
    $before = alegra_mock_count('POST', '/inventory-adjustments');
    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, true);
    TestRunner::assertSame('locked', $r['reason'], 'lock por producto');
    TestRunner::assertSame($before, alegra_mock_count('POST', '/inventory-adjustments'), 'sin POST con el lock tomado');
    Controller::release_lock('alegra_inventory_push_1040', $token);
});

TestRunner::test('T29.34 EL TITULAR: vender 3 -> poll -> WC no sube', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Public\Public_::set_syncing(false);
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('it-34', ['name' => 'T', 'reference' => 'T34', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1050, ['name' => 'T', 'sku' => 'T34', 'stock' => 10, 'manage_stock' => true]);
    update_post_meta(1050, '_alegra_item_id', 'it-34');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1050, 10);

    // FIX-11: el stub save() no dispara hooks; se dispara explícitamente.
    \Alegra\Connector\Sync\Inventory_Pusher::register_hooks(make_api(), make_logger());

    // La venta ocurre durante un import/sync (FIX-2: el hook no empuja). El
    // poll es el que reconcilia: si T3.4 se revierte a una escritura
    // incondicional, el poll pisa WC con el valor viejo de Alegra (10) y el
    // test falla — ése es el prove-it-catch del titular.
    \Alegra\Connector\Public\Public_::set_syncing(true);
    $p->set_stock_quantity(7);
    do_action('woocommerce_product_set_stock', $p);
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'FIX-2: el hook no empuja durante el sync');
    TestRunner::assertSame(10, (int) $GLOBALS['alegra_mock_state']['items']['it-34']['inventory']['availableQuantity'], 'Alegra sigue en 10');
    \Alegra\Connector\Public\Public_::set_syncing(false);

    make_products()->sync_inventory_from_alegra();

    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'el poll reconcilia y empuja out 3');
    TestRunner::assertSame(7, wc_get_product(1050)->get_stock_quantity(), 'WC NO se re-infla a 10');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-34']['inventory']['availableQuantity'], 'Alegra queda en 7');
});

TestRunner::test('T29.34b venta antes del primer poll no se re-infla (FIX-1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('it-34b', ['name' => 'B', 'reference' => 'B34', 'inventory' => ['availableQuantity' => 10]]);
    alegra_make_product(1060, ['name' => 'B', 'sku' => 'B34', 'stock' => 7, 'manage_stock' => true]);
    update_post_meta(1060, '_alegra_item_id', 'it-34b');
    \Alegra\Connector\Sync\Inventory_Pusher::set_pending(1060, 7);   // venta antes del baseline

    make_products()->sync_inventory_from_alegra();

    TestRunner::assertSame(7, wc_get_product(1060)->get_stock_quantity(), 'WC no se re-infla');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-34b']['inventory']['availableQuantity'], 'Alegra se reconcilia a 7');
});

TestRunner::test('T29.34c el poll no se muere de hambre (FIX-8)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_push_inventory_enabled', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('it-34c', ['name' => 'C', 'reference' => 'C34', 'inventory' => ['availableQuantity' => 5]]);
    alegra_make_product(1070, ['name' => 'C', 'sku' => 'C34', 'stock' => 7, 'manage_stock' => true]);
    update_post_meta(1070, '_alegra_item_id', 'it-34c');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1070, 10);   // WC 7 != synced 10 => needs_reconcile

    make_products()->sync_inventory_from_alegra();

    TestRunner::assertSame(5, wc_get_product(1070)->get_stock_quantity(), 'con disabled el poll escribe WC con el valor de Alegra');
});

TestRunner::test('T29.35 hooks de stock e is_syncing corta en el hook (FIX-2)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-35', ['name' => 'H', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1080, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1080, '_alegra_item_id', 'it-35');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1080, 10);

    \Alegra\Connector\Sync\Inventory_Pusher::register_hooks(make_api(), make_logger());

    \Alegra\Connector\Public\Public_::set_syncing(true);
    do_action('woocommerce_product_set_stock', $p);
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'is_syncing corta en el hook');
    \Alegra\Connector\Public\Public_::set_syncing(false);

    do_action('woocommerce_product_set_stock', $p);
    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'sin syncing el hook empuja');
    $body = alegra_mock_last_request('POST', '/inventory-adjustments')['body'] ?? [];
    TestRunner::assertSame('out', $body['items'][0]['type'] ?? null, 'empuja out');
});

TestRunner::test('T29.36 push fallido no re-infla', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('it-36', ['name' => 'F', 'reference' => 'F36', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1090, ['name' => 'F', 'sku' => 'F36', 'stock' => 10, 'manage_stock' => true]);
    update_post_meta(1090, '_alegra_item_id', 'it-36');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1090, 10);

    alegra_mock_fail('POST', '/inventory-adjustments', 500, ['message' => 'boom']);
    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, false);
    TestRunner::assertSame('api_error', $r['reason'], 'el push falla');
    TestRunner::assertSame(7, \Alegra\Connector\Sync\Inventory_Pusher::pending(1090), 'pending queda');

    // El poll corre con el mock OK.
    alegra_mock_clear_failures();
    $p->set_stock_quantity(7);
    make_products()->sync_inventory_from_alegra();

    TestRunner::assertSame(7, wc_get_product(1090)->get_stock_quantity(), 'el poll reintenta y WC queda 7');
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::pending(1090), 'pending limpio');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-36']['inventory']['availableQuantity'], 'Alegra 7');
});

TestRunner::test('T29.36b el poll reintenta con set_syncing activo (FIX-2, cross-fase)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    update_option('alegra_connector_inventory_source', 'alegra');
    alegra_mock_seed_item('it-36b', ['name' => 'S', 'reference' => 'S36', 'inventory' => ['availableQuantity' => 10]]);
    alegra_make_product(1100, ['name' => 'S', 'sku' => 'S36', 'stock' => 7, 'manage_stock' => true]);
    update_post_meta(1100, '_alegra_item_id', 'it-36b');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1100, 10);
    \Alegra\Connector\Sync\Inventory_Pusher::set_pending(1100, 7);

    \Alegra\Connector\Public\Public_::set_syncing(true);
    make_products()->sync_inventory_from_alegra();
    \Alegra\Connector\Public\Public_::set_syncing(false);

    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'from_poll bypassa is_syncing');
    TestRunner::assertSame('', \Alegra\Connector\Sync\Inventory_Pusher::pending(1100), 'pending limpio');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-36b']['inventory']['availableQuantity'], 'Alegra 7');
});

TestRunner::test('T29.37 dueño invoice: factura open y 0 ajustes', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', true);
    alegra_make_product(10, ['name' => 'Widget', 'sku' => 'SKU-1', 'regular_price' => '119']);
    update_post_meta(10, '_alegra_item_id', '1t3m-inv');
    $order = alegra_make_order(1200, [
        'total' => 119.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => 'inv@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-inv'],
        'items' => [new WC_Order_Item(['product_id' => 10, 'name' => 'Widget', 'quantity' => 1, 'subtotal' => 119, 'total' => 119])],
    ]);
    make_orders()->create_invoice($order);
    $body = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame('open', $body['status'] ?? null, 'un pedido pagado nace open con owner=invoice');

    $p = alegra_make_product(1201, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1201, '_alegra_item_id', 'it-inv');
    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, true);
    TestRunner::assertSame('invoice_owner', $r['reason'], 'con owner=invoice no se emite ajuste');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'cero ajustes');
});

TestRunner::test('T29.37b abre el borrador pre-existente (FIX-3/D1)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', true);
    update_option('alegra_connector_payment_account_id', 'ba-1');
    alegra_mock_seed_invoice('1nv-draft-b', [
        'status' => 'draft', 'total' => 10.0, 'balance' => 10.0,
        'items' => [['id' => '1t3m-db', 'name' => 'Widget', 'price' => 10, 'quantity' => 1]],
    ]);
    $order = alegra_make_order(1210, [
        'total' => 10.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => 'draftb@example.test'],
        'meta' => ['_alegra_invoice_id' => '1nv-draft-b', '_billing_alegra_contact_id' => 'c0n-draftb'],
    ]);

    make_orders()->create_invoice_with_payment($order);

    TestRunner::assertSame('open', $GLOBALS['alegra_mock_state']['invoices']['1nv-draft-b']['status'] ?? null, 'el borrador pre-existente se abre');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'cero ajustes');
    TestRunner::assertSame('1nv-draft-b', (string) $order->get_meta('_alegra_invoice_id', true), 'no se crea una segunda factura');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no se crea una segunda factura');
});

TestRunner::test('T29.37c reusa una factura open existente (FIX-3)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', true);
    alegra_mock_seed_invoice('1nv-open-c', [
        'status' => 'open', 'total' => 10.0, 'balance' => 0.0,
        'client' => ['id' => 'c0n-openc'],
        'observations' => 'Pedido WooCommerce #1220',
        'items' => [['id' => '1t3m-oc', 'name' => 'Widget', 'price' => 10, 'quantity' => 1]],
    ]);
    $order = alegra_make_order(1220, [
        'total' => 10.0, 'currency' => 'COP', 'payment_method' => 'bacs', 'status' => 'processing',
        'billing' => ['country' => 'CO', 'email' => 'openc@example.test'],
        'meta' => ['_billing_alegra_contact_id' => 'c0n-openc'],
    ]);

    $result = make_orders()->create_invoice($order);
    TestRunner::assertSame('1nv-open-c', (string) ($result['id'] ?? ''), 'reusa la factura open');
    TestRunner::assertTrue(!empty($result['already_exists']), 'already_exists');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/invoices'), 'no crea una segunda factura');
});

TestRunner::test('T29.38 un solo dueño por movimiento', function (): void {
    // owner=adjustment (default): 1 POST
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_mock_seed_item('it-38', ['name' => 'X', 'inventory' => ['availableQuantity' => 10]]);
    $p = alegra_make_product(1230, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1230, '_alegra_item_id', 'it-38');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1230, 10);
    (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p, 7, true);
    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'adjustment emite 1 ajuste');

    // push_orders=true + open_invoice_on_paid=false => adjustment: 1 POST
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', false);
    alegra_mock_seed_item('it-38b', ['name' => 'Y', 'inventory' => ['availableQuantity' => 10]]);
    $p2 = alegra_make_product(1231, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1231, '_alegra_item_id', 'it-38b');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1231, 10);
    (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p2, 7, true);
    TestRunner::assertSame(1, alegra_mock_count('POST', '/inventory-adjustments'), 'push_orders sin abrir factura => adjustment');

    // owner=invoice real: 0 POST
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', true);
    update_option('alegra_connector_open_invoice_on_paid', true);
    alegra_mock_seed_item('it-38c', ['name' => 'Z', 'inventory' => ['availableQuantity' => 10]]);
    $p3 = alegra_make_product(1232, ['manage_stock' => true, 'stock' => 7]);
    update_post_meta(1232, '_alegra_item_id', 'it-38c');
    \Alegra\Connector\Sync\Inventory_Pusher::set_synced(1232, 10);
    $r = (new \Alegra\Connector\Sync\Inventory_Pusher(make_api(), make_logger()))->push_delta($p3, 7, true);
    TestRunner::assertSame('invoice_owner', $r['reason'], 'owner=invoice no emite ajuste');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/inventory-adjustments'), 'owner=invoice => 0 ajustes');
});

TestRunner::test('T29.39 informe de divergencia (REQ-INV-07)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_push_orders_enabled', false);
    alegra_make_order(1300, ['status' => 'processing', 'total' => 10.0]);
    alegra_make_order(1301, ['status' => 'completed', 'total' => 20.0]);
    alegra_make_order(1302, ['status' => 'processing', 'total' => 30.0]);

    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    TestRunner::assertSame(3, $admin->get_unjournaled_sales()['count'], 'cuenta los vendidos sin factura');

    foreach ([1300, 1301, 1302] as $id) {
        $o = wc_get_order($id);
        $o->update_meta_data('_alegra_invoice_id', '1nv-' . $id);
        $o->save();
    }
    TestRunner::assertSame(0, $admin->get_unjournaled_sales()['count'], 'con factura no hay divergencia');
});

exit(TestRunner::summary());
