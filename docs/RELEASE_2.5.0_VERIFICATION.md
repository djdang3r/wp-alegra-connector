# Verificación en Producción — Alegra Connector 2.5.0

Esta guía es la **lista de validación** de la versión **2.5.0**. La 2.5.0 es una
**feature release** (sin cambio de esquema) de **observabilidad y recuperación
del catálogo**: el Monitor muestra los imports manuales y chunked (además del
cron), "Detener" funciona, "Limpiar logs" borra **TODO** con confirmación, la
ruta de logs es visible y el logger avisa si no puede escribir. Lo **nuevo de la
2.5.0** está en la sección **★★★ (2.5.0)**.

La 2.5.0 **conserva** las verificaciones de la 2.4.2, 2.4.1, 2.4.0, 2.3.11, 2.3.7,
2.3.6 y anteriores: siguen siendo válidas y están más abajo.

- **Checklist manual detallada:** `docs/sdd/logs-monitor-import/MANUAL-ACCEPTANCE.md`.
- **Logs:** `Alegra Connector → Logs` (en disco,
  `wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`).
- **Monitor:** `Alegra Connector → Monitor`.
- **Ajustes:** `Alegra Connector → Configuración` (pestañas "Sincronización" y
  "Avanzado").

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Alto** | Si falla, el comerciante cree que el catálogo está sincronizado cuando no lo está, o se pierde evidencia. **Bloquea** el uso real hasta resolverse. |
| **Medio** | Una función secundaria queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. |

---

## Política de artefactos de release (el ZIP lo construye el mantenedor)

> **Regla:** el ZIP de release se **construye y commitea localmente**; CI
> **solo lo publica**. El `.sha256` versionado en `releases/` es la **fuente de
> verdad** para verificar la descarga.

- **Quién construye:** el mantenedor corre `bash scripts/build-release.sh <X.Y.Z>`
  y commitea `releases/alegra-connector-v<X.Y.Z>.zip` + `.sha256` **antes** de
  crear el tag. El tag es el último paso.
- **Qué hace CI:** ante un push de tag `v*`, `.github/workflows/release.yml`
  **no recompila**. Verifica que el ZIP commiteado coincida con su `.sha256`
  (`sha256sum -c`) y recién entonces lo publica con `softprops/action-gh-release`
  (`make_latest: "true"`). Si el ZIP no está commiteado o el checksum no coincide,
  el job **falla** y no publica nada.
- **Por qué:** la v2.4.0 publicó un asset con los mismos archivos pero **distintos
  bytes** que el ZIP del repo, porque el workflow lo recompilaba en CI. Con esta
  política el asset es **byte-idéntico** al artefacto versionado.
- **Build determinista (bonus):** `build-release.sh` normaliza los mtimes a
  `1980-01-01` y usa `zip -X` con una lista ordenada (`LC_ALL=C sort`). Es una red
  de seguridad de reproducibilidad, **no** un permiso para recompilar en CI.
- **Verificación manual:**
  ```bash
  ( cd releases && sha256sum -c alegra-connector-v2.5.0.zip.sha256 )
  ```

---

## Regresión del harness (antes del release)

| Gate | Comando | Resultado |
|---|---|---|
| Sintaxis | `php -l` sobre cada `.php` tocado | OK |
| Ejecución | `bash scripts/exec-test.sh` | **EXEC-TEST OK: 1592 assertions passed, 0 failed** |
| Smoke | `bash scripts/smoke-test.sh` | **SMOKE OK** |

Baseline del cambio: **1289** aserciones (Fase 0). Fases 1–6: +241. Fase 7
(regresión R1–R15 + release): **+62** (18 tests nuevos). Total **1592**.

---

## ★★★ Lo nuevo de la 2.5.0 (los 4 problemas + smoking gun)

> **Leé esto antes de actualizar.** La 2.5.0 **no cambia el esquema**. Cambia
> **qué ves** y **qué hace** el plugin en cuatro puntos que antes mentían, más el
> síntoma del catálogo recreado sin imágenes. **Cambio de comportamiento
> intencional:** "Limpiar logs" ya no borra solo los antiguos.

