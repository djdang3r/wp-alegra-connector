# Changelog

All notable changes to Alegra Connector.

## [2.6.0] - 2026-09-25

> **Fiabilidad de sincronización: Consumidor Final honesto, inventario bidireccional y poll
> robusto.** El titular es la **sobreventa**: WC nunca empujaba stock a Alegra y el poll re-inflaba
> lo vendido. Ahora el plugin es dueño del movimiento de stock (ajuste WC→Alegra con delta) y el
> poll tiene presupuesto, cursor y `truncated`. Verificación de release:
> `docs/RELEASE_2.6.0_VERIFICATION.md` y `docs/sdd/sync-reliability/MANUAL-ACCEPTANCE.md`.

### Changed

- **El poll ya no re-infla el stock.** WC→Alegra empuja el **delta** vía
  `POST /inventory-adjustments` cuando `push_orders_enabled=false` (default). Con
  `push_orders_enabled=true` la factura es dueña del movimiento. **Nunca** los dos para el mismo
  movimiento (REQ-INV-08).
- **`_stock_status` se deriva con `wc_update_product_stock()`** respetando `_backorders` y el umbral
  de no-stock. **Cambio intencional:** con `backorders=yes` y stock 0 el estado pasa a
  `onbackorder` (antes `outofstock`). Con `backorders=no` y el umbral por defecto (`0`) el resultado
  es idéntico a 2.5.1; **si `woocommerce_notify_no_stock_amount > 0`**, WC deriva `outofstock` en el
  umbral (p. ej. qty 1 con umbral 2), donde 2.5.1 forzaba `instock`. Ver **R19**.
- **El poll ahora honra `preserve_fields`:** con `inventory` en la lista de preservados, el poll no
  escribe stock (antes lo ignoraba en silencio). **Cambio intencional** (corrección de bug).
- **El dashboard distingue "No verificado" de "No encontrado"** para el Consumidor Final; conectar
  resuelve el CF bajo un contexto explícito; "Verificar ahora" re-chequea sin recargar.

### Added

- **`Inventory_Writer`** (escritor único de `_manage_stock`/`_stock`/`_stock_status`) y
  **`Inventory_Pusher`** (push WC→Alegra con ledger `_alegra_stock_synced`/`_alegra_stock_push_pending`).
- **Poll con presupuesto y cursor:** `alegra_connector_inventory_poll_budget` (60 s),
  `_max_pages` (0 = sin tope), `_pull_cursor`, `_pull_total`; resultado con `truncated/completed/cursor/pages`.
- **Auto-sanado del 400 por Consumidor Final borrado** (invalida + re-resuelve + reintenta una vez
  reusando `find_existing_invoice`).
- **Cron real recomendado** en Ajustes: `DISABLE_WP_CRON` + línea de crontab con la URL del sitio.
- **Lock a prueba de fatales:** `register_shutdown_function` libera `alegra_sync_running_products`.
- **`cron_run_budget`** (540 s) < TTL 600 del lock global `alegra_cron_global`.

### Notes

- Sin cambio de esquema. Opciones nuevas con defaults seguros; **sin migración**.
- **Decisión de diseño D2 (dueño híbrido):** contradice deliberadamente `docs/sdd/inventory/DD-8`
  ("no cablear `create_inventory_adjustment()`"). Con los defaults (`push_orders=false`,
  `invoice_status=draft`) la factura **no** movía stock, así que el titular persistía. La intención
  de DD-8 (evitar doble conteo) se preserva vía el dueño a nivel tienda.
- **Action Scheduler (diferido):** el poll corre por WP-Cron con presupuesto y cursor; sin
  dependencia de AS.
- Opciones nuevas: `alegra_connector_push_inventory_enabled`,
  `alegra_connector_inventory_manage_stock_enabled`,
  `alegra_connector_inventory_poll_budget`, `alegra_connector_inventory_poll_max_pages`,
  `alegra_connector_cron_run_budget`, `alegra_connector_open_invoice_on_paid`,
  `alegra_connector_inventory_pull_cursor`,
  `alegra_connector_inventory_pull_total`, `alegra_connector_consumidor_final_probe`.

