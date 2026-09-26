# Fase 5 — D4: reconciliación WC↔Alegra (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **5** — Reconciliación: detección de divergencia + causa, informe, des-inversión del gate "Ventas sin factura" y reparación explícita (un solo mecanismo) |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) |
| Versión objetivo | **2.7.0** |
| Harness | `bash scripts/exec-test.sh` · `bash scripts/smoke-test.sh` · `smoke-load.php` (PSR-4 de `Stock_Divergence`) |
| Tareas del esqueleto | `T5.1`–`T5.5` (5 micro-tareas) |
| Depende de | Fase 3 (`T3.5` registra la divergencia; `T3.1` máquina de estados) · Fase 4 (`T4.2` ledger; `T4.3` call sites) · Fase 1 (`T1.8` esqueleto, sección `T30`) · Fase 2 (`owner()` `T2.1`) |
| Gates | `T5.4` **G1** (Rama A/B de `draft`) |
| DoD de la fase | el informe lista los productos divergentes **con causa**; la fila "Ventas sin factura" se ve siempre y detecta `draft`/`void`; la reparación es explícita, emite **un** mecanismo y deja rastro. Tests canónicos `T30.51`–`T30.56` (6 IDs) verdes con prove-it-catches |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Sin eso, el test
> no se acepta. `T5.4` es una de las 5 tareas centrales; su reversión va en
> `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`).
>
> **IDs de test.** Los tests viven en `scripts/exec-test.php`, sección
> `// === stock-ownership (2.7.0) ===` (creada en `T1.9`), con IDs `T30.5x` (fase 5). La lista canónica
> (§2.1 de `tasks.md`) es: `T30.51`, `T30.52`, `T30.53`, `T30.54`, `T30.55`, `T30.56` (**6** IDs). Un ID
> inline distinto queda **superseded**. `T5.x` son IDs de **tarea**, no de test.
>
> **Estado de HEAD (relevante).** `Stock_Divergence` **no existe** (`find` = 0). El poll ya registra la
> máquina de estados (`Products.php:1327-1353`), pero la persistencia de la divergencia (`T3.5`) y el
> informe (`T5.x`) no. `get_unjournaled_sales()` (`Admin_Dashboard.php:862-887`) y su gate invertido
> (`:903-905`) están tal como los describe el diseño. `owner()` **todavía** no lee la opción
> (`Inventory_Pusher.php:85-91`): `T5.1`/`T5.4` dependen de `T2.1`.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 5)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `get_unjournaled_sales()` `:862-887` y gate `:903-905` (`design.md` §5.2; `spec.md` REQ-RECON-02) | **Confirmado exacto.** `:865` = `['processing','completed']`; `:870` = `meta_query ['key'=>'_alegra_invoice_id','compare'=>'NOT EXISTS']`; gate `:903` = `!get_option('alegra_connector_push_orders_enabled', false)`. | `T5.3` |
| C2 | `templates/admin-dashboard.php:197-212` (fila "Ventas sin factura") | **Confirmado.** Fila `:197-212`; el bloque sólo se renderiza `if (!empty($divergence['count']))` (`:197`). | `T5.3` |
| C3 | `Controller::sync_entity('order', …)` (design §5.3) | **Confirmado.** `:321` firma; `:330-331` `case 'order'`; `sync_single_order()` `:375`; `'complete'` → `create_invoice_with_payment()` `:386`. | `T5.4` |
| C4 | `Inventory_Pusher.php:249-251` (ajuste) y Guard 2 (design §5.3) | **Confirmado.** `:249-251` `create_inventory_adjustment`; Guard 2 `:170-173` corta con `reason='invoice_owner'` si `owner() !== 'adjustment'`. | `T5.4` |
| C5 | `Write_Gate::run_explicit` `:156` | **Confirmado.** `:156-164`; patrón ya usado por `ajax_open_invoice` (`:2842`), `ajax_record_payment` (`:2899`), `ajax_sync_single` (`:3168`), `ajax_bulk_sync` (`:3307`). | `T5.4` |
| C6 | `Products.php:1327-1353` es el bloque consumido por `T3.5` (design §5.1) | **Confirmado.** `:1329` `synced`, `:1332-1334` `needs_reconcile`, `:1348-1350` `$handled`. `T3.5` agrega el contador `$divergence` run-scoped **junto** a `$result` (no como clave: el set canónico es de 9 claves, `Products.php:1147-1157`). | `T5.1` |
| C7 | `Inventory_Pusher.php:283-297` `fetch_alegra_available_quantity` (spec REQ-RECON-01) | **Confirmado.** `:283` firma, `:292` lee `inventory.availableQuantity`, `:297` cierre. | `T5.1` |
| C8 | "Nunca-intentado hoy sólo `processing\|completed`" (`design.md` §4.1/§4.4; `tasks.md` §9.3 S2) | **Cierto sólo para `get_unjournaled_sales()`** (`Admin_Dashboard.php:865`). El bulk chunked **ya** incluye `on-hold` (`ajax_sync_pending_start`, `:3874`). El cambio intencional de `T5.3` es agregar `on-hold` a `:865` y a `Invoice_Queue` (`T4.10`). | `T5.3` |
| C9 | `Write_Gate.php:43`/`:58` (inventory ya sembrado) | **Confirmado.** `ENTITY_OPTIONS['inventory']` `:43`; `ENTITY_DEFAULTS['inventory']=true` `:58`. No se repite en `T1.5`. | `T5.4` |
| C10 | `Stock_Divergence` (clase nueva) | **No existe** en HEAD (`find` = 0). El autoloader (`alegra-connector.php:95-134`, subdir `Sync` en `:118`) la resuelve por PSR-4. | `T1.8`, `T5.x` |

