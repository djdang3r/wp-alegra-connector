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

TestRunner::test('T10.3 AC-44 a variable product is pushed as variantParent, never kit', function (): void {
    alegra_test_reset();
    alegra_make_product(20, ['name' => 'Var', 'sku' => 'VAR-S', 'type' => 'variation', 'regular_price' => '5']);
    alegra_make_product(21, ['name' => 'Parent', 'sku' => 'PAR', 'type' => 'variable', 'regular_price' => '10', 'children' => [20]]);

    make_products()->sync_to_alegra(wc_get_product(21));

    $body = alegra_mock_last_request('POST', '/items')['body'] ?? [];
    TestRunner::assertSame('variantParent', $body['type'] ?? null, 'create must use variantParent');
    TestRunner::assertStringNotContains('"type":"kit"', json_encode($body), 'a variable product must never be created as a kit');
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
        alegra_mock_seed_item('c-' . $i, ['name' => 'P' . $i, 'reference' => 'C-' . $i, 'type' => 'simple', 'category' => $cat]);
    }
    $GLOBALS['alegra_category_lookups'] = 0;

    make_products()->import_from_alegra();

    TestRunner::assertSame(1, (int) $GLOBALS['alegra_category_lookups'], 'four products sharing a category must resolve it with one lookup');
});

exit(TestRunner::summary());
