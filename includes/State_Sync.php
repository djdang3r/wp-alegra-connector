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
        $lock_key = sprintf('alegra_sync_refund_lock_%d_%d', $order_id, $refund_id);

        if (get_transient($lock_key)) {
            return new \WP_Error(
                'refund_in_progress',
                __('Ya hay un reembolso en proceso para este pedido.', 'alegra-connector')
            );
        }
        set_transient($lock_key, 1, 30);

        try {
            // Idempotency: this specific refund was already credited.
            if ($refund_id > 0) {
                $meta_key = sprintf(self::REFUND_META_FMT, $refund_id);
                $existing = get_post_meta($order_id, $meta_key, true);
                if (!empty($existing)) {
                    self::log('info', 'Refund already processed', [
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'credit_note_id' => $existing,
                    ]);
                    return ['id' => (string) $existing, 'already_exists' => true];
                }
            }

            if (!function_exists('wc_get_order')) {
                return new \WP_Error('woocommerce_missing', 'WooCommerce no está disponible');
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof \WC_Order) {
                return new \WP_Error('invalid_order', 'Pedido no válido');
            }

            $invoice_id = (string) get_post_meta($order_id, '_alegra_invoice_id', true);
            if ($invoice_id === '') {
                return new \WP_Error('no_invoice', 'El pedido no tiene una factura de Alegra vinculada');
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
                    'El total de los reembolsos supera el total del pedido'
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
            if ($orders === null || !method_exists($orders, 'create_credit_note_for_refund')) {
                return new \WP_Error('not_implemented', 'Pending Phase 2');
            }

            $result = $orders->create_credit_note_for_refund($order, $refund_id, $amount);
            if (is_wp_error($result)) {
                return $result;
            }

            $credit_note_id = (string) ($result['id'] ?? '');
            if ($refund_id > 0 && $credit_note_id !== '') {
                update_post_meta($order_id, sprintf(self::REFUND_META_FMT, $refund_id), $credit_note_id);
            }

            if ($credit_note_id !== '') {
                $order->add_order_note(sprintf(
                    __('Nota de crédito Alegra #%s creada por el reembolso.', 'alegra-connector'),
                    $credit_note_id
                ));
            }

            self::log('info', 'Refund synced to Alegra', [
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'credit_note_id' => $credit_note_id,
                'amount' => $amount,
            ]);

            return $result;
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Migrate the Alegra billing fields from the user's latest order to user_meta.
     */
    public static function handle_user_profile_update(int $user_id): void
    {
        if ((string) get_user_meta($user_id, 'billing_alegra_kindofperson', true) !== '') {
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
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ((string) get_post_meta($order_id, '_alegra_invoice_id', true) === '') {
            return;
        }

        $snapshot_key = 'alegra_order_pm_' . $order_id;
        $current = (string) $order->get_payment_method();
        $snapshot = get_transient($snapshot_key);

        // First save: record the baseline and bail out.
        if ($snapshot === false) {
            set_transient($snapshot_key, $current, self::DEBOUNCE_SECONDS);
            return;
        }

        if ((string) $snapshot === $current) {
            return;
        }

        set_transient($snapshot_key, $current, self::DEBOUNCE_SECONDS);

        if (self::is_multi_payment($order)) {
            self::queue_admin_notice(sprintf(
                'Pedido #%d tiene pagos múltiples. El método de pago no se sincronizó.',
                $order_id
            ));
            return;
        }

        if (!self::is_billing_data_valid((int) $order->get_customer_id())) {
            self::queue_admin_notice('No se puede actualizar el método de pago en Alegra: faltan datos de facturación.');
            return;
        }

        $logger = self::make_logger();
        $api = new API\Client($logger);
        $orders = new Sync\Orders($api, $logger);
        $payment_method = $orders->getPaymentMethodForGateway($current);

        $result = $api->update_invoice((string) get_post_meta($order_id, '_alegra_invoice_id', true), [
            'paymentMethod' => $payment_method,
        ]);

        if (is_wp_error($result)) {
            self::log('error', 'Failed to update payment method in Alegra', [
                'order_id' => $order_id,
                'error' => $result->get_error_message(),
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
            if ($order instanceof \WC_Order && (string) $order->get_meta('_billing_alegra_kindofperson') !== '') {
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
}
