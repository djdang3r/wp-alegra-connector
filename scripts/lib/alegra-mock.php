<?php
/**
 * Alegra API mock.
 *
 * Intercepts wp_remote_request/get/post and returns canned responses keyed by
 * (method, path). Every call is recorded so tests can assert on exactly what the
 * plugin sent. Supports one-shot or persistent failure injection.
 *
 * IDs are UUIDs on purpose: Alegra migrated its id columns to VARCHAR(36) on
 * 2025-01-06, so an int-id mock would hide the class of bug this harness exists
 * to catch.
 */

declare(strict_types=1);

if (!defined('ALEGRA_MOCK_BASE')) {
    define('ALEGRA_MOCK_BASE', 'https://api.alegra.test/v1');
}

/**
 * Documented WRITE enum for the item `type` field (POST/PUT /items).
 *
 * `simple` and `variant` are READ-only values returned by GET /items; sending
 * them on write is rejected by Alegra. This constant is the source of truth the
 * mock validates against.
 *
 * @see https://developer.alegra.com/reference/post_items.md
 */
const ALEGRA_MOCK_ITEM_WRITE_ENUM = ['product', 'service', 'variantParent', 'kit'];

$GLOBALS['alegra_mock_requests'] = [];
$GLOBALS['alegra_mock_state'] = ['contacts' => [], 'items' => [], 'categories' => [], 'invoices' => [], 'credit_notes' => [], 'payments' => []];
$GLOBALS['alegra_mock_failures'] = [];
$GLOBALS['alegra_mock_seq'] = 0;

function alegra_mock_reset(): void
{
    $GLOBALS['alegra_mock_requests'] = [];
    $GLOBALS['alegra_mock_state'] = ['contacts' => [], 'items' => [], 'categories' => [], 'invoices' => [], 'credit_notes' => [], 'payments' => []];
    $GLOBALS['alegra_mock_failures'] = [];
    $GLOBALS['alegra_mock_seq'] = 0;
}

function alegra_mock_uuid(string $prefix = 'aaaaaaaa'): string
{
    $GLOBALS['alegra_mock_seq']++;
    return sprintf('%s-0000-0000-0000-%012d', $prefix, $GLOBALS['alegra_mock_seq']);
}

function alegra_mock_seed_contact(string $id, array $data = []): void
{
    $GLOBALS['alegra_mock_state']['contacts'][$id] = array_merge(['id' => $id], $data);
}

function alegra_mock_seed_item(string $id, array $data = []): void
{
    $GLOBALS['alegra_mock_state']['items'][$id] = array_merge(['id' => $id], $data);
}

function alegra_mock_seed_invoice(string $id, array $data = []): void
{
    $GLOBALS['alegra_mock_state']['invoices'][$id] = array_merge(['id' => $id], $data);
}

function alegra_mock_seed_category(string $id, array $data = []): void
{
    $GLOBALS['alegra_mock_state']['categories'][$id] = array_merge(['id' => $id], $data);
}

/**
 * Inject a failure for (method, path).
 *
 * @param int   $code  HTTP status (400, 401, 429, 500, ...).
 * @param mixed $body  Decoded body to return.
 * @param int   $times How many consecutive calls fail; 0 = forever.
 */
function alegra_mock_fail(string $method, string $path, int $code, mixed $body, int $times = 0): void
{
    $GLOBALS['alegra_mock_failures'][strtoupper($method) . ' ' . $path] = [
        'code' => $code,
        'body' => $body,
        'times' => $times,
    ];
}

function alegra_mock_clear_failures(): void
{
    $GLOBALS['alegra_mock_failures'] = [];
}

/**
 * Recorded requests, optionally filtered.
 *
 * @return array<int, array{method:string,path:string,query:array,body:mixed}>
 */
function alegra_mock_requests(?string $method = null, ?string $path = null): array
{
    $out = [];
    foreach ($GLOBALS['alegra_mock_requests'] as $req) {
        if ($method !== null && $req['method'] !== strtoupper($method)) { continue; }
        if ($path !== null && $req['path'] !== $path) { continue; }
        $out[] = $req;
    }
    return $out;
}

