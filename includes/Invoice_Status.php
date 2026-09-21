<?php

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mapping between Alegra invoice statuses and the admin UI.
 *
 * Alegra documents exactly four invoice statuses
 * (https://developer.alegra.com/reference/get_invoices.md): `open`, `closed`,
 * `draft` and `void`. `paid` is kept for the cached values written by older
 * versions and the test mock. Anything unknown must never render as
 * "Facturado", which is what the old template did.
 */
final class Invoice_Status
{
    public const DRAFT = 'draft';
    public const OPEN = 'open';
    public const PAID = 'paid';
    public const CLOSED = 'closed';
    public const VOID = 'void';

    public static function normalize(string $status): string
    {
        return strtolower(trim($status));
    }

    public static function is_void(string $status): bool
    {
        return self::normalize($status) === self::VOID;
    }

    public static function badge_class(string $status): string
    {
        switch (self::normalize($status)) {
            case self::DRAFT:
                return 'warning';
            case self::OPEN:
            case self::PAID:
            case self::CLOSED:
                return 'success';
            case self::VOID:
                return 'danger';
            default:
                return 'neutral';
        }
    }

    public static function label(string $status): string
    {
        switch (self::normalize($status)) {
            case self::DRAFT:
                return __('Borrador', 'alegra-connector');
            case self::OPEN:
                return __('Abierta', 'alegra-connector');
            case self::PAID:
            case self::CLOSED:
                return __('Pagada', 'alegra-connector');
            case self::VOID:
                return __('Anulada en Alegra', 'alegra-connector');
            default:
                return __('Sin estado', 'alegra-connector');
        }
    }
}
