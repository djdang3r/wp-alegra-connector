# REVIEW-momus — Cambio SDD `stock-ownership`

| Campo | Valor |
|---|---|
| Revisor | momus (adversarial plan review) |
| Fecha | 2026-09-25 |
| Alcance | `docs/sdd/stock-ownership/{proposal,spec,design,tasks}.md` + 8 fases en `tasks/` + los 3 análisis de `sync-reliability/` + código real en HEAD |
| Método | Lectura completa de los 4 artefactos + 8 fases + análisis; verificación de citas `archivo:línea` contra HEAD; traza end-to-end de las 6 tareas más riesgosas (T2.3, T2.5/T2.6, T3.1, T3.3, T4.7, T5.4/T7.4) |
| Baseline citado | `bash scripts/exec-test.sh` → **1990 assertions, 0 failed** (no re-ejecutado acá) |
| Estado | `phase0-results.md` **no existe todavía** (lo crea `T0.1`) |

> **Nota de encuadre.** El plan está **muy bien citado** (verifiqué ~35 `archivo:línea` y casi todos son exactos) y la disciplina de `prove-it-catches` es seria. Pero la garantía central —**"el doble descuento es imposible en CADA modo"**— es **falsa** contra el código real: en `adjustment` queda una vía **automática y silenciosa** que abre la factura y descuenta dos veces (B1). Además, la máquina de estados del poll **crea la starvation que dice evitar** cuando la factura se abre fuera del plugin (B2), el cron **nunca reintenta el primer fallo** (B3), y la reparación de corrupción existente es **contradictoria e inejecutable** (B4). Por eso el veredicto es **REJECT (fix-and-resubmit)**: los arreglos son localizados; no hay que rehacer el plan.

---

## VERDICT: **REJECT** (fix-and-resubmit)

El plan **no se puede ejecutar tal cual sin reintroducir el bug titular**. B1 rompe el invariante `REQ-OWN-01` ("en ningún modo") y la promesa de `adjustment` (`spec.md:243-247`); B2 rompe `NFR-02`/§3.3 ("la factura puede abrirse fuera del plugin"); B3 rompe `REQ-QUEUE-06`; B4 hace inejecutable `T7.4`. Con B1–B6 y los conflictos C1–C6 corregidos, el plan queda ejecutable y es de buena calidad.

---

## BLOCKERS (rankeados)

### B1 — En `adjustment`, la vía "Facturar" abre la factura y **descuenta dos veces** (el titular, roto)

**Dónde:** `includes/Sync/Orders.php:512` (real) vs `docs/sdd/stock-ownership/design.md:269` y `tasks/fase-2-ownership.md:415-426` (T2.3).

**El defecto.** El diseño afirma (design.md:269):

> *"`create_invoice_with_payment()` sólo pasa `'open'` si hay pago **y** el dueño es factura (§2.5)."*

**Es falso en HEAD.** El código real es:

```php
// Orders.php:504-512
$will_record_payment = !in_array($payment_account, ['', '0'], true)
    && (string) $order->get_meta('_alegra_payment_id', true) === ''
    && $order->is_paid();

$invoice_result = $this->create_invoice($order, $will_record_payment ? 'open' : null);
```

`$will_record_payment` **no consulta `owner()`**. Y el override de `create_invoice()` (`Orders.php:122-126`) sólo actúa cuando `$status_override === null`; como acá llega `'open'`, `prepare_invoice_data()` (`Orders.php:1246-1258`) crea la factura **`open`**. Con `invoice_status='draft'` el default no salva: el `status_override` gana.

**Traza (modo `adjustment`, cuenta de pago configurada, pedido pagado):**

| Paso | WC | Alegra | Acción |
|---|---|---|---|
| Venta de 3 | 10→7 | 10 | hook → `push_delta` → `POST /inventory-adjustments out 3` |
| "Facturar" (o "Facturar pendientes") | 7 | **7** | `create_invoice_with_payment()` → `create_invoice($order,'open')` |
| Factura `open` | 7 | **4** | **descuento doble**, sin advertencia, sin nota, sin log |

