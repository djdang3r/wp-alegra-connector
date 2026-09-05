<?php
if (!defined('ABSPATH')) exit;

$is_connected = isset($is_connected) ? $is_connected : (bool) get_option('alegra_connector_connection_tested');
$company_name = isset($company_name) ? $company_name : esc_html(get_option('alegra_connector_company_name', ''));
$last_sync = isset($last_sync) ? $last_sync : get_transient('alegra_connector_last_sync');
$stats = isset($stats) ? $stats : ['products' => 0, 'customers' => 0, 'orders' => 0, 'categories' => 0];
$sync_method = get_option('alegra_connector_sync_method', 'cron');

// Get additional info for dashboard cards
$running_runs = \Alegra\Connector\Runs::currently_running();
$pending_push = \Alegra\Connector\Push_Queue::get_pending(50);
$kill_switch = \Alegra\Connector\Kill_Switch::is_active();

$page_title = __('Dashboard', 'alegra-connector');
include __DIR__ . '/header.php';
?>
<div class="alegra-connector-wrap">

<?php if ($kill_switch): ?>
<div class="ac-notice error ac-notice-killswitch" style="margin-bottom:16px;">
    <strong><?php esc_html_e('Plugin desconectado', 'alegra-connector'); ?></strong>:
    <?php esc_html_e('Ninguna operacion se ejecuta. Reconecta desde Configuracion para reactivar.', 'alegra-connector'); ?>
    <div class="ac-killswitch-actions" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="ac-btn ac-btn-warning" id="alegra-clear-killswitch" style="background:#fff;color:#b45309;border:1px solid #b45309;">
            <span class="dashicons dashicons-controls-play" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Reactivar ahora', 'alegra-connector'); ?>
        </button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings')); ?>" class="ac-btn" style="background:#fff;color:#1f2937;border:1px solid #6b7280;">
            <?php esc_html_e('Ir a Configuracion', 'alegra-connector'); ?>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_connected): ?>
<!-- Getting Started (only shown when not connected) -->
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-primary);">
    <div class="ac-card-header"><h2><?php esc_html_e('Bienvenido a Alegra Connector', 'alegra-connector'); ?></h2></div>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.7;">
        <?php esc_html_e('Este plugin conecta tu tienda WooCommerce con el sistema contable Alegra. Comienza conectando tu cuenta.', 'alegra-connector'); ?>
    </p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;">
        <div style="background:var(--ac-primary-light);padding:14px 16px;border-radius:8px;">
            <strong style="font-size:13px;color:var(--ac-primary);"><?php esc_html_e('1. Conectar', 'alegra-connector'); ?></strong>
            <p style="font-size:12px;color:var(--ac-text-secondary);margin:4px 0 8px 0;">
                <?php esc_html_e('Ingresa email y token API de Alegra.', 'alegra-connector'); ?>
            </p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings')); ?>" class="ac-btn ac-btn-sm ac-btn-primary"><?php esc_html_e('Configurar', 'alegra-connector'); ?></a>
        </div>
        <div style="background:var(--ac-warning-bg);padding:14px 16px;border-radius:8px;">
            <strong style="font-size:13px;color:var(--ac-warning);"><?php esc_html_e('2. Mapear', 'alegra-connector'); ?></strong>
            <p style="font-size:12px;color:var(--ac-text-secondary);margin:4px 0 8px 0;">
                <?php esc_html_e('Define que impuestos WC corresponden a Alegra.', 'alegra-connector'); ?>
            </p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-mapping')); ?>" class="ac-btn ac-btn-sm"><?php esc_html_e('Mapear', 'alegra-connector'); ?></a>
        </div>
        <div style="background:var(--ac-success-bg);padding:14px 16px;border-radius:8px;">
            <strong style="font-size:13px;color:var(--ac-success);"><?php esc_html_e('3. Traer datos', 'alegra-connector'); ?></strong>
            <p style="font-size:12px;color:var(--ac-text-secondary);margin:4px 0 8px 0;">
                <?php esc_html_e('Importa productos desde Alegra a WC.', 'alegra-connector'); ?>
            </p>
            <button type="button" class="ac-btn ac-btn-sm ac-btn-primary alegra-quick-sync" data-type="products"><?php esc_html_e('Traer Productos', 'alegra-connector'); ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick status overview (NEW: at-a-glance answers) -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
    <div class="ac-kpi-card" style="border-left:4px solid <?php echo $is_connected ? 'var(--ac-success)' : 'var(--ac-warning)'; ?>;padding:14px 16px;">
        <div style="font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Conexión', 'alegra-connector'); ?></div>
        <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <span class="ac-badge <?php echo $is_connected ? 'success' : 'warning'; ?>"><?php echo $is_connected ? esc_html__('Conectado', 'alegra-connector') : esc_html__('Desconectado', 'alegra-connector'); ?></span>
        </div>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-primary);padding:14px 16px;">
        <div style="font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Procesos activos', 'alegra-connector'); ?></div>
        <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?php echo count($running_runs); ?>
        </div>
        <?php if (count($running_runs) > 0): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-monitor')); ?>" style="font-size:11px;"><?php esc_html_e('Ver Monitor', 'alegra-connector'); ?> &rarr;</a>
        <?php endif; ?>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-warning);padding:14px 16px;">
        <div style="font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Pushes pendientes', 'alegra-connector'); ?></div>
        <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?php echo count($pending_push); ?>
        </div>
        <?php if (count($pending_push) > 0): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-push-queue')); ?>" style="font-size:11px;"><?php esc_html_e('Revisar Cola', 'alegra-connector'); ?> &rarr;</a>
        <?php endif; ?>
    </div>
    <div class="ac-kpi-card" style="border-left:4px solid var(--ac-info);padding:14px 16px;">
        <div style="font-size:11px;font-weight:600;color:var(--ac-text-secondary);text-transform:uppercase;"><?php esc_html_e('Última sincronizacion', 'alegra-connector'); ?></div>
        <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?php echo $last_sync ? esc_html(human_time_diff($last_sync, time()) . ' ' . __('atras', 'alegra-connector')) : esc_html__('Nunca', 'alegra-connector'); ?>
        </div>
    </div>
