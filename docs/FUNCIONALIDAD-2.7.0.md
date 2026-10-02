# Alegra Connector 2.7.0 — Cómo funciona hoy

**Guía funcional para el comerciante.** Describe, en lenguaje claro, qué hace el plugin hoy
(versión **2.7.0**) con **ventas, facturación, inventario y clientes**, y quién manda en cada caso.

> **Cómo leer este documento.**
> - **VERIFICADO** = lo comprobamos leyendo el código de la 2.7.0. Cada afirmación trae su
>   referencia `archivo:línea` para que cualquiera lo pueda auditar.
> - **HIPÓTESIS / SIN VERIFICAR** = depende de algo que **no se puede comprobar en el código**
>   (por ejemplo, cómo responde la cuenta real de Alegra). Está marcado en rojo.
> - El plugin es la versión `2.7.0` (`alegra-connector.php:6`).
> - "Dueño del stock" = quién descuenta las existencias en Alegra.
> - "Ajuste" = `POST /inventory-adjustments` (el plugin le dice a Alegra que saque/ingrese stock).
> - "Factura" = el documento de Alegra; al **abrirse** (pasa de borrador a abierta/pagada) Alegra
>   descuenta stock.

---

## 1. Los 3 canales y quién manda (el modelo de propiedad del stock)

Hay **un solo interruptor** que decide quién mueve el stock: la opción
`alegra_connector_stock_owner` (`Inventory_Pusher.php:118-134`), con tres valores:

| Valor | Qué significa para el comerciante |
|---|---|
| `auto` (**default**) | El plugin decide solo. Reproduce el comportamiento de la versión 2.6.0: usa **Factura** solo si están activas **las dos** opciones a la vez (`push_orders_enabled` **y** `open_invoice_on_paid`); si no, usa **Ajuste**. |
| `invoice` (Factura) | La **factura de Alegra es la única** que descuenta stock. El plugin **no** emite ajustes por ventas. |
| `adjustment` (Ajuste) | El **plugin** empuja los ajustes; la factura **nunca** se abre sola (queda en borrador). |

La resolución vive en **un único lugar** (`Inventory_Pusher::owner()`,
`Inventory_Pusher.php:118-134`). Un valor no reconocido cae a `auto` y deja un aviso en el log
(`Inventory_Pusher.php:125-128,140-153`). **VERIFICADO.**

### Tabla: quién descuenta y si hay doble descuento

| Modo | Quién descuenta el stock de Alegra | ¿Doble descuento? |
|---|---|---|
| **`invoice`** | La **factura** al abrirse. El plugin **no** emite ajustes: el guard de dueño corta **todo** ajuste originado en un pedido (`Inventory_Pusher.php:233-235`). | **Imposible por los caminos del plugin.** Abrir la factura a mano no es un segundo movimiento: nunca hubo un primer ajuste. |
| **`adjustment`** | El **plugin**, con un ajuste por delta (`Inventory_Pusher.php:313-315`). La factura nace/queda **borrador** y no auto-abre (el override a `open` exige `owner()==='invoice'`, `Orders.php:128-132,618-619`). | **Evitable.** El riesgo es abrir la factura a mano: el plugin lo **advierte y exige confirmación** (ver §2 y §6). |
| **`auto`** | Deriva a uno de los dos. | No agrega caminos. |

**Advertencia honesta (alcance real).** El plugin garantiza "un solo dueño" en **sus propios
caminos**. Si el comerciante abre la factura o registra el pago desde la **pantalla de Alegra**
(fuera del plugin), eso no dispara ningún hook y el plugin no lo puede impedir: lo **detecta y lo
reporta** como divergencia en el informe de reconciliación (§7). **VERIFICADO** (diseño:
`docs/sdd/stock-ownership/spec.md`; detección: `Stock_Divergence.php:173-231`).

### La interacción `push_orders_enabled` / `open_invoice_on_paid` / `invoice_status`

| Opción | Default | Qué hace |
|---|---|---|
| `alegra_connector_push_orders_enabled` | `false` (`alegra-connector.php:420`) | **Gobierna la auto-facturación.** Si está en `false`, el plugin **no registra** los hooks de pedido (`Public_.php:49-59`) y la compuerta bloquea la entidad `invoice` (`Write_Gate.php:37-38,114-131`). La facturación es **manual** ("Facturar pendientes"). |
| `alegra_connector_open_invoice_on_paid` | `true` (`alegra-connector.php:462`) | Si está activa, un pedido **pagado** abre su factura (Alegra descuenta). La interfaz la **fuerza a ON** cuando el dueño es `invoice` (`templates/admin-settings.php:113-115`; coerción en `Admin_Dashboard.php:2315`). |
| `alegra_connector_invoice_status` | `draft` (`alegra-connector.php:471`) | Estado con el que nace la factura si no hay override. El override a `open` solo ocurre con `owner()==='invoice'` **y** pedido pagado (`Orders.php:128-132,1510`). |

