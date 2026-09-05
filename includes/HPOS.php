<?php

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class HPOS
{
    private static ?bool $enabled = null;

    public static function is_enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        if (!class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            self::$enabled = false;
            return false;
        }

        self::$enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        return self::$enabled;
    }

    public static function get_orders_table(): string
    {
        global $wpdb;
        return self::is_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
    }

    public static function get_order_meta_table(): string
    {
        global $wpdb;
        return self::is_enabled() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
    }

    public static function get_order_type_condition(string $alias = 'p'): string
    {
        if (self::is_enabled()) {
            return "{$alias}.type = 'shop_order'";
        }
        return "{$alias}.post_type = 'shop_order'";
    }

    public static function get_order_status_column(string $alias = 'p'): string
    {
        if (self::is_enabled()) {
            return "{$alias}.status";
        }
        return "{$alias}.post_status";
    }

    public static function get_order_date_column(string $alias = 'p'): string
    {
        if (self::is_enabled()) {
            return "{$alias}.date_created_gmt";
        }
        return "{$alias}.post_date";
    }
}
