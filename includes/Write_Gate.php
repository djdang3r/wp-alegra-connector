<?php
/**
 * Write Gate — single authority for "may this write reach Alegra?".
 *
 * Every outbound write (POST/PUT/PATCH/DELETE) funnels through
 * API\Client::request(). This class decides whether that write is allowed:
 *
 *   1. The kill switch is hard: active ⇒ block EVERYTHING, even an explicit
 *      merchant action. There is no bypass.
 *   2. Configuration governs the automatic paths. An automatic write is
 *      allowed only when the option that owns its entity is enabled. A
 *      merchant-triggered write (admin button, REST POST /sync) is allowed
 *      with the kill switch off regardless of the option.
 *
 * The default context is AUTOMATIC (fail-safe): a path must explicitly declare
 * itself as merchant-driven via run_explicit(). A blocked write returns a
 * marker array (never a WP_Error) and is logged, not retried.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Write_Gate
{
    /**
     * Entity key => option that governs its AUTOMATIC writes. A null option
     * means "explicit only": the entity has no enablement switch, so an
     * automatic write is refused with reason `not_explicit`.
     */
    private const ENTITY_OPTIONS = [
        'invoice'     => 'alegra_connector_push_orders_enabled',
        'credit_note' => 'alegra_connector_push_orders_enabled',
        'payment'     => 'alegra_connector_payment_reconcile_enabled',
        'contact'     => 'alegra_connector_push_customers_enabled',
        'item'        => 'alegra_connector_push_products_enabled',
        'category'    => 'alegra_connector_push_products_enabled',
        'webhook'     => null,
        'other'       => null,
    ];

    /**
     * Real default of each governing option. Only `payment` defaults to on
     * (the hourly sweep must keep working on an install that never toggled it).
     */
    private const ENTITY_DEFAULTS = [
        'payment' => true,
    ];

    /**
     * Endpoint pattern => entity key. Patterns require a `/` or end-of-string
     * after the prefix, so `/items` never swallows `/item-categories`.
     *
     * `/taxes` and `/variant-attributes` are product dependencies created
     * in-line by an automatic product push (see Products::prepare_*_data). The
     * SDD groups them under `other` (explicit-only), but that would block a
     * legitimate automatic product push whose option is enabled — a
     * regression. They are therefore governed by the `item` option
     * (`push_products_enabled`), exactly like the item write they support.
     */
    private const ENTITY_PATTERNS = [
        ['invoice',     '#^/invoices(/|$)#'],
        ['credit_note', '#^/credit-notes(/|$)#'],
        ['payment',     '#^/payments(/|$)#'],
        ['contact',     '#^/contacts(/|$)#'],
        ['category',    '#^/item-categories(/|$)#'],
        ['item',        '#^/items(/|$)#'],
        ['item',        '#^/taxes(/|$)#'],
        ['item',        '#^/variant-attributes(/|$)#'],
        ['webhook',     '#^/webhooks/subscriptions(/|$)#'],
    ];

    /**
     * Explicit-context depth. > 0 means a merchant action is in flight.
     */
    private static int $explicit_depth = 0;

    /**
     * Resolve the entity that owns a write, from its verb + endpoint.
     *
     * GET is never evaluated by the gate (it never writes), but entity_for()
     * is total so callers can classify any request.
     */
    public static function entity_for(string $method, string $endpoint): string
    {
        $path = (string) strtok($endpoint, '?');

        foreach (self::ENTITY_PATTERNS as [$entity, $pattern]) {
            if (preg_match($pattern, $path)) {
                return $entity;
            }
        }

        return 'other';
    }

    /**
     * Why a write must be blocked, or null when it is allowed.
     *
     * @return 'kill_switch'|'entity_disabled'|'not_explicit'|null
     */
    public static function block_reason(string $entity): ?string
    {
        if (Kill_Switch::is_active()) {
            return 'kill_switch';
        }

        if (self::is_explicit()) {
            return null;
        }

        $option = self::ENTITY_OPTIONS[$entity] ?? null;
        if ($option === null) {
            return 'not_explicit';
        }

        $default = self::ENTITY_DEFAULTS[$entity] ?? false;
        return get_option($option, $default) ? null : 'entity_disabled';
    }

    // -----------------------------------------------------------------------
    // Explicit context
    // -----------------------------------------------------------------------

    public static function is_explicit(): bool
    {
        return self::$explicit_depth > 0;
    }

    public static function begin_explicit(): void
    {
        self::$explicit_depth++;
    }

    public static function end_explicit(): void
    {
        self::$explicit_depth = max(0, self::$explicit_depth - 1);
    }

    /**
     * Run a merchant-triggered write path with the explicit context set.
     * Re-entrant: nested explicit handlers do not clear the context early.
     */
    public static function run_explicit(callable $fn): mixed
    {
        self::begin_explicit();
        try {
            return $fn();
        } finally {
            self::end_explicit();
        }
    }

    /**
     * Reset the explicit-context counter (test harness isolation only).
     */
    public static function reset_explicit(): void
    {
        self::$explicit_depth = 0;
    }

    // -----------------------------------------------------------------------
    // Migration
    // -----------------------------------------------------------------------

    /**
     * Seed the options the gate relies on without ever overwriting a value the
     * merchant chose. Idempotent via `alegra_connector_gate_migration_version`.
     */
    public static function maybe_migrate(): void
    {
        // Guard 1: the gate options (payment sweep, sync flags, customer
        // toggle). The version stays at 1 so existing installs are untouched.
        if ((int) get_option('alegra_connector_gate_migration_version', 0) < 1) {
            // 1. Controllable payment sweep (branch A of Phase 0.4: default true).
            if (get_option('alegra_connector_payment_reconcile_enabled') === false) {
                add_option('alegra_connector_payment_reconcile_enabled', true, '', 'no');
            }
            if (get_option('alegra_connector_payment_reconcile_batch') === false) {
                add_option('alegra_connector_payment_reconcile_batch', 20, '', 'no');
            }

            // 2. sync_*: close the UI/runtime gap on installs missing the row.
            foreach (['sync_products', 'sync_customers', 'sync_orders', 'sync_categories'] as $key) {
                $option = 'alegra_connector_' . $key;
                if (get_option($option) === false) {
                    add_option($option, false, '', 'no');
                }
            }

            // 3. Independent customer toggle: preserve the previous behaviour.
            if (get_option('alegra_connector_push_customers_enabled') === false) {
                add_option(
                    'alegra_connector_push_customers_enabled',
                    (bool) get_option('alegra_connector_push_products_enabled', false),
                    '',
                    'yes'
                );
            }

            update_option('alegra_connector_gate_migration_version', 1);
        }

        // Guard 2 (independent): webhook event selection. An existing install
        // must keep receiving every event, so seed all 12 when the option is
        // absent. Its own guard leaves the gate migration version at 1.
        self::maybe_migrate_webhook_events();
    }

    /**
     * Seed the webhook event selection with ALL documented events for an
     * existing install, so adding the selector is a no-op on upgrade.
     *
     * Idempotent via `alegra_connector_webhook_events_migration_version` and it
     * never overwrites a selection the merchant already made.
     */
    private static function maybe_migrate_webhook_events(): void
    {
        if ((int) get_option('alegra_connector_webhook_events_migration_version', 0) >= 1) {
            return;
        }

        if (get_option('alegra_connector_webhook_selected_events') === false) {
            add_option(
                'alegra_connector_webhook_selected_events',
                \Alegra\Connector\API\Client::get_webhook_events(),
                '',
                'no'
            );
        }

        update_option('alegra_connector_webhook_events_migration_version', 1);
    }
}
