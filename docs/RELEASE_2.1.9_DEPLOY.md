# Release 2.1.9 — Test Server Deployment Guide

This document walks you through upgrading from release **2.1.8** to
**2.1.9** of Alegra Connector on the test server. 2.1.9 is a **safe upgrade
on top of a working 2.1.8 install** — no broken install to recover this
time. Follow each step in order.

---

## Pre-flight (1 min)

```bash
# 1. Confirm the 2.1.9 ZIP is on your local workstation
ls -la releases/alegra-connector-v2.1.9.zip releases/alegra-connector-v2.1.9.zip.sha256

# 2. Verify SHA-256 (optional but recommended)
sha256sum releases/alegra-connector-v2.1.9.zip
# Compare against the .sha256 sidecar:
cat releases/alegra-connector-v2.1.9.zip.sha256

# 3. Confirm the server is currently on 2.1.8 (NOT a broken 2.1.7 install)
ssh user@server
cd /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector
grep -n "Version:" alegra-connector.php | head -1
# Expected: Version: 2.1.8
grep -n "ALEGRA_CONNECTOR_VERSION" alegra-connector.php | head -1
# Expected: define('ALEGRA_CONNECTOR_VERSION', '2.1.8');

# 4. Confirm WP debug logging is enabled
grep "WP_DEBUG_LOG" /home/tuntvxpm/public_html/wp-config.php
# Expected: define('WP_DEBUG_LOG', true);
```

If 2.1.8 is **NOT** the active version, this guide does not apply — back up
and use `docs/RELEASE_2.1.8_DEPLOY.md` first.

---

## Step 1 — Run the smoke-test on YOUR server (2 min, requires PHP)

Before uploading, validate the ZIP locally. This catches packaging
regressions without touching the server.

```bash
cd /home/wilfredo/projects/Alegra-Connector/wp-alegra-connector
bash scripts/smoke-test.sh releases/alegra-connector-v2.1.9.zip
```

**Expected output** (last line):
```
=== SMOKE-TEST OK: all assertions passed ===
SMOKE OK
```

If you see `SMOKE FAILED`, **DO NOT upload the ZIP** — paste the output back
and we'll debug. The 8 assertions are:

1. No fragile `require_once` of `logger/Logger/Logger.php` in `alegra-connector.php`
2. `Alegra\Connector\Logger\Logger` is reachable via autoloader alone
3. `Alegra\Connector\Alegra_Connector` singleton loads
4. `logger/Logger/Logger.php` exists on disk inside the ZIP
5. `logger/` directory tree is complete (`index.php` placeholders + `Logger.php`)
6. Every `.php` in the ZIP passes `php -l` syntax check (added in 2.1.8)
7. `get_auth_header()` in `includes/API/Client.php` has NO `error_log()` call (2.1.9 regression guard)
8. `handle_delete_item()` in `includes/Webhooks/Handlers.php` does NOT call `delete_post_meta(..., '_alegra_item_id', ...)` — must use the tombstone path (2.1.9 regression guard)

---

## Step 2 — Upload the 2.1.9 ZIP (5 min)

### 2a. Recommended: WP Admin "Subir plugin" (no SSH needed)

The upgrade path is the same as a fresh install — WordPress handles version
detection and replacement automatically. Because the destination directory
is already named `alegra-connector/` (slug-based, not version-based), the
new ZIP overwrites the old files in place.

1. Open `https://<test-site>/wp-admin/`
2. Sidebar → **Plugins** → **Añadir nuevo**
3. Click the **Subir plugin** tab
4. Click **Elegir archivo** → select `alegra-connector-v2.1.9.zip`
5. Click **Instalar ahora**
6. WordPress will:
   - Detect the existing 2.1.8 install of the same plugin
   - Replace the files in `wp-content/plugins/alegra-connector/` in place
     (does NOT deactivate first — the new code only loads at next request)
   - Show "Subiendo e instalando el plugin actualizado..." → "Plugin actualizado correctamente."
7. **Do NOT click "Activar plugin" yet** — go to Step 3 to verify on disk first.

If you see "El archivo subido excede la directiva `upload_max_filesize`",
fall back to method 2b below.

### 2b. Fallback: cPanel File Manager

