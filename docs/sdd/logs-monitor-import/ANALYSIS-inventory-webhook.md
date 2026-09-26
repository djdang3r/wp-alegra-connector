# Analysis — `edit-item` webhook does not move stock + inventory architecture

| Field | Value |
|---|---|
| Scope | READ-ONLY diagnosis. No source modified. |
| Repo | `wp-alegra-connector/` @ `d7d1c12` (HEAD, version **2.5.1**, 2026-09-25) |
| Question | (A) Why does `edit-item` update the NAME but not the STOCK? (B) How should inventory sync bidirectionally when Alegra sales/invoices (not from the ecommerce) move stock? |
| Method | Code trace with `archivo:línea`; git history; release artifact inspection; test harness review; SDD review. |
| Legend | **VERIFIED** = read directly in code/history. **HYPOTHESIS** = inferred, needs live confirmation. |

> **Headline:** In the CURRENT code (2.5.1) the `edit-item` webhook path **DOES apply stock** — the handler re-fetches the item with `mode=advanced` and calls the same inventory applier the manual import uses. The "name updates, stock doesn't" symptom is the **historical** root cause fixed in **v2.3.5/2.3.6** (commit `ed4143d`), or one of four **residual config/type gates** that are still live. There is **NO inventory webhook** in Alegra's 12 events; Alegra-side sales reach WC stock **only** through the periodic poll (default 15 min), which is also the source of a real oversell/phantom-stock risk.

---

# PART A — Why the name updates but the stock does not

## A.1 Exact webhook chain (VERIFIED)

```
Alegra edit-item
  -> POST /wp-json/alegra-connector/v1/webhook
  -> Receiver::handle()                              includes/Webhooks/Receiver.php:60
       auth (:84) -> kill switch (:106) -> replay (:128) -> event selection (:173)
  -> Handlers::process_event('edit-item', $data)     includes/Webhooks/Handlers.php:26
  -> Handlers::handle_item_event($data)              includes/Webhooks/Handlers.php:60
       $alegra_id = (string) ($item['id'] ?? '')     Handlers.php:64
  -> Products::sync_single_item_by_alegra_id($id)    includes/Sync/Products.php:2630
       $item = $this->api->get_item($alegra_id)      Products.php:2632  (RE-FETCH)
  -> API\Client::get_item()                          includes/API/Client.php:344
       GET /items/{id}?mode=advanced                 Client.php:346
  -> Products::import_single_item_from_alegra($item) Products.php:1488
       existing product found by _alegra_item_id     Products.php:1546
  -> Products::update_product_from_alegra($p,$item)  Products.php:1943
       name   -> $product->set_name()                Products.php:1987-1989
       price  -> set_regular_price()                 Products.php:1974-1985
       status/visibility/description                 Products.php:1993-1999
       INVENTORY gate + applier                      Products.php:2006-2009
  -> Products::apply_inventory_to_product()          Products.php:2052
       set_manage_stock(true)                        Products.php:2072
       set_stock_quantity($qty)                      Products.php:2097
       set_stock_status(...)                         Products.php:2099
  -> $product->save()                                Products.php:2015
```

**Two facts that kill the "the webhook payload has no stock" hypothesis:**

1. The handler **re-fetches** the item from the API. It uses the webhook body only for `item.id` (`Handlers.php:62-65`); every field applied to the product comes from `GET /items/{id}?mode=advanced`. So it does not matter whether the `edit-item` payload carries `inventory` — **VERIFIED**.
2. `get_item()` explicitly requests `mode=advanced` (`Client.php:344-347`), which is the shape that includes `inventory.availableQuantity`. The simple shape strips it (documented in `Products.php:1201-1204` and `CHANGELOG.md:776`). **VERIFIED**.

The re-fetch is even asserted by the harness: `T20.2` checks `GET /items/865` is called once (`scripts/exec-test.php:3304`).

## A.2 Does `update_product_from_alegra()` apply stock? (VERIFIED — YES)

`Products.php:2001-2009`:

```php
// Inventory. The setting was ignored here before: ...
if ((string) get_option('alegra_connector_inventory_source', 'alegra') !== 'woocommerce'
    && !in_array('inventory', $preserve, true)) {
    $this->apply_inventory_to_product($product, $item);
}
```

`apply_inventory_to_product()` (`Products.php:2052-2100`) is the applier that fixes the original "no trae la existencia" report:

```php
if ($product->is_type('variable')) {           // 2058
    $product->set_manage_stock(false);         // 2059  parent never owns stock
    return;                                    // 2060
}
$inventory = $item['inventory'] ?? null;       // 2063
if (!is_array($inventory)) {                   // 2065  service
    $product->set_manage_stock(false);         // 2067
    return;                                    // 2068
}
$product->set_manage_stock(true);              // 2072  <-- the fix
$qty = $inventory['availableQuantity'] ?? null;// 2074
if ($qty === null || $qty === '' || !is_numeric($qty)) {
    $this->logger->warning('Skipping stock update: ...'); // 2078
    return;                                    // 2082
}
$qty = (int) $qty;                             // 2085
if ($qty < 0) { $qty = 0; ... }                // 2086-2095 clamp
$product->set_stock_quantity($qty);            // 2097
$product->set_stock_status($qty > 0 ? 'instock' : 'outofstock'); // 2099
```

There is **no early return before the inventory block** for a changed name or a "no changes" check. `update_product_from_alegra` runs price, name, status, description, inventory, sku, then `save()`. **VERIFIED.**

## A.3 Root cause of the reported symptom

### A.3.1 Historical (the actual original report) — VERIFIED by git

Before commit `ed4143d` (`fix(products): ... land stock in WC`, landed in **v2.3.5**, contained in tags ≥ v2.3.6), `update_product_from_alegra` wrote stock like this:

```php
// pre-2.3.5 (git show ed4143d -- includes/Sync/Products.php)
if (isset($item['inventory']['availableQuantity']) && $product->get_manage_stock()) {
    $product->set_stock_quantity((int) $item['inventory']['availableQuantity']);
}
```

The plugin **never set `_manage_stock`** anywhere, so `$product->get_manage_stock()` was always `false` for imported products. The condition short-circuited: **the name updated (line before), the stock was never written.** That is byte-for-byte the reported symptom. `CHANGELOG.md:489-494` calls this "the root cause of the original report".

This is the **most probable cause if the store runs a build < 2.3.5**. The fix added `set_manage_stock(true)` + `set_stock_status()` and moved the logic into `apply_inventory_to_product`.

### A.3.2 Residual live gates that reproduce the same symptom on 2.5.1

Even on 2.5.1, stock is silently skipped when any of these hold (all VERIFIED):

| # | Gate | Location | Effect |
|---|---|---|---|
| 1 | `alegra_connector_inventory_source === 'woocommerce'` | `Products.php:2006` (import), `Products.php:1157` (pull) | Name updates; stock never touched on any path. |
| 2 | `'inventory'` ∈ `alegra_connector_import_preserve_fields` | `Products.php:2007`, read at `1937-1941` | Name updates; inventory preserved by design. UI warns (`CHANGELOG.md:370`). |
| 3 | Product is a variable **parent** | `Products.php:2058-2060` | Parent stock untouched; stock lives on each variation (`T16.8`). |
| 4 | Alegra item is a **service** (no `inventory` object) | `Products.php:2065-2068` | `manage_stock=no`, stock untouched. |
| 5 | `availableQuantity` null/absent/non-numeric | `Products.php:2074-2083` | `manage_stock` enabled but quantity left as-is + WARN. |
| 6 | Running a build **< 2.3.5** | git `ed4143d` | `get_manage_stock()` gate — stock never written. |

Gate #2 (`preserve_fields` containing inventory) is the one that reproduces the symptom **exactly on current code**: name changes, stock deliberately preserved. Gate #1 makes stock never move at all.

## A.4 The fix

For the merchant (operational, no code change):

