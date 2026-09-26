# Fase 3 — Máquina de estados del poll (D2) + baselines + `reference`

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único configurable + poll sin re-inflación + cola + reconciliación) |
| Fase | 3 de 8 — **D2: el arreglo del poll** (NO apagarlo) |
| Tareas | `T3.1` · `T3.2` · `T3.3` · `T3.4` · `T3.5` · `T3.6` |
| Depende de | **Fases 1–2** (`T2.1` `owner()`; `T1.1` H-A stock por factura; `T1.2` H-B `reference`; `T1.4` H-E líneas de pedido). **`T3.4` además `BLOQUEADO(Fase 0.4 / G3)`** (sólo la rama del filtro server-side) |
| DoD de la fase | REQ-POLL-01..06 verdes; vender 3 → poll → WC **no** sube (`T30.32`); `invoice` no pisa WC (`T30.33`/`T30.34`); `adjustment` con push fallido no pisa (`T30.35`); tras abrir la factura el poll vuelve a bajar (`T30.37`); el poll **sigue corriendo** Alegra→WC; `bash scripts/exec-test.sh` verde |
| Documentos base | `proposal.md` §4 Tema B · `spec.md` §B (REQ-POLL-01..06) · `design.md` §3 (D2) + §7.2 · `tasks.md` Fase 3 + §3 Bloque 3 |
| Versión objetivo | 2.7.0 |
| Decisión de la fase | **D2** (poll con conciencia de dueño; los dos fixes inseparables: baseline + no-pisar) + **D5** (`Inventory_Writer` único escritor) |

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T30.3{n}`
> (`T30.31`…`T30.39`, `T30.310`) según la convención `T30.{fase}{n}` (§5.3 de `tasks.md` es la AUTORIDAD). Los IDs
> de **tarea** (`T3.1`…`T3.6`) no cambian. `T3.5` reusa el ID `T30.51` (compartido con `T5.1`).

> **Regla de oro de esta fase (prove-it-catches, obligatoria).** El test titular de D2 es `T30.32`:
> importar un producto (baseline), vender 3 **antes** del primer poll y correr el poll ⇒ WC **no** vuelve
> al valor de Alegra. **Prove-it-catches:** quitar el baseline de `T3.2` (o el `continue` de
> `LOCAL_PENDING` de `T3.1`) ⇒ WC se re-infla y `T30.32` queda rojo. El segundo prove-it-catches es
> `T30.37`: quitar el baseline de factura (`T3.3`) ⇒ el producto queda en `LOCAL_PENDING` para siempre y
> el poll **no** vuelve a bajar el cambio de Alegra (starvation) ⇒ rojo.

---

## Correcciones de cita y hallazgos (re-verificados en HEAD)

Al leer el código real antes de escribir estas micro-tareas aparecen **11 hallazgos** que cambian el plan
de test y el código.

| # | Claim (design/spec/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1348-1349` (`$handled` **sin** `invoice_owner`) | **Confirmado.** Lista real: `['ok','already_applied','in_sync','api_error','blocked','locked','baseline_unverified']`. **`invoice_owner` NO está** ⇒ el poll **cae al writer** y pisa WC con el valor de Alegra. El `in_array` está en `:1350`; el `continue` en `:1351`. | `T3.1` (el `continue` cambia de semántica: se dispara cuando el push **no** pudo manejar el cambio local). |
| C2 | `Products.php:1332-1334` (`needs_reconcile`) | **Confirmado.** `$needs_reconcile = $product->get_manage_stock() && ($pending !== '' \|\| ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced))`. Con `synced=''` y `pending=''` ⇒ `false` ⇒ el poll escribe Alegra→WC **sin baseline** (Escenario C). | `T3.1`/`T3.2` (reemplazo por la máquina de estados). |
| C3 | W1 **no** setea el ledger (`Products.php:2274-2285`) | **Confirmado.** `apply_inventory_to_product()` delega en `Inventory_Writer::apply()` y **no** llama `set_synced`/`clear_pending`. | `T3.2` (baseline en el import). |
| C4 | "reemplazo de `Products.php:1327-1353`" (`tasks.md` T3.1 / `design.md` §3.3) | **Impreciso.** El algoritmo del diseño §3.3 también envuelve el bloque del writer (`:1355-1401`) en `if ($has_a)` y cambia su control de flujo (sólo se llega con estado no-`LOCAL_PENDING`, o con `push` en `ok/already_applied/in_sync`). El span real a reemplazar es **`1327-1401`**. | `T3.1` reemplaza `1327-1401` (no sólo `1327-1353`). |
| C5 | `Products.php:1378` es el único `set_synced` del poll | **Confirmado.** `set_synced` sólo en `Products.php:1378` y `Inventory_Pusher.php:214,244,271`. | `T3.1` conserva el `set_synced` del writer. |
| C6 | `adjustment_already_exists()` sin `reference` (`:303-335`) | **Confirmado.** Matchea `item+type+quantity` en `:319-333`; el `foreach` de líneas arranca en `:323`. | `T3.4` (reference). |
| C7 | `build_adjustment_payload()` sin `reference` (`:340-358`) | **Confirmado.** El payload es `:342-350` (`date` + `items`), sin `reference` top-level. | `T3.4` (reference). |
| C8 | `Orders.php:494` / `:512` (`create_invoice_with_payment` pasa `'open'`) | **Confirmado.** Declaración `:494`; el `'open'` se pasa en `:512`. | `T3.3` (call site del baseline). |
| C9 | `Orders.php:850-913` (`ensure_invoice_open`) | **Confirmado.** Declaración `:850`; cierra `:913`. | `T3.3` (baseline al abrir). |
| C10 | Mock: `POST /invoices` fuerza `status='open'` y **no** descuenta stock (`alegra-mock.php:854-866`, `'status'=>'open'` en `:861`) | **Confirmado.** El stored fuerza `'status' => 'open'` en `:861` y no toca `availableQuantity`. | `T3.2`/`T3.3` dependen de H-A (`T1.1`). |
| C11 | Mock: `GET /inventory-adjustments` **no** filtra `reference` (`:765-782`); `POST` guarda la reference **del ítem** (`:903`) | **Confirmado.** El GET filtra por `item_id` y pagina (`:766-781`); el stored de línea usa `'reference' => (string) ($item['reference'] ?? '')` (`:903`), no la del payload. | `T3.4` depende de H-B (`T1.2`). |

**Confirmaciones (no requieren corrección).**