Las guardas `T2.5`/`T2.6` **sólo** cubren `ajax_open_invoice_impl` (`Admin_Dashboard.php:2845-2895`) y `ajax_record_payment_impl` (`:2902-3028`). **No** cubren la creación:
- `ajax_sync_single` → `sync_entity('order', …, 'complete')` (`Admin_Dashboard.php:3194`) → `create_invoice_with_payment` (`Controller.php:386`).
- `ajax_sync_pending_page_impl` (`Admin_Dashboard.php:3942`, el bulk de "Facturar seleccionados").
- `Orders::sync_recent()` (`Orders.php:1211`, "Facturar pendientes").

`T2.3` dice explícitamente "**`:122-126` no se toca**" (`fase-2:415-426`) y su aceptación *"`stock_owner=adjustment` + pedido pagado ⇒ **nunca** fija `open`"* es **inverificable con el test propuesto** (`T30.24` sólo ejercita `create_invoice()` sin el flag `'open'` de `:512`). El invariante `REQ-OWN-01` y la promesa de `spec.md:243-247` ("la factura DEBE nacer/permanecer draft") quedan incumplidos.

**Fix exacto.**
1. En `create_invoice_with_payment()` (`Orders.php:508-512`), condicionar la apertura al dueño:
   ```php
   $will_open = $will_record_payment && Inventory_Pusher::owner() === 'invoice';
   $invoice_result = $this->create_invoice($order, $will_open ? 'open' : null);
   ```
   En `adjustment`, la factura queda `draft`; `record_payment_for_invoice()` ya maneja ese caso devolviendo `draft_invoice_not_opened` → `skip_draft_payment()` (`Orders.php:576-580`), y la nota explica por qué no se registró el pago.
2. Extender la **confirmación server-enforced** (`confirm_double_discount=1`) a la vía `complete`/bulk, o —más limpio— aplicar la política de G1 Rama B también a `create_invoice_with_payment` (no crear factura automática en `adjustment`; sólo por acción manual explícita bajo `run_explicit`).
3. Corregir la prosa falsa de `design.md:269` y `fase-2:415-426`; agregar test para el path `complete` en `adjustment` (no sólo `create_invoice()`).
4. Documentar en `CHANGELOG.md` que en `adjustment` no se registra pago automático mientras la factura no abra.

---

### B2 — La máquina de estados del poll **crea la starvation que dice arreglar** (factura abierta fuera del plugin)

**Dónde:** `tasks/fase-3-poll-fix.md:214-326` (T3.1 AFTER) y `design.md:154-180,1184-1185`; `proposal.md:257` (restricción transversal).

**El defecto.** El `LOCAL_PENDING` + `!can_push` (owner=`invoice`) hace:

```php
} else {
    // owner=invoice (o push apagado): el cambio local no se puede empujar.
    if ($s === '' && $has_a) {
        Inventory_Pusher::set_synced($product_id, $a);   // baseline PRE-venta
    }
    // REQ-POLL-03/04: NO pisar WC; reportar divergencia.
    $divergence++;
    continue;
}
```

No existe el estado **`S!='' && W!=S && A==W`** (WC y Alegra **ya coinciden** pero el baseline guardado quedó viejo). Cuando el comerciante abre la factura **desde la UI de Alegra** (no desde el plugin), Alegra descuenta a `A=7`, WC ya está en 7, pero `S` sigue en 10:

- `local_pending = true`; `!can_push`; `S!==''` ⇒ no re-baselina; `continue`.
- El producto queda **`LOCAL_PENDING` para siempre**: el poll **nunca** vuelve a escribir WC para ese producto, y cualquier cambio posterior de Alegra (POS, edición manual) **tampoco baja**.
- Peor: el informe reporta una **divergencia permanente falsa** (W==A).

El propio plan promete lo contrario: `proposal.md:257` ("El poll no puede depender de que la factura se abra dentro del plugin"), `design.md:1184-1185` ("La factura puede abrirse fuera del plugin: el poll detecta el movimiento y reporta divergencia (no rompe)"). Y `T3.3` sólo re-baselina en los call sites **del plugin** (`create_invoice`, `create_invoice_with_payment`, `ajax_open_invoice_impl`). La "mitigación" es un `continue` eterno, no una detección.

