# Análisis — Doble descuento de inventario (ajuste + factura) · 2.6.0

| Campo | Valor |
|---|---|
| Tipo | Análisis read-only (no modifica código de producción) |
| HEAD analizado | `2.6.0` (`alegra-connector.php`), tras el release del 2026-09-25 |
| Objeto | Riesgo de doble descuento entre `POST /inventory-adjustments` (dueño `adjustment`) y la factura manual de Alegra |
| Modelo del comerciante | **TODAS las ventas ecommerce se facturan en Alegra** |
| Método | Lectura de código en HEAD + lectura del core de WooCommerce (`/tmp/opencode/wc-release/woocommerce`) + diseño `sync-reliability` |
| Regla | Cada afirmación va marcada **VERIFIED** (leída en fuente) o **HYPOTHESIS** (inferida, requiere cuenta/host real) |

> **Veredicto en una línea.** Con los defaults de 2.6.0 (`push_orders_enabled=false` ⇒ `owner='adjustment'`,
> `push_inventory_enabled=true`) **cada venta de WC emite un ajuste**, y la factura manual que el
> comerciante abre después **vuelve a descontar**. Las guardas que el diseño creó para este escenario
> (`Stock_Order_Context`, Guard 6, `_alegra_stock_adjusted`, la advertencia manual) **no existen en el
> código**: cero ocurrencias fuera de `docs/`. El escenario reportado quedó **sin cobertura**.

---

## 0. Baseline de configuración (VERIFIED)

`alegra-connector.php:414-468` (`$defaults`):

| Opción | Default | Línea | Efecto |
|---|---|---|---|
| `alegra_connector_push_orders_enabled` | **`false`** | `:418` | No auto-factura |
| `alegra_connector_push_inventory_enabled` | **`true`** | `:451` | Registra los hooks del pusher |
| `alegra_connector_open_invoice_on_paid` | `true` | `:460` | Irrelevante con `push_orders=false` |
| `alegra_connector_invoice_status` | **`'draft'`** | `:462` | Factura manual nace borrador |
| `alegra_connector_inventory_source` | `'alegra'` | `:445` | El poll corre |
| `alegra_connector_inventory_sync_enabled` | `true` | `:449` | El poll corre |

`Inventory_Pusher::owner()` (`includes/Sync/Inventory_Pusher.php:85-91`):

```php
return (get_option('alegra_connector_push_orders_enabled', false)
    && get_option('alegra_connector_open_invoice_on_paid', true))
    ? 'invoice'
    : 'adjustment';
```

⇒ Con los defaults, `owner() === 'adjustment'`. **VERIFIED.**

Los hooks del pusher se registran siempre que `push_inventory_enabled` sea true
(`public/Public/Public_.php:80-82`), sin importar `owner()`. El dueño se decide en runtime dentro de
`push_delta()`. **VERIFIED.**

---

## 1. Matriz de escenarios de doble descuento

Leyenda de severidad: **CRÍTICO** = descuenta stock de más y afecta dinero/inventario; **ALTO** = riesgo
real condicionado; **MEDIO** = correctitud, no doble descuento directo; **SEGURO** = sin doble conteo.

### S1 — Venta WC → ajuste → factura manual (draft) → abrir el borrador · **CRÍTICO**

| Paso | Acción | Estado WC | Estado Alegra | Evidencia |
|---|---|---|---|---|
| 1 | Venta de 3 en WC | 10 → 7 | 10 | `wc_reduce_stock_levels()` → `wc_update_product_stock()` (`wc-stock-functions.php:207`) |
| 2 | Hook `woocommerce_product_set_stock` | 7 | 10 | `wc-stock-functions.php:75`; pusher `on_stock_changed` (`Inventory_Pusher.php:114-125`) |
| 3 | `push_delta(7)`; `owner()='adjustment'`; pasa Guards 1–5 | 7 | 10 | `Inventory_Pusher.php:157-190` |
| 4 | `POST /inventory-adjustments {out 3}` | 7 | **7** | `Inventory_Pusher.php:249-251` |
| 5 | Comerciante "Facturar" (manual) → factura **draft** | 7 | 7 | `Admin_Dashboard.php:3194` → `Controller.php:385` → `Orders::create_invoice_with_payment` (`Orders.php:494`) |
| 6 | Factura draft **no** mueve stock (HYPOTHESIS, G1 sin resolver) | 7 | 7 | `docs/sdd/sync-reliability/phase0-results.md:12` (`G1 PENDING-LIVE`) |
| 7 | Comerciante "Abrir factura" → `ensure_invoice_open` | 7 | **4** | `Admin_Dashboard.php:2840-2895`; `Orders.php:850-913` |

