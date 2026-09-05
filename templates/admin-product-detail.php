<?php
if (!defined('ABSPATH')) exit;
$alegra_id = (int) get_post_meta($product->get_id(), '_alegra_item_id', true);
$synced = $alegra_id > 0;
$alegra_error = $alegra_error ?? null;
$alegra_data = $alegra_data ?? null;
$page_title = sprintf(__('Producto: %s', 'alegra-connector'), $product->get_name());
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
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-products')); ?>" class="ac-btn ac-btn-sm">&#8592; <?php esc_html_e('Volver', 'alegra-connector'); ?></a>
        <?php if ($synced): ?>
        <button class="ac-btn ac-btn-sm alegra-import-single" data-type="product" data-id="<?php echo esc_attr($product->get_id()); ?>"><span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Traer desde Alegra', 'alegra-connector'); ?></button>
        <?php endif; ?>
        <button class="ac-btn ac-btn-sm alegra-sync-single" data-type="product" data-id="<?php echo esc_attr($product->get_id()); ?>"><span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span> <?php echo $synced ? esc_html__('Actualizar en Alegra', 'alegra-connector') : esc_html__('Enviar a Alegra', 'alegra-connector'); ?></button>
        <a href="<?php echo esc_url(admin_url('post.php?post=' . $product->get_id() . '&action=edit')); ?>" class="ac-btn ac-btn-sm" target="_blank" style="margin-left:10px;"><?php esc_html_e('Editar en WooCommerce', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url($product->get_permalink()); ?>" class="ac-btn ac-btn-sm" target="_blank"><?php esc_html_e('Ver en tienda', 'alegra-connector'); ?></a>
    </div>

    <div class="ac-detail-grid">
        <div class="ac-detail-panel">
            <h2><span class="dashicons dashicons-cart" style="color:#7f54b3;"></span> WooCommerce</h2>
            <div style="text-align:center;margin-bottom:14px;">
                <?php $thumb_id = $product->get_image_id(); ?>
                <?php if ($thumb_id): ?>
                    <?php echo wp_get_attachment_image($thumb_id, 'medium', false, ['style' => 'max-width:200px;max-height:200px;border-radius:8px;border:1px solid var(--ac-border);']); ?>
                <?php else: ?>
                    <div style="width:200px;height:200px;border-radius:8px;border:1px dashed var(--ac-border);display:inline-flex;align-items:center;justify-content:center;color:var(--ac-text-muted);font-size:12px;"><?php esc_html_e('Sin imagen', 'alegra-connector'); ?></div>
                <?php endif; ?>
            </div>
            <table class="ac-detail-table">
                <tr><th><?php esc_html_e('Nombre', 'alegra-connector'); ?></th><td><?php echo esc_html($product->get_name()); ?></td></tr>
                <tr><th>SKU</th><td><?php echo esc_html($product->get_sku()); ?></td></tr>
                <tr><th><?php esc_html_e('Tipo', 'alegra-connector'); ?></th><td><?php echo esc_html($product->get_type()); ?></td></tr>
                <tr><th><?php esc_html_e('Precio regular', 'alegra-connector'); ?></th><td><?php echo wp_kses_post(wc_price($product->get_regular_price())); ?></td></tr>
                <tr><th><?php esc_html_e('Stock', 'alegra-connector'); ?></th><td><?php echo esc_html($product->get_stock_quantity()); ?></td></tr>
                <tr><th><?php esc_html_e('Categorías', 'alegra-connector'); ?></th><td><?php echo wp_kses_post(wc_get_product_category_list($product->get_id())); ?></td></tr>
                <tr><th><?php esc_html_e('Estado', 'alegra-connector'); ?></th><td><?php echo esc_html($product->get_status()); ?></td></tr>
            </table>
        </div>
        <div class="ac-detail-panel">
            <h2><span class="dashicons dashicons-cloud" style="color:var(--ac-primary);"></span> Alegra</h2>
            <?php if ($alegra_error): ?>
                <div class="ac-notice error"><p><?php echo esc_html($alegra_error); ?></p></div>
            <?php elseif ($alegra_data): ?>
                <div style="text-align:center;margin-bottom:14px;">
                    <?php $alegra_img = !empty($alegra_data['images']) && !empty($alegra_data['images'][0]['url']) ? $alegra_data['images'][0]['url'] : ''; ?>
                    <?php if ($alegra_img): ?>
                        <img src="<?php echo esc_url($alegra_img); ?>" alt="<?php esc_attr_e('Imagen Alegra', 'alegra-connector'); ?>" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid var(--ac-border);">
                    <?php else: ?>
                        <div style="width:200px;height:200px;border-radius:8px;border:1px dashed var(--ac-border);display:inline-flex;align-items:center;justify-content:center;color:var(--ac-text-muted);font-size:12px;"><?php esc_html_e('Sin imagen', 'alegra-connector'); ?></div>
                    <?php endif; ?>
                </div>
                <table class="ac-detail-table">
                    <tr><th>ID Alegra</th><td><span class="ac-code">#<?php echo esc_html($alegra_data['id']); ?></span></td></tr>
                    <tr><th><?php esc_html_e('Nombre', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['name']); ?></td></tr>
                    <tr><th><?php esc_html_e('Referencia', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['reference'] ?? '--'); ?></td></tr>
                    <tr><th><?php esc_html_e('Precio', 'alegra-connector'); ?></th><td><?php if(isset($alegra_data['price'])&&is_array($alegra_data['price'])){foreach($alegra_data['price'] as $p){echo '$'.esc_html($p['price']).' ('.esc_html($p['name']??'General').')<br>';}} ?></td></tr>
                    <tr><th><?php esc_html_e('Inventario', 'alegra-connector'); ?></th><td><?php if(isset($alegra_data['inventory'])){echo esc_html(($alegra_data['inventory']['availableQuantity']??0).' '.($alegra_data['inventory']['unit']??'units'));} ?></td></tr>
                    <tr><th><?php esc_html_e('Estado', 'alegra-connector'); ?></th><td><?php echo esc_html($alegra_data['status'] ?? 'active'); ?></td></tr>
                </table>
            <?php elseif ($synced): ?>
                <div class="ac-notice warning"><p><?php esc_html_e('No se pudo cargar Alegra.', 'alegra-connector'); ?></p></div>
            <?php else: ?>
                <div class="ac-empty-state">
                    <p><?php esc_html_e('Este producto no esta vinculado a Alegra. Usa el boton Enviar para sincronizarlo.', 'alegra-connector'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include __DIR__ . '/footer.php'; ?></div>