# Especificación — Propiedad única del stock, poll sin re-inflación, cola de facturas y reconciliación (`stock-ownership`)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único de stock configurable + poll honesto + cola de facturas fallidas + reconciliación WC↔Alegra) |
| Documento base | `docs/sdd/stock-ownership/proposal.md` |
| Formato | Requerimientos EARS (Cuando/Si → el sistema DEBE) con escenarios Given/When/Then verificables desde el WP admin o el archivo de log |
| Estados | `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)` (espera Fase 0) · `FORK` (el diseño elige, el resultado es el especificado) |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) |
| Verificación | Cada `archivo:línea` fue re-verificado con lectura directa contra HEAD antes de escribir este documento (ver §Correcciones) |
| Relación | **Consume** `docs/sdd/sync-reliability/` (REQ-INV-07/08, dueño a nivel tienda, guarda de poll) y `docs/sdd/inventory/`; **no los duplica**; declara qué **agrega/corrige** (§E) |
| Invariante | REQ-OWN-01 |

> **Reglas de lectura.**
> 1. Cada requerimiento tiene **al menos un escenario** verificable desde el WP admin o el archivo de
>    log. No hay escenarios que requieran mirar la base de datos (los meta/ledger se observan por su
>    efecto en la UI, la nota del pedido o el log).
> 2. Los `BLOQUEADO(ON-VERIFICATION)` no se cierran hasta tener el resultado de Fase 0; cada uno
>    declara su **rama A / rama B** y el **check exacto** (§F).
> 3. Los `FORK` son requerimientos cuyo **resultado** es fijo pero cuyo **mecanismo/parámetro** decide
>    el diseño (p. ej. default de `stock_owner`). El escenario verifica el resultado, no el parámetro.
> 4. Los claims de código citan `archivo:línea`; lo no verificado se marca **SIN VERIFICAR**.
> 5. Los IDs son estables y se citan desde `design.md` y `tasks.md`.
> 6. **Fortaleza RFC 2119:** **DEBE** = MUST/SHALL (obligatorio) · **DEBERÍA** = SHOULD
>    (recomendado) · **PUEDE** = MAY (opcional).
> 7. **No duplicar `docs/sdd/sync-reliability/`.** Donde una capacidad ya está especificada allí, este
>    documento la **referencia** y declara si este cambio la **implementa**, la **corrige** o la
>    **consume** (§E).
> 8. **Idioma del escenario:** los `Dado/Cuando/Entonces` describen el **resultado observable**; el
>    ledger y los meta se verifican por la UI/cola/log, nunca por SQL.

---

## Correcciones de cita (re-verificadas contra HEAD)

La propuesta trae un bloque de correcciones a los tres análisis de entrada (su §0). Al re-verificar
**cada** cita contra el código en HEAD se confirma que **el resto de las citas es exacto**. Las
correcciones de la propuesta que se **confirman** y los matices que se agregan:

| Cita de la propuesta | Cita real verificada en HEAD | Nota |
|---|---|---|
| `Inventory_Pusher.php:85-91` (`owner()`) | **`Inventory_Pusher.php:85-91`** | Confirmado exacto. |
| `Inventory_Pusher.php:170-173` (Guard 2, `invoice_owner`) | **`Inventory_Pusher.php:170-173`** | Confirmado. El `return ... 'invoice_owner'` está en `:172`. |
| `Inventory_Pusher.php:197-216` (lógica de baseline) | **`Inventory_Pusher.php:197-216`** | Confirmado. `set_pending` en `:205`; baseline Alegra en `:209-215`. |
| `Inventory_Pusher.php:249-251` (`POST /inventory-adjustments`) | **`Inventory_Pusher.php:249-251`** | Confirmado. |
| `Inventory_Pusher.php:214,244,271` (`set_synced`) | **`Inventory_Pusher.php:214,244,271`** | Confirmado exacto. |
| `Inventory_Pusher.php:303-335` / `:340-358` | **`Inventory_Pusher.php:303-335` / `:340-358`** | Confirmado. `build_adjustment_payload()` **no** incluye `reference`. |
| `Products.php:1348-1349` (`$handled` sin `invoice_owner`) | **`Products.php:1348-1349`** | **Confirmado.** La lista real es `['ok','already_applied','in_sync','api_error','blocked','locked','baseline_unverified']`; `invoice_owner` **no está** ⇒ el poll cae al writer. La refutación de `ANALYSIS-all-invoiced-model.md:117` es **correcta**. |
| `Products.php:1332-1334` (`needs_reconcile`) | **`Products.php:1332-1334`** | Confirmado. |
| `Products.php:1378` (`set_synced` del poll) | **`Products.php:1378`** | Confirmado. Es el único `set_synced` del poll. |
| `Products.php:2276` (W1 usa `Inventory_Writer` y no setea ledger) | **`Products.php:2274-2276`** | Confirmado: `apply_inventory_to_product()` en `:2274`, `Inventory_Writer::apply()` en `:2276`; no hay `set_synced` en W1. |
| `Admin_Dashboard.php:2845-2895` (`ajax_open_invoice_impl`) | **`Admin_Dashboard.php:2845-2895`** | Confirmado (función `:2845`, cierra ~`:2895`). |
| `Admin_Dashboard.php:2897-3028` (`ajax_record_payment_impl`) | **`Admin_Dashboard.php:2897`** (wrapper) + **`:2902`** (`_impl`) | Matiz: el wrapper público está en `:2897` y la implementación arranca en `:2902`. El rango de la propuesta es válido. |
| `Admin_Dashboard.php:862-887` / gate `:903-905` | **`Admin_Dashboard.php:862-887` / `:903-905`** | Confirmado: el gate es `$divergence = (!get_option('...push_orders_enabled')) ? get_unjournaled_sales() : ['count'=>0,...]`. |
| `Admin_Dashboard.php:3964` (borra el transient) | **`Admin_Dashboard.php:3964`** | Confirmado. El mensaje final en `:3977`. |
| `Orders.php:494-540` / `:512` (pasa `'open'`) | **`Orders.php:494` / `:512`** | Confirmado. |
| `Orders.php:526` (`record_payment` descarta retorno) | **`Orders.php:526`** | Confirmado: la llamada no se asigna a nada. |
| `Orders.php:850-913` (`ensure_invoice_open`) | **`Orders.php:850-913`** | Confirmado (declaración en `:850`). |
| `Orders.php:82-107` (guard `_alegra_invoice_id` + FIX-3) | **`Orders.php:82-107`** | Confirmado. |
| `Orders.php:437-449` (`find_open_invoice_for_order`) | **`Orders.php:437-449`** | Confirmado. |
| `Orders.php:309-340` (pre-búsqueda AC-14) | **`Orders.php:309-340`** | Confirmado: `find_existing_invoice()` va `:309-340` (firma `:309`, `return null;` `:339`, cierre `:340`). La llamada AC-14 está en `:133-153`. |
| `Public_.php:411-416` (nota + log) | **`Public_.php:411-416`** | Confirmado. `trigger_sync()` en `:373`. |
| `Controller.php:72-74` (registro sweep) / `:89` | **`Controller.php:72-74`** / declaración en **`:86`** | Matiz: `run_payment_reconcile()` se **declara** en `:86`; el cuerpo útil arranca en `:88-89`. El rango `:69-104` es correcto. |
| `Controller.php:298-305` (sólo poll inbound) | **`Controller.php:298-305`** | Confirmado. |
| `Logger.php:198-241` (patrón option-backed) | **`Logger.php:198-241`** | Confirmado: `update_option('...logger_write_failed')` en `:204`; `render_write_failure_notice()` en `:224-241`. |
| `Write_Gate.php:156` (`run_explicit`) | **`Write_Gate.php:156`** | Confirmado. |
| `Inventory_Writer.php:39` (`apply()`) | **`Inventory_Writer.php:39`** | Confirmado (clase `:18`). |
| `.distignore:15` (excluye `scripts/`) | **`.distignore:15`** | Confirmado (`scripts/` en la línea 15). |
| `design.md:873-878` (guarda de dueño del poll, no implementada) | **`design.md:873-878`** | Confirmado: la guarda `owner==='invoice' && $a !== $w ⇒ continue` está en el diseño y **no** en `Products.php:1332-1379`. |
| `phase0-results.md:12` (G1 PENDING-LIVE) | **`phase0-results.md:12`** | Confirmado: G1 sigue `PENDING-LIVE`; el draft vs open no está resuelto. |

**Hechos confirmados por grep en producción (excluye `scripts/` y `docs/`):**

- `stock_owner` → **0 coincidencias**. La opción de dueño no existe (FRONT 1, A2).
- `_alegra_invoice_sync_state` → **0 coincidencias**. El ledger de fallo no existe (FRONT 3, C1).
- `_alegra_stock_adjusted` → **0 coincidencias**. La guarda de diseño no existe (A5).
- `Stock_Order_Context` → **0 coincidencias**. F1 no existe; G9 se descarta (§3.2 del proposal).
- `wp_mail` en `includes/`, `admin/`, `public/` → **0 coincidencias**. La "notificación" pedida no existe en ninguna forma (C6).
- `Inventory_Writer.php` **existe** (clase `:18`, `apply()` `:39`); el proposal de `sync-reliability` §6 quedó viejo al listarlo como "Nuevo".

**Sin verificación posible en este repo (marcado `SIN VERIFICAR`):**

- **G1:** ¿una factura `draft` de Alegra mueve stock, y `open` sí? (`phase0-results.md:12`, `PENDING-LIVE`).
- **Forma del error de stock de Alegra:** ¿es 400 o 422 y trae `response.errors` mapeable? El análisis lo marca `HYPOTHESIS`.

