<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Único escritor de `_manage_stock` / `_stock` / `_stock_status` del plugin
 * (D3 / docs/sdd/inventory/REQ-DIV-1). W1 (import) y W2 (poll) delegan acá.
 *
 * @package Alegra\Connector\Sync
 */
final class Inventory_Writer
{
    private ?Logger\Logger $logger;

    public function __construct(?Logger\Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param array $opts {
     *   source: 'alegra'|'woocommerce',
     *   preserve: bool,
     *   manage_stock: 'enable'|'respect',
     *   dry_run: bool,
     *   warehouse_id: string,
     * }
     * @return string updated|clamped_negative|skipped_no_qty|skipped_service
     *                |skipped_not_manageable|skipped_source|skipped_preserve
     *                |skipped_parent|dry_run|error
     */
    public function apply(\WC_Product $product, array $item, array $opts = []): string
    {
        // Defaults por fusión: una sola tabla, sin `??` por clave suelta.
        $opts = $opts + [
            'source'       => 'alegra',
            'preserve'     => false,
            'manage_stock' => 'respect',
            'dry_run'      => false,
            'warehouse_id' => '',
        ];

        // 1) Fuente. WooCommerce es dueño ⇒ el plugin no escribe.
        if ((string) $opts['source'] === 'woocommerce') {
            return 'skipped_source';
        }

        // 2) Preservar. `inventory` en preserve_fields ⇒ no tocar stock.
        if ($opts['preserve'] === true) {
            return 'skipped_preserve';
        }

        // 3) Variable PADRE: nunca maneja stock en WC (lo hacen las variaciones).
        if ($product->is_type('variable')) {
            $product->set_manage_stock(false);
            return 'skipped_parent';
        }

        // 4) Servicio: Alegra no manda objeto `inventory` ⇒ WC no gestiona stock.
        $inventory = $item['inventory'] ?? null;
        if (!is_array($inventory)) {
            $product->set_manage_stock(false);
            return 'skipped_service';
        }

        // 5) `manage_stock` legacy: con 'respect' se conserva el estado actual
        //    (idéntico a HEAD); con 'enable' (opt-in T2.5) se habilita.
        if ($opts['manage_stock'] !== 'enable' && !$product->get_manage_stock()) {
            return 'skipped_not_manageable';
        }

        // Comportamiento HEAD W1: un item inventariable con 'enable' habilita
        // `_manage_stock` aunque no haya cantidad usable (nunca escribe 0).
        // El caller persiste con su `save()`; el writer sólo lo hace si escribe.
        if ($opts['manage_stock'] === 'enable') {
            $product->set_manage_stock(true);
        }

        // 6) Cantidad: nulo ≠ cero; negativo ⇒ clamp 0 + WARN.
        [$qty, $reason] = $this->resolve_quantity($item, (string) $opts['warehouse_id']);
        if ($qty === null) {
            $this->log('warning', 'Skipping stock update: Alegra returned no usable availableQuantity', $product, $item);
            return 'skipped_no_qty';
        }
        if ($reason === 'clamped') {
            $this->log('warning', 'Clamping negative Alegra stock to 0', $product, $item, ['original' => $item['inventory']['availableQuantity'] ?? null]);
        }

        // 7) Dry-run: reporta sin escribir.
        if ($opts['dry_run'] === true) {
            return 'dry_run';
        }

        // 8) Escritura única.
        $this->apply_stock($product, $qty);

        return $reason === 'clamped' ? 'clamped_negative' : 'updated';
    }

    /**
     * Extrae la cantidad. Warehouse-aware sólo si el caller pasa `warehouse_id`
     * (REQ-INV-06 se difiere en T4.4; `''` ⇒ lee el total, como HEAD).
     *
     * @return array{0:int|null,1:string} [qty|null, reason]
     */
    private function resolve_quantity(array $item, string $warehouse_id): array
    {
        $qty = $item['inventory']['availableQuantity'] ?? null;

        if ($warehouse_id !== ''
            && isset($item['inventory']['warehouses'])
            && is_array($item['inventory']['warehouses'])) {
            foreach ($item['inventory']['warehouses'] as $w) {
                if (is_array($w) && (string) ($w['id'] ?? '') === $warehouse_id) {
                    $qty = $w['availableQuantity'] ?? $qty;
                    break;
                }
            }
        }

        if ($qty === null || $qty === '' || !is_numeric($qty)) {
            return [null, 'no_qty'];
        }
        $qty = (int) $qty;
        if ($qty < 0) {
            return [0, 'clamped'];
        }
        return [$qty, 'ok'];
    }

    /**
     * ÚNICO punto de escritura de stock. Usa la API recomendada de WC.
     *
     * `wc_update_product_stock()` sólo actúa si el producto `managing_stock()`,
     * por eso `set_manage_stock(true)` va ANTES. Con `$updating=false` llama
     * `save()`, que deriva `_stock_status` respetando backorders + umbral y
     * dispara `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`.
     */
    private function apply_stock(\WC_Product $p, int $qty): void
    {
        $p->set_manage_stock(true);

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($p, $qty, 'set', false);
        } else {
            // Fallback WC < 3.0 (G7 / DR12). No dispara los hooks de stock.
            $p->set_stock_quantity($qty);
            $p->save();
        }
    }

    /**
     * Logger defensivo (el writer puede construirse sin logger).
     */
    private function log(string $level, string $message, \WC_Product $product, array $item, array $extra = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $context = ['product_id' => $product->get_id(), 'alegra_id' => (string) ($item['id'] ?? '')] + $extra;
        $this->logger->{$level}($message, $context);
    }
}
