# Fase 7 — Poll: budget + cursor + `truncated` + `set_syncing` (D6) — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **7** — Poll con presupuesto, cursor, tope configurable y `set_syncing` |
| Versión objetivo | **2.6.0** |
| Harness | `bash scripts/exec-test.sh` (**1626 assertions, 0 failed** — verificado corriendo el harness en HEAD) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T7.1`–`T7.6` (expandidas a 9 micro-tareas) |
| Depende de | Fases 2 y 3 (el loop ya escribe por `Inventory_Writer` y consulta el ledger del pusher) · `T1.7` (opciones sembradas) · `T1.11` (sección `T29`) |
| DoD de la fase | el poll pausa por presupuesto, persiste el cursor, reanuda, reporta `truncated` y no dispara la cascada de push; tests `T29.71`–`T29.76` (8 IDs canónicos) verdes con prove-it-catches |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Sin eso, el test
> no se acepta.
>
> **IDs de test.** Los tests viven en `scripts/exec-test.php`, sección `// === sync-reliability (2.6.0) ===`,
> con IDs `T29.7x` (los `T29.1x`/`T29.2x`/`T29.3x`/`T29.5x`/`T29.8x`/`T29.9x` son de otras fases).
> `T7.x` son IDs de **tarea**, no de test. La sección `T29` la crea `T1.11`; esta fase **solo agrega** tests.
>
> **Lista canónica de test IDs de Fase 7 (8):** `T29.71`, `T29.72`, `T29.72b`, `T29.73`, `T29.74`,
> `T29.75`, `T29.75b`, `T29.76`. El índice maestro (`tasks.md` §2.1) debe usar estos **8**; el rango
> `T29.71`–`T29.75` (5 IDs) de `tasks.md:136` queda **superseded** por esta lista.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 7)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1290-1292` es el `finally` del poll | Confirmado: `:1290` `} finally {`, `:1291` `release_sync_lock_public('products', $lock)`, `:1292` `}`. | `T7.3`, `T8.1` |
| C2 | `Products.php:1171` = `max_pages=200` fijo; `:1172` = `set_time_limit(300)`; `:1174` = arranca en página 1 | Confirmado (`:1171` `$max_pages = 200;`, `:1172` `set_time_limit(300);`, `:1174` `for ($p = 1; ...)`). | `T7.1.b`, `T7.1.c`, `T7.2.a` |
| C3 | Patrón del import `Products.php:1305-1322` | Confirmado: cursor `:1305`, `$start` desde cursor `:1312`, budget `:1318`, deadline `:1322`. Persistencia del cursor `:1433`; limpieza/actualización `:1438-1442`. | `T7.1.b`, `T7.2.b` |
| C4 | `import_from_alegra` NO acepta deadline externo | La firma real es `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array\|\WP_Error` (`:1295`). **El design §10 no lista un cambio de firma del import**, pero el design §8.3 y `T8.2` exigen propagarle el deadline global. | `T8.2` (corrección: agregar 4º parámetro opcional `float $run_deadline = 0.0`) |
| C5 | `set_syncing` en el import: true `:1542`, false `:1632` | Confirmado (`:1542` dentro del `else`; `:1632` dentro del `finally` con guard `if (!$was_syncing)`). El poll **no** lo llama. | `T7.3` |
| C6 | La cascada la corta el **transient**, no el static | Confirmado: `on_update_product` (`public/Public/Public_.php:226-234`) **no** consulta `is_syncing()`; solo el transient `alegra_updating_product_{id}` (`:229`). `set_syncing(true)` escribe `alegra_import_in_progress` (`:433`) y `trigger_sync()` lo lee (`:371`). TTL del transient = **300 s** (`:433`). | `T7.3` (refrescar por página, DR8) |
| C7 | `ajax_sync_inventory` `admin/Admin/Admin_Dashboard.php:1811-1835` solo devuelve `updated` | Confirmado: `:1818` llama `run_inventory_sync()`; `:1827-1834` responde solo `message` + `updated`. | `T7.4` |
| C8 | `run_inventory_sync()` `Controller.php:110-115` no propaga deadline | Confirmado: `:110` firma sin args; `:112` `sync_inventory_from_alegra()` sin args. El call site del cron es `Controller.php:211`. | `T7.5`, `T8.2` |
| C9 | `set_time_limit` y `register_shutdown_function` en producción | `register_shutdown_function` = **0** matches; `set_time_limit(300)` en el poll = 1 match (`Products.php:1172`). | `T7.2.a`, `T8.1` |
| C10 | Opciones del poll | `alegra_connector_inventory_poll_budget`, `_max_pages`, `_pull_cursor`, `_pull_total` = **0** matches hoy (las siembra `T1.7`). | `T7.1.b`, `T7.2.b` |
| C11 | `T7.6` afirma "el mock debe respetar `start`/`limit` (lo hace, `alegra-mock.php`)". | **FALSO.** `GET /items` (`alegra-mock.php:659-671`) sólo aplica `array_slice($filtered, $start, $limit)` **si** `metadata=true`; sin metadata devuelve **todo** el catálogo filtrado (`:671`). El poll llama `get_items(['start'=>…,'limit'=>30,'mode'=>'advanced'])` **sin** metadata ⇒ `count($items) >= 30` siempre y el cursor no pagina. Los tests de cursor (`T29.71`/`T29.73`) no prueban nada hasta que Fase 1 (dueña de `scripts/lib/alegra-mock.php`) pagine la rama sin metadata (mismo patrón que `T1.3` para `/contacts`, `:391-394`). | `T7.1.b`, `T7.6` (FIX-13 / Oracle D7) |
| C12 | `GET /items?variantParent_id` + `limit=100` (Oracle D13) | **SIN VERIFICAR** (G2). No es un hallazgo de Fase 7: vive en `T4.1` (`fase-4-variaciones.md:88-116`). Se cross-referencia acá; el poll **no** usa `variantParent_id` ni `limit=100`. | Fase 4 `T4.1`; G2 (FIX-17) |
| C13 | `T7.2.b` actualiza `_pull_total` como `max(total, $cursor)` por página | Con `total == cursor` tras la 1.ª página, el guard `$cursor >= $known_total` es **siempre** verdadero ⇒ el cursor se resetea en **cada** corrida y el resume no funciona. El total debe venir del `metadata.total` de la API (patrón de `ajax_sync_start`, `Admin_Dashboard.php:2131-2151`), no del cursor. | `T7.1.b`, `T7.2.b` (FIX-14 / momus C6) |

**Citas confirmadas exactas:** `Products.php:1142` (firma del poll), `:1144` (`$result` sin
`truncated`), `:1149` (kill switch), `:1157` (fuente de inventario), `:1163` (`acquire_sync_lock_public`),
`:1175` (cancelación), `:1182` (`should_stop`), `:1190-1199` (transient de progreso), `:1205-1209`
(`get_items` con `start` por página), `:1211` (`WP_Error`), `:1215` (`empty`), `:1219` (`foreach`),
`:1224` (guard `availableQuantity`), `:1228` (`get_product_by_alegra_id`), `:1238` (`new_qty`),
`:1249` (`manage_stock`), `:1253-1260` (W2), `:1280` (`pages`), `:1282-1284` (`count < 30`),
`:1287` (log final); `Controller.php:110-115,211`; `Public_.php:226-234,371,426-437,442`;
`Admin_Dashboard.php:1811-1835`; `alegra-connector.php:414-457,463-486,488-492`;
`Admin_Dashboard.php:496-510` (register de `import_time_budget`/`import_max_pages`).

---

## Mapa de cobertura Fase 7 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T7.1.a` | Firma con `$deadline` + shape del resultado | `includes/Sync/Products.php:1142-1144` |
| `T7.1.b` | Loop con budget + cursor persistido | `includes/Sync/Products.php:1170-1285` |
| `T7.1.c` | Tope configurable + `truncated` + log explícito | `includes/Sync/Products.php` (cierre del loop) |
| `T7.2.a` | Quitar `set_time_limit(300)` | `includes/Sync/Products.php:1172` |
| `T7.2.b` | Reset semantics del cursor | `includes/Sync/Products.php` + `Admin_Dashboard.php` |
| `T7.3` | `set_syncing` alrededor del poll (sin cascada) | `includes/Sync/Products.php`; `Public_.php` |
| `T7.4` | Surfacear `truncated/completed/cursor/pages` + `message` | `Admin_Dashboard.php:1811-1835` |
| `T7.5` | `run_inventory_sync()` propaga deadline | `includes/Sync/Controller.php:110-115` |
| `T7.6` | Tests del poll | `scripts/exec-test.php` |

