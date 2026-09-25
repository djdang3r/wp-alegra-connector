# Fase 2 — `Inventory_Writer` (D3) + convergencia W1/W2 + D5

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Fase | 2 de 10 — el **escritor único** de stock WC (`_manage_stock`/`_stock`/`_stock_status`) |
| Tareas | `T2.1` · `T2.2` · `T2.3` · `T2.4` · `T2.5` · `T2.6` · `T2.7` · `T2.8` |
| Depende de | **Fase 1 completa** (`T1.1` stub `wc_update_product_stock`, `T1.2` derivación de `save()`, `T1.7` opciones, `T1.11` sección `T29`). **T2.1** además `BLOQUEADO(Fase 0.7 / G7)` **sólo para la rama del fallback** |
| DoD de la fase | REQ-INV-02, REQ-INV-04, REQ-INV-05 verdes; `grep` de setters devuelve matches **sólo** en `Inventory_Writer.php` (`T2.7`); `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes |
| Documentos base | `proposal.md` §4 Tema B · `spec.md` REQ-INV-02/04/05 · `design.md` §4 (D3) + §6 (D5) · `tasks.md` Fase 2 + §10 decisiones 3 |
| Versión objetivo | 2.6.0 |
| Decisión de la fase | **D3** (`Inventory_Writer` como único escritor) + **D5** (`wc_update_product_stock`, no forzar `_stock_status`) |

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T29.2{n}`
> (`T29.21`…`T29.28`) según la convención `T29.{fase}{n}`. Los IDs de **tarea** (`T2.1`…`T2.8`) no
> cambian. La sección `// === sync-reliability (2.6.0) ===` la crea `T1.11`.

> **Regla de oro de esta fase (prove-it-catches, obligatoria).** El test central de D5 es
> `T29.21`: con `wc_update_product_stock()` presente, la escritura **dispara los hooks de stock** y
> **deriva** `_stock_status` (con `backorders=yes` + qty 0 ⇒ `onbackorder`). **Prove-it-catches:**
> revertir `apply_stock()` a `set_stock_quantity()+save()` ⇒ `T29.21` rojo (no dispara hooks y el
> estado forzado pisa el derivado). Sin ese revert→rojo, el test no se acepta.

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

Al leer el código real antes de escribir estas micro-tareas aparecen **7 hallazgos** que cambian el
plan de test y el código. Se listan primero porque varias tareas dependen de ellos.

| # | Claim (design/spec/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `apply_stock()` usa `wc_update_product_stock($p,$qty,'set',false)` (design §4.2) | La firma real del core es `wc_update_product_stock( $product, $qty = null, $operation = 'set', $updating = false )`. El 4.º argumento (`$updating`) existe desde WC 6.2; en WC 3.0–6.1 **PHP ignora el argumento extra** de una función de usuario (no hay TypeError). En WC < 3.0 la función **no existe** ⇒ `function_exists` + fallback. | `T2.1` usa el 4.º arg `false`; el fallback cubre < 3.0. No se cambia la firma. |
| C2 | Enum de retorno del writer (design §4.1): `updated\|skipped_no_qty\|skipped_service\|skipped_not_manageable\|skipped_source\|skipped_preserve\|skipped_parent\|clamped_negative\|error` | `docs/INVENTORY_DESIGN.md:750-752` propone otro set (`skipped_opt_out`, `skipped_warehouse_missing`). | **Se adopta el enum del design §4.1** (canónico de este cambio). `docs/sdd/inventory/` está DESIGNED-ONLY y no se reabre. |
| C3 | "los chequeos de `:2006-2008` se mueven al writer" (design §4.3) | `:2006-2008` es la condición `inventory_source !== 'woocommerce' && !in_array('inventory',$preserve)` que gatea la llamada. `apply_inventory_to_product()` tiene **un único caller** en `:2008` (grep: 1 match). | `T2.3` elimina la condición del call site y la mueve al writer vía `$opts['source']`/`$opts['preserve']`. |
| C4 | W1 es el único lugar con `set_manage_stock` | `set_manage_stock` aparece en `Products.php:2059` (variable padre), `:2067` (servicio) y `:2072` (inventariable). Los tres **deben mudarse** al writer para cumplir el grep de `T2.7`. | `T2.3` borra los tres; el writer los replica. |
| C5 | "El forzado `$qty > 0 ? instock : outofstock` (`:1259`, `:2099`)" | **Confirmado.** `:1259` (W2) y `:2099` (W1). El docblock `:2031-2034` afirma que WC no deriva `_stock_status`; es **FALSO** desde WC 3.0 (`validate_props()`). | `T2.1` (escritura), `T2.4` (borra `:1259`), `T2.6` (docblock). |
| C6 | El dry-run ya bloqueaba la escritura de stock en WC | **Falso.** HEAD escribe `set_stock_quantity()` en W1 (`:2097`) **sin** mirar `alegra_connector_dry_run`; el dry-run del `Client` sólo bloquea POST/PUT a Alegra, no la escritura local de WC. | `T2.1`/`T2.2` agregan la compuerta `dry_run` al writer. **Cambio intencional** (nota de release en `T9.5`). |
| C7 | `resolve_preserve_fields()` accesible desde W2 | Es `private` en `Products.php:1937-1941`. W2 es un método de la **misma** clase, así que lo puede llamar directo. | `T2.4` usa `$this->resolve_preserve_fields()`. Sin cambio de visibilidad. |

**Confirmaciones (no requieren corrección).**

