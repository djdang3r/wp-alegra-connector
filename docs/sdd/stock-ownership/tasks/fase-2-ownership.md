# Fase 2 — Dueño del stock (D1) + guardas server-enforced + limpieza de modo

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único configurable + poll sin re-inflación + cola + reconciliación) |
| Fase | 2 de 8 — **D1: el modelo de propiedad del stock** (el fix del doble descuento) |
| Tareas | `T2.1` · `T2.2` · `T2.3` · `T2.4` · `T2.5` · `T2.6` · `T2.7` · `T2.8` |
| Depende de | **Fase 1** (`T1.5` opciones `stock_owner`/`stock_owner_epoch`; `T1.1` H-A stock por factura; `T1.4` H-E líneas de pedido). **`T2.4` además `BLOQUEADO(Fase 0.2 / G1)`** |
| DoD de la fase | REQ-OWN-01..07 verdes; `auto` reproduce 2.6.0 en las 4 combinaciones; `invoice` ⇒ cero `POST /inventory-adjustments`; `adjustment` ⇒ nunca auto-abre y la apertura manual exige `confirm_double_discount=1`; `_alegra_stock_adjusted_at` escrito sólo al emitir; limpieza lazy + epoch; `bash scripts/exec-test.sh` verde |
| Documentos base | `proposal.md` §4 Tema A · `spec.md` §A (REQ-OWN-01..07) · `design.md` §2 (D1) + §2.5 + §13 (G1) · `tasks.md` Fase 2 + §3 Bloque 2 |
| Versión objetivo | 2.7.0 |
| Decisión de la fase | **D1** (opción `alegra_connector_stock_owner` ∈ `{auto,invoice,adjustment}`) + **C1** (`auto` = condición DOBLE) |

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T30.2{n}`
> (`T30.21`, `T30.22`, `T30.24`, `T30.24b`, `T30.24c`, `T30.24d`, `T30.25`, `T30.26`, `T30.28`, `T30.29`, `T30.210`) según
> la convención `T30.{fase}{n}` (§5.3 de `tasks.md` es la AUTORIDAD). Los IDs de **tarea** (`T2.1`…`T2.8`)
> no cambian. La sección `// === stock-ownership (2.7.0) ===` la crea `T1.9`.

