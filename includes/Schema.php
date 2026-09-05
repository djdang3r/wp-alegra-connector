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
     * Run all migrations. Safe to call multiple times.
     */
    public static function migrate(): void
    {
        self::create_tombstones_table();
        self::create_pull_queue_table();
        self::create_runs_table();
        self::create_push_log_table();
        self::create_entity_map_table();
        self::create_push_queue_table();
    }

    public static function create_tombstones_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alegra_type VARCHAR(20) NOT NULL,
            alegra_id BIGINT UNSIGNED NOT NULL,
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

    public static function create_pull_queue_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_pull_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            change_type VARCHAR(30) NOT NULL,
            alegra_id BIGINT UNSIGNED NULL,
            wc_entity_type VARCHAR(20) NULL,
            wc_entity_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'webhook',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            detected_at DATETIME NOT NULL,
            reviewed_at DATETIME NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            applied_at DATETIME NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_status_detected (status, detected_at),
            KEY idx_alegra (alegra_id),
            KEY idx_entity (wc_entity_type, wc_entity_id)
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

    public static function create_push_log_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            entity_type VARCHAR(20) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            alegra_id BIGINT UNSIGNED NULL,
            action VARCHAR(20) NOT NULL,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            http_code INT NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_user (user_id)
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
            alegra_id BIGINT UNSIGNED NOT NULL,
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

    public static function create_push_queue_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(20) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            payload_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            detected_at DATETIME NOT NULL,
            detected_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            applied_at DATETIME NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_status_detected (status, detected_at),
            KEY idx_entity (entity_type, entity_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
