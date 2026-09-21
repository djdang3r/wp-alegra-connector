# Tareas — Pagos, Reconciliación y Ajustes

| Campo | Valor |
|---|---|
| Cambio | `payments` |
| Documentos | `proposal.md` · `spec.md` · `design.md` |
| Convención | `T<fase>.<n>` · `[ ]` pendiente · `[x]` hecho |
| Leyenda | `BLOQUEADO(Fase 0.x)` = no empezar hasta tener el resultado |
| Harness | `bash scripts/exec-test.sh` · `bash scripts/smoke-test.sh` |

> **Regla de oro:** la **Fase 0 se ejecuta antes que la parte de código que depende de
> ella**. La **Fase 1 es la causa raíz confirmada y NO depende de Fase 0**: se puede
> implementar ya. Las Fases 2, 6 y 7 tienen ramas que dependen de 0.5/0.6/0.7/0.8.

---

## Fase 0 — Verificaciones en vivo

**Objetivo:** responder las incógnitas que bloquean el spec.
**Entorno:** la tienda del reportante (o una réplica) + la cuenta Alegra real.
**DoD de la fase:** cada verificación tiene resultado registrado en
`docs/sdd/payments/phase0-results.md`, y cada requerimiento `BLOQUEADO` tiene su rama
elegida.

### Estado actual

| Task | Pregunta | Estado | Resultado |
|---|---|---|---|
| T0.1 | ¿Valor de `alegra_connector_payment_account_id`? | ✅ **RESUELTO** | `'5'` (id numérico, correcto) |
| T0.2 | ¿La cuenta aparece seleccionada? | ✅ **RESUELTO** | Sí: `<option value="5" selected="selected">` |
| T0.3 | ¿Forma de `/bank-accounts`? | ✅ **RESUELTO** | ids numéricos `5,4,3,2,1`; incluye **cajas** |
| T0.4 | ¿`_alegra_payment_id` del pedido? | ✅ **RESUELTO** | vacío (el síntoma) |
| T0.5 | ¿Blocks admite `value=''`? | ⏳ PENDIENTE | — |
| T0.6 | ¿Qué hooks dispara la pasarela? | ⏳ PENDIENTE | — |
| T0.7 | ¿Tags v2.3.8–2.3.10 reconstruibles? | ⏳ PENDIENTE | — |
| T0.8 | ¿`/invoices/{id}/open` abre un borrador? | ⏳ PENDIENTE | — |

### T0.1 — Valor de `alegra_connector_payment_account_id` ✅ RESUELTO

- **Resultado:** `'5'`. La cuenta **no** fue clobbada; es un id numérico.
- **Consecuencia:** el diagnóstico anterior (clobber a `'0'`) queda **descartado** como
  causa. Se elimina la rama "recuperar UUID".
- **DoD:** valor crudo registrado. ✔

### T0.2 — La cuenta aparece seleccionada ✅ RESUELTO

- **Resultado:** el `<select>` renderiza `<option value="5" selected="selected">`; el
  guardado no la pisa.
- **Consecuencia:** el bug del select es **latente**, no reproducido. Baja a Fase 5.
- **DoD:** captura del `<option selected>`. ✔

### T0.3 — Forma de `/bank-accounts` ✅ RESUELTO

- **Resultado:** devuelve cuentas con `id` **numérico** (`5,4,3,2,1`) y `name`; incluye
  **cajas** además de bancos.
- **Consecuencia:** `bank_account_select_options()` debe castear `id` a string y
  comparar contra el valor guardado como string; el label debe decir "banco o caja"
  (REQ-CFG-5). Sin rama B.
- **DoD:** JSON crudo + forma documentada. ✔

### T0.4 — Estado de `_alegra_payment_id` ✅ RESUELTO

- **Resultado:** vacío en el pedido de prueba pagado con factura → el síntoma confirmado.
- **Consecuencia:** el barrido y "Facturar" lo resolverán. Sin rama B.
- **DoD:** valor registrado. ✔

### T0.5 — ¿Blocks admite una opción con `value=''`? ⏳ PENDIENTE

- **Pasos:**
  1. En un sitio con Checkout Blocks, agregar temporalmente una opción vacía vía
     `woocommerce_register_additional_checkout_field`.
  2. Ver si el checkout la renderiza y si el submit la acepta.
  3. Alternativa sin sitio: revisar `developer.woocommerce.com` (Checkout Additional
     Fields) y la validación de schema.
