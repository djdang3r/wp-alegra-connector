# Fase 6 — Logs, Monitor y D6 (UI/cosmético) — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **6** — Logs, Monitor y D6 |
| Versión objetivo | **2.5.0** |
| Harness | `bash scripts/exec-test.sh` (baseline verificado: **1289 assertions**, 0 failed) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T6.1`–`T6.5` (expandidas a 8 micro-tareas) |
| Depende de | Fase 1 (`T1.1b` `Run_Context`, `T1.2` `Logger::set_run_context`, `T1.3b` `mark_stale`), Fase 0.3 (G3) solo para `T6.5` |
| DoD de la fase | REQ-LOG-05/06/07, REQ-MON-03/04/06, REQ-CON-02, NFR-03, NFR-06 verdes; `exec-test.sh` y `smoke-test.sh` verdes |

> **Regla de oro heredada.** Cada test nuevo pasa por *prove-it-catches*: se revierte el fix, se corre
> el harness y **ese** test debe fallar. Sin eso, el test no se acepta.
>
> **IDs de test ≠ IDs de tarea (corrección de convención).** Las tareas de este archivo son `T6.x`. Los
> tests del harness viven en `scripts/exec-test.php` con su **propia secuencia** (hoy termina en `T27.8`).
> Los tests nuevos de este cambio van en la sección **`// === logs-monitor-import (2.5.0) ===`** con IDs
> **`T28.{fase}{n}`**: Fase 6 usa **`T28.61`–`T28.615`**. Así no colisionan con `T1..T27` ni con Fase 3
> (`T28.31`+) ni Fase 5 (`T28.51`+).

---

## Correcciones de cita re-verificadas en HEAD (Fase 6)

La spec/design/esqueleto citan bien casi todo. Al releer el código para escribir estas micro-tareas
aparecen **6 correcciones / hallazgos** que cambian el plan:

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `clear_old_logs(int $retention_days)` (`tasks.md` T6.1, design §3.5) | La firma real es **`clear_old_logs(?int $retention_days = null): int`** (`Logger.php:198`). Además, el método **se loguea a sí mismo** en `:219` (`$this->info('Cleared old logs', …)`), lo que **recrea un archivo** — la razón real por la que "limpiar" deja la pantalla con contenido. | T6.1.a: `clear_all_logs()` **no** debe loguear; el test debe probar que el dir queda vacío. |
| C2 | `tasks.md` T6.3 test: "el fuente contiene `get_log_dir`" | `templates/admin-logs.php` **no** llama a `get_log_dir()`; usa la variable `$logger_dir` que le pasa `Admin_Dashboard::render_logs_page()`. `get_log_dir()` **lo crea T6.2.a** (método nuevo, **no existe en HEAD**) en `Logger.php` y se invoca desde el **controller**. | T6.3: source-scan del template busca `$logger_dir`; source-scan del controller busca `get_log_dir()`. |
| C3 | design §3.6: `$logger_dir = (new Logger())->get_log_dir()` | El controller **ya tiene** `$this->logger` (inyectado en `render_logs_page`, `Admin_Dashboard.php:933`). Construir otro `Logger` re-ejecuta `ensure_dir()`. Ojo: `get_log_dir()` **no existe en HEAD**; es un método **nuevo que crea T6.2.a**. | T6.3: usar `$this->logger ? $this->logger->get_log_dir() : ''` (con T6.2.a ya implementado). |
| C4 | `tasks.md` T6.2: "`Logger.php:126-169` (`write()`)" | `write()` va de **`:112`** a `:169`; el `catch` es **`:162-168`**; los checks de writable son **`:129-137`**. | T6.2.a: los puntos de inserción exactos son `:162-168` (persistir) y tras `:153` (auto-curar). |
| C5 | design §4.4 enumera las strings nuevas y **omite** las de origen del Monitor y la de cron deshabilitado | `admin.js:1-6` documenta la regla AC-35d: *toda* string de UI vive en `get_script_strings()`. Los labels de origen (`Cron`/`Manual`/`Chunked`/`Webhook`) y el texto de cron deshabilitado son UI. | T6.4: agregar `originCron`, `originManual`, `originChunked`, `originWebhook`, `cronDisabled` a `get_script_strings()` (además de `statusAbandoned` y `monitorError`, que sí están en el design). |
| C6 | `tasks.md` T6.5: "`admin-settings.php:78-81` ya usa `false`" | **Confirmado**: `:78` es `checked(get_option('alegra_connector_sync_products',false))` y `:79-81` igual para customers/orders/categories. La premisa de `config-gates` T2.5 ("`true` → `false`") está **stale** sobre HEAD. | T6.5 rama A: no se toca la UI; se agrega aserción de regresión que congela el `false`. |

**Hallazgo de infraestructura del harness (bloquea tests de runs).** `scripts/lib/wp-stubs.php`
implementa `wpdb::get_results()` (`:1655-1671`) devolviendo `[]` para **toda** query que no sea el
lookup de `alegra_category_id`, y `get_var()` (`:1592`) / `get_row()` (`:1642`) devuelven `null` para
`alegra_runs`. `insert()` guarda en `$GLOBALS['alegra_db']` pero **nadie lo lee**, y `update()` es un
no-op (`:1733-1736`). Consecuencia: hoy **no se puede observar ninguna fila de `wp_alegra_runs`** desde
el harness. Cualquier test de esta fase que necesite leer un run (p. ej. verificar `status='stale'` o el
payload de `ajax_monitor_status` con filas) **requiere** primero el modelo de `alegra_runs` que define
**`fase-1-cimientos.md` §`T1.1a`** (fuente única). `T7.1.a` (Fase 7) es **sólo aditivo** (`get_row()` +
`_n()` si falta); **no** es el dueño del modelo. Se marca como dependencia de infraestructura.

**Hallazgo de stub faltante.** `_n()` se usa en el plugin (`Admin_Dashboard.php:4363,4431`) pero **no
está definida** en `scripts/lib/wp-stubs.php` (solo hay `__`, `_e`, `esc_html__`, etc. en `:324-329`).
Si el handler de T6.1 usa `_n()` (como manda el design §3.3), el test **fatalea** con *Call to undefined
function _n()*. T6.1.a debe agregar el stub.

