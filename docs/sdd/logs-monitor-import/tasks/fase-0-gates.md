# Tareas micro-detalladas — Fase 0 (gates G1–G4)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Alcance de este archivo | **Fase 0 — T0.1, T0.2, T0.3, T0.4** (4 tasks) |
| Naturaleza | **READ-ONLY.** No se escribe código de producción en esta fase. |
| Entorno | Tienda del comerciante (o réplica) + cuenta Alegra real + repo en HEAD |
| Salida única | `docs/sdd/logs-monitor-import/phase0-results.md` (se crea en T0.1 y se completa en cada gate) |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`) · `bash scripts/smoke-test.sh` |

> **Regla de oro.** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta que el gate
> correspondiente tenga su rama elegida registrada en `phase0-results.md`. Fase 1 **no** depende
> de ningún gate: se puede empezar ya.

## Mapa de gates

| Task | Gate | Pregunta | Bloquea | Est. |
|---|---|---|---|---|
| T0.1 | G1 | ¿El host real de `images[].url` está dentro de `*.alegra.com`? | **T5.3** | S |
| T0.2 | G2 | ¿Cómo se borró el catálogo y qué `reason` hay en los tombstones? | **T4.3, T4.4** | M |
| T0.3 | G3 | ¿Valor real de `alegra_connector_sync_products` y estado de `config-gates`? | **T6.5** | S |
| T0.4 | G4 | ¿`wp_send_json_*` termina el request y **no** corre `finally`? | **T2.4** | S |

## Correcciones de cita detectadas al escribir esta fase

Re-verificadas contra HEAD. Varias afectan directamente a Fase 1/2 y se referencian en
`fase-1-cimientos.md`.

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | Design §2.3/§11 y `tasks.md` T2.4: el `finally` de `ajax_sync_page` "**no corre** en los `wp_send_json_error` de `:2116,2148,2184`". | Correcto, **pero incompleto**: el `try` arranca en `Admin_Dashboard.php:2103` y el `wp_send_json_success` **también está dentro del `try`** (`:2216-2221`), antes del `finally` de `:2222-2224`. Con `die()` el lock **también se filtra en el camino de éxito**. | T2.4 debe liberar el lock **antes de TODO send** (éxito incluido), no sólo de los `error`. Es la causa raíz del "se cerró sin decir nada": la página 1 responde OK, la página 2 encuentra el lock tomado y muere mudo. |
| C2 | `tasks.md` T2.4 (Prove-it-catches): "el mock de `wp_send_json_error` corta el request como `die()`". | **Falso.** El stub `wp_send_json_*` **lanza** `Alegra_Test_JSON_Response` (`scripts/lib/wp-stubs.php:496`, `:542-553`), y en PHP una **excepción SÍ ejecuta `finally`**. El harness actual **no** reproduce el salto de `finally`. | El test de "lock libre" pasa aunque el release esté en el `finally` ⇒ **no es prove-it-catch**. T2.4 necesita una costura de prueba (observer) o una aserción source-scan. Detallado en T0.4. |
| C3 | Design §2.1 (pseudo): dentro de `namespace Alegra\Connector` llama `Logger::set_run_context(...)`. | `Logger` es `Alegra\Connector\Logger\Logger`, no `Alegra\Connector\Logger`. Sin `use`, resuelve a un **namespace** ⇒ fatal. | `includes/Run_Context.php` debe importar `use Alegra\Connector\Logger\Logger;`. Ver T1.1b. |
| C4 | Design §2.3 `mark_abandoned`: `$cutoff = gmdate('Y-m-d H:i:s', time() - $grace_seconds)`. | `started_at` se escribe con `current_time('mysql')` (hora **local** del sitio, `Runs.php:63`); `gmdate(time())` es **UTC**. En un sitio con offset, la comparación `started_at < cutoff` queda corrida y la guarda de 180 s se degrada a "sólo TTL de heartbeat". | T1.3 debe usar `gmdate('Y-m-d H:i:s', current_time('timestamp') - $grace_seconds)` para comparar contra el mismo reloj con que se guardó `started_at`. |

---

### T0.1 — G1: host real del CDN de imágenes · BLOQUEA T5.3

**Objetivo**: determinar con evidencia el host real de `images[].url` para decidir si la allowlist
`['alegra.com']` deja fuera las imágenes reales del comerciante. Sin esto, T5.3 podría ampliar la
allowlist a ciegas (chapuza) o dejarla como está mientras las imágenes siguen bloqueadas.

**Descripción técnica**: `Products::allowed_image_hosts()` devuelve exactamente `['alegra.com']`
(`includes/Sync/Products.php:2125-2130`); `is_allowed_image_url()` exige `https` y hace match de
host/subdominio (`:2136-2158`); si falla, `download_and_attach_image()` **bloquea** y sólo loguea
(`:2170-2176`). La spec marcó SIN VERIFICAR si el host real cae dentro de `*.alegra.com`
(REQ-IMG-04). El design §6.3 fija la decisión: **no hardcodear** el CDN; si el host está fuera, el
comerciante lo carga por UI en la opción nueva `alegra_connector_allowed_image_hosts_extra`.
Este gate sólo **observa**; no toca código.

**Desarrollo técnico**:

- **Archivos a leer (no editar):** `includes/Sync/Products.php:2125-2130` (allowlist),
  `:2136-2158` (matcher), `:2170-2176` (bloqueo); `admin/Admin/Admin_Dashboard.php:108-144`
  (método muerto, ver corrección de cita abajo); `docs/sdd/logs-monitor-import/phase0-results.md`
  (nuevo).

- **Paso 1 — capturar la URL real.** Dos vías, usar la que esté disponible:

  - **(a) Desde un adjunto ya importado** (la más barata; requiere que alguna imagen se haya
    importado alguna vez). SQL read-only (ajustar el prefijo real, normalmente `wp_`):
    ```bash
    wp db query "SELECT meta_value FROM wp_postmeta WHERE meta_key='_alegra_image_url' AND meta_value <> '' ORDER BY meta_id DESC LIMIT 5;"
    ```
    También sirve: `wp post meta list <product_id> --keys=_alegra_image_url`.

  - **(b) Desde el payload de `GET /items`** (si no hay adjuntos). Ejecutar en la tienda, read-only:
    ```bash
    wp eval '$c = new \Alegra\Connector\API\Client(new \Alegra\Connector\Logger\Logger()); $r = $c->get("/items", ["limit" => 3, "mode" => "advanced"]); foreach ((array) $r as $it) { foreach ((array) ($it["images"] ?? []) as $img) { echo ($img["url"] ?? "") . "\n"; } }'
    ```
    Si el `Client` no está autoloaded en el contexto `wp eval`, forzar:
    `wp eval 'require_once ABSPATH . "wp-content/plugins/alegra-connector/alegra-connector.php"; ...'`.

- **Paso 2 — correr el matcher exacto.** Por cada URL capturada (capturar **≥3 ítems distintos**,
  no una sola muestra):
  ```bash
  wp eval '$u = "https://cdn3.alegra.com/xxxxxxxx.png"; var_dump(parse_url($u, PHP_URL_HOST)); var_dump(\Alegra\Connector\Sync\Products::is_allowed_image_url($u));'
  ```

- **Paso 3 — registrar** en `phase0-results.md`: host crudo de cada URL, esquema (`https`/`http`),
  y el booleano de `is_allowed_image_url`.

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — host `alegra.com` o `*.alegra.com` y `https`** → `is_allowed_image_url() === true`.
    No se toca la allowlist base. Se cierra como falsa alarma. T5.3 implementa **sólo** la opción
    configurable + sanitizer, sin agregar hosts por código. Documentar en `phase0-results.md`.
  - **Rama B — host fuera de `*.alegra.com` y `https`** (p.ej. `cloudfront.net`, `s3.*`,
    `*.cloudinary.com`) → `is_allowed_image_url() === false`; **todas** las imágenes se bloquean.
    T5.3 debe dejar que el comerciante cargue el host observado por la UI
    (`alegra_connector_allowed_image_hosts_extra`); **nunca `*`**; `https` obligatorio; nota en
    `CHANGELOG.md`. **No** se agrega el host al código.
  - **Rama C — la URL es `http://` (sin TLS)** → `is_allowed_image_url() === false` por el chequeo
    de esquema (`:2142-2144`). No es un problema de allowlist sino de origen: escalar al
    comerciante/Alegra (una allowlist no puede habilitar http por NFR-07). Registrar como hallazgo.
  - **Rama D — no hay ninguna URL observable** (catálogo vacío y sin adjuntos) → el gate queda
    **INCONCLUSO**; T5.3 se implementa igual con la opción configurable y se re-verifica cuando
    haya al menos un ítem con imagen.