- `Products.php:1142` es `public function sync_inventory_from_alegra(int $run_id = 0): array` — **confirmado** (la firma con `float $deadline = 0.0` la agrega `T7.1`, no esta fase).
- `Products.php:1144` `$result = ['updated'=>0,'errors'=>0,'pages'=>0,'locked'=>false,'skipped'=>false];` — confirmado.
- `Products.php:1238` lee `availableQuantity` total; `:1239-1248` clamp negativo; `:1249-1251` skip `!get_manage_stock()`; `:1253-1260` bloque de escritura W2 — confirmados.
- `Products.php:2031-2034` docblock; `:2052` firma `apply_inventory_to_product(\WC_Product $product, array $item): void`; `:2097`/`:2099` setters — confirmados.
- `Products.php:1937-1941` `resolve_preserve_fields()`; `:2006-2008` condición; `:2008` caller — confirmados.
- `Products.php:1118-1137` `apply_warehouse()`; `:1104-1110` `resolve_warehouse_id()` — confirmados.
- `alegra-connector.php:453` `'alegra_connector_dry_run' => false` — confirmado.
- Grep de `set_stock_quantity|set_stock_status|set_manage_stock` en producción: **sólo** `Products.php` (`:1255,1259,2059,2067,2072,2097,2099`) y el stub `scripts/lib/wp-stubs.php:1279-1281`. Confirmado.

---

## Contrato canónico (lo define esta fase; Fase 3/7 lo consumen, nunca lo redefinen)

### K-W — Firma y compuertas de `Inventory_Writer`

```php
namespace Alegra\Connector\Sync;

final class Inventory_Writer
{
    public function __construct(?\Alegra\Connector\Logger\Logger $logger = null);
    public function apply(\WC_Product $product, array $item, array $opts = []): string;
    private function resolve_quantity(array $item, string $warehouse_id): array; // [int|null, reason]
    private function apply_stock(\WC_Product $p, int $qty): void;
}
```

`$opts` (con defaults por fusión `$opts + [...]`, nunca `??` por clave suelta):

| Clave | Tipo | Default | Significado |
|---|---|---|---|
| `source` | `'alegra'\|'woocommerce'` | `'alegra'` | `'woocommerce'` ⇒ `skipped_source` (no escribe) |
| `preserve` | `bool` | `false` | `'inventory'` en `preserve_fields` ⇒ `skipped_preserve` |
| `manage_stock` | `'enable'\|'respect'` | `'respect'` | `'enable'` habilita `manage_stock`; `'respect'` no lo toca |
| `dry_run` | `bool` | `false` | reporta sin escribir |
| `warehouse_id` | `string` | `''` | `''` ⇒ lee el total (warehouse-aware diferido, `T4.4`) |

Retorno: `updated` · `clamped_negative` · `skipped_no_qty` · `skipped_service` ·
`skipped_not_manageable` · `skipped_source` · `skipped_preserve` · `skipped_parent` · `dry_run` ·
`error`.

**Regla dura:** `set_manage_stock()`, `set_stock_quantity()`, `set_stock_status()` y
`wc_update_product_stock()` **sólo** se llaman en este archivo. Grep-able (`T2.7`).

---

### T2.1 — Crear `Inventory_Writer` con el escritor único (`apply_stock`) · `BLOQUEADO(Fase 0.7 / G7)` (sólo fallback)

**Objetivo**: que exista la clase `Alegra\Connector\Sync\Inventory_Writer` con el **único** punto de
escritura de stock, usando `wc_update_product_stock()` (API recomendada de WC) en vez de forzar
`_stock_status`.

**Descripción técnica**: hoy hay **dos** escritores con compuertas divergentes: W1
(`apply_inventory_to_product()`, `Products.php:2052-2100`) y W2 (bloque inline del poll,
`Products.php:1253-1260`). W1 respeta `preserve_fields` (`:2006-2008`), W2 **no**; ambos fuerzan
`$qty > 0 ? 'instock' : 'outofstock'` (`:1259`, `:2099`) e ignoran `_backorders`. El docblock
`:2031-2034` afirma que WC no deriva `_stock_status`; **es falso** desde WC 3.0: `WC_Product::save()`
llama `validate_props()` (`abstract-wc-product.php:1547`), que deriva `instock`/`onbackorder`/
`outofstock` desde cantidad + backorders + umbral (`:1517-1538`). Esta tarea cubre D3 §4.1 y D5 §6.1;
implementa `docs/sdd/inventory/REQ-DIV-1` sin reabrir sus decisiones.

**Desarrollo técnico**:

Archivo **nuevo**: `includes/Sync/Inventory_Writer.php`. Namespace `Alegra\Connector\Sync` (mismo que
`Products`, así W1/W2 lo instancian sin `use`). Se carga por PSR-4 (`smoke-load.php` de `T9.3` lo
asserta).

```php
<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Único escritor de `_manage_stock` / `_stock` / `_stock_status` del plugin
 * (D3 / docs/sdd/inventory/REQ-DIV-1). W1 (import) y W2 (poll) delegan acá.
 *
 * @package Alegra\Connector\Sync
 */
final class Inventory_Writer
{
    private ?Logger\Logger $logger;

    public function __construct(?Logger\Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param array $opts {
     *   source: 'alegra'|'woocommerce',
     *   preserve: bool,
     *   manage_stock: 'enable'|'respect',
     *   dry_run: bool,
     *   warehouse_id: string,
     * }
     * @return string updated|clamped_negative|skipped_no_qty|skipped_service
     *                |skipped_not_manageable|skipped_source|skipped_preserve
     *                |skipped_parent|dry_run|error
     */
    public function apply(\WC_Product $product, array $item, array $opts = []): string
    {
        // T2.2 completa las compuertas; acá el esqueleto mínimo de T2.1.
        [$qty, $reason] = $this->resolve_quantity($item, '');
        if ($qty === null) {
            return 'skipped_no_qty';
        }
        $this->apply_stock($product, $qty);
        return $reason === 'clamped' ? 'clamped_negative' : 'updated';
    }

    /**
     * Extrae la cantidad a escribir. `[qty|null, reason]` con reason ∈
     * ok|clamped|no_qty.
     *
     * @return array{0:int|null,1:string}
     */
    private function resolve_quantity(array $item, string $warehouse_id): array
    {
        $qty = $item['inventory']['availableQuantity'] ?? null;

        if ($qty === null || $qty === '' || !is_numeric($qty)) {
            return [null, 'no_qty'];   // "no sé" NO es cero
        }

        $qty = (int) $qty;
        if ($qty < 0) {
            return [0, 'clamped'];     // Alegra permite negativo; WC no
        }
        return [$qty, 'ok'];
    }

    /**
     * ÚNICO punto de escritura de stock. Usa la API recomendada de WC.
     *
     * `wc_update_product_stock()` sólo actúa si el producto `managing_stock()`,
     * por eso `set_manage_stock(true)` va ANTES. Con `$updating=false` llama
     * `save()`, que deriva `_stock_status` respetando backorders + umbral y
     * dispara `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`.
     */
    private function apply_stock(\WC_Product $p, int $qty): void
    {
        $p->set_manage_stock(true);

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($p, $qty, 'set', false);
        } else {
            // Fallback WC < 3.0 (G7 / DR12). No dispara los hooks de stock.
            $p->set_stock_quantity($qty);
            $p->save();
        }
    }
}
```

