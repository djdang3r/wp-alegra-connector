# REVIEW-momus — Cambio SDD `sync-reliability`

| Campo | Valor |
|---|---|
| Revisor | momus (adversarial plan review) |
| Fecha | 2026-09-25 |
| Alcance | `docs/sdd/sync-reliability/{proposal,spec,design,tasks}.md` + 10 fases en `tasks/` + código real en HEAD |
| Método | Lectura de los 4 artefactos + 10 fases; verificación de citas `archivo:línea` contra HEAD; trazas end-to-end de las 6 tareas más riesgosas; cross-check design↔spec↔tasks↔fases |
| Baseline harness | `bash scripts/exec-test.sh` → **1626 assertions, 0 failed** (verificado corriendo el harness) |
| Estado del árbol | `docs/sdd/sync-reliability/` está **untracked** (no está en HEAD); `docs/sdd/inventory/` sí |

> **Nota de encuadre.** El plan está muy bien citado y es, en su mayor parte, ejecutable. Pero el **titular (FRONT B, sobreventa)** tiene dos contradicciones internas que, tal como está escrito, **reproducen el bug que intenta arreglar**, y la garantía "doble conteo imposible por construcción" (D2) es **falsa** con el código real. Por eso el veredicto es REJECT (fix-and-resubmit): los arreglos son localizados, no hay que rehacer el plan.

---

## VERDICT: **REJECT** (fix-and-resubmit)

El plan **no se puede ejecutar tal cual sin introducir bugs**. Los blockers B1 y B2 rompen el mecanismo titular; B3 invalida la garantía de no-doble-conteo de la decisión D2. Hay además 11 conflictos de consistencia (opciones, firmas, arrays, IDs de test) que, sumados, impiden cumplir la demanda del comerciante ("sin problemas, bugs, incongruencias o conflictos"). Con los 3 blockers y los conflictos C1–C6 corregidos, el plan queda ejecutable.

---

## BLOCKERS (máximo 3, rankeados)

### B1 — El baseline del ledger re-infla la venta (el titular, roto)

**Dónde:** `design.md:546-550` (§3.4 `push_delta`, rama `$synced === ''`), `tasks/fase-3-inventory-pusher.md:366-371` (idéntico), y la interacción con el poll en `design.md:597-612` (§3.5) / `tasks/fase-3-inventory-pusher.md:535-546`.

**El defecto:** cuando `_alegra_stock_synced` está vacío, `push_delta()` hace:
```php
if ($synced === '') { update_post_meta($id, '_alegra_stock_synced', $new_qty); return ...'baseline'; }
```
es decir, fija el punto de acuerdo al **valor de WC** (post-venta) **sin empujar** a Alegra. El poll, después, evalúa `$s !== '' && WC !== s` → como `WC === synced` (ambos 7), cae al `else` y escribe el valor **viejo de Alegra** sobre WC. Resultado: la venta se pierde y WC vuelve a subir. Es exactamente el bug titular.

**Traza numérica (WC=10, Alegra=10, `synced=''`):**

| Paso | WC | Alegra | `synced` | Acción |
|---|---|---|---|---|
| Estado inicial post-upgrade | 10 | 10 | `''` | — |
| Venta de 3 | 7 | 10 | `''` | hook → `push_delta(7)` → `synced=7`, **sin POST** |
| Corre el poll | 7 | 10 | 7 | `pending=''`; `WC==synced` → `else` → `Inventory_Writer::apply(Alegra=10)` |
| Resultado | **10** | 10 | 10 | **RE-INFLACIÓN. La venta se perdió.** |

**Por qué la ventana es grande, no chica:** `tasks/fase-3-inventory-pusher.md:598-599` afirma que "la ventana de re-inflación queda limitada a ventas **previas** al upgrade". **Es falso**: el hook se registra en el upgrade (`T3.3`), así que toda venta **posterior al upgrade y previa al primer poll exitoso** cae en la rama `baseline`. En `inventory_source=woocommerce` el poll no corre nunca: la primera venta se descarta en silencio (no re-infla, pero no se empuja).