function alegra_mock_last_request(string $method, string $path): ?array
{
    $all = alegra_mock_requests($method, $path);
    return $all ? end($all) : null;
}

function alegra_mock_count(string $method, string $path): int
{
    return count(alegra_mock_requests($method, $path));
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

function wp_remote_request($url, $args = [])
{
    return alegra_mock_dispatch((string) $url, is_array($args) ? $args : []);
}
function wp_remote_get($url, $args = [])
{
    $args = is_array($args) ? $args : [];
    $args['method'] = 'GET';
    return alegra_mock_dispatch((string) $url, $args);
}
function wp_remote_post($url, $args = [])
{
    $args = is_array($args) ? $args : [];
    $args['method'] = 'POST';
    return alegra_mock_dispatch((string) $url, $args);
}
function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}
function wp_remote_retrieve_body($response)
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}
function wp_remote_retrieve_header($response, $header)
{
    if (!is_array($response)) { return ''; }
    return $response['headers'][strtolower((string) $header)] ?? '';
}
function wp_remote_retrieve_headers($response)
{
    return is_array($response) ? ($response['headers'] ?? []) : [];
}

function alegra_mock_dispatch(string $url, array $args)
{
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $base = ALEGRA_MOCK_BASE;
    if (strpos($url, $base) === 0) {
        $relative = substr($url, strlen($base));
    } else {
        $parts = parse_url($url);
        $relative = (string) ($parts['path'] ?? '');
        $relative = preg_replace('#^.*?/v1#', '', $relative);
    }

    $query = [];
    $path = $relative;
    if (strpos($relative, '?') !== false) {
        [$path, $qs] = explode('?', $relative, 2);
        parse_str($qs, $query);
    }
    $path = '/' . ltrim($path, '/');
    if ($path === '/') { $path = '/'; }

    $body = null;
    if (isset($args['body']) && is_string($args['body'])) {
        $body = json_decode($args['body'], true);
        if ($body === null && trim($args['body']) !== '') {
            $body = $args['body'];
        }
    }

    $GLOBALS['alegra_mock_requests'][] = [
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'body' => $body,
    ];

    // Failure injection first.
    $key = $method . ' ' . $path;
    if (isset($GLOBALS['alegra_mock_failures'][$key])) {
        $fail = &$GLOBALS['alegra_mock_failures'][$key];
        if ($fail['times'] === 0 || $fail['times'] > 0) {
            if ($fail['times'] > 0) { $fail['times']--; }
            return alegra_mock_response($fail['code'], $fail['body']);
        }
    }

    // Schema validation. The mock used to accept ANY write body with a 200, so
    // a payload that violated the documented schema passed CI — that is how
    // `type: 'simple'` shipped. A write payload is now validated against the
    // documented schema and rejected with the same 400 Alegra returns, so an
    // invalid payload fails the suite instead of silently passing.
    $validation_error = alegra_mock_validate_write($method, $path, $body);
    if ($validation_error !== null) {
        return alegra_mock_response(400, $validation_error);
    }

    return alegra_mock_route($method, $path, $query, $body);
}

// ---------------------------------------------------------------------------
// Write-payload schema validation
//
// Source of truth: https://developer.alegra.com/reference/post_items.md,
// post_contacts.md, post_invoices.md, post_credit-notes.md.
//
// Adding an endpoint is cheap: add a rule to alegra_mock_write_validators().
// ---------------------------------------------------------------------------

/**
 * Write endpoints mapped to their validator callables.
 *
 * Each rule is `{method, pattern, validator}`. `pattern` is a PCRE matched
 * against the request path (use `{id}`-style segments as `[^/]+`). The
 * validator receives the decoded body and returns an error-body array when the
 * payload is invalid, or null when it conforms.
 *
 * @return array<int, array{method:string, pattern:string, validator:callable}>
 */
function alegra_mock_write_validators(): array
{
    return [
        ['method' => 'POST', 'pattern' => '#^/items$#',        'validator' => 'alegra_mock_validate_item_create'],
        ['method' => 'PUT',  'pattern' => '#^/items/[^/]+$#',  'validator' => 'alegra_mock_validate_item_update'],
        ['method' => 'POST', 'pattern' => '#^/contacts$#',     'validator' => 'alegra_mock_validate_contact'],
        ['method' => 'POST', 'pattern' => '#^/invoices$#',     'validator' => 'alegra_mock_validate_invoice'],
        ['method' => 'POST', 'pattern' => '#^/credit-notes$#', 'validator' => 'alegra_mock_validate_credit_note'],
    ];
}

