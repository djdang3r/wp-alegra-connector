# Especificación — Logs, Monitor e Importación de productos (`logs-monitor-import`)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documento base | `docs/sdd/logs-monitor-import/proposal.md` |
| Formato | Requerimientos EARS (Cuando/Si → el sistema DEBE) con escenarios Given/When/Then verificables desde el WP admin o el archivo de log |
| Estados | `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)` (espera Fase 0) |
| Versión analizada | 2.4.2 (`alegra-connector.php:6`) |
| Verificación | Cada `archivo:línea` fue re-verificado con lectura directa antes de escribir este documento |

> **Reglas de lectura.**
> 1. Cada requerimiento tiene **al menos un escenario** verificable desde el WP admin o el
>    archivo de log. No hay escenarios que requieran mirar la base de datos.
> 2. Los `BLOQUEADO(ON-VERIFICATION)` no se cierran hasta tener el resultado de Fase 0; cada
>    uno declara su **rama A / rama B** y el **check exacto**.
> 3. Los claims de código citan `archivo:línea`; lo no verificado se marca **SIN VERIFICAR**.
> 4. Los IDs de requerimiento son estables y se citan desde `design.md` y `tasks.md`.
> 5. **Fortaleza RFC 2119:** **DEBE** = MUST/SHALL (obligatorio) · **DEBERÍA** = SHOULD
>    (recomendado) · **PUEDE** = MAY (opcional).

---

## Correcciones de cita (re-verificadas)

La propuesta ya traía un bloque de correcciones. Al re-verificar cada cita contra el código en
HEAD, se confirma que las citas de la propuesta son exactas **salvo** los dos rangos de línea
que abajo se corrigen. También se detectó **deriva de citas en el `config-gates/spec.md`
relacionado** (la propuesta §9 lo referencia): se anota porque `tasks.md` puede consumirlo.

| Cita original | Cita real verificada | Nota |
|---|---|---|
| `ajax_import_from_api:4073-4125` | **`Admin_Dashboard.php:4073-4116`** | La función termina en `4116` (`finally` cierra el lock en `:4113-4115`). Corregido. |
| `Logger::clear_old_logs` (`Logger.php:198-218`) | **`Logger.php:198-222`** | El método cierra en `:222`; `return $count;` en `:221`. Corregido. |
| `Controller.php:150` único `Runs::track` | **Confirmado** | `grep Runs::track\|Runs::start` → 1 sola coincidencia (Controller.php:150). |
| `Heartbeat::set` sólo en cron (`:173,212,240,266,277`) | **Confirmado** | `grep Heartbeat::set` → 5 coincidencias, todas en `Controller.php`. |
| `Products.php:2231`, `:2393` (`.jpg`) | **Confirmado** | Ambos hardcodeados. |
| `Products.php:2125-2131`, `:2136-2157`, `:2170-2176` | **Confirmado** | Allowlist, matcher y bloqueo. |
| `Tombstone_Manager.php:32-59`, `:9-12` | **Confirmado** | `on_post_delete` y doc de `reason`. |
| `Products.php:1429` (skip por tombstone) | **Confirmado** | `Tombstone_Manager::exists('item', $alegra_id)`. |

**Deriva en `docs/sdd/config-gates/spec.md` (NO es cita de esta propuesta, pero §9 la usa):**
`ajax_sync_page` está en **`:2073`** (no `:1832`), `ajax_sync_pending_page` en **`:3450`** (no
`:2825`), `ajax_sync_now` en **`:1740`** (no `:1622`), `ajax_run_cron_now` en **`:3854`** (no
`:3134`), el único setter de `alegra_sync_cancelled` es **`:3015`** (no `:2532`), y el
`wp_remote_head` de preflight está en **`:1668`** (no `:1547`). El kill switch de `Products.php`
está en **`:1102,1251,1291`** (no `:1093,1266,1306`). Se recomienda corregir `config-gates`
antes de que `tasks.md` lo consuma; **no se toca en este cambio**.

---

## Convenciones

- **Ruta manual** = `ajax_import_from_api` (`Admin_Dashboard.php:4073`), el botón de la página
  **Importar** (`templates/admin-import.php:154`).
- **Ruta chunked** = `ajax_sync_start` (`Admin_Dashboard.php:2018`) → `ajax_sync_page`
  (`:2073`), el botón **"Traer desde Alegra"** de la página Productos
  (`templates/admin-products.php:92` → `admin.js:124-125,207-282`).
- **Ruta cron** = `Controller::run_cron_sync` → `Runs::track('cron_sync_all', …, 'cron')`
  (`Controller.php:150`).
- **Ruta webhook** = `Webhooks\Handlers::process_event` (`includes/Webhooks/Handlers.php:26`),
  que hoy loguea (`:28`) pero **no** crea `Runs` ni `Heartbeat`.
- **Run** = fila en `wp_alegra_runs` (`Runs::start`, `includes/Runs.php:46`).
- **Heartbeat** = transient `alegra_run_{id}` (`Heartbeat::set`, `includes/Heartbeat.php:29`).
- **Monitor** = `templates/admin-monitor.php` + handlers en `Admin_Dashboard.php:3696-3699`.
- **Log** = archivos `*.log` del `Logger` (`logger/Logger/Logger.php`).
- **Origen** = etiqueta del run: `manual` · `chunked` · `cron` · `webhook`.
- **`run_type` canónico** (contrato del Monitor, design §2.1): `cron_sync_all` (existente,
  `Controller.php:150`), `manual_import`, `chunked_import`, `webhook_item`/`webhook_client`/
  `webhook_invoice`. `Run_Context::fail_early($origin, …)` **DEBE** usar la forma canónica larga
  (`manual_import`/`chunked_import`), no alias cortos (`manual`/`chunked`): el label map del Monitor
  (design §2.7) mapea `cron_sync_all|cron_*`→Cron, `manual_import`→Manual, `chunked_import`→Chunked,
  `webhook_*`→Webhook. **Corregido (Oracle D11).**
- **Catálogo grande** = catálogo que no entra en un request (más de una página de 30 ítems).

---

## A. Observabilidad — el Monitor

### REQ-MON-01 — Toda ruta de importación abre una fila en `Runs` `ACTIVO`

**Cuando** se inicia una importación —manual, chunked, cron o webhook—, el sistema **DEBE**
crear una fila en `wp_alegra_runs` con `status='running'`, un `run_type` propio del origen y
`started_by` correcto (`usuario`, `'cron'` o `'webhook_alegra'`). **Cuando** la ruta sale
temprano o falla, **DEBE** cerrar esa fila con estado (`failed`/`cancelled`/`killed`) y
`error_summary` con la causa, nunca dejarla en `running`.

Hoy **sólo** el cron lo hace: `Runs::track` se llama una sola vez en todo el plugin
(`Controller.php:150`; verificado por grep) y `Heartbeat::set` sólo existe en
`Controller.php:173,212,240,266,277`. El chunked usa `import_single_item_public()`
(`Admin_Dashboard.php:2138`) sin `run_id`, y el webhook (`Handlers.php:26-58`) no toca `Runs`.

