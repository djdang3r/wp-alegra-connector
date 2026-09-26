# ANÁLISIS — El modelo "todas las ventas de WC se facturan en Alegra"

| Campo | Valor |
|---|---|
| Modelo del comerciante | *"La idea es que todas las ventas del ecommerce sean facturadas en alegra"* |
| Alcance | Ciclo de vida pedido→factura, interacción con stock, edge cases, reconciliación, config + invariante |
| Modo | **READ-ONLY** — no se modificó código de producción |
| Regla | Todo claim cita `archivo:línea`. Lo no verificado en el repo se marca **HYPOTHESIS** |
| Base analizada | `wp-alegra-connector/` (versión en disco, 2.6.x) |

> **Veredicto corto.** El modelo "todas las ventas facturadas" **es implementable hoy**, pero **NO es seguro por defecto** contra el fallo de una factura. La protección anti-re-inflación (`Inventory_Pusher::owner() === 'invoice'`) **sólo funciona si el producto ya tiene baseline** `_alegra_stock_synced`. Con baseline ausente (producto importado y nunca "poleado") **el poll re-infla igual** y la venta se pierde. Además, cuando la factura falla, el poll **deja de escribir WC** (bien) pero **nunca corrige Alegra** (mal): la divergencia queda silenciosa y el reporte de "Ventas sin factura" está **oculto exactamente cuando `push_orders_enabled=true`** (`Admin_Dashboard.php:903-905`). Falta la pata de reconciliación.

---

## 0. Glosario de actores y opciones

| Opción | Default | Rol verificado |
|---|---|---|
| `alegra_connector_push_orders_enabled` | `false` (`alegra-connector.php:418`) | Registra los hooks de pedido (`Public_.php:49-59`) **y** es la compuerta de entidad `invoice`/`credit_note` (`Write_Gate.php:38-39`). Es el interruptor maestro del modelo. |
| `alegra_connector_open_invoice_on_paid` | `true` (`alegra-connector.php:460`) | Con `push_orders=true`, si es `true` ⇒ `Inventory_Pusher::owner()` devuelve `invoice` (`Inventory_Pusher.php:85-91`). |
| `alegra_connector_invoice_status` | `draft` (`alegra-connector.php:462`) | Estado de creación cuando **no** hay override (`Orders.php:1246-1249`). |
| `alegra_connector_push_customers_enabled` | `false` (`alegra-connector.php:424`) | **Requisito oculto**: sin esto no se pueden crear contactos ni el Consumidor Final en modo automático (`Consumidor_Final.php:430-435`; `Write_Gate.php:41`). |
| `alegra_connector_payment_account_id` | `''` (`alegra-connector.php:463`) | Sin cuenta configurada **no se registra pago** (`Orders.php:508-510`, `725-728`). |
| `alegra_connector_inventory_source` | `alegra` (`alegra-connector.php:445`) | `woocommerce` ⇒ el poll **no toca stock** (`Products.php:1170-1174`). |
| `alegra_connector_push_inventory_enabled` | `true` (`alegra-connector.php:451`) | Habilita el push de ajustes (sólo dueño `adjustment`, `Inventory_Pusher.php:177-179`). |
| `alegra_connector_auto_complete_order` | `true` | El poll/webhook completan el pedido cuando la factura está pagada (`Orders.php:2409,2448-2456`; `Handlers.php:188,227-236`). |

**`owner()` — la decisión central** (`Inventory_Pusher.php:85-91`):

```php
return (get_option('alegra_connector_push_orders_enabled', false)
    && get_option('alegra_connector_open_invoice_on_paid', true))
    ? 'invoice' : 'adjustment';
```

- `owner='invoice'` ⇒ el pusher **no emite ajustes** (`Inventory_Pusher.php:170-173`); el stock de Alegra lo mueve la factura.
- `owner='adjustment'` ⇒ el pusher emite `POST /inventory-adjustments` con el delta (`Inventory_Pusher.php:249-251`).

---

## 1. Mapa del ciclo de vida (estado → ¿factura? → ¿stock?)

### 1.1 Hooks registrados (VERIFIED)

`Public_.php:49-67` (sólo si `push_orders_enabled=true`):

