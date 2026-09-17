# Especificación — Subsistema de Inventario

| Campo | Valor |
|---|---|
| Cambio | `inventory` |
| Documento base | `docs/INVENTORY_DESIGN.md` (corregido) |
| Formato | Requerimientos con escenarios Given/When/Then verificables por test |
| Estados | `ACTIVO` · `BLOQUEADO` (espera Fase 0) |

> **Reglas de lectura.** Cada requerimiento tiene al menos un escenario
> verificable. Los requerimientos `BLOQUEADO` **no pueden finalizarse** hasta que
> la verificación de Fase 0 devuelva una respuesta; cada uno declara su **rama
> alternativa**. Los claims de código citan `archivo:línea`; los de Alegra, URL.
>
> **Bloqueos:** R2 (initialQuantity) · R1 (borrador mueve stock) · R-CN (nota
> crédito) · R7 (semántica de bodega ausente) · R4 (warehouse en invoice).

---

## Convenciones

- **WC** = WooCommerce. **Pull** = `sync_inventory_from_alegra()`.
- **Escritor único** = `Inventory_Writer::apply()` (ver REQ-DIV-1).
- **Opt-in** = `alegra_connector_inventory_manage_stock_enabled` (default `false`).
- **Dry-run** = `alegra_connector_inventory_dry_run` (default `true`).
- **Estado pago** = pedido con `get_date_paid()` no nulo o status
  `processing`/`completed`.

---

## A. Fuente de verdad

### REQ-SRC-1 — Modo Alegra-wins: el pull es el único escritor `ACTIVO`

**Cuando** `alegra_connector_inventory_source = 'alegra'`, **el pull es la única
ruta autorizada a escribir `_manage_stock`, `_stock` y `_stock_status` en WC.**
Ninguna otra ruta (import, push, edición manual del plugin) puede escribirlos.

```gherkin
Escenario: El pull escribe el stock de Alegra en WC
  Dado un producto WC con _alegra_item_id=42 y manage_stock habilitado por migración
  Y Alegra reporta para el item 42 availableQuantity=7
  Cuando corre sync_inventory_from_alegra()
  Entonces el producto WC tiene _stock=7
  Y _stock_status='instock'
  Y el resultado del pull reporta updated>=1
```

```gherkin
Escenario: inventory_source=woocommerce desactiva el pull
  Dado alegra_connector_inventory_source='woocommerce'
  Cuando corre sync_inventory_from_alegra()
  Entonces no se emite ninguna llamada GET /items
  Y el resultado tiene skipped=true
  Y ningún _stock de WC cambia
```

**Evidencia de código:** `Products.php:453` (único lector de la opción).

### REQ-SRC-2 — Modo WooCommerce-wins: el plugin no toca stock `ACTIVO`

**Cuando** `inventory_source = 'woocommerce'`, el plugin **no lee** stock de
Alegra ni **escribe** stock en WC. La factura es el único puente.

```gherkin
Escenario: WooCommerce-wins no escribe stock aunque Alegra cambie
  Dado inventory_source='woocommerce'
  Y un producto WC con stock 5 vinculado a Alegra
  Y Alegra reporta availableQuantity=99
  Cuando corre sync_inventory_from_alegra()
  Entonces el producto WC sigue con _stock=5
  Y no se llamó set_manage_stock() sobre ese producto
```

**Evidencia:** `Products.php:453-457`.

---

## B. Compuertas del pull

### REQ-GATE-1 — Kill switch en el pull `ACTIVO` (A1)

El pull **debe** consultar `Kill_Switch::is_active()` **al inicio** y **en cada
página**. Hoy **no lo hace** (`Products.php:447-545`); es una compuerta a
**AGREGAR** (modelo: `Products.php:608,648`).

```gherkin
Escenario: Kill switch activo aborta el pull
  Dado que Kill_Switch::is_active() devuelve true
  Cuando corre sync_inventory_from_alegra()
  Entonces no se emite ninguna llamada GET /items
  Y ningún _stock de WC cambia
  Y se registra un log "inventory sync skipped: kill switch active"
```

```gherkin
Escenario: Kill switch se activa a mitad del pull
  Dado un pull en curso en la página 1
  Cuando Kill_Switch::is_active() pasa a true antes de la página 2
  Entonces el pull se detiene antes de la página 2
  Y no se escribe stock en la página 2
```

