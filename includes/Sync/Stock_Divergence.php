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
