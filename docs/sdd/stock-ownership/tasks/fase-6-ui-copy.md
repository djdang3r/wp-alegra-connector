# Fase 6 — UI/copy (selector, cola, reintento UI, aviso/badge, fila dashboard) — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **6** — UI/copy (cosmético): selector de dueño, pantalla "Facturas por subir", reintento single/bulk, aviso/badge, fila del dashboard |
| Versión objetivo | **2.7.0** (la siguiente a 2.6.0; opciones nuevas + cambios de comportamiento ⇒ minor) |
| Harness | `bash scripts/exec-test.sh` (baseline real **1990 assertions, 0 failed** — `docs/RELEASE_2.6.0_VERIFICATION.md:71`) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T6.1`–`T6.8` (8 micro-tareas) |
| Depende de | Fases 2–5 (dueño, poll, ledger/cola, reconciliación) |
| DoD de la fase | las 8 micro-tareas `[x]`; `T30.61`–`T30.67` verdes con prove-it-catches; `SMOKE OK`; aceptación manual de la UI (selector + nota, cola + badge + aviso, fila dashboard + link) |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Las tareas UI que
> dependen de un test de fases centrales (`T6.8`→`T30.25`) heredan el prove-it-catch de esa tarea.
>
> **IDs de test.** `scripts/exec-test.php`, sección `// === stock-ownership (2.7.0) ===`, IDs
> `T30.6x` para esta fase. `T6.x` son IDs de **tarea**.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 6)

Todas las citas de esta fase se re-leyeron contra HEAD. Las que difieren del esqueleto `tasks.md` quedan
corregidas acá.

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `templates/admin-settings.php:94` = "Fuente de inventario"; `:101` = "Enviar stock a Alegra" | Confirmado: `:94` es la fila "Fuente de inventario" y `:101` la fila "Enviar stock a Alegra". Entre medio: `:95` "Sincronizar inventario" y `:96-100` "Gestionar stock en WooCommerce". | El punto de inserción exacto del selector es **después de `:100` y antes de `:101`** (no "junto a `:94`"). `T6.1` |
| C2 | `templates/admin-settings.php` es multi-línea | **Falso.** El archivo es **una fila `<tr>` por línea** (todo el HTML minificado por línea). El bloque HTML de `design.md:335-347` hay que **colapsarlo a una sola línea** para respetar el estilo. | `T6.1`, `T6.2` |
| C3 | `templates/admin-settings.php:109-112` (coerción `open_invoice_on_paid`) | Confirmado: `:109` abre `<?php if (get_option('alegra_connector_push_orders_enabled', false)): ?>`, `:110` es el checkbox `open_invoice_on_paid`, `:111` la descripción, `:112` el `endif`. **El toggle sólo se renderiza si `push_orders_enabled=true`.** | Con `stock_owner=invoice` + `push_orders_enabled=false` el toggle **no existe** en el DOM: la coerción no puede depender de él. Ver `T6.2` (hallazgo fuerte). |
| C4 | `spec.md:766` cita `Admin_Dashboard.php:3166-3168` como patrón `run_explicit` | Confirmado pero **aproximado**: `ajax_sync_single()` es `:3166-3169` (el wrapper `run_explicit` está en `:3168`); el nonce/capacidad en `:3173-3177`. | `T6.4`, `T7.7` |
| C5 | `State_Sync.php:473`/`:488` (aviso) | Confirmado: `:473` es `queue_admin_notice()` (**private**, transient 60 s por usuario); `:488-504` es `render_admin_notices()` (público, enganchado en `:62`). **NO es option-backed.** | El diseño §4.7 pide option-backed: hay que **agregar** un método público nuevo. `T6.6` |
| C6 | `templates/admin-dashboard.php:197-212` (fila divergencia) | Confirmado: `:197` es `<?php if (!empty($divergence['count'])): ?>`; `:208-210` es el `<span class="alegra-health-action">` con **texto, sin link**. | El "siempre visible" se logra sacando el gate de `Admin_Dashboard.php:903-905` (**eso es `T5.3`**), no el `if` de `:197`. `T6.7` sólo agrega el link. |
| C7 | `Admin_Dashboard.php:3867-3979` (bulk) | Confirmado. `:3936` = `array_splice($state['order_ids'], 0, 10)` (10/request); `:3898` setea el transient `alegra_pending_invoice_batch`; `:3964` lo borra al terminar; `:3874` ya incluye `on-hold`. | `T6.5` |
| C8 | `Admin_Dashboard.php:106-234` (menú) | Confirmado: `add_admin_menu()` cierra en `:234`. `:118-233` son los 13 `add_submenu_page` actuales. | El submenu nuevo se inserta **antes de `:234`**. `T6.3` |
| C9 | JS de apertura manual sin cita | Realidad: `admin.js` `initRecordPayment` `:633-645`, `initOpenInvoice` `:647-667`; strings en `Admin_Dashboard.php` `get_script_strings()` `:734-736`. | `T6.8` |

**Citas confirmadas exactas:** `templates/admin-settings.php:94-105` (sección inventario), `:101-105`
(Enviar stock a Alegra), `:109-112` (toggle condicional); `templates/admin-dashboard.php:197-212`;
`Admin_Dashboard.php:32` (`admin_init`→`register_settings`), `:39-82` (registro AJAX), `:353-...`
(`register_settings`), `:613-644` (`enqueue_assets`), `:620` (enqueue `admin.js`), `:656-...`
(`get_script_strings`), `:862-887` (`get_unjournaled_sales`), `:889-908` (`render_dashboard`),
`:903-905` (gate invertido), `:2840-2895` (`ajax_open_invoice`), `:2897-3028` (`ajax_record_payment`),
`:3166-3169` (patrón `run_explicit`), `:3867-3979` (bulk chunked); `State_Sync.php:62,473,488-504`;
`admin/assets/js/admin.js:633-645,647-667,811-853`; `templates/admin-monitor.php` (patrón de pantalla);
`proposal.md:144-145` (restricción de usabilidad verbatim).

