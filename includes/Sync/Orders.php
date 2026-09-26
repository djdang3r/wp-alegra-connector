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
    /**
     * Reference of the SINGLE generic Alegra service item used for every
     * non-product invoice/credit-note line (shipping, fees, partial refunds).
     *
     * Alegra requires `items[].id` on invoices and credit notes
     * (post_invoices.md / post_credit-notes.md), so a free-text line is
     * rejected. One shared find-or-create item keeps the merchant's catalog
     * clean (no per-purpose duplicates); the line `name`/`description` carries
     * the human-readable label ("Envío", the fee name, "Reembolso parcial").
     */
    private const GENERIC_ITEM_REFERENCE = 'alegra-connector-adjustment';

    /**
     * Order meta holding the invoice id whose "voided" note was already written,
     * so the webhook and the poll never duplicate it.
     */
    public const VOID_NOTIFIED_META = '_alegra_invoice_void_notified';

    /**
     * Valid Alegra `paymentMethod` enum values for POST /payments.
     *
     * Any mapped value outside this list is a configuration bug; the resolver
     * falls back to 'transfer' rather than sending an invalid method.
     *
     * @see https://developer.alegra.com/reference/post_payments-1.md
     */
    private const ALEGRA_PAYMENT_METHOD_ENUM = ['cash', 'check', 'transfer', 'deposit', 'credit-card', 'debit-card'];

    /** REQ-QUEUE-06: backoff exponencial del reintento de facturas [5m,15m,1h,6h,24h]. */
    private const RETRY_BACKOFF = [300, 900, 3600, 21600, 86400];

    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function create_invoice(\WC_Order $order, ?string $status_override = null): array|\WP_Error
    {
        $order_id = (int) $order->get_id();
        $lock_key = 'alegra_invoice_lock_' . $order_id;

        // Anti-duplication mutex: prevent two concurrent requests creating duplicate invoices.
        // Atomic via add_option()'s UNIQUE index (real compare-and-swap).
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            $this->logger->warning('Invoice creation already in progress for this order', [
                'order_id' => $order_id,
            ]);
            return new \WP_Error(
                'invoice_in_progress',
                __('Ya hay una creacion de factura en curso para este pedido. Espera unos segundos.', 'alegra-connector')
            );
        }

        try {
            $alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);

            if ($alegra_id !== '') {
                // FIX-3 (D1): con owner=invoice, un pedido que pasa a pagado
                // debe ABRIR el borrador pre-existente. Hoy se retorna sin
                // abrirlo ⇒ la factura queda draft y no mueve stock.
                // D1 §2.5: con dueño invoice la apertura al pagar es un contrato
                // del modo, no una preferencia. Se quita la dependencia de
                // open_invoice_on_paid (T2.8 lo coerciona ON en la UI/servidor).
                if (Inventory_Pusher::owner() === 'invoice'
                    && $order->is_paid()) {
                    $opened = $this->ensure_invoice_open($alegra_id, true);
                    if (!is_wp_error($opened)) {
                        $this->persist_invoice_status($order, $opened);
                        $this->baseline_products_for_invoice($order);   // T3.3
                    } elseif ($this->logger) {
                        $this->logger->warning('No se pudo abrir el borrador pre-existente (owner=invoice)', [
                            'order_id'   => $order_id,
                            'invoice_id' => $alegra_id,
                            'error'      => $opened->get_error_message(),
                        ]);
                    }
                }
                $this->logger->info('Order already has Alegra invoice', [
                    'order_id'  => $order_id,
                    'alegra_id' => $alegra_id,
                ]);
                return ['id' => $alegra_id, 'already_exists' => true];
            }

            // FIX-3: no crear una segunda factura si el pedido ya tiene una
            // `open` (p. ej. abierta manualmente desde el dashboard).
            if (Inventory_Pusher::owner() === 'invoice') {
                $open_invoice = $this->find_open_invoice_for_order($order);
                if ($open_invoice !== null) {
                    $this->persist_invoice_result($order, (string) $open_invoice['id'], $open_invoice);
                    return ['id' => (string) $open_invoice['id'], 'already_exists' => true];
                }
            }

            // D2 (REQ-INV-01 rama b): con la factura como dueña, un pedido pagado
            // nace `open` para que Alegra descuente stock nativo. El borrador no
            // mueve stock (G1). Sólo aplica cuando el dueño es `invoice`.
            if ($status_override === null
                && Inventory_Pusher::owner() === 'invoice'
                && $order->is_paid()) {
                $status_override = 'open';
            }

            $data = $this->prepare_invoice_data($order, $status_override);
            if (is_wp_error($data)) {
                return $data;
            }

            // AC-14: idempotency pre-search. If a previous POST committed in
            // Alegra but its response was lost (timeout/reset), there is no
            // _alegra_invoice_id. Look for an invoice whose observations carry
            // this WC order marker before creating a duplicate.
            $client_id = (string) ($data['client']['id'] ?? '');
            $existing_invoice = $this->find_existing_invoice($order, $client_id);
            if ($existing_invoice !== null) {
                $existing_id = (string) ($existing_invoice['id'] ?? '');
                if ($existing_id !== '') {
                    $this->persist_invoice_result($order, $existing_id, $existing_invoice);
                    $order->add_order_note(sprintf(
                        __('[Alegra] Se encontró una factura existente (#%s) para este pedido; no se creó una nueva.', 'alegra-connector'),
                        $existing_id
                    ));
                    $this->logger->warning('Recovered pre-existing Alegra invoice (idempotency pre-search)', [
                        'order_id'   => $order_id,
                        'invoice_id' => $existing_id,
                    ]);
                    return ['id' => $existing_id, 'already_exists' => true, 'recovered' => true];
                }
            }

            $result = $this->api->create_invoice($data);

            if (is_wp_error($result)) {
                // D1 / REQ-CF-06: auto-sanado de la caché podrida (400 por client id
                // muerto). Sólo dispara con 400 + mención de cliente + id CF + una vez.
                $healed = $this->try_self_heal_dead_client($order, $data, $result);
                if ($healed !== null) {
                    return $healed;
                }

                // REQ-QUEUE-09: el POST pudo haber commiteado (timeout/5xx). Re-buscar
                // ANTES de persistir el fallo; si existe, adoptarla (no duplicar).
                $classification = Invoice_Failure::classify($result);
                if (!empty($classification['retriable']) || $classification['state'] === 'failed_retriable') {
                    $existing = $this->find_existing_invoice($order, $client_id);
                    if ($existing !== null && (string) ($existing['id'] ?? '') !== '') {
                        $this->persist_invoice_result($order, (string) $existing['id'], $existing);
                        $order->add_order_note(sprintf(
                            __('[Alegra] Factura #%s recuperada tras un fallo de red; no se creó una nueva.', 'alegra-connector'),
                            (string) $existing['id']
                        ));
                        return ['id' => (string) $existing['id'], 'already_exists' => true, 'recovered' => true];
                    }
                }

                Invoice_Failure::persist($order, $classification);
                Invoice_Queue::refresh_count();   // REQ-QUEUE-07: badge/aviso al instante.

                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }

            // REQ-QUEUE-02: un marker de bloqueo es un ARRAY, no un WP_Error.
            // Dry-run NO se persiste; gate/kill-switch ⇒ `blocked` (no loopear).
            if (API\Client::write_was_blocked($result)) {
                $classification = Invoice_Failure::classify($result);
                Invoice_Failure::persist($order, $classification); // persist=false en dry-run
                if (!empty($classification['persist'])) {
                    Invoice_Queue::refresh_count();   // REQ-QUEUE-07.
                }
                if ($this->logger) {
                    $this->logger->warning('Invoice creation blocked by config', [
                        'order_id' => $order_id,
                        'state'    => $classification['state'],
                        'code'     => $classification['code'],
                    ]);
                }
                return $result;
            }

            if (isset($result['id'])) {
                $this->persist_invoice_result($order, (string) $result['id'], $result);
                $this->baseline_products_for_invoice($order);   // REQ-POLL-02
                $order->add_order_note(sprintf(
                    __('Factura Alegra #%s creada.', 'alegra-connector'),
                    (string) $order->get_meta('_alegra_invoice_number', true)
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
            Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * Persist an Alegra invoice id/number on the order.
     *
     * Used on success and when the idempotency pre-search recovers an existing
     * invoice (AC-14).
     *
     * @param array<string, mixed> $invoice The Alegra invoice payload.
     */
    private function persist_invoice_result(\WC_Order $order, string $invoice_id, array $invoice): void
    {
        $order->update_meta_data('_alegra_invoice_id', $invoice_id);

        $invoice_number = $invoice_id;
        if (isset($invoice['numberTemplate']['fullNumber'])) {
            $invoice_number = (string) $invoice['numberTemplate']['fullNumber'];
        } elseif (isset($invoice['numberTemplate']['number'])) {
            $invoice_number = (string) $invoice['numberTemplate']['number'];
        } elseif (isset($invoice['number'])) {
            $invoice_number = (string) $invoice['number'];
        }
        $order->update_meta_data('_alegra_invoice_number', $invoice_number);

        // Cache the invoice status (draft/open/paid/void) so the orders list can
        // show it without one API call per row.
        $invoice_status = (string) ($invoice['status'] ?? '');
        if ($invoice_status !== '') {
            $order->update_meta_data('_alegra_invoice_status', $invoice_status);
        }

        $order->save();

        // REQ-QUEUE-01: éxito ⇒ sale de la cola. El badge se recalcula de forma
        // DIFERIDA (apertura de la pantalla / cron, DEF-11): no una meta query
        // pesada por cada factura creada (NFR-04).
        Invoice_Failure::clear($order);

        // AC-07: write the indexed mapping so a later lookup never scans
        // wp_postmeta.meta_value (O(N²) on a large catalog).
        \Alegra\Connector\Entity_Map::map('invoice', $invoice_id, 'order', (int) $order->get_id());
    }

    /**
     * Cache the Alegra invoice status on the order (saved only when it changes).
     *
     * Used after opening a draft and after the status poll so the admin list can
     * render a truthful badge without a per-row API call.
     *
     * @param array<string, mixed> $invoice
     */
    public function persist_invoice_status(\WC_Order $order, array $invoice): void
    {
        $status = (string) ($invoice['status'] ?? '');
        if ($status === '') {
            return;
        }
        if ((string) $order->get_meta('_alegra_invoice_status', true) === $status) {
            return;
        }
        $order->update_meta_data('_alegra_invoice_status', $status);
        $order->save();
    }

    /**
     * REQ-POLL-02 / D2 §3.5: al mover stock la factura, fija el baseline en WC
     * qty por línea. Sin esto el poll queda congelado (starvation, C27).
     * Exige dueño invoice + pago (DR13); NO hace GET por producto (NFR-04).
     *
     * Público: lo consumen create_invoice(), create_invoice_with_payment() y
     * Admin_Dashboard::ajax_open_invoice_impl().
     */
    public function baseline_products_for_invoice(\WC_Order $order): void
    {
        if (Inventory_Pusher::owner() !== 'invoice') {
            return;   // en adjustment la factura NO mueve stock
        }
        if (!$order->is_paid()) {
            return;   // WC todavía no redujo: el baseline sería mentira (DR13)
        }
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $pid = (int) $product->get_id();
            if ($pid <= 0) {
                continue;
            }
            Inventory_Pusher::set_synced($pid, (int) $product->get_stock_quantity());
            Inventory_Pusher::clear_pending($pid);
        }
    }

    /**
     * D1 §2.2.1 (C2): ¿el plugin emitió un ajuste para alguna línea de este
     * pedido DESPUÉS de su creación? `push_delta()` no conoce el pedido, así que
     * la evidencia es el timestamp por producto `_alegra_stock_adjusted_at`.
     * O(líneas del pedido); no depende del orden de hooks.
     */
    public function order_has_emitted_adjustment(\WC_Order $order): bool
    {
        $created = $order->get_date_created();
        $since   = $created instanceof \DateTimeInterface ? $created->getTimestamp() : 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }
            if (Inventory_Pusher::adjusted_at((int) $product->get_id()) > $since) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add the "invoice voided in Alegra" order note exactly once per invoice.
     *
     * Idempotent: both the webhook and the periodic poll call it, and the poll
     * runs on every cron tick. The invoice id is stored in `_alegra_invoice_void_notified`
     * so a second observation is a no-op. The order status is deliberately left
     * untouched: an Alegra void does not prove the WC order was cancelled, so
     * the merchant decides.
     *
     * @return bool True when a note was written, false when it was already there.
     */
    public function note_invoice_voided(\WC_Order $order, array $invoice): bool
    {
        $invoice_id = (string) ($invoice['id'] ?? $order->get_meta('_alegra_invoice_id', true));

        // `numberTemplate` can be an object or an array depending on the
        // endpoint; isset() keeps a string value from being offset-accessed.
        $number = $invoice_id;
        if (isset($invoice['numberTemplate']['fullNumber'])) {
            $number = (string) $invoice['numberTemplate']['fullNumber'];
        } elseif (isset($invoice['numberTemplate']['number'])) {
            $number = (string) $invoice['numberTemplate']['number'];
        } elseif (isset($invoice['number'])) {
            $number = (string) $invoice['number'];
        }

        if ($invoice_id !== '' && (string) $order->get_meta(self::VOID_NOTIFIED_META, true) === $invoice_id) {
            return false;
        }

        $order->add_order_note(sprintf(
            __('[Alegra] Factura #%s anulada en Alegra. El pedido NO se cancela automáticamente; revisá su estado manualmente.', 'alegra-connector'),
            $number
        ));
        $order->update_meta_data(self::VOID_NOTIFIED_META, $invoice_id);
        $order->save();

        if ($this->logger) {
            $this->logger->info('Order note added: invoice voided in Alegra', [
                'order_id'   => (int) $order->get_id(),
                'invoice_id' => $invoice_id,
            ]);
        }

        return true;
    }

    /**
     * Find an Alegra invoice that belongs to this WC order (AC-14).
     *
     * GET /invoices supports `client_id` but NOT an observations/query filter
     * (see get_invoices.md), so we filter server-side by client and scan the
     * returned page for the "Pedido WooCommerce #<id>" marker written by
     * prepare_invoice_data().
     *
     * @return array<string, mixed>|null
     */
    private function find_existing_invoice(\WC_Order $order, string $client_id): ?array
    {
        $params = [
            'limit'           => 30,
            'order_field'     => 'date',
            'order_direction' => 'DESC',
        ];
        if ($client_id !== '') {
            $params['client_id'] = $client_id;
        }

        $invoices = $this->api->get_invoices($params);
        if (is_wp_error($invoices) || !is_array($invoices)) {
            return null;
        }

        $order_id = (int) $order->get_id();
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $observations = (string) ($invoice['observations'] ?? '');
            // Anchor the id so "#123" never matches "#1234".
            if ($observations !== '' && preg_match('/Pedido WooCommerce #(\d+)/', $observations, $m)) {
                if ((int) $m[1] === $order_id) {
                    return $invoice;
                }
            }
        }

        return null;
    }

    /**
     * Auto-sanado de la caché podrida del CF ante un 400 por client id muerto.
     *
     * D1 / REQ-CF-06. Sólo dispara con 400 + mención de cliente + id CF + una vez.
     * Reusa find_existing_invoice() ANTES del reintento para no duplicar la factura.
     *
     * @param array<string,mixed> $data  Invoice payload sent to Alegra.
     * @return array<string,mixed>|null  El resultado de la factura si se auto-sanó, null si no aplica.
     */
    private function try_self_heal_dead_client(\WC_Order $order, array $data, \WP_Error $error): ?array
    {
        // 1) Sólo un 400.
        $code = (int) (($error->get_error_data()['code'] ?? 0));
        if ($code !== 400) {
            return null;
        }

        // 2) El error debe referenciar al cliente.
        $body = $error->get_error_data()['response'] ?? [];
        $mentions_client = (is_array($body) && isset($body['client']))
            || stripos($error->get_error_message(), 'client') !== false
            || stripos($error->get_error_message(), 'cliente') !== false;
        if (!$mentions_client) {
            return null;
        }

        // 3) El client.id usado debe ser el CF cacheado.
        $old_id = (string) ($data['client']['id'] ?? '');
        if ($old_id === '' || !\Alegra\Connector\Consumidor_Final::is_consumidor_final($old_id)) {
            return null;
        }

        // 4) Reintento ÚNICO por pedido (guard entre requests; el design lo llama
        //    "$retried === false", la firma no lo lleva).
        $guard = 'alegra_cf_self_heal_' . (int) $order->get_id();
        if (get_transient($guard)) {
            return null;
        }
        set_transient($guard, 1, 60);

        // 5) Invalidar + re-resolver con el barrido PAGINADO (FIX-18 / Oracle D9).
        //    NO usar resolve()/get_or_create_id() directo: resolve() pide `limit=5`
        //    y, si el CF está detrás de >5 falsos positivos CONTAINS, lo pierde otra
        //    vez (el mismo bug que REQ-CF-05 arregla). probe() barre por páginas
        //    (scan_candidates, hasta 300) y sólo con un barrido COMPLETO sin match
        //    se cae a crear (get_or_create_id respeta la compuerta: en contexto
        //    automático sólo crea si push_customers_enabled).
        \Alegra\Connector\Consumidor_Final::invalidate_cache();
        $probe = \Alegra\Connector\Consumidor_Final::probe();
        if ($probe['state'] === 'available' && !empty($probe['id'])) {
            $new_id = (string) $probe['id'];
        } elseif ($probe['state'] === 'not_found') {
            $new_id = \Alegra\Connector\Consumidor_Final::get_or_create_id();
        } else {
            // unverified (api_error / truncated): NO crear a ciegas.
            $new_id = false;
        }
        if ($new_id === false || $new_id === '') {
            // T5.6: soltar el id muerto del pedido para no re-loopear + nota accionable.
            $order->delete_meta_data('_billing_alegra_contact_id');
            $order->add_order_note(__('[Alegra] El Consumidor Final cambió en Alegra y no se pudo re-resolver. Revisá el contacto.', 'alegra-connector'));
            $order->save();
            return null;
        }

        // 6) Idempotencia ANTES de reintentar (no duplicar la factura).
        $existing = $this->find_existing_invoice($order, (string) $new_id);
        if ($existing !== null && !empty($existing['id'])) {
            $this->persist_invoice_result($order, (string) $existing['id'], $existing);
            $order->add_order_note(__('[Alegra] Factura recuperada tras re-resolver el Consumidor Final (auto-sanado).', 'alegra-connector'));
            return ['id' => (string) $existing['id'], 'already_exists' => true, 'self_healed' => true];
        }

        // 7) Reintento ÚNICO con el nuevo client.
        $data['client'] = ['id' => $new_id] + (is_array($data['client'] ?? null) ? $data['client'] : []);
        $retry = $this->api->create_invoice($data);
        if (is_wp_error($retry)) {
            $order->add_order_note(sprintf(
                __('[Alegra] No se pudo facturar tras re-resolver el Consumidor Final: %s', 'alegra-connector'),
                $retry->get_error_message()
            ));
            return null;
        }
        if (isset($retry['id'])) {
            $this->persist_invoice_result($order, (string) $retry['id'], $retry);
            $order->add_order_note(__('[Alegra] Consumidor Final re-resuelto y factura creada (auto-sanado).', 'alegra-connector'));
        }
        return $retry;
    }

    /**
     * FIX-3: factura `open` existente del pedido (evita doble conteo).
     *
     * @return array<string,mixed>|null
     */
    private function find_open_invoice_for_order(\WC_Order $order): ?array
    {
        $linked = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($linked !== '' && (string) $order->get_meta('_alegra_invoice_status', true) === 'open') {
            return ['id' => $linked, 'status' => 'open'];
        }
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        $existing = $this->find_existing_invoice($order, $client_id);
        if ($existing !== null && (string) ($existing['status'] ?? '') === 'open') {
            return $existing;
        }
        return null;
    }

    /**
     * Find an Alegra payment already applied to an invoice (AC-14).
     *
     * GET /payments supports `client_id`; we match on the invoice association
     * returned in each payment.
     *
     * @return array<string, mixed>|null
     */
    private function find_existing_payment(string $invoice_id, string $client_id): ?array
    {
        if ($invoice_id === '') {
            return null;
        }

        $params = ['limit' => 30, 'order_direction' => 'DESC'];
        if ($client_id !== '') {
            $params['client_id'] = $client_id;
        }

        $payments = $this->api->get_payments($params);
        if (is_wp_error($payments) || !is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $invoices = $payment['invoices'] ?? [];
            if (!is_array($invoices)) {
                continue;
            }
            foreach ($invoices as $inv) {
                $inv_id = is_array($inv) ? (string) ($inv['id'] ?? '') : (string) $inv;
                if ($inv_id !== '' && $inv_id === $invoice_id) {
                    return $payment;
                }
            }
        }

        return null;
    }

    public function create_invoice_with_payment(\WC_Order $order): array|\WP_Error
    {
        // A payment is recorded separately (POST /payments) after the invoice.
        // Alegra only allows a payment on an OPEN invoice, so when a bank
        // account is configured the invoice is created open instead of the
        // configured draft status.
        //
        // BUG 8: the settings <select> stores '0' for "no account", so both ''
        // and '0' mean UNCONFIGURED. The manual path already rejects both; the
        // auto path must too, otherwise it POSTs bankAccount.id = '0'.
        $payment_account = (string) get_option('alegra_connector_payment_account_id', '');
        // REQ-MAN-1: WooCommerce is the source of truth for whether the order
        // was paid. A payment is recorded only when an account is configured,
        // no payment was recorded before, AND $order->is_paid() is true.
        $will_record_payment = !in_array($payment_account, ['', '0'], true)
            && (string) $order->get_meta('_alegra_payment_id', true) === ''
            && $order->is_paid();

        // B1/Oracle#1 (REVIEW-momus + REVIEW-oracle DEF-1): el `'open'` de este
        // camino NO estaba gated por owner(); con cuenta de pago + pedido pagado
        // la factura nacía `open` y Alegra descontaba stock, y el pusher emitía
        // ADEMÁS el ajuste ⇒ doble descuento automático en adjustment. La
        // apertura SÓLO es válida con dueño invoice (la factura es el mecanismo).
        $will_open = $will_record_payment && Inventory_Pusher::owner() === 'invoice';
        $invoice_result = $this->create_invoice($order, $will_open ? 'open' : null);

        if (is_wp_error($invoice_result)) {
            return $invoice_result;
        }

        // AC-72: in dry-run mode create_invoice() returns ['dry_run' => true]
        // with no `id`; reading $invoice_result['id'] unguarded produced a
        // warning and an empty invoice id. Bail out before touching it.
        if (!isset($invoice_result['id']) || (string) $invoice_result['id'] === '') {
            return $invoice_result;
        }

        if ($will_record_payment) {
            $payment = $this->record_payment_for_invoice($order, (string) $invoice_result['id']);

            // REQ-QUEUE-08: la factura subió pero el pago no ⇒ estado distinto,
            // retriable (el sweep de pagos o el reintento manual lo recuperan).
            if (is_wp_error($payment) || API\Client::write_was_blocked($payment)) {
                $c = [
                    'state'     => 'payment_missing',
                    'code'      => is_wp_error($payment) ? (string) $payment->get_error_code() : (string) ($payment['reason'] ?? 'blocked'),
                    'message'   => is_wp_error($payment) ? wp_strip_all_tags($payment->get_error_message()) : '',
                    'retriable' => true,
                    'persist'   => true,
                ];
                Invoice_Failure::persist($order, $c);
                Invoice_Queue::refresh_count();   // REQ-QUEUE-07.
                if ($this->logger) {
                    $this->logger->warning('Invoice created but payment failed', [
                        'order_id' => (int) $order->get_id(),
                        'code'     => $c['code'],
                    ]);
                }
            }
        } elseif (!in_array($payment_account, ['', '0'], true)
            && (string) $order->get_meta('_alegra_payment_id', true) === '') {
            // REQ-MAN-1: an account is configured and no payment exists, but the
            // order is not marked paid in WooCommerce, so no payment is posted.
            // Tell the merchant why instead of leaving the invoice silently
            // "Por Cobrar".
            $order->add_order_note(__(
                '[Alegra] El pedido no figura pagado en WooCommerce; se creó la factura pero no se registró pago.',
                'alegra-connector'
            ));
        }

        return $invoice_result;
    }

    /**
     * Record a payment against an invoice that ALREADY exists in Alegra.
     *
     * Does NOT create invoices. Idempotent by construction:
     *   1. `_alegra_payment_id` meta guard (already recorded → bail).
     *   2. ensure_invoice_open() — Alegra only accepts payments on OPEN invoices.
     *   3. find_existing_payment() pre-search — recover a committed-but-lost response.
     *   4. prepare_payment_data() + POST /payments.
     *
     * A DRAFT invoice is a deliberate merchant decision (`invoice_status = draft`,
     * e.g. for testing). The AUTOMATIC paths (hourly sweep, real-time reconcile
     * hooks) must NEVER open it: they skip and tell the merchant. Only an
     * explicit manual action passes $allow_open_draft = true.
     *
     * @param bool $allow_open_draft Manual actions pass true; automatic ones false.
     *
     * @return array|\WP_Error The payment payload, or a WP_Error when the POST failed.
     */
    private function record_payment_for_invoice(\WC_Order $order, string $invoice_id, bool $allow_open_draft = false): array|\WP_Error
    {
        if ($invoice_id === '') {
            return new \WP_Error('missing_invoice', __('No hay una factura de Alegra vinculada.', 'alegra-connector'));
        }

        // 1. Meta guard: never post a second payment for the same order.
        $existing_payment_id = (string) $order->get_meta('_alegra_payment_id', true);
        if ($existing_payment_id !== '') {
            return ['id' => $existing_payment_id, 'already_exists' => true];
        }

        // 2. Alegra only accepts a payment on an OPEN invoice. Read the invoice
        //    and decide: a draft is opened ONLY when the caller is an explicit
        //    manual action ($allow_open_draft). The automatic reconciliation
        //    gets a `draft_invoice_not_opened` error and skips it untouched.
        $opened = $this->ensure_invoice_open($invoice_id, $allow_open_draft);
        if (is_wp_error($opened)) {
            if ($opened->get_error_code() === 'draft_invoice_not_opened') {
                return $this->skip_draft_payment($order, $invoice_id);
            }
            // A genuine read/open failure: keep the legacy tolerant behaviour
            // (log it and let the payment POST surface Alegra's own error)
            // instead of silently swallowing it.
            if ($this->logger) {
                $this->logger->warning('Could not read/open the invoice before paying; trying anyway', [
                    'order_id'   => $order->get_id(),
                    'invoice_id' => $invoice_id,
                    'error'      => $opened->get_error_message(),
                ]);
            }
        } else {
            $this->persist_invoice_status($order, $opened);
        }

        // 3. AC-14: recover a payment Alegra committed but whose response was lost.
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        $existing_payment = $this->find_existing_payment($invoice_id, $client_id);
        if ($existing_payment !== null) {
            $order->update_meta_data('_alegra_payment_id', (string) ($existing_payment['id'] ?? ''));
            if (!empty($existing_payment['number'])) {
                $order->update_meta_data('_alegra_payment_number', $existing_payment['number']);
            }
            $order->save();
            $order->add_order_note(sprintf(
                __('[Alegra] Se encontró un pago existente (#%s) para esta factura; no se registró uno nuevo.', 'alegra-connector'),
                $existing_payment['number'] ?? $existing_payment['id'] ?? ''
            ));
            $this->logger->warning('Recovered pre-existing Alegra payment (idempotency pre-search)', [
                'order_id'   => $order->get_id(),
                'payment_id' => $existing_payment['id'] ?? 'unknown',
            ]);
            return $existing_payment;
        }

        // 4. Build the payment from WooCommerce and POST it.
        $payment_data = $this->prepare_payment_data($order, $invoice_id);
        if (empty($payment_data)) {
            return ['skipped' => true, 'reason' => 'no_account'];
        }

        $payment_result = $this->api->create_payment($payment_data);
        if (is_wp_error($payment_result)) {
            // BUG 5: the failure used to be swallowed — the invoice existed
            // but the merchant was never told the payment was not recorded.
            if ($this->logger) {
                $this->logger->error('Payment recording failed after invoice creation', [
                    'order_id'   => $order->get_id(),
                    'invoice_id' => $invoice_id,
                    'error'      => $payment_result->get_error_message(),
                ]);
            }
            $order->add_order_note(sprintf(
                /* translators: 1: Alegra invoice id, 2: the error Alegra returned. */
                __('Alegra: la factura #%1$s se creó, pero el pago NO se pudo registrar (%2$s). Registralo manualmente en Alegra o reintentá con "Registrar pago".', 'alegra-connector'),
                $invoice_id,
                $payment_result->get_error_message()
            ));
            return $payment_result;
        }

        if (API\Client::write_was_blocked($payment_result)) {
            if ($this->logger) {
                $this->logger->warning('Payment recording skipped (write blocked)', [
                    'order_id'   => $order->get_id(),
                    'invoice_id' => $invoice_id,
                    'reason'     => $payment_result['reason'] ?? 'dry_run',
                ]);
            }
            return $payment_result;
        }

        $order->update_meta_data('_alegra_payment_id', (string) ($payment_result['id'] ?? ''));
        if (!empty($payment_result['number'])) {
            $order->update_meta_data('_alegra_payment_number', $payment_result['number']);
        }
        $order->save();
        $order->add_order_note(sprintf(
            __('Pago Alegra #%s registrado.', 'alegra-connector'),
            $payment_result['number'] ?? $payment_result['id'] ?? ''
        ));
        $this->logger->info('Payment recorded in Alegra', [
            'order_id' => $order->get_id(),
            'payment_id' => $payment_result['id'] ?? 'unknown',
        ]);

        return $payment_result;
    }

    /**
     * Skip recording a payment on a DRAFT invoice without touching its state.
     *
     * The automatic reconciliation must never open a draft: the merchant left
     * it in draft on purpose (invoice_status = draft). Report it once per
     * invoice on the order (the hourly sweep runs repeatedly) and log it, then
     * let the caller count the order as skipped.
     *
     * @return array{skipped:bool,reason:string}
     */
    private function skip_draft_payment(\WC_Order $order, string $invoice_id): array
    {
        if ($this->logger) {
            $this->logger->info('Payment reconcile skipped: invoice is a draft (automatic paths never open drafts)', [
                'order_id'   => $order->get_id(),
                'invoice_id' => $invoice_id,
            ]);
        }

        // One note per invoice, so an hourly sweep does not spam the order.
        if ((string) $order->get_meta('_alegra_draft_payment_notified', true) !== $invoice_id) {
            $order->add_order_note(sprintf(
                /* translators: %s: Alegra invoice id */
                __('[Alegra] La factura #%s está en BORRADOR. La conciliación automática no la abrió ni registró el pago: si querés cobrarla, abrilo manualmente con "Abrir factura" y luego "Registrar pago".', 'alegra-connector'),
                $invoice_id
            ));
            $order->update_meta_data('_alegra_draft_payment_notified', $invoice_id);
            $order->save();
        }

        return ['skipped' => true, 'reason' => 'draft_not_opened'];
    }

    /**
     * Reconcile the payment of an order whose invoice ALREADY exists in Alegra.
     *
     * This is the payment-only path used by the always-active WC hooks and the
     * retry sweep. It NEVER creates an invoice (REQ-REC-5): when no invoice is
     * linked the guard returns before touching the API. Idempotent via the
     * per-order lock plus the shared record_payment_for_invoice() guards.
     *
     * Guard (REQ-REC-2): invoice linked AND no payment yet AND WC says paid.
     *
     * @return array|\WP_Error The payment payload, a ['skipped' => true, ...]
     *                        array, or a WP_Error from the POST.
     */
    public function reconcile_payment_only(\WC_Order $order): array|\WP_Error
    {
        $order_id   = (int) $order->get_id();
        $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        $payment_id = (string) $order->get_meta('_alegra_payment_id', true);

        if ($invoice_id === '' || $payment_id !== '' || !$order->is_paid()) {
            return ['skipped' => true, 'reason' => 'guard'];
        }

        $account = (string) get_option('alegra_connector_payment_account_id', '');
        if (in_array($account, ['', '0'], true)) {
            return ['skipped' => true, 'reason' => 'no_account'];
        }

        // Per-order lock: two concurrent triggers (hook + sweep) cannot both
        // post a payment for the same order (REQ-REC-6).
        $lock_key = 'alegra_payment_lock_' . $order_id;
        $token = Controller::acquire_lock($lock_key, 60);
        if ($token === false) {
            return ['skipped' => true, 'reason' => 'locked'];
        }

        try {
            // Automatic path: a draft invoice is NEVER opened here. Only the
            // explicit manual buttons pass $allow_open_draft = true.
            return $this->record_payment_for_invoice($order, $invoice_id, false);
        } finally {
            Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * Retry sweep: reconcile every paid order with a linked invoice and no
     * payment yet (REQ-REC-4). Bounded batch, kill-switch and cancellation
     * aware. Called by the hourly `alegra_connector_payment_reconcile` cron.
     *
     * @return array{checked:int,reconciled:int,errors:int,skipped?:string}
     */
    public function reconcile_missing_payments(): array
    {
        if (!get_option('alegra_connector_payment_reconcile_enabled', true)) {
            return ['reconciled' => 0, 'skipped' => 'skipped_disabled'];
        }

        $limit = max(1, (int) get_option('alegra_connector_payment_reconcile_batch', 20));

        // A payment-less order may have no `_alegra_payment_id` row at all, so
        // the clause matches both an explicit empty value and a missing key.
        $order_ids = wc_get_orders([
            'limit'   => $limit,
            'status'  => ['processing', 'completed'],
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => '_alegra_invoice_id',
                    'value'   => '',
                    'compare' => '!=',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_alegra_payment_id',
                        'value'   => '',
                        'compare' => '=',
                    ],
                    [
                        'key'     => '_alegra_payment_id',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
        ]);

        $result = ['checked' => 0, 'reconciled' => 0, 'errors' => 0, 'draft_skipped' => 0];
        foreach ((array) $order_ids as $order_id) {
            // Re-check the kill switch and the cancellation transient so an
            // in-flight sweep stops (REQ-REC-4).
            if (\Alegra\Connector\Kill_Switch::is_active() || get_transient('alegra_sync_cancelled')) {
                break;
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $result['checked']++;
            $r = $this->reconcile_payment_only($order);
            if (is_wp_error($r)) {
                $result['errors']++;
            } elseif (!empty($r['skipped'])) {
                // A draft is a deliberate decision: count it separately so the
                // merchant can see why the sweep did not pay those orders.
                if (($r['reason'] ?? '') === 'draft_not_opened') {
                    $result['draft_skipped']++;
                }
                continue;
            } else {
                $result['reconciled']++;
            }
        }

        $this->logger->info('Payment reconcile completed', $result);
        return $result;
    }

    /**
     * REQ-QUEUE-06 / design §4.6: reintenta SÓLO los `failed_retriable`
     * vencidos, con backoff exponencial y tope de intentos; al tope ⇒
     * `failed_permanent`. Permanentes/bloqueados nunca auto-reintentan.
     *
     * @return array{checked:int,retried:int,resolved:int,failed:int,errors:int,skipped?:string}
     */
    public function retry_failed_invoices(): array
    {
        $max   = max(1, (int) get_option('alegra_connector_invoice_retry_max_attempts', 5));
        $batch = max(1, (int) get_option('alegra_connector_invoice_retry_batch', 20));
        $now   = time();

        $ids = wc_get_orders([
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => $batch,
            'return'     => 'ids',
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => Invoice_Failure::META_STATE, 'value' => 'failed_retriable'],
                // B3: `persist()` siembra META_ATTEMPTS=0 en el primer fallo, así el
                // INNER JOIN de WP_Meta_Query SÍ matchea el pedido recién fallado.
                ['key' => Invoice_Failure::META_ATTEMPTS, 'value' => $max, 'compare' => '<', 'type' => 'NUMERIC'],
                ['relation' => 'OR',
                    ['key' => Invoice_Failure::META_NEXT, 'compare' => 'NOT EXISTS'],
                    ['key' => Invoice_Failure::META_NEXT, 'value' => gmdate('Y-m-d H:i:s', $now), 'compare' => '<=', 'type' => 'DATETIME'],
                ],
            ],
        ]);

        $out = ['checked' => 0, 'retried' => 0, 'resolved' => 0, 'failed' => 0, 'errors' => 0];

        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            if (\Alegra\Connector\Kill_Switch::is_active()) {
                $out['skipped'] = 'kill_switch';
                break;
            }
            if (\Alegra\Connector\Run_Context::should_stop()) {
                $out['skipped'] = 'cancelled';
                break;
            }

            $out['checked']++;
            $attempts = (int) $order->get_meta(Invoice_Failure::META_ATTEMPTS, true) + 1;
            $order->update_meta_data(Invoice_Failure::META_ATTEMPTS, $attempts);

            $result = $this->create_invoice_with_payment($order); // contexto automático

            if (!is_wp_error($result) && !API\Client::write_was_blocked($result)) {
                // persist_invoice_result() ya limpió el ledger (T4.5); un
                // `already_exists` (id ya presente) no pasa por ahí, así que se
                // limpia acá para que el pedido no vuelva a la cola (REQ-QUEUE-09).
                Invoice_Failure::clear($order);
                $out['retried']++;
                $out['resolved']++;
                continue;
            }

            $c = Invoice_Failure::classify($result);
            if ($c['state'] === 'blocked' || $c['retriable'] === false) {
                $c['state'] = $c['state'] === 'blocked' ? 'blocked' : 'failed_permanent';
                Invoice_Failure::persist($order, $c);
                $out['failed']++;
                continue;
            }

            if ($attempts >= $max) {
                $c['state']     = 'failed_permanent';
                $c['retriable'] = false;
                Invoice_Failure::persist($order, $c);
            } else {
                $delay = self::RETRY_BACKOFF[min($attempts - 1, count(self::RETRY_BACKOFF) - 1)];
                Invoice_Failure::persist($order, $c, $now + $delay);
            }
            $out['retried']++;
            $out['failed']++;
        }

        // REQ-QUEUE-07 / NFR-04: un solo refresco por batch (no uno por pedido).
        if ($out['checked'] > 0) {
            Invoice_Queue::refresh_count();
        }

        return $out;
    }

    /**
     * T5.4: pedido pagado que contiene el producto y NO tiene una factura `open`.
     * Coste acotado: `wc_get_orders(limit=20)`; el chequeo Alegra-side
     * (`find_existing_invoice`, vía `find_open_invoice_for_order()`) sólo corre
     * para pedidos que contienen el producto y no tienen una factura vinculada `open`.
     */
    public function find_paid_order_without_open_invoice(int $product_id): ?\WC_Order
    {
        if ($this->api === null) {
            return null;
        }
        $ids = wc_get_orders([
            'status'  => ['processing', 'completed', 'on-hold'],
            'limit'   => 20,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order instanceof \WC_Order || !$order->is_paid()) {
                continue;
            }
            // ¿Contiene el producto? Se chequea ANTES del GET a Alegra (acota el coste).
            $has_product = false;
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_product_id() === $product_id
                    || (int) $item->get_variation_id() === $product_id) {
                    $has_product = true;
                    break;
                }
            }
            if (!$has_product) {
                continue;
            }
            // Sin factura `open`: ni vinculada (`_alegra_invoice_id`) ni en Alegra
            // (`find_existing_invoice`, vía `find_open_invoice_for_order()`).
            if ($this->find_open_invoice_for_order($order) !== null) {
                continue;
            }
            return $order;
        }
        return null;
    }

    /**
     * Open a DRAFT invoice in Alegra and return its resulting state.
     *
     * No-op when the invoice is already non-draft (returns it unchanged).
     *
     * Alegra documents NO clean draft→open endpoint:
     *  - `POST /invoices/{id}/open` is "revertir la anulación de una factura"
     *    (an UN-VOID), not draft→open.
     *  - `PUT /invoices/{id}` does NOT list `status` in its schema; `status` is
     *    a CREATION attribute of `POST /invoices`.
     * The call below is therefore a best effort that VERIFIES the result by
     * re-reading the invoice, with a fallback. See the inline comment.
     *
     * @see https://developer.alegra.com/reference/put_invoices-id.md
     * @see https://developer.alegra.com/reference/post_invoices-id-open.md
     *
     * ONLY explicit manual actions may pass a draft. `record_payment_for_invoice()`
     * calls this with $allow_draft = false on the automatic reconciliation, so a
     * deliberate draft is never opened behind the merchant's back.
     *
     * @param bool $allow_draft When false, a draft returns a
     *                         `draft_invoice_not_opened` WP_Error untouched.
     *
     * @return array|\WP_Error The invoice after opening, or the API error.
     */
    public function ensure_invoice_open(string $invoice_id, bool $allow_draft = true): array|\WP_Error
    {
        if ($invoice_id === '') {
            return new \WP_Error('missing_invoice', __('No hay una factura de Alegra vinculada.', 'alegra-connector'));
        }

        $invoice = $this->api->get_invoice($invoice_id);
        if (is_wp_error($invoice)) {
            return $invoice;
        }
        if (!is_array($invoice)) {
            return new \WP_Error('invoice_not_found', __('No se pudo leer la factura en Alegra.', 'alegra-connector'));
        }
        if ((string) ($invoice['status'] ?? '') !== 'draft') {
            // Already open (or any non-draft state): nothing to do.
            return $invoice;
        }

        if (!$allow_draft) {
            return new \WP_Error(
                'draft_invoice_not_opened',
                __('La factura está en borrador y la conciliación automática no la abre.', 'alegra-connector')
            );
        }

        // Alegra documents NO clean draft→open endpoint:
        //  - POST /invoices/{id}/open is "revertir la anulación" (an UN-VOID).
        //  - PUT /invoices/{id} does NOT list `status` in its schema; `status`
        //    is a CREATION attribute of POST /invoices.
        // So try, in order, and VERIFY by re-reading the invoice:
        //  1. PUT /invoices/{id} {"status":"open"} — semantic intent; the POST
        //     schema names `status` with the same `open`/`draft` enum.
        //  2. POST /invoices/{id}/open — the legacy call, which empirically
        //     converted a draft for this merchant. Only reached on a DRAFT, so
        //     the documented un-void semantics do not apply.
        //  3. Still a draft → explicit error, never a fake success.
        $this->api->update_invoice($invoice_id, ['status' => 'open']);
        $fresh = $this->api->get_invoice($invoice_id);
        if (!is_wp_error($fresh) && is_array($fresh) && (string) ($fresh['status'] ?? '') !== 'draft') {
            return $fresh;
        }

        if ($this->logger) {
            $this->logger->warning('PUT status=open did not open the draft; trying POST /open', [
                'invoice_id' => $invoice_id,
            ]);
        }
        $this->api->open_invoice($invoice_id);
        $fresh = $this->api->get_invoice($invoice_id);
        if (!is_wp_error($fresh) && is_array($fresh) && (string) ($fresh['status'] ?? '') !== 'draft') {
            return $fresh;
        }

        if ($this->logger) {
            $this->logger->warning('Could not open a draft invoice with either endpoint', [
                'invoice_id' => $invoice_id,
            ]);
        }

        return new \WP_Error(
            'invoice_still_draft',
            __('Alegra no abrió la factura; sigue en borrador. Abrilo desde la interfaz de Alegra.', 'alegra-connector')
        );
    }

    /**
     * Create a credit note for the whole order (legacy entry point).
     *
     * Naming-drift collapse (root cause of AC-04): this used to be a SECOND,
     * independent credit-note implementation with its own idempotency key
     * (`_alegra_credit_note_id`) and its own payload shape. Together with the
     * per-refund path it let one full refund issue two credit notes. It now
     * delegates to the single implementation, create_credit_note_for_refund(),
     * so every caller shares one payload builder, one cumulative cap and one
     * idempotency key.
     */
    public function create_credit_note(\WC_Order $order): array|\WP_Error
    {
        $order_id = (int) $order->get_id();

        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($alegra_invoice_id === '') {
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        // Credit whatever has not been credited yet. The cumulative
        // _alegra_credited_amount marker (maintained by the shared
        // implementation) makes this idempotent: a second call credits 0.
        $credited_before = (float) $order->get_meta('_alegra_credited_amount', true);
        $amount = round((float) $order->get_total() - $credited_before, 2);
        if ($amount <= 0) {
            return new \WP_Error(
                'credit_note_already_issued',
                __('Ya se emitió una nota de crédito para este pedido.', 'alegra-connector')
            );
        }

        return $this->create_credit_note_for_refund(
            $order_id,
            $amount,
            sprintf(__('Reembolso de pedido #%d - WooCommerce', 'alegra-connector'), $order_id),
            0
        );
    }

    /**
     * Create a credit note for a WooCommerce refund.
     *
     * Idempotent: tracks the cumulative credited amount in _alegra_credited_amount
     * and refuses to over-credit the original invoice.
     *
     * @param int    $order_id   The WC order id.
     * @param float  $amount     The refund amount (positive).
     * @param string $reason     Optional reason, becomes the item description.
     * @param int    $refund_id  Optional WC refund id, for idempotency.
     * @return array|\WP_Error   The Alegra credit note, or an error.
     */
    public function create_credit_note_for_refund(int $order_id, float $amount, string $reason = '', int $refund_id = 0): array|\WP_Error
    {
        if ($amount <= 0) {
            return new \WP_Error(
                'invalid_amount',
                __('El monto del reembolso debe ser mayor a cero.', 'alegra-connector')
            );
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('invalid_order', __('Pedido no válido.', 'alegra-connector'));
        }

        $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
        if ($invoice_id === '') {
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        // Idempotency: this specific refund was already credited.
        // Naming-drift collapse: State_Sync (the refund owner) and this class
        // used two different per-refund keys for the same concept, so their
        // guards never composed. Unify on State_Sync::REFUND_META_FMT.
        $refund_meta_key = sprintf(\Alegra\Connector\State_Sync::REFUND_META_FMT, $refund_id);
        if ($refund_id > 0) {
            $stored = $order->get_meta($refund_meta_key, true);
            if (!empty($stored)) {
                return ['id' => (string) $stored, 'already_exists' => true];
            }
        }

        $invoice = $this->api->get_invoice($invoice_id);

        // Cumulative cap: never credit more than the original invoice total.
        $credited = (float) $order->get_meta('_alegra_credited_amount', true);
        $invoice_total = (!is_wp_error($invoice) && isset($invoice['total']))
            ? (float) $invoice['total']
            : (float) $order->get_total();
        if ($credited + $amount > $invoice_total + 0.01) {
            return new \WP_Error(
                'refund_exceeds_invoice',
                __('El monto acumulado de reembolsos supera el total de la factura.', 'alegra-connector')
            );
        }

        $is_full_refund = $amount >= ($invoice_total - 0.01);

        if ($is_full_refund && !is_wp_error($invoice) && is_array($invoice) && !empty($invoice['items']) && is_array($invoice['items'])) {
            // Full refund: reuse the original invoice lines verbatim.
            $items = [];
            foreach ($invoice['items'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $items[] = array_filter([
                    'id'          => $line['id']          ?? null,
                    'name'        => $line['name']        ?? null,
                    'description' => $line['description'] ?? null,
                    'price'       => $line['price']       ?? null,
                    'quantity'    => $line['quantity']    ?? null,
                    'tax'         => $line['tax']         ?? null,
                    'discount'    => $line['discount']    ?? null,
                ], static fn($v) => $v !== null);
            }
        } else {
            // BUG 1: `items[].id` is obligatory for credit notes
            // (https://developer.alegra.com/reference/post_credit-notes.md), so
            // a free-text line is rejected. Resolution:
            //  - single-line invoice whose value covers the refund -> reuse that
            //    line's id (semantically exact: same product, partial value);
            //  - otherwise (multi-product order, or refund larger than the one
            //    line) -> the shared generic "Ajuste" service item, resolved
            //    find-or-create by reference so it is created at most once.
            $items = [];

            $invoice_items = (!is_wp_error($invoice) && is_array($invoice) && isset($invoice['items']) && is_array($invoice['items']))
                ? array_values($invoice['items'])
                : [];
            $single_line = count($invoice_items) === 1 ? $invoice_items[0] : null;

            if (is_array($single_line) && !empty($single_line['id'])) {
                $line_value = (float) ($single_line['price'] ?? 0) * (float) ($single_line['quantity'] ?? 0);
                if ($line_value > 0 && $amount <= $line_value + 0.01) {
                    $items[] = [
                        'id'       => (string) $single_line['id'],
                        'price'    => round($amount, 2),
                        'quantity' => 1,
                    ];
                }
            }

            if ($items === []) {
                $refund_item_id = $this->resolve_generic_item_id(
                    self::GENERIC_ITEM_REFERENCE,
                    __('Ajuste', 'alegra-connector')
                );
                if ($refund_item_id === '') {
                    return new \WP_Error(
                        'refund_item_unlinked',
                        __('No se pudo resolver el ítem "Ajuste" en Alegra para la nota de crédito.', 'alegra-connector')
                    );
                }
                $items[] = [
                    'id'          => $refund_item_id,
                    'name'        => __('Reembolso parcial', 'alegra-connector'),
                    'description' => $reason !== '' ? $reason : __('Reembolso parcial', 'alegra-connector'),
                    'price'       => round($amount, 2),
                    'quantity'    => 1,
                ];
            }
        }

        // Resolve the client — required by POST /credit-notes.
        $client_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
        if ($client_id === '') {
            $cf = \Alegra\Connector\Consumidor_Final::get_or_create_id();
            $client_id = $cf !== false ? (string) $cf : '';
        }
        if ($client_id === '') {
            return new \WP_Error(
                'customer_unresolved',
                __('No se pudo resolver el cliente para la nota de crédito.', 'alegra-connector')
            );
        }

        $data = [
            'date'     => date('Y-m-d'),
            'client'   => ['id' => $client_id],
            'invoices' => [[
                'id'     => $invoice_id,
                'amount' => round($amount, 2),
            ]],
            'items'    => $items,
        ];

        $result = $this->api->create_credit_note($data);

        if (is_wp_error($result)) {
            if ($this->logger) {
                $this->logger->error('Credit note for refund failed', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'error'     => $result->get_error_message(),
                ]);
            }
            return $result;
        }

        // Dry Run / Write Gate: the credit note was NOT created. Do not mark the
        // refund as credited, do not store a refund meta id, and do not claim
        // success.
        if (API\Client::write_was_blocked($result)) {
            $order->add_order_note(__('Alegra (modo de prueba): no se creó la nota de crédito. Desactiva el modo de prueba para facturar de verdad.', 'alegra-connector'));
            if ($this->logger) {
                $this->logger->warning('Credit note for refund skipped (write blocked)', [
                    'order_id'  => $order_id,
                    'refund_id' => $refund_id,
                    'amount'    => $amount,
                    'reason'    => $result['reason'] ?? 'dry_run',
                ]);
            }
            return $result;
        }

        $cn_id = (string) ($result['id'] ?? '');

        $order->update_meta_data('_alegra_credited_amount', $credited + $amount);
        if ($refund_id > 0) {
            $order->update_meta_data($refund_meta_key, $cn_id);
        }
        $order->save();

        $order->add_order_note(sprintf(
            __('Nota de crédito Alegra #%s creada por reembolso de %s.', 'alegra-connector'),
            $cn_id,
            number_format_i18n($amount, 2)
        ));

        if ($this->logger) {
            $this->logger->info('Credit note for refund created in Alegra', [
                'order_id'       => $order_id,
                'refund_id'      => $refund_id,
                'credit_note_id' => $cn_id,
                'amount'         => $amount,
            ]);
        }

        return $result;
    }

    public function void_invoice(\WC_Order $order): array|\WP_Error
    {
        $alegra_invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);

        if ($alegra_invoice_id === '') {
            return new \WP_Error('no_invoice', __('El pedido no tiene una factura de Alegra vinculada', 'alegra-connector'));
        }

        $result = $this->api->void_invoice(
            $alegra_invoice_id,
            sprintf(__('Cancelada desde WooCommerce - Pedido #%d', 'alegra-connector'), $order->get_id())
        );

        if (!is_wp_error($result)) {
            $order->add_order_note(sprintf(
                __('Factura Alegra #%s anulada.', 'alegra-connector'),
                $alegra_invoice_id
            ));
            // Keep the cached status truthful so the orders list stops showing
            // the invoice as active. Only when Alegra confirms the void.
            if (($result['status'] ?? '') === 'void') {
                $order->update_meta_data('_alegra_invoice_status', 'void');
                $order->save();
            }
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
            $alegra_id = (string) $order->get_meta('_alegra_invoice_id', true);
            if ($alegra_id !== '') {
                continue;
            }

            // REQ-MAN-3: "Facturar pendientes" must register the payment of
            // paid orders, so it goes through the payment-capable path. The
            // is_paid() guard inside decides whether a payment is posted.
            $sync_result = $this->create_invoice_with_payment($order);
            if (is_wp_error($sync_result)) {
                $result['errors']++;
            } else {
                $result['synced']++;
            }
        }

        $this->logger->info('Orders sync completed', $result);

        return $result;
    }

    private function prepare_invoice_data(\WC_Order $order, ?string $status_override = null): array|\WP_Error
    {
        // Sync customer first if needed
        $customer_alegra_id = $this->ensure_customer_synced($order);
        if ($customer_alegra_id === '') {
            return new \WP_Error(
                'customer_unresolved',
                __('No se pudo resolver el cliente en Alegra ni usar el Consumidor Final. La factura no se creó.', 'alegra-connector')
            );
        }

        $client_data = $this->build_client_data($order, $customer_alegra_id);

        // AC-10: invoice lines require an Alegra item id; abort if unresolvable.
        $items = $this->prepare_invoice_items($order);
        if (is_wp_error($items)) {
            return $items;
        }

        // Alegra creates the invoice as a draft when `status` is omitted and no
        // payments are sent. The default is therefore draft so the merchant can
        // review it; an OPEN invoice is only forced when a payment is applied.
        $status = $status_override ?? (string) get_option('alegra_connector_invoice_status', 'draft');
        if (!in_array($status, ['draft', 'open'], true)) {
            $status = 'draft';
        }

        $data = [
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : date('Y-m-d'),
            'dueDate' => $this->calculate_due_date($order),
            'client' => $client_data,
            'items' => $items,
            'currency' => ['code' => $order->get_currency() ?: get_option('alegra_connector_currency', 'COP')],
            'observations' => sprintf(__('Pedido WooCommerce #%d', 'alegra-connector'), $order->get_id()),
            'status' => $status,
        ];

        // Number template (auto-detect preferred)
        $template = $this->get_preferred_number_template();
        if ($template !== null && $template !== '') {
            $data['numberTemplate'] = ['id' => $template];
        }

        // Payment term
        $term = $this->get_default_payment_term();
        if ($term !== '') {
            $data['term'] = $term;
        }

        // Warehouse
        $warehouse = (string) get_option('alegra_connector_warehouse_id', '');
        if ($warehouse !== '' && get_option('alegra_connector_warehouse_enabled')) {
            $data['warehouse'] = $warehouse;
        }

        // NOTE: this plugin creates a normal Alegra invoice only. It does NOT
        // emit electronic invoices to the DIAN, so no DIAN-specific field is
        // ever sent. The merchant emits/stamps from Alegra when they need to.
        return $data;
    }

    /**
     * Resolve the Alegra contact for an order.
     *
     * 7-step algorithm:
     *  1. Cached alegra_contact_id (user_meta or order_meta) -> return
     *  2. Lookup by email in Alegra -> link + return
     *  3. Lookup by identification (new format) OR legacy billing_nit -> link + return
     *  4. Build the payload from billing_alegra_* (registered) or _billing_alegra_* (guest).
     *     If critical data is missing -> step 6
     *  5. POST /contacts (with 2039 retry) -> persist + return. On failure -> step 6
     *  6. Consumidor Final fallback -> persist + return
     *  7. (persist happens in each branch)
     *
     * @return string The resolved Alegra contact id, or '' on total failure.
     */
    private function ensure_customer_synced(\WC_Order $order): string
    {
        $order_id = (int) $order->get_id();

        // WC_Order::get_user() returns WP_User|false (false for a guest order
        // with customer_id 0). Normalize to ?WP_User so the typed helpers below
        // (persist_contact_id, collect_billing_values, ...) accept it; every
        // falsy branch already means "guest", so behavior is unchanged.
        $customer = $order->get_user();
        if (!$customer instanceof \WP_User) {
            $customer = null;
        }

        // Customer resolution mode: auto (default) | always_generic | require_data.
        // `require_data` is enforced at checkout and disables the Consumidor
        // Final fallback here (see step 6).
        $mode = (string) get_option('alegra_connector_customer_resolution_mode', 'auto');

        // Mode: always use the generic client (Consumidor Final).
        if ($mode === 'always_generic') {
            $cf = \Alegra\Connector\Consumidor_Final::get_or_create_id();
            if ($cf !== false && $cf !== '') {
                $this->persist_contact_id($order, $customer, (string) $cf);
                return (string) $cf;
            }
            return '';
        }

        // Step 1 — cached contact id (order meta, then user meta).
        $cached = (string) $order->get_meta('_billing_alegra_contact_id', true);
        if ($cached !== '') {
            return $cached;
        }
        if ($customer) {
            $cached = (string) get_user_meta($customer->ID, 'alegra_contact_id', true);
            if ($cached !== '') {
                $order->update_meta_data('_billing_alegra_contact_id', $cached);
                $order->save();
                return $cached;
            }
        }

        // Step 2 — lookup by email.
        // AC-50: `email` is NOT a documented listContacts filter (the documented
        // ones are `query` and `identification`). Use `query` and verify EVERY
        // returned candidate; an undocumented param can be ignored by the API,
        // which would otherwise return the first N contacts and false-match.
        $email = $customer ? $customer->user_email : $order->get_billing_email();
        if ($email !== '') {
            $contacts = $this->api->get_contacts(['query' => $email, 'limit' => 30]);
            if (!is_wp_error($contacts) && !empty($contacts)) {
                $matches = [];
                foreach ($contacts as $c) {
                    if (isset($c['id']) && isset($c['email']) && strcasecmp((string) $c['email'], $email) === 0) {
                        $matches[] = (string) $c['id'];
                    }
                }

                if (count($matches) === 1) {
                    $this->persist_contact_id($order, $customer, $matches[0]);
                    return $matches[0];
                }

                if (count($matches) > 1 && $this->logger) {
                    // Ambiguous: several contacts share this email. Do not pick
                    // arbitrarily — fall through to the identification lookup.
                    $this->logger->warning('Multiple Alegra contacts share this email; deferring to identification', [
                        'order_id' => $order_id,
                        'email'    => $email,
                        'matches'  => count($matches),
                    ]);
                }
            }
        }

        // BUG 1 (visibility): track WHY the customer could not be created so the
        // Consumidor Final fallback note names the real reason (an API
        // rejection) instead of staying silent or blaming missing data.
        $contact_create_error = '';
        $create_attempted = false;

        // Steps 3-5 run under an atomic lock to avoid duplicate contact creation.
        $lock_key = 'alegra_contact_create_lock_' . $order_id;
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            usleep(500000);
            // Re-check cache once after the concurrent request had time to persist.
            $cached = (string) $order->get_meta('_billing_alegra_contact_id', true);
            if ($cached !== '') {
                return $cached;
            }
            // Fall through to the Consumidor Final fallback.
        } else {
            try {
                // Step 3a — lookup by identification (new format).
                $idtype = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_idtype', true) : (string) $order->get_meta('_billing_alegra_idtype', true);
                $idnum  = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_identification', true) : (string) $order->get_meta('_billing_alegra_identification', true);
                $dv     = $customer ? (string) get_user_meta($customer->ID, 'billing_alegra_dv', true) : (string) $order->get_meta('_billing_alegra_dv', true);
                if ($idtype !== '' && $idnum !== '') {
                    $contact = $this->api->find_contact_by_identification($idtype, $idnum, $dv !== '' ? $dv : null);
                    if ($contact && isset($contact['id'])) {
                        $found = (string) $contact['id'];
                        $this->persist_contact_id($order, $customer, $found);
                        return $found;
                    }
                }

                // Step 3b — legacy billing_nit lookup (registered customers
                // only). Customers::update_customer_from_alegra() writes
                // billing_nit to USER meta; the guest branch used to read an
                // order meta key (`billing_nit`, no underscore) that nothing
                // ever writes, so it was dead (AC-76).
                $nit = $customer ? (string) get_user_meta($customer->ID, 'billing_nit', true) : '';
                if ($nit !== '') {
                    $contacts = $this->api->get_contacts(['identification' => $nit, 'limit' => 5]);
                    if (!is_wp_error($contacts) && !empty($contacts)) {
                        foreach ($contacts as $c) {
                            if (isset($c['id'])) {
                                $found = (string) $c['id'];
                                $this->persist_contact_id($order, $customer, $found);
                                return $found;
                            }
                        }
                    }
                }

                // Steps 4-5 — build the payload and create the contact.
                // AC-26: validate() (not has_critical_data()) so a LEGAL_ENTITY
                // with an enabled-but-empty `company` is rejected here instead of
                // producing a nameless contact that Alegra answers with a 400.
                $values = $this->collect_billing_values($order, $customer);
                $validation = \Alegra\Connector\Billing_Fields::validate($values);
                if (!is_wp_error($validation)) {
                    $payload = $customer
                        ? \Alegra\Connector\Billing_Fields::build_contact_payload($customer)
                        : \Alegra\Connector\Billing_Fields::build_guest_contact_payload($order);
                    if (!is_wp_error($payload)) {
                        $create_attempted = true;
                        $result = $this->api->create_contact_with_2039_retry($payload);
                        if (!is_wp_error($result) && isset($result['id'])) {
                            $found = (string) $result['id'];
                            $this->persist_contact_id($order, $customer, $found);
                            return $found;
                        }
                        if (is_wp_error($result)) {
                            // Remember the REAL reason so the fallback note can
                            // distinguish "Alegra rejected the contact" from
                            // "the customer is missing billing data".
                            $contact_create_error = $result->get_error_message();
                            $this->logger->warning('Contact creation failed, falling back to Consumidor Final', [
                                'order_id' => $order_id,
                                'error'    => $contact_create_error,
                            ]);
                        } elseif (API\Client::write_was_blocked($result)) {
                            $contact_create_error = ($result['reason'] ?? 'dry_run') === 'kill_switch'
                                ? __('el plugin está desconectado (kill switch activo)', 'alegra-connector')
                                : __('modo de prueba (dry run) activo', 'alegra-connector');
                        }
                    }
                }
            } finally {
                Controller::release_lock($lock_key, $token);
            }
        }

        // Step 6 — Consumidor Final fallback. Disabled in `require_data` mode so
        // the invoice aborts with `customer_unresolved` instead of silently
        // invoicing a generic consumer.
        if ($mode !== 'require_data') {
            $cf = \Alegra\Connector\Consumidor_Final::get_or_create_id();
            if ($cf !== false && $cf !== '') {
                $this->persist_contact_id($order, $customer, (string) $cf);
                $this->logger->info('Using Consumidor Final for order', ['order_id' => $order_id]);
                // Visibility: in `auto` mode this fallback is UNINTENTIONAL, so
                // the merchant must learn that the invoice went to the generic
                // consumer instead of the customer. `always_generic` returned
                // early above (intentional choice — no note) and `require_data`
                // is excluded here (it aborts instead of falling back).
                if ($mode === 'auto') {
                    $this->add_consumidor_final_fallback_note($order, $customer, $contact_create_error, $create_attempted);
                }
                return (string) $cf;
            }
            $this->logger->error('Consumidor Final could not be resolved', ['order_id' => $order_id]);
        } else {
            $this->logger->error('Customer data required but could not be resolved', ['order_id' => $order_id]);
        }
        return '';
    }

    /**
     * Add an order note when the Consumidor Final fallback is UNINTENTIONAL
     * (resolution mode `auto`).
     *
     * The note names the REAL reason:
     *  - an API rejection of the contact create (e.g. a Colombian account with
     *    e-invoicing rejecting a contact) — the merchant must see Alegra's
     *    error, not a "missing data" message that does not apply;
     *  - missing billing data (the identification the customer never entered);
     *  - a concurrent sync that prevented the attempt.
     */
    private function add_consumidor_final_fallback_note(
        \WC_Order $order,
        ?\WP_User $customer,
        string $contact_create_error = '',
        bool $create_attempted = false
    ): void {
        // 1. Alegra rejected the contact create. Surface the API reason.
        if ($contact_create_error !== '') {
            $order->add_order_note(sprintf(
                /* translators: %s: the error Alegra returned when creating the contact. */
                __('Alegra: no se pudo crear el contacto del cliente en Alegra (%s). La factura se emitirá a nombre del Consumidor Final. Corregí el dato o la conexión y volvé a facturar para emitirla a nombre del cliente.', 'alegra-connector'),
                $contact_create_error
            ));
            return;
        }

        // 2. Missing billing data.
        $missing = $this->missing_billing_field_labels($order, $customer);
        if ($missing !== []) {
            $order->add_order_note(sprintf(
                /* translators: %s: the billing field(s) the customer is missing, e.g. "número de documento". */
                __('Alegra: la factura se emitirá a nombre del Consumidor Final porque el cliente no tiene %s registrado. Si necesitas la factura a nombre del cliente, agrega su cédula/NIT y vuelve a facturar.', 'alegra-connector'),
                implode(' ni ', $missing)
            ));
            return;
        }

        // 3. Complete data but the create was never attempted (a concurrent
        //    request held the lock). Still make the fallback visible.
        if (!$create_attempted) {
            $order->add_order_note(
                __('Alegra: la factura se emitirá a nombre del Consumidor Final porque otra sincronización estaba en curso y no se pudo crear el contacto del cliente. Volvé a facturar en unos segundos para emitirla a nombre del cliente.', 'alegra-connector')
            );
        }
    }

    /**
     * Labels of the enabled catalog fields that are empty for this order's
     * customer, lowercased for inline use in a sentence. Empty when every
     * critical field is present.
     *
     * @return list<string>
     */
    private function missing_billing_field_labels(\WC_Order $order, ?\WP_User $customer): array
    {
        $values = $this->collect_billing_values($order, $customer);
        if (\Alegra\Connector\Billing_Fields::has_critical_data($values)) {
            return [];
        }

        $labels = [];
        foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
            if (!\Alegra\Connector\Billing_Fields::is_field_enabled($key)) {
                continue;
            }
            // `dv` is only required for a NIT; skip it for every other id type.
            if ($key === 'dv' && ($values['idtype'] ?? '') !== 'NIT') {
                continue;
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                $labels[] = strtolower((string) ($field['label'] ?? $key));
            }
        }

        return $labels;
    }

    /**
     * Persist the resolved Alegra contact id on the order and, when present,
     * on the customer.
     *
     * Contact-id meta naming (deliberately left as-is; renaming without a
     * migration would orphan data on live stores):
     * - order:  `_billing_alegra_contact_id`
     * - user:   `alegra_contact_id`
     * - term:   `alegra_category_id`
     * The three storages are different entity types; the prefix drift is
     * historical and every read/write uses the same key for its entity.
     */
    private function persist_contact_id(\WC_Order $order, ?\WP_User $customer, string $contact_id): void
    {
        $order->update_meta_data('_billing_alegra_contact_id', $contact_id);
        $order->save();
        if ($customer) {
            update_user_meta($customer->ID, 'alegra_contact_id', $contact_id);
        }
    }

    /**
     * Collect the catalog billing values for an order, keyed by catalog key.
     *
     * Reads from user_meta for registered customers, or from the order meta
     * (prefixed with `_`) for guests.
     *
     * @return array<string, string>
     */
    private function collect_billing_values(\WC_Order $order, ?\WP_User $customer): array
    {
        $values = [];
        foreach (\Alegra\Connector\Billing_Fields::CATALOG as $key => $field) {
            // AC-38: a disabled field is never collected.
            if (!\Alegra\Connector\Billing_Fields::is_field_enabled($key)) {
                $values[$key] = '';
                continue;
            }
            $meta_key = (string) $field['meta_key'];
            $values[$key] = $customer
                ? (string) get_user_meta($customer->ID, $meta_key, true)
                : (string) $order->get_meta('_' . $meta_key, true);
        }

        return $values;
    }

    private function build_client_data(\WC_Order $order, string $customer_alegra_id): array
    {
        return ['id' => $customer_alegra_id];
    }

    private function calculate_due_date(\WC_Order $order): string
    {
        // Try to get payment term from Alegra
        $term_id = (string) get_option('alegra_connector_payment_term_id', '');
        if ($term_id !== '') {
            $term_data = $this->api->get_term($term_id);
            if (!is_wp_error($term_data) && isset($term_data['days'])) {
                $days = (int) $term_data['days'];
                return date('Y-m-d', strtotime("+{$days} days"));
            }
        }

        return date('Y-m-d', strtotime('+15 days'));
    }

    /**
     * Coupon/discount for a line, as a percentage of its pre-discount subtotal.
     *
     * Alegra's `items[].discount` is a percentage (post_invoices.md), and the
     * line `price` must NOT include the discount. Returns 0 when there is none.
     */
    private static function line_discount_percent(float $subtotal, float $line_total): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = (($subtotal - $line_total) / $subtotal) * 100;
        if ($discount <= 0) {
            return 0.0;
        }

        return round($discount, 4);
    }

    private function prepare_invoice_items(\WC_Order $order): array|\WP_Error
    {
        $items = [];

        foreach ($order->get_items() as $item_obj) {
            $product_id = $item_obj->get_product_id();
            $variation_id = $item_obj->get_variation_id();

            $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);

            // AC-10: `items[].id` is obligatory for Alegra invoices. An id-less
            // line is either a 400 or a free-text line that breaks inventory.
            // Refuse to build the invoice rather than send a malformed one.
            if ($alegra_item_id === '') {
                return new \WP_Error(
                    'invoice_item_unlinked',
                    sprintf(
                        /* translators: %s: WooCommerce product name */
                        __('No se pudo vincular el producto "%s" con un ítem de Alegra. Sincroniza el producto antes de facturar.', 'alegra-connector'),
                        $item_obj->get_name()
                    )
                );
            }

            $subtotal = (float) $item_obj->get_subtotal();
            $line_total = (float) $item_obj->get_total();
            $quantity = (int) $item_obj->get_quantity();
            $price = (float) ($subtotal / max(1, $quantity));

            $item_data = [
                'id' => $alegra_item_id,
                'name' => $item_obj->get_name(),
                'price' => $price,
                'quantity' => $quantity,
            ];

            // AC-48: map the per-line coupon/discount to Alegra's `discount`
            // (a percentage, per post_invoices.md). Without it the invoice total
            // is HIGHER than the WooCommerce order total. `price` stays
            // pre-discount; Alegra applies the percentage.
            $discount = self::line_discount_percent($subtotal, $line_total);
            if ($discount > 0) {
                $item_data['discount'] = $discount;
            }

            $this->attach_line_taxes($item_data, $item_obj);

            $items[] = $item_data;
        }

        // BUG 2: `$order->get_items()` returns ONLY line_item rows, so shipping
        // and fees were dropped and the invoice total was smaller than the
        // order total. Map them as their own invoice lines.
        $shipping_lines = $this->build_shipping_lines($order);
        if (is_wp_error($shipping_lines)) {
            return $shipping_lines;
        }
        $items = array_merge($items, $shipping_lines);

        $fee_lines = $this->build_fee_lines($order);
        if (is_wp_error($fee_lines)) {
            return $fee_lines;
        }
        $items = array_merge($items, $fee_lines);

        return $items;
    }

    /**
     * Append the resolved Alegra taxes of a WC order item to an invoice line.
     *
     * @param array<string, mixed> $line
     */
    private function attach_line_taxes(array &$line, \WC_Order_Item $item): void
    {
        $tax_ids = $this->map_item_taxes($item);
        if ($tax_ids !== []) {
            $line['tax'] = [];
            foreach ($tax_ids as $tid) {
                $line['tax'][] = ['id' => $tid];
            }
        }
    }

    /**
     * Invoice lines for the order's shipping charges (BUG 2).
     *
     * `get_items('shipping')` returns the WC_Order_Item_Shipping rows. Because
     * `items[].id` is obligatory (post_invoices.md), each shipping row is linked
     * to the shared generic "Ajuste" service item resolved find-or-create by
     * reference (see GENERIC_ITEM_REFERENCE).
     * The line price is pre-tax; the shipping tax is mapped like a line tax.
     *
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    private function build_shipping_lines(\WC_Order $order): array|\WP_Error
    {
        $lines = [];

        foreach ($order->get_items('shipping') as $ship_item) {
            if (!$ship_item instanceof \WC_Order_Item) {
                continue;
            }
            $total = (float) $ship_item->get_total();
            if ($total <= 0) {
                continue;
            }

            $item_id = $this->resolve_generic_item_id(
                self::GENERIC_ITEM_REFERENCE,
                __('Ajuste', 'alegra-connector')
            );
            if ($item_id === '') {
                return new \WP_Error(
                    'invoice_shipping_unlinked',
                    __('No se pudo vincular el envío con un ítem de Alegra. La factura no se creó.', 'alegra-connector')
                );
            }

            $line = [
                'id'       => $item_id,
                'name'     => __('Envío', 'alegra-connector'),
                'price'    => $total,
                'quantity' => 1,
            ];
            $this->attach_line_taxes($line, $ship_item);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Invoice lines for the order's fees (BUG 2).
     *
     * All fees share the generic "Ajuste" service item (find-or-create, see
     * GENERIC_ITEM_REFERENCE); the
     * fee's own name is carried in the line `name`/`description` so it stays
     * readable. The line price is pre-tax and the fee tax is mapped like a line
     * tax.
     *
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    private function build_fee_lines(\WC_Order $order): array|\WP_Error
    {
        $lines = [];

        foreach ($order->get_items('fee') as $fee_item) {
            if (!$fee_item instanceof \WC_Order_Item) {
                continue;
            }
            $total = (float) $fee_item->get_total();
            if ($total <= 0) {
                continue;
            }

            $name = trim((string) $fee_item->get_name());
            if ($name === '') {
                $name = __('Recargo', 'alegra-connector');
            }

            $item_id = $this->resolve_generic_item_id(
                self::GENERIC_ITEM_REFERENCE,
                __('Ajuste', 'alegra-connector')
            );
            if ($item_id === '') {
                return new \WP_Error(
                    'invoice_fee_unlinked',
                    __('No se pudo vincular un recargo con un ítem de Alegra. La factura no se creó.', 'alegra-connector')
                );
            }

            $line = [
                'id'          => $item_id,
                'name'        => $name,
                'description' => $name,
                'price'       => $total,
                'quantity'    => 1,
            ];
            $this->attach_line_taxes($line, $fee_item);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Resolve (find-or-create) a generic Alegra service item by `reference`.
     *
     * Credit-note and invoice lines require `items[].id`
     * (post_credit-notes.md / post_invoices.md) — a free-text line is rejected
     * — so shipping, fees and partial refunds need a real item. The lookup is
     * keyed by a stable `reference`, which makes it idempotent: the same
     * reference always resolves to the same item and is never created twice.
     *
     * Returns '' when the item cannot be resolved.
     */
    private function resolve_generic_item_id(string $reference, string $name): string
    {
        // Dry Run blocks write verbs; return a deterministic placeholder so the
        // rest of the payload can still be built (the invoice POST is blocked
        // too, so the placeholder never leaves the plugin).
        if (get_option('alegra_connector_dry_run', false)) {
            return 'dry-run-' . $reference;
        }

        $cache = (array) get_option('alegra_connector_generic_item_ids', []);
        if (isset($cache[$reference]) && (string) $cache[$reference] !== '') {
            return (string) $cache[$reference];
        }

        $existing = $this->api->get_items(['reference' => $reference, 'limit' => 1]);
        if (!is_wp_error($existing) && !empty($existing) && isset($existing[0]['id'])) {
            $id = (string) $existing[0]['id'];
            $this->remember_generic_item_id($reference, $id);
            return $id;
        }

        $created = $this->api->create_item([
            'name'      => $name,
            'reference' => $reference,
            'type'      => 'service',
            'price'     => [['idPriceList' => 1, 'price' => 0]],
        ]);
        if (!is_wp_error($created) && isset($created['id'])) {
            $id = (string) $created['id'];
            $this->remember_generic_item_id($reference, $id);
            if ($this->logger) {
                $this->logger->warning('Created a generic Alegra item for invoice lines', [
                    'reference' => $reference,
                    'name'      => $name,
                    'item_id'   => $id,
                ]);
            }
            return $id;
        }

        if ($this->logger) {
            $this->logger->error('Could not resolve a generic Alegra item', [
                'reference' => $reference,
                'error'     => is_wp_error($created) ? $created->get_error_message() : 'missing id',
            ]);
        }

        return '';
    }

    private function remember_generic_item_id(string $reference, string $id): void
    {
        $cache = (array) get_option('alegra_connector_generic_item_ids', []);
        $cache[$reference] = $id;
        update_option('alegra_connector_generic_item_ids', $cache, false);
    }

    /**
     * Resolve the Alegra item id for a WC product/variation.
     *
     * Order: stored meta → SKU lookup → push the product to Alegra (which
     * creates/links the item and stores `_alegra_item_id`). Returns '' when the
     * item cannot be resolved.
     */
    private function resolve_item_alegra_id(int $product_id, int $variation_id): string
    {
        if ($variation_id > 0) {
            $alegra_id = (string) get_post_meta($variation_id, '_alegra_item_id', true);
            if ($alegra_id !== '') return $alegra_id;
        }

        if ($product_id > 0) {
            $alegra_id = (string) get_post_meta($product_id, '_alegra_item_id', true);
            if ($alegra_id !== '') return $alegra_id;

            $sku = get_post_meta($product_id, '_sku', true);
            if (!empty($sku)) {
                $items = $this->api->get_items(['reference' => $sku, 'limit' => 1]);
                if (!is_wp_error($items) && !empty($items) && isset($items[0]['id'])) {
                    $found_id = (string) $items[0]['id'];
                    update_post_meta($product_id, '_alegra_item_id', $found_id);
                    return $found_id;
                }
            }
        }

        // Last resort: push the product (or variation) so Alegra assigns an id.
        $target_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($target_id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($target_id);
            if ($product instanceof \WC_Product) {
                $sync = new Products($this->api, $this->logger);
                $result = $sync->sync_to_alegra($product);
                if (!is_wp_error($result) && isset($result['id'])) {
                    return (string) $result['id'];
                }
                if ($this->logger) {
                    $this->logger->warning('Could not push product to resolve Alegra item id', [
                        'product_id' => $target_id,
                        'error'      => is_wp_error($result) ? $result->get_error_message() : 'missing id',
                    ]);
                }
            }
        }

        return '';
    }

    /**
     * Build the `POST /payments` payload from WooCommerce data (REQ-PAY-1).
     *
     * The amount is $order->get_total() and the date is $order->get_date_paid();
     * the method is the single gateway→Alegra mapping (REQ-PAY-2). Values are
     * never invented and never read from the gateway's own panel.
     *
     * @return array<string,mixed> Empty array when no destination account is configured.
     */
    private function prepare_payment_data(\WC_Order $order, string $invoice_id): array
    {
        $account_id = (string) get_option('alegra_connector_payment_account_id', '');
        // BUG 8: '' and '0' both mean UNCONFIGURED (the settings <select> stores
        // '0' for "no account"), matching the manual path's guard.
        if (in_array($account_id, ['', '0'], true)) {
            return [];
        }

        $amount = (float) $order->get_total();

        // REQ-PAY-1: this is a full payment against the invoice balance. If the
        // balance Alegra reports differs, surface the discrepancy (note + log)
        // instead of silently adjusting the amount.
        $this->assert_full_payment_matches_balance($order, $invoice_id, $amount);

        $method_title = trim((string) $order->get_payment_method_title());
        $observations = $method_title !== ''
            ? sprintf(
                /* translators: 1: WooCommerce order id, 2: payment gateway title. */
                __('Pedido #%1$d — %2$s', 'alegra-connector'),
                (int) $order->get_id(),
                $method_title
            )
            : '';

        $data = [
            'date' => $this->payment_date($order),
            'bankAccount' => ['id' => $account_id],
            'invoices' => [
                [
                    'id' => $invoice_id,
                    'amount' => $amount,
                ],
            ],
            'paymentMethod' => $this->get_payment_method_code($order),
        ];

        if ($observations !== '') {
            $data['observations'] = $observations;
        }

        return $data;
    }

    /**
     * The payment date, taken from WooCommerce (REQ-PAY-1).
     *
     * Uses $order->get_date_paid() formatted as `Y-m-d`. A paid order with no
     * paid date falls back to today WITH a warning — the date is never silently
     * invented.
     */
    private function payment_date(\WC_Order $order): string
    {
        $paid = $order->get_date_paid();
        if ($paid instanceof \DateTimeInterface) {
            return $paid->format('Y-m-d');
        }

        if ($this->logger) {
            $this->logger->warning('Payment date missing on a paid order; using today', [
                'order_id' => $order->get_id(),
            ]);
        }

        return date('Y-m-d');
    }

    /**
     * Report (do NOT silently fix) when the WC order total differs from the
     * invoice balance returned by Alegra (REQ-PAY-1).
     */
    private function assert_full_payment_matches_balance(\WC_Order $order, string $invoice_id, float $amount): void
    {
        $invoice = $this->api->get_invoice($invoice_id);
        if (is_wp_error($invoice) || !is_array($invoice)) {
            // Not being able to read the balance must not block the payment.
            return;
        }

        $balance = isset($invoice['balance']) ? (float) $invoice['balance'] : null;
        if ($balance === null || abs($balance - $amount) <= 0.01) {
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: order total, 2: invoice id, 3: invoice balance. */
            __('[Alegra] Aviso: el total del pedido (%1$s) no coincide con el saldo de la factura #%2$s (%3$s). Se registró el pago por el total del pedido; revisá la factura.', 'alegra-connector'),
            number_format($amount, 2, '.', ''),
            $invoice_id,
            number_format($balance, 2, '.', '')
        ));

        if ($this->logger) {
            $this->logger->warning('Payment amount differs from invoice balance', [
                'order_id'   => $order->get_id(),
                'invoice_id' => $invoice_id,
                'amount'     => $amount,
                'balance'    => $balance,
            ]);
        }
    }

    private function get_preferred_number_template(): ?string
    {
        $templates = $this->api->get_number_templates(['documentType' => 'invoice']);
        if (is_wp_error($templates) || empty($templates)) {
            return null;
        }

        // Prefer the electronic invoice template
        foreach ($templates as $tpl) {
            if (($tpl['isElectronic'] ?? false) === true && ($tpl['documentType'] ?? '') === 'invoice') {
                return (string) $tpl['id'];
            }
        }

        // Fallback: the default invoice template
        foreach ($templates as $tpl) {
            if (($tpl['isDefault'] ?? false) === true) {
                return (string) $tpl['id'];
            }
        }

        return null;
    }

    private function get_default_payment_term(): string
    {
        return (string) get_option('alegra_connector_payment_term_id', '');
    }

    /**
     * Map a WooCommerce payment gateway slug to an Alegra `paymentMethod`.
     *
     * SINGLE resolver: both get_payment_method_code() and
     * getPaymentMethodForGateway() delegate here, so one unknown gateway can
     * never produce two different methods. The fallback is UNIQUE and
     * documented: 'transfer'.
     *
     * Valid Alegra enum (verified): cash | check | transfer | deposit |
     * credit-card | debit-card.
     *
     * @see https://developer.alegra.com/reference/post_payments-1.md
     */
    public function resolve_alegra_payment_method(string $gateway_slug): string
    {
        foreach ($this->get_payment_gateway_code_mappings() as $slug => $code) {
            if ($slug !== '' && strpos($gateway_slug, $slug) !== false) {
                if (in_array($code, self::ALEGRA_PAYMENT_METHOD_ENUM, true)) {
                    return $code;
                }
                // A value outside the official enum is a config bug, not a
                // network error: fall back instead of sending an invalid method.
                if ($this->logger) {
                    $this->logger->warning('Payment gateway maps outside the Alegra enum; falling back to transfer', [
                        'gateway' => $gateway_slug,
                        'code'    => $code,
                    ]);
                }
                return 'transfer';
            }
        }

        if ($this->logger) {
            $this->logger->warning('Unknown payment gateway; falling back to transfer', [
                'gateway' => $gateway_slug,
            ]);
        }

        return 'transfer';
    }

    private function get_payment_method_code(\WC_Order $order): string
    {
        return $this->resolve_alegra_payment_method($order->get_payment_method());
    }

    /**
     * Maps WooCommerce payment gateway slugs to Alegra payment methods.
     * Covers all major gateways in Latin America.
     */
    public function getPaymentMethodForGateway(string $gateway_slug): string
    {
        return $this->resolve_alegra_payment_method($gateway_slug);
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

        foreach ($tax_data['total'] as $rate_id => $amount) {
            // A zero-amount tax line carries no tax.
            if ((float) $amount <= 0) {
                continue;
            }
            $resolved = $this->resolve_alegra_tax_id((string) $rate_id, $tax_mapping);
            if ($resolved !== '') {
                $ids[$resolved] = $resolved;
            }
        }

        return array_values($ids);
    }

    /**
     * Resolve the Alegra tax id for a WooCommerce tax RATE id (BUG 3).
     *
     * Resolution order:
     *  1. explicit mapping by rate id;
     *  2. explicit mapping by tax-class slug — the key the "Mapeo de Campos" UI
     *     writes (admin-mapping.php keys by `sanitize_title($tax_class)`), which
     *     the old rate-id-only lookup never matched;
     *  3. derive from the WC tax rate percentage, finding an existing Alegra tax
     *     (or creating one, idempotently, when none exists).
     *
     * Returns '' when nothing can be resolved; the caller's
     * find_or_create_alegra_tax() logs the reason at `warning`.
     */
    private function resolve_alegra_tax_id(string $rate_id, array $mapping): string
    {
        if (isset($mapping[$rate_id]) && (string) $mapping[$rate_id] !== '') {
            return (string) $mapping[$rate_id];
        }

        $rate = class_exists('\WC_Tax') ? \WC_Tax::_get_tax_rate((int) $rate_id) : [];
        $percentage = isset($rate['tax_rate']) ? (float) $rate['tax_rate'] : 0.0;
        $class = isset($rate['tax_rate_class']) ? (string) $rate['tax_rate_class'] : '';
        $slug = sanitize_title($class);

        if ($slug !== '' && isset($mapping[$slug]) && (string) $mapping[$slug] !== '') {
            return (string) $mapping[$slug];
        }

        if ($percentage <= 0) {
            if ($this->logger) {
                $this->logger->warning('A WooCommerce tax rate has no resolvable percentage or mapping; the invoice line will omit it', [
                    'rate_id' => $rate_id,
                ]);
            }
            return '';
        }

        return $this->find_or_create_alegra_tax($percentage);
    }

    /**
     * Find an Alegra tax by percentage, creating it idempotently when missing.
     *
     * Only rates actually present on an order reach this method, so the plugin
     * never creates unused taxes. The resolved id is cached by percentage so a
     * rate is resolved (and at most created) once. When several Alegra taxes
     * share the percentage, an IVA-named one is preferred.
     */
    private function find_or_create_alegra_tax(float $percentage): string
    {
        $key = rtrim(rtrim(number_format($percentage, 4, '.', ''), '0'), '.');
        $cache = (array) get_option('alegra_connector_resolved_tax_ids', []);
        if (isset($cache[$key]) && (string) $cache[$key] !== '') {
            return (string) $cache[$key];
        }

        $taxes = $this->api->get_taxes(['limit' => 30]);
        if (!is_wp_error($taxes) && is_array($taxes)) {
            $candidates = [];
            foreach ($taxes as $tax) {
                if (!is_array($tax) || !isset($tax['id'])) {
                    continue;
                }
                if (abs(((float) ($tax['percentage'] ?? 0)) - $percentage) < 0.0001) {
                    $candidates[] = $tax;
                }
            }

            if (count($candidates) === 1) {
                $id = (string) $candidates[0]['id'];
                $this->remember_tax_id($key, $id);
                return $id;
            }

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    if (stripos((string) ($candidate['name'] ?? ''), 'IVA') !== false) {
                        $id = (string) $candidate['id'];
                        $this->remember_tax_id($key, $id);
                        return $id;
                    }
                }
                $id = (string) $candidates[0]['id'];
                $this->remember_tax_id($key, $id);
                if ($this->logger) {
                    $this->logger->warning('Several Alegra taxes share this percentage; picked the first', [
                        'percentage' => $percentage,
                        'tax_id'     => $id,
                    ]);
                }
                return $id;
            }
        }

        // None found: create it, idempotently keyed by percentage.
        $created = $this->api->create_tax([
            'name'       => sprintf('IVA %s%%', $key),
            'percentage' => $percentage,
        ]);
        if (!is_wp_error($created) && isset($created['id'])) {
            $id = (string) $created['id'];
            $this->remember_tax_id($key, $id);
            if ($this->logger) {
                $this->logger->warning('Created an Alegra tax to match a WooCommerce rate', [
                    'percentage' => $percentage,
                    'tax_id'     => $id,
                ]);
            }
            return $id;
        }

        if ($this->logger) {
            $this->logger->warning('Could not resolve an Alegra tax; the invoice line will omit it', [
                'percentage' => $percentage,
                'error'      => is_wp_error($created) ? $created->get_error_message() : 'missing id',
            ]);
        }

        return '';
    }

    private function remember_tax_id(string $key, string $id): void
    {
        $cache = (array) get_option('alegra_connector_resolved_tax_ids', []);
        $cache[$key] = $id;
        update_option('alegra_connector_resolved_tax_ids', $cache, false);
    }

    /**
     * Poll Alegra for invoice status changes and update linked WC orders.
     * Replaces the webhook-based approach for Alegra → WC sync.
     * Called by the cron sync job, respecting auto_complete_order setting.
     */
    public function poll_invoice_statuses(): array
    {
        $result = ['checked' => 0, 'completed' => 0, 'errors' => 0];

        // Kill switch guard
        if (\Alegra\Connector\Kill_Switch::is_active()) {
            $this->logger->info('Invoice status poll skipped: kill switch active');
            return $result;
        }

        // AC-18: the poll issues one HTTP call per order. Without a lock two
        // overlapping cron ticks duplicate every call. Wrap it in the same
        // per-type mutex the other sync steps use.
        $lock = self::acquire_poll_lock();
        if ($lock === false) {
            $this->logger->info('Invoice status poll skipped: another poll is running');
            return $result;
        }

        try {
            // AC-18: a sane, configurable batch. The API cost is high (one
            // request per order) so the default is deliberately lower than the
            // old 50. At a 5-min cron this is 288 * 20 = 5.7k calls/day vs the
            // old ~14k.
            $limit = (int) get_option('alegra_connector_orders_poll_batch', 20);
            if ($limit < 1) {
                $limit = 20;
            }

            // AC-18: fetch IDs + filter in SQL. `return => ids` avoids
            // hydrating full WC_Order objects just to read one meta.
            //
            // `completed` is included so a void issued AFTER the order was
            // completed is still noticed. The batch (`orders_poll_batch`,
            // default 20) bounds the per-run API cost regardless of how many
            // completed orders exist, and the date DESC ordering keeps the
            // most-recent orders (the ones still pending/completing) first.
            $query = [
                'limit'   => $limit,
                'status'  => ['processing', 'pending', 'on-hold', 'completed'],
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
                'meta_query' => [[
                    'key'     => '_alegra_invoice_id',
                    'value'   => '',
                    'compare' => '!=',
                ]],
            ];

            $order_ids = wc_get_orders($query);
            $should_complete = get_option('alegra_connector_auto_complete_order', true);

            foreach ((array) $order_ids as $order_id) {
                // Re-check the kill switch so an in-flight poll stops.
                if (\Alegra\Connector\Kill_Switch::is_active()) {
                    $this->logger->info('Invoice status poll stopped: kill switch active');
                    break;
                }

                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }
                $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
                if ($invoice_id === '') {
                    continue;
                }

                $result['checked']++;

                $invoice = $this->api->get_invoice($invoice_id);
                if (is_wp_error($invoice)) {
                    $result['errors']++;
                    continue;
                }

                // Keep the cached status fresh for the orders list.
                $this->persist_invoice_status($order, $invoice);

                $status = (string) ($invoice['status'] ?? '');
                $balance = (float) ($invoice['balance'] ?? 0);

                // A voided invoice never auto-completes. Note it once and leave
                // the WC order status alone: the merchant decides.
                if ($status === 'void') {
                    $this->note_invoice_voided($order, $invoice);
                    continue;
                }

                if (\Alegra\Connector\Invoice_Status::is_paid($status) && $balance <= 0 && $should_complete) {
                    if ($order->get_status() !== 'completed') {
                        $order->add_order_note(sprintf(
                            __('[Alegra] Factura #%s pagada. Pedido completado automaticamente.', 'alegra-connector'),
                            $invoice['numberTemplate']['fullNumber'] ?? $invoice_id
                        ));
                        $order->update_status('completed');
                        $result['completed']++;
                    }
                }
            }
        } finally {
            self::release_poll_lock($lock);
        }

        return $result;
    }

    private static function acquire_poll_lock(): string|false
    {
        return Controller::acquire_lock('alegra_sync_running_orders', 300);
    }

    private static function release_poll_lock(string $token): void
    {
        Controller::release_lock('alegra_sync_running_orders', $token);
    }
}