**Fix exacto.** Agregar la regla de convergencia por baseline viejo, antes del `continue` de `!can_push` (y también en `can_push` cuando `S` es viejo pero `W==A`):
```php
// S viejo pero WC y Alegra ya coinciden ⇒ re-baselinar y converger (no starvar).
if ($s !== '' && $has_a && $w === $a) {
    Inventory_Pusher::set_synced($product_id, $w);
    $s = $w;                 // ahora CONVERGED ⇒ cae al writer
    // no continue
} else {
    $divergence++;
    continue;
}
```
Agregar un test específico (extender `T30.37`) con la factura abierta **fuera** del plugin y un cambio posterior de Alegra que **debe** bajar a WC. Sin esto, `NFR-02` no se cumple.

---

### B3 — El cron de reintento **nunca ve el primer fallo** (`_alegra_invoice_attempts` ausente)

**Dónde:** `tasks/fase-4-failed-invoice-queue.md:510-525` (T4.7, query) y `:195-209` (T4.2, `persist()`).

**El defecto.** La query de `retry_failed_invoices()` exige:
```php
['key' => Invoice_Failure::META_ATTEMPTS, 'value' => $max, 'compare' => '<', 'type' => 'NUMERIC'],
```
`WP_Meta_Query` (CPT y HPOS) usa **INNER JOIN**: un pedido **sin** `_alegra_invoice_attempts` **no matchea**. Pero `Invoice_Failure::persist()` (T4.2) **no inicializa `attempts`**: sólo escribe STATE/CODE/MESSAGE/RETRIABLE/LAST (+NEXT). Es decir, todo pedido recién fallado por `T4.3`/`T4.4`/`T4.6` queda `failed_retriable` **sin** attempts y **queda excluido del cron para siempre**. Contradice `REQ-QUEUE-06` ("reintentar sólo los failed_retriable con backoff y tope") y el test `T30.49` siembra el escenario de forma que puede enmascararlo.

**Fix exacto.** En `persist()` inicializar el contador cuando no exista:
```php
if (!$order->meta_exists(self::META_ATTEMPTS)) {
    $order->update_meta_data(self::META_ATTEMPTS, 0);
}
```
o agregar a la query un `OR [key => META_ATTEMPTS, compare => NOT EXISTS]`. Test: un pedido recién persistido como `failed_retriable` **debe** ser devuelto por `retry_failed_invoices()`.

---

### B4 — `T7.4` y `T5.4` se contradicen: la reparación de corrupción existente es **inejecutable**

**Dónde:** `tasks/fase-7-compat-repair-release.md:302-306` (T7.4) vs `tasks/fase-5-reconciliation.md:358-384` (T5.4).

**El defecto.** `T7.4` ordena: **(a)** cambiar el dueño a `invoice`; **(b)** emitir **un** `POST /inventory-adjustments {type:'in', quantity: qty_doble}` compensatorio; **(c)** re-baselinar `S = availableQuantity`. Y dice que la reparación es `ajax_repair_stock_divergence` (`T5.4`).

Pero `T5.4`:
```php
if (\Alegra\Connector\Sync\Inventory_Pusher::owner() !== 'adjustment') {
    wp_send_json_error(['message' => __('El dueño del stock es la factura; no se emite un ajuste.', ...)]);
}
```
Después de (a) el dueño es `invoice` ⇒ **la rama `adjustment` de `T5.4` rechaza la reparación**. Además:
- El delta de `T5.4` es `WC - synced` (`fase-5:366-367`), **no** `qty_doble` (la divergencia medida). Con `synced=''` el `?: 0` produce `delta = WC` ⇒ un `in WC` sin sentido.
- Emite el payload **directo** (`fase-5:368-376`), **sin** `unitCost` (que el pusher pone porque Alegra lo exige — `Inventory_Pusher.php:364-366`, FIX-12), **sin** `warehouse`, **sin** lock, **sin** `reference`, **sin** `mark_adjusted`.
- **Nunca** llama `set_synced()` ⇒ el ledger queda viejo y el poll vuelve a divergir/pisar.
- (a) se haría por `update_option` directo, que **bypassa** `sanitize_stock_owner()` (`T2.8`) ⇒ `open_invoice_on_paid` queda descoercionado (estado B3).

