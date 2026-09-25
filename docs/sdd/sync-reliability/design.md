# Diseño Técnico — Fiabilidad de sincronización: Consumidor Final, inventario y poll (`sync-reliability`)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Versión analizada | 2.5.1 (`alegra-connector.php:6`) |
| Versión objetivo | **2.6.0** (cambios de comportamiento + opciones nuevas ⇒ minor) |
| Naturaleza | Diseño técnico (no implementación) |
| Regla | Toda decisión nombra archivo, método y opción; lo no verificado es **SIN VERIFICAR / BLOQUEADO** |

> Este diseño es **cerrado**: dos desarrolladores implementan lo mismo. Donde hay elección, está
> tomada y justificada. Donde depende de Fase 0, se declaran las ramas y el punto exacto de
> bifurcación. **Las citas `archivo:línea` fueron re-verificadas leyendo el código en HEAD**; las
> correcciones a la propuesta/spec van en §Correcciones.

---

## Correcciones de cita (re-verificadas en HEAD)

La spec ya corrigió varias citas. Al re-leer el código aparecen **correcciones y hallazgos nuevos**
que cambian el diseño y que `tasks.md` va a consumir.

| # | Claim de la spec/propuesta | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| 1 | `Public_.php:73,289` (cascada por `save()`; `:289` guard `is_syncing`) | El archivo real es **`public/Public/Public_.php`**. `:73` registra `woocommerce_update_product`; el handler es **`:226-234`** (`on_update_product`). **`on_update_product` NO chequea `is_syncing()`**: sólo chequea el transient `alegra_updating_product_{id}` (`:229`). La cascada la corta `set_syncing(true)` **porque escribe el transient `alegra_import_in_progress` (`:433`), que `trigger_sync()` sí consulta (`:371`)**. `:289` es el guard `is_syncing` de `on_order_paid_reconcile`, **otro** handler. | El diseño de REQ-POLL-04 depende del **transient** (TTL 300), no del static. Si el poll dura >300 s el transient expira y la cascada vuelve ⇒ DR8. |
| 2 | `set_syncing` "solo en import `Products.php:1542`" | Confirmado `:1542` (true) + `:1632` (false); **también** `Customers.php:316,374` (la spec ya lo matiza). El poll **no** lo llama. | Correcto; se agrega al poll. |
| 3 | `Orders.php:97-117` = "pre-búsqueda de idempotencia" | `:97-117` es el **call site + manejo**; el método es `find_existing_invoice()` en **`Orders.php:266-297`** (match por `observations` `/Pedido WooCommerce #(\d+)/`, `:289`). El POST está en **`:119`**. | La auto-sanación reusa `find_existing_invoice()`; se cita el método real. |
| 4 | `persist_contact_id()` (`Orders.php:1428-1435`) persiste **antes** del POST | Confirmado. Además, `ensure_customer_synced()` **paso 1** (`:1177-1180`) devuelve el `_billing_alegra_contact_id` cacheado **sin verificar que el contacto siga existiendo** ⇒ camino del id muerto. | El auto-sanado debe **sobrescribir/borrar** ese meta. |
| 5 | `Products.php:2031-2034` afirma que WC no deriva `_stock_status`; REFUTADO por `abstract-wc-product.php:1517-1538,1547` | El docblock es exacto (`:2031-2034`). La refutación en el core de WC **no vive en este repo**. La doc oficial de WC confirma que `wc_update_product_stock()` llama `save()`, que recalcula el estado, y que las escrituras disparan `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` (ver §D5). | D5 usa la API recomendada de WC; la cita externa se reemplaza por la doc. |
| 6 | El mock modela el filtro `identification` | **Falso.** `scripts/lib/alegra-mock.php:863` compara **igualdad exacta** (`$num === $ident`), no CONTAINS. | REQ-CF-05 no se puede testear sin cambiar el harness ⇒ D8. |
| 7 | `wc_update_product_stock` | 0 coincidencias en producción **y ausente de `scripts/lib/wp-stubs.php`**. | D5 no es testeable sin stub ⇒ D8. |
| 8 | Endpoint `/inventory-adjustments` | **No hay ruta** en `scripts/lib/alegra-mock.php` (grep de `inventory-adjustments` = 0 en el mock). | D2 (a) no es testeable sin mock ⇒ D8. |
| 9 | El handler de conectar es `Admin_Dashboard.php:1638-1743` | El método `ajax_test_connection()` es **`:1638-1738`** (cierra en `:1738`). | Corrección de rango. |
| 10 | `alegra_connector_import_time_budget` / `_max_pages` | Están `register_setting` (`Admin_Dashboard.php:496,507`) y se leen con default (`Products.php:1318,1326`) pero **no** están en `$defaults`/`$non_autoload` de `alegra-connector.php:414-486`. | El diseño sigue el mismo patrón (read-with-default) para las opciones del poll, y las agrega a `$defaults`/`$non_autoload`/`uninstall.php`. |

**Hechos confirmados por grep en producción (excluye `scripts/`, `docs/`):**

- `open_invoice_on_paid`, `inventory_manage_stock_enabled`, `inventory_dry_run`, `inventory_pull_cursor`,
  `Inventory_Writer`, `wc_update_product_stock`, `register_shutdown_function`, `DISABLE_WP_CRON`,
  `wp_doing_cron` → **0 coincidencias**.
- `.distignore:15` excluye `scripts/` del ZIP. Confirmado.
- `alegra_connector_inventory_sync_enabled` default **true** (`alegra-connector.php:449`) y el poll corre
  cuando `inventory_source=alegra && inventory_sync_enabled` (`Controller.php:209-210`).

---

## 0. Mapa de cambios

| # | Archivo | Símbolo | Tipo |
|---|---|---|---|
| A | `includes/Consumidor_Final.php` | `resolve_readonly()`, `probe()`, `scan_candidates()`, `probe_state()`, opción de probe | editar |
| A | `includes/Sync/Orders.php` | `try_self_heal_dead_client()` + llamada en `create_invoice()`; **abrir borrador existente** (`ensure_invoice_open`) en `create_invoice()`/`create_invoice_with_payment()` (FIX-3) | editar |
| A | `admin/Admin/Admin_Dashboard.php` | conectar resuelve CF; AJAX `alegra_verify_consumidor_final`; `register_settings`; vars del dashboard; **guarda de factura manual en `owner=adjustment`** (FIX-3) | editar |
| A | `templates/admin-dashboard.php` | fila CF honesta + "Verificar ahora" | editar |
| B | `includes/Sync/Inventory_Writer.php` | **clase nueva** — único escritor de stock WC | **crear** |
| B | `includes/Sync/Inventory_Pusher.php` | **clase nueva** — push WC→Alegra (ajuste) + ledger + `owner()` + idempotencia | **crear** |
| B | `includes/Sync/Stock_Order_Context.php` | **clase nueva** — pedido asociado al movimiento de stock (guarda FIX-3; sujeta a G9) | **crear** |
| B | `includes/Sync/Products.php` | W1/W2 delegan; poll budget/cursor/`truncated`/`total`; reconciliador; variaciones en update; `preserve_fields`; `set_syncing`; `_stock_status` | editar |
| B | `includes/Write_Gate.php` | entidad `inventory` + patrón `/inventory-adjustments` + `ENTITY_DEFAULTS['inventory']=true` | editar |
| B | `includes/API/Client.php` | quitar `@deprecated` de `create_inventory_adjustment()` (firma intacta) | editar |
| B | `public/Public/Public_.php` | registrar hooks de stock del pusher; `set_syncing` con profundidad; poblar `Stock_Order_Context` (FIX-3) | editar |
| C | `includes/Sync/Controller.php` | run budget + deadline global; shutdown handler de **ambos** locks (FIX-5) | editar |
| C | `admin/Admin/Admin_Dashboard.php` | opciones del poll; pista de cron real; `open_invoice_on_paid` (FIX-6) | editar |
| C | `templates/admin-settings.php` | recomendación de cron real; budget/tope del poll; opt-in manage_stock; push de inventario; `open_invoice_on_paid` | editar |
| D | `alegra-connector.php` | `$defaults`/`$non_autoload`; seed; registro de hooks | editar |
| D | `uninstall.php` | opciones nuevas | editar |
| D | `CHANGELOG.md` | notas de comportamiento intencional | editar |
| D | `scripts/lib/wp-stubs.php`, `scripts/lib/alegra-mock.php`, `scripts/exec-test.php` | harness (NO se distribuye) | editar |

**NO TOCAR (restricción explícita):** `languages/*`, `*/index.php`.

---

## 1. Arquitectura

### 1.1 Principios

1. **Un solo escritor de stock WC.** `Inventory_Writer::apply()` es el único lugar del plugin que
   llama `set_manage_stock()`/`set_stock_quantity()`/`set_stock_status()` y `wc_update_product_stock()`.
2. **Un solo dueño del movimiento de stock en Alegra.** O la **factura** (cuando el plugin factura
   **y** la factura realmente mueve stock) o el **ajuste** (cuando no). Nunca los dos para el mismo
   movimiento (REQ-INV-08). **El dueño se computa con la condición completa (D2/FIX-3): `invoice`
   sólo si `push_orders_enabled && open_invoice_on_paid`; el resto de los casos es `adjustment`.** La
   factura manual puede mover stock aunque el dueño sea `adjustment` ⇒ guarda en §3.3/§3.4 (FIX-3).
3. **La ausencia de dato no es cero.** `availableQuantity` ausente/null ⇒ SKIP, nunca 0.
4. **El poll nunca re-infla.** Antes de escribir el valor de Alegra en WC, compara WC vs el ledger
   (`_alegra_stock_synced` / `_alegra_stock_push_pending`). Si hay un cambio local sin empujar, no
   pisa: reconcilia.
5. **El presupuesto es propio, no del host.** Corte por wall-clock + cursor persistido. `set_time_limit`
   es backstop, no defensa.
6. **Fail-loud.** Toda truncación, lock tomado, rate-limit o fallo de CF deja señal visible.
7. **Plugin distribuido.** Cero supuestos de host: sin `exec`, sin cron real, sin `set_time_limit`
   efectivo, sin Action Scheduler obligatorio.

### 1.2 Componentes

```
                          ┌────────────────────────────────────────────┐
                          │              Write_Gate                     │
                          │  kill switch (duro) + entidad + explicit    │
                          │  entidades: … , contact, item, inventory(N) │
                          └───────────────┬─────────────────────────────┘
                                          │ todo POST/PUT/PATCH/DELETE
                                          ▼
   ┌───────────────┐    ┌──────────────────────────────┐    ┌──────────────┐
   │ admin/dashboard│    │           Sync\*             │    │  API\Client  │
   │  "Verificar"   │───▶│  Products / Orders / Ctrl    │───▶│  /contacts   │
   │  "Conectar"    │    │                              │    │  /items      │
   └───────┬───────┘    │  ┌────────────────────────┐  │    │  /inventory- │
           │            │  │ Inventory_Writer (N)   │  │    │   adjustments│
           ▼            │  │  único escritor WC     │  │    └──────┬───────┘
   ┌───────────────┐    │  └───────────┬────────────┘  │           │
   │Consumidor_Final│   │  ┌───────────▼────────────┐  │           │
   │ resolve_readonly│  │  │ Inventory_Pusher (N)   │──┼───────────┘
   │ probe()        │    │  │  ledger + delta push   │  │
   └───────────────┘    │  └────────────────────────┘  │
                        └──────────────────────────────┘
```

### 1.3 Flujo FRONT A (Consumidor Final)

```
Conectar (ajax_test_connection)
  guard nonce + manage_options
  save credentials
  preflight /company  → WP_Error ⇒ "no verificado", error
  api->test_connection
  update_option(connection_tested, true)
  ┌─ Write_Gate::run_explicit(function () {                    ◀── D1
  │     $probe = Consumidor_Final::probe();   // GET only, NUNCA POST
  │     persist_option(consumidor_final_probe, $probe)
  │  })
  wp_send_json_success([... , 'consumidor_final' => $probe])

Dashboard (render, SIN red)
  is_connected? ── no ─▶ "No verificado (sin conexión)"
                ── sí ─▶ peek_id()!=false ─▶ "Disponible"
                          probe.state=not_found ─▶ "No encontrado en Alegra" + "Crear"
                          else ─▶ "No verificado" + "Verificar ahora"

"Verificar ahora" (ajax_verify_consumidor_final)
  guard nonce + manage_options
  Write_Gate::run_explicit(fn ⇒ Consumidor_Final::probe())
  responde {success, data:{state, message}}  → JS actualiza la fila sin reload

Factura 400 por client id muerto (Orders::create_invoice)
  POST /invoices → WP_Error code 400 con client
  ¿el client id es el CF cacheado? ── no ─▶ return error (sin cambios)
                                     ── sí ─▶ Consumidor_Final::invalidate_cache()
                                              re-probe (GET)
                                              find_existing_invoice(order, old_client)  ◀── no duplicar
                                              rebuild $data['client'] + reintento UNA vez
                                              nota + log ("auto-sanado" o fallo accionable)
```

### 1.4 Flujo FRONT B (inventario)

