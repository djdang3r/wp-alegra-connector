<?php if(!defined('ABSPATH'))exit;
/**
 * Webhooks recibidos — raw deliveries recorded by the receiver.
 *
 * Read-only view over includes/Webhooks/Recorder.php. It answers, from the raw
 * payloads, whether Alegra emits `edit-item` (and whether it carries
 * `inventory.availableQuantity`) when stock changes. Every payload is remote
 * data: escape EVERYTHING. The only write is the nonce-protected "Limpiar"
 * form, handled by Admin_Dashboard::handle_clear_webhooks().
 */
$page_title=__('Webhooks recibidos','alegra-connector');
$page_subtitle=__('Qué envía Alegra, tal cual llegó — y si edit-item trae inventario','alegra-connector');
$entries=$entries??[];
$subjects=$subjects??[];
$selected_subject=$selected_subject??'';
$verdict=$verdict??'';
$edit_items=$edit_items??[];
$total=$total??0;
$shown=$shown??0;
$retention=$retention??50;
$cleared=$cleared??false;
$recorder='\Alegra\Connector\Webhooks\Recorder';
$webhooks_url=admin_url('admin.php?page=alegra-connector-webhooks');
include __DIR__.'/header.php';
?>
<div class="alegra-connector-wrap">

<?php if($cleared):?>
<div class="ac-card" style="border-left:4px solid var(--ac-success);margin-bottom:16px;padding:12px 20px;">
    <span class="ac-badge success"><?php esc_html_e('Listo','alegra-connector');?></span>
    <span style="margin-left:8px;font-size:13px;color:var(--ac-text);"><?php esc_html_e('Se borraron las entregas registradas.','alegra-connector');?></span>
</div>
<?php endif;?>

<!-- Filter + actions -->
<div class="ac-card" style="margin-bottom:16px;padding:14px 20px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php'));?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0;">
            <input type="hidden" name="page" value="alegra-connector-webhooks">
            <span style="font-weight:600;font-size:13px;color:var(--ac-text);"><?php esc_html_e('Filtrar:','alegra-connector');?></span>
            <select name="subject" style="padding:6px 12px;border-radius:var(--ac-radius-xs);border:1px solid var(--ac-border);font-size:12px;background:var(--ac-surface);">
                <option value=""><?php esc_html_e('Todos los eventos','alegra-connector');?></option>
                <?php foreach($subjects as $subject_option):?>
                    <option value="<?php echo esc_attr($subject_option);?>" <?php selected($selected_subject,$subject_option);?>><?php echo esc_html($subject_option);?></option>
                <?php endforeach;?>
            </select>
            <button type="submit" class="ac-btn ac-btn-sm"><span class="dashicons dashicons-filter" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Filtrar','alegra-connector');?></button>
        </form>
        <a class="ac-btn ac-btn-sm" href="<?php echo esc_url($webhooks_url);?>"><span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Recargar','alegra-connector');?></a>
        <div style="flex:1;"></div>
        <span style="font-size:11px;color:var(--ac-text-muted);"><?php echo esc_html(sprintf(__('Se guardan las últimas %d entregas.','alegra-connector'),$retention));?></span>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('<?php echo esc_attr(__('¿Borrar todas las entregas de webhooks registradas?','alegra-connector'));?>');" style="display:inline;margin:0;">
            <input type="hidden" name="action" value="alegra_clear_webhooks">
            <?php wp_nonce_field('alegra_clear_webhooks');?>
            <button type="submit" class="ac-btn ac-btn-sm" style="color:var(--ac-danger);"><span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Limpiar','alegra-connector');?></button>
        </form>
    </div>
</div>

