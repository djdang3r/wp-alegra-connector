# Tareas micro-detalladas — Fase 1 (cimientos de observabilidad)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Alcance de este archivo | **Fase 1 — T1.1a, T1.1b, T1.2, T1.3, T1.3b, T1.4, T1.5** (7 tasks) |
| Naturaleza | CORE, riesgo alto. Crea el punto único de verdad "hay un run" y destraba "Detener". |
| Depende de | **Nada de Fase 0.** Se puede empezar ya. |
| DoD de la fase | REQ-MON-01/02/05 y REQ-LOG-04 verdes; `Run_Context` carga por PSR-4; `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes. |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`) · `bash scripts/smoke-test.sh` (→ `scripts/smoke-load.php`) |

> **Convención de IDs de test (canónica).** Todo test nuevo del harness es `T28.{fase}{n}`:
> `{fase}` = dígito de la fase (1–7), `{n}` = secuencia 1-based dentro de la fase (1 o 2 dígitos;
> p. ej. `T28.11` = Fase 1 test 1, `T28.110` = Fase 1 test 10). Reemplaza a las convenciones
> previas `H1.x`/`T1.x`. En esta fase: `T28.11`–`T28.111`.

> **Regla de oro (prove-it-catches).** En cada test nuevo: test verde → **revertir el fix** →
> `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Si no,
> el test no se acepta. Las tareas de abajo indican el fix exacto a revertir.

> **Orden de build recomendado (el número de tarea NO es el orden de ejecución).**
> `T1.1a` → `T1.2` → `T1.3` → `T1.3b` → `T1.4` → `T1.1b` → `T1.5`.
> `Run_Context` (T1.1b) llama a `Logger::set_run_context` (T1.2), `Runs::status` (T1.3) y
> `Heartbeat::forget` (T1.4); por eso va después. El skeleton lista T1.1 primero pero su propio
> texto remite a métodos de T1.2–T1.4.

## Correcciones de cita / alcance detectadas (aplican a esta fase)

| # | Cita (skeleton/design) | Realidad verificada en HEAD | Corrección |
|---|---|---|---|
| F1 | Skeleton T1.1: "`finish()` … con guard `Runs::status`, **ver T1.4**". | `Runs::status()` es **T1.3**; T1.4 es `Heartbeat::forget`. | El guard `Runs::status` se implementa en **T1.3** y se consume en **T1.1b**; se testea en **T1.5**. |
| F2 | Skeleton T1.5 (Prove-it-catches): "volver a llamar `Heartbeat::clear` en `ajax_kill_run` → el test de **stop** falla (vía T1.4)". | Tras T1.4, `clear()` **deja de** borrar `alegra_run_stop_{id}`; por lo tanto la aserción `should_stop === true` **sigue pasando** aunque se re-agregue `clear()`. Lo que falla es la aserción de que el **heartbeat de display no fue limpiado** (`Heartbeat::get($R) !== null`). | El prove-it-catch de T1.5 se engancha en la aserción del heartbeat, no en la del stop. |
| F3 | Design §2.1 pseudo: dentro de `namespace Alegra\Connector` llama `Logger::set_run_context(...)`. | `Logger` es `Alegra\Connector\Logger\Logger`; sin importar resuelve al namespace y fatalea. | `includes/Run_Context.php` debe llevar `use Alegra\Connector\Logger\Logger;`. |
| F4 | Design §2.3 `mark_abandoned`: `$cutoff = gmdate('Y-m-d H:i:s', time() - $grace_seconds)`. | `started_at` se guarda con `current_time('mysql')` (hora local del sitio, `Runs.php:63`); `time()` es UTC. Desalinea la guarda de 180 s. | Usar `gmdate('Y-m-d H:i:s', current_time('timestamp') - $grace_seconds)` para comparar contra el mismo reloj con que se guardó `started_at`. |
| F4b | Design §2.3 corrige `mark_abandoned` **solo**, pero `mark_stale()` tiene el **mismo** bug de timezone (`Runs.php:195`: `gmdate('...', time() - self::STALE_AFTER_SECONDS)`) y corre **antes** (`currently_running()` lo llama en `:179`, preemptando a `mark_abandoned`). | En UTC−5 `started_at` (local) es ~5 h menor que el cutoff UTC ⇒ `started_at < cutoff` es siempre true ⇒ `mark_stale()` marca `stale` cualquier run `running` en el primer poll del Monitor. `mark_abandoned` ni ve la fila (`WHERE status='running'`). | **T1.3b** corrige `mark_stale()` con `current_time('timestamp') - self::STALE_AFTER_SECONDS` (mismo reloj que `started_at`) + test de offset no-UTC. |
| F5 | Skeleton T1.1: "`import_single_item_public(array $item, int $run_id = 0)`". | Firma actual: `import_single_item_public(array $item): bool|string` (`Products.php:2365`); el `$run_id` es **Fase 3** (T3.2). | Fase 1 **no** toca `Products`. Sólo se documenta que `Run_Context::should_stop()` se apoya en `Runs::should_stop`. |
| F6 | `tasks.md` "citas confirmadas": `Heartbeat.php:70` borra `alegra_run_stop_`; `Heartbeat::clear` en `:66-71`. | Confirmado (`Heartbeat.php:66-71`, `delete_transient('alegra_run_stop_' . $run_id)` en `:70`). `Heartbeat::clear` sólo se invoca en `Admin_Dashboard.php:3765`. | Sin corrección; el único llamador es `ajax_kill_run`, así que el cambio de semántica de `clear()` no rompe otros consumidores. |
| F7 | `tasks.md` "citas confirmadas": `Runs.php:174-206` (`currently_running`/`mark_stale`); `Runs::start` `:46-69`; `Runs::finish` `:95-118`; `Runs::request_stop`/`should_stop` `:123-134`. | Confirmado. `mark_stale()` es **private** (`:191`); `currently_running()` lo llama en `:179`. | `mark_abandoned()` será `public static` (design §8) y se llama desde `currently_running()` **después** de `mark_stale()`. |

---

### T1.1a — Harness: emular `wp_alegra_runs` en `Alegra_Mock_Wpdb`

**Objetivo**: darle al harness la capacidad de **leer y escribir** la tabla `wp_alegra_runs`, sin
la cual `Runs::status()`, `Runs::finish()` y `Runs::mark_abandoned()` son inobservables y ningún
test de transición de estado es real.

**Descripción técnica**: hoy el stub de `$wpdb` no conoce la tabla de runs:
- `insert()` apila la fila pero **no le asigna `id`** (`scripts/lib/wp-stubs.php:1725-1731`);
- `update()` es **no-op** (`:1733-1736`);
- `get_var()` sólo entiende `option_name`, `alegra_entity_map` y meta (`:1592-1626`);
- `get_results()` devuelve `[]` salvo el caso de `alegra_category_id` (`:1655-1671`);
- `query()` sólo procesa `alegra_entity_map` (`:1673-1710`).

`Runs::start()` usa `insert()` y `insert_id` (funciona); `Runs::finish()`/`update_progress()` usan
`update()` (no-op); `Runs::status()` (nuevo, T1.3) y `currently_running()`/`mark_stale()` usan
`get_var()`/`get_results()`/`query()` (no soportados). Sin esta task, los tests de T1.1b/T1.3/T1.5
no pueden verificar estados.

**Desarrollo técnico**:

- **Archivo:** `scripts/lib/wp-stubs.php` (clase `Alegra_Mock_Wpdb`).
- **1) `insert()` — asignar `id`.** Reemplazar el cuerpo actual (`:1725-1731`) por:
  ```php
  public function insert($table, $data = [], $format = null)
  {
      $table = (string) $table;
      $next_id = count($GLOBALS['alegra_db'][$table] ?? []) + 1;
      if (str_ends_with($table, 'alegra_runs')) {
          $data['id'] = $next_id;
      }
      $GLOBALS['alegra_db'][$table][] = $data;
      $this->insert_id = $next_id;
      return 1;
  }
  ```
  (Se mantiene el comportamiento actual para las demás tablas: no agrega `id`.)
- **2) `update()` — merge por `where`.** Reemplazar el no-op (`:1733-1736`) por:
  ```php
  public function update($table, $data = [], $where = [], $format = null, $where_format = null)
  {
      $table = (string) $table;
      if (empty($GLOBALS['alegra_db'][$table]) || !is_array($GLOBALS['alegra_db'][$table])) {
          return 1;
      }
      foreach ($GLOBALS['alegra_db'][$table] as $i => $row) {
          $match = true;
          foreach ((array) $where as $k => $v) {
              if ((string) ($row[$k] ?? '') !== (string) $v) { $match = false; break; }
          }
          if ($match) {
              $GLOBALS['alegra_db'][$table][$i] = array_merge((array) $row, $data);
          }
      }
      return 1;
  }
  ```
- **3) `get_var()` — `SELECT status … WHERE id = N`.** Agregar **antes** del `return null` final
  (`:1625`):
  ```php
  if (strpos($query, 'alegra_runs') !== false
      && preg_match('/SELECT\s+status/i', $query)
      && preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $query, $m)) {
      foreach (($GLOBALS['alegra_db']['wp_alegra_runs'] ?? []) as $row) {
          if ((int) ($row['id'] ?? 0) === (int) $m[1]) {
              return isset($row['status']) ? (string) $row['status'] : null;
          }
      }
      return null;
  }
  ```
- **4) `get_results()` — soporte de runs.** Agregar **antes** del `return []` final (`:1670`):
  ```php
  if (strpos($query, 'alegra_runs') !== false) {
      $rows = $GLOBALS['alegra_db']['wp_alegra_runs'] ?? [];
      if (preg_match("/status\s*=\s*'([^']+)'/", $query, $m)) {
          $rows = array_values(array_filter($rows, fn ($r) => ($r['status'] ?? '') === $m[1]));
      }
      if (preg_match("/run_type\s*=\s*'([^']+)'/", $query, $m)) {
          $rows = array_values(array_filter($rows, fn ($r) => ($r['run_type'] ?? '') === $m[1]));
      }
      if (preg_match("/started_at\s*<\s*'([^']+)'/", $query, $m)) {
          $rows = array_values(array_filter($rows, fn ($r) => ($r['started_at'] ?? '') < $m[1]));
      }
      if (preg_match('/LIMIT\s+(\d+)/i', $query, $m)) {
          $rows = array_slice($rows, 0, (int) $m[1]);
      }
      return array_map(static fn ($r) => (object) $r, $rows);
  }
  ```
- **5) `query()` — `UPDATE … SET status='stale' … WHERE … started_at < '…'`.** Agregar al inicio del
  cuerpo (`:1673`). **Debe respetar el predicado `started_at < cutoff`**; si no, `mark_stale()`
  marcaría stale cualquier run `running` y el test "el manual sigue running" fallaría:
  ```php
  if (stripos($query, 'alegra_runs') !== false && stripos($query, 'UPDATE') !== false) {
      $set_status = null;
      if (preg_match("/SET\s+status\s*=\s*'([^']+)'/i", $query, $m)) { $set_status = $m[1]; }
      $cutoff = null;
      if (preg_match("/started_at\s*<\s*'([^']+)'/", $query, $m)) { $cutoff = $m[1]; }
      $limit = 50;
      if (preg_match('/LIMIT\s+(\d+)/i', $query, $m)) { $limit = (int) $m[1]; }

      $n = 0;
      foreach (($GLOBALS['alegra_db']['wp_alegra_runs'] ?? []) as $i => $row) {
          if (($row['status'] ?? '') !== 'running') { continue; }
          if ($cutoff !== null && !(($row['started_at'] ?? '') < $cutoff)) { continue; }
          if ($set_status !== null) {
              $GLOBALS['alegra_db']['wp_alegra_runs'][$i]['status'] = $set_status;
          }
          if (++$n >= $limit) { break; }
      }
      return $n;
  }
  ```
  Con esto, `mark_stale()` (cutoff 3600 s) **no** toca una fila de 300 s, y `mark_abandoned()`
  (cutoff 180 s) **sí** marca la chunked. Es lo que hace significativo el test de T1.3.
- **Nota de alcance:** sólo se emula `alegra_runs`. **No** tocar `alegra_tombstones` en Fase 1
  (T1.3 no lo necesita; la emulación de tombstones, si hiciera falta, va en T4.3/T4.4).

**Resultado esperado**: insertar una fila con `Runs::start()`, finalizarla con `Runs::finish()` y
leer el `status` con `Runs::status()` refleja la transición real; `currently_running()` y
`mark_stale()`/`mark_abandoned()` operan sobre `$GLOBALS['alegra_db']['wp_alegra_runs']`.

**Dependencias**: ninguna. Es prerequisito de **T1.1b**, **T1.3**, **T1.5**.

**Trazabilidad**: infraestructura de verificación de REQ-MON-01/02/05/06.

**Verificación**: test nuevo en `scripts/exec-test.php`:
```php
TestRunner::test('T28.11 el stub wpdb emula wp_alegra_runs (insert/update/get_var)', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'x', 'status' => 'running', 'started_at' => current_time('mysql')]);
    TestRunner::assertSame('running', $wpdb->get_var("SELECT status FROM wp_alegra_runs WHERE id = 1"), 'la fila insertada debe leerse running');
    $wpdb->update($wpdb->prefix . 'alegra_runs', ['status' => 'completed'], ['id' => 1]);
    TestRunner::assertSame('completed', $wpdb->get_var("SELECT status FROM wp_alegra_runs WHERE id = 1"), 'el update debe persistir el status');
});
```
Correr `bash scripts/exec-test.sh` → `EXEC-TEST OK`.

**Verificación obligatoria de no-regresión (D-H del audit final Oracle).** El cambio de `update()`
de **no-op** (`wp-stubs.php:1733-1736`) a **merge global** es el cambio de harness de **mayor
superficie** del plan: muchos tests pueden depender de que `update()` no haga nada (p. ej.
`mark_resurrected`, entity-map). Pasos exactos:
1. Implementar `T1.1a`.
2. Correr `bash scripts/exec-test.sh`.
3. El resultado **DEBE** quedar en **≥ 1289 assertions / 0 failed** (baseline exacto del repo).
4. Si el conteo **baja** o aparece un `failed`, **no** ajustar el test en silencio: encontrar el test
   que dependía del no-op y **decidir explícitamente** (corregir el test si su supuesto quedó viejo,
   o acotar el cambio de `update()`). Documentar la decisión en el commit.

**Riesgo (D-H, UNVERIFIED hasta correrlo)**: sobre-emular y romper tests existentes que asumen
`update()` no-op o `get_results()` vacío. **Guarda primaria:** los nuevos branches filtran por
`alegra_runs`; correr el harness completo y comparar el conteo (no debe bajar de 1289). **Fallback
recomendado por Oracle:** si el merge global rompe tests, acotar el branch de `update()` a
`str_ends_with($table, 'alegra_runs')` en vez de mutar **todas** las tablas.

**Estimación**: M (1.5 h).

---

### T1.1b — Crear `includes/Run_Context.php`

**Objetivo**: crear el **punto único de verdad** de "hay un run": una clase que coordina `Runs`
(persistencia), `Heartbeat` (display) y `Logger` (trazabilidad), con estado request-scoped y la
política de tombstones. Ningún camino de importación llamará `Runs::start()` directo.

**Descripción técnica**: hoy `Runs::track()` es el único wrapper y se usa una sola vez, en el cron
(`Controller.php:150`). El manual y el chunked no crean fila (REQ-MON-01), no setean heartbeat
(REQ-MON-02), no propagan el stop (REQ-MON-05) y no inyectan `run_id` en el log (REQ-LOG-04). El
design §2.1 decide una clase nueva `Alegra\Connector\Run_Context` en `includes/Run_Context.php`
(autoloadable por el fallback genérico `includes/` del PSR-4, `alegra-connector.php:128-133`).
`Runs` queda repositorio puro (sin depender de Logger). Los tipos de run son **valores** de
`run_type`, no columnas: `cron_sync_all` (existente), `manual_import`, `chunked_import`,
`webhook_item`/`webhook_client`/`webhook_invoice` (design §2.1/§9). **Sin cambios de schema**
(`Schema.php:176-193` ya tiene `run_type`, `status`, `started_by`, `items_done`, `items_failed`,
`context_json`, `error_summary`).

**Desarrollo técnico**:

- **Archivo nuevo:** `includes/Run_Context.php`. Debe empezar con `<?php`,
  `declare(strict_types=1);`, `namespace Alegra\Connector;`, el guard
  `if (!defined('ABSPATH')) { exit; }` y `use Alegra\Connector\Logger\Logger;` (corrección F3).

- **Forma completa de la clase** (firmas exactas, design §8):
  ```php
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

