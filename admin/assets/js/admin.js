/**
 * Alegra Connector Admin JavaScript
 */
(function($) {
    'use strict';

    function showNotice(msg, type) {
        type = type || 'info';
        var $n = $('<div class="ac-notice '+type+'" style="display:none;margin:8px 0 14px 0;">'+msg+'</div>');
        $('.alegra-connector-wrap').first().prepend($n);
        $n.slideDown(200);
        setTimeout(function(){ $n.slideUp(300, function(){ $(this).remove(); }); }, 6000);
    }

    function safeMsg(response, fallback) {
        return (response && response.data && response.data.message) || fallback || 'Error desconocido';
    }

    var AlegraConnector = {
        init: function() {
            this.initTabs();
            this.initConnectionTest();
            this.initSyncNow();
            this.initLogManagement();
            this.initTokenVisibility();
            this.initWebhookManagement();
            this.initSingleSync();
            this.initRecordPayment();
            this.initCleanupDuplicateImages();
        },

        initTabs: function() {
            $('.ac-settings-tab').on('click', function() {
                var tab = $(this).data('tab');
                $('.ac-settings-tab').removeClass('active');
                $(this).addClass('active');
                $('.ac-tab-content').hide();
                $('#tab-' + tab).show();
            });
        },

        initConnectionTest: function() {
            $('#alegra-test-connection').on('click', function() {
                var $btn = $(this);
                var $status = $('#alegra-connection-status');
                var $result = $('#alegra-connection-result');
                var email = $('#alegra_connector_email').val();
                var token = $('#alegra_connector_token').val();
                if (!email || !token) { showNotice('Email y token son requeridos', 'warning'); return; }
                $btn.prop('disabled', true).text(alegraConnector.strings.testing);
                $status.html('');
                $result.hide();
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST', timeout: 15000,
                    data: { action: 'alegra_test_connection', _ajax_nonce: alegraConnector.nonce, email: email, token: token },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Probar Conexion');
                        if (r.success) {
                            var diag = r.data.diagnostics ? r.data.diagnostics.join(', ') : '';
                            $status.html('<span style="color:var(--ac-success);font-weight:500;">&#10003; Conectado - ' + (r.data.company || 'OK') + '</span>');
                            $result.show().removeClass('error').addClass('success').html(
                                '<p style="margin:0;color:var(--ac-success);"><strong>Empresa:</strong> ' + (r.data.company || 'N/A') + ' | <strong>Pais:</strong> ' + (r.data.country || 'N/A') + '</p>' +
                                '<p style="margin:4px 0 0 0;font-size:11px;color:var(--ac-text-secondary);">Endpoints: ' + diag + '</p>'
                            );
                            showNotice('Conectado a ' + (r.data.company || 'Alegra') + (diag ? ' - ' + diag : ''), 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            var msg = r.data.message || 'Error';
                            var http = r.data.http_code ? ' (HTTP ' + r.data.http_code + ')' : '';
                            $status.html('<span style="color:var(--ac-danger);font-weight:500;">&#10007; ' + msg + http + '</span>');
                            showNotice('Error: ' + msg + http, 'error');
                            $result.show().removeClass('success').addClass('error').html('<p style="margin:0;color:var(--ac-danger);">' + msg + http + '</p>');
                        }
                    },
                    error: function(xhr, status) {
                        $btn.prop('disabled', false).text('Probar Conexion');
                        var msg = status === 'timeout' ? 'Timeout: el servidor de Alegra no responde' : 'Error de red (' + status + ')';
                        $status.html('<span style="color:var(--ac-danger);">&#10007; ' + msg + '</span>');
                        showNotice(msg + '. Verifica la URL base y tu conexion.', 'error');
                    }
                });
            });
        },

        initSyncNow: function() {
            $('#alegra-sync-now-btn, .alegra-quick-sync').on('click', function() {
                var types = $(this).data('type') || 'all';
                var $btn = $(this).prop('disabled', true);

                doSync();

                function doSync() {
                var $modal = $('#alegra-sync-progress-modal');
                var $label = $modal.find('.sync-progress-label');
                var $fill = $modal.find('.sync-progress-fill');
                var $counters = $modal.find('.sync-counters');
                var $elapsed = $('#sync-elapsed');
                var startTime = Date.now();
                var cancelled = false;
                
                $label.text(types === 'all' ? 'Trayendo datos...' : 'Obteniendo conteo de Alegra...');
                $fill.css('width', '2%');
                $counters.text('');
                $elapsed.text('');
                cancelled = false;
                $modal.show();
                $('body').addClass('ac-modal-open');
                
                var currentRequest = null;

                // Cancel button - abort current request and cleanup server state
                $modal.find('.sync-cancel-btn').off('click').on('click', function() {
                    cancelled = true;
                    if (currentRequest) {
                        currentRequest.abort();
                        currentRequest = null;
                    }
                    // Tell server to stop too by deleting the batch state
                    $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_cancel_sync', _ajax_nonce: alegraConnector.nonce },
                        error: function() {}
                    });
                    cleanup();
                    $btn.prop('disabled', false).text('Sincronizar Todo');
                    showNotice('Sincronizacion cancelada', 'warning');
                });
                
                // Timer
                var timerInterval = setInterval(function() {
                    var elapsed = Math.floor((Date.now() - startTime) / 1000);
                    var m = Math.floor(elapsed / 60), s = elapsed % 60;
                    $elapsed.text('Tiempo: ' + m + 'm ' + s + 's');
                }, 1000);
                
                var cleanup = function() {
                    clearInterval(timerInterval);
                    $modal.hide();
                    $('body').removeClass('ac-modal-open');
                };
                
                var processNext = function() { return; }; // Placeholder
                
                var doAllSync = function() {
                    $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_sync_now', _ajax_nonce: alegraConnector.nonce, sync_type: 'all' },
                        success: function(r) {
                            cleanup();
                            if(r.success) { showNotice(r.data.message || 'Completado','success'); setTimeout(function(){location.reload();},1500); }
                            else { showNotice(safeMsg(r,'Error'),'error'); $btn.prop('disabled',false).text('Reintentar'); }
                        },
                        error: function() { cleanup(); showNotice('Error','error'); $btn.prop('disabled',false).text('Reintentar'); }
                    });
                };
                
                if (types === 'all') { doAllSync(); return; }
                
                // Chunked sync for specific types
                var phase = 'init'; // init | fetching | importing | done
                var retries = 0;
                var maxRetries = 2;
                
                currentRequest = $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_start', _ajax_nonce: alegraConnector.nonce, sync_type: types },
                    success: function(r) {
                        currentRequest = null;
                        if (!r.success || cancelled) { cleanup(); return; }
                        var d = r.data || {};
                        $label.text('[Fase 1] Total: ' + (d.total_items || '?') + ' items en ' + (d.total_pages || '?') + ' paginas — Pagina 1');
                        $counters.text('Procesando...');
                        processPage(1);
                    },
                    error: function() { cleanup(); showNotice('Error al iniciar','error'); $btn.prop('disabled',false).text('Reintentar'); }
                });
                
                var processPage = function(page) {
                    if (cancelled) { cleanup(); return; }
                    
                    currentRequest = $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_sync_page', _ajax_nonce: alegraConnector.nonce },
                        success: function(r) {
                            currentRequest = null;
                            retries = 0;
                            if (!r.success || cancelled) { cleanup(); $btn.prop('disabled',false).text('Reintentar'); return; }
                            var d = r.data;
                            var pct = d.percent || Math.min(95, 5 + (page * 2));
                            $fill.css('width', pct + '%');
                            $label.text('[Fase 1] ' + d.message);
                            $counters.text(d.imported + ' importados | ' + d.updated + ' actualizados | ' + (d.skipped || 0) + ' omitidos | ' + d.errors + ' errores');
                            
                            if (d.done) {
                                $fill.css('width', '100%');
                                var hasErrors = d.errors > 0;
                                $label.text(hasErrors ? '[Fase 2] Completado con errores' : '[Fase 2] Completado');
                                $counters.text(
                                    'Total: ' + d.processed + ' items | ' +
                                    d.imported + ' nuevos, ' + d.updated + ' actualizados, ' +
                                    (d.skipped || 0) + ' omitidos' +
                                    (hasErrors ? ', ' + d.errors + ' errores' : '')
                                ).css('color', hasErrors ? 'var(--ac-warning)' : '');
                                setTimeout(function() {
                                    cleanup();
                                    if (hasErrors) {
                                        showNotice(
                                            'Importacion completada con errores: ' + d.processed + ' items procesados. ' +
                                            d.imported + ' importados, ' + d.updated + ' actualizados, ' +
                                            d.errors + ' errores. Revisa el log para mas detalle.',
                                            'warning'
                                        );
                                    } else {
                                        showNotice(
                                            d.processed + ' items procesados. ' +
                                            d.imported + ' importados, ' + d.updated + ' actualizados.',
                                            'success'
                                        );
                                    }
                                    setTimeout(function(){ location.reload(); }, 2000);
                                }, 1500);
                            } else {
                                processPage(page + 1);
                            }
                        },
                        error: function() {
                            retries++;
                            if (retries <= maxRetries) {
                                $counters.text('Reintentando (' + retries + '/' + maxRetries + ')...');
                                setTimeout(function() { processPage(page); }, 2000);
                            } else {
                                cleanup(); $btn.prop('disabled',false).text('Reintentar');
                            }
                        }
                    });
                };
                } // end doSync
            });
            $('#alegra-sync-start').on('click', function() {
                var syncTypes = [];
                $('input[name="sync_products"]:checked').length && syncTypes.push('products');
                $('input[name="sync_customers"]:checked').length && syncTypes.push('customers');
                $('input[name="sync_orders"]:checked').length && syncTypes.push('orders');
                $('input[name="sync_categories"]:checked').length && syncTypes.push('categories');
                if (!syncTypes.length) { showNotice('Selecciona al menos un tipo','warning'); return; }
                var $modal = $('#alegra-sync-modal');
                var $status = $modal.find('.alegra-sync-status').text(alegraConnector.strings.syncing);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_now', _ajax_nonce: alegraConnector.nonce, sync_type: syncTypes.join(',') },
                    success: function(r) { if(r.success){$status.text(safeMsg(r,'Completado'));setTimeout(function(){location.reload();},1500);} else {showNotice(safeMsg(r,'Error'),'error');} },
                    error: function() { showNotice('Error de conexion','error'); }
                });
            });
            $('#alegra-sync-cancel').on('click', function() { $('#alegra-sync-modal').hide(); });
        },

        initLogManagement: function() {
            $('#alegra-refresh-logs').on('click', function() { location.reload(); });
            $('#alegra-clear-logs').on('click', function() {
                if(!confirm('Eliminar logs antiguos?')) return;
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_clear_logs', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) { if(r.success){showNotice(safeMsg(r,'Logs eliminados'),'success');setTimeout(function(){location.reload();},1000);} }
                });
            });
        },

        initTokenVisibility: function() {
            $('#toggle-token-visibility').on('click', function() {
                var $t = $('#alegra_connector_token');
                if ($t.attr('type') === 'password') { $t.attr('type', 'text'); $(this).text('Ocultar token'); }
                else { $t.attr('type', 'password'); $(this).text('Mostrar token'); }
            });
            $('#toggle-webhook-secret').on('click', function() {
                var $t = $('#alegra_connector_webhook_secret');
                if ($t.attr('type') === 'password') { $t.attr('type', 'text'); $(this).text('Ocultar'); }
                else { $t.attr('type', 'password'); $(this).text('Mostrar'); }
            });
            $('#alegra-disconnect').on('click', function() {
                if (!confirm('Desconectar de Alegra? Deberas volver a probar la conexion.')) return;
                var $btn = $(this).prop('disabled', true).text('Desconectando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_disconnect', _ajax_nonce: alegraConnector.nonce },
                    success: function() { location.reload(); },
                    error: function() { $btn.prop('disabled', false).text('Desconectar'); }
                });
            });
            $('#alegra-check-endpoints').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Verificando...');
                var $status = $('#alegra-endpoints-status');
                $status.html('');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_check_endpoints', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Verificar Endpoints');
                        if (r.success) {
                            var badges = '';
                            $.each(r.data.endpoints || {}, function(k, v) {
                                var cls = v.indexOf('OK') === 0 ? 'success' : (v.indexOf('vacio') !== -1 ? 'warning' : 'danger');
                                badges += '<span class="ac-badge ' + cls + '" style="margin:2px;">' + k + ': ' + v + '</span>';
                            });
                            $status.html(badges);
                        } else {
                            $status.html('<span style="color:var(--ac-danger);">Error</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text('Verificar Endpoints'); $status.html('<span style="color:var(--ac-danger);">Error de red</span>'); }
                });
            });
        },

        initWebhookManagement: function() {
            $('#alegra-register-webhooks').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Registrando...');
                var $status = $('#alegra-webhook-status');
                var secret = $('#alegra_connector_webhook_secret').val();
                if (!secret) { showNotice('Debes configurar un Webhook Secret primero', 'warning'); $btn.prop('disabled', false).text('Registrar webhooks en Alegra'); return; }
                $status.html('');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_register_webhooks', _ajax_nonce: alegraConnector.nonce, webhook_secret: secret },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Registrar webhooks en Alegra');
                        if (r.success) {
                            showNotice(r.data.message || 'Webhooks registrados', 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            showNotice(r.data.message || 'Error', 'error');
                            $status.html('<span style="color:var(--ac-danger);">Error</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text('Registrar webhooks en Alegra'); showNotice('Error de conexion', 'error'); }
                });
            });

            $('#alegra-delete-webhooks').on('click', function() {
                if (!confirm('Eliminar todas las suscripciones de webhooks en Alegra?')) return;
                var $btn = $(this).prop('disabled', true).text('Eliminando...');
                var $status = $('#alegra-webhook-status');
                $status.html('');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_delete_webhooks', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Eliminar webhooks en Alegra');
                        if (r.success) {
                            showNotice(r.data.message || 'Webhooks eliminados', 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            showNotice(r.data.message || 'Error', 'error');
                            $status.html('<span style="color:var(--ac-danger);">Error</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text('Eliminar webhooks en Alegra'); showNotice('Error de conexion', 'error'); }
                });
            });
        },


        initCleanupDuplicateImages: function() {
            $('.alegra-cleanup-duplicate-images').on('click', function() {
                if (!confirm('Esto eliminara todos los attachments de imagen duplicados en productos, basandose en la URL normalizada. Las imagenes que quedaron unicas se conservaran. Continuar?')) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text('Limpiando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_cleanup_duplicate_images', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Limpiar imagenes duplicadas');
                        if (r.success) {
                            showNotice(r.data.message || 'Imagenes duplicadas eliminadas', 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            showNotice(r.data.message || 'Error', 'error');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text('Limpiar imagenes duplicadas'); showNotice('Error de conexion', 'error'); }
                });
            });
        },

        initSingleSync: function() {
            $('.alegra-sync-single').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var origText = $btn.text();
                $btn.html('<span class="dashicons dashicons-update ac-spin" style="font-size:14px;width:14px;height:14px;"></span> Sincronizando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_single', _ajax_nonce: alegraConnector.nonce, entity_type: $btn.data('type'), entity_id: $btn.data('id') },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r,'Error'),'error'); $btn.prop('disabled',false).html(origText); } },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).html(origText); }
                });
            });
        },

        initRecordPayment: function() {
            $('.alegra-record-payment').on('click', function() {
                var $btn = $(this);
                if(!confirm('Registrar pago en Alegra?')) return;
                $btn.prop('disabled', true).text('Registrando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_record_payment', _ajax_nonce: alegraConnector.nonce, order_id: $btn.data('order-id') },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r,'Error'),'error'); $btn.prop('disabled',false).text('Reintentar'); } },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).text('Reintentar'); }
                });
            });
        },

        initBulkActions: function() {
            $('.alegra-select-all').on('click', function() {
                $('.alegra-bulk-check').prop('checked', this.checked);
                updateSelectedCount();
            });
            $('.alegra-bulk-check').on('change', function() {
                updateSelectedCount();
            });
            function updateSelectedCount() {
                var count = $('.alegra-bulk-check:checked').length;
                $('.ac-selected-count').text(count > 0 ? count + ' ' + (count === 1 ? 'seleccionado' : 'seleccionados') : '');
            }
            
            // Push selected WC → Alegra
            $('.alegra-bulk-sync').on('click', function() {
                var type = $(this).data('type');
                var ids = $('.alegra-bulk-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { showNotice('Selecciona al menos un elemento','warning'); return; }
                var $btn = $(this).prop('disabled', true).text('Enviando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_bulk_sync', _ajax_nonce: alegraConnector.nonce, entity_type: type, ids: ids },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r,'Error'),'error'); $btn.prop('disabled',false).text('Reintentar'); } },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).text('Reintentar'); }
                });
            });

            // Pull selected Alegra → WC
            $('.alegra-bulk-import').on('click', function() {
                var type = $(this).data('type');
                var ids = $('.alegra-bulk-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { showNotice('Selecciona al menos un elemento','warning'); return; }
                var $btn = $(this).prop('disabled', true).text('Trayendo...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_bulk_import', _ajax_nonce: alegraConnector.nonce, entity_type: type, ids: ids },
                    success: function(r) { if(r.success){showNotice(safeMsg(r,'Completado'),'success');setTimeout(function(){location.reload();},1500);} else {showNotice(safeMsg(r,'Error'),'error');$btn.prop('disabled',false).text('Reintentar');} },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).text('Reintentar'); }
                });
            });

            // Per-item pull Alegra → WC
            $('.alegra-import-single').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var origText = $btn.text();
                $btn.html('<span class="dashicons dashicons-update ac-spin" style="font-size:14px;width:14px;height:14px;"></span> Trayendo...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_import_single', _ajax_nonce: alegraConnector.nonce, entity_type: $btn.data('type'), entity_id: $btn.data('id') },
                    success: function(r) { if(r.success){showNotice(safeMsg(r,'Actualizado'),'success');setTimeout(function(){location.reload();},1000);} else {showNotice(safeMsg(r,'Error'),'error');$btn.prop('disabled',false).html(origText);} },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).html(origText); }
                });
            });

            // Import from Alegra buttons (also used on Products/Customers list pages)
            $('.alegra-import-from-api').on('click', function() {
                var $btn = $(this), type = $btn.data('type');
                if (!confirm('Traer ' + type + ' desde Alegra? Esto puede crear o actualizar registros en WooCommerce.')) return;
                $btn.prop('disabled', true).text('Descargando...');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_import_from_api', _ajax_nonce: alegraConnector.nonce, import_type: type },
                    success: function(r) { if(r.success){showNotice(safeMsg(r,'Importado'),'success');setTimeout(function(){location.reload();},1500);} else {showNotice(safeMsg(r,'Error'),'error');$btn.prop('disabled',false).text('Reintentar');} },
                    error: function() { showNotice('Error de conexion','error'); $btn.prop('disabled',false).text('Reintentar'); }
                });
            });

        // Batch-sync pending WC orders to Alegra invoices (chunked)
        $('.alegra-sync-pending-orders').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var $modal = $('#alegra-sync-progress-modal');
                var $label = $modal.find('.sync-progress-label');
                var $fill = $modal.find('.sync-progress-fill');
                var $counters = $modal.find('.sync-counters');
                var $elapsed = $('#sync-elapsed');
                var startTime = Date.now();
                var cancelled = false;

                $label.text('Contando pedidos pendientes...');
                $fill.css('width', '5%');
                $counters.text('');
                $elapsed.text('');
                $modal.find('.sync-cancel-btn').show();
                $modal.show();
                $('body').addClass('ac-modal-open');

                var timerInterval = setInterval(function() {
                    var e = Math.floor((Date.now() - startTime) / 1000);
                    $elapsed.text('Tiempo: ' + Math.floor(e / 60) + 'm ' + (e % 60) + 's');
                }, 1000);

                var cleanup = function() { clearInterval(timerInterval); $modal.hide(); $('body').removeClass('ac-modal-open'); };

                $modal.find('.sync-cancel-btn').off('click').on('click', function() {
                    cancelled = true;
                    cleanup();
                    $btn.prop('disabled', false).text('Facturar pendientes');
                    showNotice('Cancelado', 'warning');
                });

                // Phase 1: count pending
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_pending_start', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        if (!r.success || cancelled) { cleanup(); $btn.prop('disabled', false).text('Facturar pendientes'); return; }
                        var total = r.data.total;
                        if (total === 0) {
                            cleanup(); $btn.prop('disabled', false).text('Facturar pendientes');
                            showNotice('No hay pedidos pendientes por facturar', 'info');
                            return;
                        }
                        $label.text('Facturando ' + total + ' pedidos pendientes...');
                        processPage();
                    },
                    error: function() { cleanup(); $btn.prop('disabled', false).text('Facturar pendientes'); showNotice('Error', 'error'); }
                });

                function processPage() {
                    if (cancelled) { cleanup(); return; }
                    $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_sync_pending_page', _ajax_nonce: alegraConnector.nonce },
                        success: function(r) {
                            if (!r.success || cancelled) { cleanup(); $btn.prop('disabled', false).text('Facturar pendientes'); return; }
                            var d = r.data;
                            $fill.css('width', d.percent + '%');
                            $label.text(d.message);
                            $counters.text(d.synced + ' facturados | ' + d.errors + ' errores');
                            if (d.done) {
                                $fill.css('width', '100%');
                                setTimeout(function() {
                                    cleanup();
                                    showNotice(d.synced + ' facturas creadas. ' + d.errors + ' errores.', 'success');
                                    setTimeout(function(){ location.reload(); }, 1500);
                                }, 1000);
                            } else {
                                processPage();
                            }
                        },
                        error: function() { cleanup(); $btn.prop('disabled', false).text('Facturar pendientes'); }
                    });
                }
            });
        }
    };

    $(document).ready(function() {
        AlegraConnector.init();
        AlegraConnector.initBulkActions();
    });
})(jQuery);
