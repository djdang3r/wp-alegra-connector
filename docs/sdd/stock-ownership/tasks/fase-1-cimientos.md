# Tareas micro-detalladas — Fase 1 (cimientos: harness D5 + opciones + esqueletos + sección `T30`)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único de stock configurable + poll sin re-inflación + cola de facturas fallidas + reconciliación WC↔Alegra) |
| Documentos base | `proposal.md` §3.1 · `spec.md` (REQ-OWN-02/07, REQ-POLL-02/03/06, REQ-QUEUE-01/02/03/07, REQ-RECON-01, NFR-03/04/05) · `design.md` §2/§3/§4/§6/§9/§10 · `tasks.md` §4 + §7 (Fase 1) |
| Alcance de este archivo | **Fase 1 — `T1.1` … `T1.10`** (10 tareas) |
| Naturaleza | **Cimientos + harness.** `T1.1`–`T1.4` son costuras de test (`scripts/`); `T1.5`–`T1.8` son producto/esqueletos; `T1.9`/`T1.10` cierran. |
| Depende de | **Ningún gate (Fase 0).** Arranca ya. |
| DoD de la fase | H-A/H-B/H-C/H-E aplicados; las 8 opciones nuevas sembradas; las 3 clases nuevas cargan por PSR-4; sección `T30` creada; `bash scripts/exec-test.sh` verde; `bash scripts/smoke-test.sh` verde. |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`) · `bash scripts/smoke-test.sh` (→ `scripts/smoke-load.php`) |
| Baseline verificado | `bash scripts/exec-test.sh` ⇒ **1990 assertions, 0 failed** (re-corrido en HEAD para esta fase) |
| Versión objetivo | **2.7.0** |

> **Convención de IDs de test (canónica del SDD).** Todo test nuevo de esta fase se nombra
> **`T30.1{n}`** (`T30.11`…`T30.17` + `T30.13b`), según `T30.{fase}{n}` (`tasks.md:33`). `T1`–`T29` ya están usados;
> la sección `// === stock-ownership (2.7.0) ===` se crea en `T1.1` junto con el primer test y `T1.9` la
> formaliza/verifica. Los IDs de **tarea** (`T1.1`…`T1.10`) no cambian.

> **Regla de oro (prove-it-catches, obligatoria).** Después de que un test pase: **revertir el fix** →
> `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Cada tarea de
> abajo indica el fix exacto a revertir. Sin el revert→rojo, el test no se acepta.

> **Todos los `file:line` fueron re-verificados leyendo HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

---

## Correcciones de cita y hallazgos (verificados en HEAD)

| # | Claim (design/spec/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | H1: `wc_update_product_stock()` "ausente de `wp-stubs.php`" | **RESUELTO.** El stub **existe**: `wp-stubs.php:1489-1504` (setea stock, deriva `stock_status` con `alegra_mock_derive_stock_status()` `:1464-1481`, dispara hooks). | **No se toca.** D5 lo consume. |
| C2 | H4: "sin ruta `/inventory-adjustments`" | **RESUELTO.** Validador `alegra-mock.php:614-637` (regla en `:337`), `POST` `:880-914` (aplica el delta en `:896`), `GET` con filtro `item_id` `:765-782`. | **No se toca** salvo H-B. |
| C3 | H3/H5/FIX-13/C10 (gaps de `sync-reliability`, no de este cambio) | **YA IMPLEMENTADOS:** modo CONTAINS + paginación (`alegra-mock.php:986-1008`, setter `:67-70`, globals `:36/:46`); chequeo opt-in de `client.id` (`:77-80`, `:583-588`); `GET /items` pagina en **ambas** ramas (`:740-755`); `register_shutdown_function` + `alegra_mock_run_shutdown_callbacks()` (`scripts/lib/ns-shutdown.php`, global `alegra-mock.php:48`). | **NO reimplementar.** Este cambio no los reabre. |
| C4 | H-A: `POST /invoices` no modela stock por factura | **CONFIRMADO.** `POST /invoices` (`alegra-mock.php:854-866`) fuerza `'status' => 'open'` en `:861` y **no** descuenta `availableQuantity`. `PUT /invoices/{id}` `:928-936`; `POST /invoices/{id}/open` `:919-924`. | **`T1.1` es real.** |
| C5 | H-B: `GET /inventory-adjustments` no filtra `reference`; el POST guarda la del ítem | **CONFIRMADO.** El `GET` sólo filtra `item_id` (`:765-782`); `:903` guarda `'reference' => (string) ($item['reference'] ?? '')` (la del **ítem**, no la del payload). | **`T1.2` es real.** |
| C6 | H-C: `wc_get_orders` sin `paginate` (`wp-stubs.php:1568-1611`) | **CONFIRMADO** (cierre de función en `:1612`). Aplica `meta_key`/`meta_query`/`status`/`customer_id`/`limit`/`offset`/`return=ids`; **nunca** devuelve objeto con `->total`. | **`T1.3` es real.** |
| C7 | H-E: `WC_Order` stub `get_items()`/`get_product()`/`is_paid()` (`wp-stubs.php:1317-1420`, `get_items :1377`, `is_paid :1388`) | **PARCIAL.** La clase va `:1317-1422`; `get_items()` `:1377` e `is_paid()` `:1388` **ya existen**; el meta CRUD ya está (`get_meta`/`update_meta_data`/`meta_exists`/`save`, `:1372-1376`). **Falta `WC_Order_Item::get_product()`** (`WC_Order_Item` es `:1207-1232`, no tiene `get_product`). | **`T1.4` sólo agrega `get_product()`.** |
| C8 | `design.md` §3.6 / K-H4: payload `{date, reference, items:[{id,type,quantity,unitCost}]}` vs viejo `{date, type, quantity, item:{id}}` | El mock valida el shape **DOCUMENTADO** `{date, items:[{id,type,quantity,unitCost}], warehouse?}` (`:614-637`, `:880-914`), confirmado en `sync-reliability/phase0-results.md:64-93`. El shape viejo `item:{id}` es **rechazado** (test `T29.14`, `exec-test.php:8125-8127`). | `T1.2` guarda el `reference` **top-level**; `T3.4` debe respetar el shape documentado. |
| C9 | `tasks.md` T1.5 / `sync-reliability`: opciones de inventario en `uninstall.php:75-77` | **DESACTUALIZADO.** El bloque de inventario real es `uninstall.php:77-85`. | `T1.5` inserta las 8 nuevas **después** de `:85`. |
| C10 | `Write_Gate` inventory (`:43,:58,:79`) | **YA implementado** por `sync-reliability` (`ENTITY_OPTIONS['inventory'] :43`, `ENTITY_DEFAULTS['inventory'] :58`, `ENTITY_PATTERNS :79`). | **`T1.5` NO toca `Write_Gate`** salvo `maybe_migrate()` (`:182`). |
| C11 | `tasks.md` T1.9: sección `T30` en `scripts/exec-test.php` | **Correcto.** La sección `// === sync-reliability (2.6.0) ===` está en `:8031`; el `exit(TestRunner::summary())` en `:9758`. La sección `T30` va **antes** de `:9758`. | `T1.9`. |
| C12 | `tasks.md` T1.10: patrón `smoke-load.php:196-200` | **Aproximado.** El patrón `check('...', class_exists(...), '— ...')` está en `:196-212`. Insertar las 3 nuevas **después** de `:212`. | `T1.10`. |
| C13 | `tasks.md` T1.5: `$defaults :414-468`, `$non_autoload :474-507`, loop `:509-513` | **Confirmado exacto.** | `T1.5`. |
| C14 | `Inventory_Pusher` ledger `:47-76`, `owner() :85-91`, `adjustment_already_exists :303-335`, `build_adjustment_payload :340-358`, `set_synced :214/:244/:271` | **Confirmado exacto.** | Contexto de `T1.1`/`T1.2`/`T1.5`. |

**Citas confirmadas exactas (no requieren corrección):** `alegra-mock.php:31-51` (init + `alegra_mock_reset`),
`:88-101` (`seed_contact`/`seed_item`/`seed_invoice`), `:152-159` (`alegra_mock_fail`), `:237-307`
(`alegra_mock_dispatch`), `:337` (registro del validador), `:674-681` (`alegra_mock_response`),
`:919-936` (open/PUT), `:986-1008` (`filter_contacts`), `:1010-1065` (`filter_items`); `wp-stubs.php:1207-1232`
(`WC_Order_Item`), `:1234-1298` (`WC_Product`), `:1317-1422` (`WC_Order`), `:1448-1457` (`wc_get_order`/`wc_get_product`),
`:1464-1504` (`derive_stock_status` + `wc_update_product_stock`), `:1513-1566` (meta_query stub),
`:1568-1612` (`wc_get_orders`); `test-framework.php:144-222` (`alegra_test_reset`), `:238-301`
(factories); `exec-test.php:53-80` (factories de API/Orders/Products), `:8031` (sección T29),
`:9758` (`exit(TestSummary)`); `alegra-connector.php:95-125` (autoloader PSR-4), `:414-468`
(`$defaults`), `:474-507` (`$non_autoload`), `:509-513` (loop); `uninstall.php:77-85` (opciones de
inventario); `Write_Gate.php:182` (`maybe_migrate`); `.distignore:15` excluye `scripts/`.

---

## Contrato canónico de la fase (lo define esta fase; Fases 2/3/4/5/7 lo consumen)

### K-HA — Modelo de stock por factura en el mock (lo define `T1.1`)

