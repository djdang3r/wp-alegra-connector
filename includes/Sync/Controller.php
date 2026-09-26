<?php
/**
 * Sync Controller
 *
 * @package Alegra\Connector\Sync
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Heartbeat;
use Alegra\Connector\Kill_Switch;
use Alegra\Connector\Logger;
use Alegra\Connector\Runs;
use Alegra\Connector\Run_Context;

if (!defined('ABSPATH')) {
    exit;
}

class Controller
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;
    private Products $products;
    private Customers $customers;
    private Orders $orders;
    private Categories $categories;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;

        $this->products = new Products($api, $logger);
        $this->customers = new Customers($api, $logger);
        $this->orders = new Orders($api, $logger);
        $this->categories = new Categories($api, $logger);
    }

    /**
     * Register the cron sync hook - call this explicitly
     */
    public function register_cron_hook(): void
    {
        if (!has_action('alegra_connector_cron_sync', [$this, 'run_cron_sync'])) {
            add_action('alegra_connector_cron_sync', [$this, 'run_cron_sync']);
        }

        // Dedicated one-off dispatcher for the Monitor's "Run now". Scheduling
        // a single event under the SAME recurring hook is unreliable: WP's
        // wp_schedule_single_event() suppresses a duplicate when an identical
        // event is due within 10 minutes, which the recurring event often is.
        // A separate hook always queues and never touches the recurrence.
        if (!has_action('alegra_connector_cron_sync_now', [$this, 'run_cron_sync'])) {
            add_action('alegra_connector_cron_sync_now', [$this, 'run_cron_sync']);
        }

        // Documented manual trigger (docs/RELEASE_2.1.9_DEPLOY.md:359):
        //   wp eval 'do_action("alegra_sync_inventory_from_alegra");'
        // The action was documented but never registered. The pull enforces
        // every gate itself (kill switch, lock, cancellation, inventory_source).
        if (!has_action('alegra_sync_inventory_from_alegra', [$this, 'run_inventory_sync'])) {
            add_action('alegra_sync_inventory_from_alegra', [$this, 'run_inventory_sync']);
        }

        // Payment retry sweep. Registered under its own hook so it can be
        // triggered manually (do_action / WP-CLI) and so the hourly schedule
        // is independent from the inbound sync cron.
        if (!has_action('alegra_connector_payment_reconcile', [$this, 'run_payment_reconcile'])) {
            add_action('alegra_connector_payment_reconcile', [$this, 'run_payment_reconcile']);
        }

        // Hourly retry of retriable failed invoices (REQ-QUEUE-06). Opt-in
        // (`alegra_connector_invoice_retry_enabled`, default false).
        if (!has_action('alegra_connector_invoice_retry', [$this, 'run_invoice_retry'])) {
            add_action('alegra_connector_invoice_retry', [$this, 'run_invoice_retry']);
        }
    }

    /**
     * Payment retry sweep entry point (REQ-REC-4).
     *
     * Kill switch + one global lock around the whole run; the per-order lock
     * lives in Orders::reconcile_payment_only(). The batch itself is bounded by
     * the `alegra_connector_payment_reconcile_batch` option.
     *
     * @return array{checked:int,reconciled:int,errors:int,skipped?:string}
     */
    public function run_payment_reconcile(): array
    {
        if (Kill_Switch::is_active()) {
            $this->logger->info('Payment reconcile skipped: kill switch active');
            return ['reconciled' => 0, 'skipped' => 'skipped_kill_switch'];
        }

        $lock = self::acquire_lock('alegra_payment_reconcile', 300);
        if ($lock === false) {
            $this->logger->info('Payment reconcile skipped: another run is in progress');
            return ['reconciled' => 0, 'skipped' => 'skipped_locked'];
        }

        try {
            return $this->orders->reconcile_missing_payments();
        } finally {
            self::release_lock('alegra_payment_reconcile', $lock);
        }
    }

    /**
     * Reintento horario de facturas fallidas retriables (REQ-QUEUE-06).
     * Opt-in: `alegra_connector_invoice_retry_enabled` (default false).
     *
     * @return array{checked?:int,retried?:int,resolved?:int,failed?:int,errors?:int,skipped?:string}
     */
    public function run_invoice_retry(): array
    {
        if (Kill_Switch::is_active()) {
            return ['skipped' => 'kill_switch'];
        }
        if (!get_option('alegra_connector_invoice_retry_enabled', false)) {
            return ['skipped' => 'disabled'];
        }

        $lock = self::acquire_lock('alegra_invoice_retry', 300);
        if ($lock === false) {
            return ['skipped' => 'locked'];
        }
        register_shutdown_function(static fn () => self::release_lock('alegra_invoice_retry', $lock));

        try {
            return Run_Context::wrap('invoice_retry', fn ($run_id) => $this->orders->retry_failed_invoices());
        } finally {
            self::release_lock('alegra_invoice_retry', $lock);
        }
    }

    /**
     * Manual inventory-pull entry point for the
     * `alegra_sync_inventory_from_alegra` action (WP-CLI / do_action).
     */
    public function run_inventory_sync(float $deadline = 0.0): array
    {
        $result = $this->products->sync_inventory_from_alegra(0, $deadline);
        $this->logger->info('Inventory pull triggered', $result);
        return $result;
    }

    public function run_cron_sync(): void
    {
        // Kill switch guard — fast (<0.1ms) thanks to static cache
        if (Kill_Switch::is_active()) {
            $this->logger->info('Cron sync skipped: kill switch active', [
                'reason' => Kill_Switch::reason(),
            ]);
            return;
        }

        // `sync_method` gates the INBOUND pull: 'real-time' is webhooks-only and
        // 'disabled' means no automatic inbound sync at all. This self-check
        // also neutralises a cron event left scheduled by an older version.
        $sync_method = (string) get_option('alegra_connector_sync_method', 'cron');
        if (!in_array($sync_method, ['cron', 'both'], true)) {
            $this->logger->info('Cron sync skipped: sync_method does not include periodic pull', [
                'sync_method' => $sync_method,
            ]);
            return;
        }

        // D7 §8.3: avisar si el lock global quedó vencido de una corrida anterior
        // (acquire_lock lo reclama en silencio, Controller.php:421-428).
        $cron_lock_option = 'alegra_lock_alegra_cron_global';
        $existing_lock = get_option($cron_lock_option);
        if (is_array($existing_lock)
            && isset($existing_lock['expires'])
            && (int) $existing_lock['expires'] < time()) {
            $this->logger->warning('cron lock reclaimed (previous run overran)', [
                'expired_at' => (int) $existing_lock['expires'],
                'now'        => time(),
            ]);
        }

        // AC-20: one global mutex around the WHOLE run. Per-entity locks alone
        // let two ticks interleave (each holding a different entity lock),
        // producing duplicate runs, double API traffic and double writes.
        $global_lock = self::acquire_lock('alegra_cron_global', 600);
        if ($global_lock === false) {
            $this->logger->info('Cron sync skipped: another run is already in progress');
            return;
        }

        // D7 §8.1 (extendido): un fatal saltea el finally (:156-158) y deja el
        // lock global tomado 600 s ⇒ TODO el cron se saltea. El shutdown handler
        // lo libera; release_lock() valida el token (:441-448) ⇒ nunca libera un
        // lock ajeno (DR10/R12).
        register_shutdown_function(static function () use ($global_lock): void {
            \Alegra\Connector\Sync\Controller::release_lock('alegra_cron_global', $global_lock);
        });

        // D7 §8.3: presupuesto global de la corrida < TTL 600 del lock global,
        // para que un tick no se solape con el anterior (R13).
        $run_budget = max(60, min(590, (int) get_option('alegra_connector_cron_run_budget', 540)));
        $deadline = microtime(true) + $run_budget;

        $this->logger->info('Starting cron synchronization (Alegra → WC only)');

        try {
            // D1: mismo contrato que Runs::track (start → fn → completed /
            // failed+rethrow), pero centralizado en Run_Context (Runs + Heartbeat
            // + Logger). El global lock se libera en el finally.
            Run_Context::wrap('cron_sync_all', function ($run_id) use ($deadline) {
                $this->run_cron_sync_inner($run_id, $deadline);
            }, 'cron');
        } finally {
            self::release_lock('alegra_cron_global', $global_lock);
        }
    }

    private function run_cron_sync_inner(int $run_id, float $deadline = 0.0): void
    {
        $result = [
            'products' => 0,
            'customers' => 0,
            'orders' => 0,
            'categories' => 0,
            'errors' => [],
        ];
        $step = 0;
        $total_steps = 4;

        // PULL only: Alegra → WC
        if (get_option('alegra_connector_sync_products', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'products', 'message' => __('Importando productos desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during products');
                Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                return;
            }
            $lock = $this->acquire_sync_lock('products');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped products: another sync is running');
            } else {
                try {
                    $products_result = $this->products->import_from_alegra(1, 30, $run_id, $deadline);
                } finally {
                    $this->release_sync_lock('products', $lock);
                }
            }
            if (isset($products_result) && !is_wp_error($products_result)) {
                $result['products'] = ($products_result['imported'] ?? 0) + ($products_result['updated'] ?? 0);
            } elseif (isset($products_result) && is_wp_error($products_result)) {
                $result['errors']['products'] = $products_result->get_error_message();
            }
        } else {
            $this->logger->info('Cron sync: products skipped by configuration (sync_products=false)');
            $result['products_skipped'] = 'config';
        }

        // Inventory pull (Alegra → WC stock). Its own gate, independent of
        // sync_products: a merchant who uses Alegra as the inventory source but
        // does not import the product catalog still gets stock. It runs outside
        // the products lock so it never contends with the import, and the pull
        // itself re-checks the kill switch, the lock, the cancellation
        // transient, the per-run stop and inventory_source.
        if ((string) get_option('alegra_connector_inventory_source', 'alegra') === 'alegra'
            && get_option('alegra_connector_inventory_sync_enabled', true)) {
            $inventory_result = $this->products->sync_inventory_from_alegra($run_id, $deadline);
            if (!empty($inventory_result['updated'])) {
                $result['inventory'] = (int) $inventory_result['updated'];
            }
        }

        if (get_option('alegra_connector_sync_customers', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'customers', 'message' => __('Importando clientes desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during customers');
                Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                return;
            }
            $lock = $this->acquire_sync_lock('customers');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped customers: another sync is running');
            } else {
                try {
                    $customers_result = $this->customers->import_from_alegra(1, 30, $run_id);
                } finally {
                    $this->release_sync_lock('customers', $lock);
                }
            }
            // AC-40: a distinct variable per entity. Reusing $products_result
            // here made the customers count report the PRODUCTS numbers when the
            // customers lock was held (the block never reassigned it).
            if (isset($customers_result) && !is_wp_error($customers_result)) {
                $result['customers'] = ($customers_result['imported'] ?? 0) + ($customers_result['updated'] ?? 0);
            } elseif (isset($customers_result) && is_wp_error($customers_result)) {
                $result['errors']['customers'] = $customers_result->get_error_message();
            }
        }

        if (get_option('alegra_connector_sync_categories', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'categories', 'message' => __('Importando categorias desde Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            if (Runs::should_stop($run_id)) {
                $this->logger->info('Cron sync stopped by user during categories');
                Run_Context::finish($run_id, 'cancelled', 'Detenido por el usuario');
                return;
            }
            $lock = $this->acquire_sync_lock('categories');
            if ($lock === false) {
                $this->logger->info('Cron sync skipped categories: another sync is running');
            } else {
                try {
                    $categories_result = $this->categories->import_from_alegra($run_id);
                } finally {
                    $this->release_sync_lock('categories', $lock);
                }
            }
            if (isset($categories_result) && !is_wp_error($categories_result)) {
                $result['categories'] = ($categories_result['imported'] ?? 0) + ($categories_result['updated'] ?? 0);
            } elseif (isset($categories_result) && is_wp_error($categories_result)) {
                $result['errors']['categories'] = $categories_result->get_error_message();
            }
        }

        // Always run polling for invoice statuses
        if (get_option('alegra_connector_sync_orders', false)) {
            $step++;
            Heartbeat::set($run_id, ['step' => 'orders', 'message' => __('Verificando estado de facturas en Alegra...', 'alegra-connector'), 'step_num' => $step, 'total_steps' => $total_steps]);
            Runs::update_progress($run_id, $step, $total_steps);
            $poll_result = $this->orders->poll_invoice_statuses();
            $result['orders'] = ($poll_result['completed'] ?? 0);
        }

        set_transient('alegra_connector_last_sync', time(), DAY_IN_SECONDS);

        // AC-22: bounded retention pruning at the tail of every cron run.
        \Alegra\Connector\Maintenance::prune();

        Heartbeat::set($run_id, ['step' => 'done', 'message' => sprintf(
            __('Completado: %d productos, %d clientes, %d ordenes, %d categorias', 'alegra-connector'),
            $result['products'], $result['customers'], $result['orders'], $result['categories']
        ), 'step_num' => $total_steps, 'total_steps' => $total_steps]);
        Runs::update_progress($run_id, $total_steps, $total_steps);

        $this->logger->info('Cron synchronization completed', $result);
    }

    public function sync_entity(string $type, int $id, string $action): array|\WP_Error
    {
        $this->logger->info('Syncing entity', ['type' => $type, 'id' => $id, 'action' => $action]);

        switch ($type) {
            case 'product':
                return $this->sync_single_product($id, $action);
            case 'customer':
                return $this->sync_single_customer($id, $action);
            case 'order':
                return $this->sync_single_order($id, $action);
            case 'category':
                return $this->sync_single_category($id, $action);
            default:
                return new \WP_Error('unknown_type', 'Unknown entity type: ' . $type);
        }
    }

    private function sync_single_product(int $id, string $action): array|\WP_Error
    {
        $product = wc_get_product($id);
        if (!$product) {
            return new \WP_Error('not_found', __('Producto no encontrado', 'alegra-connector'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->products->sync_to_alegra($product);
            case 'delete':
                return $this->products->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_customer(int $id, string $action): array|\WP_Error
    {
        $customer = get_userdata($id);
        if (!$customer) {
            return new \WP_Error('not_found', __('Cliente no encontrado', 'alegra-connector'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->customers->sync_to_alegra($customer);
            case 'delete':
                return $this->customers->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_order(int $id, string $action): array|\WP_Error
    {
        $order = wc_get_order($id);
        if (!$order) {
            return new \WP_Error('not_found', __('Pedido no encontrado', 'alegra-connector'));
        }

        switch ($action) {
            case 'create':
                return $this->orders->create_invoice($order);
            case 'complete':
                return $this->orders->create_invoice_with_payment($order);
            case 'refund':
                return $this->orders->create_credit_note($order);
            case 'cancel':
                return $this->orders->void_invoice($order);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    private function sync_single_category(int $id, string $action): array|\WP_Error
    {
        $term = get_term($id, 'product_cat');
        if (!$term) {
            return new \WP_Error('not_found', __('Categoría no encontrada', 'alegra-connector'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                return $this->categories->sync_to_alegra($term);
            case 'delete':
                return $this->categories->delete_from_alegra($id);
            default:
                return new \WP_Error('unknown_action', 'Unknown action: ' . $action);
        }
    }

    public function import_from_alegra(string $type, int $run_id = 0): array|\WP_Error
    {
        switch ($type) {
            case 'products':
                return $this->products->import_from_alegra(1, 30, $run_id);
            case 'customers':
                return $this->customers->import_from_alegra(1, 30, $run_id);
            case 'categories':
                return $this->categories->import_from_alegra($run_id);
            default:
                return new \WP_Error('unknown_type', 'Unknown import type: ' . $type);
        }
    }

    /**
     * Acquire an atomic lock.
     *
     * add_option() is a real compare-and-swap: wp_options.option_name has a
     * UNIQUE index, so only one caller can create the row. Unlike the old
     * get_transient()+set_transient() pattern — an unconditional UPSERT — this
     * cannot be won by two concurrent PHP-FPM workers at once.
     *
     * @param string $key Lock identifier (stored as alegra_lock_<key>).
     * @param int    $ttl Lock lifetime in seconds; used to reclaim stale locks.
     * @return string|false The lock token on success, false if already held.
     */
    public static function acquire_lock(string $key, int $ttl = 300): string|false
    {
        $option = 'alegra_lock_' . $key;
        $token  = wp_generate_password(20, false);

        if (!add_option($option, ['token' => $token, 'expires' => time() + $ttl], '', 'no')) {
            // Lock exists — reclaim it only if it is stale (a crashed worker).
            $existing = get_option($option);
            if (is_array($existing) && isset($existing['expires']) && (int) $existing['expires'] < time()) {
                delete_option($option);
                if (!add_option($option, ['token' => $token, 'expires' => time() + $ttl], '', 'no')) {
                    return false;
                }
                return $token;
            }
            return false;
        }

        return $token;
    }

    /**
     * Release a lock, but only if we still own it (never release someone else's).
     *
     * @param string $key   Same key passed to acquire_lock().
     * @param string $token Token returned by acquire_lock().
     */
    public static function release_lock(string $key, string $token): void
    {
        $option   = 'alegra_lock_' . $key;
        $existing = get_option($option);
        if (is_array($existing) && isset($existing['token']) && $existing['token'] === $token) {
            delete_option($option);
        }
    }

    /**
     * Per-request registry of sync locks held by THIS process, with a nesting
     * depth. The cron controller and the admin AJAX handler both hold the
     * per-type lock and then call the entity import method, which holds the
     * SAME lock again — a plain re-acquire would fail and silently skip the
     * import. Tracking depth makes the lock re-entrant within one request while
     * the underlying option lock still blocks other requests.
     *
     * @var array<string, array{token:string, depth:int}>
     */
    private static array $held_sync_locks = [];

    /**
     * Try to acquire an atomic, re-entrant lock for the given sync type.
     *
     * Returns a unique token string on success, or false if another PROCESS
     * already holds the lock. Re-acquiring the same type from the same request
     * succeeds (depth++) and returns the existing token. TTL is 300 seconds
     * (5 min) so a crashed run cannot block future runs forever.
     *
     * @param string $type One of: 'products', 'customers', 'categories'.
     * @return string|false Token on success, false if locked by another process.
     */
    private function acquire_sync_lock(string $type): string|false
    {
        $key = 'alegra_sync_running_' . $type;

        if (isset(self::$held_sync_locks[$key])) {
            self::$held_sync_locks[$key]['depth']++;
            return self::$held_sync_locks[$key]['token'];
        }

        $token = self::acquire_lock($key, 300);
        if ($token === false) {
            return false;
        }

        self::$held_sync_locks[$key] = ['token' => $token, 'depth' => 1];

        return $token;
    }

    /**
     * Release a sync lock — only on the outermost release, and only if the
     * token matches.
     *
     * This prevents a stale process from accidentally releasing a fresh lock
     * after a 5-min TTL turnover, and keeps a nested acquire/release pair from
     * freeing the lock its caller still holds.
     *
     * @param string $type  Sync type (same key as acquire).
     * @param string $token Token from acquire_sync_lock().
     */
    private function release_sync_lock(string $type, string $token): void
    {
        $key = 'alegra_sync_running_' . $type;

        if (!isset(self::$held_sync_locks[$key])) {
            return;
        }

        self::$held_sync_locks[$key]['depth']--;
        if (self::$held_sync_locks[$key]['depth'] > 0) {
            return;
        }

        unset(self::$held_sync_locks[$key]);
        self::release_lock($key, $token);
    }

    /**
     * Public wrapper for acquire_sync_lock — for use from other sync classes.
     */
    public static function acquire_sync_lock_public(string $type): string|false
    {
        return (new self(null, null))->acquire_sync_lock($type);
    }

    /**
     * Public wrapper for release_sync_lock — for use from other sync classes.
     */
    public static function release_sync_lock_public(string $type, string $token): void
    {
        (new self(null, null))->release_sync_lock($type, $token);
    }

    /**
     * Test-only: clear the re-entrant lock registry between tests.
     *
     * The registry is request-scoped in production, but the harness runs many
     * "requests" in one process; without this, a lock held by one test makes the
     * next test see a phantom "sync in progress".
     */
    public static function reset_locks_for_testing(): void
    {
        self::$held_sync_locks = [];
    }
}