**Orden de operaciones**: `set_manage_stock(true)` → `wc_update_product_stock($p,$qty,'set',false)`.
El `set_manage_stock` **antes** es obligatorio: `wc_update_product_stock()` sale temprano si
`$product->managing_stock()` es falso.

**Resultado esperado (aceptación verificable)**:
- `smoke-load.php` carga `Alegra\Connector\Sync\Inventory_Writer` por PSR-4.
- `wc_update_product_stock($p, 0, 'set', false)` sobre un producto con `manage_stock=true`,
  `backorders=yes` deja `_stock_status='onbackorder'` (no `outofstock`) y dispara
  `woocommerce_product_set_stock` **una** vez (test `T29.21`, requiere H1).
- Con `function_exists('wc_update_product_stock')` forzado a `false` (si G7 diera rama B), el fallback
  escribe la cantidad igual.

**Dependencias**: Fase 1 (`T1.1` stub H1, `T1.2` H2). `T2.1` no depende de ningún gate para la rama
A; la rama B (fallback) queda `BLOQUEADO(Fase 0.7 / G7)`.

**Trazabilidad**: REQ-INV-02, REQ-INV-04; D3 §4.1, D5 §6.1; `docs/sdd/inventory/REQ-DIV-1`.

**Verificación**:
1. `bash scripts/smoke-test.sh` → `SMOKE OK`; PSR-4 carga la clase.
2. Test `T29.21 writer dispara hooks y deriva status`: `alegra_test_reset();` crear un `WC_Product`
   con `manage_stock=true`, `backorders=yes`, qty 5; registrar un contador en
   `add_action('woocommerce_product_set_stock', fn($p) => $GLOBALS['hook_hits']++);` llamar
   `(new Inventory_Writer())->apply($p, ['inventory'=>['availableQuantity'=>0]], ['manage_stock'=>'enable'])`
   → `assertSame('updated', $r)`, `assertSame('onbackorder', $p->get_stock_status())`,
   `assertSame(1, $GLOBALS['hook_hits'])`.
3. Test `T29.23 backorders=no idéntico a HEAD`: el mismo producto con `backorders=no` y qty 0 ⇒
   `assertSame('outofstock', $p->get_stock_status())` (comportamiento de HEAD conservado).
4. **Prove-it-catches**: cambiar `apply_stock()` a `set_stock_quantity($qty); $p->save();` ⇒ (2) falla
   (no hay hook y el status queda `instock` stale o forzado). Re-aplicar ⇒ verde.
5. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `wc_update_product_stock()` no exista en el WC del comerciante (R14). **Guarda**:
`function_exists` + fallback; G7 decide la rama; test (2) cubre la rama A.

**Estimación**: L (3 h).

---

### T2.2 — Compuertas únicas del writer

**Objetivo**: que `Inventory_Writer::apply()` aplique **el mismo** conjunto de compuertas (fuente,
preserve, tipo, servicio, `manage_stock`, nulo, negativo, dry-run) a W1 y W2.

**Descripción técnica**: hoy W1 y W2 divergen. W1 respeta `inventory_source` y `preserve_fields`
(`Products.php:2006-2008`); W2 no (`:1224-1260`). W2 saltea `!get_manage_stock()` (`:1249-1251`) y
clampea negativos inline (`:1239-1248`). El writer centraliza todo y **unifica el resultado**: un
`skipped_*` nunca cuenta como `updated`. Cubre D3 §4.2 y REQ-INV-02/05.

**Desarrollo técnico**:

Archivo: `includes/Sync/Inventory_Writer.php`. Reemplazar `apply()` y `resolve_quantity()` por:

