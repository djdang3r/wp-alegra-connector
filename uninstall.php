<?php
/**
 * Uninstall Handler
 *
 * Removes everything the plugin created on every site: custom tables, options,
 * user/post/term meta, scheduled actions, transients and log files.
 *
 * Multisite (AC-57): a network-wide uninstall previously returned early unless
 * `$_REQUEST['plugin']` matched, so nothing was ever cleaned. We now iterate
 * every site and clean each one.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Custom tables created by Schema::migrate() (suffixed with the site prefix).
 *
 * @return string[]
 */
function alegra_connector_uninstall_tables(): array
{
    // AC-22: alegra_pull_queue / alegra_push_log / alegra_push_queue have no
    // writers and are dropped by Schema::migrate(); they are no longer part of
    // the plugin's table set.
    return [
        'alegra_tombstones',
        'alegra_runs',
        'alegra_entity_map',
    ];
}

/**
 * Static option names created by the plugin.
 *
 * These are written as explicit delete_option() calls (not a loop over a
 * variable) so the created-vs-deleted audit is literal and complete.
 */
function alegra_connector_uninstall_options(): void
{
    delete_option('alegra_connector_version');
    delete_option('alegra_connector_email');
    delete_option('alegra_connector_token');
    delete_option('alegra_connector_connection_tested');
    delete_option('alegra_connector_company_name');
    delete_option('alegra_connector_company_country');
    delete_option('alegra_connector_company_email');
    delete_option('alegra_connector_api_url');
    delete_option('alegra_connector_sync_frequency');
    delete_option('alegra_connector_sync_method');
    delete_option('alegra_connector_push_orders_enabled');
    delete_option('alegra_connector_push_products_enabled');
    delete_option('alegra_connector_currency');
    delete_option('alegra_connector_log_retention_days');
    delete_option('alegra_connector_log_suffix');
    delete_option('alegra_connector_conflict_resolution');
    delete_option('alegra_connector_sync_products');
    delete_option('alegra_connector_sync_customers');
    delete_option('alegra_connector_sync_orders');
    delete_option('alegra_connector_sync_categories');
    delete_option('alegra_connector_sync_taxes');
    delete_option('alegra_connector_inventory_source');
    delete_option('alegra_connector_warehouse_id');
    delete_option('alegra_connector_payment_account_id');
    delete_option('alegra_connector_payment_term_id');
    delete_option('alegra_connector_auto_complete_order');
    delete_option('alegra_connector_sync_images');
    delete_option('alegra_connector_sync_images_mode');
    delete_option('alegra_connector_sync_inactive_products');
    delete_option('alegra_connector_field_mapping');
    delete_option('alegra_connector_tax_mapping');
    delete_option('alegra_connector_resolved_tax_ids');
    delete_option('alegra_connector_generic_item_ids');
    delete_option('alegra_connector_warehouse_mapping');
    delete_option('alegra_connector_category_mapping');
    delete_option('alegra_connector_warehouse_enabled');
    delete_option('alegra_connector_diagnostics');
    delete_option('alegra_connector_items_count');
    delete_option('alegra_connector_contacts_count');
    delete_option('alegra_connector_webhook_secret');
    delete_option('alegra_connector_webhook_token');
    delete_option('alegra_connector_webhook_subscriptions');
    delete_option('alegra_connector_cron_disabled');
    delete_option('alegra_connector_schema_version');
    delete_option('alegra_connector_uuid_columns_migrated');
    delete_option('alegra_connector_products_import_cursor');
    delete_option('alegra_connector_import_time_budget');
    delete_option('alegra_connector_import_max_pages');
    delete_option('alegra_connector_orders_poll_batch');
    delete_option('alegra_connector_rate_window');
    delete_option('alegra_connector_billing_field_catalog_enabled');
    delete_option('alegra_connector_customer_resolution_mode');
    delete_option('alegra_connector_invoice_status');
    delete_option('alegra_connector_dry_run');
    delete_option('alegra_connector_push_category_strategy');
    delete_option('alegra_connector_push_category_id');
    delete_option('alegra_connector_import_category_parent');
    delete_option('alegra_connector_import_preserve_fields');
    delete_option('alegra_connector_consumidor_final_contact_id');
    delete_option('alegra_connector_consumidor_final_manual_override');
    delete_option('alegra_connector_consumidor_final_manual_id');
    delete_option('alegra_connector_disconnected_reason');
    delete_option('alegra_connector_disconnected_at');
    delete_option('alegra_kill_switch');
    delete_option('alegra_lock_stale');

    // Per-entity push direction (legacy push-queue setting).
    foreach (['product', 'customer', 'order', 'payment'] as $type) {
        delete_option('alegra_connector_push_direction_' . $type);
    }

    // Consumidor Final cache is blog-scoped on multisite (Consumidor_Final::cache_key).
    delete_option('alegra_connector_consumidor_final_contact_id_' . get_current_blog_id());
}

