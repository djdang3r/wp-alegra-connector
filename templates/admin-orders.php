<?php if(!defined('ABSPATH'))exit;
$page_title=__('Pedidos','alegra-connector');
$synced_orders=$synced_orders??0;$total_orders=$total_orders??0;
$page_subtitle=sprintf(__('%d pedidos (%d facturados en Alegra)','alegra-connector'),$total_orders,$synced_orders);
include __DIR__.'/header.php';
$filter=isset($_GET['filter'])?sanitize_text_field($_GET['filter']):'all';
$search=isset($_GET['search'])?sanitize_text_field($_GET['search']):'';
$order_status=isset($_GET['order_status'])?sanitize_text_field($_GET['order_status']):'';
?>
<div class="alegra-connector-wrap">

<div class="ac-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px;">
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-text-muted);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-surface-alt);color:var(--ac-text-secondary);"><span class="dashicons dashicons-cart"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Total Pedidos','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html($total_orders);?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-success);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-success-bg);color:var(--ac-success);"><span class="dashicons dashicons-yes-alt"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Facturados en Alegra','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;color:var(--ac-success);"><?php echo esc_html($synced_orders);?></span><span class="ac-kpi-sub"><?php echo $total_orders>0?esc_html(round(($synced_orders/$total_orders)*100,1).'%'):'0%';?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-warning);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-warning-bg);color:var(--ac-warning);"><span class="dashicons dashicons-warning"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Pendientes Facturar','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;color:var(--ac-warning);"><?php echo esc_html($total_orders-$synced_orders);?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-primary);padding:14px 18px;"><div class="ac-kpi-icon" style="width:38px;height:38px;background:var(--ac-primary-light);color:var(--ac-primary);"><span class="dashicons dashicons-money-alt"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Pagos Registrados','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:18px;color:var(--ac-primary);"><?php echo esc_html($payment_count??0);?></span></div></div>
</div>

<div class="ac-card" style="margin-bottom:14px;padding:12px 20px;">
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <button type="button" class="ac-btn ac-btn-primary ac-btn-sm alegra-sync-pending-orders"><span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Facturar pendientes','alegra-connector');?></button>
    <button type="button" class="ac-btn ac-btn-sm alegra-bulk-sync" data-type="order"><span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Facturar seleccionados','alegra-connector');?></button>
    <div style="flex:1;"></div>
    <span style="font-size:12px;font-weight:600;color:var(--ac-text-secondary);"><?php esc_html_e('Filtrar:','alegra-connector');?></span>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&filter=all'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='all'?'ac-btn-primary':'';?>"><?php esc_html_e('Todos','alegra-connector');?></a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&filter=synced'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='synced'?'ac-btn-primary':'';?>"><?php esc_html_e('Facturados','alegra-connector');?></a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&filter=pending'));?>" class="ac-btn ac-btn-xs <?php echo $filter==='pending'?'ac-btn-primary':'';?>"><?php esc_html_e('Pendientes','alegra-connector');?></a>
    <select id="order-status-filter" onchange="location.href='<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&filter='.$filter));?>&order_status='+this.value" style="padding:4px 8px;border-radius:6px;border:1px solid var(--ac-border);font-size:12px;">
        <option value=""><?php esc_html_e('Todos los estados','alegra-connector');?></option>
        <?php foreach(wc_get_order_statuses() as $sk=>$sv):$sk=str_replace('wc-','',$sk);?>
        <option value="<?php echo esc_attr($sk);?>" <?php selected($order_status,$sk);?>><?php echo esc_html($sv);?></option>
        <?php endforeach;?>
    </select>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php'));?>" style="display:inline-flex;gap:4px;align-items:center;">
        <input type="hidden" name="page" value="alegra-connector-orders"><input type="text" name="search" value="<?php echo esc_attr($search);?>" placeholder="<?php esc_attr_e('Buscar # pedido o cliente...','alegra-connector');?>" style="padding:4px 10px;border-radius:6px;border:1px solid var(--ac-border);font-size:12px;width:200px;"><button type="submit" class="ac-btn ac-btn-xs"><?php esc_html_e('Buscar','alegra-connector');?></button>
    </form>
</div>
</div>

