# Especificación — Puertas de configuración y enforcement de escrituras

| Campo | Valor |
|---|---|
| Cambio | `config-gates` |
| Documento base | `docs/sdd/config-gates/proposal.md` |
| Formato | Requerimientos con escenarios Given/When/Then verificables por el harness |
| Estados | `ACTIVO` · `BLOQUEADO` (espera Fase 0) |
| Harness | `bash scripts/exec-test.sh` → `scripts/exec-test.php` (mock `scripts/lib/alegra-mock.php`) · `bash scripts/smoke-test.sh` → `scripts/smoke-load.php` |
| Framework | `scripts/lib/test-framework.php` (`TestRunner::test()`, `assertTrue/assertFalse/assertSame/assertEquals/assertCount/assertArrayHasKey/assertStringContains/assertStringNotContains`) |

> **Reglas de lectura.**
> 1. Cada requerimiento tiene al menos un escenario verificable por el harness.
> 2. Los `BLOQUEADO(Fase 0.x)` no se cierran hasta tener el resultado; cada uno declara su
>    **rama A / rama B**.
> 3. Todo claim de código cita `archivo:línea`; lo no verificado se marca **SIN VERIFICAR**.
> 4. Los IDs de requerimiento son estables y se citan desde `design.md` y `tasks.md`.

---

## Convenciones

- **Choke point** = `API\Client::request()` (`includes/API/Client.php:101-236`), el único
  lugar del plugin que ejecuta `wp_remote_request` (`:158`). Verificado: no hay otro
  `wp_remote_*` de escritura en producción (sólo `Admin_Dashboard.php:1547` hace un
  `wp_remote_head` de sólo lectura).
- **Kill switch** = `Kill_Switch::is_active()` (`includes/Kill_Switch.php:51-61`), opción
  `alegra_kill_switch` (lectura directa a `wpdb`, sin object cache).
- **Marcador dry-run** = `['dry_run' => true, 'blocked' => '<VERB> <endpoint>']`
  (`Client.php:110`), detectado con `Client::is_dry_run_response()` (`:249`).
- **Marcador de puerta** (nuevo) = `['blocked_by_gate' => true, 'reason' => <string>,
  'entity' => <string>, 'blocked' => '<VERB> <endpoint>']`, detectado con
  `Client::is_gate_blocked_response()` (nuevo).
- **Escritura bloqueada** = cualquiera de los dos marcadores; detectada con
  `Client::write_was_blocked()` (nuevo) = `is_dry_run_response() || is_gate_blocked_response()`.
- **Contexto explícito** = el comerciante pidió la acción (botón admin, REST `POST /sync`).
  **Contexto automático** = la acción la disparó un hook, el cron o un webhook.
- **`alegra_mock_count($method, $endpoint)`** = cantidad de requests de escritura que
  llegaron al mock (`scripts/lib/alegra-mock.php`). Con la puerta activa debe ser `0`.
- **UI == runtime == defaults** = el valor que renderiza el template, el que lee el código y
  el que siembra la activación coinciden para la misma opción.

---

## A. Enforcement en el punto único

### REQ-ENF-1 — Con el kill switch activo, NINGUNA escritura llega a Alegra, desde ningún camino `ACTIVO`

**Cuando** `alegra_kill_switch` está activo, toda llamada de escritura (`POST`/`PUT`/`PATCH`/`DELETE`)
que pase por `Client::request()` debe:
1. **No** ejecutar `wp_remote_request`.
2. Devolver un **marcador de puerta** con `reason = 'kill_switch'`.
3. Registrar en el log `[WRITE GATE] Blocked …` con entidad, verbo y endpoint.

Esto aplica a **todos** los orígenes: hooks WC (`Public_`, `State_Sync`), AJAX admin, REST
`POST /sync`, cron, y el render del dashboard. Los chequeos por callback existentes
(`Controller.php:87,119`; `Orders.php:535,2044,2088`; `Products.php:1093,1266,1306`;
`Customers.php:218,247`; `Categories.php:94,106`) **se mantienen** como fast-path, pero la
autoridad es el choke point.