- `Products.php:1142` `public function sync_inventory_from_alegra(int $run_id = 0, float $deadline = 0.0): array` — **confirmado** (la firma con `$deadline` ya está en HEAD).
- `Products.php:1147-1157` `$result` con las **9** claves canónicas (`updated`, `errors`, `pages`, `locked`, `skipped`, `skipped_not_manageable`, `truncated`, `completed`, `cursor`) — confirmado.
- `Products.php:1170-1174` gate `inventory_source=woocommerce` ⇒ `skipped` — confirmado (REQ-POLL-05: se conserva).
- `Products.php:1233-1240` presupuesto + `max_pages`; `:1404-1423` cursor + `truncated` + `completed` — confirmados (REQ-POLL-05: se conservan).
- `Products.php:1455` `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0, float $run_deadline = 0.0)` — confirmado.
- `Inventory_Pusher.php:197-216` baseline FIX-1; `:214` `set_synced`; `:244-246` `already_applied`; `:271-273` `ok` — confirmados.
- `Inventory_Pusher.php:157-168` Guard 1; `:170-173` Guard 2; `:218` `$delta`; `:242-247` pre-chequeo; `:249-251` `create_inventory_adjustment` — confirmados.
- `Orders.php:91-93` `ensure_invoice_open` del borrador pre-existente; `:122-126` override `'open'`; `:155-186` POST + persist; `:176` `persist_invoice_result` — confirmados.
- `alegra-mock.php:896` el `POST /inventory-adjustments` aplica el delta (`max(0, $current + $delta)`) — confirmado (H4 resuelto).
- `wp-stubs.php:1489-1504` `wc_update_product_stock` existe (H1 resuelto) — confirmado.
- `wp-stubs.php:1207-1232` `WC_Order_Item` **no** tiene `get_product()`; `:1377` `get_items()`; `:1388` `is_paid()` — confirmado (H-E es un gap **real** para `T3.3`).
- `wp-stubs.php:1568-1611` `wc_get_orders` **no** soporta `paginate` — confirmado (H-C; no lo usa esta fase).
- `wp-stubs.php:1289` `WC_Product::save()`; `:1302` `WC_Product_Variation`; `:1376` `WC_Order::save()` — confirmados (corrigen `sync-reliability/tasks.md:312`).
- `Orders.php:309-340` `find_existing_invoice()` cierra en **`:340`**, no `:341` — la "corrección" **A7** de `tasks.md` §9.2 está mal (el `return null;` está en `:339` y el `}` en `:340`).

---

## Contrato canónico (lo define esta fase; Fase 4/5 lo consumen, nunca lo redefinen)

### K-POLL — Firma, metas y estados

```php
namespace Alegra\Connector\Sync;

final class Products
{
    // + $divergence run-scoped junto a $result (NO es clave de $result)   — T3.5
    public function sync_inventory_from_alegra(int $run_id = 0, float $deadline = 0.0): array;
    private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void; // + baseline (T3.2)
}

final class Orders
{
    private function baseline_products_for_invoice(\WC_Order $order): void;   // NUEVO (T3.3)
}

final class Inventory_Pusher
{
    private function adjustment_reference(int $product_id, int $synced, int $new_qty): string;   // NUEVO (T3.4)
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product, string $reference): array; // +$reference (T3.4)
    private function adjustment_already_exists(string $alegra_item, string $reference, int $delta): bool;                       // +$reference (T3.4)
}

final class Stock_Divergence
{
    // Oracle#2: DOS parámetros. El `?\WC_Order` de fase-5:119 es incompatible con el poll.
    public static function divergence_cause(string $owner, int|string $s): string;   // T3.5 (T5.1 se alinea)
}
```

**Metas (sin cambio de nombres):**

| Meta | Tipo | Semántica |
|---|---|---|
| `_alegra_stock_synced` | int/'' | Último valor en el que WC y Alegra **acordaron**. Se fija **sólo** por: (a) push `ok`/`already_applied`; (b) pull del poll al escribir Alegra→WC; (c) **baseline del import** (`T3.2`); (d) **baseline de la factura** (`T3.3`). **Nunca** con un valor de WC sin push. |
| `_alegra_stock_push_pending` | int/'' | Valor de WC que se está empujando. Se limpia en `ok`/`in_sync`/`already_applied`. |
| `_alegra_stock_adjusted_at` (N) | int | Marca de ajuste emitido (Fase 2 `T2.2`); no la usa el poll. |

**Estados (por ítem del poll):**

```
CONVERGED       = S!='' && P=='' && W==S
NO_BASELINE_EQ  = S=='' && P=='' && A usable && W==A
LOCAL_PENDING   = !CONVERGED && !NO_BASELINE_EQ
```

### K-POLL-2 — `$divergence` (run-scoped, NO clave de `$result`)

`$divergence = 0;` se declara junto a `$result` (`Products.php:1147`). **No** se agrega a `$result` (que
conserva sus **9** claves). Alimenta `Stock_Divergence` (D4). Se devuelve/registra, nunca se imprime como
clave del resultado del poll.

---

### T3.1 — Máquina de estados del poll (`CONVERGED` / `NO_BASELINE_EQ` / `LOCAL_PENDING`)

**Objetivo**: que el poll nunca re-infle (no pise un cambio local en `invoice` ni un push fallido en
`adjustment`) y nunca se muera de hambre (siga bajando Alegra cuando no hay cambio local), sin apagarlo.

