<?php
if (!defined('ABSPATH')) exit;

$page_title = __('Productos', 'alegra-connector');

$synced_count      = $synced_count ?? 0;
$variable_count    = $variable_count ?? 0;
$variation_count   = $variation_count ?? 0;
$synced_variations = $synced_variations ?? 0;
$total             = $total ?? 0;
$connected         = $connected ?? false;

$page_subtitle = sprintf(__('%d productos (%d sincronizados con Alegra)', 'alegra-connector'), $total, $synced_count);

$export_url = wp_nonce_url(
    admin_url('admin-ajax.php?action=alegra_export_csv&export_type=products'),
    'alegra_connector_nonce',
    '_ajax_nonce'
);

include __DIR__ . '/header.php';

$filter          = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
$search          = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : 'all';
?>
<div class="alegra-connector-wrap">

<!-- KPI Bar -->
<div class="ac-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px;">
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-text-muted);padding:14px 18px;">
        <div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-surface-alt);color:var(--ac-text-secondary);">
            <span class="dashicons dashicons-products"></span>
        </div>
        <div class="ac-kpi-content">
            <span class="ac-kpi-label"><?php esc_html_e('Total Productos', 'alegra-connector'); ?></span>
            <span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html($total); ?></span>
            <span class="ac-kpi-sub">
                <?php
                if ($post_type_filter === 'variations') {
                    esc_html_e('Mostrando variaciones', 'alegra-connector');
                } else {
                    esc_html_e('Mostrando productos principales', 'alegra-connector');
                }
                ?>
            </span>
        </div>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-success);padding:14px 18px;">
        <div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-success-bg);color:var(--ac-success);">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <div class="ac-kpi-content">
            <span class="ac-kpi-label"><?php esc_html_e('Sincronizados', 'alegra-connector'); ?></span>
            <span class="ac-kpi-value" style="font-size:18px;color:var(--ac-success);"><?php echo esc_html($synced_count); ?></span>
            <span class="ac-kpi-sub">
                <?php
                if ($total > 0) {
                    echo esc_html(round(($synced_count / $total) * 100, 1) . '%');
                } else {
                    echo '0%';
                }
                ?>
            </span>
        </div>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-warning);padding:14px 18px;">
        <div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-warning-bg);color:var(--ac-warning);">
            <span class="dashicons dashicons-warning"></span>
        </div>
        <div class="ac-kpi-content">
            <span class="ac-kpi-label"><?php esc_html_e('Pendientes', 'alegra-connector'); ?></span>
            <span class="ac-kpi-value" style="font-size:18px;color:var(--ac-warning);"><?php echo esc_html(max(0, $total - $synced_count)); ?></span>
        </div>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-primary);padding:14px 18px;">
        <div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-primary-light);color:var(--ac-primary);">
            <span class="dashicons dashicons-editor-ul"></span>
        </div>
        <div class="ac-kpi-content">
            <span class="ac-kpi-label"><?php esc_html_e('Variables', 'alegra-connector'); ?></span>
            <span class="ac-kpi-value" style="font-size:18px;color:var(--ac-primary);"><?php echo esc_html((int)$variable_count); ?></span>
            <span class="ac-kpi-sub"><?php echo esc_html(sprintf(__('%d variaciones (%d sinc.)', 'alegra-connector'), (int)$variable_count, (int)$synced_variations)); ?></span>
        </div>
    </div>
</div>

