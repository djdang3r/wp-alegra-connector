# Fase 7 — Regresión y release — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **7** — Regresión y release |
| Versión objetivo | **2.5.0** |
| Harness | `bash scripts/exec-test.sh` (**1289 assertions**, 0 failed — verificado corriendo el harness) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T7.1`–`T7.4` (expandidas a 7 micro-tareas) |
| Depende de | Fases 1–6 completas |
| DoD de la fase | harness completo verde; prueba manual de los 4 problemas + el síntoma smoking-gun; release 2.5.0 publicado y verificado |

> **Regla de oro heredada.** *Prove-it-catches* en cada test nuevo: se revierte el fix, se corre el
> harness y **ese** test debe fallar; se re-aplica y vuelve a verde.
>
> **IDs (convención canónica).** Los tests nuevos van en `scripts/exec-test.php`, sección
> `// === logs-monitor-import (2.5.0) ===`, con IDs **`T28.{fase}{n}`**: `{fase}` = dígito de la
> fase (1–7), `{n}` = secuencia 1-based dentro de la fase (1 o 2 dígitos; p. ej. `T28.71` = Fase 7
> test 1, `T28.710` = Fase 7 test 10). En esta fase: `T28.71`–`T28.718` (incluye `T28.718` de R14). Los `T6.x`/`T7.x` de estos
> documentos son IDs de **tarea**, no de test.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 7)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `tasks.md` T7.1: "`exec-test.sh` (base + aserciones nuevas)" | El baseline **real** es **1289 assertions** (`EXEC-TEST OK: 1289 assertions passed, 0 failed`). El conteo estático de `TestRunner::` da 1459 porque hay aserciones dentro de loops (se cuentan por iteración). | T7.1.b: registrar el número real, no el estático. Objetivo mínimo de este cambio: **+14 (Fase 6) +15 (Fase 7)** sobre 1289. |
| C2 | design §12 / `tasks.md` T7.4: el release se describe como "bump de versión" | `scripts/build-release.sh` **no** solo mira el header: su preflight compara **header == README == `make-pot.php` == argumento** (`build-release.sh:46-63`). Un bump incompleto **aborta con exit 9**. | T7.4.a: tocar **tres** archivos: `alegra-connector.php:6`, `README.md:9`, `scripts/make-pot.php:22`. |
| C3 | `tasks.md` T7.4: "`alegra-connector.php:6` (versión)" | La constante **`ALEGRA_CONNECTOR_VERSION` se deriva** del header (`alegra-connector.php:59`, vía `alegra_connector_plugin_version(__FILE__)` en `:39-56`). | T7.4.a: **no** existe una constante hardcodeada que bumpear; el header es la única fuente. |
| C4 | `tasks.md` T7.4 / user: "the ZIP excludes `scripts/`" | Confirmado por `.distignore`: excluye `scripts/`, `docs/`, `CHANGELOG.md`, `README.md`, `releases/`, `*.zip`, `*.sha256`, `.github/`, `.omo/`. | El ZIP de release **no** lleva los SDD docs ni el CHANGELOG. Pero `releases/` **sí** está trackeado en git (los ZIPs históricos están commiteados): `.distignore` aplica al **contenido del ZIP**, no a lo que git versiona. |
| C5 | user: "`make_latest` as the STRING `\"true\"`" | **`.github/workflows/release.yml` NO tiene `make_latest` hoy** (grep en todo el repo: 0 coincidencias). `softprops/action-gh-release@v2` lo acepta como input de tipo string (`'true'|'false'|'legacy'`); en YAML, `true` sin comillas es booleano. | T7.4.b: agregar `make_latest: "true"` (con comillas) al `with:` del paso de publish. |
| C6 | `build-release.sh` "deterministic ZIP" | Confirmado: `FIXED_MTIME=198001010000`, `find | LC_ALL=C sort`, `zip -X` (`build-release.sh:236-240`). Además, **regenera `languages/alegra-connector.pot`** (`:140`) **después** del chequeo de árbol limpio (`:87-94`). | T7.4.a: correr `php scripts/make-pot.php` y commitear el `.pot` **antes** del build, para que la regeneración sea no-op y el árbol quede limpio. |
| C7 | `tasks.md` T1.x asume que `Runs::status($id)` es observable | **`scripts/lib/wp-stubs.php` no modela `wp_alegra_runs`**: `get_results()` devuelve `[]` salvo el lookup de categorías (`:1655-1671`), `get_var()` devuelve `null` (`:1592`), `get_row()` devuelve `null` (`:1642`), `insert()` escribe en `$GLOBALS['alegra_db']` pero nadie lo lee (`:1725-1731`) y `update()` es no-op (`:1733-1736`). | **BLOCKER de infraestructura.** El modelo lo implementa **T1.1a** (fuente única, `$GLOBALS['alegra_db']['wp_alegra_runs']`); sin él no se puede testear R1, R9, R11 ni el Monitor con filas. **T7.1.a sólo agrega `get_row()`** sobre ese modelo. |
| C8 | `tasks.md` T6.1 / design §3.3: usa `_n()` | `_n()` **no está stubeada** en `scripts/lib/wp-stubs.php` (solo `__`, `_e`, `esc_html__`, etc. en `:324-329`), aunque el plugin la usa en `:4363,4431`. | **Dueño: T6.1.a** (Fase 6, corre antes). T7.1.a sólo cubre el caso "no está"; no duplicar. |

**Citas confirmadas exactas:** `scripts/exec-test.sh:26` (invoca `exec-test.php`), `:30` (`EXEC-TEST OK`);
`scripts/smoke-test.sh:70-77`; `scripts/build-release.sh:156-164` (`DIST_DIR=releases`),
`:227-232` (verifica `Logger.php` en el staging), `:245-246` (`.sha256`), `:280-302` (tag/publish opt-in);
`.github/workflows/release.yml:43-61` (verifica el artefacto commiteado), `:63-66` (gates smoke+exec),
`:68-77` (publish); `.distignore` (exclusiones); `alegra-connector.php:6` (header), `:59` (constante
derivada); `README.md:9` (`Version:`); `scripts/make-pot.php:22` (`$version`); `uninstall.php:48-130`
(opciones), `:102` (`products_import_cursor` ya presente), `:224-229` (borra el dir de logs);
`templates/admin-products.php:92` ("Traer desde Alegra"), `:102` ("Traer seleccionados");
`docs/RELEASE_2.4.2_VERIFICATION.md:40-63` (política de artefactos).

