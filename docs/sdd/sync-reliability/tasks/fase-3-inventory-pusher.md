# Fase 3 — `Inventory_Pusher` + dueño híbrido (D2) [titular]

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Fase | 3 de 10 — el **cierre de la re-inflación** (`WC → Alegra` con delta tracking) |
| Tareas | `T3.1` · `T3.2` · `T3.3` · `T3.4` · `T3.5` · `T3.6` · `T3.7` |
| Depende de | **Fase 2 completa** (`T2.1`–`T2.8`) + Fase 1 (`T1.4` mock `/inventory-adjustments`, `T1.7` opciones, `T1.8` entidad `inventory`, `T1.9` quitar `@deprecated`, `T1.10` helpers del ledger). **`T3.5` además `BLOQUEADO(Fase 0.1 / G1)`** |
| DoD de la fase | REQ-INV-01, REQ-INV-07, REQ-INV-08 verdes; **el test del titular** (vender 3 → poll → WC no sube) en verde (`T29.34`); `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes |
| Documentos base | `proposal.md` §4 Tema B (B1/B2/B8/B9) · `spec.md` REQ-INV-01/07/08 · `design.md` §3 (D2) + §1.4 + §10 + §15 · `tasks.md` §0.2 + Fase 3 + §10 decisiones 1/2/7 |
| Versión objetivo | 2.6.0 |
| Decisión de la fase | **D2 — híbrido con dueño de movimiento a nivel tienda** derivado de `push_orders_enabled` |

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T29.3{n}`
> (`T29.31`…`T29.39`, más los sufijados `T29.32b/c`, `T29.34b/c`, `T29.36b`, `T29.37b/c`). Los IDs de
> **tarea** (`T3.1`…`T3.7`) no cambian.

> **Regla de oro de esta fase (prove-it-catches, obligatoria).** El test del titular es `T29.34`:
> con `push_orders=false`, vender 3 (WC 10→7) → `POST /inventory-adjustments` aplica el delta en el
> mock → un poll posterior **deja WC en 7** (no re-infla). **Prove-it-catches:** revertir `T3.4`
> (volver a escribir WC incondicionalmente) ⇒ `T29.34` rojo. Sin ese revert→rojo, el test no se acepta.

> **§0.2 — Contradicción deliberada con `docs/sdd/inventory/DD-8`.** El design §3.3 elige **cablear**
> `create_inventory_adjustment()` (que `DD-8` prohíbe) como dueño del movimiento cuando
> `push_orders_enabled=false`. **Motivo:** `DD-8` asumía que la factura (b) cubriría las ventas, pero
> con los defaults (`push_orders=false`, `invoice_status=draft`, `alegra-connector.php:418,451`) (b)
> **no cubre nada** y el titular persiste. La intención de `DD-8` (evitar doble conteo) se **preserva
> y refuerza** vía el **dueño único a nivel tienda** (FIX-3: `owner=invoice` **sólo** si
> `push_orders_enabled && open_invoice_on_paid`, o sea cuando la factura realmente mueve stock) **más
> las guardas del pusher**: antes de emitir un ajuste se resuelve el pedido asociado y, si su factura
> está `open`, **no** se emite (`reason='invoice_owner'`); la facturación manual se advierte y exige
> confirmación. Un movimiento tiene un solo dueño; **ya no** se afirma "imposible por construcción".
> Se documenta en `CHANGELOG.md` y
> en la release 2.6.0 (`T9.5`).
> Evidencia de la contradicción: `docs/sdd/inventory/design.md:440` (`DD-8`), `:423`
> (`create_inventory_adjustment()` sin llamadores), `docs/sdd/inventory/tasks.md:244`.

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

Al leer el código real y la documentación de Alegra antes de escribir estas micro-tareas aparecen
**8 hallazgos**. El **C1 es el más grave**: el payload del design §3.4 no matchea el schema
documentado.

