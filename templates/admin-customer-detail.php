<?php
if (!defined('ABSPATH')) exit;
$alegra_id = (int) get_user_meta($customer->ID, 'alegra_contact_id', true);
$synced = $alegra_id > 0;
$alegra_error = $alegra_error ?? null;
$alegra_data = $alegra_data ?? null;
$page_title = sprintf(__('Cliente: %s', 'alegra-connector'), $customer->display_name);
include __DIR__ . '/header.php';
?>
<div class="alegra-connector-wrap">

    <div style="margin-bottom:14px;">
        <?php if ($synced): ?>
            <span class="ac-sync-flag synced">&#10003; <?php esc_html_e('Sincronizado con Alegra', 'alegra-connector'); ?></span>
        <?php else: ?>
            <span class="ac-sync-flag pending">&#9888; <?php esc_html_e('No sincronizado', 'alegra-connector'); ?></span>
        <?php endif; ?>
    </div>

    <div style="margin-bottom:16px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers')); ?>" class="ac-btn ac-btn-sm">&#8592; <?php esc_html_e('Volver', 'alegra-connector'); ?></a>
        <?php if ($synced): ?>
        <button class="ac-btn ac-btn-sm alegra-import-single" data-type="customer" data-id="<?php echo esc_attr($customer->ID); ?>"><span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Traer desde Alegra', 'alegra-connector'); ?></button>
        <?php endif; ?>
        <button class="ac-btn ac-btn-sm alegra-sync-single" data-type="customer" data-id="<?php echo esc_attr($customer->ID); ?>"><span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span> <?php echo $synced ? esc_html__('Actualizar en Alegra', 'alegra-connector') : esc_html__('Enviar a Alegra', 'alegra-connector'); ?></button>
        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $customer->ID)); ?>" class="ac-btn ac-btn-sm" target="_blank" style="margin-left:10px;"><?php esc_html_e('Editar en WordPress', 'alegra-connector'); ?></a>
    </div>

    <div class="ac-detail-grid">
        <div class="ac-detail-panel">
            <h2><span class="dashicons dashicons-admin-users" style="color:#7f54b3;"></span> WooCommerce</h2>
            <table class="ac-detail-table">
                <tr><th>ID</th><td><?php echo esc_html($customer->ID); ?></td></tr>
                    <tr><th><?php esc_html_e('Nombre', 'alegra-connector'); ?></th><td><?php echo esc_html($customer->first_name . ' ' . $customer->last_name ?: $customer->display_name); ?></td></tr>
                    <tr><th>Email</th><td><?php echo esc_html($customer->user_email); ?></td></tr>
                <tr><th><?php esc_html_e('Telefono', 'alegra-connector'); ?></th><td><?php echo esc_html(get_user_meta($customer->ID, 'billing_phone', true) ?: '--'); ?></td></tr>
                <tr><th><?php esc_html_e('Dirección', 'alegra-connector'); ?></th><td><?php echo esc_html(get_user_meta($customer->ID, 'billing_address_1', true) ?: '--'); ?></td></tr>
                <tr><th><?php esc_html_e('Ciudad', 'alegra-connector'); ?></th><td><?php echo esc_html(get_user_meta($customer->ID, 'billing_city', true) ?: '--'); ?></td></tr>
                <tr><th>NIT</th><td><?php echo esc_html(get_user_meta($customer->ID, 'billing_nit', true) ?: '--'); ?></td></tr>
            </table>
        </div>
        <div class="ac-detail-panel">
            <h2><span class="dashicons dashicons-cloud" style="color:var(--ac-primary);"></span> Alegra</h2>
            <?php if ($alegra_error): ?>
                <div class="ac-notice error"><p><?php echo esc_html($alegra_error); ?></p></div>
            <?php elseif ($alegra_data): ?>
                <table class="ac-detail-table">
                    <tr><th>ID Alegra</th><td><span class="ac-code">#<?php echo esc_html($alegra_data['id']); ?></span></td></tr>
                    <tr><th><?php esc_html_e('Nombre', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['name'] ?? ''); ?></td></tr>
                    <tr><th>Email</th><td><?php echo esc_html($alegra_data['email'] ?? ''); ?></td></tr>
                    <tr><th><?php esc_html_e('Telefono', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['phonePrimary'] ?? $alegra_data['mobile'] ?? ''); ?></td></tr>
                    <tr><th><?php esc_html_e('Identificacion', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['identification'] ?? ''); ?></td></tr>
                    <tr><th><?php esc_html_e('Tipo', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['type'] ?? 'client'); ?></td></tr>
                    <?php if (!empty($alegra_data['address']['address'])): ?>
                    <tr><th><?php esc_html_e('Dirección', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['address']['address']); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($alegra_data['address']['city'])): ?>
                    <tr><th><?php esc_html_e('Ciudad', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['address']['city']); ?></td></tr>
                    <?php endif; ?>
                </table>
            <?php elseif ($synced): ?>
                <div class="ac-notice warning"><p><?php esc_html_e('No se pudo cargar Alegra.', 'alegra-connector'); ?></p></div>
            <?php else: ?>
                <div class="ac-empty-state">
                    <p><?php esc_html_e('Este cliente no esta vinculado a Alegra. Usa el boton Enviar para sincronizarlo.', 'alegra-connector'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php include __DIR__ . '/footer.php'; ?></div>