---

## Mapa de cobertura Fase 7 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| T7.1.a | Complemento de harness: `get_row()` + `_n()` (el modelo de runs es **T1.1a**) | `scripts/lib/wp-stubs.php` |
| T7.1.b | NFR-02, NFR-05, NFR-06 (verde global) | `scripts/exec-test.php`, `scripts/smoke-load.php` |
| T7.2 | Los 4 problemas reportados + smoking gun | (manual, WP admin) |
| T7.3 | R1–R15, NFR-02, NFR-06 | `scripts/exec-test.php` |
| T7.4.a | NFR-06 (documentar el cambio de semántica) | `CHANGELOG.md`, `alegra-connector.php`, `README.md`, `make-pot.php`, `uninstall.php`, `languages/*.pot` |
| T7.4.b | Release 2.5.0 (artefacto determinista + digest) | `releases/`, `.github/workflows/release.yml` |
| T7.4.c | Nota de release / doc de verificación | `docs/RELEASE_2.5.0_VERIFICATION.md` |

---

### T7.1.a — Harness aditivo: `get_row()` + stub `_n()` (sobre el modelo de T1.1a) · **BLOCKER**

**Objetivo**: completar el harness con `get_row()` para `wp_alegra_runs` y el stub `_n()`, **sin** redefinir el modelo de runs.

> **Fuente única (cierra B1 del review Momus).** El modelo de `wp_alegra_runs` en
> `scripts/lib/wp-stubs.php` lo define **entero T1.1a** (`$GLOBALS['alegra_db']['wp_alegra_runs']`,
> con `insert()`/`update()`/`get_var()`/`get_results()`/`query()` y el filtro `started_at <`).
> **T7.1.a NO lo redefine ni crea un segundo store**: si un worker sigue esta tarea, el modelo sigue
> siendo el de T1.1a. Esta tarea es **aditiva**: sólo agrega `get_row()` y `_n()` encima.

**Descripción técnica (hallazgos C7/C8, acotados a B1)**: T1.1a ya hace observable la tabla
(`Runs::start`/`status`/`finish`/`currently_running`/`recent`). Quedan dos huecos **independientes
del modelo**:
1. `get_row()` (`scripts/lib/wp-stubs.php:1642`) devuelve `null` siempre; si algún flujo lee una fila
   de runs con `get_row()`, queda ciego.
2. `_n()` **no está stubeada** (sólo `__`, `_e`, `esc_html__`, … en `:324-329`), aunque el plugin la
   usa (`Admin_Dashboard.php:4363,4431`). Sin el stub, el handler de T6.1.a fatalea.

**Desarrollo técnico** (`scripts/lib/wp-stubs.php`, clase `Alegra_Mock_Wpdb`):
1. **`get_row()` (`:1642`)** — para `alegra_runs`, delegar en el `get_results()` que ya implementó
   **T1.1a**:
   ```php
   public function get_row($query, $output = null, $y = null)
   {
       if (strpos((string) $query, 'alegra_runs') !== false) {
           return $this->get_results($query)[0] ?? null;
       }
       return null;
   }
   ```
2. **Stub `_n()`** (si no lo hizo T6.1.a) — insertar tras `:325`:
   ```php
   function _n($single, $plural, $number, $domain = '') { return ((int) $number === 1) ? $single : $plural; }
   ```
   **Ownership:** `T6.1.a` (Fase 6) corre antes y es el dueño natural; esta tarea sólo cubre el caso
   "no está". No duplicar.

> **PROHIBIDO en esta tarea:** redefinir `insert()`, `update()`, `get_var()`, `get_results()`,
> `query()` ni `alegra_test_reset()` para runs; usar `$GLOBALS['alegra_runs']` como store alternativo;
> ni cambiar el filtro `started_at <`. Todo eso es de **T1.1a**.

**Resultado esperado**: sobre el modelo de T1.1a, `Runs::start('cron_sync_all','cron')` devuelve un
id; `Runs::status($id)==='running'`; `Runs::finish($id,'completed','x')` deja `status==='completed'` y
`error_summary==='x'`; `Runs::recent(10)` lee la fila; `get_row("SELECT * FROM wp_alegra_runs …")`
devuelve la fila; `_n()` existe.

**Dependencias**: **T1.1a** (es su prerequisito, no su reemplazo). Prerrequisito de T7.1.b/T7.3.

**Trazabilidad**: NFR-02, NFR-05, NFR-06 (verificabilidad). Cierra B1 del review Momus.

**Verificación**:
- `T28.71` (infra):
  ```php
  alegra_test_reset();
  $id = \Alegra\Connector\Runs::start('cron_sync_all', 'cron');
  TestRunner::assertSame('running', \Alegra\Connector\Runs::status($id), 'la fila debe leerse running');
  \Alegra\Connector\Runs::finish($id, 'completed', 'ok');
  TestRunner::assertSame('completed', \Alegra\Connector\Runs::status($id), 'finish debe actualizar status');
  TestRunner::assertCount(1, \Alegra\Connector\Runs::recent(10), 'recent debe leer la fila');
  ```
- **Prove-it-catches**: quitar el branch de `get_var` para `alegra_runs` en **T1.1a** → `T28.71` falla.

**Riesgo**: que el parser por regex del stub (T1.1a) sea frágil ante variaciones de query → mantener
los `if` acotados y, si una query nueva no matchea, devolver `[]`/`null` (comportamiento actual) en
vez de romper. Guard: los tests existentes (1289) deben seguir verdes.

**Estimación**: S (1 h).

---

### T7.1.b — Correr el harness completo y registrar el delta

**Objetivo**: ejecutar la suite entera (base + nuevas) y registrar el número real de aserciones por fase, con el mapa de prove-it-catches.

**Descripción técnica**: la Fase 7 cierra la regresión. El harness tiene **1289** aserciones al inicio; las Fases 1–6 agregan las suyas. Este task **no** implementa lógica: ejecuta, cuenta y documenta. Cubre **NFR-02/05/06**.