**Fix exacto:** el baseline debe establecerse **sólo desde un valor confirmado contra Alegra**, nunca desde WC.
1. En `push_delta()`, con `$synced === ''`, **no** escribir `synced`; marcar `_alegra_stock_push_pending = $new_qty` y devolver `baseline_pending`.
2. En el poll, tratar `baseline_pending` como "reconciliar WC→Alegra": hacer `GET /items/{id}` (o reusar el `$item` ya traído) para fijar `synced = availableQuantity` **antes** de decidir, y luego empujar el delta `WC - synced`.
3. Alternativa mínima: agregar `_alegra_stock_baselined` (bool) y que el `else` del poll exija `baselined=1`; si no, reconcilia en vez de pisar.
4. Test: extender `T29.34` con el caso "venta antes del primer poll" (WC=7, Alegra=10, `synced=''` → correr poll → WC debe quedar en 7 y Alegra en 7).

---

### B2 — `is_syncing()` anula la reconciliación/reintento del poll (contradicción Fase 3 ↔ Fase 7)

**Dónde:** `tasks/fase-3-inventory-pusher.md:336-341` (Guard 1 de `push_delta`), `tasks/fase-3-inventory-pusher.md:538-546` (el poll llama `push_delta` para reconciliar) vs `tasks/fase-7-poll-budget.md:458-466` (`set_syncing(true)` envuelve **todo** el loop).

**El defecto:** `push_delta()` empieza con:
```php
if (get_transient('alegra_updating_product_'.$id) || Public_::is_syncing()) {
    return ['pushed' => false, 'delta' => 0, 'reason' => 'syncing'];
}
```
`Public_::is_syncing()` es un **static por request** (`public/Public/Public_.php:442-445`, devuelve `self::$is_syncing`). `T7.3` hace `set_syncing(true)` en el request del poll. Entonces, cuando el poll llama explícitamente a `push_delta()` para (a) reintentar un `pending` o (b) reconciliar un delta local, **`push_delta` devuelve `'syncing'` y no hace nada**. El `continue` posterior deja a WC sin escribir (bien para no re-inflar) pero **Alegra nunca se reconcilia y `pending` nunca se limpia**. DR2 / K-F ("el poll reintenta el push") es falso.

**Por qué rompe un test ya escrito:** `T3.4` (Fase 3) corre antes de que exista `T7.3`, así que `T29.36` (push fallido ⇒ poll reintenta) pasa en Fase 3. Cuando `T7.3` aterriza en Fase 7, `T29.36` **regresa a rojo** porque `push_delta` ahora corta por `is_syncing`. El plan no agrega ningún test cross-fase para esto.

**Fix exacto:**
1. Mover el guard de re-entrada del **API explícito** al **hook**: `on_stock_changed()` / `on_variation_stock_changed()` chequean `is_syncing()`/`alegra_updating_product_{id}` y retornan; `push_delta()` **no** chequea `is_syncing()` (sólo `invoice_owner`, `disabled`, `not_linked`, `not_manageable`).
2. O que el poll llame a un método `reconcile_from_wc()` que bypasee el guard; o bajar `set_syncing(false)` alrededor del bloque de reconciliación.
3. Test cross-fase obligatorio: con `set_syncing(true)` activo, correr el poll sobre un `pending` y assertar que el POST **sí** sale y `pending` se limpia. Debe correr en Fase 7 (o al final), no sólo en Fase 3.

---

### B3 — "Doble conteo imposible por construcción" (D2) es falso con el código real

**Dónde:** `tasks.md:55-62` (§0.2), `design.md:490-510` (§3.3), `tasks/fase-3-inventory-pusher.md:66-78` (K-P).

**El defecto:** `owner = push_orders_enabled ? 'invoice' : 'adjustment'` asume que con `adjustment` **ninguna factura mueve stock**. En HEAD eso es falso: la facturación **manual** existe y no está gateada por `push_orders_enabled`:
- `admin/Admin/Admin_Dashboard.php:2693-2730` — acción "abrir factura" (pasa `draft` a `open`).
- `admin/Admin/Admin_Dashboard.php:2778-2781` — "Registrar pago" abre un draft (`ensure_invoice_open`).
- `includes/Sync/Orders.php:1094` — `invoice_status` es configurable (`draft`/`open`).

**Escenario de doble conteo:** `push_orders_enabled=false` (owner=`adjustment`) → una venta reduce WC → el pusher emite `POST /inventory-adjustments` (Alegra baja k) → el comerciante **abre/crea la factura** del pedido manualmente → la factura `open` descuenta stock nativo → **Alegra baja 2k**. La garantía de REQ-INV-08 se viola.

**Escenario de cobertura nula (el simétrico):** `push_orders_enabled=true` (owner=`invoice`) + `open_invoice_on_paid=false` (opción configurable) → no hay mecanismo que mueva stock (el pusher salta por `invoice_owner`, la factura queda draft) → REQ-INV-01 se viola y el titular persiste.