---

## La restricción de usabilidad (se honra tal cual)

`proposal.md:144-145` (verbatim del comerciante):

> *"Nuestro plugin es profesional y el dashboard debe seguir con la buena usabilidad, nada de
> ambiguedades y que el usuario no se complique, debe ser facil de usar."*

Consecuencias de diseño para TODA la Fase 6:

1. **Nada de jerga.** El selector dice "Dueño del stock", no "owner()" ni "invoice_owner". Las opciones
   se llaman **Automático / Factura / Ajuste**.
2. **Sin ambigüedad.** La nota es literal y el dueño **efectivo** se muestra al lado ("Dueño efectivo
   ahora: Factura/Ajuste"), para que "Automático" no obligue al comerciante a deducir nada.
3. **Sin complicar.** La cola y el informe se abren desde el menú y desde el aviso; los estados se
   explican en castellano; una sola acción por fila ("Reintentar").
4. **La UI no miente** (NFR-02): si no puede comprobar algo, lo dice ("no verificado", "nunca
   intentado", "baseline ausente").

---

## Mapa de cobertura Fase 6 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T6.1` | Selector "Dueño del stock" + nota literal + dueño efectivo | `templates/admin-settings.php`, `Admin_Dashboard.php:353` (`register_settings`) |
| `T6.2` | Coerción UI de `open_invoice_on_paid` + aviso `invoice_status=open` | `templates/admin-settings.php:106-113` |
| `T6.3` | Pantalla "Facturas por subir" (columnas + filtros + paginación) | `Admin_Dashboard.php:106-234`, `templates/admin-invoice-queue.php` (NUEVO) |
| `T6.4` | Reintento single (AJAX + JS) | `Admin_Dashboard.php` (AJAX nuevo), `admin.js` |
| `T6.5` | Reintento bulk `scope=failed` | `Admin_Dashboard.php:3867-3979`, `admin.js:811-853` |
| `T6.6` | Badge + aviso dismissible + dismiss per-user | `Admin_Dashboard.php` (submenu), `State_Sync.php:473/488-504` |
| `T6.7` | Fila de divergencia + link a la cola | `templates/admin-dashboard.php:197-212` |
| `T6.8` | Confirmación JS de apertura manual en `adjustment` | `admin.js:633-667` |

---

## Tabla canónica de strings i18n nuevos (dominio `alegra-connector`)

> **Regla.** Toda string visible pasa por `__()`/`esc_html_e()`/`esc_attr_e()` con el dominio
> `alegra-connector` (NO strings sueltas). Las de PHP van en el template; las de JS van **sí o sí** en
> `Admin_Dashboard::get_script_strings()` (`:656-...`) y se consumen por `S.<clave>` en `admin.js`.

| Clave / texto (es) | Dónde vive | Helper | REQ |
|---|---|---|---|
| `Dueño del stock:` | `admin-settings.php` (fila nueva) | `esc_html_e` | REQ-OWN-07 |
| `Automático (recomendado)` | idem `<option>` | `esc_html_e` | REQ-OWN-07 |
| `Factura` | idem `<option>` | `esc_html_e` | REQ-OWN-07 |
| `Ajuste` | idem `<option>` | `esc_html_e` | REQ-OWN-07 |
| `Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces.` | nota | `esc_html_e` | REQ-OWN-07 |
| `Dueño efectivo ahora: %s.` | nota | `esc_html_e` + `sprintf` | REQ-OWN-07 |
| `Con "Factura", la factura es la única que mueve el stock: el plugin no emite ajustes.` | aviso coerción | `esc_html_e` | REQ-OWN-04/07 |
| `Con "Ajuste", la factura queda en borrador; abrirla a mano descuenta dos veces.` | aviso coerción | `esc_html_e` | REQ-OWN-04/06 |
| `Ojo: con las facturas en "Abierta", un pedido impago crea una factura que mueve stock antes del pago.` | aviso `invoice_status=open` | `esc_html_e` | REQ-OWN-04 |
| `Facturas por subir` | título del submenu | `__` | REQ-QUEUE-03 |
| `Orden`, `Fecha`, `Cliente`, `Total`, `Estado Alegra`, `Motivo`, `Código`, `Intentos`, `Último intento`, `Próximo intento`, `Acciones` | `admin-invoice-queue.php` (thead) | `esc_html_e` | REQ-QUEUE-03 |
| `Nunca intentado`, `Reintentable`, `Permanente`, `Bloqueado`, `Pago pendiente` | badges de estado | `esc_html_e` | REQ-QUEUE-03/08 |
| `Reintentar`, `Ver log`, `Ignorar` | acciones de fila | `esc_html_e` | REQ-QUEUE-03/04 |
| `%d facturas no se pudieron subir a Alegra. Ver lista` | aviso admin | `__` + `sprintf` | REQ-QUEUE-07 |
| `Los ajustes de inventario están activos; si ya se empujó un ajuste por este pedido, abrir la factura descontará dos veces. ¿Confirmás igual?` | JS (`confirmDoubleDiscount`) | `__` | REQ-OWN-06 |
| `Ya tiene factura` | JS (`alreadyInvoiced`) | `__` | REQ-QUEUE-04 |
| `Se reintentó la factura.` | JS (`retryDone`) | `__` | REQ-QUEUE-04 |
| `¿Registrar pago en Alegra?` (existente) | JS `:734` | `__` | — |
| `¿Abrir esta factura en Alegra? ...` (existente) | JS `:735` | `__` | — |

---

### T6.1 — Selector "Dueño del stock" + nota literal + dueño efectivo

**Objetivo**: que el comerciante elija el dueño del stock en Ajustes con una sola decisión, sin jerga y
sin ambigüedad, viendo además cuál es el dueño efectivo que resultó.

**Descripción técnica**: se agrega **una fila** al `<table class="form-table">` de la sección inventario
de `templates/admin-settings.php`, **después de `:100`** (fila "Gestionar stock en WooCommerce") y
**antes de `:101`** (fila "Enviar stock a Alegra"). El archivo es **una fila por línea** (C2), así que el
bloque de `design.md:335-347` se colapsa. La opción se lee con
`get_option('alegra_connector_stock_owner', 'auto')` y el dueño efectivo con
`\Alegra\Connector\Sync\Inventory_Pusher::owner()`. Cubre REQ-OWN-07 y la restricción de usabilidad
(`proposal.md:144-145`). El `register_setting` correspondiente ya lo hace la Fase 2 (`T2.7`/`T2.8`) en
`Admin_Dashboard::register_settings()` (`:353`); esta tarea **sólo** toca el template.

**Desarrollo técnico — `templates/admin-settings.php`** (fila nueva entre `:100` y `:101`):

ANTES (`:96-101`, recortado):

```php
<tr><th><?php esc_html_e('Gestionar stock en WooCommerce:','alegra-connector');?></th><td><label>
<input type="checkbox" name="alegra_connector_inventory_manage_stock_enabled" value="1" <?php checked(get_option('alegra_connector_inventory_manage_stock_enabled', false)); ?>>
<?php esc_html_e('Gestionar stock en WooCommerce para productos que Alegra marca inventariables', 'alegra-connector'); ?>
</label>
<p class="description"><?php esc_html_e('Si lo activás, ...', 'alegra-connector'); ?></p></td></tr>
<tr><th><?php esc_html_e('Enviar stock a Alegra:','alegra-connector');?></th><td><label>
```

DESPUÉS (insertar UNA línea entre las dos filas):

```php
<?php $ac_owner = (string) get_option('alegra_connector_stock_owner', 'auto'); $ac_owner_eff = \Alegra\Connector\Sync\Inventory_Pusher::owner(); ?>
<tr><th><label for="alegra_connector_stock_owner"><?php esc_html_e('Dueño del stock:','alegra-connector');?></label></th><td><select id="alegra_connector_stock_owner" name="alegra_connector_stock_owner"><option value="auto" <?php selected($ac_owner,'auto');?>><?php esc_html_e('Automático (recomendado)','alegra-connector');?></option><option value="invoice" <?php selected($ac_owner,'invoice');?>><?php esc_html_e('Factura','alegra-connector');?></option><option value="adjustment" <?php selected($ac_owner,'adjustment');?>><?php esc_html_e('Ajuste','alegra-connector');?></option></select><p class="description"><?php esc_html_e('Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces.','alegra-connector');?><br><em><?php echo esc_html(sprintf(__('Dueño efectivo ahora: %s.','alegra-connector'), $ac_owner_eff === 'invoice' ? __('Factura','alegra-connector') : __('Ajuste','alegra-connector')));?></em></p></td></tr>
```

**Opciones evaluadas** (la decisión ya está tomada; se documenta el porqué):

| Opción | Veredicto |
|---|---|
| **A — Selector con las 3 opciones + nota literal + dueño efectivo (ELEGIDA)** | Mínima, determinista, sin jerga; el comerciante ve el resultado de "Automático". |
| B — Dos checkboxes ("factura" / "ajuste") | **Rechazada:** el comerciante podría marcar los dos ⇒ ambigüedad explícita que la nota tiene que evitar. |
| C — Sólo un texto informativo sin selector | **Rechazada:** no permite elegir el modelo "todo facturado". |
| D — Mostrar sólo el dueño efectivo sin opciones | **Rechazada:** no cumple REQ-OWN-07 (selector con las 3 opciones). |

**Resultado esperado**: Ajustes → Sincronización muestra "Dueño del stock" con Automático / Factura /
Ajuste; la nota literal *"Elegí UNO solo…"*; y "Dueño efectivo ahora: Factura|Ajuste". Al recargar, el
valor guardado queda seleccionado.

**Dependencias**: `T2.1` (`owner()`), `T1.5` (opción sembrada), `T2.7`/`T2.8` (`register_setting` +
coerción server-side).

**Trazabilidad**: REQ-OWN-07; `design.md` §2.4 (`:330-347`); `proposal.md:144-145`, `:176`;
`spec.md:357-383`.

**Verificación**: test `T30.65` (selector + nota + coerción). Manual: Ajustes → ver la fila y las 3
opciones. **Prove-it-catch:** quitar el `<select>` (o hardcodear `owner()` sin leer la opción) ⇒ `T30.65`
falla.

**Riesgo**: que la nota quede en jerga o que el archivo minificado rompa el `<table>` al insertar. Se
mitiga copiando el estilo exacto de las filas vecinas (`:94-105`) y corriendo `smoke-test.sh` (`php -l`).

**Estimación**: M (3 h).

---

### T6.2 — Coerción UI de `open_invoice_on_paid` + aviso `invoice_status=open`

**Objetivo**: que la UI no permita quedar en el estado "ningún mecanismo mueve stock" (defecto B3/C1) y
que advierta cuando `invoice_status=open` puede divergir antes del pago.

**Descripción técnica**: el toggle "Abrir la factura al pagarse" vive en `templates/admin-settings.php`
`:109-112` y **sólo se renderiza si `push_orders_enabled=true`** (C3). Con `stock_owner=invoice` y
auto-facturación apagada, el toggle **no está en el DOM**, así que la coerción visual no alcanza: el
server (`T2.8`) coerciona la opción y el template agrega un **aviso** que explica el estado forzado. Con
`stock_owner=adjustment` se advierte que abrir la factura a mano descuenta dos veces (guarda fuerte en
`T2.5`/`T2.6`). Además, con `invoice_status=open` (`:241-247`) se muestra un aviso de divergencia. Cubre
REQ-OWN-04/07 y `design.md` §2.5 (`:357-370`).

**Desarrollo técnico — `templates/admin-settings.php`**, bloque `:106-113`:

ANTES (`:109-112`):

```php
<?php if (get_option('alegra_connector_push_orders_enabled', false)): ?>
<label><input type="checkbox" name="alegra_connector_open_invoice_on_paid" value="1" <?php checked(get_option('alegra_connector_open_invoice_on_paid', true)); ?>> <strong><?php esc_html_e('Abrir la factura al pagarse (Alegra descuenta stock)','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Con la factura como dueña del stock, ...','alegra-connector');?></p>
<?php endif; ?>
```

DESPUÉS (se agrega, **fuera** del `if` de `:109`, un bloque de avisos dependiente de `stock_owner`; el
toggle, si está presente, se fuerza a ON y `disabled` en modo `invoice`):

```php
<?php $ac_owner_ui = (string) get_option('alegra_connector_stock_owner', 'auto'); ?>
<?php if ($ac_owner_ui === 'invoice'): ?>
<p class="description" style="margin:2px 0 6px 24px;"><strong><?php esc_html_e('Con "Factura" como dueño, la factura abre al pagarse (forzado): es la única que mueve el stock.','alegra-connector');?></strong></p>
<?php elseif ($ac_owner_ui === 'adjustment'): ?>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Con "Ajuste" como dueño, la factura queda en borrador; abrirla a mano descuenta dos veces (se te va a pedir confirmación).','alegra-connector');?></p>
<?php endif; ?>
<?php if (get_option('alegra_connector_invoice_status','draft') === 'open'): ?>
<p class="description" style="margin:2px 0 6px 24px;color:var(--ac-danger);"><?php esc_html_e('Ojo: con las facturas en "Abierta", un pedido impago crea una factura que mueve stock antes del pago.','alegra-connector');?></p>
<?php endif; ?>
```

Y dentro del `if` de `:109`, cuando `stock_owner=invoice`, el checkbox se renderiza
`checked disabled` (más un `<input type="hidden" name="alegra_connector_open_invoice_on_paid" value="1">`
para que el POST no lo borre).

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Aviso fuera del `if` + coerción server-side (ELEGIDA)** | Cubre el caso `invoice` + auto-facturación apagada (donde el toggle no existe). |
| B — Depender sólo del toggle deshabilitado | **Rechazada:** el toggle no se renderiza con `push_orders_enabled=false` ⇒ no hay nada que deshabilitar. |
| C — Bloquear el guardado de `invoice` + `open_invoice_on_paid=false` | **Rechazada:** el server coerciona (T2.8), no bloquea; bloquear complica (viola la restricción de usabilidad). |

**Resultado esperado**: en modo `invoice` se ve el aviso del forzado; en `adjustment`, la advertencia de
apertura manual; con `invoice_status=open`, el aviso de divergencia. Ninguna combinación deja al
comerciante sin saber quién mueve el stock.

**Dependencias**: `T6.1`, `T2.8` (coerción server-side), `T2.5`/`T2.6` (guardas).

**Trazabilidad**: REQ-OWN-04/07; `design.md` §2.5 (`:357-370`); `spec.md:241-286`.

**Verificación**: cubierto por `T30.65`; manual: guardar `stock_owner=invoice` con
`push_orders_enabled=false` y ver el aviso. **Prove-it-catch:** quitar el aviso y dejar
`open_invoice_on_paid=false` ⇒ `T30.65` falla (el test asserta la coerción).

**Riesgo**: que el aviso se muestre siempre y ensucie la UI. Se mitiga mostrándolo **sólo** en el modo
correspondiente.

**Estimación**: M (2 h).

---

### T6.3 — Pantalla "Facturas por subir"

**Objetivo**: dar al comerciante una pantalla donde ver **todas** las facturas que no subieron (con
motivo, código, intentos y próximo reintento), distinguir las nunca-intentadas y poder actuar.

**Descripción técnica**: submenu nuevo en `Admin_Dashboard::add_admin_menu()` (`:106-234`, insertar
antes del cierre en `:234`) con slug `alegra-connector-invoice-queue` y callback
`render_invoice_queue_page()`. El render vive en un template nuevo `templates/admin-invoice-queue.php`
(patrón de `templates/admin-monitor.php`). La query la provee `Invoice_Queue::query()` (`T4.10`) y el
conteo `Invoice_Queue::count()` (`T4.11`), así el **conteo de la vista coincide con el badge**
(REQ-QUEUE-07). Cubre REQ-QUEUE-03/08 y NFR-04.

**Desarrollo técnico**:

1. **Submenu** — `Admin_Dashboard.php`, antes de `:234`:

```php
add_submenu_page(
    'alegra-connector',
    __('Facturas por subir', 'alegra-connector'),
    $this->invoice_queue_menu_title(),          // título con badge (T6.6)
    'manage_woocommerce',
    'alegra-connector-invoice-queue',
    [$this, 'render_invoice_queue_page']
);
```

2. **Template** — `templates/admin-invoice-queue.php` (NUEVO), columnas exactas de `design.md:755-759`:

| Columna | Fuente | Formato |
|---|---|---|
| Orden | `#id` con link a `admin.php?page=alegra-connector-orders` | `esc_html` |
| Fecha | `$order->get_date_created()` | `Y-m-d H:i` |
| Cliente | billing name | `esc_html` |
| Total | `$order->get_total()` | `wc_price` |
| Estado Alegra | `_alegra_invoice_status` (fallback: estado de sync) | badge |
| Motivo | `_alegra_invoice_error_message` | `esc_html` (truncado ≤500) |
| Código | `_alegra_invoice_error_code` | `<code>` |
| Intentos | `_alegra_invoice_attempts` | int |
| Último | `_alegra_invoice_last_attempt` | GMT → local |
| Próximo | `_alegra_invoice_next_retry` | GMT → local |
| Acciones | Reintentar / Ver log / Ignorar | botones con `data-order-id` |

3. **Estados distinguidos** (REQ-QUEUE-08): `nunca intentado` (sin `_alegra_invoice_id` ni ledger),
   `failed_retriable`, `failed_permanent`, `blocked`, `payment_missing`. La pantalla **no** infiere
   "pendiente" sólo de la ausencia de `_alegra_invoice_id` (defecto actual de `:862-887`).
4. **Filtros + paginación**: por estado de sync, rango de fechas y búsqueda; `per_page=20` (`T4.10`).
5. **Seguridad**: `render_invoice_queue_page()` chequea `current_user_can('manage_woocommerce')` (patrón
   `:891-893`).

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Submenu propio + template (ELEGIDA)** | Separación clara, paginable, con badge; sigue el patrón del Monitor. |
| B — Reusar "Pedidos" (`render_orders_page`) | **Rechazada:** mezcla la vista de pedidos con el ledger y complica los filtros. |
| C — Sólo un aviso en el dashboard | **Rechazada:** no cumple REQ-QUEUE-03 (columnas + filtros + paginación). |

**Resultado esperado**: `Alegra Connector → Facturas por subir` lista los 5 estados con sus columnas;
los nunca-intentados no se confunden con fallos; filtros y paginación funcionan; el conteo coincide con
el badge.

**Dependencias**: `T1.7` (esqueleto `Invoice_Queue`), `T4.10` (`query`), `T4.11` (conteo cacheado),
`T6.6` (badge).

**Trazabilidad**: REQ-QUEUE-03/08, NFR-04; `design.md` §4.4 (`:738-765`); `spec.md:693-728`, `:877-907`.

**Verificación**: `T30.61` (lista los estados) y `T30.62` (filtros + paginación). Manual: forzar un fallo
y abrir la cola. **Prove-it-catch:** volver a inferir "pendiente" sólo de `NOT EXISTS` ⇒ `T30.61` falla
al no distinguir nunca-intentado de fallo.

**Riesgo**: que la query sin `limit` castigue la carga (NFR-04). Se mitiga con `Invoice_Queue::query()`
paginada y el conteo cacheado; el template **no** hace `wc_get_orders` propio.

**Estimación**: L (6 h).

---

### T6.4 — Reintento single (AJAX + JS)

**Objetivo**: que "Reintentar" en una fila re-suba la factura, actualice el ledger y reporte el
desenlace, sin crear una segunda factura.

**Descripción técnica**: AJAX nuevo `ajax_retry_invoice` + `ajax_retry_invoice_impl` en
`Admin_Dashboard.php`, registrado en el constructor (`:39-82`) como
`add_action('wp_ajax_alegra_retry_invoice', [$this, 'ajax_retry_invoice'])`. El wrapper corre bajo
`Write_Gate::run_explicit()` (patrón `:3166-3169`) porque es una acción manual; el `_impl` exige
`check_ajax_referer('alegra_connector_nonce')` + `current_user_can('manage_woocommerce')` (patrón
`:3173-3177`). Si `_alegra_invoice_id` ya existe ⇒ reporta "ya facturado", marca `resolved` y **no**
crea otra (REQ-QUEUE-09). Llama `Controller::sync_entity('order', $id, 'complete')` (→
`create_invoice_with_payment()`). El JS sigue el patrón `initOpenInvoice` (`admin.js:647-667`). Cubre
REQ-QUEUE-04 y NFR-06.

**Desarrollo técnico**:

PHP (esqueleto del plan):

```php
public function ajax_retry_invoice(): void
{
    \Alegra\Connector\Write_Gate::run_explicit(fn () => $this->ajax_retry_invoice_impl());
}

private function ajax_retry_invoice_impl(): void
{
    check_ajax_referer('alegra_connector_nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('No tenés permisos.', 'alegra-connector')]);
    }
    $order = wc_get_order((int) ($_POST['order_id'] ?? 0));
    if (!$order) { wp_send_json_error(['message' => __('Pedido no encontrado.', 'alegra-connector')]); }
    if ((string) $order->get_meta('_alegra_invoice_id', true) !== '') {
        \Alegra\Connector\Sync\Invoice_Failure::clear($order);
        wp_send_json_success(['state' => 'resolved', 'message' => __('Ya tiene factura.', 'alegra-connector')]);
    }
    $result = (new \Alegra\Connector\Sync\Controller($this->api, $this->logger))
        ->sync_entity('order', (int) $order->get_id(), 'complete');
    // clasificar + persistir ledger; responder {success,data:{state,message}}
}
```

JS (`admin.js`, patrón `initOpenInvoice` `:647-667`): handler `.alegra-retry-invoice` que POSTea
`action: 'alegra_retry_invoice'`, `_ajax_nonce`, `order_id`, y en `success` recarga o actualiza la fila;
strings nuevas en `get_script_strings()`.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — AJAX nuevo bajo `run_explicit` + `sync_entity` (ELEGIDA)** | Reusa el flujo de facturación existente y la idempotencia. |
| B — Reusar `ajax_sync_single` con `entity_type=order` | **Rechazada:** no distingue "reintento de fallo" ni actualiza el ledger con `state`. |
| C — Reintento en el server sin AJAX (form POST) | **Rechazada:** rompe el patrón admin y recarga toda la página. |

**Resultado esperado**: "Reintentar" sube la factura, sale de la cola y muestra el éxito; un fallo
permanente sigue permanente con el motivo actualizado; un pedido ya facturado reporta "ya tiene factura"
sin duplicar.

**Dependencias**: `T4.3` (call site + re-búsqueda), `T4.9` (idempotencia), `T6.3` (pantalla), `T5.4`
(patrón `run_explicit`).

**Trazabilidad**: REQ-QUEUE-04, NFR-06; `design.md` §4.5 (`:767-782`); `spec.md:732-764`.

**Verificación**: `T30.48` (reintento idempotente, compartido con `T4.9`). Manual: forzar un fallo,
resolver la causa y reintentar. **Prove-it-catch:** quitar la guarda de `_alegra_invoice_id` ⇒ el test de
doble factura falla.

**Riesgo**: que el reintento duplique la factura si el POST anterior commiteó sin respuesta. Se mitiga
con la re-búsqueda post-error (`T4.3`) y la guarda de idempotencia.

**Estimación**: M (3 h).

---

### T6.5 — Reintento bulk `scope=failed`

**Objetivo**: que "Reintentar seleccionados" procese los fallos en lotes de 10, sin tocar el import y sin
re-encolar los ya resueltos.

**Descripción técnica**: se extiende el flujo chunked existente (`ajax_sync_pending_start` `:3867-3901`
y `ajax_sync_pending_page_impl` `:3908-3979`). `ajax_sync_pending_start` acepta un `scope`
(`pending` default | `failed`): con `scope=failed`, la query sale del ledger (los estados de
`design.md:748-751`) en vez de "sin `_alegra_invoice_id`". El page impl **no** cambia: sigue procesando
**10 por request** (`:3936`) y el estado vive en `alegra_pending_invoice_batch` (`:3898`, `:3966`,
`:3964`). **NO** se toca `alegra_batch_state` (es del import; REQ-QUEUE-05 escenario negativo). Cubre
REQ-QUEUE-05 y NFR-04.

**Desarrollo técnico**:

ANTES (`:3867-3901`, recortado):

```php
public function ajax_sync_pending_start(): void
{
    check_ajax_referer('alegra_connector_nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error();
    $orders = wc_get_orders([
        'limit' => 100,
        'status' => ['processing', 'completed', 'on-hold'],
        'return' => 'ids',
    ]);
    $pending = [];
    foreach ($orders as $oid) { /* ... sin _alegra_invoice_id ... */ }
    set_transient('alegra_pending_invoice_batch', $state, 600);
    wp_send_json_success(['total' => count($pending)]);
}
```

DESPUÉS (agregar `scope`; con `failed` la lista sale de `Invoice_Queue::query(['state' => ...])`):

```php
$scope = sanitize_key($_POST['scope'] ?? 'pending');   // 'pending' | 'failed'
$ids = $scope === 'failed'
    ? \Alegra\Connector\Sync\Invoice_Queue::query(['state' => 'failed'], 1, 100)['ids']  // alias C6
    : $this->collect_never_invoiced_ids();             // lógica actual
$state = ['scope' => $scope, 'order_ids' => $ids, 'total' => count($ids), 'processed' => 0, 'synced' => 0, 'errors' => 0];
set_transient('alegra_pending_invoice_batch', $state, 600);
```

JS (`admin.js:811-853`): el `ajax_sync_pending_start` agrega `scope: $btn.data('scope')` (la pantalla de
la cola pasa `scope=failed`; el botón viejo de "Facturar pendientes" sigue `pending`).

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Extender el chunked con `scope` (ELEGIDA)** | Reusa el progreso/UI existente; 10/request ya probado. |
| B — AJAX bulk nuevo | **Rechazada:** duplica el flujo y el modal de progreso. |
| C — Reintentar todo de una | **Rechazada:** agota el request en tiendas grandes (NFR-04). |

**Resultado esperado**: 25 fallos seleccionados se procesan de a 10; cada pedido termina con el ledger
actualizado; los ya resueltos se saltean por idempotencia; `alegra_batch_state` (import) queda intacto.

**Dependencias**: `T6.3` (pantalla), `T4.10` (`Invoice_Queue::query`), `T4.9` (idempotencia).

**Trazabilidad**: REQ-QUEUE-05, NFR-04; `design.md` §4.5 (`:784-790`); `spec.md:770-797`.

**Verificación**: `T30.67` (bulk `scope=failed` sin tocar el import). Manual: seleccionar varios fallos y
reintentar. **Prove-it-catch:** hacer que el bulk use `alegra_batch_state` ⇒ `T30.67` falla.

**Riesgo**: mezclar el transient del import con el del bulk. Se mitiga manteniendo
`alegra_pending_invoice_batch` separado y assertando en `T30.67`.

**Estimación**: M (4 h).

---

### T6.6 — Badge + aviso dismissible + dismiss per-user

**Objetivo**: avisar de forma persistente (aunque el fallo lo escriba el cron) con un aviso dismissible
y un badge en el menú, sin ejecutar una query pesada por página.

**Descripción técnica**: el badge se lee del **conteo cacheado** (`Invoice_Queue::count()`, option
`alegra_connector_invoice_failures_count`) y se inyecta en el título del submenu con
`<span class="awaiting-mod">N</span>`. El aviso es `notice-warning is-dismissible` con el texto
*"N facturas no se pudieron subir a Alegra. Ver lista"* y link a la cola. **Corrección C5:** el
`State_Sync::queue_admin_notice()` actual (`:473`) es **private y transient-based** (60 s), no
option-backed; para cumplir `design.md` §4.7 se **agrega** un método público
`State_Sync::queue_invoice_failure_notice(int $count)` que persiste el conteo/hash en options y engancha
el render en `admin_notices` (`State_Sync.php:62`, render existente `:488-504`). Dismiss per-user: user
meta `_alegra_invoice_notice_dismissed_hash` comparada con `alegra_connector_invoice_failures_hash`.
Cubre REQ-QUEUE-07 y NFR-04.

**Desarrollo técnico**:

1. **Badge** — helper en `Admin_Dashboard`:

```php
private function invoice_queue_menu_title(): string
{
    $n = \Alegra\Connector\Sync\Invoice_Queue::count();   // option cacheada, NO query
    $title = __('Facturas por subir', 'alegra-connector');
    return $n > 0 ? $title . ' <span class="awaiting-mod">' . (int) $n . '</span>' : $title;
}
```

2. **Aviso option-backed** — `State_Sync`:

```php
public static function queue_invoice_failure_notice(int $count): void
{
    if ($count <= 0) { delete_option('alegra_connector_invoice_failures_hash'); return; }
    // C4: hash canónico único (md5), el MISMO que escribe Invoice_Queue::refresh_count().
    update_option('alegra_connector_invoice_failures_hash', \Alegra\Connector\Sync\Invoice_Queue::failure_hash($count), false);
}
```

El render (nuevo, junto a `:488-504`) compara el hash con el user meta de dismiss y emite
`<div class="notice notice-warning is-dismissible">` con el enlace
`admin.php?page=alegra-connector-invoice-queue`.
3. **Dismiss per-user**: JS en `admin.js` que al cerrar el aviso hace `wp.ajax`/`$.post` a un AJAX de
   dismiss, o —más simple— el link "Ver lista" y el `is-dismissible` nativo de WP; el hash se guarda en
   user meta al navegar. El conteo se refresca en persist/clear/apertura (`T4.11`), nunca por página.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Option-backed (conteo + hash) + badge desde la option (ELEGIDA)** | Sobrevive al cron (no depende de un request de usuario). |
| B — Reusar el transient de `queue_admin_notice` (`:473`) | **Rechazada:** el transient se borra a los 60 s y es por usuario ⇒ el fallo del cron no se ve. |
| C — Query en cada `admin_notices` | **Rechazada:** viola NFR-04 (query por página). |

**Resultado esperado**: un fallo (incluso escrito por cron) produce aviso + badge con N; el aviso
desaparece cuando N=0; el badge no ejecuta query (lee la option); el dismiss no reaparece hasta que
cambie el hash.

**Dependencias**: `T4.11` (conteo cacheado + `refresh_count`), `T6.3` (pantalla), `T4.2`/`T4.5`
(persist/clear disparan el refresco).

**Trazabilidad**: REQ-QUEUE-07, NFR-04; `design.md` §4.7 (`:833-851`); `spec.md:840-871`;
`Logger.php:204` (patrón option-backed).

**Verificación**: `T30.63` (badge = conteo) y `T30.64` (aviso dismissible + hash). Manual: forzar un
fallo desde el cron y entrar al admin. **Prove-it-catch:** leer el badge de una query en vez de la
option ⇒ `T30.63`/`T30.411` fallan.

**Riesgo**: que el aviso se muestre en el dry-run (no debe). Se mitiga: dry-run no persiste ledger
(`T4.1`), así el conteo queda en 0.

**Estimación**: M (4 h).

---

### T6.7 — Fila de divergencia siempre visible + link a la cola

**Objetivo**: que la fila "Ventas sin factura" del dashboard deje de esconderse en el modo del
comerciante y enlace a la cola.

**Descripción técnica**: el template `templates/admin-dashboard.php:197-212` renderiza la fila cuando
`!empty($divergence['count'])` (`:197`); el `:208-210` es texto plano. **Corrección C6:** el
"siempre visible" se logra sacando el gate invertido de `Admin_Dashboard::render_dashboard()`
`:903-905` — pero **eso es `T5.3`** (REQ-RECON-02); esta tarea sólo agrega el **link** a la cola en
`:208-210` y se asegura de que la fila no quede gated por `push_orders_enabled`. Cubre REQ-RECON-01/02.

**Desarrollo técnico — `templates/admin-dashboard.php`**, `:208-210`:

ANTES:

```php
<span class="alegra-health-action">
    <?php esc_html_e('El stock de Alegra puede no reflejar esas ventas.', 'alegra-connector'); ?>
</span>
```

DESPUÉS:

```php
<span class="alegra-health-action">
    <?php esc_html_e('El stock de Alegra puede no reflejar esas ventas.', 'alegra-connector'); ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-invoice-queue')); ?>"><?php esc_html_e('Ver facturas por subir', 'alegra-connector'); ?> &rarr;</a>
</span>
```

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Mantener el `if (count)` y agregar link (ELEGIDA)** | La fila aparece cuando hay divergencia; el "siempre" se refiere a computar el reporte (T5.3). |
| B — Mostrar la fila incluso con count 0 | **Rechazada:** ruido; NFR-02 pide honestidad, no alarmismo. |
| C — Mover la fila a la cola | **Rechazada:** el dashboard es el punto de entrada natural. |

**Resultado esperado**: con divergencia, la fila muestra el conteo y un link a "Facturas por subir";
aparece también con `push_orders_enabled=true` (por `T5.3`).

**Dependencias**: `T5.2` (informe), `T5.3` (des-invertir gate), `T6.3` (pantalla destino del link).

**Trazabilidad**: REQ-RECON-01/02; `design.md` §5.2 (`:898-904`); `spec.md:982-1014`.

**Verificación**: `T30.66` (fila siempre + link). Manual: ver la fila y clickear el link.
**Prove-it-catch:** restaurar el gate `:903-905` ⇒ `T30.66` falla.

**Riesgo**: link roto si el slug del submenu difiere. Se mitiga usando la constante/slug exacto
`alegra-connector-invoice-queue` de `T6.3`.

**Estimación**: S (2 h).

---

### T6.8 — Confirmación JS de apertura manual en `adjustment`

**Objetivo**: que abrir la factura o registrar el pago a mano en modo `adjustment` pase por un diálogo
claro que reenvía la confirmación server-enforced, y que cancelar **no** llame a la API.

**Descripción técnica**: el server (`T2.5`/`T2.6`) responde `wp_send_json_error` con código
`double_discount_confirm_required` si falta `confirm_double_discount=1`. El JS de `admin.js`
`initOpenInvoice` (`:647-667`) y `initRecordPayment` (`:633-645`) debe detectar ese error y mostrar un
segundo `confirm()` con la advertencia; si el comerciante acepta, reenvía el POST con
`confirm_double_discount: 1`; si cancela, **no** llama a la API. Las strings van en
`get_script_strings()` (junto a `:734-736`). Cubre REQ-OWN-06 y NFR-06. Esta tarea **no** implementa la
guarda server (es `T2.5`/`T2.6`); sólo la UX del flag.

**Desarrollo técnico — `admin.js`**, patrón sobre `initOpenInvoice` (`:647-667`):

```js
function postOpen(orderId, confirmed) {
    $.ajax({
        url: alegraConnector.ajaxUrl, type: 'POST',
        data: { action: 'alegra_open_invoice', _ajax_nonce: alegraConnector.nonce,
                order_id: orderId, confirm_double_discount: confirmed ? 1 : 0 },
        success: function (r) {
            if (r.success) { showNotice(safeMsg(r, S.invoiceOpened), 'success'); setTimeout(function(){ location.reload(); }, 1200); return; }
            var code = r.data && r.data.code;
            if (code === 'double_discount_confirm_required') {
                if (confirm(S.confirmDoubleDiscount)) { postOpen(orderId, true); }
                return; // cancelar: no se llama de nuevo
            }
            showNotice(safeMsg(r, S.error), 'error');
        }
    });
}
```

El mismo patrón aplica a `initRecordPayment` (`:633-645`). La guarda de `confirm()` previa (`:650`) se
mantiene para el flujo normal.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Segundo diálogo + reenvío con `confirm_double_discount=1` (ELEGIDA)** | La confirmación queda en el server (auditable) y el cliente sólo la dispara. |
| B — Sólo `confirm()` de cliente sin flag | **Rechazada:** el AJAX se puede llamar directo y saltear el aviso. |
| C — Bloquear en modo `adjustment` | **Rechazada:** el requerimiento dice "NO DEBE bloquear: el comerciante decide". |

**Resultado esperado**: en `adjustment`, abrir/registrar pago pide confirmación; aceptar procede y deja
nota+log; cancelar no toca la API; en `invoice` o sin ajuste emitido no hay diálogo.

**Dependencias**: `T2.5` (guarda open), `T2.6` (guarda pago).

**Trazabilidad**: REQ-OWN-06, NFR-06; `design.md` §2.2.1 (`:272-306`); `spec.md:317-356`.

**Verificación**: cubierto por `T30.25` (y `T30.26`). Manual: en `adjustment`, vender, abrir la factura y
ver el diálogo. **Prove-it-catch:** heredado de `T30.25` (quitar la guarda server ⇒ el flag deja de
importar y el test falla).

**Riesgo**: que el diálogo aparezca en `invoice` (ruido). Se mitiga: el server sólo exige el flag en
`adjustment` **y** con ajuste emitido (`T2.5`).

**Estimación**: S (2 h).

---

## Criterios de aceptación (checklist Fase 6)

| # | Resultado verificable (WP admin) | REQ | Tareas | Tests (`T30.x`) |
|---|---|---|---|---|
| 1 | Ajustes → "Dueño del stock" con Automático/Factura/Ajuste + nota *"Elegí UNO solo…"* + dueño efectivo | REQ-OWN-07 | `T6.1` | `T30.65` |
| 2 | Modo `invoice` fuerza/avisa la apertura al pagar; modo `adjustment` advierte la apertura manual; `invoice_status=open` avisa divergencia | REQ-OWN-04/07 | `T6.2` | `T30.65` |
| 3 | "Facturas por subir" lista los 5 estados con columnas, filtros y paginación; nunca-intentado distinguido | REQ-QUEUE-03/08 | `T6.3` | `T30.61`, `T30.62` |
| 4 | "Reintentar" (single) sube, actualiza el ledger y no duplica | REQ-QUEUE-04 | `T6.4` | `T30.48` |
| 5 | "Reintentar seleccionados" procesa de a 10 sin tocar el import | REQ-QUEUE-05 | `T6.5` | `T30.67` |
| 6 | Badge = conteo cacheado; aviso dismissible; dismiss per-user | REQ-QUEUE-07 | `T6.6` | `T30.63`, `T30.64` |
| 7 | Fila "Ventas sin factura" visible (por `T5.3`) + link a la cola | REQ-RECON-01/02 | `T6.7` | `T30.66` |
| 8 | Diálogo de confirmación en `adjustment` que reenvía `confirm_double_discount=1` | REQ-OWN-06 | `T6.8` | `T30.25` |

---

## DoD Fase 6 (checklist de cierre)

- [ ] `T6.1`: selector + nota literal + dueño efectivo; `register_setting` de `stock_owner` en
      `Admin_Dashboard.php:353` (hecho en `T2.7`/`T2.8`).
- [ ] `T6.2`: coerción UI + avisos (fuera del `if` de `:109`) + aviso `invoice_status=open`.
- [ ] `T6.3`: submenu + `templates/admin-invoice-queue.php` con las columnas/filtros/paginación.
- [ ] `T6.4`: `ajax_retry_invoice` + JS; nonce/cap + `run_explicit`.
- [ ] `T6.5`: `scope=failed` en el chunked; `alegra_batch_state` intacto.
- [ ] `T6.6`: badge option-backed + aviso dismissible + dismiss per-user.
- [ ] `T6.7`: link a la cola en `admin-dashboard.php:208-210`.
- [ ] `T6.8`: confirmación JS con `confirm_double_discount=1`; cancelar no llama a la API.
- [ ] `T30.61`–`T30.67` verdes con prove-it-catch; `bash scripts/smoke-test.sh` → `SMOKE OK`.
- [ ] Strings nuevas en la tabla i18n canónica y en `get_script_strings()` (las de JS).
- [ ] Aceptación manual de la UI firmada (selector, cola+badge+aviso, fila dashboard+link).
