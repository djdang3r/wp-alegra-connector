<?php
/**
 * "Facturas por subir" — cola de facturas fallidas (T6.3, REQ-QUEUE-03/08).
 *
 * Read-only salvo el reintento/ignorar explícito (AJAX bajo run_explicit).
 * Renderiza el ledger (estados terminales) + los nunca-intentados, con las
 * columnas/filtros/paginación de design.md §4.4.
 *
 * @package Alegra\Connector
 */
if (!defined('ABSPATH')) exit;

$queue_rows    = is_array($queue_rows ?? null) ? $queue_rows : [];
$queue_total   = (int) ($queue_total ?? 0);
$queue_page    = max(1, (int) ($queue_page ?? 1));
$queue_filters = is_array($queue_filters ?? null) ? $queue_filters : [];
$per_page      = \Alegra\Connector\Sync\Invoice_Queue::PER_PAGE;
$total_pages   = $per_page > 0 ? (int) ceil($queue_total / $per_page) : 1;

include __DIR__ . '/header.php';

$ac_base_args = ['page' => 'alegra-connector-invoice-queue'];
foreach ($queue_filters as $ac_k => $ac_v) {
    if ($ac_v !== '') {
        $ac_base_args[$ac_k] = $ac_v;
    }
}
$ac_queue_url = admin_url('admin.php');

$ac_state_labels = [
    ''                 => __('Todos los estados', 'alegra-connector'),
    'never'            => __('Nunca intentado', 'alegra-connector'),
    'failed_retriable' => __('Reintentable', 'alegra-connector'),
    'failed_permanent' => __('Permanente', 'alegra-connector'),
    'blocked'          => __('Bloqueado', 'alegra-connector'),
    'payment_missing'  => __('Pago pendiente', 'alegra-connector'),
];
?>
<div class="alegra-connector-wrap">
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Facturas por subir', 'alegra-connector'); ?></h2>
        <span class="ac-badge" style="margin-left:auto;"><?php echo esc_html((string) $queue_total); ?></span>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);">
        <?php esc_html_e('Pedidos que no se pudieron subir a Alegra. Reintentá uno por uno o en lote; los ya facturados se saltean.', 'alegra-connector'); ?>
    </p>

    <form method="get" action="<?php echo esc_url($ac_queue_url); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:12px 0;">
        <input type="hidden" name="page" value="alegra-connector-invoice-queue">
        <label style="font-size:12px;"><?php esc_html_e('Estado', 'alegra-connector'); ?>
            <select name="state">
                <?php foreach ($ac_state_labels as $ac_value => $ac_label): ?>
                    <option value="<?php echo esc_attr($ac_value); ?>" <?php selected((string) ($queue_filters['state'] ?? ''), (string) $ac_value); ?>><?php echo esc_html($ac_label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="font-size:12px;"><?php esc_html_e('Desde', 'alegra-connector'); ?>
            <input type="date" name="from" value="<?php echo esc_attr((string) ($queue_filters['from'] ?? '')); ?>">
        </label>
        <label style="font-size:12px;"><?php esc_html_e('Hasta', 'alegra-connector'); ?>
            <input type="date" name="to" value="<?php echo esc_attr((string) ($queue_filters['to'] ?? '')); ?>">
        </label>
        <label style="font-size:12px;"><?php esc_html_e('Buscar', 'alegra-connector'); ?>
            <input type="search" name="s" value="<?php echo esc_attr((string) ($queue_filters['s'] ?? '')); ?>" placeholder="<?php esc_attr_e('N° de pedido o email', 'alegra-connector'); ?>">
        </label>
        <button type="submit" class="ac-btn ac-btn-sm ac-btn-primary"><?php esc_html_e('Filtrar', 'alegra-connector'); ?></button>
    </form>

    <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button type="button" class="ac-btn ac-btn-primary alegra-sync-pending-orders" data-scope="failed">
            <?php esc_html_e('Reintentar seleccionados', 'alegra-connector'); ?>
        </button>
        <span class="ac-selected-count" style="font-size:12px;color:var(--ac-text-secondary);"></span>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:28px;"><input type="checkbox" class="alegra-select-all"></th>
                <th><?php esc_html_e('Orden', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Fecha', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Cliente', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Total', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Estado Alegra', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Estado', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Motivo', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Código', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Intentos', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Último intento', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Próximo intento', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Acciones', 'alegra-connector'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($queue_rows === []): ?>
            <tr><td colspan="13"><?php esc_html_e('No hay facturas por subir.', 'alegra-connector'); ?></td></tr>
        <?php else: ?>
            <?php foreach ($queue_rows as $ac_row): ?>
            <tr>
                <td><input type="checkbox" class="alegra-bulk-check" value="<?php echo esc_attr((string) $ac_row['id']); ?>"></td>
                <td><a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders')); ?>">#<?php echo esc_html((string) $ac_row['id']); ?></a></td>
                <td><?php echo esc_html((string) $ac_row['date']); ?></td>
                <td><?php echo esc_html((string) $ac_row['customer']); ?></td>
                <td><?php echo wp_kses_post(wc_price((float) $ac_row['total'])); ?></td>
                <td><?php echo esc_html((string) $ac_row['invoice_status']); ?></td>
                <td><span class="ac-badge" data-state="<?php echo esc_attr((string) $ac_row['state']); ?>"><?php echo esc_html((string) $ac_row['state_label']); ?></span></td>
                <td><?php echo esc_html((string) $ac_row['message']); ?></td>
                <td><code><?php echo esc_html((string) $ac_row['code']); ?></code></td>
                <td><?php echo esc_html((string) $ac_row['attempts']); ?></td>
                <td><?php echo esc_html((string) $ac_row['last']); ?></td>
                <td><?php echo esc_html((string) $ac_row['next']); ?></td>
                <td>
                    <button type="button" class="ac-btn ac-btn-sm alegra-retry-invoice" data-order-id="<?php echo esc_attr((string) $ac_row['id']); ?>"><?php esc_html_e('Reintentar', 'alegra-connector'); ?></button>
                    <a class="ac-btn ac-btn-sm" href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-logs')); ?>"><?php esc_html_e('Ver log', 'alegra-connector'); ?></a>
                    <button type="button" class="ac-btn ac-btn-sm alegra-ignore-invoice" data-order-id="<?php echo esc_attr((string) $ac_row['id']); ?>"><?php esc_html_e('Ignorar', 'alegra-connector'); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="tablenav" style="margin-top:8px;">
        <?php if ($queue_page > 1): ?>
            <a class="ac-btn ac-btn-sm" href="<?php echo esc_url(add_query_arg(array_merge($ac_base_args, ['paged' => $queue_page - 1]), $ac_queue_url)); ?>">&laquo; <?php esc_html_e('Anterior', 'alegra-connector'); ?></a>
        <?php endif; ?>
        <span style="margin:0 8px;font-size:12px;">
            <?php echo esc_html(sprintf(__('Página %1$d de %2$d', 'alegra-connector'), $queue_page, $total_pages)); ?>
        </span>
        <?php if ($queue_page < $total_pages): ?>
            <a class="ac-btn ac-btn-sm" href="<?php echo esc_url(add_query_arg(array_merge($ac_base_args, ['paged' => $queue_page + 1]), $ac_queue_url)); ?>"><?php esc_html_e('Siguiente', 'alegra-connector'); ?> &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?></div>
