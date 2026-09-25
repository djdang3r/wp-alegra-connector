# REVIEW — Auditoría técnica final de ejecución (`logs-monitor-import`)

> **Tipo:** auditoría read-only del plan **antes de ejecutar**. No se modificó ningún artefacto del plan ni código de producto.
> **Fecha:** 2026-09-25
> **Método:** lectura íntegra de `proposal.md`, `spec.md`, `design.md`, `tasks.md` y los 8 `tasks/fase-*.md`; luego contraste de cada cita `archivo:línea` contra el código real en HEAD; luego trace end-to-end del síntoma del comerciante a través del **código PLANEADO**.
> **Código real verificado:** `admin/Admin/Admin_Dashboard.php`, `includes/Sync/Products.php`, `includes/Sync/Controller.php`, `includes/Runs.php`, `includes/Heartbeat.php`, `includes/Tombstone_Manager.php`, `logger/Logger/Logger.php`, `admin/assets/js/admin.js`, `templates/*.php`, `scripts/lib/wp-stubs.php`, `scripts/lib/test-framework.php`, `alegra-connector.php`, `uninstall.php`.
> **Regla de honestidad:** lo no verificable se marca **UNVERIFIED** con el check exacto.

---

## VERDICT

**SOUND-WITH-FIXES.**

El plan diagnostica correctamente las causas raíz del síntoma del comerciante y las 7 etapas del trace están cerradas por el código planeado. La arquitectura (`Run_Context`, release-antes-del-send, presupuesto propio del chunked, política de tombstones) es coherente y ejecutable.

Sin embargo **NO es execution-ready tal cual**: hay **2 blockers** (1 regresión real de producto y 1 defecto que rompe la verificación de la Fase 6) y **3 defectos adicionales** que hay que cerrar antes de repartir el trabajo. Ninguno exige rediseño: son fixes puntuales.

| Blocker | Qué rompe | Fix |
|---|---|---|
| **B-A** | El cierre del chunked borra el cursor de productos para **cualquier** entidad (customers/categories) | Gatear `delete_option(cursor)` en `$type === 'products'` |
| **B-B** | Los tests `T28.62` y `T28.612` leen `$resp->data`, que no existe ⇒ fallan | Usar `$resp->payload` |

---

## THE 7-STEP SYMPTOM TRACE (código PLANEADO, no el actual)

> Síntoma textual: *"eliminé todos los productos, le di traer desde Alegra y en breves segundos se cerró el proceso sin decir nada… recargué y no aparecían productos; al minuto aparecieron algunos, no todos, y sin imágenes."*

### 1. Borrar todos los productos → ¿la reimportación funciona con los tombstones? → **CLOSED**

- **Diagnóstico actual (real):** `alegra-connector.php:214` (`before_delete_post`) → `Tombstone_Manager::on_post_delete` escribe `'reason' => 'manual_wc'` hardcodeado (`includes/Tombstone_Manager.php:54`); `import_single_item_from_alegra` saltea la creación si `exists()` (`includes/Sync/Products.php:1429`). El workflow "borrar todo + reimportar" queda bloqueado.
- **Plan que lo cierra:**
  - `T4.3b` (`tasks/fase-4-botones-tombstones.md:452-674`) clasifica `bulk_wc` (incluye el fix D5: `isset($_REQUEST['delete_all'])` / `delete_all2` + `action2`, `fase-4:489-514`).
  - `T4.1` (`fase-4:146-158`) agrega el botón **"Reimportar todo desde cero"** con `data-from-zero="1"`; `T4.5` (`fase-4:865-912`) pide confirmación y cablea el checkbox (default **tildado**).
  - `T2.3` (`fase-2:640-762`) deriva `$policy = $recreate_manual ? 'ignore_all' : 'ignore_bulk'` y lo persiste en `$state['policy']`.
  - `T4.4` (`fase-4:706-746`) setea la política en `ajax_sync_page` y `tombstone_policy_allows()` deja recrear `bulk_wc`/`manual_wc`; `alegra_deleted` **nunca** se resucita.
  - Los tombstones **preexistentes** (creados antes del upgrade con `manual_wc`) también se recrean porque el default del checkbox es `ignore_all`.
