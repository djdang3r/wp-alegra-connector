<?php if(!defined('ABSPATH'))exit;$page_title=__('Importar Datos','alegra-connector');$page_subtitle=__('Importa productos y clientes desde CSV o directamente desde Alegra','alegra-connector');$connected=(bool)get_option('alegra_connector_connection_tested');$header_color='green';include __DIR__.'/header.php';?>
<div class="alegra-connector-wrap">

<!-- How It Works -->
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-primary);">
    <div class="ac-card-header"><h2><?php esc_html_e('Como funciona la importacion?','alegra-connector');?></h2></div>
    <p style="font-size:13px;color:var(--ac-text-secondary);line-height:1.6;">
        <?php esc_html_e('Tienes dos formas de importar datos a WooCommerce:','alegra-connector');?>
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;">
        <div style="background:var(--ac-surface-alt);padding:14px 16px;border-radius:8px;">
            <strong style="font-size:13px;"><?php esc_html_e('1. Desde archivo CSV','alegra-connector');?></strong>
            <p style="font-size:12px;color:var(--ac-text-secondary);margin:4px 0 0 0;"><?php esc_html_e('Sube un archivo CSV con tus productos o clientes. Util para migraciones iniciales o cargas masivas de datos.','alegra-connector');?></p>
        </div>
        <div style="background:var(--ac-surface-alt);padding:14px 16px;border-radius:8px;">
            <strong style="font-size:13px;"><?php esc_html_e('2. Desde Alegra API','alegra-connector');?></strong>
            <p style="font-size:12px;color:var(--ac-text-secondary);margin:4px 0 0 0;"><?php esc_html_e('Descarga todos tus productos, clientes y categorias directamente desde tu cuenta Alegra. Requiere conexion activa.','alegra-connector');?></p>
        </div>
    </div>
</div>

<!-- CSV Import -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Importar desde archivo CSV','alegra-connector');?></h2></div>

    <div class="ac-notice info" style="margin-bottom:16px;">
        <strong><?php esc_html_e('Formato del CSV:','alegra-connector');?></strong>
        <?php esc_html_e('El archivo debe tener encabezados en la primera fila. Para productos: name, reference, price, description. Para clientes: name, email, phone. Las columnas deben coincidir exactamente.','alegra-connector');?>
    </div>

    <div class="ac-form-group">
        <label for="import-type" style="font-weight:600;"><?php esc_html_e('Que deseas importar?','alegra-connector');?></label>
        <select id="import-type" name="import_type" style="max-width:300px;">
            <option value="products"><?php esc_html_e('Productos - Crea productos en WooCommerce','alegra-connector');?></option>
            <option value="customers"><?php esc_html_e('Clientes - Crea clientes en WooCommerce','alegra-connector');?></option>
        </select>
        <p class="description"><?php esc_html_e('Seleccióna el tipo de datos que contiene tu archivo CSV.','alegra-connector');?></p>
    </div>

    <div class="ac-form-group">
        <label for="csv-file" style="font-weight:600;"><?php esc_html_e('Seleccióna el archivo CSV:','alegra-connector');?></label>
        <input type="file" id="csv-file" name="csv_file" accept=".csv">
        <p class="description"><?php esc_html_e('Solo se aceptan archivos .csv con encoding UTF-8.','alegra-connector');?></p>
    </div>

    <button type="button" class="ac-btn ac-btn-primary" id="alegra-import-btn">
        <span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;"></span>
        <?php esc_html_e('Subir e Importar','alegra-connector');?>
    </button>
    <div id="alegra-import-progress" style="display:none;margin-top:12px;">
        <p id="alegra-import-status" style="font-size:13px;margin-bottom:6px;"><?php esc_html_e('Procesando...','alegra-connector');?></p>
        <div style="height:6px;background:var(--ac-surface-alt);border-radius:3px;overflow:hidden;"><div id="alegra-import-bar" style="height:100%;background:var(--ac-primary);border-radius:3px;width:0%;transition:width 0.5s ease;"></div></div>
    </div>
</div>