> **Regla de oro de esta fase (prove-it-catches, obligatoria).** El test central de D1 es `T30.21`:
> `owner()` con `stock_owner=auto` reproduce la **condición doble** en las 4 combinaciones de
> `push_orders_enabled` × `open_invoice_on_paid`. **Prove-it-catches:** revertir `owner()` a la fórmula
> simplificada (`push_orders_enabled ? 'invoice' : 'adjustment'`) ⇒ la combinación
> `push_orders_enabled=true` + `open_invoice_on_paid=false` devuelve `invoice` y el test `T30.21` queda
> rojo (reintroduce B3/Oracle#1: ningún mecanismo mueve stock). Sin ese revert→rojo, el test no se acepta.
> El segundo prove-it-catches obligatorio es `T30.25`: quitar la guarda server-enforced ⇒ el AJAX abre la
> factura con un `confirm()` de cliente y el test falla.

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

Al leer el código real antes de escribir estas micro-tareas aparecen **8 hallazgos** que cambian el plan
de test y el código. Se listan primero porque varias tareas dependen de ellos.

| # | Claim (design/spec/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `auto` = `invoice` si `push_orders_enabled` (`proposal.md:168-169`, `spec.md:173`) | **FALSO.** `owner()` real es la **condición DOBLE** `push_orders_enabled && open_invoice_on_paid` (`Inventory_Pusher.php:87-90`). Con `push_orders_enabled=true` + `open_invoice_on_paid=false` la fórmula simplificada devuelve `invoice` y **ningún mecanismo mueve stock** (B3). | `T2.1` reproduce la doble; corrige la prosa de `proposal`/`spec`. |
| C2 | El diseño escribe **dos** metas de ajuste: por producto `_alegra_stock_adjusted_at` **y** por pedido `_alegra_stock_adjusted` (order id) (`design.md` §2.2.1) | **Sólo el per-product `_alegra_stock_adjusted_at` es implementable.** `push_delta()` (`Inventory_Pusher.php:157-277`) **no conoce el pedido** (F1/`Stock_Order_Context` no existe; G6/G9 refutado). La guarda compara el timestamp del producto contra `get_date_created()` del pedido. | `T2.2` escribe sólo `_alegra_stock_adjusted_at`; `T2.5`/`T2.6` leen por línea. `_alegra_stock_adjusted` **no se crea**. |
| C3 | `Orders.php:88-90` depende de `open_invoice_on_paid` además de `owner()` | **Confirmado.** `:88` = `owner()==='invoice'`; `:89` = `get_option('alegra_connector_open_invoice_on_paid', true)`; `:90` = `$order->is_paid()`. El override de `:122-126` **no** usa la opción (ya depende sólo de dueño + pago). | `T2.3` quita `:89`; `:122-126` no cambia. |
| C4 | `META_SYNCED`/`META_PENDING` en `:32-33` | **Confirmado.** `public const META_SYNCED = '_alegra_stock_synced';` `:32`; `public const META_PENDING = '_alegra_stock_push_pending';` `:33`. | `T2.1` agrega `META_ADJUSTED_AT` en `:34`. |
| C5 | Ramas `ok`/`already_applied` de `push_delta()` para la marca | **Confirmado.** `already_applied` = `:244-246`; `ok` = `:271-273`. | `T2.2` inserta `mark_adjusted()` en ambas. |
| C6 | Existe un contexto "acción explícita" para distinguir la creación manual de la automática | **Confirmado.** `Write_Gate::is_explicit()` (`Write_Gate.php:137`), `begin_explicit()` `:142`, `run_explicit()` `:156`. | `T2.4` (Rama B) usa `!Write_Gate::is_explicit()` para no romper la creación manual. |
| C7 | No hay `register_setting` de `stock_owner` | **Confirmado.** El bloque `register_settings()` va de `Admin_Dashboard.php:353` a `:562`; ninguna clave `stock_owner`. Patrón de `:381-396` (`push_orders_enabled`/`open_invoice_on_paid`). | `T2.7`/`T2.8` agregan el registro + la coerción. |
| C8 | `ajax_open_invoice_impl`/`ajax_record_payment_impl` no consultan `owner()` | **Confirmado.** `ajax_open_invoice_impl` `Admin_Dashboard.php:2845-2895`; `ajax_record_payment` wrapper `:2897`, `_impl` `:2902-3028`. Ninguna llama `owner()`. | `T2.5`/`T2.6` agregan la guarda. |

**Confirmaciones (no requieren corrección).**

- `Inventory_Pusher.php:85-91` `owner()` (reemplazo de `T2.1`) — confirmado.
- `Inventory_Pusher.php:170-173` Guard 2 (`owner() !== 'adjustment'` ⇒ `invoice_owner`); `:172` el `return` — confirmado. **Es el corte que garantiza cero ajustes por pedido en `invoice`.**
- `Inventory_Pusher.php:197-216` baseline FIX-1; `:214` `set_synced`; `:244-246` `already_applied`; `:271-273` `ok` — confirmados.
- `Inventory_Pusher.php:303-335` `adjustment_already_exists()`; `:340-358` `build_adjustment_payload()` — confirmados (los toca `T3.4`, no esta fase).
- `Products.php:1348-1349` `$handled` = `['ok','already_applied','in_sync','api_error','blocked','locked','baseline_unverified']`; **`invoice_owner` NO está** ⇒ el poll cae al writer — confirmado (lo cierra `T3.1`).
- `Orders.php:63-193` `create_invoice()`; `:91-93` `ensure_invoice_open` del borrador pre-existente; `:122-126` override `'open'`; `:494` `create_invoice_with_payment()`; `:512` pasa `'open'`; `:850-913` `ensure_invoice_open()` — confirmados.
- `Admin_Dashboard.php:862-887` `get_unjournaled_sales()`; `:865` `['processing','completed']`; `:903-905` gate invertido — confirmados (los toca Fase 5).
- `alegra-connector.php:414-468` `$defaults`; `:474-507` `$non_autoload`; `:509-513` loop; `:460` `open_invoice_on_paid`; `:462` `invoice_status` — confirmados.
- `templates/admin-settings.php:94` "Fuente de inventario"; `:101` "Enviar stock a Alegra"; `:110` toggle "Abrir la factura al pagarse" (bloque `:109-112`) — confirmados. El **selector visible + copy literal** es `T6.1`/`T6.2` (Fase 6); esta fase sólo siembra la opción y coerciona.
- Grep en producción: `alegra_connector_stock_owner` → **0 coincidencias**; `_alegra_stock_adjusted` → **0 coincidencias**. Confirmado.

---

## Contrato canónico (lo define esta fase; Fase 3/5/6 lo consumen, nunca lo redefinen)

### K-OWN — Firma, opción y guardas

```php
namespace Alegra\Connector\Sync;

final class Inventory_Pusher
{
    public const META_ADJUSTED_AT = '_alegra_stock_adjusted_at';   // NUEVO (T2.1)

    public static function owner(): string;                        // 'invoice'|'adjustment' (T2.1)
    private static function log_invalid_owner(string $mode): void; // 1 warning por request (T2.1)
    public static function mark_adjusted(int $product_id): void;   // T2.2
    public static function adjusted_at(int $product_id): int;      // 0 si nunca (T2.2)
}

// includes/Sync/Orders.php
public function order_has_emitted_adjustment(\WC_Order $order): bool; // T2.5/T2.6
```

**Opción `alegra_connector_stock_owner`** (enum `auto|invoice|adjustment`, default **`auto`**, autoload
**no**). La siembra `T1.5` (`$defaults` + `$non_autoload` + `uninstall.php`). **`auto` = condición
DOBLE** de 2.6.0:

```
owner() = get_option('alegra_connector_stock_owner', 'auto')
  'invoice'    → 'invoice'
  'adjustment' → 'adjustment'
  'auto' u otro→ push_orders_enabled && open_invoice_on_paid ? 'invoice' : 'adjustment'
                 (otro valor ⇒ log_invalid_owner() una vez, y cae a auto)
```

**Códigos/parámetros nuevos (contrato AJAX, NFR-06):**

| Símbolo | Valor | Dónde |
|---|---|---|
| Param POST | `confirm_double_discount=1` | `ajax_open_invoice_impl` / `ajax_record_payment_impl` |
| Código de error | `double_discount_confirm_required` | respuesta `wp_send_json_error` |
| Nota del pedido | "Apertura manual con ajuste ya emitido: el comerciante confirmó el doble descuento." | `add_order_note()` |
| Log | `warning` con `order_id`, `invoice_id`, `owner` | `Logger` |

### K-OWN-2 — Interacción con las opciones existentes (design §2.5)

| Opción | `stock_owner=auto` | `stock_owner=invoice` | `stock_owner=adjustment` |
|---|---|---|---|
| `push_orders_enabled` | Decide `invoice` vs `adjustment` (junto con `open_invoice_on_paid`). | **Forzado ON** (`T2.8`, Oracle#8/DEF-8): con `invoice` el plugin **DEBE** facturar. Con `false`, Guard 2 (`Inventory_Pusher.php:170-173`) corta **todo** ajuste y no hay facturación automática ⇒ **cero** mecanismos mueven stock. | Irrelevante para el stock (el ajuste mueve); sigue gobernando la auto-facturación. |
| `open_invoice_on_paid` | **Parte de la condición** (compat total). | **Forzado ON** (`T2.8`). | **Forzado OFF** (`T2.8`). |
| `invoice_status` | Sin cambio. Con `draft`, el borrador se abre al pagar (FIX-3, `Orders.php:88-101`). | **Recomendado `draft`**. Con `open`, un pedido impago crea la factura abierta y mueve stock sin que WC reduzca ⇒ divergencia hasta el pago; la UI advierte (`T6.2`). | Sin efecto de stock: la factura queda `draft` por diseño. |

**Regla dura:** el dueño se resuelve **sólo** en `Inventory_Pusher::owner()`. Ninguna otra ruta lo decide.
`invoice` ⇒ Guard 2 (`:170-173`) corta **todo** ajuste por pedido **antes** del baseline/POST.

---

### T2.1 — `owner()` lee la opción + condición DOBLE + `log_invalid_owner()` · `BLOQUEADO` NO (arranca ya)

**Objetivo**: que `owner()` lea `alegra_connector_stock_owner` y, en `auto`, reproduzca **exactamente**
la lógica de 2.6.0 (condición DOBLE), con `log_invalid_owner()` una vez por request para valores inválidos.

**Descripción técnica**: hoy `owner()` (`Inventory_Pusher.php:85-91`) deriva el dueño de
`push_orders_enabled && open_invoice_on_paid` sin opción explícita. `T2.1` introduce la opción y el
bootstrap `log_invalid_owner()`. Corrige **C1**: `auto` **NO** es `push_orders_enabled ? invoice :
adjustment` (fórmula de `proposal.md:168-169`/`spec.md:173`); es la **condición doble**. Con
`push_orders_enabled=true` + `open_invoice_on_paid=false`, la factura nace `draft` y no mueve stock
(`Orders.php:122-126` sólo aplica `'open'` con dueño `invoice`); si `auto` devolviera `invoice`, el
pusher no emitiría ajustes y la factura no movería stock ⇒ **ningún mecanismo mueve stock** (B3).
Cubre REQ-OWN-02 y D1 §2.1.

**Desarrollo técnico**:

Archivo: `includes/Sync/Inventory_Pusher.php`.

1. **Const** — ANTES (`:32-33`):

```php
    public const META_SYNCED  = '_alegra_stock_synced';
    public const META_PENDING = '_alegra_stock_push_pending';
```

DESPUÉS (agregar la tercera):

```php
    public const META_SYNCED       = '_alegra_stock_synced';
    public const META_PENDING      = '_alegra_stock_push_pending';
    public const META_ADJUSTED_AT  = '_alegra_stock_adjusted_at';   // D1 §2.2.1 (T2.2)
```

2. **`owner()`** — ANTES (`:78-91`, completo):

```php
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
```

DESPUÉS (reemplaza el método; agrega el flag y el logger defensivo):

```php
    /** Evita repetir el warning de valor inválido dentro del mismo request. */
    private static bool $invalid_owner_logged = false;

    /**
     * Dueño único del stock (D1 / REQ-OWN-02). Resolución ÚNICA del plugin.
     *
     * `auto` reproduce la condición DOBLE de 2.6.0 (CORRECCIÓN C1): la factura
     * sólo es dueña si ADEMÁS de subir pedidos se abre al pagar; si no, la
     * factura nace draft y no mueve stock ⇒ ningún mecanismo lo movería (B3).
     *
     * @return 'invoice'|'adjustment'
     */
    public static function owner(): string
    {
        $mode = (string) get_option('alegra_connector_stock_owner', 'auto');

        if ($mode === 'invoice' || $mode === 'adjustment') {
            return $mode;
        }
        if ($mode !== 'auto') {
            // REQ-OWN-02 (borde): valor inválido ⇒ auto + warning una vez.
            self::log_invalid_owner($mode);
        }

        return (get_option('alegra_connector_push_orders_enabled', false)
            && get_option('alegra_connector_open_invoice_on_paid', true))
            ? 'invoice'
            : 'adjustment';
    }

    /**
     * REQ-OWN-02: un valor no reconocido cae a `auto` y se advierte una vez
     * por request (no por ítem, no por hook).
     */
    private static function log_invalid_owner(string $mode): void
    {
        if (self::$invalid_owner_logged) {
            return;
        }
        self::$invalid_owner_logged = true;

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning('Valor inválido de alegra_connector_stock_owner; se usa auto', [
                'source' => 'alegra-connector',
                'value'  => $mode,
            ]);
        }
    }
```

**Orden de operaciones**: la opción se lee **por llamada** (no se cachea): un toggle en Ajustes aplica en
el próximo movimiento (REQ-OWN-05). El flag `$invalid_owner_logged` es `static` (una vez por request).

**Resultado esperado (aceptación verificable)**:
- `stock_owner=invoice` ⇒ `owner()` = `invoice` (aunque `push_orders_enabled=false`).
- `stock_owner=adjustment` ⇒ `owner()` = `adjustment` (aunque `push_orders_enabled=true`).
- `stock_owner=auto` ⇒ las **4** combinaciones:
  | `push_orders_enabled` | `open_invoice_on_paid` | `owner()` |
  |---|---|---|
  | `false` | `true` | `adjustment` |
  | `false` | `false` | `adjustment` |
  | `true` | `false` | **`adjustment`** (la que rompe la fórmula simplificada) |
  | `true` | `true` | `invoice` |
- `stock_owner='basura'` ⇒ `owner()` = `auto` resuelto y **un** warning en el log.
- Con `owner()='invoice'`, `push_delta()` devuelve `invoice_owner` **sin** `POST /inventory-adjustments` (Guard 2, `:170-173`).

**Dependencias**: `T1.5` (siembra de `alegra_connector_stock_owner`). Ningún gate.

**Trazabilidad**: REQ-OWN-02, REQ-OWN-03; D1 §2.1; corrección **C1**; `design.md` §7.1; NFR-03.

**Verificación**:
1. Test `T30.21 owner() en las 4 combinaciones`: `update_option('alegra_connector_stock_owner','auto');`
   y recorrer la tabla de 4 filas con `assertSame(...)` sobre `Inventory_Pusher::owner()`.
   **Prove-it-catches**: cambiar `owner()` por `get_option('push_orders_enabled') ? 'invoice' :
   'adjustment'` ⇒ la fila `true/false` devuelve `invoice` y el test falla. Re-aplicar ⇒ verde.
2. Test `T30.22 valor inválido ⇒ auto + warning`: `update_option('...stock_owner','nope');` ⇒
   `assertSame('adjustment', owner())` con los defaults y `assertStringContainsString` en el log.
3. Test de Guard 2 (dentro de `T30.21`): `owner='invoice'` + producto vinculado `manage_stock=true` ⇒
   `push_delta()` = `['reason' => 'invoice_owner']` y **0** llamadas a `POST /inventory-adjustments`.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `auto` mal derivado reintroduzca B3 (R1/DR17). **Guarda**: la condición doble + el test de
las 4 combinaciones; el prove-it-catches lo ancla.

**Estimación**: L (4 h).

---

### T2.2 — Marca de ajuste emitido `_alegra_stock_adjusted_at`

**Objetivo**: que el pusher escriba un timestamp por producto **sólo** cuando emitió (o reconoció como ya
emitido) un ajuste, para que la guarda manual (`T2.5`/`T2.6`) sepa si hubo un primer movimiento.

**Descripción técnica**: hoy no existe ninguna marca de "ajuste emitido" (grep
`_alegra_stock_adjusted` = 0). Sin ella, la apertura manual en `adjustment` no puede advertir el doble
descuento. La marca se escribe **únicamente** en las ramas `ok` (`:271-273`) y `already_applied`
(`:244-246`) de `push_delta()`; **no** en `api_error`, `blocked`, `locked`, `baseline_unverified`,
`in_sync`, `disabled`, `not_linked`, `not_manageable`, `syncing`. Corrige **C2**: el diseño también
nombra un meta por pedido `_alegra_stock_adjusted`, pero `push_delta()` no conoce el pedido ⇒ **no se
crea**; la guarda usa el timestamp per-product contra `get_date_created()`. Cubre REQ-OWN-01/06 y D1
§2.2.1.

**Desarrollo técnico**:

Archivo: `includes/Sync/Inventory_Pusher.php`.

1. **Helpers** (nuevos, junto a `clear_pending()` `:73-76`):

```php
    /**
     * D1 §2.2.1: marca que el plugin emitió (o reconoció como ya emitido) un
     * ajuste para este producto. La lee la guarda de apertura manual.
     */
    public static function mark_adjusted(int $product_id): void
    {
        update_post_meta($product_id, self::META_ADJUSTED_AT, time());
    }

    /**
     * Timestamp del último ajuste emitido, o 0 si nunca.
     */
    public static function adjusted_at(int $product_id): int
    {
        $value = get_post_meta($product_id, self::META_ADJUSTED_AT, true);
        return ($value === '' || $value === false || $value === null) ? 0 : (int) $value;
    }
```

2. **Rama `already_applied`** — ANTES (`:242-247`):

```php
            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }
```

DESPUÉS (agrega la marca; `T3.4` cambia la firma de `adjustment_already_exists`):

```php
            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                self::mark_adjusted($id);   // D1 §2.2.1: hubo (o ya había) un ajuste
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }
```

3. **Rama `ok`** — ANTES (`:270-273`):

```php
            // Éxito: recién acá WC y Alegra acuerdan.
            self::set_synced($id, $new_qty);
            self::clear_pending($id);
            return ['pushed' => true, 'delta' => $delta, 'reason' => 'ok'];
```

DESPUÉS:

```php
            // Éxito: recién acá WC y Alegra acuerdan.
            self::set_synced($id, $new_qty);
            self::clear_pending($id);
            self::mark_adjusted($id);       // D1 §2.2.1
            return ['pushed' => true, 'delta' => $delta, 'reason' => 'ok'];
```

**Orden de operaciones**: la marca va **después** de `set_synced`/`clear_pending` y **antes** del `return`
de las dos ramas que representan un ajuste existente. No se toca ninguna otra rama.

**Resultado esperado (aceptación verificable)**:
- Un `push_delta()` que emite (`reason='ok'`) ⇒ `adjusted_at($id) > 0`.
- Un `push_delta()` reconocido (`reason='already_applied'`) ⇒ `adjusted_at($id) > 0`.
- Un `push_delta()` fallido (`api_error`/`blocked`/`locked`/`baseline_unverified`) ⇒ `adjusted_at($id) === 0` (no marca).
- `in_sync`/`disabled`/`not_linked`/`not_manageable` ⇒ `adjusted_at($id) === 0`.

**Dependencias**: `T2.1` (const `META_ADJUSTED_AT`).

**Trazabilidad**: REQ-OWN-01, REQ-OWN-06; D1 §2.2.1; DR1, DR21; corrección **C2**.

**Verificación**:
1. Test `T30.28 _alegra_stock_adjusted_at escrito`: con un mock que acepta el ajuste, `push_delta()` ⇒
   `assertSame('ok', $r['reason'])` y `assertGreaterThan(0, Inventory_Pusher::adjusted_at($pid))`.
   **Prove-it-catches**: mover `mark_adjusted()` fuera de la rama `ok` (o quitarla) ⇒ el assert falla.
2. Sub-assert: forzar `POST /inventory-adjustments` a 500 ⇒ `reason='api_error'` y
   `assertSame(0, Inventory_Pusher::adjusted_at($pid))` (no marca un fallo).
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que la marca se escriba de más (falso positivo) y dispare una advertencia innecesaria
(DR21). **Guarda**: se escribe sólo en las dos ramas de ajuste real; el test (2) lo ancla.

**Estimación**: M (2 h).

---

### T2.3 — Override `'open'` depende de `owner()` (no de `open_invoice_on_paid`)

**Objetivo**: que `create_invoice()` abra el borrador pre-existente de un pedido pagado **sólo** por el
dueño + el pago, quitando la dependencia de `open_invoice_on_paid` (corrección C3).

**Descripción técnica**: en HEAD, `Orders.php:88-90` exige las **tres** condiciones
(`owner()==='invoice'` **y** `open_invoice_on_paid` **y** `is_paid()`). Con `stock_owner=invoice` explícito
y `open_invoice_on_paid=false`, un pedido pagado **no** abre su borrador ⇒ la factura queda `draft` y no
mueve stock, mientras el pusher (dueño `invoice`) no emite ajustes ⇒ **ningún mecanismo mueve stock** (B3).
La decisión del diseño §2.5 es que `invoice` **fuerce** el comportamiento: abrir cuando hay pago. El
override de `:122-126` ya cumple (sólo dueño + pago) y **no cambia**. Cubre REQ-OWN-04 y REQ-OWN-07.

**Segundo hueco (B1/Oracle#1, CRÍTICO).** El override de `:122-126` **no es la única vía** de `'open'`.
`create_invoice_with_payment()` (`:494-540`) calcula `$will_record_payment` (`:508-510`) **sin mirar
`owner()`** y pasa `'open'` a `create_invoice()` en `:512`. Como el override de `:122-126` sólo actúa con
`$status_override === null`, el `'open'` forzado **gana** y la factura nace `open` **aunque el dueño sea
`adjustment`** ⇒ Alegra descuenta stock, y el pusher (dueño `adjustment`) emite además el ajuste ⇒
**doble descuento automático** (el bug titular). `design.md:269` afirma que esta vía está gated por dueño:
**es falso en HEAD**. Las guardas `T2.5`/`T2.6` **no** cubren este camino (sólo cubren
`ajax_open_invoice_impl`/`ajax_record_payment_impl`); tampoco `ajax_sync_single` (`:3194`, vía
`Controller.php:386`), ni el bulk (`ajax_sync_pending_page_impl`, `:3942`), ni `sync_recent()`
(`Orders.php:1211`). El gate va acá, en `create_invoice_with_payment()`, y aplica en **ambas** ramas de
G1.

**Desarrollo técnico**:

Archivo: `includes/Sync/Orders.php`.

**Cambio 1 — early-return del borrador pre-existente (`:88-90`)** — ANTES (`:84-107`, fragmento `:88-93`):

```php
                if (Inventory_Pusher::owner() === 'invoice'
                    && (bool) get_option('alegra_connector_open_invoice_on_paid', true)
                    && $order->is_paid()) {
                    $opened = $this->ensure_invoice_open($alegra_id, true);
                    if (!is_wp_error($opened)) {
                        $this->persist_invoice_status($order, $opened);
                    } elseif ($this->logger) {
```

DESPUÉS:

```php
                // D1 §2.5: con dueño invoice la apertura al pagar es un contrato
                // del modo, no una preferencia. Se quita la dependencia de
                // open_invoice_on_paid (T2.8 lo coerciona ON en la UI/servidor).
                if (Inventory_Pusher::owner() === 'invoice'
                    && $order->is_paid()) {
                    $opened = $this->ensure_invoice_open($alegra_id, true);
                    if (!is_wp_error($opened)) {
                        $this->persist_invoice_status($order, $opened);
                        $this->baseline_products_for_invoice($order);   // T3.3
                    } elseif ($this->logger) {
```

> **Nota cross-fase.** La llamada a `baseline_products_for_invoice()` la agrega `T3.3` (Fase 3). Si `T2.3`
> se implementa antes, se deja el `if` sin esa línea y `T3.3` la inserta. No duplicar.

**Cambio 2 — `create_invoice_with_payment()` (`:508-512`) [B1/Oracle#1, CRÍTICO]** — ANTES:

```php
        $will_record_payment = !in_array($payment_account, ['', '0'], true)
            && (string) $order->get_meta('_alegra_payment_id', true) === ''
            && $order->is_paid();

        $invoice_result = $this->create_invoice($order, $will_record_payment ? 'open' : null);
```

DESPUÉS:

```php
        $will_record_payment = !in_array($payment_account, ['', '0'], true)
            && (string) $order->get_meta('_alegra_payment_id', true) === ''
            && $order->is_paid();

        // B1/Oracle#1 (REVIEW-momus + REVIEW-oracle DEF-1): el `'open'` de este
        // camino NO estaba gated por owner(); con cuenta de pago + pedido pagado
        // la factura nacía `open` y Alegra descontaba stock, y el pusher emitía
        // ADEMÁS el ajuste ⇒ doble descuento automático en adjustment. La
        // apertura SÓLO es válida con dueño invoice (la factura es el mecanismo).
        $will_open = $will_record_payment && Inventory_Pusher::owner() === 'invoice';
        $invoice_result = $this->create_invoice($order, $will_open ? 'open' : null);
```

> **Por qué alcanza con forzar `draft` (no hace falta tocar `record_payment_for_invoice()`).** Con
> `owner=adjustment`, `$will_record_payment` puede seguir siendo `true`; la factura queda `draft` y
> `record_payment_for_invoice()` se llama con `$allow_open_draft = false` (default, `Orders.php:525-526`).
> `ensure_invoice_open()` devuelve `draft_invoice_not_opened` y `skip_draft_payment()` (`:576-580`)
> **no** postea el pago y deja la nota explicativa. El ajuste ya movió (o moverá) el stock ⇒ **un solo
> mecanismo**. Con `owner=invoice`, el comportamiento no cambia (`'open'` + pago).

**Override de `:122-126`** — **verificado, NO se toca** (sólo aplica cuando `$status_override === null`;
el hueco real estaba en `:512`, cerrado arriba):

```php
            if ($status_override === null
                && Inventory_Pusher::owner() === 'invoice'
                && $order->is_paid()) {
                $status_override = 'open';
            }
```

**Orden de operaciones**: el Cambio 1 es una condición menos en un `if`; no mueve el orden de llamadas.
El Cambio 2 agrega el gate de `owner()` **antes** de `create_invoice()` (`:512`), sin cambiar el resto del
flujo: `record_payment_for_invoice()` sigue decidiendo el pago y `skip_draft_payment()` cubre el `draft`.
`ensure_invoice_open()` sigue abriendo sólo el borrador (`:850-913`).

**Resultado esperado (aceptación verificable)**:
- `stock_owner=invoice` + `push_orders_enabled=false` + `open_invoice_on_paid=false` + pedido pagado con
  `_alegra_invoice_id` draft ⇒ `create_invoice()` abre el borrador (el mock deja de ver `draft`).
- `stock_owner=invoice` + pedido pagado sin `_alegra_invoice_id` ⇒ nace `open` (override `:122-126`).
- `stock_owner=adjustment` + pedido pagado ⇒ **nunca** fija `open` (ni el override, ni el early-return,
  **ni `create_invoice_with_payment()`**).
- **B1**: `stock_owner=adjustment` + cuenta de pago configurada + pedido pagado +
  `create_invoice_with_payment()` (y sus tres call sites: `ajax_sync_single`, el bulk
  `ajax_sync_pending_page_impl`, `sync_recent`) ⇒ la factura queda **`draft`**, `record_payment_for_invoice`
  devuelve `skip_draft_payment` y hay **0** `POST /payments`; **0** doble descuento.
- `grep -n 'open_invoice_on_paid' includes/Sync/Orders.php` ⇒ **0 matches**.

**Dependencias**: `T2.1`. `T1.1` (H-A: el mock modela `draft`/`open`) para poder observarlo.

**Trazabilidad**: REQ-OWN-04, REQ-OWN-07, REQ-OWN-01; D1 §2.5; corrección **C3**; hallazgo **B1/Oracle#1**;
`tasks.md` I8.

**Verificación**:
1. Test `T30.24 invoice abre; adjustment no`: con `stock_owner=invoice` + pagado + draft ⇒
   `assertSame('open', $order->get_meta('_alegra_invoice_status'))`; con `stock_owner=adjustment` ⇒
   `assertSame('draft', ...)`. **Prove-it-catches**: reponer `&& get_option('open_invoice_on_paid')` ⇒ con
   la opción en `false` el primer assert falla.
2. Test `T30.24c adjustment no auto-abre por la vía "Facturar" (B1/Oracle#1)`: con
   `stock_owner=adjustment`, `payment_account` configurada y pedido pagado, invocar
   `create_invoice_with_payment()` y sus tres call sites (`Controller::sync_entity('order', id, 'complete')`
   vía `ajax_sync_single`, `ajax_sync_pending_page` y `sync_recent`) ⇒ en los cuatro casos
   `assertSame('draft', $order->get_meta('_alegra_invoice_status'))`, **0** `POST /inventory-adjustments`
   duplicados y **0** `POST /payments` (el ajuste ya movió el stock). **Prove-it-catches**: volver a
   `$will_record_payment ? 'open' : null` ⇒ la factura nace `open` y el assert de `draft` falla.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que un comerciante con `invoice` + `open_invoice_on_paid=false` vea un cambio de
comportamiento (la factura ahora se abre). **Guarda**: es un cambio intencional del modo; la UI lo
anota (`T6.2`) y se documenta en `CHANGELOG.md` (`T7.3`).

**Estimación**: M (3 h).

---

### T2.4 — `adjustment` no crea factura automática · `BLOQUEADO(Fase 0.2 / G1)`

**Objetivo**: en **Rama B de G1** (el `draft` **sí** mueve stock), que el modo `adjustment` **no** cree la
factura automáticamente; la factura sólo se crea por acción manual explícita.

**Descripción técnica**: si el `draft` mueve stock, la creación de la factura descuenta **antes** de que
la guarda de apertura manual (`T2.5`) pueda advertir ⇒ la guarda llega tarde. La mitigación de Rama B
(design §2.2.1 / §13) es que `create_invoice()` se salte la creación cuando `owner()==='adjustment'`
**salvo** que esté en contexto explícito (`Write_Gate::is_explicit()`, confirmado en `Write_Gate.php:137`),
que es el que usan las acciones manuales. En **Rama A** (esperada: `draft` no mueve) esta tarea es un
**no-op** y la factura `draft` es un documento sin efecto de stock. Cubre REQ-OWN-04.

**Desarrollo técnico**:

Archivo: `includes/Sync/Orders.php`, dentro de `create_invoice()` (`:63-193`), **después** del guard de
`_alegra_invoice_id` (`:82-107`) y del pre-chequeo de factura `open` (`:109-117`), **antes** de
`prepare_invoice_data()` (`:128`).

ANTES (`:119-128`, el comentario del override + el build):

```php
            // D2 (REQ-INV-01 rama b): con la factura como dueña, un pedido pagado
            // nace `open` para que Alegra descuente stock nativo. El borrador no
            // mueve stock (G1). Sólo aplica cuando el dueño es `invoice`.
            if ($status_override === null
                && Inventory_Pusher::owner() === 'invoice'
                && $order->is_paid()) {
                $status_override = 'open';
            }

            $data = $this->prepare_invoice_data($order, $status_override);
```

DESPUÉS (bloque condicional de Rama B, gated por el resultado de G1):

```php
            // G1 Rama B (BLOQUEADO): si el draft mueve stock, crear la factura
            // en modo adjustment descuenta antes de que la guarda manual pueda
            // advertir. La factura sólo se crea por acción manual explícita
            // (run_explicit). En Rama A este bloque NO se agrega.
            if ($status_override === null
                && Inventory_Pusher::owner() === 'adjustment'
                && !\Alegra\Connector\Write_Gate::is_explicit()) {
                $this->logger->info('Factura no creada automáticamente en modo adjustment (G1 Rama B)', [
                    'order_id' => $order_id,
                ]);
                return ['id' => '', 'skipped' => true, 'manual_only' => true, 'reason' => 'adjustment_manual_only'];
            }

            // D2 (REQ-INV-01 rama b): con la factura como dueña, un pedido pagado
            // nace `open` para que Alegra descuente stock nativo. El borrador no
            // mueve stock (G1). Sólo aplica cuando el dueño es `invoice`.
            if ($status_override === null
                && Inventory_Pusher::owner() === 'invoice'
                && $order->is_paid()) {
                $status_override = 'open';
            }

            $data = $this->prepare_invoice_data($order, $status_override);
```

> **Oracle#10 (REVIEW-oracle DEF-10) — el skip NO debe ser retriable.** Hoy
> `Invoice_Failure::classify()` (`fase-4:114-154`) no tiene rama para un array `skipped` sin `id`: cae al
> fallback `failed_retriable`/`persist=true` y el cron lo reintenta hasta el tope (cada intento vuelve a
> saltar). `T4.1` **DEBE** agregar una rama temprana (antes del fallback):
> ```php
> if (is_array($result) && !empty($result['skipped'])) {
>     return ['state' => 'skipped', 'code' => (string) ($result['reason'] ?? 'skipped'),
>             'message' => '', 'retriable' => false, 'persist' => false];
> }
> ```
> Es **permanente/manual-only**: no se persiste en la cola y **no** lo reintenta el cron. Agregar el test
> negativo en `T4.1` (un `['skipped'=>true,'manual_only'=>true]` ⇒ `retriable=false`, `persist=false`).

**Opciones por rama de G1:**

| Rama G1 | Comportamiento | Código |
|---|---|---|
| **A** (esperada: `draft` no mueve) | Sin cambios; el diseño actual aplica. | `T2.4` no agrega el bloque. |
| **B** (`draft` mueve) | `create_invoice()` no crea automáticamente en `adjustment`; la creación manual (vía `run_explicit`) sí. | `T2.4` agrega el bloque. |

**Orden de operaciones**: el bloque va **antes** de `prepare_invoice_data()` y **después** del
pre-chequeo de factura `open`, para no crear y no gastar una llamada a Alegra.

**Resultado esperado (aceptación verificable)**:
- **Rama A**: no hay cambio; `T30.24` (de `T2.3`) sigue verde.
- **Rama B**: `stock_owner=adjustment` + pedido pagado + `create_invoice()` **automático** (sin
  `run_explicit`) ⇒ `['reason' => 'adjustment_manual_only']` y **0** `POST /invoices`; el mismo llamado
  dentro de `Write_Gate::run_explicit()` ⇒ crea la factura.
- En ambos casos, un pedido **no pagado** nunca fija `open`.
- **B1 cerrado en ambas ramas**: la vía automática de `create_invoice_with_payment()` (`T2.3`, Cambio 2)
  fuerza `draft` con dueño `adjustment`; `T2.4` (Rama B) además bloquea la creación automática por
  `create_invoice()`. No queda ninguna vía automática de `'open'` en `adjustment`.
- El marcador `adjustment_manual_only` se clasifica como `skipped`/no-retriable (Oracle#10, ver arriba).

**Dependencias**: `T2.3`; **`BLOQUEADO(Fase 0.2 / G1)`** para decidir si el bloque se agrega. `T1.1`
(H-A: `alegra_mock_set_draft_moves_stock(true)` para simular Rama B).

**Trazabilidad**: REQ-OWN-04; D1 §2.2.1 / §13; DR1; gate **G1**.

**Verificación**:
1. Test `T30.24b Rama B: adjustment no crea factura` (sólo si G1=Rama B): con
   `alegra_mock_set_draft_moves_stock(true)`, `stock_owner=adjustment`, pedido pagado, `create_invoice()`
   sin `run_explicit` ⇒ `assertSame('adjustment_manual_only', $r['reason'])` y `assertSame(0, $mock['invoice_posts'])`.
   Dentro de `run_explicit` ⇒ `assertArrayHasKey('id', $r)`.
2. **Prove-it-catches**: quitar el `!is_explicit()` ⇒ el path manual también se saltea y el segundo
   assert falla.
3. Sub-assert Oracle#10 (en `T4.1`, cross-fase): `Invoice_Failure::classify(['skipped' => true,
   'manual_only' => true, 'reason' => 'adjustment_manual_only'])` ⇒ `retriable === false` y
   `persist === false`; un pedido con ese resultado **no** aparece en `retry_failed_invoices()`.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: que la creación manual legítima quede bloqueada. **Guarda**: la excepción
`Write_Gate::is_explicit()` (los AJAX manuales corren bajo `run_explicit`); el test (1) lo cubre.

**Estimación**: M (3 h).

---

### T2.5 — Guarda server-enforced en `ajax_open_invoice_impl`

**Objetivo**: que abrir una factura desde el admin en modo `adjustment`, cuando el pedido ya tiene un
ajuste emitido, exija `confirm_double_discount=1`; con el flag, proceda + nota + log; sin él, responda
`double_discount_confirm_required`. Nunca un `confirm()` de cliente, nunca un bloqueo duro.

**Descripción técnica**: `ajax_open_invoice_impl()` (`Admin_Dashboard.php:2845-2895`) hoy abre la factura
(`ensure_invoice_open` `:2864`) sin mirar el dueño ni si hubo ajuste (A3/A7). La decisión fuerte de D1
§2.2.1 es **advertencia + confirmación server-enforced**: el servidor exige el flag POST (auditable, no
salteable por un cliente viejo), deja nota y log; respeta el cuerpo de REQ-OWN-06 ("NO DEBE bloquear").
La advertencia se muestra **sólo** si `owner()==='adjustment'` **y** el pedido tiene evidencia de ajuste
emitido; si no, procede sin advertir. Corrige **C2** (sólo `_alegra_stock_adjusted_at`) y **C8**.

> **Oracle#5 (REVIEW-oracle DEF-5) — alcance REAL de la garantía (honestidad).** Esta guarda es
> **server-enforced SÓLO en la UI/AJAX de WordPress**. Abrir la factura o registrar el pago desde la
> **UI o la API de Alegra** **NO** pasa por acá (no hay hook de WC) ⇒ la frase "imposible por
> construcción"/"no salteable" de `design.md:268-270`/`:292-293` es **falsa** y DEBE suavizarse a
> **"no salteable desde la UI del plugin"**. La mitigación real del camino Alegra-UI es el **informe de
> divergencia** (`T3.5`/`T5.1`/`T5.2`): el poll detecta `W≠A` y lo reporta. En `adjustment` la factura
> además se fuerza `draft` (`T2.3`), así que una apertura accidental exige una acción explícita en
> Alegra. **No** se promete una garantía que el código no puede dar.
>
> **Alcance del camino automático.** Las vías que crean la factura sin pasar por este AJAX
> (`ajax_sync_single` `:3194`, el bulk `:3942`, `sync_recent()` `Orders.php:1211`) **no** las cubre esta
> guarda; las cierra el gate de `create_invoice_with_payment()` de `T2.3` (Cambio 2), que en
> `adjustment` fuerza `draft`.

**Desarrollo técnico**:

Archivo: `includes/Sync/Orders.php` — helper nuevo (lo consumen `T2.5` y `T2.6`):

```php
    /**
     * D1 §2.2.1 (C2): ¿el plugin emitió un ajuste para alguna línea de este
     * pedido DESPUÉS de su creación? `push_delta()` no conoce el pedido, así que
     * la evidencia es el timestamp por producto `_alegra_stock_adjusted_at`.
     * O(líneas del pedido); no depende del orden de hooks.
     */
    public function order_has_emitted_adjustment(\WC_Order $order): bool
    {
        $created = $order->get_date_created();
        $since   = $created instanceof \DateTimeInterface ? $created->getTimestamp() : 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }
            if (Inventory_Pusher::adjusted_at((int) $product->get_id()) > $since) {
                return true;
            }
        }
        return false;
    }
```

Archivo: `admin/Admin/Admin_Dashboard.php` — insertar la guarda en `ajax_open_invoice_impl()` después del
chequeo de `_alegra_invoice_id` (`:2858-2861`) y antes de `ensure_invoice_open()` (`:2863-2864`).

ANTES (`:2858-2864`):

```php
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '') {
            wp_send_json_error(['message' => __('El pedido no tiene una factura de Alegra vinculada.', 'alegra-connector')]);
        }

        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
        $result = $orders_sync->ensure_invoice_open($alegra_invoice_id);
