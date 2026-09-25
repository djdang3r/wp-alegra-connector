# Fase 3 — Chunked con presupuesto propio, progreso y fail-loud (central)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Fase | 3 de 8 — rediseño del chunked (D3) |
| Tareas | T3.1 · T3.2 · T3.3 · T3.4 |
| Depende de | **Fase 2** (T2.3 estado con `run_id`/`start`/`offset`; T2.4 cierre+release antes del send) |
| DoD de la fase | REQ-IMP-01/03/04 · REQ-RES-04 · NFR-01 · NFR-04 · NFR-05 verdes; el chunked no muere por fatal y no pierde ítems entre páginas; `bash scripts/exec-test.sh` verde |
| Documentos base | `design.md` §4 (D3) · §6.2 (imágenes) · §8 (payload) · `spec.md` REQ-IMP-01..04 |
| Versión objetivo | 2.5.0 |

> **Convención de IDs de test (corrección).** Los tests nuevos del harness son `T28.{fase}{n}`:
> Fase 3 usa `T28.31`–`T28.311`. No colisionan con los `T1..T27` existentes. (`T28.312` se movió a
> Fase 5 como `T28.59`: `blocked` es de T5.2a y un test de Fase 3 no puede depender de una fase
> posterior; ver T3.4 §Verificación.)

> **La decisión central (D3 §4.1).** El chunked deja de depender de `@set_time_limit(60)`
> (`Admin_Dashboard.php:2105`) como defensa y pasa a **presupuesto wall-clock propio por página**
> (opción `alegra_connector_chunked_page_budget`, default **20 s**). El corte es **antes de cada
> ítem** y la reanudación es por `start`+`offset`, no por conteo fijo de ítems. El presupuesto de
> 240 s de `Products::import_from_alegra` (`Products.php:1270`) **no aplica** al chunked: sigue
> gobernando cron e Importar.

> **Contrato compartido Fase 2 ↔ Fase 3 (B3 — dependencia circular, cerrada acá).** `T2.4`
> (Fase 2) escribe el esqueleto de `ajax_sync_page` usando `$tp`/`$processed`/`$done`/`$pct`
> (definidos por **T3.1.c**) y llama `Sync\Products::set_deadline()`/`reset_image_stats()`
> (definidos por **T3.2.b**). Con el orden de `tasks.md` (Fase 2 antes que Fase 3) y el gate
> "Fase 2 verde", `T2.4` no compila ni pasa: variables indefinidas y
> `Call to undefined method Products::reset_image_stats()`. **`T2.4` + `T3.1` + `T3.2.b` se
> implementan y shippean como UN solo commit** (el contrato compartido de `ajax_sync_page`): no hay
> gate de Fase 2 verde antes de Fase 3. `T2.4` consume las definiciones de `T3.1.c`/`T3.2.b`, y esas
> tareas declaran a `T2.4` como su consumidor directo. El orden maestro de `tasks.md` lo corrige otro
> agente; acá queda registrada la regla de build.

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

| # | Claim (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C5 (de Fase 2) | `design.md` §4.1: el estado pasa de `page` a `start`+`offset` | Los tests `T21.5` (`exec-test.php:3523-3541`) y `T-RB-5` (`:5257-5274`) siembran `page` y no `start`/`offset`. | T3.1 lee `start`/`offset` con **default retrocompatible** (ver T3.1 §Desarrollo). No hace falta reescribir esos tests. |
| C6 | `tasks.md` T3.1: "la suma importados/actualizados/skipped/errores iguala el total, sin duplicados ni salteados" | El loop actual cuenta `skipped` por el filtro `variantParent` (`:2130-2133`) y por variantes (`:2125`), pero **no** incrementa nada al saltear por `offset`. Si el skip por `offset` contara, duplicaría. | El salteo por `offset` debe ser un `continue` **sin** tocar contadores. La igualdad se mide sobre los ítems **nuevos** procesados en la corrida. |
| C7 | `tasks.md` T3.2 cita `Products.php:1248` (`import_from_alegra`), `:1318-1327` (progreso; llamada `:1319`), `:1353-1356` (stop), `:2365` (`import_single_item_public`), `:1404` (`import_single_item_from_alegra`) | **Confirmado.** Además, el loop de `import_from_alegra` (`:1357-1360`) trata cualquier retorno que no sea `true`/`'updated'` como error; al agregar el centinela `'stopped'` hay que manejarlo **también** ahí (no solo en `ajax_sync_page`). | T3.2 cubre los **dos** loops. |
| C8 | `design.md` §4.2: deadline antes de cada imagen + contador `deferred` | `import_product_images` (`:2018`) llama a `download_and_attach_image` (`:2068`, `:2090`) y a `import_product_image` (`:2041`, `:2370`); el `download_url` vive en `:2220` y `:2386`. No hay deadline ni contador. | T3.2 introduce el deadline + `deferred`; T5.2a (Fase 5) completa los incrementos `blocked/download/sideload/ok`. Coordinación de orden: **T3.2 va antes** (Fase 3 < Fase 5), así que T3.2 declara el acumulador y T5.2a lo completa. |
| C9 | `tasks.md` T3.4: "`assertArrayHasKey('message', $d)` en ambas ramas" | El harness lanza en `wp_send_json_*` (`wp-stubs.php:542-553`); el payload capturado es `$resp->payload`, no `$d`. | La aserción real es `assertArrayHasKey('message', $resp->payload)`. |
| C10 | `tasks.md` T3.3: "el cliente deja de llevar `page`" | El request de página (`admin.js:232-234`) **ya no envía** `page`; solo lo usa como fallback de `percent` (`:240`). | El cambio es quitar el parámetro de `processPage` y la recursión, no el request. |

