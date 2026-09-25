# REVIEW adversarial — `logs-monitor-import` (Momus)

| Campo | Valor |
|---|---|
| Revisor | Momus (plan reviewer adversarial) |
| Fecha | 2026-09-25 |
| Artefactos leídos | `proposal.md`, `spec.md` (27 REQ + 7 NFR, 80 escenarios), `design.md` (967 líneas, D1–D6), `tasks.md` (850 líneas), `tasks/fase-0..7` (5.673 líneas), y el **código real** en HEAD (verificado con `read`/`grep`) |
| Método | Cada cita `archivo:línea` se contrastó contra HEAD. Los dos bugs críticos se trazaron end-to-end. |

---

## VERDICT: **APPROVE-WITH-FIXES**

El plan es **excepcionalmente sólido**: diagnostica correctamente los dos bugs críticos y los arregla
(incluido el camino de **éxito**, que el master `tasks.md` omitía), tiene una cultura de corrección de
citas verificable, y respeta la restricción de plugin distribuido. **No hay que rediseñar nada.**

Pero hay **3 defectos que rompen la implementación tal como está escrita** y varios conflictos
internos entre artefactos. Ninguno es de arquitectura: son correcciones locales, quirúrgicas. Se
arreglan y el plan queda ejecutable.

---

## BLOCKERS (ranked)

### B1 — El modelo de `wp_alegra_runs` del harness está especificado DOS veces, de forma incompatible

- **Artefactos:** `tasks/fase-1-cimientos.md:37-180` (T1.1a) **vs** `tasks/fase-7-regresion-release.md:62-149` (T7.1.a).
- **Defecto:** las dos tareas editan **los mismos métodos** de `scripts/lib/wp-stubs.php`
  (`insert`, `update`, `get_var`, `get_results`) con modelos de datos distintos:
  - T1.1a guarda en `$GLOBALS['alegra_db']['wp_alegra_runs']` (fila por índice).
  - T7.1.a guarda en `$GLOBALS['alegra_runs'][$id]` (mapa por id) y agrega `$GLOBALS['alegra_runs'] = []`
    en `alegra_test_reset()` (`test-framework.php:157`).
  - T1.1a implementa el `query()` UPDATE que necesitan `Runs::mark_stale()` (`Runs.php:197`) y
    `Run_Context`/`mark_abandoned`, y filtra `get_results` por `started_at <`. **T7.1.a NO**.
  - T7.1.a agrega `get_row()` (delega en `get_results`) y `_n()`; T1.1a **no**.
- **Consecuencia:** si el implementador sigue T1.1a, `Runs::status()` de T7.1.a lee un global siempre
  vacío → todos los tests de transición de estado dan `null`. Si sigue T7.1.a, `mark_stale()`/
  `mark_abandoned()` (que usan `$wpdb->query("UPDATE …")`, `Runs.php:197-205`) no marcan nada →
  falla T1.3 y el escenario "un manual viejo sigue running".
- **Fix exacto:** declarar **T1.1a como única fuente** (incluye `query()` y el filtro `started_at`,
  que son imprescindibles), y reducir T7.1.a a: (a) agregar `get_row()` y `_n()` sobre el modelo de
  T1.1a, o (b) borrarla y referenciar T1.1a. Unificar el global elegido (`$GLOBALS['alegra_db']['wp_alegra_runs']`).

### B2 — `Run_Context::fail_early()` llama `Logger::info()` en estático; `Logger::info()` es de instancia → fatal

- **Artefactos:** `design.md:144`, `tasks/fase-1-cimientos.md:274`.
- **Código real:** `logger/Logger/Logger.php:171` → `public function info(string $message, array $context = []): void` (**no** es `static`). El diseño §8 (`design.md:828-834`) **no** agrega un `info` estático.
- **Consecuencia:** `Run_Context::fail_early()` se invoca en **todas** las salidas tempranas que el
  cambio viene a arreglar (lock ocupado, conexión no testeada, `no_batch_state`, `invalid_type`). Con
  PHP 8 → `Error: Non-static method ... cannot be called statically` → **HTTP 500 en lugar de log + JSON
  de error**. Es exactamente el "cierre sin decir nada" que el plan combate.