---

### T7.1.a — Firma del poll con deadline + shape del resultado

**Objetivo**: que `sync_inventory_from_alegra()` acepte el deadline global y devuelva el estado de la corrida (budget/cursor/completitud).

**Descripción técnica**: hoy el poll firma `sync_inventory_from_alegra(int $run_id = 0): array`
(`Products.php:1142`) y su resultado (`:1144`) solo tiene `updated/errors/pages/locked/skipped`. No hay
forma de saber si la corrida **completó** o **se cortó**, ni dónde reanudar. D6 §7.2 fija la firma y el
shape; REQ-POLL-01/02 exigen cursor y `truncated`. Esta micro-tarea **solo** cambia la firma y el array
inicial (no toca el loop todavía) para que T7.1.b/T7.1.c la usen.

**Desarrollo técnico** — `includes/Sync/Products.php`:

ANTES (`:1142-1144`):
```php
    public function sync_inventory_from_alegra(int $run_id = 0): array
    {
        $result = ['updated' => 0, 'errors' => 0, 'pages' => 0, 'locked' => false, 'skipped' => false];
```

DESPUÉS:
```php
    public function sync_inventory_from_alegra(int $run_id = 0, float $deadline = 0.0): array
    {
        $result = [
            'updated'                => 0,
            'errors'                 => 0,
            'pages'                  => 0,
            'locked'                 => false,
            'skipped'                => false,
            'skipped_not_manageable' => 0,       // lo llena T2.5/T3.4 (FIX-8); esta fase NO lo borra
            'truncated'              => false,   // quedó trabajo pendiente (budget o max_pages)
            'completed'              => false,   // se recorrió el catálogo completo
            'cursor'                 => 0,       // start de la próxima corrida (0 si completó)
        ];
```

> **Restricción cross-file (C2 / tasks.md §11-I11).** `T2.4` (Fase 2) agrega `skipped_not_manageable`
> y `T2.5`/`T3.4` lo incrementan (`fase-3:769-770`). `T7.1.a` **debe preservarlo** en el array: sin eso
> se produce un *undefined index* en runtime. El conjunto canónico es de **9 claves** — `updated`,
> `errors`, `pages`, `locked`, `skipped`, `skipped_not_manageable`, `truncated`, `completed`, `cursor` —
> y **no** incluye `divergence` (eso vive en el informe de divergencia, REQ-INV-07; ver design §7.4/§10).

**Orden de operaciones:** este cambio es puramente aditivo; el resto del método compila igual. Los
call sites existentes siguen válidos porque el 2º parámetro es opcional.

**Call sites que consumen el shape nuevo** (no se tocan acá, se cablean en T7.4/T7.5/T8.2):
- `Controller.php:112` → `run_inventory_sync()` (T7.5 le pasa `$deadline`).
- `Controller.php:211` → el cron (T8.2 le pasa `$deadline`).
- `Admin_Dashboard.php:1818` → el AJAX (T7.4 surfacea los campos).

**Resultado esperado**: `sync_inventory_from_alegra` acepta `(int $run_id = 0, float $deadline = 0.0)`;
el array `$result` contiene las **9 claves canónicas**; `grep -n "function sync_inventory_from_alegra" includes/Sync/Products.php`
muestra la firma nueva.

**Dependencias**: ninguna (primera micro-tarea de la fase). Habilita T7.1.b/c.

**Trazabilidad**: REQ-POLL-01, REQ-POLL-02; design §7.2, §10.

**Verificación**: `T29.71` asserta `array_key_exists('truncated', $result)` y
`array_key_exists('cursor', $result)` tras una corrida vacía. **Prove-it-catches:** volver la firma a
`(int $run_id = 0)` y quitar las 3 claves → `T29.71` falla.

**Riesgo**: bajo. Un call site que pase `float` en el 2º argumento de otro método con firma vieja sería
un `TypeError`; el grep de call sites (`Controller.php:112,211`, `Admin_Dashboard.php:1818`) los cubre.

**Estimación**: S (0,5 h).

---