**Confirmaciones (no requieren corrección).** `Admin_Dashboard.php:2105` (`@set_time_limit(60)`),
`:2138` (`import_single_item_public` sin `run_id`), `:2144`/`:2181`/`:2193` (`$is_last`),
`:2216-2221` (send actual); `Products.php:1270` (budget 240), `:1298`/`:1353` (stop),
`:1304-1310` (pausa), `:1373-1381` (cursor), `:1385-1393` (resumen final), `:2220`/`:2235`/`:2386`/`:2397`
(descargas/sideload); `admin.js:220`,`:238`,`:278` (ramas mudas), `:699-774` (precedente
"Facturar pendientes"); `Admin_Dashboard.php:3450-3526` (`ajax_sync_pending_page`, el modelo de
progreso); `templates/admin-settings.php:307-315` (Avanzado, junto al budget de importación).

---

## T3.1 — Opción `chunked_page_budget` + deadline con `offset` en `ajax_sync_page`

**Objetivo**: que una página con imágenes pesadas corte **antes** del límite con un presupuesto
propio y reanude sin perder ni duplicar ítems, eliminando el fatal 500.

**Descripción técnica**: hoy la página hace `@set_time_limit(60)` (`:2105`) y procesa los 30 ítems
enteros; si el total supera los 60 s, PHP muere a mitad de página (HTTP 500) y el JS cierra mudo.
El design §4.1 elige **(c) presupuesto wall-clock adaptativo**: `$deadline = microtime(true)+$budget`,
corte antes de cada ítem, estado `start`+`offset`, y re-fetch de la misma página salteando `offset`
(costo: **1 llamada extra por pausa**, no por ítem — NFR-04). Cubre REQ-IMP-03, NFR-01/05.

**Desarrollo técnico**:

### T3.1.a — Opción `alegra_connector_chunked_page_budget`

| Punto | Archivo | Cambio |
|---|---|---|
| Registro | `admin/Admin/Admin_Dashboard.php:537-540` (junto a `import_time_budget`) | `register_setting('alegra_connector_settings', 'alegra_connector_chunked_page_budget', ['sanitize_callback' => fn($v) => max(10, min(40, (int) $v)), 'default' => 20]);` |
| `$defaults` | `alegra-connector.php:409-446` | `'alegra_connector_chunked_page_budget' => 20,` |
| `$non_autoload` | `alegra-connector.php:452-470` | `'alegra_connector_chunked_page_budget',` |
| UI | `templates/admin-settings.php` tras `:310` (bloque "Import time budget") | Fila nueva con `<input type="number" name="alegra_connector_chunked_page_budget" value="<?php echo esc_attr(get_option('alegra_connector_chunked_page_budget',20));?>" min="10" max="40">`, description: "Segundos de trabajo por página del botón Traer desde Alegra. Al agotarse, la página se pausa y continúa en la siguiente." |
| Uninstall | `uninstall.php` (junto a `:103-104`) | `delete_option('alegra_connector_chunked_page_budget');` |

Default **20**, rango **10–40**. El clamp `max(10, min(40, (int)$v))` replica el patrón de
`import_time_budget` (`:538`) e `import_max_pages` (`:542`).

### T3.1.b — Reestructurar el loop de `products` (`Admin_Dashboard.php:2108-2144`)

ANTES:

```php
        if ($type === 'products') {
            $start = ($page - 1) * $per_page;
            $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
            $api_params = ['start' => $start, 'limit' => $per_page, 'mode' => 'advanced']
                + $this->build_item_filter_params($filters, true);
            $items = $this->api->get('/items', $api_params);
            if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()]); }

            $products_sync = new Sync\Products($this->api, $this->logger);
            foreach ($items as $item) {
                $alegra_id = (string) ($item['id'] ?? '');
                $item_type = $item['type'] ?? 'simple';
                if ($item_type === 'variant') { continue; }
                if (($filters['type'] ?? '') === 'variantParent' && $item_type !== 'variantParent') {
                    $state['skipped'] = ($state['skipped'] ?? 0) + 1;
                    continue;
                }
                $r = $products_sync->import_single_item_public($item);
                if ($r === true) { $state['imported']++; }
                elseif ($r === 'updated') { $state['updated']++; }
                elseif ($r === 'skipped') { continue; }
                else { $state['errors']++; }
            }
            $is_last = count($items) < $per_page;
        }
```

DESPUÉS:

