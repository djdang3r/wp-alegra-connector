<?php

declare(strict_types=1);

namespace Alegra\Connector\Webhooks;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Receiver
{
    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function register_route(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('alegra-connector/v1', '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_body();
        $payload = json_decode($body, true);

        if (!is_array($payload) || !isset($payload['subject'])) {
            return new \WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        $signature = $request->get_header('X-Alegra-Signature') ?? '';
        if (!$this->verify_hmac($body, $signature)) {
            if ($this->logger) {
                $this->logger->warning('Webhook received with invalid HMAC signature');
            }
            return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        $event = sanitize_text_field($payload['subject']);
        $data = $payload['message'] ?? [];

        if ($this->logger) {
            $this->logger->info('Webhook received', ['event' => $event]);
        }

        try {
            $handlers = new Handlers($this->api, $this->logger);
            $handlers->process_event($event, $data);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Webhook processing failed', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(['error' => 'Processing failed'], 500);
        }

        return new \WP_REST_Response(['received' => true], 200);
    }

    public function verify_hmac(string $body, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $secret = (string) get_option('alegra_connector_webhook_secret', '');
        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }
}