```gherkin
Escenario: Import manual (chunked) crea fila visible en el Monitor
  Dado un catálogo con al menos 1 ítem y la conexión testeada
  Cuando el comerciante hace clic en "Traer desde Alegra" en la página Productos
  Entonces el Monitor muestra una fila con origen 'chunked' y estado 'running'
  Y al terminar, la fila pasa a 'completed' en el Historial Reciente

Escenario: Import desde la página Importar (manual) crea fila
  Dado un comerciante en la página Importar
  Cuando dispara "Importar desde Alegra" para productos
  Entonces existe una fila en el Monitor con origen 'manual'

Escenario: Webhook crea fila
  Dado que llega un webhook 'new-item' con un ítem válido
  Cuando Handlers::process_event('new-item', data) corre
  Entonces el Monitor muestra una fila con origen 'webhook' y started_by='webhook_alegra'

Escenario (borde): salida temprana cierra la fila con causa
  Dado que el lock 'products' está tomado por otro proceso
  Cuando el comerciante dispara "Traer desde Alegra"
  Entonces el Monitor muestra una fila 'failed' (o 'cancelled')
  Y su error_summary explica que ya había una sincronización en curso
```

**Evidencia:** `Runs.php:29-41` (`track`), `:46-69` (`start`), `Controller.php:150`,
`Admin_Dashboard.php:2138` (chunked sin run_id), `Handlers.php:26-58`.

---

### REQ-MON-02 — Toda ruta setea `Heartbeat` con progreso `ACTIVO`

**Cuando** una importación avanza de etapa o de página, el sistema **DEBE** llamar
`Heartbeat::set($run_id, [...])` con al menos un `message` legible y **DEBE** llamar
`Runs::update_progress($run_id, $items_done, $total_items)` para que el Monitor muestre progreso
real. **Cuando** el run termina, el `Heartbeat` **DEBE** limpiarse con `Heartbeat::forget($run_id)`
(borra **sólo** el transient de display `alegra_run_{id}`). `Heartbeat::clear($run_id)` queda como
alias retrocompatible que **ya no** borra el pedido de stop `alegra_run_stop_{id}` (ese lo limpia
`Run_Context::finish` tras observarlo, o su TTL de 300 s). Alineado al design §2.5/T1.4.

Hoy sólo el cron setea Heartbeat y progreso (`Controller.php:173,212,240,266,277`; el
`Runs::update_progress` acompaña en `:174,213,241,267,281`).

```gherkin
Escenario: El progreso del chunked se ve en vivo en el Monitor
  Dado un import chunked en curso por la página 3 de 10
  Cuando el comerciante abre el Monitor
  Entonces la fila muestra un mensaje "Procesando productos... Página 3"
  Y muestra items_done/total_items con una barra de progreso mayor a 0%

Escenario: El Heartbeat se limpia al terminar
  Dado un import que acaba de completarse
  Cuando el Monitor refresca
  Entonces la fila ya no aparece en "Procesos Activos"
  Y no queda un transient alegra_run_{id} colgado
```

**Evidencia:** `Heartbeat.php:29-43` (`set`), `:66-71` (`clear`), `Runs.php:74-88`
(`update_progress`), `Controller.php:173-174`. **Corregido (design §2.5/T1.4):** el cierre usa
`Heartbeat::forget` (nuevo, borra sólo `alegra_run_{id}`); `clear` pasa a alias y deja de borrar
`alegra_run_stop_{id}` (`Heartbeat.php:70`).

---

### REQ-MON-03 — El Monitor muestra activos, recientes, progreso y la causa del fallo `ACTIVO`

**Cuando** el comerciante abre el Monitor, **DEBE** ver: (a) los runs `running`; (b) el historial
reciente; (c) el progreso de cada activo; y (d) para cada run `failed`, la **causa**
(`error_summary`). **Si** no hay runs, **DEBE** mostrar un estado vacío honesto, nunca un
"Cargando..." perpetuo.