```

DESPUÉS:

```php
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '') {
            wp_send_json_error(['message' => __('El pedido no tiene una factura de Alegra vinculada.', 'alegra-connector')]);
        }

        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);

        // D1 §2.2.1 / REQ-OWN-06: con dueño adjustment y un ajuste ya emitido,
        // abrir la factura descontaría dos veces. Confirmación SERVER-ENFORCED
        // (no un confirm() de cliente, no un bloqueo duro): sin el flag no se
        // llama a Alegra; con el flag se procede y queda nota + log.
        $confirm = (string) ($_POST['confirm_double_discount'] ?? '') === '1';
        if (\Alegra\Connector\Sync\Inventory_Pusher::owner() === 'adjustment'
            && $orders_sync->order_has_emitted_adjustment($order)
            && !$confirm) {
            wp_send_json_error([
                'code'    => 'double_discount_confirm_required',
                'message' => __('Los ajustes de inventario están activos y ya se empujó un ajuste por este pedido. Abrir la factura descontará dos veces. Confirmá para continuar.', 'alegra-connector'),
            ]);
        }

        $result = $orders_sync->ensure_invoice_open($alegra_invoice_id);
```

Y tras el éxito (después de `persist_invoice_status()` `:2883`), la nota + log cuando se confirmó:

```php
        if ($confirm
            && \Alegra\Connector\Sync\Inventory_Pusher::owner() === 'adjustment') {
            $order->add_order_note(__(
                '[Alegra] Apertura manual con ajuste ya emitido: el comerciante confirmó el doble descuento.',
                'alegra-connector'
            ));
            $this->logger->warning('Apertura manual en modo adjustment con ajuste emitido (doble descuento confirmado)', [
                'order_id'   => $order_id,
                'invoice_id' => $alegra_invoice_id,
                'owner'      => 'adjustment',
            ]);
        }
