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
     * Read the Consumidor Final contact ID WITHOUT touching the network or
     * creating anything (REQ-RB-1).
     *
     * Resolution order: manual override → transient cache → option cache. A
     * miss returns false; it never calls resolve()/create(). This is the
     * read-only check a page render must use (the dashboard previously called
     * get_id(), so merely viewing it could POST /contacts).
     *
     * @return string|false Contact ID when cached/overridden, false otherwise.
     */
    public static function peek_id(): string|false
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

        // 3. Option cache. No transient hydration: this is a read-only peek.
        $option = get_option(self::cache_key(self::OPTION_KEY), '');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        return false;
    }

    /**
     * Whether the Consumidor Final contact is already resolved/cached.
     *
     * Read-only: safe to call from a page render (REQ-RB-1).
     */
    public static function is_configured(): bool
    {
        return self::peek_id() !== false;
    }

    /**
     * Get the Consumidor Final contact ID, resolving it lazily if needed.
     *
     * Resolution order: manual override → transient cache → option cache →
     * live resolution against the Alegra API. Returns false when the contact
     * cannot be resolved.
     *
     * WARNING: this may WRITE (POST /contacts) when the contact is not cached.
     * Never call it from a page render — use peek_id()/is_configured() there.
     *
     * @return string|false Contact ID on success, false otherwise.
     */
    public static function get_or_create_id(): string|false
    {
        // Serve every cached/override representation first (read-only).
        $peeked = self::peek_id();
        if ($peeked !== false) {
            // Hydrate the transient when the option cache served it.
            if (get_transient(self::cache_key(self::CACHE_TRANSIENT)) === false) {
                set_transient(self::cache_key(self::CACHE_TRANSIENT), $peeked, self::CACHE_TTL);
            }
            return $peeked;
        }

        // Lazy resolution (may create the contact).
        $resolved = self::resolve();
        if ($resolved === false) {
            return false;
        }

        self::cache_id($resolved);

        return $resolved;
    }

    /**
     * Backwards-compatible alias of get_or_create_id().
     *
     * @deprecated Use get_or_create_id() for the write path, or
     *             peek_id()/is_configured() for read-only checks.
     *
     * @return string|false
     */
    public static function get_id(): string|false
    {
        return self::get_or_create_id();
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
     * Resuelve el CF SÓLO con GET + match + caché. NUNCA POSTea. D1 Rama A.
     *
     * @return string|false Id del CF, o false si no se encontró / no se pudo verificar.
     */
    public static function resolve_readonly(?Client $client = null): string|false
    {
        $peeked = self::peek_id();
        if ($peeked !== false) {
            return $peeked;
        }
        if ($client === null) {
            if (!class_exists(Client::class)) {
                return false;
            }
            $client = new Client();
        }
        $scan = self::scan_candidates($client);
        if ($scan['found'] !== null) {
            self::cache_id($scan['found']);
            return $scan['found'];
        }
        return false;
    }

    /**
     * Estado honesto del CF, sin escribir nunca.
     *
     * 1. cache/override ⇒ available (sin red).
     * 2. barrido read-only ⇒ match ⇒ cache_id() + available.
     * 3. barrido completo sin match ⇒ not_found.
     * 4. red caída / barrido truncado ⇒ unverified (NUNCA "no encontrado").
     *
     * @return array{state:'available'|'not_found'|'unverified',id:?string,reason:string,scanned:int}
     */
    public static function probe(?Client $client = null): array
    {
        $peeked = self::peek_id();
        if ($peeked !== false) {
            return ['state' => 'available', 'id' => $peeked, 'reason' => 'cached', 'scanned' => 0];
        }
        if ($client === null) {
            if (!class_exists(Client::class)) {
                return ['state' => 'unverified', 'id' => null, 'reason' => 'client_unavailable', 'scanned' => 0];
            }
            $client = new Client();
        }

        $scan = self::scan_candidates($client);
        if ($scan['found'] !== null) {
            self::cache_id($scan['found']);
            return ['state' => 'available', 'id' => $scan['found'], 'reason' => 'match', 'scanned' => $scan['scanned']];
        }
        if ($scan['complete']) {
            return ['state' => 'not_found', 'id' => null, 'reason' => 'not_found', 'scanned' => $scan['scanned']];
        }
        return ['state' => 'unverified', 'id' => null, 'reason' => $scan['reason'], 'scanned' => $scan['scanned']];
    }

    /**
     * Lee el último probe persistido SIN tocar la red (para el render).
     *
     * @return array{state:string,id:?string,reason:string,scanned:int,at:int}
     */
    public static function probe_state(): array
    {
        $default = ['state' => 'unverified', 'id' => null, 'reason' => '', 'scanned' => 0, 'at' => 0];
        $stored = get_option('alegra_connector_consumidor_final_probe', []);
        if (!is_array($stored)
            || !isset($stored['state'])
            || !in_array($stored['state'], ['available', 'not_found', 'unverified'], true)
        ) {
            return $default;
        }
        return array_merge($default, $stored);
    }

    /**
     * Persiste el resultado de probe() con timestamp. Autoload off.
     *
     * @param array<string,mixed> $probe
     * @return array<string,mixed>
     */
    public static function persist_probe(array $probe): array
    {
        $probe['at'] = time();
        update_option('alegra_connector_consumidor_final_probe', $probe, false);
        return $probe;
    }

    /**
     * Barrido read-only de los candidatos del filtro `identification` (CONTAINS).
     *
     * Pagina con `limit=30` hasta `max_pages=10` (300 candidatos). Una página llena
     * NO es "fin": se pide la siguiente. Sólo un barrido COMPLETO habilita
     * `not_found`; un tope ⇒ `truncated` (⇒ el caller reporta `unverified`).
     * NUNCA POSTea: sólo GET + match + store_metadata.
     *
     * @return array{found:?string,complete:bool,scanned:int,reason:string}
     */
    private static function scan_candidates(Client $client): array
    {
        $per_page  = 30;   // máximo documentado de /contacts
        $max_pages = 10;   // 300 candidatos; más ⇒ unverified
        $found     = null;
        $scanned   = 0;
        $complete  = false;

        for ($p = 0; $p < $max_pages; $p++) {
            $batch = $client->get_contacts([
                'identification' => self::IDENTIFICATION,   // CONTAINS en Alegra
                'limit'          => $per_page,
                'start'          => $p * $per_page,
            ]);

            if (is_wp_error($batch)) {
                return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'api_error'];
            }
            if (!is_array($batch)) {
                return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'bad_response'];
            }

            foreach ($batch as $contact) {
                $scanned++;
                if (is_array($contact) && isset($contact['id']) && self::matches($contact)) {
                    $found = (string) $contact['id'];
                    self::store_metadata($contact);
                    break 2;
                }
            }

            // Una página llena NO es "fin": pedir la siguiente.
            if (count($batch) < $per_page) {
                $complete = true;
                break;
            }
        }

        return [
            'found'    => $found,
            'complete' => $complete,
            'scanned'  => $scanned,
            'reason'   => $found !== null ? 'match' : ($complete ? 'not_found' : 'truncated'),
        ];
    }

    /**
     * Cachea el id del CF en transient + option (misma representación que
     * get_or_create_id()). Sólo lo llama el camino read-only tras un match exacto.
     */
    private static function cache_id(string $id): void
    {
        if ($id === '') {
            return;
        }
        set_transient(self::cache_key(self::CACHE_TRANSIENT), $id, self::CACHE_TTL);
        update_option(self::cache_key(self::OPTION_KEY), $id);
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
        // REQ-RB-1: creating the CF contact is a WRITE. It must only happen from
        // a merchant action (explicit context) or when the `contact` entity is
        // enabled for automatic writes (`push_customers_enabled`) — never from a
        // page render. The dashboard uses is_configured()/peek_id() and never
        // reaches here; this is the hard defence in depth. It mirrors the entity
        // gate exactly (explicit OR push_customers_enabled), so no automatic
        // invoice path that could write the contact before is broken.
        if (!\Alegra\Connector\Write_Gate::is_explicit()
            && !get_option('alegra_connector_push_customers_enabled', false)
        ) {
            self::log_error('Consumidor Final: creación bloqueada (requiere acción explícita o push de clientes habilitado)');
            return false;
        }

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
     *
     * WARNING: this resolves (and may CREATE) the contact. A page render must
     * use is_configured()/peek_id() instead (REQ-RB-1).
     *
     * @deprecated Use is_configured() for read-only checks.
     */
    public static function is_available(): bool
    {
        return self::get_or_create_id() !== false;
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