```php
        if ($type === 'products') {
            $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
            // C5: `$start`/`$offset`/`$per_page` ya vienen resueltos arriba desde
            // el estado (con default retrocompatible). El fetch trae la página
            // COMPLETA y el loop saltea los primeros $offset (reanudación honesta).
            $api_params = ['start' => $start, 'limit' => $per_page, 'mode' => 'advanced']
                + $this->build_item_filter_params($filters, true);
            $items = $this->api->get('/items', $api_params);
            if (is_wp_error($items)) { /* cierre T2.4 */ }

            // D3 §4.1: presupuesto propio por página. NO es set_time_limit; el
            // backstop de 60 s (:2105) solo cubre el margen.
            $budget   = max(10, min(40, (int) get_option('alegra_connector_chunked_page_budget', 20)));
            $deadline = microtime(true) + $budget;
            Sync\Products::set_deadline($deadline);        // C8/T3.2.b: deadline para imágenes
            Sync\Products::reset_image_stats();            // C8/T3.2.b (dueño)

            $is_last = count($items) < $per_page;          // plenitud medida sobre la página completa
            $paused  = false;
            $index   = -1;
            $products_sync = new Sync\Products($this->api, $this->logger);

            foreach ($items as $item) {
                $index++;
                if ($index < $offset) { continue; }        // C6: saltear sin tocar contadores
                if (microtime(true) >= $deadline) { $paused = true; break; }  // ANTES de cada ítem

                $item_type = $item['type'] ?? 'simple';
                if ($item_type === 'variant') { continue; }
                if (($filters['type'] ?? '') === 'variantParent' && $item_type !== 'variantParent') {
                    $state['skipped'] = ($state['skipped'] ?? 0) + 1;
                    continue;
                }
                $r = $products_sync->import_single_item_public($item, $run_id);   // T3.2: run_id + 'stopped'
                if ($r === 'stopped') { $paused = true; break; }
                if ($r === true) { $state['imported']++; }
                elseif ($r === 'updated') { $state['updated']++; }
                elseif ($r === 'skipped') { $state['skipped'] = ($state['skipped'] ?? 0) + 1; }  // D-C: contar
                else { $state['errors']++; }
            }
            Sync\Products::clear_deadline();               // C8/T3.2

            // Reanudación: pausado ⇒ persistir offset sin avanzar start; completo ⇒
            // avanzar start, resetear offset y persistir el cursor compartido.
            if ($paused) {
                $state['offset'] = max(0, $index);         // siguiente índice no procesado
            } else {
                $state['start']  = $start + $per_page;
                $state['offset'] = 0;
                update_option('alegra_connector_products_import_cursor', $state['start'], false);
            }
        }
```

Detalles que importan:
- **D-C / NFR-01 — los dos `continue` NO son iguales.** El `continue` por `$offset` (reanudación)
  va **antes** de todo y **no** incrementa contadores (C6): esos ítems ya se contaron en una página
  previa y contarlos otra vez duplicaría. En cambio, el `'skipped'` que devuelve el importador
  (tombstone, variante, kill-switch/cancel) **sí** se cuenta como `skipped`: es un ítem de esta
  página que no se importó, y NFR-01 (`imported+updated+skipped+errors == total`) lo exige.
- El chequeo de deadline va **después** de incrementar `$index` y **antes** de procesar: si corta,
  el ítem `$index` queda sin procesar y `offset` = `$index`.
- El presupuesto se evalúa **una vez por ítem**, nunca dentro del ítem (las imágenes usan el mismo
  deadline vía el static — T3.2.b/C8).
- `$is_last` se mide sobre la página completa (no sobre `array_slice`), para no confundir "última
  página" con "pausa".

**D-C — fix del conteo de `skipped` (BEFORE/AFTER exacto).** `import_single_item_from_alegra`
devuelve `'skipped'` para variantes (`Products.php:1423-1425`) y para tombstones respetados
(`:1436`) — justo el escenario del comerciante ("borré todo, reimporté y no aparecieron"). La rama
original lo descartaba sin contarlo, así que la suma reportada quedaba corta y el test de NFR-01
(`fase-3:206`) fallaba.

ANTES (rama original):
```php
                elseif ($r === 'skipped') { continue; }
```

DESPUÉS:
```php
                elseif ($r === 'skipped') { $state['skipped'] = ($state['skipped'] ?? 0) + 1; }
```

El `continue` por `offset` (línea `if ($index < $offset) { continue; }`) queda **sin tocar**: ese
ítem pertenece a una página previa (ya contado), mientras que el `'skipped'` es un ítem **nuevo** de
esta página que no se importó. `$processed` (`T3.1.c`) ya incluye `skipped`, así que con este fix la
identidad de NFR-01 se sostiene.

### T3.1.c — `$done` y `percent` con `offset`

Reemplazar el cálculo de `$done`/`$pct` (`:2203-2215`). ANTES:

```php
        $processed = $state['imported'] + $state['updated'] + $state['errors'] + ($state['skipped'] ?? 0);
        $tp = (int)($state['total_pages'] ?? 0);
        $done = $is_last || ($tp > 0 && $page >= $tp) || empty($items);
        ...
        $pct = $tp > 0 ? min(100, round(($page / $tp) * 100)) : 0;
```

DESPUÉS (dentro del bloque products ya se setearon `$paused`/`$state['start']`; para
customers/categories `$paused=false` y `$state['start']` se avanza igual):

```php
        $processed = $state['imported'] + $state['updated'] + $state['errors'] + ($state['skipped'] ?? 0);
        $tp        = (int) ($state['total_pages'] ?? 0);
        $total_items = (int) ($state['total_items'] ?? 0);
        $next_start  = (int) ($state['start'] ?? 0);
        $done = !$paused && ($is_last || ($tp > 0 && $next_start >= $tp * $per_page) || empty($items));
        // percent por ítems procesados (no por página): con offset, page/tp miente.
        $pct = $total_items > 0
            ? min(100, (int) round(($processed / $total_items) * 100))
            : ($tp > 0 ? min(100, (int) round((($next_start) / ($tp * $per_page)) * 100)) : 0);
```

`$page` se mantiene solo para el payload (`page = floor($next_start/$per_page)+1`), no para el corte.

> **Consumidor directo (B3).** `$tp`/`$processed`/`$done`/`$pct` los consume el esqueleto de
> `ajax_sync_page` de **T2.4** (Fase 2). Por eso `T2.4` + `T3.1` + `T3.2.b` se shippean en un mismo
> commit (contrato compartido; ver nota al inicio de la fase): no hay gate de Fase 2 verde antes de
> Fase 3. Estas variables no existen hasta que T3.1.c aterriza.

**Orden de operaciones**: fetch → deadline → loop (offset → deadline → import) → `done`/`percent` →
persistir estado → (T2.4) heartbeat/progreso → cierre/release → send.

**Resultado esperado (aceptación verificable)**:
- Con `budget` bajo (10) y N ítems que exceden el presupuesto, la respuesta trae `paused:true` con
  `offset>0`; el segundo request reanuda desde `start+offset` sin repetir.