**Desarrollo técnico**:
1. Correr:
```bash
bash scripts/exec-test.sh
bash scripts/smoke-test.sh
```
2. Registrar el conteo real (`EXEC-TEST OK: N assertions passed, 0 failed`). Objetivo mínimo de este cambio (tabla; el worker reemplaza "≥" por el real):
   | Fase | Tests | Aserciones nuevas (mín.) |
   |---|---|---|
   | Fase 1 (cimientos) | T1.1a, T1.1b, T1.2, T1.3, T1.3b, T1.4, T1.5 | ≥ 15 |
   | Fase 2 (lock release) | T2.1–T2.5 | ≥ 12 |
   | Fase 3 (chunked) | T3.1–T3.4 | ≥ 12 |
   | Fase 4 (resume/reimport) | T4.1–T4.5 | ≥ 10 |
   | Fase 5 (imágenes) | T5.1–T5.3 | ≥ 10 |
   | Fase 6 (logs/monitor) | T6.1–T6.5 | ≥ 14 |
   | Fase 7 (regresión) | T7.x | ≥ 15 |
   | **Total** | | **≥ 1289 + 88 = 1377** |
3. Si `smoke-load.php` tiene una aserción que este cambio invalida, corregirla y registrarlo. (`grep` de `smoke-load.php` por `admin-logs`/`admin-monitor`/`clear_all_logs`/`get_log_dir` → **0 coincidencias** hoy; el único riesgo conocido es la aserción de secciones de `config-gates`, ajena a este cambio.)
4. Guardar el mapa de prove-it-catches (test → fix revertido → falla observada) en `docs/RELEASE_2.5.0_VERIFICATION.md` (T7.4.c).

**Resultado esperado**: `EXEC-TEST OK` y `SMOKE OK`; conteo real registrado; ningún test sin prove-it-catches.

**Dependencias**: Fases 1–6; T7.1.a.

**Trazabilidad**: NFR-02, NFR-05, NFR-06.

**Verificación**: `bash scripts/exec-test.sh | tail -3` → `EXEC-TEST OK: <N> assertions passed, 0 failed`; `bash scripts/smoke-test.sh | tail -1` → `SMOKE OK`.

**Riesgo**: tests que pasan por casualidad (falsos verdes) → mitigado por prove-it-catches. Tests dentro de loops que inflan el conteo → documentar el conteo real, no el estático (C1).

**Estimación**: M (1,5 h).

---

### T7.2 — Prueba manual de los 4 problemas + smoking gun en la tienda

**Objetivo**: validar en el WP admin real que los 4 problemas reportados y el síntoma smoking-gun quedaron resueltos.

**Descripción técnica**: los criterios de éxito de la propuesta §7 son observables desde el admin. Este task es la validación humana (no reemplazable por el harness, porque depende de la UI/tiempos reales). Cubre **REQ-MON-01..06**, **REQ-LOG-01..07**, **REQ-IMP-01..04**, **REQ-RES-01..04**, **REQ-IMG-01..03**, **NFR-01/02/05/06**.

**Desarrollo técnico — pasos exactos (WP admin)**:

**Preparación**: instalar 2.5.0 en una **réplica** de la tienda; `Alegra Connector → Configuración`: conexión testeada, "Productos" e "Imágenes de productos" activados, método de sincronización = `cron` (para el cron) o `real-time` (para probar el banner). Tener un catálogo de **> 60 ítems** en Alegra (≥ 2 páginas de 30).

**Problema 1 — "El monitor de procesos no funciona"** (REQ-MON-01/02/04/05)
1. Abrir `Alegra Connector → Productos` en una pestaña y `Alegra Connector → Monitor` en otra.
2. En Productos, pulsar **"Traer desde Alegra"** → aplicar filtros (o "Traer todo").
3. **Resultado esperado**: en el Monitor aparece una fila `Chunked` con estado **Corriendo**, mensaje "Procesando productos... Página N/M" y barra `items_done / total_items` que avanza cada ~5 s. Al terminar, pasa al **Historial Reciente** como `Completado` con conteos.
4. Volver a disparar y pulsar **"Detener este proceso"** sobre la fila.
5. **Resultado esperado**: el próximo lote no importa ítems nuevos; la fila queda **Cancelado**; aparece la notificación de detenido. (Antes: el botón no hacía nada — bug de `Heartbeat::clear` borrando el stop.)

**Problema 2 — "No registra los logs" + "Limpiar logs no funciona"** (REQ-LOG-01/02/04/05/06/07)
1. Correr un import (manual o chunked) y abrir `Alegra Connector → Logs`.
2. **Resultado esperado**: hay entradas de **inicio**, **progreso por página** y **fin**; cada línea del import incluye `"run_id":R` y `"run_type":"..."` en su contexto.
3. Provocar una salida temprana: `Configuración` → desconectar (o dejar la conexión sin testear) → disparar "Traer desde Alegra".
4. **Resultado esperado**: la respuesta muestra un mensaje de causa y el log registra `conexión no testeada` (o equivalente) con `run_type`.
5. **Resultado esperado (ruta)**: la página de Logs muestra la **ruta absoluta** del directorio aunque no haya archivos.
6. Pulsar **"Limpiar logs"** → **confirmar**.
7. **Resultado esperado**: dice "N archivos de log eliminados", la tabla queda vacía, y la **retención sigue en 30** (`Configuración` → no cambió).
8. Volver a pulsar "Limpiar logs" → **cancelar**.
9. **Resultado esperado**: no se borra nada.

**Problema 3 — "El proceso se cerró sin decir nada" + 60 s vs 240 s + sin progreso** (REQ-IMP-01/02/03/04, NFR-01/05)
1. Con el catálogo grande, disparar "Traer desde Alegra" y **mirar el modal**.
2. **Resultado esperado**: la barra y los contadores (`importados | actualizados | omitidos | errores`) avanzan por página; en una pausa por presupuesto, el label dice "Reanudando…" y **sigue** sin esperar; **nunca** hay un cierre mudo ni un HTTP 500.
3. Cortar la red a mitad (DevTools → offline) y esperar.
4. **Resultado esperado**: tras agotar reintentos, se ve una **notificación de error de conexión** y el botón vuelve a habilitarse (no queda deshabilitado en silencio).