| # | Claim (design/spec/tasks) | Realidad verificada | Impacto |
|---|---|---|---|
| C1 | Payload de `POST /inventory-adjustments` (design §3.4): `['date', 'type'=>'in'\|'out', 'quantity'=>abs($delta), 'item'=>['id'=>$alegra_item], 'warehouse'=>…]` | **FALSO vs el schema documentado.** La doc oficial (`https://developer.alegra.com/reference/post_inventory-adjustments`, OpenAPI consultado) exige top-level `date` (string) **e** `items` (array); cada ítem exige **`id`** (string), **`type`** (`in`/`out`), **`unitCost`** (number) y **`quantity`** (number). `warehouse` es **opcional** (default = bodega principal). `docs/INVENTORY_DESIGN.md:719-722` ya lo dice: `items[].type`, `quantity` y `unitCost`. El design puso `type`/`quantity` a nivel raíz, usó `item` singular y **omitió `unitCost`** (obligatorio). | `T3.1`/`T3.2` construyen `{date, items:[{id,type,quantity,unitCost}], warehouse?}`. Sin `unitCost` Alegra responde **422/400**. |
| C2 | `on_stock_changed(int $product_id)` / `on_variation_stock_changed(int $variation_id)` (design §10) | **FALSO.** WC dispara `do_action('woocommerce_product_set_stock', $product)` y `do_action('woocommerce_variation_set_stock', $product)` con el **objeto `WC_Product`**, no un int (`woocommerce/includes/wc-stock-functions.php:66-70`, `class-wc-product-data-store-cpt.php:920-929`). Un param tipado `int` produciría **TypeError**. | Los handlers aceptan `$product` (objeto) y normalizan defensivamente un id numérico. |
| C3 | `register_hooks()` sin argumentos (design §10) | Con handlers de instancia que necesitan `API\Client`/`Logger`, un `static register_hooks()` no puede construirlos. `Public_` sí tiene ambos (`Public_.php:22-23,37`). | **Se corrige la firma** a `register_hooks(?API\Client $api, ?Logger\Logger $logger)`; registra los handlers de una instancia. |
| C4 | `open_invoice_on_paid` (design §12, tasks `T3.5`, §10 decisión 7) | La opción **no está** en la tabla de opciones nuevas del design §11 (que lista 8) ni en `T1.7` ("8 opciones"). | **9.ª opción** `alegra_connector_open_invoice_on_paid` (bool, default **true**, autoload no). `T1.7` pasa a sembrar **9**; `T3.5` la usa. |
| C5 | El dueño `invoice` se implementa "abriendo la factura al pagar" | Ya existe parcialmente: `create_invoice_with_payment()` pasa `'open'` **sólo** si hay cuenta de pago configurada y el pedido está pagado (`Orders.php:352-360`); `prepare_invoice_data()` usa `$status_override ?? get_option('alegra_connector_invoice_status','draft')` (`Orders.php:1094`). | `T3.5` agrega el override por `open_invoice_on_paid` **independiente** de la cuenta de pago. |
| C6 | `Controller::acquire_lock`/`release_lock` usables por el pusher | **Confirmado:** `acquire_lock` es `public static` (`Controller.php:414`); `release_lock` es `public static` (`:441`). `acquire_sync_lock_public` es `:523-526` (no `:511-525`, tasks.md corrección #28). | `T3.2` usa `Controller::acquire_lock('alegra_inventory_push_'.$id, 30)` + `release_lock`. |
| C7 | El poll ya corta la cascada por `set_syncing` | **Falso en Fase 3:** `set_syncing` alrededor del poll es `T7.3` (Fase 7). En HEAD el poll **no** lo llama; `on_update_product` (`Public_.php:226-234`) tampoco chequea `is_syncing`, sólo el transient `alegra_updating_product_{id}` (`:229`) — que el poll **no** setea. | `T3.4` debe setear `set_transient('alegra_updating_product_'.$id, 1, 30)` alrededor del `Inventory_Writer::apply()` del poll, o su propio `wc_update_product_stock` dispara el hook → cascada. |
| C8 | `resolve_warehouse_id()` reutilizable | Es `private` en `Products.php:1104-1110` (opciones `alegra_connector_warehouse_enabled` / `_warehouse_id`). | El pusher tiene su **propia** copia `resolve_warehouse_id()` (mismas opciones). |

**Confirmaciones (no requieren corrección).**

- `API/Client.php:770` `create_inventory_adjustment()` con `@deprecated` (`:766-769`); `:761` `get_inventory_adjustments()`; `:242` `new WP_Error('api_error', $msg, ['code'=>$code,'response'=>$error_data])`; `:300` `write_was_blocked()`; `:954` rate limit 150 — confirmados.
- `Public_.php:73` hook `woocommerce_update_product`; `:226-234` `on_update_product`; `:229` transient; `:371` `trigger_sync` chequea `alegra_import_in_progress`; `:426-437` `set_syncing`; `:442` `is_syncing` — confirmados.
- `alegra-connector.php:418` `push_orders_enabled=false`; `:449` `inventory_sync_enabled=true`; `:451` `invoice_status='draft'`; `:453` `dry_run=false` — confirmados.
- Grep de `_alegra_stock_synced` / `_alegra_stock_push_pending` / `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` / `wc_update_product_stock` en producción = **0 matches** — confirmado.
- `Products.php:1104-1110` `resolve_warehouse_id()`; `:1937-1941` `resolve_preserve_fields()` — confirmados.
- `Orders.php:63` `create_invoice(\WC_Order $order, ?string $status_override = null)`; `:92` `prepare_invoice_data($order,$status_override)`; `:119` POST; `:121-129` sólo-loguea; `:342` `create_invoice_with_payment`; `:360` `create_invoice($order, $will_record_payment ? 'open' : null)` — confirmados.
- `Admin_Dashboard.php:826-838` `render_dashboard()`; `:1811-1835` `ajax_sync_inventory()` — confirmados.
- `templates/admin-dashboard.php:130-147` fila CF; `:148-160` fila dry-run — confirmados.

---

## Correcciones canónicas (REVIEW momus/oracle) — FIX-1 … FIX-13

> Estas correcciones son **obligatorias** y deben coincidir 1:1 con `design.md` (otro agente las aplica
> allí). Cada una indica dónde aterriza en esta fase.

| Fix | Origen | Decisión canónica | Aterriza en |
|---|---|---|---|
| **FIX-1** | B1 / D5 | El baseline de `_alegra_stock_synced` sale **siempre de Alegra**, nunca de WC. En el primer hook sin baseline se marca `_alegra_stock_push_pending` (NO `synced`); el reconciliador hace `GET /items/{id}`, fija `synced` con `availableQuantity` y empuja el delta. | `T3.2` (baseline), `T3.4` (reconciliador), test `T29.34b` |
| **FIX-2** | B2 / D2 | El guard `is_syncing()` se mueve **al hook** (`on_stock_changed`). `push_delta(..., bool $from_poll = false)`; el poll llama con `$from_poll = true` y **bypassa** el guard (el transient por producto se respeta siempre). | `T3.1`/`T3.2` (firma), `T3.3` (hook), test cross-fase `T29.36b` |
| **FIX-3** | B3 / D1 | `owner = invoice` **sólo** si `push_orders_enabled && open_invoice_on_paid`; si no, `adjustment`. El flujo de pedido reusa una factura **`open`** existente antes de crear/abrir (evita doble conteo con factura manual) y **abre un borrador pre-existente** al pagarse (hoy `Orders.php:82-90,92` early-return). | `T3.1` (`owner()`), `T3.5`, tests `T29.37b`/`T29.37c` |
| **FIX-4** | Oracle#4 / D4 | La pre-búsqueda `GET /inventory-adjustments` es **obligatoria** antes de re-emitir desde `pending`: si ya existe el ajuste, se considera aplicado (`synced = new_qty`, clear pending, `already_applied`). | `T3.2`, test `T29.32b` |
| **FIX-8** | Oracle#3 / D3 | El poll **no se muere de hambre**: sólo hace `continue` cuando el push realmente maneja el ítem (`ok`/`already_applied`/`in_sync`/`api_error`/`blocked`/`locked`/`baseline_unverified`). Con `disabled`/`invoice_owner`/`not_linked`/`not_manageable` **cae al `Inventory_Writer::apply()`** (el poll es el dueño de la escritura WC). | `T3.4`, tests `T29.34c`/`T29.36` |
| **FIX-11** | Oracle D11 | El stub `WC_Product::save()` (`wp-stubs.php:1284`) **no dispara** hooks de stock ⇒ los tests deben **manejar el hook explícitamente**. No se toca el stub en esta fase (lo posee el dueño del harness): los tests usan `wc_update_product_stock()` (H1) o `on_stock_changed()` directo, y `register_hooks($api,$logger)`. | `T3.3`/`T3.4` (verificación), `T3.7` |
| **FIX-12** | Oracle D12 | `unitCost` nunca `0.0` (Alegra puede 422): fallback determinista `> 0` desde el costo, o el **precio** del producto, o `1.0`. G3 confirma si `0` es aceptado. | `T3.1` (`unit_cost`), `T3.2` |
| **FIX-13** | Oracle#7 / D7 | **Prerrequisito de harness**: `alegra-mock.php:659-671` sólo aplica `start`/`limit` con `metadata=true`; el poll llama sin `metadata` ⇒ los tests de cursor de Fase 7 son inválidos hasta que el dueño del harness lo corrija (aplicar `start`/`limit` siempre). | `T3.4` (nota de dependencia), Fase 7 |

---

## Contratos canónicos (los define esta fase; Fases 7/9 los consumen)

### K-P — Dueño del movimiento (la decisión titular) · **FIX-3**

```php
// FIX-3 (B3): `invoice` SÓLO si la factura realmente mueve stock. Con
// `push_orders_enabled=true` + `open_invoice_on_paid=false` la factura nace
// `draft` y NO mueve stock ⇒ nadie movería (el bug simétrico de B3). En ese
// caso el dueño es `adjustment` y el pusher emite el ajuste.
owner = (get_option('alegra_connector_push_orders_enabled', false)
         && get_option('alegra_connector_open_invoice_on_paid', true))
    ? 'invoice'
    : 'adjustment';
```

| `push_orders_enabled` | `open_invoice_on_paid` | Owner | Quién mueve el stock de Alegra | Qué emite el plugin |
|---|---|---|---|---|
| `false` (**default**) | — (inerte) | `adjustment` | El **pusher** (`POST /inventory-adjustments`) | Ajuste con delta en cada cambio de stock de WC |
| `true` | `true` (**default**) | `invoice` | La **factura** (nativa, al quedar `open`) | **Ningún** ajuste; abre la factura al pagar |
| `true` | `false` | `adjustment` | El **pusher** (`POST /inventory-adjustments`) | Ajuste con delta (la factura queda `draft` y no mueve stock) |

**Doble conteo acotado por construcción** (REQ-INV-08): un solo dueño a la vez, decidido por tienda.
Con `owner=invoice`, el flujo de pedido busca una factura **`open`** existente del pedido y la reusa
antes de crear/abrir (FIX-3), y `push_delta` devuelve `invoice_owner` (cero ajustes). Con
`owner=adjustment` la factura (si se crea) queda `draft` y no se abre por la venta, así que no puede
mover stock en paralelo; la apertura **manual** de una factura en modo `adjustment` se documenta como
limitación honesta (el hook de stock es a nivel producto y no tiene contexto de pedido) y se reporta
en `T3.6`/`CHANGELOG.md`.

### K-L — El ledger (metas por producto)

| Meta | Tipo | Significado | Escritura |
|---|---|---|---|
| `_alegra_stock_synced` | int (string en meta) | Último valor en el que **WC y Alegra acordaron** | **Sólo** (a) un POST exitoso, o (b) el poll/import cuando escribe el valor de Alegra en WC. **Nunca** un valor de WC sin push (FIX-1) |
| `_alegra_stock_push_pending` | int (string) | Valor de WC que se está intentando empujar (o el delta de una venta previa al baseline) | `set_pending()` antes del POST y en `baseline_pending`; `clear_pending()` al OK |

**Idempotencia (FIX-1 + FIX-4):**
1. El **baseline** sale de Alegra: en el primer hook sin `synced` se marca `pending` y el
   reconciliador (`from_poll`) hace `GET /items/{id}` para fijar `synced = availableQuantity` **antes**
   de calcular el delta. Fijar `synced = WC` sin empujar re-infla la venta (B1) — prohibido.
2. `synced` se actualiza **sólo** con un POST exitoso (o con la escritura del poll desde Alegra).
3. Antes de re-emitir desde `pending`, la pre-búsqueda `GET /inventory-adjustments?item_id=…` es
   **obligatoria** (FIX-4): si el ajuste ya existe, se considera aplicado (`already_applied`).

> **C5 cerrado (fase-1:155-159) — helpers obligatorios del ledger.** Todas las lecturas/escrituras de
> `_alegra_stock_synced`/`_alegra_stock_push_pending` de esta fase pasan por los helpers canónicos de
> `Inventory_Pusher` (`synced()`, `set_synced()`, `pending()`, `set_pending()`, `clear_pending()`;
> definidos en `T1.10`, fase-1:1008-1046). Los helpers usan internamente `META_SYNCED`/
> `META_PENDING`, que valen exactamente `'_alegra_stock_synced'`/`'_alegra_stock_push_pending'`, así
> que el reemplazo es 1:1. **No queda ningún `get/update/delete_post_meta` crudo sobre esas dos metas
> en `T3.2`/`T3.4`**; los helpers dejan de ser código muerto.

### K-A — Payload de `POST /inventory-adjustments` (corregido por C1)

```php
[
    'date'  => current_time('Y-m-d'),          // requerido
    'items' => [[                              // requerido (array)
        'id'       => (string) $alegra_item,   // requerido (string)
        'type'     => $delta < 0 ? 'out' : 'in', // requerido
        'quantity' => abs($delta),             // requerido (number)
        'unitCost' => $this->unit_cost($product), // requerido (number; > 0 — FIX-12)
    ]],
    'warehouse' => ['id' => (string) $wh],     // opcional (default = principal)
]
```

> **FIX-12:** `unitCost` **nunca** `0.0`. Alegra puede rechazar `0` (422/400, G3). Fallback
> determinista `> 0`: costo (`_wc_cog_cost`/`_cost`) → precio del producto → `1.0`.

### K-F — Modo de fallo (un push que falla **no** re-infla)

1. `set_pending($new_qty)` **antes** del POST (y en `baseline_pending`, sin tocar `synced`).
2. POST falla ⇒ `pending` **queda**; `synced` **no** cambia.
3. El próximo poll ve `pending !== ''` ⇒ **reintenta el push** (con `$from_poll=true`, FIX-2) y
   **NO escribe WC** (K-F/T3.4).
4. El poll **nunca** pisa WC con el valor de Alegra mientras haya un movimiento local sin empujar.
5. Si el poll **no puede** empujar (`disabled`/`invoice_owner`/`not_linked`/`not_manageable`), cae al
   `Inventory_Writer::apply()` (FIX-8): el poll es el dueño de la escritura WC y no se congela.

---

### T3.1 — Crear `Inventory_Pusher` (clase + hooks + payload)

**Objetivo**: que exista `Alegra\Connector\Sync\Inventory_Pusher` con `owner()`, `register_hooks()`,
los handlers de stock, `push_delta($product, $qty, $from_poll = false)` y `resolve_warehouse_id()`.

**Descripción técnica**: HEAD nunca empuja stock WC→Alegra (`Products.php:1004-1015` manda
`inventory` sólo en creación; `sync_simple_product()` `:217-221` hace `update_item`), así que el poll
escribe el valor viejo de Alegra y re-infla (B1/B2, el titular). `create_inventory_adjustment()`
(`Client.php:770`) existe pero está `@deprecated` y sin cablear (B8). Esta tarea crea el dueño
`adjustment` (D2 §3.3/§3.4) con el payload documentado (C1). Implementa REQ-INV-01 (rama a) y
REQ-INV-08.

**Desarrollo técnico**:

Archivo **nuevo**: `includes/Sync/Inventory_Pusher.php`. Namespace `Alegra\Connector\Sync`. Se carga
por PSR-4 (lo asserta `T9.3`).

```php
<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Empuja el delta de stock de WooCommerce a Alegra vía
 * `POST /inventory-adjustments` cuando el plugin es dueño del movimiento
 * (D2, `push_orders_enabled=false`). Con `push_orders=true` la factura es la
 * dueña y este clase sólo registra hooks que no-op (REQ-INV-08).
 *
 * @package Alegra\Connector\Sync
 */
final class Inventory_Pusher
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Dueño del movimiento de stock de Alegra (K-P / FIX-3).
     * `invoice` SÓLO si `push_orders_enabled && open_invoice_on_paid` (la
     * factura realmente mueve stock). Si no, el dueño es `adjustment`.
     *
     * @return 'invoice'|'adjustment'
     */
    public static function owner(): string
    {
        return (get_option('alegra_connector_push_orders_enabled', false)
            && get_option('alegra_connector_open_invoice_on_paid', true))
            ? 'invoice'
            : 'adjustment';
    }

    /**
     * Registra los hooks de stock de WC. Un handler de instancia por request.
     * Los hooks pasan un `WC_Product` (no un int) — ver C2.
     */
    public static function register_hooks(?API\Client $api, ?Logger\Logger $logger): void
    {
        $pusher = new self($api, $logger);
        add_action('woocommerce_product_set_stock', [$pusher, 'on_stock_changed'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$pusher, 'on_variation_stock_changed'], 10, 1);
    }

    /**
     * Handler de `woocommerce_product_set_stock`. Acepta el objeto o un id.
     *
     * FIX-2 (B2): el guard de re-entrada `is_syncing()` vive ACÁ (en el hook),
     * no en `push_delta()`. Así el poll (que envuelve su loop con
     * `set_syncing(true)`, T7.3) puede reconciliar llamando a
     * `push_delta(..., $from_poll = true)` sin auto-bloquearse.
     *
     * @param \WC_Product|int|mixed $product
     */
    public function on_stock_changed($product): void
    {
        // FIX-2: cortar la cascada del import/poll en el hook.
        if (\Alegra\Connector\Public\Public_::is_syncing()) {
            return;
        }
        $product = $this->normalize_product($product);
        if ($product === null) {
            return;
        }
        $this->push_delta($product, (int) $product->get_stock_quantity());
    }

    /**
     * Handler de `woocommerce_variation_set_stock`.
     *
     * @param \WC_Product|int|mixed $variation
     */
    public function on_variation_stock_changed($variation): void
    {
        $this->on_stock_changed($variation);
    }

    /** @param mixed $product */
    private function normalize_product($product): ?\WC_Product
    {
        if ($product instanceof \WC_Product) {
            return $product;
        }
        if (is_numeric($product)) {
            $p = wc_get_product((int) $product);
            return $p instanceof \WC_Product ? $p : null;
        }
        return null;
    }

    /**
     * Empuja el delta WC→Alegra con ledger + lock + idempotencia (D2 §3.4).
     *
     * @param bool $from_poll El poll pasa `true` para bypassar `is_syncing()`
     *                        (FIX-2) y establecer el baseline desde Alegra (FIX-1).
     * @return array{pushed:bool, delta:int, reason:string}
     */
    public function push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array
    {
        // ... implementado en T3.2 ...
        return ['pushed' => false, 'delta' => 0, 'reason' => 'not_implemented'];
    }

    /**
     * Payload documentado de `POST /inventory-adjustments` (K-A / C1).
     */
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product): array
    {
        $payload = [
            'date'  => current_time('Y-m-d'),
            'items' => [[
                'id'       => $alegra_item,
                'type'     => $delta < 0 ? 'out' : 'in',
                'quantity' => abs($delta),
                'unitCost' => $this->unit_cost($product),
            ]],
        ];

        $wh = $this->resolve_warehouse_id();
        if ($wh !== '') {
            $payload['warehouse'] = ['id' => $wh];
        }

        return $payload;
    }

    /**
     * Costo unitario para `unitCost` (requerido). Misma fuente que
     * `Products::product_unit_cost()` (`_wc_cog_cost`/`_cost`).
     *
     * FIX-12 (D12): Alegra puede rechazar `unitCost = 0` (422). Nunca devolver
     * 0: costo → precio del producto → 1.0.
     */
    private function unit_cost(\WC_Product $product): float
    {
        foreach (['_wc_cog_cost', '_cost'] as $key) {
            $raw = get_post_meta($product->get_id(), $key, true);
            if ($raw !== '' && $raw !== null && is_numeric($raw) && (float) $raw > 0) {
                return (float) $raw;
            }
        }
        $price = (float) $product->get_price();
        return $price > 0 ? $price : 1.0;
    }

    /**
     * Bodega configurada o '' (misma semántica que
     * `Products::resolve_warehouse_id()`, `Products.php:1104-1110`).
     */
    private function resolve_warehouse_id(): string
    {
        if (!get_option('alegra_connector_warehouse_enabled')) {
            return '';
        }
        return (string) get_option('alegra_connector_warehouse_id', '');
    }
}
```

**Orden de operaciones**: `normalize_product` → `push_delta` (guards → ledger → lock → pending →
POST → synced/pending). `build_adjustment_payload` es puro (no toca la API).

**Resultado esperado (aceptación verificable)**:
- `Inventory_Pusher::owner()`: `'adjustment'` con `push_orders=false`; `'invoice'` con
  `push_orders=true` **y** `open_invoice_on_paid=true`; `'adjustment'` con `push_orders=true` +
  `open_invoice_on_paid=false` (FIX-3).
- `register_hooks($api,$logger)` registra los dos hooks con `accepted_args=1`.
- `build_adjustment_payload('4', -3, $p)` produce
  `['date'=>…, 'items'=>[['id'=>'4','type'=>'out','quantity'=>3,'unitCost'=>N]], 'warehouse'=>?]`
  con `N > 0` (FIX-12; sin costo ni precio ⇒ `1.0`).
- `smoke-load.php` carga la clase por PSR-4.

**Dependencias**: Fase 1 (`T1.4` mock, `T1.9` `@deprecated` fuera, `T1.10` ledger helpers). Fase 2.

**Trazabilidad**: REQ-INV-01 (rama a), REQ-INV-08; D2 §3.3/§3.4; `docs/sdd/inventory/REQ-DIV-1/3`.

**Verificación**:
1. Test `T29.31 owner y payload`: `update_option('alegra_connector_push_orders_enabled', false);`
   `assertSame('adjustment', Inventory_Pusher::owner())`; con `push_orders=true` +
   `open_invoice_on_paid=true` ⇒ `'invoice'`; con `push_orders=true` + `open_invoice_on_paid=false`
   ⇒ `'adjustment'` (FIX-3). El payload de un delta −3 tiene `type='out'`, `quantity=3`, `unitCost`
   numérico **`> 0`** (FIX-12) y `id` string.
   **Prove-it-catches**: usar el payload viejo (`item` singular sin `unitCost`) ⇒ el mock H4 devuelve
   validación fallida y el test falla; usar `owner()` sólo con `push_orders` ⇒ el caso
   `push_orders=true`/`open_invoice_on_paid=false` falla.
2. `bash scripts/smoke-test.sh` verde (PSR-4).
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el schema documentado difiera en la cuenta (DR14/G3). **Guarda**: `T0.3` (G3) confirma
`{date,items[],warehouse?}`; si no coincide, `push_inventory_enabled` default `false` + reporte, y el
payload se ajusta (nunca se escribe a ciegas).

**Estimación**: L (4 h).

---

### T3.2 — Ledger + idempotencia (delta tracking)

**Objetivo**: que `push_delta()` calcule el delta contra el ledger, setee `pending` antes del POST,
actualice `synced` **sólo** con OK y use un lock por producto.

**Descripción técnica**: sin ledger, un reintento doble-descuenta. El ledger (K-L) y el lock por
producto `alegra_inventory_push_{id}` (TTL 30) cierran el doble conteo y el solapamiento. Cubre
REQ-INV-01, REQ-INV-08 y NFR-07. Implementa el design §3.4 con C1/C2/C7, **FIX-1** (baseline desde
Alegra), **FIX-2** (`$from_poll`), **FIX-4** (pre-búsqueda obligatoria) y **FIX-12** (`unitCost > 0`).

**Desarrollo técnico**:

Archivo: `includes/Sync/Inventory_Pusher.php`. Reemplazar el cuerpo de `push_delta()` y agregar los
dos helpers (`fetch_alegra_available_quantity`, `adjustment_already_exists`):

```php
    public function push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array
    {
        $id = (int) $product->get_id();

        // Guard 1 (C7 + FIX-2): re-entrada. El transient por producto se
        // respeta SIEMPRE. `is_syncing()` sólo frena al HOOK: el poll
        // (from_poll=true) lo bypassa porque él mismo envuelve su loop con
        // set_syncing(true) (T7.3) y necesita reconciliar.
        if (get_transient('alegra_updating_product_' . $id)
            || (!$from_poll && \Alegra\Connector\Public\Public_::is_syncing())) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'syncing'];
        }

        // Guard 2 (K-P): con la factura como dueña no se emite ajuste.
        if (self::owner() !== 'adjustment') {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'invoice_owner'];
        }

        // Guard 3: kill switch / dry-run los evalúa el Write_Gate al POSTear;
        // acá el toggle de negocio del push.
        if (!get_option('alegra_connector_push_inventory_enabled', true)) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'disabled'];
        }

        // Guard 4: producto sin vínculo a Alegra.
        $alegra_item = (string) get_post_meta($id, '_alegra_item_id', true);
        if ($alegra_item === '') {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'not_linked'];
        }

        // Guard 5 (nuevo): WC no gestiona stock ⇒ no hay delta confiable.
        if (!$product->get_manage_stock()) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'not_manageable'];
        }

        // FIX-1 (B1): el baseline sale SIEMPRE de Alegra, nunca de WC.
        $synced = self::synced($id);
        if ($synced === '') {
            if (!$from_poll) {
                // Hook sin baseline: NO fijar `synced = $new_qty` (post-venta)
                // sin empujar — eso re-infla la venta en el próximo poll.
                // Se marca el movimiento como pendiente y el reconciliador
                // (poll, from_poll=true) fija el baseline real y empuja.
                self::set_pending($id, $new_qty);
                return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_pending'];
            }
            // Reconciliador: baseline desde ALEGRA (GET /items/{id}).
            $baseline = $this->fetch_alegra_available_quantity($alegra_item);
            if ($baseline === null) {
                // Sin certeza del valor de Alegra no se puede calcular delta.
                return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_unverified'];
            }
            self::set_synced($id, $baseline);
            $synced = (string) $baseline;
        }

        $delta = $new_qty - (int) $synced;
        if ($delta === 0) {
            // WC y Alegra ya coinciden: nada que empujar; limpiar pending.
            self::clear_pending($id);
            return ['pushed' => false, 'delta' => 0, 'reason' => 'in_sync'];
        }

        // Lock por producto: dos corridas no aplican el mismo delta (NFR-07).
        $lock_key = 'alegra_inventory_push_' . $id;
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            return ['pushed' => false, 'delta' => $delta, 'reason' => 'locked'];
        }

        $pending_prev = (string) self::pending($id);

        try {
            // K-F: pending ANTES del POST. Si el POST entra y la respuesta se
            // pierde, el poll ve `pending` y reintenta sin pisar WC.
            self::set_pending($id, $new_qty);

            // FIX-4 (D4): pre-búsqueda de idempotencia OBLIGATORIA antes de
            // re-emitir. Si un intento anterior entró y su respuesta se perdió,
            // el ajuste ya está en Alegra ⇒ considerarlo aplicado (no duplicar).
            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }

            $res = $this->api->create_inventory_adjustment(
                $this->build_adjustment_payload($alegra_item, $delta, $product)
            );

            // Dry-run / gate: no llegó nada a Alegra ⇒ no tocar `synced`.
            if (API\Client::write_was_blocked($res)) {
                return ['pushed' => false, 'delta' => $delta, 'reason' => (string) ($res['reason'] ?? 'blocked')];
            }
            if (is_wp_error($res)) {
                if ($this->logger) {
                    $this->logger->error('Inventory adjustment failed', [
                        'product_id'  => $id,
                        'alegra_item' => $alegra_item,
                        'delta'       => $delta,
                        'error'       => $res->get_error_message(),
                    ]);
                }
                // `pending` queda ⇒ el poll reintenta (K-F). Nunca se toca `synced`.
                return ['pushed' => false, 'delta' => $delta, 'reason' => 'api_error'];
            }

            // Éxito: recién acá WC y Alegra acuerdan.
            self::set_synced($id, $new_qty);
            self::clear_pending($id);
            return ['pushed' => true, 'delta' => $delta, 'reason' => 'ok'];
        } finally {
            Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * FIX-1: `availableQuantity` de Alegra para `GET /items/{id}`.
     * Devuelve null si no hay dato usable (nunca 0 por ausencia).
     */
    private function fetch_alegra_available_quantity(string $alegra_item): ?int
    {
        $res = $this->api->get_item($alegra_item);
        if (is_wp_error($res) || !is_array($res)) {
            return null;
        }
        $qty = $res['inventory']['availableQuantity'] ?? null;
        if ($qty === null || $qty === '' || !is_numeric($qty)) {
            return null;
        }
        return (int) $qty;
    }

    /**
     * FIX-4: ¿ya existe en Alegra un ajuste con el mismo ítem, tipo y cantidad?
     * Fail-open: ante error de red devuelve false (se reintenta el POST).
     */
    private function adjustment_already_exists(string $alegra_item, int $delta): bool
    {
        $res = $this->api->get_inventory_adjustments([
            'item_id'         => $alegra_item,
            'limit'           => 30,
            'order_field'     => 'date',
            'order_direction' => 'DESC',
        ]);
        if (is_wp_error($res) || !is_array($res)) {
            return false;
        }
        $want_type = $delta < 0 ? 'out' : 'in';
        $want_qty  = abs($delta);
        foreach ($res as $adjustment) {
            if (!is_array($adjustment)) {
                continue;
            }
            foreach (($adjustment['items'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                if ((string) ($line['id'] ?? '') === $alegra_item
                    && (string) ($line['type'] ?? '') === $want_type
                    && (int) ($line['quantity'] ?? 0) === $want_qty) {
                    return true;
                }
            }
        }
        return false;
    }
```

**Orden de operaciones (exacto):** `syncing` → `invoice_owner` → `disabled` → `not_linked` →
`not_manageable` → baseline (`baseline_pending` en el hook / `GET /items/{id}` en el poll) → `in_sync` →
`locked` → set `pending` → **pre-búsqueda FIX-4** → POST → `write_was_blocked` → `is_wp_error` →
set `synced` + clear `pending` → `release_lock` (finally).

**Tabla de razones de retorno:** `syncing` · `invoice_owner` · `disabled` · `not_linked` ·
`not_manageable` · `baseline_pending` · `baseline_unverified` · `in_sync` · `locked` ·
`already_applied` · `blocked` · `api_error` · `ok`.

**Resultado esperado (aceptación verificable)**:
- `synced` vacío + hook (`from_poll=false`) ⇒ `baseline_pending`, `pending = new_qty`, `synced` **vacío**
  (FIX-1; NO se emite POST).
- `synced` vacío + poll (`from_poll=true`) ⇒ `GET /items/{id}`, `synced = availableQuantity` de Alegra,
  y luego push del delta (si `WC != synced`).
- `delta === 0` ⇒ `in_sync` (no se emite POST).
- Vender 3 (synced 10, WC 7) ⇒ `POST /inventory-adjustments` con `type='out'`, `quantity=3`; el mock
  H4 baja `availableQuantity` a 7; `synced=7`; `pending` borrado.
- `pending` previo + ajuste ya existente ⇒ `already_applied`, `synced=new_qty`, `pending` borrado,
  **cero POST** (FIX-4).
- POST con `WP_Error` ⇒ `api_error`, `pending` **queda**, `synced` **no** cambia.
- Con `owner='invoice'` ⇒ `invoice_owner` (cero POST).
- Con `dry_run`/kill switch ⇒ `blocked` y `synced` intacto.
- `unitCost` del payload **`> 0`** (FIX-12).

**Dependencias**: `T3.1`; `T1.10` (helpers del ledger); `T1.4` (mock `/inventory-adjustments`, que
debe soportar `GET` para FIX-4); `T1.6` (H1 `wc_update_product_stock`); `T1.9`.

**Trazabilidad**: REQ-INV-01, REQ-INV-08, NFR-07; D2 §3.4; DR3; FIX-1/FIX-2/FIX-4/FIX-12.

**Verificación**:
1. Test `T29.32 ledger`: (a) baseline hook ⇒ `baseline_pending` con `synced` **vacío**; (b) `in_sync`;
   (c) delta out aplicado en el mock (`alegra_mock_state['items'][id]['inventory']['availableQuantity']`
   baja 3); (d) `api_error` deja `pending` y `synced` viejo; (e) `owner=invoice` ⇒ `invoice_owner`.
   **Prove-it-catches**: mover `Inventory_Pusher::set_synced(...)` **antes** del POST ⇒ el
   caso (d) falla (synced avanza con POST fallido).
2. Test `T29.32b baseline desde Alegra (FIX-1)`: `synced=''`, `pending=7`, mock `availableQuantity=10`;
   `push_delta($p, 7, true)` ⇒ `synced=10`, un POST `out 3`, `availableQuantity=7`.
   **Prove-it-catches**: volver a `synced = $new_qty` en el baseline ⇒ no sale el POST y el test falla.
3. Test `T29.32c pre-búsqueda obligatoria (FIX-4)`: `synced=10`, `pending=7`; sembrar en el mock un
   ajuste `out 3` del ítem; `push_delta($p, 7)` ⇒ `already_applied`, **cero POST**, `synced=7`,
   `pending` limpio.
   **Prove-it-catches**: quitar la pre-búsqueda ⇒ se emite un segundo POST (doble descuento).
4. Test `T29.33 idempotencia con lock`: tomar el lock crudo `alegra_lock_alegra_inventory_push_{id}` y
   llamar `push_delta` ⇒ `reason='locked'` y sin POST.
5. `bash scripts/exec-test.sh` verde.

**Riesgo**: doble emisión si el POST entra y se pierde la respuesta (DR3). **Guarda**: `pending` +
lock + **pre-búsqueda obligatoria** `GET /inventory-adjustments` (FIX-4), ya no opcional.

**Estimación**: M (2 h).

---

### T3.3 — Registrar los hooks de stock

**Objetivo**: que un cambio de stock en WC dispare `Inventory_Pusher::on_stock_changed` (producto) /
`on_variation_stock_changed` (variación), con el guard `is_syncing()` **en el hook** (FIX-2).

**Descripción técnica**: HEAD no registra ningún hook de stock (`grep` = 0). Los hooks
`woocommerce_product_set_stock` / `woocommerce_variation_set_stock` los dispara WC desde
`wc_update_product_stock()`/`save()` (C2), que es exactamente el camino que adopta `T2.1`. Registrarlos
en el constructor de `Public_` (que ya tiene `$api`/`$logger`) cierra el loop WC→Alegra. Cubre
REQ-INV-01. **FIX-2:** el corte de re-entrada por `is_syncing()` se evalúa en `on_stock_changed()`
(no en `push_delta()`), para que el poll pueda reconciliar con `$from_poll=true`.

**Desarrollo técnico**:

Archivo: `public/Public/Public_.php`. El namespace ya importa `use Alegra\Connector\Sync;` (`:14`).

Insertar **después** del bloque de hooks de productos (`:71-75`) y **antes** del de clientes (`:77`):

```php
        // D2 (REQ-INV-01): empuje de stock WC→Alegra. Sólo si el push de
        // inventario está activo. El dueño (adjustment/invoice) lo decide
        // push_delta() en runtime, así que el hook se registra igual.
        if (get_option('alegra_connector_push_inventory_enabled', true)) {
            Sync\Inventory_Pusher::register_hooks($this->api, $this->logger);
        }
```

**Orden de operaciones**: `plugins_loaded` → `new Public_($api,$logger)` → registro de hooks. La
opción se lee una vez por request (patrón documentado en `Public_.php:31-35`); un toggle aplica desde
el próximo request.

**Resultado esperado (aceptación verificable)**:
- `do_action('woocommerce_product_set_stock', $product)` invoca `on_stock_changed` con el **objeto**.
- `do_action('woocommerce_variation_set_stock', $variation)` invoca `on_variation_stock_changed`.
- Con `Public_::set_syncing(true)` (transient `alegra_import_in_progress`), `on_stock_changed` **retorna
  sin llamar** a `push_delta` (FIX-2) ⇒ **no** emite POST.
- Con `push_inventory_enabled=false`, no hay hooks registrados.

**Dependencias**: `T3.1`, `T3.2`; Fase 1 (`T1.6` H1 `wc_update_product_stock` + H7 para `set_syncing`).

**Trazabilidad**: REQ-INV-01; D2 §1.4, D5 §6.3; FIX-2.

**Verificación** (FIX-11 — el stub `save()` **no** dispara hooks, `wp-stubs.php:1284`):
1. Test `T29.35 hooks de stock`:
   - Registrar los hooks explícitamente con `Inventory_Pusher::register_hooks($api, $logger)`
     (en el harness `Public_` **no** se instancia, así que el registro del constructor no ocurre).
   - Simular la venta **disparando el hook explícitamente**:
     `do_action('woocommerce_product_set_stock', $product)` (NO `$product->save()`: el stub no dispara
     nada). Alternativa equivalente: `wc_update_product_stock($product, 7, 'set', false)` (H1).
   - Producto con `_alegra_item_id`, `_alegra_stock_synced=10`, `manage_stock=true`, stock 7.
   - Con `Public_::set_syncing(true)` ⇒ **0 POST** (el hook retorna antes de `push_delta`, FIX-2).
   - Con `set_syncing(false)` ⇒ **1 POST** `type='out'`.
   **Prove-it-catches**: no registrar los hooks ⇒ el segundo assert falla (0 POST); mover el guard
   `is_syncing` de vuelta a `push_delta` ⇒ el test cross-fase `T29.36b` (Fase 7) falla.
2. `bash scripts/smoke-test.sh` verde.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el hook dispare durante el propio poll (cascada). **Guarda**: guard `is_syncing` en
`on_stock_changed` (FIX-2) + Guard 1 de `push_delta` (`alegra_updating_product_`) + `T3.4` setea el
transient en el poll (C7).

**Estimación**: M (1.5 h).

---

### T3.4 — Interacción poll↔ledger (el poll no re-infla)

**Objetivo**: que el poll, antes de escribir WC, detecte un movimiento local sin empujar (o un push
fallido) y **reintente el push en vez de pisar WC**.

**Descripción técnica**: es el corazón del titular. HEAD escribe incondicionalmente
(`Products.php:1238-1260`). Con el ledger (K-L/K-F), el poll debe: (a) si `pending !== ''` ⇒ reconciliar
con `push_delta(..., true)` y `continue`; (b) si `synced !== '' && WC != synced` ⇒ hay un delta local
sin empujar ⇒ reconciliar y `continue`; (c) si no, `Inventory_Writer::apply()` y setear `synced`.
Además, `T3.4` debe **suprimir la cascada** del propio `wc_update_product_stock` del poll (C7) seteando
`alegra_updating_product_{id}` alrededor del `apply()`. Cubre REQ-INV-01, REQ-INV-08, NFR-07.
Implementa el design §3.5 con **FIX-1** (baseline reconciliado), **FIX-2** (`from_poll=true`) y
**FIX-8** (el poll no se muere de hambre).

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`, dentro del `foreach ($items as $item)` del poll, **antes** del
bloque de `T2.4` (que ya reemplazó `:1238-1277`). Insertar el chequeo del ledger y envolver el
`apply()`:

```php
                    // D2/D6/FIX-8 (REQ-INV-01, NFR-07): el poll NUNCA re-infla
                    // y TAMPOCO se muere de hambre.
                    $synced  = Inventory_Pusher::synced($product_id);
                    $pending = Inventory_Pusher::pending($product_id);

                    $needs_reconcile = $product->get_manage_stock()
                        && ($pending !== ''
                            || ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced));

                    if ($needs_reconcile) {
                        // (a) push fallido en vuelo, (b) delta local sin empujar,
                        // (c) baseline pendiente (FIX-1): reconciliar WC→Alegra.
                        // `from_poll=true` bypassa `is_syncing()` (FIX-2): el poll
                        // es el que corre con set_syncing(true) (T7.3).
                        $push = (new Inventory_Pusher($this->api, $this->logger))
                            ->push_delta($product, (int) $product->get_stock_quantity(), true);

                        // FIX-8: sólo se salta WC cuando el push REALMENTE maneja
                        // el ítem. Si el pusher no es el dueño (disabled/
                        // invoice_owner/not_linked/not_manageable), cae al writer:
                        // el poll es el dueño de la escritura WC y no se congela.
                        $handled = ['ok', 'already_applied', 'in_sync', 'api_error',
                                    'blocked', 'locked', 'baseline_unverified'];
                        if (in_array($push['reason'], $handled, true)) {
                            continue;
                        }
                    }

                    $old_qty = $product->get_stock_quantity();
                    try {
                        // C7: suprimir la cascada del propio wc_update_product_stock
                        // (set_syncing del poll es T7.3; acá el guard por producto).
                        set_transient('alegra_updating_product_' . $product_id, 1, 30);

                        $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
                            'source'       => 'alegra',
                            'preserve'     => in_array('inventory', $this->resolve_preserve_fields(), true),
                            'manage_stock' => 'respect',   // T2.5: $opt_in ? 'enable' : 'respect'
                            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
                            'warehouse_id' => $this->resolve_warehouse_id(),
                        ]);

                        if ($status === 'updated' || $status === 'clamped_negative') {
                            // FIX-1: éste es el ÚNICO camino del poll que fija
                            // `synced` sin push: el poll escribió el valor de
                            // Alegra en WC, así que WC y Alegra acuerdan.
                            Inventory_Pusher::set_synced($product_id, (int) $product->get_stock_quantity());
                            Inventory_Pusher::clear_pending($product_id);
                            $result['updated']++;
                            $new_qty = $product->get_stock_quantity();
                            if ($old_qty !== $new_qty) {
                                $this->logger->info('Inventory updated from Alegra', [
                                    'product_id' => $product_id,
                                    'alegra_id'  => $item['id'],
                                    'old_qty'    => $old_qty,
                                    'new_qty'    => $new_qty,
                                ]);
                            }
                        } elseif ($status === 'skipped_not_manageable') {
                            $result['skipped_not_manageable']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error('Failed to update inventory', [
                            'product_id' => $product_id,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        delete_transient('alegra_updating_product_' . $product_id);
                    }
```

**Orden de operaciones (por ítem):** ledger (`pending`/`synced`) → `needs_reconcile` →
`push_delta(..., true)` → si el push **maneja** el ítem (`ok`/`already_applied`/`in_sync`/`api_error`/
`blocked`/`locked`/`baseline_unverified`) ⇒ `continue`; si **no puede** empujar (`disabled`/
`invoice_owner`/`not_linked`/`not_manageable`) ⇒ cae al writer (FIX-8) → set transient anti-cascada →
`Inventory_Writer::apply()` → `synced = WC` + clear `pending` si escribió → clear transient (finally).

**Interacción con la regla de overwrite del poll (explícita):**
- **`pending !== ''`** ⇒ reconciliar con `push_delta(from_poll=true)`. Si el push sale (`ok`/
  `already_applied`) o falla (`api_error`/`blocked`/`locked`) ⇒ el poll **NO** escribe WC. Si el
  pusher no es el dueño (`disabled`/`invoice_owner`/`not_linked`/`not_manageable`) ⇒ **cae al writer**
  (FIX-8). **Nunca** re-infla.
- **`synced !== '' && WC != synced`** ⇒ delta local (venta/edición) sin empujar ⇒ reconciliar; mismo
  criterio que arriba.
- **`synced === '' && pending === ''`** (primera vez) ⇒ el poll escribe el valor de **Alegra** y fija
  `synced` (FIX-1: baseline desde Alegra, **nunca** desde WC).
- **FIX-8 — el poll no se muere de hambre:** con `push_inventory_enabled=false`, `owner=invoice`,
  producto sin `_alegra_item_id` o `manage_stock=false`, el poll **siempre escribe WC** con el valor de
  Alegra (es su rol de pull), en vez de hacer `continue` para siempre.

> **Coordinación con Fase 7.** `T7.1` reescribe el loop del poll (budget/cursor/`truncated`). Debe
> **preservar** este bloque ledger+writer **tal cual** (incluido `from_poll=true` y el fallthrough de
> FIX-8); `T7.3` agrega `set_syncing` alrededor del loop (el guard por producto de esta tarea queda como
> defensa en profundidad). **FIX-13 (prerrequisito de harness):** los tests de cursor de Fase 7 sólo
> valen si el dueño del harness hace que `alegra-mock.php:659-671` aplique `start`/`limit` en
> `GET /items` **siempre** (hoy sólo con `metadata=true`).

**Resultado esperado (aceptación verificable)** (el titular):
- Producto con WC 10, Alegra 10, `synced=10`; vender 3 en WC (WC 7) ⇒ `push_delta` emite el ajuste
  (Alegra 7, `synced=7`); correr el poll ⇒ WC **queda en 7**, no sube a 10.
- **FIX-1:** venta **antes del primer poll** (`synced=''`, `pending=7`, Alegra 10) ⇒ el poll hace
  `GET /items/{id}`, fija `synced=10` desde Alegra y empuja el delta ⇒ WC 7 y Alegra 7 (no re-infla).
- Push fallido (`api_error`) ⇒ `pending` queda; correr el poll ⇒ reintenta el push y WC **no** sube.
- Primera corrida (`synced=='' && pending==''`) ⇒ WC toma el valor de Alegra y `synced` queda seteado.
- **FIX-8:** con `push_inventory_enabled=false` (o `owner=invoice`) y WC divergente ⇒ el poll **escribe
  WC** con el valor de Alegra (no queda congelado).

**Dependencias**: `T3.1`–`T3.3`; `T2.4` (bloque writer en el poll); `T1.4` (mock que aplica el delta y
soporta `GET /inventory-adjustments`); `T1.6` (H1 `wc_update_product_stock`).
**Prerrequisito de harness (FIX-13):** `alegra-mock.php:659-671` debe paginar `GET /items` **siempre**
(no sólo con `metadata=true`); lo posee el dueño del harness.

**Trazabilidad**: REQ-INV-01, REQ-INV-08, NFR-07; D2 §3.5, D6; R3/DR2; FIX-1/FIX-2/FIX-8/FIX-13.

**Verificación** (FIX-11 — el stub `save()` **no** dispara hooks, `wp-stubs.php:1284`):
1. Test `T29.34 EL TITULAR (vender 3 → poll → WC no sube)`:
   `push_orders=false`; producto `_alegra_item_id='4'`, `_alegra_stock_synced=10`, `manage_stock=true`,
   WC stock 10, Alegra `availableQuantity=10`. **FIX-11:** registrar los hooks con
   `Inventory_Pusher::register_hooks($api, $logger)` y simular la venta **disparando el hook
   explícitamente**: `$p->set_stock_quantity(7); do_action('woocommerce_product_set_stock', $p);`
   (o `wc_update_product_stock($p, 7, 'set', false)`). `$p->save()` **no** sirve: el stub no dispara
   nada. Luego `sync_inventory_from_alegra()` ⇒ `assertSame(7, wc_get_product($id)->get_stock_quantity())`
   y `assertSame(7, alegra_mock_state['items']['4']['inventory']['availableQuantity'])`.
   **Prove-it-catches**: quitar el chequeo del ledger y volver a escribir WC incondicional ⇒ WC sube a
   10 y el test falla.
2. Test `T29.34b venta antes del primer poll no se re-infla (FIX-1)`: `synced=''`, `pending=7`, WC 7,
   Alegra 10; correr `sync_inventory_from_alegra()` ⇒ el poll hace `GET /items/4`, fija `synced=10` y
   empuja `out 3` ⇒ WC 7 y Alegra 7.
   **Prove-it-catches**: volver al baseline viejo (`synced = $new_qty` en el hook) ⇒ el poll ve
   `WC == synced`, escribe Alegra 10 y WC sube a 10: el test falla.
3. Test `T29.34c el poll no se muere de hambre (FIX-8)`: `push_inventory_enabled=false`, `synced=10`,
   WC 7, Alegra 5 ⇒ el poll **escribe WC=5** (no `continue`).
   **Prove-it-catches**: hacer `continue` ante `disabled` ⇒ WC queda 7 y el test falla.
4. Test `T29.36 push fallido no re-infla`: `alegra_mock_fail('POST','/inventory-adjustments',500,…);`
   vender 3 ⇒ `pending` queda; correr el poll (mock OK) ⇒ WC 7 y `pending` limpio.
5. Test cross-fase `T29.36b el poll reintenta con set_syncing activo (FIX-2)` — **corre con `T7.3`
   aplicado (Fase 7)**: `Public_::set_syncing(true)`; `synced=10`, WC 7, `pending` seteado; correr el
   poll ⇒ **1 POST** y `pending` limpio.
   **Prove-it-catches**: dejar el guard `is_syncing` dentro de `push_delta` ⇒ 0 POST y `pending` sucio.
6. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el `set_transient` anti-cascada no cubra un `save()` asincrónico posterior; y que el
mock no pagine `GET /items` (FIX-13). **Guarda**: TTL 30 + Guard 1 de `push_delta`; `T7.3` agrega
`set_syncing` global; el mock debe paginar siempre (prerrequisito de harness).

**Estimación**: M (2 h).

---

### T3.5 — Dueño `invoice` + `open_invoice_on_paid` · `BLOQUEADO(Fase 0.1 / G1)`

**Objetivo**: que con `push_orders_enabled=true` **y** `open_invoice_on_paid=true` la factura sea la
dueña del movimiento: un pedido pagado deja la factura `open` (mueve stock nativo), **incluso si ya
existía un borrador**, y el plugin **no** emite ajustes.

**Descripción técnica**: en modo `invoice`, el pusher ya no-opera (`push_delta` → `invoice_owner`,
`T3.2`). Falta que la factura **abra** al pagarse: HEAD usa `invoice_status='draft'` por defecto
(`alegra-connector.php:451`, `Orders.php:1094`), y un borrador **no** mueve stock (premisa
`BLOQUEADO`/R1). `create_invoice_with_payment()` ya pasa `'open'` pero **sólo** si hay cuenta de pago
(`Orders.php:352-360`); esta tarea lo hace independiente con la opción nueva (C4). **FIX-3 (D1):** el
override actual se insertaba antes de `prepare_invoice_data()` (`:92`), pero `create_invoice()`
**retorna mucho antes** (`:82-90`) cuando el pedido ya tiene `_alegra_invoice_id` (el caso normal: la
factura nació `draft` en el alta del pedido). Por eso, con owner `invoice`, hay que **abrir el borrador
pre-existente** antes de ese early return; si no, la factura queda `draft` para siempre y ningún
mecanismo mueve stock (el bug de D1). Cubre REQ-INV-01 (rama b) y REQ-INV-08.

**Desarrollo técnico**:

1. **Opción (C4):** `alegra_connector_open_invoice_on_paid` (bool, default **true**, autoload **no**).
   **Falta en el design §11** (que lista 8). Agregar a `alegra-connector.php` `$defaults` (junto a
   `:451`) y `$non_autoload` (`:463-486`), a `uninstall.php`, y `register_setting` en
   `Admin_Dashboard.php`. **`T1.7` pasa de 8 a 9 opciones.**

```php
            'alegra_connector_open_invoice_on_paid' => true,
```

2. **`Orders::create_invoice()` — abrir el borrador pre-existente (FIX-3/D1).** Insertar **dentro** de
   la rama `if ($alegra_id !== '')`, **antes** del `return` de `:89`:

```php
            $alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);

            if ($alegra_id !== '') {
                // FIX-3 (D1): con owner=invoice, un pedido que pasa a pagado
                // debe ABRIR el borrador pre-existente. Hoy se retorna sin
                // abrirlo ⇒ la factura queda draft y no mueve stock.
                if (Inventory_Pusher::owner() === 'invoice'
                    && (bool) get_option('alegra_connector_open_invoice_on_paid', true)
                    && $order->is_paid()) {
                    $opened = $this->ensure_invoice_open($alegra_id, true);
                    if (!is_wp_error($opened)) {
                        $this->persist_invoice_status($order, $opened);
                    } elseif ($this->logger) {
                        $this->logger->warning('No se pudo abrir el borrador pre-existente (owner=invoice)', [
                            'order_id'   => $order_id,
                            'invoice_id' => $alegra_id,
                            'error'      => $opened->get_error_message(),
                        ]);
                    }
                }
                $this->logger->info('Order already has Alegra invoice', [
                    'order_id'  => $order_id,
                    'alegra_id' => $alegra_id,
                ]);
                return ['id' => $alegra_id, 'already_exists' => true];
            }
```

3. **`Orders::create_invoice()` — reusar una factura `open` existente (FIX-3).** Antes de
   `$data = $this->prepare_invoice_data(...)` (`:92`), y sólo con owner `invoice`, buscar una factura
   `open` ya existente del pedido y reusarla (evita doble conteo con una factura abierta a mano). Éste
   es el chequeo "factura `open` existente" de FIX-3; vive en el flujo de **pedido** porque el hook de
   stock es a nivel producto y no tiene contexto de pedido:

```php
            // FIX-3: no crear una segunda factura si el pedido ya tiene una
            // `open` (p. ej. abierta manualmente desde el dashboard).
            if (Inventory_Pusher::owner() === 'invoice') {
                $open_invoice = $this->find_open_invoice_for_order($order);
                if ($open_invoice !== null) {
                    $this->persist_invoice_result($order, (string) $open_invoice['id'], $open_invoice);
                    return ['id' => (string) $open_invoice['id'], 'already_exists' => true];
                }
            }
```

Y el override de estado para un pedido pagado **sin** factura previa (caso que hoy funciona):

```php
            // D2 (REQ-INV-01 rama b): con la factura como dueña, un pedido pagado
            // nace `open` para que Alegra descuente stock nativo. El borrador no
            // mueve stock (G1). Sólo aplica cuando el dueño es `invoice`.
            if ($status_override === null
                && Inventory_Pusher::owner() === 'invoice'
                && $order->is_paid()) {
                $status_override = 'open';
            }
```

4. **Helper `find_open_invoice_for_order()`** en `Orders` (nuevo, privado):

```php
    /**
     * FIX-3: factura `open` existente del pedido (evita doble conteo).
     *
     * @return array<string,mixed>|null
     */
    private function find_open_invoice_for_order(\WC_Order $order): ?array
    {
        $linked = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($linked !== '' && (string) $order->get_meta('_alegra_invoice_status', true) === 'open') {
            return ['id' => $linked, 'status' => 'open'];
        }
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        $existing = $this->find_existing_invoice($order, $client_id);
        if ($existing !== null && (string) ($existing['status'] ?? '') === 'open') {
            return $existing;
        }
        return null;
    }
```

`prepare_invoice_data()` (`:1094`) ya resuelve `$status_override ?? get_option(...,'draft')`, así que
no se toca.

5. **Pusher:** sin cambios; `push_delta` devuelve `invoice_owner` (cero ajustes). Con
   `push_orders=true` + `open_invoice_on_paid=false` el dueño es `adjustment` (K-P/FIX-3) ⇒ el ajuste
   **sí** se emite: nunca "nadie mueve" (REQ-INV-08).

6. **Registro/UI:** `register_setting` (pestaña Sincronización) + toggle en
   `templates/admin-settings.php`, visible sólo cuando `push_orders_enabled=true`.

**Rama G1 (Fase 0.1):**
- **Rama A (`draft` no mueve, `open` sí):** se mantiene la apertura del borrador (default true).
- **Rama B (`draft` sí mueve):** se documenta; el dueño factura se mantiene, pero el sistema **no
  miente**: con `open_invoice_on_paid=false` el dueño pasa a `adjustment` (FIX-3) y el ajuste cubre el
  movimiento.

**Orden de operaciones:** `create_invoice` → si `_alegra_invoice_id` existe y owner=invoice y pagado ⇒
`ensure_invoice_open(..., true)` + persistir → return `already_exists`; si no hay factura y owner=invoice
⇒ buscar una `open` existente y reusar; si no ⇒ override `'open'` (si pagado) → `prepare_invoice_data`
→ POST. **No** se toca `create_invoice_with_payment` (su override por cuenta de pago sigue teniendo
prioridad porque pasa `$status_override='open'` explícito).

**Resultado esperado (aceptación verificable)**:
- `push_orders=true` + `open_invoice_on_paid=true` + pedido pagado **sin** factura ⇒ `create_invoice`
  manda `status='open'` y **no** se emite ningún `POST /inventory-adjustments`.
- **FIX-3 (D1):** `push_orders=true` + `open_invoice_on_paid=true` + pedido con factura `draft`
  pre-existente que pasa a pagado ⇒ `ensure_invoice_open` la deja `open` (mueve stock) y **no** se crea
  una segunda factura.
- **FIX-3:** pedido con factura ya `open` ⇒ se reusa (0 facturas nuevas, 0 ajustes).
- `push_orders=true` + `open_invoice_on_paid=false` ⇒ owner `adjustment`: el ajuste **sí** se emite.
- `push_orders=false` ⇒ owner `adjustment` y `open_invoice_on_paid` es inerte.

**Dependencias**: `T3.1`, `T3.2`; Fase 0.1 (G1); `T1.7` (opciones, ahora 9).

**Trazabilidad**: REQ-INV-01 (rama b), REQ-INV-08; D2 §3.3/§12; `docs/sdd/inventory/REQ-INV-1/2`; FIX-3.

**Verificación**:
1. Test `T29.37 dueño invoice`: `push_orders=true` + `open_invoice_on_paid=true`; pedido `is_paid()`
   **sin** factura; llamar `create_invoice` con un mock que captura el body ⇒
   `assertSame('open', $body['status'])`; y `push_delta` con owner invoice ⇒ `assertSame('invoice_owner', …)`
   y 0 POST a `/inventory-adjustments`.
   **Prove-it-catches**: quitar el override ⇒ el body queda `draft` y el test falla.
2. Test `T29.37b abre el borrador pre-existente (FIX-3/D1)`: pedido con `_alegra_invoice_id` de una
   factura `draft` y `is_paid()=true`; `push_orders=true` + `open_invoice_on_paid=true`; llamar
   `create_invoice_with_payment()` ⇒ la factura queda `open` (mock) y **0** POST a
   `/inventory-adjustments`; `_alegra_invoice_id` **no** cambia (no se creó una segunda).
   **Prove-it-catches**: dejar el `return` temprano sin abrir ⇒ la factura sigue `draft` y el test falla.
3. Test `T29.37c reusa factura open existente (FIX-3)`: pedido **sin** `_alegra_invoice_id` pero con
   una factura `open` en el mock que lleva el marcador `Pedido WooCommerce #<id>` (y `client_id` del
   pedido); owner invoice ⇒ `create_invoice` la reusa (`find_open_invoice_for_order`), devuelve
   `already_exists` y **no** POSTea una factura nueva.
