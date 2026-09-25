# Fase 2 — Instrumentar los caminos de importación (lock release + early exits)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Fase | 2 de 8 — instrumentación de los 4 caminos (manual / chunked / cron / webhook) |
| Tareas | T2.1 · T2.2 · T2.3 · T2.4 · T2.5 |
| Depende de | **Fase 1 completa** (`T1.1a`, `T1.1b`, `T1.2`, `T1.3`, `T1.3b`, `T1.4`, `T1.5`). **T2.4** además `BLOQUEADO(Fase 0.4 / G4)` |
| DoD de la fase | REQ-MON-01/02/04 · REQ-LOG-01/02 · REQ-IMP-02 · REQ-CON-01 · NFR-02 verdes; el lock **nunca** queda tomado por un `wp_send_json_error`; `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes |
| Documentos base | `proposal.md` · `spec.md` · `design.md` §2.2/§2.3/§3.2/§7 · `tasks.md` Fase 2 |
| Versión objetivo | 2.5.0 |

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T28.2{n}`
> (`T28.21`, `T28.22`, …) según la convención `T28.{fase}{n}`. Reemplaza los nombres `T2.x` de los
> borradores previos; los IDs de **tarea** (`T2.1`…`T2.5`) no cambian.

> **Regla de oro de esta fase.** El hallazgo de plataforma del design §0 es la restricción que
> manda: `wp_send_json_success/error()` → `wp_die()` → `die()`, y **`die()` no ejecuta `finally`**.
> Por eso, en **cada** handler con lock, el cierre del run y la liberación del lock van **antes**
> del `wp_send_json_*`; el `finally` queda **solo** para excepciones. Ver §Correcciones para el
> test que hace cumplir esto de verdad (el test propuesto en `tasks.md` **no lo cumple**).

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

Además de las correcciones ya documentadas en `tasks.md` (que se confirman abajo), al escribir
estas micro-tareas aparecen **7 hallazgos nuevos** que cambian el plan de test. Se listan primero
porque varias tareas dependen de ellos.

| # | Claim (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `tasks.md` T2.4, `Prove-it-catches`: "mover el `release_sync_lock_public` de vuelta al `finally` → el test de 'lock libre' falla (el mock de `wp_send_json_error` corta el request como `die()`)" | **Falso.** El stub `wp_send_json_error()` (`scripts/lib/wp-stubs.php:546-549`) **lanza `Alegra_Test_JSON_Response`**, una `\Exception`. PHP **sí ejecuta los `finally`** cuando una excepción se propaga. Mover el release al `finally` **igual libera el lock** y el test pasa con el bug. | **T2.4 necesita un cambio de harness (H1.b)**: el stub invoca un observer **antes** de lanzar, para poder fotografiar el estado del lock en el instante del "send". Sin esto, la regla de oro no es testeable. |
| C2 | `tasks.md` T2.3/T2.4/T3.2: asertar "fila `running`/`failed`/`completed`" | `Alegra_Mock_Wpdb::insert()` (`wp-stubs.php:1725-1731`) solo hace `$GLOBALS['alegra_db'][$table][] = $data`; `update()` (`:1733-1736`) es **no-op**; `get_var()` (`:1592-1626`) y `get_results()` (`:1655-1671`) devuelven `null`/`[]` para `alegra_runs`. ⇒ `Runs::status()`, `Runs::finish()` y `Runs::currently_running()` **no se pueden observar**. | **H1.a (= `T1.1a`, Fase 1)**: extender `Alegra_Mock_Wpdb` para persistir `wp_alegra_runs` (insert con `id`, update por `id`, `get_var`/`get_results`). Sin esto, ninguna aserción de ciclo de vida del run es escribible. |
| C3 | — | `Controller::$held_sync_locks` (`Controller.php:451`) es `private static` y **no se resetea** en `alegra_test_reset()` (`test-framework.php:144-208`). Un test que deja una entrada (o que simula contención con `acquire_sync_lock_public()`) contamina el siguiente. | **H1.c**: agregar `Controller::reset_locks_for_testing()` y llamarlo en `alegra_test_reset()`. Necesario para simular "lock ocupado" de forma fiable (ver T2.4, §Verificación). |
| C4 | `design.md` §3.2 tabla de early-exits de `ajax_sync_start` | La tabla **no cubre** el `WP_Error` de la llamada `metadata=true` (`Admin_Dashboard.php:2040-2042` y `:2048-2050`). Hoy se ignora (`$total=0`); con `Run_Context::begin()` antes, un `WP_Error` dejaría la fila **`running` para siempre**. | **T2.3 agrega** el cierre `finish(failed, …)` + `wp_send_json_error(message)` para ese `WP_Error`. Es un gap del design, no una opción. |
| C5 | `design.md` §4.1: el estado del chunked pasa de `page` a `start`+`offset` | Los tests existentes `T21.5` (`exec-test.php:3523-3541`) y `T-RB-5` (`:5257-5274`) siembran `alegra_batch_state` con `'page' => 0` y **sin** `start`/`offset`. | **T2.3/T3.1 deben leer con default**: `$start = (int)($state['start'] ?? (($state['page'] ?? 0) * $per_page))`, `$offset = (int)($state['offset'] ?? 0)`. Así los tests viejos siguen verdes; no hay que reescribirlos. |
| C6 | `tasks.md`/fase-2 T2.3: el guard de conexión nuevo de `ajax_sync_start` es inocuo para el harness | **Falso (Momus B1).** `alegra_test_reset()` (`test-framework.php:144-208`) **no** siembra `alegra_connector_connection_tested`; el stub `get_option()` devuelve el default (`false`) si la clave no existe (`wp-stubs.php:193-198`). El test base **`T21.4`** (`exec-test.php:3503-3517`) llama `ajax_sync_start()` **sin** la opción y asserta `assertTrue($resp->success)` → con el guard queda **RED**. El propio `T28.28` (fase-2:780-783) tampoco la siembra. | **T2.3 debe sembrar `update_option('alegra_connector_connection_tested', true)` en `T21.4` y en `T28.28`.** Se elige el seed **explícito** (no tocar `alegra_test_reset()`) porque es quirúrgico: cambiar el default de las 1289 aserciones a "conectado" es superficie amplia y puede enmascarar el camino "no conectado" (que T28.26/T28.210 ejercitan borrando la opción). |
| C7 | fase-2 T2.2: `$result['paused']` "lo setea `Products.php:1305`" y es la señal de stop | **Falso (Momus H1).** `:1305` es la **pausa por presupuesto de 240 s** (`if (microtime(true) >= $deadline) { $result['paused'] = true; }`, `:1304-1309`). El stop del usuario hace `break`/`break 2` **sin** tocar `paused`: `:1298-1301` (por página) y `:1353-1356` (por ítem). | **T2.2 debe separar las dos señales**: stop del Monitor → `cancelled`; pausa por presupuesto → `paused` (reanudable, REQ-RES-04). Se ajustan el contrato K3, el `DESPUÉS` y los tests. |

**Confirmaciones (no requieren corrección).**

- `tasks.md` corrección #1: `Admin_Dashboard.php:2024` es `$this->api->reload_credentials();` — **confirmado**. `ajax_sync_start` (`:2018-2068`) **no tiene** guard de conexión.
- `design.md` corrección #3 (T2.5): `process_event()` se **define** en `Handlers.php:26`; `Receiver.php:173` lo **invoca**. El wrap va en `Receiver::handle()` alrededor de `:171-173`.
- `design.md` corrección #4: `Products.php:1318` es la **condición**; `:1319` es el `set_transient(...)`. T3.2 usa `:1319`.
- `Controller.php:150` es el **único** `Runs::track` (verificado por grep). `Heartbeat::set` solo en `Controller.php:173,212,240,266,277`.
- `Products.php:1353-1356` (stop por ítem), `:1429` (tombstone), `:2125-2130` (allowlist), `:2136-2158` (`is_allowed_image_url`) — confirmados.
- `Admin_Dashboard.php:2078-2079` (batch-state ausente), `:2084-2092` (cancel), `:2099-2102` (lock), `:2105` (`set_time_limit(60)`), `:2115-2116` (`WP_Error /items`), `:2138` (`import_single_item_public`), `:2148`/`:2184` (`WP_Error` customers/categories), `:2222-2224` (`finally`) — confirmados.
- `Admin_Dashboard.php:4073-4116` (`ajax_import_from_api`), `:4081-4083` (guard conexión), `:4086-4088` (tipo inválido), `:4094-4097` (lock), `:4100` (llamada al Controller), `:4113-4115` (`finally`) — confirmados.
- `admin.js:220`, `:238`, `:278` (ramas mudas), `:25-33` (`showNotice` inyecta en `.alegra-connector-wrap`), `:184-188` (`cleanup` solo hace `$modal.hide()`) — confirmados.
- `Runs.php:95-118` (`finish` no filtra por `status`), `:123-134` (`request_stop`/`should_stop`), `:174-185` (`currently_running`), `Heartbeat.php:66-71` (`clear` borra `alegra_run_stop_`) — confirmados.

---

## Contratos canónicos (los define esta fase; Fases 4/5 los **consumen**, nunca los redefinen)

Estos tres contratos cierran **C1 (Momus)**, el defecto de `run_type` (**Oracle D11**) y el estado
terminal de los stops (**Oracle D6**). Son la **única** fuente de verdad: cualquier fase posterior
que toque `$state` o `fail_early` referencia acá en vez de re-listar el array o inventar un alias.

### K1 — Enum canónico de `run_type` (contrato del Monitor)

`Runs::start()` **no valida** el tipo (`includes/Runs.php:46-69`); el único valor que el plugin
produce hoy es `cron_sync_all` (`Controller.php:150`). El contrato del Monitor (design §2.1) fija
estos valores y el label map de `admin-monitor.php` (design §2.7) los reconoce:

| `run_type` | Origen | Label del Monitor |
|---|---|---|
| `cron_sync_all` | cron (existente, `Controller.php:150`) | Cron |
| `manual_import` | página Importar (`ajax_import_from_api`) | Manual |
| `chunked_import` | botón "Traer desde Alegra" (`ajax_sync_start`/`ajax_sync_page`) | Chunked |
| `webhook_item` / `webhook_client` / `webhook_invoice` | webhooks (`Receiver`) | Webhook |

Reglas duras:
- `Run_Context::begin()` / `wrap()` / `resume()` **DEBEN** recibir uno de estos valores.
- `Run_Context::fail_early($origin, …)` **DEBE** usar la forma canónica larga (`manual_import`,
  `chunked_import`), **no** los alias cortos `manual` / `chunked` (Oracle D11: el label map no los
  reconocería y la fila mostraría el valor crudo).
- El docblock de `Runs.php:24` (`'cron_sync'`, `'push_invoice'`, `'cleanup'`) es **ilustrativo y
  stale**; no es la fuente del enum.

### K2 — Shape canónico de `alegra_batch_state` (dueño único: T2.3)

