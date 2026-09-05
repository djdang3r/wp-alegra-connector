<?php
/**
 * Professional pagination helper for Alegra Connector admin pages.
 *
 * Usage:
 *   $base = add_query_arg(['filter'=>$filter,...], remove_query_arg('paged'));
 *   echo alegra_pagination($current_page, $total_pages, $base);
 *
 * Shows:
 *   « First  ‹ Prev   [1] 2 3 ... 8 9 10   Next ›  Last »
 *   plus a status line: "Showing 21–40 of 225"
 *
 * @param int    $current Current page (1-based).
 * @param int    $total   Total number of pages.
 * @param string $base    Base URL (without paged query arg).
 * @param int|null $total_items Optional total number of items for "showing X–Y of Z" text.
 * @param int|null $per_page  Optional per-page number (defaults to 20).
 * @return string HTML markup.
 */
function alegra_pagination(int $current, int $total, string $base_url, ?int $total_items = null, ?int $per_page = null): string
{
    if ($total <= 1) {
        return '';
    }

    $adjacent = 2; // pages to show around current

    $html = '<nav class="ac-pagination" role="navigation" aria-label="' . esc_attr__('Páginación', 'alegra-connector') . '">';

    // "Showing X–Y of Z" status line
    if ($total_items !== null) {
        $pp = $per_page ?? 20;
        $from = ($current - 1) * $pp + 1;
        $to = min($current * $pp, $total_items);
        $html .= '<span class="ac-pg-info">';
        $html .= sprintf(
            esc_html__('Mostrando %d-%d de %d', 'alegra-connector'),
            $from,
            $to,
            $total_items
        );
        $html .= '</span>';
    }

    $html .= '<div class="ac-pg-buttons">';

    // First page
    if ($current > 1) {
        $html .= '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="ac-pg-btn ac-pg-edge" title="' . esc_attr__('Primera pagina', 'alegra-connector') . '" aria-label="' . esc_attr__('Primera pagina', 'alegra-connector') . '">&laquo;</a>';
        $html .= '<a href="' . esc_url(add_query_arg('paged', $current - 1, $base_url)) . '" class="ac-pg-btn" title="' . esc_attr__('Página anterior', 'alegra-connector') . '" aria-label="' . esc_attr__('Anterior', 'alegra-connector') . '">&lsaquo;</a>';
    } else {
        $html .= '<span class="ac-pg-btn ac-pg-edge ac-pg-disabled" aria-disabled="true">&laquo;</span>';
        $html .= '<span class="ac-pg-btn ac-pg-disabled" aria-disabled="true">&lsaquo;</span>';
    }

    // Page numbers
    $start = max(1, $current - $adjacent);
    $end = min($total, $current + $adjacent);

    // Leading ellipsis
    if ($start > 1) {
        $html .= '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="ac-pg-btn">1</a>';
        if ($start > 2) {
            $html .= '<span class="ac-pg-dots" aria-hidden="true">&hellip;</span>';
        }
    }

    // Page window
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $current) {
            $html .= '<span class="ac-pg-btn ac-pg-current" aria-current="page">' . $i . '</span>';
        } else {
            $html .= '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '" class="ac-pg-btn" aria-label="' . esc_attr(sprintf(__('Ir a pagina %d', 'alegra-connector'), $i)) . '">' . $i . '</a>';
        }
    }

    // Trailing ellipsis
    if ($end < $total) {
        if ($end < $total - 1) {
            $html .= '<span class="ac-pg-dots" aria-hidden="true">&hellip;</span>';
        }
        $html .= '<a href="' . esc_url(add_query_arg('paged', $total, $base_url)) . '" class="ac-pg-btn">' . $total . '</a>';
    }

    // Last page
    if ($current < $total) {
        $html .= '<a href="' . esc_url(add_query_arg('paged', $current + 1, $base_url)) . '" class="ac-pg-btn" title="' . esc_attr__('Página siguiente', 'alegra-connector') . '" aria-label="' . esc_attr__('Siguiente', 'alegra-connector') . '">&rsaquo;</a>';
        $html .= '<a href="' . esc_url(add_query_arg('paged', $total, $base_url)) . '" class="ac-pg-btn ac-pg-edge" title="' . esc_attr__('Última pagina', 'alegra-connector') . '" aria-label="' . esc_attr__('Última', 'alegra-connector') . '">&raquo;</a>';
    } else {
        $html .= '<span class="ac-pg-btn ac-pg-disabled" aria-disabled="true">&rsaquo;</span>';
        $html .= '<span class="ac-pg-btn ac-pg-edge ac-pg-disabled" aria-disabled="true">&raquo;</span>';
    }

    $html .= '</div>'; // .ac-pg-buttons
    $html .= '</nav>';

    return $html;
}
