# Prueba manual de aceptación — `logs-monitor-import` (2.5.0)

Checklist humana que valida los **4 problemas reportados** y el **síntoma
smoking-gun** sobre una réplica de la tienda. No reemplaza al harness
(`bash scripts/exec-test.sh`, 1592 aserciones) porque depende de la UI real y de
los tiempos del navegador.

- **Preparación**: instalar **2.5.0** en una réplica de la tienda.
- **Rutas exactas (WP admin)**
  - Productos → `wp-admin/admin.php?page=alegra-connector-products`
  - Monitor → `wp-admin/admin.php?page=alegra-connector-monitor`
  - Logs → `wp-admin/admin.php?page=alegra-connector-logs`
  - Configuración → `wp-admin/admin.php?page=alegra-connector-settings`
- **Configuración previa** (Configuración): conexión **testeada**; "Productos" e
  "Imágenes de productos" **activados**; método de sincronización `cron` (para el
  cron) o `real-time` (para el banner). Catálogo de **> 60 ítems** en Alegra
  (≥ 2 páginas de 30).
- **Leyenda**: ✅ = resultado esperado verificable; 🔧 = botón/control exacto.

> **Cómo se verificó que cada paso es ejercitable (código en HEAD).** Monitor =
> `render_monitor_page` (`Admin_Dashboard.php:222`); "Detener" =
> `ajax_kill_run` (`Admin_Dashboard.php:4068`) + `data-run-id` en
> `templates/admin-monitor.php:146`; Productos = botones
> `templates/admin-products.php:108/114/124`; badge de cursor =
> `templates/admin-products.php:95`; Logs = `templates/admin-logs.php:36`;
> "Limpiar logs" = `ajax_clear_logs` (`Admin_Dashboard.php:2587`); barra y
> contadores = `admin/assets/js/admin.js`; "Reanudar…" = string
> `pausedResuming` (T28.39).

---

## Problema 1 — "El monitor de procesos no funciona"

**Cubre:** REQ-MON-01/02/04/05. **Ejercitable:** `ajax_sync_page` crea la fila
`chunked_import` (T28.28); el Monitor la lista desde `Runs::currently_running()`
(`Admin_Dashboard.php:3978`); "Detener" llama a `ajax_kill_run` → `Runs::request_stop`.

1. 🔧 Abrí **Productos** en una pestaña y **Monitor** en otra.
2. 🔧 En Productos, pulsá **"Traer desde Alegra"**.
3. ✅ En **Monitor** aparece una fila `Chunked` con estado **Corriendo**, mensaje
   "Procesando... Página N/M" y barra `items_done / total_items` que avanza cada
   ~5 s. Al terminar pasa al **Historial Reciente** como `Completado` con conteos.
4. 🔧 Volvé a disparar y pulsá **"Detener este proceso"** sobre la fila.
5. ✅ El próximo lote **no importa ítems nuevos**; la fila queda **Cancelado**;
   aparece la notificación de detenido. (Antes: `Heartbeat::clear` borraba el stop
   y el botón no hacía nada.)

## Problema 2 — "No registra los logs" + "Limpiar logs no funciona"

**Cubre:** REQ-LOG-01/02/04/05/06/07. **Ejercitable:** el logger inyecta
`run_id`/`run_type` (T28.15/T28.35); `clear_all_logs()` borra todo y no toca la
retención (T28.61/T28.79); la ruta absoluta se renderiza siempre (T28.69).

1. 🔧 Corré un import (manual o chunked) y abrí **Logs**.
2. ✅ Hay entradas de **inicio**, **progreso por página** y **fin**; cada línea
   incluye `"run_id":R` y `"run_type":"..."` en su contexto.
3. 🔧 Provocá una salida temprana: **Configuración** → desconectar (o dejar la
   conexión sin testear) → disparar **"Traer desde Alegra"**.
4. ✅ La respuesta muestra un mensaje de causa y el log registra
   `connection_not_tested` con `run_type`.
5. ✅ La página de **Logs** muestra la **ruta absoluta** del directorio aunque no
   haya archivos.
6. 🔧 Pulsá **"Limpiar logs"** → **confirmar**.
7. ✅ Dice **"N archivos de log eliminados"**, la tabla queda vacía, y la
   **retención sigue en 30** (Configuración → no cambió).
8. 🔧 Volvé a pulsar **"Limpiar logs"** → **cancelar**.
9. ✅ No se borra nada.

## Problema 3 — "El proceso se cerró sin decir nada" + 60 s vs 240 s + sin progreso

