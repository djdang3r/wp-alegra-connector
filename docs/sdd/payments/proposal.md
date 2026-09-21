# Propuesta — Pagos, Reconciliación y Ajustes del Alegra Connector

| Campo | Valor |
|---|---|
| Cambio | `payments` (registro de pagos + reconciliación + cuenta de destino + release) |
| Tipo | Corrección de bugs en producción + endurecimiento de proceso de release |
| Estado | Propuesta — **causa raíz #1 CONFIRMADA**; quedan verificaciones en vivo (Fase 0) |
| Versión analizada | 2.3.10 (`alegra-connector.php:6`) · constante `2.3.7` (`alegra-connector.php:29`) |
| Artefactos SDD | `proposal.md`, `spec.md`, `design.md`, `tasks.md` |
| Fuera de alcance | Facturación electrónica DIAN (`stamp`, `paymentForm`, campos DIAN) |

> Todo claim de código cita `archivo:línea`. Lo no verificable está marcado
> **SIN VERIFICAR** o **BLOQUEADO**. La Fase 0 de `tasks.md` resuelve los bloqueos
> restantes antes de escribir la parte de producción que depende de ellos.

---

## 1. Intención

Hacer que **facturar un pedido pagado registre el pago en Alegra de una vez**, y que
el plugin sepa si el pedido está pagado **leyéndolo de WooCommerce** —no
adivinándolo—, con el método y la pasarela que el comerciante configuró. Y hacer que
el pago llegue **sin importar el orden de los eventos**: la factura puede subirse
primero y el pago confirmarse después; el plugin debe registrarlo contra la factura
ya existente. Finalmente, que la configuración de la **cuenta de destino** no pueda
destruirse sola al guardar ajustes.

En una frase: **una sola ruta idempotente que registra el pago sobre una factura ya
vinculada, disparada por el botón "Facturar", por los eventos de pago de
WooCommerce y por un barrido de reintento, tomando la verdad del pago desde
WooCommerce; más un formulario de ajustes que nunca pisa en silencio lo que el
comerciante eligió.**

## 2. Problema (en lenguaje del dueño de la tienda)

### 2.1 Causa raíz confirmada — el botón "Facturar" no registra el pago

El flujo real del comerciante es: detalle del pedido → **"Crear factura"**. Ese botón
llama `Admin_Dashboard.php:2216` → `sync_entity('order', id, 'create')` →
`Controller.php:310-311` → `Orders::create_invoice()` (`Orders.php:47`), **un camino
que NO tiene una sola línea de lógica de pago**. La factura se crea y el pago nunca
se postea. Por eso la factura queda **"Por Cobrar"** en Alegra.

Los caminos que **sí** registran pago funcionan correctamente con la cuenta `'5'`:

- `ajax_sync_pending_page` (`Admin_Dashboard.php:2763`) → `create_invoice_with_payment()`.
- `woocommerce_payment_complete` / `woocommerce_order_status_completed` →
  `sync_single_order('complete')` → `Controller.php:312-313` →
  `create_invoice_with_payment()`.
- El botón explícito **"Registrar pago en Alegra"** (`ajax_record_payment`,
  `Admin_Dashboard.php:2093`).

**Conclusión:** el bug no es de configuración ni de cuenta; es que **el botón que el
comerciante usa va por un camino sin pago**. La corrección es que "Facturar" use el
camino con pago, condicionado a que el pedido esté pagado según WooCommerce.

### 2.2 Diagnóstico anterior (incorrecto) — descartado

Una versión previa de este SDD atribuyó el fallo a que la cuenta de destino se
"clobbeaba" a `'0'`. **Eso NO es la causa.** El comerciante pegó el `<select>`
renderizado real:

```html
<option value="0">-- Sin cuenta (no se registraran pagos) --</option>
<option value="5" selected="selected">Caja Pagina Web (ID: 5)</option>
```

