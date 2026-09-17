# Verificación en Producción — Alegra Connector 2.3.5

Esta guía es la **lista de validación** de la versión 2.3.5. La 2.3.5 **corrige
el modo de prueba (Dry Run)**: el marcador que devuelve una escritura bloqueada
es un arreglo, así que `is_wp_error()` lo dejaba pasar como éxito y **cuatro
caminos guardaban estado de operaciones que nunca ocurrieron** (pago registrado,
nota crédito creada, método de pago actualizado, webhooks borrados). Con Dry Run
encendido el panel decía "listo" sin haber escrito nada en Alegra. La 2.3.5
también hace que el push de productos variables devuelva el marcador de Dry Run
en vez de un error engañoso, y que la importación de productos use la **lista de
precios configurada** en vez de la lista 1. La sección nueva y más importante de
esta versión es **★★ (Dry Run)**.

Esta guía **conserva** los ítems de la 2.3.4 (push de productos simples y
variables, sección ★), de la 2.3.3 (subida de pedidos automática vs manual,
sección 0) y de la 2.3.2 (eliminación de la emisión DIAN). El plugin solo crea el
documento en Alegra; el timbrado, si lo necesitás, se hace en Alegra.

La base sigue siendo la 2.3.1, que corrigió los 68 hallazgos de la auditoría
(lotes 0–3) sobre la 2.3.0.

> **El riesgo más alto de esta versión está en la sección ★★ (Dry Run): el modo
> de prueba reportaba éxito sin escribir nada. Leela primero.**
>
> El push de productos (sección ★, de la 2.3.4) sigue sin probarse en vivo, y el
> ítem 5 (CO + facturación electrónica) sigue siendo el otro riesgo alto.

La 2.3.0 se construyó contra la **documentación oficial** de Alegra y de
WooCommerce, pero **nueve comportamientos concretos nunca se probaron contra la
API real de Alegra ni contra una tienda real**. La auditoría posterior resolvió
parte de ellos:

- **3 resueltos por la documentación** (2, 3 y 6): ya no hace falta probarlos.
- **2 resultaron ser bugs** (8 y 9): no eran "verificar", eran errores reales,
  y quedaron **corregidos** en la 2.3.1.
- **3 siguen necesitando prueba en vivo** (1, 4 y 7).
- **1 es nuevo y es el más importante** (5): que una cuenta **con facturación
  electrónica habilitada** acepte el contacto mínimo (sin
  `kindOfPerson`/`regime`).


> **Sé honesto contigo mismo:** los ítems 1, 4, 5 y 7 **no** están "probados en
> producción". Están implementados según la documentación y con el mayor
> cuidado, pero la única prueba válida es tu cuenta real de Alegra y tu tienda
> real. Si algo falla, el detalle importa más que el "sí funcionó".

**Guía complementaria:** este documento **no** repite cómo instalar ni cómo
revertir. Eso está en [`RELEASE_2.3.0_DEPLOY.md`](./RELEASE_2.3.0_DEPLOY.md)
(secciones 2, 3, 4 y 6). Acá solo se valida. La guía de despliegue cubre la
**instalación y el rollback**; esta cubre la **verificación funcional**.

- Dónde ver los **logs:** `Alegra Connector → Logs`. En disco:
  `wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`.
- Dónde ver el **estado de facturación:** `Alegra Connector → Dashboard` →
  tarjeta **"Estado de facturación"**.
- Dónde cambiar los **ajustes:** `Alegra Connector → Configuración` →
  pestaña **"Datos de facturación"**.

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Alto** | Si falla, la factura sale con el **destinatario equivocado**, no se factura, o se factura sin querer. **Bloquea** el uso real de la 2.3.5 hasta resolverse. |
| **Medio** | Si falla, una función secundaria (categorías, checkout) queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. Se puede convivir con él; el poll/fallback cubre la mayoría. |

---

## ★★ Dry Run: cómo usarlo bien — ⚠️ LO NUEVO Y MÁS IMPORTANTE DE LA 2.3.5

**Estado: NO probado en vivo. Requiere prueba en vivo.**

El modo de prueba **no escribe nada en Alegra**: bloquea todo `POST`/`PUT`/`DELETE`
y devuelve un marcador. Antes de la 2.3.5 ese marcador se confundía con un éxito y
el panel mostraba "pago registrado" / "nota crédito creada" sin haber hecho nada.
Ahora cada camino avisa que fue una **simulación**. El flujo correcto es:

1. **Activá el modo de prueba.** En `Alegra Connector → Configuración →
   Sincronización`, marcá **"Modo de prueba"**. El Dashboard lo refleja.
2. **Hacé un pedido de prueba** de bajo valor y completá el pago.
3. **Verificá el mensaje honesto.** El pedido debe mostrar una nota del tipo
   **"Alegra (modo de prueba): … no se registró / no se creó …"** y el panel
   **no** debe decir que la operación se completó. En `Alegra Connector → Logs`
   buscá `[DRY RUN] Blocked POST ...`; ese es el marcador de que no se escribió.
4. **Confirmá que Alegra no cambió:** en **Alegra → Facturas / Pagos /
   Webhooks**, no debe haber ningún registro nuevo.
5. **Desactivá el modo de prueba** y **hacé el pedido real** (o facturá el pedido
   de prueba). Ahora sí la factura, el pago y las notas deben crearse en Alegra.

- **Riesgo: Alto (regresión).** Si Dry Run vuelve a reportar éxito falso, el
  comerciante cree que validó la integración cuando en realidad no se escribió
  nada. La única prueba válida es que el mensaje diga **modo de prueba / no se
  registró** y que Alegra no haya cambiado.

---

## ★ Push de productos a Alegra (simple y variable) — ⚠️ LO NUEVO DE LA 2.3.4, SIGUE VIGENTE

**Estado: NO probado en vivo. El push simple estaba roto (Alegra lo habría
rechazado) y el variable nunca funcionó. Requiere prueba en vivo.**

La 2.3.4 alinea el payload con el esquema documentado. Los dos cambios que hay
que probar sí o sí:

### ★.a — Push de un producto SIMPLE

1. Creá o elegí un **producto simple** en WooCommerce con precio, SKU y stock.
   Si podés, cargale un **costo** (meta `_wc_cog_cost` o `_cost`).
2. Empujalo a Alegra (el push de productos del plugin).
3. **Resultado esperado:** el ítem **se crea** en Alegra (`GET /items`), con
   `type: product`, la `inventory.quantity` inicial y un `inventory.unitCost`
   (el costo cargado, o `0` si no hay meta de costo). Antes de la 2.3.4 Alegra
   lo **rechazaba** por `type: 'simple'`.
4. Editá el producto en WooCommerce (cambiá el nombre) y volvé a empujarlo.
5. **Resultado esperado:** el ítem se actualiza y el **stock de Alegra NO se
   resetea** — la 2.3.4 ya no envía `inventory.initialQuantity` en el update.
6. **Si falla:** revisá `Alegra Connector → Logs`; el 400 de Alegra suele decir
   qué campo rechazó (el mock de tests ya valida el esquema, pero la cuenta real
   es la única prueba válida).

### ★.b — Push de un producto VARIABLE (2+ variaciones)

1. Creá un **producto variable** en WooCommerce con **al menos 2 variaciones**
   (por ejemplo Talla S/M), cada una con precio y stock.
2. Empujalo a Alegra.
3. **Resultado esperado:** se crea el **padre** (`variantParent`) con sus
   `variantAttributes` y **una entrada `itemVariants` por variación**, y los IDs
   de los hijos devueltos por Alegra quedan **mapeados a las variaciones de
   WooCommerce**.
4. **Si falla:** antes de la 2.3.4 esto **nunca** funcionó (el padre omitía
   `variantAttributes` y usaba el campo `subitems`, que es solo para kits). Si
   Alegra rechaza el payload, reportá el mensaje textual.
- **Riesgo: Alto.** Si el push simple no crea el ítem, ningún producto llega a
  Alegra; si el variable crea hijos sueltos en vez del padre con variantes, el
  inventario queda desalineado.

> **Diseño completo:** el diseño y la spec de inventario (todavía **pendiente**
> de implementar el **pull**) están en [`docs/sdd/inventory/`](./sdd/inventory/)
> y el análisis en [`docs/INVENTORY_DESIGN.md`](./INVENTORY_DESIGN.md).

---

## 0. Subida de pedidos: automática vs manual — ⚠️ CAMBIO DE LA 2.3.3, SIGUE VIGENTE