1. Confirm the running version is ≥ **2.3.6** (ideally 2.5.1). The `_manage_stock` fix is NOT present in 2.3.4 or earlier.
2. Set `alegra_connector_inventory_source = alegra` (Ajustes → Sincronización).
3. Ensure `alegra_connector_import_preserve_fields` does **not** contain `inventory`.
4. For an existing product whose stock never moved, re-import it once (the per-product "Traer" button or a manual import) so `_manage_stock` gets set; products imported before 2.3.5 have `_manage_stock=no` and the **pull will skip them forever** (`Products.php:1249-1251`).
5. For variable products, expect the stock on the **variations**, not the parent.

For the codebase, the durable fix is the SDD's `Inventory_Writer` single-writer design (see PART B.6); today two writers exist (`apply_inventory_to_product` on import + an inline block in the pull) and the pull cannot enable `_manage_stock`.

---

# PART B — Inventory architecture (bidirectional)

## B.1 Current model

### Source of truth
`alegra_connector_inventory_source` ∈ {`alegra` (default), `woocommerce`} — there is **no `both`** (`alegra-connector.php:445`, `templates/admin-settings.php:94`). VERIFIED.

- `alegra` → Alegra wins: both the import (`Products.php:2006`) and the pull (`Products.php:1157`) overwrite WC stock.
- `woocommerce` → the plugin never writes WC stock (import gate `2006`, pull gate `1157`).

### PULL (Alegra → WC stock)
- Entry points: cron `Controller::run_cron_sync_inner` (`includes/Sync/Controller.php:209-215`, gated on `inventory_source=alegra` **and** `inventory_sync_enabled=true`), the manual `alegra_sync_inventory_from_alegra` action (`Controller.php:65,110-115`), and an admin button (`Admin_Dashboard.php:1807`).
- Fetch: `GET /items?start&limit=30&mode=advanced` (`Products.php:1205-1209`).
- Apply: only if `inventory.availableQuantity` present (`1224`), the product is linked by `_alegra_item_id` (`1228`), and `$product->get_manage_stock()` is true (`1249-1251`). Writes inline at `1254-1260` (`set_stock_quantity` + `set_stock_status` + `save`).
- Guards: kill switch (`1149`), lock (`1163`), cancel transient (`1175`), per-run stop (`1182`).
- **No cursor/checkpoint.** Every run restarts at page 1; hard cap `max_pages = 200` (`1171`) = 6,000 items, and truncation is **not** reported (`result` has no `truncated`). VERIFIED.

### PUSH (WC → Alegra inventory)
- **Stock is never pushed.** `prepare_simple_product_data()` sends the `inventory` object **only when `$is_create`** (`Products.php:986-1014`); on update it omits `inventory` entirely (the R2/R3 hotfix, commit `7baa16a`). `apply_warehouse()` has the same `!$is_create` guard (`1126-1139`). `sync_simple_product()` passes `is_create=false` on update and calls `update_item` (`Products.php:221`).
- `API\Client::create_inventory_adjustment()` exists (`Client.php:648`) but is **not wired** to any sync path; the SDD explicitly leaves it manual (proposal §2 "Fuera de alcance"). VERIFIED.
- Product push hooks are off by default (`alegra_connector_push_products_enabled=false`, `alegra-connector.php:419`).

### Conflict resolution
`alegra_connector_conflict_resolution` (default `alegra_wins`) is consulted **only in `Sync/Customers.php:72`**. Products/inventory ignore it: when `inventory_source=alegra`, the import/pull unconditionally overwrite WC stock. VERIFIED.

### Diagram

```
        ALEGRA                                              WOOCOMMERCE
  ┌──────────────────┐                                 ┌────────────────────┐
  │ item stock        │  ── GET /items?mode=advanced ──► │ _stock             │
  │ (availableQuantity)│      cron / manual / WP-CLI     │ _manage_stock      │
  └────────┬──────────┘      Controller.php:209           │ _stock_status      │
           │                 Products::sync_inventory...  └─────────┬──────────┘
           │                 Products.php:1142                      │
           │                                                       │ WC sale
           │  edit-item webhook (MAY fire on stock change?)        │ (does NOT push
           │  ─► Receiver ─► Handlers::handle_item_event           │  stock to Alegra:
           │     ─► sync_single_item_by_alegra_id                  │  update omits
           │        ─► GET /items/{id}?mode=advanced               │  inventory)
           │        ─► update_product_from_alegra                  │
           │           ─► apply_inventory_to_product ◄──── import  │
           │                                                       ▼
           │  invoice webhooks (new/edit/delete-invoice)      WC stock decrements
           │  ─► only persist status on a LINKED WC order     (Alegra unaware)
           │     (Handlers.php:166-238) — NO stock pull
           │
           │  ⚠ NO inventory webhook exists.
           └───────────────────────────────────────────────────────────────►
                Alegra-side sale (POS/invoice) → WC finds out ONLY via the poll.
```