| Evento WC | Hook | Método | Acción → método destino |
|---|---|---|---|
| Pedido creado (pending) | `woocommerce_new_order` `:50` | `on_new_order` `:249` | `'create'` → `Orders::create_invoice()` (`Controller.php:383-384`) |
| Pago completado | `woocommerce_payment_complete` `:51` | `on_payment_complete` `:267` | `'complete'` → `Orders::create_invoice_with_payment()` (`Controller.php:385-386`) |
| Completado | `woocommerce_order_status_completed` `:52` | `on_order_completed` `:255` | `'complete'` → `create_invoice_with_payment()` |
| En espera | `woocommerce_order_status_on-hold` `:53` | `on_order_on_hold` `:273` | `'create'` → `create_invoice()` |
| Cancelado | `woocommerce_order_status_cancelled` `:57` | `on_order_cancelled` `:261` | `'cancel'` → `Orders::void_invoice()` (`Controller.php:389-390`) |
| Fallido | `woocommerce_order_status_failed` `:58` | `on_order_failed` `:279` | `'cancel'` → `void_invoice()` |

**Siempre activos, independientes del gate** (`Public_.php:61-67`): `on_order_paid_reconcile` en `payment_complete` / `processing` / `completed`. **Nunca crea factura**, sólo registra pago sobre factura ya vinculada (`Public_.php:294-347`; `Orders.php:715-745`).

**Refunds**: dueño exclusivo `State_Sync::handle_refund()` vía `woocommerce_order_refunded` (`State_Sync.php:37-39`). El comentario en `Public_.php:54-56` documenta que hookear `status_refunded` emitía **dos** notas de crédito; por eso NO está registrado.

### 1.2 Tabla estado → factura → stock

| Estado / evento | ¿Se llama a Alegra? | Factura resultante | Stock WC | Stock Alegra |
|---|---|---|---|---|
| `pending` (creado) | Sí (`on_new_order`) | **Borrador** (`invoice_status=draft`, `Orders.php:1246`) | No cambia | **No cambia** (premisa: draft no mueve stock — HYPOTHESIS R1) |
| `on-hold` | Sí (`on_order_on_hold`) | Borrador (idem) | WC core puede reducir — HYPOTHESIS | No cambia (draft) |
| `processing` | **No crea**; sólo `on_order_paid_reconcile` (`:66`) registra pago si ya hay factura | La del `pending`/`payment_complete` | Ya reducido por WC core | Ya movido por la factura abierta |
| `payment_complete` | Sí (`on_payment_complete` + reconcile `:65`) | **Se abre** el borrador existente si `owner=invoice` (`Orders.php:88-101`) | WC core reduce — HYPOTHESIS | **Baja** al abrirse (HYPOTHESIS R1) |
| `completed` | Sí (`on_order_completed` + reconcile `:67`) | Abre/crea; pago | Ya reducido | Ya movido |
| `cancelled` | Sí (`void_invoice`) | **Anulada** (`Orders.php:1157-1188`) | WC core **restaura** — HYPOTHESIS | ¿Revierte? — HYPOTHESIS |
| `failed` | Sí (`void_invoice`) | Anulada | WC core restaura — HYPOTHESIS | HYPOTHESIS |
| `refunded` | Sí (`State_Sync::handle_refund`) | **Nota de crédito** (`Orders.php:967-1155`) | WC restaura si el refund reingresa stock | ¿Revierte stock? — HYPOTHESIS |

**Hallazgo 1.1 — el modelo crea un borrador por CADA pedido, incluido el no pagado.** `on_new_order` corre en `woocommerce_new_order` (`Public_.php:50`), que dispara al crear el pedido (checkout), antes del pago. `create_invoice()` con `owner=invoice` y pedido **no** pagado NO aplica el override `'open'` (`Orders.php:119-126`), así que nace `draft`. Un pedido abandonado deja un borrador huérfano en Alegra. Un pedido fallido/cancelado lo anula (`void_invoice`). **Riesgo**: ruido de borradores; si el comerciante nunca los abre, no mueven stock (correcto) pero ensucian la bandeja.

**Hallazgo 1.2 — `pending` crea borrador, pero el stock se mueve al pagar.** El flujo real es: checkout → borrador; pago → WC reduce stock y el plugin **abre** el borrador (FIX-3, `Orders.php:85-101`). Esto es correcto para el modelo, pero introduce la **ventana** analizada en §2.