**Combinación borde:** `stock_owner=invoice` + `push_orders_enabled=false` ⇒ **cero movimiento
automático**: no hay ajustes (el dueño es factura) y no hay auto-facturación (la compuerta la
bloquea). No es doble descuento, pero tampoco es "exactamente uno" automático. Se documenta como
**facturación manual** (`docs/sdd/stock-ownership/spec.md` REQ-OWN-02). **VERIFICADO** (rutas).
**Ojo con `invoice_status='open'`:** un pedido **impago** crearía una factura abierta que mueve
stock antes del pago; la interfaz lo advierte en rojo (`templates/admin-settings.php:126-128`).

---

## 2. Venta en WooCommerce → Alegra (el flujo completo)

### 2.1 Ciclo de vida del pedido: qué hook dispara qué

Todos los hooks de subida de pedidos están **gated** por `push_orders_enabled` (`Public_.php:49-59`).

| Estado / evento de WC | Hook que lo escucha | Qué hace el plugin |
|---|---|---|
| `pending` (recién creado, impago) | — | Nada automático. No hay hook de stock. |
| `on-hold` | `woocommerce_order_status_on-hold` (`Public_.php:53`) | Crea la factura: `trigger_sync('order', id, 'create')` → `create_invoice()` (`Public_.php:273-277`; `Controller.php:417-418`). |
| `processing` | `woocommerce_payment_complete` + `woocommerce_order_status_processing` | El pago dispara `create_invoice_with_payment()` (`Public_.php:51,267-271`; `Controller.php:419-420`). Además corre la **reconciliación de pago** siempre activa (`Public_.php:65-67`). |
| `completed` | `woocommerce_order_status_completed` (`Public_.php:52`) | `create_invoice_with_payment()` (registra el pago) + reconciliación de pago. |
| `cancelled` | `woocommerce_order_status_cancelled` (`Public_.php:57`) | Anula la factura: `void_invoice()` (`Controller.php:423-424`). |
| `refunded` | **No** está en `Public_` | Los reembolsos los maneja **exclusivamente** `State_Sync::handle_refund()` (nota de crédito). Antes había un segundo hook que duplicaba la nota de crédito (`Public_.php:54-57`). |
| `failed` | `woocommerce_order_status_failed` (`Public_.php:58`) | Anula la factura (`Controller.php:423-424`). |

### 2.2 ¿Cuándo se crea, se abre y se cobra la factura?

- **Se crea** en los eventos de la tabla anterior (si `push_orders_enabled=true`). En modo manual,
  con los botones **"Facturar"**, **"Facturar seleccionados"** o **"Facturar pendientes"**
  (`Orders::sync_recent()`, `Orders.php:1454-1486`; caller `Admin_Dashboard.php:4410`).
- **Se abre** (pasa a `open`, que es cuando Alegra descuenta) cuando el dueño es `invoice` **y** el
  pedido está pagado (`Orders.php:128-132`). Si ya existía una factura borrador, se abre
  (`Orders.php:94-107`; `ensure_invoice_open()`, `Orders.php:1114-1177`).
- **El pago se registra** con `POST /payments` en `record_payment_for_invoice()`
  (`Orders.php:687-794`, POST en `:748`), solo si hay **cuenta de destino** configurada, no hay pago
  previo y el pedido está pagado (`Orders.php:609-611`). Si falla, el pedido queda en la cola como
  `payment_missing` (`Orders.php:637-646`). El pago **nunca** crea una factura nueva
  (`Public_.php:294-347`).

### 2.3 ¿Cuándo baja el stock de WC y cuándo el de Alegra?

- **WC:** lo baja **el core de WooCommerce**, cuando el pedido pasa a `processing`/`completed`
  (pagado). El plugin no lo controla; de hecho lo **espera** para fijar el baseline
  (`Orders.php:313-315`: el baseline exige `is_paid()`). **VERIFICADO** (dependencia del core).
- **Alegra:**
  - Modo **`invoice`** → al **abrirse la factura** (Alegra lo descuenta nativamente).
  - Modo **`adjustment`** → cuando el **plugin** empuja el ajuste. El disparador es el hook de WC
    `woocommerce_product_set_stock` (`Inventory_Pusher.php:159-187`), que se ejecuta cuando WC
    actualiza el stock.

### 2.4 Secuencia exacta (por modo)

**Modo `invoice` (Factura) — "todo facturado":**

| Paso | Acción | WC | Alegra |
|---|---|---|---|
| 1 | El cliente paga. WC pasa a `processing`/`completed`. | 10 → **7** (core WC) | 10 |
| 2 | Hook `woocommerce_product_set_stock` → `push_delta` → guard de dueño. | 7 | 10 |
| 3 | El guard devuelve `invoice_owner`: **no** hay ajuste (`Inventory_Pusher.php:233-235`). | 7 | 10 |
| 4 | `create_invoice_with_payment` → `create_invoice` con `'open'` (`Orders.php:618-619`). | 7 | 10 |
| 5 | `baseline_products_for_invoice` fija el baseline = stock WC (`Orders.php:308-328`, llamado en `:99` y `:219`). | 7 | 10 |
| 6 | La factura se abre en Alegra. **Alegra descuenta.** | 7 | **7** |
| 7 | `POST /payments` registra el pago (si hay cuenta configurada). | 7 | 7 |

