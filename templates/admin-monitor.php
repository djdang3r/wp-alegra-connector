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

<!-- task-5: read-only import diagnostics -->
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-warning);">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Diagnóstico de importación', 'alegra-connector'); ?></h2>
        <button type="button" class="ac-btn ac-btn-xs ac-btn-danger" id="alegra-diag-release" style="margin-left:auto;">
            <span class="dashicons dashicons-controls-back" style="font-size:12px;width:12px;height:12px;"></span>
            <?php esc_html_e('Liberar importación', 'alegra-connector'); ?>
        </button>
    </div>
    <div id="ac-monitor-diag">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Cargando diagnóstico...', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<!-- task-7: last completed products import (read-only) -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Último import de productos', 'alegra-connector'); ?></h2>
    </div>
    <div id="ac-monitor-last-import">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Sin datos todavía.', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<!-- Active processes -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Procesos Activos', 'alegra-connector'); ?></h2>
        <span class="ac-badge" id="ac-monitor-running-count" style="margin-left:auto;">0</span>
    </div>
    <div id="ac-monitor-running-list">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('No hay procesos activos.', 'alegra-connector'); ?></p>
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
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('No hay tareas cron programadas.', 'alegra-connector'); ?></p>
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
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Sin historial reciente.', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<!-- Recent webhooks (D10): partitioned so they never evict cron/manual runs -->
<div class="ac-card" style="margin-top:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Webhooks recientes', 'alegra-connector'); ?></h2>
    </div>
    <div id="ac-monitor-webhooks-list">
        <div class="ac-empty-state">
            <p style="color:var(--ac-text-muted);"><?php esc_html_e('Sin historial reciente.', 'alegra-connector'); ?></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</div>