---

## Convenciones

- **Dueño** = `alegra_connector_stock_owner` ∈ `{auto, invoice, adjustment}` (default `auto`).
- **`owner()`** = `Inventory_Pusher::owner()` (`Inventory_Pusher.php:85-91`), el resolutor del dueño.
- **Pusher** = `Inventory_Pusher` (`includes/Sync/Inventory_Pusher.php`); **ajuste** = `POST /inventory-adjustments` (`Inventory_Pusher.php:249-251`).
- **Poll** = `Products::sync_inventory_from_alegra()` (`Products.php:1142`).
- **Import** = `Products::import_from_alegra()` (`Products.php:1455`).
- **W1** = `Products::apply_inventory_to_product()` (`Products.php:2274-2276`).
- **Ledger de stock** = `_alegra_stock_synced` (`Inventory_Pusher::synced()`) + `_alegra_stock_push_pending` (`Inventory_Pusher::pending()`).
- **Ledger de fallo** = los meta `_alegra_invoice_sync_state` / `_alegra_invoice_error_code` / `_alegra_invoice_error_message` / `_alegra_invoice_error_retriable` / `_alegra_invoice_attempts` / `_alegra_invoice_last_attempt` / `_alegra_invoice_next_retry`.
- **Cola** = pantalla "Facturas por subir" (submenu nuevo).
- **Compuerta de escritura** = `Write_Gate` (`includes/Write_Gate.php`); contexto explícito = `Write_Gate::run_explicit()` (`:156`).
- **Clasificador** = función pura que mapea un resultado a `(state, code, retriable)` (a crear).
- **Estado de sync** = `idle` · `pending` · `failed_retriable` · `failed_permanent` · `blocked` · `resolved`.
- **Nunca-intentado** = pedido `processing|completed|on-hold` sin `_alegra_invoice_id` y sin ledger (el conjunto actual de `get_unjournaled_sales()`).
- **Origen** (para logs): `manual` · `chunked` · `cron` · `webhook` (contrato de `logs-monitor-import/spec.md`).
- **Catálogo grande** = catálogo que no entra en el presupuesto de una corrida del poll (`alegra_connector_inventory_poll_budget`, default 60 s, `alegra-connector.php:455`).

---

## A. Propiedad única del stock (el fix del doble descuento)

> **Este cambio implementa** el desacoplamiento del dueño (Opción E del `ANALYSIS-double-discount.md`).
> **No reabre** la decisión del híbrido de `sync-reliability` (`REQ-INV-01`): la hace **explícita y
> configurable**. **Descarta** `Stock_Order_Context`/Guard 6 (G9 refutado por el core de WC) y usa la
> **advertencia gruesa** como red de seguridad (F4 del análisis).

### REQ-OWN-01 — Invariante de propiedad única `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)` parcial

**Cuando** se cierra este cambio, el sistema **DEBE** cumplir el invariante:

> **Todo pedido pagado tiene exactamente una factura de Alegra, y cada venta mueve el stock
> exactamente una vez, por un solo mecanismo (la factura o el ajuste, nunca ambos).**

El sistema **NO DEBE** permitir que un mismo movimiento de venta descuente stock dos veces, en
**ningún** modo (`auto`, `invoice`, `adjustment`). Esta cláusula **consume** `sync-reliability`
REQ-INV-08 (`spec.md:642-663`) y `inventory` REQ-DIV-1; **no la re-especifica**: la dota de una
opción determinista y testeable.

**BLOQUEADO(ON-VERIFICATION) — cláusula de stock:** la garantía "exactamente una vez" depende de
**G1** (¿el `draft` mueve stock?). Con **Rama A** (draft no mueve) el modo `invoice` mueve stock sólo
al abrir; con **Rama B** (draft mueve) el modo `invoice` mueve stock en la creación. En ambos casos
el invariante se cumple; lo que cambia es el **momento** del movimiento (ver REQ-OWN-04 y §F).

**Garantía honesta (alcance real).** El invariante se garantiza **por construcción en los caminos que
pasan por el plugin**: el dueño único (REQ-OWN-02/03/04) + la guarda server-enforced de la apertura
manual (REQ-OWN-06). **NO** se puede impedir que el comerciante abra la factura o registre el pago desde
la **UI/API de Alegra**: eso ocurre fuera del plugin y no dispara ningún hook de WC. Para ese caso el
sistema **DEBE** detectarlo y reportarlo como **divergencia** (REQ-RECON-01), no silenciarlo. Por lo
tanto: "doble descuento imposible" aplica al camino del plugin; el camino externo queda **detectado y
visible** (informe + causa), nunca invisible.

```gherkin
Escenario: Una venta mueve el stock exactamente una vez (modo invoice)
  Dado un producto con stock 10 en WC y 10 en Alegra
  Y alegra_connector_stock_owner=invoice
  Cuando se venden 3 unidades y se factura el pedido
  Entonces el stock de Alegra baja de 10 a 7 (una sola vez)
  Y el stock de WC queda en 7
  Y el log no registra ningún POST /inventory-adjustments para esa venta

Escenario: Una venta mueve el stock exactamente una vez (modo adjustment)
  Dado un producto con stock 10 en WC y 10 en Alegra
  Y alegra_connector_stock_owner=adjustment
  Cuando se venden 3 unidades y se abre la factura con confirmación
  Entonces el stock de Alegra baja exactamente 3
  Y el stock de WC queda en 7
  Y el log registra un único ajuste por la venta

Escenario (invariante): un pedido pagado tiene exactamente una factura
  Dado un pedido pagado
  Cuando se factura y luego se reintenta la subida
  Entonces Alegra tiene una sola factura para ese pedido
  Y el reintento reporta "ya facturado" en vez de crear otra

Escenario (negativo): ningún modo permite doble descuento
  Dado cualquier valor de alegra_connector_stock_owner
  Cuando se vende una unidad
  Entonces el stock de Alegra nunca baja dos veces por esa unidad
```

**Evidencia:** `Inventory_Pusher.php:85-91` (`owner()`), `:170-173` (Guard 2), `:249-251` (ajuste),
`Products.php:1348-1349` (`$handled`), `Orders.php:82-107` (idempotencia), `docs/sdd/sync-reliability/spec.md:642-663` (REQ-INV-08).

---

### REQ-OWN-02 — La opción de dueño y su resolución consistente `ACTIVO` · `FORK`

**Cuando** el sistema decide quién mueve el stock, **DEBE** resolver el dueño en **un solo lugar**
(`owner()`) a partir de `alegra_connector_stock_owner`, con la semántica:

- `invoice` ⇒ dueño `invoice` (la factura mueve el stock; el plugin **no** emite ajustes por pedido).
- `adjustment` ⇒ dueño `adjustment` (el plugin empuja los deltas; la factura **no** auto-abre).
- `auto` (default) ⇒ **condición DOBLE de 2.6.0**: `invoice` sólo si
  `push_orders_enabled && open_invoice_on_paid`; si no, `adjustment` (`Inventory_Pusher.php:87-90`).
  **NO** es la fórmula simplificada `push_orders_enabled` solo: con `push_orders_enabled=true` +
  `open_invoice_on_paid=false` la factura nace `draft` y **no** mueve stock; devolver `invoice` dejaría
  **cero** mecanismos moviendo stock (CORRECCIÓN C1 del diseño).

**NO DEBE** existir ninguna otra ruta que decida el dueño. `auto` por default **DEBE** reproducir
exactamente la lógica actual de `owner()` para no cambiar comportamiento sin acción del comerciante.

**Combinación borde `owner=invoice` + `push_orders_enabled=false` (facturación manual).** Cuando el
dueño es `invoice` explícito pero el push de pedidos está apagado, la compuerta bloquea la entidad
`invoice` (`Write_Gate.php:38-39`) y el plugin **no** registra los hooks de pedido (`Public_.php:49-59`),
mientras Guard 2 corta todo ajuste (`Inventory_Pusher.php:170-173`). El resultado es **cero movimiento
automático**: no hay doble descuento, pero tampoco "exactamente uno" automático. El sistema **DEBE**
documentarlo como **facturación manual** (el comerciante factura con "Facturar pendientes" y recién ahí
la factura —dueña— mueve el stock) y **DEBE** advertirlo en la UI al guardar esa combinación.

**FORK — default de la opción:** `auto` (compatibilidad total) vs `invoice` (alinea con el modelo
"todo facturado"). El **resultado** especificado es: con el default, una instalación que no toca la
opción se comporta como 2.6.0. El diseño elige el default; la propuesta §8.1 recomienda `auto`.

```gherkin
Escenario: auto reproduce la lógica de 2.6.0
  Dado alegra_connector_stock_owner=auto
  Y push_orders_enabled=false
  Cuando se consulta el dueño del stock en Ajustes
  Entonces el dueño efectivo es "Ajuste"

Escenario: auto con facturación activa resuelve factura
  Dado alegra_connector_stock_owner=auto
  Y push_orders_enabled=true
  Y open_invoice_on_paid=true
  Cuando se consulta el dueño del stock
  Entonces el dueño efectivo es "Factura"

Escenario (borde C1): auto con push sin auto-apertura resuelve ajuste
  Dado alegra_connector_stock_owner=auto
  Y push_orders_enabled=true
  Y open_invoice_on_paid=false
  Cuando se consulta el dueño del stock
  Entonces el dueño efectivo es "Ajuste"
  Y nunca queda el estado "ningún mecanismo mueve stock"

Escenario: la opción explícita manda sobre push_orders_enabled
  Dado alegra_connector_stock_owner=invoice
  Y push_orders_enabled=false
  Cuando se vende un producto
  Entonces el plugin NO emite ajustes por la venta
  Y no factura automáticamente (facturación manual)
  Y al facturar el comerciante ("Facturar pendientes") la factura es el único mecanismo de stock

Escenario (borde): un valor inválido cae a auto
  Dado alegra_connector_stock_owner con un valor no reconocido
  Cuando el sistema resuelve el dueño
  Entonces se comporta como auto
  Y el log advierte el valor inválido
```

