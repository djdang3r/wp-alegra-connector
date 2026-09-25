# Diseño Técnico — Logs, Monitor e Importación de productos (`logs-monitor-import`)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` (observabilidad + monitor de procesos + importación de productos) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Versión analizada | 2.4.2 (`alegra-connector.php:6`) |
| Versión objetivo | **2.5.0** (cambio de comportamiento + opciones nuevas ⇒ minor) |
| Naturaleza | Diseño técnico (no implementación) |
| Regla | Toda decisión nombra archivo, método y opción; lo no verificado es **SIN VERIFICAR / BLOQUEADO** |

> Este diseño es **cerrado**: dos desarrolladores implementan lo mismo. Donde hay elección, está
> tomada y justificada. Donde depende de Fase 0, se declaran las dos ramas y el punto exacto de
> bifurcación. Las citas `archivo:línea` fueron re-verificadas leyendo el código en HEAD.

---

## Correcciones de cita (re-verificadas en HEAD)

La spec ya corrigió varias citas. Al re-leer el código aparecen **cuatro hallazgos nuevos** que
cambian el diseño y que la spec no detectó. Se documentan acá porque `tasks.md` los va a consumir.

| # | Claim de la spec | Realidad verificada | Impacto |
|---|---|---|---|
| 1 | REQ-LOG-05: "`templates/admin-logs.php` **no muestra la ruta** (verificado: sólo filtros y visor)" | **Falso.** `admin-logs.php:98-100` SÍ muestra `wp-content/uploads/alegra-logs/`, pero: (a) es **relativa y hardcodeada**, (b) está **dentro de `if (!empty($log_files))`** (sólo aparece si hay archivos), (c) no es la ruta absoluta real (`Logger::$log_dir`). | El requerimiento cambia: no es "agregar la ruta", es "mostrar la ruta **absoluta real** (`Logger::get_log_dir()`), **siempre** (aunque no haya archivos), copiable". |
| 2 | REQ-MON-03: el Monitor "**no renderiza `error_summary`** (verificado: 0 coincidencias en `templates/admin-monitor.php`)" | **Falso.** El Historial Reciente ya pinta la causa: header `admin-monitor.php:175` (`S.thError`) y celda `:186` (`escapeHtml(r.error)`), alimentada por `Admin_Dashboard.php:3730` (`'error' => $run->error_summary`). Las "0 coincidencias" son del literal `error_summary`, no del flujo. | REQ-MON-03 queda **mayormente cubierto**; lo que falta es estado vacío honesto y mensaje de error si el AJAX falla (`admin-monitor.php:206-208` es `// Silent fail - retry next poll`). |
| 3 | REQ-IMG-02 cita sólo `Products.php:2231` y `:2393` como `.jpg` hardcodeado | Hay un **tercer** `.jpg` en `Admin_Dashboard.php:132` y una **tercera** allowlist en `Admin_Dashboard.php:113`. Ese método (`Admin_Dashboard::import_product_image`, `:108`) es **código muerto: 0 llamadores** (grep de `import_product_image` → sólo la definición; `Products` tiene el suyo). `PLAN.md:98` dice "✅ A.9: Consolidar import_product_image" — quedó a medias. | Se **borra** el método muerto en vez de arreglarlo. Cierra REQ-IMG-02 por completo. |
| 4 | REQ-MON-05: "`Products.php:1298,1353` … el botón no puede frenarlo" | Correcto, **pero hay un bug peor**: `ajax_kill_run` (`Admin_Dashboard.php:3764-3765`) llama `Runs::request_stop($run_id)` y **acto seguido** `Heartbeat::clear($run_id)`; `Heartbeat::clear` (`Heartbeat.php:70`) hace `delete_transient('alegra_run_stop_' . $run_id)` → **borra el propio pedido de stop que se acaba de setear**. "Detener" nunca funciona ni para el cron. | Hallazgo nuevo y crítico. El diseño lo arregla en D1. |
| 5 | REQ-LOG-04 evidencia `Products.php:1286,1299,1306` "incluye `run_id` en su contexto" | Esas son las llamadas de log, pero **ninguna lleva `run_id` hoy**: `:1286` y `:1299` no pasan contexto; `:1306` pasa `['cursor' => $start]`. | La evidencia describe el **sitio** donde hay que inyectarlo, no el estado actual. No invalida el requerimiento. |
| 6 | REQ-LOG-01 evidencia `Products.php:1406-1414` | El guard real kill-switch/cancel es **`:1410-1415`**. | Corrección menor de rango. |

> **Hallazgo de plataforma (afecta todo el diseño).** `wp_send_json_*()` termina el request con
> `wp_die()` → `die()`. En PHP, `die()` **no ejecuta los `finally`**. El `finally` que libera el
> lock en `ajax_sync_page` (`Admin_Dashboard.php:2222-2224`) **no corre** en **los 4** `wp_send_json_*`
> del handler: los `error` de `:2116,2148,2184` **y** el `success` de `:2216-2221` (todos dentro del
> `try`); el lock queda tomado hasta que expire el TTL de 300 s. Es un bug
> pre-existente, pero condiciona el diseño: **todo cierre de run y liberación de lock debe
> ejecutarse ANTES de `wp_send_json_*`**, nunca confiando en `finally`.

---

## 0. Mapa de cambios

| # | Archivo | Símbolo | Tipo |
|---|---|---|---|
| CORE | `includes/Run_Context.php` | clase nueva `Run_Context` (`begin`/`resume`/`finish`/`fail_early`/`wrap`/`should_stop`/política de tombstones) | **crear** |
| CORE | `includes/Runs.php` | `mark_abandoned()` (nuevo), `status()` (nuevo); fix de timezone en `mark_stale()`; doc de `run_type` | editar |
| CORE | `includes/Heartbeat.php` | `clear()` deja de borrar el transient de stop; `forget()` (nuevo) | editar |
| 1 | `logger/Logger/Logger.php` | `set_run_context()`/`clear_run_context()` (nuevos), `clear_all_logs()` (nuevo), `get_log_dir()`/`is_writable()` (nuevos), `render_write_failure_notice()` (nuevo), `write()` marca/limpia la opción de fallo | editar |
| 2 | `includes/Sync/Products.php` | `import_from_alegra()` (budget/run), `import_single_item_public()`/`import_single_item_from_alegra()` (run_id + stop), stats de imagen, extensión por mime, deadline por imagen, allowlist configurable | editar |
| 3 | `includes/Sync/Controller.php` | `import_from_alegra($type,$run_id)` propaga; `run_cron_sync` vía `Run_Context::wrap`; log de `sync_products=false` | editar |
| 4 | `includes/Tombstone_Manager.php` | `on_post_delete` clasifica `reason`; `exists_with_reason()` (nuevo) | editar |
| 5 | `includes/Webhooks/Receiver.php` | `process_event` envuelto en `Run_Context::wrap('webhook_*')` | editar |
| 6 | `admin/Admin/Admin_Dashboard.php` | `ajax_sync_start`/`ajax_sync_page`/`ajax_import_from_api` instrumentados; `ajax_clear_logs`; `ajax_monitor_status`; borrar `import_product_image` muerto (`:108-144`); mensajes en todo `wp_send_json_error`; registrar opciones nuevas; `get_script_strings()` | editar |
| 7 | `admin/assets/js/admin.js` | ramas fail-loud (`:220,:238,:278`); progreso/pausa; dos botones; confirm de limpiar todo | editar |
| 8 | `templates/admin-products.php` | segundo botón + confirm + indicador de cursor | editar |
| 9 | `templates/admin-logs.php` | label del botón, ruta absoluta siempre, copy de borrado total | editar |
| 10 | `templates/admin-monitor.php` | badges `stale`, labels de origen, estado vacío/error honesto | editar |
| 11 | `templates/admin-settings.php` | budget de página y hosts de imagen extra | editar |
| 12 | `alegra-connector.php` | `$defaults`/`$non_autoload` de opciones nuevas; hook `admin_notices` del logger | editar |
| 13 | `uninstall.php` | opciones nuevas | editar |
| 14 | `CHANGELOG.md` | nota de comportamiento (limpiar todo, desde cero, orígenes) | editar |

**NO TOCAR (restricción explícita):** `languages/*`, `logger/*/index.php`, `public/*/index.php`.

---

## 1. Principios

