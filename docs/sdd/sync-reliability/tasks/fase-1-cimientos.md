# Tareas micro-detalladas — Fase 1 (cimientos + harness H1–H8 + opciones + `Write_Gate` inventory)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Documentos base | `proposal.md` §4/§5 · `spec.md` (REQ-INV-01/02/04/08, REQ-POLL-04, NFR-04/06) · `design.md` §9 (D8) + §10 + §11 · `tasks.md` §4 + Fase 1 |
| Alcance de este archivo | **Fase 1 — `T1.1` … `T1.12`** (12 tareas) |
| Naturaleza | **Cimientos + harness.** `T1.1`–`T1.6` son costuras de test (`scripts/`); `T1.7`–`T1.10` son producto; `T1.11`/`T1.12` cierran. |
| Depende de | **Ningún gate (Fase 0).** Arranca ya. |
| DoD de la fase | H1–H8 aplicados; las 9 opciones nuevas sembradas; `Write_Gate` reconoce `inventory` sin bloquear el default; `Inventory_Pusher` (ledger) carga por PSR-4; sección `T29` creada; `bash scripts/exec-test.sh` verde. |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`) · `bash scripts/smoke-test.sh` (→ `scripts/smoke-load.php`) |
| Baseline verificado | `bash scripts/exec-test.sh` ⇒ **1626 assertions, 0 failed** (re-corrido en HEAD para esta fase) |
| Versión objetivo | **2.6.0** |

> **Convención de IDs de test (canónica del SDD).** Todo test nuevo de esta fase se nombra
> **`T29.1{n}`** (`T29.11`…`T29.18`, `T29.110`), según `T29.{fase}{n}` (`tasks.md:31`). `T1`–`T28` ya
> están usados; la sección `// === sync-reliability (2.6.0) ===` la crea `T1.11`. Los IDs de **tarea**
> (`T1.1`…`T1.12`) no cambian.

> **Regla de oro (prove-it-catches, obligatoria).** Después de que un test pase: **revertir el fix** →
> `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Cada tarea de
> abajo indica el fix exacto a revertir. Sin el revert→rojo, el test no se acepta.

> **Todos los `file:line` fueron re-verificados leyendo HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

---

## Correcciones de cita y hallazgos (verificados en HEAD)

| # | Claim (design/spec/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `design.md:1001` / `tasks.md:226` H2: "`WC_Product::save()` no deriva `_stock_status` … `wp-stubs.php:1284` (`return $this->id;`) y `:1363` (variación)". | **`:1284` es correcto** (`WC_Product::save()`). **`:1363` es FALSO**: es `WC_Order::save()`. `WC_Product_Variation` (`:1289-1296`) **hereda** el `save()` de `WC_Product`; no tiene uno propio. | `T1.2` edita **sólo `:1284`**. |
| C2 | `tasks.md:230` H7: "`set_syncing`/transient de import no simulados (`wp-stubs.php` + `test-framework.php`)". | **Falso.** `Public_::set_syncing(true)` ya escribe `set_transient('alegra_import_in_progress', 1, 300)` (`Public_.php:433`) y `trigger_sync()` ya lo consulta (`:371`). El harness ya stubea `set_transient`/`get_transient` (`wp-stubs.php:263-276`). | **`T1.6` es test-only**: no toca `wp-stubs.php` ni `test-framework.php`. |
| C3 | `tasks.md:229` H5: "`POST /invoices` rechaza `client.id` inexistente ⇒ 400". | Correcto como capacidad, pero **estricto por defecto rompe el baseline**: `T3.1`/`T3.2` (`exec-test.php:265-326`) facturan con `_billing_alegra_contact_id='c0n-co'/'c0n-mx'` **sin sembrar** el contacto; y `exec-test.php:4748,4775,4792,4809` llaman `api->create_invoice(['client'=>['id'=>'c1']])` sin seed. | **H5 debe ser opt-in**: `alegra_mock_set_invoice_client_check(bool)` default **OFF**, reseteado en `alegra_mock_reset()`. Si no, el baseline cae por debajo de 1626. |
| C4 | `tasks.md:227` H3: "modo CONTAINS en `alegra-mock.php:863`". | `:863` confirmado (`$num === $ident`). **Pero** `alegra_mock_filter_contacts()` (`:849-867`) **tampoco aplica `start`/`limit`** (devuelve todos los filtrados). | **H3b (nueva, ya anotada como C9 en `fase-4-variaciones.md`)**: `T1.3` agrega **CONTAINS + paginación**. Sin paginación, REQ-CF-05 no es testeable. |
| C5 | `tasks.md:305` T1.7: "`$defaults` + `$non_autoload` + `uninstall.php` tienen las 8 opciones". | El `design.md` §11 marca `$defaults` = sí **sólo para 5** (las 3 internas se leen con default); la 9.ª (`open_invoice_on_paid`, Fase 3 C4) también va en `$defaults`. | `T1.7`: **6** en `$defaults`; **9** en `$non_autoload` y `uninstall.php`. `register_setting`/UI **no** es de `T1.7`; dueños explícitos en la nota de `T1.7` (C4). |
| C6 | `tasks.md:309` T1.11: "`alegra_test_reset()` limpia las metas del ledger". | **Ya lo hace**: `alegra_test_reset()` pone `$GLOBALS['wp_postmeta'] = []` (`test-framework.php:148`), y `get/update/delete_post_meta` (`wp-stubs.php:286-305`) operan sobre ese global. | `T1.11` **no** toca el reset; sólo agrega la sección `T29`. |
| C7 | `tasks.md:308` T1.10: "`Inventory_Pusher` (estático) o trait". | El `design.md:1048-1056` define `final class Inventory_Pusher` con métodos de **instancia** (`push_delta`, etc.), y `T3.1` la "crea". Para no chocar, `T1.10` **crea el archivo con la superficie estática del ledger** y `T3.1` **completa** la clase con constructor + instancia. | `T1.10` crea `includes/Sync/Inventory_Pusher.php` (sólo helpers estáticos); `T3.1` agrega `push_delta`/`owner`/hooks. |
| **C8 (FIX-13)** | `fase-7:795-801,836` y `fase-3:81,821`: `GET /items` del mock debe paginar **siempre** (no sólo con `metadata=true`); "lo posee el dueño del harness", pero **ningún task de Fase 1 lo asignaba** (`T1.3` sólo paginaba `/contacts`). | `alegra-mock.php:659-671` aplica `start`/`limit` **sólo** en la rama `metadata=true`; sin metadata devuelve todo (`:671`). El poll (`T7.1`) llama sin metadata ⇒ `T29.71`/`T29.73` son **falsos verdes**. | **`T1.3` ahora posee el fix** (punto 4): pagina en ambas ramas; test en `T29.13`; DoD de Fase 1 actualizado. |

**Confirmaciones exactas (no requieren corrección):** `wp-stubs.php:1230-1285` (`WC_Product`),
`:1284` (`save()`), `:1289-1296` (`WC_Product_Variation`), `:1435-1444` (`wc_get_order`/`wc_get_product`),
`:263-276` (`get/set_transient`); `alegra-mock.php:31-35` (init de estado/globales), `:37-47`
(`alegra_mock_reset`), `:208-265` (`alegra_mock_dispatch`), `:286-297` (validators), `:535-556`
(`alegra_mock_validate_invoice`), `:656-658` (ruta `GET /contacts`), `:703/709/753/767/773` (rutas POST),
`:849-867` (`alegra_mock_filter_contacts`), `:863` (match exacto), `:880-885` (`variantParent_id`);
`Public_.php:371` (guard `trigger_sync`), `:426-437` (`set_syncing`), `:442-444` (`is_syncing`);
`Write_Gate.php:37-46` (`ENTITY_OPTIONS`), `:52-54` (`ENTITY_DEFAULTS`), `:67-77` (`ENTITY_PATTERNS`),
`:90-101` (`entity_for`), `:108-125` (`block_reason`), `:150` (`run_explicit`); `Client.php:242`
(`api_error`), `:761`/`:770` (inventory-adjustments), `:766-769` (docblock `@deprecated`);
`alegra-connector.php:414-457` (`$defaults`), `:463-486` (`$non_autoload`), `:488-492` (loop);
`uninstall.php:75-77` (opciones de inventario); `test-framework.php:144-222` (`alegra_test_reset`);
`exec-test.php:47-79` (factories), `:8017` (`exit(TestRunner::summary())`); `.distignore:15` excluye
`scripts/`.

---

## Contrato canónico de la fase (lo define esta fase; Fase 2/3/5/7 lo consumen)

### K-H1 — Stub de `wc_update_product_stock()` (lo define `T1.1`)

```php
// scripts/lib/wp-stubs.php (función global)
function wc_update_product_stock($product, $qty = null, $operation = 'set', $updating = false);
```

- Setea `stock` cuando `$qty !== null && $operation === 'set'`.
- **Deriva `stock_status`** con el helper compartido `alegra_mock_derive_stock_status($product)`:
  `manage_stock` off ⇒ no toca; `qty > 0` ⇒ `instock`; `qty <= 0 && backorders !== 'no'` ⇒
  `onbackorder`; `qty <= 0 && backorders === 'no'` ⇒ `outofstock`.
- Persiste con `$product->save()` (que en `T1.2` deriva igual) y **dispara**:
  `do_action('woocommerce_product_set_stock', $product)` y, si `$product->is_type('variation')`,
  `do_action('woocommerce_variation_set_stock', $product)`.
- Devuelve `$product->get_stock_quantity()`.
- **Nombres de hook canónicos** (los usa el pusher en `T3.3`): `woocommerce_product_set_stock`,
  `woocommerce_variation_set_stock`.

### K-H2 — `WC_Product::save()` deriva `_stock_status` (lo define `T1.2`)

`WC_Product::save()` (`:1284`) llama `alegra_mock_derive_stock_status($this)` antes de `return $this->id;`.
`WC_Product_Variation` lo hereda (no hay `save()` propio).

### K-H3 — Modo CONTAINS + paginación en `alegra_mock_filter_contacts()` (lo define `T1.3`)

```php
function alegra_mock_set_contact_identification_mode(string $mode): void; // 'exact' (default) | 'contains'
```

- `'exact'` ⇒ `$num === $ident` (comportamiento actual, default).
- `'contains'` ⇒ `str_contains($num, $ident)` (modela el CONTAINS documentado).
- **Además** aplica `start`/`limit` (como `GET /items`): `array_slice($filtered, (int)($query['start'] ?? 0), (int)($query['limit'] ?? 30))`.
- El modo se resetea a `'exact'` en `alegra_mock_reset()`.

### K-FIX13 — Paginación de `GET /items` sin `metadata=true` (lo define `T1.3`)

Hoy `alegra-mock.php:659-671` sólo aplica `array_slice($filtered, $start, $limit)` en la rama
`metadata=true`; **sin** `metadata` devuelve **todo** el catálogo filtrado (`:671`). El poll de inventario
(`T7.1`) llama `GET /items` **sin** metadata ⇒ `count($items) >= 30` siempre y los tests de cursor
(`T29.71`/`T29.73`) son **falsos verdes** hasta que `T1.3` aplique `start`/`limit` en **ambas** ramas.
La respuesta sin metadata sigue siendo la **lista plana** (no se envuelve en `{metadata,data}`).

### K-H4 / K-H6 — Ruta `POST /inventory-adjustments` + modelo de stock (lo define `T1.4`)

Payload: `{date, type:'in'|'out', quantity:int>0, item:{id}, warehouse?:{id}}`.
Efecto: `alegra_mock_state['items'][item_id]['inventory']['availableQuantity'] += (type==='out' ? -quantity : quantity)`
(clamp a `>= 0`). Item inexistente ⇒ `400 {code:400, message:'El item no existe'}`.

### K-H5 — Chequeo opt-in de `client.id` (lo define `T1.5`)

```php
function alegra_mock_set_invoice_client_check(bool $on): void; // default false
```

Con `true`, `POST /invoices` con un `client.id` no presente en `alegra_mock_state['contacts']` ⇒
`400 {message:'El cliente no existe'}`. Con `false` (default), comportamiento actual. Se resetea en
`alegra_mock_reset()`.

### K-H7 — `set_syncing` (NO requiere código)

`Public_::set_syncing(true)` ⇒ transient `alegra_import_in_progress` (`Public_.php:433`);
`trigger_sync()` lo corta (`:371`). El harness ya lo observa.

### K-H8 — Opciones nuevas (lo define `T1.7`)

| Opción | Tipo | Default | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|---|
| `alegra_connector_push_inventory_enabled` | bool | `true` | sí | sí | sí |
| `alegra_connector_inventory_manage_stock_enabled` | bool | `false` | sí | sí | sí |
| `alegra_connector_inventory_poll_budget` | int | `60` | sí | sí | sí |
| `alegra_connector_inventory_poll_max_pages` | int | `0` | sí | sí | sí |
| `alegra_connector_cron_run_budget` | int | `540` | sí | sí | sí |
| `alegra_connector_open_invoice_on_paid` | bool | `true` | sí | sí | sí |
| `alegra_connector_inventory_pull_cursor` | int | `0` | no (interno) | sí | sí |
| `alegra_connector_inventory_pull_total` | int | `0` | no (interno) | sí | sí |
| `alegra_connector_consumidor_final_probe` | array | ausente | no (interno) | sí | sí |

> **C1 (9.ª opción).** La opción `alegra_connector_open_invoice_on_paid` la introduce Fase 3 (C4);
> `T1.7` la siembra junto a las otras 8. **Total: 9 opciones nuevas** (6 en `$defaults`, 9 en
> `$non_autoload` + `uninstall.php`).

### K-P — Ledger de stock (lo define `T1.10`; lo consume `T3.x`)

```php
namespace Alegra\Connector\Sync;

