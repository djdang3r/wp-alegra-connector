<?php
/**
 * Price Lists — robust resolver for the merchant's price-list id.
 *
 * Alegra moved item-level price-list ids from INTEGER to VARCHAR/UUID; the
 * hardcoded default of `1` ("General") that older code paths assumed silently
 * fails on accounts that do not have a list with that numeric id. The error
 * surfaces as "No se encontró la lista de precios con id: 1" on every
 * `/items` POST (admin "Crear ítem de envío", Products::sync_to_alegra, the
 * shared generic service item used by Orders for shipping/fee lines).
 *
 * Three callers converge on the same problem (admin shipping handler, the
 * Products sync, and the Orders generic-item resolver). Rather than triplicate
 * the workaround, they all call Price_Lists::resolve_id(), which:
 *
 *   1. Reads the configured id from `alegra_connector_field_mapping`
 *      (`regular_price_list`, default `1`).
 *   2. Fetches the account's price lists through `Client::get_price_lists()`
 *      and caches them in a transient for 5 minutes (one network call per
 *      batch, not one per item). Empty/error responses are NOT cached, so a
 *      transient network blip can be retried on the next call.
 *   3. If the configured id is present in the fetched lists → use it.
 *   4. Else, if at least one list exists → use the FIRST list's id (logs a
 *      warning so the merchant notices the mismatch and can update the
 *      mapping on "Mapeo de Campos → Listas de Precios").
 *   5. Else (API errored or the account has zero lists) → fall back to the
 *      configured id and surface `fetched_ok` + `lists` so the caller can
 *      decide whether to emit a clear merchant-facing error (admin path) or
 *      let Alegra's own response explain the rejection (sync paths).
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Price_Lists
{
    /**
     * Transient slot for the cached price-lists response. Stored only when the
     * fetch SUCCEEDED; a transient network blip is allowed to retry.
     */
    private const TRANSIENT_KEY = 'alegra_connector_price_lists_v1';

    /**
     * 5 minutes — long enough to collapse a burst (one item per WC checkout)
     * into a single fetch, short enough that reconnection after a kill-switch
     * cycle picks up the new account state quickly.
     */
    private const CACHE_TTL = 300;

    /**
     * Resolve a price-list id that EXISTS in the merchant's Alegra account.
     *
     * @param API\Client|null $api       Connection to Alegra. Null skips the fetch
     *                                   and falls straight to the fallback id.
     * @param bool            $use_cache When false (tests only) the transient
     *                                   is bypassed and the API is hit on every
     *                                   call. Production should leave it true.
     *
     * @return array{
     *     id: int|string,
     *     lists: array<int, array<string, mixed>>,
     *     fetched_ok: bool,
     *     source: 'configured'|'first_available'|'no_lists'|'api_error'|'no_api'
     * }
     *
     * `id` is always populated (the resolver NEVER returns empty). The other
     * fields are diagnostics the admin handler uses to surface a clear,
     * merchant-facing error when the API reachable but the account has zero
     * price lists configured.
     */
    public static function resolve_id(?API\Client $api, bool $use_cache = true): array
    {
        $configured = self::configured_id();

        $lists = [];
        $fetched_ok = false;

        if ($api !== null) {
            if ($use_cache) {
                $cached = get_transient(self::TRANSIENT_KEY);
                if (is_array($cached) && array_key_exists('fetched_ok', $cached)) {
                    $lists = is_array($cached['lists'] ?? null) ? $cached['lists'] : [];
                    $fetched_ok = (bool) $cached['fetched_ok'];
                } else {
                    $fresh = self::fetch_lists($api);
                    $lists = $fresh['lists'];
                    $fetched_ok = $fresh['fetched_ok'];
                    // Only cache successful fetches so a transient 500 / DNS
                    // blip is allowed to retry on the next call.
                    if ($fetched_ok) {
                        set_transient(self::TRANSIENT_KEY, $fresh, self::CACHE_TTL);
                    }
                }
            } else {
                $fresh = self::fetch_lists($api);
                $lists = $fresh['lists'];
                $fetched_ok = $fresh['fetched_ok'];
            }
        }

        // 1+2: a usable list is available — prefer the configured id if it is
        // actually present, otherwise fall back to the first list.
        if (!empty($lists)) {
            $configured_str = (string) $configured;
            foreach ($lists as $row) {
                if (!is_array($row)) { continue; }
                if ((string) ($row['id'] ?? '') === $configured_str) {
                    return [
                        'id'         => $configured,
                        'lists'      => $lists,
                        'fetched_ok' => true,
                        'source'     => 'configured',
                    ];
                }
            }

            $first_id = (string) ($lists[0]['id'] ?? '');
            if ($first_id !== '') {
                self::log_fallback($configured, $first_id);
                return [
                    'id'         => $first_id,
                    'lists'      => $lists,
                    'fetched_ok' => true,
                    'source'     => 'first_available',
                ];
            }
        }

        // 3: no usable list. Pick a `source` so the caller can decide.
        if ($api === null) {
            $source = 'no_api';
        } elseif ($fetched_ok) {
            // API reachable, returned 200, but the account has zero lists.
            // This is the actionable case for the merchant-facing UI.
            $source = 'no_lists';
        } else {
            $source = 'api_error';
        }

        return [
            'id'         => $configured,
            'lists'      => $lists,
            'fetched_ok' => $fetched_ok,
            'source'     => $source,
        ];
    }

    /**
     * Drop the cached price-lists response. Hooked on credential changes and
     * kill-switch transitions so a reconnected account does not read stale
     * data; not strictly required (5-min TTL bounds the staleness) but cheap.
     */
    public static function invalidate_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Read the merchant-configured `regular_price_list`. Returns the raw value
     * (int or string) so a UUID stays a UUID — never coerced to 0.
     *
     * A leading-numeric STRING (e.g. `"7"` from an older mapping page that
     * wrote it as text) is normalised to an INT for backward compatibility
     * with the pre-2.8.1 hardcoded `(int) ... ?? 1` behaviour; the JSON
     * encoder emits both shapes the same on the wire so Alegra parses them
     * identically. Non-numeric strings (UUIDs) stay strings.
     */
    private static function configured_id(): int|string
    {
        $map = get_option('alegra_connector_field_mapping', []);
        $raw = is_array($map) ? ($map['regular_price_list'] ?? 1) : 1;

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') { return 1; }
            if (ctype_digit($trimmed)) { return (int) $trimmed; }
            return $trimmed;
        }
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }
        return 1;
    }

    /**
     * Fetch and normalise the account's price lists.
     *
     * @return array{lists: array<int, array<string, mixed>>, fetched_ok: bool}
     */
    private static function fetch_lists(API\Client $api): array
    {
        $resp = $api->get_price_lists();
        if (is_wp_error($resp) || !is_array($resp)) {
            return ['lists' => [], 'fetched_ok' => false];
        }

        // Alegra paginates some collections as {data: [...]}; unwrap so the
        // resolver only ever sees a flat list of rows.
        if (isset($resp['data']) && is_array($resp['data'])) {
            $resp = $resp['data'];
        }

        $clean = [];
        foreach ($resp as $row) {
            if (!is_array($row)) { continue; }
            if ((string) ($row['id'] ?? '') === '') { continue; }
            $clean[] = $row;
        }

        return ['lists' => $clean, 'fetched_ok' => true];
    }

    /**
     * Log the fallback so the merchant notices the mismatch on the next
     * review and updates "Mapeo de Campos → Listas de Precios" to the real id.
     */
    private static function log_fallback(int|string $configured, string $used): void
    {
        if (function_exists('error_log')) {
            error_log(sprintf(
                '[alegra-connector] regular_price_list=%s not found in account price lists; using first available id=%s',
                (string) $configured,
                $used
            ));
        }
    }
}