```

**Orden de operaciones**: (1) resolver `owner()`; (2) si `adjustment` **y** hay ajuste **y** falta el flag
⇒ `wp_send_json_error` **sin** tocar Alegra; (3) si no, proceder; (4) si se confirmó, nota + log. El
`check_ajax_referer` (`:2847`) y `manage_woocommerce` (`:2848`) **preceden** todo (REQ-OWN-06 seguridad).

**Resultado esperado (aceptación verificable)**:
- `owner=adjustment` + pedido con ajuste + **sin** `confirm_double_discount` ⇒ respuesta de error con
  `code='double_discount_confirm_required'` y **0** llamadas a la API.
- Mismo caso **con** `confirm_double_discount=1` ⇒ la factura se abre, el pedido queda con la nota y el
  log registra el warning.
- `owner=adjustment` + pedido **sin** ajuste ⇒ procede sin advertencia.
- `owner=invoice` ⇒ procede sin advertencia (REQ-OWN-06).
- Sin nonce/capacidad ⇒ error de permiso (ya existente, `:2847-2850`).

**Dependencias**: `T2.1`, `T2.2`. `T1.4` (H-E: `WC_Order_Item::get_product()`) — **ver C13**: hoy
`WC_Order_Item` (`wp-stubs.php:1207-1232`) **no** tiene `get_product()`; sin el stub, `order_has_emitted_adjustment()`
no es testeable. `T6.8` (Fase 6) agrega el diálogo JS que reenvía el flag.

**Trazabilidad**: REQ-OWN-06, NFR-06; D1 §2.2.1; DR1, DR19; correcciones **C2**, **C6**, **C8**;
`tasks.md` R5/R20.

**Verificación**:
1. Test `T30.25 confirmación server-enforced (open)`: sembrar `_alegra_stock_adjusted_at` posterior a la
   creación del pedido; POST sin flag ⇒ `assertSame('double_discount_confirm_required', $resp['code'])` y
   `assertSame(0, $mock['open_calls'])`; POST con `confirm_double_discount=1` ⇒ éxito + nota.
   **Prove-it-catches**: mover la guarda al JS (o quitarla) ⇒ el primer assert falla.
2. Sub-assert: sin ajuste emitido ⇒ no aparece el código (procede).
3. Sub-assert: `owner=invoice` ⇒ no aparece el código.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: que la advertencia moleste en `invoice` (DR19) o que un cliente viejo no mande el flag.
**Guarda**: sólo en `adjustment` **y** con ajuste emitido; el cliente viejo recibe el código y el JS
(`T6.8`) reenvía. La acción no se bloquea (con flag procede).

**Estimación**: L (4 h).

---

### T2.6 — Guarda server-enforced en `ajax_record_payment_impl`

**Objetivo**: que registrar un pago desde el admin en modo `adjustment`, cuando el pedido ya tiene un
ajuste emitido, exija `confirm_double_discount=1`, con la **misma** semántica que `T2.5`.

**Descripción técnica**: `ajax_record_payment_impl()` (`Admin_Dashboard.php:2902-3028`) abre el borrador
si hace falta (`ensure_invoice_open` `:2944`, manual) y postea el pago sin mirar el dueño (A4). Registrar
pago abre la factura ⇒ mismo riesgo de doble descuento que `T2.5`. Se reusa `order_has_emitted_adjustment()`
y el mismo código/flag. Cubre REQ-OWN-06 y NFR-06.

> **Oracle#5 (REVIEW-oracle DEF-5).** Mismo alcance real que `T2.5`: la guarda es **server-enforced sólo
> en la UI/AJAX de WP**. Registrar el pago desde la UI/API de Alegra **no** pasa por acá; la mitigación
> es el informe de divergencia (`T3.5`/`T5.1`/`T5.2`). No prometer más de lo que el código garantiza.

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php`, dentro de `ajax_record_payment_impl()` después del guard de
`_alegra_invoice_id` (`:2920-2923`) y antes del guard de `_alegra_payment_id` (`:2925-2929`).