4. Test `T29.38 owner adjustment emite ajuste`: `push_orders=false` ⇒ 1 POST a `/inventory-adjustments`;
   `push_orders=true` + `open_invoice_on_paid=false` ⇒ **también** 1 POST (FIX-3, REQ-INV-08); owner
   invoice real ⇒ 0 POST.
5. `bash scripts/exec-test.sh` verde.

**Riesgo**: que G1 revele que `open` no es alcanzable (DR1/G1 rama B). **Guarda**: con
`open_invoice_on_paid=false` el dueño pasa a `adjustment` (FIX-3) ⇒ **nunca** "nadie mueve" ni se emiten
ajuste y factura a la vez; `T3.6` reporta la divergencia.

**Estimación**: L (3 h).

---

### T3.6 — Informe de divergencia (read-only)

**Objetivo**: que el dashboard liste los pedidos vendidos sin `_alegra_invoice_id` cuando
`push_orders_enabled=false`, advirtiendo que el stock de Alegra no refleja esas ventas.

**Descripción técnica**: con el dueño `adjustment`, las ventas **sí** se reflejan vía ajuste; con el
dueño `invoice`, los movimientos **sin pedido** (edición manual/POS) no se empujan y las facturas
pueden quedar en borrador. `REQ-INV-07`/`docs/sdd/inventory/REQ-DIV-4` exigen visibilidad honesta, no
inventar cobertura. Read-only, sin escrituras.

