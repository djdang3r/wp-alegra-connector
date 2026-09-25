# Propuesta — Logs, Monitor e Importación de productos (`logs-monitor-import`)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` (observabilidad + monitor de procesos + importación de productos) |
| Tipo | Corrección de bugs en producción + feature de UX + endurecimiento de flujos largos |
| Versión analizada | 2.4.2 (`alegra-connector.php:6`) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Estado | Propuesta — **pendiente de verificación en vivo (Fase 0)** para 2 hipótesis |
| Regla | Todo claim de código cita `archivo:línea`; lo no verificado se marca **SIN VERIFICAR / BLOQUEADO** |

> **Correcciones de cita al diagnóstico de entrada.** El diagnóstico venía con líneas aproximadas;
> tras verificar con lectura directa, las correcciones son:
>
> - `Admin_Dashboard.php:2112-2113` (`get('/items')` WP_Error) → real **`:2115-2116`**.
>   Las líneas 2112-2113 son el comentario y el armado de `$api_params`.
> - `Products.php:2227` y `:2390` (`.jpg` hardcodeado) → real **`:2231`** y **`:2393`**.
>   En 2227 y 2390 hay `return 0;` / `return;`.
> - `Products.php:2226-2241` y `:2394-2398` (fallos de imagen solo logueados) →
>   real **`:2221-2244`** (download+sideload) y **`:2386-2402`**.
> - `Logger.php:153-156` (se traga sus propios errores salvo `WP_DEBUG`) →
>   el `try/catch` real es **`:162-168`**; el fallback de `flock` es **`:155-161`**.
> - `Tombstone_Manager.php:28-55` → el método `on_post_delete` real es **`:32-59`**.
> - `Admin_Dashboard.php:2335` (handler "Limpiar logs") → el método `ajax_clear_logs`
>   arranca en **`:2322`**; `:2335` es la llamada a `clear_old_logs`.
> - `admin.js:273-276` (rama silenciosa tras agotar reintentos) → la rama terminal sin
>   aviso es **`:278`** (el `retries++` está en `:273`).
> - `alegra-connector.php:6` → la versión es **2.4.2**, no 2.3.11.
>
> El resto de las citas fue **verificado** y es exacto (ver §4).

---

## 1. El problema, en lenguaje del dueño de tienda

> **"Le di traer productos, el proceso se cerró sin decir nada, no cargó todo y quedó sin imágenes."**

El síntoma en vivo, reproducido por el comerciante, es la pista principal:

> *"Primero eliminé todos los productos del ecommerce, al tener el ecommerce limpio le di traer
> desde Alegra y en breves segundos se cerró el proceso sin decir nada o mostrar alguna
> notificación, y aparentemente no cargó productos. Recargué la página y no aparecían productos;
> al cabo de un minuto, que volví a cargar la página, aparecieron algunos productos — no eran
> todos — y además sin imágenes."*

Ese relato se descompone en **cuatro fallas encadenadas**, todas verificables en el código:

1. **El botón "Traer desde Alegra" de la página Productos es el flujo por lotes (chunked).**
   `templates/admin-products.php:92` emite `class="alegra-quick-sync" data-type="products"`.
   `admin.js:125` lo engancha en `initSyncNow` (`admin.js:124`) y, al no ser `type === 'all'`,
   entra al camino por lotes (`admin.js:207-282`) → `alegra_sync_start`
   (`Admin_Dashboard.php:2018`) → loop `alegra_sync_page` (`:2073`).
   **No** es `ajax_import_from_api` (`:4073`), que es el botón de la página Importar
   (`templates/admin-import.php:154`, `admin.js:692`).

2. **El proceso muere por tiempo y sin aviso.** Cada página del chunked hace
   `@set_time_limit(60)` (`Admin_Dashboard.php:2105`) y descarga imágenes con
   `download_url($image_url, 15)` (`Products.php:2220` y `:2386`) más regeneración de
   thumbnails. Con 30 ítems por página eso revienta los 60 s → fatal de PHP → HTTP 500.
   Del lado del JS, el `error` reintenta 2 veces y en la rama terminal hace `cleanup()` **sin
   `showNotice`** (`admin.js:272-279`, rama sin aviso en `:278`). Resultado: **cierre silencioso**.
   El presupuesto de 240 s (`Products.php:1270`) **no aplica a este camino**: es solo de
   `import_from_alegra()`, que usa el cron y la página Importar (esta última con
   `@set_time_limit(300)` en `:4078`). El chunked y el importador global tienen presupuestos
   distintos e inconsistentes.

