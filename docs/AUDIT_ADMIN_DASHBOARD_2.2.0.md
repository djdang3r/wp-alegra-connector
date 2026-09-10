# Audit: Admin_Dashboard.php lines 1119+ — SQL injection scan

**Date**: 2026-09-10
**File**: admin/Admin/Admin_Dashboard.php
**Scope**: lines 1119 to end of file (line 2908)
**Method**: Read each `$wpdb->*` call, classify by safety

## Findings

No SQL injection vectors found.

### Per-call classification

| # | Line | Call type | Verdict | Notes |
|---|------|-----------|---------|-------|
| 1 | 1641 | `$wpdb->get_col` | SAFE | Static SQL: `SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@placeholder.local'`. No user input. |
| 2 | 1905 | `$wpdb->query` | SAFE | Static SQL: `DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_alegra\_%' ... ESCAPE '\\'`. No user input. |
| 3 | 2044 | `$wpdb->query` | SAFE | Identical static SQL to #2 (emergency_stop handler). |
| 4 | 2053 | `$wpdb->query` | SAFE | Static SQL: `UPDATE {$wpdb->prefix}alegra_runs SET status = 'killed', finished_at = NOW() WHERE status = 'running'`. Only `$wpdb->prefix` (WP global) interpolated. |
| 5 | 2092 | `$wpdb->get_row` | SAFE | `$wpdb->prepare("SELECT * FROM {$wpdb->prefix}alegra_push_queue WHERE id = %d AND status = 'pending'", $queue_id)` — `$queue_id` is `(int) ($_POST['queue_id'] ?? 0)` cast at line 2088. |
| 6 | 2560 | `$wpdb->get_var` | SAFE | Static count query on `{$wpdb->posts}`. |
| 7 | 2566 | `$wpdb->get_var` | SAFE | `$orders_table` from `HPOS::get_orders_table()` (internal WC API, not user input). Static WHERE clause. |
| 8 | 2570 | `$wpdb->get_var` | SAFE | Static count query on `{$wpdb->posts}`. |
| 9 | 2575 | `$wpdb->get_var` | SAFE | Only `$wpdb->users`, `$wpdb->usermeta`, `$wpdb->prefix` (internal WP globals) interpolated. Static LIKE pattern `'%customer%'`. |
| 10 | 2579 | `$wpdb->get_var` | SAFE | Static count query on `{$wpdb->term_taxonomy}`. |
| 11 | 2710 | `$wpdb->get_results` | SAFE | Static SELECT joining `{$wpdb->postmeta}` and `{$wpdb->posts}`. |
| 12 | 2759 | `$wpdb->get_var` | SAFE | `$wpdb->prepare("SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $keep_id)`. `$keep_id` is `(int)` cast at line 2751. |
| 13 | 2830 | `$wpdb->get_results` | SAFE | Static SELECT with NOT EXISTS subquery. |
| 14 | 2891 | `$wpdb->get_var` | SAFE | Static count on `{$wpdb->posts}`. |
| 15 | 2894 | `$wpdb->get_var` | SAFE | Static count on `{$wpdb->postmeta}`. |
| 16 | 2897 | `$wpdb->get_var` | SAFE | Static count on `{$wpdb->postmeta}`. |

## Summary
- Total queries reviewed: 16
- SAFE: 16
- SUSPICIOUS: 0
- VULNERABLE: 0

**Verdict**: CLEAN — no SQL injection vectors found in lines 1119–2908.

- 14 of 16 calls are fully static SQL strings with no user-derived variables.
- 2 of 16 calls (`$wpdb->get_row` line 2092, `$wpdb->get_var` line 2759) use `$wpdb->prepare()` with `%d` placeholders, with the bound integer explicitly cast via `(int)` before being passed in.
- All `{$wpdb->*}`, `{$wpdb->prefix}`, `{$wpdb->options}`, `{$wpdb->posts}`, `{$wpdb->users}`, `{$wpdb->usermeta}`, `{$wpdb->postmeta}`, `{$wpdb->term_taxonomy}`, `{$wpdb->termmeta}` interpolations reference WordPress core globals — these are not user-controlled.
