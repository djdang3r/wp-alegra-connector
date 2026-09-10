# Audit Report — `templates/admin-*.php` (XSS / unescaped output)

**Scope**: All `templates/admin-*.php` files. Audit every `echo`, `print`, `<?= ?>` of user-controlled data without `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, `esc_textarea()`, or `esc_url_raw()`.

**Files audited**: 16 (total 2623 LOC).
**Total echo / `<?= ?>` sites inspected**: 120 across 15 files (admin-monitor.php has only JS `$()` jQuery selectors, not PHP output).

---

## Methodology

1. `grep -n 'echo \$\|<?=\s*\$'` across all `templates/admin-*.php` to enumerate every variable-output site.
2. For each match, read 5–30 lines of surrounding context to identify:
   - Is the variable pre-escaped (e.g. `$code = esc_attr(...)` before echo)?
   - Or is it raw user data (post title, option value, request param) output directly?
3. Classified each as:
   - **SAFE** (escaped with WP escaping fn, or static value, or boolean comparison).
   - **VULNERABLE** (raw user data to HTML without escaping).

---

## Per-file audit summary

### `admin-orders.php` (55 lines)
- Line 14: `<?php echo $total_orders>0?esc_html(round(($synced_orders/$total_orders)*100,1).'%'):'0%';?>` — math + esc_html. **SAFE**
- Lines 25-27: `<?php echo $filter==='all'?'ac-btn-primary':'';?>` — boolean literal comparison. **SAFE**
- Line 50: `'<span class="ac-badge...">'.esc_html__(...).'</span>'` — pre-escaped translation. **SAFE**
- All other echoes pre-escape variables or use `esc_html_e()` / `esc_html()`. **No XSS**.

### `admin-import.php` (159 lines)
- Lines 126-148: matches are JavaScript `$()` jQuery selectors, not PHP output. **No PHP XSS**.
- All other echoes use `esc_html()` or `esc_attr()`. **SAFE**

### `admin-settings.php` (251 lines)
- Lines 105-106: `$code = esc_attr($cur['code'] ?? ''); $name = esc_html(...)` — pre-escaped in foreach before echo. **SAFE**
- Lines 167-168: bank accounts — `$id = (int)(...)` then `esc_attr($id)` / `esc_html(...)`. **SAFE**
- Lines 183-184: payment terms — same pattern. **SAFE**
- Line 230: `$ev = esc_html(...); $sid = esc_html(...);` pre-escaped before echo. **SAFE**
- All webhook secrets (line 210) wrapped in `esc_attr()`. **SAFE**

### `admin-logs.php` (137 lines)
- Line 59-61: `$bc`, `$rowBg`, `$rowBorder` are static CSS string builders (no user input). **SAFE**
- Line 63: `<?php echo esc_html($e['level']);?>` — escaped. **SAFE**
- Line 82, 89-92, 107, 109: all use `esc_html()` on log entries. **SAFE**

### `admin-docs.php` (145 lines)
- Line 81: `preg_match(...)` — PHP code, not output. **SAFE**

### `admin-wizard.php` (150 lines)
- Lines 40-43: `<?php echo $i <= $step ? 'var(--ac-success)' : 'var(--ac-warning)';?>` — boolean literal comparison. **SAFE**
- Line 111: `data-step="<?php echo $step; ?>"` — `$step` is cast `(int)` before reaching template (verified in caller). **SAFE**

### `admin-products.php` (264 lines)
- Line 188: `$detail_url = esc_url(admin_url(...))` — pre-escaped. **SAFE**
- Lines 178-183: `$ti` built from `esc_html__()` only. **SAFE**
- Lines 191-199: `$alegra_cell`, `$status_cell`, `$import_button`, `$sync_button_label` all pre-escaped in foreach. **SAFE**
- Lines 217, 225, 226, 240-242: uses `esc_html()`, `esc_url()`, `esc_attr()`, `wp_kses_post()`. **SAFE**

### `admin-product-detail.php` (80 lines)
- Line 25: `data-id="<?php echo esc_attr($product->get_id()); ?>"` — escaped. **SAFE**

### `admin-customers.php` (49 lines)
- Lines 27-29: `<?php echo $filter==='all'?'ac-btn-primary':'';?>` — boolean literal. **SAFE**

### `admin-order-detail.php` (157 lines)
- Lines 132-134: `<?php echo $alegra_payment_data ? esc_html(...) : '--'; ?>` — escaped. **SAFE**

### `admin-dashboard.php` (271 lines)
- Line 71: `<?php echo $is_connected ? 'var(--ac-success)' : 'var(--ac-warning)';?>` — boolean literal. **SAFE**
- Line 74: `<?php echo $is_connected ? 'success' : 'warning';?>` — boolean literal. **SAFE**
- Line 98: `<?php echo $last_sync ? esc_html(human_time_diff($last_sync, time()) . ' ' . __('atras', 'alegra-connector')) : esc_html__('Nunca', 'alegra-connector');?>` — `esc_html()`. **SAFE**
- Line 186: `<?php echo $is_connected ? esc_html($company_name) : ...;?>` — escaped. **SAFE**

### `admin-statistics.php` (93 lines)
- Line 49: all outputs escaped with `esc_html()` or `esc_html__()`. **SAFE**

### `admin-customer-detail.php` (71 lines)
- Line 25: `data-id="<?php echo esc_attr($customer->ID); ?>"` — escaped. **SAFE**

### `admin-monitor.php` (343 lines)
- All matches are JavaScript `$()` jQuery selectors and `.data('...')` reads — not PHP output. **No PHP XSS**.

### `admin-push-queue.php` (260 lines)
- All matches are JavaScript — not PHP output. **No PHP XSS**.

---

## Summary

**No XSS vectors found in `templates/admin-*.php`** — all output of user-controlled data is properly escaped with WordPress's standard escaping functions (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).

Pre-escaping pattern used correctly throughout: variables are assigned with escaping (`$x = esc_html($y)`) inside foreach blocks, then echoed raw — this is idiomatic WP template style and is safe.

No P0/P1/P2/P3 findings. No fixes required for these templates in 2.2.0.
