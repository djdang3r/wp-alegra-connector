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

    /**
     * Run ids opened by THIS request through wrap() and not yet finalized.
     *
     * A PHP FATAL (`Maximum execution time exceeded`, memory exhaustion) is NOT
     * a catchable Throwable, so wrap()'s try/catch never runs finish() and the
     * row stays `running` forever = a zombie. This registry lets the shutdown
     * finalizer close exactly the runs this request started — never another
     * request's.
     *
     * @var array<int, bool>
     */
    private static array $inflight = [];

    /** @var bool */
    private static bool $shutdown_registered = false;

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
        unset(self::$inflight[$run_id]);                    // ya no hay nada que finalizar en shutdown
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

        // Any PHP fatal during $fn is uncatchable: without a shutdown finalizer
        // the row would stay `running` forever (accumulating zombies). Track only
        // runs this request opened and let the finalizer settle them.
        self::$inflight[$run_id] = true;
        self::register_shutdown_finalizer();

        try {
            $result = $fn($run_id);
            self::finish($run_id, 'completed');
            return $result;
        } catch (\Throwable $e) {
            self::finish($run_id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register the shutdown finalizer once per request.
     *
     * In production this is the global `register_shutdown_function()`; the
     * harness can invoke finalize_inflight_runs() directly to simulate it.
     */
    private static function register_shutdown_finalizer(): void
    {
        if (self::$shutdown_registered) {
            return;
        }
        self::$shutdown_registered = true;
        register_shutdown_function(static function (): void {
            self::finalize_inflight_runs();
        });
    }

    /**
     * Close every run this request opened (via wrap) that is still `running`
     * when PHP shuts down. Runs on normal shutdown too, but by then wrap() has
     * already drained its id through teardown(), so it is a harmless no-op.
     * idempotent: finish() on an already-closed run returns without re-writing.
     */
    public static function finalize_inflight_runs(): void
    {
        foreach (array_keys(self::$inflight) as $id) {
            unset(self::$inflight[$id]);
            self::finish((int) $id, 'failed', 'Interrumpido (fatal/timeout)');
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