- **Fix exacto:** en `fail_early`, usar instancia: `(new Logger())->info('Import abortado antes de crear el run', ...)`
  (o inyectar/almacenar un `Logger`). Corregir `design.md:144` y `tasks/fase-1-cimientos.md:274`.
  `set_run_context`/`clear_run_context` **sí** son estáticos nuevos → esas llamadas están bien.

### B3 — Nombre de string de UI contradictorio: `confirmClearAll` vs `confirmClearLogs`

- **Artefactos:**
  - `tasks/fase-3-chunked.md:580` agrega una clave **nueva** `'confirmClearAll'`.
  - `tasks/fase-6-ui-logs-monitor.md:217-224` dice cambiar el **valor de la clave existente** `confirmClearLogs` y "**No** se agregan claves nuevas".
  - `design.md:579` lista `confirmClearAll`; `design.md:415` y `admin.js:386` usan `confirmClearLogs`.
  - `tasks.md:399` lista `confirmClearAll`; `tasks.md:609` usa `confirmClearLogs`.
- **Código real:** la clave existente es `confirmClearLogs` (`Admin_Dashboard.php:705`) y **ya** la
  consume `admin.js:387` (`if(!confirm(S.confirmClearLogs)) return;`).
- **Consecuencia:** fase-3 y fase-6 se contradicen; si se implementa fase-3, el botón sigue leyendo
  `confirmClearLogs` (texto viejo "¿Eliminar logs antiguos?") y `confirmClearAll` queda huérfano.
- **Fix exacto:** estandarizar en **`confirmClearLogs`** (cambiar solo su valor). Eliminar
  `confirmClearAll` de `design.md:579`, `tasks.md:399`, `fase-3:580,586`. fase-6 gana.

---

## CONFLICTS (pares que se contradicen)

1. **`ajax_sync_start`: dos "DESPUÉS" incompatibles del mismo bloque de `$state`.**
   - `tasks/fase-2-instrumentacion.md:602-610` (T2.3): sin `'page'`, con `'images'` y `'from_zero'`/`'policy'`; policy = `respect` / `ignore_bulk` / `ignore_all` **sin** mirar `$type`.
   - `tasks/fase-4-botones-tombstones.md:247-282` (T4.2): **con** `'page' => 0`, **sin** `'images'`, y policy condicionada a `$from_zero && $type === 'products'`.
   - Riesgo: si se copia el bloque de T4.2, `$state['images']` desaparece y T3.4/T5.2 (`merge_image_stats($state['images'], …)`) revientan con "undefined key".
   - **Fix:** declarar T2.3 dueño único del shape; T4.2 solo agrega `policy`/`resuming` al `$state` existente (no re-lista el array completo).

2. **`tasks.md` T2.4 (índice master) contradice la corrección C1/C2 de la propia fase.**
   - `tasks.md:316-317`: "mover el `release_sync_lock_public` de vuelta al `finally` → el test de 'lock libre' falla (el mock de `wp_send_json_error` corta el request como `die()`)".
   - `tasks/fase-2-instrumentacion.md:29` y `:809-811` lo marcan **Falso** (el stub lanza `Alegra_Test_JSON_Response extends \Exception`, `scripts/lib/wp-stubs.php:496,546-549`, y **una excepción SÍ ejecuta `finally`**). Confirmado en HEAD.
   - **Fix:** actualizar `tasks.md:316-317` para referir la costura H1.b (observer) de fase-2, o eliminar el prove-it-catch del índice.