```
DUEÑO (D2, CORREGIDO — FIX-3) = 'invoice' SOLO SI
        push_orders_enabled === true  Y  open_invoice_on_paid === true
        (en cualquier otro caso: 'adjustment').
        Con push_orders=true + open_invoice_on_paid=false NINGÚN mecanismo mueve
        stock por factura ⇒ el dueño real es el ajuste (si no, REQ-INV-01 queda sin cubrir).

Venta / edición / reembolso en WC
  WC reduce/sube stock (order hooks de WC core usan wc_update_product_stock)
        │ dispara
        ▼
  woocommerce_product_set_stock / woocommerce_variation_set_stock
        │  (WC pasa el OBJETO WC_Product, no un int — C2/FIX-7)
        ▼
  Inventory_Pusher::on_stock_changed($product)
     normalize_product() (acepta WC_Product|int)
     if is_syncing() ∨ transient alegra_updating_product_{id} ⇒ skip   ◀── D2 (FIX-2: guard en el HOOK)
     if owner()==='invoice' ⇒ skip (la factura es dueña) + marcar divergencia
     push_delta($product, $new, from_poll: false)
        guards: syncing(transient) / not_linked / disabled / not_manageable
        lock por producto (alegra_inventory_push_{id}, TTL 30)
        $s = meta(_alegra_stock_synced)
        if $s === ''  ⇒ pending = $new; return 'baseline_pending'       ◀── D2 (FIX-1)
                        (NUNCA synced = valor de WC)
        $delta = $new - $s;  delta==0 ⇒ skip
        pre-búsqueda OBLIGATORIA GET /inventory-adjustments (idempotencia) ◀── D2 (FIX-4)
        meta(_alegra_stock_push_pending) = $new
        POST /inventory-adjustments {items:[{id,type,quantity,unitCost}], date, warehouse?}
        ok ⇒ meta(_alegra_stock_synced)=$new; clear pending
        fail ⇒ deja pending (el poll reintenta) + log + nota

Reconciliador del poll (Alegra → WC), por ítem                ◀── D2 (FIX-1/FIX-8)
  $a = availableQuantity ; $w = WC qty ; $s = synced ; $p = pending
  dueño = Inventory_Pusher::owner(); push_on = push_inventory_enabled
  ── Push POSIBLE (dueño adjustment ∧ push_on ∧ vinculado):
     $p !== '' ∨ ($s !== '' ∧ $w != $s) ⇒ reconciliar WC→Alegra:
         si $s === ''  ⇒ GET /items/{id} ⇒ $s = availableQuantity (baseline REAL de Alegra)
                          y recién entonces push_delta(..., from_poll: true)
         push_delta(..., from_poll: true)   ◀── FIX-2: bypass del guard is_syncing
         ok/already_applied ⇒ cae al writer (escribe $a; si el push fue OK, $a == $w)
         api_error/blocked/locked ⇒ NO escribe WC (preserva el movimiento local) + divergencia
         sin baseline obtenible ($s sigue '') ⇒ NO escribe WC (evita re-inflar) + divergencia
  ── Push NO posible:
     push_on=false   ⇒ el poll ES la fuente ⇒ Inventory_Writer::apply() escribe $a
     dueño invoice   ⇒ NO pisa WC hasta que la factura esté open (divergencia, REQ-INV-07)
     (el `continue` sólo vale cuando hay un delta local sin empujar que NO se pudo empujar;
      NUNCA para saltear el writer cuando el poll es el dueño)       ◀── FIX-8
  ── Sin delta local ($s === $w): Inventory_Writer::apply() escribe $a; synced=$a

Variaciones (update path)
  import_single_item_from_alegra(item variantParent existente)
     update_product_from_alegra(parent)
     children = get_variant_children(item)   // itemVariants|subitems
     if empty ⇒ GET /items?variantParent_id={id}      ◀── D4 (SIN VERIFICAR en prod)
     foreach child ⇒ import_variation_from_alegra(parent, child)  // update ⇒ W1
  item type=variant suelto
     parent = item.variantParent.id ; import_variation_from_alegra(parent, item)
     sin parent ⇒ log explícito (nunca skip mudo)
```

### 1.5 Flujo FRONT C (poll/cron)

```
run_cron_sync (Controller)
  global lock alegra_cron_global TTL 600
  register_shutdown_function(release alegra_cron_global)            ◀── D7 (FIX-5: cubre AMBOS locks)
  $deadline = microtime(true) + cron_run_budget (default 540)       ◀── D7
  Run_Context::wrap('cron_sync_all')
     import_from_alegra(..., $run_deadline = $deadline)   // min(240, deadline)
     sync_inventory_from_alegra($run_id, $deadline)
        lock products (TTL 300) + register_shutdown_function(release products) ◀── D7 (FIX-5)
        set_syncing(true)  (transient 300 ⇒ refrescar)
        GET /items?metadata=true ⇒ total real ⇒ option(_pull_total)   ◀── D6 (FIX-9)
        cursor = option(alegra_connector_inventory_pull_cursor, 0)
        if total > 0 ∧ cursor >= total ⇒ cursor = 0; delete cursor    ◀── D6 (FIX-9)
        try
          while true
            if deadline vencido ⇒ truncated=true; persistir cursor; break
            if page > max_pages ⇒ truncated=true; break
            GET /items?start=cursor&limit=30&mode=advanced
            foreach item ⇒ reconciliador (§3.5) + Inventory_Writer
            cursor += 30 ; persistir option
            count(items) < 30 ⇒ completed; delete cursor; delete total; break
        finally
          set_syncing(false)
          release products lock
```

---

## 2. D1 — FRONT A: Consumidor Final

### 2.1 Punto de inserción exacto en `ajax_test_connection`

**Archivo:** `admin/Admin/Admin_Dashboard.php`, método `ajax_test_connection()` (`:1638-1738`).

**Punto de inserción:** **después** de `update_option('alegra_connector_connection_tested', true)`
(`:1722`) y **antes** de `wp_send_json_success([...])` (`:1729`). Motivo: `wp_send_json_*()` termina
el request con `wp_die()` → `die()`, que **no ejecuta `finally`**; la resolución tiene que completarse
antes de responder (mismo hallazgo de plataforma que `logs-monitor-import` §0). No se usa `finally`
para la persistencia.

```php
// Admin_Dashboard::ajax_test_connection() — tras :1722
$cf_probe = \Alegra\Connector\Write_Gate::run_explicit(
    static fn (): array => \Alegra\Connector\Consumidor_Final::probe()
);
update_option('alegra_connector_consumidor_final_probe', $cf_probe, false);

wp_send_json_success([
    'message'          => __('Conexión exitosa', 'alegra-connector'),
    // … campos actuales …
    'consumidor_final' => $cf_probe,
]);
```

**Por qué `run_explicit()` aunque `probe()` sea read-only:** (1) mantiene **una sola ruta** entre
conectar y "Verificar ahora" (REQ-CF-01/03); (2) deja el contexto listo si el comerciante elige el
sub-flujo explícito "Crear Consumidor Final" (REQ-CF-04 Rama B), que sí hace `POST /contacts`. La
compuerta de entidad `contact` (`Write_Gate.php:41`) y el guard de `create()`
(`Consumidor_Final.php:272-277`) siguen siendo la autoridad: con el kill switch activo no hay POST.

### 2.2 `resolve_readonly()` vs create-on-connect (FORK) — DECISIÓN

**Decisión: `resolve_readonly()` por defecto. La creación NO ocurre al conectar; ocurre sólo por una
acción explícita separada ("Crear Consumidor Final").**

| Criterio | Rama A — `resolve_readonly()` (elegida) | Rama B — crear al conectar |
|---|---|---|
| Intención de la acción | "Probar conexión" no debe tener efectos de escritura. | Mezcla test + alta de contacto. |
| Kill switch / compuerta | Nunca POSTea; imposible violar la compuerta `contact`. | Depende de `run_explicit` + kill switch. |
| Honestidad de UI | "No encontrado" es verificable y el botón "Crear" es explícito. | "Conectar" crea en silencio; el comerciante no lo pidió. |
| Regresión | Nula: el render sigue read-only (REQ-RB-1). | Un fallo de red al crear deja un estado ambiguo. |
| Cobertura | Si el CF no existe, la factura sigue resolviéndolo por `get_or_create_id()` (push_customers/explicit). | Ídem. |

**Rama A adoptada.** El escenario negativo de REQ-CF-01 (el render no resuelve ni crea) y REQ-CF-04
Rama A se cumplen. La creación queda como botón explícito "Crear Consumidor Final" (mismo AJAX, con
`create=true`), bajo `run_explicit()`, que llama a `get_or_create_id()`.

### 2.3 Modelo de estados del dashboard

**Estados (exactos):**

| Estado | Texto UI | Condición | Acción ofrecida |
|---|---|---|---|
| `disconnected` | "No verificado (sin conexión)" | `!is_connected` | "Conectar ahora" |
| `available` | "Disponible" | `peek_id() !== false` (cache/override) **o** probe `available` | — |
| `not_found` | "No encontrado en Alegra" | probe `not_found` (barrido **completo** sin match exacto) | "Crear Consumidor Final" |
| `unverified` | "No verificado" | conectado, sin probe, o probe `unverified` (red/API/truncado) | "Verificar ahora" |

**Persistencia del estado:** opción `alegra_connector_consumidor_final_probe` (array, autoload no):
`['state' => 'available'|'not_found'|'unverified', 'id' => ?string, 'reason' => string, 'at' => int]`.
El **render** lee la opción y `peek_id()`; **nunca** toca la red (REQ-CF-02 escenario negativo).

**AJAX "Verificar ahora":**

| Campo | Valor |
|---|---|
| action | `alegra_verify_consumidor_final` |
| registro | `add_action('wp_ajax_alegra_verify_consumidor_final', [$this, 'ajax_verify_consumidor_final'])` junto a `:39` |
| nonce | `check_ajax_referer('alegra_connector_nonce')` |
| capacidad | `current_user_can('manage_options')` (spec REQ-CF-03 escenario seguridad) |
| cuerpo | `create` (bool, opcional) — si `true`, además crea (bajo `run_explicit`) |
| respuesta | `{success:true, data:{state, id, message}}` **siempre** con `message` |
| efecto | `Write_Gate::run_explicit()` → `Consumidor_Final::probe()` → persiste la opción |

**Contrato:** se mantiene `success`/`data`; se agrega `message` en ambas ramas (NFR-04).

### 2.4 Auto-sanado de caché podrida (Caso D)

**Dónde se detecta:** `Orders::create_invoice()`, **después** del POST en `:119` y dentro del
`if (is_wp_error($result))` de `:121-129`.

**Condición exacta de disparo (conservadora):**
1. `$result` es `WP_Error` con `$result->get_error_data()['code'] === 400` (el `Client` devuelve
   `new WP_Error('api_error', $msg, ['code' => $code, 'response' => $error_data])`, `Client.php:242`), **y**
2. el error referencia al cliente (`response.client` presente **o** el mensaje matchea
   `/client|cliente/i`), **y**
3. el `client.id` usado (`$data['client']['id']`) es el **CF** (`Consumidor_Final::is_consumidor_final($id)`
   o `peek_id()`), **y**
4. todavía no se reintentó (`$retried === false`).

**Acción (reintento único, sin duplicar):**

```php
private function try_self_heal_dead_client(\WC_Order $order, array $data, \WP_Error $error): ?array
{
    $code = (int) (($error->get_error_data()['code'] ?? 0));
    if ($code !== 400) return null;
    $body = $error->get_error_data()['response'] ?? [];
    $mentions_client = isset($body['client']) || stripos($error->get_error_message(), 'client') !== false
        || stripos($error->get_error_message(), 'cliente') !== false;
    if (!$mentions_client) return null;

    $old_id = (string) ($data['client']['id'] ?? '');
    if ($old_id === '' || !\Alegra\Connector\Consumidor_Final::is_consumidor_final($old_id)) return null;

    // 1) invalidar + re-resolver con el BARRIDO PAGINADO (D9/FIX-10).
    //    get_or_create_id() usa resolve() con limit=5 (Consumidor_Final.php:181-184):
    //    si el CF real está enterrado detrás de >5 falsos positivos CONTAINS, la
    //    re-resolución fallaba igual que antes del fix. Acá se usa el camino nuevo.
    \Alegra\Connector\Consumidor_Final::invalidate_cache();
    $new_id = \Alegra\Connector\Consumidor_Final::resolve_readonly();   // scan_candidates (paginado, GET-only)
    if ($new_id === false || $new_id === '') {
        // Sólo se permite CREAR bajo contexto explícito (REQ-CF-04). El self-heal
        // de create_invoice() es automático: NUNCA crea en silencio.
        if (\Alegra\Connector\Write_Gate::is_explicit()) {
            $new_id = \Alegra\Connector\Consumidor_Final::get_or_create_id();
        }
    }
    if ($new_id === false || $new_id === '') {
        // No se pudo re-resolver: soltar el id muerto del pedido para no re-loopear.
        $order->delete_meta_data('_billing_alegra_contact_id');
        $order->add_order_note(__('[Alegra] El Consumidor Final cambió en Alegra y no se pudo re-resolver (barrido completo). Revisá el contacto.', 'alegra-connector'));
        return null;
    }

    // 2) idempotencia ANTES de reintentar (no duplicar la factura)
    $existing = $this->find_existing_invoice($order, $new_id);
    if ($existing !== null && !empty($existing['id'])) {
        $this->persist_invoice_result($order, (string) $existing['id'], $existing);
        return ['id' => (string) $existing['id'], 'already_exists' => true, 'self_healed' => true];
    }

    // 3) reintento ÚNICO con el nuevo client
    $data['client'] = ['id' => $new_id] + $data['client'];
    $retry = $this->api->create_invoice($data);
    if (is_wp_error($retry)) {
        $order->add_order_note(sprintf(
            __('[Alegra] No se pudo facturar tras re-resolver el Consumidor Final: %s', 'alegra-connector'),
            $retry->get_error_message()
        ));
        return null;
    }
    if (isset($retry['id'])) {
        $this->persist_invoice_result($order, (string) $retry['id'], $retry);
        $order->add_order_note(__('[Alegra] Consumidor Final re-resuelto y factura creada (auto-sanado).', 'alegra-connector'));
    }
    return $retry;
}
```

Se invoca desde `create_invoice()` en la rama de error de `:121-129`:

```php
if (is_wp_error($result)) {
    $healed = $this->try_self_heal_dead_client($order, $data, $result);
    if ($healed !== null) return $healed;
    // … log actual …
    return $result;
}
```