**Citas confirmadas exactas:** `Admin_Dashboard.php:862-887` (`get_unjournaled_sales`), `:889-908` (`render_dashboard`), `:903-905` (gate), `:2842`/`:2899`/`:3168`/`:3307` (`run_explicit`), `:106-234` (`add_admin_menu`); `templates/admin-dashboard.php:197-212`; `Controller.php:321`/`:375`/`:386` (`sync_entity`), `:440`/`:467` (`acquire_lock`/`release_lock`); `Inventory_Pusher.php:85-91` (`owner()`), `:170-173` (Guard 2), `:249-251` (ajuste), `:283-297` (`fetch_alegra_available_quantity`), `:340-358` (`build_adjustment_payload`); `Products.php:1142` (firma del poll), `:1147-1157` (9 claves canónicas), `:1327-1353` (bloque del poll), `:2274-2285` (W1); `Orders.php:203-229` (`persist_invoice_result`), `:309` (`find_existing_invoice`), `:437` (`find_open_invoice_for_order`), `:494`/`:512`/`:525-526` (`create_invoice_with_payment`), `:850-913` (`ensure_invoice_open`); `Write_Gate.php:43`/`:58`/`:156`; `Logger.php:198-241` (patrón option-backed); `alegra-connector.php:6` (2.6.0), `:474-507`/`:509-513` (`$non_autoload` + loop).

**Bloqueantes cerrados en esta revisión (Fase 5):**

| # | Bloqueante | Fix aplicado |
|---|---|---|
| B5/Oracle#2 | `divergence_cause()` con 3er arg `?\WC_Order` y causas `factura_fallida`/`reembolso`/`factura_pendiente` inalcanzables desde el poll | Firma canónica **2 args** (`design.md:913,1173`, `tasks.md:474`); el poll sólo `baseline_ausente`/`divergencia_dueno`; las causas por pedido se resuelven en `report()` (`T5.2`) con memoización por `order_id`. |
| Gap #6 | `find_paid_order_without_open_invoice()` sin definir | Definido como método **público de `Orders`** (reusa `find_open_invoice_for_order()`, que chequea `_alegra_invoice_id` + `find_existing_invoice`); coste acotado `limit=20`; call site cambiado a `$orders->…`. |
| Gap #5 | La rama `invoice` de `T5.4` no respeta el dueño | Guard `owner() !== 'invoice'` ⇒ error sin tocar la API (REQ-RECON-03). |
| C8 | Paso (a) divergente entre `T5.4` y `T7.4` | **Secuencia canónica única** (idéntica en ambos): `sanitize_stock_owner('invoice')` **persistido** + coerción de `push_orders_enabled`/`open_invoice_on_paid` por sus sanitizers. |
| Gap #3 (Fase 3) | `preserve=inventory` ignorado en el poll (`fase-3:293-295` hace `set_synced($a)` sin mirar `resolve_preserve_fields()`) | **NO es de esta fase.** Se marca para el dueño de `fase-3`: fijaría un baseline mentiroso y el producto divergiría para siempre (entra al informe con `divergencia_dueno`). El poll debe dejar `S` vacío cuando `preserve=inventory` (`design.md:549-564`). |

---

## Mapa de cobertura Fase 5 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T5.1` | `Stock_Divergence::record()` + `divergence_cause()` (bounded FIFO 200) | `includes/Sync/Stock_Divergence.php` (NUEVO); consumido por `T3.5` |
| `T5.2` | `Stock_Divergence::report()` + `clear()` (paginado, sin GET por producto) | `includes/Sync/Stock_Divergence.php` |
| `T5.3` | Des-invertir el gate + ampliar semántica (`draft`/`void`/vacío) + `on-hold` | `Admin_Dashboard.php:862-887,903-905`; `templates/admin-dashboard.php:197-212` |
| `T5.4` | `ajax_repair_stock_divergence` (un solo mecanismo, respeta el dueño, explícito, G1) | `Admin_Dashboard.php` (NUEVO); `Write_Gate::run_explicit`; `Inventory_Pusher`; `Orders::find_paid_order_without_open_invoice`; `Controller::sync_entity` |
| `T5.5` | Rastro de la reparación (nota + log con delta y mecanismo) | `Admin_Dashboard.php`; `Orders`/`Products` |

---

### T5.1 — `Stock_Divergence::record()` + `divergence_cause()`

**Objetivo**: persistir, sin GET extra, la divergencia que el poll ya midió, con su **causa**; bounded a 200 entradas FIFO.

**Descripción técnica**: el poll ya tiene `W` (stock WC), `A` (stock Alegra), `S` (`_alegra_stock_synced`) y el `reason`. Cuando **no** escribe WC por un cambio local (`LOCAL_PENDING`), registra la divergencia. La persistencia es una option `alegra_connector_stock_divergence` (array, non-autoload, **bounded a 200**, FIFO). La causa se deriva en una función pura. REQ-RECON-01; `design.md` §5.1. Consumido por `T3.5` (`Products.php:1327-1353`), que agrega un contador `$divergence` run-scoped **junto** a `$result` (no como clave: el set canónico es de 9 claves, `Products.php:1147-1157`).

**Desarrollo técnico** — `includes/Sync/Stock_Divergence.php` (NUEVO; esqueleto de `T1.8` completado):

```php
<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

final class Stock_Divergence
{
    public const OPTION = 'alegra_connector_stock_divergence';
    private const MAX   = 200;

    /** @param array{w:int,a:int,s:int|string,cause:string,at:int} $row */
    public static function record(int $product_id, array $row): void
    {
        $all = self::all();
        $all[(string) $product_id] = [
            'w'     => (int) $row['w'],
            'a'     => (int) $row['a'],
            's'     => $row['s'],
            'cause' => (string) $row['cause'],
            'at'    => (int) ($row['at'] ?? time()),
        ];
        // Bounded FIFO: conservar las 200 más recientes.
        if (count($all) > self::MAX) {
            uasort($all, static fn ($x, $y) => $y['at'] <=> $x['at']);
            $all = array_slice($all, 0, self::MAX, true);
        }
        update_option(self::OPTION, $all, false); // autoload = false
    }

    public static function clear(int $product_id): void
    {
        $all = self::all();
        unset($all[(string) $product_id]);
        update_option(self::OPTION, $all, false);
    }

    /**
     * Causa de la divergencia (función pura, firma canónica 2 args — B5/DEF-2).
     * El poll recorre PRODUCTOS y no tiene `WC_Order` ⇒ sólo resuelve las causas
     * que no dependen del pedido. Las causas `factura_fallida`/`reembolso`/
     * `factura_pendiente` las completa el informe (`report()`, T5.2), que sí
     * puede buscar el pedido pagado que contiene el producto (design.md §5.1).
     *
     * @param string $owner 'invoice'|'adjustment' (la firma canónica lo conserva;
     *                      la causa del poll no depende del dueño).
     * @param int|string $s  `_alegra_stock_synced` ('' si nunca)
     */
    public static function divergence_cause(string $owner, int|string $s): string
    {
        // 2 args, pura. NUNCA recibe `?\WC_Order`: el 3er arg que pasaba `T3.5`
        // (`$p`, int|'') era un `TypeError` fatal con declare(strict_types=1), y
        // el poll jamás tendría el pedido. Las causas por pedido viven en T5.2.
        return $s === '' ? 'baseline_ausente' : 'divergencia_dueno';
    }

    /** @return array<string,array{w:int,a:int,s:int|string,cause:string,at:int}> */
    private static function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }
}
```

