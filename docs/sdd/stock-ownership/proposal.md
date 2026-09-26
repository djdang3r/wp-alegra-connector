# Propuesta — Propiedad única del stock, poll sin re-inflación, cola de facturas y reconciliación (`stock-ownership`)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único de stock configurable + poll honesto + cola de facturas fallidas + reconciliación WC↔Alegra) |
| Tipo | Corrección de bugs críticos en producción (doble descuento + re-inflación) + recuperación de facturas + honestidad de configuración |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Estado | Propuesta — el **doble descuento** y la **re-inflación** son el titular; quedan verificaciones en vivo (Fase 0) |
| Regla | Todo claim de código cita `archivo:línea`; lo no verificado se marca **SIN VERIFICAR / BLOQUEADO** |

> **Correcciones de cita a los tres análisis de entrada.** Tras verificar cada `file:line` con
> lectura directa, las correcciones son:
>
> - **`ANALYSIS-all-invoiced-model.md:117` (Clave 1) — REFUTADO.** El análisis afirma que con
>   `owner=invoice` el `reason='invoice_owner'` **está** en la lista `$handled` del poll y por eso
>   hace `continue` (no pisa WC). **Es falso en 2.6.0.** La lista real
>   (`Products.php:1348-1349`) es `['ok','already_applied','in_sync','api_error','blocked',
>   'locked','baseline_unverified']`; `invoice_owner` **no está**. El comentario `:1344-1347`
>   documenta que es **intencional** (FIX-8): si el pusher no es el dueño, el poll **cae al writer**.
>   Consecuencia: en modo `invoice` el poll **sí escribe WC** con el valor de Alegra. El
>   "congelamiento/starvation" de `§2.4` **no es el síntoma observado**; el síntoma real es el
>   **opuesto** (re-inflación). La guarda protectora que el diseño **sí** especificó
>   (`design.md:873-878`: `owner==='invoice' && $a !== $w ⇒ no pisar WC`) **nunca se implementó**.
> - **`ANALYSIS-all-invoiced-model.md:139` (Escenario B, paso 3) — REFUTADO.** Dice "No re-infla WC
>   (bien)". Con el fall-through real, el poll **sí** pisa WC con el valor de Alegra (que sigue
>   `10` si la factura falló) ⇒ **re-inflación**. Misma causa raíz que el punto anterior.
> - **`ANALYSIS-all-invoiced-model.md:145` (Escenario C) — CONFIRMADO.** Con `_alegra_stock_synced`
>   vacío, `needs_reconcile=false` (`Products.php:1332-1334`) y el poll escribe Alegra→WC sin
>   baseline. La venta se pierde. **Éste es el hueco válido** y es el que la propuesta cierra.
> - **`docs/sdd/sync-reliability/proposal.md` §6 — desactualizado.** Lista
>   `includes/Sync/Inventory_Writer.php` como "**Nuevo**". En 2.6.0 el archivo **existe** (170
>   líneas, `apply()` en `Inventory_Writer.php:39`). La propuesta de 2.5.1 quedó vieja en ese punto.
> - **`ANALYSIS-double-discount.md:117` — confirmado.** El diseño atribuía `set_syncing` a
>   `:1542`/`:1632`; en HEAD las líneas reales son `Products.php:1701`/`:1823`. **Verificado.**
> - **`ANALYSIS-failure-handling.md:104` — rango efectivamente correcto.** La nota de fallo y el
>   log reales están en `Public_.php:411-416`, dentro de `trigger_sync()` (`:373`). El rango
>   `:406-422` abarca el bloque.
> - **`ANALYSIS-failure-handling.md:82` — confirmado.** `create_invoice_with_payment()` llama a
>   `record_payment_for_invoice()` en `Orders.php:526` y **descarta** el retorno.
> - **`ANALYSIS-failure-handling.md:263` — confirmado.** El sweep de pagos se registra en
>   `Controller.php:72-74`; `run_payment_reconcile()` arranca en `:89`.
>
> El resto de las citas fue **verificado** y es exacto.

---

## 1. El problema, en lenguaje del dueño de tienda

> **"Vendo 3 y Alegra descuenta 6. Después el stock vuelve a subir solo. Termino vendiendo lo que no tengo."**

El modelo del comerciante es uno y sin ambigüedad: **todas las ventas del ecommerce se facturan en
Alegra**. Sobre ese modelo, hoy conviven **cuatro problemas verificables** que se refuerzan entre sí.

### FRONT 1 — El doble descuento (ajuste + factura)

Hay **dos** mecanismos que pueden mover el stock de Alegra: la **factura** y el
`POST /inventory-adjustments` del plugin (`Inventory_Pusher.php:249-251`). Con los defaults de
2.6.0 —`push_orders_enabled=false` (`alegra-connector.php:418`) ⇒ `owner()='adjustment'`
(`Inventory_Pusher.php:85-91`)— **cada venta emite un ajuste**. La secuencia que reporta el
comerciante:

| Paso | Acción | WC | Alegra |
|---|---|---|---|
| 1 | Venta de 3 | 10 → 7 | 10 |
| 2 | Hook `woocommerce_product_set_stock` → `push_delta` | 7 | 10 |
| 3 | `POST /inventory-adjustments {out 3}` | 7 | **7** |
| 4 | El comerciante "Factura" (manual) → factura **draft** | 7 | 7 |
| 5 | El comerciante "Abre la factura" (`ajax_open_invoice_impl`) | 7 | **4** |

Alegra descontó **6 por una venta de 3**. El paso 5 no tiene ninguna guarda:
`ajax_open_invoice_impl()` (`Admin_Dashboard.php:2845-2895`) **no** consulta `owner()`, ni
`_alegra_stock_adjusted`, ni advierte. Y con `invoice_status='open'` (`alegra-connector.php:462`
default `draft`, pero configurable) el descuento ocurre ya en el paso 4
(`Orders.php:494-540`, `:512` pasa `'open'`). **VERIFIED (rutas).** El descuento del paso 5 depende
de que el borrador no mueva stock (premisa **G1 sin resolver**, `HYPOTHESIS`), pero es la premisa
del propio diseño y el escenario que el comerciante vivió.

Las guardas que el diseño `sync-reliability` creó para este caso —`Stock_Order_Context`, Guard 6,
`_alegra_stock_adjusted`, la advertencia manual— **no existen en producción**: las cinco cadenas
aparecen sólo en `docs/`. **VERIFIED (grep: cero ocurrencias en `includes/`, `admin/`, `public/`).**

### FRONT 2 — El poll re-infla el stock vendido

El poll (`Products.php:1142-1453`) decide si escribir WC con el valor de Alegra comparando el
ledger `_alegra_stock_synced` (`:1329-1353`). Dos agujeros verificados:

1. **Baseline ausente.** El import nunca fija `_alegra_stock_synced` (los únicos `set_synced` están
   en `Products.php:1378` y `Inventory_Pusher.php:214,244,271`). Un producto importado y vendido
   **antes** de su primer poll tiene `synced=''` ⇒ `needs_reconcile=false` ⇒ el poll escribe el
   valor de Alegra y **re-infla la venta**. **VERIFIED.**
2. **Sin conciencia de dueño.** En modo `invoice`, `push_delta` retorna `invoice_owner`
   (`Inventory_Pusher.php:170-173`) **antes** de la lógica de baseline (`:197-216`), y ese reason
   **no** está en `$handled` ⇒ el poll **cae al writer** y pisa WC con el valor de Alegra aunque la
   factura todavía no haya movido stock (o haya fallado). **VERIFIED.** Éste es el punto donde los
   tres análisis se contradicen (ver "Correcciones de cita"): el diseño quería no pisar WC
   (`design.md:873-878`), el código hace lo contrario.

**Impacto:** sobreventa / stock fantasma. WC cree que hay más mercancía de la que existe.

### FRONT 3 — Una factura que falla se pierde en silencio

Cuando la subida de una factura falla, **todo camino pierde el fallo al terminar el request**:

- **Automático** (`Public_::trigger_sync`, `:373`): sólo una nota de pedido + el log
  (`:411-416`). Sin meta persistente, sin reintento programado, sin aviso al admin.
- **Manual single** (`ajax_sync_single`, `Admin_Dashboard.php:3166`): un toast efímero.
- **Bulk** (`ajax_bulk_sync`, `:3305`; `ajax_sync_pending_*`, `:3867`): sólo un **conteo** de
  errores; el estado vive en el transient `alegra_pending_invoice_batch` y se **borra** al terminar
  (`:3964`). Sin motivo por pedido.
- **Notificaciones:** cero `wp_mail` en producción (**VERIFIED**); no hay meta
  `_alegra_invoice_sync_state` ni equivalente (**VERIFIED**, cero ocurrencias). No hay lista ni
  pantalla que muestre las facturas que no subieron.

El comerciante lo pidió textual: *"debería quedar como notificación en el listado de pedidos o
facturas por subir y ya luego de que se resuelva el problema subir la factura o pedido
manualmente."* **Hoy eso no existe.**

### FRONT 4 — Sin reconciliación WC↔Alegra

No hay ninguna herramienta que compare el stock de WC con el de Alegra ni que repare la
divergencia. El único reporte cercano —"Ventas sin factura", `get_unjournaled_sales()`
(`Admin_Dashboard.php:862-887`)— **se oculta exactamente en el modo del comerciante**: la fila sólo
se muestra cuando `push_orders_enabled=false` (`:903-905`), y sólo detecta `_alegra_invoice_id`
`NOT EXISTS`, no las facturas `draft`/`void` ni el meta vacío. **VERIFIED.**

### Impacto transversal

- **Funcional (crítico):** inventario corrupto (descuentos dobles) y sobreventa (re-inflación).
- **Fiscal/contable:** una venta sin factura `open` rompe la promesa "todas las ventas facturadas".
- **De confianza:** el comerciante no puede ver qué falló ni recuperarlo desde el admin.
- **Distribuido:** pega **más** en tiendas reales (ventas concurrentes, catálogo grande, hosts sin
  cron real), que es el público objetivo del plugin.

---

## 2. Decisiones y restricciones del comerciante (verbatim — se honran tal cual)

