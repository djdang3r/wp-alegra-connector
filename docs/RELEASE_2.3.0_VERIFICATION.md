# Verificación en Producción — Alegra Connector 2.3.0

Esta guía es la **lista de validación** de la versión 2.3.0. La 2.3.0 se
construyó y se probó contra la **documentación oficial** de Alegra y de
WooCommerce, y pasa las pruebas automáticas (smoke test, PHPUnit), pero
**nueve comportamientos concretos nunca se probaron contra la API real de
Alegra ni contra una tienda real**. Este documento los lista uno por uno para
que los valides después de desplegar.

> **Sé honesto contigo mismo:** nada de lo que sigue está "probado en
> producción". Está implementado según la documentación y con el mayor
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
  pestaña **"Facturación electrónica"**.

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Alto** | Si falla, la facturación electrónica no sirve. **Bloquea** el uso real de la 2.3.0 hasta resolverse. |
| **Medio** | Si falla, una función secundaria (categorías, checkout) queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. Se puede convivir con él; el poll/fallback cubre la mayoría. |

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

## 2. `idItemCategory` con múltiples valores

- **Qué se asume:** que Alegra acepta **una sola** categoría por producto. El
  plugin arma el payload con **una** categoría
  (`includes/Sync/Products.php:262`: `'category' => ['id' => ...]`). No se
  verificó si Alegra acepta un **arreglo** de categorías para productos que en
  WooCommerce pertenecen a varias.
- **Por qué importa:** si tu catálogo usa productos en **dos o más
  categorías**, en Alegra van a quedar clasificados en **una sola**. No rompe
  la facturación, pero desordena la contabilidad.
- **Cómo verificarlo:**
  1. En WooCommerce, asigna a un producto de prueba **dos categorías**
     distintas.
  2. Empuja el producto a Alegra (desde **Alegra Connector → Productos**, o
     guardándolo con la sincronización activa).
  3. En Alegra, abre el producto y mira su categoría. Revisa también
     `Alegra Connector → Logs` por si Alegra devolvió un error `400`.
- **Resultado esperado:** el producto se crea sin error, con **una** de sus
  categorías asignada (el plugin no promete varias).
- **Si falla:** si Alegra rechaza el payload, el log mostrará el error y el
  producto no se creará. Reporta el mensaje exacto: define si Alegra quiere un
  arreglo (`idItemCategory`) en vez del objeto `category`.
- **Riesgo: Bajo.** Afecta solo la clasificación; la facturación no depende de
  esto.

---

## 3. El push de categoría acepta `{id}`

- **Qué se asume:** que el endpoint de productos de Alegra acepta que la
  categoría se envíe como **objeto** `['id' => $alegra_cat_id]` (frente a un
  nombre plano). El plugin primero crea la categoría en Alegra
  (`create_item_category`, `includes/Sync/Products.php:1280`) y luego la
  referencia por `id` en el producto.
- **Por qué importa:** si Alegra no acepta el formato `{id}`, los productos
  nuevos se crean **sin categoría** o directamente fallan.
- **Cómo verificarlo:**
  1. En WooCommerce, crea una categoría **que no exista en Alegra** y asígnale
     un producto nuevo.
  2. Empuja el producto.
  3. En Alegra, entra a **Categorías de productos**: debe aparecer la categoría
     nueva con el mismo nombre. Abre el producto y confirma que quedó
     **asociado** a esa categoría.
  4. Revisa `Alegra Connector → Logs` por errores.
- **Resultado esperado:** la categoría se crea en Alegra y el producto queda
  asociado a ella.
- **Si falla:** verás `Failed to create Alegra category` en el log, y el
  producto se crea sin categoría. Reporta el mensaje de Alegra: define el
  formato correcto del campo.
- **Riesgo: Medio.** Degrada la organización del catálogo, pero no la emisión
  de facturas.

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

## 5. `stamp.generateStamp` (emisión ante la DIAN)

- **Qué se asume:** que el campo `stamp.generateStamp` —tomado de la
  documentación de Alegra— **emite (estampa) de verdad** la factura ante la
  DIAN. El plugin lo envía cuando **"Emitir facturas ante la DIAN"** está
  activado (`includes/Sync/Orders.php:210`). Nunca se probó con una factura
  real.
- **Por qué importa:** es el corazón de la facturación electrónica. Si el campo
  está mal, la factura queda en **borrador** y **no se envía a la DIAN**: para
  efectos legales, no facturaste.
- **Cómo verificarlo:**
  1. En `Alegra Connector → Dashboard`, confirma que la fila **"Emisión DIAN"**
     diga **"Activada"**. Si no, actívala en
     `Alegra Connector → Configuración → Facturación electrónica`.
  2. Haz un **pedido real de bajo valor** y espera 30–60 segundos.
  3. En Alegra, abre la factura generada y mira su **estado de emisión**. Debe
     estar **emitida/estampada**, no en borrador ni `PENDING`.
  4. Revisa las notas del pedido en WooCommerce por si aparece
     `[Alegra] La DIAN rechazó la emisión de la factura: ...`.