```php
    public function apply(\WC_Product $product, array $item, array $opts = []): string
    {
        // Defaults por fusión: una sola tabla, sin `??` por clave suelta.
        $opts = $opts + [
            'source'       => 'alegra',
            'preserve'     => false,
            'manage_stock' => 'respect',
            'dry_run'      => false,
            'warehouse_id' => '',
        ];

        // 1) Fuente. WooCommerce es dueño ⇒ el plugin no escribe.
        if ((string) $opts['source'] === 'woocommerce') {
            return 'skipped_source';
        }

        // 2) Preservar. `inventory` en preserve_fields ⇒ no tocar stock.
        if ($opts['preserve'] === true) {
            return 'skipped_preserve';
        }

        // 3) Variable PADRE: nunca maneja stock en WC (lo hacen las variaciones).
        if ($product->is_type('variable')) {
            $product->set_manage_stock(false);
            return 'skipped_parent';
        }

        // 4) Servicio: Alegra no manda objeto `inventory` ⇒ WC no gestiona stock.
        $inventory = $item['inventory'] ?? null;
        if (!is_array($inventory)) {
            $product->set_manage_stock(false);
            return 'skipped_service';
        }

        // 5) `manage_stock` legacy: con 'respect' se conserva el estado actual
        //    (idéntico a HEAD); con 'enable' (opt-in T2.5) se habilita.
        if ($opts['manage_stock'] !== 'enable' && !$product->get_manage_stock()) {
            return 'skipped_not_manageable';
        }

        // 6) Cantidad: nulo ≠ cero; negativo ⇒ clamp 0 + WARN.
        [$qty, $reason] = $this->resolve_quantity($item, (string) $opts['warehouse_id']);
        if ($qty === null) {
            $this->log('warning', 'Skipping stock update: Alegra returned no usable availableQuantity', $product, $item);
            return 'skipped_no_qty';
        }
        if ($reason === 'clamped') {
            $this->log('warning', 'Clamping negative Alegra stock to 0', $product, $item, ['original' => $item['inventory']['availableQuantity'] ?? null]);
        }

        // 7) Dry-run: reporta sin escribir.
        if ($opts['dry_run'] === true) {
            return 'dry_run';
        }

        // 8) Escritura única.
        $this->apply_stock($product, $qty);

        return $reason === 'clamped' ? 'clamped_negative' : 'updated';
    }

    /**
     * Extrae la cantidad. Warehouse-aware sólo si el caller pasa `warehouse_id`
     * (REQ-INV-06 se difiere en T4.4; `''` ⇒ lee el total, como HEAD).
     *
     * @return array{0:int|null,1:string} [qty|null, reason]
     */
    private function resolve_quantity(array $item, string $warehouse_id): array
    {
        $qty = $item['inventory']['availableQuantity'] ?? null;

        if ($warehouse_id !== ''
            && isset($item['inventory']['warehouses'])
            && is_array($item['inventory']['warehouses'])) {
            foreach ($item['inventory']['warehouses'] as $w) {
                if (is_array($w) && (string) ($w['id'] ?? '') === $warehouse_id) {
                    $qty = $w['availableQuantity'] ?? $qty;
                    break;
                }
            }
        }

        if ($qty === null || $qty === '' || !is_numeric($qty)) {
            return [null, 'no_qty'];
        }
        $qty = (int) $qty;
        if ($qty < 0) {
            return [0, 'clamped'];
        }
        return [$qty, 'ok'];
    }

    /**
     * Logger defensivo (el writer puede construirse sin logger).
     */
    private function log(string $level, string $message, \WC_Product $product, array $item, array $extra = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $context = ['product_id' => $product->get_id(), 'alegra_id' => (string) ($item['id'] ?? '')] + $extra;
        $this->logger->{$level}($message, $context);
    }
```

**Tabla de compuertas (orden exacto, la primera que matchea gana):**

| # | Compuerta | Condición | Retorno | Efecto lateral |
|---|---|---|---|---|
| 1 | Fuente | `source === 'woocommerce'` | `skipped_source` | ninguno |
| 2 | Preservar | `preserve === true` | `skipped_preserve` | ninguno |
| 3 | Variable padre | `is_type('variable')` | `skipped_parent` | `set_manage_stock(false)` |
| 4 | Servicio | `inventory` ausente/no-array | `skipped_service` | `set_manage_stock(false)` |
| 5 | `manage_stock` | `!get_manage_stock()` y `manage_stock !== 'enable'` | `skipped_not_manageable` | ninguno |
| 6a | Nulo | `availableQuantity` null/''/no-numérico | `skipped_no_qty` | WARN (nunca 0) |
| 6b | Negativo | `qty < 0` | `clamped_negative` | clamp 0 + WARN |
| 7 | Dry-run | `dry_run === true` | `dry_run` | ninguno |
| 8 | Escritura | — | `updated` | `apply_stock()` |

**Orden de operaciones**: las compuertas 1–5 son **antes** de tocar la cantidad; 6 antes del dry-run;
7 antes de `apply_stock`. Las compuertas 3/4 mutan `manage_stock` en memoria **sin** `save()`: el
caller sólo persiste si el retorno es `updated`/`clamped_negative` (porque `apply_stock` es el único
que guarda).

**Resultado esperado (aceptación verificable)**:
- `source='woocommerce'` ⇒ `skipped_source` y el stock no cambia.
- `preserve=true` ⇒ `skipped_preserve` y el stock no cambia.
- Variable padre ⇒ `skipped_parent` + `get_manage_stock()===false`.
- `inventory` ausente ⇒ `skipped_service` + `get_manage_stock()===false`.
- `availableQuantity=null` ⇒ `skipped_no_qty` y el stock **no** pasa a 0.
- `availableQuantity=-3` ⇒ `clamped_negative` y el stock queda 0 + WARN.
- `manage_stock='respect'` + producto `manage_stock=false` ⇒ `skipped_not_manageable` (idéntico a HEAD).
- `dry_run=true` ⇒ `dry_run` y el stock no cambia.

**Dependencias**: `T2.1`.

**Trazabilidad**: REQ-INV-02, REQ-INV-05; D3 §4.2.

**Verificación**:
1. Test `T29.22 compuertas del writer`: un test por fila de la tabla (8 asserts), incluyendo
   `assertSame('skipped_no_qty', ...)` con qty null y `assertSame(0, $p->get_stock_quantity())` tras
   el clamp. **Prove-it-catches**: quitar la compuerta 6a ⇒ el producto queda en 0 y el test falla.
2. Test `T29.23 backorders=no idéntico a HEAD`: con `backorders=no` y qty 0 ⇒ `outofstock`.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el orden de compuertas cambie comportamiento (p. ej. `preserve` antes de `variable`).
**Guarda**: el orden de la tabla es el contrato; cada fila tiene su assert.

**Estimación**: M (2 h).

---