- La cuenta está guardada correctamente como **`'5'`** y se lee correctamente.
- Es un **id numérico**, no un UUID. La lista de cuentas trae ids numéricos
  (`5,4,3,2,1`) e incluye **cajas** (cajas de efectivo), no solo cuentas bancarias.

El clobber del `<select>` **sigue siendo un bug real, pero latente** (no se reprodujo
en esta tienda): baja a severidad MEDIA y a fase de endurecimiento.

### 2.3 Otras cosas que combinadas impiden la reconciliación

Aunque la causa #1 esté corregida, persisten dos agujeros que dejan pedidos pagados
sin pago cuando la factura ya existía:

1. **En modo manual nadie reconcilia el pago posterior.** Los hooks que registrarían
   ese pago viven dentro de
   `if (get_option('alegra_connector_push_orders_enabled', false))`
   (`Public_.php:49-59`), cuya opción **por defecto es `false`**. Además
   `woocommerce_order_status_processing` **no está registrado en ningún lado** y no
   existe ningún reintento/barrido. Resultado: un pedido pagado con factura ya creada
   y sin pago **queda así para siempre**, salvo que alguien apriete "Registrar pago" a
   mano.
2. **La fecha del pago se inventa.** `prepare_payment_data` (`Orders.php:1477`) usa
   `date('Y-m-d')` en lugar de `$order->get_date_paid()`. El monto sí sale de
   `$order->get_total()` (`:1482`), pero la fecha no sale de WooCommerce.

### 2.4 Semántica de estados de WooCommerce (verificada contra WC core)

- `wc_get_is_paid_statuses()` → `['processing', 'completed']`;
  `WC_Order::is_paid()` → `has_status()` de esos estados.
- **`processing` YA significa que el pago fue recibido** (pedido en preparación).
  `payment_complete()` pone `processing` cuando el pedido requiere fulfillment, y
  `completed` en caso contrario.
- El modelo mental del comerciante ("procesando = inseguro, procesado = seguro") es
  una **colisión de terminología**: lo que él llama "Procesando" es casi seguro el
  estado de la **pasarela** (Mercado Pago confirmando), mientras WooCommerce mantiene
  el pedido en `pending`/`on-hold` (que **no** son pagados). Requiere confirmación en
  vivo (Fase 0.6).
- `woocommerce_order_status_processing` **NO está registrado** por el plugin
  (confirmado).
- Todos los hooks de pedido están dentro del gate `push_orders_enabled`
  (`Public_.php:49-59`), default `false`.

### 2.5 Hallazgos verificados (ordenados por severidad y causalidad)

| # | Severidad | Hallazgo | Evidencia |
|---|---|---|---|
| 1 | ALTA — **CONFIRMADO** | "Facturar" del detalle del pedido crea la factura por un camino **sin lógica de pago**; el pago nunca se postea | `Admin_Dashboard.php:2196-2225` (`:2216`), `Controller.php:310-311`, `Orders.php:47` |
| 2 | ALTA | No hay reconciliación del pago posterior en modo manual: hooks gateados + `processing` sin registrar + sin barrido | `Public_.php:49-59`; `Orders.php:279-386,1751-1844` |
| 3 | MEDIA | La fecha del pago se inventa (`date('Y-m-d')`) en vez de usar `$order->get_date_paid()` | `Orders.php:1477` |
| 4 | MEDIA | Fallback inconsistente para pasarela desconocida: `'transfer'` en `get_payment_method_code` (`:1529`) vs `'cash'` en `getPaymentMethodForGateway` (`:1546`) | `Orders.php:1518-1547` |
| 5 | MEDIA — latente | El `<select>` de cuenta puede clobbear el valor guardado con `'0'` si la lista de `/bank-accounts` no trae la cuenta guardada | `templates/admin-settings.php:337-350`; `Admin_Dashboard.php:288-304,363-364` |
| 6 | MEDIA | El label dice "Cuenta bancaria" pero la lista incluye **cajas** | `templates/admin-settings.php` |
| 7 | MEDIA | El checkout arranca en "Registro Civil" (sin opción vacía) | `Billing_Fields.php:103-108,499-501,595-602`; `Checkout_Integration.php:188-202` |
| 8 | MEDIA | Versión stale (cache-buster) + release no publica el "latest" correcto | `alegra-connector.php:6,29`; `Admin_Dashboard.php:503-504,514`; `Checkout_Integration.php:251`; `scripts/build-release.sh`; `README.md:29` |

