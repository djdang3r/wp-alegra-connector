<?php
/**
 * Products Sync Handler
 *
 * Full bidirectional sync with variant support.
 * Each WooCommerce variation becomes a variant item in Alegra,
 * linked as subitems to the parent variantParent.
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
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
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
            $this->sync_product_image($product, (int) ($result['id'] ?? 0));
        }

        return $result;
    }

    /**
     * Upload product featured image to Alegra item attachment
     */
    private function sync_product_image(\WC_Product $product, int $alegra_id): void
    {
        if ($alegra_id <= 0) return;

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
        $alegra_id = (int) get_post_meta($product->get_id(), '_alegra_item_id', true);
        $data = $this->prepare_simple_product_data($product);

        if ($alegra_id > 0) {
            $result = $this->api->update_item($alegra_id, $data);
            $this->logger->info('Product updated in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $alegra_id,
            ]);
        } else {
            // Search by SKU in Alegra before creating (prevent duplicates)
            $sku = $product->get_sku();
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $alegra_id = (int) $items[0]['id'];
                    update_post_meta($product->get_id(), '_alegra_item_id', $alegra_id);
                    $result = $this->api->update_item($alegra_id, $data);
                    $this->logger->info('Product linked to existing Alegra item by SKU', [
                        'product_id' => $product->get_id(),
                        'alegra_id' => $alegra_id,
                    ]);
                    return $result;
                }
            }

            $result = $this->api->create_item($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($product->get_id(), '_alegra_item_id', (int) $result['id']);
                $this->logger->info('Product created in Alegra', [
                    'product_id' => $product->get_id(),
                    'alegra_id' => $result['id'],
                ]);
            }
        }

        return $result;
    }

    /**
     * Sync variable product (parent with variants as subitems)
     */
    private function sync_variable_product(\WC_Product $product): array|\WP_Error
    {
        $alegra_id = (int) get_post_meta($product->get_id(), '_alegra_item_id', true);

        // First, sync each variation individually to get their Alegra IDs
        $variation_ids = $product->get_children();
        $subitems = [];

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            // Sync each variation
            $var_result = $this->sync_variation($variation);
            if (!is_wp_error($var_result) && isset($var_result['id'])) {
                $subitems[] = [
                    'id' => (int) $var_result['id'],
                    'reference' => $variation->get_sku(),
                    'price' => (float) $variation->get_regular_price(),
                    'quantity' => (int) ($variation->get_stock_quantity() ?? 0),
                ];
            }
        }

        $data = $this->prepare_variable_product_data($product, $subitems);

        if ($alegra_id > 0) {
            $result = $this->api->update_item($alegra_id, $data);
            $this->logger->info('Variable product updated in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $alegra_id,
                'variations' => count($subitems),
            ]);
        } else {
            $data['type'] = 'kit';
            $result = $this->api->create_item($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($product->get_id(), '_alegra_item_id', (int) $result['id']);
                $this->logger->info('Variable product created in Alegra', [
                    'product_id' => $product->get_id(),
                    'alegra_id' => $result['id'],
                    'variations' => count($subitems),
                ]);
            }
        }

        return $result;
    }

    /**
     * Sync a single variation to Alegra as an individual item
     */
    private function sync_variation(\WC_Product $variation): array|\WP_Error
    {
        $alegra_id = (int) get_post_meta($variation->get_id(), '_alegra_item_id', true);
        $data = $this->prepare_variation_data($variation);

        if ($alegra_id > 0) {
            $result = $this->api->update_item($alegra_id, $data);
        } else {
            // Search by SKU in Alegra before creating (prevent duplicates)
            $sku = $variation->get_sku();
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $alegra_id = (int) $items[0]['id'];
                    update_post_meta($variation->get_id(), '_alegra_item_id', $alegra_id);
                    $result = $this->api->update_item($alegra_id, $data);
                    $this->logger->info('Variation linked to existing Alegra item by SKU', [
                        'variation_id' => $variation->get_id(),
                        'alegra_id' => $alegra_id,
                    ]);
                    return $result;
                }
            }

            $data['type'] = 'variant';
            $result = $this->api->create_item($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($variation->get_id(), '_alegra_item_id', (int) $result['id']);
                $this->logger->info('Variation synced to Alegra', [
                    'variation_id' => $variation->get_id(),
                    'alegra_id' => $result['id'],
                ]);
            }
        }

        return $result;
    }

    /**
     * Prepare data for simple product
     */
    private function prepare_simple_product_data(\WC_Product $product): array
    {
        $data = [
            'name' => $product->get_name(),
            'reference' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_description()),
            'type' => 'simple',
            'price' => [
                [
                    'idPriceList' => 1,
                    'price' => (float) $product->get_regular_price(),
                ],
            ],
            'inventory' => [
                'unit' => 'unit',
                'initialQuantity' => (int) ($product->get_stock_quantity() ?? 0),
            ],
        ];

        $sale_price = $product->get_sale_price();
        if (!empty($sale_price) && (float) $sale_price < $product->get_regular_price()) {
            $data['price'][0]['price'] = (float) $sale_price;
        }

        // Tax
        $tax_id = $this->map_product_tax($product);
        if ($tax_id > 0) {
            $data['tax'] = [['id' => $tax_id]];
        }

        // Category
        $cat_name = $this->get_main_category_name($product->get_category_ids());
        if ($cat_name) {
            $data['category'] = ['name' => $cat_name];
        }

        return $data;
    }

    /**
     * Prepare data for variable product parent
     */
    private function prepare_variable_product_data(\WC_Product $product, array $subitems): array
    {
        return [
            'name' => $product->get_name(),
            'reference' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_description()),
            'type' => 'variantParent',
            'price' => [
                [
                    'idPriceList' => 1,
                    'price' => (float) $product->get_price(),
                ],
            ],
            'subitems' => $subitems,
        ];
    }

    /**
     * Prepare data for a single variation
     */
    private function prepare_variation_data(\WC_Product $variation): array
    {
        $parent = wc_get_product($variation->get_parent_id());
        $attributes = $variation->get_attributes();
        $name_parts = [$parent ? $parent->get_name() : 'Variation'];
        foreach ($attributes as $attr => $value) {
            $name_parts[] = $value;
        }
        $variation_name = implode(' - ', $name_parts);

        return [
            'name' => $variation_name,
            'reference' => $variation->get_sku(),
            'description' => wp_strip_all_tags($variation->get_description()),
            'price' => [
                [
                    'idPriceList' => 1,
                    'price' => (float) $variation->get_regular_price(),
                ],
            ],
            'inventory' => [
                'unit' => 'unit',
                'initialQuantity' => (int) ($variation->get_stock_quantity() ?? 0),
            ],
        ];
    }

    /**
     * Map product tax class to Alegra tax ID
     */
    private function map_product_tax(\WC_Product $product): int
    {
        $tax_class = $product->get_tax_class();
        if (empty($tax_class) || $tax_class === 'zero-rate') {
            return 0;
        }

        $tax_mapping = get_option('alegra_connector_tax_mapping', []);
        return isset($tax_mapping[$tax_class]) ? (int) $tax_mapping[$tax_class] : 0;
    }

    /**
     * Sync inventory from Alegra to WooCommerce (pull)
     */
    public function sync_inventory_from_alegra(): array
    {
        $result = ['updated' => 0, 'errors' => 0];

        $items = $this->api->get_items(['limit' => 30]);
        if (is_wp_error($items)) {
            return $result;
        }

        foreach ($items as $item) {
            if (!isset($item['inventory']['availableQuantity'])) {
                continue;
            }

            $product_id = $this->get_product_by_alegra_id((int) $item['id']);
            if (!$product_id) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $new_qty = (int) $item['inventory']['availableQuantity'];
            if ($product->get_manage_stock()) {
                try {
                    $old_qty = $product->get_stock_quantity();
                    $product->set_stock_quantity($new_qty);
                    $product->save();
                    $result['updated']++;

                    if ($old_qty !== $new_qty) {
                        $this->logger->info('Inventory updated from Alegra', [
                            'product_id' => $product_id,
                            'alegra_id' => $item['id'],
                            'old_qty' => $old_qty,
                            'new_qty' => $new_qty,
                        ]);
                    }
                } catch (\Exception $e) {
                    $result['errors']++;
                    $this->logger->error('Failed to update inventory', [
                        'product_id' => $product_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('Inventory sync from Alegra completed', $result);

        return $result;
    }

    public function sync_all(): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0];
        $page = 1;
        $per_page = 30;

        while (true) {
            $products = wc_get_products([
                'limit' => $per_page,
                'page' => $page,
                'status' => 'publish',
                'type' => ['simple', 'variable'],
            ]);

            if (empty($products)) break;

            foreach ($products as $product) {
                $sync_result = $this->sync_to_alegra($product);
                if (is_wp_error($sync_result)) {
                    $result['errors']++;
                    $this->logger->error('Failed to sync product', [
                        'product_id' => $product->get_id(),
                        'error' => $sync_result->get_error_message(),
                    ]);
                } else {
                    $result['synced']++;
                }
            }

            if (count($products) < $per_page) break;
            $page++;
        }

        $this->logger->info('Products sync completed', $result);

        return $result;
    }

    public function import_from_alegra(int $page = 1, int $per_page = 30): array|\WP_Error
    {
        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Products import skipped: kill switch active');
            return new \WP_Error('kill_switch_active', 'Plugin is disconnected or deactivated');
        }

        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0, 'total_pages' => 0, 'current_page' => 0];
        $current_page = $page;
        $max_pages = 200; // ~6000 items max

        // Prevent PHP timeout on large syncs
        set_time_limit(300);

        for ($p = 1; $p <= $max_pages; $p++) {
            // Check for cancellation
            if (get_transient('alegra_sync_cancelled')) {
                delete_transient('alegra_sync_cancelled');
                $this->logger->info('Products import cancelled by user');
                break;
            }

            // Update progress transient
            set_transient('alegra_sync_progress', [
                'type' => 'products',
                'current_page' => $p,
                'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'message' => sprintf(__('Procesando productos... Página %d', 'alegra-connector'), $p),
            ], 120);

            $api_params = [
                'start' => ($current_page - 1) * $per_page,
                'limit' => $per_page,
                'mode' => 'advanced',
            ];
            if (!get_option('alegra_connector_sync_inactive_products', false)) {
                $api_params['status'] = 'active';
            }
            $alegra_items = $this->api->get_items($api_params);

            if (is_wp_error($alegra_items)) {
                delete_transient('alegra_sync_progress');
                if ($current_page === 1) return $alegra_items;
                break;
            }

            if (empty($alegra_items)) break;

            foreach ($alegra_items as $item) {
                $r = $this->import_single_item_from_alegra($item);
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
            }

            if (count($alegra_items) < $per_page) break;
            $current_page++;
        }

        // Final progress
        set_transient('alegra_sync_progress', [
            'type' => 'products',
            'current_page' => $current_page,
            'items_processed' => $result['imported'] + $result['updated'] + $result['errors'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'done' => true,
            'message' => sprintf(__('Completado: %d importados, %d actualizados', 'alegra-connector'), $result['imported'], $result['updated']),
        ], 60);

        $this->logger->info('Products import from Alegra completed', $result);
        $result['total_pages'] = $current_page;

        return $result;
    }

    /**
     * Sync a single item by Alegra ID (used by webhooks)
     */
    private function import_single_item_from_alegra(array $item): bool|string
    {
        $alegra_id = (int) ($item['id'] ?? 0);
        $sku = $item['reference'] ?? '';
        $name = $item['name'] ?? '';
        $type = $item['type'] ?? 'simple';

        // Skip variant children - they're imported with their parent
        if ($type === 'variant') {
            return 'skipped';
        }

        // TOMBSTONE GUARD: skip if user previously deleted this product in WC.
        // Only blocks CREATION, not updates of existing products.
        if (\Alegra\Connector\Tombstone_Manager::exists('item', $alegra_id)) {
            $existing_for_update = $this->get_product_by_alegra_id($alegra_id);
            if (!$existing_for_update) {
                $this->logger->info('Skipped product import: tombstone exists (user deleted in WC)', [
                    'alegra_id' => $alegra_id,
                    'name' => $name,
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
                    $this->update_product_from_alegra($product, $item);
                    return 'updated';
                }
            }

            // Check by SKU
            if (!empty($sku)) {
                $existing_by_sku = $this->get_product_by_sku($sku);
                if ($existing_by_sku) {
                    update_post_meta($existing_by_sku, '_alegra_item_id', $alegra_id);
                    $product = wc_get_product($existing_by_sku);
                    if ($product) {
                        $this->update_product_from_alegra($product, $item);
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

        $product = wc_get_product($product_id);
        if ($product) {
            if ($is_variable) {
                // Convert to variable product
                wp_set_object_terms($product_id, 'variable', 'product_type');
                $product = wc_get_product($product_id);
            }
            $this->update_product_from_alegra($product, $item);
        }

        // Import variant children
        if ($is_variable && !empty($item['subitems'])) {
            foreach ($item['subitems'] as $subitem_data) {
                $this->import_variation_from_alegra($product_id, $subitem_data);
            }
        }

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
     * Import a variation from Alegra subitem
     */
    private function import_variation_from_alegra(int $parent_id, array $subitem_data): void
    {
        $alegra_id = (int) ($subitem_data['id'] ?? 0);
        if ($alegra_id <= 0) {
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

        $variation = wc_get_product($variation_id);
        if ($variation) {
            $this->update_product_from_alegra($variation, $item);
        }
    }

    private function update_product_from_alegra(\WC_Product $product, array $item): void
    {
        $product_id = (int) $product->get_id();
        $guard_key = 'alegra_updating_product_' . $product_id;

        // Anti-loop guard: when we save a product, WC fires `woocommerce_update_product`
        // which can trigger a recursive sync. The guard prevents re-entry.
        set_transient($guard_key, 1, 30);

        try {
            // Price extraction
            $price = 0;
            if (isset($item['price']) && is_array($item['price'])) {
                foreach ($item['price'] as $pe) {
                    if (isset($pe['idPriceList']) && (int) $pe['idPriceList'] === 1) {
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

            $product->set_regular_price($price);

            if (!empty($item['name'])) {
                $product->set_name($item['name']);
            }

            // Map Alegra status to WC status and catalog visibility.
            // Alegra: active|inactive → WC: publish|draft and visible|hidden
            $alegra_status = $item['status'] ?? 'active';
            $product->set_status($alegra_status === 'inactive' ? 'draft' : 'publish');
            $product->set_catalog_visibility($alegra_status === 'inactive' ? 'hidden' : 'visible');

            if (!empty($item['description'])) {
                $product->set_description($item['description']);
            }

            if (isset($item['inventory']['availableQuantity']) && $product->get_manage_stock()) {
                try {
                    $product->set_stock_quantity((int) $item['inventory']['availableQuantity']);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to update stock: ' . $e->getMessage());
                }
            }

            if (!empty($item['reference'])) {
                $product->set_sku($item['reference']);
            }

            $product->save();

            // Import product images from Alegra
            if (get_option('alegra_connector_sync_images', true)) {
                $mode = get_option('alegra_connector_sync_images_mode', 'favorite');
                $this->import_product_images($product_id, $item['images'] ?? [], $mode);
            }
        } finally {
            // Release the guard - keep it a bit longer to catch any delayed hooks
            delete_transient($guard_key);
        }
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

            // Skip already-processed URLs in this session
            if (in_array($img['url'], $seen_urls, true)) {
                continue;
            }
            $seen_urls[] = $img['url'];

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
                if (in_array($img['url'], $seen_urls, true)) continue;
                $seen_urls[] = $img['url'];
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
     * Download an image from a URL and attach it to a product.
     * Checks for existing attachment by URL (normalized + hash) to avoid duplicates.
     * Uses a process lock to prevent race conditions.
     * Returns the attachment ID on success, 0 on failure.
     */
    private function download_and_attach_image(int $product_id, string $image_url): int
    {
        if (empty($image_url)) return 0;

        // Normalize URL to strip query params that may differ between syncs
        $normalized_url = $this->normalize_image_url($image_url);
        if (empty($normalized_url)) return 0;

        // Compute hash for fast indexed lookup (avoid full-string scan in MySQL)
        $url_hash = md5($normalized_url);

        // Process lock: prevent two concurrent calls from racing on the same URL
        $lock_key = 'alegra_img_dedup_' . $url_hash;
        if (get_transient($lock_key)) {
            // Another process is handling this URL right now; wait briefly and re-check
            usleep(500000); // 0.5s
            $existing_id = $this->get_attachment_by_url($product_id, $normalized_url);
            if ($existing_id > 0) return $existing_id;
            // Fall through - we'll try ourselves
        }
        set_transient($lock_key, 1, 30);

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
                return $existing_id;
            }

            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp = download_url($image_url, 15);
            if (is_wp_error($tmp)) {
                $this->logger->warning('Image download failed', [
                    'product_id' => $product_id,
                    'url' => $image_url,
                    'error' => $tmp->get_error_message(),
                ]);
                return 0;
            }

            $file_array = [
                'name' => 'alegra-' . $product_id . '-' . substr($url_hash, 0, 8) . '.jpg',
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload($file_array, $product_id);
            if (is_wp_error($attachment_id)) {
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

            $this->logger->debug('Image imported', [
                'product_id' => $product_id,
                'attachment_id' => $attachment_id,
                'url_hash' => $url_hash,
            ]);

            return (int) $attachment_id;
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Find an existing attachment by URL hash (fast indexed lookup).
     */
    private function get_attachment_by_hash(string $url_hash, int $product_id): int
    {
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

        return $id ? (int) $id : 0;
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
            // Ensure attachment is parented to this product
            wp_update_post([
                'ID' => $attachment_id,
                'post_parent' => $product_id,
            ]);
        }

        return $attachment_id;
    }

    public function import_single_item_public(array $item): bool|string
    {
        return $this->import_single_item_from_alegra($item);
    }

    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 15);
        if (is_wp_error($tmp)) {
            $this->logger->warning('Image download failed for product ' . $product_id, ['error' => $tmp->get_error_message()]);
            return;
        }

        $file_array = [
            'name' => 'alegra-' . $product_id . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $product_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        } else {
            $this->logger->warning('Image sideload failed for product ' . $product_id, ['error' => $attachment_id->get_error_message()]);
        }
    }

    public function sync_single_item_by_alegra_id(int $alegra_id): bool|string
    {
        $item = $this->api->get_item($alegra_id);
        if (is_wp_error($item) || !is_array($item)) {
            return false;
        }
        return $this->import_single_item_from_alegra($item);
    }

    public function delete_from_alegra(int $product_id): array|\WP_Error
    {
        $alegra_id = (int) get_post_meta($product_id, '_alegra_item_id', true);
        if ($alegra_id <= 0) {
            return new \WP_Error('not_linked', 'Product not linked to Alegra');
        }

        $result = $this->api->delete_item($alegra_id);
        if (!is_wp_error($result)) {
            delete_post_meta($product_id, '_alegra_item_id');
        }

        return $result;
    }

    private function get_product_by_alegra_id(int $alegra_id): ?int
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

    private function get_main_category_name(array $cat_ids): ?string
    {
        if (empty($cat_ids)) {
            return null;
        }
        $cat = get_term($cat_ids[0], 'product_cat');
        return ($cat && !is_wp_error($cat)) ? $cat->name : null;
    }
}