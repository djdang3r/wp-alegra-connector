<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Kill_Switch;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * C3 — Observabilidad read-only de devoluciones/notas crédito.
 *
 * Alegra aplica una nota crédito y el stock puede cambiar del lado de Alegra
 * (fuente de verdad). WooCommerce se entera por el poll de inventario, pero el
 * MERCHANT necesita una traza en el pedido. Este watcher:
 *
 *  - hace UNA sola `GET /credit-notes` por corrida (acotada por ventana + limit),
 *  - arma un mapa `invoice_id → credit_note_id` desde `invoices[].id` de cada NC,
 *  - para cada pedido vinculado (`_alegra_invoice_id` presente en el mapa)
 *    agrega UNA nota idempotente (meta `_alegra_credit_note_seen`, CRUD/HPOS-safe).
 *
 * NUNCA escribe stock, NUNCA cambia el estado del pedido y NUNCA emite ajustes.
 * Respeta el kill switch. La firma/el almacenamiento de meta usan la API CRUD del
 * pedido (`get_meta`/`update_meta_data`/`save`) para ser HPOS-safe.
 *
 * @package Alegra\Connector\Sync
 */
final class Credit_Note_Watcher
{
    /** @var string Meta del pedido con los ids de NC ya notificados (array). */
    public const SEEN_META = '_alegra_credit_note_seen';

    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api, ?Logger\Logger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Corre la detección una vez. Devuelve un resumen acotado.
     *
     * @param int $window_days Ventana hacia atrás (días) del filtro `date`.
     * @return array{checked:int,noted:int,skipped?:string}
     */
    public function run(int $window_days = 30): array
    {
        if (Kill_Switch::is_active()) {
            $this->logger?->info('Credit note watcher skipped: kill switch active');
            return ['checked' => 0, 'noted' => 0, 'skipped' => 'kill_switch'];
        }

        if ($this->api === null) {
            return ['checked' => 0, 'noted' => 0, 'skipped' => 'no_api'];
        }

        $window = max(1, $window_days);
        $date   = gmdate('Y-m-d', time() - $window * DAY_IN_SECONDS);

        // UNA sola consulta por corrida (control de tasa: 150/min).
        $notes = $this->api->get_credit_notes(['date' => $date, 'limit' => 30]);
        if (is_wp_error($notes) || !is_array($notes)) {
            $this->logger?->warning('Credit note watcher: could not list credit notes');
            return ['checked' => 0, 'noted' => 0, 'skipped' => 'api_error'];
        }

        $map = $this->build_invoice_map($notes);
        if ($map === []) {
            return ['checked' => 0, 'noted' => 0];
        }

        $checked = 0;
        $noted   = 0;
        foreach ($map as $invoice_id => $credit_note_id) {
            // Lookup HPOS-safe: wc_get_orders() lee el storage correcto vía CRUD.
            $found = wc_get_orders([
                'meta_key'   => '_alegra_invoice_id',
                'meta_value' => (string) $invoice_id,
                'limit'      => 1,
                'return'     => 'ids',
            ]);
            if (empty($found)) {
                continue;
            }
            $order = wc_get_order((int) $found[0]);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $checked++;

            $seen = $this->seen_ids($order);
            if (in_array($credit_note_id, $seen, true)) {
                continue;
            }

            $order->add_order_note(sprintf(
                __('[Alegra] Nota crédito #%s registrada para la factura #%s.', 'alegra-connector'),
                $credit_note_id,
                (string) $invoice_id
            ));
            $seen[] = $credit_note_id;
            $order->update_meta_data(self::SEEN_META, $seen);
            $order->save();
            $noted++;

            $this->logger?->info('Credit note watcher: order noted', [
                'order_id'       => (int) $order->get_id(),
                'invoice_id'     => (string) $invoice_id,
                'credit_note_id' => $credit_note_id,
            ]);
        }

        return ['checked' => $checked, 'noted' => $noted];
    }

    /**
     * Mapa `invoice_id → credit_note_id` desde `invoices[].id` de cada NC.
     * Una NC posterior sobre la misma factura sobreescribe la anterior (la más
     * reciente gana); el id quedará registrado igual en `_alegra_credit_note_seen`.
     *
     * @param array<int, mixed> $notes
     * @return array<string, string>
     */
    private function build_invoice_map(array $notes): array
    {
        $map = [];
        foreach ($notes as $note) {
            if (!is_array($note)) {
                continue;
            }
            $credit_note_id = (string) ($note['id'] ?? '');
            if ($credit_note_id === '') {
                continue;
            }
            foreach ((array) ($note['invoices'] ?? []) as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }
                $invoice_id = (string) ($invoice['id'] ?? '');
                if ($invoice_id !== '') {
                    $map[$invoice_id] = $credit_note_id;
                }
            }
        }
        return $map;
    }

    /**
     * Ids de NC ya vistos para el pedido (siempre array de strings).
     *
     * @return string[]
     */
    private function seen_ids(\WC_Order $order): array
    {
        $seen = $order->get_meta(self::SEEN_META, true);
        if (!is_array($seen)) {
            return [];
        }
        return array_values(array_map('strval', $seen));
    }
}
