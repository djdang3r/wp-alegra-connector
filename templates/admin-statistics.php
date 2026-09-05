<?php
if(!defined('ABSPATH'))exit;
$currency=$currency_symbol??get_woocommerce_currency_symbol();
$growth_class=($growth??0)>=0?'green':'red';
$growth_arrow=($growth??0)>=0?'&#9650;':'&#9660;';
$page_title=__('Estadisticas','alegra-connector');
include __DIR__.'/header.php';

// Defaults for empty state
$rev=['completed'=>0,'processing'=>0,'pending'=>0,'cancelled'=>0,'refunded'=>0,'total'=>0];
$revenue=array_merge($rev,$revenue??[]);
$oc=['completed'=>0,'processing'=>0,'pending'=>0,'cancelled'=>0,'refunded'=>0,'total'=>0];
$order_counts=array_merge($oc,$order_counts??[]);
$daily_sales=$daily_sales??[];
$payment_methods=$payment_methods??[];
$top_products=$top_products??[];
$recent_orders=$recent_orders??[];
$abandoned_count=$abandoned_count??0;
$abandoned_value=$abandoned_value??0;
$abandoned_rate=$abandoned_rate??0;
$conversion_rate=$conversion_rate??0;
$avg_order=$avg_order??0;
$new_customers=$new_customers??0;
$sync_stats=$sync_stats??['products_synced'=>0,'products_total'=>0,'orders_synced'=>0,'orders_total'=>0,'customers_synced'=>0,'customers_total'=>0];
$growth=$growth??0;
?>
<div class="alegra-connector-wrap">

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
<div class="ac-period-bar"><?php
$periods=['today'=>__('Hoy','alegra-connector'),'7d'=>__('7 dias','alegra-connector'),'30d'=>__('30 dias','alegra-connector'),'90d'=>__('90 dias','alegra-connector'),'this_month'=>__('Este mes','alegra-connector'),'last_month'=>__('Mes pasado','alegra-connector'),'this_year'=>__('Este ano','alegra-connector')];
$b=admin_url('admin.php?page=alegra-connector-stats');
foreach($periods as $k=>$lbl):
    $a=($period===$k)?'active':'';
    $u=$b.'&period='.$k;
    $da=['today'=>['start'=>date('Y-m-d'),'end'=>date('Y-m-d')],'7d'=>['start'=>date('Y-m-d',strtotime('-7 days')),'end'=>date('Y-m-d')],'30d'=>['start'=>date('Y-m-d',strtotime('-30 days')),'end'=>date('Y-m-d')],'90d'=>['start'=>date('Y-m-d',strtotime('-90 days')),'end'=>date('Y-m-d')],'this_month'=>['start'=>date('Y-m-01'),'end'=>date('Y-m-d')],'last_month'=>['start'=>date('Y-m-01',strtotime('first day of last month')),'end'=>date('Y-m-t',strtotime('last day of last month'))],'this_year'=>['start'=>date('Y-01-01'),'end'=>date('Y-m-d')]];
    if(isset($da[$k]))$u.='&start='.$da[$k]['start'].'&end='.$da[$k]['end'];
    echo '<a href="'.esc_url($u).'" class="ac-period-btn '.esc_attr($a).'">'.esc_html($lbl).'</a>';
endforeach;?>
</div>
<form method="get" action="<?php echo esc_url(admin_url('admin.php'));?>" style="display:inline-flex;align-items:center;gap:4px;">
<input type="hidden" name="page" value="alegra-connector-stats"><input type="date" name="start" value="<?php echo esc_attr($start);?>" class="ac-period-date"><span style="color:var(--ac-text-muted);">-</span><input type="date" name="end" value="<?php echo esc_attr($end);?>" class="ac-period-date"><button type="submit" class="ac-btn ac-btn-sm"><?php esc_html_e('Filtrar','alegra-connector');?></button>
</form>
</div>

