<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detección/causa/informe de divergencia WC↔Alegra (D4).
 * T1.8: firmas. T5.1: record()/clear() + causa. T5.2: report().
 */
final class Stock_Divergence
{
    public static function record(int $product_id, array $entry): void
    {
        // T5.1
    }

    /**
     * D4 §5.1 / Oracle#2: causa calculable desde el poll. DOS parámetros
     * (`$owner`, `$s`); nunca un `WC_Order` (el poll no tiene un pedido por
     * producto y `declare(strict_types=1)` lanzaría TypeError).
     *
     * Fase 3 sólo produce `baseline_ausente` (`S===''`) y `divergencia_dueno`
     * (resto). Las causas que requieren pedido se resuelven en el informe
     * (`T5.2::report()`), no acá.
     */
    public static function divergence_cause(string $owner, int|string $s): string
    {
        if ($s === '') {
            return 'baseline_ausente';
        }
        return 'divergencia_dueno';
    }

    /**
     * @return array{items:array,total:int}
     */
    public static function report(int $limit = 20, int $offset = 0): array
    {
        return ['items' => [], 'total' => 0]; // T5.2
    }

    public static function clear(int $product_id): void
    {
        // T5.1
    }
}
