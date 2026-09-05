# Changelog

All notable changes to Alegra Connector.

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