1. **Un solo punto de verdad para "hay un run".** Ningún camino de importación llama a
   `Runs::start()` directo: todos pasan por `Run_Context`. El `run_id` viaja por parámetro
   explícito o por el transient de estado; **nunca** por variable global mutable.
2. **Fail-loud de punta a punta.** Cada `return` temprano del servidor incluye `message` y deja
   rastro en el log; cada rama del JS muestra `showNotice` con ese `message`. Cero ramas mudas.
3. **El presupuesto es propio, no del host.** El corte del chunked es por wall-clock propio +
   reanudación con offset; `set_time_limit` es backstop, no defensa principal.
4. **Nada se pierde ni se duplica.** El estado de reanudación del chunked trackea `start` **y**
   `offset` dentro de la página; pausar a mitad de página no saltea ítems.
5. **El logger nunca rompe el import, pero tampoco se esconde.** Un fallo de escritura se
   persiste y se surface como `admin_notice`; el flujo continúa.
6. **Sin regresión.** El cron conserva su contrato (`Runs::track` → `Run_Context::wrap` con la
   misma semántica); las filas viejas de `Runs` siguen renderizándose; ninguna opción cambia de
   default sin decisión explícita del comerciante.
7. **Plugin distribuido.** Cero supuestos de host: sin `exec`, sin cron real, sin
   `set_time_limit` efectivo, sin host de imágenes fijo hardcodeado.

---

## 2. D1 — Pipeline de observabilidad

### 2.1 `Run_Context`: el wrapper compartido

**Decisión:** clase nueva `Alegra\Connector\Run_Context` (`includes/Run_Context.php`). No se
meten estos métodos en `Runs` porque coordinan **tres** colaboradores (`Runs`, `Heartbeat`,
`Logger`) y manejan **estado request-scoped** + una **política** de tombstones; eso es
orquestación, no persistencia. `Runs` queda repositorio puro (sin dependencia de Logger),
testeable y sin romper el Monitor.

```php
namespace Alegra\Connector;

use Alegra\Connector\Logger\Logger;   // F3: sin este import, `Logger::` resuelve a un namespace inexistente y fatalea

final class Run_Context
{
    private static int $run_id = 0;
    private static string $type = '';
    private static string $tombstone_policy = 'respect'; // respect | ignore_bulk | ignore_all

    public static function begin(string $type, ?string $started_by = null, array $context = []): int;
    public static function resume(int $run_id, string $type): void;
    public static function finish(int $run_id, string $status = 'completed', ?string $error = null): void;
    public static function fail_early(string $origin, string $reason, array $context = []): void;
    public static function wrap(string $type, callable $fn, ?string $started_by = null, array $context = []): mixed;
    public static function current(): int;
    public static function should_stop(): bool;
    public static function set_tombstone_policy(string $policy): void;
    public static function tombstone_policy(): string;
}
```

Comportamiento:

```php
public static function begin(string $type, ?string $started_by = null, array $context = []): int
{
    $run_id = Runs::start($type, $started_by, $context);
    self::$run_id = $run_id;
    self::$type = $type;
    Logger::set_run_context($run_id, $type);          // D2
    Heartbeat::set($run_id, ['step' => 'start', 'message' => __('Iniciando...', 'alegra-connector')]);
    return $run_id;
}

public static function finish(int $run_id, string $status = 'completed', ?string $error = null): void
{
    // §2.5 punto 4 (R9): no re-finalizar un run ya cerrado. Sin este guard, un
    // "Detener" sobre un run terminado pisa `completed` con `cancelled`.
    $current = Runs::status($run_id);                  // T1.3
    if ($current !== null && $current !== 'running') {
        self::teardown($run_id);                       // ya cerrado: sólo limpiar
        return;
    }
    Runs::finish($run_id, $status, $error);
    self::teardown($run_id);
}

private static function teardown(int $run_id): void
{
    delete_transient('alegra_run_stop_' . $run_id);    // §2.5 punto 1: el stop ya fue observado
    Heartbeat::forget($run_id);                        // sólo el transient de display
    Logger::clear_run_context();
    if (self::$run_id === $run_id) { self::$run_id = 0; self::$type = ''; }
}

public static function fail_early(string $origin, string $reason, array $context = []): void
{
    // NO crea fila: el run no llegó a existir. Loguea con run_type y lo declara.
    Logger::set_run_context(0, $origin);
    // `info()` es de INSTANCIA (`Logger.php:171`, no `static`): `Logger::info(...)` es
    // fatal en PHP 8 → HTTP 500 en cada salida temprana. Se usa una instancia.
    (new Logger())->info('Import abortado antes de crear el run', ['reason' => $reason] + $context);
    Logger::clear_run_context();
}

public static function wrap(string $type, callable $fn, ?string $started_by = null, array $context = []): mixed
{
    $run_id = self::begin($type, $started_by, $context);
    try {
        $result = $fn($run_id);
        self::finish($run_id, 'completed');
        return $result;
    } catch (\Throwable $e) {
        self::finish($run_id, 'failed', $e->getMessage());
        throw $e;
    }
}
```

**Tipos de run (contrato del Monitor):** `cron_sync_all` (existente), `manual_import`,
`chunked_import`, `webhook_item`/`webhook_client`/`webhook_invoice`. `started_by`:
`usuario`, `'cron'`, `'webhook_alegra'`.

### 2.2 Flujo por ruta

```
                         ┌───────────────────────────────────────────────┐
  Botón Importar  ──────▶ │ ajax_import_from_api                          │
                         │  guard conexión ─ fail_early (sin fila)       │
                         │  Run_Context::begin('manual_import')          │
                         │  lock ─ ocupado? finish(failed)+release+error │
                         │  Controller::import_from_alegra($t,$run_id)   │
                         │  finish(completed|failed)                     │
                         └───────────────────────────────────────────────┘

  Botón Productos ──────▶ ┌ ajax_sync_start ─────────────────────────────┐
  (chunked)              │  Run_Context::begin('chunked_import')         │
                         │  cursor: from_zero? 0 : option                │
                         │  state['run_id']=R, state['start'], offset=0  │
                         │  set_transient('alegra_batch_state')          │
                         └───────────────────┬───────────────────────────┘
                                             │  (R persiste en el transient)
                         ┌───────────────────▼───────────────────────────┐
                         │ ajax_sync_page  (N requests)                  │
                         │  Run_Context::resume(R,'chunked_import')      │
                         │  cancel/stop? finish(cancelled)+del state      │
                         │  lock ─ ocupado? finish(failed)+release+error │
                         │  deadline propio; procesa ítems                │
                         │  import_single_item_public($item, R)          │
                         │  Heartbeat::set(R,…) + Runs::update_progress  │
                         │  done? finish(completed)+del cursor+del state │
                         │  paused? persiste offset y responde paused    │
                         └───────────────────────────────────────────────┘

  Cron ─────────────────▶ Run_Context::wrap('cron_sync_all', fn, 'cron')
                         │  (idéntico a Runs::track de hoy)
                         └─▶ Products/Customers/Categories::import_from_alegra(..., $run_id)

  Webhook ──────────────▶ Receiver: Run_Context::wrap('webhook_item', process_event, 'webhook_alegra')
```

### 2.3 El chunked multi-request: dónde vive el `run_id`

**Decisión:** el `run_id` se persiste en el transient `alegra_batch_state` (ya existe, TTL 600 s,
ya es el estado que cruza requests). No se crea una fila por página ni un transient dedicado.

**Por qué no las alternativas:**
- *Fila por página*: infla `wp_alegra_runs` con una fila cada 20 s y rompe la semántica "un run
  = un proceso" del Monitor.
- *Transient dedicado*: duplica el ciclo de vida del estado sin beneficio; el batch_state ya se
  limpia al terminar/cancelar.

**Ciclo de vida del run chunked:**
1. `ajax_sync_start` crea la fila (`Run_Context::begin`) **después** del guard de conexión y
   **antes** del lock. Así el escenario REQ-MON-01 "lock ocupado ⇒ fila `failed`" funciona: la
   fila ya existe cuando `ajax_sync_page` no puede tomar el lock.
2. Cada `ajax_sync_page` hace `Run_Context::resume($state['run_id'], 'chunked_import')`.
3. `finish()` se llama **antes** de cada `wp_send_json_*` (ver hallazgo de plataforma):
   - página completa ⇒ `finish(completed)` + `delete_option(cursor)` + `delete_transient(state)`.
   - pausa por presupuesto ⇒ **no** se cierra; se persiste el estado y se responde `paused:true`.
   - cancel/stop ⇒ `finish(cancelled, 'Detenido por el usuario')` + borra state.
   - lock ocupado / WP_Error ⇒ `finish(failed, $motivo)` + borra state.