- Al completar, `cursor` borrado y `done:true`.
- La suma de `imported+updated+skipped+errors` de todas las páginas iguala el total, sin duplicados
  ni salteados (NFR-01).
- El fuente de `ajax_sync_page` contiene `microtime(true) >= $deadline`.

**Dependencias**: Fase 2 (T2.3/T2.4). T3.2.a (`import_single_item_public($item,$run_id)`) + T3.2.b
(`set_deadline`/`reset_image_stats`). H1 de Fase 2. **Build (B3): `T2.4` + `T3.1` + `T3.2.b` en un
solo commit** (contrato compartido; ver nota al inicio de la fase).

**Trazabilidad**: REQ-IMP-03, REQ-RES-04, NFR-01, NFR-05.

**Verificación**:
1. Test `T28.31 pausa con offset`: `update_option('alegra_connector_chunked_page_budget', 10, false);`
   sembrar 40 ítems y un deadline imposible (p. ej. mock lento o `microtime` — ver nota) →
   `assertSame(true, $resp->payload['paused'])`, `assertTrue($resp->payload['offset'] > 0)`.
   **Nota harness**: el deadline es wall-clock; para forzar el corte de forma determinista, el test
   puede setear `Products::set_deadline(microtime(true) - 1)` directamente (método público de T3.2.b)
   y llamar al handler; como el handler recalcula el deadline, el test alternativo es inyectar el
   budget en `0` (clamp lo lleva a 10) y usar un ítem con `usleep` en el mock. Si el mock no permite
   demora, agregar un hook `alegra_test_item_delay_ms` en `alegra-mock.php` (harness).
2. Test `T28.32 reanuda sin repetir`: primer request pausado con `offset=k`; segundo request con el
   mismo `start` → el mock registra `GET /items?start=<start>` y el ítem `k` es el primero importado
   (assert sobre `alegra_mock_requests`).
3. Test `T28.33 completa y borra cursor`: `assertSame(true, $resp->payload['done'])` y
   `assertFalse(get_option('alegra_connector_products_import_cursor', false))`.
4. **Prove-it-catches**: quitar el chequeo de deadline → (1) falla (procesa todo y no pausa).
5. `bash scripts/exec-test.sh` verde.

**Riesgo**: R4 de `tasks.md` (re-fetch duplica/saltea). **Guarda**: `offset` = índice absoluto no
procesado; test (2) + la igualdad de la suma (1). Riesgo de degradar catálogos chicos: mismo UX que
"Facturar pendientes", páginas de 30.

**Estimación**: L (4 h).

---

## T3.2 — `Products`: heartbeat/progreso + `run_id` en el import + centinela `'stopped'` + deadline de imágenes

**Objetivo**: que el importador de productos reporte progreso al Monitor, propague el `run_id` para
que "Detener" corte a mitad de página, distinga **pausado** de **completado**, y que las imágenes
respeten el deadline de la página reportando las diferidas.

**Descripción técnica**: hoy `Products::import_from_alegra` solo setea el transient
`alegra_sync_progress` (`:1319`, `:1385`) y consulta `should_stop` cuando `$run_id>0`
(`:1298`,`:1353`); `import_single_item_public` (`:2365`) no acepta `run_id`, así que el chunked no
puede frenarse por ítem (REQ-MON-05). El resumen final (`:1385-1393`) marca `done=>true` siempre,
sin distinguir pausa (REQ-RES-04). Cubre D1 §2.4/§2.5 + D3 §4.2. **Split interno** por tamaño:
T3.2.a (run_id/stop/progreso) y T3.2.b (imágenes/deadline).

### T3.2.a — `run_id` + `'stopped'` + heartbeat/progreso

**Archivos:** `includes/Sync/Products.php`.

1. **Firmas** (`:1404`, `:2365`):

```php
    private function import_single_item_from_alegra(array $item, int $run_id = 0): bool|string
    public function import_single_item_public(array $item, int $run_id = 0): bool|string
    {
        return $this->import_single_item_from_alegra($item, $run_id);
    }
```

2. **Centinela `'stopped'`** — en `import_single_item_from_alegra`, **después** del guard de
   kill-switch/cancelación (`:1410-1415`) y **antes** del tombstone (`:1429`):

```php
        // REQ-MON-05: parada pedida desde el Monitor. A diferencia de kill
        // switch/cancel (que devuelven 'skipped'), 'stopped' hace que el loop
        // corte y cuente el ítem como no-procesado.
        if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
            $this->logger->info('Item import stopped by user', ['alegra_id' => (string) ($item['id'] ?? '')]);
            return 'stopped';
        }
```

3. **Manejar `'stopped'` en los DOS loops**:
   - `ajax_sync_page` (T3.1): `if ($r === 'stopped') { $paused = true; break; }` (ya especificado).
   - `Products::import_from_alegra` (`:1357-1360`): ANTES:

```php
                $r = $this->import_single_item_from_alegra($item);
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
```

DESPUÉS:

```php
                $r = $this->import_single_item_from_alegra($item, $run_id);
                if ($r === 'stopped') { $result['paused'] = true; break 2; }
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
```

4. **Heartbeat + progreso** junto al `set_transient('alegra_sync_progress', …)` de `:1318-1327`,
   **solo si `$run_id > 0`**:

```php
            if ($pages_done === 0 || $pages_done % 5 === 0) {
                set_transient('alegra_sync_progress', [ /* …igual que hoy… */ ], 600);
                if ($run_id > 0) {
                    \Alegra\Connector\Heartbeat::set($run_id, [
                        'step' => 'products',
                        'message' => sprintf(__('Procesando productos... Página %d', 'alegra-connector'), $current_page),
                    ]);
                    \Alegra\Connector\Runs::update_progress(
                        $run_id,
                        $result['imported'] + $result['updated'] + $result['errors'],
                        0
                    );
                }
            }
```