**Descripción técnica**: hoy el poll (`Products.php:1327-1401`) calcula `needs_reconcile`, empuja, y si el
`reason` está en `$handled` (`:1348-1349`) hace `continue`; si el `reason` **no** está (p. ej.
`invoice_owner`, `disabled`, `not_linked`), **cae al writer** y pisa WC con el valor de Alegra (B2). Con
`synced=''` y `pending=''`, `needs_reconcile=false` y el poll escribe a ciegas (B3). El diseño §3.3
reemplaza el bloque por una máquina de estados explícita. Corrige **C1**, **C2**, **C4**. Cubre
REQ-POLL-03/04/05 y REQ-OWN-03.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`, dentro de `foreach ($items as $item)` del poll.

**BEFORE** (`:1327-1401`, bloque per-ítem completo):

```php
                    // D2/D6/FIX-8 (REQ-INV-01, NFR-07): el poll NUNCA re-infla
                    // y TAMPOCO se muere de hambre.
                    $synced  = Inventory_Pusher::synced($product_id);
                    $pending = Inventory_Pusher::pending($product_id);

                    $needs_reconcile = $product->get_manage_stock()
                        && ($pending !== ''
                            || ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced));

                    if ($needs_reconcile) {
                        // (a) push fallido en vuelo, (b) delta local sin empujar,
                        // (c) baseline pendiente (FIX-1): reconciliar WC→Alegra.
                        // `from_poll=true` bypassa `is_syncing()` (FIX-2): el poll
                        // es el que corre con set_syncing(true) (T7.3).
                        $push = (new Inventory_Pusher($this->api, $this->logger))
                            ->push_delta($product, (int) $product->get_stock_quantity(), true);

                        // FIX-8: sólo se salta WC cuando el push REALMENTE maneja
                        // el ítem. Si el pusher no es el dueño (disabled/
                        // invoice_owner/not_linked/not_manageable), cae al writer:
                        // el poll es el dueño de la escritura WC y no se congela.
                        $handled = ['ok', 'already_applied', 'in_sync', 'api_error',
                                    'blocked', 'locked', 'baseline_unverified'];
                        if (in_array($push['reason'], $handled, true)) {
                            continue;
                        }
                    }

                    $old_qty = $product->get_stock_quantity();
                    try {
                        // C7: suprimir la cascada del propio wc_update_product_stock
                        // (set_syncing del poll es T7.3; acá el guard por producto).
                        set_transient('alegra_updating_product_' . $product_id, 1, 30);

                        // D3 (REQ-INV-02): un solo escritor. El writer decide
                        // fuente/preserve/nulo/negativo/servicio/manage_stock y
                        // deriva _stock_status con wc_update_product_stock (D5).
                        $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
                            'source'       => 'alegra',
                            'preserve'     => in_array('inventory', $this->resolve_preserve_fields(), true),
                            'manage_stock' => get_option('alegra_connector_inventory_manage_stock_enabled', false)
                                ? 'enable'
                                : 'respect',
                            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
                            'warehouse_id' => $this->resolve_warehouse_id(),
                        ]);

                        if ($status === 'updated' || $status === 'clamped_negative') {
                            // FIX-1: éste es el ÚNICO camino del poll que fija
                            // `synced` sin push: el poll escribió el valor de
                            // Alegra en WC, así que WC y Alegra acuerdan.
                            Inventory_Pusher::set_synced($product_id, (int) $product->get_stock_quantity());
                            Inventory_Pusher::clear_pending($product_id);
                            $result['updated']++;
                            $new_qty = $product->get_stock_quantity();
                            if ($old_qty !== $new_qty) {
                                $this->logger->info('Inventory updated from Alegra', [
                                    'product_id' => $product_id,
                                    'alegra_id'  => $item['id'],
                                    'old_qty'    => $old_qty,
                                    'new_qty'    => $new_qty,
                                ]);
                            }
                        } elseif ($status === 'skipped_not_manageable') {
                            $result['skipped_not_manageable']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error('Failed to update inventory', [
                            'product_id' => $product_id,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        delete_transient('alegra_updating_product_' . $product_id);
                    }
```

**AFTER** (reemplaza `:1327-1401`; conserva el paso (A) que inserta `T2.7`):

```php
                    // D2 §3.3: leer A/W/S/P y el dueño; clasificar el estado.
                    $a_raw  = $item['inventory']['availableQuantity'] ?? null;
                    $has_a  = ($a_raw !== null && $a_raw !== '' && is_numeric($a_raw));
                    $a      = $has_a ? (int) $a_raw : null;

                    $w       = (int) $product->get_stock_quantity();
                    $s       = Inventory_Pusher::synced($product_id);    // int|''
                    $p       = Inventory_Pusher::pending($product_id);   // int|''

                    $owner   = Inventory_Pusher::owner();
                    $push_on = (bool) get_option('alegra_connector_push_inventory_enabled', true);
                    $linked  = (string) get_post_meta($product_id, '_alegra_item_id', true) !== '';
                    $manage  = $product->get_manage_stock();
                    $can_push = ($owner === 'adjustment') && $push_on && $linked && $manage;
                    // D2 §3.4 / momus gap #3: con `preserve=inventory` el stock lo maneja
                    // el comerciante. El poll NO debe baselinar `S=A` (mentiría y luego
                    // divergiría para siempre) ni fijar el baseline pre-venta.
                    $preserve_inventory = in_array('inventory', $this->resolve_preserve_fields(), true);

                    // (A) REQ-OWN-05: limpieza lazy del pending de un modo anterior.
                    if ($owner === 'invoice' && $p !== '') {
                        Inventory_Pusher::clear_pending($product_id);
                        $p = '';
                    }

                    // (B) Clasificación del estado.
                    $converged      = ($s !== '' && $p === '' && $w === (int) $s);
                    $no_baseline_eq = ($s === '' && $p === '' && $has_a && $w === $a);
                    $local_pending  = (!$converged && !$no_baseline_eq);

                    // (B.2) B2/Oracle#4 — convergencia por baseline viejo. Si NO
                    // hay push local en vuelo y WC YA coincide con Alegra, el
                    // baseline S quedó viejo (factura abierta FUERA del plugin,
                    // migración 2.6.0 con S stale). Re-baselinar y caer al writer
                    // evita la starvation (el poll nunca volvería a escribir WC)
                    // y la divergencia FALSA cuando W==A.
                    if ($local_pending && $p === '' && $s !== '' && $has_a && $w === $a) {
                        Inventory_Pusher::set_synced($product_id, $w);
                        $s = $w;
                        $local_pending = false;
                    }

                    if ($local_pending) {
                        if ($can_push) {
                            if ($s === '') {
                                // FIX-1: baseline desde ALEGRA. El poll YA tiene A ⇒ sin GET extra.
                                if (!$has_a) {
                                    $divergence++;                 // no se puede decidir; NO pisar WC
                                    continue;
                                }
                                Inventory_Pusher::set_synced($product_id, $a);
                                $s = $a;
                            }
                            $push = (new Inventory_Pusher($this->api, $this->logger))
                                ->push_delta($product, $w, true);

                            if (in_array($push['reason'], ['ok', 'already_applied', 'in_sync'], true)) {
                                // ok/already_applied ⇒ WC y Alegra acordaron (A == W).
                                // in_sync            ⇒ W == S: no hay delta real; el poll puede bajar A.
                                // En ambos casos cae al writer.
                            } else {
                                // api_error | blocked | locked | baseline_unverified
                                //   | disabled | not_linked | not_manageable
                                // Hay un cambio local sin empujar ⇒ NO pisar WC (se perdería la venta).
                                $divergence++;
                                continue;
                            }
                        } else {
                            // owner=invoice (o push apagado): el cambio local no se puede empujar.
                            if ($s === '' && $has_a && !$preserve_inventory) {
                                Inventory_Pusher::set_synced($product_id, $a);   // baseline PRE-venta
                            }
                            // REQ-POLL-03/04: NO pisar WC; reportar divergencia.
                            $divergence++;
                            continue;
                        }
                    } elseif ($no_baseline_eq && !$preserve_inventory) {
                        // REQ-POLL-01: baselinar sin escribir de más (el writer es no-op).
                        // Con preserve=inventory NO se baselina: el comerciante maneja el
                        // stock y `S=A` sería un baseline mentiroso (momus gap #3).
                        Inventory_Pusher::set_synced($product_id, $a);
                    }

                    // (C) Sin cambio local pendiente: el poll es dueño de la escritura WC.
                    //     Incluye owner=invoice DESPUÉS de que la factura movió stock y baselinó (§3.5).
                    if ($has_a) {
                        $old_qty = $product->get_stock_quantity();
                        try {
                            // C7: suprimir la cascada del propio wc_update_product_stock.
                            set_transient('alegra_updating_product_' . $product_id, 1, 30);

                            // D3 (REQ-INV-02): un solo escritor.
                            $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
                                'source'       => 'alegra',
                                'preserve'     => $preserve_inventory,
                                'manage_stock' => get_option('alegra_connector_inventory_manage_stock_enabled', false)
                                    ? 'enable'
                                    : 'respect',
                                'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
                                'warehouse_id' => $this->resolve_warehouse_id(),
                            ]);

                            if ($status === 'updated' || $status === 'clamped_negative') {
                                // ÚNICO camino del poll que fija `synced` sin push.
                                Inventory_Pusher::set_synced($product_id, (int) $product->get_stock_quantity());
                                Inventory_Pusher::clear_pending($product_id);
                                $result['updated']++;
                                $new_qty = $product->get_stock_quantity();
                                if ($old_qty !== $new_qty) {
                                    $this->logger->info('Inventory updated from Alegra', [
                                        'product_id' => $product_id,
                                        'alegra_id'  => $item['id'],
                                        'old_qty'    => $old_qty,
                                        'new_qty'    => $new_qty,
                                    ]);
                                }
                            } elseif ($status === 'skipped_not_manageable') {
                                $result['skipped_not_manageable']++;
                            }
                        } catch (\Exception $e) {
                            $result['errors']++;
                            $this->logger->error('Failed to update inventory', [
                                'product_id' => $product_id,
                                'error' => $e->getMessage(),
                            ]);
                        } finally {
                            delete_transient('alegra_updating_product_' . $product_id);
                        }
                    }