`ajax_sync_start` (T2.3) es el **único** que construye el array. `ajax_sync_page` (T2.4/T3.1),
`T4.2` y `T5.2` sólo lo **leen** y mutan claves existentes. **Ninguna otra fase lo re-lista ni lo
redefine** (esto es el fix de **C1 de Momus**: el bloque de T4.2 con `page` y sin `images` hacía
desaparecer `$state['images']` y rompía T3.4/T5.2).

```php
$state = [
    'type'        => string,   // 'products' | 'customers' | 'categories'
    'run_id'      => int,      // fila wp_alegra_runs (T2.3)
    'start'       => int,      // cursor ABSOLUTO de ítems (contrato T3.1); 0 en "desde cero"
    'offset'      => int,      // reanudación intra-página; sólo products lo usa (T3.1)
    'per_page'    => int,      // 30 (máximo de la API de Alegra)
    'total_pages' => int,
    'total_items' => int,
    'imported'    => int,
    'updated'     => int,
    'skipped'     => int,
    'errors'      => int,
    'images'      => ['ok'=>int,'blocked'=>int,'download'=>int,'sideload'=>int,'deferred'=>int], // acumulado del run (T5.2)
    'from_zero'   => bool,     // T4.2
    'policy'      => string,   // 'respect' | 'ignore_bulk' | 'ignore_all' (T4.2/T4.4)
    'filters'     => array,    // sanitize_item_filters()
];
```

Notas duras:
- **`page` NO es parte del shape canónico.** Se acepta sólo como **fallback de lectura** para el
  estado viejo (`C5`: `$start = (int)($state['start'] ?? (($state['page'] ?? 0) * $per_page))`),
  pero **no se vuelve a escribir**. El dueño del avance es `start` (T3.1.c lo avanza para los tres
  tipos).
- **`images` siempre está presente** (T2.3 lo inicializa en ceros). `T5.2` sólo lo **mergea**
  (`merge_image_stats`); si una fase lo omite, T3.4/T5.2 rompen con "undefined key".
- `T4.2` **no re-lista** el array: sólo garantiza `policy` y `resuming` (el `resuming` viaja en la
  **respuesta**, no en el state).

### K3 — Estado terminal por camino (cierra Oracle D6)

El stop desde el Monitor (`Runs::request_stop`) y el cancel del botón deben terminar en
`cancelled`, no en `completed`; la pausa por presupuesto termina en `paused`, no en `cancelled`:

| Camino | Condición | `finish` |
|---|---|---|
| manual (`ajax_import_from_api`) | éxito normal | `completed` |
| manual | `WP_Error` de la API | `failed` |
| manual | lock ocupado | `failed` |
| manual | stop del Monitor (`Runs::should_stop($run_id)`) | `cancelled` |
| manual | pausa por presupuesto (`!empty($result['paused'])`, `Products.php:1304-1309`) | `paused` (reanudable, **no** `cancelled`) |
| chunked (`ajax_sync_page`) | `done` | `completed` |
| chunked | cancel (botón) o stop (Monitor) | `cancelled` |
| chunked | `WP_Error` / lock ocupado | `failed` |
| chunked | pausa por presupuesto | **no cierra** (responde `paused:true`) |
| cron (`run_cron_sync_inner`) | ciclo completo | `completed` |
| cron | stop observado | `cancelled` |
| cron | excepción | `failed` (re-lanza) |
| webhook | éxito | `completed` |
| webhook | excepción | `failed` (el `catch` del Receiver igual responde 200) |

`Run_Context::finish` (T1.5) **no re-finaliza** un run ya cerrado, así que el `finish(cancelled)`
del cron/manual es respetado aunque `wrap`/el flujo llame `finish(completed)` después.

> **H1 (Momus).** `$result['paused']` **no** es la señal de stop: es la **pausa por presupuesto**
> (`Products.php:1304-1309`). El stop real (`:1298-1301` por página, `:1353-1356` por ítem) hace
> `break`/`break 2` **sin** tocar `paused`. Conflarlos marcaba un import cortado por presupuesto como
> `cancelled` + "Importación detenida por el usuario" (falso; viola REQ-RES-04). El status `paused`
> es válido: la columna es `status VARCHAR(20) NOT NULL` (`Schema.php:179`), sin enum.
> **Nota de integración (Fase 6):** `statusBadge` (`admin-monitor.php:87-99`) hoy no reconoce
> `paused`; cae al fallback neutral con el string crudo. Fase 6 debe agregar el label `paused`.

---

## Prerrequisito de harness (H1) — hacer **antes** de T2.1

Sin esto, la mitad de las aserciones de la fase no son escribibles. **No** es una tarea de producto;
vive en `scripts/` (excluido del ZIP de release). **`H1.a` es sólo alias de `T1.1a` (Fase 1)** —dueño
único del modelo de `wp_alegra_runs`—; `H1.b`/`H1.c` sí se implementan en esta fase.

### H1.a — Alias de `T1.1a` (modelo de `wp_alegra_runs`) — **NO** re-especificar

> **Dueño único: `T1.1a` (Fase 1).** El modelo de `wp_alegra_runs` en `Alegra_Mock_Wpdb` lo define
> **entero** `fase-1-cimientos.md` §`T1.1a`: `insert()` con `id` autogenerado, `update()` merge por
> `where` (respeta `status='running'`), `get_var()` = `SELECT status … WHERE id=N`, `get_results()`
> con filtros `status`/`run_type`/`started_at`/`LIMIT`, y `query()` = `UPDATE … status='stale' …
> started_at < cutoff` (respeta el cutoff). Ver también `tasks.md` §4 (tabla canónica de seams).
>
> **`H1.a` es sólo un alias**, no una fuente paralela. Esta fase **no** vuelve a especificar el modelo
> ni lo extiende: **está prohibido** crear un segundo store (`$GLOBALS['alegra_runs']`) o redefinir
> `insert`/`update`/`get_var`/`get_results`/`query`.

**Deltas aditivos que Fase 2 necesita: NINGUNO.** Verificado contra `includes/Runs.php` en HEAD:

| Uso de Fase 2 | Query real | Cubierto por `T1.1a` |
|---|---|---|
| `Runs::status($id)` (T1.3) | `SELECT status FROM … WHERE id = N` | `get_var()` |
| `Runs::update_progress()` / `Runs::finish()` | `UPDATE … WHERE id = N [AND status='running']` | `update()` (merge por `where`) |
| `Runs::currently_running()` | `SELECT * … WHERE status='running' … LIMIT 50` | `get_results()` (filtro `status` + `LIMIT`) |
| `Runs::recent($n)` | `SELECT * … ORDER BY started_at DESC LIMIT N` | `get_results()` (sin filtro + `LIMIT`) |
| `Runs::mark_stale()` | `UPDATE … SET status='stale' … started_at < cutoff LIMIT 50` | `query()` (respeta cutoff) |
| `Runs::start()` | `INSERT …` + `insert_id` | `insert()` (`id` autogenerado) |

Si al implementar T2.x aparece un query **no** cubierto, la corrección va en **`T1.1a`** (fuente
única), **no** acá.

### H1.b — Observer de `wp_send_json_*` (el "instante del `die()`")

**Archivo:** `scripts/lib/wp-stubs.php` (`:542-553`).

```php
// Global observer: se invoca justo ANTES de lanzar, emulando el punto exacto
// en el que WP llama wp_die()->die(). Un test registra un callable para
// fotografiar el estado (p. ej. el lock) en ese instante.
$GLOBALS['alegra_test_json_observer'] = null;

function wp_send_json_success($data = null, $status_code = null)
{
    $payload = is_array($data) ? $data : ['data' => $data];
    if (is_callable($GLOBALS['alegra_test_json_observer'] ?? null)) {
        ($GLOBALS['alegra_test_json_observer'])(true, $payload);
    }
    throw new Alegra_Test_JSON_Response(true, $payload);
}
function wp_send_json_error($data = null, $status_code = null)
{
    $payload = is_array($data) ? $data : ['data' => $data];
    if (is_callable($GLOBALS['alegra_test_json_observer'] ?? null)) {
        ($GLOBALS['alegra_test_json_observer'])(false, $payload);
    }
    throw new Alegra_Test_JSON_Response(false, $payload);
}
```

Y en `alegra_test_reset()` (`test-framework.php:144`) agregar
`$GLOBALS['alegra_test_json_observer'] = null;`.

### H1.c — Reset del registry de locks re-entrantes

**Archivo:** `includes/Sync/Controller.php` (junto a `acquire_sync_lock_public`, `:511-525`):

```php
/** Solo para el harness: limpia el registry re-entrante entre tests. */
public static function reset_locks_for_testing(): void
{
    self::$held_sync_locks = [];
}
```

Y en `alegra_test_reset()`: `\Alegra\Connector\Sync\Controller::reset_locks_for_testing();`.

> **Por qué importa para "lock ocupado".** `acquire_sync_lock_public()` es **re-entrante dentro del
> mismo request** (`Controller.php:464-481`): si el test toma el lock con esa API y **no** lo
> libera, el handler vuelve a entrar y **no** ve contención. Para simular **otro proceso**, el test
> debe crear el option crudo `alegra_lock_alegra_sync_running_products` directamente
> (`add_option(...)`), **no** llamar a `acquire_sync_lock_public()`. El nombre exacto del option
> sale de `Controller.php:407` (`'alegra_lock_' . $key`) + `:466` (`'alegra_sync_running_' . $type`).

---

### T2.1 — `Controller::import_from_alegra($type,$run_id)` + cron vía `Run_Context::wrap`

**Objetivo**: que el cron deje de usar `Runs::track` directo y pase por el mismo `Run_Context` que
el resto de los caminos, y que `run_id` llegue a los importadores de las 3 entidades para que el
stop por Monitor funcione.

**Descripción técnica**: hoy el cron es el **único** camino instrumentado (`Controller.php:150`,
`Runs::track`), pero `import_from_alegra()` (`:379-391`) no propaga ningún `run_id` a
`Products/Customers/Categories::import_from_alegra()`, así que el `should_stop($run_id)` de
`Products.php:1298,1353` (y sus pares de Customers/Categories) **nunca** ve el `run_id` del cron.
Además el gate `sync_products=false` (`:171`) saltea productos **sin log** (hallazgo A2/B10,
REQ-CON-01). Esta tarea cubre D1 (§2.2) + D6 (§7).

**Desarrollo técnico**:

Archivos: `includes/Sync/Controller.php`.

1. **`use` nuevo.** Tras `use Alegra\Connector\Runs;` (`:16`) agregar
   `use Alegra\Connector\Run_Context;`.

2. **`run_cron_sync()` (`:148-155`)** — ANTES:

```php
        try {
            // Wrap entire run in Runs::track for the Monitor
            Runs::track('cron_sync_all', function ($run_id) {
                $this->run_cron_sync_inner($run_id);
            }, 'cron');
        } finally {
            self::release_lock('alegra_cron_global', $global_lock);
        }
```

