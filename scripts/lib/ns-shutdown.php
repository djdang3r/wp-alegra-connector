<?php
/**
 * Namespaced `register_shutdown_function()` override for the execution harness.
 *
 * A global `register_shutdown_function()` stub is impossible: it is a PHP
 * built-in and redeclaring it is a fatal error. PHP resolves an unqualified
 * function call in a namespace against that namespace's function table before
 * falling back to the global one, so declaring the override here lets the
 * plugin's own calls (which live in `Alegra\Connector\Sync`) be captured and
 * run deterministically from a test, instead of at process teardown.
 *
 * This file is harness-only: it is never shipped in the release ZIP, and in
 * production no namespaced `register_shutdown_function()` exists, so the plugin
 * calls the real global one.
 *
 * Consumed by T8.1/T8.6 (`register_shutdown_function` releases both locks) via
 * `alegra_mock_run_shutdown_callbacks()`.
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync {

    function register_shutdown_function(callable $callback): void
    {
        $GLOBALS['alegra_mock_shutdown_callbacks'][] = $callback;
    }
}

namespace {

    /**
     * Run (and clear) the callbacks registered through the namespaced
     * `register_shutdown_function()` override, FIFO — mirroring PHP.
     */
    function alegra_mock_run_shutdown_callbacks(): void
    {
        $callbacks = $GLOBALS['alegra_mock_shutdown_callbacks'] ?? [];
        $GLOBALS['alegra_mock_shutdown_callbacks'] = [];
        foreach ($callbacks as $callback) {
            if (is_callable($callback)) {
                $callback();
            }
        }
    }
}