```gherkin
Escenario: kill switch activo + "Facturar" manual (AJAX) → cero escrituras
  Dado el kill switch activo
  Y un pedido pagado con cuenta configurada, sin _alegra_invoice_id
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces alegra_mock_count('POST','/invoices') === 0
  Y alegra_mock_count('POST','/payments') === 0
  Y la respuesta del handler indica bloqueo (no error de red)
  Y _alegra_invoice_id permanece ''

Escenario: kill switch activo + hook de pago en tiempo real → cero escrituras
  Dado el kill switch activo
  Y un pedido pagado con _alegra_invoice_id='inv-1' y sin _alegra_payment_id
  Cuando se dispara 'woocommerce_payment_complete'
  Entonces alegra_mock_count('POST','/payments') === 0
  Y _alegra_payment_id permanece ''

Escenario: kill switch activo + refund → cero escrituras
  Dado el kill switch activo
  Y un pedido con factura vinculada
  Cuando se dispara 'woocommerce_order_refunded'
  Entonces alegra_mock_count('POST','/credit-notes') === 0

Escenario: kill switch activo + REST POST /sync → cero escrituras
  Dado el kill switch activo
  Y un request REST POST /alegra-connector/v1/sync con permiso manage_woocommerce
  Entonces alegra_mock_count() de escrituras === 0
  Y la respuesta REST tiene code 423 (Locked) o un payload {blocked:true, reason:'kill_switch'}

Escenario: kill switch activo + render del dashboard → cero escrituras
  Dado el kill switch activo
  Y el Consumidor Final sin cache ni override
  Cuando se renderiza templates/admin-dashboard.php
  Entonces alegra_mock_count('POST','/contacts') === 0

Escenario (NEGATIVO): kill switch INACTIVO + push_orders_enabled=true → la escritura LLEGA
  Dado el kill switch inactivo
  Y alegra_connector_push_orders_enabled = true
  Y un pedido nuevo
  Cuando se dispara 'woocommerce_new_order'
  Entonces alegra_mock_count('POST','/invoices') === 1
```

**Evidencia:** `Client.php:101-111` (sólo dry-run hoy), `Kill_Switch.php:51-61`,
`Public_.php:65-67`, `State_Sync.php:37-39`, `Admin_Dashboard.php:2273,2399,2765,2825`.

**BLOQUEADO(Fase 0.1)** — No aplica al comportamiento del gate (está confirmado). Bloquea
únicamente la **decisión de default** de `payment_reconcile_enabled` (ver REQ-CFG-1).

---

### REQ-ENF-2 — El mismo punto honra la habilitación por entidad `ACTIVO`

**Cuando** una escritura es de **contexto automático**, debe permitirse sólo si la opción
que gobierna su entidad está habilitada. Una escritura de **contexto explícito** se permite
con el kill switch inactivo, sin importar la opción. La tabla de gobierno es:

| Entidad | Endpoint(s) | Opción que gobierna (automático) | Default |
|---|---|---|---|
| `invoice` | `/invoices`, `/invoices/{id}/open`, `/invoices/{id}/retentions-applied` | `alegra_connector_push_orders_enabled` | `false` |
| `credit_note` | `/credit-notes` | `alegra_connector_push_orders_enabled` | `false` |
| `payment` | `/payments` | `alegra_connector_payment_reconcile_enabled` | `true` |
| `contact` | `/contacts` | `alegra_connector_push_customers_enabled` (nueva) | `false` |
| `item` | `/items`, `/items/{id}/attachment` | `alegra_connector_push_products_enabled` | `false` |
| `category` | `/item-categories` | `alegra_connector_push_products_enabled` | `false` |
| `webhook` | `/webhooks/subscriptions` | (ninguna: sólo explícito) | — |
| `other` | `/price-lists`, `/variant-attributes`, `/taxes`, `/inventory-adjustments`, `/estimates` | (ninguna: sólo explícito) | — |

```gherkin
Escenario: pedidos deshabilitados + hook automático → factura bloqueada
  Dado el kill switch inactivo
  Y alegra_connector_push_orders_enabled = false
  Cuando se dispara 'woocommerce_new_order' para un pedido nuevo
  Entonces alegra_mock_count('POST','/invoices') === 0
  Y el resultado del gate tiene reason='entity_disabled', entity='invoice'

Escenario: pedidos deshabilitados + "Facturar" explícito → factura permitida
  Dado el kill switch inactivo
  Y alegra_connector_push_orders_enabled = false
  Cuando Admin_Dashboard::ajax_sync_single recibe {entity_type:'order'}
  Entonces alegra_mock_count('POST','/invoices') === 1

Escenario: clientes deshabilitados + hook automático → contacto bloqueado
  Dado alegra_connector_push_customers_enabled = false
  Cuando se dispara 'woocommerce_new_customer'
  Entonces alegra_mock_count('POST','/contacts') === 0

Escenario: clientes habilitados + hook automático → contacto permitido
  Dado alegra_connector_push_customers_enabled = true
  Cuando se dispara 'woocommerce_new_customer'
  Entonces alegra_mock_count('POST','/contacts') === 1

Escenario (NEGATIVO): reconciliación de pagos deshabilitada + sweep → pago bloqueado
  Dado alegra_connector_payment_reconcile_enabled = false
  Cuando Controller::run_payment_reconcile() corre
  Entonces alegra_mock_count('POST','/payments') === 0
  Y el resultado tiene skipped='skipped_disabled'

Escenario (NEGATIVO): reconciliación deshabilitada + "Registrar pago" explícito → permitido
  Dado alegra_connector_payment_reconcile_enabled = false
  Cuando Admin_Dashboard::ajax_record_payment corre para una factura vinculada
  Entonces alegra_mock_count('POST','/payments') === 1
```