**Evidencia:** `Inventory_Pusher.php:85-91` (única resolución hoy), `alegra-connector.php:418,460` (opciones que hoy la gobiernan), `alegra-connector.php:507-513` (loop de defaults).

---

### REQ-OWN-03 — El pusher respeta el dueño (invoice ⇒ cero ajustes por pedido) `ACTIVO`

**Cuando** `owner()` es `invoice`, el pusher **NO DEBE** emitir `POST /inventory-adjustments` para
cambios originados en un pedido. **DEBE** cortar en la guarda de dueño **antes** de cualquier cálculo
de baseline o POST. La guarda existente (`Inventory_Pusher.php:170-173`) ya lo hace; este
requerimiento la fija como contrato y agrega el caso "producto sin baseline": aun sin
`_alegra_stock_synced`, el modo `invoice` **NO DEBE** caer al writer del poll con el valor de Alegra
(ese hueco es REQ-POLL-03).

```gherkin
Escenario: Modo invoice no emite ajustes
  Dado owner=invoice
  Y un producto vinculado con manage_stock=true
  Cuando una venta baja su stock en WC
  Entonces el pusher retorna "invoice_owner" sin POST a Alegra
  Y el log no muestra "Inventory adjustment"

Escenario (borde): modo invoice tampoco emite ajuste en la reconciliación del poll
  Dado owner=invoice y un producto con baseline ausente
  Cuando corre el poll y detecta un delta local
  Entonces NO se emite POST /inventory-adjustments
  Y el poll reporta divergencia (no pisa WC)
```

**Evidencia:** `Inventory_Pusher.php:170-173` (Guard 2), `:157-168` (Guard 1), `Products.php:1348-1349`.

---

### REQ-OWN-04 — La factura respeta el dueño (adjustment ⇒ nunca auto-abre) `BLOQUEADO(ON-VERIFICATION)`

**Cuando** `owner()` es `adjustment`, el sistema **NO DEBE** abrir automáticamente una factura (ni
fijar `status='open'` en la creación) por un pedido pagado. La factura **DEBE** nacer/permanecer
`draft`. La apertura **manual** es una acción explícita del comerciante y **DEBE** pasar por la
advertencia de REQ-OWN-06. Cuando `owner()` es `invoice`, el sistema **DEBE** abrir/crear la factura
`open` (comportamiento de 2.6.0, `Orders.php:82-126`).

**BLOQUEADO(ON-VERIFICATION) — G1:** si el `draft` **no** mueve stock (Rama A), el modo `adjustment`
es seguro y la factura queda como documento sin efecto de stock. Si el `draft` **sí** mueve stock
(Rama B), el modo `adjustment` **NO DEBE** crear la factura con estado `draft` sin advertir que ya
movió stock; el diseño **DEBE** declarar cómo evita el doble conteo (ver §F, G1).

```gherkin
Escenario: Modo adjustment no auto-abre la factura
  Dado owner=adjustment
  Y un pedido pagado
  Cuando el flujo automático factura el pedido
  Entonces la factura se crea/queda en estado "borrador"
  Y el log no registra una apertura automática

Escenario: Modo invoice sí abre la factura
  Dado owner=invoice
  Y un pedido pagado
  Cuando el flujo factura el pedido
  Entonces la factura queda "open" (o se abre el borrador pre-existente)
  Y el log registra la apertura

Escenario (rama A): con draft que no mueve stock, adjustment no toca el stock
  Dado owner=adjustment y la Rama A de G1 (draft no mueve stock)
  Cuando se factura un pedido pagado
  Entonces el stock de Alegra no cambia por la factura
  Y el único movimiento lo hizo el ajuste

Escenario (rama B): con draft que mueve stock, el diseño no duplica
  Dado owner=adjustment y la Rama B de G1 (draft mueve stock)
  Cuando se factura un pedido pagado
  Entonces el sistema NO mueve el stock dos veces
  Y deja señal explícita del modo (advertencia/estado)
```

**Evidencia:** `Orders.php:82-126` (FIX-3 + override `open`), `Orders.php:512` (`create_invoice_with_payment` pasa `'open'`), `Orders.php:850-913` (`ensure_invoice_open`), `phase0-results.md:12` (G1).

---

### REQ-OWN-05 — Cambio de dueño seguro (sin arrastrar estado) `ACTIVO`

**Cuando** el comerciante cambia `alegra_connector_stock_owner` de un valor a otro, el sistema
**DEBE** limpiar `_alegra_stock_push_pending` de los productos afectados para no arrastrar un push
fallido de un modo al otro, y **DEBE** dejar que el poll re-baseline `_alegra_stock_synced` en su
próximo contacto. **NO DEBE** requerir migración de datos masiva. El cambio **DEBE** surtir efecto en
el **próximo** movimiento (el dueño es runtime, no persistido).

```gherkin
Escenario: Cambiar de adjustment a invoice limpia el pending
  Dado un producto con _alegra_stock_push_pending sucio por un push fallido
  Cuando el comerciante cambia el dueño a "Factura"
  Entonces el pending queda limpio
  Y el próximo poll no intenta reconciliar un ajuste en modo invoice

Escenario: El cambio de dueño no exige migración
  Dado una instalación con productos ya sincronizados
  Cuando el comerciante cambia el dueño
  Entonces el siguiente movimiento usa el nuevo dueño
  Y no se pide ningún paso de migración

Escenario (borde): cambiar el dueño no re-infla stock
  Dado un producto vendido con el ledger desactualizado
  Cuando se cambia el dueño y corre el poll
  Entonces el poll no sube el stock de WC por encima del valor vendido
```

**Evidencia:** `Inventory_Pusher.php:85-91` (runtime), `Inventory_Pusher.php:70-76` (`set_pending`/`clear_pending`), `Products.php:1327-1401` (re-baseline), `ANALYSIS-double-discount.md:340-354` (§5.1).

---

### REQ-OWN-06 — Advertencia gruesa en apertura manual / registro de pago `ACTIVO`

**Cuando** `owner()` es `adjustment` y el comerciante abre una factura o registra un pago desde el
admin (`ajax_open_invoice_impl`, `Admin_Dashboard.php:2845`; `ajax_record_payment_impl`,
`Admin_Dashboard.php:2902`), el sistema **DEBE** advertir y exigir confirmación explícita:
*"Los ajustes de inventario están activos; si ya se empujó un ajuste por este pedido, abrir la factura
descontará dos veces."* **DEBE** dejar una nota en el pedido. **NO DEBE** mostrar la advertencia en
modo `invoice` (donde abrir es la acción esperada). **NO DEBE** bloquear: el comerciante decide.

```gherkin
Escenario: Advertencia y confirmación en modo adjustment
  Dado owner=adjustment
  Y un pedido con ajuste ya empujado
  Cuando el comerciante pulsa "Abrir factura"
  Entonces ve la advertencia de doble descuento
  Y debe confirmar para continuar
  Y el pedido queda con una nota que registra la apertura

Escenario: Sin advertencia en modo invoice
  Dado owner=invoice
  Cuando el comerciante abre la factura
  Entonces la acción procede sin advertencia de doble descuento

Escenario (negativo): cancelar la confirmación no abre nada
  Dado owner=adjustment
  Cuando el comerciante cancela la confirmación
  Entonces no se emite ninguna llamada a Alegra
  Y la factura sigue en su estado previo

Escenario (seguridad): el AJAX exige nonce y capacidad
  Dado un request sin nonce válido o sin manage_woocommerce
  Cuando se invoca la apertura manual
  Entonces la respuesta es un error de permiso
  Y no se toca la API
```

**Evidencia:** `Admin_Dashboard.php:2845-2895`, `:2897-3028`, `design.md:601-604` (F4), `ANALYSIS-double-discount.md:305-309` (forma gruesa).

---

### REQ-OWN-07 — Control en Ajustes con nota anti-ambigüedad `ACTIVO`

**Cuando** el comerciante abre Ajustes (sección de inventario), el sistema **DEBE** mostrar un
selector "Dueño del stock" con las tres opciones (`Automático` / `Factura` / `Ajuste`) y la nota
clara: *"Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces."* **NO DEBE** usar
jerga. La sección existente de inventario (`templates/admin-settings.php:94-105`) **DEBE** seguir
coherente con la nueva opción (no debe contradecirla).

```gherkin
Escenario: El selector muestra las tres opciones y la nota
  Cuando el comerciante abre Ajustes → inventario
  Entonces ve "Dueño del stock" con Automático, Factura y Ajuste
  Y ve la nota "Elegí UNO solo; si elegís los dos, el stock se descuenta dos veces"

Escenario: El valor guardado se refleja al recargar
  Dado que el comerciante eligió "Factura"
  Cuando recarga Ajustes
  Entonces el selector muestra "Factura" seleccionado

Escenario (borde): la opción nueva se siembra sin pisar valores
  Dado una instalación actualizada
  Cuando se registran los defaults
  Entonces stock_owner toma "auto" si no existía
  Y no se sobrescribe ningún valor ya elegido
```

**Evidencia:** `templates/admin-settings.php:94-105`, `:110-111`, `alegra-connector.php:507-513` (defaults), `Write_Gate.php:182` (`maybe_migrate` como patrón).

