# REVIEW final adversarial — `logs-monitor-import`

| Campo | Valor |
|---|---|
| Tipo | Auditoría adversarial **read-only** del plan (no se tocó ningún artefacto del plan) |
| Fecha | 2026-09-25 |
| Método | Lectura íntegra de los 14 artefactos + contraste de **cada** claim contra el código en HEAD (`read`/`grep`) + ejecución mental de los tests propuestos contra el harness real |
| Foco | Encontrar lo que las 3 rondas de fixes **omitieron o introdujeron** (un fix que arregla un archivo y rompe otro) |
| Veredicto | **REJECT** — no es seguro entregarlo a un worker con cero preguntas |

> Las 3 rondas cerraron los sospechosos del enunciado: `DR1–DR11` vs `R1–R13` (design §11 vs fase-7 §T7.3),
> `T1.1a` vs `T7.1.a`, `alegra_test_json_observer`, `confirmClearLogs`, `chunked_import`. **Todo eso está
> resuelto.** Los defectos de abajo son **nuevos**: ninguno aparece en `REVIEW-momus.md`,
> `REVIEW-oracle.md` ni `REVIEW-final-consistency.md`.

---

## 0. Resumen ejecutivo

| Sev. | # | Hallazgo | Archivo:línea |
|---|---|---|---|
| **BLOQUEANTE** | B1 | El guard de conexión nuevo de `ajax_sync_start` **rompe el test base `T21.4`** (y su propio test `T28.28`): el harness nunca siembra `alegra_connector_connection_tested`. Adiós al "1289 base verdes". | `fase-2:607-611` vs `scripts/exec-test.php:3503-3517` |
| **BLOQUEANTE** | B2 | `fmt()` **no existe** en `admin-monitor.php`: el nuevo `renderCron` llama `fmt(S.cronDisabled, …)` y `fmt` es privado del IIFE de `admin.js`. `ReferenceError` en el Monitor. | `fase-6:557-558` vs `admin/assets/js/admin.js:16` |
| **BLOQUEANTE** | B3 | **Dependencia circular Fase 2 ↔ Fase 3**: el esqueleto de `T2.4` usa variables (`$tp/$processed/$done/$pct`) y métodos (`reset_image_stats`/`set_deadline`) que definen `T3.1.c`/`T3.2.b`, pero `tasks.md` ordena Fase 2 antes que Fase 3 y exige Fase 2 verde. | `fase-2:1102-1127` vs `fase-3:186-195`, `fase-3:355-357` |
| **ALTA** | H1 | **Pausa por presupuesto se reporta como "detenida por el usuario" / `cancelled`**: `$result['paused']` se setea en la pausa de 240 s, no en el stop. Viola REQ-RES-04 y la decisión de UI honesta. | `fase-2:503-514` vs `Products.php:1298-1305` |
| **ALTA** | H2 | **Momus R3 sigue sin arreglar**: `'skipped'` (tombstone/variante/kill-switch/cancel) se cuenta como `errors` en `Products::import_from_alegra`. | `Products.php:1357-1360`; `fase-3:293-298` |
| **ALTA** | H3 | Tests de Fase 6 usan `$resp->data[...]`; el harness expone `$resp->payload[...]`. Los tests `T28.62`/`T28.612` fallan. | `fase-6:192,193,611` |
| MEDIA | M1 | `set_deadline`/`image_stats` atribuidos a `T3.2.a`; viven en `T3.2.b`. La nota de paralelismo contradice el diagrama de build. | `tasks.md:116-117`, `fase-3:739-740` |
| MEDIA | M2 | `design.md §2.1` muestra un `Run_Context::finish()` **sin** el guard de `Runs::status` ni el borrado del stop; `T1.1b`/`design §2.5` exigen lo contrario. | `design.md:135-141` vs `fase-1:255-275` |
| BAJA | L1 | `$recent_wh_data` se usa pero nunca se construye (el loop arma `$recent_wh`, no `..._data`). | `fase-6:576-591` |
| BAJA | L2 | `T28.312` (blocked reflejado) vive en Fase 3 pero depende de `T5.2a` (Fase 5). | `fase-3:711-712` |
| BAJA | L3 | La nota de `T4.4` dice que `import_single_item_public` no recibe `$run_id`; `T3.2.a` se lo agregó. | `fase-4:835-836` |