**Fix exacto.** Definir **un solo** contrato de reparación:
- O `T5.4` acepta el mecanismo `adjustment` **después** de pasar el dueño a `invoice` (flag explícito `repair_mode=legacy_compensation`), con `quantity = qty_doble` del informe, `unitCost`/`warehouse` del pusher, `set_synced(A)` al final; o
- O se crea `ajax_repair_legacy_corruption` separado con la secuencia exacta (a)→(b)→(c), usando el mismo `build_adjustment_payload()`.
En ambos casos: usar `sanitize_stock_owner()`/coerción al cambiar el dueño, y un test que asserte el orden y **un solo** mecanismo (`T30.74`).

---

### B5 — `divergence_cause()`: firma incompatible y causas **inalcanzables**

**Dónde:** `tasks/fase-3-poll-fix.md:812-821` (T3.5) vs `tasks/fase-5-reconciliation.md:119-139` (T5.1).

**El defecto.** `T3.5` llama:
```php
'cause' => Stock_Divergence::divergence_cause($owner, $s, $p),
```
`T5.1` declara:
```php
public static function divergence_cause(string $owner, int|string $s, ?\WC_Order $order = null): string
```
El 3er argumento es `$p` (`int|string`, el `pending`), no un `WC_Order`. Con `declare(strict_types=1)` (el plugin lo usa) es un **`TypeError`**. Y aunque se arreglara el tipo, el **único call site** (el poll) **nunca pasa un pedido**, así que las causas `factura_fallida`, `factura_pendiente` y `reembolso` —el valor real de `REQ-RECON-01`— son **inalcanzables**: todo cae a `divergencia_dueno`/`baseline_ausente`. El escenario `spec.md:958-964` ("muestra la causa factura fallida") no se puede cumplir.

**Fix exacto.** Una sola firma. Si se quiere la causa real, resolver el pedido en `record()`/`report()` (p.ej. el pedido pagado más reciente que contiene el producto, con `limit` acotado) y pasar el `WC_Order`; si no, sacar `factura_fallida`/`reembolso` del alcance y decirlo. Alinear `T3.5` y `T5.1` y actualizar `T30.51`.

---

### B6 — El harness **no puede ejecutar** los tests de cola/retry: el stub de `meta_query` no soporta `IN` ni `<`/`<=`/DATETIME

**Dónde:** `tasks/fase-1-cimientos.md` (T1.3/H-C) vs `scripts/lib/wp-stubs.php:1541-1566` (real) y `tasks/fase-4:783-795`.

**El defecto.** `alegra_stub_meta_clause_matches()` sólo maneja `NOT EXISTS`, `EXISTS`, `!=`, `LIKE` y `=`. `Invoice_Queue::meta_query()` usa:
```php
['key' => Invoice_Failure::META_STATE, 'value' => $states, 'compare' => 'IN'],
```
`IN` cae al `default '='` y compara contra `(string) $states` (= `"Array"`) ⇒ la cola **no matchea nunca** en el harness. `retry_failed_invoices()` usa `compare '<'` y `<=` (DATETIME), que también caen a `=`. El `H-C` de `T1.3` **sólo** agrega `paginate` a `wc_get_orders`, no extiende el motor de `meta_query`. Por lo tanto `T30.61`, `T30.62`, `T30.49`, `T30.411` **no son ejecutables** como están escritos.

**Fix exacto.** Ampliar el alcance de `T1.3` (H-C) para soportar en `alegra_stub_meta_clause_matches()`: `IN`, `NOT IN`, comparaciones numéricas `<`/`<=`/`>`/`>=` (con `type=NUMERIC`) y `DATETIME`, y `NOT EXISTS` combinado en `relation OR`. Agregar un test de harness que lo cubra antes de Fase 4.

