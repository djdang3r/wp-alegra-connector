# REVIEW Oracle — Correctitud técnica del plan `logs-monitor-import`

| Campo | Valor |
|---|---|
| Alcance | ¿El CÓDIGO PLANEADO funciona en WordPress/PHP? (no proceso ni estilo) |
| Artefactos revisados | `design.md` (D1–D6) · `tasks/fase-1..5` |
| Código real verificado | `Admin_Dashboard.php`, `Sync/Controller.php`, `Sync/Products.php`, `Sync/Categories.php`, `Runs.php`, `Heartbeat.php`, `Tombstone_Manager.php`, `Logger.php`, `admin.js`, `Webhooks/Receiver.php`, `Webhooks/Handlers.php`, `Schema.php`, `alegra-connector.php`, `uninstall.php`, templates |
| Método | Lectura del HEAD + cotejo de cada cita `archivo:línea` del plan |

---

## VERDICT: **SOUND-WITH-FIXES**

El plan es ejecutable y los dos bugs headline (stop borrado por `Heartbeat::clear`, lock que no se libera por `die()`) están correctamente diagnosticados y el fix propuesto es válido. Sin embargo, hay **5 defectos que rompen funcionalidad real** (cancel del chunked, `$page` para customers/categories, race de dos pestañas, timezone de `mark_stale`, clasificación `delete_all`) y varios de severidad media. Ninguno exige rediseño: son fixes puntuales.

---

## TECHNICAL DEFECTS (ranked por severidad)

### D1 — CRÍTICO: el botón "Cancelar" mata el batch-state y el run nunca queda `cancelled`
- **Artefacto:** `fase-2 T2.4` (rama cancel de `ajax_sync_page`) vs `Admin_Dashboard.php:3010-3018`.
- **Defecto:** `ajax_cancel_sync()` hace `set_transient('alegra_sync_cancelled', 1, 120)` **y** `delete_transient('alegra_batch_state')` (`:3015-3016`). En el plan, `ajax_sync_page` lee el state **primero** y, si falta, llama `fail_early('chunked','no_batch_state')` y responde error (`T2.4`, primeras líneas). Por lo tanto la rama de cancelación (`get_transient('alegra_sync_cancelled') || should_stop`) **es inalcanzable** cuando el cancel vino del botón Cancelar del JS (`admin.js:167-171`).
- **Por qué rompe:** el run queda `running` hasta que `mark_abandoned` lo pase a `stale` (180 s). El Monitor muestra "Abandonado", nunca "Cancelado" (REQ-MON-05 / REQ-IMP-02). Además hay una carrera: el request en vuelo ya pasó el chequeo de cancel antes de que se setee el transient, así que puede terminar `completed` después de cancelar.
- **Fix exacto:** mover el chequeo de cancelación/stop **antes** del `if (!$state)`, p. ej. leer `$state = get_transient(...)` con `?? []`, chequear cancel/stop primero y recién después `if (!$state) fail_early(...)`. Alternativa mínima: que `ajax_cancel_sync` **no** borre el batch-state (solo setee el flag) y que `Run_Context::finish(cancelled)` ocurra en el próximo `ajax_sync_page`; o que `ajax_cancel_sync` cierre el run y libere el lock él mismo.