---

## 1. BLOQUEANTES

### B1 — El guard de conexión de `ajax_sync_start` rompe el test base `T21.4`

**Qué dice el plan** (`fase-2:607-611`, T2.3):

```php
if (!get_option('alegra_connector_connection_tested')) {
    \Alegra\Connector\Run_Context::fail_early('chunked_import', 'connection_not_tested');
    wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
}
```

**Realidad verificada:**

- `alegra_test_reset()` (`scripts/lib/test-framework.php:144-208`) **no** setea
  `alegra_connector_connection_tested`. Grep en todo `scripts/`: sólo **3** tests lo setean a mano
  (`exec-test.php:3545,3560,5216`). El stub `get_option()` devuelve el default (`false`) si la clave no
  existe (`wp-stubs.php:193-198`).
- El test existente **`T21.4`** (`exec-test.php:3503-3517`) hace `alegra_test_reset()`, siembra ítems y
  llama `ajax_sync_start()` **sin** setear `connection_tested`; luego asserta
  `assertTrue($resp->success)`.

**Consecuencia:** con el guard nuevo, `ajax_sync_start` responde `success:false` →
`T21.4` falla. Es una **regresión de un test del baseline** (parte de los 1289), y contradice el DoD
repetido "los 1289 tests base siguen verdes" (`tasks.md:495`, `fase-7:35,128,517`).

Además, el **propio test nuevo** `T28.28` (`fase-2:780-783`) llama `ajax_sync_start()` sin sembrar la
opción → también falla. El plan nunca menciona actualizar `T21.4`.

**Fix:** sembrar `update_option('alegra_connector_connection_tested', true)` en `T21.4` (o en
`alegra_test_reset()`, que es lo más limpio y hace que todos los tests de import arranquen "conectados")
**y** en `T28.28`; y declararlo en la tabla de correcciones de Fase 2. Sin esto, el primer gate de la fase
es rojo.

---

### B2 — `fmt()` no existe en `admin-monitor.php` (ReferenceError en el Monitor)

**Qué dice el plan** (`fase-6:557-558`, T6.4.b):

```js
return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' +
    escapeHtml(fmt(S.cronDisabled, syncMethod)) + '</p></div>';
```

**Realidad verificada:**

- `fmt` es una función **privada** dentro del IIFE de `admin.js` (`admin/assets/js/admin.js:16`,
  `function fmt(tpl, ...args)`), nunca se expone (`window.fmt` → 0 coincidencias).
- `templates/admin-import.php:129` define **su propio** `fmt` local. `templates/admin-monitor.php`
  **no** define `fmt` y **nunca** lo usó: su IIFE (`admin-monitor.php:79-348`) tiene `escapeHtml`
  (`:101`), `showNotice` (`:327`) y `statusBadge`, pero no `fmt`.
- Leer un identificador no declarado con `'use strict'` lanza `ReferenceError`.

**Consecuencia:** cuando `cron` está vacío y `sync_method` es `real-time`/`disabled`, `renderCron`
explota en `fmt` y **la sección Cron del Monitor queda rota** (justo el escenario que T6.4.b viene a
arreglar: REQ-MON-06). No lo caza ningún test (T28.613 es source-scan de texto, no ejecuta el JS).

**Fix:** agregar un helper local `fmt` a `admin-monitor.php` (copiar el de `admin-import.php`) o usar
`String(S.cronDisabled).replace('%s', syncMethod)`.

---

