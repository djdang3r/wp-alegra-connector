# REVIEW-oracle — Correctitud técnica del plan `sync-reliability`

| Campo | Valor |
|---|---|
| Rol | Revisión de **correctitud del código planeado** (no proceso, no estilo) |
| Alcance | `design.md` D1–D8 + 10 fases de `tasks/` vs. código real en HEAD |
| Baseline | `bash scripts/exec-test.sh` ⇒ **EXEC-TEST OK: 1626 assertions passed, 0 failed** (re-ejecutado para esta revisión) |
| Método | Cada claim se contrastó con el código real y con el core de WooCommerce 9.4 (fuente descargada de `woocommerce/woocommerce`) |
| Fecha | 2026-09-25 |

---

## VERDICT: **SOUND-WITH-FIXES**

El plan tiene buenos cimientos (el harness corre en 1626/0; el escritor único, el gate `inventory` y la
detección de `wc_update_product_stock` están verificados contra el core real). **Pero la decisión
titular (D2, dueño híbrido) tiene dos defectos de integración que dejan el bug original vivo para el
modo `push_orders_enabled=true`, y un tercero que rompe la reconciliación del poll.** No se puede
implementar tal cual: los tres primeros defectos son bloqueantes.

---

## TECHNICAL DEFECTS (ranked)

### D1 — CRÍTICO — La rama `owner=invoice` NO abre una factura `draft` ya existente ⇒ el bug sobrevive

**Artefactos:** `design.md:497-501` (§3.3), `design.md:1122-1125` (§12), `tasks/fase-3-inventory-pusher.md:635-713` (T3.5).
**Código real:** `includes/Sync/Orders.php:82-90` (early return por `_alegra_invoice_id`), `:92`
(`prepare_invoice_data`), `:119-130` (POST/error), `:342-360` (`create_invoice_with_payment`),
`:408-428` + `:698-721` (`ensure_invoice_open` con `$allow_draft=false` rechaza borradores).

**Defecto:** T3.5 inserta el override `status='open'` **antes de `:92`**, pero `create_invoice()` retorna
mucho antes, en `:84-90`, cuando el pedido ya tiene `_alegra_invoice_id`. El flujo real para
`push_orders_enabled=true` es:

1. `on_new_order` → `sync_single_order('create')` → `create_invoice($order)` (`Controller.php:357-358`).
   Si el pedido **no está pagado** en ese instante, T3.5 no aplica → la factura nace `draft`
   (`Orders.php:1094`), `_alegra_invoice_id` queda seteado y **el stock NO se mueve**.
2. `payment_complete` → `create_invoice_with_payment()` → `create_invoice($order, ...)`. Como
   `_alegra_invoice_id` ya existe, **retorna en `:84-90` sin llegar al override de T3.5**. La factura
   sigue `draft`.
3. `record_payment_for_invoice()` con `$allow_open_draft=false` (`:424`) → `ensure_invoice_open()`
   devuelve `draft_invoice_not_opened` (`:716-721`) → `skip_draft_payment()` (`:527-548`) deja una nota
   y **no abre nada**.

**Por qué rompe:** Para `push_orders_enabled=true`, **cualquier pedido que no esté pagado en el momento
del primer `create_invoice()`** (transferencia, contra reembolso, pago manual, la mayoría de los
gateways) termina con una factura `draft` que nunca se abre ⇒ la factura no mueve stock. Y como el
pusher devuelve `invoice_owner` (cero ajustes), **ningún mecanismo mueve el stock**: el bug de
re-inflación/sobreventa persiste, agravado porque además el poll deja de reconciliar (ver D3). El
resultado de G1 (¿`draft` mueve stock?) es irrelevante: incluso en la rama A, la factura nunca se abre
en este flujo.

**Fix exacto:** manejar el caso "factura existente en borrador y el pedido pasa a pagado" cuando
`owner()==='invoice'`:
- En `create_invoice()`, **antes** del early return de `:82-90`: si existe `_alegra_invoice_id` y
  `owner()==='invoice'` y `get_option('alegra_connector_open_invoice_on_paid', true)` y `$order->is_paid()`,
  llamar `ensure_invoice_open($alegra_id, true)` y persistir el resultado (o al menos devolverlo).