<!-- The critical verdict -->
<?php
$verdict_class='info';
$verdict_icon='dashicons-info-outline';
if($verdict===$recorder::VERDICT_YES){$verdict_class='success';$verdict_icon='dashicons-yes-alt';}
elseif($verdict===$recorder::VERDICT_NO){$verdict_class='danger';$verdict_icon='dashicons-no-alt';}
elseif($verdict===$recorder::VERDICT_NONE){$verdict_class='warning';$verdict_icon='dashicons-warning';}
?>
<div class="ac-card" style="border-left:4px solid var(--ac-<?php echo esc_attr($verdict_class);?>);margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><span class="dashicons <?php echo esc_attr($verdict_icon);?>" style="font-size:18px;width:18px;height:18px;"></span> <?php esc_html_e('Veredicto: ¿Alegra manda inventario en edit-item?','alegra-connector');?></h2>
        <span class="ac-badge <?php echo esc_attr($verdict_class);?>"><?php echo esc_html($verdict);?></span>
    </div>
    <p style="font-size:12px;color:var(--ac-text-muted);margin:0;"><?php echo esc_html(sprintf(__('Calculado sobre TODAS las entregas guardadas (%d), no sobre el filtro.','alegra-connector'),$total));?></p>
</div>

<?php if($edit_items===[]):?>
<!-- No edit-item yet: warn + exact test procedure -->
<div class="ac-card" style="border-left:4px solid var(--ac-warning);margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Todavía no hay entregas de edit-item','alegra-connector');?></h2></div>
    <p style="font-size:13px;color:var(--ac-text);"><?php esc_html_e('Sin una entrega de edit-item no se puede responder la pregunta. Hacé la prueba en vivo y recargá esta página:','alegra-connector');?></p>
    <ol style="font-size:13px;color:var(--ac-text);margin:10px 0 0 20px;line-height:1.7;">
        <li><?php esc_html_e('En Configuración, suscribí el evento edit-item (selector de eventos) y volvé a registrar los webhooks.','alegra-connector');?></li>
        <li><?php esc_html_e('Anotá el stock de un producto en Alegra.','alegra-connector');?></li>
        <li><?php esc_html_e('Anulá una factura que haya descontado ese producto (o hacé un ajuste de inventario).','alegra-connector');?></li>
        <li><?php esc_html_e('Recargá esta página y buscá una entrega edit-item.','alegra-connector');?></li>
        <li><?php esc_html_e('Leé el veredicto de arriba: SÍ/NO sobre inventory.availableQuantity.','alegra-connector');?></li>
    </ol>
</div>
<?php endif;?>

<?php if($total===0):?>
<!-- Empty buffer -->
<div class="ac-empty-state" style="padding:60px 20px;">
    <span class="dashicons dashicons-cloud" style="font-size:48px;width:48px;height:48px;opacity:0.2;margin-bottom:12px;"></span>
    <p style="font-size:14px;color:var(--ac-text-secondary);"><?php esc_html_e('Todavía no se registró ninguna entrega de webhooks.','alegra-connector');?></p>
    <p style="font-size:12px;color:var(--ac-text-muted);"><?php esc_html_e('Cuando Alegra envíe un webhook al plugin, va a aparecer acá con su payload crudo, el evento, la entidad y la IP de origen.','alegra-connector');?></p>
    <p style="font-size:12px;color:var(--ac-text-muted);"><?php esc_html_e('Si ya registraste los webhooks, probá hacer un cambio en Alegra y recargá esta página.','alegra-connector');?></p>
</div>
<?php else:?>

