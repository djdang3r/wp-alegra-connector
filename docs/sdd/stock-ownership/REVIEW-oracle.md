# REVIEW-oracle — Corrección técnica del plan `stock-ownership`

| Campo | Valor |
|---|---|
| Alcance | Verificar si el **código planeado** funciona (no el proceso) |
| Artefactos leídos | `design.md` (D1–D6), los 8 archivos de `tasks/`, y el código real en HEAD |
| Código real verificado | `includes/Sync/{Inventory_Pusher,Inventory_Writer,Orders,Products,Controller}.php`, `admin/Admin/Admin_Dashboard.php`, `public/Public/Public_.php`, `scripts/lib/{alegra-mock,wp-stubs}.php`, `templates/admin-settings.php`, `alegra-connector.php` |
| Harness | `bash scripts/exec-test.sh` **corrido en HEAD** ⇒ `EXEC-TEST OK: 1990 assertions passed, 0 failed` |
| Fecha | 2026-09-25 |

---

## VERDICT

**UNSOUND.**

La arquitectura de D1/D2 es defendible, pero el plan, **tal como está escrito**, no se puede implementar sin introducir (a) una ruta **automática de doble descuento** en modo `adjustment` que el propio diseño declara imposible, (b) un **fatal `TypeError`** entre la Fase 3 y la Fase 5, y (c) una **inanición de la cola de reintentos** que deja los primeros fallos sin reintentar jamás. El bug titular ("vendo 3 y Alegra descuenta 6") **reaparece** por una vía que el plan no cierra en su rama esperada (G1 Rama A).

Con las correcciones de §TECHNICAL DEFECTS (1–8) el plan es recuperable; ninguna exige rehacer la arquitectura.

---

## Respuestas directas a las 10 preguntas

| # | Pregunta | Respuesta |
|---|---|---|
| 1 | ¿En TODA combinación exactamente un mecanismo mueve stock? | **No.** Encontré **DOS** y **CERO**. Dos: `owner=adjustment` (explícito o `auto`+`push_orders=true`+`open_invoice_on_paid=false`) con cuenta de pago configurada ⇒ la factura nace `open` (mueve) **y** el pusher emite ajuste (mueve). Cero: `stock_owner=invoice` + `push_orders_enabled=false` ⇒ Guard 2 bloquea ajustes y no hay facturación automática. Ver **DEF-1**, **DEF-8**. |
| 2 | ¿Se puede hacer doble descuento desde la UI de Alegra? | **Sí.** La guarda `confirm_double_discount=1` vive **solo** en `ajax_open_invoice_impl`/`ajax_record_payment_impl`. Abrir/registrar pago desde la UI o la API de Alegra no pasa por ella. La garantía "imposible por construcción" de `design.md:268-270` es **falsa**. Ver **DEF-5**. |
| 3 | ¿El poll converge? ¿Drift/starvation? | El camino feliz converge (trazado abajo). **Pero** si `S` quedó no-vacío y `W≠S` en modo `invoice` (factura abierta fuera de WC, o instalación migrada de 2.6.0), el poll queda **congelado para siempre** y reporta divergencia falsa cuando `W==A`. Ver **DEF-4**. |
| 4 | ¿`POST /inventory-adjustments` soporta `reference`? | **Sin verificar (G3).** El fallback **no** evita de forma fiable un duplicado: el pre-chequeo solo corre en reintento (`pending_prev!==''`) y el match `item+type+quantity` sobre los últimos 30 puede falso-positivar o perder. Además, mandar un campo `reference` desconocido podría dar 422 (sin verificar). Ver **DEF-6**. |
| 5 | ¿El timestamp `_alegra_stock_adjusted_at` compara bien contra `get_date_created()`? | **Sí, correcto.** `time()` (epoch UTC) vs `WC_DateTime::getTimestamp()` (epoch UTC). No hay bug de timezone. Fail-safe cuando `get_date_created()` es `null` (`since=0`). Ver **VERIFIED-CORRECT**. |
| 6 | ¿El reintento duplica factura? ¿El clasificador cubre todo? ¿El backoff termina? | No duplica si `_alegra_invoice_id` o AC-14 aciertan (riesgo residual por `limit=30`). El clasificador **no** cubre el skip `adjustment_manual_only` (lo trata como retriable). El backoff **termina**, pero la query **nunca selecciona** el primer fallo (falta `_alegra_invoice_attempts`). Ver **DEF-3**, **DEF-10**. |
| 7 | ¿La reconciliación detecta lo real? ¿La reparación empeora? | Detecta de menos/ensucia: registra divergencia falsa con `W==A` y `S` stale, y no puede calcular `factura_fallida`/`reembolso`/`factura_pendiente` desde el poll (no tiene pedido). La reparación `adjustment` **empeora**: calcula el delta desde `synced` (no desde `A`), saltea el pusher y no actualiza `synced`. Ver **DEF-4**, **DEF-6b**. |
| 8 | ¿La reparación de corrupción existente es segura? | **No implementable con T5.4** y no atómica. T7.4 manda cambiar el dueño a `invoice` y **después** emitir un `in`; T5.4 rechaza ajustes si `owner!=='adjustment'`. Además el cambio de dueño es **global** y una interrupción deja el comercio en `invoice` con la corrupción intacta. Ver **UNIMPLEMENTABLE-1**. |
| 9 | ¿Corren los tests? ¿Se sostiene 1990/0? | **Sí corren** (vía docker `php:8.3-cli`) y **1990/0 confirmado en HEAD**. Los cambios del harness son aditivos; el default `'open'` de H-A protege el baseline. No se puede verificar el post-cambio sin implementar. Ver **VERIFIED-CORRECT**. |
| 10 | ¿Otros bugs/fatales/carreras/regresiones? | Sí: `divergence_cause()` recibe `$p` (int) donde espera `?\WC_Order` ⇒ **fatal**; coerción de `open_invoice_on_paid` dependiente del orden de `register_setting`; `array_map('intval', $res)` sobre objetos en el fallback de `Invoice_Queue`; `refresh_count()` por cada factura; `meta_query` OR con `NOT EXISTS`. Ver **DEF-2**, **DEF-9**, **RACES**. |

