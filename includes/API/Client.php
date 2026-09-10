<?php
/**
 * Alegra API Client
 *
 * @package Alegra\Connector\API
 */

declare(strict_types=1);

namespace Alegra\Connector\API;

use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Client
{
    private ?Logger\Logger $logger;
    private string $email;
    private string $token;
    private string $base_url;

    public function __construct(?Logger\Logger $logger = null)
    {
        $this->logger = $logger;
        $this->reload_credentials();
        $configured_url = (string) get_option('alegra_connector_api_url', '');
        $this->base_url = !empty($configured_url) ? rtrim($configured_url, '/') : 'https://api.alegra.com/api/v1';
    }

    /**
     * Reload credentials from DB
     */
    public function reload_credentials(): void
    {
        $this->email = (string) get_option('alegra_connector_email', '');
        $this->token = (string) get_option('alegra_connector_token', '');
    }

    public function set_credentials(string $email, string $token): void
    {
        $this->email = $email;
        $this->token = $token;
    }

    private function get_auth_header(): string
    {
        if (empty($this->email) || empty($this->token)) {
            return 'Basic ';
        }
        $credentials = $this->email . ':' . $this->token;
        return 'Basic ' . base64_encode($credentials);
    }

    public function test_connection(string $email = '', string $token = ''): array|\WP_Error
    {
        if (!empty($email) && !empty($token)) {
            $this->set_credentials($email, $token);
        }

        $response = $this->request('GET', '/company', [], ['timeout' => 10]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Quick endpoint diagnostics
        $items_count = 0;
        $contacts_count = 0;
        $invoices_count = 0;
        $diagnostics = [];

        $items = $this->request('GET', '/items', ['limit' => 1], ['timeout' => 8]);
        if (!is_wp_error($items)) {
            $items_count = is_array($items) ? count($items) : 0;
            $diagnostics[] = 'items: ' . ($items_count > 0 ? 'OK' : 'vacio');
        } else {
            $diagnostics[] = 'items: ' . $items->get_error_message();
        }

        $contacts = $this->request('GET', '/contacts', ['limit' => 1], ['timeout' => 8]);
        if (!is_wp_error($contacts)) {
            $contacts_count = is_array($contacts) ? count($contacts) : 0;
            $diagnostics[] = 'contacts: ' . ($contacts_count > 0 ? 'OK' : 'vacio');
        } else {
            $diagnostics[] = 'contacts: ' . $contacts->get_error_message();
        }

        return [
            'company' => $response['name'] ?? 'Unknown',
            'country' => $response['country'] ?? '',
            'email' => $response['email'] ?? '',
            'items_count' => $items_count,
            'contacts_count' => $contacts_count,
            'diagnostics' => $diagnostics,
        ];
    }

    public function request(string $method, string $endpoint, array $data = [], array $args = []): array|\WP_Error
    {
        // Throttle: wait if we're at the rate limit
        if (!$this->throttle()) {
            if ($this->logger) $this->logger->warning('API rate limit hard-reached', [
                'endpoint' => $endpoint,
                'limit' => self::RATE_LIMIT_PER_MIN,
            ]);
            return new \WP_Error('rate_limited', sprintf(
                __('Alegra rate limit reached (%d req/min). Reintentaremos automaticamente.', 'alegra-connector'),
                self::RATE_LIMIT_PER_MIN
            ));
        }

        $url = $this->base_url . $endpoint;

        $defaults = [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => $this->get_auth_header(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
            'redirection' => 0,
        ];

        if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return new \WP_Error('json_error', 'Failed to encode request data');
            }
            $defaults['body'] = $encoded;
        }

        $args = wp_parse_args($args, $defaults);

        if (defined('WP_DEBUG') && WP_DEBUG && $this->logger) {
            $this->logger->debug('API Request', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
            ]);
        }

        // Exponential backoff retry: 1s, 2s, 4s, 8s, 16s (max 5 attempts)
        $max_retries = 5;
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                if ($attempt < ($max_retries - 1) && $this->is_retryable_wp_error($response)) {
                    $delay = (int) min(2 ** $attempt, 16);
                    if ($this->logger) $this->logger->warning('API request error, backing off', [
                        'attempt' => $attempt + 1,
                        'delay_seconds' => $delay,
                        'error' => $response->get_error_message(),
                    ]);
                    sleep($delay);
                    continue;
                }
                if ($this->logger) $this->logger->error('API Request failed', ['error' => $response->get_error_message()]);
                $this->increment_rate_limit();
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // Only increment rate limit on SUCCESSFUL requests (not on errors)
            // Otherwise we hit the limit just by having errors!
            if ($code < 400) {
                $this->increment_rate_limit();
            }

            // Retry on 5xx and 429 with exponential backoff
            if (($code >= 500 || $code === 429) && $attempt < ($max_retries - 1)) {
                $delay = (int) min(2 ** $attempt, 16);
                // Honor Retry-After header if present
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after && is_numeric($retry_after)) {
                    $delay = min((int) $retry_after, 30);
                }
                if ($this->logger) $this->logger->warning('API server error / rate-limited, backing off', [
                    'code' => $code,
                    'attempt' => $attempt + 1,
                    'delay_seconds' => $delay,
                ]);
                sleep($delay);
                continue;
            }

            if ($code >= 400) {
                $error_data = json_decode($body, true);
                $message = is_array($error_data) ? ($error_data['message'] ?? $error_data['code'] ?? 'API Error') : 'API Error';
                if ($this->logger) $this->logger->error('API Error response', ['code' => $code, 'message' => $message]);
                return new \WP_Error('api_error', $message, ['code' => $code, 'response' => $error_data]);
            }

            $decoded = json_decode($body, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($this->logger) $this->logger->error('API Response JSON decode error', ['error' => json_last_error_msg()]);
                return new \WP_Error('json_error', 'Invalid JSON response from API');
            }

            return $decoded ?? [];
        }

        return new \WP_Error('max_retries', 'Maximum API retries exceeded (5 attempts)');
    }

    /**
     * Check if a WP_Error is retryable (timeout, connection issues)
     */
    private function is_retryable_wp_error(\WP_Error $error): bool
    {
        $code = $error->get_error_code();
        $retryable = ['http_request_failed', 'http_request_timeout'];
        return in_array($code, $retryable, true);
    }

    public function get(string $endpoint, array $params = []): array|\WP_Error
    {
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->request('GET', $endpoint);
    }

    public function post(string $endpoint, array $data = []): array|\WP_Error
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array|\WP_Error
    {
        return $this->request('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint): array|\WP_Error
    {
        return $this->request('DELETE', $endpoint);
    }

    // Items/Products
    public function get_items(array $params = []): array|\WP_Error
    {
        return $this->get('/items', $params);
    }

    public function get_item(int $id): array|\WP_Error
    {
        return $this->get('/items/' . $id, ['mode' => 'advanced']);
    }

    public function create_item(array $data): array|\WP_Error
    {
        return $this->post('/items', $data);
    }

    public function update_item(int $id, array $data): array|\WP_Error
    {
        return $this->put('/items/' . $id, $data);
    }

    public function delete_item(int $id): array|\WP_Error
    {
        return $this->delete('/items/' . $id);
    }

    // Contacts/Customers
    public function get_contacts(array $params = []): array|\WP_Error
    {
        return $this->get('/contacts', $params);
    }

    public function get_contact(int $id): array|\WP_Error
    {
        return $this->get('/contacts/' . $id);
    }

    public function create_contact(array $data): array|\WP_Error
    {
        return $this->post('/contacts', $data);
    }

    public function update_contact(int $id, array $data): array|\WP_Error
    {
        return $this->put('/contacts/' . $id, $data);
    }

    public function delete_contact(int $id): array|\WP_Error
    {
        return $this->delete('/contacts/' . $id);
    }

    // Invoices
    public function get_invoices(array $params = []): array|\WP_Error
    {
        return $this->get('/invoices', $params);
    }

    public function get_invoice(int $id): array|\WP_Error
    {
        return $this->get('/invoices/' . $id);
    }

    public function get_invoice_pdf(int $id): array|\WP_Error
    {
        return $this->get('/invoices/' . $id, ['fields' => 'pdf']);
    }

    public function create_invoice(array $data): array|\WP_Error
    {
        return $this->post('/invoices', $data);
    }

    public function update_invoice(int $id, array $data): array|\WP_Error
    {
        return $this->put('/invoices/' . $id, $data);
    }

    public function void_invoice(int $id, string $reason = ''): array|\WP_Error
    {
        $data = [];
        if (!empty($reason)) {
            $data['reason'] = sanitize_text_field($reason);
        }
        return $this->post('/invoices/' . $id . '/void', $data);
    }

    // Credit Notes
    public function create_credit_note(array $data): array|\WP_Error
    {
        return $this->post('/credit-notes', $data);
    }

    // Categories
    public function get_item_categories(array $params = []): array|\WP_Error
    {
        return $this->get('/item-categories', $params);
    }

    public function get_item_category(int $id): array|\WP_Error
    {
        return $this->get('/item-categories/' . $id);
    }

    public function create_item_category(array $data): array|\WP_Error
    {
        return $this->post('/item-categories', $data);
    }

    public function update_item_category(int $id, array $data): array|\WP_Error
    {
        return $this->put('/item-categories/' . $id, $data);
    }

    public function delete_item_category(int $id): array|\WP_Error
    {
        return $this->delete('/item-categories/' . $id);
    }

    // Warehouses
    public function get_warehouses(array $params = []): array|\WP_Error
    {
        return $this->get('/warehouses', $params);
    }

    // Taxes
    public function get_taxes(array $params = []): array|\WP_Error
    {
        return $this->get('/taxes', $params);
    }

    // Price Lists
    public function get_price_lists(array $params = []): array|\WP_Error
    {
        return $this->get('/price-lists', $params);
    }

    // Payments
    public function get_payments(array $params = []): array|\WP_Error
    {
        return $this->get('/payments', $params);
    }

    public function create_payment(array $data): array|\WP_Error
    {
        return $this->post('/payments', $data);
    }

    // Currency
    public function get_currencies(array $params = []): array|\WP_Error
    {
        return $this->get('/currencies', $params);
    }

    public function get_items_count(): int
    {
        $result = $this->get('/items', ['metadata' => 'true', 'limit' => 1]);
        if (is_wp_error($result)) return -1;
        return (int) ($result['metadata']['total'] ?? 0);
    }

    public function get_contacts_count(): int
    {
        $result = $this->get('/contacts', ['metadata' => 'true', 'limit' => 1, 'type' => 'client']);
        if (is_wp_error($result)) return -1;
        return (int) ($result['metadata']['total'] ?? 0);
    }

    // Number Templates
    public function get_number_templates(array $params = []): array|\WP_Error
    {
        return $this->get('/number-templates', $params);
    }

    public function get_number_template(int $id): array|\WP_Error
    {
        return $this->get('/number-templates/' . $id);
    }

    // Payment Terms
    public function get_terms(array $params = []): array|\WP_Error
    {
        return $this->get('/terms', $params);
    }

    public function get_term(int $id): array|\WP_Error
    {
        return $this->get('/terms/' . $id);
    }

    // Retentions (tax withholding)
    public function get_retentions(array $params = []): array|\WP_Error
    {
        return $this->get('/retentions', $params);
    }

    public function get_retention(int $id): array|\WP_Error
    {
        return $this->get('/retentions/' . $id);
    }

    // Bank Accounts
    public function get_bank_accounts(array $params = []): array|\WP_Error
    {
        return $this->get('/bank-accounts', $params);
    }

    public function get_bank_account(int $id): array|\WP_Error
    {
        return $this->get('/bank-accounts/' . $id);
    }

    // Company
    public function get_company(): array|\WP_Error
    {
        return $this->get('/company');
    }

    // Sellers
    public function get_sellers(array $params = []): array|\WP_Error
    {
        return $this->get('/sellers', $params);
    }

    // Full CRUD for Credit Notes
    public function get_credit_notes(array $params = []): array|\WP_Error
    {
        return $this->get('/credit-notes', $params);
    }

    public function get_credit_note(int $id): array|\WP_Error
    {
        return $this->get('/credit-notes/' . $id);
    }

    public function update_credit_note(int $id, array $data): array|\WP_Error
    {
        return $this->put('/credit-notes/' . $id, $data);
    }

    public function delete_credit_note(int $id): array|\WP_Error
    {
        return $this->delete('/credit-notes/' . $id);
    }

    public function void_credit_note(int $id): array|\WP_Error
    {
        return $this->post('/credit-notes/' . $id . '/void', []);
    }

    // Full CRUD for Payments
    public function get_payment(int $id): array|\WP_Error
    {
        return $this->get('/payments/' . $id);
    }

    public function update_payment(int $id, array $data): array|\WP_Error
    {
        return $this->put('/payments/' . $id, $data);
    }

    public function delete_payment(int $id): array|\WP_Error
    {
        return $this->delete('/payments/' . $id);
    }

    public function void_payment(int $id): array|\WP_Error
    {
        return $this->post('/payments/' . $id . '/void', []);
    }

    public function open_payment(int $id): array|\WP_Error
    {
        return $this->post('/payments/' . $id . '/open', []);
    }

    // Price Lists CRUD
    public function create_price_list(array $data): array|\WP_Error
    {
        return $this->post('/price-lists', $data);
    }

    public function update_price_list(int $id, array $data): array|\WP_Error
    {
        return $this->put('/price-lists/' . $id, $data);
    }

    public function get_price_list(int $id): array|\WP_Error
    {
        return $this->get('/price-lists/' . $id);
    }

    public function delete_price_list(int $id): array|\WP_Error
    {
        return $this->delete('/price-lists/' . $id);
    }

    // Variant Attributes (for product variants like color, size)
    public function get_variant_attributes(array $params = []): array|\WP_Error
    {
        return $this->get('/variant-attributes', $params);
    }

    public function get_variant_attribute(int $id): array|\WP_Error
    {
        return $this->get('/variant-attributes/' . $id);
    }

    public function create_variant_attribute(array $data): array|\WP_Error
    {
        return $this->post('/variant-attributes', $data);
    }

    // Tax CRUD
    public function get_tax(int $id): array|\WP_Error
    {
        return $this->get('/taxes/' . $id);
    }

    public function create_tax(array $data): array|\WP_Error
    {
        return $this->post('/taxes', $data);
    }

    // Inventory Adjustments
    public function get_inventory_adjustments(array $params = []): array|\WP_Error
    {
        return $this->get('/inventory-adjustments', $params);
    }

    public function create_inventory_adjustment(array $data): array|\WP_Error
    {
        return $this->post('/inventory-adjustments', $data);
    }

    // Estimates / Quotes
    public function get_estimates(array $params = []): array|\WP_Error
    {
        return $this->get('/estimates', $params);
    }

    public function get_estimate(int $id): array|\WP_Error
    {
        return $this->get('/estimates/' . $id);
    }

    public function create_estimate(array $data): array|\WP_Error
    {
        return $this->post('/estimates', $data);
    }

    // Invoice actions
    public function open_invoice(int $id): array|\WP_Error
    {
        return $this->post('/invoices/' . $id . '/open', []);
    }

    public function stamp_invoice(int $id, bool $generateStamp = true): array|\WP_Error
    {
        return $this->post('/invoices/' . $id . '/stamp', [
            'stamp' => ['generateStamp' => $generateStamp],
        ]);
    }

    // Retentions on invoices
    public function update_invoice_retentions(int $id, array $retentions): array|\WP_Error
    {
        return $this->put('/invoices/' . $id . '/retentions-applied', $retentions);
    }

    // Cost Centers
    public function get_cost_centers(array $params = []): array|\WP_Error
    {
        return $this->get('/cost-centers', $params);
    }

    public function get_cost_center(int $id): array|\WP_Error
    {
        return $this->get('/cost-centers/' . $id);
    }

    // Webhook Subscriptions
    public function create_webhook_subscription(string $event, string $url): array|\WP_Error
    {
        return $this->post('/webhooks/subscriptions', [
            'event' => $event,
            'url' => $url,
        ]);
    }

    public function get_webhook_subscriptions(): array|\WP_Error
    {
        return $this->get('/webhooks/subscriptions');
    }

    public function delete_webhook_subscription(string $id): array|\WP_Error
    {
        return $this->delete('/webhooks/subscriptions/' . $id);
    }

    public static function get_webhook_events(): array
    {
        return [
            'new-invoice',
            'edit-invoice',
            'delete-invoice',
            'new-bill',
            'edit-bill',
            'delete-bill',
            'new-client',
            'edit-client',
            'delete-client',
            'new-item',
            'edit-item',
            'delete-item',
        ];
    }

    // Item attachments (images)
    public function upload_item_image(int $item_id, string $file_path): array|\WP_Error
    {
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', 'Image file not found');
        }

        $boundary = 'alegra-' . uniqid();
        $file_name = basename($file_path);
        $file_content = file_get_contents($file_path);
        $mime = mime_content_type($file_path) ?: 'image/jpeg';

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"{$file_name}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $url = $this->base_url . '/items/' . $item_id . '/attachment';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => $this->get_auth_header(),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            if ($this->logger) $this->logger->error('Item image upload failed', ['error' => $response->get_error_message()]);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($response_body, true);

        if ($code >= 400) {
            $message = is_array($decoded) ? ($decoded['message'] ?? 'Upload failed') : 'Upload failed';
            if ($this->logger) $this->logger->error('Item image upload error', ['code' => $code, 'message' => $message]);
            return new \WP_Error('upload_error', $message);
        }

        return $decoded ?? [];
    }

    // Rate limiting (token bucket: 50 req/min, conservative vs Alegra's 60)
    private const RATE_LIMIT_PER_MIN = 50;
    private const RATE_BURST_ALLOWANCE = 10;

    private function is_rate_limited(): bool
    {
        $rate_limit = (int) get_transient('alegra_connector_rate_limit');
        return $rate_limit >= (self::RATE_LIMIT_PER_MIN + self::RATE_BURST_ALLOWANCE);
    }

    private function increment_rate_limit(): void
    {
        $rate_limit = (int) get_transient('alegra_connector_rate_limit');
        set_transient('alegra_connector_rate_limit', $rate_limit + 1, 60, 'no');
    }

    /**
     * Wait briefly if we're at the rate limit. Returns true if request should proceed.
     * If limit is hard-reached, throws WP_Error so the caller knows to retry later.
     */
    private function throttle(): bool
    {
        if ($this->is_rate_limited()) {
            // Wait up to 5s for the rate limit window to refresh
            $sleep = min(60 - (time() % 60), 5);
            if ($sleep > 0) {
                sleep($sleep);
            }
            // Re-check after sleep
            if ($this->is_rate_limited()) {
                return false;
            }
        }
        return true;
    }
}