3. **El catálogo quedó incompleto por diseño oculto.** El cron (`alegra-connector.php:577`,
   programado a `time()+60`) corre en el siguiente `page_load` con presupuesto de 240 s y
   **cursor de reanudación** (`Products.php:1264`, `:1373-1381`). Por eso "al minuto
   aparecieron algunos": el cron importó el primer tramo y guardó el cursor. **"Algunos, no
   todos" es exactamente el comportamiento actual**, y **nada en la UI lo explica**.

4. **Las imágenes fallan en silencio.** El nombre del archivo está hardcodeado a `.jpg`
   (`Products.php:2231` y `:2393`) y los fallos solo van al log (`:2222-2226`,
   `:2238-2242`, `:2387-2389`, `:2400-2402`); nunca se muestran al comerciante. La allowlist
   de hosts es `['alegra.com']` (`Products.php:2125-2131`) con match de subdominio
   (`:2136-2157`) — si Alegra sirviera desde un host fuera de `*.alegra.com`, **toda** imagen
   se bloquearía (`:2170-2176`). Esto último queda **SIN VERIFICAR**: necesita captura de la
   URL real de imagen en la instalación del comerciante.

### Los tres síntomas adicionales que reportó el comerciante

5. **"No está registrando los logs del proceso de traer productos."** Los caminos por lotes
   y `ajax_import_from_api` (`:4073-4116`) **retornan temprano** (lock ocupado, sin conexión,
   `WP_Error`) **sin escribir en el logger**. Peor: `Logger::write` se traga sus propios errores
   salvo `WP_DEBUG` (`Logger.php:162-168`), así que una carpeta de uploads no escribible produce
   **cero logs y cero aviso**.

6. **"El botón limpiar logs no funciona."** `ajax_clear_logs` (`Admin_Dashboard.php:2322`)
   llama a `clear_old_logs($retention)` (`:2335`) y esa función **solo borra archivos con
   `filemtime < cutoff`** (`Logger.php:198-222`, chequeo en `:211`). El archivo de **hoy**
   nunca se toca → "0 logs eliminados" → la pantalla queda idéntica y parece roto. El botón
   (`admin-logs.php:36`, JS en `admin.js:386`) dice "Limpiar antiguos".

7. **"El monitor de procesos no funciona."** El Monitor (`templates/admin-monitor.php`) lee
   `Runs::currently_running()` / `Runs::recent()` / `Heartbeat`. Pero `Runs::track` se llama
   **solo** desde el cron (`Controller.php:150`) y `Heartbeat::set` **solo** dentro de ese
   callback (`:173,212,240,266,277`). Ni `ajax_import_from_api` ni el chunked
   `ajax_sync_start`/`ajax_sync_page` crean fila en `Runs` ni setean `Heartbeat`. Por eso un
   import manual **jamás** aparece: el Monitor solo puede mostrar cron.

### Impacto

- **Funcional:** el comerciante no puede vaciar y reimportar el catálogo (el flujo documentado
  es **imposible**, ver punto 8). Queda con catálogo parcial y sin imágenes, sin saber por qué.
- **De confianza:** la UI promete "Traer desde Alegra" como acción completa; entrega un tramo.
- **Operativo:** sin logs ni Monitor, no hay forma de diagnosticar desde el admin. El soporte
  queda ciego.
- **Distribuido:** el bug se manifiesta **más** en catálogos grandes (cualquier tienda real),
  que es justamente el público objetivo del plugin.

### El agravante de los tombstones (bloquea el flujo que el comerciante intentó)

