# ANALYSIS — Invoice upload failure handling (READ-ONLY)

**Change**: `sync-reliability` follow-up — invoice failure notifications + pending/failed list + retry
**Plugin**: `wp-alegra-connector` (HEAD after v2.6.0)
**Date**: 2026-09-25
**Scope**: read-only analysis of the invoice-upload failure paths. No source file was modified. This document is the only artifact written.

## Evidence convention

- `[VERIFIED: file:line]` — confirmed by reading the file in this session.
- `[HYPOTHESIS]` — reasoned from code/doc knowledge, not directly observable in this repo (e.g. Alegra server behaviour).
- Citations use paths relative to `wp-alegra-connector/` unless absolute.

## Merchant requirement (verbatim)

> "si se hace la factura y se intenta subir a alegra automatica o manualmente y no hay existencias o se produce algun problema deberia de emitirse la notificacion si se hace manualmente o si se hace automaticamente pues deberia quedar como notificacion en el listado de pedidos o facturas por subir y ya luego de que se resuelva el problema subir la factura o pedido manualmente."

Decomposed:
1. **Manual attempt fails** → emit a notification to the operator.
2. **Automatic attempt fails** → leave the order/invoice in a persistent "pending to upload" list.
3. **After the problem is fixed** → retry the invoice/order manually from that list.

---

## 1. The complete failure taxonomy

All outbound writes funnel through `API\Client::request()` `[VERIFIED: includes/API/Client.php:101]`. The order of the choke point is **dry-run → write gate → throttle → network** `[VERIFIED: includes/API/Client.php:103-145]`.

### 1.1 Client-level failures (HTTP / transport)

| # | Failure | Exact code path | Error shape | Retriable? |
|---|---------|-----------------|-------------|------------|
| F1 | Network / DNS / connection / cURL error / timeout | `wp_remote_request()` returns `WP_Error`; retried while `is_retryable_wp_error()` `[VERIFIED: includes/API/Client.php:182-196, 308-313]` | raw `WP_Error('http_request_failed', ...)` (or `http_request_timeout`) | **YES** — 4 retries, 1/2/4/8 s `[VERIFIED: Client.php:184]` |
| F2 | Alegra 429 Too Many Requests | `($code >= 500 \|\| $code === 429)` → sleep + retry `[VERIFIED: Client.php:211-224]` | after retries: `WP_Error('api_error', msg, ['code'=>429,'response'=>body])` `[VERIFIED: Client.php:242]` | **YES** |
| F3 | Alegra 5xx (500/501/502/503/504/…) | same branch as F2 | `WP_Error('api_error', ..., ['code'=>5xx, ...])` | **YES** |
| F4 | Alegra 400 Bad Request | falls through to generic `if ($code >= 400)` `[VERIFIED: Client.php:227-242]` | `WP_Error('api_error', msg, ['code'=>400,'response'=>body])` | **NO** (permanent) — except the CF dead-client self-heal (see F13) |
| F5 | Alegra 401 Unauthorized | generic 4xx | `WP_Error('api_error', ..., code 401)` | **NO** — needs credentials fix |
| F6 | Alegra 403 Forbidden | generic 4xx | code 403 | **NO** |
| F7 | Alegra 404 Not Found | generic 4xx | code 404 | **NO** |
| F8 | Alegra 409 Conflict | generic 4xx | code 409 | **NO** (duplicate number / state conflict) |
| F9 | Alegra 422 Unprocessable (validation) | generic 4xx; the full `errors[]` body is preserved under `response` | code 422 | **NO** until data is fixed |
| F10 | Empty / non-JSON 2xx body (incl. 204) | `json_decode` guard `[VERIFIED: Client.php:245-249]` | `WP_Error('json_error', 'Invalid JSON response from API')` | **YES/UNKNOWN** — the write may have landed; recover by pre-search (see §4.7) |
| F11 | Local rate limiter hard-reached | `throttle()` sleeps ≤30 s then `request()` returns `WP_Error` `[VERIFIED: Client.php:136-145, 1014-1034]` | `WP_Error('rate_limited', ...)` | **YES** |
| F12 | Local JSON encode failure | `[VERIFIED: Client.php:162-163]` | `WP_Error('json_error', 'Failed to encode request data')` | **NO** (programmer/data error) |
| F13 | 400 caused by a dead Consumidor Final client id | `try_self_heal_dead_client()` — only fires on 400 + client mention + CF id + once `[VERIFIED: includes/Sync/Orders.php:351-430]` | re-resolves CF and retries once; on failure returns original `WP_Error` | **YES after CF fixed** |
| F14 | Per-order invoice lock held | `Controller::acquire_lock('alegra_invoice_lock_'.$id, 30)` fails `[VERIFIED: Orders.php:66-79]` | `WP_Error('invoice_in_progress', ...)` | **YES** (contention, retry shortly) |

