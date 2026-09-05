<?php
if (!defined('ABSPATH')) exit;

$payment_id = (int) get_post_meta($order->get_id(), '_alegra_payment_id', true);
$payment_synced = $payment_id > 0;

// Safe defaults (PHP 8+)
$alegra_invoice_id = $alegra_invoice_id ?? 0;
$alegra_invoice_number = $alegra_invoice_number ?? '';
$alegra_data = $alegra_data ?? null;
$alegra_error = $alegra_error ?? null;

$page_title = sprintf(__('Pedido #%d', 'alegra-connector'), $order->get_id());
if ($alegra_invoice_id > 0) {
    $page_subtitle = __('Facturado en Alegra', 'alegra-connector');
} elseif ($payment_synced) {
    $page_subtitle = __('Pago registrado', 'alegra-connector');
} else {
    $page_subtitle = '';
}
$header_color = 'amber';

include __DIR__ . '/header.php';

// Use API from controller via local variable (avoid $GLOBALS antipattern)
$alegra_api = $alegra_api ?? null;
$alegra_payment_data = null;
if ($payment_synced && get_option('alegra_connector_connection_tested') && $alegra_api) {
    $p = $alegra_api->get_payment($payment_id);
    if (!is_wp_error($p)) {
        $alegra_payment_data = $p;
    }
}

// Generate nonce once for all links in this page
$order_pdf_nonce = wp_create_nonce('alegra_connector_nonce');
?>
<div class="alegra-connector-wrap">

<div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders')); ?>" class="ac-btn ac-btn-sm">&#8592; <?php esc_html_e('Volver', 'alegra-connector'); ?></a>
    <?php if ($alegra_invoice_id > 0): ?>
        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=alegra_get_invoice_pdf&order_id=' . $order->get_id() . '&_ajax_nonce=' . $order_pdf_nonce)); ?>" class="ac-btn ac-btn-sm" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-pdf" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Descargar PDF', 'alegra-connector'); ?></a>
    <?php endif; ?>
    <?php if ($alegra_invoice_id > 0 && !$payment_synced): ?>
        <button class="ac-btn ac-btn-primary ac-btn-sm alegra-record-payment" data-order-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Registrar pago en Alegra', 'alegra-connector'); ?></button>
    <?php endif; ?>
    <?php if (!$alegra_invoice_id): ?>
        <button class="ac-btn ac-btn-primary ac-btn-sm alegra-sync-single" data-type="order" data-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Crear factura', 'alegra-connector'); ?></button>
    <?php endif; ?>
</div>