**Resultado:** Alegra descuenta 6 por una venta de 3. **Doble descuento.** El paso 7 no tiene ninguna
guarda: `ajax_open_invoice_impl()` (`Admin_Dashboard.php:2845-2895`) no consulta `owner()` ni
`_alegra_stock_adjusted` ni advierte. **VERIFIED (código); el descuento del paso 6/7 es HYPOTHESIS
(Alegra draft vs open) pero es la premisa del propio diseño (G1).**

### S2 — Venta WC → ajuste → factura manual con `invoice_status=open` · **CRÍTICO**

Mismo arranque que S1. En el paso 5, la ruta manual "Facturar" usa `create_invoice_with_payment()`
(`Orders.php:494-540`), que llama `create_invoice($order, 'open')` cuando hay cuenta configurada y el
pedido está pagado (`Orders.php:508-512`). El override de dueño (`Orders.php:122-126`) sólo actúa si
`status_override === null`; acá ya viene `'open'`, así que la factura **nace abierta** y mueve stock en
la creación. Con `invoice_status='open'` configurado y pago presente, el descuento ocurre en el paso 5
(sin necesidad de abrir). **VERIFIED (ruta); el movimiento de stock por factura abierta es HYPOTHESIS
(G1).** Severidad CRÍTICO.

### S3 — Factura automática (`push_orders=true`, `open_invoice_on_paid=true`) · **SEGURO**

`owner()` devuelve `'invoice'` ⇒ Guard 2 corta: `push_delta` retorna `invoice_owner` sin POST
(`Inventory_Pusher.php:171-173`). La factura (creada `open` si pagada, `Orders.php:122-126`) mueve el
stock una sola vez. **VERIFIED.** Es el único modo sin doble conteo.

### S4 — Factura automática creada impaga (draft) → paga después → se abre · **SEGURO con borde**

`owner='invoice'` ⇒ cero ajustes. La factura nace `draft` (no mueve stock, HYPOTHESIS). Al pagarse,
`create_invoice()` abre el borrador pre-existente (`Orders.php:88-101`, FIX-3 implementado) y mueve el
stock una vez. **Borde:** si `ensure_invoice_open()` no logra abrir el borrador (Alegra no documenta un
draft→open limpio, `Orders.php:875-912`), el stock **nunca** se mueve ⇒ subconteo, no doble. **MEDIO.**

### S5 — Reembolso/cancelación → stock restaurado → ¿nota de crédito/anulación restaura de nuevo? · **ALTO (HYPOTHESIS)**

Cancelar/refundir en WC dispara `wc_increase_stock_levels()` (`wc-stock-functions.php:351-412`) →
hook → `push_delta` emite un ajuste `in` (+N) (owner=adjustment). En paralelo, la nota de crédito
(`State_Sync::handle_refund` → `Orders::create_credit_note_for_refund`, `Orders.php:967`) o la anulación
de factura (`Controller.php:390` → `Orders::void_invoice`) pueden restaurar stock del lado Alegra. Si
Alegra **también** restaura por nota de crédito/anulación ⇒ **doble restauración** (stock inflado).
**HYPOTHESIS**: depende del comportamiento de Alegra ante credit-note/void, no verificable en este
entorno. Requiere check live (§7).

### S6 — Edición manual de producto en WC (sin pedido) → ajuste; luego edición en Alegra → poll · **MEDIO**

La edición manual en WC dispara `woocommerce_product_set_stock` ⇒ ajuste (owner=adjustment). El poll
(`Products.php:1142-1453`) luego lee Alegra y, si no hay delta local, escribe Alegra→WC. Dos ediciones
concurrentes (WC y Alegra) pueden oscilar/divergir. No es doble descuento por factura, pero es una
divergencia de stock. **VERIFIED (rutas); el resultado final depende del orden temporal.**

### S7 — Import masivo / `sync_products` · **SEGURO (no empuja ajustes)**

El import setea `set_syncing(true)` (`Products.php:1701`, `:1823`) y `update_product_from_alegra()`
setea el transient `alegra_updating_product_{id}` (`Products.php:2185`). El pusher corta por Guard 1
(`Inventory_Pusher.php:165-168` y `Public_.php:117`). Por lo tanto el import **no** emite
`POST /inventory-adjustments`. **VERIFIED.** (Nota: el diseño lo atribuía a `:1542`/`:1632`; en HEAD
las líneas reales son `:1701`/`:1823`.)

### S8 — Dos pedidos del mismo producto en rápida sucesión · **MEDIO**

