<?php
/**
 * Runs — Process execution tracking.
 *
 * Records start/progress/finish of each sync operation (cron, AJAX, webhook).
 * Backed by wp_alegra_runs table.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Runs
{
    /**
     * Track an entire operation: start → execute → finish.
     *
     * @param string $type One of: 'cron_sync', 'chunked_import', 'push_invoice', 'webhook', 'cleanup', etc.
     * @param callable $fn The actual operation. Receives $run_id.
     * @param string|null $started_by User login, 'cron', 'webhook_alegra', etc.
     * @param array $context Additional context for debugging.
     */
    public static function track(string $type, callable $fn, ?string $started_by = null, array $context = []): mixed
    {
        $run_id = self::start($type, $started_by, $context);

        try {
            $result = $fn($run_id);
            self::finish($run_id, 'completed', null);
            return $result;
        } catch (\Throwable $e) {
            self::finish($run_id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Start a new run. Returns run_id.
     */
    public static function start(string $type, ?string $started_by = null, array $context = []): int
    {
        global $wpdb;

        if ($started_by === null) {
            $started_by = (wp_get_current_user()->user_login ?? 'system');
        }

        $wpdb->insert($wpdb->prefix . 'alegra_runs', [
            'run_type' => $type,
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'started_by' => substr($started_by, 0, 100),
            'context_json' => $context ? wp_json_encode($context) : null,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Update progress on a running run.
     */
    public static function update_progress(int $run_id, int $items_done, int $total_items = 0): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'alegra_runs',
            [
                'items_done' => $items_done,
                'total_items' => $total_items,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
            ['id' => $run_id, 'status' => 'running'],
            ['%d', '%d', '%f'],
            ['%d', '%s']
        );
    }

    /**
     * Mark run as finished.
     *
     * @param string $status 'completed' | 'failed' | 'cancelled' | 'killed'
     */
    public static function finish(int $run_id, string $status = 'completed', ?string $error_summary = null): void
    {
        global $wpdb;

        $cpu_load = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpu_load = round((float) ($load[0] ?? 0), 2);
        }

        $wpdb->update(
            $wpdb->prefix . 'alegra_runs',
            [
                'status' => $status,
                'finished_at' => current_time('mysql'),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'cpu_load' => $cpu_load,
                'error_summary' => $error_summary ? substr($error_summary, 0, 500) : null,
            ],
            ['id' => $run_id],
            ['%s', '%s', '%f', '%f', '%s'],
            ['%d']
        );
    }

    /**
     * Request a running process to stop.
     */
    public static function request_stop(int $run_id): void
    {
        set_transient('alegra_run_stop_' . $run_id, 1, 300);
    }

    /**
     * Check if a stop was requested for a run.
     */
    public static function should_stop(int $run_id): bool
    {
        return (bool) get_transient('alegra_run_stop_' . $run_id);
    }

    /**
     * Get recent runs (for monitor history).
     *
     * @return array
     */
    public static function recent(int $limit = 20, ?string $status = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';

        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY started_at DESC LIMIT %d",
                $status,
                $limit
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY started_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Get currently running runs.
     */
    public static function currently_running(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';

        return $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 'running' ORDER BY started_at DESC"
        );
    }

    /**
     * Get upcoming cron events for this plugin.
     */
    public static function get_cron_events(): array
    {
        $events = [];
        $crons = _get_cron_array();

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $event_groups) {
                if (strpos($hook, 'alegra') === false) {
                    continue;
                }
                foreach ($event_groups as $event) {
                    $events[] = [
                        'hook' => $hook,
                        'next_run' => $timestamp,
                        'next_run_human' => human_time_diff($timestamp, time()),
                        'schedule' => $event['schedule'] ?? 'single',
                    ];
                }
            }
        }

        return $events;
    }
}