**Problema 4 — "Reanudar vs reimportar" + tombstones** (REQ-RES-01/02/03/04)
1. A mitad de un import (o tras una pausa), mirar `Productos`.
2. **Resultado esperado**: un badge "Pausado en el ítem N de M. 'Traer desde Alegra' continúa desde ahí." (solo si `cursor>0`); con cursor 0, no hay badge.
3. Pulsar **"Reimportar todo desde cero"** → **confirmar**.
4. **Resultado esperado**: el cursor se limpia, arranca en la página 1 y el import corre completo; el botón pide confirmación destructiva.

**Smoking gun — "borré todo → Traer → se cerró sin aviso → al minuto aparecieron algunos sin imágenes"** (REQ-IMP-01, REQ-RES-01/04, REQ-IMG-01/02/03, REQ-LOG-01/02)
1. En WooCommerce, borrar **todos** los productos (selección masiva → papelera → vaciar).
2. En `Productos`, pulsar **"Traer desde Alegra"** (no "desde cero", para probar el flujo normal) o **"Reimportar todo desde cero"** (para vencer tombstones).
3. **Resultado esperado**: el modal muestra progreso; **no** se cierra en silencio; al terminar hay una notificación de resultado.
4. Abrir `Monitor`: el run aparece con origen `Chunked` y sus conteos.
5. Abrir `Logs`: hay la traza completa (`run_id`).
6. **Resultado esperado (imágenes)**: los productos recreados tienen sus imágenes (o el resumen reporta "N imágenes no se pudieron importar" con desglose: host bloqueado / descarga / adjuntado / diferidas). **Nunca** "aparecieron algunos sin imágenes" en silencio.

**Resultado esperado**: checklist firmado + capturas del Monitor, Logs y el modal.

**Dependencias**: Fases 1–6; T7.1.b.

**Trazabilidad**: los 4 problemas + smoking gun (ver checklist final).

**Verificación**: cada paso de arriba es la verificación. Adjuntar capturas a `docs/RELEASE_2.5.0_VERIFICATION.md`.

**Riesgo**: la réplica difiere de producción (permisos, tamaño de catálogo) → correr con un catálogo ≥ 2 páginas y probar también la carpeta no escribible (chmod 0555 a `wp-content/uploads/alegra-logs`) para validar el aviso de T6.2.b.

**Estimación**: M (2 h).

---

### T7.3 — Regresión: matriz R1–R15 → test concreto

**Objetivo**: convertir cada riesgo de regresión del esqueleto en una aserción concreta y verificable.

**Descripción técnica**: el esqueleto lista R1–R13 (`tasks.md`, tabla "Riesgos de regresión y guardas"); los audits finales (`REVIEW-final-technical.md` / `REVIEW-final-adversarial.md`) agregan **R14** (regresión del cursor-wipe) y **R15** (fix `$resp->payload`). Esta tarea los aterriza en tests del harness. Cubre **NFR-02**, **NFR-06** y el "sin regresión" de la propuesta §3.3.

**Desarrollo técnico — matriz R → test** (todos en `scripts/exec-test.php`, sección `T28`):

> **Namespace (H2).** Esta es la **matriz autoritativa de riesgos de ejecución (R1–R15)**. El `design.md` usa el namespace `DR1–DR11` para los riesgos de diseño; no cruzar los números entre ambos documentos. R14/R15 provienen de la cuarta ronda de auditoría (post-fix-agents).

| R | Riesgo | Test concreto (aserción) | Tarea que lo implementa | Prove-it-catches |
|---|---|---|---|---|
| **R1** | El cron deja de completar su ciclo al migrar de `Runs::track` a `Run_Context::wrap` | `T28.72`: con `sync_products/customers/categories/orders` en `true`, `make_controller()->run_cron_sync()` deja una fila `cron_sync_all` `completed`; `Heartbeat::get($id)===null`; el log contiene "Cron synchronization completed" | T2.1 | Quitar el `Run_Context::wrap` → no hay fila `cron_sync_all` |
| **R2** | El webhook devuelve no-200 si el run falla | `T28.73`: `Receiver::handle()` con un handler que lanza → respuesta 200 y fila `webhook_item` `failed` (no 500) | T2.5 | Quitar el `try/catch` del Receiver → la respuesta no es 200 |
| **R3** | El lock queda tomado si el cierre se mueve después del `wp_send_json_*` | `T28.74` (observer H1.b): `alegra_mock_fail('GET','/items',500,…)` + `ajax_sync_page` → `success:false` y el observer fotografía el lock **libre** en el instante del send | T2.4 | Mover el `release` a **después** del `wp_send_json_error` (dentro del `try`) → el observer ve el lock **tomado** en el instante del send → `T28.74` falla. Moverlo al `finally` **no** sirve: la excepción del stub corre el `finally` y el lock se libera igual |
| **R4** | Re-fetch por pausa duplica/saltea ítems | `T28.75`: con budget bajo y N ítems, `imported+updated+skipped+errors === N` y no hay `_alegra_item_id` duplicado | T3.1/T3.2 | Quitar el chequeo de deadline → no pausa (procesa todo) |
| **R5** | El heurístico `bulk_wc` no detecta el borrado masivo | `T28.76`: `$_REQUEST=['action'=>'delete','post'=>['1','2']]` + `on_post_delete` → `reason='bulk_wc'`; `post=123` → `manual_wc` | T4.3 | Hardcodear `manual_wc` → el test de `bulk_wc` falla |
| **R6** | La política de tombstones resucita un borrado manual no deseado | `T28.77`: matriz 3 reasons × 3 policies; el botón normal (`respect`) **no** crea con tombstone `manual_wc`; `ignore_bulk` recrea `bulk_wc` | T4.4 | Volver a `exists()` sin política → el test de `ignore_bulk` falla |
| **R7** | La instrumentación agrega I/O por ítem (NFR-04) | `T28.78`: una página de N ítems hace **1** `GET /items` (`alegra_mock_count('GET','/items')===1`), no N; el log tiene ≤ 1 línea de progreso por página | T2.4/T3.2 | Agregar un `Heartbeat::set` dentro del loop por ítem → el conteo de red no cambia, pero la aserción de log por página falla |
| **R8** | `clear_all_logs` borra evidencia | `T28.79`: el confirm contiene "TODOS" y "No se puede deshacer"; tras `clear_all_logs()`, `get_option('alegra_connector_log_retention_days')===30` | T6.1 | Quitar el copy destructivo → la aserción estática falla |
| **R9** | `Run_Context::finish` re-finaliza un run cerrado | `T28.710`: `finish($id,'cancelled')` sobre un run `completed` → `status($id)` sigue `completed` | T1.5 | Quitar el guard `Runs::status()` → el test falla |
| **R10** | Ampliar la allowlist reintroduce SSRF | `T28.711`: `sanitize_image_hosts("https://cdn.example.com\n*\nfoo:8080")` → solo `cdn.example.com`; `is_allowed_image_url('http://…')===false` | T5.3 | Quitar el rechazo de `*` → el test de comodín falla |
| **R11** | La inyección de `run_id` filtra contexto fuera del run | `T28.712`: tras `Run_Context::finish`, un `(new Logger())->info('x')` posterior **no** lleva `run_id` en el archivo (nunca la forma estática `Logger::info('x')`, que es el fatal de B2) | T1.2/T1.5 | Quitar `Logger::clear_run_context()` del finish → el test falla |
| **R12** | `sync_products` cambia de default y el cron importa catálogo no deseado | Test de Fase 6 (ver `fase-6-ui-logs-monitor.md`): las 3 fuentes de verdad en `false` | T6.5 | Cambiar `alegra-connector.php:424` a `true` → la aserción estática falla |
| **R13** | Pagos/clientes/órdenes se afectan por tocar helpers compartidos | `T28.713`: correr la reconciliación de pagos y el pull de clientes como hoy → los conteos y las llamadas HTTP no cambian respecto del baseline; los tests existentes siguen verdes | T7.3 | N/A (es una regresión de no-cambio; el guard es que los 1289 tests base sigan verdes) |
| **R14** | El cierre del chunked borra el cursor de **productos** al completar `customers`/`categories` (regresión real, Momus B-A) | `T28.718`: sembrar `alegra_connector_products_import_cursor=1500`; correr un `ajax_sync_page` de `type='customers'` hasta `done:true` → el cursor de products **sigue** en 1500; un chunked de `products` que completa → el cursor **sí** se borra | T2.4 | Quitar el gate `if ($type === 'products')` del `delete_option` (`fase-2:1279`) → el test de `customers` falla (borra el cursor ajeno) |
| **R15** | Los tests de Fase 6 leen `$resp->data` (no existe; el harness expone `$resp->payload`) (Momus H3 / Oracle B-B) | `T28.62`/`T28.612` leen `$resp->payload['files']`/`['message']`/`['sync_method']` (el payload real es `Alegra_Test_JSON_Response::$payload`, `wp-stubs.php:500`) | T6.1.a / T6.4.b | Revertir a `$resp->data[...]` → los tests fallan (propiedad indefinida → `null`; `assertSame(1, null)` no matchea) |