final class Inventory_Pusher
{
    public const META_SYNCED  = '_alegra_stock_synced';
    public const META_PENDING = '_alegra_stock_push_pending';

    public static function synced(int $product_id): int|string;   // '' si ausente, int si existe
    public static function set_synced(int $product_id, int $qty): void;
    public static function pending(int $product_id): int|string;  // '' si ausente, int si existe
    public static function set_pending(int $product_id, int $qty): void;
    public static function clear_pending(int $product_id): void;
}
```

`T3.1` agrega a la misma clase: `__construct(?API\Client, ?Logger)`, `register_hooks(?API\Client, ?Logger\Logger)`,
`on_stock_changed()`, `on_variation_stock_changed()`, `push_delta()`, `owner()`,
`resolve_warehouse_id()`. **No** redefine los helpers.

> **Uso obligatorio de los helpers (C5).** `T3.2`/`T3.4` (Fase 3) **deben** leer/escribir el ledger vía
> estos helpers (`Inventory_Pusher::synced()`, `set_synced()`, `pending()`, `set_pending()`,
> `clear_pending()`). El uso de `get_post_meta`/`update_post_meta`/`delete_post_meta` crudo con
> `_alegra_stock_synced`/`_alegra_stock_push_pending` en el borrador de Fase 3 queda **superseded**. Se
> **mantienen** los helpers (no se borran): no hay código muerto.

---

### T1.1 — H1: stub `wc_update_product_stock()` · `scripts/`

**Objetivo**: darle al harness la API de WooCommerce que el escritor único (D5) y el pusher usan, de
modo que el stock se derive y los hooks de stock se disparen. Sin esto, D5 y el pusher no son
testeables (DR16).

**Descripción técnica**: hoy `wc_update_product_stock()` tiene **0 coincidencias** en producción y en
`scripts/lib/wp-stubs.php` (`grep` verificado). D5 (`design.md` §6.1) adopta
`wc_update_product_stock($p,$qty,'set',false)` como único punto de escritura; el harness no lo tiene, así
que `Inventory_Writer::apply_stock()` caería al fallback y la derivación de `_stock_status` + los hooks
serían inobservables. Cubre **REQ-INV-04**. La firma real del core es
`wc_update_product_stock( $product, $qty = null, $operation = 'set', $updating = false )` (el 4.º
argumento existe desde WC 6.2; en versiones previas PHP ignora el extra).

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/wp-stubs.php`. Insertar **después** de `wc_get_product()` (`:1440-1444`),
  antes de `wc_get_product_id_by_sku()` (`:1563`).

