<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query paginada de la pantalla "Facturas por subir" + conteo cacheado.
 * T1.7: firmas. T4.10: query real. T4.11: refresh_count()/count().
 */
final class Invoice_Queue
{
    /**
     * @param array<string,mixed> $filters
     * @return array{ids:int[],total:int}
     */
    public static function query(array $filters = [], int $paged = 1, int $per_page = 20): array
    {
        return ['ids' => [], 'total' => 0]; // T4.10
    }

    public static function refresh_count(): int
    {
        return 0; // T4.11
    }

    public static function count(): int
    {
        return 0; // T4.11
    }
}