**Fix exacto:**
1. Que `owner()` no dependa sólo de `push_orders_enabled`: `owner=invoice` sólo si las facturas realmente mueven stock (`invoice_status != 'draft'` u `open_invoice_on_paid=true`); en cualquier otro caso `adjustment`.
2. O, en el pusher, suprimir el ajuste cuando el pedido ya tiene una factura `open` (`_alegra_invoice_status === 'open'`), y bloquear/avisar la apertura manual en modo `adjustment`.
3. Test explícito: venta + factura abierta manual en modo `adjustment` ⇒ Alegra baja **una** vez. Y test `push_orders=true` + `open_invoice_on_paid=false` ⇒ el sistema reporta que no hay dueño (no miente).
4. Actualizar `tasks.md:62`, `design.md:503-510` y `fase-3:77-78` para no afirmar "imposible por construcción" sin la condición.

---

## CONFLICTS (pares que se contradicen)

| # | Par contradictorio | Evidencia | Fix |
|---|---|---|---|
| **C1** | Opción `open_invoice_on_paid` existe vs no existe | `design.md:1096-1108` (§11 lista **8** opciones) y `tasks.md:305` (T1.7 "8 opciones", test T29.17 asserta 8) **vs** `tasks/fase-3-inventory-pusher.md:45` (C4: "**9.ª** opción", "T1.7 pasa a sembrar 9") y `T3.5:635-703`. Además el nombre en `design.md:1122` y `T3.5` es `open_invoice_on_paid` (sin prefijo `alegra_connector_`). | Agregar `alegra_connector_open_invoice_on_paid` (bool, default true) a §11, a `$defaults`/`$non_autoload`/`uninstall.php`, y cambiar T1.7/T29.17 a **9**. |
| **C2** | Dos tareas reescriben `Products.php:1144` con arrays incompatibles | `tasks/fase-2-inventory-writer.md:520-521` agrega `'skipped_not_manageable' => 0`; `tasks/fase-7-poll-budget.md:88-100` reescribe con **8 claves** sin esa clave y afirma "el array `$result` contiene las 8 claves". `T2.5` (`fase-2:690`) y `T3.4` (`fase-3:575-577`) incrementan `$result['skipped_not_manageable']` → **undefined index** tras T7.1.a. | T7.1.a debe preservar la 9.ª clave (`skipped_not_manageable`) o agregarla; ajustar el assert a 9. |
| **C3** | Firmas design §10 vs fases | `register_hooks(): void` (`design.md:1050`) vs `register_hooks(?API\Client, ?Logger)` (`fase-3:44,179`); `on_stock_changed(int $product_id)` (`design.md:1051`) vs `on_stock_changed($product)` (`fase-3:43,191` — WC pasa **objeto**, un `int` daría TypeError); `run_inventory_sync(float $deadline=0.0)` (T7.5), `import_from_alegra(..., float $run_deadline=0.0)` (T8.2) y `apply_inventory_to_product(..., array $preserve=[])` (T2.3) **no** están en `design.md:1059-1072`; `dry_run` está en `fase-2:81` pero no en el enum de `design.md:642-643`. `fase-1:146` todavía dice `register_hooks()` sin args. | Reconciliar `design.md` §10/§4.1 con las correcciones C2/C3/C4/C7 de las fases (o marcar §10 como superseded). |
| **C4** | `register_setting`/UI sin dueño | `design.md:64` (§0) asigna "budget/tope del poll; push de inventario" a `templates/admin-settings.php`; `tasks/fase-1-cimientos.md:748-750` difiere el `register_setting` de los 5 controles a "T7.1/T7.4/T8.4". Ninguna de esas fases registra `alegra_connector_push_inventory_enabled`, `alegra_connector_inventory_poll_budget` ni `alegra_connector_inventory_poll_max_pages`, ni crea sus controles. | Asignar dueño explícito (p. ej. T7.1/T7.4 para budget/max_pages y T3.x para push inventory) y agregar el control en admin-settings. |
| **C5** | Helpers del ledger creados pero no usados | `T1.10` (`fase-1`) define `synced()/set_synced()/pending()/set_pending()/clear_pending()` y dice que los consume T3.x; `T3.2` (`fase-3:367-413`) y `T3.4` (`fase-3:535-536`) usan `get_post_meta`/`update_post_meta`/`delete_post_meta` crudos. | Usar los helpers en T3.2/T3.4, o eliminar T1.10 (evitar código muerto). |
| **C6** | Reset del cursor por `pull_total` que nunca se escribe | `design.md:886-892` (§7.3) resetea si `cursor >= total`; `design.md:843-881` (§7.2) y `fase-7` T7.1.b **nunca** actualizan `alegra_connector_inventory_pull_total`. Con total default 0, `cursor >= 0` es **siempre** verdadero → el cursor se resetea en cada corrida y el resume no funciona. | Guardar `total > 0 && cursor >= total` y actualizar `pull_total` en el poll. |
| **C7** | Matriz R1–R18 de Fase 9 mapea test IDs inexistentes | `fase-9:79` R2→`T29.35` (debería ser `T29.37`); `:81` R4→`T29.28` (debería ser `T29.21`/`T29.23`); `:82` R5→`T29.22` (debería ser `T29.25`); `:83` R6→`T29.55` (el mapa de Fase 5 asigna `T29.56` a idempotencia); `:84` R7→`T29.56` (debería ser `T29.58`); `:91` R14→`T29.21` (el fallback no tiene test asignado). | Corregir los IDs de la matriz contra el mapa real de cada fase. |
| **C8** | Rangos de test de Fase 9 no coinciden con las fases | `fase-9:138-147` lista Fase 2 `T29.21–T29.28` (define hasta `T29.29`), Fase 3 `T29.31–T29.37` (define hasta `T29.39`), Fase 4 `T29.41–T29.44` (define hasta `T29.45`), Fase 5 `T29.51–T29.57` (define hasta `T29.59`), Fase 6 `T29.61–T29.64` (define +`T29.640`), Fase 9 `T29.91–T29.98` (define sólo `T29.91–T29.95`). El total "≥ 1740" queda mal calculado. | Reconciliar rangos y recomputar el total. |
| **C9** | Colisión de IDs de test en Fase 5 | `T5.2b` (`fase-5:496`) usa `T29.53`, pero el mapa de `T5.7` (`fase-5:1132`) asigna `T29.53` a `T5.3`; ídem `T29.54/55/56`. El propio `T5.2b` dice "renumerar si T5.3 toma ese ID". | Asignar IDs únicos antes de ejecutar. |
| **C10** | Dueño del stub `register_shutdown_function` | `T1.6` (`fase-1:657-658`) dice "**No** tocar `wp-stubs.php` ni `test-framework.php`"; `T8.6` (`fase-8`) asigna un posible cambio de stub a `T1.1–T1.6`. | Asignar el stub a `T1.1` explícitamente. |
| **C11** | Check de smoke duplicado | `T1.10` (`fase-1:1011-1018`) y `T9.3` (`fase-9:177-189`) agregan ambos el assert `Inventory_Pusher class loads` en `smoke-load.php:196-200`. | Un solo dueño. |
| **C12** | Mismo punto de inserción `T7.3` vs `T8.1` | Ambos insertan código "después de adquirir el lock (`:1163-1168`) y antes del `try`": `T7.3` mete `$was_syncing`/`set_syncing(true)`; `T8.1` mete `$lock_key`/`register_shutdown_function`. No es semánticamente incompatible, pero cada fase muestra su propio "ANTES/DESPUÉS" de las mismas líneas. | Definir el orden de inserción en una sola tarea (o marcar la segunda como "insertar después de la primera"). |