```php
// scripts/lib/alegra-mock.php (función global)
function alegra_mock_set_draft_moves_stock(bool $on): void;   // default false (G1 Rama A)
function alegra_mock_apply_invoice_stock(string $invoice_id, mixed $body): void; // idempotente por invoice id
```

- `POST /invoices`: **respeta** el `status` del body; si viene ausente, default **`'open'`** (preserva
  el baseline 1990; el plugin **siempre** manda `status`, así que producción no cambia).
- Si `status ∈ {open, paid}` **o** `draft_moves_stock === true`: por cada `items[]` con `id` de un ítem
  **con `inventory`**, resta `quantity` a `availableQuantity` (clamp a `>= 0`). **Idempotente**: un
  mismo `invoice_id` mueve stock **una sola vez** (`$GLOBALS['alegra_mock_invoices_stock_moved']`).
- `PUT /invoices/{id}` y `POST /invoices/{id}/open`: al pasar a `open`/`paid`, aplican stock por
  primera vez.
- `draft` (sin el flag) **no** mueve stock (modela G1 Rama A). El flag modela la Rama B.
- El flag y el set de invoices movidos se resetean en `alegra_mock_reset()`.

### K-HB — `reference` en ajustes (lo define `T1.2`)

- `POST /inventory-adjustments`: guarda el `reference` **top-level del payload** en el stored
  (`$stored['reference']`), además del `reference` por línea (que sigue siendo el del ítem).
- `GET /inventory-adjustments`: acepta el query `reference` y filtra por el `reference` top-level del
  stored.
- El validador documentado (`:614-637`) **no** rechaza campos top-level extra ⇒ el `reference` pasa.
- Sin esto, `REQ-POLL-06` es vacuo. `T3.4` (Fase 3) consume este seam.

### K-HC — `wc_get_orders` con `paginate` (lo define `T1.3`)

```php
wc_get_orders(['paginate' => true, 'limit' => 2, 'paged' => 1, 'return' => 'objects'])
  // => object{ orders: WC_Order[], total: int, max_num_pages: int }
```

- `total` = conjunto **filtrado** (tras `meta_query`/`status`/`customer_id`), **antes** de paginar.
- `paged` (1-based) y `offset` (explícito) soportados; `return=ids` sigue devolviendo **array**.
- Sin `paginate`, devuelve el array de siempre (compatibilidad total).
- **Motor `meta_query` extendido (cierre de B6).** `alegra_stub_meta_clause_matches()`
  (`wp-stubs.php:1541-1566`) hoy sólo soporta `NOT EXISTS`/`EXISTS`/`!=`/`LIKE`/`=`. `T1.3` agrega
  `IN`/`NOT IN` y comparaciones `<`/`<=`/`>`/`>=` (`type=NUMERIC`) y `DATETIME`/`DATE`; el combinador
  `relation=OR` con `NOT EXISTS` ya funciona (`alegra_stub_meta_query_matches()`, `:1513-1539`). Sin esto,
  `Invoice_Queue` (`compare=IN`) y `retry_failed_invoices` (`<`/`<=`/DATETIME) **no son ejecutables** en el
  harness ⇒ `T30.49`/`T30.61`/`T30.62`/`T30.411` (Fase 4) no corren (B6 de `REVIEW-momus.md`).
- Consumido por el badge (`T4.11`) y la pantalla (`T4.10`/`T6.3`).

### K-HE — Línea de pedido resoluble (lo define `T1.4`)

```php
WC_Order_Item::get_product(): \WC_Product|false   // resuelve por variation_id > product_id
```

- `get_items()` (`:1377`) e `is_paid()` (`:1388`) **ya existen**; el meta CRUD **ya existe**
  (`:1372-1376`). `T1.4` sólo agrega `get_product()`.
- Habilita `baseline_products_for_invoice()` (`T3.3`, REQ-POLL-02).

### K-OPT — Opciones nuevas (lo define `T1.5`)

| Opción | Tipo | Default | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|---|
| `alegra_connector_stock_owner` | enum `auto\|invoice\|adjustment` | **`auto`** | sí | sí | sí |
| `alegra_connector_invoice_retry_enabled` | bool | `false` | sí | sí | sí |
| `alegra_connector_invoice_retry_max_attempts` | int | `5` | sí | sí | sí |
| `alegra_connector_invoice_retry_batch` | int | `20` | sí | sí | sí |
| `alegra_connector_invoice_failures_count` | int | `0` | no (interno) | sí | sí |
| `alegra_connector_invoice_failures_hash` | string | `''` | no (interno) | sí | sí |
| `alegra_connector_stock_divergence` | array | `[]` | no (interno) | sí | sí |
| `alegra_connector_stock_owner_epoch` | int | `0` | no (interno) | sí | sí |

> **`auto` = condición DOBLE (C1 del diseño).** `auto` **NO** es "invoice si `push_orders_enabled`":
> es `push_orders_enabled && open_invoice_on_paid ? invoice : adjustment` (`Inventory_Pusher.php:87-90`).
> Lo implementa `T2.1`; `T1.5` sólo siembra el default `auto`.
>
> **Filtro G3 (CORRECCIONES C5/C29).** G3 es una **decisión de build**, no del comerciante: la rama del
> filtro por `reference` se fija con la **constante de clase** `Inventory_Pusher::USE_REFERENCE_FILTER`
> (`true` = Rama A, `false` = Rama B; la define `T3.4`), **no** con `get_option(...)`. Por eso **no** se
> siembra ninguna opción `alegra_connector_inventory_reference_filter` y el conteo de opciones nuevas
> queda en **8** (`design.md` §3.6/§10). `T3.4` lee la constante, no la opción.

### K-SKEL — Esqueletos de las 3 clases nuevas (los definen `T1.6`/`T1.7`/`T1.8`)

```php
namespace Alegra\Connector\Sync;

final class Invoice_Failure {                       // T1.6 (T4.1/T4.2 completan)
    public static function classify(array|\WP_Error $result): array;
    public static function persist(\WC_Order $order, array $classification): void;
    public static function clear(\WC_Order $order): void;
    public static function get(\WC_Order $order): array;
}

final class Invoice_Queue {                         // T1.7 (T4.10/T4.11 completan)
    public static function query(array $filters = [], int $paged = 1, int $per_page = 20): array;
    public static function refresh_count(): int;
    public static function count(): int;
}

final class Stock_Divergence {                      // T1.8 (T5.1/T5.2 completan)
    public static function record(int $product_id, array $entry): void;
    public static function report(int $limit = 20, int $offset = 0): array;
    public static function clear(int $product_id): void;
}
```

- Resuelven por el autoloader PSR-4 (`alegra-connector.php:95-125`, subdir `Sync`) y se validan en
  `T1.10` (`smoke-load.php`).

### K-META — Meta `_alegra_stock_adjusted_at` (lo define `T2.2`; acá sólo se documenta)

- Meta **por producto** (timestamp) que el pusher escribe en la rama `ok`/`already_applied` de
  `push_delta()`. La lee la guarda manual (`T2.5`/`T2.6`) comparando contra la fecha de creación del
  pedido (`design.md` §2.2.1, DR21).
- **El harness ya lo soporta:** el CRUD de post meta (`get_post_meta`/`update_post_meta`/
  `delete_post_meta`, `wp-stubs.php:286-305`) opera sobre `$GLOBALS['wp_postmeta']`, que
  `alegra_test_reset()` limpia (`test-framework.php:148`). **No se agrega stub.**
- **No es de Fase 1**: lo escribe `T2.2`. Fase 1 sólo garantiza que el harness puede leerlo/escribirlo.

### K-WPMAIL — `wp_mail` NO se usa (decisión)

- El diseño **no** usa email (`design.md` §4.7): la "notificación" pedida se satisface con aviso +
  lista + badge. `grep wp_mail` = **0** en `includes/`, `admin/`, `public/` y **0** en
  `scripts/lib/wp-stubs.php`. **No se agrega stub.** (H-D = N/A.)

---

### T1.1 — H-A: el mock modela stock por factura · `scripts/`

**Objetivo**: que `POST /invoices` respete el `status` del body y mueva el stock del ítem una sola vez
al abrirse/pagarse, para poder probar que el poll no re-infla (D2) y que la factura mueve exactamente
una vez (D1). Sin esto, D1/D2 no son testeables (DR16).