/**
 * Validate a write request against the documented schema.
 *
 * @return array|null Error body to return (a 400), or null when valid.
 */
function alegra_mock_validate_write(string $method, string $path, mixed $body): ?array
{
    foreach (alegra_mock_write_validators() as $rule) {
        if ($rule['method'] !== $method) {
            continue;
        }
        if (!preg_match($rule['pattern'], $path)) {
            continue;
        }
        return ($rule['validator'])(is_array($body) ? $body : []);
    }

    return null;
}

/**
 * Realistic Alegra error body: `{code, message}` with the HTTP status in
 * `code`. Application-level errors (e.g. 2039) carry their own code inside the
 * message — the API client scans for it there.
 */
function alegra_mock_validation_error(string $message, int $code = 400): array
{
    return ['code' => $code, 'message' => $message];
}

function alegra_mock_validate_item_create(array $body): ?array
{
    return alegra_mock_validate_item($body, true);
}

function alegra_mock_validate_item_update(array $body): ?array
{
    return alegra_mock_validate_item($body, false);
}

/**
 * POST /items and PUT /items/{id}.
 *
 * The WRITE enum for `type` is product|service|variantParent|kit. `simple` and
 * `variant` are READ-only values returned by GET /items and must never be sent
 * on write — that mismatch is the bug that shipped.
 *
 * On create `name` and `price` are obligatorios; `price` is an array of
 * `{idPriceList, price}`. `inventory`, when present, must carry `unit` (string),
 * `unitCost` (number) and `initialQuantity` (number) — all documented
 * obligatorios. A `variantParent` must carry `variantAttributes` (min 1);
 * `subitems` is a kit-only field.
 */
function alegra_mock_validate_item(array $body, bool $is_create): ?array
{
    $write_enum = ALEGRA_MOCK_ITEM_WRITE_ENUM;

    if (array_key_exists('type', $body)) {
        if (!is_string($body['type']) || !in_array($body['type'], $write_enum, true)) {
            return alegra_mock_validation_error(
                'El campo type no es valido: ' . json_encode($body['type'])
                . '. Valores permitidos: ' . implode(', ', $write_enum)
            );
        }
    }

    if ($is_create) {
        if (empty($body['name']) || !is_string($body['name'])) {
            return alegra_mock_validation_error('El campo name es obligatorio');
        }
        if (!isset($body['price']) || !is_array($body['price']) || $body['price'] === []) {
            return alegra_mock_validation_error('El campo price es obligatorio y debe ser un arreglo con al menos una lista de precio');
        }
        foreach (array_values($body['price']) as $i => $price) {
            if (!is_array($price) || !array_key_exists('idPriceList', $price) || !array_key_exists('price', $price)) {
                return alegra_mock_validation_error('El campo price[' . $i . '] debe contener idPriceList y price');
            }
        }
    }

    if (array_key_exists('inventory', $body)) {
        $inventory = $body['inventory'];
        if (!is_array($inventory)) {
            return alegra_mock_validation_error('El campo inventory debe ser un objeto');
        }
        foreach (['unit', 'unitCost', 'initialQuantity'] as $field) {
            if (!array_key_exists($field, $inventory)) {
                return alegra_mock_validation_error('El campo inventory.' . $field . ' es obligatorio cuando se envia inventory');
            }
        }
        if (!is_string($inventory['unit'])) {
            return alegra_mock_validation_error('El campo inventory.unit debe ser texto');
        }
        if (!is_numeric($inventory['unitCost'])) {
            return alegra_mock_validation_error('El campo inventory.unitCost debe ser numerico');
        }
        if (!is_numeric($inventory['initialQuantity'])) {
            return alegra_mock_validation_error('El campo inventory.initialQuantity debe ser numerico');
        }
    }

    if (($body['type'] ?? null) === 'variantParent') {
        if (empty($body['variantAttributes']) || !is_array($body['variantAttributes'])) {
            return alegra_mock_validation_error('El campo variantAttributes es obligatorio para type=variantParent (subitems solo aplica a type=kit)');
        }
    }

    return null;
}