```

**Diferencia clave con HEAD** (`design.md` §3.3): en HEAD el `continue` de `:1350-1351` se dispara con
**cualquier** reason de `$handled`, y si el reason **no** está (p. ej. `invoice_owner`, `disabled`,
`not_linked`) el flujo **cae al writer**. En el diseño, el `continue` se dispara cuando el push **no**
pudo manejar el cambio local (`LOCAL_PENDING` + reason fuera de `ok|already_applied|in_sync`);
**`in_sync` ahora cae al writer**. Así:

- `owner=invoice` + `LOCAL_PENDING` ⇒ divergencia, **no** pisa WC (REQ-POLL-03). Cierra B2.
- `owner=adjustment` + push fallido ⇒ divergencia, `pending` queda, **no** pisa WC (REQ-POLL-04). Cierra B3.
- Sin cambio local ⇒ el writer baja Alegra (REQ-POLL-05). No hay starvation.
- `S` viejo pero `W==A` sin `pending` ⇒ **converge**: `set_synced(W)` y cae al writer (B2/Oracle#4). Sin
  esta regla el producto quedaba `LOCAL_PENDING` **para siempre** (factura abierta fuera del plugin /
  migración 2.6.0) y se reportaba una divergencia **falsa** (`W==A`).
- `S===''` ⇒ nunca escribe WC a ciegas (REQ-POLL-01). Cierra el Escenario C.

**Opciones leídas por ítem:** `alegra_connector_push_inventory_enabled` (`:451`, default `true`),
`alegra_connector_inventory_manage_stock_enabled` (`:453`, default `false`), `alegra_connector_dry_run`
(`:464`, default `false`), `alegra_connector_inventory_source` (gate previo `:1170-1174`).

**Orden de operaciones**: (A) limpieza lazy → (B) clasificar → **(B.2) convergencia por baseline viejo
(`S!=='' && W==A && P===''` ⇒ `set_synced(W)` y fall-through)** → (C) si `LOCAL_PENDING` y `can_push`,
baseline desde `A` + `push_delta`; `ok|already_applied|in_sync` cae al writer, el resto `continue`; si
`NO_BASELINE_EQ`, `set_synced`; luego (C) el writer **sólo** si `$has_a`. El `try/finally` del transient
se conserva (un throw del writer no aborta el poll).

**Resultado esperado (aceptación verificable)**:
- `owner=invoice`, `W=7`, `A=10`, `S` presente (`LOCAL_PENDING`) ⇒ WC queda 7, `$divergence` incrementa,
  **0** `POST /inventory-adjustments`.
- `owner=invoice`, `S=''`, `W=7`, `A=10` ⇒ WC queda 7 (no se re-infla a 10) y se fija `S=10` como baseline
  pre-venta.
- `owner=adjustment`, `pending` sucio + push fallido (`api_error`) ⇒ WC queda 7, `pending` queda, **no** pisa.
- Sin cambio local (`S=7`, `W=7`, `A=12`) ⇒ `CONVERGED`, el writer baja WC a 12 y `S=12` (REQ-POLL-05).
- **B2/Oracle#4**: `owner=invoice`, `S=10` (viejo), `W=7`, `A=7`, `P=''` ⇒ `set_synced(7)`, **no** registra
  divergencia, y un cambio posterior de Alegra a 12 **baja** a WC=12 (el poll no queda congelado).
- `S=''`, `W=10`, `A=10` ⇒ `NO_BASELINE_EQ`, `set_synced(10)` y el writer es no-op.
- **`preserve=inventory`** (`preserve_fields=['inventory']`), `S=''`, `W=5`, `A=10` ⇒ **no** se fija `S`
  (queda `''`), WC queda 5 y no hay baseline mentiroso (momus gap #3).
- `A` ausente/servicio ⇒ `$has_a=false` ⇒ nunca se escribe WC a ciegas.

**Dependencias**: `T2.1`, `T2.7`. `T1.1` (H-A: el mock descuenta por factura). **T3.2/T3.3** son
**inseparables**: sin `T3.2` el poll re-infla; sin `T3.3` el poll se congela (starvation).

**Trazabilidad**: REQ-POLL-03/04/05, REQ-OWN-03; D2 §3.3; DR3, DR4; correcciones **C1**, **C2**, **C4**;
`tasks.md` R3/R4.

**Verificación**:
1. Test `T30.33 invoice no pisa WC`: `owner=invoice`, `S=10`, `W=7`, `A=10` ⇒ `sync_inventory_from_alegra()`
   deja WC en 7 y `assertSame(0, $mock['adjustment_posts'])`. **Prove-it-catches**: reponer el fall-through
   al writer ⇒ WC sube a 10 y el test falla.
2. Test `T30.34 invoice sin baseline no pisa`: `owner=invoice`, `S=''`, `W=7`, `A=10` ⇒ WC queda 7 y
   `S=10` (baseline pre-venta).
3. Test `T30.35 adjustment con push fallido no pisa`: forzar `POST /inventory-adjustments` a 500 ⇒ WC
   queda 7 y `pending` sigue seteado.
4. Test `T30.310 convergencia por baseline viejo (B2/Oracle#4)`: `owner=invoice`, `S=10`, `W=7`, `A=7`
   (sin `pending`) ⇒ tras el poll, `assertSame(7, Inventory_Pusher::synced($pid))` y **0** divergencias
   registradas; luego cambiar Alegra a `A=12` y correr el poll ⇒ `assertSame(12, WC qty)` (el poll **no**
   queda congelado). **Prove-it-catches**: quitar el bloque (B.2) ⇒ el producto queda `LOCAL_PENDING`, se
   registra divergencia falsa y WC **no** baja a 12 ⇒ rojo.
5. Sub-assert REQ-POLL-05: `S=7`, `W=7`, `A=12` ⇒ WC pasa a 12 (el poll sigue bajando).
6. Sub-assert `preserve=inventory` (momus gap #3): con `preserve_fields=['inventory']`, `S=''`, `W=5`,
   `A=10` ⇒ tras el poll, `assertSame('', Inventory_Pusher::synced($pid))` y WC sigue en 5.
   **Prove-it-catches**: quitar el guard `!$preserve_inventory` del `NO_BASELINE_EQ`/baseline pre-venta ⇒
   `synced` pasa a 10 y el assert falla.
7. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el orden de las compuertas o el `in_sync` cambien el conteo de `updated` (R4). **Guarda**:
la tabla de estados es el contrato; cada fila tiene su assert; `in_sync` cae al writer por diseño.

**Estimación**: L (8 h).

---

### T3.2 — Baseline en el import (W1)

**Objetivo**: que `apply_inventory_to_product()` fije `_alegra_stock_synced` con el valor escrito **sólo**
si el writer realmente escribió, para que un producto importado y vendido antes del primer poll no
re-infla.

**Descripción técnica**: W1 (`Products.php:2274-2285`) delega en `Inventory_Writer` y **no** setea el
ledger (C3). Un producto importado y vendido antes del primer poll tiene `synced=''` ⇒ `needs_reconcile=false`
⇒ el poll escribe Alegra→WC y re-infla (B1). `T3.2` captura el retorno de `apply()` y fija el baseline
**sólo** con `updated`/`clamped_negative`; con `skipped_*`/`dry_run`/`skipped_source` **no** (fijar
`synced` mentiría y el poll no reconciliaría — DR14). Cubre REQ-POLL-01.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`, método `apply_inventory_to_product()`.

**BEFORE** (`:2274-2285`):

```php
    private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void
    {
        (new Inventory_Writer($this->logger))->apply($product, $item, [
            'source'       => (string) get_option('alegra_connector_inventory_source', 'alegra'),
            'preserve'     => in_array('inventory', $preserve, true),
            // El import SIEMPRE habilitó manage_stock (comportamiento HEAD, W1
            // `:2072`): se conserva con 'enable' para no cambiar el import.
            'manage_stock' => 'enable',
            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
            'warehouse_id' => $this->resolve_warehouse_id(),
        ]);
    }
```

**AFTER** (captura el retorno + baseline condicional):

```php
    private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void
    {
        $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
            'source'       => (string) get_option('alegra_connector_inventory_source', 'alegra'),
            'preserve'     => in_array('inventory', $preserve, true),
            'manage_stock' => 'enable',
            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
            'warehouse_id' => $this->resolve_warehouse_id(),
        ]);

        // REQ-POLL-01: el import fija el baseline SÓLO si el writer realmente
        // escribió. Con skipped_*/dry_run/skipped_source WC y Alegra NO
        // acordaron; fijar `synced` mentiría y el poll no reconciliaría (DR14).
        if ($status === 'updated' || $status === 'clamped_negative') {
            $pid = (int) $product->get_id();
            Inventory_Pusher::set_synced($pid, (int) $product->get_stock_quantity());
            Inventory_Pusher::clear_pending($pid);
        }
    }