<div class="ac-detail-grid">
    <div class="ac-detail-panel">
        <h2><span class="dashicons dashicons-cart" style="color:#7f54b3;"></span> WooCommerce</h2>
        <table class="ac-detail-table">
            <tr><th>#</th><td><?php echo esc_html($order->get_id()); ?></td></tr>
            <tr><th><?php esc_html_e('Fecha', 'alegra-connector'); ?></th><td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : ''); ?></td></tr>
            <tr><th><?php esc_html_e('Estado', 'alegra-connector'); ?></th><td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td></tr>
            <tr><th><?php esc_html_e('Cliente', 'alegra-connector'); ?></th><td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td></tr>
            <tr><th>Email</th><td><?php echo esc_html($order->get_billing_email()); ?></td></tr>
            <tr><th><?php esc_html_e('Total', 'alegra-connector'); ?></th><td><strong><?php echo wp_kses_post(wc_price($order->get_total())); ?></strong></td></tr>
            <tr><th><?php esc_html_e('Método de pago', 'alegra-connector'); ?></th><td><?php echo esc_html($order->get_payment_method_title()); ?></td></tr>
            <tr><th><?php esc_html_e('Transacción', 'alegra-connector'); ?></th><td><span class="ac-code"><?php echo esc_html($order->get_transaction_id() ?: '--'); ?></span></td></tr>
        </table>
    </div>

    <div class="ac-detail-panel">
        <h2><span class="dashicons dashicons-cloud" style="color:var(--ac-primary);"></span> Alegra</h2>
        <?php if ($alegra_error): ?>
            <div class="ac-notice error"><p><?php echo esc_html($alegra_error); ?></p></div>
        <?php elseif ($alegra_data): ?>
            <table class="ac-detail-table">
                <tr><th>ID Factura</th><td><span class="ac-code">#<?php echo esc_html($alegra_data['id']); ?></span></td></tr>
                <tr><th><?php esc_html_e('Número', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['number'] ?? '--'); ?></td></tr>
                <tr><th><?php esc_html_e('Fecha', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['date'] ?? ''); ?></td></tr>
                <tr><th><?php esc_html_e('Vencimiento', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['dueDate'] ?? ''); ?></td></tr>
                <tr><th><?php esc_html_e('Estado', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['status'] ?? ''); ?></td></tr>
                <tr><th><?php esc_html_e('Total', 'alegra-connector'); ?></th><td><strong><?php echo isset($alegra_data['total']) ? wp_kses_post(wc_price((float) $alegra_data['total'])) : '--'; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Saldo', 'alegra-connector'); ?></th><td><?php echo isset($alegra_data['balance']) ? wp_kses_post(wc_price((float) $alegra_data['balance'])) : '--'; ?></td></tr>
            </table>
        <?php elseif ($alegra_invoice_id > 0): ?>
            <div class="ac-notice warning"><p><?php esc_html_e('No se pudo cargar Alegra.', 'alegra-connector'); ?></p></div>
        <?php else: ?>
            <div class="ac-empty-state">
                <button class="ac-btn ac-btn-primary alegra-sync-single" data-type="order" data-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Crear factura', 'alegra-connector'); ?></button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="ac-payment-tracking">
    <h2><?php esc_html_e('Pagos - Trazabilidad', 'alegra-connector'); ?></h2>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Fuente', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Método', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Monto', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Fecha', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Transacción', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Estado', 'alegra-connector'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>WooCommerce</strong></td>
                <td><?php echo esc_html($order->get_payment_method_title()); ?></td>
                <td><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
                <td>
                    <?php
                    if ($order->get_date_paid()) {
                        echo esc_html($order->get_date_paid()->date('Y-m-d H:i'));
                    } elseif ($order->is_paid()) {
                        echo esc_html(wp_date('Y-m-d'));
                    } else {
                        echo '--';
                    }
                    ?>
                </td>
                <td><span class="ac-code"><?php echo esc_html($order->get_transaction_id() ?: '--'); ?></span></td>
                <td>
                    <?php if ($order->is_paid()): ?>
                        <span class="ac-badge success"><?php esc_html_e('Pagado', 'alegra-connector'); ?></span>
                    <?php else: ?>
                        <span class="ac-badge warning"><?php esc_html_e('Pendiente', 'alegra-connector'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>Alegra</strong></td>
                <td><?php echo $alegra_payment_data ? esc_html($alegra_payment_data['paymentMethod'] ?? '--') : '--'; ?></td>
                <td><?php echo $alegra_payment_data ? wp_kses_post(wc_price((float) ($alegra_payment_data['amount'] ?? 0))) : '--'; ?></td>
                <td><?php echo $alegra_payment_data ? esc_html($alegra_payment_data['date'] ?? '--') : '--'; ?></td>
                <td>
                    <?php if ($payment_synced): ?>
                        <span class="ac-code">#A-<?php echo esc_html($payment_id); ?></span>
                    <?php else: ?>
                        --
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($payment_synced): ?>
                        <span class="ac-badge success"><?php esc_html_e('Registrado', 'alegra-connector'); ?></span>
                    <?php elseif ($alegra_invoice_id > 0): ?>
                        <button class="ac-btn ac-btn-xs alegra-record-payment" data-order-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Registrar pago', 'alegra-connector'); ?></button>
                    <?php else: ?>
                        <span style="color:var(--ac-text-muted);">--</span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</div>