### T7.1.b — Loop con presupuesto de wall-clock + cursor persistido

**Objetivo**: reemplazar el `for` de 200 páginas por un `while` que corta por presupuesto propio y reanuda desde un cursor persistido.

**Descripción técnica**: hoy el poll arranca **siempre** en la página 1 (`for ($p = 1; ...)`, `:1174`)
y calcula el `start` como `($p - 1) * 30` (`:1206`), con un tope fijo de 200 páginas (`:1171`). En un
catálogo grande **nunca termina** (C1/C2 de la propuesta) y cada corrida re-empieza de cero. El import
ya resolvió esto (`:1305-1322`): cursor persistido + `deadline = microtime(true) + budget`. Se copia el
patrón. Opciones (sembradas por `T1.7`): `alegra_connector_inventory_poll_budget` (int, default **60**),
`alegra_connector_inventory_pull_cursor` (int, default **0**). D6 §7.1/§7.2; REQ-POLL-01.

**Desarrollo técnico** — `includes/Sync/Products.php`:

ANTES (`:1170-1174`, dentro del `try`):
```php
        try {
            $max_pages = 200;
            set_time_limit(300);

            for ($p = 1; $p <= $max_pages; $p++) {
```

DESPUÉS:
```php
        try {
            $cursor_key = 'alegra_connector_inventory_pull_cursor';
            $total_key  = 'alegra_connector_inventory_pull_total';

            // Cursor persistido (D6 §7.2): un cursor viejo es válido y reanudable.
            $cursor = max(0, (int) get_option($cursor_key, 0));

            // Presupuesto propio, NO del host (NFR-03). Default conservador 60 s.
            $budget = max(10, (int) get_option('alegra_connector_inventory_poll_budget', 60));
            $own_deadline = microtime(true) + $budget;
            if ($deadline > 0.0) {
                $own_deadline = min($own_deadline, $deadline);   // deadline global del cron (T8.2)
            }

            // 0 = sin tope: el cursor reanuda. Reemplaza el 200 fijo de HEAD.
            $max_pages = max(0, (int) get_option('alegra_connector_inventory_poll_max_pages', 0));

            $p = 0;
            $completed = false;
            while (true) {
                if (microtime(true) >= $own_deadline) {
                    $result['truncated'] = true;
                    break;
                }
                if ($max_pages > 0 && $p >= $max_pages) {
                    $result['truncated'] = true;
                    break;
                }
```

El bloque de cancelación (`:1175-1179`), el `should_stop` (`:1182-1185`) y el transient de progreso
(`:1190-1199`) **quedan igual**, salvo que el número de página humano pasa a ser `$p + 1`:

```php
                if ($p === 0 || ($p + 1) % 5 === 0) {
                    set_transient('alegra_sync_progress', [
                        'type' => 'inventory',
                        'current_page' => $p + 1,
                        'items_processed' => $result['updated'] + $result['errors'],
                        'updated' => $result['updated'],
                        'errors' => $result['errors'],
                        'cursor' => $cursor,
                        'message' => sprintf(__('Sincronizando inventario... Página %d', 'alegra-connector'), $p + 1),
                    ], 600);
                }
```

El `get_items` deja de calcular el `start` por página y usa el cursor:

ANTES (`:1205-1209`):
```php
                $items = $this->api->get_items([
                    'start' => ($p - 1) * 30,
                    'limit' => 30,
                    'mode'  => 'advanced',
                ]);
```

DESPUÉS:
```php
                $items = $this->api->get_items([
                    'start'           => $cursor,
                    'limit'           => 30,
                    'mode'            => 'advanced',
                    // FIX-15 (Oracle R-3): orden estable por id. Sin él, un
                    // catálogo que cambia entre corridas corre los offsets y
                    // saltea/reprocesa ítems. SIN VERIFICAR en la cuenta (G2):
                    // si la API no soporta order_field/order_direction, se
                    // mantiene el offset y se acepta la limitación (ver abajo).
                    'order_field'     => 'id',
                    'order_direction' => 'ASC',
                ]);
```

> **FIX-15 — orden estable del cursor (Oracle R-3).** El cursor es un **offset** (`start`); si el
> catálogo cambia entre corridas, las páginas se corren y se saltean/reprocesan ítems. Se fija el orden
> con `order_field=id`/`order_direction=ASC`. **SIN VERIFICAR** (G2): si la cuenta no soporta esos
> parámetros, se mantiene el offset y se **acepta la limitación** con dos mitigaciones: (1) el ledger
> `_alegra_stock_synced` hace **idempotente** reprocesar un ítem (no re-infla ni re-empuja); (2) una
> corrida que completa recorre el catálogo entero, así que un salteo transitorio se corrige en la
> siguiente pasada completa. Se documenta en `T9.5` (release).

El `empty($items)` marca completitud (antes solo cortaba en silencio):

ANTES (`:1215-1217`):
```php
                if (empty($items)) {
                    break;
                }
```

DESPUÉS:
```php
                if (empty($items)) {
                    $completed = true;
                    break;
                }
```

Al final de cada página se avanza y persiste el cursor:

ANTES (`:1280-1284`):
```php
                $result['pages'] = $p;

                if (count($items) < 30) {
                    break;
                }
```

DESPUÉS:
```php
                $result['pages'] = ++$p;
                $cursor += 30;
                update_option($cursor_key, $cursor, false);   // persistir por página (patrón :1433)

                if (count($items) < 30) {
                    $completed = true;
                    break;
                }
```

**Orden de operaciones:** (1) leer cursor; (2) calcular `own_deadline` con `min` del global; (3) leer
`max_pages`; (4) `while(true)` con chequeos de budget/tope arriba; (5) cancelación/stop; (6) progreso;
(7) GET desde `$cursor`; (8) `foreach` (cuerpo de T2.4/T3.4 intacto); (9) `++$p`, `$cursor += 30`,
persistir; (10) `count < 30` ⇒ completar.

**Resultado esperado**: con un catálogo que no entra en el budget, el poll corta por presupuesto (no por
fatal), persiste `alegra_connector_inventory_pull_cursor` con el `start` de la próxima página y la
corrida siguiente emite `GET /items` con ese `start` (no 0).

**Dependencias**: T7.1.a. El cuerpo del `foreach` (ledger + `Inventory_Writer`) lo dejaron T2.4 y T3.4;
**no se re-toca**.

