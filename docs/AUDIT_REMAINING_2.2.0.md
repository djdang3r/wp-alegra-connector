# Audit: remaining files — security + robustness scan

**Date**: 2026-09-10
**Method**: Full read of each file, scanning for: (1) SQL injection, (2) file ops from user input, (3) silent error swallowing, (4) state mutation without locks, (5) sensitive data in logs.

## includes/Sync/Categories.php

1. **SQL injection**: 1 call at line 167:
   ```php
   $term_id = $wpdb->get_var($wpdb->prepare(
       "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'alegra_category_id' AND meta_value = %d LIMIT 1",
       $alegra_id
   ));
   ```
   `$wpdb->prepare()` with `%d`; `$alegra_id` is `int` typed in the signature. **SAFE**.

2. **File ops**: None. No `file_get_contents`, `fopen`, `unlink`, `readfile`.

3. **Error handling**: No try/catch. Operations bubble errors as `WP_Error`. `is_wp_error()` checks throughout. **No silent swallowing**.

4. **State mutation without locks**: `import_from_alegra` (lines 90-124) loops over pages without any transient lock — concurrent runs can both read from Alegra. `sync_all` (lines 59-88) also lockless. **Concern but not vulnerability**: at worst this duplicates imported terms, and the existing `wp_insert_term` will return existing term if name collides. Race window is bounded to import duration. The outer AJAX layer (Admin_Dashboard.php:2532) acquires a per-type sync lock, which provides the protective boundary. **Acceptable**.

5. **Sensitive logging**: `$this->logger->info()` / `->error()` with structured context arrays containing `term_id`, `alegra_id`, error messages only. No tokens, passwords, or credentials. **SAFE**.

## includes/Push_Queue.php

1. **SQL injection**: 8 `$wpdb` calls (lines 39, 59, 73, 78, 91, 109, 127, 154):
   - Line 39: `$wpdb->insert($table, [...])` — array form, WP escapes. **SAFE**.
   - Lines 59, 73, 78: `$wpdb->get_results($wpdb->prepare(...))` with `%s`/`%d`. **SAFE**.
   - Lines 91, 109, 127: `$wpdb->update($table, [...], [...])` — array form, WP escapes. **SAFE**.
   - Line 154: `$wpdb->insert($table, [...])` — array form. **SAFE**.

2. **File ops**: None.

3. **Error handling**: No try/catch. `wpdb->insert/update` return `false` on failure but the result is silently cast to `(bool)` in `approve()` / `reject()` / `mark_applied()` — caller cannot distinguish "no row matched WHERE" from "DB error". **Minor P3 (defensive)**: the silent bool return makes queue corruption silent. Recommend wrapping with `is_wp_error()` or logging on `false`. Not a security issue.

4. **State mutation without locks**: `enqueue()` (line 34-50), `approve()` (line 87-100), `reject()` (line 105-118), `mark_applied()` (line 123-136), `log_push()` (line 141-166) — all individual row-level mutations. Concurrent workers could race on `enqueue()` (two cron ticks enqueueing the same WC order), creating duplicate queue rows. The `UPDATE ... WHERE id = X AND status = 'pending'` clauses in `approve`/`reject` make state transitions atomic. **Acceptable** — duplicate queue rows are a UX issue not a security one.

5. **Sensitive logging**: No direct logger usage. `log_push()` writes the full request/response payload to `wp_alegra_push_log` table — but this is the explicit purpose of the audit log. No tokens/credentials are written (verified: only `entity_type`, `entity_id`, `action`, `request_payload`, `response_payload`, `http_code`, `error_message` are persisted). **SAFE**.

## includes/Heartbeat.php

1. **SQL injection**: None. Only `set_transient()` / `get_transient()` / `delete_transient()`. **N/A**.

2. **File ops**: None.

3. **Error handling**: No try/catch needed (pure transient operations). Functions return `?array` or `void`. **No silent swallowing**.

4. **State mutation without locks**: Transient `set_transient('alegra_run_' . $run_id, ..., 120)` (line 42) — WordPress transient writes are atomic at DB level (uses `add_option()` / `update_option()` with internal locking). **Acceptable**. Per-request static cache (`self::$cache`) on top of the transient — provides fast repeated reads within the same request.

5. **Sensitive logging**: No logging. **N/A**.

## includes/Runs.php

1. **SQL injection**: 6 `$wpdb` calls (lines 54, 71, 99, 141, 148, 162):
   - Line 54: `$wpdb->insert(...)` — array form. **SAFE**.
   - Lines 71, 99: `$wpdb->update(...)` — array form with `%d`/`%s`/`%f` format specifiers. **SAFE**.
   - Lines 141, 148: `$wpdb->get_results($wpdb->prepare(...))` with `%s`/`%d`. **SAFE**.
   - Line 162: `$wpdb->get_results("SELECT * FROM $table WHERE status = 'running' ORDER BY started_at DESC")` — `$table` is internal WP global. **SAFE**.

2. **File ops**: None.

3. **Error handling**: 1 try/catch in `track()` (line 37-40):
   ```php
   } catch (\Throwable $e) {
       self::finish($run_id, 'failed', $e->getMessage());
       throw $e;
   }
   ```
   Catches, records failure in runs table, then **re-throws**. Error is NOT silently swallowed. Upstream caller sees the exception. **GOOD pattern**.

