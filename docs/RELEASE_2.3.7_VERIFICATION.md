# Verificación en Producción — Alegra Connector 2.3.6

Esta guía es la **lista de validación** de la versión 2.3.6. La 2.3.6 es una
**versión grande de correcciones** (cuatro lotes: Productos, Clientes, Pedidos y
Orquestación) salida de un análisis de funcionamiento real. El hallazgo central
es el **reporte original del comerciante: el stock nunca aparecía en
WooCommerce**. La causa raíz era que el plugin **nunca activaba `_manage_stock`**,
y WooCommerce **ignora** `_stock` si la gestión de inventario está desactivada;
`_stock_status` tampoco se seteaba. Además, la categoría **comercial** del
producto nunca se asignaba porque se enviaba bajo `category` (la categoría
**contable** de Alegra) en vez de `itemCategory`. Ambas direcciones quedaron
corregidas, junto con el **lock doble que rompía la importación de clientes**, los
**totales de pedidos con envío**, y un endpoint de **webhooks sin autenticación**.

La guía **conserva** los ítems que siguen necesitando prueba en vivo: el
webhook/poll de stock (ítem 1), los condicionales de Blocks (ítem 4) y la
pregunta CO + facturación electrónica (ítem 5), que ahora se maneja de forma
**defensiva** pero **igual necesita una prueba real**. Los ítems ya resueltos en
versiones anteriores (categoría única, `invoices:[{id,amount}]`, HPOS, cap de
reembolsos, Consumidor Final preexistente) se retiraron de esta guía por quedar
obsoletos o corregidos.

> **⚠️ ANTES QUE NADA: re-registrá los webhooks.** Las suscripciones viejas
> apuntan a la URL **sin el token secreto** y el endpoint nuevo **las rechaza**.
> Hacelo en **Alegra Connector → Configuración → pestaña Avanzado → sección
> "Sincronización en Tiempo Real (Webhooks)" → "Registrar webhooks en Alegra"**.
> Si no lo hacés, los webhooks quedan mudos (el cron periódico sigue como
> respaldo, pero perdés el tiempo real).

**Guía complementaria:** este documento **no** repite cómo instalar ni cómo
revertir. Eso está en [`RELEASE_2.3.0_DEPLOY.md`](./RELEASE_2.3.0_DEPLOY.md)
(secciones 2, 3, 4 y 6). Acá solo se valida. La guía de despliegue cubre la
**instalación y el rollback**; esta cubre la **verificación funcional**.

- Dónde ver los **logs:** `Alegra Connector → Logs`. En disco:
  `wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`.
- Dónde ver el **estado de facturación:** `Alegra Connector → Dashboard` →
  tarjeta **"Estado de facturación"**.
- Dónde cambiar los **ajustes:** `Alegra Connector → Configuración` (pestañas
  **"Sincronización"** y **"Avanzado"**).

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Alto** | Si falla, la factura sale con el **destinatario equivocado**, no se factura, se factura sin querer, o el stock queda desalineado. **Bloquea** el uso real de la 2.3.6 hasta resolverse. |
| **Medio** | Si falla, una función secundaria (categorías, checkout) queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. Se puede convivir con él; el poll/fallback cubre la mayoría. |

---

## ★ Re-registrar los webhooks — ⚠️ OBLIGATORIO DESPUÉS DE ACTUALIZAR

**Estado: NO probado en vivo. Requiere prueba en vivo.**

La 2.3.6 cierra el endpoint de webhooks. Alegra **no firma** sus webhooks, así
que cualquiera podía hacer un POST al endpoint y **disparar sincronizaciones o
completar pedidos**. Ahora la URL lleva un **token secreto** y se valida con
`hash_equals`, con criterio **fail-closed**. Como la URL cambió, **las
suscripciones viejas dejan de ser válidas** y hay que rehacerlas.

Además, la 2.3.6 corrige un bug por el que **los webhooks nunca se creaban**:
Alegra hace un **POST con cuerpo vacío** para verificar una URL nueva y exige
**2XX en menos de 5 segundos**; el plugin respondía **400**, así que la
verificación fallaba. Ahora ese handshake se responde con 2XX.

1. Entrá a **Alegra Connector → Configuración → pestaña Avanzado**.
2. En la tarjeta **"Sincronización en Tiempo Real (Webhooks)"**, mirá la
   **"URL del Webhook"**: debe incluir `?token=...`. Si no lo incluye, guardá los
   ajustes primero (el token se genera y persiste solo).