- **Caveat honesto:** el botón viejo **"Traer desde Alegra"** sigue respetando tombstones (`policy='respect'`), así que **repetir exactamente el mismo clic del comerciante NO reimporta**. El plan cierra el workflow por el **botón nuevo** + el badge de cursor (`T4.1`, `fase-4:128-143`). La distinción es visible (dos botones, uno `ac-btn-danger`, confirm destructivo), pero depende de que el comerciante use el botón correcto. Es la decisión explícita del design (`design.md:704-713`).

### 2. Clic "Traer desde Alegra" → ¿el JS muestra mensaje en TODA rama de fallo? → **CLOSED**

- **Ramas mudas actuales:** `admin.js:220` (start `!r.success`), `:238` (página `!r.success`), `:278` (terminal tras reintentos) — todas hacen `cleanup()` sin `showNotice`.
- **Plan que lo cierra:** `T3.3.a` (`fase-3:465-479`), `T3.3.b` (`fase-3:500-531`), `T3.3.c` (`fase-3:552-563`) separan `if (cancelled)` de `if (!r.success)` y llaman `showNotice(safeMsg(r, …))`; la rama terminal agrega `showNotice(S.connectionError)`. El `error:` del start (`admin.js:226`) y del `doAllSync` ya avisaban.
- `showNotice` inyecta en `.alegra-connector-wrap` (`admin.js:25-33`), fuera del modal, así que sobrevive al `cleanup()` (`admin.js:184-188`). Correcto.

### 3. "Se cerró en segundos sin decir nada" → ¿se elimina el cierre silencioso? → **CLOSED**

Cadena de causa raíz y su fix, rama por rama:
- **Lock filtrado por `die()`:** `wp_send_json_*` → `wp_die()` → `die()` no ejecuta `finally`; el lock de `ajax_sync_page` se libera sólo en `finally` (`Admin_Dashboard.php:2222-2224`) en **4** sends (3 `error` + el `success` de `:2216`). La página 1 responde OK y deja el lock; la página 2 recibe `false` → `wp_send_json_error` (`:2101`) → el JS lo tragaba en `:238`. **Cerrado por `T2.4`** (`fase-2:1010-1137`): cierre del run + `release_sync_lock_public` **antes de cada** `wp_send_json_*` (cancel `:1030`, lock ocupado `:1049`, WP_Error `:1097`, éxito `:1118`), con `finally` sólo como red de excepciones.
- **Fatal 500 por 60 s:** `@set_time_limit(60)` (`Admin_Dashboard.php:2105`) + imágenes con `download_url($url, 15)` + thumbnails revientan los 60 s. **Cerrado por `T3.1.a/b`** (`fase-3:59-158`): presupuesto propio `alegra_connector_chunked_page_budget` (default 20 s) con corte antes de cada ítem y reanudación por `start`/`offset`.
- **Ramas JS terminales:** cerradas por `T3.3.c`.
- **Ramas residuales revisadas:** cancelación (el botón Cancelar ya avisa, `admin.js:174`); `d.paused` (no cierra, reanuda); HTTP 500 (cae en `error:` → `showNotice`). No encontré una rama de cierre mudo que sobreviva al plan.

### 4. "No aparecieron productos" → ¿la puerta que lo bloqueó ahora queda logueada y surfaceada? → **CLOSED (con defecto D-C)**

- **Puerta:** el guard de tombstone (`Products.php:1429`) devolvía `'skipped'` sin decir por qué.
- **Plan:** `T4.4` (`fase-4:770-784`) reemplaza por `exists_with_reason()` + política y loguea `reason` + `policy`; el contador de `skipped` viaja al payload (`T3.4`, `fase-3:653-675`) y el Monitor muestra el run (`T2.4`). El cursor/badge de `T4.1` explica el punto de reanudación.
- **Defecto D-C:** el loop planeado **no incrementa `$state['skipped']`** cuando el importador devuelve `'skipped'` (`fase-3:144`: `elseif ($r === 'skipped') { continue; }`), así que los productos saltados por tombstone/variante **no se cuentan** ni se ven en el resumen. Ver REMAINING DEFECTS.

### 5. "~1 minuto después aparecieron algunos" (el cron) → ¿se hace visible el cron (Run + heartbeat)? → **CLOSED**