**Evidencia:** `Public_.php:49,72` (gates actuales), `Orders.php:495` (flag del sweep),
`Admin_Dashboard.php:2225` (pago manual).

---

### REQ-ENF-3 — El bloqueo por puerta es distinguible de un error real `ACTIVO`

**Cuando** una escritura se bloquea, el valor de retorno debe ser un **array marcador**, nunca
un `WP_Error`. Un fallo real (HTTP 4xx/5xx, timeout) sigue devolviendo `WP_Error` como hoy
(`Client.php:220`).

```gherkin
Escenario: bloqueo por kill switch ≠ error
  Dado el kill switch activo
  Cuando Client::post('/invoices', [...])
  Entonces Client::is_gate_blocked_response($r) === true
  Y Client::is_dry_run_response($r) === false
  Y is_wp_error($r) === false
  Y $r['reason'] === 'kill_switch'
  Y Client::write_was_blocked($r) === true

Escenario: dry-run sigue devolviendo su marcador exacto (sin regresión)
  Dado alegra_connector_dry_run = true
  Y el kill switch inactivo
  Cuando Client::post('/invoices', [...])
  Entonces Client::is_dry_run_response($r) === true
  Y Client::is_gate_blocked_response($r) === false
  Y $r === ['dry_run' => true, 'blocked' => 'POST /invoices']

Escenario: error HTTP real sigue siendo WP_Error
  Dado el mock configurado para responder 422 a POST /invoices
  Cuando Client::post('/invoices', [...])
  Entonces is_wp_error($r) === true
  Y Client::write_was_blocked($r) === false
```

**Evidencia:** `Client.php:110` (marcador dry-run), `Client.php:220` (`api_error`),
`Client.php:249` (`is_dry_run_response`).

---

## B. Honestidad de configuración

### REQ-CFG-1 — `payment_reconcile_enabled` / `_batch` son controlables y respetadas por TODOS los caminos `ACTIVO`

**Cuando** el plugin arranca, `alegra_connector_payment_reconcile_enabled` (bool, default
`true`) y `alegra_connector_payment_reconcile_batch` (int 1..100, default `20`) deben:
1. Estar registradas con `register_setting()` en `alegra_connector_settings`.
2. Estar en `$defaults` y en `$non_autoload` (`alegra-connector.php:403-439`).
3. Sembrarse en la activación (el loop `:441-445` ya lo hace una vez agregadas).
4. Renderizarse en la UI (tab Avanzado).
5. Ser respetadas por el **barrido horario** (`Orders::reconcile_missing_payments`, `:493`)
   **y** por los hooks en tiempo real (`Public_::on_order_paid_reconcile` → `reconcile_payment_only`).
6. Borrarse en `uninstall.php`.

```gherkin
Escenario: la opción está registrada y expuesta
  Cuando Admin_Dashboard::register_settings() corre
  Entonces register_setting fue llamado con 'alegra_connector_payment_reconcile_enabled'
  Y register_setting fue llamado con 'alegra_connector_payment_reconcile_batch'
  Y templates/admin-settings.php contiene un input con name="alegra_connector_payment_reconcile_enabled"

Escenario: el barrido honra el flag
  Dado alegra_connector_payment_reconcile_enabled = false
  Cuando Controller::run_payment_reconcile() corre
  Entonces alegra_mock_count('POST','/payments') === 0
  Y $result['skipped'] === 'skipped_disabled'

Escenario: el hook en tiempo real honra el flag
  Dado alegra_connector_payment_reconcile_enabled = false
  Y un pedido pagado con _alegra_invoice_id y sin _alegra_payment_id
  Cuando se dispara 'woocommerce_payment_complete'
  Entonces alegra_mock_count('POST','/payments') === 0

Escenario (NEGATIVO): flag activo + hook en tiempo real → el pago LLEGA
  Dado alegra_connector_payment_reconcile_enabled = true
  Y un pedido pagado con _alegra_invoice_id y sin _alegra_payment_id
  Cuando se dispara 'woocommerce_payment_complete'
  Entonces alegra_mock_count('POST','/payments') === 1

Escenario (NEGATIVO): batch fuera de rango se clampea
  Cuando se guarda alegra_connector_payment_reconcile_batch = 0
  Entonces get_option('alegra_connector_payment_reconcile_batch') === 1
  Cuando se guarda 999
  Entonces get_option('alegra_connector_payment_reconcile_batch') === 100

Escenario: uninstall borra las opciones
  Cuando corre alegra_connector_uninstall_options()
  Entonces el código de uninstall.php contiene delete_option('alegra_connector_payment_reconcile_enabled')
  Y contiene delete_option('alegra_connector_payment_reconcile_batch')
```