### D2 — CRÍTICO: `$page` desaparece del handler y customers/categories pierden paginación
- **Artefacto:** `fase-2 T2.4` (esqueleto de `ajax_sync_page`) y `fase-3 T3.1.c`.
- **Defecto:** el código actual computa `$page = ((int)($state['page'] ?? 0)) + 1;` en `Admin_Dashboard.php:2094`, **antes** del lock, y lo usa para `products` (`:2109`), `customers` (`:2146`), categorías (`:2183`) y para persistir `$state['page'] = $page` (`:2196`). El esqueleto de T2.4 lee `$start`/`$offset`/`$type`/`$run_id` pero **no** asigna `$page`; y T3.1.b sólo reescribe el bloque de **products**. La nota de T2.4 dice que `$page` "se calcula dentro del bloque :2196-2215", pero eso es falso: en `:2196` ya existe; se calcula en `:2094`.
- **Por qué rompe:** con la implementación literal, `$page` es `null` (warning PHP 8) en `:2146`/`:2183` → `($page - 1) * $per_page` = 0 → customers/categorías re-fetchean la página 1 en loop infinito (porque `$state['page']` nunca avanza si se quitó `:2196`, o avanza mal). Además `$state['start']` no se avanza para no-products, así que `$next_start >= $tp * $per_page` en T3.1.c es siempre falso.
- **Fix exacto:** mantener `$page` para customers/categorías: conservar `$page = ((int)($state['page'] ?? 0)) + 1;` y `$state['page'] = $page;` en el camino no-products, y que T3.1.c use `$page`/`$tp` para esos tipos (como hoy). Para products, derivar `$page` de `$state['start']` sólo en el payload.

### D3 — ALTO: race de dos pestañas / state leído antes del lock
- **Artefacto:** `fase-2 T2.4` (`get_transient` antes de `acquire_sync_lock_public`), `fase-2 T2.3` (`ajax_sync_start` sin lock).
- **Defecto:** el state (`alegra_batch_state`) es un transient único y compartido; `ajax_sync_start` **no** toma el lock y sobreescribe el state. Dos pestañas (o doble clic) pueden: (a) sobrescribir el `run_id`/`offset` del otro; (b) leer el mismo state (offset=0) **antes** de serializarse en el lock y ambas procesar la misma página. El `run_id` persistido en el transient agrava el problema: la pestaña A hace `resume` del `run_id` de la pestaña B.
- **Por qué rompe:** doble procesamiento de la misma página (imports idempotentes, pero contadores duplicados y `start` avanzado dos veces), y runs huérfanos. `mark_abandoned` los limpia a los 180 s, pero el conteo/progreso queda corrupto.
- **Fix exacto:** adquirir el lock **antes** de leer/actualizar el state (o al menos antes de procesar), y hacer que `ajax_sync_start` falle con `wp_send_json_error` si ya hay un batch-state activo (o si `Runs::currently_running()` tiene un `chunked_import`). Incluir el `run_id` en el POST de `alegra_sync_page` y rechazar si no coincide con el del transient.

### D4 — ALTO: `mark_stale()` tiene el bug de timezone que el plan sólo corrige en `mark_abandoned`
- **Artefacto:** `Runs.php:195` (`mark_stale`) vs `fase-1 F4`/`T1.3`.
- **Defecto:** `started_at` se guarda con `current_time('mysql')` (hora **local**) en `Runs.php:63`. `mark_stale()` compara contra `gmdate('Y-m-d H:i:s', time() - 3600)` (UTC, `:195`). En un sitio UTC−5 (p. ej. America/Bogota) `started_at` de un run recién arrancado es ~5 h menor que el cutoff → `started_at < cutoff` es **siempre true** → `mark_stale()` marca `stale` cualquier run `running` en el primer poll del Monitor. El F4 corrige `mark_abandoned`, pero `currently_running()` llama `mark_stale()` **primero** (`:179`), así que en UTC− el daño ya está hecho y `mark_abandoned` ni ve la fila (`WHERE status='running'`).
- **Por qué rompe:** "Procesos Activos" queda vacío y el Monitor no refleja los runs chunked/manual/cron en el sitio real del comerciante. Es pre-existente pero cae de lleno en el alcance de REQ-MON-01/02.
- **Fix exacto:** en `mark_stale()` usar `gmdate('Y-m-d H:i:s', current_time('timestamp') - self::STALE_AFTER_SECONDS)` (mismo reloj que `started_at`), igual que el F4 de `mark_abandoned`. Añadir test con offset no-UTC.