<!-- Actions + Filters -->
<div class="ac-card" style="margin-bottom:14px;padding:12px 20px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span class="ac-selected-count" style="font-size:12px;color:var(--ac-text-muted);"></span>
        <button type="button" class="ac-btn ac-btn-primary ac-btn-sm alegra-quick-sync" data-type="products" data-requires-filter="1" <?php echo !$connected ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Traer desde Alegra', 'alegra-connector'); ?>
        </button>
        <?php if ((string) get_option('alegra_connector_inventory_source', 'alegra') !== 'woocommerce') : ?>
        <button type="button" class="ac-btn ac-btn-sm alegra-sync-inventory" title="<?php esc_attr_e('Trae las existencias desde Alegra y actualiza el stock de WooCommerce.', 'alegra-connector'); ?>">
            <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Sincronizar inventario', 'alegra-connector'); ?>
        </button>
        <?php endif; ?>
        <button type="button" class="ac-btn ac-btn-sm alegra-bulk-import" data-type="product">
            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Traer seleccionados', 'alegra-connector'); ?>
        </button>
        <button type="button" class="ac-btn ac-btn-sm alegra-bulk-sync" data-type="product">
            <span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Enviar seleccionados', 'alegra-connector'); ?>
        </button>
        <a href="<?php echo esc_url($export_url); ?>" class="ac-btn ac-btn-sm">
            <span class="dashicons dashicons-media-spreadsheet" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Exportar CSV', 'alegra-connector'); ?>
        </a>
        <button type="button" class="ac-btn ac-btn-sm alegra-cleanup-duplicate-images" style="color:var(--ac-warning);border-color:var(--ac-warning);" title="<?php esc_attr_e('Elimina imagenes duplicadas existentes en la galeria de productos basandose en la URL normalizada. Solo ejecuta una vez para limpiar duplicados historicos.', 'alegra-connector'); ?>">
            <span class="dashicons dashicons-image-rotate" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Limpiar imagenes duplicadas', 'alegra-connector'); ?>
        </button>
        <div style="flex:1;"></div>
        <span style="font-size:12px;font-weight:600;color:var(--ac-text-secondary);"><?php esc_html_e('Tipo:', 'alegra-connector'); ?></span>
        <a href="<?php echo esc_url(add_query_arg('post_type_filter', 'all', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $post_type_filter === 'all' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Todos', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url(add_query_arg('post_type_filter', 'simple', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $post_type_filter === 'simple' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Simples', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url(add_query_arg('post_type_filter', 'variable', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $post_type_filter === 'variable' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Variables', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url(add_query_arg('post_type_filter', 'variations', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $post_type_filter === 'variations' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Variaciones', 'alegra-connector'); ?></a>
        <span style="font-size:12px;font-weight:600;color:var(--ac-text-secondary);"><?php esc_html_e('Filtrar:', 'alegra-connector'); ?></span>
        <a href="<?php echo esc_url(add_query_arg('filter', 'all', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $filter === 'all' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Todos', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url(add_query_arg('filter', 'synced', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $filter === 'synced' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Sincronizados', 'alegra-connector'); ?></a>
        <a href="<?php echo esc_url(add_query_arg('filter', 'pending', remove_query_arg('paged'))); ?>" class="ac-btn ac-btn-xs <?php echo $filter === 'pending' ? 'ac-btn-primary' : ''; ?>"><?php esc_html_e('Pendientes', 'alegra-connector'); ?></a>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline-flex;gap:4px;align-items:center;">
            <input type="hidden" name="page" value="alegra-connector-products">
            <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
            <input type="hidden" name="post_type_filter" value="<?php echo esc_attr($post_type_filter); ?>">
            <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Buscar producto...', 'alegra-connector'); ?>" style="padding:4px 10px;border-radius:6px;border:1px solid var(--ac-border);font-size:12px;width:180px;">
            <button type="submit" class="ac-btn ac-btn-xs"><?php esc_html_e('Buscar', 'alegra-connector'); ?></button>
        </form>
    </div>
</div>

<!-- Product Table -->
<div class="ac-table-wrap ac-table-products">
<table class="widefat striped">
<colgroup>
    <col class="ac-col-checkbox">
    <col class="ac-col-image">
    <col class="ac-col-id">
    <col class="ac-col-name">
    <col class="ac-col-sku">
    <col class="ac-col-price">
    <col class="ac-col-type">
    <col class="ac-col-parent">
    <col class="ac-col-alegra">
    <col class="ac-col-status">
    <col class="ac-col-actions">
</colgroup>
<thead>
    <tr>
        <th class="ac-col-checkbox"><input type="checkbox" class="alegra-select-all" data-type="product"></th>
        <th class="ac-col-image"><?php esc_html_e('Imagen', 'alegra-connector'); ?></th>
        <th class="ac-col-id">ID</th>
        <th class="ac-col-name"><?php esc_html_e('Producto', 'alegra-connector'); ?></th>
        <th class="ac-col-sku">SKU</th>
        <th class="ac-col-price"><?php esc_html_e('Precio', 'alegra-connector'); ?></th>
        <th class="ac-col-type"><?php esc_html_e('Tipo', 'alegra-connector'); ?></th>
        <th class="ac-col-parent"><?php esc_html_e('Padre', 'alegra-connector'); ?></th>
        <th class="ac-col-alegra"><?php esc_html_e('Alegra ID', 'alegra-connector'); ?></th>
        <th class="ac-col-status"><?php esc_html_e('Estado Sync', 'alegra-connector'); ?></th>
        <th class="ac-col-actions"><?php esc_html_e('Acciónes', 'alegra-connector'); ?></th>
    </tr>
