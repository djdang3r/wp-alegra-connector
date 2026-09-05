<?php
/**
 * Common header for all Alegra Connector admin views
 */
if (!defined('ABSPATH')) exit;

$is_connected = $is_connected ?? (bool) get_option('alegra_connector_connection_tested');
$company_name = $company_name ?? esc_html(get_option('alegra_connector_company_name', ''));
$page_title = $page_title ?? __('Alegra Connector', 'alegra-connector');
$page_subtitle = $page_subtitle ?? '';
$header_color = $header_color ?? 'indigo';
?>
<div class="alegra-app-header header-<?php echo esc_attr($header_color); ?>">
    <div class="alegra-app-header-inner">
        <div class="alegra-app-header-left">
            <div class="alegra-app-header-logos">
                <img src="<?php echo esc_url(ALEGRA_CONNECTOR_URL . 'admin/assets/images/scriptdevelop.svg'); ?>" alt="Script Develop" style="filter:none;">
                <span class="alegra-app-header-separator">×</span>
                <img src="<?php echo esc_url(ALEGRA_CONNECTOR_URL . 'admin/assets/images/alegra.svg'); ?>" alt="Alegra">
            </div>
            <div class="alegra-app-header-title">
                <h2><?php echo esc_html($page_title); ?></h2>
                <?php if ($page_subtitle): ?>
                    <small><?php echo esc_html($page_subtitle); ?></small>
                <?php elseif ($is_connected): ?>
                    <small><?php echo sprintf(esc_html__('Conectado a %s', 'alegra-connector'), $company_name); ?></small>
                <?php else: ?>
                    <small><?php esc_html_e('No conectado - Configura tus credenciales', 'alegra-connector'); ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="alegra-app-header-status">
            <?php if ($is_connected): ?>
                <span class="status-badge connected">
                    <span class="status-dot green"></span>
                    <?php esc_html_e('Conectado', 'alegra-connector'); ?>
                </span>
            <?php else: ?>
                <span class="status-badge disconnected">
                    <span class="status-dot red"></span>
                    <?php esc_html_e('Desconectado', 'alegra-connector'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>