> **Oracle (O(divergentes)).** `divergence_cause()` es pura y **no** toca el pedido (sólo mira `S`).
> El `get_refunds()`/ledger por pedido vive en `report()` (T5.2): **memoizar por `order_id`** (un
> `get_refunds()` por pedido, no por producto) y resolver en lote; nunca una refund-query por producto
> divergente (NFR-04). El poll no busca pedidos: registra `baseline_ausente`/`divergencia_dueno`.

**Integración con el poll (`T3.5`, `Products.php:1327-1353`):** cuando el poll no escribe WC por cambio local (cae al writer o `needs_reconcile`), agrega:
```php
Stock_Divergence::record($product_id, [
    'w' => (int) $product->get_stock_quantity(),
    'a' => (int) ($item['inventory']['availableQuantity'] ?? 0),
    's' => Inventory_Pusher::synced($product_id),
    'cause' => Stock_Divergence::divergence_cause(Inventory_Pusher::owner(), Inventory_Pusher::synced($product_id)),
    'at' => time(),
]);
```
El contador `$divergence` vive **junto** a `$result` (no dentro): `Products.php:1147-1157` fija las 9 claves canónicas y `divergence` no es una de ellas (C6).

**Resultado esperado**: un producto con `S=''` y `W≠A` registra `cause='baseline_ausente'`; con `S` presente registra `divergencia_dueno` (las causas por pedido las completa `report()`, T5.2); la option nunca supera 200 entradas; registrar dos veces el mismo producto actualiza (no duplica).

**Dependencias**: `T1.8` (esqueleto); `T3.5` (call site del poll); `T2.1` (`owner()` lee la opción).

**Trazabilidad**: REQ-RECON-01; `design.md` §5.1; `spec.md` REQ-RECON-01; matriz R15.

**Verificación**: `T30.51` (compartido con `T3.5`) — el **poll** registra `baseline_ausente` (`S===''`) o `divergencia_dueno` (`S` presente); el **informe** (`T30.52`) resuelve `factura_fallida`/`reembolso`/`factura_pendiente` por pedido; el bound FIFO se respeta. **Prove-it-catches:** quitar la rama `S===''` → `T30.51` falla; no completar la causa por pedido en `report()` → `T30.52` falla.

**Riesgo**: la option crece sin control ⇒ bound 200 FIFO y non-autoload. `get_refunds()` es O(refunds) por pedido: se evalúa sólo al **resolver la causa en el informe**, con memoización por `order_id` (nunca por producto; el poll no busca pedidos).

**Estimación**: M (2 h).

---

### T5.2 — `Stock_Divergence::report()` + `clear()`

**Objetivo**: exponer un informe paginado de las divergencias registradas, sin GET por producto y sin falsos positivos.

**Descripción técnica**: el informe lee la option (lo que el poll ya midió) y no hace `GET /items` por producto (contra NFR-04; alternativa rechazada en `design.md` §5.5). Un producto que **coincide** no está en la option (sólo se registra al divergir), así que no puede aparecer. `clear($product_id)` lo saca al reparar/converger. REQ-RECON-01.

**Desarrollo técnico** — `includes/Sync/Stock_Divergence.php`, métodos nuevos:

```php
/**
 * Informe paginado. Lee la option (lo que el poll midió) y **completa** la
 * causa por pedido (`factura_fallida`/`reembolso`/`factura_pendiente`), que el
 * poll no puede resolver porque no tiene `WC_Order` (design.md §5.1).
 * Sin GET por producto: el pedido se busca con `wc_get_orders` acotado y
 * `get_refunds()` se memoiza por `order_id` (NFR-04).
 *
 * @return array{items:array<int,array{product_id:int,w:int,a:int,s:int|string,cause:string,at:int}>,total:int}
 */
public static function report(int $limit = 20, int $offset = 0): array
{
    $all = self::all();
    uasort($all, static fn ($x, $y) => $y['at'] <=> $x['at']);
    $total  = count($all);
    $offset = max(0, $offset);
    $slice  = array_slice($all, $offset, $limit, true);

    $owner = Inventory_Pusher::owner();
    $memo  = [];   // memoización por order_id: un `get_refunds()` por pedido.

    $items = [];
    foreach ($slice as $pid => $row) {
        $pid   = (int) $pid;
        $cause = (string) $row['cause'];   // baseline_ausente | divergencia_dueno
        $order = self::find_paid_order_for_product($pid);
        if ($order instanceof \WC_Order) {
            $oid = (int) $order->get_id();
            if (!array_key_exists($oid, $memo)) {
                $memo[$oid] = self::cause_for_order($order, $owner);
            }
            if ($memo[$oid] !== '') {
                $cause = $memo[$oid];
            }
        }
        $items[] = [
            'product_id' => $pid,
            'w'          => (int) $row['w'],
            'a'          => (int) $row['a'],
            's'          => $row['s'],
            'cause'      => $cause,
            'at'         => (int) $row['at'],
        ];
    }
    return ['items' => $items, 'total' => $total];
}

/**
 * Pedido pagado más reciente que contiene el producto (limit acotado). A
 * diferencia del helper de reparación (`T5.4`), NO exige que la factura esté
 * `open`: el informe necesita resolver también `factura_fallida`/`reembolso`.
 */
private static function find_paid_order_for_product(int $product_id): ?\WC_Order
{
    $ids = wc_get_orders([
        'status'  => ['processing', 'completed', 'on-hold'],
        'limit'   => 20,
        'return'  => 'ids',
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);
    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || !$order->is_paid()) {
            continue;
        }
        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() === $product_id
                || (int) $item->get_variation_id() === $product_id) {
                return $order;
            }
        }
    }
    return null;
}

/** Causa que depende del pedido ('' si ninguna aplica). Orden de design.md §5.1. */
private static function cause_for_order(\WC_Order $order, string $owner): string
{
    if ($owner === 'invoice') {
        $ledger = (string) $order->get_meta('_alegra_invoice_sync_state', true);
        if (in_array($ledger, ['failed_retriable', 'failed_permanent', 'blocked', 'payment_missing'], true)) {
            return 'factura_fallida';
        }
    }
    if ((string) $order->get_meta('_alegra_credit_note_id', true) !== ''
        || !empty($order->get_refunds())) {
        return 'reembolso';
    }
    if ($owner === 'invoice'
        && (string) $order->get_meta('_alegra_invoice_status', true) !== 'open') {
        return 'factura_pendiente';
    }
    return '';
}
```