---

## COVERAGE GAPS

- **REQ → tarea:** los 30 REQ (CF-01..08, INV-01..08, POLL-01..07, NFR-01..07) tienen al menos una micro-tarea en la matriz de `tasks.md:470-508`. **No hay REQ huérfano.** ✔
- **Concern → fix:** los 5 problemas de `tasks.md:593-602` están mapeados a tareas y a pasos manuales de `T9.4`. ✔
- **Gap real 1:** la UI/`register_setting` de `push_inventory_enabled`, `inventory_poll_budget` e `inventory_poll_max_pages` no tiene dueño (C4). REQ-POLL-02 ("el tope es configurable desde la UI/opción") queda a medias: la opción se lee pero no se registra ni se puede editar.
- **Gap real 2:** `alegra_connector_inventory_pull_total` (para el "avance" de REQ-POLL-02/REQ-POLL-01 en la UI) nunca se escribe; además su ausencia rompe el reset del cursor (C6).
- **Gap real 3:** `T1.10` (helpers del ledger) queda como código muerto si no se usa en T3.2/T3.4 (C5).
- **Diferido declarado:** REQ-INV-06 (warehouse-aware) y REQ-POLL-07 (Action Scheduler) están diferidos con aviso UI / fallback — correcto y honesto.

---

## RISKS (regresiones sin guarda suficiente)