**Evidencia:** `Kill_Switch.php:51`; modelo en `Products.php:608,648`.

### REQ-GATE-2 — Lock y cancelación `ACTIVO`

El pull respeta el lock atómico y la cancelación del usuario.

```gherkin
Escenario: Otro sync corriendo bloquea el pull
  Dado que el lock 'products' está tomado
  Cuando corre sync_inventory_from_alegra()
  Entonces el resultado tiene locked=true
  Y no se emite ninguna llamada GET /items
```

```gherkin
Escenario: Cancelación del usuario corta el pull entre páginas
  Dado un pull en curso
  Cuando se setea el transient 'alegra_sync_cancelled'
  Entonces el pull se detiene antes de la próxima página
```

**Evidencia:** `Products.php:459` (lock), `Products.php:471` (cancelación).

### REQ-GATE-3 — La migración opt-in gatea la escritura `ACTIVO`

Con `manage_stock_enabled=false`, el pull **puede leer y reportar** pero **no
escribe** `_manage_stock` ni `_stock`.

```gherkin
Escenario: Sin opt-in el pull no escribe
  Dado alegra_connector_inventory_manage_stock_enabled=false
  Y un producto WC con _manage_stock=no y _stock=12
  Y Alegra reporta availableQuantity=7
  Cuando corre sync_inventory_from_alegra()
  Entonces el producto WC sigue con _manage_stock=no y _stock=12
  Y el resultado reporta el cambio propuesto (dry-run) sin aplicarlo
```

**Evidencia:** sección 6 del diseño.

---

## C. Facturación

### REQ-INV-1 — Abrir la factura borrador al pagar `BLOQUEADO por R1`

**Cuando** `alegra_connector_open_invoice_on_paid=true` **y** el pedido se paga
**y** ya existe `_alegra_invoice_id` en estado `draft`, el plugin **abre** la
factura (`Orders::ensure_invoice_open()`, `Orders.php:322`). El handler **solo
abre borradores existentes: nunca crea facturas.**

**Bloqueo:** el requerimiento asume que "borrador no mueve stock y `open` sí"
(R1). El gating de hooks (R-HOOK) también debe resolverse.

**Rama alternativa (si R1 = "el borrador SÍ mueve stock"):** REQ-INV-1 y REQ-INV-2
se cancelan; la sección 3 del diseño se simplifica y no hace falta abrir nada.

```gherkin
Escenario: Factura borrador existente se abre al pagar (modo manual)
  Dado push_orders_enabled=false
  Y open_invoice_on_paid=true
  Y un pedido con _alegra_invoice_id=555 en estado draft
  Cuando se dispara woocommerce_payment_complete para ese pedido
  Entonces se llama POST /invoices/555/open
  Y el pedido registra una nota de que la factura se abrió
```

```gherkin
Escenario: El handler no crea facturas
  Dado push_orders_enabled=false
  Y un pedido pagado SIN _alegra_invoice_id
  Cuando se dispara woocommerce_payment_complete
  Entonces NO se llama POST /invoices
  Y NO se llama POST /payments
```

**Evidencia:** `Public_.php:49-59` (gating a corregir), `Orders.php:322,670`.

### REQ-INV-2 — Estado de la factura según el pago `BLOQUEADO por R1`

`Orders::prepare_invoice_data()` (`Orders.php:592`) resuelve el estado en este
orden:
1. `$status_override` si se pasó.
2. Si `open_invoice_on_paid=true` y el pedido está pagado → `'open'`.
3. `get_option('alegra_connector_invoice_status', 'draft')`.

```gherkin
Escenario: Pedido ya pagado al crear la factura nace open
  Dado open_invoice_on_paid=true
  Y un pedido con status 'processing' y get_date_paid() no nulo
  Cuando se llama create_invoice(order)
  Entonces el payload POST /invoices tiene status='open'
```

```gherkin
Escenario: Pedido no pagado respeta invoice_status
  Dado open_invoice_on_paid=true
  Y invoice_status='draft'
  Y un pedido en 'pending' sin fecha de pago
  Cuando se llama create_invoice(order)
  Entonces el payload POST /invoices tiene status='draft'
```