- **Orden:** Paso 1 → Paso 2 → Paso 3. No hay efectos de escritura.

**Resultado esperado**: `phase0-results.md` con el/los host(s) crudo(s), el esquema y el booleano,
más la rama elegida (A/B/C/D). Si es A o D, T5.3 no agrega hosts; si es B, T5.3 queda habilitada a
cargar el host observado por UI.

**Dependencias**: ninguna. Bloquea **T5.3**.

**Trazabilidad**: REQ-IMG-04, NFR-07 (seguridad de la allowlist), R7 (riesgo SSRF).

**Verificación**: los comandos exactos de los Pasos 1–2 sobre la tienda; el resultado crudo y el
booleano quedan en `phase0-results.md`. Criterio de aceptación: el archivo contiene `host=...`,
`scheme=https|http`, `is_allowed=true|false` y `rama=A|B|C|D`.

**Riesgo**: muestrear **una sola** imagen y asumir el host para todo el catálogo. Guard: capturar
≥3 ítems distintos y, si difieren, listar todos los hosts; la decisión de T5.3 es por host.

**Estimación**: S (0.5 h, agente/comerciante).

> **Corrección de cita (confirmada).** `admin/Admin/Admin_Dashboard.php:113` **no** es una tercera
> allowlist: es una llamada a la allowlist compartida `Sync\Products::is_allowed_image_url()`. El
> tercer `.jpg` sí existe en `:132`. El método `Admin_Dashboard::import_product_image` (`:108-144`)
> tiene **0 llamadores** (grep: sólo la definición). T5.1 lo borra; se re-scopea la frase "elimina
> la tercera allowlist" a "elimina el tercer `.jpg` y el método muerto".