8. Borrar los productos de WC con `_alegra_item_id` dispara `before_delete_post`
   (`alegra-connector.php:214`) → `Tombstone_Manager::on_post_delete` (`:32-59`) escribe un
   tombstone por ítem → al reimportar, `import_single_item_from_alegra` **saltea la creación**
   si el tombstone existe (`Products.php:1429`). Es decir: **el workflow "borrar todo +
   reimportar" que el comerciante ejecutó está bloqueado por el propio plugin.** No es un bug
   de red: es una decisión de diseño que convierte una operación legítima en silencio.

### El agravante de configuración

9. `alegra_connector_sync_products` tiene default `false` tanto en `$defaults`
   (`alegra-connector.php:424`) como en la lectura del cron (`Controller.php:171`). El cron
   **nunca** importa productos salvo que el comerciante active el checkbox. Esto explica por
   qué el "tramo del minuto" pudo no ocurrir en algunas tiendas, y es una trampa de
   configuración: la casilla de la UI y el default del runtime pueden no coincidir (ya
   abordado en `config-gates`).

---

## 2. Decisiones del comerciante (ya tomadas — se honran tal cual)

1. **"Limpiar logs" → borrar TODO.** Un botón, limpia todo (no solo lo viejo). Confirmación
   explícita antes de borrar.
2. **"Traer productos" → DOS botones.** El actual **reanuda** desde el cursor; se agrega
   **"Reimportar todo desde cero"** con confirmación. Nada de ambigüedad.
3. **Catálogo grande → chunks con progreso visible**, como el flujo existente de
   "Facturar pendientes". **No** se sube el timeout, **no** hay pausas silenciosas.
4. **Mayormente manual, pero también debe actualizarse por webhooks.** El cron es
   **temporal** mientras se afinan los webhooks. La modalidad de importación ya es
   configurable en el dashboard y **queda como está**. El botón de Productos es un proceso
   manual, y así se entiende.
5. **SDD primero** — propuesta → spec → design → tasks, revisado antes de implementar.

Restricciones textuales del comerciante:

- *"Nuestro plugin es profesional y el dashboard debe seguir con la buena usabilidad, nada de
  ambigüedades y que el usuario no se complique, debe ser fácil de usar."*
- *"Nada de chapuzadas y de código como pañitos de agua tibia."*
- *"Todo debe quedar funcional y todo el proceso debe funcionar correctamente sin problemas,
  errores, bugs o conflictos."*
- *"La idea es que funcione en cualquiera"* — plugin **distribuido**, cualquier tamaño/config.
- *"No necesitamos lo de la facturación electrónica ni DIAN."*
- *"No necesitamos carpetas de test ni nada de eso"* — el ZIP de release excluye `scripts/`.

---

## 3. Alcance

### 3.1 Dentro del alcance

**Observabilidad (logs + Monitor)**

- Que **todos** los caminos de importación (manual, chunked, cron, webhook) escriban log de
  inicio, progreso, fin, pausa y error — incluyendo **sus salidas tempranas** (lock ocupado,
  sin conexión, `WP_Error`, kill switch, cancelación).
- Que el **botón Limpiar logs borre todo** lo que corresponde, con confirmación y mensaje
  honesto (cuántos archivos/entradas se borraron).
- Que `Logger::write` deje de ser silencioso ante fallos propios: al menos un aviso visible en
  el admin cuando el logger no puede escribir.
- Que el **Monitor muestre los procesos manuales y chunked** además del cron: fila en `Runs`,
  `Heartbeat` por etapa, progreso, y que el botón "Detener" funcione sobre ellos.

**Importación de productos**

- **Fail-loud:** eliminar todas las ramas de cierre silencioso del JS (`admin.js:220`, `:238`,
  `:272-279`) y del servidor. Todo cierre muestra notificación con causa y resultado.
- **Un solo camino con progreso visible.** El botón actual y el nuevo "Reimportar todo desde
  cero" comparten el flujo chunked, con progreso real (página/ítems/errores) y sin depender de
  un `set_time_limit` que revienta.
- **Respetar el presupuesto de tiempo de forma limpia:** el chunked debe cortar **antes** del
  límite y reanudar en la siguiente página, no morir por fatal de PHP.