El lock por producto `alegra_inventory_push_{id}` (TTL 30, `Inventory_Pusher.php:226-230`) hace que el
segundo hook concurrente devuelva `locked` **antes** de `set_pending` (`:228-230`), perdiendo ese delta
del camino del hook. El poll lo recupera luego (delta local `WC != synced`, `Products.php:1332-1342`),
pero si el poll no corre (`inventory_source=woocommerce`) queda perdido. Además, el pre-chequeo de
idempotencia (`adjustment_already_exists()`, `:303-335`) **no usa `reference`** (el diseño lo pedía,
`design.md:714`) y matchea por `item+type+quantity` en los últimos 30 ajustes: un `out 1` viejo puede
hacer que un `out 1` nuevo se marque `already_applied` y **no se emita** (fuga de stock). **VERIFIED
(código); la colisión es HYPOTHESIS.** No es doble descuento; es el bug inverso (Alegra demasiado alto).

### S9 — El ajuste falla (red) pero la factura se emite después · **CRÍTICO**

`push_delta` con `api_error` deja `_alegra_stock_push_pending` seteado y **no** toca `synced`
(`Inventory_Pusher.php:257-267`). Luego el comerciante factura (S1/S2) ⇒ la factura descuenta. En el
próximo poll, `needs_reconcile` es true por `pending` (`Products.php:1332-1334`) ⇒ `push_delta(...,
from_poll:true)` reintenta el ajuste ⇒ Alegra descuenta **por segunda vez** (factura + ajuste).
**VERIFIED (rutas).** Severidad CRÍTICO.

---

## 2. Guardas actuales (VERIFIED) vs. guardas ausentes (design-only)

### 2.1 Presentes en HEAD

| # | Guarda | Ubicación | Qué hace |
|---|---|---|---|
| G1 | `is_syncing()` / transient por producto | `Inventory_Pusher.php:165-168`; hook `Public_.php:117` | Corta la cascada del import/poll |
| G2 | `owner() !== 'adjustment'` | `Inventory_Pusher.php:171-173` | Cero ajustes si la factura es dueña |
| G3 | `push_inventory_enabled` | `Inventory_Pusher.php:177-179` | Toggle de negocio |
| G4 | `_alegra_item_id` vacío | `Inventory_Pusher.php:182-185` | Producto no vinculado |
| G5 | `!get_manage_stock()` | `Inventory_Pusher.php:188-190` | WC no gestiona stock |
| — | `owner()` condición doble | `Inventory_Pusher.php:85-91` | `invoice` sólo con `push_orders && open_on_paid` |
| — | Abrir borrador con `owner=invoice` | `Orders.php:88-101` | FIX-3, sólo modo invoice |
| — | `find_open_invoice_for_order()` | `Orders.php:437-449` | Evita 2ª factura si ya hay una `open` |
| — | Override `status='open'` | `Orders.php:122-126` | Sólo `owner=invoice` |
| — | Informe "Ventas sin factura" | `Admin_Dashboard.php:862-887`, gate `:903-905` | Lista read-only (pedidos sin `_alegra_invoice_id`) |
| — | Reconciliador del poll | `Products.php:1327-1379` | Empuja delta pendiente o escribe Alegra→WC |

### 2.2 Ausentes (especificadas en el diseño, cero en producción)

Búsqueda de `Stock_Order_Context|_alegra_stock_adjusted|current_order_id|woocommerce_reduce_order_stock|woocommerce_restore_order_stock`
en todo el repo: **las 5 cadenas aparecen sólo en `docs/`** (design.md), nunca en `includes/`, `admin/`,
`public/`.

| # | Guarda faltante | Dónde la especificó el diseño | Estado real |
|---|---|---|---|
| F1 | Clase `Stock_Order_Context` (`set()` / `current_order_id()`) | `design.md:58`, `:594-600`, `:1455-1459` | **No existe** `includes/Sync/Stock_Order_Context.php` |
| F2 | **Guard 6** — suprimir ajuste si el pedido ya tiene factura `open` | `design.md:669-678` | **No existe** en `push_delta` (`Inventory_Pusher.php:157-277`) |
| F3 | Meta `_alegra_stock_adjusted` | `design.md:602`, `:632`, `:1530-1531` | **No se escribe ni se lee** |
| F4 | Advertencia + confirmación en la factura manual | `design.md:601-604`, `:1481-1482` | **No existe** en `ajax_open_invoice_impl` (`:2845`) ni `ajax_record_payment_impl` (`:2902`) |
| F5 | Poblar el contexto desde los hooks de pedido | `design.md:62`, `:594-600` | **No existe** en `Public_.php` (sólo `:100-101` registra hooks de producto) |
| F6 | Gate **G9** (orden real de los hooks) | `design.md:1610` | **Nunca se ejecutó**: `phase0-results.md` lista G1–G8, no G9 |

### 2.3 Hallazgo estructural: la Guard 6 del diseño NO cubre el escenario del comerciante