### 2.6 Lo que el hallazgo 1 significa, sin maquillaje

**El comerciante aprieta "Facturar", la factura aparece en Alegra, y cree que el
pedido quedó facturado — pero el pago nunca se registró.** No hay error, no hay
aviso: el flujo manual simplemente no tiene la lógica. Es exactamente el síntoma que
motivó este trabajo. La cuenta `'5'` está bien configurada; el problema es la ruta.

## 3. Alcance

**Dentro:**

- **Hallazgo 1 (causa raíz):** que **"Facturar"** registre el pago **inmediatamente
  cuando `$order->is_paid()`**, leyendo de WooCommerce el monto, la fecha y el
  método/pasarela. Si el pedido no está pagado, la factura se crea y **no** se postea
  pago (con aviso al comerciante).
- **Fuente de verdad del pago:** una sola función que arma el pago desde WC
  (`is_paid()`, `get_date_paid()`, `get_total()`, `get_payment_method()`,
  `get_payment_method_title()`) y un **mapeo pasarela → `paymentMethod` de Alegra**
  único, con caso explícito **Mercado Pago** y fallback seguro para desconocidas.
- **Hallazgo 2:** registrar los hooks de pago **fuera** del gate
  `push_orders_enabled`, con el guard exacto (factura vinculada + sin pago + pedido
  pagado); registrar `woocommerce_order_status_processing`; agregar un barrido de
  reintento (cron) idempotente y con kill-switch/lock.
- **Modo borrador:** si `invoice_status = draft`, registrar un pago exige abrir la
  factura antes; hay que **verificar/corregir** el endpoint actual
  (`POST /invoices/{id}/open`). BLOQUEADO pendiente de verificación en vivo.
- **Hallazgo 5 (latente):** garantizar que el `<select>` de cuenta de destino siempre
  tenga seleccionada la opción correcta; sanitizador que **avise** al rechazar;
  default explícito; render tolerante a fallo de `/bank-accounts`.
- **Hallazgo 6:** el label debe decir **banco o caja**.
- **Hallazgo 7:** opción vacía "Seleccione…" en el checkout clásico y en Blocks.
- **Hallazgo 8:** una sola fuente de verdad para la versión; validación de
  consistencia en el build; tag + Release automáticos; README apuntando a GitHub
  Releases.

**Fuera de alcance (excluido explícitamente):**

- **Facturación electrónica DIAN.** Nada de `stamp`, `paymentForm` ni campos DIAN. El
  test `T18.15` (`scripts/exec-test.php:2841-2846`) exige que el texto de ajustes diga
  que el plugin **NO** emite a la DIAN; se mantiene.
- Reescritura del subsistema de inventario (otro SDD: `docs/sdd/inventory/`).
- Migración de datos históricos (no se tocan pagos ya registrados).

## 4. Criterios de éxito

Un cambio se considera exitoso cuando, **para cualquier tienda** (no solo la del
reportante):

1. **Al hacer clic en "Facturar" sobre un pedido pagado, se registra exactamente un
   pago en Alegra**, usando el **monto, la fecha y el método tomados de
   WooCommerce** (`$order->get_total()`, `$order->get_date_paid()`,
   `$order->get_payment_method()` mapeado a un `paymentMethod` válido de Alegra).
