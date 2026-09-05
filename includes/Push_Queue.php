<?php
/**
 * Push_Queue — Pending pushes WC → Alegra awaiting user approval.
 *
 * When push direction is "manual", hooks enqueue here instead of pushing
 * directly. User reviews and approves/rejects each one from admin UI.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Push_Queue
{
    /**
     * Get current push direction for an entity type.
     *
     * @return string 'disabled' | 'manual' | 'auto'
     */
    public static function get_direction(string $entity_type): string
    {
        return (string) get_option('alegra_connector_push_direction_' . $entity_type, 'disabled');
    }

    /**
     * Enqueue a push action for user review.
     */
    public static function enqueue(string $entity_type, int $entity_id, string $action, array $payload = []): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';

        $wpdb->insert($table, [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'action' => $action,
            'payload_json' => $payload ? wp_json_encode($payload) : null,
            'status' => 'pending',
            'detected_at' => current_time('mysql'),
            'detected_by' => get_current_user_id() ?: null,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Get pending items.
     */
    public static function get_pending(int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'pending' ORDER BY detected_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Get all items (history).
     */
    public static function get_all(int $limit = 100, ?string $status = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY detected_at DESC LIMIT %d",
                $status, $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY detected_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Mark an item as approved (will be applied by separate process).
     */
    public static function approve(int $id, int $user_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        return (bool) $wpdb->update(
            $table,
            [
                'status' => 'approved',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => $user_id,
            ],
            ['id' => $id, 'status' => 'pending']
        );
    }

    /**
     * Mark an item as rejected.
     */
    public static function reject(int $id, int $user_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        return (bool) $wpdb->update(
            $table,
            [
                'status' => 'rejected',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => $user_id,
            ],
            ['id' => $id, 'status' => 'pending']
        );
    }

    /**
     * Mark an item as applied (push completed).
     */
    public static function mark_applied(int $id, ?string $error = null): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_queue';
        return (bool) $wpdb->update(
            $table,
            [
                'status' => $error ? 'failed' : 'applied',
                'applied_at' => current_time('mysql'),
                'error_message' => $error,
            ],
            ['id' => $id]
        );
    }

    /**
     * Log a push attempt to audit table.
     */
    public static function log_push(string $entity_type, int $entity_id, string $action, array $request_payload, $response, ?int $http_code = null, ?int $user_id = null): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alegra_push_log';

        $error_message = null;
        $response_json = null;
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
        } elseif (is_array($response)) {
            $response_json = wp_json_encode($response);
        }

        $wpdb->insert($table, [
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'alegra_id' => is_array($response) && isset($response['id']) ? (int) $response['id'] : null,
            'action' => $action,
            'request_payload' => wp_json_encode($request_payload),
            'response_payload' => $response_json,
            'http_code' => $http_code,
            'error_message' => $error_message,
        ]);
    }
}