```

**Orden de operaciones**: `apply()` escribe (o no) y el baseline va **después**, leyendo
`$product->get_stock_quantity()` ya actualizado por `wc_update_product_stock()`. El `$product->save()` de
`update_product_from_alegra()` (`:2240`) sigue siendo idempotente.

**Resultado esperado (aceptación verificable)**:
- Import de un ítem con `availableQuantity=10` ⇒ `synced=10`.
- Import con `preserve_fields=['inventory']` ⇒ `skipped_preserve` y `synced` **no** se fija (queda `''`).
- Import de un servicio (sin `inventory`) ⇒ `skipped_service` y `synced` **no** se fija.
- Import en `dry_run` ⇒ `synced` **no** se fija.

**Dependencias**: `T2.1`; `T3.1` (el poll lee el baseline). `T1.1` (H-A).

**Trazabilidad**: REQ-POLL-01; D2 §3.4; DR14; corrección **C3**; `tasks.md` R14.

**Verificación**:
1. Test `T30.31 baseline en el import (sólo con updated)`: importar con `availableQuantity=10` ⇒
   `assertSame(10, Inventory_Pusher::synced($pid))`; con `preserve_fields=['inventory']` ⇒
   `assertSame('', Inventory_Pusher::synced($pid))`. **Prove-it-catches**: fijar `synced` siempre ⇒ el
   segundo assert falla.
2. Test `T30.32 venta pre-poll no re-infla (titular)`: importar (10), vender 3 (WC=7) **antes** del poll,
   correr el poll ⇒ `assertSame(7, WC qty)` y `assertSame(7, Inventory_Pusher::synced($pid))`.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el baseline se fije con un valor de WC sin push (DR17 de `sync-reliability`). **Guarda**:
sólo con `updated`/`clamped_negative`; el test (1) cubre el caso negativo.

**Estimación**: M (3 h).

---

### T3.3 — Baseline al mover stock la factura

**Objetivo**: que al crear/abrir una factura que mueve stock, el plugin fije `_alegra_stock_synced = WC
qty` por cada producto de las líneas del pedido y limpie `pending`, para que el poll vuelva a bajar
(starvation → `CONVERGED`).

**Descripción técnica**: hoy abrir/crear la factura **no** actualiza el ledger (B4). En modo `invoice`,
`S` queda viejo ⇒ `LOCAL_PENDING` para siempre; con el fix de `T3.1` el poll **no** pisa WC (evita la
re-inflación) pero el producto queda divergente indefinidamente. El diseño §3.5 fija `synced = WC qty`
(el movimiento de la factura y la reducción de WC son el **mismo evento de venta**), **sin** GET por
producto (NFR-04). Exige `owner()==='invoice'` **y** `is_paid()` (DR13: sin pago, WC todavía no redujo y
el baseline sería mentira). Cubre REQ-POLL-02.

**Desarrollo técnico**:

Archivo: `includes/Sync/Orders.php` — método **nuevo** (privado), junto a `persist_invoice_status()`
(`:239-250`):

```php
    /**
     * REQ-POLL-02 / D2 §3.5: al mover stock la factura, fija el baseline en WC
     * qty por línea. Sin esto el poll queda congelado (starvation, C27).
     * Exige dueño invoice + pago (DR13); NO hace GET por producto (NFR-04).
     */
    private function baseline_products_for_invoice(\WC_Order $order): void
    {
        if (Inventory_Pusher::owner() !== 'invoice') {
            return;   // en adjustment la factura NO mueve stock
        }
        if (!$order->is_paid()) {
            return;   // WC todavía no redujo: el baseline sería mentira (DR13)
        }
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $pid = (int) $product->get_id();
            if ($pid <= 0) {
                continue;
            }
            Inventory_Pusher::set_synced($pid, (int) $product->get_stock_quantity());
            Inventory_Pusher::clear_pending($pid);
        }
    }
