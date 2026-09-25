# Tareas — Logs, Monitor e Importación de productos (`logs-monitor-import`)

> **MASTER INDEX (reescrito).** Este archivo es el índice navegable **y** el contrato de ejecución
> del plan expandido. Las tareas micro-detalladas viven en `tasks/fase-N-*.md`; acá está el orden
> real de build, los prerequisitos transversales de harness, los gates que bloquean, la matriz de
> trazabilidad REQ→tarea, el audit trail de citas corregidas y el registro de las dos revisiones
> adversariales.
>
> **Esta versión refleja el PLAN CORREGIDO por los 4 fix agents** (B1/B2/B3, D1–D13, C1/C3, K1/K2/K3).
> Se **recontó** cada fase desde sus archivos; no se heredaron los números viejos.

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` (observabilidad + monitor de procesos + importación de productos) |
| Versión analizada | 2.4.2 (`alegra-connector.php:6`) |
| Versión objetivo | **2.5.0** (cambio de comportamiento + opciones nuevas ⇒ minor) |
| Estado global | **PLAN REVISADO — bloqueantes cerrados** · 3 requerimientos `BLOQUEADO(ON-VERIFICATION)` por Fase 0 (REQ-RES-03, REQ-IMG-04, REQ-CON-02) |
| Micro-tareas | **54** en 8 fases (4+7+5+11+6+6+8+7) + prerequisitos de harness transversales |
| Tests | Viven en `scripts/` (**excluido del ZIP de release** vía `.distignore`). **NO** se agregan carpetas de test al plugin (restricción del comerciante). |
| Harness | `bash scripts/exec-test.sh` (baseline **1289 assertions**, 0 failed) · `bash scripts/smoke-test.sh` |
| Convención de task ID | `T<fase>.<n>[a-z]` · `[ ]` pendiente · `[x]` hecho · `BLOQUEADO(Fase 0.x)` |
| Convención de test ID | Sección `// === logs-monitor-import (2.5.0) ===` con IDs **`T28.{fase}{n}`** (el harness ya usó `T1..T27`) |
| Reviews | `REVIEW-momus.md` (**APPROVE-WITH-FIXES**) · `REVIEW-oracle.md` (**SOUND-WITH-FIXES**) |

## Mapa de artefactos

| Artefacto | Contenido |
|---|---|
| `proposal.md` | Problema, decisiones del comerciante, hallazgos A1–A4/B1–B11/C1–C3, criterios de éxito |
| `spec.md` | 27 REQ + 7 NFR en EARS/Gherkin, con escenarios verificables |
| `design.md` | D1–D6, firmas exactas, matriz de archivos, riesgos **DR1–DR11** (los `R1–R15` de ejecución viven en `tasks/fase-7`) |
| `tasks.md` | **Este archivo** — índice maestro + contrato de ejecución |
| `tasks/fase-0-gates.md` | Fase 0 — gates G1–G4 (`T0.1`–`T0.4`) |
| `tasks/fase-1-cimientos.md` | Fase 1 — cimientos (`T1.1a`, `T1.2`, `T1.3`, `T1.3b`, `T1.4`, `T1.1b`, `T1.5`) |
| `tasks/fase-2-instrumentacion.md` | Fase 2 — instrumentación de los 4 caminos (`T2.1`–`T2.5` + harness H1.a/b/c) |
| `tasks/fase-3-chunked.md` | Fase 3 — chunked con presupuesto propio (`T3.1.a`–`T3.4`, 11 micro) |
| `tasks/fase-4-botones-tombstones.md` | Fase 4 — reanudar vs reimportar + tombstones (`T4.1`–`T4.5`, 6 micro + H1.d/H2) |
| `tasks/fase-5-imagenes.md` | Fase 5 — imágenes (`T5.1a`–`T5.3b`, 6 micro) |
| `tasks/fase-6-ui-logs-monitor.md` | Fase 6 — logs, monitor, D6 (`T6.1.a`–`T6.5`, 8 micro) |
| `tasks/fase-7-regresion-release.md` | Fase 7 — regresión y release 2.5.0 (`T7.1.a`–`T7.4.c`, 7 micro) |
| `phase0-results.md` | Salida única de Fase 0 (**se crea en T0.1**) |

