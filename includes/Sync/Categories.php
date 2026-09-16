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
            $result = $this->api->update_item_category((string) $alegra_id, $data);
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
        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Categories import skipped: kill switch active');
            return new \WP_Error('kill_switch_active', 'Plugin is disconnected or deactivated');
        }

        $result = ['imported' => 0, 'updated' => 0, 'errors' => 0];
        $current_page = 1;
        $max_pages = 200;
        set_time_limit(300);

        for ($p = 1; $p <= $max_pages; $p++) {
            // Re-check the kill switch every page so an in-flight run stops.
            if (\Alegra\Connector\Kill_Switch::is_active()) {
                $this->logger->info('Categories import stopped: kill switch active');
                break;
            }

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

        // Link an existing WC term with the same name instead of failing with
        // WP_Error('term_exists') when it was created manually (without meta).
        $existing = term_exists($name, 'product_cat');
        if ($existing) {
            $term_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            update_term_meta($term_id, 'alegra_category_id', $alegra_id);
            wp_update_term($term_id, 'product_cat', [
                'description' => $category['description'] ?? '',
            ]);

            $this->logger->info('Category linked to existing term from Alegra', [
                'alegra_id' => $alegra_id,
                'term_id' => $term_id,
            ]);

            return 'updated';
        }

        $term = wp_insert_term($name, 'product_cat', [
            'description' => $category['description'] ?? '',
            'parent' => self::get_import_parent_id(),
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

    /**
     * Parent term ID for categories imported from Alegra (0 = top level).
     */
    private static function get_import_parent_id(): int
    {
        return (int) get_option('alegra_connector_import_category_parent', 0);
    }

    private function get_category_by_alegra_id(string $alegra_id): ?int
    {
        global $wpdb;

        $term_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'alegra_category_id' AND meta_value = %s LIMIT 1",
                $alegra_id
            )
        );

        return $term_id ? (int) $term_id : null;
    }

    /**
     * Find WC product_cat terms whose alegra_category_id no longer exists in Alegra.
     *
     * @return array<int, array{term_id:int, name:string, alegra_id:string}>
     */
    public function find_orphans(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        // AC-27: fetch the Alegra category list ONCE (paginated) and diff
        // locally. The old implementation issued one HTTP GET per WC term
        // (200 categories = 200 sequential API calls).
        $valid_ids = $this->fetch_all_category_ids();
        if ($valid_ids === null) {
            // The first page failed; never report every term as an orphan.
            return [];
        }

        $orphans = [];
        foreach ($terms as $term) {
            $alegra_id = (string) get_term_meta($term->term_id, 'alegra_category_id', true);
            if ($alegra_id === '' || isset($valid_ids[$alegra_id])) {
                continue;
            }
            $orphans[] = [
                'term_id'   => (int) $term->term_id,
                'name'      => (string) $term->name,
                'alegra_id' => $alegra_id,
            ];
        }
        return $orphans;
    }

    /**
     * Fetch every Alegra item-category id, paginated, as a lookup set.
     *
     * @return array<string, true>|null null when the first page could not be fetched.
     */
    private function fetch_all_category_ids(): ?array
    {
        $ids = [];
        $per_page = 30;
        $max_pages = 200;

        for ($page = 1; $page <= $max_pages; $page++) {
            $batch = $this->api->get_item_categories([
                'start' => ($page - 1) * $per_page,
                'limit' => $per_page,
            ]);

            if (is_wp_error($batch)) {
                // A mid-pagination failure keeps what we already have; a failure
                // on page 1 is fatal for the orphan check.
                return $page === 1 ? null : $ids;
            }

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $category) {
                if (is_array($category) && isset($category['id'])) {
                    $ids[(string) $category['id']] = true;
                }
            }

            if (count($batch) < $per_page) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Unlink the alegra_category_id meta from a term.
     */
    public function unlink(int $term_id): void
    {
        delete_term_meta($term_id, 'alegra_category_id');
        $this->logger->info('Category unlinked from Alegra', ['term_id' => $term_id]);
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