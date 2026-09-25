# REVIEW — Verificación final de consistencia (`logs-monitor-import`)

> **Tipo:** auditoría read-only del plan (no se modificó ningún artefacto del plan).
> **Fecha:** 2026-09-25
> **Método:** lectura íntegra de los 14 artefactos + greps dirigidos + contraste contra el código en HEAD.
> **Veredicto:** **ISSUES FOUND** — los fixes de titular aterrizaron, pero quedan 1 dependencia no declarada
> (bloqueante de orden de build), 1 colisión de namespace de riesgos, y varios residuos de los fixes
> D5/D11/B3/I3 más texto de audit-trail ya obsoleto.

---

## 0. Resumen ejecutivo

| Sev. | # | Hallazgo | Archivo:línea |
|---|---|---|---|
| **ALTA** | H1 | `Logger::get_log_dir()` **no existe en HEAD**; lo agrega T6.2.a, pero los tests de T6.1.a lo invocan y T6.1.a declara "Dependencias: ninguna dura". Orden de build `T6.1.a → T6.2.a` ⇒ fatal. Además C3/#25 afirman que ya existe. | `fase-6:34`, `fase-6:170,186,299`, `tasks.md:347,390`, `design.md:48` |
| **ALTA** | H2 | El namespace de riesgos **R** colisiona: `design.md §11` define R1–R11; `fase-7 T7.3` usa R1–R13 con significados distintos; `tasks.md:31` dice que el design tiene R1–R13. | `design.md:976-986`, `fase-7:244-258`, `tasks.md:31` |
| MEDIA | M1 | `tasks.md §8` lista 6–7 "Inconsistencias que QUEDAN" que **ya están resueltas** en los phase files (texto stale). | `tasks.md:416-447` |
| MEDIA | M2 | `fail_early('chunked', …)` — alias corto, viola K1/D11 (canónico `chunked_import`). | `design.md:399` |
| MEDIA | M3 | `import_from_alegra(..., array $opts = [])` — `$opts` fue eliminado por la corrección #9 / C1 de fase-4. | `design.md:890` |
| MEDIA | M4 | Fase 0 instruye a esperar `action=delete_all` para "Vaciar papelera", contradiciendo el fix D5. | `fase-0:177-178,197` |
| MEDIA | M5 | Nombre del seam del harness: `alegra_test_json_probe` (fase-0) vs `alegra_test_json_observer` (fase-2/7). | `fase-0:357` vs `fase-2:183`, `fase-7:290` |
| MEDIA | M6 | `confirmRecreateManual`: declarada por T3.3.e y la tabla dice que T4.1 la consume, pero T4.1 hardcodea **otro texto** y no lee `S.confirmRecreateManual`. | `fase-3:588`, `fase-4:166`, `fase-6:683` |
| BAJA | L1 | El DoD de Fase 2 equipara el prove-it-catch del observer con `T28.218`, pero ese ID es el source-scan D3 (ítem 7), no el observer (ítem 8). | `fase-2:1320` |
| BAJA | L2 | Referencia de línea rota al observer de Fase 2 (`fase-2:1238-1240`; real `:1203-1205`). | `fase-7:270` |
| BAJA | L3 | La descripción del test R11 usa la forma estática `Logger::info('x')` (el patrón fatal de B2). | `fase-7:256` |
| BAJA | L4 | La matriz de archivos del design omite `get_log_dir` en la edición de `Logger`. | `design.md:1008` |
| BAJA | L5 | La nota de plataforma del design cita sólo los 3 sends de error; el leak también afecta al success (4). | `design.md:32-37` |
| BAJA | L6 | IDs viejos (`T1.1`, etc.) como shorthand en contextos históricos. | `fase-1:25,32,37`; `tasks.md:68,222,333,335,438,446` |
| BAJA | L7 | Fase 2 usa headings `## T2.x`; el resto usa `### T…`. | `fase-2:229,399,587,807,1215` |

---

## 1. Greps de defectos que debían eliminarse

```
=== confirmClearAll ===
design.md:612, tasks.md:378/441, fase-3:607, fase-6:227  (sólo texto "NO se agrega")
REVIEW-momus.md (histórico)
```
**Resultado: OK (con nota).** `confirmClearAll` **no** existe como clave en `Admin_Dashboard.php`
(HEAD sólo tiene `confirmClearLogs` en `:705`). Todas las apariciones son notas que ordenan **no**
agregarla. B3 aterrizó.