---

### T0.2 — G2: fork de tombstones (`bulk_wc`) · BLOQUEA T4.3, T4.4

**Objetivo**: determinar por qué `reason` están los tombstones del comerciante, cómo borró el
catálogo, la forma real de `$_REQUEST` del bulk delete de WooCommerce, y fijar el **default del
checkbox** de "Reimportar todo desde cero". Sin esto, la política de tombstones de T4.4 y el
clasificador de T4.3 quedarían adivinados.

**Descripción técnica**: `Tombstone_Manager::on_post_delete()` escribe **siempre**
`reason='manual_wc'` (`includes/Tombstone_Manager.php:54`), así que borrado masivo y borrado
individual son indistinguibles. La columna `reason` es `VARCHAR(50) DEFAULT 'manual_wc'` **sin
enum** (`includes/Schema.php:159`) ⇒ `bulk_wc` entra sin migración. El design §5.2 toma la
**tercera opción**: clasificar best-effort el borrado masivo en el request
(`classify_delete_reason()`, T4.3) **más** un checkbox explícito en "desde cero". Este gate
**define el default del checkbox** y valida el heurístico contra la forma real de `$_REQUEST`.
`import_single_item_from_alegra()` hoy saltea la creación si hay tombstone
(`Products.php:1429`).

**Desarrollo técnico**:

- **Paso 1 — SQL de tombstones** (read-only; ajustar prefijo). Registrar los tres resultados:
  ```sql
  SELECT reason, COUNT(*) AS n
    FROM wp_alegra_tombstones
   WHERE alegra_type = 'item'
   GROUP BY reason;

  SELECT COUNT(*) AS vivos
    FROM wp_alegra_tombstones
   WHERE alegra_type = 'item' AND resurrected_at IS NULL;

  SELECT alegra_id, deleted_at, deleted_by, reason
    FROM wp_alegra_tombstones
   WHERE alegra_type = 'item'
   ORDER BY deleted_at DESC
   LIMIT 20;
  ```
  Comando WP-CLI equivalente: `wp db query "<SQL>"`.