<script>
(function($) {
    'use strict';

    var pollInterval = null;
    var monitorErrorShown = false;
    var monitorNonce = <?php echo json_encode($monitor_nonce); ?>;
    // Populated on DOM ready: alegraConnector is printed with the footer scripts.
    var S = {};

    function statusBadge(status) {
        var cls = 'neutral';
        // Escape the fallback too: an unknown status is server data.
        var label = escapeHtml(status);
        switch (status) {
            case 'running':   cls = 'warning'; label = S.statusRunning;   break;
            case 'completed': cls = 'success'; label = S.statusCompleted; break;
            case 'failed':    cls = 'danger';  label = S.statusFailed;    break;
            case 'cancelled': cls = 'neutral'; label = S.statusCancelled; break;
            case 'killed':    cls = 'danger';  label = S.statusKilled;    break;
            case 'stale':     cls = 'neutral'; label = S.statusAbandoned; break;
        }
        return '<span class="ac-badge ' + cls + '">' + label + '</span>';
    }

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    // admin-monitor.php had no fmt: the one in admin.js lives private inside its
    // IIFE and is not exposed. Same pattern as templates/admin-import.php.
    function fmt(tpl, arg) {
        return String(tpl == null ? '' : tpl).replace('%s', arg);
    }

    // Map a raw run_type to its origin label (REQ-MON-04).
    function runTypeLabel(type) {
        var t = String(type || '');
        if (t.indexOf('cron') === 0) return S.originCron;
        if (t === 'manual_import') return S.originManual;
        if (t === 'chunked_import') return S.originChunked;
        if (t.indexOf('webhook') === 0) return S.originWebhook;
        return t; // old/unknown rows: raw value
    }

    function renderRunning(running) {
        if (!running || running.length === 0) {
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' + escapeHtml(S.noActiveProcesses) + '</p></div>';
        }
        var html = '';
        for (var i = 0; i < running.length; i++) {
            var r = running[i];
            var pct = r.total_items > 0 ? Math.round((r.items_done / r.total_items) * 100) : 0;
            html += '<div class="ac-running-item" data-run-id="' + r.id + '" style="padding:12px;border-bottom:1px solid var(--ac-border-light);">';
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
            html += statusBadge('running');
            html += '<strong>' + escapeHtml(runTypeLabel(r.type)) + '</strong>';
            html += '<span style="color:var(--ac-text-muted);font-size:12px;margin-left:auto;">' + escapeHtml(r.started_at) + '</span>';
            html += '</div>';
            html += '<div style="margin-bottom:6px;font-size:13px;color:var(--ac-text);">' + escapeHtml(r.message || '') + '</div>';
            if (r.total_items > 0) {
                html += '<div style="background:var(--ac-surface-alt);height:6px;border-radius:3px;overflow:hidden;margin-bottom:6px;">';
                html += '<div style="background:var(--ac-primary);height:100%;width:' + pct + '%;"></div></div>';
                html += '<div style="font-size:11px;color:var(--ac-text-muted);">' + r.items_done + ' / ' + r.total_items + ' (' + pct + '%)</div>';
            }
            html += '<div style="font-size:11px;color:var(--ac-text-muted);margin-top:6px;">';
            if (r.memory_mb != null) html += '<span style="margin-right:12px;">' + escapeHtml(S.memory) + ': ' + r.memory_mb.toFixed(1) + ' MB</span>';
            if (r.cpu_load != null) html += '<span style="margin-right:12px;">CPU: ' + r.cpu_load.toFixed(2) + '</span>';
            html += '</div>';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-danger" data-stop-run="' + r.id + '" style="margin-top:8px;">' + escapeHtml(S.stopProcess) + '</button>';
            html += '</div>';
        }
        return html;
    }

    function renderCron(cron, syncMethod) {
        if (!cron || cron.length === 0) {
            if (syncMethod === 'real-time' || syncMethod === 'disabled') {
                return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' +
                    escapeHtml(fmt(S.cronDisabled, syncMethod)) + '</p></div>';
            }
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' + escapeHtml(S.noCronTasks) + '</p></div>';
        }
        var html = '<table class="widefat striped"><thead><tr>' +
            '<th>' + escapeHtml(S.thHook) + '</th><th>' + escapeHtml(S.thNextRun) + '</th><th>' + escapeHtml(S.thFrequency) + '</th>' +
            '<th style="width:240px;">' + escapeHtml(S.thActions) + '</th></tr></thead><tbody>';
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
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-primary ac-cron-run" data-hook="' + hookAttr + '" title="' + escapeHtml(S.runNow) + '">' +
                    '<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> ' + escapeHtml(S.run) +
                    '</button> ';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-cron-skip" data-hook="' + hookAttr + '" data-timestamp="' + tsAttr + '" title="' + escapeHtml(S.skipNext) + '">' +
                    '<span class="dashicons dashicons-controls-skipforward" style="font-size:12px;width:12px;height:12px;"></span>' +
                    '</button> ';
            html += '<button type="button" class="ac-btn ac-btn-xs ac-btn-danger ac-cron-remove" data-hook="' + hookAttr + '" title="' + escapeHtml(S.removeAll) + '">' +
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
            return '<div class="ac-empty-state"><p style="color:var(--ac-text-muted);">' + escapeHtml(S.noRecentHistory) + '</p></div>';
        }
        var html = '<table class="widefat striped"><thead><tr><th>#</th><th>' + escapeHtml(S.thType) + '</th><th>' + escapeHtml(S.thStatus) + '</th><th>' + escapeHtml(S.thStart) + '</th><th>' + escapeHtml(S.thEnd) + '</th><th>' + escapeHtml(S.thItems) + '</th><th>' + escapeHtml(S.thMemory) + '</th><th>' + escapeHtml(S.thError) + '</th></tr></thead><tbody>';
        for (var i = 0; i < recent.length; i++) {
            var r = recent[i];
            html += '<tr>';
            html += '<td>' + r.id + '</td>';
            html += '<td>' + escapeHtml(runTypeLabel(r.type)) + '</td>';
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

    // task-5: read-only import diagnostics. Server values are injected with
    // .text() — never concatenated into HTML.
    function diagRow(label, $value) {
        return $('<div>').css({ display: 'flex', gap: '10px', padding: '5px 0', borderBottom: '1px solid var(--ac-border-light)' })
            .append($('<span>').css({ minWidth: '170px', color: 'var(--ac-text-secondary)', fontSize: '12px' }).text(label))
            .append($('<span>').css({ fontSize: '13px', flex: '1' }).append($value));
    }

    function renderDiag(d) {
        var $wrap = $('<div>').css({ padding: '4px 0' });
        if (!d) {
            return $wrap.append($('<div>').addClass('ac-empty-state').append($('<p>').css('color', 'var(--ac-text-muted)').text(S.diagNoData)));
        }

        // task-7: visible config-honesty warning — "Solo Tiempo Real" + Alegra
        // inventory means stock is never pulled automatically. Message comes
        // from the localize payload; .text() keeps it injection-safe.
        if (d.realtime_inventory_warning) {
            $wrap.append(
                $('<div>').addClass('ac-notice').addClass('error')
                    .css({ padding: '8px 10px', margin: '0 0 8px 0', fontWeight: '500' })
                    .text(S.realtimeInventoryWarning)
            );
        }

        $wrap.append(diagRow(S.diagSyncMode, $('<span>').text(d.sync_method_label || d.sync_method || '')));

        var residue = d.residue || {};
        $wrap.append(diagRow(S.diagImportState, $('<span>').text(residue.label || '')));

        $wrap.append(diagRow(S.diagCursor, $('<span>').text((d.cursor || 0) + ' / ' + (d.import_total || 0))));

        var typeLabels = { products: S.diagTypeProducts, customers: S.diagTypeCustomers, orders: S.diagTypeOrders, categories: S.diagTypeCategories };
        var $locks = $('<div>');
        var locks = d.locks || {};
        for (var key in locks) {
            if (!Object.prototype.hasOwnProperty.call(locks, key)) continue;
            var lock = locks[key] || {};
            var line = (typeLabels[key] || key) + ': ' + (lock.label || '');
            if (lock.held && lock.expires_in != null) {
                line += ' (' + fmt(S.diagExpires, lock.expires_in) + ')';
            }
            $locks.append($('<div>').css({ padding: '2px 0' }).text(line));
        }
        $wrap.append(diagRow(S.diagLocks, $locks));

        var ks = d.kill_switch || {};
        var ksTxt = (ks.label || '') + (ks.active && ks.reason ? ' (' + ks.reason + ')' : '');
        $wrap.append(diagRow(S.diagKillSwitch, $('<span>').text(ksTxt)));

        return $wrap;
    }

    // task-7: read-only "Último import de productos" card. Server values go
    // through .text()/diagRow — never concatenated into HTML.
    function renderLastImport(s) {
        if (!s) {
            return $('<div>').addClass('ac-empty-state').append(
                $('<p>').css('color', 'var(--ac-text-muted)').text(S.lastImportNoData)
            );
        }
        var img = s.images || {};
        var $wrap = $('<div>').css({ padding: '4px 0' });
        var fecha = s.at ? new Date(s.at * 1000).toLocaleString() : '';
        $wrap.append(diagRow(S.lastImportDate, $('<span>').text(fecha)));
        $wrap.append(diagRow(S.lastImportCreated, $('<span>').text(s.imported || 0)));
        $wrap.append(diagRow(S.lastImportUpdated, $('<span>').text(s.updated || 0)));
        $wrap.append(diagRow(S.lastImportSkipped, $('<span>').text(s.skipped || 0)));
        $wrap.append(diagRow(S.lastImportImages, $('<span>').text((img.ok || 0) + ' / ' + (img.deferred || 0) + ' / ' + (img.failed || 0))));
        $wrap.append(diagRow(S.lastImportErrors, $('<span>').text(s.errors || 0)));
        $wrap.append(diagRow(S.lastImportTotal, $('<span>').text(s.total_items || 0)));
        return $wrap;
    }

    function refresh() {
        $.ajax({
            url: alegraConnector.ajaxUrl,
            type: 'POST',
            data: { action: 'alegra_monitor_status', _ajax_nonce: monitorNonce },
            success: function(r) {
                if (!r || !r.success) return;
                monitorErrorShown = false;
                var d = r.data;
                $('#ac-monitor-running-list').html(renderRunning(d.running));
                $('#ac-monitor-running-count').text(d.running.length);
                $('#ac-monitor-cron-list').html(renderCron(d.cron, d.sync_method));
                $('#ac-monitor-recent-list').html(renderRecent(d.recent));
                $('#ac-monitor-webhooks-list').html(renderRecent(d.recent_webhooks));
                $('#ac-monitor-diag').empty().append(renderDiag(d.diagnostics));
                $('#ac-monitor-last-import').empty().append(renderLastImport(d.last_import_summary));
            },
            error: function() {
                // Show the failure once per streak so a flaky request does not
                // spam a notice every 5 seconds.
                if (!monitorErrorShown) {
                    monitorErrorShown = true;
                    showNotice(S.monitorError, 'error');
                }
            }
        });
    }

    function setupHandlers() {
        $(document).on('click', '[data-stop-run]', function() {
            if (!confirm(S.confirmStopProcess)) return;
            var runId = $(this).data('stop-run');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_kill_run', _ajax_nonce: monitorNonce, run_id: runId },
                success: function(r) {
                    if (r.success) showNotice(r.data.message, 'success');
                    else showNotice(r.data.message || S.error, 'error');
                    refresh();
                }
            });
        });

        $(document).on('click', '#alegra-emergency-stop', function() {
            if (!confirm(S.confirmEmergencyStop)) return;
            var $btn = $(this).prop('disabled', true).text(S.stopping);
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_kill_all', _ajax_nonce: monitorNonce },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message, 'warning');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showNotice(r.data.message || S.error, 'error');
                        $btn.prop('disabled', false).text(S.stopAll);
                    }
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false).text(S.stopAll);
                }
            });
        });

        // task-5: release import locks from the diagnostics panel.
        $(document).on('click', '#alegra-diag-release', function() {
            if (!confirm(S.diagReleaseConfirm)) return;
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_release_import', _ajax_nonce: monitorNonce },
                success: function(r) {
                    if (r.success) showNotice(r.data.message || S.releaseDone, 'success');
                    else showNotice(r.data.message || S.error, 'error');
                    $btn.prop('disabled', false);
                    refresh();
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false);
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
                        showNotice(r.data.message || S.hookExecuted, 'success');
                        setTimeout(refresh, 1500);
                    } else {
                        showNotice(r.data.message || S.error, 'error');
                    }
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> ' + escapeHtml(S.run));
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size:12px;width:12px;height:12px;"></span> ' + escapeHtml(S.run));
                }
            });
        });

        // Skip cron next run
        $(document).on('click', '.ac-cron-skip', function() {
            if (!confirm(S.confirmSkipCron)) return;
            var $btn = $(this).prop('disabled', true);
            var hook = $(this).data('hook');
            var timestamp = $(this).data('timestamp');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_skip_cron_next', _ajax_nonce: monitorNonce, hook: hook, timestamp: timestamp },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message || S.taskSkipped, 'success');
                        setTimeout(refresh, 1000);
                    } else {
                        showNotice(r.data.message || S.error, 'error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false);
                }
            });
        });

        // Remove cron entirely
        $(document).on('click', '.ac-cron-remove', function() {
            if (!confirm(S.confirmRemoveCron)) return;
            var $btn = $(this).prop('disabled', true);
            var hook = $(this).data('hook');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_unschedule_cron', _ajax_nonce: monitorNonce, hook: hook },
                success: function(r) {
                    if (r.success) {
                        showNotice(r.data.message || S.taskRemoved, 'warning');
                        setTimeout(refresh, 1000);
                    } else {
                        showNotice(r.data.message || S.error, 'error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    function showNotice(msg, type) {
        type = type || 'info';
        // Build the node and inject the message with .text() — never concatenate
        // a server/Alegra-derived string into HTML.
        var $n = $('<div>').addClass('ac-notice').addClass(type).css({display: 'none', margin: '8px 0 14px 0'}).text(msg);
        $('.alegra-connector-wrap').first().prepend($n);
        $n.slideDown(200);
        setTimeout(function() { $n.slideUp(300, function() { $(this).remove(); }); }, 6000);
    }

    $(document).ready(function() {
        S = (window.alegraConnector && window.alegraConnector.strings) || {};
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