**No rompe el camino que hoy funciona:** sólo dispara con **400 + mención de cliente + id CF + una
sola vez**. Cualquier otro error (401, 422, red, 5xx) retorna como en HEAD. El `find_existing_invoice()`
(`:266-297`) evita duplicar si el primer POST entró.

### 2.5 Mitigación del `limit=5` CONTAINS — DECISIÓN

**Decisión: paginar el barrido con `limit=30` y verificar cada página completa; concluir
`not_found` sólo con un barrido COMPLETO; si se agota un tope de páginas, reportar `unverified`.**

```php
private static function scan_candidates(Client $client): array
{
    $per_page   = 30;                 // máximo documentado
    $max_pages  = 10;                 // 300 candidatos; más ⇒ unverified
    $found      = null;
    $scanned    = 0;
    $complete   = false;

    for ($p = 0; $p < $max_pages; $p++) {
        $batch = $client->get_contacts([
            'identification' => self::IDENTIFICATION,   // CONTAINS en Alegra
            'limit'          => $per_page,
            'start'          => $p * $per_page,
        ]);
        if (is_wp_error($batch)) {
            return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'api_error'];
        }
        if (!is_array($batch)) {
            return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'bad_response'];
        }

        foreach ($batch as $contact) {
            $scanned++;
            if (is_array($contact) && isset($contact['id']) && self::matches($contact)) {
                $found = (string) $contact['id'];
                self::store_metadata($contact);
                break 2;
            }
        }

        // Una página llena NO es "fin": pedir la siguiente.
        if (count($batch) < $per_page) { $complete = true; break; }
    }

    return ['found' => $found, 'complete' => $complete, 'scanned' => $scanned,
            'reason' => $found !== null ? 'match' : ($complete ? 'not_found' : 'truncated')];
}
```

**Por qué no subir el `limit` a 30 y listo:** CONTAINS puede devolver cientos/miles; 30 no garantiza
cubrir al CF real. Paginar es correcto; el tope + `unverified` evita crear sobre un barrido incompleto.
**Por qué no usar `identificationObject.number` server-side:** el filtro documentado es `identification`
(CONTAINS); `matches()` (`:241-251`) ya exige igualdad exacta del lado del cliente y se mantiene.

### 2.6 `probe()` — contrato

```php
/**
 * @return array{state:'available'|'not_found'|'unverified', id:?string, reason:string, scanned:int}
 */
public static function probe(?Client $client = null): array
{
    // 1. cache/override ⇒ available (sin red)
    $peeked = self::peek_id();
    if ($peeked !== false) {
        return ['state' => 'available', 'id' => $peeked, 'reason' => 'cached', 'scanned' => 0];
    }
    if ($client === null) { $client = new Client(); }

    // 2. barrido read-only (GET + match + cache)
    $scan = self::scan_candidates($client);
    if ($scan['found'] !== null) {
        self::cache_id($scan['found']);        // transient + option
        return ['state' => 'available', 'id' => $scan['found'], 'reason' => 'match', 'scanned' => $scan['scanned']];
    }
    if ($scan['complete']) {
        return ['state' => 'not_found', 'id' => null, 'reason' => 'not_found', 'scanned' => $scan['scanned']];
    }
    // red caída o barrido truncado ⇒ honesto: no verificado, NUNCA "no encontrado"
    return ['state' => 'unverified', 'id' => null, 'reason' => $scan['reason'], 'scanned' => $scan['scanned']];
}

/** Lee la opción persistida sin red (para el render). */
public static function probe_state(): array;

/** GET + match + cache, NUNCA POST. Devuelve el id o false. */
public static function resolve_readonly(?Client $client = null): string|false;
```

`resolve()` (el que puede crear) se conserva intacto para el camino de factura.

---

## 3. D2 — FRONT B: cerrar la re-inflación (LA DECISIÓN TITULAR)

### 3.1 El problema en una frase

`push_orders_enabled=false` (default) + `invoice_status=draft` (default) ⇒ **ninguna factura mueve
stock en Alegra**, y el poll (`Products.php:1238-1260`) escribe el valor viejo de Alegra sobre el stock
ya vendido en WC. El resultado es sobreventa.

### 3.2 Matriz de decisión

| Criterio | (a) Ajuste WC→Alegra (delta) | (b) Factura al pagar | Híbrido elegido (dueño por tienda) |
|---|---|---|---|
| Complejidad | Alta: delta tracking + ledger + idempotencia + lock. | Media: implementar `open_invoice_on_paid` + depender de R1. | Media-alta: (a) con ledger; (b) sólo abre facturas. |
| Falla si… | El push falla ⇒ el poll reintenta (ledger `pending`). | La factura no se crea (push_orders off) o no se abre (R1 falso) ⇒ **no cubre nada**. | Cada modo tiene su fallback. |
| Cobertura | **Toda** venta/edición/reembolso de WC. | Sólo ventas **facturadas y abiertas**. | Config `push_orders=false` ⇒ (a). Config `true` ⇒ (b). |
| Doble conteo | Riesgo si además se factura. | Sin doble conteo (Alegra descuenta nativo). | Un solo dueño **si `owner()` exige que las facturas muevan stock** (D2/FIX-3); con factura manual en modo `adjustment` hay guarda explícita (§3.3). |
| Distribuido | No depende de facturación ni de R1. | Depende de R1 (SIN VERIFICAR) y de que cada venta facture. | (a) no depende de R1; (b) sí. |
| Default install | **Arregla el titular**. | **NO arregla el titular** (push_orders=false). | Arregla el titular en el default. |

### 3.3 Decisión

**Híbrido con dueño de movimiento a nivel tienda, derivado de `push_orders_enabled` y de que las
facturas realmente muevan stock (FIX-3):**

```
owner = (push_orders_enabled === true && open_invoice_on_paid === true)
            ? 'invoice'
            : 'adjustment'
```

