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
    public static function find_wc_id(string $alegra_type, string $alegra_id, string $wc_entity_type): ?int
    {
        global $wpdb;

        // 1. Try the indexed table
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_entity_id FROM {$wpdb->prefix}alegra_entity_map
             WHERE alegra_type = %s AND alegra_id = %s AND wc_entity_type = %s
             LIMIT 1",
            $alegra_type, $alegra_id, $wc_entity_type
        ));

        if ($id) {
            return (int) $id;
        }

        // 2. Fallback to postmeta/usermeta (legacy data)
        if ($wc_entity_type === 'product') {
            $meta_key = '_alegra_item_id';
            $table = $wpdb->postmeta;
            $id_col = 'post_id';
        } elseif ($wc_entity_type === 'customer') {
            $meta_key = 'alegra_contact_id';
            $table = $wpdb->usermeta;
            $id_col = 'user_id';
        } elseif ($wc_entity_type === 'order') {
            // Orders live in wc_orders_meta on HPOS, postmeta on legacy. Pick the
            // storage that actually holds the data so this fallback works on both.
            $meta_key = '_alegra_invoice_id';
            $table = HPOS::get_order_meta_table();
            $id_col = HPOS::is_enabled() ? 'order_id' : 'post_id';
        } elseif ($wc_entity_type === 'category') {
            $meta_key = 'alegra_category_id';
            $table = $wpdb->termmeta;
            $id_col = 'term_id';
        } else {
            return null;
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT {$id_col} FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
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
    public static function map(string $alegra_type, string $alegra_id, string $wc_entity_type, int $wc_entity_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_entity_map';

        // INSERT ... ON DUPLICATE KEY UPDATE
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (alegra_type, alegra_id, wc_entity_type, wc_entity_id, synced_at)
             VALUES (%s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE wc_entity_id = VALUES(wc_entity_id), synced_at = VALUES(synced_at)",
            $alegra_type, $alegra_id, $wc_entity_type, $wc_entity_id, current_time('mysql')
        ));

        return $result !== false;
    }

    /**
     * Remove a mapping. Called when a WC entity is deleted/unlinked so a stale
     * mapping can never point at a ghost id (AC-60).
     */
    public static function remove(string $alegra_type, string $alegra_id, string $wc_entity_type): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}alegra_entity_map
             WHERE alegra_type = %s AND alegra_id = %s AND wc_entity_type = %s",
            $alegra_type, $alegra_id, $wc_entity_type
        ));
    }

    /**
     * Remove every mapping that points at a given WC entity (AC-60).
     */
    public static function remove_by_wc(string $wc_entity_type, int $wc_entity_id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}alegra_entity_map
             WHERE wc_entity_type = %s AND wc_entity_id = %d",
            $wc_entity_type, $wc_entity_id
        ));
    }

    /**
     * O(1) image-dedup lookup: the URL hash is stored as alegra_id with
     * alegra_type='image'. Never falls back to an unindexed postmeta scan
     * (AC-23) — the caller does that once and backfills via map().
     */
    public static function find_attachment_id(string $url_hash): ?int
    {
        return self::find_wc_id('image', $url_hash, 'attachment');
    }

    /**
     * Delete mappings whose WC target no longer exists (AC-60 reconciliation).
     *
     * @return array{products:int, customers:int, orders:int}
     */
    public static function reconcile_orphans(int $batch = 1000): array
    {
        global $wpdb;
        $removed = ['products' => 0, 'customers' => 0, 'orders' => 0];
        $table = $wpdb->prefix . 'alegra_entity_map';

        // [wc_entity_type, source table, id column, extra WHERE, stats key]
        $checks = [
            ['product',  $wpdb->posts, 'ID', "AND post_type = 'product'", 'products'],
            ['customer', $wpdb->users, 'ID', '', 'customers'],
        ];

        foreach ($checks as [$wc_type, $src, $id_col, $extra, $key]) {
            $orphans = $wpdb->get_col($wpdb->prepare(
                "SELECT m.wc_entity_id FROM {$table} m
                 LEFT JOIN {$src} s ON s.{$id_col} = m.wc_entity_id {$extra}
                 WHERE m.wc_entity_type = %s AND s.{$id_col} IS NULL
                 LIMIT %d",
                $wc_type,
                $batch
            ));
            foreach ((array) $orphans as $id) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} WHERE wc_entity_type = %s AND wc_entity_id = %d",
                    $wc_type,
                    (int) $id
                ));
                $removed[$key]++;
            }
        }

        return $removed;
    }

    /**
     * Backfill the entity_map table from existing postmeta data.
     *
     * AC-21: this used to load every matching postmeta/usermeta row into memory
     * in one get_results() (75k rows at the audit's scale) and issue one write
     * per row. It is now a keyset-paginated, opt-in bulk operation: each batch
     * is released before the next is read.
     *
     * The lazy per-lookup backfill in find_wc_id() remains the primary path;
     * this method exists for the explicit maintenance/backfill admin action.
     *
     * @return array{products:int, customers:int, orders:int, total:int}
     */
    public static function backfill_from_postmeta(bool $dry_run = false, int $batch_size = 1000): array
    {
        global $wpdb;
        $stats = ['products' => 0, 'customers' => 0, 'orders' => 0, 'total' => 0];
        $batch_size = max(1, $batch_size);

        // Products — keyset on meta_id.
        self::backfill_batch(
            "SELECT meta_id AS pk, post_id AS entity_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_alegra_item_id' AND meta_value > '' AND meta_id > %d
             ORDER BY meta_id ASC LIMIT %d",
            $batch_size,
            fn(array $row) => self::map('item', (string) $row->meta_value, 'product', (int) $row->entity_id),
            $dry_run,
            $stats['products']
        );

        // Customers — keyset on umeta_id.
        self::backfill_batch(
            "SELECT umeta_id AS pk, user_id AS entity_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'alegra_contact_id' AND meta_value > '' AND umeta_id > %d
             ORDER BY umeta_id ASC LIMIT %d",
            $batch_size,
            fn(array $row) => self::map('contact', (string) $row->meta_value, 'customer', (int) $row->entity_id),
            $dry_run,
            $stats['customers']
        );

        // Orders (only those with invoice linked). HPOS-safe storage selection.
        $meta_table = HPOS::get_order_meta_table();
        $id_col = HPOS::is_enabled() ? 'order_id' : 'post_id';
        $pk_col = HPOS::is_enabled() ? 'id' : 'meta_id';
        self::backfill_batch(
            "SELECT {$pk_col} AS pk, {$id_col} AS entity_id, meta_value FROM {$meta_table}
             WHERE meta_key = '_alegra_invoice_id' AND meta_value > '' AND {$pk_col} > %d
             ORDER BY {$pk_col} ASC LIMIT %d",
            $batch_size,
            fn(array $row) => self::map('invoice', (string) $row->meta_value, 'order', (int) $row->entity_id),
            $dry_run,
            $stats['orders']
        );

        $stats['total'] = $stats['products'] + $stats['customers'] + $stats['orders'];

        return $stats;
    }

    /**
     * Run one keyset-paginated backfill query to exhaustion.
     *
     * @param callable(array):void $writer
     */
    private static function backfill_batch(string $sql, int $batch_size, callable $writer, bool $dry_run, int &$counter): void
    {
        global $wpdb;
        $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $last, $batch_size));
            $count = is_array($rows) ? count($rows) : 0;
            if ($count === 0) {
                break;
            }
            foreach ($rows as $row) {
                if (!$dry_run) {
                    $writer($row);
                }
                $counter++;
                $last = (int) $row->pk;
            }
            unset($rows);
        } while ($count >= $batch_size);
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
