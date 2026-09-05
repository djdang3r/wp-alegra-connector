<?php if(!defined('ABSPATH'))exit;$saved=isset($_GET['saved'])&&$_GET['saved']==='1';$page_title=__('Mapeo de Campos','alegra-connector');$page_subtitle=__('Define como se corresponden los datos entre WooCommerce y Alegra','alegra-connector');$header_color='indigo';include __DIR__.'/header.php';?>
<div class="alegra-connector-wrap">

<?php if($saved):?><div class="ac-notice success"><?php esc_html_e('Mapeo guardado correctamente. Los cambios se aplicaran en la proxima sincronizacion.','alegra-connector');?></div><?php endif;?>

<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-primary);">
    <div class="ac-card-header"><h2><?php esc_html_e('Que es el Mapeo de Campos?','alegra-connector');?></h2></div>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.6;">
        <?php esc_html_e('Esta seccion te permite definir como se traducen los datos entre WooCommerce y Alegra. Por ejemplo, puedes indicar que la clase de impuesto "Standard" en WooCommerce corresponde al impuesto "IVA 19%" en Alegra. Esto asegura que al crear facturas o productos, los impuestos se asignen correctamente en el sistema contable.','alegra-connector');?>
    </p>
    <p style="font-size:12px;color:var(--ac-text-muted);margin-top:8px;">
        <?php esc_html_e('Consejo: Conecta primero con Alegra en la pestana Conexión para que los dropdowns muestren tus datos reales de Alegra.','alegra-connector');?>
    </p>
</div>

<form method="post" action="options.php"><?php settings_fields('alegra_connector_mapping');?>

<!-- ==================== PRODUCTOS ==================== -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Productos','alegra-connector');?></h2></div>

    <div class="ac-notice info" style="margin-bottom:16px;">
        <?php esc_html_e('Estos valores se usan como predeterminados al crear productos en Alegra. Si un producto en WooCommerce no tiene un valor especifico para estos campos, se usara el valor que definas aqui.','alegra-connector');?>
    </div>

    <table class="form-table">
        <tr>
            <th><?php esc_html_e('Categoría por defecto:','alegra-connector');?></th>
            <td>
                <select name="alegra_connector_field_mapping[default_category]">
                    <option value=""><?php esc_html_e('-- No asignar categoria --','alegra-connector');?></option>
                    <?php foreach($alegra_categories as $cat):?>
                    <option value="<?php echo esc_attr($cat['id']);?>" <?php selected(($field_mapping['default_category']??''),$cat['id']);?>><?php echo esc_html($cat['name']);?></option>
                    <?php endforeach;?>
                </select>
                <p class="description"><?php esc_html_e('Categoría que se asignara en Alegra si el producto no tiene categoria en WooCommerce. Las categorias se obtienen de tu cuenta Alegra.','alegra-connector');?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Unidad de medida por defecto:','alegra-connector');?></th>
            <td>
                <input type="text" name="alegra_connector_field_mapping[default_unit]" value="<?php echo esc_attr($field_mapping['default_unit']??'unit');?>" class="regular-text">
                <p class="description"><?php esc_html_e('Unidad de medida para el inventario en Alegra. Ejemplos: unit (unidad), piece (pieza), kg (kilogramo), m (metro), cm (centimetro), l (litro), box (caja), pair (par).','alegra-connector');?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Estado por defecto:','alegra-connector');?></th>
            <td>
                <select name="alegra_connector_field_mapping[default_status]">
                    <option value="active" <?php selected(($field_mapping['default_status']??'active'),'active');?>><?php esc_html_e('Activo - Producto disponible para facturar','alegra-connector');?></option>
                    <option value="inactive" <?php selected(($field_mapping['default_status']??''),'inactive');?>><?php esc_html_e('Inactivo - Producto oculto en Alegra','alegra-connector');?></option>
                </select>
                <p class="description"><?php esc_html_e('Estado con el que se crean los productos en Alegra. "Activo" permite usarlos en facturas inmediatamente.','alegra-connector');?></p>
            </td>
        </tr>
    </table>
</div>