3. **Spec vs design (drift de requerimiento).**
   - REQ-MON-02 (`spec.md:121`) exige limpiar el Heartbeat con `Heartbeat::clear`; el diseño usa `Heartbeat::forget()` (`design.md:135,265,826`). Nombres distintos para la operación de fin.
   - REQ-LOG-05 (`spec.md:381-382`) afirma "no muestra la ruta"; el design lo corrige (`design.md:25`): sí la muestra, relativa y dentro del `if`. La spec **no** se actualizó.
   - REQ-MON-03 (`spec.md:153-154`) afirma "0 coincidencias de `error_summary`"; el design lo corrige (`design.md:26`): sí se renderiza (`admin-monitor.php:175,186`; `Admin_Dashboard.php:3730`). Confirmado en HEAD. La spec **no** se actualizó.
   - REQ-RES-03 (`spec.md:693`) **recomienda Rama B** (preservar manuales); `design.md:650-659` **invierte** el default del checkbox a `ignore_all` (recrear todo). Documentado como decisión explícita, pero spec y design dicen cosas opuestas.
   - REQ-IMG-02 (`spec.md:763-766`) exige que `Products.php` **no contenga `.jpg` hardcodeado**; el fix introduce `$fallback . '.jpg'` como 2º argumento de `wp_check_filetype_and_ext` (`fase-5:101,120`). El escenario estático de la spec es **imposible de cumplir** tal como está redactado; solo vale la versión acotada del task ("en el armado de `file_array`").

---

## COVERAGE GAPS

- **Ningún REQ sin tarea.** Los 27 REQ + 7 NFR están en la matriz de trazabilidad (`tasks.md:815-850`) y cada uno tiene al menos una tarea. Verificado contra la lista real de la spec.
- **Ningún síntoma sin fix.** Los 4 problemas + el smoking gun están cubiertos (matriz `tasks.md:794-801` y validación manual `fase-7 T7.2`).
- **Gap menor:** el escenario de REQ-MON-06 "**kill switch activo** → la sección Cron muestra el motivo" (`spec.md:232-235`) no lo implementa ninguna tarea. T6.4 cubre `sync_method ∉ {cron,both}` (`cronDisabled`) y el error del AJAX, pero no el kill switch (el payload de `ajax_monitor_status` ya manda `kill_switch_active`, `Admin_Dashboard.php:3748`, y el template lo usa para el banner, pero no para la sección cron). Es cosmético; se puede cerrar en T6.4 con una rama más.
- **Gap de test, no de fix:** REQ-IMG-02 en runtime no es verificable en el harness (`wp_check_filetype_and_ext`/`wp_get_image_mime` **no** están stubeados — confirmado: 0 coincidencias; `download_url`/`media_handle_sideload` siempre devuelven `WP_Error`, `wp-stubs.php:817-818`). Queda solo source-scan + test puro. Aceptable, pero hay que declararlo en el reporte de verificación.

---

## RISKS (regresiones sin guarda suficiente)

1. **Heurístico `bulk_wc` ignora `action2`.** `classify_delete_reason()` (`fase-4:493-501`) mira solo
   `$_REQUEST['action']`. El botón "Aplicar" **de abajo** de la lista de WP manda `action=-1&action2=delete`,
   por lo que un borrado masivo se clasificaría `manual_wc`. Mitigación: el default del checkbox es
   `ignore_all` (recrea igual). **Pero** si G2 decide default `ignore_bulk`, el workflow central se rompe
   para ese flujo. La propia `fase-0-gates.md:200` reconoce la forma `action=-1&action2=delete` y T4.3
   **no la contempla**. Agregar: `if ($action2 === 'delete' || $action === 'delete_all') return 'bulk_wc';`
2. **Cursor compartido + `offset` de media página.** Al pausar, `$state['start']` no avanza pero el
   cursor (`alegra_connector_products_import_cursor`) tampoco se actualiza (`fase-3:148-154`). Un cron/manual
   posterior que reanuda desde el cursor re-procesa los `offset` ítems de esa página. `import_single_item_from_alegra`
   es idempotente (update), así que **no duplica productos**, pero sí **doble-cuenta contadores/logs**.
   NFR-01 ("sin duplicados") se sostiene a nivel producto, no a nivel métrica. Documentarlo.