> **Firma canónica.** `report(int $limit = 20, int $offset = 0)` es la firma del esqueleto `T1.8`
> (`fase-1:1221`) y de `design.md` §9 (`:1170`); reemplaza a `report($page, $per_page)` para no
> divergir del contrato. La paginación por `$limit`/`$offset` es la que consume la pantalla del informe.

**Resultado esperado**: `report()` devuelve `items` + `total`; un catálogo sin divergencias devuelve `items=[]` y `total=0` sin advertencia; un producto que coincide no aparece; cada ítem trae la **causa real** (`factura_fallida`/`reembolso`/`factura_pendiente` cuando aplica; si no, la del poll).

**Dependencias**: `T5.1`. Consumido por la fila del dashboard (`T6.7`) y la pantalla del informe.

**Trazabilidad**: REQ-RECON-01; `design.md` §5.1/§5.5/§9; `spec.md` REQ-RECON-01 (escenario negativo).

**Verificación**: `T30.52` — sin divergencia ⇒ `items=[]`; con N divergencias ⇒ pagina (`limit`/`offset`) y `total` correcto; no se listan productos que coinciden; la causa por pedido se completa (factura fallida / reembolso / factura pendiente) con **una** refund-query por `order_id`. **Prove-it-catches:** listar todos los productos vinculados → `T30.52` falla (falsos positivos); no completar la causa → `T30.52` falla.

**Riesgo**: bajo. Sólo lectura de una option bounded.

**Estimación**: S (1 h).

---

### T5.3 — Des-invertir el gate de "Ventas sin factura" + semántica `draft`/`void`/vacío + `on-hold`

**Objetivo**: que el reporte "Ventas sin factura" se muestre **siempre** (no oculto en modo `push_orders=true`) y detecte facturas ausentes/vacías/`draft`/`void`, incluyendo pedidos `on-hold`.

**Descripción técnica**: `get_unjournaled_sales()` (`Admin_Dashboard.php:862-887`) hoy sólo busca `_alegra_invoice_id NOT EXISTS` (`:870`) y sólo `processing|completed` (`:865`); `render_dashboard()` oculta la fila con `push_orders_enabled=true` (`:903-905`). El diseño (`design.md` §5.2) elimina el gate y amplía la semántica. La fila se renderiza en `templates/admin-dashboard.php:197-212`. **Cambio intencional** documentado en `CHANGELOG.md` (`T7.3`). REQ-RECON-02.

**Desarrollo técnico** — `admin/Admin/Admin_Dashboard.php`:

ANTES (`:862-887`):
```php
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
```

DESPUÉS:
```php
    public function get_unjournaled_sales(int $limit = 20): array
    {
        // REQ-RECON-02: corre SIEMPRE; incluye on-hold (S2) y detecta
        // ausente / vacío / draft / void (no sólo NOT EXISTS).
        $ids = wc_get_orders([
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => $limit,
            'return'     => 'ids',
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_alegra_invoice_id', 'compare' => 'NOT EXISTS'],
                ['key' => '_alegra_invoice_id', 'value' => '', 'compare' => '='],
                ['key' => '_alegra_invoice_status', 'value' => ['draft', 'void'], 'compare' => 'IN'],
            ],
        ]);
```

> **Oracle#4 — validez del `meta_query` OR.** El `relation => OR` con `NOT EXISTS` + `=` + `IN` es
> **WP válido**: `NOT EXISTS` ignora `value`, `=` con `''` matchea el meta guardado vacío y `IN` acepta
> el array de estados. Requiere que el stub del harness soporte `IN` (lo cubre `T1.3`/B6); correr el test
> con los 4 casos (ausente, `''`, `draft`, `void`).

ANTES (`:901-905`, en `render_dashboard()`):
```php
        // REQ-INV-07: honest visibility. With owner=adjustment the sales ARE
        // reflected via adjustments; only the manual-invoice mode can diverge.
        $divergence = (!get_option('alegra_connector_push_orders_enabled', false))
            ? $this->get_unjournaled_sales()
            : ['count' => 0, 'orders' => []];
```

DESPUÉS:
```php
        // REQ-RECON-02: el reporte se muestra SIEMPRE (cambio intencional,
        // CHANGELOG). Con owner=adjustment las ventas se reflejan por ajustes,
        // pero el reporte ya no se oculta justo en el modo del comerciante.
        $divergence = $this->get_unjournaled_sales();
```

**Template** — `templates/admin-dashboard.php:197-212`: la fila ya existe; `T6.7` le agrega el link a la cola. No requiere cambios de gate.

