# Release 2.1.8 — Test Server Deployment Guide

This document walks you through uploading and verifying release **2.1.8** of
Alegra Connector on the test server. Follow each step in order.

---

## Pre-flight (1 min)

```bash
# 1. Confirm the ZIP is on your local workstation
ls -la alegra-connector-v2.1.8.zip alegra-connector-v2.1.8.zip.sha256

# Expected:
#   alegra-connector-v2.1.8.zip          ~154 KB
#   alegra-connector-v2.1.8.zip.sha256   ~94 bytes

# 2. Verify SHA-256 (optional but recommended)
sha256sum alegra-connector-v2.1.8.zip
# Compare against the .sha256 sidecar:
cat alegra-connector-v2.1.8.zip.sha256
```

Expected SHA-256: `d17c2bd70d6203f3b76f76ddf082c19c9d596328d9790711fbbf1e0424388cbe`

---

## Step 1 — Run the smoke-test on YOUR server (2 min, requires PHP)

Before uploading anything, validate the ZIP locally with PHP. This catches
the 2.1.7 regression class without touching the server.

```bash
# Requires PHP 8.0+ and unzip
bash scripts/smoke-test.sh dist/alegra-connector-v2.1.8.zip
```

**Expected output** (last line):
```
=== SMOKE-TEST OK: all assertions passed ===
SMOKE OK
```

If you see `SMOKE FAILED`, **DO NOT upload the ZIP** — paste the output back
and we'll debug. The 6 assertions are:

1. No fragile `require_once` of `logger/Logger/Logger.php` in `alegra-connector.php`
2. `Alegra\Connector\Logger\Logger` is reachable via autoloader alone
3. `Alegra\Connector\Alegra_Connector` singleton loads
4. `logger/Logger/Logger.php` exists on disk inside the ZIP
5. `logger/` directory tree is complete (`index.php` placeholders + `Logger.php`)
6. Every `.php` in the ZIP passes `php -l` syntax check

---

## Step 2 — Recover the broken 2.1.7 install (5 min, server-side)

The current production install (`alegra-connector-v2.1.7`) is broken: it has
no `logger/` directory. Even after uploading 2.1.8, you should remove the
broken install first OR overwrite it.

**Option A — overwrite in place (recommended):**

```bash
# SSH or cPanel terminal to the server
cd /home/tuntvxpm/public_html/wp-content/plugins/

# If the broken dir is named with version suffix:
ls -d alegra-connector*    # confirm what's there

# Either rename the broken dir to a backup name (keeps it as rollback)
mv alegra-connector-v2.1.7 alegra-connector-v2.1.7-BROKEN-BACKUP-$(date +%Y%m%d)

# OR delete it (your call — you said keep as backup, so rename is safer)
```

**Option B — install 2.1.8 alongside and deactivate old one:**

The WordPress plugin system will get confused if two plugins claim the same
slug. **Use Option A.**

---

## Step 3 — Upload the 2.1.8 ZIP (5 min, server-side)

### 3a. Upload the ZIP

Via cPanel File Manager:
1. Navigate to `/home/tuntvxpm/public_html/wp-content/plugins/`
2. Upload `alegra-connector-v2.1.8.zip` to that directory
3. Right-click the ZIP → **Extract**
4. This creates `alegra-connector/` (the plugin slug directory)
5. **Delete the ZIP** from the server after extracting (cleanup)

Via SFTP/SCP:
```bash
scp dist/alegra-connector-v2.1.8.zip user@server:/tmp/
ssh user@server
cd /home/tuntvxpm/public_html/wp-content/plugins/
unzip /tmp/alegra-connector-v2.1.8.zip
rm /tmp/alegra-connector-v2.1.8.zip
```

### 3b. Verify on disk

```bash
ls /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/
ls /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/logger/Logger/
```

**Expected:**
- Top-level dir has `alegra-connector.php`, `uninstall.php`, `includes/`, `admin/`, `public/`, `templates/`, `logger/`, `languages/`
- `logger/Logger/` has `Logger.php` AND `index.php`
- `logger/` has `index.php`

If `Logger.php` is missing, the upload was incomplete — re-upload.

---

## Step 4 — Activate and watch debug.log (3 min)

### 4a. Activate

1. Open `https://<test-site>/wp-admin/`
2. Go to **Plugins**
3. Find **Alegra Connector** — should show version **2.1.8**
4. Click **Activate**
5. **Watch for redirect errors.** If you see "The plugin could not be activated because it triggered a fatal error" — STOP and check Step 5.

### 4b. Tail debug.log during activation

Open a second SSH session to the server:

```bash
tail -F /home/tuntvxpm/public_html/wp-content/debug.log | grep -E '(PHP Fatal|PHP Parse|Uncaught|alegra-connector)'
```

Click **Activate** in the WP admin.

**Expected:**
- Zero new error lines.
- The plugin row shows "Alegra Connector — version 2.1.8 — active".

If you see errors, **paste the stack trace** — that's the regression coming
back, and we'll dig in.

---

## Step 5 — Post-activation smoke (5 min)

### 5a. Open the admin dashboard

Navigate to: `https://<test-site>/wp-admin/admin.php?page=alegra-connector`