**Desarrollo técnico**:

1. **Helper** en `admin/Admin/Admin_Dashboard.php` (HPOS-safe):

```php
    /**
     * REQ-INV-07: ventas sin factura vinculada. Sólo lectura.
     *
     * @return array{count:int, orders:array<int,array{id:int,status:string,total:float,date:string}>}
     */
    public function get_unjournaled_sales(int $limit = 20): array
    {
        $ids = wc_get_orders([
            'status'     => ['processing', 'completed'],
            'limit'      => $limit,
            'return'     => 'ids',
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => [[ 'key' => '_alegra_invoice_id', 'compare' => 'NOT EXISTS' ]],
        ]);
        $orders = [];
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order) { continue; }
            $orders[] = [
                'id'     => (int) $id,
                'status' => (string) $order->get_status(),
                'total'  => (float) $order->get_total(),
                'date'   => (string) $order->get_date_created(),
            ];
        }
        return ['count' => count($orders), 'orders' => $orders];
    }
```

2. **`render_dashboard()`** (`:826-838`) — pasar la variable al template:

```php
        $divergence = (!get_option('alegra_connector_push_orders_enabled', false))
            ? $this->get_unjournaled_sales()
            : ['count' => 0, 'orders' => []];
```

3. **`templates/admin-dashboard.php`** — insertar una fila en la card "Estado de facturación"
   después de la fila "Modo de prueba" (`:160`):

