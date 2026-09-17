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
     * Sync variable product (parent with variants as subitems)
     */
    private function sync_variable_product(\WC_Product $product): array|\WP_Error
    {
        $alegra_id = (string) get_post_meta($product->get_id(), '_alegra_item_id', true);

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
                    'id' => (string) $var_result['id'],
                    'reference' => $variation->get_sku(),
                    'price' => (float) $variation->get_regular_price(),
                    'quantity' => (int) ($variation->get_stock_quantity() ?? 0),
                ];
            }
        }

        $data = $this->prepare_variable_product_data($product, $subitems);

        if ($alegra_id !== '') {
            $result = $this->api->update_item($alegra_id, $data);
            $this->logger->info('Variable product updated in Alegra', [
                'product_id' => $product->get_id(),
                'alegra_id' => $alegra_id,
                'variations' => count($subitems),
            ]);
        } else {
            // AC-44: a variable product is always a `variantParent` in Alegra
            // (create and update must agree; `kit` is for composed items only).
            // AC-16: the mapped "default status" only applies on creation.
            $data['status'] = $this->field_mapping('default_status', 'active');
            $result = $this->api->create_item($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($product->get_id(), '_alegra_item_id', (string) $result['id']);
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
        $alegra_id = (string) get_post_meta($variation->get_id(), '_alegra_item_id', true);
        $linked_by_sku = false;

        // Resolve an existing Alegra item by SKU BEFORE building the payload:
        // the builder needs the final create-vs-update intent so it never
        // re-sends `initialQuantity` on an update (R2 hotfix).
        if ($alegra_id === '') {
            // Search by SKU in Alegra before creating (prevent duplicates)
            $sku = $variation->get_sku();
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $alegra_id = (string) $items[0]['id'];
                    $linked_by_sku = true;
                    update_post_meta($variation->get_id(), '_alegra_item_id', $alegra_id);
                }
            }
        }

        $is_create = ($alegra_id === '');
        $data = $this->prepare_variation_data($variation, $is_create);

        if (!$is_create) {
            $result = $this->api->update_item($alegra_id, $data);
            if ($linked_by_sku) {
                $this->logger->info('Variation linked to existing Alegra item by SKU', [
                    'variation_id' => $variation->get_id(),
                    'alegra_id' => $alegra_id,
                ]);
            }

            return $result;
        }

        $data['type'] = 'variant';
        // AC-16: the mapped "default status" only applies on creation.
        $data['status'] = $this->field_mapping('default_status', 'active');
        $result = $this->api->create_item($data);
        if (!is_wp_error($result) && isset($result['id'])) {
            update_post_meta($variation->get_id(), '_alegra_item_id', (string) $result['id']);
            $this->logger->info('Variation synced to Alegra', [
                'variation_id' => $variation->get_id(),
                'alegra_id' => $result['id'],
            ]);
        }

        return $result;
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
            'type' => 'simple',
            'price' => [
                [
                    'idPriceList' => $this->price_list_id(),
                    'price' => (float) $product->get_regular_price(),
                ],
            ],
            'inventory' => [
                'unit' => $this->field_mapping('default_unit', 'unit'),
            ],
        ];

        // R2 hotfix: `initialQuantity` is the quantity the item was CREATED
        // with. It is the documented way to set the initial stock on create and
        // must NEVER be re-sent on update, or every product edit would reset
        // the current stock.
        if ($is_create) {
            $data['inventory']['initialQuantity'] = (int) ($product->get_stock_quantity() ?? 0);
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

        // Category
        $alegra_cat_id = $this->resolve_alegra_category_id($product);
        if ($alegra_cat_id !== '') {
            $data['category'] = ['id' => $alegra_cat_id];
        }

        return $data;
    }

    /**
     * Prepare data for variable product parent
     */
    private function prepare_variable_product_data(\WC_Product $product, array $subitems): array
    {
        $data = [
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

        // Category
        $alegra_cat_id = $this->resolve_alegra_category_id($product);
        if ($alegra_cat_id !== '') {
            $data['category'] = ['id' => $alegra_cat_id];
        }

        return $data;
    }

    /**
     * Prepare data for a single variation
     */
    private function prepare_variation_data(\WC_Product $variation, bool $is_create): array
    {
        $parent = wc_get_product($variation->get_parent_id());
        $attributes = $variation->get_attributes();
        $name_parts = [$parent ? $parent->get_name() : 'Variation'];
        foreach ($attributes as $attr => $value) {
            $name_parts[] = $value;
        }
        $variation_name = implode(' - ', $name_parts);

        $data = [
            'name' => $variation_name,
            'reference' => $variation->get_sku(),
            'description' => wp_strip_all_tags($variation->get_description()),
            'price' => [
                [
                    'idPriceList' => $this->price_list_id(),
                    'price' => (float) $variation->get_regular_price(),
                ],
            ],
            'inventory' => [
                'unit' => $this->field_mapping('default_unit', 'unit'),
            ],
        ];

        // R2 hotfix: see prepare_simple_product_data(). A variation's
        // `initialQuantity` is only sent on create, never on update.
        if ($is_create) {
            $data['inventory']['initialQuantity'] = (int) ($variation->get_stock_quantity() ?? 0);
        }

        $this->apply_warehouse($data, $variation, $is_create);

        // Category (variations inherit from their parent)
        $cat_source = ($parent && $parent->get_category_ids()) ? $parent : $variation;
        $alegra_cat_id = $this->resolve_alegra_category_id($cat_source);
        if ($alegra_cat_id !== '') {
            $data['category'] = ['id' => $alegra_cat_id];
        }

        return $data;
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

        // R2 hotfix: a warehouse `initialQuantity` is still an initial
        // quantity — it is only meaningful on create. On update we omit the
        // whole `warehouses` array: the Alegra docs mark `initialQuantity` as
        // obligatorio inside a warehouse object, so sending a partial object
        // would be undocumented (and could reset the per-warehouse stock).
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
    public function sync_inventory_from_alegra(): array
    {
        $result = ['updated' => 0, 'errors' => 0, 'pages' => 0, 'locked' => false, 'skipped' => false];

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
                    if (!isset($item['inventory']['availableQuantity'])) {
                        continue;
                    }

                    $product_id = $this->get_product_by_alegra_id((string) $item['id']);
                    if (!$product_id) {
                        continue;
                    }

                    $product = wc_get_product($product_id);
                    if (!$product) {
                        continue;
                    }

                    $new_qty = (int) $item['inventory']['availableQuantity'];
                    if (!$product->get_manage_stock()) {
                        continue;
                    }

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

                $result['pages'] = $p;

                if (count($items) < 30) {
                    break;
                }
            }

            $this->logger->info('Inventory sync from Alegra completed', $result);

            return $result;
        } finally {
            \Alegra\Connector\Sync\Controller::release_sync_lock_public('products', $lock);
        }
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

        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0, 'total_pages' => 0, 'current_page' => 0, 'paused' => false];
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
                $r = $this->import_single_item_from_alegra($item);
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
        $alegra_id = (string) ($item['id'] ?? '');
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
            $this->update_product_from_alegra($product, $item);
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
                $this->assign_variation_sku((int) $variation_id, $item);
                if (!empty($item['category'])) {
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
            $this->update_product_from_alegra($variation, $item);
        }

        // AC-45: attributes + SKU make the variation selectable in WooCommerce.
        $this->assign_variation_attributes((int) $variation_id, $item);
        $this->assign_variation_sku((int) $variation_id, $item);

        if (!empty($item['category'])) {
            $this->assign_product_category((int) $variation_id, $item);
        }
    }

    /**
     * Assign the Alegra item's category to the WC product.
     * Reads $item['category'] (object with id/name) and finds or creates the WC term.
     */
    private function assign_product_category(int $product_id, array $item): void
    {
        $category = $item['category'] ?? null;
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

            // Skip already-processed URLs in this session. AC-79: use a hash
            // set (O(1)) instead of in_array() (O(n)) to avoid O(n²) dedup.
            if (isset($seen_urls[$img['url']])) {
                continue;
            }
            $seen_urls[$img['url']] = true;

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
     * Hosts allowed as a source for product images (AC-68).
     *
     * Alegra serves item images from its own CDN — the documented response
     * example is `https://cdn3.alegra.com/...` — so the base domain plus every
     * subdomain is allowed. Extendable via the
     * `alegra_connector_allowed_image_hosts` filter.
     *
     * @return string[]
     */
    public static function allowed_image_hosts(): array
    {
        $hosts = ['alegra.com'];
        $filtered = apply_filters('alegra_connector_allowed_image_hosts', $hosts);
        return is_array($filtered) ? $filtered : $hosts;
    }

    /**
     * True only for an https URL whose host is in the image allowlist. Blocks
     * SSRF via an attacker-controlled Alegra image URL (AC-68).
     */
    public static function is_allowed_image_url(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower((string) $parts['host']);
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
            $this->logger->warning('Blocked image download from a non-allowlisted host', [
                'product_id' => $product_id,
                'host' => (string) (wp_parse_url($image_url)['host'] ?? ''),
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
            // AC-23: index the new attachment for O(1) dedup on later imports.
            \Alegra\Connector\Entity_Map::map('image', $url_hash, 'attachment', (int) $attachment_id);

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

    public function import_single_item_public(array $item): bool|string
    {
        return $this->import_single_item_from_alegra($item);
    }

    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        if (!self::is_allowed_image_url($image_url)) {
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
        if (!is_wp_error($result)) {
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