### 1.3 El override de estado al pagar (VERIFIED)

`Orders.php:82-126`:
1. Si el pedido ya tiene `_alegra_invoice_id` y `owner=invoice` + `open_invoice_on_paid` + `is_paid()` ⇒ `ensure_invoice_open()` (`:88-101`). Es **best-effort**: si Alegra no abre, se loguea y se devuelve `already_exists` sin error.
2. Si no tiene id, busca una factura `open` preexistente (`find_open_invoice_for_order`, `:111-117`, `:437-449`).
3. Si no, y `owner=invoice` + pagado ⇒ `status_override='open'` (`:122-126`).

`prepare_invoice_data()` fija `status` con el override (`Orders.php:1246-1249`), `currency` desde el pedido (`:1256`) y `observations = "Pedido WooCommerce #<id>"` (`:1257`), que es el marcador de idempotencia.

---

## 2. Interacción con stock y ventana de fallo (LA parte crítica)

### 2.1 Quién reduce el stock de WC

- **No lo hace el plugin.** No existe hook a `woocommerce_reduce_order_stock` (grep: 0 matches). Lo hace **WooCommerce core** (`wc_maybe_reduce_stock_levels`) al pasar a `processing`/`completed`/`on-hold`/`payment_complete`, y lo **restaura** en `cancelled`/`failed`/`refunded` — **HYPOTHESIS** (comportamiento de WC core, no está en este repo).
- **El plugin escucha el resultado**: `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` (`Inventory_Pusher.php:100-101`). Cualquier cambio de stock de WC dispara `on_stock_changed()` (`:114-125`).

**Orden de ejecución (HYPOTHESIS).** Ambos —WC core y plugin— enganchan a las mismas acciones con prioridad 10. A igual prioridad, WordPress ejecuta en orden de registro, y WC core se registra antes que el plugin ⇒ **WC reduce stock primero, el plugin factura después**. La ventana entre ambos es la duración del request de facturación (una o más llamadas HTTP a Alegra).

### 2.2 El ledger anti-re-inflación

El poll (`Products.php:1142-1453`) decide si escribir WC o empujar a Alegra con `needs_reconcile` (`Products.php:1329-1353`):

```php
$synced  = Inventory_Pusher::synced($product_id);   // _alegra_stock_synced
$pending = Inventory_Pusher::pending($product_id);  // _alegra_stock_push_pending
$needs_reconcile = $product->get_manage_stock()
    && ($pending !== ''
        || ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced));
if ($needs_reconcile) {
    $push = (new Inventory_Pusher(...))->push_delta($product, $wc_qty, true);
    if (in_array($push['reason'], ['ok','already_applied','in_sync','api_error',
                                  'blocked','locked','baseline_unverified'], true)) {
        continue;   // NO escribe WC
    }
}
// …si no, escribe WC con el valor de Alegra (Inventory_Writer, :1355-1392)
```

**Clave 1:** `push_delta()` con `owner=invoice` retorna temprano en el guard 2 (`Inventory_Pusher.php:170-173`) con `reason='invoice_owner'`, que **está** en la lista `$handled` ⇒ `continue` ⇒ **el poll NO pisa WC**. Ésta es la protección.

**Clave 2 (el agujero):** el guard 2 corta **antes** de la lógica de baseline (`:198-216`). Por lo tanto, con `owner=invoice`:
- El hook **nunca** setea `_alegra_stock_push_pending` (el `set_pending()` de `:205` es inalcanzable).
- `_alegra_stock_synced` **sólo** se setea en el camino del poll que escribe WC (`Products.php:1378`) — y en el push de ajustes, que con `owner=invoice` no corre (`Inventory_Pusher.php:214,244,271`).

**Consecuencia:** para un producto cuyo `_alegra_stock_synced` está vacío, `needs_reconcile = false` (porque `pending=''` y `synced=''`), el poll **cae al `Inventory_Writer` y escribe el valor de Alegra en WC** (`Products.php:1355-1392`). **Re-inflación garantizada.**

### 2.3 Los cuatro escenarios de venta (con números)

