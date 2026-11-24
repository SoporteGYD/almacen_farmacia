<?php
declare(strict_types=1);
/** Instalador G&D: panel aditivo, sin migraciones ni escrituras de BD. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo se ejecuta desde consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_rendimiento_cajeros_v14.php --check | --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));$payload=__DIR__.'/gyd_rendimiento_payload';
if($root===false){fwrite(STDERR,"ERROR: No se encontró la raíz de tu ERP.\n");exit(2);}
$targets=['app/helpers/gyd_rendimiento_cajeros.php','public/erp_rendimiento_cajeros.php'];
$errors=[];$warnings=[];$new=[];$present=[];
if(PHP_VERSION_ID<80100)$errors[]='Requiere PHP 8.1 o superior.';
foreach(['app/config/database.php','app/helpers/auth.php','app/views/layouts/header.php','public/erp_eficiencia_cajeros.php','public/pos.php'] as $file){if(!is_file($root.'/'.$file))$errors[]='No se encontró archivo requerido: '.$file;}
foreach($targets as $relative){
 $source=$payload.'/'.$relative;$dest=$root.'/'.$relative;
 if(!is_file($source)){$errors[]='Falta un archivo del paquete: '.$relative;continue;}
 $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($source).' 2>&1';$output=[];$status=0;exec($cmd,$output,$status);
 if($status!==0)$errors[]='PHP inválido en '.$relative.': '.implode(' ',$output);
 if(is_file($dest)){
   if(hash_file('sha256',$source)===hash_file('sha256',$dest)){$present[]=$relative;}
   else{$errors[]='Ya hay una versión distinta de '.$relative.'. Por seguridad no se sobrescribirá.';}
 }elseif(file_exists($dest))$errors[]='Ruta ocupada: '.$relative;
 else $new[$relative]=(string)file_get_contents($source);
}
$menuPath=$root.'/app/views/layouts/header.php';$before=is_file($menuPath)?(string)file_get_contents($menuPath):'';
$old="\$menuAgregar('🛍️ Ventas y gerencia', 'Eficiencia de Cajeros', 'erp_eficiencia_cajeros.php', \$menuEsAdmin);";
$replacement="\$menuAgregar('🛍️ Ventas y gerencia', 'Rendimiento de Cajeros', 'erp_rendimiento_cajeros.php', \$menuEsAdmin);";
$menuUpdate=null;
if(str_contains($before,$replacement))$warnings[]='El enlace de Rendimiento de Cajeros ya está en el menú.';
elseif(substr_count($before,$old)===1){$menuUpdate=str_replace($old,$replacement,$before);}
else $warnings[]='El menú tiene una estructura diferente: el panel se instalará sin cambiarlo. Puedes abrir public/erp_rendimiento_cajeros.php y agregar el enlace manualmente.';
// Chequeos de esquema de solo lectura al aplicar (evita instalar sobre un entorno equivocado).
if($mode==='--apply'&&!$errors){
 try {
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
  if($dbName!=='prueba')$errors[]='La conexión actual no apunta a la base prueba (actual: '.$dbName.').';
  $q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
  foreach(['usuarios','pos_ventas','pos_sucursales','pos_venta_detalle'] as $table){$q->execute([$table]);if(!$q->fetchColumn())$errors[]='Falta tabla obligatoria: '.$table;}
  $q->execute(['erp_pos_eficiencia_cajeros']);if(!$q->fetchColumn())$warnings[]='No existe cronómetro de eficiencia: podrás consultar ventas y unidades, pero los tiempos se mostrarán como «Sin medición».';
 }catch(Throwable $e){$errors[]='No se pudo conectar con la base de pruebas: '.$e->getMessage();}
}
foreach($warnings as $w)echo 'AVISO: '.$w.PHP_EOL;
if($errors){foreach($errors as $e)fwrite(STDERR,'INCOMPATIBLE: '.$e.PHP_EOL);echo "No se modificó ningún archivo.\n";exit(1);}
if($mode==='--check'){
 echo 'COMPATIBLE: '.count($new).' archivos nuevos, '.count($present).' archivos ya instalados, '.($menuUpdate!==null?'1 enlace por actualizar.':'menú sin cambios.').PHP_EOL;
 echo "Prueba estática aprobada. Para instalar, crea un respaldo y ejecuta --apply.\n";exit(0);
}
$backupDir=$root.'/backups_rendimiento_cajeros_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$created=[];$changedMenu=false;$tmpFiles=[];
try{
 if($menuUpdate!==null){
  if(!mkdir($backupDir,0775,true)&&!is_dir($backupDir))throw new RuntimeException('No se pudo crear el respaldo.');
  if(!copy($menuPath,$backupDir.'/header.php'))throw new RuntimeException('No se pudo respaldar el menú.');
 }
 foreach($new as $relative=>$content){
  $dest=$root.'/'.$relative;
  if(!is_dir(dirname($dest)))throw new RuntimeException('Falta carpeta destino: '.dirname($dest));
  if(file_exists($dest))throw new RuntimeException('Archivo apareció durante instalación: '.$relative);
  $tmp=$dest.'.tmp_'.bin2hex(random_bytes(4));$tmpFiles[]=$tmp;
  if(file_put_contents($tmp,$content,LOCK_EX)!==strlen($content))throw new RuntimeException('No se pudo escribir '.$relative);
  if(!rename($tmp,$dest))throw new RuntimeException('No se pudo instalar '.$relative);
  $created[]=$dest;
  echo 'Creado: '.$relative.PHP_EOL;
 }
 if($menuUpdate!==null){
  if((string)file_get_contents($menuPath)!==$before)throw new RuntimeException('El menú cambió mientras instalábamos; cancelado.');
  $tmp=$menuPath.'.tmp_'.bin2hex(random_bytes(4));$tmpFiles[]=$tmp;
  if(file_put_contents($tmp,$menuUpdate,LOCK_EX)!==strlen($menuUpdate))throw new RuntimeException('No se pudo escribir el menú.');
  if(!rename($tmp,$menuPath))throw new RuntimeException('No se pudo cambiar el menú.');
  $changedMenu=true;echo 'Menú actualizado. Respaldo: '.$backupDir.PHP_EOL;
 }
 echo "INSTALADO. Abre http://localhost/almacen-farmacia2/public/erp_rendimiento_cajeros.php\n";
 echo "Sin cambios SQL, sin tocar ventas, cortes ni retiros.\n";
}catch(Throwable $e){
 foreach($tmpFiles as $f)@unlink($f);
 if($changedMenu)@copy($backupDir.'/header.php',$menuPath);
 foreach($created as $f)@unlink($f);
 fwrite(STDERR,'ERROR: '.$e->getMessage().'. Se revirtieron los cambios de archivos.\n');exit(1);
}