- Y/o en `create_invoice_with_payment()`: cuando `owner()==='invoice'` y el pedido está pagado, pasar
  `$allow_open_draft=true` a la reconciliación.
- Añadir el test del flujo real: crear `draft` → marcar pagado → `create_invoice_with_payment()` →
  asertar `status==='open'` y **0** `POST /inventory-adjustments`. (El `T29.37` propuesto sólo cubre
  "pedido ya pagado al crear", que es el caso que hoy funciona.)

**Effort:** Medium (1-2 d).

---

### D2 — CRÍTICO — `set_syncing(true)` (T7.3) anula la reconciliación del poll (T3.4)

**Artefactos:** `tasks/fase-3-inventory-pusher.md:533-587` (T3.4), `:332-419` (T3.2 Guard 1);
`tasks/fase-7-poll-budget.md:445-516` (T7.3).
**Código real:** `public/Public/Public_.php:426-437` (`set_syncing`), `:442-445` (`is_syncing`),
`Products.php:1539-1543`.

**Defecto:** T7.3 setea `Public_::set_syncing(true)` **antes del loop** del poll. T3.4, dentro del loop,
llama `(new Inventory_Pusher(...))->push_delta($product, ...)` para reintentar un push pendiente. Pero
`push_delta` Guard 1 (`fase-3:338-341`) hace:

```php
if (get_transient('alegra_updating_product_'.$id) || \Alegra\Connector\Public\Public_::is_syncing()) {
    return ['pushed' => false, 'delta' => 0, 'reason' => 'syncing'];
}
```

`is_syncing()` es el **static de la misma request** (`Public_.php:444`), que T7.3 acaba de poner en
`true`. Por lo tanto **todo `push_delta` llamado desde el poll devuelve `'syncing'`**, no empuja y el poll
hace `continue` sin escribir WC. La reconciliación y el reintento de un push fallido que el diseño
promete (`design.md:595-617`, K-F) **nunca ocurren** en 2.6.0.

**Por qué rompe:** Contradicción directa entre Fase 3 y Fase 7. Además es invisible a los tests: `T29.34`
corre en Fase 3 (sin T7.3) y pasa; `T29.74` (Fase 7) verifica "0 POST" y pasa **precisamente porque**
`push_delta` es un no-op. Ningún test verifica que el poll **sí** reintente el push después de Fase 7.

**Fix exacto:** dar a `push_delta` una vía explícita para el poll, p. ej.
`push_delta(\WC_Product $p, int $new_qty, bool $from_poll = false)` y saltar el guard `is_syncing()` sólo
cuando `$from_poll === true` (el guard por-producto `alegra_updating_product_` se mantiene). Alternativa:
no envolver todo el loop con `set_syncing`; usar sólo el transient por-producto alrededor del writer y un
flag separado para cortar la cascada de `woocommerce_update_product`. Añadir un test que, **con T7.3
aplicado**, verifique que un `pending` se reintenta y se limpia (el `T29.36` actual corre en Fase 3).

**Effort:** Medium (1 d).

---

### D3 — ALTO — El poll se "estrella" (starvation) cuando `push_delta` no puede empujar

**Artefactos:** `tasks/fase-3-inventory-pusher.md:538-546` (T3.4), `:343-364` (T3.2 guards
`invoice_owner`/`disabled`/`not_linked`/`locked`).

**Defecto:** La condición del poll es:

```php
if ($product->get_manage_stock()
    && ($pending !== '' || ($synced !== '' && (int)$product->get_stock_quantity() !== (int)$synced))) {
    (new Inventory_Pusher(...))->push_delta($product, ...);
    continue;   // el poll NO escribe WC
}
```

Si `push_delta` retorna cualquiera de `invoice_owner`, `disabled`, `not_linked`, `locked` o `syncing`,
el poll hace `continue` y **nunca** escribe WC para ese producto, en **todas** las corridas futuras
(mientras siga divergente). Casos concretos:

- **`push_orders_enabled=true`** (owner=invoice): cualquier venta en WC deja `WC != synced`; el poll
  salta el producto para siempre. Combinado con D1, el producto queda congelado y Alegra desactualizado.