---

## TECHNICAL DEFECTS (ranked)

### DEF-1 — CRÍTICO · Doble descuento AUTOMÁTICO en modo `adjustment` (el bug titular reaparece)

**Artefacto / código:**
- `includes/Sync/Orders.php:508-512` (real):
  ```php
  $will_record_payment = !in_array($payment_account, ['', '0'], true)
      && (string) $order->get_meta('_alegra_payment_id', true) === ''
      && $order->is_paid();
  $invoice_result = $this->create_invoice($order, $will_record_payment ? 'open' : null);
  ```
- `includes/Sync/Orders.php:1246` (real): `$status = $status_override ?? (string) get_option('alegra_connector_invoice_status', 'draft');`
- Plan: `fase-2-ownership.md:489-513` (T2.4) solo agrega el bloque de salto **en Rama B de G1**; en Rama A es **no-op**.

**Defecto:** El `'open'` de `create_invoice_with_payment()` **no está gated por `owner()`** (contrario a `design.md:269`: "sólo pasa 'open' si hay pago y el dueño es factura"). El override de `Orders.php:122-126` sí está gated, pero es irrelevante porque `$status_override='open'` ya viene forzado. En modo `adjustment`, un pedido pagado con cuenta de pago configurada crea la factura **abierta** (Alegra descuenta) **y** el hook de stock emite el ajuste (descuenta de nuevo). Igual ocurre con `invoice_status='open'` en `adjustment` (cualquier pedido, sin cuenta de pago).

**Por qué importa:** es exactamente el defecto B3/Oracle#1 que el cambio dice eliminar. `design.md:268-270` afirma "imposible por construcción"; es falso.