**Modo `adjustment` (Ajuste):**

| Paso | Acción | WC | Alegra |
|---|---|---|---|
| 1 | El cliente paga. WC reduce stock. | 10 → **7** | 10 |
| 2 | Hook de stock → `push_delta`. Dueño = ajuste. | 7 | 10 |
| 3 | Calcula delta (`7 − 10 = −3`) y hace `POST /inventory-adjustments {out 3}` (`Inventory_Pusher.php:280-338`). | 7 | **7** |
| 4 | La factura nace/queda **borrador** (no mueve stock). | 7 | 7 |
| 5 | Si el comerciante la abre a mano desde el plugin, aparece **confirmación obligatoria** (ver §6). | 7 | ⚠️ |

> **HIPÓTESIS / SIN VERIFICAR — G1.** Que una factura **borrador** *no* mueva stock y una
> **abierta** *sí* es la premisa del diseño, pero **no se puede comprobar en el repo**
> (`docs/sdd/stock-ownership/phase0-results.md:12`, estado `PENDING-LIVE`). Define el
> comportamiento exacto del modo `adjustment` + apertura manual. **Verificar en la cuenta real.**

### 2.5 El rol de `invoice_status=draft`

- Es el **default** (`alegra-connector.php:471`). La factura nace borrador y **no** descuenta stock
  (premisa G1). Al pagarse, si el dueño es `invoice`, se **abre** (FIX-3, `Orders.php:88-101`).
- Con `invoice_status='open'`, la factura se crea ya abierta y **mueve stock aunque el pedido esté
  impago** → divergencia hasta que WC reduzca. La interfaz lo advierte (`templates/admin-settings.php:126-128`).
- En modo `adjustment` el estado de la factura es irrelevante para el stock: el plugin nunca la abre.

---

## 3. Venta en Alegra (POS / factura manual) → WooCommerce

### 3.1 ¿Cómo se entera WooCommerce?

Hay **dos caminos**, y ninguno reemplaza al otro:

1. **El poll (sincronización periódica).** `Products::sync_inventory_from_alegra()`
   (`Products.php:1142`). Corre dentro del cron (`Controller.php:269-275`), que solo lo ejecuta si
   `alegra_connector_inventory_source='alegra'` **y** `alegra_connector_inventory_sync_enabled=true`
   (`Controller.php:269-270`). También se puede disparar a mano con la acción
   `alegra_sync_inventory_from_alegra` (`Controller.php:65-67`).
2. **El webhook `edit-item`.** Cuando Alegra avisa que un ítem cambió, el receptor llama a
   `Products::sync_single_item_by_alegra_id()` (`Handlers.php:31-33,60-75`).

### 3.2 La máquina de estados del poll, en lenguaje llano

Por cada producto vinculado, el poll compara cuatro valores:

- **W** = stock actual en WooCommerce.
- **A** = `availableQuantity` que reporta Alegra.
- **S** = `_alegra_stock_synced` (el último valor en el que WC y Alegra **acordaron**).
- **P** = `_alegra_stock_push_pending` (un valor de WC que se está empujando).

Estados (`Products.php:1356-1358`):

| Estado | Regla | Qué hace el poll |
|---|---|---|
| **CONVERGED** | `S` existe, `P` vacío y `W == S` | Todo en orden: si Alegra cambió `A`, baja `A` a WC. |
| **NO_BASELINE_EQ** | `S` vacío, `P` vacío, `A` usable y `W == A` | Fija el baseline (`S = A`) sin escribir de más. |
| **LOCAL_PENDING** | Cualquier otro caso | **Hay un cambio local sin empujar.** NO pisa WC. |

Dentro de `LOCAL_PENDING` (`Products.php:1372-1424`):
- Si se puede empujar (dueño = ajuste, push activo, producto vinculado y con gestión de stock):
  intenta el ajuste. Si sale bien o ya estaba aplicado → **no** escribe WC; si Alegra cambió de
  verdad (`in_sync`) → deja que el writer baje el valor; si falla → **divergencia, no pisa WC**.
- Si **no** se puede empujar (dueño = factura, o push apagado): fija un baseline pre-venta si falta
  y **reporta divergencia; no pisa WC** (`Products.php:1411-1423`).

### 3.3 ¿Cuándo el poll ESCRIBE stock en WC y cuándo NO?

- **ESCRIBE** solo cuando **no** hay cambio local pendiente y Alegra manda una cantidad usable:
  llama al escritor único en `Products.php:1441` (bloque `if ($has_a)` en `:1434`).