```
=== Logger::(info|warning|error|debug|success)( ===
design.md:146 (comentario), tasks.md:377 (fix), fase-1:281 (comentario), fase-7:256 (descripción de test)
```
**Resultado: OK salvo L3.** No hay llamadas estáticas reales en bloques de código ejecutable.
`fase-1:283` usa `(new Logger())->info(...)`. La única forma estática residual está en la
**descripción** del test R11 (`fase-7:256`), que si se copia literal fatalea (ver L3).

```
=== action.*delete_all ===
design.md:679 (defensivo), fase-4:471 (defensivo), fase-0:178/197 (gate), tasks.md/reviews (audit)
```
**Resultado: OK salvo M4.** Los usos en `design.md:679` y `fase-4:471` son el fallback defensivo
explícito (`if ($action === 'delete_all')`, con comentario). La señal primaria
`isset($_REQUEST['delete_all'])` está en `fase-4:457` y `design.md:672`. El residuo está en Fase 0.

```
=== T1.1[^ab] ===
fase-1:25,32,37 ; tasks.md:68,222,333,335,438,446
```
**Resultado: OK salvo L6.** Son citas del skeleton viejo en tablas de corrección; no hay
`Dependencias` colgadas a `T1.1`.

```
=== T7.1.a | T1.1a ===
design.md:277-279, fase-1 (T1.1a), fase-2 H1.a (alias), fase-6:46/708, fase-7 T7.1.a
```
**Resultado: OK.** El dueño único del modelo `wp_alegra_runs` es **`T1.1a`** en todos los
artefactos; `T7.1.a` figura explícitamente como aditivo.

```
=== new Logger())->get_log_dir ===
design.md:477 (explicativo), tasks.md:348/390/442 (fix), fase-6:34 (C3)
```
**Resultado: OK salvo H1.** Ningún bloque de código usa `(new Logger())->get_log_dir()`;
T6.3 usa `$this->logger->get_log_dir()`. Pero el método **no existe en HEAD** (ver H1).

---

## 2. Unicidad de test-IDs

Extracción de todos los `T28\.\d+` de los phase files, por fase:

| Fase | IDs definidos |
|---|---|
| 1 | `T28.11`–`T28.19`, `T28.110`, `T28.111` |
| 2 | `T28.21`–`T28.29`, `T28.210`–`T28.220` |
| 3 | `T28.31`–`T28.39`, `T28.310`–`T28.312` |
| 4 | `T28.41`–`T28.47` |
| 5 | `T28.51`–`T28.58` |
| 6 | `T28.61`–`T28.69`, `T28.610`–`T28.615` |
| 7 | `T28.71`–`T28.79`, `T28.710`–`T28.717` |

**Resultado: CLEAN.** No hay colisiones entre fases. Las repeticiones de conteo
(`T28.11`×4, `T28.31`×3, `T28.51`×5) son **referencias** (DoD, prosa de "no colisiona con Fase 3/5"),
no definiciones duplicadas. `T28.2` es la plantilla de convención (`T28.2{n}`), no un ID.

> Nota (no defecto): el esquema `{n}` de 1–2 dígitos hace que `T28.11` (test 1) sea prefijo de
> `T28.110`/`T28.111` (tests 10/11). El regex greedy `T28\.\d+` los distingue bien, pero un grep
> humano puede confundirse. No requiere cambio.

---

## 3. Integridad de task-IDs

Headings reales por archivo (todos existen):

- Fase 0: `T0.1`–`T0.4` (4)
- Fase 1: `T1.1a`, `T1.1b`, `T1.2`, `T1.3`, `T1.3b`, `T1.4`, `T1.5` (7)
- Fase 2: `T2.1`–`T2.5` (5) — **headings en `##`, no `###`** (L7)
- Fase 3: `T3.1.a/b/c`, `T3.2.a/b`, `T3.3.a–e`, `T3.4` (11)
- Fase 4: `T4.1`, `T4.2`, `T4.3a`, `T4.3b`, `T4.4`, `T4.5` (6)
- Fase 5: `T5.1a/b`, `T5.2a/b`, `T5.3a/b` (6)
- Fase 6: `T6.1.a/b`, `T6.2.a/b`, `T6.3`, `T6.4.a/b`, `T6.5` (8)
- Fase 7: `T7.1.a/b`, `T7.2`, `T7.3`, `T7.4.a/b/c` (7)