- **Bloque nuevo** (helper compartido + stub):

  ```php
  /**
   * Deriva `stock_status` desde `manage_stock` + cantidad + backorders,
   * replicando WC_Product::validate_props() (WC >= 3.0). Compartido por
   * `wc_update_product_stock()` (T1.1) y `WC_Product::save()` (T1.2).
   */
  function alegra_mock_derive_stock_status($product): void
  {
      if (!$product instanceof WC_Product) { return; }
      if (!$product->get_manage_stock()) { return; }
      $qty = $product->get_stock_quantity();
      if ($qty === null) { return; }
      if ($qty > 0) {
          $product->set_stock_status('instock');
      } elseif ($product->get_backorders() !== 'no') {
          $product->set_stock_status('onbackorder');
      } else {
          $product->set_stock_status('outofstock');
      }
  }

  /**
   * Harness stub of the WC core API. Sets the quantity, derives the status and
   * fires the stock hooks the pusher listens to (T3.3).
   *
   * @return int|null New quantity, or null when the product cannot manage stock.
   */
  function wc_update_product_stock($product, $qty = null, $operation = 'set', $updating = false)
  {
      if (!$product instanceof WC_Product) { return null; }
      if ($qty !== null && $operation === 'set') {
          $product->set_stock_quantity((int) $qty);
      }
      alegra_mock_derive_stock_status($product);
      if (!$updating) {
          $product->save();
      }
      do_action('woocommerce_product_set_stock', $product);
      if ($product->is_type('variation')) {
          do_action('woocommerce_variation_set_stock', $product);
      }
      return $product->get_stock_quantity();
  }
  ```

- **Orden:** el helper se define antes del stub (mismo archivo). `WC_Product` ya existe (`:1230-1285`).
- **Nota de alcance:** el stub **siempre** dispara los hooks (real WC sólo cuando `managing_stock()`);
  en el harness el writer ya hizo `set_manage_stock(true)`, así que es equivalente y hace `T29.11`
  determinista.

- **Alcance adicional — stub `register_shutdown_function` (C10).** `T1.1` es la **dueña** de este stub
  en `scripts/lib/wp-stubs.php` (hoy **0 matches**). Debe registrar los callbacks y exponer un helper
  `alegra_mock_run_shutdown_callbacks()` para ejecutarlos, porque `T8.6`/`T29.81` (Fase 8) los consumen
  y `T1.6` **no** toca el harness. `T8.6` deja de asignar este stub "a `T1.1`–`T1.6`" en bloque: es de
  `T1.1`.

**Resultado esperado**: `function_exists('wc_update_product_stock')` true en el harness; setear `qty=0`
con `manage_stock=true` y `backorders=no` deja `stock_status='outofstock'`; con `backorders=yes` deja
`onbackorder`; `$GLOBALS['wp_did_action']['woocommerce_product_set_stock'] >= 1`; y
`function_exists('register_shutdown_function')` + `alegra_mock_run_shutdown_callbacks()` ejecutan los
callbacks registrados (stub de C10).

**Dependencias**: ninguna. Prerequisito de **T2.1**, **T2.8**, **T3.3**, **T3.7**.

**Trazabilidad**: REQ-INV-04; D5; DR12/DR16; C10 (stub `register_shutdown_function`); `design.md` §9.2.

**Verificación**: test nuevo en la sección `T29` (creada en `T1.11`):

```php
TestRunner::test('T29.11 wc_update_product_stock deriva stock_status y dispara hooks', function (): void {
    alegra_test_reset();
    $p = alegra_make_product(900, ['manage_stock' => true, 'stock' => 5, 'backorders' => 'no']);
    $ret = wc_update_product_stock($p, 0, 'set', false);
    TestRunner::assertSame(0, $ret, 'devuelve la cantidad nueva');
    TestRunner::assertSame('outofstock', $p->get_stock_status(), 'qty 0 + backorders no => outofstock');
    TestRunner::assertTrue(($GLOBALS['wp_did_action']['woocommerce_product_set_stock'] ?? 0) >= 1, 'dispara woocommerce_product_set_stock');
});
```

Correr `bash scripts/exec-test.sh` → `EXEC-TEST OK`. **Prove-it-catches:** borrar
`alegra_mock_derive_stock_status()` del stub ⇒ `T29.11` rojo (queda `instock`).

**Riesgo**: que `save()` derive **dos** veces o que el hook cambie de nombre. **Guard**: la derivación
es idempotente; el nombre `woocommerce_product_set_stock` lo consumen `T29.11` y `T29.21` (Fase 2).

**Estimación**: M (2 h).

---

### T1.2 — H2: `WC_Product::save()` deriva `stock_status` · `scripts/`

**Objetivo**: que `save()` recalcule `stock_status` desde cantidad + backorders (como WC ≥ 3.0), para
que un `save()` directo (fallback del writer, tests) sea consistente con la API recomendada.

**Descripción técnica**: `WC_Product::save()` es hoy `return $this->id;` (`wp-stubs.php:1284`). El core
de WC llama `validate_props()` (`abstract-wc-product.php:1547`) y deriva
`instock`/`onbackorder`/`outofstock` (`:1517-1538`). Sin esta derivación, el fallback del writer y el
camino `set_stock_quantity()+save()` no reflejan backorders. Cubre **REQ-INV-04** (escenario
"backorders=no idéntico a HEAD"). **`WC_Product_Variation` (`:1289-1296`) hereda este `save()`; NO
existe un `save()` propio de variación** (corrección C1: `:1363` es `WC_Order::save()`).

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/wp-stubs.php:1284`.

- **ANTES:**

  ```php
  public function save() { return $this->id; }
  ```

- **DESPUÉS:**

  ```php
  public function save()
  {
      // Replica WC_Product::validate_props() (WC >= 3.0): el estado se deriva
      // de cantidad + backorders, no se fuerza desde el plugin (D5).
      if (function_exists('alegra_mock_derive_stock_status')) {
          alegra_mock_derive_stock_status($this);
      }
      return $this->id;
  }
  ```

- **Dependencia de orden:** usa el helper que define `T1.1`. `T1.1` va primero.
- **Sin cambio de firma** ni de tipo de retorno.

**Resultado esperado**: `manage_stock=true`, `stock=0`, `backorders=yes` ⇒ `onbackorder`;
`backorders=no` ⇒ `outofstock`; `manage_stock=false` ⇒ no cambia el estado.

**Dependencias**: **T1.1** (helper). Prerequisito de **T2.1**, **T2.8**.

**Trazabilidad**: REQ-INV-04; D5; `design.md` §6.1/§9.2.

**Verificación**: test nuevo en la sección `T29`:

```php
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
```

**Prove-it-catches:** quitar la llamada a `alegra_mock_derive_stock_status()` de `save()` ⇒ `T29.12`
rojo.

**Riesgo (alto):** `save()` lo llaman muchísimos tests; la derivación puede cambiar aserciones
existentes. **Guard**: sólo deriva con `manage_stock` on; correr el harness completo y **verificar que
el conteo no baje de 1626** ni aparezcan `failed`. Si baja, encontrar el test que asumía el no-op y
decidir explícitamente (corregir el test o acotar la derivación).

**Estimación**: S (1 h).

---

### T1.3 — H3 + H3b + FIX-13: modo CONTAINS y paginación de contactos y de `GET /items` · `scripts/`

**Objetivo**: poder simular que el filtro `identification` de Alegra es **CONTAINS** y que la respuesta
**pagina** por `start`/`limit`, para probar REQ-CF-05 (barrido completo, sin perder el CF). Además, que
`GET /items` pagine **siempre** (no sólo con `metadata=true`), prerequisito de los tests de cursor del
poll (**FIX-13**, cierra el falso verde de `T29.71`/`T29.73`).

**Descripción técnica**: `alegra_mock_filter_contacts()` (`alegra-mock.php:849-867`) compara
**igualdad exacta** (`:863`, `$num === $ident`) y **no aplica `start`/`limit`** (devuelve todos). El
filtro real de Alegra es CONTAINS y `limit=5` puede perder el CF entre falsos positivos (hallazgo A4).
El default del mock debe seguir **exacto** para no romper los tests existentes. Cubre **REQ-CF-05**;
H3b la registró `fase-4-variaciones.md` (C9). Consumido por `T5.1`/`T5.7`. En paralelo, `GET /items`
(`:659-671`) sólo pagina en la rama `metadata=true`; el poll llama sin metadata ⇒ hay que paginar en
**ambas** ramas (**FIX-13**; consumido por `T7.1`/`T7.2`).

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/alegra-mock.php`.