### D5 — ALTO: la clasificación `delete_all` del tombstone es incorrecta y el heurístico no es fiable
- **Artefacto:** `fase-4 T4.3b` (`classify_delete_reason`), `Tombstone_Manager.php:32-59`.
- **Defecto:** WP no manda `action=delete_all`; "Empty Trash" es un submit `name="delete_all"` (`$_REQUEST['delete_all']`), con `action` en `-1`. El chequeo `$action === 'delete_all'` **nunca matchea** → el vaciado de papelera cae en `manual_wc`.
- **Por qué rompe:** el flujo "borrar todo → reimportar desde cero" clasifica los tombstones como `manual_wc`; sólo funciona porque el checkbox default es `ignore_all` (recrea todo). El heurístico `bulk_wc` queda casi decorativo.
- **Fix exacto:** `if (isset($_REQUEST['delete_all'])) return 'bulk_wc';` y, para robustez, `$action = $_REQUEST['action2'] ?? $_REQUEST['action'] ?? ''` (WP list-table pone la acción en `action` **o** `action2`). Documentar que REST/WP-CLI/programático → `manual_wc` y que la red de seguridad real es el default `ignore_all`.

### D6 — MEDIO: "Detener" en manual/cron se registra como `completed`, no `cancelled`
- **Artefacto:** `fase-2 T2.1`/`T2.2` + `Products.php:1298,1353`.
- **Defecto:** `Products::import_from_alegra` corta por `should_stop` y devuelve `$result` normal (con `paused=true`). `ajax_import_from_api` (T2.2) entonces llama `Run_Context::finish($run_id, 'completed')`. Sólo el chunked mapea stop→`cancelled` (T2.4). Cron (`Controller::run_cron_sync_inner`) tampoco cierra `cancelled`.
- **Por qué rompe:** el Monitor muestra "Completado" cuando el usuario detuvo el proceso (REQ-MON-05, R9).
- **Fix exacto:** tras `import_from_alegra`, si `!empty($result['paused'])` o `Runs::should_stop($run_id)` → `finish($run_id, 'cancelled', 'Detenido por el usuario')`. Igual en `run_cron_sync_inner`.

### D7 — MEDIO: `ajax_sync_start` no limpia `alegra_sync_cancelled`
- **Artefacto:** `fase-2 T2.3`; `Admin_Dashboard.php:3015`.
- **Defecto:** `ajax_cancel_sync` deja `alegra_sync_cancelled` con TTL 120 s. `ajax_sync_start` no lo borra. Un import nuevo arrancado dentro de los 120 s siguientes es cancelado instantáneamente por la rama de cancel de `ajax_sync_page`.
- **Fix exacto:** `delete_transient('alegra_sync_cancelled')` al inicio de `ajax_sync_start` (pre-existente, pero el plan lo vuelve visible al agregar el cierre `cancelled`).

### D8 — MEDIO: conflicto de fuente para `images` en el payload (T3.4 vs T5.2b)
- **Artefacto:** `fase-3 T3.4` (`'images' => Sync\Products::image_stats()`) vs `fase-5 T5.2b` (`'images' => $state['images']` mergeado).
- **Defecto:** ambas tareas escriben la misma clave del payload de `ajax_sync_page` con fuentes distintas (página vs acumulado del run). Implementadas literalmente, una pisa a la otra; el aviso de `imagesFailed` en el JS (`T3.3.b`) mostraría sólo la última página.
- **Fix exacto:** que T3.4 exponga `$state['images']` (mergeado, con `failed` recalculado) y que `image_stats()` de la página se use sólo para el merge. Eliminar la línea de T3.4 que usa `image_stats()` directo.