Rate-limiter constants: 150 req/min, fixed 60 s window `[VERIFIED: Client.php:954-956]`. Alegra's own documented limit is also 150 req/min returning 429 `Too Many request` with `X-Rate-Limit-Limit/Remaining/Reset` headers `[VERIFIED: developer.alegra.com/reference/límite-de-request]`; the client honours those headers `[VERIFIED: Client.php:990-1008]`.

### 1.2 Config-level "blocks" (not errors)

These return a **plain array marker**, never a `WP_Error` `[VERIFIED: Client.php:103-105, 126-131]`:

| # | Block | Code path | Shape | Auto-retry? |
|---|-------|-----------|-------|-------------|
| B1 | Kill switch active | `Kill_Switch::is_active()` → `block_reason() === 'kill_switch'` `[VERIFIED: includes/Write_Gate.php:116-118]` | `['blocked_by_gate'=>true,'reason'=>'kill_switch',...]` | **NO** — config fix |
| B2 | Entity disabled (`push_orders_enabled=false`) | `block_reason() === 'entity_disabled'` `[VERIFIED: Write_Gate.php:129-130]` | `['blocked_by_gate'=>true,'reason'=>'entity_disabled',...]` | **NO** — config fix |
| B3 | Automatic write to a switch-less entity | `block_reason() === 'not_explicit'` `[VERIFIED: Write_Gate.php:124-127]` | `['blocked_by_gate'=>true,'reason'=>'not_explicit',...]` | **NO** |
| B4 | Dry-run mode | `get_option('alegra_connector_dry_run')` `[VERIFIED: Client.php:109-114]` | `['dry_run'=>true,...]` | **NO** — intentional no-op |

Callers must use `Client::write_was_blocked()` (dry-run OR gate) instead of `is_wp_error()` `[VERIFIED: Client.php:300-303]`; a marker is an array so `is_wp_error()` is `false` and a naive caller treats it as success `[VERIFIED: Client.php:263-267, 281-282]`.

### 1.3 Payload / data failures (before the API call)

`prepare_invoice_data()` returns `WP_Error` and `create_invoice()` passes it through `[VERIFIED: Orders.php:128-131]`:

| # | Failure | Code path | Retriable? |
|---|---------|-----------|------------|
| D1 | `customer_unresolved` | `[VERIFIED: Orders.php:1229-1232]` | **NO** until billing/contact fixed |
| D2 | `invoice_item_unlinked` | `[VERIFIED: Orders.php:1669]` | **NO** until product mapping fixed |
| D3 | `invoice_shipping_unlinked` | `[VERIFIED: Orders.php:1768]` | **NO** |
| D4 | `invoice_fee_unlinked` | `[VERIFIED: Orders.php:1821]` | **NO** |

### 1.4 No stock / negative stock

- **The plugin has ZERO stock-specific error handling on the invoice path.** A repo-wide search for `insuficiente|existencia|sin stock|negative|availableQuantity` finds only the *inbound* clamp in `Inventory_Writer` (Alegra→WC, clamps negative `availableQuantity` to 0), never invoice-error mapping `[VERIFIED: includes/Sync/Inventory_Writer.php; grep]`. `Orders.php` references stock only in comments about `owner=invoice` `[VERIFIED: Orders.php:87, 120-121]`.
- Alegra's public error table documents 400/401/402/403/404/405/500/503 (plus 429 for the rate limit) but **does not define a stock-specific error code** `[VERIFIED: developer.alegra.com/reference/respuestas-del-api-y-manejo-de-errores]`. When "allow negative inventory" is disabled, an invoice for an out-of-stock item is expected to be rejected as a **400/422 validation error** (`response.errors` on the item/warehouse) `[HYPOTHESIS]`. With negative inventory allowed, Alegra accepts it and stock simply goes negative `[HYPOTHESIS]`.
- Consequence: a stock rejection is indistinguishable from any other 4xx and is therefore **permanent** in the taxonomy above. The design below classifies all 4xx except 429 as non-retriable, which is the correct safe behaviour for stock errors (manual retry after restocking).