**Citas confirmadas exactas (no requieren corrección):** `Logger.php:198-222` (`clear_old_logs`),
`:211` (chequeo `$modified < $cutoff`), `:162-168` (catch silencioso), `:126-137` (writable);
`Admin_Dashboard.php:2322` (`ajax_clear_logs`), `:2335` (llamada a `clear_old_logs`),
`:2326-2328` (cap/nonce), `:3691-3751` (`ajax_monitor_status`), `:3730` (`'error' => error_summary`),
`:3756-3769` (`ajax_kill_run`), `:108-144` (`import_product_image` muerto), `:652` (`get_script_strings`),
`:705` (`confirmClearLogs`); `templates/admin-logs.php:36` (botón "Limpiar antiguos"), `:80-102`
(bloque `if(!empty($log_files))`), `:98-100` (ruta relativa hardcodeada); `admin-monitor.php:87-99`
(`statusBadge`), `:108-136`/`:119` (`renderRunning`), `:138-169` (`renderCron`), `:171-191`/`:180`
(`renderRecent`), `:46,58,70` ("Cargando..."), `:206-208` (`// Silent fail`); `admin.js:386-393`/`:387`
(confirm de limpiar logs); `alegra-connector.php:6` (versión), `:59` (constante derivada), `:214`
(`before_delete_post`), `:284` (`init_logger()` en `on_plugins_loaded`), `:294` (guard WooCommerce),
`:371-374` (`init_logger`), `:424` (`sync_products` default); `Controller.php:171` (gate `sync_products`);
`Runs.php:95-118` (`finish`), `:174-206` (`currently_running`/`mark_stale`), `:211-233`
(`get_cron_events`); `Heartbeat.php:66-71` (`clear`).

---

## Mapa de cobertura Fase 6 → requerimiento

| Micro-tarea | REQ / NFR | Archivos |
|---|---|---|
| T6.1.a | REQ-LOG-07, NFR-03 | `Logger.php`, `Admin_Dashboard.php`, `wp-stubs.php` (stub `_n`) |
| T6.1.b | REQ-LOG-07, NFR-06 | `admin-logs.php`, `admin.js`, `Admin_Dashboard.php` |
| T6.2.a | REQ-LOG-06, NFR-07 | `Logger.php` |
| T6.2.b | REQ-LOG-06, NFR-07 | `Logger.php`, `alegra-connector.php` |
| T6.3 | REQ-LOG-05 | `admin-logs.php`, `Admin_Dashboard.php` |
| T6.4.a | REQ-MON-03, REQ-MON-04 | `admin-monitor.php`, `Admin_Dashboard.php` |
| T6.4.b | REQ-MON-03, REQ-MON-06, NFR-05 | `admin-monitor.php`, `Admin_Dashboard.php` |
| T6.5 | REQ-CON-01, REQ-CON-02, NFR-06 | `alegra-connector.php`, `Controller.php`, `admin-settings.php`, `exec-test.php` |

---

### T6.1.a — `Logger::clear_all_logs()` + handler `ajax_clear_logs`

**Objetivo**: que "Limpiar logs" borre **todos** los `*.log` (incluido el de hoy) y devuelva el conteo real, sin recrear un archivo al hacerlo.

**Descripción técnica**: hoy `ajax_clear_logs` (`Admin_Dashboard.php:2322-2338`) lee la retención (`:2334`) y llama `clear_old_logs($retention)` (`:2335`); ese método solo borra `filemtime < cutoff` (`Logger.php:211`) y **encima** escribe su propia entrada (`:219`), recreando un `.log`. Resultado: el archivo del día nunca se borra y la pantalla no queda vacía → "0 eliminados" / "parece roto". El design **D2 §3.3** agrega `clear_all_logs(): array{files,bytes}` que deliberadamente **no se loguea** (loguearse recrearía el archivo). El design **D2 §3.5** deja `clear_old_logs()` y `Maintenance::run()` **intactos** y **no** toca la opción de retención (REQ-LOG-07 borde, NFR-03). Cubre **REQ-LOG-07**.

**Desarrollo técnico**:

1. **Stub faltante (prerrequisito de test)** — `scripts/lib/wp-stubs.php`, insertar tras `:325` (`function _e(...)`):
```php
function _n($single, $plural, $number, $domain = '') { return ((int) $number === 1) ? $single : $plural; }
```
`_n()` ya se usa en producción (`Admin_Dashboard.php:4363,4431`) pero no estaba stubeada porque esos caminos no se ejercitaban.

2. **Método nuevo** — `logger/Logger/Logger.php`, insertar **después** de `clear_old_logs()` (después de `:222`, antes de `get_logs()` en `:224`):
```php
/**
 * Borra TODOS los archivos *.log del directorio de logs (no toca .htaccess
 * ni index.php) y devuelve el conteo real.
 *
 * NO escribe una entrada de log propia a propósito: hacerlo recrearía un
 * archivo al instante y la pantalla de Logs no quedaría vacía (REQ-LOG-07).
 * La auditoría de la acción es el aviso de éxito en el admin.
 *
 * @return array{files:int,bytes:int}
 */
public function clear_all_logs(): array
{
    $this->ensure_dir();
    $files = glob($this->log_dir . '/*.log') ?: [];
    $deleted = 0;
    $bytes = 0;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $size = (int) @filesize($file);
        if (@unlink($file)) {
            $deleted++;
            $bytes += $size;
        }
    }
    return ['files' => $deleted, 'bytes' => $bytes];
}
```
Notas: usa `$this->log_dir` (seteado por `ensure_dir()` en `:55`), `glob('*.log')` (mismo patrón que `:206`/`:228`/`:314`), y **no** toca `.htaccess`/`index.php` (que `protect_log_dir()` mantiene).

3. **Handler** — `admin/Admin/Admin_Dashboard.php:2334-2337`, reemplazar:
```php
// ANTES
$retention = (int) get_option('alegra_connector_log_retention_days', 30);
$count = $this->logger->clear_old_logs($retention);

wp_send_json_success(['message' => sprintf(__('%d logs eliminados.', 'alegra-connector'), $count)]);
```
```php
// DESPUÉS
$res = $this->logger->clear_all_logs();

wp_send_json_success([
    'message' => sprintf(
        /* translators: %s: number of deleted log files */
        _n('%s archivo de log eliminado.', '%s archivos de log eliminados.', $res['files'], 'alegra-connector'),
        number_format_i18n($res['files'])
    ),
    'files' => $res['files'],
    'bytes' => $res['bytes'],
]);
```
El handler **deja de leer** `alegra_connector_log_retention_days` (la retención sigue gobernando solo `Maintenance`/`clear_old_logs`).

4. **NO tocar**: `Logger::clear_old_logs()` (`:198-222`), `Maintenance::run()` (`includes/Maintenance.php`), la opción `alegra_connector_log_retention_days`, ni `Logger::get_log_files()`/`get_logs()`.

**Resultado esperado**: con 3 `.log` (uno de hoy), el handler borra los 3, responde `{success:true, files:3}` y el directorio queda vacío; la retención sigue en 30.