Producto: WC 10 / Alegra 10. Se venden 3 (WC queda 7). `owner='invoice'`.

**Escenario A — factura OK, baseline presente** (`synced=10`):
1. WC core: WC 10→7.
2. Plugin: abre la factura ⇒ Alegra 10→7.
3. Poll: `synced=10`, WC=7 ⇒ `needs_reconcile=true` ⇒ `push_delta` ⇒ `invoice_owner` ⇒ `continue`. WC queda 7.
4. **Resultado:** WC 7 / Alegra 7. ✅ Seguro. (Pero ver Hallazgo 2.4: el baseline queda viejo.)

**Escenario B — factura FALLA, baseline presente** (`synced=10`):
1. WC 10→7.
2. `ensure_invoice_open`/`create_invoice` falla ⇒ Alegra sigue 10. `trigger_sync` escribe una nota en el pedido (`Public_.php:403-422`, BUG 7) y **no reintenta**.
3. Poll: `needs_reconcile=true` ⇒ `invoice_owner` ⇒ `continue`. **No re-infla WC** (bien), **no corrige Alegra** (mal).
4. **Resultado:** WC 7 / Alegra 10. La venta **no se reflejó en Alegra**. Divergencia silenciosa. Si más adelante se pierde/limpia `_alegra_stock_synced`, el poll re-infla WC a 10 ⇒ **sobreventa**.

**Escenario C — factura FALLA, baseline AUSENTE** (`synced=''`):
1. WC 10→7.
2. Factura falla ⇒ Alegra 10.
3. Poll: `pending=''` y `synced=''` ⇒ `needs_reconcile=false` ⇒ escribe Alegra (10) en WC.
4. **Resultado:** WC **10** / Alegra 10. **La venta se perdió y se re-infló.** ❌ Éste es el riesgo que el comerciante reportó, **aún con `owner=invoice`**.

¿Cuándo `synced` está vacío? El import (`update_product_from_alegra` → W1 → `Inventory_Writer`) **no** setea `_alegra_stock_synced` (grep: `set_synced` sólo en `Products.php:1378` y `Inventory_Pusher.php:214,244,271`). Un producto recién importado y vendido **antes** de su primer poll cae en C. Igual si se borra el postmeta (reimport, limpieza, migración).

**Escenario D — `owner='adjustment'`** (no es el modelo del comerciante): el hook empuja el delta a Alegra (`Inventory_Pusher.php:249-251`); si falla, queda `pending` y el poll reintenta. **Se auto-sana**, pero no factura.

### 2.4 Hallazgo 2.4 — el baseline nunca se refresca tras abrir la factura (VERIFIED)

Al abrir la factura (`Orders.php:88-101` / `ensure_invoice_open`, `:850-913`) **no se actualiza** `_alegra_stock_synced`. Con el escenario A: WC 7, Alegra 7, `synced` sigue **10**. En cada poll posterior `needs_reconcile=true` (WC 7 ≠ synced 10) ⇒ `invoice_owner` ⇒ `continue`. El poll **queda congelado para ese producto para siempre**: nunca más baja a WC un cambio de stock hecho en Alegra. El diseño reconoce la desactualización "hasta abrir la factura" (`design.md:892`, D9.4), pero **no cubre el estado post-apertura**. Es una **starvation** silenciosa (el producto nunca se reconcilia).

### 2.5 Refunds (VERIFIED)

- `State_Sync::handle_refund()` (`State_Sync.php:72-203`) exige `_alegra_invoice_id` (`:128-131`), aplica tope acumulado (`:133-145`) y llama `Orders::create_credit_note_for_refund()` (`:161`).
- La nota de crédito reusa las líneas de la factura en reembolso total (`Orders.php:1014-1030`) o el ítem genérico "Ajuste" en parcial (`:1058-1076`).
- La compuerta de entidad `credit_note` es `push_orders_enabled` (`Write_Gate.php:39`): **en modo manual NO se emite** nota de crédito automática.
- **¿La nota de crédito restaura stock en Alegra?** Depende de la cuenta/ítem (los credit notes de Alegra pueden o no mover inventario). **HYPOTHESIS** — no verificable en el repo. Si **no** lo restaura y `owner=invoice`, un reembolso deja Alegra con stock **bajo** (la venta descontó y el reembolso no reingresó). Riesgo de faltante fantasma.

