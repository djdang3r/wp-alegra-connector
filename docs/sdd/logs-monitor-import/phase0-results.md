# Fase 0 — Resultados de los gates G1–G4

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Fecha | 2026-09-25 |
| Ejecutado por | agente de implementación (Fase 1) |
| Entorno del agente | repo en HEAD + Docker `php:8.3-cli` (no hay `php` en PATH) |
| Alcance | read-only; este archivo es la única salida |

> **Estado global.** G4 **CONFIRMADO**. G2 y G3 tienen su parte *code-inspectable* resuelta y su
> parte *live* pendiente de la tienda del comerciante. **G1 BLOQUEADO**: necesita credenciales
> reales y no puede resolverse desde el repo.

---

## G4 — `wp_send_json_*` termina el request y `die()` no corre `finally`

**Veredicto: CONFIRMADO.**

- `die_skips_finally = CONFIRMADO`
- `wp_send_json_uses_wp_die = CONFIRMADO` (por semántica de WP core; ver Prueba 2)
- `leak_sends = 4`
- `test_strategy = observer` (opción (a) del plan; la costura `$GLOBALS['alegra_test_json_observer']`
  quedó implementada en T1.1a)

### Prueba 1 — `die()` saltea `finally` (ejecutada)

```
$ docker run --rm php:8.3-cli php -v | head -1
PHP 8.3.33 (cli) (built: Sep 19 2026 00:32:23) (NTS)

$ docker run --rm php:8.3-cli php -r 'try { echo "try\n"; exit; } finally { echo "finally\n"; }'
try                       # NO imprime "finally" → die()/exit saltea finally

$ docker run --rm php:8.3-cli php -r 'try { throw new Exception("x"); } catch (Exception $e) {} finally { echo "finally\n"; }'
finally                   # una excepción SÍ corre finally
```

**Versión PHP registrada:** 8.3.33.

### Prueba 2 — `wp_send_json_*` → `wp_die()` → `die()` (razonada; el core de WP no vive en el repo)

El repo no incluye el core de WordPress, así que la cadena se verifica por semántica conocida del
core (la ejecución de la Prueba 1 ya prueba el eslabón crítico: `die()` no corre `finally`). El
comerciante puede confirmarla en su instalación con:

```bash
grep -n -A8 "function wp_send_json\b" wp-includes/functions.php
grep -n -A6 "function wp_send_json_success\b" wp-includes/functions.php
grep -n -A6 "function wp_send_json_error\b" wp-includes/functions.php
grep -n "die(\$message)" wp-includes/functions.php
```

Cadena: `wp_send_json_success/error` → `wp_send_json` → `wp_die()` →
`_default_wp_die_handler()` → `die($message)`. **Versión WP de la tienda: pendiente de registrar.**

### Prueba 3 — lectura del código real (confirmada)

`admin/Admin/Admin_Dashboard.php` (`ajax_sync_page`):
- `:2099-2102` — `acquire_sync_lock_public()` + `if false → wp_send_json_error`.
- `:2103` — `try {`.
- `:2116`, `:2148`, `:2184` — tres `wp_send_json_error` **dentro** del `try`.
- `:2216-2221` — `wp_send_json_success` **también dentro** del `try`.
- `:2222-2224` — `} finally { release_sync_lock_public(...) }`.

### Hallazgo C1 (registrado)

El leak del lock ocurre en **4** sends, no en 3: los tres `error` de `:2116/:2148/:2184` **y** el
`success` de `:2216-2221`. En el camino de **éxito** la página 1 responde OK y deja el lock tomado;
la página 2 encuentra el lock y muere mudo (síntoma del comerciante). T2.4 debe cerrar el run y
liberar el lock **antes de todo** `wp_send_json_*`, no sólo de los `error`.

### Hallazgo C2 (registrado)

El stub `wp_send_json_*` **lanza** `Alegra_Test_JSON_Response` (`scripts/lib/wp-stubs.php`), y las
excepciones **sí** corren `finally`. Por eso el test conductual de "lock libre" no era
prove-it-catch. **Resuelto en T1.1a**: se implementó la costura
`$GLOBALS['alegra_test_json_observer']`, invocada justo antes de lanzar, para fotografiar el lock
en el instante exacto del `die()`. Estrategia elegida: **(a) observer**.