```php
        <?php if (!empty($divergence['count'])): ?>
        <div class="alegra-health-row is-danger">
            <span class="alegra-health-dot is-amber"></span>
            <span class="alegra-health-label"><?php esc_html_e('Ventas sin factura', 'alegra-connector'); ?></span>
            <span class="alegra-health-status">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of sold orders without a linked Alegra invoice. */
                    __('%d pedidos vendidos no tienen factura en Alegra', 'alegra-connector'),
                    (int) $divergence['count']
                )); ?>
            </span>
            <span class="alegra-health-action">
                <?php esc_html_e('El stock de Alegra puede no reflejar esas ventas.', 'alegra-connector'); ?>
            </span>
        </div>
        <?php endif; ?>
```

**Orden de operaciones:** sólo cuando `push_orders=false` se consulta; sin divergencia no se renderiza
nada (REQ-INV-07 escenario negativo).

**Resultado esperado (aceptación verificable)**:
- `push_orders=false` + 3 pedidos vendidos sin `_alegra_invoice_id` ⇒ la fila muestra 3 y la
  advertencia.
- Todos con factura ⇒ `count=0`, sin fila.
- El render no emite ninguna escritura.

**Dependencias**: `T3.1`; `templates/admin-dashboard.php`; `T5.x`/`T6.x` no interfieren (filas
distintas).