Aun si F1/F2 se implementaran tal cual el diseño, **no detendrían S1/S2**. Guard 6 suprime el ajuste
cuando el pedido **ya** tiene factura `open` (`design.md:669-678`). En el escenario del comerciante el
orden es el inverso: **primero** el ajuste (al vender), **después** la factura. En el momento del ajuste
el pedido no tiene factura ⇒ `_alegra_invoice_status` está vacío ⇒ Guard 6 no dispara.

El escenario del comerciante sólo lo cubre F4 (advertencia manual) — y F4 **sólo advierte**, no impide.
Y F4/F3 dependen de F1 (asociar el ajuste a un pedido), que a su vez depende de F5/G9. **El eslabón
crítico es F1.** La sección 3 demuestra que F1, tal como se diseñó, **no es implementable**.

---

## 3. G9 / `Stock_Order_Context`: por qué el mecanismo diseñado no funciona (VERIFIED en WC core)

El diseño asume (Rama A de G9): *"`woocommerce_reduce_order_stock`/`woocommerce_restore_order_stock`
(con el pedido) corren **antes** de `woocommerce_product_set_stock` (con el producto)"* (`design.md:1610`).

**Es falso.** En el core de WC (`/tmp/opencode/wc-release/woocommerce/includes/wc-stock-functions.php`):

```
wc_reduce_stock_levels($order_id)                    // :170
  foreach ($order->get_items() as $item) {           // :185
      $new_stock = wc_update_product_stock($product, $qty, 'decrease');  // :207
          └─ do_action('woocommerce_product_set_stock', $product_with_stock);  // :75  ◀── ACÁ corre el pusher
      do_action('woocommerce_reduce_order_item_stock', $item, $change, $order); // :233
  }
  do_action('woocommerce_reduce_order_stock', $order);  // :238  ◀── DESPUÉS del pusher
```

Idéntico en la restauración (`wc_increase_stock_levels`, `:351-412`): `wc_update_product_stock()` en
`:381` dispara el hook de producto en `:75`; `woocommerce_restore_order_stock` recién en `:411`.

**Conclusión:** cuando corre `Inventory_Pusher::on_stock_changed()`, `Stock_Order_Context` poblado desde
`woocommerce_reduce_order_stock` estaría **vacío**. G9 resolvería **Rama B** (la guarda 1 se degrada a
la guarda 2), y esa degradación es justamente la que **no se implementó**.

**¿Hay una fuente de contexto viable?** Sí, pero distinta de la diseñada (**HYPOTHESIS de
implementación, a validar**): los filtros `woocommerce_can_reduce_order_stock` (`:178`) y
`woocommerce_can_restore_order_stock` (`:360`) reciben `$order` y corren **antes** del loop. Un
`add_filter(..., 1)` podría fijar el contexto y limpiarlo en `woocommerce_reduce_order_stock`/
`woocommerce_restore_order_stock` (que corren después). Es frágil (no se limpia si el filtro aborta la
operación; es request-scoped, no por pedido) pero es la única fuente pública que respeta el orden.
**No es una certeza: requiere test en vivo.**

---

## 4. Matriz de opciones

Criterios: complejidad, cobertura del modelo del comerciante, modos de falla, compatibilidad hacia atrás
y preocupación de plugin distribuido (sin cron real, sin Action Scheduler, sin `exec`, sin hooks de
Alegra fuera de WC).

### Opción A — La factura es el único dueño (detectar "order-driven")

**Mecanismo:** deshabilitar el ajuste para cambios originados en un pedido; ajustar sólo cambios sin
pedido (edición manual WC, POS-en-WC). Detección: contexto desde `woocommerce_can_reduce_order_stock`/
`woocommerce_can_restore_order_stock` (§3).

| Criterio | Evaluación |
|---|---|
| Complejidad | Media-alta: contexto request-scoped + limpieza + casos borde (filtro abortado, múltiples pedidos) |
| Cobertura | **Cubre S1/S2/S9** (ventas): no hay ajuste, la factura mueve stock una vez |
| Falla si… | La detección falla (falso negativo) ⇒ vuelve el doble descuento; un ajuste manual legítimo se suprime de más |
| Backward compat | Cambia el comportamiento de `owner=adjustment` (deja de cubrir ventas). Riesgo para instalaciones que hoy dependen del ajuste para ventas no facturadas |
| Distribuido | Depende sólo de hooks del core de WC (≥3.0) — OK |
| Venta nunca facturada | El stock **no** se mueve en Alegra ⇒ reaparece la re-inflación; mitigación: informe "Ventas sin factura" (`Admin_Dashboard.php:862-887`), que ya existe |

### Opción B — Implementar las guardas (F1–F5)