**Total 4+7+5+11+6+6+8+7 = 54** ✔ (coincide con `tasks.md:66`).

- El mapa de fases de `tasks.md:57-64` coincide con los headings reales. ✔
- Todas las líneas `Dependencias` referencian IDs existentes (o los prerequisitos de harness
  `H1.b/H1.c/H1.d/H2/_n()`, documentados en `tasks.md §4`). ✔
- No hay referencias colgadas a tasks inexistentes. ✔

---

## 4. Propiedad de definiciones cross-file

| Definición | Dueño canónico | Estado |
|---|---|---|
| Modelo `wp_alegra_runs` del harness | **T1.1a** (fase-1) | ✔ `fase-2 H1.a` es alias explícito; `fase-7 T7.1.a` es aditivo |
| Shape canónico de `$state` (K2) | **T2.3** (fase-2) | ✔ `fase-2:80-115`; T4.2 reducido a JS (`fase-4:227-232`) |
| `$image_stats` + `reset_image_stats()` + `image_stats()` | **T3.2.b** (fase-3) | ✔ `fase-3:345-372`; T5.2.a sólo incrementa (`fase-5:281-285`) |
| Payload `images` → `$state['images']` | **T3.4** (fase-3) | ✔ `fase-3:685-694`; T5.2.b no re-lista (`fase-5:420-421`) |
| Strings i18n | Tabla de **fase-6** | ⚠ M6: `confirmRecreateManual` inconsistente |
| `confirmReimport` | **T3.3.e** (fase-3), consume T4.5 | ✔ `fase-3:586`, `fase-4:843` |

---

## 5. Matriz de trazabilidad

`spec.md` contiene 27 REQ + 7 NFR + 1 cita externa (`REQ-CFG-2`, de `config-gates`).
`tasks.md §6` mapea **los 27 REQ y los 7 NFR**, y **todos los task IDs mapeados existen**.

**Resultado: CLEAN.** `REQ-CFG-2` (`spec.md:900,927`) es una referencia cruzada al cambio
`config-gates`, no un requerimiento de este cambio → no es un gap.

---

## 6. Coherencia de los fixes de titular

| Fix | Dónde | Estado |
|---|---|---|
| `ajax_kill_run` / `Heartbeat::clear` no borra el stop | `design.md:298-308`, `fase-1 T1.4` (717-797), `fase-1 T1.5` (801-895) | ✔ coherente |
| `wp_send_json_*` → `die()` saltea `finally`, **incluido el success** | `fase-0 C1/T0.4` (33, 343-345), `fase-2 T2.2` (519-527), `fase-2 T2.4` (1118-1127), `tasks.md G4` (238) | ✔ coherente (design `:32-37` incompleto — L5) |
| `mark_stale()` timezone (T1.3b) | `fase-1 T1.3b` (626-713), `design.md:259-274`, `design.md:986` | ✔ coherente |
| Cancel antes de `!$state` (D1) | `fase-2 T2.4` (1016-1039, 856-876) | ✔ coherente |
| Lock antes de la lectura autoritativa (D3) | `fase-2 T2.4` (1041-1058) | ✔ coherente |

**Resultado: los 5 fixes de titular están presentes y coherentes.**

---

## 7. Detalle de los hallazgos

### H1 (ALTA) — `get_log_dir()` no existe; dependencia no declarada

- `grep -rn "get_log_dir" --include=*.php .` → **0 coincidencias** en todo el repo.
- `design.md:48` lo lista correctamente como **nuevo** (`get_log_dir()`/`is_writable()` (nuevos)),
  y `design.md:885` lo declara en §8.