- **Ramas:**
  - **Rama A (admite):** REQ-CHK-2 se implementa anteponiendo la opción.
  - **Rama B (no admite):** REQ-CHK-2 se implementa validando `''` sin preselección
    (ver `design.md` §7.2).
- **DoD:** resultado + rama elegida.

### T0.6 — ¿Qué hooks disparan las pasarelas de la tienda? ⏳ PENDIENTE

- **Pasos:**
  1. Listar las pasarelas activas (`wp option get woocommerce_gateway_order`).
  2. Con un pedido de prueba, registrar temporalmente
     `add_action('all', fn($h) => error_log($h))` filtrado por
     `woocommerce_payment_complete` y `woocommerce_order_status_processing`.
  3. Pagar con **Mercado Pago** y ver qué hooks se disparan, en qué orden, y en qué
     estado queda el pedido (`processing`/`completed` vs `pending`/`on-hold`).
- **Ramas:**
  - **Rama A (el pedido queda pagado):** `is_paid()` es true; hooks de REQ-REC-3
    completos.
  - **Rama B (el pedido queda en `pending`/`on-hold`):** la pasarela no marca el pedido
    como pagado en WC → el barrido es la red principal y hay que documentar la
    configuración de la pasarela.
- **DoD:** lista de hooks + estado final del pedido + rama elegida.

### T0.7 — ¿Se pueden reconstruir los tags v2.3.8/2.3.9/2.3.10? ⏳ PENDIENTE

- **Pasos:**
  1. `git log --oneline --decorate` y cruzar con `CHANGELOG.md`.
  2. Identificar el commit de cada versión.
- **Ramas:**
  - **Rama A (identificables):** crear tags retroactivos y Releases.
  - **Rama B (no identificables):** publicar solo desde el próximo release.
- **DoD:** decisión + commits (si aplica).

### T0.8 — ¿`POST /invoices/{id}/open` abre un borrador o revierte una anulación? ⏳ PENDIENTE

- **Por qué:** `ensure_invoice_open` (`Orders.php:400-433`) lo usa para pasar de `draft` a
  `open`, pero la doc lo describe como **un-void**.
- **Pasos:**
  1. Crear una factura `draft` en la cuenta de prueba (o tomar una existente).
  2. `curl -u "$EMAIL:$TOKEN" -X POST https://api.alegra.com/api/v1/invoices/{id}/open`.
  3. Observar el `status` resultante (`open`? error?).
  4. Probar `PUT /invoices/{id}` con `{"status":"open"}` y observar.
- **Ramas:**
  - **Rama A (`/open` abre el borrador):** `ensure_invoice_open` se mantiene.
  - **Rama B (`/open` es un-void):** cambiar a `PUT /invoices/{id}` `{"status":"open"}`.
- **Doc:** <https://developer.alegra.com/reference/post_invoices-id-open.md>,
  <https://developer.alegra.com/reference/put_invoices-id.md>.
- **DoD:** respuesta cruda + `status` antes/después + rama elegida.

---

## Fase 1 — "Facturar" registra el pago (causa raíz CONFIRMADA)

**DoD de la fase:** REQ-MAN-1..4 verdes; `T-MAN-1..4` en el harness; el pedido pagado
termina con `_alegra_payment_id` seteado; el no pagado no postea pago y avisa.

### [ ] T1.1 — "Facturar" usa el camino completo
- **Archivo:** `admin/Admin/Admin_Dashboard.php:2216`
- **Qué:** `case 'order'` pasa de `sync_entity('order', $entity_id, 'create')` a
  `'complete'`.
- **DoD:** el botón dispara `create_invoice_with_payment`; el payload ya no usa
  `record_payment`.

### [ ] T1.2 — Guard `is_paid()` + aviso al no pagado
- **Archivo:** `includes/Sync/Orders.php:279-386` (`create_invoice_with_payment`)
- **Qué:** agregar `&& $order->is_paid()` a `$will_record_payment` (`:289-291`); si el
  pedido no está pagado y hay cuenta, agregar nota de pedido explicando que no se registró
  pago.
- **DoD:** pedido on-hold → 0 `POST /payments` + nota; pedido pagado → 1 pago.

### [ ] T1.3 — Extraer `record_payment_for_invoice()`
- **Archivo:** `includes/Sync/Orders.php:306-383`
- **Qué:** extraer el bloque de pago a un método privado reutilizable (design §5.3);
  `create_invoice_with_payment` lo usa.
