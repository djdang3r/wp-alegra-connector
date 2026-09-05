<?php
/**
 * Tombstone_Manager — Records products deleted in WC to prevent recreation.
 *
 * When a user deletes a product in WooCommerce, we record a "tombstone" so that
 * future pulls from Alegra won't recreate it.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Tombstone_Manager
{
    /**
     * Hook: before_delete_post
     *
     * If the deleted post is a product with _alegra_item_id, record a tombstone.
     */
    public static function on_post_delete(int $post_id): void
    {
        // Only products and variations
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['product', 'product_variation'], true)) {
            return;
        }

        $alegra_id = (int) get_post_meta($post_id, '_alegra_item_id', true);
        if ($alegra_id <= 0) {
            return;
        }

        self::create([
            'alegra_id' => $alegra_id,
            'alegra_type' => 'item',
            'wc_post_id' => $post_id,
            'deleted_by' => get_current_user_id(),
            'reason' => 'manual_wc',
        ]);
    }

    /**
     * Create or update a tombstone.
     */
    public static function create(array $data): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, resurrected_at FROM $table
             WHERE alegra_type = %s AND alegra_id = %d",
            $data['alegra_type'],
            $data['alegra_id']
        ));

        if ($existing && $existing->resurrected_at) {
            // Was resurrected before — remove the resurrection marker
            $wpdb->update(
                $table,
                ['resurrected_at' => null, 'deleted_at' => current_time('mysql')],
                ['id' => $existing->id]
            );
            return true;
        }

        if ($existing) {
            // Already exists, skip insert (UNIQUE constraint would fail anyway)
            return false;
        }

        $wpdb->insert($table, [
            'alegra_type' => $data['alegra_type'],
            'alegra_id' => $data['alegra_id'],
            'wc_post_id' => $data['wc_post_id'] ?? null,
            'wc_user_id' => $data['wc_user_id'] ?? null,
            'deleted_at' => current_time('mysql'),
            'deleted_by' => $data['deleted_by'] ?? null,
            'reason' => $data['reason'] ?? 'manual_wc',
        ]);

        return (bool) $wpdb->insert_id;
    }

    /**
     * Check if a tombstone exists for the given Alegra ID.
     */
    public static function exists(string $alegra_type, int $alegra_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table
             WHERE alegra_type = %s AND alegra_id = %d
             AND resurrected_at IS NULL
             LIMIT 1",
            $alegra_type,
            $alegra_id
        ));

        return (bool) $found;
    }

    /**
     * Mark a tombstone as resurrected (when an item reappears via pull).
     */
    public static function mark_resurrected(string $alegra_type, int $alegra_id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';

        $wpdb->update(
            $table,
            ['resurrected_at' => current_time('mysql')],
            [
                'alegra_type' => $alegra_type,
                'alegra_id' => $alegra_id,
            ]
        );
    }

    /**
     * Get all tombstones for a given Alegra type.
     */
    public static function list(string $alegra_type = 'item'): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE alegra_type = %s ORDER BY deleted_at DESC LIMIT 100",
            $alegra_type
        ));
    }
}
