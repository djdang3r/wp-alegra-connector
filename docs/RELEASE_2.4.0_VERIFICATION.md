# Verificación en Producción — Alegra Connector 2.4.0

Esta guía es la **lista de validación** de la versión **2.4.0**. La 2.4.0 es una
**versión de comportamiento con migración**: el **kill switch** y los **toggles
por entidad** dejaron de ser decorativos y ahora se aplican en un único punto de
control (`Write_Gate`, dentro de `Client::request()`), de modo que **ninguna
escritura llega a Alegra** mientras el plugin está "desconectado" o la entidad
está deshabilitada. Además: el modo manual ya no emite notas de crédito
automáticas por reembolso (hay un botón manual **"Emitir nota de crédito"**), el
barrido/reconciliación respeta `payment_reconcile_enabled`, el dashboard ya no
crea el Consumidor Final al renderizar, y los cuatro checkboxes de "qué
sincronizar" muestran el valor real.

La 2.4.0 **conserva** las verificaciones de la 2.3.11 (pagos), de la 2.3.7
(webhooks), de la 2.3.6 (stock, categoría comercial, clientes, pedidos y
orquestación) y de las versiones anteriores: siguen siendo válidas y están más
abajo. Lo **nuevo** es la sección de **write gates (★★★)**.

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
| **Alto** | Si falla, la factura sale con el **destinatario equivocado**, no se factura, se factura sin querer, el stock queda desalineado, o los webhooks se **borran solos**. **Bloquea** el uso real hasta resolverse. |
| **Medio** | Si falla, una función secundaria (categorías, checkout) queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. Se puede convivir con él; el poll/fallback cubre la mayoría. |

---

## Política de artefactos de release (el ZIP lo construye el mantenedor)

> **Regla:** el ZIP de release se **construye y commitea localmente**; CI
> **solo lo publica**. El `.sha256` versionado en `releases/` es la **fuente de
> verdad** para verificar la descarga.

- **Quién construye:** el mantenedor corre `bash scripts/build-release.sh <X.Y.Z>`
  y commitea `releases/alegra-connector-v<X.Y.Z>.zip` + `.sha256` **antes** de
  crear el tag. El tag es el último paso.
- **Qué hace CI:** ante un push de tag `v*`, `.github/workflows/release.yml`
  **no recompila**. Verifica que el ZIP commiteado coincida con su `.sha256`
  (`sha256sum -c`) y recién entonces lo publica con `softprops/action-gh-release`.
  Si el ZIP no está commiteado o el checksum no coincide, el job **falla** y no
  publica nada.
- **Por qué:** la v2.4.0 publicó un asset con los mismos archivos pero **distintos
  bytes** que el ZIP del repo, porque el workflow lo recompilaba en CI y competía
  con la subida manual. El `.sha256` del repo no podía verificar la descarga.
  Con esta política el asset es **byte-idéntico** al artefacto versionado.
- **Build determinista (bonus):** `build-release.sh` normaliza los mtimes a
  `1980-01-01` y usa `zip -X` con una lista ordenada (`LC_ALL=C sort`), así los
  mismos archivos de entrada producen **siempre los mismos bytes**. Es una red de
  seguridad de reproducibilidad, **no** un permiso para recompilar en CI.
- **Verificación manual:**
  ```bash
  ( cd releases && sha256sum -c alegra-connector-v<X.Y.Z>.zip.sha256 )
  ```

---

## ★★★ Write gates — lo nuevo de la 2.4.0 (comportamiento)

> **Leé esto antes de actualizar.** La 2.4.0 cambia el comportamiento de forma
> deliberada: mientras el plugin esté **"desconectado"** (kill switch), **ninguna
> escritura sale** hacia Alegra, ni siquiera las que hacías a mano desde el admin.
> En **modo manual** (`push_orders_enabled=false`), un reembolso **ya no** emite
> la nota de crédito sola: se usa el botón **"Emitir nota de crédito"**.

### ★★★.a — El kill switch ahora bloquea una factura/pago manual

1. Andá a **Alegra Connector → Configuración** y **desconectá** el plugin (o
   apagá el kill switch). Confirmá que figura como **desconectado**.
2. En el detalle de un pedido sin factura, apretá **"Facturar"**. Probá también
   un push por REST/acción manual si tenés una.