```gherkin
Escenario: open_invoice_on_paid=false conserva el comportamiento viejo
  Dado open_invoice_on_paid=false
  Y invoice_status='draft'
  Y un pedido pagado
  Cuando se llama create_invoice(order)
  Entonces el payload POST /invoices tiene status='draft'
```

**Rama alternativa (si R1 = "draft mueve stock"):** REQ-INV-2 se cancela; se
mantiene `Orders.php:614` tal cual.

**Evidencia:** `Orders.php:614`.

### REQ-INV-3 — Guardrail de coherencia `ACTIVO`

Si `inventory_source=alegra` **y** `invoice_status=draft` **y**
`open_invoice_on_paid=false`, el dashboard muestra una advertencia visible. **No
bloquea.**

```gherkin
Escenario: Advertencia de stock fantasma
  Dado inventory_source='alegra'
  Y invoice_status='draft'
  Y open_invoice_on_paid=false
  Cuando se renderiza el dashboard de configuración
  Entonces se muestra un aviso de que el stock de Alegra no reflejará las ventas
```

### REQ-INV-4 — `invoice_status` documenta su nuevo significado `ACTIVO`

La UI (`templates/admin-settings.php:209-214`) describe `invoice_status` como
"estado de la factura mientras el pedido NO está pago".

```gherkin
Escenario: Texto de UI actualizado
  Cuando se renderiza el campo invoice_status
  Entonces el texto de ayuda menciona que aplica mientras el pedido no está pago
```

---

## D. Bodegas

### REQ-WH-1 — Con bodega configurada se lee esa bodega `ACTIVO`

**Cuando** `warehouse_enabled=true` **y** `warehouse_id` es válido (distinto de
`''` y de `'0'`), el pull usa
`inventory.warehouses[id==warehouse_id].availableQuantity`.

```gherkin
Escenario: Se lee la bodega configurada
  Dado warehouse_enabled=true y warehouse_id='3'
  Y el item 42 tiene warehouses=[{id:'1',availableQuantity:'20'},{id:'3',availableQuantity:'150'}]
  Y inventory.availableQuantity=170
  Cuando el pull procesa el item 42
  Entonces el producto WC recibe _stock=150
```

```gherkin
Escenario: availableQuantity de bodega es string y se convierte
  Dado warehouses=[{id:'3',availableQuantity:'150'}]
  Cuando el pull procesa el item
  Entonces _stock=150 (entero)
```

**Evidencia:** doc https://developer.alegra.com/reference/get_items (tipo string
en `warehouses[]`); `Products.php:510,524` hoy ignora bodegas.

### REQ-WH-2 — Bodega ausente del item: SKIP + WARN `BLOQUEADO por R7`

**Cuando** la bodega configurada **no está** en `inventory.warehouses[]`, el pull
**no escribe** y reporta WARN. **Nunca escribe 0.**

**Rama alternativa (si R7 = "ausente significa cero"):** se puede escribir 0, pero
se recomienda **igual** SKIP+WARN por reversibilidad. La rama por defecto no
cambia.

```gherkin
Escenario: Bodega configurada ausente no escribe 0
  Dado warehouse_enabled=true y warehouse_id='3'
  Y el item tiene warehouses=[{id:'1',availableQuantity:'20'}]
  Y el producto WC tiene _stock=5
  Cuando el pull procesa el item
  Entonces el producto WC sigue con _stock=5
  Y el resultado reporta skipped_warehouse_missing
```

### REQ-WH-3 — Bodegas activadas sin bodega elegida (C6) `BLOQUEADO por R7`

**Cuando** `warehouse_enabled=true` **y** (`warehouse_id === ''` **o**
`warehouse_id === '0'`), el pull trata el caso como **"sin bodega"** (usa el
total) y muestra una advertencia. Hoy `resolve_warehouse_id()` (`Products.php:418`)
devuelve `'0'` y el código lo trata como bodega real.

```gherkin
Escenario: warehouse_id '0' se normaliza a vacío
  Dado warehouse_enabled=true y warehouse_id='0'
  Y el item tiene inventory.availableQuantity=170
  Cuando el pull procesa el item
  Entonces el producto WC recibe _stock=170
  Y se registra una advertencia de bodega sin seleccionar
```

```gherkin
Escenario: warehouse_id vacío con bodegas activadas usa el total
  Dado warehouse_enabled=true y warehouse_id=''
  Cuando el pull procesa un item con availableQuantity=9
  Entonces el producto WC recibe _stock=9
```

