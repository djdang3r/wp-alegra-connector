<?php
/**
 * Inventory pusher — ledger + WC → Alegra delta push (D2).
 *
 * T1.10 crea la superficie estática del ledger. T3.1 agrega el constructor,
 * los hooks y push_delta(); NO redefine estos helpers.
 *
 * @package Alegra\Connector\Sync
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

final class Inventory_Pusher
{
    public const META_SYNCED  = '_alegra_stock_synced';
    public const META_PENDING = '_alegra_stock_push_pending';

    /**
     * Last quantity WC and Alegra agreed on. '' when never baselined.
     */
    public static function synced(int $product_id): int|string
    {
        $value = get_post_meta($product_id, self::META_SYNCED, true);
        return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
    }

    public static function set_synced(int $product_id, int $qty): void
    {
        update_post_meta($product_id, self::META_SYNCED, $qty);
    }

    /**
     * WC quantity currently being pushed (set before the POST, cleared on OK).
     * '' when no push is in flight.
     */
    public static function pending(int $product_id): int|string
    {
        $value = get_post_meta($product_id, self::META_PENDING, true);
        return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
    }

    public static function set_pending(int $product_id, int $qty): void
    {
        update_post_meta($product_id, self::META_PENDING, $qty);
    }

    public static function clear_pending(int $product_id): void
    {
        delete_post_meta($product_id, self::META_PENDING);
    }
}
