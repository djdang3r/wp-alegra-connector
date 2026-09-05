<?php if(!defined('ABSPATH'))exit;
$page_title=__('Logs de Auditoria','alegra-connector');
$page_subtitle=__('Registro detallado de todas las sincronizaciones y eventos','alegra-connector');
$log_stats=$log_stats??['total'=>0,'info'=>0,'warning'=>0,'error'=>0,'critical'=>0,'today'=>0,'system'=>0];
$log_entries=$log_entries??[];
$log_files=$log_files??[];
$system_logs=$system_logs??[];
include __DIR__.'/header.php';
?>
<div class="alegra-connector-wrap">

<!-- Log Stats Cards -->
<div class="ac-kpi-grid" style="grid-template-columns:repeat(5,1fr);">
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-text-muted);"><div class="ac-kpi-icon" style="background:var(--ac-surface-alt);color:var(--ac-text-secondary);"><span class="dashicons dashicons-media-text"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Total Entradas','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:20px;"><?php echo esc_html($log_stats['total']);?></span></div></div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-info);"><div class="ac-kpi-icon" style="background:var(--ac-info-bg);color:var(--ac-info);"><span class="dashicons dashicons-info-outline"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label">INFO</span><span class="ac-kpi-value" style="font-size:20px;color:var(--ac-info);"><?php echo esc_html($log_stats['info']);?></span></div></div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-warning);"><div class="ac-kpi-icon" style="background:var(--ac-warning-bg);color:var(--ac-warning);"><span class="dashicons dashicons-warning"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label">WARNING</span><span class="ac-kpi-value" style="font-size:20px;color:var(--ac-warning);"><?php echo esc_html($log_stats['warning']);?></span></div></div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-danger);"><div class="ac-kpi-icon" style="background:var(--ac-danger-bg);color:var(--ac-danger);"><span class="dashicons dashicons-dismiss"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label">ERROR + CRITICAL</span><span class="ac-kpi-value" style="font-size:20px;color:var(--ac-danger);"><?php echo esc_html($log_stats['error']+$log_stats['critical']);?></span></div></div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-primary);"><div class="ac-kpi-icon" style="background:var(--ac-primary-light);color:var(--ac-primary);"><span class="dashicons dashicons-calendar"></span></div><div class="ac-kpi-content"><span class="ac-kpi-label"><?php esc_html_e('Hoy','alegra-connector');?></span><span class="ac-kpi-value" style="font-size:20px;color:var(--ac-primary);"><?php echo esc_html($log_stats['today']);?></span></div></div>
</div>

<!-- Filter + Actions Bar -->
<div class="ac-card" style="margin-bottom:16px;padding:14px 20px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span style="font-weight:600;font-size:13px;color:var(--ac-text);"><?php esc_html_e('Filtrar:','alegra-connector');?></span>
        <select id="alegra-log-level" style="padding:6px 12px;border-radius:var(--ac-radius-xs);border:1px solid var(--ac-border);font-size:12px;background:var(--ac-surface);">
            <option value=""><?php esc_html_e('Todos los niveles','alegra-connector');?></option>
            <option value="INFO" style="color:var(--ac-info);">INFO</option>
            <option value="WARNING" style="color:var(--ac-warning);">WARNING</option>
            <option value="ERROR" style="color:var(--ac-danger);">ERROR</option>
            <option value="CRITICAL" style="color:var(--ac-danger);">CRITICAL</option>
        </select>
        <input type="text" id="alegra-log-search" placeholder="<?php esc_attr_e('Buscar en logs...','alegra-connector');?>" style="padding:6px 12px;border-radius:var(--ac-radius-xs);border:1px solid var(--ac-border);font-size:12px;width:200px;background:var(--ac-surface);">
        <div style="flex:1;"></div>
        <button class="ac-btn ac-btn-sm" id="alegra-refresh-logs"><span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Actualizar','alegra-connector');?></button>
        <button class="ac-btn ac-btn-sm" id="alegra-download-logs"><span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Descargar','alegra-connector');?></button>
        <button class="ac-btn ac-btn-sm" id="alegra-clear-logs" style="color:var(--ac-danger);"><span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Limpiar antiguos','alegra-connector');?></button>
    </div>