**Trazabilidad**: REQ-POLL-01, NFR-02, NFR-03, NFR-07; design §7.2/§7.3; hallazgos C1/C2.

**Verificación**: `T29.71` — con `alegra_connector_inventory_poll_budget` bajo y N ítems,
`$result['truncated'] === true`, `$result['cursor'] > 0` y la 2ª corrida arranca con ese cursor.
**Prove-it-catches:** quitar el chequeo `microtime(true) >= $own_deadline` → el poll no pausa y `T29.71`
falla.

**Riesgo**: un budget mal calibrado en host lento → defaults conservadores + configurable (DR15). Un
cursor viejo apuntando a un catálogo encogido → lo cubre T7.2.b.

**Estimación**: M (1,5 h).

---

### T7.1.c — Tope de lote configurable + flag `truncated` + log explícito

**Objetivo**: que el corte por tope/budget deje `truncated=true`, un log con el remanente y el cursor para continuar.

**Descripción técnica**: hoy el tope es fijo (200 páginas, `:1171`), el resultado no tiene `truncated`
(`:1144`) y la salida por `count($items) < 30` (`:1282-1284`) no distingue "fin" de "corte" (C2). D6
§7.4 exige `truncated` + log. Opción `alegra_connector_inventory_poll_max_pages` (int, default **0** =
sin tope). REQ-POLL-02.

**Desarrollo técnico** — cierre del `while`, antes del `return` (reemplaza el bloque `:1287-1289`):

ANTES (`:1287-1289`):
```php
            $this->logger->info('Inventory sync from Alegra completed', $result);

            return $result;
```

DESPUÉS:
```php
            // Reset del cursor SOLO al completar (D6 §7.3; el resto lo cubre T7.2.b).
            if ($completed) {
                delete_option($cursor_key);
                delete_option($total_key);
            }
            $result['completed'] = $completed;
            $result['cursor'] = $completed ? 0 : $cursor;

            if ($result['truncated']) {
                $this->logger->warning('Inventory poll truncated; resuming next run', [
                    'cursor'  => $cursor,
                    'pages'   => $result['pages'],
                    'updated' => $result['updated'],
                    'errors'  => $result['errors'],
                ]);
            }

            $this->logger->info('Inventory sync from Alegra completed', $result);

            return $result;
```

**Orden de operaciones:** al salir del `while`, (1) si `$completed` ⇒ borrar cursor y total; (2) setear
`completed`/`cursor`; (3) si `truncated` ⇒ `warning` con cursor/páginas/actualizados; (4) `info` final.

**Resultado esperado**: una corrida cortada por budget o `max_pages` deja `truncated=true`,
`completed=false`, `cursor>0` y una línea `Inventory poll truncated; resuming next run` con el cursor.
Una corrida que completa deja `truncated=false`, `completed=true`, `cursor=0` y borra las opciones de
cursor/total.

**Dependencias**: T7.1.b.

**Trazabilidad**: REQ-POLL-02, NFR-04; design §7.3/§7.4; hallazgo C2.

**Verificación**: `T29.72` — corrida truncada ⇒ `truncated=true` y log presente (el harness captura el
logger); corrida completa ⇒ `truncated=false` y `get_option($cursor_key) === false`.
**Prove-it-catches:** quitar el `logger->warning` de truncación → `T29.72` falla.

**Riesgo**: bajo. `delete_option` sobre opciones inexistentes es no-op.

**Estimación**: S (0,75 h).

---

### T7.2.a — Quitar `set_time_limit(300)` del poll

**Objetivo**: eliminar el `set_time_limit(300)` como parche de duración; el corte es por presupuesto propio.

**Descripción técnica**: hoy el poll llama `set_time_limit(300)` (`:1172`). En hosts que lo ignoran
(`disable_functions`/`safe_mode`) no protege nada y da falsa seguridad (NFR-03, propuesta §3.3). El
deadline propio de T7.1.b es la defensa real. **Nota de ejecución:** T7.1.b reescribe el bloque
`:1170-1174`; su DESPUÉS **ya no incluye** `set_time_limit(300)`. Si el worker hizo T7.1.b como un solo
edit, esta micro-tarea es de **verificación** (grep). Si no, se borra acá.

**Desarrollo técnico** — `includes/Sync/Products.php`:

ANTES (`:1172`, línea suelta):
```php
            set_time_limit(300);
```

DESPUÉS: **línea eliminada** (sin reemplazo).

**Resultado esperado**: `grep -n "set_time_limit" includes/Sync/Products.php` → **0** coincidencias en el
poll (la única del repo, si queda, no está en `sync_inventory_from_alegra`).

**Dependencias**: T7.1.b.

**Trazabilidad**: REQ-POLL-01, NFR-02, NFR-03; design §7.4; hallazgo C2.

**Verificación**: source-scan `T29.72b`: el cuerpo de `sync_inventory_from_alegra` (extraído por regex
entre `function sync_inventory_from_alegra` y el `finally`) **no** contiene `set_time_limit`.
**Prove-it-catches:** re-agregar `set_time_limit(300);` dentro del poll → `T29.72b` falla.

**Riesgo**: un host con `max_execution_time` muy bajo y budget 60 s podría cortar antes del budget. Es
aceptable: el corte deja cursor y reanuda. El import mantiene su `set_time_limit` propio (fuera de esta
tarea).

**Estimación**: S (0,25 h).

---

### T7.2.b — Reset semantics del cursor

**Objetivo**: definir exactamente cuándo el cursor vuelve a 0, para que nunca quede pegado a un catálogo encogido ni bloqueado por un "desde cero".

**Descripción técnica**: el cursor se reanuda, pero hay 3 casos donde debe resetearse (D6 §7.3): (1) la
corrida **completa** el catálogo; (2) el comerciante dispara "Sincronizar inventario" con
`from_zero=true`; (3) el cursor persistido es `>=` al total conocido
(`alegra_connector_inventory_pull_total`), señal de catálogo encogido. **No** se resetea por TTL. El
caso (1) ya lo cubre T7.1.c. Esta micro-tarea agrega (3) dentro del poll y define (2) como parámetro del
AJAX (se cablea en T7.4). REQ-POLL-01/02, NFR-07, R17.