### T2.3 — W1 delega en el writer (mover fuente + preserve)

**Objetivo**: que `Products::apply_inventory_to_product()` deje de escribir y construya `$opts` para
`Inventory_Writer::apply()`, moviendo los chequeos de `:2006-2008` al writer.

**Descripción técnica**: W1 (`Products.php:2052-2100`) es el escritor del **import**. Contiene los tres
`set_manage_stock` (`:2059,2067,2072`), `set_stock_quantity` (`:2097`) y `set_stock_status` (`:2099`).
El call site `:2006-2008` decide fuente + preserve. Con D3, esa decisión vive **sólo** en el writer
(una fuente de verdad). Cubre D3 §4.3 y REQ-INV-02/05.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`.

1. **Call site** en `update_product_from_alegra()` — ANTES (`:2001-2009`):

```php
            // Inventory. The setting was ignored here before: a merchant who
            // chose WooCommerce as the source still had stock overwritten on
            // every import. When WooCommerce owns inventory the plugin must not
            // touch stock at all. A variable PARENT never manages stock in WC
            // (its variations do), so it is skipped too.
            if ((string) get_option('alegra_connector_inventory_source', 'alegra') !== 'woocommerce'
                && !in_array('inventory', $preserve, true)) {
                $this->apply_inventory_to_product($product, $item);
            }
```

DESPUÉS:

```php
            // D3 (REQ-INV-02): la compuerta (fuente + preserve) vive en
            // Inventory_Writer; acá sólo se arma el contexto. Un solo escritor.
            $this->apply_inventory_to_product($product, $item, $preserve);
```

2. **Método W1** — ANTES (`:2052-2100`, completo): el bloque que va desde
`private function apply_inventory_to_product(\WC_Product $product, array $item): void {` hasta el
`}` de `:2100` (variable padre `:2058-2061`, servicio `:2063-2069`, `set_manage_stock(true)`
`:2072`, nulo `:2074-2083`, negativo `:2085-2095`, setters `:2097-2099`).

DESPUÉS (reemplaza todo el cuerpo del método):

```php
    /**
     * W1 — el import delega en el escritor único (D3).
     *
     * @param array<int,string> $preserve `resolve_preserve_fields()` del update.
     */
    private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void
    {
        (new Inventory_Writer($this->logger))->apply($product, $item, [
            'source'       => (string) get_option('alegra_connector_inventory_source', 'alegra'),
            'preserve'     => in_array('inventory', $preserve, true),
            // El import SIEMPRE habilitó manage_stock (comportamiento HEAD, W1
            // `:2072`): se conserva con 'enable' para no cambiar el import.
            'manage_stock' => 'enable',
            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
            'warehouse_id' => $this->resolve_warehouse_id(),
        ]);
    }
```

**Orden de operaciones**: el call site **no** cambia de posición (`:2008`); sólo deja de decidir.
`update_product_from_alegra()` sigue llamando `$product->save()` en `:2015` (fuera del writer): el
`apply_stock` del writer ya guardó con `wc_update_product_stock`, así que el `save()` de `:2015` es
idempotente. **No** se elimina `:2015` (persiste precio/nombre/etc.).

**Resultado esperado (aceptación verificable)**:
- Con `inventory_source=woocommerce`, el import **no** escribe stock (`skipped_source`).
- Con `inventory` en `preserve_fields`, el import **no** escribe stock (`skipped_preserve`).
- Sin ninguna de las dos, el import escribe vía `wc_update_product_stock` y deriva `_stock_status`.
- `grep -n 'set_manage_stock\|set_stock_quantity\|set_stock_status' includes/Sync/Products.php` ya no
  devuelve matches en W1.

**Dependencias**: `T2.1`, `T2.2`.

**Trazabilidad**: REQ-INV-02, REQ-INV-05; D3 §4.3.

**Verificación**:
1. Test `T29.24 W1 delega`: `update_option('alegra_connector_inventory_source','woocommerce');` +
   item con `availableQuantity=99` → el producto conserva su stock y el retorno del writer es
   `skipped_source`. Con `preserve_fields=['inventory']` → `skipped_preserve`. **Prove-it-catches**:
   volver a escribir el stock en W1 ⇒ el assert de "no cambia" falla.
2. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `update_product_from_alegra` dependa del estado en memoria de W1 para el `save()` de
`:2015`. **Guarda**: el writer guarda por su cuenta; el `save()` posterior es no-op para stock.

**Estimación**: M (2 h).

---

### T2.4 — W2 delega en el writer (poll)

**Objetivo**: que el poll (`sync_inventory_from_alegra`) reemplace su bloque de escritura inline por
`Inventory_Writer::apply()`, eliminando el forzado de `_stock_status`.

**Descripción técnica**: W2 (`Products.php:1238-1277`) castea `availableQuantity` (`:1238`), clampea
negativos (`:1239-1248`), saltea `!get_manage_stock()` (`:1249-1251`), y dentro de un `try` escribe
`set_stock_quantity` (`:1255`) + `set_stock_status($new_qty > 0 ? 'instock' : 'outofstock')` (`:1259`)
+ `save()` (`:1260`). El writer reemplaza todo y respeta `preserve_fields` (que hoy W2 ignora, REQ-INV-05).
Cubre D3 §4.3 y REQ-INV-02/04/05.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`, dentro del `foreach ($items as $item)` del poll.

1. **`$result`** (`:1144`) — agregar el contador del opt-in (lo llena `T2.5`). **Conjunto CANÓNICO de
   claves de `$result` (9):** `updated`, `errors`, `pages`, `locked`, `skipped`,
   `skipped_not_manageable`, `truncated`, `completed`, `cursor`.

   > **Restricción cross-file (C2).** `T2.4` agrega `skipped_not_manageable`; `T7.1.a` (Fase 7) agrega
   > `truncated`/`completed`/`cursor` y **debe preservar `skipped_not_manageable`**. El assert de
   > `T7.1.a` que dice "el array `$result` contiene las 8 claves" queda **superseded: son 9**. Sin esta
   > preservación, `T2.5`/`T3.4` incrementan `$result['skipped_not_manageable']` sobre un índice
   > inexistente (undefined index). El agente de Fase 7 debe actualizar su bloque y su assert a 9 claves.

