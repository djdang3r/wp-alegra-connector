<?php
/**
 * Heartbeat — Real-time process state for the Monitor UI.
 *
 * Stores lightweight state in a transient (TTL 120s) plus a static cache
 * for the current request, so the Monitor can poll every 5s with minimal overhead.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Heartbeat
{
    private const TTL = 120;

    /** @var array<int, array> Per-request cache keyed by run_id. */
    private static array $cache = [];

    /**
     * Set the current heartbeat for a run.
     */
    public static function set(int $run_id, array $data): void
    {
        self::$cache[$run_id] = $data;
        $data['updated_at'] = current_time('mysql');
        $data['memory_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);

        $cpu = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpu = round((float) ($load[0] ?? 0), 2);
        }
        $data['cpu_load'] = $cpu;

        set_transient('alegra_run_' . $run_id, $data, self::TTL);
    }

    /**
     * Get heartbeat for a run (with per-request cache).
     */
    public static function get(int $run_id): ?array
    {
        if (isset(self::$cache[$run_id])) {
            return self::$cache[$run_id];
        }

        $data = get_transient('alegra_run_' . $run_id);
        if ($data) {
            self::$cache[$run_id] = $data;
            return $data;
        }

        return null;
    }

    /**
     * Delete heartbeat (when run finishes).
     */
    public static function clear(int $run_id): void
    {
        unset(self::$cache[$run_id]);
        delete_transient('alegra_run_' . $run_id);
        delete_transient('alegra_run_stop_' . $run_id);
    }

    /**
     * Get heartbeats for multiple run_ids in one go (efficient for monitor UI).
     */
    public static function get_batch(array $run_ids): array
    {
        $results = [];
        foreach ($run_ids as $run_id) {
            $hb = self::get((int) $run_id);
            if ($hb !== null) {
                $results[(int) $run_id] = $hb;
            }
        }
        return $results;
    }
}