ANTES (`:2920-2931`):

```php
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '' || $alegra_invoice_id === null) {
            wp_send_json_error(['message' => __('Primero crea la factura en Alegra.', 'alegra-connector')]);
        }

        // Check if payment already recorded
        $existing_payment = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment !== '' && $existing_payment !== null) {
            wp_send_json_error(['message' => __('El pago ya está registrado en Alegra.', 'alegra-connector')]);
        }

        $account_id = (string) get_option('alegra_connector_payment_account_id', '');
```

DESPUÉS:

```php
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '' || $alegra_invoice_id === null) {
            wp_send_json_error(['message' => __('Primero crea la factura en Alegra.', 'alegra-connector')]);
        }

        // Check if payment already recorded
        $existing_payment = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment !== '' && $existing_payment !== null) {
            wp_send_json_error(['message' => __('El pago ya está registrado en Alegra.', 'alegra-connector')]);
        }

        // D1 §2.2.1 / REQ-OWN-06: misma guarda que ajax_open_invoice_impl. Registrar
        // el pago abre la factura; con dueño adjustment y ajuste emitido, exige
        // confirmación server-enforced.
        $orders_sync = new \Alegra\Connector\Sync\Orders($this->api, $this->logger);
        $confirm = (string) ($_POST['confirm_double_discount'] ?? '') === '1';
        if (\Alegra\Connector\Sync\Inventory_Pusher::owner() === 'adjustment'
            && $orders_sync->order_has_emitted_adjustment($order)
            && !$confirm) {
            wp_send_json_error([
                'code'    => 'double_discount_confirm_required',
                'message' => __('Los ajustes de inventario están activos y ya se empujó un ajuste por este pedido. Registrar el pago abrirá la factura y descontará dos veces. Confirmá para continuar.', 'alegra-connector'),
            ]);
        }

        $account_id = (string) get_option('alegra_connector_payment_account_id', '');
```