- **NO ESCRIBE** cuando:
  - el kill switch está activo (`Products.php:1165-1169`);
  - `inventory_source=woocommerce` (`Products.php:1173-1177`);
  - otro proceso tiene el lock (`Products.php:1179-1184`);
  - hay cambio local pendiente y el ajuste falló o no se puede empujar
    (`Products.php:1383,1396,1408,1423`);
  - Alegra no manda cantidad usable (`Products.php:1434`).

### 3.4 El baseline `_alegra_stock_synced` (la clave contra la re-inflación)

Este meta es "el último valor en el que ambos acordaron". El poll **no** escribe WC a ciegas si no
lo tiene: con `S` vacío y `W != A` cae en `LOCAL_PENDING` y no pisa WC (`Products.php:1358,1411-1423`).
Además, la **importación** de productos ahora fija el baseline tras escribir stock
(`Products.php:2370-2374`), cerrando el agujero por el cual un producto importado y vendido antes
del primer poll se re-inflaba. **VERIFICADO.**

### 3.5 Latencia (cron + WP-Cron)

- La frecuencia del cron se configura con `alegra_connector_sync_frequency`, default **15 minutos**
  (`alegra-connector.php:418`); se admiten **5, 15, 30 o 60** minutos
  (`alegra-connector.php:259-276,610-638`).
- **Cuidado con WP-Cron:** por defecto WP-Cron **no corre solo**, dispara cuando alguien visita el
  sitio. En tiendas con poco tráfico el poll puede tardar más de lo configurado. Para producción
  conviene un **cron real del sistema** apuntando a `wp-cron.php`. **VERIFICADO** (comportamiento
  de WP, no del plugin).
- El webhook `edit-item`, si Alegra lo manda con inventario, da actualización casi inmediata (ver
  §8 y §10).

### 3.6 Una venta de POS sin pedido de WC

No crea ningún pedido en WooCommerce: es un **cambio de stock en Alegra**. El poll (o el webhook)
trae el nuevo valor `A` a WC **siempre que no haya un cambio local pendiente**. Es el caso de uso
central de la dirección Alegra→WC. **VERIFICADO** (diseño; `Products.php:1432-1455`).

---

## 4. Inventario: el escritor único y las compuertas

### 4.1 Un solo escritor de stock: `Inventory_Writer`

`Inventory_Writer::apply()` (`Inventory_Writer.php:18,39`) es el **único** punto del plugin que
llama a `wc_update_product_stock()` (`Inventory_Writer.php:146-157`). Tanto la importación
(`Products.php:2357`) como el poll (`Products.php:1441`) delegan ahí. Esto evita que dos partes del
plugin escriban stock a la vez. **VERIFICADO.**

### 4.2 El empujador y el ledger: `Inventory_Pusher`

`Inventory_Pusher::push_delta()` (`Inventory_Pusher.php:219-342`) calcula el delta y emite el
ajuste. Maneja tres metas de control:

| Meta | Para qué sirve | Dónde |
|---|---|---|
| `_alegra_stock_synced` | Último valor en el que WC y Alegra acordaron. | `Inventory_Pusher.php:32,57-66` |
| `_alegra_stock_push_pending` | Valor de WC que se está empujando (se limpia al OK). | `Inventory_Pusher.php:33,72-86` |
| `_alegra_stock_adjusted_at` | Marca de que el plugin **emitió** un ajuste para ese producto. La usa la advertencia de apertura manual. | `Inventory_Pusher.php:34,92-104` |

Además, el ajuste ahora lleva una **`reference` estable** (`wc-stock-{producto}-{synced}-{nuevo}`,
`Inventory_Pusher.php:452-455,519`) para no confundir un ajuste viejo con uno nuevo y para reconocer
un reintento del mismo movimiento (`Inventory_Pusher.php:463-510`). **VERIFICADO.**

### 4.3 Las compuertas (qué se puede y qué no)

| Compuerta | Opción | Efecto |
|---|---|---|
| **Fuente de inventario** | `alegra_connector_inventory_source` (`alegra` default, `alegra-connector.php:447`) | Si es `woocommerce`, el poll **no toca** el stock (`Products.php:1173-1177`) y el writer devuelve `skipped_source` (`Inventory_Writer.php:50-53`). |
| **Sincronización de inventario** | `alegra_connector_inventory_sync_enabled` (default `true`, `alegra-connector.php:451`) | Si está apagada, el cron **no** corre el poll (`Controller.php:269-270`). |
| **Preservar campos** | `alegra_connector_import_preserve_fields` (se lee en `Products.php:2249-2253`) | Si incluye `inventory`, el writer devuelve `skipped_preserve` y **no** toca el stock (`Inventory_Writer.php:55-58`; el poll lo respeta en `Products.php:1347`). |
| **Gestión de stock** | `alegra_connector_inventory_manage_stock_enabled` (default `false`, `alegra-connector.php:455`) | En `respect` (default) no habilita productos que no gestionan stock; en `enable` los habilita (`Inventory_Writer.php:73-84`). |
| **Servicios** | — | Un ítem sin objeto `inventory` se trata como servicio: `skipped_service` y `manage_stock=false` (`Inventory_Writer.php:66-71`). |
| **Variables** | — | El **padre variable** nunca maneja stock: `skipped_parent` (`Inventory_Writer.php:60-64`). Las **variaciones** son ítems de Alegra independientes, mapeados a su variación de WC. |