**Fix exacto:** en `create_invoice_with_payment()` condicionar el override:
```php
$open_now = $will_record_payment && Inventory_Pusher::owner() === 'invoice';
$invoice_result = $this->create_invoice($order, $open_now ? 'open' : null);
```
y en `prepare_invoice_data()` forzar `draft` cuando `owner()!=='invoice'` (o coercer `invoice_status` server-side, como ya se hace con `open_invoice_on_paid`). Aplicar en **ambas** ramas de G1, no solo Rama B.

---

### DEF-2 — CRÍTICO · Fatal `TypeError`: `divergence_cause()` recibe `$p` donde espera `?\WC_Order`

**Artefacto:**
- `tasks/fase-3-poll-fix.md:814-819` (T3.5) llama:
  ```php
  'cause' => Stock_Divergence::divergence_cause($owner, $s, $p),
  ```
  donde `$p = Inventory_Pusher::pending($product_id)` es `int|''`.
- `tasks/fase-5-reconciliation.md:119` (T5.1) define:
  ```php
  public static function divergence_cause(string $owner, int|string $s, ?\WC_Order $order = null): string
  ```
- `Products.php` tiene `declare(strict_types=1)` (mismo namespace/archivo del poll).

**Defecto:** pasar un `int`/`''` a un parámetro tipado `?\WC_Order` lanza `TypeError` (PHP no coercion a clases). El poll **muere** en el primer ítem divergente.

**Fix:** alinear el tercer parámetro. Si la causa no puede depender del pedido en el poll, la firma debe ser `divergence_cause(string $owner, int|string $s): string` y las causas `factura_fallida`/`reembolso`/`factura_pendiente` se resuelven en el **informe** (donde sí hay pedido), no en `record()`. Documentar que en el poll solo se producen `baseline_ausente`/`divergencia_dueno`.

---

### DEF-3 — CRÍTICO · Inanición de la cola: la query de reintento excluye los fallos nuevos

**Artefacto:**
- `tasks/fase-4-failed-invoice-queue.md:516-524` (T4.7) query:
  ```php
  ['key' => Invoice_Failure::META_ATTEMPTS, 'value' => $max, 'compare' => '<', 'type' => 'NUMERIC'],
  ```
- `tasks/fase-4-failed-invoice-queue.md:195-209` (T4.2) `persist()` **no** escribe `META_ATTEMPTS`.
- `tasks/fase-4-failed-invoice-queue.md:536` (T4.7) recién incrementa `attempts` dentro del loop.

**Defecto:** en `WP_Meta_Query`, un `compare` `<` con `meta_value` exige que la fila de meta exista. Un pedido recién fallado (persistido por T4.3/T4.4/T4.6) **no tiene** `_alegra_invoice_attempts`, así que la query **nunca lo selecciona**. La cola de reintento automático queda vacía para el primer fallo; solo se reintentaría algo que ya tenga `attempts` (nunca, porque solo T4.7 lo escribe). El cron no reintenta nada.

**Fix:** en `Invoice_Failure::persist()` escribir `META_ATTEMPTS = 0` si no existe; o cambiar la cláusula a `relation OR` con `['key'=>META_ATTEMPTS,'compare'=>'NOT EXISTS']` + `['key'=>META_ATTEMPTS,'value'=>$max,'compare'=>'<','type'=>'NUMERIC']`.

---

### DEF-4 — ALTO · El poll se congela en modo `invoice` cuando `S` es stale pero no vacío

**Artefacto:**
- `tasks/fase-3-poll-fix.md:237-239` (T3.1) máquina de estados:
  ```php
  $converged      = ($s !== '' && $p === '' && $w === (int) $s);
  $no_baseline_eq = ($s === '' && $p === '' && $has_a && $w === $a);
  $local_pending  = (!$converged && !$no_baseline_eq);
  ```
- `tasks/fase-3-poll-fix.md:266-274` (rama `!can_push`): si `$s === ''` re-baselina; si `$s !== ''`, `continue` sin tocar `S`.
- `design.md:1029-1039` (§7.2) afirma que el poll "re-baselina de forma conservadora" sin borrar `S`.

