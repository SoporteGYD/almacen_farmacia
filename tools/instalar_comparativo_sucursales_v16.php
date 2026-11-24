<?php
declare(strict_types=1);
/** Instalador aditivo del Comparativo General de Sucursales; solo para base prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_comparativo_sucursales_v16.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_comparativo_payload';
if($root===false){fwrite(STDERR,"No se encontró la raíz del proyecto.\n");exit(2);}
$sourceFiles=['app/helpers/gyd_comparativo_sucursales.php','public/erp_comparativo_sucursales.php'];
$needs=[];$installed=[];$errors=[];$warnings=[];
if(PHP_VERSION_ID<80100)$errors[]='Se requiere PHP 8.1 o superior.';
foreach(['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/dashboard.php'] as $file)if(!is_file($root.'/'.$file))$errors[]='Falta archivo del ERP: '.$file;
foreach($sourceFiles as $rel){
 $src=$payload.'/'.$rel;$dst=$root.'/'.$rel;
 if(!is_file($src)){$errors[]='Falta archivo en ZIP: '.$rel;continue;}
 $output=[];$exitCode=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($src).' 2>&1',$output,$exitCode);
 if($exitCode!==0)$errors[]='Error de sintaxis en '.$rel.': '.implode(' ',$output);
 if(is_file($dst)){
  if(hash_file('sha256',$src)===hash_file('sha256',$dst))$installed[]=$rel;
  else $errors[]='Ya existe un archivo diferente: '.$rel.'. Por seguridad, no se reemplazará.';
 }elseif(file_exists($dst))$errors[]='La ruta ya está ocupada: '.$rel;
 else $needs[]=$rel;
}
$menuPath=$root.'/app/views/layouts/header.php';$menuBefore=is_file($menuPath)?(string)file_get_contents($menuPath):'';
$newLine="$"."menuAgregar('🛍️ Ventas y gerencia', 'Comparativo General de Sucursales', 'erp_comparativo_sucursales.php', " . '$menuEsAdmin' . ");";
$menuAfter=null;
if(str_contains($menuBefore,'erp_comparativo_sucursales.php'))$warnings[]='El enlace del menú ya existe; no se duplicará.';
else {
 $anchors=[
  "$"."menuAgregar('🛍️ Ventas y gerencia', 'Centro de Alertas Gerenciales', 'erp_alertas_gerenciales.php', " . '$menuEsAdmin || $esGerente' . ");",
  "$"."menuAgregar('🛍️ Ventas y gerencia', 'Control de Cajas y Arqueos', 'erp_control_cajas.php', " . '$menuEsAdmin' . ");",
 ];
 foreach($anchors as $anchor)if(substr_count($menuBefore,$anchor)===1){$menuAfter=str_replace($anchor,$anchor."\n".$newLine,$menuBefore);break;}
 if($menuAfter===null)$warnings[]='No se encontró anclaje seguro en el menú: se instalará el panel y podrás entrar por su URL; no se alterará header.php.';
}
if($mode==='--apply'&&!$errors){
 try{
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('La conexión no apunta a la base «prueba».');
  $q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
  foreach(['pos_sucursales','pos_cajas','pos_turnos','pos_ventas'] as $table){$q->execute([$table]);if(!$q->fetchColumn())throw new RuntimeException('Falta tabla obligatoria: '.$table);}
  // No DDL, no inserciones ni cambios de datos.
 }catch(Throwable $e){$errors[]='Prevalidación de MySQL: '.$e->getMessage();}
}
foreach($warnings as $warning)echo 'AVISO: '.$warning.PHP_EOL;
if($errors){foreach($errors as $msg)fwrite(STDERR,'INCOMPATIBLE: '.$msg.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
echo 'COMPATIBLE: archivos nuevos '.count($needs).', ya presentes '.count($installed).', actualización de menú '.($menuAfter===null?'no':'sí').".\n";
if($mode==='--check'){echo "No se cambió ningún archivo. Respalda tu proyecto y la base de pruebas antes de --apply.\n";exit(0);}
$created=[];$tmp=[];$backup=null;$menuChanged=false;
try{
 if($menuAfter!==null){
  $dir=$root.'/tools/backups_comparativo_sucursales';
  if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
  $backup=$dir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
  if(!copy($menuPath,$backup))throw new RuntimeException('No fue posible respaldar el menú.');
 }
 foreach($needs as $rel){
  $src=$payload.'/'.$rel;$dest=$root.'/'.$rel;$dir=dirname($dest);
  if(!is_dir($dir))throw new RuntimeException('No existe la carpeta destino: '.$dir);
  if(file_exists($dest))throw new RuntimeException('El archivo apareció durante la instalación: '.$rel);
  $filename=$dest.'.tmp_'.bin2hex(random_bytes(4));$tmp[]=$filename;
  if(!copy($src,$filename))throw new RuntimeException('No se pudo preparar '.$rel);
  if(!rename($filename,$dest))throw new RuntimeException('No se pudo instalar '.$rel);
  $created[]=$dest;echo 'Creado: '.$rel.PHP_EOL;
 }
 if($menuAfter!==null){
  if((string)file_get_contents($menuPath)!==$menuBefore)throw new RuntimeException('El menú cambió durante la instalación.');
  $filename=$menuPath.'.tmp_'.bin2hex(random_bytes(4));$tmp[]=$filename;
  if(file_put_contents($filename,$menuAfter,LOCK_EX)!==strlen($menuAfter))throw new RuntimeException('No se pudo generar el menú actualizado.');
  if(!rename($filename,$menuPath))throw new RuntimeException('No se pudo publicar el menú.');
  $menuChanged=true;echo 'Menú actualizado y respaldado en '.$backup.PHP_EOL;
 }
 echo "INSTALADO: http://localhost/almacen-farmacia2/public/erp_comparativo_sucursales.php\n";
 echo "Sin SQL, sin cambios en ventas, inventario, retiros o cortes.\n";
}catch(Throwable $e){
 foreach($tmp as $f)@unlink($f);
 if($menuChanged && $backup!==null)@copy($backup,$menuPath);
 foreach($created as $f)@unlink($f);
 fwrite(STDERR,'ERROR: '.$e->getMessage().'. Archivos nuevos revertidos; el menú se conserva/restaura desde el respaldo.'.PHP_EOL);exit(1);
}