- **1) Global + reset.** En la init (`:31-35`) agregar:
  ```php
  $GLOBALS['alegra_mock_contact_identification_mode'] = 'exact';
  ```
  Y en `alegra_mock_reset()` (`:37-47`), junto a los demás resets:
  ```php
  $GLOBALS['alegra_mock_contact_identification_mode'] = 'exact';
  ```

- **2) Setter nuevo** (junto a `alegra_mock_set_contact_fiscal_required()`, `:54-57`):
  ```php
  /**
   * Model the documented CONTAINS semantics of `identification`. The default
   * ('exact') mirrors the old mock so existing tests are untouched.
   */
  function alegra_mock_set_contact_identification_mode(string $mode): void
  {
      $GLOBALS['alegra_mock_contact_identification_mode'] = $mode === 'contains' ? 'contains' : 'exact';
  }
  ```

- **3) `alegra_mock_filter_contacts()` — CONTAINS + paginación.** **ANTES** (`:858-866`):
  ```php
  if (!empty($query['identification'])) {
      $ident = (string) $query['identification'];
      $contacts = array_values(array_filter($contacts, static function ($c) use ($ident) {
          $obj = $c['identificationObject'] ?? null;
          $num = is_array($obj) ? (string) ($obj['number'] ?? '') : (string) ($c['identification'] ?? '');
          return $num === $ident;
      }));
  }
  return $contacts;
  ```

  **DESPUÉS**:
  ```php
  if (!empty($query['identification'])) {
      $ident = (string) $query['identification'];
      $mode  = (string) ($GLOBALS['alegra_mock_contact_identification_mode'] ?? 'exact');
      $contacts = array_values(array_filter($contacts, static function ($c) use ($ident, $mode) {
          $obj = is_array($c['identificationObject'] ?? null) ? $c['identificationObject'] : null;
          $num = $obj !== null ? (string) ($obj['number'] ?? '') : (string) ($c['identification'] ?? '');
          return $mode === 'contains' ? str_contains($num, $ident) : $num === $ident;
      }));
  }
  // Alegra paginates: apply start/limit like GET /items does.
  $start = (int) ($query['start'] ?? 0);
  $limit = (int) ($query['limit'] ?? 30);
  return array_slice($contacts, $start, $limit);
  ```

- **Nota:** el route `GET /contacts` (`:656-658`) ya pasa el `$query` completo; no hay que tocarlo.

- **4) `GET /items` — paginar en ambas ramas (FIX-13).** **Archivo:** `scripts/lib/alegra-mock.php:659-671`.

  **ANTES** (sin `metadata` devuelve todo el catálogo):
  ```php
  if ($method === 'GET' && $path === '/items') {
      $filtered = alegra_mock_filter_items($query);
      // `metadata=true` wraps the list as {metadata:{total}, data:[]}, as the
      // documented endpoint does. Used by ajax_sync_start for the exact total.
      if (filter_var($query['metadata'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
          $start = (int) ($query['start'] ?? 0);
          $limit = (int) ($query['limit'] ?? 30);
          return alegra_mock_response(200, [
              'metadata' => ['total' => count($filtered)],
              'data'     => array_slice($filtered, $start, $limit),
          ]);
      }
      return alegra_mock_response(200, $filtered);
  }
  ```

  **DESPUÉS** (`start`/`limit` se calculan una vez y aplican en las dos ramas):
  ```php
  if ($method === 'GET' && $path === '/items') {
      $filtered = alegra_mock_filter_items($query);
      $start = (int) ($query['start'] ?? 0);
      $limit = (int) ($query['limit'] ?? 30);
      // `metadata=true` wraps the list as {metadata:{total}, data:[]}, as the
      // documented endpoint does. Used by ajax_sync_start for the exact total.
      // FIX-13: the inventory poll calls GET /items WITHOUT metadata, so
      // start/limit must slice the plain list too.
      if (filter_var($query['metadata'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
          return alegra_mock_response(200, [
              'metadata' => ['total' => count($filtered)],
              'data'     => array_slice($filtered, $start, $limit),
          ]);
      }
      return alegra_mock_response(200, array_slice($filtered, $start, $limit));
  }
  ```

  La rama sin metadata sigue devolviendo la **lista plana** (contrato del `Client::get_items()`); sólo se
  recorta. La rama con metadata conserva `{metadata.total, data}`.

**Resultado esperado**: con `'contains'`, `222222222222` matchea todo número que lo **contenga**; el
default `'exact'` se comporta igual que hoy; `start`/`limit` recortan la página. `GET /items` **sin**
`metadata` respeta `start`/`limit` (la lista plana se recorta); con `metadata=true` sigue devolviendo
`{metadata.total, data}` paginado.