- Pero `fase-6:34` (corrección C3) afirma "`get_log_dir()` vive en `Logger.php` y se invoca desde
  el controller", y `tasks.md:347` (#25) repite "`get_log_dir()` vive en `Logger`". `tasks.md:390`
  (#58/D9) dice que "(new Logger())->get_log_dir() devolvía ''" — imposible si el método no existe.
- `T6.1.a` (fase-6:170,186) llama `$logger->get_log_dir()` y declara **"Dependencias: ninguna dura"**
  (`fase-6:161`). `get_log_dir()` recién lo crea **T6.2.a** (`fase-6:277`), que corre **después**
  en el orden de build (`tasks.md:143`: `T6.1.a → T6.1.b   T6.2.a → T6.2.b → T6.3`).
- **Efecto:** implementar Fase 6 en el orden canónico produce *Call to undefined method* en
  `T28.61`/`T28.62`.
- **Fix exacto:** declarar `T6.1.a` con dependencia de `T6.2.a` (o mover `get_log_dir()` a T6.1.a),
  y corregir C3 (`fase-6:34`) + #25 (`tasks.md:347`) + #58 (`tasks.md:390`) para decir que el
  método **se crea en este cambio (T6.2.a)**.

### H2 (ALTA) — Colisión del namespace de riesgos R

`design.md:976-986` define R1–R11 con un significado; `fase-7:244-258` usa R1–R13 con otro:

| R | `design.md §11` | `fase-7 T7.3` |
|---|---|---|
| R1 | wp_send_json no corre finally | el cron deja de completar |
| R2 | heurístico `bulk_wc` | webhook no-200 |
| R3 | re-fetch API | lock tomado si el cierre va después del send |
| R4 | imágenes diferidas | re-fetch duplica |
| R5 | `clear_all_logs` borra evidencia | heurístico `bulk_wc` |
| R7 | allowlist SSRF | instrumentación agrega I/O |
| R10 | logger filtra run_id | allowlist SSRF |
| R11 | `mark_stale` timezone | run_id filtra |
| R12/R13 | **no existen** | sync_products / helpers compartidos |

`tasks.md:31` afirma que el design tiene "riesgos **R1–R13**" (falso: tiene R1–R11).
`tasks.md:385` (#53) mapea D4→R11 usando el número del **design**, mientras `fase-7` usa R11 para
el leak de `run_id`. Un worker que cruce "R11" entre documentos toma el riesgo equivocado.
- **Fix exacto:** unificar. Recomendado: `fase-7 T7.3` es la matriz autoritativa de R1–R13
  (tests concretos); renumerar `design.md §11` a R1–R13 o eliminar el número del design y referir
  por nombre; y corregir `tasks.md:31`.

### M1 (MEDIA) — `tasks.md §8` obsoleto

`tasks.md:416-447` ("Inconsistencias que QUEDAN") enumera drift que **ya no existe**:

1. §8.1 dice que `fase-6` apunta a `T7.1.a` como dueño del modelo. **Falso hoy:**
   `fase-6:46` y `fase-6:708` dicen `T1.1a` (fuente única) y `T7.1.a` aditivo.
2. §8.2 dice que `fase-2:148-208` re-especifica el modelo. **Falso hoy:** `fase-2:149-173` es la
   sección "Alias … NO re-especificar".
3. §8.3 dice que `T6.4.a`/`T6.4.b`/`T5.2.b` vuelven a listar `monitorError`/`statusAbandoned`/
   `imagesFailed` y que el texto de `monitorError` difiere. **Falso hoy:** `fase-3:594-604` no las
   redeclara y descarta el texto viejo; `fase-6:690-691` fija el texto canónico.
4. §8.4 dice que `T28.74` no es prove-it-catch. **Falso hoy:** `fase-7:265-309` lo reescribió con el
   observer H1.b.
5. §8.5 dice que `T4.4` asume un reset inexistente en `Run_Context::finish`. **Falso hoy:**
   `fase-4:682-690` trae la aclaración I5 que cierra el tema.
6. §8.6 dice que `design.md` conserva 3 citas corregidas (`:606`, `:648`, `:474`). **Obsoleto:**
   las líneas cambiaron y los 3 textos ya no son defectos (`design.md:612`, `:679`, `:477`).

- **Fix exacto:** borrar §8 o marcarlo como "RESUELTAS (histórico)". (Los residuos reales del
  design son M2/M3/L4/L5, que §8 no menciona.)

### M2 (MEDIA) — Alias corto `fail_early('chunked', …)`

`design.md:399`: `| Batch-state ausente | :2079 | fail_early('chunked','no_batch_state') |`.
K1 (`fase-2:74-76`) y D11 exigen la forma larga `chunked_import`.
- **Fix exacto:** `fail_early('chunked_import', 'no_batch_state')`.

### M3 (MEDIA) — `$opts` resucitado en el design

`design.md:890`: `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0, array $opts = [])`.
La corrección #9 (`tasks.md:331`) y C1 (`fase-4:29`) establecen que **no existe `$opts`**.
- **Fix exacto:** quitar `, array $opts = []` de `design.md:890`.

### M4 (MEDIA) — Fase 0 contradice D5

`fase-0:177-178`: "Vaciar papelera … Forma esperada: `action=delete_all`". `fase-0:197`:
"Forma observada `action=delete_all` → cubrir explícitamente". El fix D5 (`fase-4:36,430-448`,
`design.md:655-663`) establece que WP **no** manda `action=delete_all`: es un submit
`name="delete_all"`/`delete_all2` + `action2`. Fase 0 es el gate que fija el heurístico, así que
una instrucción equivocada sesga la observación.
- **Fix exacto:** en `fase-0:177-178,197` reemplazar por "`delete_all=Empty+Trash` (submit name,
  `action` en `-1`)" y agregar el caso `action=-1&action2=delete` como esperado (hoy `fase-0:200`
  lo trata como "distinta a las tres esperadas", contradiciendo `fase-4:515`).