1. *"La idea es que todas las ventas del ecommerce sean facturadas en alegra."*
2. *"Necesitamos un plugin totalmente profesional pero tambien debe ser configurable porq puede que
   cada cliente o usuario del plugin tenga necesidades diferentes."*
3. *"Necesitamos soluciones totalmente profesionales, con las mejoras practicas, y un plugin
   configurable, ademas todo debe respetar la escalabilidad."*
4. *"Nuestro plugin es profesional y el dashboard debe seguir con la buena usabilidad, nada de
   ambiguedades y que el usuario no se complique, debe ser facil de usar."*
5. *"Nada de chapuzadas y de codigo como panitos de agua tibia."*
6. *"Todo debe quedar funcional y todo el proceso debe funcionar correctamente sin problemas,
   errores, bugs o conflictos."*
7. *"La idea es que funcione en cualquiera"* — plugin **distribuido**, sin supuestos de host.
8. **El poll NO se apaga.** El comerciante lo aclaró: el poll es necesario para la dirección
   Alegra→WC (ventas de POS, ediciones manuales en Alegra). **Se arregla, no se desactiva.**
9. **No DIAN.** Fuera de alcance. El ZIP de release **excluye `scripts/`** (`.distignore:15`).

---

## 3. Alcance

### 3.1 Dentro del alcance

**1) Propiedad única del stock (el fix del doble descuento)**

- Nueva opción `alegra_connector_stock_owner`: **`invoice` | `adjustment` | `auto`** (default
  `auto`).
  - `invoice`: la **factura de Alegra es el único** motor de stock de las ventas. El plugin **no**
    emite ajustes para cambios originados en un pedido.
  - `adjustment`: el plugin empuja los deltas; la factura se crea **`draft`** y **nunca** se
    auto-abre (no mueve stock).
  - `auto`: `invoice` **sólo** si `push_orders_enabled && open_invoice_on_paid` (la **condición
    doble** exacta de 2.6.0, `Inventory_Pusher.php:87-90`); si no, `adjustment` (comportamiento
    actual ⇒ compatibilidad total). La fórmula simplificada `push_orders_enabled` solo reintroduce B3.
- `owner()` (`Inventory_Pusher.php:85-91`) pasa a leer la opción; Guard 2 (`:170-173`) ya la respeta.
- **Cerrar el agujero "adjustment + abrir factura a mano":** en modo `adjustment`, la apertura
  manual (`ajax_open_invoice_impl`, `Admin_Dashboard.php:2845-2895`) **advierte y exige
  confirmación** (advertencia gruesa, sin `Stock_Order_Context`); en modo `invoice`, la apertura
  manual es la acción esperada.
- **Control en el dashboard** con la nota clara: *"Elegí UNO solo. Si elegís los dos, el stock se
  descuenta dos veces."* Sin jerga, sin ambigüedad.
- **Sanear al cambiar de dueño:** limpiar `_alegra_stock_push_pending` para no arrastrar un push
  fallido de un modo al otro.

**2) El poll: arreglar sus tres huecos (NO apagarlo)**

- **Baseline en el import.** Al importar/crear un producto, fijar `_alegra_stock_synced` con el
  `availableQuantity` de Alegra (hoy W1 usa `Inventory_Writer` en `Products.php:2276` y **no**
  setea el ledger). Cierra el Escenario C.
- **Actualizar el baseline cuando la factura mueve stock.** Al abrir/crear la factura que mueve
  stock (`ensure_invoice_open`, `Orders.php:850-913`; `create_invoice`, `:63-193`), actualizar
  `_alegra_stock_synced` (o registrar el delta) para que el ledger refleje el movimiento. Cierra
  la "starvation" **de la guarda del diseño** y hace consistente el modo `invoice`.
- **Poll con conciencia de dueño.** Implementar la guarda que el diseño especificó
  (`design.md:873-878`): si `owner()==='invoice'` y `Alegra != WC`, el poll **no pisa WC** (reporta
  divergencia) hasta que la factura haya movido stock. El poll **nunca** debe sobrescribir un valor
  de WC con un cambio local pendiente. Esto se combina con las tres reglas del ledger ya
  existentes (`needs_reconcile`, `pending`, `synced`).
- **Referencia en la idempotencia de ajustes.** Agregar `reference` al payload
  (`build_adjustment_payload`, `:340-358`) y al pre-chequeo (`adjustment_already_exists`, `:303-335`)
  para no confundir un `out 1` viejo con uno nuevo (fuga de stock).
- **Mantener el poll corriendo:** el presupuesto, el cursor y el `truncated` de 2.6.0
  (`alegra-connector.php:455-456`) quedan; no se toca la dirección Alegra→WC.

**3) Cola de facturas fallidas (notificación + lista + reintento)**

- **Ledger por pedido** (meta CRUD/HPOS): `_alegra_invoice_sync_state`
  (`idle|pending|failed_retriable|failed_permanent|blocked|resolved`), `_alegra_invoice_error_code`,
  `_alegra_invoice_error_message`, `_alegra_invoice_error_retriable`, `_alegra_invoice_attempts`,
  `_alegra_invoice_last_attempt`, `_alegra_invoice_next_retry`.
- **Clasificador puro** de fallos (retriable vs permanente) en un solo lugar, compartido por el cron
  y la UI.