- **`push_inventory_enabled=false`** (el comerciante apaga el push): no se registran hooks (T3.3), pero el
  poll **sí** llama a `push_delta`, que devuelve `disabled` → el poll deja de sincronizar stock para
  cualquier producto con divergencia. Apagar el push debería significar "Alegra manda" (el poll escribe),
  no "nadie escribe".

**Fix exacto:** sólo tomar la rama de reconciliación si el push es realmente posible. Hacer que
`push_delta` devuelva un flag `can_push` (o consultar `owner()`/`push_inventory_enabled` antes), y si no
puede empujar, **caer al `Inventory_Writer::apply()`** en vez de `continue`. Para owner=invoice sin
factura abierta, decidir explícitamente: o se reconcilia vía ajuste (fallback), o se reporta divergencia
y se **deja de marcar el producto como bloqueado** (hoy queda en un limbo permanente).

**Effort:** Short-Medium (4h-1d).

---

### D4 — ALTO — Doble descuento si el POST entra y la respuesta se pierde (DR3 sin mitigar)

**Artefactos:** `design.md:588-593` (§3.4, "por defecto se acepta el riesgo"), `tasks/fase-3-inventory-pusher.md:317-419` (T3.2).

**Defecto:** `synced` sólo se actualiza con POST OK. Si el POST llegó a Alegra pero la respuesta se
perdió (timeout/reset), `synced` queda viejo y `pending` seteado. El siguiente `push_delta` recalcula
`delta = WC - synced` (el mismo delta) y lo **re-emite** ⇒ Alegra descuenta dos veces. Después el poll ve
`synced` actualizado a WC, `WC == synced`, y **escribe el valor ya doblemente descontado** en WC: el stock
queda por debajo de lo real. La única mitigación propuesta es un `GET /inventory-adjustments?item_id=…`
"opcional según G3", que **no está implementado** en T3.2.

**Fix exacto:** antes de re-emitir desde la rama `pending`, hacer la pre-búsqueda
`Client::get_inventory_adjustments(['item_id'=>$alegra_item, ...])` y, si ya existe un ajuste con el mismo
`date`/`type`/`quantity` posterior a `synced`, considerarlo aplicado (`synced = pending; clear pending`).
O persistir el `delta` aplicado + un nonce de intento y deduplicar. Sin esto, DR3 no es "riesgo acotado":
es un camino normal de red.

**Effort:** Medium (1 d).

---

### D5 — ALTO — El baseline en el primer hook se come la primera venta post-upgrade y re-infla

**Artefactos:** `design.md:614-617` (§3.5, DR2), `tasks/fase-3-inventory-pusher.md:366-371` (T3.2 baseline).

**Defecto:** En la primera llamada del hook tras el upgrade, `_alegra_stock_synced` está vacío ⇒
`push_delta` setea `synced = $new_qty` (el valor **post-venta**) y retorna `baseline` **sin empujar**. Es
decir, la primera venta local se registra como "ya acordada" y no se comunica a Alegra. Luego el poll ve
`WC == synced` y escribe el valor viejo de Alegra ⇒ **re-inflación de la primera venta post-upgrade**. El
diseño dice que la ventana se limita a "ventas previas al upgrade", pero esto afecta a una venta
**posterior**.

**Fix exacto:** no baselinar en el hook. En la primera llamada (synced vacío), obtener el stock real de
Alegra (`GET /items/{id}`) y fijar `synced` a ese valor **antes** de calcular el delta (así la venta se
empuja), o baselinar a `$new_qty + (unidades de esta venta)` si el hook aporta esa info. El baseline
"silencioso" debería quedar sólo en el camino del poll.

**Effort:** Short (2-4 h).

---

### D6 — ALTO — El lock global del cron no se libera en un fatal

**Artefactos:** `tasks/fase-8-lock-cron.md:61-134` (T8.1), `design.md:905-921` (§8.1).
**Código real:** `Controller.php:141` (lock `alegra_cron_global`, TTL 600), `:157` (release en `finally`).