## [2.5.1] - 2026-09-25

> **Descarga de imágenes flexible.** Por defecto ya no se restringe el host de
> las imágenes de producto: se acepta cualquier host público por `http` o
> `https`, de modo que CDNs externos (S3/CloudFront) se descargan. La seguridad
> queda detrás de un switch.

### Changed

- **`is_allowed_image_url()` ahora es permisivo por defecto.** Se permite
  cualquier host público sobre `http` o `https` (antes sólo `https` de
  `alegra.com` y sus subdominios). Se conserva un guard SSRF siempre activo:
  `localhost`, `*.localhost`, `*.local`, `*.internal` y literales IP
  privados/reservados/loopback/link-local se rechazan siempre.
- La validación de mime de la imagen descargada se mantiene: un archivo que no
  sea imagen se rechaza igual.

### Added

- **Opción `alegra_connector_restrict_image_hosts`** (bool, default `false`).
  Al activarla se vuelve al comportamiento estricto: sólo se permiten los hosts
  de `allowed_image_hosts()` (`alegra.com` + lista extra). Checkbox en la
  pestaña Avanzado, junto a "Hosts de imágenes extra".

### Notes

- Sin cambio de esquema.
- `allowed_image_hosts()`, `alegra_connector_allowed_image_hosts_extra` y el
  filtro `alegra_connector_allowed_image_hosts` siguen existiendo: ahora definen
  la lista de restricción (sólo aplica con el switch activo).
- Opción nueva: `alegra_connector_restrict_image_hosts`.

## [2.5.0] - 2026-09-25

> **Observabilidad y recuperación del catálogo.** El Monitor ahora muestra los
> imports manuales y chunked (además del cron), "Detener" funciona, "Limpiar
> logs" borra TODO con confirmación, la ruta de logs es visible y el logger
> avisa si no puede escribir. **Cambio de comportamiento intencional:** "Limpiar
> logs" ya no borra solo los antiguos. Verificación de release:
> `docs/RELEASE_2.5.0_VERIFICATION.md`.

### Changed

- **"Limpiar logs" ahora borra TODOS los archivos de log** (incluido el del
  día) y reporta el conteo real. Antes solo borraba los más viejos que la
  retención, por lo que el botón parecía no funcionar. La retención automática
  (`alegra_connector_log_retention_days`) **no** se desactiva. La confirmación
  advierte que es irreversible.
- La página de **Logs** muestra la **ruta absoluta real** del directorio,
  siempre (aunque no haya archivos).

### Added

- **Monitor**: etiquetas de origen (`Cron`/`Manual`/`Chunked`/`Webhook`), badge
  `Abandonado` para runs sin finalizar, y estados vacíos/error honestos (no más
  "Cargando..." infinito). La sección Cron explica cuando la sincronización
  periódica está desactivada.
- **Logger**: si el directorio de logs no es escribible, el admin muestra un
  aviso con la ruta y el error (antes fallaba en silencio).

### Notes

- Sin cambio de esquema.
- `alegra_connector_sync_products` **mantiene** su default `false` (decisión G3,
  rama A): el cron no importa productos salvo toggle; el botón manual y los
  webhooks siguen funcionando igual.
- Opciones nuevas: `alegra_connector_chunked_page_budget`,
  `alegra_connector_allowed_image_hosts_extra`,
  `alegra_connector_products_import_total`, `alegra_connector_logger_write_failed`.

## [2.4.2] - 2026-09-21

> **Webhooks visibles en el admin (sin cambio de esquema).** La pregunta que
> bloqueaba el diseño de inventario —¿Alegra manda `edit-item` con
> `inventory.availableQuantity` al cambiar el stock?— ahora se responde desde
> `Alegra Connector → Webhooks`, sin scripts de desarrollo (el inspector CLI no
> viaja en el ZIP de release).

### Added