**Evidencia:** `admin-settings.php:164` (`value="0"`), `Products.php:418-424`.

### REQ-WH-4 — El pull lee la misma bodega que el push escribe `ACTIVO`

La bodega que `apply_warehouse()` (`Products.php:432`) usa al empujar debe ser la
misma que el pull lee.

```gherkin
Escenario: Ida y vuelta coherente de bodega
  Dado warehouse_enabled=true y warehouse_id='3'
  Cuando se empuja un producto a Alegra
  Entonces el payload PUT /items usa warehouses[0].id='3'
  Y cuando se hace el pull de ese item, se lee warehouses[id='3']
```

---

## E. Tipos de producto

### REQ-TYPE-1 — Producto simple `ACTIVO`

```gherkin
Escenario: Simple inventariable recibe manage/stock/status
  Dado un producto simple con _alegra_item_id=42
  Y el item 42 tiene inventory.availableQuantity=7
  Cuando el pull procesa el item
  Entonces _manage_stock='yes'
  Y _stock=7
  Y _stock_status='instock'
```

### REQ-TYPE-2 — Producto variable (padre) `ACTIVO`

El padre **nunca** recibe `_stock`; el stock vive en las variaciones.

```gherkin
Escenario: El padre variable no recibe _stock
  Dado un producto variable con _alegra_item_id=10 (variantParent)
  Cuando el pull procesa el item 10
  Entonces el padre tiene _manage_stock='no'
  Y el padre NO tiene _stock escrito por el pull
  Y el padre NO recibe set_stock_quantity()
```

```gherkin
Escenario: Variaciones del padre sí reciben stock
  Dado el variantParent 10 con variaciones 11 y 12 inventariables
  Cuando el pull procesa los items 11 y 12
  Entonces cada variación recibe _manage_stock='yes', _stock y _stock_status
```

### REQ-TYPE-3 — Variación `ACTIVO`

```gherkin
Escenario: La variación se escribe individualmente
  Dado un producto_variation con _alegra_item_id=11
  Y el item 11 tiene inventory.availableQuantity=0
  Cuando el pull procesa el item 11
  Entonces la variación tiene _stock=0 y _stock_status='outofstock'
```

### REQ-TYPE-4 — Servicio `ACTIVO`

Un item sin objeto `inventory` es un servicio: `_manage_stock='no'`, sin cantidad
ni estado.

```gherkin
Escenario: Servicio no es inventariable
  Dado un item de Alegra SIN el objeto inventory
  Cuando el pull procesa el item
  Entonces el producto WC tiene _manage_stock='no'
  Y NO se escribe _stock ni _stock_status
  Y el resultado reporta skipped_service
```

**Evidencia:** doc https://developer.alegra.com/reference/get_items ("si no lo
está se asume como servicio").

### REQ-TYPE-5 — Variable con variaciones mixtas (C2) `ACTIVO`

Si **solo algunas** variaciones son inventariables, el padre va `manage_stock=no`,
las variaciones servicio no se tocan, y se reporta WARN.

```gherkin
Escenario: Padre variable con hijos mixtos
  Dado un producto variable con variaciones 11 (inventariable) y 12 (servicio)
  Cuando el pull procesa el padre y sus hijos
  Entonces el padre tiene _manage_stock='no'
  Y la variación 11 recibe stock
  Y la variación 12 NO recibe stock
  Y se reporta un WARN de configuración mixta
```

### REQ-TYPE-6 — Servicios y variables no reciben negativos ni basura `ACTIVO`

Ver REQ-FAIL-2 y REQ-FAIL-3; aplican a cualquier entidad que gestione stock.

---

## F. Migración

### REQ-MIG-1 — Dry-run obligatorio antes de escribir `ACTIVO`

Con `inventory_dry_run=true`, el pull genera un **informe de diferencias** por
producto y **no escribe**.

```gherkin
Escenario: Dry-run reporta sin escribir
  Dado inventory_dry_run=true
  Y un producto WC con _manage_stock='no' y _stock=12
  Y Alegra reporta availableQuantity=7
  Cuando corre el pull
  Entonces el producto WC sigue con _manage_stock='no' y _stock=12
  Y el informe incluye una fila "Camiseta: no/12 → yes/7/instock"
```

### REQ-MIG-2 — Opt-out por producto (C1) `ACTIVO`