---

## 3. Tabla de edge cases

| # | Caso | Comportamiento actual (cita) | Riesgo |
|---|---|---|---|
| E1 | **Guest sin email/datos** | `ensure_customer_synced()` cae al Consumidor Final (`Orders.php:1465-1487`). Si `customer_resolution_mode='require_data'`, aborta con `customer_unresolved` (`:1468`). | **Medio.** El CF requiere `push_customers_enabled=true` (`Consumidor_Final.php:430-435`); si no, la factura **aborta**. La nota de fallback avisa (`Orders.php:1501-1535`). |
| E2 | **Pagos múltiples / parciales** | `prepare_payment_data()` siempre registra `$order->get_total()` (`Orders.php:1978`); `assert_full_payment_matches_balance()` **avisa** pero no ajusta (`:2041-2067`). `is_multi_payment()` sólo se usa para el cambio de método (`State_Sync.php:360-371`). | **Medio-alto.** Un pago parcial que no marca `is_paid()` no registra pago; si lo marca, se postea el total ⇒ saldo descuadrado en Alegra. |
| E3 | **Producto sin `_alegra_item_id`** | `resolve_item_alegra_id()` intenta meta → SKU → **push del producto** (`Orders.php:1916-1958`). Si todo falla, `prepare_invoice_items()` aborta con `invoice_item_unlinked` (`:1668-1677`). | **Alto para "todas las ventas".** Un producto no vinculable **rompe la factura entera**; no hay factura parcial. |
| E4 | **Productos variables (variaciones)** | `resolve_item_alegra_id()` prioriza la variación (`:1918-1921`). `Inventory_Writer` marca al padre como `skipped_parent` (`:61-64`). El push de stock registra `woocommerce_variation_set_stock` (`Inventory_Pusher.php:101`). | **Medio.** REQ-INV-03 (`spec.md:483-516`) documenta que el camino **update** no recorre hijos ⇒ el stock de variaciones puede quedar viejo. |
| E5 | **Shipping / taxes / fees** | Se mapean como líneas con el ítem genérico "Ajuste" (`Orders.php:1750-1839`), find-or-create por `reference` (`:1852-1900`). Impuestos por línea (`:1728-1737`, `:2200-2271`). | **Bajo.** Si no se puede resolver el ítem genérico, **aborta** la factura (`:1767-1772`, `:1820-1825`). |
| E6 | **Moneda no-COP** | `currency.code = $order->get_currency() ?: option` (`Orders.php:1256`). El panel ofrece COP/USD/MXN/EUR/ARS (`admin-settings.php:164-168`). | **Bajo-medio.** Si la moneda del pedido no existe en la cuenta Alegra, Alegra rechaza. Sin validación previa. |
| E7 | **Pedido editado tras facturar** | **No hay hook** de `woocommerce_saved_order_items`/`order_updated` que actualice la factura. Sólo `handle_payment_method_change` en `woocommerce_process_shop_order_meta` (`State_Sync.php:55-57`). | **Alto.** Ítems agregados/quitados tras facturar ⇒ factura desactualizada; el stock de WC puede cambiar sin contrapartida en Alegra. |
| E8 | **Factura duplicada en retry** | Lock atómico por pedido 30 s (`Orders.php:70-79`) + guard `_alegra_invoice_id` (`:82-107`) + pre-búsqueda por `observations` (`find_existing_invoice`, `:309-340`) + recuperación de pago (`find_existing_payment`, `:459-492`). | **Bajo.** Sólo si el POST commitea, la respuesta se pierde, el lock expira (>30 s) y `GET /invoices` no ve la factura aún. |
| E9 | **Mismo producto en 2 pedidos en segundos** | Lock por pedido (distinto) + lock por producto en el push (`Inventory_Pusher.php:226-230`). Con `owner=invoice`, el stock lo mueven **dos facturas** independientes. | **Bajo-medio.** Dos facturas abiertas descuentan dos veces (correcto si son dos ventas). El poll concurrente puede ver un estado intermedio. |
| E10 | **`push_customers_enabled=false`** | `contact` ⇒ `entity_disabled` (`Write_Gate.php:41,114-131`); el CF tampoco se crea (`Consumidor_Final.php:430-435`). | **CRÍTICO para el modelo.** Un cliente nuevo (o guest) **no puede facturarse** hasta habilitar el push de clientes. Es un requisito oculto de la config. |
| E11 | **Borrador creado y nunca pagado** | Queda `draft` indefinidamente. `get_unjournaled_sales()` **no lo detecta** (tiene `_alegra_invoice_id`; el reporte busca `NOT EXISTS`, `Admin_Dashboard.php:870`). | **Medio.** Borradores huérfanos invisibles; no mueven stock (correcto) pero no hay limpieza. |
| E12 | **Factura `void` en Alegra** | El webhook/poll escribe nota y **no** cancela el pedido (`Orders.php:263-297`; `Handlers.php:215-225`). | **Bajo.** El comerciante decide; el stock de la venta no se restaura en WC automáticamente. |