- **Paso 2 — preguntar al comerciante**: (a) ¿borró el catálogo con **selección masiva** o
  **ítem por ítem**? (b) ¿hay algún producto borrado a mano que **deba permanecer borrado**?
  (c) ¿usó "Vaciar papelera" (trash) o borrado permanente? Registrar textual.

- **Paso 3 — reproducir la forma exacta de `$_REQUEST`.** En la tienda (o réplica), con DevTools →
  Network, ejecutar los cuatro flujos y capturar el POST a `edit.php?post_type=product`:
  - **Borrado individual:** seleccionar 1 producto → acción "Mover a la papelera" **o** "Eliminar
    permanentemente" → Apply. Forma esperada: `action=delete&post=123&_wpnonce=...` (escalar).
  - **Borrado masivo (select superior):** seleccionar ≥2 → misma acción. Forma esperada:
    `action=delete&post%5B%5D=1&post%5B%5D=2&_wpnonce=...` (`post` es **array**).
  - **Borrado masivo (select inferior):** el `<select>` de abajo postea en `action2`. Forma
    esperada: `action=-1&action2=delete&post%5B%5D=1&post%5B%5D=2&_wpnonce=...` (`action=-1` es
    el "sin acción" que WP ignora y cae al fallback `action2`).
  - **Vaciar papelera:** `edit.php?post_status=trash&post_type=product` → "Vaciar papelera".
    Forma esperada: `delete_all=Empty+Trash&post_status=trash` — **no** `action=delete_all`. WP
    renderiza ese botón con `submit_button('Empty Trash','apply','delete_all')`
    (`wp-admin/includes/class-wp-posts-list-table.php:606`), o sea un submit **`name="delete_all"`**
    (abajo, `delete_all2`); `WP_Posts_List_Table::current_action()` reconoce la acción con
    `isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])` (`:625-631`). Puede no
    traer `post`.
  - **Ojo (crítico):** mover a la papelera **no** dispara `before_delete_post`; sólo el borrado
    permanente lo hace (`alegra-connector.php:214` engancha `before_delete_post`). Si el
    comerciante "borró todo" pasando por papelera y luego vaciando, el `reason` salió del flujo
    **"Vaciar papelera"** (`delete_all`), no del bulk con `post[]`. Confirmar cuál de los dos usó.

- **Paso 4 — fijar el default del checkbox** (mapea a `Run_Context::$tombstone_policy`, T4.4):
  - Si el comerciante dice "no quiero preservar ningún borrado" o el heurístico puede fallar
    (usó REST/WP-CLI/otro plugin) → **default tildado = `ignore_all`** (recrea todo). Es la
    recomendación del design §5.2 y garantiza el workflow "borrar todo → reimportar".
  - Si el comerciante dice "tengo borrados manuales que deben quedar borrados" y la forma
    capturada es limpia (`post[]` array o el submit `delete_all`/`delete_all2`) → **default
    destildado = `ignore_bulk`** (respeta `manual_wc`, ignora `bulk_wc`).
  - En ambos casos el checkbox es **override del comerciante**; el botón normal "Traer desde
    Alegra" siempre usa `respect`.

- **Decision tree sobre `classify_delete_reason()` (T4.3):**
  - Forma observada `action=delete&post[]=…` (array, count>1) → el heurístico `is_array($post) &&
    count>1 → 'bulk_wc'` es correcto.
  - Forma observada `delete_all=Empty+Trash` (submit `name="delete_all"`/`delete_all2`, `action` en
    `-1`) → cubrir explícitamente `isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])
    → 'bulk_wc'` (señal primaria D5; **no** `action=delete_all`).
  - Forma observada `action=-1&action2=delete&post[]=…` (select inferior) → **esperada**: el
    fallback a `action2` + `post[]` de largo >1 ya la clasifica `bulk_wc` (fase-4 T4.3b).
  - Si el comerciante usó un flujo sin `$_REQUEST` (REST/WP-CLI/otro plugin) → el heurístico **no**
    lo detecta; por eso el default debe ser `ignore_all` (no `ignore_bulk`).
  - Forma observada distinta a las cuatro esperadas (p.ej. `action=trash&post[]=…`, un plugin que
    dispare `before_delete_post` fuera del list table, o `action=delete_all` posteado por un plugin)
    → registrar la forma cruda y ajustar T4.3 para contemplarla.