- **Resultado esperado:** la factura queda **emitida ante la DIAN**.
- **Si falla:** si queda en borrador o el log/nota muestra un rechazo, **no
  confíes en la 2.3.0 para facturar a la DIAN** hasta corregirlo. Desactiva
  temporalmente "Emitir facturas ante la DIAN" si necesitas seguir vendiendo
  (las facturas quedarán en borrador) y reporta el mensaje exacto.
- **Riesgo: Alto.** Bloquea el uso real de la facturación electrónica en
  Colombia hasta confirmarse.

---

## 6. `invoices: [{id, amount}]` en notas de crédito

- **Qué se asume:** que Alegra acepta el arreglo **plural** `invoices` con
  `{id, amount}` para asociar la nota crédito a la factura original. El plugin
  lo envía así desde la 2.3.0 (`includes/Sync/Orders.php:325`); antes lo
  mandaba en singular y Alegra lo rechazaba. El camino de reembolso **no** se
  probó de punta a punta.
- **Por qué importa:** si el formato está mal, **ningún reembolso** genera nota
  crédito, y tu contabilidad queda descuadrada respecto a WooCommerce.
- **Cómo verificarlo:**
  1. Haz un pedido real, espera a que se cree la factura y confirma que esté
     **emitida** (ver ítem 5). El plugin **bloquea** la nota crédito si la
     factura no está estampada.
  2. Desde WooCommerce, haz un **reembolso parcial** de ese pedido.
  3. En Alegra, entra a **Notas crédito**: debe aparecer una nota nueva,
     **asociada a la factura original**, por el monto reembolsado.
  4. En el pedido de WooCommerce, revisa las notas: debe decir
     **"Nota de crédito Alegra #... creada por reembolso de ..."**.
  5. Revisa `Alegra Connector → Logs`: debe figurar
     `Credit note for refund created in Alegra`.
- **Resultado esperado:** la nota crédito se crea y queda ligada a la factura.
- **Si falla:** en el log verás `Credit note for refund failed` con el mensaje
  de Alegra. Si el error es por factura no emitida, primero resuelve el ítem 5.
- **Riesgo: Alto.** Sin notas crédito, los reembolsos no se reflejan en Alegra.

---

## 7. Consumidor Final debe pre-existir en Alegra

- **Qué se asume:** que el contacto **"Consumidor Final"** ya existe en tu
  cuenta de Alegra con estos datos exactos: identificación
  **`222222222222`**, tipo **`CC`**, persona **`PERSON_ENTITY`**, régimen
  **`SIMPLIFIED_REGIME`**. El plugin lo **busca pero nunca lo crea**
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
  `Alegra Connector → Configuración → Facturación electrónica → "Consumidor
  Final manual"`.
- **Riesgo: Alto.** Sin Consumidor Final, los pedidos sin datos completos no se
  facturan.

---

## 8. HPOS (almacenamiento de pedidos de WooCommerce)

- **Qué se asume:** que la 2.3.0 funciona con **HPOS** (High-Performance Order
  Storage), el almacenamiento de pedidos en tablas propias de WooCommerce. El
  plugin usa la API oficial de WooCommerce (`$order->get_meta()`,
  `update_meta_data()`, `save()`, `wc_get_order()`) y es **idempotente**: antes
  de crear una factura revisa `_alegra_invoice_id`
  (`includes/Sync/Orders.php:54`), y los reembolsos buscan ese mismo meta. Se
  arregló a nivel de código, pero **no** se probó en una tienda real con HPOS.
- **Por qué importa:** con HPOS activado, un mal uso de `get_post_meta()` sobre
  el pedido haría que el plugin no viera la factura ya creada y **duplicara
  facturas**, o que un reembolso no encontrara la factura.
- **Cómo verificarlo:**
  1. Confirma que HPOS esté activo: **WooCommerce → Ajustes → Avanzado →
     Almacenamiento de pedidos**. Debe decir que WooCommerce usa las tablas
     propias para pedidos.
  2. Toma un pedido que **ya tenga factura** en Alegra. Fuerza una
     re-sincronización (vuelve a guardar el pedido desde el admin, o usa la
     acción de sincronizar en `Alegra Connector → Pedidos`).
  3. En Alegra, confirma que **no** apareció una **segunda factura** para ese
     pedido.
  4. Haz un reembolso de ese pedido y confirma que **encuentra** la factura
     (no devuelve `no_invoice`) y crea la nota crédito (ítem 6).
- **Resultado esperado:** una sola factura por pedido; el reembolso encuentra
  la factura.
- **Si falla:** si ves facturas duplicadas o el error `Order has no linked
  Alegra invoice`, el problema es la lectura del meta bajo HPOS. Reporta el
  pedido exacto y el log.
- **Riesgo: Medio.** Afecta a tiendas con HPOS; si tu tienda usa el
  almacenamiento clásico, no aplica.

---

## 9. El cap de reembolsos usa un transient lock, no una transacción

- **Qué se asume:** que el tope acumulado de reembolsos
  (`_alegra_credited_amount`) no se va a romper con **reembolsos
  simultáneos**. El bloqueo es un **transient** de 30 s
  (`includes/State_Sync.php:69`) y el tope se lee/escribe sin transacción de
  base de datos (`includes/Sync/Orders.php:281`). Es un caso borde: en
  condiciones normales (un admin, un reembolso a la vez) funciona.
