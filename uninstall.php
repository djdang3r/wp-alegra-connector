<?php
/**
 * Uninstall Handler
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

// WordPress passes the plugin basename as the second argument since 4.0
if (is_multisite()) {
    $plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
    if ($plugin !== 'alegra-connector/alegra-connector.php') {
        return;
    }
}

// Clean up options
$options = [
    'alegra_connector_version',
    'alegra_connector_email',
    'alegra_connector_token',
    'alegra_connector_connection_tested',
    'alegra_connector_company_name',
    'alegra_connector_company_country',
    'alegra_connector_company_email',
    'alegra_connector_api_url',
    'alegra_connector_sync_frequency',
    'alegra_connector_sync_method',
    'alegra_connector_push_orders_enabled',
    'alegra_connector_push_products_enabled',
    'alegra_connector_currency',
    'alegra_connector_log_retention_days',
    'alegra_connector_conflict_resolution',
    'alegra_connector_sync_products',
    'alegra_connector_sync_customers',
    'alegra_connector_sync_orders',
    'alegra_connector_sync_categories',
    'alegra_connector_sync_taxes',
    'alegra_connector_inventory_source',
    'alegra_connector_warehouse_id',
    'alegra_connector_payment_account_id',
    'alegra_connector_payment_term_id',
    'alegra_connector_auto_complete_order',
    'alegra_connector_sync_images',
    'alegra_connector_sync_images_mode',
    'alegra_connector_sync_inactive_products',
    'alegra_connector_field_mapping',
    'alegra_connector_tax_mapping',
    'alegra_connector_warehouse_mapping',
    'alegra_connector_category_mapping',
    'alegra_connector_warehouse_enabled',
    'alegra_connector_diagnostics',
    'alegra_connector_items_count',
    'alegra_connector_contacts_count',
    'alegra_connector_webhook_secret',
    'alegra_connector_webhook_subscriptions',
];

foreach ($options as $option) {
    delete_option($option);
}

// Clear scheduled crons
wp_clear_scheduled_hook('alegra_connector_cron_sync');
wp_clear_scheduled_hook('alegra_connector_process_webhook');

// Clear transients
delete_transient('alegra_connector_rate_limit');
delete_transient('alegra_connector_last_sync');
delete_transient('alegra_connector_sync_queue');
delete_transient('alegra_connector_sync_progress');
delete_transient('alegra_batch_state');

// Delete log files recursively
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/alegra-logs';

if (is_dir($log_dir)) {
    alegra_connector_rmdir_recursive($log_dir);
}

/**
 * Recursively delete a directory and its contents
 */
function alegra_connector_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? alegra_connector_rmdir_recursive($path) : unlink($path);
    }
    rmdir($dir);
}