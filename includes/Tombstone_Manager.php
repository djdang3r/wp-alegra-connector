<?php
/**
 * Tombstone_Manager — Records entities deleted (in WC or in Alegra) to prevent recreation.
 *
 * When a user deletes a product in WooCommerce, OR when Alegra sends a
 * delete-item webhook, we record a "tombstone" so that future pulls from
 * Alegra won't recreate the corresponding WC entity.
 *
 * Tombstone `reason` values:
 *   - 'manual_wc'      : user deleted the product/customer in WP admin
 *   - 'bulk_wc'        : deleted through the WP/WC bulk-delete action (2.5.0)
 *   - 'alegra_deleted' : webhook received from Alegra that the item/client
 *                        was deleted in Alegra (added in 2.1.9)
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
     * Clasifica best-effort el borrado. Default seguro: manual_wc (WP-CLI, REST y
     * llamadas programáticas no tienen $_REQUEST). D4, design.md:616-637.
     *
     * WordPress NO manda `action=delete_all`: "Empty Trash" es un submit
     * `name="delete_all"` (top) / `delete_all2` (bottom), y
     * WP_Posts_List_Table::current_action() devuelve 'delete_all' con
     * isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2']).
     * Ver wp-admin/includes/class-wp-posts-list-table.php:606 y :625-631.
     */
    private static function classify_delete_reason(): string
    {
        if (!is_admin()) {
            return 'manual_wc';
        }

        // 1) "Empty Trash": señal PRIMARIA, no `action=delete_all` (D5).
        if (isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])) {
            return 'bulk_wc';
        }

        // 2) La acción viaja en `action` (select de arriba) o `action2` (select de
        //    abajo). WP ignora `-1`, así que se cae al fallback `action2`.
        $action = isset($_REQUEST['action'])
            ? sanitize_key(wp_unslash((string) $_REQUEST['action']))
            : '';
        if ($action === '' || $action === '-1') {
            $action = isset($_REQUEST['action2'])
                ? sanitize_key(wp_unslash((string) $_REQUEST['action2']))
                : '';
        }
        if ($action === 'delete_all') { // defensivo: algunos plugins lo postean así
            return 'bulk_wc';
        }

        // 3) Selección masiva: WP manda `post[]` (array). Un solo ítem (array de
        //    largo 1) cae en manual_wc (default seguro).
        $post = $_REQUEST['post'] ?? null;
        if (is_array($post) && count($post) > 1) {
            return 'bulk_wc';
        }

        return 'manual_wc';
    }

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

        $alegra_id = (string) get_post_meta($post_id, '_alegra_item_id', true);
        if ($alegra_id === '') {
            return;
        }

        self::create([
            'alegra_id' => $alegra_id,
            'alegra_type' => 'item',
            'wc_post_id' => $post_id,
            'deleted_by' => get_current_user_id(),
            'reason' => self::classify_delete_reason(),
        ]);

        // AC-60: drop the indexed mapping so it cannot point at a ghost id.
        Entity_Map::remove('item', $alegra_id, 'product');
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
             WHERE alegra_type = %s AND alegra_id = %s",
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
     * Devuelve el `reason` del tombstone vigente, o null si no hay.
     * reason: 'manual_wc' | 'bulk_wc' | 'alegra_deleted' (Schema.php:159, VARCHAR(50)).
     */
    public static function exists_with_reason(string $alegra_type, string $alegra_id): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_tombstones';

        $reason = $wpdb->get_var($wpdb->prepare(
            "SELECT reason FROM $table
             WHERE alegra_type = %s AND alegra_id = %s
             AND resurrected_at IS NULL
             LIMIT 1",
            $alegra_type,
            $alegra_id
        ));

        return $reason !== null ? (string) $reason : null;
    }

    /**
     * Check if a tombstone exists for the given Alegra ID.
     *
     * @deprecated Usar exists_with_reason() cuando haga falta la política (D4).
     */
    public static function exists(string $alegra_type, string $alegra_id): bool
    {
        return self::exists_with_reason($alegra_type, $alegra_id) !== null;
    }

    /**
     * Mark a tombstone as resurrected (when an item reappears via pull).
     */
    public static function mark_resurrected(string $alegra_type, string $alegra_id): void
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