```

**Call sites** (3 + el de `T2.3`):

| # | Dónde (HEAD) | Cuándo |
|---|---|---|
| 1 | `create_invoice()` — tras el POST exitoso `:175-186` | Factura creada con `status ∈ {open, paid}` |
| 2 | `create_invoice()` — en el early-return del borrador `:91-93` (lo agrega `T2.3`) | Tras `ensure_invoice_open()` exitoso |
| 3 | `create_invoice_with_payment()` — tras `create_invoice()` `:512-523` | Factura creada (el pago abre la factura) |
| 4 | `ajax_open_invoice_impl()` — tras `ensure_invoice_open()` `:2864-2883` | Apertura manual exitosa |

Fragmento del call site 1 (después de `persist_invoice_result()` `:176`):

```php
            if (isset($result['id'])) {
                $this->persist_invoice_result($order, (string) $result['id'], $result);
                $this->baseline_products_for_invoice($order);   // REQ-POLL-02
                $order->add_order_note(sprintf(
```

Fragmento del call site 4 (`admin/Admin/Admin_Dashboard.php`, tras `persist_invoice_status()` `:2883`):

```php
        $orders_sync->persist_invoice_status($order, $result);
        $orders_sync->baseline_products_for_invoice($order);   // REQ-POLL-02
```

> **Visibilidad.** `baseline_products_for_invoice()` es `private` en `Orders`. El call site 4 vive en
> `Admin_Dashboard`, así que el método debe ser **`public`** (o se agrega un wrapper público). Se adopta
> **`public function baseline_products_for_invoice(\WC_Order $order): void`** (contrato K-POLL). No expone
> secretos ni escribe Alegra: sólo meta local.

**Orden de operaciones**: el baseline va **después** de que la factura quedó `open`/`paid` y de que WC ya
redujo stock (`is_paid()` es el proxy). Nunca antes (DR13). El `clear_pending()` es obligatorio: un
`pending` viejo haría que el poll intente reconciliar de nuevo.

**Resultado esperado (aceptación verificable)**:
- `owner=invoice` + pedido pagado + factura abierta ⇒ por cada línea, `synced = WC qty` y `pending=''`.
- `owner=invoice` + pedido **impago** (factura draft) ⇒ el baseline **no** se adelanta.
- `owner=adjustment` ⇒ el método es no-op.
- Tras abrir la factura, `S=W` ⇒ `CONVERGED` ⇒ el poll vuelve a bajar un cambio de Alegra.

**Dependencias**: `T3.1`, `T3.2` (inseparables). `T1.4` (H-E: **`WC_Order_Item::get_product()` falta** en
`wp-stubs.php:1207-1232`; sin el stub el test es vacío). `T2.3` (call site 2).

**Trazabilidad**: REQ-POLL-02; D2 §3.5; DR4, DR13; `tasks.md` R13/R4.

**Verificación**:
1. Test `T30.36 baseline de factura exige is_paid`: pedido pagado + factura abierta ⇒
   `assertSame($wc_qty, Inventory_Pusher::synced($pid))`; pedido impago ⇒ `synced` sin cambio.
   **Prove-it-catches**: quitar el guard `is_paid()` ⇒ el segundo assert falla.
2. Test `T30.37 tras abrir la factura, el poll vuelve a bajar (no starvation)`: `owner=invoice`, `S` viejo,
   `W=7`; abrir la factura (baseline `S=7`); cambiar Alegra a 12; correr el poll ⇒ WC=12.
   **Prove-it-catches**: quitar el baseline de factura ⇒ `LOCAL_PENDING` permanente y WC no baja ⇒ rojo.
3. `bash scripts/exec-test.sh` verde.

**Riesgo**: que `synced = WC qty` enmascare una divergencia previa. **Mitigación**: el informe de D4
compara WC vs Alegra y la saca a la luz (DR13); se documenta en `CHANGELOG.md` (`T7.3`).

**Estimación**: L (4 h).

---

### T3.4 — `reference` en la idempotencia de ajustes · `BLOQUEADO(Fase 0.4 / G3)`

**Objetivo**: que el payload y el pre-chequeo de ajustes incluyan una `reference` estable que identifique
el movimiento, para no confundir un `out 1` viejo con uno nuevo ni duplicar un reintento.

**Descripción técnica**: hoy `adjustment_already_exists()` (`:303-335`) matchea `item+type+quantity` en los
últimos 30 y **no** usa `reference` (C6); `build_adjustment_payload()` (`:340-358`) no emite `reference`
(C7). Un ajuste legítimo puede marcarse `already_applied` y no emitirse (fuga de stock, B5). El diseño
§3.6 define `reference = 'wc-stock-{product_id}-{synced}-{new_qty}'`, calculada **una vez** en
`push_delta()` y pasada tanto al pre-chequeo como al payload. La `reference` estable en el payload se
implementa **siempre**; la rama del **filtro server-side** del GET queda `BLOQUEADO(G3)` (Rama A: filtrar
por `reference`; Rama B: fallback `item+type+quantity`). La rama se fija con la **constante de clase**
`Inventory_Pusher::USE_REFERENCE_FILTER` (`true` = Rama A, `false` = Rama B), **no** con una opción de
runtime (CORRECCIONES **C5/C29**: G3 es decisión de build y el conteo de opciones queda en 8). Cubre REQ-POLL-06.

**Desarrollo técnico**:

Archivo: `includes/Sync/Inventory_Pusher.php`.

1. **Constante de build + helper nuevo** (junto a `adjustment_already_exists()`):

```php
    /**
     * REQ-POLL-06 / D2 §3.6 / G3: rama del filtro server-side por `reference`.
     * Decisión de BUILD, no de runtime (CORRECCIONES C5/C29): NO hay opción.
     * Rama A (el GET soporta `reference`) ⇒ true; Rama B ⇒ false.
     */
    public const USE_REFERENCE_FILTER = true;

    /**
     * REQ-POLL-06 / D2 §3.6: reference estable del movimiento. Distingue dos
     * `out 1` del mismo ítem y reconoce el MISMO movimiento reintentado.
     */
    private function adjustment_reference(int $product_id, int $synced, int $new_qty): string
    {
        return 'wc-stock-' . $product_id . '-' . $synced . '-' . $new_qty;
    }
```

2. **`push_delta()`** — calcular la reference **una vez** antes del pre-chequeo. ANTES (`:232-251`):

```php
        $pending_prev = (string) self::pending($id);

        try {
            // K-F: pending ANTES del POST. ...
            self::set_pending($id, $new_qty);

            // FIX-4 (D4): pre-búsqueda de idempotencia OBLIGATORIA antes de
            // re-emitir. ...
            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                self::mark_adjusted($id);   // T2.2
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }

            $res = $this->api->create_inventory_adjustment(
                $this->build_adjustment_payload($alegra_item, $delta, $product)
            );
```

DESPUÉS:

```php
        $pending_prev = (string) self::pending($id);
        $reference    = $this->adjustment_reference($id, (int) $synced, $new_qty);

        try {
            self::set_pending($id, $new_qty);

            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $reference, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                self::mark_adjusted($id);
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }

            $res = $this->api->create_inventory_adjustment(
                $this->build_adjustment_payload($alegra_item, $delta, $product, $reference)
            );
```

3. **`build_adjustment_payload()`** — ANTES (`:340-358`):

```php
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product): array
    {
        $payload = [
            'date'  => current_time('Y-m-d'),
            'items' => [[
```

DESPUÉS:

```php
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product, string $reference): array
    {
        $payload = [
            'date'      => current_time('Y-m-d'),
            'reference' => $reference,          // REQ-POLL-06
            'items'     => [[
```

4. **`adjustment_already_exists()`** — ANTES (`:303-335`):

```php
    private function adjustment_already_exists(string $alegra_item, int $delta): bool
    {
        ...
        $want_type = $delta < 0 ? 'out' : 'in';
        $want_qty  = abs($delta);
        foreach ($res as $adjustment) {
            ...
            foreach (($adjustment['items'] ?? []) as $line) {
                ...
                if ((string) ($line['id'] ?? '') === $alegra_item
                    && (string) ($line['type'] ?? '') === $want_type
                    && (int) ($line['quantity'] ?? 0) === $want_qty) {
                    return true;
                }
            }
        }
        return false;
    }
```

DESPUÉS (Rama A de G3: comparar `reference`; Rama B: fallback):

```php
    private function adjustment_already_exists(string $alegra_item, string $reference, int $delta): bool
    {
        ...
        $want_type = $delta < 0 ? 'out' : 'in';
        $want_qty  = abs($delta);
        $use_reference = self::USE_REFERENCE_FILTER;   // G3 (constante de build, C5/C29)

        foreach ($res as $adjustment) {
            if (!is_array($adjustment)) {
                continue;
            }
            // G3 Rama A: el endpoint devuelve la `reference` (top-level o por línea).
            if ($use_reference) {
                $ref = (string) ($adjustment['reference'] ?? '');
                if ($ref !== '' && $ref === $reference) {
                    return true;
                }
                if ($ref !== '') {
                    continue;   // hay reference y no coincide ⇒ no es el mismo movimiento
                }
            }
            // G3 Rama B: fallback al comportamiento actual (item+type+quantity).
            foreach (($adjustment['items'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                if ((string) ($line['id'] ?? '') === $alegra_item
                    && (string) ($line['type'] ?? '') === $want_type
                    && (int) ($line['quantity'] ?? 0) === $want_qty) {
                    return true;
                }
            }
        }
        return false;
    }
```

**Rama de G3 (constante de build, no opción — CORRECCIONES C5/C29):**

| Rama G3 | Comportamiento | Código |
|---|---|---|
| **A** (esperada: el GET soporta `reference`) | Comparar `reference` server-side; el mock la devuelve (H-B). | `USE_REFERENCE_FILTER = true` |
| **B** (no soporta) | Fallback `item+type+quantity`; la `reference` estable **igual** viaja en el payload y ayuda al caso "mismo movimiento". | `USE_REFERENCE_FILTER = false` |

**Orden de operaciones**: la `reference` se calcula **una vez** con `$synced` (baseline ya resuelto en
`:198-216`) y `$new_qty`; se pasa al pre-chequeo (`:242-243`) y al payload (`:249-251`). Un `out 1` viejo
con otra reference no se confunde; un reintento del mismo movimiento con la misma reference se reconoce.

**Resultado esperado (aceptación verificable)**:
- Dos `out 1` del mismo ítem con references distintas ⇒ el pre-chequeo **no** los confunde; el segundo se
  emite con su `reference`.
- El mismo movimiento reintentado (misma reference) ⇒ `already_applied`, sin duplicar.
- El payload de `POST /inventory-adjustments` incluye `reference` top-level.
- El mock (H-B) guarda la `reference` del payload y el GET la devuelve/filtra.

**Dependencias**: `T2.2` (marca en `already_applied`). `T1.2` (H-B: el mock filtra/guarda `reference`).
**`BLOQUEADO(Fase 0.4 / G3)`** para decidir la rama del filtro.

**Trazabilidad**: REQ-POLL-06; D2 §3.6; DR5; correcciones **C6**, **C7**, **C11**; `tasks.md` R7, S1.

**Verificación**:
1. Test `T30.38 dos out 1 con reference distinta`: sembrar un ajuste con `reference=wc-stock-1-10-9` y
   necesitar otro `out 1` con `reference=wc-stock-1-9-8` ⇒ el pre-chequeo no lo marca; el POST sale con
   la reference nueva. **Prove-it-catches**: volver a `item+type+quantity` ⇒ el pre-chequeo lo marca y el
   assert falla.
2. Test `T30.39 mismo movimiento reintentado ⇒ already_applied`: mismo `$synced`/`$new_qty`, respuesta
   perdida y reintento ⇒ `assertSame('already_applied', $r['reason'])` y 1 solo ajuste en el mock.
3. Sub-assert payload: `assertSame('wc-stock-{pid}-{synced}-{new}', $mock['last_adjustment']['reference'])`.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: que el endpoint real no soporte `reference` (DR5). **Guarda**: G3 decide la rama; el fallback
mantiene el comportamiento actual; el mock se extiende igual (H-B).

**Estimación**: L (4 h).

---

### T3.5 — Contador `$divergence` run-scoped + registro en `Stock_Divergence`

**Objetivo**: que cada vez que el poll **no** escribe WC por un cambio local, registre la divergencia
`{w, a, s, cause, at}` para el informe de D4, sin agregar claves a `$result`.

**Descripción técnica**: el poll ya tiene `A`, `W`, `S` y el reason en el punto de decisión (D4 §5.1).
`T3.5` declara `$divergence = 0` **junto a** `$result` (NO como clave: `$result` conserva sus **9**
claves) y llama `Stock_Divergence::record($product_id, $entry)` en cada punto `continue` de `T3.1` por
cambio local. La **causa** la calcula `Stock_Divergence::divergence_cause()` (T5.1). Cubre REQ-RECON-01.

**Desarrollo técnico**:

Archivo: `includes/Sync/Products.php`.

1. **Declaración** junto a `$result` (`:1147-1157`):

```php
        $result = [
            'updated'                => 0,
            'errors'                 => 0,
            'pages'                  => 0,
            'locked'                 => false,
            'skipped'                => false,
            'skipped_not_manageable' => 0,
            'truncated'              => false,
            'completed'              => false,
            'cursor'                 => 0,
        ];

        // D4 §5.1: contador run-scoped. NO es clave de $result (9 claves).
        $divergence = 0;
```

2. **Registro** en los 3 puntos de divergencia de `T3.1` (los `continue` por `LOCAL_PENDING`):

```php
                                    // (can_push, S=='' y sin A usable)
                                    Stock_Divergence::record($product_id, [
                                        'w' => $w, 'a' => $a, 's' => $s,
                                        'cause' => Stock_Divergence::divergence_cause($owner, $s),
                                        'at' => time(),
                                    ]);
                                    $divergence++;
                                    continue;
```

(Idéntico en la rama `can_push` con push fallido y en la rama `!can_push`.)

> **Oracle#2 (REVIEW-oracle DEF-2) — firma ÚNICA y sin `WC_Order`.** La firma del poll es
> `divergence_cause(string $owner, int|string $s): string` (**dos** parámetros). El tercer argumento
> `?\WC_Order $order = null` de `fase-5:119` es **incompatible**: el poll no tiene un pedido por
> producto y, con `declare(strict_types=1)` (`Products.php`), pasar un `int|''` a `?\WC_Order` lanza
> **`TypeError`** y el poll muere en el primer ítem divergente. `T5.1` **DEBE** alinearse a esta firma.
> Las causas que dependen de un pedido (`factura_fallida`, `factura_pendiente`, `reembolso`) se resuelven
> en el **informe** (`T5.2::report()`, donde el pedido se puede buscar), **no** en `record()`. En el poll
> sólo se producen `baseline_ausente` (`S===''`) y `divergencia_dueno` (resto).

3. **Log de cierre** (opcional, tras el loop): si `$divergence > 0`,
   `info('Inventory poll: divergences reported', ['count' => $divergence])`.

> **Resolución de la dependencia circular (corrección C14).** `tasks.md` dice a la vez "T5.1 consumido por
> T3.5" y "T5.1 consume T3.5". Resolución: `T3.5` usa el **esqueleto** `Stock_Divergence` (`T1.8`) y
> `divergence_cause()` con los dos casos que puede calcular en Fase 3 (`baseline_ausente` si `S===''`,
> si no `divergencia_dueno`); `T5.1` **consolida** la función **sin cambiar su firma de dos parámetros**
> (Oracle#2) y las causas que requieren pedido (`factura_fallida`/`reembolso`/`factura_pendiente`) pasan al
> **informe** (`T5.2::report()`), no a `divergence_cause()`. El ID de test `T30.51` es compartido.

**Orden de operaciones**: `record()` se llama **antes** del `continue`, con `$w`/`$a`/`$s` del ítem y el
`$owner` vigente. No hace GET extra (REQ-RECON-01/NFR-04).

**Resultado esperado (aceptación verificable)**:
- Un ítem que no se escribe por cambio local ⇒ `Stock_Divergence` recibe `{w,a,s,cause,at}`.
- `$result` conserva **exactamente** sus 9 claves (assert de conteo).
- `baseline_ausente` cuando `S===''`; `divergencia_dueno` en el resto (Fase 3).
- `divergence_cause()` se llama con **dos** argumentos (`$owner`, `$s`); no recibe un `WC_Order` y **no**
  lanza `TypeError` (Oracle#2).
- Sin cambio local ⇒ no se registra divergencia (sin falsos positivos, DR22).

**Dependencias**: `T3.1`; `T1.8` (`Stock_Divergence` esqueleto). `T5.1` consolida la causa **y se alinea a
la firma de dos parámetros**.

**Trazabilidad**: REQ-RECON-01; D4 §5.1; DR22; correcciones **C14**, **Oracle#2**.

**Verificación**:
1. Test `T30.51 divergencia + causa`: `owner=invoice`, `W=7`, `A=10`, `S=10` ⇒ tras el poll,
   `assertSame(1, count(Stock_Divergence::report()['items']))` con `cause='divergencia_dueno'`.
   **Prove-it-catches**: quitar el `record()` ⇒ el informe queda vacío y el test falla.
2. Sub-assert Oracle#2: la llamada del poll usa la firma de dos parámetros y el poll **completa** sin
   `TypeError` con `S` entero y con `S=''`.
3. Sub-assert: `assertCount(9, array_keys($result))` tras el poll.
4. `bash scripts/exec-test.sh` verde.

**Riesgo**: registrar divergencia falsa cuando `W==A` y `S===''` (DR22). **Guarda**: ese caso es
`NO_BASELINE_EQ` y **no** registra; el test (1) y el escenario negativo de REQ-POLL-03 lo cubren.

**Estimación**: M (3 h).

---

### T3.6 — Cierre de fase: test del titular + prove-it-catches

**Objetivo**: cerrar D2 con el test titular (`T30.32`) y los prove-it-catches documentados.

**Descripción técnica**: la fase no está cerrada hasta que el escenario del comerciante ("vendo 3 y el
stock vuelve a subir solo") quede anclado: importar → vender pre-poll → poll ⇒ WC no sube. Se documentan
las reversiones de `T3.1`/`T3.2`/`T3.3` en `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`). Cubre
REQ-POLL-01/04.

**Desarrollo técnico**:

No escribe producto. Se agrega al final de la sección `T30` (`scripts/exec-test.php`):

| Test | Cubre | Assert clave |
|---|---|---|
| `T30.31` | REQ-POLL-01 | baseline en el import sólo con `updated` |
| `T30.32` | REQ-POLL-01 (titular) | vender pre-poll → poll → WC **no** sube |
| `T30.33` | REQ-POLL-03 | `invoice` no pisa WC; 0 ajustes |
| `T30.34` | REQ-POLL-03 | `invoice` sin baseline no pisa |
| `T30.35` | REQ-POLL-04 | `adjustment` con push fallido no pisa |
| `T30.36` | REQ-POLL-02 | baseline de factura exige `is_paid` |
| `T30.37` | REQ-POLL-02 (starvation) | tras abrir la factura, el poll vuelve a bajar |
| `T30.38` | REQ-POLL-06 | dos `out 1` con reference distinta |
| `T30.39` | REQ-POLL-06 | mismo movimiento reintentado ⇒ `already_applied` |
| `T30.310` | REQ-POLL-05 (B2/Oracle#4) | `S` viejo + `W==A` ⇒ converge y el poll vuelve a bajar |
| `T30.51` | REQ-RECON-01 | divergencia + causa (compartido con `T5.1`) |

**Escenario titular (`T30.32`), pasos exactos:**

1. `alegra_test_reset();` crear un ítem en Alegra con `availableQuantity=10`; importarlo ⇒ `synced=10`.
2. Simular la venta: `$product->set_stock_quantity(7); $product->save();` (WC=7) **antes** de cualquier poll.
3. Correr `sync_inventory_from_alegra()`.
4. Asserts: `assertSame(7, $product->get_stock_quantity())` (WC **no** sube a 10) y
   `assertSame(7, Inventory_Pusher::synced($pid))`.

**Resultado esperado (aceptación verificable)**: `bash scripts/exec-test.sh` ⇒ `EXEC-TEST OK` con
`T30.31`–`T30.310` + `T30.51` verdes y 0 failed; el poll sigue corriendo (REQ-POLL-05).

**Dependencias**: `T3.1`–`T3.5`; H-A/H-B/H-E (`T1.1`/`T1.2`/`T1.4`).

**Trazabilidad**: REQ-POLL-01..06, REQ-RECON-01; NFR-01; `tasks.md` R2/R4/R14.

**Verificación**:
1. `bash scripts/exec-test.sh` → `EXEC-TEST OK`, 0 failed.
2. **Prove-it-catches** (los cuatro obligatorios): quitar el baseline de `T3.2` ⇒ `T30.32` rojo; quitar el
   `continue` de `LOCAL_PENDING` de `T3.1` ⇒ `T30.33` rojo; quitar el baseline de factura de `T3.3` ⇒
   `T30.37` rojo; quitar el bloque (B.2) de convergencia de `T3.1` ⇒ `T30.310` rojo (starvation +
   divergencia falsa). Re-aplicar cada uno ⇒ verde. Documentar en `docs/RELEASE_2.7.0_VERIFICATION.md`.
3. `bash scripts/smoke-test.sh` → `SMOKE OK`.

**Riesgo**: que el harness H-A/H-E no modele stock por factura o las líneas (los tests serían vacíos,
DR16). **Guarda**: `T1.1`/`T1.4` se implementan **antes** (Fase 1) y `T30.11`/`T30.14` los cubren.

**Estimación**: M (2 h).

---

## DoD de la fase

- REQ-POLL-01..06 verdes; el poll **no se apaga** (REQ-POLL-05): presupuesto/cursor/`truncated`
  (`Products.php:1233-1240,1404-1423`) y el gate `inventory_source=woocommerce` (`:1170-1174`) intactos.
- La máquina `CONVERGED`/`NO_BASELINE_EQ`/`LOCAL_PENDING` reemplaza `Products.php:1327-1401` (corrección
  **C4**: el span real es `1327-1401`, no `1327-1353`) y conserva el paso (A) de `T2.7`.
- `invoice` + `W≠A` ⇒ **no** pisa WC y reporta divergencia (`T30.33`/`T30.34`); `adjustment` + push
  fallido ⇒ **no** pisa WC (`T30.35`); sin cambio local ⇒ el writer baja Alegra (`T30.37`).
- **B2/Oracle#4**: `S` viejo + `W==A` sin `pending` ⇒ converge (`set_synced(W)`), **no** registra
  divergencia falsa y el poll sigue bajando cambios posteriores de Alegra (`T30.310`).
- Baseline en el import sólo con `updated`/`clamped_negative` (`T30.31`); baseline de factura con
  `owner=invoice` + `is_paid()` (`T30.36`).
- `reference` estable `wc-stock-{pid}-{synced}-{new}` en el payload y en el pre-chequeo
  (`T30.38`/`T30.39`); la rama del filtro server-side según G3.
- `$divergence` run-scoped registra en `Stock_Divergence` sin tocar las 9 claves de `$result` (`T30.51`).
- **Oracle#2**: `divergence_cause($owner, $s)` con **dos** parámetros; sin `TypeError` (T5.1 se alinea).
- **Test titular** `T30.32` verde: vender 3 pre-poll → poll → WC **no** sube.
- **Prove-it-catches** de `T3.1`/`T3.2`/`T3.3` documentados en `docs/RELEASE_2.7.0_VERIFICATION.md`.
- `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes.