**Resultado esperado**: con `push_orders_enabled=true`, la fila "Ventas sin factura" se ve; un pedido con `_alegra_invoice_id=''` o `_alegra_invoice_status='draft'`/`'void'` cuenta como pendiente; un `on-hold` sin factura cuenta.

**Dependencias**: ninguna dentro de la fase (independiente de `T5.1`/`T5.2`). Consumido por `T6.7`.

**Trazabilidad**: REQ-RECON-02; `design.md` §5.2/§7.4; `spec.md` REQ-RECON-02; matriz R19; hallazgos C1/C8/I3/S2.

**Verificación**: `T30.53` — reporte visible con `push_orders_enabled=true`; detecta vacío/`draft`/`void`; incluye `on-hold`. **Prove-it-catches:** restaurar el gate `:903-905` → `T30.53` falla (se oculta en modo facturación).

**Riesgo**: la fila siempre visible puede sorprender a quien tenía `push_orders=true`; es **intencional** y se documenta en `CHANGELOG.md` (`T7.3`). La query ampliada usa `meta_query` OR acotado a `limit`.

**Estimación**: S (1,5 h).

---

### T5.4 — `ajax_repair_stock_divergence` (un solo mecanismo) — BLOQUEADO(G1)

**Objetivo**: reparar explícitamente un producto divergente emitiendo **o** la factura faltante **o** un ajuste correctivo, **nunca ambos**, bajo `run_explicit`, con nonce/capacidad, y dejando rastro.

**Descripción técnica**: no existe hoy ninguna reparación (`grep` = 0). El patrón AJAX es el de `ajax_open_invoice`/`ajax_record_payment`/`ajax_sync_single`/`ajax_bulk_sync`: wrapper `Write_Gate::run_explicit(fn () => $this->_impl())` (`:2842`, `:2899`, `:3168`, `:3307`). El mecanismo `invoice` usa `Controller::sync_entity('order', $id, 'complete')` (`:321`, `:375`, `:386`); el `adjustment` usa el `Inventory_Pusher` (`:249-251`), reusando `push_delta()` (lock/pending/idempotencia/`reference`/`unitCost`/`set_synced`). Nunca ambos (REQ-INV-08). **BLOQUEADO(Fase 0.2 / G1):** Rama A (`draft` no mueve) ⇒ reparar por factura exige abrirla; Rama B (`draft` mueve) ⇒ re-baselinar en vez de re-emitir. REQ-RECON-03; NFR-06.

