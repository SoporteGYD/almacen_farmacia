<?php
declare(strict_types=1);
/** Instalador idempotente y conservador; no crea ni altera tablas. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
  fwrite(STDERR,"Uso: php tools/instalar_comparador_proveedores.php --check|--apply\n");exit(2);
}
$root=dirname(__DIR__);
$source=__DIR__.'/comparador_proveedores_files';
$files=['app/helpers/erp_comparador_proveedores.php','public/erp_catalogo_comparar_proveedores.php'];
$fin=$root.'/public/erp_catalogo_finanzas.php';
$hist=$root.'/public/erp_catalogo_historial_costos.php';
$link='<a href="erp_catalogo_comparar_proveedores.php">Comparador de proveedores</a>';
$bad=[];$todo=[];$messages=[];
foreach(['app/helpers/auth.php','app/config/database.php'] as $rel){
  if(!is_file($root.'/'.$rel))$bad[]='Falta archivo base '.$rel;
}
foreach($files as $rel){
 $src=$source.'/'.$rel;$dst=$root.'/'.$rel;
 if(!is_file($src)){$bad[]='Falta archivo del parche '.$rel;continue;}
 if(is_file($dst)&&hash_file('sha256',$src)!==hash_file('sha256',$dst)){
    $bad[]='El archivo '.$rel.' ya existe con cambios diferentes. Revisión manual necesaria.';
 }else{
    $messages[]=is_file($dst)?'INSTALADO: '.$rel:'NUEVO: '.$rel;
    if(!is_file($dst))$todo[]=$rel;
 }
}
foreach([$fin,$hist] as $file){
 if(!is_file($file)){$bad[]='Falta el módulo anterior: '.basename($file);continue;}
 $content=file_get_contents($file);
 if($content===false){$bad[]='No se pudo leer '.basename($file);continue;}
 if(str_contains($content,'href="erp_catalogo_comparar_proveedores.php"')){
   $messages[]='ENLACE INSTALADO: '.basename($file);
 }elseif(substr_count($content,'</header>')===1){
   $todo[]='LINK:'.$file;$messages[]='ENLACE PENDIENTE: '.basename($file);
 }else{$bad[]='No hay punto único de inserción de enlace en '.basename($file);}
}
foreach($messages as $msg)echo $msg,"\n";
if($bad){foreach($bad as $msg)echo 'INCOMPATIBLE: ',$msg,"\n";
 echo "INCOMPATIBLE: no se cambió ningún archivo ni la base de datos.\n";exit(1);
}
echo "COMPATIBLE: consulta de solo lectura. No se necesitan cambios SQL.\n";
if($mode==='--check')exit(0);
if(!$todo){echo "INSTALADO: no hay cambios pendientes.\n";exit(0);}
$backup=$root.'/backups_gyd_comp_prov_'.date('Ymd_His');
if(is_dir($backup))$backup.='_'.bin2hex(random_bytes(3));
$changed=[];
try{
 if(!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
 foreach($files as $rel){
   if(!in_array($rel,$todo,true))continue;
   $dest=$root.'/'.$rel;$directory=dirname($dest);
   if(!is_dir($directory)&&!mkdir($directory,0775,true))throw new RuntimeException('No se pudo crear carpeta de destino.');
   $contents=file_get_contents($source.'/'.$rel);
   if($contents===false)throw new RuntimeException('No se pudo leer archivo fuente.');
   if(file_put_contents($dest,$contents,LOCK_EX)===false)throw new RuntimeException('No se pudo instalar '.$rel);
   $changed[]=[$dest,null];
 }
 foreach([$fin,$hist] as $file){
   if(!in_array('LINK:'.$file,$todo,true))continue;
   $orig=file_get_contents($file);
   if($orig===false)throw new RuntimeException('No se pudo leer encabezado.');
   $bak=$backup.'/'.basename($file);
   if(!copy($file,$bak))throw new RuntimeException('No se pudo crear respaldo del archivo '.basename($file));
   $changed[]=[$file,$bak];
   $new=preg_replace('~</header>~',' '.$link.'</header>',$orig,1,$count);
   if($new===null||$count!==1)throw new RuntimeException('Encabezado incompatible.');
   if(file_put_contents($file,$new,LOCK_EX)===false)throw new RuntimeException('No se pudo modificar encabezado.');
 }
 echo "INSTALADO: disponible public/erp_catalogo_comparar_proveedores.php\n";
 echo "RESPALDO: $backup\n";
}catch(Throwable $e){
 foreach(array_reverse($changed) as [$path,$bak]){
   if($bak!==null&&is_file($bak))copy($bak,$path);
   elseif($bak===null&&is_file($path))unlink($path);
 }
 fwrite(STDERR,"ABORTADO: ".$e->getMessage().". Archivos anteriores restaurados.\n");exit(1);
}