**Dependencias**: **`T6.2.a → T6.1.a`** (orden de build: `T6.2.a` **antes** que `T6.1.a`). `clear_all_logs()` en sí no usa el contexto de run, pero sus tests `T28.61`/`T28.62` leen el directorio con `$logger->get_log_dir()`, y `get_log_dir()` **no existe en HEAD**: lo **crea `T6.2.a`**. Correr `T6.1.a` antes que `T6.2.a` fatalea con *Call to undefined method*. (La fase asume Fase 1 hecha.)

**Trazabilidad**: REQ-LOG-07, NFR-03, NFR-06.

**Verificación** (tests nuevos en `scripts/exec-test.php`, sección `T28`):
- `T28.61` `clear_all_logs() borra los 3 .log (incluido el de hoy) y no recrea ninguno`:
  ```php
  alegra_test_reset();
  $logger = make_logger();
  $dir = $logger->get_log_dir();
  @mkdir($dir, 0777, true);
  foreach (['2026-01-01', '2026-01-02', date('Y-m-d')] as $d) {
      file_put_contents($dir . '/alegra-sync-test-' . $d . '.log', "x\n");
  }
  $res = $logger->clear_all_logs();
  TestRunner::assertSame(3, $res['files'], 'debe borrar los 3 archivos, incluido el de hoy');
  TestRunner::assertTrue($res['bytes'] > 0, 'debe reportar bytes liberados');
  TestRunner::assertSame([], glob($dir . '/*.log') ?: [], 'el directorio debe quedar sin .log');
  TestRunner::assertSame(30, (int) get_option('alegra_connector_log_retention_days', 30), 'la retención no se toca');
  ```
  (Limpiar `.log` preexistentes del `sys_get_temp_dir()/alegra-exec-uploads` antes de sembrar, para que `files===3` sea determinista.)
- `T28.62` `ajax_clear_logs responde files y el plural correcto`:
  ```php
  alegra_test_reset();
  $logger = make_logger();
  $dir = $logger->get_log_dir();
  @mkdir($dir, 0777, true);
  file_put_contents($dir . '/a.log', 'x');
  $admin = new Admin_Dashboard(make_api(), $logger);
  $resp = alegra_capture_json(static fn() => $admin->ajax_clear_logs());
  TestRunner::assertTrue($resp->success, 'debe responder success');
  TestRunner::assertSame(1, $resp->payload['files'], 'files debe ser 1');
  TestRunner::assertStringContains('archivo de log eliminado', $resp->payload['message'], 'plural/singular correcto');
  ```
- Source-scan `T28.63`: `Admin_Dashboard.php` contiene `clear_all_logs()` y **no** contiene `clear_old_logs($retention)` dentro de `ajax_clear_logs`; `Logger.php` contiene `function clear_all_logs`.
- **Prove-it-catches**: revertir el handler a `clear_old_logs($retention)` → `T28.61` (dir vacío / de hoy) y `T28.62` (`files===1`) fallan.
- **Manual (WP admin)**: `Alegra Connector → Logs` → "Limpiar logs" → confirmar → ver "N archivos de log eliminados" y la tabla vacía. Cancelar no borra.

**Riesgo**: R8 (borrar evidencia). Guardas: `clear_all_logs` **no** se loguea; la retención automática queda intacta; T6.1.b agrega el copy destructivo.

**Estimación**: M (2 h).

---

### T6.1.b — Botón "Limpiar logs" + confirm + i18n

**Objetivo**: que la UI diga lo que realmente hace ("borra TODO") y advierta antes de hacerlo.

**Descripción técnica**: el botón dice "Limpiar antiguos" (`templates/admin-logs.php:36`) y el confirm dice "¿Eliminar logs antiguos?" (`Admin_Dashboard.php:705`, consumido por `admin.js:387`). Ambos prometen "antiguos" cuando el nuevo comportamiento es "todo". Design **D2 §3.3**. Cubre **REQ-LOG-07** y **NFR-06** (cambio de semántica documentado + confirmación).

**Desarrollo técnico**:
1. `templates/admin-logs.php:36`: cambiar la etiqueta
```php
// ANTES
<?php esc_html_e('Limpiar antiguos','alegra-connector');?>
// DESPUÉS
<?php esc_html_e('Limpiar logs','alegra-connector');?>
```
2. `admin/Admin/Admin_Dashboard.php:705` (en `get_script_strings()`): cambiar el **valor** de la clave existente `confirmClearLogs`
```php
// ANTES
'confirmClearLogs'      => __('¿Eliminar logs antiguos?', 'alegra-connector'),
// DESPUÉS
'confirmClearLogs'      => __('Esto borrará TODOS los logs, incluido el de hoy. No se puede deshacer. ¿Continuar?', 'alegra-connector'),
```
3. **No** se agregan claves nuevas: `logsDeleted` (`:706`) sigue como fallback en `admin.js:391`. **No** tocar `admin.js:386-393` (ya llama `confirm(S.confirmClearLogs)` y `safeMsg`).
4. **Consistencia con Fase 3 (cierra B3).** Fase 3 T3.3.e **no** agrega `confirmClearAll`; la única clave es la **existente** `confirmClearLogs` (`Admin_Dashboard.php:705`), consumida por `admin.js:387`. Esta tarea es el **único** punto que cambia su valor. Si aparece `confirmClearAll` en el código, es un defecto.

**Resultado esperado**: el botón dice "Limpiar logs"; al pulsarlo el navegador muestra el copy destructivo; cancelar no borra; confirmar borra y avisa el conteo.

**Dependencias**: T6.1.a (el handler que devuelve el conteo).

**Trazabilidad**: REQ-LOG-07, NFR-06.

**Verificación**:
- Source-scan `T28.64`: `admin-logs.php` contiene `Limpiar logs` y **no** contiene `Limpiar antiguos`; `Admin_Dashboard.php` contiene `Esto borrará TODOS los logs`.
- **Prove-it-catches**: reponer `Limpiar antiguos` → la aserción estática falla.
- **Manual**: `Alegra Connector → Logs`; ver la etiqueta y el confirm.

**Riesgo**: que el comerciante borre evidencia por accidente → mitigado por el confirm explícito y porque la retención no se desactiva.

**Estimación**: S (0,5 h).

---

### T6.2.a — `Logger`: persistir el fallo de escritura + `get_log_dir()` / `is_writable()`

**Objetivo**: que un directorio de logs no escribible deje de ser silencioso: se persiste el fallo y se auto-cura cuando vuelve a andar.

**Descripción técnica**: `Logger::write()` (`Logger.php:112-169`) atrapa `\Throwable` (`:162-168`) y **solo** escribe a `error_log` bajo `WP_DEBUG`; en cron/AJAX `error_log` no se ve, así que una carpeta no escribible produce cero logs y cero aviso. Design **D2 §3.4**: persistir `update_option('alegra_connector_logger_write_failed', ['at','path','error'], false)` en el `catch` y borrarla en el camino de éxito; nuevos helpers `get_log_dir(): string` y `is_writable(): bool`. Cubre **REQ-LOG-06** y **NFR-07**.