```php
        $result = ['updated' => 0, 'errors' => 0, 'pages' => 0, 'locked' => false, 'skipped' => false,
                   'skipped_not_manageable' => 0];
```

2. **Bloque de escritura** — ANTES (`:1238-1277`):

```php
                    $new_qty = (int) $item['inventory']['availableQuantity'];
                    if ($new_qty < 0) {
                        // Alegra permits negative stock; WooCommerce does not.
                        // Mirror apply_inventory_to_product() and clamp to 0.
                        $this->logger->warning('Clamping negative Alegra stock to 0', [
                            'product_id' => $product_id,
                            'alegra_id'  => $item['id'],
                            'original'   => $new_qty,
                        ]);
                        $new_qty = 0;
                    }
                    if (!$product->get_manage_stock()) {
                        continue;
                    }

                    try {
                        $old_qty = $product->get_stock_quantity();
                        $product->set_stock_quantity($new_qty);
                        // WC does not derive _stock_status from the quantity on
                        // save(); it must be set explicitly so a 0 becomes
                        // outofstock instead of keeping a stale instock.
                        $product->set_stock_status($new_qty > 0 ? 'instock' : 'outofstock');
                        $product->save();
                        $result['updated']++;

                        if ($old_qty !== $new_qty) {
                            $this->logger->info('Inventory updated from Alegra', [
                                'product_id' => $product_id,
                                'alegra_id' => $item['id'],
                                'old_qty' => $old_qty,
                                'new_qty' => $new_qty,
                            ]);
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error('Failed to update inventory', [
                            'product_id' => $product_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
```

DESPUÉS (T2.4 usa `'respect'`; `T2.5` lo cambia por `$opt_in`):

```php
                    $old_qty = $product->get_stock_quantity();
                    try {
                        // D3 (REQ-INV-02): un solo escritor. El writer decide
                        // fuente/preserve/nulo/negativo/servicio/manage_stock y
                        // deriva _stock_status con wc_update_product_stock (D5).
                        $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
                            'source'       => 'alegra',
                            'preserve'     => in_array('inventory', $this->resolve_preserve_fields(), true),
                            'manage_stock' => 'respect',   // T2.5 lo vuelve $opt_in ? 'enable' : 'respect'
                            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
                            'warehouse_id' => $this->resolve_warehouse_id(),
                        ]);

                        if ($status === 'updated' || $status === 'clamped_negative') {
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
                    }
```

3. **Borrar** el guard `:1224-1226` (`!isset($item['inventory']['availableQuantity'])`): el writer
   ahora devuelve `skipped_no_qty`. El lookup `:1228-1236` se mantiene.

**Orden de operaciones**: lookup (`:1228`) → `$product` (`:1233`) → writer → contar. **No** se
elimina el `try/catch` (un throw del writer no debe abortar el poll). En Fase 3, `T3.4` inserta el
chequeo del ledger **antes** del writer (y `T7.1` preserva ese bloque al reescribir el loop). Ese
chequeo del ledger **debe** usar los helpers de `Inventory_Pusher` (`synced()`/`pending()`/
`set_synced()`/`set_pending()`/`clear_pending()`), **no** `get_post_meta` crudo (C5; ver `fase-1`
T1.10, contrato K-P).

**Resultado esperado (aceptación verificable)**:
- Un ítem con `availableQuantity=10` y `preserve_fields` **sin** `inventory` ⇒ stock 10 vía
  `wc_update_product_stock`.
- Con `preserve_fields=['inventory']` ⇒ stock **no** cambia y `updated` **no** incrementa (REQ-INV-05).
- `backorders=yes` + qty 0 ⇒ `_stock_status='onbackorder'` (REQ-INV-04).
- `grep` de setters en `Products.php` ya no devuelve matches en W2.

**Dependencias**: `T2.1`, `T2.2`, `T2.3`.

**Trazabilidad**: REQ-INV-02, REQ-INV-04, REQ-INV-05; D3 §4.3, D5 §6.1.

**Verificación**:
1. Test `T29.25 W2 delega y respeta preserve`: seed de un ítem con qty 10 y producto WC qty 7;
   `preserve_fields=['inventory']` → `sync_inventory_from_alegra()` deja 7 y `updated=0`.
   Sin preserve → 10. **Prove-it-catches**: restaurar el `set_stock_status` forzado ⇒ con
   `backorders=yes` y qty 0 el estado queda `outofstock` y el test falla.
2. Test `T29.26 W2 no escribe servicios`: ítem sin `inventory` → `skipped_service` y sin save.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el poll deje de contar ítems que antes contaba (cambio de semántica de `updated`).
**Guarda**: el conteo de `skipped_not_manageable` da visibilidad; el DoD de REQ-INV-05 exige no contar
los preservados.

**Estimación**: M (1.5 h).

---

### T2.5 — Migración legacy `manage_stock=no` (opt-in)

**Objetivo**: que el poll pueda habilitar `manage_stock` en productos que Alegra marca inventariables
pero WC tiene en `no`, **sólo** si el comerciante activa la opción; por defecto respeta el estado
actual (idéntico a HEAD).

**Descripción técnica**: HEAD saltea en el poll todo producto con `manage_stock=false`
(`:1249-1251`), así que un catálogo legacy nunca sincroniza stock (hallazgo B7). El design §4.4 decide
un **opt-in** `alegra_connector_inventory_manage_stock_enabled` (bool, default `false`, autoload no),
**sin migración de datos**: el cambio es por-producto al pasar el poll. Cubre REQ-INV-02 y D3 §4.4.

