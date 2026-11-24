<?php
declare(strict_types=1);
/**
 * Instala Reportes del Catálogo Maestro ERP G&D.
 * --check : validar y mostrar cambios, sin escribir nada.
 * --apply : insertar enlace, dar permiso a Compras, crear archivos nuevos.
 * No conecta ni modifica ninguna tabla de base de datos.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_reportes_catalogo.php --check | --apply\n");exit(2);}
$raiz=dirname(__DIR__);
$plantilla=__DIR__.'/catalogo_reportes_files';
$nuevos=['app/helpers/erp_catalogo_reportes.php','public/erp_catalogo_reportes.php'];
$pendientes=[];$mensajes=[];$errores=[];
foreach($nuevos as $ruta){
  $source=$plantilla.'/'.$ruta;$destino=$raiz.'/'.$ruta;
  if(!is_file($source)){$errores[]="Falta plantilla: $source";continue;}
  $contenido=(string)file_get_contents($source);
  if(is_file($destino)){
    if(hash('sha256',$contenido)!==hash_file('sha256',$destino))$errores[]="INCOMPATIBLE: $ruta ya existe con otro contenido. No se sobrescribe.";
    else $mensajes[]="YA INSTALADO: $ruta";
  }else{$pendientes[$ruta]=$contenido;$mensajes[]="NUEVO: $ruta";}
}
$catalogo='public/erp_catalogo_maestro.php';$abs=$raiz.'/'.$catalogo;
if(!is_file($abs))$errores[]="No se encontró $catalogo";
else{
 $actual=(string)file_get_contents($abs);
 if(str_contains($actual,'href="erp_catalogo_reportes.php"'))$mensajes[]="YA INSTALADO: enlace en $catalogo";
 else{
   $anchor='</header><main>';
   if(substr_count($actual,$anchor)!==1)$errores[]="INCOMPATIBLE: encabezado de $catalogo cambió. No se reemplazará.";
   else{
      $nuevo='<a href="erp_catalogo_reportes.php" style="background:#dbeafb;color:#153c65">📊 Reportes personalizados</a>'.$anchor;
      $pendientes[$catalogo]=str_replace($anchor,$nuevo,$actual);
      $mensajes[]="ACTUALIZAR: botón Reportes en el Catálogo Maestro";
   }
 }
}
// En versiones nuevas, las rutas de Compras se administran en esta lista cerrada.
$modulos='app/helpers/erp_compras_modulos.php';$absMod=$raiz.'/'.$modulos;
if(is_file($absMod)){
  $actual=(string)file_get_contents($absMod);
  if(str_contains($actual,"'erp_catalogo_reportes.php'=>true"))$mensajes[]="YA INSTALADO: permiso de Compras para reportes";
  else {
    $ancla="'erp_catalogo_maestro.php'=>true,";
    if(substr_count($actual,$ancla)!==1)$errores[]="INCOMPATIBLE: lista de rutas de Compras en $modulos";
    else {
      $pendientes[$modulos]=str_replace($ancla,"'erp_catalogo_reportes.php'=>true,\n        ".$ancla,$actual);
      $mensajes[]="ACTUALIZAR: acceso de Compras a Reportes del Catálogo";
    }
  }
}else{
 // Compatibilidad con el perfil COMPRAS antiguo (lista en auth.php).
 $auth='app/helpers/auth.php';$absAuth=$raiz.'/'.$auth;
 if(!is_file($absAuth))$errores[]='Falta app/helpers/auth.php';
 else {
   $actual=(string)file_get_contents($absAuth);
   if(str_contains($actual,"'erp_catalogo_reportes.php'"))$mensajes[]="YA INSTALADO: ruta de Compras";
   else{
     $ancla="'erp_recepciones_pendientes.php'";
     if(substr_count($actual,$ancla)!==1)$errores[]="INCOMPATIBLE: no se encontró la lista de Compras en auth.php";
     else{
       $pendientes[$auth]=str_replace($ancla,"'erp_catalogo_reportes.php',".$ancla,$actual);
       $mensajes[]="ACTUALIZAR: ruta de reportes para COMPRAS en auth.php";
     }
   }
 }
}
foreach($pendientes as $ruta=>$contenido){
 if(!str_ends_with($ruta,'.php'))continue;
 $tmp=tempnam(sys_get_temp_dir(),'gyd-cat-lint-');
 if($tmp===false){$errores[]='No se pudo crear temporal para prueba de sintaxis';continue;}
 file_put_contents($tmp,$contenido);
 $out=[];$res=1;
 @exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$res);
 @unlink($tmp);
 if($res!==0)$errores[]='Error de sintaxis en '.$ruta.': '.implode(' ', $out);
}
foreach($mensajes as $line)echo $line."\n";
if($errores){foreach($errores as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE: no se modificaron archivos.\n");exit(3);}
if($modo==='--check'){
 echo "COMPATIBLE: ".count($pendientes)." archivos por crear o actualizar. No se hizo ningún cambio.\n";
 if(!is_file($raiz.'/vendor/autoload.php'))echo "AVISO: No hay vendor/autoload.php; el Excel .xlsx requerirá PhpSpreadsheet. La opción CSV seguirá disponible.\n";
 exit(0);
}
if(!$pendientes){echo "YA INSTALADO: sin cambios adicionales.\n";exit(0);}
$backupDir=$raiz.'/tools/backups/catalogo_reportes_'.date('Ymd_His');
if(!is_dir($backupDir) && !@mkdir($backupDir,0775,true)){
 fwrite(STDERR,"No se pudo crear carpeta de respaldos.\n");exit(4);
}
// Respaldar primero todos los archivos que serán modificados.
foreach($pendientes as $ruta=>$contenido){
  $destino=$raiz.'/'.$ruta;
  if(is_file($destino)){
    $bak=$backupDir.'/'.$ruta;
    if(!is_dir(dirname($bak))&&!@mkdir(dirname($bak),0775,true)){
      fwrite(STDERR,"No se pudo preparar respaldo $bak\n");exit(4);
    }
    if(!@copy($destino,$bak)){fwrite(STDERR,"No se pudo respaldar $ruta\n");exit(4);}
  }
}
$completados=[];
try{
 foreach($pendientes as $ruta=>$contenido){
   $destino=$raiz.'/'.$ruta;
   if(!is_dir(dirname($destino)) && !@mkdir(dirname($destino),0775,true))throw new RuntimeException("No se pudo crear directorio para $ruta");
   $tmp=$destino.'.nuevo_gyd_'.getmypid();
   if(file_put_contents($tmp,$contenido,LOCK_EX)===false)throw new RuntimeException("No se pudo escribir $ruta");
   if(!@rename($tmp,$destino)){@unlink($tmp);throw new RuntimeException("No se pudo actualizar $ruta");}
   $completados[]=$ruta;
 }
}catch(Throwable $e){
  foreach(array_reverse($completados) as $ruta){$bak=$backupDir.'/'.$ruta;$dest=$raiz.'/'.$ruta;if(is_file($bak))@copy($bak,$dest);else @unlink($dest);}
  fwrite(STDERR,"ABORTADO: ".$e->getMessage().". Se intentó restaurar los archivos originales.\n");exit(5);
}
echo "INSTALADO: ".count($completados)." archivos. Respaldos: $backupDir\n";
echo "Abrir: http://localhost/almacen-farmacia2/public/erp_catalogo_reportes.php\n";
echo "No se tocó la base de datos ni las existencias.\n";
