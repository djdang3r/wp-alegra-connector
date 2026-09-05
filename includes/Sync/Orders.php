<?php
/**
 * Orders Sync Handler
 *
 * Converts WooCommerce orders to Alegra invoices with:
 * - Inventory sync on order completion
 * - Credit notes for refunds
 * - Number templates for proper invoice numbering
 * - Payment terms for due dates
 */

declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\HPOS;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Orders
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function create_invoice(\WC_Order $order): array|\WP_Error
    {
        $order_id = (int) $order->get_id();
        $lock_key = 'alegra_invoice_lock_' . $order_id;

        // Anti-duplication mutex: prevent two concurrent requests creating duplicate invoices.
        // Transient is atomic via MySQL row lock (works in PHP-FPM multi-worker).
        if (get_transient($lock_key)) {
            $this->logger->warning('Invoice creation already in progress for this order', [
                'order_id' => $order_id,
            ]);
            return new \WP_Error(
                'invoice_in_progress',
                __('Ya hay una creacion de factura en curso para este pedido. Espera unos segundos.', 'alegra-connector')
            );
        }
        set_transient($lock_key, 1, 30); // 30s lock

        try {
            $alegra_id = (int) get_post_meta($order_id, '_alegra_invoice_id', true);

            if ($alegra_id > 0) {
                $this->logger->info('Order already has Alegra invoice', [
                    'order_id' => $order_id,
                    'alegra_id' => $alegra_id,
                ]);
                return ['id' => $alegra_id, 'already_exists' => true];
            }

            $data = $this->prepare_invoice_data($order);

            $result = $this->api->create_invoice($data);

            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($order_id, '_alegra_invoice_id', (int) $result['id']);
                $invoice_number = $result['number'] ?? $result['id'];
                if (isset($result['numberTemplate']['fullNumber'])) {
                    $invoice_number = $result['numberTemplate']['fullNumber'];
                }
                update_post_meta($order_id, '_alegra_invoice_number', (string) $invoice_number);
                // Add note to WC order
                $order->add_order_note(sprintf(
                    __('Factura Alegra #%s creada.', 'alegra-connector'),
                    $invoice_number
                ));
                $this->logger->info('Invoice created in Alegra', [
                    'order_id' => $order_id,
                    'invoice_id' => $result['id'],
                    'number' => $result['number'] ?? 'N/A',
                ]);
            }

            return $result;
        } finally {
            // Always release lock, even on error
            delete_transient($lock_key);
        }
    }

    public function create_invoice_with_payment(\WC_Order $order): array|\WP_Error
    {
        $invoice_result = $this->create_invoice($order);

        if (is_wp_error($invoice_result)) {
            return $invoice_result;
        }

        $existing_payment_id = (int) get_post_meta($order->get_id(), '_alegra_payment_id', true);
        if ($existing_payment_id > 0) {
            return $invoice_result;
        }

        $payment_data = $this->prepare_payment_data($order, (int) $invoice_result['id']);

        if (!empty($payment_data)) {
            $payment_result = $this->api->create_payment($payment_data);
            if (!is_wp_error($payment_result)) {
                update_post_meta($order->get_id(), '_alegra_payment_id', (int) ($payment_result['id'] ?? 0));
                if (!empty($payment_result['number'])) {
                    update_post_meta($order->get_id(), '_alegra_payment_number', $payment_result['number']);
                }
                $order->add_order_note(sprintf(
                    __('Pago Alegra #%s registrado.', 'alegra-connector'),
                    $payment_result['number'] ?? $payment_result['id'] ?? ''
                ));
                $this->logger->info('Payment recorded in Alegra', [
                    'order_id' => $order->get_id(),
                    'payment_id' => $payment_result['id'] ?? 'unknown',
                ]);
            }
        }

        return $invoice_result;
    }

    public function create_credit_note(\WC_Order $order): array|\WP_Error
    {
        $alegra_invoice_id = (int) get_post_meta($order->get_id(), '_alegra_invoice_id', true);

        if ($alegra_invoice_id <= 0) {
            return new \WP_Error('no_invoice', 'Order has no linked Alegra invoice');
        }

        $refunds = $order->get_refunds();
        if (!empty($refunds)) {
            $items = [];
            foreach ($refunds as $refund) {
                $refund_items = $this->prepare_credit_note_items_from_refund($refund);
                $items = array_merge($items, $refund_items);
            }
            $observation = sprintf(
                __('Reembolso parcial de pedido #%d - WooCommerce', 'alegra-connector'),
                $order->get_id()
            );
        } else {
            $items = $this->prepare_credit_note_items($order);
            $observation = sprintf(
                __('Reembolso total de pedido #%d - WooCommerce', 'alegra-connector'),
                $order->get_id()
            );
        }

        $data = [
            'invoice' => ['id' => $alegra_invoice_id],
            'date' => date('Y-m-d'),
            'dueDate' => date('Y-m-d'),
            'observations' => $observation,
            'items' => $items,
        ];

        $result = $this->api->create_credit_note($data);

        if (!is_wp_error($result)) {
            $cn_id = $result['id'] ?? 0;
            update_post_meta($order->get_id(), '_alegra_credit_note_id', (int) $cn_id);
            $this->logger->info('Credit note created in Alegra', [
                'order_id' => $order->get_id(),
                'credit_note_id' => $cn_id,
            ]);
        }

        return $result;
    }

    public function void_invoice(\WC_Order $order): array|\WP_Error
    {
        $alegra_invoice_id = (int) get_post_meta($order->get_id(), '_alegra_invoice_id', true);

        if ($alegra_invoice_id <= 0) {
            return new \WP_Error('no_invoice', 'Order has no linked Alegra invoice');
        }

        $result = $this->api->void_invoice(
            $alegra_invoice_id,
            sprintf('Cancelled via WooCommerce - Order #%d', $order->get_id())
        );

        if (!is_wp_error($result)) {
            $this->logger->info('Invoice voided in Alegra', [
                'order_id' => $order->get_id(),
                'invoice_id' => $alegra_invoice_id,
            ]);

        }

        return $result;
    }

    public function sync_recent(int $days = 7): array|\WP_Error
    {
        $result = ['synced' => 0, 'errors' => 0];

        $args = [
            'limit' => 100,
            'status' => ['processing', 'completed'],
            'date_after' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
        ];

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            $alegra_id = (int) get_post_meta($order->get_id(), '_alegra_invoice_id', true);
            if ($alegra_id > 0) {
                continue;
            }

            $sync_result = $this->create_invoice($order);
            if (is_wp_error($sync_result)) {
                $result['errors']++;
            } else {
                $result['synced']++;
            }
        }

        $this->logger->info('Orders sync completed', $result);

        return $result;
    }

    private function prepare_invoice_data(\WC_Order $order): array
    {
        // Sync customer first if needed
        $customer_alegra_id = $this->ensure_customer_synced($order);

        $client_data = $this->build_client_data($order, $customer_alegra_id);

        // Country must be resolved BEFORE being used (PHP 8+ safe + correct logic).
        $country = (string) $order->get_billing_country();

        $data = [
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : date('Y-m-d'),
            'dueDate' => $this->calculate_due_date($order),
            'client' => $client_data,
            'items' => $this->prepare_invoice_items($order),
            'currency' => ['code' => $order->get_currency() ?: get_option('alegra_connector_currency', 'COP')],
            'observations' => sprintf(__('Pedido WooCommerce #%d', 'alegra-connector'), $order->get_id()),
            'status' => 'open',
        ];

        // Payment method mapping
        $payment = $this->map_payment_method($order);
        if ($payment) {
            // paymentForm is only for Colombia; other countries just use paymentMethod
            $data['paymentMethod'] = $payment['method'];
            if ($country === 'CO') {
                $data['paymentForm'] = $payment['form'];
            }
        }

        // Country-specific fields
        if ($country === 'CO') {
            $data['type'] = 'NATIONAL';
        }

        // Number template (auto-detect preferred)
        $template = $this->get_preferred_number_template();
        if ($template) {
            $data['numberTemplate'] = ['id' => (string) $template];
        }

        // Payment term
        $term = $this->get_default_payment_term();
        if ($term > 0) {
            $data['term'] = $term;
        }

        // Warehouse
        $warehouse = (int) get_option('alegra_connector_warehouse_id', 0);
        if ($warehouse > 0 && get_option('alegra_connector_warehouse_enabled')) {
            $data['warehouse'] = $warehouse;
        }

        return $data;
    }

    private function ensure_customer_synced(\WC_Order $order): int
    {
        $customer = $order->get_user();

        // Registered user: check if already linked first
        if ($customer) {
            $alegra_id = (int) get_user_meta($customer->ID, 'alegra_contact_id', true);
            if ($alegra_id > 0) {
                return $alegra_id;
            }

            // Search by email before creating (prevents duplicates)
            $email = $customer->user_email;
            if (!empty($email)) {
                $contacts = $this->api->get_contacts(['email' => $email, 'limit' => 1]);
                if (!is_wp_error($contacts) && !empty($contacts) && isset($contacts[0]['id'])) {
                    $existing_id = (int) $contacts[0]['id'];
                    update_user_meta($customer->ID, 'alegra_contact_id', $existing_id);
                    $this->logger->info('Linked existing Alegra contact by email (order sync)', [
                        'user_id' => $customer->ID,
                        'alegra_id' => $existing_id,
                    ]);
                    return $existing_id;
                }
            }

            // Search by NIT/identification before creating
            $nit = get_user_meta($customer->ID, 'billing_nit', true);
            if (!empty($nit)) {
                $contacts = $this->api->get_contacts(['identification' => $nit, 'limit' => 1]);
                if (!is_wp_error($contacts) && !empty($contacts) && isset($contacts[0]['id'])) {
                    $existing_id = (int) $contacts[0]['id'];
                    update_user_meta($customer->ID, 'alegra_contact_id', $existing_id);
                    $this->logger->info('Linked existing Alegra contact by NIT (order sync)', [
                        'user_id' => $customer->ID,
                        'alegra_id' => $existing_id,
                    ]);
                    return $existing_id;
                }
            }

            // No existing contact found, create new one in Alegra
            $customers_sync = new Customers($this->api, $this->logger);
            $sync_result = $customers_sync->sync_to_alegra($customer);
            if (!is_wp_error($sync_result) && isset($sync_result['id'])) {
                return (int) $sync_result['id'];
            }
            return 0;
        }

        // Guest checkout: search by email first, then by NIT, then create
        $email = $order->get_billing_email();
        if (empty($email)) {
            return 0;
        }

        // Search by email first
        $contacts = $this->api->get_contacts(['email' => $email, 'limit' => 1]);
        if (!is_wp_error($contacts) && !empty($contacts) && isset($contacts[0]['id'])) {
            $this->logger->info('Found existing Alegra contact by email (guest order)', [
                'order_id' => $order->get_id(),
                'alegra_id' => (int) $contacts[0]['id'],
            ]);
            return (int) $contacts[0]['id'];
        }

        // Search by NIT/identification as fallback
        $nit = $order->get_meta('billing_nit');
        if (!empty($nit)) {
            $contacts = $this->api->get_contacts(['identification' => $nit, 'limit' => 1]);
            if (!is_wp_error($contacts) && !empty($contacts) && isset($contacts[0]['id'])) {
                $this->logger->info('Found existing Alegra contact by NIT (guest order)', [
                    'order_id' => $order->get_id(),
                    'alegra_id' => (int) $contacts[0]['id'],
                ]);
                return (int) $contacts[0]['id'];
            }
        }

        // No existing contact found, create new one in Alegra
        $contact_data = [
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $email,
            'email' => $email,
            'phonePrimary' => $order->get_billing_phone() ?: '',
            'address' => [
                'address' => $order->get_billing_address_1() ?: '',
                'city' => $order->get_billing_city() ?: '',
            ],
            'type' => 'client',
        ];

        if (!empty($nit)) {
            $contact_data['identification'] = $nit;
        }

        $result = $this->api->create_contact($contact_data);
        if (!is_wp_error($result) && isset($result['id'])) {
            $this->logger->info('New Alegra contact created from guest order', [
                'order_id' => $order->get_id(),
                'alegra_id' => (int) $result['id'],
            ]);
            return (int) $result['id'];
        }

        return 0;
    }

    private function build_client_data(\WC_Order $order, int $customer_alegra_id): array
    {
        if ($customer_alegra_id > 0) {
            return ['id' => $customer_alegra_id];
        }

        return [
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $order->get_billing_email(),
            'email' => $order->get_billing_email(),
            'phonePrimary' => $order->get_billing_phone(),
            'address' => [
                'address' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
            ],
        ];
    }

    private function calculate_due_date(\WC_Order $order): string
    {
        // Try to get payment term from Alegra
        $term_id = (int) get_option('alegra_connector_payment_term_id', 0);
        if ($term_id > 0) {
            $term_data = $this->api->get_term($term_id);
            if (!is_wp_error($term_data) && isset($term_data['days'])) {
                $days = (int) $term_data['days'];
                return date('Y-m-d', strtotime("+{$days} days"));
            }
        }

        return date('Y-m-d', strtotime('+15 days'));
    }

    private function prepare_invoice_items(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $price = (float) ($item_obj->get_subtotal() / max(1, $item_obj->get_quantity()));
            $quantity = (int) $item_obj->get_quantity();

            $item_data = [
                'name' => $item_obj->get_name(),
                'price' => $price,
                'quantity' => $quantity,
            ];

            if ($alegra_item_id > 0) {
                $item_data['id'] = $alegra_item_id;
            }

            $tax_ids = $this->map_item_taxes($item_obj);
            if (!empty($tax_ids)) {
                $item_data['tax'] = [];
                foreach ($tax_ids as $tid) {
                    $item_data['tax'][] = ['id' => $tid];
                }
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function resolve_item_alegra_id(int $product_id, int $variation_id): int
    {
        if ($variation_id > 0) {
            $alegra_id = (int) get_post_meta($variation_id, '_alegra_item_id', true);
            if ($alegra_id > 0) return $alegra_id;
        }

        if ($product_id > 0) {
            $alegra_id = (int) get_post_meta($product_id, '_alegra_item_id', true);
            if ($alegra_id > 0) return $alegra_id;

            $sku = get_post_meta($product_id, '_sku', true);
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $found_id = (int) $items[0]['id'];
                    update_post_meta($product_id, '_alegra_item_id', $found_id);
                    return $found_id;
                }
            }
        }

        return 0;
    }

    private function prepare_payment_data(\WC_Order $order, int $invoice_id): array
    {
        $account_id = (int) get_option('alegra_connector_payment_account_id', 0);
        if ($account_id <= 0) {
            return [];
        }

        return [
            'date' => date('Y-m-d'),
            'bankAccount' => ['id' => $account_id],
            'invoices' => [
                [
                    'id' => $invoice_id,
                    'amount' => (float) $order->get_total(),
                ],
            ],
            'paymentMethod' => $this->get_payment_method_code($order),
        ];
    }

    private function prepare_credit_note_items(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $item_data = [
                'name' => $item_obj->get_name(),
                'quantity' => (int) $item_obj->get_quantity(),
                'price' => (float) ($item_obj->get_subtotal() / max(1, $item_obj->get_quantity())),
            ];

            if ($alegra_item_id > 0) {
                $item_data['id'] = $alegra_item_id;
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function prepare_credit_note_items_from_refund(\WC_Order_Refund $refund): array
    {
        $items = [];

        foreach ($refund->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();
            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            $refund_qty = abs((int) $item_obj->get_quantity());
            $refund_total = abs((float) $item_obj->get_total());
            $refund_price = $refund_qty > 0 ? $refund_total / $refund_qty : 0;

            $item_data = [
                'name' => $item_obj->get_name(),
                'quantity' => $refund_qty,
                'price' => $refund_price,
            ];

            if ($alegra_item_id > 0) {
                $item_data['id'] = $alegra_item_id;
            }

            $items[] = $item_data;
        }

        return $items;
    }

    private function get_preferred_number_template(): ?int
    {
        $templates = $this->api->get_number_templates();
        if (is_wp_error($templates) || empty($templates)) {
            return null;
        }

        foreach ($templates as $tpl) {
            // Prefer electronic invoice template for Colombia
            if (isset($tpl['type']) && $tpl['type'] === 'electronic') {
                return (int) $tpl['id'];
            }
        }

        // Fallback to first template
        return isset($templates[0]['id']) ? (int) $templates[0]['id'] : null;
    }

    private function get_default_payment_term(): int
    {
        return (int) get_option('alegra_connector_payment_term_id', 0);
    }

    private function map_payment_method(\WC_Order $order): ?array
    {
        $method = $order->get_payment_method();

        $mappings = $this->get_payment_gateway_mappings();

        foreach ($mappings as $slug => $data) {
            if (strpos($method, $slug) !== false) {
                return $data;
            }
        }

        return ['form' => 'CREDIT', 'method' => 'transfer'];
    }

    private function get_payment_method_code(\WC_Order $order): string
    {
        $method = $order->get_payment_method();
        $mappings = $this->get_payment_gateway_code_mappings();

        foreach ($mappings as $slug => $code) {
            if (strpos($method, $slug) !== false) {
                return $code;
            }
        }

        return 'transfer';
    }

    /**
     * Maps WooCommerce payment gateway slugs to Alegra payment methods.
     * Covers all major gateways in Latin America.
     */
    public function getPaymentMethodForGateway(string $gateway_slug): string
    {
        $codes = $this->get_payment_gateway_code_mappings();

        foreach ($codes as $slug => $code) {
            if (strpos($gateway_slug, $slug) !== false) {
                return $code;
            }
        }

        return 'cash';
    }

    /**
     * Payment gateway → Alegra form/method mapping
     */
    private function get_payment_gateway_mappings(): array
    {
        return [
            'bacs' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'cod' => ['form' => 'CASH', 'method' => 'cash'],
            'cheque' => ['form' => 'CREDIT', 'method' => 'check'],
            'paypal' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'stripe' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'mercadopago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woocommerce-mercado-pago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woo-mercado-pago' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'payu' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'epayco' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woocommerce_payments' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'square' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'razorpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'payfast' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'dlocal' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'ebanx' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'woo-wompi' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'openpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'clip' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'culqi' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'pse' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'efecty' => ['form' => 'CASH', 'method' => 'cash'],
            'baloto' => ['form' => 'CASH', 'method' => 'cash'],
            'oxxo' => ['form' => 'CASH', 'method' => 'cash'],
            'spei' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'webpay' => ['form' => 'CREDIT', 'method' => 'credit-card'],
            'flow' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'khipu' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'mach' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'servipag' => ['form' => 'CASH', 'method' => 'cash'],
            'safetypay' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'multicaja' => ['form' => 'CASH', 'method' => 'cash'],
            'billetera' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'transferencia' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'nequi' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'daviplata' => ['form' => 'CREDIT', 'method' => 'transfer'],
            'bancolombia' => ['form' => 'CREDIT', 'method' => 'transfer'],
        ];
    }

    /**
     * Payment gateway → Alegra payment method code
     */
    private function get_payment_gateway_code_mappings(): array
    {
        return [
            'bacs' => 'transfer',
            'cod' => 'cash',
            'cheque' => 'check',
            'paypal' => 'transfer',
            'stripe' => 'credit-card',
            'mercadopago' => 'credit-card',
            'woocommerce-mercado-pago' => 'credit-card',
            'woo-mercado-pago' => 'credit-card',
            'payu' => 'credit-card',
            'epayco' => 'credit-card',
            'woocommerce_payments' => 'credit-card',
            'square' => 'credit-card',
            'razorpay' => 'credit-card',
            'payfast' => 'credit-card',
            'dlocal' => 'credit-card',
            'ebanx' => 'credit-card',
            'wompi' => 'credit-card',
            'openpay' => 'credit-card',
            'clip' => 'credit-card',
            'culqi' => 'credit-card',
            'pse' => 'transfer',
            'efecty' => 'cash',
            'baloto' => 'cash',
            'oxxo' => 'cash',
            'spei' => 'transfer',
            'webpay' => 'credit-card',
            'flow' => 'transfer',
            'khipu' => 'transfer',
            'mach' => 'transfer',
            'servipag' => 'cash',
            'safetypay' => 'transfer',
            'multicaja' => 'cash',
            'nequi' => 'transfer',
            'daviplata' => 'transfer',
            'bancolombia' => 'transfer',
        ];
    }

    private function map_item_taxes(\WC_Order_Item $item): array
    {
        $tax_data = $item->get_taxes();
        if (empty($tax_data['total'])) {
            return [];
        }

        $tax_mapping = (array) get_option('alegra_connector_tax_mapping', []);
        $ids = [];

        foreach (array_keys($tax_data['total']) as $rate_id) {
            if (isset($tax_mapping[$rate_id])) {
                $mapped = (int) $tax_mapping[$rate_id];
                if ($mapped > 0) {
                    $ids[$mapped] = $mapped;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Poll Alegra for invoice status changes and update linked WC orders.
     * Replaces the webhook-based approach for Alegra → WC sync.
     * Called by the cron sync job, respecting auto_complete_order setting.
     */
    public function poll_invoice_statuses(): array
    {
        $result = ['checked' => 0, 'completed' => 0, 'errors' => 0];

        $orders = wc_get_orders([
            'limit' => 50,
            'status' => ['processing', 'pending', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $order_ids = [];
        foreach ($orders as $o) {
            $alegra_id = (int) get_post_meta($o->get_id(), '_alegra_invoice_id', true);
            if ($alegra_id > 0) {
                $order_ids[] = $o->get_id();
            }
        }

        $should_complete = get_option('alegra_connector_auto_complete_order', true);

        foreach ($order_ids as $order_id) {
            $result['checked']++;
            $invoice_id = (int) get_post_meta($order_id, '_alegra_invoice_id', true);
            if ($invoice_id <= 0) continue;

            $invoice = $this->api->get_invoice($invoice_id);
            if (is_wp_error($invoice)) {
                $result['errors']++;
                continue;
            }

            $status = $invoice['status'] ?? '';
            $balance = (float) ($invoice['balance'] ?? 0);

            if ($status === 'paid' && $balance <= 0 && $should_complete) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_status() !== 'completed') {
                    $order->add_order_note(sprintf(
                        __('[Alegra] Factura #%s pagada. Pedido completado automaticamente.', 'alegra-connector'),
                        $invoice['numberTemplate']['fullNumber'] ?? $invoice_id
                    ));
                    $order->update_status('completed');
                    $result['completed']++;
                }
            }
        }

        return $result;
    }
}