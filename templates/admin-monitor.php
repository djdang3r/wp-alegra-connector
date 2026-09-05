<?php
if (!defined('ABSPATH')) exit;
$page_title = __('Monitor de Procesos', 'alegra-connector');
$page_subtitle = __('Visualiza y controla los procesos del plugin en tiempo real', 'alegra-connector');
$header_color = 'blue';
include __DIR__ . '/header.php';
$monitor_nonce = wp_create_nonce('alegra_connector_nonce');
?>
<div class="alegra-connector-wrap">

<?php if (\Alegra\Connector\Kill_Switch::is_active()): ?>
<div class="ac-notice error" style="margin-bottom:16px;">
    <strong><?php esc_html_e('Plugin desconectado', 'alegra-connector'); ?></strong>:
    <?php echo esc_html(sprintf(
        __('Razon: %s. El plugin no procesara ninguna operacion hasta que lo reconectes.', 'alegra-connector'),
        \Alegra\Connector\Kill_Switch::reason()
    )); ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings')); ?>" class="ac-btn ac-btn-sm ac-btn-primary" style="margin-left:8px;">
        <?php esc_html_e('Ir a Configuración', 'alegra-connector'); ?>
    </a>
</div>
<?php endif; ?>

<!-- Emergency stop -->
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-danger);">
    <div class="ac-card-header">
        <h2 style="color:var(--ac-danger);"><?php esc_html_e('Boton de Emergencia', 'alegra-connector'); ?></h2>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);margin-bottom:12px;">
        <?php esc_html_e('Detiene TODOS los procesos del plugin inmediatamente. El plugin quedara desconectado hasta que lo reconectes manualmente.', 'alegra-connector'); ?>
    </p>
    <button type="button" class="ac-btn ac-btn-danger" id="alegra-emergency-stop">
        <span class="dashicons dashicons-warning"></span>
        <?php esc_html_e('Detener Todos los Procesos', 'alegra-connector'); ?>
    </button>
</div>

<!-- Active processes -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Procesos Activos', 'alegra-connector'); ?></h2>
        <span class="ac-badge" id="ac-monitor-running-count" style="margin-left:auto;">0</span>
    </div>
    <div id="ac-monitor-running-list">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Cargando...', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<!-- Scheduled cron -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Tareas Programadas (Cron)', 'alegra-connector'); ?></h2>
    </div>
    <div id="ac-monitor-cron-list">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Cargando...', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<!-- Recent runs -->
<div class="ac-card">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Historial Reciente', 'alegra-connector'); ?></h2>
    </div>
    <div id="ac-monitor-recent-list">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Cargando...', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</div>

