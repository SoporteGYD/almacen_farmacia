<?php
declare(strict_types=1);
/** ERP G&D - Instalador seguro CI V1. Copiar SOLO tools/ en el proyecto. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/ci_payload';
$required=['app/helpers/auth.php','app/config/database.php','public/erp_oc_win.php','public/erp_facturas.php'];
$newFiles=['app/helpers/erp_conciliacion_integral.php','public/erp_conciliacion_integral.php'];
$errors=[];$messages=[];$changes=[];
foreach($required as $path)if(!is_file($root.'/'.$path))$errors[]="Falta $path. Copia la carpeta tools al directorio correcto del ERP.";
foreach($newFiles as $path){
    $source=$payload.'/'.$path;$dest=$root.'/'.$path;
    if(!is_file($source)){$errors[]="Falta el archivo del instalador: $path";continue;}
    $contents=(string)file_get_contents($source);
    if(!is_file($dest)){$changes[$path]=$contents;$messages[]="CREAR $path";}
    elseif(hash_equals(hash('sha256',$contents),hash_file('sha256',$dest)))$messages[]="YA INSTALADO $path";
    else $errors[]="INCOMPATIBLE: $path ya existe con cambios diferentes; no se reemplazará.";
}
function ci_insertar(string $path,string $anchor,string $addition,string $marker,array &$changes,array &$errors,array &$messages,string $root,bool $required=false):void {
    $file=$root.'/'.$path;
    if(!is_file($file)){if($required)$errors[]="Falta $path.";return;}
    $src=$changes[$path]??(string)file_get_contents($file);
    if(str_contains($src,$marker)){$messages[]="YA INSTALADO acceso en $path";return;}
    if(substr_count($src,$anchor)!==1){$m="No se encontró un punto seguro en $path para insertar el acceso.";if($required)$errors[]=$m;else $messages[]='AVISO: '.$m;return;}
    $contents=str_replace($anchor,$anchor.$addition,$src);
    $changes[$path]=$contents;$messages[]="INTEGRAR acceso $path";
}
// La lista cerrada de rutas de Compras debe incluir el nuevo módulo si existe.
$compras='app/helpers/erp_compras_modulos.php';
if(is_file($root.'/'.$compras)){
  ci_insertar($compras,"        'erp_compras_inicio.php'=>true,","\n        'erp_conciliacion_integral.php'=>true, // GYD_CI_RUTA_COMPRAS_V1",'GYD_CI_RUTA_COMPRAS_V1',$changes,$errors,$messages,$root,true);
  ci_insertar($compras,"        'Reportes, costos y alertas' => [","\n            ['titulo'=>'Conciliación Integral OC / Facturas / POS', 'ruta'=>'erp_conciliacion_integral.php', 'detalle'=>'Diferencias, faltantes, recepciones y aplicaciones por orden'], // GYD_CI_MENU_COMPRAS_V1",'GYD_CI_MENU_COMPRAS_V1',$changes,$errors,$messages,$root,false);
}else $messages[]='AVISO: No se encontró menú de Compras específico; el módulo será accesible con su URL si el rol lo permite.';
// Acceso desde la captura de órdenes, sin afectar el formulario.
ci_insertar('public/erp_oc_win.php','<a class="nav-action" href="erp_analisis_compras.php">Análisis de compras</a>', ' <a class="nav-action" href="erp_conciliacion_integral.php">Conciliación integral</a><!-- GYD_CI_MENU_OC_V1 -->','GYD_CI_MENU_OC_V1',$changes,$errors,$messages,$root,false);
// Enlace complementario opcional en el menú global, únicamente si los indicadores existen.
$header='app/views/layouts/header.php';
if(is_file($root.'/'.$header)){
 ci_insertar($header,"\$menuAgregar('🧾 Compras y proveedores', 'Tesorería y Calendario de Pagos', 'erp_tesoreria.php', \$menuEsAdmin || \$menuEsCompras);", "\n\$menuAgregar('🧾 Compras y proveedores', 'Conciliación Integral', 'erp_conciliacion_integral.php', \$menuEsCompras || \$menuEsAdmin); // GYD_CI_MENU_HEADER_V1",'GYD_CI_MENU_HEADER_V1',$changes,$errors,$messages,$root,false);
}
// Comprobar la sintaxis de TODOS los archivos nuevos/modificados antes de tocar el proyecto.
foreach($changes as $path=>$contents){
   $tmp=tempnam(sys_get_temp_dir(),'ci_lint_');if($tmp===false){$errors[]='No se pudo generar el archivo temporal de comprobación.';break;}
   file_put_contents($tmp,$contents);
   $output=[];$rc=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$rc);@unlink($tmp);
   if($rc!==0)$errors[]="Sintaxis PHP incompatible en $path: ".implode(' ',$output);
}
foreach($messages as $line)echo $line."\n";
if($errors){foreach($errors as $e)fwrite(STDERR,"INCOMPATIBLE: $e\n");fwrite(STDERR,"No se ha cambiado ningún archivo.\n");exit(3);}
echo "COMPATIBLE: ".count($changes)." archivo(s) por instalar/actualizar; sin migraciones SQL ni movimientos de inventario.\n";
if($mode==='--check')exit(0);
if(!$changes){echo "YA INSTALADO: sin cambios.\n";exit(0);}
$backup=$root.'/tools/respaldos_centro_conciliacion_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear el directorio de respaldo.\n");exit(4);}
foreach($changes as $path=>$data){
    $source=$root.'/'.$path;
    if(is_file($source)){
      $dest=$backup.'/'.$path;
      if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true)){fwrite(STDERR,"No se pudo crear carpeta de respaldo.\n");exit(4);}
      if(!copy($source,$dest)){fwrite(STDERR,"No se pudo respaldar $path.\n");exit(4);}
    }
}
$installed=[];
try {
 foreach($changes as $path=>$data){
   $dest=$root.'/'.$path;
   if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new RuntimeException('Sin directorio para '.$path);
   $tmp=$dest.'.ci_tmp_'.getmypid();
   if(file_put_contents($tmp,$data,LOCK_EX)===false || !rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('No se pudo copiar '.$path);}
   $installed[]=$path;
 }
} catch(Throwable $e){
 foreach(array_reverse($installed) as $path){$old=$backup.'/'.$path;if(is_file($old))copy($old,$root.'/'.$path);else @unlink($root.'/'.$path);}
 fwrite(STDERR,'ERROR: '.$e->getMessage().". Se intentaron revertir los cambios.\n");exit(5);
}
echo "INSTALADO. Respaldo: $backup\n";
echo "Abre public/erp_conciliacion_integral.php con ADMINISTRADOR o COMPRAS.\n";