**Defecto:** T8.1 registra `register_shutdown_function` **sólo** para `alegra_sync_running_products`
(el lock del poll). El lock global `alegra_cron_global` se libera únicamente en el `finally` de
`run_cron_sync()` (`Controller.php:156-158`). Un fatal dentro del poll saltea ese `finally` ⇒ el lock
global queda tomado 600 s y **todo el cron se saltea** hasta que expire. El objetivo declarado de
REQ-POLL-03 ("un fatal no deja el lock colgado") no se cumple para el lock más importante.

**Fix exacto:** registrar en `run_cron_sync()` un shutdown handler que libere `alegra_cron_global` (y
opcionalmente los locks por entidad), o un único handler que libere ambos locks. Mantener el TTL 600 como
backstop.

**Effort:** Short (2 h).

---

### D7 — MEDIO — El mock NO pagina `GET /items` sin `metadata=true` ⇒ los tests del cursor (Fase 7) no prueban nada

**Artefactos:** `tasks/fase-7-poll-budget.md:200-217` (T7.1.b usa `start`/`limit` sin metadata),
`:703-704` (T7.6 afirma "el mock debe respetar start/limit (lo hace)").
**Código real:** `scripts/lib/alegra-mock.php:659-671`: `GET /items` sólo aplica `array_slice($filtered, $start, $limit)`
**si** `metadata=true`; si no, devuelve **todos** los ítems filtrados (línea 671). `alegra_mock_filter_items()`
(`:869-924`) no corta por `start`/`limit`.

**Por qué rompe:** El poll llama `get_items(['start'=>$cursor,'limit'=>30,'mode'=>'advanced'])` **sin**
`metadata`. El mock devuelve el catálogo completo en cada página ⇒ `count($items) >= 30` siempre (con >30
ítems) ⇒ el cursor avanza 30 por iteración pero el contenido es idéntico. `T29.71` (pausa por budget +
reanuda por cursor) y `T29.73` (reset por total encogido) no pueden verificar la paginación; `T29.71`
incluso puede fallar por el conteo del cursor. El mismo problema afecta a `import_from_alegra` (que
también usa `start`/`limit` sin metadata) para catálogos >30, aunque sus tests actuales usan catálogos
chicos.

**Fix exacto:** en la ruta `GET /items` del mock, aplicar `start`/`limit` **siempre** (o agregar un flag
`alegra_mock_set_items_pagination(true)` default on) y correr el harness completo para confirmar que la
base no se mueve de 1626/0. Documentar el cambio en D8/H1–H8.

**Effort:** Short (2-3 h).

---

### D8 — MEDIO — Race entre el poll y el pusher sobre el mismo producto

**Artefactos:** `tasks/fase-3-inventory-pusher.md:378-417` (lock por producto sólo en `push_delta`),
`tasks/fase-7-poll-budget.md:237-263` (el writer del poll no toma ese lock).

**Defecto:** `push_delta` toma `alegra_inventory_push_{id}` (TTL 30), pero `Inventory_Writer::apply()` del
poll **no** toma ese lock. Dos workers concurrentes (poll en worker A, venta WC en worker B) pueden:
A escribe `_stock`/`_stock_status` desde Alegra mientras B calcula el delta contra `synced` y empuja. El
resultado puede ser un `synced` inconsistente o una re-inflación. El lock global `products` sólo lo tiene
el poll, no el pusher.

**Fix exacto:** que el poll tome el mismo lock por producto (`alegra_inventory_push_{id}`) alrededor del
`apply()` (y del chequeo del ledger), de modo que poll y pusher se serialicen por producto. Alternativa:
hacer el chequeo del ledger + escritura una operación compare-and-set sobre las metas.

**Effort:** Short (2-4 h).

---

### D9 — MEDIO — El self-heal re-resuelve con el `resolve()` viejo (`limit=5`), no con el barrido paginado

**Artefactos:** `tasks/fase-5-consumidor-final.md:826-902` (T5.5 paso 5), `design.md:329-330`.
**Código real:** `includes/Consumidor_Final.php:181-184` (`resolve()` pide `limit=5`); `scan_candidates`
nuevo (T5.1) pagina a 30.