Y la nota + log tras el éxito, junto a la nota de pago (`:3013-3016`):

```php
        if ($confirm
            && \Alegra\Connector\Sync\Inventory_Pusher::owner() === 'adjustment') {
            $order->add_order_note(__(
                '[Alegra] Registro de pago con ajuste ya emitido: el comerciante confirmó el doble descuento.',
                'alegra-connector'
            ));
            $this->logger->warning('Registro de pago en modo adjustment con ajuste emitido (doble descuento confirmado)', [
                'order_id'   => $order_id,
                'invoice_id' => $alegra_invoice_id,
                'owner'      => 'adjustment',
            ]);
        }
```

> **Nota de no-duplicación.** El `$orders_sync` ya se construye en `:2936`; `T2.6` lo mueve antes de la
> guarda y **elimina** la construcción duplicada de `:2936` (una sola instancia).

**Orden de operaciones**: idéntico a `T2.5`, después del guard de pago existente (para no advertir si ya
está pago) y antes de `ensure_invoice_open()` (`:2944`).

**Resultado esperado (aceptación verificable)**:
- `owner=adjustment` + ajuste emitido + sin flag ⇒ `double_discount_confirm_required`, **0** llamadas a
  `ensure_invoice_open`/`POST /payments`.
- Con flag ⇒ abre (si hace falta) + registra el pago + nota + log.
- `owner=adjustment` sin ajuste, o `owner=invoice` ⇒ procede sin advertencia.

**Dependencias**: `T2.1`, `T2.2`, `T2.5` (comparte `order_has_emitted_adjustment()`).

**Trazabilidad**: REQ-OWN-06, NFR-06; D1 §2.2.1; DR1; correcciones **C2**, **C6**, **C8**.

**Verificación**:
1. Test `T30.26 confirmación server-enforced (pago)`: mismo setup que `T30.25` pero invocando
   `ajax_record_payment`; sin flag ⇒ código y 0 POST; con flag ⇒ pago registrado + nota.
   **Prove-it-catches**: quitar la guarda ⇒ el assert del código falla.
2. Sub-assert: el guard de `_alegra_payment_id` sigue ganando (un pago ya registrado no llega a la guarda).
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: duplicar la construcción de `Orders` o dejar dos rutas de apertura divergentes.
**Guarda**: una sola instancia de `$orders_sync`; helper compartido con `T2.5`.

**Estimación**: M (3 h).

---

### T2.7 — Limpieza lazy del `pending` + epoch de modo

**Objetivo**: que cambiar de dueño no arrastre un `pending` sucio de un modo al otro, y que el cambio
surta efecto en el próximo movimiento sin migración masiva.

**Descripción técnica**: al pasar a `invoice`, un `_alegra_stock_push_pending` de un push fallido del modo
`adjustment` es basura: el poll no debe intentar reconciliar un ajuste. La decisión (D1 §2.3) es **limpieza
lazy en el poll** (`owner()==='invoice' && pending!=='' ⇒ clear_pending`), O(ítems), sin meta query; y al
guardar Ajustes, escribir `alegra_connector_stock_owner_epoch = time()` y borrar los conteos cacheados.
El dueño es runtime: no se persiste. Corrige el hallazgo de "cambio de dueño arrastra pending" (R11).
Cubre REQ-OWN-05.