</div>

<!-- Primary actions -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Acciónes Principales', 'alegra-connector'); ?></h2></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" class="ac-btn ac-btn-primary" id="alegra-sync-now-btn">
            <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Traer productos desde Alegra', 'alegra-connector'); ?>
        </button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-monitor')); ?>" class="ac-btn">
            <span class="dashicons dashicons-dashboard" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Monitor de Procesos', 'alegra-connector'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-push-queue')); ?>" class="ac-btn">
            <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Cola de Push', 'alegra-connector'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-products')); ?>" class="ac-btn">
            <span class="dashicons dashicons-products" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Ver Productos', 'alegra-connector'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-logs')); ?>" class="ac-btn">
            <span class="dashicons dashicons-media-text" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Ver Logs', 'alegra-connector'); ?>
        </a>
    </div>
</div>

<!-- Entity counts -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="ac-card">
        <div class="ac-card-header"><h2><?php esc_html_e('Datos en WooCommerce', 'alegra-connector'); ?></h2></div>
        <div class="ac-kpi-grid" style="grid-template-columns:1fr 1fr;gap:10px;">
            <div class="ac-kpi-card accent-green" style="padding:12px 16px;">
                <div class="ac-kpi-icon" style="width:36px;height:36px;"><span class="dashicons dashicons-products"></span></div>
                <div class="ac-kpi-content">
                    <span class="ac-kpi-label"><?php esc_html_e('Productos', 'alegra-connector'); ?></span>
                    <span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html((int) $stats['products']); ?></span>
                </div>
            </div>
            <div class="ac-kpi-card accent-blue" style="padding:12px 16px;">
                <div class="ac-kpi-icon" style="width:36px;height:36px;"><span class="dashicons dashicons-groups"></span></div>
                <div class="ac-kpi-content">
                    <span class="ac-kpi-label"><?php esc_html_e('Clientes', 'alegra-connector'); ?></span>
                    <span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html((int) $stats['customers']); ?></span>
                </div>
            </div>
            <div class="ac-kpi-card accent-amber" style="padding:12px 16px;">
                <div class="ac-kpi-icon" style="width:36px;height:36px;"><span class="dashicons dashicons-cart"></span></div>
                <div class="ac-kpi-content">
                    <span class="ac-kpi-label"><?php esc_html_e('Pedidos', 'alegra-connector'); ?></span>
                    <span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html((int) $stats['orders']); ?></span>
                </div>
            </div>
            <div class="ac-kpi-card accent-purple" style="padding:12px 16px;">
                <div class="ac-kpi-icon" style="width:36px;height:36px;"><span class="dashicons dashicons-category"></span></div>
                <div class="ac-kpi-content">
                    <span class="ac-kpi-label"><?php esc_html_e('Categorías', 'alegra-connector'); ?></span>
                    <span class="ac-kpi-value" style="font-size:18px;"><?php echo esc_html((int) $stats['categories']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="ac-card">
        <div class="ac-card-header"><h2><?php esc_html_e('Estado del Plugin', 'alegra-connector'); ?></h2></div>
        <table class="ac-detail-table" style="margin-top:8px;">
            <tr>
                <th style="width:140px;"><?php esc_html_e('Versión', 'alegra-connector'); ?></th>
                <td><code><?php echo esc_html(ALEGRA_CONNECTOR_VERSION); ?></code></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Modo sync', 'alegra-connector'); ?></th>
                <td>
                    <?php
                    if ($sync_method === 'both') echo esc_html__('Tiempo Real + Periódica', 'alegra-connector');
                    elseif ($sync_method === 'real-time') echo esc_html__('Tiempo Real', 'alegra-connector');
                    elseif ($sync_method === 'disabled') echo esc_html__('Desactivada', 'alegra-connector');
                    else echo esc_html__('Periódica', 'alegra-connector');
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Empresa', 'alegra-connector'); ?></th>
                <td><?php echo $is_connected ? esc_html($company_name) : '<span style="color:var(--ac-text-muted);">' . esc_html__('No conectado', 'alegra-connector') . '</span>'; ?></td>
            </tr>
            <?php if ($is_connected): ?>
            <tr>
                <th><?php esc_html_e('Pais', 'alegra-connector'); ?></th>
                <td><?php echo esc_html(get_option('alegra_connector_company_country', '')); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e('Plugin', 'alegra-connector'); ?></th>
                <td>
                    <?php if ($kill_switch): ?>
                        <span class="ac-badge danger"><?php esc_html_e('Desconectado', 'alegra-connector'); ?></span>
                    <?php else: ?>
                        <span class="ac-badge success"><?php esc_html_e('Activo', 'alegra-connector'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Help / docs -->
<div class="ac-card">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Ayuda', 'alegra-connector'); ?></h2>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.7;">
        <?php esc_html_e('Toda la documentacion esta disponible en la seccion de Documentación del menu lateral.', 'alegra-connector'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-docs')); ?>" class="ac-btn ac-btn-sm" style="margin-left:8px;"><?php esc_html_e('Ver Documentación', 'alegra-connector'); ?></a>
    </p>
    <p style="font-size:13px;color:var(--ac-text-secondary);margin-top:12px;">
        <strong><?php esc_html_e('Desarrollado por', 'alegra-connector'); ?>:</strong>
        <a href="https://scriptdevelop.com.co" target="_blank" rel="noopener noreferrer">Script Develop</a>
    </p>
</div>

<script>
(function($) {
    'use strict';
    $(document).ready(function() {
        $('#alegra-clear-killswitch').on('click', function() {
            if (!confirm('Reactivar el plugin ahora?')) return;
            var $btn = $(this).prop('disabled', true).text('Reactivando...');
            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: { action: 'alegra_clear_kill_switch', _ajax_nonce: alegraConnector.nonce },
                success: function(r) {
                    if (r.success) {
                        location.reload();
                    } else {
                        alert(r.data.message || 'Error');
                        $btn.prop('disabled', false).text('Reactivar ahora');
                    }
                },
                error: function() {
                    alert('Error de conexion');
                    $btn.prop('disabled', false).text('Reactivar ahora');
                }
            });
        });
    });
})(jQuery);
</script>

<?php include __DIR__ . '/footer.php'; ?>
</div>

<!-- Sync modal (kept here for backward compat) -->
<div id="alegra-sync-modal" class="ac-modal-overlay" style="display:none;">
    <div class="ac-modal">
        <h2><?php esc_html_e('Traer desde Alegra', 'alegra-connector'); ?></h2>
        <p style="margin-bottom:12px;"><?php esc_html_e('Seleccióna que datos traer a WooCommerce:', 'alegra-connector'); ?></p>
        <div style="margin-bottom:16px;">
            <label style="display:block;margin:4px 0;"><input type="checkbox" name="sync_products" checked> <?php esc_html_e('Productos', 'alegra-connector'); ?></label>
            <label style="display:block;margin:4px 0;"><input type="checkbox" name="sync_customers"> <?php esc_html_e('Clientes', 'alegra-connector'); ?></label>
            <label style="display:block;margin:4px 0;"><input type="checkbox" name="sync_orders"> <?php esc_html_e('Pedidos (estado)', 'alegra-connector'); ?></label>
            <label style="display:block;margin:4px 0;"><input type="checkbox" name="sync_categories" checked> <?php esc_html_e('Categorías', 'alegra-connector'); ?></label>
        </div>
        <div class="ac-modal-actions">
            <button type="button" class="ac-btn" id="alegra-sync-cancel"><?php esc_html_e('Cancelar', 'alegra-connector'); ?></button>
            <button type="button" class="ac-btn ac-btn-primary" id="alegra-sync-start"><?php esc_html_e('Iniciar', 'alegra-connector'); ?></button>
        </div>
    </div>
</div>
