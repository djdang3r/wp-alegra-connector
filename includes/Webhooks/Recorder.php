<?php

declare(strict_types=1);

namespace Alegra\Connector\Webhooks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded, read-only record of the most recent webhook deliveries.
 *
 * The receiver used to keep no raw payload, so there was no way to inspect what
 * Alegra actually sends. This recorder keeps the last MAX_ENTRIES deliveries in
 * a SINGLE non-autoloaded option as a ring buffer: a new entry past the cap
 * drops the oldest one, and a body over MAX_BODY_BYTES is truncated. Storage is
 * therefore hard-bounded no matter how many deliveries arrive.
 *
 * It is purely additive: it never changes the receiver's response, token gate,
 * handshake, replay window or handler dispatch. It is also the single home of
 * the payload inspection helpers the read-only inspector and its tests use, so
 * the verdict logic cannot drift between the tool and the tests.
 */
final class Recorder
{
    /**
     * Option holding the ring buffer. Non-autoloaded: it is only read by the
     * inspector / admin tooling, never on a frontend request.
     */
    public const OPTION = 'alegra_connector_webhook_recent';

    /** Hard cap on the number of retained deliveries. */
    public const MAX_ENTRIES = 50;

    /** Per-entry body cap, in bytes. Larger bodies are truncated. */
    public const MAX_BODY_BYTES = 20480; // 20 KB

    /** Verdict: at least one edit-item carried inventory.availableQuantity. */
    public const VERDICT_YES = 'Alegra SÍ envía inventario en edit-item';

    /** Verdict: edit-item deliveries exist but none carried inventory. */
    public const VERDICT_NO = 'Alegra NO envía inventario en edit-item — la reconciliación debe ser por poll';

    /** Verdict: no edit-item delivery was captured at all. */
    public const VERDICT_NONE = 'No hay entregas de edit-item en la ventana guardada';

    /**
     * Append a delivery to the bounded ring buffer.
     *
     * @param string $subject The validated webhook subject.
     * @param string $body    The RAW request body, exactly as received.
     * @param string $ip      The source IP, when available.
     */
    public static function record(string $subject, string $body, string $ip = ''): void
    {
        $entries = self::all();

        $original_size = strlen($body);
        $truncated = $original_size > self::MAX_BODY_BYTES;
        if ($truncated) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $entries[] = [
            'time'      => function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'subject'   => $subject,
            'body'      => $body,
            'bytes'     => $original_size,
            'truncated' => $truncated,
            'ip'        => self::sanitize_ip($ip),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION, $entries, false);
    }

    /**
     * Every retained delivery, oldest first.
     *
     * @return array<int,array{time:string,timestamp:int,subject:string,body:string,bytes:int,truncated:bool,ip:string}>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $entry) {
            if (is_array($entry) && array_key_exists('body', $entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Decode a stored raw body, or null when it is not a JSON object.
     *
     * @return array<string,mixed>|null
     */
    public static function payload(string $body): ?array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * One-line summary of the entity in a payload, e.g. `item 865 — "Camiseta azul"`.
     */
    public static function entity_summary(string $body): string
    {
        $payload = self::payload($body);
        if ($payload === null) {
            return '';
        }
        $message = $payload['message'] ?? null;
        if (!is_array($message)) {
            return '';
        }

        foreach (['item', 'client', 'invoice', 'bill'] as $type) {
            $entity = $message[$type] ?? null;
            if (!is_array($entity)) {
                continue;
            }
            $id = (string) ($entity['id'] ?? '');
            $name = $entity['name'] ?? ($entity['number'] ?? '');
            if (is_array($name)) {
                $name = (string) ($name['name'] ?? ($name['fullname'] ?? ''));
            }
            $name = trim((string) $name);
            $label = $type . ' ' . ($id !== '' ? $id : '?');
            return $name !== '' ? $label . ' — "' . $name . '"' : $label;
        }

        return '';
    }

    /**
     * The item id carried by a payload (empty when absent).
     */
    public static function item_id(string $body): string
    {
        $payload = self::payload($body);
        if ($payload === null) {
            return '';
        }
        $item = $payload['message']['item'] ?? null;
        return is_array($item) ? (string) ($item['id'] ?? '') : '';
    }

    /**
     * Whether a payload carries `message.item.inventory.availableQuantity`.
     */
    public static function has_inventory_available_quantity(string $body): bool
    {
        $payload = self::payload($body);
        if ($payload === null) {
            return false;
        }
        $inventory = $payload['message']['item']['inventory'] ?? null;
        return is_array($inventory) && array_key_exists('availableQuantity', $inventory);
    }

    /**
     * The raw `availableQuantity` value, or null when it is absent.
     */
    public static function available_quantity(string $body): mixed
    {
        $payload = self::payload($body);
        if ($payload === null) {
            return null;
        }
        $inventory = $payload['message']['item']['inventory'] ?? null;
        if (!is_array($inventory) || !array_key_exists('availableQuantity', $inventory)) {
            return null;
        }
        return $inventory['availableQuantity'];
    }

    /**
     * The verdict on whether edit-item carries inventory, over a set of entries.
     */
    public static function inventory_verdict(array $entries): string
    {
        $edit_items = 0;
        $with_inventory = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || (string) ($entry['subject'] ?? '') !== 'edit-item') {
                continue;
            }
            $edit_items++;
            if (self::has_inventory_available_quantity((string) ($entry['body'] ?? ''))) {
                $with_inventory++;
            }
        }

        if ($edit_items === 0) {
            return self::VERDICT_NONE;
        }

        return $with_inventory > 0 ? self::VERDICT_YES : self::VERDICT_NO;
    }

    /**
     * Validate an IP without trusting the request; returns '' when invalid.
     */
    private static function sanitize_ip(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