**Desarrollo técnico** (`logger/Logger/Logger.php`):
1. En el `catch` de `write()` (`:162-168`), **antes** del `if (defined('WP_DEBUG')…)`:
```php
} catch (\Throwable $e) {
    // REQ-LOG-06: persistir el fallo para que el admin lo vea aunque ocurra
    // en cron/AJAX (donde admin_notices no se renderiza en ese request).
    update_option('alegra_connector_logger_write_failed', [
        'at'    => time(),
        'path'  => $this->log_dir,
        'error' => $e->getMessage(),
    ], false); // autoload = false
    // Fallback: nunca dejar que un fallo de log rompa el import.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Alegra Logger] ' . $e->getMessage() . ' | ' . trim($log_entry));
    }
}
```
2. Auto-curación en el camino de éxito: insertar **después** de `fclose($handle);` (`:153`) y del bloque `if (!$locked) { … }` (`:155-161`), antes del cierre del `try`:
```php
if (get_option('alegra_connector_logger_write_failed') !== false) {
    delete_option('alegra_connector_logger_write_failed');
}
```
3. Helpers nuevos (insertar junto a `clear_old_logs()`, p. ej. después de `:222`):
```php
public function get_log_dir(): string
{
    $this->ensure_dir();
    return $this->log_dir;
}

public function is_writable(): bool
{
    $this->ensure_dir();
    if (!is_dir($this->log_dir) || !is_writable($this->log_dir)) {
        return false;
    }
    if (file_exists($this->log_file) && !is_writable($this->log_file)) {
        return false;
    }
    return true;
}
```
Reutiliza exactamente los checks de `:129-137`. `$this->log_dir` se setea en `ensure_dir()` (`:55`).

**Resultado esperado**: `get_log_dir()` devuelve la ruta absoluta real; `is_writable()` es `true` en un dir normal; un `write()` fallido deja `alegra_connector_logger_write_failed` con `path`/`error`; un `write()` exitoso la borra.

**Dependencias**: ninguna (T6.1.a, T6.3 y T6.2.b dependen de estos helpers).

**Trazabilidad**: REQ-LOG-06, NFR-07.

**Verificación** (tests `T28.65`/`T28.66`):
- `T28.65` fallo persistido — forzar un dir imposible **por reflexión** (robusto aun corriendo como root):
  ```php
  alegra_test_reset();
  $logger = make_logger();
  $bogus = sys_get_temp_dir() . '/alegra-not-a-dir-' . uniqid();
  @unlink($bogus); file_put_contents($bogus, 'x'); // un ARCHIVO donde se espera un dir
  $ref = new ReflectionClass($logger);
  foreach (['log_dir' => $bogus, 'log_file' => $bogus . '/x.log', 'dir_ready' => true] as $p => $v) {
      $prop = $ref->getProperty($p); $prop->setAccessible(true); $prop->setValue($logger, $v);
  }
  $logger->info('boom');
  $failure = get_option('alegra_connector_logger_write_failed');
  TestRunner::assertTrue(is_array($failure), 'el fallo debe persistirse');
  TestRunner::assertSame($bogus, $failure['path'] ?? null, 'la opción debe llevar el path');
  @unlink($bogus);
  ```
- `T28.66` auto-curación:
  ```php
  alegra_test_reset();
  update_option('alegra_connector_logger_write_failed', ['at' => 1, 'path' => '/x', 'error' => 'x'], false);
  make_logger()->info('ok');
  TestRunner::assertFalse(get_option('alegra_connector_logger_write_failed'), 'un write exitoso debe limpiar la opción');
  TestRunner::assertSame(sys_get_temp_dir() . '/alegra-exec-uploads/alegra-logs', make_logger()->get_log_dir(), 'get_log_dir absoluta');
  ```
- **Prove-it-catches**: quitar el `update_option` del `catch` → `T28.65` falla; quitar la auto-curación → `T28.66` falla.

**Riesgo**: que la opción quede "pegada" tras arreglar permisos → mitigado por la auto-curación. Que `get_option` con `false` no distinga "ausente" (ver T6.5): acá alcanza porque solo se consulta la presencia.

**Estimación**: M (1,5 h).

---

### T6.2.b — `render_write_failure_notice()` + registro en `admin_notices`

**Objetivo**: mostrar en el admin, con la ruta, que el logger no puede escribir.

**Descripción técnica**: la opción persistida en T6.2.a no sirve de nada sin un render. Design **D2 §3.4**: `Logger::render_write_failure_notice()` registrado en `alegra-connector.php` **junto a `init_logger()`** (corre incluso sin WooCommerce), solo para `current_user_can('manage_options')`, `notice-error is-dismissible`, con path y error; sin opción → sin aviso. Cubre **REQ-LOG-06**.

**Desarrollo técnico**:
1. `logger/Logger/Logger.php`, método estático nuevo:
```php
public static function render_write_failure_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $failure = get_option('alegra_connector_logger_write_failed');
    if (!is_array($failure) || empty($failure['path'])) {
        return;
    }
    echo '<div class="notice notice-error is-dismissible"><p>';
    echo esc_html(sprintf(
        /* translators: 1: log directory path, 2: error message */
        __('Alegra Connector: no se pudo escribir en el directorio de logs (%1$s). Error: %2$s. El plugin sigue funcionando, pero los eventos no se registran.', 'alegra-connector'),
        (string) $failure['path'],
        (string) ($failure['error'] ?? '')
    ));
    echo '</p></div>';
}
```
2. `alegra-connector.php`, en `on_plugins_loaded()` (`:279`), **inmediatamente después** de `$this->init_logger();` (`:284`) y **antes** del guard de WooCommerce (`:294`):
```php
$this->init_logger();

// REQ-LOG-06: el aviso de fallo de escritura corre aunque WooCommerce no esté
// (por eso va antes del guard de WC, igual que el logger).
add_action('admin_notices', [Logger\Logger::class, 'render_write_failure_notice']);
```
(`Logger\Logger::class` resuelve a `Alegra\Connector\Logger\Logger` desde el namespace `Alegra\Connector`; mismo patrón que `:373`.)

**Resultado esperado**: con la opción presente y un usuario `manage_options`, el admin muestra un `notice-error is-dismissible` con la ruta; sin opción, no muestra nada; un usuario sin `manage_options` tampoco lo ve.

**Dependencias**: T6.2.a (la opción + los helpers).

**Trazabilidad**: REQ-LOG-06, NFR-07.