---

## CONFLICTS (drift interno entre artefactos)

| # | Conflicto | Evidencia | Fix |
|---|---|---|---|
| C1 | **`auto` simplificado vs condición doble.** `proposal.md:168-169` y `spec.md:173` siguen diciendo `auto = invoice si push_orders_enabled`. | `design.md:28` (C1), `tasks.md:689` (I1) | Ya declarado canónico en design/tasks, pero **`spec.md` no se corrige**. Un implementador que lea spec.md solo reintroduce B3. Editar `spec.md:173` y `proposal.md:168-169`. |
| C2 | **`REQ-OWN-06` fork vs cuerpo.** `spec.md:322-324` fija "NO DEBE bloquear"; `spec.md:1278` (§G) lo trata como FORK advertir/bloquear. | `design.md:55` (C28) | Corregir `spec.md` §G: no es fork; el mecanismo (server-enforced) lo decide el diseño. |
| C3 | **Firma de `persist()`.** `design.md:1114` → `persist($order, $classification): void`; `tasks/fase-4:195` → `persist($order, array $c, ?int $next_retry_ts = null): void`. | design §9 vs T4.2 | Unificar en design §9 (agregar el 3er param). |
| C4 | **`failures_hash` con dos formatos.** `T4.10` guarda `md5((string)$count)` (`fase-4:774`); `T6.6` guarda `(string)$count` (`fase-6:512`). | T4.10 vs T6.6 | Elegir uno; además el hash debería incluir el **set** de fallos (no sólo el conteo), o un resolve+fallo con el mismo N no re-muestra el aviso. |
| C5 | **Opción `alegra_connector_inventory_reference_filter` no sembrada.** `T3.4` la lee (`fase-3:705`) pero no está en las 8 opciones de `T1.5`/`design.md:1158-1167` ni en `uninstall.php`. | fase-3 vs fase-1/design §10 | Agregarla a la tabla de opciones (o reemplazarla por una constante: G3 es decisión de build, no de runtime). |
| C6 | **`scope=failed` no filtra "failed".** `T6.5` llama `Invoice_Queue::query(['state' => 'failed'])` (`fase-6:443`), pero `'failed'` no está en `Invoice_Queue::STATES` ⇒ el `if (in_array(...))` falla y usa **todos** los estados + nunca-intentados. | fase-6 vs fase-4:783-794 | Aceptar `'failed_retriable'`/`'failed_permanent'` o un alias `'failed'` que expanda a ambos; excluir nunca-intentados del bulk de reintento. |
| C7 | **`refresh_count()` no se llama al persistir un fallo.** `T4.11` dice "Persist de un fallo: T4.3/T4.4/T4.6/T4.7 → `refresh_count()`" (`fase-4:823`), pero los snippets de T4.3/T4.4/T4.6/T4.7 **no** la llaman; sólo T4.5 (éxito) la llama. | fase-4:293-307, 359-370, 452-472, 548-563 vs :409-418 | Agregar `Invoice_Queue::refresh_count()` tras cada `persist()`; si no, el badge/aviso de `REQ-QUEUE-07` no aparece hasta abrir la cola. |
| C8 | **`T7.4` cambia el dueño por `update_option` directo**, saltando `sanitize_stock_owner()` (T2.8) ⇒ `open_invoice_on_paid` queda descoercionado. | fase-7:304 vs fase-2:875-957 | Usar el sanitizador/coerción o setear ambas opciones en la secuencia. |

---

## COVERAGE GAPS