### D9 — MEDIO: `get_log_dir()` sobre `new Logger()` devuelve `''`
- **Artefacto:** `fase-3 T3.6` (`(new Logger())->get_log_dir()`), `Logger.php:48-68`.
- **Defecto:** `$log_dir` se setea recién en `ensure_dir()` (`:55`). Una instancia nueva tiene `$log_dir=''`. Si `get_log_dir()` no llama `ensure_dir()`, el template muestra la ruta vacía.
- **Fix exacto:** `public function get_log_dir(): string { $this->ensure_dir(); return $this->log_dir; }` (e `is_writable()` análogo). Test: instancia fresca devuelve la ruta absoluta.

### D10 — MEDIO: los webhooks inundan el Historial del Monitor
- **Artefacto:** `fase-2 T2.5` (`Run_Context::wrap` por evento), `Runs::recent()` (`:141-158`).
- **Defecto:** cada evento de webhook manejado crea una fila `webhook_*`. `recent(15)` ordena por `started_at DESC` y no filtra: en una tienda con volumen, el historial de cron/manual desaparece y sólo se ven webhooks. No hay retención específica.
- **Fix exacto:** excluir `run_type LIKE 'webhook\_%'` de `recent()` (o de la vista) o agregar una sección/limit separada. Decisión de producto, pero el plan no la toma.

### D11 — BAJO: `fail_early` usa `run_type` no canónicos
- **Artefacto:** `fase-1 T1.1b` (`fail_early($origin,...)`) + `fase-2 T2.3` (`fail_early('chunked', ...)`, `'manual'`).
- **Defecto:** el label map de `design §2.7` mapea `chunked_import`/`manual_import`, no `chunked`/`manual`. Las líneas de early-exit quedan con un `run_type` que el Monitor no reconoce.
- **Fix exacto:** pasar `'manual_import'` / `'chunked_import'` (o agregar los alias al mapa).

### D12 — BAJO: `Logger::write()` — la opción de fallo no debe re-escribirse por línea
- **Artefacto:** `design §3.4`, `fase-2`/`T1.2`.
- **Defecto:** "en el camino de éxito, si la opción existe, la borra" debe ser `get_option()` → borrar sólo si existe. Un `delete_option()` incondicional por cada línea escribe la DB en cada log.
- **Fix exacto:** `if (get_option('alegra_connector_logger_write_failed') !== false) { delete_option(...); }`.

### D13 — BAJO: strings duplicadas entre fases
- **Artefacto:** `T3.3.e` y `T4.5` (`confirmReimport`), `T3.3.e` y `T5.2b` (`imagesFailed`).
- **Defecto:** claves duplicadas en `get_script_strings()`; la última gana (texto distinto), silencioso.
- **Fix exacto:** declararlas una sola vez (en T3.3.e) y que las fases posteriores sólo las consuman.

---

## UNIMPLEMENTABLE

**Nada es estrictamente imposible.** El heurístico `bulk_wc` **sí se puede implementar** para el caso común: el bulk delete de WC postea `post[]` como array (se distingue del escalar `post=123`), y eso funciona independientemente de si la acción viaja en `action` o `action2`. Lo que **no** se puede es hacerlo confiable:
- "Empty Trash" (`delete_all`) no se detecta con el chequeo actual (D5) pero se puede arreglar.
- REST / WP-CLI / `wp_delete_post()` programático no tienen `$_REQUEST` → siempre `manual_wc`.
- Un bulk con **un solo** ítem tildado (`post[]` de largo 1) cae en `manual_wc` (el plan lo acepta).

Conclusión: implementable como best-effort, **no** como clasificación confiable. La red de seguridad real es el checkbox default `ignore_all`; el plan lo reconoce, pero conviene que el copy de UI no prometa que el borrado masivo se detecta siempre.

---

## RACE CONDITIONS / EDGE CASES que el plan NO cubre

