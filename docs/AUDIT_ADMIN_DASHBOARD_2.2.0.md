# Audit Report — `admin/Admin/Admin_Dashboard.php` (lines 1119–end)

**Scope**: SQL injection vectors in every `$wpdb->query`, `->get_results`, `->get_var`, `->get_row`, `->get_col`, `->insert`, `->update`, `->delete` call from line 1119 to end of file (line 2908).

**File size**: 2908 lines total → 1790 lines audited.
**Total `$wpdb->*` calls in audited range**: 16 (after filtering to ≥1119).
**Findings**: 0 SQL injection vectors found. **All calls are SAFE.**

---

## Methodology

1. `grep -n '\$wpdb->(query|get_results|get_var|get_row|get_col|insert|update|delete)'` to enumerate every call.
2. Filtered matches to lines ≥ 1119.
3. Read each call site (10–30 lines of context) to inspect the SQL string and identify any variable interpolation.
4. Classified each call as:
   - **SAFE** (static SQL with no user input, or `$wpdb->prepare()` with `%s`/`%d`/`%f` placeholders).
   - **VULNERABLE** (interpolation from `$_GET`/`$_POST`/request without placeholder).

---

## Per-call audit (lines 1119–2908)

| Line | Call | Verdict | Detail |
|------|------|---------|--------|
| 1640 | `$wpdb->get_col("SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@placeholder.local'")` | **SAFE** | Static SQL with hardcoded LIKE pattern. No user input. |
| 1905 | `$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_alegra\\_%' OR option_name LIKE '_transient_timeout_alegra\\_%' ESCAPE '\\\\'")` | **SAFE** | Static SQL. `{$wpdb->options}` is internal WP global. |
| 2044 | `$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_alegra\\_%' OR option_name LIKE '_transient_timeout_alegra\\_%' ESCAPE '\\\\'")` | **SAFE** | Same pattern as line 1905 (duplicate in emergency_stop handler). |
| 2053 | `$wpdb->query("UPDATE {$wpdb->prefix}alegra_runs SET status = 'killed', finished_at = NOW() WHERE status = 'running'")` | **SAFE** | Static SQL. `{$wpdb->prefix}` is internal WP global. |
| 2092 | `$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alegra_push_queue WHERE id = %d AND status = 'pending'", $queue_id))` | **SAFE** | `prepare()` with `%d`. `$queue_id` is `(int) ($_POST['queue_id'] ?? 0)` — explicitly cast to int at line 2088. |
| 2560 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'")` | **SAFE** | Static SQL. |
| 2566 | `$wpdb->get_var("SELECT COUNT(*) FROM {$orders_table} WHERE type='shop_order' AND status IN ('wc-processing','wc-completed','wc-pending','wc-on-hold')")` | **SAFE** | `$orders_table` is the return value of `HPOS::get_orders_table()` (internal WP/WC API). Not user input. |
| 2570 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-processing','wc-completed','wc-pending','wc-on-hold')")` | **SAFE** | Static SQL. |
| 2575 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID=um.user_id AND um.meta_key='{$wpdb->prefix}capabilities' WHERE um.meta_value LIKE '%customer%'")` | **SAFE** | `$wpdb->users`, `$wpdb->usermeta`, `{$wpdb->prefix}capabilities` are all internal WP globals. `LIKE '%customer%'` is static. |
| 2579 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'")` | **SAFE** | Static SQL. |
| 2710 | `$wpdb->get_results("SELECT pm.post_id, pm.meta_value, p.post_parent FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment' WHERE pm.meta_key = '_alegra_image_url'")` | **SAFE** | Static SQL. `{$wpdb->postmeta}` and `{$wpdb->posts}` are internal. |
| 2759 | `$wpdb->get_var($wpdb->prepare("SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $keep_id))` | **SAFE** | `prepare()` with `%d`. `$keep_id` originates from a `foreach` loop over `$attachments` (post IDs cast to int). |
| 2830 | `$wpdb->get_results("SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment' WHERE pm.meta_key = '_alegra_image_url' AND NOT EXISTS (...)")` | **SAFE** | Static SQL including subquery. |
| 2891 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'")` | **SAFE** | Static SQL. |
| 2894 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_image_url_hash'")` | **SAFE** | Static SQL. |
| 2897 | `$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_image_url'")` | **SAFE** | Static SQL. |

---

## Summary

**No SQL injection vectors found in lines 1119–end of `admin/Admin/Admin_Dashboard.php`.**

All 16 audited `$wpdb->*` calls are safe:
- **9 calls** use static SQL strings with no user input.
- **2 calls** use `$wpdb->prepare()` with `%d` placeholders.
- **5 calls** use static SQL with only `{$wpdb->postmeta}`, `{$wpdb->posts}`, `{$wpdb->options}`, `{$wpdb->users}`, `{$wpdb->usermeta}`, `{$wpdb->prefix}`, `{$wpdb->term_taxonomy}` interpolations — all are internal WordPress table-name globals, never user-controlled.

No P0/P1/P2/P3 findings. No fixes required for this file in 2.2.0.