---

## B. El poll: arreglar sus tres huecos (NO apagarlo)

> El poll **se mantiene**. Este FRONT cierra el **baseline ausente**, la **starvation** y la **falta
> de conciencia de dueño**. **No reabre** `sync-reliability` REQ-POLL-01..07 (presupuesto, cursor,
> `truncated`, lock, `set_syncing`, cron real): los **consume** y declara qué agrega. La dirección
> Alegra→WC (POS, ediciones manuales) **NO DEBE** desactivarse.

### REQ-POLL-01 — Baseline en el import `ACTIVO`

**Cuando** se importa/crea un producto desde Alegra, el sistema **DEBE** fijar
`_alegra_stock_synced` con el `availableQuantity` de Alegra, para que un producto importado y vendido
**antes** de su primer poll no tenga `synced=''` (que hoy produce `needs_reconcile=false` y
re-inflación). El import **DEBE** dejar el ledger consistente sin depender de que el poll corra.
Esto **corrige** `sync-reliability` REQ-INV-02 (W1) y cierra el Escenario C de
`ANALYSIS-all-invoiced-model.md`.

```gherkin
Escenario: Importar fija el baseline
  Dado un producto nuevo en Alegra con availableQuantity=10
  Cuando se importa el producto
  Entonces _alegra_stock_synced queda en 10
  Y el log del import lo registra

Escenario (borde): un producto importado y vendido antes del primer poll no re-infla
  Dado un producto recién importado con availableQuantity=10
  Cuando se venden 3 unidades antes de cualquier poll
  Y luego corre el poll
  Entonces el stock de WC NO vuelve a 10
  Y queda en 7 (o en el valor vendido)

Escenario (negativo): el import no pisa el stock de WC
  Dado un producto ya existente en WC
  Cuando se importa/actualiza desde Alegra
  Entonces el baseline se fija con el valor de Alegra
  Y la escritura de stock sigue pasando por Inventory_Writer
```

**Evidencia:** `Products.php:1455` (`import_from_alegra`), `Products.php:2274-2276` (W1),
`Inventory_Writer.php:39`, `Products.php:1378` (único `set_synced` actual del poll),
`ANALYSIS-all-invoiced-model.md:141-147` (Escenario C).

---

### REQ-POLL-02 — Baseline al mover stock la factura `ACTIVO`

**Cuando** se crea o abre una factura que mueve stock (`ensure_invoice_open`, `Orders.php:850-913`;
`create_invoice`, `Orders.php:63-193`), el sistema **DEBE** actualizar `_alegra_stock_synced` (o
registrar el delta de la factura) para que el ledger refleje el movimiento real. Sin esto, el ledger
queda desfasado y la guarda de dueño del poll (REQ-POLL-03) queda ciega o congelada ("starvation",
`ANALYSIS-all-invoiced-model.md` §2.4).

```gherkin
Escenario: Abrir la factura actualiza el ledger
  Dado owner=invoice y un producto con WC=7 / Alegra=7 tras la factura
  Cuando se abre/crea la factura que mueve stock
  Entonces _alegra_stock_synced queda en 7
  Y el log registra la actualización del baseline

Escenario (borde): el poll no queda congelado para ese producto
  Dado un producto cuya factura ya movió stock y actualizó el baseline
  Cuando Alegra cambia el stock por una edición manual (sin cambio local pendiente)
  Entonces un poll posterior baja el nuevo valor a WC
  Y no reporta divergencia permanente

Escenario (negativo): sin apertura no se inventa un baseline
  Dado owner=invoice y un pedido impago con factura draft
  Cuando no se abre la factura
  Entonces el baseline no se adelanta al movimiento
  Y el poll no re-infla WC
```

**Evidencia:** `Orders.php:63-193`, `:850-913`, `Inventory_Pusher.php:214,244,271` (patrón `set_synced`), `Products.php:1378`, `ANALYSIS-all-invoiced-model.md:151-153`.

---

### REQ-POLL-03 — Poll con conciencia de dueño (no pisa WC en invoice) `ACTIVO`

**Cuando** `owner()` es `invoice` y el valor de Alegra difiere del de WC, el poll **NO DEBE** escribir
el valor de Alegra en WC mientras la factura no haya movido stock; **DEBE** reportar divergencia. Esta
es la guarda que el diseño de `sync-reliability` especificó (`design.md:873-878`) y que **no se
implementó** (`Products.php:1332-1379` no la tiene). El `reason='invoice_owner'` **DEBE** dejar de
caer al writer del poll. **NO DEBE** apagar el poll.

```gherkin
Escenario: En modo invoice el poll no pisa la venta
  Dado owner=invoice
  Y WC=7 / Alegra=10 (la factura todavía no movió stock)
  Cuando corre el poll
  Entonces el stock de WC sigue en 7
  Y el informe/log reporta la divergencia
  Y no se emite POST /inventory-adjustments

Escenario (borde): con baseline ausente tampoco pisa
  Dado owner=invoice y un producto con _alegra_stock_synced vacío
  Y WC=7 / Alegra=10
  Cuando corre el poll
  Entonces el stock de WC no se re-infla a 10
  Y el poll reporta la divergencia o fija el baseline correcto

Escenario (negativo): sin divergencia el poll no alarma
  Dado owner=invoice y WC=Alegra
  Cuando corre el poll
  Entonces no se reporta divergencia
  Y no se emite ningún ajuste
```

**Evidencia:** `Products.php:1332-1349` (hoy cae al writer), `Inventory_Pusher.php:170-173` (`invoice_owner` fuera de `$handled`), `design.md:873-878` (guarda especificada), `Products.php:1378`.

---

### REQ-POLL-04 — El poll nunca sobrescribe un cambio local pendiente `ACTIVO`

**Cuando** existe un cambio local pendiente (`_alegra_stock_push_pending` no vacío) o un delta local
sin empujar (`WC != synced`), el poll **NO DEBE** sobrescribir el valor de WC con el de Alegra.
**DEBE** reconciliar (empujar el delta, en modo `adjustment`) o reportar divergencia (en modo
`invoice`), según el dueño. Esto **consume** la regla `needs_reconcile` existente
(`Products.php:1332-1334`) y la extiende al modo `invoice`.

```gherkin
Escenario: Un push fallido no se pisa con el valor de Alegra
  Dado un producto con _alegra_stock_push_pending sucio por un push fallido
  Cuando corre el poll
  Entonces el stock de WC no se sobrescribe con el de Alegra
  Y el poll reintenta la reconciliación o reporta divergencia

Escenario: Un delta local sin empujar no se pierde
  Dado un producto con WC=7 y synced=10 (delta local -3)
  Y owner=adjustment
  Cuando corre el poll
  Entonces el poll empuja el delta a Alegra
  Y WC queda en 7

Escenario (borde): en modo invoice un cambio local pendiente reporta, no pisa
  Dado owner=invoice y un delta local sin empujar
  Cuando corre el poll
  Entonces el stock de WC se conserva
  Y se reporta divergencia en vez de escribir el valor de Alegra
```

**Evidencia:** `Products.php:1327-1401`, `Inventory_Pusher.php:232-247` (`pending_prev` + `already_applied`), `Products.php:1332-1334`.

---

### REQ-POLL-05 — El poll sigue corriendo (Alegra→WC preservado) `ACTIVO`

**Cuando** el plugin está configurado, el poll **DEBE** seguir bajando cambios de Alegra a WC (POS,
ediciones manuales) cuando **no** hay un cambio local pendiente. **NO DEBE** desactivarse como "fix".
El presupuesto, el cursor y `truncated` de 2.6.0 (`alegra-connector.php:455-456`; `Products.php:1233-1240`)
**DEBEN** conservarse. La fuente de inventario `woocommerce` **PUEDE** seguir apagando el poll
(comportamiento existente, `Products.php:1170-1174`), pero el default `alegra` **DEBE** mantenerlo
activo.

```gherkin
Escenario: Un cambio de stock hecho en Alegra baja a WC
  Dado que no hay cambio local pendiente
  Y Alegra cambia el stock de 7 a 12 (POS o edición manual)
  Cuando corre el poll
  Entonces el stock de WC queda en 12
  Y el log registra "Inventory updated from Alegra"

Escenario (borde): el presupuesto y el cursor siguen vigentes
  Dado un catálogo grande
  Cuando corre el poll
  Entonces se corta por presupuesto (no por fatal)
  Y persiste el cursor para reanudar
  Y el resultado reporta truncated cuando quedó trabajo

Escenario (negativo): el poll no se apaga por este cambio
  Cuando se cierra este cambio
  Entonces el cron del poll sigue registrado
  Y la dirección Alegra→WC sigue activa con el default
```

**Evidencia:** `Products.php:1142-1174`, `:1233-1240`, `alegra-connector.php:445,449,455-456`, `spec.md:674-737` (REQ-POLL-01/02, consumidos).

---

### REQ-POLL-06 — `reference` en la idempotencia de ajustes `ACTIVO`

**Cuando** el plugin emite o pre-chequea un ajuste, el payload y el pre-chequeo **DEBEN** incluir una
`reference` que identifique el movimiento, para no confundir un `out 1` viejo con uno nuevo. El
pre-chequeo actual (`adjustment_already_exists`, `Inventory_Pusher.php:303-335`) matchea por
`item+type+quantity` en los últimos 30 y **no usa `reference`**; un ajuste legítimo puede marcarse
`already_applied` y no emitirse (fuga de stock). El diseño lo exigía (`design.md:714`) y **no se
implementó**.