DESPUÉS:

```php
        try {
            // D1: mismo contrato que Runs::track (start → fn → completed /
            // failed+rethrow), pero centralizado en Run_Context (Runs + Heartbeat
            // + Logger). El global lock se libera en el finally.
            Run_Context::wrap('cron_sync_all', function ($run_id) {
                $this->run_cron_sync_inner($run_id);
            }, 'cron');
        } finally {
            self::release_lock('alegra_cron_global', $global_lock);
        }
```

`Run_Context::wrap` re-lanza en fallo (design §2.1); `run_cron_sync` no tiene `catch`, igual que
hoy con `Runs::track`. **No** agregar `catch`: el contrato de negocio no cambia (NFR-02, R1).

3. **Gate `sync_products` (`:171-194`)** — agregar el `else` (hoy no existe) **después** del bloque
   que cierra en `:194`:

```php
        } else {
            $this->logger->info('Cron sync: products skipped by configuration (sync_products=false)');
            $result['products_skipped'] = 'config';
        }
```

El bloque de inventario (`:196-208`) queda **intacto**: es independiente del flag y corre siempre.

4. **`import_from_alegra()` (`:379-391`)** — ANTES:

```php
    public function import_from_alegra(string $type): array|\WP_Error
    {
        switch ($type) {
            case 'products':
                return $this->products->import_from_alegra();
            case 'customers':
                return $this->customers->import_from_alegra();
            case 'categories':
                return $this->categories->import_from_alegra();
            default:
                return new \WP_Error('unknown_type', 'Unknown import type: ' . $type);
        }
    }
```

DESPUÉS:

```php
    public function import_from_alegra(string $type, int $run_id = 0): array|\WP_Error
    {
        switch ($type) {
            case 'products':
                return $this->products->import_from_alegra(1, 30, $run_id);
            case 'customers':
                return $this->customers->import_from_alegra(1, 30, $run_id);
            case 'categories':
                return $this->categories->import_from_alegra($run_id);
            default:
                return new \WP_Error('unknown_type', 'Unknown import type: ' . $type);
        }
    }
```

Firmas destino ya existentes (verificadas): `Products::import_from_alegra(int $page=1,int $per_page=30,int $run_id=0)`
(`Products.php:1248`), `Customers::import_from_alegra(int $page=1,int $per_page=30,int $run_id=0)`
(`Customers.php:180`), `Categories::import_from_alegra(int $run_id=0)` (`Categories.php:63`).

5. **Stop del cron → `cancelled` (K3, Oracle D6)** — hoy los tres `should_stop` de
   `run_cron_sync_inner` hacen `return;` pelado (`:175-178`, `:214-217`, `:242-245`), y como
   `Run_Context::wrap` cierra `completed` al volver, un "Detener" del Monitor sobre el cron queda
   como **Completado**. Cambiar cada `return;` por un cierre explícito. ANTES:

```php
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during products');
                return;
            }
```

   DESPUÉS (idéntico patrón en products `:175`, customers `:214`, categories `:242`, ajustando el
   mensaje de cada uno):

```php
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during products');
                Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                return;
            }
```

   El `finish(completed)` posterior de `wrap` es **no-op** por el guard de `Runs::status()` (T1.5),
   así que la fila queda `cancelled`. `run_cron_sync_inner` ya tiene `$run_id` en scope.

**Orden de operaciones**: el `else` de log va **antes** del bloque de inventario; el `import_from_alegra`
del Controller se cambia en un solo commit con T2.2 (que es su otro llamador) para no romper la firma.

**Resultado esperado (aceptación verificable)**:
- `bash scripts/exec-test.sh` verde; existe fila `cron_sync_all` con `status='completed'` tras
  `make_controller()->run_cron_sync()` (leída vía `Runs::status()` — requiere H1.a).
- Con `alegra_connector_sync_products=false`, el archivo de log contiene
  `products skipped by configuration` y `$result['products_skipped']==='config'`.
- `make_controller()->import_from_alegra('products', 7)` propaga `7`: el mock registra el stop
  (`Runs::request_stop(7)` + un item → `should_stop(7)` corta).

**Dependencias**: Fase 1 (T1.1b `Run_Context::wrap`, T1.3 `Runs::status`). H1.a para las aserciones
de fila.

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-CON-01, NFR-02.

**Verificación**:
1. `bash scripts/exec-test.sh` → `EXEC-TEST OK`.
2. Test nuevo `T28.21 cron wrap crea run completed`: `alegra_test_reset(); make_controller()->run_cron_sync();`
   → `assertSame('completed', \Alegra\Connector\Runs::status(1))`.
3. Test nuevo `T28.22 skip logueado`: `update_option('alegra_connector_sync_products', false);`
   `make_controller()->run_cron_sync();` → leer el `*.log` en `wp_upload_dir()['basedir'].'/alegra-logs'`
   y `assertStringContains('products skipped by configuration', $contenido)`.
4. Test nuevo `T28.23 propaga run_id`: `Runs::request_stop(7); $r = make_controller()->import_from_alegra('products', 7);`
   → `assertTrue($r['paused'] === false && ...)` y `alegra_mock_count('GET','/items')` acotado
   (corta por stop, no recorre todo).
5. Test nuevo `T28.24 stop del cron cierra cancelled (K3/D6)`: con `sync_products=true`,
   `Runs::request_stop(1)` (el primer run insertado será id=1) → `make_controller()->run_cron_sync()`
   → el `should_stop` de products (`:175`) dispara → `assertSame('cancelled', Runs::status(1))`.
6. **Prove-it-catches**: quitar el `else` de log → (3) falla. Volver a `Runs::track` → (2) falla
   solo si H1.a modela runs; si no, el assert de estado no es observable. Quitar el
   `Run_Context::finish(cancelled)` de los `should_stop` del cron → (5) falla (queda `completed`).

**Riesgo**: que `wrap` cambie el resultado del cron (R1 de `tasks.md`). **Guarda**: `wrap` es
copia exacta de `track` (`Run_Context` §2.1); test (2) cubre el ciclo completo; T7.3 cubre las 4
etapas.

**Estimación**: M (3 h).

---

### T2.2 — `ajax_import_from_api` instrumentado (ruta manual)

**Objetivo**: que la ruta manual de la página Importar cree/cierre su fila `manual_import`, loguee
sus salidas tempranas y **libere el lock antes** de cada `wp_send_json_*`.

**Descripción técnica**: hoy (`Admin_Dashboard.php:4073-4116`) la ruta tiene el lock correcto pero
**ningún** `Runs`/`Heartbeat`/log de salida temprana (hallazgo A2). El `finally` (`:4113-4115`) es
la única liberación; por el hallazgo de plataforma (`die()` saltea `finally`) el lock queda tomado
hasta el TTL de 300 s en los `wp_send_json_error` de `:4082,4087,4096,4103`. Cubre D1 §2.2/§3.2.

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php:4073-4116`. Se usa FQN `\Alegra\Connector\Run_Context`
(no hay `use` para esa clase en `:12-15`).

ANTES (esqueleto actual):

```php
    public function ajax_import_from_api(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        @set_time_limit(300);
        wp_raise_memory_limit();

        if (!get_option('alegra_connector_connection_tested')) {
            wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
        }

        $import_type = sanitize_text_field($_POST['import_type'] ?? '');
        if (!in_array($import_type, ['products', 'customers', 'categories'])) {
            wp_send_json_error(['message' => __('Tipo de importacion invalido.', 'alegra-connector')]);
        }

        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($import_type);
        if ($lock === false) {
            wp_send_json_error(['message' => __('Another sync is in progress. Please wait.', 'alegra-connector')]);
        }
        try {
            $sync_controller = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);
            $result = $sync_controller->import_from_alegra($import_type);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message())]);
            }

            $imported = (int) ($result['imported'] ?? 0);
            $updated = (int) ($result['updated'] ?? 0);

            wp_send_json_success([
                'message' => sprintf(__('Importación completada: %d nuevos, %d actualizados.', 'alegra-connector'), $imported, $updated),
                'data' => $result,
            ]);
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
        }
    }
```

DESPUÉS:

```php
    public function ajax_import_from_api(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);

        @set_time_limit(300);
        wp_raise_memory_limit();

        // REQ-LOG-01: salida temprana con rastro (sin fila: el run no existe aún).
        // K1: el run_type es el canónico `manual_import`, no el alias corto `manual`.
        if (!get_option('alegra_connector_connection_tested')) {
            \Alegra\Connector\Run_Context::fail_early('manual_import', 'connection_not_tested');
            wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
        }

        $import_type = sanitize_text_field($_POST['import_type'] ?? '');
        if (!in_array($import_type, ['products', 'customers', 'categories'], true)) {
            \Alegra\Connector\Run_Context::fail_early('manual_import', 'invalid_type', ['import_type' => $import_type]);
            wp_send_json_error(['message' => __('Tipo de importacion invalido.', 'alegra-connector')]);
        }

        // D1 §2.2: el run se abre ANTES del lock para que el lock ocupado cierre
        // la fila con causa (REQ-MON-01 escenario borde).
        $run_id = \Alegra\Connector\Run_Context::begin('manual_import');

        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($import_type);
        if ($lock === false) {
            \Alegra\Connector\Run_Context::finish($run_id, 'failed', 'Ya hay una sincronización en curso');
            wp_send_json_error(['message' => __('Another sync is in progress. Please wait.', 'alegra-connector')]);
        }
        try {
            $sync_controller = new \Alegra\Connector\Sync\Controller($this->api, $this->logger);
            $result = $sync_controller->import_from_alegra($import_type, $run_id);

            if (is_wp_error($result)) {
                $msg = sprintf(__('Error de Alegra: %s', 'alegra-connector'), $result->get_error_message());
                \Alegra\Connector\Run_Context::finish($run_id, 'failed', $result->get_error_message());
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
                wp_send_json_error(['message' => $msg]);
            }

            // K3 (Oracle D6 + H1): DOS señales distintas, no una.
            //  - stop del usuario (Monitor): Products.php:1298-1301 (por página)
            //    y :1353-1356 (por ítem) hacen `break`/`break 2` SIN tocar
            //    `paused`.
            //  - pausa por presupuesto de 240 s: Products.php:1304-1309 setea
            //    `$result['paused']=true`.
            if (\Alegra\Connector\Runs::should_stop($run_id)) {
                \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
                wp_send_json_success([
                    'message' => __('Importación detenida por el usuario.', 'alegra-connector'),
                    'data' => $result,
                    'images' => $result['images'] ?? [],
                ]);
            }

            // REQ-RES-04 (H1): la pausa por presupuesto se reporta como pausa
            // HONESTA (el cursor ya quedó persistido por Products.php:1373 y
            // :1380-1381), NO como cancelación ni como "Completado".
            if (!empty($result['paused'])) {
                \Alegra\Connector\Run_Context::finish($run_id, 'paused', 'Pausado por presupuesto; reanudable');
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
                wp_send_json_success([
                    'message' => sprintf(
                        __('Importación pausada por presupuesto: %1$d nuevos, %2$d actualizados. Reanudá para continuar.', 'alegra-connector'),
                        (int) ($result['imported'] ?? 0),
                        (int) ($result['updated'] ?? 0)
                    ),
                    'paused' => true,
                    'data' => $result,
                    'images' => $result['images'] ?? [],
                ]);
            }

            $imported = (int) ($result['imported'] ?? 0);
            $updated = (int) ($result['updated'] ?? 0);

            // D1 §2.3: cierre + release ANTES del send (die() no corre finally).
            \Alegra\Connector\Run_Context::finish($run_id, 'completed');
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);

            wp_send_json_success([
                'message' => sprintf(__('Importación completada: %d nuevos, %d actualizados.', 'alegra-connector'), $imported, $updated),
                'data' => $result,
                'images' => $result['images'] ?? [],   // T5.2 (payload), placeholder tolerante
            ]);
        } catch (\Throwable $e) {
            // Solo excepciones reales: el run se cierra failed y el finally libera.
            \Alegra\Connector\Run_Context::finish($run_id, 'failed', $e->getMessage());
            throw $e;
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
        }
    }
