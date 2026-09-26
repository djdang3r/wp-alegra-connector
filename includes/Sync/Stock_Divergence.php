<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detección/causa/informe de divergencia WC↔Alegra (D4).
 * T5.1: record()/clear() + divergence_cause() (bounded FIFO 200).
 * T5.2: report() paginado + clear() + causa por pedido (memoizada).
 */
final class Stock_Divergence
{
    public const OPTION = 'alegra_connector_stock_divergence';
    private const MAX   = 200;

    /** @param array{w:int,a:int,s:int|string,cause:string,at:int} $row */
    public static function record(int $product_id, array $row): void
    {
        $all = self::all();
        $all[(string) $product_id] = [
            'w'     => (int) $row['w'],
            'a'     => (int) $row['a'],
            's'     => $row['s'],
            'cause' => (string) $row['cause'],
            'at'    => (int) ($row['at'] ?? time()),
        ];
        // Bounded FIFO: conservar las 200 más recientes.
        if (count($all) > self::MAX) {
            uasort($all, static fn ($x, $y) => $y['at'] <=> $x['at']);
            $all = array_slice($all, 0, self::MAX, true);
        }
        update_option(self::OPTION, $all, false); // autoload = false
    }

    public static function clear(int $product_id): void
    {
        $all = self::all();
        unset($all[(string) $product_id]);
        update_option(self::OPTION, $all, false);
    }

    /**
     * Causa de la divergencia (función pura, firma canónica 2 args — B5/DEF-2).
     * El poll recorre PRODUCTOS y no tiene `WC_Order` ⇒ sólo resuelve las causas
     * que no dependen del pedido. Las causas `factura_fallida`/`reembolso`/
     * `factura_pendiente` las completa el informe (`report()`, T5.2), que sí
     * puede buscar el pedido pagado que contiene el producto (design.md §5.1).
     *
     * @param string $owner 'invoice'|'adjustment' (la firma canónica lo conserva;
     *                      la causa del poll no depende del dueño).
     * @param int|string $s  `_alegra_stock_synced` ('' si nunca)
     */
    public static function divergence_cause(string $owner, int|string $s): string
    {
        // 2 args, pura. NUNCA recibe `?\WC_Order`: el 3er arg que pasaba `T3.5`
        // (`$p`, int|'') era un `TypeError` fatal con declare(strict_types=1), y
        // el poll jamás tendría el pedido. Las causas por pedido viven en T5.2.
        return $s === '' ? 'baseline_ausente' : 'divergencia_dueno';
    }

    /**
     * Informe paginado. Lee la option (lo que el poll midió) y **completa** la
     * causa por pedido (`factura_fallida`/`reembolso`/`factura_pendiente`), que el
     * poll no puede resolver porque no tiene `WC_Order` (design.md §5.1).
     * Sin GET por producto: el pedido se busca con `wc_get_orders` acotado y
     * `get_refunds()` se memoiza por `order_id` (NFR-04).
     *
     * @return array{items:array<int,array{product_id:int,w:int,a:int,s:int|string,cause:string,at:int}>,total:int}
     */
    public static function report(int $limit = 20, int $offset = 0): array
    {
        $all = self::all();
        uasort($all, static fn ($x, $y) => $y['at'] <=> $x['at']);
        $total  = count($all);
        $offset = max(0, $offset);
        $slice  = array_slice($all, $offset, $limit, true);

        $owner = Inventory_Pusher::owner();
        $memo  = [];   // memoización por order_id: un `get_refunds()` por pedido.

        $items = [];
        foreach ($slice as $pid => $row) {
            $pid   = (int) $pid;
            $cause = (string) $row['cause'];   // baseline_ausente | divergencia_dueno
            $order = self::find_paid_order_for_product($pid);
            if ($order instanceof \WC_Order) {
                $oid = (int) $order->get_id();
                if (!array_key_exists($oid, $memo)) {
                    $memo[$oid] = self::cause_for_order($order, $owner);
                }
                if ($memo[$oid] !== '') {
                    $cause = $memo[$oid];
                }
            }
            $items[] = [
                'product_id' => $pid,
                'w'          => (int) $row['w'],
                'a'          => (int) $row['a'],
                's'          => $row['s'],
                'cause'      => $cause,
                'at'         => (int) $row['at'],
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Pedido pagado más reciente que contiene el producto (limit acotado). A
     * diferencia del helper de reparación (`T5.4`), NO exige que la factura esté
     * `open`: el informe necesita resolver también `factura_fallida`/`reembolso`.
     */
    private static function find_paid_order_for_product(int $product_id): ?\WC_Order
    {
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
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_product_id() === $product_id
                    || (int) $item->get_variation_id() === $product_id) {
                    return $order;
                }
            }
        }
        return null;
    }

    /** Causa que depende del pedido ('' si ninguna aplica). Orden de design.md §5.1. */
    private static function cause_for_order(\WC_Order $order, string $owner): string
    {
        if ($owner === 'invoice') {
            $ledger = (string) $order->get_meta('_alegra_invoice_sync_state', true);
            if (in_array($ledger, ['failed_retriable', 'failed_permanent', 'blocked', 'payment_missing'], true)) {
                return 'factura_fallida';
            }
        }
        if ((string) $order->get_meta('_alegra_credit_note_id', true) !== ''
            || !empty($order->get_refunds())) {
            return 'reembolso';
        }
        if ($owner === 'invoice'
            && (string) $order->get_meta('_alegra_invoice_status', true) !== 'open') {
            return 'factura_pendiente';
        }
        return '';
    }

    /** @return array<string,array{w:int,a:int,s:int|string,cause:string,at:int}> */
    private static function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }
}