**BLOQUEADO(Fase 0.4)** — la decisión de **default** depende del estado real en la tienda:
- **Rama A (la opción no existe en la tienda):** default `true` (preserva el barrido actual;
  sin regresión). **Recomendada.**
- **Rama B (la opción existe con un valor no-default):** migrar respetando el valor existente
  y usar `true` sólo para instalaciones nuevas.

---

### REQ-CFG-2 — Los cuatro `sync_*` muestran el valor real `ACTIVO`

**Cuando** se renderiza la página de Settings, cada uno de `alegra_connector_sync_products`,
`_customers`, `_orders`, `_categories` debe usar el mismo default que el runtime (`false`) y
el mismo que la activación.

```gherkin
Escenario: opción ausente → checkbox destildado (UI == runtime)
  Dado que la opción alegra_connector_sync_products NO existe
  Cuando se renderiza templates/admin-settings.php
  Entonces el input name="alegra_connector_sync_products" NO tiene el atributo checked
  Y get_option('alegra_connector_sync_products', false) === false

Escenario: opción en false → checkbox destildado
  Dado alegra_connector_sync_products = false
  Cuando se renderiza la página
  Entonces el input NO está checked

Escenario: opción en true → checkbox tildado
  Dado alegra_connector_sync_products = true
  Cuando se renderiza la página
  Entonces el input SÍ está checked

Escenario (estático): los cuatro templates no usan default true
  Entonces el fuente de templates/admin-settings.php NO contiene
    checked(get_option('alegra_connector_sync_products',true))
  Y lo mismo para _customers, _orders, _categories

Escenario: migración siembra las ausentes con false
  Dado una instalación existente sin las cuatro opciones
  Cuando corre Write_Gate::maybe_migrate()
  Entonces get_option('alegra_connector_sync_products') === false
  Y get_option('alegra_connector_sync_customers') === false
  Y get_option('alegra_connector_sync_orders') === false
  Y get_option('alegra_connector_sync_categories') === false

Escenario (NEGATIVO): la migración NO pisa valores existentes
  Dado alegra_connector_sync_products = true (el comerciante lo eligió)
  Cuando corre Write_Gate::maybe_migrate()
  Entonces get_option('alegra_connector_sync_products') === true
```

**Evidencia:** `templates/admin-settings.php:78-81` vs `Controller.php:171,208,236,262` vs
`alegra-connector.php:409-412`. **Nota de honestidad:** como la activación ya siembra `false`,
el default `true` del template sólo se manifiesta en instalaciones que **no** tienen la fila
(upgrades viejos, opción borrada). Aun así, la discrepancia es real y la migración la cierra.

---

### REQ-CFG-3 — Guardar Settings no borra `field_mapping` / `tax_mapping` `ACTIVO`

**Cuando** el comerciante guarda la página de Settings (`option_page=alegra_connector_settings`),
`alegra_connector_field_mapping` y `alegra_connector_tax_mapping` **no deben cambiar**.

**Fundamento (CONFIRMADO, ya no es incógnita).** WordPress `wp-admin/options.php` (verificado
en 5.8 y 6.7) recorre la whitelist del `option_page` y llama `update_option($option, $value)`
**incondicionalmente**; si el campo no está en `$_POST`, `$value` es `null`, y `update_option()`
aplica el `sanitize_option_{$option}` (que `register_setting` engancha al `sanitize_callback`).
El callback actual (`Admin_Dashboard.php:454-463`) convierte `null` en `[]` → **borra** el
mapping. La registración gemela en el grupo `alegra_connector_mapping` (`:563-564`) no ayuda
porque el `option_page` enviado es `alegra_connector_settings`.