```

### H1 — separar "pausa por presupuesto" de "stop del usuario" (Momus)

**Defecto (verificado en HEAD).** El borrador conflaba las dos señales en una sola rama:
`$result['paused']` **no** lo setea el stop, lo setea la **pausa por presupuesto de 240 s**
(`Products.php:1304-1309`: `if (microtime(true) >= $deadline) { $result['paused'] = true; ... }`).
El stop del usuario (`:1298-1301` por página y `:1353-1356` por ítem) hace `break`/`break 2`
**sin** tocar `paused`. Con la rama vieja, un import manual cortado por presupuesto (normal en
catálogos grandes) terminaba `cancelled` + "Importación detenida por el usuario" → **falso**, y
violaba REQ-RES-04.

**BEFORE** (rama conflada del `DESPUÉS`):

```php
            // K3 (Oracle D6): si el import cortó por "Detener" del Monitor, el run
            // termina `cancelled`, no `completed`. `$result['paused']` lo setea
            // Products.php:1305; `should_stop` cubre customers/categories.
            if (!empty($result['paused']) || \Alegra\Connector\Runs::should_stop($run_id)) {
                \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($import_type, $lock);
                wp_send_json_success([
                    'message' => __('Importación detenida por el usuario.', 'alegra-connector'),
                    'data' => $result,
                    'images' => $result['images'] ?? [],
                ]);
            }