- **Actual:** el cron ya crea fila vía `Runs::track` (`Controller.php:150`) y setea `Heartbeat` por etapa (`:173,212,240,266,277`), pero el Monitor no distingue el origen y `sync_products=false` saltea productos en silencio.
- **Plan:** `T2.1` (`fase-2:249-275`) cambia `Runs::track` por `Run_Context::wrap('cron_sync_all', …)` (misma semántica: start → fn → completed/failed+rethrow) y agrega el `else` que loguea `products skipped by configuration` + `$result['products_skipped']='config'`. `T6.4.a` (`fase-6:462-490`) agrega `runTypeLabel()` (Cron/Manual/Chunked/Webhook) y el badge `stale`; `T6.4.b` (`fase-6:515-595`) muestra estado vacío/error honestos y particiona webhooks (D10).
- El run del cron ya era visible en el Monitor; el plan lo etiqueta y lo deja en el historial no-webhook.

### 6. "No eran todos" → ¿se hace visible la pausa/presupuesto con el remanente? → **CLOSED**

- `T3.1.a/b` (`fase-3:59-158`): `$deadline = microtime(true)+$budget`, corte antes de cada ítem, `$state['offset']=$index` al pausar (sin perder ni duplicar).
- `T3.1.c` (`fase-3:185-195`): `$processed` y `percent = processed/total_items` (no `page/tp`), con `$done = !$paused && (…)`.
- `T3.3.b` (`fase-3:516-518`): `d.paused` muestra `S.pausedResuming` y continúa; los contadores `imported|updated|skipped|errors` se pintan.
- `T4.1` (`fase-4:128-143`): badge "Pausado en el ítem X de Y".
- La pausa se reanuda automáticamente en el mismo loop del JS, así que la corrida **termina** (ya no queda "a medias" silenciosamente).

### 7. "Sin imágenes" → ¿se arregla el cap de 60 s y el reporte de fallos? → **CLOSED**

- **Cap:** `T3.2.b` (`fase-3:345-396`) agrega `set_deadline`/`deadline_exhausted` y un guard **antes de cada descarga** en `download_and_attach_image` (después del allowlist) y antes de cada imagen en `import_product_images` (`:2041` favorite, `:2068`, `:2090`). Las imágenes que no entran suman a `deferred` y el producto **igual se importa**; el reimport futuro las reintenta porque `update_product_from_alegra` vuelve a llamar `import_product_images` (`Products.php:1923-1925`) y `download_and_attach_image` deduplica por hash.
- **Reporte:** `T5.2a` (`fase-5:287-307`) incrementa `blocked/download/sideload/ok`; `T5.2b` (`fase-5:365-465`) mergea en `$state['images']` y el JS muestra un `showNotice` warning si `failed > 0` (`fase-3:524-526`).
- **`.jpg` hardcodeado:** `T5.1a` (`fase-5:69-128`) deriva la extensión con `wp_check_filetype_and_ext` + `extension_from_mime`; `T5.1b` (`fase-5:181-265`) borra el método muerto `Admin_Dashboard::import_product_image` (`:108-144`).
- **Allowlist:** `T5.3a/b` (`fase-5:525-727`) opción configurable + sanitizer (rechaza `*`, `/`, `:`, http), condicionada al gate G1.

**Resultado del trace: 7/7 CLOSED.** No hay un paso abierto que bloquee el síntoma del comerciante.

---

## BLOCKERS

### B-A — El cierre del chunked borra el cursor de productos para TODAS las entidades (regresión real)
- **Ubicación:** `tasks/fase-2-instrumentacion.md:1110-1113` (bloque `if ($done)` del esqueleto integrado de `ajax_sync_page`).
- **Defecto:** el cierre hace `delete_option('alegra_connector_products_import_cursor')` **sin condicionar por `$type`**. El estado `$state['type']` puede ser `customers` o `categories`. El código actual **no** toca ese option en `ajax_sync_page` (sólo borra `alegra_batch_state`, `Admin_Dashboard.php:2207-2213`).
- **Impacto:** al completar una importación chunked de **clientes** o **categorías**, se borra el punto de reanudación de **productos**. El próximo "Traer desde Alegra" de productos arranca de 0 y re-camina todo el catálogo (silencioso). Viola REQ-RES-01 y contradice "sin regresión".
- **Fix:** mover el `delete_option` dentro del bloque `if ($type === 'products')`, o gatearlo: `if ($type === 'products') { delete_option('alegra_connector_products_import_cursor'); }`. Agregar aserción: correr un chunked de `customers` y verificar que el cursor de products sigue intacto.

