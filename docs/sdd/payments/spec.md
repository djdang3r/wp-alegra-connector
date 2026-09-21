# Especificación — Pagos, Reconciliación y Ajustes

| Campo | Valor |
|---|---|
| Cambio | `payments` |
| Documento base | `docs/sdd/payments/proposal.md` |
| Formato | Requerimientos con escenarios Given/When/Then verificables por el harness |
| Estados | `ACTIVO` · `BLOQUEADO` (espera Fase 0) |
| Harness | `scripts/exec-test.sh` → `scripts/exec-test.php` (mock: `scripts/lib/alegra-mock.php`) |

> **Reglas de lectura.** Cada requerimiento tiene al menos un escenario
> verificable. Los requerimientos `BLOQUEADO` **no pueden finalizarse** hasta que
> la verificación de Fase 0 devuelva una respuesta; cada uno declara su **rama
> alternativa**. Los claims de código citan `archivo:línea`.
>
> **Fuente de verdad:** WooCommerce decide si un pedido está pagado y con qué
> método/pasarela. Alegra solo recibe el resultado. Ninguna regla de negocio de
> pagos puede basarse en el nombre de la pasarela ni en el estado que la pasarela
> muestra: se usa `WC_Order::is_paid()`.

---

## Convenciones

- **WC** = WooCommerce. **Alegra** = la API de Alegra.
- **Cuenta configurada** = `alegra_connector_payment_account_id` ∉ `{'', '0'}`
  (`Orders.php:289-291`). Es un **id numérico** (p. ej. `'5'`), no un UUID; la lista
  de `/bank-accounts` incluye **cajas** además de bancos.
- **Pedido pagado** = `$order->is_paid()` es `true`. En WC core equivale a status ∈
  `{processing, completed}` (`wc_get_is_paid_statuses()`); `processing` **ya** significa
  pago recibido. En el stub del harness, `is_paid()` = status ∈
  `{processing, completed}` (`wp-stubs.php:1149`).
- **Factura vinculada** = `$order->get_meta('_alegra_invoice_id')` ≠ `''`.
- **Pago registrado** = `$order->get_meta('_alegra_payment_id')` ≠ `''`.
- **Gate** = `get_option('alegra_connector_push_orders_enabled', false)`
  (`Public_.php:49`), default `false`.
- **`POST /payments`** = `API\Client::create_payment()` → `POST /payments`
  (`Client.php:485-487`, mock `alegra-mock.php:768`).
- **`POST /invoices`** = `API\Client::create_invoice()`.
- **Enum de `paymentMethod` de Alegra** (válido):
  `cash`, `check`, `transfer`, `deposit`, `credit-card`, `debit-card`.
  Fuente: <https://developer.alegra.com/reference/post_payments-1.md>.
- **`POST /invoices/{id}/open`** = "Abrir factura de venta: revierte la anulación"
  (es **un-void**, no borrador→abierto).
  Fuente: <https://developer.alegra.com/reference/post_invoices-id-open.md>.

---

## A. Facturar registra el pago (causa raíz #1, confirmada)

### REQ-MAN-1 — "Facturar" registra el pago inmediatamente cuando el pedido está pagado `ACTIVO`

**Cuando** el admin aprieta **"Facturar"** en el detalle del pedido, el plugin debe
llamar `sync_entity('order', $id, 'complete')` (`Controller.php:312-313` →
`Orders::create_invoice_with_payment()`, `Orders.php:279`). Esa función:

1. Crea (o reutiliza) la factura.
2. Registra **exactamente un** `POST /payments` **si y solo si** `$order->is_paid()`.
3. Toma el monto, la fecha y el método **de WooCommerce** (ver REQ-PAY-1/2).
4. Si el pedido **no** está pagado, crea la factura y **no** postea pago, dejando una
   nota de pedido que lo explique.

El flag explícito `record_payment` del diseño anterior **se elimina**: la decisión la
toma `is_paid()`, no un botón separado. "Facturar" es la única acción del detalle y su
comportamiento depende del estado real del pedido.

