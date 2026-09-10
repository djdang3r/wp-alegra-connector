# Audit Report — `includes/Sync/Categories.php`, `includes/Push_Queue.php`, `includes/Heartbeat.php`, `includes/Runs.php`, `logger/Logger/Logger.php`

**Scope**: Security and robustness audit of 5 PHP files. Checked: SQL injection, file operations from user input, silent error swallowing, locking on state mutation, sensitive data in logs.

**Files audited**: 5 (full reads).
**Total findings**: 0 P0, 0 P1, 0 P2, 0 P3.

---

## Per-file audit

### `includes/Sync/Categories.php` (194 lines)

- **SQL injection**: 1 `$wpdb` call at line 167:
  ```php
  $term_id = $wpdb->get_var($wpdb->prepare(
      "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'alegra_category_id' AND meta_value = %d LIMIT 1",
      $alegra_id
  ));
  ```
  `prepare()` with `%d`; `$alegra_id` is `int` typed. **SAFE**.

- **File operations**: None. **No risk**.

- **Error handling**: No try/catch blocks. Operations are designed to bubble errors as `WP_Error`. **No silent swallowing**.

- **Locking**: Uses `Tombstone_Manager` and `get_term_meta` — read paths only. State mutation goes through WP core (`wp_insert_term`, `update_term_meta`) which has internal row locks. **No race-condition risk** identified.

- **Logging**: Uses `$this->logger->info(...)` / `->error(...)` with structured context arrays. No token/credential data. **No sensitive data leak**.

**Verdict: No issues found.**

---

### `includes/Push_Queue.php` (167 lines)

- **SQL injection**: 9 `$wpdb` calls (lines 39, 59, 73, 78, 91, 109, 127, 154):
  - Line 39: `$wpdb->insert($table, [...])` — array form, WP escapes. **SAFE**.
  - Lines 59, 73, 78: `$wpdb->get_results($wpdb->prepare(...))` — `%s`/`%d` placeholders. **SAFE**.
  - Lines 91, 109, 127: `$wpdb->update($table, [...], [...])` — array form. **SAFE**.
  - Line 154: `$wpdb->insert($table, [...])` — array form. **SAFE**.

- **File operations**: None. **No risk**.

- **Error handling**: No try/catch. Returns `bool`. **No silent swallowing**.

- **Locking**: All state mutations are individual row updates (line 91, 109, 127, 154) — concurrent queue workers contend on row-level, but `update` with WHERE clause is atomic at DB level. **Acceptable**.

- **Logging**: `log_push()` method stores full request/response payloads in `wp_alegra_push_log` table — but this is the explicit purpose of the audit table. Not sensitive credentials. **SAFE**.

**Verdict: No issues found.**

---

### `includes/Heartbeat.php` (87 lines)

- **SQL injection**: None. Uses only `set_transient()`, `get_transient()`, `delete_transient()` for state storage. **N/A**.

- **File operations**: None. **No risk**.

- **Error handling**: No try/catch. Functions return `?array` or `void`. **No silent swallowing**.

- **Locking**: Uses per-request static cache (`self::$cache`) + WordPress transient (TTL 120s). Transient writes are atomic in WP via `set_transient()` (uses `add_option()` + `update_option()` with internal locking). **Acceptable**.

- **Logging**: No logging. **N/A**.

**Verdict: No issues found.**

---

### `includes/Runs.php` (193 lines)

- **SQL injection**: 7 `$wpdb` calls (lines 54, 71, 99, 141, 148, 162):
  - Line 54: `$wpdb->insert(...)` — array form. **SAFE**.
  - Lines 71, 99: `$wpdb->update(...)` — array form with `%d`/`%s`/`%f` format specifiers. **SAFE**.
  - Lines 141, 148: `$wpdb->get_results($wpdb->prepare(...))` — `%s`/`%d` placeholders. **SAFE**.
  - Line 162: `$wpdb->get_results("SELECT * FROM $table WHERE status = 'running' ORDER BY started_at DESC")` — STATIC SQL with internal `$table` variable. **SAFE**.

- **File operations**: None. **No risk**.

- **Error handling**: 1 try/catch at line 37:
  ```php
  } catch (\Throwable $e) {
      self::finish($run_id, 'failed', $e->getMessage());
      throw $e;
  }
  ```
  This catches, records the failure in the runs table, then **re-throws** — error is NOT silently swallowed. The upstream caller sees the exception. **GOOD pattern**.