/**
 * POST /contacts.
 *
 * `name` and `nameObject` are mutually exclusive (Alegra error 2039) and at
 * least one is required. When `identificationObject` is present it must carry
 * `type` and `number`.
 */
function alegra_mock_validate_contact(array $body): ?array
{
    $has_name = array_key_exists('name', $body) && $body['name'] !== null && $body['name'] !== '';
    $has_name_object = array_key_exists('nameObject', $body) && !empty($body['nameObject']);

    if ($has_name && $has_name_object) {
        return alegra_mock_validation_error('2039: los campos name y nameObject son mutuamente excluyentes');
    }
    if (!$has_name && !$has_name_object) {
        return alegra_mock_validation_error('El campo name o nameObject es obligatorio');
    }

    if (array_key_exists('identificationObject', $body)) {
        $identification = $body['identificationObject'];
        if (!is_array($identification) || empty($identification['type']) || !isset($identification['number']) || $identification['number'] === '') {
            return alegra_mock_validation_error('El campo identificationObject debe contener type y number');
        }
    }

    return null;
}

/**
 * POST /invoices. Required: client, items (non-empty, every line with an id),
 * date and dueDate (post_invoices.md).
 */
function alegra_mock_validate_invoice(array $body): ?array
{
    if (empty($body['client']) || !is_array($body['client']) || empty($body['client']['id'])) {
        return alegra_mock_validation_error('El campo client es obligatorio');
    }
    if (empty($body['items']) || !is_array($body['items'])) {
        return alegra_mock_validation_error('El campo items es obligatorio y no puede estar vacio');
    }
    foreach (array_values($body['items']) as $i => $item) {
        if (!is_array($item) || empty($item['id'])) {
            return alegra_mock_validation_error('El campo items[' . $i . '].id es obligatorio');
        }
    }
    if (empty($body['date']) || !is_string($body['date'])) {
        return alegra_mock_validation_error('El campo date es obligatorio');
    }
    if (empty($body['dueDate']) || !is_string($body['dueDate'])) {
        return alegra_mock_validation_error('El campo dueDate es obligatorio');
    }

    return null;
}

/**
 * POST /credit-notes. Required: date, client, items (non-empty). When
 * `invoices` is present every entry must carry `{id, amount}`
 * (post_credit-notes.md).
 */
function alegra_mock_validate_credit_note(array $body): ?array
{
    if (empty($body['client']) || !is_array($body['client']) || empty($body['client']['id'])) {
        return alegra_mock_validation_error('El campo client es obligatorio');
    }
    if (empty($body['items']) || !is_array($body['items'])) {
        return alegra_mock_validation_error('El campo items es obligatorio y no puede estar vacio');
    }
    if (empty($body['date']) || !is_string($body['date'])) {
        return alegra_mock_validation_error('El campo date es obligatorio');
    }
    if (array_key_exists('invoices', $body)) {
        if (!is_array($body['invoices'])) {
            return alegra_mock_validation_error('El campo invoices debe ser un arreglo');
        }
        foreach (array_values($body['invoices']) as $i => $invoice) {
            if (!is_array($invoice) || empty($invoice['id']) || !array_key_exists('amount', $invoice)) {
                return alegra_mock_validation_error('El campo invoices[' . $i . '] debe contener id y amount');
            }
        }
    }

    return null;
}

function alegra_mock_response(int $code, mixed $body): array
{
    return [
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
        'response' => ['code' => $code, 'message' => $code >= 400 ? 'Error' : 'OK'],
    ];
}