3. **Resultado esperado:** **no** se crea factura ni pago en Alegra; el plugin
   reporta el bloqueo (aviso/nota) en vez de escribir. En los logs no aparece
   ningún `POST /invoices` ni `POST /payments`.
4. **Antes de la 2.4.0** ese push manual **sí** llegaba a Alegra aunque el plugin
   estuviera "desconectado". Ese es exactamente el agujero que tapa esta versión.
5. **Si falla:** es un bloqueante — el kill switch no es real. Revisá
   `includes/Write_Gate.php` y que `Client::request()` lo invoque.

### ★★★.b — `payment_reconcile_enabled=false` frena el barrido

1. En **Configuración**, poné **`payment_reconcile_enabled`** (reconciliación de
   pagos) en **apagado**.
2. Tomá un pedido **pagado**, ya facturado y **sin pago** en Alegra, y esperá el
   barrido horario (o forzá el cron `alegra_connector_payment_reconcile`).
3. **Resultado esperado:** **ni** el barrido horario **ni** la reconciliación en
   tiempo real registran el pago; la factura sigue "Por Cobrar".
4. Volvé a prenderlo y confirmá que el pago se adjunta (sin duplicar).
5. **Riesgo: Alto.** Si el flag no frena ambas vías, seguís pagando de más sin
   control.

### ★★★.c — El botón manual "Emitir nota de crédito" (modo manual)

1. Con **`push_orders_enabled=false`** (modo manual) y una factura vinculada,
   **reembolsá** un pedido en WooCommerce.
2. **Resultado esperado:** **no** se emite nota de crédito automáticamente.
3. Ahora apretá **"Emitir nota de crédito"** en la pantalla del pedido.
4. **Resultado esperado:** se emite **exactamente una** nota de crédito en Alegra
   ligada a la factura. Repetir el clic no debe duplicarla.
5. **Casos borde:** sin factura vinculada, el botón avisa y no postea nada; con
   factura en **borrador**, pide abrirla primero; en **dry run** o con el kill
   switch activo, reporta el bloqueo y no escribe.
6. **Riesgo: Alto.** Reemplaza la nota automática que la 2.4.0 dejó de emitir.

### ★★★.d — Los cuatro checkboxes de "qué sincronizar" dicen la verdad

1. En **Configuración → Sincronización**, mirá los cuatro checkboxes
   (`push_products_enabled`, `push_customers_enabled`, `push_orders_enabled` y
   el restante).
2. **Resultado esperado:** lo que muestra cada checkbox **coincide con lo que el
   cron realmente hace**. Antes se veían tildados mientras el cron los trataba
   como apagados.
3. **Migración:** `push_customers_enabled` se sembró desde
   `push_products_enabled`, así que una instalación existente **sigue empujando
   clientes**. Una instalación nueva lo tiene **apagado** por defecto.
4. **Riesgo: Medio.** Un desfase acá hace que creas que sincronizás algo que no.

### ★★★.e — Guardar Configuración conserva los mapeos

1. Anotá los mapeos de **campos** y de **impuestos** en **Configuración**.
2. Guardá la página (aunque no toques nada).
3. **Resultado esperado:** los mapeos **siguen ahí**, intactos. Antes, guardar
   los borraba.
4. **Riesgo: Alto.** Perder los mapeos rompe la facturación con impuestos.

### ★★★.f — El dashboard ya no crea el Consumidor Final al renderizar

1. Asegurate de que **no** exista todavía el contacto **Consumidor Final** en
   Alegra (o mirá su `id`/fecha).
2. Abrí **Alegra Connector → Dashboard** varias veces.
3. **Resultado esperado:** **no** se hace ningún `POST /contacts` al renderizar;
   el Consumidor Final **no** se crea por mirar el dashboard. Se crea recién en
   la **primera facturación** que lo necesite.
4. **Riesgo: Medio.** Un render no debería tener efectos secundarios de escritura.

---

## ★★★ Pagos — lo nuevo de la 2.3.11 (el bug reportado)

### ★★★.a — "Facturar" un pedido pagado registra el pago

1. Tomá un pedido **pagado** (`processing`/`completed`) **sin** factura en Alegra
   y con la **cuenta de destino** configurada.