> **Regla de oro (prove-it-catches, obligatoria).** Después de que un test pase: **revertir el fix**
> → `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Sin esto,
> el test no se acepta. Varias tareas indican el fix exacto a revertir.
>
> **Regla de oro (Fase 0).** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta que el gate
> tenga su rama registrada en `phase0-results.md`. **Fase 1 no depende de ningún gate**: se puede
> empezar ya.

---

## 2. Mapa de fases (IDs reales, recontados)

| Fase | Archivo | Micro-tareas (IDs reales) | Foco | Depende de |
|---|---|---|---|---|
| 0 | `fase-0-gates.md` | `T0.1`, `T0.2`, `T0.3`, `T0.4` **(4)** | Verificaciones en vivo (read-only); gates G1–G4 | — |
| 1 | `fase-1-cimientos.md` | `T1.1a`, `T1.2`, `T1.3`, `T1.3b`, `T1.4`, `T1.1b`, `T1.5` **(7)** | `Run_Context` + arreglos `Runs`/`Heartbeat` (destraba "Detener" + fix timezone `mark_stale`) | — (ningún gate) |
| 2 | `fase-2-instrumentacion.md` | `T2.1`, `T2.2`, `T2.3`, `T2.4`, `T2.5` **(5)** + harness `H1.a`/`H1.b`/`H1.c` | Instrumentar manual/chunked/cron/webhook; lock release antes de cada send; define **K1/K2/K3** | Fase 1; `T2.4` además G4 |
| 3 | `fase-3-chunked.md` | `T3.1.a`, `T3.1.b`, `T3.1.c`, `T3.2.a`, `T3.2.b`, `T3.3.a`, `T3.3.b`, `T3.3.c`, `T3.3.d`, `T3.3.e`, `T3.4` **(11)** | Presupuesto propio, offset, progreso, fail-loud JS | Fase 2 (`T2.3`, `T2.4`) |
| 4 | `fase-4-botones-tombstones.md` | `T4.1`, `T4.2`, `T4.3a`, `T4.3b`, `T4.4`, `T4.5` **(6)** + harness `H1.d`/`H2` | Dos botones, cursor visible, política de tombstones | Fase 2 (`T2.3`); `T4.3a`/`T4.3b`/`T4.4` además G2 |
| 5 | `fase-5-imagenes.md` | `T5.1a`, `T5.1b`, `T5.2a`, `T5.2b`, `T5.3a`, `T5.3b` **(6)** | Extensión por mime, reporte de fallos, allowlist configurable | Fase 3 (`T3.4`); `T5.3a`/`T5.3b` además G1 |
| 6 | `fase-6-ui-logs-monitor.md` | `T6.1.a`, `T6.1.b`, `T6.2.a`, `T6.2.b`, `T6.3`, `T6.4.a`, `T6.4.b`, `T6.5` **(8)** | Logs (borrar todo, aviso, ruta), Monitor (origen/estado), partición de webhooks, D6 | Fase 1 (`T1.2`); `T6.5` además G3 |
| 7 | `fase-7-regresion-release.md` | `T7.1.a`, `T7.1.b`, `T7.2`, `T7.3`, `T7.4.a`, `T7.4.b`, `T7.4.c` **(7)** | Regresión R1–R15 + release 2.5.0 | Fases 1–6 |

**Total: 4 + 7 + 5 + 11 + 6 + 6 + 8 + 7 = 54 micro-tareas.**

> **Los IDs del skeleton viejo (`T1.1`, `T3.1`, `T4.3`, `T5.1`, `T6.1`, `T7.1`, etc.) ya no existen
> como tales.** Cuando este documento cita un ID, es siempre el ID real de la fase. El skeleton
> anterior (35 tasks) se descarta. **`T1.3b` es la tarea nueva** que cierra el bug de timezone de
> `mark_stale()` (D4); por eso el total pasó de 53 a **54**.

---

## 3. Orden de ejecución (top-to-bottom)

**El número de tarea NO es el orden de build.** El orden real, con los paralelizables marcados:

### Bloque 0 — Gates (read-only, los 4 en paralelo)

```
T0.1 (G1) ∥ T0.2 (G2) ∥ T0.3 (G3) ∥ T0.4 (G4)   →  phase0-results.md
```

### Bloque 1 — Cimientos (Fase 1; arranca ya, sin gates)

```
T1.1a  →  T1.2  →  T1.3  →  T1.3b  →  T1.4  →  T1.1b  →  T1.5
```

- `T1.1a` = modelo de `wp_alegra_runs` en el stub (**prerequisito de harness**, ver §4).
- `T1.2` y `T1.4` son independientes entre sí; `T1.3` y `T1.3b` necesitan `T1.1a`.
- `T1.1b` va **después** de `T1.2` (`Logger::set_run_context`), `T1.3` (`Runs::status`),
  `T1.3b` (`mark_stale` sano) y `T1.4` (`Heartbeat::forget`): los llama.
- `T1.5` cierra el lado del handler y verifica el guard de `Run_Context::finish`.

### Bloque 2 — Instrumentación (Fase 2)

```
[H1.b observer ∥ H1.c reset locks]  →  T2.1  →  T2.2  →  T2.3  →  T2.4*  →  T2.5
```

- `H1.b` y `H1.c` son prerequisitos de harness (§4); `H1.a` ya lo cubrió `T1.1a`.
- `T2.4` está `BLOQUEADO(Fase 0.4 / G4)`.
- **`T2.4*` no es un commit aislado**: va junto con `T3.1` + `T3.2.b` (ver "Contrato compartido" abajo).
- `T2.1` y `T2.2` cambian la firma de `Controller::import_from_alegra`; van juntos.
- **T2.3 es el dueño único de K2** (`$state`) y **T2.3 define K1/K3**; Fases 4/5 sólo consumen.

> **Contrato compartido `T2.4` + `T3.1` + `T3.2.b` (cierra B3 del audit adversarial).** `T2.4` **no**
> es un commit independiente. El esqueleto de `ajax_sync_page` que cierra (`fase-2:1102-1127`) usa
> `$tp`/`$processed`/`$done`/`$pct` (que **fija `T3.1.c`**), `Sync\Products::reset_image_stats()`
> (dueño **`T3.2.b`**) y `set_deadline`/`clear_deadline` (dueño **`T3.2.b`**); además sus tests
> `T28.216`/`T28.217` assertan `$resp->payload['done']`, que sólo existe tras `T3.1.c`. Con el orden
> Fase 2 → Fase 3, `T2.4` **no compila ni pasa** en el gate de Fase 2 (variables indefinidas +
> `Call to undefined method Products::reset_image_stats()`). Por eso **`T2.4` + `T3.1` + `T3.2.b`
> se implementan y commitean como UNA sola unidad (mismo commit)**: son la misma edición de
> `ajax_sync_page` y el gate "Fase 2 verde" se evalúa con esa unidad, no con `T2.4` aislado. Los
> phase files de Fase 2 y Fase 3 referencian este contrato.

### Bloque 3 — Chunked (Fase 3)

```
T3.1.a ∥ T3.2.a ∥ T3.2.b  →  T3.1.b  →  T3.1.c  →  T3.4  →  T3.3 (a→e)
```

- `T3.1.a` (opción), `T3.2.a` (`run_id`/stop/progreso) y `T3.2.b` (deadline de imágenes) son
  paralelizables **entre sí**; los tres deben existir antes de `T3.1.b`.
- **`T3.2.b` DEBE ir ANTES de `T3.1.b`, nunca en paralelo** (cierra O2/M1 del audit adversarial):
  `T3.1.b` **llama** `Sync\Products::set_deadline()`/`reset_image_stats()`/`clear_deadline()`
  (`fase-3:121-122,147`), que define **`T3.2.b`** (`fase-3:349-357`); **`T3.2.a` no los toca**
  (corrige la atribución vieja a `T3.2.a`). El diagrama del phase file de Fase 3 ya encadena
  `T3.2.b ─► T3.1.b`; la frase "en paralelo" era la que mentía.
- `T3.3` (JS) va **al final**: consume el payload de `T3.1`/`T3.4`.

### Bloque 4 — Botones y tombstones (Fase 4)

```
T4.1 ∥ T4.3a ∥ T4.3b  →  T4.2  →  T4.4  →  T4.5
```

- `T4.2` depende de `T2.3` (dueño del estado) + `T4.1`; `T4.4` depende de `T4.2` + `T4.3a`;
  `T4.5` depende de `T4.1` + `T4.2`.
- `T4.3a`, `T4.3b`, `T4.4` están `BLOQUEADO(Fase 0.2 / G2)` para el cierre de la rama; la
  implementación base es idéntica en ambas ramas.

### Bloque 5 — Imágenes (Fase 5)

```
T5.1a ∥ T5.1b        T5.2a → T5.2b        T5.3a → T5.3b
```

- `T5.2b` depende de `T3.4` (payload `images`) y `T2.2` (ruta manual).
- `T5.3a`/`T5.3b` están `BLOQUEADO(Fase 0.1 / G1)` para el cierre de la rama.

### Bloque 6 — UI de logs y monitor (Fase 6)

```
T6.2.a ─┬─► T6.1.a → T6.1.b
        ├─► T6.2.b
        └─► T6.3        T6.4.a ∥ T6.4.b        T6.5