4. Si el navegador se cierra, no llega el próximo request.

**Abandono por navegador cerrado:** `Runs::mark_abandoned(int $grace_seconds = 180)` (nuevo),
llamado desde `currently_running()` **después** de `mark_stale()`:

```php
// Sólo aplica a runs multi-request (chunked). Un cron/manual vivo es un request vivo;
// si muere, lo cubre mark_stale() a los 3600 s.
public static function mark_abandoned(int $grace_seconds = 180): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'alegra_runs';
    // Mismo reloj con que Runs::start() guardó started_at (current_time('mysql')).
    $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $grace_seconds);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM $table WHERE status = 'running' AND run_type = 'chunked_import' AND started_at < %s LIMIT 50",
        $cutoff
    ));
    foreach ($rows as $row) {
        if (Heartbeat::get((int) $row->id) === null) {   // heartbeat TTL 120 s vencido
            $wpdb->update($table, [
                'status' => 'stale',
                'finished_at' => current_time('mysql'),
                'error_summary' => __('Proceso abandonado (pestaña cerrada o request interrumpido)', 'alegra-connector'),
            ], ['id' => (int) $row->id]);
        }
    }
}
```

**Bug de timezone de `mark_stale()` (corrección D4, T1.3b).** `started_at` se guarda con
`current_time('mysql')` (hora **local**) en `Runs.php:63`, pero `mark_stale()` compara contra
`gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS)` (UTC) en `Runs.php:195`. En un sitio
UTC−5 (America/Bogota) el `started_at` de un run recién arrancado es ~5 h menor que el cutoff ⇒
`started_at < cutoff` es **siempre true** ⇒ `mark_stale()` marca `stale` todo run `running` en el
primer poll del Monitor. Y como `currently_running()` llama `mark_stale()` **antes** de
`mark_abandoned()` (`Runs.php:179`), `mark_abandoned` ni ve la fila (`WHERE status='running'`). Se
corrige con el mismo reloj que `started_at` (T1.3b):

```php
// includes/Runs.php — mark_stale()
$cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::STALE_AFTER_SECONDS);
```

El harness fija `$GLOBALS['alegra_test_gmt_offset']` (seam nuevo en `current_time()`, default `0`)
para poder reproducir un sitio no-UTC y que el test de T1.3b sea un prove-it-catches real.

> **Modelo del harness (fuente única).** La emulación de `wp_alegra_runs` en
> `scripts/lib/wp-stubs.php` vive **entera** en **T1.1a** (`$GLOBALS['alegra_db']['wp_alegra_runs']`,
> con `insert`/`update`/`get_var`/`get_results`/`query`). `T7.1.a` es **aditivo**: sólo agrega
> `get_row()` y `_n()`; no redefine el modelo ni crea un segundo store.

**Por qué `stale` y no un status nuevo:** `mark_stale()` ya usa `stale` y el badge del Monitor
cae a `neutral`; se agrega el caso `stale` a `statusBadge()` (D1 §2.7). No hay cambio de schema.

### 2.4 Heartbeat con progreso

| Punto | Archivo | Payload |
|---|---|---|
| `begin` | `Run_Context` | `['step'=>'start','message'=>'Iniciando...']` |
| Página chunked | `ajax_sync_page` (nuevo, tras el loop) | `['step'=>'products','message'=>sprintf('Procesando productos... Página %d/%d',$page,$tp),'step_num'=>$page,'total_steps'=>$tp]` |
| Página manual/cron | `Products::import_from_alegra`, junto al `set_transient('alegra_sync_progress', …)` de `:1318` | `['step'=>'products','message'=>sprintf('Procesando productos... Página %d',$current_page)]` cuando `$run_id > 0` |
| Etapas cron | `Controller::run_cron_sync_inner` (`:173,212,240,266,277`) | sin cambios |
| Fin | `Run_Context::finish` | `Heartbeat::forget($run_id)` |

`Runs::update_progress($run_id, $items_done, $total_items)` acompaña en los mismos puntos
(por página/etapa, **nunca por ítem** — NFR-04). El Monitor ya lee `items_done`/`total_items`
(`Admin_Dashboard.php:3709-3710`) y `message`/`step` del heartbeat (`:3711-3712`).

### 2.5 "Detener" (REQ-MON-05)

**El bug de base:** `Heartbeat::clear` borra `alegra_run_stop_{id}` (`Heartbeat.php:70`), y
`ajax_kill_run` lo llama inmediatamente después de `request_stop`. Fix:

1. `Heartbeat::clear()` deja de borrar el transient de stop. Se agrega `Heartbeat::forget()`
   que borra **sólo** `alegra_run_{id}`. `Run_Context::finish` usa `forget()`; el stop se limpia
   con `delete_transient('alegra_run_stop_'.$id)` dentro de `finish` (después de que el flujo lo
   observó) o por TTL (300 s).
2. `ajax_kill_run` (`Admin_Dashboard.php:3756`): `Runs::request_stop($run_id)` + log + responder.
   **No** toca el heartbeat: el run sigue vivo hasta que el flujo lo ve.
3. Propagación del `run_id`:
   - **Manual/cron**: `Products/Customers/Categories::import_from_alegra(..., $run_id)` ya
     chequean `should_stop` cuando `$run_id > 0` (`Products.php:1298,1353`;
     `Customers.php:218`; `Categories.php:84`). Sólo falta que el `run_id` llegue
     (`Controller::import_from_alegra($type, $run_id)`).
   - **Chunked**: `ajax_sync_page` chequea `Runs::should_stop($run_id)` **al inicio de la página**
     y se pasa `$run_id` a `import_single_item_public($item, $run_id)` para cortar a mitad de
     página. `import_single_item_from_alegra` devuelve el centinela nuevo `'stopped'`; el loop
     hace `break` y cuenta como no-procesado.
4. Escenario borde "detener un run terminado": `should_stop` de un run `completed` devuelve
   false (transient ausente) y `finish` no cambia el estado porque el `UPDATE` de `Runs::finish`
   no filtra por `status='running'`… **corrección necesaria**: `Run_Context::finish` debe
   chequear primero `Runs::status($run_id)` y no re-finalizar un run ya cerrado (evita pisar
   `completed` con `cancelled`). Se agrega `Runs::status(int): ?string`.

### 2.6 Compatibilidad hacia atrás

- **Schema `wp_alegra_runs`**: sin cambios. `items_failed`, `error_summary`, `context_json` ya
  existen (`Schema.php:183-189`). Los tipos nuevos son **valores** de `run_type`, no columnas.
- **Cron**: `Run_Context::wrap` replica exactamente `Runs::track` (start → fn → completed /
  failed+rethrow). Cero cambio de resultado de negocio (NFR-02).
- **Filas viejas**: el Monitor seguirá mostrando `run_type` crudo para lo no mapeado (label map
  en §2.7).
- **Host**: transients y options nativos de WP. Nada de `exec`/cron real.

### 2.7 Monitor (REQ-MON-03/04/06)

- **Labels de origen** (`admin-monitor.php`, nuevo `runTypeLabel()`):
  `cron_sync_all|cron_*` → "Cron"; `manual_import` → "Manual"; `chunked_import` → "Chunked";
  `webhook_*` → "Webhook"; resto → el valor crudo. Se usa en `renderRunning` (`:119`) y
  `renderRecent` (`:180`).
- **Badge `stale`**: agregar `case 'stale': label = S.statusAbandoned;` en `statusBadge` (`:91-97`).
- **Estado vacío honesto**: los placeholders "Cargando..." (`:46,58,70`) se reemplazan por
  "No hay procesos activos / sin tareas cron / sin historial" **antes** del primer poll, y el
  `error` del `$.ajax` (`:206-208`) muestra un `showNotice(S.monitorError,'error')` en vez de
  silencio (REQ-MON-06 escenario borde).
- **Cron deshabilitado**: `ajax_monitor_status` agrega `sync_method` al payload; `renderCron`
  recibe `{cron:[], sync_method:'real-time'}` y muestra "La sincronización periódica está
  desactivada (método: solo tiempo real)" en vez de la lista vacía muda (`admin-monitor.php:51-61`).
- **`error_summary`**: ya se renderiza en el Historial (`:175,:186`). No hay que agregarlo; sólo
  asegurar que todo `finish` de fallo lo escriba (D1).

