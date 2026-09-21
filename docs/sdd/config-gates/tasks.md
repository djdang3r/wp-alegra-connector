# Tareas — Puertas de configuración y enforcement de escrituras

| Campo | Valor |
|---|---|
| Cambio | `config-gates` |
| Documentos | `proposal.md` · `spec.md` · `design.md` |
| Convención | `T<fase>.<n>` · `[ ]` pendiente · `[x]` hecho · `BLOQUEADO(Fase 0.x)` |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`, mock `scripts/lib/alegra-mock.php`) · `bash scripts/smoke-test.sh` (→ `scripts/smoke-load.php`) |
| Versión objetivo | 2.4.0 |

> **Regla de oro.** La **Fase 0** se ejecuta antes que las tareas que dependen de ella.
> La **Fase 1** (enforcement) **no depende de Fase 0** salvo la decisión de borrado de métodos
> (T0.2, que es Fase 5). Las Fases 2 y 5 tienen ramas que dependen de Fase 0.
>
> **Prove-it-catches (obligatorio en cada test nuevo):** después de que el test pase,
> **revertir el fix**, correr el harness y confirmar que **ese** test falla. Volver a aplicar
> el fix y confirmar verde. Sin esto, el test no se acepta.

---

## Fase 0 — Verificaciones en vivo

**Objetivo:** responder las incógnitas que bloquean spec/design.
**Entorno:** la tienda del comerciante (o réplica) + la cuenta Alegra real + el repo.
**DoD de la fase:** cada verificación con resultado registrado en
`docs/sdd/config-gates/phase0-results.md`, y cada requerimiento `BLOQUEADO` con su rama elegida.

| Task | Pregunta | Estado | Depende |
|---|---|---|---|
| T0.1 | ¿`options.php` borra un option ausente del POST? | ✅ **RESUELTO** | — |
| T0.2 | ¿Hay consumidores externos de los 14 métodos "muertos" de `Client`? | ⏳ PENDIENTE | — |
| T0.3 | ¿Valor real de `alegra_connector_push_products_enabled` en la tienda? | ⏳ PENDIENTE | — |
| T0.4 | ¿Existen `payment_reconcile_enabled`/`_batch` en la tienda y con qué valor? | ⏳ PENDIENTE | — |
| T0.5 | ¿Alcance exacto de las aserciones de `smoke-load.php` sobre secciones? | ✅ **RESUELTO** (parcial) | — |
| T0.6 | ¿Hay `do_settings_sections()`/`add_settings_field()` ocultos? | ✅ **RESUELTO** | — |
| T0.7 | ¿La tienda depende de pushes manuales con el plugin desconectado? | ⏳ PENDIENTE | — |

### T0.1 — ¿`options.php` borra un option ausente del POST? ✅ RESUELTO

- **Resultado:** **SÍ.** `wp-admin/options.php` (verificado en 5.8 y 6.7) recorre la whitelist
  del `option_page` y llama `update_option($option, $value)` **incondicionalmente**; si el campo
  no está en `$_POST`, `$value = null`, y `update_option()` aplica el filtro
  `sanitize_option_{$option}` (que `register_setting` engancha al `sanitize_callback`).
  El callback actual devuelve `[]` para `null` ⇒ **borra** el mapping.
- **Consecuencia:** REQ-CFG-3 deja de ser incógnita. El fix de doble defensa (§3.3 del design)
  es correcto y necesario.
- **DoD:** fuente de `options.php` citada + rama fijada. ✔

### T0.2 — ¿Consumidores externos de los 14 métodos "muertos"? ⏳ PENDIENTE · BLOQUEA T5.2

- **Pasos:**
  1. `grep -rn "delete_item_category\|void_credit_note\|update_credit_note\|delete_credit_note\|update_payment\|delete_payment\|void_payment\|open_payment\|create_price_list\|update_price_list\|delete_price_list\|create_inventory_adjustment\|create_estimate\|update_invoice_retentions"` sobre el repo (ya hecho: **0 llamadores**).
  2. Buscar en el ecosistema distribuido: GitHub code search, Packagist, foros, snippets
     públicos, y el changelog/`README` que documenten `API\Client` como API pública.
  3. Revisar `docs/` y `CHANGELOG.md` por menciones de extensibilidad.
- **Ramas:**
  - **Rama A (0 consumidores externos):** borrar los 14 métodos + los 3 `sync_all` (T5.2/T5.3).
  - **Rama B (hay consumidores o no se puede confirmar):** conservar con
    `@deprecated 2.4.0` y una nota; borrar sólo `sync_all` y las opciones muertas.
- **DoD:** resultado + rama elegida, registrado.

### T0.3 — Valor real de `push_products_enabled` ⏳ PENDIENTE · BLOQUEA T2.4

- **Pasos:** `wp option get alegra_connector_push_products_enabled` en la tienda (o leer la UI
  del tab Sincronización).
- **Ramas (migración de `push_customers_enabled`):**
  - **Rama A (`true`):** sembrar `push_customers_enabled = true` (preserva el push de clientes).
  - **Rama B (`false` o ausente):** sembrar `false`.
- **DoD:** valor crudo + rama elegida.

### T0.4 — Existencia y valor de `payment_reconcile_*` ⏳ PENDIENTE · BLOQUEA T2.2

- **Pasos:** `wp option get alegra_connector_payment_reconcile_enabled` y `..._batch`.
- **Ramas (default de `payment_reconcile_enabled`):**
  - **Rama A (no existe):** default `true` (preserva el barrido; sin regresión). **Recomendada.**
  - **Rama B (existe con valor):** migrar respetando el valor existente.
- **DoD:** valores crudos + rama elegida.

### T0.5 — Alcance de las aserciones de `smoke-load.php` ✅ RESUELTO (parcial) · BLOQUEA T5.5

- **Resultado:** `scripts/smoke-load.php:575-576` asserta que el fuente de `Admin_Dashboard.php`
  contiene `add_settings_section('alegra_connector_billing_section'`. Es la **única** aserción
  de secciones encontrada. Al remover las 6 secciones hay que actualizar esta aserción.
- **Rama:** la decisión de **eliminar** las secciones (design §5.5) exige editar `smoke-load.php`.
  La rama alternativa (llamar `do_settings_sections()`) no se adopta: no hay `add_settings_field`.
- **DoD:** lista de líneas a tocar + decisión. ✔

### T0.6 — ¿`do_settings_sections()`/`add_settings_field()` ocultos? ✅ RESUELTO

- **Resultado:** **0** llamadas a `do_settings_sections` y **0** `add_settings_field` en
  producción. Las 6 `add_settings_section()` (`Admin_Dashboard.php:566-571`) son decorativas.
- **DoD:** conteo 0/0. ✔

### T0.7 — ¿La tienda depende de pushes manuales estando desconectada? ⏳ PENDIENTE

- **Pasos:** preguntar al comerciante / revisar el log si hay bloqueos de kill switch en el
  historial; ver si usó "Facturar" con el plugin desconectado.
- **Ramas:**
  - **Rama A (no depende):** release normal.
  - **Rama B (depende):** agregar a la nota de release una guía de "cómo limpiar el kill switch
    antes de facturar manualmente"; no se agrega bypass (REQ-ENF-1 lo prohíbe).
- **DoD:** respuesta + plan de comunicación.

---

## Fase 1 — Enforcement central (núcleo, mayor valor)

**Objetivo:** kill switch y habilitación por entidad en el choke point.
**Depende de:** nada (Fase 0.1 ya resuelta).
**DoD de la fase:** REQ-ENF-1, REQ-ENF-2, REQ-ENF-3, REQ-COMP-1 verdes; harness completo verde.

### T1.1 — Crear `includes/Write_Gate.php` `[ ]`

- **Archivos:** `includes/Write_Gate.php` (nuevo).
- **Contenido:** clase `Alegra\Connector\Write_Gate` con `ENTITY_OPTIONS`, `ENTITY_DEFAULTS`,
  `entity_for()`, `block_reason()`, `begin_explicit()`, `end_explicit()`, `is_explicit()`,
  `run_explicit()`, `maybe_migrate()` (este último se completa en T2.3). `declare(strict_types=1)`.
- **DoD:** la clase carga por el autoloader PSR-4 existente; `Write_Gate::entity_for('POST','/invoices') === 'invoice'`.

### T1.2 — Insertar la puerta en `Client::request()` `[ ]`

- **Archivos:** `includes/API/Client.php:103-111`.
- **Cambio:** dry-run primero, puerta después (design §2.1). Agregar
  `is_gate_blocked_response()` y `write_was_blocked()` (design §2.4).
- **Tests (nuevos en `scripts/exec-test.php`):**
  - `TestRunner::assertSame(['dry_run'=>true,'blocked'=>'POST /invoices'], $r, 'dry-run marker unchanged')` con `dry_run=true`.
  - `TestRunner::assertTrue(Client::is_gate_blocked_response($r), 'kill switch returns gate marker')` con kill switch activo.
  - `TestRunner::assertSame(0, alegra_mock_count('POST','/invoices'), 'no HTTP when gate blocks')`.
  - `TestRunner::assertSame(1, alegra_mock_count('GET','/invoices'), 'GET passes the gate')` con kill switch activo.
- **Prove-it-catches:** quitar el bloque de la puerta → los tests de `is_gate_blocked_response` fallan.

### T1.3 — Envolver los puntos explícitos en `run_explicit()` `[ ]`

- **Archivos:** `Admin_Dashboard.php` (`ajax_sync_single:2273`, `ajax_bulk_sync:2399`,
  `ajax_sync_pending_orders:2765`, `ajax_sync_pending_page:2825`, `ajax_record_payment:2170`,
  `ajax_open_invoice:2126`, `ajax_register_webhooks:2537`, `ajax_delete_webhooks:2710`,
  `ajax_disconnect:2897`) y `public/Public/Public_.php` (`handle_sync_request`).
- **Cambio:** envolver el cuerpo en `\Alegra\Connector\Write_Gate::run_explicit(fn() => ...)`.
- **DoD:** con `push_orders_enabled=false` y kill switch inactivo, "Facturar" escribe (explícito);
  un hook automático no.

### T1.4 — Migrar los call sites de dry-run a `write_was_blocked()` `[ ]`

- **Archivos:** `Orders.php:416,808,1136`; `Products.php:174,246,263,2425,2486`;
  `Customers.php:37,51,74,618`; `Categories.php:40,46`; `Orders.php:113,589,854,2001`;
  `Admin_Dashboard.php:2237,2565,2733`; `State_Sync.php:157,305`; `Consumidor_Final.php:217`.
- **Cambio:** donde hoy se evita persistir por dry-run, usar `Client::write_was_blocked($r)`;
  donde no había guard, agregarlo antes de tocar metas/estado.
- **DoD:** ningún camino persiste `_alegra_*` con un resultado bloqueado.

### T1.5 — Tests de enforcement por camino `[ ]`

- **Archivos:** `scripts/exec-test.php`.
- **Tests:** los 6 escenarios de REQ-ENF-1 (AJAX, hook pago, refund, REST, render, negativo)
  + los de REQ-ENF-2 (invoice/contact/payment, automático vs explícito).
- **Prove-it-catches:** revertir T1.2 → los 5 escenarios de bloqueo fallan (el mock cuenta >0).

**DoD Fase 1:** `bash scripts/exec-test.sh` verde con ≥ 20 aserciones nuevas; REQ-ENF-1/2/3,
REQ-COMP-1 verificados.

---

## Fase 2 — Honestidad de configuración + migración

**Depende de:** Fase 0.3 (T0.3), Fase 0.4 (T0.4).
**DoD de la fase:** REQ-CFG-1, REQ-CFG-2, REQ-CFG-3, REQ-CFG-4 verdes.

### T2.1 — Registrar opciones nuevas `[ ]`

- **Archivos:** `Admin_Dashboard.php:380-564` (register_settings), `alegra-connector.php:403-439`
  (`$defaults`, `$non_autoload`).
- **Cambio:** `payment_reconcile_enabled`, `payment_reconcile_batch`, `push_customers_enabled`
  (design §3.1). Agregar al `$defaults` (la siembra de activación `:441-445` las cubre).
- **Test:** `TestRunner::assertTrue(function_exists('register_setting'), ...)` no sirve;
  usar el patrón de source-scan: el fuente de `Admin_Dashboard.php` contiene
  `'alegra_connector_payment_reconcile_enabled'` dentro de `register_setting`.
- **Prove-it-catches:** quitar una registración → la aserción de fuente falla.

### T2.2 — Default y UI de reconciliación `[ ]` · BLOQUEADO(Fase 0.4)

- **Archivos:** `templates/admin-settings.php` (tab Avanzado).
- **Cambio:** checkbox + number con los defaults de la rama elegida (A: `true`/`20`).
- **Tests:** `alegra_connector_payment_reconcile_batch = 0` → `get_option(...) === 1`;
  `999` → `100`; el sweep con flag `false` no postea pagos (REQ-CFG-1).

### T2.3 — Implementar `Write_Gate::maybe_migrate()` `[ ]` · BLOQUEADO(Fase 0.3/0.4)

- **Archivos:** `includes/Write_Gate.php`, `alegra-connector.php` (hook `plugins_loaded` +
  `activate()`).
- **Cambio:** design §3.4, con la rama de T0.3/T0.4.
- **Tests:**
  - Instalación sin las 4 `sync_*` → tras `maybe_migrate()`, las 4 son `false`.
  - Instalación con `sync_products=true` → sigue `true` (no se pisa).
  - Instalación con `push_products_enabled=true` → `push_customers_enabled === true` (rama A).
  - Idempotencia: llamar `maybe_migrate()` dos veces no cambia nada la segunda vez.
- **Prove-it-catches:** quitar el early-return de versión → el test de idempotencia falla.

### T2.4 — Toggle independiente de clientes `[ ]` · BLOQUEADO(Fase 0.3)

- **Archivos:** `public/Public/Public_.php:72-78`, `templates/admin-settings.php:99-102`.
- **Cambio:** separar el `if` de productos del de clientes; el de clientes usa
  `get_option('alegra_connector_push_customers_enabled', false)`. Agregar el checkbox.
- **Tests:** los 3 escenarios de REQ-CFG-4.
- **Prove-it-catches:** dejar el `if` compartido → "productos on, clientes off → contacto 0" falla.

### T2.5 — Fix de los cuatro `sync_*` en la UI `[ ]`

- **Archivos:** `templates/admin-settings.php:78-81`.
- **Cambio:** `true` → `false` en el default del `checked()` (design §3.2).
- **Test (source-scan):** el fuente no contiene
  `checked(get_option('alegra_connector_sync_products',true))` (ni las otras 3).
- **Prove-it-catches:** revertir una línea → la aserción de fuente falla.

### T2.6 — Fix del doble registro de mappings `[ ]`

- **Archivos:** `Admin_Dashboard.php:454-463` (borrar), `:563-564` (reemplazar).
- **Cambio:** design §3.3 (quitar del grupo Settings + callback que preserva ante `null`).
- **Tests:**
  - El callback con `$value = null` devuelve el mapping existente (no `[]`).
  - El callback con un array nuevo lo sanitiza.
  - Source-scan: exactamente una registración por opción de mapping.
- **Prove-it-catches:** volver a poner la registración en `alegra_connector_settings` → el
  test de "una sola registración" falla.

**DoD Fase 2:** REQ-CFG-1..4 verdes; migración idempotente; `smoke-test.sh` verde.

---

## Fase 3 — Gates de reconciliación y refunds

**Depende de:** Fase 1 (puerta) y Fase 2 (opción `payment_reconcile_enabled`).
**DoD de la fase:** REQ-CFG-1 (camino tiempo real), REQ-ENF-2 (refunds) verdes.

### T3.1 — Gate del flag en los hooks en tiempo real `[ ]`

- **Archivos:** `public/Public/Public_.php:278-314` (`on_order_paid_reconcile`).
- **Cambio:** al inicio, `if (!get_option('alegra_connector_payment_reconcile_enabled', true)) return;`
  y `if (Kill_Switch::is_active()) return;` (fast-path).
- **Tests:** REQ-CFG-1 escenarios 3 y 4 (flag off → 0 pagos; flag on → 1 pago).
- **Prove-it-catches:** quitar el flag-guard → "flag off → 0" falla.

### T3.2 — Fast-path en `State_Sync` `[ ]`

- **Archivos:** `includes/State_Sync.php:72` (`handle_refund`), `:236` (`handle_payment_method_change`).
- **Cambio:** `if (Kill_Switch::is_active()) return;` al inicio de cada uno (la escritura igual
  la decide la puerta).
- **Tests:** REQ-ENF-1 escenario refund (kill switch → 0 `POST /credit-notes`).
- **Prove-it-catches:** quitar el fast-path no hace fallar el test (la puerta igual bloquea);
  **el test válido es el de la puerta** (T1.2). Documentar que este fast-path es optimización.

### T3.3 — Test de refund automático con `push_orders_enabled=false` `[ ]`

- **Test:** hook `woocommerce_order_refunded` con `push_orders_enabled=false` y kill switch
  inactivo → `alegra_mock_count('POST','/credit-notes') === 0` (bloqueo por entidad).
- **Prove-it-catches:** desactivar el gate de entidad → el mock cuenta 1 y falla.

**DoD Fase 3:** REQ-CFG-1 completo (ambos caminos) y el gate de `credit_note` verificados.

---

## Fase 4 — Robustez

**Depende de:** Fase 1 (para el contexto explícito de T4.3).
**DoD de la fase:** REQ-RB-1, REQ-RB-2, REQ-RB-3 verdes.

### T4.1 — Separar `peek_id()` de `get_or_create_id()` y guardar `create()` `[ ]`

- **Archivos:** `includes/Consumidor_Final.php:42,87,212`; `templates/admin-dashboard.php:18`.
- **Cambio:** design §4.1. El template usa `is_configured()`; `create()` exige `Write_Gate::is_explicit()`.
- **Tests:** los 4 escenarios de REQ-RB-1.
- **Prove-it-catches:** volver a llamar `is_available()` en el template → "render → 0 POST /contacts" falla.

### T4.2 — Cancelación por lote e importadores `[ ]`

- **Archivos:** `Admin_Dashboard.php:1832,2825`; `Sync/Products.php:1419`; `Sync/Customers.php:328`.
- **Cambio:** design §4.2.
- **Tests:** los 4 escenarios de REQ-RB-2.
- **Prove-it-catches:** quitar el chequeo de cancelación en `ajax_sync_page` → el test de
  "cancelado en el próximo lote" falla (procesa el lote igual).

### T4.3 — `ajax_disconnect` honesto `[ ]`

- **Archivos:** `Admin_Dashboard.php:2897-2955`.
- **Cambio:** design §4.3 (borrar webhooks antes del kill switch, contar por resultado real).
- **Tests:** los 3 escenarios de REQ-RB-3.
- **Prove-it-catches:** volver a activar el kill switch primero → el test de "dry-run no miente"
  / "borra 2" falla (la puerta bloquea y el contador miente).

### T4.4 — Defensa del webhook `[ ]`

- **Archivos:** `includes/Webhooks/Receiver.php:41-70`.
- **Cambio:** si el kill switch está activo, ACK 200 sin procesar (design §4.4).
- **Tests:** webhook con kill switch activo → 200 y 0 mutaciones locales.
- **Prove-it-catches:** quitar la guarda → el test de "no procesa" falla.

**DoD Fase 4:** REQ-RB-1/2/3 verdes.

---

## Fase 5 — Higiene

**Depende de:** Fase 0.2 (T0.2, borrado de métodos), Fase 0.5 (T0.5, secciones).
**DoD de la fase:** REQ-HYG-1, REQ-HYG-2 verdes; harness verde.

### T5.1 — Borrar las escrituras de opciones muertas `[ ]`

- **Archivos:** `Admin_Dashboard.php:1599,1602,2943`.
- **Cambio:** design §5.1. Conservar los `delete_option` en `uninstall.php:87,88,112`.
- **Test (source-scan):** el fuente no contiene `update_option('alegra_connector_items_count'`
  (ni `contacts_count`, ni `disconnected_at`).
- **Prove-it-catches:** reponer una línea → la aserción de fuente falla.

### T5.2 — Borrar los 14 métodos muertos de `Client` `[ ]` · BLOQUEADO(Fase 0.2)

- **Archivos:** `includes/API/Client.php`.
- **Cambio:** design §5.2, según la rama de T0.2.
- **Test (source-scan):** el fuente no contiene `function delete_item_category` … (los 14).
- **Prove-it-catches:** reponer uno → la aserción de fuente falla.
- **Rama B:** no borrar; marcar `@deprecated` y testear que siguen existiendo.

### T5.3 — Borrar los `sync_all()` muertos `[ ]`

- **Archivos:** `Sync/Products.php:1225`, `Sync/Customers.php:172`, `Sync/Categories.php:60`.
- **Test (source-scan):** los tres fuentes no contienen `function sync_all`.
- **Prove-it-catches:** reponer uno → la aserción falla.

### T5.4 — Completar `uninstall.php` `[ ]`

- **Archivos:** `uninstall.php:48-123`.
- **Cambio:** design §5.4.
- **Test (source-scan):** contiene los 4 `delete_option` nuevos.
- **Prove-it-catches:** quitar uno → la aserción falla.

### T5.5 — Resolver las secciones decorativas `[ ]` · BLOQUEADO(Fase 0.5)

- **Archivos:** `Admin_Dashboard.php:566-571`, `scripts/smoke-load.php:575-576`.
- **Cambio:** design §5.5 (eliminar las 6 secciones + actualizar la aserción de smoke).
- **Test:** `bash scripts/smoke-test.sh` verde tras el cambio.
- **Prove-it-catches:** dejar la aserción vieja → smoke falla.

**DoD Fase 5:** REQ-HYG-1/2 verdes; `exec-test.sh` y `smoke-test.sh` verdes.

---

## Fase 6 — Regresión y release

**Depende de:** Fases 1-5.
**DoD de la fase:** harness completo verde, migración probada en una instalación real, release publicado.

### T6.1 — Correr el harness completo `[ ]`

- `bash scripts/exec-test.sh` (768 aserciones base + nuevas) y `bash scripts/smoke-test.sh`.
- **DoD:** `EXEC-TEST OK` y `SMOKE OK`.

### T6.2 — Prueba de migración en instalación real `[ ]`

- Clonar la tienda, actualizar a 2.4.0, verificar:
  - La UI de `sync_*` coincide con el runtime.
  - `payment_reconcile_enabled` aparece con el valor de la rama T0.4.
  - `push_customers_enabled` coincide con la rama T0.3.
  - Guardar Settings **no** borra el mapping.
- **DoD:** captura de la UI + valores crudos.

### T6.3 — Prueba manual del kill switch `[ ]`

- Desconectar; intentar facturar, registrar pago, refund, registrar webhooks, render del
  dashboard; confirmar que **todo** devuelve mensaje de bloqueo y Alegra no registra nada.
- Limpiar el kill switch; confirmar que vuelve a funcionar.
- **DoD:** checklist firmado.

### T6.4 — Nota de release y CHANGELOG `[ ]`

- Documentar el cambio de comportamiento (kill switch real; gate por entidad; refunds
  automáticos bloqueados en modo manual).
- Actualizar `README.md`/`DOCUMENTACION.md` con las opciones nuevas.
- **DoD:** `CHANGELOG.md` + `docs/RELEASE_2.4.0_DEPLOY.md`.

### T6.5 — Actualizar `alegra_connector_version` y bump `2.4.0` `[ ]`

- **Archivos:** `alegra-connector.php:6,29`.
- **DoD:** versión consistente; `uninstall.php` borra la versión.

**DoD Fase 6:** release 2.4.0 publicado y verificado.

---

## Orden recomendado

```
Fase 0 (0.2/0.3/0.4/0.7)  ──┐
                            ├──► Fase 2 (2.2/2.3/2.4 según ramas)
Fase 1 (enforcement)  ──────┼──► Fase 3 ──► Fase 4 ──► Fase 5 ──► Fase 6
                            └──► Fase 5 (5.2/5.5 según ramas)
```

- **Fase 1 se puede empezar ya** (no depende de Fase 0).
- **Fase 2 depende de 0.3 y 0.4** para los defaults de migración.
- **Fase 5 depende de 0.2 y 0.5** para borrado de métodos y secciones.
- **Fases 3 y 4** dependen de 1 (y 2 para la opción de reconciliación).

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-ENF-1 | T1.1, T1.2, T1.3, T1.5, T3.2, T4.4 |
| REQ-ENF-2 | T1.1, T1.3, T1.4, T1.5, T3.3 |
| REQ-ENF-3 | T1.2, T1.5 |
| REQ-CFG-1 | T2.1, T2.2, T3.1 |
| REQ-CFG-2 | T2.3, T2.5 |
| REQ-CFG-3 | T2.6 |
| REQ-CFG-4 | T2.3, T2.4 |
| REQ-CFG-5 | T2.1 (handler en Fase 2), T6.3 |
| REQ-RB-1 | T4.1 |
| REQ-RB-2 | T4.2 |
| REQ-RB-3 | T4.3 |
| REQ-HYG-1 | T5.1, T5.2, T5.3, T5.4 |
| REQ-HYG-2 | T5.5 |
| REQ-COMP-1 | T1.2, T1.5 |