1. **State leído antes del lock + `ajax_sync_start` sin lock** (D3). Doble página procesada.
2. **Transient expira a mitad de run:** TTL 600 s; cada página lo renueva, pero una pausa >10 min (laptop cerrada) lo borra. El próximo `ajax_sync_page` da `fail_early('no_batch_state')`; el run queda `running` hasta `mark_abandoned`. Aceptable, pero el plan no lo documenta como escenario.
3. **Run marcado `stale` que "revive":** `mark_abandoned` marca `stale` pero **no** borra `alegra_batch_state`. Si el navegador zombie reintenta `ajax_sync_page`, `resume` sigue, procesa ítems y `finish` no re-finaliza (guard de status). Ítems importados sin fila de run coherente.
4. **Fatal mid-page por `max_execution_time`:** el offset no se persiste (el fatal ocurre antes), así que el próximo request re-procesa la página. Recuperable (idempotente), pero el plan afirma "el budget ≤40 s + margen siempre entra en 60 s" asumiendo `set_time_limit` efectivo. Con budget 40 s + una imagen de 15 s = 55 s; en hosts con hard limit 30 s y `set_time_limit` deshabilitado, muere igual. Recomendación: budget default 20 s y/o bajar el timeout de `download_url`.
5. **`$total_items = 0` (metadata sin total):** `$tp = 1` (`ajax_sync_start`), y `$done` con `next_start >= tp*per_page` cierra tras la primera página llena. Pre-existente, pero el rediseño de `$done` lo mantiene.
6. **Cursor compartido chunked vs cron:** el chunked libera el lock entre páginas; un cron puede tomar el lock, avanzar el cursor, y luego el chunked lo pisa hacia atrás con `update_option(cursor, $state['start'])`. La ventana es chica; conviene que el chunked no escriba el cursor salvo `done`.
7. **Conteo "suma == total" con variantes:** los ítems `variant` se saltean sin contar (`Products.php`/`ajax_sync_page`), así que la aserción NFR-01 del plan no se cumple en catálogos con variantes. Ajustar el test a ítems nuevos.
8. **`recent()` mezcla webhooks** (D10).

---

## VERIFIED-CORRECT

1. **El bug del stop (`Heartbeat::clear` borra `alegra_run_stop_{id}`)** — confirmado en `Heartbeat.php:66-71` (`:70`), único llamador `Admin_Dashboard.php:3765`. La separación `forget()` (sólo display) vs stop-transient borrado en `Run_Context::teardown()` es correcta y suficiente. **El fix funciona.**
2. **El bug del lock que `die()` no libera** — confirmado: `wp_send_json_*` → `wp_die()` → `die()`; el `finally` de `:2222-2224` no corre. **El plan cubre TODOS los `wp_send_json_*` con lock activo:**
   - `ajax_sync_page`: `:2116`, `:2148`, `:2184` (WP_Error) y `:2216` (éxito). Los de `:2076`, `:2079`, `:2087`, `:2101` ocurren **antes** de adquirir el lock, no filtran.
   - `ajax_import_from_api`: `:4103` (WP_Error) y `:4109` (éxito); `:4076/:4082/:4087/:4096` son previos al lock.
   - Doble `release` (explícito + `finally`) es **no-op seguro**: `release_sync_lock` sale temprano si la key no está en `$held_sync_locks` (`Controller.php:498-500`).