Por página/etapa, **nunca** por ítem (NFR-04). Nota: `Runs::update_progress` filtra
`status='running'` (`Runs.php:84`); si el run ya cerró, es no-op (deseado).

5. **Resumen final distingue pausa** (`:1384-1393`). ANTES: `'done' => true` incondicional.
   DESPUÉS:

```php
        set_transient('alegra_sync_progress', [
            'type' => 'products',
            'current_page' => $current_page,
            'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'done' => !$result['paused'],
            'paused' => $result['paused'],
            'cursor' => $start,
            'message' => $result['paused']
                ? sprintf(__('Pausado en el ítem %d; continúa en la próxima ejecución', 'alegra-connector'), $start)
                : sprintf(__('Completado: %d importados, %d actualizados', 'alegra-connector'), $result['imported'], $result['updated']),
        ], 60);
```

`$result['paused']` ya se setea en `:1305`. `$start` es el cursor al salir del loop.

### T3.2.b — Deadline de imágenes + contador `deferred` (design §4.2 / C8)

**Archivo:** `includes/Sync/Products.php`.

1. **Estado estático** (nuevo, junto a las propiedades de la clase):

```php
    private static float $deadline = 0.0;
    private static array $image_stats = ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0];

    public static function set_deadline(float $deadline): void { self::$deadline = $deadline; }
    public static function clear_deadline(): void { self::$deadline = 0.0; }
    public static function deadline_exhausted(): bool { return self::$deadline > 0.0 && microtime(true) >= self::$deadline; }
    public static function reset_image_stats(): void { self::$image_stats = ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0]; }
    public static function image_stats(): array
    {
        $s = self::$image_stats;
        $s['failed'] = $s['blocked'] + $s['download'] + $s['sideload'];
        return $s;
    }
```

> **Dueño único del acumulador (cierra el conflicto T3.2.b vs T5.2a).** **T3.2.b es el ÚNICO dueño**
> de `$image_stats`, `reset_image_stats()` e `image_stats()`. T5.2a (Fase 5) **sólo agrega los
> incrementos** `blocked`/`download`/`sideload`/`ok` en las ramas de `download_and_attach_image`
> (`:2170-2176`, `:2221-2228`, `:2236-2244`) y `import_product_image` (`:2374-2380`, `:2387-2389`,
> `:2400-2402`); **no redeclara** la propiedad ni los accessors. El `deferred++` también es de T3.2.b
> (guard de deadline, punto 2), no de T5.2a.

> **Consumidor directo (B3).** `set_deadline()`/`reset_image_stats()` los llama el bloque `products`
> del esqueleto de `ajax_sync_page` de **T2.4** (Fase 2) **antes** del loop. Por eso `T2.4` +
> `T3.2.b` van en el mismo commit (contrato compartido): sin T3.2.b, T2.4 llama a métodos que no
> existen. Y por eso **T3.2.b debe ir ANTES de T3.1.b** (T3.1.b llama estos accessors), no en
> paralelo.

2. **Guard antes de cada descarga.** En `download_and_attach_image` (`:2166`), **después** del
   allowlist (`:2170-2176`) y **antes** de `download_url` (`:2220`):

```php
        if (self::deadline_exhausted()) {
            self::$image_stats['deferred']++;
            $this->logger->info('Image download deferred to next run (page budget exhausted)', [
                'product_id' => $product_id,
                'url' => $image_url,
            ]);
            return 0;
        }
```

3. **Guard antes de cada imagen** en `import_product_images` (`:2018`): antes de las llamadas de
   `:2041` (modo `favorite`), `:2068` (modo `all`/`except_favorite`) y `:2090` (fallback), chequear
   `if (self::deadline_exhausted()) { break; }` (o `return;` en `favorite`). El ítem **igual se
   importa** (datos + las imágenes que entraron); las no descargadas quedan como `deferred` y el
   reimport futuro las reintenta (dedup por hash `:2183-2214`, REQ-IMG-01).

4. **Reset por página.** `ajax_sync_page` ya llama `Sync\Products::reset_image_stats()` (T3.1) y
   mergea `$state['images']` (T5.2b). `import_from_alegra` resetea al inicio y devuelve
   `$result['images'] = self::image_stats()` (T5.2b).

**Resultado esperado (aceptación verificable)**:
- Un run chunked en curso muestra "Procesando productos... Página N" e `items_done`/`total_items`
  en el Monitor (REQ-MON-02).
- "Detener" corta a mitad de página: el siguiente `import_single_item_public($item,$run_id)` devuelve
  `'stopped'` y el run queda `cancelled`.
- Una pausa reporta `paused:true` + `cursor`; una corrida completa no (REQ-RES-04).
- Con deadline agotado, las imágenes no descargadas suman a `deferred` y **no** a `failed`.

**Dependencias**: Fase 2 (T2.4 `resume`, consumidor directo de `set_deadline`/`reset_image_stats`).
T5.2a (incrementos restantes del acumulador). **Debe ir ANTES de T3.1.b** (T3.1.b llama
`set_deadline`/`reset_image_stats`), no en paralelo.

**Trazabilidad**: REQ-MON-02, REQ-MON-05, REQ-LOG-02, REQ-RES-01, REQ-RES-04, REQ-IMG-01,
REQ-IMG-03 (parcial), NFR-04.