<!-- KPI Row 1 -->
<div class="ac-kpi-grid">
<div class="ac-kpi-card accent-green"><div class="ac-kpi-icon"><span class="dashicons dashicons-chart-area"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Ventas Completadas','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($currency.' '.number_format($revenue['completed'],0));?></span><span class="ac-kpi-sub"><?php echo esc_html($order_counts['completed'].' '.__('pedidos','alegra-connector'));?></span></div></div>
<div class="ac-kpi-card accent-blue"><div class="ac-kpi-icon"><span class="dashicons dashicons-money-alt"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Facturacion Total','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($currency.' '.number_format($revenue['total'],0));?></span><span class="ac-kpi-sub <?php echo $growth_class;?>"><?php if($growth!=0):echo $growth_arrow.' '.esc_html(abs($growth)).'% '.__('vs periodo anterior','alegra-connector');else:esc_html_e('Sin periodo anterior','alegra-connector');endif;?></span></div></div>
<div class="ac-kpi-card accent-amber"><div class="ac-kpi-icon"><span class="dashicons dashicons-chart-bar"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Ticket Promedio','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($currency.' '.number_format($avg_order,0));?></span><span class="ac-kpi-sub"><?php echo esc_html($order_counts['total'].' '.__('pedidos totales','alegra-connector'));?></span></div></div>
<div class="ac-kpi-card accent-purple"><div class="ac-kpi-icon"><span class="dashicons dashicons-yes-alt"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Conversion','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($conversion_rate);?>%</span><span class="ac-kpi-sub"><?php echo esc_html($order_counts['completed'].'/'.$order_counts['total'].' '.__('completados','alegra-connector'));?></span></div></div>
</div>

<!-- KPI Row 2 -->
<div class="ac-kpi-grid">
<div class="ac-kpi-card accent-red"><div class="ac-kpi-icon"><span class="dashicons dashicons-cart"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Carritos Abandonados','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($abandoned_count);?></span><span class="ac-kpi-sub"><?php echo esc_html($currency.' '.number_format($abandoned_value,0).' | '.$abandoned_rate.'%');?></span></div></div>
<div class="ac-kpi-card accent-teal"><div class="ac-kpi-icon"><span class="dashicons dashicons-groups"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Nuevos Clientes','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($new_customers);?></span><span class="ac-kpi-sub"><?php echo esc_html($sync_stats['customers_total'].' '.__('totales','alegra-connector'));?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid var(--ac-primary);"><div class="ac-kpi-icon" style="background:var(--ac-primary-light);color:var(--ac-primary);"><span class="dashicons dashicons-update"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Sincronización Alegra','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:16px;">P:<?php echo esc_html($sync_stats['products_synced'].'/'.$sync_stats['products_total']);?> O:<?php echo esc_html($sync_stats['orders_synced'].'/'.$sync_stats['orders_total']);?> C:<?php echo esc_html($sync_stats['customers_synced'].'/'.$sync_stats['customers_total']);?></span></div></div>
<div class="ac-kpi-card" style="border-left:4px solid #ea580c;"><div class="ac-kpi-icon" style="background:#fff7ed;color:#ea580c;"><span class="dashicons dashicons-clock"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Pendiente por Cobrar','alegra-connector');?></span><span class="ac-kpi-value"><?php echo esc_html($currency.' '.number_format($revenue['pending']+$revenue['processing'],0));?></span><span class="ac-kpi-sub"><?php echo esc_html(($order_counts['pending']+$order_counts['processing']).' '.__('pedidos','alegra-connector'));?></span></div></div>
</div>

<!-- Charts Row -->
<div class="ac-charts-row">
<div class="ac-chart-card"><div class="ac-card-header"><h2><?php esc_html_e('Ventas Diarias','alegra-connector');?></h2></div><div class="ac-chart-body lg"><canvas id="dailySalesChart"></canvas></div></div>
<div class="ac-chart-card"><div class="ac-card-header"><h2><?php esc_html_e('Métodos de Pago','alegra-connector');?></h2></div><div class="ac-chart-body lg"><canvas id="paymentMethodsChart"></canvas></div></div>
</div>

<div class="ac-charts-row equal">
<div class="ac-chart-card"><div class="ac-card-header"><h2><?php esc_html_e('Pedidos por Estado','alegra-connector');?></h2></div><div class="ac-chart-body sm"><canvas id="orderStatusChart"></canvas></div></div>
<div class="ac-chart-card"><div class="ac-card-header"><h2><?php esc_html_e('Top 10 Productos','alegra-connector');?></h2></div>
<div class="ac-table-wrap"><table class="widefat fixed striped"><thead><tr><th>#</th><th><?php esc_html_e('Producto','alegra-connector');?></th><th><?php esc_html_e('Vendidos','alegra-connector');?></th><th><?php esc_html_e('Total','alegra-connector');?></th></tr></thead><tbody><?php $r=1;foreach($top_products as $n=>$d):?><tr><td><?php echo esc_html($r++);?></td><td><?php echo esc_html(mb_strlen($n)>35?mb_substr($n,0,35).'...':$n);?></td><td><?php echo esc_html($d['qty']);?></td><td><?php echo esc_html($currency.' '.number_format($d['total'],0));?></td></tr><?php endforeach;if(empty($top_products)):?><tr><td colspan="4" style="text-align:center;padding:20px;color:var(--ac-text-muted);"><?php esc_html_e('Aún no hay productos vendidos en este periodo','alegra-connector');?></td></tr><?php endif;?></tbody></table></div>
</div>
</div>