2. En el detalle del pedido, apretá **"Facturar"**.
3. **Resultado esperado:** se crea **1 factura** y **1 pago** en Alegra; la
   factura deja de estar **"Por Cobrar"**. En el pedido, `_alegra_payment_id`
   queda seteado y la nota del pedido lo confirma.
4. **Si falla:** revisá `Alegra Connector → Logs`; el error real de Alegra queda
   ahí y en una nota del pedido. Verificá también que la **fecha del pago** sea
   la del pedido (`get_date_paid()`), no la del servidor.

### ★★★.b — "Facturar" un pedido impago crea la factura y NO paga

1. Tomá un pedido **on-hold/pending** (no pagado) con la cuenta configurada.
2. Apretá **"Facturar"**.
3. **Resultado esperado:** se crea la **factura** pero **no** se registra pago
   (0 pagos). Queda una **nota** explicando que el pedido no figura pagado en
   WooCommerce.
4. **Caso sin cuenta:** sin cuenta configurada, un pedido pagado con factura y
   sin pago **no** postea pago, pero deja **nota + warning** (no falla en
   silencio).

### ★★★.c — Reconciliación: el pago posterior se adjunta solo

1. Con un pedido **ya facturado** (factura vinculada) y **sin pago**, pasalo a
   **`processing`** (o completá el pago).
2. **Resultado esperado:** el plugin registra el pago automáticamente por el
   hook, aunque el modo push de pedidos esté **apagado** (modo manual). Si el
   evento se pierde, el **barrido horario** lo reintenta (hasta 20 por corrida).
3. **No duplica:** disparar el hook y el barrido juntos produce **un solo**
   `POST /payments` (guard de meta + pre-búsqueda + lock).
4. **Nunca crea factura:** en modo manual, la reconciliación solo adjunta el pago
   a una factura existente.

### ★★★.d — Cuenta de destino y placeholder del checkout

1. En **Ajustes → Avanzado**, el label es **"Cuenta de destino para pagos
   (banco o caja)"** y, si había un id guardado, **sigue seleccionado** aunque
   `/bank-accounts` falle o no incluya ese id.
2. Si la cuenta había quedado en **"Sin cuenta"**, **re-elegila y guardá** (el
   select ya no puede perder el valor, pero no puede adivinar el que se pisó).
3. En el checkout (clásico y Blocks), el select de **tipo de documento** arranca
   en **"Seleccione…"**; ya no viene preseleccionado "Registro Civil".

- **Riesgo: Alto.** Era el bug reportado: sin esto, la factura queda "Por Cobrar"
  y la contabilidad no refleja el cobro.

### ★★★.e — Diagnóstico de pagos (solo lectura)

Si un pedido quedó **pagado en WooCommerce** pero **sin pago en Alegra**, hay un
script que te dice **exactamente por qué**, sin tocar nada. Es **solo lectura**:
no escribe opciones ni meta, y a Alegra solo le hace **GET** (`/company` y
`/invoices/{id}`). Es seguro correrlo en producción.

**Opción 1 — WP-CLI (recomendado):**

```bash
wp eval-file wp-content/plugins/alegra-connector/scripts/diagnose-payments.php -- --order=123
```

Sin `--order` usa el **pedido pagado más reciente**. También podés correrlo como
comando: `wp alegra-diagnose --order=123`.

**Opción 2 — Navegador (si no tenés WP-CLI):**

1. Copiá `scripts/diagnose-payments.php` a la **raíz de WordPress** (donde está
   `wp-load.php`).
2. Abrí `https://TU-SITIO/diagnose-payments.php?order=123` con tu usuario.
3. Requiere sesión con permiso **`manage_woocommerce`**; sin eso responde
   **403**. Borrá el archivo cuando termines.

**Qué te muestra:**

1. La **versión** cargada (header vs constante vs opción) — detecta la clase de
   bug de la "constante vieja".
2. La **configuración de pagos**: cuenta de destino (valor crudo y si el plugin
   la considera configurada), `invoice_status`, `push_orders_enabled`,
   `dry_run`, `customer_resolution_mode`.
