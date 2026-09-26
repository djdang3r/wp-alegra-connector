<?php
/**
 * State Sync — refunds, profile updates, payment method changes.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

use Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class State_Sync
{
    public const DEBOUNCE_SECONDS   = 300;
    public const STATUS_SYNCED_META = '_alegra_status_synced_at';
    public const REFUND_META_FMT    = '_alegra_credit_note_id_for_%s';
    public const VOID_META          = '_alegra_void_at';

    /**
     * Register the state-sync hooks. Idempotent.
     */
    public static function register_hooks(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        // Hook signature: ($order_id, $refund_id).
        add_action('woocommerce_order_refunded', static function ($order_id, $refund_id = 0): void {
            self::handle_refund((int) $order_id, (int) $refund_id);
        }, 10, 2);

        add_action('user_register', static function (int $user_id): void {
            self::handle_user_profile_update($user_id);
        }, 20, 1);

        // Guest → registered with an existing email.
        add_action('woocommerce_created_customer', static function (int $customer_id): void {
            self::handle_user_profile_update($customer_id);
        }, 20, 1);

        add_action('profile_update', static function (int $user_id): void {
            self::handle_user_profile_update($user_id);
        }, 20, 1);

        // WC fires this when an order is saved in admin.
        add_action('woocommerce_process_shop_order_meta', static function ($order_id): void {
            self::handle_payment_method_change((int) $order_id);
        }, 20, 1);

        // AC-39: render (and clear) the queued sync notices. They were written
        // by queue_admin_notice() but nothing ever displayed them, so real
        // failures (e.g. a dropped payment-method change) were silent.
        add_action('admin_notices', [self::class, 'render_admin_notices']);

        // T6.6 (2.7.0): aviso option-backed de facturas fallidas. A diferencia
        // del transient de 60 s, sobrevive a un fallo escrito por el cron.
        add_action('admin_notices', [self::class, 'render_invoice_failure_notice']);
    }

    /**
     * Create a credit note for a refund (partial or full).
     *
     * @param int $order_id  WC order ID.
     * @param int $refund_id WC refund ID (0 for a full-order refund).
     * @return array|\WP_Error
     */
    public static function handle_refund(int $order_id, int $refund_id = 0): array|\WP_Error
    {
        // Fast-path: the authority is Client::request() (Write_Gate). With the
        // kill switch active every write is blocked there, so skip the lock and
        // the API round-trip here. The marker mirrors the gate's shape so
        // callers that check Client::write_was_blocked() behave identically.
        // (The entity gate — `credit_note` => `alegra_connector_push_orders_enabled`
        // — decides the automatic refund in manual mode; the gate, not this
        // method, is the authority.)
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            self::log('warning', 'Refund sync skipped (kill switch active)', [
                'order_id'  => $order_id,
                'refund_id' => $refund_id,
            ]);
            return [
                'blocked_by_gate' => true,
                'reason'          => 'kill_switch',
                'entity'          => 'credit_note',
                'blocked'         => 'POST /credit-notes',
            ];
        }

        $lock_key = sprintf('alegra_sync_refund_lock_%d_%d', $order_id, $refund_id);

        $token = \Alegra\Connector\Sync\Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            return new \WP_Error(
                'refund_in_progress',
                __('Ya hay un reembolso en proceso para este pedido.', 'alegra-connector')
            );
        }

        try {
            if (!function_exists('wc_get_order')) {
                return new \WP_Error('woocommerce_missing', __('WooCommerce no está disponible', 'alegra-connector'));
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof \WC_Order) {
                return new \WP_Error('invalid_order', __('Pedido no válido', 'alegra-connector'));
            }

            // Idempotency: this specific refund was already credited.
            if ($refund_id > 0) {
                $meta_key = sprintf(self::REFUND_META_FMT, $refund_id);
                $existing = $order->get_meta($meta_key, true);
                if (!empty($existing)) {
                    self::log('info', 'Refund already processed', [
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'credit_note_id' => $existing,
                    ]);
                    return ['id' => (string) $existing, 'already_exists' => true];
                }
            }

            $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
            if ($invoice_id === '') {
                return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
            }

            // Cumulative cap: the sum of every refund must not exceed the order total.
            $refunded_total = 0.0;
            foreach ($order->get_refunds() as $refund) {
                if ($refund instanceof \WC_Order_Refund) {
                    $refunded_total += abs((float) $refund->get_total());
                }
            }
            if ($refunded_total > (float) $order->get_total()) {
                return new \WP_Error(
                    'refund_exceeds_total',
                    __('El total de los reembolsos supera el total del pedido', 'alegra-connector')
                );
            }

            if ($refund_id > 0) {
                $refund = wc_get_order($refund_id);
                $amount = $refund instanceof \WC_Order_Refund
                    ? abs((float) $refund->get_total())
                    : abs((float) $order->get_total() - (float) $order->get_total_refunded());
            } else {
                $amount = abs((float) $order->get_total() - (float) $order->get_total_refunded());
            }

            $orders = self::make_orders_handler();
            if ($orders === null) {
                return new \WP_Error('orders_handler_unavailable', __('No se pudo inicializar el manejador de pedidos.', 'alegra-connector'));
            }

            $result = $orders->create_credit_note_for_refund($order_id, $amount, '', $refund_id);
            if (is_wp_error($result)) {
                self::log('error', 'Failed to create credit note for refund', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'error'     => $result->get_error_message(),
                ]);
                $order->add_order_note(sprintf(
                    __('[Alegra] No se pudo crear la nota de crédito del reembolso: %s', 'alegra-connector'),
                    $result->get_error_message()
                ));
                return $result;
            }

            // Dry Run / Write Gate: create_credit_note_for_refund() already
            // added the honest note. Do not log a false "synced" success.
            if (API\Client::write_was_blocked($result)) {
                self::log('warning', 'Refund sync skipped (write blocked)', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'amount'    => $amount,
                ]);
                return $result;
            }

            $credit_note_id = (string) ($result['id'] ?? '');
            if ($refund_id > 0 && $credit_note_id !== '') {
                $order->update_meta_data(sprintf(self::REFUND_META_FMT, $refund_id), $credit_note_id);
                $order->save();
            }

            self::log('info', 'Refund synced to Alegra', [
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'credit_note_id' => $credit_note_id,
                'amount' => $amount,
            ]);

            return $result;
        } finally {
            \Alegra\Connector\Sync\Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * Migrate the Alegra billing fields from the user's latest order to user_meta.
     */
    public static function handle_user_profile_update(int $user_id): void
    {
        if ((string) get_user_meta($user_id, 'billing_alegra_identification', true) !== '') {
            return;
        }

        if (!class_exists(Billing_Fields::class)) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof \WP_User || $user->user_email === '') {
            return;
        }

        $order = self::find_migratable_order($user->user_email);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $migrated = 0;
        foreach (array_keys(Billing_Fields::CATALOG) as $key) {
            $meta_key = 'billing_alegra_' . $key;
            if ((string) get_user_meta($user_id, $meta_key, true) !== '') {
                continue;
            }

            $value = $order->get_meta('_' . $meta_key);
            if ($value === '' || $value === null) {
                continue;
            }

            update_user_meta($user_id, $meta_key, $value);
            $migrated++;
        }

        if ($migrated > 0) {
            self::log('info', 'Migrated billing fields from order to user', [
                'user_id' => $user_id,
                'order_id' => $order->get_id(),
                'migrated' => $migrated,
            ]);
        }
    }

    /**
     * Detect an admin payment-method change and push it to the Alegra invoice.
     */
    public static function handle_payment_method_change(int $order_id): void
    {
        // Fast-path: the authority is Client::request() (Write_Gate). With the
        // kill switch active the invoice update is blocked there; skip the
        // bookkeeping here. In manual mode (`push_orders_enabled = false`) the
        // entity gate blocks the automatic `PUT /invoices/{id}` too — a merchant
        // who wants it must trigger it explicitly (Write_Gate::run_explicit()).
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            self::log('warning', 'Payment method sync skipped (kill switch active)', [
                'order_id' => $order_id,
            ]);
            return;
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($invoice_id === '') {
            return;
        }

        // AC-41: the baseline is stored durably in order meta, NOT in a 300s
        // transient. With the transient, a payment-method change made more than
        // 5 minutes after the baseline save found it expired, re-baselined, and
        // silently dropped the change.
        $baseline_key = '_alegra_last_payment_method';
        $current = (string) $order->get_payment_method();
        $baseline = (string) $order->get_meta($baseline_key, true);

        // First observation: record the baseline and bail out.
        if ($baseline === '') {
            $order->update_meta_data($baseline_key, $current);
            $order->save();
            return;
        }

        if ($baseline === $current) {
            return;
        }

        $order->update_meta_data($baseline_key, $current);
        $order->save();

        if (self::is_multi_payment($order)) {
            self::queue_admin_notice(sprintf(
                __('Pedido #%d tiene pagos múltiples. El método de pago no se sincronizó.', 'alegra-connector'),
                $order_id
            ));
            return;
        }

        if (!self::is_billing_data_valid((int) $order->get_customer_id())) {
            self::queue_admin_notice(__('No se puede actualizar el método de pago en Alegra: faltan datos de facturación.', 'alegra-connector'));
            return;
        }

        $logger = self::make_logger();
        $api = new API\Client($logger);
        $orders = new Sync\Orders($api, $logger);
        $payment_method = $orders->getPaymentMethodForGateway($current);

        $result = $api->update_invoice($invoice_id, [
            'paymentMethod' => $payment_method,
        ]);

        if (is_wp_error($result)) {
            self::log('error', 'Failed to update payment method in Alegra', [
                'order_id' => $order_id,
                'error' => $result->get_error_message(),
            ]);
            return;
        }

        // Dry Run / Write Gate: the invoice was NOT updated. Do not claim it.
        if (API\Client::write_was_blocked($result)) {
            $order->add_order_note(__('Alegra (modo de prueba): el método de pago NO se actualizó. Desactiva el modo de prueba para sincronizar de verdad.', 'alegra-connector'));
            self::log('warning', 'Payment method update skipped (write blocked)', [
                'order_id' => $order_id,
                'payment_method' => $payment_method,
            ]);
            return;
        }

        $order->add_order_note(sprintf(
            __('Método de pago actualizado en Alegra (%s).', 'alegra-connector'),
            $payment_method
        ));

        self::log('info', 'Payment method updated in Alegra', [
            'order_id' => $order_id,
            'payment_method' => $payment_method,
        ]);
    }

    /**
     * Whether the order was paid with more than one payment method.
     */
    public static function is_multi_payment(\WC_Order $order): bool
    {
        if ((string) $order->get_meta('_split_payment') !== '') {
            return true;
        }

        if (method_exists($order, 'get_payment_tokens')) {
            return count($order->get_payment_tokens()) > 1;
        }

        return false;
    }

    /**
     * Whether the user has the critical Alegra billing data.
     */
    public static function is_billing_data_valid(int $user_id): bool
    {
        if ($user_id <= 0 || !class_exists(Billing_Fields::class)) {
            return false;
        }

        $values = [];
        foreach (array_keys(Billing_Fields::CATALOG) as $key) {
            $values[$key] = (string) get_user_meta($user_id, 'billing_alegra_' . $key, true);
        }

        return Billing_Fields::has_critical_data($values);
    }

    /**
     * Find the most recent order for an email that carries the Alegra billing meta.
     */
    private static function find_migratable_order(string $email): ?\WC_Order
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!is_array($orders)) {
            return null;
        }

        foreach ($orders as $order) {
            if ($order instanceof \WC_Order && (string) $order->get_meta('_billing_alegra_identification') !== '') {
                return $order;
            }
        }

        return null;
    }

    /**
     * Build the Orders sync handler, wiring the API client and logger.
     */
    private static function make_orders_handler(): ?Sync\Orders
    {
        if (!class_exists(Sync\Orders::class)) {
            return null;
        }

        $logger = self::make_logger();

        try {
            return new Sync\Orders(new API\Client($logger), $logger);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a logger instance, or null when unavailable.
     */
    private static function make_logger(): ?Logger\Logger
    {
        if (!class_exists(Logger\Logger::class)) {
            return null;
        }

        try {
            return new Logger\Logger();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Log defensively — logging must never break state sync.
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        $logger = self::make_logger();
        if ($logger === null || !method_exists($logger, $level)) {
            return;
        }

        try {
            $logger->{$level}($message, $context);
        } catch (\Throwable $e) {
            // Ignore.
        }
    }

    /**
     * Queue an admin notice via a short-lived transient.
     */
    private static function queue_admin_notice(string $message): void
    {
        $user_id = get_current_user_id();
        $key = 'alegra_sync_admin_notices' . ($user_id > 0 ? '_' . $user_id : '');
        $notices = get_transient($key);
        if (!is_array($notices)) {
            $notices = [];
        }
        $notices[] = $message;
        set_transient($key, $notices, 60);
    }

    /**
     * Render and clear the queued sync notices (AC-39).
     */
    public static function render_admin_notices(): void
    {
        $user_id = get_current_user_id();
        $key = 'alegra_sync_admin_notices' . ($user_id > 0 ? '_' . $user_id : '');
        $notices = get_transient($key);
        if (!is_array($notices) || empty($notices)) {
            return;
        }

        delete_transient($key);

        foreach ($notices as $notice) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html((string) $notice)
                . '</p></div>';
        }
    }

    /**
     * T6.6 / REQ-QUEUE-07: siembra el hash del conteo de fallos en una option
     * (autoload no) para que el aviso sobreviva a un fallo escrito por el cron
     * (no depende de un request de usuario). Delega en `Invoice_Queue` para no
     * divergir del hash canónico (`md5((string) $count)`, design §4.7/C4).
     */
    public static function queue_invoice_failure_notice(int $count): void
    {
        if ($count <= 0) {
            delete_option('alegra_connector_invoice_failures_hash');
            return;
        }
        update_option(
            'alegra_connector_invoice_failures_hash',
            \Alegra\Connector\Sync\Invoice_Queue::failure_hash($count),
            false
        );
    }

    /**
     * T6.6: render del aviso dismissible + dismiss per-user. Lee la option
     * (NUNCA ejecuta una query por página, NFR-04). El dismiss se persiste por
     * usuario (`_alegra_invoice_notice_dismissed_hash`) y no reaparece hasta
     * que cambie el hash.
     */
    public static function render_invoice_failure_notice(): void
    {
        $count = (int) get_option('alegra_connector_invoice_failures_count', 0);
        if ($count <= 0) {
            return;
        }
        $hash = (string) get_option('alegra_connector_invoice_failures_hash', '');
        if ($hash === '') {
            $hash = \Alegra\Connector\Sync\Invoice_Queue::failure_hash($count);
        }
        if ((string) get_user_meta(get_current_user_id(), '_alegra_invoice_notice_dismissed_hash', true) === $hash) {
            return;
        }

        $url = admin_url('admin.php?page=alegra-connector-invoice-queue');
        echo '<div class="notice notice-warning is-dismissible" id="alegra-invoice-failure-notice" data-alegra-notice-hash="' . esc_attr($hash) . '"><p>';
        echo esc_html(sprintf(
            /* translators: %d: number of invoices that failed to upload. */
            __('%d facturas no se pudieron subir a Alegra.', 'alegra-connector'),
            $count
        ));
        echo ' <a href="' . esc_url($url) . '">' . esc_html__('Ver lista', 'alegra-connector') . '</a>';
        echo '</p></div>';
    }
}