</thead>
<tbody>
<?php if (empty($products)): ?>
    <tr>
        <td colspan="11" style="text-align:center;padding:30px;color:var(--ac-text-muted);">
            <?php esc_html_e('No se encontraron productos.', 'alegra-connector'); ?>
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($products as $p):
        $ai = (string)($p['alegra_id'] ?? '');
        $s  = $ai !== '' && $ai !== null;
        $type = $p['type'];
        $pi  = (int)($p['parent_id'] ?? 0);
        $is_variation = ($type === 'variation' || $pi > 0);

        if ($is_variation) {
            $ti = '<span class="ac-badge neutral">' . esc_html__('variacion', 'alegra-connector') . '</span>';
        } elseif ($type === 'variable') {
            $ti = '<span class="ac-badge neutral">' . esc_html__('variable', 'alegra-connector') . '</span>';
        } else {
            $ti = '<span class="ac-badge info">' . esc_html__('simple', 'alegra-connector') . '</span>';
        }

        $thumb_url = isset($p['thumbnail_url']) ? $p['thumbnail_url'] : '';
        $thumb_alt = isset($p['thumbnail_alt']) && !empty($p['thumbnail_alt']) ? $p['thumbnail_alt'] : $p['name'];

        $detail_url = esc_url(admin_url('admin.php?page=alegra-connector-products&product_id=' . (int)$p['id']));

        if ($s) {
            $alegra_cell = '<span class="ac-code">#' . esc_html($ai) . '</span>';
            $status_cell = '<span class="ac-badge success">' . esc_html__('Sincronizado', 'alegra-connector') . '</span>';
            $sync_button_label = esc_html__('Actualizar', 'alegra-connector');
            $import_button = '<button class="ac-btn ac-btn-xs alegra-import-single" data-type="product" data-id="' . esc_attr($p['id']) . '"><span class="dashicons dashicons-download" style="font-size:12px;width:12px;height:12px;"></span> ' . esc_html__('Traer', 'alegra-connector') . '</button>';
        } else {
            $alegra_cell = '<span style="color:var(--ac-text-muted);">--</span>';
            $status_cell = '<span class="ac-badge warning">' . esc_html__('Pendiente', 'alegra-connector') . '</span>';
            $sync_button_label = esc_html__('Enviar', 'alegra-connector');
            $import_button = '';
        }
    ?>
    <tr>
        <td class="ac-td-narrow ac-col-checkbox">
            <input type="checkbox" class="alegra-bulk-check" value="<?php echo esc_attr($p['id']); ?>">
        </td>
        <td class="ac-col-image ac-td-image">
            <?php if (!empty($thumb_url)): ?>
                <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($thumb_alt); ?>" class="ac-product-thumb" loading="lazy">
            <?php else: ?>
                <span class="ac-product-thumb-placeholder" title="<?php esc_attr_e('Sin imagen', 'alegra-connector'); ?>">
                    <span class="dashicons dashicons-format-image"></span>
                </span>
            <?php endif; ?>
        </td>
        <td class="ac-col-id"><?php echo esc_html((int)$p['id']); ?></td>
        <td class="ac-col-name">
            <strong><a href="<?php echo $detail_url; ?>"><?php echo esc_html($p['name']); ?></a></strong>
            <?php if ($pi > 0): ?>
                <small style="color:var(--ac-text-muted);">
                    (<?php esc_html_e('de', 'alegra-connector'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-products&product_id=' . $pi)); ?>">#<?php echo (int)$pi; ?></a>)
                </small>
            <?php endif; ?>
        </td>
        <td class="ac-col-sku"><?php echo !empty($p['sku']) ? esc_html($p['sku']) : '--'; ?></td>
        <td class="ac-col-price"><?php echo wp_kses_post(wc_price((float)$p['price'])); ?></td>
        <td class="ac-col-type"><?php echo $ti; ?></td>
        <td class="ac-col-parent">
            <?php if ($pi > 0): ?>
                <small style="color:var(--ac-text-muted);">#<?php echo (int)$pi; ?></small>
            <?php else: ?>
                -
            <?php endif; ?>
        </td>
        <td class="ac-col-alegra"><?php echo $alegra_cell; ?></td>
        <td class="ac-col-status"><?php echo $status_cell; ?></td>
        <td class="ac-td-actions ac-col-actions">
            <a href="<?php echo $detail_url; ?>" class="ac-btn ac-btn-xs"><?php esc_html_e('Ver', 'alegra-connector'); ?></a>
            <?php echo $import_button; ?>
            <button class="ac-btn ac-btn-xs alegra-sync-single" data-type="product" data-id="<?php echo esc_attr($p['id']); ?>">
                <span class="dashicons dashicons-upload" style="font-size:12px;width:12px;height:12px;"></span>
                <?php echo $sync_button_label; ?>
            </button>
        </td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php
if ($total_pages > 1) {
    include __DIR__ . '/pagination.php';
    $base = add_query_arg(
        ['filter' => $filter, 'search' => $search, 'post_type_filter' => $post_type_filter],
        remove_query_arg('paged')
    );
    echo alegra_pagination($page, $total_pages, $base, $total, 20);
}
?>

<!-- Product import filter modal -->
<div id="alegra-import-filter-modal" class="ac-modal-overlay" style="display:none;">
    <div class="ac-modal">
        <h2><?php esc_html_e('Traer productos desde Alegra', 'alegra-connector'); ?></h2>
        <p style="margin-bottom:14px;color:var(--ac-text-secondary);font-size:13px;">
            <?php esc_html_e('Elige qué productos traer. Sin filtros se trae todo el catálogo.', 'alegra-connector'); ?>
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 18px;">
            <label style="display:block;font-size:13px;">
                <span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Categoría', 'alegra-connector'); ?></span>
                <select id="ac-filter-category" style="width:100%;">
                    <option value=""><?php esc_html_e('Todas', 'alegra-connector'); ?></option>
                </select>
                <span id="ac-filter-category-note" style="display:block;font-size:11px;color:var(--ac-text-muted);margin-top:3px;"></span>
            </label>

            <label style="display:block;font-size:13px;">
                <span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Tipo', 'alegra-connector'); ?></span>
                <select id="ac-filter-type" style="width:100%;">
                    <option value=""><?php esc_html_e('Todos', 'alegra-connector'); ?></option>
                    <option value="simple"><?php esc_html_e('Sencillos', 'alegra-connector'); ?></option>
                    <option value="kit"><?php esc_html_e('Combos', 'alegra-connector'); ?></option>
                    <option value="variantParent"><?php esc_html_e('Con variantes', 'alegra-connector'); ?></option>
                </select>
            </label>

            <label style="display:block;font-size:13px;">
                <span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Estado', 'alegra-connector'); ?></span>
                <select id="ac-filter-status" style="width:100%;">
                    <option value="default"><?php esc_html_e('Por defecto (según Ajustes)', 'alegra-connector'); ?></option>
                    <option value="active"><?php esc_html_e('Activos', 'alegra-connector'); ?></option>
                    <option value="inactive"><?php esc_html_e('Inactivos', 'alegra-connector'); ?></option>
                </select>
            </label>

            <label style="display:block;font-size:13px;">
                <span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Inventario', 'alegra-connector'); ?></span>
                <select id="ac-filter-inventariable" style="width:100%;">
                    <option value=""><?php esc_html_e('Todos', 'alegra-connector'); ?></option>
                    <option value="1"><?php esc_html_e('Solo con inventario', 'alegra-connector'); ?></option>
                </select>
            </label>

            <label style="display:block;font-size:13px;grid-column:1 / -1;">
                <span style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e('Buscar (nombre o referencia)', 'alegra-connector'); ?></span>
                <input type="text" id="ac-filter-query" style="width:100%;" placeholder="<?php esc_attr_e('Ej: camisa', 'alegra-connector'); ?>">
            </label>
        </div>

        <p style="font-size:11px;color:var(--ac-text-muted);margin-top:12px;line-height:1.5;">
            <?php
            printf(
                /* translators: %s: link to the Synchronization settings tab */
                esc_html__('Al actualizar productos que ya existen, los campos que marques en %s se conservan de WooCommerce. Los productos nuevos se crean con todos los datos de Alegra.', 'alegra-connector'),
                '<a href="' . esc_url(admin_url('admin.php?page=alegra-connector-settings&tab=sync')) . '" target="_blank" rel="noopener">' . esc_html__('Ajustes → Sincronización', 'alegra-connector') . '</a>'
            );
            ?>
        </p>

        <p id="ac-filter-variant-note" style="display:none;margin-top:12px;font-size:12px;color:var(--ac-warning);background:var(--ac-warning-bg);padding:8px 10px;border-radius:6px;">
            <?php esc_html_e('Con variantes: se recorre todo el catálogo; el total mostrado es aproximado.', 'alegra-connector'); ?>
        </p>

        <div class="ac-modal-actions" style="margin-top:16px;display:flex;gap:10px;align-items:center;justify-content:flex-end;">
            <a href="#" id="ac-filter-all" style="margin-right:auto;font-size:12px;"><?php esc_html_e('Traer todo sin filtros', 'alegra-connector'); ?></a>
            <button type="button" class="ac-btn" id="ac-filter-cancel"><?php esc_html_e('Cancelar', 'alegra-connector'); ?></button>
            <button type="button" class="ac-btn ac-btn-primary" id="ac-filter-apply"><?php esc_html_e('Aplicar y traer', 'alegra-connector'); ?></button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</div>
