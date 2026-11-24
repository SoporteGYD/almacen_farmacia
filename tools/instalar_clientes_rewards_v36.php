<?php
declare(strict_types=1);
/** Instalador exclusivo de V36 en prueba:3307. No altera inventario. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_clientes_rewards_v36.php --check|--apply|--verify\n");exit(2);}
$root=realpath(__DIR__.'/..');if(!$root){fwrite(STDERR,"No se encontró proyecto.\n");exit(2);}
$manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_clientes_rewards_v36.json'),true,512,JSON_THROW_ON_ERROR);
$source=__DIR__.'/payload_clientes_rewards';
$actual=is_file($root.'/public/pos.php')?hash_file('sha256',$root.'/public/pos.php'):'';
$variant='';
foreach($manifest['pos_versions'] as $v=>$entry){if(in_array($actual,$entry['allowed'],true)||hash_equals($actual,$entry['new'])){$variant=$v;break;}}
if(!$variant){fwrite(STDERR,"INCOMPATIBLE: public/pos.php no es V35 instalada; primero instala/verifica V35 sin modificarla a mano.\n");exit(1);}
$manifest['files']['public/pos.php']=$manifest['pos_versions'][$variant];
$fail=[];$pending=[];
foreach($manifest['files'] as $rel=>$entry){
  $from=$source.'/'.$entry['payload'];$dest=$root.'/'.$rel;
  if(!is_file($from)||hash_file('sha256',$from)!==$entry['new']){$fail[]='Payload inválido: '.$rel;continue;}
  $old=is_file($dest)?hash_file('sha256',$dest):null;
  if($old===$entry['new']){$st='INSTALADO';}
  elseif($old===null && empty($entry['allowed'])){$st='CREAR';$pending[$rel]=$entry;}
  elseif(in_array($old,$entry['allowed'],true)){$st='ACTUALIZAR';$pending[$rel]=$entry;}
  else{$st='INCOMPATIBLE';$fail[]='Archivo desconocido, no se tocará: '.$rel;}
  echo str_pad($st,14).$rel.PHP_EOL;
}
if($fail){foreach($fail as $f)fwrite(STDERR,"ERROR: $f\n");echo "Bloqueado. Ningún archivo modificado.\n";exit(1);}
if($modo==='--check'){echo "COMPATIBLE: POS $variant V35; pendientes ".count($pending).". Sin cambios.\n";exit(0);}
require_once $root.'/app/config/database.php';
try {
  $db=(new Database())->connect();
  $info=$db->query('SELECT DATABASE() base, @@port puerto')->fetch(PDO::FETCH_ASSOC);
  if(strtolower((string)($info['base']??''))!=='prueba'||(int)($info['puerto']??0)!==3307)throw new RuntimeException('Solo se permite base prueba, puerto 3307.');
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage().PHP_EOL);exit(2);}
function gd_schema_ok(PDO $db):bool {
 $needed=['gyd_clientes'=>['id','codigo','nombre','telefono','correo','acepta_programa','activo'],'gyd_clientes_ventas'=>['venta_id','cliente_id','puntos_otorgados'],'gyd_puntos_movimientos'=>['cliente_id','venta_id','origen_clave','tipo','puntos']];
 foreach($needed as $table=>$columns){
  $names=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN,0);
  foreach($columns as $col)if(!in_array($col,$names,true))throw new RuntimeException('Falta '.$table.'.'.$col);
 }
 return true;
}
if($modo==='--verify'){
 if($pending){fwrite(STDERR,"Faltan archivos: ejecuta --apply.\n");exit(1);}
 try{gd_schema_ok($db);}catch(Throwable $e){fwrite(STDERR,'ESQUEMA NO VERIFICADO: '.$e->getMessage().PHP_EOL);exit(1);}
 echo "V36 VERIFICADA. Código y esquema presentes en prueba:3307. Canjes deshabilitados.\n";exit(0);
}
// --apply: DDL antes de copiar código; sin cambios en pos_ventas ni stocks.
try{
  $sql=file_get_contents(__DIR__.'/esquema_clientes_rewards_v36.sql');
  if($sql===false)throw new RuntimeException('No se encontró SQL V36.');
  foreach(preg_split('/;\s*(?:\n|$)/',$sql) as $statement){
   $statement=trim($statement);
   if($statement==='' || preg_match('/^\s*(?:--[^\n]*\n\s*)+$/',$statement))continue;
   $statement=preg_replace('/^(?:--[^\n]*\n\s*)+/','',$statement);
   if(trim($statement)!=='')$db->exec($statement);
  }
  gd_schema_ok($db);
}catch(Throwable $e){fwrite(STDERR,'SQL V36 FALLÓ: '.$e->getMessage().". No se modificó el código.\n");exit(2);}
if(!$pending){echo "YA INSTALADO. Tablas comprobadas.\n";exit(0);}
// Resguardar fuera de htdocs como se hace en V35.
$backup=dirname(dirname($root)).'/gyd_resguardos_codigo/clientes_rewards_v36_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if(!mkdir($backup,0770,true)){fwrite(STDERR,"No se creó respaldo de código.\n");exit(2);}
$changed=[];
try{
 foreach($pending as $rel=>$entry){
  $old=$root.'/'.$rel;$bak=$backup.'/'.$rel;
  if(!is_dir(dirname($bak)) && !mkdir(dirname($bak),0770,true))throw new RuntimeException('Carpeta respaldo no creada.');
  if(is_file($old) && (!copy($old,$bak)||hash_file('sha256',$old)!==hash_file('sha256',$bak)))throw new RuntimeException('No se pudo respaldar '.$rel);
 }
 foreach($pending as $rel=>$entry){
  $dest=$root.'/'.$rel;$from=$source.'/'.$entry['payload'];
  if(!is_dir(dirname($dest)) && !mkdir(dirname($dest),0770,true))throw new RuntimeException('No se pudo crear carpeta destino.');
  $tmp=dirname($dest).'/.v36_'.bin2hex(random_bytes(6)).'.tmp';
  if(!copy($from,$tmp)||hash_file('sha256',$tmp)!==$entry['new'])throw new RuntimeException('No se preparó '.$rel);
  $changed[]=$rel;
  if(!@rename($tmp,$dest)){if(!@copy($tmp,$dest))throw new RuntimeException('No se reemplazó '.$rel);@unlink($tmp);}
  if(hash_file('sha256',$dest)!==$entry['new'])throw new RuntimeException('Hash incorrecto '.$rel);
 }
 echo "V36 INSTALADA. Respaldo de código: $backup\n";
 echo "No se tocó inventario, precios, ventas anteriores ni pagos. Ejecuta --verify.\n";
}catch(Throwable $e){
 foreach(array_reverse($changed) as $rel){$saved=$backup.'/'.$rel;if(is_file($saved))@copy($saved,$root.'/'.$rel);else @unlink($root.'/'.$rel);}
 fwrite(STDERR,'INSTALACIÓN FALLÓ: '.$e->getMessage().". Se intentó restaurar código. Las tablas V36 pueden permanecer.\n");exit(2);
}