- **R-1 `register_shutdown_function` no cubre OOM/timeout.** `design.md:907-921` (§8.1) y `REQ-POLL-03` afirman que un fatal libera el lock. En PHP, los shutdown functions **no** se ejecutan en OOM (`memory_limit`) ni confiablemente en `max_execution_time`. La garantía es **parcial**; el TTL 300 sigue siendo el backstop real. Acción: declarar la limitación en el diseño y no vender la cobertura total; mantener TTL.
- **R-2 `GET /items?variantParent_id` sin verificar.** `design.md:187` y `fase-4:752-758` lo marcan SIN VERIFICAR. Si el endpoint no soporta ese filtro, el update de variaciones no refresca hijos (REQ-INV-03). Guarda actual: log de warning (nunca skip mudo). Acción: agregarlo a Fase 0 G2 o a `phase0-results.md` como check explícito.
- **R-3 Cursor por offset con catálogo cambiante.** El cursor es un `start` numérico (`design.md:862`, `fase-7` T7.1.b). Si se agregan/eliminan ítems entre corridas, los offsets se corren → ítems salteados o reprocesados. Sólo se maneja "catálogo encogido" y ese check está roto (C6). Acción: documentar la limitación o reanudar por id.
- **R-4 `is_syncing()` static vs transient.** `public/Public/Public_.php:442-445` devuelve un static por request; la supresión cross-process depende de `trigger_sync`'s transient (`:371`). El diseño mezcla ambos conceptos (`design.md:935-937`, `fase-3:48`). El guard por producto `alegra_updating_product_{id}` (TTL 30) cubre el caso del poll, pero conviene explicitar la semántica para no asumir cobertura cross-process donde no la hay.
- **R-5 `preserve_fields` + owner `adjustment`.** REQ-INV-05 exige que el poll no escriba con `inventory` preservado; el **pusher** no consulta `preserve_fields` (`fase-3:350` sólo mira `push_inventory_enabled`). Con owner `adjustment`, el plugin seguirá empujando WC→Alegra aunque el comerciante haya pedido preservar inventario. Acción: decidir y documentar si el push también debe respetar `preserve_fields`.
- **R-6 Fallback sin hook.** `apply_stock()` cae a `set_stock_quantity()+save()` si no existe `wc_update_product_stock` (`design.md:678-681`). En WC moderno `save()` sí deriva `_stock_status`, pero **no** dispara `woocommerce_product_set_stock`; el diseño §6.1 dice lo contrario ("no dispara hooks específicos" — correcto) pero también insinúa que el fallback no deriva estado (incorrecto). Documentar la diferencia con precisión (ya hay DR12).

---

## WHAT'S GOOD

- **Disciplina de citas y honestidad:** cada claim cita `archivo:línea`; lo no verificado va como `SIN VERIFICAR / BLOQUEADO`. Verifiqué las citas principales contra HEAD: `Consumidor_Final.php:44-67,74-77,181-184,241-251,392-397,480-491`; `Orders.php:63,97-117,119,121-129,266-297,1428-1435`; `Products.php:1142,1171-1174,1238,1253-1260,1282-1284,1290-1292,2052-2100`; `Controller.php:141,414,441,475,482,523-526`; `Client.php:242,300,761,770,954`; `Write_Gate.php:37-46,52-54,108-125,150,176`; `Public_.php:73,226-234,371,426-437,442`. **Todas exactas** salvo las correcciones ya declaradas.
- **Hallazgos propios de las fases que salvan bugs reales:** C1 (payload de `/inventory-adjustments` necesita `items[]` + `unitCost`, no `item`/`type` raíz — `fase-3:42`), C2 (los hooks de WC pasan **objeto**, un `int` daría TypeError — `fase-3:43`), C3 (`register_hooks` necesita API/Logger — `fase-3:44`), C4 (9.ª opción — `fase-3:45`), T1.8 (`ENTITY_DEFAULTS['inventory']=true`, si no el gate bloquea el ajuste — `tasks.md:653`). Estos son exactamente el tipo de bug que la demanda pide evitar.
- **Invariante de un solo escritor** (`REQ-INV-02` + grep gate `T2.7`) con W1/W2 delegando en `Inventory_Writer`; verifiqué que hoy los únicos setters están en `Products.php:1259,2099,2059,2067,2072` y `1255,2097`.
- **Seams de harness H1–H8** con tests concretos (`wc_update_product_stock` stub, derivación de `_stock_status`, modo CONTAINS, ruta `/inventory-adjustments`, rechazo de client inexistente, `set_syncing`), y el hallazgo de que H5 debe ser **opt-in** para no romper el baseline (`fase-1:35,553-601`) — muy bueno.
- **Prove-it-catches** obligatorio en las 5 tareas centrales (`T2.1,T3.4,T5.5,T7.1,T8.1`) con reversión→rojo documentada.
- **Fail-loud y degradación honesta:** "No verificado" vs "No encontrado", `truncated`, log de truncación, aviso de divergencia.
- **Restricciones de plugin distribuido** respetadas: sin depender de `set_time_limit`, cron real ni Action Scheduler; ZIP sin `scripts/` (`.distignore:15` verificado); opciones con defaults.
- **D2 vs `docs/sdd/inventory/DD-8`** se contradice de forma **explícita y justificada** (`tasks.md:64-69`, `fase-3:22-30`), con evidencia de por qué el default `push_orders=false` + `invoice_status=draft` no cubre nada. La intención es correcta; lo que falta es endurecer la garantía (B3).