Además, tests end-to-end de no-regresión:
- `T28.714` **Filas viejas del Monitor**: sembrar una fila `cron_sync_all` con status `completed` y `error_summary`; `ajax_monitor_status` la incluye con `type` crudo y `error` poblado (REQ-MON-03, NFR-06).
- `T28.715` **Kill switch**: con `Kill_Switch::is_active()`, `ajax_monitor_status` devuelve `kill_switch_active===true` y `kill_switch_reason` (NFR-05).
- `T28.716` **`exists()` de tombstones sigue delegando**: `Tombstone_Manager::exists()` y `exists_with_reason()` coinciden (T4.3).

**R3 — cuerpo exacto de `T28.74` (prove-it-catch con el observer H1.b).** El stub `wp_send_json_*`
**lanza** `Alegra_Test_JSON_Response` (una `\Exception`) y en PHP las excepciones **sí** corren el
`finally`; además `acquire_sync_lock_public()` es **re-entrante** dentro del request. Por eso el
"revertir al `finally`" **no** caza el leak: hay que fotografiar el lock en el instante exacto del
`wp_die()`→`die()`, que es justo lo que hace `H1.b` (`$GLOBALS['alegra_test_json_observer']`,
definido en `fase-2 H1.b`). Mismo mecanismo que `T2.4 §Verificación 8` (`fase-2:1203-1205`).

```php
TestRunner::test('T28.74 el lock se libera ANTES del wp_send_json_error (observer H1.b)', function (): void {
    alegra_test_reset();
    alegra_mock_fail('GET', '/items', 500, ['error' => 'boom']);

    $run_id = \Alegra\Connector\Runs::start('chunked_import', 'tester');
    set_transient('alegra_batch_state', [
        'type' => 'products', 'run_id' => $run_id, 'start' => 0, 'offset' => 0, 'per_page' => 30,
        'total_pages' => 2, 'total_items' => 60, 'imported' => 0, 'updated' => 0, 'skipped' => 0,
        'errors' => 0, 'policy' => 'respect',
        'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0],
        'filters' => [],
    ], 600);

    // H1.b: el observer corre JUSTO antes del throw del stub, o sea en el
    // instante exacto en que WP haría wp_die()->die(). Fotografía el lock CRUDO
    // (el option), no vía acquire_sync_lock_public() — que es re-entrante.
    $lock_at_send = 'unset';
    $GLOBALS['alegra_test_json_observer'] = function (bool $success, array $payload) use (&$lock_at_send): void {
        $lock_at_send = get_option('alegra_lock_alegra_sync_running_products', null);
    };

    $resp = alegra_capture_json(fn () => (new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger()))->ajax_sync_page());
    $GLOBALS['alegra_test_json_observer'] = null;

    TestRunner::assertFalse($resp->success, 'un WP_Error de /items debe responder error');
    TestRunner::assertSame(null, $lock_at_send, 'el lock debe estar LIBRE en el instante del send (die() no corre finally)');
    TestRunner::assertSame('failed', \Alegra\Connector\Runs::status($run_id), 'la fila debe cerrarse failed');
    TestRunner::assertFalse(get_option('alegra_lock_alegra_sync_running_products', false), 'el lock no debe quedar tomado');
});
```

> **Prove-it-catch (exacto).** En `ajax_sync_page`, mover
> `release_sync_lock_public($type, $lock)` a **después** del `wp_send_json_error` (dentro del `try`;
> bloque de cierre del `WP_Error`, `fase-2:1128-1133`) → el observer ve el lock **presente** en el
> instante del send → `assertSame(null, $lock_at_send)` **falla**. **No** alcanza con mover el
> release al `finally` (redacción vieja de la fila R3): el stub lanza y el `finally` corre, así que
> el lock se libera igual y el test pasaba **con el bug**.