```gherkin
Escenario: mapping no está en la whitelist de Settings
  Cuando Admin_Dashboard::register_settings() corre
  Entonces register_setting NO fue llamado con
    ('alegra_connector_settings', 'alegra_connector_field_mapping')
  Y register_setting NO fue llamado con
    ('alegra_connector_settings', 'alegra_connector_tax_mapping')
  Y SÍ fue llamado con ('alegra_connector_mapping', 'alegra_connector_field_mapping')
  Y SÍ fue llamado con ('alegra_connector_mapping', 'alegra_connector_tax_mapping')

Escenario: guardar Settings preserva el mapping (simulación del saneo)
  Dado get_option('alegra_connector_field_mapping') = ['default_category' => 'cat-1']
  Y get_option('alegra_connector_tax_mapping') = ['iva' => 'tax-1']
  Cuando se aplica el sanitize_callback de mapping a $value = null
  Entonces el resultado es ['default_category' => 'cat-1'] (el valor existente, no [])
  Y el resultado de tax es ['iva' => 'tax-1']

Escenario (NEGATIVO): guardar Mapping con datos nuevos SÍ actualiza
  Dado un POST a option_page=alegra_connector_mapping con
    alegra_connector_field_mapping[default_category]='cat-2'
  Cuando se aplica el sanitize_callback
  Entonces el resultado es ['default_category' => 'cat-2']

Escenario (estático): una sola registración por opción de mapping
  Entonces el fuente de Admin_Dashboard.php contiene exactamente una llamada
    register_setting(..., 'alegra_connector_field_mapping')
  Y exactamente una para 'alegra_connector_tax_mapping'
```

**Evidencia:** `Admin_Dashboard.php:454-463`, `:563-564`; `templates/admin-settings.php:13`
(`settings_fields('alegra_connector_settings')`); `templates/admin-mapping.php:16`
(`settings_fields('alegra_connector_mapping')`).

**Decisión de diseño:** el `sanitize_callback` del grupo Mapping debe tratar `null`/no-array
como **"sin cambio"** (devolver el valor existente), no como `[]`. Doble defensa: quitar la
registración del grupo Settings **y** endurecer el callback. Ver `design.md` §3.3.

---

### REQ-CFG-4 — Toggle independiente de clientes `BLOQUEADO(Fase 0.3)`

**Cuando** el comerciante habilita el push de productos, los clientes **no** deben activarse
solos. Debe existir `alegra_connector_push_customers_enabled` (bool), registrada, expuesta,
sembrada y usada por `Public_.php` para los hooks `woocommerce_new_customer` /
`woocommerce_update_customer`.

```gherkin
Escenario: productos habilitados no habilitan clientes
  Dado alegra_connector_push_products_enabled = true
  Y alegra_connector_push_customers_enabled = false
  Cuando se dispara 'woocommerce_new_product'
  Entonces alegra_mock_count('POST','/items') === 1
  Cuando se dispara 'woocommerce_new_customer'
  Entonces alegra_mock_count('POST','/contacts') === 0

Escenario: la opción está registrada y expuesta
  Entonces register_setting fue llamado con 'alegra_connector_push_customers_enabled'
  Y templates/admin-settings.php contiene name="alegra_connector_push_customers_enabled"

Escenario (NEGATIVO): clientes habilitados → el hook de cliente escribe
  Dado alegra_connector_push_customers_enabled = true
  Cuando se dispara 'woocommerce_new_customer'
  Entonces alegra_mock_count('POST','/contacts') === 1
```

**BLOQUEADO(Fase 0.3)** — el valor de migración depende del estado real de la tienda:
- **Rama A (la tienda tenía `push_products_enabled = true`):** migrar
  `push_customers_enabled = push_products_enabled` → preserva el comportamiento actual
  (los clientes ya se subían). Sin regresión.
- **Rama B (la tienda tenía `push_products_enabled = false`):** migrar a `false` (no había
  push de clientes). Sin regresión.
- En ambos casos, **instalaciones nuevas** arrancan con `false` (default).

---

### REQ-CFG-5 — "Run now" / "Sincronizar ahora" es honesto `ACTIVO`

**Cuando** el comerciante aprieta "Sincronizar ahora" con `sync_method ∉ {cron, both}`, el
plugin debe devolver un mensaje que explique por qué no puede ejecutar (en vez del no-op
silencioso actual). Si el kill switch está activo, debe decirlo. Si `sync_method ∈ {cron, both}`
y el kill switch está inactivo, debe ejecutar.