Un producto con `_alegra_manage_stock_opt_out='yes'` **nunca** recibe
`_manage_stock` ni `_stock`.

```gherkin
Escenario: Producto excluido no se toca
  Dado un producto WC con _alegra_manage_stock_opt_out='yes'
  Y Alegra reporta availableQuantity=7
  Cuando corre el pull
  Entonces el producto WC no cambia _manage_stock ni _stock
  Y el resultado reporta skipped_opt_out
```

### REQ-MIG-3 — Advertencia de sobrescritura `ACTIVO`

Si un producto ya tiene `_manage_stock='yes'` con stock distinto de 0 y el valor
de Alegra difiere, el pull **no sobrescribe en la primera corrida real**: marca
WARN y requiere confirmación explícita.

```gherkin
Escenario: Posible pisada de dato real
  Dado un producto WC con _manage_stock='yes' y _stock=12
  Y Alegra reporta availableQuantity=7
  Cuando corre el pull real
  Entonces el resultado incluye un WARN de sobrescritura para ese producto
```

---

## G. Prevención de divergencia

### REQ-DIV-1 — Un solo escritor `ACTIVO`

Existe exactamente **una** ruta de código autorizada a escribir
`_manage_stock`/`_stock`/`_stock_status`: `Inventory_Writer::apply()`
(`includes/Sync/Inventory_Writer.php`).

```gherkin
Escenario: Grep de escritores únicos
  Cuando se busca set_manage_stock|set_stock_quantity|set_stock_status en producción
  Entonces todos los matches están en includes/Sync/Inventory_Writer.php
```

### REQ-DIV-2 — El import no escribe stock directamente `ACTIVO`

`update_product_from_alegra()` (`Products.php:1152`) deja de escribir stock en
`Products.php:1203-1209` y delega en `Inventory_Writer::apply()`.

```gherkin
Escenario: Importar un producto nuevo usa el escritor único
  Dado un item de Alegra con inventory.availableQuantity=7
  Y no existe el producto en WC
  Cuando import_from_alegra() lo crea
  Entonces el stock se escribió vía Inventory_Writer::apply()
  Y no existe una segunda ruta de escritura en el import
```

### REQ-DIV-3 — El push no reenvía `inventory` en updates (R2/R3) `RESUELTO (R3)`

