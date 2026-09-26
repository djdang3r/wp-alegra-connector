<?php
/**
 * Reconciliación de stock WC↔Alegra (REQ-RECON-01/03, T7.4).
 *
 * Read-only: lista las divergencias registradas por el poll (con causa) y el
 * doble decremento heredado detectado. La reparación es explícita y bajo
 * confirmación; nunca emite los dos mecanismos.
 *
 * @package Alegra\Connector
 */
if (!defined('ABSPATH')) exit;

$report = is_array($report ?? null) ? $report : ['items' => [], 'total' => 0];
$legacy = is_array($legacy ?? null) ? $legacy : ['items' => [], 'total' => 0];
$items  = (array) ($report['items'] ?? []);
$legacy_items = (array) ($legacy['items'] ?? []);

include __DIR__ . '/header.php';

$ac_cause_labels = [
    'baseline_ausente'  => __('Baseline ausente', 'alegra-connector'),
    'divergencia_dueno' => __('Divergencia de dueño', 'alegra-connector'),
    'factura_fallida'   => __('Factura fallida', 'alegra-connector'),
    'reembolso'         => __('Reembolso', 'alegra-connector'),
    'factura_pendiente' => __('Factura pendiente', 'alegra-connector'),
];
$ac_owner = \Alegra\Connector\Sync\Inventory_Pusher::owner();
?>
<div class="alegra-connector-wrap">

<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Reconciliación de stock', 'alegra-connector'); ?></h2>
        <span class="ac-badge" style="margin-left:auto;"><?php echo esc_html((string) (int) ($report['total'] ?? 0)); ?></span>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);">
        <?php esc_html_e('Productos cuyo stock en WooCommerce difiere del de Alegra. La reparación es explícita: emite UN solo mecanismo (factura o ajuste), nunca los dos.', 'alegra-connector'); ?>
    </p>

    <?php if ($items === []): ?>
        <div class="ac-empty-state"><?php esc_html_e('Sin divergencias registradas.', 'alegra-connector'); ?></div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Producto', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('WooCommerce', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Alegra', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Baseline', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Causa', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Acciones', 'alegra-connector'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $ac_it): ?>
            <?php $ac_cause = (string) ($ac_it['cause'] ?? ''); ?>
            <tr>
                <td>#<?php echo esc_html((string) ($ac_it['product_id'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($ac_it['w'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($ac_it['a'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($ac_it['s'] ?? '')); ?></td>
                <td><span class="ac-badge" data-cause="<?php echo esc_attr($ac_cause); ?>"><?php echo esc_html($ac_cause_labels[$ac_cause] ?? $ac_cause); ?></span></td>
                <td>
                    <button type="button" class="ac-btn ac-btn-sm alegra-repair-divergence"
                            data-product-id="<?php echo esc_attr((string) ($ac_it['product_id'] ?? '')); ?>"
                            data-mechanism="adjustment" data-repair-mode="divergence">
                        <?php esc_html_e('Reparar con ajuste', 'alegra-connector'); ?>
                    </button>
                    <button type="button" class="ac-btn ac-btn-sm alegra-repair-divergence"
                            data-product-id="<?php echo esc_attr((string) ($ac_it['product_id'] ?? '')); ?>"
                            data-mechanism="invoice" data-repair-mode="divergence">
                        <?php esc_html_e('Reparar con factura', 'alegra-connector'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-danger);">
    <div class="ac-card-header">
        <h2><?php esc_html_e('Doble descuento existente (corrupción heredada)', 'alegra-connector'); ?></h2>
    </div>
    <p style="font-size:13px;color:var(--ac-text-secondary);">
        <?php esc_html_e('Pedidos facturados cuyos productos además recibieron un ajuste en 2.6.0: Alegra descontó dos veces. Reparar emite UN ajuste compensatorio (entrada) por producto, cambia el dueño a Factura y re-baselina. Antes de reparar, medí el availableQuantity en Alegra.', 'alegra-connector'); ?>
    </p>
    <p style="font-size:12px;color:var(--ac-danger);">
        <?php esc_html_e('Atención: si re-baselinás a un valor mayor que el stock físico de WooCommerce, el poll querrá empujar un ajuste de salida. Si querés conservar el stock de WooCommerce, corregí Alegra o desactivá el push temporalmente.', 'alegra-connector'); ?>
    </p>

    <?php if ($legacy_items === []): ?>
        <div class="ac-empty-state"><?php esc_html_e('No se detectó doble descuento existente.', 'alegra-connector'); ?></div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Producto', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Cantidad duplicada', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Pedidos', 'alegra-connector'); ?></th>
                <th><?php esc_html_e('Acciones', 'alegra-connector'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($legacy_items as $ac_leg): ?>
            <tr>
                <td>#<?php echo esc_html((string) ($ac_leg['product_id'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($ac_leg['qty_doble'] ?? 0)); ?></td>
                <td><?php echo esc_html(implode(', ', array_map('strval', (array) ($ac_leg['orders'] ?? [])))); ?></td>
                <td>
                    <button type="button" class="ac-btn ac-btn-sm alegra-repair-divergence"
                            data-product-id="<?php echo esc_attr((string) ($ac_leg['product_id'] ?? '')); ?>"
                            data-mechanism="adjustment" data-repair-mode="legacy_compensation"
                            data-qty-doble="<?php echo esc_attr((string) ($ac_leg['qty_doble'] ?? 0)); ?>">
                        <?php esc_html_e('Reparar corrupción heredada', 'alegra-connector'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <p style="font-size:12px;color:var(--ac-text-muted);">
        <?php echo esc_html(sprintf(__('Dueño efectivo ahora: %s.', 'alegra-connector'), $ac_owner === 'invoice' ? __('Factura', 'alegra-connector') : __('Ajuste', 'alegra-connector'))); ?>
    </p>
</div>
<?php include __DIR__ . '/footer.php'; ?></div>