```gherkin
Escenario: sync_method=disabled + "Sincronizar ahora" → mensaje, no no-op
  Dado alegra_connector_sync_method = 'disabled'
  Y el kill switch inactivo
  Cuando Admin_Dashboard::ajax_sync_now recibe {sync_type:'all'}
  Entonces la respuesta es wp_send_json_error (o success con blocked=true)
  Y el mensaje contiene 'desactivada' o 'Periódica'
  Y no se programó ningún evento de cron

Escenario: sync_method=real-time + "Sincronizar ahora" → mensaje
  Dado alegra_connector_sync_method = 'real-time'
  Cuando Admin_Dashboard::ajax_sync_now recibe {sync_type:'all'}
  Entonces el mensaje explica que el modo es sólo tiempo real

Escenario: kill switch activo + "Sincronizar ahora" → mensaje de kill switch
  Dado el kill switch activo
  Cuando Admin_Dashboard::ajax_run_cron_now corre
  Entonces la respuesta explica que el plugin está desconectado/bloqueado

Escenario (NEGATIVO): sync_method=cron + "Sincronizar ahora" → ejecuta
  Dado alegra_connector_sync_method = 'cron'
  Y el kill switch inactivo
  Cuando Admin_Dashboard::ajax_sync_now recibe {sync_type:'all'}
  Entonces se programó (o ejecutó) alegra_connector_cron_sync
  Y la respuesta NO es de error
```

**Evidencia:** `Admin_Dashboard.php:1622-1636` (`ajax_sync_now` → `run_cron_sync`),
`:3134-3172` (`ajax_run_cron_now` encola `alegra_connector_cron_sync_now`),
`Controller.php:129-135` (early return).

---

## C. Robustez

### REQ-RB-1 — `Consumidor_Final::create` nunca se dispara desde un render `ACTIVO`

**Cuando** se renderiza `templates/admin-dashboard.php`, el plugin **no** debe ejecutar
`POST /contacts`. La resolución/creación del Consumidor Final sólo puede ocurrir en una
acción explícita (facturar un pedido, registrar un pago).

```gherkin
Escenario: render del dashboard con cache vacío → sin POST /contacts
  Dado el Consumidor Final sin override, sin transient y sin option cache
  Cuando se renderiza templates/admin-dashboard.php
  Entonces alegra_mock_count('POST','/contacts') === 0
  Y el template no llama a un método que resuelva/crea

Escenario: peek no toca la red
  Dado el Consumidor Final sin cache
  Cuando Consumidor_Final::peek_id() corre
  Entonces devuelve false
  Y alegra_mock_count('GET','/contacts') === 0
  Y alegra_mock_count('POST','/contacts') === 0

Escenario (NEGATIVO): facturar un pedido explícito SÍ puede crear el contacto
  Dado el Consumidor Final sin cache
  Y el kill switch inactivo
  Cuando se ejecuta una acción explícita que lo requiere
  Entonces alegra_mock_count('POST','/contacts') === 1

Escenario: con kill switch activo, ni siquiera la acción explícita lo crea
  Dado el kill switch activo
  Cuando se ejecuta la acción explícita que lo requiere
  Entonces alegra_mock_count('POST','/contacts') === 0
```

**Evidencia:** `templates/admin-dashboard.php:18` → `Consumidor_Final.php:42` (`get_id`),
`:66` (`resolve`), `:168` (llama a `create`), `:212-214` (`create_contact`).

**Decisión:** separar `peek_id()` (sólo cache/override, sin red) de
`get_or_create_id()` (resolución completa, sólo contexto explícito). El template usa
`is_configured()`/`peek_id()`. Ver `design.md` §4.1.

---

### REQ-RB-2 — Los flujos por lotes honran la cancelación; los importadores por ítem, el kill switch `ACTIVO`

**Cuando** `alegra_sync_cancelled` está seteado, `ajax_sync_page` y `ajax_sync_pending_page`
deben detenerse **en el próximo lote**, no seguir hasta el final. Los importadores por ítem
(`Products::import_single_item_from_alegra`, `Customers::import_single_contact`) deben chequear
kill switch y cancelación como lo hace `import_from_alegra`.

```gherkin
Escenario: cancelación detiene ajax_sync_page en el próximo lote
  Dado un estado de sync con 30 ítems pendientes
  Y alegra_sync_cancelled seteado
  Cuando Admin_Dashboard::ajax_sync_page corre
  Entonces la respuesta tiene done=true y cancelled=true
  Y no se importó ningún ítem del lote

Escenario: cancelación detiene ajax_sync_pending_page
  Dado un estado con 30 pedidos pendientes
  Y alegra_sync_cancelled seteado
  Cuando Admin_Dashboard::ajax_sync_pending_page corre
  Entonces la respuesta tiene cancelled=true
  Y alegra_mock_count('POST','/invoices') === 0

Escenario: importador por ítem respeta el kill switch
  Dado el kill switch activo
  Cuando Products::import_single_item_from_alegra($id) corre
  Entonces devuelve WP_Error('kill_switch_active') o un skip
  Y no se ejecutó GET /items/{id} ni ninguna escritura

Escenario (NEGATIVO): sin cancelación el lote continúa
  Dado alegra_sync_cancelled NO seteado
  Cuando ajax_sync_page corre
  Entonces procesa el lote normalmente
```