```gherkin
Escenario: pedido pagado vía Mercado Pago con cuenta 5 → un pago con datos de WC
  Dado alegra_connector_payment_account_id = '5'
  Y un pedido processing pagado con payment_method='mercadopago', total=150.00
  Y get_date_paid() = 2026-09-20
  Y el pedido NO tiene _alegra_invoice_id ni _alegra_payment_id
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces se emite 1 POST /invoices
  Y alegra_mock_count('POST','/payments') === 1
  Y el body del pago tiene bankAccount.id = '5'
  Y el body del pago tiene invoices[0].amount = 150.00
  Y el body del pago tiene date = '2026-09-20'
  Y el body del pago tiene paymentMethod = 'credit-card'
  Y _alegra_payment_id queda seteado

Escenario: pedido NO pagado → factura sí, pago no, y el comerciante se entera
  Dado un pedido on-hold (is_paid()=false) con cuenta configurada
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces se emite 1 POST /invoices
  Y alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id permanece ''
  Y una nota del pedido explica que no se registró el pago porque el pedido no está pagado

Escenario: pedido ya facturado y pagado → "Facturar" de nuevo no duplica
  Dado un pedido pagado con _alegra_invoice_id='inv-1' y _alegra_payment_id='pay-1'
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces alegra_mock_count('POST','/invoices') === 0
  Y alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id sigue siendo 'pay-1'

Escenario: pedido pagado sin factura previa → factura + pago en una sola acción
  Dado un pedido processing pagado, cuenta configurada, sin _alegra_invoice_id
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces se emite 1 POST /invoices
  Y alegra_mock_count('POST','/payments') === 1
```

**Evidencia:** `Admin_Dashboard.php:2196-2225` (handler; `:2216` hoy usa `'create'`),
`Controller.php:302-321` (`:310-311` create, `:312-313` complete), `Orders.php:47,279`,
`admin/assets/js/admin.js` (payload), `templates/admin-order-detail.php` (botón).

**Cambio exacto:** `Admin_Dashboard.php:2216` pasa de `'create'` a `'complete'`. El
guard `is_paid()` vive **dentro** de `create_invoice_with_payment` (REQ-MAN-1 lo
exige), no en el handler.

---

## B. Fuente de datos de pago y mapeo de pasarela

### REQ-PAY-1 — El monto, la fecha y el método salen de WooCommerce, nunca se inventan `ACTIVO`

**Cuando** se arma el `POST /payments`, el plugin debe leer:

| Dato | API de WC | Uso |
|---|---|---|
| ¿Pagado? | `$order->is_paid()` | decide si se postea el pago |
| Monto | `$order->get_total()` | `invoices[0].amount` |
| Fecha | `$order->get_date_paid()` | `date` (`Y-m-d`) |
| Método/pasarela | `$order->get_payment_method()` | mapeo a `paymentMethod` (REQ-PAY-2) |
| Título del método | `$order->get_payment_method_title()` | nota de pedido / observación |

Reglas:

- **Fecha:** `get_date_paid()`; si es `null` (pedido marcado pagado sin fecha), fallback
  documentado a `date('Y-m-d')` y **aviso en el log**. Prohibido usar siempre "hoy".
- **Monto:** `get_total()`. El pago es un **pago total** contra el saldo de la factura;
  si el saldo que devuelve Alegra (`GET /invoices/{id}`) difiere del monto, **se reporta
  la discrepancia** (nota + log) y no se ajusta en silencio.
- **Nunca** se inventan valores ni se toman de la pasarela directamente.

```gherkin
Escenario: fecha y monto salen del pedido
  Dado un pedido pagado con total=99.50 y get_date_paid()=2026-09-20
  Cuando se arma el pago
  Entonces date = '2026-09-20'
  Y invoices[0].amount = 99.50

Escenario: date_paid nulo cae a hoy con aviso
  Dado un pedido pagado con get_date_paid() = null
  Cuando se arma el pago
  Entonces date = la fecha actual (Y-m-d)
  Y el log registra un warning de fecha de pago faltante

Escenario: saldo de la factura distinto del total se reporta, no se fuerza
  Dado un pedido pagado con total=100
  Y GET /invoices/inv-1 devuelve balance=80
  Cuando se registra el pago
  Entonces se agrega una nota/log con la discrepancia (100 vs 80)
  Y no se modifica el monto de forma silenciosa

Escenario: la nota de pago menciona la pasarela
  Dado un pedido pagado con get_payment_method_title()='Mercado Pago'
  Cuando se registra el pago
  Entonces la nota de pedido del pago menciona 'Mercado Pago'
```

**Evidencia (actual):** `Orders.php:1467-1487` — `prepare_payment_data` usa
`date('Y-m-d')` (`:1477`) y `$order->get_total()` (`:1482`).

### REQ-PAY-2 — Mapeo pasarela → `paymentMethod` de Alegra, único y con fallback seguro `ACTIVO`

**Cuando** se determina el `paymentMethod`, el plugin debe usar **una sola** función
de mapeo (hoy hay dos con fallback distinto). El resultado debe pertenecer al enum
válido de Alegra. Casos:

| Pasarela WC | `paymentMethod` | Válido |
|---|---|---|
| `mercadopago` / `woocommerce-mercado-pago` / `woo-mercado-pago` | `credit-card` | sí |
| `bacs` | `transfer` | sí |
| `cod`, `efecty`, `baloto`, `oxxo` | `cash` | sí |
| `stripe`, `payu`, `epayco`, `wompi`, … | `credit-card` | sí |
| **desconocida** | **`transfer`** (fallback único) | sí |