```gherkin
Escenario: Dos ajustes iguales no se confunden
  Dado un ajuste previo `out 1` del mismo ítem con reference R1
  Cuando se necesita un nuevo ajuste `out 1` con reference R2
  Entonces el pre-chequeo NO lo marca como ya aplicado
  Y se emite el POST con reference R2

Escenario (borde): un ajuste idéntico sí se reconoce
  Dado un ajuste previo con reference R1 cuya respuesta se perdió
  Cuando se reintenta el mismo movimiento con reference R1
  Entonces el pre-chequeo lo reconoce como aplicado
  Y no se duplica el ajuste

Escenario (negativo): el payload expone la referencia
  Cuando se construye el payload de un ajuste
  Entonces incluye el campo reference del movimiento
  Y el log permite auditar qué ajuste corresponde a qué pedido
```

**Evidencia:** `Inventory_Pusher.php:303-335` (sin `reference`), `:340-358` (payload sin `reference`), `design.md:714`.

---

## C. Cola de facturas fallidas (ledger + lista + reintento)

> **Este cambio implementa** el ledger, el clasificador, la pantalla, el reintento y la notificación
> del `ANALYSIS-failure-handling.md`. **Consume** la idempotencia de `sync-reliability` REQ-CF-06 y
> REQ-INV-*: no las duplica. **No reabre** el sweep de pagos (`Controller.php:86-104`).

### REQ-QUEUE-01 — Ledger por pedido (CRUD/HPOS) `ACTIVO`

**Cuando** la subida de una factura termina en un fallo terminal o en un bloqueo, el sistema **DEBE**
persistir en el pedido los meta `_alegra_invoice_sync_state` (`idle|pending|failed_retriable|
failed_permanent|blocked|resolved`), `_alegra_invoice_error_code`, `_alegra_invoice_error_message`,
`_alegra_invoice_error_retriable`, `_alegra_invoice_attempts`, `_alegra_invoice_last_attempt` y
`_alegra_invoice_next_retry`. **DEBE** escribir vía CRUD (`update_meta_data()`/`save()`) para ser
HPOS-safe. En éxito, **DEBE** limpiar el ledger y marcar `resolved`, sin tocar `_alegra_invoice_id`
(marcador de éxito existente).

```gherkin
Escenario: Un fallo terminal queda persistido
  Dado un pedido cuya factura falla con un error de red
  Cuando termina el intento
  Entonces el pedido queda en la cola con su código, motivo, intentos y último intento
  Y el estado de sync es "failed_retriable"

Escenario: Un éxito limpia el ledger
  Dado un pedido que estaba en failed_retriable
  Cuando la factura sube correctamente
  Entonces el pedido sale de la cola
  Y su estado de sync es "resolved"

Escenario (borde): el ledger sobrevive al fin del request
  Dado un fallo producido en un request de admin
  Cuando el request termina
  Entonces el fallo sigue visible al recargar la cola
  Y no se pierde como un toast efímero

Escenario (HPOS): los meta se escriben por CRUD
  Dado un pedido en HPOS
  Cuando se persiste el fallo
  Entonces los meta quedan legibles por la cola
  Y no se usa SQL directo
```

**Evidencia:** `Admin_Dashboard.php:862-887` (hoy "pendiente" se infiere de la ausencia de id),
`Orders.php:203-229` (`persist_invoice_result`), `ANALYSIS-failure-handling.md:193-221` (§4.1).

---

### REQ-QUEUE-02 — Clasificador puro retriable/permanente `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)`

**Cuando** se evalúa un resultado de subida, el sistema **DEBE** clasificarlo en **un solo lugar**
compartido por el cron y la UI:

- **retriable** → `failed_retriable`: red/timeout, 429, 5xx, `rate_limited`, `json_error`, lock en curso.
- **permanente** → `failed_permanent`: 400/401/403/404/409/422, errores de datos (`customer_unresolved`,
  `invoice_item_unlinked`, `invoice_shipping_unlinked`, `invoice_fee_unlinked`) y rechazo de stock.
- **bloqueado** → `blocked`: kill switch / entidad deshabilitada / dry-run (dry-run **NO** se persiste).

**BLOQUEADO(ON-VERIFICATION) — forma del error de stock:** no hay un código de error específico de
existencias documentado. La clasificación segura (todo 4xx salvo 429 = permanente) **NO DEBE** loopear.
Si Fase 0 confirma que el rechazo de stock es 400 o 422 con `response.errors` mapeable, el diseño
**PUEDE** agregar un código simbólico `stock_insufficient` sin cambiar la clasificación (sigue
permanente).

```gherkin
Escenario: Un error de red es retriable
  Dado un resultado con código "http_request_failed" o "api_error" 503
  Cuando se clasifica
  Entonces es "failed_retriable"

Escenario: Un 422 de validación es permanente
  Dado un resultado con código "api_error" 422
  Cuando se clasifica
  Entonces es "failed_permanent"

Escenario: Un bloqueo por kill switch es blocked
  Dado el kill switch activo
  Cuando se intenta subir una factura
  Entonces el estado es "blocked"
  Y no se reintenta automáticamente

Escenario (borde): el dry-run no se persiste
  Dado dry_run activo
  Cuando se intenta subir una factura
  Entonces no se escribe ningún fallo en el ledger
  Y el pedido no aparece en la cola

Escenario (stock, rama A): un rechazo de stock es permanente
  Dado que Alegra rechaza una factura por falta de existencias (4xx)
  Cuando se clasifica
  Entonces es "failed_permanent"
  Y el cron no lo reintenta en loop
```

**Evidencia:** `Client.php:177-255` (mapeo de error), `Client.php:308` (`is_retryable_wp_error`), `Write_Gate.php:156` (bloqueos), `ANALYSIS-failure-handling.md:29-78,290-307`.

---

### REQ-QUEUE-03 — Pantalla "Facturas por subir" `ACTIVO`

**Cuando** el comerciante abre el submenu "Facturas por subir", el sistema **DEBE** listar los pedidos
`failed_retriable`, `failed_permanent`, `blocked` y los **nunca-intentados**, con las columnas
orden/fecha/cliente/total/estado Alegra/motivo/código/intentos/último intento/próximo reintento, y
con filtros por estado de sync, rango de fechas y búsqueda. **DEBE** soportar paginación. El conteo
de la lista **DEBE** coincidir con el badge (REQ-QUEUE-07).

```gherkin
Escenario: La pantalla lista las facturas fallidas
  Dado un pedido en failed_retriable y otro en failed_permanent
  Cuando el comerciante abre "Facturas por subir"
  Entonces ve ambos pedidos con su motivo y código
  Y ve los intentos y el último intento

Escenario: La pantalla distingue los nunca-intentados
  Dado un pedido pagado sin factura y sin ledger
  Cuando el comerciante abre la cola
  Entonces el pedido aparece como nunca-intentado
  Y no se confunde con un fallo

Escenario (borde): los filtros acotan la lista
  Dado que el comerciante filtra por "permanente"
  Cuando aplica el filtro
  Entonces sólo ve los pedidos failed_permanent
  Y el conteo de la vista coincide con el filtro

Escenario (escalabilidad): la lista está paginada
  Dado una tienda con muchos pedidos fallidos
  Cuando se abre la cola
  Entonces se muestra una página acotada
  Y la navegación permite ver el resto sin agotar el request
```

**Evidencia:** `Admin_Dashboard.php:106-234` (patrón `add_admin_menu`), `:862-887` (query base),
`templates/admin-monitor.php` (patrón de pantalla), `ANALYSIS-failure-handling.md:223-245` (§4.2).

---

### REQ-QUEUE-04 — Reintento single `ACTIVO`

**Cuando** el comerciante pulsa "Reintentar" en una fila, el sistema **DEBE** ejecutar la subida bajo
`Write_Gate::run_explicit()` (una acción manual debe bypassar `entity_disabled`), **DEBE** actualizar
el ledger con el resultado y **DEBE** reportar el desenlace en la UI. **NO DEBE** crear una segunda
factura si `_alegra_invoice_id` ya existe (REQ-QUEUE-09).

```gherkin
Escenario: Reintentar sube la factura
  Dado un pedido en failed_retriable con el problema ya resuelto
  Cuando el comerciante pulsa "Reintentar"
  Entonces la factura se crea en Alegra
  Y el pedido sale de la cola (estado "resolved")
  Y la UI muestra el éxito

Escenario: Reintentar un fallo permanente lo deja permanente
  Dado un pedido en failed_permanent
  Cuando el comerciante reintenta sin corregir los datos
  Entonces el pedido sigue en failed_permanent
  Y la UI muestra el motivo actualizado

Escenario (seguridad): el AJAX exige nonce y capacidad
  Dado un request sin nonce o sin permiso
  Cuando se invoca "Reintentar"
  Entonces la respuesta es un error de permiso
  Y no se toca la API

Escenario (borde): un pedido ya facturado no se reintenta
  Dado un pedido con _alegra_invoice_id ya presente
  Cuando se pulsa "Reintentar"
  Entonces la UI reporta que ya tiene factura
  Y no se crea una segunda
```

**Evidencia:** `Admin_Dashboard.php:3166-3168` (patrón `run_explicit`), `Orders.php:494` (`create_invoice_with_payment`), `Orders.php:82-107` (guard), `Write_Gate.php:156`.

---

### REQ-QUEUE-05 — Reintento bulk `ACTIVO`

**Cuando** el comerciante selecciona varias filas y pulsa "Reintentar seleccionados", el sistema
**DEBE** procesarlas en lotes de 10 por request reusando el flujo chunked existente
(`ajax_sync_pending_*`) con un `scope=failed` que filtre por el ledger. **DEBE** actualizar el ledger
por pedido. **NO DEBE** tocar el transient de import `alegra_batch_state`.