**Mecanismo:** `Stock_Order_Context` + Guard 6 + `_alegra_stock_adjusted` + advertencia manual.

| Criterio | Evaluación |
|---|---|
| Complejidad | Alta (contexto + asociación ajuste↔pedido + UI de confirmación) |
| Cobertura | Guard 6 cubre el orden **factura-primero** (no el del comerciante). La advertencia cubre el orden ajuste-primero, pero **sólo advierte** |
| Falla si… | El contexto no se puebla (G9 Rama B) ⇒ Guard 6 y `_alegra_stock_adjusted` nunca se setean ⇒ todo queda en la advertencia. El comerciante puede confirmar y volver a descontar |
| Backward compat | Aditiva, pero con la premisa falsa de G9 no protege |
| Distribuido | OK, pero depende de un orden de hooks que **no existe** en WC |

**Nota:** B por sí sola **no resuelve** el escenario del comerciante; sólo agrega una advertencia.

### Opción C — Revertir el ajuste cuando la factura se abre

**Mecanismo:** registrar el ajuste emitido y, al abrir la factura, emitir el inverso.

| Criterio | Evaluación |
|---|---|
| Complejidad | Muy alta: asociación ajuste↔pedido, idempotencia del inverso, concurrencia |
| Cobertura | S1/S2 si el tracking es perfecto |
| Falla si… | La factura se abre desde la **UI de Alegra** (fuera de WC) ⇒ no hay hook ⇒ no se revierte. Si el inverso se reintenta ⇒ sobre-restaura. La respuesta perdida del POST inverso ⇒ estado ambiguo |
| Backward compat | Muy invasiva |
| Distribuido | **Falla de raíz**: el evento "factura abierta" puede ocurrir fuera del plugin. No hay webhook de Alegra que garantice disparar la reversión a tiempo |

### Opción D — No abrir la factura si el ajuste ya movió stock (dejarla draft)

**Mecanismo:** con `owner=adjustment`, nunca abrir la factura; dejarla en borrador.

| Criterio | Evaluación |
|---|---|
| Complejidad | Baja |
| Cobertura | Evita el doble descuento **si** el draft no mueve stock (HYPOTHESIS, G1) |
| Falla si… | El draft **sí** mueve stock en la cuenta (G1 Rama B) ⇒ no sirve. Además el comerciante necesita una factura **emitida** (no un borrador) por requisito fiscal/contable ⇒ **rompe el modelo de negocio** |
| Backward compat | Rompe el flujo de facturación manual |
| Distribuido | OK técnicamente, pero inviable funcionalmente |

### Opción E — Desacoplar el dueño del stock de la auto-facturación (recomendada)

**Mecanismo:** el defecto de raíz es que `owner()` está atado a `push_orders_enabled` (`Inventory_Pusher.php:85-91`),
y `push_orders_enabled` **también** gobierna la auto-facturación (`Public_.php:49-59`). El comerciante
que factura **manualmente pero factura TODO** no puede elegir `owner=invoice` sin activar la
auto-facturación. Se propone una opción explícita `alegra_connector_stock_owner`
(`auto|invoice|adjustment`; default `auto` = lógica actual ⇒ compatibilidad total):

- `stock_owner=invoice` ⇒ Guard 2 corta **todos** los ajustes; la factura (manual o automática) mueve
  el stock. Es exactamente el modelo "todas las ventas facturadas".
- `stock_owner=adjustment` ⇒ comportamiento actual.
- `stock_owner=auto` ⇒ `owner()` derivado como hoy.

| Criterio | Evaluación |
|---|---|
| Complejidad | **Baja**: una opción + un `if` en `owner()`; sin tocar la detección de pedidos |
| Cobertura | **Cubre S1/S2/S9** para este comerciante (cero ajustes en modo invoice) |
| Falla si… | El comerciante no factura una venta ⇒ el stock no se mueve en Alegra (mitigado por el informe "Ventas sin factura") |
| Backward compat | **Total** con `auto` por default |
| Distribuido | Cero dependencias nuevas |

**Caveat (HYPOTHESIS, requiere G1 live):** si una factura `draft` **sí** mueve stock en la cuenta de
este comerciante, entonces `owner=invoice` + `invoice_status=draft` movería stock en la creación (una
sola vez, igual) — correcto. Si `draft` no mueve y el comerciante nunca abre, el stock no se mueve.

**Refinamiento opcional de E (defensa en profundidad):** implementar la advertencia manual (F4) de
forma **gruesa** — al abrir/registrar pago de una factura con `owner()==='adjustment'`, advertir
siempre: *"Los ajustes de inventario están activos; si ya se empujó un ajuste por este pedido, abrir la
factura descontará dos veces."* No requiere `Stock_Order_Context` y cubre a los comerciantes que se
queden en `adjustment`.