- **Comportamiento de cada método (código concreto):**
  ```php
  public static function begin(string $type, ?string $started_by = null, array $context = []): int
  {
      $run_id = Runs::start($type, $started_by, $context);
      self::$run_id = $run_id;
      self::$type = $type;
      Logger::set_run_context($run_id, $type);           // D2 / T1.2
      Heartbeat::set($run_id, [
          'step' => 'start',
          'message' => __('Iniciando...', 'alegra-connector'),
      ]);
      return $run_id;
  }

  public static function resume(int $run_id, string $type): void
  {
      self::$run_id = $run_id;
      self::$type = $type;
      Logger::set_run_context($run_id, $type);
  }

  public static function finish(int $run_id, string $status = 'completed', ?string $error = null): void
  {
      $current = Runs::status($run_id);                   // T1.3
      if ($current !== null && $current !== 'running') {
          self::teardown($run_id);                        // ya cerrado: no re-finalizar (R9)
          return;
      }
      Runs::finish($run_id, $status, $error);
      self::teardown($run_id);
  }

  private static function teardown(int $run_id): void
  {
      delete_transient('alegra_run_stop_' . $run_id);     // el stop ya fue observado
      Heartbeat::forget($run_id);                         // T1.4: sólo el display
      Logger::clear_run_context();
      if (self::$run_id === $run_id) {
          self::$run_id = 0;
          self::$type = '';
      }
  }

  public static function fail_early(string $origin, string $reason, array $context = []): void
  {
      Logger::set_run_context(0, $origin);                // sin run_id: el run no llegó a existir
      // OJO: info() es de INSTANCIA (`Logger.php:171`, no `static`). Llamarlo como
      // `Logger::info(...)` es fatal en PHP 8 → HTTP 500 en cada salida temprana (justo
      // los caminos que REQ-LOG-01 viene a arreglar). Se usa una instancia.
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

  public static function current(): int
  {
      return self::$run_id;
  }

  public static function should_stop(): bool
  {
      return self::$run_id > 0 && Runs::should_stop(self::$run_id);
  }

  public static function set_tombstone_policy(string $policy): void
  {
      if (in_array($policy, ['respect', 'ignore_bulk', 'ignore_all'], true)) {
          self::$tombstone_policy = $policy;
      }
  }

  public static function tombstone_policy(): string
  {
      return self::$tombstone_policy;
  }
  ```
  **Notas de diseño:**
  - `wrap()` replica **exactamente** la semántica de `Runs::track` (`Runs.php:29-41`): start → fn →
    `completed`, o `failed` + rethrow. Cero cambio de negocio (NFR-02, R1).
  - `finish()` guarda contra re-finalizar (`Runs::status`); el `teardown()` limpia stop, heartbeat
    y contexto aunque el run ya estuviera cerrado, para no dejar transients colgados.
  - `should_stop()` **sin argumentos** (design §8): requiere que el request haya llamado
    `begin()` o `resume()` antes. En `ajax_sync_page`, el `resume($state['run_id'], ...)` va
    primero (T2.4).
  - `fail_early()` **no** crea fila: el run no existió; loguea con `run_type=$origin` y sin
    `run_id` (REQ-LOG-04 borde).
  - `set_tombstone_policy()` valida contra la allowlist de 3 valores; un valor inválido no cambia
    el default `respect` (la política se usa en T4.4).