**Desarrollo técnico**:

1. **Opción** `alegra_connector_stock_owner_epoch` (int, default `0`, autoload **no**): la siembra `T1.5`
   (`$defaults` + `$non_autoload` + `uninstall.php`).

2. **Limpieza lazy en el poll** (`includes/Sync/Products.php`). Se inserta **antes** de la clasificación de
   estado (hoy `:1327-1334`; `T3.1` reescribe ese bloque y **debe preservar** este paso como sección (A)
   del algoritmo del diseño §3.3).

ANTES (`:1327-1334`):

```php
                    // D2/D6/FIX-8 (REQ-INV-01, NFR-07): el poll NUNCA re-infla
                    // y TAMPOCO se muere de hambre.
                    $synced  = Inventory_Pusher::synced($product_id);
                    $pending = Inventory_Pusher::pending($product_id);

                    $needs_reconcile = $product->get_manage_stock()
                        && ($pending !== ''
                            || ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced));
```

DESPUÉS (con el paso (A) al frente):

```php
                    // D2/D6/FIX-8 (REQ-INV-01, NFR-07): el poll NUNCA re-infla
                    // y TAMPOCO se muere de hambre.
                    $synced  = Inventory_Pusher::synced($product_id);
                    $pending = Inventory_Pusher::pending($product_id);

                    // (A) D1 §2.3 / REQ-OWN-05: un `pending` en modo invoice es
                    // basura de un modo anterior. Limpieza lazy, O(ítems), sin
                    // meta query. (T3.1 la conserva al reescribir el bloque.)
                    if (Inventory_Pusher::owner() === 'invoice' && $pending !== '') {
                        Inventory_Pusher::clear_pending($product_id);
                        $pending = '';
                    }

                    $needs_reconcile = $product->manage_stock ... // T3.1 reemplaza el resto
```

3. **Epoch + reset de cachés** (`admin/Admin/Admin_Dashboard.php`), en `register_settings()` junto a
   `:381-396`. El `sanitize_callback` de `stock_owner` corre al guardar; si el valor cambió, escribe el
   epoch y borra los conteos cacheados:

```php
        // D1 §2.3 / REQ-OWN-05: la opción nueva + epoch de modo.
        register_setting('alegra_connector_settings', 'alegra_connector_stock_owner', [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_stock_owner'],
            'default'           => 'auto',
        ]);
```

Y el método estático (junto a `sanitize_masked_secret`/`sanitize_reconcile_batch`):

```php
    /**
     * D1 §2.3: allowlist + epoch de modo. Si el dueño cambió, se escribe el
     * epoch y se borran los conteos cacheados (badge/divergencia). NO se recorre
     * el catálogo (NFR-04): el `pending` se limpia lazy desde el poll (T2.7).
     */
    public static function sanitize_stock_owner($value): string
    {
        $allowed = ['auto', 'invoice', 'adjustment'];
        $value   = in_array($value, $allowed, true) ? (string) $value : 'auto';

        if ((string) get_option('alegra_connector_stock_owner', 'auto') !== $value) {
            update_option('alegra_connector_stock_owner_epoch', time(), false);
            delete_option('alegra_connector_invoice_failures_count');
            delete_option('alegra_connector_stock_divergence');
        }

        return $value;
    }
```

> **Nota cross-fase.** `alegra_connector_invoice_failures_count` y `alegra_connector_stock_divergence`
> son opciones de Fases 4/5 (`T4.11`/`T5.1`). `T2.7` las borra por nombre (idempotente aunque no existan);
> no crea dependencia de código.

**Orden de operaciones**: el poll limpia **por ítem** (no cachea `owner()` entre ítems; el dueño es
runtime); el epoch se escribe **sólo** cuando el valor cambia (no en cada guardado).

**Resultado esperado (aceptación verificable)**:
- Producto con `pending` sucio + `stock_owner=invoice` ⇒ un poll lo deja `pending=''`.
- `stock_owner=adjustment` + `pending` sucio ⇒ el poll **no** lo limpia (lo necesita para reconciliar).
- Guardar `stock_owner` con un valor distinto ⇒ `alegra_connector_stock_owner_epoch > 0` y los conteos
  cacheados borrados; guardar el **mismo** valor ⇒ no re-escribe el epoch.
- Un valor inválido se guarda como `auto` (allowlist).

**Dependencias**: `T2.1`. `T1.5` (siembra de `stock_owner` + `stock_owner_epoch`).

**Trazabilidad**: REQ-OWN-05; D1 §2.3; DR11; `tasks.md` R11.

**Verificación**:
1. Test `T30.29 limpieza lazy del pending`: `update_post_meta($pid, '_alegra_stock_push_pending', 9);`
   + `stock_owner=invoice` ⇒ `sync_inventory_from_alegra()` y `assertSame('', Inventory_Pusher::pending($pid))`.
   **Prove-it-catches**: quitar el paso (A) ⇒ el pending queda y el test falla.
2. Test `T30.210 epoch + reset de cachés`: `update_option('alegra_connector_stock_owner','invoice');`
   `sanitize_stock_owner('adjustment')` ⇒ `assertGreaterThan(0, get_option('..._stock_owner_epoch'))` y
   `assertFalse(get_option('alegra_connector_invoice_failures_count'))`.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que la limpieza lazy se ejecute en modo `adjustment` y borre el `pending` que el reconciliador
necesita. **Guarda**: sólo con `owner()==='invoice'`; el test (1) cubre el caso negativo.

**Estimación**: M (3 h).

---

### T2.8 — Coerción server-side determinista de `open_invoice_on_paid` y `push_orders_enabled`

**Objetivo**: que `open_invoice_on_paid` quede **ON** cuando el dueño es `invoice` y **OFF** cuando es
`adjustment`, y que `push_orders_enabled` quede **ON** en `invoice`, para que no exista el estado "ningún
mecanismo mueve stock" (B3/Oracle#8). La coerción es **determinista** (Oracle#9), independiente del orden
de `register_setting`.