La 2.3.3 separa dos controles que antes se pisaban. Cada uno hace **una sola
cosa**, y son **independientes**:

| Control | Dónde | Qué gobierna |
|---|---|---|
| **Método de sincronización** | Configuración → Sincronización → "Método" | **Solo la entrante** (Alegra → WooCommerce): periódica, tiempo real, ambas o desactivada. **No** sube pedidos. |
| **Subir pedidos a Alegra** | Configuración → Sincronización → "Subir pedidos a Alegra" | **Solo la saliente** (WooCommerce → Alegra). Por defecto **Manual (desactivado)**. |

Antes, el toggle automático quedaba anulado cuando el método era `cron` (el
default de la UI), así que marcarlo no hacía nada. Ese es el bug que esta
versión corrige.

### 0.a — Verificar el camino AUTOMÁTICO DESACTIVADO (el default)

1. En `Alegra Connector → Configuración → Sincronización`, confirmá que
   **"Subir pedidos a Alegra" está DESMARCADO** (es el default de una
   instalación nueva). El Dashboard lo refleja.
2. Creá un **pedido de prueba** en WooCommerce y **completá el pago**.
3. Esperá unos segundos y mirá **Alegra → Facturas**.
4. **Resultado esperado:** **no** aparece ninguna factura nueva. El pedido queda
   como pendiente de facturar; nada se sube solo.
5. **Si falla** (aparece una factura): el toggle quedó en automático o hay algo
   que sube pedidos. Revisá `Alegra Connector → Logs` y la configuración de
   subida.

### 0.b — Verificar el camino MANUAL