- **Autoloader:** no requiere registro. `Alegra\Connector\Run_Context` → `Run_Context.php` cae en
  el candidato genérico `__DIR__ . '/includes/Run_Context.php'` (`alegra-connector.php:128-133`).

- **Smoke:** agregar en `scripts/smoke-load.php` (junto al check del Logger, ~`:183-201`):
  ```php
  check('Run_Context class loads',
      class_exists(\Alegra\Connector\Run_Context::class),
      '— la clase nueva debe resolver por el autoloader PSR-4');
  ```

**Resultado esperado**: `Run_Context::current() === 0` fuera de un run; `begin()` crea fila
`running` + heartbeat `start` + contexto de logger; `wrap()` deja `completed`/`failed`; `finish()`
no pisa un run ya cerrado. `Run_Context` carga por PSR-4.

**Dependencias**: **T1.1a** (harness), **T1.2** (`Logger::set_run_context`), **T1.3**
(`Runs::status`), **T1.4** (`Heartbeat::forget`).

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-MON-05, REQ-LOG-01, REQ-LOG-04, REQ-IMP-03,
REQ-RES-02, REQ-RES-03, REQ-CON-01, NFR-02.

**Verificación**: tests nuevos en `scripts/exec-test.php`:
```php
TestRunner::test('T28.12 Run_Context::begin crea fila running y current() la expone', function (): void {
    alegra_test_reset();
    $id = \Alegra\Connector\Run_Context::begin('manual_import', 'tester');
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'begin debe crear la fila running');
    TestRunner::assertSame($id, \Alegra\Connector\Run_Context::current(), 'current() debe devolver el run activo');
    \Alegra\Connector\Run_Context::finish($id, 'completed');
    TestRunner::assertSame(0, \Alegra\Connector\Run_Context::current(), 'fuera del run current() debe ser 0');
});

TestRunner::test('T28.13 Run_Context::wrap completa y propaga excepciones', function (): void {
    alegra_test_reset();
    $out = \Alegra\Connector\Run_Context::wrap('manual_import', fn ($rid) => 7, 'tester');
    TestRunner::assertSame(7, $out, 'wrap debe devolver el resultado del closure');

    alegra_test_reset();
    $threw = false;
    try {
        \Alegra\Connector\Run_Context::wrap('manual_import', function (): void { throw new \RuntimeException('boom'); }, 'tester');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    TestRunner::assertTrue($threw, 'wrap debe re-lanzar la excepción');
});

TestRunner::test('T28.14 Run_Context::current es 0 fuera de un run', function (): void {
    alegra_test_reset();
    TestRunner::assertSame(0, \Alegra\Connector\Run_Context::current(), 'sin begin/resume current() es 0');
});
```
Para verificar el estado `completed`/`failed` de los runs creados por `wrap`, extender los asserts
leyendo `$GLOBALS['alegra_db']['wp_alegra_runs'][0]['status']` (T1.1a) o capturar el id con un
closure que lo reciba.