- **Cursor visible y comprensible:** la UI muestra si el próximo "Traer" reanuda o empieza de
  cero; el nuevo botón fuerza el reinicio y **limpia el cursor**.
- **Tombstones y reimportación:** "Reimportar todo desde cero" debe poder recrear lo borrado
  (política de tombstones explícita y reversible), sin romper la protección de borrados
  manuales para el flujo incremental.
- **Imágenes:** corregir el `.jpg` hardcodeado y **decidir la allowlist con evidencia**;
  los fallos de imagen dejan de ser silenciosos y se reportan en el resumen.

**UX**

- Dos botones en Productos con etiquetas inequívocas y confirmación en el destructivo.
- Sin jerga técnica; mensajes en español que digan **qué pasó** y **qué hacer**.

### 3.2 Fuera del alcance (explícito)

- **DIAN / facturación electrónica:** nada de `stamp`, `paymentForm` ni esquema fiscal.
- **Rediseño general del dashboard:** solo se tocan Productos, Logs y Monitor.
- **Reescritura de la modalidad de importación** (manual/cron/webhook): la configuración actual
  queda como está. El cron se mantiene **temporal**, no se elimina en este cambio.
- **Webhooks:** no se rediseñan; el objetivo es que el import manual y el cron queden sanos
  mientras los webhooks maduran.
- **Inventario, pagos, clientes, órdenes:** fuera. Si comparten helpers de observabilidad, se
  usan, pero no se cambia su lógica de negocio.
- **Carpetas de test / harness `scripts/`:** no se agregan ni se distribuyen.
- **Multisite** más allá de lo que ya soporta el plugin.

### 3.3 Restricción transversal

El plugin es **distribuido**: todo debe funcionar para **cualquier** tamaño de catálogo y
cualquier host (con o sin `set_time_limit` habilitado, con o sin `exec`, con o sin cron real).
**Sin regresión** para instalaciones existentes. Cuando un cambio altere comportamiento previo
(p. ej. limpiar logs ahora borra todo), la propuesta lo declara como riesgo y exige
confirmación + nota de release.

---

## 4. Los hallazgos, agrupados por tema

Leyenda: **[BUG]** = comportamiento incorrecto / promesa incumplida · **[FEATURE]** = falta
control o visibilidad · **[HYG]** = limpieza.

### Tema A — Observabilidad (logs + Monitor)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| A1 | El import manual/chunked no crea fila en `Runs` ni `Heartbeat`; el Monitor solo puede mostrar cron. | `Controller.php:150` (único `Runs::track`); `Heartbeat::set` solo en `:173,212,240,266,277`; `templates/admin-monitor.php:11`+`Admin_Dashboard.php:3696-3699` | **[BUG]** |
| A2 | Las salidas tempranas de import (lock, sin conexión, `WP_Error`, kill switch, cancel) no se loguean. | `Admin_Dashboard.php:4073-4116` (sin log en los `return`); `Products.php:1410-1415` | **[BUG]** |
| A3 | `Logger::write` se traga sus errores salvo `WP_DEBUG` → import sin logs y sin aviso. | `Logger.php:162-168` (catch), `:155-161` (flock) | **[BUG]** |
| A4 | "Limpiar logs" solo borra archivos viejos; el de hoy nunca → "0 eliminados". | `Logger.php:198-222` (chequeo `:211`); handler `Admin_Dashboard.php:2322` (call `:2335`); botón `admin-logs.php:36`; JS `admin.js:386` | **[BUG]** |