3. Hacé clic en **"Registrar webhooks en Alegra"**. Debe registrar **6 eventos**
   (creación/edición de items, clientes y facturas) y mostrar el estado.
4. Confirmá en **Alegra → Webhooks** (o la sección de integraciones) que las 6
   suscripciones apuntan a la URL **con token**.
5. **Prueba de disparo:** cambiá un producto en Alegra y verificá que WooCommerce
   reacciona y que en `Alegra Connector → Logs` aparece el evento procesado.

- **Resultado esperado:** 6 suscripciones activas apuntando a la URL con token y
  un cambio en Alegra que llega a WooCommerce.
- **Si falla:** si el botón reporta 0 registrados, revisá que la URL sea pública
  y que el sitio responda **2XX en menos de 5 segundos**. Un POST sin token ahora
  responde **401** (antes cualquiera pasaba).
- **Riesgo: Alto (seguridad).** Sin re-registrar, los webhooks no llegan; con el
  endpoint viejo abierto, cualquiera podía disparar la integración.

---

## ★★ Productos: el stock y la categoría — ⚠️ LO MÁS IMPORTANTE DE LA 2.3.6

**Estado: NO probado en vivo. El stock era el reporte original del comerciante.
Requiere prueba en vivo.**

### ★★.a — El stock llega a WooCommerce (el bug original)

1. En **Alegra Connector → Configuración → Sincronización**, confirmá que
   **"Fuente de inventario"** es **"Alegra (recomendado)"**. Con **"WooCommerce"**
   el plugin **no** toca el stock (por diseño).
2. Empujá un producto a Alegra (push) y luego traelo de vuelta con **"Traer
   productos desde Alegra"** (o usá **"Sincronizar inventario"** en el Dashboard).
3. **Resultado esperado:** en **WooCommerce → Productos**, el producto queda con
   **"Gestionar inventario" activado** y la **cantidad** visible. Antes de la
   2.3.6 el stock se escribía pero WooCommerce lo **ignoraba** porque
   `_manage_stock` nunca se activaba, y `_stock_status` tampoco se seteaba. **Ese
   era el bug raíz del reporte original.**
4. **Caso servicio:** un ítem de Alegra **sin** `inventory` (servicio) queda con
   "Gestionar inventario" **desactivado** y su stock **no** se toca.
5. **Si falla:** revisá en los logs si Alegra devolvió `availableQuantity`. Si es
   nulo/ausente, el plugin **no** escribe 0 (a propósito: "no sé" no es "cero") y
   deja una advertencia. Un stock negativo en Alegra se recorta a 0 con aviso.

### ★★.b — La categoría comercial (`itemCategory`)

1. Creá una **categoría de producto** en WooCommerce y asignala a un producto.
2. Empujalo a Alegra.
3. **Resultado esperado:** en Alegra el ítem queda en la **categoría comercial**
   correcta (`itemCategory`). Antes se enviaba bajo `category` (la categoría
   **contable**), así que la comercial **nunca** se asignaba.
4. Importá productos desde Alegra y confirmá que las categorías creadas en
   WooCommerce son las **comerciales**, **no** nombres de **cuentas contables**.
5. **Si falla:** el nombre de la categoría importada delata el problema (si ves
   un nombre de cuenta contable, se está leyendo `category` en vez de
   `itemCategory`).

### ★★.c — El pull de inventario ahora tiene quién lo llame

1. En el Dashboard, usá **"Sincronizar inventario"**.
2. **Resultado esperado:** el mensaje dice **"Inventario sincronizado: N
   productos actualizados."** y el stock de WooCommerce refleja a Alegra.
3. Con la fuente en **WooCommerce**, el botón debe responder **"La copia de
   inventario está desactivada: la fuente de inventario es WooCommerce."**
4. **Si falla:** revisá el kill switch y que no haya otra sincronización en curso
   (lock).

- **Riesgo: Alto.** El stock era el reporte original; si no aparece, el
  comerciante vende sin existencias reales.

---

## ★ Clientes: importación, Consumidor Final y CO + FE

### ★.a — La importación ya no se saltea clientes

1. En **Alegra Connector → Dashboard**, ejecutá la **importación de clientes**
   (cron o botón manual).
2. **Resultado esperado:** los clientes se importan; los **contactos salteados ya
   no se cuentan como errores**, y se deduplican por **email o identificación**
   (no solo por email).
3. Con `conflict_resolution = alegra_wins`, un cliente existente ahora **actualiza
   nombre y email** desde Alegra.