---

## 4. Reconciliación

### 4.1 Qué existe hoy

| Mecanismo | Cobertura | Límite / gate |
|---|---|---|
| `Admin_Dashboard::get_unjournaled_sales()` (`:862-887`) | Pedidos `processing`/`completed` con `_alegra_invoice_id` **NOT EXISTS** | `limit=20`; **NO detecta** meta vacío (`= ''`) ni factura `draft`/`void`; y **se oculta** con `push_orders=true` (`:903-905`) |
| "Ventas sin factura" (dashboard) | Render de lo anterior | **Gate invertido**: sólo se muestra en modo manual (`admin-dashboard.php:197-212`) |
| `sync_recent()` (`Orders.php:1190-1222`) | `processing`/`completed` sin factura, últimos N días, `limit=100` | **Manual** (botón "Facturar pendientes", `ajax_sync_pending_orders`, `Admin_Dashboard.php:3838-3865`) |
| `ajax_sync_pending_start/page` (`:3867-3968`) | `processing`/`completed`/`on-hold`, `limit=100`, lotes de 10 | **Manual**; estado en transient 600 s |
| `reconcile_missing_payments()` (`Orders.php:754-823`) | Pago faltante en factura **ya vinculada** | **No crea facturas** (`:721`); horario; lote `payment_reconcile_batch=20` |
| Poll de estados (`Orders.php:2358-2464`) | Estado de factura vinculada; auto-completa | Lote `orders_poll_batch=20` |

### 4.2 El problema de fondo

Con `push_orders_enabled=true` —el modo del comerciante— **la única reconciliación visible se apaga** (`Admin_Dashboard.php:903-905`). Una factura que falló al crearse no deja `_alegra_invoice_id`, así que *sería* detectada por `get_unjournaled_sales()`, pero la fila no se muestra. La única señal es la **nota del pedido** (`Public_.php:406-422`) y el log. **No hay cron que reintente facturar.** La promesa "todas las ventas facturadas" no tiene red de seguridad.

### 4.3 Propuesta de reconciliación (READ-ONLY, a implementar)

1. **Des-invertir el gate del reporte.** `get_unjournaled_sales()` debe correr **siempre**, y su semántica debe ampliarse a: pedidos `processing`/`completed` (y opcional `on-hold`) cuyo `_alegra_invoice_id` esté **ausente, vacío, o con estado `draft`/`void`**. Mostrar el conteo en el dashboard sin importar `push_orders_enabled`.
2. **Cron de reconciliación de facturación.** Un hook periódico (p. ej. `alegra_connector_orders_reconcile`) que:
   - listé pedidos pagados sin factura `open`/`paid` (mismo criterio que (1)),
   - llame `create_invoice_with_payment()` en lotes acotados (patrón de `ajax_sync_pending_page`, `Admin_Dashboard.php:3935-3951`),
   - respete kill switch, lock global, cancelación y presupuesto,
   - deje señal **fail-loud** (nota + log + fila del dashboard) cuando no pueda.