## B.2 The critical scenario: an Alegra-side sale moves stock — does WC find out?

**Only through the periodic poll.** VERIFIED, step by step:

1. **There is no inventory event.** The 12 events are `new/edit/delete` × `invoice|bill|client|item` (`Client.php:852-868`). No `edit-stock` / `inventory` event.
2. **`edit-item` on a stock-only change: UNVERIFIED.** Alegra's own behaviour is unknown to this repo — that is exactly why the developer shipped a read-only webhook inspector whose verdict is "Alegra SÍ/NO envía inventario en edit-item — la reconciliación debe ser por poll" (`Recorder.php:39-46`, admin screen `templates/admin-webhooks.php:60-89`). **HYPOTHESIS:** if `edit-item` does fire on a stock change, the handler re-fetches and applies stock → near-real-time; if it does **not** fire, the poll is the only path.
3. **Invoice webhooks do not pull stock.** `handle_invoice_event()` re-fetches the invoice, looks up a WC order by `_alegra_invoice_id`, persists the status and maybe completes the order (`Handlers.php:166-238`). No stock read/write. An Alegra sale with no matching WC order is invisible.
4. Therefore the **only guaranteed mechanism** for a non-ecommerce Alegra sale to reach WC stock is `sync_inventory_from_alegra()`.

### Latency
- Defaults: `sync_method = cron` (`alegra-connector.php:417`), `sync_frequency = 15` minutes (`alegra-connector.php:416`, UI `templates/admin-settings.php:76`). Intervals offered: 5/15/30/60 (`alegra-connector.php:260`).
- This is **WP-Cron**: it fires on the next request after the interval. On a low/no-traffic store the real latency is **unbounded** until a page view occurs.
- The pull must also find a linked WC product with `_manage_stock=yes` (`Products.php:1228,1249`), or the update is skipped.
- **Worst-case oversell window ≈ one poll interval (≥15 min, unbounded with WP-Cron).** During it, WC can sell stock Alegra already decremented.

### Conflict risk
Between two polls, if WC sells (decrementing WC stock) and Alegra has the pre-sale value, the next pull **overwrites WC back to Alegra's number** — re-inflating stock WC already sold. Because WC sales never push stock to Alegra, and default `push_orders_enabled=false` / `invoice_status=draft` (`alegra-connector.php:418`, `:441`), the WC sale never moves Alegra. This is the "phantom stock / doble conteo" divergence the SDD documents as REQ-DIV-4 (blocked). The only mitigation is enabling outbound invoicing so WC sales open Alegra invoices that move Alegra stock. VERIFIED.

## B.3 Event table

| Alegra-side action | Webhook fired? | Plugin updates WC stock? | Mechanism / latency |
|---|---|---|---|
| Item edited **including stock** | `edit-item` (fires on stock-only change: UNVERIFIED) | If it fires: **yes**, via re-fetch + `apply_inventory_to_product` | Webhook, seconds; otherwise poll ≤15 min |
| Item created | `new-item` | Yes (create path) | Webhook, seconds |
| Item deleted | `delete-item` | No (tombstone + unlink) | `Handlers.php:77-114` |
| **Sale / invoice in Alegra (POS, not ecommerce)** | `new-invoice` / `edit-invoice` | **No** — only persists status on a LINKED WC order | Poll only (≤15 min, unbounded) |
| Invoice paid / closed | `edit-invoice` | No stock; may complete the WC order | `Handlers.php:227-237` |
| Invoice voided | `edit-invoice` | No stock; adds a note (order NOT cancelled) | `Handlers.php:215-225` |
| Inventory adjustment / transfer in Alegra | none | No direct event | Poll only |
| WC sale | n/a (outbound) | WC decrements; **no push to Alegra** | never (update omits inventory) |
| WC product edit | n/a | Pushes name/price/etc.; **no stock** | `prepare_simple_product_data(...,false)` |