### Tema B — Importación de productos

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| B1 | El botón Productos es el flujo chunked, no `ajax_import_from_api`. | `templates/admin-products.php:92` → `admin.js:124-125,207-282` → `Admin_Dashboard.php:2018,2073` | **[DISEÑO]** (documentar, no bug per se) |
| B2 | Ramas de cierre silencioso en el JS chunked (sin aviso). | `admin.js:220` (start falla, botón queda deshabilitado), `:238` (`!r.success`), `:272-279` (terminal en `:278`) | **[BUG]** crítico |
| B3 | Puertas del servidor que devuelven `success:false` en segundos. | lock `Admin_Dashboard.php:2099-2101`; `alegra_batch_state` ausente `:2079`; `WP_Error` de `/items` `:2115-2116` | **[BUG]** UX |
| B4 | 60 s por página (chunked) vs 240 s (importador global); imágenes + thumbnails revientan los 60 s → fatal 500 → cierre silencioso. | `Admin_Dashboard.php:2105`; `Products.php:1270`; descargas `:2220,2235,2386,2397` | **[BUG]** crítico |
| B5 | Tombstones bloquean la reimportación tras borrar todo. | `alegra-connector.php:214` → `Tombstone_Manager.php:32-59` → `Products.php:1429` | **[BUG]** de flujo |
| B6 | Cursor de reanudación invisible; "Traer" reanuda en vez de reimportar. | `Products.php:1264`, `:1373-1381`; UI sin indicador | **[BUG]** UX |
| B7 | `.jpg` hardcodeado para toda imagen (rompe PNG/WebP y la validación del sideload). | `Products.php:2231`, `:2393` | **[BUG]** |
| B8 | Fallos de imagen solo logueados, nunca reportados al usuario. | `Products.php:2222-2226`, `:2238-2242`, `:2387-2389`, `:2400-2402` | **[BUG]** UX |
| B9 | Allowlist `['alegra.com']`; si el CDN real no es `*.alegra.com`, **toda** imagen se bloquea. | `Products.php:2125-2131`, `:2136-2157`, bloqueo `:2170-2176` | **[BUG]** — **SIN VERIFICAR (Fase 0)** |
| B10 | `sync_products` default `false` → el cron no importa productos salvo toggle. | `alegra-connector.php:424`; `Controller.php:171` | **[BUG]** de configuración (cubierto también en `config-gates`) |
| B11 | El cron programa a `time()+60` y reanuda por cursor → "algunos, no todos" es diseño no explicado. | `alegra-connector.php:577`; `Controller.php:150,272`; `Products.php:1373-1381` | **[BUG]** UX |

### Tema C — UX / superficie

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| C1 | No existe acción para reimportar desde cero; el comerciante no puede recuperarse. | `templates/admin-products.php:88-105` (solo "Traer desde Alegra") | **[FEATURE]** |
| C2 | "Traer desde Alegra" y "Traer seleccionados" (`:102`) conviven sin jerarquía clara de alcance. | `templates/admin-products.php:92,102` | **[FEATURE]** UX |
| C3 | El resumen de fin no distingue "pausado por presupuesto" de "completado". | `Products.php:1385-1393`; `admin.js:245-267` | **[BUG]** UX |

---

## 5. Enfoque (alto nivel — el detalle va en `design`)

1. **Un solo pipeline de importación instrumentado.** Toda importación (botón actual, botón
   nuevo, cron, webhook) pasa por el mismo esqueleto que: abre fila en `Runs`, setea
   `Heartbeat` por etapa, escribe log de inicio/progreso/fin/pausa/error, y respeta kill
   switch/cancelación. **Cero caminos paralelos no instrumentados.**
2. **Fail-loud de punta a punta.** Cada `return` temprano del servidor devuelve un motivo
   legible; cada rama del JS muestra `showNotice` con ese motivo. Ningún cierre sin mensaje.
3. **Chunked con presupuesto propio y reanudación honesta.** El servidor corta **antes** del
   límite por página y responde "pausado, continuá"; el JS continúa la siguiente página. Nada
   de `set_time_limit` como parche. Progreso visible real (ítems/página/errores).
4. **Dos botones, dos semánticas.** "Traer desde Alegra" = incremental (respeta cursor y
   tombstones de borrados manuales). "Reimportar todo desde cero" = destructivo controlado
   (confirma, limpia cursor, define política de tombstones, reconstruye).
5. **Logs honestos.** "Limpiar logs" borra todo con confirmación; el logger avisa cuando no
   puede escribir. Se documenta la retención y qué significa cada acción.
6. **Monitor unificado.** La misma instrumentación alimenta el Monitor para manual, chunked y
   cron; "Detener" funciona en todos.
