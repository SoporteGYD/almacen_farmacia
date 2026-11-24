<?php
declare(strict_types=1);
/** Instalador incremental G&D, 23/10/2026. NO altera stock, costos, precios ni facturas. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo PHP CLI');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply','--check-files'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_seguimiento_alertas_compras.php --check | --apply | --check-files\n");exit(2);
}
$root=dirname(__DIR__);$sources=__DIR__.'/seguimiento_alertas_fuentes';
$errors=[];$notes=[];$pending=[];$original=[];
$required=[
 'app/helpers/erp_alertas_compras.php',
 'app/helpers/erp_comparador_proveedores.php',
 'app/helpers/erp_centro_notificaciones.php',
 'app/helpers/erp_compras_modulos.php',
 'public/erp_alertas_compras.php',
 'public/erp_notificaciones.php',
 'public/erp_compras_inicio.php',
];
$newFiles=[
 'app/helpers/erp_compras_alertas_seguimiento.php',
 'public/erp_compras_alertas_seguimiento.php',
];
foreach($required as $rel){
 if(!is_file($root.'/'.$rel)){$errors[]="Falta $rel. Instala primero Centro de Notificaciones, Comparador, Alertas y permisos de COMPRAS.";continue;}
 $original[$rel]=(string)file_get_contents($root.'/'.$rel);
}
foreach($newFiles as $rel){
 $src=$sources.'/'.$rel;$dest=$root.'/'.$rel;
 if(!is_file($src)){$errors[]="El ZIP no contiene $rel";continue;}
 $data=(string)file_get_contents($src);
 if(is_file($dest)){
  if(hash_file('sha256',$dest)===hash('sha256',$data)){$notes[]="YA INSTALADO $rel";continue;}
  $errors[]="INCOMPATIBLE: $rel existe y es diferente. No se reemplazará.";
 }else{$pending[$rel]=$data;$notes[]="CREAR $rel";}
}
function gdca_patch(string $rel,string $old,string $new,string $description,array &$original,array &$pending,array &$errors,array &$notes):void {
 if(!isset($original[$rel]))return;
 $text=$pending[$rel]??$original[$rel];
 if(str_contains($text,$new)){$notes[]="YA INSTALADO $description";return;}
 if(substr_count($text,$old)!==1){$errors[]="INCOMPATIBLE $rel ($description): punto de inserción ausente o duplicado.";return;}
 $pending[$rel]=str_replace($old,$new,$text);
 $notes[]="ACTUALIZAR $description";
}
// Conservar en cada alerta el ID inequívoco del proveedor y de la factura.
$old="      'codigo'=>\$p['codigo'],'descripcion'=>\$p['descripcion'],'proveedor'=>\$provider['proveedor']??'—',";
$new="      'codigo'=>\$p['codigo'],'descripcion'=>\$p['descripcion'],'proveedor_id'=>(int)(\$provider['id']??0),'factura_id'=>(int)(\$provider['factura_id']??0),'proveedor'=>\$provider['proveedor']??'—',";
gdca_patch('app/helpers/erp_alertas_compras.php',$old,$new,'vínculos documentales de alertas',$original,$pending,$errors,$notes);
// Integración en el centro de notificaciones con filtro de rol y tolerancia a fallos.
$old="    \$userId=(int)\$user['id'];";
$new=<<<'GDCA'
    // GYD_SEGUIMIENTO_COMPRAS_NOTIFICACIONES_V1
    // Los avisos financieros sólo llegan a Administración/Compras; no se amplían permisos de Almacén ni POS.
    if ($isAdmin || $rol==='COMPRAS') {
        require_once __DIR__.'/erp_compras_alertas_seguimiento.php';
        try { $items=array_merge($items,gdca_notification_items($pdo,$user)); }
        catch(Throwable $e){error_log('G&D avisos financieros: '.$e->getMessage());}
    }
    $userId=(int)$user['id'];
GDCA;
gdca_patch('app/helpers/erp_centro_notificaciones.php',$old,$new,'notificaciones financieras por usuario',$original,$pending,$errors,$notes);
// Marcar como leído NO modifica el estado de la alerta (tarea abierta).
$old="'/^(RECEPCION|OC_BODEGA):([1-9][0-9]*)$/D'";
$new="'/^(RECEPCION|OC_BODEGA|ALERTA_COMPRA):([1-9][0-9]*)$/D'";
gdca_patch('public/erp_notificaciones.php',$old,$new,'marcado de lectura de avisos financieros',$original,$pending,$errors,$notes);
// Mantener el control de rutas COMPRAS: una única página extra.
$old="        'erp_alertas_compras.php'=>true,";
$new="        'erp_alertas_compras.php'=>true,\n        // GYD_SEGUIMIENTO_COMPRAS_RUTA_V1\n        'erp_compras_alertas_seguimiento.php'=>true,";
gdca_patch('app/helpers/erp_compras_modulos.php',$old,$new,'ruta protegida para COMPRAS',$original,$pending,$errors,$notes);
$old="            ['titulo'=>'Alertas inteligentes', 'ruta'=>'erp_alertas_compras.php', 'detalle'=>'Aumentos, margen bajo, pérdidas y oportunidades de ahorro'],";
$new=$old."\n            // GYD_SEGUIMIENTO_COMPRAS_MENU_V1\n            ['titulo'=>'Centro de seguimiento de alertas', 'ruta'=>'erp_compras_alertas_seguimiento.php', 'detalle'=>'Asignar, revisar y cerrar incidencias con historial'],";
gdca_patch('app/helpers/erp_compras_modulos.php',$old,$new,'tarjeta en el inicio de COMPRAS',$original,$pending,$errors,$notes);
$old='<nav><a href="erp_catalogo_comparar_proveedores.php">';
$new='<nav><a href="erp_compras_alertas_seguimiento.php">Seguimiento de alertas</a> <a href="erp_catalogo_comparar_proveedores.php">';
gdca_patch('public/erp_alertas_compras.php',$old,$new,'enlace desde Alertas inteligentes',$original,$pending,$errors,$notes);
// Complemento opcional al menú de escritorio; si ha cambiado no impedir instalación.
$header='app/views/layouts/header.php';
if(is_file($root.'/'.$header)){
 $original[$header]=(string)file_get_contents($root.'/'.$header);
 $old="\$menuAgregar('🧾 Compras y proveedores', 'Alertas Inteligentes', 'erp_alertas_compras.php', \$menuEsCompras || \$menuEsAdmin);";
 $new=$old."\n// GYD_SEGUIMIENTO_COMPRAS_HEADER_V1\n\$menuAgregar('🧾 Compras y proveedores', 'Seguimiento de Alertas', 'erp_compras_alertas_seguimiento.php', \$menuEsCompras || \$menuEsAdmin);";
 if(str_contains($original[$header],'GYD_SEGUIMIENTO_COMPRAS_HEADER_V1'))$notes[]='YA INSTALADO menú global';
 elseif(substr_count($original[$header],$old)===1){$pending[$header]=str_replace($old,$new,$original[$header]);$notes[]='ACTUALIZAR menú global';}
 else $notes[]='AVISO: menú global personalizado. El módulo sí aparecerá en el inicio de COMPRAS.';
}
// Exigir guardas de autenticación en endpoints y evitar la edición silenciosa de productos.
foreach($newFiles as $rel){
 $body=$pending[$rel]??(is_file($root.'/'.$rel)?file_get_contents($root.'/'.$rel):'');
 if($rel==='public/erp_compras_alertas_seguimiento.php'&&(!str_contains($body,'requireLogin();')||!str_contains($body,'gdca_authorized($user)')))
     $errors[]='Se detectó página nueva sin controles de acceso obligatorios.';
}
foreach($pending as $rel=>$data){
 if(!str_ends_with($rel,'.php'))continue;
 $temp=tempnam(sys_get_temp_dir(),'gydseg_');
 if($temp===false){$errors[]='No se pudo validar sintaxis: sin archivo temporal';continue;}
 file_put_contents($temp,$data);
 $out=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1',$out,$code);@unlink($temp);
 if($code!==0)$errors[]="Error PHP en $rel: ".implode(' ',$out);
}
foreach($notes as $n)echo $n."\n";
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE: sin modificar archivos ni BD.\n");exit(3);}
if($mode==='--check-files'){echo "ARCHIVOS COMPATIBLES: ".count($pending)." a crear/modificar. No se consultó MariaDB.\n";exit(0);}
// Validar conexión y estructura antes de crear tablas. Compatible con MariaDB: information_schema, no SHOW TABLES LIKE ?.
try{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();
 $tables=['erp_facturas','erp_facturas_detalle','productos','proveedores','usuarios','erp_notificaciones_lecturas'];
 $chk=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 foreach($tables as $table){$chk->execute([$table]);if((int)$chk->fetchColumn()===0)$errors[]="No existe tabla requerida: $table";}
 $fields=[
  'erp_facturas'=>['id','proveedor_id','fecha_factura','estado','numero_factura'],
  'erp_facturas_detalle'=>['factura_id','producto_id','costo_unitario','cantidad_facturada'],
  'productos'=>['id','codigo','codigo_barras','descripcion','precio_venta','costo_ultimo','costo_promedio'],
  'usuarios'=>['id','rol','estado','nombre'],
 ];
 $cc=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
 foreach($fields as $table=>$cols)foreach($cols as $col){$cc->execute([$table,$col]);if((int)$cc->fetchColumn()===0)$errors[]="Falta columna $table.$col";}
 // Evitar usar una tabla ya existente con esquema incompatible.
 foreach(['erp_compras_alerta_casos'=>['id','huella','estado','vigente','asignado_a'],
           'erp_compras_alerta_historial'=>['id','caso_id','usuario_id'],
           'erp_compras_alerta_config'=>['llave','valor']] as $table=>$cols){
  $chk->execute([$table]);if((int)$chk->fetchColumn()===0)continue;
  foreach($cols as $col){$cc->execute([$table,$col]);if((int)$cc->fetchColumn()===0)$errors[]="Tabla $table existente con estructura incompatible: falta $col";}
 }
}catch(Throwable $e){$errors[]='No se pudo comprobar MariaDB: '.$e->getMessage();}
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e."\n");exit(3);}
echo "COMPATIBLE: ".count($pending)." archivos nuevos/actualizados y tres tablas técnicas de seguimiento.\n";
if($mode==='--check')exit(0);
$backup=$root.'/tools/respaldos_seguimiento_alertas_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!@mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear carpeta de respaldos.\n");exit(4);}
foreach($pending as $rel=>$content){
 if(!is_file($root.'/'.$rel))continue;
 $target=$backup.'/'.$rel;
 if(!is_dir(dirname($target))&&!@mkdir(dirname($target),0700,true)){fwrite(STDERR,"Error creando respaldo de $rel\n");exit(4);}
 if(!@copy($root.'/'.$rel,$target)){fwrite(STDERR,"No se pudo respaldar $rel. No se aplicaron cambios.\n");exit(4);}
}
$written=[];
try{
 // Solo DDL nuevo; estas tablas no tienen procedimientos ni triggers sobre el inventario.
 $db->exec("CREATE TABLE IF NOT EXISTS erp_compras_alerta_casos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  producto_id BIGINT NOT NULL,codigo VARCHAR(100) NOT NULL,descripcion VARCHAR(350) NOT NULL,
  tipo VARCHAR(24) NOT NULL,nivel VARCHAR(12) NOT NULL,
  sucursal_id INT NOT NULL DEFAULT 0,sucursal_nombre VARCHAR(150) NOT NULL,
  proveedor_id INT NOT NULL DEFAULT 0,proveedor VARCHAR(255) NOT NULL,
  factura_id BIGINT NOT NULL DEFAULT 0,factura_numero VARCHAR(140) NOT NULL,
  fecha_factura DATE NULL,costo DECIMAL(18,4) NULL,precio DECIMAL(18,4) NULL,variacion DECIMAL(12,4) NULL,
  descripcion_alerta TEXT NOT NULL,estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  vigente TINYINT(1) NOT NULL DEFAULT 1,asignado_a INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detectado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL,cerrado_en DATETIME NULL,
  KEY idx_estado_vigente (vigente,estado,nivel),KEY idx_asignado (asignado_a,estado),KEY idx_producto (producto_id),KEY idx_fecha (creado_en)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS erp_compras_alerta_historial (
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   caso_id BIGINT UNSIGNED NOT NULL,usuario_id INT NOT NULL,usuario_nombre VARCHAR(150) NOT NULL,
   accion VARCHAR(28) NOT NULL,estado_anterior VARCHAR(20) NOT NULL,estado_nuevo VARCHAR(20) NOT NULL,
   asignado_anterior INT NULL,asignado_nuevo INT NULL,observacion TEXT NULL,
   creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   KEY idx_caso_hist (caso_id,creado_en),KEY idx_usuario_hist (usuario_id,creado_en),
   CONSTRAINT fk_gyd_alertas_caso FOREIGN KEY(caso_id) REFERENCES erp_compras_alerta_casos(id) ON DELETE RESTRICT
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS erp_compras_alerta_config (
   llave VARCHAR(60) NOT NULL PRIMARY KEY,valor VARCHAR(120) NOT NULL
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 foreach($pending as $rel=>$data){
   $target=$root.'/'.$rel;
   if(!is_dir(dirname($target))&&!@mkdir(dirname($target),0755,true))throw new RuntimeException('No se pudo crear directorio de '.$rel);
   $tmp=$target.'.gydseguimiento_'.getmypid();
   if(file_put_contents($tmp,$data,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);
   if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('No se pudo actualizar '.$rel);}
   $written[]=$rel;
 }
}catch(Throwable $e){
 foreach(array_reverse($written) as $rel){$backupPath=$backup.'/'.$rel;if(is_file($backupPath))@copy($backupPath,$root.'/'.$rel);else @unlink($root.'/'.$rel);}
 fwrite(STDERR,'ERROR: '.$e->getMessage().". Se intentó restaurar archivos; las tablas nuevas vacías pueden permanecer.\n");exit(5);
}
echo "INSTALADO: ".count($written)." archivos. Respaldo: $backup\n";
echo "Abre public/erp_compras_alertas_seguimiento.php con COMPRAS o ADMINISTRADOR.\n";