## B.4 Gaps

- **No real-time stock event.** `edit-item`-on-stock is unproven; there is no inventory webhook. Real-time depends on a poll.
- **No cursor / no resumable pull.** Re-scans from page 1 every run; caps at 6,000 items with no `truncated` signal (`Products.php:1171,1282`).
- **Two stock writers** (import applier `Products.php:2052` + pull inline `Products.php:1254-1260`). REQ-DIV-1 ("un solo escritor") is **not** met.
- **The pull cannot enable `_manage_stock`.** It skips any product where `get_manage_stock()` is false (`1249-1251`), unlike the import path. Products imported before v2.3.5 are permanently skipped by the pull.
- **Warehouses ignored on pull.** The pull reads total `inventory.availableQuantity` (`1238`), never `inventory.warehouses[].availableQuantity`; the push writes a warehouse on create (`apply_warehouse`). REQ-WH-1/4 not met.
- **No product conflict resolution.** `conflict_resolution` is customer-only.
- **No WC→Alegra stock push.** Guarantees the WC-sale re-inflation above.
- **No divergence report** for WC orders sold but never invoiced in Alegra (REQ-DIV-4, blocked).
- **`sync_products=false` default** but webhooks and the inventory pull are independent; the inventory pull is on by default (`alegra-connector.php:429,449`).

### What real-time bidirectional sync would require
1. **A live answer to the `edit-item`-on-stock question** (the shipped inspector already produces it). If NO: there is no event, so real-time must be built on a short-interval poll or Alegra's `POST /inventory-adjustments` / a webhook proxy.
2. **A reconciliation cursor** persisted between runs (`alegra_connector_inventory_pull_cursor`), with resumability and a `truncated` flag.
3. **Conflict resolution for stock** (who wins when both changed since the last checkpoint), honouring `conflict_resolution`.
4. **An outbound stock path** so WC sales move Alegra stock (open the invoice on payment, or `POST /inventory-adjustments`), otherwise the poll will always re-inflate.
5. **Single writer** (`Inventory_Writer`) shared by import and pull, with warehouse-aware quantity resolution.
6. **Idempotency** (already partly present via the re-entrancy guard and `Entity_Map`).

## B.5 SDD inventory (`docs/sdd/inventory/`) — IMPLEMENTED vs DESIGNED-ONLY

**Status: DESIGNED-ONLY.** All 10 phases in `tasks.md` are unchecked (`[ ]`), there is **no `Inventory_Writer.php`**, no `docs/sdd/inventory/phase0-results.md`, and Phase 0 (the live verifications that gate the design) was never completed. The proposal itself is marked "bloqueada por verificaciones en vivo (Fase 0)" (`proposal.md:7`). VERIFIED.

However, a **subset of the intent was implemented ad-hoc** (outside the SDD) by commits `ed4143d` (v2.3.5) and `e8466f4` (v2.4.1). Mapping:

| SDD requirement | Implemented? | Where |
|---|---|---|
| REQ-SRC-1 Alegra-wins pull is the writer | Partial (pull exists + wired; **not** the only writer) | `Products.php:1142`, `Controller.php:209` |
| REQ-SRC-2 WC-wins never touches stock | Yes | `Products.php:1157,2006` |
| REQ-GATE-1 kill switch in pull | Yes | `Products.php:1149` |
| REQ-GATE-2 lock + cancel | Yes | `Products.php:1163,1175,1182` |
| REQ-GATE-3 opt-in migration gates the write | **No** (`inventory_manage_stock_enabled` absent) | — |
| REQ-INV-1..4 invoice state / open-on-paid | **No** (`open_invoice_on_paid` absent) | — |
| REQ-WH-1/4 pull reads the push warehouse | **No** (pull reads total only) | `Products.php:1238` |
| REQ-WH-2/3 missing warehouse SKIP+WARN | **No** | — |
| REQ-TYPE-1..4 simple/variable/variation/service | Partial | `Products.php:2052-2100` |
| REQ-TYPE-5 mixed variations WARN | **No** | — |
| REQ-TYPE-6 no negatives/garbage | Yes (clamp + numeric guard) | `Products.php:2074-2095` |
| REQ-MIG-1..3 dry-run / opt-out / overwrite WARN | **No** | — |
| REQ-DIV-1 single writer | **No** (two writers) | `2052` + `1254` |
| REQ-DIV-2 import doesn't write stock directly | **No** (import is a writer) | `Products.php:2008` |
| REQ-DIV-3 push omits `inventory` on update | Yes (R2/R3 hotfix) | `Products.php:986-1014` |
| REQ-DIV-4 divergence report (manual mode) | **No** | — |
| REQ-DIV-5 push omits inventory if no manage_stock | Partial (omitted on all updates) | `Products.php:1005` |
| REQ-FAIL-1..3 null/negative/garbage | Yes | `Products.php:2074-2095` |
| REQ-FAIL-4..6 cursor/resume/truncation | **No** | — |
| REQ-DEL-1 deleted item | Yes (tombstone) | `Handlers.php:88` |
| REQ-WC-1 WC-only product never in Alegra | Partial | — |
| REQ-CN-1 credit note restores stock | **No** | — |

Roughly: **the "safety" requirements (source gate, kill switch, lock, null/negative handling, R2/R3 push hotfix) are implemented; the "architecture" requirements (single writer, migration opt-in, cursor, warehouse-aware, invoice-driven stock, conflict resolution, divergence reporting, credit notes) are designed-only.**

## B.6 The harness

- **Import path stock:** `T16.4`–`T16.12` (`scripts/exec-test.php:2126-2291`) assert `inventory_source=woocommerce` preserves stock, `alegra` lands `_manage_stock` + `_stock` + `_stock_status`, services disable manage_stock, null/absent never becomes 0, variations get stock, cron gating, kill switch, and negative clamping. These **lock the v2.3.5 fix** (they were written with it).
- **Webhook path:** `T20.2` (`exec-test.php:3287-3305`) asserts the handler **re-fetches** the item (`GET /items/865`). It does **not** assert stock, and it uses a `type='variant'` item, which `import_single_item_from_alegra` skips (`Products.php:1515-1517`).
- **Verdict:** the harness would catch a regression of the stock fix on the **import** path (T16.5). It would **not** catch a webhook-specific stock regression, because no test drives `Receiver → Handlers → sync_single_item_by_alegra_id → stock` end-to-end. Since the webhook shares `import_single_item_from_alegra`, the import tests give indirect coverage only.

## B.7 Exact live checks

Run these on the production site (all read-only). Use WP-CLI where possible.

**1. Version (is the `_manage_stock` fix even present?)**
```bash
wp plugin get alegra-connector --field=version
# Need >= 2.3.6. If <= 2.3.4, the pre-ed4143d get_manage_stock() gate is the cause.
```

**2. Configuration gates**
```bash
wp option get alegra_connector_inventory_source          # must be 'alegra'
wp option get alegra_connector_inventory_sync_enabled    # must be truthy
wp option get alegra_connector_sync_method               # 'cron' | 'both'
wp option get alegra_connector_sync_frequency            # 5|15|30|60
wp option get alegra_connector_import_preserve_fields --format=json  # must NOT contain "inventory"
```

**3. The product's actual WC stock (the ground truth)**
```bash
wp wc product get <PRODUCT_ID> --fields=id,name,type,manage_stock,stock_quantity,stock_status --user=1
# or SQL (products still live in postmeta, even on HPOS stores):
wp db query "SELECT post_id,meta_key,meta_value FROM wp_postmeta
  WHERE post_id=<PRODUCT_ID>
    AND meta_key IN ('_stock','_manage_stock','_stock_status','_alegra_item_id');"
# If _manage_stock != 'yes', that is the bug (gate #6 or the pull skip at Products.php:1249).
```