**Descripción técnica**: hoy `POST /invoices` (`alegra-mock.php:854-866`) fuerza `'status' => 'open'`
en `:861` y **nunca** toca `inventory.availableQuantity`. `PUT /invoices/{id}` (`:928-936`) sólo hace
`array_merge` y `POST /invoices/{id}/open` (`:919-924`) sólo cambia el status. El modelo de stock vive
en `$GLOBALS['alegra_mock_state']['items'][id]['inventory']['availableQuantity']` (sembrado con
`alegra_mock_seed_item()`, `:93-96`). Cubre **REQ-POLL-02/03, REQ-OWN-01**; consumido por `T2.3`,
`T3.1`, `T3.3`. La premisa "draft no mueve" es la Rama A esperada de G1 (`T0.2`); el flag
`alegra_mock_set_draft_moves_stock(true)` modela la Rama B.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/alegra-mock.php`.

- **1) Globals + reset.** En la init (`:31-36`) agregar tras `$GLOBALS['alegra_mock_contact_identification_mode'] = 'exact';` (`:36`):

  ```php
  $GLOBALS['alegra_mock_draft_moves_stock'] = false;
  $GLOBALS['alegra_mock_invoices_stock_moved'] = [];
  ```

  Y en `alegra_mock_reset()` (`:38-51`), junto a los demás resets (después de `:46`):

  ```php
  $GLOBALS['alegra_mock_draft_moves_stock'] = false;
  $GLOBALS['alegra_mock_invoices_stock_moved'] = [];
  ```

- **2) Setter nuevo** (junto a `alegra_mock_set_invoice_client_check()`, `:77-80`):

  ```php
  /**
   * H-A / G1 Rama B: cuando está ON, una factura `draft` también mueve stock.
   * OFF por default (modela la Rama A: el borrador no mueve). Se resetea en
   * alegra_mock_reset().
   */
  function alegra_mock_set_draft_moves_stock(bool $on): void
  {
      $GLOBALS['alegra_mock_draft_moves_stock'] = $on;
  }
  ```

- **3) Helper de stock** (junto a `alegra_mock_seed_invoice()`, `:98-101`):

  ```php
  /**
   * H-A: aplica el movimiento de stock de una factura. Descuenta
   * `availableQuantity` por línea de ítem inventariable, UNA sola vez por
   * invoice id. `draft` no mueve (Rama A); con draft_moves_stock=true sí.
   */
  function alegra_mock_apply_invoice_stock(string $invoice_id, mixed $body): void
  {
      if ($invoice_id === '' || !is_array($body)) { return; }
      $status = (string) ($body['status'] ?? 'open');
      $moves  = in_array($status, ['open', 'paid'], true)
          || !empty($GLOBALS['alegra_mock_draft_moves_stock']);
      if (!$moves) { return; }
      if (!empty($GLOBALS['alegra_mock_invoices_stock_moved'][$invoice_id])) { return; }
      $GLOBALS['alegra_mock_invoices_stock_moved'][$invoice_id] = true;

      foreach ((array) ($body['items'] ?? []) as $line) {
          if (!is_array($line)) { continue; }
          $item_id = (string) ($line['id'] ?? '');
          if ($item_id === '' || !isset($GLOBALS['alegra_mock_state']['items'][$item_id])) { continue; }
          if (!array_key_exists('inventory', $GLOBALS['alegra_mock_state']['items'][$item_id])) { continue; }
          $qty     = (int) ($line['quantity'] ?? 0);
          $current = (int) ($GLOBALS['alegra_mock_state']['items'][$item_id]['inventory']['availableQuantity'] ?? 0);
          $GLOBALS['alegra_mock_state']['items'][$item_id]['inventory']['availableQuantity'] = max(0, $current - $qty);
      }
  }
  ```

- **4) `POST /invoices` — DESPUÉS** (reemplaza `:854-866`):

  ```php
  if ($method === 'POST' && $path === '/invoices') {
      $id = alegra_mock_uuid('eeeeeeee');
      $number = 'FV-' . $GLOBALS['alegra_mock_seq'];
      // H-A: respetar el status del body. Ausente => 'open' (preserva el
      // baseline del harness; el plugin SIEMPRE manda status).
      $status = (string) (is_array($body) ? ($body['status'] ?? 'open') : 'open');
      $stored = array_merge(is_array($body) ? $body : [], [
          'id' => $id,
          'number' => $number,
          'numberTemplate' => ['id' => '11111111-1111-1111-1111-111111111111', 'fullNumber' => $number],
          'status' => $status,
          'total' => alegra_mock_body_total($body),
          'balance' => alegra_mock_body_total($body),
      ]);
      $GLOBALS['alegra_mock_state']['invoices'][$id] = $stored;
      alegra_mock_apply_invoice_stock($id, $stored);
      return alegra_mock_response(200, $stored);
  }
  ```

- **5) `POST /invoices/{id}/open` — DESPUÉS** (reemplaza `:919-924`):

  ```php
  if ($method === 'POST' && preg_match('#^/invoices/([^/]+)/open$#', $path, $m)) {
      if (isset($GLOBALS['alegra_mock_state']['invoices'][$m[1]])) {
          $GLOBALS['alegra_mock_state']['invoices'][$m[1]]['status'] = 'open';
          alegra_mock_apply_invoice_stock($m[1], $GLOBALS['alegra_mock_state']['invoices'][$m[1]]);
      }
      return alegra_mock_response(200, ['id' => $m[1], 'status' => 'open']);
  }
  ```

- **6) `PUT /invoices/{id}` — DESPUÉS** (reemplaza `:928-936`): tras el `array_merge`, aplicar stock:

  ```php
  if ($method === 'PUT' && preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
      $existing = $GLOBALS['alegra_mock_state']['invoices'][$m[1]] ?? null;
      if (!$existing) {
          return alegra_mock_response(404, ['message' => 'Invoice not found']);
      }
      $patch = is_array($body) ? $body : [];
      $GLOBALS['alegra_mock_state']['invoices'][$m[1]] = array_merge($existing, $patch);
      $merged = $GLOBALS['alegra_mock_state']['invoices'][$m[1]];
      alegra_mock_apply_invoice_stock($m[1], $merged);
      return alegra_mock_response(200, $merged);
  }
  ```

- **Orden:** el helper (paso 3) se define antes de las rutas (mismo archivo). El `status` default
  `'open'` cuando el body lo omite es **deliberado** para no bajar el baseline (el plugin siempre
  manda `status`, ver `Orders.php:1246-1258`).

**Resultado esperado**: `draft` no mueve stock; `open`/`paid` descuenta **una vez** (idempotente por
invoice id); abrir un draft mueve por primera vez; con `alegra_mock_set_draft_moves_stock(true)` el
draft también mueve. Test `T30.11`.

**Dependencias**: ninguna. Prerequisito de **`T2.3`**, **`T3.1`**, **`T3.3`** (Fases 2–3).

**Trazabilidad**: REQ-POLL-02/03, REQ-OWN-01; D5 (H-A); DR16; `design.md` §6.1/§6.2; `spec.md` §G (G1).

**Verificación**: test nuevo en la sección `T30` (creada acá):

```php
TestRunner::test('T30.11 el mock modela stock por factura (draft no mueve; open/paid sí; idempotente)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-inv-ha', ['name' => 'HA', 'inventory' => ['availableQuantity' => 10]]);
    $api = make_api();

    $draft = $api->create_invoice([
        'status' => 'draft', 'client' => ['id' => 'c1'],
        'items' => [['id' => 'it-inv-ha', 'price' => 1, 'quantity' => 3]],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    TestRunner::assertFalse(is_wp_error($draft), 'el draft debe aceptarse');
    TestRunner::assertSame('draft', (string) ($draft['status'] ?? ''), 'el mock respeta draft');
    TestRunner::assertSame(10, (int) $GLOBALS['alegra_mock_state']['items']['it-inv-ha']['inventory']['availableQuantity'], 'draft NO mueve');

    $open = $api->create_invoice([
        'status' => 'open', 'client' => ['id' => 'c1'],
        'items' => [['id' => 'it-inv-ha', 'price' => 1, 'quantity' => 4]],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    TestRunner::assertSame(6, (int) $GLOBALS['alegra_mock_state']['items']['it-inv-ha']['inventory']['availableQuantity'], 'open descuenta 4');

    alegra_mock_apply_invoice_stock((string) $open['id'], $open);
    TestRunner::assertSame(6, (int) $GLOBALS['alegra_mock_state']['items']['it-inv-ha']['inventory']['availableQuantity'], 'idempotente por invoice id');

    $draft2 = $api->create_invoice([
        'status' => 'draft', 'client' => ['id' => 'c1'],
        'items' => [['id' => 'it-inv-ha', 'price' => 1, 'quantity' => 2]],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    $api->update_invoice((string) $draft2['id'], ['status' => 'open']);
    TestRunner::assertSame(4, (int) $GLOBALS['alegra_mock_state']['items']['it-inv-ha']['inventory']['availableQuantity'], 'draft→open mueve una vez');

    alegra_mock_set_draft_moves_stock(true);
    $api->create_invoice([
        'status' => 'draft', 'client' => ['id' => 'c1'],
        'items' => [['id' => 'it-inv-ha', 'price' => 1, 'quantity' => 1]],
        'date' => '2026-09-25', 'dueDate' => '2026-09-25',
    ]);
    TestRunner::assertSame(3, (int) $GLOBALS['alegra_mock_state']['items']['it-inv-ha']['inventory']['availableQuantity'], 'Rama B: draft mueve con el flag');
});
```

Correr `bash scripts/exec-test.sh` → `EXEC-TEST OK` (1990 + nuevos, 0 failed). **Prove-it-catches:**
comentar `alegra_mock_apply_invoice_stock(...)` en el `POST` ⇒ `T30.11` rojo (el open no descuenta).
**Guard del baseline:** si el conteo **baja** de 1990, el sospechoso es el `status` default; el body
siempre trae `status` en el plugin, así que el default `'open'` ya lo protege. Si aun así baja, buscar
el test que asumía el `'open'` forzado y corregirlo explícitamente.

**Riesgo**: que el `status` respetado cambie el stored de tests existentes (que asertaban `'open'`).
**Guard**: default `'open'` cuando el body omite `status` + correr el harness completo y comparar
contra 1990.

**Estimación**: M (4 h).

---

### T1.2 — H-B: `reference` en `GET`/`POST /inventory-adjustments` · `scripts/`

**Objetivo**: que el mock guarde el `reference` **del payload** y que el `GET` filtre por él, para
poder probar la idempotencia con `reference` (REQ-POLL-06). Sin esto, dos `out 1` con references
distintas se confunden.

**Descripción técnica**: el `GET /inventory-adjustments` (`alegra-mock.php:765-782`) sólo filtra por
`item_id`; el `POST` (`:880-914`) guarda en `:903` el `reference` **del ítem**
(`$item['reference']`), no el del payload. El shape documentado del POST es
`{date, items:[{id,type,quantity,unitCost}], warehouse?}` (`:614-637`); el validador **no** rechaza un
`reference` top-level extra. Cubre **REQ-POLL-06**; consumido por `T3.4`. El gate `G3` (`T0.4`) decide
si la cuenta real soporta el filtro; el mock se extiende **igual**.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/alegra-mock.php`.

- **1) `POST /inventory-adjustments`** — agregar el `reference` top-level al `$stored` (`:906-912`),
  después de `'observations'`:

  ```php
  $stored = [
      'id'           => alegra_mock_uuid('a9a9a9a9'),
      'date'         => (string) ($body['date'] ?? ''),
      'reference'    => (string) ($body['reference'] ?? ''),   // H-B: el del PAYLOAD
      'observations' => (string) ($body['observations'] ?? ''),
      'warehouse'    => $body['warehouse'] ?? null,
      'items'        => $stored_items,
  ];
  ```

  El `reference` por línea (`:903`, `$item['reference']`) **se conserva** (fidelidad del response).

- **2) `GET /inventory-adjustments`** — agregar el filtro por `reference` después del bloque
  `item_id` (`:768-778`), antes de `$start = ...` (`:779`):

  ```php
  if (!empty($query['reference'])) {
      $ref = (string) $query['reference'];
      $all = array_values(array_filter($all, static function ($adj) use ($ref) {
          return (string) ($adj['reference'] ?? '') === $ref;
      }));
  }
  ```

- **Orden:** el filtro va antes del `array_slice` de paginación; el POST no cambia de firma.

**Resultado esperado**: dos ajustes iguales (`item+type+quantity`) con `reference` distinta **no** se
confunden; `GET ?reference=R1` devuelve sólo el de R1; `items[].reference` sigue siendo el del ítem.
Test `T30.12`.

**Dependencias**: ninguna. Prerequisito de **`T3.4`** (Fase 3). No depende de `G3`.

**Trazabilidad**: REQ-POLL-06; D5 (H-B); `design.md` §3.6/§6.2; DR5; `spec.md` §G (G3).

**Verificación**: test nuevo en la sección `T30`:

```php
TestRunner::test('T30.12 GET /inventory-adjustments filtra por el reference del payload', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-ref', ['name' => 'Ref', 'reference' => 'SKU-REF', 'inventory' => ['availableQuantity' => 10]]);
    $api = make_api();

    $api->create_inventory_adjustment([
        'date' => '2026-09-25', 'reference' => 'wc-stock-1-10-9',
        'items' => [['id' => 'it-ref', 'type' => 'out', 'quantity' => 1, 'unitCost' => 1]],
    ]);
    $api->create_inventory_adjustment([
        'date' => '2026-09-25', 'reference' => 'wc-stock-1-9-8',
        'items' => [['id' => 'it-ref', 'type' => 'out', 'quantity' => 1, 'unitCost' => 1]],
    ]);

    $one = $api->get_inventory_adjustments(['reference' => 'wc-stock-1-10-9']);
    TestRunner::assertFalse(is_wp_error($one), 'GET debe responder');
    TestRunner::assertSame(1, count($one), 'references distintas no se confunden');
    TestRunner::assertSame('wc-stock-1-10-9', (string) ($one[0]['reference'] ?? ''), 'se guarda el reference del payload');
    TestRunner::assertSame('SKU-REF', (string) ($one[0]['items'][0]['reference'] ?? ''), 'items[].reference sigue siendo el del ítem');
});
```

**Prove-it-catches:** quitar el filtro por `reference` ⇒ `count($one)===1` falla (devuelve 2).

**Riesgo**: que el `reference` top-level rompa el validador documentado. **Guard**: el validador
(`:614-637`) itera sólo los campos obligatorios y no rechaza extras; `T30.12` lo prueba.

**Estimación**: M (3 h).

---

### T1.3 — H-C: `wc_get_orders` con `paginate` · `scripts/`

**Objetivo**: (1) que `wc_get_orders(['paginate' => true, ...])` devuelva un objeto con `->orders` y
`->total`, para que el badge y la cola no hagan una query pesada por página (NFR-04); y (2) que el motor
`meta_query` del stub soporte los operadores que usan `Invoice_Queue` y `retry_failed_invoices`, para que
los tests de Fase 4 sean **ejecutables** (cierre de **B6**).

**Descripción técnica**: hoy `wc_get_orders()` (`wp-stubs.php:1568-1612`) filtra por
`meta_key`/`meta_query`/`status`/`customer_id`, aplica `offset`/`limit` y devuelve un **array** (o ids
con `return=ids`). **Nunca** devuelve `->total`. El diseño (§4.7) lee el conteo con
`wc_get_orders(['paginate' => true, 'limit' => 1, <meta_query>])->total`. Cubre **REQ-QUEUE-03/07,
NFR-04**; consumido por `T4.10`, `T4.11`, `T6.3`. El gate `G4` (`T0.5`) decide si el core real lo
soporta; el stub se extiende **igual** para que el test sea determinista.

> **Segundo defecto (B6).** El motor de cláusulas `alegra_stub_meta_clause_matches()`
> (`wp-stubs.php:1541-1566`) sólo soporta `NOT EXISTS`/`EXISTS`/`!=`/`LIKE`/`=`. `Invoice_Queue::meta_query()`
> usa `compare => 'IN'` (`fase-4:789`) y `retry_failed_invoices()` usa `compare => '<'`/`'<='` con
> `type=NUMERIC`/`DATETIME` (`fase-4:519,522`). Con el `default '='` esos `meta_query` **nunca matchean**
> (`(string) $states` = `"Array"`; `'2' === '5'`), así que `T30.49`/`T30.61`/`T30.62`/`T30.411` no son
> ejecutables. `T1.3` (H-C) debe extender **ambos** seams. Verificado contra HEAD: el stub no tiene `IN`
> ni comparaciones.

**Desarrollo técnico**:

- **Archivo 1:** `scripts/lib/wp-stubs.php:1568-1612` (`wc_get_orders`).

- **ANTES** (cola de la función, `:1602-1611`):

  ```php
  if (!empty($args['limit'])) {
      $offset = (int) ($args['offset'] ?? 0);
      $orders = array_slice($orders, $offset, (int) $args['limit']);
  }

  if (($args['return'] ?? '') === 'ids') {
      return array_map(static fn ($o) => $o->get_id(), $orders);
  }

  return $orders;
  ```

- **DESPUÉS**:

  ```php
  // H-C: el total es el conjunto FILTRADO, antes de paginar.
  $total = count($orders);

  $limit = (int) ($args['limit'] ?? 0);
  $paged = max(1, (int) ($args['paged'] ?? 1));
  if ($limit > 0) {
      $offset = isset($args['offset']) ? (int) $args['offset'] : ($paged - 1) * $limit;
      $orders = array_slice($orders, $offset, $limit);
  }

  if (($args['return'] ?? '') === 'ids') {
      return array_map(static fn ($o) => $o->get_id(), $orders);
  }

  if (!empty($args['paginate'])) {
      return (object) [
          'orders'        => $orders,
          'total'         => $total,
          'max_num_pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
      ];
  }

  return $orders;
  ```

- **Compatibilidad:** sin `paginate`, el retorno es el array de siempre; `return=ids` intacto; `offset`
  explícito sigue teniendo prioridad sobre `paged`.

- **Archivo 2:** `scripts/lib/wp-stubs.php:1541-1566` (`alegra_stub_meta_clause_matches`).

- **ANTES** (real en HEAD, `:1541-1566`):

  ```php
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
  ```

- **DESPUÉS** (reemplaza `:1541-1566`; aditivo salvo el manejo de `value` array):

  ```php
  function alegra_stub_meta_clause_matches($order, array $clause): bool
  {
      $key = (string) ($clause['key'] ?? '');
      if ($key === '' || !method_exists($order, 'meta_exists')) {
          return false;
      }

      $compare = strtoupper((string) ($clause['compare'] ?? '='));
      $type    = strtoupper((string) ($clause['type'] ?? 'CHAR'));
      $raw     = $clause['value'] ?? '';              // puede ser array (IN/NOT IN)
      $exists  = (bool) $order->meta_exists($key);
      $actual  = (string) $order->get_meta($key, true);

      switch ($compare) {
          case 'NOT EXISTS':
              return !$exists;
          case 'EXISTS':
              return $exists;
          case 'IN':
              // WP_Meta_Query usa INNER JOIN: la fila de meta debe existir.
              if (!$exists) { return false; }
              foreach ((is_array($raw) ? $raw : [$raw]) as $wanted) {
                  if ($actual === (string) $wanted) { return true; }
              }
              return false;
          case 'NOT IN':
              if (!$exists) { return false; }
              foreach ((is_array($raw) ? $raw : [$raw]) as $wanted) {
                  if ($actual === (string) $wanted) { return false; }
              }
              return true;
          case '!=':
              return $exists && $actual !== (string) $raw;
          case 'LIKE':
              return $exists && stripos($actual, trim((string) $raw, '%')) !== false;
          case '<':
          case '<=':
          case '>':
          case '>=':
              if (!$exists) { return false; }
              // NUMERIC/DECIMAL/SIGNED/UNSIGNED comparan como número; el resto
              // (CHAR/DATETIME/DATE/TIME) como string, igual que WP_Meta_Query.
              // Las fechas GMT `Y-m-d H:i:s` ordenan lexicográficamente.
              $numeric = in_array($type, ['NUMERIC', 'DECIMAL', 'SIGNED', 'UNSIGNED'], true);
              $left    = $numeric ? (float) $actual : (string) $actual;
              $right   = $numeric ? (float) $raw    : (string) $raw;
              switch ($compare) {
                  case '<':  return $left <  $right;
                  case '<=': return $left <= $right;
                  case '>':  return $left >  $right;
                  case '>=': return $left >= $right;
              }
              return false;
          case '=':
          default:
              return $exists && $actual === (string) $raw;
      }
  }
  ```

- **Combinador OR + `NOT EXISTS`:** `alegra_stub_meta_query_matches()` (`:1513-1539`) ya evalúa
  sub-cláusulas anidadas con `relation=OR`/`AND` y `NOT EXISTS`; **no se toca**. `T30.13b` lo cubre
  explícitamente con el `meta_query` de `retry_failed_invoices()`.
- **Compatibilidad:** los casos viejos (`=`/`!=`/`LIKE`/`EXISTS`/`NOT EXISTS`) se conservan; `value`
  array sólo aplica a `IN`/`NOT IN` (antes un array se casteaba a `"Array"`).

**Resultado esperado**: `paginate=true` devuelve objeto con `->orders` (la página) y `->total` (el
conjunto filtrado); `paged` avanza; `return=ids` sigue devolviendo array. Además, `meta_query` con
`IN`/`NOT IN`/`<`/`<=`/`>`/`>=` (NUMERIC) y `DATETIME` matchea igual que `WP_Meta_Query`, y
`NOT EXISTS` combinado en `relation=OR` funciona. Tests `T30.13` y `T30.13b`.

**Dependencias**: ninguna. Prerequisito de **`T4.10`**, **`T4.11`**, **`T6.3`**.

**Trazabilidad**: REQ-QUEUE-03/07, NFR-04; D5 (H-C); `design.md` §4.7/§11/§13 (G4); DR9.

**Verificación**: test nuevo en la sección `T30`:

```php
TestRunner::test('T30.13 wc_get_orders soporta paginate (orders + total) y mantiene ids', function (): void {
    alegra_test_reset();
    for ($i = 1; $i <= 5; $i++) {
        alegra_make_order(1000 + $i, ['status' => 'processing']);
    }
    $page = wc_get_orders(['paginate' => true, 'limit' => 2, 'paged' => 1, 'return' => 'objects']);
    TestRunner::assertTrue(is_object($page), 'paginate devuelve objeto');
    TestRunner::assertSame(5, (int) ($page->total ?? -1), 'total = conjunto filtrado');
    TestRunner::assertCount(2, $page->orders, 'page 1 trae limit=2');

    $page3 = wc_get_orders(['paginate' => true, 'limit' => 2, 'paged' => 3, 'return' => 'objects']);
    TestRunner::assertCount(1, $page3->orders, 'page 3 trae el resto');

    $ids = wc_get_orders(['return' => 'ids', 'limit' => 2]);
    TestRunner::assertTrue(is_array($ids), 'return=ids sigue siendo array');
    TestRunner::assertSame(2, count($ids), 'ids respeta limit');
});
```

Test de cierre de **B6** (operadores del motor `meta_query`; usa los `meta_query` reales de Fase 4):

```php
TestRunner::test('T30.13b meta_query soporta IN, NOT IN, <, <=, DATETIME y NOT EXISTS en OR', function (): void {
    alegra_test_reset();
    // Metas literales: no depende de que T1.6 haya creado Invoice_Failure.
    alegra_make_order(2001, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_sync_state' => 'failed_retriable',
        '_alegra_invoice_attempts'   => '2',
        '_alegra_invoice_next_retry' => '2026-09-25 10:00:00',
    ]]);
    alegra_make_order(2002, ['status' => 'processing', 'meta' => [
        '_alegra_invoice_sync_state' => 'failed_permanent',
        '_alegra_invoice_attempts'   => '5',
    ]]);
    alegra_make_order(2003, ['status' => 'processing']); // sin ledger

    // IN — Invoice_Queue::meta_query (fase-4:789).
    $in = wc_get_orders([
        'status' => ['processing'], 'return' => 'ids',
        'meta_query' => [[
            'key' => '_alegra_invoice_sync_state',
            'value' => ['failed_retriable', 'blocked'],
            'compare' => 'IN',
        ]],
    ]);
    TestRunner::assertSame([2001], array_values($in), 'IN matchea sólo el estado de la lista');

    // NOT IN — excluye lo listado y lo inexistente (INNER JOIN).
    $not_in = wc_get_orders([
        'status' => ['processing'], 'return' => 'ids',
        'meta_query' => [[
            'key' => '_alegra_invoice_sync_state',
            'value' => ['failed_retriable'],
            'compare' => 'NOT IN',
        ]],
    ]);
    TestRunner::assertSame([2002], array_values($not_in), 'NOT IN excluye lo listado y lo ausente');

    // < NUMERIC — retry_failed_invoices (fase-4:519).
    $lt = wc_get_orders([
        'status' => ['processing'], 'return' => 'ids',
        'meta_query' => [[
            'key' => '_alegra_invoice_attempts',
            'value' => 5, 'compare' => '<', 'type' => 'NUMERIC',
        ]],
    ]);
    TestRunner::assertSame([2001], array_values($lt), '< NUMERIC compara como número, no como string');

    // <= DATETIME + NOT EXISTS en OR — retry_failed_invoices (fase-4:520-523).
    $due = wc_get_orders([
        'status' => ['processing'], 'return' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => '_alegra_invoice_sync_state', 'value' => 'failed_retriable'],
            ['relation' => 'OR',
                ['key' => '_alegra_invoice_next_retry', 'compare' => 'NOT EXISTS'],
                ['key' => '_alegra_invoice_next_retry', 'value' => '2026-09-25 12:00:00', 'compare' => '<=', 'type' => 'DATETIME'],
            ],
        ],
    ]);
    TestRunner::assertSame([2001], array_values($due), 'DATETIME <= y NOT EXISTS combinan en OR');
});
```

**Prove-it-catches:**
- Quitar la rama `if (!empty($args['paginate']))` ⇒ `is_object($page)` falla (devuelve array).
- Quitar `case 'IN'` del switch ⇒ `T30.13b` falla (la cola no matchea; devuelve `[]`).
- Quitar los `case '<'/'<='` ⇒ `T30.13b` falla (`'2' === 5` es falso con el `default '='`).

**Riesgo**: que algún test viejo dependa de que `limit` sin `paginate` devuelva array — se mantiene.
**Guard**: la rama `paginate` es aditiva; el motor `meta_query` conserva los casos viejos; correr el
harness y comparar contra 1990.

**Estimación**: M (4 h, incluye el motor `meta_query`).

---

### T1.4 — H-E: `WC_Order_Item::get_product()` · `scripts/`

**Objetivo**: que las líneas del pedido resuelvan su producto, para que
`baseline_products_for_invoice()` (T3.3) pueda recorrer las líneas y fijar el baseline.

**Descripción técnica**: `WC_Order_Item` (`wp-stubs.php:1207-1232`) tiene `product_id`/`variation_id` y
`get_product_id()`, pero **no** `get_product()`. `WC_Order::get_items()` (`:1377`) e `is_paid()`
(`:1388`) **ya existen**, y el meta CRUD (`get_meta`/`update_meta_data`/`meta_exists`/`save`,
`:1372-1376`) también. Cubre **REQ-POLL-02**; consumido por `T3.3`. El gate `G4`/`G5` no aplica acá.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/wp-stubs.php:1207-1232` (`class WC_Order_Item`).

- **DESPUÉS de `get_product_id()`** (`:1224`) agregar:

  ```php
  /**
   * H-E: resuelve el producto de la línea (variación si la hay, si no el
   * simple). Devuelve false si el producto no está en el mundo del harness,
   * como WC_Order_Item::get_product() del core.
   */
  public function get_product()
  {
      $id = $this->variation_id > 0 ? $this->variation_id : $this->product_id;
      return $GLOBALS['wc_products'][$id] ?? false;
  }
  ```

- **Sin cambio de firma** en `WC_Order`; `get_items()` e `is_paid()` se dejan como están.

**Resultado esperado**: `$order->get_items()[0]->get_product()` devuelve el `WC_Product` sembrado;
`is_paid()` es true para `processing`/`completed` y false para `on-hold`/`pending`. Test `T30.14`.

**Dependencias**: ninguna. Prerequisito de **`T3.3`** (Fase 3).

**Trazabilidad**: REQ-POLL-02; D5 (H-E); `design.md` §3.5/§6.1; `spec.md` §G.

**Verificación**: test nuevo en la sección `T30`:

```php
TestRunner::test('T30.14 WC_Order_Item::get_product + is_paid para el baseline de factura', function (): void {
    alegra_test_reset();
    $p = alegra_make_product(900, ['manage_stock' => true, 'stock' => 10]);
    $order = alegra_make_order(900, [
        'status' => 'processing',
        'items' => [new WC_Order_Item(['product_id' => 900, 'quantity' => 3])],
    ]);
    $items = $order->get_items();
    TestRunner::assertCount(1, $items, 'get_items devuelve las líneas');
    $product = $items[0]->get_product();
    TestRunner::assertInstanceOf(WC_Product::class, $product, 'get_product resuelve el producto');
    TestRunner::assertSame(10, $product->get_stock_quantity(), 'resuelve por product_id');
    TestRunner::assertTrue($order->is_paid(), 'processing es paid');

    TestRunner::assertFalse(alegra_make_order(901, ['status' => 'on-hold'])->is_paid(), 'on-hold no es paid');
    TestRunner::assertFalse(alegra_make_order(902, ['status' => 'pending'])->is_paid(), 'pending no es paid');
});
```

**Prove-it-catches:** quitar `get_product()` ⇒ `$items[0]->get_product()` tira `Error` (método
inexistente) y `T30.14` falla.

**Riesgo**: que `get_product()` devuelva `null` en vez de `false` y algún `instanceof` falle. **Guard**:
devolver `false` (contrato del core) y cubrirlo con `assertInstanceOf`/`assertFalse`.

**Estimación**: S (2 h).

---

### T1.5 — Opciones nuevas (8) + semilla idempotente · producto

**Objetivo**: que las 8 opciones del cambio existan con defaults seguros en
activación y se limpien en `uninstall.php`, sin pisar valores elegidos por el comerciante.

**Descripción técnica**: las 8 opciones (`design.md` §10) tienen **0 coincidencias** en producción. El
filtro G3 **no** es una opción: es la constante de build `Inventory_Pusher::USE_REFERENCE_FILTER`
(CORRECCIONES **C5/C29**; la define `T3.4`). El
patrón es el de `alegra-connector.php:414-468` (`$defaults`) + `:474-507` (`$non_autoload`) + `:509-513`
(loop `get_option($key) === false ⇒ add_option`), y el de `uninstall.php` (`delete_option`
explícitos). Para instalaciones ya activas (que no re-activan), se siembran desde
`Write_Gate::maybe_migrate()` (`:182`) con el mismo patrón idempotente (`design.md` §7.1). Cubre
**REQ-OWN-02/07, REQ-QUEUE-06, NFR-03**. **`Write_Gate` ya tiene `inventory`** (`:43,:58,:79`): no se
toca. La opción clave es `alegra_connector_stock_owner` ∈ `{auto, invoice, adjustment}`, default
**`auto`** (condición DOBLE, la implementa `T2.1`).

**Desarrollo técnico**:

- **Archivo 1:** `alegra-connector.php`.

  **1a) `$defaults` (`:414-468`)** — agregar **las 4 sembrables** junto al bloque 2.6.0
  (después de `'alegra_connector_open_invoice_on_paid' => true,` `:460`):

  ```php
  // 2.7.0: dueño único del stock. `auto` reproduce 2.6.0 con la condición
  // DOBLE (push_orders_enabled && open_invoice_on_paid) — CORRECCIÓN C1.
  'alegra_connector_stock_owner' => 'auto',
  // 2.7.0: cola de reintento de facturas (opt-in, default off por ser dinero).
  'alegra_connector_invoice_retry_enabled' => false,
  'alegra_connector_invoice_retry_max_attempts' => 5,
  'alegra_connector_invoice_retry_batch' => 20,
  ```

  **1b) `$non_autoload` (`:474-507`)** — agregar **las 8** (las 4 internas quedan
  inertes en el loop porque no están en `$defaults`, pero se listan para su creación/lectura):

  ```php
  'alegra_connector_stock_owner',
  'alegra_connector_invoice_retry_enabled',
  'alegra_connector_invoice_retry_max_attempts',
  'alegra_connector_invoice_retry_batch',
  'alegra_connector_invoice_failures_count',
  'alegra_connector_invoice_failures_hash',
  'alegra_connector_stock_divergence',
  'alegra_connector_stock_owner_epoch',
  ```

- **Archivo 2:** `uninstall.php` — agregar los 8 `delete_option()`
  **después** del bloque de inventario (real `:77-85`), tras
  `delete_option('alegra_connector_consumidor_final_probe');` (`:85`):

  ```php
  delete_option('alegra_connector_stock_owner');
  delete_option('alegra_connector_invoice_retry_enabled');
  delete_option('alegra_connector_invoice_retry_max_attempts');
  delete_option('alegra_connector_invoice_retry_batch');
  delete_option('alegra_connector_invoice_failures_count');
  delete_option('alegra_connector_invoice_failures_hash');
  delete_option('alegra_connector_stock_divergence');
  delete_option('alegra_connector_stock_owner_epoch');
  ```

- **Archivo 3:** `includes/Write_Gate.php` — en `maybe_migrate()` (`:182-220`), agregar el bloque
  idempotente **después** del `if (... < 1) { ... }` (`:186-214`) y **antes** de
  `self::maybe_migrate_webhook_events();` (`:219`):

  ```php
  // Guard 1b (2.7.0): dueño del stock + cola de reintento en instalaciones ya
  // activas. Idempotente: sólo siembra si la opción no existe (nunca pisa).
  // La condición sobre stock_owner evita releer las otras 3 en cada request.
  if (get_option('alegra_connector_stock_owner') === false) {
      add_option('alegra_connector_stock_owner', 'auto', '', 'no');
      if (get_option('alegra_connector_invoice_retry_enabled') === false) {
          add_option('alegra_connector_invoice_retry_enabled', false, '', 'no');
      }
      if (get_option('alegra_connector_invoice_retry_max_attempts') === false) {
          add_option('alegra_connector_invoice_retry_max_attempts', 5, '', 'no');
      }
      if (get_option('alegra_connector_invoice_retry_batch') === false) {
          add_option('alegra_connector_invoice_retry_batch', 20, '', 'no');
      }
  }
  ```

- **Nota:** `register_setting` y la UI **no** son de `T1.5` (los poseen `T6.1`/`T6.2`/`T2.8`). El loop
  de `$defaults` no pisa valores: sólo siembra si `get_option($key) === false` (`:510`).

**Resultado esperado**: en activación, las 4 sembrables quedan con su default; una
instalación existente con valor elegido no se pisa; `maybe_migrate()` es idempotente; `uninstall.php`
limpia las 8. Test `T30.15`.

**Dependencias**: ninguna. Prerequisito de **`T2.1`**, **`T2.8`**, **`T4.7`**, **`T4.8`** (Fases 2–4).

**Trazabilidad**: REQ-OWN-02/07, REQ-QUEUE-06, NFR-03; `design.md` §7.1/§10; `spec.md` REQ-OWN-07/§G.

**Verificación**: test nuevo en la sección `T30` (source-scan + funcional, patrón de `T29.17`):

```php
TestRunner::test('T30.15 las 8 opciones de stock-ownership se siembran y se limpian', function (): void {
    $root = $GLOBALS['alegra_plugin_root'];
    $boot = (string) file_get_contents($root . 'alegra-connector.php');
    $uninstall = (string) file_get_contents($root . 'uninstall.php');

    $seed = [
        'alegra_connector_stock_owner' => "'auto'",
        'alegra_connector_invoice_retry_enabled' => 'false',
        'alegra_connector_invoice_retry_max_attempts' => '5',
        'alegra_connector_invoice_retry_batch' => '20',
    ];
    foreach ($seed as $opt => $default) {
        TestRunner::assertStringContains("'$opt' => $default", $boot, "$opt debe estar en \$defaults con default $default");
    }
    foreach ([
        'alegra_connector_stock_owner', 'alegra_connector_invoice_retry_enabled',
        'alegra_connector_invoice_retry_max_attempts', 'alegra_connector_invoice_retry_batch',
        'alegra_connector_invoice_failures_count', 'alegra_connector_invoice_failures_hash',
        'alegra_connector_stock_divergence', 'alegra_connector_stock_owner_epoch',
    ] as $opt) {
        TestRunner::assertStringContains($opt, $boot, "$opt debe estar en \$non_autoload");
        TestRunner::assertStringContains("delete_option('$opt')", $uninstall, "$opt debe limpiarse en uninstall");
    }

    alegra_test_reset();
    TestRunner::assertSame(false, get_option('alegra_connector_stock_owner', false), 'sin sembrar');
    \Alegra\Connector\Write_Gate::maybe_migrate();
    TestRunner::assertSame('auto', get_option('alegra_connector_stock_owner'), 'maybe_migrate siembra auto');
    update_option('alegra_connector_stock_owner', 'invoice');
    \Alegra\Connector\Write_Gate::maybe_migrate();
    TestRunner::assertSame('invoice', get_option('alegra_connector_stock_owner'), 'no pisa un valor elegido');
});
```

**Prove-it-catches:** quitar `alegra_connector_stock_owner` de `$defaults` ⇒ la primera aserción falla;
quitar el bloque de `maybe_migrate()` ⇒ la aserción `'auto'` falla. **G3 (C5/C29):** `T30.15` **no**
verifica ninguna opción `alegra_connector_inventory_reference_filter`; la rama se fija con la constante
`Inventory_Pusher::USE_REFERENCE_FILTER` (test en `T3.4`).

**Riesgo**: que `maybe_migrate()` corra en cada request y agregue lecturas. **Guard**: el `if` externo
sobre `stock_owner` hace que, una vez sembrada, sólo se lea esa opción (1 lectura no-autoload por
request, comparable a la del guard de webhooks).

**Estimación**: M (3 h).

---

### T1.6 — Esqueleto `Invoice_Failure` · producto

**Objetivo**: crear la superficie de la clase del clasificador puro + ledger de fallo, para que las
fases 4 y 5 la completen sin redefinir contratos.

**Descripción técnica**: `design.md` §4.2/§9 define `final class Invoice_Failure` con
`classify()/persist()/clear()/get()`. La tabla de clasificación completa y el símbolo `stock_insufficient`
son de `T4.1` (bloqueado por G2 sólo en el símbolo); el CRUD HPOS-safe de los 7 metas (`design.md`
§4.1) es de `T4.2`. `T1.6` crea la clase, el **shape** y un mapeo conservador; los call sites son
`T4.3`. Cubre **REQ-QUEUE-01/02**.

**Desarrollo técnico**:

- **Archivo nuevo:** `includes/Sync/Invoice_Failure.php` (namespace `Alegra\Connector\Sync`, PSR-4
  `includes/Sync/`, `alegra-connector.php:118-125`).

- **Contenido** (superficie + shape + mapeo conservador; T4.1/T4.2 completan):

  ```php
  <?php
  declare(strict_types=1);

  namespace Alegra\Connector\Sync;

  if (!defined('ABSPATH')) {
      exit;
  }

  /**
   * Clasificador puro de fallos de factura + ledger por pedido (D3).
   * T1.6: superficie + shape + mapeo conservador.
   * T4.1: tabla completa (429/5xx/4xx, errores de datos) + símbolo G2.
   * T4.2: persist()/clear() (CRUD HPOS-safe de los 7 metas).
   */
  final class Invoice_Failure
  {
      public const META_STATE      = '_alegra_invoice_sync_state';
      public const META_CODE       = '_alegra_invoice_error_code';
      public const META_MESSAGE    = '_alegra_invoice_error_message';
      public const META_RETRIABLE  = '_alegra_invoice_error_retriable';
      public const META_ATTEMPTS   = '_alegra_invoice_attempts';
      public const META_LAST       = '_alegra_invoice_last_attempt';
      public const META_NEXT_RETRY = '_alegra_invoice_next_retry';

      /**
       * @param array|\WP_Error $result
       * @return array{state:string,code:string,message:string,retriable:bool,persist:bool}
       */
      public static function classify(array|\WP_Error $result): array
      {
          if ($result instanceof \WP_Error) {
              return [
                  'state'     => 'failed_retriable',
                  'code'      => (string) $result->get_error_code(),
                  'message'   => mb_substr((string) $result->get_error_message(), 0, 500),
                  'retriable' => true,
                  'persist'   => true,
              ];
          }
          if (is_array($result) && !empty($result['blocked_by_gate'])) {
              return [
                  'state' => 'blocked', 'code' => 'blocked:' . (string) ($result['reason'] ?? ''),
                  'message' => '', 'retriable' => false, 'persist' => true,
              ];
          }
          if (is_array($result) && \Alegra\Connector\API\Client::is_dry_run_response($result)) {
              return ['state' => 'idle', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
          }
          if (is_array($result) && !empty($result['id'])) {
              return ['state' => 'resolved', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
          }
          return ['state' => 'failed_retriable', 'code' => 'unknown', 'message' => '', 'retriable' => true, 'persist' => true];
      }

      public static function persist(\WC_Order $order, array $classification): void
      {
          // T4.2: CRUD HPOS-safe de los 7 metas. T1.6 deja la superficie.
      }

      public static function clear(\WC_Order $order): void
      {
          // T4.2: borra el ledger sin tocar _alegra_invoice_id. T1.6 deja la superficie.
      }

      /** @return array<string,mixed> */
      public static function get(\WC_Order $order): array
      {
          return [
              'state'      => (string) $order->get_meta(self::META_STATE, true),
              'code'       => (string) $order->get_meta(self::META_CODE, true),
              'message'    => (string) $order->get_meta(self::META_MESSAGE, true),
              'retriable'  => (string) $order->get_meta(self::META_RETRIABLE, true) === '1',
              'attempts'   => (int) $order->get_meta(self::META_ATTEMPTS, true),
              'last'       => (string) $order->get_meta(self::META_LAST, true),
              'next_retry' => (string) $order->get_meta(self::META_NEXT_RETRY, true),
          ];
      }
  }
  ```

- **Orden:** no hay call sites todavía; el archivo se resuelve por PSR-4 (`Sync/`).

**Resultado esperado**: la clase carga por PSR-4; `classify()` devuelve las 5 claves; un `WP_Error`
desconocido ⇒ `failed_retriable`/`retriable=true`/`persist=true`; un éxito ⇒ `resolved`. Test `T30.16`.

**Dependencias**: ninguna. Prerequisito de **`T4.1`**, **`T4.2`**, **`T4.3`** (Fase 4).

**Trazabilidad**: REQ-QUEUE-01/02; `design.md` §4.1/§4.2/§9; `spec.md` REQ-QUEUE-02.

**Verificación**: test nuevo en la sección `T30` (cubre también `T1.7`/`T1.8`):

```php
TestRunner::test('T30.16 Invoice_Failure::classify devuelve el shape y las 3 clases cargan por PSR-4', function (): void {
    alegra_test_reset();
    TestRunner::assertTrue(class_exists(\Alegra\Connector\Sync\Invoice_Failure::class), 'Invoice_Failure carga');
    TestRunner::assertTrue(class_exists(\Alegra\Connector\Sync\Invoice_Queue::class), 'Invoice_Queue carga');
    TestRunner::assertTrue(class_exists(\Alegra\Connector\Sync\Stock_Divergence::class), 'Stock_Divergence carga');

    $shape = \Alegra\Connector\Sync\Invoice_Failure::classify(new \WP_Error('api_error', 'boom'));
    foreach (['state', 'code', 'message', 'retriable', 'persist'] as $key) {
        TestRunner::assertArrayHasKey($key, $shape, "classify() debe traer $key");
    }
    TestRunner::assertSame('failed_retriable', $shape['state'], 'un error desconocido es retriable');
    TestRunner::assertTrue($shape['retriable'], 'retriable=true');
    TestRunner::assertTrue($shape['persist'], 'persist=true');

    $ok = \Alegra\Connector\Sync\Invoice_Failure::classify(['id' => 'inv-1']);
    TestRunner::assertSame('resolved', $ok['state'], 'un éxito es resolved');
});
```

**Prove-it-catches:** cambiar `classify()` para que no devuelva `persist` ⇒ `assertArrayHasKey`
falla. Renombrar el archivo/namespace ⇒ `class_exists` falla.

**Riesgo**: que `T4.1` redefina el shape y rompa el contrato. **Guard**: el shape es el del
`design.md` §4.2/§9; `T4.1` sólo completa valores, no claves.

**Estimación**: M (2 h).

---

### T1.7 — Esqueleto `Invoice_Queue` · producto

**Objetivo**: crear la superficie de la clase de query/conteo de la pantalla "Facturas por subir", para
que `T4.10`/`T4.11` la implementen sin redefinir firmas.

**Descripción técnica**: `design.md` §9 define `final class Invoice_Queue` con
`query()/refresh_count()/count()`. La query paginada con el `meta_query` del ledger + nunca-intentados
es de `T4.10` (bloqueada por G4 en el mecanismo de conteo); el conteo cacheado option-backed es de
`T4.11`. `T1.7` crea la clase y las firmas (cuerpos vacíos). Cubre **REQ-QUEUE-03/07, NFR-04**.

**Desarrollo técnico**:

- **Archivo nuevo:** `includes/Sync/Invoice_Queue.php` (namespace `Alegra\Connector\Sync`).

  ```php
  <?php
  declare(strict_types=1);

  namespace Alegra\Connector\Sync;

  if (!defined('ABSPATH')) {
      exit;
  }

  /**
   * Query paginada de la pantalla "Facturas por subir" + conteo cacheado.
   * T1.7: firmas. T4.10: query real. T4.11: refresh_count()/count().
   */
  final class Invoice_Queue
  {
      /**
       * @param array<string,mixed> $filters
       * @return array{ids:int[],total:int}
       */
      public static function query(array $filters = [], int $paged = 1, int $per_page = 20): array
      {
          return ['ids' => [], 'total' => 0]; // T4.10
      }

      public static function refresh_count(): int
      {
          return 0; // T4.11
      }

      public static function count(): int
      {
          return 0; // T4.11
      }
  }
  ```

**Resultado esperado**: la clase carga por PSR-4 con las 3 firmas (cubierto por `T30.16` y `T1.10`).

**Dependencias**: ninguna. Prerequisito de **`T4.10`**, **`T4.11`**, **`T6.3`** (Fases 4/6).

**Trazabilidad**: REQ-QUEUE-03/07, NFR-04; `design.md` §4.4/§4.7/§9; `spec.md` REQ-QUEUE-03.

**Verificación**: `T30.16` asserta `class_exists`; `T1.10` (smoke) refuerza la resolución PSR-4.
Además, `method_exists(Invoice_Queue::class, 'query'|'refresh_count'|'count')`.

**Prove-it-catches:** renombrar `refresh_count()` ⇒ el `method_exists` de `T30.16` (o el smoke) falla.

**Riesgo**: que el nombre de la clase/namespace no matchee el archivo y PSR-4 no la encuentre. **Guard**:
`includes/Sync/Invoice_Queue.php` ↔ `Alegra\Connector\Sync\Invoice_Queue` (autoloader `:118-125`).

**Estimación**: S (2 h).

---

### T1.8 — Esqueleto `Stock_Divergence` · producto

**Objetivo**: crear la superficie de la clase de detección/informe de divergencia WC↔Alegra, para que
`T5.1`/`T5.2` la implementen sin redefinir firmas.

**Descripción técnica**: `design.md` §9 define `final class Stock_Divergence` con
`record()/report()/clear()`. La detección + causa (option `alegra_connector_stock_divergence`, bounded
a 200) es de `T5.1`; el informe paginado es de `T5.2`. `T1.8` crea la clase y las firmas. Cubre
**REQ-RECON-01**.

**Desarrollo técnico**:

- **Archivo nuevo:** `includes/Sync/Stock_Divergence.php` (namespace `Alegra\Connector\Sync`).

  ```php
  <?php
  declare(strict_types=1);

  namespace Alegra\Connector\Sync;

  if (!defined('ABSPATH')) {
      exit;
  }

  /**
   * Detección/causa/informe de divergencia WC↔Alegra (D4).
   * T1.8: firmas. T5.1: record()/clear() + causa. T5.2: report().
   */
  final class Stock_Divergence
  {
      public static function record(int $product_id, array $entry): void
      {
          // T5.1
      }

      /**
       * @return array{items:array,total:int}
       */
      public static function report(int $limit = 20, int $offset = 0): array
      {
          return ['items' => [], 'total' => 0]; // T5.2
      }

      public static function clear(int $product_id): void
      {
          // T5.1
      }
  }
  ```

**Resultado esperado**: la clase carga por PSR-4 con las 3 firmas (cubierto por `T30.16` y `T1.10`).

**Dependencias**: ninguna. Prerequisito de **`T5.1`**, **`T5.2`**, **`T7.4`** (Fases 5/7).

**Trazabilidad**: REQ-RECON-01; `design.md` §5.1/§5.2/§9; `spec.md` REQ-RECON-01.

**Verificación**: `T30.16` asserta `class_exists`; `T1.10` (smoke) refuerza la resolución PSR-4.
Además, `method_exists(Stock_Divergence::class, 'record'|'report'|'clear')`.

**Prove-it-catches:** renombrar `report()` ⇒ el `method_exists`/smoke falla.

**Riesgo**: idéntico a `T1.7` (nombre ↔ PSR-4). **Guard**: `includes/Sync/Stock_Divergence.php` ↔
`Alegra\Connector\Sync\Stock_Divergence`.

**Estimación**: S (2 h).

---

### T1.9 — Sección `T30` en el harness (cierre) · `scripts/`

**Objetivo**: formalizar la sección `// === stock-ownership (2.7.0) ===` en `scripts/exec-test.php`,
agregar el test de cierre `T30.17` y confirmar que el baseline sigue verde con los tests nuevos.

**Descripción técnica**: `T1.1`–`T1.6` ya insertaron sus tests `T30.11`–`T30.16`; `T1.9` verifica que
la sección exista, agrega `T30.17` (sección + PSR-4 de las 3 clases) y corre la suite completa. La
sección `// === sync-reliability (2.6.0) ===` está en `:8031` y el `exit(TestRunner::summary())` en
`:9758`. La sección `T30` va **antes** de `:9758`. Cubre **NFR-05** (los tests viven en `scripts/`,
excluido del ZIP por `.distignore:15`).

**Desarrollo técnico**:

- **Archivo:** `scripts/exec-test.php`.

- **Encabezado de sección** (insertar **antes** de `exit(TestRunner::summary());` `:9758`, después del
  último test de `sync-reliability`):

  ```php
  // ===========================================================================
  // === stock-ownership (2.7.0) ===
  // Fase 1 — cimientos (harness D5 H-A/H-B/H-C/H-E), opciones y esqueletos.
  // IDs: T30.1{n} (T30.11…T30.17 + T30.13b). Fases 2..7 agregan T30.2x..T30.7x.
  // ===========================================================================
  ```

  > Si `T1.1` ya creó el encabezado al insertar `T30.11`, `T1.9` **no** lo duplica: verifica que exista.

- **Test de cierre `T30.17`**:

  ```php
  TestRunner::test('T30.17 la sección T30 existe y las 3 clases nuevas cargan por PSR-4', function (): void {
      $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'scripts/exec-test.php');
      TestRunner::assertStringContains('=== stock-ownership (2.7.0) ===', $src, 'la sección T30 debe existir');
      foreach (['Invoice_Failure', 'Invoice_Queue', 'Stock_Divergence'] as $class) {
          TestRunner::assertTrue(
              class_exists('Alegra\\Connector\\Sync\\' . $class),
              "$class debe cargar por PSR-4"
          );
      }
  });
  ```

- **No** se agregan carpetas de test al plugin (NFR-05): todo queda en `scripts/exec-test.php`.

**Resultado esperado**: la sección existe; `bash scripts/exec-test.sh` → `EXEC-TEST OK` con
`1990 + nuevos` assertions y **0 failed**. Test `T30.17`.

**Dependencias**: `T1.1`–`T1.8` (los tests y las clases). Cierra la Fase 1.

**Trazabilidad**: NFR-05; `tasks.md` §8/§14; `.distignore:15`.

**Verificación**:

```bash
grep -n '=== stock-ownership (2.7.0) ===' scripts/exec-test.php
bash scripts/exec-test.sh   # => EXEC-TEST OK: <N> assertions passed, 0 failed
```

**Prove-it-catches:** borrar el encabezado de sección ⇒ `assertStringContains` de `T30.17` falla.

**Riesgo**: que el conteo real de assertions no sea el esperado por cambios en tests ajenos. **Guard**:
el objetivo es **0 failed** y **≥ 1990 + los nuevos**; documentar el conteo exacto en el commit.

**Estimación**: S (2 h).

---

### T1.10 — smoke-load: PSR-4 de las 3 clases nuevas · `scripts/`

**Objetivo**: que `bash scripts/smoke-test.sh` asserta que `Invoice_Failure`, `Invoice_Queue` y
`Stock_Divergence` cargan por el autoloader PSR-4, cerrando la Fase 1.

**Descripción técnica**: `scripts/smoke-load.php` ya valida `Run_Context`, `Inventory_Writer` e
`Inventory_Pusher` con `check('...', class_exists(...), '— ...')` (`:196-212`). `T1.10` agrega las 3
clases nuevas. `smoke-test.sh` corre `smoke-load.php` contra el working tree (y contra el ZIP en modo
`$1=zip`). Cubre **NFR-05**.

**Desarrollo técnico**:

- **Archivo:** `scripts/smoke-load.php`. Insertar **después** del check de `Inventory_Pusher`
  (`:208-212`):

  ```php
  check(
      'Invoice_Failure class loads',
      class_exists(\Alegra\Connector\Sync\Invoice_Failure::class),
      '— el clasificador puro de fallos debe resolver por el autoloader PSR-4'
  );

  check(
      'Invoice_Queue class loads',
      class_exists(\Alegra\Connector\Sync\Invoice_Queue::class),
      '— la query de la cola debe resolver por el autoloader PSR-4'
  );

  check(
      'Stock_Divergence class loads',
      class_exists(\Alegra\Connector\Sync\Stock_Divergence::class),
      '— el informe de divergencia debe resolver por el autoloader PSR-4'
  );
  ```

- **Sin** cambios en `smoke-test.sh` (ya corre `smoke-load.php`).

**Resultado esperado**: `bash scripts/smoke-test.sh` → `SMOKE OK`; las 3 clases resuelven por PSR-4.

**Dependencias**: `T1.6`/`T1.7`/`T1.8` (los archivos deben existir). Cierra la Fase 1.

**Trazabilidad**: NFR-05; `design.md` §6/§9; `tasks.md` §14 (DoD: `smoke-load.php` asserta las 3).

**Verificación**:

```bash
bash scripts/smoke-test.sh   # => SMOKE OK
```

**Prove-it-catches:** borrar `includes/Sync/Invoice_Queue.php` ⇒ `smoke-test.sh` falla en el check
correspondiente.

**Riesgo**: que `smoke-load.php` corra con un `$plugin_root` sin las clases (ZIP viejo). **Guard**: el
modo ZIP extrae el ZIP construido en la fase 7; en Fase 1 se corre contra el working tree.

**Estimación**: S (1 h).

---

## DoD de la Fase 1

- [ ] **H-A** aplicado: `POST /invoices` respeta `status` y mueve stock una sola vez; `draft` no mueve
      (salvo el flag); `PUT`/`POST .../open` mueven al abrir. Test `T30.11`.
- [ ] **H-B** aplicado: el `POST` guarda el `reference` del payload y el `GET` filtra por él. Test
      `T30.12`.
- [ ] **H-C** aplicado: `wc_get_orders(paginate=>true)` devuelve `->orders` + `->total`; `ids` intacto;
      el motor `meta_query` soporta `IN`/`NOT IN`/`<`/`<=`/`>`/`>=` (NUMERIC) y `DATETIME` + `NOT EXISTS`
      en `OR` (cierre de **B6**). Tests `T30.13` y `T30.13b`.
- [ ] **H-E** aplicado: `WC_Order_Item::get_product()`; `get_items`/`is_paid`/meta CRUD ya existían.
      Test `T30.14`.
- [ ] **H1/H4/H3/H5/FIX-13/C10 NO se reimplementaron** (ya estaban en HEAD).
- [ ] Las **8** opciones nuevas sembradas (4 en `$defaults`; 8 en `$non_autoload` + `uninstall`);
      `maybe_migrate()` idempotente. El filtro G3 **no** es opción: es la constante
      `Inventory_Pusher::USE_REFERENCE_FILTER` (C5/C29). Test `T30.15`.
- [ ] Las **3** clases nuevas (`Invoice_Failure`, `Invoice_Queue`, `Stock_Divergence`) cargan por PSR-4
      con sus firmas. Test `T30.16` + smoke (`T1.10`).
- [ ] Sección `// === stock-ownership (2.7.0) ===` creada y `T30.17` verde.
- [ ] **`wp_mail` NO se usó ni se stubeó** (H-D = N/A).
- [ ] `_alegra_stock_adjusted_at` documentado; el harness ya soporta su CRUD (lo escribe `T2.2`).
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con **0 failed** y conteo **≥ 1990 + nuevos**.
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`.
- [ ] Cada tarea central con su **prove-it-catches** documentado (revertir → rojo → re-aplicar → verde).
- [ ] `scripts/` **NO** se distribuye (`.distignore:15`); no se agregaron carpetas de test al plugin.