4. **State mutation without locks**: `start()` / `update_progress()` / `finish()` are individual row mutations — atomic at DB level. The runs table is a log, not a mutex. The actual per-type sync mutex lives in `Controller::acquire_sync_lock()` (transient-based, out of scope). **Acceptable**.

5. **Sensitive logging**: `error_summary` truncated to 500 chars (line 106) before storage in DB. Could in theory contain user-controlled data from API error messages, but only readable by admins via the Monitor UI. `started_by` truncated to 100 chars (line 58). **Acceptable risk**.

## logger/Logger/Logger.php (FULL)

1. **SQL injection**: None. **N/A**.

2. **File ops**:
   - Line 33: `@file_put_contents($htaccess, ...)` — internal path from `wp_upload_dir()`. **SAFE**.
   - Line 39: `@file_put_contents($index, ...)` — internal path, static content. **SAFE**.
   - Line 72: `fopen($this->log_file, 'a')` — `$this->log_file` constructed in constructor from `wp_upload_dir()['basedir'] . '/alegra-logs/alegra-sync-' . date('Y-m-d') . '.log'`. No user input. **SAFE**.
   - Line 81: `fwrite($handle, $log_entry)` — formatted entry from `$message` + `wp_json_encode($context)`.
   - Line 80: `flock($handle, LOCK_EX)` — intentional post-2.2.0 fix (was `LOCK_EX | LOCK_NB`, now blocking for guaranteed writes). Per task instructions, not flagged.
   - Lines 129, 150, 233: `glob(...)` patterns — static `'*.log'` glob. **SAFE**.
   - Line 135: `unlink($file)` — `$file` comes from `glob()` of internal dir. **SAFE**.
   - Line 166: `fopen($file, 'r')` — `$file` from `glob()` of internal dir. **SAFE**.
   - Line 227: `readfile($file_path)` — `$file_path = $this->log_dir . '/' . basename($filename)` (line 217). `basename()` strips path traversal. **SAFE** even with hostile user input.
   - Line 287: `fopen($filepath, 'r')` — `$filepath` is `WP_CONTENT_DIR . '/debug.log'` or user-supplied `WP_DEBUG_LOG` constant. WP core configures this; not user-controllable from plugin context. **SAFE**.

3. **Error handling**:
   - Line 92-95: `catch (\Throwable $e) { error_log('[Alegra Logger] ' . $e->getMessage() . ' | ' . trim($log_entry)); }` — catches all logging failures and falls back to PHP `error_log()`. This is **intentional design**: logger failures must never break the import flow. The fallback message is loud (includes both error reason AND the dropped entry), so log loss is visible in `wp-content/debug.log`. **GOOD pattern**.
   - Line 167-169: `if ($handle === false) continue;` — silent skip on `get_logs()` file open fail. Minor **P3** (could happen on transient permission flip); the loop continues and the user just sees fewer entries. Acceptable.

4. **State mutation without locks**: `flock($handle, LOCK_EX)` blocking lock on the log file (line 80). Per task instructions, the LOCK_NB → LOCK_EX fix in 2.2.2 is intentionally excluded from this audit's findings.

5. **Sensitive logging**: The logger writes `$message` + `wp_json_encode($context)` to disk. The 5 audited files were scanned for any token / password / secret / credential / api_key string passed into `$this->logger->*()` — **none found**. The class itself doesn't introduce any credential exposure; the responsibility for not logging secrets lies with the callers, and the audited files comply.

## Summary

| File | SQL inj | File ops | Error swallow | Locking | Logging | Verdict |
|------|---------|----------|---------------|---------|---------|---------|
| `includes/Sync/Categories.php` | ✓ SAFE | ✓ N/A | ✓ None | ✓ OK | ✓ Clean | **No issues** |
| `includes/Push_Queue.php` | ✓ SAFE | ✓ N/A | ⚠ P3 (silent bool) | ✓ Atomic | ✓ Clean | **1 P3** |
| `includes/Heartbeat.php` | ✓ N/A | ✓ N/A | ✓ None | ✓ Atomic | ✓ N/A | **No issues** |
| `includes/Runs.php` | ✓ SAFE | ✓ N/A | ✓ Rethrows | ✓ Atomic | ✓ Truncated | **No issues** |
| `logger/Logger/Logger.php` | ✓ N/A | ✓ Safe paths | ✓ Loud fallback | ✓ LOCK_EX | ✓ Clean | **No issues** |

- Total files: 5
- Clean files: 4
- Files with findings: 1 (`includes/Push_Queue.php`)
- Total P0 findings: 0
- Total P1 findings: 0
- Total P2 findings: 0
- Total P3 findings: 1

### P3 detail (Push_Queue.php)

`approve()`, `reject()`, `mark_applied()` return `(bool) $wpdb->update(...)`. On DB failure the method returns `false` and the caller (`Admin_Dashboard.php:2097-2118`, `2149`) treats it as "row not found" without distinguishing from "DB error". Recommend: log via `$this->logger->error()` when `false` is returned for non-zero row counts, or use `$wpdb->last_error` to surface DB-side failures. Not a release blocker.