> **Contrato ÚNICO de reparación (B4/Oracle#6/#7 — lo comparten `T5.4` y `T7.4`).** El delta SIEMPRE
> sale de Alegra (`A`) o del informe (`qty_doble`), **nunca** de `synced`. `mechanism=adjustment` tiene
> dos modos:
> - `repair_mode=divergence` (default): reconcilia Alegra con WC vía
>   `Inventory_Pusher::repair_to_wc()` (delta = `W − A` real; reusa `push_delta()`).
> - `repair_mode=legacy_compensation` (T7.4): secuencia **(a)** dueño→`invoice` vía
>   `sanitize_stock_owner()` + coerción (C8); **(b)** UN `in qty_doble` vía
>   `Inventory_Pusher::push_compensation()` (lock + pending + idempotencia + `unitCost` + `warehouse` +
>   `reference`); **(c)** re-baseline `S = availableQuantity`; **(d)** nota + log.
>
> Ambos reusan `build_adjustment_payload()` y `mark_adjusted()`; **nunca** un POST crudo.

**Desarrollo técnico** — `admin/Admin/Admin_Dashboard.php`, AJAX nuevo (registrar en el constructor junto a `:46-66`):

```php
public function ajax_repair_stock_divergence(): void
{
    \Alegra\Connector\Write_Gate::run_explicit(fn () => $this->ajax_repair_stock_divergence_impl());
}

private function ajax_repair_stock_divergence_impl(): void
{
    check_ajax_referer('alegra_connector_nonce');            // NFR-06
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
    }

    $product_id  = (int) ($_POST['product_id'] ?? 0);
    $mechanism   = sanitize_text_field($_POST['mechanism'] ?? '');
    $repair_mode = sanitize_key($_POST['repair_mode'] ?? 'divergence');
    if ($product_id <= 0 || !in_array($mechanism, ['invoice', 'adjustment'], true)
        || !in_array($repair_mode, ['divergence', 'legacy_compensation'], true)) {
        wp_send_json_error(['message' => __('Parámetros inválidos.', 'alegra-connector')]);
    }

    $product = wc_get_product($product_id);
    if (!$product instanceof \WC_Product) {
        wp_send_json_error(['message' => __('Producto no encontrado.', 'alegra-connector')]);
    }

    $pusher = new \Alegra\Connector\Sync\Inventory_Pusher($this->api, $this->logger);
    $orders = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);

    if ($mechanism === 'invoice') {
        // REQ-RECON-03: la elección respeta el dueño vigente. Con dueño
        // `adjustment`, emitir una factura abierta duplicaría el movimiento (la
        // factura mueve y el ajuste también) ⇒ se rechaza. La reparación de
        // corrupción heredada va por `repair_mode=legacy_compensation`.
        if (\Alegra\Connector\Sync\Inventory_Pusher::owner() !== 'invoice') {
            wp_send_json_error(['message' => __('El dueño del stock es el ajuste; no se emite una factura.', 'alegra-connector')]);
        }
        // Reparar por factura: exige un pedido pagado sin factura `open`.
        $order = $orders->find_paid_order_without_open_invoice($product_id);
        if (!$order instanceof \WC_Order) {
            wp_send_json_error(['message' => __('No hay un pedido pagado sin factura para este producto.', 'alegra-connector')]);
        }
        $result = (new \Alegra\Connector\Sync\Controller($this->api, $this->logger))
            ->sync_entity('order', (int) $order->get_id(), 'complete');

        if (is_wp_error($result) || API\Client::write_was_blocked($result)) {
            wp_send_json_error(['message' => self::blocked_message((array) $result)]);
        }
        // G1 Rama A: si la factura quedó draft, abrirla (mueve stock recién ahí).
        if (\Alegra\Connector\Sync\Inventory_Pusher::owner() === 'invoice'
            && (string) $order->get_meta('_alegra_invoice_status', true) === 'draft') {
            $orders->ensure_invoice_open((string) $order->get_meta('_alegra_invoice_id', true));
        }
        // Rastro (T5.5).
        $order->add_order_note(__('[Alegra] Reparación de divergencia: factura emitida (un solo mecanismo).', 'alegra-connector'));
        $this->logger->info('Stock divergence repaired', ['product_id' => $product_id, 'mechanism' => 'invoice', 'order_id' => (int) $order->get_id()]);
        \Alegra\Connector\Sync\Stock_Divergence::clear($product_id);
        wp_send_json_success(['message' => __('Divergencia reparada emitiendo la factura.', 'alegra-connector'), 'mechanism' => 'invoice']);
    }

    // mechanism === 'adjustment'.
    if ($repair_mode === 'legacy_compensation') {
        // T7.4 (a)→(d): compensación explícita del doble decremento heredado.
        $qty_doble = (int) ($_POST['qty_doble'] ?? 0);   // del informe (Σ ajustes)
        if ($qty_doble <= 0) {
            wp_send_json_error(['message' => __('Falta qty_doble del informe.', 'alegra-connector')]);
        }
        // (a) SECUENCIA CANÓNICA compartida con T7.4 (idéntica). Dueño → `invoice`
        //     POR EL SANITIZADOR (T2.8/C8): allowlist + epoch + reset de cachés.
        //     `sanitize_stock_owner()` sólo DEVUELVE el valor ⇒ hay que persistirlo.
        //     Luego se coercen las dos opciones que lee `owner()` por sus propios
        //     sanitizers (T2.8/Oracle#9) para no quedar en el estado B3.
        update_option(
            'alegra_connector_stock_owner',
            \Alegra\Connector\Admin\Admin_Dashboard::sanitize_stock_owner('invoice')
        );
        update_option(
            'alegra_connector_push_orders_enabled',
            \Alegra\Connector\Admin\Admin_Dashboard::sanitize_push_orders_enabled(true)
        );
        update_option(
            'alegra_connector_open_invoice_on_paid',
            \Alegra\Connector\Admin\Admin_Dashboard::sanitize_open_invoice_on_paid(true)
        );
        // (b) UN `in qty_doble` (lock + idempotencia + unitCost + warehouse + reference).
        $res = $pusher->push_compensation($product, $qty_doble);
        if (empty($res['pushed'])) {
            wp_send_json_error(['message' => self::blocked_message((array) $res)]);
        }
        // (c) `push_compensation()` ya re-baselinó `S = availableQuantity`.
        $product->add_meta_data('_alegra_stock_divergence_note', gmdate('Y-m-d H:i:s'), true);
        $product->save();
        $this->logger->info('Stock divergence repaired', [
            'product_id' => $product_id, 'mechanism' => 'adjustment',
            'repair_mode' => 'legacy_compensation', 'delta' => $qty_doble,
        ]);
        \Alegra\Connector\Sync\Stock_Divergence::clear($product_id);
        wp_send_json_success([
            'message' => __('Corrupción heredada reparada (un solo mecanismo).', 'alegra-connector'),
            'mechanism' => 'adjustment', 'repair_mode' => 'legacy_compensation',
        ]);
    }

    // repair_mode === 'divergence': reconciliar Alegra con WC (Oracle#6).
    if (\Alegra\Connector\Sync\Inventory_Pusher::owner() !== 'adjustment') {
        wp_send_json_error(['message' => __('El dueño del stock es la factura; no se emite un ajuste.', 'alegra-connector')]);
    }
    if ((string) get_post_meta($product_id, '_alegra_item_id', true) === '') {
        wp_send_json_error(['message' => __('El producto no está vinculado a Alegra.', 'alegra-connector')]);
    }
    // Delta contra A real, reusando push_delta() (lock/pending/idempotencia/
    // reference/unitCost/set_synced). Nunca `synced` ni un POST crudo.
    $res = $pusher->repair_to_wc($product);
    if (empty($res['pushed']) && ($res['reason'] ?? '') !== 'in_sync') {
        wp_send_json_error(['message' => self::blocked_message((array) $res)]);
    }
    $product->add_meta_data('_alegra_stock_divergence_note', gmdate('Y-m-d H:i:s'), true);
    $product->save();
    $this->logger->info('Stock divergence repaired', [
        'product_id' => $product_id, 'mechanism' => 'adjustment',
        'repair_mode' => 'divergence', 'delta' => (int) $res['delta'],
    ]);
    \Alegra\Connector\Sync\Stock_Divergence::clear($product_id);
    wp_send_json_success([
        'message' => __('Divergencia reparada emitiendo un ajuste.', 'alegra-connector'),
        'mechanism' => 'adjustment', 'repair_mode' => 'divergence',
    ]);
}
```

> **Nunca ambos.** La rama `invoice` **no** emite ajuste y la rama `adjustment` **no** emite factura; el `mechanism` es un `enum` de un solo valor por request. REQ-INV-08.
>
> **Dueño (REQ-RECON-03).** Cada rama exige su dueño: `invoice` sólo con `owner()==='invoice'`; `adjustment` (modo `divergence`) sólo con `owner()==='adjustment'`. `legacy_compensation` es **owner-independiente** (la secuencia (a) ya dejó `owner=invoice`) y por eso corre **antes** del guard `owner !== 'adjustment'`. El AJAX no lee `alegra_connector_stock_owner` del request: `effective_stock_owner_from_request()` (T2.8) cae a la opción recién persistida por el paso (a).
>
> **G1.** Rama A: `ensure_invoice_open()` tras emitir (arriba). Rama B: no llamar `ensure_invoice_open()` y re-baselinar `Inventory_Pusher::set_synced($product_id, (int) $product->get_stock_quantity())` en su lugar.

**Métodos nuevos en `Inventory_Pusher`** (reusan las guardas reales; `build_adjustment_payload()` ya recibe `$reference` por `T3.4`):

```php
/**
 * Oracle#6: reconcilia Alegra con WC en modo `adjustment`. El delta se calcula
 * contra `A` real (no contra `synced`), reusando `push_delta()` (lock + pending
 * + idempotencia + `reference` + `unitCost` + `set_synced`). NUNCA un POST crudo.
 *
 * @return array{pushed:bool, delta:int, reason:string}
 */
public function repair_to_wc(\WC_Product $product): array
{
    $id = (int) $product->get_id();
    $alegra_item = (string) get_post_meta($id, '_alegra_item_id', true);
    if ($alegra_item === '') {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'not_linked'];
    }
    $a = $this->fetch_alegra_available_quantity($alegra_item);
    if ($a === null) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_unverified'];
    }
    self::set_synced($id, $a);                       // baseline = A real
    return $this->push_delta($product, (int) $product->get_stock_quantity());
}

/**
 * B4/T7.4: compensación explícita del doble decremento heredado. Emite UN `in`
 * por `$qty` con lock + pending + idempotencia + `unitCost` + `warehouse` +
 * `reference`, y re-baselina `synced` con el `availableQuantity` corregido (o el
 * stock de WC si no se puede leer). NO chequea `owner()`: es la reparación
 * explícita bajo `run_explicit`.
 *
 * @return array{pushed:bool, delta:int, reason:string}
 */
public function push_compensation(\WC_Product $product, int $qty): array
{
    $id = (int) $product->get_id();
    if ($qty <= 0) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'no_delta'];
    }
    $alegra_item = (string) get_post_meta($id, '_alegra_item_id', true);
    if ($alegra_item === '' || $this->api === null
        || !get_option('alegra_connector_push_inventory_enabled', true)) {
        return ['pushed' => false, 'delta' => $qty, 'reason' => 'not_linked'];
    }

    $lock_key = 'alegra_inventory_push_' . $id;
    $token = Controller::acquire_lock($lock_key, 30);
    if ($token === false) {
        return ['pushed' => false, 'delta' => $qty, 'reason' => 'locked'];
    }
    $pending_prev = (string) self::pending($id);
    $reference    = 'wc-repair-' . $id . '-' . $qty;
    try {
        self::set_pending($id, (int) $product->get_stock_quantity());
        if ($pending_prev !== '' && $this->adjustment_already_exists($alegra_item, $reference, $qty)) {
            self::set_synced($id, (int) $product->get_stock_quantity());
            self::clear_pending($id);
            self::mark_adjusted($id);
            return ['pushed' => true, 'delta' => $qty, 'reason' => 'already_applied'];
        }
        $res = $this->api->create_inventory_adjustment(
            $this->build_adjustment_payload($alegra_item, $qty, $product, $reference)   // qty>0 ⇒ 'in'
        );
        if (API\Client::write_was_blocked($res)) {
            return ['pushed' => false, 'delta' => $qty, 'reason' => (string) ($res['reason'] ?? 'blocked')];
        }
        if (is_wp_error($res)) {
            if ($this->logger) {
                $this->logger->error('Legacy stock compensation failed', [
                    'product_id' => $id, 'alegra_item' => $alegra_item,
                    'delta' => $qty, 'error' => $res->get_error_message(),
                ]);
            }
            return ['pushed' => false, 'delta' => $qty, 'reason' => 'api_error'];
        }
        // (c) Re-baseline con el availableQuantity corregido; fallback a WC.
        $after = $this->fetch_alegra_available_quantity($alegra_item);
        self::set_synced($id, $after ?? (int) $product->get_stock_quantity());
        self::clear_pending($id);
        self::mark_adjusted($id);
        return ['pushed' => true, 'delta' => $qty, 'reason' => 'ok'];
    } finally {
        Controller::release_lock($lock_key, $token);
    }
}
```

**Método nuevo en `Orders`** (coverage gap #6: define el helper implícito `find_paid_order_without_open_invoice()`; vive en `Orders` porque reusa `find_open_invoice_for_order()` — `private` de esa clase —, que a su vez chequea `_alegra_invoice_id`/`_alegra_invoice_status` y cae a `find_existing_invoice()` del lado Alegra):

```php
/**
 * T5.4: pedido pagado que contiene el producto y NO tiene una factura `open`.
 * Coste acotado: `wc_get_orders(limit=20)`; el chequeo Alegra-side
 * (`find_existing_invoice`, vía `find_open_invoice_for_order()`) sólo corre para
 * pedidos que contienen el producto y no tienen una factura vinculada `open`.
 *
 * @return \WC_Order|null
 */
public function find_paid_order_without_open_invoice(int $product_id): ?\WC_Order
{
    if ($this->api === null) {
        return null;
    }
    $ids = wc_get_orders([
        'status'  => ['processing', 'completed', 'on-hold'],
        'limit'   => 20,
        'return'  => 'ids',
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);
    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || !$order->is_paid()) {
            continue;
        }
        // ¿Contiene el producto? Se chequea ANTES del GET a Alegra (acota el coste).
        $has_product = false;
        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() === $product_id
                || (int) $item->get_variation_id() === $product_id) {
                $has_product = true;
                break;
            }
        }
        if (!$has_product) {
            continue;
        }
        // Sin factura `open`: ni vinculada (`_alegra_invoice_id`) ni en Alegra
        // (`find_existing_invoice`, vía `find_open_invoice_for_order()`).
        if ($this->find_open_invoice_for_order($order) !== null) {
            continue;
        }
        return $order;
    }
    return null;
}
```

> **Por qué en `Orders` y no en `Admin_Dashboard`.** `find_existing_invoice()` y `find_open_invoice_for_order()` son
> `private` de `Orders` (`Orders.php:309`/`:437`); ponerlo en `Admin_Dashboard` obligaría a duplicar la lógica o a
> exponer otro método. `T5.4` ya construye un `Orders` para `ensure_invoice_open()`, así que reusa la misma instancia.
> **Cross-ref:** `design.md` §9 no lista este método; conviene agregarlo a la lista de APIs nuevas (no es de esta fase).

**Resultado esperado**: `mechanism=invoice` con pedido pagado ⇒ una factura (una sola vez); `mechanism=adjustment` en modo `adjustment` ⇒ un ajuste por el delta contra `A`; `mechanism=adjustment&repair_mode=legacy_compensation` ⇒ dueño→`invoice` + un `in qty_doble` + re-baseline + rastro; sin nonce/capacidad ⇒ error y **cero** llamadas a la API; la divergencia se limpia del informe.

**Dependencias**: `T5.1`/`T5.2`; `T2.1` (`owner()`); `T4.2` (ledger, para la causa); `T1.5` (opciones). Gate **G1**.

**Trazabilidad**: REQ-RECON-03, REQ-OWN-01, NFR-06; `design.md` §5.3/§5.4; `spec.md` REQ-RECON-03; matrices R12/R17.

**Verificación**: `T30.54` (nunca ambos + nota/log), `T30.55` (reparación por factura + **respeta el dueño**: con `owner=adjustment` la rama `invoice` rechaza sin llamar a la API), `T30.56` (nonce/cap). **Prove-it-catches:** emitir ambos mecanismos → `T30.54` falla; quitar `check_ajax_referer` → `T30.56` falla; quitar el guard de dueño de la rama `invoice` → `T30.55` falla. `T7.4`/`T30.74` assertea el orden (a)→(b)→(c) con `repair_mode=legacy_compensation` y **un solo** mecanismo; `repair_mode=divergence` usa `A` real (no `synced`) y deja `set_synced` (Oracle#6).

**Riesgo**: reparar por factura un pedido que ya tiene `_alegra_invoice_id` ⇒ la guarda `T4.9` lo evita. Re-baselinar a un valor mayor que WC hará que el poll quiera empujar un `out` (advertencia en `T7.4`). Sin cron real, la reparación es manual por diseño.

**Estimación**: L (2,5 h).

---

### T5.5 — Rastro de la reparación (nota + log con delta y mecanismo)

**Objetivo**: que toda reparación deje nota en el pedido/producto y una línea de log con el delta y el mecanismo.

**Descripción técnica**: `design.md` §5.3 exige "Nota en el pedido/producto + log con el delta y el mecanismo". En `T5.4` ya se llama `$order->add_order_note(...)` (rama `invoice`) y `$product->add_meta_data('_alegra_stock_divergence_note', …)` + `$this->logger->info('Stock divergence repaired', …)` (rama `adjustment`). Esta micro-tarea cierra el contrato: el log siempre incluye `mechanism` y, en `adjustment`, el `delta`. REQ-RECON-03.

**Desarrollo técnico** — completar el log de la rama `invoice` con el delta (opcional) y garantizar la nota en ambos caminos:

```php
// Rama invoice (T5.4): incluir el delta informado por el informe (W - A).
$this->logger->info('Stock divergence repaired', [
    'product_id' => $product_id,
    'mechanism'  => 'invoice',
    'order_id'   => (int) $order->get_id(),
    'delta'      => $delta_reported,   // el que mostró el informe
]);
```

En la rama `adjustment` el log ya lleva `delta`; la nota queda como meta del producto (no hay pedido asociado). No se agrega email (fuera de alcance, `design.md` §4.7).

**Resultado esperado**: tras reparar, el pedido (o el producto) tiene la nota y el log tiene `mechanism` + `delta`.

**Dependencias**: `T5.4` (cubierto por su implementación).

**Trazabilidad**: REQ-RECON-03; `design.md` §5.3; `spec.md` REQ-RECON-03 (escenario "la reparación deja rastro").

**Verificación**: `T30.54` — el harness captura el log y asserta `mechanism`/`delta` y la nota. **Prove-it-catches:** quitar el `add_order_note` → `T30.54` falla.

**Riesgo**: nulo (log + nota).

**Estimación**: S (0,5 h).

---

## DoD Fase 5 (checklist de cierre)

- [ ] `T5.1`: `Stock_Divergence::record()` bounded a 200 FIFO, non-autoload; `divergence_cause(string $owner, int|string $s)` **2 args** (sólo `baseline_ausente`/`divergencia_dueno`); las causas `factura_fallida`/`reembolso`/`factura_pendiente` las completa `report()` (`T5.2`); consumido por `T3.5` (contador `$divergence` **fuera** de las 9 claves de `$result`); `get_refunds()` memoizado por `order_id` (no por producto).
- [ ] `T5.2`: `report(int $limit = 20, int $offset = 0)` (firma de `T1.8`/`design.md` §9) paginado sin GET por producto; completa la causa por pedido; `clear()`; sin falsos positivos.
- [ ] `T5.3`: `get_unjournaled_sales()` corre siempre; semántica ausente/vacío/`draft`/`void`; incluye `on-hold` (`:865`); gate `:903-905` eliminado; `CHANGELOG.md` documenta el cambio intencional (`T7.3`).
- [ ] `T5.4`: `ajax_repair_stock_divergence` bajo `run_explicit` (`:156`), nonce + `manage_woocommerce`; `mechanism ∈ {invoice,adjustment}` + `repair_mode ∈ {divergence,legacy_compensation}`; **nunca ambos**; **respeta el dueño** (`invoice` sólo con `owner=invoice`, `adjustment` sólo con `owner=adjustment`, `legacy_compensation` owner-independiente); contrato único con `T7.4` (delta de `A`/`qty_doble`, `push_delta`/`push_compensation` con lock+`reference`+`unitCost`+`set_synced`); paso (a) = secuencia canónica compartida con `T7.4` (`sanitize_stock_owner()` persistido + coerción de las dos opciones); `Orders::find_paid_order_without_open_invoice()` definido; G1 declarado (Rama A abre, Rama B re-baselina).
- [ ] `T5.5`: nota + log con `mechanism`/`delta` en ambas ramas.
- [ ] Los **6 IDs canónicos** (`T30.51`–`T30.56`) verdes con prove-it-catches.
- [ ] **Cross-ref G1** (`T0.2`): la rama de `draft` define si `T5.4` abre la factura o re-baselina.
- [ ] **Cross-ref `T6.7`**: la fila "Ventas sin factura" (`templates/admin-dashboard.php:197-212`) enlaza a la cola.