**Defecto:** en modo `invoice`, si `S` es un valor viejo distinto de `W` (factura abierta **fuera** de WC —escenario que `design.md:1184` declara soportado—, o instalación migrada de 2.6.0 donde `push_delta` nunca fijó `S`), el estado es `LOCAL_PENDING` con `!can_push`. El poll **nunca** re-baselina (`$s !== ''`) y **nunca** escribe WC. La dirección Alegra→WC queda muerta para ese producto. Si además `W==A`, se reporta una divergencia **falsa** permanente (el `divergence_cause` devuelve `divergencia_dueno`). Viola REQ-POLL-05 ("el poll no se apaga") y DR22.

**Fix:** agregar un estado de convergencia por evidencia de Alegra, p. ej. `if ($has_a && $w === $a) { set_synced($product_id, $a); }` antes de la rama `!can_push` (re-baseline cuando WC y Alegra ya coinciden), o re-baselinar en la rama `!can_push` cuando `W==A`. El baseline de factura de T3.3 solo cubre el caso "el plugin abrió la factura".

---

### DEF-5 — ALTO · La guarda anti-doble-descuento es solo de la UI de WP

**Artefacto:**
- `tasks/fase-2-ownership.md:624-632` (T2.5) y `:741-749` (T2.6): guarda dentro de los AJAX.
- `design.md:268-270` / `:292-293`: "imposible por construcción" / "no salteable".
- `design.md:378` (alternativa C) reconoce que no hay hook si la factura se abre fuera de WC.

**Defecto:** abrir/registrar pago desde la UI o la API de Alegra **no** pasa por el AJAX de WP. La garantía "server-enforced" es solo del panel de WP. La mitigación DR1 queda a medias.

**Fix (alcance realista):** (a) suavizar la afirmación del diseño a "no salteable **desde la UI del plugin**"; (b) reforzar con la detección de divergencia (D4) para que una apertura externa se reporte; (c) opcionalmente, en modo `adjustment`, forzar `invoice_status='draft'` (DEF-1) para que una apertura accidental no mueva stock sin confirmación.

---

### DEF-6 — ALTO · Reparación `adjustment` incorrecta y `reference` no fiable

**6a. Reparación `adjustment` (`tasks/fase-5-reconciliation.md:358-385`):**
- `$delta = (int) $product->get_stock_quantity() - (int) (Inventory_Pusher::synced($product_id) ?: 0);` usa `synced` (no `A`), y si `synced===''` el `?: 0` hace `delta = W` (un `in` gigante y falso).
- Emite `POST /inventory-adjustments` directo, **salteando** `push_delta`: sin lock, sin `pending`, sin `adjustment_already_exists`, sin `reference`, sin `mark_adjusted`, sin `set_synced`. El payload omite `unitCost` (que el propio código comenta que Alegra puede rechazar con 422) y `warehouse`.
- No actualiza `synced` ⇒ la divergencia **vuelve** en el próximo poll y la reparación es repetible/duplicable.

**Fix:** la reparación debe llamar `Inventory_Pusher::push_delta()` (o un método de reparación que reuse lock/idempotencia/reference) calculando el delta contra `A` (disponible en el informe), y actualizar `synced` con el resultado. Nunca un POST crudo.

**6b. `reference` (G3 sin verificar):** el pre-chequeo `adjustment_already_exists()` solo corre si `$pending_prev !== ''` (`Inventory_Pusher.php:242`), y el fallback Rama B compara `item+type+quantity` sobre 30 ajustes. No previene de forma fiable un duplicado ni un falso `already_applied`. El mock se extiende (H-B) aunque la API real no soporte `reference`, así que los tests pasarán en verde sin probar producción.

**Fix:** no declarar REQ-POLL-06 cumplido hasta G3; si Rama B, mantener el fallback **con** la `reference` estable **y** acotar por ventana temporal/cantidad, y aceptar el riesgo residual documentado. Considerar que un campo `reference` desconocido puede provocar 422 (verificar en G3 antes de mandarlo).

---

### DEF-7 — ALTO · La secuencia de reparación de corrupción existente es contradictoria

