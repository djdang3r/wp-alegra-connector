<?php
/**
 * Consumidor Final — fallback contact for customers without billing data.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

use Alegra\Connector\API\Client;

if (!defined('ABSPATH')) {
    exit;
}

class Consumidor_Final
{
    public const IDENTIFICATION      = '222222222222';
    public const IDENTIFICATION_TYPE = 'CC';
    public const KIND_OF_PERSON      = 'PERSON_ENTITY';
    public const REGIME              = 'SIMPLIFIED_REGIME';
    public const NAME                = 'Consumidor Final';

    private const CACHE_TRANSIENT    = 'alegra_consumidor_final_resolved';
    private const CACHE_TTL          = 3600; // 1 hour
    private const OPTION_KEY         = 'alegra_connector_consumidor_final_contact_id';
    private const METADATA_TRANSIENT = 'alegra_consumidor_final_metadata';
    private const LOCK_KEY           = 'alegra_consumidor_final_resolving';
    private const LOCK_TTL           = 60;

    /**
     * Get the Consumidor Final contact ID, resolving it lazily if needed.
     *
     * Resolution order: manual override → transient cache → option cache →
     * live resolution against the Alegra API. Returns false when the contact
     * cannot be resolved.
     *
     * @return string|false Contact ID on success, false otherwise.
     */
    public static function get_id(): string|false
    {
        // 1. Manual override wins when present and non-empty.
        if (get_option('alegra_connector_consumidor_final_manual_override', false)) {
            $manual = (string) get_option('alegra_connector_consumidor_final_manual_id', '');
            if ($manual !== '') {
                return $manual;
            }
        }

        // 2. Transient cache.
        $cached = get_transient(self::cache_key(self::CACHE_TRANSIENT));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 3. Option cache — hydrate the transient for subsequent requests.
        $option = get_option(self::cache_key(self::OPTION_KEY), '');
        if (is_string($option) && $option !== '') {
            set_transient(self::cache_key(self::CACHE_TRANSIENT), $option, self::CACHE_TTL);
            return $option;
        }

        // 4. Lazy resolution.
        $resolved = self::resolve();
        if ($resolved === false) {
            return false;
        }

        set_transient(self::cache_key(self::CACHE_TRANSIENT), $resolved, self::CACHE_TTL);
        update_option(self::cache_key(self::OPTION_KEY), $resolved);

        return $resolved;
    }

    /**
     * Resolve the Consumidor Final contact from Alegra.
     *
     * Looks up the contact by identification and, when the search succeeds but
     * the contact is missing, auto-creates it (idempotently). A manual override
     * still wins, and a failed search never creates.
     *
     * @param Client|null $client Optional API client; one is built when omitted.
     * @return string|false Contact ID on success, false otherwise.
     */
    public static function resolve(?Client $client = null): string|false
    {
        // AC-42: serve the existing cache before touching the API. get_metadata()
        // calls resolve() whenever its own transient is missing, so without this
        // short-circuit every metadata read triggered a live lookup.
        $cached = get_transient(self::cache_key(self::CACHE_TRANSIENT));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $option = get_option(self::cache_key(self::OPTION_KEY), '');
        if (is_string($option) && $option !== '') {
            set_transient(self::cache_key(self::CACHE_TRANSIENT), $option, self::CACHE_TTL);
            return $option;
        }

        $lock_key = self::cache_key(self::LOCK_KEY);

        // Acquire an atomic lock so concurrent requests do not hammer the API.
        $token = \Alegra\Connector\Sync\Controller::acquire_lock($lock_key, self::LOCK_TTL);
        if ($token === false) {
            usleep(500000); // 500ms

            $cached = get_transient(self::cache_key(self::CACHE_TRANSIENT));
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $option = get_option(self::cache_key(self::OPTION_KEY), '');
            if (is_string($option) && $option !== '') {
                return $option;
            }

            return false;
        }

        try {
            if ($client === null) {
                if (!class_exists(Client::class)) {
                    return false;
                }
                $client = new Client();
            }

            $contacts = $client->get_contacts([
                'identification' => self::IDENTIFICATION,
                'limit'          => 5,
            ]);

            if (is_wp_error($contacts)) {
                self::log_error(
                    'Consumidor Final: error al consultar el contacto en Alegra',
                    ['error' => $contacts->get_error_message()]
                );
                // The search FAILED: do not create. We cannot tell "missing"
                // apart from "the API is down", and creating blindly could
                // duplicate a contact that does exist.
                return false;
            }

            if (!is_array($contacts)) {
                return false;
            }

            foreach ($contacts as $contact) {
                if (!is_array($contact) || !isset($contact['id'])) {
                    continue;
                }

                if (!self::matches($contact)) {
                    continue;
                }

                self::store_metadata($contact);

                return (string) $contact['id'];
            }

            // BUG 2: the search succeeded and the contact does not exist.
            // Auto-create it so the Consumidor Final fallback is
            // self-sufficient instead of failing the invoice with
            // `customer_unresolved`.
            $created = self::create($client);
            if ($created !== false) {
                return $created;
            }

            // AC-25: creation failed (or was blocked). Drop every cached
            // representation so a dead id is not served forever.
            self::invalidate_cache();

            return false;
        } finally {
            \Alegra\Connector\Sync\Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * Whether an Alegra contact is the Consumidor Final contact.
     *
     * Accepts the structured `identificationObject` shape (type CC) and the
     * legacy flat `identification` string. Never trust the server-side filter
     * alone: an undocumented param can be ignored, which would false-match.
     */
    private static function matches(array $contact): bool
    {
        $identification_object = $contact['identificationObject'] ?? null;

        if (is_array($identification_object)) {
            return (($identification_object['type'] ?? '') === self::IDENTIFICATION_TYPE)
                && ((string) ($identification_object['number'] ?? '') === self::IDENTIFICATION);
        }

        return (string) ($contact['identification'] ?? '') === self::IDENTIFICATION;
    }

    /**
     * Create the Consumidor Final contact in Alegra and return its id.
     *
     * Idempotent: only reached after a successful search found nothing, and the
     * resolve lock serializes concurrent creators. If Alegra still rejects the
     * duplicate identification (a concurrent creator or a contact the search
     * missed), the contact is re-searched once and its id returned.
     *
     * @return string|false Contact id on success, false otherwise.
     */
    private static function create(Client $client): string|false
    {
        $result = $client->create_contact(self::build_create_payload());

        // Dry run / Write Gate: the contact was NOT created. Never cache a fake id.
        if (Client::write_was_blocked($result)) {
            self::log_error('Consumidor Final: no se creó el contacto (escritura bloqueada por configuración)');
            return false;
        }

        if (is_wp_error($result)) {
            // A duplicate-identification rejection means the contact exists
            // after all. Re-search once so the operation stays idempotent.
            $contacts = $client->get_contacts([
                'identification' => self::IDENTIFICATION,
                'limit'          => 5,
            ]);
            if (!is_wp_error($contacts) && is_array($contacts)) {
                foreach ($contacts as $contact) {
                    if (is_array($contact) && isset($contact['id']) && self::matches($contact)) {
                        self::store_metadata($contact);
                        return (string) $contact['id'];
                    }
                }
            }

            self::log_error(
                'Consumidor Final: no se pudo crear el contacto en Alegra',
                ['error' => $result->get_error_message()]
            );
            return false;
        }

        if (!is_array($result) || empty($result['id'])) {
            return false;
        }

        self::store_metadata($result);

        return (string) $result['id'];
    }

    /**
     * Build the POST /contacts payload for the Consumidor Final contact.
     *
     * CO uses the structured identificationObject + the fiscal fields required
     * by the "con facturación electrónica" schema; other countries use the
     * generic flat identification.
     *
     * @return array<string, mixed>
     */
    private static function build_create_payload(): array
    {
        if (\Alegra\Connector\Billing_Fields::is_colombia_account()) {
            return [
                // kindOfPerson = PERSON_ENTITY, so the docs require nameObject
                // (name alone is only valid for a non-natural person).
                'nameObject'           => ['firstName' => 'Consumidor', 'lastName' => 'Final'],
                'identificationObject' => [
                    'type'   => self::IDENTIFICATION_TYPE,
                    'number' => self::IDENTIFICATION,
                ],
                'kindOfPerson'         => self::KIND_OF_PERSON,
                'regime'               => self::REGIME,
                'type'                 => 'client',
            ];
        }

        return [
            'name'           => self::NAME,
            'identification' => self::IDENTIFICATION,
            'type'           => 'client',
        ];
    }

    /**
     * Get the metadata of the cached Consumidor Final contact.
     *
     * Reads the metadata transient populated by resolve(); resolves the
     * contact first when the transient is missing.
     *
     * @return array<string, string>|null Metadata array or null when unavailable.
     */
    public static function get_metadata(): ?array
    {
        $cached = get_transient(self::cache_key(self::METADATA_TRANSIENT));
        if (is_array($cached)) {
            return $cached;
        }

        if (self::resolve() === false) {
            return null;
        }

        $cached = get_transient(self::cache_key(self::METADATA_TRANSIENT));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Whether the Consumidor Final contact is available for use.
     */
    public static function is_available(): bool
    {
        return self::get_id() !== false;
    }

    /**
     * Invalidate every cached representation of the contact.
     */
    public static function invalidate_cache(): void
    {
        delete_transient(self::cache_key(self::CACHE_TRANSIENT));
        delete_option(self::cache_key(self::OPTION_KEY));
        delete_transient(self::cache_key(self::METADATA_TRANSIENT));
    }

    /**
     * Whether an Alegra contact id is the cached/manual Consumidor Final.
     *
     * Reads the caches directly (never resolves) so webhook handlers can detect
     * a deleted/replaced CF contact without an extra API round-trip.
     */
    public static function is_consumidor_final(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        if (get_option('alegra_connector_consumidor_final_manual_override', false)) {
            $manual = (string) get_option('alegra_connector_consumidor_final_manual_id', '');
            if ($manual !== '' && $manual === $id) {
                return true;
            }
        }

        $option = get_option(self::cache_key(self::OPTION_KEY), '');
        if (is_string($option) && $option !== '' && $option === $id) {
            return true;
        }

        $cached = get_transient(self::cache_key(self::CACHE_TRANSIENT));
        return is_string($cached) && $cached !== '' && $cached === $id;
    }

    /**
     * Wire the settings-save invalidation hooks (AC-25).
     *
     * Changing the manual override must not keep serving the previously cached
     * contact id. Idempotent.
     */
    public static function register_invalidation_hooks(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        add_action('update_option_alegra_connector_consumidor_final_manual_override', [self::class, 'invalidate_cache']);
        add_action('update_option_alegra_connector_consumidor_final_manual_id', [self::class, 'invalidate_cache']);
    }

    /**
     * Build a multisite-safe key by suffixing the current blog ID.
     */
    private static function cache_key(string $key): string
    {
        return is_multisite() ? $key . '_' . get_current_blog_id() : $key;
    }

    /**
     * Persist the contact metadata for later consumption.
     *
     * @param array<string, mixed> $contact Raw Alegra contact payload.
     */
    private static function store_metadata(array $contact): void
    {
        $identification_object = $contact['identificationObject'] ?? null;

        $metadata = [
            'name'               => (string) ($contact['name'] ?? self::NAME),
            'kindOfPerson'       => (string) ($contact['kindOfPerson'] ?? self::KIND_OF_PERSON),
            'identificationType' => is_array($identification_object) && !empty($identification_object['type'])
                ? (string) $identification_object['type']
                : self::IDENTIFICATION_TYPE,
            'identification'     => (string) ($contact['identification'] ?? self::IDENTIFICATION),
            'regime'             => (string) ($contact['regime'] ?? self::REGIME),
        ];

        set_transient(self::cache_key(self::METADATA_TRANSIENT), $metadata, self::CACHE_TTL);
    }

    /**
     * Log a resolution error when the Logger is available. Never throws.
     *
     * @param array<string, mixed> $context Log context.
     */
    private static function log_error(string $message, array $context = []): void
    {
        if (!class_exists(Logger\Logger::class)) {
            return;
        }

        try {
            (new Logger\Logger())->error($message, $context);
        } catch (\Throwable $e) {
            // Logging must never break contact resolution.
        }
    }
}