- **Pantalla "Facturas por subir"** (submenu nuevo, patrón `add_admin_menu`,
  `Admin_Dashboard.php:106-234`): lista fallidas (retriable + permanente), bloqueadas y
  nunca-intentadas; columnas orden/fecha/cliente/total/motivo/código/intentos/último/próximo;
  filtros.
- **Reintento single + bulk.** Single: AJAX nuevo bajo `Write_Gate::run_explicit()` (patrón
  `ajax_sync_single`, `:3166`). Bulk: extender el flujo chunked existente
  (`ajax_sync_pending_*`, `:3867-3979`) con `scope=failed`; 10 por request.
- **Cron horario de reintento** con backoff exponencial y tope de intentos, sólo para
  `failed_retriable`; permanente/bloqueado son **sólo manuales** (una falta de stock no puede
  loopear para siempre). Opt-in: `alegra_connector_invoice_retry_enabled` (default **false**).
- **Notificación:** aviso dismissible en el admin (patrón option-backed de
  `alegra_connector_logger_write_failed`, `Logger.php:198-241`) + **badge** en el submenu.
- **Idempotencia:** reusar las guardas existentes (`_alegra_invoice_id` `Orders.php:82-107`,
  `find_open_invoice_for_order` `:437-449`, pre-búsqueda AC-14 `:309-340`, lock por pedido
  `:66-79`) y agregar la re-búsqueda post-error antes de persistir un fallo retriable.

**4) Reconciliación WC↔Alegra**

- **Informe de divergencia:** por producto vinculado, comparar stock WC vs `availableQuantity` de
  Alegra y listar los que difieren, con la **causa** (factura fallida / baseline ausente /
  reembolso).
- **Des-invertir el gate** de "Ventas sin factura": correr **siempre** y ampliar la semántica a
  `_alegra_invoice_id` ausente/vacío o con estado `draft`/`void` (`get_unjournaled_sales`,
  `:862-887`; gate `:903-905`).
- **Reparación explícita** (no automática): para cada producto divergente, emitir la factura
  faltante (si hay pedido) **o** un ajuste correctivo por el delta, **nunca ambos para el mismo
  movimiento** (REQ-INV-08). Deja rastro en log + nota.

### 3.2 Fuera del alcance (explícito)

- **Apagar el poll.** **No** se apaga ni se desactiva la dirección Alegra→WC. El comerciante lo
  pidió explícitamente.
- **DIAN / facturación electrónica:** nada de `stamp`, `paymentForm` ni esquema fiscal.
- **`Stock_Order_Context` / Guard 6 fina:** el diseño original se apoyó en un orden de hooks
  refutado por el core de WC (G9 = Rama B; ver `ANALYSIS-double-discount.md` §3). **No** se
  implementa el contexto request-scoped frágil. La seguridad la da el **dueño único configurable**
  + la advertencia gruesa.
- **Revertir el ajuste al abrir la factura (Opción C):** falla cuando la factura se abre fuera de
  WC (no hay hook). Descartada.
- **Dejar la factura siempre en `draft` (Opción D):** rompe el requisito de facturar. Descartada.
- **Rediseño de los flujos de factura/cliente/pago:** no se reescriben.
- **Multisite** más allá de lo que ya soporta el plugin.
- **Carpetas de test / harness `scripts/`:** no se agregan ni se distribuyen.

### 3.3 Restricción transversal

El plugin es **distribuido**: todo debe funcionar para **cualquier** tamaño de catálogo y cualquier
host (con o sin `set_time_limit`, con WP-Cron o cron real, con o sin Action Scheduler). El dueño
único no puede depender del orden de hooks de WC (refutado). El poll no puede depender de que la
factura se abra dentro del plugin. La cola de reintentos no puede depender de cron real: degrada a
"reintento manual disponible siempre". **Sin regresión** para instalaciones existentes; todo cambio
de comportamiento se declara como **intencional** con nota de release.

---

## 4. Los hallazgos, agrupados por tema

Leyenda: **[BUG]** = comportamiento incorrecto / promesa incumplida · **[FEATURE]** = falta control
o visibilidad · **[HYG]** = limpieza · **[REFUTADO]** = un análisis afirma algo falso.

### Tema A — Propiedad del stock (FRONT 1)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| A1 | `owner()` está atado a `push_orders_enabled`; el comerciante que factura **manual** pero factura **todo** no puede elegir `invoice` sin activar la auto-facturación. | `Inventory_Pusher.php:85-91`; `alegra-connector.php:418` | **[BUG]** crítico |
| A2 | No existe una opción explícita de dueño; el modo es implícito y frágil. | grep `stock_owner` = 0 en producción | **[FEATURE]** |
| A3 | `ajax_open_invoice_impl()` abre la factura **sin ninguna guarda** ni advertencia aunque el ajuste ya haya movido stock. | `Admin_Dashboard.php:2845-2895` | **[BUG]** crítico (doble descuento) |
| A4 | `ajax_record_payment_impl()` idem: registra pago/abre factura sin mirar el dueño. | `Admin_Dashboard.php:2897-3028` | **[BUG]** |
| A5 | Las guardas del diseño (`Stock_Order_Context`, Guard 6, `_alegra_stock_adjusted`, advertencia) **no existen**. | grep: 0 en `includes/`, `admin/`, `public/`; diseño `design.md:594-604,669-678` | **[BUG]** de diseño |
| A6 | G9 (orden de hooks) nunca se ejecutó y su Rama A es refutada por el core de WC: `woocommerce_product_set_stock` corre **dentro** de `wc_update_product_stock()` y `woocommerce_reduce_order_stock` recién después. | `ANALYSIS-double-discount.md` §3; `wc-stock-functions.php:75,207,238` | **[REFUTADO]** |
| A7 | La apertura manual en modo `adjustment` no ofrece confirmación: el doble descuento es **silencioso**. | `Admin_Dashboard.php:2845-2895`; diseño `design.md:601-604` | **[BUG]** UX |