**Artefacto:** `tasks/fase-7-compat-repair-release.md:302-310` (T7.4) vs `tasks/fase-5-reconciliation.md:359-361` (T5.4).
- T7.4: "(a) cambiar el dueño a `invoice`; (b) emitir **un** `in qty_doble`; (c) re-baselinar".
- T5.4: `if (Inventory_Pusher::owner() !== 'adjustment') { wp_send_json_error('El dueño del stock es la factura; no se emite un ajuste.'); }`

**Defecto:** tras el paso (a) el dueño es `invoice`, y el paso (b) **no se puede ejecutar** con el código de T5.4. Además:
- El cambio de dueño es **de tienda** (`alegra_connector_stock_owner`), no por producto: reparar un producto cambia el modo de todo el comercio.
- No es atómico: si falla (b), el comercio queda en `invoice` con la corrupción intacta; si falla (c), `S` queda stale (DEF-4).

**Fix:** definir la reparación compensatoria como operación explícita que **no** dependa de `owner()` (o que emita el ajuste bajo `run_explicit` aunque el dueño sea `invoice`, con guarda de un solo mecanismo), y hacerla idempotente/reanudable (marcar el paso completado en meta). Documentar que el cambio de dueño es global y pedir confirmación.

---

### DEF-8 — MEDIO · `auto`/`invoice` + `push_orders_enabled=false` ⇒ CERO movimiento automático

**Artefacto:** `design.md:359-363` (§2.5) afirma que `push_orders_enabled` es "irrelevante para el stock" en modo `invoice`. En realidad:
- `public/Public/Public_.php:49-59` registra los hooks de pedido **solo** si `push_orders_enabled`; `Write_Gate.php:38-39` bloquea la entidad `invoice` si `push_orders_enabled=false`.
- Con `stock_owner=invoice` y `push_orders=false`: Guard 2 (`Inventory_Pusher.php:170-173`) corta los ajustes y no hay facturación automática ⇒ **cero** mecanismos automáticos.

**Fix:** documentar la combinación como "facturación manual" (el comerciante factura con "Facturar pendientes"), o coercer/avisar en la UI. No es un doble descuento, pero contradice "exactamente uno".

---

### DEF-9 — MEDIO · Coerción de `open_invoice_on_paid` dependiente del orden de guardado

**Artefacto:** `tasks/fase-2-ownership.md:939-957` (T2.8) escribe `update_option('...open_invoice_on_paid', ...)` dentro del `sanitize_callback` de `stock_owner`. Registro real de `open_invoice_on_paid`: `Admin_Dashboard.php:393-396`.

**Defecto:** `wp-admin/options.php` itera los settings registrados y, para cada uno ausente del POST, llama `update_option($option, null)`. Si `open_invoice_on_paid` se procesa **después** de `stock_owner`, la coerción se pierde (queda `null`/false). El resultado depende del orden de `register_setting` y de si el checkbox está en el DOM (no lo está si `push_orders_enabled=false`, ver `templates/admin-settings.php:109-112`). La "garantía server-side" es frágil.

**Fix:** no coercer dentro del sanitize de otro campo. Usar un hook dedicado (`pre_update_option_alegra_connector_open_invoice_on_paid` / `update_option_*`) que aplique la coerción según `stock_owner`, o resolver el estado efectivo en `owner()`/`create_invoice()` (DEF-1). Añadir test de guardado con `push_orders_enabled=false`.

---

### DEF-10 — MEDIO · El clasificador marca como retriable un skip que no debe reintentarse

**Artefacto:** `tasks/fase-2-ownership.md:500` (T2.4) devuelve `['id' => '', 'skipped' => true, 'reason' => 'adjustment_manual_only']`; `tasks/fase-4-failed-invoice-queue.md:114-154` (T4.1) no tiene rama para un array sin `id` y sin marker de bloqueo ⇒ cae al fallback `failed_retriable`/`persist=true`.

**Defecto:** el skip por G1 Rama B se persiste como fallo retriable; el cron lo reintenta hasta `max` (y cada intento vuelve a saltar). Igual para cualquier marker array desconocido.