```gherkin
Escenario: El bulk reintenta en lotes
  Dado 25 pedidos fallidos seleccionados
  Cuando el comerciante pulsa "Reintentar seleccionados"
  Entonces se procesan de a 10 por request
  Y al terminar cada pedido tiene su ledger actualizado

Escenario (borde): el bulk no re-encola los que ya subieron
  Dado un pedido que se resolvió durante el bulk
  Cuando el lote siguiente lo alcanza
  Entonces se saltea por idempotencia
  Y no se crea una segunda factura

Escenario (negativo): el bulk no mezcla import
  Dado un import en curso con alegra_batch_state
  Cuando se corre el bulk de reintento
  Entonces el transient de import no se modifica
  Y el progreso del bulk se reporta por separado
```

**Evidencia:** `Admin_Dashboard.php:3867-3979` (flujo chunked), `:3964` (borra el transient), `ANALYSIS-failure-handling.md:247-259`.

---

### REQ-QUEUE-06 — Cron horario con backoff + tope (sólo retriable) `ACTIVO`

**Cuando** `alegra_connector_invoice_retry_enabled` está activo, el sistema **DEBE** reintentar
**sólo** los `failed_retriable` con backoff exponencial y tope de intentos; al alcanzar el tope
**DEBE** pasar a `failed_permanent`. Los `failed_permanent` y `blocked` **NO DEBEN** auto-reintentarse
(una falta de stock no puede loopear). El cron **DEBE** respetar kill switch, lock global y
presupuesto, y **DEBE** aparecer como un run en el Monitor. Con el cron inactivo (o sin cron real),
el reintento **manual** **DEBE** seguir disponible.

```gherkin
Escenario: El cron reintenta sólo los retriables
  Dado invoice_retry_enabled=true
  Y un pedido failed_retriable y otro failed_permanent
  Cuando corre el cron horario
  Entonces se reintenta el retriable
  Y el permanente no se toca

Escenario: El backoff y el tope se respetan
  Dado un pedido failed_retriable que sigue fallando
  Cuando corren varios ciclos del cron
  Entonces el próximo intento se posterga con backoff
  Y al alcanzar el tope pasa a failed_permanent

Escenario: El sweep aparece en el Monitor
  Cuando corre el cron de reintento
  Entonces el Monitor muestra un run "invoice_retry" con items y errores

Escenario (borde): sin cron real el reintento manual sigue
  Dado un host sin cron real
  Cuando el comerciante abre la cola
  Entonces el botón "Reintentar" está disponible
  Y no depende del cron
```

**Evidencia:** `Controller.php:72-74` (registro del sweep de pagos), `:86-104` (`run_payment_reconcile`),
`:154,164` (lock global + shutdown), `alegra-connector.php:567,669-674` (schedule), `ANALYSIS-failure-handling.md:261-280`.

---

### REQ-QUEUE-07 — Notificación admin + badge `ACTIVO`

**Cuando** existe al menos una factura fallida, el sistema **DEBE** mostrar un aviso dismissible en el
admin con el conteo y un enlace a la cola, y **DEBE** mostrar un badge en el submenu "Facturas por
subir". El aviso **DEBE** sobrevivir a fallos escritos por cron (patrón option-backed, sin depender de
un request de usuario) y **DEBE** desaparecer cuando el conteo llega a cero. El conteo del badge
**DEBE** estar cacheado para no ejecutar una query en cada carga del admin.

```gherkin
Escenario: Un fallo produce aviso y badge
  Dado un fallo de factura registrado por cron
  Cuando el comerciante entra al admin
  Entonces ve el aviso "N facturas no se pudieron subir" con enlace a la lista
  Y el submenu muestra el badge con N

Escenario: El aviso desaparece al resolverse
  Dado que el conteo de fallos llega a cero
  Cuando el comerciante recarga el admin
  Entonces no aparece el aviso
  Y el badge no se muestra

Escenario (borde): el badge no castiga la carga del admin
  Dado una tienda con muchos pedidos
  Cuando se carga cualquier página del admin
  Entonces el badge se lee de un conteo cacheado
  Y no se ejecuta una consulta pesada por página

Escenario (negativo): el dry-run no genera aviso
  Dado dry_run activo
  Cuando se intenta subir una factura
  Entonces no se genera aviso ni badge
```

**Evidencia:** `Logger.php:198-241` (patrón option-backed), `State_Sync.php:488` (`render_admin_notices`), `ANALYSIS-failure-handling.md:282-288`.

---

### REQ-QUEUE-08 — Distinción de estados (no confundir "nunca intentado" con "falló") `ACTIVO`

**Cuando** la cola clasifica un pedido, el sistema **DEBE** distinguir **cuatro** situaciones:
(a) **nunca-intentado** (pagado, sin `_alegra_invoice_id`, sin ledger); (b) **failed_retriable**;
(c) **failed_permanent**; (d) **blocked**. **NO DEBE** inferir "pendiente" sólo de la ausencia de
`_alegra_invoice_id` (comportamiento actual), porque no distingue "nunca se intentó" de "falló por un
motivo". El estado `payment_missing` (factura ok, pago no registrado) **PUEDE** mostrarse como estado
distinto (fork del diseño, §8.4 de la propuesta).

```gherkin
Escenario: Un pedido nunca intentado no se muestra como fallo
  Dado un pedido pagado sin factura y sin ledger
  Cuando se abre la cola
  Entonces figura como "nunca intentado"
  Y no como failed_retriable/permanent

Escenario: Un fallo retriable y uno permanente se distinguen
  Dado un pedido failed_retriable y otro failed_permanent
  Cuando se abre la cola
  Entonces cada uno muestra su estado distinto
  Y el filtro por estado los separa

Escenario (borde): un bloqueo se distingue del fallo
  Dado el kill switch activo al intentar facturar
  Cuando se abre la cola
  Entonces el pedido figura como "blocked"
  Y no como failed_retriable
```

**Evidencia:** `Admin_Dashboard.php:862-887,903-905` (hoy sólo `NOT EXISTS`),
`ANALYSIS-failure-handling.md:175,221` (gaps y definición de nunca-intentado).

---

### REQ-QUEUE-09 — Idempotencia del reintento (nunca segunda factura) `ACTIVO`

**Cuando** se reintenta una subida, el sistema **DEBE** reusar las guardas existentes
(`_alegra_invoice_id` `Orders.php:82-107`, `find_open_invoice_for_order` `:437-449`, pre-búsqueda AC-14
`:133-153,309-340`, lock por pedido `:66-79`) y, ante un fallo retriable/desconocido (donde el POST
pudo haber commiteado), **DEBE** re-buscar la factura **antes** de persistir el fallo. Si la encuentra,
**DEBE** adoptarla como éxito en vez de crear otra. Esto **cierra** el hueco intra-request del cliente
(`Client.php:177-255` reintenta el POST sin idempotency key).

```gherkin
Escenario: Un timeout con POST commiteado no duplica
  Dado un POST /invoices que entró en Alegra pero cuya respuesta se perdió
  Cuando se reintenta la subida
  Entonces find_existing_invoice encuentra la factura
  Y el pedido queda "resolved" con el id recuperado
  Y no se crea una segunda factura

Escenario: El lock impide dos reintentos simultáneos
  Dado dos reintentos del mismo pedido en paralelo
  Cuando ambos intentan facturar
  Entonces uno toma el lock y el otro se saltea
  Y Alegra recibe una sola factura

Escenario (borde): el ledger se limpia al adoptar la factura
  Dado un pedido failed_retriable cuya factura se recupera
  Cuando se adopta la factura existente
  Entonces el ledger pasa a "resolved"
  Y el pedido sale de la cola
```

**Evidencia:** `Orders.php:66-79`, `:82-107`, `:133-153`, `:309-340`, `:437-449`, `Client.php:177-255`, `ANALYSIS-failure-handling.md:309-311` (§4.7).

---

## D. Reconciliación WC↔Alegra

> **Este cambio implementa** `sync-reliability` REQ-INV-07 (informe de divergencia, hoy no
> implementado) y la reparación de `ANALYSIS-all-invoiced-model.md` §4.3. **No duplica** REQ-INV-07:
> lo hace **siempre visible** y le agrega la **causa** y la **reparación explícita**.

### REQ-RECON-01 — Informe de divergencia con causa `ACTIVO`

**Cuando** el comerciante abre el informe de divergencia, el sistema **DEBE** comparar, por producto
vinculado, el stock de WC con el `availableQuantity` de Alegra y **DEBE** listar los que difieren con
la **causa** (factura fallida / baseline ausente / reembolso / divergencia de dueño). **NO DEBE**
mostrar como divergencia un producto que coincide.

```gherkin
Escenario: Se listan los productos divergentes con su causa
  Dado un producto con WC=7 y Alegra=10 por una factura fallida
  Cuando el comerciante abre el informe
  Entonces el producto aparece listado
  Y muestra la causa "factura fallida"

Escenario (negativo): sin divergencia no alarma
  Dado que todos los productos vinculados coinciden
  Cuando se abre el informe
  Entonces no se listan productos
  Y no aparece advertencia

Escenario (borde): un baseline ausente se distingue
  Dado un producto con _alegra_stock_synced vacío y WC != Alegra
  Cuando se abre el informe
  Entonces la causa reportada es "baseline ausente"
  Y se sugiere re-baselinar
```

**Evidencia:** `Products.php:1329-1379` (ledger), `Inventory_Pusher.php:283-297` (`fetch_alegra_available_quantity`), `spec.md:616-638` (REQ-INV-07 consumido), `ANALYSIS-all-invoiced-model.md:200-213` (§4.3-4).

