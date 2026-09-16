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
        'emission_status' => 'STAMPED',
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
    TestRunner::assertSame('simple', $body['type'] ?? null, 'type');
    TestRunner::assertSame(1, $body['price'][0]['idPriceList'] ?? null, 'price[0].idPriceList');
    TestRunner::assertEquals(50.0, $body['price'][0]['price'] ?? null, 'price[0].price');
    TestRunner::assertSame('unit', $body['inventory']['unit'] ?? null, 'inventory.unit');
    TestRunner::assertArrayHasKey('initialQuantity', $body['inventory'] ?? [], 'inventory.initialQuantity');
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

TestRunner::test('T3.1 CO invoice payload is complete and uses an UPPERCASE DIAN payment method', function (): void {
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
    TestRunner::assertSame(true, $body['stamp']['generateStamp'] ?? null, 'stamp.generateStamp must be true for CO');
    TestRunner::assertSame('c0n-co', $body['client']['id'] ?? null, 'client must reference the resolved contact');

    TestRunner::assertTrue(!empty($body['items']), 'items must not be empty');
    foreach ($body['items'] as $i => $item) {
        TestRunner::assertArrayHasKey('id', $item, "items[$i] must carry an Alegra id (obligatory)");
    }

    $pm = $body['paymentMethod'] ?? null;
    if ($pm !== null) {
        TestRunner::assertSame(strtoupper((string) $pm), (string) $pm, 'CO paymentMethod must be an UPPERCASE DIAN code');
    }

    TestRunner::assertSame('1t3m-co', $order->get_meta('_alegra_invoice_id', true) === '' ? null : $body['items'][0]['id'], 'invoice line links the item');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_invoice_id', true) !== '', '_alegra_invoice_id must be persisted');
});

TestRunner::test('T3.2 non-CO invoice never sends a lowercase global code as a DIAN code', function (): void {
    alegra_test_reset();
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
    TestRunner::assertArrayNotHasKey('stamp', $body, 'non-CO must not request a DIAN stamp');
    TestRunner::assertSame('credit-card', $body['paymentMethod'] ?? null, 'non-CO keeps the lowercase global code');
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
    // register the refund status hook that caused the duplicate DIAN note).
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
        'emission_status' => 'STAMPED', 'total' => 100.0, 'balance' => 100.0, 'status' => 'open',
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
        'emission_status' => 'STAMPED', 'total' => 40.0, 'balance' => 40.0, 'status' => 'open',
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
        'emission_status' => 'STAMPED', 'total' => 25.0, 'balance' => 25.0, 'status' => 'open',
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

TestRunner::test('T5.1 full billing data (legal entity) creates a contact with name (never nameObject)', function (): void {
    alegra_test_reset();
    alegra_make_user(1, ['user_email' => 'acme@example.test', 'display_name' => 'Acme SA'], [
        'billing_alegra_kindofperson' => 'LEGAL_ENTITY',
        'billing_alegra_idtype' => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv' => '1',
        'billing_alegra_regime' => 'COMMON_REGIME',
        'billing_alegra_company' => 'Acme SA',
    ]);
    $order = make_invoice_order(600, 1, 'acme@example.test');

    make_orders()->create_invoice($order);

    $contact = alegra_mock_last_request('POST', '/contacts');
    TestRunner::assertTrue($contact !== null, 'a contact must be created');
    $body = $contact['body'] ?? [];
    TestRunner::assertArrayHasKey('identificationObject', $body, 'identificationObject');
    TestRunner::assertArrayHasKey('regime', $body, 'regime');
    TestRunner::assertSame('LEGAL_ENTITY', $body['kindOfPerson'] ?? null, 'kindOfPerson');
    TestRunner::assertArrayHasKey('name', $body, 'legal entity uses name');
    TestRunner::assertArrayNotHasKey('nameObject', $body, 'legal entity must NOT also send nameObject');

    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertTrue((string) ($invoice['client']['id'] ?? '') !== '', 'invoice must reference the created contact');
});

TestRunner::test('T5.2 full billing data (natural person) uses nameObject (never name)', function (): void {
    alegra_test_reset();
    alegra_make_user(3, ['user_email' => 'jane@example.test', 'display_name' => 'Jane Doe', 'first_name' => 'Jane', 'last_name' => 'Doe'], [
        'billing_alegra_kindofperson' => 'PERSON_ENTITY',
        'billing_alegra_idtype' => 'CC',
        'billing_alegra_identification' => '1234567890',
        'billing_alegra_regime' => 'SIMPLIFIED_REGIME',
        'billing_first_name' => 'Jane',
        'billing_last_name' => 'Doe',
    ]);
    $order = make_invoice_order(601, 3, 'jane@example.test');

    make_orders()->create_invoice($order);

    $body = alegra_mock_last_request('POST', '/contacts')['body'] ?? [];
    TestRunner::assertArrayHasKey('nameObject', $body, 'natural person uses nameObject');
    TestRunner::assertArrayNotHasKey('name', $body, 'natural person must NOT also send name');
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
        'billing_alegra_kindofperson' => 'LEGAL_ENTITY',
        'billing_alegra_idtype' => 'NIT',
        'billing_alegra_identification' => '900123456',
        'billing_alegra_dv' => '1',
        'billing_alegra_regime' => 'COMMON_REGIME',
        'billing_alegra_company' => 'Acme SA',
    ]);
    $order = make_invoice_order(604, 1, 'acme@example.test');

    make_orders()->create_invoice($order);

    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'always_generic must not create a contact');
    $invoice = alegra_mock_last_request('POST', '/invoices')['body'] ?? [];
    TestRunner::assertSame($cf, $invoice['client']['id'] ?? null, 'invoice must use Consumidor Final');
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
// T7 — Stamp failure recovery
// ===========================================================================
echo "\nT7 — Stamp failure (create_invoice with a 400 carrying a draft)\n";

TestRunner::test('T7.1 the draft invoice id from the error body is persisted and the note explains it', function (): void {
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
        'message' => 'La factura se creó pero no se pudo emitir',
        'error' => ['message' => 'DIAN rechazó la emisión'],
        'invoice' => [
            'id' => 'draft-t7-uuid',
            'number' => 'D-1',
            'numberTemplate' => ['fullNumber' => 'D-1'],
        ],
    ]);

    $result = make_orders()->create_invoice($order);

    TestRunner::assertInstanceOf(\WP_Error::class, $result, 'the API call must surface an error');
    TestRunner::assertSame('draft-t7-uuid', $order->get_meta('_alegra_invoice_id', true), 'the created draft id must be persisted');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the 400 must not be retried into a duplicate');

    $notes = implode("\n", $order->get_notes());
    TestRunner::assertStringContains('NO se emitió', $notes, 'the order note must say the invoice was created but not stamped');
});

exit(TestRunner::summary());