**Riesgo**: que `wrap()` no replique exactamente `Runs::track` y cambie el resultado del cron
(R1). Guard: test de las 4 etapas en T2.1 + NFR-02 en T7.3; el código es copia literal de
`Runs::track`.

**Estimación**: L (4 h).

---

### T1.2 — `Logger::set_run_context`/`clear_run_context` + inyección en `write()`

**Objetivo**: que **cada línea de log** de un run lleve `run_id` y `run_type` en su contexto, para
poder correlacionar un run del Monitor con sus entradas (REQ-LOG-04) sin crear un archivo por run.

**Descripción técnica**: hoy `Logger::write()` (`logger/Logger/Logger.php:112-169`) serializa
`$context` tal cual (`:117`); ninguna entrada de import lleva `run_id` (design hallazgo #5:
`Products.php:1286/1299/1306` son los sitios, no el estado). El design §3.1 decide **contexto
estático por request inyectado en cada entrada, archivo único**: un `run_id` en cada línea es lo
que permite `grep run_id=N` y resuelve la concurrencia cron+manual por dato, no por archivo.
`Logger` ya usa statics en la práctica vía instancias múltiples; los nuevos statics son de clase.

**Desarrollo técnico**:

- **Archivo:** `logger/Logger/Logger.php`.
- **1) Propiedades nuevas** (junto a las de `:18-21`):
  ```php
  private static int $run_id = 0;
  private static string $run_type = '';
  ```