### Ranking

| Rank | Opción | Por qué |
|---|---|---|
| **1** | **E** (`stock_owner` desacoplado) | Mínima, cubre el 100% del modelo del comerciante, compatibilidad total, cero dependencias |
| 2 | A (invoice-only para order-driven) | Correcta arquitectónicamente, pero exige detección frágil y cambia el default de `adjustment` |
| 3 | B (guardas) | Necesaria como red de seguridad, pero **insuficiente sola** y bloqueada por G9 |
| 4 | D (dejar draft) | Simple pero rompe el requisito de facturar |
| 5 | C (revertir ajuste) | No funciona cuando la factura se abre fuera de WC |

### Recomendación

1. **Fix primario (para este comerciante):** implementar **E** (`alegra_connector_stock_owner`) y
   configurarlo en `invoice`. Cero ajustes por ventas; la factura manual (abierta) mueve el stock una
   sola vez. Alternativa cero-código inmediata: activar `push_orders_enabled=true` +
   `open_invoice_on_paid=true` (fuerza `owner=invoice`) y aceptar la auto-facturación — que es,
   literalmente, lo que el comerciante pidió ("todas las ventas facturadas").
2. **Fix secundario (red de seguridad):** implementar F4 en su forma gruesa (advertencia manual sin
   `Stock_Order_Context`).
3. **Si se quiere la guarda fina:** implementar F1/F2/F3 con la fuente de contexto **correcta**
   (`woocommerce_can_reduce_order_stock`/`woocommerce_can_restore_order_stock`, §3), no la del diseño.
   Marcar G9 como **Rama B** (documentar que el mecanismo original no es implementable).
4. **Correcciones adicionales:** agregar `reference` al payload de ajustes y al pre-chequeo de
   idempotencia (FIX-4 real, hoy ausente: `Inventory_Pusher.php:303-335`); y el guard `owner=invoice`
   en el writer del poll (el diseño lo pedía en `design.md:875-878` y no está en
   `Products.php:1332-1379`).

---

## 5. Migración y reparación de corrupción existente

### 5.1 ¿Qué pasa al cambiar la lógica del dueño en una instalación existente?

- **`owner()` es runtime** (`Inventory_Pusher.php:85-91`): no hay estado persistido del dueño. Cambiar
  a `stock_owner=invoice` surte efecto en el **próximo** movimiento, sin migración. **VERIFIED.**
- **Ledger `_alegra_stock_synced`:** en modo `adjustment` se fijó con cada push OK (`:271`) o con el
  pull del poll (`Products.php:1378`). En modo `invoice` el pusher no escribe `synced`; el poll puede
  fijarlo. Al cambiar de dueño, el ledger puede quedar **desactualizado** respecto de Alegra. No hace
  falta migrar el valor: el poll lo re-baselina en su primer contacto (diseño §3.4, `design.md:898-902`),
  siempre que `inventory_source=alegra` y `inventory_sync_enabled` (defaults, sí).
- **`_alegra_stock_push_pending`:** puede quedar sucio de un push fallido. Conviene limpiarlo al
  cambiar de dueño (si no, el poll intentará reconciliar y emitirá un ajuste en modo invoice; hoy
  `push_delta` retorna `invoice_owner` antes de mirar `pending` — `:171-173` — así que el `pending`
  queda como basura y el writer del poll escribe igual). **Limpieza recomendada, no obligatoria.**
- **`_alegra_stock_adjusted`:** no existe en HEAD ⇒ no hay nada que migrar.

### 5.2 ¿Cómo detectar y reparar un doble descuento YA existente?

No hay herramienta de reconciliación en el plugin. La reparación es **manual/asistida**:

1. **Identificar los pedidos afectados:** pedidos con `_alegra_invoice_id` **y** con al menos un
   ajuste emitido por el plugin para sus productos. El plugin **no** asocia ajuste↔pedido (F1 ausente),
   así que la detección es por logs (`Inventory adjustment` / `Inventory adjustment failed`,
   `Inventory_Pusher.php:259`) + `GET /inventory-adjustments?item_id={alegra_item}`.
2. **Cuantificar el exceso:** para cada producto, `qty_doble = Σ(cantidad ajustada de pedidos facturados)`.
   El stock en Alegra está `qty_doble` unidades por debajo del correcto.
3. **Reparar:** emitir un `POST /inventory-adjustments {type:'in', quantity: qty_doble}` compensatorio
   (por producto), o corregir el stock a mano en Alegra. Luego re-baselinar: `GET /items/{id}` y fijar
   `_alegra_stock_synced` = `availableQuantity` (o dejar que el poll lo haga).