**Defecto:** El fix central de REQ-CF-05 (CONTAINS + paginación) vive en `probe()`/`resolve_readonly()`.
Pero el auto-sanado llama `Consumidor_Final::get_or_create_id()`, que internamente usa `resolve()` con
`limit=5`. Si el CF real está enterrado detrás de >5 falsos positivos CONTAINS, la re-resolución falla
igual que antes del fix, y el self-heal no puede recuperarse (borra el meta y devuelve error). El fix de
CF-05 no se aplica al camino que más lo necesita.

**Fix exacto:** en el self-heal, usar `resolve_readonly()`/`scan_candidates()` para encontrar el CF y sólo
caer a `create()` (bajo `run_explicit`) si el barrido completo dio `not_found`.

**Effort:** Short (2 h).

---

### D10 — MEDIO — Quitar el `_stock_status` forzado cambia el comportamiento con umbral de no-stock ≠ 0

**Artefactos:** `design.md:796-815` (§6.1, "con `backorders=no` idéntico a HEAD"), `tasks/fase-2-inventory-writer.md:724-777` (T2.6).
**Código real:** `Products.php:1259` y `:2099` fuerzan `$qty > 0 ? 'instock' : 'outofstock'`.

**Defecto (verificado contra WC 9.4):** `WC_Product::validate_props()`
(`abstract-wc-product.php:1419-1442`) deriva el estado como
`(int)qty > absint(get_option('woocommerce_notify_no_stock_amount', 0)) ? instock : (backorders ? onbackorder : outofstock)`.
Con el umbral por defecto (0) el resultado coincide con HEAD. **Pero si el comerciante configuró
`woocommerce_notify_no_stock_amount > 0`**, un producto con `qty=1` y `backorders=no` pasa a
`outofstock` donde HEAD forzaba `instock`. El claim "idéntico a HEAD" es falso fuera del default.

**Fix exacto:** documentar el cambio en `CHANGELOG.md`/release (la tarea T9.5 ya prevé notas de cambio
intencional) y añadir un test con `notify_no_stock_amount=2` para fijar la semántica. No requiere código,
pero sí dejar de afirmar equivalencia total.

**Effort:** Quick (1 h, documentación + test).

---

### D11 — MEDIO — Los tests `T29.34`/`T29.35` asumen que `save()` dispara el hook de stock (el stub no lo hace)

**Artefactos:** `tasks/fase-3-inventory-pusher.md:616-626` (T29.34), `:499-505` (T29.35).
**Código real:** `scripts/lib/wp-stubs.php:1284` — `WC_Product::save()` es `return $this->id;` y **no**
dispara `woocommerce_product_set_stock` ni `woocommerce_update_product`. T1.2 sólo agrega la derivación de
status, no hooks.

**Defecto:** `T29.34` simula la venta con `$p->set_stock_quantity(7); $p->save();` y comenta "(dispara el
hook)". En el harness **no dispara nada** ⇒ el mock no baja a 7, no hay POST y el test no ejerce el
titular; su `prove-it-catches` es vacuo. `T29.35` hace `do_action('woocommerce_product_set_stock', ...)`
pero no muestra quién registra los hooks del pusher (los registra `Public_::__construct`, que el test no
instancia).

**Fix exacto:** en los tests, simular la venta con `wc_update_product_stock($p, 7, 'set', false)` (el
stub H1 sí dispara el hook) o llamar `(new Inventory_Pusher(...))->push_delta(...)` directamente; y
registrar los hooks con `Inventory_Pusher::register_hooks($api, $logger)` o instanciando `Public_`. Añadir
al stub `save()` el disparo de `woocommerce_update_product` si se quiere modelar la cascada real.

**Effort:** Short (2-4 h).

---

### D12 — MEDIO — `unitCost` obligatorio con default `0.0` puede ser rechazado por Alegra (SIN VERIFICAR, G3)

**Artefactos:** `tasks/fase-3-inventory-pusher.md:92-105` (K-A), `:257-270` (`unit_cost`).
**Defecto:** El payload corregido exige `unitCost`; el pusher lo defaulta a `0.0` cuando no hay
`_wc_cog_cost`/`_cost`. Si la cuenta rechaza `unitCost=0` (algunos endpoints exigen >0), **todo** push de
un producto sin costo falla con 422/400. El gate G3 debe confirmar si `0` es aceptado.