---

## 3. D2 — Arquitectura del logger

### 3.1 Contexto por run

**Decisión: (a) contexto estático por request inyectado en cada entrada, archivo único.**

```php
// Logger.php
private static int $run_id = 0;
private static string $run_type = '';

public static function set_run_context(int $run_id, string $run_type = ''): void
{
    self::$run_id = $run_id; self::$run_type = $run_type;
}
public static function clear_run_context(): void { self::$run_id = 0; self::$run_type = ''; }

// en write(), antes de serializar $context:
if (!isset($context['run_id'])) {
    if (self::$run_id > 0) $context = ['run_id' => self::$run_id] + $context;
    if (self::$run_type !== '') $context = ['run_type' => self::$run_type] + $context;
}
```

**Por qué no un archivo por run** (`alegra-sync-{suffix}-run-{id}-{date}.log`):
`get_logs()`/`get_log_files()` hacen `glob('*.log')` (`Logger.php:206,228,314`) y leen archivos
secuencialmente; un store con cron cada 15 min + chunked + webhooks genera cientos de archivos
por día → `glob` + `usort` + apertura por archivo degradan la página de Logs y complican
`clear_all_logs`/retención. La correlación run ↔ log la da el **campo `run_id` en cada línea**,
que es justo lo que pide REQ-LOG-04 y lo que permite `grep run_id=N`. La interleaved concurrente
(cron + manual) se resuelve por dato, no por archivo.

**Salida temprana sin run** (REQ-LOG-04 borde): `fail_early()` llama
`set_run_context(0, $origin)` → la entrada lleva `run_type` pero **no** `run_id`, y el mensaje lo
declara ("Import abortado antes de crear el run").

### 3.2 Loguear las salidas tempranas

El chunked y `ajax_import_from_api` retornan antes de construir el Controller. La llamada de log
va **dentro del handler**, en cada `return`:

| Punto | Handler:línea | Llamada |
|---|---|---|
| Conexión no testeada | `Admin_Dashboard.php:4081`, `:2024` (start) | `Run_Context::fail_early($origin, 'connection_not_tested')` |
| Tipo inválido | `:4086` | `fail_early($origin, 'invalid_type')` |
| Lock ocupado | `:4095`, `:2100` | `finish($run_id,'failed','Ya hay una sincronización en curso')` |
| Batch-state ausente | `:2079` | `fail_early('chunked_import','no_batch_state')` |
| `WP_Error` de `/items` | `:2116`, `:2148`, `:2184` | `finish($run_id,'failed',$wp_error->get_error_message())` |
| Cancelado | `:2084` | `finish($run_id,'cancelled','Cancelado por el usuario')` |
| Pausa por presupuesto | `Products.php:1304-1309` | `$this->logger->info('Products import paused…', ['cursor'=>$start])` (ya existe; `info()` es de instancia) |
| Tombstone | `Products.php:1432-1435` | ya existe; se agrega `run_id` por el contexto |
| Kill switch | `Products.php:1291`, `:1410` | ya existe; se agrega `run_id` por el contexto |

Regla: **ningún `wp_send_json_error`/`success` de un camino de import sin `message`** (NFR-06).
Se agrega `message` a los que hoy no lo tienen (`:2021`, `:2076`, `:3417`).

### 3.3 `clear_all_logs()`

```php
/**
 * Borra TODOS los archivos .log (no toca .htaccess ni index.php).
 * @return array{files:int, bytes:int}
 */
public function clear_all_logs(): array
{
    $this->ensure_dir();
    $files = glob($this->log_dir . '/*.log') ?: [];
    $deleted = 0; $bytes = 0;
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $size = (int) @filesize($file);
        if (@unlink($file)) { $deleted++; $bytes += $size; }
    }
    return ['files' => $deleted, 'bytes' => $bytes];
}
```

- **Devuelve `array{files,bytes}`**, no `int`: el conteo de archivos es lo que pide la spec
  ("conteo real de archivos eliminados"); los bytes dan una noción honesta del espacio liberado.
  Contar **entradas** exigiría leer todos los archivos antes de borrarlos (caro y sin valor una
  vez borrados) → se rechaza.
- **No escribe una entrada de log propia.** Hacerlo recrearía un archivo inmediatamente y la
  pantalla no quedaría vacía (REQ-LOG-07 escenario "la pantalla queda vacía"). La auditoría de la
  acción es el `admin_notice` de éxito; si se necesita rastro durable, va a `error_log` sólo bajo
  `WP_DEBUG`. Esto **desvía** del "log de la propia limpieza" de la propuesta §6: es imposible
  borrar todo y a la vez conservar el log del borrado en el mismo canal. Decisión explícita.
- **Handler** (`ajax_clear_logs`, `:2322`): `$res = $this->logger->clear_all_logs();` y
  `wp_send_json_success(['message' => sprintf(_n('%d archivo de log eliminado.', '%d archivos de log eliminados.', $res['files'], …), $res['files']), 'files' => $res['files']])`.
- **UI**: el botón pasa de "Limpiar antiguos" a **"Limpiar logs"** (`admin-logs.php:36`); el
  confirm (`admin.js:386-393` + `S.confirmClearLogs`) pasa a "Esto borrará TODOS los logs,
  incluido el de hoy. No se puede deshacer. ¿Continuar?".

### 3.4 Directorio no escribible (REQ-LOG-06)

- `Logger::write()` deja de ser silencioso: en el `catch` (`:162-168`) **persiste** el fallo:
  ```php
  update_option('alegra_connector_logger_write_failed', [
      'at' => time(), 'path' => $this->log_dir, 'error' => $e->getMessage(),
  ], false); // autoload no
  ```
  y en el camino de éxito, si la opción existe, la borra (auto-curación).
- Nuevos helpers: `get_log_dir(): string`, `is_writable(): bool` (dir + archivo, mismos checks
  de `:128-137`).
- Aviso admin: `Logger::render_write_failure_notice()` registrado en `alegra-connector.php`
  `on_plugins_loaded()` (junto a `init_logger()`, corre incluso sin WooCommerce). Sólo para
  `current_user_can('manage_options')`; lee la opción y muestra un `notice-error is-dismissible`
  con el **path** y el error. Si no hay opción → no hay aviso (escenario negativo).
- **Por qué una opción y no un static**: el fallo ocurre en cron/AJAX, donde `admin_notices` no
  se renderiza; un static muere con el request y el comerciante nunca lo ve.

### 3.5 Retención (NFR-03)

**Decisión: `clear_old_logs()` y el barrido de `Maintenance::run()` (`Maintenance.php:53-58`)
quedan intactos.** "Limpiar logs" es puntual y **no** toca
`alegra_connector_log_retention_days`. Los dos métodos coexisten:
`clear_old_logs(int)` (automático, por antigüedad) y `clear_all_logs()` (manual, total). La
retención sigue acotando el crecimiento; el borrado total no la desactiva (REQ-LOG-07 borde).

### 3.6 Ruta de logs visible (REQ-LOG-05 corregido)

- `templates/admin-logs.php`: la ruta se mueve **fuera** del `if (!empty($log_files))` y se
  renderiza con `esc_html($logger_dir)`, donde
  `$logger_dir = $this->logger ? $this->logger->get_log_dir() : ''` (`render_logs_page()`,
  `Admin_Dashboard.php:927`), es la ruta **absoluta real**. **No** se usa
  `(new Logger())->get_log_dir()`: en una instancia fresca `$log_dir` es `''` hasta que corre
  `ensure_dir()` (`Logger.php:18,48-55`); el controller **ya tiene** `$this->logger` inyectado, así
  que construir otra instancia es redundante y re-ejecuta `ensure_dir()`. Siempre visible, aunque
  no haya archivos, en un `<code>` copiable.

---

## 4. D3 — Rediseño del chunked

### 4.1 Resolver 60 s vs 240 s (REQ-IMP-03)

**Decisión: (c) presupuesto wall-clock propio por página, adaptativo al costo de cada ítem.**

- Nueva opción `alegra_connector_chunked_page_budget` (int, 10–40, default **20** s).
- `ajax_sync_page` calcula `$deadline = microtime(true) + $budget` y corta **antes de cada ítem**
  (`if (microtime(true) >= $deadline) { $paused = true; break; }`).
- `@set_time_limit(60)` se conserva como **backstop duro** (documentado como tal, no como
  defensa principal): el budget (≤40 s) + margen siempre entra en 60 s.