**Expected:**
- HTTP 200 (no 500)
- Header: "Alegra Connector"
- Quick status cards visible (Conexión, Procesos, Pushes, Última sync)
- No PHP errors in browser console (F12 → Console tab)

### 5b. Click "Test connection"

Click the **Test connection** button in the dashboard.

**Expected:**
- AJAX returns 200
- Response is JSON: `{success: true, ...}` OR `{success: false, message: "..."}` (the second is fine — credentials not configured yet)
- No PHP fatal in `debug.log`

### 5c. Configure credentials

If 5b returned "credentials not configured":
1. Go to **Alegra Connector → Settings**
2. Enter your Alegra API email + token
3. Click **Test connection** again
4. Expected: `{success: true, company: "...", country: "..."}`

---

## Step 6 — 5-minute debug.log monitor

In the SSH session from Step 4b:

```bash
# Keep tailing for 5 minutes; trigger normal admin activity
tail -F /home/tuntvxpm/public_html/wp-content/debug.log | grep -E '(PHP Fatal|PHP Parse|Uncaught|alegra-connector)'
```

In WP admin, exercise these pages (click each, give it 30 seconds):
1. Dashboard
2. Products (let it load)
3. Customers
4. Orders
5. Settings (without saving)
6. Logs
7. Back to Dashboard

**Expected over 5 minutes:** Zero `PHP Fatal`, zero `Uncaught`, zero error lines
mentioning `alegra-connector`. Only the pre-existing unrelated noise
(Elementor deprecations, WP `SCRIPT_NAME`, WC `Commands out of sync` — see
the `## Notas` section).

---

## Rollback (if something goes wrong)

The previous broken installs are still on the server (`alegra-connector-v2.1.6`,
`alegra-connector-v2.1.7-1`, optionally `alegra-connector-v2.1.7-BROKEN-BACKUP-*`).

To roll back to 2.1.7:

1. **Deactivate 2.1.8** in WP admin → Plugins
2. Delete the `alegra-connector/` dir (the 2.1.8 install)
3. Rename the backup back: `mv alegra-connector-v2.1.7-BROKEN-BACKUP-* alegra-connector`
4. **NOTE:** the rollback to 2.1.7 will re-trigger the original fatal error
   unless you also seed the `logger/` directory (one-time fix).

To seed the `logger/` directory on 2.1.7 if you need it temporarily:

```bash
cd /home/tuntvxpm/public_html/wp-content/plugins/alegra-connector/

# Create the directories
mkdir -p logger/Logger

# Create placeholder index.php files (WordPress convention)
echo '<?php // Silence is golden.' > logger/index.php
echo '<?php // Silence is golden.' > logger/Logger/index.php

# Upload logger/Logger/Logger.php from the 2.1.8 ZIP
unzip -p dist/alegra-connector-v2.1.8.zip alegra-connector/logger/Logger/Logger.php > logger/Logger/Logger.php

# Set ownership (cPanel often requires this)
chown -R $(stat -c '%U:%G' alegra-connector.php) logger/
```

After this, 2.1.7 will also activate without fatal — until you upgrade to 2.1.8.

---

## Success criteria checklist

- [ ] `bash scripts/smoke-test.sh dist/alegra-connector-v2.1.8.zip` exits 0
- [ ] Uploaded to server, `logger/Logger/Logger.php` present in `wp-content/plugins/alegra-connector/`
- [ ] Plugin shows as **version 2.1.8** in WP admin → Plugins
- [ ] Activation does NOT produce a "fatal error" message
- [ ] 5-minute debug.log monitor shows zero new errors mentioning `alegra-connector`
- [ ] Admin dashboard renders (HTTP 200, status cards visible)
- [ ] "Test connection" AJAX responds 200 with JSON

When all checkboxes are ✅, the release is deployed.

---

## Notas (informational, NOT blocking)

- **Pre-existing log noise** (not caused by Alegra Connector):
  - `PHP Warning: Undefined array key "SCRIPT_NAME" in wp-includes/load.php:1337`
    — comes from WP core when WP-Cron runs without a normal request context.
    Fixed in WP 6.4+; safe to ignore on WP 5.8.
  - `Elementor\Modules\GlobalClasses\Atomic_Global_Styles::get_cache_root_key()` deprecation
    — Elementor plugin compat with PHP 8.1+; not our code.
  - `WordPress database error Commands out of sync` — Action Scheduler / WC
    background processing; unrelated.
  - `Error de evento de reprogramación de cron` (jetpack) — Jetpack cron lock
    contention on shared hosting; unrelated.

- The old `require_once __DIR__ . '/logger/Logger/Logger.php';` line that caused
  the 2.1.7 activation fatal is **GONE** in 2.1.8. The new PSR-4 autoloader has
  a dedicated fast-path for the `Alegra\Connector\Logger\*` namespace. If a
  future release somehow ships without `logger/`, you'll see a Spanish
  admin notice in `/wp-admin/` and the plugin auto-deactivates — instead of
  a hard fatal.

- If anything goes wrong, the rollback section above restores 2.1.7 quickly.
  But for 2.1.7 to activate without fatal, you also need to seed `logger/`
  using the one-time script provided.