**Desarrollo técnico**:

1. **Opción**: `alegra_connector_inventory_manage_stock_enabled` (bool, default **false**, autoload
   **no**). Ya la siembra `T1.7` (`$defaults` + `$non_autoload` + `uninstall.php`, design §11).

2. **`Products.php` (W2 `$opts`)** — reemplazar `'manage_stock' => 'respect'` de `T2.4`:

```php
                            'manage_stock' => get_option('alegra_connector_inventory_manage_stock_enabled', false)
                                ? 'enable'
                                : 'respect',
```

3. **Registro** en `admin/Admin/Admin_Dashboard.php` (junto a `register_setting`, pestaña
   Sincronización; patrón de `:496,507`):

```php
        register_setting('alegra_connector_settings', 'alegra_connector_inventory_manage_stock_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ]);
```

4. **UI** en `templates/admin-settings.php` (pestaña **Sincronización**), toggle con advertencia:

```php
<label>
    <input type="checkbox" name="alegra_connector_inventory_manage_stock_enabled" value="1"
        <?php checked(get_option('alegra_connector_inventory_manage_stock_enabled', false)); ?>>
    <?php esc_html_e('Gestionar stock en WooCommerce para productos que Alegra marca inventariables', 'alegra-connector'); ?>
</label>
<p class="description">
    <?php esc_html_e('Si lo activás, el poll habilita la gestión de stock en WC para los productos con inventario en Alegra. Si lo dejás apagado, los productos con "Gestionar stock" desactivado en WC no se tocan (comportamiento actual).', 'alegra-connector'); ?>
</p>
```

5. **Log del conteo**: al final del poll, si `$result['skipped_not_manageable'] > 0`, loguear
   `info('Inventory sync: products skipped because WC does not manage stock', ['count' => ..., 'opt_in' => ...])`.

**Orden de operaciones**: la opción se lee **por ítem** (no se cachea en la clase) para que un toggle
en Ajustes aplique en la próxima corrida sin re-registrar hooks.

**Resultado esperado (aceptación verificable)**:
- `false` (default) + producto `manage_stock=false` ⇒ `skipped_not_manageable` y stock intacto
  (idéntico a HEAD).
- `true` + producto `manage_stock=false` + ítem inventariable ⇒ el poll habilita `manage_stock` y
  escribe la cantidad.
- El log del poll reporta cuántos productos quedaron `skipped_not_manageable`.
- Una instalación existente **no** cambia de comportamiento (default false, sin migración).

**Dependencias**: `T2.4`. `T1.7` (siembra de la opción).

**Trazabilidad**: REQ-INV-02; D3 §4.4; NFR-04; `docs/sdd/inventory/REQ-MIG` (se consume, no se reabre).

**Verificación**:
1. Test `T29.27 opt-in manage_stock`: `update_option('alegra_connector_inventory_manage_stock_enabled', false);`
   + producto `manage_stock=false` → `sync_inventory_from_alegra()` no lo toca y
   `$result['skipped_not_manageable']===1`. Con la opción en `true` → el producto queda
   `get_manage_stock()===true` y stock actualizado. **Prove-it-catches**: hardcodear `'respect'` ⇒ el
   segundo assert falla.
2. `bash scripts/smoke-test.sh` verde (el template y el `register_setting` cargan).
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: activar el opt-in y pisar stock de productos que el comerciante no quería gestionar.
**Guarda**: default `false`; la UI advierte explícitamente; el log reporta el conteo.

**Estimación**: M (1.5 h).

---

### T2.6 — Corregir el docblock refutado (`Products.php:2031-2034`)

**Objetivo**: que el docblock de `apply_inventory_to_product()` deje de afirmar que WC no deriva
`_stock_status`.