3. **`import_from_alegra` cuenta `'skipped'` como `errors`.** `Products.php:1358-1360`: cualquier retorno
   que no sea `true`/`'updated'` cae en `else $result['errors']++`. El centinela `'skipped'` (kill switch,
   cancel, variante, **tombstone**) se reporta como error. T3.2 agrega el manejo de `'stopped'` pero **no**
   dice de arreglar el miscount de `'skipped'`. Afecta la exactitud de REQ-LOG-02/REQ-RES-04. Agregar
   `elseif ($r === 'skipped') { /* no-op o skipped++ */ }`.
4. **`mark_stale()` sigue con el bug de zona horaria.** `Runs.php:195` usa `gmdate(..., time()-3600)`
   contra `started_at` guardado con `current_time('mysql')` (local). El plan corrige esto **solo** para
   `mark_abandoned` (fase-0 C4 / fase-1 F4). El bug pre-existente de `mark_stale` queda. Está fuera de
   alcance, pero como el plan documenta el problema conviene anotarlo como deuda.
5. **El prove-it-catch del lock release no es tal sin la costura H1.b.** El harness actual no reproduce
   `die()`; sin el observer propuesto (`fase-2:49,463-468`) el test de "lock libre" pasa con el bug. Es
   un cambio de harness que **aún no existe** (0 coincidencias de `probe` en `scripts/`). Está planificado,
   pero es un prerrequisito duro de T2.2/T2.4 y debe ejecutarse antes de declarar verdes esos tests.
6. **`_n()` no está stubeada** (confirmado: 0 coincidencias en `wp-stubs.php`) y el handler de T6.1 la usa
   según design §3.3. fase-6 C-hallazgo y fase-7 T7.1.a lo detectan y lo agregan; solo hay que no olvidarlo.
7. **`wp_alegra_runs` no observable** en el harness (`get_results` → `[]`, `get_var` → `null`,
   `update` no-op). Ya declarado como BLOCKER de infraestructura en `fase-7:62-66`; sin él no se testea
   R1/R9/R11 ni el Monitor con filas. Ver B1 (¡y hay que resolver la duplicación!).

---

## WHAT'S GOOD (fair)

- **Los dos bugs críticos están correctamente diagnosticados y arreglados.**
  - `ajax_kill_run` (`Admin_Dashboard.php:3764-3765`) llama `Runs::request_stop()` y acto seguido
    `Heartbeat::clear()`, que borra `alegra_run_stop_{id}` (`Heartbeat.php:70`). T1.4 (`clear` deja de
    borrarlo) + T1.5 (`kill_run` no toca el heartbeat) lo arreglan. **Verificado en HEAD.**
  - `wp_send_json_*` → `wp_die()` → `die()` **no** corre `finally`; el lock se filtra en **los 4 sends**
    de `ajax_sync_page`, **incluido el éxito** `:2216-2221` (fase-0 C1). T2.4 libera antes de los 4 y T2.2
    hace lo propio en `ajax_import_from_api` (éxito `:4109` y `WP_Error` `:4103`). **Verificado.**
    Este es el diagnóstico real del síntoma "se cerró sin decir nada / al minuto aparecieron algunos".
- **Citas casi perfectas.** De ~120 referencias comprobadas, las correctas incluyen `Products.php:2231/2393`
  (`.jpg`), `:1429` (tombstone), `:2125-2130`/`:2136-2158`/`:2170-2176` (allowlist), `Logger.php:162-168/198-222/211`,
  `Admin_Dashboard.php:2099-2101/2116/2148/2184/2216-2224/2322/2335/3764-3765/4073-4116`,
  `Heartbeat.php:70`, `Runs.php:29-41/46-69/74-88/95-118/123-134/174-206`, `Schema.php:159/176-193`,
  `admin.js:220/238/278/386-393`, `Tombstone_Manager.php:32-59/54/107-122`, `Receiver.php:171-173`.
  El plan incluso corrige citas **de sí mismo** (`design.md:25-30`, `fase-0:31-36`, `fase-5:20-36`).