**Cubre:** REQ-IMP-01/02/03/04, NFR-01/05. **Ejercitable:** el chunked corta por
presupuesto propio (T28.31/T28.75); el lock se libera antes del send (T28.74); el
JS nunca cierra mudo (T28.38/T28.310).

1. 🔧 Con el catálogo grande, dispará **"Traer desde Alegra"** y **mirá el modal**.
2. ✅ La barra y los contadores (`importados | actualizados | omitidos | errores`)
   avanzan por página; en una pausa por presupuesto el label dice
   **"Reanudando…"** y **sigue** sin esperar; **nunca** hay cierre mudo ni 500.
3. 🔧 Cortá la red a mitad (DevTools → offline) y esperá.
4. ✅ Tras agotar reintentos se ve una **notificación de error de conexión** y el
   botón vuelve a habilitarse (no queda deshabilitado en silencio).

## Problema 4 — "Reanudar vs reimportar" + tombstones

**Cubre:** REQ-RES-01/02/03/04. **Ejercitable:** badge de cursor
(`templates/admin-products.php:95`), botón "Reimportar todo desde cero"
(`:114`), confirmación destructiva (`admin.js` → `S.confirmReimport`).

1. 🔧 A mitad de un import (o tras una pausa), mirá **Productos**.
2. ✅ Aparece un badge **"Pausado en el ítem N de M. 'Traer desde Alegra'
   continúa desde ahí."** (solo si `cursor>0`); con cursor 0 no hay badge.
3. 🔧 Pulsá **"Reimportar todo desde cero"** → **confirmar**.
4. ✅ El cursor se limpia, arranca en la página 1 y el import corre completo; el
   botón pide confirmación destructiva.

## Smoking gun — "borré todo → Traer → se cerró sin aviso → al minuto aparecieron algunos sin imágenes"

**Cubre:** REQ-IMP-01, REQ-RES-01/04, REQ-IMG-01/02/03, REQ-LOG-01/02.
**Ejercitable:** tombstones con política (T28.46/T28.77); fallos de imagen
reportados con desglose (T28.54/T28.59); run + log visibles (T28.28/T28.35).

1. 🔧 En WooCommerce, borrá **todos** los productos (selección masiva → papelera →
   vaciar).
2. 🔧 En **Productos**, pulsá **"Traer desde Alegra"** (flujo normal) o
   **"Reimportar todo desde cero"** (para vencer tombstones).
3. ✅ El modal muestra progreso; **no** se cierra en silencio; al terminar hay una
   notificación de resultado.
4. ✅ Abrí **Monitor**: el run aparece con origen `Chunked` y sus conteos.
5. ✅ Abrí **Logs**: está la traza completa (`run_id`).
6. ✅ Los productos recreados tienen sus imágenes (o el resumen reporta
   "N imágenes no se pudieron importar" con desglose: host bloqueado / descarga /
   adjuntado / diferidas). **Nunca** "aparecieron algunos sin imágenes" en
   silencio.

---

## Trazabilidad del síntoma en 7 pasos

| # | Paso del síntoma | Resultado verificable | Paso acá |
|---|---|---|---|
| 1 | Borrar todo → ¿vence tombstones? | "Reimportar todo desde cero" recrea `bulk_wc`/`manual_wc`; `alegra_deleted` nunca | Problema 4 |
| 2 | "Traer" → ¿avisa en toda rama de fallo? | Ninguna rama muda; `showNotice(safeMsg(...))` | Problema 3 |
| 3 | "Se cerró sin decir nada" → ¿sin cierre mudo? | Run + lock se cierran antes de cada send | Problema 3 |
| 4 | "No aparecieron productos" → ¿puerta de tombstone visible? | `exists_with_reason()` + `skipped` al payload/Monitor | Smoking gun |
| 5 | "~1 min después aparecieron algunos" (cron) → ¿cron visible? | `Run_Context::wrap('cron_sync_all')` + `Cron` | Problema 1 |
| 6 | "No eran todos" → ¿pausa/presupuesto visible? | `paused:true` + offset + badge "Pausado en el ítem X de Y" | Problemas 3/4 |
| 7 | "Sin imágenes" → ¿cap de 60 s + reporte? | Deadline antes de cada descarga (diferidas) + desglose de fallos | Smoking gun |

## Cierre

- [ ] Problema 1 firmado (capturas del Monitor corriendo/cancelado).
- [ ] Problema 2 firmado (capturas de Logs con `run_id` y del borrado total).
- [ ] Problema 3 firmado (captura del modal con "Reanudando…").
- [ ] Problema 4 firmado (captura del badge de cursor y del confirm destructivo).
- [ ] Smoking gun firmado (captura del resumen de imágenes).
