<?php
declare(strict_types=1);
/** Instalador defensivo del Centro de Diagnóstico: base prueba; no toca movimientos existentes. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$action=$argv[1]??'';
if(!in_array($action,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_diagnostico_erp_v18.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_diagnostico_payload';
if(!$root){fwrite(STDERR,"INCOMPATIBLE: No se encontró la raíz del ERP.\n");exit(1);}
$items=['app/helpers/gyd_diagnostico.php','public/erp_diagnostico.php'];
$errors=[];$warnings=[];$new=[];$found=[];
if(PHP_VERSION_ID<80100)$errors[]='Se necesita PHP 8.1 o superior.';
foreach(['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/dashboard.php'] as $rel)if(!is_file($root.'/'.$rel))$errors[]='Falta archivo obligatorio: '.$rel;
foreach($items as $rel){
  $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
  if(!is_file($src)){$errors[]='Falta archivo en el parche: '.$rel;continue;}
  $syntax=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($src).' 2>&1',$syntax,$code);
  if($code!==0)$errors[]='Error de sintaxis en '.$rel;
  if(is_file($dst)){
    if(!hash_equals((string)hash_file('sha256',$src),(string)hash_file('sha256',$dst)))$errors[]='Ya hay una versión distinta de '.$rel.'; no se sobrescribirá.';
    else $found[]=$rel;
  }elseif(file_exists($dst))$errors[]='Existe una ruta de otro tipo: '.$rel;
  else $new[]=$rel;
}
if(!is_file($payload.'/instalar_tablas.sql'))$errors[]='Falta instalar_tablas.sql del paquete.';
$headerPath=$root.'/app/views/layouts/header.php';$original=is_file($headerPath)?(string)file_get_contents($headerPath):'';
$link='$menuAgregar(\'⚙️ Administración\', \'Centro de Diagnóstico\', \'erp_diagnostico.php\', $menuEsAdmin);';
$headerNew=null;
if(str_contains($original,'erp_diagnostico.php'))$warnings[]='El menú ya tiene el enlace. No se duplicará.';
else {
  $anchors=[
   '$menuAgregar(\'⚙️ Administración\', \'Usuarios y Permisos\', \'erp_permisos_usuarios.php\', $menuEsAdmin);',
   '$menuAgregar(\'⚙️ Administración\', \'Respaldos\', \'respaldos.php\', $menuEsAdmin || $esEncargado);',
   '$menuAgregar(\'⚙️ Administración\', \'Usuarios\', \'usuario.php\', $menuEsAdmin);',
  ];
  foreach($anchors as $anchor)if(substr_count($original,$anchor)===1){$headerNew=str_replace($anchor,$anchor."\n".$link,$original);break;}
  if($headerNew===null)$warnings[]='No se encontró un marcador inequívoco del menú; el panel se abrirá por su URL.';
}
foreach($warnings as $warning)echo 'AVISO: '.$warning.PHP_EOL;
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e.PHP_EOL);echo "No se cambió ningún archivo.\n";exit(1);}
echo 'COMPATIBLE: archivos nuevos '.count($new).', ya instalados '.count($found).', actualizar menú '.($headerNew===null?'no':'sí').".\n";
if($action==='--check'){echo "No se cambió ningún archivo ni tabla. Respalda antes de aplicar.\n";exit(0);}
// Antes de publicar archivos: solo trabajar contra prueba.
try{
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('La conexión NO usa la base prueba. Instalación cancelada.');
  foreach(['usuarios','pos_ventas','pos_turnos'] as $t){
   $s=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');$s->execute([$t]);
   if(!$s->fetchColumn())throw new RuntimeException('Falta tabla imprescindible: '.$t);
  }
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: No se publicaron archivos. '.$e->getMessage().PHP_EOL);exit(1);}
$backup=null;$created=[];$temps=[];$menuUpdated=false;
try{
  if($headerNew!==null){
    $dir=$root.'/tools/backups_centro_diagnostico';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear directorio de respaldos.');
    $backup=$dir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
    if(!copy($headerPath,$backup))throw new RuntimeException('No se pudo respaldar el menú.');
  }
  // Única escritura SQL: tabla propia de historial. Ninguna tabla operativa se altera.
  $sql=(string)file_get_contents($payload.'/instalar_tablas.sql');
  if(trim($sql)==='')throw new RuntimeException('Falta la definición de la tabla de historial.');
  $db->exec($sql);
  foreach($new as $rel){
    $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
    if(!is_dir(dirname($dst)))throw new RuntimeException('No existe la carpeta destino para '.$rel);
    if(file_exists($dst))throw new RuntimeException('El archivo apareció durante la instalación: '.$rel);
    $tmp=$dst.'.tmp_'.bin2hex(random_bytes(4));$temps[]=$tmp;
    if(!copy($src,$tmp)||!rename($tmp,$dst))throw new RuntimeException('No se pudo publicar '.$rel);
    $created[]=$dst;echo 'Creado: '.$rel.PHP_EOL;
  }
  if($headerNew!==null){
    if((string)file_get_contents($headerPath)!==$original)throw new RuntimeException('El menú cambió durante la instalación.');
    $tmp=$headerPath.'.tmp_'.bin2hex(random_bytes(4));$temps[]=$tmp;
    if(file_put_contents($tmp,$headerNew,LOCK_EX)!==strlen($headerNew)||!rename($tmp,$headerPath))throw new RuntimeException('No se pudo publicar el menú.');
    $menuUpdated=true;echo 'Menú actualizado. Respaldo: '.$backup.PHP_EOL;
  }
  echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_diagnostico.php\n";
  echo "El instalador solo creó la tabla gyd_diagnosticos. No altera datos operativos.\n";
}catch(Throwable $e){
 foreach($temps as $tmp)@unlink($tmp);
 if($menuUpdated && $backup!==null)@copy($backup,$headerPath);
 foreach($created as $path)@unlink($path);
 fwrite(STDERR,'ERROR: '.$e->getMessage().'. Se revirtieron los archivos creados. Si el SQL había terminado, la tabla de historial puede permanecer vacía.'.PHP_EOL);exit(1);
}