---

### REQ-RECON-02 — Des-invertir el gate de "Ventas sin factura" `ACTIVO`

**Cuando** el dashboard renderiza el reporte "Ventas sin factura", el sistema **DEBE** mostrarlo
**siempre**, sin importar `push_orders_enabled`, y **DEBE** ampliar su semántica a `_alegra_invoice_id`
ausente/vacío o con estado `draft`/`void`. Hoy la fila **se oculta** exactamente en el modo del
comerciante (`push_orders_enabled=true`, `Admin_Dashboard.php:903-905`) y sólo detecta `NOT EXISTS`
(`:862-887`). El cambio de comportamiento **DEBE** documentarse en `CHANGELOG.md`.

```gherkin
Escenario: El reporte se muestra en modo facturación automática
  Dado push_orders_enabled=true
  Y un pedido pagado sin factura
  Cuando el comerciante abre el dashboard
  Entonces la fila "Ventas sin factura" es visible
  Y muestra el conteo

Escenario: Una factura draft se detecta como pendiente
  Dado un pedido con _alegra_invoice_id y estado draft
  Cuando se evalúa el reporte
  Entonces el pedido se cuenta como venta sin factura emitida

Escenario: Una factura void se detecta como pendiente
  Dado un pedido con _alegra_invoice_id y estado void
  Cuando se evalúa el reporte
  Entonces el pedido se cuenta como venta sin factura vigente

Escenario (borde): un meta vacío se detecta
  Dado un pedido con _alegra_invoice_id igual a cadena vacía
  Cuando se evalúa el reporte
  Entonces el pedido se cuenta como pendiente
```

**Evidencia:** `Admin_Dashboard.php:862-887` (query actual), `:903-905` (gate invertido), `templates/admin-dashboard.php:197-212`, `ANALYSIS-all-invoiced-model.md:189-202`.

---

### REQ-RECON-03 — Reparación explícita (nunca ambos mecanismos) `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)`

**Cuando** el comerciante decide reparar un producto divergente, el sistema **DEBE** hacerlo de forma
**explícita** (bajo `Write_Gate::run_explicit()`), emitiendo **o** la factura faltante (si hay pedido)
**o** un ajuste correctivo por el delta, **nunca ambos para el mismo movimiento** (REQ-INV-08). **DEBE**
dejar rastro en log + nota. **NO DEBE** reparar automáticamente. La elección del mecanismo **DEBE**
respetar el dueño vigente.

**BLOQUEADO(ON-VERIFICATION) — G1:** si la factura `draft` **no** mueve stock (Rama A), la reparación
por factura exige abrirla; si **sí** mueve (Rama B), la creación ya movió y la reparación debe
re-baselinar en vez de re-emitir. El diseño **DEBE** declarar la rama.

```gherkin
Escenario: La reparación es explícita y confirmada
  Dado un producto divergente con un pedido pagado sin factura
  Cuando el comerciante pulsa "Reparar"
  Entonces se le pide confirmación
  Y recién entonces se emite el mecanismo elegido

Escenario: Nunca se emiten los dos mecanismos
  Dado un producto divergente
  Cuando se repara emitiendo la factura
  Entonces NO se emite además un ajuste por el mismo movimiento
  Y el stock de Alegra queda correcto (una sola vez)

Escenario (borde): la reparación deja rastro
  Cuando se ejecuta una reparación
  Entonces el log registra el delta y el mecanismo
  Y el pedido/producto queda con una nota

Escenario (seguridad): sin permiso no se repara
  Dado un request sin nonce o sin manage_woocommerce
  Cuando se invoca la reparación
  Entonces la respuesta es un error de permiso
  Y no se toca la API
```

**Evidencia:** `Write_Gate.php:156`, `Orders.php:494` (factura), `Inventory_Pusher.php:249-251` (ajuste),
`spec.md:642-663` (REQ-INV-08), `ANALYSIS-all-invoiced-model.md:210-212` (§4.3-5).

---

## E. Requerimientos no funcionales

### NFR-01 — Sin regresión en factura, pago, webhook e import `ACTIVO`

**Cuando** se cierra el cambio, los flujos de **factura**, **pago**, **webhook** e **import**
**DEBEN** seguir funcionando con su contrato actual. Las únicas alteraciones permitidas en el camino de
factura son: la actualización del baseline (REQ-POLL-02), la persistencia del ledger (REQ-QUEUE-01),
la advertencia gruesa (REQ-OWN-06) y la re-búsqueda post-error (REQ-QUEUE-09). **NO DEBE** duplicarse
ninguna factura.

```gherkin
Escenario: La factura normal sigue igual
  Dado un pedido pagado con billing normal y owner=invoice
  Cuando se factura
  Entonces el payload y el flujo son los de HEAD
  Y no hay requests nuevos por la cola ni la reconciliación

Escenario: El webhook sigue procesando
  Dado un webhook 'new-item' válido
  Cuando se procesa
  Entonces el ítem se importa como hoy

Escenario: El pago se sigue registrando
  Dado un pedido con factura vinculada
  Cuando se completa el pago
  Entonces se registra el pago como hoy

Escenario: El import no se rompe
  Dado un catálogo a importar
  Cuando corre el import
  Entonces importa/actualiza como hoy
  Y sólo agrega el baseline del ledger (REQ-POLL-01)
```

**Evidencia:** `Orders.php:63-193`, `Public_.php:49-67`, `Products.php:1455`, `spec.md:888-914` (NFR-01 de `sync-reliability`).

---

### NFR-02 — Plugin distribuido: sin supuestos de host `ACTIVO`

**Cuando** el plugin corre en cualquier host, el dueño único **NO DEBE** depender del orden de hooks
de WC (refutado por G9), el poll **NO DEBE** depender de que la factura se abra dentro del plugin, y la
cola de reintentos **NO DEBE** depender de cron real: **DEBE** degradar a "reintento manual siempre
disponible". La UI **NO DEBE** mentir: si no puede comprobar algo, lo dice.

```gherkin
Escenario: El reintento manual funciona sin cron real
  Dado un host sin cron real ni Action Scheduler
  Cuando el comerciante abre la cola
  Entonces puede reintentar manualmente
  Y el resultado se refleja en el ledger

Escenario: El dueño no depende del orden de hooks
  Dado cualquier tema/plugin que altere el orden de hooks de WC
  Cuando se vende un producto
  Entonces el dueño efectivo es el configurado
  Y no se duplica el descuento

Escenario: La UI es honesta cuando no puede comprobar
  Dado un host donde el informe no puede leer Alegra
  Cuando el comerciante abre el informe
  Entonces ve "no verificado" con el motivo
  Y no un resultado inventado
```

**Evidencia:** `ANALYSIS-double-discount.md` §3 (G9 refutado), `Inventory_Pusher.php:85-91` (dueño determinista), `ANALYSIS-failure-handling.md:384` (cron host-dependent).

---

### NFR-03 — Compatibilidad hacia atrás para una instalación 2.6.0 `ACTIVO`

**Cuando** se actualiza una instalación 2.6.0 existente, las opciones nuevas (`stock_owner`,
`invoice_retry_enabled`, `invoice_retry_max_attempts`, `invoice_retry_batch`) **DEBEN** sembrarse con
defaults seguros **sin pisar** valores elegidos, siguiendo el loop de defaults
(`alegra-connector.php:507-513`) y el patrón `Write_Gate::maybe_migrate()` (`:182`). Con
`stock_owner=auto`, `owner()` **DEBE** devolver exactamente lo mismo que hoy. **NO DEBE** haber
migración de datos masiva. Los cambios intencionales de comportamiento (reporte "Ventas sin factura"
siempre visible; semántica `draft`/`void`) **DEBEN** documentarse en `CHANGELOG.md`.

```gherkin
Escenario: Una instalación existente no cambia de comportamiento
  Dado un sitio 2.6.0 con opciones ya configuradas
  Cuando se actualiza el plugin
  Entonces stock_owner toma "auto"
  Y owner() devuelve lo mismo que en 2.6.0
  Y ningún valor elegido se sobrescribe

Escenario: El ledger de fallo es aditivo
  Dado un pedido sin meta de fallo
  Cuando se actualiza el plugin
  Entonces el pedido no aparece en la cola
  Y no se requiere migración

Escenario (borde): el cambio intencional está documentado
  Entonces CHANGELOG.md describe que "Ventas sin factura" se muestra siempre
  Y que detecta facturas draft/void
```

**Evidencia:** `alegra-connector.php:414-468` (defaults), `:507-513` (loop), `Write_Gate.php:182` (`maybe_migrate`), `spec.md:964-993` (NFR-04 de `sync-reliability`).

---

### NFR-04 — Escalabilidad (catálogos grandes, muchos pedidos) `ACTIVO`

**Cuando** la tienda tiene un catálogo grande o muchos pedidos, el sistema **DEBE** acotar el trabajo:
el poll **DEBE** conservar presupuesto y cursor (REQ-POLL-05); el informe y la cola **DEBEN** estar
paginados; el badge **DEBE** leer un conteo cacheado; el cron de reintento **DEBE** procesar por lotes
acotados. **NO DEBE** ejecutar una consulta de meta sin `limit` en cada carga del admin.

```gherkin
Escenario: El poll acota su trabajo en un catálogo grande
  Dado un catálogo que no entra en el presupuesto
  Cuando corre el poll
  Entonces se corta por presupuesto y persiste el cursor
  Y no produce un fatal 500

Escenario: La cola y el informe no cargan todo
  Dado muchos pedidos y productos
  Cuando se abren la cola y el informe
  Entonces cada pantalla carga una página acotada
  Y la respuesta no agota el request

Escenario (borde): el badge no hace una query por página
  Dado un admin con muchas páginas
  Cuando se navega entre ellas
  Entonces el badge se sirve de un conteo cacheado
```