1. cPanel → File Manager → navigate to `/home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/`
2. **Important:** delete the following 2.1.8-era files that 2.1.9 obsoletes
   (the new ZIP will replace them, but if you upload via File Manager the
   overwrite behavior is order-dependent):

   ```bash
   cd /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/
   ```

   Skip this step when uploading the full ZIP via File Manager — the
   extraction will overwrite them in place. Skip and proceed to the upload.

3. Upload `alegra-connector-v2.1.9.zip` to `/home/tuntvxpm/public_html/wp-content/plugins/`
4. Right-click the ZIP → **Extract** → choose `alegra-connector/` as the
   target. If you extract to `/tmp/` instead, copy the resulting
   `alegra-connector/` folder over the existing one:

   ```bash
   # extract to staging
   cd /tmp
   unzip /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector-v2.1.9.zip

   # sync the new files into the live plugin dir
   rsync -av /tmp/alegra-connector/ \
     /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/

   # cleanup
   rm -rf /tmp/alegra-connector /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector-v2.1.9.zip
   ```
5. Continue to Step 3 (activate via WP admin).

### 2c. Last-resort: SFTP / SCP

```bash
# Push the new files onto the server, preserving the existing dir.
scp releases/alegra-connector-v2.1.9.zip user@server:/tmp/
ssh user@server
cd /home/tuntvxpm/public_html/wp-content/plugins/
unzip -o /tmp/alegra-connector-v2.1.9.zip -d /tmp/alegra-staging
rsync -av --delete /tmp/alegra-staging/alegra-connector/ \
  /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/
rm -rf /tmp/alegra-staging /tmp/alegra-connector-v2.1.9.zip
```

---

## Step 3 — Verify on disk (before activating)

```bash
ssh user@server
cd /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/

# 1. plugin header reflects 2.1.9
grep -n "Version:" alegra-connector.php | head -1
# Expected: Version: 2.1.9

grep -n "ALEGRA_CONNECTOR_VERSION" alegra-connector.php | head -1
# Expected: define('ALEGRA_CONNECTOR_VERSION', '2.1.9');

# 2. critical files present
ls -d logger logger/Logger docs/RELEASE_2.1.9_DEPLOY.md releases/ 2>/dev/null
ls includes/{Tombstone_Manager.php,API/Client.php,Webhooks/Handlers.php,Sync/Controller.php,Sync/Products.php,Sync/Customers.php}

# 3. NO token-leak — the 2.1.9 fix MUST be present
grep -n "error_log" includes/API/Client.php | grep -i "auth\|token\|email" | head -3
# Expected: NO output (the error_log is gone from get_auth_header)

# 4. tombstone path — handle_delete_item uses Tombstone_Manager
grep -n "Tombstone_Manager::create" includes/Webhooks/Handlers.php
# Expected: at least one match
```

**Expected:** everything matches. If any item is missing, the upload was
incomplete — re-upload.

---

## Step 4 — Activate (1 min)

In WP admin:

1. Sidebar → **Plugins** → **Plugins instalados**
2. Find **Alegra Connector** — the row should show **2.1.9 disponible**,
   with a "Actualizar" link (because 2.1.9 was installed via "Subir plugin"
   on top of an active 2.1.8). **Click "Activar"** (or the "Activar plugin"
   link in the row's hover menu).

   Or, if WP shows it already as inactive after the upload-overwrite:

3. Click **Activar**.
4. **Watch for redirect errors.** If you see "El plugin no pudo activarse
   porque disparó un error fatal" — STOP and go to Step 5.

---

## Step 5 — Tail debug.log during activation (2 min)

Open a second SSH session to the server:

```bash
tail -F /home/tuntvxpm/public_html/wp-content/debug.log | grep -E '(PHP Fatal|PHP Parse|Uncaught|alegra-connector)'
```

Click **Activar** in the WP admin.

**Expected over the next 30 seconds:**
- Zero `PHP Fatal` lines.
- Zero `PHP Parse` lines.
- Zero `Uncaught` exceptions.
- The plugin row in **Plugins instalados** now reads "Alegra Connector
  — version 2.1.9 — active".

If you see errors, **paste the stack trace** — that's a regression, and
we'll dig in.

---

## Step 6 — Post-activation smoke (3 min)

### 6a. Open the admin dashboard

Navigate to: `https://<test-site>/wp-admin/admin.php?page=alegra-connector`

**Expected:**
- HTTP 200 (no 500)
- Header: "Alegra Connector"
- Quick status cards visible (Conexión, Procesos, Pushes, Última sync)
- No PHP errors in the browser console (F12 → Console tab)

### 6b. Click "Test connection"

Click the **Test connection** button in the dashboard.

**Expected:**
- AJAX returns 200
- Response is JSON: `{success: true, ...}` if credentials are configured,
  OR `{success: false, message: "..."}` (the second is fine — credentials
  not configured yet)
- No PHP fatal in `debug.log`
- No `error_log` mention of `"get_auth_header"` or `"auth header"` (the
  2.1.9 leak guard — Step 7.1 below details this)

---

## Step 7 — Verify the 4 NEW behaviors (10 min)

This is the core of the 2.1.9 release. Each subsection has a concrete
copy-paste command.

### 7.1 Token-log leak fix

The 2.1.9 fix removed an `error_log()` call in `get_auth_header()` that
logged token/email length info. Verify on disk:

```bash
ssh user@server
cd /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/

# This MUST return 0 — the function body must not contain an error_log call.
grep -c "error_log" includes/API/Client.php
# Expected: 0

# If it returns > 0, double-check it isn't a pre-existing comment:
grep -n "error_log" includes/API/Client.php
# Each line should be inside /* ... */ or // comments, NOT inside get_auth_header().
```

**Manual sanity check** in PHP:

```php
php -r 'require "includes/API/Client.php"; $r = new ReflectionMethod("Alegra\\Connector\\API\\Client", "get_auth_header"); echo $r->getFileName() . ":" . $r->getStartLine() . "-" . $r->getEndLine() . PHP_EOL;'
# Expected: shows the line range for inspection.
```

Then trigger an auth failure (clear credentials → click "Test connection"
→ expect a graceful failure message, not a leaked token-length line in
debug.log):

```bash
tail -F /home/tuntvxpm/public_html/wp-content/debug.log &
# In WP admin: clear the API email field, click "Guardar", then "Test connection"
sleep 5
grep -i "get_auth_header\|token\|auth header" \
  /home/tuntvxpm/public_html/wp-content/debug.log | tail -10
# Expected: NO mentions of "get_auth_header", "token length", "len=", etc.
```

### 7.2 Tombstone on `delete-item` webhook

Send a test webhook from Alegra (or simulate via `curl`):

```bash
# Simulate a delete-item webhook (replace the HMAC if your setup uses one)
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Alegra-Event: item.deleted" \
  -d '{"event":"item.deleted","resource":{"id":"ITEM_ID","name":"Test product"}}' \
  https://<test-site>/wp-json/alegra-connector/v1/webhook
```

Then verify the tombstone row in the database:

```bash
ssh user@server
# Replace 'wp_' with your prefix if different
wp db query "SELECT id, entity_type, entity_id, reason, created_at FROM wp_alegra_tombstones WHERE reason='alegra_deleted' ORDER BY created_at DESC LIMIT 5;"
# Expected: at least one row with reason='alegra_deleted' (within the last 5 minutes).
```

Or via phpMyAdmin: open `wp_alegra_tombstones` → filter `reason` →
`alegra_deleted` → confirm a row exists with the recent timestamp.

If no row appears, check the webhook logs:

```bash
grep -E "webhook|delete-item|delete_item|tombstone" \
  /home/tuntvxpm/public_html/wp-content/debug.log | tail -20
# Expected: a log line like "Webhook delete-item received, wrote tombstone for item ITEM_ID"
```

### 7.3 Sync lock — concurrent syncs abort cleanly

```bash
# Open a WP admin session. Schedule a one-minute cron to trigger a sync.
ssh user@server
cd /home/tuntvxpm/public_html/
wp eval '
$next = wp_next_scheduled("alegra_connector_cron_sync");
if (!$next) {
    wp_schedule_event(time() + 5, "alegra_connector_5min", "alegra_connector_cron_sync");
    echo "Scheduled next run at " . date("H:i:s", time() + 5) . PHP_EOL;
} else {
    echo "Next run already at " . date("H:i:s", $next) . PHP_EOL;
}
'
```

In WP admin → Alegra Connector → Dashboard, immediately click **Sincronizar
ahora** (or the "Ejecutar" button on a cron row). The two should collide.

Watch the response:

- Manual AJAX response: `{success: false, message: "Another sync is in progress..."}`
- OR the manual run shows the error in the UI

Check debug.log:

```bash
grep -E "sync_lock|Sync.*in progress|sync_running" \
  /home/tuntvxpm/public_html/wp-content/debug.log | tail -10
# Expected: lines mentioning the transient 'alegra_sync_running_{type}' lock
```

### 7.4 Inventory pagination — >30 products sync

This requires a store with more than 30 Alegra products. If your test store
has fewer, create a few temporary items in Alegra first.

```bash
ssh user@server
cd /home/tuntvxpm/public_html/

# Trigger the inventory sync via WP-CLI
wp eval '
do_action("alegra_sync_inventory_from_alegra");
echo "Triggered alegra_sync_inventory_from_alegra" . PHP_EOL;
'

# Or, equivalently, click the manual sync button in WP admin → Products.
```

Wait ~30–120 seconds (depending on network and rate limit) and check the
sync ran to completion:

```bash
tail -F /home/tuntvxpm/public_html/wp-content/debug.log | grep -E "inventory|page [0-9]+|limit 30|processed" &
sleep 90
# Expected: log lines showing pagination, e.g. "page 1 (30 items)", "page 2 (28 items)" etc.
```

Verify the WC product count matches the Alegra count:

```bash
wp eval 'echo wp_count_posts("product")->publish . PHP_EOL;'
# Compare against the Alegra UI's product count.

# Verify there are no orphans: each WC product must have its _alegra_item_id meta.
wp eval '
$args = ["post_type"=>"product","posts_per_page"=>-1,"fields"=>"ids"];
$ids = get_posts($args);
$missing = 0;
foreach ($ids as $id) {
    if (!get_post_meta($id, "_alegra_item_id", true)) $missing++;
}
echo "products=" . count($ids) . " missing_meta=" . $missing . PHP_EOL;
'
# Expected: missing_meta=0 (or equal to local-only products).
```

---

## Step 8 — 5-minute debug.log monitor (5 min)

In the SSH session from Step 5:

```bash
# Keep tailing for 5 minutes; trigger normal admin activity
tail -F /home/tuntvxpm/public_html/wp-content/debug.log | grep -E '(PHP Fatal|PHP Parse|Uncaught|alegra-connector)'
```

In WP admin, exercise these pages (give each ~30 s to load):

1. Dashboard
2. Products
3. Customers
4. Orders
5. Settings (without saving)
6. Monitor de procesos
7. Cola de push
8. Logs (newest entries)
9. Back to Dashboard

**Expected over 5 minutes:**
- Zero `PHP Fatal`.
- Zero `Uncaught`.
- Zero error lines mentioning `alegra-connector`.
- Only the pre-existing unrelated noise (Elementor deprecations, WP
  `SCRIPT_NAME`, WC `Commands out of sync` — see `## Notas` below).

---

## Step 9 — Rollback (if something goes wrong)

2.1.9 is **additive over 2.1.8** — the four changes all roll forward cleanly,
no destructive operations:

| Change | Additive behavior |
|---|---|
| Token-log removal | Just stops writing one log line — no schema, no data |
| Tombstone on delete-item | Writes a new row in `wp_alegra_tombstones` — 2.1.8 ignores `reason='alegra_deleted'` |
| Sync lock | Uses a transient (`alegra_sync_running_*`) — 2.1.8 doesn't read this transient, so harmless leftover |
| Inventory pagination | Same `sync_inventory_from_alegra()` action — 2.1.8 just stops after 30 products |

Rolling back is therefore safe at any time:

1. **Deactivate 2.1.9** in WP admin → Plugins → deactivate "Alegra Connector"
2. Delete or rename `wp-content/plugins/alegra-connector/` (the 2.1.9 install)
3. Upload `alegra-connector-v2.1.8.zip` the same way (Step 2)
4. **Activate** — 2.1.8 will pick up where 2.1.9 left off, including any
   `wp_alegra_tombstones` rows written under `reason='alegra_deleted'`
   (which 2.1.8 simply ignores — they're inert).

No data corruption on rollback.

---

## Success criteria checklist

- [ ] `bash scripts/smoke-test.sh releases/alegra-connector-v2.1.9.zip` exits 0
- [ ] Uploaded to server, `includes/API/Client.php` and `includes/Webhooks/Handlers.php` updated
- [ ] `grep "Version:" alegra-connector.php` returns **2.1.9**
- [ ] `define('ALEGRA_CONNECTOR_VERSION', '2.1.9')` confirmed on disk
- [ ] Activation does NOT produce a "fatal error" message
- [ ] 5-minute debug.log monitor shows zero new errors mentioning `alegra-connector`
- [ ] Admin dashboard renders (HTTP 200, status cards visible)
- [ ] "Test connection" AJAX responds 200 with JSON
- [ ] **7.1** — `grep -c "error_log" includes/API/Client.php` returns **0**
- [ ] **7.2** — A tombstone row appears in `wp_alegra_tombstones` with `reason='alegra_deleted'` after a delete-item webhook
- [ ] **7.3** — Manual + cron sync collide; one aborts with "Another sync is in progress"
- [ ] **7.4** — Inventory sync processes >30 products on a store that has them

When all checkboxes are ✅, the release is deployed.

---

## Notas (informational, NOT blocking)

### What changed from 2.1.8 to 2.1.9

The 2.1.8 → 2.1.9 diff is fully additive and low-risk:

- **`includes/API/Client.php:50-52`** — removed a `error_log()` inside
  `get_auth_header()` that was leaking token/email length info to PHP's
  error stream. The function now returns `'Basic '` silently when
  credentials are missing; auth failures are surfaced through the
  structured logger only.

- **`includes/Webhooks/Handlers.php:76-110`** — `handle_delete_item()`
  now calls `Tombstone_Manager::create(..., 'alegra_deleted')` instead of
  `delete_post_meta($post_id, '_alegra_item_id', ...)`. The previous
  behavior could lose product linkage when a concurrent sync pull was
  creating the same product. Tombstones block future re-imports.

- **`includes/Sync/Controller.php`** — new `acquire_sync_lock()` /
  `release_sync_lock()` helpers around the cron sync. Lock is a transient
  `alegra_sync_running_{type}` with a 5-minute TTL. Public static wrappers
  expose the lock for cross-class use.

- **`includes/Sync/Products.php`** — `sync_inventory_from_alegra()`
  paginates up to 200 pages × 30 items = 6000 items per run. Previously
  hardcoded `limit: 30` and silently truncated.

- **`includes/Sync/Customers.php`** — wraps `import_from_alegra()` in
  the same transient lock.

- **`admin/Admin/Admin_Dashboard.php`** — manual AJAX sync handlers
  respect the transient lock and return a clean error to the JS front-end.

### Pre-existing log noise (not caused by Alegra Connector)

- `PHP Warning: Undefined array key "SCRIPT_NAME" in wp-includes/load.php:1337`
  — comes from WP core when WP-Cron runs without a normal request context.
  Fixed in WP 6.4+; safe to ignore on WP 5.8.
- `Elementor\Modules\GlobalClasses\Atomic_Global_Styles::get_cache_root_key()`
  deprecation — Elementor plugin compat with PHP 8.1+; not our code.
- `WordPress database error Commands out of sync` — Action Scheduler / WC
  background processing; unrelated.
- `Error de evento de reprogramación de cron` (jetpack) — Jetpack cron lock
  contention on shared hosting; unrelated.

### Why this is a "safe upgrade"

None of the 2.1.9 changes alter the public schema, kill-switch behavior,
existing transients, or any data already written by 2.1.8. Rolling back
to 2.1.8 simply leaves the new tombstones and the unused lock transient
in place — both are inert under 2.1.8. Pagination becomes a 30-item
cap again under 2.1.8, which was the previous behavior.

If you'd like a more conservative staging path: test on a staging clone
first by uploading 2.1.9 over a fresh 2.1.8 install. The 8 smoke-test
assertions should all pass without any server-side state change.