7. **Imágenes: arreglar lo confirmado y verificar lo dudoso.** El `.jpg` hardcodeado se
   corrige (extensión real). La allowlist **no se toca a ciegas**: Fase 0 captura la URL real
   de imagen; si está fuera de `*.alegra.com`, se amplía con el host observado (y filtro
   existente `alegra_connector_allowed_image_hosts`, `Products.php:2128`).

### Sobre el alcance de las imágenes (argumento explícito)

**Se incluye ahora** la corrección del `.jpg` y el reporte de fallos de imagen, porque son
parte directa del síntoma reportado ("sin imágenes") y son de bajo riesgo. **La allowlist se
condiciona a la verificación de Fase 0**: cambiarla sin evidencia sería una chapuza. Si la
captura confirma que el host está fuera de `alegra.com`, el fix entra en este mismo cambio; si
no, se cierra como falsa alarma y se documenta. La regeneración de thumbnails se trata como
costo dentro del presupuesto del chunked (parte del punto 3), no como optimización aparte.

---

## 6. Impacto

### Archivos/áreas afectadas

| Área | Impacto | Descripción |
|---|---|---|
| `admin/assets/js/admin.js` | Modificado | Ramas fail-loud, progreso, dos botones, limpiar logs (**editable** — fixes de JS necesarios, confirmado) |
| `admin/Admin/Admin_Dashboard.php` | Modificado | `ajax_sync_start`/`ajax_sync_page`/`ajax_import_from_api` instrumentados, `ajax_clear_logs`, handlers del Monitor |
| `includes/Sync/Products.php` | Modificado | Presupuesto, cursor, tombstones, imágenes (`.jpg`, allowlist, reporte) |
| `includes/Sync/Controller.php` | Modificado | Instrumentación de runs manuales, visibilidad de logs |
| `includes/Runs.php`, `includes/Heartbeat.php` | Modificado/Reusado | Tipos de run manual/chunked, progreso |
| `logger/Logger/Logger.php` | Modificado | Borrar todo, aviso de fallo de escritura |
| `templates/admin-products.php` | Modificado | Segundo botón + confirmación |
| `templates/admin-logs.php`, `templates/admin-monitor.php` | Modificado | Copys honestos, estado de reanudación |
| `languages/*` | **NO TOCAR** | Restricción explícita |
| `logger/*/index.php`, `public/*/index.php` | **NO TOCAR** | Restricción explícita |

### Riesgo

| Riesgo | Prob. | Mitigación |
|---|---|---|
| "Limpiar logs" ahora borra todo y el comerciante pierde evidencia | Media | Confirmación explícita, copy claro, log de la propia limpieza |
| "Reimportar desde cero" duplica productos si el matching falla | Media | Reusar `Entity_Map` + `_alegra_item_id`; simulación/dry-run antes de escribir; reporte de creados/actualizados |
| Política de tombstones revivida borra protección de borrados manuales | Media | Separar tombstones por `reason` (`manual_wc` vs `alegra_deleted`, `Tombstone_Manager.php:9-12`); "desde cero" solo ignora/limpia los del flujo masivo |
| Chunked más granular degrada UX en catálogos chicos | Baja | Misma UX que "Facturar pendientes"; páginas de 30 |
| Cambiar el presupuesto altera tiempos de tiendas existentes | Baja | Sin regresión de resultado; solo cambia cómo se reparte en el tiempo |
| Allowlist de imágenes se amplía de más (SSRF) | Baja | Mantener https + allowlist; agregar solo el host verificado, nunca `*` |

### Compatibilidad hacia atrás

- El flujo por lotes y el importador global **mantienen su contrato** (`success`/`data`), pero
  **siempre** incluyen `message`. Consumidores que hoy ignoran `message` no se rompen.
- La semántica de "Limpiar logs" **cambia** (antes: viejos; ahora: todo). Se documenta como
  **cambio intencional** y se anota en `CHANGELOG.md`.
- El default `sync_products=false` **no se cambia** en este cambio salvo decisión explícita; se
  coordina con `config-gates`.

### Preocupación de plugin distribuido