**Verificación**:
1. Test `T28.34 stopped`: `Runs::request_stop(7);` → `assertSame('stopped', make_products()->import_single_item_public($item, 7))`.
2. Test `T28.35 run_id en el log`: con `$run_id=7`, el archivo de log contiene `"run_id":7`.
3. Test `T28.36 resumen paused`: forzar `$result['paused']` (deadline agotado) → el transient
   `alegra_sync_progress` trae `'paused'=>true` y `'done'=>false`, con `cursor>0`.
4. Test `T28.37 imagen diferida`: `Products::set_deadline(microtime(true) - 1);` + importar un ítem con
   imágenes → `Products::image_stats()['deferred'] > 0` y `['failed'] === 0`.
5. **Prove-it-catches**: quitar el chequeo de `should_stop` por ítem → (1) falla (devuelve `true`/
   `'updated'`). Quitar el guard de deadline → (4) falla (`deferred === 0`).
6. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el centinela `'stopped'` se cuente como error en algún caller. **Guarda**: cubrir
los **dos** loops (T3.2.a punto 3) y el test (1). Riesgo de imágenes parciales (R4 de `tasks.md`):
default `favorite` (1 imagen), se reporta "N diferidas", el reimport reintenta.

**Estimación**: L (3 h). Split interno T3.2.a / T3.2.b.

---

## T3.3 — JS fail-loud en las 3 ramas + pausa + progreso

**Objetivo**: eliminar las 3 ramas de cierre silencioso del chunked y mostrar causa + progreso real
en todas las ramas.

**Descripción técnica**: `admin.js:220` (start `!r.success`), `:238` (página `!r.success`) y `:278`
(terminal tras reintentos) hacen `cleanup()` **sin** `showNotice`. El design §4.4 separa
`if (cancelled)` de `if (!r.success)` y muestra `safeMsg(r, fallback)`. El aviso sobrevive al
`cleanup()` porque `showNotice` inyecta en `.alegra-connector-wrap` (`:25-33`) y `cleanup` solo
oculta `#alegra-sync-progress-modal` (`:184-188`). El cliente deja de llevar `page`: el servidor es
dueño del cursor (D3 §4.3). Cubre REQ-IMP-01/02/04.

**Desarrollo técnico**:

Archivos: `admin/assets/js/admin.js` (`:218-227`, `:229-282`), `admin/Admin/Admin_Dashboard.php`
(`get_script_strings`, `:652-826`).

### T3.3.a — Rama `start` (`:218-227`)

ANTES:

```js
                    success: function(r) {
                        currentRequest = null;
                        if (!r.success || cancelled) { cleanup(); return; }
                        var d = r.data || {};
                        $label.text(fmt(S.phase1Total, (d.total_items || '?'), (d.total_pages || '?')));
                        $counters.text(S.processing);
                        processPage(1);
                    },
```

DESPUÉS:

```js
                    success: function(r) {
                        currentRequest = null;
                        if (cancelled) { cleanup(); return; }        // el botón Cancelar ya avisó
                        if (!r.success) {
                            cleanup();
                            showNotice(safeMsg(r, S.startError), 'error');
                            $btn.prop('disabled', false).text(S.retry);   // hoy quedaba deshabilitado
                            return;
                        }
                        var d = r.data || {};
                        $label.text(fmt(S.phase1Total, (d.total_items || '?'), (d.total_pages || '?')));
                        $counters.text(d.resuming ? fmt(S.resumingFrom, (d.start || 0)) : S.processing);
                        processPage();
                    },
```

### T3.3.b — Rama `page` (`:235-271`)

ANTES (líneas clave):

```js
                        success: function(r) {
                            currentRequest = null;
                            retries = 0;
                            if (!r.success || cancelled) { cleanup(); $btn.prop('disabled',false).text(S.retry); return; }
                            var d = r.data;
                            var pct = d.percent || Math.min(95, 5 + (page * 2));
                            ...
                            if (d.done) { ... } else { processPage(page + 1); }
                        },
```

DESPUÉS:

```js
                        success: function(r) {
                            currentRequest = null;
                            retries = 0;
                            if (cancelled) { cleanup(); return; }
                            if (!r.success) {
                                cleanup();
                                showNotice(safeMsg(r, S.error), 'error');
                                $btn.prop('disabled', false).text(S.retry);
                                return;
                            }
                            var d = r.data;
                            $fill.css('width', (d.percent || 0) + '%');
                            $label.text(fmt(S.phase1, d.message));
                            $counters.text(d.imported + ' ' + S.importedLabel + ' | ' + d.updated + ' ' + S.updatedLabel + ' | ' + (d.skipped || 0) + ' ' + S.skippedLabel + ' | ' + d.errors + ' ' + S.errorsLabel);

                            if (d.paused) {
                                $label.text(S.pausedResuming);
                                processPage();                       // el servidor es dueño del cursor
                            } else if (d.done) {
                                $fill.css('width', '100%');
                                var hasErrors = d.errors > 0;
                                $label.text(hasErrors ? S.phase2CompletedErrors : S.phase2Completed);
                                $counters.text(/* …igual que hoy… */);
                                if (d.images && Number(d.images.failed) > 0) {
                                    showNotice(fmt(S.imagesFailed, d.images.failed, d.images.blocked, d.images.download, d.images.sideload, d.images.deferred), 'warning');
                                }
                                setTimeout(function() { /* …resumen + reload igual que hoy… */ }, 1500);
                            } else {
                                processPage();
                            }
                        },
```

### T3.3.c — Rama terminal (`:272-280`)

ANTES:

```js
                        error: function() {
                            retries++;
                            if (retries <= maxRetries) {
                                $counters.text(fmt(S.retrying, retries, maxRetries));
                                setTimeout(function() { processPage(page); }, 2000);
                            } else {
                                cleanup(); $btn.prop('disabled',false).text(S.retry);
                            }
                        }
```

