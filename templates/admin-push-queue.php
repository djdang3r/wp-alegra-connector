<?php
if (!defined('ABSPATH')) exit;
$page_title = __('Cola de Push a Alegra', 'alegra-connector');
$page_subtitle = __('Revisa y aprueba cada operacion antes de enviarla a Alegra', 'alegra-connector');
$header_color = 'amber';
include __DIR__ . '/header.php';
$queue_nonce = wp_create_nonce('alegra_connector_nonce');
$pending = \Alegra\Connector\Push_Queue::get_pending(50);
$recent = \Alegra\Connector\Push_Queue::get_all(20);
?>
<div class="alegra-connector-wrap">

<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-warning);">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Como funciona la Cola de Push?', 'alegra-connector'); ?></h2>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:12px;">
        <?php esc_html_e('Cuando el plugin detecta una operacion que deberia enviarse a Alegra (una venta, un pago, un cliente nuevo), y la direccion esta en modo "manual", la operacion se encola aqui para tu revision.', 'alegra-connector'); ?>
    </p>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:0;">
        <?php esc_html_e('Tu debes aprobar cada item antes de que se envie a Alegra. Esto protege la integridad de tu sistema contable.', 'alegra-connector'); ?>
    </p>
</div>

<!-- Config: direcciones de push -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Dirección de Push (cuando enviar a Alegra)', 'alegra-connector'); ?></h2>
    </div>
    <form id="ac-push-direction-form">
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Productos:', 'alegra-connector'); ?></th>
                <td>
                    <select name="product" class="ac-push-direction-select">
                        <option value="disabled"><?php esc_html_e('Desactivado (no subir)', 'alegra-connector'); ?></option>
                        <option value="manual"><?php esc_html_e('Manual (cola de revision)', 'alegra-connector'); ?></option>
                        <option value="auto"><?php esc_html_e('Automático (inmediato)', 'alegra-connector'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Clientes:', 'alegra-connector'); ?></th>
                <td>
                    <select name="customer" class="ac-push-direction-select">
                        <option value="disabled"><?php esc_html_e('Desactivado (no subir)', 'alegra-connector'); ?></option>
                        <option value="manual"><?php esc_html_e('Manual (cola de revision)', 'alegra-connector'); ?></option>
                        <option value="auto"><?php esc_html_e('Automático (inmediato)', 'alegra-connector'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Pedidos (facturas):', 'alegra-connector'); ?></th>
                <td>
                    <select name="order" class="ac-push-direction-select">
                        <option value="disabled"><?php esc_html_e('Desactivado (no subir)', 'alegra-connector'); ?></option>
                        <option value="manual"><?php esc_html_e('Manual (cola de revision)', 'alegra-connector'); ?></option>
                        <option value="auto"><?php esc_html_e('Automático (inmediato)', 'alegra-connector'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Pagos:', 'alegra-connector'); ?></th>
                <td>
                    <select name="payment" class="ac-push-direction-select">
                        <option value="disabled"><?php esc_html_e('Desactivado (no subir)', 'alegra-connector'); ?></option>
                        <option value="manual"><?php esc_html_e('Manual (cola de revision)', 'alegra-connector'); ?></option>
                        <option value="auto"><?php esc_html_e('Automático (inmediato)', 'alegra-connector'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <p class="description"><?php esc_html_e('Recomendamos dejar todo en "Manual" hasta validar que el plugin funciona correctamente.', 'alegra-connector'); ?></p>
        <button type="submit" class="ac-btn ac-btn-primary"><?php esc_html_e('Guardar configuracion', 'alegra-connector'); ?></button>
    </form>
</div>

<!-- Pending -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Pendientes de Revision', 'alegra-connector'); ?></h2>
        <span class="ac-badge warning" style="margin-left:auto;"><?php echo count($pending); ?></span>
    </div>
    <?php if (empty($pending)): ?>
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-secondary);"><?php esc_html_e('No hay operaciones pendientes de revision.', 'alegra-connector'); ?></p>
        </div>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Tipo', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Entidad', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Acción', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Detectado', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Acciónes', 'alegra-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $item): ?>
                    <tr data-queue-id="<?php echo esc_attr($item->id); ?>">
                        <td><strong><?php echo esc_html($item->entity_type); ?></strong></td>
                        <td>#<?php echo esc_html($item->entity_id); ?></td>
                        <td><?php echo esc_html($item->action); ?></td>
                        <td style="font-size:12px;color:var(--ac-text-muted);"><?php echo esc_html($item->detected_at); ?></td>
                        <td>
                            <button type="button" class="ac-btn ac-btn-xs ac-btn-success ac-approve-push" data-queue-id="<?php echo esc_attr($item->id); ?>" style="margin-right:4px;">
                                <?php esc_html_e('Aprobar', 'alegra-connector'); ?>
                            </button>
                            <button type="button" class="ac-btn ac-btn-xs ac-btn-danger ac-reject-push" data-queue-id="<?php echo esc_attr($item->id); ?>">
                                <?php esc_html_e('Rechazar', 'alegra-connector'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- History -->
<div class="ac-card">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Historial', 'alegra-connector'); ?></h2>
    </div>
    <?php if (empty($recent)): ?>
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-secondary);"><?php esc_html_e('Sin historial.', 'alegra-connector'); ?></p>
        </div>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Tipo', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Entidad', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Estado', 'alegra-connector'); ?></th>
                    <th><?php esc_html_e('Cuando', 'alegra-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $item):
                    $status_cls = 'neutral';
                    $status_label = $item->status;
                    switch ($item->status) {
                        case 'pending':   $status_cls = 'warning'; $status_label = 'Pendiente'; break;
                        case 'approved':  $status_cls = 'info';    $status_label = 'Aprobado'; break;
                        case 'applied':   $status_cls = 'success'; $status_label = 'Aplicado'; break;
                        case 'failed':    $status_cls = 'danger';  $status_label = 'Fallido'; break;
                        case 'rejected':  $status_cls = 'neutral'; $status_label = 'Rechazado'; break;
                    }
                ?>
                    <tr>
                        <td><?php echo esc_html($item->entity_type); ?></td>
                        <td>#<?php echo esc_html($item->entity_id); ?></td>
                        <td><span class="ac-badge <?php echo esc_attr($status_cls); ?>"><?php echo esc_html($status_label); ?></span></td>
                        <td style="font-size:12px;color:var(--ac-text-muted);"><?php echo esc_html($item->detected_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</div>

<script>
(function($) {
    'use strict';

    var nonce = <?php echo json_encode($queue_nonce); ?>;
    var directions = {
        product:  <?php echo json_encode(\Alegra\Connector\Push_Queue::get_direction('product')); ?>,
        customer: <?php echo json_encode(\Alegra\Connector\Push_Queue::get_direction('customer')); ?>,
        order:    <?php echo json_encode(\Alegra\Connector\Push_Queue::get_direction('order')); ?>,
        payment:  <?php echo json_encode(\Alegra\Connector\Push_Queue::get_direction('payment')); ?>
    };

    function showNotice(msg, type) {
        type = type || 'info';
        var $n = $('<div class="ac-notice ' + type + '" style="display:none;margin:8px 0;">' + msg + '</div>');
        $('.alegra-connector-wrap').first().prepend($n);
        $n.slideDown(200);
        setTimeout(function() { $n.slideUp(300, function() { $(this).remove(); }); }, 5000);
    }

    // Load saved directions
    $('.ac-push-direction-select').each(function() {
        var name = $(this).attr('name');
        if (directions[name]) $(this).val(directions[name]);
    });

    // Save directions
    $('#ac-push-direction-form').on('submit', function(e) {
        e.preventDefault();
        var data = { action: 'alegra_save_sync_directions', _ajax_nonce: nonce };
        $('.ac-push-direction-select').each(function() {
            data['direction_' + $(this).attr('name')] = $(this).val();
        });
        $.ajax({
            url: alegraConnector.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(r) {
                if (r.success) showNotice(r.data.message || 'Guardado', 'success');
                else showNotice(r.data.message || 'Error', 'error');
            }
        });
    });

    // Approve
    $(document).on('click', '.ac-approve-push', function() {
        var id = $(this).data('queue-id');
        // Double confirmation
        var code = prompt('Para confirmar el envio a Alegra, escribe ENVIAR en mayusculas:');
        if (!code || code.trim().toUpperCase() !== 'ENVIAR') {
            showNotice('Confirmacion cancelada. No se envio nada a Alegra.', 'warning');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Enviando...');
        $.ajax({
            url: alegraConnector.ajaxUrl,
            type: 'POST',
            data: { action: 'alegra_approve_push', _ajax_nonce: nonce, queue_id: id },
            success: function(r) {
                if (r.success) {
                    showNotice(r.data.message || 'Aprobado', 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showNotice(r.data.message || 'Error', 'error');
                    $btn.prop('disabled', false).text('Aprobar');
                }
            },
            error: function() {
                showNotice('Error de conexion', 'error');
                $btn.prop('disabled', false).text('Aprobar');
            }
        });
    });

    // Reject
    $(document).on('click', '.ac-reject-push', function() {
        if (!confirm('Rechazar esta operacion? No se enviara a Alegra.')) return;
        var id = $(this).data('queue-id');
        $.ajax({
            url: alegraConnector.ajaxUrl,
            type: 'POST',
            data: { action: 'alegra_reject_push', _ajax_nonce: nonce, queue_id: id },
            success: function(r) {
                if (r.success) {
                    showNotice(r.data.message || 'Rechazado', 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showNotice(r.data.message || 'Error', 'error');
                }
            }
        });
    });
})(jQuery);
</script>
