<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clasificador puro de fallos de factura + ledger por pedido (D3).
 * T4.1: clasificador completo (429/5xx/4xx, errores de datos, dry-run/gate/skip).
 * T4.2: persist()/clear()/get() (CRUD HPOS-safe de los 7 metas).
 */
final class Invoice_Failure
{
    public const META_STATE     = '_alegra_invoice_sync_state';
    public const META_CODE      = '_alegra_invoice_error_code';
    public const META_MESSAGE   = '_alegra_invoice_error_message';
    public const META_RETRIABLE = '_alegra_invoice_error_retriable';
    public const META_ATTEMPTS  = '_alegra_invoice_attempts';
    public const META_LAST      = '_alegra_invoice_last_attempt';
    public const META_NEXT      = '_alegra_invoice_next_retry';

    /**
     * Clasificador puro. NO persiste, NO llama a la API.
     *
     * @param array|\WP_Error $result Resultado de un write (Client::create_invoice, etc.).
     * @return array{state:string,code:string,message:string,retriable:bool,persist:bool}
     */
    public static function classify(array|\WP_Error $result): array
    {
        // 1) Marker de bloqueo (array): dry-run NO persiste; gate ⇒ blocked.
        if (is_array($result) && \Alegra\Connector\API\Client::write_was_blocked($result)) {
            if (\Alegra\Connector\API\Client::is_dry_run_response($result)) {
                return ['state' => 'blocked', 'code' => 'dry_run', 'message' => '', 'retriable' => false, 'persist' => false];
            }
            $reason = (string) ($result['reason'] ?? 'unknown');
            return ['state' => 'blocked', 'code' => 'blocked:' . $reason, 'message' => '', 'retriable' => false, 'persist' => true];
        }

        // 1.b) Marker de skip (array): manual-only ⇒ NO retriable, NO persiste
        // (Oracle#10/DEF-10). El `adjustment_manual_only` devuelve
        // `['skipped'=>true, 'manual_only'=>true, 'reason'=>…]`; sin esta rama
        // temprana cae al fallback `failed_retriable`/`persist=true` y el cron lo
        // reintenta hasta el tope. `skipped` NO es `blocked`.
        if (is_array($result) && !empty($result['skipped'])) {
            return [
                'state'     => 'skipped',
                'code'      => (string) ($result['reason'] ?? 'skipped'),
                'message'   => '',
                'retriable' => false,
                'persist'   => false,
            ];
        }

        // 2) Éxito (array con id): resolved (limpia el ledger).
        if (is_array($result) && isset($result['id']) && (string) $result['id'] !== '') {
            return ['state' => 'resolved', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
        }

        // 3) WP_Error.
        if ($result instanceof \WP_Error) {
            $code    = (string) $result->get_error_code();
            $message = wp_strip_all_tags((string) $result->get_error_message());
            $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);

            // Red / timeout / 429 / 5xx / JSON / lock ⇒ retriable.
            if (in_array($code, ['rate_limited', 'http_request_failed', 'http_request_timeout', 'json_error', 'max_retries'], true)) {
                return ['state' => 'failed_retriable', 'code' => $code, 'message' => $message, 'retriable' => true, 'persist' => true];
            }
            if ($code === 'invoice_in_progress') {
                return ['state' => 'failed_retriable', 'code' => 'lock', 'message' => $message, 'retriable' => true, 'persist' => true];
            }

            $data = $result->get_error_data();
            $http = is_array($data) ? (int) ($data['code'] ?? 0) : 0;

            if ($code === 'api_error' && ($http === 429 || $http >= 500)) {
                return ['state' => 'failed_retriable', 'code' => (string) $http, 'message' => $message, 'retriable' => true, 'persist' => true];
            }
            if ($code === 'api_error' && in_array($http, [400, 401, 403, 404, 409, 422], true)) {
                return ['state' => 'failed_permanent', 'code' => (string) $http, 'message' => $message, 'retriable' => false, 'persist' => true];
            }

            // Datos: permanentes. `draft_invoice_not_opened` se agrega (C9).
            $permanent = ['customer_unresolved', 'invoice_item_unlinked', 'invoice_shipping_unlinked',
                          'invoice_fee_unlinked', 'invoice_still_draft', 'draft_invoice_not_opened'];
            if (in_array($code, $permanent, true)) {
                return ['state' => 'failed_permanent', 'code' => 'data:' . $code, 'message' => $message, 'retriable' => false, 'persist' => true];
            }

            // Desconocido ⇒ retriable (seguro: el cron lo reintenta con tope).
            return ['state' => 'failed_retriable', 'code' => $code, 'message' => $message, 'retriable' => true, 'persist' => true];
        }

        // Fallback conservador.
        return ['state' => 'failed_retriable', 'code' => 'unknown', 'message' => '', 'retriable' => true, 'persist' => true];
    }

    /**
     * Persiste el resultado de un fallo en los 7 metas del pedido vía CRUD
     * (HPOS-safe). No toca `_alegra_invoice_id`.
     *
     * @param array{state:string,code:string,message:string,retriable:bool,persist:bool} $c
     * @param int|null $next_retry_ts Timestamp GMT del próximo reintento (opcional).
     */
    public static function persist(\WC_Order $order, array $c, ?int $next_retry_ts = null): void
    {
        if (empty($c['persist'])) {
            return; // dry-run/skip: no se persiste (REQ-QUEUE-02).
        }

        $order->update_meta_data(self::META_STATE, (string) $c['state']);
        $order->update_meta_data(self::META_CODE, (string) $c['code']);
        $order->update_meta_data(self::META_MESSAGE, (string) $c['message']);
        $order->update_meta_data(self::META_RETRIABLE, !empty($c['retriable']) ? '1' : '0');

        // B3/REQ-QUEUE-06: `WP_Meta_Query` con `META_ATTEMPTS < max` usa INNER
        // JOIN; un pedido recién fallado SIN esta fila queda excluido del cron
        // para SIEMPRE. Sembrarla en 0 en el primer fallo. No se pisa el valor
        // existente para no perder la cuenta entre reintentos (T4.7).
        if (!$order->meta_exists(self::META_ATTEMPTS)) {
            $order->update_meta_data(self::META_ATTEMPTS, 0);
        }

        $order->update_meta_data(self::META_LAST, gmdate('Y-m-d H:i:s'));
        if ($next_retry_ts !== null) {
            $order->update_meta_data(self::META_NEXT, gmdate('Y-m-d H:i:s', $next_retry_ts));
        }

        $order->save();
    }

    /** Éxito: limpia el ledger y marca resolved. NO toca `_alegra_invoice_id`. */
    public static function clear(\WC_Order $order): void
    {
        $order->update_meta_data(self::META_STATE, 'resolved');
        foreach ([self::META_CODE, self::META_MESSAGE, self::META_RETRIABLE, self::META_NEXT, self::META_ATTEMPTS] as $k) {
            $order->delete_meta_data($k);
        }
        $order->save();
    }

    /** @return array<string,string> */
    public static function get(\WC_Order $order): array
    {
        return [
            'state'     => (string) $order->get_meta(self::META_STATE, true),
            'code'      => (string) $order->get_meta(self::META_CODE, true),
            'message'   => (string) $order->get_meta(self::META_MESSAGE, true),
            'retriable' => (string) $order->get_meta(self::META_RETRIABLE, true),
            'attempts'  => (string) $order->get_meta(self::META_ATTEMPTS, true),
            'last'      => (string) $order->get_meta(self::META_LAST, true),
            'next'      => (string) $order->get_meta(self::META_NEXT, true),
        ];
    }
}