---

## Apéndice A — Verificación de referencias (condensada)

- `wc_update_product_stock`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`: **0 matches** en producción (`includes/`, `public/`, `admin/`) y ausentes del harness. Confirmado.
- `_alegra_stock_synced` / `_alegra_stock_push_pending`: **0 matches** en producción (sólo docs). Confirmado.
- `open_invoice_on_paid`, `inventory_manage_stock_enabled`, `inventory_dry_run`, `inventory_pull_cursor`, `Inventory_Writer`, `Inventory_Pusher`: **0 matches** en producción.
- `Write_Gate::ENTITY_DEFAULTS` (`Write_Gate.php:52-54`) contiene sólo `'payment' => true`; `'inventory'` **no** existe → `T1.8` es necesario.
- `Controller::acquire_lock` es `public static` (`:414`, `(string $key, int $ttl = 300): string|false`); `release_lock` es `public static` (`:441`, valida token en `:445`). El pusher puede usarlos.
- `find_existing_invoice(\WC_Order $order, string $client_id): ?array` (`Orders.php:266`) — la firma coincide con el uso del self-heal (`design.md:339`).
- `Consumidor_Final::is_consumidor_final(string $id): bool` existe (`:405`) y se usa en `Webhooks/Handlers.php:125,148`.
- Baseline `exec-test.sh`: **1626 assertions, 0 failed** (re-ejecutado). Prefijo `T29` libre (`T1..T28` usados).
- `docs/sdd/inventory/design.md:440` — `DD-8 | No cablear create_inventory_adjustment() | Evitar doble conteo con la factura (sección 5.8).` La contradicción de D2 es real y está documentada.

## Apéndice B — Traza end-to-end de las 6 tareas más riesgosas

| Tarea | Traza | Veredicto |
|---|---|---|
| `T3.2` ledger/delta | Delta contra `synced` es correcto **salvo** baseline (B1); `pending` antes del POST correcto; `synced` sólo con OK correcto; lock correcto. | **B1** |
| `T3.4` poll↔ledger | La intención es correcta; la guarda `is_syncing` de `push_delta` la anula tras `T7.3` (B2); el `else` pisa WC en el caso baseline (B1). | **B1+B2** |
| `T5.5` self-heal 400 | Condición conservadora (400 + cliente + id CF + una vez) correcta; `find_existing_invoice` con la firma correcta; borra meta si no re-resuelve (no loop). Bien. | OK |
| `T7.1` budget/cursor | Firma y shape correctos; el reset por `pull_total` está roto (C6); `skipped_not_manageable` se pierde (C2). | **C2+C6** |
| `T8.1` shutdown lock | `release_lock` valida token (correcto); cobertura parcial ante OOM/timeout (R-1). | OK con salvedad |
| `T5.1`/`T5.2` CF CONTAINS | Paginación + barrido completo + `unverified` ante tope; `probe` nunca POSTea. Correcto. | OK |

---

**Conclusión:** corregir **B1, B2, B3** y los conflictos **C1–C6** (mínimo), re-verificar la matriz de tests (C7–C9) y re-someter. El resto del plan (FRONT A, harness, release) está en buen estado y no requiere rediseño.
