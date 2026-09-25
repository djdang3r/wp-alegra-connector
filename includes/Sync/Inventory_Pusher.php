<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Empuja el delta de stock de WooCommerce a Alegra vía
 * `POST /inventory-adjustments` cuando el plugin es dueño del movimiento
 * (D2, `push_orders_enabled=false`). Con `push_orders=true` la factura es la
 * dueña y este clase sólo registra hooks que no-op (REQ-INV-08).
 *
 * CONTRADICCIÓN DELIBERADA con `docs/sdd/inventory/DD-8`: ese documento
 * prohibía cablear `create_inventory_adjustment()` asumiendo que la factura (b)
 * cubriría las ventas. Con los defaults reales (`push_orders_enabled=false`,
 * `invoice_status=draft`) la factura no cubre nada y la re-inflación persiste.
 * La intención de DD-8 (evitar doble conteo) se preserva con el dueño único a
 * nivel tienda (`owner()`): `invoice` sólo si `push_orders_enabled &&
 * open_invoice_on_paid`, más las guardas del pusher (`invoice_owner`) y la
 * confirmación de factura manual. Ver Fase 3 §0.2 / REQ-INV-08.
 *
 * @package Alegra\Connector\Sync
 */
final class Inventory_Pusher
{
    public const META_SYNCED  = '_alegra_stock_synced';
    public const META_PENDING = '_alegra_stock_push_pending';

    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Last quantity WC and Alegra agreed on. '' when never baselined.
     */
    public static function synced(int $product_id): int|string
    {
        $value = get_post_meta($product_id, self::META_SYNCED, true);
        return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
    }

    public static function set_synced(int $product_id, int $qty): void
    {
        update_post_meta($product_id, self::META_SYNCED, $qty);
    }

    /**
     * WC quantity currently being pushed (set before the POST, cleared on OK).
     * '' when no push is in flight.
     */
    public static function pending(int $product_id): int|string
    {
        $value = get_post_meta($product_id, self::META_PENDING, true);
        return ($value === '' || $value === false || $value === null) ? '' : (int) $value;
    }

    public static function set_pending(int $product_id, int $qty): void
    {
        update_post_meta($product_id, self::META_PENDING, $qty);
    }

    public static function clear_pending(int $product_id): void
    {
        delete_post_meta($product_id, self::META_PENDING);
    }

    /**
     * Dueño del movimiento de stock de Alegra (K-P / FIX-3).
     * `invoice` SÓLO si `push_orders_enabled && open_invoice_on_paid` (la
     * factura realmente mueve stock). Si no, el dueño es `adjustment`.
     *
     * @return 'invoice'|'adjustment'
     */
    public static function owner(): string
    {
        return (get_option('alegra_connector_push_orders_enabled', false)
            && get_option('alegra_connector_open_invoice_on_paid', true))
            ? 'invoice'
            : 'adjustment';
    }

    /**
     * Registra los hooks de stock de WC. Un handler de instancia por request.
     * Los hooks pasan un `WC_Product` (no un int) — ver C2.
     */
    public static function register_hooks(?API\Client $api, ?Logger\Logger $logger): void
    {
        $pusher = new self($api, $logger);
        add_action('woocommerce_product_set_stock', [$pusher, 'on_stock_changed'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$pusher, 'on_variation_stock_changed'], 10, 1);
    }

    /**
     * Handler de `woocommerce_product_set_stock`. Acepta el objeto o un id.
     *
     * FIX-2 (B2): el guard de re-entrada `is_syncing()` vive ACÁ (en el hook),
     * no en `push_delta()`. Así el poll (que envuelve su loop con
     * `set_syncing(true)`, T7.3) puede reconciliar llamando a
     * `push_delta(..., $from_poll = true)` sin auto-bloquearse.
     *
     * @param \WC_Product|int|mixed $product
     */
    public function on_stock_changed($product): void
    {
        // FIX-2: cortar la cascada del import/poll en el hook.
        if (\Alegra\Connector\Public\Public_::is_syncing()) {
            return;
        }
        $product = $this->normalize_product($product);
        if ($product === null) {
            return;
        }
        $this->push_delta($product, (int) $product->get_stock_quantity());
    }

    /**
     * Handler de `woocommerce_variation_set_stock`.
     *
     * @param \WC_Product|int|mixed $variation
     */
    public function on_variation_stock_changed($variation): void
    {
        $this->on_stock_changed($variation);
    }

