<?php
/**
 * Schema — DB migrations for Alegra Connector.
 *
 * Provides idempotent table creation via dbDelta().
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Schema
{
    /**
     * Current schema version. Bump this whenever the table set changes so the
     * version guard in migrate() runs the migrations exactly once.
     *
     * Kept in lockstep with the plugin version: it identifies the release whose
     * schema this is, not a future one. Batch 2 introduced it as '2.4.0', but
     * 2.3.1 is the release that actually ships the schema change (drop the dead
     * queue tables, UUID column migration), so it must read '2.3.1'. Existing
     * 2.3.0 installs stored the old literal '2.3.0' in
     * `alegra_connector_schema_version`, so the guard still differs and the
     * (idempotent) migrations run once on upgrade.
     */
    public const SCHEMA_VERSION = '2.3.1';

    /**
     * Run all migrations. Safe to call multiple times.
     *
     * AC-06: dbDelta() issues DESCRIBE/SHOW TABLES per table. Running it on
     * every request (frontend included) wasted ~6-12 queries per page view for
     * every visitor on every store. The schema version is an autoloaded option:
     * reading it costs no query, and when it matches we do zero schema work.
     */
    public static function migrate(): void
    {
        $applied = (string) get_option('alegra_connector_schema_version', '');
        if ($applied === self::SCHEMA_VERSION) {
            return;
        }

        self::create_tombstones_table();
        self::create_runs_table();
        self::create_entity_map_table();

        // AC-22/AC-59: the push-queue subsystem was removed in 2.3.x, so these
        // tables no longer have any writer. Drop them (and stop creating them)
        // instead of letting them grow forever.
        self::drop_dead_tables();

        self::maybe_migrate_alegra_id_columns();

        // Written last so a mid-migration failure retries on the next request.
        update_option('alegra_connector_schema_version', self::SCHEMA_VERSION, 'yes');

        // AC-22: self-heal the daily retention cron on install/upgrade.
        Maintenance::schedule();
    }

    /**
     * Drop tables whose subsystem was removed and that no code writes to.
     *
     * AC-22: alegra_pull_queue and alegra_push_log have no writers; the queue
     * table was already gone from the schema but lingered in uninstall.
     */
    private static function drop_dead_tables(): void
    {
        global $wpdb;
        foreach (['alegra_pull_queue', 'alegra_push_log', 'alegra_push_queue'] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
    }

    /**
     * Migrate alegra_id columns from BIGINT to VARCHAR(36) for UUID support.
     *
     * AC-59: MODIFY rebuilds the table, so this is gated by its own dedicated
     * guard (written BEFORE the DDL) and by a row-count ceiling so a huge table
     * is never rebuilt inside a web request.
     */
    private static function maybe_migrate_alegra_id_columns(): void
    {
        global $wpdb;

        if (get_option('alegra_connector_uuid_columns_migrated') === '2.3.0') {
            return;
        }
        // Write the guard first: if an ALTER below dies, we do not want to
        // re-run the whole rebuild on every subsequent request.
        update_option('alegra_connector_uuid_columns_migrated', '2.3.0', 'yes');

        // Only the surviving tables need the UUID column migration now.
        $tables = [
            $wpdb->prefix . 'alegra_tombstones' => 'alegra_id',
            $wpdb->prefix . 'alegra_entity_map' => 'alegra_id',
        ];

        foreach ($tables as $table => $column) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if (!$exists) {
                continue;
            }

            // AC-59: never rebuild a large table on a web request.
            $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            if ($rows > 100000) {
                if (class_exists(\Alegra\Connector\Logger\Logger::class)) {
                    (new \Alegra\Connector\Logger\Logger())->warning('Skipped UUID column migration on a large table', [
                        'table' => $table,
                        'rows'  => $rows,
                    ]);
                }
                continue;
            }

            $col = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (!$col || stripos((string) $col->Type, 'bigint') === false) {
                continue;
            }
            $null = (stripos((string) $col->Null, 'yes') !== false) ? 'NULL' : 'NOT NULL';
            $wpdb->query("ALTER TABLE {$table} MODIFY {$column} VARCHAR(36) {$null}");
            if ($wpdb->last_error) {
                // Route through the plugin Logger (respects the managed log and
                // retention); fall back to error_log only under WP_DEBUG.
                if (class_exists(\Alegra\Connector\Logger\Logger::class)) {
                    (new \Alegra\Connector\Logger\Logger())->error('Schema migration failed', [
                        'table' => $table,
                        'column' => $column,
                        'db_error' => $wpdb->last_error,
                    ]);
                } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Alegra Schema] Failed to migrate ' . $table . '.' . $column . ': ' . $wpdb->last_error);
                }
            }
        }
    }

    public static function create_tombstones_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alegra_type VARCHAR(20) NOT NULL,
            alegra_id VARCHAR(36) NOT NULL,
            wc_post_id BIGINT UNSIGNED NULL,
            wc_user_id BIGINT UNSIGNED NULL,
            deleted_at DATETIME NOT NULL,
            deleted_by BIGINT UNSIGNED NULL,
            reason VARCHAR(50) NOT NULL DEFAULT 'manual_wc',
            resurrected_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_alegra (alegra_type, alegra_id),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function create_runs_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            started_by VARCHAR(100) NULL,
            total_items INT UNSIGNED DEFAULT 0,
            items_done INT UNSIGNED DEFAULT 0,
            items_failed INT UNSIGNED DEFAULT 0,
            memory_peak_mb DECIMAL(10,2) NULL,
            cpu_load DECIMAL(5,2) NULL,
            context_json LONGTEXT NULL,
            error_summary TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_status_started (status, started_at),
            KEY idx_run_type (run_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function create_entity_map_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_entity_map';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alegra_type VARCHAR(20) NOT NULL,
            alegra_id VARCHAR(36) NOT NULL,
            wc_entity_type VARCHAR(20) NOT NULL,
            wc_entity_id BIGINT UNSIGNED NOT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_mapping (alegra_type, alegra_id, wc_entity_type),
            KEY idx_wc (wc_entity_type, wc_entity_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