Nada puede depender de `set_time_limit` efectivo, `exec`, cron real, ni de un host de imágenes
fijo. El chunked debe funcionar **aunque el host ignore `set_time_limit`**: por eso el corte es
por presupuesto propio y reanudación, no por extender el límite. El Monitor debe degradar bien
si `Runs` no puede escribir (mostrar el error, no pantalla vacía).

---

## 7. Criterios de éxito

Observables desde el WP admin, sin mirar la base:

1. **Import manual visible en el Monitor.** Al dar "Traer desde Alegra", el Monitor muestra el
   proceso activo con progreso; al terminar, aparece en el historial reciente con estado y
   conteos.
2. **Nunca un cierre silencioso.** Cualquier final —éxito, error, cancelación, lock ocupado,
   sin conexión— produce una notificación en pantalla con la causa. Cero ramas mudas.
3. **Progreso visible en catálogo grande.** Un catálogo que no entra en un request muestra
   avance incremental y termina (o pausa explícitamente) sin fatal 500 y sin perder ítems.
4. **"Reimportar todo desde cero" funciona.** Tras borrar todos los productos de WC, el botón
   recrea el catálogo completo (con confirmación previa), venciendo los tombstones del borrado
   masivo, sin tocar los borrados manuales protegidos.
5. **El cursor es comprensible.** La UI indica si el próximo "Traer" reanuda o empieza de cero,
   y "Reimportar" limpia el cursor.
6. **Logs se registran.** Un import (manual o cron) deja entradas de inicio/progreso/fin/error
   consultables en la página de Logs.
7. **"Limpiar logs" borra todo** y lo dice con un conteo honesto; tras limpiar, la pantalla
   refleja el vacío.
8. **Imágenes:** el nombre del archivo respeta la extensión real; los fallos de imagen se
   reportan en el resumen (cuántas fallaron), no solo en el log. Si Fase 0 confirma host fuera
   de allowlist, las imágenes se importan.
9. **Sin regresión:** cron y webhooks siguen funcionando; la configuración de modalidad de
   importación queda intacta.
10. **Distribuido:** funciona en un host que ignore `set_time_limit` y en catálogos chicos y
    grandes.

---

## 8. Preguntas abiertas (forks reales)

1. **Host real de imágenes (BLOQUEA parte de imágenes).** ¿La URL de imagen de los ítems de
   esta tienda resuelve a `*.alegra.com` o a otro CDN? **SIN VERIFICAR — Fase 0.** Define si
   se toca la allowlist y con qué host.
2. **Política de tombstones en "Reimportar todo desde cero".** ¿Se limpian **todos** los
   tombstones de `item` (incluidos los `manual_wc`), o se ofrece una opción explícita
   ("recrear también los que borré a mano")? Recomendación: por defecto **solo** los del
   borrado masivo; los `manual_wc` requieren un check aparte. Decisión del comerciante.
3. **¿El botón actual "Traer desde Alegra" debe reanudar o siempre reiniciar?** La decisión 2
   dice que reanuda; queda confirmar que el nuevo botón es el único reinicio. (Recomendación:
   sí.)
4. **`sync_products` default.** ¿Se alinea a `true` para que el cron importe por defecto, o se
   deja `false` y se coordina con `config-gates`? Afecta la "actualización automática mientras
   maduran los webhooks". Decisión del comerciante.
5. **Retención de logs tras "borrar todo".** ¿La limpieza deja intacta la opción de retención
   para el barrido automático (`Maintenance.php:56`), o también la reinicia? (Recomendación:
   dejar la retención; "borrar todo" es puntual.)

---

## 9. Relación con otros SDD

- `docs/sdd/config-gates` ya aborda la honestidad de configuración y el default de
  `sync_products` (`config-gates` §Tema B). Esta propuesta **no reabre** esa decisión: la
  consume y coordina el punto B10/§8.4.
- `docs/sdd/import-filters` introdujo el modal de filtros y el binding `.alegra-quick-sync`
  (`import-filters/design.md:29,136,148`). Esta propuesta **respeta** ese binding y lo
  reutiliza; no cambia la semántica de filtros.
- `docs/sdd/inventory` e `docs/sdd/payments` no se tocan. Si comparten el logger o `Runs`, se
  benefician de la instrumentación sin cambios de su lógica.