<div class="ac-table-wrap"><table class="widefat fixed striped">
<thead><tr><th style="width:36px;"><input type="checkbox" class="alegra-select-all" data-type="order"></th><th style="width:80px;"><?php esc_html_e('Pedido','alegra-connector');?></th><th><?php esc_html_e('Fecha','alegra-connector');?></th><th><?php esc_html_e('Cliente','alegra-connector');?></th><th><?php esc_html_e('Total','alegra-connector');?></th><th><?php esc_html_e('Estado WC','alegra-connector');?></th><th><?php esc_html_e('Factura Alegra','alegra-connector');?></th><th><?php esc_html_e('Pago','alegra-connector');?></th><th><?php esc_html_e('Sync','alegra-connector');?></th><th></th></tr></thead>
<tbody><?php if(empty($orders)):?><tr><td colspan="10" style="text-align:center;padding:30px;color:var(--ac-text-muted);"><?php esc_html_e('No se encontraron pedidos.','alegra-connector');?></td></tr>
<?php else:foreach($orders as $o):
    $ii=(int)get_post_meta($o->get_id(),'_alegra_invoice_id',true);$in=get_post_meta($o->get_id(),'_alegra_invoice_number',true);$s=$ii>0;
    $pi=(int)get_post_meta($o->get_id(),'_alegra_payment_id',true);$ps=$pi>0;
    $ost=$o->get_status();$osl=wc_get_order_status_name($ost);
    if($filter==='synced'&&!$s)continue;if($filter==='pending'&&$s)continue;
    if($order_status&&$ost!==$order_status)continue;
    if($search){$sn='#'.$o->get_id().' '.$o->get_billing_first_name().' '.$o->get_billing_last_name();if(stripos($sn,$search)===false)continue;}
    $pbadge=$ps?'<span class="ac-badge success" style="font-size:10px;">'.esc_html__('Registrado','alegra-connector').'</span>':($s?'<span class="ac-badge neutral" style="font-size:10px;">'.esc_html__('Sin pago','alegra-connector').'</span>':'<span style="color:var(--ac-text-muted);">--</span>');
?>
<tr><td><input type="checkbox" class="alegra-bulk-check" value="<?php echo esc_attr($o->get_id());?>"></td><td><strong><a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&order_id='.$o->get_id()));?>">#<?php echo esc_html($o->get_id());?></a></strong></td><td><?php echo esc_html($o->get_date_created()?$o->get_date_created()->date('Y-m-d'):'');?></td><td><?php echo esc_html($o->get_billing_first_name().' '.$o->get_billing_last_name());?></td><td><?php echo wp_kses_post(wc_price($o->get_total()));?></td><td><mark class="order-status status-<?php echo esc_attr($ost);?>"><span><?php echo esc_html($osl);?></span></mark></td><td><?php echo $s?'<span class="ac-code">'.esc_html($in?:'#'.$ii).'</span>':'<span style="color:var(--ac-text-muted);">--</span>';?></td><td><?php echo $pbadge;?></td><td><?php echo $s?'<span class="ac-badge success">'.esc_html__('Facturado','alegra-connector').'</span>':'<span class="ac-badge warning">'.esc_html__('Pendiente','alegra-connector').'</span>';?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&order_id='.$o->get_id()));?>" class="ac-btn ac-btn-xs"><?php esc_html_e('Ver','alegra-connector');?></a><?php if(!$s):?> <button class="ac-btn ac-btn-xs alegra-sync-single" data-type="order" data-id="<?php echo esc_attr($o->get_id());?>"><?php esc_html_e('Facturar','alegra-connector');?></button><?php else:?> <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=alegra_get_invoice_pdf&order_id='.$o->get_id().'&_ajax_nonce='.wp_create_nonce('alegra_connector_nonce')));?>" class="ac-btn ac-btn-xs" target="_blank" title="<?php esc_attr_e('Descargar PDF','alegra-connector');?>">PDF</a><?php endif;?></td></tr>
<?php endforeach;endif;?></tbody></table></div>
<?php if($total_pages>1): include __DIR__.'/pagination.php'; $base=admin_url('admin.php?page=alegra-connector-orders&filter='.urlencode($filter).'&search='.urlencode($search).'&order_status='.urlencode($order_status)); echo alegra_pagination($page, $total_pages, $base); endif;?>

<?php include __DIR__.'/footer.php';?></div>