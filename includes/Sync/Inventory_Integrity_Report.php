<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

use Alegra\Connector\API;
use Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * C1 — Auditoría de integridad de inventario (SOLO LECTURA).
 *
 * Diagnostica el estado del sync Alegra↔WC sin escribir NADA:
 *
 *  (a) los gates que habilitan/matan de hambre el sync (leídos de las opciones
 *      EXISTENTES; esta clase NO define ni escribe opciones nuevas),
 *  (b) por producto vinculado (`_alegra_item_id`), `WC stock` vs el
 *      `availableQuantity` de Alegra (vía `API\Client::get_items(['mode'=>'advanced', …])`),
 *  (c) el ledger de divergencias registradas (`Stock_Divergence::report()`).
 *
 * PROHIBIDO en esta clase: escribir stock, emitir ajustes, tomar locks, disparar
 * el poll. Es una herramienta de observabilidad: `build()`/`render_text()` no
 * tienen efectos secundarios persistentes. Si la API falla, degrada a un
 * reporte parcial con un warning (nunca lanza).
 *
 * @package Alegra\Connector\Sync
 */
final class Inventory_Integrity_Report
{
    /** Tope de ítems de Alegra comparados por corrida (control de costo). */
    public const DEFAULT_LIMIT = 200;

    private ?API\Client $api;
    private ?Logger\Logger $logger;

    public function __construct(?API\Client $api = null, ?Logger\Logger $logger = null)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Construye el diagnóstico. Nunca lanza: ante un error de la API devuelve
     * un reporte parcial (gates + divergencias) con un warning.
     *
     * @return array{
     *   gates: array<string,mixed>,
     *   products: array<int,array{product_id:int,item_id:string,wc_stock:int,alegra_stock:?int,status:string}>,
     *   summary: array{ok:int,divergent:int,missing:int,total:int},
     *   divergences: array{items:array<int,array<string,mixed>>,total:int},
     *   warnings: string[]
     * }
     */
    public function build(int $limit = self::DEFAULT_LIMIT): array
    {
        $gates    = $this->read_gates();
        $warnings = [];
        $products = [];

        if ($this->api === null) {
            $warnings[] = 'No hay cliente de API disponible: se reportan solo los gates y las divergencias.';
            return $this->package($gates, $products, count($products), $warnings);
        }

        $items = $this->api->get_items([
            'start'           => 0,
            'limit'           => max(1, $limit),
            'mode'            => 'advanced',
            'order_field'     => 'id',
            'order_direction' => 'ASC',
        ]);

        if (is_wp_error($items)) {
            $warnings[] = 'Error de la API de Alegra al listar ítems: ' . $items->get_error_message();
            $this->logger?->warning('Inventory integrity audit: no se pudo listar ítems', [
                'error' => $items->get_error_message(),
            ]);
            $items = [];
        }
        if (!is_array($items)) {
            $items = [];
        }

        $missing = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_id = (string) ($item['id'] ?? '');
            if ($item_id === '') {
                continue;
            }

            // Lookup replicado de Admin_Dashboard::get_product_by_alegra_id()
            // (los ids de Alegra son UUID/VARCHAR ⇒ preparar con %s, no %d).
            $product_id = $this->find_product_by_alegra_id($item_id);
            if ($product_id === null) {
                $missing++;
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                $missing++;
                continue;
            }

            $w      = (int) $product->get_stock_quantity();
            $a_raw  = $item['inventory']['availableQuantity'] ?? null;
            $has_a  = ($a_raw !== null && $a_raw !== '' && is_numeric($a_raw));
            $a      = $has_a ? (int) $a_raw : null;

            $products[] = [
                'product_id'   => $product_id,
                'item_id'      => $item_id,
                'wc_stock'     => $w,
                'alegra_stock' => $a,
                'status'       => ($has_a && $w === $a) ? 'ok' : 'divergent',
            ];
        }