<!-- ==================== IMPUESTOS ==================== -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Impuestos','alegra-connector');?></h2></div>

    <div class="ac-notice warning" style="margin-bottom:16px;">
        <strong><?php esc_html_e('Importante:','alegra-connector');?></strong>
        <?php esc_html_e('El mapeo de impuestos es esencial para que las facturas se creen con los impuestos correctos en Alegra. Sin este mapeo, las facturas se crearan sin impuestos.','alegra-connector');?>
    </div>

    <p style="font-size:13px;color:var(--ac-text-secondary);margin-bottom:16px;">
        <?php esc_html_e('A continuacion se muestran las clases de impuesto configuradas en WooCommerce. Para cada una, selecciona el impuesto equivalente en Alegra. Por ejemplo, la clase "Standard" de WooCommerce suele corresponder al "IVA 19%" en Alegra (Colombia) o "IVA 16%" (Mexico).','alegra-connector');?>
    </p>

    <table class="form-table">
        <?php
        $wc=WC_Tax::get_tax_classes();
        array_unshift($wc,'');
        foreach($wc as $cs):
            $cn=empty($cs)?__('Standard (Tasa Estandar)','alegra-connector'):$cs;
            $k=sanitize_title($cs);
        ?>
        <tr>
            <th><?php echo esc_html($cn);?></th>
            <td>
                <select name="alegra_connector_tax_mapping[<?php echo esc_attr($k);?>]">
                    <option value=""><?php esc_html_e('-- Sin mapear (no se aplicara impuesto) --','alegra-connector');?></option>
                    <?php foreach($alegra_taxes as $t):?>
                    <option value="<?php echo esc_attr($t['id']);?>" <?php selected(($tax_mapping[$k]??''),$t['id']);?>>
                        <?php echo esc_html($t['name'].' - '.$t['percentage'].'%');?>
                    </option>
                    <?php endforeach;?>
                </select>
                <p class="description">
                    <?php if(empty($cs)):?>
                        <?php esc_html_e('Esta es la tasa de impuesto por defecto en WooCommerce. Aplica a la mayoria de productos.','alegra-connector');?>
                    <?php else:?>
                        <?php echo sprintf(esc_html__('Clase de impuesto adicional configurada en WooCommerce > Ajustes > Impuestos. Aplica a productos con esta clase.','alegra-connector'), esc_html($cn));?>
                    <?php endif;?>
                </p>
            </td>
        </tr>
        <?php endforeach;?>
    </table>

    <?php if(empty($alegra_taxes) && get_option('alegra_connector_connection_tested')):?>
    <div class="ac-notice warning"><?php esc_html_e('No se encontraron impuestos en tu cuenta Alegra. Verifica que tengas impuestos configurados en Alegra.','alegra-connector');?></div>
    <?php elseif(empty($alegra_taxes)):?>
    <div class="ac-notice info"><?php esc_html_e('Conecta con Alegra para ver tus impuestos disponibles.','alegra-connector');?></div>
    <?php endif;?>
</div>

<!-- ==================== PRECIOS ==================== -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Listas de Precios','alegra-connector');?></h2></div>

    <div class="ac-notice info" style="margin-bottom:16px;">
        <?php esc_html_e('Alegra soporta multiples listas de precios (Ej: General, Mayorista, Distribuidor). Aqui defines a que lista de precios de Alegra se asigna cada precio de WooCommerce.','alegra-connector');?>
    </div>

    <table class="form-table">
        <tr>
            <th><?php esc_html_e('Precio regular a lista:','alegra-connector');?></th>
            <td>
                <select name="alegra_connector_field_mapping[regular_price_list]">
                    <option value="1"><?php esc_html_e('General (ID: 1) - Lista por defecto','alegra-connector');?></option>
                    <?php foreach($alegra_price_lists as $pl): if((int)($pl['id']??0)===1)continue;?>
                    <option value="<?php echo esc_attr($pl['id']);?>" <?php selected(($field_mapping['regular_price_list']??'1'),$pl['id']);?>>
                        <?php echo esc_html(($pl['name']??'Lista').' (ID: '.$pl['id'].') - '.($pl['type']??'value'));?>
                    </option>
                    <?php endforeach;?>
                </select>
                <p class="description"><?php esc_html_e('El precio regular de WooCommerce se asignara a esta lista de precios en Alegra. La lista "General" (ID 1) es la que se usa por defecto en facturas.','alegra-connector');?></p>
            </td>
        </tr>
    </table>
</div>

<?php submit_button(__('Guardar Mapeo','alegra-connector'));?>
</form>

<?php include __DIR__.'/footer.php'; ?>
</div>