- **DoD:** una sola implementación del POST /payments; T3.3 y T18.11 siguen verdes.

### [ ] T1.4 — Bulk y pendientes usan el camino con pago
- **Archivos:** `admin/Admin/Admin_Dashboard.php:2332`, `includes/Sync/Orders.php:720`
- **Qué:** bulk `order` → `'complete'`; `sync_recent` → `create_invoice_with_payment`.
- **DoD:** bulk de 2 pagados → 2 pagos; pendientes de 3 pagados → 3 pagos.

### [ ] T1.5 — Tests `T-MAN-1..4` + prove-it-catches
- **Archivo:** `scripts/exec-test.php`
- **Qué:**
  - `T-MAN-1`: "Facturar" pagado → 1 pago con `bankAccount.id='5'`,
    `amount=get_total()`, `paymentMethod` mapeado; on-hold → 0 pagos + nota; ya
    facturado+pagado → 0 pagos.
  - `T-MAN-2`: bulk de 2 pagados → 2 pagos; 1 pagado + 1 on-hold → 1 pago.
  - `T-MAN-3`: `sync_recent` de 3 pagados → 3 pagos.
  - `T-MAN-4`: pago ya registrado → 0 `POST /payments`; pago preexistente se recupera.
- **DoD:** los 4 pasan; **prove-it-catches:** quitar el guard `is_paid()` hace fallar el
  caso on-hold de `T-MAN-1`; volver `:2216` a `'create'` hace fallar el caso pagado.

---

## Fase 2 — Fuente de datos de pago y mapeo de pasarela

**DoD de la fase:** REQ-PAY-1..2 y REQ-DRAFT-1 verdes; fecha desde WC; fallback único.

### [ ] T2.1 — Fecha de pago desde WooCommerce
- **Archivo:** `includes/Sync/Orders.php:1477` (`prepare_payment_data`) + nuevo
  `payment_date()`
- **Qué:** usar `$order->get_date_paid()` (`Y-m-d`); fallback a `date('Y-m-d')` con
  warning si es `null`.
- **DoD:** `date` del pago = fecha del pedido; nulo → hoy + warning.

### [ ] T2.2 — Resolvedor único de método + fallback `transfer`
- **Archivo:** `includes/Sync/Orders.php:1518-1547`
- **Qué:** unificar `get_payment_method_code` y `getPaymentMethodForGateway` en
  `resolve_alegra_payment_method()`; fallback único `'transfer'`; Mercado Pago →
  `'credit-card'`; log de pasarela desconocida.
- **DoD:** ambos resolvedores coinciden; todo valor ∈ enum oficial; `T-PAY-2` verde.

### [ ] T2.3 — Reporte de discrepancia monto vs saldo
- **Archivo:** `includes/Sync/Orders.php` (`prepare_payment_data`)
- **Qué:** `assert_full_payment_matches_balance()`: `GET /invoices/{id}`, comparar
  `balance` con `get_total()`; si difiere, nota + warning (no ajustar).
- **DoD:** total 100 vs saldo 80 → nota/log; pago igual se registra por el total.

### [ ] T2.4 — Modo borrador: `ensure_invoice_open` (rama Fase 0.8)
- **Archivo:** `includes/Sync/Orders.php:400-433`, `includes/API/Client.php:693-696`
- **Qué:** si Rama A, no se toca; si Rama B, `PUT /invoices/{id}` `{"status":"open"}` vía
  nuevo `update_invoice_status()`.
- **DoD:** factura `draft` queda `open` antes del `POST /payments`; factura ya `open` no se
  toca.
- **BLOQUEADO(Fase 0.8).**

### [ ] T2.5 — Tests `T-PAY-1..2` + `T-DRAFT-1` + prove-it-catches
- **Archivo:** `scripts/exec-test.php`, `scripts/lib/wp-stubs.php`
- **Qué:**
  - `wp-stubs.php`: agregar `get_payment_method_title()` y permitir seedear
    `date_paid`.
  - `T-PAY-1`: monto/fecha desde WC; fecha nula → hoy + warning; discrepancia reportada;
    nota menciona la pasarela.
  - `T-PAY-2`: Mercado Pago → `credit-card`; desconocida → `transfer` + no falla; ambos
    resolvedores coinciden.
  - `T-DRAFT-1`: draft → open antes de pagar; open → no se toca; alternativa PUT.