---

## G2 — Fork de tombstones (`bulk_wc`) — **PARTE LIVE PENDIENTE**

**Veredicto code-level: CONFIRMADO.** La política recomendada es `ignore_all` (checkbox tildado),
alineada al design §5.2/D4. La forma real de `$_REQUEST` y el conteo por `reason` necesitan la
tienda.

### Código verificado (HEAD)

- `includes/Tombstone_Manager.php:54` — `on_post_delete()` escribe **siempre** `'reason' => 'manual_wc'`.
  (`:98` tiene otro insert path con `$data['reason'] ?? 'manual_wc'`.)
- `includes/Schema.php:159` — `reason VARCHAR(50) NOT NULL DEFAULT 'manual_wc'`, **sin enum** ⇒
  `bulk_wc` entra sin migración.
- `alegra-connector.php:214` engancha `before_delete_post`; **mover a papelera NO dispara ese hook**,
  sólo el borrado permanente. Si el comerciante borró vía papelera + "Vaciar papelera", el flujo fue
  `delete_all` (submit `name="delete_all"`/`delete_all2`), no `action=delete` con `post[]`.

### Forma esperada de `$_REQUEST` (según WP core; a confirmar en la tienda)

| Flujo | Forma esperada |
|---|---|
| Borrado individual | `action=delete&post=123&_wpnonce=…` (`post` escalar) |
| Bulk (select superior) | `action=delete&post[]=1&post[]=2&_wpnonce=…` (`post` array, count>1) |
| Bulk (select inferior) | `action=-1&action2=delete&post[]=…` (fallback a `action2`) |
| Vaciar papelera | `delete_all=Empty+Trash&post_status=trash` (submit `name="delete_all"`; `action` en `-1`) |

### Pendiente del comerciante (live)

1. `wp db query "SELECT reason, COUNT(*) AS n FROM wp_alegra_tombstones WHERE alegra_type='item' GROUP BY reason;"`
2. `wp db query "SELECT COUNT(*) AS vivos FROM wp_alegra_tombstones WHERE alegra_type='item' AND resurrected_at IS NULL;"`
3. `wp db query "SELECT alegra_id, deleted_at, deleted_by, reason FROM wp_alegra_tombstones WHERE alegra_type='item' ORDER BY deleted_at DESC LIMIT 20;"`
4. Responder: (a) ¿borrado masivo o ítem por ítem? (b) ¿algún borrado a mano debe permanecer
   borrado? (c) ¿papelera o borrado permanente?
5. Capturar el POST a `edit.php?post_type=product` en DevTools para los 4 flujos.

### Default fijado (recomendación, no bloquea T4)

`default_checkbox = ignore_all` (tildado → recrea **todo**, incluidos los `manual_wc`). Razón: el
workflow central del comerciante es "borrar todo → reimportar desde cero"; si el heurístico
`bulk_wc` falla (REST/WP-CLI/otro plugin), con `ignore_bulk` por default el catálogo **no** se
recrearía. El botón normal "Traer desde Alegra" es **siempre** `respect`. `alegra_deleted` **nunca**
se resucita. Si el comerciante pide preservar borrados manuales y la forma capturada es limpia,
destildar = `ignore_bulk`.

**Bloquea:** T4.3a, T4.3b, T4.4 (cierre de rama; la implementación base es idéntica en ambas ramas).

---

## G3 — Default de `sync_products` — **PARTE LIVE PENDIENTE**

**Veredicto code-level: Rama A (recomendada).** La UI y el runtime **ya** usan `false`; no hay
migración.

### Código verificado (HEAD)

| Punto | Línea | Valor |
|---|---|---|
| `$defaults` | `alegra-connector.php:424` | `'alegra_connector_sync_products' => false` |
| Lectura del cron | `includes/Sync/Controller.php:171` | `get_option('alegra_connector_sync_products', false)` |
| UI | `templates/admin-settings.php:78` | `checked(get_option('alegra_connector_sync_products', false))` |

Los tres coinciden en `false`. La premisa de `config-gates` T2.5 ("`true` → `false`") está **stale**
sobre HEAD.

### Pendiente del comerciante (live)

```bash
wp option get alegra_connector_sync_products
wp option get alegra_connector_sync_products --format=json
```