- **2) Métodos estáticos nuevos** (agregar antes de `write()`, `:112`):
  ```php
  public static function set_run_context(int $run_id, string $run_type = ''): void
  {
      self::$run_id = $run_id;
      self::$run_type = $run_type;
  }

  public static function clear_run_context(): void
  {
      self::$run_id = 0;
      self::$run_type = '';
  }
  ```
- **3) Inyección en `write()`.** **ANTES** de serializar (`:117`), insertar:
  ```php
  // ANTES (Logger.php:116-117)
  $timestamp = current_time('Y-m-d H:i:s');
  $context_str = !empty($context) ? ' ' . wp_json_encode($context) : '';

  // DESPUÉS (Logger.php:116-123)
  $timestamp = current_time('Y-m-d H:i:s');
  if (!isset($context['run_id'])) {
      if (self::$run_id > 0) {
          $context = ['run_id' => self::$run_id] + $context;
      }
      if (self::$run_type !== '') {
          $context = ['run_type' => self::$run_type] + $context;
      }
  }
  $context_str = !empty($context) ? ' ' . wp_json_encode($context) : '';
  ```
  **Regla:** no pisar un `run_id` explícito en `$context` (si viene, se respeta tal cual y no se
  inyecta nada). El orden de claves deja `run_id`/`run_type` primero (`+` preserva las claves del
  array de la izquierda y no sobreescribe las existentes a la derecha).
- **Sin cambios de firma** en `info/warning/error/critical/debug`.

**Resultado esperado**: un import con `run_id=R` deja entradas con `run_id=R` y `run_type`; un
`run_id` explícito en `$context` se conserva; `clear_run_context()` corta la inyección.

**Dependencias**: ninguna. Es prerequisito de **T1.1b**.

**Trazabilidad**: REQ-LOG-04, NFR-07 (los logs no deben filtrar secretos: sólo se agregan
`run_id`/`run_type`, nunca credenciales).

**Verificación**: test nuevo en `scripts/exec-test.php` (usa el helper existente
`alegra_read_log()`, `exec-test.php:2454`, y `alegra_clear_log()`, `:2463`):
```php
TestRunner::test('T28.15 Logger inyecta run_id/run_type y respeta el run_id explícito', function (): void {
    alegra_test_reset();
    alegra_clear_log();
    $logger = make_logger();

    \Alegra\Connector\Logger\Logger::set_run_context(42, 'manual_import');
    $logger->info('linea con contexto');
    \Alegra\Connector\Logger\Logger::clear_run_context();
    $log = alegra_read_log();
    TestRunner::assertStringContains('"run_id":42', $log, 'la línea debe llevar run_id=42');
    TestRunner::assertStringContains('"run_type":"manual_import"', $log, 'la línea debe llevar run_type');

    alegra_clear_log();
    \Alegra\Connector\Logger\Logger::set_run_context(42, 'manual_import');
    $logger->info('explicito', ['run_id' => 7]);
    \Alegra\Connector\Logger\Logger::clear_run_context();
    $log = alegra_read_log();
    TestRunner::assertStringContains('"run_id":7', $log, 'el run_id explícito debe conservarse');
    TestRunner::assertStringNotContains('"run_id":42', $log, 'no debe pisar el explícito');
});
```

**Prove-it-catches**: quitar el bloque `if (!isset($context['run_id'])) { ... }` de `write()` →
la aserción `"run_id":42` falla.

**Riesgo**: R10 (que el contexto estático se filtre a logs fuera del run). Guard: `finish()` y
`fail_early()` llaman `clear_run_context()` (T1.1b); el contexto se setea al inicio de cada
request de import.

**Estimación**: M (2 h).

---

### T1.3 — `Runs::mark_abandoned()` + `Runs::status()`

**Objetivo**: cerrar runs chunked que quedaron `running` porque el navegador se cerró (nunca llegó
el próximo request), y exponer el `status` actual para que `Run_Context::finish()` no re-finalice.

**Descripción técnica**: `currently_running()` (`Runs.php:174-185`) hoy sólo llama `mark_stale()`
(`:191-206`), que marca `stale` a los **3600 s** (`STALE_AFTER_SECONDS`, `:164`). El chunked es
multi-request: si el comerciante cierra la pestaña, la fila queda `running` hasta una hora. El
design §2.3 agrega `mark_abandoned(int $grace_seconds = 180)` que, **después** de `mark_stale()`,
marca `stale` a los runs `chunked_import` con `started_at` viejo **y** heartbeat vencido (TTL 120 s
de `Heartbeat`, `Heartbeat.php:21`). Un cron/manual vivo es un request vivo: si muere, lo cubre
`mark_stale` a los 3600 s. **Sin schema change**: `stale` ya es un valor de `status` (usado por
`mark_stale`) y el badge lo maneja el Monitor (T6.4). `Runs::status()` (design §2.5/R9) es el
guard de re-finalización que consume `Run_Context::finish()`.

**Desarrollo técnico**:

- **Archivo:** `includes/Runs.php`.
- **1) `status()` (nuevo, público).** Agregar después de `finish()` (`:118`):
  ```php
  public static function status(int $run_id): ?string
  {
      global $wpdb;
      $table = $wpdb->prefix . 'alegra_runs';
      $status = $wpdb->get_var($wpdb->prepare(
          "SELECT status FROM $table WHERE id = %d",
          $run_id
      ));
      return $status !== null ? (string) $status : null;
  }
  ```
