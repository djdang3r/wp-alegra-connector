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
    /**
     * Stored as an OPTION (not a transient) so the deactivation transient
     * sweep (`_transient_alegra_%`) cannot wipe the kill switch it just set.
     */
    private const OPTION_KEY = 'alegra_kill_switch';
    private const REASON_OPTION = 'alegra_connector_disconnected_reason';

    /**
     * Check if the kill switch is currently active.
     *
     * Reads straight from the options table on every call, bypassing every
     * layer of the object cache (`options`, `alloptions`, `notoptions`) so a
     * long import loop in one worker actually sees a flip made by another
     * request (admin emergency stop / disconnect). One indexed lookup.
     */
    public static function is_active(): bool
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            self::OPTION_KEY
        ));

        return $value !== null && $value !== '' && $value !== '0';
    }

    /**
     * Activate the kill switch.
     *
     * @param string $reason One of: 'user_disconnected', 'plugin_deactivated', 'emergency_stop', 'manual'
     */
    public static function activate(string $reason = 'manual'): void
    {
        update_option(self::OPTION_KEY, $reason, false);
        update_option(self::REASON_OPTION, $reason);
        update_option('alegra_connector_connection_tested', false);

        do_action('alegra_kill_switch_activated', $reason);
    }

    /**
     * Deactivate the kill switch (used after successful reconnect).
     */
    public static function deactivate(): void
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::REASON_OPTION);
        update_option('alegra_connector_connection_tested', true);

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
     * Reset any cached state (kept for backwards compatibility; is_active()
     * already reads straight from the database every time).
     */
    public static function reset_cache(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_KEY, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }
}