    /** @param mixed $product */
    private function normalize_product($product): ?\WC_Product
    {
        if ($product instanceof \WC_Product) {
            return $product;
        }
        if (is_numeric($product)) {
            $p = wc_get_product((int) $product);
            return $p instanceof \WC_Product ? $p : null;
        }
        return null;
    }

    /**
     * Empuja el delta WC→Alegra con ledger + lock + idempotencia (D2 §3.4).
     *
     * @param bool $from_poll El poll pasa `true` para bypassar `is_syncing()`
     *                        (FIX-2) y establecer el baseline desde Alegra (FIX-1).
     * @return array{pushed:bool, delta:int, reason:string}
     */
    public function push_delta(\WC_Product $product, int $new_qty, bool $from_poll = false): array
    {
        $id = (int) $product->get_id();

        // Guard 1 (C7 + FIX-2): re-entrada. El transient por producto se
        // respeta SIEMPRE. `is_syncing()` sólo frena al HOOK: el poll
        // (from_poll=true) lo bypassa porque él mismo envuelve su loop con
        // set_syncing(true) (T7.3) y necesita reconciliar.
        if (get_transient('alegra_updating_product_' . $id)
            || (!$from_poll && \Alegra\Connector\Public\Public_::is_syncing())) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'syncing'];
        }

        // Guard 2 (K-P): con la factura como dueña no se emite ajuste.
        if (self::owner() !== 'adjustment') {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'invoice_owner'];
        }

        // Guard 3: kill switch / dry-run los evalúa el Write_Gate al POSTear;
        // acá el toggle de negocio del push.
        if (!get_option('alegra_connector_push_inventory_enabled', true)) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'disabled'];
        }

        // Guard 4: producto sin vínculo a Alegra.
        $alegra_item = (string) get_post_meta($id, '_alegra_item_id', true);
        if ($alegra_item === '') {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'not_linked'];
        }

        // Guard 5 (nuevo): WC no gestiona stock ⇒ no hay delta confiable.
        if (!$product->get_manage_stock()) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'not_manageable'];
        }

        // Sin API no se puede calcular ni emitir el ajuste.
        if ($this->api === null) {
            return ['pushed' => false, 'delta' => 0, 'reason' => 'api_error'];
        }

        // FIX-1 (B1): el baseline sale SIEMPRE de Alegra, nunca de WC.
        $synced = self::synced($id);
        if ($synced === '') {
            if (!$from_poll) {
                // Hook sin baseline: NO fijar `synced = $new_qty` (post-venta)
                // sin empujar — eso re-infla la venta en el próximo poll.
                // Se marca el movimiento como pendiente y el reconciliador
                // (poll, from_poll=true) fija el baseline real y empuja.
                self::set_pending($id, $new_qty);
                return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_pending'];
            }
            // Reconciliador: baseline desde ALEGRA (GET /items/{id}).
            $baseline = $this->fetch_alegra_available_quantity($alegra_item);
            if ($baseline === null) {
                // Sin certeza del valor de Alegra no se puede calcular delta.
                return ['pushed' => false, 'delta' => 0, 'reason' => 'baseline_unverified'];
            }
            self::set_synced($id, $baseline);
            $synced = (string) $baseline;
        }

        $delta = $new_qty - (int) $synced;
        if ($delta === 0) {
            // WC y Alegra ya coinciden: nada que empujar; limpiar pending.
            self::clear_pending($id);
            return ['pushed' => false, 'delta' => 0, 'reason' => 'in_sync'];
        }

        // Lock por producto: dos corridas no aplican el mismo delta (NFR-07).
        $lock_key = 'alegra_inventory_push_' . $id;
        $token = Controller::acquire_lock($lock_key, 30);
        if ($token === false) {
            return ['pushed' => false, 'delta' => $delta, 'reason' => 'locked'];
        }

        $pending_prev = (string) self::pending($id);

        try {
            // K-F: pending ANTES del POST. Si el POST entra y la respuesta se
            // pierde, el poll ve `pending` y reintenta sin pisar WC.
            self::set_pending($id, $new_qty);

            // FIX-4 (D4): pre-búsqueda de idempotencia OBLIGATORIA antes de
            // re-emitir. Si un intento anterior entró y su respuesta se perdió,
            // el ajuste ya está en Alegra ⇒ considerarlo aplicado (no duplicar).
            if ($pending_prev !== ''
                && $this->adjustment_already_exists($alegra_item, $delta)) {
                self::set_synced($id, $new_qty);
                self::clear_pending($id);
                return ['pushed' => true, 'delta' => $delta, 'reason' => 'already_applied'];
            }

            $res = $this->api->create_inventory_adjustment(
                $this->build_adjustment_payload($alegra_item, $delta, $product)
            );

            // Dry-run / gate: no llegó nada a Alegra ⇒ no tocar `synced`.
            if (API\Client::write_was_blocked($res)) {
                return ['pushed' => false, 'delta' => $delta, 'reason' => (string) ($res['reason'] ?? 'blocked')];
            }
            if (is_wp_error($res)) {
                if ($this->logger) {
                    $this->logger->error('Inventory adjustment failed', [
                        'product_id'  => $id,
                        'alegra_item' => $alegra_item,
                        'delta'       => $delta,
                        'error'       => $res->get_error_message(),
                    ]);
                }
                // `pending` queda ⇒ el poll reintenta (K-F). Nunca se toca `synced`.
                return ['pushed' => false, 'delta' => $delta, 'reason' => 'api_error'];
            }

            // Éxito: recién acá WC y Alegra acuerdan.
            self::set_synced($id, $new_qty);
            self::clear_pending($id);
            return ['pushed' => true, 'delta' => $delta, 'reason' => 'ok'];
        } finally {
            Controller::release_lock($lock_key, $token);
        }
    }

    /**
     * FIX-1: `availableQuantity` de Alegra para `GET /items/{id}`.
     * Devuelve null si no hay dato usable (nunca 0 por ausencia).
     */
    private function fetch_alegra_available_quantity(string $alegra_item): ?int
    {
        if ($this->api === null) {
            return null;
        }
        $res = $this->api->get_item($alegra_item);
        if (is_wp_error($res) || !is_array($res)) {
            return null;
        }
        $qty = $res['inventory']['availableQuantity'] ?? null;
        if ($qty === null || $qty === '' || !is_numeric($qty)) {
            return null;
        }
        return (int) $qty;
    }

    /**
     * FIX-4: ¿ya existe en Alegra un ajuste con el mismo ítem, tipo y cantidad?
     * Fail-open: ante error de red devuelve false (se reintenta el POST).
     */
    private function adjustment_already_exists(string $alegra_item, int $delta): bool
    {
        if ($this->api === null) {
            return false;
        }
        $res = $this->api->get_inventory_adjustments([
            'item_id'         => $alegra_item,
            'limit'           => 30,
            'order_field'     => 'date',
            'order_direction' => 'DESC',
        ]);
        if (is_wp_error($res) || !is_array($res)) {
            return false;
        }
        $want_type = $delta < 0 ? 'out' : 'in';
        $want_qty  = abs($delta);
        foreach ($res as $adjustment) {
            if (!is_array($adjustment)) {
                continue;
            }
            foreach (($adjustment['items'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                if ((string) ($line['id'] ?? '') === $alegra_item
                    && (string) ($line['type'] ?? '') === $want_type
                    && (int) ($line['quantity'] ?? 0) === $want_qty) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Payload documentado de `POST /inventory-adjustments` (K-A / C1).
     */
    private function build_adjustment_payload(string $alegra_item, int $delta, \WC_Product $product): array
    {
        $payload = [
            'date'  => current_time('Y-m-d'),
            'items' => [[
                'id'       => $alegra_item,
                'type'     => $delta < 0 ? 'out' : 'in',
                'quantity' => abs($delta),
                'unitCost' => $this->unit_cost($product),
            ]],
        ];

        $wh = $this->resolve_warehouse_id();
        if ($wh !== '') {
            $payload['warehouse'] = ['id' => $wh];
        }

        return $payload;
    }

    /**
     * Costo unitario para `unitCost` (requerido). Misma fuente que
     * `Products::product_unit_cost()` (`_wc_cog_cost`/`_cost`).
     *
     * FIX-12 (D12): Alegra puede rechazar `unitCost = 0` (422). Nunca devolver
     * 0: costo → precio del producto → 1.0.
     */
    private function unit_cost(\WC_Product $product): float
    {
        foreach (['_wc_cog_cost', '_cost'] as $key) {
            $raw = get_post_meta($product->get_id(), $key, true);
            if ($raw !== '' && $raw !== null && is_numeric($raw) && (float) $raw > 0) {
                return (float) $raw;
            }
        }
        $price = (float) $product->get_price();
        return $price > 0 ? $price : 1.0;
    }

    /**
     * Bodega configurada o '' (misma semántica que
     * `Products::resolve_warehouse_id()`, `Products.php:1104-1110`).
     */
    private function resolve_warehouse_id(): string
    {
        if (!get_option('alegra_connector_warehouse_enabled')) {
            return '';
        }
        return (string) get_option('alegra_connector_warehouse_id', '');
    }
}
