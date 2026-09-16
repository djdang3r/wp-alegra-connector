<?php if(!defined('ABSPATH'))exit;$page_title=__('Configuración','alegra-connector');$page_subtitle=__('Ajusta la conexion y el comportamiento del plugin','alegra-connector');include __DIR__.'/header.php';?>
<div class="alegra-connector-wrap">
<?php if(get_option('alegra_connector_dry_run',false)):?>
<div class="alegra-dryrun-banner">
<span class="alegra-dryrun-badge"><?php esc_html_e('MODO PRUEBA ACTIVADO','alegra-connector');?></span>
<span><?php esc_html_e('El plugin NO envía nada a Alegra: no se crean facturas, clientes ni productos. Desactívalo antes de facturar de verdad.','alegra-connector');?></span>
</div>
<?php endif;?>
<?php if(isset($_GET['settings-updated']) && $_GET['settings-updated']):?>
<div class="ac-notice success" style="margin-bottom:16px;"><?php esc_html_e('Configuración guardada correctamente.','alegra-connector');?></div>
<?php endif;?>
<form method="post" action="options.php"><?php settings_fields('alegra_connector_settings');?>
<div class="ac-settings-tabs">
<button type="button" class="ac-settings-tab active" data-tab="connection"><?php esc_html_e('Conexión','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="sync"><?php esc_html_e('Sincronización','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="currency"><?php esc_html_e('Moneda','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="warehouse"><?php esc_html_e('Bodegas','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="billing"><?php esc_html_e('Facturación electrónica','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="advanced"><?php esc_html_e('Avanzado','alegra-connector');?></button>
</div>
<div class="ac-settings-content">

<!-- ==================== CONEXION ==================== -->
<div class="ac-tab-content active" id="tab-connection">
<h2><?php esc_html_e('Conexión con Alegra','alegra-connector');?></h2>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Para conectar necesitas el email de tu cuenta Alegra y un token API. Obten tu token en: app.alegra.com > Configuración > API - Integraciones con otros sistemas.','alegra-connector');?></div>
<table class="form-table">
<tr><th><label for="alegra_connector_email"><?php esc_html_e('Email de la cuenta:','alegra-connector');?></label></th><td><input type="email" id="alegra_connector_email" name="alegra_connector_email" value="<?php echo esc_attr(get_option('alegra_connector_email',''));?>" class="regular-text" placeholder="tu@email.com"><p class="description"><?php esc_html_e('El mismo email con el que inicias sesion en Alegra.','alegra-connector');?></p></td></tr>
<tr><th><label for="alegra_connector_token"><?php esc_html_e('Token API:','alegra-connector');?></label></th>
<td>
    <?php $ac_token_saved = (string) get_option('alegra_connector_token', ''); ?>
    <input type="password" id="alegra_connector_token" name="alegra_connector_token" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo esc_attr($ac_token_saved !== '' ? __('•••••••• (guardado, deja vacio para conservarlo)', 'alegra-connector') : __('Token generado en Alegra', 'alegra-connector')); ?>">
    <button type="button" class="ac-btn ac-btn-sm" id="toggle-token-visibility" style="margin-left:8px;"><?php esc_html_e('Mostrar','alegra-connector');?></button>
    <p class="description"><?php esc_html_e('El token se almacena en la base de datos de WordPress. Por seguridad no se vuelve a mostrar: deja el campo vacio para conservar el token guardado.','alegra-connector');?></p>
</td></tr>
<tr><th><label for="alegra_connector_api_url"><?php esc_html_e('URL de la API:','alegra-connector');?></label></th><td><input type="text" id="alegra_connector_api_url" name="alegra_connector_api_url" value="<?php echo esc_attr(get_option('alegra_connector_api_url','https://api.alegra.com/api/v1'));?>" class="regular-text" placeholder="https://api.alegra.com/api/v1"><p class="description"><?php esc_html_e('URL base de la API de Alegra. No cambiar a menos que Alegra te indique otra URL.','alegra-connector');?></p></td></tr>
<tr><th></th><td><button type="button" class="ac-btn ac-btn-primary" id="alegra-test-connection"><?php esc_html_e('Probar Conexión','alegra-connector');?></button> <span id="alegra-connection-status" style="margin-left:8px;font-size:13px;"></span><p class="description"><?php esc_html_e('Verifica que las credenciales sean correctas antes de guardar.','alegra-connector');?></p></td></tr></table>
<div id="alegra-connection-result" style="display:none;margin-top:12px;padding:12px;border-radius:8px;"></div>

<?php $connected=(bool)get_option('alegra_connector_connection_tested');if($connected):?>
<div id="alegra-connection-info" class="ac-card" style="margin-top:16px;border-left:4px solid var(--ac-success);">
    <div class="ac-card-header"><h2><?php esc_html_e('Información de la Conexión','alegra-connector');?></h2><span class="ac-badge success"><?php esc_html_e('Conectado','alegra-connector');?></span></div>
    <table class="ac-detail-table" style="margin-top:8px;">
        <tr><th style="width:100px;"><?php esc_html_e('Empresa','alegra-connector');?></th><td><strong><?php echo esc_html(get_option('alegra_connector_company_name','--'));?></strong></td></tr>
        <tr><th><?php esc_html_e('Email','alegra-connector');?></th><td><?php echo esc_html(get_option('alegra_connector_company_email','--'));?></td></tr>
        <tr><th><?php esc_html_e('Pais','alegra-connector');?></th><td><?php echo esc_html(get_option('alegra_connector_company_country','--'));?></td></tr>
        <tr><th><?php esc_html_e('URL API','alegra-connector');?></th><td><code><?php echo esc_html(get_option('alegra_connector_api_url','https://api.alegra.com/api/v1'));?></code></td></tr>
        <?php $diag = get_option('alegra_connector_diagnostics',[]); if(!empty($diag)):?>
        <tr><th><?php esc_html_e('Diagnostico','alegra-connector');?></th><td><?php foreach($diag as $d):?><span class="ac-badge <?php echo strpos($d,'OK')!==false?'success':(strpos($d,'vacio')!==false?'warning':'danger');?>" style="margin:2px;"><?php echo esc_html($d);?></span><?php endforeach;?></td></tr>
        <?php endif;?>
    </table>
    <div style="margin-top:12px;display:flex;gap:8px;">
        <button type="button" class="ac-btn" id="alegra-disconnect" style="color:var(--ac-danger);border-color:var(--ac-danger);"><span class="dashicons dashicons-no" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e('Desconectar','alegra-connector');?></button>
        <button type="button" class="ac-btn" id="alegra-check-endpoints"><span class="dashicons dashicons-saved" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e('Verificar Endpoints','alegra-connector');?></button>
        <span id="alegra-endpoints-status" style="font-size:12px;align-self:center;"></span>
    </div>
</div>
<?php endif;?>
</div>

<!-- ==================== SINCRONIZACION ==================== -->
<div class="ac-tab-content" id="tab-sync" style="display:none;">
<h2><?php esc_html_e('Configuración de Sincronización','alegra-connector');?></h2>
<table class="form-table">
<tr><th><?php esc_html_e('Método de sincronizacion:','alegra-connector');?></th><td><fieldset>
<label><input type="radio" name="alegra_connector_sync_method" value="cron" <?php checked(get_option('alegra_connector_sync_method','cron'),'cron');?>> <strong><?php esc_html_e('Periódica (recomendado)','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Sincroniza automaticamente desde Alegra hacia WooCommerce cada X minutos. Trae productos, clientes y verifica facturas pagadas.','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="both" <?php checked(get_option('alegra_connector_sync_method','cron'),'both');?>> <strong><?php esc_html_e('Periódica + Tiempo Real','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Combina la sincronizacion periodica con webhooks en tiempo real para maxima inmediatez.','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="real-time" <?php checked(get_option('alegra_connector_sync_method','cron'),'real-time');?>> <strong><?php esc_html_e('Solo Tiempo Real','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Solo webhooks. Sin cron periodico (no recomendado para la mayoria de casos).','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="disabled" <?php checked(get_option('alegra_connector_sync_method','cron'),'disabled');?>> <strong><?php esc_html_e('Desactivada','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Ningun sync automatico. Solo manual desde el dashboard. Util para validar antes de activar automatico.','alegra-connector');?></p>
</fieldset></td></tr>
<tr><th><label for="alegra_connector_sync_frequency"><?php esc_html_e('Frecuencia periodica:','alegra-connector');?></label></th><td><select id="alegra_connector_sync_frequency" name="alegra_connector_sync_frequency"><option value="5" <?php selected(get_option('alegra_connector_sync_frequency'),5);?>>5 min</option><option value="15" <?php selected(get_option('alegra_connector_sync_frequency',15),15);?>>15 min</option><option value="30" <?php selected(get_option('alegra_connector_sync_frequency'),30);?>>30 min</option><option value="60" <?php selected(get_option('alegra_connector_sync_frequency'),60);?>>1 hora</option></select><p class="description"><?php esc_html_e('Cada cuanto tiempo se ejecuta la sincronizacion automatica. Solo aplica si usas modo Periódica o Ambos.','alegra-connector');?></p></td></tr>
<tr><th><?php esc_html_e('Entidades a sincronizar:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_sync_products" value="1" <?php checked(get_option('alegra_connector_sync_products',true));?>> <?php esc_html_e('Productos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae productos, variaciones e imagenes desde Alegra.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_customers" value="1" <?php checked(get_option('alegra_connector_sync_customers',true));?>> <?php esc_html_e('Clientes','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae contactos desde Alegra a la lista de clientes de WooCommerce.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_orders" value="1" <?php checked(get_option('alegra_connector_sync_orders',true));?>> <?php esc_html_e('Pedidos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Verifica el estado de las facturas en Alegra y actualiza los pedidos cuando se pagan.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_categories" value="1" <?php checked(get_option('alegra_connector_sync_categories',true));?>> <?php esc_html_e('Categorías','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae categorias de items desde Alegra.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_images" value="1" <?php checked(get_option('alegra_connector_sync_images',true));?>> <?php esc_html_e('Imagenes de productos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Activa la sincronizacion bidireccional de imagenes de productos. Al desactivar, no se descargan ni se suben imagenes.','alegra-connector');?></p>
<div style="margin:0 0 12px 24px;">
<label style="font-size:12px;font-weight:600;color:var(--ac-text-secondary);display:block;margin-bottom:4px;"><?php esc_html_e('Al traer desde Alegra:','alegra-connector');?></label>
<select name="alegra_connector_sync_images_mode" style="padding:4px 8px;border-radius:6px;border:1px solid var(--ac-border);font-size:12px;max-width:280px;">
<option value="favorite" <?php selected(get_option('alegra_connector_sync_images_mode','favorite'),'favorite');?>><?php esc_html_e('Solo la imagen favorita (favorite: true) — recomendado','alegra-connector');?></option>
<option value="all" <?php selected(get_option('alegra_connector_sync_images_mode'),'all');?>><?php esc_html_e('Todas las imagenes (la favorita como principal, el resto en galeria)','alegra-connector');?></option>
<option value="except_favorite" <?php selected(get_option('alegra_connector_sync_images_mode'),'except_favorite');?>><?php esc_html_e('Todas excepto la favorita (solo galeria, sin imagen principal)','alegra-connector');?></option>
</select>
<p class="description" style="margin-top:2px;"><?php esc_html_e('La API de Alegra devuelve un array images[] donde cada objeto tiene: id, name, url y favorite (booleano). La imagen con favorite:true es la predeterminada en Alegra.','alegra-connector');?></p>
</div>
<label><input type="checkbox" name="alegra_connector_sync_inactive_products" value="1" <?php checked(get_option('alegra_connector_sync_inactive_products',false));?>> <?php esc_html_e('Productos inactivos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Al activar, tambien se sincronizan los productos inactivos de Alegra. Se importaran como Borrador en WooCommerce. Al desactivar (por defecto), solo se sincronizan productos activos (status=active en Alegra). La API de Alegra permite filtrar por status: active, inactive.','alegra-connector');?></p>
</fieldset></td></tr>
<tr><th><?php esc_html_e('Fuente de inventario:','alegra-connector');?></th><td><select name="alegra_connector_inventory_source"><option value="alegra" <?php selected(get_option('alegra_connector_inventory_source','alegra'),'alegra');?>><?php esc_html_e('Alegra (recomendado)','alegra-connector');?></option><option value="woocommerce" <?php selected(get_option('alegra_connector_inventory_source'),'woocommerce');?>><?php esc_html_e('WooCommerce','alegra-connector');?></option></select><p class="description"><?php esc_html_e('Define que sistema es la fuente principal del inventario. Si hay conflicto, gana la fuente seleccionada.','alegra-connector');?></p></td></tr>
<tr><th><?php esc_html_e('Enviar ventas y pagos a Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_push_orders_enabled" value="1" <?php checked(get_option('alegra_connector_push_orders_enabled',true));?>> <strong><?php esc_html_e('Activar envio automatico de ventas y pagos a Alegra','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Al activar esta opcion, cuando se complete una venta en WooCommerce, se creara automaticamente la factura en Alegra. Si desactivas esta opcion, deberas crear las facturas manualmente desde el dashboard o usando el boton "Facturar pendientes".','alegra-connector');?></p>
</fieldset></td></tr>
<tr><th><?php esc_html_e('Subir productos a Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_push_products_enabled" value="1" <?php checked(get_option('alegra_connector_push_products_enabled',false));?>> <strong><?php esc_html_e('Activar envio automatico de productos y clientes a Alegra','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('PRECAUCION: Al activar, cada vez que crees o actualices un producto o cliente en WooCommerce, se enviara automaticamente a Alegra. Esto puede sobrescribir datos existentes. Por defecto esta desactivado. Cuando este toggle esta apagado, solo puedes subir productos a Alegra manualmente desde los botones "Actualizar" de la pagina de detalle del producto, o usando los botones "Enviar seleccionados" del listado de productos.','alegra-connector');?></p>
</fieldset></td></tr>
<?php $ac_product_cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]); if(is_wp_error($ac_product_cats))$ac_product_cats=[]; ?>
<tr><th><label for="alegra_connector_push_category_strategy"><?php esc_html_e('Categoría a enviar a Alegra:','alegra-connector');?></label></th><td>
<select id="alegra_connector_push_category_strategy" name="alegra_connector_push_category_strategy">
<option value="deepest" <?php selected(get_option('alegra_connector_push_category_strategy','deepest'),'deepest');?>><?php esc_html_e('La más específica (recomendado)','alegra-connector');?></option>
<option value="first" <?php selected(get_option('alegra_connector_push_category_strategy'),'first');?>><?php esc_html_e('La primera','alegra-connector');?></option>
<option value="specific" <?php selected(get_option('alegra_connector_push_category_strategy'),'specific');?>><?php esc_html_e('Una categoría específica','alegra-connector');?></option>
</select>
<select name="alegra_connector_push_category_id" style="margin-left:8px;">
<option value="0"><?php esc_html_e('-- Seleccionar categoría --','alegra-connector');?></option>
<?php foreach($ac_product_cats as $ac_cat):?>
<option value="<?php echo esc_attr((string)$ac_cat->term_id);?>" <?php selected((int)get_option('alegra_connector_push_category_id',0),(int)$ac_cat->term_id);?>><?php echo esc_html($ac_cat->name);?></option>
<?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Cuando un producto tiene varias categorías, define cuál se envía a Alegra. "La más específica" usa la hoja del árbol; "Una categoría específica" solo se usa si el producto la tiene asignada.','alegra-connector');?></p>
</td></tr>
<tr><th><label for="alegra_connector_import_category_parent"><?php esc_html_e('Categoría padre al importar:','alegra-connector');?></label></th><td>
<select id="alegra_connector_import_category_parent" name="alegra_connector_import_category_parent">
<option value="0"><?php esc_html_e('-- Sin padre (nivel superior) --','alegra-connector');?></option>
<?php foreach($ac_product_cats as $ac_cat):?>
<option value="<?php echo esc_attr((string)$ac_cat->term_id);?>" <?php selected((int)get_option('alegra_connector_import_category_parent',0),(int)$ac_cat->term_id);?>><?php echo esc_html($ac_cat->name);?></option>
<?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Las categorías creadas al importar productos desde Alegra se colgarán de esta categoría.','alegra-connector');?></p>
</td></tr>
</table>
</div>

<!-- ==================== MONEDA ==================== -->
<div class="ac-tab-content" id="tab-currency" style="display:none;">
<h2><?php esc_html_e('Configuración de Moneda','alegra-connector');?></h2>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('La moneda seleccionada se usara al crear facturas en Alegra. Debe coincidir con una moneda configurada en tu cuenta Alegra. Si no estas conectado, se muestran las monedas basicas.','alegra-connector');?></div>
<table class="form-table">
<tr><th><?php esc_html_e('Moneda por defecto:','alegra-connector');?></th><td>
<?php if(!empty($alegra_currencies)):?>
<select name="alegra_connector_currency">
    <?php foreach($alegra_currencies as $cur): $code=esc_attr($cur['code']??'');$name=esc_html(($cur['name']??'').' ('.$code.')');?>
    <option value="<?php echo $code;?>" <?php selected(get_option('alegra_connector_currency','COP'),$code);?>><?php echo $name;?></option>
    <?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Estas monedas vienen directamente de tu cuenta Alegra. La factura se creara en la moneda aqui seleccionada.','alegra-connector');?></p>
<?php else:?>
<select name="alegra_connector_currency">
    <option value="COP" <?php selected(get_option('alegra_connector_currency','COP'),'COP');?>>COP - Peso Colombiano</option>
    <option value="USD" <?php selected(get_option('alegra_connector_currency'),'USD');?>>USD - Dolar</option>
    <option value="MXN" <?php selected(get_option('alegra_connector_currency'),'MXN');?>>MXN - Peso Mexicano</option>
    <option value="EUR" <?php selected(get_option('alegra_connector_currency'),'EUR');?>>EUR - Euro</option>
    <option value="ARS" <?php selected(get_option('alegra_connector_currency'),'ARS');?>>ARS - Peso Argentino</option>
</select>
<p class="description"><?php esc_html_e('Conecta con Alegra para ver tus monedas configuradas. Estas son las monedas basicas.','alegra-connector');?></p>
<?php endif;?>
</td></tr></table>
</div>

<!-- ==================== BODEGAS ==================== -->
<div class="ac-tab-content" id="tab-warehouse" style="display:none;">
<h2><?php esc_html_e('Configuración de Bodegas','alegra-connector');?></h2>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Las bodegas permiten gestionar inventario en diferentes ubicaciones. Si no manejas multiples bodegas, puedes dejar esta seccion desactivada y se usara la bodega Principal de Alegra.','alegra-connector');?></div>
<table class="form-table">
<tr><th><?php esc_html_e('Activar gestion de bodegas:','alegra-connector');?></th><td><label><input type="checkbox" name="alegra_connector_warehouse_enabled" value="1" <?php checked(get_option('alegra_connector_warehouse_enabled'),true);?>> <?php esc_html_e('Si','alegra-connector');?></label><p class="description"><?php esc_html_e('Al activarlo, los productos y facturas se asociaran a la bodega seleccionada abajo.','alegra-connector');?></p></td></tr>
<?php if(!empty($alegra_warehouses)):?>
<tr><th><?php esc_html_e('Bodega por defecto:','alegra-connector');?></th><td>
<select name="alegra_connector_warehouse_id">
    <option value="0"><?php esc_html_e('-- Principal (ID: 1) --','alegra-connector');?></option>
    <?php foreach($alegra_warehouses as $wh): $id=(string)($wh['id']??'');?>
    <option value="<?php echo esc_attr($id);?>" <?php selected((string)get_option('alegra_connector_warehouse_id',''),$id);?>><?php echo esc_html(($wh['name']??'').' (ID: '.$id.')');?></option>
    <?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Bodegas sincronizadas desde Alegra. Al crear productos y facturas se usara esta bodega.','alegra-connector');?></p></td></tr>
<?php else:?>
<tr><th><?php esc_html_e('Bodega por defecto:','alegra-connector');?></th><td><input type="text" name="alegra_connector_warehouse_id" value="<?php echo esc_attr(get_option('alegra_connector_warehouse_id',''));?>" class="small-text" placeholder="ID o UUID"><p class="description"><?php esc_html_e('Conecta con Alegra para ver tus bodegas o ingresa el ID manualmente.','alegra-connector');?></p></td></tr>
<?php endif;?>
</table>
</div>

<!-- ==================== FACTURACION ELECTRONICA ==================== -->
<div class="ac-tab-content" id="tab-billing" style="display:none;">
<h2><?php esc_html_e('Facturación electrónica','alegra-connector');?></h2>
<?php
$ac_country = strtoupper(trim((string) get_option('alegra_connector_company_country','')));
$ac_is_colombia = ($ac_country === '') || (strpos($ac_country,'COLOMBIA') !== false) || ($ac_country === 'CO');
$ac_resolution_mode = get_option('alegra_connector_customer_resolution_mode','auto');
?>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Aquí defines a nombre de quién se emiten las facturas, si se envían a la DIAN y qué datos de facturación se le piden al cliente.','alegra-connector');?></div>

<table class="form-table">
<!-- 1a. Customer resolution mode -->
<tr><th><label for="alegra_connector_customer_resolution_mode"><?php esc_html_e('¿A nombre de quién se factura?','alegra-connector');?></label></th><td>
<select id="alegra_connector_customer_resolution_mode" name="alegra_connector_customer_resolution_mode">
<option value="auto" <?php selected($ac_resolution_mode,'auto');?>><?php esc_html_e('Automático (recomendado)','alegra-connector');?></option>
<option value="always_generic" <?php selected($ac_resolution_mode,'always_generic');?>><?php esc_html_e('Siempre Consumidor Final','alegra-connector');?></option>
<option value="require_data" <?php selected($ac_resolution_mode,'require_data');?>><?php esc_html_e('Exigir datos al cliente','alegra-connector');?></option>
</select>
<p class="description" style="margin-top:8px;"><?php esc_html_e('Qué hace cada opción:','alegra-connector');?></p>
<ul class="alegra-mode-legend">
<li><strong><?php esc_html_e('Automático (recomendado):','alegra-connector');?></strong> <?php esc_html_e('Usa los datos de facturación del cliente. Si faltan, factura al Consumidor Final.','alegra-connector');?></li>
<li><strong><?php esc_html_e('Siempre Consumidor Final:','alegra-connector');?></strong> <?php esc_html_e('Todas las facturas se emiten al Consumidor Final. El cliente no recibe factura a su nombre.','alegra-connector');?></li>
<li><strong><?php esc_html_e('Exigir datos al cliente:','alegra-connector');?></strong> <?php esc_html_e('No se puede pagar sin NIT/cédula. No se factura al Consumidor Final.','alegra-connector');?></li>
</ul>
</td></tr>

<!-- 1a-bis. Manual Consumidor Final override -->
<tr><th><?php esc_html_e('Consumidor Final manual:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_consumidor_final_manual_override" value="1" <?php checked(get_option('alegra_connector_consumidor_final_manual_override',false));?>> <?php esc_html_e('Usar un contacto específico como Consumidor Final','alegra-connector');?></label>
<p class="description"><?php esc_html_e('Por defecto el plugin busca en Alegra el contacto "Consumidor Final" por su identificación. Actívalo para forzar otro contacto.','alegra-connector');?></p>
<input type="text" name="alegra_connector_consumidor_final_manual_id" value="<?php echo esc_attr(get_option('alegra_connector_consumidor_final_manual_id',''));?>" class="regular-text" placeholder="<?php esc_attr_e('ID del contacto en Alegra','alegra-connector');?>">
<p class="description"><?php esc_html_e('ID (o UUID) del contacto en Alegra que se usará como Consumidor Final. Solo aplica si la casilla está marcada.','alegra-connector');?></p>
</td></tr>

<!-- 1b. DIAN stamp toggle -->
<tr><th><?php esc_html_e('Emisión ante la DIAN:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_stamp_enabled" value="1" <?php checked(get_option('alegra_connector_stamp_enabled',true));?>> <strong><?php esc_html_e('Emitir facturas ante la DIAN','alegra-connector');?></strong></label>
<p class="description"><?php esc_html_e('Si lo desactivas, las facturas quedan en borrador y NO se envían a la DIAN. Solo desactívalo si estás probando.','alegra-connector');?></p>
<?php if(!$ac_is_colombia):?>
<div class="ac-notice warning" style="margin-top:8px;"><?php esc_html_e('La emisión ante la DIAN solo aplica a cuentas de Colombia. Tu cuenta está configurada en otro país, por lo que esta opción no tendrá efecto.','alegra-connector');?></div>
<?php endif;?>
</td></tr>

<!-- 1c. Dry run toggle -->
<tr><th><?php esc_html_e('Modo de prueba:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_dry_run" value="1" <?php checked(get_option('alegra_connector_dry_run',false));?>> <strong><?php esc_html_e('Modo de prueba (Dry Run)','alegra-connector');?></strong></label>
<div class="ac-notice warning" style="margin-top:8px;"><strong><?php esc_html_e('Atención:','alegra-connector');?></strong> <?php esc_html_e('Con esto activado, el plugin NO envía nada a Alegra. No se crean facturas, clientes ni productos. Ideal para probar la configuración. ACUÉRDATE de desactivarlo.','alegra-connector');?></div>
</td></tr>
</table>

<?php
$ac_catalog = \Alegra\Connector\Billing_Fields::CATALOG;
$ac_groups = ['A'=>__('Obligatorios','alegra-connector'),'B'=>__('Recomendados','alegra-connector'),'C'=>__('Opcionales','alegra-connector')];
$ac_group_hint = [
'A'=>__('Siempre activos: sin ellos la factura electrónica no es válida. No se pueden desactivar.','alegra-connector'),
'B'=>__('Recomendados para facturar a empresas o a personas con nombre y apellido completos.','alegra-connector'),
'C'=>__('Opcionales. Actívalos solo si realmente los usas.','alegra-connector'),
];
$ac_group_badge = ['A'=>'danger','B'=>'warning','C'=>'neutral'];
?>
<h3 style="margin:24px 0 4px 0;"><?php esc_html_e('Campos de facturación','alegra-connector');?></h3>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Activa los campos que se le pedirán al cliente en el checkout y en su cuenta. Los campos obligatorios están fijos para que la facturación nunca se rompa.','alegra-connector');?></div>
<input type="hidden" name="alegra_connector_billing_field_catalog_enabled[__submitted]" value="1">
<div style="margin-bottom:8px;">
<button type="button" class="ac-btn ac-btn-primary" id="alegra-enable-all-fields"><?php esc_html_e('Habilitar todos los campos','alegra-connector');?></button>
<span id="alegra-enable-all-status" style="margin-left:8px;font-size:12px;color:var(--ac-text-secondary);"></span>
</div>
<?php foreach($ac_groups as $ac_gk=>$ac_glabel):?>
<h4 class="alegra-billing-group-title"><?php echo esc_html($ac_glabel);?> <span class="ac-badge <?php echo esc_attr($ac_group_badge[$ac_gk]);?>"><?php echo esc_html($ac_gk);?></span></h4>
<p class="description" style="margin:0;"><?php echo esc_html($ac_group_hint[$ac_gk]);?></p>
<div class="alegra-billing-field-list">
<?php foreach($ac_catalog as $ac_key=>$ac_field): if(($ac_field['group']??'')!==$ac_gk)continue; $ac_locked=($ac_gk==='A'); $ac_enabled=$ac_locked||\Alegra\Connector\Billing_Fields::is_field_enabled($ac_key);?>
<label class="alegra-billing-field-row<?php echo $ac_locked?' is-locked':'';?>">
<input type="checkbox" name="alegra_connector_billing_field_catalog_enabled[<?php echo esc_attr($ac_key);?>]" value="1" <?php checked($ac_enabled);?> <?php disabled($ac_locked);?>>
<?php if($ac_locked):?><input type="hidden" name="alegra_connector_billing_field_catalog_enabled[<?php echo esc_attr($ac_key);?>]" value="1"><?php endif;?>
<span class="alegra-billing-field-info">
<span class="alegra-billing-field-label"><?php echo esc_html((string)$ac_field['label']);?> <?php if($ac_locked):?><span class="alegra-lock-tag"><?php esc_html_e('Obligatorio','alegra-connector');?></span><?php endif;?></span>
<span class="alegra-billing-field-help"><?php echo esc_html((string)$ac_field['help']);?></span>
</span>
</label>
<?php endforeach;?>
</div>
<?php endforeach;?>
</div>

<!-- ==================== AVANZADO ==================== -->
<div class="ac-tab-content" id="tab-advanced" style="display:none;">
<h2><?php esc_html_e('Configuración Avanzada','alegra-connector');?></h2>

<table class="form-table">
<!-- Conflict Resolution -->
<tr><th><?php esc_html_e('Resolucion de conflictos:','alegra-connector');?></th><td>
<select name="alegra_connector_conflict_resolution">
    <option value="alegra_wins" <?php selected(get_option('alegra_connector_conflict_resolution','alegra_wins'),'alegra_wins');?>><?php esc_html_e('Alegra gana (recomendado)','alegra-connector');?></option>
    <option value="woocommerce_wins" <?php selected(get_option('alegra_connector_conflict_resolution'),'woocommerce_wins');?>><?php esc_html_e('WooCommerce gana','alegra-connector');?></option>
</select>
<p class="description"><?php esc_html_e('Si un producto o cliente se edita en AMBAS plataformas entre sincronizaciones, este ajuste define cual version prevalece. Con "Alegra gana", el sistema contable tiene prioridad.','alegra-connector');?></p></td></tr>

<!-- Log Retention -->
<tr><th><?php esc_html_e('Retencion de logs:','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_log_retention_days" value="<?php echo esc_attr(get_option('alegra_connector_log_retention_days',30));?>" class="small-text" min="1" max="365"> <?php esc_html_e('dias','alegra-connector');?>
<p class="description"><?php esc_html_e('Los logs anteriores a este numero de dias se eliminan automaticamente. Los logs se guardan en wp-content/uploads/alegra-logs/.','alegra-connector');?></p></td></tr>

<!-- Bank Account -->
<tr><th><?php esc_html_e('Cuenta bancaria para pagos:','alegra-connector');?></th><td>
<?php if(!empty($alegra_bank_accounts)):?>
<select name="alegra_connector_payment_account_id">
    <option value="0"><?php esc_html_e('-- Sin cuenta (no se registraran pagos) --','alegra-connector');?></option>
    <?php foreach($alegra_bank_accounts as $ba): $id=(string)($ba['id']??'');?>
    <option value="<?php echo esc_attr($id);?>" <?php selected((string)get_option('alegra_connector_payment_account_id',''),$id);?>><?php echo esc_html(($ba['name']??'Banco').' (ID: '.$id.')');?></option>
    <?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Sincronizado desde Alegra. Sin una cuenta seleccionada NO se registraran pagos automaticos en Alegra al completar pedidos.','alegra-connector');?></p>
<?php else:?>
<input type="text" name="alegra_connector_payment_account_id" value="<?php echo esc_attr(get_option('alegra_connector_payment_account_id',''));?>" class="small-text" placeholder="ID o UUID en Alegra">
<p class="description"><?php esc_html_e('ID de la cuenta bancaria en Alegra donde se registraran los pagos. Conecta con Alegra para ver tus cuentas o ingresa el ID manualmente.','alegra-connector');?></p>
<?php endif;?>
</td></tr>

<!-- Payment Term -->
<tr><th><?php esc_html_e('Termino de pago:','alegra-connector');?></th><td>
<?php if(!empty($alegra_terms)):?>
<select name="alegra_connector_payment_term_id">
    <option value="0"><?php esc_html_e('-- Sin termino (15 dias por defecto) --','alegra-connector');?></option>
    <?php foreach($alegra_terms as $tm): $id=(string)($tm['id']??'');?>
    <option value="<?php echo esc_attr($id);?>" <?php selected((string)get_option('alegra_connector_payment_term_id',''),$id);?>><?php echo esc_html(($tm['name']??'Termino').' - '.($tm['days']??0).' '.__('dias','alegra-connector'));?></option>
    <?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Define cuantos dias despues de la fecha de factura vence el pago. Sincronizado desde Alegra.','alegra-connector');?></p>
<?php else:?>
<input type="text" name="alegra_connector_payment_term_id" value="<?php echo esc_attr(get_option('alegra_connector_payment_term_id',''));?>" class="small-text" placeholder="ID o UUID en Alegra">
<p class="description"><?php esc_html_e('ID del termino de pago en Alegra. Conecta con Alegra para ver tus terminos.','alegra-connector');?></p>
<?php endif;?>
</td></tr>
</table>

<!-- ==================== WEBHOOKS ==================== -->
<div class="ac-card" style="margin-top:20px;border-left:4px solid var(--ac-primary);">
<div class="ac-card-header"><h2><?php esc_html_e('Sincronización en Tiempo Real (Webhooks)','alegra-connector');?></h2></div>
<div class="ac-notice info" style="margin-bottom:16px;">
<?php esc_html_e('Los webhooks permiten que Alegra notifique a WooCommerce instantaneamente cuando ocurre un cambio. Sin webhooks, la sincronizacion depende del cron periodico. Se recomienda activar ambos para maxima cobertura.','alegra-connector');?>
</div>

<?php $webhook_url = rest_url('alegra-connector/v1/webhook'); ?>
<table class="form-table">
<tr><th><?php esc_html_e('URL del Webhook:','alegra-connector');?></th>
<td><code style="font-size:12px;word-break:break-all;"><?php echo esc_url($webhook_url); ?></code>
<p class="description"><?php esc_html_e('Esta URL debe configurarse en Alegra. El plugin la registra automaticamente al hacer clic en "Registrar webhooks".','alegra-connector');?></p></td></tr>

<tr><th><?php esc_html_e('Webhook Secret:','alegra-connector');?></th>
<td>
<?php $ac_secret_saved = (string) get_option('alegra_connector_webhook_secret', ''); ?>
<input type="password" name="alegra_connector_webhook_secret" id="alegra_connector_webhook_secret" value="" autocomplete="new-password" data-saved="<?php echo $ac_secret_saved !== '' ? '1' : '0'; ?>" class="regular-text" placeholder="<?php echo esc_attr($ac_secret_saved !== '' ? __('•••••••• (guardado, deja vacio para conservarlo)', 'alegra-connector') : __('Clave HMAC para validar webhooks', 'alegra-connector')); ?>">
<button type="button" class="ac-btn ac-btn-sm" id="toggle-webhook-secret" style="margin-left:4px;"><?php esc_html_e('Mostrar','alegra-connector');?></button>
<p class="description"><?php esc_html_e('Clave secreta para validar que los webhooks vienen de Alegra (HMAC-SHA256). Por seguridad no se vuelve a mostrar: deja el campo vacio para conservar la clave guardada.','alegra-connector');?></p>
</td></tr>

<tr><th><?php esc_html_e('Gestionar suscripciones:','alegra-connector');?></th>
<td>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
<button type="button" class="ac-btn ac-btn-primary" id="alegra-register-webhooks"><?php esc_html_e('Registrar webhooks en Alegra','alegra-connector');?></button>
<button type="button" class="ac-btn ac-btn-danger" id="alegra-delete-webhooks"><?php esc_html_e('Eliminar webhooks en Alegra','alegra-connector');?></button>
<span id="alegra-webhook-status" style="font-size:12px;"></span>
</div>
<p class="description"><?php esc_html_e('Registra automaticamente 6 eventos: creacion/edicion de items, clientes y facturas. Los webhooks se eliminan automaticamente al desconectar.','alegra-connector');?></p>
</td></tr>

<?php $subscriptions = (array) get_option('alegra_connector_webhook_subscriptions', []); if (!empty($subscriptions)): ?>
<tr><th><?php esc_html_e('Suscripciones activas:','alegra-connector');?></th>
<td>
<div style="display:flex;flex-wrap:wrap;gap:4px;">
<?php foreach ($subscriptions as $sub): $ev = esc_html($sub['event'] ?? ''); $sid = esc_html($sub['id'] ?? ''); ?>
<span class="ac-badge success" style="font-size:11px;"><?php echo $ev; ?> <small>(ID: <?php echo $sid; ?>)</small></span>
<?php endforeach; ?>
</div>
</td></tr>
<?php endif; ?>
</table>
</div>

<!-- ==================== CRON POLLING ==================== -->
<div class="ac-card" style="margin-top:20px;border-left:4px solid var(--ac-amber);">
<div class="ac-card-header"><h2><?php esc_html_e('Sincronización Periódica (Cron Polling)','alegra-connector');?></h2></div>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Cuando Alegra detecta un cambio, el plugin actualiza WooCommerce mediante la sincronizacion periodica (cron). Funcióna como respaldo de los webhooks. Configura la frecuencia en la pestana Sincronización.','alegra-connector');?></div>

<table class="form-table">
<tr><th><?php esc_html_e('Auto-completar pedidos:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_auto_complete_order" value="1" <?php checked(get_option('alegra_connector_auto_complete_order',true));?>> <?php esc_html_e('Marcar pedido como Completado cuando la factura se paga en Alegra','alegra-connector');?></label>
<p class="description"><?php esc_html_e('Durante la sincronizacion periodica (cron), si se detecta que una factura fue pagada en Alegra, el pedido WooCommerce se marcara automaticamente como "Completado".','alegra-connector');?></p></td></tr>
</table>
</div>

</div>
<?php submit_button(esc_html__('Guardar Cambios','alegra-connector'));?></form>
<script>
(function($){
    'use strict';
    $(function(){
        $('#alegra-enable-all-fields').on('click', function(){
            var $btn = $(this), $status = $('#alegra-enable-all-status');
            $btn.prop('disabled', true);
            $status.text('<?php echo esc_js(__('Habilitando campos...','alegra-connector'));?>');
            $.ajax({
                url: alegraConnector.ajaxUrl, type: 'POST',
                data: { action: 'alegra_enable_all_billing_fields', _ajax_nonce: alegraConnector.nonce },
                success: function(r){
                    if (r && r.success) {
                        $status.text(r.data && r.data.message ? r.data.message : 'OK');
                        location.reload();
                    } else {
                        $status.text(r && r.data && r.data.message ? r.data.message : 'Error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function(){
                    $status.text('<?php echo esc_js(__('Error de conexión','alegra-connector'));?>');
                    $btn.prop('disabled', false);
                }
            });
        });
    });
})(jQuery);
</script>
<?php include __DIR__ . '/footer.php'; ?></div>