- **2) `mark_abandoned()` (nuevo, público).** Agregar después de `mark_stale()` (`:206`). Código
  concreto (con la corrección F4 de timezone):
  ```php
  public static function mark_abandoned(int $grace_seconds = 180): void
  {
      global $wpdb;
      $table = $wpdb->prefix . 'alegra_runs';
      // Mismo reloj con que Runs::start() guardó started_at (current_time('mysql')).
      $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $grace_seconds);

      $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT id FROM $table
           WHERE status = 'running' AND run_type = 'chunked_import' AND started_at < %s
           LIMIT 50",
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
  **Nota (corrección F4):** el design usaba `gmdate('...', time() - $grace_seconds)`. Como
  `started_at` se guarda con `current_time('mysql')` (hora local), se usa
  `current_time('timestamp')` para que cutoff y `started_at` compartan el mismo reloj. En el
  harness ambos coinciden (UTC), pero en producción la versión del design desactivaba la guarda de
  180 s.
- **3) Enganchar en `currently_running()`.** Insertar la llamada **después** de `self::mark_stale()`
  (`:179`):
  ```php
  // ANTES
  self::mark_stale();

  // DESPUÉS
  self::mark_stale();
  self::mark_abandoned();
  ```

**Resultado esperado**: una fila `chunked_import` `running`, con `started_at` > 180 s y **sin**
heartbeat, pasa a `stale` y `currently_running()` deja de devolverla; una `manual_import` análoga
**no** cambia (la cubre `mark_stale` a 3600 s); un chunked con heartbeat vivo **no** se marca.

**Dependencias**: **T1.1a** (harness para leer/escribir runs). Prerequisito de **T1.1b** y
**T1.5**.

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-MON-06, R9.

**Verificación**: test nuevo en `scripts/exec-test.php`:
```php
TestRunner::test('T28.16 mark_abandoned cierra el chunked huérfano pero no el manual', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 300);

    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'running', 'started_at' => $old]);
    $chunked = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'manual_import', 'status' => 'running', 'started_at' => $old]);
    $manual = (int) $wpdb->insert_id;

    $running = \Alegra\Connector\Runs::currently_running();

    TestRunner::assertSame('stale', \Alegra\Connector\Runs::status($chunked), 'el chunked huérfano debe quedar stale');
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($manual), 'el manual no lo cubre mark_abandoned');
    foreach ($running as $r) {
        TestRunner::assertNotSame($chunked, (int) $r->id, 'el chunked stale no debe listarse como running');
    }
});

