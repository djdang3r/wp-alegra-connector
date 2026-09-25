/**
 * Alegra Connector Admin JavaScript
 *
 * AC-35d: every user-facing string lives in the wp_localize_script payload
 * (alegraConnector.strings, built in Admin_Dashboard::get_script_strings()).
 * Nothing here is hardcoded, so the strings are translatable.
 */
(function($) {
    'use strict';

    var S = (window.alegraConnector && window.alegraConnector.strings) || {};

    /**
     * Minimal printf-style formatter: supports %s, %d, %1$s, %2$d.
     */
    function fmt(tpl, ...args) {
        var auto = 0;
        return String(tpl == null ? '' : tpl).replace(/%(\d+\$)?[sd]/g, function(match, pos) {
            var idx = pos ? (parseInt(pos, 10) - 1) : auto++;
            var value = args[idx];
            return value == null ? '' : String(value);
        });
    }

    function showNotice(msg, type) {
        type = type || 'info';
        // Build the node and inject the message with .text() — never concatenate
        // a server/Alegra-derived string into HTML.
        var $n = $('<div>').addClass('ac-notice').addClass(type).css({display:'none',margin:'8px 0 14px 0'}).text(msg);
        $('.alegra-connector-wrap').first().prepend($n);
        $n.slideDown(200);
        setTimeout(function(){ $n.slideUp(300, function(){ $(this).remove(); }); }, 6000);
    }

    function safeMsg(response, fallback) {
        return (response && response.data && response.data.message) || fallback || S.unknownError;
    }

    var AlegraConnector = {
        // Filters chosen in the Products import modal. Read by initSyncNow when
        // it calls alegra_sync_start. Empty = import everything.
        pendingFilters: {},
        // D4: set by the import modal (run()) and sent by alegra_sync_start.
        pendingFromZero: false,
        pendingRecreateManual: false,

        init: function() {
            this.initTabs();
            this.initConnectionTest();
            this.initSyncNow();
            this.initImportFilters();
            this.initLogManagement();
            this.initTokenVisibility();
            this.initWebhookManagement();
            this.initSingleSync();
            this.initRecordPayment();
            this.initOpenInvoice();
            this.initEmitCreditNote();
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
                if (!email || !token) { showNotice(S.emailTokenRequired, 'warning'); return; }
                $btn.prop('disabled', true).text(S.testing);
                $status.html('');
                $result.hide();
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST', timeout: 15000,
                    data: { action: 'alegra_test_connection', _ajax_nonce: alegraConnector.nonce, email: email, token: token },
                    success: function(r) {
                        $btn.prop('disabled', false).text(S.testConnection);
                        if (r.success) {
                            var diag = r.data.diagnostics ? r.data.diagnostics.join(', ') : '';
                            // Server-derived strings (company/country/diagnostics) go
                            // through .text()/DOM nodes, never string-concatenated HTML.
                            $status.empty().append(
                                $('<span>').css({color:'var(--ac-success)',fontWeight:'500'}).text('\u2713 ' + S.connected + ' - ' + (r.data.company || S.ok))
                            );
                            $result.show().removeClass('error').addClass('success').empty();
                            $result.append(
                                $('<p>').css({margin:0,color:'var(--ac-success)'})
                                    .append($('<strong>').text(S.company)).append(document.createTextNode(' ' + (r.data.company || 'N/A') + ' | '))
                                    .append($('<strong>').text(S.country)).append(document.createTextNode(' ' + (r.data.country || 'N/A')))
                            );
                            $result.append(
                                $('<p>').css({margin:'4px 0 0 0',fontSize:'11px',color:'var(--ac-text-secondary)'}).text(S.endpoints + ' ' + diag)
                            );
                            showNotice(fmt(S.connectedTo, (r.data.company || 'Alegra')) + (diag ? ' - ' + diag : ''), 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            var msg = r.data.message || S.error;
                            var http = r.data.http_code ? ' (HTTP ' + r.data.http_code + ')' : '';
                            $status.empty().append(
                                $('<span>').css({color:'var(--ac-danger)',fontWeight:'500'}).text('\u2717 ' + msg + http)
                            );
                            showNotice(S.error + ': ' + msg + http, 'error');
                            $result.show().removeClass('success').addClass('error').empty();
                            $result.append($('<p>').css({margin:0,color:'var(--ac-danger)'}).text(msg + http));
                        }
                    },
                    error: function(xhr, status) {
                        $btn.prop('disabled', false).text(S.testConnection);
                        var msg = status === 'timeout' ? S.timeout : fmt(S.networkError, status);
                        $status.html('<span style="color:var(--ac-danger);">&#10007; ' + msg + '</span>');
                        showNotice(msg + '. ' + S.checkUrl, 'error');
                    }
                });
            });
        },

        initSyncNow: function() {
            $('#alegra-sync-now-btn, .alegra-quick-sync').on('click', function() {
                // The Products-page button opens the filter modal first. Once the
                // modal applies, it re-triggers this click with filter-confirmed
                // set, so the sync proceeds through the normal path.
                if ($(this).data('requires-filter') && !$(this).data('filter-confirmed')) {
                    if ($(this).data('from-zero') && !confirm(S.confirmReimport)) {
                        return; // cancelar no toca nada (ni cursor ni import)
                    }
                    AlegraConnector.openImportFilterModal($(this));
                    return;
                }
                $(this).removeData('filter-confirmed');

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

                $label.text(types === 'all' ? S.fetchingData : S.gettingCount);
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
                    $btn.prop('disabled', false).text(S.syncAll);
                    showNotice(S.syncCancelled, 'warning');
                });

                // Timer
                var timerInterval = setInterval(function() {
                    var elapsed = Math.floor((Date.now() - startTime) / 1000);
                    var m = Math.floor(elapsed / 60), s = elapsed % 60;
                    $elapsed.text(fmt(S.elapsed, m, s));
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
                            if(r.success) { showNotice(r.data.message || S.completed,'success'); setTimeout(function(){location.reload();},1500); }
                            else { showNotice(safeMsg(r, S.error),'error'); $btn.prop('disabled',false).text(S.retry); }
                        },
                        error: function() { cleanup(); showNotice(S.error,'error'); $btn.prop('disabled',false).text(S.retry); }
                    });
                };

                if (types === 'all') { doAllSync(); return; }

                // Chunked sync for specific types
                var phase = 'init'; // init | fetching | importing | done
                var retries = 0;
                var maxRetries = 2;

                currentRequest = $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: {
                        action: 'alegra_sync_start', _ajax_nonce: alegraConnector.nonce, sync_type: types,
                        filters: JSON.stringify(AlegraConnector.pendingFilters || {}),
                        from_zero: AlegraConnector.pendingFromZero ? 1 : 0,
                        recreate_manual: AlegraConnector.pendingRecreateManual ? 1 : 0
                    },
                    success: function(r) {
                        currentRequest = null;
                        if (cancelled) { cleanup(); return; }        // el botón Cancelar ya avisó
                        if (!r.success) {
                            cleanup();
                            showNotice(safeMsg(r, S.startError), 'error');
                            $btn.prop('disabled', false).text(S.retry);   // hoy quedaba deshabilitado
                            return;
                        }
                        var d = r.data || {};
                        $label.text(fmt(S.phase1Total, (d.total_items || '?'), (d.total_pages || '?')));
                        $counters.text(d.resuming ? fmt(S.resumingFrom, (d.start || 0)) : S.processing);
                        processPage();
                    },
                    error: function() { cleanup(); showNotice(S.startError,'error'); $btn.prop('disabled',false).text(S.retry); }
                });

                var processPage = function() {
                    if (cancelled) { cleanup(); return; }

                    currentRequest = $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_sync_page', _ajax_nonce: alegraConnector.nonce },
                        success: function(r) {
                            currentRequest = null;
                            retries = 0;
                            if (cancelled) { cleanup(); return; }
                            if (!r.success) {
                                cleanup();
                                showNotice(safeMsg(r, S.error), 'error');
                                $btn.prop('disabled', false).text(S.retry);
                                return;
                            }
                            var d = r.data;
                            $fill.css('width', (d.percent || 0) + '%');
                            $label.text(fmt(S.phase1, d.message));
                            $counters.text(d.imported + ' ' + S.importedLabel + ' | ' + d.updated + ' ' + S.updatedLabel + ' | ' + (d.skipped || 0) + ' ' + S.skippedLabel + ' | ' + d.errors + ' ' + S.errorsLabel);

                            if (d.paused) {
                                $label.text(S.pausedResuming);
                                processPage();                       // el servidor es dueño del cursor
                            } else if (d.done) {
                                $fill.css('width', '100%');
                                var hasErrors = d.errors > 0;
                                $label.text(hasErrors ? S.phase2CompletedErrors : S.phase2Completed);
                                $counters.text(
                                    fmt(S.syncSummary, d.processed, d.imported, d.updated, (d.skipped || 0)) +
                                    (hasErrors ? fmt(S.syncSummaryErrors, d.errors) : '')
                                ).css('color', hasErrors ? 'var(--ac-warning)' : '');
                                if (d.images && Number(d.images.failed) > 0) {
                                    showNotice(fmt(S.imagesFailed, d.images.failed, d.images.blocked, d.images.download, d.images.sideload, d.images.deferred), 'warning');
                                }
                                setTimeout(function() {
                                    cleanup();
                                    if (hasErrors) {
                                        showNotice(
                                            fmt(S.syncDoneErrors, d.processed, d.imported, d.updated, d.errors),
                                            'warning'
                                        );
                                    } else {
                                        showNotice(
                                            fmt(S.syncDone, d.processed, d.imported, d.updated),
                                            'success'
                                        );
                                    }
                                    setTimeout(function(){ location.reload(); }, 2000);
                                }, 1500);
                            } else {
                                processPage();
                            }
                        },
                        error: function() {
                            retries++;
                            if (retries <= maxRetries) {
                                $counters.text(fmt(S.retrying, retries, maxRetries));
                                setTimeout(function() { processPage(); }, 2000);
                            } else {
                                cleanup();
                                showNotice(S.connectionError, 'error');   // rama terminal antes muda (:278)
                                $btn.prop('disabled', false).text(S.retry);
                            }
                        }
                    });
                };
                } // end doSync
            });
        },

        // -------------------------------------------------------------------
        // Product import filters (Products page modal)
        // -------------------------------------------------------------------

        /** Remembered button that opened the modal (re-triggered on apply). */
        filterTriggerBtn: null,

        openImportFilterModal: function($btn) {
            // A fresh open always starts from "no filters".
            AlegraConnector.pendingFilters = {};
            AlegraConnector.filterTriggerBtn = $btn || null;
            $('#ac-filter-type').val('');
            $('#ac-filter-status').val('default');
            $('#ac-filter-inventariable').val('');
            $('#ac-filter-query').val('');
            $('#ac-filter-variant-note').hide();
            var fromZero = !!($btn && $btn.data('from-zero'));
            $('#ac-filter-from-zero-block').toggle(fromZero);
            if (fromZero) { $('#ac-filter-recreate-manual').prop('checked', true); } // default: recrear todo
            $('#alegra-import-filter-modal').show();
            $('body').addClass('ac-modal-open');
            AlegraConnector.loadFilterCategories();
        },

        loadFilterCategories: function() {
            var $sel = $('#ac-filter-category');
            var $note = $('#ac-filter-category-note');
            $sel.prop('disabled', true);
            $note.text(S.importFilterLoading || '...');
            $.ajax({
                url: alegraConnector.ajaxUrl, type: 'POST',
                data: { action: 'alegra_get_item_categories', _ajax_nonce: alegraConnector.nonce },
                success: function(r) {
                    $sel.prop('disabled', false);
                    $sel.find('option:not(:first)').remove();
                    if (r && r.success && r.data && r.data.categories && r.data.categories.length) {
                        var cats = r.data.categories;
                        for (var i = 0; i < cats.length; i++) {
                            $sel.append($('<option>').val(cats[i].id).text(cats[i].name));
                        }
                        $note.text('');
                    } else {
                        $note.text(S.importFilterNoCats || '');
                    }
                },
                error: function() {
                    $sel.prop('disabled', false);
                    $note.text(S.importFilterCatError || '');
                }
            });
        },

        initImportFilters: function() {
            var $modal = $('#alegra-import-filter-modal');
            if (!$modal.length) { return; }

            function closeModal() {
                $modal.hide();
                $('body').removeClass('ac-modal-open');
            }

            function run($btn, filters) {
                AlegraConnector.pendingFilters = filters || {};
                AlegraConnector.pendingFromZero = !!($btn && $btn.data('from-zero'));
                AlegraConnector.pendingRecreateManual = $('#ac-filter-recreate-manual').is(':checked');
                closeModal();
                if ($btn && $btn.length) {
                    $btn.data('filter-confirmed', true).trigger('click');
                }
            }

            function collectFilters() {
                var f = {};
                var cat = $('#ac-filter-category').val();
                if (cat) { f.idItemCategory = cat; }
                var type = $('#ac-filter-type').val();
                if (type) { f.type = type; }
                var status = $('#ac-filter-status').val();
                if (status && status !== 'default') { f.status = status; }
                if ($('#ac-filter-inventariable').val()) { f.inventariable = true; }
                var q = $.trim($('#ac-filter-query').val());
                if (q) { f.query = q; }
                return f;
            }

            $('#ac-filter-type').on('change', function() {
                $('#ac-filter-variant-note').toggle($(this).val() === 'variantParent');
            });

            $('#ac-filter-cancel').on('click', closeModal);
            $modal.on('click', function(e) { if (e.target === this) { closeModal(); } });

            $('#ac-filter-apply').on('click', function() {
                run(AlegraConnector.filterTriggerBtn, collectFilters());
            });

            $('#ac-filter-all').on('click', function(e) {
                e.preventDefault();
                run(AlegraConnector.filterTriggerBtn, {});
            });
        },

        initLogManagement: function() {
            $('#alegra-refresh-logs').on('click', function() { location.reload(); });
            $('#alegra-clear-logs').on('click', function() {
                if(!confirm(S.confirmClearLogs)) return;
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_clear_logs', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) { if(r.success){showNotice(safeMsg(r, S.logsDeleted),'success');setTimeout(function(){location.reload();},1000);} }
                });
            });
        },

        initTokenVisibility: function() {
            $('#toggle-token-visibility').on('click', function() {
                var $t = $('#alegra_connector_token');
                if ($t.attr('type') === 'password') { $t.attr('type', 'text'); $(this).text(S.hideToken); }
                else { $t.attr('type', 'password'); $(this).text(S.showToken); }
            });
            $('#toggle-webhook-secret').on('click', function() {
                var $t = $('#alegra_connector_webhook_secret');
                if ($t.attr('type') === 'password') { $t.attr('type', 'text'); $(this).text(S.hide); }
                else { $t.attr('type', 'password'); $(this).text(S.show); }
            });
            $('#alegra-disconnect').on('click', function() {
                if (!confirm(S.confirmDisconnect)) return;
                var $btn = $(this).prop('disabled', true).text(S.disconnecting);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_disconnect', _ajax_nonce: alegraConnector.nonce },
                    success: function() { location.reload(); },
                    error: function() { $btn.prop('disabled', false).text(S.disconnect); }
                });
            });
            $('#alegra-check-endpoints').on('click', function() {
                var $btn = $(this).prop('disabled', true).text(S.verifying);
                var $status = $('#alegra-endpoints-status');
                $status.html('');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_check_endpoints', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text(S.verifyEndpoints);
                        if (r.success) {
                            $status.empty();
                            $.each(r.data.endpoints || {}, function(k, v) {
                                v = String(v);
                                var cls = v.indexOf('OK') === 0 ? 'success' : (v.indexOf('vacio') !== -1 ? 'warning' : 'danger');
                                // k/v are endpoint names + Alegra error text: use .text().
                                $status.append($('<span>').addClass('ac-badge').addClass(cls).css('margin','2px').text(k + ': ' + v));
                            });
                        } else {
                            $status.html('<span style="color:var(--ac-danger);">' + S.error + '</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text(S.verifyEndpoints); $status.html('<span style="color:var(--ac-danger);">' + S.networkErrorLabel + '</span>'); }
                });
            });
        },

        initWebhookManagement: function() {
            $('#alegra-register-webhooks').on('click', function() {
                var $btn = $(this).prop('disabled', true).text(S.registering);
                var $status = $('#alegra-webhook-status');
                var $secretField = $('#alegra_connector_webhook_secret');
                var secret = $secretField.val();
                // The field is masked; a saved secret is signalled by data-saved.
                var hasSavedSecret = String($secretField.data('saved')) === '1';
                if (!secret && !hasSavedSecret) { showNotice(S.webhookSecretRequired, 'warning'); $btn.prop('disabled', false).text(S.registerWebhooks); return; }
                $status.html('');
                // AC-FIX: send the live checkbox state so the merchant does not
                // need to "Guardar Cambios" before clicking "Registrar webhooks".
                // The PHP handler falls back to the stored option if no array
                // is posted, so external callers keep their old behaviour.
                var checkedEvents = $('.alegra-webhook-event-check:checked').map(function() { return $(this).val(); }).get();
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_register_webhooks', _ajax_nonce: alegraConnector.nonce, webhook_secret: secret, webhook_selected_events: checkedEvents },
                    success: function(r) {
                        $btn.prop('disabled', false).text(S.registerWebhooks);
                        if (r.success) {
                            var d = r.data || {};
                            var msg = d.message || S.webhooksRegistered;
                            // The handler always returns success; the error
                            // count is what tells whether anything failed.
                            // Show the real message either way, and keep it on
                            // screen (no reload) when there are errors.
                            var hasErrors = Number(d.errores || 0) > 0;
                            showNotice(msg, hasErrors ? 'warning' : 'success');
                            $status.text(msg);
                            if (!hasErrors) {
                                setTimeout(function(){ location.reload(); }, 2000);
                            }
                        } else {
                            showNotice(r.data.message || S.error, 'error');
                            $status.html('<span style="color:var(--ac-danger);">' + S.error + '</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text(S.registerWebhooks); showNotice(S.connectionError, 'error'); }
                });
            });

            $('#alegra-delete-webhooks').on('click', function() {
                if (!confirm(S.confirmDeleteWebhooks)) return;
                var $btn = $(this).prop('disabled', true).text(S.deleting);
                var $status = $('#alegra-webhook-status');
                $status.html('');
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_delete_webhooks', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text(S.deleteWebhooks);
                        if (r.success) {
                            showNotice(r.data.message || S.webhooksDeleted, 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            showNotice(r.data.message || S.error, 'error');
                            $status.html('<span style="color:var(--ac-danger);">' + S.error + '</span>');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text(S.deleteWebhooks); showNotice(S.connectionError, 'error'); }
                });
            });

            // Select all / none for the webhook event selector. A convenience
            // only; the checkboxes themselves are the source of truth.
            $('#alegra-webhook-events-select-all').on('click', function() {
                $('.alegra-webhook-event-check').prop('checked', true);
            });
            $('#alegra-webhook-events-select-none').on('click', function() {
                $('.alegra-webhook-event-check').prop('checked', false);
            });
        },


        initCleanupDuplicateImages: function() {
            $('.alegra-cleanup-duplicate-images').on('click', function() {
                if (!confirm(S.confirmCleanupImages)) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text(S.cleaning);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_cleanup_duplicate_images', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        $btn.prop('disabled', false).text(S.cleanupImages);
                        if (r.success) {
                            showNotice(r.data.message || S.imagesDeleted, 'success');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            showNotice(r.data.message || S.error, 'error');
                        }
                    },
                    error: function() { $btn.prop('disabled', false).text(S.cleanupImages); showNotice(S.connectionError, 'error'); }
                });
            });
        },

        initSingleSync: function() {
            $('.alegra-sync-single').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var origText = $btn.text();
                $btn.html('<span class="dashicons dashicons-update ac-spin" style="font-size:14px;width:14px;height:14px;"></span> ' + S.syncing);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_single', _ajax_nonce: alegraConnector.nonce, entity_type: $btn.data('type'), entity_id: $btn.data('id') },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r, S.error),'error'); $btn.prop('disabled',false).html(origText); } },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).html(origText); }
                });
            });
        },

        initRecordPayment: function() {
            $('.alegra-record-payment').on('click', function() {
                var $btn = $(this);
                if(!confirm(S.confirmRecordPayment)) return;
                $btn.prop('disabled', true).text(S.registering);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_record_payment', _ajax_nonce: alegraConnector.nonce, order_id: $btn.data('order-id') },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r, S.error),'error'); $btn.prop('disabled',false).text(S.retry); } },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).text(S.retry); }
                });
            });
        },

        initOpenInvoice: function() {
            $('.alegra-open-invoice').on('click', function() {
                var $btn = $(this);
                if (!confirm(S.confirmOpenInvoice)) return;
                $btn.prop('disabled', true);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_open_invoice', _ajax_nonce: alegraConnector.nonce, order_id: $btn.data('order-id') },
                    success: function(r) {
                        if (r.success) {
                            showNotice(safeMsg(r, S.invoiceOpened), 'success');
                            setTimeout(function(){ location.reload(); }, 1200);
                        } else {
                            showNotice(safeMsg(r, S.error), 'error');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() { showNotice(S.connectionError, 'error'); $btn.prop('disabled', false); }
                });
            });
        },

        initEmitCreditNote: function() {
            $('.alegra-emit-credit-note').on('click', function() {
                var $btn = $(this);
                if (!confirm(S.confirmEmitCreditNote)) return;
                $btn.prop('disabled', true).text(S.emittingCreditNote);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_emit_credit_note', _ajax_nonce: alegraConnector.nonce, order_id: $btn.data('order-id') },
                    success: function(r) {
                        if (r.success) {
                            showNotice(safeMsg(r, S.creditNoteEmitted), 'success');
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            showNotice(safeMsg(r, S.error), 'error');
                            $btn.prop('disabled', false).text(S.creditNoteEmitted);
                        }
                    },
                    error: function() { showNotice(S.connectionError, 'error'); $btn.prop('disabled', false).text(S.creditNoteEmitted); }
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
                $('.ac-selected-count').text(count > 0 ? count + ' ' + (count === 1 ? S.selectedOne : S.selectedMany) : '');
            }

            // Push selected WC → Alegra
            $('.alegra-bulk-sync').on('click', function() {
                var type = $(this).data('type');
                var ids = $('.alegra-bulk-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { showNotice(S.selectOneItem,'warning'); return; }
                var $btn = $(this).prop('disabled', true).text(S.sending);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_bulk_sync', _ajax_nonce: alegraConnector.nonce, entity_type: type, ids: ids },
                    success: function(r) { if(r.success) location.reload(); else { showNotice(safeMsg(r, S.error),'error'); $btn.prop('disabled',false).text(S.retry); } },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).text(S.retry); }
                });
            });

            // Pull selected Alegra → WC
            $('.alegra-bulk-import').on('click', function() {
                var type = $(this).data('type');
                var ids = $('.alegra-bulk-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) { showNotice(S.selectOneItem,'warning'); return; }
                var $btn = $(this).prop('disabled', true).text(S.fetching);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_bulk_import', _ajax_nonce: alegraConnector.nonce, entity_type: type, ids: ids },
                    success: function(r) { if(r.success){showNotice(safeMsg(r, S.completed),'success');setTimeout(function(){location.reload();},1500);} else {showNotice(safeMsg(r, S.error),'error');$btn.prop('disabled',false).text(S.retry);} },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).text(S.retry); }
                });
            });

            // Pull inventory Alegra → WC stock
            $('.alegra-sync-inventory').on('click', function() {
                var $btn = $(this).prop('disabled', true).text(S.syncing);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_inventory', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        if (r.success) {
                            showNotice(safeMsg(r, S.completed), 'success');
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            showNotice(safeMsg(r, S.error), 'error');
                            $btn.prop('disabled', false).text(S.syncInventory);
                        }
                    },
                    error: function() { showNotice(S.connectionError, 'error'); $btn.prop('disabled', false).text(S.syncInventory); }
                });
            });

            // Per-item pull Alegra → WC
            $('.alegra-import-single').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var origText = $btn.text();
                $btn.html('<span class="dashicons dashicons-update ac-spin" style="font-size:14px;width:14px;height:14px;"></span> ' + S.fetching);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_import_single', _ajax_nonce: alegraConnector.nonce, entity_type: $btn.data('type'), entity_id: $btn.data('id') },
                    success: function(r) { if(r.success){showNotice(safeMsg(r, S.updatedSingle),'success');setTimeout(function(){location.reload();},1000);} else {showNotice(safeMsg(r, S.error),'error');$btn.prop('disabled',false).html(origText);} },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).html(origText); }
                });
            });

            // Import from Alegra buttons (also used on Products/Customers list pages)
            $('.alegra-import-from-api').on('click', function() {
                var $btn = $(this), type = $btn.data('type');
                if (!confirm(fmt(S.confirmImportFromApi, type))) return;
                $btn.prop('disabled', true).text(S.downloading);
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_import_from_api', _ajax_nonce: alegraConnector.nonce, import_type: type },
                    success: function(r) { if(r.success){showNotice(safeMsg(r, S.importedSingle),'success');setTimeout(function(){location.reload();},1500);} else {showNotice(safeMsg(r, S.error),'error');$btn.prop('disabled',false).text(S.retry);} },
                    error: function() { showNotice(S.connectionError,'error'); $btn.prop('disabled',false).text(S.retry); }
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

                $label.text(S.countingPending);
                $fill.css('width', '5%');
                $counters.text('');
                $elapsed.text('');
                $modal.find('.sync-cancel-btn').show();
                $modal.show();
                $('body').addClass('ac-modal-open');

                var timerInterval = setInterval(function() {
                    var e = Math.floor((Date.now() - startTime) / 1000);
                    $elapsed.text(fmt(S.elapsed, Math.floor(e / 60), (e % 60)));
                }, 1000);

                var cleanup = function() { clearInterval(timerInterval); $modal.hide(); $('body').removeClass('ac-modal-open'); };

                $modal.find('.sync-cancel-btn').off('click').on('click', function() {
                    cancelled = true;
                    cleanup();
                    $btn.prop('disabled', false).text(S.invoicePending);
                    showNotice(S.cancelled, 'warning');
                });

                // Phase 1: count pending
                $.ajax({
                    url: alegraConnector.ajaxUrl, type: 'POST',
                    data: { action: 'alegra_sync_pending_start', _ajax_nonce: alegraConnector.nonce },
                    success: function(r) {
                        if (!r.success || cancelled) { cleanup(); $btn.prop('disabled', false).text(S.invoicePending); return; }
                        var total = r.data.total;
                        if (total === 0) {
                            cleanup(); $btn.prop('disabled', false).text(S.invoicePending);
                            showNotice(S.noPendingOrders, 'info');
                            return;
                        }
                        $label.text(fmt(S.invoicingPending, total));
                        processPage();
                    },
                    error: function() { cleanup(); $btn.prop('disabled', false).text(S.invoicePending); showNotice(S.error, 'error'); }
                });

                function processPage() {
                    if (cancelled) { cleanup(); return; }
                    $.ajax({
                        url: alegraConnector.ajaxUrl, type: 'POST',
                        data: { action: 'alegra_sync_pending_page', _ajax_nonce: alegraConnector.nonce },
                        success: function(r) {
                            if (!r.success || cancelled) { cleanup(); $btn.prop('disabled', false).text(S.invoicePending); return; }
                            var d = r.data;
                            $fill.css('width', d.percent + '%');
                            $label.text(d.message);
                            $counters.text(d.synced + ' ' + S.invoicedLabel + ' | ' + d.errors + ' ' + S.errorsLabel);
                            if (d.done) {
                                $fill.css('width', '100%');
                                setTimeout(function() {
                                    cleanup();
                                    showNotice(fmt(S.invoicesCreated, d.synced, d.errors), 'success');
                                    setTimeout(function(){ location.reload(); }, 1500);
                                }, 1000);
                            } else {
                                processPage();
                            }
                        },
                        error: function() { cleanup(); $btn.prop('disabled', false).text(S.invoicePending); }
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