- El `per_page` sigue en 30 (máximo de la API); el presupuesto decide cuántos entran.
- El budget de 240 s de `Products::import_from_alegra` (`:1270`) **no aplica** al chunked; sigue
  gobernando cron/Importar (requests largos y de servidor, no de navegador).

**Modos de falla de las alternativas rechazadas:**
- **(a) bajar el conteo fijo por página**: no arregla el fatal, porque **un solo ítem** con muchas
  imágenes puede pasar cualquier límite de ítems; y degrada el rendimiento en páginas de ítems
  baratos. Impredecible.
- **(b) subir/eliminar el cap y confiar en 240 s**: una página puede durar 240 s → timeout de
  proxy/navegador (se ve colgado) y, si el host ignora `set_time_limit`, PHP muere por
  `max_execution_time` **a mitad de página** → exactamente el bug original.
- **(c) presupuesto adaptativo**: página acotada, reanudable, funciona en cualquier host.

**Reanudación sin perder ítems (NFR-01):** el estado del chunked pasa de `page` a
`start` + `offset`:

```php
$state = [
  'type' => 'products', 'run_id' => R,
  'start' => 1500,     // offset de ítem en la API (== cursor compartido)
  'offset' => 12,      // ítems YA procesados dentro de la página actual
  'per_page' => 30, 'processed' => 1512, 'total_items' => 5000,
  'imported' => …, 'updated' => …, 'skipped' => …, 'errors' => …,
  'images' => ['ok'=>…,'blocked'=>…,'download'=>…,'sideload'=>…,'deferred'=>…],
  'from_zero' => false, 'policy' => 'respect',
];
```

Al pausar a mitad de página: se persiste `offset` y **no** se avanza `start`. El próximo request
**re-fetch** la misma página y saltea los primeros `offset` ítems. Costo: **una llamada extra a
la API por pausa**, no por ítem (NFR-04 ok). Al completar la página: `start += per_page`,
`offset = 0`, `update_option($cursor_key, $start)`. Al terminar el catálogo (`count($items) <
per_page` o `empty($items)`): `delete_option($cursor_key)`, `finish(completed)`.

El `offset` se evita guardando los 30 ítems en el transient (tamaño + staleness) → se prefiere el
re-fetch. Alternativa rechazada.

### 4.2 Imágenes: inline con deadline (REQ-IMG-01/03)

**Decisión: (a) inline por ítem, con el deadline de página chequeado antes de cada ítem y
**antes de cada descarga de imagen**; las imágenes que no entran se reportan como diferidas.**

- `Products::import_product_images()` recibe el deadline (o lo lee de un static) y, antes de cada
  `download_and_attach_image()`, chequea el presupuesto; si se agotó, deja de descargar para ese
  ítem y suma a `deferred`.
- El ítem **igual se importa** (datos + las imágenes que entraron). Es honesto: el producto existe
  y el resumen dice "N imágenes no se descargaron". Un reimport futuro reintenta porque
  `download_and_attach_image` es idempotente por hash de URL (`:2183-2214`) y el producto ya
  existe (update path).
- El modo default `favorite` (`:1924`) baja **una** imagen por producto → el caso patológico
  (muchas imágenes) es poco frecuente.

**Alternativas rechazadas:**
- **(b) segunda pasada diferida**: el producto queda transitoriamente sin imágenes (el síntoma
  textual del comerciante), y o duplica el listado de la API o exige una cola de URLs en
  transient (tamaño/staleness) + complejiza el cursor.
- **(c) job de fondo**: WP-cron/Action Scheduler no está garantizado en cualquier host (restricción
  distribuida), agrega dependencia y rompe el progreso visible en el mismo modal.

### 4.3 Progreso visible (REQ-IMP-04)

Se reutiliza el patrón de "Facturar pendientes" (`admin.js:699-774` + modal de `footer.php:6-19`):
`.sync-progress-fill`, `.sync-progress-label`, `.sync-counters`, `#sync-elapsed`,
`.sync-cancel-btn`. Cambios:
- El cliente deja de llevar `page`: el **servidor es dueño del cursor**. El JS llama
  `alegra_sync_page` en loop hasta `d.done`.
- En `d.paused`: `$label.text(S.pausedResuming)` y sigue (sin esperar); la barra usa `d.percent`.
- Contadores: `imported | updated | skipped | errors` (ya está en `:243`). Al terminar, resumen
  con totales + aviso de imágenes fallidas (si `d.images.failed > 0`).

### 4.4 Fail-loud del JS (REQ-IMP-01/02)

**Contrato de error:** el servidor **siempre** devuelve `{success, data:{message, …}}` en ambas
ramas. El JS usa `safeMsg(r, fallback)` (ya existe, `admin.js:35-37`).

Las tres ramas mudas (`admin.js:220,:238,:278`) se reescriben:

```js
// :218-227 (start)
success: function(r) {
    currentRequest = null;
    if (cancelled) { cleanup(); return; }                 // el cancel ya avisó (botón Cancelar)
    if (!r.success) {
        cleanup();
        showNotice(safeMsg(r, S.startError), 'error');
        $btn.prop('disabled', false).text(S.retry);        // hoy queda deshabilitado
        return;
    }
    …
}
// :235-238 (página)
if (cancelled) { cleanup(); return; }
if (!r.success) {
    cleanup();
    showNotice(safeMsg(r, S.error), 'error');
    $btn.prop('disabled', false).text(S.retry);
    return;
}
// :272-280 (terminal tras reintentos)
error: function() {
    retries++;
    if (retries <= maxRetries) { $counters.text(fmt(S.retrying, retries, maxRetries)); setTimeout(function(){ processPage(); }, 2000); }
    else { cleanup(); showNotice(S.connectionError, 'error'); $btn.prop('disabled', false).text(S.retry); }
}
```

**Sobrevivir al `cleanup()`:** `cleanup()` sólo hace `$modal.hide()` (esconde el modal de
progreso, `footer.php:7`); `showNotice` inyecta el aviso en `.alegra-connector-wrap`
(`admin.js:25-33`), **fuera** del modal. Por eso el aviso queda visible tras cerrar el modal.
Regla de orden: `cleanup()` primero, `showNotice` después (o indistinto; son nodos distintos).

**Chequeo estático de REQ-IMP-01:** separar `if (cancelled)` de `if (!r.success)` garantiza que
ninguna rama de error/`!r.success` llama `cleanup()` sin `showNotice` en la misma rama.

**Strings nuevas en `get_script_strings()`** (`Admin_Dashboard.php:652+`):
`startError` (ya), `pausedResuming`, `retry` (ya), `monitorError`, `statusAbandoned`,
`confirmReimport`, `imagesFailed`, `resumingFrom`, `startingFromZero`, `confirmRecreateManual`.
**No** se agrega `confirmClearAll`: el borrado total reusa la clave **existente**
`confirmClearLogs` (`Admin_Dashboard.php:705`, consumida por `admin.js:387`), de la que **sólo
cambia su VALOR** (`'¿Eliminar logs antiguos?'` → `'Esto borrará TODOS los logs, incluido el de
hoy. No se puede deshacer. ¿Continuar?'`). `languages/*` NO se toca (restricción): los `.pot` se
regeneran aparte.

---

## 5. D4 — Reanudar vs reimportar

### 5.1 Los dos botones

| Botón | Semántica | Cursor | Tombstones | Confirmación |
|---|---|---|---|---|
| **"Traer desde Alegra"** (existente) | Incremental: reanuda desde el cursor; si no hay, desde 0. Actualiza existentes, crea faltantes. | Lee `alegra_connector_products_import_cursor`; lo persiste por página; lo borra al completar. | `respect` (respeta todos) | No |
| **"Reimportar todo desde cero"** (nuevo) | Destructivo controlado: borra el cursor, corre desde 0. | `delete_option(cursor)` en `ajax_sync_start` (sólo `type='products'`). | Ver §5.2 | Sí, con copy destructivo + checkbox |

- `ajax_sync_start` acepta `from_zero` (bool) y `recreate_manual` (bool) en `$_POST`.
- Indicador de cursor en `templates/admin-products.php` (arriba de la barra de acciones):
  ```php
  $cursor = (int) get_option('alegra_connector_products_import_cursor', 0);
  $import_total = (int) get_option('alegra_connector_products_import_total', 0);
  if ($cursor > 0): ?>
      <span class="ac-badge warning">
        <?php echo esc_html(sprintf(__('Pausado en el ítem %1$s de %2$s. "Traer desde Alegra" continúa desde ahí.', 'alegra-connector'), number_format_i18n($cursor), $import_total ?: '?')); ?>
      </span>
  <?php endif; ?>
  ```