### Tema B — Poll / ledger (FRONT 2)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| B1 | El import **no** fija `_alegra_stock_synced`; un producto importado y vendido antes del primer poll re-infla. | `set_synced` sólo en `Products.php:1378`, `Inventory_Pusher.php:214,244,271`; import `Products.php:2276` | **[BUG]** crítico |
| B2 | El poll **no** respeta `owner=invoice`: `invoice_owner` **no** está en `$handled` ⇒ cae al writer y pisa WC con el valor de Alegra. La guarda `design.md:873-878` no se implementó. | `Products.php:1348-1352`; `Inventory_Pusher.php:170-173` | **[BUG]** crítico (re-inflación) |
| B3 | Con baseline ausente y sin delta local, `needs_reconcile=false` ⇒ el poll escribe Alegra→WC sin reconciliar (Escenario C). | `Products.php:1332-1334`; `ANALYSIS-all-invoiced-model.md` §2.3-C | **[BUG]** crítico |
| B4 | Abrir/crear la factura **no** actualiza `_alegra_stock_synced` ⇒ el ledger queda desfasado del movimiento real. | `Orders.php:850-913`, `:63-193` (sin `set_synced`) | **[BUG]** |
| B5 | La idempotencia de ajustes **no usa `reference`** y matchea por `item+type+quantity` en los últimos 30 ⇒ un ajuste legítimo puede marcarse `already_applied` y no emitirse (fuga de stock). | `Inventory_Pusher.php:303-335`, `:340-358`; diseño `design.md:714` | **[BUG]** |
| B6 | `push_delta` corta por `invoice_owner` **antes** de la lógica de baseline (`:197-216`), así que el hook nunca setea `pending` en modo `invoice`. | `Inventory_Pusher.php:170-173` vs `:197-216` | **[BUG]** |
| B7 | `ANALYSIS-all-invoiced-model.md` describe mal el mecanismo (Clave 1) y por eso diagnostica "starvation" donde el síntoma real es re-inflación. | `ANALYSIS-all-invoiced-model.md:117,139` vs `Products.php:1348-1349` | **[REFUTADO]** |

### Tema C — Fallos de factura (FRONT 3)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| C1 | No hay estado persistente de fallo: "pendiente" se infiere de la **ausencia** de `_alegra_invoice_id`. | `Admin_Dashboard.php:870`, `:3886`; grep `_alegra_invoice_sync_state` = 0 | **[BUG]** crítico |
| C2 | El fallo automático sólo deja nota de pedido + log; no hay aviso ni meta. | `Public_.php:411-416` | **[BUG]** |
| C3 | El bulk descarta el motivo por pedido y borra el transient al terminar. | `Admin_Dashboard.php:3867-3979`, `:3964`, `:3977` | **[BUG]** UX |
| C4 | No existe lista/pantalla de facturas fallidas ni badge. | `Admin_Dashboard.php:106-234` (sin submenu) | **[FEATURE]** |
| C5 | No hay reintento programado de facturas; el único sweep outbound es el de pagos. | `Controller.php:298-305` (sólo poll inbound); `:69-104` (pagos) | **[BUG]** |
| C6 | Cero `wp_mail`; la "notificación" pedida no existe en ninguna forma. | grep `wp_mail` en producción = 0 | **[BUG]** |
| C7 | La clasificación retriable/permanente no existe: todo fallo es indistinguible. | `Client.php:177-255` (retry HTTP); sin clasificador de negocio | **[FEATURE]** |
| C8 | Duplicado intra-request: el cliente reintenta el POST sin idempotency key; la pre-búsqueda AC-14 corre una sola vez. | `Client.php:177-255`; `Orders.php:133-153` | **[BUG]** latente |

### Tema D — Reconciliación (FRONT 4)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| D1 | No hay informe de divergencia WC↔Alegra. | grep = 0; base REQ-INV-07 | **[FEATURE]** |
| D2 | El reporte "Ventas sin factura" **se oculta** justo en modo `push_orders=true`. | `Admin_Dashboard.php:903-905` | **[BUG]** UX |
| D3 | No detecta facturas `draft`/`void` ni meta vacío; sólo `NOT EXISTS`. | `Admin_Dashboard.php:862-887` | **[BUG]** |
| D4 | No hay reparación de divergencia existente (ni factura faltante ni ajuste correctivo explícito). | grep = 0 | **[FEATURE]** |
| D5 | Los `owner()` previos no dejan rastro del ajuste asociado al pedido (no se puede auditar qué movió qué). | `Inventory_Pusher.php:249-267` (sin `reference`) | **[BUG]** |