1. **No hay tarea que cubra `create_invoice_with_payment()` con `owner=adjustment`** (B1). Es el path del botón "Facturar" y del bulk. Sin esto, `REQ-OWN-01` no se cumple.
2. **No hay tarea que re-baseline cuando la factura se abre fuera del plugin** (B2). `T3.3` sólo cubre los call sites internos. `NFR-02`/§3.3 lo exigen.
3. **`preserve=inventory` ignorado en la máquina de estados del poll.** `design.md:549-550` dice que con `preserve=inventory` el baseline debe quedar vacío, pero `NO_BASELINE_EQ` (`fase-3:275-278`) hace `set_synced($a)` sin mirar `resolve_preserve_fields()`. El poll puede fijar un baseline mentiroso y luego divergir para siempre en productos que el comerciante gestiona a mano.
4. **`REQ-QUEUE-07` (aviso/badge al fallar)** queda sin disparador real por C7: el aviso no se actualiza al persistir el fallo.
5. **`T5.4` no respeta el dueño en la rama `invoice`.** `REQ-RECON-03` (`spec.md:1023-1024`) dice "la elección del mecanismo DEBE respetar el dueño vigente"; la rama `invoice` (`fase-5:333-356`) no chequea `owner()` ⇒ puede emitir factura en `adjustment` (doble descuento).
6. **`find_paid_order_without_open_invoice()` no está definido en ningún lado** (`fase-5:335`); es un helper implícito sin contrato ni coste acotado.
7. **`unitCost`/`warehouse` faltan en el payload de reparación de `T5.4`** (`fase-5:368-375`) ⇒ probable 422 (Alegra exige `unitCost`, ver `Inventory_Pusher.php:364-366`).
8. **`maybe_self_heal_invoice_retry` no cita el enganche `init`.** El molde real se auto-sana en `init` (`alegra-connector.php:196`) además de `activate()`; `T4.8` dice "llamarlo en activate() y en el hook … donde ya se auto-sana" sin fijar el `add_action('init', …)`. Si sólo se llama en `activate()`, activar el opt-in después no agenda el cron.
9. **`R6` (doble descuento por factura abierta fuera de WC) está mapeado al test equivocado.** `tasks.md:133` y `fase-7:360` lo mapean a `T30.28` (que verifica `_alegra_stock_adjusted_at`), que **no** guarda ese escenario. El escenario real es B2 y no tiene test.

---

## RISKS (no bloqueantes)

- **`Stock_Divergence::divergence_cause()` llama `$order->get_refunds()`** en el poll (`fase-5:133-136`). Aunque sólo corre al registrar una divergencia, en un catálogo con muchas divergencias es O(divergentes) refund-queries por corrida; y con B5 el pedido nunca se pasa, así que hoy es código muerto.
- **`get_unjournaled_sales()` des-invertido** (`T5.3`) corre en cada dashboard y ahora usa `meta_query` OR con `_alegra_invoice_status IN (draft,void)`; acotado a `limit=20`, aceptable, pero conviene cachear el conteo (hoy no lo hace).
- **`Invoice_Queue::refresh_count()` fallback** hace `wc_get_orders(limit=200)` cuando no hay `->total` (`fase-4:769-771`); en tiendas con >200 fallos el conteo queda **truncado** y el badge miente. Decir "≈200+" o paginar el conteo.
- **Multisite** fuera de alcance (declarado), pero las options nuevas se siembran por sitio; no hay nota de eso.
- **Rollback**: el ledger nuevo es aditivo, correcto; pero `T7.8` promete "reinstalar 2.6.0 sin cambio de esquema" — 2.6.0 ignora los meta nuevos, ok. Sin riesgo real.

---

## VERIFIABILITY (criterios vagos o inverificables)

1. **`T2.3` aceptación "`adjustment` ⇒ nunca fija open"** es inverificable con el test propuesto: `T30.24` usa `create_invoice()` sin el `'open'` de `:512`. El caso real (B1) no se prueba.
2. **`T30.32` (titular) no fija el modo.** El test (fase-3:888-894) importa, vende y corre el poll, y assertea `synced=7`. Eso sólo es cierto en **`adjustment`** (push) o tras abrir la factura; en **`invoice`** sin factura el diseño fija `synced=A=10` (fase-3:268-270) y el assert **falla**. Hay que fijar el modo y separar el escenario invoice.
3. **`spec.md:410-415`**: "queda en 7 (**o en el valor vendido**)" — "o" deja el resultado abierto. Definir el valor exacto por modo.
4. **`REQ-RECON-01` escenario "muestra la causa factura fallida"** (`spec.md:958-964`) es inejecutable por B5 (el poll no pasa el pedido).
5. **`REQ-OWN-06` escenario "el AJAX exige nonce y capacidad"** es genérico; el plan no assertea el caso `manage_woocommerce` en los AJAX nuevos más allá de listarlo (T7.7 sí lo cubre con `T30.76`, aceptable).
6. **`NFR-04` "el badge no hace una query por página"** se verifica por `T30.411`; correcto, pero el fallback de C7/refresh truncado a 200 hace que el conteo pueda ser incorrecto — verificar el valor, no sólo que sea O(1).

