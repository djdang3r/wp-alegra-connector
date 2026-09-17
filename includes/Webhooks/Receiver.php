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
    /**
     * How long an identical webhook body is remembered and treated as a replay.
     */
    private const REPLAY_WINDOW_SECONDS = 900; // 15 minutes

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

        // Registration handshake. Alegra's docs (descripción-general) state that
        // when a subscription is created it POSTs an EMPTY body to the URL and
        // requires a 2XX within 5s, otherwise the subscription is never created.
        // The old code answered 400 to that empty body, so registration could
        // never succeed. Ack it: an empty body carries no subject to process.
        if (trim($body) === '') {
            return new \WP_REST_Response(['received' => true, 'handshake' => true], 200);
        }

        // Shared-secret gate. Alegra cannot sign deliveries and publishes no
        // source IPs (the /webhooks/subscriptions schema accepts only {event,
        // url}), so the only credential available is a secret embedded in the
        // registered URL (?token=...). Reject any request that does not carry
        // the exact token.
        if (!$this->authorize($request)) {
            if ($this->logger) {
                $this->logger->warning('Webhook rejected: missing or invalid token');
            }
            return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($body, true);

        if (!is_array($payload) || !isset($payload['subject'])) {
            return new \WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        // Optional HMAC. Alegra does NOT send X-Alegra-Signature, so this is
        // normally absent; when a reverse proxy or extension DOES inject one,
        // enforce it fail-closed as a second factor.
        $signature = $request->get_header('X-Alegra-Signature') ?? '';
        if ($signature !== '' && !$this->verify_hmac($body, $signature)) {
            if ($this->logger) {
                $this->logger->warning('Webhook received with invalid HMAC signature');
            }
            return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        // Replay protection (AC-32). Alegra cannot sign, so a captured request
        // can be replayed; remember the body hash and ignore duplicates inside
        // the window. Ack with 200 (not 4xx) so Alegra stops retrying and does
        // not delete the subscription for a request we already processed.
        if ($this->is_replay($body)) {
            if ($this->logger) {
                $this->logger->warning('Webhook replay ignored (duplicate body)', [
                    'subject' => sanitize_text_field((string) $payload['subject']),
                ]);
            }
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        $event = sanitize_text_field($payload['subject']);
        $data = $payload['message'] ?? [];

        // AC-77: `message` must be an array. A scalar body used to raise a
        // TypeError inside process_event(string, array) that was caught and
        // reported as a generic 500; reject it as a bad request instead.
        if (!is_array($data)) {
            return new \WP_REST_Response(['error' => 'Invalid payload: message must be an object'], 400);
        }

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

    /**
     * Record the body hash and report whether this exact body was already seen
     * within the replay window. Public for testing.
     */
    public function is_replay(string $body): bool
    {
        $key = 'alegra_webhook_seen_' . hash('sha256', $body);
        if (get_transient($key) !== false) {
            return true;
        }

        set_transient($key, time(), self::REPLAY_WINDOW_SECONDS);
        return false;
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

    /**
     * Enforce the shared secret carried in the registered URL.
     *
     * Fail-closed: with no token configured there can be no legitimate
     * subscription, so reject rather than accept an unauthenticated event.
     */
    private function authorize(\WP_REST_Request $request): bool
    {
        $expected = (string) get_option(self::token_option(), '');
        if ($expected === '') {
            return false;
        }

        $provided = (string) ($request->get_param('token') ?? '');
        if ($provided === '') {
            // Secondary transport, e.g. a reverse proxy that injects a header.
            $provided = (string) $request->get_header('X-Alegra-Webhook-Token');
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Option name holding the webhook shared secret.
     */
    public static function token_option(): string
    {
        return 'alegra_connector_webhook_token';
    }

    /**
     * Return the existing webhook token, generating and persisting one on first
     * use. Generated with the CSPRNG-backed wp_generate_password (no special
     * chars, so it survives a query string untouched).
     */
    public static function ensure_token(): string
    {
        $token = (string) get_option(self::token_option(), '');
        if ($token === '') {
            $token = wp_generate_password(40, false, false);
            update_option(self::token_option(), $token, false);
        }
        return $token;
    }

    /**
     * Build the URL to register with Alegra: the REST route plus the shared
     * secret. add_query_arg() keeps the plain-permalink `?rest_route=` form
     * working (a manual '?token=' would produce a second '?').
     */
    public static function registration_url(): string
    {
        return add_query_arg(
            'token',
            self::ensure_token(),
            rest_url('alegra-connector/v1/webhook')
        );
    }
}
