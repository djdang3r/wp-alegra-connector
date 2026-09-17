# Diseño del Subsistema de Inventario — Alegra Connector

| Campo | Valor |
|---|---|
| Documento | Diseño (no implementación) |
| Plugin | Alegra Connector para WooCommerce |
| Versión analizada | 2.3.3 (`alegra-connector.php:6`) |
| Fecha | 2026-09-17 |
| Estado | Propuesta para decisión — **corregido tras revisión adversarial** |
| Audiencia | Dueño de tienda (secciones 1 y 3) · Desarrollador (secciones 4 a 9) |

> **Este documento NO modifica código de producción.** Es un diseño. Las líneas
> citadas (`archivo:línea`) corresponden a la versión 2.3.3 y sirven para ubicar
> cada afirmación. Lo que no pude verificar contra la cuenta real o contra la
> documentación oficial está marcado **SIN VERIFICAR**.
>
> **Registro de correcciones (revisión adversarial).** Se corrigieron errores
> factuales y se agregaron los huecos detectados: (A1) el kill switch **no** está
> en la copia de inventario; (A2) el riesgo #1 real es `initialQuantity` en cada
> `PUT /items` (R2), no el borrador; se agregaron los riesgos de notas crédito,
> gating de hooks y escala de la copia; (A3) se modela el camino por defecto (modo
> manual, sin factura); (A4) se agregaron 7 celdas de configuración faltantes;
> (A5) se especifica el **DÓNDE** (método/archivo/hook/opción/llamadas WC); (A6) se
> resuelve la contradicción D1. Ver sección 9 para el detalle.

---

## 1. Resumen ejecutivo

### El problema, en criollo

El plugin tiene un interruptor en el dashboard que dice **"Fuente de inventario:
Alegra (recomendado)"**. La idea es simple y es la que el comerciante espera:
*Alegra manda; el stock de Alegra se copia a WooCommerce, y WooCommerce nunca
sobreescribe a Alegra.*

Ese interruptor **no hace absolutamente nada**. La función que debería copiar el
stock desde Alegra hacia la tienda existe, pero **nadie la llama**. Y aunque
alguien la llamara, hay un segundo problema: WooCommerce **ignora por completo**
el campo de cantidad (`_stock`) si el producto no tiene activada la casilla
"Gestionar inventario". Esa casilla **nunca se activa** en el plugin. Resultado:
el stock que el plugin escribe cae en un cajón que WooCommerce no mira.

### La causa raíz

1. **`_manage_stock` nunca se escribe.** El plugin lee `get_manage_stock()` en
   dos lugares (`Products.php:525` y `Products.php:1203`) pero **nunca** activa
   la gestión de stock. WooCommerce, con `_manage_stock = no`, ignora `_stock`
   y muestra todos los productos como disponibles.
2. **`_stock_status` nunca se escribe.** WooCommerce **no** recalcula el estado
   ("disponible" / "agotado") solo al cambiar la cantidad: hay que setearlo
   explícitamente. El plugin nunca lo hace. Aun con `_manage_stock = yes`, un
   producto con 0 unidades seguiría vendiéndose como disponible.
3. **La función de copia está muerta.** `sync_inventory_from_alegra()`
   (`Products.php:447`) no tiene ningún llamador productivo: solo la usa un
   script de pruebas (`scripts/exec-test.php:967,1184`). El cron no la llama. No
   hay botón en el dashboard. La acción `alegra_sync_inventory_from_alegra` que
   el propio manual de despliegue documenta (`docs/RELEASE_2.1.9_DEPLOY.md:359`)
   **no está registrada en el plugin**.

### La solución propuesta

Tres movimientos, en orden:

1. **Activar de verdad la gestión de stock** (`_manage_stock`) y setear el estado
   (`_stock_status`) cada vez que se escribe una cantidad. Sin esto, cualquier
   otra cosa es decorativa.
2. **Conectar la copia de stock** (cron + botón + acción), respetando el
   interruptor de "Fuente de inventario" que ya existe.
3. **Resolver la tensión factura-borrador vs. stock** (sección 3): una factura
   en **borrador NO mueve stock en Alegra**. Si la fuente es Alegra y las
   facturas se quedan en borrador, el stock de Alegra nunca baja, y copiarlo a
   WooCommerce **re-infla las unidades ya vendidas**. La recomendación es que la
   factura **siga el ciclo de vida del pedido**: nace borrador si el pedido aún
   no está pago, y se abre automáticamente cuando el pedido se paga. Así el modo
   manual se mantiene (el comerciante decide *cuándo* facturar), pero el stock de
   Alegra queda veraz.

**Lo que el comerciante debe saber:** hoy el inventario **no funciona en ninguna
dirección**. No es que funcione mal: no funciona. Y si lo activáramos a lo bruto
sin los pasos 1 y 2, podríamos sobrescribir el stock real de la tienda con
valores de Alegra. Por eso la migración es **opt-in** y con **simulación previa**
(sección 6).

### Lo más urgente NO es el diseño: es un bug ya en producción

El riesgo #1 (sección 7, **R2**) **no es una hipótesis de diseño**: el push
reenvía `inventory.initialQuantity` en **cada** `PUT /items`
(`Products.php:282,361,440`), y ese camino **ya está publicado y corre en cada
edición de producto**. Si `initialQuantity` resetea el stock actual, **cada
edición de un producto en WooCommerce corrompe el stock de Alegra hoy**. Eso es
una corrupción activa, no un escenario futuro. Antes de tocar una línea del
diseño hay que verificar R2 (sección 7).

---

## 2. Estado actual (el diagnóstico)

### 2.1 Las tres causas raíz

| # | Causa | Evidencia | Efecto |
|---|---|---|---|
| 1 | `_manage_stock` nunca se escribe | Solo hay **lecturas**: `Products.php:525`, `Products.php:1203`. No existe `set_manage_stock()` ni escritura de `_manage_stock` en producción. | WooCommerce ignora `_stock` y `_stock_status`; todos los productos se ven disponibles. |
| 2 | `_stock_status` nunca se escribe | **0 coincidencias** de `_stock_status` / `set_stock_status` en producción. | Aun con `_manage_stock = yes`, un producto con 0 se vende como disponible (riesgo de sobreventa). |
| 3 | `sync_inventory_from_alegra()` está muerta | Definida en `Products.php:447`; únicos llamadores: `scripts/exec-test.php:967,1184`. | El interruptor "Fuente de inventario" no tiene efecto real. |

### 2.2 El interruptor que no hace nada

- Opción: `alegra_connector_inventory_source`, default `alegra`
  (`alegra-connector.php:384`).
- Se registra en `Admin_Dashboard.php:340`.
- Se dibuja en `templates/admin-settings.php:93`.
- **Único lector productivo:** `Products.php:453`, dentro de
  `sync_inventory_from_alegra()` — la función muerta.
- La **ruta de importación** (`import_from_alegra()`, `Products.php:605`) **no
  consulta** `inventory_source`. Escribe stock en `Products.php:1203-1205` sin
  preguntar de dónde viene el inventario.

### 2.3 WooCommerce ignora el stock sin `_manage_stock`

En WooCommerce, un producto solo descuenta stock si `_manage_stock = yes`. El
plugin nunca lo activa. Por lo tanto:

- `$product->set_stock_quantity($n); $product->save();` guarda un número que
  WooCommerce **no usa** para decidir disponibilidad.
- `$product->get_manage_stock()` devuelve `false`, y ambas rutas del plugin
  (`Products.php:525` y `Products.php:1203`) **hacen `continue`/saltan** la
  escritura. Es un círculo cerrado: como nunca se activa, nunca se escribe.

Además, verificado en el core de WooCommerce: `set_stock_quantity()` + `save()`
**NO** recalcula `_stock_status`. El estado debe setearse explícitamente. Esto es
clave para la sección 5.

### 2.4 Lo que la UI promete vs. lo que pasa

**Texto real del dashboard** (`templates/admin-settings.php:93`):

> *"Con 'Alegra', la sincronización periódica importa el stock desde Alegra y
> sobrescribe el de WooCommerce. Con 'WooCommerce', el plugin NO toca el stock de
> WooCommerce (solo gestionas inventario en tu tienda)."*

**Realidad:** la "sincronización periódica" nunca llama a
`sync_inventory_from_alegra()`. El cron (`Controller::run_cron_sync()`) llama a
`import_from_alegra()` (`Controller.php:120`), que intenta escribir stock solo si
`get_manage_stock()` es `true` (`Products.php:1203`) — y nunca lo es. **No se
sobrescribe nada, ni se importa nada.** La promesa del texto es exactamente lo
contrario de lo que ocurre.

**Texto real del dashboard** (`templates/admin-settings.php:209-214`):

> *"Estado de las facturas: Borrador (recomendado) ... Las facturas se crean en
> Alegra como borrador para que puedas revisarlas antes de emitirlas."*

**Realidad:** correcto, y es la raíz de la tensión de la sección 3. El default
`draft` (`alegra-connector.php:386`, `Orders.php:614`) es sensato para revisión
manual, pero es **incompatible** con "Alegra como fuente de stock" sin el ajuste
de la sección 5.

**Texto real del dashboard** (`templates/admin-settings.php:94-96`):

> *"Subir pedidos a Alegra ... Por defecto está DESACTIVADO (modo manual)."*

**Realidad:** correcto. Default `false` (`alegra-connector.php:372`). El modo
manual es el default de fábrica, tal como el usuario lo pidió. **Pero esto tiene
una consecuencia grave que este documento no modelaba: sección 3.4.**

### 2.5 Inventario por bodegas: parcialmente cableado

- La configuración de bodegas existe: `alegra_connector_warehouse_enabled`,
  `alegra_connector_warehouse_id` (`templates/admin-settings.php:156-173`).
- El **push** de productos respeta la bodega: `resolve_warehouse_id()`
  (`Products.php:418`) y `apply_warehouse()` (`Products.php:432`).