</div>

<!-- Log Viewer -->
<?php if(!empty($log_entries)):?>
<div class="ac-card" style="padding:0;overflow:hidden;">
    <div style="max-height:500px;overflow-y:auto;">
        <table class="widefat fixed" style="border:none;margin:0;">
            <thead style="position:sticky;top:0;z-index:1;">
                <tr style="background:var(--ac-surface-alt);">
                    <th style="width:160px;padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--ac-text-secondary);"><?php esc_html_e('Timestamp','alegra-connector');?></th>
                    <th style="width:90px;padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--ac-text-secondary);"><?php esc_html_e('Nivel','alegra-connector');?></th>
                    <th style="padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--ac-text-secondary);"><?php esc_html_e('Mensaje','alegra-connector');?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($log_entries as $e):
                    $lv=strtolower($e['level']??'');
                    $rowBg='';
                    $rowBorder='';
                    if($lv==='error'||$lv==='critical'){$rowBg='background:#fef2f2;';$rowBorder='border-left:3px solid var(--ac-danger);';}
                    elseif($lv==='warning'){$rowBg='background:#fffbeb;';$rowBorder='border-left:3px solid var(--ac-warning);';}
                    $bc=$lv==='error'||$lv==='critical'?'danger':($lv==='warning'?'warning':'info');
                ?>
                <tr style="<?php echo $rowBg.$rowBorder;?>">
                    <td style="padding:8px 16px;font-family:monospace;font-size:11px;color:var(--ac-text-secondary);white-space:nowrap;"><?php echo esc_html($e['timestamp']);?></td>
                    <td style="padding:8px 16px;"><span class="ac-badge <?php echo $bc;?>"><?php echo esc_html($e['level']);?></span></td>
                    <td style="padding:8px 16px;font-size:12px;color:var(--ac-text);word-break:break-word;"><?php echo esc_html($e['message']);?></td>
                </tr>
                <?php endforeach;?>
            </tbody>
        </table>
    </div>
</div>
<?php else:?>
<div class="ac-empty-state" style="padding:60px 20px;">
    <span class="dashicons dashicons-media-text" style="font-size:48px;width:48px;height:48px;opacity:0.2;margin-bottom:12px;"></span>
    <p style="font-size:14px;color:var(--ac-text-secondary);"><?php esc_html_e('No hay registros de log todavia.','alegra-connector');?></p>
    <p style="font-size:12px;color:var(--ac-text-muted);"><?php esc_html_e('Los logs se generan cuando ocurren sincronizaciones, errores o eventos de conexion.','alegra-connector');?></p>
</div>
<?php endif;?>

<!-- Log Files -->
<?php if(!empty($log_files)):?>
<div class="ac-card" style="margin-top:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Archivos de Log','alegra-connector');?></h2><span style="font-size:11px;color:var(--ac-text-muted);"><?php echo esc_html(count($log_files).' '.__('archivos','alegra-connector'));?></span></div>
    <div class="ac-table-wrap">
        <table class="widefat fixed striped">
            <thead><tr><th><?php esc_html_e('Archivo','alegra-connector');?></th><th><?php esc_html_e('Tamano','alegra-connector');?></th><th><?php esc_html_e('Última modificacion','alegra-connector');?></th><th></th></tr></thead>
            <tbody>
                <?php foreach($log_files as $lf):?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;"><span class="dashicons dashicons-media-text" style="font-size:14px;width:14px;height:14px;color:var(--ac-text-muted);margin-right:4px;"></span><?php echo esc_html($lf['name']);?></td>
                    <td><?php echo esc_html($lf['size']);?></td>
                    <td><?php echo esc_html($lf['modified']);?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin-ajax.php?action=alegra_download_logs&log_file='.urlencode($lf['name']).'&_ajax_nonce='.wp_create_nonce('alegra_connector_nonce')));?>" class="ac-btn ac-btn-xs"><span class="dashicons dashicons-download" style="font-size:12px;width:12px;height:12px;"></span> <?php esc_html_e('Descargar','alegra-connector');?></a></td>
                </tr>
                <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px;font-size:11px;color:var(--ac-text-muted);">
        <?php esc_html_e('Los logs se almacenan en:','alegra-connector');?> <code style="font-size:11px;">wp-content/uploads/alegra-logs/</code>
    </div>