**Desarrollo técnico** — `includes/Sync/Products.php`, justo **después** de leer el cursor en T7.1.b
(después de `$cursor = max(0, (int) get_option($cursor_key, 0));`):

```php
            // FIX-14 (momus C6): el total DEBE venir de la API, no del cursor.
            // Se usa el mismo probe metadata=true que ajax_sync_start
            // (Admin_Dashboard.php:2131-2151). El mock ya devuelve
            // {metadata:{total},data:[]} en esa rama (alegra-mock.php:663-669).
            // Si el probe falla, se conserva el total previo (nunca se resetea
            // por un total stale/0).
            $known_total = max(0, (int) get_option($total_key, 0));
            $total_probe = $this->api->get_items(['limit' => 1, 'metadata' => true, 'mode' => 'advanced']);
            if (is_array($total_probe) && isset($total_probe['metadata']['total'])) {
                $known_total = max(0, (int) $total_probe['metadata']['total']);
                update_option($total_key, $known_total, false);
            }

            // Defensivo (D6 §7.3-3): si el cursor apunta más allá del total
            // conocido, el catálogo se encogió ⇒ arrancar de cero.
            if ($known_total > 0 && $cursor >= $known_total) {
                $cursor = 0;
                delete_option($cursor_key);
            }
```

Y por página, al persistir el cursor (T7.1.b), **solo** se avanza el cursor:

```php
                $cursor += 30;
                update_option($cursor_key, $cursor, false);
                // FIX-14: NO se escribe $total_key acá. Escribirlo como
                // max(total, cursor) dejaba total == cursor ⇒ el guard de arriba
                // (`$cursor >= $known_total`) era siempre verdadero y el cursor
                // se reseteaba en cada corrida. El total es el de la API.
```

> **FIX-14 — quién escribe el total y cuándo resetea el cursor.**
> - **Escribe `alegra_connector_inventory_pull_total`:** el poll, **al inicio de cada corrida**, con el
>   `metadata.total` de la API (probe de arriba). Es el total autoritativo; el cursor nunca lo escribe.
> - **El cursor se resetea a 0 (borrado) en 3 casos** (D6 §7.3): (1) la corrida **completa** el catálogo
>   (`empty($items)` o `count($items) < 30`, lo cubre T7.1.c); (2) el comerciante dispara "Sincronizar
>   inventario" con `from_zero=true` (lo cablea T7.4); (3) `$known_total > 0 && $cursor >= $known_total`
>   (catálogo encogido, defensivo). **No** se resetea por TTL.
> - Si el probe de total falla o no trae metadata, `$known_total` conserva el valor previo y el caso (3)
>   no dispara con un total desconocido (0). El `empty($items)` de (1) sigue cubriendo el catálogo
>   encogido en la práctica.

**Caso (2) `from_zero`:** el parámetro es del **AJAX** (T7.4): si `!empty($_POST['from_zero'])`,
`delete_option('alegra_connector_inventory_pull_cursor')` **antes** de llamar `run_inventory_sync()`. El
poll no necesita un parámetro nuevo (respeta la firma de design §10). Se documenta acá y se implementa
en T7.4.

**Resultado esperado**: un cursor persistido `1500` con `alegra_connector_inventory_pull_total=1000` se
ignora y la corrida arranca en `start=0`; el AJAX con `from_zero=1` borra el cursor y arranca de cero;
un cursor válido **no** se toca.

**Dependencias**: T7.1.b. T7.4 consume el caso (2).

**Trazabilidad**: REQ-POLL-01, NFR-07, R17; design §7.3; hallazgo C13 (momus C6/FIX-14).

**Verificación**: `T29.73` — sembrar cursor 1500 y total 1000 ⇒ el `GET /items` usa `start=0`; con
`from_zero=1` en el AJAX ⇒ `get_option($cursor_key) === false` antes del poll; con el probe de total
devolviendo 1000, `_pull_total` queda en 1000 (no en el cursor).
**Prove-it-catches:** quitar el chequeo `$cursor >= $known_total` → `T29.73` falla.

**Riesgo**: que `_pull_total` quede stale de una corrida anterior más grande → el probe de total lo
refresca al inicio de cada corrida; si el probe falla, solo dispara un reset extra (inofensivo:
re-escanea desde 0). Clave namespaced `alegra_connector_inventory_pull_cursor` (no comparte con el import
de productos) ⇒ R17 cubierto.

**Estimación**: S (0,75 h).

---

### T7.3 — `set_syncing(true/false)` alrededor del poll (sin cascada de push)

**Objetivo**: cortar la cascada de `woocommerce_update_product` → POST por ítem mientras corre el poll.

**Descripción técnica**: con `push_products_enabled=true`, cada `save()` del poll dispara
`woocommerce_update_product` (`Public_.php:73`) → `on_update_product` (`:226-234`). Ese handler **no**
consulta el static `is_syncing()`: solo el transient `alegra_updating_product_{id}` (`:229`). La cascada
la corta `set_syncing(true)`, que escribe el transient **`alegra_import_in_progress`** (`:433`), leído
por `trigger_sync()` (`:371`). Hoy el poll **no** llama `set_syncing` (C5). D6 §8.2; REQ-POLL-04.

**Desarrollo técnico** — `includes/Sync/Products.php`. Declarar `$was_syncing` **después** de adquirir el
lock (`:1163-1168`) y **después** del `register_shutdown_function` de `T8.1`, antes del `try`
(ver **FIX-16** en el orden de operaciones):

```php
        // D6 §8.2: cortar la cascada WC→Alegra mientras escribimos stock.
        // El guard real es el transient `alegra_import_in_progress` (Public_.php:433),
        // que trigger_sync() consulta (:371). Patrón anidado $was_syncing.
        $was_syncing = \Alegra\Connector\Public\Public_::is_syncing();
        if (!$was_syncing) {
            \Alegra\Connector\Public\Public_::set_syncing(true);
        }
```

Dentro del loop, al empezar cada página, **refrescar** el transient (DR8: TTL 300 < poll largo):

```php
                if (!$was_syncing) {
                    set_transient('alegra_import_in_progress', 1, 300);
                }
```

En el `finally` (`:1290-1292`), apagar el flag **antes** de liberar el lock:

ANTES (`:1290-1292`):
```php
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public('products', $lock);
        }
```

