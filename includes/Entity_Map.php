<?php
/**
 * Entity_Map — Indexed Alegra ↔ WC mapping table.
 *
 * Replaces slow lookups via wp_postmeta._alegra_item_id with O(1) indexed lookup
 * on wp_alegra_entity_map.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Entity_Map
{
    /**
     * Look up a WC entity ID by Alegra ID + type.
     * Tries the new table first, falls back to postmeta for backward compat.
     */
    public static function find_wc_id(string $alegra_type, int $alegra_id, string $wc_entity_type): ?int
    {
        global $wpdb;

        // 1. Try the indexed table
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_entity_id FROM {$wpdb->prefix}alegra_entity_map
             WHERE alegra_type = %s AND alegra_id = %d AND wc_entity_type = %s
             LIMIT 1",
            $alegra_type, $alegra_id, $wc_entity_type
        ));

        if ($id) {
            return (int) $id;
        }

        // 2. Fallback to postmeta (legacy data)
        if ($wc_entity_type === 'product') {
            $meta_key = '_alegra_item_id';
            $table = $wpdb->postmeta;
            $id_col = 'post_id';
        } elseif ($wc_entity_type === 'customer') {
            $meta_key = 'alegra_contact_id';
            $table = $wpdb->usermeta;
            $id_col = 'user_id';
        } elseif ($wc_entity_type === 'order') {
            $meta_key = '_alegra_invoice_id';
            $table = $wpdb->postmeta;
            $id_col = 'post_id';
        } else {
            return null;
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT {$id_col} FROM {$table} WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            $meta_key, $alegra_id
        ));

        if ($id) {
            // Backfill to the new table
            self::map($alegra_type, $alegra_id, $wc_entity_type, (int) $id);
            return (int) $id;
        }

        return null;
    }

    /**
     * Record a new mapping.
     */
    public static function map(string $alegra_type, int $alegra_id, string $wc_entity_type, int $wc_entity_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_entity_map';

        // INSERT ... ON DUPLICATE KEY UPDATE
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (alegra_type, alegra_id, wc_entity_type, wc_entity_id, synced_at)
             VALUES (%s, %d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE wc_entity_id = VALUES(wc_entity_id), synced_at = VALUES(synced_at)",
            $alegra_type, $alegra_id, $wc_entity_type, $wc_entity_id, current_time('mysql')
        ));

        return $result !== false;
    }

    /**
     * Backfill the entity_map table from existing postmeta data.
     *
     * @return array{products:int, customers:int, orders:int, total:int}
     */
    public static function backfill_from_postmeta(bool $dry_run = false): array
    {
        global $wpdb;
        $stats = ['products' => 0, 'customers' => 0, 'orders' => 0, 'total' => 0];

        // Products
        $products = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_alegra_item_id' AND meta_value > ''"
        );
        foreach ($products as $row) {
            if (!$dry_run) {
                self::map('item', (int) $row->meta_value, 'product', (int) $row->post_id);
            }
            $stats['products']++;
        }

        // Customers
        $customers = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'alegra_contact_id' AND meta_value > '' AND meta_value > 0"
        );
        foreach ($customers as $row) {
            if (!$dry_run) {
                self::map('contact', (int) $row->meta_value, 'customer', (int) $row->user_id);
            }
            $stats['customers']++;
        }

        // Orders (only those with invoice linked)
        $orders = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_alegra_invoice_id' AND meta_value > '' AND meta_value > 0"
        );
        foreach ($orders as $row) {
            if (!$dry_run) {
                self::map('invoice', (int) $row->meta_value, 'order', (int) $row->post_id);
            }
            $stats['orders']++;
        }

        $stats['total'] = $stats['products'] + $stats['customers'] + $stats['orders'];

        return $stats;
    }

    /**
     * Get stats about the entity_map table.
     */
    public static function stats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_entity_map';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $by_type = $wpdb->get_results(
            "SELECT alegra_type, wc_entity_type, COUNT(*) as cnt
             FROM $table GROUP BY alegra_type, wc_entity_type"
        );

        $breakdown = [];
        foreach ($by_type as $row) {
            $key = $row->alegra_type . '->' . $row->wc_entity_type;
            $breakdown[$key] = (int) $row->cnt;
        }

        return ['total' => $total, 'breakdown' => $breakdown];
    }
}