3. **Corregir el baseline tras abrir la factura.** Al abrir/crear la factura que mueve stock, actualizar `_alegra_stock_synced` al nuevo valor acordado (o registrar el delta de la factura), para que el poll no quede congelado (§2.4).
4. **Informe de divergencia de stock.** Comparar, por producto vinculado, stock WC vs `availableQuantity` de Alegra y listar los que difieren, con la causa (factura fallida / baseline ausente / reembolso). Base: REQ-INV-07 (`spec.md:616-638`).
5. **Reparación de divergencia existente.** Para cada producto divergente:
   - si `owner='invoice'` ⇒ emitir la factura faltante (si hay pedido) **o** un `POST /inventory-adjustments` correctivo por el delta, de forma **explícita** (nunca ambos para el mismo movimiento, REQ-INV-08);
   - si `owner='adjustment'` ⇒ el push por delta ya lo cubre (`Inventory_Pusher.php`).
6. **Cubrir el baseline ausente.** Al importar/crear un producto, setear `_alegra_stock_synced` con el `availableQuantity` de Alegra para que el primer poll no re-infle. (Hoy W1 no lo hace.)

---

## 5. Configuración recomendada + invariante

### 5.1 Config para "cada venta facturada"

| Opción | Valor | Motivo |
|---|---|---|
| `push_orders_enabled` | **`true`** | Habilita hooks de pedido y compuerta de factura/nota de crédito (`Public_.php:49`; `Write_Gate.php:38-39`). |
| `open_invoice_on_paid` | **`true`** | Hace que `owner()` sea `invoice` y la factura mueva stock (`Inventory_Pusher.php:85-91`). |
| `invoice_status` | **`draft`** | Borrador al crear; se abre al pagar. Evita cuentas por cobrar y stock movido en pedidos impagos. |
| `push_customers_enabled` | **`true`** | **Imprescindible**: sin esto no se crean contactos ni el CF y las facturas abortan (`Consumidor_Final.php:430-435`; `Write_Gate.php:41`). |
| `payment_account_id` | **configurada** | Sin cuenta no se registra el pago (`Orders.php:508-510,725-728`). |
| `payment_reconcile_enabled` | `true` | Registra pagos de facturas ya vinculadas (`Public_.php:308`). |
| `push_inventory_enabled` | `true` | Habilita el ajuste como **reconciliación**; con `owner=invoice` queda suprimido en runtime (`Inventory_Pusher.php:170-173`). |
| `inventory_source` | **`woocommerce`** *(o `alegra` con reconciliación)* | `woocommerce` **desactiva el poll** (`Products.php:1170-1174`) ⇒ elimina la re-inflación por completo. Contra: Alegra→WC deja de fluir. `alegra` mantiene el flujo pero reintroduce la ventana de §2.3. **Tradeoff explícito.** |
| `auto_complete_order` | `true` | Cierra el ciclo WC cuando la factura se paga (`Orders.php:2448-2456`). |

> **Advertencia operativa:** con esta config, **cada pedido crea un borrador** (incluido el impago). El comerciante debe saberlo y abrir/anular los que correspondan.

### 5.2 El invariante

> **INVARIANTE (ventas):** todo pedido de WC en estado pagado (`processing`/`completed`) tiene **exactamente una** factura de Alegra en estado `open`/`paid`.
>
> **INVARIANTE (stock):** cada venta mueve el stock de Alegra **exactamente una vez**, por **un solo** mecanismo (la factura **o** el ajuste, nunca ambos — REQ-INV-08), y el poll **nunca** sube el stock de WC por encima del valor vendido.

**Estado actual del invariante (VERIFIED):**

| Cláusula | ¿Se cumple? | Evidencia |
|---|---|---|
| Una factura por pedido pagado | **Parcial** | Idempotencia fuerte (`Orders.php:70-153`), pero un fallo no se reintenta ni se detecta (reporte oculto, `Admin_Dashboard.php:903-905`). |
| Un solo dueño del movimiento | **Sí** | `owner()` + guards 1-5 (`Inventory_Pusher.php:157-195`). Guard 6 (`Stock_Order_Context`) **no existe** (diseñado en `design.md:669-676`, ausente en disco). |
| El poll nunca re-infla | **Sí, SÓLO con baseline** | Con `synced=''` el poll **sí re-infla** (§2.3-Escenario C). |
| La venta se refleja en Alegra | **No garantizado** | Si la factura falla, Alegra no se corrige y el poll no empuja (`invoice_owner`). Divergencia silenciosa (§2.3-Escenario B). |