TestRunner::test('T28.17 un chunked con heartbeat vivo no se marca abandonado', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 300);
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'running', 'started_at' => $old]);
    $id = (int) $wpdb->insert_id;
    \Alegra\Connector\Heartbeat::set($id, ['step' => 'products', 'message' => 'vivo']);
    \Alegra\Connector\Runs::currently_running();
    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'con heartbeat vivo no debe marcarse');
});
```

**Prove-it-catches**: quitar `self::mark_abandoned();` de `currently_running()` → el primer test
falla (el chunked queda `running`).

**Riesgo**: que el cutoff UTC/local marque runs vivos como abandonados. Guard: la corrección F4 +
el test del heartbeat vivo.

**Estimación**: M (2 h).

---

### T1.3b — Fix del timezone en `Runs::mark_stale()`

**Objetivo**: que `mark_stale()` compare `started_at` contra el **mismo reloj** con que se guardó
(hora local), para que en sitios con offset ≠ UTC no marque `stale` runs recién arrancados.

**Descripción técnica (corrección D4 del review Oracle)**: `Runs::start()` guarda
`started_at` con `current_time('mysql')` (hora **local** del sitio, `Runs.php:63`), pero
`mark_stale()` (`Runs.php:191-206`) usa `$cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS)`
(UTC, `:195`). En un sitio UTC−5 (p. ej. America/Bogota) el `started_at` de un run recién arrancado
es ~5 h menor que el cutoff ⇒ `started_at < cutoff` es **siempre true** ⇒ `mark_stale()` marca
`stale` cualquier run `running` en el primer poll del Monitor. Es **pre-existente** y T1.3 sólo
corrigió `mark_abandoned`; pero `currently_running()` llama `mark_stale()` **primero** (`:179`), así
que en UTC− el daño ya está hecho cuando `mark_abandoned` corre (`WHERE status='running'` no ve la
fila). Sin este fix, REQ-MON-01/02 ("Procesos Activos" refleja los runs vivos) se rompe en el sitio
real del comerciante.

**Desarrollo técnico**:

- **Archivo:** `includes/Runs.php`.
- **1) `mark_stale()` (`:195`) — mismo reloj que `started_at`.** **ANTES:**
  ```php
  $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS);
  ```
  **DESPUÉS:**
  ```php
  // Mismo reloj con que Runs::start() guardó started_at (current_time('mysql')).
  // `time()` es UTC y desalineaba el cutoff en sitios con offset ≠ 0.
  $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::STALE_AFTER_SECONDS);
  ```
  (`self::STALE_AFTER_SECONDS` es `3600`, `Runs.php:164`; equivale al `- 3600` de la corrección.)
- **2) Seam de harness para reproducir un sitio no-UTC.** El harness corre en UTC
  (`current_time('timestamp') === time()`), así que el bug **no** se reproduce sin simular el
  offset. Agregar en `scripts/lib/wp-stubs.php` (`current_time()`, `:395-398`) soporte de offset
  **sin cambiar el comportamiento por defecto** (offset `0`):
  ```php
  function current_time($type = 'mysql', $gmt = 0)
  {
      if ($gmt) {
          return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
      }
      $offset = (int) ($GLOBALS['alegra_test_gmt_offset'] ?? 0);
      if ($offset === 0) {
          return $type === 'timestamp' ? time() : date('Y-m-d H:i:s');
      }
      $ts = time() + $offset * 3600;
      return $type === 'timestamp' ? $ts : gmdate('Y-m-d H:i:s', $ts);
  }
  ```
  Y resetearlo en `alegra_test_reset()` (`scripts/lib/test-framework.php:174`, junto a
  `$GLOBALS['alegra_test_wp_die_throws'] = false;`):
  ```php
  $GLOBALS['alegra_test_gmt_offset'] = 0;
  ```
- **Nota de alcance:** este task **no** toca el modelo de `wp_alegra_runs` (fuente única: T1.1a);
  sólo agrega el seam de tiempo.

**Resultado esperado**: en un sitio UTC−5, un run `manual_import` recién creado con
`Runs::start()` **no** pasa a `stale` tras `currently_running()`; un run realmente viejo (> 3600 s
en el mismo reloj local) **sí** pasa a `stale`.

**Dependencias**: **T1.1a** (harness para leer/escribir `wp_alegra_runs`). Independiente de T1.3
(la corrección de `mark_abandoned` es ortogonal, pero ambas comparten el reloj local).

**Trazabilidad**: REQ-MON-01, REQ-MON-02, D4 del review Oracle. Deuda pre-existente de `Runs.php:195`.

**Verificación**: test nuevo en `scripts/exec-test.php`:
```php
TestRunner::test('T28.18 mark_stale no marca stale un run recién arrancado (offset no-UTC)', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_gmt_offset'] = -5;   // America/Bogota (UTC-5)

    $id = \Alegra\Connector\Runs::start('manual_import', 'tester');   // started_at = hora LOCAL
    \Alegra\Connector\Runs::currently_running();                       // dispara mark_stale()

    TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'un run recién arrancado no debe quedar stale');

    $GLOBALS['alegra_test_gmt_offset'] = 0;
});
```

**Prove-it-catches**: revertir `mark_stale()` a `gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS)`
→ `T28.18` falla (el run recién arrancado queda `stale`).

**Riesgo**: que el seam de `current_time()` altere el default y rompa los 1289 tests base. Guard: con
`$alegra_test_gmt_offset === 0` el stub devuelve exactamente lo de hoy; correr el harness completo y
comparar el conteo de aserciones.

**Estimación**: S (1 h).

---

### T1.4 — `Heartbeat::forget()` + `clear()` deja de borrar el stop

**Objetivo**: arreglar el bug del **"Detener nunca funcionó"**: `clear()` borraba el propio pedido
de stop que `ajax_kill_run` acababa de setear. Separar "borrar el display" (`forget`) de "borrar
todo" (`clear`, que ya no toca el stop).

**Descripción técnica (hallazgo crítico #4 del design)**: `ajax_kill_run` llama
`Runs::request_stop($run_id)` (`Admin_Dashboard.php:3764`) y acto seguido `Heartbeat::clear($run_id)`
(`:3765`); `clear()` ejecuta `delete_transient('alegra_run_stop_' . $run_id)` (`Heartbeat.php:70`) →
**borra el pedido de stop recién creado**. Por eso "Detener" nunca funcionó, ni para el cron. El
design §2.5: `clear()` deja de borrar el stop; se agrega `forget()` que borra **sólo**
`alegra_run_{id}`; `Run_Context::finish` usa `forget()`. El stop se limpia dentro de `finish`
(tras observarlo) o por TTL (300 s, `Runs.php:125`). Único llamador de `clear()`:
`Admin_Dashboard.php:3765` (grep), así que el cambio es seguro.

**Desarrollo técnico**:

- **Archivo:** `includes/Heartbeat.php:66-71`.
- **ANTES:**
  ```php
  public static function clear(int $run_id): void
  {
      unset(self::$cache[$run_id]);
      delete_transient('alegra_run_' . $run_id);
      delete_transient('alegra_run_stop_' . $run_id);
  }
  ```
- **DESPUÉS:**
  ```php
  public static function clear(int $run_id): void
  {
      // Backwards-compatible alias. Since 2.5.0 it does NOT delete
      // alegra_run_stop_{id}: that transient is the "Detener" request, owned by
      // Run_Context::finish()/TTL, not by the display cleanup.
      self::forget($run_id);
  }

  /**
   * Delete ONLY the display heartbeat (alegra_run_{id}). The stop request
   * (alegra_run_stop_{id}) is intentionally left intact.
   */
  public static function forget(int $run_id): void
  {
      unset(self::$cache[$run_id]);
      delete_transient('alegra_run_' . $run_id);
  }
  ```
  (Se mantiene `clear()` por compatibilidad; ambos borran sólo el display.)

**Resultado esperado**: tras `Runs::request_stop(R)` + `Heartbeat::clear(R)`,
`Runs::should_stop(R)` sigue `true`; `Heartbeat::forget(R)` borra el display y **no** el stop;
`Heartbeat::get(R) === null` tras `forget`.

**Dependencias**: ninguna. Prerequisito de **T1.1b** (usa `forget`) y **T1.5**.

**Trazabilidad**: REQ-MON-02, REQ-MON-05. Bug del design hallazgo #4.

**Verificación**: test nuevo en `scripts/exec-test.php`:
```php
TestRunner::test('T28.19 clear() no borra el stop; forget() borra sólo el display', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Runs::request_stop(9);
    \Alegra\Connector\Heartbeat::set(9, ['step' => 'products', 'message' => 'x']);

    \Alegra\Connector\Heartbeat::clear(9);
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop(9), 'clear() NO debe borrar el pedido de stop');

    \Alegra\Connector\Heartbeat::forget(9);
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop(9), 'forget() NO debe borrar el stop');
    TestRunner::assertSame(null, \Alegra\Connector\Heartbeat::get(9), 'forget() debe borrar el display');
});
```

**Prove-it-catches**: reponer `delete_transient('alegra_run_stop_' . $run_id);` dentro de `clear()`
(o de `forget()`) → la primera aserción (`should_stop` tras `clear`) falla.

**Riesgo**: que otro consumidor dependa de que `clear()` borre el stop. Guard: grep confirma que el
único llamador es `ajax_kill_run` (`Admin_Dashboard.php:3765`), que T1.5 corrige para **no** llamar
`clear()`.

**Estimación**: S (1 h).

---

### T1.5 — `ajax_kill_run` honesto + `Run_Context::finish` no re-finaliza

**Objetivo**: que "Detener" **pida** el stop sin matar el heartbeat (el run sigue vivo hasta que el
flujo lo vea), y que `Run_Context::finish` **no pise** el estado de un run ya cerrado.

**Descripción técnica**: con T1.4 arreglado, falta que `ajax_kill_run`
(`Admin_Dashboard.php:3756-3769`) deje de llamar `Heartbeat::clear($run_id)` (`:3765`): limpiar el
display haría desaparecer el run de "Procesos Activos" antes de que el flujo observe el stop. El
design §2.5 punto 2: `ajax_kill_run` hace `Runs::request_stop($run_id)` + log + responde; **no**
toca el heartbeat. El stop se limpia en `Run_Context::finish` (tras observarlo, T1.1b) o por TTL
(300 s). El segundo punto (design §2.5 punto 4): "detener un run terminado" no debe cambiar el
estado; `Run_Context::finish` consulta `Runs::status()` (T1.3) y si ya está cerrado no
re-finaliza. Esto evita pisar `completed` con `cancelled` (R9). La propagación del `run_id` al
chunked/manual es **Fase 2/3** (T2.2–T2.4, T3.2): esta task cierra el lado del handler.

**Desarrollo técnico**:

- **Archivo:** `admin/Admin/Admin_Dashboard.php:3756-3769`.
- **ANTES (`:3764-3768`):**
  ```php
  \Alegra\Connector\Runs::request_stop($run_id);
  \Alegra\Connector\Heartbeat::clear($run_id);

  $this->log('info', 'Run stop requested by user', ['run_id' => $run_id]);
  wp_send_json_success(['message' => __('El proceso se detendra en su siguiente verificacion.', 'alegra-connector')]);
  ```
- **DESPUÉS:**
  ```php
  \Alegra\Connector\Runs::request_stop($run_id);

  // Do NOT clear the heartbeat: the run is still alive until the running flow
  // observes Runs::should_stop() and calls Run_Context::finish(). Clearing the
  // display here would make the live run disappear from the Monitor.
  $this->log('info', 'Run stop requested by user', ['run_id' => $run_id]);
  wp_send_json_success(['message' => __('El proceso se detendra en su siguiente verificacion.', 'alegra-connector')]);
  ```
  (Se elimina sólo la línea `:3765`; los strings no se tocan — `languages/*` está fuera de alcance.)
- **`Run_Context::finish` (T1.1b) — el guard.** Ya queda implementado con `Runs::status()`; esta
  task lo **verifica** y, si T1.1b se hizo sin guard, se agrega:
  ```php
  $current = Runs::status($run_id);
  if ($current !== null && $current !== 'running') {
      self::teardown($run_id);
      return;                       // no re-finalizar: preserva completed/failed/cancelled
  }
  ```

**Resultado esperado**: pulsar "Detener" deja `should_stop=true` y el heartbeat **intacto** (el run
sigue `running` en el Monitor hasta que el flujo lo vea); `Run_Context::finish($R,'cancelled')`
sobre un run ya `completed` deja `status='completed'` y no tira error.

**Dependencias**: **T1.1b** (`Run_Context::finish`), **T1.3** (`Runs::status`), **T1.4** (stop
sobrevive a `clear`).

**Trazabilidad**: REQ-MON-05, R9. Cierra el bug del design hallazgo #4 del lado del handler.

**Verificación**: tests nuevos en `scripts/exec-test.php`:
```php
TestRunner::test('T28.110 ajax_kill_run pide el stop y NO limpia el heartbeat', function (): void {
    alegra_test_reset();
    $run_id = 55;
    \Alegra\Connector\Heartbeat::set($run_id, ['step' => 'products', 'message' => 'corriendo']);

    $_POST['run_id'] = $run_id;
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_kill_run());
    unset($_POST['run_id']);

    TestRunner::assertTrue($resp->success, 'kill_run debe responder success');
    TestRunner::assertTrue(\Alegra\Connector\Runs::should_stop($run_id), 'el stop debe quedar pedido');
    TestRunner::assertTrue(\Alegra\Connector\Heartbeat::get($run_id) !== null, 'el heartbeat NO debe limpiarse');
});

TestRunner::test('T28.111 Run_Context::finish no re-finaliza un run ya cerrado', function (): void {
    alegra_test_reset();
    $wpdb = $GLOBALS['wpdb'];
    $wpdb->insert($wpdb->prefix . 'alegra_runs', ['run_type' => 'chunked_import', 'status' => 'completed', 'started_at' => current_time('mysql')]);
    $run_id = (int) $wpdb->insert_id;

    \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');

    TestRunner::assertSame('completed', \Alegra\Connector\Runs::status($run_id), 'no debe pisar completed con cancelled');
});
```

**Prove-it-catches (corregido — ver F2)**: volver a poner `\Alegra\Connector\Heartbeat::clear($run_id);`
en `ajax_kill_run` → la aserción `Heartbeat::get($run_id) !== null` falla. (La aserción de
`should_stop` **no** falla, porque tras T1.4 `clear()` ya no borra el stop.) Y quitar el guard de
`Runs::status()` en `Run_Context::finish` → el segundo test falla (queda `cancelled`).

**Riesgo**: que el run quede `running` para siempre si ningún flujo observa el stop (el chunked
muere por otra causa). Guard: `mark_abandoned()` (T1.3) cierra el chunked huérfano a los 180 s +
TTL del stop a 300 s; `mark_stale()` cubre manual/cron a 3600 s.

**Estimación**: M (2 h).

---

## DoD Fase 1 (checklist verificable)

- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con las aserciones nuevas (`T28.11`–`T28.111`).
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`; `smoke-load.php` asserta que `Run_Context` carga
      por el autoloader PSR-4.
- [ ] Prove-it-catches ejecutados y documentados para cada test (revertir → rojo → re-aplicar →
      verde).
- [ ] REQ-MON-01/02/05 y REQ-LOG-04 cubiertos por aserciones.
- [ ] `Run_Context::current() === 0` fuera de un run.
- [ ] `Runs::mark_stale()` usa `current_time('timestamp')` (T1.3b) y `T28.18` prueba un sitio
      UTC−5 (run recién arrancado ≠ `stale`).
- [ ] El único llamador de `Heartbeat::clear()` que queda es `ajax_kill_run`, y T1.5 lo eliminó;
      `clear()` delega en `forget()`.
- [ ] Sin cambios de schema (`Schema.php` intacto).