- `alegra_connector_products_import_total` (opción interna, autoload no): la escribe
  `ajax_sync_start` (conoce el total por el `metadata=true`); la borra quien borra el cursor.
  El cron no la conoce → muestra "de ?" (honesto).
- El botón destructivo es visualmente distinto (`ac-btn-danger`) y su `confirm()` explica que
  reinicia y que puede recrear productos borrados.

### 5.2 El fork de tombstones (REQ-RES-03) — decisión

**Problema:** `on_post_delete` escribe siempre `reason='manual_wc'` (`:54`), así que borrado
masivo y borrado individual son indistinguibles. La propuesta proponía "separar por `reason`";
la spec notó que eso solo no alcanza.

**Decisión: tercera opción — clasificación best-effort del borrado masivo en el request +
checkbox explícito en "desde cero".**

**Parte 1 — `bulk_wc` en el momento del borrado.** WooCommerce usa la lista de WP: el borrado
individual llega como `action=delete&post=123` (escalar), el masivo como
`action=delete&post[]=1&post[]=2` (array), y "vaciar papelera" ("Empty Trash") **NO** llega como
`action=delete_all`: es un submit con `name="delete_all"`/`delete_all2` y `action` en `-1`. El
render del botón está en `wp-admin/includes/class-wp-posts-list-table.php:606`
(`submit_button('Empty Trash','apply','delete_all')`), y `WP_Posts_List_Table::current_action()`
(`:625-631`) devuelve `'delete_all'` mirando
`isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])`. La señal primaria es el
`isset`; `action2` (select de abajo) es el fallback.

```php
// Tombstone_Manager
private static function classify_delete_reason(): string
{
    if (!is_admin()) return 'manual_wc';

    // 1) "Empty Trash": señal PRIMARIA (WP NO manda action=delete_all).
    if (isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])) return 'bulk_wc';

    // 2) La acción viaja en `action` (select de arriba) o `action2` (abajo); WP ignora `-1`.
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';
    if ($action === '' || $action === '-1') {
        $action = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash((string) $_REQUEST['action2'])) : '';
    }
    if ($action === 'delete_all') return 'bulk_wc';   // defensivo: algunos plugins lo postean así

    // 3) Selección masiva: WP manda `post[]` (array). Un solo ítem cae en manual_wc.
    $post = $_REQUEST['post'] ?? null;
    if (is_array($post) && count($post) > 1) return 'bulk_wc';

    return 'manual_wc';
}
```

`on_post_delete` usa ese `reason`. El default seguro es `manual_wc` (WP-CLI/REST/programático no
tienen `$_REQUEST`). `reason VARCHAR(50)` no tiene enum (`Schema.php:159`) → **sin migración**.

**Parte 2 — política en "desde cero".** `Run_Context::$tombstone_policy`:

| Política | Tombstone `bulk_wc` | Tombstone `manual_wc` | Tombstone `alegra_deleted` |
|---|---|---|---|
| `respect` (default del botón "Traer") | respeta | respeta | respeta |
| `ignore_bulk` (checkbox **destildado**) | ignora/recrea | respeta | respeta |
| `ignore_all` (checkbox **tildado**, default) | ignora/recrea | ignora/recrea | respeta |

`import_single_item_from_alegra` (`:1429`) reemplaza el chequeo por
`Tombstone_Manager::exists_with_reason('item', $alegra_id)` + la política.

**Por qué el checkbox default es "recrear todo" (tildado) y no "preservar manual" (spec Rama B):**
el workflow documentado del comerciante es "borrar todo → reimportar". Si el heurístico
`bulk_wc` falla (otro plugin, REST, WP-CLI, un tema que borra en lote distinto), con default
`ignore_bulk` el comerciante borraría todo y "desde cero" **no** lo recrearía → el flujo central
queda roto. El botón ya es destructivo y confirmado: su propósito es reconstruir. El comerciante
que quiera preservar un borrado puntual **destilda** el checkbox (`ignore_bulk`). El botón
normal "Traer desde Alegra" **siempre** respeta tombstones (`respect`), así que un producto
borrado a propósito **no** resucita en un resume. Esto invierte la recomendación de la spec
(Rama B "manual requiere check"), pero es la única opción que garantiza el workflow real; se
documenta como decisión explícita.

**Implementación de `exists_with_reason`:**
```php
public static function exists_with_reason(string $alegra_type, string $alegra_id): ?string
{
    global $wpdb;
    $table = $wpdb->prefix . 'alegra_tombstones';
    $reason = $wpdb->get_var($wpdb->prepare(
        "SELECT reason FROM $table WHERE alegra_type=%s AND alegra_id=%s AND resurrected_at IS NULL LIMIT 1",
        $alegra_type, $alegra_id
    ));
    return $reason !== null ? (string) $reason : null;
}
```
`exists()` se conserva (delega en `exists_with_reason() !== null`) para no romper llamadores.

### 5.3 Dónde vive el cursor y cómo se limpia

- Cursor: opción `alegra_connector_products_import_cursor` — leída en `Products.php:1264`,
  escrita/borrada en `:1373-1381`. **Compartido** entre el chunked y `import_from_alegra`
  (cron/Importar), para que ambos reanuden el mismo punto.
- "Desde cero": `ajax_sync_start` con `from_zero` hace, **sólo si `type==='products'`**:
  `delete_option($cursor_key); delete_option('alegra_connector_products_import_total');`
  y arranca `start=0`. El `delete_option` es atómico y seguro. Se hace en `start` (no en page 1)
  para que un cron concurrente no reanude el cursor viejo. Tradeoff: si el comerciante confirma
  desde cero y el navegador muere antes de la página 1, el cursor quedó en 0 (próximo "Traer"
  arranca de cero). Aceptable: eligió desde cero.

---

## 6. D5 — Imágenes

### 6.1 Extensión por mime real (REQ-IMG-02)

Reemplazar el `.jpg` hardcodeado (`Products.php:2231`, `:2393`) por la extensión derivada del
contenido descargado:

```php
private static function extension_from_mime(string $mime): string
{
    $map = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp',
        'image/tiff' => 'tiff', 'image/svg+xml' => 'svg',
    ];
    return $map[strtolower($mime)] ?? 'jpg';
}

// en el sideload, tras download_url():
$fallback = 'alegra-' . $product_id . '-' . substr($url_hash, 0, 8);
$check = wp_check_filetype_and_ext($tmp, $fallback . '.jpg');   // usa contenido real
$ext = !empty($check['ext']) ? $check['ext'] : self::extension_from_mime(
    (string) (wp_get_image_mime($tmp) ?: mime_content_type($tmp))
);
$file_array = ['name' => $fallback . '.' . $ext, 'tmp_name' => $tmp];
```

`wp_check_filetype_and_ext` es el helper canónico de WP: valida el mime real y devuelve la
extensión correcta; si no puede, cae al mapa por mime y, último recurso, `.jpg` (preserva el
comportamiento actual). Aplica a los dos puntos vivos. El método muerto de
`Admin_Dashboard::import_product_image` (`:108-144`) **se borra** (hallazgo #3): elimina el
tercer `.jpg` y la tercera allowlist.

### 6.2 Reportar fallos de imagen (REQ-IMG-03)

- `Products` gana un acumulador estático por request:
  ```php
  private static array $image_stats = ['ok'=>0,'blocked'=>0,'download'=>0,'sideload'=>0,'deferred'=>0];
  public static function reset_image_stats(): void;
  public static function image_stats(): array;   // ['failed' => blocked+download+sideload, ...]
  ```
- `download_and_attach_image` y `import_product_image` incrementan `ok`/`blocked`/`download`/
  `sideload`/`deferred` en cada rama (incluidas las que hoy sólo loguean: `:2170-2176`,
  `:2221-2228`, `:2236-2244`, `:2374-2380`, `:2386-2390`, `:2400-2402`).
- `import_from_alegra` resetea al inicio y devuelve `$result['images'] = self::image_stats()`.
- `ajax_sync_page` resetea al inicio de la página, y tras el loop hace
  `$state['images'] = merge_stats($state['images'], Products::image_stats())`; el response incluye
  `images`.
- `ajax_import_from_api` incluye `'images' => $result['images'] ?? []`.
- **UI**: si `images.failed > 0`, el resumen del modal y un `showNotice` de tipo `warning`
  muestran: "N imágenes no se pudieron importar (M host no permitido, K fallo de descarga, J
  fallo al adjuntar, D diferidas)". Sin fallos → sin alarma (escenario borde). Log por imagen ya
  existe (REQ-LOG-03); se mantiene.