### 5.3 Arquitectura recomendada

- **La factura es el dueño único del movimiento** (`owner=invoice`). Correcto para el modelo.
- **El ajuste queda como reconciliación explícita**, no automático: se emite **sólo** cuando la factura no pudo cubrir el movimiento (fallo o pedido sin factura), con guarda anti-doble-conteo. Hoy el guard 2 lo suprime **siempre** con `owner=invoice`, incluso cuando la factura falló ⇒ la reconciliación debe ser un camino **explícito** (`Write_Gate::run_explicit()`), no el hook automático.
- **Cola/fallo:** el fallo de factura debe encolar un reintento (cron de §4.3) en lugar de quedar sólo como nota. Coordinar con el análisis de fallos: la factura fallida es un ítem de cola, no un log.
- **Baseline vivo:** sincronizar `_alegra_stock_synced` con cada movimiento de stock (incluida la apertura de factura) para que el ledger no quede ciego ni congelado.

---

## 6. Anexo — VERIFIED vs HYPOTHESIS

### VERIFIED (leído en el código de este repo)

- Hooks y acciones de pedido: `Public_.php:49-67`, `Controller.php:375-394`.
- `owner()`: `Inventory_Pusher.php:85-91`; guards de `push_delta()`: `:157-195`.
- Poll y ledger: `Products.php:1142-1453` (especialmente `:1329-1353`).
- Escritura única de stock: `Inventory_Writer.php:39-157`.
- `create_invoice` / override `open` / idempotencia: `Orders.php:63-193`, `:309-340`, `:437-449`.
- `create_invoice_with_payment` / pago: `Orders.php:494-540`, `:560-667`, `:1969-2067`.
- `ensure_invoice_open`: `Orders.php:850-913`.
- Nota de crédito: `Orders.php:926-1155`; `State_Sync.php:72-203`.
- `void_invoice`: `Orders.php:1157-1188`.
- Poll de estados / auto-complete: `Orders.php:2358-2464`; webhook: `Handlers.php:166-238`.
- Compuertas: `Write_Gate.php:37-47,114-131`.
- CF gated por `push_customers_enabled`: `Consumidor_Final.php:430-435`.
- Reporte de divergencia y su gate invertido: `Admin_Dashboard.php:862-887,901-905`; `admin-dashboard.php:197-212`.
- Defaults: `alegra-connector.php:414-468`.
- `set_synced` sólo en `Products.php:1378` y `Inventory_Pusher.php:214,244,271` (el import NO setea baseline).
- `Stock_Order_Context.php` **no existe** (guard 6 no implementado).

### HYPOTHESIS (fuera de este repo o no verificable aquí)

- **R1:** un borrador de Alegra **no** mueve stock y `open` **sí**. Es premisa explícita del diseño (`spec.md:444,1116`), marcada `BLOQUEADO(ON-VERIFICATION)`.
- **Timing WC-core:** WC reduce stock **antes** que el plugin facture (mismas acciones, prioridad 10, WC registrado primero).
- **Restauración de stock** de WC en `cancelled`/`failed`/`refunded`.
- **La nota de crédito restaura stock** en Alegra (depende de la cuenta).
- **Estado `on-hold`**: si WC reduce stock y si el pedido se factura como borrador.

---

## 7. Resumen de acciones sugeridas (priorizadas)

1. **[CRÍTICO]** Cubrir el baseline ausente: setear `_alegra_stock_synced` al importar/crear y al abrir la factura, para cerrar el Escenario C y la starvation de §2.4.
2. **[CRÍTICO]** Reconciliación de facturación: reporte siempre visible + cron de reintento de facturas fallidas (§4.3-1/2).
3. **[ALTO]** Documentar/validar `push_customers_enabled=true` como requisito del modelo (E10).
4. **[ALTO]** Manejar edición de pedido post-factura (E7): actualizar/anular la factura o avisar.
5. **[MEDIO]** Reconciliación de stock WC↔Alegra + reparación de divergencia existente (§4.3-4/5).
6. **[MEDIO]** Ampliar `get_unjournaled_sales()` a meta vacío y facturas `draft`/`void`; subir `limit`.
7. **[MEDIO]** Pagos parciales (E2) y refunds/stock (E-refund).
