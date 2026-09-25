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
            // AC-70: wp_get_current_user() always returns a WP_User (id 0 when
            // there is no logged-in user), so `?? 'system'` never fired and cron
            // runs were recorded with an empty started_by. Key off the user id.
            $user_id = get_current_user_id();
            $started_by = $user_id > 0
                ? (string) (wp_get_current_user()->user_login ?: 'system')
                : 'system';
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
     * Current status of a run, or null if the row does not exist.
     *
     * Used as the re-finish guard by Run_Context::finish() (R9): a run that is
     * already completed/failed/cancelled must not be overwritten.
     */
    public static function status(int $run_id): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM $table WHERE id = %d",
            $run_id
        ));
        return $status !== null ? (string) $status : null;
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
     * A run still marked `running` after this many seconds is treated as
     * abandoned (crashed worker / killed request) and reconciled (AC-82).
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Get currently running runs.
     *
     * AC-82: bounded (LIMIT) and stale-aware. A worker that crashed without
     * calling finish() left rows stuck in `running` forever, polluting the
     * monitor and inflating the "currently running" count. Anything older than
     * STALE_AFTER_SECONDS is marked `stale` before the live rows are returned.
     */
    public static function currently_running(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';

        self::mark_stale();
        self::mark_abandoned();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'running' ORDER BY started_at DESC LIMIT %d",
            50
        ));
    }

    /**
     * Mark abandoned `running` rows as `stale` so they stop being reported as
     * live (AC-82). Bounded by the same LIMIT as the read.
     */
    private static function mark_stale(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';
        // Mismo reloj con que Runs::start() guardó started_at (current_time('mysql')).
        // `time()` es UTC y desalineaba el cutoff en sitios con offset ≠ 0.
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::STALE_AFTER_SECONDS);

        $wpdb->query($wpdb->prepare(
            "UPDATE $table
             SET status = 'stale', finished_at = %s, error_summary = %s
             WHERE status = 'running' AND started_at < %s
             LIMIT 50",
            current_time('mysql'),
            __('Proceso abandonado (sin finalizar)', 'alegra-connector'),
            $cutoff
        ));
    }

    /**
     * Close chunked runs that were abandoned (browser closed / request killed).
     *
     * The chunked import is multi-request: if the browser closes, no further
     * request arrives and the row stays `running` until mark_stale()'s 1h TTL.
     * A live cron/manual is a live request; if it dies, mark_stale() covers it.
     */
    public static function mark_abandoned(int $grace_seconds = 180): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_runs';
        // Mismo reloj con que Runs::start() guardó started_at (current_time('mysql')).
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $grace_seconds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table
             WHERE status = 'running' AND run_type = 'chunked_import' AND started_at < %s
             LIMIT 50",
            $cutoff
        ));

        foreach ($rows as $row) {
            if (Heartbeat::get((int) $row->id) === null) {   // heartbeat TTL 120 s vencido
                $wpdb->update($table, [
                    'status' => 'stale',
                    'finished_at' => current_time('mysql'),
                    'error_summary' => __('Proceso abandonado (pestaña cerrada o request interrumpido)', 'alegra-connector'),
                ], ['id' => (int) $row->id]);
            }
        }
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