### 4.4 Productos variables y variaciones

El poll no tiene una rama especial para variaciones: resuelve **cualquier** ítem por su vínculo a
Alegra (`Products.php:1320-1328`) y lo escribe. El padre variable se saltea (`skipped_parent`), pero
cada variación (que **no** es `is_type('variable')`) sí se escribe con `wc_update_product_stock()`
(`Inventory_Writer.php:146-157`). **VERIFICADO** (el poll no distingue; el writer sí). El único
"tope" de variantes del archivo es `MAX_ITEM_VARIANTS = 100` (`Products.php:38`), no un tope de
stock.

### 4.5 Stock negativo

Alegra puede devolver un negativo: el writer lo **recorta a 0** y deja un warning
(`Inventory_Writer.php:128-135`), y devuelve el estado `clamped_negative` (`Inventory_Writer.php:104`).
Tanto el poll como la importación cuentan ese caso como "actualizado" y fijan el baseline
(`Products.php:1451,2370`). **VERIFICADO.**

---

## 5. Clientes y Consumidor Final

### 5.1 Cómo un cliente de WC se convierte en contacto de Alegra

Hay dos caminos que comparten la misma lógica de identidad:

1. **Alta/edición de cliente de WC** (si `push_customers_enabled=true`): `Customers::sync_to_alegra()`
   (`Customers.php:30-68`). Si el cliente ya tiene `alegra_contact_id`, lo actualiza (`:37`). Si no,
   busca duplicados por email o identificación (`find_existing_contact()`, `:103-178`) y resuelve el
   conflicto (`resolve_duplicate()`, `:70-101`) según `alegra_connector_conflict_resolution`
   (default `alegra_wins`, `alegra-connector.php:446`): siempre vincula, y luego Alegra pisa a WC o
   WC pisa a Alegra. Si no hay duplicado, crea el contacto (`:56`).
2. **Al facturar un pedido**: `Orders::ensure_customer_synced()` (`Orders.php:1564-1752`). Resuelve en
   este orden: caché (meta del pedido/usuario) → email → identificación (nueva y legacy) → crea el
   contacto (`create_contact_with_2039_retry`, `:1702`) → si todo falla, **Consumidor Final**
   (`:1729-1751`).

### 5.2 El fallback "Consumidor Final"

Es un contacto genérico (`Consumidor Final`, identificación `222222222222`, `Consumidor_Final.php:20-24`)
para ventas sin datos fiscales. La lógica es honesta y defensiva:

- **Nunca escribe desde el render de una página:** hay un `peek_id()` de solo lectura
  (`Consumidor_Final.php:44-67`) y la creación real exige contexto explícito **o**
  `push_customers_enabled` (`Consumidor_Final.php:430-435`).
- **No crea si la búsqueda falla:** no puede distinguir "no existe" de "la API está caída", así que
  no duplica (`Consumidor_Final.php:185-194`). Solo crea cuando la búsqueda **funcionó** y no lo
  encontró (`:214-221`).
- **4 estados de UI:** `probe()` devuelve `available`, `not_found`, `unverified` (nunca dice
  "no encontrado" si la red falló; `Consumidor_Final.php:268-290`), y el estado se persiste para el
  render (`probe_state()`, `:297-321`).
- **Auto-reparación (self-heal):** si el CF quedó viejo, `Orders.php:471-495` invalida la caché,
  vuelve a sondear y lo recrea. Los webhooks `edit-client`/`delete-client` también invalidan la
  caché (`Handlers.php:125-127,148-150`), igual que cambiar el override en Ajustes
  (`Consumidor_Final.php:591-601`).
- **Modo `auto` avisa:** si el fallback fue **no intencional**, deja una **nota en el pedido** con el
  motivo real (rechazo de Alegra vs. datos faltantes) (`Orders.php:1742-1744,1765-1783`).

### 5.3 La compuerta `push_customers_enabled`

`alegra_connector_push_customers_enabled` (default `false`, `alegra-connector.php:426`) es un
interruptor **independiente**: habilita los hooks de cliente (`Public_.php:86-89`) y gobierna la
entidad `contact` en la compuerta de escritura (`Write_Gate.php:41`). En una instalación existente
se siembra desde `push_products_enabled` para no cortar una conducta previa (`Write_Gate.php:204-211`).

---

## 6. Las facturas fallidas (la cola)

### 6.1 Estados y clasificador

Cuando una factura no sube, el plugin la clasifica en **un solo lugar**
(`Invoice_Failure::classify()`, `Invoice_Failure.php:31-99`) y guarda el resultado en 7 metas del
pedido (`Invoice_Failure.php:17-23,108-133`):