### 1.5 Side-failure: invoice created but payment not recorded

`create_invoice_with_payment()` calls `record_payment_for_invoice()` and **discards its return value** `[VERIFIED: Orders.php:525-526]`, so a payment POST failure does **not** fail the method; the only record is an order note + logger error `[VERIFIED: Orders.php:632-637]`. This is a separate class ("invoice ok, payment missing") and is partially covered by the hourly payment reconcile sweep (`reconcile_missing_payments`) `[VERIFIED: Orders.php:754+; Controller.php:69-104]`.

---

## 2. What happens TODAY for each failure

### 2.1 Automatic path (`woocommerce_*` hooks → `Public_::trigger_sync`)

Hooks are gated by `alegra_connector_push_orders_enabled` `[VERIFIED: public/Public/Public_.php:49-59]`. `trigger_sync` acquires `alegra_sync_guard_{type}_{id}` (TTL 30) and calls `sync_entity` `[VERIFIED: Public_.php:373-401]`. On failure `[VERIFIED: Public_.php:406-422]`:

```php
if (is_wp_error($result) && $type === 'order') {
    $order->add_order_note(sprintf(
        __('Alegra: no se pudo sincronizar el pedido con Alegra (%s). Revisá la configuración y volvé a intentarlo desde el panel.', ...),
        $result->get_error_message()
    ));
    $this->logger->error('Automatic order sync failed', [...]);
}
```

| Aspect | Today |
|---|---|
| Notification | Order note + log file only. **No email, no admin notice** `[VERIFIED: grep wp_mail = 0 production hits]` |
| Persistent state | **None.** No failure meta, no transient, no DB row, no attempt counter `[VERIFIED: grep `_alegra_*error*` = 0]` |
| Order status | Unchanged |
| Retriable | Only by a future order-status transition or a manual button; **no scheduled retry** |
| Appears in a list | No |
| Blocked writes (B1–B4) | **Silent** — `is_wp_error()` is false for the array marker, so no note and no order-level log `[VERIFIED: Public_.php:406]` |

### 2.2 Manual single (`ajax_sync_single`)

`Write_Gate::run_explicit` wrapper `[VERIFIED: Admin_Dashboard.php:3166-3168]` → `sync_entity('order', id, 'complete')` `[VERIFIED: Admin_Dashboard.php:3194]`.

| Aspect | Today |
|---|---|
| Notification | `wp_send_json_error(['message' => 'Error de Alegra: ...'])` toast `[VERIFIED: Admin_Dashboard.php:3198-3200]`; blocked → `blocked_message()` `[VERIFIED: :3202-3208]` |
| Persistent state | **None** |
| Retriable | Yes, by pressing the button again (idempotent — see §4.3) |
| Appears in a list | No |

### 2.3 Manual bulk — "Facturar seleccionados" (`alegra_bulk_sync`)

Loops all selected ids in one request; counts `synced/errors/blocked` `[VERIFIED: Admin_Dashboard.php:3305-3346]`. Failures are only a **count**; no per-order detail, no persistence.

### 2.4 Manual bulk — "Facturar pendientes" (chunked)

`alegra_sync_pending_start` collects up to 100 orders in `processing|completed|on-hold` with empty `_alegra_invoice_id` `[VERIFIED: Admin_Dashboard.php:3872-3900]`; state lives in the **`alegra_pending_invoice_batch`** transient (TTL 600), **not** `alegra_batch_state` (that transient is the import flow only) `[VERIFIED: Admin_Dashboard.php:3898, 3916, 3966]`. `alegra_sync_pending_page` processes 10/request `[VERIFIED: :3936]` and increments counters `[VERIFIED: :3942-3951]`.

| Aspect | Today |
|---|---|
| Notification | Final toast `'%d/%d facturas — %d ok, %d errores'` `[VERIFIED: :3977; admin.js:838,843]` |
| Persistent state | Transient only; **deleted on completion** `[VERIFIED: :3964]` |
| Per-order reason | **Not retained** |
| Retriable | Yes, but a failed order silently falls out of the pending set only if it has an invoice id; failures are not re-queued |
| Appears in a list | No |

### 2.5 Dashboard divergence row

`get_unjournaled_sales()` returns `processing|completed` orders with `meta_query` `_alegra_invoice_id NOT EXISTS` `[VERIFIED: Admin_Dashboard.php:862-887]`; rendered as a **count-only** health row, and only when `push_orders_enabled=false` `[VERIFIED: :901-905; templates/admin-dashboard.php:197-212]`. It is not a retry surface and does not show *why* anything failed.