<!-- API Import -->
<div class="ac-card" style="margin-bottom:16px;">
    <div class="ac-card-header"><h2><?php esc_html_e('Importar desde Alegra','alegra-connector');?></h2>
        <?php if($connected):?><span class="ac-badge success"><?php esc_html_e('Conectado','alegra-connector');?></span><?php else:?><span class="ac-badge warning"><?php esc_html_e('No conectado','alegra-connector');?></span><?php endif;?>
    </div>

    <?php if(!$connected):?>
    <div class="ac-notice warning" style="margin-bottom:16px;">
        <?php esc_html_e('Necesitas conectar con Alegra para usar esta opcion. Ve a Configuración > Conexión para ingresar tus credenciales.','alegra-connector');?>
    </div>
    <?php else:?>
    <div class="ac-notice info" style="margin-bottom:16px;">
        <?php esc_html_e('Esto descargara los datos desde tu cuenta Alegra y los creara o actualizara en WooCommerce. Los productos existentes se actualizaran si coinciden por SKU o ID Alegra. Los clientes se actualizaran si coinciden por email.','alegra-connector');?>
    </div>
    <?php endif;?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="ac-btn alegra-import-from-api" data-type="products" <?php echo !$connected?'disabled':'';?>>
            <span class="dashicons dashicons-products" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Productos','alegra-connector');?>
        </button>
        <button type="button" class="ac-btn alegra-import-from-api" data-type="customers" <?php echo !$connected?'disabled':'';?>>
            <span class="dashicons dashicons-groups" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Clientes','alegra-connector');?>
        </button>
        <button type="button" class="ac-btn alegra-import-from-api" data-type="categories" <?php echo !$connected?'disabled':'';?>>
            <span class="dashicons dashicons-category" style="font-size:16px;width:16px;height:16px;"></span>
            <?php esc_html_e('Categorías','alegra-connector');?>
        </button>
        <span id="alegra-api-import-status" style="font-size:13px;color:var(--ac-primary);display:none;"><?php esc_html_e('Importando...','alegra-connector');?></span>
    </div>
</div>

<!-- CSV Templates -->
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-amber);">
    <div class="ac-card-header"><h2><?php esc_html_e('Plantillas CSV','alegra-connector');?></h2></div>
    <p style="font-size:13px;color:var(--ac-text-secondary);margin-bottom:12px;"><?php esc_html_e('Descarga plantillas CSV de ejemplo para asegurar el formato correcto:','alegra-connector');?></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="#" class="ac-btn ac-btn-sm" id="alegra-download-template-products" onclick="downloadTemplate('products');return false;">
            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Plantilla Productos','alegra-connector');?>
        </a>
        <a href="#" class="ac-btn ac-btn-sm" id="alegra-download-template-customers" onclick="downloadTemplate('customers');return false;">
            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span>
            <?php esc_html_e('Plantilla Clientes','alegra-connector');?>
        </a>
    </div>
</div>

<?php include __DIR__.'/footer.php'; ?>
</div>

<script>
function downloadTemplate(type){
    var csv, filename;
    if(type==='products'){
        csv='name,reference,price,description\nEjemplo Producto,REF-001,50000,Descripción del producto\n';
        filename='alegra-template-productos.csv';
    }else{
        csv='name,email,phone\nJuan Perez,juan@email.com,3001234567\n';
        filename='alegra-template-clientes.csv';
    }
    var blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
    var link=document.createElement('a');
    link.href=URL.createObjectURL(blob);
    link.download=filename;
    link.click();
}

jQuery(function($){
    function showNotice(msg,type){var n=$('<div class="ac-notice '+(type||'info')+'">'+msg+'</div>').hide();$('.alegra-connector-wrap').first().prepend(n);n.slideDown(200);setTimeout(function(){n.slideUp(300,function(){$(this).remove();});},6000);}
    function safeMsg(r,f){return(r&&r.data&&r.data.message)||f||'Error desconocido';}

    $('#alegra-import-btn').on('click',function(){
        var $btn=$(this);
        var fileInput=$('#csv-file')[0];
        if(!fileInput.files.length){showNotice('Seleccióna un archivo CSV','warning');return;}
        var formData=new FormData();
        formData.append('action','alegra_import_csv');formData.append('_ajax_nonce',alegraConnector.nonce);
        formData.append('import_type',$('#import-type').val());formData.append('csv_file',fileInput.files[0]);
        $btn.prop('disabled',true).text('Importando...');
        $('#alegra-import-progress').show();$('#alegra-import-bar').css('width','30%');
        $.ajax({url:alegraConnector.ajaxUrl,type:'POST',data:formData,processData:false,contentType:false,
            success:function(r){$('#alegra-import-bar').css('width','100%');
                if(r.success){$('#alegra-import-status').text(safeMsg(r,'Importación completada'));}
                else{showNotice(safeMsg(r,'Error en la importacion'),'error');$('#alegra-import-status').text('Error');}
                $btn.prop('disabled',false).text('Subir e Importar');},
            error:function(){showNotice('Error de conexion','error');$btn.prop('disabled',false).text('Subir e Importar');}
        });
    });

    $('.alegra-import-from-api').on('click',function(){
        var $btn=$(this);var type=$(this).data('type');
        if(!confirm('Estas seguro de importar desde Alegra? '+type+'?'))return;
        $btn.prop('disabled',true);$('#alegra-api-import-status').show().text('Importando '+type+'...');
        $.ajax({url:alegraConnector.ajaxUrl,type:'POST',
            data:{action:'alegra_import_from_api',_ajax_nonce:alegraConnector.nonce,import_type:type},
            success:function(r){$('#alegra-api-import-status').text(safeMsg(r,'Importación completada'));
                if(r.success)setTimeout(function(){location.reload();},2000);else showNotice(safeMsg(r,'Error'),'error');
                $btn.prop('disabled',false);},
            error:function(){showNotice('Error de conexion','error');$btn.prop('disabled',false);}
        });
    });
});
</script>