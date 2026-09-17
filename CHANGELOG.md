# Changelog

All notable changes to Alegra Connector.

## [2.3.5] - 2026-09-16

### 🐛 Fixed

- **FIX: dry run reported success for operations it never performed.** The
  dry-run marker is an array, so `is_wp_error()` let it through as a success in
  four places, which meant testing with dry run on showed "payment registered" /
  "credit note created" for things that never happened:
  - registering a payment from the admin no longer saves an empty Alegra payment
    id or reports success;
  - refund credit notes no longer mark the refund as credited;
  - payment-method changes no longer claim the invoice was updated in Alegra;
  - deleting webhooks no longer wipes the local subscription list when nothing
    was deleted on Alegra's side.
- **FIX: variable products returned a misleading "no id" error** under dry run
  instead of the dry-run marker.
- **FIX: the product import read only price list 1**, ignoring the configured
  price list.
- **CHANGED: removed an unused constant** (`ALEGRA_CONNECTOR_API_URL`).

### ✅ Upgrade Notes

- **Dry run now reports honestly:** nothing is written and the UI says so. If
  you used dry run before, the previous "success" messages were not real.
- **No change to live (dry run off) behaviour.**

## [2.3.4] - 2026-09-16

### 🐛 Fixed

- **FIX (crítico): the item payload used `type: 'simple'`, which is the READ
  enum** — the WRITE enum is `product|service|variantParent|kit`. Every product
  create/update sent an invalid type. Corrected to `product`.
- **FIX (crítico): `inventory.unitCost` was missing** from the create payload
  (documented as required when `inventory` is present). It is now sourced from
  the WooCommerce cost-of-goods meta with a `0` fallback.
- **FIX (crítico): the variable-product push never worked** — the parent omitted
  the required `variantAttributes`, used the kit-only `subitems` field, and
  children used the non-writable type `variant`. It now resolves the attributes,
  builds `variantAttributes` + `itemVariants`, and maps the child IDs back to the
  WooCommerce variations.
- **FIX (crítico): `inventory.initialQuantity` was re-sent on every product
  update.** It is documented as the quantity at creation, so re-sending it risked
  resetting the stock on every edit. It is now sent only on create; updates omit
  `inventory` entirely (the API is a partial update).

### ✨ Added

- **ADDED: the test mock now validates write payloads** against the documented
  schema (enums + required fields) and returns a realistic 400. This is why the
  invalid payloads above passed CI for so long — the mock accepted anything.

### ✅ Upgrade Notes

- **If you push products to Alegra, this release fixes payloads that Alegra
  would have rejected.** Re-push any products that failed silently.
- **Products with no cost-of-goods meta will be sent with `unitCost: 0`.**
- **Variable products can now be pushed**; each WooCommerce variation maps to an
  Alegra variant.

## [2.3.3] - 2026-09-16

### 🐛 Fixed

- **FIX: the "upload orders automatically" toggle could be silently ineffective** —
  it was gated by a second, overlapping option (`sync_method`) whose UI default
  was `cron`, so turning automatic ON did nothing. There are now two clearly
  separated controls: *automatic sync FROM Alegra* (inbound) and *upload orders
  TO Alegra* (automatic/manual).
- **CHANGED: the default for uploading orders is now manual** — it was
  automatic. Existing installs that already enabled it keep their setting.
- **VERIFIED: the manual "create invoice" action works independently** — the
  per-order "Facturar" path was verified to work regardless of the automatic
  setting.

### ✨ Changed

- **CHANGED: `sync_method` is now inbound-only** — matching its label. Installs
  with `real-time` or `disabled` will now skip the inbound cron (previously it
  ran regardless).
- **CHANGED: setting labels rewritten** — each control now states plainly what
  it does and that the two are independent.

### ✅ Upgrade Notes

- **If you relied on orders being uploaded automatically, you must now enable
  it explicitly** — it is off by default. Turn on *Configuración → Sincronización
  → Subir pedidos a Alegra* to restore the old behaviour. Manual invoicing is
  unaffected and remains the default workflow.
- **If `sync_method` was `real-time` or `disabled`, the inbound cron now
  respects it** — it will no longer run the periodic Alegra → WooCommerce pull.
  If you need the pull, set the method to *Periódica* or *Periódica + Tiempo
  Real*.

## [2.3.2] - 2026-09-16