**Evidencia:** `Admin_Dashboard.php:1832` (`ajax_sync_page`), `:2825`
(`ajax_sync_pending_page`), `:2532` (único lugar que **setea** `alegra_sync_cancelled`),
`Sync/Products.php:1419`, `Sync/Customers.php:328`; contraste con `Products.php:1266,1306`
y `Customers.php:218,247`.

---

### REQ-RB-3 — `ajax_disconnect` maneja honestamente dry-run y bloqueo `ACTIVO`

**Cuando** `ajax_disconnect` corre, debe: (a) borrar los webhooks **antes** de activar el kill
switch (si no, la puerta bloquearía el propio cleanup); (b) no contar como borrado lo que la
puerta o el dry-run bloquearon; (c) reportar `webhooks_deleted` a partir del resultado real.

```gherkin
Escenario: dry-run activo → no miente sobre los borrados
  Dado alegra_connector_dry_run = true
  Y 2 suscripciones de webhook en option
  Cuando Admin_Dashboard::ajax_disconnect corre
  Entonces alegra_mock_count('DELETE','/webhooks/subscriptions') === 0
  Y la respuesta NO reporta webhooks_deleted=2 como si se hubieran borrado
  Y el mensaje indica que fue simulado (dry-run)

Escenario: disconnect con kill switch inactivo → borra webhooks y luego activa KS
  Dado el kill switch inactivo y 2 suscripciones
  Cuando ajax_disconnect corre
  Entonces alegra_mock_count('DELETE','/webhooks/subscriptions') === 2
  Y Kill_Switch::is_active() === true al finalizar
  Y webhooks_deleted === 2

Escenario: plugin ya desconectado → reporta bloqueo, no borrados falsos
  Dado el kill switch ya activo
  Cuando ajax_disconnect corre
  Entonces alegra_mock_count('DELETE','/webhooks/subscriptions') === 0
  Y la respuesta reporta blocked/skipped con reason='kill_switch'
```

**Evidencia:** `Admin_Dashboard.php:2897-2955` (`:2904` activa KS, `:2912` borra,
`:2952` `count($subscriptions)` local) contra `:2710-2755` (que sí maneja
`is_dry_run_response`).

---

## D. Higiene

### REQ-HYG-1 — La superficie muerta se elimina o se conecta, y `uninstall.php` limpia lo nuevo `BLOQUEADO(Fase 0.2)`

**Cuando** se cierra el cambio:
1. Las 3 opciones sólo-escritura (`items_count`, `contacts_count`, `disconnected_at`) deben
   eliminarse o leerse. Decisión: **eliminar las escrituras** (nunca se leen).
2. Los 14 métodos de `Client` sin llamadores deben eliminarse **o** documentarse como API
   pública. Decisión propuesta: **eliminar** (ver BLOQUEADO).
3. Los 3 `sync_all()` sin llamador deben eliminarse **o** conectarse. Decisión: **eliminar**.
4. `uninstall.php` debe borrar `alegra_connector_payment_reconcile_enabled`,
   `_batch`, `alegra_connector_push_customers_enabled` y `alegra_connector_gate_migration_version`.

```gherkin
Escenario (estático): los 14 métodos ya no existen
  Entonces Client.php NO contiene 'function delete_item_category'
  Y NO contiene 'function void_credit_note'
  Y NO contiene 'function update_credit_note'
  Y NO contiene 'function delete_credit_note'
  Y NO contiene 'function update_payment'
  Y NO contiene 'function delete_payment'
  Y NO contiene 'function void_payment'
  Y NO contiene 'function open_payment'
  Y NO contiene 'function create_price_list'
  Y NO contiene 'function update_price_list'
  Y NO contiene 'function delete_price_list'
  Y NO contiene 'function create_inventory_adjustment'
  Y NO contiene 'function create_estimate'
  Y NO contiene 'function update_invoice_retentions'

Escenario (estático): los sync_all muertos ya no existen
  Entonces Sync/Products.php NO contiene 'function sync_all'
  Y Sync/Customers.php NO contiene 'function sync_all'
  Y Sync/Categories.php NO contiene 'function sync_all'

Escenario (estático): uninstall limpia las opciones nuevas
  Entonces uninstall.php contiene delete_option('alegra_connector_payment_reconcile_enabled')
  Y contiene delete_option('alegra_connector_payment_reconcile_batch')
  Y contiene delete_option('alegra_connector_push_customers_enabled')
  Y contiene delete_option('alegra_connector_gate_migration_version')

Escenario: las opciones muertas se siguen limpiando en uninstall (legacy)
  Entonces uninstall.php SIGUE conteniendo delete_option('alegra_connector_items_count')
  Y delete_option('alegra_connector_contacts_count')
  Y delete_option('alegra_connector_disconnected_at')
```