### B3 — Dependencia circular Fase 2 ↔ Fase 3 (`T2.4` ↔ `T3.1`/`T3.2.b`)

**El ciclo:**

- `tasks.md:60` y `fase-3:8`: **Fase 3 depende de Fase 2** (`T2.3`, `T2.4`).
- `fase-2:1165-1167` (T2.4 "Dependencias"): "**T3.1** completa el loop con deadline/offset".
- El esqueleto de `T2.4` (`fase-2:1102-1127`) usa `$tp`, `$processed`, `$done`, `$pct` y el `if ($done)`,
  que **fija `T3.1.c`** (`fase-3:186-195`). Y sus tests `T28.216`/`T28.217`
  (`fase-2:1190-1197`) assertan `$resp->payload['done']`, o sea necesitan `T3.1.c`.
- `T2.4` además llama `Sync\Products::reset_image_stats()` (vía el bloque products de `T3.1.b`,
  `fase-3:122`) y el loop usa `import_single_item_public($item, $run_id)` con `set_deadline`/`clear_deadline`
  (`fase-3:121,147`), todo definido en **`T3.2.b`** (`fase-3:349-357`).

**Consecuencia:** siguiendo el orden de `tasks.md` (Bloque 2 → Bloque 3) con el DoD "Fase 2 verde",
`T2.4` no compila/pasa: variables indefinidas y `Call to undefined method Products::reset_image_stats()`.
En la práctica `T2.4` y `T3.1`/`T3.2` son **la misma edición de `ajax_sync_page`** y hay que hacerlas
juntas; el plan las presenta como fases secuenciales con gates de verde independientes.

**Fix:** declarar explícitamente que `T2.4` se implementa **junto con** `T3.1`/`T3.2.b` (un solo commit),
o reordenar el Bloque 3 antes del cierre de Fase 2, o mover la creación de `$done/$tp/$processed/$pct`
y de los accessors de imagen a `T2.4`.

---

## 2. INCONGRUENCIAS (introducidas o no cerradas por los fixes)

### H1 — Pausa por presupuesto == "detenida por el usuario" / `cancelled`

**El plan** (`fase-2:503-514`, T2.2) afirma: *"`$result['paused']` lo setea `Products.php:1305`"* y lo usa
como señal de stop:

```php
if (!empty($result['paused']) || \Alegra\Connector\Runs::should_stop($run_id)) {
    \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
    ...
    'message' => __('Importación detenida por el usuario.', 'alegra-connector'),
}
```

**Realidad** (`includes/Sync/Products.php`):

- `:1304-1309` → `$result['paused'] = true` es la **pausa por presupuesto de 240 s** (`import_time_budget`).
- El **stop del usuario** (`:1298-1301` y `:1353-1356`) hace `break`/`break 2` **sin** tocar `paused`.

**Consecuencia:** un import manual de la página Importar que corta por presupuesto (normal en catálogos
grandes) termina la fila como **`cancelled`** y muestra **"Importación detenida por el usuario"** — falso.
Viola **REQ-RES-04** ("el resultado DEBE indicar que quedó pausado, el cursor y cuántos quedan") y la
decisión textual del comerciante de UI sin ambigüedades. El comentario del plan cita la línea de pausa
como si fuera el stop: confusión de semántica.

**Fix:** la rama `cancelled` debe depender **solo** de `Runs::should_stop($run_id)`; para
`$result['paused']` (presupuesto) hay que reportar pausa honesta con cursor y pendientes (y decidir un
estado de run que no sea "Cancelado"; hoy el enum no tiene `paused`, así que la decisión es de diseño).

### H2 — `'skipped'` contado como `errors` (Momus R3 sin cerrar)

`REVIEW-momus.md` R3 lo marcó; el fix no aterrizó. `Products::import_from_alegra`
(`Products.php:1357-1360`) sigue con:

```php
$r = $this->import_single_item_from_alegra($item);
if ($r === true) $result['imported']++;
elseif ($r === 'updated') $result['updated']++;
else $result['errors']++;
```

Y `T3.2.a` (`fase-3:293-298`) **solo** agrega la rama `'stopped'`, dejando `'skipped'` en el `else`.
`import_single_item_from_alegra` devuelve `'skipped'` por variante (`:1423-1425`), kill-switch/cancel
(`:1410-1415`) y **tombstone** (`:1429-1436`). Por lo tanto el import manual y el cron **infla `errors`**
con ítems salteados legítimamente, y el log/resumen no puede distinguir `skipped` de `errors`
(REQ-LOG-02 lo exige; REQ-RES-04 depende de los conteos). La ruta chunked sí lo maneja bien
(`fase-3:144`: `elseif ($r === 'skipped') { continue; }`), lo que hace la inconsistencia entre rutas aún
más visible.

**Fix:** `elseif ($r === 'skipped') { $result['skipped'] = ($result['skipped'] ?? 0) + 1; }` y agregar
`'skipped' => 0` al `$result` inicial (`Products.php:1256`).

### H3 — Tests de Fase 6 usan `$resp->data` (el harness usa `payload`)

`Alegra_Test_JSON_Response` (`scripts/lib/wp-stubs.php:496-508`) expone `public bool $success` y
`public array $payload`. **No** tiene `$data`. `alegra_capture_json()` (`test-framework.php:311-320`)
devuelve esa excepción. Todo el plan usa `$resp->payload` **salvo**:

- `fase-6:192` → `assertSame(1, $resp->data['files'], …)` (T28.62)
- `fase-6:193` → `assertStringContains('archivo de log eliminado', $resp->data['message'], …)` (T28.62)
- `fase-6:611` → `assertSame('real-time', $resp->data['sync_method'] ?? null, …)` (T28.612)

**Consecuencia:** `$resp->data` es propiedad indefinida → warning + `null[...]` → asserts fallan. Los
tests de T6.1.a y T6.4.b no pasan tal como están escritos.

**Fix:** cambiar los tres a `$resp->payload[...]`. (Nota: `fase-3:33` C9 ya documenta que la propiedad
correcta es `payload`; Fase 6 no se alineó.)

### M1 — Dueño de `set_deadline`/`image_stats` mal atribuido; nota de paralelismo imposible

`tasks.md:116-117` y `fase-3:739-740` dicen:

> "`T3.2.b` puede ir en paralelo con `T3.1.b` una vez que **`T3.2.a`** define `set_deadline`/`image_stats`."

Falso: `set_deadline`, `clear_deadline`, `deadline_exhausted`, `reset_image_stats`, `image_stats` los
define **`T3.2.b`** (`fase-3:349-357`). `T3.2.a` (`fase-3:253-343`) no los toca. Como `T3.1.b` **llama**
esos métodos (`fase-3:121-122,147`), `T3.2.b` **debe ir antes** que `T3.1.b`, no en paralelo. El
diagrama de `tasks.md:111-114` sí los encadena (`T3.2.b ─► T3.1.b`), contradiciendo la nota.

**Fix:** corregir el dueño a `T3.2.b` y borrar la frase "en paralelo con T3.1.b".

### M2 — `design.md §2.1` muestra un `finish()` sin guard ni limpieza del stop