> **⚠️ This release REMOVES functionality shipped in 2.3.0/2.3.1.** If you rely
> on the plugin to emit e-invoices to the DIAN, that is gone: **stamp in Alegra**
> (or use Alegra's own stamping) instead. Existing invoices are untouched. The
> `alegra_connector_stamp_enabled` option is removed.

### 🗑️ Removed — DIAN e-invoicing was never a requirement

The plugin's job is to push orders/invoices to Alegra — automatically and
manually. DIAN electronic invoicing was added in 2.3.0 as an **unrequested
feature that defaulted ON**, so a Colombian store that installed the plugin and
did nothing silently issued **legal e-invoices to the tax authority for every
order and every refund** — irreversible, numbering-consuming, with tax/legal
consequences. It is removed.

- **REMOVED: DIAN stamping** — Invoices and credit notes no longer send
  `stamp.generateStamp`. There is no draft-with-400 stamp-recovery path anymore.
- **REMOVED: the DIAN payment-method catalog and `paymentForm`** — Colombian
  invoices no longer send the uppercase DIAN "Medio de pago" codes or the
  CASH/CREDIT `paymentForm`.
- **REMOVED: the `emission_status` gate** — credit notes are no longer blocked
  on the original invoice's DIAN emission state.
- **REMOVED: the Colombia country gating** — contact and invoice fields are no
  longer conditioned on the account country.
- **REMOVED: the `alegra_connector_stamp_enabled` option**, its settings
  checkbox, its dashboard health indicator and its uninstall cleanup.
- **REMOVED: `kindOfPerson` and `regime` from the contact payload** — the
  official docs show they are required only for the e-invoicing contact schema,
  not to create a plain contact.

### ✨ Changed

- **CHANGED: invoices are now created as DRAFTS** — the plugin sent
  `status: open` unconditionally, contradicting its own documentation ("en
  borrador"). Alegra's documented behaviour is that omitting `status` (with no
  payments) creates a draft. A new setting, **"Estado de las facturas"**
  (Borrador / Abierta), defaults to **Borrador**; an invoice that records a
  payment is created open because Alegra only accepts payments on open invoices.
- **CHANGED: the billing catalog is reduced from 11 fields to the
  identification** — the document type, the number and the DV for a NIT are the
  only things WooCommerce does not already collect. The admin section is renamed
  from "Facturación electrónica" to **"Datos de facturación"**.
- **CHANGED: the invoice-status option replaced `stamp_enabled`** — the removed
  stamp toggle is superseded by the new invoice-status setting.

### ✨ Added

- **NEW: an order note when the invoice is issued to Consumidor Final** — when a
  customer has no identification and the resolution mode is `auto`, the invoice
  falls back to the generic Consumidor Final contact. The order now gets a
  Spanish note naming the missing field(s), so the merchant finds out instead of
  discovering it later on a real invoice. `always_generic` (an intentional
  choice) and `require_data` (which aborts instead of falling back) never get
  the note.

### ✅ Kept (from 2.3.1)

- The customer resolution, the Consumidor Final fallback, the atomic locks, the
  HPOS fixes and the performance work.

## [2.3.1] - 2026-09-16

> **⚠️ This release replaces the broken public 2.3.0 — upgrade immediately.**
> 2.3.0 was published with **two fatal errors** inside (`update_transient()` is
> not a WordPress function, and `map_product_tax()` returned `int` from a
> `: string` method under `strict_types`), a duplicate DIAN credit-note bug and
> a set of correctness defects. A full audit followed — **68 findings, all fixed
> across batches 0–3** (critical → security → correctness → performance →
> hygiene). This is a **security and correctness release, not a feature
> release**. The 2.3.0 feature set is unchanged; only the defects are.

### 🛡️ Critical & Security

- **FIX: `update_transient()` fatal killed every cron sync** — `update_transient()` is not a WordPress function. It fataled every cron run right before the completion heartbeat, so runs were marked failed and `alegra_connector_last_sync` was never updated. Replaced with `set_transient()` (and a proper heartbeat write).

- **FIX: `map_product_tax()` TypeError killed every product push** — The method is declared `: string` but returned `int 0` for products without an explicit tax class. Under `strict_types` that is a `TypeError`, so **every** product push without a tax class died. It now returns `'0'` (and the callers treat tax as a string end-to-end).

- **FIX: a full refund issued TWO credit notes to the DIAN** — Two hooks both created a credit note (`order_status_refunded` → `create_credit_note`, `order_refunded` → `create_credit_note_for_refund`). A full refund fires both, with different idempotency keys, so it emitted **two fiscal documents**. There is now a single owner and one per-refund idempotency key.

- **FIX: sync/invoice/contact/refund locks were not atomic** — The locks used `get_transient()` then `set_transient()` (an unconditional upsert), so two PHP-FPM workers could both read empty and both win, each creating an invoice for the same order → **duplicate fiscal documents**. Replaced with an `add_option()`-based compare-and-swap (`wp_options.option_name` is UNIQUE) with stale-lock reclamation; every lock site releases in a `finally` block.

- **FIX: Colombian invoices sent the wrong `paymentMethod` catalog** — Colombia requires the uppercase DIAN "Medio de pago" codes; the plugin sent the lowercase global codes. It now maps WooCommerce gateways to DIAN codes for CO and **omits** `paymentMethod` (rather than guessing) when the gateway has no unambiguous DIAN equivalent.

- **FIX: API token rendered in the settings page HTML** — The Alegra API token was printed in plaintext in the settings page markup, readable by any user with `manage_woocommerce`. It is now masked, and submitting the field empty keeps the stored value instead of wiping it.

- **FIX: DOM XSS from Alegra error text** — Raw Alegra error strings were concatenated into HTML in three admin templates. Nodes are now built with `.text()` and tags are stripped at the source.

- **FIX: webhooks could never authenticate** — The receiver required an HMAC signature, but **Alegra sends no signature**, so every webhook returned `401` and the feature was silently dead. Verification is now optional (only enforced when a signature header is actually present) and replay protection uses a body-hash window.

- **FIX: capability mismatches** — Credential overwrite, user/attachment deletion, CSV import and disconnect now require the capability the operation actually needs, instead of a weaker shared check.

### 🐛 Correctness

- **FIX: HPOS order read still used `get_post_meta()`** — One admin/dashboard order-meta read bypassed the WooCommerce CRUD API, so on High-Performance Order Storage stores **every order looked pending**. It now uses `$order->get_meta()`.

- **FIX: Alegra UUIDs truncated by `(int)` casts** — Five remaining places cast Alegra UUIDs to `int`, corrupting ids. All Alegra ids are treated as strings end-to-end.

- **FIX: disabled billing fields were still required and still sent** — Field enablement now gates collection, validation, saving and payload building alike, so a disabled field is neither demanded at checkout nor transmitted.

- **FIX: coupon discounts were not mapped** — Invoices exceeded the WooCommerce order total because discounts were dropped from the payload. Coupon discounts are now applied.

- **FIX: CO-only contact fields sent to every account country** — Contact address keys are now filtered per country, so a non-Colombian account is not sent Colombian-only fields (and `OTHER_ENTITY` was added to `kindOfPerson`).

- **FIX: payment-method change >5 min after the baseline was dropped** — The baseline lived in a 300-second transient, so a later change was silently ignored. The baseline is now persisted so the change is detected.

- **FIX: inventory pull omitted `mode=advanced`** — The stock pull requested the simple item shape, which excludes inventory detail — likely a silent no-op. It now asks for `mode=advanced`.

- **FIX: a failed DIAN stamp left an untracked draft → duplicate on retry** — Alegra returns `400` with the created invoice in the body when stamping fails; the plugin discarded it and a retry created a second invoice. The id is now recovered and persisted, and the order is annotated that it was created but not stamped.

- **FIX: no idempotency — a lost response duplicated the invoice/payment** — Before creating a document the plugin now pre-searches for an existing one, so a lost/retried response no longer duplicates an invoice or a payment.

- **FIX: imported variations had no attributes** — Imported product variations were created without attributes and therefore were not selectable in WooCommerce. They now get their attributes.

- **FIX: `Consumidor Final` cache was never invalidated** — A deleted/replaced contact id was served forever; the cache is now invalidated and `resolve()` respects it.

- **FIX: a missing Alegra price overwrote the WooCommerce price with `0`** — A missing/absent price is now skipped instead of zeroing the store price.

### ⚡ Performance & Robustness

- **FIX: `Schema::migrate()` ran six `dbDelta()` on every request** — Including frontend page views, for every visitor. It is now version-guarded via `alegra_connector_schema_version`, so schema work runs only on install/upgrade.

- **FIX: `Entity_Map` was never written on import → O(N²) scans** — Every lookup fell back to an unindexed `wp_postmeta.meta_value` scan. Imports now populate the entity map, lookups use it, and `backfill_from_postmeta()` is keyset-paginated and opt-in.

- **FIX: product import was silently capped at 6,000 items and not resumable** — It now has a cursor + time budget, no page cap, and drops the `set_time_limit(300)` crutch; interrupted runs resume instead of restarting.

- **FIX: rate limiting used a window that never reset, at a third of the documented limit** — It now uses a fixed window that resets, honours the `X-Rate-Limit-*` headers, and uses the documented **150 req/min** (was 50).

- **FIX: log tables had no retention; dead tables lingered** — A daily retention/prune cron was added, and three writerless tables (`wp_alegra_pull_queue`, `wp_alegra_push_log`, `wp_alegra_push_queue`) are dropped.

- **REMOVED: the non-functional push-queue subsystem** — `enqueue()` had zero callers, `execute_queued_push()` called a missing method and `mark_applied()` had no status guard. The class, admin page, template, AJAX handlers and settings were removed (see Upgrade Notes).

- **FIX: cron overlap and admin page cost** — Added the missing global cron overlap lock; the orders poll is locked and ID-only; admin pages use `EXISTS`/SQL aggregates instead of per-row correlated subqueries; image dedup uses the indexed map and an O(1) hash set.

### 🌍 i18n & Hygiene

- **NEW: the plugin now ships a translation template** — There was **no `.pot` at all**, so the plugin could not be translated. Added a dependency-free extractor (`scripts/make-pot.php`), generated `languages/alegra-connector.pot`, and wired it into the release build.

- **FIX: mojibake and hardcoded strings** — Restored accented characters that had been corrupted (`Estad sticas` → `Estadísticas`, etc.), wrapped the remaining hardcoded user-facing strings in the `alegra-connector` text domain, and moved hardcoded JS strings into the localized payload.

- **FIX: Chart.js loaded from a third-party CDN** — Vendored locally at `admin/assets/js/vendor/chart.min.js`.

- **FIX: `uninstall.php` left data behind and did nothing on multisite** — It now removes every table, option, meta and scheduled action it created, and works on a network uninstall.

- **FIX: the Logger self-check silently deactivated the plugin** — A class-load failure used to deactivate a production plugin. It now only raises an admin notice.

### 📝 Technical

- Plugin version: **2.3.0 → 2.3.1** (PATCH — corrective release; no API or feature change).
- Plugin header `Version:` and `ALEGRA_CONNECTOR_VERSION` bumped to `2.3.1`.
- `Schema::SCHEMA_VERSION` reconciled from `2.4.0` to `2.3.1` so it names the release that actually ships the schema change (see Upgrade Notes).
- `scripts/make-pot.php` `$version` bumped to `2.3.1`; the `.pot` header now reports `Alegra Connector 2.3.1`.
- New files: `scripts/make-pot.php`, `scripts/exec-test.php`, `scripts/exec-test.sh`, `scripts/lib/` (wp-stubs, alegra-mock, test-framework), `languages/alegra-connector.pot`, `admin/assets/js/vendor/chart.min.js`.
- Smoke-test extended; the release gate now also runs an **execution harness** that boots the plugin against a stubbed WordPress/WooCommerce and a mocked Alegra API, so a runtime fatal or a bad payload cannot be packaged.
- `README.md` version line bumped to `2.3.1`.

### ✅ Upgrade Notes

- **Upgrade from 2.3.0 immediately.** All fixes are corrective; settings and data are preserved. The 2.3.0 feature set is unchanged.
- **Three tables are dropped** by the schema migration: `wp_alegra_pull_queue`, `wp_alegra_push_log` and `wp_alegra_push_queue`. They had no writers (the push-queue subsystem was removed — see below). If you had bookmarked or scripted against them, they are gone.
- **The push-queue UI and settings are gone.** The old "Cola de push a Alegra" admin page, its AJAX actions and the per-entity push-direction settings were removed because the subsystem never worked (`enqueue()` had zero callers; the executor called a missing method). Nothing that worked was lost, but a setting/UI you may have seen is no longer there.
- **The schema migration re-runs once on upgrade and is idempotent.** 2.3.0 stored `alegra_connector_schema_version = '2.3.0'`; 2.3.1 ships `'2.3.1'`, so the guard differs and the migrations (drop dead tables, UUID column migration) run exactly once. The UUID column migration keeps its own separate guard.
- **The API token field now masks the stored token.** Re-saving the settings form with the field left empty keeps the stored token; type a new token only to replace it.
- **Webhook signature verification is now optional.** Alegra does not send a signature; if you had configured a webhook secret, it is only checked when a signature header is present. Replay protection is enforced via a body-hash window. Re-delivering the same webhook body within the window is ignored.
- **A `.pot` now ships in the ZIP** (`languages/alegra-connector.pot`) — translations can finally be built. There is no `.mo` yet; the plugin still runs in English/Spanish source strings.
- See `docs/RELEASE_2.3.5_VERIFICATION.md` for the assumptions that still require a live API test.

---

## [2.3.0] - 2026-09-16

### 🐛 Critical Fixes

- **FIX: "el cliente no existe" when invoicing** — Orders sent the customer inline inside the invoice payload, which Alegra rejects because every invoice must reference an existing contact by `id`. `Orders::ensure_customer_synced()` now resolves (or creates) the contact with a 7-step algorithm and falls back to the **Consumidor Final** contact when there is not enough data, so invoicing no longer fails for customers without billing data.

- **FIX: Alegra UUIDs truncated by `(int)` casts** — Alegra IDs are UUIDs (`VARCHAR(36)`), but they were cast to `int` throughout (invoices, contacts, categories, push queue). The plugin now treats Alegra IDs as strings end-to-end, and `Schema::maybe_migrate_alegra_id_columns()` migrates the `alegra_id` columns of `wp_alegra_tombstones`, `wp_alegra_pull_queue`, `wp_alegra_push_log` and `wp_alegra_entity_map` from `BIGINT` to `VARCHAR(36)`.

- **FIX: HPOS compatibility** — Order data was read/written through post meta, so under High-Performance Order Storage the invoice id was never found (duplicate invoices), refunds reported "no invoice" and the Alegra contact id was never persisted. Order meta now goes through the WooCommerce CRUD API (`$order->get_meta()` / `update_meta_data()`), which works with both post and HPOS storage.

- **FIX: Credit notes never tied to their invoice** — Credit notes were sent with an undocumented singular `invoice` field, so Alegra never linked them. They now use the documented plural `invoices: [{ id, amount }]` array.

- **FIX: Partial refunds over-credited the full invoice** — A partial refund produced a credit note for the FULL invoice amount. The credit note now uses the refunded amount, is idempotent per refund id, and is capped by the cumulative `_alegra_credited_amount` so it can never exceed the original invoice total.

- **FIX: Number-template selection never matched** — The invoice template lookup tested for `type === 'electronic'`, which never matched Alegra's response, so invoices could not be stamped. It now selects the template with `isElectronic === true` (falling back to `isDefault`).

- **FIX: UUID settings truncated to 0 on save** — `warehouse_id`, `payment_account_id` and `payment_term_id` were saved via `intval`, truncating UUID values to `0`. They are now stored and read as strings.

- **FIX: Category import / re-push** — Imported products now get their Alegra category assigned; pushing a product sends the category as `{ id }` instead of `{ name }`; and `assign_product_category()` no longer wipes the merchant's existing categories.

### ✨ New Features

- **NEW: 11 pre-defined billing fields for Colombia** — Obligatorios (tipo de persona, tipo de documento, número de documento, dígito de verificación, régimen tributario), recomendados (razón social, segundo nombre, segundo apellido) and opcionales (teléfono secundario, celular, observaciones), with support for both the classic checkout and Cart/Checkout Blocks.

- **NEW: Consumidor Final fallback contact** — Used when a customer cannot be resolved or has no billing data. The plugin never creates it; it must already exist in Alegra.

- **NEW: DIAN stamping** — Invoices and credit notes are stamped (`stamp.generateStamp`) when `stamp_enabled` is on; otherwise they stay as drafts.

- **NEW: Customer resolution modes** — `auto` / `always_generic` / `require_data`, configurable under Ajustes → Facturación electrónica.

- **NEW: Refund credit notes** — Idempotent per refund, with a cumulative cap per order.

- **NEW: "Facturación electrónica" admin section** plus an invoicing health widget on the dashboard.

- **NEW: Dry Run mode** — Blocks every write (POST) to Alegra while keeping reads, for safe production testing.

- **NEW: State sync** — Refunds, profile updates and payment-method changes propagate to Alegra.

### 📝 Technical

- Plugin version: **2.2.0 → 2.3.0** (MINOR bump — additive changes, no breaking API).
- Plugin header `Version:` and the `ALEGRA_CONNECTOR_VERSION` constant bumped to `2.3.0`.
- New files: `includes/Billing_Fields.php`, `includes/Consumidor_Final.php`, `includes/Checkout_Integration.php`, `includes/State_Sync.php`, `assets/js/alegra-checkout-conditions.js`, `docs/RELEASE_2.3.0_DEPLOY.md`.
- Modified files: `includes/Sync/Orders.php`, `includes/Sync/Products.php`, `includes/Schema.php`, `includes/API/Client.php`, `includes/Entity_Map.php`, `admin/Admin/Admin_Dashboard.php`, templates, `README.md`, `CHANGELOG.md`.
- Smoke-test extended to 37 assertions (all passing).

### ✅ Upgrade Notes

- **Safe upgrade from 2.2.0.** All changes are additive. The `alegra_id` column migration from `BIGINT` to `VARCHAR(36)` runs automatically on load and is **not** reversed by a rollback.
- **Rollback safe for numeric IDs.** Reverting to 2.2.0 keeps working with numeric IDs (the columns simply stay as text); the new options (`customer_resolution_mode`, `stamp_enabled`, `dry_run`) are ignored by 2.2.0.
- See `docs/RELEASE_2.3.0_DEPLOY.md` for the full deploy, verification and rollback guide.

---

## [2.2.0] - 2026-09-10

### ⚡ Performance & Robustness

- **FIX: Customers::sync_all() memory blow-up** — Previously used `get_users(['number' => -1])` which loaded ALL customers into memory (OOM risk on stores with 50k+ customers). Now paginates via `number` + `paged` with batch size 100, mirroring `Products::sync_all()` pattern.

- **FIX: Logger silently dropped logs under contention** — `Logger::write()` used `LOCK_EX | LOCK_NB` (non-blocking flock) which silently fell through to `error_log()` fallback when another worker held the lock. Now uses `LOCK_EX` (blocking) for durability. Contention is rare (~1-5ms worst case, invisible next to the API call that triggered the log).

- **FIX: Rate-limit transient bloated wp_options** — `set_transient('alegra_connector_rate_limit', ...)` now passes `'no'` as 4th arg so the row in `wp_options` has `autoload='no'`. Prevents the WP `alloptions` cache from loading it on every page load.

- **FIX: Log files orphaned on uninstall** — `uninstall.php` already removes the `wp-content/uploads/alegra-logs/` directory recursively (was added in 2.1.0). Reaffirmed in 2.2.0 audit.

### 🔍 Audit Pass (deeper coverage)

- Audited `admin/Admin/Admin_Dashboard.php` lines 1119+ for SQL injection vectors — see `docs/AUDIT_ADMIN_DASHBOARD_2.2.0.md`.
- Audited 16 `templates/admin-*.php` files for XSS / unescaped output — see `docs/AUDIT_TEMPLATES_2.2.0.md`.
- Audited `includes/Sync/Categories.php`, `includes/Push_Queue.php`, `includes/Heartbeat.php`, `includes/Runs.php`, full `logger/Logger/Logger.php` — see `docs/AUDIT_REMAINING_2.2.0.md`.
- **Audit results: 0 P0/P1 fixes required.** All three sub-audits came back clean — see the three reports for full per-call/per-file analysis.

### 📝 Technical

- Plugin version: **2.1.9 → 2.2.0** (MINOR bump — additive changes, no breaking API).
- Plugin header `Version:` (English) recognized by WP.
- Constant `ALEGRA_CONNECTOR_VERSION` bumped to `'2.2.0'`.
- New files: `docs/AUDIT_ADMIN_DASHBOARD_2.2.0.md`, `docs/AUDIT_TEMPLATES_2.2.0.md`, `docs/AUDIT_REMAINING_2.2.0.md`.
- Modified files: `includes/Sync/Customers.php`, `logger/Logger/Logger.php`, `includes/API/Client.php`, `uninstall.php`, `alegra-connector.php`, `CHANGELOG.md`, `README.md`, `scripts/smoke-load.php`, plus any files fixed per audit findings.
- Smoke-test extended from 8 to 12 assertions.

### ✅ Upgrade Notes

- **Safe upgrade from 2.1.9.** All changes are additive — pagination loops more items with same logic; LOCK_EX replaces LOCK_NB (logically identical behavior under no contention); `'no'` autoload is a metadata change visible only via `wp_options` queries; uninstall cleanup runs only on plugin deletion.
- **Rollback safe.** Upload 2.1.9 ZIP to revert; no data corruption.

---

## [2.1.9] - 2026-09-10

### 🐛 Critical Fixes

- **FIX: Token length leak in API client logs** — Removed `error_log()` call in `includes/API/Client.php:50-52` (`get_auth_header()`) that logged token/email length info to PHP error stream. The method now returns `'Basic '` silently when credentials are missing; auth failures are reported through the structured logger only.

- **FIX: Webhook delete-item race condition** — When Alegra sends a `delete-item` webhook, the plugin now writes a tombstone (`includes/Webhooks/Handlers.php:76-110`) instead of deleting `_alegra_item_id` postmeta. Previous behavior could lose product linkage when a concurrent sync pull was creating the product. The tombstone blocks future re-imports via `Tombstone_Manager::exists()`. New tombstone `reason` value: `'alegra_deleted'`.

### ⚡ Performance & Robustness

- **NEW: Transient-based sync lock** — Added `acquire_sync_lock()` / `release_sync_lock()` helpers to `Controller`. Cron sync, manual AJAX sync, and the inventory sync method now acquire a 5-minute transient lock (`alegra_sync_running_{type}`) before starting. Concurrent syncs abort cleanly with a clear error instead of duplicating products/invoices. Public static wrappers exposed for cross-class use.

- **FIX: Inventory pagination bug** — `Products::sync_inventory_from_alegra()` previously hardcoded `limit: 30` and only synced the first 30 products. Now paginates through all items (up to 200 pages × 30 = 6000 per run) using the same loop pattern as `import_from_alegra()`. Includes cancel detection and progress reporting.

### 📝 Technical

- Plugin version: **2.1.8 → 2.1.9**
- Plugin header `Version:` (English) recognized by WP — `Versión` (Spanish) placeholder bug remains fixed.
- Constant `ALEGRA_CONNECTOR_VERSION` bumped to `'2.1.9'`.
- Files modified: `includes/API/Client.php`, `includes/Webhooks/Handlers.php`, `includes/Sync/Controller.php`, `includes/Sync/Products.php`, `includes/Sync/Customers.php`, `admin/Admin/Admin_Dashboard.php`, `CHANGELOG.md`, `README.md`.
- New file: `docs/RELEASE_2.1.9_DEPLOY.md`, `scripts/smoke-load.php` extended with 2 regression assertions.
- Total plugin source code touched: 6 files in `includes/`, 1 in `admin/`.

### ✅ Upgrade Notes

- **Safe upgrade from 2.1.8.** All changes are additive: tombstones write new rows in `wp_alegra_tombstones`; lock is a transient check; pagination loops more items with the same logic.
- **Rollback safe.** Upload 2.1.8 ZIP to revert; no data corruption.

---

## [2.1.8] - 2026-09-09

### 🐛 Critical Fix — Activation Fatal

- **FIX: Activation no longer crashes with `PHP Fatal error: Failed opening required '.../logger/Logger/Logger.php'`.** The plugin was crashing on activation whenever the lowercase `logger/` directory was missing from the release ZIP — which happened because the previous release process could ship incomplete archives without surfacing the problem. Release 2.1.7 (and 2.1.6, 2.1.7-1) all failed to activate on production with this same fatal.
- **Removed fragile `require_once` workaround.** The `require_once __DIR__ . '/logger/Logger/Logger.php';` line that was a workaround for an autoloader edge case is gone. The PSR-4 autoloader below it now resolves the Logger class correctly without any explicit include.
- **Hardened PSR-4 autoloader.** Added a dedicated fast-path for the `Alegra\Connector\Logger\*` namespace that checks `__DIR__ . '/logger/'` first, before any other candidate. This is the resolution order that fixes the regression permanently — the previous order buried `logger/` as the LAST generic candidate, which is why some shared-hosting setups missed it.
- **Readable fallback when Logger is still missing.** If a future release somehow ships without `logger/`, the plugin now (a) surfaces a clear Spanish admin notice (`"Alegra Connector: no se pudo cargar la clase Logger..."`) in `/wp-admin/`, (b) auto-deactivates itself to prevent leaving the install in a half-broken state. Replaces the silent fatal with a clear, recoverable signal.

### 🔧 Refactor — Bootstrap Hardening

- **No more fragile explicit `require_once`.** `alegra-connector.php` line 31 deleted.
- **Logger fast-path.** New branch in the `spl_autoload_register` closure that resolves `Alegra\Connector\Logger\*` from `__DIR__ . '/logger/'` first.
- **Removed redundant `Logger` from subdirs list.** The `$subdirs = ['Webhooks', 'Sync', 'API'];` array no longer scans `includes/Logger/` (which never existed) — the real path is handled by the fast-path.

### 📦 Release Pipeline — Reproducible ZIPs

- **NEW: `scripts/build-release.sh`** — portable Bash script (Ubuntu, Git Bash, WSL, macOS) that builds a deterministic release ZIP. Uses `git ls-files` filtered through `.distignore` (the standard WordPress release exclude mechanism). Computes SHA-256 sidecar. Runs `scripts/smoke-test.sh` as a pre-release gate.
- **NEW: `.distignore`** — checked into the repo. Excludes `.git/`, `scripts/`, `temp_pkg/`, `tests/`, `vendor/`, `node_modules/`, `composer.json`, dev docs, IDE files, etc. from release ZIPs. Verified not to exclude `logger/` or any other runtime file.
- **NEW: `scripts/smoke-test.sh`** + **`scripts/smoke-load.php`** — pre-release gate. Asserts (a) no fragile `require_once` in the main file, (b) `Alegra\Connector\Logger\Logger` is reachable via autoloader alone, (c) `Alegra\Connector\Alegra_Connector` singleton loads, (d) `logger/` directory tree is complete on disk, (e) `php -l` passes on every `.php` in the extracted ZIP. Exits non-zero on any failure — aborts the build before shipping.

### 📝 Technical

- Plugin version: **2.1.7 → 2.1.8**
- Plugin header fixed: `Versión: 2.1.7` (Spanish, with tilde — WP ignored it) → `Version: 2.1.8` (English, recognized by WP).
- Constant `ALEGRA_CONNECTOR_VERSION` bumped to `'2.1.8'`.
- `README.md` version line bumped to `2.1.8`.
- Files added: `.distignore`, `scripts/build-release.sh`, `scripts/smoke-test.sh`, `scripts/smoke-load.php`.
- Files modified: `alegra-connector.php` only (bootstrap refactor + header + constant).
- Total plugin source code touched: 0 files in `includes/`, `admin/`, `public/`, `templates/`, `languages/`, `uninstall.php`.

### ✅ Upgrade Notes

- **Safe to upgrade from 2.1.7 over the broken install.** Upload the 2.1.8 ZIP via WP admin → Plugins → Add New → Upload Plugin, or replace the existing `wp-content/plugins/alegra-connector/` (or `alegra-connector-v2.1.7/`) directory via SFTP. Activate. The autoloader will resolve the Logger class from the freshly uploaded `logger/` directory.
- **If upgrading from a working pre-2.1.6 install:** credentials, settings, mappings, logs, cron, and tombstones are all preserved (the deactivate handler in 2.1.0+ already cleans transients and cron on each deactivation, but preserves user options).

---

## [2.1.0] - 2026-09-02

### 🖥️ Monitor de Procesos (Sección G)

- **NEW:** `includes/Runs.php` — Track start/progress/finish of every sync operation
- **NEW:** `includes/Heartbeat.php` — Real-time state via transient + static cache (memory + CPU tracking)
- **NEW:** `templates/admin-monitor.php` — Full monitor UI with live polling (5s)
- **NEW AJAX:** `alegra_monitor_status`, `alegra_kill_run`, `alegra_kill_all`
- **Emergency stop** button in monitor UI: activates kill switch + clears all
- Controller::run_cron_sync wrapped with Runs::track + step-by-step heartbeats
- Cron events listed in monitor (next runs visible)
- Recent history (last 15 runs) with status, errors, memory

### 📤 Cola de Push a Alegra (Sección D)

- **NEW:** `includes/Push_Queue.php` — Pending push operations awaiting user approval
- **NEW:** `templates/admin-push-queue.php` — Full UI to review/approve/reject pushes
- **NEW AJAX:** `alegra_approve_push`, `alegra_reject_push`, `alegra_save_sync_directions`
- **Double confirmation** with "ENVIAR" word required (Spanish caps)
- **Push directions** per entity: `disabled` / `manual` / `auto`
- **Audit log** table `wp_alegra_push_log` with request/response payloads
- Default: everything `disabled` (preserves Alegra data integrity)

### 🖼️ Fix bug duplicación imágenes (Sección B)

- **`download_and_attach_image`** hardened with:
  - Process lock via transient (prevents race conditions)
  - Hash MD5 + lookup by `_alegra_image_url_hash` meta (indexed)
  - Dual lookup: hash first, then URL fallback
- **NEW:** Botón "Reconstruir índice de imágenes" AJAX: backfills hash meta for legacy attachments
- Filename now includes hash prefix (avoids collisions)
- Detailed logging of import/skip events

### ⚡ Performance & Robustez (Sección H)

- **NEW:** `includes/Entity_Map.php` — Indexed `wp_alegra_entity_map` table for fast Alegra↔WC lookups
- **Backfill from postmeta:** Products/Customers/Invoices migrated to new table automatically
- **`Products::get_product_by_alegra_id`** uses new indexed table with fallback
- **Rate limiter:** Corrected from 150/min to 50/min (Alegra's actual limit), with token bucket and `Retry-After` header support
- **Exponential backoff:** 1s, 2s, 4s, 8s, 16s on 5xx/429 (was only 1s)
- Rate limit only increments on SUCCESSFUL requests (not errors)

### 🎨 UX Intuitiva (Sección I)

- **Dashboard rediseñado:** Quick status cards at top (Conexión, Procesos, Pushes, Última sync)
- **Wizard de onboarding 5 pasos** con progreso visual: Conexión → Sync → Imágenes → Push → Listo
- **Tooltips (UX7):** `?` iconos en campos complejos via `.ac-tooltip[data-tip]`
- **Empty states (UX6):** Estados vacíos explicativos con icono + mensaje
- **Modal de aprobación** con CSS limpio
- **Responsive (UX10):** Media queries para tablet/mobile
- Botón "Saltar asistente" con confirmación
- Estado del wizard guardado en user_meta por usuario

### 📚 Documentación

- **NEW:** Documentación inline en clases nuevas
- Todos los archivos PHPDoc-documented

### 📦 Technical

- Plugin version: **2.0.0 → 2.1.0**
- New PHP classes: `Runs`, `Heartbeat`, `Push_Queue`, `Entity_Map`
- New tables: `wp_alegra_push_queue`, `wp_alegra_push_log`
- New templates: `admin-monitor.php`, `admin-push-queue.php`, `admin-wizard.php`
- CSS: ~200 lines added for tooltips, modals, responsive
- ~600 lines of new JS for monitor polling, push queue, wizard

### ✅ Mantiene lo crítico de v2.0.0

- Tombstones siguen bloqueando recreación de productos borrados
- Kill switch sigue deteniendo todo al desconectar
- Deactivate sigue limpiando todos los recursos
- Modo de sync permanece `cron` por defecto

### ⚠️ Para validar en producción

1. **Pull de productos:** Traer desde Alegra y verificar que no duplica
2. **Tombstone:** Borrar un producto, próximo pull no debe recrearlo
3. **Monitor:** Iniciar un sync, ver progreso en tiempo real
4. **Push queue:** Activar push manual, aprobar un item, ver que llega a Alegra
5. **Wizard:** Usuario nuevo debe poder configurarlo sin ayuda externa

### 🔜 Próximo (post-v2.1.0)

- Encriptación del token (sodium, per `docs/ENCRYPTION_STRATEGY.md`)
- Tests PHPUnit/Playwright completos
- Idempotency keys en push (UUID v4)
- HMAC + timestamp + replay protection en webhooks
- Cache de catálogos estáticos
- Documentación PDF y screenshots

---







## [2.1.4] - 2026-09-03

### 🐛 Encoding Bug Fixes

- **FIX: Spanish characters displayed as mojibake** - Fixed broken UTF-8 encoding in many strings across all templates and Admin_Dashboard.php. Words like Configuraci n, Sincronizaci n, Documentaci n (with literal space instead of tilde) are now correctly rendered as Configuración, Sincronización, Documentación.

- **Comprehensive fix applied** across:
  - dmin/Admin/Admin_Dashboard.php - 11 replacements
  - 	emplates/admin-dashboard.php - 11 replacements
  - 	emplates/admin-settings.php - 24 replacements
  - 	emplates/admin-import.php - 9 replacements
  - 	emplates/admin-push-queue.php - 7 replacements
  - 	emplates/admin-wizard.php - 9 replacements
  - 	emplates/pagination.php - 6 replacements
  - All other templates and files - 30+ additional replacements

### 📝 Technical

- Plugin version: 2.1.3 -> 2.1.4
- New ZIP: legra-connector-v2.1.4.zip
- 99+ encoding replacements across 20 files
- WordPress install synced to v2.1.4 in same operation
## [2.1.3] - 2026-09-03

### 🐛 Critical Bug Fix

- **FIX: Productos listing table broken** - Fixed PHP syntax bug in 	emplates/admin-products.php that caused literal text ';?> to appear in the rendered HTML output for the status and action columns. The bug was caused by premature ;?> PHP close tags inside ternary expressions.
- Template rewritten with proper indentation and one statement per line for maintainability.
- Status and action cells now correctly show Sincronizado / Pendiente badges and action buttons.
- Each product row is on multiple lines for better debuggability.

### 📝 Technical

- Plugin version: 2.1.2 -> 2.1.3
- New ZIP: legra-connector-v2.1.3.zip
- Only 	emplates/admin-products.php changed in this release.
## [2.1.2] - 2026-09-03

### ✨ New Features

- **Product thumbnails in products listing** (	emplates/admin-products.php):
  - Added image column with 48x48px thumbnails
  - Placeholder icon when product has no image (dashicons-format-image)
  - colspan increased from 10 to 11 columns to accommodate new column

- **Improved pagination** (	emplates/pagination.php + CSS):
  - Status text: "Mostrando X-Y de Z productos"
  - Disabled state for First/Prev/Next/Last buttons at edges
  - ARIA ole="navigation" and ria-current="page" for accessibility
  - Keyboard focus outlines
  - Better visual separation

- **Cron task action buttons** (	emplates/admin-monitor.php + new AJAX endpoints):
  - **Ejecutar** — runs the cron hook immediately via wp_schedule_event + spawn_cron()
  - **Saltar** — removes the next scheduled run via wp_unschedule_event
  - **Eliminar** — clears ALL scheduled runs via wp_clear_scheduled_hook
  - Bridge hook legra_manual_run registered to invoke target hook safely
  - 4 new AJAX endpoints: legra_run_cron_now, legra_skip_cron_next, legra_unschedule_cron, legra_change_cron_frequency
  - Security: only legra_* hooks can be triggered manually

### 🎨 UI / CSS

- Added CSS classes: .ac-td-image, .ac-product-thumb, .ac-product-thumb-placeholder, .ac-col-image, .ac-cron-actions, .ac-spin
- Added loading spinner animation for async actions
- Better visual hierarchy in pagination with .ac-pg-buttons container

### 📦 Technical

- Plugin version: **2.1.1 → 2.1.2**
- New ZIP: legra-connector-v2.1.2.zip
- All files updated and verified
- No breaking changes

## [2.1.1] - 2026-09-03

### 🐛 Critical Bug Fix

- **FIX:** schedule_cron() was private, causing fatal error when saving plugin configuration (WordPress hooks invoke methods via call_user_func_array from outside the class context).
- Changed to public so the update_option_alegra_connector_sync_* hooks can invoke it correctly.
- This fix is required for users to save sync settings without seeing:
  `
  Fatal error: Uncaught TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid callback, cannot access private method Alegra\Connector\Alegra_Connector::schedule_cron()
  `

## [2.0.0] - 2026-09-02

### 🛡️ Critical Fixes

- **Fix `$country` undefined** in `Orders::prepare_invoice_data()`
- **Anti-duplication mutex** in `Orders::create_invoice()`
- **Thread-safe re-entrancy guard** in `Public_::trigger_sync()`
- **Anti-loop guard** in `Products::update_product_from_alegra()`
- **`wp_raise_memory_limit()`** in ALL heavy AJAX handlers

### 🔄 Kill Switch

- **NEW:** `includes/Kill_Switch.php` with per-request cache
- **`ajax_disconnect()` exhaustive:** kill switch + all transients + cron + webhooks
- **`deactivate()` exhaustive:** kill switch + all transients + cron + summary

### 🪦 Tombstones

- **NEW:** `includes/Tombstone_Manager.php`
- **NEW:** `includes/Schema.php` (5 tables)
- **Hook:** `before_delete_post` automatic tombstone creation
- **`Products::import_single_item_from_alegra()`** checks tombstone

### 🎨 UI Audit Fixes

- HTML broken fixed (admin-products.php:17-18)
- Sync method bug fixed (admin-dashboard.php)
- Settings defaults consistency
- CSS line-clamp opt-in
- XSS in markdown (admin-docs.php)
- One-liner refactored (admin-order-detail.php)
- PHP 8+ undefined variables fixed

### 📚 Documentation

- `docs/PLAN.md`, `docs/UI_AUDIT.md`, `docs/ENCRYPTION_STRATEGY.md`, `CHANGELOG.md`
