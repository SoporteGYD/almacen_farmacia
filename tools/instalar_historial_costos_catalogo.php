<?php
declare(strict_types=1);
/** Instalador seguro G&D: historial documental de costos (solo lectura). */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_historial_costos_catalogo.php --check | --apply\n");exit(2);
}
$root=dirname(__DIR__);$source=__DIR__.'/historial_costos_files';
$files=['app/helpers/erp_historial_costos_catalogo.php','public/erp_catalogo_historial_costos.php'];
$finance=$root.'/public/erp_catalogo_finanzas.php';
$link='<a href="erp_catalogo_historial_costos.php">Historial de costos y facturas</a>';
$messages=[];$bad=[];$todo=[];
if(!is_file($root.'/app/helpers/auth.php')||!is_file($root.'/app/config/database.php'))$bad[]='Faltan los archivos base de autenticación o base de datos.';
foreach($files as $rel){
    $src=$source.'/'.$rel;$dest=$root.'/'.$rel;
    if(!is_file($src)){$bad[]='Falta fuente: '.$rel;continue;}
    if(is_file($dest)&&hash_file('sha256',$src)!==hash_file('sha256',$dest))$bad[]='Ya existe una versión diferente: '.$rel.'. Revisión manual necesaria.';
    else{$messages[]=((is_file($dest))?'INSTALADO: ':'NUEVO: ').$rel;if(!is_file($dest))$todo[]=$rel;}
}
if(!is_file($finance))$bad[]='No existe public/erp_catalogo_finanzas.php. Instala primero Costos y Rentabilidad.';
else{
    $current=file_get_contents($finance);
    if($current===false)$bad[]='No pude leer el módulo de Costos y Rentabilidad.';
    elseif(str_contains($current,'href="erp_catalogo_historial_costos.php"'))$messages[]='ENLACE INSTALADO: public/erp_catalogo_finanzas.php';
    elseif(str_contains($current,'</header>')){$messages[]='ENLACE: se agregará al encabezado de Costos y Rentabilidad.';$todo[]='LINK';}
    else $bad[]='No se encontró un encabezado seguro en erp_catalogo_finanzas.php. No se sobreescribirá.';
}
foreach($messages as $m)echo $m,"\n";
if($bad){foreach($bad as $m)echo 'INCOMPATIBLE: ',$m,"\n";echo "INCOMPATIBLE: no se modificó ningún archivo ni la base de datos.\n";exit(1);}
echo "COMPATIBLE: módulo de solo lectura; no requiere cambios SQL.\n";
if($mode==='--check')exit(0);
if(!$todo){echo "INSTALADO: ya está aplicado, no se duplicaron enlaces.\n";exit(0);}
$backup=$root.'/backups_gyd_hist_costos_'.date('Ymd_His');$changed=[];
try{
 if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
 foreach($files as $rel){
  $dest=$root.'/'.$rel;
  if(is_file($dest))continue;
  $folder=dirname($dest);
  if(!is_dir($folder)&&!mkdir($folder,0775,true))throw new RuntimeException('No se pudo crear: '.$folder);
  if(!copy($source.'/'.$rel,$dest)){if(is_file($dest))unlink($dest);throw new RuntimeException('No se pudo copiar: '.$rel);}
  $changed[]=[$dest,null];
 }
 if(in_array('LINK',$todo,true)){
  $orig=file_get_contents($finance);
  if($orig===false)throw new RuntimeException('No se pudo leer finanzas.');
  $backupFile=$backup.'/erp_catalogo_finanzas.php';
  if(!copy($finance,$backupFile))throw new RuntimeException('No se pudo respaldar finanzas.');
  $changed[]=[$finance,$backupFile];
  $new=preg_replace('~</header>~',' '.$link.'</header>',$orig,1,$count);
  if($count!==1||$new===null)throw new RuntimeException('No se pudo agregar enlace al encabezado.');
  if(file_put_contents($finance,$new,LOCK_EX)===false)throw new RuntimeException('No se pudo actualizar finanzas.');
 }
 echo "INSTALADO: Historial de costos disponible en public/erp_catalogo_historial_costos.php\n";
 echo "RESPALDO: ",$backup,"\n";
}catch(Throwable $e){
 foreach(array_reverse($changed) as [$target,$bak]){
   if($bak!==null&&is_file($bak))copy($bak,$target);
   elseif($bak===null&&is_file($target))unlink($target);
 }
 fwrite(STDERR,'ABORTADO: '.$e->getMessage()."\n");exit(1);
}