- **Pantalla "Webhooks" en el admin.** Un submenú nuevo muestra las últimas 50
  entregas registradas por el receptor: fecha, evento, entidad en una línea
  (`item 865 — "Camiseta azul"`), IP de origen, veredicto por entrega y el
  **payload crudo** (JSON) de cada una, plegable. Incluye filtro por evento
  (GET), un enlace **"Recargar"**, la nota de retención ("se guardan las últimas
  50 entregas") y un estado vacío explicativo.
- **Veredicto destacado.** Para `edit-item` muestra **SÍ/NO** sobre
  `message.item.inventory.availableQuantity` y un banner final ("Alegra SÍ
  envía inventario en edit-item" / "Alegra NO envía inventario en edit-item —
  la reconciliación debe ser por poll"). Si no hay ninguna entrega de
  `edit-item`, avisa y lista los 5 pasos de la prueba en vivo.
- **Acción "Limpiar"** protegida por nonce y `manage_woocommerce`, vía
  `admin-post.php` (POST-redirect-GET). Es la única escritura de la pantalla.

### Changed

- La lógica de inspección (veredicto, resumen de entidad,
  `has_inventory_available_quantity()`, `available_quantity()`) vive en
  `includes/Webhooks/Recorder.php` y la reusan **tanto** la pantalla de admin
  como el inspector CLI, para que no puedan divergir.

### Notes

- Sin cambio de esquema (`Schema::SCHEMA_VERSION` sigue en `2.3.1`). No hace
  falta re-registrar los webhooks.
- Todo el payload (dato remoto) se escapa con `esc_html()`/`esc_attr()`; el
  cuerpo ya viene truncado a 20 KB por el receptor y se marca `[TRUNCADO]`.

## [2.4.1] - 2026-09-21

> **Honesty + reliability patch (no schema change).** Four fixes that made the
> merchant see the wrong thing: the automatic reconciliation opened a draft
> invoice behind their back, a voided invoice rendered as a green "Facturado", a
> settled invoice (`closed`) could never complete its WooCommerce order, and the
> webhook URL carried a scheme Alegra rejects. Plus a webhook event selector and
> a read-only delivery inspector. **Re-register the webhooks after updating**
> (see the upgrade notes).

### Added

- **Webhook event selector.** All 12 events are still registered by default, so
  an upgrade changes nothing until the merchant touches it. Deselecting an event
  **unsubscribes** it in Alegra, and a deselected event delivered by a stale
  subscription is ignored (200 ACK + log).
- **Webhook delivery inspector.** The receiver keeps a bounded ring buffer of the
  last 50 deliveries (subject, raw body, timestamp, source IP) and
  `scripts/inspect-webhooks.php` prints them with a verdict on whether
  `edit-item` carries inventory data.
- A new **`alegra_connector_inventory_sync_enabled`** toggle (default **on**) so
  the inventory pull no longer depends on `sync_products`.

### Changed

- **The automatic payment reconciliation never opens a draft invoice.** The
  hourly sweep and the real-time hooks now skip a draft untouched — one order
  note, an info log and a new `draft_skipped` counter. Only the explicit manual
  actions ("Abrir factura", "Registrar pago") open a draft.
- **The inventory pull is decoupled from `sync_products`** and now sets
  `_stock_status`, so a zero-quantity product becomes `outofstock`.
- The release workflow **attaches the committed ZIP** (it no longer rebuilds it
  in CI) and fails if the artifact does not match its committed `.sha256`, so the
  published asset can be verified against the repo.

### Fixed

- **CRITICAL: the webhook URL is now sent scheme-less.** Alegra rejects a URL
  that includes `http://`/`https://` ("La URL ingresada no debe incluir el
  'http://' o 'https://'"), which made the registration fail 12/12. The receiver
  strips the scheme while keeping the `?token=` shared secret.
- **Voided invoices are shown honestly.** A void renders as a red **"Anulada en
  Alegra"** badge (it used to show a green "Facturado"), the order detail warns,
  and both the webhook and the poll add an order note (once).
- **A settled invoice is recognised by `closed`, not just `paid`.** Alegra
  documents the enum as `open/closed/draft/void` (`closed` = settled), so a paid
  invoice could never complete its WooCommerce order. The check is now
  centralised in `Invoice_Status::is_paid()` and accepts both.

### Upgrade notes

- **Re-register the webhooks** (Ajustes → desconectar/conectar, or the webhook
  section) so they point at the **scheme-less** URL and so the new **event
  selector** takes effect.
- A draft invoice is **no longer auto-opened** by the automatic reconciliation.
  Open it manually ("Abrir factura") if you want to charge it.
- The **inventory pull now runs independently** of "sync products" (new toggle,
  **on by default**).
- If you relied on the old behaviour where a **voided invoice showed as
  "Facturado"**, it now shows **"Anulada en Alegra"**.
- `Schema::SCHEMA_VERSION` is unchanged (`2.3.1`): this release has **no schema
  change**.
- See `docs/RELEASE_2.4.1_VERIFICATION.md` for the step-by-step live verification
  of the items above.

## [2.4.0] - 2026-09-21

> **Write-gate release (behavioural, with a migration).** Until now the kill
> switch and the per-entity toggles were decorative: the "disconnected" state
> still let manual admin/REST pushes reach Alegra, the cron ignored the "what to
> sync" checkboxes, and a refund in manual mode emitted a credit note behind your
> back. This release moves enforcement to a single choke point (`Write_Gate`),
> makes the settings tell the truth, and adds the manual credit-note action that
> replaces the now-gated automatic one. **Read the upgrade notes before updating.**

### Added

- **Central write enforcement.** The kill switch and the per-entity enablement
  are now enforced at a single choke point (`includes/Write_Gate.php`, wired into
  `Client::request()`), so no write reaches Alegra from any path — hooks, admin,
  REST, cron or dashboard render — while disconnected or while the entity is
  disabled.
- A new **`push_customers_enabled`** option, so customers can be pushed
  independently of products.
- **`payment_reconcile_enabled`** and **`payment_reconcile_batch`**, now
  registered and exposed in the dashboard (they were read but uncontrollable).
- A manual **"Emitir nota de crédito"** action on the order screen.

### Changed

- **The kill switch is now real.** With the plugin "disconnected", manual
  admin/REST pushes are blocked (they used to reach Alegra).
- **Manual mode (`push_orders_enabled=false`): a WooCommerce refund no longer
  automatically emits a credit note, and a payment-method change no longer
  updates the invoice.** Use the new **"Emitir nota de crédito"** button.
- **`payment_reconcile_enabled=false` stops BOTH** the hourly sweep and the
  real-time payment reconciliation.
- **Viewing the dashboard no longer creates the Consumidor Final contact**; it is
  created on the first invoicing.
- The four **"what to sync" checkboxes now show the real value** (they displayed
  checked while the cron treated them as off).

### Fixed

- Saving the Settings page no longer wipes the field/tax mappings.
- "Run now" / "Sincronizar ahora" explains why it cannot run instead of doing
  nothing.
- Disconnecting now actually deletes the webhook subscriptions in Alegra (a
  regression introduced by the new gate) and reports the real count.
- The chunked import flows now stop when the sync is cancelled.

### Upgrade notes (critical)

- The migration seeds **`push_customers_enabled` from `push_products_enabled`** so
  existing installs keep pushing customers; fresh installs get it off.
- If you relied on manual pushes working while "disconnected", they no longer do
  — that is the point of the kill switch.
- If you relied on automatic credit notes on refund in manual mode, use the new
  **"Emitir nota de crédito"** button.
- Re-save the payment account if the settings ever showed "Sin cuenta".
- `Schema::SCHEMA_VERSION` is unchanged (`2.3.1`): this release has **no schema
  change**; the migration only seeds an option.

## [2.3.11] - 2026-09-16

> **Payments reliability release.** The "Facturar" button on the order detail
> never registered a payment: it called the invoice-only path, so an already-paid
> order's invoice stayed **"Por Cobrar"** in Alegra. This release fixes that root
> cause, takes every payment datum from WooCommerce, and adds automatic
> reconciliation (event hooks + an hourly sweep) so a payment that arrives after
> the invoice is attached without the merchant clicking anything.

### Fixed

- **CRITICAL (crítico): "Facturar" never registered a payment.** The order-detail
  action mapped to `create_invoice()` — a code path with no payment logic — so
  the invoice was created and left **"Por Cobrar"** even when the order was
  already paid. "Facturar", bulk "Facturar seleccionados" and "Facturar
  pendientes" now reach `create_invoice_with_payment()`, which registers the
  payment from WooCommerce **when the order `is_paid()`**. An unpaid order still
  gets its invoice, with no payment and an explanatory order note.
- **Payment data now comes from WooCommerce, not the server date.** The `date`
  is `$order->get_date_paid()` (with a logged fallback to today when it is null),
  the `amount` is `get_total()`, and the `observations` carry the gateway title. A
  mismatch between the order total and the invoice balance is reported (note +
  log), never silently adjusted. The gateway→`paymentMethod` mapping is now a
  single resolver with one safe fallback (`transfer`), validated against the
  official Alegra enum; Mercado Pago maps to `credit-card`.
- **Reconciliation: a later payment now reaches an already-created invoice.**
  The reconcile hooks (`payment_complete`, `processing`, `completed`) are
  registered **outside** the `push_orders_enabled` gate (manual mode included),
  and an **hourly sweep** covers missed events. They never create an invoice;
  they only attach the payment through the same idempotent path (meta guard +
  pre-search + lock).
- **The payment-account `<select>` could silently overwrite a saved account with
  "Sin cuenta".** When the stored id was absent from `/bank-accounts`, no option
  was selected and the browser submitted `0`. The select now always renders the
  stored value (injecting a synthetic "Cuenta guardada (no sincronizada)" option
  when needed), so a stored value can no longer be lost.
- **The sanitizer no longer drops an invalid value in silence.** It keeps the
  previous value and registers a visible `settings_error`; the "Configuración
  guardada correctamente" banner is suppressed when a rejection occurred.
- **The checkout document-type select pre-selected "Registro Civil".** The
  classic and Blocks selects now start with a **"Seleccione…"** placeholder.
- **`ALEGRA_CONNECTOR_VERSION` was stuck at 2.3.7 while the header said 2.3.10**,
  so every upgrade served cached JS/CSS and new features never loaded. The
  constant now derives from the plugin header, so the two can never drift again.
- **The option label "Cuenta bancaria" was misleading** (Alegra's
  `/bank-accounts` returns **cajas** as well as banks). It is now **"Cuenta de
  destino para pagos (banco o caja)"**.

### Changed

- The release process is now consistent and reproducible:
  `scripts/build-release.sh` refuses to build when the header, README and
  `make-pot.php` versions disagree (exit 9), and a pushed `v*` tag publishes a
  GitHub Release with the ZIP and its SHA256 (`.github/workflows/release.yml`).
  The README points at the GitHub Releases page instead of the lexicographically
  sorted `releases/` folder, where `2.3.10` sorts after `2.3.1` and `2.3.9` is
  last.

### Notes for the merchant

- **Upgrade note:** if the payment-account select ever showed "Sin cuenta",
  re-save it (Ajustes → Avanzado). The select can no longer lose the value, but
  it cannot guess a value that was already overwritten.
- `Schema::SCHEMA_VERSION` is unchanged (`2.3.1`): this release has **no schema
  change**.
- The release process now tags and (when `gh` is available) publishes a Release;
  `v2.3.8`, `v2.3.9` and `v2.3.10` are back-filled as tags.

## [2.3.10] - 2026-09-18

> **Draft-invoice visibility.** When invoices are created as drafts, the order
> detail already shows the Alegra status; this release adds a button to open an
> existing draft from the plugin.

### Added

- **"Abrir factura (borrador)" button** on the order detail page. It appears only
  when the linked Alegra invoice is in `draft`; it opens it in Alegra
  (`POST /invoices/{id}/open`) so it stops being a draft and gets accounted.
  No-op (and hidden) when the invoice is already open.
- **The orders list now shows the real invoice status** (Pendiente / Borrador /
  Abierta / Pagada) instead of a generic "Facturado", and offers an **Abrir**
  button for drafts. The status is cached on the order (`_alegra_invoice_status`)
  when the invoice is created, opened or polled, so the list needs no per-row
  API call.
- The order's Alegra panel already displayed `Estado` (draft/open/paid); the
  button complements it.

### Notes for the merchant

- New invoices follow **Ajustes → Sincronización → "Estado de la factura"**
  (`draft` = borrador, `open` = abierta). This affects only new invoices.
- To stop using drafts: set that option to "Abierta". Existing drafts can be
  opened one by one from the order detail (or in Alegra).
- Recording a payment still opens a draft automatically, because Alegra only
  accepts payments on open invoices.

## [2.3.9] - 2026-09-18

> **Field-preservation release.** When Alegra is the source of truth, a
> re-import used to overwrite everything, including a description the merchant
> had extended in WooCommerce. You can now choose which fields to keep, from a
> single, unambiguous place: the plugin settings. This release also fixes a
> fatal when invoicing an order placed by a guest.

### Fixed

- **FATAL (crítico): invoicing a guest order threw a `TypeError`.**
  `WC_Order::get_user()` returns `WP_User|false` (`false` for a guest with
  `customer_id = 0`), and that `false` was passed to the `?WP_User` parameters
  of `collect_billing_values()`, `persist_contact_id()` and
  `add_consumidor_final_fallback_note()`. PHP 8 raised
  `Argument #2 ($customer) must be of type ?WP_User, bool given` and the
  invoice was never created. The customer is now normalized to `?WP_User`, so a
  guest order resolves to Consumidor Final (mode `auto`) as designed.

### Added

- **Preserve WooCommerce fields on update.** Mark any of: Descripción, Nombre,
  Precio, Imágenes, Inventario/stock, SKU/referencia. A marked field is never
  overwritten on an existing product.
- **Ajustes → Sincronización → "Al actualizar productos, conservar de
  WooCommerce"** (`alegra_connector_import_preserve_fields`). This single
  setting applies to every import path: manual (Productos), the "Importar"
  page, the per-product "Traer" button, the cron and the webhooks.
- The "Traer desde Alegra" modal links to that setting so the configuration is
  easy to find.

### Notes for the merchant

- The exclusion applies **only to products that already exist** in WooCommerce.
  New products are always created with all the data from Alegra.
- With no field marked, the behavior is identical to before (Alegra overwrites
  everything).
- If Alegra is your inventory source and you preserve "Inventario/stock", stock
  will stop syncing; that is intentional and shown in the setting description.

## [2.3.8] - 2026-09-18

> **Import filters release.** You can now choose *which* products to bring
> from Alegra instead of always pulling the whole catalog. The "Traer desde
> Alegra" button in the Products page opens a filter modal (category, type,
> status, inventory, text search); without filters the behavior is identical
> to before. This release also removes the dead "Traer desde Alegra" modal
> that was never reachable from the dashboard.

### Added

**Products import filters**

- **New filter modal in the Products page.** The "Traer desde Alegra" button
  opens a modal with: category (`idItemCategory`, single), type
  (sencillos/combos/con variantes), status (default/activos/inactivos),
  inventory (solo con inventario) and free-text search (name or reference).
  Two actions: **Aplicar y traer** (applies the filters) and **Traer todo sin
  filtros** (the previous behavior).
- **Backend plumbing** (`Admin_Dashboard::ajax_sync_start` /
  `ajax_sync_page`): a single helper translates filters into the documented
  Alegra `GET /items` query params. The metadata total reflects the explicit
  filters, so the progress bar matches what is actually imported.
- **New endpoint** `alegra_get_item_categories` to populate the category
  selector (paginated with `start`/`limit`, up to 10 pages).
- **`variantParent` filter** is applied client-side: the Alegra API only
  documents `type=simple|kit`, so "Con variantes" walks the catalog and
  discards non-variant items. The modal warns that the total is approximate.

### Changed

- The Products page button label is now "Traer desde Alegra" (it opens the
  filter modal). The dashboard quick-sync buttons are untouched.

### Removed

- The dead `#alegra-sync-modal` markup and its JS handlers. Nothing opened
  it; the dashboard "Traer productos" flow already used the progress modal.

### Notes for the merchant

- With no filters selected, the import sends exactly the same parameters as
  before this release (verified by test T21.1).
- Cron, webhooks and the "Importar" page are unaffected: filters apply only
  to the manual import from the Products page.

## [2.3.7] - 2026-09-16

> **⚠️ Webhook reliability release.** A `new-client` webhook crashed the
> receiver with a fatal (HTTP 500), and every delivery counted as a failure —
> which matters because Alegra **deletes a subscription after 10 consecutive
> failures**. On top of that, re-registering the webhooks (the expected action
> right after updating) reported **"12 errores"** even when everything was
> already correctly registered. Both are fixed.

### 🐛 Fixed

**Webhooks**

- **FIX (crítico): a `new-client` webhook crashed with a fatal.** The docs send
  `name` as an **object** (`{firstName, lastName}` / `{fullname}`), but the
  plugin passed it to a function expecting a **string** → `TypeError` → **HTTP
  500**. Every client webhook counted as a failure, and since Alegra **deletes a
  subscription after 10 consecutive failures**, the client webhooks would have
  deleted themselves. The name parsing now accepts a string, the documented
  object, and `fullname`.
- **FIX (crítico): a malformed body, an unknown `subject`, or a non-array
  `message` returned a non-2XX**, which counted toward the 10-failure deletion.
  They now return **200 (ignored)**. Only an authentication failure returns 401.
- **FIX: a `Throwable` inside a handler returned 500.** It is now logged and
  acked with **200**, so a bug in one event can no longer make Alegra delete the
  subscription.
- **FIX: webhook registration reported "12 errores" on a re-register.** Alegra
  returns **400** `"Ya existe una suscripción con el mismo evento y URL"` when a
  subscription already exists. Already-registered subscriptions are no longer
  counted as errors; the result now reports `creados`, `ya_existian` and
  `errores` separately, and the local subscription ids are preserved so the
  DELETE flow keeps working.

### 📝 Notes for the merchant

- **The handshake was verified working.** Alegra POSTs an **empty body** to
  verify the URL and requires a **2XX in under 5 seconds**; the endpoint answers
  it (no token required for an empty body).
- **Handlers are synchronous.** A slow Alegra API call inside a handler could
  exceed the **5-second** budget and count as a failure. This is a known
  limitation, not fixed in this release.

### ✅ Upgrade Notes

- **Re-register the webhooks** (Alegra Connector → Configuración → pestaña
  Avanzado → "Sincronización en Tiempo Real (Webhooks)") so they point at the
  URL that carries the security token. **Re-registering is now safe and
  idempotent:** if the subscriptions already exist, the result says how many
  already existed instead of reporting errors.

## [2.3.6] - 2026-09-16

> **⚠️ This is a large correctness release.** Four fix batches from a
> functional-readiness analysis. The headline: **stock never landed in
> WooCommerce** — the merchant's original report — because the plugin never set
> `_manage_stock`, and the commercial product category was never assigned
> because it was sent under Alegra's *accounting* category field. Both are fixed,
> along with the customer import lock, order totals with shipping, and an
> unauthenticated webhook endpoint.

### 🐛 Fixed

**Products**

- **FIX (crítico): the item payload sent the commercial category under the wrong
  field.** The push put the item-category id under `category`, which is Alegra's
  **accounting** category, instead of `itemCategory` (the commercial one). The
  commercial category was therefore never assigned, and the import read the
  accounting category, creating WooCommerce categories named after accounts.
  Both directions now use `itemCategory`.
- **FIX (crítico): stock never appeared in WooCommerce — the root cause of the
  original report.** The import wrote `_stock` without ever setting
  `_manage_stock`, and WooCommerce ignores `_stock` unless stock management is
  enabled; `_stock_status` was never set either. The plugin now enables stock
  management and sets the status for inventoriable items (services are left
  untouched; variable parents are forced to `_manage_stock=no`).
- **FIX: the import overwrote stock without checking `inventory_source`.** A
  store configured with WooCommerce as the inventory source still had its stock
  overwritten by the Alegra import. The write is now gated on the configured
  source.
- **FIX: the dedicated inventory pull existed but had no caller.** It is now
  wired to the cron and to a manual action, gated on `inventory_source`, and
  honours the kill switch.

**Customers**

- **FIX (crítico): the customer import was completely broken by a double lock.**
  Both the cron and the manual button could not proceed. Replaced with a
  re-entrant lock.
- **FIX (crítico): Consumidor Final was never created.** If the Alegra account
  did not already have it, no customer without an identification could be
  invoiced. It is now created automatically and idempotently.
- **FIX (crítico): a Colombia contact needs `regime` + `kindOfPerson` when the
  account has e-invoicing.** They were missing, so the contact create failed and
  the plugin silently fell back to Consumidor Final. They are now always sent
  (configurable under *Datos fiscales del contacto (Colombia)*), and a failure is
  surfaced with the real reason in an order note instead of being swallowed.
- **FIX: skipped contacts were counted as errors** by the import. They are no
  longer reported as failures.
- **FIX: users are now also deduplicated by identification**, not only by email.
- **FIX: `conflict_resolution = alegra_wins` now actually pulls the name and
  email** from Alegra instead of keeping the WooCommerce values.

**Orders**

- **FIX (crítico): a partial-refund credit note sent an item with no `id`**,
  which the API requires, so the request returned a 400. The item id is now
  included.
- **FIX (crítico): shipping and fees were not mapped**, so an order with shipping
  produced an invoice smaller than the order total. They are now mapped and the
  invoice total equals the order total.
- **FIX: taxes were silently dropped when no tax mapping was configured.** They
  are now derived from the WooCommerce rate, idempotently.
- **FIX: void sent `reason` instead of the documented `cause`.**
- **FIX: a failed payment was swallowed.** It is now logged and added as an order
  note.
- **FIX: "Registrar pago" on a draft invoice failed.** The invoice is now opened
  first, then the payment is registered.
- **FIX: automatic-mode failures were invisible.** An order note now carries the
  real reason.
- **FIX: the `'0'` payment-account guard is now consistent** across paths.

**Orchestration**

- **FIX (seguridad): the webhook endpoint had no authentication.** Alegra cannot
  sign its webhooks, so anyone could POST to the endpoint and trigger syncs or
  complete orders. It now requires a shared secret in the URL, enforced with
  `hash_equals`, and fails closed.
- **FIX (crítico): webhooks were never created.** Alegra POSTs an empty body to
  verify a new subscription URL and requires a 2XX response in under 5 seconds;
  the plugin returned 400, so registration never completed. The verification
  handshake is now acknowledged.
- **FIX: webhook registration always reported 0** because it read the wrong
  response shape. It now reads the nested subscription id.
- **FIX: "Run now" permanently destroyed the recurring cron.**
  `wp_clear_scheduled_hook` removed the recurrence and nothing rescheduled it.
  Fixed, plus a self-heal on init.
- **FIX: "Skip next" also killed the recurrence.** It now moves the run forward
  and keeps the schedule.
- **FIX: the wizard redirected after headers were sent.**
- **FIX: the per-run "Stop" button was ineffective mid-import.** It now halts the
  products, customers and categories loops.

### ✅ Upgrade Notes

- **Re-register the webhooks after updating.** Existing subscriptions point at
  the old URL without the secret token and will be rejected by the new
  authentication. Go to **Alegra Connector → Configuración → pestaña Avanzado →
  sección "Sincronización en Tiempo Real (Webhooks)"** and click **"Registrar
  webhooks en Alegra"**.
- **Products with no `manage_stock` will now have stock management enabled** when
  the inventory source is Alegra. This is the fix (without it WooCommerce ignores
  the stock), but it means WooCommerce will start tracking stock for those
  products.
- **If the merchant uses taxes, the plugin may now create the corresponding taxes
  in Alegra** (idempotently) when no tax mapping is configured.

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
- See `docs/RELEASE_2.4.1_VERIFICATION.md` for the assumptions that still require a live API test (this guide was renamed from `RELEASE_2.4.0_VERIFICATION.md`, itself renamed from `RELEASE_2.3.7_VERIFICATION.md`).

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