**Resultado esperado**: 15 riesgos con test + 3 end-to-end, todos verdes y con prove-it-catches (salvo R13, cuyo guard es la no-regresión de la base).

**Dependencias**: Fases 1–6; T7.1.a (modelo de runs).

**Trazabilidad**: R1–R15, NFR-02, NFR-06.

**Verificación**: cada fila de la matriz es su verificación.

**Riesgo**: que un test de regresión dependa de un mock demasiado laxo y dé un falso verde → mitigado por prove-it-catches. R13 no tiene "revert" natural: su guard es que los 1289 tests base sigan verdes.

**Estimación**: L (3 h).

---

### T7.4.a — CHANGELOG + versión + `uninstall.php` + `.pot`

**Objetivo**: documentar el cambio de semántica de "Limpiar logs" y dejar la versión 2.5.0 consistente en las tres fuentes que el build verifica, más la limpieza de las opciones nuevas.

**Descripción técnica**: `build-release.sh` exige `header == README == make-pot.php == argumento` (C2) y la constante se deriva del header (C3). `uninstall.php` debe borrar las 4 opciones nuevas (design §9). Cubre **NFR-06**.

**Desarrollo técnico**:
1. **`CHANGELOG.md`** — agregar arriba del todo (después de `All notable changes…`), con el formato existente:
```markdown
## [2.5.0] - 2026-XX-XX

> **Observabilidad y recuperación del catálogo.** El Monitor ahora muestra los
> imports manuales y chunked (además del cron), "Detener" funciona, "Limpiar
> logs" borra TODO con confirmación, la ruta de logs es visible y el logger
> avisa si no puede escribir. **Cambio de comportamiento intencional:** "Limpiar
> logs" ya no borra solo los antiguos.

### Changed

- **"Limpiar logs" ahora borra TODOS los archivos de log** (incluido el del
  día) y reporta el conteo real. Antes solo borraba los más viejos que la
  retención, por lo que el botón parecía no funcionar. La retención automática
  (`alegra_connector_log_retention_days`) **no** se desactiva. La confirmación
  advierte que es irreversible.
- La página de **Logs** muestra la **ruta absoluta real** del directorio,
  siempre (aunque no haya archivos).

### Added

- **Monitor**: etiquetas de origen (`Cron`/`Manual`/`Chunked`/`Webhook`), badge
  `Abandonado` para runs sin finalizar, y estados vacíos/error honestos (no más
  "Cargando..." infinito). La sección Cron explica cuando la sincronización
  periódica está desactivada.
- **Logger**: si el directorio de logs no es escribible, el admin muestra un
  aviso con la ruta y el error (antes fallaba en silencio).

### Notes

- Sin cambio de esquema.
- `alegra_connector_sync_products` **mantiene** su default `false` (decisión G3,
  rama A): el cron no importa productos salvo toggle; el botón manual y los
  webhooks siguen funcionando igual.
- Opciones nuevas: `alegra_connector_chunked_page_budget`,
  `alegra_connector_allowed_image_hosts_extra`,
  `alegra_connector_products_import_total`, `alegra_connector_logger_write_failed`.
```
2. **`alegra-connector.php:6`** — `Version: 2.5.0` (la constante `ALEGRA_CONNECTOR_VERSION` en `:59` se deriva sola; **no** hay otra constante que tocar).
3. **`README.md:9`** — `Version: 2.5.0 | PHP 8.0+ | …`.
4. **`scripts/make-pot.php:22`** — `$version = '2.5.0';`.
5. **`uninstall.php`** — agregar a `alegra_connector_uninstall_options()` (junto a las opciones existentes, p. ej. después de `:102`):
```php
delete_option('alegra_connector_chunked_page_budget');
delete_option('alegra_connector_allowed_image_hosts_extra');
delete_option('alegra_connector_products_import_total');
delete_option('alegra_connector_logger_write_failed');
```
   (`alegra_connector_products_import_cursor` **ya** está en `:102`; `alegra_connector_log_retention_days` en `:67`.)
6. **`languages/alegra-connector.pot`** — regenerar con `php scripts/make-pot.php` (tooling, no edición a mano: la restricción "NO TOCAR `languages/*`" prohíbe editar traducciones, no regenerar el `.pot`). Commitear el `.pot` actualizado **antes** del build para que la regeneración de `build-release.sh` sea no-op y el árbol quede limpio (C6).
7. **Commit** del bump: `git add CHANGELOG.md alegra-connector.php README.md scripts/make-pot.php uninstall.php languages/alegra-connector.pot && git commit -m "chore(release): 2.5.0"`.

**Resultado esperado**: `grep '^ \* Version:' alegra-connector.php` → `2.5.0`; `README.md:9` y `make-pot.php:22` → `2.5.0`; `uninstall.php` contiene las 4 opciones nuevas; `CHANGELOG.md` describe el cambio de "Limpiar logs".

**Dependencias**: Fases 1–6 (todas las strings nuevas deben existir antes de regenerar el `.pot`).

**Trazabilidad**: NFR-06.

**Verificación**:
- Source-scan `T28.717`: `uninstall.php` contiene las 4 opciones nuevas; `alegra-connector.php` contiene `Version: 2.5.0`; `CHANGELOG.md` contiene `## [2.5.0]` y `borra TODOS los archivos de log`.
- **Prove-it-catches**: quitar una de las 4 opciones de `uninstall.php` → la aserción estática falla.
- `grep -c "2.5.0"` en los 3 archivos de versión.

**Riesgo**: que el `.pot` regenerado cambie y ensucie el árbol → commitearlo antes del build. Que el `CHANGELOG` no se actualice → la aserción estática lo cubre.

**Estimación**: M (1,5 h).

---

### T7.4.b — Release 2.5.0: build determinista + ZIP + `.sha256` + workflow + verificación de digest

**Objetivo**: producir y publicar el artefacto de release **byte-idéntico** al commiteado, verificable por su `.sha256`.

**Descripción técnica**: política de artefactos (`docs/RELEASE_2.4.2_VERIFICATION.md:40-63`): el mantenedor **construye y commitea** el ZIP localmente; CI **solo verifica y publica** (nunca recompila — incidente v2.4.0). `build-release.sh` es determinista (C6). El ZIP excluye `scripts/`, `docs/`, `CHANGELOG.md`, `README.md`, `releases/` (C4). Cubre el DoD de release.

