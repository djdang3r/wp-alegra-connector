<?php if(!defined('ABSPATH'))exit;
$page_title=__('Clientes','alegra-connector');
$synced_customers=$synced_customers??0;$total_users=$total_users??0;
$page_subtitle=sprintf(__('%d clientes (%d sincronizados con Alegra)','alegra-connector'),$total_users,$synced_customers);
$export_url=wp_nonce_url(admin_url('admin-ajax.php?action=alegra_export_csv&export_type=customers'),'alegra_connector_nonce','_ajax_nonce');
include __DIR__.'/header.php';
$filter=isset($_GET['filter'])?sanitize_text_field($_GET['filter']):'all';
$search=isset($_GET['search'])?sanitize_text_field($_GET['search']):'';
?>
<div class="alegra-connector-wrap">

<div class="ac-kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:14px;">
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-text-muted);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-surface-alt);color:var(--ac-text-secondary);"><span class="dashicons dashicons-groups"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Total Clientes','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html($total_users);?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-success);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-success-bg);color:var(--ac-success);"><span class="dashicons dashicons-yes-alt"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Sincronizados','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;color:var(--ac-success);"><?php echo esc_html($synced_customers);?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-warning);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-warning-bg);color:var(--ac-warning);"><span class="dashicons dashicons-warning"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Pendientes','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;color:var(--ac-warning);"><?php echo esc_html($total_users-$synced_customers);?></span></div></div>
</div>

<div class="ac-card" style="margin-bottom:14px;padding:12px 20px;">
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <button type="button" class="ac-btn ac-btn-primary ac-btn-sm alegra-quick-sync" data-type="customers"><span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Traer todo desde Alegra','alegra-connector');?></button>
    <button type="button" class="ac-btn ac-btn-sm alegra-bulk-import" data-type="customer"><span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Traer seleccionados','alegra-connector');?></button>
    <button type="button" class="ac-btn ac-btn-sm alegra-bulk-sync" data-type="customer"><span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Enviar seleccionados','alegra-connector');?></button>
    <a href="<?php echo esc_url($export_url);?>" class="ac-btn ac-btn-sm"><span class="dashicons dashicons-media-spreadsheet" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Exportar CSV','alegra-connector');?></a>
    <div style="flex:1;"></div>
    <button type="button" class="ac-btn ac-btn-sm ac-btn-danger alegra-cleanup-placeholders" title="<?php esc_attr_e('Elimina usuarios creados con email placeholder (@placeholder.local) de versiones anteriores. Estos no tienen email real y no pueden usarse como clientes de WooCommerce.','alegra-connector'); ?>"><span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Limpiar emails placeholder','alegra-connector');?></button>
    <span style="font-size:12px;font-weight:600;color:var(--ac-text-secondary);"><?php esc_html_e('Filtrar:','alegra-connector');?></span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers&filter=all'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='all'?'ac-btn-primary':'';?>"><?php esc_html_e('Todos','alegra-connector');?></a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers&filter=synced'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='synced'?'ac-btn-primary':'';?>"><?php esc_html_e('Sincronizados','alegra-connector');?></a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers&filter=pending'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='pending'?'ac-btn-primary':'';?>"><?php esc_html_e('Pendientes','alegra-connector');?></a>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php'));?>" style="display:inline-flex;gap:4px;align-items:center;">
        <input type="hidden" name="page" value="alegra-connector-customers"><input type="text" name="search" value="<?php echo esc_attr($search);?>" placeholder="<?php esc_attr_e('Buscar por nombre o email...','alegra-connector');?>" style="padding:4px 10px;border-radius:6px;border:1px solid var(--ac-border);font-size:12px;width:200px;"><button type="submit" class="ac-btn ac-btn-xs"><?php esc_html_e('Buscar','alegra-connector');?></button>
    </form>
</div>
</div>

<div class="ac-table-wrap"><table class="widefat fixed striped">
<thead><tr><th style="width:36px;"><input type="checkbox" class="alegra-select-all" data-type="customer"></th><th style="width:60px;">ID</th><th><?php esc_html_e('Cliente','alegra-connector');?></th><th>Email</th><th><?php esc_html_e('Telefono','alegra-connector');?></th><th><?php esc_html_e('Alegra ID','alegra-connector');?></th><th><?php esc_html_e('Estado Sync','alegra-connector');?></th><th></th></tr></thead>
<tbody><?php if(empty($customers)):?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--ac-text-muted);"><?php esc_html_e('No se encontraron clientes.','alegra-connector');?></td></tr>
<?php else:foreach($customers as $c):
    $ai=(int)get_user_meta($c->ID,'alegra_contact_id',true);$s=$ai>0;
    if($filter==='synced'&&!$s)continue;if($filter==='pending'&&$s)continue;
    if($search&&stripos($c->display_name.' '.$c->user_email,$search)===false)continue;
    $ph=get_user_meta($c->ID,'billing_phone',true);
    $nit=get_user_meta($c->ID,'billing_nit',true);
?>
<tr><td><input type="checkbox" class="alegra-bulk-check" value="<?php echo esc_attr($c->ID);?>"></td><td><?php echo esc_html($c->ID);?></td><td><strong><a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers&customer_id='.$c->ID));?>"><?php echo esc_html($c->display_name?:$c->user_email);?></a></strong><?php echo $nit?'<br><small style="color:var(--ac-text-muted);">NIT: '.esc_html($nit).'</small>':'';?></td><td><?php echo esc_html($c->user_email);?></td><td><?php echo esc_html($ph?:'--');?></td><td><?php echo $s?'<span class="ac-code">#'.esc_html($ai).'</span>':'<span style="color:var(--ac-text-muted);">--</span>';?></td><td><?php echo $s?'<span class="ac-badge success">'.esc_html__('Sincronizado','alegra-connector').'</span>':'<span class="ac-badge warning">'.esc_html__('Pendiente','alegra-connector').'</span>';?></td><td style="white-space:nowrap;"><?php if($s):?><button class="ac-btn ac-btn-xs alegra-import-single" data-type="customer" data-id="<?php echo esc_attr($c->ID);?>"><span class="dashicons dashicons-download" style="font-size:12px;width:12px;height:12px;"></span> Traer</button><?php endif;?> <button class="ac-btn ac-btn-xs alegra-sync-single" data-type="customer" data-id="<?php echo esc_attr($c->ID);?>"><span class="dashicons dashicons-upload" style="font-size:12px;width:12px;height:12px;"></span> <?php echo $s?esc_html__('Actualizar','alegra-connector'):esc_html__('Enviar','alegra-connector');?></button> <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-customers&customer_id='.$c->ID));?>" class="ac-btn ac-btn-xs"><?php esc_html_e('Ver','alegra-connector');?></a></td></tr>
<?php endforeach;endif;?></tbody></table></div>
<?php if($total_pages>1): include __DIR__.'/pagination.php'; $base=admin_url('admin.php?page=alegra-connector-customers&filter='.urlencode($filter).'&search='.urlencode($search)); echo alegra_pagination($page, $total_pages, $base); endif;?>

<?php include __DIR__.'/footer.php';?></div>