**Verificación**:
- `T28.67` renderer:
  ```php
  alegra_test_reset();
  update_option('alegra_connector_logger_write_failed', ['at' => time(), 'path' => '/var/www/uploads/alegra-logs', 'error' => 'Permission denied'], false);
  ob_start(); \Alegra\Connector\Logger\Logger::render_write_failure_notice(); $html = ob_get_clean();
  TestRunner::assertStringContains('/var/www/uploads/alegra-logs', $html, 'el aviso muestra la ruta');
  TestRunner::assertStringContains('notice-error', $html, 'es un notice de error');
  TestRunner::assertStringContains('is-dismissible', $html, 'es descartable');
  alegra_test_reset();
  ob_start(); \Alegra\Connector\Logger\Logger::render_write_failure_notice(); $empty = ob_get_clean();
  TestRunner::assertSame('', $empty, 'sin opción no hay aviso (escenario negativo)');
  ```
- Source-scan `T28.68`: `alegra-connector.php` contiene `render_write_failure_notice`; el `add_action` aparece **antes** de la línea del guard `class_exists('WooCommerce')`.
- **Prove-it-catches**: quitar el `add_action` → la aserción estática falla; quitar el `empty($failure['path'])` → `T28.67` negativo falla si se siembra un array vacío.

**Riesgo**: que el aviso se muestre a usuarios sin permiso → guard `manage_options`. Que persista para siempre → `is-dismissible` + auto-curación en T6.2.a.

**Estimación**: S (1 h).

---

### T6.3 — Ruta absoluta de logs visible siempre

**Objetivo**: que la página de Logs muestre la ruta **absoluta real** del directorio, aunque no haya archivos, para poder ubicarla por FTP/panel.

**Descripción técnica**: hoy `templates/admin-logs.php:98-100` muestra `wp-content/uploads/alegra-logs/`: (a) **relativa y hardcodeada**, (b) **dentro** de `if (!empty($log_files))` (`:80-102`), así que solo aparece si hay archivos, (c) no es la ruta absoluta real (`Logger::$log_dir`). El design **D2 §3.6** la mueve fuera del `if` y la renderiza con `esc_html($logger_dir)`, donde `$logger_dir = $this->logger->get_log_dir()`. Cubre **REQ-LOG-05**.

**Desarrollo técnico**:
1. `admin/Admin/Admin_Dashboard.php`, en `render_logs_page()` (después de `$system_logs = …` en `:935`, antes del include en `:948`):
```php
$logger_dir = $this->logger ? $this->logger->get_log_dir() : '';
```
2. `templates/admin-logs.php`:
   - **Borrar** el bloque de ruta actual (`:98-100`):
     ```php
     <div style="margin-top:12px;font-size:11px;color:var(--ac-text-muted);">
         <?php esc_html_e('Los logs se almacenan en:','alegra-connector');?> <code style="font-size:11px;">wp-content/uploads/alegra-logs/</code>
     </div>
     ```
   - **Agregar**, después del cierre del Log Viewer (`:77`) y antes de `<!-- Log Files -->` (`:79`), un bloque **siempre visible**:
     ```php
     <!-- Ruta del directorio de logs (REQ-LOG-05): absoluta y copiable, siempre -->
     <div class="ac-card" style="margin-bottom:16px;padding:10px 20px;">
         <span style="font-size:12px;color:var(--ac-text-muted);">
             <?php esc_html_e('Los logs se almacenan en:','alegra-connector');?>
         </span>
         <code style="font-size:12px;user-select:all;"><?php echo esc_html($logger_dir);?></code>
     </div>
     ```

**Resultado esperado**: la página muestra la ruta absoluta (p. ej. `/var/www/.../wp-content/uploads/alegra-logs`) aunque `$log_files` esté vacío; el literal relativo desaparece.

**Dependencias**: T6.2.a (`Logger::get_log_dir()`).

**Trazabilidad**: REQ-LOG-05.

**Verificación**:
- Runtime `T28.69` (render real del template):
  ```php
  alegra_test_reset();
  $logger = make_logger();
  $admin = new Admin_Dashboard(make_api(), $logger);
  ob_start(); $admin->render_logs_page(); $html = ob_get_clean();
  TestRunner::assertStringContains($logger->get_log_dir(), $html, 'debe renderizar la ruta absoluta');
  TestRunner::assertStringNotContains('wp-content/uploads/alegra-logs/', $html, 'no debe quedar la ruta relativa hardcodeada');
  ```
- Source-scan `T28.610`: `admin-logs.php` contiene `esc_html($logger_dir)`; `Admin_Dashboard.php` contiene `$this->logger->get_log_dir()`.
- **Prove-it-catches**: volver a hardcodear `wp-content/uploads/alegra-logs/` → `T28.69` falla; quitar `$logger_dir` del controller → `T28.69` falla.

**Riesgo**: exponer una ruta del servidor en pantalla → es intencional y ya se muestra bajo `manage_woocommerce`; no se muestra a anónimos.

**Estimación**: S (1 h).

---

### T6.4.a — Monitor: `runTypeLabel()` + badge `stale`

**Objetivo**: que el comerciante distinga el **origen** de cada run (manual/chunked/cron/webhook) y vea los runs abandonados.

**Descripción técnica**: hoy `renderRunning` (`admin-monitor.php:119`) y `renderRecent` (`:180`) imprimen `r.type` **crudo** (`manual_import`, `chunked_import`, `cron_sync_all`, `webhook_item`…). El `statusBadge` (`:87-99`) no tiene `case 'stale'`, aunque `Runs::mark_stale()` (`Runs.php:191-206`, **T1.3b**) y `mark_abandoned()` (**T1.3**) ya lo escriben. Design **D1 §2.7**. Cubre **REQ-MON-04** y **REQ-MON-03**.