**Desarrollo técnico**:
1. **Bump + gates ya hechos** en T7.4.a (árbol limpio).
2. **Build local** (corre smoke+exec+.pot como gates, y aborta si el árbol está sucio o las versiones no coinciden):
```bash
bash scripts/build-release.sh 2.5.0
```
   Salida esperada: `Version consistency OK: header == README == make-pot.php == 2.5.0`, `SMOKE OK`, `EXEC-TEST OK`, y `Wrote releases/alegra-connector-v2.5.0.zip` + `.sha256`.
3. **Commit de artefactos**:
```bash
git add releases/alegra-connector-v2.5.0.zip releases/alegra-connector-v2.5.0.zip.sha256
git commit -m "chore(release): add the 2.5.0 build artifacts"
```
   (Si `build-release.sh` regeneró el `.pot` y quedó distinto, commitearlo también; por eso T7.4.a lo regenera antes.)
4. **Workflow** — `.github/workflows/release.yml`, en el paso `Publish GitHub Release` (`:68-77`), agregar la marca de latest **como string** (C5):
```yaml
        with:
          tag_name: v${{ steps.version.outputs.version }}
          name: Alegra Connector ${{ steps.version.outputs.version }}
          generate_release_notes: true
          make_latest: "true"
          fail_on_unmatched_files: true
          files: |
            releases/alegra-connector-v${{ steps.version.outputs.version }}.zip
            releases/alegra-connector-v${{ steps.version.outputs.version }}.zip.sha256
```
   (Sin comillas, YAML interpreta `true` como booleano y el input —que es de tipo string— no lo recibe como se espera. **Comillado siempre.**)
5. **Tag y push** (dispara el workflow):
```bash
git tag -a v2.5.0 -m "Alegra Connector 2.5.0"
git push origin main
git push origin v2.5.0
```
6. **CI**: `.github/workflows/release.yml:43-61` verifica `sha256sum -c` del ZIP commiteado; `:63-66` corre smoke+exec; `:68-77` publica el ZIP y el `.sha256` commiteados (sin recompilar).

**Resultado esperado**: release `v2.5.0` publicado en GitHub, marcado como **latest**, con el ZIP y el `.sha256` adjuntos, byte-idénticos a `releases/`.

**Dependencias**: T7.4.a (bump), T7.1.b (gates verdes).

**Trazabilidad**: DoD de release; NFR-06.

**Verificación (digest asset == ZIP commiteado)**:
```bash
# 1. El ZIP local coincide con su .sha256
( cd releases && sha256sum -c alegra-connector-v2.5.0.zip.sha256 )

# 2. El asset publicado es byte-idéntico al ZIP commiteado
COMMITTED=$(awk '{print $1}' releases/alegra-connector-v2.5.0.zip.sha256)
gh release download v2.5.0 -p 'alegra-connector-v2.5.0.zip' -O /tmp/alegra-2.5.0.zip
DOWNLOADED=$(sha256sum /tmp/alegra-2.5.0.zip | awk '{print $1}')
test "$COMMITTED" = "$DOWNLOADED" && echo "ASSET OK"

# 3. El release está marcado latest
gh release view v2.5.0 --json isLatest,assets --jq '{isLatest, assets: [.assets[].name]}'
```
   Criterio: `ASSET OK` y `isLatest: true`.

**Riesgo**: que CI recompile (prohibido) → el workflow solo verifica/publica; que el asset no coincida → el `sha256sum -c` del CI lo detecta y **no publica**. Que `make_latest` llegue como booleano → comillado.

**Estimación**: M (1,5 h).

---

### T7.4.c — Nota de release / doc de verificación

**Objetivo**: dejar el documento de verificación 2.5.0 para el comerciante y para el próximo mantenedor.

**Descripción técnica**: la convención del repo es `docs/RELEASE_<X.Y.Z>_VERIFICATION.md` (existe `RELEASE_2.4.2_VERIFICATION.md`). Cubre **NFR-06** (cambio de semántica documentado) y cierra el DoD.

**Desarrollo técnico**:
1. Crear `docs/RELEASE_2.5.0_VERIFICATION.md` con:
   - Encabezado + política de artefactos (copiar la de 2.4.2, `:40-63`).
   - Sección **★★★ Lo nuevo de la 2.5.0**: los 4 problemas + smoking gun (los pasos de T7.2), cada uno con "Resultado esperado".
   - **Matriz de prove-it-catches** de T7.1.b (test → fix revertido → falla).
   - **Riesgos de upgrade**: el cambio de semántica de "Limpiar logs" (irreversible, con confirmación); `sync_products` sigue en `false` (rama A); sin migración.
   - **Rollback**: reinstalar 2.4.2 (los datos no cambian de esquema).
2. Referenciar el doc desde `CHANGELOG.md` (nota de release) y desde el README si aplica.
3. **No** agregar el doc al ZIP: `docs/` está en `.distignore` (C4).

**Resultado esperado**: el doc existe, mapea cada problema reportado a un resultado verificable, y lista el mapa de prove-it-catches.

**Dependencias**: T7.2 (resultados reales) y T7.1.b (matriz).

**Trazabilidad**: NFR-06.

**Verificación**: `docs/RELEASE_2.5.0_VERIFICATION.md` existe y contiene las secciones de los 4 problemas + smoking gun + prove-it-catches.

**Riesgo**: doc desactualizado respecto del comportamiento real → se escribe después de T7.2.

**Estimación**: M (1,5 h).

---

## Criterios de aceptación (checklist final — los 4 problemas + smoking gun)