### 2.6 Monitor / Runs

The Monitor screen exists `[VERIFIED: Admin_Dashboard.php:217-224, 952-958; templates/admin-monitor.php]` and reads `wp_alegra_runs` via `Runs::recent()/currently_running()` `[VERIFIED: Runs.php:158-203; Admin_Dashboard.php:4144-4234]`. **Invoice pushes create no run row**, so invoice failures never appear there `[VERIFIED: `push_invoice` appears only in a Runs.php docblock; no `Run_Context` call on the invoice path]`.

### 2.7 Summary table

| Failure | Retriable? | Auto today | Manual single today | Manual bulk today | Persisted? |
|---|---|---|---|---|---|
| F1 network/timeout | YES | note+log, no retry | toast | count | No |
| F2 429 | YES | note+log | toast | count | No |
| F3 5xx | YES | note+log | toast | count | No |
| F4 400 | NO | note+log | toast | count | No |
| F5 401 | NO | note+log | toast | count | No |
| F6 403 | NO | note+log | toast | count | No |
| F7 404 | NO | note+log | toast | count | No |
| F8 409 | NO | note+log | toast | count | No |
| F9 422 | NO | note+log | toast | count | No |
| F10 json/empty 2xx | YES/unknown | note+log | toast | count | No |
| F11 local rate limit | YES | note+log | toast | count | No |
| F12 encode | NO | note+log | toast | count | No |
| F13 CF 400 self-heal | YES after fix | one retry then note+log | one retry then toast | count | No |
| F14 lock held | YES | silent skip (guard) | toast `invoice_in_progress` | count | No |
| D1–D4 data | NO | note+log | toast | count | No |
| Stock rejection | NO (4xx) | note+log | toast | count | No |
| B1–B4 blocked | NO | **silent** | toast `blocked_message` | count | No |
| Payment side-failure | YES | note+log | note only | count | note |

**Every path loses the failure when the request ends.** The only durable traces are WC order notes and the log file.

---

## 3. Current gaps

1. **No persistent failed/pending state.** There is no meta to query a failed order later; "pending" is inferred solely from the *absence* of `_alegra_invoice_id` `[VERIFIED: Admin_Dashboard.php:870, 3886]`, which cannot distinguish "never attempted" from "failed for a reason" or "blocked".
2. **No per-order failure reason.** The AJAX responses discard `$result->get_error_message()` and `$result->get_error_data()['code']`; the chunked flow only increments `errors` `[VERIFIED: Admin_Dashboard.php:3942-3951]`.
3. **No list/screen.** No admin surface lists failed uploads; the orders page only has a boolean "Pendiente" badge based on missing id.
4. **No automatic retry.** `run_cron_sync_inner` only polls invoice statuses inbound `[VERIFIED: Controller.php:298-305]`; the only outbound cron sweep is payments `[VERIFIED: Controller.php:69-104]`. Failed invoices are never retried by cron.
5. **No notification on automatic failure.** Order notes are not notifications; there is no admin notice, email, or badge for invoice failures. `State_Sync::render_admin_notices` exists but only payment-method messages are queued `[VERIFIED: State_Sync.php:473-504]`.
6. **Blocked automatic writes are invisible** at the order level `[VERIFIED: Public_.php:406]`.
7. **Duplicate risk within a single request.** The client retries POST `/invoices` on timeout/5xx with no idempotency key `[VERIFIED: Client.php:177-255]`; the AC-14 pre-search runs only once before the first POST `[VERIFIED: Orders.php:133-153]`, so a POST that commits server-side then errors can be retried into a duplicate `[HYPOTHESIS — direct consequence of the verified retry loop]`.
8. **`items_failed` in `wp_alegra_runs` is dead data** — declared `[VERIFIED: Schema.php:185]`, read by the Monitor `[VERIFIED: Admin_Dashboard.php:4193, 4209]`, never written by production code `[VERIFIED: grep]`.
9. **`Runs::track()` has no production callers** (only a comment and tests); runs go through `Run_Context` `[VERIFIED: Run_Context.php:9]`.

---

## 4. Proposed design

### 4.1 State machine + meta (the durable layer)

Add a per-order failure ledger. The invoice id remains the source of truth for success; these keys describe *why not yet*.