<!-- Recent Orders (always shown) -->
<div class="ac-chart-card" style="margin-top:14px;">
<div class="ac-card-header"><h2><?php esc_html_e('Pedidos Recientes','alegra-connector');?></h2></div>
<div class="ac-table-wrap"><table class="widefat fixed striped"><thead><tr><th>ID</th><th><?php esc_html_e('Fecha','alegra-connector');?></th><th><?php esc_html_e('Cliente','alegra-connector');?></th><th><?php esc_html_e('Total','alegra-connector');?></th><th><?php esc_html_e('Estado','alegra-connector');?></th></tr></thead><tbody><?php if(!empty($recent_orders)):foreach($recent_orders as $ro):$sl=wc_get_order_status_name($ro['status']??'pending');?><tr><td><a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-orders&order_id='.$ro['id']));?>">#<?php echo esc_html($ro['id']);?></a></td><td><?php echo esc_html($ro['date']);?></td><td><?php echo esc_html($ro['customer']);?></td><td><?php echo esc_html($currency.' '.number_format($ro['total'],0));?></td><td><?php echo esc_html($sl);?></td></tr><?php endforeach;else:?><tr><td colspan="5" style="text-align:center;padding:20px;color:var(--ac-text-muted);"><?php esc_html_e('Aún no hay pedidos en este periodo','alegra-connector');?></td></tr><?php endif;?></tbody></table></div>
</div>

<?php include __DIR__.'/footer.php'; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color='#64748b';Chart.defaults.borderColor='#e2e8f0';
(function(){var c=['#2563eb','#16a34a','#d97706','#9333ea','#dc2626','#0891b2','#4f46e5','#0d9488','#c026d3','#ea580c'];
var dl=<?php echo json_encode(!empty($daily_sales)?array_keys($daily_sales):[]);?>;
var dd=<?php echo json_encode(!empty($daily_sales)?array_values(array_column($daily_sales,'total')):[]);?>;
new Chart(document.getElementById('dailySalesChart'),{type:'bar',data:{labels:dl.length?dl:[<?php echo json_encode(date('Y-m-d'));?>],datasets:[{label:'Ventas',data:dd.length?dd:[0],backgroundColor:'#2563eb',borderRadius:6,borderSkipped:false}]},options:{responsive:!0,maintainAspectRatio:!1,plugins:{legend:{display:!1}},scales:{y:{beginAtZero:!0,grid:{color:'#f1f5f9'},ticks:{callback:function(v){return'<?php echo esc_js($currency);?>'+(v/1000).toFixed(0)+'k'}}},x:{grid:{display:!1}}}}});
var pl=<?php echo json_encode(!empty($payment_methods)?array_keys($payment_methods):[__('Sin datos','alegra-connector')]);?>;
var pd=<?php echo json_encode(!empty($payment_methods)?array_values(array_column($payment_methods,'total')):[1]);?>;
new Chart(document.getElementById('paymentMethodsChart'),{type:'doughnut',data:{labels:pl,datasets:[{data:pd,backgroundColor:pd.length===1?['#e2e8f0']:c,borderWidth:0}]},options:{responsive:!0,maintainAspectRatio:!1,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:12,font:{size:10}}}}}});
new Chart(document.getElementById('orderStatusChart'),{type:'bar',data:{labels:['Completado','Procesando','Pendiente','Cancelado','Reembolsado'],datasets:[{label:'Pedidos',data:[<?php echo (int)($order_counts['completed']??0);?>,<?php echo (int)($order_counts['processing']??0);?>,<?php echo (int)($order_counts['pending']??0);?>,<?php echo (int)($order_counts['cancelled']??0);?>,<?php echo (int)($order_counts['refunded']??0);?>],backgroundColor:['#16a34a','#2563eb','#d97706','#dc2626','#9333ea'],borderRadius:6,borderSkipped:!1}]},options:{responsive:!0,maintainAspectRatio:!1,plugins:{legend:{display:!1}},scales:{y:{beginAtZero:!0,ticks:{stepSize:1},grid:{color:'#f1f5f9'}},x:{grid:{display:!1}}}}});
})();</script>