| Estado | Cuándo | ¿Se reintenta solo? |
|---|---|---|
| `failed_retriable` | Red/timeout, 429, 5xx, JSON, lock (`:69-74,79-80`). | Sí (con el cron opt-in). |
| `failed_permanent` | 400/401/403/404/409/422 y errores de datos (`:82-84,87-91`). | No: solo manual. |
| `blocked` | Kill switch o entidad deshabilitada (`:38-39`). | No. |
| `payment_missing` | La factura subió pero el pago falló (`Orders.php:637-646`). | No (lo cubre el sweep de pagos). |
| `skipped` | Caso "solo manual" (`:47-55`). | **No se persiste** (no entra a la cola). |
| `resolved` | Éxito (`:58-59`). | — |

Un error desconocido se trata como **retriable** (seguro: el cron tiene tope, `:94`). El dry-run
**no** se persiste (`:35-37`).

### 6.2 La pantalla "Facturas por subir"

- Submenú con **badge** (`Admin_Dashboard.php:239-248`). El badge lee un **conteo cacheado**, no
  ejecuta una query por página (`invoice_queue_menu_title()`, `:263-271`).
- La pantalla (`render_invoice_queue_page()`, `:986-1015`) lista los pedidos con estado en la cola
  **y** los "nunca intentados" (pagados, sin factura y sin ledger). Columnas: Orden, Fecha, Cliente,
  Total, Estado Alegra, Estado, Motivo, Código, Intentos, Último intento, Próximo intento, Acciones
  (`templates/admin-invoice-queue.php:80-92`).
- La query distingue los 4 estados terminales + nunca-intentado (`Invoice_Queue.php:124-153`).

### 6.3 El reintento

- **Manual (single):** `ajax_retry_invoice()` corre bajo `Write_Gate::run_explicit()`
  (`Admin_Dashboard.php:3330-3381`). Una acción manual saltea el bloqueo por entidad deshabilitada,
  pero **nunca** el kill switch (`Write_Gate.php:114-131`).
- **Manual (bulk):** "Reintentar seleccionados" usa `scope=failed` y procesa de a **10 por request**
  (`Admin_Dashboard.php:4425-4470,4472-4548`).
- **Automático:** cron **horario opt-in** (`alegra_connector_invoice_retry_enabled`, default `false`,
  `alegra-connector.php:467`; `Controller.php:118-138`). Solo reintenta `failed_retriable`
  (`Orders.php:973`), con tope de intentos (`alegra_connector_invoice_retry_max_attempts`, default 5,
  `alegra-connector.php:468`). Si el host no tiene cron real, **el botón manual siempre está**.
- **Nunca duplica:** antes de crear, re-busca la factura existente (AC-14, `Orders.php:410-441`) y,
  tras un error, vuelve a buscarla antes de guardar el fallo (`Orders.php:171-184`). La idempotencia
  se apoya en `_alegra_invoice_id`, el lock por pedido y la pre-búsqueda.

### 6.4 Notificación y badge

El conteo y el hash se cachean en opciones (`Invoice_Queue.php:88-89,95`). El aviso es
**option-backed** y se muestra en el admin (`State_Sync.php:66,535-544`); se descarta por usuario
(`Admin_Dashboard.php:3418-3427`). Al llegar a cero, el aviso y el badge desaparecen
(`Invoice_Queue.php:93-96`). **VERIFICADO.**

---

## 7. La reconciliación

### 7.1 El informe de divergencia

- El **poll** registra los productos donde **no pudo** escribir WC (divergencia), con una causa base:
  `baseline_ausente` o `divergencia_dueno` (`Stock_Divergence.php:57-63`), en la opción
  `alegra_connector_stock_divergence` (tope 200, `Stock_Divergence.php:17-18,21-37`).
- El **informe** (`report()`, `Stock_Divergence.php:74-109`) completa la causa por pedido:
  `factura_fallida`, `reembolso` o `factura_pendiente` (`:140-158`).
- Pantalla **"Reconciliación de stock"** (`Admin_Dashboard.php:250-259,1069-1087`).
- El reporte **"Ventas sin factura"** ahora se muestra **siempre** (antes se ocultaba justo en el
  modo del comerciante) y detecta facturas `draft`/`void`/meta vacío (`Admin_Dashboard.php:925-957,971-974`;
  `CHANGELOG.md:16-17`). **Cambio intencional.**

### 7.2 La reparación

Es **explícita** (nunca automática) y corre bajo `Write_Gate::run_explicit()`
(`Admin_Dashboard.php:3197-3323`). Para cada producto emite **un solo** mecanismo: la factura
faltante **o** un ajuste correctivo, **nunca ambos** para el mismo movimiento (REQ-INV-08). Deja
nota y log.

### 7.3 Detección del doble descuento heredado (legacy)