DESPUÉS:
```php
        } finally {
            if (!$was_syncing) {
                \Alegra\Connector\Public\Public_::set_syncing(false);
            }
            \Alegra\Connector\Sync\Controller::release_sync_lock_public('products', $lock);
        }
```

> **FIX-2 (B2 / Oracle D2) — el poll debe poder empujar con `set_syncing(true)` activo.**
> El guard `is_syncing()` **no** puede vivir dentro de `push_delta()`: el poll lo llama explícitamente
> para (a) reintentar un `pending` o (b) reconciliar un delta local, y con `set_syncing(true)` el guard
> lo cortaría (`reason='syncing'`), dejando a Alegra sin reconciliar y el `pending` sin limpiar. Además,
> `T29.74` pasa **porque** `push_delta` es un no-op (falso verde). Decisión canónica:
> 1. El guard `is_syncing()` / `alegra_updating_product_{id}` se mueve al **hook** `on_stock_changed()` /
>    `on_variation_stock_changed()` de `Inventory_Pusher` (`fase-3`, `T3.1`): si `is_syncing()` o el
>    transient por producto están activos, el hook **retorna** y no empuja (la cascada del `save()` del
>    poll no se re-dispara).
> 2. `push_delta()` **no** chequea `is_syncing()`; acepta un flag explícito
>    `push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array`.
> 3. La llamada del **poll** (bloque ledger de `T3.4`) pasa `from_poll: true`:
>    `->push_delta($product, (int) $product->get_stock_quantity(), true)`.
> El transient por producto `alegra_updating_product_{id}` se mantiene alrededor del writer del poll
> (defensa en profundidad, C7 de `fase-3`). La fase dueña del pusher (Fase 3) aplica 1–3; acá se fija el
> contrato que consume Fase 7. **Test cross-fase obligatorio:** `T29.76` (con `set_syncing(true)` activo,
> un `pending` se reintenta, el POST **sale** y `pending` se limpia). El `T29.36` de Fase 3 no alcanza:
> corre antes de `T7.3`.

> **FIX-8 (Oracle D3) — el poll no se estrella.**
> El bloque de reconciliación de `T3.4` hace `continue` cuando `push_delta` devuelve
> `disabled` / `invoice_owner` / `not_linked` / `locked` / `not_manageable` ⇒ el poll **nunca** escribe
> WC y el producto queda congelado en **todas** las corridas futuras (p. ej. `push_orders_enabled=true`
> o `push_inventory_enabled=false`). Regla: **el poll SIEMPRE escribe WC desde Alegra cuando es el
> dueño** (es la dirección pull). El `continue` (no pisar WC) sólo se justifica si el push realmente
> puede concretarse o si queda un `pending` en vuelo. Contrato del bloque `T3.4`:
> ```php
> $res = (new Inventory_Pusher($this->api, $this->logger))
>     ->push_delta($product, (int) $product->get_stock_quantity(), true); // from_poll (FIX-2)
> // Sólo saltear el writer si el push se concretó o quedó `pending` para reintentar.
> if (!empty($res['pushed']) || $res['reason'] === 'api_error') {
>     continue;   // no pisar WC; el pending se reintenta la próxima corrida
> }
> // disabled/invoice_owner/not_linked/locked/not_manageable/syncing ⇒ el poll cae al writer
> // (Inventory_Writer::apply) y escribe WC desde Alegra.
> ```
> Para `owner='invoice'` esto elimina el limbo permanente (Oracle D3): el poll sigue reflejando Alegra en
> WC aunque el pusher no emita ajustes. La serialización poll↔pusher por producto la cubre el lock
> `alegra_inventory_push_{id}` (Oracle D8, Fase 3). Se agrega como borde del test `T29.74`.

**Orden de operaciones (FIX-16):** adquirir lock (`:1163-1168`) → **`register_shutdown_function` de
`T8.1`** (primero: cubre un fatal ocurrido en cualquier punto posterior) → calcular `$was_syncing` →
`set_syncing(true)` si no estaba → `try { loop (refresh transient por página) } finally { set_syncing(false)
si lo pusimos; release lock }`. El handler de `T8.1` va **antes** del bloque `$was_syncing` de esta tarea;
`T8.1` documenta su inserción como "inmediatamente después del lock, antes del `try` de `T7.3`".

**Resultado esperado**: con `push_products_enabled=true`, un poll que guarda N productos **no** emite un
POST a Alegra por ítem; el número de requests no crece con el catálogo; `is_syncing()` vuelve a `false`
al terminar (incluso con error).

**Dependencias**: T7.1.b (el loop debe existir). `Public_.php` ya tiene `set_syncing`/`is_syncing`
(`:426-437`, `:442`); **no se modifica**.

**Trazabilidad**: REQ-POLL-04, NFR-02; design §8.2; hallazgos C5/C6; DR8/DR9.

**Verificación**: `T29.74` — con el hook de push registrado y `push_products_enabled=true`, correr el
poll sobre N ítems y assertar que el contador de POST del mock es 0; borde anidado: con
`set_syncing(true)` previo, tras el poll el transient **sigue** presente (no lo apaga el poll); borde
**FIX-8**: con un producto divergente y `push_inventory_enabled=false` (`reason='disabled'`), el poll
**igual** escribe WC desde Alegra (no se estrella).
**Test cross-fase `T29.76` (FIX-2):** con `set_syncing(true)` activo y un `pending` sembrado, el poll
llama `push_delta(..., from_poll: true)`, el `POST /inventory-adjustments` **sale**, `pending` se limpia
y `synced` queda en el valor de WC. **Prove-it-catches:** dejar el guard `is_syncing()` dentro de
`push_delta` → `T29.76` ve `reason='syncing'`, 0 POST y `pending` intacto ⇒ falla. Quitar
`set_syncing(true)` → `T29.74` ve N POSTs y falla.

**Riesgo**: si el poll dura > 300 s y no se refresca, el transient expira y la cascada vuelve (DR8) →
mitigado por el refresh por página. Si el host no soporta transients (object cache raro), el static
`$is_syncing` igual protege el request actual.

**Estimación**: S (1 h).

---

### T7.4 — Surfacear `truncated`/`completed`/`cursor`/`pages` + `message` en el AJAX

**Objetivo**: que el botón "Sincronizar inventario" muestre el resultado honesto (parcial vs completo) y acepte `from_zero`.