**Fix:** en `classify()` tratar `['skipped'=>true]` como `resolved`/no persistir (o un estado `skipped` fuera de la cola). Añadir un test negativo.

---

### DEF-11 — BAJO · Fallbacks de `Invoice_Queue` y conteo

**Artefacto:** `tasks/fase-4-failed-invoice-queue.md:749-755` (T4.10) y `:759-776`.
- `array_map('intval', $res)` sobre un array de objetos (`WC_Order`) ⇒ cada elemento se castea a `1` (G4 Rama B roto).
- El fallback de `refresh_count()` capa el conteo en `limit=200`; en tiendas con >200 fallos el badge miente.
- `meta_query` `never` exige `_alegra_invoice_id` `NOT EXISTS`; un meta almacenado como `''` no entra (el diseño dice "ausente **o** vacío").
- `refresh_count()` se llama en cada `persist_invoice_result()` (`Orders.php:224-229` vía T4.5) ⇒ una meta query pesada por cada factura creada (NFR-04).

**Fix:** mapear `$o->get_id()` en el fallback; usar `$res->total` o un `count` acotado y documentar el tope; agregar `['key'=>'_alegra_invoice_id','value'=>'','compare'=>'=']` al OR; refrescar el conteo de forma diferida (al abrir la pantalla / cron) en vez de en cada éxito.

---

## UNIMPLEMENTABLE

1. **Secuencia T7.4 con el código T5.4** (DEF-7): "cambiar dueño a `invoice` → emitir `in`" es incompatible con el guard `owner!=='adjustment'` de T5.4. Tal cual, no se puede implementar.
2. **`divergence_cause($owner,$s,$p)` con `$p` int** (DEF-2): la llamada del poll no compila/ejecuta contra la firma de T5.1 (`?\WC_Order`). Fatal.
3. **Causas `factura_fallida`/`reembolso`/`factura_pendiente`** en `Stock_Divergence::divergence_cause` (`fase-5:124-137`): dependen de un `WC_Order`, pero el poll (único call site) no tiene pedido para un producto. Son código muerto en la práctica; el informe debería resolverlas por pedido, no la función de `record()`.

---

## RACES / EDGE CASES

- **Carrera poll ↔ hook en `adjustment`:** el hook fija `pending` y el poll reintenta con `from_poll=true`. El lock por producto (`Inventory_Pusher.php:226-230`) serializa, pero el pre-chequeo de idempotencia solo corre en reintento (`:242`), no en el primer POST. Un POST concurrente con el mismo `pending` podría duplicar si el lock expira (TTL 30 s) durante un request lento. Riesgo bajo, no cubierto por test.
- **`T3.3` baseline de factura con `is_paid()` como proxy:** si WC reduce stock en un hook posterior al pago (o el pedido se marca pagado sin reducción por configuración de WC), `synced=W` puede fijarse antes de que WC reduzca, enmascarando el movimiento (DR13 asumido).
- **`order_has_emitted_adjustment()` por timestamp de producto:** un producto vendido en un pedido **posterior** hace que un pedido anterior falso-positivee la advertencia (DR21, fail-safe). Y si el ajuste ocurrió antes de la creación del pedido (raro), no advierte (fail-open). No es determinista.
- **`sanitize_stock_owner` borra `alegra_connector_stock_divergence`** (`fase-2:883`) al cambiar de dueño: pierde el informe de divergencias existentes. Intencional según el diseño, pero puede ocultar corrupción previa justo cuando el comerciante cambia de modo.
- **`T5.3` meta_query OR** con `NOT EXISTS` + `=` + `IN` sobre `_alegra_invoice_id`/`_alegra_invoice_status`: WP puede generar SQL que excluya filas por el `NOT EXISTS` combinado; **sin verificar** — correr el test con pedidos en los 4 casos (ausente, `''`, `draft`, `void`).
- **`refresh_count()` tras `persist()` en cron:** el conteo se recalcula mientras el pedido acaba de guardarse; si HPOS y el índice de meta no está actualizado en el mismo request, el badge puede quedar stale. Bajo.