**BLOQUEADO(Fase 0.2)** — borrar métodos públicos de una clase de un plugin distribuido:
- **Rama A (0 callers externos confirmados):** eliminar los 14 métodos + 3 `sync_all`.
- **Rama B (hay consumidores externos o no se puede confirmar):** mantenerlos con
  `@deprecated 2.4.0` y una nota; eliminar sólo `sync_all` y las opciones muertas.

---

### REQ-HYG-2 — Las secciones decorativas se eliminan o se conectan `BLOQUEADO(Fase 0.5)`

**Cuando** se cierra el cambio, `add_settings_section()` no debe quedar como código muerto.

```gherkin
Escenario (estático): sin secciones decorativas
  Entonces el fuente de Admin_Dashboard.php NO contiene 'add_settings_section('
  O BIEN el fuente de templates/admin-settings.php contiene 'do_settings_sections('

Escenario: el harness de smoke queda consistente
  Dado que scripts/smoke-load.php:575-576 asserta
    add_settings_section('alegra_connector_billing_section')
  Cuando se remueven las secciones
  Entonces esa aserción se actualiza y smoke-test.sh queda verde
```

**Evidencia:** `Admin_Dashboard.php:566-571` (6 secciones), `scripts/smoke-load.php:575-576`
(aserción de la sección de billing), 0 llamadas a `do_settings_sections` y 0
`add_settings_field` en producción.

**Decisión:** **eliminar** las 6 secciones y actualizar la aserción de smoke (no tiene sentido
llamar `do_settings_sections()` sin campos registrados; los templates ya renderizan tablas).
**BLOQUEADO(Fase 0.5)** — confirmar el alcance exacto de las aserciones de `smoke-load.php`
antes de tocarlas.

---

## E. Escenarios transversales (composición dry-run / puerta)

### REQ-COMP-1 — `dry_run` y la puerta componen, no se reemplazan `ACTIVO`

```gherkin
Escenario: dry_run tiene prioridad sobre la puerta
  Dado alegra_connector_dry_run = true
  Y el kill switch activo
  Cuando Client::post('/invoices', [...])
  Entonces Client::is_dry_run_response($r) === true
  Y Client::is_gate_blocked_response($r) === false
  Y alegra_mock_count('POST','/invoices') === 0

Escenario: dry_run no evita que el bloqueo se reporte cuando aplica
  Dado alegra_connector_dry_run = false
  Y alegra_connector_push_orders_enabled = false
  Cuando se dispara 'woocommerce_new_order'
  Entonces $r['reason'] === 'entity_disabled'

Escenario: GET nunca se bloquea por la puerta
  Dado el kill switch activo
  Cuando Client::get('/invoices')
  Entonces la puerta NO bloquea (GET pasa)
  Y el mock registra 1 GET /invoices
```

**Fundamento:** el dry-run actual (`Client.php:106`) bloquea sólo verbos ≠ GET. La puerta
respeta esa misma regla: **sólo escrituras**.

---

## F. Matriz de trazabilidad (hallazgo → requerimiento)

| Hallazgo | Requerimiento |
|---|---|
| 1 (kill switch no en el choke point) | REQ-ENF-1, REQ-ENF-3 |
| 2 (reconcile ignora KS y flag) | REQ-ENF-1, REQ-ENF-2, REQ-CFG-1 |
| 3 (payment_reconcile incontrolable) | REQ-CFG-1, REQ-HYG-1 |
| 4 (refunds / payment-method sin gate) | REQ-ENF-1, REQ-ENF-2 |
| 5 (pushes manuales sin gate) | REQ-ENF-1, REQ-ENF-2 |
| 6 (sync_* mienten) | REQ-CFG-2 |
| 7 (mappings se borran) | REQ-CFG-3 |
| 8 (Consumidor_Final en render) | REQ-RB-1, REQ-ENF-1 |
| 9 (lotes ignoran cancelación) | REQ-RB-2 |
| 10 (disconnect deshonesto) | REQ-RB-3 |
| 11 (Run now no-op) | REQ-CFG-5 |
| 12 (clientes comparten toggle) | REQ-CFG-4 |
| 13 (superficie muerta) | REQ-HYG-1 |
| 14 (webhook público) | REQ-ENF-1 (kill switch), REQ-HYG-1 (nota) |
| 15 (secciones decorativas) | REQ-HYG-2 |