**Fix exacto:** G3 (T0.3) debe probar explícitamente `unitCost: 0`; si falla, usar `price` del producto o
un mínimo, o desactivar el push por defecto (rama B de G3).

**Effort:** Quick (parte de G3).

---

### D13 — MEDIO — `GET /items?variantParent_id` sin verificar y `limit=100` probablemente capado a 30

**Artefactos:** `tasks/fase-4-variaciones.md:88-116` (T4.1), `design.md:748-763`.
**Defecto:** (a) La existencia/forma del filtro en producción es **SIN VERIFICAR** (G2). (b) El import de
productos usa `limit=30` como máximo documentado; pedir `limit=100` puede devolver sólo 30, perdiendo
variaciones de padres con >30 hijos. (c) `import_variation_from_alegra` re-hace `GET /items/{child}` por
cada hijo (N+1) aunque el payload de la lista ya traiga el objeto completo.

**Fix exacto:** verificar el filtro y el tope reales en la cuenta (G2); si el tope es 30, paginar el fetch
de hijos; reusar el payload del hijo en vez de re-consultar por `id`.

**Effort:** Short (2-4 h) + verificación.

---

### D14 — BAJO/MEDIO — Riesgo de merge `T1.10` → `T3.1` (los helpers del ledger se pierden)

**Artefactos:** `tasks/fase-1-cimientos.md:932-1057` (T1.10 crea la clase con helpers estáticos),
`tasks/fase-3-inventory-pusher.md:133-283` (T3.1 muestra la clase **sin** los helpers).
**Defecto:** El código de T3.1 es una definición de clase completa que **omite** `META_SYNCED`,
`META_PENDING`, `synced()`, `set_synced()`, `pending()`, `set_pending()`, `clear_pending()`. Si el worker
reemplaza el archivo con el bloque de T3.1, `T1.10` se pierde y `T3.2` (que usa `update_post_meta`
directo, pero también las metas) y los tests `T29.110` fallan. El texto dice "NO redefine estos helpers",
pero el bloque de código invita al reemplazo.

**Fix exacto:** en T3.1, mostrar el archivo **completo** (helpers de T1.10 + lo nuevo) o instruir
explícitamente "agregar los métodos al archivo existente, no reemplazarlo".

**Effort:** Quick (0.5 h, edición de la tarea).

---

### D15 — BAJO — `T29.51` no discrimina paginación (pasa sin H3b)

**Artefactos:** `tasks/fase-5-consumidor-final.md:229-269`.
**Defecto:** Con 30 decoys + 1 CF real, tanto con H3b (2 páginas) como sin H3b (1 página con 31
contactos) el resultado es `found='cf-real'` y `scanned=31`. El test no prueba que se hayan pedido 2
páginas. La segunda mitad (301 decoys → `truncated`) tampoco distingue (sin H3b escanea 3010 y el loop
igual corta a 10 páginas).

**Fix exacto:** assertar el número de `GET /contacts` (p.ej. `alegra_mock_count('GET','/contacts') === 2`)
o capturar el `start` de cada request.

**Effort:** Quick (1 h).

---

### D16 — BAJO — `register_shutdown_function`: cubre fatales y `max_execution_time`, no SIGKILL/OOM-killer

**Artefactos:** `design.md:905-921`, `tasks/fase-8-lock-cron.md:61-134`.
**Verificación:** En PHP, los shutdown functions **sí** corren en `E_ERROR`, excepciones no capturadas y
en el fatal de `max_execution_time` (el claim del diseño se cumple para timeouts). **No** corren ante
`SIGKILL`/OOM-killer del SO, y ante `memory_limit` agotado pueden no poder asignar memoria para
`get_option`/`delete_option`. El TTL 300/600 queda como backstop, así que el riesgo es acotado, pero el
diseño no debe afirmar cobertura total.

**Fix exacto:** aclarar en el diseño que es best-effort y que el TTL es la garantía real; ya previsto.

**Effort:** Quick (documentación).

---

## UNIMPLEMENTABLE

Nada es estrictamente imposible en WordPress/WC/PHP. Puntos a corregir de la premisa:

- **`register_shutdown_function` en timeout:** SÍ corre en `max_execution_time` (fatal). El diseño lo
  subestima al ponerlo como "backstop"; en realidad es efectivo para timeouts. No cubre SIGKILL/OOM.
- **`wc_update_product_stock($p,$qty,'set',false)` en WC viejos:** seguro. El 4.º argumento existe desde
  WC 6.2; en WC 3.0–6.1 PHP ignora argumentos extra de funciones de usuario (sin `TypeError`); en <3.0 no
  existe y el `function_exists` + fallback lo cubre. La firma real es
  `wc_update_product_stock($product, $stock_quantity = null, $operation = 'set', $updating = false)`
  (`wc-stock-functions.php:28`). **Correcto.**
- **`GET /items?variantParent_id`:** puede no existir en producción (SIN VERIFICAR). No es "imposible",
  pero el plan lo trata como disponible en el mock y lo marca bloqueado. Correcto marcarlo.
- **`POST /inventory-adjustments` con `unitCost=0`:** puede ser rechazado. SIN VERIFICAR (G3).

---

## RACE CONDITIONS / EDGE CASES que el plan omite

1. **Poll vs. pusher por producto** (D8): el poll no toma el lock por producto que sí toma el pusher.
2. **`synced` como compare-and-set no atómico:** el poll lee `synced`, escribe WC y luego `update_post_meta`.
   Entre la lectura y la escritura, un pusher concurrente puede cambiar `synced`; el poll sobreescribe con
   un valor obsoleto. Falta un CAS real (p.ej. `add_post_meta` con valor único + reintento).
3. **Cursor sin orden estable:** el poll usa `start`/`limit` sin `order_field`/`order_direction`
   (`fase-7:213-217`). Si Alegra cambia el orden por defecto entre corridas (altas/bajas), el resume
   puede saltear o reprocesar ítems. Mismo patrón que el import, pero conviene fijar el orden si la API
   lo soporta. **SIN VERIFICAR.**
4. **`from_zero` + `pull_total`:** T7.4 borra cursor y total; el poll, si `known_total` quedó stale de una
   corrida anterior mayor, puede resetear el cursor "de más" (inofensivo, pero re-escanea). Documentado.
5. **Variación con stock gestionado por el padre:** `wc_update_product_stock` puede disparar
   `woocommerce_product_set_stock` con el **padre** (`wc-stock-functions.php:41-70`), no
   `woocommerce_variation_set_stock`. El pusher entonces empuja el `_alegra_item_id` del padre. Edge case
   no cubierto.
6. **Doble disparo del hook:** verificado que `wc_update_product_stock` dispara
   `woocommerce_product_set_stock` **una vez** (el `save()` interno sólo dispara `_set_stock_status`,
   porque `read_stock_quantity` escribe con `object_read=false` y no marca `stock_quantity` como cambio).
   No hay doble push por este lado. **Correcto.**
7. **`push_delta` sin chequeo de `pending`:** un cambio local posterior a un push fallido recalcula el
   delta contra `synced` (correcto) pero re-POSTea el acumulado; si el primer POST había entrado, se
   duplica (mismo caso D4).

---

## VERIFIED-CORRECT (lo que sí sostiene)

- **Baseline del harness:** `bash scripts/exec-test.sh` ⇒ `EXEC-TEST OK: 1626 assertions passed, 0 failed`
  (ejecutado en esta revisión). El conteo del plan es correcto.
- **D5 / `wc_update_product_stock`:** el core WC 9.4 deriva `_stock_status` en `save()` →
  `validate_props()` (`abstract-wc-product.php:1419-1442`); el `set_manage_stock(true)` previo es
  necesario porque la función exige `managing_stock()` (`wc-stock-functions.php:36`). El claim central de
  D5 es correcto (con la salvedad del umbral, D10).
- **El hook `woocommerce_product_set_stock` dispara una sola vez** por `wc_update_product_stock(..., false)`
  (el `save()` interno dispara `_set_stock_status`, no `_set_stock`). El pusher no se duplica por esto.
- **`Client` error shape:** `new WP_Error('api_error', $msg, ['code'=>$code,'response'=>$error_data])`
  (`Client.php:242`) — el detector del self-heal (T5.5) lee exactamente `get_error_data()['code']` y
  `['response']`. Correcto.