**Por qué la condición doble (defecto B3/Oracle#1):** `push_orders_enabled=true` **no** alcanza.
Con `open_invoice_on_paid=false`, `create_invoice()` nace `draft` (`Orders.php:1094`) y el stock no se
mueve; y aunque `open_invoice_on_paid=true`, si el pedido ya tenía `_alegra_invoice_id` el
`create_invoice()` **retorna temprano** (`Orders.php:82-90`) y **nunca abre el borrador**. En ambos
casos, con `owner='invoice'` el pusher no emite ajustes y la factura no mueve stock ⇒ **ningún mecanismo
mueve stock** (REQ-INV-01 violado). La condición doble garantiza que `invoice` sólo se elige cuando las
facturas efectivamente mueven stock.

- **`owner = adjustment`** (default: `push_orders=false`): el plugin es dueño del movimiento vía
  `POST /inventory-adjustments` con **delta tracking** (D2.4). Cubre ventas, ediciones manuales y
  reembolsos. **Este es el caso que arregla el titular por defecto.**
- **`owner = invoice`** (`push_orders=true` **y** `open_invoice_on_paid=true`): la **factura** es dueña.
  El plugin **NO** emite ajustes para movimientos de pedido. Los movimientos sin pedido (edición manual,
  POS-en-WC) **no se empujan** en este modo: se **reportan como divergencia** (REQ-INV-07) y la UI lo
  declara. *(Limitación explícita y honesta; no se inventa cobertura.)*

**`owner = invoice` — abrir el borrador existente (FIX-3, cierra Oracle#1).** Hoy el flujo real deja la
factura en `draft` y nunca la abre:

1. `on_new_order` → `create_invoice()` si el pedido **no** está pagado ⇒ nace `draft`
   (`Orders.php:1094`), `_alegra_invoice_id` queda seteado y el stock **no** se mueve.
2. `payment_complete` → `create_invoice_with_payment()` → `create_invoice()`; como
   `_alegra_invoice_id` ya existe, **retorna en `Orders.php:84-90` sin abrir**.
3. `record_payment_for_invoice()` con `$allow_open_draft=false` (`Orders.php:424`) →
   `ensure_invoice_open()` devuelve `draft_invoice_not_opened` (`Orders.php:716-721`) → `skip_draft_payment()`.

**Decisión:** en `owner='invoice'`, `create_invoice()` debe **abrir el borrador existente** cuando el
pedido pasa a pagado, **antes** del early-return de `:82-90`:

```php
$alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);
if ($alegra_id !== '') {
    // D2/FIX-3: la factura ya existe. Si el pedido pasó a pagado y la factura es la
    // dueña, ABRIR el borrador AHORA. Si no, el stock nunca se mueve (el early-return
    // de :84-90 lo dejaba en draft para siempre).
    if (Inventory_Pusher::owner() === 'invoice'
        && (bool) get_option('alegra_connector_open_invoice_on_paid', true)
        && $order->is_paid()) {
        $opened = $this->ensure_invoice_open($alegra_id, true);   // $allow_draft = true (abrir)
        if (!is_wp_error($opened)) {
            $this->persist_invoice_status($order, $opened);
            $order->add_order_note(__('[Alegra] Factura en borrador abierta al confirmarse el pago (dueño factura).', 'alegra-connector'));
        }
    }
    return ['id' => $alegra_id, 'already_exists' => true];
}
```

Y en `create_invoice_with_payment()`, cuando `owner()==='invoice'` y `$order->is_paid()`, pasar
`$allow_open_draft = true` a `record_payment_for_invoice()` (hoy pasa `false` por default,
`Orders.php:424`), para que la reconciliación automática del pago abra el borrador existente. El
override `status='open'` de creación (T3.5) sigue aplicando **sólo** cuando la factura se crea en ese
momento y `$order->is_paid()`.

**`owner = adjustment` — guarda contra la factura manual (FIX-3, cierra B3).** La facturación **manual**
(`admin/Admin/Admin_Dashboard.php:2693-2730` "Abrir factura" y `:2778-2781` "Registrar pago") **no está
gated por `push_orders_enabled`**: con `owner=adjustment` el comerciante puede abrir la factura y
descontar stock nativo **además** del ajuste ⇒ doble descuento. Guardas obligatorias:

1. **En el pusher (`push_delta`):** antes de emitir un ajuste, resolver el pedido asociado al movimiento
   (contexto request-scoped que `Public_` puebla desde los hooks de stock de pedido de WC
   `woocommerce_reduce_order_stock` / `woocommerce_restore_order_stock`) y, si ese pedido tiene
   `_alegra_invoice_status === 'open'`, **no emitir el ajuste** (`reason='invoice_owner'`): la factura
   ya movió el stock. Si no se puede resolver el pedido, se emite el ajuste (comportamiento actual).
   *(Verificar en Fase 0 — G9 — que el hook de pedido corre antes del hook de producto; si no, esta
   guarda se degrada a la guarda 2.)*
2. **En la acción manual (`ajax_open_invoice` / `ajax_record_payment`):** si `owner()==='adjustment'` y
   el pedido tiene un ajuste ya emitido por el plugin (meta `_alegra_stock_adjusted` seteada por el
   pusher), **advertir explícitamente** que abrir la factura descontaría dos veces y exigir confirmación
   del comerciante; registrar nota + log. Nunca abrir en silencio.

**Doble conteo (REQ-INV-08):** con la condición doble de `owner()` y las guardas 1/2, un movimiento
tiene **un solo dueño**. Ya **no** se afirma "imposible por construcción": la factura manual es un
camino real que el dueño a nivel tienda no cubre por sí solo (B3).

**Esto contradice `docs/sdd/inventory/` DD-8 ("no cablear `create_inventory_adjustment()`") y su
principio #4 ("la factura es el único mecanismo").** Motivo del cambio: DD-8 asumía que (b) cubriría
las ventas; con los defaults (`push_orders=false`, `invoice_status=draft`) (b) **no cubre nada** y el
titular persiste. La intención de DD-8 (evitar doble conteo) se **preserva** vía el dueño a nivel
tienda **más** las guardas de la factura manual, que son más fuertes que la regla ad-hoc que DD-8 temía.
Se documenta como decisión explícita.

**Rechazo del híbrido fino ("(b) para pedidos, (a) para el resto" en el mismo modo):** requeriría
distinguir por movimiento si vino de un pedido, acoplando hooks de pedido (`woocommerce_reduce_order_item_stock`)
con el hook genérico de stock; el hook de **restauración** de stock no está confirmado (SIN VERIFICAR) y
el acoplamiento es frágil. El dueño a nivel tienda es determinista y testeable. La guarda 1 usa los
hooks de pedido **sólo para suprimir** el ajuste cuando ya hay factura `open` (no para elegir dueño por
movimiento).

### 3.4 Delta tracking + idempotencia (el ledger)

**Metas por producto (post meta):**

| Meta | Tipo | Significado |
|---|---|---|
| `_alegra_stock_synced` | int | Último valor en el que **WC y Alegra acordaron**. Se fija **SÓLO** con (a) un push OK o (b) el pull del poll al escribir el valor de Alegra. **NUNCA con un valor de WC sin push** (FIX-1). |
| `_alegra_stock_push_pending` | int | Valor de WC que se está intentando empujar (se setea antes del POST, se limpia al OK o al detectar que ya se aplicó). |
| `_alegra_stock_adjusted` | string | (opcional) Marca de que el plugin ya emitió un ajuste para el movimiento de este pedido. La escribe el pusher; la lee la guarda manual de §3.3. |

**`Inventory_Pusher::push_delta()` (contrato — FIX-1/2/3/4/10):**

```php
/**
 * Empuja el delta WC→Alegra.
 *
 * @param bool $from_poll true ⇒ lo llama el reconciliador del poll: BYPASEA el
 *                        guard `is_syncing()` (el poll lo seteó) pero mantiene el
 *                        guard por producto. FIX-2.
 * @return array{pushed:bool, delta:int, reason:string}
 */
public function push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array
{
    $id = (int) $product->get_id();

    // Guard 1 (FIX-2): el guard `is_syncing()` vive en el HOOK (on_stock_changed),
    // no acá. En push_delta sólo queda el guard por producto, que $from_poll
    // puede saltear (el poll ES quien escribe desde Alegra y debe reconciliar).
    if (!$from_poll && get_transient('alegra_updating_product_' . $id)) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'syncing'];
    }
    if (self::owner() !== 'adjustment') {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'invoice_owner'];
    }
    if (!get_option('alegra_connector_push_inventory_enabled', true)) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'disabled'];
    }
    $alegra_item = (string) get_post_meta($id, '_alegra_item_id', true);
    if ($alegra_item === '') {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'not_linked'];
    }
    if (!$product->get_manage_stock()) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'not_manageable'];
    }

    // Guard 6 (FIX-3): si el pedido asociado al movimiento ya tiene factura `open`,
    // la factura movió el stock ⇒ NO emitir ajuste (evita doble descuento).
    $order_id = \Alegra\Connector\Sync\Stock_Order_Context::current_order_id();
    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order
            && (string) $order->get_meta('_alegra_invoice_status', true) === 'open') {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'invoice_owner'];
        }
    }

    $synced = get_post_meta($id, '_alegra_stock_synced', true);
    if ($synced === '') {
        // FIX-1: NUNCA baselinar con el valor de WC (eso re-infla la venta).
        // `synced` se fija SÓLO con un push OK o con el pull del poll (§3.5).
        update_post_meta($id, '_alegra_stock_push_pending', $new_qty);
        return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_pending'];
    }
    $delta = $new_qty - (int) $synced;
    if ($delta === 0) {
        return ['pushed' => false, 'delta' => 0, 'reason' => 'in_sync'];
    }

    $token = \Alegra\Connector\Sync\Controller::acquire_lock('alegra_inventory_push_' . $id, 30);
    if ($token === false) {
        return ['pushed' => false, 'delta' => $delta, 'reason' => 'locked'];
    }
    try {
        // FIX-4: pre-búsqueda OBLIGATORIA de idempotencia. Si el POST anterior entró
        // y la respuesta se perdió, ya hay un ajuste con la misma `reference`.
        $ref      = $this->adjustment_reference($id, (int) $synced, $new_qty);
        $existing = $this->find_existing_adjustment($alegra_item, $ref, $delta);
        if ($existing === false) {
            // No se pudo verificar (red) ⇒ NO emitir (fail-safe): `pending` queda y
            // el próximo poll reintenta. Nunca arriesgar un doble descuento.
            return ['pushed' => false, 'delta' => $delta, 'reason' => 'unverified'];
        }
        if ($existing !== null) {
            update_post_meta($id, '_alegra_stock_synced', $new_qty);
            delete_post_meta($id, '_alegra_stock_push_pending');
            return ['pushed' => false, 'delta' => $delta, 'reason' => 'already_applied'];
        }

        update_post_meta($id, '_alegra_stock_push_pending', $new_qty);
        $payload = $this->build_adjustment_payload($alegra_item, $delta, $product);
        $payload['reference'] = $ref;   // clave de idempotencia (verificada por G3)

        $res = $this->api->create_inventory_adjustment($payload);
        if (\Alegra\Connector\API\Client::write_was_blocked($res)) {
            return ['pushed' => false, 'delta' => $delta, 'reason' => ($res['reason'] ?? 'blocked')];
        }
        if (is_wp_error($res)) {
            $this->logger->error('Inventory adjustment failed', ['product_id' => $id, 'delta' => $delta, 'error' => $res->get_error_message()]);
            return ['pushed' => false, 'delta' => $delta, 'reason' => 'api_error'];   // pending queda ⇒ el poll reintenta
        }
        update_post_meta($id, '_alegra_stock_synced', $new_qty);
        delete_post_meta($id, '_alegra_stock_push_pending');
        return ['pushed' => true, 'delta' => $delta, 'reason' => 'ok'];
    } finally {
        \Alegra\Connector\Sync\Controller::release_lock('alegra_inventory_push_' . $id, $token);
    }
}

/** Payload documentado de `POST /inventory-adjustments` (K-A/C1): `items[]` + `unitCost`. */
private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product): array
{
    $payload = [
        'date'  => current_time('Y-m-d'),
        'items' => [[
            'id'       => $alegra_item,
            'type'     => $delta < 0 ? 'out' : 'in',
            'quantity' => abs($delta),
            'unitCost' => $this->unit_cost($product),   // FIX-10/D12
        ]],
    ];
    $wh = $this->resolve_warehouse_id();
    if ($wh !== '') { $payload['warehouse'] = ['id' => $wh]; }
    return $payload;
}

/**
 * Costo unitario (FIX-10/D12). `unitCost=0` puede ser rechazado (422): si no hay
 * costo, usar el precio regular; si tampoco hay, 1.0. NUNCA 0.
 */
private function unit_cost(\WC_Product $product): float
{
    foreach (['_wc_cog_cost', '_cost'] as $key) {
        $raw = get_post_meta($product->get_id(), $key, true);
        if ($raw !== '' && $raw !== null && is_numeric($raw)) { return (float) $raw; }
    }
    $price = (float) $product->get_price();
    return $price > 0.0 ? $price : 1.0;
}

/** Clave de idempotencia estable para (producto, synced, new_qty). */
private function adjustment_reference(int $id, int $synced, int $new_qty): string
{
    return 'wc-stock-' . $id . '-' . $synced . '-' . $new_qty;
}

/**
 * Pre-búsqueda OBLIGATORIA (FIX-4).
 *
 * @return array|null|false array = ya aplicado; null = no existe (se puede emitir);
 *                          false = NO se pudo verificar (red) ⇒ NO emitir (fail-safe).
 * `Client::get_inventory_adjustments()` existe (`Client.php:761`).
 */
private function find_existing_adjustment(string $alegra_item, string $ref, int $delta): array|null|false
{
    $res = $this->api->get_inventory_adjustments([
        'item_id'   => $alegra_item,
        'reference' => $ref,          // G3 confirma el nombre real del filtro
        'limit'     => 30,
    ]);
    if (is_wp_error($res) || !is_array($res)) { return false; }   // fail-safe: no duplicar
    foreach ($res as $adj) {
        if (!is_array($adj)) { continue; }
        if ((string) ($adj['reference'] ?? '') === $ref) { return $adj; }
    }
    return null;   // no existe ⇒ se puede emitir
}
```

**Baseline del ledger (FIX-1) — secuencia exacta.** `_alegra_stock_synced` se fija **sólo** por:

1. **Push OK** (`push_delta`, rama `ok`): `synced = new_qty` tras el POST.
2. **Pull del poll** (§3.5, rama `apply`): `synced = availableQuantity` tras escribir Alegra→WC.
3. **Detección de idempotencia** (`already_applied`): `synced = new_qty` porque el ajuste ya estaba en Alegra.

En la **primera** vez (sin baseline), el hook **no** escribe `synced`: setea
`_alegra_stock_push_pending = $new_qty` y devuelve `baseline_pending`. El **reconciliador del poll**
(§3.5) hace `GET /items/{id}`, fija `synced = availableQuantity` (valor REAL de Alegra) y **recién
entonces** empuja el delta `WC - synced`. Traza (WC=10, Alegra=10, `synced=''`, venta de 3):

| Paso | WC | Alegra | `synced` | `pending` | Acción |
|---|---|---|---|---|---|
| Estado inicial post-upgrade | 10 | 10 | `''` | `''` | — |
| Venta de 3 | 7 | 10 | `''` | 7 | hook → `push_delta(7)` → `baseline_pending` (sin POST, **sin** tocar `synced`) |
| Corre el poll | 7 | 10 | `''` | 7 | reconciliador: `GET /items/{id}` ⇒ baseline=10; `synced=10`; `push_delta(7, from_poll)` ⇒ `delta=-3` ⇒ POST out 3 |
| Push OK | 7 | **7** | **7** | `''` | `synced=7`, `pending` limpio |
| Resultado | **7** | **7** | 7 | `''` | **la venta se conserva; no hay re-inflación** |

Si `GET /items/{id}` no devuelve `availableQuantity` usable (servicio/null/red), el baseline **no** se
establece: el poll **no** escribe WC (evita pisar la venta) y reporta divergencia. `pending` queda para
el próximo reintento.

**Idempotencia (FIX-4):** la pre-búsqueda de `find_existing_adjustment()` es **obligatoria**, no
opcional. Si el POST entró y la respuesta se perdió, el reintento encuentra el ajuste con la misma
`reference` y **no re-emite** (`already_applied`). Si la propia pre-búsqueda falla por red, el pusher
**no** emite (fail-safe: deja `pending` y reintenta). El endpoint de consulta existe
(`Client::get_inventory_adjustments()` `:761`); el nombre exacto del filtro lo confirma G3 (si no
soporta `reference`, se filtra por `item_id` + fecha y se compara `type`/`quantity` localmente).

### 3.5 Interacción con el poll (no re-inflar) — el reconciliador

El poll, por ítem, decide con el ledger **antes** de escribir WC (ver §1.4). Regla canónica
(FIX-1/FIX-8): **el poll escribe WC cuando es el dueño efectivo; el `continue` sólo vale cuando hay un
delta local sin empujar que NO se pudo empujar.**

```php
$a = $item['inventory']['availableQuantity'] ?? null;   // null ⇒ el writer lo maneja
$w = (int) $product->get_stock_quantity();
$s = get_post_meta($id, '_alegra_stock_synced', true);
$p = get_post_meta($id, '_alegra_stock_push_pending', true);

$pusher   = new Inventory_Pusher($this->api, $this->logger);
$owner    = Inventory_Pusher::owner();
$push_on  = (bool) get_option('alegra_connector_push_inventory_enabled', true);
$linked   = (string) get_post_meta($id, '_alegra_item_id', true) !== '';
$can_push = ($owner === 'adjustment') && $push_on && $linked && $product->get_manage_stock();

$has_local_delta = ($p !== '') || ($s !== '' && $w !== (int) $s);

// `$divergence` es el contador run-scoped declarado junto a `$result` en §7.2; NO es una
// clave de `$result`. Alimenta el informe de divergencia (REQ-INV-07, §15).

if ($can_push && $has_local_delta) {
    // (1) FIX-1: sin baseline, establecerlo contra ALEGRA (NUNCA contra WC).
    if ($s === '') {
        $full     = $this->api->get_item((string) get_post_meta($id, '_alegra_item_id', true));
        $baseline = is_wp_error($full) ? null : ($full['inventory']['availableQuantity'] ?? null);
        if ($baseline === null || $baseline === '' || !is_numeric($baseline)) {
            // No se pudo fijar el baseline ⇒ NO pisar WC (preserva la venta) + divergencia.
            $divergence++;
            continue;   // saltea el writer de este ítem; `pending` queda para el próximo reintento
        }
        update_post_meta($id, '_alegra_stock_synced', (int) $baseline);
        $s = (string) (int) $baseline;
    }
    // (2) FIX-2: from_poll=true ⇒ bypassa is_syncing(); el guard por producto se mantiene.
    $r = $pusher->push_delta($product, $w, true);
    if (in_array($r['reason'], ['ok', 'already_applied'], true)) {
        // WC y Alegra acordaron: cae al writer (escribe $a; si el push fue OK, $a == $w).
    } elseif ($r['reason'] === 'locked') {
        $divergence++;   // contención: reintentar la próxima corrida, NO pisar WC
        continue;
    } else {
        // api_error / blocked / not_manageable: el movimiento local sigue sin empujar;
        // NO pisar WC; `pending` queda para el próximo reintento.
        $divergence++;
        continue;
    }
}

// (3) FIX-8: el poll es el dueño efectivo ⇒ SIEMPRE escribe WC (Alegra manda).
//     Excepción: dueño invoice con la factura todavía no abierta ⇒ divergencia, no pisar.
if ($owner === 'invoice' && $a !== null && (int) $a !== $w) {
    $divergence++;   // escribir WC re-inflaría la venta (la factura aún no movió stock)
    continue;
}
$status = (new Inventory_Writer($this->logger))->apply($product, $item, $opts);
if (in_array($status, ['updated', 'clamped_negative'], true)) {
    update_post_meta($id, '_alegra_stock_synced', (int) $product->get_stock_quantity());
    $result['updated']++;
}
```

**Por qué ya no hay `continue` ciego (FIX-8, cierra Oracle#3/starvation):** el `continue` original
saltaba el writer cuando `push_delta` devolvía `disabled`/`invoice_owner`/`not_linked`/`locked`, y el
producto quedaba congelado para siempre. Acá:

- `push_on=false` ⇒ `can_push=false` ⇒ **cae al writer** (Alegra manda). Apagar el push ya no deja a
  nadie escribiendo.
- `owner=invoice` ⇒ el poll no pisa WC hasta que la factura esté `open` (reporta divergencia).
- `not_linked` ⇒ el ítem del poll ya resolvió el producto por id de Alegra, así que no debería ocurrir;
  si ocurre, `can_push=false` ⇒ cae al writer.
- `locked` ⇒ contención; se reintenta la próxima corrida sin pisar WC.
- `disabled`/`invoice_owner`/`not_linked` **nunca** hacen `continue`.

**Primera vez (instalación existente):** si `_alegra_stock_synced` está vacío y **no** hay delta local
(`$w === $a`), el poll escribe el valor de Alegra y setea `synced` (rama (3)). Si **sí** hay delta local
(venta antes del primer push), entra por (1): baseline contra Alegra + push del delta. La ventana de
re-inflación **desaparece** (antes se limitaba a ventas previas al upgrade; ahora también cubre las
posteriores). Se documenta (DR2/DR17).

### 3.6 D9 — Baseline, idempotencia y no-starvation (decisiones canónicas post-review)

Consolida **FIX-1/FIX-2/FIX-4/FIX-8**. Cada fila es una decisión cerrada (no hay bifurcación).

| # | Decisión canónica | Alternativa rechazada | Riesgo / mitigación |
|---|---|---|---|
| D9.1 | `_alegra_stock_synced` se fija **sólo** por push OK, pull del poll, o detección de idempotencia. En la primera vez, el hook setea `pending` y devuelve `baseline_pending`; el poll hace `GET /items/{id}` y fija el baseline **contra Alegra**. | Baselinar con `$new_qty` de WC (el diseño previo, `design.md:546-550`): el poll ve `WC==synced` y escribe el valor viejo de Alegra ⇒ **re-inflación** (B1). | El baseline puede no obtenerse (red/servicio): el poll **no** pisa WC, deja `pending` y reporta divergencia. DR17. |
| D9.2 | El guard `is_syncing()` vive en el **hook** (`on_stock_changed`); `push_delta(..., bool $from_poll=false)` lo **bypassa** cuando `$from_poll=true`. El guard por producto `alegra_updating_product_{id}` se mantiene en `push_delta` (excepto `$from_poll`). | Dejar `is_syncing()` dentro de `push_delta` (el diseño previo): con `set_syncing(true)` en el poll (T7.3), **todo** `push_delta` del poll devolvía `syncing` y la reconciliación/reintento nunca corría (B2/Oracle#2). | Un call site directo que olvide `from_poll` no reintenta desde el poll; los call sites son sólo el hook (`false`) y el poll (`true`). DR18. |
| D9.3 | La pre-búsqueda `GET /inventory-adjustments` es **obligatoria** antes de emitir; se agrega `reference` (clave estable) al payload. Si el ajuste ya existe ⇒ `already_applied` (no re-emite). Si la pre-búsqueda falla por red ⇒ **no** emite (fail-safe). | Pre-búsqueda "opcional según G3" (el diseño previo): con la respuesta perdida, el reintento re-emitía el mismo delta ⇒ doble descuento (DR3/D4). | Un `reference` no soportado por la cuenta ⇒ G3 confirma el filtro; fallback a filtrar por `item_id`+fecha y comparar `type`/`quantity`. DR20. |
| D9.4 | El poll **cae al writer** cuando el push no es posible (`disabled`/`invoice_owner`/`not_linked`); el `continue` sólo se usa con un delta local sin empujar que no se pudo empujar. | `continue` ante cualquier `push_delta` no-OK (el diseño previo): el producto quedaba congelado y Alegra desactualizado (D3/starvation). | Con `owner=invoice` y factura no abierta, el poll **no** pisa WC (reporta divergencia): puede quedar desactualizado hasta abrir la factura, pero **nunca** re-infla. DR22. |

### 3.7 D10 — Dueño del movimiento y locks (decisiones canónicas post-review)

Consolida **FIX-3/FIX-5/FIX-9/FIX-10**.

| # | Decisión canónica | Alternativa rechazada | Riesgo / mitigación |
|---|---|---|---|
| D10.1 | `owner='invoice'` **sólo si** `push_orders_enabled && open_invoice_on_paid`; en cualquier otro caso `adjustment`. | `owner = push_orders_enabled ? 'invoice' : 'adjustment'` (el diseño previo): con `open_invoice_on_paid=false` o un borrador pre-existente, **ningún** mecanismo movía stock (B3/Oracle#1). | El dueño se computa en runtime; un cambio de opción cambia el dueño en el próximo movimiento. DR19. |
| D10.2 | En `owner=invoice`, `create_invoice()` **abre** el borrador existente cuando el pedido pasa a pagado (`ensure_invoice_open($alegra_id, true)` **antes** del early-return `Orders.php:82-90`); `create_invoice_with_payment()` pasa `$allow_open_draft=true`. | Confiar en el override `status='open'` de creación (T3.5): no cubre el flujo real (factura `draft` creada antes del pago; `create_invoice()` retorna temprano y nunca la abre). | `ensure_invoice_open()` es best-effort (Alegra no documenta draft→open limpio, `Orders.php:678-761`): si no abre, se reporta divergencia; el dueño factura se mantiene documentado. DR19. |
| D10.3 | En `owner=adjustment`, la factura manual (Admin_Dashboard `:2693-2730`, `:2778-2781`) se guarda: el pusher no emite ajuste si el pedido ya tiene factura `open`; la acción manual advierte y exige confirmación si ya se emitió un ajuste. | Afirmar "doble conteo imposible por construcción" (el diseño previo): la factura manual no está gated por `push_orders_enabled` (B3). | Si el hook de pedido de WC no corre antes del hook de producto (G9), la guarda 1 se degrada a la guarda 2 (advertencia manual). DR19. |
| D10.4 | `register_shutdown_function` libera **ambos** locks: `alegra_sync_running_products` (poll, TTL 300) y `alegra_cron_global` (cron, TTL 600, `Controller.php:141`). | Cubrir sólo el lock del poll (T8.1): un fatal dejaba el lock global tomado 600 s y **todo** el cron se salteaba (Oracle#6). | Best-effort: no cubre SIGKILL/OOM-killer ni `memory_limit` agotado; el TTL es el backstop real. DR21. |
| D10.5 | `alegra_connector_inventory_pull_total` lo escribe el poll con el total real (`GET /items?metadata=true` ⇒ `metadata.total`) al inicio de cada corrida; el reset del cursor exige `total > 0 && cursor >= total`. | Resetear con `cursor >= total` y `total` default 0 (el diseño previo): `cursor >= 0` siempre ⇒ el cursor se reseteaba en cada corrida (C6). | Si `metadata` no está disponible, no se resetea por catálogo encogido (sólo por completitud/`from_zero`). DR23. |
| D10.6 | `unitCost` cae a `price` (y a `1.0` si no hay precio) cuando no hay `_wc_cog_cost`/`_cost`. G3 prueba explícitamente `unitCost: 0`. | Default `0.0` (el diseño previo): si la cuenta rechaza `0`, **todo** push sin costo falla 422/400 (D12). | G3 confirma el comportamiento; el fallback nunca envía 0. DR25. |
| D10.7 | El self-heal del CF re-resuelve con `resolve_readonly()` (barrido paginado); **no** crea en automático (sólo bajo `run_explicit`). | `get_or_create_id()` (usa `resolve()` con `limit=5`, `Consumidor_Final.php:181-184`): no ve el CF enterrado detrás de >5 falsos positivos CONTAINS (D9). | Si el barrido no lo encuentra y no hay contexto explícito, se borra el meta y se deja nota accionable (no loop). DR26. |

---

## 4. D3 — FRONT B: el escritor único (`Inventory_Writer`)

### 4.1 Clase y contrato

**Archivo:** `includes/Sync/Inventory_Writer.php` — namespace `Alegra\Connector\Sync`.

```php
final class Inventory_Writer
{
    public function __construct(private ?\Alegra\Connector\Logger\Logger $logger = null) {}

    /**
     * Aplica el inventario de un item de Alegra a la entidad WC que maneja stock.
     *
     * @param array $opts {
     *   source: 'alegra'|'woocommerce',
     *   preserve: bool,                    // 'inventory' en preserve_fields
     *   manage_stock: 'enable'|'respect',  // 'enable' = puede poner manage_stock=true
     *   dry_run: bool,
     *   warehouse_id: string,              // '' si no aplica
     * }
     * @return string updated|skipped_no_qty|skipped_service|skipped_not_manageable
     *                |skipped_source|skipped_preserve|skipped_parent|clamped_negative|dry_run|error
     */
    public function apply(\WC_Product $product, array $item, array $opts = []): string;

    /** @return array{0:int|null,1:string} [qty, reason] */
    private function resolve_quantity(array $item, string $warehouse_id): array;

    /** ÚNICO punto de escritura de stock. Usa wc_update_product_stock(). */
    private function apply_stock(\WC_Product $p, int $qty): void;
}
```

### 4.2 Las compuertas únicas

| Compuerta | Dónde | Comportamiento |
|---|---|---|
| Fuente | `$opts['source'] === 'woocommerce'` | `skipped_source` (no escribe) |
| Preservar | `$opts['preserve'] === true` | `skipped_preserve` |
| Variable padre | `$product->is_type('variable')` | `set_manage_stock(false)` + `skipped_parent` |
| Servicio | `inventory` ausente/no-array | `set_manage_stock(false)` + `skipped_service` |
| Nulo | `availableQuantity` null/''/no-numérico | `skipped_no_qty` (**nunca 0**) |
| Negativo | `qty < 0` | clamp 0 + WARN + `clamped_negative` |
| `manage_stock` | `!$product->get_manage_stock()` y `$opts['manage_stock']==='respect'` | `skipped_not_manageable` |
| Dry-run | `$opts['dry_run']` | reporta sin escribir |

**`apply_stock()` (el único escritor):**

```php
private function apply_stock(\WC_Product $p, int $qty): void
{
    $p->set_manage_stock(true);
    if (function_exists('wc_update_product_stock')) {
        // API recomendada: actualiza el data store, dispara hooks y save(),
        // que recalcula el _stock_status respetando backorders + umbral (D5).
        wc_update_product_stock($p, $qty, 'set', false);
    } else {
        // Fallback defensivo para WC muy viejo: setter + save + status derivado.
        $p->set_stock_quantity($qty);
        $p->save();
    }
}
```

> `wc_update_product_stock()` **sólo actualiza si `$product->managing_stock()`** (doc WC). Por eso
> `set_manage_stock(true)` va **antes**. Con `$updating=false` (default) llama `save()` y dispara
> `woocommerce_product_set_stock`/`woocommerce_variation_set_stock` (doc WC). El guard
> `function_exists` cubre WC pre-3.0 (DR12).

### 4.3 Convergencia de W1 y W2

- **W1** (`apply_inventory_to_product()`, `Products.php:2052-2100`) deja de escribir: construye `$opts`
  y delega en `Inventory_Writer::apply()`. `update_product_from_alegra()` (`:2006-2008`) ya chequea
  fuente + preserve; esos chequeos **se mueven al writer** (una sola fuente) y el call site pasa
  `$opts['source']`/`$opts['preserve']`.
- **W2** (bloque inline del poll, `Products.php:1253-1260`) se reemplaza por
  `Inventory_Writer::apply($product, $item, $opts)` con el chequeo del ledger (§3.5).
- **`manage_stock`:** W1 pasa `'enable'` (comportamiento actual del import: habilita manage_stock);
  W2 pasa `$opt_in ? 'enable' : 'respect'`, con
  `$opt_in = get_option('alegra_connector_inventory_manage_stock_enabled', false)`.

### 4.4 Migración de productos legacy con `manage_stock=no`

- **Opt-in** `alegra_connector_inventory_manage_stock_enabled` (bool, default **false**, autoload no).
  Con `false`, el poll respeta el estado actual (`skipped_not_manageable`, igual que HEAD).
- Con `true`, el poll **habilita** `manage_stock` para items inventariables de Alegra.
- **UI:** toggle en `templates/admin-settings.php` (pestaña Sincronización), con la advertencia de que
  activa la gestión de stock en WC para los productos que Alegra marca inventariables.
- **Sin migración de datos:** no se recorre el catálogo; el cambio es opt-in y por-producto al pasar
  el poll. Se loguea el conteo de `skipped_not_manageable` para que el comerciante dimensione.

---

## 5. D4 — FRONT B: variaciones en el camino de UPDATE

### 5.1 El gap

`import_single_item_from_alegra()` (`Products.php:1488`): si el `variantParent` **ya existe**, llama
`update_product_from_alegra(parent)` y retorna `'updated'` (`:1548-1557`) **sin iterar los hijos**. La
rama de **creación** sí itera (`:1613-1618`). Además, un item `type=variant` se **saltea** en
`:1515-1517` y en el chunked `Admin_Dashboard.php:2316`.

### 5.2 Decisión

**Refrescar los hijos en el update y resolver el `type=variant` suelto; el fetch explícito de hijos
sólo cuando el payload no los trae.**

```php
// dentro de import_single_item_from_alegra(), en la rama existing (tras :1554-1556)
if ($existing_id) {
    $product = wc_get_product($existing_id);
    if ($product) {
        Entity_Map::map('item', $alegra_id, 'product', (int) $existing_id);
        $this->update_product_from_alegra($product, $item);
        $this->assign_product_category((int) $existing_id, $item);

        // D4: refrescar variaciones TAMBIÉN en update.
        if ($product->is_type('variable') || ($type === 'variantParent' || $type === 'kit')) {
            $this->refresh_variant_children((int) $existing_id, $item);
        }
        return 'updated';
    }
}
```

```php
private function refresh_variant_children(int $parent_id, array $item): void
{
    $children = $this->get_variant_children($item);
    if (empty($children)) {
        // SIN VERIFICAR en producción: si el payload no trae itemVariants, traerlos.
        $alegra_parent = (string) ($item['id'] ?? '');
        if ($alegra_parent !== '') {
            $fetched = $this->api->get_items(['variantParent_id' => $alegra_parent, 'limit' => 100]);
            if (!is_wp_error($fetched) && is_array($fetched)) { $children = $fetched; }
            else { $this->logger->warning('No se pudieron traer las variaciones del padre', ['parent' => $alegra_parent]); }
        }
    }
    foreach ($children as $child) {
        if (is_array($child)) { $this->import_variation_from_alegra($parent_id, $child); }
    }
}
```

**`type=variant` suelto** (reemplaza el `return 'skipped'` de `:1515-1517`):

```php
if ($type === 'variant') {
    $parent_id = (string) ($item['variantParent']['id'] ?? '');
    if ($parent_id === '') {
        $this->logger->warning('Item variant sin variantParent; no se puede aplicar', ['alegra_id' => $alegra_id]);
        return 'skipped';
    }
    $parent_wc = $this->get_product_by_alegra_id($parent_id);
    if (!$parent_wc) {
        $this->logger->warning('Item variant con padre no importado; se ignora con señal', ['alegra_id' => $alegra_id, 'parent' => $parent_id]);
        return 'skipped';
    }
    $this->import_variation_from_alegra((int) $parent_wc, $item);   // update ⇒ W1
    return 'updated';
}
```

**Chunked** (`Admin_Dashboard.php:2316`): reemplazar el `continue` mudo por
`$products_sync->import_single_item_public($item, $run_id)` (el handler ya resuelve `type=variant`).

**Por qué el fetch explícito:** `import_variation_from_alegra()` ya hace `GET /items/{child}` (`:1773`)
y, si existe, `update_product_from_alegra` → W1 → `Inventory_Writer`. El mock ya soporta
`variantParent_id` (`alegra-mock.php:880`). El fetch explícito cubre payloads de webhook sin hijos.
**BLOQUEADO(ON-VERIFICATION):** si `edit-item` dispara con cambio sólo de stock (Fase 0 G2); el
requerimiento (refrescar en update) aplica igual en ambos casos.

---

## 6. D5 — FRONT B: `_stock_status`, backorders y `wc_update_product_stock`

### 6.1 Decisión

**Adoptar `wc_update_product_stock($product, $qty, 'set', false)` dentro de `Inventory_Writer::apply_stock()`**
**y eliminar el forzado `$qty > 0 ? 'instock' : 'outofstock'` (`Products.php:1259` y `:2099`).**

Justificación contra la doc de WooCommerce:
- `wc_update_product_stock()` es la **API recomendada**; actualiza vía el data store, dispara
  `woocommerce_product_before_set_stock` / `woocommerce_product_set_stock` (y `…variation…`), y cuando
  `$updating=false` llama `save()`, que **recalcula el `_stock_status`** respetando `_backorders` y el
  umbral de no-stock. Esto cubre REQ-INV-04 y su escenario de hooks.
- El camino `set_stock_quantity()+save()` **no** dispara los hooks específicos de stock y obligaba al
  plugin a forzar el estado (prueba de que no derivaba), ignorando backorders.

**Alternativa rechazada (setter + backorders manual):** replicar la derivación en el plugin duplica
lógica del core, se desincroniza con WC y no dispara los hooks que las integraciones escuchan.

**Cambio intencional:** con `backorders=yes` y stock 0 el estado pasa a `onbackorder` (antes
`outofstock`). Nota en `CHANGELOG.md`.

**Umbral de no-stock ≠ 0 (FIX-10/D10, cierra Oracle D10).** El claim "idéntico a HEAD con
`backorders=no`" es **falso** cuando el comerciante configuró
`woocommerce_notify_no_stock_amount > 0`. WC deriva el estado como
`(int)qty > absint(notify_no_stock_amount) ? 'instock' : ('onbackorder'|'outofstock')`
(`abstract-wc-product.php:1419-1442`). Con el umbral default (0) el resultado coincide con HEAD; con
umbral 2, un producto `qty=1`, `backorders=no` pasa a `outofstock` donde HEAD forzaba `instock`.

- **Decisión:** se **respeta** la semántica de WC (es el punto del cambio: dejar de forzar el estado).
  **No** se agrega código para replicar HEAD; el "guard" es explícito:
  1. **Test** con `woocommerce_notify_no_stock_amount=2` que fija la semántica (umbral ⇒ `outofstock`).
  2. **Nota de release** en `CHANGELOG.md` (cambio intencional) y en la UI de Ajustes cuando el umbral
     es `> 0`, aclarando que el estado lo deriva WC según el umbral configurado.
  3. El comerciante que quiera la semántica de HEAD pone `woocommerce_notify_no_stock_amount = 0`.
- **Riesgo:** un comerciante con umbral `> 0` ve productos pasar a "agotado" con stock positivo. Se
  documenta y se avisa; no es silencioso. DR24.

### 6.2 Backorders

No se escribe `_backorders` (Alegra no lo expone): se **respeta** el valor del producto. La derivación
la hace WC. El docblock `Products.php:2031-2034` (claim refutado) se corrige.

### 6.3 Interacción con el pusher

El pusher **no** escribe stock WC; sólo lee `get_stock_quantity()`. La escritura de WC pasa siempre
por `Inventory_Writer`. `wc_update_product_stock` dispara `woocommerce_product_set_stock`, que el
pusher escucha. **El guard de re-entrada (`is_syncing()` + transient `alegra_updating_product_`) vive
en el hook `on_stock_changed` (FIX-2), no en `push_delta`**: así el reconciliador del poll puede llamar
a `push_delta(..., from_poll: true)` sin auto-bloquearse (§3.4/§3.5).

---

## 7. D6 — FRONT C: presupuesto del poll + cursor

### 7.1 Opciones

| Opción | Tipo | Default | Autoload | Descripción |
|---|---|---|---|---|
| `alegra_connector_inventory_poll_budget` | int 10..300 | **60** | no | Wall-clock por corrida del poll. |
| `alegra_connector_inventory_poll_max_pages` | int | **0** | no | 0 = sin tope. Reemplaza el 200 fijo. |
| `alegra_connector_inventory_pull_cursor` | int | 0 | no | Cursor (`start`) persistido. Nombre alineado con `docs/sdd/inventory/`. |
| `alegra_connector_inventory_pull_total` | int | 0 | no | Total para la UI (informativo). |

### 7.2 Patrón (copiado del import, `Products.php:1305-1322`)

```php
public function sync_inventory_from_alegra(int $run_id = 0, float $deadline = 0.0): array
{
    // Conjunto CANÓNICO de claves de $result (9, autoridad: fase-2 T2.4 / tasks.md §11-I11):
    // updated, errors, pages, locked, skipped, skipped_not_manageable, truncated, completed, cursor.
    // `divergence` NO es clave de $result: vive en el informe de divergencia (REQ-INV-07, §15).
    $result = ['updated'=>0,'errors'=>0,'pages'=>0,'locked'=>false,'skipped'=>false,
               'skipped_not_manageable'=>0,'truncated'=>false,'completed'=>false,'cursor'=>0];
    $divergence = 0;   // REQ-INV-07: contador run-scoped del informe de divergencia (NO es clave de $result).
    // … kill switch, inventory_source, lock (sin cambios) …
    try {
        $cursor_key = 'alegra_connector_inventory_pull_cursor';
        $cursor = max(0, (int) get_option($cursor_key, 0));
        $budget = max(10, (int) get_option('alegra_connector_inventory_poll_budget', 60));
        $own_deadline = microtime(true) + $budget;
        if ($deadline > 0.0) { $own_deadline = min($own_deadline, $deadline); }
        $max_pages = max(0, (int) get_option('alegra_connector_inventory_poll_max_pages', 0));

        // FIX-9: el total REAL del catálogo. Sin esto `pull_total` queda en 0 y el
        // reset por catálogo encogido dispara SIEMPRE (`cursor >= 0` es siempre true).
        $total_key = 'alegra_connector_inventory_pull_total';
        $meta  = $this->api->get_items(['metadata' => 'true', 'limit' => 1]);
        $total = (is_wp_error($meta) || !is_array($meta))
            ? 0
            : (int) ($meta['metadata']['total'] ?? 0);
        if ($total > 0) {
            update_option($total_key, $total, false);   // quién lo escribe: el poll (§7.3)
            if ($cursor >= $total) {                    // catálogo encogido ⇒ reanudar de cero
                $cursor = 0;
                delete_option($cursor_key);
            }
        }

        $p = 0; $completed = false;
        while (true) {
            if (microtime(true) >= $own_deadline) { $result['truncated'] = true; break; }
            if ($max_pages > 0 && $p >= $max_pages) { $result['truncated'] = true; break; }
            // … cancel / should_stop …
            $items = $this->api->get_items(['start'=>$cursor,'limit'=>30,'mode'=>'advanced']);
            if (is_wp_error($items)) { $result['errors']++; break; }
            if (empty($items)) { $completed = true; break; }

            foreach ($items as $item) { /* ledger + Inventory_Writer */ }

            $cursor += 30; $p++;
            update_option($cursor_key, $cursor, false);      // persistir por página
            if (count($items) < 30) { $completed = true; break; }
        }

        if ($completed) { delete_option($cursor_key); delete_option('alegra_connector_inventory_pull_total'); }
        $result['completed'] = $completed;
        $result['cursor'] = $completed ? 0 : $cursor;
        if ($result['truncated']) {
            $this->logger->warning('Inventory poll truncated; resuming next run', ['cursor' => $cursor]);
        }
        return $result;
    } finally { /* release lock */ }
}
```

### 7.3 Reset semantics del cursor (FIX-9)

**Quién escribe `alegra_connector_inventory_pull_total`:** el **poll**, al inicio de cada corrida, con el
`metadata.total` real de `GET /items?metadata=true&limit=1`. Es la **autoridad** del total (no el
`max(cursor)`). Si esa llamada falla, `total` queda en 0 y **no** se resetea por catálogo encogido.

**Cuándo se resetea el cursor a 0 (los 3 casos, exactos):**

1. **Completitud:** la corrida completa el catálogo (`count($items) < 30` o `empty($items)`) ⇒ se borran
   cursor y total.
2. **`from_zero`:** el AJAX "Sincronizar inventario" con `from_zero=true` borra cursor y total **antes**
   de llamar al poll (T7.4). El poll no necesita un parámetro nuevo.
3. **Catálogo encogido (defensivo):** `total > 0 && cursor >= total` ⇒ el cursor apunta más allá del
   catálogo ⇒ `cursor = 0` y se borra la opción. **El guard `total > 0` es obligatorio**: con el total
   default 0, `cursor >= 0` es siempre verdadero y el cursor se resetearía en cada corrida (C6).

No se resetea por TTL: un cursor viejo es válido y reanudable. Al truncar por budget/tope, el cursor
**se persiste** y la corrida siguiente reanuda desde ahí.

### 7.4 `truncated` + log

`truncated=true` cuando se corta por budget o por `max_pages` **sin** haber completado; el log indica
el cursor y los ítems procesados. El resultado del poll (`$result`) tiene el **conjunto canónico de 9
claves** (fase-2 T2.4 / tasks.md §11-I11): `updated`, `errors`, `pages`, `locked`, `skipped`,
`skipped_not_manageable`, `truncated`, `completed`, `cursor`. `truncated`/`completed`/`cursor` los fija
esta fase (REQ-POLL-02). **`divergence` NO es clave de `$result`**: el poll lleva un contador separado
(`$divergence`) que cuenta los ítems donde **no** pudo escribir WC (delta local sin empujar, baseline no
obtenible, `locked`, o dueño factura con factura no abierta) y alimenta el informe de divergencia
(REQ-INV-07, §15). `set_time_limit(300)` (`:1172`) se **elimina** del poll (el budget propio es la
defensa).

---

## 8. D7 — FRONT C: lock, `set_syncing` y cron real

### 8.1 Liberación de los locks en shutdown (FIX-5)

**Decisión: `register_shutdown_function` (primario) + TTL (backstop) para AMBOS locks.** El defecto
(Oracle#6): T8.1 sólo cubría `alegra_sync_running_products` (poll, TTL 300), pero el lock **global del
cron** `alegra_cron_global` (TTL 600, `Controller.php:141`) se liberaba **sólo** en el `finally` de
`run_cron_sync()` (`:156-158`). Un fatal dentro del poll saltea ese `finally` ⇒ el lock global queda
tomado 600 s y **todo** el cron se saltea. El handler debe cubrir **ambos** (y cualquier lock de TTL
largo).

**Lock del poll** (en `Products::sync_inventory_from_alegra()`, tras `acquire_sync_lock_public`):

```php
$lock = Controller::acquire_sync_lock_public('products');
if ($lock === false) { /* … */ }
$lock_key = 'alegra_sync_running_products';
register_shutdown_function(static function () use ($lock_key, $lock): void {
    \Alegra\Connector\Sync\Controller::release_lock($lock_key, $lock);
});
```

**Lock global del cron** (en `Controller::run_cron_sync()`, tras `acquire_lock('alegra_cron_global', 600)`):

```php
$global_lock = self::acquire_lock('alegra_cron_global', 600);
if ($global_lock === false) { /* … */ }
register_shutdown_function(static function () use ($global_lock): void {
    \Alegra\Connector\Sync\Controller::release_lock('alegra_cron_global', $global_lock);
});
```

`release_lock()` valida el token (`Controller.php:441-448`), así que el handler nunca libera un lock
ajeno (DR10). En una corrida normal el `finally` libera primero y el shutdown no encuentra token ⇒ no-op.

**Cobertura real (best-effort) y limitación declarada (Oracle D16):** los shutdown functions **sí**
corren en `E_ERROR`, excepciones no capturadas y el fatal de `max_execution_time`; **no** corren ante
`SIGKILL`/OOM-killer, y con `memory_limit` agotado pueden no poder asignar memoria para `get_option`/
`delete_option`. Por eso **el TTL sigue siendo el backstop real**; no se afirma cobertura total. DR21.

**Alternativa rechazada (sólo bajar el TTL):** un TTL corto libera un poll legítimamente largo y permite
solapamiento; el shutdown handler ataca la causa (fatal) sin ese riesgo.

### 8.2 `set_syncing` alrededor del poll

```php
$was_syncing = \Alegra\Connector\Public\Public_::is_syncing();
if (!$was_syncing) { \Alegra\Connector\Public\Public_::set_syncing(true); }
try {
    // loop del poll (cada N páginas: refrescar el transient si sigue corriendo)
} finally {
    if (!$was_syncing) { \Alegra\Connector\Public\Public_::set_syncing(false); }
}
```

- **Por qué funciona (mecanismo real, corregido):** `set_syncing(true)` escribe el transient
  `alegra_import_in_progress` (`Public_.php:433`) y `trigger_sync()` lo consulta (`:371`). Como
  `on_update_product` (`:226-234`) deriva en `trigger_sync()`, la cascada se corta.
- **No bloquea al reconciliador (FIX-2):** el `is_syncing()` que setea el poll vive en el **hook**
  `on_stock_changed`; el reconciliador llama `push_delta(..., from_poll: true)`, que lo bypassa. Si el
  guard siguiera dentro de `push_delta`, todo push del poll devolvería `syncing` y no reconciliaría
  (B2/Oracle#2).
- **TTL 300 (DR8):** si el poll corre >300 s, el transient expira. El poll refresca
  `set_transient('alegra_import_in_progress', 1, 300)` en cada iteración de página.
- **Anidado (REQ-POLL-04 borde):** el patrón `$was_syncing` evita apagar el flag del contexto externo.

### 8.3 Lock global del cron vs corrida larga

**Problema:** `alegra_cron_global` TTL 600 (`Controller.php:141`) y la corrida (import 240 + poll + el
resto) puede excederlo ⇒ solapamiento.

**Decisión: presupuesto global de corrida + deadline propagado.**

- Nueva opción `alegra_connector_cron_run_budget` (int, default **540**, autoload no).
- `run_cron_sync()` calcula `$deadline = microtime(true) + $budget` y lo pasa a cada etapa:
  `import_from_alegra(..., $deadline)` y `sync_inventory_from_alegra($run_id, $deadline)`.
- Cada etapa corta en `min(su budget, $deadline)` y persiste su cursor.
- El TTL del lock global se mantiene en **600** (> 540): una corrida que respeta el deadline termina
  antes de que el lock expire.
- Al reclamar un lock vencido se agrega un `logger->warning('cron lock reclaimed (previous run overran)')`
  (hoy es silencioso).

### 8.4 Recomendación de cron real (UI)

**Dónde:** `templates/admin-settings.php`, pestaña **Avanzado**, nueva tarjeta junto a "Sincronización
Periódica (Cron Polling)" (`:468-474`).

**Copy + snippet (exactos):**

```
Sincronización con cron real (recomendado)

Por defecto, WordPress dispara el cron cuando alguien visita el sitio. En tiendas con poco
tráfico la sincronización puede demorar horas. Un cron real del sistema la ejecuta a horario.

1) Desactiva el cron por visitas: agregá en wp-config.php
      define('DISABLE_WP_CRON', true);

2) Agregá esta línea a tu crontab (crontab -e), reemplazando la ruta si tu hosting la cambia:
      */15 * * * * wget -q -O - https://TU-SITIO/wp-cron.php?doing_wp_cron >/dev/null 2>&1

   Si tu host permite PHP CLI:
      */15 * * * * cd /ruta/a/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

La ruta real se genera con `home_url('/wp-cron.php')` y `esc_html()`. Es **informativa**, nunca un
error (REQ-POLL-05 borde). Se registra `DISABLE_WP_CRON` en la UI; no se escribe `wp-config.php`.

### 8.5 Action Scheduler — DIFERIDO

**Decisión: no se adopta Action Scheduler en este cambio.** Motivo: no está garantizado en hosts
distribuidos (`uninstall.php:221-226` sólo lo menciona en limpieza), el poll ya funciona con
WP-Cron + budget + cursor, y agregar AS introduce una dependencia y una ruta de lotes encadenados que
duplica el presupuesto/cursor. Se deja como mejora futura (REQ-POLL-07 escenario fallback ya se cumple
al no depender de AS).

---

## 9. D8 — El harness

### 9.1 Qué falta en el stub/mock (verificado)

| # | Gap | Evidencia | Requerido por |
|---|---|---|---|
| H1 | `wc_update_product_stock()` ausente | 0 en `scripts/lib/wp-stubs.php` | D5, REQ-INV-04 |
| H2 | `WC_Product::save()` no deriva `_stock_status` de qty+backorders | `wp-stubs.php:1284` (`return $this->id;`) | REQ-INV-04 |
| H3 | Filtro `identification` **exacto**, no CONTAINS | `alegra-mock.php:863` (`$num === $ident`) | REQ-CF-05 |
| H4 | Sin ruta `/inventory-adjustments` | grep = 0 en `alegra-mock.php` | D2 (a), REQ-INV-08 |
| H5 | `POST /invoices` no rechaza un `client.id` inexistente | `alegra-mock.php:535-556` sólo valida presencia | REQ-CF-06 |
| H6 | Sin modelo de stock por ítem que el ajuste modifique | `alegra_mock_state['items']` no aplica adjustments | D2, REQ-INV-01 |
| H7 | `set_syncing`/transient de import no simulados | — | REQ-POLL-04 |
| H8 | Opciones nuevas no sembradas | `alegra-connector.php` | NFR-04 |

El modelo `wp_alegra_runs` **sí existe** (de `logs-monitor-import`, `wp-stubs.php:1680-1803`).

### 9.2 Cambios de harness (NO se distribuyen; `.distignore:15` excluye `scripts/`)

- **`scripts/lib/wp-stubs.php`:**
  - `function wc_update_product_stock($product, $qty = null, $op = 'set', $updating = false)`: setea
    `stock`, **deriva `stock_status`** desde `manage_stock` + qty + `backorders` (modelo de H2), y
    dispara `do_action('woocommerce_product_set_stock', $product)` /
    `…variation…` para testear la cascada y el pusher.
  - `WC_Product::save()` deriva `stock_status` cuando `manage_stock` está on (opcional si H1 lo cubre).
- **`scripts/lib/alegra-mock.php`:**
  - `alegra_mock_filter_contacts`: modo CONTAINS para `identification` (default actual = exacto,
    con un flag `alegra_mock_set_contact_identification_mode('contains')`) para probar REQ-CF-05.
  - Ruta `POST /inventory-adjustments`: valida `{item.id, type, quantity}` y **aplica** el delta al
    `alegra_mock_state['items'][id]['inventory']['availableQuantity']` (modelo de H6).
  - `POST /invoices`: si `client.id` no existe en `contacts` ⇒ `400 {message: "El cliente no existe"}` (H5).
- **`scripts/exec-test.php`:** tests nuevos (ver §14).

---

## 10. APIs nuevas (firmas exactas)

> **Reconciliado con las fases (C3/FIX-7).** Estas son las firmas canónicas; donde el código de HEAD o
> las fases previas difieren (p. ej. `register_hooks()` sin args, `on_stock_changed(int)`,
> `dry_run` fuera del enum), **estas mandan**. Los call sites de WC pasan el **objeto** `WC_Product`.

```php
// includes/Consumidor_Final.php
public static function resolve_readonly(?Client $client = null): string|false;
public static function probe(?Client $client = null): array;
public static function probe_state(): array;            // lee la opción, sin red
private static function scan_candidates(Client $client): array;
private static function cache_id(string $id): void;

// includes/Sync/Inventory_Writer.php (NUEVA)
final class Inventory_Writer {
    public function __construct(?\Alegra\Connector\Logger\Logger $logger = null);
    public function apply(\WC_Product $product, array $item, array $opts = []): string;
    private function resolve_quantity(array $item, string $warehouse_id): array;
    private function apply_stock(\WC_Product $p, int $qty): void;
}

// includes/Sync/Inventory_Pusher.php (NUEVA)
final class Inventory_Pusher {
    public function __construct(?API\Client $api, ?\Alegra\Connector\Logger\Logger $logger);
    public static function register_hooks(?API\Client $api, ?\Alegra\Connector\Logger\Logger $logger): void;   // C3/FIX-7
    public function on_stock_changed($product): void;                 // WC pasa OBJETO WC_Product (C2/FIX-7)
    public function on_variation_stock_changed($variation): void;     // idem
    private function normalize_product($product): ?\WC_Product;       // acepta WC_Product|int
    public function push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array;  // FIX-2
    public static function owner(): string;                           // condición doble (FIX-3)
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product): array;
    private function unit_cost(\WC_Product $product): float;          // fallback price/1 (FIX-10/D12)
    private function adjustment_reference(int $id, int $synced, int $new_qty): string;
    private function find_existing_adjustment(string $alegra_item, string $ref, int $delta): array|null|false;
    private function resolve_warehouse_id(): string;
}

// includes/Sync/Stock_Order_Context.php (NUEVA, si G9 confirma el orden de hooks)
final class Stock_Order_Context {
    public static function set(int $order_id): void;
    public static function current_order_id(): int;
}

// includes/Sync/Products.php
public function sync_inventory_from_alegra(int $run_id = 0, float $deadline = 0.0): array;
public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0, float $run_deadline = 0.0): array|\WP_Error;  // C7/FIX-7
private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void;  // C3/FIX-7
private function refresh_variant_children(int $parent_id, array $item): void;

// includes/Sync/Controller.php
public function run_cron_sync(): void;                    // deadline global + shutdown de AMBOS locks (FIX-5)
public function run_inventory_sync(float $deadline = 0.0): array;   // T7.5/FIX-7

// includes/Sync/Orders.php
private function try_self_heal_dead_client(\WC_Order $order, array $data, \WP_Error $error): ?array;
//   + create_invoice(): si owner()==='invoice' && pagado && open_invoice_on_paid
//       ⇒ ensure_invoice_open($alegra_id, true) ANTES del early-return (FIX-3)
//   + create_invoice_with_payment(): $allow_open_draft=true cuando owner()==='invoice' && pagado (FIX-3)

// includes/API/Client.php  (firma intacta; se quita @deprecated)
public function create_inventory_adjustment(array $data): array|\WP_Error;

// admin/Admin/Admin_Dashboard.php
public function ajax_verify_consumidor_final(): void;
//   + guarda de la factura manual en owner=adjustment (FIX-3, §3.3)

// includes/Write_Gate.php  (entidad nueva)
//   ENTITY_OPTIONS['inventory']  = 'alegra_connector_push_inventory_enabled'
//   ENTITY_DEFAULTS['inventory'] = true    // T1.8: sin esto el gate bloquea el ajuste en installs viejas
//   ENTITY_PATTERNS[]            = ['inventory', '#^/inventory-adjustments(/|$)#']
```

**Payload de `ajax_verify_consumidor_final` (respuesta):**
```php
['state' => 'available'|'not_found'|'unverified', 'id' => ?string, 'message' => string]
```

**Payload del poll (resultado) — conjunto canónico de 9 claves:**
```php
['updated'=>int,'errors'=>int,'pages'=>int,'locked'=>bool,'skipped'=>bool,
 'skipped_not_manageable'=>int,'truncated'=>bool,'completed'=>bool,'cursor'=>int]
```
> `divergence` **no** forma parte de este `$result`: el poll lo lleva en un contador separado
> (`$divergence`) que alimenta el informe de divergencia (REQ-INV-07, §15).

---

## 11. DB / opciones / migración

**Schema: SIN CAMBIOS.** Se usan post meta y options nativas.

**Opciones nuevas:**

| Opción | Tipo | Default | `register_setting` | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|---|---|
| `alegra_connector_push_inventory_enabled` | bool | `true` | sí (sync) | sí | sí | sí |
| `alegra_connector_inventory_manage_stock_enabled` | bool | `false` | sí (sync) | sí | sí | sí |
| `alegra_connector_inventory_poll_budget` | int | `60` | sí (avanzado) | sí | sí | sí |
| `alegra_connector_inventory_poll_max_pages` | int | `0` | sí (avanzado) | sí | sí | sí |
| `alegra_connector_cron_run_budget` | int | `540` | sí (avanzado) | sí | sí | sí |
| `alegra_connector_open_invoice_on_paid` | bool | `true` | sí (sync) | sí | sí | sí |
| `alegra_connector_inventory_pull_cursor` | int | `0` | no (interno) | no | sí | sí |
| `alegra_connector_inventory_pull_total` | int | `0` | no (interno) | no | sí | sí |
| `alegra_connector_consumidor_final_probe` | array | ausente | no (interno) | no | sí | sí |

> **9.ª opción (FIX-6, cierra C1).** `alegra_connector_open_invoice_on_paid` es la **9.ª** opción nueva
> (la tabla previa listaba 8). Nombre completo con prefijo `alegra_connector_`; la fase 3 la abrevia
> como `open_invoice_on_paid`. Se agrega a `alegra-connector.php` `$defaults` (junto a
> `alegra_connector_invoice_status`, `:451`) y `$non_autoload` (`:463-486`), a `uninstall.php` y a
> `register_setting` (pestaña Sincronización, visible sólo con `push_orders_enabled=true`). **T1.7 pasa
> de 8 a 9** y el test de opciones (T29.17) asserta 9.

**Metas por producto:** `_alegra_stock_synced` (int), `_alegra_stock_push_pending` (int),
`_alegra_stock_adjusted` (string, opcional — §3.3).

**Migración para una instalación existente:** no hace falta un `maybe_migrate` para estas opciones
(se leen con default). Se agregan a `$defaults` (siembra en activación) y `$non_autoload`, y a
`uninstall.php`. El **dueño** se computa dinámicamente de `push_orders_enabled && open_invoice_on_paid`
⇒ **sin migración**. El ledger `_alegra_stock_synced` se baselina contra Alegra en el primer contacto
(§3.4/§3.5). Se documenta en `CHANGELOG.md`.

---

## 12. Compatibilidad hacia atrás + plugin distribuido

- **Contrato AJAX (NFR-04):** se mantiene `success`/`data`; se agrega `message` siempre.
- **`push_orders_enabled=true` + `open_invoice_on_paid=true`:** dueño `invoice`; no se emite ajuste. Un
  pedido pagado nace `open` **y** un borrador pre-existente se abre al pagarse (`ensure_invoice_open`,
  FIX-3). **Cambio intencional** (antes el default `draft` no movía stock); nota de release.
- **`push_orders_enabled=true` + `open_invoice_on_paid=false`:** dueño `adjustment` (FIX-3). Antes el
  dueño era `invoice` y **ningún** mecanismo movía stock; ahora el ajuste cubre. Nota de release.
- **`push_orders_enabled=false` (default):** se emite ajuste con delta. El poll no re-infla.
- **`inventory_source=woocommerce`:** el poll no escribe (ya existe) y el ajuste **sí** puede correr
  (dueño adjustment) para que Alegra refleje WC; si `push_inventory_enabled=false`, no.
- **`_stock_status`/backorders:** cambio intencional; con `backorders=no` **y**
  `woocommerce_notify_no_stock_amount=0` idéntico a HEAD. Con umbral `> 0` WC puede derivar `outofstock`
  con stock positivo (FIX-10/D10); se documenta y se testea.
- **`preserve_fields`:** el poll ahora lo honra (bug corregido); nota de release.
- **Grep invariante:** los setters de stock sólo en `Inventory_Writer.php`.
- **Distribuido:** sin `set_time_limit` como defensa, sin cron real obligatorio, sin Action Scheduler,
  sin pool FPM grande. La UI degrada honesta ("No verificado", "truncated").

---

## 13. Registro de riesgos de diseño (DR1–DR26)

> **Namespace.** Riesgos **de diseño** (`DRn`). La matriz de riesgos **de ejecución/regresión** (`R1–Rn`)
> vive en `tasks.md`. No confundir.

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| DR1 | Doble descuento (ajuste + factura) | Media | Alto | Dueño a nivel tienda con **condición doble** (`push_orders_enabled && open_invoice_on_paid`, FIX-3) + guarda de factura `open` en el pusher + guarda manual en `owner=adjustment`. |
| DR2 | El poll re-infla si un push falló | Media | Alto | Ledger `_alegra_stock_push_pending`: el poll reintenta el push y NO escribe WC. Baseline **sólo desde Alegra** (FIX-1): la ventana de re-inflación desaparece (antes cubría las ventas post-upgrade). |
| DR3 | El ajuste se re-emite si el POST entró pero se perdió la respuesta | Media | Medio | Lock por producto + `synced` sólo con OK + **pre-búsqueda obligatoria** `GET /inventory-adjustments` con `reference` (FIX-4). Si la pre-búsqueda falla, no emite. |
| DR4 | La auto-sanación duplica la factura | Media | Alto | `find_existing_invoice()` antes del reintento; reintento único. |
| DR5 | La auto-sanación loop entre requests | Baja | Medio | Si la re-resolución falla, se borra `_billing_alegra_contact_id` y se deja nota. |
| DR6 | Barrido CONTAINS incompleto ⇒ crear duplicado | Baja | Alto | Sólo `not_found` con barrido **completo**; tope ⇒ `unverified` (nunca crear). |
| DR7 | `probe()`/render POSTea por accidente | Baja | Alto | `probe()`/`resolve_readonly()` nunca llaman `create()`; test estático de que el render no dispara POST. |
| DR8 | `set_syncing` TTL 300 < poll largo ⇒ cascada vuelve | Media | Medio | Refrescar `alegra_import_in_progress` por página. |
| DR9 | Anidado apaga el flag del contexto externo | Baja | Bajo | Patrón `$was_syncing`. |
| DR10 | El shutdown handler libera un lock ajeno | Baja | Bajo | `release_lock()` valida el token. |
| DR11 | Solapamiento del lock global del cron | Media | Medio | `cron_run_budget` (540) < TTL lock (600); deadline propagado; log al reclamar. |
| DR12 | `wc_update_product_stock` ausente en WC viejo | Baja | Medio | `function_exists` + fallback setter+save. |
| DR13 | El update de variaciones dispara un `GET /items/{child}` por hijo | Media | Bajo | Sólo en update de `variantParent`; `get_variant_children` evita el fetch si el payload trae hijos. |
| DR14 | El schema de `/inventory-adjustments` no coincide | Media | Alto | Fase 0 G3; si falla, se desactiva el push y se reporta (nunca escribe a ciegas). |
| DR15 | Opciones nuevas no sembradas ⇒ comportamiento distinto | Baja | Bajo | Read-with-default + `$defaults` + `uninstall.php`; sin migración. |
| DR16 | Divergencia harness↔producción (CONTAINS, endpoint ausente) | Alta | Medio | D8: H1–H8 con tests concretos. |
| **DR17** | **Baseline del ledger desde WC re-infla la primera venta** (B1/Oracle#5) | Alta | Alto | FIX-1: `synced` sólo por push OK/pull/idempotencia; en la primera vez `pending` + `GET /items/{id}` del poll. Traza §3.4. |
| **DR18** | **`is_syncing()` anula el push del poll** (B2/Oracle#2) | Alta | Alto | FIX-2: guard en el hook + `push_delta(..., from_poll: true)`. Test cross-fase con `set_syncing(true)`. |
| **DR19** | **Doble conteo con la factura manual en modo `adjustment`** (B3/Oracle#1) | Media | Alto | FIX-3: condición doble de `owner()`, abrir el borrador existente, guarda de factura `open` en el pusher + guarda manual. G9 verifica el orden de hooks. |
| **DR20** | **Push re-emitido por respuesta perdida** (Oracle#4) | Media | Medio | FIX-4: pre-búsqueda obligatoria + `reference`; fail-safe si la pre-búsqueda falla. |
| **DR21** | **Lock global del cron pegado por un fatal** (Oracle#6) | Media | Alto | FIX-5: `register_shutdown_function` para `alegra_cron_global` **y** `alegra_sync_running_products`; TTL 600/300 como backstop. Best-effort (no OOM/SIGKILL). |
| **DR22** | **Starvation del poll** (Oracle#3) | Alta | Alto | FIX-8: el poll cae al writer cuando el push no es posible; `continue` sólo con delta local sin empujar. |
| **DR23** | **Reset del cursor por `pull_total` nunca escrito** (C6) | Alta | Medio | FIX-9: el poll escribe `pull_total` desde `metadata.total`; reset exige `total > 0 && cursor >= total`. |
| **DR24** | **`_stock_status` con `notify_no_stock_amount > 0`** (Oracle D10) | Media | Medio | FIX-10/D10: se documenta la semántica de WC, test con umbral 2, nota de release; no se replica HEAD. |
| **DR25** | **`unitCost=0` rechazado (422)** (Oracle D12) | Media | Alto | FIX-10/D12: fallback a `price` y a `1.0`; G3 prueba `unitCost: 0`. |
| **DR26** | **Self-heal del CF con `resolve()` limit=5** (Oracle D9) | Media | Medio | FIX-10/D9: `resolve_readonly()` (barrido paginado); crear sólo bajo `run_explicit`. |

---

## 14. Fase 0 — gates de verificación

| Gate | Requerimiento | Check exacto | Rama A | Rama B | De qué depende el diseño |
|---|---|---|---|---|---|
| G1 | REQ-INV-01 (rama b) | En la cuenta: (a) crear factura `draft` con un ítem de stock conocido y observar; (b) **luego** marcar el pedido pagado y correr `create_invoice_with_payment()`; observar si la factura pasa a `open` y si el stock baja. | `draft` **no** mueve y `open` sí ⇒ (b) viable como dueño cuando `push_orders=true && open_invoice_on_paid=true`. | `draft` **sí** mueve ⇒ revisar la apertura; el dueño factura se mantiene pero se documenta. **El flujo (b) es el que valida FIX-3/D1** (el caso "ya pagado al crear" no lo cubre). | §3.3, §12. |
| G2 | REQ-INV-03 | Cambiar **sólo** el stock de un ítem en Alegra y ver el Recorder (`templates/admin-webhooks.php`). | `edit-item` dispara ⇒ webhook refresca en tiempo real. | No dispara ⇒ el poll (D6) es el único camino; el update cubre igual. | §5.2. |
| G3 | D2 (a) | `POST /inventory-adjustments` en la cuenta/docs: confirmar campos exactos (`items[].id/type/quantity/unitCost`, `warehouse`, `date`) y **probar `unitCost: 0`** (200 vs 422) y el filtro de idempotencia (`reference` vs `item_id`). | Schema coincide y `unitCost:0` se acepta ⇒ push activo por defecto. | `unitCost:0` se rechaza ⇒ fallback `price`/`1.0` (FIX-10/D12); sin filtro de idempotencia ⇒ filtrar por `item_id`+fecha. No coincide el schema ⇒ `push_inventory_enabled` default `false` + reporte. | §3.4, DR14/DR25. |
| G4 | REQ-POLL-02 | Contar ítems activos en Alegra (`GET /items?metadata=true`). | Catálogo chico ⇒ defaults actuales. | Catálogo grande ⇒ calibrar budget/tope. | §7.1. |
| G5 | REQ-POLL-05 | Revisar `DISABLE_WP_CRON` + `wp cron event list`. | Ya usa cron real ⇒ recomendación informativa. | WP-Cron por tráfico ⇒ recomendación accionable + crontab. | §8.4. |
| G6 | REQ-CF-06 | Forzar un 400 por client id muerto y capturar el body exacto (`response.client` vs mensaje). | Body trae `client` ⇒ el detector usa el campo. | Sólo mensaje ⇒ el detector cae al regex `/client|cliente/i`. | §2.4. |
| G7 | D5 | Confirmar la versión de WC del comerciante (`function_exists('wc_update_product_stock')`). | Existe ⇒ API recomendada. | No existe (WC < 3.0, improbable) ⇒ fallback setter+save. | §4.2, DR12. |
| G8 | REQ-INV-06 | Semántica de bodega (R7 del SDD `inventory`). | Se implementa warehouse-aware (flag). | **Diferido**: el poll lee el total; la UI lo advierte. | §D2/§7; ver §15. |
| **G9** | **FIX-3 (guarda de factura manual)** | En WC, verificar el orden real de los hooks de reducción de stock: ¿`woocommerce_reduce_order_stock`/`woocommerce_restore_order_stock` (con el pedido) corren **antes** de `woocommerce_product_set_stock` (con el producto)? | Corren antes ⇒ `Stock_Order_Context` puebla el pedido y el pusher suprime el ajuste si la factura está `open` (guarda 1 completa). | No corren antes (o no existen en la versión del comerciante) ⇒ la guarda 1 se degrada a la guarda 2 (advertencia en la acción manual). | §3.3, DR19. |

---

## 15. Decisiones diferidas / fuera de alcance (declaradas)

- **REQ-INV-06 (poll warehouse-aware):** **DIFERIDO** detrás de flag. Hoy el poll lee el total
  (`Products.php:1238`) y el push escribe bodega (`:1118-1137`). El diseño lo difiere (R7 del SDD
  `inventory`, SIN VERIFICAR) y **exige** que la UI de Ajustes advierta, cuando hay bodega configurada,
  que el poll usa el total (REQ-INV-06 escenario 2). No se implementa la lectura por bodega en este
  cambio.
- **REQ-INV-07 (informe de divergencia):** se implementa como **lista informativa** en el dashboard/
  Ajustes: (a) pedidos vendidos sin `_alegra_invoice_id` cuando `push_orders_enabled=false` o cuando el
  dueño es factura y la factura no está `open`; (b) **divergencias por producto** que el poll cuenta en
  su contador separado `$divergence` (**no** es clave de `$result`, ver §7.4/§10) — delta local sin
  empujar, baseline no obtenible, `locked`, o dueño factura con factura no abierta — §3.5/§7.4). Sólo
  lectura; no escribe.
- **Action Scheduler (REQ-POLL-07):** diferido (§8.5). El fallback WP-Cron cumple.
- **DIAN / facturación electrónica:** fuera de alcance (propuesta §3.2).

---

## 16. Trazabilidad (requerimiento → decisión)

| Requerimiento | Decisión |
|---|---|
| REQ-CF-01 | §2.1 (conectar resuelve bajo `run_explicit`) |
| REQ-CF-02 | §2.3 (estados honestos) |
| REQ-CF-03 | §2.3 ("Verificar ahora" + nonce + capacidad) |
| REQ-CF-04 (FORK) | §2.2 (Rama A: `resolve_readonly()`) |
| REQ-CF-05 | §2.5 (paginación + barrido completo) |
| REQ-CF-06 | §2.4 (auto-sanado 400 con `resolve_readonly()` paginado — FIX-10/D9) |
| REQ-CF-07 | §2.3/§2.6 (`unverified` + motivo) |
| REQ-CF-08 | §2.4 (sólo 400 + CF; el resto intacto) |
| REQ-INV-01 (FORK) | §3 (híbrido, dueño por tienda con condición doble — FIX-3) + §3.6/§3.7 |
| REQ-INV-02 | §4 (`Inventory_Writer`) |
| REQ-INV-03 | §5 (update de variaciones) |
| REQ-INV-04 | §6 (`wc_update_product_stock` + backorders; umbral ≠ 0 documentado — FIX-10/D10) |
| REQ-INV-05 | §4.2 (`preserve` en el writer) |
| REQ-INV-06 | §15 (diferido + advertencia UI) |
| REQ-INV-07 | §15 (informe de divergencia) |
| REQ-INV-08 | §3.3 + §3.7 (dueño único con guardas de factura manual — FIX-3) |
| REQ-POLL-01 | §7.2 (budget + cursor + total — FIX-9) |
| REQ-POLL-02 | §7.4 (`truncated` + tope configurable) |
| REQ-POLL-03 | §8.1 (shutdown handler para **ambos** locks — FIX-5) |
| REQ-POLL-04 | §8.2 (`set_syncing` + TTL + `from_poll` — FIX-2) |
| REQ-POLL-05 | §8.4 (cron real en UI) |
| REQ-POLL-06 | §8.3 (deadline global, no inline) |
| REQ-POLL-07 (FORK) | §8.5 (diferido, fallback WP-Cron) |
| NFR-01..07 | §12 + §13 |