**Dependencias**: ninguna. Prerequisito de **T5.1**, **T5.7** y de los tests de cursor del poll
(**T7.1**/**T7.2**, FIX-13).

**Trazabilidad**: REQ-CF-05, REQ-POLL-01/02; `design.md` §2.5/§9.2; H3b; FIX-13 (Oracle D7).

**Verificación**: test nuevo en la sección `T29`:

```php
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
```

**Prove-it-catches:** revertir `str_contains` a `===` ⇒ la aserción `count($contains)===2` falla;
quitar el `array_slice` de contactos ⇒ la aserción de `limit` falla; revertir `GET /items` a
`return alegra_mock_response(200, $filtered)` en la rama sin metadata ⇒ las aserciones `limit=2`/
segunda página fallan (FIX-13).

**Riesgo**: que el default cambie y rompa tests de contactos; y que el corte a 30 de `GET /items`
recorte un test viejo que esperaba el catálogo completo sin paginar. **Guard**: default `'exact'`
idéntico a hoy + reset en `alegra_mock_reset()`; todos los callers de producción
(`Products.php:886,1205,1396`, `Orders.php`, `Admin_Dashboard.php:4376`) pasan `start`/`limit`
explícitos, y los tests existentes siembran ≤ 30 ítems de Alegra ⇒ correr el harness y comparar el
conteo contra 1626.

**Estimación**: M (2 h) — se sumó el seam de `GET /items` (FIX-13).

---

### T1.4 — H4/H6: ruta `POST /inventory-adjustments` + modelo de stock por ítem · `scripts/`

**Objetivo**: que el mock acepte un ajuste de inventario y **aplique el delta** al stock del ítem, para
poder probar el titular (vender → push → poll no re-infla). Sin esto, D2(a) no es testeable.

**Descripción técnica**: `scripts/lib/alegra-mock.php` tiene **0 coincidencias** de
`inventory-adjustments`; las rutas POST viven en `:703`/`:709`/`:753`/`:767`/`:773`. El modelo de estado
es `$GLOBALS['alegra_mock_state']['items'][id]['inventory']['availableQuantity']` (los ítems guardan
`inventory` sólo si se sembró; `alegra_mock_seed_item()` `:70-73`). `Client::create_inventory_adjustment()`
(`Client.php:770`) hace `POST /inventory-adjustments`. Cubre **REQ-INV-01** y **REQ-INV-08**;
consumido por `T3.7` y `T5.7`.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/alegra-mock.php`.

- **1) Validador de write.** En `alegra_mock_write_validators()` (`:286-297`), agregar la regla:
  ```php
  ['method' => 'POST', 'pattern' => '#^/inventory-adjustments$#', 'validator' => 'alegra_mock_validate_inventory_adjustment'],
  ```
  Y la función (junto a `alegra_mock_validate_invoice()`, `:535`):
  ```php
  /**
   * POST /inventory-adjustments. Required: item.id, type in|out, quantity > 0.
   */
  function alegra_mock_validate_inventory_adjustment(array $body): ?array
  {
      if (empty($body['item']) || !is_array($body['item']) || empty($body['item']['id'])) {
          return alegra_mock_validation_error('El campo item.id es obligatorio');
      }
      if (($body['type'] ?? '') !== 'in' && ($body['type'] ?? '') !== 'out') {
          return alegra_mock_validation_error('El campo type debe ser in u out');
      }
      if (!isset($body['quantity']) || !is_numeric($body['quantity']) || (int) $body['quantity'] <= 0) {
          return alegra_mock_validation_error('El campo quantity debe ser numerico y mayor a cero');
      }
      return null;
  }
  ```

- **2) Ruta.** En `alegra_mock_route()`, insertar **después** de la ruta `POST /payments` (`:773-778`) y
  **antes** de `POST /invoices/{id}/void` (`:779`):
  ```php
  if ($method === 'POST' && $path === '/inventory-adjustments') {
      $item_id = (string) ($body['item']['id'] ?? '');
      $qty     = (int) ($body['quantity'] ?? 0);
      $type    = (string) ($body['type'] ?? 'in');
      $item    = $GLOBALS['alegra_mock_state']['items'][$item_id] ?? null;
      if ($item === null) {
          return alegra_mock_response(400, ['code' => 400, 'message' => 'El item no existe']);
      }
      $current = (int) ($item['inventory']['availableQuantity'] ?? 0);
      $delta   = $type === 'out' ? -$qty : $qty;
      $GLOBALS['alegra_mock_state']['items'][$item_id]['inventory']['availableQuantity'] = max(0, $current + $delta);
      return alegra_mock_response(200, [
          'id'       => alegra_mock_uuid('a9a9a9a9'),
          'date'     => (string) ($body['date'] ?? ''),
          'type'     => $type,
          'quantity' => $qty,
          'item'     => ['id' => $item_id],
      ]);
  }
  ```

- **3) Modelo de stock (H6).** No hace falta un campo nuevo: se usa
  `items[id]['inventory']['availableQuantity']`. Los tests siembran el ítem con
  `alegra_mock_seed_item('it-x', ['name' => 'X', 'inventory' => ['availableQuantity' => 10]])`.

- **Orden:** validador primero (se ejecuta en `alegra_mock_dispatch()` `:259-262`); la ruta después.

**Resultado esperado**: `POST /inventory-adjustments` válido ⇒ 200 y el delta aplicado; `type=out`
resta; `GET /items/{id}` (`:693-696`) refleja el nuevo `availableQuantity`; payload inválido ⇒ 400.

**Dependencias**: ninguna. Prerequisito de **T3.7**, **T5.7**.

**Trazabilidad**: REQ-INV-01, REQ-INV-08; D2(a); DR16; `design.md` §9.2.

**Verificación**: test nuevo en la sección `T29`:

```php
TestRunner::test('T29.14 POST /inventory-adjustments aplica el delta al stock del item', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('it-adj', ['name' => 'Adj', 'inventory' => ['availableQuantity' => 10]]);
    $api = make_api();

    $out = $api->create_inventory_adjustment([
        'date' => '2026-09-25', 'type' => 'out', 'quantity' => 3, 'item' => ['id' => 'it-adj'],
    ]);
    TestRunner::assertFalse(is_wp_error($out), 'el ajuste out debe aceptarse');
    TestRunner::assertSame(7, (int) $GLOBALS['alegra_mock_state']['items']['it-adj']['inventory']['availableQuantity'], 'out resta');

    $in = $api->create_inventory_adjustment([
        'date' => '2026-09-25', 'type' => 'in', 'quantity' => 2, 'item' => ['id' => 'it-adj'],
    ]);
    TestRunner::assertFalse(is_wp_error($in), 'el ajuste in debe aceptarse');
    TestRunner::assertSame(9, (int) $GLOBALS['alegra_mock_state']['items']['it-adj']['inventory']['availableQuantity'], 'in suma');

    $bad = $api->create_inventory_adjustment(['type' => 'out', 'quantity' => 0, 'item' => ['id' => 'it-adj']]);
    TestRunner::assertTrue(is_wp_error($bad), 'quantity 0 => 400 (quantity debe ser > 0)');
});
```

**Prove-it-catches:** quitar la ruta ⇒ `T29.14` rojo (404 del mock).

**Riesgo**: que el validador rechace el payload real del `design.md` §3.4. **Guard**: `T29.14` usa el
payload exacto; si G3 (`T0.3`) revela campos extra, se ajusta el validador antes de `T3.1`.

**Estimación**: M (2 h).

---

### T1.5 — H5: `POST /invoices` rechaza un `client.id` inexistente (opt-in) · `scripts/`

**Objetivo**: hacer real el 400 por client id muerto para poder probar el self-heal del CF. El chequeo
es **opt-in** para no romper el baseline.

**Descripción técnica**: `alegra_mock_validate_invoice()` (`alegra-mock.php:535-556`) sólo valida la
**presencia** de `client.id`. El self-heal (REQ-CF-06) necesita que un `client.id` muerto devuelva
400. **Pero** activarlo por defecto rompe el baseline: `T3.1`/`T3.2` (`exec-test.php:265-326`) facturan
con contactos **no sembrados** (`c0n-co`/`c0n-mx`) y hay 4 tests que pasan `['client'=>['id'=>'c1']]`
(`exec-test.php:4748,4775,4792,4809`). Por eso H5 se implementa con un **flag** default **OFF** (mismo
patrón que `alegra_mock_set_contact_fiscal_required()`), y el test lo prende. Cubre **REQ-CF-06**.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/alegra-mock.php`.

- **1) Global + reset.** En la init (`:31-35`):
  ```php
  $GLOBALS['alegra_mock_invoice_client_check'] = false;
  ```
  En `alegra_mock_reset()` (`:37-47`):
  ```php
  $GLOBALS['alegra_mock_invoice_client_check'] = false;
  ```

- **2) Setter** (junto a `alegra_mock_set_contact_fiscal_required()`, `:54-57`):
  ```php
  /**
   * Opt-in: when ON, POST /invoices rejects a `client.id` that is not present in
   * the mock's contacts. OFF by default so the existing invoice tests (which use
   * placeholder client ids) keep passing.
   */
  function alegra_mock_set_invoice_client_check(bool $on): void
  {
      $GLOBALS['alegra_mock_invoice_client_check'] = $on;
  }
  ```

- **3) Chequeo** en `alegra_mock_validate_invoice()` (`:535-556`), **después** del guard de presencia
  (`:537-539`) y antes del de `items`:
  ```php
  if (!empty($GLOBALS['alegra_mock_invoice_client_check'])) {
      $client_id = (string) ($body['client']['id'] ?? '');
      if ($client_id !== '' && !isset($GLOBALS['alegra_mock_state']['contacts'][$client_id])) {
          return alegra_mock_validation_error('El cliente no existe');
      }
  }
  ```

**Resultado esperado**: con el flag ON, `client.id='dead'` no sembrado ⇒ `WP_Error` code 400 con
`message` "El cliente no existe"; con el flag OFF (default), el comportamiento es el de hoy y el
baseline sigue en 1626.

**Dependencias**: ninguna. Prerequisito de **T5.5**, **T5.7**.

**Trazabilidad**: REQ-CF-06; D1 §2.4; `design.md` §9.2.

**Verificación**: test nuevo en la sección `T29`:

```php
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
```

**Prove-it-catches:** quitar el bloque del chequeo ⇒ `T29.15` rojo (no hay WP_Error). Y verificar que
**con el flag OFF** el harness sigue en 1626/0 (si se activara por default, `T3.1`/`T3.2` y los 4 tests
de `c1` caerían).

**Riesgo (alto si se hace mal):** activarlo por default rompe el baseline. **Guard**: default `false` +
reset en `alegra_mock_reset()` + correr el harness completo.

**Estimación**: S (1 h).

---

### T1.6 — H7: `set_syncing` observable (test-only) · `scripts/`

**Objetivo**: dejar un test que pruebe que `Public_::set_syncing(true)` corta `trigger_sync()` vía el
transient `alegra_import_in_progress`, prerequisito de REQ-POLL-04.

**Descripción técnica**: `Public_::set_syncing(true)` **ya** escribe
`set_transient('alegra_import_in_progress', 1, 300)` (`Public_.php:433`) y `trigger_sync()` ya lo
consulta (`:371`). El harness ya stubea transients (`wp-stubs.php:263-276`). Por lo tanto **no hay
código de harness que agregar**: esta tarea es un **test de regresión** que fija el contrato que
`T7.3` va a usar (refresco por página, DR8). Cubre **REQ-POLL-04**. (Corrección C2: la fila H7 del
skeleton citaba cambios en `wp-stubs.php`/`test-framework.php` que no hacen falta.)

**Desarrollo técnico**:

- **Archivos:** sólo `scripts/exec-test.php` (test nuevo). **No** tocar `wp-stubs.php` ni
  `test-framework.php`.