### ★★★.1 — El Monitor de procesos ahora funciona

1. Abrí **Productos** y **Monitor**.
2. Dispará **"Traer desde Alegra"**.
3. **Resultado esperado:** en el Monitor aparece una fila `Chunked` **Corriendo**
   con "Procesando... Página N/M" y barra `items_done/total_items`; al terminar
   pasa a **Historial Reciente** como `Completado`. "Detener este proceso" deja la
   fila **Cancelado** y el próximo lote no importa ítems nuevos.
4. **Riesgo: Alto.** Sin esto no hay forma de saber si el import corre.

### ★★★.2 — Los logs se registran y "Limpiar logs" borra TODO

1. Corré un import y abrí **Logs**: hay inicio/progreso/fin, cada línea con
   `run_id`/`run_type`.
2. Provocá una salida temprana (desconectar): el log registra
   `connection_not_tested`.
3. La página de Logs muestra la **ruta absoluta** del directorio aunque no haya
   archivos.
4. **"Limpiar logs"** (confirmar) borra **todos** los `.log` (incluido el de hoy),
   dice cuántos y **no** toca la retención (sigue en 30). Cancelar no borra nada.
5. **Riesgo: Medio.** El borrado es irreversible (la confirmación lo advierte).

### ★★★.3 — El proceso ya no se cierra mudo

1. Con catálogo grande, dispará el import y mirá el modal: la barra y los
   contadores avanzan por página; en una pausa por presupuesto dice
   **"Reanudando…"** y sigue; nunca hay cierre mudo ni 500.
2. Cortá la red a mitad: tras agotar reintentos aparece una **notificación de
   error de conexión** y el botón se rehabilita.
3. **Riesgo: Alto.** Era el reporte "se cerró sin decir nada".

### ★★★.4 — Reanudar vs reimportar + tombstones

1. A mitad de un import, **Productos** muestra el badge **"Pausado en el ítem N de
   M…"** (solo con `cursor>0`).
2. **"Reimportar todo desde cero"** (confirmación destructiva) limpia el cursor,
   arranca en la página 1 y recrea lo borrado masivamente sin tocar los borrados
   manuales.
3. **Riesgo: Alto.** El workflow central "borré todo → reimportar" depende de esto.

### ★★★.5 — Smoking gun: el catálogo recreado no queda sin imágenes en silencio

1. Borrá **todos** los productos en WooCommerce (papelera → vaciar).
2. **"Traer desde Alegra"** o **"Reimportar todo desde cero"**.
3. **Resultado esperado:** el modal no se cierra mudo; el Monitor muestra el run
   `Chunked`; los Logs tienen la traza con `run_id`; los productos recreados
   tienen sus imágenes o el resumen reporta
   **"N imágenes no se pudieron importar"** con desglose (host bloqueado /
   descarga / adjuntado / diferidas). **Nunca** "aparecieron algunos sin imágenes"
   en silencio.
4. **Riesgo: Alto.** El síntoma textual del comerciante.

---

## Matriz de prove-it-catch (Fase 7)

Cada test de regresión se validó revirtiendo su fix: el test **falla** con el bug
y **pasa** con el fix. Corrido contra `bash scripts/exec-test.sh`.