**Meta keys (all on the WC order, written via CRUD `update_meta_data()` so HPOS works):**

| Meta key | Type | Meaning |
|---|---|---|
| `_alegra_invoice_sync_state` | enum string | `idle` \| `pending` \| `failed_retriable` \| `failed_permanent` \| `blocked` \| `resolved` |
| `_alegra_invoice_error_code` | string | HTTP status (`400`…`503`) or symbolic (`network`, `rate_limited`, `lock`, `json`, `blocked:kill_switch`, `blocked:entity_disabled`, `data:customer_unresolved`, …) |
| `_alegra_invoice_error_message` | string | Sanitized, truncated (≤500) human message |
| `_alegra_invoice_error_retriable` | `'1'`/`'0'` | Cached classification (avoids re-deriving in SQL) |
| `_alegra_invoice_attempts` | int | Total attempts (incremented on each terminal failure) |
| `_alegra_invoice_last_attempt` | datetime (GMT) | Last terminal failure time |
| `_alegra_invoice_next_retry` | datetime (GMT) | Earliest next auto-retry (backoff); empty = none |

`_alegra_invoice_id` (existing) is untouched and stays the success marker. On success, `persist_invoice_result()` clears the ledger and sets `_alegra_invoice_sync_state = resolved` `[reuse: Orders.php:203-229]`.

**State transitions:**

```
idle ──attempt──▶ pending ──success──▶ resolved
                    │
                    ├─ retriable error (F1,F2,F3,F10,F11,F13,F14) ─▶ failed_retriable
                    ├─ permanent error (F4–F9, D1–D4, stock)      ─▶ failed_permanent
                    └─ block (B1–B4)                              ─▶ blocked
failed_retriable ──cron/manual retry──▶ pending …
failed_retriable ──attempts >= max──▶ failed_permanent
failed_* / blocked ──manual "Reintentar"──▶ pending …
any ──success──▶ resolved
```

`never-attempted` is not a stored state: it is `processing|completed` + no `_alegra_invoice_id` + no ledger (the existing `get_unjournaled_sales` set). The screen shows it as `idle`.

### 4.2 The screen — "Facturas por subir"

**Where:** a new submenu `alegra-connector-invoice-queue` ("Facturas por subir") in `add_admin_menu()` `[pattern: Admin_Dashboard.php:106-234]`, plus a count badge on the submenu title. Rationale: the merchant explicitly asked for a *listado*; a dedicated screen keeps the Orders page clean and mirrors the existing Monitor page structure.

**Query:** one `wc_get_orders()` with a `meta_query` over `_alegra_invoice_sync_state IN (failed_retriable, failed_permanent, blocked)` OR the never-attempted set (`_alegra_invoice_id NOT EXISTS` and status in `processing|completed|on-hold`). Support `paged` + `limit`.

**Columns:**

| Column | Source |
|---|---|
| Order | `#<id>` link to the order edit screen |
| Date | `get_date_created()` |
| Customer | billing name |
| Total | `get_total()` |
| Alegra state | `Invoice_Status` badge for `_alegra_invoice_status`, else the sync-state badge |
| Failure reason | `_alegra_invoice_error_message` (short) |
| Code | `_alegra_invoice_error_code` |
| Attempts | `_alegra_invoice_attempts` |
| Last attempt | `_alegra_invoice_last_attempt` |
| Next retry | `_alegra_invoice_next_retry` |
| Actions | `Reintentar` (single), `Ver log`, `Ignorar` |

**Filters:** sync-state (retriable / permanent / blocked / never-attempted), date range, search. **Bulk action:** "Reintentar seleccionados" (chunked, see §4.3).

### 4.3 Retry action (single + bulk) and idempotency

**Single:** new AJAX `alegra_retry_invoice` wrapped in `Write_Gate::run_explicit` (a merchant action must bypass `entity_disabled`, exactly like `ajax_sync_single`) `[pattern: Admin_Dashboard.php:3166-3168]`. It calls `Sync\Orders::create_invoice_with_payment($order)` and updates the ledger from the result.

**Bulk:** extend the existing chunked `alegra_pending_invoice_start/page` flow with an optional `scope=failed` parameter that adds `meta_query` on `_alegra_invoice_sync_state IN (failed_retriable, failed_permanent, blocked)`. Reuses the 10-per-request loop and the admin.js modal `[reuse: Admin_Dashboard.php:3867-3979; admin.js:778-853]`. Do **not** touch `alegra_batch_state` (imports only).