**Trazabilidad**: REQ-INV-07; `docs/sdd/inventory/REQ-DIV-4`; D2 §15.

**Verificación**:
1. Test `T29.39 divergencia`: seed de 3 pedidos `processing` sin `_alegra_invoice_id` ⇒
   `assertSame(3, $admin->get_unjournaled_sales()['count'])`; agregar `_alegra_invoice_id` a todos ⇒
   `count=0`. **Prove-it-catches**: quitar el `meta_query` NOT EXISTS ⇒ cuenta los facturados y falla.
2. `bash scripts/smoke-test.sh` verde.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `NOT EXISTS` en `meta_query` no sea HPOS-compatible. **Guarda**: `wc_get_orders()` es
la API HPOS-safe; el test lo cubre.

**Estimación**: M (2 h).

---

### T3.7 — Tests del titular

**Objetivo**: cubrir el ciclo completo del titular, el no-doble-conteo y la reconciliación con lock.

**Descripción técnica**: cierra la fase con la batería `T29.31`–`T29.39` **más los casos sufijados**
(`T29.32b/c`, `T29.34b/c`, `T29.36b`, `T29.37b/c`) que cierran FIX-1/FIX-2/FIX-3/FIX-4/FIX-8. El test
central es `T29.34` (vender 3 → poll → WC no sube). El de no-doble-conteo es `T29.38`.