4. **Si falla:** antes de la 2.3.6 un **lock doble** rompía la importación
   entera (tanto el cron como el botón manual). Si ves "ya hay una
   sincronización en curso" de forma permanente, reportalo.

### ★.b — Consumidor Final se crea solo

1. Si tu cuenta de Alegra **no** tiene el contacto Consumidor Final, ya **no**
   hace falta crearlo a mano: el plugin lo **crea automáticamente** e
   **idempotentemente**.
2. Facturá un pedido sin identificación y confirmá que la factura sale a nombre de
   **Consumidor Final**.
3. En el Dashboard, la fila **"Consumidor Final"** debe quedar en verde.
4. **Si falla:** revisá `Alegra Connector → Logs`; el error real del create ya no
   se enmascara.

### ★.c — CO + facturación electrónica: sigue necesitando prueba en vivo

**Estado: NO probado en vivo. La 2.3.6 lo maneja defensivamente, pero igual hay
que verificarlo.**

- **Qué cambió:** una cuenta colombiana **con facturación electrónica** exige
  `regime` + `kindOfPerson` en el contacto. Antes faltaban, el create **fallaba**
  y el plugin caía **en silencio** a Consumidor Final. Ahora **siempre se envían**
  (configurables en **Configuración → Avanzado → "Datos fiscales del contacto
  (Colombia)"**) y, si el create falla, el **motivo real** queda en una **nota del
  pedido**.
- **Cómo verificarlo:**
  1. Hacé un pedido de prueba con un cliente que **tenga** identificación
     (cédula/NIT).
  2. En Alegra, abrí la factura y mirá **a nombre de quién** quedó.
  3. Revisá las **notas del pedido**: ya no debería aparecer el fallback
     silencioso a Consumidor Final; si aparece, la nota dirá **qué campo** rechazó
     Alegra.
- **Resultado esperado:** la factura sale a nombre del **cliente** y **no**
  aparece la nota de fallback.
- **Si falla:** reportá el mensaje textual de la nota; con eso se ajusta el
  régimen/persona configurado.
- **Riesgo: Alto.** Un destinatario equivocado es un problema fiscal.

---

## ★ Pedidos: totales, reembolsos y errores visibles

### ★.a — Pedido con envío: el total de la factura == total del pedido

1. Creá un pedido **con costo de envío** (y, si querés, con fees).
2. Facturalo (**"Crear factura"**).
3. **Resultado esperado:** el total de la factura en Alegra **coincide** con el
   total del pedido. Antes el **envío y los fees no se mapeaban**, así que la
   factura salía **más chica** que el pedido.
4. **Si falla:** compará subtotales; si el envío no aparece como ítem/servicio en
   la factura, reportalo con el número de pedido.

### ★.b — Reembolso parcial: se crea la nota crédito

1. Sobre un pedido ya facturado, hacé un **reembolso parcial**.
2. **Resultado esperado:** se crea la **nota crédito** en Alegra, ligada a la
   factura, por el monto reembolsado. Antes el ítem se enviaba **sin `id`** y la
   API devolvía **400**, así que la nota crédito **no se creaba**.
3. Verificá que el acumulado `_alegra_credited_amount` no supera el total.
4. **Si falla:** el log dirá el 400 textual; anotalo.

### ★.c — Impuestos sin mapeo

1. Si usás impuestos y **no** tenés mapeo configurado, facturá un pedido con
   impuesto.
2. **Resultado esperado:** el plugin **crea el impuesto** en Alegra
   (idempotentemente) y lo aplica. Antes los impuestos se **descartaban en
   silencio** cuando no había mapeo.

### ★.d — Los errores ya no se tragan

1. Forzá un fallo de pago (por ejemplo, un medio de pago inválido) y facturá.
2. **Resultado esperado:** queda una **nota en el pedido** con el motivo real y
   una entrada en los logs. En **modo automático** pasa lo mismo (antes el fallo
   era invisible).
3. **"Registrar pago"** sobre una factura en **borrador** ahora **abre la factura
   primero** y luego registra el pago (antes fallaba).
4. El "void" ahora envía `cause` (el campo documentado) en vez de `reason`, y el
   guard del medio de pago `'0'` es consistente en todos los caminos.

---

## ★ Orquestación: cron recurrente y stop por corrida

### ★.a — "Run now" y "Skip next" ya no matan el cron

1. En **Alegra Connector → Monitor**, usá **"Run now"**.
2. **Resultado esperado:** la corrida se ejecuta **y la recurrencia sigue
   programada**. Antes `wp_clear_scheduled_hook` borraba la recurrencia y nada la
   reprogramaba, así que el cron **moría para siempre**.
3. Usá **"Skip next"** y confirmá lo mismo (antes también mataba la recurrencia).
4. **Auto-sanación:** si la recurrencia se perdió, el plugin la **restaura** al
   iniciar (respetando un stop explícito del usuario).

### ★.b — El botón "Stop" frena a mitad de importación

1. Iniciá una importación grande (productos, clientes o categorías).
2. Apretá **"Stop"** durante la corrida.
3. **Resultado esperado:** el loop se detiene de verdad (antes el stop por corrida
   era inefectivo a mitad de importación).

### ★.c — El asistente (wizard) ya no rompe el redirect

1. Completá el asistente de configuración inicial.
2. **Resultado esperado:** redirige correctamente (antes redirigía después de que
   los headers ya se habían enviado).

---

## Los que siguen necesitando prueba en vivo

### Ítem 1 — `edit-item` no dispara con cambios solo de stock

- **Qué se asume:** que Alegra **no** dispara el webhook `edit-item` cuando lo
  único que cambia en un producto es el **inventario**. Por eso la sincronización
  de stock en tiempo real no depende del webhook: cae en el **poll** (la
  sincronización programada, por defecto cada 15 minutos, método `both`). El
  handler de `edit-item` existe igual
  (`includes/Webhooks/Handlers.php:32`), por si Alegra sí lo dispara.
- **Cómo verificarlo:**
  1. En Alegra, cambiá **solo el stock** de un producto y guardá.
  2. Esperá unos segundos y revisá `Alegra Connector → Logs`. Buscá
     `Processing webhook event` con `"event": "edit-item"`.
  3. Mirá el mismo producto en **WooCommerce → Productos**: ¿cambió el stock? Si
     cambió en segundos, el webhook funcionó. Si no, esperá a que corra la
     sincronización programada (hasta 15 min) y volvé a mirar.
  4. Para forzarla, usá **Dashboard → "Sincronizar inventario"** (nuevo caller de
     la 2.3.6) o **"Traer productos desde Alegra"**.
- **Resultado esperado:** el stock de WooCommerce termina actualizado, por
  webhook **o** por poll. Las dos vías son correctas.
- **Riesgo: Bajo.** No bloquea el release: el poll es la red de seguridad.

### Ítem 4 — Ruta `customer.address.<field>` en Blocks

- **Qué se asume:** que el **checkout por bloques** resuelve bien la ruta de los
  campos adicionales `customer.address.<namespace>/<field>`
  (`alegra-connector/dv`, `alegra-connector/company`, etc.). Las condiciones se
  escribieron contra la documentación y el esquema oficial de WooCommerce
  (`includes/Checkout_Integration.php:253`), pero **no** contra un checkout
  Blocks real con este plugin.
- **Cómo verificarlo:**
  1. Asegurate de que tu checkout use el **editor de bloques** (no el shortcode
     clásico).
  2. Llegá al checkout con un producto en el carrito.
  3. En **Tipo de documento**, elegí **NIT** → debe **aparecer** **"Dígito de
     verificación"**. Elegí otro tipo → debe **desaparecer**.
  4. En **Tipo de persona**, elegí **Persona Jurídica** → debe **aparecer**
     **"Razón social"**. Elegí **Persona Natural** → debe **desaparecer**.
  5. Repetí en **"Mi cuenta"** si el cliente edita su dirección.
- **Resultado esperado:** los campos aparecen y desaparecen según lo elegido,
  igual que en el checkout clásico.
- **Riesgo: Medio.** No impide facturar, pero puede generar facturas con datos
  mal capturados si el cliente no ve el campo que necesita.

### Ítem 5 — CO + Facturación Electrónica

Ver **★.c**. Sigue siendo el otro riesgo alto, pero la 2.3.6 ya no falla en
silencio: si Alegra rechaza el contacto, la nota del pedido lo dice.

---

## Orden de prueba recomendado

1. **Pre-vuelo (sin tocar producción):** respaldos, `sha256` del ZIP y smoke
   test. Ver `RELEASE_2.3.0_DEPLOY.md` §2.
2. **Desplegar** e instalar la **2.3.6**. Ver `RELEASE_2.3.0_DEPLOY.md` §3.
3. **★ Re-registrar los webhooks (obligatorio).** Sin esto, no hay tiempo real.
4. **★★ Productos:** stock (★★.a), categoría comercial (★★.b) y pull de
   inventario (★★.c). Es lo más importante de esta versión.
5. **★ Clientes:** importación sin salteos (★.a), Consumidor Final (★.b) y
   CO+FE (★.c).
6. **★ Pedidos:** envío/totales (★.a), reembolso parcial (★.b), impuestos (★.c)
   y errores visibles (★.d).
7. **★ Orquestación:** "Run now"/"Skip" conservan el cron (★.a), "Stop" (★.b) y
   el wizard (★.c).
8. **Ítems 1 y 4:** stock por webhook/poll y condicionales de Blocks.
9. **Pedido real de bajo valor.** Acá empieza lo que toca dinero real: confirmá
   que la factura se crea en Alegra (en borrador por defecto), vinculada al
   cliente correcto y con el total correcto.
10. **Reembolso parcial** del pedido anterior → confirmá que se crea la nota
    crédito ligada a la factura.

---

## Qué hacer si algo falla

1. **Revisá primero los logs:** `Alegra Connector → Logs` (y en disco,
   `wp-content/uploads/alegra-logs/`). El mensaje de Alegra suele decir
   exactamente qué campo rechazó.
2. **Anotá el caso:** pedido, hora, qué hiciste y el mensaje textual. Sin eso,
   no se puede diagnosticar.
3. **Rollback a 2.3.5** (el procedimiento completo está en
   `RELEASE_2.3.0_DEPLOY.md` §6):
   - Desactivá **"Alegra Connector"** en **Plugins**.
   - Reemplazá la carpeta por `releases/alegra-connector-v2.3.5.zip`.
   - Reactivá el plugin.
   - **Ojo:** la 2.3.0 migró las columnas `alegra_id` de `BIGINT` a
     `VARCHAR(36)`; eso **no** se revierte. Con IDs numéricos, versiones previas
     siguen funcionando.
   - **Ojo:** si re-registraste los webhooks con token, la 2.3.5 **no** entiende
     ese token; al revertir, volvé a registrar los webhooks desde la 2.3.5.
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
- [ ] 2.3.6 instalado y la versión figura como **2.3.6** en **Plugins**.

### Re-registrar webhooks (obligatorio)
- [ ] **★** La "URL del Webhook" incluye `?token=...`.
- [ ] **★** "Registrar webhooks en Alegra" reporta **6 eventos**.
- [ ] **★** Un cambio en Alegra dispara el webhook (visible en los logs).

### Productos — lo nuevo de la 2.3.6
- [ ] **★★.a** Tras importar/pull, el producto tiene **"Gestionar inventario"**
      activado y la cantidad visible en WooCommerce.
- [ ] **★★.b** La categoría comercial (`itemCategory`) se asigna al empujar y se
      importa como categoría comercial (no como cuenta contable).
- [ ] **★★.c** "Sincronizar inventario" actualiza N productos (o avisa que la
      fuente es WooCommerce).

### Clientes
- [ ] **★.a** La importación no se saltea clientes; los salteados no cuentan como
      error; se deduplica por identificación.
- [ ] **★.b** Consumidor Final se crea solo (widget en verde).
- [ ] **★.c** CO + FE: la factura sale a nombre del **cliente** (no del genérico);
      si falla, la nota del pedido dice por qué.

### Pedidos
- [ ] **★.a** Pedido **con envío** → total de factura **==** total del pedido.
- [ ] **★.b** Reembolso **parcial** → nota crédito creada (sin 400).
- [ ] **★.c** Impuestos sin mapeo → se crean en Alegra.
- [ ] **★.d** Fallo de pago / modo automático → nota en el pedido con el motivo.

### Orquestación
- [ ] **★.a** "Run now" y "Skip next" **conservan** la recurrencia del cron.
- [ ] **★.b** "Stop" frena la importación a mitad.
- [ ] **★.c** El wizard redirige correctamente.

### Siguen necesitando prueba en vivo
- [ ] **1.** Cambio de stock en Alegra → el stock de WooCommerce se actualiza
      (webhook o poll).
- [ ] **4.** En Blocks: al elegir NIT aparece el Dígito de verificación (y se
      oculta con los demás tipos); Persona Jurídica muestra Razón social.
- [ ] **5.** CO + FE: la factura sale a nombre del **cliente**.

### Cierre
- [ ] "Modo de prueba" **desactivado**.
- [ ] "Estado de las facturas" en el estado deseado (borrador por defecto).
- [ ] Logs revisados y sin errores `[Alegra]` repetidos.