### M5 (MEDIA) — Nombre del seam H1.b

`fase-0:357` recomienda `$GLOBALS['alegra_test_json_probe']`; `fase-2:183-204` define
`$GLOBALS['alegra_test_json_observer']`; `fase-7:290` usa `observer`. `tasks.md:189` documenta
`observer`. Si el worker implementa T0.4 opción (a) al pie de la letra, crea un global que H1.b no
lee.
- **Fix exacto:** unificar en `$GLOBALS['alegra_test_json_observer']` en `fase-0:357`.

### M6 (MEDIA) — String `confirmRecreateManual` duplicada y mal atribuida

- `fase-3:588` (dueño T3.3.e): `'confirmRecreateManual' => __('¿Recrear también los productos que borraste a mano?')`.
- `fase-4:166` (T4.1): hardcodea `esc_html_e('Recrear también los productos que borré a mano.')` y
  **no** lee `S.confirmRecreateManual`.
- `fase-6:683` (tabla canónica): dice que T4.1 es consumidor de la clave de T3.3.e.
  Además los textos difieren (`borraste` vs `borré`), justo el modo de falla que la tabla dice cerrar.
- **Fix exacto:** que T4.1 renderice `esc_html(S.confirmRecreateManual)` y que el texto canónico
  quede en T3.3.e; o, si el label va en PHP, sacar la fila de la tabla canónica y alinear el texto.

### L1 (BAJA) — DoD de Fase 2 mal etiquetado

`fase-2:1320`: "(test del observer, T2.4 §Verificación 8 = `T28.218`)". El ítem 8
(`fase-2:1203-1205`) es el prove-it-catch del observer y **no tiene** ID T28; `T28.218` es el ítem 7
(source-scan D3, `fase-2:1198`).
- **Fix exacto:** quitar "= `T28.218`" o asignar un ID propio al test del observer.

### L2 (BAJA) — Referencia de línea rota

`fase-7:270` cita `fase-2:1238-1240`; el observer está en `fase-2:1203-1205`. `:1238-1240` cae en
la sección de T2.5 (webhook).
- **Fix exacto:** `fase-2:1203-1205`.

### L3 (BAJA) — Forma estática en la descripción del test R11

`fase-7:256`: "tras `Run_Context::finish`, un `Logger::info('x')` posterior…". `info()` es de
instancia (`Logger.php:171`); B2 fue exactamente ese fatal.
- **Fix exacto:** describir `make_logger()->info('x')` (o `(new Logger())->info('x')`).

### L4 (BAJA) — `get_log_dir` omitido en la matriz del design