1. En **WooCommerce → Pedidos**, abrí el pedido de prueba y entrá al detalle.
2. Hacé clic en **"Crear factura"** (en el listado de pedidos el botón dice
   **"Facturar"**; también existen "Facturar seleccionados" y "Facturar
   pendientes").
3. **Resultado esperado:** la factura aparece en **Alegra → Facturas** (en
   borrador por defecto), vinculada al contacto correcto, **sin depender** de
   que la subida automática esté activada.
4. **Si falla:** revisá los logs; el mensaje de Alegra suele decir qué campo
   rechazó. Este camino es independiente del toggle automático por diseño.

- **Riesgo: Alto (regresión).** El bug original era que el toggle automático no
  hacía nada con el método en `cron`. Si el default manual no se respeta, se
  factura sin querer; si el manual se rompe, no se puede facturar.

---

## 1. `edit-item` no dispara con cambios solo de stock

- **Qué se asume:** que Alegra **no** dispara el webhook `edit-item` cuando lo
  único que cambia en un producto es el **inventario**. Por eso la
  sincronización de stock en tiempo real no depende del webhook: cae en el
  **poll** (la sincronización programada, por defecto cada 15 minutos, método
  `both`). El handler de `edit-item` existe igual
  (`includes/Webhooks/Handlers.php:32`), por si Alegra sí lo dispara.
- **Por qué importa:** si Alegra no dispara el webhook **y** el poll estuviera
  desactivado o roto, el stock de WooCommerce quedaría desactualizado respecto
  a Alegra, y podrías vender sin existencias.
- **Cómo verificarlo:**
  1. En Alegra, entra a un producto y **cambia solo el stock** (deja nombre y
     precio intactos). Guarda.
  2. Espera unos segundos y revisa `Alegra Connector → Logs`. Busca
     `Processing webhook event` con `"event": "edit-item"`. Si aparece otro
     evento, el log lo dirá (`Unhandled webhook event`).
  3. Mira el mismo producto en **WooCommerce → Productos**: ¿cambió el stock?
     Si cambió en segundos, el webhook funcionó. Si no, espera a que corra la
     sincronización programada (hasta 15 min por defecto) y vuelve a mirar.
  4. Para forzarla, usa **Alegra Connector → Dashboard → "Traer productos
     desde Alegra"**.
- **Resultado esperado:** el stock de WooCommerce termina actualizado. Puede
  ser **por webhook** (inmediato) **o por poll** (hasta el intervalo
  configurado). Las dos vías son correctas.
- **Si falla:** si ni el webhook ni el poll actualizan el stock, revisa en
  `Alegra Connector → Monitor` que la sincronización programada esté
  registrada, y en `Alegra Connector → Configuración → Sincronización` que la
  frecuencia no esté desactivada. Mientras tanto, el botón "Traer productos
  desde Alegra" es el parche manual.
- **Riesgo: Bajo.** No bloquea el release: el poll es la red de seguridad.

---

## 2. `idItemCategory` con múltiples valores — ✅ RESUELTO (documentación)

**Estado: resuelto. Ya no hace falta probarlo en vivo.**

- **Qué se asumía:** si Alegra aceptaba un **arreglo** de categorías por
  producto. El plugin envía **una sola** (`includes/Sync/Products.php`:
  `'category' => ['id' => ...]`).
- **Qué dice la documentación:** en `GET /items`, `idItemCategory` es un
  **parámetro de filtro** con `"type": "string"` — un **único** valor, no un
  arreglo. Y el ítem expone `category` como **un objeto** `{id, name}`, también
  singular.
  - https://developer.alegra.com/reference/get_items
- **Conclusión:** Alegra acepta **una sola** categoría por ítem. El push de una
  categoría del plugin es correcto. Un producto de WooCommerce con dos
  categorías queda clasificado en Alegra en **una** (comportamiento esperado,
  no un bug).

---

## 3. El push de categoría acepta `{id}` — ✅ RESUELTO (documentación)

**Estado: resuelto. Ya no hace falta probarlo en vivo.**

- **Qué se asumía:** que el endpoint de productos acepta la categoría como
  **objeto** `['id' => $alegra_cat_id]` en vez de un nombre plano.
- **Qué dice la documentación:** el ítem documenta `category` como un objeto con
  `id`/`name`, tanto al listar (`GET /items`) como al consultar uno
  (`GET /items/{id}`). El plugin primero crea la categoría
  (`create_item_category`, `includes/Sync/Products.php`) y luego la referencia
  por `id` en el producto.
  - https://developer.alegra.com/reference/get_items
  - https://developer.alegra.com/reference/get_items-id
- **Conclusión:** el formato `{ id }` es el documentado. La implementación es
  correcta.

---

## 4. Ruta `customer.address.<field>` en Blocks

- **Qué se asume:** que el **checkout por bloques** (Checkout Blocks) resuelve
  bien la ruta de los campos adicionales
  `customer.address.<namespace>/<field>` (`alegra-connector/dv`,
  `alegra-connector/company`, etc.). Las condiciones se escribieron contra la
  documentación y el esquema oficial de WooCommerce
  (`includes/Checkout_Integration.php:253`), pero **no** contra un checkout
  Blocks real con este plugin.
- **Por qué importa:** si la ruta no resuelve, los campos condicionales **no se
  muestran ni se ocultan** como corresponde: el Dígito de verificación podría
  pedirse sin ser NIT, o la Razón social no aparecer para una empresa. Eso
  produce facturas con datos incompletos.
- **Cómo verificarlo:**
  1. Asegúrate de que tu página de checkout use el **editor de bloques** (no
     el shortcode clásico). La página de checkout en Blocks es la que se edita
     con el editor de bloques de WordPress.
  2. Llega al checkout con un producto en el carrito.
  3. En **Tipo de documento**, elige **NIT** → debe **aparecer** el campo
     **"Dígito de verificación"**. Elige cualquier otro tipo → debe
     **desaparecer**.
  4. En **Tipo de persona**, elige **Persona Jurídica (empresa)** → debe
     **aparecer** **"Razón social"**. Elige **Persona Natural** → debe
     **desaparecer**.
  5. Repite en la página **"Mi cuenta"** si el cliente edita su dirección.
- **Resultado esperado:** los campos aparecen y desaparecen según lo elegido,
  igual que en el checkout clásico.
- **Si falla:** es el primer sitio a revisar (lo dice la guía de despliegue).
  Anota **qué campo**, **qué valor** y si quedó visible u oculto cuando no
  correspondía. El problema estará en la ruta del esquema o en el namespace
  `alegra-connector/`.
- **Riesgo: Medio.** No impide facturar, pero puede generar facturas con datos
  mal capturados si el cliente no ve el campo que necesita.

---

## 5. CO + Facturación Electrónica: ¿Alegra acepta el contacto mínimo? — ⚠️ EL RIESGO MÁS ALTO

**Estado: NO probado. Requiere prueba en vivo. Si falla, es silencioso.**

- **Qué se asume:** que una cuenta de Alegra **colombiana con facturación
  electrónica habilitada** acepta el contacto **mínimo** que envía el plugin
  (sin `kindOfPerson` ni `regime`). La documentación oficial define un esquema
  de contacto aparte — "con facturación electrónica" — que **sí** exige esos
  campos; el plugin los eliminó en la 2.3.2 porque el esquema de contacto
  "pelado" no los pide.
- **Por qué importa:** si la cuenta con FE rechaza el contacto mínimo, la
  creación del contacto **falla**, el plugin cae al respaldo **Consumidor
  Final** y **la factura sale a nombre del genérico en vez del cliente** —
  exactamente lo que la resolución de clientes de la 2.3.1 buscaba evitar.
  Falla **en silencio**: el fallback lo enmascara.
- **Cómo verificarlo:**
  1. Hacé un pedido de prueba con un cliente que **tenga** identificación
     (cédula/NIT) registrada.
  2. En Alegra, abrí la factura creada y mirá **a nombre de quién** quedó:
     ¿el **cliente** o **Consumidor Final**?
  3. Revisá las **notas del pedido** en WooCommerce. La nueva nota de la 2.3.2
     dice explícitamente si se cayó al Consumidor Final y qué campo faltaba.
- **Resultado esperado:** la factura sale a nombre del **cliente** y **no**
  aparece la nota de Consumidor Final.
- **Si falla (cayó al Consumidor Final):** la cuenta tiene facturación
  electrónica habilitada y necesita `regime`/`kindOfPerson`. **Reportalo** para
  restaurar esos dos campos de forma condicional (solo cuando la cuenta los
  exija).
- **Riesgo: Alto.** Produce en silencio una factura con el **destinatario
  equivocado**.

---

## 6. `invoices: [{id, amount}]` en notas de crédito — ✅ RESUELTO (documentación)

**Estado: resuelto. Ya no hace falta probarlo en vivo.**

- **Qué se asumía:** que Alegra acepta el arreglo **plural** `invoices` con
  `{id, amount}` para asociar la nota crédito a la factura original.
- **Qué dice la documentación:** `POST /credit-notes` define `invoices` como un
  **array** de objetos `{ id, amount }`; para timbrar, `amount` puede ser
  parcial o igual al saldo y **la suma debe coincidir con el total** de la nota
  crédito. El plugin envía exactamente esa forma (`includes/Sync/Orders.php`).
  - https://developer.alegra.com/reference/post_credit-notes
- **Conclusión:** el formato es el documentado. La implementación es correcta.

---

## 7. Consumidor Final debe pre-existir en Alegra

- **Qué se asume:** que el contacto **"Consumidor Final"** ya existe en tu
  cuenta de Alegra con estos datos exactos: identificación
  **`222222222222`** y tipo **`CC`**. El plugin lo **busca pero nunca lo crea**
  (`includes/Consumidor_Final.php:20`). Es el respaldo cuando un pedido no
  trae datos de facturación suficientes.
- **Por qué importa:** si el contacto no existe y el pedido no tiene datos, la
  factura **se aborta** en vez de usar el genérico. En modo "Exigir datos al
  cliente" ni siquiera hay respaldo.
- **Cómo verificarlo:**
  1. En Alegra → **Contactos**, busca por identificación `222222222222` o por
     nombre `Consumidor Final`.
  2. Ve a `Alegra Connector → Dashboard` → tarjeta **"Estado de facturación"**
     → fila **"Consumidor Final"**. Debe estar en verde y decir
     **"Disponible"**.
- **Resultado esperado:** la fila muestra **"Disponible"** (verde).
- **Si falla:** el widget dirá **"No encontrado en Alegra"** (ámbar) y te
  pedirá crearlo. Créalo a mano con los datos exactos de arriba. Si prefieres
  usar otro contacto, actívalo en
  `Alegra Connector → Configuración → Datos de facturación → "Consumidor
  Final manual"`.
- **Riesgo: Alto.** Sin Consumidor Final, los pedidos sin datos completos no se
  facturan.

---

## 8. HPOS (almacenamiento de pedidos de WooCommerce) — 🐛 ERA UN BUG → CORREGIDO

**Estado: era un bug real, no una suposición. Corregido en la 2.3.1.**

- **Qué se asumía:** que la versión anterior funcionaba con **HPOS**
  (High-Performance Order Storage). El plugin usaba la API oficial de
  WooCommerce en la mayoría de los caminos, pero **no en todos**.
- **Qué estaba mal:** una lectura de meta de pedido en el panel
  (`Admin_Dashboard.php:1890`, AC-34) seguía usando `get_post_meta()` en vez de
  la API CRUD de WooCommerce. Bajo HPOS, `get_post_meta()` no ve el meta del
  pedido, así que **todos los pedidos se clasificaban como pendientes**. (No
  duplicaba facturas, porque la creación sí era idempotente, pero el estado que
  veías era incorrecto.)
- **Qué se corrigió:** esa lectura ahora usa `$order->get_meta()`, que funciona
  con almacenamiento clásico y con HPOS. Ya no hay que "verificar" esto: era un
  defecto de código y está arreglado. Vale la pena confirmar en tu tienda que
  un pedido ya facturado se ve como facturado.

---

## 9. El cap de reembolsos usaba un transient lock, no una transacción — 🐛 ERA UN BUG → CORREGIDO

**Estado: era un bug real, más grave de lo descrito. Corregido en la 2.3.1.**

- **Qué se asumía:** que el tope acumulado de reembolsos
  (`_alegra_credited_amount`) no se iba a romper con reembolsos simultáneos
  porque el caso borde era poco probable.
- **Qué estaba mal:** la carrera era real y peor de lo descrito. Los locks no
  eran atómicos (`get_transient` + `set_transient`, AC-03): dos workers podían
  leer vacío, ambos escribir y ambos ganar. Y el camino de nota crédito sin
  guardia **nunca** actualizaba `_alegra_credited_amount` (AC-09). Resultado:
  reembolsos concurrentes podían acreditar de más, por encima del
  total de la factura.
- **Qué se corrigió:** los locks ahora usan un **compare-and-swap** sobre
  `add_option()` (`wp_options.option_name` es UNIQUE), con reclamación de locks
  vencidos, y todos los sitios liberan en un bloque `finally`. Además, un solo
  dueño crea la nota crédito por reembolso (una sola clave de idempotencia) y el
  tope acumulado se actualiza siempre. Ya no hay que "probar dos reembolsos a la
  vez": el mecanismo es atómico. Podés revalidarlo si querés, pero ya no es un
  riesgo conocido.

---

## Orden de prueba recomendado

1. **Pre-vuelo (sin tocar producción):** respaldos, `sha256` del ZIP y smoke
   test. Ver `RELEASE_2.3.0_DEPLOY.md` §2.
2. **Desplegar** e instalar la 2.3.5. Ver `RELEASE_2.3.0_DEPLOY.md` §3.
3. **Sección ★★ — Dry Run (lo nuevo de la 2.3.5).** Activá "Modo de prueba",
   hacé un pedido de prueba y confirmá que los mensajes digan **modo de prueba /
   no se registró** y que **nada** se cree en Alegra. Después **desactivalo**.
   Es el flujo que valida todo antes de ir a producción.
4. **Sección ★ — push de productos (simple y variable).** Es lo nuevo de la
   2.3.4 y sigue vigente: empujá un producto simple y uno variable con 2+
   variaciones y confirmá que se crean en Alegra. Antes de la 2.3.4 el simple se
   rechazaba y el variable nunca funcionaba.
5. **Ítem 0 — automático OFF (default) y manual.** Creá un pedido y confirmá
   que **no** se factura solo; después usá "Crear factura" y confirmá que sí
   aparece. Es el cambio de la 2.3.3, sigue vigente.
6. **Ítem 5 — CO + facturación electrónica (el más importante).** Pedido de
   prueba con un cliente que **tenga** identificación; confirmá en Alegra que la
   factura sale a nombre del **cliente** y que **no** aparece la nota de
   Consumidor Final. Si cayó al genérico, reportalo.
7. **Ítem 7 — Consumidor Final.** Solo lectura en Alegra y el widget. Si falta,
   se crea a mano antes de facturar.
8. **Ítem 4 — condicionales de Blocks.** Aprovéchalos en el mismo checkout de
   prueba del paso anterior.
9. **Ítem 1 — webhook/poll de stock.** Cambia stock en Alegra y observa.
10. **Pedido real de bajo valor.** Acá empieza lo que toca dinero real: confirma
    que la factura se crea en Alegra (en borrador por defecto), que queda
    vinculada al cliente correcto y que **no** aparece la nota de Consumidor
    Final.
11. **Reembolso parcial** del pedido anterior → confirma que se crea la nota
    crédito ligada a la factura (el formato es el documentado, ítem 6; el cap es
    atómico, ítem 9).

---

## Qué hacer si algo falla

1. **Revisa primero los logs:** `Alegra Connector → Logs` (y en disco,
   `wp-content/uploads/alegra-logs/`). El mensaje de Alegra suele decir
   exactamente qué campo rechazó.
2. **Anota el caso:** pedido, hora, qué hiciste y el mensaje textual. Sin eso,
   no se puede diagnosticar.
3. **Rollback a 2.2.0** (el procedimiento completo está en
   `RELEASE_2.3.0_DEPLOY.md` §6):
   - Desactiva **"Alegra Connector"** en **Plugins**.
   - Reemplaza la carpeta por `releases/alegra-connector-v2.2.0.zip`.
   - Reactiva el plugin.
   - **Ojo:** la 2.3.0 migró las columnas `alegra_id` de `BIGINT` a
     `VARCHAR(36)`; eso **no** se revierte. Con IDs numéricos, 2.2.0 sigue
     funcionando.
4. **Estado de las facturas:** por defecto se crean como **borrador** para que
   las revises antes de emitirlas. Si querés que se creen abiertas, cambialo en
   `Alegra Connector → Configuración → Datos de facturación`.
5. **No borres** pedidos, notas crédito ni facturas de Alegra para "limpiar":
   primero entiende qué pasó.

---

## Checklist final

### Antes de empezar
- [ ] Respaldo de base de datos y de archivos hecho.
- [ ] `sha256` del ZIP coincide con el `.sha256`.
- [ ] Smoke test del ZIP termina en `SMOKE OK`.
- [ ] 2.3.5 instalado y la versión figura como **2.3.5** en **Plugins**.

### Lo nuevo de la 2.3.5 (Dry Run)
- [ ] **★★** Con "Modo de prueba" activo, un pedido de prueba **no** escribe en
      Alegra y el mensaje dice **modo de prueba / no se registró**.
- [ ] **★★** Al desactivarlo, el pedido real **sí** crea la factura y el pago.

### Push de productos — 2.3.4, sigue vigente
- [ ] **★.a** Producto simple empujado → **se crea** el ítem en Alegra con
      `type: product` y `unitCost` (o `0`); editarlo **no** resetea el stock.
- [ ] **★.b** Producto variable con 2+ variaciones → se crea el **padre** con
      `variantAttributes` + `itemVariants` y los hijos quedan mapeados.

### El cambio de la 2.3.3 (automático vs manual)
- [ ] **0.a** "Subir pedidos a Alegra" **desmarcado** (default) → un pedido de
      prueba **no** genera factura en Alegra.
- [ ] **0.b** "Crear factura" en el pedido → la factura **sí** aparece en Alegra.

### Las 4 verificaciones que quedan
- [ ] **1.** Cambio de stock en Alegra → el stock de WooCommerce se actualiza
      (webhook o poll).
- [ ] **4.** En Blocks: al elegir NIT aparece el Dígito de verificación (y se
      oculta con los demás tipos de documento).
- [ ] **5.** CO + FE: la factura sale a nombre del **cliente** (no de Consumidor
      Final) y **no** aparece la nota de fallback.
- [ ] **7.** Consumidor Final: widget en verde **"Disponible"**.

### Ya resueltos (no requieren prueba en vivo)
- [x] **2.** `idItemCategory` es un valor único; el push de una categoría es
      correcto (documentación).
- [x] **3.** El push de categoría acepta `{id}` (documentación).
- [x] **6.** `invoices: [{id, amount}]` es la forma documentada (documentación).
- [x] **8.** HPOS: era un `get_post_meta()` en el panel; corregido.
- [x] **9.** Cap de reembolsos: locks no atómicos; corregidos.

### Cierre
- [ ] "Modo de prueba" **desactivado**.
- [ ] "Estado de las facturas" en el estado deseado (borrador por defecto).
- [ ] Logs revisados y sin errores `[Alegra]` repetidos.