| # | Problema reportado / síntoma | Resultado verificable (WP admin) | REQ | Tareas | Verificación (T7.2) |
|---|---|---|---|---|---|
| 1 | **"El monitor de procesos no funciona"** | Un import manual/chunked/webhook aparece en el Monitor con origen y progreso; "Detener" corta el run y lo deja `Cancelado` | REQ-MON-01..06 | T1.1b, T1.3, T1.3b, T1.4, T1.5, T2.2–T2.5, T6.4 | Pasos Problema 1 |
| 2 | **"No registra los logs" + "Limpiar logs no funciona"** | Cada import (y sus salidas tempranas) deja inicio/progreso/fin con `run_id`; "Limpiar logs" borra **todo**, dice cuántos y no toca la retención; el logger avisa si no puede escribir; la ruta absoluta es visible | REQ-LOG-01..07 | T1.2, T2.1–T2.4, T6.1, T6.2, T6.3 | Pasos Problema 2 |
| 3 | **"El proceso se cerró sin decir nada" + 60 s vs 240 s + sin progreso** | Ninguna rama cierra sin `showNotice`; el chunked pausa por presupuesto propio y reanuda sin perder ítems ni fatal 500; barra y contadores reales | REQ-IMP-01..04, NFR-01, NFR-05 | T3.1–T3.4, T2.4 | Pasos Problema 3 |
| 4 | **"Reanudar vs reimportar" + tombstones** | Dos botones inequívocos; el cursor es visible; "Reimportar todo desde cero" recrea lo borrado masivamente sin tocar los manuales | REQ-RES-01..04 | T4.1–T4.5 | Pasos Problema 4 |
| 5 | **Smoking gun: "borré todo → Traer → se cerró sin aviso → al minuto aparecieron algunos sin imágenes"** | Nunca hay cierre mudo; la pausa es visible con el cursor; los fallos de imagen se reportan; el catálogo se recrea completo desde cero con imágenes (o con el desglose de fallos) | REQ-IMP-01, REQ-RES-01/04, REQ-IMG-01/02/03, REQ-LOG-01/02 | T3.3, T3.4, T4.1, T5.1, T5.2 | Pasos Smoking gun |
| 6 | **Sin regresión (distribuido)** | Cron y webhooks funcionan igual (y ahora dejan run+log); el chunked anda con `set_time_limit` ignorado; el Monitor degrada bien | NFR-02, NFR-05, NFR-06 | T2.1, T2.5, T3.1, T6.4, T7.3 | T7.3 (matriz R1–R15) |

### Trazabilidad del síntoma en 7 pasos (audit final Oracle)

El trace end-to-end del síntoma textual del comerciante se cerró en **7 pasos**; cada uno tiene su
resultado verificable, su cobertura de requerimiento y su paso manual en `T7.2`:

| # | Paso del síntoma | Resultado verificable | REQ | Tareas | T7.2 |
|---|---|---|---|---|---|
| 1 | Borrar todos los productos → ¿la reimportación vence los tombstones? | El botón nuevo "Reimportar todo desde cero" (`ignore_all` default) recrea los `bulk_wc`/`manual_wc`; `alegra_deleted` **nunca** se resucita; los tombstones preexistentes también se recrean | REQ-RES-01/03 | T4.1, T4.3b, T4.4, T4.5 | Pasos Problema 4 |
| 2 | Clic "Traer desde Alegra" → ¿el JS muestra mensaje en TODA rama de fallo? | Ninguna rama muda: `if (cancelled)` separado de `if (!r.success)` con `showNotice(safeMsg(...))`; la rama terminal tras reintentos muestra `S.connectionError` | REQ-IMP-01/02 | T3.3.a–c | Pasos Problema 3 |
| 3 | "Se cerró en segundos sin decir nada" → ¿se elimina el cierre silencioso? | El run + lock se cierran **antes de cada** `wp_send_json_*` (4 sends); no hay `die()` que deje el lock tomado ni rama sin aviso | REQ-MON-05, REQ-IMP-01 | T2.4, T3.1.a/b | Pasos Problema 3 |
| 4 | "No aparecieron productos" → ¿la puerta de tombstone queda logueada y surfaceada? | `exists_with_reason()` + política; el `reason`/`policy` se loguean y el contador `skipped` viaja al payload y al Monitor | REQ-RES-03/04, REQ-LOG-02 | T4.4, T3.2.a, T3.4 | Pasos Smoking gun |
| 5 | "~1 minuto después aparecieron algunos" (el cron) → ¿se hace visible el cron? | `Run_Context::wrap('cron_sync_all')` crea fila + heartbeat; `runTypeLabel()` muestra `Cron` y `sync_products=false` deja `products_skipped='config'` logueado | REQ-MON-01/04, REQ-CON-01 | T2.1, T6.4.a, T6.4.b | Pasos Problema 1 |
| 6 | "No eran todos" → ¿se hace visible la pausa/presupuesto con el remanente? | El chunked corta por presupuesto propio con `paused:true` + `offset` + cursor; el JS muestra "Reanudando…" y sigue; el badge "Pausado en el ítem X de Y" | REQ-IMP-03/04, REQ-RES-04 | T3.1.a/b/c, T3.3.b, T4.1 | Pasos Problema 3/4 |
| 7 | "Sin imágenes" → ¿se arregla el cap de 60 s y el reporte de fallos? | Guard de deadline antes de cada descarga (las que no entran = `deferred`, el producto igual se importa); extensión por mime real (no `.jpg`); fallos reportados con desglose | REQ-IMG-01/02/03, NFR-05 | T3.2.b, T5.1a, T5.2a, T5.2b | Pasos Smoking gun |

---

## DoD Fase 7 (checklist de cierre)

- [ ] T7.1.a: agrega `get_row()` y `_n()` **sobre** el modelo de `wp_alegra_runs` de **T1.1a** (sin redefinirlo); los 1289 tests base siguen verdes.
- [ ] T7.1.b: `bash scripts/exec-test.sh` → `EXEC-TEST OK` con el conteo real registrado (objetivo ≥ 1377); `bash scripts/smoke-test.sh` → `SMOKE OK`.
- [ ] T7.2: checklist manual firmado + capturas del Monitor, Logs y el modal.
- [ ] T7.3: R1–R15 con test concreto; prove-it-catches documentados (R13 = base verde; R14 = cursor por tipo; R15 = payload vs data).
- [ ] T7.4.a: `CHANGELOG.md`, versión 2.5.0 en header/README/make-pot, `uninstall.php` con las 4 opciones, `.pot` regenerado y commiteado.
- [ ] T7.4.b: `releases/alegra-connector-v2.5.0.zip` + `.sha256` commiteados; `make_latest: "true"` en el workflow; tag `v2.5.0` pusheado; CI publicó; `ASSET OK` + `isLatest: true`.
- [ ] T7.4.c: `docs/RELEASE_2.5.0_VERIFICATION.md` con los 4 problemas + smoking gun + prove-it-catches.
