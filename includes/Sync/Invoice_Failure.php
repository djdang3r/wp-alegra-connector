<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clasificador puro de fallos de factura + ledger por pedido (D3).
 * T1.6: superficie + shape + mapeo conservador.
 * T4.1: tabla completa (429/5xx/4xx, errores de datos) + símbolo G2.
 * T4.2: persist()/clear() (CRUD HPOS-safe de los 7 metas).
 */
final class Invoice_Failure
{
    public const META_STATE      = '_alegra_invoice_sync_state';
    public const META_CODE       = '_alegra_invoice_error_code';
    public const META_MESSAGE    = '_alegra_invoice_error_message';
    public const META_RETRIABLE  = '_alegra_invoice_error_retriable';
    public const META_ATTEMPTS   = '_alegra_invoice_attempts';
    public const META_LAST       = '_alegra_invoice_last_attempt';
    public const META_NEXT_RETRY = '_alegra_invoice_next_retry';

    /**
     * @param array|\WP_Error $result
     * @return array{state:string,code:string,message:string,retriable:bool,persist:bool}
     */
    public static function classify(array|\WP_Error $result): array
    {
        if ($result instanceof \WP_Error) {
            return [
                'state'     => 'failed_retriable',
                'code'      => (string) $result->get_error_code(),
                'message'   => mb_substr((string) $result->get_error_message(), 0, 500),
                'retriable' => true,
                'persist'   => true,
            ];
        }
        if (is_array($result) && !empty($result['blocked_by_gate'])) {
            return [
                'state' => 'blocked', 'code' => 'blocked:' . (string) ($result['reason'] ?? ''),
                'message' => '', 'retriable' => false, 'persist' => true,
            ];
        }
        if (is_array($result) && \Alegra\Connector\API\Client::is_dry_run_response($result)) {
            return ['state' => 'idle', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
        }
        if (is_array($result) && !empty($result['id'])) {
            return ['state' => 'resolved', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
        }
        return ['state' => 'failed_retriable', 'code' => 'unknown', 'message' => '', 'retriable' => true, 'persist' => true];
    }

    public static function persist(\WC_Order $order, array $classification): void
    {
        // T4.2: CRUD HPOS-safe de los 7 metas. T1.6 deja la superficie.
    }

    public static function clear(\WC_Order $order): void
    {
        // T4.2: borra el ledger sin tocar _alegra_invoice_id. T1.6 deja la superficie.
    }

    /** @return array<string,mixed> */
    public static function get(\WC_Order $order): array
    {
        return [
            'state'      => (string) $order->get_meta(self::META_STATE, true),
            'code'       => (string) $order->get_meta(self::META_CODE, true),
            'message'    => (string) $order->get_meta(self::META_MESSAGE, true),
            'retriable'  => (string) $order->get_meta(self::META_RETRIABLE, true) === '1',
            'attempts'   => (int) $order->get_meta(self::META_ATTEMPTS, true),
            'last'       => (string) $order->get_meta(self::META_LAST, true),
            'next_retry' => (string) $order->get_meta(self::META_NEXT_RETRY, true),
        ];
    }
}
