<?php
/**
 * Maintenance — retention pruning and orphan reconciliation.
 *
 * AC-22: the plugin's history tables used to grow forever. This runs on a daily
 * cron (and at the end of each cron sync) and deletes rows older than the
 * configured retention window in bounded batches.
 *
 * AC-60: it also reconciles the entity map so a deleted WC product/customer
 * cannot leave a mapping pointing at a ghost id.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Maintenance
{
    public const CRON_HOOK = 'alegra_connector_daily_maintenance';

    /**
     * Register the daily maintenance cron.
     */
    public static function register_hooks(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run']);
    }

    /**
     * Ensure the daily maintenance event is scheduled (idempotent).
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Cron entry point.
     */
    public static function run(): void
    {
        self::prune();
        Entity_Map::reconcile_orphans();

        if (class_exists(\Alegra\Connector\Logger\Logger::class)) {
            $days = (int) get_option('alegra_connector_log_retention_days', 30);
            if ($days > 0) {
                (new \Alegra\Connector\Logger\Logger())->clear_old_logs($days);
            }
        }
    }

    /**
     * Delete rows older than the retention window, in bounded batches.
     *
     * @return array{runs:int, tombstones:int}
     */
    public static function prune(): array
    {
        global $wpdb;

        $days = (int) get_option('alegra_connector_log_retention_days', 30);
        if ($days <= 0) {
            return ['runs' => 0, 'tombstones' => 0];
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $runs = self::delete_older('alegra_runs', 'started_at', $cutoff);
        $tombstones = self::delete_older(
            'alegra_tombstones',
            'deleted_at',
            $cutoff,
            'resurrected_at IS NOT NULL'
        );

        return ['runs' => $runs, 'tombstones' => $tombstones];
    }

    /**
     * Delete up to $batch rows older than $cutoff. Never one giant DELETE.
     */
    private static function delete_older(string $table, string $date_column, string $cutoff, string $extra_where = ''): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . $table;
        $extra = $extra_where !== '' ? " AND {$extra_where}" : '';

        $deleted = 0;
        for ($i = 0; $i < 10; $i++) {
            $affected = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} WHERE {$date_column} < %s {$extra} LIMIT 1000",
                $cutoff
            ));
            $deleted += $affected;
            if ($affected < 1000) {
                break;
            }
        }

        return $deleted;
    }
}