```

- **`T6.2.a` va PRIMERO**: crea `Logger::get_log_dir()`/`is_writable()`, que los tests de `T6.1.a`
  (`T28.61`/`T28.62`) invocan aunque `T6.1.a` declare "Dependencias: ninguna dura". Orden real:
  `T6.2.a → T6.1.a`.
- `T6.3` depende **sólo** de `T6.2.a` (`get_log_dir`); `T6.2.b` también depende de `T6.2.a`, pero `T6.2.b` y `T6.3` son **paralelos** entre sí (no hay `T6.2.b → T6.3`). `T6.5` está `BLOQUEADO(Fase 0.3 / G3)`.
- `T6.4.b` incluye la **partición de webhooks** (D10): historial no-webhook cap 15 + sección
  `recent_webhooks` cap 5, para que los webhooks no desplacen a cron/manual.

### Bloque 7 — Regresión y release (Fase 7)

```
T7.1.a → T7.1.b → T7.2        T7.3        T7.4.a → T7.4.b → T7.4.c
```

- `T7.1.a` es **aditivo** sobre `T1.1a` (sólo `get_row()` + `_n()` si falta; ver §4).
- `T7.1.b` corre el harness y registra el delta real (objetivo ≥ 1377).
- `T7.2` es la prueba manual en la tienda (los 4 problemas + smoking gun).

### Grupos paralelizables (resumen)

| Grupo | Tareas |
|---|---|
| Gates | `T0.1` ∥ `T0.2` ∥ `T0.3` ∥ `T0.4` |
| Cimientos | `T1.2` ∥ `T1.4` |
| Harness extra | `H1.b` ∥ `H1.c` ∥ `H1.d` ∥ `H2` ∥ `_n()` |
| Chunked | `T3.1.a` ∥ `T3.2.a` ∥ `T3.2.b` (los tres antes de `T3.1.b`) |
| Botones | `T4.1` ∥ `T4.3a` ∥ `T4.3b` |
| Imágenes | `T5.1a` ∥ `T5.1b` |
| UI | `T6.1.*` ∥ `T6.2.*` ∥ `T6.4.*` |

---

## 4. Prerequisitos de harness — transversales (canónico)

Los agentes de fase descubrieron que **el harness no puede verificar grandes partes del plan**.
Estos cambios bloquean tareas de producto. **Canonización (cierra B1):** el modelo de
`wp_alegra_runs` lo define **entero `T1.1a`** (Fase 1, la fuente única); **`T7.1.a` es aditivo**
(sólo agrega `get_row()` y, si falta, `_n()`). Se eliminó el segundo store `$GLOBALS['alegra_runs']`.

> **Nomenclatura duplicada (histórico).** El mismo cambio de harness apareció con 5 nombres:
> `T1.1a` (Fase 1), `H1.a`/`H1.b`/`H1.c` (Fase 2), `H1`/`H2` (Fase 4), `T7.1.a` (Fase 7).
> **Canónico:** el modelo es de **`T1.1a`**; el resto son alias o tareas aditivas.

| ID canónico | Alias en fases | Archivo:línea | Qué modela / por qué | Bloquea |
|---|---|---|---|---|
| **`T1.1a`** | Fase 2 `H1.a`; Fase 7 `T7.1.a` (alias del modelo) | `scripts/lib/wp-stubs.php` → `Alegra_Mock_Wpdb::insert()` `:1725-1731`, `update()` `:1733-1736`, `get_var()` antes de `:1625`, `get_results()` antes de `:1670`, `query()` `:1673` | Modela **`wp_alegra_runs`** en `$GLOBALS['alegra_db']['wp_alegra_runs']`: `insert` con `id` autogenerado, `update` por `id`/`where` (respeta `status='running'`), `get_var` = `SELECT status … WHERE id=N`, `get_results` con filtros `status`/`run_type`/`started_at`/`LIMIT`, `query` = `UPDATE … status='stale' … started_at < cutoff` (respeta el cutoff). Sin esto, `Runs::status()`, `Runs::finish()`, `mark_abandoned()` y `currently_running()` son **inobservables**. | `T1.1b`, `T1.3`, `T1.3b`, `T1.5`, `T2.1`–`T2.5`, `T3.2.a`, `T4.x`, `T6.4.*`, `T7.1.b`, `T7.3` |
| **`T1.3b` (seam de tiempo)** | — | `scripts/lib/wp-stubs.php` `current_time()` `:395-398`; reset en `test-framework.php:174` | `$GLOBALS['alegra_test_gmt_offset']` (default `0`, sin cambiar el comportamiento actual) para reproducir un sitio no-UTC y hacer real el prove-it-catches del fix de timezone de `mark_stale()`. | `T1.3b` (su test `T28.18`) |
| **`H1.b`** | Fase 2 `H1.b`; Fase 0 `T0.4` (opción a) | `scripts/lib/wp-stubs.php:542-553`; reset en `test-framework.php:144` | Observer `$GLOBALS['alegra_test_json_observer']` invocado **justo antes** de lanzar `Alegra_Test_JSON_Response`, emulando el instante exacto del `wp_die()`→`die()`. **El stub lanza una excepción** (`Alegra_Test_JSON_Response extends \Exception`, `:496`) y en PHP **las excepciones SÍ corren `finally`** ⇒ sin observer, el test de "lock libre" **no** es prove-it-catch. | `T2.2`, `T2.4` (prove-it-catch del release antes del send) |
| **`H1.c`** | Fase 2 `H1.c` | `includes/Sync/Controller.php` junto a `acquire_sync_lock_public` `:511-525`; reset en `test-framework.php:144-208` | `Controller::reset_locks_for_testing()` limpia `$held_sync_locks` (hoy `private static`, nunca reseteado) → `alegra_test_reset()` lo llama. Necesario para simular "lock ocupado" de forma fiable. **Ojo:** `acquire_sync_lock_public` es re-entrante; para simular **otro proceso** hay que crear el option crudo `alegra_lock_alegra_sync_running_products`. | `T2.2`, `T2.4` |
| **`H1.d`** | Fase 4 `H1` | `scripts/lib/wp-stubs.php:426`; reset en `test-framework.php:173` | `is_admin()` configurable vía `$GLOBALS['alegra_test_is_admin']` (hoy devuelve `false` fijo). Sin esto, el test de `bulk_wc` de `T4.3b` es imposible. | `T4.3b` |
| **`H2`** | Fase 4 `H2` | `scripts/lib/wp-stubs.php` → `get_var()` antes de `:1625` | `get_var()` entiende **`wp_alegra_tombstones`**: matchea `alegra_type='…'` + `alegra_id='…'` con `resurrected_at IS NULL` y devuelve `reason` (o `null`). Sin esto, `exists_with_reason()` siempre da `null` en el harness. | `T4.3a`, `T4.4` |
| **`T6.1.a` (`_n()`)** | Fase 7 `T7.1.a` ("si no lo hizo T6.1.a") | `scripts/lib/wp-stubs.php` tras `:325` | Stub `_n($single, $plural, $number, $domain='')`. `_n()` ya se usa en producción (`Admin_Dashboard.php:4363,4431`) pero **no está stubeada** → el handler de `T6.1.a` fatalea con *Call to undefined function _n()*. **Dueño: `T6.1.a`** (Fase 6 corre antes); `T7.1.a` sólo cubre el caso "no está". | `T6.1.a`, `T6.1.b`, `T7.4.a` |
| **`T7.1.a` (aditivo)** | Fase 7 `T7.1.a` | `scripts/lib/wp-stubs.php:1642` | **Sólo** agrega `get_row()` delegando en el `get_results()` de `T1.1a` (para `alegra_runs`). **PROHIBIDO** redefinir `insert`/`update`/`get_var`/`get_results`/`query`/`alegra_test_reset` ni crear un segundo store. | `T7.1.b`, `T7.3` |

### Alcance de cada seam (por qué importa)

- **`T1.1a`** es el blocker más grande: sin él, **ninguna aserción de ciclo de vida del run** es
  escribible (ni R1, ni R9, ni R11, ni el Monitor con filas). Es el prerequisito de la Fase 1
  entera y de `T7.3`.
- **`H1.b`** convierte la regla de oro del lock (`release` **antes** del send) en algo testeable.
  Sin observer, el test conductual pasa **con el bug** (C1/C2 de Fase 2).
- **`H1.d` + `H2`** hacen testeable la política de tombstones (Fase 4).
- **`T1.3b`** es el único seam que agrega un **offset de tiempo** al harness; sin él, el fix de
  `mark_stale` no tiene prove-it-catches real.

> **Dónde viven.** Casi todos viven en `scripts/` (**excluido del ZIP de release** vía
> `.distignore`). **Excepción: `H1.c`** agrega `reset_locks_for_testing()` a
> `includes/Sync/Controller.php`, que **SÍ shipea** (código de producto). Es un método
> sólo-para-testing dentro de producción: aceptable, pero **no** "vive en `scripts/`". Ojo al
> revisar el ZIP.

### Conflictos entre fases (estado tras los fixes)

1. **Clave de storage del modelo de runs — CERRADO (B1).** Canónico:
   `$GLOBALS['alegra_db']['wp_alegra_runs']` (T1.1a). `T7.1.a` ya no usa `$GLOBALS['alegra_runs']`.
2. **`mark_resurrected` + `update()` de tombstones — DECIDIDO (opción b).** `T4.3a` siembra
   `resurrected_at` directo y deja el **write** real de `mark_resurrected()` para un chequeo
   manual (`wp eval`). No se extiende `update()`.
3. **`_n()` ownership — CERRADO.** Dueño `T6.1.a`; `T7.1.a` sólo cubre "no está".
4. **`tombstone_policy` no se resetea en `Run_Context::finish` — NOTA / RESUELTO (ver §8.5).** `T4.4` Parte 1
   dice "agregar el reset en `Run_Context::finish` es parte de T1.1" (ID viejo), pero `T1.1b` no
   resetea la política en `teardown()`. En la práctica `ajax_sync_page` la setea por página desde
   `$state` y manual/cron usan el default `'respect'`, así que **no hay fuga entre requests**; se
   deja como nota para que `T4.4` no asuma un reset que no existe.

---

## 5. Gates G1–G4

Cada gate es **read-only** y su salida va a `docs/sdd/logs-monitor-import/phase0-results.md`.

| Gate | Task | Check exacto | Ramas / decisión | Bloquea (hasta resolver) |
|---|---|---|---|---|
| **G1** | `T0.1` | Capturar `images[].url` real (≥3 ítems: meta `_alegra_image_url` o `GET /items`) + correr `Products::is_allowed_image_url($url)`; registrar host y esquema | **A** (host `*.alegra.com`): no se toca la allowlist; falsa alarma · **B** (host fuera): el comerciante carga el host por la UI (`allowed_image_hosts_extra`), **nunca `*`**, https obligatorio · **C** (http): escalar, una allowlist no habilita http · **D** (sin URL): INCONCLUSO; se implementa la opción igual | `T5.3a`, `T5.3b` |
| **G2** | `T0.2` | `SELECT reason, COUNT(*) FROM wp_alegra_tombstones WHERE alegra_type='item' GROUP BY reason` + preguntar cómo borró + reproducir el shape real de `$_REQUEST` (`post[]` array vs `post` escalar vs `delete_all`/`delete_all2`; ojo: trash no dispara `before_delete_post`) | Define el **default del checkbox** de "desde cero": **`ignore_all`** (tildado, recrear todo — recomendado) o **`ignore_bulk`** (destildado). El botón normal siempre `respect` | `T4.3a`, `T4.3b`, `T4.4` |
| **G3** | `T0.3` | `wp option get alegra_connector_sync_products` + estado de `config-gates` (`grep sync_products docs/sdd/config-gates/…`) + confirmar UI/runtime/activación | **A** (recomendada): queda `false`, sin migración · **B**: `true` con siembra **solo si la opción está ausente** + nota de release | `T6.5` |
| **G4** | `T0.4` | `php -r 'try{echo "try"; exit;}finally{echo "finally";}'` (no imprime finally) + `wp_send_json_*` → `wp_die()` → `die()` en el core. **C1:** el leak afecta **4** sends, no 3: los `error` de `:2116,2148,2184` **y** el `success` de `:2216-2221` (ambos dentro del `try`). **C2:** el stub lanza excepción ⇒ `finally` SÍ corre ⇒ el test viejo no caza el leak | **CONFIRMADO** (esperado): cierre de run + release **antes de cada** `wp_send_json_*`; `finally` solo para excepciones. Estrategia de test: **(a) observer H1.b** (recomendada) o **(b) source-scan** de offsets | `T2.4` (y el diseño de su test) |

> **G4 no bloquea por la plataforma, bloquea por el harness.** El comportamiento de WP es el
> estándar; lo que falta es la costura H1.b para probarlo. Si no se implementa H1.b, el
> prove-it-catch de `T2.4` **debe** ser el source-scan (opción b), **no** el test conductual actual.

---

## 6. Matriz de trazabilidad REQ → micro-tarea

### Requerimientos funcionales (27)

| REQ | Micro-tareas | Nota |
|---|---|---|
| REQ-MON-01 | `T1.1a`, `T1.1b`, `T1.3`, `T2.1`, `T2.2`, `T2.3`, `T2.4`, `T2.5` | + verificabilidad `T7.1.a` |
| REQ-MON-02 | `T1.1b`, `T1.3`, `T1.4`, `T2.1`, `T2.2`, `T2.3`, `T2.4`, `T3.2.a` | |
| REQ-MON-03 | `T6.4.a`, `T6.4.b` | datos de progreso vienen de `T2.4`/`T3.2.a`; barra pre-existente |
| REQ-MON-04 | `T2.5`, `T6.4.a` | label map K1/design §2.7 |
| REQ-MON-05 | `T1.1b`, `T1.4`, `T1.5`, `T2.1`, `T2.2`, `T2.4`, `T3.2.a` | bug raíz: `Heartbeat::clear` borraba el stop; K3 (stop→`cancelled`) |
| REQ-MON-06 | `T1.3`, `T6.4.a`, `T6.4.b` | `sync_method` + estado vacío/error honesto |
| REQ-LOG-01 | `T1.1b`, `T2.1`, `T2.2`, `T2.3`, `T2.4` | kill switch: **cobertura parcial** (código pre-existente + `run_id` de `T1.2`) |
| REQ-LOG-02 | `T2.1`, `T2.2`, `T3.2.a` | |
| REQ-LOG-03 | `T5.2a`, `T5.2b` | |
| REQ-LOG-04 | `T1.1b`, `T1.2` | |
| REQ-LOG-05 | `T6.3` | ruta absoluta siempre visible |
| REQ-LOG-06 | `T6.2.a`, `T6.2.b` | |
| REQ-LOG-07 | `T6.1.a`, `T6.1.b` | |
| REQ-IMP-01 | `T2.4`, `T3.3.a`, `T3.3.b`, `T3.3.c`, `T3.3.d`, `T3.3.e` | fail-loud JS |
| REQ-IMP-02 | `T2.2`, `T2.4`, `T3.3.a`, `T3.3.b`, `T3.3.c` | |
| REQ-IMP-03 | `T3.1.a`, `T3.1.b`, `T3.1.c` | presupuesto propio |
| REQ-IMP-04 | `T2.3`, `T3.1.b`, `T3.3.b`, `T3.3.d`, `T3.4` | |
| REQ-RES-01 | `T2.3`, `T3.2.a`, `T4.1` | |
| REQ-RES-02 | `T4.1`, `T4.2`, `T4.5` | estado del server: `T2.3` |
| REQ-RES-03 | `T0.2`, `T1.1b`, `T4.3a`, `T4.3b`, `T4.4` | `BLOQUEADO(G2)` |
| REQ-RES-04 | `T2.3`, `T2.4`, `T3.1.b`, `T3.1.c`, `T3.2.a`, `T3.4`, `T4.2` | |
| REQ-IMG-01 | `T3.2.b`, `T4.1` (parcial), `T5.2a`, `T5.2b` | escenario "campo `images` excluido": **cobertura parcial** (pre-existente) |
| REQ-IMG-02 | `T5.1a`, `T5.1b` | runtime NO verificable en harness (source-scan + test puro) |
| REQ-IMG-03 | `T3.4`, `T5.2a`, `T5.2b` | |
| REQ-IMG-04 | `T0.1`, `T5.3a`, `T5.3b` | `BLOQUEADO(G1)` |
| REQ-CON-01 | `T2.1`, `T6.5` | `else` de log en el cron |
| REQ-CON-02 | `T0.3`, `T6.5` | `BLOQUEADO(G3)` |

### No funcionales (7)

| NFR | Micro-tareas | Nota |
|---|---|---|
| NFR-01 | `T3.1.b`, `T3.1.c` | suma `imported+updated+skipped+errors` == total |
| NFR-02 | `T1.1b`, `T2.1`, `T2.5`, `T7.1.b`, `T7.3` | |
| NFR-03 | `T6.1.a`, `T6.1.b` | retención intacta |
| NFR-04 | `T2.4`, `T3.2.a`, `T7.3` (R7) | heartbeat/progreso por página, no por ítem |
| NFR-05 | `T3.1.a`, `T3.1.b`, `T3.1.c`, `T6.4.b`, `T7.3` | |
| NFR-06 | `T2.2`, `T2.4`, `T3.4`, `T6.1.a`, `T6.1.b`, `T6.5`, `T7.1.b`, `T7.4.a`, `T7.4.b`, `T7.4.c` | |
| NFR-07 | `T1.2`, `T5.3a`, `T6.2.a`, `T6.2.b` | |

### Coverage gaps (RE-VERIFICADO tras los fixes)

**Ningún REQ ni NFR queda sin al menos una micro-tarea.** Los 27 REQ y los 7 NFR tienen cobertura
mapeada. Lo que sí hay son **4 escenarios borde con cobertura parcial** (tienen tarea, pero ningún
test explícito los clava) **+ 1 gap menor** que Momus señaló:

| Escenario borde | REQ | Estado (post-fixes) |
|---|---|---|
| "Kill switch deja rastro" | REQ-LOG-01 | Sin tarea dedicada; se apoya en el log pre-existente de `Products.php:1291,1410` + la inyección de `run_id` de `T1.2`. **Riesgo:** no hay aserción que lo verifique. |
| "Campo `images` excluido no se toca" | REQ-IMG-01 | Sin tarea; comportamiento pre-existente (`Products.php:1922-1926`). No se rompe, pero tampoco se testea. |
| "El progreso se ve como barra y contador" | REQ-MON-03 | Sin tarea de render dedicada; `admin-monitor.php:108-136` ya renderiza y los datos llegan de `T2.4`/`T3.2.a`. |
| "Fallo de la tabla de runs se muestra" | REQ-MON-06 | `T6.4.b` cubre el error genérico del AJAX, no el fallo de lectura de la BD específicamente. |
| **"Kill switch activo → la sección Cron muestra el motivo"** | REQ-MON-06 | `T6.4.b` cubre `sync_method ∉ {cron,both}` (`cronDisabled`) y el error del AJAX, pero **no** el kill switch en la sección Cron (el payload ya manda `kill_switch_active`, `Admin_Dashboard.php:3748`, y el template lo usa para el banner, no para la sección cron). Gap cosmético de Momus. |

> **Decisión de plan:** los 4 primeros son aceptables como cobertura parcial (3 son comportamiento
> pre-existente y 1 es un borde de infraestructura). El 5º es cosmético. Si el equipo quiere
> cerrarlos, el lugar natural es `T7.3` (matriz R→test) o una rama más en `T6.4.b`, no una tarea
> nueva.

---

## 7. Correcciones consolidadas (audit trail)

Todas las citas que los agentes de fase corrigieron al re-verificar HEAD. **Original → realidad →
dónde impacta.** Se incluyen las 43 del índice previo **más** las nuevas de los 4 fix agents
(B1/B2/B3, D1–D13, C1/C3, K1/K2/K3).

### 7.1 Del skeleton viejo y la spec/design (43 del índice previo)

| # | Fuente | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|---|
| 1 | design §2.2/§3.2 | `Admin_Dashboard.php:2024` = guard de conexión en `ajax_sync_start` | `:2024` es `$this->api->reload_credentials();`. `ajax_sync_start` (`:2018-2068`) **no tiene** guard; el único real está en `ajax_import_from_api:4081` | `T2.3` **crea** el guard |
| 2 | design hallazgo #3 | "tercera allowlist en `Admin_Dashboard.php:113`" | `:113` **llama** a `Sync\Products::is_allowed_image_url()` (allowlist compartida). El tercer `.jpg` sí existe: `:132`. **No hay tercera allowlist** | `T5.1b` borra el método muerto y el tercer `.jpg` |
| 3 | design matriz fila 5 | `Receiver.php` envuelve `process_event` | `process_event()` se **define** en `Webhooks/Handlers.php:26`; `Receiver.php:171-173` lo **invoca** | `T2.5` envuelve la llamada en `Receiver::handle()` |
| 4 | design §2.4 | `set_transient('alegra_sync_progress', …)` en `:1318` | La condición es `:1318`; la llamada es `:1319` | `T3.2.a` usa `:1319` |
| 5 | design §2.3 `mark_abandoned` | `gmdate('Y-m-d H:i:s', time() - $grace_seconds)` | `started_at` se guarda con `current_time('mysql')` (hora **local**); `time()` es UTC → guarda corrida | `T1.3` usa `gmdate(..., current_time('timestamp') - $grace_seconds)` |
| 6 | design §2.1 pseudo | `Logger::set_run_context(...)` dentro de `namespace Alegra\Connector` | `Logger` es `Alegra\Connector\Logger\Logger`; sin `use` fatalea | `T1.1b` agrega `use Alegra\Connector\Logger\Logger;` |
| 7 | spec REQ-LOG-05 | "`admin-logs.php` **no muestra la ruta**" | `:98-100` **sí** la muestra, pero relativa, hardcodeada y **dentro** de `if (!empty($log_files))` | `T6.3` la mueve afuera y usa `$logger_dir` absoluta |
| 8 | spec REQ-MON-03 | "el Monitor **no renderiza `error_summary`** (0 coincidencias)" | Falso: `admin-monitor.php:175`/`:186` ya lo pintan, alimentado por `Admin_Dashboard.php:3730`. Las 0 coincidencias eran del literal `error_summary` | `T6.4.b` solo agrega vacío/error honestos |
| 9 | design §8 / `tasks.md` | `import_from_alegra(int $page=1, int $per_page=30, int $run_id=0, array $opts=[])` | La firma real (`Products.php:1248`) **no tiene `$opts`** | `T4.4` no agrega `$opts`; la política va por `Run_Context` |
| 10 | design §8 | `import_single_item_public(array $item, int $run_id=0)` como estado actual | Hoy es `(array $item): bool\|string` (`:2365`); el `$run_id` lo agrega `T3.2` | `T4.4` no necesita `$run_id` |
| 11 | `tasks.md` T1.1 | "`finish()` con guard `Runs::status`, ver T1.4" | `Runs::status` es **T1.3**; `T1.4` es `Heartbeat::forget` | Guard en `T1.3`, consumido en `T1.1b`, testeado en `T1.5` |
| 12 | `tasks.md` T1.5 | prove-it-catch en la aserción de stop | Tras `T1.4`, `clear()` ya no borra el stop ⇒ la aserción de stop **sigue pasando**; lo que falla es la del **heartbeat no limpiado** | `T1.5` engancha el catch en `Heartbeat::get($R) !== null` |
| 13 | `tasks.md` T1.1 | `import_single_item_public(array $item, int $run_id=0)` | Firma real sin `$run_id`; es de Fase 3 | Fase 1 **no** toca `Products` |
| 14 | `tasks.md` T3.1 | "la suma … iguala el total, sin duplicados" | El loop cuenta `skipped` por `variantParent`/variantes pero no por `offset`; contar el salteo duplicaría | `T3.1.b`: el salteo por `offset` es `continue` **sin** tocar contadores |
| 15 | `tasks.md` T3.4 | `assertArrayHasKey('message', $d)` | El harness lanza en `wp_send_json_*`; el payload capturado es `$resp->payload` | `T3.4` usa `$resp->payload` |
| 16 | `tasks.md` T3.3 | "el cliente deja de llevar `page`" | El request (`admin.js:232-234`) **ya no** envía `page`; solo lo usa como fallback de `percent` (`:240`) | `T3.3.d` quita el parámetro de `processPage`, no del request |
| 17 | `tasks.md` T4.3 | `Tombstone_Manager.php:107-115` (`exists`) | Real: `:107-122` (`return (bool) $found;` en `:121`) | `T4.3a` usa `:107-122` |
| 18 | `tasks.md` T4.3 / spec REQ-RES-03 | `on_post_delete` puede clasificar hoy | Escribe `'reason' => 'manual_wc'` **hardcodeado** (`:54`); `is_admin()` no configurable (`wp-stubs.php:426`) | `T4.3b` requiere **H1.d** |
| 19 | `tasks.md` T4.3 | `exists_with_reason()` testeable | `Mock_Wpdb::get_var()` no maneja `alegra_tombstones` (`:1592-1626`); `get_row()` (`:1642`) siempre `null` | `T4.3a` requiere **H2** |
| 20 | design §6.1 / `tasks.md` T5.2 | `download_and_attach_image:2166-2258` | Real: `:2166-2264` (finally en `:2259-2263`) | `T5.1a` usa el rango real |
| 21 | `tasks.md` T5.1 / design §6.1 | "el método muerto elimina la tercera allowlist" | Falso (ver #2): `:113` llama a la compartida; el tercer `.jpg` es `:132` | `T5.1b` borra el método muerto |
| 22 | `tasks.md` / spec | `is_allowed_image_url:2136-2158` / `:2136-2157` | Real: `:2136-2158` | `T5.3a` |
| 23 | spec | `allowed_image_hosts:2125-2131` | Real: `:2125-2130` (`:2131` en blanco) | `T5.3a` |
| 24 | design §3.5 / `tasks.md` T6.1 | `clear_old_logs(int $retention_days)` | Real: `clear_old_logs(?int $retention_days = null): int` (`Logger.php:198`); además **se loguea a sí mismo** en `:219` (recrea un archivo) | `T6.1.a`: `clear_all_logs()` **no** loguea |
| 25 | `tasks.md` T6.3 | "el fuente contiene `get_log_dir`" | El template usa `$logger_dir` (lo pasa el controller); `get_log_dir()` **no existe en HEAD: se crea en este cambio** (`T6.2.a`) y se invoca desde el controller | `T6.3`: source-scan del template por `$logger_dir`, del controller por `get_log_dir()` |
| 26 | design §3.6 | `(new Logger())->get_log_dir()` | El controller **ya tiene** `$this->logger` (`:933`); construir otro re-ejecuta `ensure_dir()` | `T6.3` usa `$this->logger->get_log_dir()` |
| 27 | `tasks.md` T6.2 | "`Logger.php:126-169` (`write()`)" | `write()` va de `:112` a `:169`; el catch es `:162-168`; los checks de writable `:129-137` | `T6.2.a`: inserción en `:162-168` y tras `:153` |
| 28 | design §4.4 | Enumera strings nuevas y **omite** labels de origen y cron deshabilitado | AC-35d exige que **toda** string de UI viva en `get_script_strings()` | `T6.4.a`/`T6.4.b` agregan `originCron/Manual/Chunked/Webhook`, `cronDisabled` |
| 29 | `tasks.md` T7.1 | "base + aserciones nuevas" | Baseline real: **1289 assertions**; el conteo estático (1459) infla por loops | `T7.1.b` registra el real (objetivo ≥ 1377) |
| 30 | design §12 / `tasks.md` T7.4 | "bump de versión" | `build-release.sh:46-63` compara **header == README == make-pot.php == argumento**; bump incompleto → exit 9 | `T7.4.a` toca 3 archivos |
| 31 | `tasks.md` T7.4 | `alegra-connector.php:6` (versión) | `ALEGRA_CONNECTOR_VERSION` **se deriva** del header (`:59`, vía `:39-56`) | `T7.4.a`: no hay constante hardcodeada que bumpear |
| 32 | user / `tasks.md` T7.4 | ZIP excluye `scripts/` | Confirmado por `.distignore`; pero `releases/` **sí** está trackeado en git | `T7.4.b` |
| 33 | user | `make_latest` como string `"true"` | `.github/workflows/release.yml` **no tiene** `make_latest` hoy (0 coincidencias); sin comillas YAML lo lee booleano | `T7.4.b` agrega `make_latest: "true"` |
| 34 | `tasks.md` T7.4 | build determinista | Confirmado (`FIXED_MTIME`, `sort`, `zip -X`, `:236-240`); regenera el `.pot` (`:140`) **después** del chequeo de árbol limpio | `T7.4.a` regenera y commitea el `.pot` antes del build |

### 7.2 Hallazgos de plataforma y harness (43→ del índice previo, #35–#43)

| # | Fuente | Claim | Realidad | Impacto |
|---|---|---|---|---|
| 35 | design §2.3/§11 + `tasks.md` T2.4 | El `finally` de `ajax_sync_page` no corre en los `wp_send_json_error` de `:2116,2148,2184` | Correcto pero **incompleto**: el `try` arranca en `:2103` y el `wp_send_json_success` (`:2216-2221`) **también** está dentro. El leak ocurre en **4** sends | `T2.4` libera el lock **antes de todo** send, incluido el éxito |
| 36 | `tasks.md` T2.4 | "el mock de `wp_send_json_error` corta el request como `die()`" | **Falso**: el stub **lanza** `Alegra_Test_JSON_Response` (`wp-stubs.php:496,542-553`) y las excepciones **sí** corren `finally` | `T2.4` necesita **H1.b** (observer) o source-scan |
| 37 | `tasks.md` T2.3/T2.4/T3.2 | Asertar "fila `running`/`failed`/`completed`" | El Mock Wpdb no modela runs: `update()` no-op, `get_var`/`get_results` devuelven `null`/`[]` | **T1.1a** |
| 38 | — | `Controller::$held_sync_locks` es `private static` y no se resetea | Contamina el test siguiente | **H1.c** |
| 39 | design §3.2 | Tabla de early-exits de `ajax_sync_start` | **No cubre** el `WP_Error` de `metadata=true` (`:2040-2042`, `:2048-2050`); con `begin()` antes, la fila quedaría `running` para siempre | `T2.3` agrega el cierre |
| 40 | design §4.1 | El estado pasa de `page` a `start`+`offset` | Los tests `T21.5` (`exec-test.php:3523-3541`) y `T-RB-5` (`:5257-5274`) siembran `page` sin `start`/`offset` | `T2.3`/`T3.1` leen con **default retrocompatible**; no se reescriben esos tests |
| 41 | `tasks.md` T3.2 | El loop `:1357-1360` de `import_from_alegra` | Trata cualquier retorno que no sea `true`/`'updated'` como error; el centinela `'stopped'` hay que manejarlo **también ahí** | `T3.2.a` cubre los **dos** loops |
| 42 | harness Fase 5 | `wp_check_filetype_and_ext` / `wp_get_image_mime` stubeadas | **No** lo están; `download_url`/`media_handle_sideload` siempre devuelven `WP_Error` (`wp-stubs.php:817-818`) | `T5.1a` se verifica por source-scan + test puro; `T5.2a` con fallos |
| 43 | harness Fase 6 | `_n()` disponible | **No** está stubeada (solo `__`, `_e`, … en `:324-329`) aunque producción la usa (`:4363,4431`) | **`T6.1.a`** (dueño); `T7.1.a` cubre "si falta" |

### 7.3 Nuevas de los 4 fix agents (revisión adversarial)

| # | Fix | Original (defectuoso) | Realidad / decisión canónica | Dónde impacta |
|---|---|---|---|---|
| 44 | **B1** | El modelo de `wp_alegra_runs` estaba especificado **dos veces** e incompatible: `T1.1a` en `$GLOBALS['alegra_db']['wp_alegra_runs']` vs `T7.1.a` en `$GLOBALS['alegra_runs']` (mapa por id), sin `query()` ni filtro `started_at` | **`T1.1a` es la única fuente** (`$GLOBALS['alegra_db']['wp_alegra_runs']`, con `query()` y filtro `started_at`). `T7.1.a` es **aditivo**: sólo `get_row()` + `_n()` si falta | `fase-1 T1.1a`, `fase-7 T7.1.a`, `design.md:276-279` |
| 45 | **B2** | `Run_Context::fail_early()` llamaba `Logger::info()` **en estático**; `info()` es de instancia → fatal PHP 8 → HTTP 500 en **todas** las salidas tempranas | `(new Logger())->info('Import abortado antes de crear el run', ['reason'=>$reason]+$context)` | `fase-1 T1.1b:283`, `design.md:148` |
| 46 | **B3** | `confirmClearAll` (clave nueva) vs `confirmClearLogs` (existente) | Se unifica en **`confirmClearLogs`** (se cambia sólo su **valor**). `confirmClearAll` **no** se agrega | `fase-3 T3.3.e`, `fase-6 T6.1.b` |
| 47 | **C1 (Momus)** | Dos "DESPUÉS" incompatibles de `$state`: `T2.3` (con `images`, sin `page`) vs `T4.2` (con `page`, sin `images`) | **T2.3 dueño único de K2**; `T4.2` se reduce a JS (`from_zero`/`recreate_manual`), no re-lista el array | `fase-2 K2`, `fase-4 T4.2` |
| 48 | **C2 (Momus)** | `tasks.md` decía que mover el release al `finally` hacía fallar el test (el mock "corta como `die()`") | **Falso**: el stub lanza excepción y el `finally` corre ⇒ el test viejo no caza el leak | `fase-2 C1`, `T2.4 §Verificación 8` (observer H1.b) |
| 49 | **C3 (Momus)** | Drift spec↔design en REQ-MON-02 (`clear` vs `forget`), REQ-LOG-05 (ruta), REQ-MON-03 (`error_summary`), REQ-RES-03 (default del checkbox), REQ-IMG-02 (`.jpg`) | La spec se **alineó al design** (MON-02 usa `forget`; LOG-05 "absoluta siempre"; MON-03 reconoce que ya renderiza; RES-03 default `ignore_all`; IMG-02 `.jpg` sólo como fallback) | `spec.md` REQ-MON-02/LOG-05/MON-03/RES-03/IMG-02 |
| 50 | **D1 (Oracle)** | La rama de cancel de `ajax_sync_page` era **inalcanzable**: `ajax_cancel_sync` borra el state y el handler chequeaba `!$state` primero; el run quedaba `running` → "Abandonado", no "Cancelado" | Cancel/stop se chequea **antes** de `!$state`; `ajax_cancel_sync` lee el `run_id` antes de borrar el state y **cierra la fila `cancelled`** | `fase-2 T2.4 (D1)`, `ajax_cancel_sync` |
| 51 | **D2 (Oracle)** | `$page` desaparecía del handler y customers/categorías re-fetcheaban la página 1 en loop; `$state['start']` no avanzaba | `$page` se **deriva** (`floor($start/$per_page)+1`); `start` avanza para products **AND** customers **AND** categories | `fase-2 T2.4 (D2)`, `fase-3 T3.1.c` |
| 52 | **D3 (Oracle)** | `get_transient` corría **antes** del lock y `ajax_sync_start` no rechazaba un segundo arranque → race de dos pestañas | El lock se toma **antes** de la lectura autoritativa; un segundo `start` con run vivo se rechaza; se limpia `alegra_sync_cancelled` | `fase-2 T2.3`, `T2.4 (D3)` |
| 53 | **D4 (Oracle)** | `mark_stale()` comparaba `started_at` (local) contra `gmdate(time())` (UTC) → en UTC− marca `stale` runs recién arrancados y `mark_abandoned` ni los ve | **Tarea nueva `T1.3b`**: `gmdate(..., current_time('timestamp') - STALE_AFTER_SECONDS)` + seam `$GLOBALS['alegra_test_gmt_offset']` + test `T28.18` | `fase-1 T1.3b`, `design §2.3`, `DR11` |
| 54 | **D5 (Oracle)** | El chequeo `$action === 'delete_all'` **nunca** matchea: "Empty Trash" es `name="delete_all"`/`delete_all2` y el select de abajo manda `action2` | `isset($_REQUEST['delete_all'])`/`delete_all2` como señal primaria + fallback `action2` | `fase-4 T4.3b`, `T28.44`/`T28.45` |
| 55 | **D6 (Oracle)** | El stop de manual/cron quedaba `completed` (sólo el chunked mapeaba `cancelled`) | **K3**: stop observado ⇒ `finish(cancelled)` en manual (`T2.2`), cron (`T2.1`) y chunked (`T2.4`) | `fase-2 K3`, `T2.1`, `T2.2` |
| 56 | **D7 (Oracle)** | `ajax_sync_start` no limpiaba `alegra_sync_cancelled` (TTL 120 s) → un import nuevo era cancelado al instante | `delete_transient('alegra_sync_cancelled')` al inicio de `ajax_sync_start` | `fase-2 T2.3` |
| 57 | **D8 (Oracle)** | `T3.4` (`image_stats()`) y `T5.2b` (`$state['images']`) escribían la misma clave del payload con fuentes distintas | **Fuente única:** `$state['images']` (acumulado del run). `image_stats()` es insumo del merge, no del payload | `fase-3 T3.4`, `fase-5 T5.2b` |
| 58 | **D9 (Oracle)** | El diseño proponía `(new Logger())->get_log_dir()`; además `get_log_dir()` **no existe en HEAD** (lo crea `T6.2.a`) | `get_log_dir()` (y `is_writable()`) llaman `ensure_dir()`; el controller usa `$this->logger` | `fase-6 T6.2.a` |
| 59 | **D10 (Oracle)** | Los webhooks inundaban el Historial (`Runs::recent(15)` sin filtro) | **Partición**: historial no-webhook cap **15** + `recent_webhooks` cap **5** (sección aparte) | `fase-6 T6.4.b`, `T28.615` |
| 60 | **D11 (Oracle)** | `fail_early` usaba `run_type` no canónicos (`chunked`/`manual`) que el label map no reconoce | **K1**: forma larga canónica (`manual_import`, `chunked_import`) | `fase-2 K1`, `T2.2`, `T2.3` |
| 61 | **D12 (Oracle)** | `Logger::write()` borraba la opción de fallo **incondicionalmente** por línea | `if (get_option(...) !== false) delete_option(...)` (sólo si existe) | `fase-6 T6.2.a` |
| 62 | **D13 (Oracle)** | Strings duplicadas entre fases (`confirmReimport`, `imagesFailed`) | Dueño único `T3.3.e`; las fases posteriores sólo consumen | `fase-3 T3.3.e`, `fase-4 T4.5`, `fase-5 T5.2b` |
| 63 | **K1** | No había enum canónico de `run_type` | Enum: `cron_sync_all` · `manual_import` · `chunked_import` · `webhook_item/client/invoice`. `Runs.php:24` es ilustrativo/stale | `fase-2 K1` |
| 64 | **K2** | Shape de `$state` inconsistente entre fases | Shape canónico definido **una vez** en `T2.3` (sin `page`; con `images`, `policy`, `from_zero`). Fases 4/5 consumen | `fase-2 K2` |
| 65 | **K3** | Estado terminal por camino no definido | Tabla terminal: manual/chunked/cron/webhook → `completed`/`failed`/`cancelled` según condición | `fase-2 K3` |

### 7.4 Nuevas del audit final (Oracle técnico + Momus adversarial)

Cuarta ronda de auditoría (`REVIEW-final-technical.md`, `REVIEW-final-adversarial.md`) corrida
**después** de los 4 fix agents. Son defectos **nuevos** (introducidos u omitidos por los fixes
anteriores).

| # | Fix | Defecto (original) | Decisión canónica | Dónde impacta |
|---|---|---|---|---|
| 66 | **B3 (Momus)** | **Dependencia circular Fase 2 ↔ Fase 3**: el esqueleto de `T2.4` usa `$tp`/`$processed`/`$done`/`$pct` (que fija `T3.1.c`) y `set_deadline`/`reset_image_stats` (dueño `T3.2.b`) antes de que existan | **`T2.4` + `T3.1` + `T3.2.b` = UNA sola unidad (mismo commit)**. §3 los agrupa y el gate "Fase 2 verde" se evalúa con la unidad, no con `T2.4` aislado | `tasks.md §3 Bloque 2`, phase files Fase 2/3 |
| 67 | **O2 (Momus)** | `T3.2.b` "puede ir en paralelo con `T3.1.b`" | **Falso**: `T3.1.b` **llama** los accessors que define `T3.2.b` ⇒ orden correcto **`T3.2.b → T3.1.b`**, nunca en paralelo | `tasks.md §3 Bloque 3` (`fase-3:739-740` lo corrige el fix agent de Fase 3) |
| 68 | **M1 (Momus)** | `set_deadline`/`image_stats` atribuidos a `T3.2.a` | Dueño real **`T3.2.b`** (`fase-3:349-357`); `T3.2.a` (`fase-3:253-343`) no los toca | `tasks.md §3 Bloque 3` |
| 69 | **M2 (Momus)** | `design.md §2.1` muestra `finish()` **sin** guard de `Runs::status` ni borrado del stop (contradice `T1.1b` y §2.5) | `finish()` de §2.1 refleja `T1.1b`/§2.5: guard de `Runs::status` + `teardown()` que borra `alegra_run_stop_{id}` | `design.md:135-141` |
| 70 | **D-H (Oracle, UNVERIFIED)** | `T1.1a` convierte `Alegra_Mock_Wpdb::update()` de no-op (`wp-stubs.php:1733-1736`) a merge global: el cambio de harness de mayor superficie | Guard explícito: tras `T1.1a`, `bash scripts/exec-test.sh` **≥ 1289 / 0 failed**. Si baja, **no** tocar el test en silencio: hallar el que dependía del no-op y decidir; fallback acotar el branch a `str_ends_with($table, 'alegra_runs')` | `fase-1 T1.1a` |
| 71 | **B-A / B-B → R14/R15** | La matriz R1–R13 **no** tenía guarda para la regresión del cursor-wipe (B-A) ni para el fix `$resp->payload` (B-B) | Se agregan **R14** (cursor borrado sólo para `products`) y **R15** (los tests leen `$resp->payload`, no `$resp->data`) a `T7.3` | `fase-7 T7.3` |

---

## 8. Registro de revisiones (audit trail)

Cuatro rondas de revisión adversarial independientes se corrieron sobre el plan: las **3 primeras**
antes de este reindexado (Momus, Oracle, consistencia) y una **cuarta final** post-fix-agents
(`REVIEW-final-technical.md`, `REVIEW-final-adversarial.md`). Todas terminaron en "con fixes"; los
fixes están aplicados a los phase files (y a `spec.md`/`design.md` donde correspondía).

| Review | Veredicto | Alcance | Qué encontró | Estado |
|---|---|---|---|---|
| `REVIEW-momus.md` | **APPROVE-WITH-FIXES** | Plan + código en HEAD; ~120 citas contrastadas | **3 blockers** (B1 modelo de runs duplicado; B2 `Logger::info()` estático fatal; B3 `confirmClearAll` vs `confirmClearLogs`), **3 conflicts** (C1 shape de `$state`; C2 prove-it-catch falso; C3 drift spec↔design) y **riesgos** (R1 `action2`, R3 `'skipped'` contado como error) | **Cerrados**: B1→§7.3#44, B2→#45, B3→#46, C1→#47, C2→#48, C3→#49 |
| `REVIEW-oracle.md` | **SOUND-WITH-FIXES** | Correctitud técnica del código planeado en WP/PHP | **13 defectos** (D1 cancel inalcanzable; D2 `$page` perdido; D3 race de pestañas; D4 timezone `mark_stale`; D5 `delete_all`; D6 stop→`completed`; D7 flag cancel viejo; D8 fuente de `images`; D9 `get_log_dir()` vacío; D10 flood de webhooks; D11 `run_type`; D12 `write()` reescribe opción; D13 strings duplicadas) + 8 race/edge cases | **Cerrados**: D1→#50, D2→#51, D3→#52, D4→#53, D5→#54, D6→#55, D7→#56, D8→#57, D9→#58, D10→#59, D11→#60, D12→#61, D13→#62 |
| `REVIEW-final-technical.md` (Oracle) | **SOUND-WITH-FIXES** | Auditoría read-only del plan **antes de ejecutar**; trace de los 7 pasos del síntoma + cita por cita contra HEAD | **2 blockers** (B-A cursor borrado para toda entidad; B-B tests leen `$resp->data`) + **defectos D-C..D-H** (skipped no contado; race de `ajax_sync_start`; stop a mitad de página; dependencia T6.1.a; mimes no stubeados; `update()` global del harness) | **Cerrados en el plan**: B-A/B-B ya aplicados por los fix agents; **D-H** ahora con guard explícito (`fase-1 T1.1a`, §7.4#70); D-C/D-E/D-F/D-G quedan como deuda declarada |
| `REVIEW-final-adversarial.md` (Momus) | **REJECT → con fixes** | Busca lo que las 3 rondas omitieron/introdujeron; ejecución mental de los tests contra el harness real | **B1** guard de conexión rompe `T21.4`; **B2** `fmt()` no existe en `admin-monitor.php`; **B3** ciclo Fase 2↔Fase 3; **H1** pausa por presupuesto reportada `cancelled`; **H2** `'skipped'` contado como `errors`; **H3** tests con `$resp->data`; **M1/M2**; **O1–O4**; **L1–L3** | **Cerrados**: B3→§7.4#66; O2→#67; M1→#68; M2→#69; B1/B2/H1/H2/H3/O1/O3/O4/L1–L3 son de los phase files de Fase 2/3/6 (fix agents de esas fases) |

**Smoking gun verificado en ambos reviews.** El lock que `die()` no libera (4 sends) y el
`Heartbeat::clear` que borraba el stop son la causa raíz del síntoma del comerciante; los fixes
(`T2.4`, `T1.4`, `T1.5`) están correctamente diagnosticados y cubiertos.

### Inconsistencias detectadas → RESUELTAS (histórico)

Los fix agents tocaron archivos distintos y quedó drift cross-file. Se auditó de nuevo contra HEAD y
**las 7 quedaron resueltas**; se conserva el registro para no re-abrirlas:

1. **`fase-6` apuntaba a `T7.1.a` como dueño del modelo de runs. RESUELTO:** `fase-6:46` y
   `fase-6:708` dicen **`T1.1a`** (fuente única) y `T7.1.a` sólo aditivo.
2. **`fase-2 §H1.a` re-especificaba el modelo de runs. RESUELTO:** `fase-2:149-173` es la sección
   "Alias … **NO** re-especificar"; remite a `T1.1a` como dueño único.
3. **Strings duplicadas `monitorError`/`statusAbandoned`/`imagesFailed`. RESUELTO:** `fase-3:594-604`
   ya no las redeclara y descarta el texto viejo; `fase-6:690-691` fija el texto canónico de
   `monitorError`. Dueño único por clave: `imagesFailed`→`T5.2b`, `monitorError`→`T6.4.b`,
   `statusAbandoned`→`T6.4.a`.
4. **`T7.3 R3` (test `T28.74`) no era prove-it-catch con H1.b. RESUELTO:** `fase-7:265-309` lo
   reescribió con el observer `$GLOBALS['alegra_test_json_observer']` (fotografía el lock crudo en
   el instante exacto del send).
5. **`T4.4` asumía un reset de `tombstone_policy` en `Run_Context::finish`. RESUELTO:** `fase-4:682-690`
   trae la aclaración I5: los statics son request-scoped, no hace falta reset, y la cita al ID viejo
   `T1.1` quedó cerrada.
6. **`design.md` conservaba 3 citas corregidas. RESUELTO:** las líneas se movieron y los 3 textos ya
   no son defectos (`design.md:612` usa `confirmClearLogs`; `:679` es el fallback defensivo
   `action === 'delete_all'`; `:477` explica por qué **no** se usa `(new Logger())->get_log_dir()`).
   Los residuos reales del design eran M2/M3/L4/L5 y están cerrados en este pase.
7. **IDs viejos (`T1.1`, etc.) usados como shorthand en contextos históricos. RESUELTO (histórico):**
   son citas del skeleton descartado dentro de tablas de corrección, no dependencias colgadas; no
   hay `Dependencias` apuntando a un ID viejo.

---

## 9. Checklist de aceptación (los 4 problemas + smoking gun)

| # | Problema reportado / síntoma | Micro-tareas | Resultado verificable (WP admin) |
|---|---|---|---|
| 1 | **"El monitor de procesos no funciona"** | `T1.1b`, `T1.3`, `T1.4`, `T1.5`, `T2.2`–`T2.5`, `T6.4.a` | Un import manual/chunked/webhook aparece en el Monitor con **origen** y **progreso**; **"Detener"** corta el run y lo deja `Cancelado` (bug raíz: `Heartbeat::clear` borraba el stop, `T1.4`; timezone de `mark_stale`, `T1.3b`) |
| 2 | **"No registra los logs" + "Limpiar logs no funciona"** | `T1.2`, `T2.1`–`T2.4`, `T6.1.a`, `T6.1.b`, `T6.2.a`, `T6.2.b`, `T6.3` | Cada import (y sus salidas tempranas) deja **inicio/progreso/fin** con `run_id`; **"Limpiar logs" borra TODO** y dice cuántos; el logger **avisa** si no puede escribir; la **ruta absoluta** es visible |
| 3 | **"El proceso se cerró sin decir nada" + 60 s vs 240 s + sin progreso** | `T2.4`, `T3.1.a`, `T3.1.b`, `T3.1.c`, `T3.2.a`, `T3.2.b`, `T3.3.a`–`T3.3.e`, `T3.4` | Ninguna rama cierra sin `showNotice`; el chunked **pausa por presupuesto propio** y reanuda sin perder ítems ni fatal 500; barra y contadores reales |
| 4 | **"Reanudar vs reimportar" + tombstones** | `T4.1`, `T4.2`, `T4.3a`, `T4.3b`, `T4.4`, `T4.5` | **Dos botones** inequívocos; el **cursor es visible**; "Reimportar todo desde cero" recrea lo borrado masivamente **sin** tocar los manuales (`respect`) y **nunca** resucita `alegra_deleted` |
| 5 | **Smoking gun: "borré todo → Traer → se cerró sin aviso → al minuto aparecieron algunos sin imágenes"** | `T3.3.a`–`T3.3.c`, `T3.4`, `T4.1`, `T5.1a`, `T5.2a`, `T5.2b` | **Nunca** cierre mudo; la pausa es visible con el cursor; los **fallos de imagen se reportan** con desglose; el catálogo se recrea completo con imágenes (o con el desglose de fallos) |
| 6 | **Sin regresión (distribuido)** | `T2.1`, `T2.5`, `T3.1.a`, `T6.4.a`, `T6.4.b`, `T7.3` | Cron y webhooks funcionan **igual** (y ahora dejan run+log); el chunked anda aunque el host ignore `set_time_limit`; el Monitor degrada bien |

### Mapa problema → verificación manual (`T7.2`)

- **Problema 1** → `T7.2` "Pasos Problema 1" (Monitor con origen/progreso, Detener → `Cancelado`).
- **Problema 2** → `T7.2` "Pasos Problema 2" (logs con `run_id`, ruta absoluta, limpiar todo + retención intacta).
- **Problema 3** → `T7.2` "Pasos Problema 3" (barra/contadores, pausa visible, sin cierre mudo, error de conexión).
- **Problema 4** → `T7.2` "Pasos Problema 4" (badge de cursor, reimport desde cero con confirm).
- **Smoking gun** → `T7.2` "Pasos Smoking gun" (borrar todo → Traer → nunca mudo → imágenes o desglose).

---

## 10. Decisiones abiertas (BLOCKED-ON-VERIFICATION) y default recomendado

| Gate | Task | Incógnita | Default recomendado (para no bloquear) | Micro-tarea afectada |
|---|---|---|---|---|
| **G1** | `T0.1` | Host real del CDN de imágenes | **Rama A** si resuelve a `*.alegra.com` (no tocar la allowlist); si no, **Rama B**: el comerciante carga el host observado por la UI, **nunca `*`**, https obligatorio | `T5.3a`, `T5.3b` |
| **G2** | `T0.2` | Política de tombstones en "desde cero" | Tercera opción del design: heurístico `bulk_wc` (con `delete_all`/`delete_all2` + `action2`) + checkbox; **default del checkbox = recrear todo** (`ignore_all`); el botón normal siempre `respect` | `T4.3a`, `T4.3b`, `T4.4` |
| **G3** | `T0.3` | Default de `sync_products` | **Rama A: queda `false`** (sin cambio de comportamiento); coordinar con `config-gates` | `T6.5` |
| **G4** | `T0.4` | `wp_send_json_*` no corre `finally` | **Confirmar** (comportamiento estándar de WP); el diseño ya cierra run+lock antes de cada send. El blocker real es el observer **H1.b** | `T2.4` |
| — | — | Retención de logs tras "borrar todo" | **Dejar la retención intacta**; "borrar todo" es puntual (NFR-03) | `T6.1.a`, `T6.1.b` |

> **Regla de cierre.** Las tres `BLOQUEADO(ON-VERIFICATION)` del spec (REQ-RES-03, REQ-IMG-04,
> REQ-CON-02) **no se cierran** hasta que `phase0-results.md` tenga la rama elegida. Las
> implementaciones base de `T4.x`, `T5.3x` y `T6.5` son **idénticas en ambas ramas**: lo único que
> cambia es el default / si el comerciante carga el host.

---

## 11. DoD global del cambio

- [ ] Gates G1–G4 con rama registrada en `phase0-results.md`.
- [ ] Prerequisitos de harness **`T1.1a`, `T1.3b`, `H1.b`, `H1.c`, `H1.d`, `H2`, `_n()` (`T6.1.a`),
      `T7.1.a` (aditivo)** aplicados (casi todos en `scripts/`, fuera del ZIP; `H1.c` toca
      `includes/Sync/Controller.php`, que sí shipea).
- [ ] Las **54** micro-tareas `[x]`, cada una con su prove-it-catches documentado (revertir → rojo →
      re-aplicar → verde).
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con el conteo real (objetivo ≥ **1377**;
      baseline 1289 + ≥ 88).
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`; `smoke-load.php` asserta que `Run_Context` carga
      por PSR-4.
- [ ] `T7.2` checklist manual firmado + capturas del Monitor, Logs y el modal.
- [ ] Matriz R1–R15 de `T7.3` con test concreto (R13 = base verde; R14 = cursor por tipo; R15 = payload vs data).
- [ ] Release **2.5.0**: `CHANGELOG.md`, versión en header/README/make-pot, `uninstall.php` con las
      4 opciones nuevas, `.pot` regenerado y commiteado, ZIP + `.sha256` commiteados, workflow con
      `make_latest: "true"`, `ASSET OK` + `isLatest: true`.
- [ ] `docs/RELEASE_2.5.0_VERIFICATION.md` con los 4 problemas + smoking gun + prove-it-catches.