**Resultado esperado**: `set_syncing(true)` ⇒ `get_transient('alegra_import_in_progress')` truthy;
`set_syncing(false)` ⇒ null; y un `trigger_sync()` disparado con el transient puesto **no** llama a
`sync_entity` (corta la cascada).

**Dependencias**: ninguna. Prerequisito de **T7.3**, **T7.6**.

**Trazabilidad**: REQ-POLL-04; `design.md` §8.2; DR8.

**Verificación**: test nuevo en la sección `T29`:

```php
TestRunner::test('T29.16 set_syncing(true) es observable por el transient de import', function (): void {
    alegra_test_reset();
    TestRunner::assertSame(null, get_transient('alegra_import_in_progress'), 'sin syncing no hay transient');

    \Alegra\Connector\Public\Public_::set_syncing(true);
    TestRunner::assertTrue((bool) get_transient('alegra_import_in_progress'), 'set_syncing(true) escribe el transient');

    \Alegra\Connector\Public\Public_::set_syncing(false);
    TestRunner::assertSame(null, get_transient('alegra_import_in_progress'), 'set_syncing(false) lo borra');
});
```

**Prove-it-catches:** cambiar `set_transient('alegra_import_in_progress', …)` por un no-op en
`Public_::set_syncing()` (`:432-436`) ⇒ la segunda aserción falla.

**Riesgo**: ninguno (test de contrato existente). Si el test ya existiera, **no** duplicarlo: verificar
con `grep -n "alegra_import_in_progress" scripts/exec-test.php` antes de agregarlo.

**Estimación**: S (1 h).

---

### T1.7 — H8: sembrar las 9 opciones nuevas · producto

**Objetivo**: que las 9 opciones del cambio (las 8 de `design.md` §11 + `alegra_connector_open_invoice_on_paid`
de Fase 3 C4) existan con defaults seguros en activación y se limpien en `uninstall.php`, sin pisar
valores elegidos por el comerciante.

**Descripción técnica**: las opciones `push_inventory_enabled`, `inventory_manage_stock_enabled`,
`inventory_poll_budget`, `inventory_poll_max_pages`, `cron_run_budget`, `open_invoice_on_paid`,
`inventory_pull_cursor`, `inventory_pull_total` y `consumidor_final_probe` (9) tienen **0 coincidencias**
en producción. El patrón es
el de `alegra-connector.php:414-457` (`$defaults`) + `:463-486` (`$non_autoload`) + `:488-492` (loop
`get_option($key) === false ⇒ add_option`), y el de `uninstall.php` (`delete_option` explícitos).
Cubre **NFR-04**. Consumido por `T2.5`, `T3.2`, `T7.1`, `T8.2`.

**Desarrollo técnico**:

- **Archivo 1:** `alegra-connector.php`.

  **1a) `$defaults` (`:414-457`)** — agregar **las 6 sembrables** (no las internas), junto al bloque de
  inventario (`:445-449`):
  ```php
  // 2.6.0: WC → Alegra inventory push (owner=adjustment). Default true salvo G3.
  'alegra_connector_push_inventory_enabled' => true,
  // 2.6.0: legacy manage_stock=no migration opt-in (D3.4).
  'alegra_connector_inventory_manage_stock_enabled' => false,
  // 2.6.0: poll budget (D6).
  'alegra_connector_inventory_poll_budget' => 60,
  'alegra_connector_inventory_poll_max_pages' => 0,
  // 2.6.0: global cron run budget (D7).
  'alegra_connector_cron_run_budget' => 540,
  // 2.6.0 (Fase 3 C4): con dueño invoice, un pedido pagado nace `open`.
  'alegra_connector_open_invoice_on_paid' => true,
  ```

  **1b) `$non_autoload` (`:463-486`)** — agregar **las 9** (las 3 internas quedan inertes en el loop
  porque no están en `$defaults`, pero se listan para cuando se creen por primera vez):
  ```php
  'alegra_connector_push_inventory_enabled',
  'alegra_connector_inventory_manage_stock_enabled',
  'alegra_connector_inventory_poll_budget',
  'alegra_connector_inventory_poll_max_pages',
  'alegra_connector_cron_run_budget',
  'alegra_connector_open_invoice_on_paid',
  'alegra_connector_inventory_pull_cursor',
  'alegra_connector_inventory_pull_total',
  'alegra_connector_consumidor_final_probe',
  ```

- **Archivo 2:** `uninstall.php` — agregar 9 `delete_option()` junto a las de inventario (`:75-77`):
  ```php
  delete_option('alegra_connector_push_inventory_enabled');
  delete_option('alegra_connector_inventory_manage_stock_enabled');
  delete_option('alegra_connector_inventory_poll_budget');
  delete_option('alegra_connector_inventory_poll_max_pages');
  delete_option('alegra_connector_cron_run_budget');
  delete_option('alegra_connector_open_invoice_on_paid');
  delete_option('alegra_connector_inventory_pull_cursor');
  delete_option('alegra_connector_inventory_pull_total');
  delete_option('alegra_connector_consumidor_final_probe');
  ```

- **Nota (corrección C4/C5):** `register_setting` y los controles de UI **no** son de `T1.7`. El dueño
  de cada uno queda **asignado explícitamente** (cierra el gap "sin dueño"):

  | Opción | `register_setting` + UI (dueño) | Pestaña |
  |---|---|---|
  | `alegra_connector_push_inventory_enabled` | **`T3.5`** | Sincronización |
  | `alegra_connector_inventory_manage_stock_enabled` | **`T2.5`** (ya lo declara) | Sincronización |
  | `alegra_connector_inventory_poll_budget` | **`T7.4`** | Avanzado |
  | `alegra_connector_inventory_poll_max_pages` | **`T7.4`** | Avanzado |
  | `alegra_connector_cron_run_budget` | **`T8.2`** (ya lo declara) | Avanzado |
  | `alegra_connector_open_invoice_on_paid` | **`T3.5`** (ya lo declara) | Sincronización |
  | `inventory_pull_cursor` / `_pull_total` / `consumidor_final_probe` | internas: **sin** UI ni `register_setting` | — |

  El `$defaults` del loop no pisa valores: sólo siembra si `get_option($key) === false` (`:489`).

**Resultado esperado**: en activación, las 6 sembrables quedan con su default; una instalación existente
con valor elegido no se pisa; `uninstall.php` limpia las 9.

**Dependencias**: ninguna. Prerequisito de **T2.5**, **T3.2**, **T7.1**, **T8.2**.

**Trazabilidad**: NFR-04; `design.md` §11; `tasks.md` H8.

**Verificación**: test nuevo en la sección `T29` (source-scan, patrón de `T28.717`):

```php
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
```

**Prove-it-catches:** quitar una opción de `$defaults` ⇒ la aserción correspondiente falla.

**Riesgo**: sembrar `push_inventory_enabled=true` cuando G3 (`T0.3`) haya dado **rama B** (schema no
coincide). **Guard**: si G3 = B, cambiar el default a `false` en esta tarea (una línea) y reportarlo.

**Estimación**: M (1.5 h).

---

### T1.8 — `Write_Gate` entidad `inventory` (+ `ENTITY_DEFAULTS`) · producto

**Objetivo**: que el ajuste `POST /inventory-adjustments` se clasifique como entidad `inventory` y que,
con la opción ausente en una instalación existente, el gate **no** lo bloquee (default `true`).

**Descripción técnica**: `Write_Gate::entity_for()` (`:90-101`) resuelve la entidad por `ENTITY_PATTERNS`
(`:67-77`); `block_reason()` (`:108-125`) consulta `ENTITY_OPTIONS` (`:37-46`) y usa
`ENTITY_DEFAULTS[$entity] ?? false` (`:123`). El `design.md` §10 agrega `ENTITY_OPTIONS['inventory']` y
`ENTITY_PATTERNS[]`, pero **omite `ENTITY_DEFAULTS`**: como `push_inventory_enabled` está **ausente** en
una instalación existente, `block_reason()` devolvería `entity_disabled` y **bloquearía el ajuste**
(gap R15, `tasks.md` §12.2 #25). Hay que agregar `ENTITY_DEFAULTS['inventory'] = true`. Cubre
**NFR-06** y **REQ-INV-08**. Consumido por `T3.1`, `T3.7`.

**Desarrollo técnico**:

- **Archivo:** `includes/Write_Gate.php`.

- **1) `ENTITY_OPTIONS` (`:37-46`)** — agregar tras `'item'` (`:42`):
  ```php
  'inventory'   => 'alegra_connector_push_inventory_enabled',
  ```