Interpretar: ausente / `false` / `true` / `1` / `0`. Y coordinar con `config-gates`:

```bash
grep -n "sync_products" docs/sdd/config-gates/tasks.md
grep -n "sync_products" docs/sdd/config-gates/spec.md
```

### Rama fijada (recomendación)

`rama = A` — el default queda `false`, sin migración. El cron no importa productos salvo toggle; el
comerciante usa el botón manual o webhooks. REQ-CON-02 se cierra como "ya alineado". T6.5 agrega
sólo la aserción de regresión. **No** se recomienda la Rama B (R8).

**Bloquea:** T6.5.

---

## G1 — Host real del CDN de imágenes — **BLOQUEADO (necesita la tienda)**

**Veredicto: BLOQUEADO.** El host real de `images[].url` no es observable desde el repo. Requiere
una llamada autenticada a la API de Alegra con las credenciales del comerciante.

### Código verificado (HEAD)

- `includes/Sync/Products.php:2125-2130` — `allowed_image_hosts()` devuelve `['alegra.com']`
  (más el filtro `alegra_connector_allowed_image_hosts`).
- `:2136-2158` — `is_allowed_image_url()` exige `https` y match de host/subdominio.
- `:2170-2176` — si falla, `download_and_attach_image()` **bloquea** y sólo loguea.

### Comando exacto que debe correr el comerciante (vía (b): payload de `GET /items`)

```bash
wp eval '$c = new \Alegra\Connector\API\Client(new \Alegra\Connector\Logger\Logger()); $r = $c->get("/items", ["limit" => 3, "mode" => "advanced"]); foreach ((array) $r as $it) { foreach ((array) ($it["images"] ?? []) as $img) { echo ($img["url"] ?? "") . "\n"; } }'
```

Si el `Client` no está autoloaded en `wp eval`, forzar:

```bash
wp eval 'require_once ABSPATH . "wp-content/plugins/alegra-connector/alegra-connector.php"; $c = new \Alegra\Connector\API\Client(new \Alegra\Connector\Logger\Logger()); $r = $c->get("/items", ["limit" => 3, "mode" => "advanced"]); foreach ((array) $r as $it) { foreach ((array) ($it["images"] ?? []) as $img) { echo ($img["url"] ?? "") . "\n"; } }'
```

Vía (a): adjuntos ya importados:

```bash
wp db query "SELECT meta_value FROM wp_postmeta WHERE meta_key='_alegra_image_url' AND meta_value <> '' ORDER BY meta_id DESC LIMIT 5;"
```

Matcher por cada URL capturada (**≥3 ítems distintos**):

```bash
wp eval '$u = "https://<host-observado>/<archivo>"; var_dump(parse_url($u, PHP_URL_HOST)); var_dump(\Alegra\Connector\Sync\Products::is_allowed_image_url($u));'
```

### Criterio de aceptación (a completar por el comerciante)

El archivo debe quedar con, por cada URL: `host=…`, `scheme=https|http`, `is_allowed=true|false`, y
una única `rama=A|B|C|D`:

- **A** — host `*.alegra.com` + `https` → no se toca la allowlist (falsa alarma). T5.3 sólo agrega
  la opción configurable.
- **B** — host fuera de `*.alegra.com` + `https` → el comerciante carga el host por UI
  (`alegra_connector_allowed_image_hosts_extra`), **nunca `*`**; no se hardcodea en el código.
- **C** — `http://` → escalar; una allowlist no puede habilitar http (NFR-07).
- **D** — sin URL observable → INCONCLUSO; T5.3 se implementa igual con la opción configurable.

**Bloquea:** T5.3a, T5.3b.

---

## Resumen de gates

| Gate | Estado | Bloquea | Acción del comerciante |
|---|---|---|---|
| G1 | **BLOQUEADO** | T5.3a/b | correr el `wp eval` de arriba (≥3 ítems) |
| G2 | code-level CONFIRMADO; live pendiente | T4.3a/b, T4.4 | 3 SQL + preguntas + captura de Network |
| G3 | code-level Rama A; live pendiente | T6.5 | `wp option get alegra_connector_sync_products` |
| G4 | **CONFIRMADO** | T2.4 | ninguna (test_strategy=observer) |