**Descripción técnica**: el modo `invoice` exige que la factura abra al pagar (si no, el pusher no emite y
la factura no mueve ⇒ B3) **y** que el plugin facture (si no, cero mecanismos ⇒ Oracle#8). El modo
`adjustment` exige lo contrario para la apertura. El diseño §2.5 decide coercer las opciones **en el
servidor** (además de la coerción visual de `T6.2`). Para que un POST directo a `options.php` no deje la
combinación inconsistente por el **orden** de procesamiento, la coerción se hace en el
`sanitize_callback` de **cada opción coercida**, leyendo el dueño efectivo del request (Oracle#9). Con
`auto` **no** se coerciona (compat total). Cubre REQ-OWN-04/07 y NFR-03.

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php`.

> **Oracle#9 (REVIEW-oracle DEF-9) — coerción determinista.** `wp-admin/options.php` itera los settings
> registrados y, para cada uno **ausente** del POST, llama `update_option($option, null)`. Si
> `open_invoice_on_paid` se procesa **después** de `stock_owner`, la coerción escrita dentro del sanitize
> de `stock_owner` se **pierde** (el resultado depende del orden de `register_setting` y de si el checkbox
> está en el DOM). Por eso la coerción **NO** va en `sanitize_stock_owner()`: pasa al sanitize de la
> **opción coercida**, leyendo el dueño **efectivo del request** (POST explícito, o la opción guardada).
> Así el orden de procesamiento deja de importar.

**1. `sanitize_stock_owner()` (T2.7) queda sólo con allowlist + epoch** (sin escribir otras opciones):

```php
    public static function sanitize_stock_owner($value): string
    {
        $allowed = ['auto', 'invoice', 'adjustment'];
        $value   = in_array($value, $allowed, true) ? (string) $value : 'auto';

        if ((string) get_option('alegra_connector_stock_owner', 'auto') !== $value) {
            update_option('alegra_connector_stock_owner_epoch', time(), false);
            delete_option('alegra_connector_invoice_failures_count');
            delete_option('alegra_connector_stock_divergence');
        }

        // Oracle#9: acá NO se coerciona otra opción (options.php lo pisaba según
        // el orden). La coerción vive en los sanitizers de abajo.
        return $value;
    }
```

**2. Helpers + sanitizers deterministas** (junto a `sanitize_masked_secret`/`sanitize_reconcile_batch`):

```php
    /**
     * Oracle#9 (DEF-9): dueño EFECTIVO determinista. Lee el POST explícito (lo
     * que el comerciante acaba de elegir) y cae a la opción guardada. No depende
     * del orden de register_setting ni de que options.php haya nullado la opción.
     */
    private static function effective_stock_owner_from_request(): string
    {
        $allowed = ['auto', 'invoice', 'adjustment'];
        $posted  = isset($_POST['alegra_connector_stock_owner'])
            ? (string) wp_unslash($_POST['alegra_connector_stock_owner'])
            : '';
        if (in_array($posted, $allowed, true)) {
            return $posted;
        }
        $stored = (string) get_option('alegra_connector_stock_owner', 'auto');
        return in_array($stored, $allowed, true) ? $stored : 'auto';
    }

    /**
     * D1 §2.5 / REQ-OWN-04: coerción server-side de open_invoice_on_paid.
     *   invoice    ⇒ ON  (la factura debe abrir al pagar; si no, nada mueve stock)
     *   adjustment ⇒ OFF (la factura NO debe abrir)
     *   auto       ⇒ sin coerción (compat total con 2.6.0)
     */
    public static function sanitize_open_invoice_on_paid($value): bool
    {
        $value = (bool) rest_sanitize_boolean($value);
        $owner = self::effective_stock_owner_from_request();
        if ($owner === 'invoice') {
            return true;
        }
        if ($owner === 'adjustment') {
            return false;
        }
        return $value;
    }

    /**
     * Oracle#8 (REVIEW-oracle DEF-8): con dueño invoice el plugin DEBE facturar.
     * Con push_orders_enabled=false, Guard 2 (Inventory_Pusher.php:170-173)
     * corta los ajustes y no hay facturación automática ⇒ CERO mecanismos mueven
     * stock. Se coerciona ON en modo invoice; en adjustment/auto no se toca.
     */
    public static function sanitize_push_orders_enabled($value): bool
    {
        $value = (bool) rest_sanitize_boolean($value);
        return self::effective_stock_owner_from_request() === 'invoice' ? true : $value;
    }
```

**3. Re-apuntar los `register_setting` existentes** (`Admin_Dashboard.php:381-384` y `:393-396`) a los
sanitizers nuevos:

```php
        register_setting('alegra_connector_settings', 'alegra_connector_push_orders_enabled', [
            'sanitize_callback' => [self::class, 'sanitize_push_orders_enabled'],
            'default' => false,
        ]);
        register_setting('alegra_connector_settings', 'alegra_connector_open_invoice_on_paid', [
            'sanitize_callback' => [self::class, 'sanitize_open_invoice_on_paid'],
            'default' => true,
        ]);
```

> **Estado inconsistente heredado (documentado).** Si una instalación llega con `stock_owner=invoice` +
> `push_orders_enabled=false` (DB directa, sin pasar por Ajustes), el comportamiento es **facturación
> manual**: Guard 2 corta los ajustes y no hay auto-facturación; el comerciante factura con "Facturar
> pendientes" (`sync_recent`, corre bajo `run_explicit`) y el **informe de divergencia** (`T5.1`/`T5.2`)
> muestra el `W≠A`. El próximo guardado de Ajustes coerciona `push_orders_enabled=true`.

**Copy exacto del control (contrato con `T6.1`/`T6.2`, Fase 6).** El selector visible y la nota literal
los implementa `T6.1`; esta fase **fija el texto** para que no diverja:

```html
<tr><th>Dueño del stock:</th><td>
  <select name="alegra_connector_stock_owner">
    <option value="auto">Automático (recomendado)</option>
    <option value="invoice">Factura</option>
    <option value="adjustment">Ajuste</option>
  </select>
  <p class="description">
    Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces.
    <br><em>Dueño efectivo ahora: <strong>Factura|Ajuste</strong>.</em>
  </p>
</td></tr>
```

- Nota **literal**: *"Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces."*
- Muestra el **dueño efectivo** (`Inventory_Pusher::owner()`), para que "Automático" no sea ambiguo.
- Ubicación: `templates/admin-settings.php`, **junto a** "Fuente de inventario" (`:94`) y **antes de**
  "Enviar stock a Alegra" (`:101`).
- Si se elige **Factura**, el toggle "Abrir la factura al pagarse" (`:110`) se muestra forzado ON y
  anotado; si se elige **Ajuste**, se advierte la apertura manual (`T6.2`).

**Orden de operaciones**: `sanitize_stock_owner()` hace allowlist + epoch (nada más). La coerción de
`open_invoice_on_paid` y `push_orders_enabled` ocurre en **su propio** `sanitize_callback`, que resuelve
el dueño efectivo del request. El orden de `register_setting`/`options.php` **no** importa (Oracle#9). En
`auto` no se coerciona ninguna.

**Resultado esperado (aceptación verificable)**:
- `$_POST['alegra_connector_stock_owner']='invoice'` + `sanitize_open_invoice_on_paid(false)` ⇒ `true`;
  `sanitize_push_orders_enabled(false)` ⇒ `true`.
- `$_POST['alegra_connector_stock_owner']='adjustment'` + `sanitize_open_invoice_on_paid(true)` ⇒ `false`.
- `stock_owner=auto` ⇒ `sanitize_open_invoice_on_paid(x)` devuelve `x` y
  `sanitize_push_orders_enabled(x)` devuelve `x` (sin coerción).
- Un valor inválido de `stock_owner` se guarda como `auto` y **no** coerciona.
- Guardar la página de Ajustes con `stock_owner=invoice` y el checkbox `open_invoice_on_paid` ausente
  del POST ⇒ la opción queda **ON** igual (Oracle#9: la coerción no se pierde por el orden).

**Dependencias**: `T2.7`. `T1.5` (siembra de `open_invoice_on_paid`, ya existente en `:460`).

**Trazabilidad**: REQ-OWN-04, REQ-OWN-07, REQ-OWN-01; D1 §2.5; NFR-03; correcciones **Oracle#8**, **Oracle#9**;
`tasks.md` §12.2 fila 9.

**Verificación**:
1. Test `T30.24` (compartido con `T2.3`) sub-assert de coerción determinista: con
   `$_POST['alegra_connector_stock_owner']='invoice'` ⇒ `sanitize_open_invoice_on_paid(false) === true` y
   `sanitize_push_orders_enabled(false) === true`; con `'adjustment'` ⇒
   `sanitize_open_invoice_on_paid(true) === false`; con `'auto'` ⇒ el valor pasa sin cambios.
   **Prove-it-catches**: volver a coercer dentro de `sanitize_stock_owner()` y simular el nullado de
   `options.php` ⇒ el assert de `invoice`/ON falla (la coerción se pierde).
2. Test `T30.24d` (Oracle#8): guardar con `stock_owner=invoice` y `push_orders_enabled` ausente del POST
   ⇒ la opción queda `true`; con `adjustment`/`auto` ⇒ se respeta el valor enviado.
3. `bash scripts/smoke-test.sh` verde (el `register_setting` y el template cargan).
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: pisar una preferencia explícita del comerciante en modo `auto`. **Guarda**: sólo coerciona en
`invoice`/`adjustment`; en `auto` no toca nada; se documenta en `CHANGELOG.md` (`T7.3`).

**Estimación**: M (2 h).

---

## DoD de la fase

- REQ-OWN-01..07 verdes.
- `owner()` lee `alegra_connector_stock_owner` y `auto` reproduce la **condición DOBLE** (`T30.21`); valor
  inválido ⇒ `auto` + warning (`T30.22`).
- `invoice` ⇒ **cero** `POST /inventory-adjustments` por pedido (Guard 2, `Inventory_Pusher.php:170-173`);
  `adjustment` ⇒ **nunca** auto-abre: ni el override de `:122-126`, ni el early-return, ni
  `create_invoice_with_payment()` (`T2.3` Cambio 2, `T2.4`). **B1/Oracle#1 cerrado en ambas ramas de G1.**
- **B1**: `create_invoice_with_payment()` fuerza `draft` con `owner=adjustment`; sus tres call sites
  (`ajax_sync_single`, el bulk, `sync_recent`) **no** crean factura `open` (`T30.24c`).
- La apertura/registro manual en `adjustment` exige `confirm_double_discount=1` + nota + log
  (`T30.25`/`T30.26`); no hay `confirm()` de cliente ni bloqueo duro. **Oracle#5**: la garantía es
  "no salteable **desde la UI del plugin**"; el camino Alegra-UI lo cubre el informe de divergencia.
- `_alegra_stock_adjusted_at` se escribe **sólo** al emitir/reconocer un ajuste (`T30.28`).
- Limpieza lazy del `pending` en el poll + `_stock_owner_epoch` + reset de cachés (`T30.29`/`T30.210`).
- `open_invoice_on_paid` se coerciona server-side **de forma determinista** (sanitize de la opción
  coercida + dueño efectivo del request) y `push_orders_enabled` se fuerza ON en `invoice`
  (`T2.8`, Oracle#8/Oracle#9, `T30.24d`); el selector visible y el copy literal quedan especificados
  para `T6.1`/`T6.2`.
- `adjustment_manual_only` se clasifica como `skipped`/no-retriable (Oracle#10): no entra a la cola de
  reintentos.
- **Prove-it-catches** documentado para `T2.1` (`T30.21`, `owner()` simplificado → rojo), `T2.5`
  (`T30.25`, guarda movida al cliente → rojo) y `T2.3` (`T30.24c`, reponer el `'open'` sin gate → rojo),
  en `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`).
- `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes.