**Descripción técnica**: el claim `Products.php:2031-2034` ("it does NOT derive `_stock_status` from
`set_stock_quantity()`+`save()`, so all three fields must be written explicitly") está **REFUTADO**:
`WC_Product::save()` llama `validate_props()` (`abstract-wc-product.php:1547`), que desde WC 3.0
deriva `instock`/`onbackorder`/`outofstock` (`:1517-1538`). El docblock fue la justificación del
forzado que D5 elimina. Cubre REQ-INV-04 y D5 §6.2.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`, docblock de `apply_inventory_to_product()`.

ANTES (`:2031-2034`):

```php
     * WooCommerce ignores `_stock` unless `_manage_stock` is enabled, and it
     * does NOT derive `_stock_status` from `set_stock_quantity()`+`save()`, so
     * all three fields must be written explicitly. Without this the stock value
     * was written but never shown — the root cause of "no trae la existencia".
```

DESPUÉS:

```php
     * WooCommerce ignores `_stock` unless `_manage_stock` is enabled. The
     * `_stock_status` is DERIVED by WC on save(): `WC_Product::save()` calls
     * `validate_props()` (WC >= 3.0), which computes instock/onbackorder/
     * outofstock from quantity + `_backorders` + the no-stock threshold. The
     * old claim that WC does not derive it was FALSE. The write goes through
     * `wc_update_product_stock()` (the recommended API, which fires the stock
     * hooks) and never forces the status; `_backorders` is respected untouched.
```

Además, actualizar la línea `:2045` (`_backorders ... deliberately left untouched`) para aclarar que
el respeto lo garantiza WC al derivar. Y ajustar `:2040-2043` para reflejar que el writer es el dueño.

**Resultado esperado (aceptación verificable)**: `grep -n 'does NOT derive' includes/Sync/Products.php`
devuelve 0 matches; el docblock cita `validate_props()` y `wc_update_product_stock()`.

**Dependencias**: `T2.1`–`T2.4` (el código que documenta ya cambió).

**Trazabilidad**: REQ-INV-04; D5 §6.2.

**Verificación**:
1. Test `T29.28 source-scan docblock`: leer el archivo y `assertStringNotContains('does NOT derive', $src)`
   + `assertStringContains('validate_props', $src)`.
2. `bash scripts/smoke-test.sh` verde.

**Riesgo**: ninguno (sólo comentario). **Guarda**: el source-scan.

**Estimación**: XS (0.25 h).

---

### T2.7 — Grep invariante de un solo escritor

**Objetivo**: probar por búsqueda estática que los setters de stock viven **sólo** en
`Inventory_Writer.php`.

**Descripción técnica**: REQ-INV-02 exige que un grep de `set_manage_stock|set_stock_quantity|
set_stock_status` en producción devuelva matches sólo en el writer. Antes de esta fase había matches
en `Products.php:1255,1259,2059,2067,2072,2097,2099`. Cubre REQ-INV-02 y D3.

**Desarrollo técnico**: no escribe producto. Se agrega un **source-scan** al harness
(`scripts/exec-test.php`) y se documenta el comando manual:

```bash
grep -rnE 'set_manage_stock|set_stock_quantity|set_stock_status|wc_update_product_stock' \
    includes/ public/ admin/ | grep -v 'Inventory_Writer.php'
```

Debe devolver **0 líneas**. Excluir `scripts/` (harness, fuera del ZIP) y `docs/`.

**Resultado esperado (aceptación verificable)**: el comando devuelve 0 líneas; `T29.29` verde.

**Dependencias**: `T2.1`–`T2.5`.

**Trazabilidad**: REQ-INV-02; D3 §1.1.

**Verificación**:
1. Test `T29.29 grep un solo escritor`: recorrer `includes/`, `public/`, `admin/` y assertar que
   ningún archivo que no sea `includes/Sync/Inventory_Writer.php` contiene los patrones.
   **Prove-it-catches**: dejar el `set_stock_quantity` viejo en W1 ⇒ el test falla.
2. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `wc_update_product_stock` aparezca en un docblock de otro archivo y dé falso
positivo. **Guarda**: el scan excluye comentarios o acepta la mención sólo en el writer; el test
reporta archivo:línea para revisión.

**Estimación**: S (0.5 h).

---

### T2.8 — Tests del writer

**Objetivo**: cubrir las 8 compuertas, la derivación de `_stock_status` con backorders yes/no y el
`preserve_fields` del poll.

**Descripción técnica**: cierra la fase con la batería `T29.21`–`T29.29` en
`scripts/exec-test.php` (sección `T29`, creada por `T1.11`). El test central de D5 es `T29.21`
(hooks + derivación). El de REQ-INV-05 es `T29.25`.

**Desarrollo técnico**: agregar al final de la sección `T29` (tras los tests de Fase 1):

| Test | Cubre | Assert clave |
|---|---|---|
| `T29.21` | REQ-INV-04 / D5 | `wc_update_product_stock` dispara `woocommerce_product_set_stock`; `backorders=yes`+qty 0 ⇒ `onbackorder` |
| `T29.22` | REQ-INV-02 | las 8 compuertas devuelven el código esperado |
| `T29.23` | REQ-INV-04 | `backorders=no`+qty 0 ⇒ `outofstock` (idéntico a HEAD) |
| `T29.24` | REQ-INV-02/05 | W1 respeta `source`/`preserve` |
| `T29.25` | REQ-INV-05 | W2 (poll) respeta `preserve_fields` |
| `T29.26` | REQ-INV-02 | W2 no escribe servicios |
| `T29.27` | REQ-INV-02 / D3.4 | opt-in `manage_stock` |
| `T29.28` | REQ-INV-04 | docblock corregido (source-scan) |
| `T29.29` | REQ-INV-02 | grep de un solo escritor |

**Resultado esperado (aceptación verificable)**: `bash scripts/exec-test.sh` ⇒ `EXEC-TEST OK` con
`T29.21`–`T29.29` verdes y 0 failed.

**Dependencias**: `T2.1`–`T2.7`; H1/H2 (Fase 1).

**Trazabilidad**: REQ-INV-02/04/05; NFR-01.

**Verificación**:
1. `bash scripts/exec-test.sh` → `EXEC-TEST OK`, 0 failed.
2. Documentar el prove-it-catches de `T29.21` (revertir `apply_stock` → rojo) en
   `docs/RELEASE_2.6.0_VERIFICATION.md` (`T9.6`).
3. `bash scripts/smoke-test.sh` → `SMOKE OK`.

**Riesgo**: que el stub H1 no dispare el hook exacto (`woocommerce_product_set_stock`). **Guarda**:
`T1.1` lo define; si el nombre cambia, `T29.21` lo detecta.

**Estimación**: M (2 h).

---

## DoD de la fase

- REQ-INV-02, REQ-INV-04 y REQ-INV-05 verdes.
- Existe `includes/Sync/Inventory_Writer.php` (`final class`, `apply()` con las 8 compuertas,
  `apply_stock()` con `wc_update_product_stock` + fallback).
- W1 (`apply_inventory_to_product`) y W2 (poll) **delegan**; los setters de stock viven sólo en el
  writer (`T29.29`).
- `_stock_status` se deriva: `backorders=yes`+0 ⇒ `onbackorder`; `backorders=no`+0 ⇒ `outofstock`.
- El poll honra `preserve_fields` (REQ-INV-05) y la opción opt-in `manage_stock`.
- El docblock refutado (`:2031-2034`) está corregido.
- `bash scripts/exec-test.sh` (`T29.21`–`T29.29`) y `bash scripts/smoke-test.sh` verdes.
- **Prove-it-catches** documentado para `T2.1` (`T29.21`).
- Cambios intencionales (`dry_run` sobre stock, `_stock_status` derivado, `preserve_fields` en el
  poll) anotados para `CHANGELOG.md` en `T9.5`.