### 6.3 Allowlist de hosts (REQ-IMG-04, BLOQUEADO)

**Decisión: allowlist configurable por el comerciante desde la UI, sin hardcodear el host en el
plugin distribuido.**

- Nueva opción `alegra_connector_allowed_image_hosts_extra` (array de hostnames), editable en
  **Ajustes → Avanzado** (textarea, un host por línea).
- `Products::allowed_image_hosts()` pasa a:
  ```php
  public static function allowed_image_hosts(): array
  {
      $base = ['alegra.com'];
      $extra = (array) get_option('alegra_connector_allowed_image_hosts_extra', []);
      $hosts = array_values(array_unique(array_merge($base, $extra)));
      $filtered = apply_filters('alegra_connector_allowed_image_hosts', $hosts);
      return is_array($filtered) ? $filtered : $hosts;
  }
  ```
- **Sanitizer** (`Admin_Dashboard::sanitize_image_hosts`): por línea, `trim`, `strtolower`,
  quitar esquema si vino, aceptar sólo `/^[a-z0-9.-]+\.[a-z]{2,}$/`, **rechazar `*`, `/`, `:`**.
  Así la allowlist nunca se amplía a comodín y `is_allowed_image_url` sigue exigiendo https
  (NFR-07). El filtro existente (`Products.php:2128`) se conserva para integradores.
- **Fase 0 (check exacto):** en la instalación del comerciante, capturar la URL real de
  `images[].url` de `GET /items` (o el meta `_alegra_image_url` de un adjunto) y correr
  `Products::is_allowed_image_url($url)`, registrando el host.
  - **Rama A** (host `*.alegra.com`): no se toca la allowlist; se cierra como falsa alarma.
  - **Rama B** (host fuera): el comerciante agrega el host observado en la UI; se documenta en
    `CHANGELOG.md`/nota de release **sin** ponerlo en el código. Se agrega prueba de regresión
    (`is_allowed_image_url` true para el host observado, false para `http://` y `*`).

---

## 7. D6 — Default de `sync_products` (REQ-CON-01/02, BLOQUEADO)

**REQ-CON-01 (ACTIVO).** `Controller.php:171` hoy saltea el bloque de productos en silencio
cuando `sync_products=false`. Fix: `else` que loguea
`'Cron sync: products skipped by configuration (sync_products=false)'` y agrega
`$result['products_skipped'] = 'config'` al resumen del cron (visible en el log/run). Las rutas
manual y webhook no dependen de ese flag y siguen funcionando (escenario borde).

**REQ-CON-02 (BLOQUEADO).** Marco de decisión:

| Rama | Qué pasa | Riesgo | Migración |
|---|---|---|---|
| **A — queda `false` (recomendada)** | El cron no importa productos salvo toggle; el comerciante usa el botón manual o webhooks. | Ninguno: no cambia comportamiento. | Ninguna. |
| **B — cambia a `true`** | El cron importa productos por defecto mientras maduran los webhooks. | Instalaciones que nunca tocaron la opción empiezan a importar el catálogo completo (red, cuota API, posibles sobreescrituras). | Sembrar `true` **sólo si la opción está ausente** (`get_option(...) === false`), respetar `false` explícito, + nota de release. |