`Stock_Divergence::detect_legacy_double_discount()` (`Stock_Divergence.php:173-231`) busca productos
donde el plugin **emitió un ajuste** (`_alegra_stock_adjusted_at > 0`) **y** existe un pedido pagado
con factura vinculada ⇒ Alegra descontó dos veces. Es **solo lectura**: mide el `availableQuantity`
con `GET /items` solo para los productos detectados y acotado. La reparación
(`push_compensation()`, `Inventory_Pusher.php:375-426`) emite **un `in`** por la cantidad
duplicada y **cambia el dueño a `invoice` antes de compensar** (`Admin_Dashboard.php:3265-3271`).
**VERIFICADO.**

---

## 8. Los webhooks de Alegra

### 8.1 Los 12 eventos

El plugin registra **12 eventos** documentados (`Client.php:852-868`):

| Entidad | Eventos |
|---|---|
| Facturas | `new-invoice`, `edit-invoice`, `delete-invoice` |
| Facturas de compra | `new-bill`, `edit-bill`, `delete-bill` |
| Clientes | `new-client`, `edit-client`, `delete-client` |
| Ítems | `new-item`, `edit-item`, `delete-item` |

### 8.2 Qué hace el plugin con cada uno

`Handlers::process_event()` (`Handlers.php:26-58`):

| Evento | Acción |
|---|---|
| `new-item` / `edit-item` | Sincroniza el ítem desde Alegra (`Handlers.php:31-33,60-75`). **Re-consulta** el ítem por API; no confía en el cuerpo del webhook. |
| `delete-item` | Escribe un **tombstone** para no recrearlo en futuras sincronizaciones (`:36-38,77-114`). |
| `new-client` / `edit-client` | Sincroniza el contacto; si es el Consumidor Final, invalida la caché (`:40-43,116-137`). |
| `delete-client` | Desvincula el `alegra_contact_id` del usuario (`:45-47,139-164`). |
| `new-invoice` / `edit-invoice` | **Re-consulta** la factura por API y solo actúa si está pagada o anulada; puede completar el pedido (`:49-52,166-238`). |
| `delete-invoice`, `new-bill`, `edit-bill`, `delete-bill` | **No manejados**: solo se loguean (`Handlers.php:54-56`). |

> El plugin **nunca confía** en el estado que viene en el webhook: lo re-consulta, porque Alegra no
> firma las entregas (`Handlers.php:173-184`). Además hay **replay protection** de 15 minutos
> (`Receiver.php:124-135,214-223`), un **token compartido** en la URL (`Receiver.php:84-89,240-260`)
> y handshake de cuerpo vacío (`:70-72`).

### 8.3 El re-fetch de `edit-item`

Cuando llega `edit-item`, el handler no usa el cuerpo: llama a
`Products::sync_single_item_by_alegra_id()`, que **hace `GET` del ítem** a Alegra y luego lo importa
(`Handlers.php:60-75`). Es la vía rápida de la dirección Alegra→WC.

### 8.4 El grabador de webhooks (el veredicto SÍ/NO)

El receptor guarda las últimas **50** entregas (cuerpo crudo, máx. 20 KB) en una opción acotada
(`Recorder.php:31-37,55-80`). Sobre eso, `inventory_verdict()` (`Recorder.php:203-223`) responde la
pregunta clave:

- **SÍ** = `edit-item` **trae** `inventory.availableQuantity` → se puede reconciliar por webhook.
- **NO** = hay entregas `edit-item` pero **ninguna** trae inventario → **hay que reconciliar por poll**.
- **NONE** = no hay entregas `edit-item` en la ventana guardada.

---

## 9. La configuración recomendada (para el modelo "todo facturado")

Hay **dos estrategias válidas**. El comerciante elige **una sola**.

### Estrategia A — "La factura es dueña del stock" (recomendada si facturás todo)

Ideal si **Alegra es tu fuente fiscal y querés que la factura descuente stock nativamente**.

| Opción | Valor recomendado |
|---|---|
| `alegra_connector_stock_owner` | **`invoice`** |
| `alegra_connector_push_orders_enabled` | **`true`** (auto-facturación) |
| `alegra_connector_open_invoice_on_paid` | **`true`** (la UI lo fuerza con `invoice`) |
| `alegra_connector_invoice_status` | **`draft`** (el borrador se abre al pagar; así un pedido impago no mueve stock) |
| `alegra_connector_inventory_source` | `alegra` |
| `alegra_connector_inventory_sync_enabled` | `true` |
| `alegra_connector_push_inventory_enabled` | `true` o `false`: en modo factura **no emite ajustes** igual (guard de dueño) |
| `alegra_connector_payment_account_id` | **configurada** (para que se registre el pago) |
| `alegra_connector_invoice_retry_enabled` | `true` (opcional: reintento automático de fallos retriables) |

### Estrategia B — "El plugin es dueño del stock" (ajustes)

Ideal si **no querés que la factura mueva stock** o facturás manualmente.

| Opción | Valor recomendado |
|---|---|
| `alegra_connector_stock_owner` | **`adjustment`** |
| `alegra_connector_push_inventory_enabled` | **`true`** |
| `alegra_connector_push_orders_enabled` | `true` (factura en borrador) o `false` (facturación manual) |
| `alegra_connector_open_invoice_on_paid` | se comporta OFF: la factura queda borrador |
| `alegra_connector_invoice_status` | `draft` |

