# Guía de Despliegue — Alegra Connector 2.3.0

Esta guía explica cómo actualizar el plugin desde la versión **2.2.0** a la
**2.3.0** en la tienda. La 2.3.0 es una actualización grande: arregla la
facturación electrónica, agrega los campos de facturación de Colombia y
soporte para HPOS. Léela completa antes de empezar.

> **Resumen para el dueño de la tienda:** 2.3.0 arregla el error
> *"el cliente no existe"* que impedía emitir facturas, agrega 11 campos de
> facturación para la DIAN, permite emitir o no ante la DIAN, agrega el
> respaldo de Consumidor Final, notas crédito para reembolsos y soporte para
> el nuevo almacenamiento de pedidos de WooCommerce (HPOS).

---

## 1. Qué cambió en la versión 2.3.0

### 1.1 Se arregló el error "el cliente no existe" al facturar

**El problema:** al crear una factura, el plugin enviaba los datos del cliente
*en línea* dentro de la factura (nombre, identificación, etc.). La API de Alegra
rechaza eso con el error **"el cliente no existe"**, porque toda factura debe
referenciar un **contacto ya existente por su `id`**.

**El arreglo:** antes de facturar, el plugin ahora resuelve (o crea) el contacto
del cliente y recién después emite la factura apuntando a ese contacto:

1. Busca el contacto guardado en el pedido o en el usuario.
2. Si no está, lo busca en Alegra por correo electrónico.
3. Si no está, lo busca por tipo y número de identificación.
4. Si no existe y hay datos completos, lo **crea** en Alegra.
5. Si no hay datos suficientes, usa el **Consumidor Final** (ver 1.4).

Además, las **notas crédito** (reembolsos) ahora se envían con el arreglo
plural `invoices`; antes se enviaban en singular y Alegra las rechazaba.

### 1.2 Once campos de facturación para Colombia

La 2.3.0 agrega un catálogo de 11 campos que se le piden al cliente en el
checkout y en su cuenta, agrupados así:

| Grupo | Nombre en el panel | Campos |
|---|---|---|
| **A** | Obligatorios | Tipo de persona, Tipo de documento, Número de documento, Dígito de verificación, Régimen tributario |
| **B** | Recomendados | Razón social, Segundo nombre, Segundo apellido |
| **C** | Opcionales | Teléfono secundario, Celular, Observaciones |

Los campos del grupo **A** están fijos: no se pueden desactivar, porque sin
ellos la factura electrónica no es válida. Los grupos B y C se activan o
desactivan desde **Alegra Connector → Ajustes → Campos de facturación**.

### 1.3 Emisión ante la DIAN (estampado)

Cuando la opción **"Emitir facturas ante la DIAN"** está activa, el plugin le
pide a Alegra que emita (estampe) la factura y las notas crédito ante la DIAN.
Si la desactivas, los documentos quedan en **borrador** y NO se envían a la
DIAN (solo para pruebas).

### 1.4 Consumidor Final como respaldo

Si un pedido no tiene datos de facturación suficientes (o el cliente no se
puede resolver), el plugin factura al contacto **"Consumidor Final"** que ya
existe en tu cuenta de Alegra. El plugin **nunca crea** ese contacto: si no
existe, hay que crearlo a mano en Alegra con estos datos exactos:

- Identificación: `222222222222`
- Tipo de identificación: `CC`
- Tipo de persona: `PERSON_ENTITY`
- Régimen: `SIMPLIFIED_REGIME`

### 1.5 Reembolsos (notas crédito)

Cada reembolso de WooCommerce genera una nota crédito en Alegra. El plugin
lleva un acumulado por pedido (`_alegra_credited_amount`) y **nunca permite
acreditar más que el total de la factura original**.

### 1.6 Soporte HPOS (nuevo almacenamiento de pedidos)

WooCommerce puede guardar los pedidos en tablas propias (HPOS) en vez de
`wp_posts`. La 2.3.0 lee y escribe los datos del pedido con la API oficial de
WooCommerce, por lo que funciona con HPOS activado.

---

## 2. Antes de actualizar

### 2.1 Respaldos (obligatorio)

**Respalda la base de datos y los archivos antes de tocar nada.**

- **Base de datos:** cPanel → *phpMyAdmin* → selecciona tu base → pestaña
  *Exportar* → método *Rápido* → *Continuar* y descarga el `.sql`.
  (O pide a tu hosting que corra un backup completo.)
- **Archivos:** cPanel → *Administrador de archivos* → entra a
  `wp-content/plugins/` → clic derecho en `alegra-connector` → *Comprimir* →
  descarga el `.zip` resultante a tu computador.

### 2.2 Verifica el paquete de la versión

El paquete de esta versión es:

```
releases/alegra-connector-v2.3.0.zip
releases/alegra-connector-v2.3.0.zip.sha256
```

Si el ZIP todavía no existe en tu copia del repositorio, genéralo con:

```bash
./scripts/build-release.sh 2.3.0
```

Verifica el `sha256` (opcional pero recomendado) y corre el smoke test antes de
subirlo:

```bash
sha256sum releases/alegra-connector-v2.3.0.zip
cat releases/alegra-connector-v2.3.0.zip.sha256

bash scripts/smoke-test.sh releases/alegra-connector-v2.3.0.zip
# Debe terminar en: === SMOKE-TEST OK: all assertions passed ===  /  SMOKE OK
```

Si el smoke test falla, **NO subas el ZIP**.

---

## 3. Cómo actualizar

### 3.1 Recomendado: WordPress ("Subir plugin")

1. Entra a `https://<tu-tienda>/wp-admin/`.
2. Menú lateral → **Plugins** → **Añadir nuevo**.
3. Pestaña **Subir plugin** → **Elegir archivo** → selecciona
   `alegra-connector-v2.3.0.zip`.
4. Clic en **Instalar ahora**.
5. WordPress detecta la instalación existente y **reemplaza los archivos en el
   mismo directorio** `wp-content/plugins/alegra-connector/`. Verás
   *"Plugin actualizado correctamente."*
6. **No actives todavía**: primero verifica (sección 4).

### 3.2 Alternativa: cPanel (Administrador de archivos)

1. cPanel → *Administrador de archivos* → navega a
   `public_html/wp-content/plugins/`.
2. Sube `alegra-connector-v2.3.0.zip` a esa carpeta.
3. Clic derecho al ZIP → **Extraer**. Confirma que sobrescriba la carpeta
   `alegra-connector/` existente.
4. Borra el ZIP del servidor cuando termine.
5. Ve a **Plugins** en WordPress y confirma que "Alegra Connector" figure como
   versión **2.3.0** (si se desactivó, actívalo).

> La actualización **no borra** tus credenciales, mapeos, logs ni opciones.

---

## 4. Verificación después de actualizar (con Modo de prueba)

Sigue este camino exacto para verificar **sin** crear nada real en Alegra.

1. **Activa el Modo de prueba.** Ve a
   **Alegra Connector → Ajustes → Facturación electrónica** → marca
   **"Modo de prueba (Dry Run)"** → **Guardar cambios**.
2. **Haz un pedido de prueba** en la tienda (producto económico, pago contra
   entrega o transferencia). Completa los campos de facturación.
3. **Revisa el log.** Ve a **Alegra Connector → Logs**. Debes ver líneas
   `[DRY RUN] Blocked POST ...` y, si no había datos, un mensaje indicando que
   se usó el **Consumidor Final**. Con Dry Run **no** debe crearse nada en
   Alegra.
4. **Revisa el pedido.** En el detalle del pedido debe figurar el contacto
   resuelto (`_billing_alegra_contact_id`) y **ninguna** factura creada.
5. **Si usas el checkout por bloques (Blocks):** confirma que el
   **Dígito de verificación** solo aparece cuando el tipo de documento es
   **NIT**, y **Razón social** solo cuando el tipo de persona es
   **Persona Jurídica**.
6. **Desactiva el Modo de prueba.** Vuelve a Ajustes, **desmarca**
   "Modo de prueba (Dry Run)" → **Guardar cambios**.
7. **Haz un pedido real** y espera 30–60 segundos.
8. **Verifica en Alegra:** debe aparecer la factura con el **cliente correcto**.
   Si "Emitir facturas ante la DIAN" está activo, revisa que la factura quede
   **emitida** ante la DIAN.

---

## 5. Los 3 ajustes nuevos explicados

Todos están en **Alegra Connector → Ajustes → Facturación electrónica**.

### 5.1 ¿A nombre de quién se factura? (`customer_resolution_mode`)

| Opción | Qué hace | Consecuencia |
|---|---|---|
| **Automático (recomendado)** | Usa los datos de facturación del cliente. Si faltan, factura al Consumidor Final. | Siempre se factura. Si el cliente no dejó datos, la factura sale a nombre del Consumidor Final (no del cliente). |
| **Siempre Consumidor Final** | Todas las facturas se emiten al Consumidor Final. | El cliente **nunca** recibe factura a su nombre. Útil solo si no necesitas factura por cliente. |
| **Exigir datos al cliente** | No se puede pagar sin NIT/cédula. Desactiva el respaldo de Consumidor Final. | El checkout **bloquea** el pago si faltan datos. Si no se puede resolver el cliente, la factura se **aborta** con `customer_unresolved` en vez de usar el genérico. |

> En **Exigir datos**, los campos de facturación pasan a ser **obligatorios** en
> el checkout. En **Automático** y **Siempre Consumidor Final** quedan
> opcionales para que el respaldo pueda funcionar.

### 5.2 Emitir facturas ante la DIAN (`stamp_enabled`)

- **Activado (por defecto):** el plugin pide a Alegra que emita (estampe) la
  factura y las notas crédito ante la DIAN.