- **DoD:** pasan; **prove-it-catches:** volver a `date('Y-m-d')` hace fallar `T-PAY-1`;
  cambiar el fallback de una función hace fallar `T-PAY-2`.

**BLOQUEADO(Fase 0.8):** T2.4 y `T-DRAFT-1` dependen de la rama.

---

## Fase 3 — Hooks de reconciliación

**DoD de la fase:** REQ-REC-1,2,3,5,6 verdes; T12.1/T12.2/T12.3 verdes.

### [ ] T3.1 — `reconcile_payment_only()`
- **Archivo:** `includes/Sync/Orders.php`
- **Qué:** método público con el guard exacto (factura + sin pago + `is_paid()`) + lock por
  pedido (design §5.3).
- **DoD:** sin factura/pago/paid → no-op; con factura+paid+cuenta → 1 pago.

### [ ] T3.2 — `on_order_paid_reconcile()` y registro fuera del gate
- **Archivo:** `public/Public/Public_.php:37-71`
- **Qué:** handler + `add_action` para `payment_complete`, `order_status_processing`,
  `order_status_completed`, **fuera** del `if`.
- **DoD:** con `push_orders_enabled=false`, los 3 hooks están registrados y
  `on_new_order`/`on_payment_complete` no.

### [ ] T3.3 — Aviso de cuenta no configurada
- **Archivo:** `public/Public/Public_.php` (handler)
- **Qué:** nota de pedido + warning de log cuando falta la cuenta.
- **DoD:** `T-CFG-4` sigue verde; nota y log presentes.

### [ ] T3.4 — Tests `T-REC-1,2,3,5,6` + prove-it-catches
- **Archivo:** `scripts/exec-test.php`
- **Qué:**
  - `T-REC-1`: registro de hooks en modo manual (usa `on_order_paid_reconcile`).
  - `T-REC-2`: los 3 guards negativos → 0 pagos.
  - `T-REC-3`: `do_action('woocommerce_order_status_processing', id)` con factura →
    1 pago; con `payment_complete` + `processing` → 1 pago.
  - `T-REC-5`: modo manual sin factura → 0 `POST /invoices`.
  - `T-REC-6`: dos disparos concurrentes → 1 `POST /payments`.
- **DoD:** pasan; **prove-it-catches:** mover los hooks dentro del gate hace fallar
  `T-REC-1`; quitar el guard de `_alegra_payment_id` hace fallar `T-REC-6`.

**BLOQUEADO(Fase 0.6):** `T-REC-3` depende de que la pasarela deje el pedido pagado.

---

## Fase 4 — Barrido de reintento

**DoD de la fase:** REQ-REC-4 verde; cron agendado/limpiado; kill-switch y lock probados.

### [ ] T4.1 — `reconcile_missing_payments()`
- **Archivo:** `includes/Sync/Orders.php`
- **Qué:** query con `meta_query` (design §6.3) + batching + kill-switch en vuelo.
- **DoD:** 2 pedidos pendientes → 2 pagos; pedido sin factura no se toca.

### [ ] T4.2 — `Controller::run_payment_reconcile()`
- **Archivo:** `includes/Sync/Controller.php`
- **Qué:** kill-switch + lock global + delegación.
- **DoD:** kill-switch → 0 pagos + `skipped_kill_switch`; lock tomado → `skipped_locked`.

### [ ] T4.3 — Scheduling y limpieza
- **Archivo:** `alegra-connector.php` (activación/`plugins_loaded`/desactivación)
- **Qué:** `wp_schedule_event(hourly)`, auto-reparación, `wp_clear_scheduled_hook` al
  desactivar, borrado en `uninstall.php`.
- **DoD:** tras activar, `wp_next_scheduled` no es `false`; tras desactivar, es `false`.

### [ ] T4.4 — Tests `T-REC-4` + prove-it-catches
- **Archivo:** `scripts/exec-test.php`
- **Qué:** barrido reconcilia; kill-switch; lock; no toca sin factura.
- **DoD:** pasan; **prove-it-catches:** quitar el chequeo de kill-switch hace fallar el
  escenario de kill-switch.

---

## Fase 5 — Endurecimiento de ajustes y cuenta de destino (clobber latente)

**DoD de la fase:** REQ-CFG-1..5 verdes; `T-CFG-1..5`; `smoke-test` verde.