**Descripción técnica**: `ajax_sync_inventory` (`Admin_Dashboard.php:1811-1835`) solo responde `updated`
(C7); con una corrida truncada el comerciante cree que terminó. NFR-04 exige mantener `success`/`data`
y agregar `message`; REQ-POLL-02 exige surfacear `truncated`. El `from_zero` de T7.2.b se cablea acá.
D6 §7.4.

**Desarrollo técnico** — `admin/Admin/Admin_Dashboard.php`, método `ajax_sync_inventory()` (`:1811-1835`):

ANTES (`:1818-1834`):
```php
        $result = (new Sync\Controller($this->api, $this->logger))->run_inventory_sync();

        if (!empty($result['skipped'])) {
            wp_send_json_error(['message' => __('La copia de inventario está desactivada: la fuente de inventario es WooCommerce.', 'alegra-connector')]);
        }
        if (!empty($result['locked'])) {
            wp_send_json_error(['message' => __('Ya hay una sincronización en curso. Intenta de nuevo en unos segundos.', 'alegra-connector')]);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products whose stock was updated */
                __('Inventario sincronizado: %d productos actualizados.', 'alegra-connector'),
                (int) ($result['updated'] ?? 0)
            ),
            'updated' => (int) ($result['updated'] ?? 0),
        ]);
```

DESPUÉS:
```php
        // T7.2.b-2: "desde cero" borra el cursor antes de la corrida.
        if (!empty($_POST['from_zero'])) {
            delete_option('alegra_connector_inventory_pull_cursor');
            delete_option('alegra_connector_inventory_pull_total');
        }

        $result = (new Sync\Controller($this->api, $this->logger))->run_inventory_sync();

        if (!empty($result['skipped'])) {
            wp_send_json_error(['message' => __('La copia de inventario está desactivada: la fuente de inventario es WooCommerce.', 'alegra-connector')]);
        }
        if (!empty($result['locked'])) {
            wp_send_json_error(['message' => __('Ya hay una sincronización en curso. Intenta de nuevo en unos segundos.', 'alegra-connector')]);
        }

        $truncated = !empty($result['truncated']);
        $message = $truncated
            ? sprintf(
                /* translators: 1: updated count, 2: resume cursor */
                __('Inventario sincronizado parcialmente: %1$d productos actualizados. Quedó trabajo pendiente (cursor %2$d); volvé a ejecutar para continuar.', 'alegra-connector'),
                (int) ($result['updated'] ?? 0),
                (int) ($result['cursor'] ?? 0)
            )
            : sprintf(
                /* translators: %d: number of products whose stock was updated */
                __('Inventario sincronizado: %d productos actualizados.', 'alegra-connector'),
                (int) ($result['updated'] ?? 0)
            );

        wp_send_json_success([
            'message'   => $message,
            'updated'   => (int) ($result['updated'] ?? 0),
            'truncated' => $truncated,
            'completed' => !empty($result['completed']),
            'cursor'    => (int) ($result['cursor'] ?? 0),
            'pages'     => (int) ($result['pages'] ?? 0),
        ]);
```

**Orden de operaciones:** guard nonce+cap (sin cambios) → `from_zero` → `run_inventory_sync()` →
gates `skipped`/`locked` → armar `message` según `truncated` → `wp_send_json_success` con los 6 campos.

**Resultado esperado**: respuesta AJAX con `success:true` y `data:{message,updated,truncated,completed,cursor,pages}`;
con truncación, `message` dice "parcialmente" y el cursor; sin truncación, el mensaje actual.

**Dependencias**: T7.1.c, T7.2.b, T7.5. **Opcional (fuera de alcance):** un toggle JS "desde cero" en la
vista de Productos; el AJAX ya acepta el parámetro.