```

**AFTER**: dos ramas independientes (ya aplicadas en el bloque de arriba):

- `Runs::should_stop($run_id)` → `finish($run_id, 'cancelled', 'Detenido por el usuario')` +
  mensaje de stop. La rama `cancelled` depende **sólo** de `should_stop`.
- `!empty($result['paused'])` → `finish($run_id, 'paused', 'Pausado por presupuesto; reanudable')`
  + `'paused' => true` + mensaje con los conteos y la instrucción de reanudar.

**Citas correctas** (verificadas contra `includes/Sync/Products.php`): pausa por presupuesto
`:1304-1309`; stop por página `:1298-1301`; stop por ítem `:1353-1356`; persistencia del cursor
`:1373` (por página) y `:1380-1381` (al salir). El status `paused` es almacenable: `Schema.php:179`
es `status VARCHAR(20) NOT NULL DEFAULT 'running'`, sin enum.

Detalles finos:
- El `catch` que re-lanza es **necesario** para cerrar el run ante una excepción real; el
  `finally` sigue liberando el lock. El doble `release` es **seguro**: `release_sync_lock()`
  (`Controller.php:494-509`) sale temprano si el registry ya no tiene la key (depth 0 → `unset`).
- En el harness, `wp_send_json_*` **lanza** `Alegra_Test_JSON_Response`; el `catch` lo atrapa y
  llama `finish(...)` **otra vez**. Por eso `Run_Context::finish` (T1.5) **debe** consultar
  `Runs::status()` y no re-finalizar un run ya cerrado. Esa guarda es dependencia dura de T2.2.
- `Run_Context::begin('manual_import')` con `started_by=null` → `Runs::start` deriva el login
  (`Runs.php:50-58`). No pasar el usuario a mano.
- `started_by` en `Runs::start` con usuario logueado = login; el Monitor muestra el label **Manual**
  para `run_type='manual_import'` (K1/design §2.7, REQ-MON-04).

**Resultado esperado**: un import manual aparece en el Monitor con origen `manual`; lock ocupado
cierra la fila `failed` con causa y deja rastro; `success`/`data` se mantienen y `message` está
siempre (NFR-06); el stop del Monitor cierra `cancelled` y la pausa por presupuesto cierra `paused`
con el mensaje honesto y el cursor persistido (H1, REQ-RES-04).

**Dependencias**: Fase 1 (T1.1b, T1.5). T2.1 (firma de `Controller::import_from_alegra`). H1.a/H1.b/H1.c.

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-LOG-01, REQ-LOG-02, REQ-IMP-02, NFR-06.

**Verificación**:
1. Test `T28.25 lock ocupado cierra failed y libera`: sembrar el lock **crudo**
   `add_option('alegra_lock_alegra_sync_running_products', ['token'=>'x','expires'=>time()+300], '', 'no');`
   y `$_POST['import_type']='products'`; `$resp = alegra_capture_json(fn()=>$admin->ajax_import_from_api());`
   → `assertFalse($resp->success)`, `assertStringContains('Another sync', $resp->payload['message'])`,
   `assertSame('failed', Runs::status($run_id))` (requiere H1.a).
2. Test `T28.26 conexión no testeada deja rastro`: borrar `alegra_connector_connection_tested` →
   el log contiene `connection_not_tested` (o el mensaje `Import abortado antes de crear el run`) y
   `run_type='manual_import'` (K1, no `manual`).
3. Test `T28.27 stop manual cierra cancelled (K3/D6)`: `Runs::request_stop(1);`
   `$_POST['import_type']='products';` → `$resp = alegra_capture_json(fn()=>$admin->ajax_import_from_api());`
   → `assertSame('cancelled', Runs::status(1))` y el mensaje contiene `detenida por el usuario`.
   **Prove-it-catches**: quitar la rama `if (\Alegra\Connector\Runs::should_stop($run_id))` → el
   run queda `completed` y el test falla.
4. Test `T28.221 pausa por presupuesto cierra paused (H1)`: source-scan sobre `ajax_import_from_api`
   (mismo patrón que `T28.218`) → el método contiene **dos** ramas separadas,
   `if (\Alegra\Connector\Runs::should_stop($run_id))` y `if (!empty($result['paused']))`; la rama
   `should_stop` **no** menciona `$result['paused']` y la rama `paused` llama
   `Run_Context::finish($run_id, 'paused'`. **Prove-it-catches**: volver a la rama conflada
   (`!empty($result['paused']) || should_stop(...)`) → el source-scan falla.
   > **Por qué source-scan.** El harness no tiene seam de `microtime()`, así que forzar la pausa
   > por presupuesto de 240 s (mínimo 30 s, `Products.php:1270-1273`) no es viable en un test
   > determinista. El source-scan fija el contrato de branching del handler; la producción de
   > `$result['paused']` en `Products::import_from_alegra` queda fuera del alcance del harness.
5. **Prove-it-catches (con H1.b)**: registrar el observer que fotografía
   `get_option('alegra_lock_alegra_sync_running_products', null)`; forzar un `WP_Error`
   (`alegra_mock_fail('GET','/items',500,['error'=>'boom'])` para products) →
   `assertSame(null, $lock_at_send, 'el lock debe liberarse antes del send')`. Con el release solo
   en el `finally`, el observer ve el lock **presente** → falla. **Este es el test que `tasks.md`
   describía mal (C1).**
6. `bash scripts/exec-test.sh` verde.

**Riesgo**: el `catch` nuevo podría tragarse una excepción de control. **Guarda**: re-lanza
siempre; el `finally` no cambia el flujo.

**Estimación**: M (3 h).

---

### T2.3 — `ajax_sync_start` instrumentado (crea el run chunked)

**Objetivo**: que el botón "Traer desde Alegra" abra la fila `chunked_import`, **agregue el guard
de conexión que hoy no existe**, soporte `from_zero`/`recreate_manual` y persista el estado
`start`/`offset` que consume T3.1.

**Descripción técnica**: hoy (`:2018-2068`) el handler **no** crea fila, **no** chequea conexión
(`:2024` es `reload_credentials()`, ver corrección #1 de `tasks.md`) y persiste un estado con
`'page'=>0` (`:2058`). El design §2.3/§5.1 lo cambia a `start`+`offset`+`run_id`+`policy`. El
guard de conexión tiene precedente textual en `ajax_get_item_categories` (`:2241-2243`).

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php:2018-2068`.

1. **Guard de conexión (nuevo) + limpieza del flag de cancel (D7) + lock atómico (D-D) +
   anti-doble-arranque (D3)** — insertar **después** de `$type = ...` (`:2023`) y **antes** de
   `$this->api->reload_credentials();` (`:2024`):

```php
        if (!get_option('alegra_connector_connection_tested')) {
            // K1: run_type canónico, no el alias `chunked`.
            \Alegra\Connector\Run_Context::fail_early('chunked_import', 'connection_not_tested');
            wp_send_json_error(['message' => __('Conecta primero con Alegra.', 'alegra-connector')]);
        }

        // D7 (Oracle): el botón Cancelar deja `alegra_sync_cancelled` con TTL 120 s.
        // Sin limpiarlo acá, un import nuevo arrancado dentro de esa ventana es
        // cancelado al instante por la rama de cancel de `ajax_sync_page`.
        delete_transient('alegra_sync_cancelled');

        // D-D (Oracle): `ajax_sync_start` debe ser ATÓMICO. Sin lock, dos arranques
        // concurrentes (doble clic / dos pestañas) pasan AMBOS el read-check de
        // abajo y llaman `Run_Context::begin()` + `set_transient()`: el segundo
        // pisa el `run_id`/`start` del primero y deja un run `running` huérfano.
        // Se toma el MISMO lock per-type que usa `ajax_sync_page` (y los
        // importadores) y se mantiene durante: lectura del estado + begin() +
        // set_transient(). Se libera ANTES de cada `wp_send_json_*` (die() no
        // corre finally).
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($type);
        if ($lock === false) {
            wp_send_json_error(['message' => __('Ya hay una importación en curso. Esperá a que termine o cancelala.', 'alegra-connector')]);
        }

        // D3 (Oracle): DENTRO del lock, rechazar un segundo arranque sólo si el
        // run del estado sigue VIVO (running + heartbeat vigente, mismo criterio
        // que `mark_abandoned`). Un estado huérfano (run stale/cerrado o
        // heartbeat vencido) se limpia y se permite reanudar (REQ-RES-01).
        $existing = get_transient('alegra_batch_state');
        if (is_array($existing)) {
            $existing_run   = (int) ($existing['run_id'] ?? 0);
            $existing_alive = $existing_run > 0
                && \Alegra\Connector\Runs::status($existing_run) === 'running'
                && \Alegra\Connector\Heartbeat::get($existing_run) !== null;
            if ($existing_alive) {
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
                wp_send_json_error(['message' => __('Ya hay una importación en curso. Esperá a que termine o cancelala.', 'alegra-connector')]);
            }
            delete_transient('alegra_batch_state');
        }
```

> **Red de excepciones (D-D).** Envolvé el tramo desde el `acquire` (punto 1) hasta el `set_transient`
> (punto 5) en `try { … } finally { \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock); }`.
> Los releases **explícitos antes de cada `wp_send_json_*`** (puntos 1/4/5) son la defensa real
> —`die()` no corre `finally`—; el `finally` sólo cubre una excepción inesperada entre el `acquire`
> y el send.

2. **`from_zero` / `recreate_manual`** — leer de `$_POST` junto a `$type` (`:2023`):

```php
        $from_zero      = !empty($_POST['from_zero']);
        $recreate_manual = !empty($_POST['recreate_manual']);
```

3. **`begin` antes del metadata** — después del guard y **antes** de la llamada `metadata=true`:

```php
        // D1 §2.3: la fila existe desde el arranque; ajax_sync_page la cierra.
        $run_id = \Alegra\Connector\Run_Context::begin('chunked_import');
```

4. **`WP_Error` del `metadata=true` (corrección C4)** — ANTES:

```php
        $total = 0;
        if ($type === 'customers') {
            $resp = $this->api->get('/contacts', ['metadata' => 'true', 'limit' => 1, 'type' => 'client']);
            if (!is_wp_error($resp) && isset($resp['metadata']['total'])) {
                $total = (int) $resp['metadata']['total'];
            }
        } else {
            $params = ['metadata' => 'true', 'limit' => 1] + $this->build_item_filter_params($filters, false);
            $resp = $this->api->get('/items', $params);
            if (!is_wp_error($resp) && isset($resp['metadata']['total'])) {
                $total = (int) $resp['metadata']['total'];
            }
        }
```

DESPUÉS (agregar cierre en cada rama `is_wp_error`):

```php
        $total = 0;
        if ($type === 'customers') {
            $resp = $this->api->get('/contacts', ['metadata' => 'true', 'limit' => 1, 'type' => 'client']);
            if (is_wp_error($resp)) {
                \Alegra\Connector\Run_Context::finish($run_id, 'failed', $resp->get_error_message());
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
                wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $resp->get_error_message())]);
            }
            if (isset($resp['metadata']['total'])) { $total = (int) $resp['metadata']['total']; }
        } else {
            $params = ['metadata' => 'true', 'limit' => 1] + $this->build_item_filter_params($filters, false);
            $resp = $this->api->get('/items', $params);
            if (is_wp_error($resp)) {
                \Alegra\Connector\Run_Context::finish($run_id, 'failed', $resp->get_error_message());
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
                wp_send_json_error(['message' => sprintf(__('Error de Alegra: %s', 'alegra-connector'), $resp->get_error_message())]);
            }
            if (isset($resp['metadata']['total'])) { $total = (int) $resp['metadata']['total']; }
        }
```

5. **Cursor / `from_zero`** — reemplazar el armado de `$state` (`:2057-2063`). ANTES:

```php
        $per_page = 30;
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        $state = [
            'type' => $type, 'page' => 0, 'per_page' => $per_page,
            'total_pages' => $total_pages, 'total_items' => $total,
            'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
            'filters' => $filters,
        ];
        set_transient('alegra_batch_state', $state, 600);

        wp_send_json_success([
            'total_pages' => $total_pages, 'total_items' => $total, 'per_page' => $per_page,
        ]);
```

DESPUÉS:

```php
        $per_page = 30;
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $cursor_key = 'alegra_connector_products_import_cursor';

        // D4 §5.3: "desde cero" solo toca el cursor si es products. Se hace en
        // `start` (no en page 1) para que un cron concurrente no reanude el cursor viejo.
        $start = 0;
        if ($type === 'products') {
            if ($from_zero) {
                delete_option($cursor_key);
                delete_option('alegra_connector_products_import_total');
                $start = 0;
            } else {
                $start = max(0, (int) get_option($cursor_key, 0));
            }
        }
        $resuming = ($start > 0);

        $policy = 'respect';
        if ($from_zero) {
            $policy = $recreate_manual ? 'ignore_all' : 'ignore_bulk';
        }

        // K2: shape canónico de `alegra_batch_state`. Este bloque es el DUEÑO
        // único; Fases 4/5 sólo leen/mutan claves existentes (nunca lo re-listan).
        // `page` NO se escribe (sólo se lee como fallback del estado viejo, C5).
        $state = [
            'type' => $type, 'run_id' => $run_id,
            'start' => $start, 'offset' => 0, 'per_page' => $per_page,
            'total_pages' => $total_pages, 'total_items' => $total,
            'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
            'images' => ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0],
            'from_zero' => $from_zero, 'policy' => $policy,
            'filters' => $filters,
        ];
        set_transient('alegra_batch_state', $state, 600);

        if ($type === 'products' && $total > 0) {
            update_option('alegra_connector_products_import_total', $total, false);
        }

        // D-D: liberar el lock per-type ANTES de responder (die() no corre finally).
        \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);

        wp_send_json_success([
            'total_pages' => $total_pages, 'total_items' => $total, 'per_page' => $per_page,
            'run_id' => $run_id, 'start' => $start, 'resuming' => $resuming,
            'message' => $resuming
                ? sprintf(__('Reanudando desde el ítem %1$d de %2$d.', 'alegra-connector'), $start, $total)
                : sprintf(__('Importando %1$d ítems desde el inicio.', 'alegra-connector'), $total),
        ]);
```

### D-D — `ajax_sync_start` no era atómico (Oracle)

**Defecto.** El guard D3 (punto 1) leía `alegra_batch_state` y después creaba el run **sin lock**.
Dos requests simultáneos (doble clic / dos pestañas) leen "sin estado" a la vez, ambos llaman
`Run_Context::begin()` y ambos `set_transient()`: el segundo pisa el `run_id`/`start` del primero y
deja un run `running` huérfano hasta `mark_abandoned` (180 s). D3 sólo cerraba el caso **secuencial**.

**BEFORE** (orden, sin lock):

```text
read-check alegra_batch_state  →  begin()  →  metadata  →  set_transient(state)  →  send
```

**AFTER** (orden, con el lock per-type abarcando el tramo crítico):

```text
acquire_sync_lock_public($type)
  ├─ read-check alegra_batch_state   (D3)
  ├─ begin()                          (D1)
  ├─ metadata
  └─ set_transient(state, 600)        (K2)
release_sync_lock_public($type, $lock)   ← ANTES de cada wp_send_json_* (die() no corre finally)
send
```

Un segundo arranque concurrente ve el lock tomado (`acquire_lock` usa `add_option`, que **no** es
ganable por dos procesos; `Controller.php:405-424`) y se rechaza. Se usa el **mismo** lock per-type
que `ajax_sync_page`/los importadores, así que además serializa "start" contra una "page" en vuelo.
El lock se libera **antes** de responder: el tramo crítico es corto (read + `begin` + metadata +
`set_transient`). `try { … } finally { release }` como red de excepciones.

**Orden de operaciones**: guard conexión → `delete_transient('alegra_sync_cancelled')` (D7) →
**`acquire_sync_lock_public($type)` (D-D)** → rechazo de doble arranque (D3) → `begin` → metadata
→ cursor → state (K2) → option total → **`release_sync_lock_public` (D-D)** → send.
El `begin` **antes** del metadata garantiza que un `WP_Error` cierre la fila; el `delete_option`
del cursor ocurre **en `start`** (design §5.3).

**Resultado esperado**: "Traer desde Alegra" crea fila `chunked_import` `running`; "Reimportar
desde cero" limpia el cursor y arranca `start=0`; `total_items` visible; sin conexión → `fail_early`
con `run_type='chunked_import'` (K1); un segundo arranque con un run vivo se rechaza con mensaje
(D3); dos arranques **concurrentes** se serializan por el lock per-type (D-D); el flag de cancel
viejo se limpia (D7).

**Dependencias**: Fase 1 (T1.1b). T2.4 (consumidor del estado). H1.a/H1.b/H1.c. `T4.2` completa el
lado JS del botón nuevo.

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-LOG-01, REQ-RES-01, REQ-RES-02, REQ-IMP-04.

**Verificación**:

> **B1 (Momus) — el guard de conexión rompe el baseline.** `alegra_test_reset()`
> (`test-framework.php:144-208`) **no** siembra `alegra_connector_connection_tested` y el test base
> **`T21.4`** (`exec-test.php:3503-3517`) llama `ajax_sync_start()` sin la opción. **Antes del gate**,
> editar `T21.4`: justo después de `alegra_test_reset();` agregar
> `update_option('alegra_connector_connection_tested', true);` (y lo mismo en `T28.28`, punto 1).
> Se elige el seed **explícito** en vez de tocar `alegra_test_reset()` para no cambiar el default
> "no conectado" de las 1289 aserciones. El baseline debe quedar **≥1289 / 0 failed**.
> **Todos** los tests de esta tarea que no ejercitan el camino "sin conexión" (`T28.28`, `T28.29`,
> `T28.211`, `T28.223`) deben sembrar `update_option('alegra_connector_connection_tested', true);`
> antes de llamar `ajax_sync_start()`; sólo `T28.210` borra la opción a propósito.

1. Test `T28.28 start crea run chunked running`: `alegra_test_reset();`
   `update_option('alegra_connector_connection_tested', true);` (**B1: el guard nuevo lo exige**)
   `$_POST['sync_type']='products';`
   `$resp = alegra_capture_json(fn()=>$admin->ajax_sync_start());` →
   `assertTrue($resp->success)`, `assertSame('running', Runs::status((int)$resp->payload['run_id']))`,
   y `get_transient('alegra_batch_state')['run_id']` coincide.
2. Test `T28.29 from_zero limpia cursor`: `update_option('alegra_connector_connection_tested', true);`
   `update_option('alegra_connector_products_import_cursor', 1500, false);`
   `$_POST['from_zero']='1';` → `assertSame(0, (int)$resp->payload['start'])` y
   `assertFalse(get_option('alegra_connector_products_import_cursor', false))`.
3. Test `T28.210 sin conexión fail_early`: `delete_option('alegra_connector_connection_tested');` →
   `assertFalse($resp->success)` y el log contiene `connection_not_tested` con `run_type='chunked_import'`.
4. Test `T28.211 segundo start rechazado (D3)`: `update_option('alegra_connector_connection_tested', true);`
   sembrar `alegra_batch_state` con `run_id` de un run `running` + heartbeat vivo
   (`Runs::start('chunked_import')` + `Heartbeat::set($id,…)`), luego
   `$_POST['sync_type']='products';` → `assertFalse($resp->success)` y el mensaje contiene
   `Ya hay una importación en curso`. Con el heartbeat vencido (o el run `stale`), el mismo POST
   **sí** arranca (reanudación, REQ-RES-01).
5. Test `T28.223 start concurrente rechazado por el lock (D-D)`:
   `update_option('alegra_connector_connection_tested', true);` sembrar el lock **crudo** de otro
   proceso `add_option('alegra_lock_alegra_sync_running_products', ['token'=>'other','expires'=>time()+300], '', 'no');`
   `$_POST['sync_type']='products';` → `assertFalse($resp->success)` y el mensaje contiene
   `importación en curso`; `assertFalse(get_transient('alegra_batch_state'))` (no se creó state).
   Source-scan: en `ajax_sync_start`, el `acquire_sync_lock_public` aparece **antes** del
   `get_transient('alegra_batch_state')` y antes de `Run_Context::begin`. **Prove-it-catches**: quitar
   el `acquire` → el start arranca igual con el lock crudo tomado y el primer assert falla.
6. **Prove-it-catches**: quitar el guard de conexión → (3) falla. Quitar el rechazo de doble arranque
   (D3) → (4) falla. Quitar el `acquire` del lock (D-D) → (5) falla. Quitar el cierre del `WP_Error`
   del metadata (`alegra_mock_fail('GET','/items',500,['error'=>'boom'])`) → la fila queda `running`
   → un assert `assertSame('failed', Runs::status($run_id))` falla.

**Riesgo**: que `delete_option(cursor)` corra en el camino "Traer" normal y borre la reanudación.
**Guarda**: el `delete_option` está **solo** dentro de `if ($from_zero)` y `$type==='products'`;
test (2) + test de que sin `from_zero` el cursor queda intacto.

**Estimación**: M (3 h).

---

### T2.4 — `ajax_sync_page` instrumentado: cierre + release ANTES de cada send · `BLOQUEADO(Fase 0.4)`

**Objetivo**: que cada página del chunked cierre su run y libere el lock **antes** de responder, y
que un `WP_Error`/lock ocupado/cancelación deje la fila con causa y el lock **libre**.

**Descripción técnica**: hoy (`:2073-2225`) el lock se libera **solo** en el `finally` (`:2222-2224`),
que `die()` **no ejecuta** en los `wp_send_json_error` de `:2116,2148,2184` (hallazgo de plataforma,
R1). Tampoco hay `Heartbeat`/`update_progress` ni cierre de run. El estado pasa de `page` a
`start`/`offset` (design §4.1). Cubre D1 §2.3/§2.5/§3.2 + REQ-IMP-01/02. **Esta revisión cierra
tres defectos del esqueleto original**: la rama de cancel inalcanzable (D1), la pérdida de `$page`
para customers/categorías (D2) y el race de dos pestañas (D3).

### D1 — la rama de cancel era inalcanzable (Oracle CRÍTICO)

**Defecto.** `ajax_cancel_sync()` (`Admin_Dashboard.php:3010-3018`) hace
`set_transient('alegra_sync_cancelled', 1, 120)` **y** `delete_transient('alegra_batch_state')`
(`:3015-3016`). El esqueleto original leía el state **primero** y, si faltaba, respondía
`fail_early('no_batch_state')`. Como el botón Cancelar borra el state, la rama de cancel
(`get_transient('alegra_sync_cancelled') || should_stop`) **nunca se ejecutaba** y el run quedaba
`running` hasta `mark_abandoned` → el Monitor mostraba "Abandonado", no "Cancelado"
(REQ-MON-05/REQ-IMP-02).

**Fix elegido: reordenar (cancel ANTES de `!$state`) + que `ajax_cancel_sync` cierre el run.**
Justificación:
- **Reordenar solo no alcanza**: el JS del botón Cancelar **aborta el request en vuelo**
  (`admin.js:160-175`), así que puede no haber "próxima página" que observe el flag; y aunque la
  hubiera, el `run_id` ya se borró con el state y no hay a quién cerrar.
- **"No borrar el state" solo tampoco alcanza**: la pestaña ya se canceló; nadie consumiría el
  state, quedaría vivo 600 s y el guard anti-doble-arranque de T2.3 bloquearía el próximo import.
- Por eso: `ajax_cancel_sync` lee el `run_id` **antes** de borrar el state y cierra la fila
  `cancelled`; y `ajax_sync_page` mueve el chequeo de cancel/stop **antes** del `!$state` como
  defensa en profundidad (cubre el caso de que el state ya no esté).

**BEFORE — `ajax_cancel_sync()` (`Admin_Dashboard.php:3010-3018`):**

```php
    public function ajax_cancel_sync(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        set_transient('alegra_sync_cancelled', 1, 120);
        delete_transient('alegra_batch_state');
        wp_send_json_success(['message' => __('Sincronización cancelada', 'alegra-connector')]);
    }
```

**AFTER:**

```php
    public function ajax_cancel_sync(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        // D1: leer el run_id ANTES de borrar el estado y cerrar la fila
        // `cancelled`. El JS aborta el request en vuelo, así que "la próxima
        // página lo cierra" NO alcanza: este handler es el dueño del cierre.
        $state  = get_transient('alegra_batch_state');
        $run_id = is_array($state) ? (int) ($state['run_id'] ?? 0) : 0;
        if ($run_id > 0) {
            \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Cancelado por el usuario');
        }

        set_transient('alegra_sync_cancelled', 1, 120);
        delete_transient('alegra_sync_progress');
        delete_transient('alegra_batch_state');
        wp_send_json_success(['message' => __('Sincronización cancelada', 'alegra-connector')]);
    }
```

### D2 — `$page` desaparecía; customers/categorías en loop infinito (Oracle CRÍTICO)

**Defecto.** El código actual calcula `$page = ((int)($state['page'] ?? 0)) + 1` en
`Admin_Dashboard.php:2094` (antes del lock) y lo usa para products (`:2109`), customers (`:2146`) y
categorías (`:2183`), persistiendo `$state['page'] = $page` (`:2196`). El esqueleto original leía
`$start`/`$offset`/`$type`/`$run_id` pero **no** asignaba `$page`, y T3.1.b sólo reescribe el bloque
de **products**. Con eso, `$page` quedaba `null` en customers/categorías → `($page - 1) * $per_page`
= 0 → re-fetcheaban la página 1 para siempre; y como `$state['start']` no se avanzaba para esos
tipos, el `$done` de T3.1.c (`$next_start >= $tp * $per_page`) nunca daba true.

**Fix elegido: `start` es el cursor absoluto para los TRES tipos** (coherente con T3.1.c, que ya
dice "para customers/categories `$paused=false` y `$state['start']` se avanza igual"). `$page` se
**deriva** de `$start` al vuelo (`floor($start / $per_page) + 1`) para el payload y para la API de
categorías (que pagina por `page`); `$state['page']` **no se escribe** (queda sólo como fallback de
lectura, C5/K2).

**BEFORE — variables (`:2094-2096`) y las tres ramas:**

```php
        $page = ((int) ($state['page'] ?? 0)) + 1;
        $type = $state['type'];
        $per_page = 30; // Max allowed by Alegra API
        ...
        if ($type === 'products') {
            $start = ($page - 1) * $per_page;
            ...
        } elseif ($type === 'customers') {
            $contacts_start = ($page - 1) * $per_page;
            $items = $this->api->get('/contacts', ['start' => $contacts_start, 'limit' => $per_page, 'type' => 'client']);
            ...
        } else {
            $items = $this->api->get_item_categories(['page' => $page, 'limit' => $per_page]);
            ...
        }
        $state['page'] = $page;
```

**AFTER — variables + ramas (el cursor `start` avanza en los tres tipos):**

```php
        // K2 + C5: `start` es el cursor absoluto; `offset` la reanudación
        // intra-página (sólo products). `page` es fallback de lectura, nunca se
        // escribe. `$page` se deriva para el payload y la API de categorías.
        $per_page = (int) ($state['per_page'] ?? 30);
        $start    = (int) ($state['start'] ?? (((int) ($state['page'] ?? 0)) * $per_page));
        $offset   = (int) ($state['offset'] ?? 0);
        $page     = (int) floor($start / $per_page) + 1;
        $type     = (string) $state['type'];
        ...
        if ($type === 'products') {
            // T3.1.b reescribe este bloque: fetch con start=$start, deadline y
            // loop con offset. Avance del cursor (T3.1.b):
            //   completo → $state['start'] = $start + $per_page; $state['offset'] = 0;
            //   pausa    → $state['offset'] = $index;   // start NO avanza
        } elseif ($type === 'customers') {
            $items = $this->api->get('/contacts', ['start' => $start, 'limit' => $per_page, 'type' => 'client']);
            if (is_wp_error($items)) { /* cierre T2.4 (abajo) */ }

            // ... loop de customers, idéntico a :2151-2180 ...
            $state['start'] = $start + $per_page;   // D2: el cursor avanza
            $is_last = count($items) < $per_page;
        } else {
            // La API de categorías pagina por `page`, derivada de `start`.
            $items = $this->api->get_item_categories(['page' => $page, 'limit' => $per_page]);
            if (is_wp_error($items)) { /* cierre T2.4 (abajo) */ }

            // ... loop de categorías, idéntico a :2185-2192 ...
            $state['start'] = $start + $per_page;   // D2: el cursor avanza
            $is_last = count($items) < $per_page;
        }
```

`$is_last` de products lo fija T3.1.b (`count($items) < $per_page`); T3.1.c unifica `$done` con
`$next_start >= $tp * $per_page`, que es el chequeo que **realmente** cierra customers/categorías.

### D3 — race de dos pestañas / state leído antes del lock (Oracle ALTO)

**Defecto.** `get_transient('alegra_batch_state')` corría **antes** de
`acquire_sync_lock_public`, y `ajax_sync_start` **no** tomaba lock ni rechazaba un segundo arranque.
Dos pestañas/doble clic podían pisarse `run_id`/`start` y procesar la misma página. El guard de
`ajax_sync_start` (rechazar un run vivo) está en T2.3 (punto 1). Acá se reordena `ajax_sync_page`:
el lock se toma **antes** de la lectura autoritativa del estado con el que se procesa.

**BEFORE — orden (`:2078-2102`):**

```php
        $state = get_transient('alegra_batch_state');     // 1) state
        if (!$state) { ... }
        if (get_transient('alegra_sync_cancelled')) { ... }
        $page = ...; $type = $state['type']; $per_page = 30;
        $lock = ...acquire_sync_lock_public($type);        // 2) lock DESPUÉS
        if ($lock === false) { ... }
        try { ... }
```

**AFTER — orden:** (1) state provisional sólo para `type`/`run_id`; (2) cancel/stop; (3) `!$state`;
(4) **lock**; (5) **re-lectura** del state bajo el lock; (6) recién ahí procesar. Si otra pestaña
ganó la carrera, su estado es el vigente y este request lo respeta (o falla).

```php
        $state  = get_transient('alegra_batch_state');     // provisional (type/run_id)
        $state  = is_array($state) ? $state : [];
        $run_id = (int) ($state['run_id'] ?? 0);
        // ... cancel/stop (D1) ...
        if (!$state) { fail_early('chunked_import', 'no_batch_state'); ... }
        $type = (string) $state['type'];

        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($type);   // lock
        if ($lock === false) { finish(failed) + del state + error }
        $state = get_transient('alegra_batch_state');      // re-lectura AUTORITATIVA
        if (!is_array($state)) {
            fail_early('chunked_import', 'no_batch_state');
            release_sync_lock_public($type, $lock);
            wp_send_json_error([...]);
        }
        $run_id = (int) ($state['run_id'] ?? $run_id);
        // Cursor desde el estado AUTORITATIVO (no del provisional):
        $per_page = (int) ($state['per_page'] ?? 30);
        $start    = (int) ($state['start'] ?? (((int) ($state['page'] ?? 0)) * $per_page));
        $offset   = (int) ($state['offset'] ?? 0);
        $page     = (int) floor($start / $per_page) + 1;
        // El contexto de logger se fija con el run_id AUTORITATIVO.
        \Alegra\Connector\Run_Context::resume($run_id, 'chunked_import');
        try { ... }
```

**Desarrollo técnico**:

Archivo: `admin/Admin/Admin_Dashboard.php:2073-2225`. Esqueleto integrado (D1 + D2 + D3); las ramas
de procesamiento por entidad se conservan tal cual (`:2108-2194`), salvo el avance de `$state['start']`
que agrega D2:

```php
    public function ajax_sync_page(): void
    {
        check_ajax_referer('alegra_connector_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        // D1: leer el state con default [] para poder chequear cancel/stop ANTES
        // del guard de "no_batch_state" (el botón Cancelar setea el flag).
        $state  = get_transient('alegra_batch_state');
        $state  = is_array($state) ? $state : [];
        $run_id = (int) ($state['run_id'] ?? 0);

        // REQ-MON-05 / D1: cancel (botón) o stop (Monitor) cierran el run ANTES
        // de cualquier otro guard. `should_stop` sólo se evalúa si hay run_id.
        if (get_transient('alegra_sync_cancelled') || ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id))) {
            if ($run_id > 0) {
                \Alegra\Connector\Run_Context::finish($run_id, 'cancelled', 'Cancelado por el usuario');
            }
            delete_transient('alegra_sync_progress');
            delete_transient('alegra_batch_state');
            wp_send_json_success([
                'done' => true, 'cancelled' => true,
                'message' => __('Sincronización cancelada.', 'alegra-connector'),
            ]);
        }

        if (!$state) {
            \Alegra\Connector\Run_Context::fail_early('chunked_import', 'no_batch_state');
            wp_send_json_error(['message' => __('No hay un proceso de sincronización en curso.', 'alegra-connector')]);
        }

        // D3: el lock se toma ANTES de la lectura autoritativa del estado. El
        // `get_transient` de arriba fue sólo para conocer `type`/`run_id`
        // (key del lock + rama de cancel). Bajo el lock se RE-LEE.
        $type = (string) $state['type'];
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public($type);
        if ($lock === false) {
            \Alegra\Connector\Run_Context::finish($run_id, 'failed', 'Ya hay una sincronización en curso');
            delete_transient('alegra_batch_state');
            wp_send_json_error(['message' => __('Another sync is in progress. Please wait.', 'alegra-connector')]);
        }

        $state = get_transient('alegra_batch_state');
        if (!is_array($state)) {
            \Alegra\Connector\Run_Context::fail_early('chunked_import', 'no_batch_state');
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
            wp_send_json_error(['message' => __('No hay un proceso de sincronización en curso.', 'alegra-connector')]);
        }
        $run_id = (int) ($state['run_id'] ?? $run_id);

        // K2 + C5: el cursor se resuelve desde el estado AUTORITATIVO (re-leído
        // bajo el lock). `start` es el cursor absoluto; `offset` la reanudación
        // intra-página (sólo products). `page` es fallback de lectura, no se
        // escribe. `$page` se deriva para el payload y la API de categorías.
        $per_page = (int) ($state['per_page'] ?? 30);
        $start    = (int) ($state['start'] ?? (((int) ($state['page'] ?? 0)) * $per_page));
        $offset   = (int) ($state['offset'] ?? 0);
        $page     = (int) floor($start / $per_page) + 1;

        // El contexto de logger se fija con el run_id AUTORITATIVO, no el provisional.
        \Alegra\Connector\Run_Context::resume($run_id, 'chunked_import');

        try {
            $this->api->reload_credentials();
            @set_time_limit(60);        // backstop duro; el corte real es el deadline de T3.1
            wp_raise_memory_limit();

            // --- bloques por entidad (D2: el cursor `start` avanza en los TRES) ---
            // products: lo reescribe T3.1.b (fetch start/limit + deadline + offset).
            // customers:
            //   $items = $this->api->get('/contacts', ['start'=>$start,'limit'=>$per_page,'type'=>'client']);
            //   if (is_wp_error($items)) { cierre (abajo) }
            //   ... loop idéntico a :2151-2180 ...
            //   $state['start'] = $start + $per_page;   // D2
            //   $is_last = count($items) < $per_page;
            // categorías:
            //   $items = $this->api->get_item_categories(['page'=>$page,'limit'=>$per_page]);
            //   if (is_wp_error($items)) { cierre (abajo) }
            //   ... loop idéntico a :2185-2192 ...
            //   $state['start'] = $start + $per_page;   // D2
            //   $is_last = count($items) < $per_page;

            // Cierre de WP_Error (idéntico en /items :2116, /contacts :2148 y categorías :2184):
            if (is_wp_error($items)) {
                \Alegra\Connector\Run_Context::finish($run_id, 'failed', $items->get_error_message());
                delete_transient('alegra_batch_state');
                \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
                wp_send_json_error(['message' => $items->get_error_message()]);
            }

            // ... loop por ítem con import_single_item_public($item, $run_id) (T3.2) ...

            // D1 §2.4: heartbeat + progreso por PÁGINA (nunca por ítem, NFR-04).
            \Alegra\Connector\Heartbeat::set($run_id, [
                'step' => 'products',
                'message' => sprintf(__('Procesando productos... Página %d/%d', 'alegra-connector'), $page, $tp),
                'step_num' => $page, 'total_steps' => $tp,
            ]);
            \Alegra\Connector\Runs::update_progress($run_id, $processed, (int) ($state['total_items'] ?? 0));

            if ($done) {
                \Alegra\Connector\Run_Context::finish($run_id, 'completed');
                // B-A (Oracle): `alegra_connector_products_import_cursor` es un
                // option GLOBAL de products. Sólo se borra cuando la entidad que
                // terminó es `products`; un chunked de customers/categories NO
                // debe tocar el punto de reanudación de productos (REQ-RES-01,
                // sin regresión). El código actual (Admin_Dashboard.php:2207-2213)
                // tampoco lo toca.
                if ($type === 'products') {
                    delete_option('alegra_connector_products_import_cursor');
                }
                delete_transient('alegra_batch_state');
            } else {
                set_transient('alegra_batch_state', $state, 600);
            }

            \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);

            wp_send_json_success([
                'page' => $page, 'total_pages' => $tp, 'total_items' => (int) ($state['total_items'] ?? 0),
                'imported' => $state['imported'], 'updated' => $state['updated'],
                'skipped' => $state['skipped'] ?? 0, 'errors' => $state['errors'],
                'processed' => $processed, 'percent' => $pct, 'done' => $done, 'paused' => false,
                'images' => $state['images'] ?? [],
                'message' => sprintf(__('%d/%d items — Pág. %d/%d', 'alegra-connector'), $processed, (int) ($state['total_items'] ?? 0), $page, $tp),
            ]);
        } catch (\Throwable $e) {
            // Solo excepciones reales. En el harness también atrapa el throw de
            // wp_send_json_*; Run_Context::finish (T1.5) no re-finaliza si ya cerró.
            \Alegra\Connector\Run_Context::finish($run_id, 'failed', $e->getMessage());
            throw $e;
        } finally {
            // Solo para excepciones: los sends ya liberaron antes.
            \Alegra\Connector\Sync\Controller::release_sync_lock_public($type, $lock);
        }
    }
```

Reglas duras:
- **Cierre + release SIEMPRE antes de cada `wp_send_json_*`** (cancel, lock ocupado, WP_Error,
  éxito). El `finally` es red de seguridad, no la defensa.
- El `catch` re-lanza; el doble release es no-op (depth 0).
- `resume($run_id,'chunked_import')` inyecta el contexto de log (REQ-LOG-04) y habilita
  `should_stop` en T3.2.
- `$pct` se calcula con `processed/total_items` (no `page/total_pages`) una vez que T3.1 introduce
  el `offset` (T3.4 lo formaliza).
- La rama de cancelación responde `success` (contrato actual, REQ-IMP-02); **sólo** la cancelación
  cierra el run `cancelled`.
- **Provenance de las variables del bloque (D2).** `$page`, `$start`, `$offset`, `$per_page` se
  resuelven **arriba** (fuera del `try`), con `start` como cursor dueño y `page` derivado
  (`floor($start / $per_page) + 1`). `$tp`, `$processed`, `$done`, `$pct` los fija T3.1.c a partir
  de `$state['start']`; T3.4 fija el payload. El loop fino es de T3.1. **`$state['page']` no se
  escribe** (K2/C5).
- **Cancel por botón (D1).** `ajax_cancel_sync` cierra el run `cancelled` antes de borrar el state;
  el flag `alegra_sync_cancelled` sobrevive 120 s y `ajax_sync_start` lo limpia (D7, T2.3).
- `Run_Context::finish` borra el stop transient (`alegra_run_stop_{id}`) al observarlo (T1.4/T1.5).

**Resultado esperado**: un `WP_Error` de `/items` cierra la fila `failed` con causa, loguea,
**libera el lock** y responde `message`; el lock no queda tomado hasta el TTL. El cancel del botón
y el stop del Monitor dejan la fila `cancelled` (D1/K3); customers y categorías avanzan `start` y
**no** loopan (D2); el lock se toma antes de la lectura autoritativa y un segundo arranque con run
vivo se rechaza (D3); al completar un chunked de customers/categories el cursor de reanudación de
**products** queda **intacto** (B-A, REQ-RES-01).

**Dependencias**: Fase 1 (T1.1b, T1.4, T1.5). **T2.3** (estado con `run_id`/`start`/`offset`).
**Fase 0.4 (G4)** confirma que `die()` saltea `finally` (el diseño lo asume). **T3.1** completa el
loop con deadline/offset. **Contrato compartido (B3):** `T2.4` + `T3.1` + `T3.2.b` se implementan y
commitean como **una sola unidad** (mismo commit): `T2.4` usa `$tp`/`$processed`/`$done`/`$pct` (los
fija `T3.1.c`) y `Sync\Products::set_deadline()`/`reset_image_stats()` (los define `T3.2.b`), así que
no compila aislado. Ver `tasks.md` §3 Bloque 2 y `fase-3-chunked.md` (contrato compartido).
H1.a/H1.b/H1.c.

**Trazabilidad**: REQ-MON-01, REQ-MON-02, REQ-MON-05, REQ-LOG-01, REQ-IMP-01, REQ-IMP-02,
REQ-RES-04, NFR-06.

**Verificación**:
1. Test `T28.212 WP_Error cierra failed y libera lock`:
   `alegra_mock_fail('GET','/items',500,['error'=>'boom']);`
   `set_transient('alegra_batch_state', ['type'=>'products','run_id'=>1,'start'=>0,'offset'=>0,'per_page'=>30,'total_pages'=>2,'total_items'=>60,'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'policy'=>'respect','images'=>['ok'=>0,'blocked'=>0,'download'=>0,'sideload'=>0,'deferred'=>0],'filters'=>[]], 600);`
   `$resp = alegra_capture_json(fn()=>$admin->ajax_sync_page());` →
   `assertFalse($resp->success)`, `assertStringContains('boom', $resp->payload['message'])`,
   `assertSame('failed', Runs::status(1))`, y el lock crudo
   `assertFalse(get_option('alegra_lock_alegra_sync_running_products', false))`.
2. Test `T28.213 lock ocupado cierra failed`: crear el option crudo del lock (no vía API, ver H1.c) →
   `assertFalse($resp->success)`, `assertSame('failed', Runs::status(1))`.
3. Test `T28.214 stop cierra cancelled`: `Runs::request_stop(1);` → `assertSame('cancelled', Runs::status(1))`
   y `assertSame(true, $resp->payload['cancelled'])`.
4. Test `T28.215 cancel del botón cierra cancelled (D1)`: crear la fila con
   `$rid = Runs::start('chunked_import')` y sembrar el state con `run_id=$rid`;
   `$admin->ajax_cancel_sync();` → `assertSame('cancelled', Runs::status($rid))`,
   `assertFalse(get_transient('alegra_batch_state'))` y
   `assertTrue((bool) get_transient('alegra_sync_cancelled'))`. **Prove-it-catches**: volver al
   `ajax_cancel_sync` viejo (sin `finish`) → el primer assert falla (la fila queda `running`).
5. Test `T28.216 customers avanza el cursor (D2)`: `set_transient('alegra_batch_state',
   ['type'=>'customers','run_id'=>1,'start'=>0,'offset'=>0,'per_page'=>30,'total_pages'=>2,
   'total_items'=>60,...], 600)` + mock `/contacts` con 30 ítems → `$resp = ...ajax_sync_page()` →
   `assertSame(30, (int) get_transient('alegra_batch_state')['start'])` y `assertSame(false, $resp->payload['done'])`.
   **Prove-it-catches**: quitar `$state['start'] = $start + $per_page;` → el cursor queda en 0 y el
   test falla (reproduce el loop infinito).
6. Test `T28.217 categorías avanzan el cursor (D2)`: mismo patrón con `type='categories'` y
   `get_item_categories(['page'=>1,...])` → `assertSame(30, ...['start'])`.
7. Test `T28.218 el lock se toma antes de la lectura autoritativa (D3)`: source-scan sobre
   `ajax_sync_page` → el **segundo** `get_transient('alegra_batch_state')` (la lectura
   autoritativa) aparece **después** de `acquire_sync_lock_public` (el primero, provisional, va
   antes). **Prove-it-catches**: volver a leer el state una sola vez, antes del lock → el
   source-scan falla.
8. **Prove-it-catches (con H1.b)**: registrar el observer que fotografía el lock; mover el
   `release_sync_lock_public` de vuelta al `finally` → `assertSame(null, $lock_at_send)` **falla**.
   **Este es el test corregido de C1**; sin H1.b el test viejo pasaba con el bug.
9. Test `T28.222 customers done NO borra el cursor de products (B-A)`: `alegra_test_reset();`
   `update_option('alegra_connector_connection_tested', true);`
   `update_option('alegra_connector_products_import_cursor', 1500, false);`
   crear la fila `$rid = Runs::start('chunked_import');` y sembrar el state de customers con
   `run_id=$rid`, `start=0`, `per_page=30`, `total_pages=1`, `total_items=1`; mock
   `alegra_mock_seed_contact('c-1', ['name'=>'Cliente','email'=>'c1@example.test']);` →
   `$resp = alegra_capture_json(fn()=>$admin->ajax_sync_page());` →
   `assertTrue($resp->success)`, `assertSame(true, $resp->payload['done'])` y
   `assertSame(1500, (int) get_option('alegra_connector_products_import_cursor', 0), 'un chunked de customers NO debe borrar el cursor de products')`.
   **Prove-it-catches**: quitar el gate `if ($type === 'products')` → el option queda en `false` y
   el assert falla. Un segundo caso con `type='products'` y `done=true` **sí** deja el option en
   `false` (cobertura del camino que debe borrar).
10. `bash scripts/exec-test.sh` verde.

**Riesgo**: R3 de `tasks.md` (lock colgado). **Guarda**: release explícito antes de cada send +
observer test (4). Riesgo de doble release con el `finally`: no-op por depth (T1.4).

**Estimación**: L (4 h).

---

### T2.5 — Webhook instrumentado (`Run_Context::wrap`)

**Objetivo**: que cada webhook procesado deje su fila `webhook_*` sin cambiar el ACK 200 ni el
resultado de negocio.

**Descripción técnica**: hoy `Receiver::handle()` invoca `$handlers->process_event($event,$data)`
(`Receiver.php:171-173`) dentro de un `try/catch` que ACKea 200 aunque falle (`:174-184`). No hay
fila `Runs` (hallazgo A1). El design §2.2 envuelve la llamada en `Run_Context::wrap` con tipo según
el evento. Corrección #3 de `tasks.md` confirmada: `process_event` vive en `Handlers.php:26`.

**Desarrollo técnico**:

Archivos: `includes/Webhooks/Receiver.php` (`:167-186`).

1. Agregar `use Alegra\Connector\Run_Context;` tras `use Alegra\Connector\Logger;` (`:8`).

2. Helper privado de mapeo (nuevo, cerca de `handle()`):

```php
    /**
     * run_type del Monitor para un evento despachado. Devuelve null para
     * eventos no manejados por Handlers::process_event (no inflar wp_alegra_runs).
     */
    private static function run_type_for_event(string $event): ?string
    {
        if (str_contains($event, 'item'))    { return 'webhook_item'; }
        if (str_contains($event, 'client'))  { return 'webhook_client'; }
        if (str_contains($event, 'invoice')) { return 'webhook_invoice'; }
        return null;
    }
```

3. ANTES (`:171-184`):

```php
        try {
            $handlers = new Handlers($this->api, $this->logger);
            $handlers->process_event($event, $data);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Webhook processing failed', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(['received' => true, 'processed' => false], 200);
        }
```

DESPUÉS:

```php
        try {
            $handlers = new Handlers($this->api, $this->logger);
            $run_type = self::run_type_for_event($event);
            if ($run_type !== null) {
                Run_Context::wrap($run_type, function () use ($handlers, $event, $data) {
                    $handlers->process_event($event, $data);
                }, 'webhook_alegra');
            } else {
                $handlers->process_event($event, $data);
            }
        } catch (\Throwable $e) {
            // R2/NFR-02: ACK 200 aunque el handler falle (Alegra borra la
            // suscripción ante un 5xx). El wrap ya dejó la fila en 'failed'.
            if ($this->logger) {
                $this->logger->error('Webhook processing failed', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(['received' => true, 'processed' => false], 200);
        }
```

`Run_Context::wrap` re-lanza en fallo (design §2.1), y el `catch` existente devuelve 200: el
contrato del webhook **no cambia** (R2). El wrap cierra `completed` en éxito y `failed` en error.
Eventos no despachados (`Handlers::process_event` default, `:54-56`) no crean fila (design §11 R6).

**Resultado esperado**: un webhook `new-item` válido importa el ítem **como hoy** y además deja fila
`webhook_item`; la respuesta sigue 200; un handler que lanza deja fila `failed` y **también** 200.

**Dependencias**: Fase 1 (T1.1b `wrap`). H1.a para la aserción de fila.

**Trazabilidad**: REQ-MON-01, REQ-MON-04, NFR-02.

**Verificación**:
1. Test `T28.219 webhook new-item deja fila completed`: payload `new-item` válido contra el mock →
   `assertSame(200, $response->get_status())`, ítem importado, `Runs::status(1)==='completed'` y
   `run_type==='webhook_item'`.
2. Test `T28.220 handler que falla deja failed y 200`: forzar que `process_event` lance (p. ej.
   `alegra_mock_fail('GET','/items/…',500,…)`) → `assertSame(200, …)` y `Runs::status(1)==='failed'`.
3. **Prove-it-catches**: quitar el `Run_Context::wrap` → el test de fila (1) falla.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: R2 (webhook no-200). **Guarda**: el `catch` existente ya ACKea 200; el wrap re-lanza
para que la fila quede `failed` pero la respuesta no cambia. Test (2).

**Estimación**: S/M (2 h).

---

## DoD de la fase

- REQ-MON-01/02/04, REQ-LOG-01/02, REQ-IMP-02, REQ-CON-01, NFR-02 verdes.
- El lock **nunca** queda tomado por un `wp_send_json_error` (test del observer, T2.4 §Verificación 8; implementado como `T28.74` en Fase 7).
- El cancel del botón y el stop del Monitor terminan en `cancelled` en los tres caminos (K3:
  `T28.24` cron, `T28.27` manual, `T28.214`/`T28.215` chunked).
- **H1**: la pausa por presupuesto del camino manual cierra `paused` (no `cancelled`) y el mensaje
  es honesto (`T28.221`); el stop sigue cerrando `cancelled` (`T28.27`).
- **B-A**: un chunked completado de `customers`/`categories` **no** borra
  `alegra_connector_products_import_cursor` (`T28.222`); uno de `products` sí.
- **B1**: `T21.4` y `T28.28` siembran `alegra_connector_connection_tested`; el baseline queda
  **≥1289 / 0 failed**.
- **D-D**: dos `ajax_sync_start` concurrentes se serializan por el lock per-type (`T28.223`).
- `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes.
- H1.a/H1.b/H1.c aplicados en `scripts/` (fuera del ZIP de release).
