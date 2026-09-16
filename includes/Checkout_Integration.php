<?php
/**
 * Checkout Integration — dual support for Blocks + legacy checkout.
 *
 * Detects which checkout the store uses and registers the Alegra billing
 * fields accordingly:
 *   - Blocks checkout  → `woocommerce_register_additional_checkout_field()`
 *     (native conditional visibility/required via JSON Schema).
 *   - Legacy checkout  → `woocommerce_checkout_fields` (already injected by
 *     Billing_Fields) + a small vanilla-JS layer for the conditionals.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Checkout_Integration
{
    /**
     * Transient that caches the detected checkout type.
     */
    private const TRANSIENT_KEY = 'alegra_checkout_type';

    /**
     * Blocks namespace for the registered additional fields.
     */
    private const FIELD_NAMESPACE = 'alegra-connector';

    /**
     * Cache lifetime for the detection result, in seconds.
     */
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Detect whether the store uses the Checkout Blocks or the legacy
     * shortcode checkout. Result is cached for one hour.
     *
     * @return string 'blocks' | 'legacy'
     */
    public static function detect(): string
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached === 'blocks' || $cached === 'legacy') {
            return $cached;
        }

        // WooCommerce not loaded yet: do NOT cache a guess.
        if (!function_exists('wc_get_page_id')) {
            return 'legacy';
        }

        $result = self::detect_uncached();
        set_transient(self::TRANSIENT_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Detect the checkout type without reading or writing the cache.
     */
    private static function detect_uncached(): string
    {
        $checkout_page_id = (int) wc_get_page_id('checkout');
        if ($checkout_page_id <= 0) {
            return 'legacy';
        }

        if (function_exists('has_block') && has_block('woocommerce/checkout', $checkout_page_id)) {
            return 'blocks';
        }

        $content = (string) get_post_field('post_content', $checkout_page_id);
        if (strpos($content, '[woocommerce_checkout]') !== false) {
            return 'legacy';
        }

        return 'legacy';
    }

    /**
     * Register the billing fields for both checkout systems. Idempotent.
     *
     * The branch decision is deferred to `woocommerce_init` so WooCommerce
     * functions are guaranteed to be available.
     */
    public static function register(): void
    {
        Billing_Fields::register_hooks();

        add_action('woocommerce_init', static function (): void {
            if (self::detect() === 'blocks') {
                self::register_blocks_fields();
            }
        }, 20);

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_scripts']);

        self::register_cache_invalidation();
    }

    /**
     * Invalidate the cached checkout type when the checkout page or its content
     * changes, so a merchant switching between the Checkout Block and the
     * shortcode checkout never gets a stale detection for up to an hour.
     */
    private static function register_cache_invalidation(): void
    {
        add_action('update_option_woocommerce_checkout_page_id', [self::class, 'clear_cache']);

        add_action('save_post_page', static function (int $post_id): void {
            if (!function_exists('wc_get_page_id')) {
                return;
            }
            if ($post_id === (int) wc_get_page_id('checkout')) {
                self::clear_cache();
            }
        });
    }

    /**
     * Register the Alegra fields with the Checkout Blocks API.
     */
    private static function register_blocks_fields(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            // Billing_Fields injects the fields through `woocommerce_checkout_fields`.
            return;
        }

        foreach (Billing_Fields::CATALOG as $key => $field) {
            if (!Billing_Fields::is_field_enabled($key)) {
                continue;
            }

            woocommerce_register_additional_checkout_field(self::block_field_args($key, $field));
        }
    }

    /**
     * Build the argument array for a single Checkout Blocks field.
     *
     * @param array<string, mixed> $field Catalog entry.
     * @return array<string, mixed>
     */
    private static function block_field_args(string $key, array $field): array
    {
        // Checkout Blocks supports text/select/checkbox only.
        $type_map = ['select' => 'select', 'textarea' => 'text', 'text' => 'text'];
        $type = $type_map[(string) ($field['type'] ?? 'text')] ?? 'text';

        // Only `require_data` hard-blocks checkout. In `auto`/`always_generic`
        // the fields stay optional so the Consumidor Final fallback can run.
        $require_mode = Billing_Fields::is_require_data_mode();

        $args = [
            'id'       => self::FIELD_NAMESPACE . '/' . $key,
            'label'    => (string) $field['label'],
            'location' => 'address',
            'type'     => $type,
            'required' => $require_mode && !empty($field['required']),
        ];

        if ($type === 'select') {
            $args['options'] = self::block_options($key, $field);
        }

        $condition = self::condition_for($key);
        if ($condition !== null) {
            $args['required'] = $require_mode ? $condition['required'] : false;
            $args['hidden'] = $condition['hidden'];
        }

        return $args;
    }

    /**
     * Options for a select field, in the Blocks API shape.
     *
     * @param array<string, mixed> $field Catalog entry.
     * @return array<int, array<string, string>>
     */
    private static function block_options(string $key, array $field): array
    {
        if ($key === 'idtype') {
            $map = Billing_Fields::ID_TYPES;
        } else {
            $map = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
        }

        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    /**
     * JSON Schema visibility/required conditions for the conditional fields.
     *
     * @return array{required: mixed, hidden: mixed}|null
     */
    private static function condition_for(string $key): ?array
    {
        $schema = static function (array $properties): array {
            return [
                'customer' => [
                    'properties' => [
                        'address' => [
                            'properties' => $properties,
                        ],
                    ],
                ],
            ];
        };

        switch ($key) {
            case 'dv':
                return [
                    'required' => $schema([self::FIELD_NAMESPACE . '/idtype' => ['const' => 'NIT']]),
                    'hidden'   => $schema([self::FIELD_NAMESPACE . '/idtype' => ['not' => ['const' => 'NIT']]]),
                ];
        }

        return null;
    }

    /**
     * Enqueue the conditional-fields script on the legacy checkout only.
     */
    public static function enqueue_scripts(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (self::detect() !== 'legacy') {
            return;
        }

        wp_enqueue_script(
            'alegra-checkout-conditions',
            ALEGRA_CONNECTOR_URL . 'assets/js/alegra-checkout-conditions.js',
            ['jquery'],
            ALEGRA_CONNECTOR_VERSION,
            true
        );

        wp_localize_script('alegra-checkout-conditions', 'alegraCheckout', [
            'prefix'        => 'billing_alegra_',
            // AC-35d: user-facing strings, no longer hardcoded in the JS file.
            'strings'       => [
                'selectPlaceholder' => __('Seleccione…', 'alegra-connector'),
            ],
        ]);
    }

    /**
     * Clear the cached checkout type. Call after saving settings.
     */
    public static function clear_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }
}