- **Desactivado:** los documentos quedan en **borrador**, sin enviarse a la
  DIAN. Úsalo solo para pruebas.

> Solo aplica a cuentas de Colombia. Si tu cuenta está configurada en otro
> país, esta opción no tiene efecto.

### 5.3 Modo de prueba / Dry Run (`dry_run`)

- **Activado:** el plugin **NO envía nada** a Alegra. No crea facturas,
  clientes, productos, categorías ni notas crédito. Las lecturas (GET) sí
  funcionan.
- **Desactivado (por defecto):** operación normal.

> **Acuérdate de desactivarlo.** Mientras esté activo, ninguna venta se
> factura realmente.

---

## 6. Revertir a la versión 2.2.0 (rollback)

Si algo sale mal:

1. En WordPress → **Plugins** → desactiva **"Alegra Connector"**.
2. En cPanel → *Administrador de archivos*, renombra o elimina la carpeta
   `wp-content/plugins/alegra-connector/`.
3. Sube e instala `releases/alegra-connector-v2.2.0.zip` (método de la
   sección 3).
4. Activa el plugin.

**Qué tener en cuenta al revertir:**

- La 2.3.0 migra las columnas `alegra_id` de las tablas propias del plugin de
  `BIGINT` a `VARCHAR(36)` (para aceptar UUIDs). Esa migración **no se
  revierte** al volver a 2.2.0. Para IDs numéricos 2.2.0 sigue funcionando,
  pero la columna queda como texto.
- Las opciones nuevas (`customer_resolution_mode`, `stamp_enabled`,
  `dry_run`) simplemente se ignoran en 2.2.0; no estorban.
- Los pedidos ya facturados con 2.3.0 conservan sus datos (`_alegra_invoice_id`,
  `_billing_alegra_contact_id`), que 2.2.0 respeta.

---

## 7. Limitaciones conocidas (lo que NO se probó contra la API real de Alegra)

Estas cosas están implementadas y pasan las pruebas automáticas, pero **no se
verificaron todavía contra la API real de Alegra**. Verifícalas en tu cuenta
antes de confiar en ellas a ciegas:

- **`edit-item` al cambiar stock.** No está confirmado que Alegra dispare el
  webhook `edit-item` cuando lo único que cambia es el inventario de un
  producto. Si no lo dispara, la sincronización de stock en tiempo real puede
  no activarse por esa vía.
- **`idItemCategory` con varios valores.** No se verificó si Alegra acepta
  **más de un** `idItemCategory` por producto; el plugin envía uno.
- **Envío de categoría como `{id}`.** No está confirmado que el endpoint de
  categorías acepte un objeto `{id}` (frente a un nombre plano). El plugin
  asume el formato `{id}`.
- **Condicionales del checkout por bloques.** La ruta
  `customer.address.<campo>` se validó contra la documentación oficial de
  WooCommerce y contra el esquema, pero **no** contra un checkout Blocks real
  con este plugin. Si un campo condicional no se muestra/oculta, es el primer
  sitio a revisar.
- **Estampado DIAN (`stamp.generateStamp`).** El nombre y la forma del campo
  se tomaron de la documentación de Alegra; confírmalo con una factura real
  antes de darlo por bueno.
- **Nota crédito con el arreglo plural `invoices`.** Se corrigió según la API
  de Alegra, pero no se probó un reembolso real de punta a punta.
- **Consumidor Final debe existir en Alegra.** El plugin lo busca pero **nunca
  lo crea**. Si no existe el contacto con identificación `222222222222` tipo
  `CC`, el respaldo falla y la factura se aborta.
- **HPOS.** La lectura/escritura de pedidos se hizo con la API oficial de
  WooCommerce, pero no se probó en una tienda real con HPOS activado.
- **Concurrencia de reembolsos.** El tope acumulado
  (`_alegra_credited_amount`) se protege con un bloqueo temporal, no con una
  transacción de base de datos. Reembolsos simultáneos en el mismo pedido
  podrían, en teoría, competir.

---

## 8. Checklist final

- [ ] Respaldo de base de datos y de archivos hecho.
- [ ] `sha256` del ZIP coincide con el `.sha256`.
- [ ] Smoke test del ZIP termina en `SMOKE OK`.
- [ ] ZIP 2.3.0 subido e instalado.
- [ ] En **Plugins** figura la versión **2.3.0**.
- [ ] El sitio carga sin errores fatales.
- [ ] Modo de prueba: pedido de prueba, log con `[DRY RUN] Blocked ...`, sin
      nada creado en Alegra.
- [ ] Modo de prueba desactivado.
- [ ] Pedido real: la factura aparece en Alegra con el cliente correcto.
- [ ] (Si aplica) La factura queda **emitida** ante la DIAN.
- [ ] Los campos condicionales se muestran/ocultan bien (NIT → Dígito de
      verificación; Persona Jurídica → Razón social).