**Desarrollo técnico**: agregar al final de la sección `T29` (tras los tests de Fase 2). **FIX-11:** los
tests que simulan una venta **no** usan `$p->save()` (el stub no dispara hooks): registran los hooks con
`Inventory_Pusher::register_hooks($api, $logger)` y disparan `do_action('woocommerce_product_set_stock',
$p)` (o `wc_update_product_stock()`):

| Test | Cubre | Assert clave |
|---|---|---|
| `T29.31` | REQ-INV-01 | `owner()` (FIX-3) y payload documentado (`items[]`, `unitCost > 0`) |
| `T29.32` | REQ-INV-01/08, NFR-07 | baseline hook (`baseline_pending`, `synced` vacío) / in_sync / delta aplicado / api_error deja pending |
| `T29.32b` | **FIX-1** | baseline desde Alegra: `synced=''` + `pending` ⇒ `GET /items/{id}` + push del delta |
| `T29.32c` | **FIX-4** | pre-búsqueda obligatoria: ajuste existente ⇒ `already_applied`, 0 POST |
| `T29.33` | NFR-07 | lock por producto ⇒ `locked` |
| `T29.34` | **REQ-INV-01 (titular)** | vender 3 → poll → WC **7**, Alegra **7** (hook explícito, FIX-11) |
| `T29.34b` | **FIX-1** | venta antes del primer poll no se re-infla |
| `T29.34c` | **FIX-8** | el poll no se muere de hambre: `disabled` ⇒ escribe WC |
| `T29.35` | REQ-INV-01 | hooks de stock + `is_syncing` corta en el hook (FIX-2) |
| `T29.36` | REQ-INV-01 (fallo) | push falla → pending → poll no re-infla |
| `T29.36b` | **FIX-2 (cross-fase)** | con `set_syncing(true)` el poll reintenta y limpia `pending` (corre con T7.3) |
| `T29.37` | REQ-INV-01 (rama b) | owner invoice ⇒ factura `open`, 0 ajustes |
| `T29.37b` | **FIX-3/D1** | borrador pre-existente se abre al pagar |
| `T29.37c` | **FIX-3** | factura `open` existente se reusa (no se duplica) |
| `T29.38` | REQ-INV-08 | `push_orders=true`+`open_invoice_on_paid=false` ⇒ ajuste; owner invoice ⇒ 0 ajustes |
| `T29.39` | REQ-INV-07 | informe de divergencia |