**Resultado esperado**: `phase0-results.md` con (1) conteo por `reason`; (2) respuesta textual del
comerciante; (3) la forma cruda de `$_REQUEST` de cada flujo; (4) el default elegido
(`ignore_all`/`ignore_bulk`) y su justificación.

**Dependencias**: ninguna. Bloquea **T4.3** y **T4.4**.

**Trazabilidad**: REQ-RES-03, R2 y R5 (riesgos del heurístico).

**Verificación**: los 3 SQL del Paso 1 + la captura de Network del Paso 3 + el default en
`phase0-results.md`. Criterio de aceptación: el archivo declara `reason` distintos (o uno solo),
el shape de `$_REQUEST`, y `default_checkbox=ignore_all|ignore_bulk`.

**Riesgo**: que el borrado masivo real use una forma no contemplada y el heurístico falle. Guard:
default `ignore_all` en el botón destructivo (ya confirmado) + `respect` en el botón normal; el
gate reproduce el flujo real para reducir la sorpresa.

**Estimación**: M (1 h).

---

### T0.3 — G3: default de `sync_products` · BLOQUEA T6.5

**Objetivo**: confirmar el valor real de `alegra_connector_sync_products` en la tienda y el estado
de `config-gates`, para cerrar REQ-CON-02 sin romper instalaciones existentes.

**Descripción técnica**: hoy el default `false` está en `alegra-connector.php:424` (`$defaults`),
en la lectura del cron `includes/Sync/Controller.php:171` y en la UI
`templates/admin-settings.php:78` (`checked(get_option('alegra_connector_sync_products', false))`).
**Observación verificada en HEAD**: la UI **ya** usa `false`; la premisa de `config-gates` T2.5
("`true` → `false`") está **stale** sobre HEAD. Si los tres coinciden en `false`, REQ-CON-02 ya se
cumple y no hay migración. REQ-CON-01 (el `else` de log cuando el cron saltea productos) se
implementa en T2.1, independientemente de este gate.

**Desarrollo técnico**:

- **Paso 1 — valor crudo en la tienda:**
  ```bash
  wp option get alegra_connector_sync_products
  wp option get alegra_connector_sync_products --format=json
  ```
  Interpretar: ausente (`Error: Could not get '...'`) / `false` / `true` / `1` / `0`.

- **Paso 2 — estado de `config-gates`** (coordinación, no se toca acá):
  ```bash
  grep -n "sync_products" docs/sdd/config-gates/tasks.md
  grep -n "sync_products" docs/sdd/config-gates/spec.md
  ```
  Si `config-gates` T2.5 ya está hecho, la UI y el runtime ya son `false`. Si no, anotar que
  queda pendiente y que este cambio **no** reabre esa decisión.

- **Paso 3 — confirmar UI vs runtime**: leer `templates/admin-settings.php:78-81`
  (los 4 `checked(..., false)`) y `alegra-connector.php:424` y `Controller.php:171`. Registrar si
  los tres coinciden.

- **Decision tree:**
  - **Rama A (recomendada) — el default queda `false`:** no cambia comportamiento; el cron no
    importa productos salvo toggle; el comerciante usa el botón manual o webhooks. Sin migración.
    REQ-CON-02 se cierra como "ya alineado". T6.5 agrega sólo la aserción de regresión.
  - **Rama B — el default cambia a `true`:** sólo si el comerciante pide explícitamente que el cron
    importe productos mientras maduran los webhooks. Exige sembrar `true` **sólo si la opción está
    ausente** (`get_option(...) === false`), respetar `false` explícito y nota de release.
    **No recomendada** (R8).
  - **Caso borde — la opción está ausente y `config-gates` no corrió:** runtime y UI ya usan
    `false`, así que la rama A se cumple igual; registrar "ausente" como valor.

**Resultado esperado**: `phase0-results.md` con el valor crudo, el estado de `config-gates` y la
rama elegida (`A`/`B`). T6.5 implementa sólo la aserción de regresión (Rama A) o la migración
condicional (Rama B).

**Dependencias**: ninguna. **Coordina** con `config-gates` (no lo bloquea). Bloquea **T6.5**.