- **Disciplina de plugin distribuido.** Presupuesto wall-clock propio (no confía en `set_time_limit`),
  sin `exec`/cron real, allowlist configurable por UI sin hardcodear CDN, sanitizer que rechaza `*`, `/`, `:`,
  https obligatorio. El chunked reanuda con `offset` y re-fetch (1 llamada extra por pausa, no por ítem).
- **Contrato fail-loud real.** Separar `if (cancelled)` de `if (!r.success)` (`fase-3 T3.3`) con
  `showNotice` en cada rama, más una aserción estática, es una forma correcta de garantizar REQ-IMP-01.
- **Compatibilidad hacia atrás bien pensada.** Estado retrocompatible `start`/`offset` con default desde
  `page` (C5), `exists()` delega en `exists_with_reason()`, cron con semántica idéntica a `Runs::track`,
  filas viejas caen al label crudo, opciones nuevas leídas con default (sin migración).
- **Autoconciencia del harness.** fase-0 C2, fase-6 (runs no observable, `_n()`), fase-7 C7/C8 identifican
  correctamente los huecos de verificación y proponen costuras concretas. Eso es más de lo que hace el 99%
  de los planes.

---

## Checks exactos para lo no verificado (marcados "unverified")

- **Shape real de `$_REQUEST` del bulk delete de WC** (G2/T0.2) y si el flujo del comerciante fue
  "papelera → vaciar" (`action=delete_all`) o selección masiva (`post[]`). No verificable sin la tienda.
- **Host real del CDN de imágenes** (G1/T0.1): capturar `images[].url` y correr
  `Products::is_allowed_image_url($url)`. No verificable sin la tienda/Alegra.
- **Valor real de `alegra_connector_sync_products`** en la tienda (G3/T0.3). En HEAD el default es `false`
  en los 3 lugares (`alegra-connector.php:424`, `Controller.php:171`, `admin-settings.php:78`), así que la
  Rama A se cumple por código; falta el valor de la instalación del comerciante.
- **`action2`** del bulk delete: reproducir en DevTools → Network y ajustar T4.3 (ver RISK 1).

---

## Resumen accionable

| # | Qué | Dónde | Fix |
|---|---|---|---|
| B1 | Modelo `wp_alegra_runs` duplicado e incompatible | `fase-1:37-180` vs `fase-7:62-149` | T1.1a es la única fuente; T7.1.a solo agrega `get_row()`/`_n()` |
| B2 | `Logger::info()` estático en `fail_early` (fatal) | `design.md:144`, `fase-1:274` | `(new Logger())->info(...)` |
| B3 | `confirmClearAll` vs `confirmClearLogs` | `fase-3:580` vs `fase-6:217-224` | Unificar en `confirmClearLogs` |
| C1 | Shape de `$state` en `ajax_sync_start` | `fase-2:602-610` vs `fase-4:247-282` | T2.3 dueño; T4.2 solo agrega `policy`/`resuming` |
| C2 | `tasks.md:316-317` prove-it-catch falso | `tasks.md` vs `fase-2:29` | Referir H1.b / eliminar del índice |
| C3 | Drift spec↔design (MON-02, LOG-05, MON-03, RES-03, IMG-02) | `spec.md` | Actualizar la spec o anotar la divergencia |
| R1 | `action2` no contemplado | `fase-4:493-501` | Agregar `$action2 === 'delete'` |
| R3 | `'skipped'` cuenta como error | `Products.php:1358-1360` | Rama explícita para `'skipped'` |