4. **Prevenir recurrencia:** cambiar el dueño (§4) **antes** de re-baselinar, para que el ajuste
   compensatorio no vuelva a chocar con una factura.

> **Advertencia:** re-baselinar `_alegra_stock_synced` a un valor **mayor** que WC hará que el poll
> quiera empujar un `out` (delta negativo) para igualar. Si el comerciante quiere conservar el stock
> físico de WC, debe corregir **Alegra** (ajuste compensatorio) y no WC, o desactivar temporalmente
> `push_inventory_enabled`.

---

## 6. Checks live para confirmar el bug en la tienda del comerciante

> Ejecutar con la cuenta real y `wp-cli`. Ningún comando escribe; los `GET` son read-only.

**C1 — Confirmar el dueño efectivo (debe dar `adjustment`):**

```bash
wp option get alegra_connector_push_orders_enabled --allow-root   # esperado: 0/false
wp option get alegra_connector_push_inventory_enabled --allow-root # esperado: 1/true
wp option get alegra_connector_invoice_status --allow-root         # esperado: draft
wp eval 'echo \Alegra\Connector\Sync\Inventory_Pusher::owner();' --allow-root  # esperado: adjustment
```

**C2 — Confirmar que el plugin emite ajustes por ventas:** revisar el log de disco
(`wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`) por `Inventory adjustment failed` /
llamadas a `POST /inventory-adjustments` alrededor de la fecha de ventas.

**C3 — Confirmar que hay ajustes en Alegra para los ítems vendidos:**

```bash
export ALEGRA_EMAIL="…"; export ALEGRA_TOKEN="…"; export ITEM_ID="…"
curl -s -u "$ALEGRA_EMAIL:$ALEGRA_TOKEN" \
  "https://api.alegra.com/api/v1/inventory-adjustments?item_id=$ITEM_ID&limit=30&order_field=date&order_direction=DESC"
```

**C4 — Medir el doble descuento (el check decisivo):** para un producto con ventas facturadas,

```bash
curl -s -u "$ALEGRA_EMAIL:$ALEGRA_TOKEN" "https://api.alegra.com/api/v1/items/$ITEM_ID" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["inventory"]["availableQuantity"])'
wp post meta get <PRODUCT_ID> _stock --allow-root
wp post meta get <PRODUCT_ID> _alegra_stock_synced --allow-root
wp post meta get <PRODUCT_ID> _alegra_stock_push_pending --allow-root
```

**Interpretación:** si `availableQuantity` en Alegra ≈ `stock_inicial − Σ(cantidad facturada) − Σ(cantidad ajustada)`,
hay doble descuento. Comparar además `_alegra_stock_synced`: si difiere de `availableQuantity` de Alegra,
el ledger quedó desincronizado por el doble descuento.

**C5 — G1 (crítico, sin resolver):** crear una factura **draft** con un ítem de stock conocido y releer
`inventory.availableQuantity`; luego abrirla (`PUT status=open`) y releer. Confirma si el draft mueve
stock (Rama B) o no (Rama A). El guion está en `docs/sdd/sync-reliability/phase0-results.md:32-54`.

**C6 — S5 (restauración doble):** en una cuenta de prueba, cancelar un pedido facturado y comparar
`availableQuantity` antes/después de la cancelación y de la nota de crédito.

---

## 7. Defectos secundarios encontrados (fuera del titular, relevantes para el fix)

| # | Defecto | Evidencia | Impacto |
|---|---|---|---|
| D1 | La idempotencia de ajustes no usa `reference` (el diseño lo exigía) y matchea por `item+type+quantity` en los últimos 30 | `Inventory_Pusher.php:303-335`; `design.md:714` | Un ajuste legítimo puede marcarse `already_applied` y **no emitirse** (fuga de stock) |
| D2 | El poll no respeta `owner=invoice`: el diseño pedía no pisar WC hasta abrir la factura | `design.md:875-878`; `Products.php:1332-1379` (sin chequeo de owner) | En modo invoice, el poll puede escribir Alegra→WC antes de que la factura mueva stock |
| D3 | `get_unjournaled_sales()` cuenta pedidos `processing/completed` **sin** `_alegra_invoice_id`, pero no los que tienen factura `draft` | `Admin_Dashboard.php:862-887` | El informe no distingue "sin factura" de "factura borrador nunca abierta" |
| D4 | La advertencia manual (F4) no existe; el comerciante abre la factura sin señal alguna | `Admin_Dashboard.php:2845-2895`, `:2902-3028` | El doble descuento es **silencioso** |
| D5 | G9 nunca se ejecutó y su Rama A es refutada por el core de WC | `phase0-results.md` (G1–G8, sin G9); `wc-stock-functions.php:75,207,238` | El diseño de la guarda fina se apoyó en una premisa falsa |