**Desarrollo técnico** (`templates/admin-monitor.php`):
1. En `statusBadge` (`:91-97`), agregar:
```js
case 'stale':     cls = 'neutral'; label = S.statusAbandoned; break;
```
2. Nueva función `runTypeLabel(type)`, antes de `renderRunning` (`:108`):
```js
function runTypeLabel(type) {
    var t = String(type || '');
    if (t.indexOf('cron') === 0) return S.originCron;
    if (t === 'manual_import') return S.originManual;
    if (t === 'chunked_import') return S.originChunked;
    if (t.indexOf('webhook') === 0) return S.originWebhook;
    return t; // filas viejas / desconocidas: valor crudo
}
```
3. Usarla en `renderRunning` (`:119`): `escapeHtml(runTypeLabel(r.type))` (en lugar de `escapeHtml(r.type)`).
4. Usarla en `renderRecent` (`:180`): `escapeHtml(runTypeLabel(r.type))`.
5. `Admin_Dashboard.php`, `get_script_strings()` (bloque Monitor, `:770-804`), agregar las claves
   (**dueño único: T6.4.a**; T3.3.e sólo las consume, **no** las redeclara — ver §"Tabla canónica de
   strings i18n"):
```php
'statusAbandoned'       => __('Abandonado', 'alegra-connector'),
'originCron'            => __('Cron', 'alegra-connector'),
'originManual'          => __('Manual', 'alegra-connector'),
'originChunked'         => __('Chunked', 'alegra-connector'),
'originWebhook'         => __('Webhook', 'alegra-connector'),
```
(`statusAbandoned` está en el design §4.4; los cuatro `origin*` son **corrección C5**: el design los omitió pero AC-35d exige que toda string de UI viva en `get_script_strings()`.)

**Resultado esperado**: 4 orígenes distinguibles en "Procesos Activos" y "Historial Reciente"; un run `stale` se muestra como "Abandonado" (badge neutral); un tipo desconocido cae al valor crudo.

**Dependencias**: ninguna (el `stale` ya lo escriben `mark_stale`/**T1.3b** y `mark_abandoned`/**T1.3**).

**Trazabilidad**: REQ-MON-04, REQ-MON-03.

**Verificación**:
- Source-scan `T28.611`: `admin-monitor.php` contiene `function runTypeLabel`, `runTypeLabel(r.type)` (×2) y `case 'stale'`; `Admin_Dashboard.php` contiene `'statusAbandoned'`, `'originChunked'`, `'originWebhook'`.
- **Prove-it-catches**: quitar el `case 'stale'` → la aserción estática falla; quitar `runTypeLabel` de `renderRecent` → falla el conteo de ocurrencias.
- **Manual**: abrir el Monitor con runs de distinto origen y verificar las etiquetas.

**Riesgo**: que un `run_type` no mapeado rompa el render → el fallback devuelve el valor crudo (y se escapa con `escapeHtml`).

**Estimación**: S (1 h).

---

### T6.4.b — Monitor: estados vacíos/error honestos + cron deshabilitado

**Objetivo**: que el Monitor nunca quede en "Cargando..." infinito ni en silencio ante un error, y que explique por qué no hay cron.

**Descripción técnica**: los placeholders "Cargando..." (`admin-monitor.php:46,58,70`) son el estado inicial; el `error` del `$.ajax` (`:206-208`) es `// Silent fail - retry next poll`; y `renderCron` (`:138-169`) muestra "no hay tareas" aunque el motivo sea que el método es `real-time`/`disabled`. `ajax_monitor_status` (`Admin_Dashboard.php:3691-3751`) no expone `sync_method`. Design **D1 §2.7**. Cubre **REQ-MON-03**, **REQ-MON-06** y **NFR-05**.

**Desarrollo técnico**:
1. `Admin_Dashboard.php`, `ajax_monitor_status` — agregar `sync_method` al payload (`:3744-3750`):
```php
wp_send_json_success([
    'running' => $running_data,
    'cron' => $cron_data,
    'recent' => $recent_data,
    'sync_method' => (string) get_option('alegra_connector_sync_method', 'cron'),
    'kill_switch_active' => \Alegra\Connector\Kill_Switch::is_active(),
    'kill_switch_reason' => \Alegra\Connector\Kill_Switch::reason(),
]);
```
2. `templates/admin-monitor.php`, placeholders `:46,58,70` — reemplazar `<p>Cargando...</p>` por el estado vacío honesto (server-side):
```php
// :46 (running)
<p style="color:var(--ac-text-muted);"><?php esc_html_e('No hay procesos activos.', 'alegra-connector');?></p>
// :58 (cron)
<p style="color:var(--ac-text-muted);"><?php esc_html_e('No hay tareas cron programadas.', 'alegra-connector');?></p>
// :70 (recent)
<p style="color:var(--ac-text-muted);"><?php esc_html_e('Sin historial reciente.', 'alegra-connector');?></p>
```
3. `templates/admin-monitor.php`, `error:` del `refresh()` (`:206-208`) — mostrar el error **una vez por racha** (evita spamear un aviso cada 5 s):
```js
var monitorErrorShown = false;
// ...
success: function(r) {
    if (!r || !r.success) return;
    monitorErrorShown = false;
    // ...
},
error: function() {
    if (!monitorErrorShown) {
        monitorErrorShown = true;
        showNotice(S.monitorError, 'error');
    }
}
```
4. `templates/admin-monitor.php` — **helper local `fmt` + `renderCron(cron, syncMethod)`**. `admin-monitor.php` **no** define `fmt` y nunca lo usó: el `fmt` de `admin.js` es **privado** del IIFE (`admin.js:16`) y no se expone (`window.fmt` → 0), y `templates/admin-import.php:129` define el suyo local. Sin helper propio, `fmt(...)` lanza `ReferenceError` cuando el cron está vacío y `sync_method` es `real-time`/`disabled` (exactamente REQ-MON-06). Se agrega el helper **antes** de `renderCron` (mismo patrón que `admin-import.php:129`):
```js
// admin-monitor.php no tenía fmt: el de admin.js vive privado dentro de su IIFE
// y no se expone. Mismo patrón que templates/admin-import.php:129.
function fmt(tpl, arg) {
    return String(tpl == null ? '' : tpl).replace('%s', arg);
}

function renderCron(cron, syncMethod) {
    if (!cron || cron.length === 0) {
        if (syncMethod === 'real-time' || syncMethod === 'disabled') {
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' +
                escapeHtml(fmt(S.cronDisabled, syncMethod)) + '</p></div>';
        }
        return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' + escapeHtml(S.noCronTasks) + '</p></div>';
    }
    // ... resto igual
}
```
   **Escaping (obligatorio).** El orden es `escapeHtml(fmt(...))`: `fmt` formatea primero y `escapeHtml` (`admin-monitor.php:101-106`) corre sobre el string **ya formateado**, neutralizando `& < > " '` de `syncMethod` (dato de opción) antes de concatenarlo al HTML. El helper `fmt` **no** inyecta HTML por sí solo (a diferencia de un `.html()`); nunca usar `fmt(...)` sin envolverlo en `escapeHtml`.
   Y en `refresh()` (`:203`): `$('#ac-monitor-cron-list').html(renderCron(d.cron, d.sync_method));`
5. `Admin_Dashboard.php`, `get_script_strings()`, agregar (**dueño único: T6.4.b**; T3.3.e sólo la
   consume, **no** la redeclara):
```php
'monitorError'          => __('No se pudo cargar el monitor. Reintentando...', 'alegra-connector'),
'cronDisabled'          => __('La sincronización periódica está desactivada (método: %s).', 'alegra-connector'),
```
(`monitorError` está en el design §4.4; `cronDisabled` es **corrección C5**. **Reconciliación I3:** el
texto canónico de `monitorError` es éste, **no** el de T3.3.e (`No se pudo actualizar el Monitor.`):
nombra la acción real —cargar el monitor— y describe el comportamiento del consumidor —el poll
reintenta—; el texto viejo era genérico y no explicaba la reintentación.)
6. **Mitigación D10 (los webhooks inundan el Historial).** `Runs::recent(15)` (`Runs.php:141-158`)
   ordena por `started_at DESC` y **no** filtra: en una tienda con volumen de webhooks, el historial de
   cron/manual desaparece. En `ajax_monitor_status` (`:3691-3751`), traer `$recent_all = \Alegra\Connector\Runs::recent(25);`
   y particionar por `run_type`, mapeando **ambos** arrays al shape del payload (reemplaza el loop actual de `$recent_data`, `Admin_Dashboard.php:3718-3732`). Ojo: el loop viejo armaba `$recent_data` a partir de `$recent`; si no se mapea `$recent_wh` a `$recent_wh_data`, el payload queda con una variable indefinida (gap L1):
   ```php
   $recent = [];
   $recent_wh = [];
   foreach ($recent_all as $r) {
       if (strpos((string) $r->run_type, 'webhook') === 0) {
           if (count($recent_wh) < 5) { $recent_wh[] = $r; }
       } elseif (count($recent) < 15) {
           $recent[] = $r;
       }
   }

   $recent_data = [];
   foreach ($recent as $run) {
       $recent_data[] = [
           'id' => (int) $run->id,
           'type' => $run->run_type,
           'status' => $run->status,
           'started_at' => $run->started_at,
           'finished_at' => $run->finished_at,
           'items_done' => (int) $run->items_done,
           'total_items' => (int) $run->total_items,
           'items_failed' => (int) $run->items_failed,
           'memory_mb' => $run->memory_peak_mb ? (float) $run->memory_peak_mb : null,
           'error' => $run->error_summary,
       ];
   }

   $recent_wh_data = [];
   foreach ($recent_wh as $run) {
       $recent_wh_data[] = [
           'id' => (int) $run->id,
           'type' => $run->run_type,
           'status' => $run->status,
           'started_at' => $run->started_at,
           'finished_at' => $run->finished_at,
           'items_done' => (int) $run->items_done,
           'total_items' => (int) $run->total_items,
           'items_failed' => (int) $run->items_failed,
           'memory_mb' => $run->memory_peak_mb ? (float) $run->memory_peak_mb : null,
           'error' => $run->error_summary,
       ];
   }
   ```
   Agregar `'recent_webhooks' => $recent_wh_data` al payload (`:3744-3750`) y renderizar en
   `templates/admin-monitor.php` una sección **"Webhooks recientes"** (máx. 5) con `d.recent_webhooks`,
   dejando `renderRecent(d.recent)` para el historial no-webhook. Así los webhooks siguen observables
   pero no desplazan a cron/manual. (Coordina con design §2.7/R6: los runs webhook se siguen creando y
   `Maintenance::prune` los limpia por retención.)

**Resultado esperado**: sin runs → mensaje honesto (no "Cargando..." infinito); si el AJAX falla → un `showNotice` de error (una vez por racha); con `sync_method=real-time` y `cron:[]` → "La sincronización periódica está desactivada (método: real-time)"; el `error_summary` sigue renderizándose (`:175,:186`); el Historial no queda copado por webhooks (se muestran en una sección aparte, cap 5).

**Dependencias**: ninguna (el payload de `error_summary` ya existe: `Admin_Dashboard.php:3730` + `admin-monitor.php:175,186`).

**Trazabilidad**: REQ-MON-03, REQ-MON-06, NFR-05.

**Verificación**:
- Runtime `T28.612` (payload):
  ```php
  alegra_test_reset();
  $GLOBALS['wp_options']['alegra_connector_sync_method'] = 'real-time';
  $admin = new Admin_Dashboard(make_api(), make_logger());
  $resp = alegra_capture_json(static fn() => $admin->ajax_monitor_status());
  TestRunner::assertTrue($resp->success, 'monitor responde success');
  TestRunner::assertSame('real-time', $resp->payload['sync_method'] ?? null, 'el payload expone sync_method');
  ```
- Source-scan `T28.613`: `admin-monitor.php` **no** contiene `// Silent fail - retry next poll`; contiene `function fmt`, `monitorError`, `sync_method`, `cronDisabled`, `escapeHtml(fmt(S.cronDisabled`, `renderCron(d.cron, d.sync_method)`; `Admin_Dashboard.php` contiene `'sync_method' => (string) get_option('alegra_connector_sync_method'`. (La aserción `function fmt` es la red que caza el `ReferenceError` de B2: sin el helper local, la sección Cron rompe en REQ-MON-06 y ningún test runtime la ejercita.)
- Source-scan `T28.615` (D10): `Admin_Dashboard.php` contiene `'recent_webhooks'`, `$recent_wh_data[]` (construcción que cierra el gap L1) y el filtro `strpos((string) $r->run_type, 'webhook')`; `admin-monitor.php` contiene `d.recent_webhooks`.
- **Prove-it-catches**: quitar `sync_method` del payload → `T28.612` falla; reponer `// Silent fail` → `T28.613` falla; quitar el helper local `fmt` → `T28.613` falla (B2); quitar el filtro de webhooks del historial → `T28.615` falla; quitar la construcción `$recent_wh_data[]` → `T28.615` falla (gap L1).

**Riesgo**: que el aviso de error se repita cada poll → guard `monitorErrorShown`. Que el placeholder honesto mienta durante el primer poll → ventana mínima (el poll corre en `$(document).ready`); el error path lo cubre.

**Estimación**: M (1,5 h).

---

### T6.5 — D6: default de `sync_products` + aserción de regresión · BLOQUEADO(Fase 0.3)

**Objetivo**: dejar el default de `alegra_connector_sync_products` consistente entre UI, runtime y activación, y congelarlo con una aserción.

**Descripción técnica**: el default `false` está en tres lugares: `alegra-connector.php:424` (`$defaults`), `Controller.php:171` (lectura del cron) y la UI `templates/admin-settings.php:78-81`. Design **D6 §7**: **Rama A (recomendada)** → queda `false`, sin migración; **Rama B** → `true` con siembra solo si la opción está ausente + nota de release. El `else` de log de `T2.1` cubre REQ-CON-01. Cubre **REQ-CON-02**, **REQ-CON-01**, **NFR-06**. **Gate G3 (T0.3) decide la rama.**

**Desarrollo técnico**:
- **Rama A (recomendada)**: **no** se cambia ningún default. Solo se agrega la aserción de regresión en `scripts/exec-test.php`:
  ```php
  TestRunner::test('T28.614 sync_products queda false en las tres fuentes de verdad', function (): void {
      alegra_test_reset();
      $root = $GLOBALS['alegra_plugin_root'];
      $main = (string) file_get_contents($root . 'alegra-connector.php');
      $ctrl = (string) file_get_contents($root . 'includes/Sync/Controller.php');
      $ui   = (string) file_get_contents($root . 'templates/admin-settings.php');
      TestRunner::assertStringContains("'alegra_connector_sync_products' => false", $main, 'default del activador = false');
      TestRunner::assertStringContains("get_option('alegra_connector_sync_products', false)", $ctrl, 'runtime del cron = false');
      TestRunner::assertStringContains("get_option('alegra_connector_sync_products',false)", $ui, 'UI = false');
      TestRunner::assertStringNotContains("alegra_connector_sync_products',true", $ui, 'la UI no debe usar true');
      TestRunner::assertSame(false, get_option('alegra_connector_sync_products', false), 'sin opción, el runtime cae a false');
  });
  ```
- **Rama B (solo si G3 la elige)**: cambiar `alegra-connector.php:424` a `true` y sembrar **solo si la opción está ausente**. Ojo con la semántica de `get_option` (ver Riesgo): el loop de activación ya usa `get_option($key) === false` (`:473`) y este plugin siembra vía `add_option`, que guarda un `false` como `''`; el chequeo `=== false` sigue significando "ausente". Para robustez explícita, usar centinela:
  ```php
  // En el loop de activación, antes de add_option:
  if (get_option('alegra_connector_sync_products', '__absent__') === '__absent__') {
      add_option('alegra_connector_sync_products', true, '', 'no');
  }
  ```
  Respetar un `false` explícito + nota de release + test del escenario Rama B (instalación nueva importa; instalación con `false` explícito no cambia).

**Resultado esperado**: con la rama A, las tres fuentes dicen `false`; el cron no toca productos salvo toggle; la UI muestra el checkbox destildado (UI == runtime); manual/webhook funcionan igual.

**Dependencias**: **T0.3 (G3)** para fijar la rama. Nada más.

**Trazabilidad**: REQ-CON-02, REQ-CON-01, NFR-06.

**Verificación**:
- Test `T28.614` (arriba). **Prove-it-catches**: cambiar el default de `alegra-connector.php:424` a `true` sin rama B → la aserción estática falla.
- Manual: `Alegra Connector → Configuración → Sincronización`; el checkbox "Productos" aparece destildado en una instalación nueva.

**Riesgo**: R12 (cambiar el default importa catálogo no deseado). Guardas: rama A recomendada; si B, sembrar solo si ausente + release note. **Nuance**: `get_option($k) === false` es el patrón que ya usa el activador (`:473`) y funciona porque `add_option` guarda `false` como `''`; el centinela `'__absent__'` es la versión explícita y es la que debe usarse en la rama B.

**Estimación**: S (1 h, rama A) / M (2 h, rama B).

---

## Tabla canónica de strings i18n (dueño único — cierra I3)

`get_script_strings()` (`Admin_Dashboard.php:652`) es un **único array**: si dos tareas declaran la
misma clave, la última pisa a la primera **en silencio** (y si el texto difiere, gana el último sin
aviso). Por eso cada clave tiene **un solo dueño** — el task que declara el literal; las demás fases
**consumen** la clave vía `S.<key>` / `fmt(S.<key>, …)` y **NO** la redeclaran.

| Clave | Texto canónico | Dueño (declara) | Consumidor |
|---|---|---|---|
| `pausedResuming` | `Pausado por tiempo; continúa con la próxima página...` | **T3.3.e** | T3.3.b (chunked JS) |
| `resumingFrom` | `Reanudando desde el ítem %s...` | **T3.3.e** | T3.3.a (chunked JS) |
| `confirmReimport` | `Esto reimporta TODO el catálogo desde cero y limpia el punto de reanudación. ¿Continuar?` | **T3.3.e** | T4.5 (botón reimportar) |
| `startingFromZero` | `Empezando de cero...` | **T3.3.e** | Fase 4 (sin consumidor documentado) |
| `confirmRecreateManual` | `¿Recrear también los productos que borraste a mano?` | **T3.3.e** | T4.1 (label del checkbox) |
| `imagesFailed` | `%1$s imágenes no se pudieron importar (%2$s host no permitido, %3$s fallo de descarga, %4$s fallo al adjuntar, %5$s diferidas).` | **T5.2b** | T3.3.b + T5.2b |
| `statusAbandoned` | `Abandonado` | **T6.4.a** | T6.4.a (badge `stale`) |
| `originCron` | `Cron` | **T6.4.a** | T6.4.a (`runTypeLabel`) |
| `originManual` | `Manual` | **T6.4.a** | T6.4.a (`runTypeLabel`) |
| `originChunked` | `Chunked` | **T6.4.a** | T6.4.a (`runTypeLabel`) |
| `originWebhook` | `Webhook` | **T6.4.a** | T6.4.a (`runTypeLabel`) |
| `monitorError` | `No se pudo cargar el monitor. Reintentando...` | **T6.4.b** | T6.4.b (poll del Monitor) |
| `cronDisabled` | `La sincronización periódica está desactivada (método: %s).` | **T6.4.b** | T6.4.b (`renderCron`) |
| `confirmClearLogs` | `Esto borrará TODOS los logs, incluido el de hoy. No se puede deshacer. ¿Continuar?` | **T6.1.b** | `admin.js:387` |

> **Regla para el worker.** Si una fase necesita una clave de esta tabla, la **consume**; **no** la
> agrega a `get_script_strings()`. Agregarla duplicaría el literal y, si el texto difiere, la última
> declaración ganaría en silencio. `T3.3.e` **sólo** declara las claves cuyo dueño es Fase 3; las
> compartidas (`imagesFailed`, `monitorError`, `statusAbandoned`) se consumen, no se redeclaran.

---

## DoD Fase 6 (checklist de cierre)

- [ ] `T28.61`–`T28.615` verdes; cada uno con prove-it-catches registrado.
- [ ] REQ-LOG-05/06/07, REQ-MON-03/04/06, REQ-CON-02, NFR-03, NFR-06 verdes.
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con ≥ 15 aserciones nuevas (baseline 1289).
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`.
- [ ] `_n()` agregado a `scripts/lib/wp-stubs.php` (prerrequisito de `T28.62`).
- [ ] **`T6.2.a` implementado antes que `T6.1.a`**: sus tests usan `Logger::get_log_dir()`, que **crea T6.2.a** (no existe en HEAD).
- [ ] Modelo de `wp_alegra_runs` del harness (**`T1.1a`**, Fase 1; `T7.1.a` sólo aditivo) disponible si algún test de esta fase lo necesita.