DESPUÉS:

```js
                        error: function() {
                            retries++;
                            if (retries <= maxRetries) {
                                $counters.text(fmt(S.retrying, retries, maxRetries));
                                setTimeout(function() { processPage(); }, 2000);
                            } else {
                                cleanup();
                                showNotice(S.connectionError, 'error');   // rama terminal antes muda (:278)
                                $btn.prop('disabled', false).text(S.retry);
                            }
                        }
```

### T3.3.d — `processPage` sin `page`

Cambiar la definición `var processPage = function(page) {` (`:229`) a `var processPage = function() {`.
Quitar `if (cancelled) { cleanup(); return; }` **no** — se conserva (`:230`). Reemplazar el
`processPage(1)` de `:224` y el `processPage(page + 1)` de `:269` por `processPage()`. El request de
página (`:232-234`) **no cambia** (ya no envía `page`, C10). `processNext` (`:190`) queda como
placeholder sin uso.

### T3.3.e — Strings nuevas en `get_script_strings()` (`Admin_Dashboard.php:652-826`)

**Dueño único por string (cierra I3).** Fase 3 **sólo declara** las claves cuyo dueño es Fase 3; las
compartidas las declara el dueño de la UI que las consume y acá se **consumen**, nunca se redeclaran
(dos claves iguales en el mismo array se pisan en silencio). Tabla canónica completa en
`fase-6-ui-logs-monitor.md` §"Tabla canónica de strings i18n".

Declarar acá (dueño Fase 3):

```php
            'pausedResuming'        => __('Pausado por tiempo; continúa con la próxima página...', 'alegra-connector'),
            'resumingFrom'          => __('Reanudando desde el ítem %s...', 'alegra-connector'),
            'confirmReimport'       => __('Esto reimporta TODO el catálogo desde cero y limpia el punto de reanudación. ¿Continuar?', 'alegra-connector'),
            'startingFromZero'      => __('Empezando de cero...', 'alegra-connector'),
            'confirmRecreateManual' => __('¿Recrear también los productos que borraste a mano?', 'alegra-connector'),
```

> `confirmReimport`, `startingFromZero` y `confirmRecreateManual` las consume el JS de Fase 4 (T4.5);
> por coordinación de fases se declaran **acá una sola vez** y T4.5 sólo las consume (ver `fase-4` T4.5).

**Claves compartidas — consumir, NO redeclarar:**

| Clave | Dueño (única declaración) | Consumidor |
|---|---|---|
| `imagesFailed` | **T5.2b** (Fase 5) | T3.3.b (chunked) + T5.2b (manual) |
| `monitorError` | **T6.4.b** (Fase 6) | T6.4.b (poll del Monitor) |
| `statusAbandoned` | **T6.4.a** (Fase 6) | T6.4.a (badge `stale`) |

Consumir la clave declarada en `T{x}.{y}`; **no** redeclarar. El texto canónico de `monitorError` es
el de **T6.4.b** (`No se pudo cargar el monitor. Reintentando...`); el texto viejo de este archivo
(`No se pudo actualizar el Monitor.`) queda **descartado**.

`startError` (`:687`), `retry` (`:686`), `connectionError` (`:661`) **ya existen**. **NO** se agrega
`confirmClearAll`: el borrado total reusa la clave **existente** `confirmClearLogs`
(`Admin_Dashboard.php:705`, consumida por `admin.js:387`), cuyo **valor** cambia en Fase 6 T6.1.b.
**NO** tocar `languages/*`.

**Resultado esperado**: start/página/terminal muestran causa; el botón se rehabilita; la barra y los
contadores avanzan; ningún cierre mudo.

**Dependencias**: T2.4/T3.1 (payload `message`/`paused`/`percent`). T3.4 (payload `images`).

**Trazabilidad**: REQ-IMP-01, REQ-IMP-02, REQ-IMP-04.

**Verificación**:
1. Test `T28.38` — **Source-scan** (patrón `assertStringNotContains` sobre `file_get_contents`, ya usado en
   `exec-test.php`): el fuente de `admin.js` **no** contiene `if (!r.success || cancelled) { cleanup(); return; }`
   (la rama muda del start) ni `cleanup(); $btn.prop('disabled',false).text(S.retry);` sin
   `showNotice` en la misma rama.
2. Test `T28.39` — **Source-scan**: `get_script_strings()` contiene las claves **dueño de Fase 3**
   (`pausedResuming`, `resumingFrom`, `confirmReimport`). `imagesFailed`/`monitorError`/`statusAbandoned`
   **no** se assertan acá: las declaran T5.2b/T6.4.b/T6.4.a respectivamente (I3, dueño único).
3. `bash scripts/exec-test.sh` verde.
4. **Prove-it-catches**: reponer `if (!r.success || cancelled) { cleanup(); return; }` en `:220` →
   (1) falla.

**Riesgo**: que un `showNotice` de error se pierda al hacer `cleanup()` primero. **Guarda**: nodos
distintos (`showNotice` → `.alegra-connector-wrap`; `cleanup` → modal); el orden no importa, pero se
documenta. Riesgo de bucle infinito si el servidor devuelve `paused:true` para siempre: **no** hay
reintento infinito porque cada request avanza `offset` o `start` (T3.1); si la API devuelve el mismo
conjunto sin avanzar, el deadline corta y el `offset` avanza igual.

**Estimación**: M (3 h).

---

## T3.4 — Payload de página completo (`paused`, `images`, `message`)

**Objetivo**: que la respuesta de `alegra_sync_page` incluya todo lo que el JS y el resumen
necesitan, con `message` **siempre** en ambas ramas.