- La **factura** respeta la bodega: `Orders.php:642-645` envía
  `$data['warehouse']` — campo **NO documentado** en el esquema de
  `POST /invoices` (ver Riesgo 4). **Nota:** el esquema de `POST /credit-notes`
  **sí** documenta `warehouse`
  (https://developer.alegra.com/reference/post_credit-notes), lo que hace más
  llamativa su ausencia en el de facturas.
- La **copia (pull)** **no lee bodegas en absoluto**: `sync_inventory_from_alegra()`
  solo usa `inventory.availableQuantity` (`Products.php:510,524`). Es decir, si
  el comerciante configura una bodega, el pull ignora esa configuración y trae el
  total multi-bodega.

### 2.6 Lo que la documentación del repo dice (y ya no es cierto)

`docs/DOCUMENTACION.md:662` y `:680` afirman que se eliminó
`sync_inventory_to_alegra()` porque `PUT /items` con `availableQuantity` "no está
soportado por la API de Alegra", y que *"el inventario ahora se descuenta/
restaura automáticamente al crear facturas/notas crédito con items vinculados por
ID"*. La primera parte es correcta. La segunda **depende de que la factura esté
abierta** (sección 3). La doc asume un comportamiento que el default `draft`
contradice. **SIN VERIFICAR** en vivo (Riesgo 1). Para notas crédito hay un
agravante: el payload del plugin **no envía `status`** (ver R-CN, sección 7), y
la doc de `POST /credit-notes` **tampoco documenta un campo `status`**, así que
la premisa "la nota crédito restaura stock" es aún más débil que en facturas.

### 2.7 El kill switch NO cubre la copia de inventario

`import_from_alegra()` sí consulta el kill switch, incluso en medio del bucle
(`Products.php:608` y `Products.php:648`). La factura y el polling también
(`Orders.php:1237`, `Orders.php:1281`). **La copia de inventario NO**
(`Products.php:447-545`): no hay ninguna llamada a `Kill_Switch::is_active()`.
Por lo tanto la compuerta "Kill switch" del diseño original **es una compuerta a
AGREGAR**, no una que ya exista. Ver sección 5.6.

---

## 3. La tensión central (borrador vs. stock)

### 3.1 El escenario, con números

Stock inicial en Alegra: **10 unidades** de "Camiseta". Se venden **3** en
WooCommerce.

**Configuración del comerciante:** `inventory_source = alegra` (default),
`invoice_status = draft` (default), facturación manual (default).

| Paso | Alegra | WooCommerce | ¿Qué pasó? |
|---|---|---|---|
| Inicio | 10 | 10 | Estado sano. |
| Venta de 3 en WC | 10 | 10 | La factura se crea **en borrador**. |
| **La factura borrador NO mueve stock** | **10** | **10** | Alegra sigue creyendo que hay 10. |
| El cron copia Alegra → WC | 10 | **10** | **El stock vendido se re-infla.** |
| Otro cliente compra 4 | 10 | 10 | WC cree que hay 10, pero físicamente hay 7. |
| **Resultado** | 10 | 6 (tras la 2.ª venta) | **Sobreventa / stock fantasma.** |

**Las 3 unidades ya vendidas reaparecen** en WooCommerce cada vez que corre la
copia. El comerciante vende mercancía que ya no tiene. Eso es exactamente el
"stock fantasma" que produce sobreventa y clientes enojados.

### 3.2 Por qué pasa (en una frase)

En Alegra, **solo la factura abierta (`open`) mueve el stock**; la factura en
**borrador (`draft`) no mueve nada**. La API lo documenta al describir el campo
`status` de `POST /invoices`
(https://developer.alegra.com/reference/post_invoices):

> *"Estado de la factura, las opciones posibles son: open o draft. Si no se envía
> este atributo y no se envían pagos asociados la factura se crea en 'draft'. Si
> se envían pagos a la factura, la factura queda creada en 'open'."*

La documentación **no dice explícitamente** que el borrador no mueva stock; es un
comportamiento de la aplicación que **hay que verificar en vivo** (Riesgo 1). Si
resultara que el borrador **sí** mueve stock, toda esta sección se simplifica
enormemente — pero el diseño **no puede asumirlo**.

### 3.3 El círculo vicioso

```
   Facturación MANUAL (default)
            │
            ▼
   Facturas quedan en BORRADOR
            │
            ▼
   Alegra NO descuenta stock
            │
            ▼
   Fuente de stock = ALEGRA (default)
            │
            ▼
   La copia re-infla lo vendido  ──►  SOBREVENTA
```

Los tres defaults del plugin (facturación manual + factura borrador + fuente
Alegra) **combinan para romper el inventario**. Cualquiera de los tres, cambiado,
rompe el círculo. El diseño debe elegir cuál cambiar (sección 5.1).

### 3.4 El camino por defecto real: pedido vendido, NUNCA facturado

**Este es el hueco más grande del diseño original.** El documento modelaba
solamente "existe una factura borrador". Pero el default de fábrica es
`alegra_connector_push_orders_enabled = false` (`alegra-connector.php:372`): en
**modo manual no se crea factura alguna**. No hay borrador que abrir, ni al
pagar ni al completar. El resultado es peor que el de la sección 3.1:

| Paso | Alegra | WooCommerce | ¿Qué pasó? |
|---|---|---|---|
| Inicio | 10 | 10 | Sano. |
| Venta de 3 (modo manual) | 10 | 7 | WC **sí** descuenta stock al vender. |
| El comerciante **nunca** presiona "Facturar" | 10 | 7 | **No existe factura en Alegra.** |
| Corre la copia (Alegra → WC) | 10 | **10** | El pull **re-infla** las 3 vendidas. |
| Estado real | 10 (Alegra) vs 7 (físico) vs 10 (WC) | | **Divergencia triple, sin detección.** |

Consecuencias que el diseño debe cubrir:

1. **Divergencia silenciosa.** WooCommerce vendió, descontó su propio stock y
   Alegra nunca se enteró. La copia activamente **empeora** el estado: sube el
   stock de WC al valor (desactualizado) de Alegra.
2. **No hay detección.** Nada avisa que hay pedidos vendidos y no facturados.
   El comerciante se entera cuando sobrevende.
3. **La copia es contraproducente en este modo.** En `alegra`-wins + modo manual
   + facturas sin crear, copiar Alegra→WC es escribir un número que **no
   incluye las ventas de WC**. Es exactamente el caso donde la copia hace daño.

**Qué debería hacer el plugin (propuesta):**

- **Advertir** en el dashboard cuando `inventory_source=alegra` y
  `push_orders_enabled=false`: "Hay pedidos que no se facturan; el stock de
  Alegra no reflejará las ventas de WooCommerce."
- **Reconciliar / reportar**: un informe de **"pedidos vendidos pero no
  facturados"** (pedidos en `processing`/`completed` sin `_alegra_invoice_id`),
  con acción de "facturar pendientes" (ya existe `Orders::sync_recent()`,
  `Orders.php:561`, y los botones de facturación masiva).
- **Definir la interacción con el pull**: si hay pedidos sin facturar y
  `inventory_source=alegra`, el pull **no debe re-inflar a ciegas**. Opciones
  (decisión pendiente D9): (i) saltar el pull para productos con pedidos
  pendientes; (ii) correr igual y advertir; (iii) bloquear el pull hasta
  facturar. La recomendación conservadora es (ii) + informe visible, y **no**
  prometer precisión de stock mientras existan pedidos sin facturar.

---

## 4. Matriz de configuraciones

> **Nota de lectura.** El plugin es **distribuido**: cada instalación puede tener
> una combinación distinta. Las tres dimensiones de configuración
> (`inventory_source` × facturación × bodegas) definen **8 escenarios**. El tipo
> de producto (`simple` / `variable` / `variación` / `servicio`) es **ortogonal**
> a esas tres: no cambia *quién manda* ni *qué se lee*, solo **sobre qué entidad
> de WooCommerce se escribe**. Por eso la matriz completa de **32 celdas** se
> obtiene cruzando la Tabla 4.1 (8 escenarios) con la Tabla 4.2 (4 tipos). Cada
> celda de la Tabla 4.1 se replica para cada tipo de la Tabla 4.2, y el resultado
> está definido abajo en las Tablas 4.3 y 4.4 (una fila por celda).

### 4.1 Tabla 4.1 — Autoridad por escenario (8 filas)

Abreviaturas: **S** = `inventory_source`; **F** = facturación (`auto`/`manual`);
**B** = bodegas (configurada / no).

| # | S | F | B | Quién manda el stock | Qué lee el plugin | Qué escribe en WC | Qué empuja a Alegra | Experiencia del usuario |
|---|---|---|---|---|---|---|---|---|
| 1 | alegra | auto | no | Alegra (pull) | `inventory.availableQuantity` | `_manage_stock=yes`, `_stock`, `_stock_status` | Factura del pedido (open al pagar) | WC refleja Alegra. Loop cerrado. |
| 2 | alegra | auto | sí | Alegra (pull) | `inventory.warehouses[id].availableQuantity` | idem 1 | Factura con `warehouse` (ver R4) | WC refleja esa bodega de Alegra. |
| 3 | alegra | manual | no | Alegra (pull) | `inventory.availableQuantity` | idem 1 | Factura manual (draft→open al pagar) | El comerciante factura cuando quiere; el stock baja al pagarse. |
| 4 | alegra | manual | sí | Alegra (pull) | `inventory.warehouses[id].availableQuantity` | idem 1 | Factura manual + `warehouse` | idem 3, con bodega. |
| 5 | woocommerce | auto | no | WooCommerce | nada de Alegra | **nada** | Factura del pedido | WC intocable. Alegra refleja la venta por factura. |
| 6 | woocommerce | auto | sí | WooCommerce | nada de Alegra | **nada** | Factura con `warehouse` | idem 5, con bodega. |
| 7 | woocommerce | manual | no | WooCommerce | nada de Alegra | **nada** | Factura manual | WC intocable. El stock de Alegra es responsabilidad del comerciante. |
| 8 | woocommerce | manual | sí | WooCommerce | nada de Alegra | **nada** | Factura manual + `warehouse` | idem 7, con bodega. |

**Lectura clave:** los escenarios 1-4 (Alegra manda) son donde vive la tensión de
la sección 3. Los escenarios 5-8 (WooCommerce manda) **no tienen tensión**: el
plugin no toca el stock de WC, y la factura es el único mecanismo que mueve el
stock de Alegra. El modo "WC-wins" es una configuración legítima, **no un
parche** para el problema.

**Escenarios 3, 4, 7 y 8 en modo manual real (ver 3.4):** "manual" no
significa "factura borrador"; significa "no se crea factura automáticamente".
La Tabla 4.1 asume que el comerciante **va a facturar**. Si no lo hace, aplica
la sección 3.4: divergencia con re-inflación por el pull.

### 4.2 Tabla 4.2 — Entidad y campos por tipo de producto (4 filas)

| Tipo | Entidad WC que gestiona stock | `_manage_stock` | `_stock` | `_stock_status` | `_backorders` |
|---|---|---|---|---|---|
| `simple` | El producto | `yes` si `inventory` presente | en el producto | en el producto | no tocar |
| `variable` (padre) | **Ninguna** (el stock vive en las variaciones) | `no` en el padre | no escribir | WC lo deriva de las variaciones | no tocar |
| `variación` | La variación (hijo) | `yes` si `inventory` presente | en la variación | en la variación | no tocar |
| `servicio` | **Ninguna** | `no` | no escribir | no escribir | no tocar |

Reglas duras por tipo:
- **`variable` padre:** nunca escribir `_stock` en el padre. El stock de un
  producto variable en WooCommerce vive en las variaciones. Si se escribe en el
  padre se crea un dato que WooCommerce ignora y que confunde al comerciante.
- **`servicio`:** Alegra **no devuelve** el objeto `inventory` para un servicio
  (la doc de `GET /items` dice: *"Si este objeto está presente indica que el
  artículo es inventariable, si no lo está se asume como servicio"*,
  https://developer.alegra.com/reference/get_items). Un servicio recibe
  `_manage_stock=no` y **nunca** se le escribe cantidad ni estado.

### 4.3 Tabla 4.3 — Matriz completa, `inventory_source = alegra` (16 celdas)

Cada fila = un escenario de la Tabla 4.1 × un tipo de la Tabla 4.2.

| F | B | Tipo | Quién manda | Qué lee | Qué escribe en WC | Qué empuja a Alegra | Experiencia |
|---|---|---|---|---|---|---|---|
| auto | no | simple | Alegra | `availableQuantity` | producto: manage/stock/status | factura open al pagar | WC = Alegra |
| auto | no | variable | Alegra | `availableQuantity` (por variación) | padre `manage=no`; variaciones: manage/stock/status | factura open al pagar | WC = Alegra |
| auto | no | variación | Alegra | `availableQuantity` | variación: manage/stock/status | factura open al pagar | WC = Alegra |
| auto | no | servicio | Alegra | nada (`inventory` ausente) | `manage=no`; sin stock | factura open al pagar | Servicio no inventariable |
| auto | sí | simple | Alegra | `warehouses[id]` | producto: manage/stock/status | factura + `warehouse` | WC = bodega Alegra |
| auto | sí | variable | Alegra | `warehouses[id]` por variación | padre `manage=no`; variaciones | factura + `warehouse` | WC = bodega Alegra |
| auto | sí | variación | Alegra | `warehouses[id]` | variación | factura + `warehouse` | WC = bodega Alegra |
| auto | sí | servicio | Alegra | nada | `manage=no` | factura + `warehouse` | Servicio no inventariable |
| manual | no | simple | Alegra | `availableQuantity` | producto | factura manual draft→open | Stock baja al pagar |
| manual | no | variable | Alegra | `availableQuantity` por variación | padre `manage=no`; variaciones | factura manual draft→open | Stock baja al pagar |
| manual | no | variación | Alegra | `availableQuantity` | variación | factura manual draft→open | Stock baja al pagar |
| manual | no | servicio | Alegra | nada | `manage=no` | factura manual draft→open | Servicio no inventariable |
| manual | sí | simple | Alegra | `warehouses[id]` | producto | factura manual + `warehouse` | Stock baja al pagar |
| manual | sí | variable | Alegra | `warehouses[id]` por variación | padre `manage=no`; variaciones | factura manual + `warehouse` | Stock baja al pagar |
| manual | sí | variación | Alegra | `warehouses[id]` | variación | factura manual + `warehouse` | Stock baja al pagar |
| manual | sí | servicio | Alegra | nada | `manage=no` | factura manual + `warehouse` | Servicio no inventariable |

### 4.4 Tabla 4.4 — Matriz completa, `inventory_source = woocommerce` (16 celdas)

| F | B | Tipo | Quién manda | Qué lee | Qué escribe en WC | Qué empuja a Alegra | Experiencia |
|---|---|---|---|---|---|---|---|
| auto | no | simple | WooCommerce | nada | **nada** | factura del pedido | WC intocable |
| auto | no | variable | WooCommerce | nada | **nada** | factura del pedido | WC intocable |
| auto | no | variación | WooCommerce | nada | **nada** | factura del pedido | WC intocable |
| auto | no | servicio | WooCommerce | nada | **nada** | factura del pedido | WC intocable |
| auto | sí | simple | WooCommerce | nada | **nada** | factura + `warehouse` | WC intocable |
| auto | sí | variable | WooCommerce | nada | **nada** | factura + `warehouse` | WC intocable |
| auto | sí | variación | WooCommerce | nada | **nada** | factura + `warehouse` | WC intocable |
| auto | sí | servicio | WooCommerce | nada | **nada** | factura + `warehouse` | WC intocable |
| manual | no | simple | WooCommerce | nada | **nada** | factura manual | WC intocable |
| manual | no | variable | WooCommerce | nada | **nada** | factura manual | WC intocable |
| manual | no | variación | WooCommerce | nada | **nada** | factura manual | WC intocable |
| manual | no | servicio | WooCommerce | nada | **nada** | factura manual | WC intocable |
| manual | sí | simple | WooCommerce | nada | **nada** | factura manual + `warehouse` | WC intocable |
| manual | sí | variable | WooCommerce | nada | **nada** | factura manual + `warehouse` | WC intocable |
| manual | sí | variación | WooCommerce | nada | **nada** | factura manual + `warehouse` | WC intocable |
| manual | sí | servicio | WooCommerce | nada | **nada** | factura manual + `warehouse` | WC intocable |

### 4.5 Resumen de la matriz

- **32 celdas** en total (8 escenarios × 4 tipos).
- **Alegra manda** (16 celdas): el plugin es el único escritor de `_stock` en WC;
  la venta mueve el stock de Alegra vía factura **abierta**.
- **WooCommerce manda** (16 celdas): el plugin **no escribe stock en WC** y **no
  lee stock de Alegra**; la factura es el único puente.
- **Servicios** (8 celdas): nunca inventariables, en ninguna configuración.
- **Variables** (8 celdas): el padre nunca gestiona stock; las variaciones sí.

### 4.6 Las 7 celdas de configuración que faltaban

El diseño original asumía que todo producto inventariable debe gestionar stock y
que toda entidad existe en ambos lados. Estas 7 configuraciones **no estaban
modeladas** y cada una tiene un comportamiento requerido distinto.

| # | Configuración | Por qué importa | Comportamiento requerido |
|---|---|---|---|
| **C1** | Producto **deliberadamente** `manage_stock=no` en WC (dropship, digital, print-on-demand) | El diseño fuerza `_manage_stock=yes` sin preguntar. Un comerciante que a propósito no gestiona stock en WC (porque lo gestiona un tercero) se lo rompemos. | **Opt-out por producto**: meta `_alegra_manage_stock_opt_out = yes` (o regla global). Si está seteado, el pull **no escribe** `_manage_stock` ni `_stock` para ese producto y lo reporta como "excluido". |
| **C2** | Producto **variable donde solo ALGUNAS variaciones** son inventariables | `WC_Product_Variable::sync()` deriva el estado del padre de sus hijos; si hay hijos con `manage_stock` mixto, el estado del padre es **indefinido**. El diseño solo contemplaba "variable con todas inventariables". | Política explícita: si **cualquier** variación es inventariable, el padre va `manage_stock=no` y `stock_status` se deriva con `sync()` (ver R8). Las variaciones **servicio** dentro del padre se tratan como C1 (no se tocan). Reportar el caso mixto como WARN. |
| **C3** | Producto **borrado en Alegra** pero existente en WC | El pull **nunca lo visita** (no viene en `GET /items`). Queda stock viejo y un `_alegra_item_id` colgando que apunta a un id inexistente. El handler de webhook **existe** (`Webhooks/Handlers.php:76`, escribe tombstone vía `Tombstone_Manager`), pero el diseño no lo referencia. | El pull debe **detectar** items vinculados que ya no existen en Alegra (o confiar en `delete-item`): marcar el producto (meta `_alegra_item_missing_since`), **no** borrar stock por defecto, y reportar. El webhook `delete-item` (`Handlers.php:86`) es la fuente preferida; el pull es el respaldo. Ver R-DEL. |
| **C4** | Producto **creado en WC, nunca en Alegra** | El pull nunca lo visita (no está en Alegra). El push (`prepare_simple_product_data()`, `Products.php:267`) le manda `initialQuantity` **sin importar** `manage_stock`, y puede crear un item de Alegra con stock basura (o 0). | El push no debe enviar `inventory` si el producto no gestiona stock (C1) o si el valor es desconocido. Al crear en Alegra por primera vez, enviar `initialQuantity` es legítimo **solo en el `POST /items` de creación**, nunca en updates (R2). |
| **C5** | `availableQuantity` **negativo** (Alegra lo permite) | El pull hace `(int) $item['inventory']['availableQuantity']` (`Products.php:524`) y lo pasa directo a `set_stock_quantity()`. No hay política para negativos. | Política: un negativo en Alegra es un dato inválido para WC. **Clampear a 0** y `outofstock`, **reportar WARN** con el valor original. No propagar negativos a WC. |
| **C6** | Bodegas **activadas pero sin bodega seleccionada** | El `<select>` de bodega tiene una opción `value="0"` ("Principal (ID: 1)", `admin-settings.php:164`). `resolve_warehouse_id()` (`Products.php:418`) devuelve `''` si `warehouse_enabled` está apagado, pero si está **encendido** y el valor es `'0'`, devuelve `'0'` — y `'0' !== ''`, así que se trata como una bodega real. **El documento original NO cubría este caso** (solo cubría "bodega ausente del item", no "sin bodega elegida"). | Normalizar: `warehouse_enabled && (id === '' || id === '0')` ⇒ **tratar como "sin bodega"** y usar `availableQuantity` total; **advertir** en el dashboard que la gestión de bodegas está encendida sin bodega elegida. Verificar la semántica de "Principal" con Alegra (SIN VERIFICAR). |
| **C7** | `inventory_source=woocommerce` **+ facturación automática** | Es la combinación "WC manda el stock, pero igual se factura sola". ¿La factura mueve el stock de Alegra y el comerciante además lo maneja en WC? ¿Doble conteo? | **Cubierto** por la Tabla 4.1 (escenarios 5 y 6) y la sección 5.7: en WC-wins el plugin **no escribe** stock en WC; la factura mueve Alegra y eso es **esperado** (Alegra refleja la venta). El stock "real" es el de WC. La copia se salta por completo (`Products.php:453`). No hay doble conteo porque el plugin nunca empuja ajustes automáticos (sección 5.8). |

---

## 5. Diseño propuesto

### 5.1 Resolución de la tensión borrador vs. stock

Se evaluaron cuatro opciones contra cuatro criterios: **plugin distribuido**
(cada configuración debe funcionar), **default manual** (no romperlo),
**riesgo de stock fantasma** y **reversibilidad**.

| Opción | Descripción | Distribuido | Default manual | Stock fantasma | Reversible | Veredicto |
|---|---|---|---|---|---|---|
| **(a) Factura atada al pedido** | Borrador si el pedido no está pago; **se abre automáticamente al pagarse/completarse**. | ✅ | ✅ (manual sigue decidiendo *cuándo* facturar) | ✅ lo elimina | ✅ (basta no abrir) | **RECOMENDADA** |
| (b) Forzar WC-wins si hay borradores | Si `invoice_status=draft`, ignorar `inventory_source=alegra`. | ⚠️ rompe la promesa del interruptor | ✅ | ✅ | ✅ | Rechazada como default |
| (c) Crear siempre `open` | Ignorar el default `draft`. | ✅ | ❌ lo rompe | ✅ | ❌ (emitir es fiscalmente irreversible) | Rechazada |
| (d) Empujar ajustes desde WC (WC-wins puro) | WC escribe, Alegra se ajusta por deltas. | ✅ | ✅ | ✅ | ⚠️ doble conteo si hay factura | No como default; sí como modo |

**Recomendación: opción (a), la "factura atada al ciclo de vida del pedido".**

**Por qué (a) y no las otras:**

- **(a) respeta los tres constraints del usuario.** El plugin sigue siendo
  distribuido: es una opción más, no una dirección hardcodeada. El default
  manual se conserva: el comerciante **decide cuándo facturar** (el botón sigue
  ahí). Y el stock de Alegra queda veraz porque la factura se abre cuando el
  pedido se paga, que es cuando la venta es real.
- **(b) miente.** El comerciante eligió "Alegra manda" y el plugin, por lo bajo,
  haría lo contrario. Eso es exactamente el patrón que ya lo quemó una vez (un
  interruptor que no hace lo que dice). Prohibido repetirlo.
- **(c) es fiscalmente peligroso.** Emitir una factura antes de que el cliente
  pague es irreversible y puede violar la normativa del país. No puede ser el
  default.
- **(d) no resuelve la tensión; la esquiva.** Además, si se empujan ajustes y
  además se abre la factura, se **cuenta doble** el descuento de stock.

#### 5.1.1 Cómo funciona (a) en concreto — y la contradicción D1 resuelta

**El problema (A6).** Hoy:

- `create_invoice()` (`Orders.php:35`) resuelve el estado así:
  `$status_override ?? get_option('alegra_connector_invoice_status', 'draft')`
  (`Orders.php:614`). **No consulta el estado de pago del pedido.** Si no hay
  override, siempre usa la opción (`draft` por defecto).
- El **único** camino que fuerza `open` es `create_invoice_with_payment()`
  (`Orders.php:238`): pasa `'open'` como override **solo si**
  `alegra_connector_payment_account_id` está configurado **y** el pedido no tiene
  `_alegra_payment_id` (`Orders.php:244-247`). Si no hay cuenta de banco, la
  factura queda `draft` aunque el pedido esté pagado.
- El gancho natural "al pagar" (`woocommerce_payment_complete` /
  `woocommerce_order_status_completed`) está registrado **solo dentro de**
  `if (get_option('alegra_connector_push_orders_enabled', false))`
  (`Public_.php:49-59`). En **modo manual** (default) esos hooks **no existen**:
  abrir la factura al pagar es **imposible** por esa vía. Es un **bloqueo
  arquitectónico** (ver R-HOOK, sección 7).

**La resolución (método exacto que cambia):**

1. **`Orders::prepare_invoice_data()` (`Orders.php:592`)** deja de resolver el
   estado en `Orders.php:614` con un simple `??`. Nueva regla, en este orden:
   1. Si hay `$status_override`, usarlo (lo pasa `create_invoice_with_payment()`).
   2. Si `get_option('alegra_connector_open_invoice_on_paid', true)` **y** el
      pedido ya está pago (helper `Orders::order_is_paid($order)`: `$order->get_date_paid()`
      no nulo **o** status `processing`/`completed`), usar `'open'`.
   3. Si no, usar `get_option('alegra_connector_invoice_status', 'draft')`.
   > Nota: la opción `invoice_status` pasa a leerse como *"estado de la factura
   > mientras el pedido NO está pago"*. Se actualiza el texto de
   > `templates/admin-settings.php:209-214` para que lo diga.
2. **`Orders::create_invoice_with_payment()` (`Orders.php:238`)** **no cambia su
   lógica de override** (sigue forzando `open` si hay `payment_account_id`),
   pero ahora su caso "sin cuenta de banco" también queda `open` si el pedido
   está pago, por la regla 1.2.
3. **Nuevo handler `Orders::on_order_paid_open_invoice(int $order_id)`** que:
   lee `_alegra_invoice_id`; si no existe, **no hace nada** (no crea factura: eso
   sigue siendo responsabilidad de `create_invoice*()`); si existe, llama a
   `ensure_invoice_open()` (`Orders.php:322`) — que ya hace exactamente esto. Se
   registra **fuera** del `if (push_orders_enabled)` en `Public_.php`, para que
   funcione en modo manual (resuelve R-HOOK). Es una operación de **apertura de
   un borrador existente**, no de facturación, así que no viola el modo manual.
4. **`ensure_invoice_open()` (`Orders.php:322`)** ya existe y no cambia; solo se
   invoca desde el nuevo handler.

**Nueva opción:** `alegra_connector_open_invoice_on_paid`
(`sí`/`no`, default `sí`). Si el comerciante la apaga, vuelve al comportamiento
actual (facturas siempre en el estado elegido, sin abrir al pagar) y el diseño
**debe advertirle** que con `inventory_source=alegra` eso produce stock fantasma.

**Regla de coherencia (guardrail):** si `inventory_source=alegra` **y**
`invoice_status=draft` **y** `open_invoice_on_paid=no`, mostrar una advertencia
visible en el dashboard (sección 2.4). No bloquear: es una configuración
legítima, pero debe ser **consciente**.

### 5.2 Árbol de decisión (fuente × bodegas × tipo)

```
¿inventory_source == 'woocommerce'?
├── SÍ  → NO leer stock de Alegra. NO escribir stock en WC.
│         (La factura es el único mecanismo que mueve Alegra.)
│         → FIN. No tocar inventario en WC.
│
└── NO (alegra) → El plugin es el ÚNICO escritor de _stock en WC.
    │
    ├── ¿Kill switch activo? (AGREGAR: hoy no existe) → abortar.
    ├── ¿Lock de sync tomado? → abortar.
    ├── ¿Cancelación pedida? → abortar.
    │
    └── Para cada item de Alegra (GET /items?mode=advanced):
        │
        ├── ¿Falta el objeto inventory?  → es un SERVICIO.
        │     → set_manage_stock(no). No escribir _stock. FIN item.
        │
        ├── ¿inventory.availableQuantity ausente/null?
        │     → SKIP + WARN. NUNCA escribir 0. FIN item.
        │
        ├── ¿warehouse_enabled && warehouse_id válido (ni '' ni '0')?
        │     ├── buscar warehouses[] por id
        │     ├── ¿no está esa bodega en el item?
        │     │     → SKIP + WARN. NUNCA escribir 0. FIN item.
        │     └── qty = warehouses[id].availableQuantity
        │
        ├── ¿warehouse_enabled && (warehouse_id === '' || '0')?
        │     → C6: tratar como "sin bodega" + WARN. qty = total.
        │
        └── sino → qty = inventory.availableQuantity  (total multi-bodega)
              │
              ├── ¿qty < 0? (C5) → clamp a 0 + WARN.
              │
              ├── ¿producto con _alegra_manage_stock_opt_out=yes? (C1)
              │     → NO escribir. Reportar "excluido". FIN item.
              │
              └── resolver entidad WC:
                  ├── variación  → escribir en la VARIACIÓN
                  ├── variable   → padre manage=no; NO escribir en el padre
                  ├── simple     → escribir en el PRODUCTO
                  └── servicio   → (ya resuelto arriba)
```

### 5.3 Campos exactos de WooCommerce

Por cada entidad que gestiona stock (producto simple, o cada variación):

| Campo | Valor | Cuándo |
|---|---|---|
| `_manage_stock` | `yes` | Si `inventory` está presente (item inventariable) **y** la migración opt-in está activa **y** no hay opt-out por producto (C1). |
| `_manage_stock` | `no` | Si es servicio, o si `inventory` está ausente, o si la migración no está activa. |
| `_stock` | `(int) $qty` | Solo si `$qty` es un entero válido (no null, no ausente, no negativo — C5). |
| `_stock_status` | `instock` si `$qty > 0` | Siempre que se escriba `_stock`. |
| `_stock_status` | `outofstock` si `$qty <= 0` | Siempre que se escriba `_stock`. |
| `_stock_status` | `onbackorder` si `$qty <= 0` y `_backorders` != `no` | Si el comerciante permite pedidos en espera. |
| `_backorders` | **no tocar** | Por defecto. Cambiarlo altera la política de la tienda; no es parte del sync. |

**En API de WooCommerce (equivalente):**

```
$product->set_manage_stock(true);
$product->set_stock_quantity($qty);
$product->set_stock_status($status);   // OBLIGATORIO: WC no lo recalcula solo
$product->save();
```

**Regla padre vs. variación (obligatoria):**

- Un producto `variable` en WooCommerce **no** gestiona stock a nivel padre
  cuando sus variaciones lo hacen. Escribir `_stock` en el padre es un error
  silencioso: WC lo ignora y el dato queda inconsistente.
- El padre recibe `_manage_stock = no` (o no se toca) y, si hace falta,
  `WC_Product_Variable::sync()` para que el padre derive su `_stock_status` de
  las variaciones. **SIN VERIFICAR** si `sync()` recalcula el estado del padre;
  hay que probarlo (Riesgo 8).
- **Caso mixto (C2):** si solo algunas variaciones son inventariables, el padre
  igual va `manage_stock=no`; las variaciones servicio no se tocan. Reportar WARN.
- Cada **variación** se escribe con sus tres campos, individualmente.

### 5.4 Lógica de bodegas

- **Sin bodega configurada** (`warehouse_enabled` apagado, **o** `warehouse_id`
  vacío **o** `'0'` — C6): usar `inventory.availableQuantity`, que según la doc de
  `GET /items` (*"si el producto se encuentra distribuido en múltiples bodegas,
  este atributo retorna la cantidad disponible en todas las bodegas"*,
  https://developer.alegra.com/reference/get_items) es el **total**.
- **Con bodega configurada**: recorrer `inventory.warehouses[]`, buscar la entrada
  con `id == warehouse_id`, y usar **su** `availableQuantity`.
  - El tipo en la API es **string** (`"availableQuantity": "150"`), no entero.
    Convertir con cuidado.
- **Bodega configurada pero ausente del item** → **SKIP + WARN, NUNCA escribir 0.**
  Motivo: la ausencia puede significar "este item no está asignado a esa bodega",
  no "hay cero unidades". Escribir 0 marcaría el producto como agotado en toda la
  tienda, un daño peor que no actualizar. La política por defecto es conservadora:
  no escribir es siempre reversible; escribir 0 no.
- **Alternativa documentada (a evaluar):** `GET /items` acepta el parámetro
  `idWarehouse` (*"Si se especifica este parámetro se retornan únicamente los
  productos no inventariables y los inventariables que estén en la
  bodega/almacén"*, https://developer.alegra.com/reference/get_items). Eso
  permitiría pedir el inventario **ya filtrado por bodega** en vez de leer
  `warehouses[]` item por item. **SIN VERIFICAR** qué campo de cantidad devuelve
  en ese modo. Ventaja: menos ambigüedad de "bodega ausente". Riesgo: el filtro
  **excluye** items que no están en la bodega, lo que puede dejar stock viejo.
- **Bodegas en el push**: ya funciona (`apply_warehouse()`, `Products.php:432`).
  El pull debe **leer** la misma bodega que el push **escribe**, para que el ida
  y vuelta sea coherente. Hoy el push usa `warehouse_id` y el pull ignora bodegas:
  eso es una divergencia a corregir.

### 5.5 Regla de `availableQuantity` nulo/ausente/negativo

| Caso | Acción |
|---|---|
| `inventory` ausente | Es un **servicio**. `_manage_stock=no`. No escribir `_stock` ni `_stock_status`. |
| `inventory` presente, `availableQuantity` ausente o `null` | **SKIP + WARN**. Nunca escribir 0. |
| `availableQuantity` = 0 | **Sí escribir 0** (es un valor legítimo) y `_stock_status=outofstock`. |
| `availableQuantity` **negativo** (C5) | **Clamp a 0** + `outofstock` + WARN con el valor original. Nunca propagar negativos a WC. |
| `availableQuantity` con formato no numérico | **SKIP + WARN**. No castear basura a 0. |

**Principio rector:** *la ausencia de dato no es un cero.* Es la diferencia entre
"no sé" y "hay cero". El plugin nunca debe convertir "no sé" en "agotado".

### 5.6 Cómo se expone la copia (pull)

Hoy: **no se expone en ningún lado productivo.** El diseño propone tres entradas,
todas apuntando al **mismo** método (`sync_inventory_from_alegra()`), para que
haya una sola implementación:

1. **Cron.** `Controller::run_cron_sync()` (`Controller.php:90`) debe invocar la
   copia cuando `sync_products` está activo **y** `inventory_source=alegra`.
   *Hoy* el cron llama a `import_from_alegra()` (`Controller.php:120`), que
   intenta escribir stock por su cuenta (`Products.php:1203`). Eso es un
   **segundo escritor**. La corrección es que `update_product_from_alegra()`
   deje de escribir stock y **delegue** en el escritor único (sección 5.7).
2. **Botón de admin.** No existe hoy. Agregar "Sincronizar inventario" en la
   página de Productos, con su AJAX y nonce, como los demás botones de sync.
3. **Acción `alegra_sync_inventory_from_alegra`.** El manual de despliegue la
   documenta (`docs/RELEASE_2.1.9_DEPLOY.md:359`) y el operador la invoca por
   WP-CLI (`wp eval 'do_action("alegra_sync_inventory_from_alegra");'`). **No
   está registrada.** Registrarla con `add_action()` apuntando al mismo método.

**Compuertas (gates) que la copia debe respetar, en orden:**

| Compuerta | Fuente | Comportamiento | Estado |
|---|---|---|---|
| Kill switch | `Kill_Switch::is_active()` | Abortar todo. | **AGREGAR.** Hoy **no existe** en `sync_inventory_from_alegra()` (`Products.php:447-545`). `import_from_alegra()` sí lo tiene (`Products.php:608,648`) y sirve de modelo. |
| Lock atómico | `Controller::acquire_sync_lock_public('products')` | Si está tomado, no correr. | Ya existe (`Products.php:459`). |
| Cancelación | transient `alegra_sync_cancelled` | Cortar entre páginas. | Ya existe (`Products.php:471`). |
| `sync_method` | `alegra_connector_sync_method` | El cron solo corre con `cron` o `both`; el botón y la acción corren siempre (manual). | Ya existe (`Controller.php:65`). |
| `inventory_source` | `alegra_connector_inventory_source` | Si es `woocommerce`, no leer ni escribir. | Ya existe (`Products.php:453`). |
| Migración opt-in | nueva opción (sección 6) | Si no está activa, la copia **reporta** pero **no escribe** `_manage_stock`/`_stock`. | **AGREGAR** (sección 6). |

**Nota sobre `sync_method`:** la doc del repo es explícita en que
`sync_method` controla **solo la entrada** (Alegra → WC) y no la subida de pedidos
(`docs/DOCUMENTACION.md:378`; `alegra-connector.php:494-499`). El diseño respeta
esa separación: la copia de stock es entrada.

### 5.7 Regla anti-divergencia: UN solo escritor

**Para `inventory_source = alegra`, el ÚNICO escritor de `_stock` / `_manage_stock`
/ `_stock_status` en WooCommerce es `sync_inventory_from_alegra()`** (o el helper
compartido que este use). Ningún otro camino puede escribir esos campos.

Consecuencias de diseño:

- `update_product_from_alegra()` (`Products.php:1152`) **debe dejar de escribir
  stock** en `Products.php:1203-1209`. Hoy es un segundo escritor, y su guard
  `get_manage_stock()` hace que además sea inconsistente con el pull. **La
  escritura se elimina de ese método y se delega al helper compartido**
  (sección 5.9), de modo que la importación de productos nuevos y el pull usen
  exactamente la misma lógica de bodegas, nulos, negativos, tipos y opt-out.
- El push WC→Alegra (`prepare_simple_product_data()`, `Products.php:267`) **no**
  debe reenviar el stock como si fuera un valor absoluto (ver Riesgo 2 / R2).
- La importación de productos (`import_from_alegra()`) y la copia de inventario
  comparten el mismo helper de escritura; el import **delega** en él para no
  duplicar la lógica de bodegas, nulos y servicios.
- En `inventory_source = woocommerce`, el escritor es **WooCommerce** (el
  comerciante). El plugin no escribe nada.

**Definición operativa de "un solo escritor":** para cada producto y cada
configuración, existe exactamente **una** ruta de código autorizada a escribir
`_stock`. Cualquier otra ruta que lo escriba es un bug de divergencia.

### 5.8 El rol de `create_inventory_adjustment()`

`Client::create_inventory_adjustment()` (`Client.php:648`) existe y **tiene 0
llamadores**. La API lo soporta: `POST /inventory-adjustments`
(https://developer.alegra.com/reference/post_inventory-adjustments), con
`warehouse.id`, `items[].type` (`in`/`out`), `quantity` y `unitCost`.

**Rol propuesto:**

- **En `inventory_source = alegra` (Alegra-wins): NO cablearlo.** Alegra es la
  fuente; empujarle ajustes desde WC sería un segundo escritor del stock de
  Alegra y podría contar doble con la factura. Se deja como está: sin usar.
- **En `inventory_source = woocommerce` (WC-wins): documentar su rol**, no
  cablearlo a ciegas. Sirve para **reconciliaciones explícitas de ediciones
  manuales de stock en WC** (mermas, ajustes de conteo) que el comerciante quiera
  reflejar en Alegra. **No** se usa para ventas: la venta ya la mueve la factura.
  Usarlo para ventas **contaría doble** cuando la factura se abra.
- **Regla:** `create_inventory_adjustment()` es una herramienta de
  **reconciliación manual/opt-in**, nunca un mecanismo automático del sync. Si se
  llegara a usar, debe ser delta-based (`type=in|out`) y con su propio lock e
  idempotencia, jamás un valor absoluto.

### 5.9 Especificación del DÓNDE (implementación concreta)

La crítica central de la revisión: *"dos devs no implementarían lo mismo"*. Esta
sección fija **archivo, método, hook, opción, llamadas WC y destino del código
existente** para cada cambio. Es el contrato que consumen las tareas del SDD.

#### 5.9.1 Helper compartido (nuevo)

- **Archivo nuevo:** `includes/Sync/Inventory_Writer.php`.
- **Clase:** `Alegra\Connector\Sync\Inventory_Writer`.
- **Método público único:** `apply( \WC_Product $product, array $item, array $opts = [] ): string`.
  Devuelve un código de resultado: `updated` | `skipped_no_qty` | `skipped_service`
  | `skipped_opt_out` | `skipped_warehouse_missing` | `skipped_not_manageable`
  | `error`.
- **Métodos privados:** `resolve_quantity(array $item, string $warehouse_id): array`
  (devuelve `[qty|null, reason]`), `apply_stock(\WC_Product $p, int $qty): void`.
- **Es el ÚNICO lugar del plugin que llama a `set_manage_stock()`,
  `set_stock_quantity()` y `set_stock_status()`.** Grep-able: esos tres setters no
  deben aparecer en ningún otro archivo de producción.
- `apply_stock()` ejecuta literalmente:
  `set_manage_stock(true)` + `set_stock_quantity($qty)` +
  `set_stock_status($qty > 0 ? 'instock' : 'outofstock')` + `save()`.
- Recibe `$opts`: `manage_stock_enabled` (bool, de la opción de migración),
  `warehouse_id` (string), `dry_run` (bool).

#### 5.9.2 `Products::sync_inventory_from_alegra()` (modificar)

- **Archivo/método:** `includes/Sync/Products.php:447`.
- **Agregar al inicio:** guard `Kill_Switch::is_active()` (copiar patrón de
  `Products.php:608`). **A1.**
- **Reemplazar** el bloque `Products.php:524-532` (cast + guard `get_manage_stock`
  + `set_stock_quantity` + `save`) por una llamada a
  `(new Inventory_Writer($this->logger))->apply($product, $item, $opts)`.
- **`$opts`:** `manage_stock_enabled` = `get_option('alegra_connector_inventory_manage_stock_enabled', false)`,
  `warehouse_id` = `resolve_warehouse_id()` **normalizado** (C6: `''` o `'0'` ⇒ `''`),
  `dry_run` = `get_option('alegra_connector_inventory_dry_run', true)`.
- **Paginación (R-PULL):** mantener `max_pages=200` (`Products.php:467`) pero
  **persistir un cursor** (`alegra_connector_inventory_pull_cursor`) y reanudar en
  la próxima corrida, igual que `import_from_alegra()` (`Products.php:614-626`).
  En error de API (`Products.php:501-504`): **guardar el cursor y salir**, no
  `break` perdiendo el progreso. Agregar **idempotencia**: la operación es
  naturalmente idempotente (escribir el valor absoluto de Alegra), pero el
  resultado debe registrar `updated`/`skipped` por producto para poder reanudar.

#### 5.9.3 `Products::update_product_from_alegra()` (modificar)

- **Archivo/método:** `includes/Sync/Products.php:1152`.
- **Eliminar** el bloque `Products.php:1203-1209` (escritura directa de stock).
- **Delegar:** llamar a `(new Inventory_Writer(...))->apply($product, $item, $opts)`
  con los mismos `$opts`. Así la importación de productos nuevos (que pasa por
  este método en `Products.php:792,806,844,1018,1045`) y el pull usan la misma
  regla.
- **Consecuencia:** `import_from_alegra()` deja de ser un segundo escritor. El
  guard `get_manage_stock()` desaparece como criterio (lo reemplaza la política
  de C1/servicio/migración).

#### 5.9.4 Push WC→Alegra (modificar — R2)

- **Archivo/métodos:** `Products.php:267` (`prepare_simple_product_data`),
  `Products.php:339` (`prepare_variation_data`), `Products.php:432`
  (`apply_warehouse`).
- **Regla:** `inventory.initialQuantity` se envía **solo en creación**
  (`create_item()`, `Products.php:152,251`), **nunca** en actualización
  (`update_item()`, `Products.php:128,141,196,230`). Implementación: agregar un
  parámetro `bool $is_create = false` a los tres builders; cuando `$is_create`
  es `false`, **omitir** `initialQuantity` (y el `initialQuantity` de
  `apply_warehouse()`, `Products.php:440`). Alternativa si `PUT` exige el bloque:
  enviar el valor que Alegra ya tiene (requiere leerlo antes) — evaluar tras R2.
- **Además (C4):** no enviar `inventory` si el producto tiene
  `_alegra_manage_stock_opt_out=yes` o `get_manage_stock() === false`.
- **R3 (2026-09-17) — endurecido al revisar la doc:** omitir `initialQuantity`
  no alcanza; en el update se omite el objeto `inventory` **entero**. La doc
  marca `unit`, `unitCost` e `initialQuantity` como *obligatorios cuando el
  objeto está presente*, así que un `inventory` parcial `{unit}` es
  indocumentado (riesgo de **400** o de leer `initialQuantity` ausente como
  **0** → stock corrupto). `PUT /items/{id}` es una actualización parcial —
  *"Solo enviar los campos que cambiarán"*
  (https://developer.alegra.com/reference/items__updateitem) — e `inventory` no
  es obligatorio, por lo que omitirlo deja el inventario intacto. En creación se
  sigue enviando `inventory: {unit, unitCost, initialQuantity}` (y `warehouses`
  si aplica).
- **Fix WRITE enum + `unitCost` (2026-09-17) — el push de productos estaba
  roto.** Dos defectos en el `POST /items` de creación:
  1. `type` se enviaba como `simple`. Ese es el enum de **lectura** (`GET /items`
     devuelve `simple` para un producto sencillo —
     https://developer.alegra.com/reference/get_items); el enum de **escritura**
     es `product | service | variantParent | kit`
     (https://developer.alegra.com/reference/post_items y
     https://developer.alegra.com/reference/items__createitem). Un producto
     sencillo debe enviarse como `product`. Corregido en
     `prepare_simple_product_data()` (create **y** update, que comparten el
     builder).
  2. `inventory.unitCost` estaba ausente. La doc lo marca *obligatorio* cuando
     `inventory` está presente (`post_items.md`: "unit (obligatorio) ... unitCost
     (obligatorio) ... initialQuantity (obligatorio)"). Corregido: se envía el
     costo desde `_wc_cog_cost` / `_cost` y **0** como fallback (la doc exige que
     el campo exista y sea numérico, no que sea > 0).
  El mock de tests (`scripts/lib/alegra-mock.php`) aceptaba cualquier body con
  200 y **no** validaba el enum, por lo que el defecto era invisible en la
  suite. Tests de regresión: `exec-test.php` T1.3/T1.4/T1.5 y T-hotfix-1.
  **Pendiente/UNVERIFIED:** `warehouses` es opcional en el REST (`post_items`)
  pero la tabla del tool MCP lo lista junto a `unit`/`unitCost` como requerido
  para `product`; se mantiene el envío condicional actual (solo con bodega
  configurada). El flujo de producto **variable** sigue usando `subitems` con
  `type=variantParent` (el campo `subitems` es de `kit`) y crea cada variación
  con `type=variant`, que **no** está en el enum de escritura — es un problema
  de diseño aparte, no cubierto por este fix.
- **Cambio de diseño (R3):** el stock **no** se cambia vía `PUT /items`; el
  camino documentado es `POST /inventory-adjustments`
  (https://developer.alegra.com/reference/post_inventory-adjustments), con
  `items[].type` (`in`/`out`), `quantity` y `unitCost` obligatorios. Cablearlo
  queda para el SDD de inventario; este hotfix solo deja de mandar `inventory`
  en los updates (ver `docs/sdd/inventory/design.md` §3.4).

#### 5.9.5 Factura: estado y apertura (modificar — A6)

- **Archivo/método:** `Orders::prepare_invoice_data()` (`Orders.php:592`),
  línea `Orders.php:614`.
- **Cambio:** reemplazar el `??` por la regla de 3 pasos de la sección 5.1.1.
- **Nuevo helper privado:** `Orders::order_is_paid(\WC_Order $order): bool` en
  `Orders.php` (usa `get_date_paid()` y el status).
- **Nuevo handler:** `Orders::on_order_paid_open_invoice(int $order_id): void` en
  `Orders.php`, que llama a `ensure_invoice_open()` (`Orders.php:322`) si existe
  `_alegra_invoice_id`.
- **Registro del hook (A2/R-HOOK):** en `public/Public/Public_.php`, **fuera** del
  bloque `if (get_option('alegra_connector_push_orders_enabled', false))`
  (`Public_.php:49-59`), agregar:
  `add_action('woocommerce_payment_complete', [$this, 'on_order_paid_open_invoice'], 10, 1);`
  y `add_action('woocommerce_order_status_completed', ...)`. El método público en
  `Public_` delega a `Sync\Orders`. Esto hace que la apertura funcione en modo
  manual sin crear facturas.
- **`create_invoice_with_payment()` (`Orders.php:238`)** no cambia su override
  (`Orders.php:247`); hereda la regla nueva.

#### 5.9.6 Opciones (nuevas y modificadas)

| Opción | Tipo | Default | Se registra en | Se lee en |
|---|---|---|---|---|
| `alegra_connector_inventory_manage_stock_enabled` | bool | `false` | `Admin_Dashboard::register_settings` (patrón `Admin_Dashboard.php:340`) | `Inventory_Writer` vía `$opts`; `sync_inventory_from_alegra()` |
| `alegra_connector_inventory_dry_run` | bool | `true` | idem | `sync_inventory_from_alegra()` |
| `alegra_connector_inventory_pull_cursor` | int | `0` | no se registra (interno) | `sync_inventory_from_alegra()` |
| `alegra_connector_open_invoice_on_paid` | bool | `true` | idem | `Orders::prepare_invoice_data()` |
| `alegra_connector_inventory_source` (existente) | string | `alegra` | ya registrada (`Admin_Dashboard.php:340`) | `sync_inventory_from_alegra()` (`Products.php:453`) |
| `alegra_connector_invoice_status` (existente) | string | `draft` | ya registrada | `Orders.php:614` |

**Meta por producto (C1):** `_alegra_manage_stock_opt_out` (`yes`/vacío).
**Meta por producto (C3):** `_alegra_item_missing_since` (timestamp, solo reporte).

#### 5.9.7 Código existente: qué pasa con cada cosa

| Código | Acción | Motivo |
|---|---|---|
| `Products.php:1203-1209` | **Eliminar** | Segundo escritor. |
| `Products.php:525-532` | **Reemplazar** por `Inventory_Writer::apply()` | Único escritor. |
| `Products.php:282,361,440` (`initialQuantity`) | **Condicionar a creación** | R2: corrupción activa en cada `PUT`. |
| `Orders.php:614` | **Modificar** (regla de 3 pasos) | D1/A6. |
| `Orders.php:247` (override `'open'`) | **No cambiar** | Ya correcto; hereda la regla nueva. |
| `Orders.php:322` (`ensure_invoice_open`) | **No cambiar**, solo invocar desde el nuevo handler | Reutilizar. |
| `Orders.php:485-493` (payload nota crédito) | **Revisar** tras R-CN | Posible falta de `status`/`warehouse`. |
| `Public_.php:49-59` | **Agregar hooks fuera del `if`** | R-HOOK: modo manual. |
| `Controller.php:120` (`import_from_alegra`) | **Mantener**, pero ya no escribe stock | Un solo escritor. |
| `Client.php:648` (`create_inventory_adjustment`) | **No cablear** | Sección 5.8. |

#### 5.9.8 Botón de admin y acción WP-CLI (nuevos)

- **Botón:** `Admin_Dashboard` — AJAX `alegra_sync_inventory` (nonce
  `alegra_sync_nonce`, capability `manage_woocommerce`), llama a
  `sync_inventory_from_alegra()`.
- **Acción:** `add_action('alegra_sync_inventory_from_alegra', [$products, 'sync_inventory_from_alegra'])`
  registrada en el bootstrap (`alegra-connector.php`), para que
  `docs/RELEASE_2.1.9_DEPLOY.md:359` deje de mentir.

---

## 6. Migración

### 6.1 El peligro (hay que decirlo claro)

Las tiendas existentes tienen hoy:

- `_manage_stock = no` en (prácticamente) todos los productos, porque el plugin
  nunca lo activó.
- Posiblemente `_stock` con valores **reales** cargados a mano por el comerciante,
  o con valores viejos/cero.

Si activáramos la gestión de stock **en masa** y escribiéramos las cantidades de
Alegra:

1. **Sobrescribiríamos el stock real de WooCommerce** con lo que diga Alegra. Si
   el comerciante lleva el inventario en WC (y no en Alegra), le borramos el dato.
2. **Sin `_stock_status`**, todos los productos pasarían a "disponible". Un
   producto con 0 unidades quedaría **vendible**, generando sobreventa inmediata.
3. Si Alegra tuviera valores desactualizados (por el propio problema de la
   sección 3), estaríamos copiando basura **encima** de datos buenos.

En criollo: *activar esto sin cuidado puede romper el inventario de una tienda que
hoy funciona*. Por eso la migración **no puede** ser automática ni silenciosa.

### 6.2 Propuesta: opt-in, dry-run y regla por producto

**a) Interruptor de migración separado (NO reutilizar `inventory_source`).**

- Nueva opción: `alegra_connector_inventory_manage_stock_enabled`, default
  **`false`**.
- Es **independiente** de `inventory_source`. `inventory_source` decide *quién
  manda*; este interruptor decide *si el plugin se anima a tocar la gestión de
  stock de WC*. Mezclarlos sería repetir el error de un solo control para dos
  cosas.
- Con el interruptor en `false`, la copia puede **leer y reportar** pero **no
  escribe** `_manage_stock` ni `_stock`.

**b) Simulación previa (dry-run) obligatoria.**

- Una corrida en seco que recorra los items de Alegra y, por cada producto WC
  vinculado, muestre una tabla de diferencias:

  | Producto | WC `_manage_stock` | WC `_stock` | Alegra (bodega/total) | ¿Qué cambiaría? |
  |---|---|---|---|---|
  | Camiseta | no | 12 | 7 | activar manage + stock 7 + status instock |
  | Gorra | no | 0 | (servicio) | manage=no, sin stock |
  | Pantalón | no | 5 | (bodega ausente) | SKIP + WARN |

- El comerciante **revisa la tabla** y recién entonces confirma. Nada se escribe
  antes.
- Reutilizar el patrón de "Modo de prueba" ya existente
  (`alegra_connector_dry_run`, `templates/admin-settings.php:218-221`), pero con
  un **dry-run específico de inventario** que no dependa del global (el global
  apaga *todo* el envío a Alegra, que es otra cosa). Opción:
  `alegra_connector_inventory_dry_run` (default `true`).

**c) Regla de seguridad por producto.**

Antes de escribir, evaluar cada producto:

| Condición | Acción |
|---|---|
| Tiene `_alegra_item_id` (está vinculado) | Candidato a escribir. |
| No está vinculado | **No tocar**. No hay contraparte en Alegra. |
| `_alegra_manage_stock_opt_out=yes` (C1) | **No tocar**. Excluido a propósito. |
| Ya tiene `_manage_stock=yes` y stock != 0 y el valor de Alegra difiere | **WARN + pedir confirmación explícita** (posible sobreescritura de dato real). |
| Es un servicio en Alegra (`inventory` ausente) | `manage=no`, sin stock. |
| La bodega configurada no está en el item | **SKIP + WARN**, nunca 0. |
| `availableQuantity` ausente/null | **SKIP + WARN**, nunca 0. |
| `availableQuantity` negativo (C5) | clamp a 0 + WARN. |

**d) Orden de la migración.**

1. Activar el interruptor opt-in (sigue en dry-run).
2. Correr la simulación y revisar la tabla.
3. Desactivar el dry-run de inventario y correr la copia real.
4. Verificar un puñado de productos en WC.

### 6.3 Cómo explicárselo al comerciante

> "Antes de copiar el stock de Alegra a tu tienda, el plugin te muestra una
> **lista de lo que va a cambiar** en cada producto. No toca nada hasta que vos
> digas 'dale'. Si algún producto ya tenía stock cargado a mano y el valor de
> Alegra es distinto, te lo marca para que decidas. Esto es para que no te
> pisemos un inventario que ya tenías bien."

---

## 7. Riesgos y verificación en vivo

**Ranking corregido por impacto real.** El criterio ya no es "cuánto bloquea el
diseño" sino **"cuánto daño hace hoy en producción"**. Por eso R2 pasa primero:
es el único que **ya está corriendo y corrompiendo datos**.

### R2 — `PUT /items` con `initialQuantity` ¿resetea el stock actual? **(EL FUEGO EN PRODUCCIÓN)**

- **Por qué es el #1:** el push reenvía `initialQuantity` en **cada**
  actualización de producto:
  - `prepare_simple_product_data()` → `Products.php:282`
  - `prepare_variation_data()` → `Products.php:361`
  - `apply_warehouse()` → `Products.php:440`

  Esos payloads se usan en `update_item()` en **cuatro** lugares:
  `Products.php:128`, `Products.php:141`, `Products.php:196`, `Products.php:230`.
  Es decir: **cada vez que se edita un producto en WooCommerce y el push está
  activo (o se usa el botón manual "Actualizar"), se manda `initialQuantity` al
  `PUT /items`.** Esto **ya está publicado** (v2.3.3) y corre hoy. Si
  `initialQuantity` resetea el stock, **cada edición corrompe el stock de
  Alegra**. No es una hipótesis de diseño: es una corrupción activa.
- **Estado:** **SIN VERIFICAR.** La doc describe `initialQuantity` como *"la
  cantidad inicial con la cual se creó el producto"*
  (https://developer.alegra.com/reference/put_items-id), lo que sugiere que no
  debería alterar el actual, pero **no lo garantiza**.
- **Verificación (30 s):**
  1. Item con stock actual 10 (haber vendido ya).
  2. `PUT /items/{id}` enviando `inventory.initialQuantity = 5` (sin tocar nada más).
  3. `GET /items/{id}?mode=advanced` → ¿`availableQuantity` sigue 10 o pasó a 5?
  4. **Si pasó a 5: bug crítico.** El push no debe enviar `initialQuantity` en
     updates (sección 5.9.4).

### R1 — ¿Una factura borrador mueve stock en Alegra? ¿Y `open`? **(PRERREQUISITO, no el fuego)**

- **Por qué importa:** de esto depende toda la sección 3 y la recomendación (a).
  Si el borrador mueve stock, el diseño se simplifica; si no, (a) es obligatoria.
- **Por qué NO es el #1:** su blast radius es **cero mientras el pull esté
  desconectado**, y hoy la copia está muerta. Es un prerrequisito del diseño, no
  un incendio operativo.
- **Estado:** comportamiento **SIN VERIFICAR** contra la app (la doc no lo dice
  explícitamente; ver `post_invoices`).
- **Verificación (30 s):**
  1. En Alegra, crear (o tomar) un item con stock conocido, ej. 10.
  2. `GET /items/{id}?mode=advanced` → anotar `inventory.availableQuantity` (10).
  3. `POST /invoices` con `status: "draft"` y 3 unidades de ese item.
  4. `GET /items/{id}?mode=advanced` → ¿sigue 10? **Si sigue 10: el borrador NO
     mueve stock (confirmado el problema).**
  5. `POST /invoices/{id}/open` (o `PUT` con `status: open`).
  6. `GET /items/{id}?mode=advanced` → ¿ahora 7? **Si es 7: `open` sí mueve stock.**

### R-CN — Notas crédito: el payload no envía `status` y la doc no lo documenta **(fusiona los viejos R3 y R5)**

- **Por qué importa:** el plugin construye la nota crédito en
  `create_credit_note_for_refund()` (`Orders.php:397`) y el payload
  (`Orders.php:485-493`) tiene **`date`, `client`, `invoices`, `items` — y ningún
  `status`**. La doc de `POST /credit-notes`
  (https://developer.alegra.com/reference/post_credit-notes) **tampoco documenta
  un campo `status`**. Si la misma regla "draft no mueve stock" aplica a notas
  crédito, entonces **un reembolso puede NO restaurar stock** en Alegra.
- **Dos subcasos que fusionan los riesgos viejos:**
  - **R3 (viejo):** ¿anular una factura restaura stock? (`void_invoice()`,
    `Orders.php:534`).
  - **R5 (viejo):** reembolso **parcial** usa una línea sin `id` de item
    (`Orders.php:461-470`: solo `name`, `price`, `quantity`). Si Alegra no asocia
    esa línea a un item, **no restaura stock**. El reembolso total sí reusa los
    items originales (`Orders.php:444-460`), así que ese caso está cubierto.
- **Estado:** **SIN VERIFICAR.** Es el riesgo más subestimado: el comerciante
  cree que reembolsar repone stock y puede que no.
- **Verificación (30 s):**
  1. Factura abierta por 3 (stock 10→7).
  2. Nota crédito **parcial** con una línea **sin `id`** (como hace el plugin).
  3. `GET /items/{id}` → ¿sube a 8/9/10 o queda 7?
  4. Repetir con nota crédito **total** (reusando items con `id`).
  5. Repetir con `void_invoice` sobre una factura abierta.

### R-HOOK — El "abrir al pagar" no puede dispararse en modo manual **(BLOQUEO ARQUITECTÓNICO)**

- **Por qué importa:** `woocommerce_payment_complete` y
  `woocommerce_order_status_completed` se registran **solo** dentro de
  `if (get_option('alegra_connector_push_orders_enabled', false))`
  (`Public_.php:49-59`; hooks en `Public_.php:50-52`). El default es `false`
  (`alegra-connector.php:372`). Por lo tanto, en la configuración de fábrica
  **los hooks no existen** y la opción (a) "abrir la factura cuando se paga" **no
  puede dispararse**. No es un detalle: es un bloqueo de la recomendación
  principal.
- **Estado:** **VERIFICADO por lectura de código** (no requiere prueba en vivo).
- **Resolución:** registrar los hooks de apertura **fuera** del `if`
  (sección 5.9.5). El handler solo **abre** un borrador existente; no factura,
  así que no rompe el modo manual.

### R-PULL — Escala, fallo y reanudación de la copia

- **Por qué importa:** la copia actual (`Products.php:467-557`) tiene:
  - **Truncación silenciosa** en `max_pages=200` × `limit=30` = **6000 items**
    (`Products.php:467`, `Products.php:495-499`). Un catálogo mayor se corta sin
    aviso (`Products.php:554` sale con `< 30`, pero el tope de 200 páginas corta
    antes si hay más de 6000).
  - **`save()` por item sin batching** (`Products.php:531-532`): N productos = N
    escrituras + N hooks.
  - **Sin rollback/resume**: si la API falla a mitad (`Products.php:501-504`),
    hace `break` y pierde el progreso; la próxima corrida empieza de cero.
  - **Sin contrato de idempotencia** explícito.
- **Estado:** **VERIFICADO por lectura de código.** No requiere cuenta real.
- **Resolución:** cursor persistente + reanudación + registro de resultados
  (sección 5.9.2). El batching es optimización, no corrección; se puede diferir.

### R4 — El campo `warehouse` en `POST /invoices` (no documentado)

- **Por qué importa:** `Orders.php:642-645` envía `$data['warehouse']` a la API,
  pero el esquema documentado de `POST /invoices` **no incluye** `warehouse` a
  nivel raíz (verificado en https://developer.alegra.com/reference/post_invoices).
  **Dato nuevo:** el esquema de `POST /credit-notes` **sí** documenta `warehouse`
  (https://developer.alegra.com/reference/post_credit-notes), lo que hace más
  raro que falte en facturas. Si Alegra lo ignora, la factura descuenta del
  **total** y no de la bodega configurada → divergencia con el pull por bodega.
- **Estado:** campo enviado por el plugin; aceptación **SIN VERIFICAR.**
- **Verificación (30 s):** crear una factura con `warehouse.id` explícito;
  `GET /items/{id}` y comparar `availableQuantity` total vs.
  `warehouses[id].availableQuantity`. ¿Bajó el total, la bodega, o ambos?

### R6 — `_stock_status` no se recalcula solo (WooCommerce)

- **Por qué importa:** si se escribe `_stock=0` sin `_stock_status=outofstock`, el
  producto sigue vendible. Es la causa raíz #2.
- **Estado:** verificado por el brief en el core de WooCommerce; **conviene
  reproducirlo** en el entorno de prueba con la versión de WC instalada.
- **Verificación (30 s):** en un producto de prueba, `set_stock_quantity(0); save();`
  y leer `get_stock_status()` **sin** setearlo. Si sigue `instock`, queda
  confirmado.

### R7 — Semántica de bodegas: ausencia vs. cero y tipo string

- **Por qué importa:** `inventory.warehouses[].availableQuantity` es **string**
  (`"150"`) según la doc, mientras `inventory.availableQuantity` es **integer**
  (https://developer.alegra.com/reference/get_items). La ausencia de la bodega
  puede significar "no asignada", no "cero". Escribir 0 por error marca todo como
  agotado.
- **Estado:** el tipo string está documentado; la semántica de ausencia
  **SIN VERIFICAR.**
- **Verificación (30 s):** en una cuenta con bodegas, tomar un item asignado solo
  a la bodega A; leer `warehouses[]` y ver si B aparece con `0` o **no aparece**.
  Eso define si "ausente" puede tratarse como cero (no debería).

### R8 — `WC_Product_Variable::sync()` y el estado del padre

- **Por qué importa:** si el padre de un variable no deriva su `_stock_status` de
  las variaciones, quedaría siempre "disponible" aunque todas las variaciones
  estén agotadas. Agravado por C2 (variaciones con `manage_stock` mixto).
- **Estado:** **SIN VERIFICAR.**
- **Verificación (30 s):** producto variable con todas las variaciones en 0;
  llamar a `sync()` y leer `get_stock_status()` del padre.

---

## 8. Decisiones pendientes

Cada una con opciones y recomendación. **La más urgente es verificar R2, no
decidir D1** (R2 puede estar corrompiendo producción ahora mismo).

| # | Decisión | Opciones | Recomendación |
|---|---|---|---|
| **D1** | **¿Cómo se resuelve la tensión borrador vs. stock?** | (a) factura atada al pedido · (b) forzar WC-wins · (c) siempre `open` · (d) WC-wins puro | **(a)**, con nueva opción `open_invoice_on_paid` (default `sí`), guardrail de advertencia, y hooks de apertura registrados **fuera** del gating de push (R-HOOK). |
| D2 | ¿Migración opt-in? | reutilizar `inventory_source` · opción nueva | **Opción nueva** `inventory_manage_stock_enabled`, default `false`. |
| D3 | ¿Cuándo se abre la factura? | al `processing` · al `completed` · al `payment_complete` | **Al confirmarse el pago** (`woocommerce_payment_complete`) y también al `completed`. Cubre el gap de pedidos que nunca llegan a completado (`docs/DOCUMENTACION.md:657`). Requiere R-HOOK resuelto. |
| D4 | ¿Se usa `create_inventory_adjustment()`? | cablearlo siempre · no usarlo · solo reconciliación manual | **Solo reconciliación manual opt-in en WC-wins.** No cablearlo en Alegra-wins. |
| D5 | Bodega configurada ausente del item | fallback al total · SKIP+WARN · escribir 0 | **SKIP + WARN.** Nunca escribir 0. |
| D6 | `_backorders` | no tocar · setear `no` · setear `notify` | **No tocar.** No es parte del sync. |
| D7 | La función muerta y el doble escritor | dejar `sync_inventory_from_alegra` muerta · registrarla y quitar la escritura de `update_product_from_alegra` | **Registrarla** (cron + botón + acción) y **quitar** la escritura de stock del import path, dejando **un solo escritor**. |
| D8 | ¿Qué hacer si `inventory_source=alegra` y las facturas quedan en borrador? | bloquear · advertir · ignorar | **Advertir** en el dashboard. No bloquear (config legítima), pero que sea consciente. |
| **D9** | **Modo manual con pedidos sin facturar: ¿qué hace el pull?** | (i) saltar el pull para productos con pedidos pendientes · (ii) correr igual y advertir · (iii) bloquear el pull hasta facturar | **(ii)** + informe visible de "pedidos vendidos sin facturar". No prometer precisión de stock mientras existan. |
| D10 | Producto `manage_stock=no` a propósito (C1) | forzar `yes` · opt-out por producto · regla global | **Opt-out por producto** (`_alegra_manage_stock_opt_out`). |
| D11 | Bodegas activadas sin bodega elegida (C6) | tratar como "sin bodega" · error · forzar Principal | **Tratar como "sin bodega"** + advertencia. Verificar semántica de "Principal". |

### Por qué la verificación de R2 es lo más urgente

R2 no define el diseño: define **si hay que hacer un hotfix ya**. El camino de
push está publicado y se ejecuta en cada edición de producto. Si `initialQuantity`
resetea el stock, hay comerciantes perdiendo inventario en Alegra en este momento,
y ninguna decisión de diseño (D1-D11) importa hasta apagarlo.

---

## 9. Registro de correcciones (A1–A6)

| # | Corrección | Dónde |
|---|---|---|
| A1 | El kill switch **no** está en `sync_inventory_from_alegra()` (`Products.php:447-545`); es una compuerta a **AGREGAR**, no existente. | Sección 2.7, 5.6 |
| A2 | R2 (initialQuantity en cada PUT) es el riesgo #1, **ya en producción**. R1 baja a prerrequisito. Nuevos riesgos: R-CN (notas crédito sin `status`), R-HOOK (gating de hooks), R-PULL (escala/fallo). | Sección 1, 7 |
| A3 | Se modela el camino por defecto: `push_orders_enabled=false` ⇒ **no hay factura**, divergencia con re-inflación. | Sección 3.4 |
| A4 | 7 celdas de configuración agregadas (C1–C7), con por qué y comportamiento. C6 **no** estaba cubierta; C7 sí. | Sección 4.6 |
| A5 | Se especifica el **DÓNDE**: archivo/método/hook/opción/llamadas WC y destino del código existente. | Sección 5.9 |
| A6 | D1 resuelto: qué método cambia (`prepare_invoice_data`, `Orders.php:614`), nuevo handler y gating de hooks. | Sección 5.1.1, 5.9.5 |

---

## Anexo A — Inventario de opciones relevantes

| Opción | Default | Definida en | Leída en |
|---|---|---|---|
| `alegra_connector_inventory_source` | `alegra` | `alegra-connector.php:384` | `Products.php:453` (único lector productivo) |
| `alegra_connector_invoice_status` | `draft` | `alegra-connector.php:386` | `Orders.php:614` |
| `alegra_connector_push_orders_enabled` | `false` | `alegra-connector.php:372` | `Public_.php:49` (gating de hooks) |
| `alegra_connector_warehouse_enabled` | (sin default explícito) | UI `admin-settings.php:160` | `Products.php:420`, `Orders.php:643` |
| `alegra_connector_warehouse_id` | `''` | UI `admin-settings.php:163-171` | `Products.php:423`, `Orders.php:642` |
| `alegra_connector_auto_complete_order` | `true` | UI `admin-settings.php:360` | `Orders.php:1277`, `Handlers.php:170` |
| `alegra_connector_sync_products` | `false` | `alegra-connector.php:374` | `Controller.php:107` |
| `alegra_connector_sync_method` | `cron` | `alegra-connector.php:371` | `alegra-connector.php:498`, `Controller.php:65` |
| `alegra_connector_dry_run` | `false` | `alegra-connector.php:387` | `Client` (guard de escritura global) |
| `alegra_connector_inventory_manage_stock_enabled` **(NUEVA)** | `false` | (a registrar) | `Inventory_Writer` (sección 6) |
| `alegra_connector_inventory_dry_run` **(NUEVA)** | `true` | (a registrar) | `sync_inventory_from_alegra()` |
| `alegra_connector_open_invoice_on_paid` **(NUEVA)** | `true` | (a registrar) | `Orders::prepare_invoice_data()` |

## Anexo B — Rutas de código citadas

| Ruta | Rol |
|---|---|
| `includes/Sync/Products.php:447` | `sync_inventory_from_alegra()` — copia de stock (muerta, sin kill switch). |
| `includes/Sync/Products.php:453` | Única lectura de `inventory_source`. |
| `includes/Sync/Products.php:459` | Lock atómico del pull. |
| `includes/Sync/Products.php:467,495-499,554` | Tope de páginas y truncación (R-PULL). |
| `includes/Sync/Products.php:524-532` | Cast `(int)`, guard `get_manage_stock`, escritura por item. |
| `includes/Sync/Products.php:282,361,440` | `initialQuantity` en el push (R2 — riesgo #1). |
| `includes/Sync/Products.php:128,141,196,230` | `update_item()` (PUT) donde viaja `initialQuantity`. |
| `includes/Sync/Products.php:608,648` | Kill switch SÍ presente en `import_from_alegra()` (modelo a copiar). |
| `includes/Sync/Products.php:1152,1203-1209` | `update_product_from_alegra()` — segundo escritor a eliminar. |
| `includes/Sync/Products.php:1626,1652` | `sync_single_item_by_alegra_id()` / `get_product_by_alegra_id()`. |
| `includes/Sync/Orders.php:35,614` | `create_invoice()` y resolución de estado (D1/A6). |
| `includes/Sync/Orders.php:238-247` | `create_invoice_with_payment()` — override `open` condicionado a `payment_account_id`. |
| `includes/Sync/Orders.php:322` | `ensure_invoice_open()`. |
| `includes/Sync/Orders.php:397,485-493` | `create_credit_note_for_refund()` — payload sin `status` (R-CN). |
| `includes/Sync/Orders.php:444-470` | Reembolso total (reusa items) vs. parcial (línea sin `id`). |
| `includes/Sync/Orders.php:642-645` | `warehouse` en `POST /invoices` (R4). |
| `public/Public/Public_.php:49-59` | Hooks de pedido gated por `push_orders_enabled` (R-HOOK). |
| `includes/Sync/Controller.php:120` | El cron llama a `import_from_alegra()`. |
| `includes/Sync/Controller.php:327` | `acquire_lock()` atómico. |
| `includes/Kill_Switch.php:51` | `is_active()`. |
| `includes/API/Client.php:648` | `create_inventory_adjustment()` (0 llamadores). |
| `includes/API/Client.php:670` | `open_invoice()`. |
| `includes/Webhooks/Handlers.php:76-112` | `handle_delete_item()` — tombstone de item borrado en Alegra (C3). |
| `templates/admin-settings.php:93` | UI "Fuente de inventario". |
| `templates/admin-settings.php:160-171` | UI bodegas (opción `value="0"`, C6). |
| `templates/admin-settings.php:209-214` | UI "Estado de las facturas". |
| `admin/Admin/Admin_Dashboard.php:340` | Registro de `inventory_source`. |
| `docs/RELEASE_2.1.9_DEPLOY.md:359` | Acción `alegra_sync_inventory_from_alegra` documentada y no registrada. |

## Anexo C — URLs de documentación de Alegra citadas

| Tema | URL | Estado |
|---|---|---|
| Listado de items (`mode=advanced`, `availableQuantity` int, `warehouses[]` string, `idWarehouse`, `inventariable`, `inventory` presente=inventariable) | https://developer.alegra.com/reference/get_items | Verificada (2026-09-17) |
| Crear factura (`status: open/draft`, `payments`) | https://developer.alegra.com/reference/post_invoices | Verificada (2026-09-17) |
| Editar item (`initialQuantity` = "cantidad inicial con la cual se creó"; sin `required` a nivel schema) | https://developer.alegra.com/reference/put_items-id | Verificada (2026-09-17) |
| Editar item — MCP (`required: [id]`; *"Solo enviar los campos que cambiarán"*; `inventory` opcional) | https://developer.alegra.com/reference/items__updateitem | Verificada (2026-09-17) |
| Crear item — MCP (`required: [name, type, price]`) | https://developer.alegra.com/reference/items__createitem | Verificada (2026-09-17) |
| Crear nota crédito (**sin campo `status`**; `warehouse` documentado) | https://developer.alegra.com/reference/post_credit-notes | Verificada (2026-09-17) |
| Crear ajuste de inventario | https://developer.alegra.com/reference/post_inventory-adjustments | Citada por el repo |
| Listado de bodegas | https://developer.alegra.com/reference/get_warehouses | Citada por el repo |
| Abrir factura (`POST /invoices/{id}/open`) | https://developer.alegra.com/reference/post_invoices-id-open | **SIN VERIFICAR** (URL exacta) |

---

*Fin del documento. Diseño sujeto a las decisiones de la sección 8. El plan de
implementación vive en `docs/sdd/inventory/` (proposal, spec, design, tasks).*
