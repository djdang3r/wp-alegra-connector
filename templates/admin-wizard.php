<?php
if (!defined('ABSPATH')) exit;

// Track wizard state per user
$user_id = get_current_user_id();
$wizard_step = (int) get_user_meta($user_id, 'alegra_wizard_step', true);
$wizard_done = (bool) get_user_meta($user_id, 'alegra_wizard_done', true);

$is_connected = (bool) get_option('alegra_connector_connection_tested');

// Skip wizard if already done or user is already connected (basic connection is set)
if ($wizard_done || $is_connected) {
    // Redirect to dashboard
    wp_safe_redirect(admin_url('admin.php?page=alegra-connector'));
    exit;
}

$page_title = __('Asistente de Configuración', 'alegra-connector');
$page_subtitle = __('Configura el plugin paso a paso', 'alegra-connector');
$header_color = 'primary';
include __DIR__ . '/header.php';
$step = max(1, min(5, $wizard_step ?: 1));
?>
<div class="alegra-connector-wrap">

<!-- Progress -->
<div class="ac-card" style="margin-bottom:16px;">
    <div style="display:flex;gap:6px;align-items:center;">
        <?php for ($i = 1; $i <= 5; $i++):
            $step_cls = $i < $step ? 'success' : ($i === $step ? 'primary' : 'neutral');
            $step_label = [
                1 => 'Conexión',
                2 => 'Sincronización',
                3 => 'Imagenes',
                4 => 'Push',
                5 => 'Listo',
            ][$i];
        ?>
            <div style="flex:1;text-align:center;">
                <div style="background:<?php echo $i <= $step ? 'var(--ac-primary)' : 'var(--ac-surface-alt)'; ?>;color:white;border-radius:50%;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-weight:600;margin-bottom:6px;">
                    <?php echo $i < $step ? '&#10003;' : $i; ?>
                </div>
                <div style="font-size:11px;color:<?php echo $i === $step ? 'var(--ac-primary)' : 'var(--ac-text-muted)'; ?>;font-weight:<?php echo $i === $step ? '600' : '400'; ?>;">
                    <?php echo esc_html($step_label); ?>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</div>

<!-- Step content -->
<div class="ac-card" style="margin-bottom:16px;">
    <?php if ($step === 1): ?>
        <h2><?php esc_html_e('Paso 1: Conectar con Alegra', 'alegra-connector'); ?></h2>
        <p style="font-size:14px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:16px;">
            <?php esc_html_e('Para empezar, conecta el plugin con tu cuenta de Alegra. Necesitas tu email y un token API.', 'alegra-connector'); ?>
        </p>
        <p style="font-size:13px;color:var(--ac-text-muted);margin-bottom:16px;">
            <?php esc_html_e('El token se genera en Alegra: app.alegra.com > Configuración > API - Integraciones con otros sistemas.', 'alegra-connector'); ?>
        </p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings&tab=connection')); ?>" class="ac-btn ac-btn-primary">
            <?php esc_html_e('Ir a Configuración de Conexión', 'alegra-connector'); ?>
        </a>
    <?php elseif ($step === 2): ?>
        <h2><?php esc_html_e('Paso 2: Elegir que sincronizar', 'alegra-connector'); ?></h2>
        <p style="font-size:14px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:16px;">
            <?php esc_html_e('Seleccióna que entidades quieres sincronizar desde Alegra hacia WooCommerce. Recomendamos empezar solo con Productos para validar.', 'alegra-connector'); ?>
        </p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings&tab=sync')); ?>" class="ac-btn ac-btn-primary">
            <?php esc_html_e('Configurar Sincronización', 'alegra-connector'); ?>
        </a>
    <?php elseif ($step === 3): ?>
        <h2><?php esc_html_e('Paso 3: Configurar Imagenes', 'alegra-connector'); ?></h2>
        <p style="font-size:14px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:16px;">
            <?php esc_html_e('Decide como importar las imagenes de productos. Recomendado: solo la imagen favorita.', 'alegra-connector'); ?>
        </p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings&tab=sync')); ?>" class="ac-btn ac-btn-primary">
            <?php esc_html_e('Configurar Imagenes', 'alegra-connector'); ?>
        </a>
    <?php elseif ($step === 4): ?>
        <h2><?php esc_html_e('Paso 4: Push a Alegra (opcional)', 'alegra-connector'); ?></h2>
        <p style="font-size:14px;color:var(--ac-text-secondary);line-height:1.7;margin-bottom:16px;">
            <?php esc_html_e('Por defecto, el plugin SOLO trae datos desde Alegra. NO envia ventas/pagos a Alegra automaticamente.', 'alegra-connector'); ?>
        </p>
        <p style="font-size:13px;color:var(--ac-warning-bg);background:var(--ac-warning-bg);padding:12px;border-radius:6px;margin-bottom:16px;">
            <strong><?php esc_html_e('Importante', 'alegra-connector'); ?>:</strong>
            <?php esc_html_e('Si decides enviar ventas/facturas a Alegra, primero configura la Cola de Push para aprobar manualmente cada envio.', 'alegra-connector'); ?>
        </p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-push-queue')); ?>" class="ac-btn ac-btn-primary">
            <?php esc_html_e('Configurar Cola de Push', 'alegra-connector'); ?>
        </a>
    <?php elseif ($step === 5): ?>
        <h2><?php esc_html_e('Paso 5: Listo!', 'alegra-connector'); ?></h2>
        <p style="font-size:14px;color:var(--ac-success-bg);background:var(--ac-success-bg);padding:14px;border-radius:6px;margin-bottom:16px;">
            <strong><?php esc_html_e('Configuración completa!', 'alegra-connector'); ?></strong>
            <?php esc_html_e('Ya puedes empezar a sincronizar. Recomendamos hacer una primera prueba manual antes de activar sync automatico.', 'alegra-connector'); ?>
        </p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-dashboard')); ?>" class="ac-btn ac-btn-primary">
            <?php esc_html_e('Ir al Dashboard', 'alegra-connector'); ?>
        </a>
    <?php endif; ?>
</div>

<!-- Navigation -->
<?php if ($step < 5): ?>
<div class="ac-card">
    <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;">
        <button type="button" class="ac-btn" id="alegra-wizard-skip">
            <?php esc_html_e('Saltar asistente', 'alegra-connector'); ?>
        </button>
        <button type="button" class="ac-btn ac-btn-primary" id="alegra-wizard-next" data-step="<?php echo $step; ?>">
            <?php echo esc_html__('Siguiente', 'alegra-connector'); ?> &rarr;
        </button>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__.'/footer.php'; ?>
</div>

<script>
(function($) {
    'use strict';
    var nonce = <?php echo json_encode(wp_create_nonce('alegra_connector_nonce')); ?>;

    $('#alegra-wizard-next').on('click', function() {
        var currentStep = $(this).data('step');
        $.post(alegraConnector.ajaxUrl, {
            action: 'alegra_wizard_advance',
            _ajax_nonce: nonce,
            step: currentStep + 1
        }, function(r) {
            if (r.success) location.reload();
            else alert('Error al guardar progreso');
        });
    });

    $('#alegra-wizard-skip').on('click', function() {
        if (!confirm('Saltar el asistente? Puedes volver a iniciarlo desde Dashboard.')) return;
        $.post(alegraConnector.ajaxUrl, {
            action: 'alegra_wizard_skip',
            _ajax_nonce: nonce
        }, function(r) {
            if (r.success) {
                window.location.href = '<?php echo esc_js(admin_url('admin.php?page=alegra-connector')); ?>';
            }
        });
    });
})(jQuery);
</script>