**Trazabilidad**: REQ-POLL-02, NFR-04; design §7.4; hallazgo C7 (corrección #26 de `tasks.md`).

**Verificación**: `T29.75` — capturar la respuesta JSON y assertar las 6 claves; con budget bajo,
`truncated===true` y `message` contiene "parcialmente". **Prove-it-catches:** volver el payload a solo
`updated` → `T29.75` falla.

**Riesgo**: bajo. `$_POST['from_zero']` se lee sin sanitizar como booleano (patrón del repo para flags).

**Estimación**: S (1 h).

---

### T7.5 — `run_inventory_sync()` propaga el deadline

**Objetivo**: que el entry point manual/cron del poll acepte y reenvíe el deadline global.

**Descripción técnica**: `run_inventory_sync()` (`Controller.php:110-115`) llama al poll **sin** args
(C8). T8.2 necesita propagarle el deadline del cron; el AJAX pasa `0.0`. D6 §7.2; design §8.3; REQ-POLL-01/06.

**Desarrollo técnico** — `includes/Sync/Controller.php`:

ANTES (`:110-115`):
```php
    public function run_inventory_sync(): array
    {
        $result = $this->products->sync_inventory_from_alegra();
        $this->logger->info('Inventory pull triggered', $result);
        return $result;
    }
```

DESPUÉS:
```php
    public function run_inventory_sync(float $deadline = 0.0): array
    {
        $result = $this->products->sync_inventory_from_alegra(0, $deadline);
        $this->logger->info('Inventory pull triggered', $result);
        return $result;
    }
```

**Call sites:** `Admin_Dashboard.php:1818` (`run_inventory_sync()` → `0.0`, sin cambio); `Controller.php:211`
(el cron pasa `$deadline` en T8.2).

**Resultado esperado**: `run_inventory_sync(123.0)` llega al poll como `$deadline = 123.0`; sin argumento
equivale a `0.0` (sin deadline externo).

**Dependencias**: T7.1.a. Habilita T8.2.

**Trazabilidad**: REQ-POLL-01, REQ-POLL-06; design §8.3; hallazgo C8 (corrección #27 de `tasks.md`).

**Verificación**: `T29.75b` — un spy/observer sobre `sync_inventory_from_alegra` confirma que el 2º
argumento recibido es el deadline pasado. **Prove-it-catches:** dejar la firma sin args → `T29.75b` falla.

**Riesgo**: nulo (parámetro opcional).

**Estimación**: S (0,25 h).

---

### T7.6 — Tests del poll

**Objetivo**: cubrir pausa por budget, reanudación por cursor, `truncated`, reset y ausencia de cascada.

**Descripción técnica**: los tests viven en `scripts/exec-test.php`, sección
`// === sync-reliability (2.6.0) ===` (creada en T1.11). El mock de Alegra (`scripts/lib/alegra-mock.php`)
ya permite sembrar ítems y contar requests; `T1.4` (H4/H6) agrega la ruta `/inventory-adjustments` y el
modelo de stock; `T1.6` (H7) hace observable `set_syncing`. Baseline del harness: **1626** aserciones.
REQ-POLL-01/02/04, NFR-02/07.

**Desarrollo técnico** — agregar en `scripts/exec-test.php` los **8 IDs canónicos** (`T29.71`,
`T29.72`, `T29.72b`, `T29.73`, `T29.74`, `T29.75`, `T29.75b`, `T29.76`):

1. `T29.71` **pausa por budget + reanuda por cursor** (requiere el fix de harness de FIX-13):
   ```php
   TestRunner::test('T29.71 el poll pausa por budget y reanuda por cursor', function (): void {
       alegra_test_reset();
       // Sembrar > 1 página de ítems (p.ej. 45) y bajar el budget.
       update_option('alegra_connector_inventory_poll_budget', 10, false);
       // ... correr el poll con un deadline vencido a mitad ...
       $r1 = $products->sync_inventory_from_alegra();
       TestRunner::assertTrue($r1['truncated'], 'debe truncar por budget');
       $cursor = (int) get_option('alegra_connector_inventory_pull_cursor', 0);
       TestRunner::assertTrue($cursor > 0, 'debe persistir el cursor');
       $r2 = $products->sync_inventory_from_alegra();
       TestRunner::assertSame($cursor, $r2['cursor'] - 30, 'la 2ª corrida arranca desde el cursor');
   });
   ```
2. `T29.72` **`truncated` + log** (T7.1.c).
3. `T29.72b` **source-scan sin `set_time_limit`** en el cuerpo del poll (T7.2.a).
4. `T29.73` **reset semantics** (cursor >= total ⇒ start 0; `from_zero` ⇒ borra cursor).
5. `T29.74` **sin cascada** (`set_syncing`; 0 POST con `push_products_enabled=true`; borde anidado;
   borde **FIX-8**: con `push_inventory_enabled=false` (`reason='disabled'`) el poll **igual** escribe WC).
6. `T29.75` **AJAX surfacea** los 6 campos.
7. `T29.75b` **deadline propagado** por `run_inventory_sync` (T7.5).
8. `T29.76` **cross-fase FIX-2**: con `set_syncing(true)` activo y un `pending` sembrado, el poll llama
   `push_delta(..., from_poll: true)`, el POST **sale**, `pending` se limpia y `synced` queda en WC.

> **Prerequisito de harness (FIX-13 / Oracle D7).** El mock **no** pagina `GET /items` sin
> `metadata=true`: `alegra-mock.php:659-671` sólo aplica `array_slice($filtered, $start, $limit)` en la
> rama `metadata=true` y, sin metadata, devuelve **todo** el catálogo filtrado (`:671`). El poll llama
> sin metadata ⇒ `count($items) >= 30` siempre y `T29.71`/`T29.73` **no prueban la paginación** hasta que
> Fase 1 (dueña de `scripts/lib/alegra-mock.php`) aplique `start`/`limit` también en la rama sin
> metadata, igual que `T1.3` hizo para `/contacts` (`fase-1:391-394`). **Sin ese fix los tests de cursor
> son inválidos.** Queda como prerequisito de Fase 7 en el DoD.

**Resultado esperado**: `bash scripts/exec-test.sh` verde con `T29.71`–`T29.76` incluidos; cada test con
su prove-it-catch documentado en `docs/RELEASE_2.6.0_VERIFICATION.md` (T9.6).

**Dependencias**: T7.1.a–T7.5, T1.4, T1.6, T1.11.

**Trazabilidad**: REQ-POLL-01/02/04; NFR-02/07; matriz R10/R11/R17.

**Verificación**: la corrida del harness; el prove-it-catch de cada test es la verificación dura.

**Riesgo**: tests que pasan por casualidad (falsos verdes) → mitigado por prove-it-catches. **FIX-13:** el
mock **no** respeta `start`/`limit` en `GET /items` sin `metadata=true` (`alegra-mock.php:659-671`); es un
prerequisito de Fase 1 (dueña del mock). Hasta que se aplique ese fix, `T29.71`/`T29.73` no prueban la
paginación y son inválidos.

**Estimación**: M (2,5 h).

---

## DoD Fase 7 (checklist de cierre)

- [ ] `T7.1.a`: firma `(int $run_id = 0, float $deadline = 0.0)` y `$result` con `truncated/completed/cursor`.
- [ ] `T7.1.b`: `while` con `own_deadline` + cursor persistido por página; arranca desde el cursor;
      `get_items` con `order_field=id`/`order_direction=ASC` (FIX-15) o limitación documentada.
- [ ] `T7.1.c`: `truncated=true` + `warning` con cursor; `completed` borra el cursor.
- [ ] `T7.2.a`: `grep set_time_limit includes/Sync/Products.php` no aparece en el poll.
- [ ] `T7.2.b`: reset al completar / `from_zero` / total encogido; `pull_total` escrito **desde el
      `metadata.total` de la API** (no desde el cursor) — FIX-14.
- [ ] `T7.3`: `$was_syncing` + refresh del transient por página; `set_syncing(false)` en el `finally`;
      `push_delta(..., from_poll: true)` (FIX-2); el poll **no** se estrella (FIX-8); orden de inserción
      tras `T8.1` (FIX-16).
- [ ] `T7.4`: AJAX devuelve `truncated/completed/cursor/pages` + `message`; acepta `from_zero`.
- [ ] `T7.5`: `run_inventory_sync(float $deadline = 0.0)`.
- [ ] `T7.6`: los **8 IDs canónicos** (`T29.71`, `T29.72`, `T29.72b`, `T29.73`, `T29.74`, `T29.75`,
      `T29.75b`, `T29.76`) verdes con prove-it-catches.
- [ ] **Prerequisito Fase 1 (FIX-13):** `GET /items` del mock pagina sin `metadata` (dueño: Fase 1,
      `scripts/lib/alegra-mock.php`).
- [ ] **Cross-ref Fase 4 (FIX-17):** `variantParent_id` + `limit=100` verificados en G2 (`T4.1`); no es
      de Fase 7.
