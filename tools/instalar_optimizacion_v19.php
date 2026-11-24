<?php
declare(strict_types=1);
/** Instalación aditiva: no cambia consultas existentes ni datos de negocio. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$mode=$argv[1]??'';if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_optimizacion_v19.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_optimizacion_payload';if(!$root){fwrite(STDERR,"Raíz inválida.\n");exit(2);}
$source=['app/helpers/gyd_optimizacion.php','public/erp_rendimiento_sistema.php'];$needs=[];$present=[];$errors=[];$warnings=[];
if(PHP_VERSION_ID<80100)$errors[]='Se requiere PHP 8.1 o superior.';
foreach(['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/dashboard.php'] as $file)if(!is_file($root.'/'.$file))$errors[]='Falta archivo base: '.$file;
foreach($source as $rel){
  $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
  if(!is_file($src)){$errors[]='El ZIP no contiene '.$rel;continue;}
  $res=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($src).' 2>&1',$res,$code);
  if($code!==0)$errors[]='Sintaxis inválida: '.$rel.' '.implode(' ',$res);
  if(is_file($dst)){
    if(hash_file('sha256',$src)===hash_file('sha256',$dst))$present[]=$rel;
    else $errors[]='Archivo existente distinto: '.$rel.'; no se sobrescribirá.';
  }elseif(file_exists($dst))$errors[]='Ruta ocupada: '.$rel;
  else $needs[]=$rel;
}
$menuFile=$root.'/app/views/layouts/header.php';$before=is_file($menuFile)?(string)file_get_contents($menuFile):'';$after=null;
$link='$menuAgregar(\'⚙️ Administración\', \'Rendimiento y Optimización\', \'erp_rendimiento_sistema.php\', $menuEsAdmin);';
if(str_contains($before,'erp_rendimiento_sistema.php'))$warnings[]='Enlace ya presente en el menú: no se duplicará.';
else {
 $anchors=[
  '$menuAgregar(\'⚙️ Administración\', \'Centro de Diagnóstico\', \'erp_diagnostico.php\', $menuEsAdmin);',
  '$menuAgregar(\'⚙️ Administración\', \'Usuarios y Permisos\', \'erp_permisos_usuarios.php\', $menuEsAdmin);',
  '$menuAgregar(\'⚙️ Administración\', \'Respaldos\', \'respaldos.php\', $menuEsAdmin || $esEncargado);',
 ];
 foreach($anchors as $anchor)if(substr_count($before,$anchor)===1){$after=str_replace($anchor,$anchor."\n".$link,$before);break;}
 if($after===null)$warnings[]='Menú diferente: la página se instalará sin alterar el menú; usar enlace directo.';
}
if($mode==='--apply' && !$errors){
 try{require_once $root.'/app/config/database.php';$db=(new Database())->connect();if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('La conexión no apunta a prueba.');}
 catch(Throwable $e){$errors[]='Prevalidación MySQL: '.$e->getMessage();}
}
foreach($warnings as $w)echo 'AVISO: '.$w.PHP_EOL;
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
echo 'COMPATIBLE: archivos nuevos '.count($needs).', ya instalados '.count($present).', actualizar menú: '.($after===null?'no':'sí').PHP_EOL;
if($mode==='--check'){echo "No se cambió ningún archivo ni registro.\n";exit(0);}
$created=[];$temp=[];$backup=null;$changed=false;
try{
 if($after!==null){
  $dir=$root.'/tools/backups_optimizacion';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear respaldo del menú.');
  $backup=$dir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';if(!copy($menuFile,$backup))throw new RuntimeException('No se pudo copiar el menú de respaldo.');
 }
 foreach($needs as $rel){$dest=$root.'/'.$rel;$sourcePath=$payload.'/'.$rel;
   if(!is_dir(dirname($dest)))throw new RuntimeException('No existe el directorio destino: '.$rel);
   if(file_exists($dest))throw new RuntimeException('La ruta apareció durante la instalación: '.$rel);
   $tmp=$dest.'.tmp_'.bin2hex(random_bytes(4));$temp[]=$tmp;
   if(!copy($sourcePath,$tmp) || !rename($tmp,$dest))throw new RuntimeException('No se pudo crear '.$rel);
   $created[]=$dest;echo 'Creado: '.$rel.PHP_EOL;
 }
 if($after!==null){
   if((string)file_get_contents($menuFile)!==$before)throw new RuntimeException('Menú modificado por otra operación, no se sobrescribió.');
   $tmp=$menuFile.'.tmp_'.bin2hex(random_bytes(4));$temp[]=$tmp;
   if(file_put_contents($tmp,$after,LOCK_EX)!==strlen($after) || !rename($tmp,$menuFile))throw new RuntimeException('No se pudo actualizar el menú.');
   $changed=true;echo 'Menú respaldado: '.$backup.PHP_EOL;
 }
 echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_rendimiento_sistema.php\n";
 echo "La instalación no crea índices: usa la herramienta independiente para revisar su plan.\n";
}catch(Throwable $e){foreach($temp as $f)@unlink($f);if($changed&&$backup)@copy($backup,$menuFile);foreach($created as $f)@unlink($f);fwrite(STDERR,'ERROR: '.$e->getMessage().'. Archivos añadidos revertidos y menú restaurado si fue modificado.'.PHP_EOL);exit(1);}