Reglas:

- **Un solo fallback:** `'transfer'`. Se elimina la inconsistencia `'transfer'` vs
  `'cash'` entre `get_payment_method_code` (`Orders.php:1529`) y
  `getPaymentMethodForGateway` (`:1546`); ambos delegan en un único método.
- Una pasarela desconocida **no falla** el registro: usa `transfer` y lo registra en
  el log.
- El mapeo se valida contra el enum oficial
  (<https://developer.alegra.com/reference/post_payments-1.md>); un valor fuera del
  enum es un bug de configuración, no un error de red.

```gherkin
Escenario: Mercado Pago mapea a un método válido
  Dado un pedido con payment_method='woocommerce-mercado-pago'
  Cuando se arma el pago
  Entonces paymentMethod = 'credit-card'
  Y 'credit-card' ∈ {cash,check,transfer,deposit,credit-card,debit-card}

Escenario: pasarela desconocida cae a transfer y no falla
  Dado un pedido con payment_method='pasarela-inventada-xyz'
  Cuando se arma el pago
  Entonces paymentMethod = 'transfer'
  Y alegra_mock_count('POST','/payments') === 1
  Y el log registra la pasarela no mapeada

Escenario: ambos resolvedores coinciden en el fallback
  Dado el slug 'gateway-desconocido'
  Cuando se llama get_payment_method_code(order) y getPaymentMethodForGateway(slug)
  Entonces ambos devuelven 'transfer'
```

**Evidencia (actual):** `Orders.php:1518-1591` (tabla `get_payment_gateway_code_mappings`),
`:1529` fallback `'transfer'`, `:1546` fallback `'cash'`.

---

## C. Otros caminos manuales

### REQ-MAN-2 — "Facturar seleccionados" (bulk) registra el pago de los pedidos pagados `ACTIVO`

```gherkin
Escenario: bulk de pedidos pagados registra pago por cada uno
  Dado 2 pedidos pagados con cuenta configurada y sin factura
  Cuando Admin_Dashboard::ajax_bulk_sync recibe {entity_type:'order', ids:[a,b]}
  Entonces se emiten 2 POST /invoices
  Y alegra_mock_count('POST','/payments') === 2
  Y ambos _alegra_payment_id quedan seteados

Escenario: bulk con un pedido no pagado no paga ese pedido
  Dado 1 pedido pagado y 1 pedido on-hold
  Cuando corre el bulk
  Entonces se emiten 2 POST /invoices
  Y alegra_mock_count('POST','/payments') === 1
```

**Evidencia:** `Admin_Dashboard.php:2319-2338` (`:2332` acción `create` → pasa a
`complete`).

### REQ-MAN-3 — "Facturar pendientes" (`sync_recent`) registra el pago `ACTIVO`

```gherkin
Escenario: sync_recent de pedidos pagados sin factura crea factura y pago
  Dado 3 pedidos processing/completed sin factura y cuenta configurada
  Cuando Orders::sync_recent(30)
  Entonces se emiten 3 POST /invoices
  Y alegra_mock_count('POST','/payments') === 3

Escenario: sync_recent de un pedido no pagado no paga
  Dado 1 pedido on-hold sin factura y cuenta configurada
  Cuando Orders::sync_recent(30)
  Entonces se emite 1 POST /invoices
  Y alegra_mock_count('POST','/payments') === 0
```

**Evidencia:** `Orders.php:702-731` (`:720` usa `create_invoice` → pasa a
`create_invoice_with_payment`), llamado desde `Admin_Dashboard.php:2683-2705`.

### REQ-MAN-4 — Ningún camino manual duplica un pago ya registrado `ACTIVO`

```gherkin
Escenario: pago ya registrado no se vuelve a postear
  Dado un pedido pagado con factura y _alegra_payment_id='pay-1'
  Cuando se corre cualquier camino manual (single/bulk/sync_recent)
  Entonces alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id sigue siendo 'pay-1'

Escenario: pago preexistente en Alegra se recupera sin re-postear
  Dado un pedido pagado, factura vinculada, sin _alegra_payment_id
  Y Alegra ya tiene un pago para esa factura (GET /payments lo devuelve)
  Cuando se corre la reconciliación
  Entonces alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id se completa con el id encontrado
```

**Evidencia:** `Orders.php:306-309` (guard de meta), `:321-339` (`find_existing_payment`).

---

## D. Cuenta de destino y ajustes

### REQ-CFG-1 — El `<select>` nunca puede pisar un valor guardado `ACTIVO`

**Cuando** la opción `alegra_connector_payment_account_id` tiene un valor no vacío y
distinto de `'0'` (p. ej. `'5'`), **el `<select>` renderizado debe contener siempre una
opción seleccionada cuyo `value` sea exactamente ese valor.** Si el id guardado no vino
en la lista de `/bank-accounts`, se inyecta una opción sintética seleccionada con ese id
(etiquetada "cuenta guardada, no sincronizada"). Esto es un **bug latente** (no fue la
causa del reporte), pero se corrige igual.

```gherkin
Escenario: id guardado presente en la lista de cuentas
  Dado alegra_connector_payment_account_id = '5'
  Y /bank-accounts devuelve [{id:5},{id:4},{id:3}]
  Cuando se renderiza el <select> de cuenta de destino
  Entonces existe una opción con value='5'
  Y esa opción tiene el atributo selected
  Y la opción "Sin cuenta" (value='0') NO tiene selected

Escenario: id guardado AUSENTE de la lista (regresión del clobber)
  Dado alegra_connector_payment_account_id = '5'
  Y /bank-accounts devuelve [{id:4}]   # '5' no vino
  Cuando se renderiza el <select>
  Entonces existe una opción con value='5' y selected
  Y la opción "Sin cuenta" (value='0') NO tiene selected
  Y el navegador NO puede resolver el default a '0'

Escenario: opción genuinamente vacía
  Dado alegra_connector_payment_account_id = '' (o '0')
  Cuando se renderiza el <select>
  Entonces la opción "Sin cuenta" (value='0') tiene selected
```

**Verificación:** extraer el armado de opciones a un método puro testeable (ver
`design.md` §4) y assertear la marca de selección. El test debe **fallar** si se quita
la inyección de la opción sintética. **Desbloqueado:** Fase 0.1/0.2/0.3 confirmaron
cuenta `'5'`, `selected` correcto e ids numéricos incluyendo cajas.

### REQ-CFG-2 — El sanitizador nunca descarta en silencio `ACTIVO`

**Cuando** el valor enviado no es un id válido (numérico) ni un UUID legado, el
sanitizador **conserva el valor anterior** y **registra un `settings_error` visible** en
la pantalla de ajustes. La pantalla **no debe mostrar** "Configuración guardada
correctamente" cuando hubo un rechazo.

```gherkin
Escenario: entrada inválida conserva el valor y avisa
  Dado el valor guardado '5'
  Cuando se sanitiza el valor 'no-es-un-id'
  Entonces la opción sigue valiendo '5'
  Y get_settings_errors('alegra_connector_settings') contiene un error
  Y el mensaje de error menciona que el valor no se guardó

Escenario: entrada inválida no muestra el banner de éxito
  Dado un rechazo del sanitizador
  Cuando se renderiza la pantalla de ajustes tras el redirect
  Entonces NO aparece "Configuración guardada correctamente"
  Y SÍ aparece el mensaje de error

Escenario: valores válidos siguen aceptándose
  Dado un id numérico '5' o un UUID legado
  Cuando se sanitiza
  Entonces el sanitizador devuelve el mismo valor
  Y no se registra ningún settings_error
```

**Verificación:** `add_settings_error()` llamado desde el sanitize_callback;
`settings_errors('alegra_connector_settings')` renderizado por la plantilla. Test
unitario sobre el método extraído (ver `design.md` §4.2) + test de plantilla.

### REQ-CFG-3 — Default de activación y render tolerante a fallo de `/bank-accounts` `ACTIVO`

**Cuando** el plugin se activa, `alegra_connector_payment_account_id` debe existir con
default `''`. **Cuando** `GET /bank-accounts` falla o devuelve vacío, la pantalla **no
debe borrar** la opción guardada: si hay un id guardado se renderiza el `<select>` con
la opción sintética; si no hay id, se cae al input de texto.

```gherkin
Escenario: activación deja un default explícito
  Dado un sitio donde la opción nunca existió
  Cuando se activa el plugin
  Entonces get_option('alegra_connector_payment_account_id') === ''

Escenario: fallo de /bank-accounts con id guardado no destruye la configuración
  Dado alegra_connector_payment_account_id = '5'
  Y GET /bank-accounts devuelve WP_Error (o [])
  Cuando se renderiza la pantalla de ajustes
  Entonces existe una opción con value='5' y selected
  Y la opción "Sin cuenta" NO tiene selected

Escenario: fallo de /bank-accounts sin id guardado cae al input de texto
  Dado alegra_connector_payment_account_id = ''
  Y GET /bank-accounts devuelve WP_Error (o [])
  Cuando se renderiza la pantalla de ajustes
  Entonces se muestra el input de texto
  Y no se renderiza ningún <select> de cuenta de destino
```

**Desbloqueado:** Fase 0.3 confirmó la forma de `/bank-accounts` (lista con `id`
numérico y `name`). **Evidencia:** `Admin_Dashboard.php:735-746` (fetch),
`templates/admin-settings.php:346-349` (fallback), sin default en
`Admin_Dashboard.php:363-364`.

### REQ-CFG-4 — Cuenta genuinamente vacía: salta el pago pero AVISA `ACTIVO`

**Cuando** no hay cuenta configurada y un pedido pagado tiene factura vinculada y sin
pago, el plugin **no debe** emitir `POST /payments`, pero **debe** registrar un warning
en el log y una nota de pedido explicando por qué no se registró.

```gherkin
Escenario: sin cuenta no se paga pero se avisa
  Dado alegra_connector_payment_account_id = '0'
  Y un pedido pagado con _alegra_invoice_id='inv-1' y _alegra_payment_id=''
  Cuando se dispara la reconciliación del pedido
  Entonces alegra_mock_count('POST','/payments') === 0
  Y la nota del pedido menciona que falta configurar la cuenta de destino
  Y el log contiene un warning de reconciliación salteada

Escenario: dry-run no emite pago ni lo declara registrado
  Dado el modo dry-run activo
  Y una cuenta configurada y un pedido pagado con factura
  Cuando se dispara la reconciliación
  Entonces alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id permanece ''
```

**Evidencia:** `Orders.php:289-291`, `Admin_Dashboard.php:2122-2125`.

### REQ-CFG-5 — El label dice "banco o caja" `ACTIVO`

**Cuando** se renderiza la pantalla de ajustes, el label de la opción debe indicar que
la cuenta de destino puede ser **un banco o una caja**, porque la lista de Alegra
incluye ambas. Texto propuesto: **"Cuenta de destino para pagos (banco o caja)"**.

```gherkin
Escenario: el label menciona banco y caja
  Dado la pantalla de ajustes renderizada
  Cuando se lee el label de alegra_connector_payment_account_id
  Entonces el texto contiene "Cuenta de destino para pagos"
  Y el texto contiene "banco o caja"

Escenario: las opciones siguen mostrando nombre e id reales
  Dado /bank-accounts devuelve [{id:5,name:'Caja Pagina Web'}]
  Cuando se renderiza el <select>
  Entonces la opción dice 'Caja Pagina Web (ID: 5)'
  Y la opción "Sin cuenta (no se registraran pagos)" sigue existiendo
```

**Evidencia:** `templates/admin-settings.php` (label "Cuenta bancaria");
Fase 0.3 (ids `5,4,3,2,1`, incluye cajas).

---

## E. Reconciliación automática (agujero #2)

### REQ-REC-1 — Los hooks de pago se registran fuera del gate `ACTIVO`

**Cuando** `push_orders_enabled` es `false` (modo manual, default), el plugin **debe**
registrar los hooks que reconcilian el pago de un pedido pagado, sin registrar los
hooks que crean facturas.

```gherkin
Escenario: modo manual registra reconciliación pero no creación
  Dado alegra_connector_push_orders_enabled = false
  Cuando se instancia Public_::__construct
  Entonces has_action('woocommerce_payment_complete', [public,'on_order_paid_reconcile']) !== false
  Y has_action('woocommerce_order_status_processing', [public,'on_order_paid_reconcile']) !== false
  Y has_action('woocommerce_new_order', [public,'on_new_order']) === false
  Y has_action('woocommerce_payment_complete', [public,'on_payment_complete']) === false
```

**Nota de no-regresión:** el test `T12.1` (`exec-test.php:1646-1660`) assertea
`has_action(..., 'on_payment_complete') === false` con la opción ausente. Se preserva
usando un **método distinto** para el hook siempre-activo
(`on_order_paid_reconcile`). Ese test se mantiene verde sin cambios.

### REQ-REC-2 — Guard exacto de reconciliación `ACTIVO`

La reconciliación solo actúa cuando se cumplen **las tres** condiciones: factura
vinculada (`_alegra_invoice_id` ≠ `''`) **Y** sin pago (`_alegra_payment_id` = `''`)
**Y** pedido pagado (`$order->is_paid()`). En cualquier otro caso retorna sin efectos.

```gherkin
Escenario: sin factura vinculada no reconcilia
  Dado un pedido pagado con _alegra_invoice_id='' y cuenta configurada
  Cuando se dispara on_order_paid_reconcile
  Entonces no se emite POST /invoices
  Y no se emite POST /payments

Escenario: con pago ya registrado no reconcilia
  Dado un pedido pagado con _alegra_payment_id='pay-1'
  Cuando se dispara on_order_paid_reconcile
  Entonces no se emite POST /payments

Escenario: pedido no pagado no reconcilia
  Dado un pedido on-hold con factura y sin pago
  Cuando se dispara on_order_paid_reconcile
  Entonces no se emite POST /payments
```

### REQ-REC-3 — `processing` reconcilia el pago `BLOQUEADO`

**Cuando** un pedido pasa a `processing` (que en WC **ya** significa pago recibido) y ya
tiene factura vinculada, el pago debe registrarse aunque el hook
`woocommerce_payment_complete` no se haya disparado.

```gherkin
Escenario: status processing registra el pago sobre factura existente
  Dado un pedido con _alegra_invoice_id='inv-9', _alegra_payment_id='', cuenta configurada
  Cuando el pedido pasa a 'processing'
  Entonces alegra_mock_count('POST','/payments') === 1
  Y _alegra_payment_id queda seteado

Escenario: payment_complete y processing no duplican
  Dado el mismo pedido pagado con factura y sin pago
  Cuando se disparan payment_complete Y status_processing
  Entonces alegra_mock_count('POST','/payments') === 1
```

**BLOQUEADO por Fase 0.6** — hay que confirmar que las pasarelas de la tienda disparan
`woocommerce_order_status_processing` y/o `woocommerce_payment_complete` (el comerciante
reporta que su pedido queda en `pending`/`on-hold` mientras la pasarela dice
"Procesando").
- **Rama A (la pasarela deja el pedido en `processing`/`completed`):** se registran
  ambos hooks; el guard `is_paid()` los hace seguros.
- **Rama B (la pasarela deja el pedido en `pending`/`on-hold`):** el pago **no** se
  puede inferir; el barrido (REQ-REC-4) es la red de seguridad y hay que documentar que
  la configuración de la pasarela en WC debe marcar el pedido como pagado.

**Evidencia:** `Public_.php:49-59` (no existe `processing`);
`Docs/DOCUMENTACION.md:657` documenta el hook pero no está registrado;
`wp-stubs.php:1149` (`is_paid()` = processing/completed).

### REQ-REC-4 — Barrido de reintento idempotente `ACTIVO`

**Cuando** corre el cron `alegra_connector_payment_reconcile`, debe buscar un lote
acotado de pedidos pagados con factura vinculada y sin pago, y reconciliar cada uno.
Debe respetar kill-switch, lock y batch.

```gherkin
Escenario: el barrido reconcilia pedidos pendientes de pago
  Dado 2 pedidos processing con _alegra_invoice_id y sin _alegra_payment_id
  Y una cuenta configurada
  Cuando corre run_payment_reconcile()
  Entonces alegra_mock_count('POST','/payments') === 2
  Y el resultado reporta reconciled=2

Escenario: kill-switch detiene el barrido
  Dado el kill-switch activo
  Cuando corre run_payment_reconcile()
  Entonces alegra_mock_count('POST','/payments') === 0
  Y el resultado reporta skipped_kill_switch

Escenario: lock impide dos barridos simultáneos
  Dado un lock 'alegra_payment_reconcile' ya tomado
  Cuando corre run_payment_reconcile()
  Entonces alegra_mock_count('POST','/payments') === 0
  Y el resultado reporta skipped_locked

Escenario: el barrido no toca pedidos sin factura
  Dado un pedido pagado sin _alegra_invoice_id
  Cuando corre el barrido
  Entonces no se emite POST /invoices
  Y no se emite POST /payments
```

**Evidencia de patrón:** `Orders.php:1751-1844` (kill-switch + lock + batch),
`Controller.php:367-401` (locks), `Maintenance.php:41` (scheduling).

### REQ-REC-5 — En modo manual la reconciliación NUNCA crea facturas `ACTIVO`

```gherkin
Escenario: reconciliación manual no crea factura
  Dado push_orders_enabled = false
  Y un pedido pagado SIN _alegra_invoice_id
  Cuando se dispara on_order_paid_reconcile
  Entonces alegra_mock_count('POST','/invoices') === 0
  Y no se modifica el pedido

Escenario: reconciliación con factura existente solo registra el pago
  Dado push_orders_enabled = false
  Y un pedido pagado con _alegra_invoice_id='inv-1' y sin pago
  Cuando se dispara on_order_paid_reconcile
  Entonces alegra_mock_count('POST','/invoices') === 0
  Y alegra_mock_count('POST','/payments') === 1
```

### REQ-REC-6 — Idempotencia estricta: un pago nunca se postea dos veces `ACTIVO`

```gherkin
Escenario: dos disparos concurrentes producen un solo POST /payments
  Dado un pedido pagado con factura y sin pago
  Cuando se disparan on_order_paid_reconcile y el barrido para el mismo pedido
  Entonces alegra_mock_count('POST','/payments') === 1

Escenario: fallo de red recuperable no duplica al reintentar
  Dado un POST /payments que falla con 500 (sin id devuelto)
  Y Alegra SÍ registró el pago (GET /payments lo devuelve)
  Cuando se reintenta la reconciliación
  Entonces alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id se completa con el id recuperado
```

**Garantías combinadas:** guard de meta + pre-búsqueda `find_existing_payment()` + lock
por pedido (`alegra_payment_lock_<id>`). Ver `design.md` §9.

---

## F. Modo borrador (solo comerciantes con `invoice_status = draft`)

### REQ-DRAFT-1 — Si la factura está en borrador, abrirla antes de pagar (endpoint a verificar) `BLOQUEADO`

El comerciante que reportó el bug crea sus facturas **`open`**, así que este caso **no
es su bloqueo**. Pero para comerciantes con `invoice_status = draft`, registrar un pago
exige que la factura esté abierta. El código actual (`Orders.php:400-433`,
`ensure_invoice_open`) llama `POST /invoices/{id}/open` (`Client.php:693-696`), pero la
documentación de Alegra describe ese endpoint como **revertir la anulación (un-void)**,
**no** borrador→abierto. Hay que verificarlo en vivo y, si no abre un borrador,
corregirlo.

```gherkin
Escenario: factura en borrador se abre antes de pagar (endpoint correcto)
  Dado un pedido pagado, cuenta configurada, e invoice_status='draft'
  Y _alegra_invoice_id='inv-draft' con status 'draft'
  Cuando se registra el pago
  Entonces la factura queda 'open' antes del POST /payments
  Y alegra_mock_count('POST','/payments') === 1

Escenario: factura ya abierta no se toca
  Dado un pedido pagado con factura status 'open'
  Cuando se registra el pago
  Entonces no se emite POST /invoices/{id}/open
  Y alegra_mock_count('POST','/payments') === 1

Escenario (alternativa): /open no abre borradores
  Dado que POST /invoices/{id}/open revierte anulación y no abre un borrador
  Cuando se verifica en vivo
  Entonces se usa PUT /invoices/{id} con {"status":"open"} (o el mecanismo documentado)
  Y se actualiza ensure_invoice_open para usar el endpoint correcto
```

**BLOQUEADO por Fase 0.8** — verificación en vivo contra Alegra real.
- **Rama A (`/open` abre el borrador):** se mantiene `ensure_invoice_open` como está.
- **Rama B (`/open` es un-void):** se cambia a `PUT /invoices/{id}` con
  `{"status":"open"}` y se agrega el método `open_invoice` corregido.

**Evidencia:** `Orders.php:314-319,400-433`; `Client.php:693-696`;
<https://developer.alegra.com/reference/post_invoices-id-open.md> (un-void);
<https://developer.alegra.com/reference/put_invoices-id.md> (editar factura).

---

## G. Checkout (Hallazgo 7)

### REQ-CHK-1 — El select clásico de tipo de documento arranca vacío `ACTIVO`

```gherkin
Escenario: idtype clásico incluye opción vacía y no preselecciona RC
  Dado el checkout clásico con el campo idtype habilitado
  Cuando se construyen los campos de checkout
  Entonces las opciones de 'billing_alegra_idtype' contienen '' => 'Seleccione…'
  Y '' es la primera opción
  Y ningún valor (RC) viene preseleccionado

Escenario: el resto de los selects también tienen placeholder
  Dado cualquier campo tipo select del catálogo
  Cuando se construyen las opciones de checkout
  Entonces la opción '' está presente
```

**Evidencia:** `Billing_Fields.php:499-501`, `:595-602`, `:561-569` (la ruta de
registro/account ya agrega el placeholder).

### REQ-CHK-2 — El select de Blocks también arranca vacío `BLOQUEADO`

```gherkin
Escenario: block_options antepone la opción vacía
  Dado el checkout en Blocks con idtype habilitado
  Cuando se arma block_options('idtype')
  Entonces el primer elemento es ['value'=>'','label'=>'Seleccione…']
  Y RC no es el default

Escenario (alternativa): Blocks no admite opción vacía
  Dado que la API de Blocks rechaza una opción con value=''
  Cuando se registra el campo
  Entonces el campo no se marca required por defecto
  Y se valida '' como "sin dato" en el guardado
```

**BLOQUEADO por Fase 0.5** — confirmar si
`woocommerce_register_additional_checkout_field` admite una opción con `value=''`.
- **Rama A (admite):** se antepone la opción vacía.
- **Rama B (no admite):** se documenta y se valida por schema/JS.

**Evidencia:** `Checkout_Integration.php:188-202`, `:255-261` (string localizado sin uso).

---

## H. Versión y release (Hallazgo 8)

### REQ-REL-1 — Una sola fuente de verdad para la versión `ACTIVO`

```gherkin
Escenario: constante y encabezado coinciden
  Dado el archivo alegra-connector.php
  Cuando se lee el encabezado 'Version' y la constante ALEGRA_CONNECTOR_VERSION
  Entonces son idénticos

Escenario: el cache-buster usa la versión real
  Dado ALEGRA_CONNECTOR_VERSION = 'X.Y.Z'
  Cuando se encola admin.js / checkout-conditions.js
  Entonces el cuarto argumento de wp_enqueue_script es 'X.Y.Z'
```

**Evidencia:** `alegra-connector.php:6,29`; `Admin_Dashboard.php:503-504,514`;
`Checkout_Integration.php:251`.

### REQ-REL-2 — El build valida la consistencia y no publica con versiones divergentes `ACTIVO`

```gherkin
Escenario: build rechaza versión divergente
  Dado scripts/build-release.sh 2.4.0
  Y el encabezado dice 2.3.10
  Cuando corre el build
  Entonces termina con código ≠ 0
  Y el mensaje explica la divergencia
  Y no se escribe ningún ZIP nuevo

Escenario: build consistente procede
  Dado encabezado == constante == argumento
  Cuando corre el build
  Entonces termina con código 0
  Y existe releases/alegra-connector-v<version>.zip
```

### REQ-REL-3 — Un tag publica un GitHub Release con el ZIP `ACTIVO`

```gherkin
Escenario: push de tag v* dispara el workflow
  Dado un tag 'v2.4.0' pusheado
  Cuando corre .github/workflows/release.yml
  Entonces se ejecutan smoke-test y exec-test como gates
  Y se crea un Release 'v2.4.0'
  Y el Release adjunta el ZIP y su .sha256
```

### REQ-REL-4 — El README apunta al "latest" correcto `ACTIVO`

```gherkin
Escenario: el README no manda a la carpeta releases/
  Dado README.md
  Cuando se lee la sección de instalación
  Entonces no dice "download the latest release from the releases folder"
  Y apunta a https://github.com/djdang3r/wp-alegra-connector/releases/latest
```

**Evidencia:** `README.md:29`; orden lexicográfico de `releases/` (2.3.10 después de 2.3.1).

---

## I. No-regresión y harness

### REQ-NR-1 — El harness sigue verde `ACTIVO`

```gherkin
Escenario: los gates existentes no se rompen
  Cuando corre bash scripts/exec-test.sh
  Entonces exit code === 0
  Y T12.1, T12.2, T12.3, T12.4, T3.3, T18.11, T18.14 siguen pasando
  Cuando corre bash scripts/smoke-test.sh
  Entonces exit code === 0
```

### REQ-NR-2 — Prove-it-catches `ACTIVO`

Cada test nuevo de un bug debe **fallar** si se revierte el fix. Los tests marcados
"prove-it-catches" en `tasks.md` incluyen esa verificación en su DoD.

---

## J. Resumen de bloqueos

| Req | Bloqueo | Rama A | Rama B |
|---|---|---|---|
| REQ-REC-3 | Fase 0.6 | la pasarela deja el pedido en `processing`/`completed` → hooks + guard | queda en `pending`/`on-hold` → barrido obligatorio + doc de configuración |
| REQ-DRAFT-1 | Fase 0.8 | `/open` abre el borrador → se mantiene | `/open` es un-void → `PUT /invoices/{id}` `{"status":"open"}` |
| REQ-CHK-2 | Fase 0.5 | Blocks admite `value=''` | no admite → validar sin preselección |

**Desbloqueados por Fase 0:** REQ-CFG-1 (cuenta `'5'`, `selected` correcto), REQ-CFG-3
(ids numéricos incluyendo cajas), REQ-CFG-5 (label banco/caja).

## K. Trazabilidad requerimiento → test

| Req | Test propuesto (ver `tasks.md`) |
|---|---|
| REQ-MAN-1 | `T-MAN-1` (Facturar: pagado paga / no pagado no paga / sin duplicar) |
| REQ-MAN-2..4 | `T-MAN-2..4` |
| REQ-PAY-1 | `T-PAY-1` (monto/fecha desde WC + discrepancia + nota) |
| REQ-PAY-2 | `T-PAY-2` (Mercado Pago + fallback único) |
| REQ-CFG-1 | `T-CFG-1` (opciones del select) |
| REQ-CFG-2 | `T-CFG-2` (sanitizador + banner) |
| REQ-CFG-3 | `T-CFG-3` (activación + fallback) |
| REQ-CFG-4 | `T-CFG-4` (sin cuenta → WARN, no POST) |
| REQ-CFG-5 | `T-CFG-5` (label banco/caja) |
| REQ-REC-1..6 | `T-REC-1..6` |
| REQ-DRAFT-1 | `T-DRAFT-1` |
| REQ-CHK-1..2 | `T-CHK-1..2` |
| REQ-REL-1..4 | `T-REL-1..4` |
| REQ-NR-1..2 | gates existentes + `T-NR-1` |

**Conteo:** 26 requerimientos (`REQ-MAN-1..4`, `REQ-PAY-1..2`, `REQ-CFG-1..5`,
`REQ-REC-1..6`, `REQ-DRAFT-1`, `REQ-CHK-1..2`, `REQ-REL-1..4`, `REQ-NR-1..2`),
**58 escenarios** Gherkin verificables.
