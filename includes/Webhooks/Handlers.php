<?php

declare(strict_types=1);

namespace Alegra\Connector\Webhooks;

use Alegra\Connector\API;
use Alegra\Connector\Logger;
use Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class Handlers
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function process_event(string $event, array $data): void
    {
        if ($this->logger) $this->logger->info('Processing webhook event', ['event' => $event]);

        switch ($event) {
            case 'new-item':
            case 'edit-item':
                $this->handle_item_event($data);
                break;

            case 'delete-item':
                $this->handle_delete_item($data);
                break;

            case 'new-client':
            case 'edit-client':
                $this->handle_client_event($data);
                break;

            case 'delete-client':
                $this->handle_delete_client($data);
                break;

            case 'new-invoice':
            case 'edit-invoice':
                $this->handle_invoice_event($data);
                break;

            default:
                if ($this->logger) $this->logger->info('Unhandled webhook event', ['event' => $event]);
                break;
        }
    }

    private function handle_item_event(array $data): void
    {
        $item = $data['item'] ?? $data;
        $alegra_id = (int) ($item['id'] ?? 0);
        if ($alegra_id <= 0) return;

        $products = new Sync\Products($this->api, $this->logger);
        $result = $products->sync_single_item_by_alegra_id($alegra_id);

        if ($result === false) {
            if ($this->logger) $this->logger->error('Webhook: Failed to sync item', ['alegra_id' => $alegra_id]);
        } else {
            if ($this->logger) $this->logger->info('Webhook: Item synced', ['alegra_id' => $alegra_id, 'result' => $result]);
        }
    }

    private function handle_delete_item(array $data): void
    {
        global $wpdb;
        $item = $data['item'] ?? $data;
        $alegra_id = (int) ($item['id'] ?? 0);
        if ($alegra_id <= 0) return;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_item_id' AND meta_value = %d LIMIT 1",
            $alegra_id
        ));

        if ($product_id) {
            delete_post_meta((int) $product_id, '_alegra_item_id');
            if ($this->logger) $this->logger->info('Webhook: Item unlinked from product', ['alegra_id' => $alegra_id, 'product_id' => (int) $product_id]);
        }
    }

    private function handle_client_event(array $data): void
    {
        $contact = $data['client'] ?? $data;
        $alegra_id = (int) ($contact['id'] ?? 0);
        if ($alegra_id <= 0) return;

        $customers = new Sync\Customers($this->api, $this->logger);
        $result = $customers->sync_single_contact_by_alegra_id($alegra_id);

        if ($result === false) {
            if ($this->logger) $this->logger->error('Webhook: Failed to sync contact', ['alegra_id' => $alegra_id]);
        } else {
            if ($this->logger) $this->logger->info('Webhook: Contact synced', ['alegra_id' => $alegra_id, 'result' => $result]);
        }
    }

    private function handle_delete_client(array $data): void
    {
        $contact = $data['client'] ?? $data;
        $alegra_id = (int) ($contact['id'] ?? 0);
        if ($alegra_id <= 0) return;

        $user_args = [
            'meta_key' => 'alegra_contact_id',
            'meta_value' => $alegra_id,
            'number' => 1,
            'fields' => 'ID',
        ];
        $users = get_users($user_args);

        if (!empty($users)) {
            delete_user_meta((int) $users[0], 'alegra_contact_id');
            if ($this->logger) $this->logger->info('Webhook: Contact unlinked from user', ['alegra_id' => $alegra_id, 'user_id' => (int) $users[0]]);
        }
    }

    private function handle_invoice_event(array $data): void
    {
        $invoice = $data['invoice'] ?? $data;
        $alegra_invoice_id = (int) ($invoice['id'] ?? 0);
        if ($alegra_invoice_id <= 0) return;

        $status = $invoice['status'] ?? '';
        $balance = (float) ($invoice['balance'] ?? 0);
        $should_complete = get_option('alegra_connector_auto_complete_order', true);

        if ($status === 'paid' && $balance <= 0 && $should_complete) {
            global $wpdb;
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_invoice_id' AND meta_value = %d LIMIT 1",
                $alegra_invoice_id
            ));

            if ($order_id) {
                $order = wc_get_order((int) $order_id);
                if ($order && $order->get_status() !== 'completed') {
                    $order->add_order_note(sprintf(
                        __('[Alegra Webhook] Factura #%s pagada. Pedido completado automaticamente.', 'alegra-connector'),
                        $invoice['number'] ?? $alegra_invoice_id
                    ));
                    $order->update_status('completed');
                    if ($this->logger) $this->logger->info('Webhook: Order completed from paid invoice', [
                        'order_id' => $order_id,
                        'invoice_id' => $alegra_invoice_id,
                    ]);
                }
            }
        }
    }
}