3. La **conexión**: el flag `connection_tested` y un **GET /company** real.
4. El **pedido**: estado, `is_paid()`, fecha de pago, total, pasarela, meta
   (`_alegra_invoice_id`, `_alegra_payment_id`, `_billing_alegra_contact_id`), el
   **medio de pago mapeado** y el **payload que el plugin enviaría** (no se
   envía), más el **estado real de la factura** en Alegra (`status`, `total`,
   `balance`, `totalPaid`).
5. Un **veredicto** en una línea: falta la cuenta, el pedido no está pagado, la
   factura es borrador, no hay factura, ya está pagada, o está todo bien.
6. La **lista de pedidos con factura y sin pago** (la misma consulta del
   barrido), para ver el alcance.

**Cómo leer el veredicto:**

- `SIN PAGO: falta la cuenta de destino...` → re-elegí y guardá la **Cuenta de
  destino** en **Configuración → Avanzado**.
- `SIN PAGO: la factura esta en borrador...` → el barrido debería abrirla y
  pagarla; revisá el cron `alegra_connector_payment_reconcile`.
- `SIN PAGO ESPERADO: el pedido no figura pagado...` → no es un bug; el pedido
  no está pagado en WooCommerce.
- `OK: el pedido ya tiene pago registrado...` → no hay nada que arreglar.

Copiá y pegá la salida completa al reportar un problema: tiene todo lo que se
necesita para diagnosticar sin acceso a tu sitio.

---

## ★ Webhooks — lo nuevo de la 2.3.7

### ★.a — Hechos confirmados en la documentación oficial