function alegra_mock_route(string $method, string $path, array $query, mixed $body): array
{
    // --- GET single-segment collections ---
    if ($method === 'GET' && $path === '/company') {
        return alegra_mock_response(200, [
            'id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'Harness Co',
            'country' => 'CO',
            'email' => 'harness@example.test',
        ]);
    }
    if ($method === 'GET' && $path === '/number-templates') {
        return alegra_mock_response(200, [[
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Factura electrónica',
            'documentType' => 'invoice',
            'isElectronic' => true,
            'isDefault' => true,
        ]]);
    }
    if ($method === 'GET' && $path === '/taxes') {
        return alegra_mock_response(200, [[
            'id' => '22222222-0000-0000-0000-000000000001',
            'name' => 'IVA 19%',
            'percentage' => 19,
        ]]);
    }
    if ($method === 'GET' && $path === '/item-categories') {
        $all = array_values($GLOBALS['alegra_mock_state']['categories']);
        $start = (int) ($query['start'] ?? 0);
        $limit = (int) ($query['limit'] ?? 30);
        return alegra_mock_response(200, array_slice($all, $start, $limit));
    }
    if ($method === 'GET' && preg_match('#^/item-categories/([^/]+)$#', $path, $m)) {
        $cat = $GLOBALS['alegra_mock_state']['categories'][$m[1]] ?? null;
        return $cat ? alegra_mock_response(200, $cat) : alegra_mock_response(404, ['message' => 'Category not found']);
    }
    if ($method === 'GET' && $path === '/warehouses') {
        return alegra_mock_response(200, []);
    }
    if ($method === 'GET' && $path === '/contacts') {
        return alegra_mock_response(200, alegra_mock_filter_contacts($query));
    }
    if ($method === 'GET' && $path === '/items') {
        return alegra_mock_response(200, alegra_mock_filter_items($query));
    }
    if ($method === 'GET' && $path === '/invoices') {
        return alegra_mock_response(200, alegra_mock_filter_invoices($query));
    }
    if ($method === 'GET' && $path === '/payments') {
        return alegra_mock_response(200, array_values($GLOBALS['alegra_mock_state']['payments']));
    }
    if ($method === 'GET' && $path === '/credit-notes') {
        return alegra_mock_response(200, array_values($GLOBALS['alegra_mock_state']['credit_notes']));
    }

    // --- GET /terms/{id} ---
    if ($method === 'GET' && preg_match('#^/terms/([^/]+)$#', $path, $m)) {
        return alegra_mock_response(200, ['id' => $m[1], 'name' => 'Net 30', 'days' => 30]);
    }

    // --- GET /contacts/{id}, /items/{id}, /invoices/{id} ---
    if ($method === 'GET' && preg_match('#^/contacts/([^/]+)$#', $path, $m)) {
        $c = $GLOBALS['alegra_mock_state']['contacts'][$m[1]] ?? null;
        return $c ? alegra_mock_response(200, $c) : alegra_mock_response(404, ['message' => 'Contact not found']);
    }
    if ($method === 'GET' && preg_match('#^/items/([^/]+)$#', $path, $m)) {
        $i = $GLOBALS['alegra_mock_state']['items'][$m[1]] ?? null;
        return $i ? alegra_mock_response(200, $i) : alegra_mock_response(404, ['message' => 'Item not found']);
    }
    if ($method === 'GET' && preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
        $inv = $GLOBALS['alegra_mock_state']['invoices'][$m[1]] ?? null;
        return $inv ? alegra_mock_response(200, $inv) : alegra_mock_response(404, ['message' => 'Invoice not found']);
    }

    // --- POST ---
    if ($method === 'POST' && $path === '/contacts') {
        $id = alegra_mock_uuid('cccccccc');
        $stored = array_merge(is_array($body) ? $body : [], ['id' => $id]);
        $GLOBALS['alegra_mock_state']['contacts'][$id] = $stored;
        return alegra_mock_response(200, $stored);
    }
    if ($method === 'POST' && $path === '/items') {
        $id = alegra_mock_uuid('dddddddd');
        $stored = array_merge(is_array($body) ? $body : [], ['id' => $id]);
        $GLOBALS['alegra_mock_state']['items'][$id] = $stored;
        return alegra_mock_response(200, $stored);
    }
    if ($method === 'POST' && $path === '/invoices') {
        $id = alegra_mock_uuid('eeeeeeee');
        $number = 'FV-' . $GLOBALS['alegra_mock_seq'];
        $stored = array_merge(is_array($body) ? $body : [], [
            'id' => $id,
            'number' => $number,
            'numberTemplate' => ['id' => '11111111-1111-1111-1111-111111111111', 'fullNumber' => $number],
            'status' => 'open',
            'total' => alegra_mock_body_total($body),
            'balance' => alegra_mock_body_total($body),
        ]);
        $GLOBALS['alegra_mock_state']['invoices'][$id] = $stored;
        return alegra_mock_response(200, $stored);
    }
    if ($method === 'POST' && $path === '/credit-notes') {
        $id = alegra_mock_uuid('ffffffff');
        $stored = array_merge(is_array($body) ? $body : [], ['id' => $id]);
        $GLOBALS['alegra_mock_state']['credit_notes'][$id] = $stored;
        return alegra_mock_response(200, $stored);
    }
    if ($method === 'POST' && $path === '/payments') {
        $id = alegra_mock_uuid('99999999');
        $stored = array_merge(is_array($body) ? $body : [], ['id' => $id, 'number' => 'P-' . $GLOBALS['alegra_mock_seq']]);
        $GLOBALS['alegra_mock_state']['payments'][$id] = $stored;
        return alegra_mock_response(200, $stored);
    }
    if ($method === 'POST' && preg_match('#^/invoices/([^/]+)/void$#', $path, $m)) {
        return alegra_mock_response(200, ['id' => $m[1], 'status' => 'void']);
    }
    if ($method === 'POST' && preg_match('#^/invoices/([^/]+)/open$#', $path, $m)) {
        if (isset($GLOBALS['alegra_mock_state']['invoices'][$m[1]])) {
            $GLOBALS['alegra_mock_state']['invoices'][$m[1]]['status'] = 'open';
        }
        return alegra_mock_response(200, ['id' => $m[1], 'status' => 'open']);
    }

    // --- PUT ---
    if ($method === 'PUT') {
        return alegra_mock_response(200, array_merge(is_array($body) ? $body : [], ['id' => basename($path)]));
    }
    if ($method === 'DELETE') {
        return alegra_mock_response(200, ['deleted' => true]);
    }

    return alegra_mock_response(404, ['message' => 'Unhandled endpoint ' . $method . ' ' . $path]);
}

