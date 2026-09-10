# Audit: templates/admin-*.php — XSS scan

**Date**: 2026-09-10
**Scope**: All 16 `templates/admin-*.php` files (2623 LOC total)
**Method**: Read each file, inspect every `echo`/`print`/`<?= ?>` output for missing escaping of user-controlled data.

## Findings

No P0 or P1 findings. One P3 finding noted below.

### P0 findings: 0
None. No template outputs raw user input without escaping.

### P1 findings: 0
None. Indirect user input (post titles, option values, request params) is consistently escaped via `esc_html()` / `esc_attr()` / `esc_url()` / `wp_kses_post()` / `esc_js()`.

### P2 findings: 0
None.

### P3 findings

#### Finding 1: `showNotice()` builds DOM via innerHTML concatenation [P3]
- **Files**: `templates/admin-push-queue.php:179-185`, `templates/admin-monitor.php:324-330`
- **Code** (`admin-monitor.php:324-330`):
  ```js
  function showNotice(msg, type) {
      type = type || 'info';
      var $n = $('<div class="ac-notice ' + type + '" style="display:none;margin:8px 0 14px 0;">' + msg + '</div>');
      $('.alegra-connector-wrap').first().prepend($n);
      ...
  }
  ```
- **Issue**: `msg` originates from `r.data.message` (server JSON), which in PHP is composed via `__()` translations or `WP_Error::get_error_message()`. The current call sites (admin-push-queue.php:217, 227, 230, 250, 254; admin-monitor.php:218, 219, 226, 234, 258, 261, 267, 284, 287, 293, 309, 312, 318) pass server-controlled strings. Not exploitable today, but the pattern allows injected HTML if any future WP_Error message ever embeds raw user data.
- **Fix (defensive)**: switch to `$n.text(msg)` (or build the div, then `$n.find('.text').text(msg)`). Not required for 2.2.0 release.

## Per-file review notes

### `admin-orders.php` — CLEAN
All `echo`/`<?= ?>` statements use `esc_html()` / `esc_attr()` / `esc_url()`. Lines 13-16 (KPIs), 25-27 (filter links), 28 (status select), 35 (search input), 47-52 (table rows) — all properly escaped.

### `admin-settings.php` — CLEAN
Tokens, URLs, options, webhook secrets all wrapped in `esc_attr()`. Lines 105-106, 167-168, 183-184, 210, 230 — pre-escaped in `foreach` loops before echo. Safe.

### `admin-docs.php` — CLEAN
`ac_md_to_html()` (lines 7-85) calls `esc_html($md)` first (line 17) then restores pre-escaped code blocks. URL scheme guard at lines 34, 43 blocks `javascript:` / `data:` / `vbscript:` / `file:` schemes. Safe.

### `admin-order-detail.php` — CLEAN
All order fields (`get_id`, `get_billing_first_name`, `get_date_created`, etc.) and Alegra API response fields (`$alegra_data['id']`, `['number']`, `['date']`, `['dueDate']`, `['status']`) escaped via `esc_html()` / `esc_url()`. Safe.

### `admin-mapping.php` — CLEAN
Lines 33, 42, 50, 86, 125, 126, 134, 168, 184 — all pre-escaped via `esc_attr()` / `esc_html()` / `esc_html__()`. Safe.

### `admin-wizard.php` — CLEAN
`$step` cast `(int)` before reaching template (line 22). All other outputs use `esc_html()` / `esc_url()` / `esc_js()` / `esc_attr()`. JS-side `json_encode(wp_create_nonce(...))` (line 124) is HTML-safe in `<script>` context. Safe.

### `admin-customers.php` — CLEAN
Lines 27-29 use boolean literal comparisons. Lines 46 (table row) all properly escaped. Safe.

### `admin-statistics.php` — CLEAN
JS-side `json_encode(...)` (lines 87-93) for chart data is HTML-safe in `<script>` context. Numerical values cast `(int)` (line 93). Safe.

### `admin-import.php` — CLEAN
All echo statements use `esc_html()` / `esc_attr()`. The `$connected` boolean check at line 73 / 77 / 81 uses ternary on internal option. Safe.

### `admin-logs.php` — CLEAN
Log entries, sizes, dates all `esc_html()`-wrapped. `$rowBg`, `$rowBorder`, `$bc` (lines 55-60) are static CSS string builders. Safe.

### `admin-products.php` — CLEAN
Lines 191-199 build `$alegra_cell`, `$status_cell`, `$import_button`, `$sync_button_label` with `esc_html()` / `esc_attr()`. Image URLs at line 208 use `esc_url()`. Safe.

### `admin-customer-detail.php` — CLEAN
All WC and Alegra customer fields escaped. Safe.

### `admin-push-queue.php` — CLEAN except P3 finding 1
PHP outputs are all properly escaped. JS-side `showNotice()` has the P3 defensive concern (see Finding 1).

### `admin-product-detail.php` — CLEAN
Image URLs use `esc_url()` (line 59). All other fields use `esc_html()`. Safe.

### `admin-dashboard.php` — CLEAN
KPIs use `(int)` cast. Options use `esc_html(get_option(...))` or `esc_attr()`. Safe.

### `admin-monitor.php` — CLEAN except P3 finding 1
JS-side `escapeHtml()` function (lines 98-103) handles `&<>"'` — used consistently in `renderRunning` / `renderCron` / `renderRecent`. The P3 `showNotice` pattern at lines 324-330 is the only soft spot (see Finding 1).

## Summary
- Total files reviewed: 16
- Total LOC: 2623
- Clean files: 16 (no P0/P1/P2)
- Files with findings: 2 (`admin-monitor.php`, `admin-push-queue.php` — both share the same P3 `showNotice` pattern)
- Total P0 findings: 0
- Total P1 findings: 0
- Total P2 findings: 0
- Total P3 findings: 1