**Descripción técnica**: el design §8 fija el contrato del payload. Hoy (`:2216-2221`) faltan
`paused`, `images` y `resuming`; el `percent` se calcula por página. T3.4 lo cierra y avisa de
imágenes fallidas si `images.failed > 0` (REQ-IMG-03).

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php` (`ajax_sync_page`, respuesta final) y `admin.js` (resumen).

1. **Payload de éxito** (reemplaza `:2216-2221`):

```php
        wp_send_json_success([
            'page'        => (int) floor(($state['start'] ?? 0) / $per_page) + 1,
            'total_pages' => $tp,
            'total_items' => $total_items,
            'imported'    => (int) $state['imported'],
            'updated'     => (int) $state['updated'],
            'skipped'     => (int) ($state['skipped'] ?? 0),
            'errors'      => (int) $state['errors'],
            'processed'   => $processed,
            'percent'     => $pct,
            'paused'      => $paused,
            'done'        => $done,
            'images'      => $state['images'] ?? ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0, 'failed' => 0],   // T5.2b: acumulado del run (fuente única)
            'message'     => sprintf(
                __('%1$d/%2$d items — Pág. %3$d/%4$d', 'alegra-connector'),
                $processed, $total_items,
                (int) floor(($state['start'] ?? 0) / $per_page) + 1, $tp
            ),
        ]);
```

2. **`message` en las ramas de error** — ya lo tienen (T2.4): lock (`:2101`), WP_Error
   (`:2116`,`:2148`,`:2184`), batch-state (`:2079`), cancel (`:2090`). Verificar que **ninguna**
   rama de `ajax_sync_page` use `wp_send_json_error()` sin `['message' => …]` (el `wp_send_json_error()`
   de `:2076` por falta de permisos es el único sin mensaje; no es un camino de import).

3. **JS** (parte de T3.3.b): el resumen del modal y el `showNotice` de `imagesFailed` cuando
   `d.images.failed > 0` (warning). Sin fallos → sin alarma.

4. **Fuente única de `images` (cierra el conflicto T3.4 vs T5.2b).** El payload expone
   **`$state['images']`** (acumulado del run), que es la **fuente canónica**. `Sync\Products::image_stats()`
   es el acumulador **de la página** y se usa **sólo como insumo del merge**: T5.2b hace
   `$state['images'] = self::merge_image_stats($state['images'], Sync\Products::image_stats())` tras el
   loop de `products` (`Admin_Dashboard.php:2144`). T3.4 **no** escribe `image_stats()` directo en el
   payload (esta corrección elimina esa línea) para que ambas tareas coincidan.
   - **Path canónico del dato crudo:** el ítem de `GET /items` (con `mode => 'advanced'`, `Products.php:1329-1333`)
     trae `$item['images'][]` (`['url' => …, 'favorite' => …]`), consumido por `Products::import_product_images()`
     (`Products.php:2018`).
   - **Path canónico del payload:** `$state['images']` → JS `d.images.{ok,blocked,download,sideload,deferred,failed}`.

**Resultado esperado (aceptación verificable)**:
- La barra usa `d.percent`; el resumen muestra totales y el aviso de imágenes; un import sin fallos
  no alarma.
- `assertArrayHasKey('message', $resp->payload)` en **ambas** ramas (éxito y error).
- `images.failed === 0` sin alarma; con `blocked>0` el payload lo refleja.

**Dependencias**: T3.1 (`percent`/`paused`), T3.2.b/T5.2a/T5.2b (`image_stats`).

**Trazabilidad**: REQ-IMP-04, REQ-IMG-03, REQ-RES-04, NFR-06.

**Verificación**:
1. Test `T28.310 message siempre`: `assertArrayHasKey('message', $resp->payload)` en el camino de éxito
   y en el de `WP_Error`.
2. Test `T28.311 images en el payload`: `assertArrayHasKey('images', $resp->payload)` y
   `assertSame(0, $resp->payload['images']['failed'])` sin fallos.
3. `T28.312 blocked reflejado` **se movió a Fase 5** (ahora `T28.59`, §T5.2b). Motivo: `blocked` lo
   produce el incremento de **T5.2a** (Fase 5) y no existe en el gate de Fase 3; un test de Fase 3
   no puede depender de una fase posterior. La aserción sobre el payload (que sí es de T3.4) se
   verifica igual en T28.59 una vez que T5.2a está aplicada.
4. **Prove-it-catches**: quitar `'message'` de la respuesta de éxito → (1) falla.
5. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el JS viejo espere `percent` por página y la barra retroceda. **Guarda**: `percent`
monótono por `processed/total_items`; test manual en T7.2. Riesgo de payload grande: `images` son 6
enteros, despreciable.

**Estimación**: S/M (2 h).

---

## DoD de la fase

- REQ-IMP-01/03/04, REQ-RES-04, NFR-01/04/05 verdes.
- El chunked **no** muere por fatal 500 y **no** pierde ítems entre páginas (test de suma == total).
- Ninguna rama del JS cierra sin `showNotice` (source-scan).
- `bash scripts/exec-test.sh` verde.

## Orden interno recomendado

```
T3.1.a (opción) ─┐
T3.2.a (run_id/stop/progreso) ─┼─► T3.1.b/c (loop con deadline+offset) ─► T3.4 (payload) ─► T3.3 (JS)
T3.2.b (deadline imágenes) ────┘
```

T3.3 (JS) va al final: consume el payload de T3.1/T3.4. **T3.2.b va ANTES de T3.1.b** (secuencial,
no en paralelo): T3.1.b llama `set_deadline()`/`reset_image_stats()`, que define **T3.2.b** (no
T3.2.a). El diagrama de arriba ya los encadena (`T3.2.b ─► T3.1.b`).