`design.md:1008` lista la edición de `Logger` como `set_run_context`, `clear_all_logs`,
`is_writable`, `render_write_failure_notice` — omite `get_log_dir` que sí está en `design.md:48`.
- **Fix exacto:** agregar `get_log_dir`.

### L5 (BAJA) — Nota de plataforma incompleta

`design.md:32-37` describe el leak sólo en los `wp_send_json_error` de `:2116,2148,2184`. Fase 0 C1
y `tasks.md:238` (G4) establecen que también afecta al `success` (`:2216-2221`) → **4** sends. La
regla normativa de `design.md:224` ("antes de cada `wp_send_json_*`") sí es correcta.
- **Fix exacto:** agregar el success a la nota de `design.md:34-35`.

### L6 (BAJA) — IDs viejos como shorthand

`fase-1:25,32,37`; `tasks.md:68,222,333,335,438,446` citan `T1.1`/`T3.1`/etc. Son contexto
histórico, pero contradicen "los IDs del skeleton viejo ya no existen" (`tasks.md:68`).
- **Fix exacto (opcional):** anotar `T1.1 (ID viejo)` en cada cita.

### L7 (BAJA) — Nivel de heading inconsistente en Fase 2

`fase-2` usa `## T2.x`; el resto usa `### T…`. El check de headings `### T...` devuelve 0 en fase-2.
- **Fix exacto:** promover a `###`.

---

## 8. Inventario final de artefactos

| Archivo | Líneas | Propósito |
|---|---|---|
| `proposal.md` | 400 | Problema, decisiones del comerciante, hallazgos, criterios de éxito |
| `spec.md` | 1090 | 27 REQ + 7 NFR en EARS/Gherkin |
| `design.md` | 1021 | D1–D6, firmas, matriz de archivos, riesgos R1–R11 |
| `tasks.md` | 506 | Índice maestro, orden de build, seams de harness, gates, matriz REQ→tarea, audit trail, reviews |
| `tasks/fase-0-gates.md` | 394 | Gates G1–G4 (`T0.1`–`T0.4`, read-only) |
| `tasks/fase-1-cimientos.md` | 912 | `Run_Context` + fixes `Runs`/`Heartbeat` (`T1.1a`–`T1.5`) |
| `tasks/fase-2-instrumentacion.md` | 1324 | Instrumentación de los 4 caminos + K1/K2/K3 + H1.b/H1.c (`T2.1`–`T2.5`) |
| `tasks/fase-3-chunked.md` | 740 | Chunked con presupuesto, offset, progreso, fail-loud (`T3.1`–`T3.4`, 11 micro) |
| `tasks/fase-4-botones-tombstones.md` | 957 | Reanudar vs reimportar + tombstones (`T4.1`–`T4.5`, 6 micro) |
| `tasks/fase-5-imagenes.md` | 762 | Imágenes: mime, fallos, allowlist (`T5.1`–`T5.3`, 6 micro) |
| `tasks/fase-6-ui-logs-monitor.md` | 708 | Logs, Monitor, D6 (`T6.1.a`–`T6.5`, 8 micro) |
| `tasks/fase-7-regresion-release.md` | 521 | Regresión R1–R13 + release 2.5.0 (`T7.1.a`–`T7.4.c`) |
| `REVIEW-momus.md` | 192 | Revisión adversarial (histórico, APPROVE-WITH-FIXES) |
| `REVIEW-oracle.md` | 156 | Revisión técnica (histórico, SOUND-WITH-FIXES) |

Total plan (sin reviews): 12 archivos, ~9.3k líneas. Con reviews: 14 archivos, ~9.7k líneas.

---

## 9. Veredicto

**ISSUES FOUND.** Los 5 fixes de titular aterrizaron y son coherentes entre `design` + `fase-1` +
`fase-2`; la matriz REQ/NFR está completa, no hay colisiones de test-ID ni task-ID colgadas, y los
blockers B1/B2/B3 están cerrados. **No es execution-ready sin corregir H1** (dependencia
`T6.1.a → T6.2.a` no declarada por `get_log_dir`, que no existe en HEAD) **y H2** (colisión de
numeración de riesgos R entre `design` y `fase-7`). M2–M6 son residuos de los fixes D5/D11/I3 que
conviene cerrar antes de repartir el trabajo; L1–L7 son deuda documental.