---

## WHAT'S GOOD

- **Disciplina de citas sobresaliente.** Verifiqué ~35 `archivo:línea` y prácticamente todos son exactos: `Inventory_Pusher.php:85-91` (`owner()` doble), `:170-173` (Guard 2 `invoice_owner`), `:197-216` (FIX-1), `:303-335`/`:340-358` (sin `reference`); `Products.php:1348-1349` (`$handled` **sin** `invoice_owner`), `:1332-1334` (`needs_reconcile`), `:2274-2285` (W1 sin ledger); `Orders.php:88-90,122-126,494,512,850-913,309-340`; `Admin_Dashboard.php:862-887,903-905,2845-2895,2897/2902-3028,3166,3867-3979`; `Controller.php:72-74,86`; `alegra-connector.php:414-468,474-507,509-513,567,667-675`; `templates/admin-settings.php:94,101,109-112`; `.distignore:15`; `alegra-mock.php:854-866,903`; `wp-stubs.php:1568-1611`. Las correcciones C1–C28 son verificaciones reales, no maquillaje.
- **La corrección C1 es un acierto real.** Detectar que `auto` debe ser la **condición doble** (`push_orders_enabled && open_invoice_on_paid`) evita reintroducir el estado "ningún mecanismo mueve stock" (B3/Oracle#1). La tabla de verdad de 4 combinaciones (fase-2:228-233) es la prueba correcta.
- **La refutación del análisis ("starvation" → "re-inflación") es correcta** y está bien fundada (`Products.php:1348-1349`). El plan no copia el diagnóstico equivocado.
- **El orden harness-first (Fase 1 antes de tocar producto)** y la regla `prove-it-catches` por tarea central son exactamente lo que hay que hacer; la matriz R1–R20 con test+guarda es una buena herramienta de auditoría.
- **La idempotencia reusa las guardas reales** (lock por pedido `Orders.php:66-79`, `_alegra_invoice_id` `:82-107`, `find_existing_invoice` `:309-340`, `find_open_invoice_for_order` `:437-449`) y agrega la re-búsqueda post-error: es la forma correcta de "nunca duplicar".
- **El fail-loud (ledger + pantalla + badge + aviso option-backed)** responde a la demanda textual del comerciante y usa el patrón probado de `Logger.php:198-241`.

---

## Cómo verifiqué / lo que quedó sin verificar

- **Verificado por lectura directa de HEAD**: todas las citas de la tabla "WHAT'S GOOD", el `$handled` real, el `status_override` de `create_invoice_with_payment` (`Orders.php:508-512`), el `is_explicit()` real (`Write_Gate.php:137-164`), el motor `meta_query` del stub (`wp-stubs.php:1513-1566`), el mock de `POST /invoices` (`alegra-mock.php:854-866`).
- **No re-ejecuté** `bash scripts/exec-test.sh` (baseline 1990/0 citado, no reproducido en esta review). Si el veredicto depende de que el baseline esté verde, correrlo antes de aplicar.
- **G1 (¿el `draft` mueve stock?)** sigue `PENDING-LIVE`: B1 y B4 asumen Rama A (la esperada). Si G1=Rama B, B1 se agrava (la mera creación descuenta) y T2.4 es obligatorio, no opcional.
- **No pude verificar contra la cuenta viva** G2 (forma del error de stock), G3 (`reference` en GET), G4 (`paginate`+HPOS) y G5 (enumerar ajustes). Son gates de Fase 0 correctamente declarados.