**4. What Alegra returns (the source value)**
```bash
wp eval '
  $l = new \Alegra\Connector\Logger\Logger();
  $c = new \Alegra\Connector\API\Client($l);
  $id = (string) get_post_meta(<PRODUCT_ID>, "_alegra_item_id", true);
  $it = $c->get_item($id);
  echo "alegra_id=$id\n";
  echo "availableQuantity=" . var_export($it["inventory"]["availableQuantity"] ?? null, true) . "\n";
  echo "type=" . ($it["type"] ?? "?") . "\n";
'
# If availableQuantity is null/absent -> Products.php:2074 SKIP+WARN. If type=variant -> parent.
```

**5. Logs (which branch was taken)**
Grep the plugin log for these exact strings:
- `Skipping stock update: Alegra returned no usable availableQuantity` → gate #5 (`Products.php:2078`).
- `Inventory pull skipped: WooCommerce is the configured inventory source` → gate #1.
- `Inventory updated from Alegra` → the pull worked.
- `Webhook: Item synced` with `result=updated` → the webhook ran.
- `Clamping negative Alegra stock to 0` → negative stock in Alegra.

**6. Webhook inspector — the `edit-item`-on-stock question**
```bash
# Admin: Alegra Connector -> Webhooks  (read the SÍ/NO verdict)
# or CLI (dev builds only; not shipped in the release ZIP):
wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --event=edit-item
```
Procedure: subscribe `edit-item`, note an item's stock, make an inventory adjustment (or void an invoice) in Alegra, then reload the inspector. If no `edit-item` arrives → stock changes are invisible to webhooks and the poll is the only path.

**7. Force the poll (does it fix the product?)**
```bash
wp eval 'do_action("alegra_sync_inventory_from_alegra");'
# then re-run check #3. If stock changes now but not from the webhook -> webhook firing/type issue.
```

**8. Cron is actually scheduled**
```bash
wp cron event list | grep alegra_connector_cron_sync
wp option get alegra_connector_sync_method
# Remember: WP-Cron only fires on traffic. Confirm with a real request or a system cron.
```

## B.8 Confidence

| Finding | Confidence |
|---|---|
| Current `edit-item` path re-fetches with `mode=advanced` and calls the stock applier | **HIGH — VERIFIED** (`Handlers.php:64-68`, `Products.php:2630-2636`, `Client.php:344-347`, `Products.php:2006-2009`) |
| `apply_inventory_to_product` sets `_manage_stock`/`_stock`/`_stock_status` on the webhook path | **HIGH — VERIFIED** (`Products.php:2072,2097,2099`, save at `2015`) |
| Pre-2.3.5 `get_manage_stock()` gate is the original "name but no stock" root cause | **HIGH — VERIFIED** (`git show ed4143d`, `CHANGELOG.md:489-494`) |
| The user's *current* cause is one of the 6 gates | **MEDIUM — needs live checks B.7.1/2/3** |
| No inventory webhook exists; only poll covers Alegra-side sales | **HIGH — VERIFIED** (`Client.php:852-868`, `Handlers.php:166-238`) |
| `edit-item` fires on a stock-only change | **LOW — UNVERIFIED** (repo ships an inspector precisely because it is unknown) |
| WC→Alegra stock push never happens on update | **HIGH — VERIFIED** (`Products.php:986-1014,1126-1139,221`) |
| Default latency ≈15 min, unbounded under WP-Cron | **HIGH — VERIFIED** (`alegra-connector.php:416-417`, WP-Cron semantics) |
| Poll re-inflates WC stock after a WC sale (phantom stock) | **HIGH — VERIFIED** (no push + unconditional overwrite at `Products.php:1238-1260`) |
| Inventory SDD is designed-only; `Inventory_Writer` absent | **HIGH — VERIFIED** (all tasks `[ ]`, no file, no `phase0-results.md`) |
| Harness lacks an end-to-end webhook stock test | **HIGH — VERIFIED** (`T20.2` uses a skipped `variant` item; no stock assertion) |