3. **`Products::import_from_alegra` NO adquiere el lock** (el lock de `:1116/:1244` es de `sync_inventory_from_alegra`). Así que en `ajax_import_from_api` hay **una** sola adquisición; el doble release es correcto. (En customers sí hay re-entrada: `Customers.php:188/289`; el registry estático la soporta.)
4. **`Run_Context` es autoloadable** — `Alegra\Connector\Run_Context` cae en el fallback `includes/<rel>` (`alegra-connector.php:128-133`). El `use Alegra\Connector\Logger\Logger;` es necesario (F3) y correcto.
5. **`mark_abandoned` SQL** — columnas `status`, `run_type`, `started_at`, `finished_at`, `error_summary` existen (`Schema.php:176-193`). `stale` ya es un valor de `status` usado por `mark_stale`. Sin migración.
6. **Timezone de `mark_abandoned` (F4)** — `current_time('timestamp')` + `gmdate` produce hora local, igual que `started_at` guardado con `current_time('mysql')`. Correcto (a diferencia de `mark_stale`, ver D4).
7. **`clear_all_logs()` no recrea archivo** — `write()` es el único que abre el `.log` (`Logger.php:139`); `ensure_dir()` sólo escribe `.htaccess`/`index.php` (`:80-89`), que no matchean `glob('*.log')` (`:206/:228/:314`). Correcto.
8. **Logger context estático por request** — seguro entre requests chunked: `resume()` lo setea en cada uno; los statics de PHP no persisten entre requests. La inyección `['run_id'=>X] + $context` respeta un `run_id` explícito.
9. **Webhook handshake (body vacío)** — retorna en `Receiver.php:57-59` **antes** del wrap → no crea fila. Correcto. El `catch` de `:174-184` mantiene el ACK 200 aunque `wrap` re-lance. Correcto (R2/NFR-02).
10. **Extensión por mime** — para imágenes raster (el caso real): `wp_check_filetype_and_ext($tmp, $fallback.'.jpg')` devuelve `ext=false` cuando el mime real no coincide con `.jpg`, y el fallback `extension_from_mime(wp_get_image_mime())` mapea bien PNG/WebP/GIF/AVIF. **Con caveat:** para SVG (u otro formato no introspeccionable por `exif_imagetype`/`wp_get_image_mime`) la función devuelve la extensión del filename (`jpg`) no-vacía, y el fallback **no** se ejecuta → el SVG queda `.jpg` (comportamiento previo). Verificar con un test de PNG real.
11. **Offset de reanudación** — `$index` cuenta el índice crudo del array; `offset = max(0,$index)` al pausar y `continue` sin tocar contadores al saltear: **no pierde ni duplica** ítems. El deadline se chequea antes de procesar y después del skip: correcto.
12. **`Runs::update_progress` filtra `status='running'`** (`:84`), así que actualizar un run ya cerrado es no-op. Correcto para el caso "run cerrado".
13. **`ajax_kill_run` sin `Heartbeat::clear`** — el run sigue visible en el Monitor hasta que el flujo observe el stop; `mark_abandoned` + TTL 300 s del stop evitan runs colgados. Correcto.
14. **Esquema de tombstones** — `reason VARCHAR(50)` sin enum (`Schema.php:159`), `resurrected_at` (`:160`): `exists_with_reason` y `bulk_wc` no requieren migración.

---

## Notas de test (harness)

- El harness lanza `Alegra_Test_JSON_Response` desde `wp_send_json_*`, que **sí** ejecuta `finally`. Por eso el observer H1.b de Fase 2 es imprescindible para que el test de "lock liberado en el instante del die()" sea real; sin él, el test pasa con el bug. Correcto en el plan.
- Para el test de deadline determinista (T3.1) conviene exponer un hook de delay en el mock o inyectar `Products::set_deadline(microtime(true)-1)` directamente; el plan ya lo contempla.
- Los tests viejos `T21.5`/`T-RB-5` siembran `page` sin `start`/`offset`; el default retrocompatible de C5 es correcto.

---

## Resumen de fixes mínimos antes de implementar

1. D1: chequear cancel/stop antes del `!$state` (o no borrar el state en `ajax_cancel_sync`).
2. D2: conservar `$page`/`$state['page']` para customers/categorías.
3. D3: lock antes de leer el state; `ajax_sync_start` rechaza si hay batch activo; `run_id` en el POST de página.
4. D4: corregir el cutoff de `mark_stale()` con `current_time('timestamp')`.
5. D5: `isset($_REQUEST['delete_all'])` + considerar `action2`.
6. D6: stop de manual/cron → `finish(cancelled)`.
7. D8: una sola fuente de `images` en el payload (mergeado).
8. D9: `get_log_dir()` debe llamar `ensure_dir()`.