function alegra_mock_filter_contacts(array $query): array
{
    $contacts = array_values($GLOBALS['alegra_mock_state']['contacts']);
    if (!empty($query['email'])) {
        $email = strtolower((string) $query['email']);
        $contacts = array_values(array_filter($contacts, static function ($c) use ($email) {
            return strtolower((string) ($c['email'] ?? '')) === $email;
        }));
    }
    if (!empty($query['identification'])) {
        $ident = (string) $query['identification'];
        $contacts = array_values(array_filter($contacts, static function ($c) use ($ident) {
            $obj = $c['identificationObject'] ?? null;
            $num = is_array($obj) ? (string) ($obj['number'] ?? '') : (string) ($c['identification'] ?? '');
            return $num === $ident;
        }));
    }
    return $contacts;
}

function alegra_mock_filter_items(array $query): array
{
    $items = array_values($GLOBALS['alegra_mock_state']['items']);
    if (!empty($query['reference'])) {
        $ref = (string) $query['reference'];
        $items = array_values(array_filter($items, static function ($i) use ($ref) {
            return (string) ($i['reference'] ?? '') === $ref;
        }));
    }
    return $items;
}

function alegra_mock_filter_invoices(array $query): array
{
    $invoices = array_values($GLOBALS['alegra_mock_state']['invoices']);
    if (!empty($query['client_id'])) {
        $cid = (string) $query['client_id'];
        $invoices = array_values(array_filter($invoices, static function ($inv) use ($cid) {
            return (string) ($inv['client']['id'] ?? '') === $cid;
        }));
    }
    return $invoices;
}

function alegra_mock_body_total(mixed $body): float
{
    if (!is_array($body)) { return 0.0; }
    $total = 0.0;
    foreach (($body['items'] ?? []) as $item) {
        if (is_array($item)) {
            $line = (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 0);
            // `discount` is a percentage (post_invoices.md).
            if (isset($item['discount'])) {
                $line *= (1 - ((float) $item['discount'] / 100));
            }
            $total += $line;
        }
    }
    return round($total, 2);
}