**Idempotency (no duplicate invoice on retry):** already guaranteed by the existing guards, which run on every call:
1. `_alegra_invoice_id` short-circuit `[VERIFIED: Orders.php:82-107]`.
2. `find_open_invoice_for_order()` when `owner=invoice` `[VERIFIED: Orders.php:111-117]`.
3. AC-14 pre-search by the `Pedido WooCommerce #<id>` observations marker before the POST `[VERIFIED: Orders.php:133-153, 309-340]`.
4. Per-order mutex `alegra_invoice_lock_{id}` (TTL 30) `[VERIFIED: Orders.php:66-79]`.

The retry therefore never creates a second invoice. **Additional hardening (§4.7)** closes the within-request duplicate window.

### 4.4 Automatic retry (cron sweep)

**New hook:** `alegra_connector_invoice_retry`, hourly (mirror `alegra_connector_payment_reconcile` `[pattern: Controller.php:69-104; alegra-connector.php:667-675]`).

**Logic:**
1. Global lock `alegra_invoice_retry` (TTL 300) + `register_shutdown_function` release (mirror D7) `[pattern: Controller.php:154-166]`.
2. `Run_Context::wrap('invoice_retry', ...)` so the sweep appears in the Monitor `[reuse: Run_Context.php:81-92]`.
3. Kill-switch check; if active, exit.
4. Select up to `batch` orders with `_alegra_invoice_sync_state = failed_retriable` AND (`_alegra_invoice_next_retry` empty OR ≤ now) AND `_alegra_invoice_attempts < max`.
5. For each: call `create_invoice_with_payment()` in **automatic** context (gate applies; if blocked → mark `blocked`, do not loop).
6. On success → `persist_invoice_result()` clears the ledger.
7. On retriable failure → attempts++, `next_retry = now + backoff(attempts)` (e.g. 5 min, 15 min, 1 h, 6 h, 24 h). On attempts ≥ max → `failed_permanent`.
8. On permanent failure → `failed_permanent` (never auto-retried).

**Which failures auto-retry:** only `failed_retriable` (F1, F2, F3, F10, F11, F13, F14). Permanent (F4–F9, D1–D4, stock) and `blocked` are **manual-only**, so a stock rejection cannot loop forever.

**Options:**
- `alegra_connector_invoice_retry_enabled` (bool, default **false** — opt-in; auto-creating invoices is money-sensitive).
- `alegra_connector_invoice_retry_max_attempts` (int, default 5).
- `alegra_connector_invoice_retry_batch` (int, default 20).

### 4.5 Notification logic

1. **Failure ledger write** happens in one place: a new private helper `Sync\Orders::persist_invoice_failure($order, $code, $message, $retriable, $reason)` called from the `create_invoice()` error branch `[target: Orders.php:157-172]` and from the block detection in callers. This is the single source of the screen data.
2. **Admin notice:** make the existing `State_Sync::queue_admin_notice()` reusable (add a public `queue_invoice_failure_notice()` or extract a small `Admin_Notices` helper). Render via the existing `admin_notices` hook `[reuse: State_Sync.php:62, 488-504]`. Because invoice failures can be written by cron (no user request), store the notice state in an **option** (e.g. `alegra_connector_invoice_failures_dirty` = count + last-hash), not the 60 s transient — mirror the option-backed `alegra_connector_logger_write_failed` pattern `[reuse: logger/Logger/Logger.php:224-241]`. Notice text: *"N facturas no se pudieron subir a Alegra. Ver lista"* + link; `notice-warning is-dismissible`; dismissing per-user stops it until the count changes.
3. **Menu badge:** a bubble count on the "Facturas por subir" submenu, computed from a cached count option updated on failure/resolve (avoid a `wc_get_orders` on every admin page load).
4. **Email:** out of scope for v1 (opt-in later) — the requirement says "notificación", satisfied by the admin notice + list. Document as a follow-up.
5. **Manual path:** the AJAX already toasts; additionally the ledger is written so the order lands in the list. **Automatic path:** no toast is possible (no request), so the notice + list + badge are the notification.

### 4.6 Failure classification helper

A single pure function maps a result to `(state, code, retriable)`:

```
is_wp_error:
  code 'rate_limited' / 'http_request_failed' / 'http_request_timeout' / 'json_error'  -> failed_retriable
  code 'invoice_in_progress'                                                            -> failed_retriable
  code 'api_error': HTTP 429 or >=500                                                   -> failed_retriable
                    HTTP 4xx (400,401,403,404,409,422)                                   -> failed_permanent
  code 'customer_unresolved' | 'invoice_item_unlinked' | 'invoice_shipping_unlinked'
       | 'invoice_fee_unlinked'                                                          -> failed_permanent
write_was_blocked(result):
  dry_run                                                                                -> do NOT persist (intentional)
  blocked_by_gate                                                                        -> blocked (reason)
```

This is the only place the retriable/permanent decision lives, so cron and UI agree.

### 4.7 Close the within-request duplicate window (targeted hardening)

Before persisting a **retriable/unknown** failure in `create_invoice()` (F1/F3/F10 — where the server may have committed), run `find_existing_invoice($order, $client_id)` once more. If found, adopt it (`persist_invoice_result`) and return success instead of a failure. This reuses the AC-14 mechanism `[reuse: Orders.php:309-340]` and prevents the client's internal POST retry from leaving a real invoice while the order is marked failed. Cheap (one GET) and only on the error path.

---

## 5. What to reuse vs build

### Reuse (no new mechanism)
| Asset | Where | Use |
|---|---|---|
| `_alegra_invoice_id` idempotency guard | `Orders.php:82-107` | success marker; retry short-circuit |
| `find_existing_invoice()` (AC-14) | `Orders.php:309-340` | retry recovery + §4.7 hardening |
| `create_invoice()` / `create_invoice_with_payment()` | `Orders.php:63, 494` | the retry calls these unchanged |
| Per-order lock | `Orders.php:66-79`; `Controller::acquire_lock` `Controller.php:440-459` | concurrency safety on retry |
| `persist_invoice_result()` | `Orders.php:203-229` | clear ledger on success |
| `alegra_pending_invoice_batch` chunked flow + admin.js modal | `Admin_Dashboard.php:3867-3979`; `admin.js:778-853` | bulk retry (add a `scope` filter) |
| `Write_Gate::run_explicit` | `Write_Gate.php:156-164` | manual retry must bypass `entity_disabled` |
| `State_Sync::render_admin_notices` + `queue_admin_notice` | `State_Sync.php:62, 473-504` | notification rendering (expose the queue) |
| `Runs` / `Run_Context` / `Heartbeat` + Monitor | `Runs.php`, `Run_Context.php`, `templates/admin-monitor.php` | run row + visibility for the cron sweep |
| `reconcile_missing_payments` sweep pattern + global lock | `Orders.php:754+`; `Controller.php:69-104` | template for the invoice retry cron |
| `get_unjournaled_sales()` + dashboard divergence row | `Admin_Dashboard.php:862-887`; `templates/admin-dashboard.php:197-212` | reuse the never-attempted query; add a link to the new screen |
| `Invoice_Status` badges | `Invoice_Status.php` | status rendering |
| `add_admin_menu` submenu pattern | `Admin_Dashboard.php:106-234` | new "Facturas por subir" page |

### Build (new)
| Item | Notes |
|---|---|
| Ledger meta keys (§4.1) + `persist_invoice_failure()` / `clear_invoice_failure()` | in `Sync\Orders` |
| `classify_invoice_failure()` (§4.6) | pure, unit-testable |
| Failure persistence calls in `create_invoice()` + block detection in callers | ~3 call sites |
| New submenu + `templates/admin-invoice-queue.php` + row query | mirrors orders/monitor templates |
| AJAX `alegra_retry_invoice` (single) + `scope` in pending start/page (bulk) | reuses the chunk loop |
| Cron `alegra_connector_invoice_retry` + schedule + options | mirrors payment reconcile |
| Option-backed admin notice + menu badge count | mirrors logger-write-failure notice |
| §4.7 post-error reconciliation call | one added `find_existing_invoice` on the error path |
| Tests (harness `scripts/`) | see acceptance |

---

## 6. Acceptance criteria