### B-B — Los tests de Fase 6 leen `$resp->data` (no existe; el payload es `$resp->payload`)
- **Ubicación:** `tasks/fase-6-ui-logs-monitor.md:192-193` (`T28.62`) y `:611` (`T28.612`).
- **Defecto:** `alegra_capture_json()` devuelve `Alegra_Test_JSON_Response`, que expone `public bool $success` y `public array $payload` (`scripts/lib/test-framework.php:311-318`; `scripts/lib/wp-stubs.php:496-508`). **No existe `$resp->data`.** `$resp->data['files']` devuelve `null` (Warning de propiedad indefinida) ⇒ `assertSame(1, null)` falla.
- **Evidencia de que es un descuido:** los tests existentes usan `$resp->payload` (`scripts/exec-test.php:3520,3539`); y la propia `fase-3` corrigió **este mismo error** en su corrección C9 (`fase-3:33`: "el payload capturado es `$resp->payload`, no `$d`"). Fase 6 no recibió la corrección.
- **Fix:** `$resp->payload['files']`, `$resp->payload['message']` (T28.62) y `$resp->payload['sync_method']` (T28.612).

---

## REMAINING DEFECTS (ranked)

### D-C — NFR-01 (`suma == total`) no se cumple con ítems `skipped` (inconsistencia plan↔NFR, test falla)
- **Ubicación:** loop en `tasks/fase-3-chunked.md:140-145`; `$processed` en `:186`; criterio en `:206-207`.
- **Defecto:** `elseif ($r === 'skipped') { continue; }` **no** incrementa `$state['skipped']`. `import_single_item_from_alegra` devuelve `'skipped'` para variantes (`Products.php:1423-1425`) y para tombstones respetados (`:1436`) — justo el escenario del comerciante. Entonces `imported+updated+skipped+errors < total` y el test de NFR-01 (`fase-3:206`) falla; el resumen subreporta omitidos.
- **Nota:** el código actual tiene el mismo `continue` sin contar (`Admin_Dashboard.php:2141`), así que no es una regresión nueva, pero el plan afirma cerrar NFR-01.
- **Fix:** incrementar `$state['skipped']++` en la rama `'skipped'` (manteniendo el salteo por `offset` **sin** contar, como manda C6), o acotar la aserción de NFR-01 a un dataset sin saltos.

### D-D — `ajax_sync_start` no es atómico: dos arranques concurrentes pueden huérfanar un run
- **Ubicación:** guard D3 en `tasks/fase-2-instrumentacion.md:625-635`; `begin` en `:649`.
- **Defecto:** el guard lee `alegra_batch_state` y luego crea el run, **sin tomar lock**. Dos requests simultáneos (doble clic / dos pestañas) leen "sin estado" a la vez, ambos llaman `Run_Context::begin` y ambos `set_transient`; el segundo pisa el `run_id`/`start` del primero ⇒ un run `running` huérfano hasta `mark_abandoned` (180 s). El plan afirma que D3 cierra el race de dos pestañas, pero sólo cierra el caso **secuencial**.
- **Fix:** tomar el lock per-type (o un lock dedicado `alegra_sync_start`) alrededor de la lectura + `begin` + `set_transient` en `ajax_sync_start`, o rechazar si `Runs::currently_running()` contiene un `chunked_import` vivo. Probabilidad baja, pero el requisito es "sin conflictos".

### D-E — Stop a mitad de página: el run se cierra `cancelled` recién en el siguiente request, y el modal dice "Completado"
- **Ubicación:** `tasks/fase-3-chunked.md:141` (`if ($r === 'stopped') { $paused = true; break; }`); top-of-page stop en `fase-2:1024-1034`; rama `d.done` del JS en `fase-3:519-527`.
- **Defecto:** al devolver `'stopped'`, T3.1.b marca `$paused=true` y persiste el estado; **no** cierra el run. El cierre `cancelled` ocurre en el **siguiente** `ajax_sync_page` (que responde `done:true, cancelled:true`). El JS `d.done` no mira `d.cancelled` y muestra el resumen de "Completado". El Monitor sí queda `Cancelado`.
- **Fix:** en T3.1.b, ante `'stopped'`, llamar `Run_Context::finish($run_id,'cancelled','Detenido por el usuario')` y marcar `$state['cancelled']=true`; o que el JS `d.done` priorice `d.cancelled` para mostrar el aviso de cancelación.