| Test | Riesgo | Fix revertido | Resultado |
|---|---|---|---|
| `T28.71` | T7.1.a | `get_row()` deja de delegar en `get_results()` | ✅ FAIL observado |
| `T28.72` | R1 | Quitar `Run_Context::wrap('cron_sync_all')` del cron | ✅ FAIL observado |
| `T28.73` | R2 | Estrechar el `catch (\Throwable)` del Receiver | ✅ FAIL observado |
| `T28.74` | R3 | Quitar el `release_sync_lock_public` antes del send | ✅ FAIL observado |
| `T28.75` | R4 | Quitar el chequeo de deadline del chunked | ✅ FAIL observado |
| `T28.76` | R5 | Hardcodear `manual_wc` en `classify_delete_reason` | ✅ FAIL observado |
| `T28.77` | R6 | Ignorar la política de tombstones (gate `if (false)`) | ✅ FAIL observado |
| `T28.78` | R7 | Agregar `Heartbeat::set` dentro del loop por ítem | ✅ FAIL observado |
| `T28.79` | R8 | Quitar el copy destructivo del confirm | ✅ FAIL observado |
| `T28.710` | R9 | Quitar el guard de re-finalizado en `Run_Context::finish` | ✅ FAIL observado |
| `T28.711` | R10 | Quitar el chequeo `https` de `is_allowed_image_url` | ✅ FAIL observado |
| `T28.712` | R11 | Quitar `Logger::clear_run_context()` del teardown | ✅ FAIL observado |
| `T28.713` | R13 | N/A — guard = no-regresión de la base (1289→1592 verdes) | ✅ base verde |
| `T28.714` | — | Filas viejas del Monitor (cobertura nueva) | ✅ base verde |
| `T28.715` | — | Quitar `kill_switch_active`/`kill_switch_reason` del payload | ✅ FAIL observado |
| `T28.716` | — | `exists()` deja de delegar (`return false`) | ✅ FAIL observado |
| `T28.717` | — | Quitar una opción de `uninstall.php` | ✅ FAIL observado |
| `T28.718` | R14 | Quitar el gate `if ($type === 'products')` del borrado de cursor | ✅ FAIL observado |
| `T28.62`/`T28.612` | R15 | Revertir `$resp->payload` a `$resp->data` | ✅ cubierto por Fase 6 |
| `T28.614` | R12 | Cambiar `sync_products` a `true` | ✅ cubierto por Fase 6 |

**R13** no tiene un "revert" natural (es una regresión de **no-cambio**): su guard
es que las **1592** aserciones base sigan verdes.

---

## Riesgos de upgrade (2.4.2 → 2.5.0)

- **Cambio de semántica de "Limpiar logs" (irreversible).** Antes borraba solo los
  archivos más viejos que la retención; ahora borra **todos** los `.log` con una
  confirmación explícita. La retención automática
  (`alegra_connector_log_retention_days`, 30 días) **no** se desactiva.
- **`alegra_connector_sync_products` sigue en `false`** (decisión G3, rama A). El
  cron **no** importa productos salvo que el toggle esté tildado; el botón manual
  y los webhooks siguen igual.
- **Sin cambio de esquema.** `Schema::SCHEMA_VERSION` no cambia; no hay migración.
- **Opciones nuevas** (limpiadas por `uninstall.php`):
  `alegra_connector_chunked_page_budget`,
  `alegra_connector_allowed_image_hosts_extra`,
  `alegra_connector_products_import_total`,
  `alegra_connector_logger_write_failed`.

## Rollback

Reinstalar **2.4.2** desde `releases/alegra-connector-v2.4.2.zip`. **No hay
migración de datos**: el esquema es idéntico, así que el rollback es un simple
reemplazo de carpeta. Las opciones nuevas quedan huérfanas (inofensivas) o se
borran al desinstalar.

---

## Checklist final

- [ ] Respaldo de base de datos y archivos.
- [ ] `sha256sum -c` del ZIP commiteado.
- [ ] `SMOKE OK` sobre el ZIP extraído.
- [ ] `EXEC-TEST OK: 1592 assertions passed, 0 failed`.
- [ ] Versión **2.5.0** visible en **Plugins**.
- [ ] ★★★.1 Monitor muestra el import manual/chunked; "Detener" cancela.
- [ ] ★★★.2 Logs con `run_id`; ruta absoluta; "Limpiar logs" borra todo sin tocar
      la retención.
- [ ] ★★★.3 Sin cierre mudo; pausa visible; error de conexión notificado.
- [ ] ★★★.4 Badge de cursor; "Reimportar todo desde cero" con confirmación.
- [ ] ★★★.5 Smoking gun: catálogo recreado con imágenes o con desglose de fallos.
- [ ] "Modo de prueba" desactivado; estado de facturas deseado.