---

## VERIFIED-CORRECT

- **`owner()` condición doble** (`Inventory_Pusher.php:87-90`) reproducida en T2.1; el test de las 4 combinaciones es el correcto y ancla C1.
- **Guard 2** (`Inventory_Pusher.php:170-173`): corta todo ajuste originado en pedido en modo `invoice`, **antes** del baseline/POST. Correcto.
- **`_alegra_stock_adjusted_at` vs `get_date_created()`**: `time()` (epoch UTC) vs `WC_DateTime::getTimestamp()` (epoch UTC) ⇒ **sin bug de timezone**. El anterior SDD comparaba strings/`current_time('timestamp')`; acá no.
- **`in_sync` cae al writer** (T3.1): corrige la semántica de HEAD donde el `continue` de `Products.php:1350-1351` con reasons no listados caía al writer y pisaba WC. Correcto y verificado contra `Products.php:1348-1352`.
- **Baseline en el import condicionado a `updated`/`clamped_negative`** (T3.2): correcto; evita fijar `synced` cuando el writer no escribió (`skipped_*`, `dry_run`).
- **Baseline de factura con guard `is_paid()`** (T3.3): correcto (DR13).
- **Re-búsqueda post-error AC-14** (T4.3): el orden auto-sanado → clasificar → `find_existing_invoice` → persistir es correcto y usa `find_existing_invoice()` (`Orders.php:309-340`, cierre en `:340`, no `:341`).
- **Dry-run vs gate** (T4.4): `Client::write_was_blocked()`/`is_dry_run_response()` son `public static` (`Client.php:271,286,300`); la distinción es correcta.
- **Idempotencia de reintento**: `_alegra_invoice_id` (`Orders.php:82-107`), lock por pedido (`:66-79`), `find_open_invoice_for_order` (`:437-449`), AC-14 (`:133-153`) existen y cubren el caso feliz. Riesgo residual: AC-14 usa `limit=30` y el marcador en `observations`.
- **Harness**: `bash scripts/exec-test.sh` corre (docker `php:8.3-cli`) ⇒ **`EXEC-TEST OK: 1990 assertions passed, 0 failed`** en HEAD. Los cambios H-A/H-B/H-C/H-E son aditivos; el default `'open'` cuando el body omite `status` (`fase-1:260`) protege el baseline de los tests que asumían `status='open'` (`alegra-mock.php:861`).
- **Autoloader PSR-4**: `alegra-connector.php:95-134` mapea `Alegra\Connector\Sync\X` → `includes/Sync/X.php`; las 3 clases nuevas resuelven sin cambios.

---

## Opcional (fuera de alcance, máx. 2)

- **`Invoice_Failure::persist()` no escribe `META_ATTEMPTS`** (DEF-3) es también una oportunidad de simplificar: que `persist()` mantenga siempre las 7 metas (incluida `attempts`) haría la query de T4.7 más robusta y evita el `NOT EXISTS` frágil.
- **`refresh_count()` en el camino de éxito** (DEF-11) debería diferirse a la apertura de la pantalla/cron para no sumar una meta query por factura creada.

---

## Cómo verifiqué

- Leí `design.md` completo (1311 líneas) y los 8 archivos de `tasks/` (Fase 0–7).
- Leí el código real citado: `Inventory_Pusher.php` (390), `Inventory_Writer.php` (170), `Orders.php` (55–414, 480–690, 840–929, 1180–1329), `Products.php` (1140–1219, 1320–1409, 2265–2294), `Controller.php` (340–409), `Public_.php` (40–119), `Admin_Dashboard.php` (375–404, 850–919, 2830–3039), `templates/admin-settings.php` (90–119), `scripts/lib/alegra-mock.php` (850–869).
- Corrí `bash scripts/exec-test.sh` (vía docker) ⇒ 1990/0.
- Los `file:line` de los defectos apuntan al **código real** en HEAD o al **archivo de fase** indicado.