### D-F — Dependencia de build `T6.1.a → T6.2.a` no declarada en el phase file
- **Ubicación:** `tasks/fase-6-ui-logs-monitor.md:161` ("Dependencias: ninguna dura") vs `:170,186,299` que invocan `$logger->get_log_dir()`, método que **no existe en HEAD** y crea `T6.2.a` (`:277`).
- **Estado:** el índice maestro **sí** ordena `T6.2.a` primero (`tasks.md:147-149`), así que es resoluble; pero el phase file se contradice.
- **Fix:** cambiar la línea de dependencias de T6.1.a a "Depende de T6.2.a (`get_log_dir`)" y corregir `fase-6:34`/`tasks.md:347`.

### D-G — `wp_check_filetype_and_ext` / `wp_get_image_mime` no stubeadas (UNVERIFIED, hoy inalcanzable)
- **Ubicación:** `tasks/fase-5-imagenes.md:36` (C8) y `:165-168`.
- **Estado:** `download_url` y `media_handle_sideload` siempre devuelven `WP_Error` (`wp-stubs.php:817-818`), así que el camino mime no se ejecuta y el test no fatalea. **UNVERIFIED** si algún día se stubea `download_url` con éxito: `wp_get_image_mime()` no existe en el harness → fatal.
- **Check exacto:** `grep -n "wp_check_filetype_and_ext\|wp_get_image_mime" scripts/lib/wp-stubs.php` → 0 coincidencias. Si se stubea `download_url`, agregar ambos stubs antes de correr `T28.54`.
- **Riesgo de producción:** `mime_content_type()` (fallback) exige la extensión `fileinfo`. Si está deshabilitada y `wp_get_image_mime()` falla, fatal. Es un borde poco probable; el plan ya lo declara en su riesgo (`fase-5:174-175`).

### D-H — `T1.1a` cambia `Alegra_Mock_Wpdb::update()` de no-op a mutación global (UNVERIFIED, riesgo de regresión de harness)
- **Ubicación:** `tasks/fase-1-cimientos.md:79-98`.
- **Estado:** el `update()` actual es no-op (`wp-stubs.php:1733-1736`) y **muchos** tests pueden depender de eso (p. ej. `mark_resurrected`, entity-map). Convertirlo en merge global es el cambio de harness de mayor superficie del plan. **UNVERIFIED.**
- **Check exacto:** implementar T1.1a y correr `bash scripts/exec-test.sh`; el conteo debe quedar **≥ 1289** y 0 failed. Si baja, acotar el branch a `str_ends_with($table, 'alegra_runs')` en vez de mutar todas las tablas.

---

## WHAT HOLDS (verificado contra HEAD)