<!-- Summary table -->
<div class="ac-card" style="padding:0;overflow:hidden;margin-bottom:16px;">
    <div class="ac-card-header" style="padding:14px 20px;">
        <h2><?php esc_html_e('Resumen de entregas','alegra-connector');?></h2>
        <span class="ac-badge info"><?php echo esc_html(sprintf(__('%1$d de %2$d','alegra-connector'),$shown,$total));?></span>
    </div>
    <div class="ac-table-wrap">
        <table class="widefat fixed striped">
            <thead><tr>
                <th style="width:40px;">#</th>
                <th style="width:170px;"><?php esc_html_e('Fecha','alegra-connector');?></th>
                <th style="width:150px;"><?php esc_html_e('Evento','alegra-connector');?></th>
                <th><?php esc_html_e('Entidad','alegra-connector');?></th>
                <th style="width:150px;"><?php esc_html_e('Inventario','alegra-connector');?></th>
                <th style="width:130px;"><?php esc_html_e('IP de origen','alegra-connector');?></th>
            </tr></thead>
            <tbody>
            <?php foreach($entries as $index=>$entry):
                $body=(string)($entry['body']??'');
                $entry_subject=(string)($entry['subject']??'');
                $entity=$recorder::entity_summary($body);
                $ip=(string)($entry['ip']??'');
                $has_inventory=($entry_subject==='edit-item')?$recorder::has_inventory_available_quantity($body):null;
            ?>
                <tr>
                    <td><?php echo esc_html((string)($index+1));?></td>
                    <td style="font-family:monospace;font-size:11px;color:var(--ac-text-secondary);white-space:nowrap;"><?php echo esc_html((string)($entry['time']??'?'));?></td>
                    <td><span class="ac-badge info"><?php echo esc_html($entry_subject!==''?$entry_subject:'?');?></span></td>
                    <td style="font-size:12px;color:var(--ac-text);word-break:break-word;"><?php echo esc_html($entity!==''?$entity:'(sin entidad reconocida)');?></td>
                    <td>
                        <?php if($has_inventory===true):?>
                            <span class="ac-badge success"><?php esc_html_e('SÍ','alegra-connector');?></span>
                        <?php elseif($has_inventory===false):?>
                            <span class="ac-badge danger"><?php esc_html_e('NO','alegra-connector');?></span>
                        <?php else:?>
                            <span style="color:var(--ac-text-muted);">—</span>
                        <?php endif;?>
                    </td>
                    <td style="font-family:monospace;font-size:11px;color:var(--ac-text-muted);"><?php echo esc_html($ip!==''?$ip:'(desconocida)');?></td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
</div>

<!-- Raw payloads -->
<div class="ac-card" style="padding:14px 20px;">
    <div class="ac-card-header" style="padding:0 0 10px 0;">
        <h2><?php esc_html_e('Payloads crudos (JSON)','alegra-connector');?></h2>
        <span style="font-size:11px;color:var(--ac-text-muted);"><?php esc_html_e('Click en cada entrega para ver el cuerpo completo','alegra-connector');?></span>
    </div>
    <?php foreach($entries as $index=>$entry):
        $body=(string)($entry['body']??'');
        $entry_subject=(string)($entry['subject']??'');
        $entity=$recorder::entity_summary($body);
        $ip=(string)($entry['ip']??'');
        $truncated=(bool)($entry['truncated']??false);
        $decoded=$recorder::payload($body);
        $json=$decoded!==null?(string)wp_json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):$body;
    ?>
        <details style="border:1px solid var(--ac-border);border-radius:var(--ac-radius-xs);margin-bottom:8px;overflow:hidden;">
            <summary style="cursor:pointer;padding:10px 14px;background:var(--ac-surface-alt);font-size:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <strong style="color:var(--ac-text);">[<?php echo esc_html((string)($index+1));?>] <?php echo esc_html($entry_subject!==''?$entry_subject:'?');?></strong>
                <span style="font-family:monospace;color:var(--ac-text-secondary);"><?php echo esc_html((string)($entry['time']??'?'));?></span>
                <span style="color:var(--ac-text-secondary);"><?php echo esc_html($entity!==''?$entity:'(sin entidad reconocida)');?></span>
                <span style="color:var(--ac-text-muted);"><?php esc_html_e('IP:','alegra-connector');?> <?php echo esc_html($ip!==''?$ip:'(desconocida)');?></span>
                <?php if($truncated):?><span class="ac-badge warning"><?php echo esc_html(sprintf(__('[TRUNCADO] %d bytes','alegra-connector'),(int)($entry['bytes']??0)));?></span><?php endif;?>
            </summary>
            <pre style="margin:0;padding:14px;background:#0f172a;color:#e2e8f0;font-size:11px;line-height:1.5;overflow-x:auto;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html($json);?></pre>
        </details>
    <?php endforeach;?>
</div>

<?php endif;?>

<?php include __DIR__.'/footer.php'; ?>
</div>