`design.md:135-141` (pseudo de `Run_Context::finish`) hace solo
`Runs::finish(...)` + `Heartbeat::forget(...)` + `Logger::clear_run_context()`: **no** consulta
`Runs::status()` ni borra `alegra_run_stop_{id}`. Pero `design.md:319-323` (§2.5 punto 4) dice que el
guard es una "corrección necesaria", y `T1.1b` (`fase-1:255-275`) implementa `finish()` con guard +
`teardown()` que borra el stop. Un worker que copie el pseudo de §2.1 (como manda la sección "el
diseño es cerrado") implementa la versión sin guard y rompe R9.

**Fix:** actualizar `design.md §2.1` para reflejar el `finish()` real de `T1.1b`.

### L1/L2/L3 — Residuos menores

- **L1** `fase-6:576-591`: el loop de partición construye `$recent` y `$recent_wh` (objetos `run`), pero
  el payload referencia `$recent_wh_data`, que **nunca se define**. Hay que mapear `$recent_wh` a un
  array `$recent_wh_data` (como el loop existente de `$recent_data`, `Admin_Dashboard.php:3718-3732`).
- **L2** `fase-3:711-712`: `T28.312` ("blocked reflejado") está en Fase 3 pero requiere `T5.2a`
  (Fase 5) para incrementar `blocked`. No puede pasar en el gate de Fase 3.
- **L3** `fase-4:835-836`: "`import_single_item_public` no recibe `$run_id`" — `T3.2.a` se lo agregó
  (`fase-3:261`). El test igual funciona por el default `0`, pero la nota quedó stale.

---

## 3. GAPS

1. **Cobertura del guard de conexión**: el plan no testea el nuevo early-exit de `ajax_sync_start` de
   forma aislada sin romper `T21.4` (B1). Falta el seed y/o un test que primero limpie la opción.
2. **REQ-MON-06 "kill switch activo → la sección Cron muestra el motivo"**: sigue sin tarea
   (`tasks.md:308` lo reconoce como cosmético). `T6.4.b` cubre `sync_method ∉ {cron,both}` y el error
   del AJAX, no el kill switch en la sección Cron. Aceptable, pero es un escenario de la spec sin
   implementar.
3. **`$recent_wh_data`** (L1): falta la definición; el payload quedaría con `undefined`/error.
4. **Presupuesto vs. `download_url`**: Oracle ya notó que con `budget=40` + una imagen de 15 s se roza el
   `set_time_limit(60)`; el default 20 s lo mitiga, pero el plan no declara el techo real (imagen lenta +
   sideload). No es bloqueante; conviene documentarlo.

---

## 4. ORDERING DEFECTS

| # | Defecto | Evidencia |
|---|---|---|
| O1 | **Ciclo Fase 2 ↔ Fase 3**: `T2.4` usa `$tp/$processed/$done/$pct` (de `T3.1.c`) y `reset_image_stats`/`set_deadline` (de `T3.2.b`) antes de que existan. | `fase-2:1102-1127`, `fase-3:186-195,355-357`, `tasks.md:60` |
| O2 | **`T3.2.b` antes que `T3.1.b`** (no en paralelo). `T3.1.b` llama los accessors de `T3.2.b`. | `fase-3:121-122,147`, `tasks.md:116-117` |
| O3 | `T28.312` (Fase 3) depende de `T5.2a` (Fase 5). | `fase-3:711-712` |
| O4 | `T6.1.a` declara (ya corregido) `T6.2.a → T6.1.a`; ok. Pero el orden de `tasks.md:143` aún lista `T6.1.a` antes de `T6.2.a` en el diagrama de bloques. | `tasks.md:143-149` vs `fase-6:161` |

---

## 5. VIOLACIONES DE DECISIONES DEL COMERCIANTE

**Ninguna violación material.** Verificación una a una:

1. **"Limpiar logs" borra TODO** → `T6.1.a` (`clear_all_logs`), `T6.1.b` (label + confirm). ✔
2. **Dos botones (reanudar vs reimportar)** → `T4.1`, `T4.2`, `T4.5`. ✔
3. **Chunks con progreso visible, sin subir timeout** → `T3.1` (budget propio 20 s), `T3.3`, `T3.4`. ✔
4. **Mayormente manual, webhooks actualizan, cron temporal, modalidad como está** → `T2.1`, `T2.5`;
   no se toca `sync_method`. ✔
5. **Plugin distribuido (cualquier tienda)** → budget propio, sin `exec`, allowlist configurable,
   Monitor degradable. ✔
6. **Sin DIAN/facturación electrónica** → fuera de alcance; no se toca. ✔
7. **Sin carpetas de test en el ZIP** → tests en `scripts/` (excluido por `.distignore`). ⚠ **Matiz:**
   `H1.c` (`tasks.md:194,211-215`) agrega `Controller::reset_locks_for_testing()` a
   `includes/Sync/Controller.php`, que **sí shipea**. Es un método solo-test en producción, no una
   carpeta de test; el plan lo declara. No viola la letra, pero roza el espíritu de "nada de cosas de
   test en el plugin". Recomendación: envolverlo en `if (defined('ALEGRA_CONNECTOR_TESTING'))` o
   aceptarlo explícitamente en la nota de release.

---

## 6. OVER-ENGINEERING / RIESGO

1. **Heurístico `classify_delete_reason()` (`T4.3b`)** — complejidad real (4 shapes de `$_REQUEST` +
   `action2` + `delete_all`/`delete_all2`) con confiabilidad incompleta (REST/WP-CLI/bulk de 1 ítem →
   `manual_wc`). La red de seguridad es el checkbox `ignore_all` por default. Es defendible, pero es la
   pieza más frágil del plan; el copy de UI no debe prometer detección total (el plan ya lo dice en
   `fase-4:561-562`).
2. **Opción `allowed_image_hosts_extra` implementada en ambas ramas** (`T5.3a`/`T5.3b`) aunque G1
   probablemente cierre Rama A (host ya en `*.alegra.com`, `fase-5:519-521`). En Rama A agrega un
   textarea en Ajustes que el comerciante no necesita. Para plugin distribuido se justifica; el costo
   es bajo.
3. **Anti-doble-arranque con heartbeat (`T2.3`, `fase-2:625-635`)** — usa `Heartbeat::get() !== null`
   (TTL 120 s) como prueba de vida. Una página que tarde >120 s (imágenes lentas) expira el heartbeat y
   una segunda pestaña podría borrar el state vivo y arrancar otro run. Ventana chica, pero real.
4. **`mark_abandoned` no borra `alegra_batch_state`** (`fase-1:530-556`): un run marcado `stale` deja el
   state; una pestaña zombie que reintente `ajax_sync_page` puede resumir el run `stale` (el guard de
   `finish` evita pisar el estado, pero se procesan ítems sin fila coherente). Oracle ya lo listó
   (race #3); sigue sin guard.

---

## 7. FINAL CALL

**No está listo para un worker con cero preguntas.** Los defectos B1/B2/B3 son **ejecución-roja**
(regresión de un test base, `ReferenceError` en el Monitor, y un ciclo de build), y H1/H2/H3 son
**correctitud/DoD** (estado y mensaje falsos, conteos inflados, tests que no pasan). No son de
arquitectura: son quirúrgicos y localizados.

**Mínimo indispensable antes de repartir trabajo:**

1. Sembrar `alegra_connector_connection_tested` en `T21.4` y `T28.28` (B1).
2. Definir `fmt` en `admin-monitor.php` o reemplazar `fmt(...)` por `.replace('%s', …)` (B2).
3. Declarar que `T2.4` se hace junto con `T3.1`/`T3.2.b` (o reordenar) (B3).
4. Separar "pausa por presupuesto" de "stop del usuario" en `T2.2` (H1).
5. Agregar la rama `'skipped'` en el loop de `Products::import_from_alegra` (H2).
6. Cambiar `$resp->data` → `$resp->payload` en `fase-6:192,193,611` (H3).
7. Corregir el dueño/paralelismo de `T3.2.b` y el `finish()` de `design §2.1` (M1/M2).

Con esos 7 cambios (todos de menos de una hora), el plan queda ejecutable. Sin ellos, el primer
`bash scripts/exec-test.sh` de Fase 2 falla y el Monitor rompe en el escenario de REQ-MON-06.