2. **WooCommerce es la fuente de verdad.** El plugin decide si registrar el pago con
   `$order->is_paid()` y toma método/pasarela de WC; nunca inventa ni adivina.
3. **Funciona con cualquier pasarela/método que el comerciante configure**, incluido
   Mercado Pago; una pasarela desconocida cae a un `paymentMethod` válido y **no
   falla** el registro.
4. Guardar cualquier ajuste **no puede** convertir una cuenta configurada en `0`. Si
   el valor es inválido, la pantalla **muestra un error** y conserva el valor
   anterior; nunca dice "guardado correctamente" cuando rechazó algo.
5. Un pedido **pagado** con factura ya vinculada y sin pago termina con
   `_alegra_payment_id` seteado y **exactamente un** `POST /payments` en Alegra, ya
   sea por "Facturar", por evento de WooCommerce o por el barrido.
6. Un pedido **no pagado** nunca registra un pago; si la cuenta no está configurada,
   el sistema **avisa** (log + nota de pedido) en vez de saltear en silencio.
7. En modo manual (`push_orders_enabled = false`), el plugin **nunca crea facturas**
   por su cuenta; solo reconcilia pagos sobre facturas ya vinculadas.
8. El `<select>` de tipo de documento del checkout arranca en una opción vacía
   ("Seleccione…") en clásico y en Blocks.
9. La versión del encabezado y `ALEGRA_CONNECTOR_VERSION` son idénticas; el build
   falla si no lo son; el release publica el ZIP como GitHub Release.
10. `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` siguen verdes.

## 5. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Doble `POST /payments` al mover hooks / cambiar "Facturar" a `complete` | Guard de meta `_alegra_payment_id` + pre-búsqueda `find_existing_payment()` + lock por pedido (`Controller::acquire_lock`) |
| La pasarela no confirma el pago cuando el plugin cree que sí | La verdad la da `$order->is_paid()` (estados `processing`/`completed`), no el nombre de la pasarela; el barrido cubre el caso tardío |
| Pasarela desconocida mapea a un método inválido | Fallback único a un valor válido (`transfer`) y tabla explícita; se cita el enum oficial de Alegra |
| Fecha de pago nula (pedido sin `date_paid`) | Fallback a `date('Y-m-d')` solo cuando `get_date_paid()` es `null`, documentado |
| El monto del pago no coincide con el saldo de la factura | Comparar contra el saldo; si difieren, reportar la discrepancia (no forzar) |
| `ensure_invoice_open` usa el endpoint equivocado (un-void) | BLOQUEADO: Fase 0.8 verifica en vivo; alternativa `PUT /invoices/{id}` `{"status":"open"}` |
| Cambiar el sanitizador rompe otras opciones de ID | Se reutiliza el mismo sanitizador genérico para `warehouse_id`/`payment_term_id`; el cambio es "avisar al rechazar", no "rechazar distinto" |
| Backfill de tags/releases 2.3.8–2.3.10 no reproducible | Fase 0.7 y rama alternativa: publicar solo desde 2.4.0 y dejar el historial documentado |

## 6. No-objetivos

- No se agrega UI nueva de configuración de pagos más allá del aviso de error de
  ajustes y del label corregido.
- No se cambia el esquema de la API de Alegra ni se asume `paymentForm`/DIAN.
- No se modifican los contratos públicos existentes (`create_invoice`,
  `create_invoice_with_payment`, `ajax_record_payment`) más allá de agregar el guard
  de "pedido pagado", centralizar la fuente de datos de pago y refactorizar su bloque
  de pago interno.
- No se toca el mapeo por pasarela para agregar UI; solo se centraliza y se corrige
  el fallback.

## 7. Relación con el SDD de inventario

`docs/sdd/inventory/design.md:276` ya proponía mover los hooks de pago fuera del
gate. Esta propuesta **concreta y cierra** esa decisión con un guard explícito y un
barrido de reintento, y es independiente de las decisiones de stock.