        return $this->package($gates, $products, $missing, $warnings);
    }

    /**
     * Render de texto plano del reporte. Sólo lee; puede llamarse sin `build()`
     * previo (lo invoca internamente).
     */
    public function render_text(int $limit = self::DEFAULT_LIMIT): string
    {
        $r = $this->build($limit);

        $lines   = [];
        $lines[] = '==================================================';
        $lines[] = ' Alegra Connector - Auditoria de integridad de inventario (solo lectura)';
        $lines[] = ' Fecha: ' . (function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'));
        $lines[] = '==================================================';
        $lines[] = '';

        $g = $r['gates'];
        $lines[] = '--- Gates del sync ---';
        $lines[] = sprintf('  %-34s %s', 'inventory_source:', (string) $g['inventory_source']);
        $lines[] = sprintf('  %-34s %s', 'inventory_sync_enabled:', $g['inventory_sync_enabled'] ? 'true' : 'false');
        $lines[] = sprintf('  %-34s %s', 'push_inventory_enabled:', $g['push_inventory_enabled'] ? 'true' : 'false');
        $lines[] = sprintf('  %-34s %s', 'inventory_manage_stock_enabled:', $g['inventory_manage_stock_enabled'] ? 'true' : 'false');
        $lines[] = sprintf('  %-34s %s', 'import_preserve_fields:', $g['import_preserve_fields'] === [] ? '(vacio)' : implode(', ', $g['import_preserve_fields']));
        $lines[] = sprintf('  %-34s %s', 'stock_owner:', (string) $g['stock_owner']);
        $lines[] = sprintf('  %-34s %s', 'stock_owner_resuelto:', (string) $g['stock_owner_resolved']);
        $lines[] = '';

        $lines[] = '--- WC stock vs Alegra availableQuantity ---';
        $lines[] = sprintf('  %-8s %-20s %-10s %-10s %s', 'PID', 'ITEM', 'WC', 'ALEGRA', 'ESTADO');
        if ($r['products'] === []) {
            $lines[] = '  (sin productos vinculados comparados)';
        }
        foreach ($r['products'] as $row) {
            $lines[] = sprintf(
                '  %-8d %-20s %-10d %-10s %s',
                (int) $row['product_id'],
                (string) $row['item_id'],
                (int) $row['wc_stock'],
                $row['alegra_stock'] === null ? '?' : (string) (int) $row['alegra_stock'],
                $row['status'] === 'ok' ? 'OK' : 'DIVERGENT'
            );
        }
        $s = $r['summary'];
        $lines[] = sprintf(
            '  Resumen: OK=%d DIVERGENTES=%d SIN_PRODUCTO=%d TOTAL=%d',
            (int) $s['ok'],
            (int) $s['divergent'],
            (int) $s['missing'],
            (int) $s['total']
        );
        $lines[] = '';

        $lines[] = '--- Divergencias registradas (ledger) ---';
        if (($r['divergences']['items'] ?? []) === []) {
            $lines[] = '  (sin divergencias registradas)';
        }
        foreach (($r['divergences']['items'] ?? []) as $d) {
            $lines[] = sprintf(
                '  PID %-8d WC=%d ALEGRA=%s BASELINE=%s CAUSA=%s',
                (int) ($d['product_id'] ?? 0),
                (int) ($d['w'] ?? 0),
                (string) ($d['a'] ?? '?'),
                is_string($d['s'] ?? null) ? $d['s'] : (string) (int) ($d['s'] ?? 0),
                (string) ($d['cause'] ?? '')
            );
        }
        $lines[] = '';

        if ($r['warnings'] !== []) {
            $lines[] = '--- Warning(s) ---';
            foreach ($r['warnings'] as $warning) {
                $lines[] = '  ! ' . $warning;
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Empaqueta el reporte calculando el resumen.
     *
     * @param array<string,mixed> $gates
     * @param array<int,array<string,mixed>> $products
     * @param string[] $warnings
     * @return array{gates:array<string,mixed>,products:array<int,array<string,mixed>>,summary:array{ok:int,divergent:int,missing:int,total:int},divergences:array<string,mixed>,warnings:string[]}
     */
    private function package(array $gates, array $products, int $missing, array $warnings): array
    {
        $ok        = 0;
        $divergent = 0;
        foreach ($products as $row) {
            if (($row['status'] ?? '') === 'ok') {
                $ok++;
            } else {
                $divergent++;
            }
        }

        return [
            'gates'       => $gates,
            'products'    => $products,
            'summary'     => [
                'ok'        => $ok,
                'divergent' => $divergent,
                'missing'   => $missing,
                'total'     => count($products),
            ],
            'divergences' => Stock_Divergence::report(),
            'warnings'    => array_values($warnings),
        ];
    }

    /**
     * Gates leídos de las opciones EXISTENTES (sin nombres nuevos).
     *
     * @return array<string,mixed>
     */
    private function read_gates(): array
    {
        $preserve = get_option('alegra_connector_import_preserve_fields', []);
        if (!is_array($preserve)) {
            $preserve = [];
        }

        return [
            'inventory_source'               => (string) get_option('alegra_connector_inventory_source', 'alegra'),
            'inventory_sync_enabled'         => (bool) get_option('alegra_connector_inventory_sync_enabled', true),
            'push_inventory_enabled'         => (bool) get_option('alegra_connector_push_inventory_enabled', true),
            'inventory_manage_stock_enabled' => (bool) get_option('alegra_connector_inventory_manage_stock_enabled', false),
            'import_preserve_fields'         => array_values(array_map('strval', $preserve)),
            'stock_owner'                    => (string) get_option('alegra_connector_stock_owner', 'auto'),
            'stock_owner_resolved'           => Inventory_Pusher::owner(),
        ];
    }

    /**
     * Resuelve el `post_id` de un producto por su `_alegra_item_id`.
     * Réplica intencional del query documentado en
     * `admin/Admin/Admin_Dashboard::get_product_by_alegra_id()` (los ids son
     * UUID ⇒ `%s`, nunca `%d`). No se llama el método privado ajeno.
     */
    private function find_product_by_alegra_id(string $alegra_id): ?int
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }
        $pid = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alegra_item_id' AND meta_value=%s LIMIT 1",
            $alegra_id
        ));
        return $pid ? (int) $pid : null;
    }
}