- **2) `ENTITY_DEFAULTS` (`:52-54`)** — **ANTES:**
  ```php
  private const ENTITY_DEFAULTS = [
      'payment' => true,
  ];
  ```
  **DESPUÉS:**
  ```php
  private const ENTITY_DEFAULTS = [
      'payment'   => true,
      // 2.6.0: el ajuste WC→Alegra debe poder correr en una instalación existente
      // que aún no tiene la opción (default true en alegra-connector.php). Sin
      // esto block_reason() devolvería entity_disabled y lo bloquearía (R15).
      'inventory' => true,
  ];
  ```

- **3) `ENTITY_PATTERNS` (`:67-77`)** — agregar tras `'item'` (`:73`), **antes** de `/taxes`:
  ```php
  ['inventory',   '#^/inventory-adjustments(/|$)#'],
  ```

- **Orden de patrones:** `/inventory-adjustments` no colisiona con `/items`, así que el orden es
  indiferente; se ubica junto a `item` por legibilidad.

**Resultado esperado**: `entity_for('POST','/inventory-adjustments') === 'inventory'`; con la opción
ausente, `block_reason('inventory') === null` (default `true`); con la opción en `false`,
`block_reason('inventory') === 'entity_disabled'`.

**Dependencias**: ninguna. Prerequisito de **T3.1**, **T3.7**.

**Trazabilidad**: NFR-06, REQ-INV-08; R15; `design.md` §10; `tasks.md` §12.2 #25.

**Verificación**: test nuevo en la sección `T29`:

```php
TestRunner::test('T29.18 Write_Gate reconoce inventory y no lo bloquea por default', function (): void {
    alegra_test_reset();
    TestRunner::assertSame('inventory', \Alegra\Connector\Write_Gate::entity_for('POST', '/inventory-adjustments'), 'entity_for');
    TestRunner::assertSame('inventory', \Alegra\Connector\Write_Gate::entity_for('POST', '/inventory-adjustments/'), 'con slash final');

    unset($GLOBALS['wp_options']['alegra_connector_push_inventory_enabled']);
    TestRunner::assertSame(null, \Alegra\Connector\Write_Gate::block_reason('inventory'), 'opción ausente => ENTITY_DEFAULTS true => no bloquea');

    update_option('alegra_connector_push_inventory_enabled', false);
    TestRunner::assertSame('entity_disabled', \Alegra\Connector\Write_Gate::block_reason('inventory'), 'false => entity_disabled');
});
```

**Prove-it-catches:** quitar `'inventory' => true` de `ENTITY_DEFAULTS` ⇒ la aserción de `null` falla
(devuelve `entity_disabled`). **Este es el fix central de la tarea.**

**Riesgo**: que el kill switch del harness esté activo y `block_reason()` devuelva `kill_switch`.
**Guard**: `alegra_test_reset()` no activa el kill switch (opción ausente); si un test previo lo dejó,
el reset de `wp_options` lo limpia.

**Estimación**: S (1 h).

---

### T1.9 — Client: quitar `@deprecated` de `create_inventory_adjustment` · producto

**Objetivo**: dejar de marcar `create_inventory_adjustment()` como deprecada, porque pasa a ser el
camino productivo del dueño `adjustment` (D2 rama a). La firma no cambia.

**Descripción técnica**: `Client::create_inventory_adjustment()` (`Client.php:770-773`) tiene un
docblock `@deprecated 2.4.0 No production caller` (`:766-769`). A partir de `T3.1` **sí** tiene caller
(`Inventory_Pusher::push_delta()`). Dejarlo deprecado es mentir. Cubre **REQ-INV-01**.

**Desarrollo técnico**:

- **Archivo:** `includes/API/Client.php:766-769`.

- **ANTES:**
  ```php
  /**
   * @deprecated 2.4.0 No production caller; kept as public API of the
   *             distributed plugin for backward compatibility.
   */
  public function create_inventory_adjustment(array $data): array|\WP_Error
  ```

- **DESPUÉS:**
  ```php
  /**
   * POST /inventory-adjustments. Owner of the WC → Alegra stock movement when
   * `push_orders_enabled=false` (D2). Called by Inventory_Pusher::push_delta().
   */
  public function create_inventory_adjustment(array $data): array|\WP_Error
  ```

- **Sin cambio de firma** (`array $data`, retorno `array|\WP_Error`) ni de cuerpo (`:772`).

**Resultado esperado**: el docblock no contiene `@deprecated`; la firma sigue intacta; el smoke-load
sigue verde.

**Dependencias**: ninguna. Consumido por **T3.1**.

**Trazabilidad**: REQ-INV-01; `design.md` §10.

**Verificación**: `grep -n "@deprecated" includes/API/Client.php` **no** muestra la línea de
`create_inventory_adjustment`; `bash scripts/smoke-test.sh` → `SMOKE OK`. (No requiere test `T29`.)

**Prove-it-catches**: no aplica (cambio de docblock). Se verifica por grep + smoke.

**Riesgo**: que otro método comparta el `@deprecated`; **guard**: verificar por contexto (el bloque de
`create_inventory_adjustment`, `:766-773`).

**Estimación**: S (0.25 h).

---

### T1.10 — Helpers del ledger de stock (`Inventory_Pusher`) · producto

**Objetivo**: crear `includes/Sync/Inventory_Pusher.php` con la superficie **estática** del ledger
(`synced`/`pending`), prerequisito del pusher (D2) y de la interacción poll↔ledger (D6/T3.4).

**Descripción técnica**: el diseño (§3.4) usa dos metas por producto: `_alegra_stock_synced` (último
valor en que WC y Alegra acordaron) y `_alegra_stock_push_pending` (valor de WC que se intenta empujar).
Hoy **0 coincidencias**. `T3.1` "crea" `Inventory_Pusher` con la API de instancia; para no chocar,
`T1.10` crea el **archivo** con la superficie estática del ledger (corrección C7) y `T3.1` la completa.
El autoloader PSR-4 resuelve `Alegra\Connector\Sync\Inventory_Pusher` por el subdir `Sync`
(`alegra-connector.php:118`). Cubre **REQ-INV-01**; consumido por `T3.1`–`T3.4`.

**Desarrollo técnico**:

- **Archivo nuevo:** `includes/Sync/Inventory_Pusher.php`. Namespace `Alegra\Connector\Sync` (igual que
  `Products`/`Inventory_Writer`). Se carga por PSR-4.

- **Contenido completo de `T1.10`** (la superficie que `T3.1` **no** redefine):

  ```php
  <?php
  /**
   * Inventory pusher — ledger + WC → Alegra delta push (D2).
   *
   * T1.10 crea la superficie estática del ledger. T3.1 agrega el constructor,
   * los hooks y push_delta(); NO redefine estos helpers.
   *
   * @package Alegra\Connector\Sync
   */

  declare(strict_types=1);

  namespace Alegra\Connector\Sync;

  if (!defined('ABSPATH')) {
      exit;
  }

  final class Inventory_Pusher
  {
      public const META_SYNCED  = '_alegra_stock_synced';
      public const META_PENDING = '_alegra_stock_push_pending';

      /**
       * Last quantity WC and Alegra agreed on. '' when never baselined.
       */
      public static function synced(int $product_id): int|string
      {
          $value = get_post_meta($product_id, self::META_SYNCED, true);
          return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
      }

      public static function set_synced(int $product_id, int $qty): void
      {
          update_post_meta($product_id, self::META_SYNCED, $qty);
      }

      /**
       * WC quantity currently being pushed (set before the POST, cleared on OK).
       * '' when no push is in flight.
       */
      public static function pending(int $product_id): int|string
      {
          $value = get_post_meta($product_id, self::META_PENDING, true);
          return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
      }

      public static function set_pending(int $product_id, int $qty): void
      {
          update_post_meta($product_id, self::META_PENDING, $qty);
      }

      public static function clear_pending(int $product_id): void
      {
          delete_post_meta($product_id, self::META_PENDING);
      }
  }
  ```

- **Smoke (`scripts/smoke-load.php`).** El check `Inventory_Pusher class loads` lo agrega **`T9.3`**
  (dueño único, C11); `T1.10` **no** lo duplica. La clase igual resuelve por PSR-4 (el autoloader ya
  está registrado en `alegra-connector.php:118`).

