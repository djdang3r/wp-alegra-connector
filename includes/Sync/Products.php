<?php
/**
 * Products Sync Handler
 *
 * Bidirectional sync for simple and variable products plus the item import.
 *
 * PUSHING a variable product: Alegra models it as ONE `type=variantParent`
 * item that carries `variantAttributes` (min 1) — an array of
 * `{id, options:[{id}]}` referencing EXISTING Alegra variant attribute/option
 * IDs — plus an optional `itemVariants` list. The child variations are separate
 * `type=variant` items created by Alegra from `itemVariants`; the plugin never
 * creates them standalone. The parent is not a `kit`: `subitems` is kit-only
 * and `variant` is not in the WRITE enum (`product|service|kit|variantParent`).
 *
 * @see self::sync_variable_product()
 * @see https://developer.alegra.com/reference/items__createitem.md
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Products
{
    /**
     * Documented cap: a `variantParent` accepts at most 100 explicit
     * `itemVariants` entries (and at most 100 cartesian combinations).
     *
     * @see https://developer.alegra.com/reference/items__createitem.md
     */
    private const MAX_ITEM_VARIANTS = 100;

    private ?API\Client $api;
    private ?Logger\Logger $logger;

    /**
     * AC-63: per-instance Alegra-category-id / name → WC term id caches. Loaded
     * once (single indexed query) and reused for every imported product.
     *
     * @var array<string,int>
     */
    private array $category_id_map = [];
    /** @var array<string,int> */
    private array $category_name_map = [];
    private bool $category_map_loaded = false;

    /**
     * Per-request cache of the Alegra variant-attribute catalog, keyed by a
     * normalized name. Loaded once (paged GET /variant-attributes) and reused
     * for every variation so a variable product push never hits the API once
     * per variation.
     *
     * @var array{by_name:array<string,array>,list:array<int,array>}|null
     */
    private ?array $variant_attribute_index = null;

    /**
     * Wall-clock deadline for the current chunked page (T3.2.b/C8). 0 means
     * "no budget": the deadline is only enforced while a chunked page is in
     * flight. Shared with the page loop via the static so image downloads
     * honour the same budget as the item loop (NFR-04/NFR-05).
     */
    private static float $deadline = 0.0;

    /**
     * Per-page image counters. The owner is this class (T3.2.b); the chunked
     * page resets them before the products loop and T5.2a adds the remaining
     * increments.
     *
     * @var array<string,int>
     */
    private static array $image_stats = ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0];

    public static function set_deadline(float $deadline): void
    {
        self::$deadline = $deadline;
    }

    public static function clear_deadline(): void
    {
        self::$deadline = 0.0;
    }

    public static function deadline_exhausted(): bool
    {
        return self::$deadline > 0.0 && microtime(true) >= self::$deadline;
    }

    public static function reset_image_stats(): void
    {
        self::$image_stats = ['ok' => 0, 'blocked' => 0, 'download' => 0, 'sideload' => 0, 'deferred' => 0];
    }

    /**
     * @return array{ok:int,blocked:int,download:int,sideload:int,deferred:int,failed:int}
     */
    public static function image_stats(): array
    {
        $s = self::$image_stats;
        $s['failed'] = $s['blocked'] + $s['download'] + $s['sideload'];
        return $s;
    }

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Load the Alegra category → WC term map once per instance (AC-63).
     */
    private function load_category_map(): void
    {
        if ($this->category_map_loaded) {
            return;
        }
        $this->category_map_loaded = true;

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT term_id, meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'alegra_category_id' AND meta_value > ''"
        );
        foreach ((array) $rows as $row) {
            $this->category_id_map[(string) $row->meta_value] = (int) $row->term_id;
        }
    }

    /**
     * Sync a WooCommerce product (simple or variable) to Alegra
     */
    public function sync_to_alegra(\WC_Product $product): array|\WP_Error
    {
        if ($product->is_type('variable')) {
            return $this->sync_variable_product($product);
        }

        if ($product->is_type('variation')) {
            return $this->sync_variation($product);
        }

        $result = $this->sync_simple_product($product);

        // Sync product image if enabled
        if (!is_wp_error($result) && get_option('alegra_connector_sync_images', true)) {
            $this->sync_product_image($product, (string) ($result['id'] ?? ''));
        }

        return $result;
    }

    /**
     * Upload product featured image to Alegra item attachment
     */
    private function sync_product_image(\WC_Product $product, string $alegra_id): void
    {
        if ($alegra_id === '') return;

        $image_id = $product->get_image_id();
        if (!$image_id) return;

        $file_path = get_attached_file($image_id);
        if (!$file_path || !file_exists($file_path)) return;

        // Check file size (max 2MB per Alegra docs)
        if (filesize($file_path) > 2 * 1024 * 1024) {
            $this->logger->warning('Product image too large for Alegra (max 2MB)', [
                'product_id' => $product->get_id(), 'size' => filesize($file_path),
            ]);
            return;
        }

        $result = $this->api->upload_item_image($alegra_id, $file_path);
        if (is_wp_error($result)) {
            $this->logger->warning('Failed to upload product image to Alegra', [
                'product_id' => $product->get_id(), 'alegra_id' => $alegra_id, 'error' => $result->get_error_message(),
            ]);
        } else {
            $this->logger->info('Product image synced to Alegra', [
                'product_id' => $product->get_id(), 'alegra_id' => $alegra_id,
            ]);
        }
    }

    /**
     * Sync simple product
     */
    private function sync_simple_product(\WC_Product $product): array|\WP_Error
    {
        $alegra_id = (string) get_post_meta($product->get_id(), '_alegra_item_id', true);
        $linked_by_sku = false;

        // Resolve an existing Alegra item by SKU BEFORE building the payload:
        // the builder needs the final create-vs-update intent so it never
        // re-sends `initialQuantity` on an update (R2 hotfix).
        if ($alegra_id === '') {
            // Search by SKU in Alegra before creating (prevent duplicates)
            $sku = $product->get_sku();
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $alegra_id = (string) $items[0]['id'];
                    $linked_by_sku = true;
                    update_post_meta($product->get_id(), '_alegra_item_id', $alegra_id);
                }
            }
        }

        $is_create = ($alegra_id === '');
        $data = $this->prepare_simple_product_data($product, $is_create);

        if (!$is_create) {
            $result = $this->api->update_item($alegra_id, $data);
            // Write Gate: nothing reached Alegra. Return the marker without
            // logging a false success.
            if (API\Client::write_was_blocked($result)) {
                return $result;
            }
            if ($linked_by_sku) {
                $this->logger->info('Product linked to existing Alegra item by SKU', [
                    'product_id' => $product->get_id(),
                    'alegra_id' => $alegra_id,
                ]);
            } else {
                $this->logger->info('Product updated in Alegra', [
                    'product_id' => $product->get_id(),
                    'alegra_id' => $alegra_id,
                ]);
            }

            return $result;
        }

        // AC-16: the mapped "default status" only applies on creation.
        $data['status'] = $this->field_mapping('default_status', 'active');
        $result = $this->api->create_item($data);
        if (!is_wp_error($result) && isset($result['id'])) {
            update_post_meta($product->get_id(), '_alegra_item_id', (string) $result['id']);
            $this->logger->info('Product created in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $result['id'],
            ]);
        }

        return $result;
    }

    /**
     * Sync a variable product (WC parent) to Alegra as ONE `variantParent`.
     *
     * The parent carries `variantAttributes` (min 1) plus one `itemVariants`
     * entry per WC variation. The child variations are created by Alegra from
     * `itemVariants`; the plugin does NOT create them standalone (a `variant`
     * is a READ-only type). After the write, the returned child ids are mapped
     * back onto the WC variations.
     */
    private function sync_variable_product(\WC_Product $product): array|\WP_Error
    {
        $alegra_id = (string) get_post_meta($product->get_id(), '_alegra_item_id', true);

        // Resolve an existing Alegra item by SKU before building (prevent dupes).
        if ($alegra_id === '') {
            $sku = $product->get_sku();
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $alegra_id = (string) $items[0]['id'];
                    update_post_meta($product->get_id(), '_alegra_item_id', $alegra_id);
                }
            }
        }

        $is_create = ($alegra_id === '');
        $built = $this->prepare_variable_product_data($product, $is_create);
        if (is_wp_error($built)) {
            return $built;
        }
        $data = $built['data'];
        $selections = $built['selections'];

        if ($is_create) {
            // AC-16: the mapped "default status" only applies on creation.
            $data['status'] = $this->field_mapping('default_status', 'active');
            $result = $this->api->create_item($data);
            if (is_wp_error($result)) {
                return $result;
            }
            // Dry Run / Write Gate: no item was created. Return the marker
            // (consistent with the simple-product path) instead of a misleading
            // "no id" error.
            if (API\Client::write_was_blocked($result)) {
                return $result;
            }
            $parent_id = (string) ($result['id'] ?? '');
            if ($parent_id === '') {
                return new \WP_Error(
                    'variable_product_no_id',
                    __('Alegra no devolvió el id del producto variable creado.', 'alegra-connector')
                );
            }
            update_post_meta($product->get_id(), '_alegra_item_id', $parent_id);
            $this->logger->info('Variable product created in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $parent_id,
                'variants' => count($selections),
            ]);
        } else {
            $result = $this->api->update_item($alegra_id, $data);
            if (is_wp_error($result)) {
                return $result;
            }
            if (API\Client::write_was_blocked($result)) {
                return $result;
            }
            $parent_id = $alegra_id;
            $this->logger->info('Variable product updated in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $parent_id,
                'variants' => count($selections),
            ]);
        }

        // Map the child variant ids back to the WC variations.
        $this->map_variant_children($parent_id, $result, $selections);

        if (get_option('alegra_connector_sync_images', true)) {
            $this->sync_product_image($product, $parent_id);
        }

        return $result;
    }

    /**
     * Sync a single variation.
     *
     * A variation only exists in Alegra as an `itemVariants` entry on its
     * `variantParent`; syncing it means syncing the parent (which re-emits the
     * whole `itemVariants` list).
     */
    private function sync_variation(\WC_Product $variation): array|\WP_Error
    {
        $parent_id = $variation->get_parent_id();
        $parent = $parent_id > 0 ? wc_get_product($parent_id) : false;
        if (!$parent || !$parent->is_type('variable')) {
            return new \WP_Error(
                'variation_without_parent',
                __('La variación no tiene un producto variable padre en WooCommerce.', 'alegra-connector')
            );
        }

        return $this->sync_variable_product($parent);
    }

    /**
     * Build the documented `variantParent` payload for a WC variable product.
     *
     * @return array{data:array,selections:array<int,string>}|\WP_Error
     *         `selections` maps each WC variation id to its canonical attribute
     *         signature (`attrId:optionId` sorted) used to match Alegra children.
     */
    private function prepare_variable_product_data(\WC_Product $product, bool $is_create): array|\WP_Error
    {
        $variation_ids = $product->get_children();
        if (empty($variation_ids)) {
            return new \WP_Error(
                'variable_product_no_variations',
                __('El producto variable no tiene variaciones; no se puede construir el variantParent.', 'alegra-connector')
            );
        }

        $defs = $this->collect_variation_attribute_defs($product);
        if (empty($defs)) {
            return new \WP_Error(
                'variable_product_no_attributes',
                __('El producto variable no tiene atributos de variación configurados; Alegra exige variantAttributes.', 'alegra-connector')
            );
        }

        // Resolve each variation's canonical option value per attribute.
        $rows = [];
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product((int) $variation_id);
            if (!$variation) {
                continue;
            }
            $selection = $this->variation_selection($variation);
            $combo = [];
            foreach ($defs as $key => $def) {
                $raw = (string) ($selection[$key] ?? '');
                if ($raw === '') {
                    return new \WP_Error(
                        'variable_product_attribute_missing',
                        sprintf(
                            /* translators: 1: variation id, 2: attribute label */
                            __('La variación #%1$d no tiene valor para el atributo "%2$s"; no se puede mapear a Alegra.', 'alegra-connector'),
                            (int) $variation_id,
                            $def['name']
                        )
                    );
                }
                $norm = $this->normalize_variant_token($raw);
                if (!isset($def['options'][$norm])) {
                    return new \WP_Error(
                        'variable_product_attribute_missing',
                        sprintf(
                            /* translators: 1: option value, 2: attribute label, 3: variation id */
                            __('La opción "%1$s" del atributo "%2$s" (variación #%3$d) no se pudo resolver en Alegra.', 'alegra-connector'),
                            $raw,
                            $def['name'],
                            (int) $variation_id
                        )
                    );
                }
                $combo[$key] = $def['options'][$norm];
            }
            $rows[] = ['variation' => $variation, 'combo' => $combo];
        }

        if (empty($rows)) {
            return new \WP_Error(
                'variable_product_no_variations',
                __('No se pudieron cargar las variaciones del producto variable.', 'alegra-connector')
            );
        }
        if (count($rows) > self::MAX_ITEM_VARIANTS) {
            return new \WP_Error(
                'variable_product_too_many_variants',
                sprintf(
                    /* translators: 1: variation count, 2: documented max */
                    __('El producto tiene %1$d variaciones; Alegra acepta como máximo %2$d.', 'alegra-connector'),
                    count($rows),
                    self::MAX_ITEM_VARIANTS
                )
            );
        }

        // Resolve (or create) the Alegra attribute + option ids. Cached per request.
        $resolved = [];
        foreach ($defs as $key => $def) {
            $resolved_attr = $this->resolve_variant_attribute($def['name'], $def['options']);
            if (is_wp_error($resolved_attr)) {
                return $resolved_attr;
            }
            $resolved[$key] = $resolved_attr;
        }

        // Parent `variantAttributes`: only the options actually used by a variation
        // (so Alegra never auto-generates a variant we did not send).
        $variant_attributes = [];
        foreach ($defs as $key => $def) {
            $used = [];
            foreach ($rows as $row) {
                $norm = $this->normalize_variant_token($row['combo'][$key]);
                $option_id = (string) ($resolved[$key]['options'][$norm] ?? '');
                if ($option_id !== '') {
                    $used[$option_id] = ['id' => $option_id];
                }
            }
            if (empty($used)) {
                continue;
            }
            $variant_attributes[] = ['id' => $resolved[$key]['id'], 'options' => array_values($used)];
        }
        if (empty($variant_attributes)) {
            return new \WP_Error(
                'variable_product_no_attributes',
                __('No se pudieron resolver los atributos de variación en Alegra.', 'alegra-connector')
            );
        }

        $warehouse = $this->resolve_warehouse_id();

        // One `itemVariants` entry per WC variation.
        $item_variants = [];
        $selections = [];
        foreach ($rows as $row) {
            $variation = $row['variation'];
            $combo_attrs = [];
            $signature_parts = [];
            foreach ($defs as $key => $def) {
                $norm = $this->normalize_variant_token($row['combo'][$key]);
                $option_id = (string) ($resolved[$key]['options'][$norm] ?? '');
                if ($option_id === '') {
                    return new \WP_Error(
                        'variable_product_attribute_missing',
                        sprintf(
                            /* translators: 1: option value, 2: attribute label */
                            __('No se pudo resolver la opción "%1$s" del atributo "%2$s" en Alegra.', 'alegra-connector'),
                            $row['combo'][$key],
                            $def['name']
                        )
                    );
                }
                $combo_attrs[] = ['id' => $resolved[$key]['id'], 'options' => [['id' => $option_id]]];
                $signature_parts[] = $resolved[$key]['id'] . ':' . $option_id;
            }
            sort($signature_parts, SORT_STRING);
            $selections[$variation->get_id()] = implode('|', $signature_parts);

            $entry = ['variantAttributes' => $combo_attrs];

            // On update, an existing child is referenced by its id; a new one
            // omits the id so Alegra creates it.
            if (!$is_create) {
                $existing = (string) get_post_meta($variation->get_id(), '_alegra_item_id', true);
                if ($existing !== '') {
                    $entry['id'] = $existing;
                }
            }

            // Per-variation inventory: only for a variation that manages stock,
            // only on create (R3: never re-send initialQuantity on update) and
            // only when a target warehouse is configured — a variant's inventory
            // is warehouse-only (no unit/unitCost/initialQuantity at its level).
            if ($is_create && $variation->get_manage_stock() && $warehouse !== '') {
                $entry['inventory'] = ['warehouses' => [[
                    'id' => $warehouse,
                    'initialQuantity' => (int) ($variation->get_stock_quantity() ?? 0),
                ]]];
            }

            $item_variants[] = $entry;
        }

        $data = [
            'name' => $product->get_name(),
            'reference' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_description()),
            'type' => 'variantParent',
            // A variable product's own `_regular_price` is normally empty; use
            // `get_price()`, which WC_Product_Variable resolves to the lowest
            // variation price (the old builder used get_price() too).
            'price' => [[
                'idPriceList' => $this->price_list_id(),
                'price' => (float) $product->get_price(),
            ]],
            'variantAttributes' => $variant_attributes,
            'itemVariants' => $item_variants,
        ];

        $tax_id = $this->map_product_tax($product);
        if ($tax_id !== '') {
            $data['tax'] = [['id' => $tax_id]];
        }

        $alegra_cat_id = $this->resolve_alegra_category_id($product);
        if ($alegra_cat_id !== '') {
            // Alegra has TWO category fields: `category` is the ACCOUNTING
            // category (a chart-of-accounts entry, e.g. "Ventas") and
            // `itemCategory` is the COMMERCIAL item category managed by
            // /item-categories. The id resolved here is an item-category id, so
            // it must be sent under `itemCategory`.
            // @see https://developer.alegra.com/reference/items__createitem.md
            $data['itemCategory'] = ['id' => $alegra_cat_id];
        }

        return ['data' => $data, 'selections' => $selections];
    }

    /**
     * Variation attributes (keyed by their `_product_attributes` key) that the
     * variations actually vary on. Read from the canonical `_product_attributes`
     * meta (the same store the import path writes) so both directions agree.
     *
     * @return array<string, array{name:string, options:array<string,string>, is_taxonomy:bool}>
     *         `options` maps a normalized token (value or slug) to its display value.
     */
    private function collect_variation_attribute_defs(\WC_Product $product): array
    {
        $raw = get_post_meta($product->get_id(), '_product_attributes', true);
        if (!is_array($raw)) {
            return [];
        }

        $defs = [];
        foreach ($raw as $key => $def) {
            if (!is_array($def) || empty($def['is_variation'])) {
                continue;
            }
            $key = (string) $key;
            $label = trim((string) ($def['name'] ?? ''));
            if ($label === '') {
                $label = $this->humanize_attribute_key($key);
            }
            $is_taxonomy = !empty($def['is_taxonomy']);

            $options = [];
            if ($is_taxonomy) {
                $terms = get_the_terms($product->get_id(), $key);
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (!is_object($term)) {
                            continue;
                        }
                        $name = (string) ($term->name ?? '');
                        if ($name === '') {
                            continue;
                        }
                        $options[$this->normalize_variant_token($name)] = $name;
                        $slug = (string) ($term->slug ?? '');
                        if ($slug !== '') {
                            $options[$this->normalize_variant_token($slug)] = $name;
                        }
                    }
                }
            } else {
                foreach (explode('|', (string) ($def['value'] ?? '')) as $option) {
                    $option = trim($option);
                    if ($option !== '') {
                        $options[$this->normalize_variant_token($option)] = $option;
                    }
                }
            }

            if (!empty($options)) {
                $defs[$key] = ['name' => $label, 'options' => $options, 'is_taxonomy' => $is_taxonomy];
            }
        }

        return $defs;
    }

    /**
     * A variation's chosen value per attribute key, from `get_variation_attributes()`
     * (`attribute_color` → `Rojo`). Empty ("any") values are dropped.
     *
     * @return array<string,string>
     */
    private function variation_selection(\WC_Product $variation): array
    {
        $selection = [];
        foreach ($variation->get_variation_attributes() as $key => $value) {
            $key = preg_replace('/^attribute_/', '', (string) $key) ?? '';
            if ($key === '') {
                continue;
            }
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value === '') {
                continue;
            }
            $selection[$key] = $value;
        }

        return $selection;
    }

    /**
     * The whole Alegra variant-attribute catalog, loaded once per request and
     * indexed by a normalized name so a push never resolves per variation.
     *
     * @return array{by_name:array<string,array>,list:array<int,array>}|\WP_Error
     */
    private function alegra_variant_attribute_index(): array|\WP_Error
    {
        if ($this->variant_attribute_index !== null) {
            return $this->variant_attribute_index;
        }

        $list = [];
        $start = 0;
        // limit max is 30 (documented); page defensively.
        for ($page = 0; $page < 20; $page++) {
            $batch = $this->api->get_variant_attributes(['start' => $start, 'limit' => 30]);
            if (is_wp_error($batch)) {
                return $batch;
            }
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $attribute) {
                if (is_array($attribute) && isset($attribute['id'])) {
                    $list[] = $attribute;
                }
            }
            if (count($batch) < 30) {
                break;
            }
            $start += 30;
        }

        $by_name = [];
        foreach ($list as $attribute) {
            $by_name[$this->normalize_variant_token((string) ($attribute['name'] ?? ''))] = $attribute;
        }

        $this->variant_attribute_index = ['by_name' => $by_name, 'list' => $list];

        return $this->variant_attribute_index;
    }

    /**
     * Resolve a WC variation attribute (by name, case/whitespace-insensitive)
     * to an Alegra variant attribute, creating the attribute and/or its missing
     * options when needed.
     *
     * @param array<string,string> $options normalized token → display value
     * @return array{id:string, options:array<string,string>}|\WP_Error
     */
    private function resolve_variant_attribute(string $label, array $options): array|\WP_Error
    {
        $index = $this->alegra_variant_attribute_index();
        if (is_wp_error($index)) {
            return $index;
        }

        $norm_label = $this->normalize_variant_token($label);
        $attribute = $index['by_name'][$norm_label] ?? null;

        if ($attribute === null) {
            $created = $this->api->create_variant_attribute([
                'name' => $label,
                'options' => array_map(
                    static fn ($value): array => ['value' => (string) $value],
                    array_values($options)
                ),
            ]);
            if (is_wp_error($created)) {
                return new \WP_Error(
                    'variant_attribute_create_failed',
                    sprintf(
                        /* translators: 1: attribute label, 2: API error */
                        __('No se pudo crear el atributo de variación "%1$s" en Alegra: %2$s', 'alegra-connector'),
                        $label,
                        $created->get_error_message()
                    )
                );
            }
            $attribute = $created;
            $this->variant_attribute_index['by_name'][$norm_label] = $attribute;
            $this->variant_attribute_index['list'][] = $attribute;
        }

        // Existing options, keyed by normalized value.
        $existing = [];
        foreach ((array) ($attribute['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $existing[$this->normalize_variant_token((string) ($option['value'] ?? ''))] = (string) ($option['id'] ?? '');
        }

        // Add any missing options. PUT resends the full list: existing options
        // keep their id, new ones carry only `value`
        // (https://developer.alegra.com/reference/put_variant-attributes-id).
        $missing = [];
        foreach ($options as $norm => $value) {
            if (!isset($existing[$norm]) || $existing[$norm] === '') {
                $missing[$norm] = (string) $value;
            }
        }
        if (!empty($missing)) {
            $payload_options = [];
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $payload_options[] = [
                        'id' => (string) $option['id'],
                        'value' => (string) ($option['value'] ?? ''),
                    ];
                }
            }
            foreach ($missing as $value) {
                $payload_options[] = ['value' => $value];
            }

            $updated = $this->api->update_variant_attribute((string) $attribute['id'], [
                'name' => (string) ($attribute['name'] ?? $label),
                'options' => $payload_options,
            ]);
            if (is_wp_error($updated)) {
                return new \WP_Error(
                    'variant_attribute_update_failed',
                    sprintf(
                        /* translators: 1: attribute label, 2: API error */
                        __('No se pudieron agregar opciones al atributo de variación "%1$s" en Alegra: %2$s', 'alegra-connector'),
                        $label,
                        $updated->get_error_message()
                    )
                );
            }
            $attribute = $updated;
            $this->variant_attribute_index['by_name'][$norm_label] = $attribute;
            foreach ($this->variant_attribute_index['list'] as $i => $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === (string) ($attribute['id'] ?? '')) {
                    $this->variant_attribute_index['list'][$i] = $attribute;
                }
            }
        }

        $attribute_id = (string) ($attribute['id'] ?? '');
        if ($attribute_id === '') {
            return new \WP_Error(
                'variant_attribute_no_id',
                sprintf(
                    /* translators: %s: attribute label */
                    __('Alegra no devolvió el id del atributo de variación "%s".', 'alegra-connector'),
                    $label
                )
            );
        }

        $option_ids = [];
        foreach ((array) ($attribute['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $option_ids[$this->normalize_variant_token((string) ($option['value'] ?? ''))] = (string) ($option['id'] ?? '');
        }

        return ['id' => $attribute_id, 'options' => $option_ids];
    }

    /**
     * Persist `_alegra_item_id` on each WC variation from the child variants
     * returned by the create/update response, falling back to a documented
     * `GET /items?variantParent_id={id}` when the response carries no children.
     *
     * @param array<int,string> $selections variation id → attribute signature
     */
    private function map_variant_children(string $parent_id, array $response, array $selections): void
    {
        $children = [];
        if (!empty($response['itemVariants']) && is_array($response['itemVariants'])) {
            $children = $response['itemVariants'];
        }
        if (empty($children)) {
            $children = $this->fetch_variant_children($parent_id);
        }
        if (empty($children)) {
            $this->logger->warning('Variable product: no child variants returned; only the parent was mapped', [
                'alegra_id' => $parent_id,
            ]);
            return;
        }

        $mapped = 0;
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $child_id = (string) ($child['id'] ?? '');
            if ($child_id === '') {
                continue;
            }
            $signature = $this->variant_signature((array) ($child['variantAttributes'] ?? []));
            if ($signature === '') {
                continue;
            }
            foreach ($selections as $variation_id => $selection_signature) {
                if ($selection_signature === $signature) {
                    update_post_meta((int) $variation_id, '_alegra_item_id', $child_id);
                    \Alegra\Connector\Entity_Map::map('item', $child_id, 'product', (int) $variation_id);
                    $mapped++;
                    break;
                }
            }
        }

        if ($mapped < count($selections)) {
            $this->logger->warning('Variable product: some variations could not be mapped to Alegra child ids', [
                'alegra_id' => $parent_id,
                'mapped' => $mapped,
                'expected' => count($selections),
            ]);
        }
    }

    /**
     * Child variants of a variantParent, via the documented `variantParent_id`
     * filter (limit max 30, so page defensively).
     *
     * @return array<int,array>
     */
    private function fetch_variant_children(string $parent_id): array
    {
        $children = [];
        $start = 0;
        for ($page = 0; $page < 5; $page++) {
            $batch = $this->api->get_items([
                'variantParent_id' => $parent_id,
                'start' => $start,
                'limit' => 30,
                'mode' => 'advanced',
            ]);
            if (is_wp_error($batch) || empty($batch)) {
                break;
            }
            foreach ($batch as $child) {
                if (is_array($child)) {
                    $children[] = $child;
                }
            }
            if (count($batch) < 30) {
                break;
            }
            $start += 30;
        }

        return $children;
    }

    /**
     * Canonical signature of an Alegra variant's attribute combination
     * (`attrId:optionId` sorted). Must mirror the signatures built from the WC
     * side in prepare_variable_product_data().
     *
     * @param array<int,array> $variant_attributes
     */
    private function variant_signature(array $variant_attributes): string
    {
        $parts = [];
        foreach ($variant_attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $attribute_id = (string) ($attribute['id'] ?? '');
            if ($attribute_id === '') {
                continue;
            }
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                $option_id = '';
                if (is_array($option)) {
                    $option_id = (string) ($option['id'] ?? '');
                } elseif (is_scalar($option)) {
                    $option_id = (string) $option;
                }
                if ($option_id !== '') {
                    $parts[] = $attribute_id . ':' . $option_id;
                }
            }
        }
        sort($parts, SORT_STRING);

        return implode('|', $parts);
    }

    /**
     * Normalize a token (attribute name, option value or term slug) for
     * case/whitespace-insensitive matching against Alegra.
     */
    private function normalize_variant_token(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /**
     * Turn an attribute key (`pa_color`, `color`) into a readable label.
     */
    private function humanize_attribute_key(string $key): string
    {
        $key = preg_replace('/^pa_/', '', $key) ?? $key;
        return ucwords(str_replace(['-', '_'], ' ', $key));
    }

    /**
     * Prepare data for simple product
     */
    private function prepare_simple_product_data(\WC_Product $product, bool $is_create): array
    {
        $data = [
            'name' => $product->get_name(),
            'reference' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_description()),
            // The WRITE enum for `type` is product|service|variantParent|kit
            // (POST/PUT /items). `simple` is only the READ representation:
            // GET /items returns `simple` for a plain product, which is what
            // originally caused this mismatch. A plain product is `product`.
            'type' => 'product',
            'price' => [
                [
                    'idPriceList' => $this->price_list_id(),
                    'price' => (float) $product->get_regular_price(),
                ],
            ],
        ];

        // R3 hotfix: send the WHOLE `inventory` object only on CREATE.
        //
        // CREATE is the documented place to set the initial stock
        // (`inventory.unit` + `inventory.unitCost` + `inventory.initialQuantity`).
        //
        // On UPDATE we omit `inventory` entirely:
        //  - Alegra documents `unit`, `unitCost` and `initialQuantity` as
        //    obligatorios *when the object is present*, so a partial
        //    `{unit}` payload is undocumented (400 risk) and an absent
        //    `initialQuantity` could be read as 0 (stock corruption).
        //  - `PUT /items/{id}` is a partial update ("solo enviar los campos que
        //    cambiarán") and `inventory` is not required, so omitting it leaves
        //    the inventory untouched.
        //  - Re-sending `initialQuantity` on update is the R2 corruption bug.
        //
        // Stock changes must go through `POST /inventory-adjustments` (the
        // documented stock-movement endpoint), never through `PUT /items`.
        // Wiring that path is out of scope for this hotfix.
        if ($is_create) {
            $data['inventory'] = [
                'unit' => $this->field_mapping('default_unit', 'unit'),
                // The docs mark `unitCost` as obligatorio whenever `inventory`
                // is present ("unit (obligatorio) ... unitCost (obligatorio)
                // ... initialQuantity (obligatorio)"). Omitting it was the
                // reason a create could be rejected. Falls back to 0 when the
                // store has no cost source.
                'unitCost' => $this->product_unit_cost($product),
                'initialQuantity' => (int) ($product->get_stock_quantity() ?? 0),
            ];
        }

        $this->apply_warehouse($data, $product, $is_create);

        $sale_price = $product->get_sale_price();
        if (!empty($sale_price) && (float) $sale_price < $product->get_regular_price()) {
            $data['price'][0]['price'] = (float) $sale_price;
        }

        // Tax
        $tax_id = $this->map_product_tax($product);
        if ($tax_id !== '') {
            $data['tax'] = [['id' => $tax_id]];
        }

        // Category. `itemCategory` is the COMMERCIAL item category (the one
        // /item-categories manages); `category` is Alegra's ACCOUNTING category
        // and must NOT receive an item-category id.
        // @see https://developer.alegra.com/reference/items__createitem.md
        $alegra_cat_id = $this->resolve_alegra_category_id($product);
        if ($alegra_cat_id !== '') {
            $data['itemCategory'] = ['id' => $alegra_cat_id];
        }

        return $data;
    }

    /**
     * Unit cost for the Alegra `inventory.unitCost` field.
     *
     * WooCommerce core has no cost field; the value comes from the Cost of
     * Goods Sold extension (`_wc_cog_cost`) or a common `_cost` meta key. When
     * neither is present we fall back to 0: the docs require the field to be
     * present and numeric but do not require it to be > 0, so 0 is the safest
     * documented-valid default.
     */
    private function product_unit_cost(\WC_Product $product): float
    {
        foreach (['_wc_cog_cost', '_cost'] as $key) {
            $raw = get_post_meta($product->get_id(), $key, true);
            if ($raw !== '' && $raw !== null && is_numeric($raw)) {
                return (float) $raw;
            }
        }

        return 0.0;
    }

    /**
     * Map product tax class to Alegra tax ID
     */
    private function map_product_tax(\WC_Product $product): string
    {
        $tax_class = $product->get_tax_class();
        if (empty($tax_class) || $tax_class === 'zero-rate') {
            return '';
        }

        $tax_mapping = get_option('alegra_connector_tax_mapping', []);
        return isset($tax_mapping[$tax_class]) ? (string) $tax_mapping[$tax_class] : '';
    }

    /**
     * Read a saved value from the "Mapeo de Campos" settings (AC-16).
     *
     * These options are persisted by the mapping page; before this they were
     * never read, so the UI promised behavior the push path did not honor.
     */
    private function field_mapping(string $key, string $default = ''): string
    {
        $map = get_option('alegra_connector_field_mapping', []);
        if (is_array($map) && isset($map[$key]) && (string) $map[$key] !== '') {
            return (string) $map[$key];
        }
        return $default;
    }

    /**
     * Alegra price-list id for the regular price (mapping default: 1 "General").
     */
    private function price_list_id(): int
    {
        $id = (int) $this->field_mapping('regular_price_list', '1');
        return $id > 0 ? $id : 1;
    }

    /**
     * Configured Alegra warehouse id, or '' when warehouse management is off.
     */
    private function resolve_warehouse_id(): string
    {
        if (!get_option('alegra_connector_warehouse_enabled')) {
            return '';
        }
        return (string) get_option('alegra_connector_warehouse_id', '');
    }

    /**
     * Distribute a product's initial stock across the configured Alegra
     * warehouse when warehouse management is enabled (AC-16). The invoice path
     * already honored this setting; the product path did not, contradicting
     * the settings UI.
     */
    private function apply_warehouse(array &$data, \WC_Product $product, bool $is_create): void
    {
        $warehouse = $this->resolve_warehouse_id();
        if ($warehouse === '' || !isset($data['inventory']) || !is_array($data['inventory'])) {
            return;
        }

        // R3 hotfix: a warehouse `initialQuantity` is still an initial
        // quantity — it is only meaningful on create. On update the caller no
        // longer builds an `inventory` object at all, so this is a no-op; the
        // guard keeps the rule explicit if the builder ever changes.
        if (!$is_create) {
            return;
        }

        $data['inventory']['warehouses'] = [[
            'id' => $warehouse,
            'initialQuantity' => (int) ($product->get_stock_quantity() ?? 0),
        ]];
    }

    /**
     * Sync inventory from Alegra to WooCommerce (pull)
     */
    public function sync_inventory_from_alegra(int $run_id = 0): array
    {
        $result = ['updated' => 0, 'errors' => 0, 'pages' => 0, 'locked' => false, 'skipped' => false,
                   'skipped_not_manageable' => 0];

        // Kill switch guard: the pull is a sync entry point like any other and
        // must abort when the plugin is disconnected/deactivated. This was the
        // only entry point missing the check.
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Inventory pull skipped: kill switch active');
            $result['skipped'] = true;
            return $result;
        }

        // AC-16: the inventory-source setting was never read. When the merchant
        // selects WooCommerce as the source, the pull must not overwrite stock.
        if ((string) get_option('alegra_connector_inventory_source', 'alegra') === 'woocommerce') {
            $this->logger->info('Inventory pull skipped: WooCommerce is the configured inventory source');
            $result['skipped'] = true;
            return $result;
        }

        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public('products');
        if ($lock === false) {
            $this->logger->info('Inventory sync skipped: another sync is running');
            $result['locked'] = true;
            return $result;
        }

        try {
            $max_pages = 200;
            set_time_limit(300);

            for ($p = 1; $p <= $max_pages; $p++) {
                if (get_transient('alegra_sync_cancelled')) {
                    delete_transient('alegra_sync_cancelled');
                    $this->logger->info('Inventory sync cancelled by user');
                    break;
                }

                // Per-run stop (Monitor "Detener").
                if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                    $this->logger->info('Inventory sync stopped by user');
                    break;
                }

                // AC-83: throttle the progress write (every 5th page) and use a
                // TTL longer than a run so the admin UI never sees it expire
                // mid-import.
                if ($p === 1 || $p % 5 === 0) {
                    set_transient('alegra_sync_progress', [
                        'type' => 'inventory',
                        'current_page' => $p,
                        'items_processed' => $result['updated'] + $result['errors'],
                        'updated' => $result['updated'],
                        'errors' => $result['errors'],
                        'message' => sprintf(__('Sincronizando inventario... Página %d', 'alegra-connector'), $p),
                    ], 600);
                }

                // AC-51: simple mode strips `inventory` down to `unit`, so
                // `availableQuantity` is absent and the guard below skips every
                // item. Request advanced mode (as import_from_alegra already
                // does) so the pull actually updates stock.
                $items = $this->api->get_items([
                    'start' => ($p - 1) * 30,
                    'limit' => 30,
                    'mode'  => 'advanced',
                ]);

                if (is_wp_error($items)) {
                    $this->logger->error('Inventory sync: API error', ['error' => $items->get_error_message()]);
                    break;
                }
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                        $this->logger->info('Inventory sync stopped by user mid-page');
                        break 2;
                    }
                    $product_id = $this->get_product_by_alegra_id((string) $item['id']);
                    if (!$product_id) {
                        continue;
                    }

                    $product = wc_get_product($product_id);
                    if (!$product) {
                        continue;
                    }

                    // D2/D6/FIX-8 (REQ-INV-01, NFR-07): el poll NUNCA re-infla
                    // y TAMPOCO se muere de hambre.
                    $synced  = Inventory_Pusher::synced($product_id);
                    $pending = Inventory_Pusher::pending($product_id);

                    $needs_reconcile = $product->get_manage_stock()
                        && ($pending !== ''
                            || ($synced !== '' && (int) $product->get_stock_quantity() !== (int) $synced));

                    if ($needs_reconcile) {
                        // (a) push fallido en vuelo, (b) delta local sin empujar,
                        // (c) baseline pendiente (FIX-1): reconciliar WC→Alegra.
                        // `from_poll=true` bypassa `is_syncing()` (FIX-2): el poll
                        // es el que corre con set_syncing(true) (T7.3).
                        $push = (new Inventory_Pusher($this->api, $this->logger))
                            ->push_delta($product, (int) $product->get_stock_quantity(), true);

                        // FIX-8: sólo se salta WC cuando el push REALMENTE maneja
                        // el ítem. Si el pusher no es el dueño (disabled/
                        // invoice_owner/not_linked/not_manageable), cae al writer:
                        // el poll es el dueño de la escritura WC y no se congela.
                        $handled = ['ok', 'already_applied', 'in_sync', 'api_error',
                                    'blocked', 'locked', 'baseline_unverified'];
                        if (in_array($push['reason'], $handled, true)) {
                            continue;
                        }
                    }

                    $old_qty = $product->get_stock_quantity();
                    try {
                        // C7: suprimir la cascada del propio wc_update_product_stock
                        // (set_syncing del poll es T7.3; acá el guard por producto).
                        set_transient('alegra_updating_product_' . $product_id, 1, 30);

                        // D3 (REQ-INV-02): un solo escritor. El writer decide
                        // fuente/preserve/nulo/negativo/servicio/manage_stock y
                        // deriva _stock_status con wc_update_product_stock (D5).
                        $status = (new Inventory_Writer($this->logger))->apply($product, $item, [
                            'source'       => 'alegra',
                            'preserve'     => in_array('inventory', $this->resolve_preserve_fields(), true),
                            'manage_stock' => get_option('alegra_connector_inventory_manage_stock_enabled', false)
                                ? 'enable'
                                : 'respect',
                            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
                            'warehouse_id' => $this->resolve_warehouse_id(),
                        ]);

                        if ($status === 'updated' || $status === 'clamped_negative') {
                            // FIX-1: éste es el ÚNICO camino del poll que fija
                            // `synced` sin push: el poll escribió el valor de
                            // Alegra en WC, así que WC y Alegra acuerdan.
                            Inventory_Pusher::set_synced($product_id, (int) $product->get_stock_quantity());
                            Inventory_Pusher::clear_pending($product_id);
                            $result['updated']++;
                            $new_qty = $product->get_stock_quantity();
                            if ($old_qty !== $new_qty) {
                                $this->logger->info('Inventory updated from Alegra', [
                                    'product_id' => $product_id,
                                    'alegra_id'  => $item['id'],
                                    'old_qty'    => $old_qty,
                                    'new_qty'    => $new_qty,
                                ]);
                            }
                        } elseif ($status === 'skipped_not_manageable') {
                            $result['skipped_not_manageable']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error('Failed to update inventory', [
                            'product_id' => $product_id,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        delete_transient('alegra_updating_product_' . $product_id);
                    }
                }

                $result['pages'] = $p;

                if (count($items) < 30) {
                    break;
                }
            }

            // D3.4 (T2.5): report the legacy manage_stock=no backlog so the
            // merchant can decide whether to flip the opt-in.
            if ($result['skipped_not_manageable'] > 0) {
                $this->logger->info('Inventory sync: products skipped because WC does not manage stock', [
                    'count'  => $result['skipped_not_manageable'],
                    'opt_in' => (bool) get_option('alegra_connector_inventory_manage_stock_enabled', false),
                ]);
            }

            $this->logger->info('Inventory sync from Alegra completed', $result);

            return $result;
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public('products', $lock);
        }
    }

    public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array|\WP_Error
    {
        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Products import skipped: kill switch active');
            return new \WP_Error('kill_switch_active', 'Plugin is disconnected or deactivated');
        }

        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0, 'total_pages' => 0, 'current_page' => 0, 'paused' => false];
        self::reset_image_stats();
        $cursor_key = 'alegra_connector_products_import_cursor';

        // AC-19: resume from the persisted cursor. The old code always started
        // at page 1, so every interrupted run re-imported the whole catalog.
        if ($page > 1) {
            $start = ($page - 1) * $per_page;
        } else {
            $start = max(0, (int) get_option($cursor_key, 0));
        }
        $current_page = (int) floor($start / $per_page) + 1;

        // AC-19/AC-61: a wall-clock budget replaces set_time_limit(300). Stop
        // cleanly before max_execution_time and let the next run resume.
        $budget = (int) get_option('alegra_connector_import_time_budget', 240);
        if ($budget < 30) {
            $budget = 30;
        }
        $deadline = microtime(true) + $budget;

        // AC-19: 0 = unlimited. The resume cursor makes a hard page cap
        // unnecessary — a 50k-item catalog imports across several runs.
        $max_pages = (int) get_option('alegra_connector_import_max_pages', 0);
        $pages_done = 0;
        $completed = false;

        while (true) {
            // Check for cancellation
            if (get_transient('alegra_sync_cancelled')) {
                delete_transient('alegra_sync_cancelled');
                $this->logger->info('Products import cancelled by user');
                break;
            }

            // Re-check the kill switch every page so an in-flight run stops.
            if (\Alegra\Connector\Kill_Switch::is_active()) {
                $this->logger->info('Products import stopped: kill switch active');
                break;
            }

            // Per-run stop (Monitor "Detener"). Checked per page so a long
            // import reacts without waiting for the whole entity to finish.
            if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                $this->logger->info('Products import stopped by user');
                break;
            }

            // AC-19: pause at the time budget; the next run continues here.
            if (microtime(true) >= $deadline) {
                $result['paused'] = true;
                $this->logger->info('Products import paused at the time budget; resuming next run', [
                    'cursor' => $start,
                ]);
                break;
            }

            if ($max_pages > 0 && $pages_done >= $max_pages) {
                break;
            }

            // Update progress transient (AC-83: throttled to every 5th page,
            // with a TTL longer than the run).
            if ($pages_done === 0 || $pages_done % 5 === 0) {
                set_transient('alegra_sync_progress', [
                    'type' => 'products',
                    'current_page' => $current_page,
                    'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
                    'imported' => $result['imported'],
                    'updated' => $result['updated'],
                    'message' => sprintf(__('Procesando productos... Página %d', 'alegra-connector'), $current_page),
                ], 600);
                if ($run_id > 0) {
                    \Alegra\Connector\Heartbeat::set($run_id, [
                        'step' => 'products',
                        'message' => sprintf(__('Procesando productos... Página %d', 'alegra-connector'), $current_page),
                    ]);
                    \Alegra\Connector\Runs::update_progress(
                        $run_id,
                        $result['imported'] + $result['updated'] + $result['errors'],
                        0
                    );
                }
            }

            $api_params = [
                'start' => $start,
                'limit' => $per_page,
                'mode' => 'advanced',
            ];
            if (!get_option('alegra_connector_sync_inactive_products', false)) {
                $api_params['status'] = 'active';
            }
            $alegra_items = $this->api->get_items($api_params);

            if (is_wp_error($alegra_items)) {
                delete_transient('alegra_sync_progress');
                if ($start === 0) return $alegra_items;
                break;
            }

            if (empty($alegra_items)) {
                $completed = true;
                break;
            }

            foreach ($alegra_items as $item) {
                // Per-item per-run stop: a single page of 30 items is short, but
                // this keeps the loop responsive even with a slow item.
                if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
                    $this->logger->info('Products import stopped by user mid-page');
                    break 2;
                }
                $r = $this->import_single_item_from_alegra($item, $run_id);
                if ($r === 'stopped') { $result['paused'] = true; break 2; }
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
            }

            $start += $per_page;
            $current_page++;
            $pages_done++;

            if (count($alegra_items) < $per_page) {
                $completed = true;
                break;
            }

            // Persist the resume cursor after every completed page.
            update_option($cursor_key, $start, false);
        }

        // Clear the cursor only when the catalog was fully walked; otherwise the
        // next run resumes where this one stopped (AC-19).
        if ($completed) {
            delete_option($cursor_key);
        } elseif ($start > 0) {
            update_option($cursor_key, $start, false);
        }

        // Final progress (T3.2.a: a pause is reported as paused, not done)
        set_transient('alegra_sync_progress', [
            'type' => 'products',
            'current_page' => $current_page,
            'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'done' => !$result['paused'],
            'paused' => $result['paused'],
            'cursor' => $start,
            'message' => $result['paused']
                ? sprintf(__('Pausado en el ítem %d; continúa en la próxima ejecución', 'alegra-connector'), $start)
                : sprintf(__('Completado: %d importados, %d actualizados', 'alegra-connector'), $result['imported'], $result['updated']),
        ], 60);

        $this->logger->info('Products import from Alegra completed', $result);
        $result['total_pages'] = $current_page;
        $result['images'] = self::image_stats();

        return $result;
    }

    /**
     * ¿La política vigente permite recrear un producto con este tombstone? (D4)
     * `alegra_deleted` NUNCA se resucita, en ninguna política.
     */
    private static function tombstone_policy_allows(string $reason): bool
    {
        if ($reason === 'alegra_deleted') {
            return false;
        }
        $policy = \Alegra\Connector\Run_Context::tombstone_policy();
        if ($policy === 'ignore_all') {
            return true; // recrea bulk_wc + manual_wc
        }
        if ($policy === 'ignore_bulk') {
            return $reason === 'bulk_wc';
        }
        return false; // respect
    }

    /**
     * Sync a single item by Alegra ID (used by webhooks)
     */
    private function import_single_item_from_alegra(array $item, int $run_id = 0): bool|string
    {
        // REQ-RB-2: the per-item importer must honour the kill switch and the
        // cancellation flag, exactly like import_from_alegra(). The return type
        // is bool|string, so the "stop" sentinel is 'skipped' (a WP_Error would
        // be a TypeError). Nothing is fetched or written.
        if (\Alegra\Connector\Kill_Switch::is_active() || get_transient('alegra_sync_cancelled')) {
            $this->logger->info('Item import skipped: kill switch active or sync cancelled', [
                'alegra_id' => (string) ($item['id'] ?? ''),
            ]);
            return 'skipped';
        }

        // REQ-MON-05: parada pedida desde el Monitor. A diferencia de kill
        // switch/cancel (que devuelven 'skipped'), 'stopped' hace que el loop
        // corte y cuente el ítem como no-procesado.
        if ($run_id > 0 && \Alegra\Connector\Runs::should_stop($run_id)) {
            $this->logger->info('Item import stopped by user', ['alegra_id' => (string) ($item['id'] ?? '')]);
            return 'stopped';
        }

        $alegra_id = (string) ($item['id'] ?? '');
        $sku = $item['reference'] ?? '';
        $name = $item['name'] ?? '';
        $type = $item['type'] ?? 'simple';

        // Skip variant children - they're imported with their parent
        if ($type === 'variant') {
            return 'skipped';
        }

        // TOMBSTONE GUARD: skip CREATION unless the active policy overrides it (D4).
        // Only blocks CREATION, not updates of existing products.
        $tombstone_reason = \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', $alegra_id);
        if ($tombstone_reason !== null && !self::tombstone_policy_allows($tombstone_reason)) {
            $existing_for_update = $this->get_product_by_alegra_id($alegra_id);
            if (!$existing_for_update) {
                $this->logger->info('Skipped product import: tombstone respected by policy', [
                    'alegra_id' => $alegra_id,
                    'name' => $name,
                    'reason' => $tombstone_reason,
                    'policy' => \Alegra\Connector\Run_Context::tombstone_policy(),
                ]);
                return 'skipped';
            }
            // Product exists in WC despite tombstone (resurrected) - clear the tombstone
            \Alegra\Connector\Tombstone_Manager::mark_resurrected('item', $alegra_id);
        }

        // Prevent re-entrant sync: skip WC→Alegra hooks while we're importing
        $was_syncing = false;
        if (\Alegra\Connector\Public\Public_::is_syncing()) {
            $was_syncing = true;
        } else {
            \Alegra\Connector\Public\Public_::set_syncing(true);
        }

        try {
            $existing_id = $this->get_product_by_alegra_id($alegra_id);

            if ($existing_id) {
                $product = wc_get_product($existing_id);
                if ($product) {
                    // AC-07: make sure the indexed map is populated even for a
                    // product found through the (legacy) postmeta fallback.
                    \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $existing_id);
                    $this->update_product_from_alegra($product, $item);
                    $this->assign_product_category((int) $existing_id, $item);
                    return 'updated';
                }
            }

            // Check by SKU
            if (!empty($sku)) {
                $existing_by_sku = $this->get_product_by_sku($sku);
                if ($existing_by_sku) {
                    update_post_meta($existing_by_sku, '_alegra_item_id', $alegra_id);
                    \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $existing_by_sku);
                    $product = wc_get_product($existing_by_sku);
                    if ($product) {
                        $this->update_product_from_alegra($product, $item);
                        $this->assign_product_category((int) $existing_by_sku, $item);
                        return 'updated';
                    }
                }
            }

        // Create new product
        $is_variable = ($type === 'variantParent' || $type === 'kit');

        $product_id = wp_insert_post([
            'post_title' => $name,
            'post_content' => $item['description'] ?? '',
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($product_id) || !$product_id) {
            $this->logger->error('Failed to create product from Alegra', [
                'alegra_id' => $alegra_id,
                'error' => is_wp_error($product_id) ? $product_id->get_error_message() : 'Unknown',
            ]);
            return false;
        }

        update_post_meta($product_id, '_alegra_item_id', $alegra_id);
        update_post_meta($product_id, '_sku', $sku);
        // AC-07: write the indexed mapping at create time. Without this every
        // lookup fell back to an unindexed wp_postmeta.meta_value scan.
        \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $product_id);

        $product = wc_get_product($product_id);
        if ($product) {
            if ($is_variable) {
                // Convert to variable product
                wp_set_object_terms($product_id, 'variable', 'product_type');
                $product = wc_get_product($product_id);
            }
            // New product: nothing to preserve, so the field exclusion is
            // ignored ($is_new=true).
            $this->update_product_from_alegra($product, $item, true);
        }

        // Import variant children. Alegra returns `itemVariants` for a
        // variantParent (and `subitems` only for kits); accept both.
        if ($is_variable) {
            $this->register_parent_variation_attributes((int) $product_id, $item);
            foreach ($this->get_variant_children($item) as $subitem_data) {
                $this->import_variation_from_alegra((int) $product_id, $subitem_data);
            }
        }

        // Assign the Alegra item's category to the parent product
        $this->assign_product_category((int) $product_id, $item);

        $this->logger->info('Product imported from Alegra', [
            'alegra_id' => $alegra_id,
            'product_id' => $product_id,
        ]);

        return true;
        } finally {
            // Release re-entrancy guard if we set it
            if (!$was_syncing) {
                \Alegra\Connector\Public\Public_::set_syncing(false);
            }
        }
    }

    /**
     * Normalize the Alegra variant children list for a parent item.
     *
     * Alegra returns `itemVariants` (full item objects) for a variantParent and
     * `subitems` (kit components, wrapped in `item`) for a kit.
     */
    private function get_variant_children(array $item): array
    {
        if (!empty($item['itemVariants']) && is_array($item['itemVariants'])) {
            return array_values(array_filter($item['itemVariants'], 'is_array'));
        }
        if (!empty($item['subitems']) && is_array($item['subitems'])) {
            return array_values(array_filter($item['subitems'], 'is_array'));
        }
        return [];
    }

    /**
     * Register the parent product's variation attributes so WooCommerce can
     * match the imported variations to them (AC-45). Uses custom (non-taxonomy)
     * product attributes, which is what an arbitrary Alegra attribute maps to.
     *
     * @param array<int, array{name?:string, options?:array}> $item Alegra parent.
     */
    private function register_parent_variation_attributes(int $product_id, array $item): void
    {
        $attributes = $item['variantAttributes'] ?? [];
        if (!is_array($attributes) || empty($attributes)) {
            return;
        }

        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($product_attributes)) {
            $product_attributes = [];
        }

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $name = (string) ($attribute['name'] ?? '');
            $key  = sanitize_title($name);
            if ($key === '') {
                continue;
            }

            $options = [];
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                $value = $this->variant_option_value($option);
                if ($value !== '') {
                    $options[] = $value;
                }
            }

            $product_attributes[$key] = [
                'name'         => $name,
                'value'        => implode(' | ', array_values(array_unique($options))),
                'position'     => count($product_attributes),
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 0,
            ];
        }

        if (!empty($product_attributes)) {
            update_post_meta($product_id, '_product_attributes', $product_attributes);
        }
    }

    /**
     * Set a variation's `attribute_<key>` meta from the Alegra variant
     * attributes so the variation is selectable in WooCommerce (AC-45).
     *
     * @param array<int, array{name?:string, options?:array}> $item Alegra variant.
     */
    private function assign_variation_attributes(int $variation_id, array $item): void
    {
        $attributes = $item['variantAttributes'] ?? [];
        if (!is_array($attributes) || empty($attributes)) {
            return;
        }

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $key = sanitize_title((string) ($attribute['name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $value = '';
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                $value = $this->variant_option_value($option);
                if ($value !== '') {
                    break;
                }
            }
            if ($value !== '') {
                update_post_meta($variation_id, 'attribute_' . $key, $value);
            }
        }
    }

    /**
     * Extract the string value of an Alegra variant attribute option. Alegra
     * returns objects ({id, value}); tolerate a bare string too.
     */
    private function variant_option_value(mixed $option): string
    {
        if (is_array($option)) {
            return (string) ($option['value'] ?? '');
        }
        return is_scalar($option) ? (string) $option : '';
    }

    /**
     * Persist the Alegra `reference` as the variation SKU (AC-45).
     */
    private function assign_variation_sku(int $variation_id, array $item): void
    {
        $sku = (string) ($item['reference'] ?? '');
        if ($sku !== '') {
            update_post_meta($variation_id, '_sku', $sku);
        }
    }

    /**
     * Import a variation from Alegra subitem
     */
    private function import_variation_from_alegra(int $parent_id, array $subitem_data): void
    {
        $alegra_id = (string) ($subitem_data['id'] ?? ($subitem_data['item']['id'] ?? ''));
        if ($alegra_id === '') {
            return;
        }

        $item = $this->api->get_item($alegra_id);
        if (is_wp_error($item) || !is_array($item)) {
            return;
        }

        $variation_id = $this->get_product_by_alegra_id($alegra_id);
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $this->update_product_from_alegra($variation, $item);
                $this->assign_variation_attributes((int) $variation_id, $item);
                // assign_variation_sku() writes _sku directly, so it must honour
                // the SKU exclusion too (update_product_from_alegra already skips
                // set_sku).
                if (!in_array('sku', $this->resolve_preserve_fields(), true)) {
                    $this->assign_variation_sku((int) $variation_id, $item);
                }
                if (!empty($item['itemCategory'])) {
                    $this->assign_product_category((int) $variation_id, $item);
                }
                return;
            }
        }

        // Create variation
        $variation_id = wp_insert_post([
            'post_title' => $item['name'] ?? 'Variation',
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'post_parent' => $parent_id,
        ]);

        if (is_wp_error($variation_id) || !$variation_id) {
            return;
        }

        update_post_meta($variation_id, '_alegra_item_id', $alegra_id);
        \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $variation_id);

        $variation = wc_get_product($variation_id);
        if ($variation) {
            // New variation: nothing to preserve ($is_new=true).
            $this->update_product_from_alegra($variation, $item, true);
        }

        // AC-45: attributes + SKU make the variation selectable in WooCommerce.
        $this->assign_variation_attributes((int) $variation_id, $item);
        $this->assign_variation_sku((int) $variation_id, $item);

        if (!empty($item['itemCategory'])) {
            $this->assign_product_category((int) $variation_id, $item);
        }
    }

    /**
     * Assign the Alegra item's COMMERCIAL category to the WC product.
     *
     * Reads `$item['itemCategory']` — NOT `$item['category']`, which is Alegra's
     * ACCOUNTING category (chart of accounts, e.g. "Ventas"). Reading `category`
     * created WooCommerce product categories named after accounting accounts.
     *
     * @see https://developer.alegra.com/reference/get_items.md
     */
    private function assign_product_category(int $product_id, array $item): void
    {
        $category = $item['itemCategory'] ?? null;
        if (empty($category)) {
            return;
        }

        // Alegra returns {id, name} — handle both array and scalar
        $alegra_cat_id = '';
        $alegra_cat_name = '';
        if (is_array($category)) {
            $alegra_cat_id = (string) ($category['id'] ?? '');
            $alegra_cat_name = (string) ($category['name'] ?? '');
        } elseif (is_string($category)) {
            $alegra_cat_name = $category;
        }

        if ($alegra_cat_id === '' && $alegra_cat_name === '') {
            return;
        }

        // AC-63: resolve the Alegra→WC category mapping ONCE per Products
        // instance (one indexed query) instead of a per-item get_terms() with an
        // unindexed wp_termmeta meta_query (N queries for N products).
        $this->load_category_map();

        // 1. Find by alegra_category_id (per-request map)
        $term_id = 0;
        if ($alegra_cat_id !== '' && isset($this->category_id_map[$alegra_cat_id])) {
            $term_id = $this->category_id_map[$alegra_cat_id];
        }

        // 2. Fallback: find by name (per-request map, then term_exists)
        if ($term_id === 0 && $alegra_cat_name !== '' && isset($this->category_name_map[$alegra_cat_name])) {
            $term_id = $this->category_name_map[$alegra_cat_name];
        }
        if ($term_id === 0 && $alegra_cat_name !== '') {
            $existing = term_exists($alegra_cat_name, 'product_cat');
            if ($existing) {
                $term_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            }
        }

        // 3. Create if not found
        if ($term_id === 0 && $alegra_cat_name !== '') {
            $parent = (int) get_option('alegra_connector_import_category_parent', 0);
            $created = wp_insert_term($alegra_cat_name, 'product_cat', ['parent' => $parent]);
            if (is_wp_error($created)) {
                $this->logger->warning('Failed to create category from Alegra item', [
                    'product_id' => $product_id,
                    'category'   => $alegra_cat_name,
                    'error'      => $created->get_error_message(),
                ]);
                return;
            }
            $term_id = (int) $created['term_id'];
        }

        if ($term_id === 0) {
            return;
        }

        // Remember the resolution for the rest of this run.
        if ($alegra_cat_id !== '') {
            $this->category_id_map[$alegra_cat_id] = $term_id;
        }
        if ($alegra_cat_name !== '') {
            $this->category_name_map[$alegra_cat_name] = $term_id;
        }

        // Backfill the alegra_category_id meta if we found it by name
        if ($alegra_cat_id !== '' && !get_term_meta($term_id, 'alegra_category_id', true)) {
            update_term_meta($term_id, 'alegra_category_id', $alegra_cat_id);
        }
        if ($alegra_cat_id !== '') {
            // AC-07/AC-60: keep the indexed category mapping in sync.
            \Alegra\Connector\Entity_Map::map('category', $alegra_cat_id, 'category', $term_id);
        }

        // Assign. Append=true so re-imports ADD the Alegra category instead of
        // replacing the merchant's manual WooCommerce categorization.
        $result = wp_set_object_terms($product_id, [$term_id], 'product_cat', true);
        if (is_wp_error($result)) {
            $this->logger->warning('Failed to assign category to product', [
                'product_id' => $product_id,
                'term_id'    => $term_id,
                'error'      => $result->get_error_message(),
            ]);
        }
    }

    /**
     * WooCommerce fields the merchant asked NOT to overwrite on an update.
     *
     * Configured once in Ajustes → Sincronización
     * (`alegra_connector_import_preserve_fields`); the same setting is honoured
     * by every import path (cron, webhooks, Importar page and the manual
     * Products import), so there is a single source of truth.
     *
     * @return array<int,string> Field keys: description|name|price|images|inventory|sku
     */
    private function resolve_preserve_fields(): array
    {
        $saved = get_option('alegra_connector_import_preserve_fields', []);
        return is_array($saved) ? array_values($saved) : [];
    }

    private function update_product_from_alegra(\WC_Product $product, array $item, bool $is_new = false): void
    {
        $product_id = (int) $product->get_id();
        $guard_key = 'alegra_updating_product_' . $product_id;

        // The exclusion applies only to EXISTING products: on create there is
        // nothing to preserve, so a new product is always fully populated.
        $preserve = $is_new ? [] : $this->resolve_preserve_fields();

        // Anti-loop guard: when we save a product, WC fires `woocommerce_update_product`
        // which can trigger a recursive sync. The guard prevents re-entry.
        set_transient($guard_key, 1, 30);

        try {
            // Price extraction
            $price = 0;
            $target_price_list = $this->price_list_id();
            if (isset($item['price']) && is_array($item['price'])) {
                foreach ($item['price'] as $pe) {
                    if (isset($pe['idPriceList']) && (int) $pe['idPriceList'] === $target_price_list) {
                        $price = (float) ($pe['price'] ?? 0);
                        break;
                    }
                }
                if ($price === 0 && !empty($item['price'][0]['price'])) {
                    $price = (float) $item['price'][0]['price'];
                }
            } elseif (isset($item['price']) && is_numeric($item['price'])) {
                $price = (float) $item['price'];
            }

            if (!in_array('price', $preserve, true)) {
                // AC-46: never overwrite a WooCommerce price with 0. A missing/zero
                // Alegra price list used to wipe the merchant's price.
                if ($price > 0) {
                    $product->set_regular_price($price);
                } else {
                    $this->logger->warning('Skipping price update: no usable Alegra price', [
                        'product_id' => $product_id,
                        'alegra_id'  => (string) ($item['id'] ?? ''),
                    ]);
                }
            }

            if (!empty($item['name']) && !in_array('name', $preserve, true)) {
                $product->set_name($item['name']);
            }

            // Map Alegra status to WC status and catalog visibility.
            // Alegra: active|inactive → WC: publish|draft and visible|hidden
            $alegra_status = $item['status'] ?? 'active';
            $product->set_status($alegra_status === 'inactive' ? 'draft' : 'publish');
            $product->set_catalog_visibility($alegra_status === 'inactive' ? 'hidden' : 'visible');

            if (!empty($item['description']) && !in_array('description', $preserve, true)) {
                $product->set_description($item['description']);
            }

            // D3 (REQ-INV-02): la compuerta (fuente + preserve) vive en
            // Inventory_Writer; acá sólo se arma el contexto. Un solo escritor.
            $this->apply_inventory_to_product($product, $item, $preserve);

            if (!empty($item['reference']) && !in_array('sku', $preserve, true)) {
                $product->set_sku($item['reference']);
            }

            $product->save();

            // Import product images from Alegra
            if (get_option('alegra_connector_sync_images', true) && !in_array('images', $preserve, true)) {
                $mode = get_option('alegra_connector_sync_images_mode', 'favorite');
                $this->import_product_images($product_id, $item['images'] ?? [], $mode);
            }
        } finally {
            // Release the guard - keep it a bit longer to catch any delayed hooks
            delete_transient($guard_key);
        }
    }

    /**
     * W1 — el import delega en el escritor único (D3).
     *
     * WooCommerce ignores `_stock` unless `_manage_stock` is enabled. The
     * `_stock_status` is DERIVED by WC on save(): `WC_Product::save()` calls
     * `validate_props()` (WC >= 3.0), which computes instock/onbackorder/
     * outofstock from quantity + `_backorders` + the no-stock threshold. The
     * old claim that WC does not derive it was FALSE. The write goes through
     * `wc_update_product_stock()` (the recommended API, which fires the stock
     * hooks) and never forces the status; `_backorders` is respected untouched.
     *
     * The presence of the `inventory` object is what marks an Alegra item as
     * inventariable ("Si este objeto está presente indica que el artículo es
     * inventariable, si no lo está se asume como servicio"). All the gates
     * (source/preserve/service/parent/null/negative/manage_stock/dry_run) live
     * in `Inventory_Writer`, the single writer (D3/REQ-INV-02/05).
     *
     * @param array<int,string> $preserve `resolve_preserve_fields()` del update.
     *
     * @see https://developer.alegra.com/reference/get_items.md
     */
    private function apply_inventory_to_product(\WC_Product $product, array $item, array $preserve = []): void
    {
        (new Inventory_Writer($this->logger))->apply($product, $item, [
            'source'       => (string) get_option('alegra_connector_inventory_source', 'alegra'),
            'preserve'     => in_array('inventory', $preserve, true),
            // El import SIEMPRE habilitó manage_stock (comportamiento HEAD, W1
            // `:2072`): se conserva con 'enable' para no cambiar el import.
            'manage_stock' => 'enable',
            'dry_run'      => (bool) get_option('alegra_connector_dry_run', false),
            'warehouse_id' => $this->resolve_warehouse_id(),
        ]);
    }

    /**
     * Import images from Alegra to WooCommerce product.
     *
     * Mode 'favorite': Only the image with favorite:true is downloaded as featured image.
     * Mode 'all': All images are downloaded. The favorite (or first) becomes featured,
     *             the rest are added to the product gallery.
     * Mode 'except_favorite': All images except the favorite. All go to gallery,
     *                         no featured image is set. Falls back to 'all' if no favorite.
     *
     * Alegra API image object: {id, name, url, favorite}
     */
    private function import_product_images(int $product_id, array $images, string $mode): void
    {
        if (empty($images)) return;

        // Find the favorite image URL
        $favorite_url = null;
        foreach ($images as $img) {
            if (!empty($img['favorite']) && !empty($img['url'])) {
                $favorite_url = $img['url'];
                break;
            }
        }

        // --- Mode: only the favorite image ---
        if ($mode === 'favorite') {
            $url = $favorite_url;
            if (!$url) {
                // No favorite flag → fallback to first image with URL
                foreach ($images as $img) {
                    if (!empty($img['url'])) { $url = $img['url']; break; }
                }
            }
            if ($url) {
                // T3.2.b: honour the page budget before downloading.
                if (self::deadline_exhausted()) {
                    self::$image_stats['deferred']++;
                    return;
                }
                $this->import_product_image($product_id, $url);
            }
            return;
        }

        // --- Mode: all images or except_favorite ---
        $gallery_ids = [];
        $seen_urls = [];
        $featured_set = false;
        $skipped_count = 0;

        foreach ($images as $img) {
            if (empty($img['url'])) continue;

            // Skip favorite in except_favorite mode
            if ($mode === 'except_favorite' && $img['url'] === $favorite_url) {
                $skipped_count++;
                continue;
            }

            // Skip already-processed URLs in this session. AC-79: use a hash
            // set (O(1)) instead of in_array() (O(n)) to avoid O(n²) dedup.
            if (isset($seen_urls[$img['url']])) {
                continue;
            }
            $seen_urls[$img['url']] = true;

            // T3.2.b: stop the gallery loop once the page budget is spent.
            if (self::deadline_exhausted()) {
                self::$image_stats['deferred']++;
                break;
            }

            $attachment_id = $this->download_and_attach_image($product_id, $img['url']);
            if (!$attachment_id) continue;

            // Set featured image: first non-favorite in except_favorite, first image in all
            if (!$featured_set && $mode === 'except_favorite') {
                set_post_thumbnail($product_id, $attachment_id);
                $featured_set = true;
            } elseif (!$featured_set && $mode === 'all') {
                set_post_thumbnail($product_id, $attachment_id);
                $featured_set = true;
            } else {
                $gallery_ids[] = $attachment_id;
            }
        }

        // Fallback for except_favorite: if all images were skipped, download everything
        if ($mode === 'except_favorite' && empty($gallery_ids) && !$featured_set && $skipped_count > 0) {
            $seen_urls = [];
            foreach ($images as $img) {
                if (empty($img['url'])) continue;
                if (isset($seen_urls[$img['url']])) continue;
                $seen_urls[$img['url']] = true;
                // T3.2.b: stop the fallback loop once the page budget is spent.
                if (self::deadline_exhausted()) {
                    self::$image_stats['deferred']++;
                    break;
                }
                $attachment_id = $this->download_and_attach_image($product_id, $img['url']);
                if (!$attachment_id) continue;
                if (!$featured_set) {
                    set_post_thumbnail($product_id, $attachment_id);
                    $featured_set = true;
                } else {
                    $gallery_ids[] = $attachment_id;
                }
            }
        }

        // Set gallery - skip IDs already in gallery
        if (!empty($gallery_ids)) {
            $existing_gallery = get_post_meta($product_id, '_product_image_gallery', true);
            $existing_ids = !empty($existing_gallery) ? explode(',', $existing_gallery) : [];
            $gallery_ids = array_unique($gallery_ids);
            // Only add IDs not already in gallery
            $new_ids = array_diff($gallery_ids, $existing_ids);
            if (!empty($new_ids)) {
                $all_ids = array_merge($existing_ids, $new_ids);
                update_post_meta($product_id, '_product_image_gallery', implode(',', $all_ids));
            }
        }
    }

    /**
     * Hosts allowed as a source for product images when the restriction mode is
     * ON (AC-68).
     *
     * Alegra serves item images from its own CDN — the documented response
     * example is `https://cdn3.alegra.com/...` — so the base domain plus every
     * subdomain is allowed. Extendable via the
     * `alegra_connector_allowed_image_hosts` filter.
     *
     * Since 2.5.1 this is the RESTRICT list: it only applies when
     * `alegra_connector_restrict_image_hosts` is enabled. By default every
     * public host is accepted so third-party CDNs (S3/CloudFront) download.
     *
     * @return string[]
     */
    public static function allowed_image_hosts(): array
    {
        $base = ['alegra.com'];
        $extra = (array) get_option('alegra_connector_allowed_image_hosts_extra', []);
        $hosts = array_values(array_unique(array_merge($base, $extra)));
        $filtered = apply_filters('alegra_connector_allowed_image_hosts', $hosts);
        return is_array($filtered) ? $filtered : $hosts;
    }

    /**
     * Whether image downloads are restricted to the allowlist.
     *
     * Default false: 2.5.1 makes the default permissive so images always
     * download. When true, `allowed_image_hosts()` is enforced. The switch
     * exists so the stricter behavior can be re-enabled without a code change.
     */
    public static function image_host_restriction_enabled(): bool
    {
        return (bool) get_option('alegra_connector_restrict_image_hosts', false);
    }

    /**
     * Always-on SSRF guard: true for loopback / private / reserved targets.
     *
     * Independent of the restriction mode — a legitimate public CDN is never
     * caught here, but an attacker-controlled Alegra image URL cannot make the
     * server fetch itself or the internal network.
     */
    private static function is_blocked_image_host(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return true;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Private, reserved, loopback and link-local literals fail this
            // filter (10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ...).
            if (filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extensión de archivo derivada del mime real. Fallback 'jpg' (comportamiento previo).
     */
    private static function extension_from_mime(string $mime): string
    {
        $map = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/avif'    => 'avif',
            'image/bmp'     => 'bmp',
            'image/tiff'    => 'tiff',
            'image/svg+xml' => 'svg',
        ];
        return $map[strtolower($mime)] ?? 'jpg';
    }

    /**
     * Whether a URL may be downloaded as a product image.
     *
     * 2.5.1 makes the default FLEXIBLE so images always download: any public
     * host over http/https is accepted. An always-on SSRF guard blocks
     * loopback / private / reserved targets regardless of the mode. When
     * `alegra_connector_restrict_image_hosts` is ON, the host must match
     * `allowed_image_hosts()` (exact or subdomain).
     *
     * Security note: the permissive default is intentional and deferred — the
     * SSRF guard is the minimum bar; the full allowlist is one option away.
     */
    public static function is_allowed_image_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (self::is_blocked_image_host($host)) {
            return false;
        }

        if (!self::image_host_restriction_enabled()) {
            return true;
        }

        foreach (self::allowed_image_hosts() as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download an image from a URL and attach it to a product.
     * Checks for existing attachment by URL (normalized + hash) to avoid duplicates.
     * Uses a process lock to prevent race conditions.
     * Returns the attachment ID on success, 0 on failure.
     */
    private function download_and_attach_image(int $product_id, string $image_url): int
    {
        if (empty($image_url)) return 0;

        if (!self::is_allowed_image_url($image_url)) {
            self::$image_stats['blocked']++;
            $this->logger->warning('Blocked image download from a non-allowlisted host', [
                'product_id' => $product_id,
                'host' => (string) (wp_parse_url($image_url)['host'] ?? ''),
            ]);
            return 0;
        }

        // T3.2.b/C8: the page budget covers image downloads too. A deferred
        // image is retried by a future run (dedup by URL hash), never counted
        // as a hard failure.
        if (self::deadline_exhausted()) {
            self::$image_stats['deferred']++;
            $this->logger->info('Image download deferred to next run (page budget exhausted)', [
                'product_id' => $product_id,
                'url' => $image_url,
            ]);
            return 0;
        }

        // Normalize URL to strip query params that may differ between syncs
        $normalized_url = $this->normalize_image_url($image_url);
        if (empty($normalized_url)) return 0;

        // Compute hash for fast indexed lookup (avoid full-string scan in MySQL)
        $url_hash = md5($normalized_url);

        // Process lock: prevent two concurrent calls from racing on the same URL
        $lock_key = 'alegra_img_dedup_' . $url_hash;
        $token = \Alegra\Connector\Sync\Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            // Another process is handling this URL right now; wait briefly and re-check
            usleep(500000); // 0.5s
            $existing_id = $this->get_attachment_by_url($product_id, $normalized_url);
            if ($existing_id > 0) return $existing_id;
            // Fall through - we'll try ourselves (best effort, without the lock)
        }

        try {
            // Check if this URL was already imported for this product (by hash first, then URL)
            $existing_id = $this->get_attachment_by_hash($url_hash, $product_id);
            if ($existing_id > 0) {
                // Backfill: store URL meta if missing
                if (!get_post_meta($existing_id, '_alegra_image_url', true)) {
                    update_post_meta($existing_id, '_alegra_image_url', $normalized_url);
                }
                return $existing_id;
            }

            // Fallback: legacy lookup by URL string (for old attachments without hash meta)
            $existing_id = $this->get_attachment_by_url($product_id, $normalized_url);
            if ($existing_id > 0) {
                update_post_meta($existing_id, '_alegra_image_url_hash', $url_hash);
                // AC-23: index it so the next lookup is O(1).
                \Alegra\Connector\Entity_Map::map('image', $url_hash, 'attachment', (int) $existing_id);
                return $existing_id;
            }

            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp = download_url($image_url, 15);
            if (is_wp_error($tmp)) {
                self::$image_stats['download']++;
                $this->logger->warning('Image download failed', [
                    'product_id' => $product_id,
                    'url' => $image_url,
                    'error' => $tmp->get_error_message(),
                ]);
                return 0;
            }

            $fallback = 'alegra-' . $product_id . '-' . substr($url_hash, 0, 8);
            $check = wp_check_filetype_and_ext($tmp, $fallback . '.jpg');
            $ext = !empty($check['ext'])
                ? (string) $check['ext']
                : self::extension_from_mime((string) (wp_get_image_mime($tmp) ?: mime_content_type($tmp)));
            $file_array = ['name' => $fallback . '.' . $ext, 'tmp_name' => $tmp];

            $attachment_id = media_handle_sideload($file_array, $product_id);
            if (is_wp_error($attachment_id)) {
                self::$image_stats['sideload']++;
                @unlink($tmp);
                $this->logger->warning('Image sideload failed', [
                    'product_id' => $product_id,
                    'url' => $image_url,
                    'error' => $attachment_id->get_error_message(),
                ]);
                return 0;
            }

            // Store BOTH normalized URL AND hash for future dedup (faster lookup)
            update_post_meta((int) $attachment_id, '_alegra_image_url', $normalized_url);
            update_post_meta((int) $attachment_id, '_alegra_image_url_hash', $url_hash);
            // AC-23: index the new attachment for O(1) dedup on later imports.
            \Alegra\Connector\Entity_Map::map('image', $url_hash, 'attachment', (int) $attachment_id);

            self::$image_stats['ok']++;
            $this->logger->debug('Image imported', [
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'url_hash' => $url_hash,
            ]);

            return (int) $attachment_id;
        } finally {
            if ($token !== false) {
                \Alegra\Connector\Sync\Controller::release_lock($lock_key, $token);
            }
        }
    }

    /**
     * Find an existing attachment by URL hash (fast indexed lookup).
     */
    private function get_attachment_by_hash(string $url_hash, int $product_id): int
    {
        // AC-23: O(1) indexed lookup via the entity map. The old postmeta scan
        // on the unindexed meta_value was a full table scan per image.
        $mapped = \Alegra\Connector\Entity_Map::find_attachment_id($url_hash);
        if ($mapped) {
            return $mapped;
        }

        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = '_alegra_image_url_hash' AND pm.meta_value = %s
             ORDER BY p.post_parent = %d DESC
             LIMIT 1",
            $url_hash,
            $product_id
        ));

        if ($id) {
            // Backfill the indexed map so subsequent lookups are O(1).
            \Alegra\Connector\Entity_Map::map('image', $url_hash, 'attachment', (int) $id);
            return (int) $id;
        }

        return 0;
    }

    /**
     * Normalize an image URL by removing query parameters and fragments.
     * This ensures that the same image with different query strings (tokens,
     * cache busters, etc.) is correctly identified as duplicate.
     */
    private function normalize_image_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $fragment;
    }

    /**
     * Find an existing attachment by the Alegra image URL (global search).
     * Updates the attachment parent to this product if found.
     */
    private function get_attachment_by_url(int $product_id, string $image_url): int
    {
        // Normalize the URL to match stored values that were also normalized
        $image_url = $this->normalize_image_url($image_url);
        if ($image_url === '') {
            return 0;
        }

        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             WHERE pm.meta_key = '_alegra_image_url' AND pm.meta_value = %s
             ORDER BY p.post_parent = %d DESC
             LIMIT 1",
            $image_url,
            $product_id
        ));

        $attachment_id = $id ? (int) $id : 0;
        if ($attachment_id > 0) {
            // AC-23: only reparent when the parent actually differs. The old
            // code called wp_update_post() on EVERY hit (extra write + hook
            // storm) even when nothing changed.
            $current = get_post($attachment_id);
            if (!$current || (int) $current->post_parent !== $product_id) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_parent' => $product_id,
                ]);
            }
        }

        return $attachment_id;
    }

    public function import_single_item_public(array $item, int $run_id = 0): bool|string
    {
        return $this->import_single_item_from_alegra($item, $run_id);
    }

    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        if (!self::is_allowed_image_url($image_url)) {
            self::$image_stats['blocked']++;
            $this->logger->warning('Blocked image download from a non-allowlisted host', [
                'product_id' => $product_id,
                'host' => (string) (wp_parse_url($image_url)['host'] ?? ''),
            ]);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 15);
        if (is_wp_error($tmp)) {
            self::$image_stats['download']++;
            $this->logger->warning('Image download failed for product ' . $product_id, ['error' => $tmp->get_error_message()]);
            return;
        }

        $fallback = 'alegra-' . $product_id;
        $check = wp_check_filetype_and_ext($tmp, $fallback . '.jpg');
        $ext = !empty($check['ext'])
            ? (string) $check['ext']
            : self::extension_from_mime((string) (wp_get_image_mime($tmp) ?: mime_content_type($tmp)));
        $file_array = ['name' => $fallback . '.' . $ext, 'tmp_name' => $tmp];

        $attachment_id = media_handle_sideload($file_array, $product_id);
        if (!is_wp_error($attachment_id)) {
            self::$image_stats['ok']++;
            set_post_thumbnail($product_id, $attachment_id);
        } else {
            self::$image_stats['sideload']++;
            $this->logger->warning('Image sideload failed for product ' . $product_id, ['error' => $attachment_id->get_error_message()]);
        }
    }

    public function sync_single_item_by_alegra_id(string $alegra_id): bool|string
    {
        $item = $this->api->get_item($alegra_id);
        if (is_wp_error($item) || !is_array($item)) {
            return false;
        }
        return $this->import_single_item_from_alegra($item);
    }

    public function delete_from_alegra(int $product_id): array|\WP_Error
    {
        $alegra_id = (string) get_post_meta($product_id, '_alegra_item_id', true);
        if ($alegra_id === '') {
            return new \WP_Error('not_linked', 'Product not linked to Alegra');
        }

        $result = $this->api->delete_item($alegra_id);
        // Write Gate: the item was NOT deleted in Alegra, so the local link and
        // mapping must survive.
        if (!is_wp_error($result) && !API\Client::write_was_blocked($result)) {
            delete_post_meta($product_id, '_alegra_item_id');
            // AC-60: drop the indexed mapping so it cannot point at a ghost id.
            \Alegra\Connector\Entity_Map::remove('item', $alegra_id, 'product');
        }

        return $result;
    }

    private function get_product_by_alegra_id(string $alegra_id): ?int
    {
        $id = \Alegra\Connector\Entity_Map::find_wc_id('item', $alegra_id, 'product');
        return $id ?: null;
    }

    private function get_product_by_sku(string $sku): ?int
    {
        if (empty($sku)) {
            return null;
        }
        $id = wc_get_product_id_by_sku($sku);
        return $id ? (int) $id : null;
    }

    /**
     * Resolve the Alegra category id for a WC product, creating it in Alegra if needed.
     * Caches resolved ids in a per-request static array to avoid repeated API calls.
     */
    private function resolve_alegra_category_id(\WC_Product $product): string
    {
        static $cache = [];
        $cat_ids = $product->get_category_ids();
        if (empty($cat_ids)) {
            // AC-16: honor the "Categoría por defecto" mapping when the product
            // has no WooCommerce category (the mapping page promised this).
            return $this->field_mapping('default_category', '');
        }

        // Strategy: first | deepest | specific
        $strategy = (string) get_option('alegra_connector_push_category_strategy', 'deepest');
        $cat_id = $this->pick_category_id($cat_ids, $strategy);
        if ($cat_id <= 0) {
            return '';
        }

        if (isset($cache[$cat_id])) {
            return $cache[$cat_id];
        }

        $alegra_id = (string) get_term_meta($cat_id, 'alegra_category_id', true);
        if ($alegra_id !== '') {
            $cache[$cat_id] = $alegra_id;
            return $alegra_id;
        }

        $term = get_term($cat_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return '';
        }

        $result = $this->api->create_item_category([
            'name'        => $term->name,
            'description' => $term->description ?: '',
        ]);
        if (is_wp_error($result) || empty($result['id'])) {
            $this->logger->warning('Failed to create Alegra category', [
                'term_id' => $cat_id,
                'error'   => is_wp_error($result) ? $result->get_error_message() : 'no id',
            ]);
            return '';
        }
        $alegra_id = (string) $result['id'];
        update_term_meta($cat_id, 'alegra_category_id', $alegra_id);
        $cache[$cat_id] = $alegra_id;
        return $alegra_id;
    }

    /**
     * Pick which WC category to push based on the strategy.
     */
    private function pick_category_id(array $cat_ids, string $strategy): int
    {
        if (empty($cat_ids)) {
            return 0;
        }
        if ($strategy === 'first') {
            return (int) $cat_ids[0];
        }
        if ($strategy === 'deepest') {
            $best = (int) $cat_ids[0];
            $best_depth = 0;
            foreach ($cat_ids as $cid) {
                $depth = count(get_ancestors((int) $cid, 'product_cat'));
                if ($depth >= $best_depth) {
                    $best_depth = $depth;
                    $best = (int) $cid;
                }
            }
            return $best;
        }
        // 'specific': use the configured category
        $specific = (int) get_option('alegra_connector_push_category_id', 0);
        if ($specific > 0 && in_array($specific, array_map('intval', $cat_ids), true)) {
            return $specific;
        }
        return (int) $cat_ids[0];
    }
}