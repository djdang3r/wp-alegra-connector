<?php
/**
 * Kill Switch — Global plugin enable/disable control.
 *
 * Provides a fast, cached check used at every sync entrypoint.
 * When active, NO sync operation runs (pull or push).
 *
 * Activated by:
 * - User disconnect (admin manual)
 * - Plugin deactivation
 * - Admin emergency stop (future)
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Kill_Switch
{
    private const TRANSIENT_KEY = 'alegra_kill_switch';
    private const REASON_OPTION = 'alegra_connector_disconnected_reason';
    private const TTL = 86400; // 24h

    /** Per-request cache to avoid repeated get_transient() calls. */
    private static ?bool $cached_state = null;

    /**
     * Check if the kill switch is currently active.
     * Fast (<0.1ms) thanks to static cache.
     *
     * Only the explicit transient is checked. We deliberately do NOT check
     * connection_tested here because:
     * - Fresh installs don't have connection_tested=true yet (default false),
     *   which would incorrectly show the plugin as "disconnected" even though
     *   it's just not configured yet.
     * - The connection check is done at action time (ajax_test_connection,
     *   sync entrypoints) where missing credentials produce a clear, actionable
     *   error to the user.
     *
     * The transient is set ONLY when the user explicitly disconnects (or the
     * plugin is deactivated). To clear it, the user clicks "Reactivar" in the
     * notice or runs ajax_test_connection successfully.
     */
    public static function is_active(): bool
    {
        if (self::$cached_state !== null) {
            return self::$cached_state;
        }

        $transient_active = (bool) get_transient(self::TRANSIENT_KEY);
        self::$cached_state = $transient_active;
        return self::$cached_state;
    }

    /**
     * Activate the kill switch.
     *
     * @param string $reason One of: 'user_disconnected', 'plugin_deactivated', 'emergency_stop', 'manual'
     */
    public static function activate(string $reason = 'manual'): void
    {
        set_transient(self::TRANSIENT_KEY, $reason, self::TTL);
        update_option(self::REASON_OPTION, $reason);
        update_option('alegra_connector_connection_tested', false);
        self::$cached_state = true;

        do_action('alegra_kill_switch_activated', $reason);
    }

    /**
     * Deactivate the kill switch (used after successful reconnect).
     */
    public static function deactivate(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        delete_option(self::REASON_OPTION);
        update_option('alegra_connector_connection_tested', true);
        self::$cached_state = false;

        do_action('alegra_kill_switch_deactivated');
    }

    /**
     * Get the reason for the current kill switch state.
     */
    public static function reason(): string
    {
        return (string) get_option(self::REASON_OPTION, 'unknown');
    }

    /**
     * Reset the per-request cache (useful in long-running processes).
     */
    public static function reset_cache(): void
    {
        self::$cached_state = null;
    }
}