**Resultado esperado (aceptación verificable)**: `bash scripts/exec-test.sh` ⇒ `EXEC-TEST OK` con
`T29.31`–`T29.39` y los sufijados `b/c` verdes y 0 failed.

**Dependencias**: `T3.1`–`T3.6`; `T1.4` (mock `/inventory-adjustments` con `GET` y `POST`); `T1.6`
(H1 `wc_update_product_stock`); Fase 2. **FIX-13:** los tests de cursor de Fase 7 requieren que el dueño
del harness pagine `GET /items` siempre (`alegra-mock.php:659-671`).

**Trazabilidad**: REQ-INV-01/07/08; NFR-01/NFR-07; FIX-1/FIX-2/FIX-3/FIX-4/FIX-8/FIX-11.

**Verificación**:
1. `bash scripts/exec-test.sh` → `EXEC-TEST OK`, 0 failed.
2. Documentar el prove-it-catches de `T29.34` (revertir `T3.4` → rojo) y de `T29.34b` (revertir el
   baseline FIX-1 → rojo) en `docs/RELEASE_2.6.0_VERIFICATION.md` (`T9.6`).
3. **Manual WP-admin** (`T9.4`, problema 2): vender en WC → "Sincronizar inventario" → el stock **no**
   sube; el log muestra `Inventory adjustment failed`/`Inventory updated from Alegra` según el caso.
4. `bash scripts/smoke-test.sh` → `SMOKE OK` (PSR-4 carga `Inventory_Pusher`).

**Riesgo**: que el mock H4 no aplique el delta y `T29.34` dé falso verde. **Guarda**: `T1.4` asserta
que `type=out` **resta** `availableQuantity`; el test del titular verifica ambos lados (WC y Alegra).

**Estimación**: L (3 h).

---

## DoD de la fase

- Existe `includes/Sync/Inventory_Pusher.php` (`owner()`, `register_hooks($api,$logger)`,
  `on_stock_changed()`, `on_variation_stock_changed()`, `push_delta($product,$qty,$from_poll=false)`,
  `resolve_warehouse_id()`, `fetch_alegra_available_quantity()`, `adjustment_already_exists()`).
- **El titular está cerrado:** vender 3 → poll → WC **no** sube (`T29.34`).
- **FIX-1:** el baseline de `_alegra_stock_synced` sale de Alegra (`GET /items/{id}`) o de un push OK;
  una venta antes del primer poll no se re-infla (`T29.34b`).
- **FIX-2:** el guard `is_syncing()` vive en `on_stock_changed`; el poll reconcilia con
  `push_delta(..., true)` (`T29.35`, `T29.36b`).
- **FIX-3:** `owner=invoice` sólo con `push_orders_enabled && open_invoice_on_paid`; el borrador
  pre-existente se abre al pagar y una factura `open` existente se reusa (`T29.37`, `T29.37b/c`,
  `T29.38`).
- **FIX-4:** la pre-búsqueda `GET /inventory-adjustments` es obligatoria antes de re-emitir (`T29.32c`).
- **FIX-8:** el poll no se muere de hambre: con `disabled`/`invoice_owner`/`not_linked`/`not_manageable`
  cae al `Inventory_Writer` (`T29.34c`).
- **FIX-11:** los tests manejan el hook de stock explícitamente (el stub `save()` no lo dispara).
- **FIX-12:** `unitCost` nunca `0.0` (costo → precio → `1.0`).
- **FIX-13:** prerrequisito de harness documentado (el mock debe paginar `GET /items` sin `metadata`).
- El payload de `/inventory-adjustments` es el documentado (`date` + `items[{id,type,quantity,unitCost}]`
  + `warehouse?`) — **corrección C1 aplicada**.
- **Sin doble conteo:** un solo dueño por movimiento (`T29.38`).
- El fallo de un push deja `pending` y el poll reintenta **sin** re-inflar (`T29.36`).
- `open_invoice_on_paid` existe como **9.ª** opción (C4), registrada en `$defaults`/`$non_autoload`/
  `uninstall.php`/`register_setting` + UI.
- Informe de divergencia read-only en el dashboard (REQ-INV-07).
- La contradicción con `docs/sdd/inventory/DD-8` está documentada en `CHANGELOG.md` (`T9.5`).
- `bash scripts/exec-test.sh` (`T29.31`–`T29.39` + sufijos) y `bash scripts/smoke-test.sh` verdes.
- **Prove-it-catches** documentado para `T3.4` (`T29.34`) y `T29.34b` (FIX-1).