- **Nota (corrección):** la fila de test strategy de Fase 1 en `tasks.md:315` dice "el writer/pusher no
  existen aún"; es **stale**: `T1.10` crea el pusher. El writer sigue siendo Fase 2.

**Resultado esperado**: `Inventory_Pusher::synced()/pending()` devuelven `''` si no hay meta e `int` si
la hay; `set_*`/`clear_pending` persisten/borran; la clase carga por PSR-4.

**Dependencias**: ninguna. Prerequisito de **T3.1**, **T3.2**, **T3.4**.

**Trazabilidad**: REQ-INV-01; D2 §3.4; `design.md` §10.

**Verificación**: test nuevo en la sección `T29`:

```php
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
```

Y `bash scripts/smoke-test.sh` → `SMOKE OK` (el check de PSR-4 de `Inventory_Pusher` lo agrega `T9.3`, dueño único).

**Prove-it-catches:** cambiar `get_post_meta($id, self::META_SYNCED, true)` por una meta distinta ⇒
`T29.110` rojo (round-trip no coincide).

**Riesgo**: que `get_post_meta(..., true)` devuelva `'0'` y el cast a int lo confunda con "ausente".
**Guard**: el guard compara contra `''`/`false`/`null`; `'0'` se castea a `0` (presente). Cubierto por
el round-trip.

**Estimación**: S (1 h).

---

### T1.11 — Sección de tests `T29` en `exec-test.php` · cierre de harness

**Objetivo**: crear el bloque `// === sync-reliability (2.6.0) ===` al final de
`scripts/exec-test.php`, donde viven los tests `T29.*`, y dejar constancia del reset del ledger.

**Descripción técnica**: `exec-test.php` termina con `exit(TestRunner::summary());` en **`:8017`**. Las
secciones previas se marcan con headers (`// === logs-monitor-import (2.5.0) ===` en `:6535`). Los tests
de Fase 1 (`T29.11`–`T29.18`, `T29.110`) se insertan **antes** del `exit`. `alegra_test_reset()` ya
limpia `$GLOBALS['wp_postmeta']` (`test-framework.php:148`), así que las metas del ledger
(`_alegra_stock_synced`/`_alegra_stock_push_pending`) se limpian solas (corrección C6). Cubre **NFR-04**.

**Desarrollo técnico**:

- **Archivo:** `scripts/exec-test.php`.

- **Punto de inserción:** inmediatamente **antes** de la última línea
  `exit(TestRunner::summary());` (hoy `:8017`; verificar con
  `grep -n "exit(TestRunner::summary())" scripts/exec-test.php`).

- **Header del bloque:**
  ```php
  // ===========================================================================
  // === sync-reliability (2.6.0) ===
  // Fase 1 — cimientos + harness (H1–H8), opciones nuevas y Write_Gate inventory.
  // IDs: T29.1{n} (T29.11…T29.18, T29.110). Fases 2..9 agregan T29.2x..T29.9x.
  // ===========================================================================
  ```

- **Contenido:** los tests `T29.11`–`T29.18` y `T29.110` definidos en `T1.1`–`T1.10` (esta tarea sólo
  crea el header y el lugar; los tests se agregan con cada tarea). **Orden:** el header primero, luego
  los tests en orden numérico.

- **Reset del ledger:** **no** hace falta tocar `alegra_test_reset()` (C6). Si se quisiera explicitar,
  la opción es agregar en `test-framework.php:157-166` (bloque de `$GLOBALS[...] = []`) un comentario;
  **no** se agrega código.

**Resultado esperado**: el archivo tiene el header `sync-reliability (2.6.0)` y los tests `T29.1x`
corren en `bash scripts/exec-test.sh`.

**Dependencias**: ninguna (crea el lugar). Es prerequisito de **T1.12** y de todas las fases 2–9.

**Trazabilidad**: NFR-04; `tasks.md` Fase 1.

**Verificación**: `grep -n "sync-reliability (2.6.0)" scripts/exec-test.php` devuelve el header;
`bash scripts/exec-test.sh` corre los `T29.1x` sin error de parseo.

**Prove-it-catches**: no aplica (estructura). Se verifica por grep + corrida.

**Riesgo**: insertar después del `exit` (no se ejecutarían). **Guard**: verificar que el header quede
**antes** de `exit(TestRunner::summary())`; el conteo de aserciones debe **subir** respecto de 1626.

**Estimación**: S (0.5 h).

---

### T1.12 — Baseline verde antes de tocar producto · cierre de fase

**Objetivo**: confirmar que, con todos los seams y las opciones de Fase 1 aplicados, el harness sigue
verde y el conteo subió respecto del baseline.

**Descripción técnica**: el baseline real de HEAD es **1626 assertions / 0 failed** (re-corrido al
escribir esta fase). Fase 1 agrega 9 tests (`T29.11`–`T29.18`, `T29.110`), cada uno con varias
aserciones, así que el conteo debe **superar** 1626 con `0 failed`. Si **baja** o aparece un `failed`,
alguno de los seams de harness rompió un supuesto viejo (riesgo D-H: el cambio de `save()`/`update()`
de mayor superficie). Cubre **NFR-01**.

**Desarrollo técnico**:

- **Comando:**
  ```bash
  bash scripts/exec-test.sh
  ```
- **Esperado:** `EXEC-TEST OK: <N> assertions passed, 0 failed` con `N >= 1626 + (aserciones nuevas)`.
- **Si falla:** aislar el seam (H1/H2/H3/H3b/H4/H5) que lo rompió y decidir explícitamente: corregir el
  test cuyo supuesto quedó viejo, o acotar el cambio de harness. Documentar la decisión en el commit.
- **Smoke:**
  ```bash
  bash scripts/smoke-test.sh   # SMOKE OK (el check de Inventory_Pusher lo agrega T9.3)
  ```

**Resultado esperado**: `EXEC-TEST OK` con 0 failed y `SMOKE OK`.

**Dependencias**: **T1.1**–**T1.11**.

**Trazabilidad**: NFR-01, NFR-05; `tasks.md` §12.2 #24.

**Verificación**: la salida literal de ambos comandos.

**Prove-it-catches**: no aplica (gate de cierre).

**Riesgo (alto)**: que H2 (derivación en `save()`) o H5 (aunque opt-in) altere el baseline. **Guard**:
correr el harness **después de cada seam** (T1.1…T1.5), no sólo al final; comparar el conteo en cada
paso.

**Estimación**: S (0.25 h).

---

## DoD de la Fase 1

- [ ] H1–H8 aplicados: `wc_update_product_stock` + derivación en `save()` (H1/H2), CONTAINS +
      paginación en el mock (H3/H3b), ruta `/inventory-adjustments` (H4/H6), 400 opt-in por client
      muerto (H5), `set_syncing` observable (H7), 9 opciones sembradas (H8).
- [ ] **`T1.3` / FIX-13**: `GET /items` del mock aplica `start`/`limit` **sin** `metadata=true` (lista
      plana recortada); con `metadata=true` sigue devolviendo `{metadata.total, data}`. Cierra el falso
      verde de `T29.71`/`T29.73` (prerequisito de Fase 7).
- [ ] **`T1.8`**: `Write_Gate::ENTITY_DEFAULTS['inventory'] = true` presente; `block_reason('inventory')`
      es `null` con la opción ausente (R15 cerrado).
- [ ] Las 9 opciones nuevas están en `$non_autoload` y `uninstall.php`; las 6 sembrables en `$defaults`.
- [ ] `includes/Sync/Inventory_Pusher.php` existe con los helpers del ledger y carga por PSR-4.
- [ ] `Client::create_inventory_adjustment()` sin `@deprecated`.
- [ ] Sección `// === sync-reliability (2.6.0) ===` en `scripts/exec-test.php` (antes del `exit`).
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK`, **≥ 1626 + nuevos**, 0 failed.
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK` (el check de `Inventory_Pusher` es de `T9.3`, dueño único).
- [ ] **Prove-it-catches** documentado para cada test (`T29.11`…`T29.18`, `T29.110`).
- [ ] `scripts/` no se distribuye (`.distignore:15`); `T1.8`/`T1.7`/`T1.9`/`T1.10` **sí** shipean.
- [ ] **Fase 1 no dependió de ningún gate** de Fase 0.