Fuente: [`developer.alegra.com/docs/descripción-general.md`](https://developer.alegra.com/docs/descripci%C3%B3n-general.md)
y [`reference/post_webhooks-subscriptions.md`](https://developer.alegra.com/reference/post_webhooks-subscriptions.md).

- **NO hay autenticación.** Alegra **no firma** los webhooks: **no hay firma, ni
  header, ni secreto, ni lista de IPs**. Por eso el plugin mete un **token secreto
  en la URL** (`?token=...`) y lo valida con `hash_equals`, con criterio
  **fail-closed** (sin token configurado, rechaza).
- **Handshake de creación.** Al crear una suscripción, Alegra hace un **POST con
  cuerpo vacío** a la URL y exige **2XX en menos de 5 segundos**; si no,
  **la suscripción NO se crea**. El plugin responde ese handshake con **2XX** (sin
  token: un cuerpo vacío no trae nada que procesar).
- **10 fallos consecutivos → la suscripción se elimina sola.** Cada entrega debe
  responder **2XX en menos de 5 segundos**. Si no ocurre en **10 intentos
  consecutivos**, Alegra **borra la suscripción automáticamente**. Por eso un
  handler que devuelve **500** no es "un error más": **acerca la suscripción a su
  borrado**.
- **Forma del payload.** `{ "subject": "<evento>", "message": { "item" | "client" | "invoice": { ... } } }`.
  El cuerpo del mensaje va bajo `message.item`, `message.client` o
  `message.invoice` según el evento.

### ★.b — Lo que arregló la 2.3.7

- **`new-client` ya no crashea.** Alegra manda `name` como **objeto**
  (`{firstName, lastName, ...}` o `{fullname}`). El plugin lo pasaba a una función
  que esperaba **string** → `TypeError` → **HTTP 500**. Ahora acepta string, el
  objeto documentado y `fullname`.
- **Un body malformado, un `subject` desconocido o un `message` no-array** ya no
  devuelven un no-2XX: responden **200 (ignorado)**. Solo un fallo de
  **autenticación** devuelve **401**.
- **Un `Throwable` dentro de un handler** se registra en el log y se responde
  **200**, en vez de 500.
- **Re-registrar es idempotente.** Alegra devuelve **400**
  `"Ya existe una suscripción con el mismo evento y URL"` si ya existe. Eso ya
  **no** se cuenta como error: el resultado informa **creados / ya existían /
  errores** por separado.

### ★.c — Configuración paso a paso (lo que pidió el comerciante)

1. **HTTPS obligatorio.** La URL debe ser **pública** y responder por **HTTPS**.
   Alegra exige POST por HTTPS. Si el sitio no tiene HTTPS válido, los webhooks
   no se crean.
2. **Borrá las suscripciones viejas (si querés empezar limpio).** En **Alegra
   Connector → Configuración → pestaña Avanzado → "Sincronización en Tiempo Real
   (Webhooks)"**, hacé clic en **"Eliminar webhooks en Alegra"**. Se borran las
   suscripciones guardadas localmente (por su **id**) y se limpia la lista local.
   > **Ojo:** si la lista local quedó vacía pero en Alegra hay suscripciones
   > viejas, el botón **no las ve**. En ese caso **re-registrá primero** (paso 3)
   > —que ahora recupera los ids de las que ya existen— y después eliminá, o
   > borralas a mano en Alegra.
3. **Registrá.** En la misma tarjeta, verificá que la **"URL del Webhook"** incluya
   `?token=...` (el token se genera y persiste solo). Hacé clic en **"Registrar
   webhooks en Alegra"**. Debe registrar los **12 eventos** (facturas, facturas de
   compra, clientes e ítems) y mostrar algo como **"12 webhooks registrados, 0 ya
   existían, 0 errores."**
   - Si ya estaban registrados, el mensaje dirá **"0 webhooks registrados, 12 ya
     existían, 0 errores."** — **eso es éxito**, no un fallo.
4. **Verificá en Alegra.** En **Alegra → Webhooks** (o la sección de
   integraciones), confirmá que las **12 suscripciones** apuntan a la URL **con
   token**.
5. **Probá con un evento real.** Cambiá algo en Alegra (por ejemplo, un ítem o un
   cliente) y verificá que WooCommerce reacciona y que en **Alegra Connector →
   Logs** aparece el evento procesado (`Processing webhook event`).
6. **Troubleshooting.**
   - **No se crea ninguna suscripción:** la URL no responde **2XX en <5s**. Probá
     abrir la URL en el navegador (debería dar **401** sin token, y **200** con el
     token correcto) y revisá que no haya un firewall/WAF bloqueando el POST.
   - **Todo responde 401:** el token de la URL no coincide con el guardado.
     Re-guardá los ajustes y re-registrá.
   - **Los webhooks dejan de llegar solos:** revisá si Alegra borró las
     suscripciones por **10 fallos consecutivos**. Mirá los logs del sitio por
     errores 5xx en la ruta del webhook.
   - **Resultado esperado:** 12 suscripciones activas apuntando a la URL con token
     y un cambio en Alegra que llega a WooCommerce.
   - **Riesgo: Alto (seguridad y confiabilidad).** Sin re-registrar, los webhooks
     no llegan; con un handler que devuelve 5xx, la suscripción se borra sola.

### ★.d — Verificación en vivo: ¿Alegra conserva el query string?

**Estado: NO probado en vivo. Requiere prueba en vivo.**

La documentación oficial **no dice** si Alegra **conserva el query string**
(`?token=...`) de la URL registrada al entregar el evento. El plugin lo asume. Si
Alegra lo **elimina**, la entrega llegará **sin token** → el endpoint responde
**401** (seguro, fail-closed) → y como es un no-2XX, cuenta como fallo: **tras 10
fallos consecutivos Alegra borra la suscripción**. O sea: no habría fuga de
seguridad, pero **los webhooks no funcionarían**.

**El chequeo exacto:**

1. Registrá los webhooks (★.c) y confirmá en Alegra que la URL guardada **incluye
   `?token=...`**.
2. En Alegra, dispará un evento real (editá un ítem o un cliente).
3. En **Alegra Connector → Logs**, buscá la entrada del evento. **Si aparece
   `Processing webhook event`**, Alegra **conservó** el query string → el token en
   la URL funciona.
4. **Si en cambio ves `Webhook rejected: missing or invalid token`** (o el log del
   servidor muestra un **401** en `/wp-json/alegra-connector/v1/webhook`), Alegra
   **NO** conservó el query string. En ese caso:
   - el endpoint sigue **seguro** (401 fail-closed), pero
   - **hay que registrar la URL con el token en el path** (o usar un header/otra
     vía); y hay que hacerlo **antes de que se acumulen 10 fallos**, porque Alegra
     borra la suscripción.
5. **Prueba del endpoint (complementaria):** con el token guardado a mano, hacé un
   POST a la URL **con** `?token=<token>` (debe dar **200**) y **sin** él (debe dar
   **401**). Eso valida el endpoint, pero **no** responde la pregunta de si Alegra
   conserva el query string: para eso hace falta el **evento real**.


### ★.e — Inspector de webhooks (solo lectura) y la prueba de `edit-item` con stock

**Pregunta que responde:** ¿Alegra manda `edit-item` cuando cambia **solo el
inventario** de un ítem, y ese payload trae
`message.item.inventory.availableQuantity`? Es lo que decide si el stock se
reconcilia por **webhook** o por **poll**.

El receptor ahora guarda un **buffer acotado** de las últimas **50** entregas
(subject, body crudo, fecha e IP; un body mayor a **20 KB** se trunca). El
inspector **solo lee** ese buffer: no escribe en Alegra ni cambia estado.

**Opción 1 — WP-CLI (recomendado):**

```bash
wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --limit=20
wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --event=edit-item
wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --item=865
```

También como comando: `wp alegra-inspect-webhooks --event=edit-item`.

**Opción 2 — Navegador (si no tenés WP-CLI):**

1. Copiá `scripts/inspect-webhooks.php` a la **raíz de WordPress** (donde está
   `wp-load.php`).
2. Abrí `https://TU-SITIO/inspect-webhooks.php?event=edit-item` con tu usuario.
3. Requiere sesión con permiso **`manage_woocommerce`**; sin eso responde **403**.
   Borrá el archivo cuando termines.

**Procedimiento de prueba exacto:**

1. En **Configuración → Avanzado → Sincronización en Tiempo Real (Webhooks)**,
   verificá que el evento **`edit-item`** esté **tildado** (selector de eventos) y
   **registrá** los webhooks.
2. En Alegra, **anotá el stock** de un producto (por ejemplo, 10).
3. **Anulá una factura** que haya descontado ese producto (o hacé un **ajuste de
   inventario**). Eso cambia el inventario del ítem.
4. Corré el inspector (opción 1 o 2) y buscá una entrega con `subject=edit-item`.
5. Leé el **veredicto** de la sección 3.

**Cómo leer la salida:**

- `VEREDICTO: Alegra SÍ envía inventario en edit-item` → el payload trae
  `availableQuantity`: el stock se puede reconciliar **por webhook**.
- `VEREDICTO: Alegra NO envía inventario en edit-item — la reconciliación debe
  ser por poll` → la entrega llegó **sin** inventario: hay que seguir con el
  **poll** (sincronización programada).
- `ATENCION: no hay NINGUNA entrega de edit-item...` → no llegó el evento:
  revisá que esté suscrito, que el webhook responda 2XX y que el token de la URL
  sea el correcto (ver ★.c y ★.d).

- **Riesgo: Bajo.** No bloquea el release: el poll es la red de seguridad. Pero
  **define** si la reconciliación de stock en tiempo real es posible.


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
  5. **Más fácil:** corré el **inspector ★.e**, que muestra el payload crudo de
     cada `edit-item` y da el **veredicto** (SÍ/NO trae inventario).
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
2. **Desplegar** e instalar la **2.4.0**. Ver `RELEASE_2.3.0_DEPLOY.md` §3.
3. **★★★ Write gates (lo nuevo de la 2.4.0):** kill switch (★★★.a), barrido
   (★★★.b), botón de nota de crédito (★★★.c), checkboxes (★★★.d), mapeos
   (★★★.e) y Consumidor Final en el render (★★★.f). **Hacelo primero:** cambia
   el comportamiento de escritura.
4. **★ Re-registrar los webhooks (obligatorio).** Sin esto, no hay tiempo real.
5. **★★ Productos:** stock (★★.a), categoría comercial (★★.b) y pull de
   inventario (★★.c). Es lo más importante de esta versión.
6. **★ Clientes:** importación sin salteos (★.a), Consumidor Final (★.b) y
   CO+FE (★.c).
7. **★ Pedidos:** envío/totales (★.a), reembolso parcial (★.b), impuestos (★.c)
   y errores visibles (★.d).
8. **★ Orquestación:** "Run now"/"Skip" conservan el cron (★.a), "Stop" (★.b) y
   el wizard (★.c).
9. **Ítems 1 y 4:** stock por webhook/poll y condicionales de Blocks.
10. **Pedido real de bajo valor.** Acá empieza lo que toca dinero real: confirmá
    que la factura se crea en Alegra (en borrador por defecto), vinculada al
    cliente correcto y con el total correcto.
11. **Reembolso parcial** del pedido anterior → en modo manual, usá el botón
    **"Emitir nota de crédito"** (★★★.c) para crear la nota ligada a la factura.

---

## Qué hacer si algo falla

1. **Revisá primero los logs:** `Alegra Connector → Logs` (y en disco,
   `wp-content/uploads/alegra-logs/`). El mensaje de Alegra suele decir
   exactamente qué campo rechazó.
2. **Anotá el caso:** pedido, hora, qué hiciste y el mensaje textual. Sin eso,
   no se puede diagnosticar.
3. **Rollback a 2.3.11** (el procedimiento completo está en
   `RELEASE_2.3.0_DEPLOY.md` §6):
   - Desactivá **"Alegra Connector"** en **Plugins**.
   - Reemplazá la carpeta por `releases/alegra-connector-v2.3.11.zip`.
   - Reactivá el plugin.
   - **Ojo:** la 2.3.0 migró las columnas `alegra_id` de `BIGINT` a
     `VARCHAR(36)`; eso **no** se revierte. Con IDs numéricos, versiones previas
     siguen funcionando.
   - **Ojo:** la 2.4.0 sembró `push_customers_enabled` desde
     `push_products_enabled`; la 2.3.11 **no** lee esa opción, así que el push de
     clientes vuelve a depender del gate de productos.
   - **Ojo:** la 2.4.0 **deshabilitó** la nota de crédito automática en modo
     manual; al volver a 2.3.11 esa automatización se reactiva.
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
- [ ] 2.4.0 instalado y la versión figura como **2.4.0** en **Plugins**.

### Write gates — lo nuevo de la 2.4.0
- [ ] **★★★.a** Con el plugin desconectado, "Facturar" **no** escribe en Alegra
      (ni factura ni pago).
- [ ] **★★★.b** `payment_reconcile_enabled=false` frena el **barrido** y la
      reconciliación en tiempo real.
- [ ] **★★★.c** En modo manual, el reembolso **no** emite nota sola; el botón
      **"Emitir nota de crédito"** emite **exactamente una**.
- [ ] **★★★.d** Los cuatro checkboxes de "qué sincronizar" coinciden con lo que
      hace el cron; `push_customers_enabled` respeta la migración.
- [ ] **★★★.e** Guardar Configuración **conserva** los mapeos de campos/impuestos.
- [ ] **★★★.f** Abrir el dashboard **no** crea el Consumidor Final en Alegra.

### Pagos — lo nuevo de la 2.3.11
- [ ] **★★★.a** "Facturar" un pedido **pagado** → **1 pago** en Alegra y la
      factura deja de estar "Por Cobrar"; `_alegra_payment_id` seteado.
- [ ] **★★★.b** "Facturar" un pedido **impago** → factura sí, **0 pagos**, con
      nota explicativa.
- [ ] **★★★.c** Pago posterior a una factura ya creada → se adjunta **solo**
      (hook y/o barrido), sin duplicar.
- [ ] **★★★.d** Cuenta de destino con label "banco o caja" y valor guardado
      seleccionado; checkout con "Seleccione…" en tipo de documento.

### Re-registrar webhooks (obligatorio)
- [ ] **★** La "URL del Webhook" incluye `?token=...`.
- [ ] **★** "Registrar webhooks en Alegra" reporta **12 eventos** (o **"12 ya
      existían"** si ya estaban: eso es éxito, no un error).
- [ ] **★** Un cambio en Alegra dispara el webhook (visible en los logs).
- [ ] **★.d** El evento real llega **con** el token (no aparece `Webhook
      rejected: missing or invalid token`).

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
- [ ] **★.e** El inspector de webhooks confirma si `edit-item` trae
      `inventory.availableQuantity` (veredicto SÍ/NO).
- [ ] **4.** En Blocks: al elegir NIT aparece el Dígito de verificación (y se
      oculta con los demás tipos); Persona Jurídica muestra Razón social.
- [ ] **5.** CO + FE: la factura sale a nombre del **cliente**.

### Cierre
- [ ] "Modo de prueba" **desactivado**.
- [ ] "Estado de las facturas" en el estado deseado (borrador por defecto).
- [ ] Logs revisados y sin errores `[Alegra]` repetidos.