**Trazabilidad**: REQ-CON-02, REQ-CON-01 (contexto), R8.

**Verificación**: `wp option get ...` en la tienda; `grep` de `config-gates`; lectura de
`admin-settings.php:78-81`, `alegra-connector.php:424`, `Controller.php:171`. Criterio: el archivo
declara `value=...`, `config_gates=done|pending`, `rama=A|B`.

**Riesgo**: que `config-gates` cambie la UI/runtime **después** de este gate y desalinee el
resultado. Guard: re-ejecutar T0.3 después de que `config-gates` mergee; T6.5 incluye la aserción
`assertSame(false, get_option('alegra_connector_sync_products', false))` + source-scan de la UI.

**Estimación**: S (0.5 h).

---

### T0.4 — G4: comportamiento de `wp_send_json_*` (plataforma)

**Objetivo**: confirmar que `wp_send_json_success/error()` terminan el request con `wp_die()` →
`die()`, que `die()` **no ejecuta `finally`**, y documentar la consecuencia exacta sobre
`ajax_sync_page` — incluido el hallazgo C1 (el leak también ocurre en el **éxito**) y la
limitación del harness C2. Es el gate que habilita T2.4.

**Descripción técnica**: `ajax_sync_page` (`Admin_Dashboard.php:2073-2225`) abre el lock en
`:2099-2102`, entra al `try` en `:2103`, y dentro del `try` llama:
- `wp_send_json_error` en `:2116` (WP_Error de `/items`), `:2148` (WP_Error de `/contacts`),
  `:2184` (WP_Error de categorías);
- `wp_send_json_success` en `:2216-2221` (fin de página).

El `finally` que libera el lock está en `:2222-2224`. En producción, `wp_send_json_*` → `wp_die()`
→ `die()`, y `die()` **no corre `finally`**. Consecuencia: el lock de `products`/`customers`/
`categories` queda tomado hasta el TTL de 300 s (`Controller::acquire_lock(..., 300)`,
`Controller.php:473`). En el camino de **éxito** esto es fatal para el chunked: la página 1
responde OK y deja el lock; la página 2 (`:2099`) recibe `false` → `wp_send_json_error`
"Another sync is in progress" (`:2101`) que el JS actual traga en silencio (`admin.js:238`) →
**cierre mudo**, exactamente el síntoma del comerciante. El design (R1) ya ordena: **cerrar el run
y liberar el lock ANTES de cada `wp_send_json_*`**, dejando el `finally` sólo para excepciones.
T2.4 es la tarea que lo implementa; este gate sólo lo confirma.

**Desarrollo técnico**:

- **Prueba 1 — PHP: `die()` saltea `finally`.** Comando exacto:
  ```bash
  php -r 'try { echo "try\n"; exit; } finally { echo "finally\n"; }'
  # salida: try   (NO imprime "finally")
  ```
  Contraste (excepción sí corre `finally`):
  ```bash
  php -r 'try { throw new Exception("x"); } catch (Exception $e) {} finally { echo "finally\n"; }'
  # salida: finally
  ```
  Registrar la versión de PHP (`php -v`) en `phase0-results.md`.

- **Prueba 2 — `wp_send_json_*` llama a `wp_die()`.** En el core del WP del comerciante (o en el
  repo de WordPress de la versión instalada):
  ```bash
  grep -n -A8 "function wp_send_json\b" wp-includes/functions.php
  grep -n -A6 "function wp_send_json_success\b" wp-includes/functions.php
  grep -n -A6 "function wp_send_json_error\b" wp-includes/functions.php
  grep -n "die(\$message)\|die( \$message )\|die(" wp-includes/functions.php | grep -i "wp_die\|default_wp_die"
  ```
  La cadena es `wp_send_json_success/error` → `wp_send_json` → `wp_die()` →
  `_default_wp_die_handler()` → `die($message)`. Registrar la versión de WP.

- **Prueba 3 — leer el código real y confirmar el orden** (no ejecutar nada):
  - `Admin_Dashboard.php:2099-2102` (acquire + `if false` → send error).
  - `:2103` (`try {`).
  - `:2116`, `:2148`, `:2184` (los tres `wp_send_json_error` **dentro** del `try`).
  - `:2216-2221` (el `wp_send_json_success` **dentro** del `try`).
  - `:2222-2224` (`} finally { release_sync_lock_public(...) }`).