### [ ] T5.1 — Extraer `bank_account_select_options()`
- **Archivo:** `admin/Admin/Admin_Dashboard.php`
- **Qué:** método estático puro que arma las opciones garantizando el `selected` del valor
  guardado e inyecta la opción sintética (design §4.2a); comparación de `id` como string.
- **DoD:** método existe, sin I/O, con la firma exacta del diseño.

### [ ] T5.2 — Usar el método en la plantilla + label "banco o caja"
- **Archivo:** `templates/admin-settings.php:337-350`
- **Qué:** reemplazar el loop por `bank_account_select_options()`; ampliar la condición
  del `if`; cambiar el label a **"Cuenta de destino para pagos (banco o caja)"**.
- **DoD:** el HTML renderizado tiene exactamente una opción `selected` con el valor
  guardado; el label menciona banco y caja.

### [ ] T5.3 — Extraer `sanitize_alegra_id()` y hacerlo avisar
- **Archivo:** `admin/Admin/Admin_Dashboard.php:288-364`
- **Qué:** método estático con `add_settings_error` en el rechazo; usarlo en
  `register_setting` para `payment_account_id`, `warehouse_id`, `payment_term_id`.
- **DoD:** entrada inválida conserva el valor previo y registra un settings_error.

### [ ] T5.4 — Banner de éxito condicionado + render de errores
- **Archivo:** `templates/admin-settings.php:9-11`
- **Qué:** `settings_errors('alegra_connector_settings')` antes del banner; el banner solo
  si no hay errores.
- **DoD:** con un rechazo no aparece "guardado correctamente"; sí el error.

### [ ] T5.5 — Default de activación
- **Archivo:** `alegra-connector.php` (activación) + `Admin_Dashboard.php:363-364`
- **Qué:** `add_option(..., '')` en activación y `'default' => ''` en register.
- **DoD:** tras activar, `get_option(...)` devuelve `''`.

### [ ] T5.6 — Tests `T-CFG-1..5` + prove-it-catches
- **Archivo:** `scripts/exec-test.php`, `scripts/lib/alegra-mock.php`
- **Qué:**
  - `alegra-mock.php`: agregar ruta `GET /bank-accounts` y helper
    `alegra_mock_seed_bank_account()`.
  - `T-CFG-1`: `bank_account_select_options([['id'=>4]], '5')` → existe entrada `'5'`
    con `selected=true` y `'0'` con `selected=false`. **Assert exacto.**
  - `T-CFG-2`: `sanitize_alegra_id('no-id', ...)` devuelve el valor previo y
    `get_settings_errors()` contiene el error.
  - `T-CFG-3`: activación default + fallback con `/bank-accounts` en error.
  - `T-CFG-4`: sin cuenta → 0 `POST /payments` + nota + warning en log.
  - `T-CFG-5`: el label renderizado contiene "banco o caja".
- **DoD:** los 5 pasan; **prove-it-catches:** revertir la inyección sintética de T5.1
  hace fallar `T-CFG-1`; revertir el `add_settings_error` hace fallar `T-CFG-2`.

**Desbloqueado:** Fase 0.1/0.2/0.3 confirmaron cuenta `'5'`, `selected` correcto e ids
numéricos incluyendo cajas. Sin rama B.

---

## Fase 6 — Checkout

**DoD de la fase:** REQ-CHK-1..2 verdes; valor guardado intacto.

### [ ] T6.1 — Placeholder clásico
- **Archivo:** `includes/Billing_Fields.php:499-501`
- **Qué:** anteponer `'' => 'Seleccione…'` a las opciones select.
- **DoD:** `billing_alegra_idtype` tiene `''` primero y RC no preseleccionado.

### [ ] T6.2 — Placeholder Blocks
- **Archivo:** `includes/Checkout_Integration.php:188-202`
- **Qué:** anteponer la opción vacía (rama A de Fase 0.5).
- **DoD:** primer elemento de `block_options('idtype')` es `value=''`.
- **BLOQUEADO(Fase 0.5).**

### [ ] T6.3 — Tests `T-CHK-1..2`
- **Archivo:** `scripts/exec-test.php`
- **Qué:** `T-CHK-1` assertea la opción `''` en `render_checkout_fields`; `T-CHK-2` usa
  `alegra_call_private` para `block_options`.
- **DoD:** pasan; **prove-it-catches:** quitar la inyección hace fallar `T-CHK-1`.

---

## Fase 7 — Versión y release

**DoD de la fase:** REQ-REL-1..4 verdes; gates de CI definidos.