- **`write_was_blocked()`** devuelve bool para dry-run/gate (arrays con `dry_run`/`blocked_by_gate`)
  (`Client.php:271-303`). El pusher lo usa antes de tocar `synced`. Correcto.
- **`Write_Gate`:** T1.8 es **necesario** — sin `ENTITY_DEFAULTS['inventory'] = true`, `block_reason()`
  devolvería `entity_disabled` en una instalación existente (`Write_Gate.php:118-124`). El diseño lo
  omitía; la corrección de T1.8 es correcta y crítica.
- **`set_syncing` / transient:** `set_syncing(true)` escribe `alegra_import_in_progress`
  (`Public_.php:433`) y `trigger_sync()` lo consulta (`:371`). La afirmación de la corrección #1 del
  diseño es correcta; el static `is_syncing()` es el que rompe D2.
- **Cursor del poll:** el gate `$known_total > 0` (T7.2.b) evita el bug de "total nunca seteado". La
  persistencia por página y el borrado al completar son correctos.
- **Patrón del import (`Products.php:1305-1322`, `:1432-1442`):** citado correctamente; el poll lo copia
  fielmente.
- **Self-heal del CF:** la condición (400 + mención de cliente + id CF + guard transient) es
  conservadora; no loop ni duplica en los casos cubiertos por `find_existing_invoice()`. El guard
  transient evita el loop entre requests.
- **`resolve_warehouse_id()` es privado** (`Products.php:1104-1110`); el pusher necesita su propia copia
  (T3.1). Correcto.
- **`is_consumidor_final()`** lee caché/override sin red (`Consumidor_Final.php:405-425`). Correcto para
  el detector.
- **Mock:** soporta `variantParent_id` (`alegra-mock.php:880-885`) y `client_id` en `/invoices`
  (`:1000-1008`); `alegra_mock_validation_error` produce `['code'=>400,...]` que el `Client` mapea a
  `get_error_data()['code']===400`. Los tests del self-heal son viables.
- **H5 opt-in:** el chequeo estricto de `client.id` debe ser opt-in para no romper la base (tests con
  `c0n-co`/`c1` sin sembrar, `exec-test.php:265-326,4748+`). La corrección es correcta.

---

## CHECKS EXACTOS PENDIENTES (para cerrar lo "unverified")

1. **G1 ampliado (D1):** crear un pedido **no pagado** con `push_orders=true` → factura `draft`; luego
   marcarlo pagado y correr `create_invoice_with_payment()`. Verificar si la factura pasa a `open` y si el
   stock de Alegra baja. (El gate G1 original no cubre este flujo.)
2. **G3:** `POST /inventory-adjustments` con `unitCost: 0` → confirmar 200/422.
3. **G2:** `GET /items?variantParent_id={id}` con un padre de >30 hijos → confirmar que el filtro existe y
   si `limit=100` se respeta o se capa a 30.
4. **Cursor:** `GET /items?start=N&limit=30&mode=advanced` repetido → confirmar orden estable entre
   llamadas y soporte de `order_field`/`order_direction`.
5. **Lock shutdown:** matar el proceso con `kill -9` durante el poll → confirmar que el lock sólo se
   libera por TTL (esperado) y documentar.

---

## Resumen de fixes mínimos para pasar a SOUND

1. **D1:** abrir la factura `draft` existente cuando el pedido pasa a pagado y `owner=invoice` (o
   fallback a ajuste). — bloqueante
2. **D2:** permitir que el poll llame a `push_delta` sin el guard `is_syncing()` (flag `from_poll`). —
   bloqueante
3. **D3:** no hacer `continue` cuando `push_delta` no puede empujar; caer al writer o reportar
   divergencia sin congelar el producto. — bloqueante
4. **D4:** pre-búsqueda de idempotencia antes de re-emitir un `pending`. — alto
5. **D6:** shutdown handler para `alegra_cron_global`. — alto
6. **D7:** el mock debe paginar `GET /items` sin `metadata`. — alto (testabilidad)
7. **D5/D8/D9/D10/D11:** fixes acotados descritos arriba. — medios