- **Recomendación: Rama A.** Coincide con la propuesta §6 ("no se cambia salvo decisión
  explícita; se coordina con `config-gates`") y con el modelo del comerciante ("mayormente
  manual, cron temporal").
- **Una sola fuente de verdad:** hoy el default `false` está duplicado en
  `alegra-connector.php:424`, `Controller.php:171` y la UI (`admin-settings.php:78-81`, que
  `config-gates` corrige). Tras `config-gates`, los tres coinciden en `false`. El diseño agrega
  una **aserción de regresión** (en `scripts/exec-test.php`, que sí existe) de que
  `get_option('alegra_connector_sync_products', false) === false` y que la UI lo refleja.
- **Decisión del comerciante** (Fase 0): `get_option('alegra_connector_sync_products')` en su
  tienda + coordinar con `config-gates` antes de cerrar la rama.

---

## 8. APIs nuevas (firmas exactas)

```php
// includes/Run_Context.php (NUEVA)
final class Run_Context {
    public static function begin(string $type, ?string $started_by = null, array $context = []): int;
    public static function resume(int $run_id, string $type): void;
    public static function finish(int $run_id, string $status = 'completed', ?string $error = null): void;
    public static function fail_early(string $origin, string $reason, array $context = []): void;
    public static function wrap(string $type, callable $fn, ?string $started_by = null, array $context = []): mixed;
    public static function current(): int;
    public static function should_stop(): bool;
    public static function set_tombstone_policy(string $policy): void;
    public static function tombstone_policy(): string;
}

// includes/Runs.php
public static function mark_abandoned(int $grace_seconds = 180): void;
public static function status(int $run_id): ?string;                 // para no re-finalizar

// includes/Heartbeat.php
public static function forget(int $run_id): void;                    // borra sólo alegra_run_{id}
// clear() deja de borrar alegra_run_stop_{id}

// logger/Logger/Logger.php
public static function set_run_context(int $run_id, string $run_type = ''): void;
public static function clear_run_context(): void;
public function clear_all_logs(): array;                             // ['files'=>int,'bytes'=>int]
public function get_log_dir(): string;
public function is_writable(): bool;
public static function render_write_failure_notice(): void;

// includes/Sync/Products.php
public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array|\WP_Error;
public function import_single_item_public(array $item, int $run_id = 0): bool|string;   // 'stopped' nuevo centinela
private function import_single_item_from_alegra(array $item, int $run_id = 0): bool|string;
public static function reset_image_stats(): void;
public static function image_stats(): array;
public static function allowed_image_hosts(): array;                 // mergea opción

// includes/Sync/Controller.php
public function import_from_alegra(string $type, int $run_id = 0): array|\WP_Error;     // propaga run_id

// includes/Tombstone_Manager.php
public static function exists_with_reason(string $alegra_type, string $alegra_id): ?string;

// admin/Admin/Admin_Dashboard.php
private static function sanitize_image_hosts(mixed $value): array;
// AJAX nuevos/modificados: alegra_sync_start, alegra_sync_page, alegra_import_from_api,
//   alegra_clear_logs, alegra_monitor_status (mismos action names; cambia el payload)
```

**Payload de `alegra_sync_page` (respuesta):**
```php
[
  'page' => int, 'total_pages' => int, 'total_items' => int,
  'imported' => int, 'updated' => int, 'skipped' => int, 'errors' => int,
  'processed' => int, 'percent' => int,
  'paused' => bool, 'done' => bool,
  'images' => ['ok'=>int,'blocked'=>int,'download'=>int,'sideload'=>int,'deferred'=>int,'failed'=>int],
  'message' => string,   // SIEMPRE
]
```

**Payload de `alegra_sync_start` (respuesta):** `total_pages`, `total_items`, `per_page`,
`run_id`, `start`, `resuming` (bool), `message`.

---

## 9. DB / opciones / migración

**Schema: SIN CAMBIOS.**
- `wp_alegra_runs` ya tiene `run_type`, `status`, `started_by`, `items_done`, `items_failed`,
  `context_json`, `error_summary` (`Schema.php:176-193`). Los orígenes nuevos son valores de
  `run_type`; `stale` ya es un valor de `status` usado por `mark_stale()`.
- `wp_alegra_tombstones.reason` es `VARCHAR(50)` sin enum (`Schema.php:159`) → `bulk_wc` entra sin
  migración.

**Opciones nuevas:**

| Opción | Tipo | Default | `register_setting` | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|---|---|
| `alegra_connector_chunked_page_budget` | int 10..40 | `20` | sí (settings) | sí | sí | sí |
| `alegra_connector_allowed_image_hosts_extra` | array<string> | `[]` | sí (settings) | sí | sí | sí |
| `alegra_connector_products_import_total` | int | `0` | no (interno) | no | sí | sí |
| `alegra_connector_logger_write_failed` | array | ausente | no (interno) | no | sí | sí |

**Migración para instalaciones existentes:** **no hace falta** un `maybe_migrate` para estas
opciones: se leen con default (`get_option($k, $default)`), así que una instalación sin la opción
usa el default. Se agregan a `$defaults` (siembra en activación) y a `$non_autoload` por higiene,
y a `uninstall.php` para limpieza. El comportamiento del `from_zero`/tombstones **no** requiere
migración de datos: los tombstones existentes son `manual_wc` y el default del botón normal es
`respect` (idéntico a hoy).

---

## 10. Compatibilidad y plugin distribuido

- **Contrato de respuesta (NFR-06):** se mantiene `success`/`data`; se agrega `message` **siempre**
  en ambas ramas. Consumidores que hoy ignoran `message` no se rompen.
- **Cambio de semántica "Limpiar logs"** (antes: viejos; ahora: todo): **intencional**, con
  confirmación y nota en `CHANGELOG.md`.
- **Cron/webhooks (NFR-02):** el cron usa `Run_Context::wrap` con la misma semántica de
  `Runs::track`; el webhook agrega un run pero no cambia el resultado de negocio ni la respuesta
  200. `Receiver` mantiene el ACK aunque el handler falle.
- **Sin supuestos de host (NFR-05):** el chunked corta por presupuesto propio + reanudación; no
  depende de `set_time_limit` efectivo, `exec` ni cron real. El Monitor degrada: si el AJAX falla,
  muestra error (no "Cargando..." infinito).
- **Filas viejas de `Runs`:** siguen renderizándose; el label map cae al valor crudo.
- **`Products::allowed_image_hosts`:** sin la opción nueva devuelve exactamente `['alegra.com']`
  (comportamiento actual). Sin regresión.
- **`exists()`:** se conserva delegando en `exists_with_reason()`.

---

## 11. Registro de riesgos de diseño (DR1–DR11)

> **Nota de namespace.** Estos son riesgos **de diseño** (`DR1–DR11`). La **matriz autoritativa de
> riesgos de ejecución/regresión** es `R1–R15` y vive en `tasks/fase-7-regresion-release.md`
> §`T7.3` (cada R con su test concreto). No confundir `DR11` (timezone de `mark_stale`) con el `R11`
> de fase-7 (fuga de `run_id`).

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| DR1 | `wp_send_json_*` mata el request y no corre `finally` → lock/run colgados | Alta (hoy) | Alto | Cerrar run y liberar lock **antes** de cada `wp_send_json_*`; `finally` sólo para excepciones |
| DR2 | El heurístico `bulk_wc` no detecta el borrado masivo en algún flujo | Media | Alto | Checkbox de "desde cero" con default **recrear todo**; el botón normal siempre respeta; Fase 0 reproduce el flujo real |
| DR3 | Re-fetch por pausa duplica llamadas a la API en catálogos con muchas pausas | Media | Bajo | A lo sumo 1 llamada extra por pausa (no por ítem); budget de 20 s procesa varios ítems por página |
| DR4 | Imágenes diferidas dejan productos parcialmente sin imagen | Baja | Medio | Default `favorite` (1 imagen); se reporta "N imágenes diferidas"; el reimport las reintenta (dedup por hash) |
| DR5 | `clear_all_logs` borra evidencia | Media | Medio | Confirmación explícita, copy claro, no desactiva retención |
| DR6 | Crear un run por webhook infla `wp_alegra_runs` | Media | Bajo | Sólo eventos despachados (no ignorados); `Maintenance::prune` los borra por retención |
| DR7 | Ampliar la allowlist de imágenes reintroduce SSRF | Baja | Alto | Sanitizer rechaza `*`, `/`, `:`; https obligatorio; sólo hosts verificados por el comerciante |
| DR8 | Cambiar el default de `sync_products` a `true` (Rama B) importa catálogo no deseado | Media | Alto | Recomendación Rama A; si B, sembrar sólo si ausente + release note |
| DR9 | `Run_Context::finish` re-finaliza un run ya cerrado y pisa `completed` | Media | Medio | `Runs::status()` guard antes de finalizar |
| DR10 | El logger con contexto estático filtra `run_id` a logs fuera del run | Baja | Bajo | `finish`/`fail_early` limpian el contexto; contexto se setea al inicio de cada request de import |
| DR11 | `mark_stale()` usa cutoff UTC contra `started_at` local ⇒ marca `stale` runs vivos en sitios con offset ≠ 0, antes de que corra `mark_abandoned` (D4) | Alta (hoy, en UTC−) | Alto | `current_time('timestamp')` en el cutoff (T1.3b) + test con `$GLOBALS['alegra_test_gmt_offset']` (T28.18) |

---

## 12. Fase 0 — gates de verificación

| Gate | Requerimiento | Check exacto | Rama A | Rama B | De qué depende el diseño |
|---|---|---|---|---|---|
| G1 | REQ-IMG-04 | Capturar `images[].url` real en la tienda y correr `Products::is_allowed_image_url($url)`; registrar host | Host en `*.alegra.com` → sin cambio | Host fuera → UI `allowed_image_hosts_extra` + nota release | §6.3; si es A, no se toca la allowlist |
| G2 | REQ-RES-03 | `SELECT reason, COUNT(*) FROM wp_alegra_tombstones WHERE alegra_type='item' GROUP BY reason;` + preguntar al comerciante cómo borró + reproducir la forma de `$_REQUEST` del bulk delete de WC | Ignorar todos los `item` | Sólo masivo; `manual_wc` con check | §5.2; con la tercera opción, la rama A/B define el **default** del checkbox |
| G3 | REQ-CON-02 | `get_option('alegra_connector_sync_products')` + coordinar con `config-gates` | Queda `false` (recomendada) | Cambia a `true` + migración + release note | §7 |
| G4 (extra) | Plataforma | Confirmar que `wp_send_json_*` termina el request (comportamiento WP estándar) | — | — | §2.3/§8: cierre explícito antes de cada send |

---

## 13. Matriz de archivos (resumen operativo)

| Archivo | Acción | Requerimientos |
|---|---|---|
| `includes/Run_Context.php` | **crear** | MON-01/02/05, LOG-01/04, IMP-01/03, RES-02/03, CON-01, NFR-02 |
| `includes/Runs.php` | editar (`mark_abandoned`, `status`, fix timezone de `mark_stale`) | MON-01/02/06 |
| `includes/Heartbeat.php` | editar (`clear`/`forget`) | MON-02/05 |
| `logger/Logger/Logger.php` | editar (`set_run_context`, `clear_run_context`, `clear_all_logs`, `get_log_dir`, `is_writable`, `render_write_failure_notice`) | LOG-04/05/06/07, NFR-03 |
| `includes/Sync/Products.php` | editar (budget, offset, run_id, stop, stats imagen, mime, allowlist) | IMP-03/04, RES-01/04, IMG-01..04, LOG-01/02/03 |
| `includes/Sync/Controller.php` | editar (propaga run_id, `Run_Context::wrap`, log `sync_products=false`) | MON-01/02, CON-01, NFR-02 |
| `includes/Tombstone_Manager.php` | editar (`classify_delete_reason`, `exists_with_reason`) | RES-03 |
| `includes/Webhooks/Receiver.php` | editar (wrap del run) | MON-01, NFR-02 |
| `admin/Admin/Admin_Dashboard.php` | editar (handlers, opciones, strings, borrar método muerto) | MON-03/04/06, LOG-01/05/06/07, IMP-01..04, RES-01/02, IMG-02/03, CON-01 |
| `admin/assets/js/admin.js` | editar (fail-loud, progreso/pausa, dos botones, confirm) | IMP-01/02/04, RES-02, LOG-07 |
| `templates/admin-products.php` | editar (segundo botón, indicador de cursor) | RES-01/02, C1/C2 |
| `templates/admin-logs.php` | editar (label, ruta absoluta, copy) | LOG-05/07 |
| `templates/admin-monitor.php` | editar (badges, labels, vacío/error) | MON-03/04/06 |
| `templates/admin-settings.php` | editar (budget de página, hosts extra) | IMP-03, IMG-04 |
| `alegra-connector.php` | editar (`$defaults`/`$non_autoload`, `admin_notices` logger) | LOG-06, CON-02 |
| `uninstall.php` | editar (opciones nuevas) | LOG-06/07, IMP-03, IMG-04 |
| `CHANGELOG.md` | editar (nota de comportamiento) | NFR-06 |