</div>
<?php endif;?>

<!-- System Logs -->
<?php if(!empty($system_logs)):?>
<div class="ac-card" style="margin-top:16px;border-left:4px solid #6366f1;">
    <div class="ac-card-header"><h2><?php esc_html_e('Logs del Sistema (WordPress/PHP)','alegra-connector');?></h2><span class="ac-badge info"><?php echo esc_html(count($system_logs).' '.__('entradas','alegra-connector'));?></span></div>
    <p style="font-size:12px;color:var(--ac-text-muted);margin-bottom:12px;"><?php esc_html_e('Errores y avisos de WordPress y PHP. Util para diagnosticar problemas del servidor.','alegra-connector');?></p>
    <div style="max-height:400px;overflow-y:auto;"><table class="widefat fixed" style="border:none;margin:0;"><thead style="position:sticky;top:0;z-index:1;"><tr style="background:var(--ac-surface-alt);"><th style="width:160px;padding:10px 16px;font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Timestamp','alegra-connector');?></th><th style="width:85px;padding:10px 16px;font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Nivel','alegra-connector');?></th><th style="width:80px;padding:10px 16px;font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Origen','alegra-connector');?></th><th style="padding:10px 16px;font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Mensaje','alegra-connector');?></th></tr></thead><tbody><?php foreach($system_logs as $sl):$slv=strtolower($sl['level']??'');$bc=$slv==='error'||$slv==='critical'?'danger':($slv==='warning'?'warning':($slv==='debug'?'neutral':'info'));?><tr style="<?php echo $slv==='error'||$slv==='critical'?'background:#fef2f2;border-left:3px solid var(--ac-danger);':($slv==='warning'?'background:#fffbeb;border-left:3px solid var(--ac-warning);':'');?>"><td style="padding:6px 16px;font-family:monospace;font-size:10px;color:var(--ac-text-muted);white-space:nowrap;"><?php echo esc_html($sl['timestamp']);?></td><td style="padding:6px 16px;"><span class="ac-badge <?php echo $bc;?>" style="font-size:10px;"><?php echo esc_html($sl['level']);?></span></td><td style="padding:6px 16px;font-size:11px;"><?php echo esc_html($sl['source']??'--');?></td><td style="padding:6px 16px;font-size:11px;color:var(--ac-text);word-break:break-word;font-family:monospace;"><?php echo esc_html($sl['message']);?></td></tr><?php endforeach;?></tbody></table></div>
</div>
<?php else:?>
<div class="ac-card" style="margin-top:16px;border-left:4px solid var(--ac-text-muted);"><div class="ac-card-header"><h2><?php esc_html_e('Logs del Sistema','alegra-connector');?></h2></div><p style="font-size:13px;color:var(--ac-text-muted);"><?php esc_html_e('No se encontro debug.log. Para habilitar logs del sistema, agrega a wp-config.php:','alegra-connector');?></p><code style="display:block;padding:10px;background:var(--ac-surface-alt);border-radius:6px;margin-top:8px;font-size:12px;">define('WP_DEBUG', true);<br>define('WP_DEBUG_LOG', true);</code></div>
<?php endif;?>

<?php include __DIR__.'/footer.php'; ?>
</div>

<script>
jQuery(function($){
    // Client-side filtering
    function filterLogs(){
        var lvl=$('#alegra-log-level').val().toUpperCase();
        var search=$('#alegra-log-search').val().toLowerCase();
        $('table tbody tr').each(function(){
            var row=$(this);
            var rlvl=row.find('.ac-badge').text().trim().toUpperCase();
            var rmsg=row.find('td:last').text().toLowerCase();
            var show=true;
            if(lvl&&rlvl!==lvl) show=false;
            if(search&&rmsg.indexOf(search)===-1) show=false;
            row.toggle(show);
        });
    }
    $('#alegra-log-level, #alegra-log-search').on('change keyup', filterLogs);
    $('#alegra-refresh-logs').on('click',function(){location.reload();});
});
</script>