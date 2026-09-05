<?php
if (!defined('ABSPATH')) exit;

$last_sync = $last_sync ?? get_transient('alegra_connector_last_sync');
?>
<!-- Sync Progress Modal -->
<div id="alegra-sync-progress-modal" class="ac-modal-overlay" style="display:none;">
    <div class="ac-modal" style="text-align:center;">
        <h2><?php esc_html_e('Trayendo desde Alegra','alegra-connector');?></h2>
        <p class="sync-progress-label" style="margin:12px 0;font-size:13px;color:var(--ac-text-secondary);"><?php esc_html_e('Conectando con Alegra...','alegra-connector');?></p>
        <div style="height:8px;background:var(--ac-surface-alt);border-radius:4px;overflow:hidden;margin:16px 0;">
            <div class="sync-progress-fill" style="height:100%;background:linear-gradient(90deg,var(--ac-primary),#818cf8);border-radius:4px;width:0%;transition:width 0.3s ease;"></div>
        </div>
        <p style="font-size:11px;color:var(--ac-text-muted);"><?php esc_html_e('Los registros existentes se actualizaran, no se duplicaran.','alegra-connector');?></p>
        <p class="sync-counters" style="font-size:12px;color:var(--ac-text-secondary);margin:8px 0;font-weight:500;"></p>
        <p id="sync-elapsed" style="font-size:11px;color:var(--ac-text-muted);"></p>
        <button type="button" class="ac-btn sync-cancel-btn" style="margin-top:12px;color:var(--ac-danger);"><?php esc_html_e('Cancelar','alegra-connector');?></button>
    </div>
</div>

<div class="ac-footer">
    <div class="ac-footer-logos">
        <span style="font-size:11px;color:var(--ac-text-muted);"><?php esc_html_e('Desarrollado por', 'alegra-connector'); ?></span>
        <a href="https://scriptdevelop.com.co" target="_blank" title="Script Develop">
            <img src="<?php echo esc_url(ALEGRA_CONNECTOR_URL . 'admin/assets/images/scriptdevelop.svg'); ?>" alt="Script Develop">
        </a>
        <a href="https://scriptdevelop.com.co" target="_blank" style="font-size:11px;color:var(--ac-text-muted);text-decoration:none;">scriptdevelop.com.co</a>
        <span style="color:var(--ac-border);">|</span>
        <span style="font-size:11px;color:var(--ac-text-muted);"><?php esc_html_e('Conecta con', 'alegra-connector'); ?></span>
        <a href="https://alegra.com" target="_blank"><img src="<?php echo esc_url(ALEGRA_CONNECTOR_URL . 'admin/assets/images/alegra.svg'); ?>" alt="Alegra"></a>
    </div>
    <p>
        <?php echo sprintf(
            esc_html__('Última sincronizacion: %s', 'alegra-connector'),
            $last_sync ? esc_html(human_time_diff($last_sync, time()) . ' ' . __('atras', 'alegra-connector')) : esc_html__('Nunca', 'alegra-connector')
        ); ?>
    </p>
</div>