- **Por qué importa:** dos reembolsos disparados **al mismo tiempo** sobre el
  mismo pedido podrían leer el mismo acumulado y **acreditar de más** en
  Alegra (por encima del total de la factura).
- **Cómo verificarlo:**
  1. Necesitas un pedido con factura **emitida** y saldo suficiente para dos
     reembolsos parciales.
  2. Dispara **dos reembolsos casi simultáneos**: abre el pedido en **dos
     pestañas** y confirma los dos reembolsos con pocos segundos de
     diferencia. (O pide a dos personas que lo hagan a la vez.)
  3. En Alegra, suma las **notas crédito** de ese pedido. En WooCommerce, mira
     el meta `_alegra_credited_amount` del pedido (o el acumulado que muestra
     `Alegra Connector → Pedidos`).
  4. El **total acreditado no debe superar** el total de la factura.
- **Resultado esperado:** el acumulado nunca pasa del total de la factura. En
  el peor caso, el segundo reembolso devuelve `refund_in_progress` o
  `refund_exceeds_invoice` y no se acredita de más.
- **Si falla:** si el acumulado supera el total, **no emitas más reembolsos**
  sobre ese pedido hasta revisar; emite una nota crédito de reverso manual en
  Alegra y reporta el caso con las dos fechas/horas.
- **Riesgo: Bajo.** Caso borde poco probable en el flujo normal de una tienda.

---

## Orden de prueba recomendado (de lo más seguro a lo más riesgoso)

1. **Pre-vuelo (sin tocar producción):** respaldos, `sha256` del ZIP y smoke
   test. Ver `RELEASE_2.3.0_DEPLOY.md` §2.
2. **Desplegar** e instalar la 2.3.0. Ver `RELEASE_2.3.0_DEPLOY.md` §3.
3. **Ítem 7 — Consumidor Final.** Solo lectura en Alegra y el widget. Si falta,
   se crea a mano antes de facturar.
4. **Dry Run** con un pedido de prueba: activa "Modo de prueba", haz el pedido,
   revisa el log (`[DRY RUN] Blocked POST ...`) y que no se cree nada en
   Alegra. Ver `RELEASE_2.3.0_DEPLOY.md` §4. **Desactívalo al terminar.**
5. **Ítem 4 — condicionales de Blocks.** Aprovéchalos en el mismo checkout de
   prueba del paso anterior.
6. **Ítems 2 y 3 — categorías de producto.** Bajo impacto y reversible: crea,
   empuja y revisa en Alegra.
7. **Ítem 1 — webhook/poll de stock.** Cambia stock en Alegra y observa.
8. **Ítem 5 — pedido real de bajo valor + emisión DIAN.** Acá empieza lo que
   toca dinero real.
9. **Ítem 6 — reembolso parcial** del pedido del paso 8 → nota crédito.
10. **Ítem 8 — HPOS.** Re-sincroniza un pedido facturado y reembólsalo.
11. **Ítem 9 — reembolsos concurrentes.** El más riesgoso, al final, con un
    pedido de prueba.

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
4. **Mientras esté en duda la emisión DIAN**, puedes desmarcar **"Emitir
   facturas ante la DIAN"** para que las facturas queden en borrador y no se
   envíen a la DIAN, y seguir operando con cautela.
5. **No borres** pedidos, notas crédito ni facturas de Alegra para "limpiar":
   primero entiende qué pasó.

---

## Checklist final

### Antes de empezar
- [ ] Respaldo de base de datos y de archivos hecho.
- [ ] `sha256` del ZIP coincide con el `.sha256`.
- [ ] Smoke test del ZIP termina en `SMOKE OK`.
- [ ] 2.3.0 instalado y la versión figura como **2.3.0** en **Plugins**.

### Las 9 verificaciones
- [ ] **1.** Cambio de stock en Alegra → el stock de WooCommerce se actualiza
      (webhook o poll).
- [ ] **2.** Producto con 2 categorías → se crea en Alegra sin error (con una
      categoría).
- [ ] **3.** Categoría nueva → se crea en Alegra y el producto queda asociado.
- [ ] **4.** En Blocks: NIT → aparece Dígito de verificación; Persona Jurídica
      → aparece Razón social (y se ocultan al revés).
- [ ] **5.** Pedido real → la factura queda **emitida ante la DIAN**.
- [ ] **6.** Reembolso → se crea la **nota crédito** ligada a la factura.
- [ ] **7.** Consumidor Final: widget en verde **"Disponible"**.
- [ ] **8.** HPOS: re-sincronizar no duplica la factura; el reembolso la
      encuentra.
- [ ] **9.** Dos reembolsos simultáneos → el acumulado **no** supera el total.

### Cierre
- [ ] "Modo de prueba" **desactivado**.
- [ ] "Emitir facturas ante la DIAN" en el estado deseado.
- [ ] Logs revisados y sin errores `[Alegra]` repetidos.