**Cuando** se hace `PUT /items` (update), el payload **no incluye el objeto
`inventory` en absoluto** (ni `initialQuantity`, ni un `{unit}` parcial). La doc
marca `unit`, `unitCost` e `initialQuantity` como obligatorios *cuando el objeto
está presente*, así que un objeto parcial es indocumentado; y `PUT /items/{id}`
es una actualización parcial (*"Solo enviar los campos que cambiarán"*,
https://developer.alegra.com/reference/items__updateitem). **Cuando** se hace
`POST /items` (create), sí se envía `inventory: {unit, initialQuantity}`.

**Cambio de diseño (R3):** el stock no se cambia por `PUT /items`; el camino
documentado es `POST /inventory-adjustments`. Cablearlo queda para Fase 5.

```gherkin
Escenario: Update no envía inventory
  Dado un producto WC con _alegra_item_id=42
  Cuando se llama update_item('42', payload)
  Entonces payload no tiene la clave 'inventory'
```

```gherkin
Escenario: Create sí puede enviar initialQuantity
  Dado un producto WC sin _alegra_item_id
  Cuando se llama create_item(payload)
  Entonces payload['inventory'] puede tener 'initialQuantity'
```

**Evidencia:** `Products.php:282,361,440` y `Products.php:128,141,196,230`.

### REQ-DIV-4 — Informe de divergencia en modo manual (C-path 3.4) `BLOQUEADO por R1`

**Cuando** `push_orders_enabled=false` **y** existen pedidos vendidos
(`processing`/`completed`) sin `_alegra_invoice_id`, el sistema muestra un
**informe de "pedidos vendidos pero no facturados"** y advierte que el stock de
Alegra no refleja esas ventas.

**Bloqueo:** la premisa "el pull re-inflaría" depende de R1. Si R1 = "el borrador
sí mueve stock", cambia el análisis pero el informe sigue siendo útil.

```gherkin
Escenario: Se detectan pedidos vendidos sin facturar
  Dado push_orders_enabled=false
  Y 3 pedidos en 'processing' sin _alegra_invoice_id
  Cuando se abre el dashboard de inventario
  Entonces el informe lista esos 3 pedidos
  Y muestra una advertencia de divergencia de stock
```

### REQ-DIV-5 — El push no envía inventario si el producto no gestiona stock (C4) `ACTIVO`

**Cuando** un producto WC tiene `_manage_stock=false` (o opt-out), el push **no
envía** el bloque `inventory` al crear/actualizar en Alegra.

```gherkin
Escenario: Producto sin gestión no crea inventario en Alegra
  Dado un producto WC con _manage_stock=false
  Cuando se empuja a Alegra
  Entonces el payload no incluye el objeto inventory
```

---

## H. Caminos de fallo

### REQ-FAIL-1 — `availableQuantity` ausente/null: SKIP + WARN `ACTIVO`

```gherkin
Escenario: Cantidad ausente no se convierte en 0
  Dado un item con inventory presente pero SIN availableQuantity
  Y un producto WC con _stock=5
  Cuando el pull procesa el item
  Entonces el producto WC sigue con _stock=5
  Y el resultado reporta skipped_no_qty
  Y NO se llama set_stock_quantity(0)
```

### REQ-FAIL-2 — `availableQuantity` negativo: clamp a 0 + WARN (C5) `ACTIVO`

```gherkin
Escenario: Negativo se clampea
  Dado un item con inventory.availableQuantity=-3
  Cuando el pull procesa el item
  Entonces el producto WC recibe _stock=0
  Y _stock_status='outofstock'
  Y el resultado incluye un WARN con el valor original -3
```

### REQ-FAIL-3 — Formato no numérico: SKIP + WARN `ACTIVO`

```gherkin
Escenario: Valor basura no se castea a 0
  Dado un item con inventory.availableQuantity='N/A'
  Y un producto WC con _stock=5
  Cuando el pull procesa el item
  Entonces el producto WC sigue con _stock=5
  Y el resultado reporta skipped_no_qty
```

### REQ-FAIL-4 — Error de API a mitad del pull no pierde progreso (R-PULL) `ACTIVO`

```gherkin
Escenario: Error de API guarda cursor y sale
  Dado un pull que ya procesó 2 páginas
  Cuando GET /items de la página 3 devuelve WP_Error
  Entonces el pull termina sin lanzar excepción
  Y el cursor queda en el offset de la página 2
  Y la próxima corrida reanuda desde ese offset
```

### REQ-FAIL-5 — Pull reanudable e idempotente (R-PULL) `ACTIVO`

```gherkin
Escenario: Reanudación no reprocesa todo
  Dado que un pull anterior quedó pausado en el offset 60
  Cuando corre el pull de nuevo
  Entonces la primera llamada GET /items usa start=60
```

```gherkin
Escenario: Corrida idempotente
  Dado un pull completo exitoso
  Cuando corre el mismo pull de nuevo sin cambios en Alegra
  Entonces los valores de _stock de WC no cambian
```

### REQ-FAIL-6 — Truncación reportada (R-PULL) `ACTIVO`

```gherkin
Escenario: Tope de páginas se reporta
  Dado un catálogo de más de 6000 items
  Cuando corre el pull
  Entonces el resultado indica que quedó pendiente (truncated=true)
  Y el cursor permite continuar en la próxima corrida
```

---

## I. Ciclo de vida de items

### REQ-DEL-1 — Producto borrado en Alegra (C3) `ACTIVO`

El sistema detecta items vinculados que ya no existen en Alegra (vía webhook
`delete-item`, `Handlers.php:76`, o por ausencia en el pull) y los reporta. **No
borra stock por defecto.**

```gherkin
Escenario: Webhook delete-item marca el producto
  Dado un producto WC con _alegra_item_id=42
  Cuando llega el webhook delete-item para el id 42
  Entonces se crea un tombstone (Tombstone_Manager)
  Y el producto queda reportado como huérfano
  Y su _stock NO se modifica
```

### REQ-WC-1 — Producto creado en WC nunca en Alegra (C4) `ACTIVO`

El pull **no lo visita** (no está en Alegra); el push no debe crear un item de
Alegra con inventario basura.

```gherkin
Escenario: Producto solo-WC no genera inventario falso
  Dado un producto WC sin _alegra_item_id y con _manage_stock=false
  Cuando corre el pull
  Entonces el producto no se modifica
  Y cuando se empuja a Alegra, no se envía el objeto inventory
```

---

## J. Notas de crédito

### REQ-CN-1 — Reembolso restaura stock o se reporta que no `BLOQUEADO por R-CN`

**Cuando** se crea una nota crédito por un reembolso, el stock en Alegra debe
restaurarse **o** el sistema debe reportar explícitamente que no lo hace. El
payload actual **no envía `status`** (`Orders.php:485-493`) y la doc de
`POST /credit-notes` **tampoco documenta un `status`**
(https://developer.alegra.com/reference/post_credit-notes). El reembolso parcial
usa una línea **sin `id`** de item (`Orders.php:461-470`).

**Rama A (R-CN = "la nota crédito SÍ restaura stock"):** no se cambia el payload;
se agrega un test de regresión.

**Rama B (R-CN = "NO restaura, sobre todo la línea sin id"):** se debe (i)
reportar el fallo al comerciante, y (ii) evaluar enviar `warehouse`/`status` o un
`POST /inventory-adjustments` de compensación (`Client.php:648`), con su propio
lock e idempotencia.

```gherkin
Escenario: Reembolso total restaura stock
  Dado una factura abierta por 3 unidades (stock 10→7)
  Cuando se crea una nota crédito total reusando los items originales
  Entonces el stock del item vuelve a 10
```

```gherkin
Escenario: Reembolso parcial con línea sin id
  Dado una factura abierta por 3 unidades (stock 10→7)
  Cuando se crea una nota crédito parcial con una línea sin id
  Entonces el sistema reporta si el stock subió o no
```

---

## Matriz de trazabilidad (requerimiento → diseño → tarea)

| Requerimiento | Estado | Sección diseño | Tarea |
|---|---|---|---|
| REQ-SRC-1 | ACTIVO | 5.7, 5.9.2 | T1.2, T2.1 |
| REQ-SRC-2 | ACTIVO | 5.7 | T1.2 |
| REQ-GATE-1 | ACTIVO | 5.6, 5.9.2 | T1.3 |
| REQ-GATE-2 | ACTIVO | 5.6 | T1.3 |
| REQ-GATE-3 | ACTIVO | 6.2 | T5.1 |
| REQ-INV-1 | BLOQUEADO (R1) | 5.1.1, 5.9.5 | T3.2 |
| REQ-INV-2 | BLOQUEADO (R1) | 5.1.1, 5.9.5 | T3.1 |
| REQ-INV-3 | ACTIVO | 5.1 | T3.3 |
| REQ-INV-4 | ACTIVO | 5.9.5 | T3.3 |
| REQ-WH-1 | ACTIVO | 5.4 | T1.4 |
| REQ-WH-2 | BLOQUEADO (R7) | 5.4 | T1.4 |
| REQ-WH-3 | BLOQUEADO (R7) | 4.6 C6 | T8.3 |
| REQ-WH-4 | ACTIVO | 5.4 | T1.4 |
| REQ-TYPE-1..6 | ACTIVO | 4.2, 5.3 | T1.2, T8.2 |
| REQ-MIG-1 | ACTIVO | 6.2 | T5.1 |
| REQ-MIG-2 | ACTIVO | 4.6 C1, 6.2 | T8.1 |
| REQ-MIG-3 | ACTIVO | 6.2 | T5.2 |
| REQ-DIV-1 | ACTIVO | 5.7, 5.9.1 | T1.1 |
| REQ-DIV-2 | ACTIVO | 5.9.3 | T2.2 |
| REQ-DIV-3 | RESUELTO (R3) | 5.9.4 | T4.1 (hotfix) |
| REQ-DIV-4 | BLOQUEADO (R1) | 3.4 | T9.1 |
| REQ-DIV-5 | ACTIVO | 4.6 C4 | T8.4 |
| REQ-FAIL-1..3 | ACTIVO | 5.5 | T1.2 |
| REQ-FAIL-4..6 | ACTIVO | 5.9.2 | T7.1 |
| REQ-DEL-1 | ACTIVO | 4.6 C3 | T8.5 |
| REQ-WC-1 | ACTIVO | 4.6 C4 | T8.4 |
| REQ-CN-1 | BLOQUEADO (R-CN) | 7 R-CN | T10.1 |
