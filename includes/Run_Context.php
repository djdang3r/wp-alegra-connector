<?php
/**
 * Run_Context — the single source of truth for "there is a run".
 *
 * Coordinates Runs (persistence), Heartbeat (display) and Logger (tracing) so
 * that every import path — manual, chunked, cron, webhook — opens/closes a run
 * the same way, with request-scoped state and the tombstone policy.
 *
 * No import path should call Runs::start() directly.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

use Alegra\Connector\Logger\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class Run_Context
{
    private static int $run_id = 0;
    private static string $type = '';
    private static string $tombstone_policy = 'respect'; // respect | ignore_bulk | ignore_all

    public static function begin(string $type, ?string $started_by = null, array $context = []): int
    {
        $run_id = Runs::start($type, $started_by, $context);
        self::$run_id = $run_id;
        self::$type = $type;
        Logger::set_run_context($run_id, $type);
        Heartbeat::set($run_id, [
            'step' => 'start',
            'message' => __('Iniciando...', 'alegra-connector'),
        ]);
        return $run_id;
    }

    public static function resume(int $run_id, string $type): void
    {
        self::$run_id = $run_id;
        self::$type = $type;
        Logger::set_run_context($run_id, $type);
    }

    public static function finish(int $run_id, string $status = 'completed', ?string $error = null): void
    {
        $current = Runs::status($run_id);
        if ($current !== null && $current !== 'running') {
            self::teardown($run_id);                        // ya cerrado: no re-finalizar (R9)
            return;
        }
        Runs::finish($run_id, $status, $error);
        self::teardown($run_id);
    }

    private static function teardown(int $run_id): void
    {
        delete_transient('alegra_run_stop_' . $run_id);     // el stop ya fue observado
        Heartbeat::forget($run_id);                         // sólo el display
        Logger::clear_run_context();
        if (self::$run_id === $run_id) {
            self::$run_id = 0;
            self::$type = '';
        }
    }

    public static function fail_early(string $origin, string $reason, array $context = []): void
    {
        Logger::set_run_context(0, $origin);                // sin run_id: el run no llegó a existir
        // `info()` es de INSTANCIA (no `static`): llamarlo como `Logger::info(...)`
        // es fatal en PHP 8. Se usa una instancia.
        (new Logger())->info('Import abortado antes de crear el run', ['reason' => $reason] + $context);
        Logger::clear_run_context();
    }

    public static function wrap(string $type, callable $fn, ?string $started_by = null, array $context = []): mixed
    {
        $run_id = self::begin($type, $started_by, $context);
        try {
            $result = $fn($run_id);
            self::finish($run_id, 'completed');
            return $result;
        } catch (\Throwable $e) {
            self::finish($run_id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    public static function current(): int
    {
        return self::$run_id;
    }

    public static function should_stop(): bool
    {
        return self::$run_id > 0 && Runs::should_stop(self::$run_id);
    }

    public static function set_tombstone_policy(string $policy): void
    {
        if (in_array($policy, ['respect', 'ignore_bulk', 'ignore_all'], true)) {
            self::$tombstone_policy = $policy;
        }
    }

    public static function tombstone_policy(): string
    {
        return self::$tombstone_policy;
    }
}