- **AC-1 (persistence)** Every terminal invoice-upload failure writes `_alegra_invoice_sync_state`, `_alegra_invoice_error_code`, `_alegra_invoice_error_message`, `_alegra_invoice_attempts`, `_alegra_invoice_last_attempt`; success clears them and sets `resolved`.
- **AC-2 (classification)** `429`/`5xx`/network/`rate_limited`/`json_error`/lock → `failed_retriable`; all other 4xx, data errors, and stock rejections → `failed_permanent`; gate/dry-run → `blocked` (dry-run not persisted).
- **AC-3 (screen)** "Facturas por subir" lists failed (retriable + permanent), blocked, and never-attempted orders with order/date/customer/total/reason/code/attempts/last/next and filters; row count matches a `wc_get_orders` meta query.
- **AC-4 (manual single retry)** "Reintentar" calls `create_invoice_with_payment` under explicit context, updates the ledger, toasts the outcome, and never creates a duplicate when `_alegra_invoice_id` already exists.
- **AC-5 (bulk retry)** "Reintentar seleccionados" processes in chunks of 10 via the existing transient flow with a `scope=failed` filter; per-order ledger is updated.
- **AC-6 (auto-retry)** With `invoice_retry_enabled`, the hourly cron retries only `failed_retriable` with exponential backoff and stops at `max_attempts` (→ `failed_permanent`). Permanent/blocked are never auto-retried.
- **AC-7 (idempotency)** A retry after a timeout/5xx whose first POST actually committed adopts the existing invoice via `find_existing_invoice` and does **not** create a second one (test with the Alegra mock: fail after commit, assert one invoice).
- **AC-8 (notification)** A failed upload produces a dismissible admin notice with the count and a link; the submenu shows a badge; the notice clears when the count reaches zero.
- **AC-9 (observability)** The retry sweep appears in the Monitor as a `invoice_retry` run with items done/total and `error_summary`.
- **AC-10 (no regression)** Existing v2.6.0 behaviour (inventory writer/pusher, CF self-heal, poll budget, locks) is unchanged; the full harness passes with 0 failures.

---

## 7. Effort estimate

| Workstream | Estimate |
|---|---|
| Ledger meta + classifier + persistence hooks + §4.7 hardening | 1.0 day |
| Screen (submenu + template + query + filters + badge) | 1.5 days |
| Retry AJAX (single + bulk `scope`) | 1.0 day |
| Cron sweep + options + backoff/max attempts | 1.0 day |
| Notification (option-backed notice + reuse of render) | 0.5 day |
| Tests (unit classifier, mock end-to-end, idempotency, acceptance) | 1.5 days |
| **Total** | **~6.5 dev days** |

Suggested phasing (4 phases): (1) ledger + classifier + persistence; (2) screen + single retry; (3) bulk retry + cron; (4) notifications + tests + acceptance.

---

## 8. Risks / open questions

1. **Alegra stock error shape is undocumented** `[HYPOTHESIS]`. Classification defaults all 4xx to permanent, which is safe (no loop) but means a transient validation glitch needs a manual retry. Confirm against a live account whether stock rejection is 400 or 422 and whether it carries a stable `response.errors` key we can map.
2. **Auto-retry default OFF** is deliberate; enabling it creates real fiscal documents unattended. Confirm the merchant wants unattended retry or only manual.
3. **Cron availability** — the plugin already documents that real cron is host-dependent; the retry sweep inherits the same caveat as the poll/payment sweeps.
4. **Meta query cost** on large stores: index `_alegra_invoice_sync_state` usage by adding `_alegra_invoice_id`-style `Entity_Map` entries or a bounded `limit` + cached count (the design caches the badge count).
5. **Payment side-failure** (invoice ok, payment missing) is adjacent but handled by the existing payment reconcile; decide whether the new screen should also surface it (recommended: yes, as a distinct state `payment_missing`).

---

## Appendix A — Key evidence index

- Write choke point / gates: `includes/API/Client.php:101-145`, `includes/Write_Gate.php:114-131`
- Retry + backoff + error mapping: `includes/API/Client.php:177-255`
- Rate limiter: `includes/API/Client.php:954-956, 1014-1034`
- Invoice creation + idempotency + lock: `includes/Sync/Orders.php:63-193, 203-229, 309-340, 351-430`
- Automatic failure note: `public/Public/Public_.php:373-427`
- Manual single: `admin/Admin/Admin_Dashboard.php:3166-3211`
- Bulk: `admin/Admin/Admin_Dashboard.php:3305-3346, 3867-3979`
- Divergence row: `admin/Admin/Admin_Dashboard.php:862-887, 901-905`
- Notices: `includes/State_Sync.php:62, 473-504`
- Runs/Monitor: `includes/Runs.php:46-203`, `includes/Run_Context.php:30-92`, `includes/Schema.php:170-197`, `admin/Admin/Admin_Dashboard.php:4144-4234`, `templates/admin-monitor.php`
- Invoice status model: `includes/Invoice_Status.php:22-83`
