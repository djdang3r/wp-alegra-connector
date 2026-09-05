<?php
/**
 * Categories Sync Handler
 *
 * @package Alegra\Connector\Sync
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Categories
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function sync_to_alegra(\WP_Term $category): array|\WP_Error
    {
        $alegra_id = get_term_meta($category->term_id, 'alegra_category_id', true);

        $data = [
            'name' => $category->name,
            'description' => $category->description ?: '',
        ];

        if (!empty($alegra_id)) {
            $result = $this->api->update_item_category((int) $alegra_id, $data);
            $this->logger->info('Category updated in Alegra', [
                'term_id' => $category->term_id,
                'alegra_id' => $alegra_id,
            ]);
        } else {
            $result = $this->api->create_item_category($data);
            if (!is_wp_error($result) && isset($result['id'])) {
                update_term_meta($category->term_id, 'alegra_category_id', $result['id']);
                $this->logger->info('Category created in Alegra', [
                    'term_id' => $category->term_id,
                    'alegra_id' => $result['id'],
                ]);
            }
        }

        return $result;
    }

    public function sync_all(): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0];

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($categories)) {
            return $categories;
        }

        foreach ($categories as $category) {
            $sync_result = $this->sync_to_alegra($category);
            if (is_wp_error($sync_result)) {
                $result['errors']++;
                $this->logger->error('Failed to sync category', [
                    'term_id' => $category->term_id,
                    'error' => $sync_result->get_error_message(),
                ]);
            } else {
                $result['synced']++;
            }
        }

        $this->logger->info('Categories sync completed', $result);

        return $result;
    }

    public function import_from_alegra(): array|\WP_Error
    {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0];
        $current_page = 1;
        $max_pages = 200;
        set_time_limit(60);

        for ($p = 1; $p <= $max_pages; $p++) {
            $alegra_categories = $this->api->get_item_categories([
                'start' => ($current_page - 1) * 30,
                'limit' => 30,
            ]);

            if (is_wp_error($alegra_categories)) {
                if ($current_page === 1) return $alegra_categories;
                break;
            }

            if (empty($alegra_categories)) break;

            foreach ($alegra_categories as $category) {
                $r = $this->import_single_category($category);
                if ($r === true) $result['imported']++;
                elseif ($r === 'updated') $result['updated']++;
                else $result['errors']++;
            }

            if (count($alegra_categories) < 30) break;
            $current_page++;
        }

        $this->logger->info('Categories import from Alegra completed', $result);

        return $result;
    }

    private function import_single_category(array $category): bool|string
    {
        $alegra_id = $category['id'];
        $name = $category['name'];

        $existing_term_id = $this->get_category_by_alegra_id($alegra_id);

        if ($existing_term_id) {
            wp_update_term($existing_term_id, 'product_cat', [
                'name' => $name,
                'description' => $category['description'] ?? '',
            ]);
            return 'updated';
        }

        $term = wp_insert_term($name, 'product_cat', [
            'description' => $category['description'] ?? '',
        ]);

        if (is_wp_error($term)) {
            $this->logger->error('Failed to create category from Alegra', [
                'alegra_id' => $alegra_id,
                'error' => $term->get_error_message(),
            ]);
            return false;
        }

        update_term_meta($term['term_id'], 'alegra_category_id', $alegra_id);

        $this->logger->info('Category imported from Alegra', [
            'alegra_id' => $alegra_id,
            'term_id' => $term['term_id'],
        ]);

        return true;
    }

    private function get_category_by_alegra_id(int $alegra_id): ?int
    {
        global $wpdb;

        $term_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'alegra_category_id' AND meta_value = %d LIMIT 1",
                $alegra_id
            )
        );

        return $term_id ? (int) $term_id : null;
    }

    public function delete_from_alegra(int $term_id): array|\WP_Error
    {
        $alegra_id = get_term_meta($term_id, 'alegra_category_id', true);

        if (empty($alegra_id)) {
            return new \WP_Error('not_linked', 'Category not linked to Alegra');
        }

        $this->logger->info('Category delete from Alegra not implemented via API', [
            'term_id' => $term_id,
            'alegra_id' => $alegra_id,
        ]);

        delete_term_meta($term_id, 'alegra_category_id');

        return ['success' => true];
    }
}