- **Hallazgo C1 (obligatorio registrarlo):** el leak ocurre en **4** sends, no en 3: los tres
  `error` **y** el `success` de `:2216`. El design §2.3/§11 sólo cita los `error`; el gate corrige
  el alcance. T2.4 debe liberar el lock **antes de todo** `wp_send_json_*`.

- **Hallazgo C2 (limitación del harness — obligatorio):** el stub del harness **no** reproduce el
  `die()`. Evidencia:
  ```bash
  grep -n "class Alegra_Test_JSON_Response\|extends .Exception\|function wp_send_json_error\|function wp_send_json_success" scripts/lib/wp-stubs.php
  ```
  Muestra `Alegra_Test_JSON_Response extends \Exception` (`:496`) y los stubs que lo lanzan
  (`:542-553`). Como las **excepciones sí corren `finally`**, mover el release al `finally`
  **seguiría liberando el lock** en el harness ⇒ el test conductual de "lock libre" **no** es un
  prove-it-catch. Opciones para T2.4 (elegir una y anotarla):
  - **(a) Costura de prueba (recomendada):** el stub `wp_send_json_*` invoca, justo **antes** de
    lanzar, un callback opcional `$GLOBALS['alegra_test_json_observer']` si está seteado. El test
    setea el observer para inspeccionar, en el instante del send, si el lock sigue tomado
    (`get_option('alegra_lock_alegra_sync_running_products')`). Con el release en el `finally`, el
    observer ve el lock **tomado** → el test falla; con el release antes del send, lo ve **libre** →
    pasa. Es determinista y conductual.
  - **(b) Source-scan:** aserción estática de que, dentro de `ajax_sync_page`, el offset de
    `release_sync_lock_public` es **menor** que el de cada `wp_send_json_*` del cuerpo.
  - **(c) Subproceso:** correr el handler en un `proc_open` con `wp_send_json_*` que use `exit`
    real. Más caro; no recomendado.

- **Decision tree:**
  - **CONFIRMADO** (esperado): `die()` saltea `finally` → T2.4 implementa cierre+release **antes de
    los 4 sends**; el `finally` queda sólo para excepciones. La rama de test es (a) o (b) por C2.
  - **NO confirmado** (improbable): si el `finally` corriera igual, se mantiene el release
    explícito antes del send (defensivo, sin daño) y se documenta la divergencia con el core.
  - **Harness**: si el equipo no implementa la costura (a), el prove-it-catch de T2.4 debe ser el
    source-scan (b); **no** vale el test conductual actual como prueba.

**Resultado esperado**: nota en `phase0-results.md` que declare: (1) PHP/`die()` saltea `finally`
(CONFIRMADO/NO); (2) `wp_send_json_*` → `wp_die()` → `die()` (CONFIRMADO/NO) + versiones PHP/WP;
(3) el leak afecta **los 4** sends de `ajax_sync_page` (C1); (4) la estrategia de test elegida para
T2.4 (a/b/c) por C2.

**Dependencias**: ninguna. Bloquea **T2.4** (y condiciona el diseño de su test).

**Trazabilidad**: R1 (design §11), REQ-MON-05 (el "Detener" y el lock sano), REQ-IMP-01/02
(cierre mudo), NFR-05 (plugin distribuido), NFR-06 (contrato de respuesta).

**Verificación**: `php -r` de la Prueba 1; `grep` del core de la Prueba 2; lectura de
`Admin_Dashboard.php:2099-2224`. Criterio de aceptación: `phase0-results.md` con
`die_skips_finally=CONFIRMADO`, `wp_send_json_uses_wp_die=CONFIRMADO`, `leak_sends=4`, y
`test_strategy=observer|source-scan|subprocess`.

**Riesgo**: que T2.4 sólo corrija los caminos de `error` y olvide el `success` de `:2216` → el
chunked sigue trabándose en la página 2. Guard: el gate enumera los 4 sends; T2.4 agrega una
aserción que cubra también el `success` (source-scan o observer).

**Estimación**: S (0.5 h).