<script>
(function($) {
    'use strict';

    var pollInterval = null;
    var monitorNonce = <?php echo json_encode($monitor_nonce); ?>;

    function statusBadge(status) {
        var cls = 'neutral';
        var label = status;
        switch (status) {
            case 'running':   cls = 'warning'; label = 'Corriendo'; break;
            case 'completed': cls = 'success'; label = 'Completado'; break;
            case 'failed':    cls = 'danger';  label = 'Fallido';   break;
            case 'cancelled': cls = 'neutral'; label = 'Cancelado'; break;
            case 'killed':    cls = 'danger';  label = 'Detenido';  break;
        }
        return '<span class="ac-badge ' + cls + '">' + label + '</span>';
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function renderRunning(running) {
        if (!running || running.length === 0) {
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">No hay procesos activos.</p></div>';
        }
        var html = '';
        for (var i = 0; i < running.length; i++) {
            var r = running[i];
            var pct = r.total_items > 0 ? Math.round((r.items_done / r.total_items) * 100) : 0;
            html += '<div class="ac-running-item" data-run-id="' + r.id + '" style="padding:12px;border-bottom:1px solid var(--ac-border-light);">';
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
            html += statusBadge('running');
            html += '<strong>' + escapeHtml(r.type) + '</strong>';
            html += '<span style="color:var(--ac-text-muted);font-size:12px;margin-left:auto;">' + escapeHtml(r.started_at) + '</span>';
            html += '</div>';
            html += '<div style="margin-bottom:6px;font-size:13px;color:var(--ac-text);">' + escapeHtml(r.message || '') + '</div>';
            if (r.total_items > 0) {
                html += '<div style="background:var(--ac-surface-alt);height:6px;border-radius:3px;overflow:hidden;margin-bottom:6px;">';
                html += '<div style="background:var(--ac-primary);height:100%;width:' + pct + '%;"></div></div>';
                html += '<div style="font-size:11px;color:var(--ac-text-muted);">' + r.items_done + ' / ' + r.total_items + ' (' + pct + '%)</div>';
            }
            html += '<div style="font-size:11px;color:var(--ac-text-muted);margin-top:6px;">';
            if (r.memory_mb != null) html += '<span style="margin-right:12px;">Memoria: ' + r.memory_mb.toFixed(1) + ' MB</span>';
            if (r.cpu_load != null) html += '<span style="margin-right:12px;">CPU: ' + r.cpu_load.toFixed(2) + '</span>';
            html += '</div>';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-danger" data-stop-run="' + r.id + '" style="margin-top:8px;">Detener este proceso</button>';
            html += '</div>';
        }
        return html;
    }

    function renderCron(cron) {
        if (!cron || cron.length === 0) {
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">No hay tareas cron programadas.</p></div>';
        }
        var html = '<table class="widefat striped"><thead><tr>' +
            '<th>Hook</th><th>Proxima ejecucion</th><th>Frecuencia</th>' +
            '<th style="width:240px;">Acciónes</th></tr></thead><tbody>';
        for (var i = 0; i < cron.length; i++) {
            var c = cron[i];
            var dt = new Date(c.next_run * 1000);
            var hookAttr = escapeHtml(c.hook).replace(/"/g, '&quot;');
            var tsAttr = c.next_run;
            html += '<tr>';
            html += '<td><code>' + escapeHtml(c.hook) + '</code></td>';
            html += '<td>' + escapeHtml(dt.toLocaleString()) + '<br><small style="color:var(--ac-text-muted);">en ' + escapeHtml(c.next_run_human) + '</small></td>';
            html += '<td>' + escapeHtml(c.schedule) + '</td>';
            html += '<td class="ac-cron-actions">';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-primary ac-cron-run" data-hook="' + hookAttr + '" title="Ejecutar ahora">' +
                    '<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> Ejecutar' +
                    '</button> ';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-cron-skip" data-hook="' + hookAttr + '" data-timestamp="' + tsAttr + '" title="Saltar la proxima ejecucion">' +
                    '<span class="dashicons dashicons-controls-skipforward" style="font-size:12px;width:12px;height:12px;"></span>' +
                    '</button> ';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-danger ac-cron-remove" data-hook="' + hookAttr + '" title="Eliminar todas las programaciones">' +
                    '<span class="dashicons dashicons-trash" style="font-size:12px;width:12px;height:12px;"></span>' +
                    '</button>';
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function renderRecent(recent) {
        if (!recent || recent.length === 0) {
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">Sin historial reciente.</p></div>';
        }
        var html = '<table class="widefat striped"><thead><tr><th>#</th><th>Tipo</th><th>Estado</th><th>Inicio</th><th>Fin</th><th>Items</th><th>Mem.</th><th>Error</th></tr></thead><tbody>';
        for (var i = 0; i < recent.length; i++) {
            var r = recent[i];
            html += '<tr>';
            html += '<td>' + r.id + '</td>';
            html += '<td>' + escapeHtml(r.type) + '</td>';
            html += '<td>' + statusBadge(r.status) + '</td>';
            html += '<td style="font-size:11px;">' + escapeHtml(r.started_at) + '</td>';
            html += '<td style="font-size:11px;">' + escapeHtml(r.finished_at || '-') + '</td>';
            html += '<td>' + (r.items_failed > 0 ? r.items_done + ' ok / ' + r.items_failed + ' error' : r.items_done + ' / ' + r.total_items) + '</td>';
            html += '<td>' + (r.memory_mb != null ? r.memory_mb.toFixed(1) + ' MB' : '-') + '</td>';
            html += '<td style="font-size:11px;color:var(--ac-danger);">' + escapeHtml(r.error || '') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function refresh() {
        $.ajax({
            url: alegraConnector.ajaxUrl,
            type: 'POST',
            data: { action: 'alegra_monitor_status', _ajax_nonce: monitorNonce },
            success: function(r) {
                if (!r || !r.success) return;
                var d = r.data;
                $('#ac-monitor-running-list').html(renderRunning(d.running));
                $('#ac-monitor-running-count').text(d.running.length);
                $('#ac-monitor-cron-list').html(renderCron(d.cron));
                $('#ac-monitor-recent-list').html(renderRecent(d.recent));
            },
            error: function() {
                // Silent fail - retry next poll
            }
        });
    }

    function setupHandlers() {
        $(document).on('click', '[data-stop-run]', function() {
            if (!confirm('Estas seguro de detener este proceso?')) return;
            var runId = $(this).data('stop-run');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_kill_run', _ajax_nonce: monitorNonce, run_id: runId },
                success: function(r) {
                    if (r.success) showNotice(r.data.message, 'success');
                    else showNotice(r.data.message || 'Error', 'error');
                    refresh();
                }
            });
        });

        $(document).on('click', '#alegra-emergency-stop', function() {
            if (!confirm('Esto detendra TODOS los procesos del plugin y lo desconectara. Continuar?')) return;
            var $btn = $(this).prop('disabled', true).text('Deteniendo...');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_kill_all', _ajax_nonce: monitorNonce },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message, 'warning');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showNotice(r.data.message || 'Error', 'error');
                        $btn.prop('disabled', false).text('Detener Todos los Procesos');
                    }
                },
                error: function() {
                    showNotice('Error de conexion', 'error');
                    $btn.prop('disabled', false).text('Detener Todos los Procesos');
                }
            });
        });

        // Run cron now
        $(document).on('click', '.ac-cron-run', function() {
            var $btn = $(this).prop('disabled', true).html('<span class="dashicons dashicons-update ac-spin" style="font-size:12px;width:12px;height:12px;"></span>');
            var hook = $(this).data('hook');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_run_cron_now', _ajax_nonce: monitorNonce, hook: hook },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message || 'Hook ejecutado', 'success');
                        setTimeout(refresh, 1500);
                    } else {
                        showNotice(r.data.message || 'Error', 'error');
                    }
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> Ejecutar');
                },
                error: function() {
                    showNotice('Error de conexion', 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> Ejecutar');
                }
            });
        });

        // Skip cron next run
        $(document).on('click', '.ac-cron-skip', function() {
            if (!confirm('Saltar la proxima ejecucion de esta tarea cron?')) return;
            var $btn = $(this).prop('disabled', true);
            var hook = $(this).data('hook');
            var timestamp = $(this).data('timestamp');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_skip_cron_next', _ajax_nonce: monitorNonce, hook: hook, timestamp: timestamp },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message || 'Tarea saltada', 'success');
                        setTimeout(refresh, 1000);
                    } else {
                        showNotice(r.data.message || 'Error', 'error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotice('Error de conexion', 'error');
                    $btn.prop('disabled', false);
                }
            });
        });

        // Remove cron entirely
        $(document).on('click', '.ac-cron-remove', function() {
            if (!confirm('Eliminar TODAS las programaciones de esta tarea cron?')) return;
            var $btn = $(this).prop('disabled', true);
            var hook = $(this).data('hook');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_unschedule_cron', _ajax_nonce: monitorNonce, hook: hook },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message || 'Tarea eliminada', 'warning');
                        setTimeout(refresh, 1000);
                    } else {
                        showNotice(r.data.message || 'Error', 'error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotice('Error de conexion', 'error');
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    function showNotice(msg, type) {
        type = type || 'info';
        var $n = $('<div class="ac-notice ' + type + '" style="display:none;margin:8px 0 14px 0;">' + msg + '</div>');
        $('.alegra-connector-wrap').first().prepend($n);
        $n.slideDown(200);
        setTimeout(function() { $n.slideUp(300, function() { $(this).remove(); }); }, 6000);
    }

    $(document).ready(function() {
        setupHandlers();
        refresh();
        // Poll every 5 seconds
        pollInterval = setInterval(refresh, 5000);
        // Cleanup on unload to prevent memory leaks
        $(window).on('beforeunload', function() {
            if (pollInterval) clearInterval(pollInterval);
        });
    });
})(jQuery);
</script>