### [ ] T7.1 — Fuente de verdad de la versión
- **Archivos:** `alegra-connector.php:6,29`, `scripts/lib/wp-stubs.php`
- **Qué:** derivar la constante del encabezado; stub de `get_file_data()` si falta.
- **DoD:** encabezado y constante idénticos; `T-REL-1` verde.

### [ ] T7.2 — Preflight en `build-release.sh`
- **Archivo:** `scripts/build-release.sh`
- **Qué:** comparar encabezado vs argumento; `exit 9` si difieren.
- **DoD:** versión divergente → exit ≠ 0 y sin ZIP nuevo.

### [ ] T7.3 — Workflow de release
- **Archivo:** `.github/workflows/release.yml` (nuevo)
- **Qué:** gates + build + `action-gh-release`.
- **DoD:** YAML válido; en tag `v*` publica ZIP + sha256.

### [ ] T7.4 — README
- **Archivo:** `README.md:29`
- **Qué:** apuntar a `/releases/latest`.
- **DoD:** `T-REL-4` verde.

### [ ] T7.5 — Backfill de tags/releases (si Fase 0.7 lo permite)
- **Qué:** tags v2.3.8/2.3.9/2.3.10 + Releases con sus ZIP.
- **DoD:** tags creados o decisión documentada de publicar solo desde el próximo.
- **BLOQUEADO(Fase 0.7).**

### [ ] T7.6 — Tests `T-REL-1..4`
- **Archivo:** `scripts/exec-test.php`
- **Qué:** versión == encabezado; cache-buster; build rechaza mismatch; README.
- **DoD:** pasan; **prove-it-catches:** restaurar la constante stale hace fallar
  `T-REL-1`.

---

## Fase 8 — Regresión y cierre

**DoD de la fase:** harness verde + sin regresiones + CHANGELOG.

### [ ] T8.1 — Correr los gates completos
- **Comando:** `bash scripts/smoke-test.sh && bash scripts/exec-test.sh`
- **DoD:** ambos exit 0; T3.3, T12.1, T12.2, T12.3, T12.4, T18.11, T18.14, T18.15 verdes.

### [ ] T8.2 — Prove-it-catches consolidado
- **Qué:** por cada test nuevo, revertir su fix y adjuntar la falla observada.
- **DoD:** tabla test → fix revertido → falla, en el PR.

### [ ] T8.3 — CHANGELOG y DOCUMENTACION
- **Archivos:** `CHANGELOG.md`, `docs/DOCUMENTACION.md`
- **Qué:** documentar que "Facturar" ahora registra el pago si el pedido está pagado; la
  fuente de datos WC; el nuevo hook y el cron; que el clobber del select era latente.
- **DoD:** entradas presentes y en español.

### [ ] T8.4 — Verificación en vivo post-deploy (manual, una factura)
- **Qué:** en la tienda del reportante, un pedido pagado con factura previa sin pago;
  confirmar 1 pago en Alegra y `_alegra_payment_id` seteado. Verificar el caso nuevo:
  "Facturar" sobre un pedido pagado vía Mercado Pago.
- **DoD:** captura de Alegra + meta del pedido.

---

## Matriz de dependencias

| Fase | Depende de |
|---|---|
| 1 | **Nada** (causa raíz confirmada) |
| 2 | Fase 0.8 (solo T2.4/T-DRAFT-1) |
| 3 | Fase 1 (T1.3 refactor) · Fase 0.6 para T3.4 |
| 4 | Fase 3 (T3.1) |
| 5 | Nada (Fase 0.1/0.2/0.3 resueltas) |
| 6 | Fase 0.5 (solo T6.2) |
| 7 | Fase 0.7 (solo T7.5) |
| 8 | Todas |

## Conteo

- Verificaciones Fase 0: **8** (4 resueltas ✅, 4 pendientes ⏳).
- Tareas de implementación: **37** (Fases 1–8):
  Fase 1 = 5 · Fase 2 = 5 · Fase 3 = 4 · Fase 4 = 4 · Fase 5 = 6 · Fase 6 = 3 ·
  Fase 7 = 6 · Fase 8 = 4.
- Tests nuevos propuestos: **25** (`T-MAN-1..4`, `T-PAY-1..2`, `T-REC-1..6`,
  `T-DRAFT-1`, `T-CFG-1..5`, `T-CHK-1..2`, `T-REL-1..4`, `T-NR-1`).
