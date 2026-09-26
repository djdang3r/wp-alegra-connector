<?php if(!defined('ABSPATH'))exit;$page_title=__('Configuración','alegra-connector');$page_subtitle=__('Ajusta la conexion y el comportamiento del plugin','alegra-connector');include __DIR__.'/header.php';?>
<div class="alegra-connector-wrap">
<?php if(get_option('alegra_connector_dry_run',false)):?>
<div class="alegra-dryrun-banner">
<span class="alegra-dryrun-badge"><?php esc_html_e('MODO PRUEBA ACTIVADO','alegra-connector');?></span>
<span><?php esc_html_e('El plugin NO envía nada a Alegra: no se crean facturas, clientes ni productos. Desactívalo antes de facturar de verdad.','alegra-connector');?></span>
</div>
<?php endif;?>
<?php settings_errors('alegra_connector_settings');?>
<?php if(isset($_GET['settings-updated']) && $_GET['settings-updated'] && empty(get_settings_errors('alegra_connector_settings'))):?>
<div class="ac-notice success" style="margin-bottom:16px;"><?php esc_html_e('Configuración guardada correctamente.','alegra-connector');?></div>
<?php endif;?>
<form method="post" action="options.php"><?php settings_fields('alegra_connector_settings');?>
<div class="ac-settings-tabs">
<button type="button" class="ac-settings-tab active" data-tab="connection"><?php esc_html_e('Conexión','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="sync"><?php esc_html_e('Sincronización','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="currency"><?php esc_html_e('Moneda','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="warehouse"><?php esc_html_e('Bodegas','alegra-connector');?></button>
<button type="button" class="ac-settings-tab" data-tab="billing"><?php esc_html_e('Datos de facturación','alegra-connector');?></button>
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
<tr><th><?php esc_html_e('Sincronizacion automatica desde Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="radio" name="alegra_connector_sync_method" value="cron" <?php checked(get_option('alegra_connector_sync_method','cron'),'cron');?>> <strong><?php esc_html_e('Periódica (recomendado)','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Trae automaticamente desde Alegra hacia WooCommerce cada X minutos: productos, clientes y el estado de las facturas pagadas. NO afecta la subida de pedidos a Alegra (eso se controla mas abajo).','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="both" <?php checked(get_option('alegra_connector_sync_method','cron'),'both');?>> <strong><?php esc_html_e('Periódica + Tiempo Real','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Combina la traida periodica con webhooks en tiempo real (Alegra avisa a WooCommerce al instante). NO afecta la subida de pedidos a Alegra.','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="real-time" <?php checked(get_option('alegra_connector_sync_method','cron'),'real-time');?>> <strong><?php esc_html_e('Solo Tiempo Real','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Solo webhooks, sin traida periodica. NO afecta la subida de pedidos a Alegra.','alegra-connector');?></p>
<label><input type="radio" name="alegra_connector_sync_method" value="disabled" <?php checked(get_option('alegra_connector_sync_method','cron'),'disabled');?>> <strong><?php esc_html_e('Desactivada','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('No se trae nada desde Alegra automaticamente. La subida de pedidos a Alegra NO se ve afectada: se controla con la opcion "Subir pedidos a Alegra" mas abajo.','alegra-connector');?></p>
</fieldset></td></tr>
<tr><th><label for="alegra_connector_sync_frequency"><?php esc_html_e('Frecuencia periodica:','alegra-connector');?></label></th><td><select id="alegra_connector_sync_frequency" name="alegra_connector_sync_frequency"><option value="5" <?php selected(get_option('alegra_connector_sync_frequency'),5);?>>5 min</option><option value="15" <?php selected(get_option('alegra_connector_sync_frequency',15),15);?>>15 min</option><option value="30" <?php selected(get_option('alegra_connector_sync_frequency'),30);?>>30 min</option><option value="60" <?php selected(get_option('alegra_connector_sync_frequency'),60);?>>1 hora</option></select><p class="description"><?php esc_html_e('Cada cuanto tiempo se ejecuta la sincronizacion automatica. Solo aplica si usas modo Periódica o Ambos.','alegra-connector');?></p></td></tr>
<tr><th><?php esc_html_e('Entidades a sincronizar:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_sync_products" value="1" <?php checked(get_option('alegra_connector_sync_products',false));?>> <?php esc_html_e('Productos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae productos, variaciones e imagenes desde Alegra.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_customers" value="1" <?php checked(get_option('alegra_connector_sync_customers',false));?>> <?php esc_html_e('Clientes','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae contactos desde Alegra a la lista de clientes de WooCommerce.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_orders" value="1" <?php checked(get_option('alegra_connector_sync_orders',false));?>> <?php esc_html_e('Pedidos','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Verifica el estado de las facturas en Alegra y actualiza los pedidos cuando se pagan.','alegra-connector');?></p>
<label><input type="checkbox" name="alegra_connector_sync_categories" value="1" <?php checked(get_option('alegra_connector_sync_categories',false));?>> <?php esc_html_e('Categorías','alegra-connector');?></label><p class="description" style="margin:0 0 6px 24px;"><?php esc_html_e('Trae categorias de items desde Alegra.','alegra-connector');?></p>
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
<tr><th><?php esc_html_e('Fuente de inventario:','alegra-connector');?></th><td><select name="alegra_connector_inventory_source"><option value="alegra" <?php selected(get_option('alegra_connector_inventory_source','alegra'),'alegra');?>><?php esc_html_e('Alegra (recomendado)','alegra-connector');?></option><option value="woocommerce" <?php selected(get_option('alegra_connector_inventory_source'),'woocommerce');?>><?php esc_html_e('WooCommerce','alegra-connector');?></option></select><p class="description"><?php esc_html_e('Con "Alegra", la sincronización periódica importa el stock desde Alegra y sobrescribe el de WooCommerce. Con "WooCommerce", el plugin NO toca el stock de WooCommerce (solo gestionas inventario en tu tienda).','alegra-connector');?></p></td></tr>
<tr><th><?php esc_html_e('Sincronizar inventario:','alegra-connector');?></th><td><label><input type="checkbox" name="alegra_connector_inventory_sync_enabled" value="1" <?php checked(get_option('alegra_connector_inventory_sync_enabled',true));?>> <?php esc_html_e('Traer el stock desde Alegra','alegra-connector');?></label><p class="description"><?php esc_html_e('Independiente de la entidad "Productos". Con la fuente en "Alegra", activa la traida periodica de existencias y sobrescribe el stock de WooCommerce. Al desmarcar, el plugin no trae stock.','alegra-connector');?></p></td></tr>
<tr><th><?php esc_html_e('Gestionar stock en WooCommerce:','alegra-connector');?></th><td><label>
<input type="checkbox" name="alegra_connector_inventory_manage_stock_enabled" value="1" <?php checked(get_option('alegra_connector_inventory_manage_stock_enabled', false)); ?>>
<?php esc_html_e('Gestionar stock en WooCommerce para productos que Alegra marca inventariables', 'alegra-connector'); ?>
</label>
<p class="description"><?php esc_html_e('Si lo activás, el poll habilita la gestión de stock en WC para los productos con inventario en Alegra. Si lo dejás apagado, los productos con "Gestionar stock" desactivado en WC no se tocan (comportamiento actual).', 'alegra-connector'); ?></p></td></tr>
<?php $ac_owner = (string) get_option('alegra_connector_stock_owner', 'auto'); $ac_owner_eff = \Alegra\Connector\Sync\Inventory_Pusher::owner(); ?>
<tr><th><label for="alegra_connector_stock_owner"><?php esc_html_e('Dueño del stock:','alegra-connector');?></label></th><td><select id="alegra_connector_stock_owner" name="alegra_connector_stock_owner"><option value="auto" <?php selected($ac_owner,'auto');?>><?php esc_html_e('Automático (recomendado)','alegra-connector');?></option><option value="invoice" <?php selected($ac_owner,'invoice');?>><?php esc_html_e('Factura','alegra-connector');?></option><option value="adjustment" <?php selected($ac_owner,'adjustment');?>><?php esc_html_e('Ajuste','alegra-connector');?></option></select><p class="description"><?php esc_html_e('Elegí UNO solo. Si elegís los dos, el stock se descuenta dos veces.','alegra-connector');?><br><em><?php echo esc_html(sprintf(__('Dueño efectivo ahora: %s.','alegra-connector'), $ac_owner_eff === 'invoice' ? __('Factura','alegra-connector') : __('Ajuste','alegra-connector')));?></em></p></td></tr>
<tr><th><?php esc_html_e('Enviar stock a Alegra:','alegra-connector');?></th><td><label>
<input type="checkbox" name="alegra_connector_push_inventory_enabled" value="1" <?php checked(get_option('alegra_connector_push_inventory_enabled', true)); ?>>
<?php esc_html_e('Empujar los cambios de stock de WooCommerce a Alegra', 'alegra-connector'); ?>
</label>
<p class="description"><?php esc_html_e('Cuando una venta o edición baja el stock en WooCommerce, el plugin envía el ajuste a Alegra para que no se re-infle en la próxima sincronización. Desactivado, el plugin sólo trae stock desde Alegra.', 'alegra-connector'); ?></p></td></tr>
<tr><th><?php esc_html_e('Subir pedidos a Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_push_orders_enabled" value="1" <?php checked(get_option('alegra_connector_push_orders_enabled',false));?>> <strong><?php esc_html_e('Automatico: subir la factura a Alegra al crear o pagar el pedido','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Por defecto esta DESACTIVADO (modo manual). Desmarcado: ninguna venta se sube sola; tu decides cuando facturar desde "Pedidos" con los botones "Facturar", "Facturar seleccionados" o "Facturar pendientes". Marcado: cada pedido nuevo se sube a Alegra como factura y, al completarse el pago, se registra el pago automaticamente. Esta opcion es independiente del metodo de sincronizacion de arriba.','alegra-connector');?></p>
<?php $ac_owner_ui = (string) get_option('alegra_connector_stock_owner', 'auto'); ?>
<?php if (get_option('alegra_connector_push_orders_enabled', false)): ?>
<?php if ($ac_owner_ui === 'invoice'): ?>
<label><input type="checkbox" name="alegra_connector_open_invoice_on_paid" value="1" checked disabled> <strong><?php esc_html_e('Abrir la factura al pagarse (Alegra descuenta stock)','alegra-connector');?></strong></label>
<input type="hidden" name="alegra_connector_open_invoice_on_paid" value="1">
<?php else: ?>
<label><input type="checkbox" name="alegra_connector_open_invoice_on_paid" value="1" <?php checked(get_option('alegra_connector_open_invoice_on_paid', true)); ?>> <strong><?php esc_html_e('Abrir la factura al pagarse (Alegra descuenta stock)','alegra-connector');?></strong></label>
<?php endif; ?>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Con la factura como dueña del stock, un pedido pagado abre su factura en Alegra para que descuente existencias nativamente; el plugin NO emite ajustes. Si lo desactivás, el plugin emite un ajuste de inventario por cada cambio de stock (la factura queda en borrador y no mueve stock).','alegra-connector');?></p>
<?php endif; ?>
<?php if ($ac_owner_ui === 'invoice'): ?>
<p class="description" style="margin:2px 0 6px 24px;"><strong><?php esc_html_e('Con "Factura", la factura es la única que mueve el stock: el plugin no emite ajustes.','alegra-connector');?></strong></p>
<?php elseif ($ac_owner_ui === 'adjustment'): ?>
<p class="description" style="margin:2px 0 6px 24px;"><?php esc_html_e('Con "Ajuste", la factura queda en borrador; abrirla a mano descuenta dos veces.','alegra-connector');?></p>
<?php endif; ?>
<?php if (get_option('alegra_connector_invoice_status','draft') === 'open'): ?>
<p class="description" style="margin:2px 0 6px 24px;color:var(--ac-danger);"><?php esc_html_e('Ojo: con las facturas en "Abierta", un pedido impago crea una factura que mueve stock antes del pago.','alegra-connector');?></p>
<?php endif; ?>
</fieldset></td></tr>
<tr><th><?php esc_html_e('Subir productos a Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_push_products_enabled" value="1" <?php checked(get_option('alegra_connector_push_products_enabled',false));?>> <strong><?php esc_html_e('Activar envio automatico de productos y clientes a Alegra','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('PRECAUCION: Al activar, cada vez que crees o actualices un producto o cliente en WooCommerce, se enviara automaticamente a Alegra. Esto puede sobrescribir datos existentes. Por defecto esta desactivado. Cuando este toggle esta apagado, solo puedes subir productos a Alegra manualmente desde los botones "Actualizar" de la pagina de detalle del producto, o usando los botones "Enviar seleccionados" del listado de productos.','alegra-connector');?></p>
</fieldset></td></tr>
<tr><th><?php esc_html_e('Subir clientes a Alegra:','alegra-connector');?></th><td><fieldset>
<label><input type="checkbox" name="alegra_connector_push_customers_enabled" value="1" <?php checked(get_option('alegra_connector_push_customers_enabled',false));?>> <strong><?php esc_html_e('Activar envio automatico de clientes a Alegra','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Independiente del envio de productos. Al activar, cada vez que crees o actualices un cliente en WooCommerce se enviara automaticamente a Alegra. Por defecto esta desactivado.','alegra-connector');?></p>
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
<?php
$ac_wh_enabled = (bool) get_option('alegra_connector_warehouse_enabled', false);
$ac_wh_id      = (string) get_option('alegra_connector_warehouse_id', '');
if ($ac_wh_enabled && $ac_wh_id !== '' && $ac_wh_id !== '0'):
?>
<tr><td colspan="2">
<div class="ac-notice warning" style="margin:0;">
<strong><?php esc_html_e('Aviso sobre el inventario por bodega','alegra-connector');?></strong><br>
<?php esc_html_e('Tenés una bodega configurada. Los productos y facturas se envían a esa bodega, pero la sincronización de inventario (Alegra → WooCommerce) todavía lee el stock TOTAL del artículo, no el de la bodega elegida. Si repartís stock entre varias bodegas, el número que ves en WooCommerce puede no coincidir con la bodega configurada.','alegra-connector');?>
</div>
</td></tr>
<?php endif;?>
</table>
</div>

<!-- ==================== DATOS DE FACTURACION ==================== -->
<div class="ac-tab-content" id="tab-billing" style="display:none;">
<h2><?php esc_html_e('Datos de facturación','alegra-connector');?></h2>
<?php
$ac_resolution_mode = get_option('alegra_connector_customer_resolution_mode','auto');
?>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Aquí defines a nombre de quién se crean las facturas en Alegra y qué datos del cliente se le piden en el checkout.','alegra-connector');?></div>

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
<li><strong><?php esc_html_e('Siempre Consumidor Final:','alegra-connector');?></strong> <?php esc_html_e('Todas las facturas se crean a nombre del Consumidor Final. El cliente no recibe factura a su nombre.','alegra-connector');?></li>
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

<!-- 1b. Invoice status -->
<tr><th><label for="alegra_connector_invoice_status"><?php esc_html_e('Estado de las facturas:','alegra-connector');?></label></th><td>
<select id="alegra_connector_invoice_status" name="alegra_connector_invoice_status">
<option value="draft" <?php selected(get_option('alegra_connector_invoice_status','draft'),'draft');?>><?php esc_html_e('Borrador (recomendado)','alegra-connector');?></option>
<option value="open" <?php selected(get_option('alegra_connector_invoice_status','draft'),'open');?>><?php esc_html_e('Abierta','alegra-connector');?></option>
</select>
<p class="description"><?php esc_html_e('Las facturas se crean en Alegra como borrador para que puedas revisarlas antes de emitirlas. Si eliges "Abierta" se crean directamente como facturas abiertas.','alegra-connector');?></p>
<div class="ac-notice warning" style="margin-top:8px;"><strong><?php esc_html_e('Importante (Colombia / DIAN):','alegra-connector');?></strong> <?php esc_html_e('El plugin NO emite las facturas ante la DIAN: nunca envía el timbrado electrónico (stamp.generateStamp). Aunque elijas "Abierta", la factura queda sin emitir electrónicamente en Alegra y debés emitirla/timbrarla manualmente desde Alegra.','alegra-connector');?></div>
</td></tr>

<!-- 1c. Dry run toggle -->
<tr><th><?php esc_html_e('Modo de prueba:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_dry_run" value="1" <?php checked(get_option('alegra_connector_dry_run',false));?>> <strong><?php esc_html_e('Modo de prueba (Dry Run)','alegra-connector');?></strong></label>
<div class="ac-notice warning" style="margin-top:8px;"><strong><?php esc_html_e('Atención:','alegra-connector');?></strong> <?php esc_html_e('Con esto activado, el plugin NO envía nada a Alegra. No se crean facturas, clientes ni productos. Ideal para probar la configuración. ACUÉRDATE de desactivarlo.','alegra-connector');?></div>
</td></tr>
</table>

<?php
$ac_catalog = \Alegra\Connector\Billing_Fields::CATALOG;
?>
<h3 style="margin:24px 0 4px 0;"><?php esc_html_e('Campos de facturación','alegra-connector');?></h3>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('La identificación es el único dato que WooCommerce no recolecta. El tipo y el número de documento se le piden al cliente en el checkout y en su cuenta.','alegra-connector');?></div>
<input type="hidden" name="alegra_connector_billing_field_catalog_enabled[__submitted]" value="1">
<div class="alegra-billing-field-list">
<?php foreach($ac_catalog as $ac_key=>$ac_field): $ac_locked=((($ac_field['group']??'')==='A')); $ac_enabled=$ac_locked||\Alegra\Connector\Billing_Fields::is_field_enabled($ac_key);?>
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
</div>

<!-- ==================== AVANZADO ==================== -->
<div class="ac-tab-content" id="tab-advanced" style="display:none;">
<h2><?php esc_html_e('Configuración Avanzada','alegra-connector');?></h2>

<table class="form-table">
<!-- Conflict Resolution (customers only) -->
<tr><th><?php esc_html_e('Resolucion de conflictos de clientes:','alegra-connector');?></th><td>
<select name="alegra_connector_conflict_resolution">
    <option value="alegra_wins" <?php selected(get_option('alegra_connector_conflict_resolution','alegra_wins'),'alegra_wins');?>><?php esc_html_e('Alegra gana (recomendado)','alegra-connector');?></option>
    <option value="woocommerce_wins" <?php selected(get_option('alegra_connector_conflict_resolution'),'woocommerce_wins');?>><?php esc_html_e('WooCommerce gana','alegra-connector');?></option>
</select>
<p class="description"><?php esc_html_e('Solo aplica a clientes: cuando un cliente ya existe en Alegra (mismo email o NIT), define si se conserva la version de Alegra o la de WooCommerce. No afecta a productos ni pedidos.','alegra-connector');?></p></td></tr>

<!-- Colombia fiscal contact fields -->
<tr><th><?php esc_html_e('Datos fiscales del contacto (Colombia):','alegra-connector');?></th><td>
<?php
$ac_kind_option   = \Alegra\Connector\Billing_Fields::OPTION_KIND_OF_PERSON;
$ac_regime_option = \Alegra\Connector\Billing_Fields::OPTION_REGIME;
$ac_kind_current  = strtoupper((string) get_option($ac_kind_option, ''));
$ac_regime_current = strtoupper((string) get_option($ac_regime_option, 'SIMPLIFIED_REGIME'));
$ac_kind_labels = [
    'PERSON_ENTITY' => __('Persona natural','alegra-connector'),
    'LEGAL_ENTITY'  => __('Persona jurídica','alegra-connector'),
    'OTHER_ENTITY'  => __('Otro tipo de obligado','alegra-connector'),
];
$ac_regime_labels = [
    'SIMPLIFIED_REGIME'              => __('No responsable de IVA (régimen simplificado)','alegra-connector'),
    'COMMON_REGIME'                  => __('Responsable de IVA (régimen común)','alegra-connector'),
    'NATIONAL_CONSUMPTION_TAX'       => __('Impuesto Nacional al Consumo (INC)','alegra-connector'),
    'NOT_REPONSIBLE_FOR_CONSUMPTION' => __('No responsable de consumo (INC)','alegra-connector'),
    'INC_IVA_RESPONSIBLE'            => __('Responsable de IVA e INC','alegra-connector'),
    'SPECIAL_REGIME'                 => __('Régimen especial','alegra-connector'),
];
?>
<label style="display:block;margin-bottom:6px;"><?php esc_html_e('Tipo de persona:','alegra-connector');?>
<select name="<?php echo esc_attr($ac_kind_option); ?>">
    <option value="" <?php selected($ac_kind_current, ''); ?>><?php esc_html_e('Automático (persona natural)','alegra-connector');?></option>
    <?php foreach($ac_kind_labels as $ac_value=>$ac_label): ?>
    <option value="<?php echo esc_attr($ac_value); ?>" <?php selected($ac_kind_current, $ac_value); ?>><?php echo esc_html($ac_label); ?></option>
    <?php endforeach; ?>
</select>
</label>
<label style="display:block;"><?php esc_html_e('Régimen:','alegra-connector');?>
<select name="<?php echo esc_attr($ac_regime_option); ?>">
    <?php foreach($ac_regime_labels as $ac_value=>$ac_label): ?>
    <option value="<?php echo esc_attr($ac_value); ?>" <?php selected($ac_regime_current, $ac_value); ?>><?php echo esc_html($ac_label); ?></option>
    <?php endforeach; ?>
</select>
</label>
<p class="description"><?php esc_html_e('Solo aplica a cuentas de Colombia. Se envían en cada contacto nuevo para cumplir el esquema de Alegra (obligatorios con facturación electrónica). Por defecto: persona natural + régimen simplificado.','alegra-connector');?></p></td></tr>

<!-- Log Retention -->
<tr><th><?php esc_html_e('Retencion de logs:','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_log_retention_days" value="<?php echo esc_attr(get_option('alegra_connector_log_retention_days',30));?>" class="small-text" min="1" max="365"> <?php esc_html_e('dias','alegra-connector');?>
<p class="description"><?php esc_html_e('Los logs anteriores a este numero de dias se eliminan automaticamente. Los logs se guardan en wp-content/uploads/alegra-logs/.','alegra-connector');?></p></td></tr>

<!-- Import time budget -->
<tr><th><?php esc_html_e('Presupuesto de importacion (segundos):','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_import_time_budget" value="<?php echo esc_attr(get_option('alegra_connector_import_time_budget',240));?>" class="small-text" min="30" max="600"> <?php esc_html_e('segundos','alegra-connector');?>
<p class="description"><?php esc_html_e('Tiempo maximo que una importacion de productos usa por corrida. Al agotarse, la importacion se pausa y continua en la siguiente ejecucion.','alegra-connector');?></p></td></tr>

<!-- Chunked page budget -->
<tr><th><?php esc_html_e('Presupuesto por pagina del boton Traer desde Alegra (segundos):','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_chunked_page_budget" value="<?php echo esc_attr(get_option('alegra_connector_chunked_page_budget',20));?>" class="small-text" min="10" max="40"> <?php esc_html_e('segundos','alegra-connector');?>
<p class="description"><?php esc_html_e('Segundos de trabajo por pagina del boton Traer desde Alegra. Al agotarse, la pagina se pausa y continua en la siguiente.','alegra-connector');?></p></td></tr>

<!-- Import max pages -->
<tr><th><?php esc_html_e('Paginas maximas por importacion:','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_import_max_pages" value="<?php echo esc_attr(get_option('alegra_connector_import_max_pages',0));?>" class="small-text" min="0">
<p class="description"><?php esc_html_e('Limite de paginas por corrida. 0 = sin limite (recomendado: la importacion reanuda desde el cursor).','alegra-connector');?></p></td></tr>

<!-- Extra image hosts (D5) -->
<tr><th><?php esc_html_e('Hosts de imágenes extra:','alegra-connector');?></th><td>
<textarea name="alegra_connector_allowed_image_hosts_extra" rows="4" class="large-text code" placeholder="cdn.ejemplo.com"><?php
    echo esc_textarea(implode("\n", (array) get_option('alegra_connector_allowed_image_hosts_extra', [])));
?></textarea>
<p class="description"><?php esc_html_e('Un host por línea, sin https:// (ej: cdn.ejemplo.com). Se permiten además de los hosts de Alegra. No se admite "*" ni http.','alegra-connector');?></p></td></tr>

<!-- Restrict image hosts (2.5.1) -->
<tr><th><?php esc_html_e('Restringir descarga de imágenes:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_restrict_image_hosts" value="1" <?php checked(get_option('alegra_connector_restrict_image_hosts',false));?>> <?php esc_html_e('Restringir la descarga de imágenes a los servidores listados (por defecto se permiten todos los hosts públicos)','alegra-connector');?></label>
<p class="description"><?php esc_html_e('Solo aplica a la lista de hosts de arriba. Los hosts locales o internos (SSRF) se bloquean siempre.','alegra-connector');?></p></td></tr>

<!-- Preserve WooCommerce fields on update -->
<tr><th><?php esc_html_e('Al actualizar productos, conservar de WooCommerce:','alegra-connector');?></th><td><fieldset>
<?php
$ac_preserve = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_preserve_fields(get_option('alegra_connector_import_preserve_fields', []));
$ac_preserve_fields = [
    'description' => __('Descripcion', 'alegra-connector'),
    'name'        => __('Nombre', 'alegra-connector'),
    'price'       => __('Precio', 'alegra-connector'),
    'images'      => __('Imagenes', 'alegra-connector'),
    'inventory'   => __('Inventario / stock', 'alegra-connector'),
    'sku'         => __('SKU / referencia', 'alegra-connector'),
];
?>
<input type="hidden" name="alegra_connector_import_preserve_fields[]" value="">
<?php foreach ($ac_preserve_fields as $ac_key => $ac_label): ?>
<label style="display:block;margin:2px 0;"><input type="checkbox" name="alegra_connector_import_preserve_fields[]" value="<?php echo esc_attr($ac_key); ?>" <?php checked(in_array($ac_key, $ac_preserve, true)); ?>> <?php echo esc_html($ac_label); ?></label>
<?php endforeach; ?>
<p class="description"><?php esc_html_e('Los campos marcados NO se sobrescriben cuando se actualiza un producto que ya existe en WooCommerce. Aplica a la sincronizacion automatica (cron y webhooks) y a la importacion manual. En el modal "Traer desde Alegra" (pagina Productos) puedes cambiarlo solo para esa corrida. Los productos nuevos siempre se crean con todos los datos.','alegra-connector');?></p>
</fieldset></td></tr>

<!-- Orders poll batch -->
<tr><th><?php esc_html_e('Pedidos por revision de estado:','alegra-connector');?></th><td>
<input type="number" name="alegra_connector_orders_poll_batch" value="<?php echo esc_attr(get_option('alegra_connector_orders_poll_batch',20));?>" class="small-text" min="1" max="100">
<p class="description"><?php esc_html_e('Cuantos pedidos revisa cada corrida del cron al consultar el estado de sus facturas en Alegra.','alegra-connector');?></p></td></tr>

<!-- Bank Account -->
<tr><th><?php esc_html_e('Cuenta de destino para pagos (banco o caja):','alegra-connector');?></th><td>
<?php $ac_payment_account=(string)get_option('alegra_connector_payment_account_id','');?>
<?php if(!empty($alegra_bank_accounts) || !in_array($ac_payment_account,['','0'],true)):?>
<select name="alegra_connector_payment_account_id">
    <?php foreach(\Alegra\Connector\Admin\Admin_Dashboard::bank_account_select_options($alegra_bank_accounts,$ac_payment_account) as $opt):?>
    <option value="<?php echo esc_attr($opt['value']);?>" <?php selected($opt['selected']);?>><?php echo esc_html($opt['label']);?></option>
    <?php endforeach;?>
</select>
<p class="description"><?php esc_html_e('Sincronizado desde Alegra (bancos y cajas). Sin una cuenta seleccionada NO se registraran pagos automaticos en Alegra al completar pedidos.','alegra-connector');?></p>
<?php else:?>
<input type="text" name="alegra_connector_payment_account_id" value="<?php echo esc_attr($ac_payment_account);?>" class="small-text" placeholder="ID o UUID en Alegra">
<p class="description"><?php esc_html_e('ID de la cuenta de destino (banco o caja) en Alegra donde se registraran los pagos. Conecta con Alegra para ver tus cuentas o ingresa el ID manualmente.','alegra-connector');?></p>
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

<!-- Payment reconciliation (REQ-CFG-1) -->
<tr><th><?php esc_html_e('Reconciliacion automatica de pagos:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_payment_reconcile_enabled" value="1" <?php checked(get_option('alegra_connector_payment_reconcile_enabled',true));?>> <strong><?php esc_html_e('Registrar automaticamente los pagos de facturas ya vinculadas','alegra-connector');?></strong></label>
<p class="description" style="margin:2px 0 0 24px;"><?php esc_html_e('Activa el barrido horario y los hooks que registran el pago en Alegra cuando una factura vinculada ya fue pagada en WooCommerce. Desactivado, no se registra ningun pago automatico (los pagos manuales siguen disponibles).','alegra-connector');?></p>
</td></tr>
<tr><th><label for="alegra_connector_payment_reconcile_batch"><?php esc_html_e('Pagos por corrida:','alegra-connector');?></label></th><td>
<input type="number" id="alegra_connector_payment_reconcile_batch" name="alegra_connector_payment_reconcile_batch" value="<?php echo esc_attr(get_option('alegra_connector_payment_reconcile_batch',20));?>" class="small-text" min="1" max="100">
<p class="description"><?php esc_html_e('Cuantos pedidos revisa cada corrida del barrido de pagos. Entre 1 y 100.','alegra-connector');?></p></td></tr>
</table>

<!-- ==================== WEBHOOKS ==================== -->
<div class="ac-card" style="margin-top:20px;border-left:4px solid var(--ac-primary);">
<div class="ac-card-header"><h2><?php esc_html_e('Sincronización en Tiempo Real (Webhooks)','alegra-connector');?></h2></div>
<div class="ac-notice info" style="margin-bottom:16px;">
<?php esc_html_e('Los webhooks permiten que Alegra notifique a WooCommerce instantaneamente cuando ocurre un cambio. Sin webhooks, la sincronizacion depende del cron periodico. Se recomienda activar ambos para maxima cobertura.','alegra-connector');?>
</div>

<?php
$ac_webhook_token = (string) get_option(\Alegra\Connector\Webhooks\Receiver::token_option(), '');
$webhook_url = $ac_webhook_token !== ''
    ? add_query_arg('token', $ac_webhook_token, rest_url('alegra-connector/v1/webhook'))
    : rest_url('alegra-connector/v1/webhook');
?>
<table class="form-table">
<tr><th><?php esc_html_e('URL del Webhook:','alegra-connector');?></th>
<td><code style="font-size:12px;word-break:break-all;"><?php echo esc_url($webhook_url); ?></code>
<p class="description"><?php esc_html_e('Esta URL (con su token secreto) debe configurarse en Alegra. El plugin la registra automaticamente al hacer clic en "Registrar webhooks".','alegra-connector');?></p></td></tr>

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
<p class="description"><?php esc_html_e('Registra en Alegra los eventos marcados abajo. Al guardar y volver a registrar, los eventos desmarcados se eliminan en Alegra. Los webhooks se eliminan automaticamente al desconectar.','alegra-connector');?></p>
</td></tr>

<tr><th><?php esc_html_e('Eventos a suscribir:','alegra-connector');?></th>
<td><fieldset>
<input type="hidden" name="alegra_connector_webhook_selected_events[]" value="">
<div style="margin-bottom:6px;display:flex;gap:8px;flex-wrap:wrap;">
<button type="button" class="ac-btn ac-btn-sm" id="alegra-webhook-events-select-all"><?php esc_html_e('Seleccionar todo','alegra-connector');?></button>
<button type="button" class="ac-btn ac-btn-sm" id="alegra-webhook-events-select-none"><?php esc_html_e('Quitar todo','alegra-connector');?></button>
</div>
<?php
$ac_selected_events = \Alegra\Connector\Webhooks\Receiver::selected_events();
$ac_event_labels = \Alegra\Connector\API\Client::get_webhook_event_labels();
foreach ($ac_event_labels as $ac_slug => $ac_label): ?>
<label style="display:block;margin:2px 0;"><input type="checkbox" class="alegra-webhook-event-check" name="alegra_connector_webhook_selected_events[]" value="<?php echo esc_attr($ac_slug); ?>" <?php checked(in_array($ac_slug, $ac_selected_events, true)); ?>> <code><?php echo esc_html($ac_slug); ?></code> &mdash; <?php echo esc_html($ac_label); ?></label>
<?php endforeach; ?>
<p class="description"><?php esc_html_e('Se registran todos por defecto. Desmarca los que no quieras recibir.','alegra-connector');?></p>
</fieldset></td></tr>

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
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Cuando Alegra detecta un cambio, el plugin actualiza WooCommerce a través de la sincronizacion periodica (cron). Funcióna como respaldo de los webhooks. Configura la frecuencia en la pestana Sincronización.','alegra-connector');?></div>

<table class="form-table">
<tr><th><?php esc_html_e('Auto-completar pedidos:','alegra-connector');?></th><td>
<label><input type="checkbox" name="alegra_connector_auto_complete_order" value="1" <?php checked(get_option('alegra_connector_auto_complete_order',true));?>> <?php esc_html_e('Marcar pedido como Completado cuando la factura se paga en Alegra','alegra-connector');?></label>
<p class="description"><?php esc_html_e('Durante la sincronizacion periodica (cron), si se detecta que una factura fue pagada en Alegra, el pedido WooCommerce se marcara automaticamente como "Completado".','alegra-connector');?></p></td></tr>
</table>
</div>

<!-- ==================== CRON REAL (RECOMENDADO) ==================== -->
<div class="ac-card" style="margin-top:20px;border-left:4px solid var(--ac-amber);">
<div class="ac-card-header"><h2><?php esc_html_e('Sincronización con cron real (recomendado)','alegra-connector');?></h2></div>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Por defecto, WordPress dispara el cron cuando alguien visita el sitio. En tiendas con poco tráfico la sincronización puede demorar horas. Un cron real del sistema la ejecuta a horario.','alegra-connector');?></div>

<p><?php esc_html_e('1) Desactivá el cron por visitas: agregá en wp-config.php','alegra-connector');?></p>
<pre><code>define('DISABLE_WP_CRON', true);</code></pre>

<p><?php esc_html_e('2) Agregá esta línea a tu crontab (crontab -e), reemplazando la ruta si tu hosting la cambia:','alegra-connector');?></p>
<pre><code>*/15 * * * * wget -q -O - <?php echo esc_html(home_url('/wp-cron.php')); ?>?doing_wp_cron &gt;/dev/null 2&gt;&amp;1</code></pre>

<p class="description"><?php esc_html_e('Si tu host permite PHP CLI:','alegra-connector');?></p>
<pre><code>*/15 * * * * cd /ruta/a/wordpress &amp;&amp; wp cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></pre>
</div>

</div>
<?php submit_button(esc_html__('Guardar Cambios','alegra-connector'));?></form>
<?php include __DIR__ . '/footer.php'; ?></div>
