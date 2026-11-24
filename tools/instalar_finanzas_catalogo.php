<?php
declare(strict_types=1);
/** Instalación atómica de análisis financiero de catálogo. Solo CLI. No ejecuta SQL de escritura. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$accion=$argv[1]??'--check';
if(!in_array($accion,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_finanzas_catalogo.php --check | --apply\n");exit(2);}
$raiz=dirname(__DIR__);
$plantillas=__DIR__.'/finanzas_catalogo_files';
$nuevos=['app/helpers/erp_catalogo_finanzas.php','public/erp_catalogo_finanzas.php'];
$porHacer=[];$mensajes=[];$errores=[];
foreach($nuevos as $ruta){
  $orig=$plantillas.'/'.$ruta;$dest=$raiz.'/'.$ruta;
  if(!is_file($orig)){$errores[]="Falta archivo de origen: $ruta";continue;}
  $contenido=(string)file_get_contents($orig);
  if(is_file($dest)){
    if(hash_equals(hash('sha256',$contenido),hash_file('sha256',$dest)))$mensajes[]="YA INSTALADO: $ruta";
    else $errores[]="INCOMPATIBLE: $ruta ya existe y tiene cambios locales; no se sobrescribe.";
  }else {$porHacer[$ruta]=$contenido;$mensajes[]="CREAR: $ruta";}
}
$catalogo='public/erp_catalogo_maestro.php';
if(!is_file($raiz.'/'.$catalogo))$errores[]="Falta $catalogo";
else {
 $raw=(string)file_get_contents($raiz.'/'.$catalogo);
 if(str_contains($raw,'href="erp_catalogo_finanzas.php"'))$mensajes[]="YA INSTALADO: enlace en Catálogo Maestro";
 else {
  $target='</header>';
  if(substr_count($raw,$target)!==1 || !str_contains($raw,'$puedeEditar'))$errores[]="INCOMPATIBLE: encabezado o variable de permisos cambiados en $catalogo";
  else{
   $link='<?php if($puedeEditar):?><a href="erp_catalogo_finanzas.php" style="background:#fff0d7;color:#744800">💰 Costos y rentabilidad</a><?php endif;?>';
   $porHacer[$catalogo]=str_replace($target,$link.$target,$raw);
   $mensajes[]='ACTUALIZAR: enlace financiero solo para Administrador en Catálogo Maestro';
  }
 }
 if(!str_contains($raw,'class="finance-product"') && !isset($errores[$catalogo])){
  $actual=$porHacer[$catalogo]??$raw;
  $ancla='<?php if($puedeEditar):?><button type="button" class="editbtn"';
  if(substr_count($actual,$ancla)!==1)$errores[]='INCOMPATIBLE: no se encontró el punto seguro de enlace por producto';
  else{
   $enlace='<?php if($puedeEditar):?><a class="finance-product" style="display:inline-block;background:#fff2df;color:#724c15;border-radius:6px;padding:7px;margin-left:4px;text-decoration:none;font-weight:700" href="erp_catalogo_finanzas.php?producto_id=<?=(int)$r[\'id\']?>">Costos y márgenes</a><?php endif;?>';
   $porHacer[$catalogo]=str_replace($ancla,$enlace.$ancla,$actual);
   $mensajes[]='ACTUALIZAR: enlace al desglose financiero de cada artículo';
  }
 }else if(str_contains($raw,'class="finance-product"'))$mensajes[]='YA INSTALADO: desglose financiero por producto';
}
$reportes='public/erp_catalogo_reportes.php';
if(!is_file($raiz.'/'.$reportes))$errores[]="Falta $reportes; instala Reportes del Catálogo Maestro primero.";
else {
 $raw=(string)file_get_contents($raiz.'/'.$reportes);
 if(str_contains($raw,'href="erp_catalogo_finanzas.php"'))$mensajes[]='YA INSTALADO: acceso desde Reportes';
 elseif(substr_count($raw,'</header>')!==1 || !str_contains($raw,'gyd_cr_ver_costos'))$errores[]="INCOMPATIBLE: encabezado o permisos no reconocidos en $reportes";
 else{
  $link='<?php if(gyd_cr_ver_costos($rol)):?><a href="erp_catalogo_finanzas.php" style="background:#fff0d7;color:#744800">💰 Costos y rentabilidad</a><?php endif;?>';
  $porHacer[$reportes]=str_replace('</header>',$link.'</header>',$raw);
  $mensajes[]='ACTUALIZAR: acceso desde Reportes para administradores';
 }
}
foreach($porHacer as $ruta=>$contenido){
 if(!str_ends_with($ruta,'.php'))continue;
 $f=tempnam(sys_get_temp_dir(),'gd-fin-');
 if($f===false){$errores[]='No se pudo crear archivo temporal';continue;}
 file_put_contents($f,$contenido);$out=[];$code=1;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($f).' 2>&1',$out,$code);
 unlink($f);if($code!==0)$errores[]="Error de sintaxis $ruta: ".implode(' ',$out);
}
foreach($mensajes as $mensaje)echo $mensaje."\n";
if($errores){foreach($errores as $error)fwrite(STDERR,$error."\n");fwrite(STDERR,"INCOMPATIBLE: no se modificó ningún archivo.\n");exit(3);}
if($accion==='--check'){echo 'COMPATIBLE: '.count($porHacer).' archivos por instalar/actualizar. Sin cambios de base de datos.' . "\n";exit(0);}
if(!$porHacer){echo "YA INSTALADO: no hay cambios.\n";exit(0);}
$backup=$raiz.'/tools/backups/finanzas_catalogo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!@mkdir($backup,0775,true)){fwrite(STDERR,"No se pudo crear el directorio de respaldo.\n");exit(4);}
foreach($porHacer as $ruta=>$contenido){
 if(!is_file($raiz.'/'.$ruta))continue;
 $dest=$backup.'/'.$ruta;
 if(!is_dir(dirname($dest))&&!@mkdir(dirname($dest),0775,true)){fwrite(STDERR,"No se pudo crear carpeta de respaldo.\n");exit(4);}
 if(!copy($raiz.'/'.$ruta,$dest)){fwrite(STDERR,"No se pudo respaldar: $ruta\n");exit(4);}
}
$escritos=[];
try{
 foreach($porHacer as $ruta=>$contenido){
   $dest=$raiz.'/'.$ruta;
   if(!is_dir(dirname($dest))&&!@mkdir(dirname($dest),0775,true))throw new RuntimeException("No se creó directorio: $ruta");
   $tmp=$dest.'.temporal_'.getmypid();
   if(file_put_contents($tmp,$contenido,LOCK_EX)===false)throw new RuntimeException("No se pudo escribir: $ruta");
   if(!@rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException("No se pudo reemplazar: $ruta");}
   $escritos[]=$ruta;
 }
}catch(Throwable $e){
 foreach(array_reverse($escritos) as $ruta){$dest=$raiz.'/'.$ruta;$bak=$backup.'/'.$ruta;if(is_file($bak))copy($bak,$dest);else @unlink($dest);}
 fwrite(STDERR,'ABORTADO Y RESTAURADO: '.$e->getMessage()."\n");exit(5);
}
echo 'INSTALADO: '.count($escritos)." archivos. Respaldo: $backup\n";
echo "Abrir: http://localhost/almacen-farmacia2/public/erp_catalogo_finanzas.php\n";
echo "Solo lectura. Inventarios, compras, IVA y precios no se modifican.\n";