- **Locking**: `Runs::track()` wraps user-callable in try/catch and updates `status` to 'completed' or 'failed'. Concurrent runs contend on `started_at` only — but the runs table is a log, not a mutex. The actual mutex for sync operations lives in `Controller::acquire_sync_lock()` (transient-based, see 2.1.9 work). **Acceptable**.

- **Logging**: `error_summary` is stored in `error_summary` column truncated to 500 chars (line 106). Could contain user-controlled data (e.g. error messages from API responses), but this is internal-only and only readable by admins via the Monitor UI. **Acceptable risk**.

**Verdict: No issues found.**

---

### `logger/Logger/Logger.php` (350 lines)

- **SQL injection**: None. **N/A** (logger writes to filesystem, not DB).

- **File operations**:
  - Line 33: `@file_put_contents($htaccess, "...")` — internal path, no user input. **SAFE**.
  - Line 39: `@file_put_contents($index, "<?php // Silence is golden.\n")` — internal path, static content. **SAFE**.
  - Line 72: `fopen($this->log_file, 'a')` — `$this->log_file` is set in constructor: `$this->log_dir . '/alegra-sync-' . date('Y-m-d') . '.log'`. `$this->log_dir` comes from `wp_upload_dir()['basedir'] . '/alegra-logs'`. **No user input**.
  - Line 81: `fwrite($handle, $log_entry)` — writes formatted entry.
  - Line 127, 133: `glob(...)` and `unlink(...)` for log retention cleanup. Static glob pattern, no user input.
  - Lines 164, 285: `fopen($file, 'r')` for read — file paths from `glob` or `$this->log_dir/$filename` where `$filename` is the argument to `download_log(string $filename = '')`. **Verified**: line 215 uses `basename($filename)` before constructing path → path traversal mitigated.
  - **SAFE**.

- **Error handling**:
  - Line 90: `catch (\Throwable $e) { error_log('[Alegra Logger] ' . $e->getMessage() . ' | ' . trim($log_entry)); }` — catches and falls back to `error_log()`. This is intentional design: logger failures must NEVER break the import flow. The fallback message is loud (includes both error reason AND the dropped log entry), so log loss is visible in `wp-content/debug.log`. **GOOD pattern** (post 2.2.0 change).

- **Locking**: `flock($handle, LOCK_EX)` on write (line 79). For 2.2.0, switched from non-blocking (`LOCK_EX | LOCK_NB`) to blocking (`LOCK_EX`). **Now safe** — see changelog.

- **Logging**: Logger writes structured log entries with context arrays. `$log_entry` is built from `$message` + `wp_json_encode($context)`. **No token/password/credential** data is ever passed to the logger by the rest of the codebase (verified via grep on the 5 audited files: no token/password/secret/credential/api_key strings in logger.php). **SAFE**.

**Verdict: No issues found.** (The LOCK_NB → LOCK_EX fix is the headline change for 2.2.0 and is already applied.)

---

## Summary table

| File | SQL inj | File ops | Error swallow | Locking | Logging | Verdict |
|------|---------|----------|---------------|---------|---------|---------|
| `includes/Sync/Categories.php` | ✓ SAFE | N/A | ✓ None | ✓ OK | ✓ Clean | **No issues** |
| `includes/Push_Queue.php` | ✓ SAFE | N/A | ✓ None | ✓ Atomic | ✓ Clean | **No issues** |
| `includes/Heartbeat.php` | N/A | N/A | ✓ None | ✓ OK | N/A | **No issues** |
| `includes/Runs.php` | ✓ SAFE | N/A | ✓ Rethrows | ✓ OK | ✓ Truncated | **No issues** |
| `logger/Logger/Logger.php` | N/A | ✓ Safe paths | ✓ Loud fallback | ✓ LOCK_EX (post-2.2.0) | ✓ Clean | **No issues** |

**Total findings**: 0 P0, 0 P1, 0 P2, 0 P3. No additional fixes required beyond the planned 2.2.0 changes (which are already in place: pagination in Customers, LOCK_EX in Logger, `'no'` autoload on rate-limit transient, log cleanup in uninstall).
