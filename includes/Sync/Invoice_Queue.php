<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query paginada de la pantalla "Facturas por subir" + conteo cacheado.
 * T4.10: query real (4 estados + nunca-intentados). T4.11: refresh_count()/count().
 */
final class Invoice_Queue
{
    public const PER_PAGE = 20;
    private const STATES  = ['failed_retriable', 'failed_permanent', 'blocked', 'payment_missing'];

    /**
     * @param array{state?:string,from?:string,to?:string,s?:string} $filters
     * @return array{ids:array<int,int>,total:int}
     */
    public static function query(array $filters = [], int $page = 1, int $per_page = self::PER_PAGE): array
    {
        $meta = self::meta_query($filters);
        $args = [
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => $per_page,
            'paged'      => max(1, $page),
            'paginate'   => true,                 // G4: ->orders + ->total
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => $meta,
        ];
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $args['date_created'] = trim(($filters['from'] ?? '') . '...' . ($filters['to'] ?? ''), '.');
        }
        $res = wc_get_orders($args);
        if (is_object($res) && isset($res->orders)) {
            $ids = array_map(static fn ($o) => (int) $o->get_id(), $res->orders);
            return ['ids' => $ids, 'total' => (int) ($res->total ?? count($ids))];
        }
        // G4 Rama B: sin `paginate`, `wc_get_orders` devuelve `WC_Order[]` (no ids).
        // Mapear el objeto a su id: `array_map('intval', $objetos)` casteaba cada
        // `WC_Order` a `1` (DEF-11, G4 Rama B roto). Se acepta también un int por
        // si algún caller usó `return=ids`.
        $ids = [];
        foreach ((array) $res as $o) {
            $ids[] = is_object($o) ? (int) $o->get_id() : (int) $o;
        }
        return ['ids' => $ids, 'total' => count($ids)];
    }

    /** G4 rama A: conteo O(1). G4 rama B: limit acotado + caché. */
    public static function refresh_count(): int
    {
        $args = [
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => 1,
            'paginate'   => true,
            'return'     => 'ids',
            'meta_query' => self::meta_query([]),
        ];
        $res = wc_get_orders($args);
        if (is_object($res) && isset($res->total)) {
            $count = (int) $res->total;
        } else {
            // G4 Rama B (sin `paginate`): conteo acotado a 200. Es una APROXIMACIÓN
            // documentada (DEF-11): con >200 fallos el badge queda en 200 (truncado,
            // no silencioso). La ruta real (Rama A) usa `->total`, O(1) exacto.
            $count = count((array) wc_get_orders([
                'status'     => ['processing', 'completed', 'on-hold'],
                'limit'      => 200,
                'return'     => 'ids',
                'meta_query' => self::meta_query([]),
            ]));
        }

        update_option('alegra_connector_invoice_failures_count', $count, false);
        update_option('alegra_connector_invoice_failures_hash', self::failure_hash($count), false);
        return $count;
    }

    public static function count(): int
    {
        return max(0, (int) get_option('alegra_connector_invoice_failures_count', 0));
    }

    /** Alias de filtro (C6): `failed` = los DOS estados terminales. */
    private const STATE_ALIASES = [
        'failed' => ['failed_retriable', 'failed_permanent'],
    ];

    /**
     * Hash canónico del aviso dismissible (C4). Fuente única para T4.10/T6.6:
     * SIEMPRE `md5((string) $count)`. T6.6 delega acá, no escribe `(string) $count`.
     */
    public static function failure_hash(int $count): string
    {
        return md5((string) $count);
    }

    /** @return list<string> Estados efectivos del filtro ([] = sin filtro). */
    private static function resolve_states(string $state): array
    {
        if ($state === '') {
            return [];
        }
        if (isset(self::STATE_ALIASES[$state])) {
            return self::STATE_ALIASES[$state];
        }
        return in_array($state, self::STATES, true) ? [$state] : [];
    }

    private static function meta_query(array $filters): array
    {
        $states = self::resolve_states((string) ($filters['state'] ?? ''));
        if ($states !== []) {
            // Filtro explícito por estado ⇒ SÓLO el ledger. Excluye los
            // nunca-intentados del bulk de reintento (C6): no hay qué reintentar.
            return ['key' => Invoice_Failure::META_STATE, 'value' => $states, 'compare' => 'IN'];
        }
        $ledger = ['key' => Invoice_Failure::META_STATE, 'value' => self::STATES, 'compare' => 'IN'];
        // Nunca-intentado = `_alegra_invoice_id` AUSENTE **o** VACÍO (`''`) **y** sin
        // ledger. `NOT EXISTS` solo NO alcanza: un meta guardado como `''` existe y
        // quedaría afuera (DEF-11; `design.md` §4.4: "ausente **o** vacío"). El stub
        // distingue `= ''` de `NOT EXISTS`.
        $never  = ['relation' => 'AND',
            ['relation' => 'OR',
                ['key' => '_alegra_invoice_id', 'compare' => 'NOT EXISTS'],
                ['key' => '_alegra_invoice_id', 'value' => '', 'compare' => '='],
            ],
            ['key' => Invoice_Failure::META_STATE, 'compare' => 'NOT EXISTS'],
        ];
        return ['relation' => 'OR', $ledger, $never];
    }
}