---

## 5. Enfoque (alto nivel — el detalle va en `design`)

### 5.1 La decisión configurable del dueño (el corazón)

**Se adopta la Opción E del análisis de doble descuento:** desacoplar el dueño del stock de la
auto-facturación mediante una opción explícita `alegra_connector_stock_owner`
(`auto|invoice|adjustment`, default `auto`).

- **Por qué no la Opción A** (detectar "order-driven" por hooks): depende de un orden de hooks que
  **no existe** en WC (G9 refutado) y cambia el default de `adjustment`.
- **Por qué no la Opción B** (implementar las guardas F1–F5): Guard 6 sólo cubre el orden
  factura-primero; el comerciante factura **después**. Su eslabón crítico (`Stock_Order_Context`)
  no es implementable como se diseñó.
- **Por qué no C/D:** C falla si la factura se abre fuera de WC; D rompe el requisito de facturar.
- **`auto` por default ⇒ compatibilidad total:** una instalación 2.6.0 que no toque nada se comporta
  exactamente como hoy.
- **Distribuido:** una opción + un `if` en `owner()`; cero dependencias nuevas. Cada cliente elige su
  modelo, que es literalmente lo que pidió el comerciante ("configurable… cada cliente tiene
  necesidades diferentes").

**Advertencia gruesa como red de seguridad.** En modo `adjustment`, al abrir/registrar pago de una
factura se advierte siempre: *"Los ajustes de inventario están activos; si ya se empujó un ajuste
por este pedido, abrir la factura descontará dos veces."* No requiere contexto de pedido y cubre a
quien se quede en `adjustment`.

### 5.2 El poll: se arregla, no se apaga (corrección explícita)

**Decisión del comerciante: el poll se mantiene.** La dirección Alegra→WC (POS, ediciones manuales)
es un requisito, no un lujo. Por eso **no** se propone `inventory_source=woocommerce` como "fix".
En cambio se cierran sus tres huecos:

1. **Baseline al importar** (B1) — sin esto, cualquier venta pre-poll re-infla.
2. **Baseline al mover stock la factura** (B4) — sin esto, el ledger no refleja el movimiento real y
   la guarda de dueño queda ciega.
3. **Poll con conciencia de dueño** (B2) — implementar la guarda `design.md:873-878`: en modo
   `invoice`, si `Alegra != WC`, **no pisar WC** (reportar divergencia) hasta que la factura haya
   movido stock. El poll **nunca** sobrescribe un cambio local pendiente.

La combinación cierra el ciclo sin apagar nada: la factura mueve el stock y actualiza el baseline; el
poll baja cambios de Alegra sólo cuando **no** hay un cambio local pendiente. Se preservan el
presupuesto, el cursor y `truncated` de 2.6.0.

### 5.3 La cola de fallos

Un **ledger por pedido** es la capa durable. El clasificador puro decide retriable vs permanente en
**un solo lugar**. La pantalla "Facturas por subir" reusa el patrón de submenu y el flujo chunked de
10/request ya existentes. El cron horario reintenta **sólo** `failed_retriable` con backoff y tope
(opt-in, default off por ser dinero). La notificación usa el patrón option-backed ya probado
(`Logger.php:198-241`) para sobrevivir a fallos escritos por cron sin request de usuario.

### 5.4 La reconciliación

Informe de divergencia con causa + des-inversión del gate del reporte + reparación **explícita**
(nunca ambos mecanismos para el mismo movimiento). El informe es la superficie que hace visible lo
que hoy es silencioso.

### 5.5 Principios transversales

- **Un solo dueño por movimiento** (REQ-INV-08). El dueño es una **decisión de tienda**, no un
  accidente de hooks.
- **Fail-loud.** Todo fallo deja señal visible: ledger + pantalla + badge + aviso. Cero silencios.
- **Idempotencia antes de reintentar.** Reusar `_alegra_invoice_id`, `find_open_invoice_for_order`,
  la pre-búsqueda AC-14 y el lock por pedido. Un reintento nunca duplica una factura.
- **HPOS-safe.** Todo meta vía CRUD (`update_meta_data()`/`save()`), nunca SQL directo.

---

## 6. Impacto

### Archivos/áreas afectadas

| Área | Impacto | Descripción |
|---|---|---|
| `alegra-connector.php` | Modificado | Defaults/registro de `stock_owner` y opciones de reintento; schedule del cron de reintento |
| `includes/Sync/Inventory_Pusher.php` | Modificado | `owner()` lee la opción; `reference` en payload + idempotencia; limpieza de `pending` al cambiar de dueño |
| `includes/Sync/Products.php` | Modificado | Baseline en el import; guarda de dueño en el writer del poll |
| `includes/Sync/Orders.php` | Modificado | Actualizar `_alegra_stock_synced` al abrir/crear la factura que mueve stock; persistencia del ledger de fallo; clasificador; re-búsqueda post-error |
| `includes/Sync/Inventory_Writer.php` | Reusado | Único escritor (ya existe, `:39`); sin cambio de contrato |
| `includes/Sync/Controller.php` | Modificado | Cron de reintento de facturas + lock global (patrón `run_payment_reconcile`) |
| `admin/Admin/Admin_Dashboard.php` | Modificado | Submenu "Facturas por subir"; AJAX retry single; `scope=failed` en el bulk; aviso+badge; advertencia en `ajax_open_invoice`/`ajax_record_payment`; des-invertir el gate del reporte |
| `admin/assets/js/admin.js` | Modificado | Confirmación del reintento bulk y de la apertura manual en modo `adjustment` |
| `templates/admin-settings.php` | Modificado | Selector de dueño del stock + nota anti-ambigüedad |
| `templates/admin-invoice-queue.php` | **Nuevo** | Pantalla "Facturas por subir" |
| `templates/admin-dashboard.php` | Modificado | Fila de divergencia siempre visible + link a la cola |
| `includes/State_Sync.php` | Reusado | Render del aviso (exponer la cola) |
| `logger/Logger/Logger.php` | Reusado | Patrón option-backed del aviso |
| `languages/*`, `*/index.php`, `scripts/*` | **NO TOCAR / NO DISTRIBUIR** | Restricción explícita |

### Riesgo

| Riesgo | Prob. | Mitigación |
|---|---|---|
| Cambiar de dueño deja `_alegra_stock_synced` desactualizado | Media | El poll re-baselina; limpiar `_alegra_stock_push_pending` al cambiar de modo; documentar |
| El poll con guarda de dueño deja de bajar cambios de Alegra (starvation) | Media | Actualizar el baseline **cuando la factura mueve stock**; el poll baja cuando no hay cambio local pendiente |
| El reintento automático crea facturas no deseadas | Media | `invoice_retry_enabled` default **false** (opt-in); sólo retriable; tope de intentos |
| Reintento duplica una factura tras timeout/5xx | Baja | `_alegra_invoice_id` + `find_open_invoice_for_order` + AC-14 + lock + re-búsqueda post-error |
| La advertencia manual molesta en modo `invoice` | Baja | Sólo se muestra en modo `adjustment` |
| Reparación de divergencia emite el mecanismo equivocado | Media | Reparación **explícita**; nunca ambos para el mismo movimiento; log + nota |
| Meta query costosa en tiendas grandes | Media | Badge con conteo cacheado; `limit` acotado |
| Sin cron real el reintento no corre | Media | El botón "Reintentar" siempre está disponible; el cron es una mejora, no un requisito |

### Compatibilidad hacia atrás (instalación 2.6.0 existente)

- **`stock_owner=auto` por default** ⇒ `owner()` devuelve exactamente lo mismo que hoy
  (`push_orders_enabled && open_invoice_on_paid`). Cero cambio de comportamiento sin acción del
  comerciante.
- **El poll sigue corriendo** con el mismo presupuesto/cursor; se le agrega la guarda de dueño, que
  sólo cambia el resultado en el caso que hoy está roto (re-inflación).
- **`_alegra_stock_push_pending`** puede quedar sucio de un push fallido previo; se limpia al cambiar
  de dueño. No hay migración de datos masiva.
- **La semántica del reporte "Ventas sin factura" cambia** (se muestra siempre y detecta
  `draft`/`void`): **cambio intencional**, documentado en `CHANGELOG.md`.
- Los contratos AJAX (`success`/`data`) se mantienen; se agrega `message` legible.

### Preocupación de plugin distribuido

Nada puede depender del orden de hooks de WC (refutado), de cron real, de Action Scheduler ni de
que la factura se abra dentro del plugin. El dueño único es una opción determinista y testeable. El
poll funciona aunque el host ignore `set_time_limit`. La cola de fallos degrada a "reintento manual
siempre disponible" cuando no hay cron. La UI no miente: si no puede comprobar, lo dice.

### Migración

- **Sin migración de datos.** `stock_owner` se siembra con `auto` (comportamiento actual).
- El ledger de fallo es aditivo: pedidos sin meta simplemente no aparecen en la cola.
- El baseline del import se aplica a partir del deploy; los productos ya importados se baselinan en
  su próximo poll (y la guarda de dueño evita la re-inflación mientras tanto).
- La reparación de divergencia existente es **bajo demanda**, nunca automática.

---

## 7. Criterios de éxito

**Invariante (el titular):**

> **Todo pedido pagado tiene exactamente una factura de Alegra, y cada venta mueve el stock
> exactamente una vez, por un solo mecanismo (la factura **o** el ajuste, nunca ambos).**

Observables desde el WP admin, sin mirar la base:

1. **Dueño único configurable.** Ajustes muestra "Dueño del stock: Automático / Factura / Ajuste"
   con la nota *"Elegí UNO solo; si elegís los dos, el stock se descuenta dos veces."*
2. **No hay doble descuento.** En modo `invoice`, una venta descuenta stock **una sola vez**; abrir
   la factura manualmente **no** vuelve a descontar.
3. **No hay doble descuento silencioso.** En modo `adjustment`, abrir/registrar pago advierte y pide
   confirmación; queda nota en el pedido.
4. **El poll no re-infla.** Tras una venta, un poll posterior **no** sube el stock de WC por encima
   del valor vendido — ni con baseline presente ni ausente.
5. **El poll no queda ciego.** Un cambio de stock hecho en Alegra (POS, edición manual) **sí** baja a
   WC cuando no hay un cambio local pendiente.
6. **Toda factura fallida queda listada.** La pantalla "Facturas por subir" muestra orden, motivo,
   código, intentos y último intento; el badge del submenu coincide con el conteo.
7. **Se puede recuperar desde el admin.** "Reintentar" (single y bulk) sube la factura tras resolver
   el problema y **nunca** crea una segunda factura.
8. **Reintento automático acotado.** Con el cron activo, sólo se reintentan fallos retriables con
   backoff y tope; los permanentes (falta de stock, datos) quedan para reintento manual.
9. **Notificación visible.** Un fallo produce un aviso dismissible en el admin con enlace a la lista;
   desaparece cuando el conteo llega a cero.
10. **La divergencia es visible y reparable.** El informe WC↔Alegra lista los productos que difieren
    con su causa; la reparación es explícita y deja rastro.
11. **Sin regresión.** El default `auto` conserva el comportamiento de 2.6.0; el poll sigue
    corriendo; la facturación, clientes y pagos no cambian; DIAN intacto.
12. **Distribuido.** Funciona sin cron real (reintento manual), en catálogos chicos y grandes, y en
    hosts que ignoran `set_time_limit`.

---

## 8. Preguntas abiertas (forks reales)

1. **¿El default de `stock_owner` debe ser `auto` o `invoice`?** `auto` garantiza compatibilidad
   total con 2.6.0; `invoice` alinea el default con el modelo "todo facturado" del comerciante, pero
   cambia comportamiento. Recomendación: **`auto`** en el código, y el asistente/wizard sugiere
   `invoice` cuando `push_orders_enabled=true`. Decisión del comerciante.
2. **¿La apertura manual en modo `adjustment` advierte o directamente bloquea?** Advertir respeta la
   autonomía; bloquear evita el error. Recomendación: **advertir con confirmación explícita**.
3. **¿El reintento automático arranca encendido o apagado?** Crear documentos fiscales sin
   supervisión es sensible. Recomendación: **apagado (opt-in)**.
4. **¿La cola debe incluir el estado `payment_missing`** (factura ok, pago no registrado)? Hoy lo
   cubre el sweep de pagos, pero no se ve en la lista. Recomendación: **sí**, como estado distinto.
5. **¿La reparación de divergencia puede emitir una factura faltante automáticamente** si el pedido
   pagado existe, o siempre requiere acción manual? Recomendación: **manual con confirmación**.
6. **¿La guarda de dueño del poll debe aplicar también con `inventory_source=woocommerce`?** En ese
   modo el poll ya no toca stock (`Products.php:1170-1174`), así que quedaría inerte; confirmar.
7. **Forma del error de stock de Alegra.** El análisis lo marca `HYPOTHESIS`: no hay un código de
   error específico de existencias. El clasificador trata todo 4xx como permanente (seguro, no
   loopea), pero conviene confirmar en cuenta viva si es 400 o 422 y si trae `response.errors`
   mapeable. **SIN VERIFICAR — Fase 0.**
8. **¿La factura `draft` mueve stock? (G1).** Sigue sin resolverse (`phase0-results.md`). Define el
   comportamiento exacto de `adjustment` + apertura manual. **SIN VERIFICAR — Fase 0.**

---

## 9. Relación con otros SDD

- **`docs/sdd/sync-reliability`** — es el SDD padre. Este cambio **consume** su dueño a nivel tienda
  (`design.md:505-620`), su guarda de poll (`design.md:873-878`) y REQ-INV-08, y **corrige** lo que
  quedó a medias: `Inventory_Writer` ya existe (el proposal de 2.5.1 decía "Nuevo"), la guarda de
  dueño del poll no se implementó, y `Stock_Order_Context` se descarta (G9 refutado). **No reabre**
  la decisión del híbrido: la hace **explícita y configurable**.
- **`docs/sdd/inventory`** — `Inventory_Writer` (su REQ-DIV-1) ya está implementado y se reusa.
  REQ-INV-07 (informe de divergencia) y REQ-INV-08 (un solo dueño) se implementan acá.
- **`docs/sdd/config-gates`** — `Write_Gate::run_explicit()` (`Write_Gate.php:156`) y la compuerta
  por entidad ya existen; el reintento manual los reusa. La opción de dueño se suma al patrón de
  defaults sembrados.
- **`docs/sdd/logs-monitor-import`** — su principio "un solo pipeline instrumentado" y "fail-loud"
  aplican: todo fallo de factura deja señal visible, y el reintento aparece en el Monitor como un
  run. Comparte `Logger`, `Runs` y `State_Sync::render_admin_notices` (`:488`).
- **`docs/sdd/payments`** — el sweep de pagos (`Controller.php:69-104`) es el molde del cron de
  reintento; su lógica no se toca. Comparten el rate limiter (`Client.php:954`), así que el
  presupuesto del poll sigue reduciendo la contención.