**Evidencia:** `Products.php:1233-1240` (presupuesto/tope), `:1404-1423` (cursor), `ANALYSIS-failure-handling.md:385` (costo de meta query).

---

### NFR-05 — El ZIP de release excluye `scripts/` `ACTIVO`

**Cuando** se construye el ZIP de release, **NO DEBE** contener `scripts/`, tests ni harness. La
exclusión está en `.distignore:15` y la aplica el script de build. Este cambio **NO DEBE** agregar
carpetas de test ni harness distribuibles.

```gherkin
Escenario (estático): el ZIP no contiene scripts
  Cuando se construye el release
  Entonces el ZIP no incluye scripts/
  Y no incluye tests/ ni harness
  Y .distignore sigue excluyendo scripts/
```

**Evidencia:** `.distignore:15`, `spec.md:997-1010` (NFR-05 de `sync-reliability`), propuesta §2.9/§3.2.

---

### NFR-06 — Seguridad: compuertas, nonce y sin secretos en logs `ACTIVO`

**Cuando** se ejecutan las acciones nuevas (reintento single/bulk, reparación, advertencia manual), el
sistema **DEBE** respetar `Write_Gate` (kill switch + entidad) y **DEBE** exigir nonce y capacidad en
cada AJAX. Los logs **NO DEBEN** exponer token ni credenciales.

```gherkin
Escenario: La compuerta sigue siendo autoridad
  Dado el kill switch activo
  Cuando se dispara cualquier acción nueva
  Entonces no se emite ningún POST a Alegra
  Y se registra el motivo

Escenario: Los AJAX nuevos exigen nonce y capacidad
  Dado un request sin nonce o sin permiso
  Cuando se invoca una acción nueva
  Entonces la respuesta es un error de permiso
  Y no se toca la API

Escenario (borde): los logs no filtran el token
  Cuando se escribe una entrada de log con contexto de API
  Entonces el token/credenciales no aparecen en el texto
```

**Evidencia:** `Write_Gate.php:156`, `Admin_Dashboard.php:3166-3168` (nonce/capacidad en el patrón), `spec.md:1014-1039` (NFR-06 de `sync-reliability`).

---

## F. Trazabilidad (requerimiento → propuesta)

> La propuesta no tiene una sección literal "What Changes"; el mapeo apunta a **§3.1 Alcance
> (dentro del alcance)**, a los **hallazgos §4 (A1-A7, B1-B7, C1-C8, D1-D5)** y al **enfoque §5**.

| Grupo | Requerimientos | §3.1 (Alcance) | Hallazgos §4 | Enfoque §5 |
|---|---|---|---|---|
| A — Propiedad única | REQ-OWN-01..07 | 1) Opción `stock_owner`; `owner()` lee la opción; advertencia manual; control en dashboard; sanear `pending` al cambiar de dueño | A1, A2, A3, A4, A5, A6, A7 | 5.1 |
| B — Poll | REQ-POLL-01..06 | 2) Baseline en el import; baseline al mover stock la factura; poll con conciencia de dueño; `reference` en idempotencia; mantener el poll | B1, B2, B3, B4, B5, B6, B7 | 5.2 |
| C — Cola de facturas | REQ-QUEUE-01..09 | 3) Ledger por pedido; clasificador puro; pantalla "Facturas por subir"; reintento single/bulk; cron con backoff y tope; notificación + badge; idempotencia | C1, C2, C3, C4, C5, C6, C7, C8 | 5.3 |
| D — Reconciliación | REQ-RECON-01..03 | 4) Informe de divergencia; des-invertir el gate; reparación explícita | D1, D2, D3, D4, D5 | 5.4 |
| NFR | NFR-01..06 | §3.3 Restricción transversal; §2 decisiones 5/7/8/9 | §6 Riesgo / compatibilidad / migración | 5.5 |

### Relación con `docs/sdd/sync-reliability/` (consumidos, no duplicados)

| Requerimiento `sync-reliability` | Estado en HEAD | Este cambio |
|---|---|---|
| REQ-INV-01 (una venta se refleja en Alegra) | Parcial (ajuste o factura, pero con huecos) | **REQ-OWN-01..04** fija el dueño único; **REQ-POLL-01..03** cierran los huecos |
| REQ-INV-02 (un solo escritor `Inventory_Writer`) | Implementado (`Inventory_Writer.php:39`) | Se **consume**; **REQ-POLL-01** agrega el baseline en el import |
| REQ-INV-07 (informe de divergencia) | No implementado | **REQ-RECON-01/02** lo implementan y le agregan causa + des-inversión del gate |
| REQ-INV-08 (nunca (a) y (b) para el mismo movimiento) | Guard 2 lo suprime con `invoice` | Se **consume** como invariante (**REQ-OWN-01**) y **REQ-RECON-03** lo respeta en la reparación |
| REQ-POLL-01/02 (presupuesto + cursor + `truncated`) | Implementado (`Products.php:1233-1240,1425`) | Se **conserva** (**REQ-POLL-05**); no se reabre |
| REQ-CF-06 (auto-sanar CF) | Implementado (`Orders.php:351-430`) | Se **consume**; **REQ-QUEUE-09** agrega la re-búsqueda post-error |
| REQ-CF-07 (fail-loud) | Parcial (log) | **REQ-QUEUE-01/07** lo completan (ledger + aviso + badge) |
| Dueño a nivel tienda (`design.md:505-620`) | Parcial (implícito) | **REQ-OWN-02** lo hace explícito y configurable |
| Guarda de poll (`design.md:873-878`) | **No implementada** | **REQ-POLL-03** la implementa |
| `Stock_Order_Context` / Guard 6 | **No implementable** (G9 refutado) | **Se descarta**; se reemplaza por la advertencia gruesa (**REQ-OWN-06**) |

---

## G. Bloqueados por verificación

Cada bloqueo declara el **check exacto** de Fase 0 y sus **ramas**. Ningún `BLOQUEADO` se cierra sin el
resultado.

| Requerimiento | Incógnita | Check exacto | Rama A | Rama B |
|---|---|---|---|---|
| REQ-OWN-01 / REQ-OWN-04 / REQ-RECON-03 | **G1: ¿una factura `draft` mueve stock, y `open` sí?** | En la cuenta del comerciante: crear una factura `draft` por un ítem con stock conocido y observar `availableQuantity`; luego abrirla (`PUT status=open`) y observar. Guion: `phase0-results.md:23-54`. | El borrador **no** mueve stock y `open` sí → `adjustment` seguro; `invoice` mueve al abrir | El borrador **sí** mueve stock → el modo `adjustment` no debe crear `draft` sin advertir; `invoice` mueve en la creación |
| REQ-QUEUE-02 | **Forma del error de stock de Alegra** | Forzar una factura sin existencias en una cuenta viva y observar el status (400 vs 422) y si trae `response.errors` mapeable. | 400/422 sin código específico → se mantiene "todo 4xx salvo 429 = permanente" | 422 con `response.errors` de stock → se agrega el código `stock_insufficient` (sigue permanente) |
| REQ-OWN-02 (FORK) | **Default de `stock_owner`** | Decisión del comerciante. | `auto` (compatibilidad total, recomendado) | `invoice` (alinea con "todo facturado") |
| REQ-OWN-06 | **¿Advertir o bloquear en modo `adjustment`?** | **Resuelto por el cuerpo del requerimiento (`spec.md:322-324`): NO DEBE bloquear.** El diseño elige el **mecanismo** (confirmación server-enforced `confirm_double_discount=1` + nota + log). | Advertir con confirmación server-enforced (**mecanismo elegido**, honra el cuerpo) | Descartada: bloquear contradice "NO DEBE bloquear" |
| REQ-QUEUE-06 (FORK) | **¿El reintento automático arranca encendido?** | Decisión del comerciante. | Apagado / opt-in (recomendado) | Encendido por default |
| REQ-QUEUE-08 (FORK) | **¿La cola incluye `payment_missing`?** | Decisión del comerciante. | Sí, como estado distinto | No, queda sólo en el sweep de pagos |
| REQ-RECON-03 (FORK) | **¿La reparación puede emitir la factura automáticamente?** | Decisión del comerciante. | Manual con confirmación (recomendado) | Automática si hay pedido pagado |

### Cerrados / moot

| Incógnita | Estado | Motivo |
|---|---|---|
| G9 (orden de hooks) | **Rama B (cerrado)** | El core de WC dispara `woocommerce_product_set_stock` **dentro** de `wc_update_product_stock()` y `woocommerce_reduce_order_stock` **después** (`ANALYSIS-double-discount.md` §3). `Stock_Order_Context` **no** se implementa. |
| `Inventory_Writer` "Nuevo" | **Corregido** | En HEAD el archivo **existe** (`Inventory_Writer.php:18,39`); el proposal de `sync-reliability` §6 quedó viejo. |
| `owner=invoice` y el poll | **Confirmado** | `invoice_owner` **no** está en `$handled` (`Products.php:1348-1349`) ⇒ el poll cae al writer. La refutación de `ANALYSIS-all-invoiced-model.md:117` es correcta. |
| El poll se apaga | **Fuera de alcance** | Decisión explícita del comerciante (propuesta §2.8); **REQ-POLL-05** lo prohíbe. |
| DIAN / facturación electrónica | **Fuera de alcance** | Propuesta §3.2; no se toca `stamp`/`paymentForm`. |