---

## 8. Índice de evidencia (file:line)

**Plugin**

- `alegra-connector.php:418,445,449,451,460,462` — defaults de configuración.
- `includes/Sync/Inventory_Pusher.php:85-91` — `owner()`.
- `includes/Sync/Inventory_Pusher.php:97-102` — registro de hooks.
- `includes/Sync/Inventory_Pusher.php:114-125` — `on_stock_changed` (Guard 1 en el hook).
- `includes/Sync/Inventory_Pusher.php:157-277` — `push_delta` (Guards 1–5, ledger, lock, POST).
- `includes/Sync/Inventory_Pusher.php:303-335` — `adjustment_already_exists` (sin `reference`).
- `includes/Sync/Inventory_Pusher.php:340-358` — `build_adjustment_payload` (sin `reference`).
- `includes/Sync/Orders.php:63-193` — `create_invoice` (FIX-3 open-draft, override de dueño).
- `includes/Sync/Orders.php:437-449` — `find_open_invoice_for_order`.
- `includes/Sync/Orders.php:494-540` — `create_invoice_with_payment` (pasa `'open'`).
- `includes/Sync/Orders.php:850-913` — `ensure_invoice_open` (draft→open best-effort).
- `includes/Sync/Products.php:1142-1453` — poll/reconciliador; `:1327-1379` ledger + writer.
- `includes/Sync/Products.php:1701,1823` — `set_syncing` del import.
- `includes/Sync/Products.php:2185` — transient anti-cascada del import.
- `includes/Sync/Controller.php:321-394` — `sync_entity` (order → `create_invoice_with_payment`).
- `admin/Admin/Admin_Dashboard.php:862-887,903-905` — informe "Ventas sin factura".
- `admin/Admin/Admin_Dashboard.php:2840-2895` — `ajax_open_invoice` (sin guarda).
- `admin/Admin/Admin_Dashboard.php:2897-3028` — `ajax_record_payment` (sin guarda).
- `admin/Admin/Admin_Dashboard.php:3194,3325-3326,3942` — rutas manuales de facturación.
- `public/Public/Public_.php:49-59` — auto-facturación (atada a `push_orders_enabled`).
- `public/Public/Public_.php:80-82` — registro de hooks del pusher.
- `includes/Write_Gate.php:43,58,79` — entidad `inventory` del gate.

**Diseño (lo especificado y no implementado)**

- `docs/sdd/sync-reliability/design.md:58,594-604,632,669-678,1455-1459,1530-1531` — F1–F5.
- `docs/sdd/sync-reliability/design.md:1610` — G9.
- `docs/sdd/sync-reliability/phase0-results.md:10-19` — G1–G8; **G9 ausente**.

**WooCommerce core** (`/tmp/opencode/wc-release/woocommerce`)

- `includes/wc-stock-functions.php:75` — `do_action('woocommerce_product_set_stock')`.
- `includes/wc-stock-functions.php:170-239` — `wc_reduce_stock_levels` (producto antes que pedido).
- `includes/wc-stock-functions.php:351-412` — `wc_increase_stock_levels` (mismo orden).
- `includes/wc-stock-functions.php:178,360` — filtros `can_reduce`/`can_restore` (contexto previo, HYPOTHESIS).

---

## 9. Resumen ejecutivo

1. **El bug es real y está sin guarda.** Con los defaults, cada venta emite un ajuste; la factura
   manual posterior vuelve a descontar. Las 4 guardas del diseño (F1–F4) **no existen** y G9 **nunca
   se ejecutó**.
2. **La guarda diseñada no cubre el escenario reportado.** Guard 6 suprime el ajuste sólo si la factura
   ya está `open`; el comerciante factura **después**. El eslabón que sí lo cubriría (F4) sólo advierte
   y depende de F1.
3. **F1 (Stock_Order_Context) no es implementable como se diseñó:** WC dispara
   `woocommerce_product_set_stock` **dentro** de `wc_update_product_stock()` y recién después
   `woocommerce_reduce_order_stock`. G9 = Rama B. Hay una fuente alternativa (filtros `can_*`) a validar.
4. **Recomendación:** desacoplar el dueño del stock (`alegra_connector_stock_owner`, Opción E) y
   ponerlo en `invoice`; la factura mueve el stock una sola vez. Alternativa cero-código: activar
   `push_orders_enabled`. Sumar la advertencia manual gruesa como red de seguridad.
5. **Reparación:** no hay herramienta; hay que detectar por logs + `GET /inventory-adjustments`,
   cuantificar el exceso y emitir ajustes compensatorios `in`, luego re-baselinar el ledger.