Hoy el Monitor lee `Runs::currently_running()` / `Runs::recent()` / `Heartbeat::get_batch`
(`Admin_Dashboard.php:3696-3699`) y **sí** renderiza `error_summary` en el Historial Reciente
(header `templates/admin-monitor.php:175` = `S.thError`, celda `:186` = `escapeHtml(r.error)`,
alimentado por `Admin_Dashboard.php:3730` = `'error' => $run->error_summary`). **Corregido
(design §Correcciones #2):** la afirmación previa de "0 coincidencias" era del literal
`error_summary`, no del flujo. Lo que falta es el estado vacío honesto y un aviso cuando el AJAX
de estado falla (`admin-monitor.php:206-208` hoy es un silencio).

```gherkin
Escenario: Un run fallido muestra por qué falló
  Dado un import que falló porque GET /items devolvió WP_Error
  Cuando el comerciante abre el Monitor
  Entonces la fila del historial muestra estado 'failed'
  Y muestra el texto de la causa (el error_summary), no sólo la etiqueta "Fallido"

Escenario: El progreso se ve como barra y contador
  Dado un run activo con items_done=45 y total_items=300
  Cuando el comerciante abre el Monitor
  Entonces ve la barra al 15% y el texto "45 / 300 (15%)"

Escenario (borde): sin runs, estado vacío honesto
  Dado que no hay runs activos ni recientes
  Cuando el comerciante abre el Monitor
  Entonces ve un mensaje de "no hay procesos" (no un "Cargando..." infinito)
```

**Evidencia:** `Admin_Dashboard.php:3696-3699`, `templates/admin-monitor.php:108-136`
(`renderRunning`), `:171-191` (`renderRecent`; `:175` header `thError`, `:186` celda `r.error`),
`Admin_Dashboard.php:3730` (`'error' => $run->error_summary`), `Runs.php:95-118` (`finish` guarda
`error_summary`).

---

### REQ-MON-04 — El Monitor distingue el origen del run `ACTIVO`

**Cuando** se listan runs, el sistema **DEBE** mostrar el origen de cada uno con una etiqueta
inequívoca: `manual` · `chunked` · `cron` · `webhook`. El comerciante **DEBE** poder distinguir
un import disparado por él de uno automático.

```gherkin
Escenario: Los cuatro orígenes son distinguibles
  Dado un run manual, uno chunked, uno cron y uno webhook en el historial
  Cuando el comerciante abre el Monitor
  Entonces cada fila muestra su origen ('manual'/'chunked'/'cron'/'webhook')
  Y no todos se ven como el mismo tipo genérico
```

**Evidencia:** `Runs.php:24` (doc de `$type`), `Admin_Dashboard.php:3696-3699`.

---

### REQ-MON-05 — "Detener" detiene realmente un run manual/chunked `ACTIVO`

**Cuando** el comerciante pulsa "Detener" sobre un run manual/chunked, el sistema **DEBE**
llamar `Runs::request_stop($run_id)` y el flujo **DEBE** consultar `Runs::should_stop($run_id)`
y detenerse en el próximo lote/página. Esto **exige propagar el `run_id`** al flujo chunked:
hoy `Products::import_from_alegra` sólo consulta `should_stop` cuando `$run_id > 0`
(`Products.php:1298,1353`) y el chunked llama `import_single_item_public()` sin `run_id`
(`Admin_Dashboard.php:2138`), por lo que el botón no puede frenarlo.

```gherkin
Escenario: Detener corta el chunked en el próximo lote
  Dado un import chunked en curso con run_id R
  Cuando el comerciante pulsa "Detener" sobre la fila R en el Monitor
  Entonces el próximo lote no importa ítems nuevos
  Y el run R queda 'cancelled'
  Y el comerciante ve una notificación de que se detuvo

Escenario (borde): el run_id llega al importador
  Dado el flujo chunked de productos
  Entonces el run_id de la fila se propaga a la importación por página
  Y Runs::should_stop(run_id) se evalúa aun cuando el chunked no llame a import_from_alegra()

Escenario (borde): Detener un run ya terminado no rompe
  Dado un run 'completed'
  Cuando el comerciante pulsa "Detener" (si el botón aún se muestra)
  Entonces no se produce error y el estado no cambia
```

**Evidencia:** `Runs.php:123-134` (`request_stop`/`should_stop`), `Products.php:1248,1298,1353`,
`Admin_Dashboard.php:2138`, `templates/admin-monitor.php:132` (botón `data-stop-run`).

---

### REQ-MON-06 — El Monitor no se ve roto con el cron deshabilitado `ACTIVO`

**Cuando** el cron no está programado (`sync_method` ∉ {`cron`,`both`}) o el kill switch está
activo, la sección Cron del Monitor **DEBE** mostrar un estado vacío/deshabilitado **honesto**
con la razón. **Si** la tabla de runs no puede leerse/escribirse, **DEBE** mostrar el error, no
una pantalla en blanco.

```gherkin
Escenario: Cron deshabilitado muestra el motivo, no una lista vacía muda
  Dado alegra_connector_sync_method = 'real-time'
  Cuando el comerciante abre el Monitor
  Entonces la sección "Tareas Programadas (Cron)" explica que el modo es sólo tiempo real
  Y no se queda en "Cargando..." indefinidamente

Escenario (borde): fallo de la tabla de runs se muestra
  Dado que la tabla wp_alegra_runs no es legible (error de BD)
  Cuando el comerciante abre el Monitor
  Entonces ve un mensaje de error honesto
  Y no una pantalla vacía silenciosa
```

**Evidencia:** `templates/admin-monitor.php:51-61` (sección cron), `:11-22` (kill switch),
`Runs.php:174-206` (`currently_running`/`mark_stale`).

---

## B. Observabilidad — los logs

### REQ-LOG-01 — Toda salida temprana escribe un log con el motivo `ACTIVO`

**Cuando** una importación sale temprano por **lock ocupado, conexión no testeada, kill switch,
`WP_Error`, pausa por presupuesto, tombstone o fallo de imagen**, el sistema **DEBE** escribir
una entrada de log con el motivo y el origen. Hoy el chunked (`Admin_Dashboard.php:2099-2101`,
`:2079`, `:2116`) y la ruta manual (`ajax_import_from_api`, `Admin_Dashboard.php:4073-4116`)
retornan sin loguear.

```gherkin
Escenario: Lock ocupado deja rastro en el log
  Dado el lock 'products' tomado
  Cuando el comerciante dispara "Traer desde Alegra"
  Entonces la respuesta trae un message con la causa
  Y el archivo de log contiene una entrada que menciona el lock y el tipo 'products'

Escenario: Conexión no testeada deja rastro
  Dado alegra_connector_connection_tested ausente/false
  Cuando el comerciante dispara un import manual
  Entonces el archivo de log registra "conexión no testeada" (o equivalente)

Escenario: Kill switch deja rastro
  Dado el kill switch activo
  Cuando se dispara una importación
  Entonces el archivo de log registra el motivo del kill switch

Escenario: WP_Error de la API deja rastro
  Dado que GET /items responde WP_Error
  Cuando el chunked procesa la página
  Entonces el archivo de log registra el mensaje del WP_Error

Escenario: Pausa por presupuesto y tombstone dejan rastro
  Dado un import que pausa por presupuesto de tiempo
  Y un ítem salteado por tombstone
  Cuando termina el request
  Entonces el log registra la pausa con el cursor
  Y registra el skip del tombstone con el alegra_id
```

**Evidencia:** `Admin_Dashboard.php:2099-2101,2079,2116,4073-4116`; `Products.php:1306-1309`
(pausa), `:1432-1435` (tombstone), `:1406-1414` (kill switch/cancel).

---

### REQ-LOG-02 — El log registra inicio, progreso, resultado por página y resumen final `ACTIVO`

**Cuando** corre una importación, el log **DEBE** contener: (a) una entrada de inicio con el
origen y `run_id`; (b) progreso por página; (c) el resultado de cada página
(importados/actualizados/skipped/errores); y (d) un resumen final con los totales y el estado del
cursor (completo o pausado).

```gherkin
Escenario: Un import de dos páginas deja el rastro completo
  Dado un catálogo de 2 páginas (≤60 ítems)
  Cuando completa el import
  Entonces el log tiene una entrada de inicio
  Y al menos una entrada de progreso por página
  Y una entrada final con imported/updated/skipped/errors
  Y el estado del cursor (vacío si completó)

Escenario (borde): un import pausado lo dice en el resumen
  Dado un import que pausa por presupuesto antes de terminar
  Cuando termina el request
  Entonces el resumen final indica 'pausado' y el valor del cursor
  Y no lo reporta como completado
```

**Evidencia:** `Products.php:1286,1292,1299,1306,1395` (logs de import), `:1318-1327`
(progreso), `:1384-1393` (resumen), `Controller.php:283`.

---

### REQ-LOG-03 — Los fallos de imagen se loguean Y se exponen `ACTIVO`

**Cuando** una imagen falla (host no permitido, descarga o sideload), el sistema **DEBE**
loguear el motivo **Y** contabilizarlo para exponerlo en el resultado del import (ver
REQ-IMG-03). Hoy los fallos de imagen sólo van al log (`Products.php:2170-2175`,
`:2221-2227`, `:2236-2243`, `:2374-2379`, `:2387-2389`, `:2400-2402`).

```gherkin
Escenario: Los fallos de imagen quedan en el log con su motivo
  Dado un import donde 3 imágenes fallan al descargar
  Cuando termina el import
  Entonces el log tiene 3 entradas "Image download failed"
  Y cada una incluye el product_id y el mensaje de error

Escenario: Un host no permitido se loguea como bloqueo, no como fallo de red
  Dado un ítem cuya imagen apunta a un host fuera de la allowlist
  Cuando se importa el producto
  Entonces el log registra "Blocked image download from a non-allowlisted host"
```

**Evidencia:** `Products.php:2170-2175`, `:2221-2227`, `:2236-2243`, `:2374-2379`,
`:2387-2389`, `:2400-2402`.

---

### REQ-LOG-04 — El log registra el `run_id` para trazar de punta a punta `ACTIVO`

**Cuando** una entrada de log pertenece a una importación, **DEBE** incluir el `run_id` en su
contexto, de modo que el comerciante/soporte pueda correlacionar un run del Monitor con sus
entradas de log.

```gherkin
Escenario: Las entradas del import llevan el run_id
  Dado un import con run_id R
  Cuando se consulta el archivo de log
  Entonces cada entrada de ese import incluye run_id=R en su contexto
  Y el Monitor permite identificar el run R

Escenario (borde): una salida temprana también lleva run_id (o lo declara ausente)
  Dado un import que falla antes de crear el run
  Cuando se registra el fallo
  Entonces el log lo indica explícitamente (p. ej. run_id ausente por fallo previo a la creación)
```

**Evidencia:** `Runs.php:29-31` (el callback recibe `$run_id`), `Products.php:1286,1299,1306`
(contexto de log).

---

### REQ-LOG-05 — La página de Logs muestra dónde están los archivos `ACTIVO`

**Cuando** el comerciante abre la página de Logs, **DEBE** ver la **ruta absoluta real** del
directorio de logs (`Logger::get_log_dir()`) **siempre** (aunque no haya archivos), copiable. Hoy
`templates/admin-logs.php:98-100` **sí** muestra una ruta, pero es **relativa y hardcodeada**
(`wp-content/uploads/alegra-logs/`), **sólo aparece si hay archivos** (`if (!empty($log_files))`,
`:80`) y **no** es la ruta absoluta real. **Corregido (design §Correcciones #1).**

```gherkin
Escenario: La ruta del log es visible
  Cuando el comerciante abre la página de Logs
  Entonces se muestra la ruta absoluta del directorio de logs
  Y un enlace o texto copiable para localizarla
```

**Evidencia:** `templates/admin-logs.php:98-100` (ruta relativa y hardcodeada), `:80`
(`if (!empty($log_files))` la oculta sin archivos), `Logger.php` (`get_log_dir()`/`log_dir`).

---

### REQ-LOG-06 — Un fallo de escritura del logger no queda oculto `ACTIVO`

**Cuando** el directorio o el archivo de logs **no es escribible**, el sistema **DEBE** surface
un aviso visible en el admin (no sólo `error_log` bajo `WP_DEBUG`). Hoy `Logger::write` captura
`\Throwable` y sólo escribe a `error_log` si `WP_DEBUG` está activo (`Logger.php:162-168`), así
que una carpeta no escribible produce **cero logs y cero aviso**.

```gherkin
Escenario: Carpeta no escribible produce un aviso en el admin
  Dado que el directorio de logs existe pero no es escribible
  Cuando ocurre una importación
  Entonces el admin muestra un aviso visible de que el logger no puede escribir
  Y el aviso menciona la ruta del directorio

Escenario (negativo): carpeta escribible no muestra aviso
  Dado que el directorio de logs es escribible
  Cuando ocurre una importación
  Entonces no aparece ningún aviso de logger
```

**Evidencia:** `Logger.php:130-137` (checks de writable), `:162-168` (catch silencioso).

---

### REQ-LOG-07 — "Limpiar logs" borra TODO, con confirmación y conteo real `ACTIVO`

**Cuando** el comerciante pulsa "Limpiar logs" **y confirma**, el sistema **DEBE** borrar
**todos** los archivos `.log` (no sólo los más viejos que N días) y **DEBE** devolver el conteo
real de archivos eliminados. **Si** cancela, no **DEBE** borrar nada. La opción de retención para
el barrido automático (`Maintenance.php:56`) **DEBE** quedar intacta.

Hoy `clear_old_logs` sólo borra `filemtime < cutoff` (`Logger.php:198-222`, chequeo en `:211`),
por lo que el archivo del día nunca se toca → "0 eliminados". El handler
(`Admin_Dashboard.php:2322`, llamada en `:2335`) y el botón (`admin-logs.php:36`,
`admin.js:386`) dicen "Limpiar antiguos".

```gherkin
Escenario: Borra todos los archivos, incluido el de hoy
  Dado que existen 3 archivos .log, uno de ellos modificado hoy
  Cuando el comerciante pulsa "Limpiar logs" y confirma
  Entonces los 3 archivos se eliminan
  Y el mensaje dice "3 logs eliminados"
  Y la pantalla de Logs queda vacía

Escenario: Cancelar no borra nada
  Dado que existen archivos .log
  Cuando el comerciante pulsa "Limpiar logs" y rechaza la confirmación
  Entonces ningún archivo se elimina
  Y la pantalla no cambia

Escenario (borde): la retención automática sigue vigente
  Dado alegra_connector_log_retention_days = 30
  Cuando el comerciante limpia todo
  Entonces la opción de retención sigue en 30
  Y el barrido de Maintenance sigue eliminando archivos viejos en el futuro
```

**Evidencia:** `Logger.php:198-222` (chequeo `:211`), `Admin_Dashboard.php:2322,2335`,
`admin-logs.php:36`, `admin.js:386-393`, `Maintenance.php:53-58`.

---

## C. La importación de productos — sin fallos silenciosos

### REQ-IMP-01 — El JS chunked muestra error visible en TODA rama de fallo `ACTIVO`

**Cuando** el flujo chunked falla en cualquier punto —el `start` devuelve `success:false`, una
página devuelve `success:false`, o se agotan los reintentos— el sistema **DEBE** mostrar una
notificación visible con la causa. **NO DEBE** existir ninguna rama que llame a `cleanup()` sin
`showNotice`.

Hoy hay tres ramas mudas: `admin.js:220` (`!r.success` en el start), `:238` (`!r.success` en la
página) y `:278` (terminal tras agotar reintentos).

```gherkin
Escenario: El start falla y se muestra la causa
  Dado que alegra_sync_start responde success:false con un message
  Cuando el comerciante dispara "Traer desde Alegra"
  Entonces ve una notificación con la causa
  Y el botón vuelve a estar habilitado

Escenario: Una página falla y se muestra la causa
  Dado que alegra_sync_page responde success:false con un message
  Cuando el chunked procesa una página
  Entonces el comerciante ve una notificación con la causa (no un cierre mudo)

Escenario: Agotar reintentos muestra la causa, no un cierre mudo
  Dado que alegra_sync_page falla 3 veces seguidas
  Cuando se agotan los reintentos
  Entonces el comerciante ve una notificación de error con la causa
  Y no hay una rama que sólo haga cleanup()

Escenario (estático): ninguna rama de fallo sin showNotice
  Entonces el fuente de admin.js NO contiene una rama de error o de !r.success
    que llame a cleanup() sin un showNotice en la misma rama
```

**Evidencia:** `admin.js:218-227` (`:220`), `:235-271` (`:238`), `:272-280` (`:278`).

---

### REQ-IMP-02 — Los `success:false` del servidor llegan al usuario `ACTIVO`

**Cuando** el servidor responde `success:false` con un `message` (lock ocupado, batch-state
ausente, `WP_Error` de la API), el cliente **DEBE** mostrar ese mensaje al comerciante. El
contrato de respuesta (`success`/`data`) **DEBE** mantenerse, pero **DEBE** incluir siempre
`message`.

```gherkin
Escenario: Lock ocupado muestra el mensaje del servidor
  Dado el lock 'products' tomado
  Cuando el chunked recibe success:false con "Another sync is in progress"
  Entonces el comerciante ve ese mensaje (traducido o tal cual)

Escenario: Batch-state ausente muestra el mensaje del servidor
  Dado que el transient alegra_batch_state expiró
  Cuando se procesa una página
  Entonces el comerciante ve "No hay un proceso de sincronización en curso"

Escenario: WP_Error de la API muestra el mensaje del servidor
  Dado que GET /items devuelve WP_Error
  Cuando el chunked recibe success:false con el error
  Entonces el comerciante ve el mensaje del error
```

**Evidencia:** `Admin_Dashboard.php:2079,2099-2101,2116,2148,2184`; `admin.js:220,238`.

---

### REQ-IMP-03 — Se resuelve la inconsistencia 60 s/página vs 240 s de presupuesto `ACTIVO`

**Cuando** una página del chunked tiene imágenes pesadas (descarga + regeneración de thumbnails)
y el trabajo supera el límite, el servidor **DEBE** cortar **antes** del límite con un
presupuesto propio y responder "pausado, continuá" con el cursor, y el JS **DEBE** continuar con
la página siguiente. **NO DEBE** depender de `@set_time_limit(60)` como única defensa ni morir
por fatal de PHP (HTTP 500).

Hoy el chunked hace `@set_time_limit(60)` (`Admin_Dashboard.php:2105`) mientras que el
importador global usa un presupuesto de 240 s (`Products.php:1270`) y la página Importar usa
`@set_time_limit(300)` (`Admin_Dashboard.php:4078`). Los tres presupuestos son inconsistentes.

```gherkin
Escenario: Una página con imágenes pesadas pausa limpio, no muere
  Dado un chunked con 30 ítems cuyas imágenes tardan en total más que el límite
  Cuando el servidor procesa la página
  Entonces corta ANTES del límite y responde un estado de pausa con el cursor
  Y el JS continúa con la página siguiente
  Y no hay un fatal 500 ni un cierre silencioso

Escenario (borde): el resultado no pierde ítems entre páginas
  Dado un import que pausa y reanuda varias veces
  Cuando termina
  Entonces la suma de importados/actualizados/skipped/errores iguala el total del catálogo
  Y no hay ítems duplicados ni salteados

Escenario (estático): el corte no es un parche de set_time_limit
  Entonces el chunked tiene un presupuesto propio con reanudación
  Y @set_time_limit(60) no es la única defensa documentada
```

**Evidencia:** `Admin_Dashboard.php:2105` (`set_time_limit(60)`), `Products.php:1268-1274`
(presupuesto 240), `Admin_Dashboard.php:4078` (`set_time_limit(300)`).

---

### REQ-IMP-04 — Progreso visible durante el import `ACTIVO`

**Cuando** corre un import chunked de varias páginas, el comerciante **DEBE** ver una barra de
progreso y un contador (página / ítems procesados / errores) que se actualiza en cada página,
como el flujo existente de "Facturar pendientes". El modal **NO DEBE** quedar congelado sin
feedback.

```gherkin
Escenario: Barra y contadores se actualizan por página
  Dado un import chunked de 5 páginas
  Cuando el comerciante lo dispara
  Entonces ve la barra avanzar y los contadores de importados/actualizados/skipped/errores
  Y al terminar ve un resumen con los totales

Escenario (borde): import de una sola página también informa
  Dado un catálogo de menos de 30 ítems
  Cuando el comerciante importa
  Entonces ve el progreso completarse y un mensaje de finalización (no un cierre mudo)
```

**Evidencia:** `admin.js:141-155,240-267`, `Admin_Dashboard.php:2216-2221`
(`percent`/`message`), flujo modelo en `admin.js:699` y `Admin_Dashboard.php:3450`.

---

## D. La importación de productos — reanudar vs reimportar

### REQ-RES-01 — "Traer desde Alegra" reanuda desde el cursor y la UI lo muestra `ACTIVO`

**Cuando** existe un cursor de reanudación, el botón "Traer desde Alegra" **DEBE** continuar
desde ese punto (no reiniciar), y la UI **DEBE** mostrar dónde está (p. ej. "pausado en el ítem
1500 de 5000"). **Cuando** el import completa, **DEBE** limpiar el cursor y no mostrar indicador
de pausa.

Hoy el cursor existe (`Products.php:1264,1373-1381`) pero la UI no lo muestra, y "Traer desde
Alegra" reanuda en silencio.

```gherkin
Escenario: La UI indica que el próximo "Traer" reanuda
  Dado un cursor en el ítem 1500 de un catálogo de 5000
  Cuando el comerciante abre la página Productos
  Entonces ve un indicador de "pausado en el ítem 1500 de 5000" (o equivalente)
  Y al dar "Traer desde Alegra" el import continúa desde 1500, no desde 0

Escenario (borde): un import completo no muestra pausa
  Dado un import que completó el catálogo
  Cuando el comerciante abre la página Productos
  Entonces no ve indicador de pausa
  Y el cursor está vacío (0)

Escenario (borde): cursor en 0 no muestra una pausa falsa
  Dado un catálogo que nunca fue importado (cursor ausente/0)
  Cuando el comerciante abre la página Productos
  Entonces no ve un indicador de pausa
```

**Evidencia:** `Products.php:1264` (lectura del cursor), `:1373-1381` (persistencia),
`templates/admin-products.php:88-105`.

---

### REQ-RES-02 — "Reimportar todo desde cero" limpia el cursor y reimporta `ACTIVO`

**Cuando** el comerciante pulsa **"Reimportar todo desde cero"** **y confirma**, el sistema
**DEBE** limpiar el cursor y reimportar desde la página 1. **Si** cancela, **NO DEBE** limpiar
ni importar. El botón **DEBE** ser visualmente distinguible del botón que reanuda y su
confirmación **DEBE** explicar que es destructivo.

```gherkin
Escenario: Confirmar limpia el cursor y empieza de cero
  Dado un cursor en el ítem 1500
  Cuando el comerciante pulsa "Reimportar todo desde cero" y confirma
  Entonces el cursor queda en 0
  Y el import empieza en la página 1
  Y al terminar reporta creados/actualizados

Escenario: Cancelar no cambia nada
  Dado un cursor en el ítem 1500
  Cuando el comerciante pulsa "Reimportar todo desde cero" y rechaza la confirmación
  Entonces el cursor sigue en 1500
  Y no se importa ningún ítem

Escenario (borde): el botón destructivo se distingue del que reanuda
  Cuando el comerciante abre la página Productos
  Entonces ve dos botones con etiquetas inequívocas
  Y el destructivo pide confirmación
```

**Evidencia:** `templates/admin-products.php:88-105`, `Products.php:1264`, decisión del
comerciante §2.2 de la propuesta.

---

### REQ-RES-03 — Política de tombstones en "desde cero" `BLOQUEADO(ON-VERIFICATION)`

**Cuando** corre "Reimportar todo desde cero", el sistema **DEBE** poder recrear lo borrado,
venciendo los tombstones del borrado masivo **sin** romper la protección de los borrados
manuales del flujo incremental. La **política exacta** depende de Fase 0.

Hoy borrar productos de WC dispara `before_delete_post` (`alegra-connector.php:214`) →
`Tombstone_Manager::on_post_delete` (`Tombstone_Manager.php:32-59`) que **siempre** escribe
`reason='manual_wc'` (`:54`), y la reimportación saltea la creación si el tombstone existe
(`Products.php:1429`). **Problema de fondo:** un borrado masivo (seleccionar todo → borrar) y un
borrado individual son **indistinguibles** por `reason` — ambos son `manual_wc`. La mitigación
que proponía la propuesta ("separar por `reason`") **no alcanza por sí sola**; el fork es real.

**Check exacto (Fase 0):** confirmar con el comerciante **cómo** borró el catálogo (selección
masiva vs. ítem por ítem) y si **algún** producto borrado a mano debe permanecer borrado. En la
instalación: listar `wp_alegra_tombstones` y verificar si todos los tombstones de `item` son del
mismo evento masivo o hay borrados individuales mezclados.

**Rama A — "desde cero" ignora/limpia TODOS los tombstones de `item`:** simple y predecible;
recrea todo, pero **también** resucita los productos que el comerciante borró a propósito.
```gherkin
Escenario (Rama A): recrea todo lo borrado
  Dado tombstones de item (todos reason='manual_wc')
  Cuando el comerciante confirma "Reimportar todo desde cero"
  Entonces los tombstones de item se ignoran/limpian durante el import
  Y el catálogo completo se recrea
```

**Rama B — "desde cero" sólo limpia el borrado masivo; los `manual_wc` requieren un check
aparte:** preserva la protección de borrados manuales, pero exige un mecanismo nuevo para
detectar el borrado masivo (p. ej. un marcador seteado durante la limpieza, o un check
explícito "recrear también los que borré a mano").
```gherkin
Escenario (Rama B): el borrado masivo se recrea; el manual protegido no
  Dado un tombstone de un borrado masivo y uno de un borrado manual individual
  Cuando el comerciante confirma "Reimportar todo desde cero"
  Entonces el del borrado masivo se vence y el producto se recrea
  Y el manual individual NO se recrea salvo que marque el check explícito
```

**Decisión (design §5.2 / D4 — invierte la recomendación previa):** el checkbox del reimport arranca
**tildado** → política `ignore_all` (recrea **todo**, incluidos los `manual_wc`); destildarlo cambia
a `ignore_bulk` (recrea sólo el borrado masivo `bulk_wc`). Se descarta la Rama B como **default**
porque el workflow central del comerciante es "borrar todo → reimportar desde cero": si el
heurístico `bulk_wc` falla (otro plugin, REST, WP-CLI), con `ignore_bulk` por default el catálogo
**no** se recrearía y el flujo central quedaría roto. El botón normal "Traer desde Alegra" es
**siempre** `respect`, así que un borrado puntual no resucita en un resume; y `alegra_deleted`
**nunca** se resucita en ninguna política. El escenario de Rama B describe el comportamiento con el
checkbox **destildado** (`ignore_bulk`).

---

### REQ-RES-04 — Una importación pausada es visible y reporta cuántos quedan `ACTIVO`

**Cuando** un import pausa por presupuesto, el resultado **DEBE** indicar que quedó pausado, el
valor del cursor y **cuántos ítems quedan**. El Monitor **DEBE** mostrar el run como pausado (no
`completed`).

```gherkin
Escenario: El resultado de una pausa dice cuántos quedan
  Dado un catálogo de 5000 ítems y un cursor en 1500
  Cuando el import pausa por presupuesto
  Entonces el resultado indica 'pausado' con el cursor en 1500
  Y reporta que quedan 3500 ítems

Escenario (borde): el Monitor distingue pausado de completado
  Dado un import pausado
  Cuando el comerciante abre el Monitor
  Entonces el run NO se muestra como 'completed'
  Y se ve el estado de pausa con el progreso alcanzado
```

**Evidencia:** `Products.php:1303-1310,1376-1393`; `templates/admin-products.php:88-105`.

---

## E. La importación de productos — imágenes

### REQ-IMG-01 — Las imágenes se importan en el pull `ACTIVO`

**Cuando** se importa o actualiza un producto y `alegra_connector_sync_images` está activo, el
sistema **DEBE** importar las imágenes del ítem llamando a `import_product_images()` según el
`sync_images_mode` configurado. **Si** el modo excluye `images` por el campo excluido, **NO
DEBE** tocarlas.

```gherkin
Escenario: Un ítem con imágenes queda con sus adjuntos
  Dado un ítem de Alegra con images[] no vacío
  Y alegra_connector_sync_images = true
  Cuando se importa el producto
  Entonces el producto queda con sus imágenes adjuntas
  Y la imagen destacada respeta sync_images_mode

Escenario (borde): campo 'images' excluido no se toca
  Dado un producto existente con 'images' en la lista de campos excluidos
  Cuando se actualiza desde Alegra
  Entonces las imágenes del producto no cambian
```

**Evidencia:** `Products.php:1922-1926` (call site), `:2018` (`import_product_images`),
`:2231`/`:2393` (nombre de archivo).

---

### REQ-IMG-02 — La extensión del archivo se deriva del mime real `ACTIVO`

**Cuando** se descarga y sideload una imagen, el nombre del archivo **DEBE** derivar la extensión
del **mime real** (`wp_check_filetype_and_ext()` / `wp_get_image_mime()`), no de un `.jpg` forzado.
`.jpg` queda **sólo** como **último recurso** (`extension_from_mime()` default y el 2º argumento
`$fallback . '.jpg'` del chequeo de mime), nunca como nombre final forzado. Hoy hay dos puntos con
`.jpg` hardcodeado (`Products.php:2231`, `:2393`) más un método muerto (`Admin_Dashboard.php:132`),
que rompen PNG/WebP y la validación del sideload.

```gherkin
Escenario: Una imagen PNG conserva su extensión
  Dado un ítem cuya imagen es un PNG
  Cuando se descarga y sideload
  Entonces el adjunto resultante conserva la extensión .png
  Y no se guarda como .jpg

Escenario (estático): la extensión se deriva del mime
  Entonces el armado del file_array en Products.php usa wp_check_filetype_and_ext(...)
  Y .jpg aparece sólo como fallback literal, nunca como nombre final forzado
  Y el método muerto Admin_Dashboard::import_product_image ya no existe
```

**Evidencia:** `Products.php:2230-2233`, `:2392-2395`, `Admin_Dashboard.php:108-144` (método muerto);
`fase-5 T5.1a`/`T5.1b`.

---

### REQ-IMG-03 — Los fallos de imagen se reportan en el resultado del import `ACTIVO`

**Cuando** una o más imágenes fallan durante un import, el resultado **DEBE** reportar el
**conteo** y los **motivos** (host no permitido / descarga / sideload), no sólo loguearlos. El
comerciante **DEBE** ver en pantalla que hubo imágenes que no se importaron.

```gherkin
Escenario: El resumen reporta cuántas imágenes fallaron
  Dado un import donde 3 de 10 imágenes fallan
  Cuando termina el import
  Entonces el resultado reporta "3 imágenes fallaron"
  Y desglosa los motivos (host bloqueado / download / sideload)

Escenario (borde): un import sin fallos de imagen no alarma
  Dado un import donde todas las imágenes se importan
  Cuando termina
  Entonces el resultado no muestra advertencias de imagen
```

**Evidencia:** `Products.php:2170-2175,2221-2244,2374-2379,2386-2402`.

---

### REQ-IMG-04 — La allowlist se verifica contra el CDN real de Alegra `BLOQUEADO(ON-VERIFICATION)`

**Cuando** se importa una imagen, el sistema **DEBE** aceptarla sólo si el host está en la
allowlist (`Products.php:2125-2131`) con match de subdominio (`:2136-2157`). La propuesta marcó
como **SIN VERIFICAR** si el host real de las imágenes está dentro de `*.alegra.com`; si
Alegra sirviera desde otro CDN, **toda** imagen se bloquearía (`:2170-2176`).

**Check exacto (Fase 0):** en la instalación del comerciante, capturar la URL real de
`images[].url` de un ítem de Alegra (p. ej. el payload de `GET /items` o el meta
`_alegra_image_url` de un adjunto existente) y correr `Products::is_allowed_image_url($url)`.
Registrar el host observado.

**Rama A — el host está en `*.alegra.com`:** no se toca la allowlist; se cierra como falsa
alarma y se documenta.
```gherkin
Escenario (Rama A): el host real ya está permitido
  Dado que la URL real de imagen resuelve a un subdominio de alegra.com
  Cuando se importa el producto
  Entonces is_allowed_image_url() es true
  Y la imagen se importa sin cambios en la allowlist
```

**Rama B — el host está fuera de `*.alegra.com`:** se amplía la allowlist con el host observado
(nunca `*`), manteniendo https obligatorio, y se agrega una prueba de regresión.
```gherkin
Escenario (Rama B): host fuera de la allowlist se agrega con evidencia
  Dado que la URL real resuelve a un CDN fuera de alegra.com
  Cuando se cierra Fase 0
  Entonces la allowlist incluye el host observado
  Y NO se agrega un comodín '*'
  Y sigue exigiéndose https
  Y la imagen se importa
```

**Evidencia:** `Products.php:2125-2131,2136-2157,2170-2176`; propuesta §8.1 (SIN VERIFICAR).

---

## F. Correctitud del contrato de importación

### REQ-CON-01 — El cron no importa productos cuando `sync_products=false` `ACTIVO`

**Cuando** `alegra_connector_sync_products=false` (default actual: `alegra-connector.php:424`,
`Controller.php:171`), el cron **NO DEBE** importar productos ni crear un run de productos; el
sistema **DEBE** dejarlo explícito en el log/Monitor (no un salto mudo). Las rutas manual y
webhook **DEBEN** seguir funcionando independientemente de ese flag.

```gherkin
Escenario: Con el flag en false el cron no toca productos
  Dado alegra_connector_sync_products = false
  Cuando corre el cron
  Entonces no se importan productos
  Y el log/Monitor lo indica (productos salteados por configuración)

Escenario (negativo): con el flag en true el cron importa productos
  Dado alegra_connector_sync_products = true
  Cuando corre el cron
  Entonces se importan productos
  Y el run del cron reporta el conteo de productos

Escenario (borde): el botón manual funciona con el flag en false
  Dado alegra_connector_sync_products = false
  Cuando el comerciante usa "Traer desde Alegra" o "Reimportar todo desde cero"
  Entonces el import se ejecuta igual
```

**Evidencia:** `alegra-connector.php:424`, `Controller.php:171`.

---

### REQ-CON-02 — El default de `sync_products` `BLOQUEADO(ON-VERIFICATION)`

**Cuando** se cierra este cambio, el default de `alegra_connector_sync_products` **DEBE** quedar
alineado entre UI, runtime y activación (consistente con `config-gates` REQ-CFG-2). La propuesta
**dejó abierta** la decisión de cambiarlo o no.

**Check exacto (Fase 0):** confirmar el estado real de `alegra_connector_sync_products` en la
tienda del comerciante (`get_option`) y coordinar con `config-gates` antes de decidir.

**Rama A — el default queda `false` (sin cambio):** el cron no importa productos salvo toggle;
se documenta que la importación automática de productos requiere activarlo o usar el botón
manual/webhook. **Recomendada** para no cambiar comportamiento existente.
```gherkin
Escenario (Rama A): instalación nueva no importa productos por cron
  Dado una instalación nueva sin tocar la opción
  Cuando corre el cron
  Entonces no se importan productos (el default sigue false)
  Y la UI muestra el checkbox destildado (UI == runtime)
```

**Rama B — el default cambia a `true`:** el cron importa productos por defecto mientras maduran
los webhooks; exige migración que **no** pise valores existentes y nota de release.
```gherkin
Escenario (Rama B): instalación nueva importa productos por cron
  Dado una instalación nueva sin tocar la opción
  Cuando corre el cron
  Entonces se importan productos (el default es true)
  Y una instalación existente con false explícito NO cambia
```

**Evidencia:** `alegra-connector.php:424`, `Controller.php:171`; `config-gates/spec.md` REQ-CFG-2.

---

## G. Requerimientos no funcionales

### NFR-01 — Sin regresión en catálogos grandes `ACTIVO`

**Cuando** el catálogo no entra en un request, el import **DEBE** completarse (o pausar y
reanudar) sin perder ni duplicar ítems y sin fatal 500.

```gherkin
Escenario: Catálogo grande completa sin perder ítems
  Dado un catálogo de 5000 ítems
  Cuando el comerciante importa (reanudando las veces necesarias)
  Entonces el total procesado iguala los 5000
  Y no hay duplicados ni ítems salteados
  Y no hubo fatal 500 en ningún request
```

### NFR-02 — Sin regresión en cron y webhook `ACTIVO`

**Cuando** se cierra el cambio, el cron y los webhooks **DEBEN** seguir funcionando con su
contrato actual. La instrumentación nueva **NO DEBE** alterar el resultado de negocio.

```gherkin
Escenario: El cron sigue completando su ciclo
  Dado el cron configurado y el kill switch inactivo
  Cuando corre el cron
  Entonces completa sus 4 etapas como hoy
  Y ahora además deja run + heartbeat + log

Escenario: Un webhook sigue importando el ítem
  Dado un webhook 'new-item' válido
  Cuando se procesa
  Entonces el ítem se importa como hoy
  Y además queda registrado el run del webhook
```

### NFR-03 — Los logs no crecen sin límite (retención) `ACTIVO`

**Cuando** pasa el tiempo, los archivos de log **NO DEBEN** crecer indefinidamente. El barrido
automático de retención (`Maintenance.php:53-58`) **DEBE** seguir vigente, y "Limpiar logs"
**DEBE** ser una acción puntual que no desactiva la retención.

```gherkin
Escenario: El barrido automático respeta la retención
  Dado alegra_connector_log_retention_days = 30
  Cuando corre Maintenance
  Entonces los archivos con más de 30 días se eliminan
  Y los recientes se conservan

Escenario (borde): limpiar todo no desactiva la retención
  Dado que el comerciante limpió todos los logs
  Entonces la opción de retención sigue configurada
  Y el próximo barrido sigue funcionando
```

### NFR-04 — El import no se ralentiza `ACTIVO`

**Cuando** se agrega instrumentación (run, heartbeat, log), el import **NO DEBE** volverse
significativamente más lento: **NO DEBE** agregar llamadas de red por ítem ni escrituras de log
por ítem no acotadas.

```gherkin
Escenario: La instrumentación no agrega llamadas de red
  Dado un import de N ítems
  Cuando corre
  Entonces el número de requests a Alegra no aumenta por la instrumentación
  Y el log se escribe por página/etapa, no por ítem (salvo el detalle de fallo)

Escenario (borde): el progreso se actualiza de forma acotada
  Entonces Runs::update_progress y Heartbeat::set se llaman por página/etapa
  Y no una vez por ítem
```

### NFR-05 — Plugin distribuido: sin supuestos de host `ACTIVO`

**Cuando** el plugin corre en cualquier host, el chunked **DEBE** funcionar aunque el host
ignore `set_time_limit`, no haya `exec` ni cron real, y el CDN de imágenes no sea fijo. El
Monitor **DEBE** degradar bien si `Runs` no puede escribir.

```gherkin
Escenario: El chunked funciona con set_time_limit ignorado
  Dado un host que ignora set_time_limit
  Cuando corre un import grande
  Entonces el corte es por presupuesto propio y reanudación
  Y no depende de extender el límite

Escenario: El Monitor degrada bien sin tabla de runs
  Dado que la tabla de runs no puede escribirse/leerse
  Cuando el comerciante abre el Monitor
  Entonces ve el error, no una pantalla vacía
```

### NFR-06 — Compatibilidad hacia atrás `ACTIVO`

**Cuando** se cierra el cambio, el contrato de respuesta del chunked y del importador global
**DEBE** mantener `success`/`data` y **DEBE** incluir siempre `message`. El cambio de semántica
de "Limpiar logs" (antes: viejos; ahora: todo) **DEBE** documentarse como intencional en
`CHANGELOG.md`.

```gherkin
Escenario: El contrato de respuesta se mantiene
  Dado un consumidor que hoy lee r.success y r.data
  Cuando el chunked responde
  Entonces success y data siguen presentes
  Y message está presente en ambas ramas

Escenario: El cambio de "Limpiar logs" está documentado
  Entonces CHANGELOG.md describe que "Limpiar logs" ahora borra todo
  Y la confirmación de la UI lo advierte
```

### NFR-07 — Seguridad `ACTIVO`

**Cuando** se ajusta la allowlist de imágenes, **NO DEBE** ampliarse a comodín (`*`) ni
permitirse http; **DEBE** seguir exigiéndose https y host permitido. Los logs **NO DEBEN**
exponer secretos (token/credenciales).

```gherkin
Escenario: La allowlist nunca se amplía a comodín
  Entonces allowed_image_hosts() no contiene '*'
  Y is_allowed_image_url() rechaza http y hosts no permitidos

Escenario (borde): los logs no filtran el token
  Cuando se escribe una entrada de log con contexto de API
  Entonces el token/credenciales no aparecen en el texto del log
```

---

## H. Trazabilidad (requerimiento → propuesta "What Changes")

> La propuesta no tiene una sección literal "What Changes"; el mapeo apunta a **§3.1 Alcance
> (dentro del alcance)** y a los **hallazgos §4 (A1-A4, B1-B11, C1-C3)** y las **decisiones §2**.

| Grupo | Requerimientos | §3.1 (Alcance) | Hallazgos §4 | Decisiones §2 |
|---|---|---|---|---|
| A — Monitor | REQ-MON-01..06 | Observabilidad: fila `Runs`, `Heartbeat`, Monitor manual+chunked, "Detener" | A1, B1, B11 | 2, 4 |
| B — Logs | REQ-LOG-01..07 | Observabilidad: log de inicio/progreso/fin/error, limpiar todo, logger no silencioso | A2, A3, A4 | 1 |
| C — Sin fallos silenciosos | REQ-IMP-01..04 | Importación: fail-loud, presupuesto limpio, progreso visible | B2, B3, B4, C3 | 3 |
| D — Reanudar vs reimportar | REQ-RES-01..04 | Importación: cursor visible, "desde cero", tombstones | B5, B6, B11, C1, C2 | 2 |
| E — Imágenes | REQ-IMG-01..04 | Importación: `.jpg`, allowlist con evidencia, reporte de fallos | B7, B8, B9 | — |
| F — Contrato | REQ-CON-01..02 | Importación: modalidad como está, cron temporal | B10, B11 | 4 |
| G — No funcionales | NFR-01..07 | §3.3 Restricción transversal (distribuido, sin regresión) | §6 Riesgo / compatibilidad | 5 |

### Decisiones del comerciante → requerimiento

| Decisión (§2) | Requerimiento |
|---|---|
| 1. "Limpiar logs" borra TODO con confirmación | REQ-LOG-07 |
| 2. Dos botones (reanudar + reimportar desde cero) | REQ-RES-01, REQ-RES-02, REQ-RES-03 |
| 3. Chunks con progreso visible, sin timeout/pausa silenciosa | REQ-IMP-03, REQ-IMP-04 |
| 4. Mayormente manual, webhooks actualizan; cron temporal; modalidad como está | REQ-MON-01 (webhook), REQ-CON-01, REQ-CON-02, NFR-02 |
| 5. Debe funcionar en cualquier tienda (distribuido) | NFR-05 |

### Bloqueados por verificación (resumen)

| Requerimiento | Incógnita | Check exacto | Rama A | Rama B |
|---|---|---|---|---|
| REQ-IMG-04 | Host real del CDN de imágenes | Capturar `images[].url` real y correr `is_allowed_image_url()` | Ya permitido → sin cambio | Fuera de allowlist → ampliar con host observado (nunca `*`) |
| REQ-RES-03 | Política de tombstones en "desde cero" | Confirmar cómo se borró el catálogo y si hay borrados manuales que deben persistir | Ignorar todos los tombstones `item` | Checkbox tildado por default (`ignore_all`, recrea todo); destildado = `ignore_bulk` (design §5.2/D4) |
| REQ-CON-02 | Default de `sync_products` | `get_option('alegra_connector_sync_products')` + coordinar con `config-gates` | Queda `false` (recomendada) | Cambia a `true` con migración + release note |