- **Diagnóstico del lock:** `wp_send_json_*` → `die()` no ejecuta `finally`; el `try` de `ajax_sync_page` arranca en `Admin_Dashboard.php:2103` y contiene **4** sends (`:2116,2148,2184,2216`), con el `finally` en `:2222-2224`. La regla "cerrar run + release **antes** de cada send" (T2.2/T2.4) es correcta y está aplicada a los 4.
- **Bug "Detener nunca funcionó":** `Heartbeat::clear` borra `alegra_run_stop_{id}` (`Heartbeat.php:70`) y `ajax_kill_run` lo llama tras `request_stop` (`Admin_Dashboard.php:3764-3765`). T1.4/T1.5 lo corrigen. Único llamador de `clear()`: `:3765`.
- **Bug de timezone de `mark_stale`:** `started_at` con `current_time('mysql')` (`Runs.php:63`) vs `gmdate(time())` (`:195`). T1.3b usa `current_time('timestamp')` y agrega el seam `$GLOBALS['alegra_test_gmt_offset']` (`wp-stubs.php:395-398`).
- **Bug de cancel inalcanzable:** `ajax_cancel_sync` borra el state (`Admin_Dashboard.php:3016`); el plan reordena el chequeo de cancel antes de `!$state` y hace que `ajax_cancel_sync` cierre el run (`fase-2:856-876`).
- **`.jpg` hardcodeado y método muerto:** confirmados en `Products.php:2231,2393` y `Admin_Dashboard.php:108-144`.
- **Tombstone hardcodeado `manual_wc`:** confirmado en `Tombstone_Manager.php:54`; `reason` es `VARCHAR(50)` sin enum ⇒ `bulk_wc` entra sin migración.
- **`clear_old_logs` se auto-loguea y recrea el archivo:** `Logger.php:219`; T6.1.a's `clear_all_logs()` correctamente **no** loguea.
- **Harness — seams suficientes:** `T1.1a` modela `wp_alegra_runs` (insert/update/get_var/get_results/query); `H1.b` (observer) captura el estado del lock **antes** del throw del stub (`wp-stubs.php:542-553`), y como las excepciones **sí** corren `finally`, es un prove-it-catch genuino; `H1.c` (`reset_locks_for_testing`), `H1.d` (`is_admin` configurable, hoy `wp-stubs.php:426`), `H2` (tombstone `get_var`), `_n()` (hoy ausente, `wp-stubs.php:324-329`). El autoloader resuelve `Run_Context` por el candidato genérico `includes/` (`alegra-connector.php:128-133`).
- **`import_single_item_public` es retrocompatible:** la firma planeada agrega `int $run_id = 0`; los 11 llamados existentes del harness con 1 argumento siguen válidos (`exec-test.php:2136,2152,…,5300,5306`).
- **`Controller::import_from_alegra($type, $run_id=0)`:** único llamador de producción es `Admin_Dashboard.php:4100`; la firma nueva es retrocompatible.
- **Backward-compat distribuida:** `allowed_image_hosts_extra` vacío ⇒ `['alegra.com']` (sin cambio); `sync_products` queda `false` (Rama A); filas viejas del Monitor caen al `run_type` crudo; `clear_all_logs` cambia semántica **intencionalmente** con confirmación y nota de release.
- **Citas:** las que verifiqué (`Admin_Dashboard.php:2018-2068,2073-2225,2322,3691-3751,3756-3769,4073-4116`; `Products.php:1248,1270,1318-1327,1357-1361,1404-1440,2018-2113,2125-2158,2166-2264,2365`; `Runs.php:46-69,95-118,123-134,141-206`; `Heartbeat.php:66-71`; `Logger.php:112-169,198-222`; `admin.js:124-285`; templates) son exactas o están dentro de ±1 línea.

---

## UNVERIFIED (checks exactos antes de ejecutar)

1. **G1 (host de imágenes), G2 (default de tombstones), G3 (default `sync_products`)** siguen `BLOQUEADO(ON-VERIFICATION)`. Es por diseño (Fase 0 read-only), pero el plan **no se puede cerrar** sin `phase0-results.md`. Check: correr T0.1–T0.3 en la tienda del comerciante.
2. **`T1.1a` no rompe la base de 1289 aserciones.** Check: implementar T1.1a → `bash scripts/exec-test.sh` → esperar ≥1289, 0 failed (ver D-H).
3. **El observer `H1.b` captura el lock en el instante exacto.** Check: implementar H1.b + mover el release al `finally` → `T28.74` debe fallar; con el release antes del send, debe pasar.
4. **`wp_check_filetype_and_ext` en runtime de producción** (no harness). Check: en la tienda, importar un PNG y verificar el nombre del adjunto `.png` (ver D-G).
5. **`classify_delete_reason()` contra el shape real de `$_REQUEST`** de esta tienda. Check: G2/T0.2 (Captura de Network). Si el shape no matchea, el default `ignore_all` igual salva el workflow, pero el heurístico `bulk_wc` queda decorativo.

---

## RECOMENDACIÓN DE CIERRE (orden)

1. **B-A** (cursor por tipo) y **B-B** (`$resp->payload`) — antes de repartir Fase 2 y Fase 6.
2. **D-C** (contar `skipped`) y **D-E** (cerrar `cancelled` en el mismo request) — antes de cerrar Fase 3.
3. **D-F** (dependencia T6.1.a) — corregir el phase file.
4. **D-D** (lock en `ajax_sync_start`) — evaluar si se acepta el race residual o se agrega el lock.
5. Correr Fase 0 y registrar `phase0-results.md`; recién ahí T4.3/T4.4/T5.3/T6.5.

Con B-A y B-B corregidos, el plan queda **execution-ready** para Fase 1.