> **Regla de oro:** "Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces."
> (texto literal de la interfaz, `templates/admin-settings.php:102`).

**Cuándo usar cada una:**
- **A** si facturás **todas** las ventas en Alegra y querés el stock nativo de Alegra.
- **B** si querés controlar el stock desde WooCommerce, o si facturás a mano y no querés que la
  factura mueva existencias.
- **Combinación a evitar:** `stock_owner=invoice` + `push_orders_enabled=false` ⇒ **cero movimiento
  automático** (facturación manual pura). No es doble descuento, pero el stock solo se mueve cuando
  el comerciante factura.

---

## 10. Los límites y las advertencias

### Lo que está verificado

- **Latencia del poll:** el cron es de **15 min** por defecto (5/15/30/60 configurables) y
  **WP-Cron solo corre con tráfico**. En tiendas tranquilas puede tardar. Usá cron real en
  producción. (`alegra-connector.php:418,259-276,610-638`).
- **`edit-item` no verificado:** no se puede saber en el repo si Alegra manda el inventario en
  `edit-item`; el **grabador** te da el veredicto SÍ/NO/NONE (`Recorder.php:203-223`).
- **Apertura desde la UI de Alegra:** abrir la factura o registrar el pago desde la pantalla de
  Alegra **no pasa por el plugin** y no se puede prevenir. El informe de divergencia lo **detecta**
  (`Stock_Divergence.php:173-231`).
- **Sin tope de 6000 ítems:** en 2.7.0 el poll usa `alegra_connector_inventory_poll_max_pages`
  default **0 = sin tope**, con un presupuesto de **60 s** por corrida
  (`alegra-connector.php:457-458`; `Products.php:1236-1243`). El "6000" (200 páginas × 30) es
  histórico de versiones anteriores, no de la 2.7.0. **VERIFICADO que NO existe en el código actual.**
- **El poll no se apaga:** la dirección Alegra→WC (POS, ediciones manuales) es un requisito y se
  mantiene; se le agregó conciencia de dueño (`Products.php:1173-1177` solo la apaga si
  `inventory_source=woocommerce`).

### Lo que el comerciante debe verificar en vivo

| # | Qué verificar | Por qué |
|---|---|---|
| 1 | **¿Una factura borrador mueve stock? (G1)** | Define el modo `adjustment` + apertura manual. `phase0-results.md:12` lo deja `PENDING-LIVE`. **SIN VERIFICAR.** |
| 2 | **Veredicto del grabador de `edit-item`** | Si Alegra manda inventario en el webhook, la reconciliación es casi instantánea; si no, depende del poll. |
| 3 | **Forma del error de stock de Alegra** | No hay un código específico documentado; el clasificador trata todo 4xx (salvo 429) como permanente para no loopear (`Invoice_Failure.php:82-84`). Confirmar si es 400 o 422. **HIPÓTESIS.** |
| 4 | **Que exista cron real** | WP-Cron depende del tráfico. Sin cron real, el poll y el reintento automático se atrasan. |

### Advertencias operativas

- **Nunca abras la factura a mano en modo `adjustment`** sin leer la confirmación: el plugin
  advierte y exige confirmar, pero el doble descuento es posible si confirmás
  (`Admin_Dashboard.php:3135-3143,3467-3475`).
- **`invoice_status='open'` con pedidos impagos** crea facturas que mueven stock antes del pago
  (`templates/admin-settings.php:126-128`).
- **El ledger de fallos es aditivo:** un pedido sin meta simplemente no aparece en la cola; no hay
  migración (`RELEASE_2.7.0_VERIFICATION.md:109-131`).
- **La reparación de divergencia nunca es automática:** siempre es explícita y deja rastro.

---

## Anexo — Mapa rápido de archivos

| Tema | Archivo principal |
|---|---|
| Dueño del stock, ajustes, ledger | `includes/Sync/Inventory_Pusher.php` |
| Escritor único de stock WC | `includes/Sync/Inventory_Writer.php` |
| Poll Alegra→WC e importación | `includes/Sync/Products.php` |
| Facturas, pagos, baseline de factura | `includes/Sync/Orders.php` |
| Clientes y contactos | `includes/Sync/Customers.php` |
| Consumidor Final | `includes/Consumidor_Final.php` |
| Cola de facturas fallidas | `includes/Sync/Invoice_Failure.php`, `includes/Sync/Invoice_Queue.php` |
| Reconciliación / divergencia | `includes/Sync/Stock_Divergence.php` |
| Compuerta de escritura | `includes/Write_Gate.php` |
| Webhooks | `includes/Webhooks/Receiver.php`, `Handlers.php`, `Recorder.php` |
| Pantallas del admin | `admin/Admin/Admin_Dashboard.php` |
| Opciones y cron | `alegra-connector.php` |

*Documento generado leyendo el código de la versión 2.7.0. Las citas `archivo:línea` corresponden a
esa versión.*