/**
 * Clean a single site.
 */
function alegra_connector_uninstall_site(): void
{
    global $wpdb;

    // 1. Drop the plugin's custom tables.
    foreach (alegra_connector_uninstall_tables() as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    // 2. Delete options.
    alegra_connector_uninstall_options();

    // 3. Delete lock options (alegra_lock_<key>) — dynamic key, swept by prefix.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'alegra\\_lock\\_%' ESCAPE '\\\\'"
    );

    // 4. Delete every plugin transient by prefix.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_alegra\\_%'
         OR option_name LIKE '_transient_timeout_alegra\\_%'
         ESCAPE '\\\\'"
    );

    // 5. Delete user meta: billing fields, Alegra link and wizard state.
    $user_meta = [
        'billing_alegra_kindofperson',
        'billing_alegra_idtype',
        'billing_alegra_identification',
        'billing_alegra_dv',
        'billing_alegra_regime',
        'billing_alegra_company',
        'billing_alegra_secondname',
        'billing_alegra_secondlastname',
        'billing_alegra_phonesecondary',
        'billing_alegra_mobile',
        'billing_alegra_observations',
        'alegra_contact_id',
        'alegra_notes',
        'billing_nit',
        'alegra_wizard_step',
        'alegra_wizard_done',
    ];
    foreach ($user_meta as $meta_key) {
        delete_metadata('user', 0, $meta_key, '', true);
    }
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta}
         WHERE meta_key LIKE 'billing\\_alegra\\_%' ESCAPE '\\\\'"
    );

    // 6. Delete post meta (products, variations, orders, attachments).
    $post_meta = [
        '_alegra_item_id',
        '_alegra_invoice_id',
        '_alegra_invoice_number',
        '_alegra_payment_id',
        '_alegra_payment_number',
        '_alegra_credit_note_id',
        '_alegra_credited_amount',
        '_alegra_last_payment_method',
        '_alegra_status_synced_at',
        '_alegra_void_at',
        '_billing_alegra_contact_id',
    ];
    foreach ($post_meta as $meta_key) {
        delete_metadata('post', 0, $meta_key, '', true);
    }
    // Dynamic keys: _alegra_image_url[_hash], _alegra_credit_note_for_refund_*,
    // _alegra_credit_note_id_for_*, and the per-field _billing_alegra_* mirrors.
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta}
         WHERE meta_key LIKE '\\_alegra\\_%' ESCAPE '\\\\'
         OR meta_key LIKE '\\_billing\\_alegra\\_%' ESCAPE '\\\\'"
    );

    // 7. Delete term meta (category link).
    delete_metadata('term', 0, 'alegra_category_id', '', true);

    // 8. Clear scheduled actions (WP-Cron + Action Scheduler).
    foreach (['alegra_connector_cron_sync', 'alegra_connector_daily_maintenance', 'alegra_connector_payment_reconcile'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('alegra_connector_cron_sync', [], 'alegra');
    }

    // 9. Delete the log directory for this site.
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/alegra-logs';
    if (is_dir($log_dir)) {
        alegra_connector_rmdir_recursive($log_dir);
    }
}

/**
 * Recursively delete a directory and its contents.
 */
function alegra_connector_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? alegra_connector_rmdir_recursive($path) : unlink($path);
    }
    rmdir($dir);
}

// AC-57: on multisite, clean every site. The old `$_REQUEST['plugin']` check
// made a network-wide uninstall a no-op. On single-site, run once.
if (is_multisite() && function_exists('get_sites') && function_exists('switch_to_blog')) {
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        alegra_connector_uninstall_site();
        restore_current_blog();
    }
} else {
    alegra_connector_uninstall_site();
}
