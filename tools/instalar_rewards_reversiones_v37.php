<?php
declare(strict_types=1);
/** Instalador V37 con hashes estrictos; sólo DB prueba:3307; no modifica el POS. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_rewards_reversiones_v37.php --check|--apply|--verify\n");exit(2);
}
$root=realpath(__DIR__.'/..');if(!$root){fwrite(STDERR,"Proyecto no localizado\n");exit(2);}
$manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_reversiones_v37.json'),true,512,JSON_THROW_ON_ERROR);
$source=__DIR__.'/payload_rewards_reversiones_v37';
$pending=[];$fails=[];
foreach($manifest['files'] as $rel=>$entry){
 $src=$source.'/'.$entry['payload'];$dest=$root.'/'.$rel;
 if(!is_file($src)||hash_file('sha256',$src)!==$entry['new']){$fails[]="Payload inválido: $rel";continue;}
 $hash=is_file($dest)?hash_file('sha256',$dest):null;
 if($hash===$entry['new'])$estado='INSTALADO';
 elseif($hash===null && empty($entry['allowed'])){$estado='CREAR';$pending[$rel]=$entry;}
 elseif($hash!==null && in_array($hash,$entry['allowed'],true)){$estado='ACTUALIZAR';$pending[$rel]=$entry;}
 else {$estado='INCOMPATIBLE';$fails[]="Archivo distinto a V36: $rel";}
 echo str_pad($estado,14).$rel.PHP_EOL;
}
if($fails){foreach($fails as $f)fwrite(STDERR,"BLOQUEADO: $f\n");exit(1);}
if($modo==='--check'){echo 'COMPATIBLE V36. Archivos pendientes: '.count($pending).". Sin cambios.\n";exit(0);}
require_once $root.'/app/config/database.php';
try {
 $db=(new Database())->connect();
 $ctx=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
 if(strtolower((string)($ctx['base']??''))!=='prueba'||(int)($ctx['puerto']??0)!==3307)
  throw new RuntimeException('Base permitida: prueba, puerto 3307.');
 foreach(['pos_reversiones'=>['id','venta_id','tipo','estado'],
          'gyd_clientes_ventas'=>['venta_id','cliente_id','puntos_otorgados'],
          'gyd_puntos_movimientos'=>['origen_clave','puntos','tipo']] as $table=>$columns){
     $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
     $s->execute([$table]);$exists=$s->fetchAll(PDO::FETCH_COLUMN);
     foreach($columns as $col)if(!in_array($col,$exists,true))throw new RuntimeException('Esquema incompatible: '.$table.'.'.$col);
 }
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");exit(2);}
function gd_v37_schema(PDO $db):void {
 $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_rewards_revision_v37'")->fetchAll(PDO::FETCH_COLUMN);
 foreach(['reversion_id','venta_id','cliente_id','tipo_reversion','estado_revision'] as $c)if(!in_array($c,$cols,true))throw new RuntimeException('Falta gyd_rewards_revision_v37.'.$c);
}
if($modo==='--verify'){
 if($pending){fwrite(STDERR,"Faltan archivos: ejecuta --apply.\n");exit(1);}
 try{gd_v37_schema($db);}catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");exit(1);}
 echo "V37 VERIFICADA: sólo prueba:3307. Canjes no habilitados.\n";exit(0);
}
try {
 $sql=(string)file_get_contents(__DIR__.'/esquema_rewards_reversiones_v37.sql');
 $sql=preg_replace('/^\s*(?:--[^\n]*\n\s*)+/','',$sql);
 if(trim($sql)==='')throw new RuntimeException('SQL vacío.');
 $db->exec($sql);
 gd_v37_schema($db);
}catch(Throwable $e){fwrite(STDERR,'SQL BLOQUEADO: '.$e->getMessage().". Código sin modificar.\n");exit(2);}
if(!$pending){echo "V37 YA INSTALADA: esquema correcto.\n";exit(0);}
$backup=dirname(dirname($root)).'/gyd_resguardos_codigo/rewards_reversiones_v37_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if(!@mkdir($backup,0770,true)){fwrite(STDERR,"Respaldo no creado. Código sin modificar.\n");exit(2);}
$changed=[];
try {
 foreach($pending as $rel=>$entry){
  $src=$root.'/'.$rel;$bak=$backup.'/'.$rel;
  if(!is_dir(dirname($bak))&&!mkdir(dirname($bak),0770,true))throw new RuntimeException('No se creó carpeta de respaldo.');
  if(is_file($src)&&(!copy($src,$bak)||hash_file('sha256',$src)!==hash_file('sha256',$bak)))throw new RuntimeException('Error respaldando '.$rel);
 }
 foreach($pending as $rel=>$entry){
  $src=$source.'/'.$entry['payload'];$dest=$root.'/'.$rel;
  if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0770,true))throw new RuntimeException('Carpeta destino no creada.');
  $tmp=dirname($dest).'/.v37_'.bin2hex(random_bytes(6)).'.tmp';
  if(!copy($src,$tmp)||hash_file('sha256',$tmp)!==$entry['new'])throw new RuntimeException('Copia temporal incorrecta.');
  $changed[]=$rel;
  if(!@rename($tmp,$dest)){if(!@copy($tmp,$dest))throw new RuntimeException('No se reemplazó '.$rel);@unlink($tmp);}
  if(hash_file('sha256',$dest)!==$entry['new'])throw new RuntimeException('Verificación falló: '.$rel);
 }
 echo "V37 INSTALADA. Respaldo de código en: $backup\nEjecuta --verify y luego conciliar_rewards_v37.php --report.\n";
}catch(Throwable $e){
 foreach(array_reverse($changed) as $rel){$saved=$backup.'/'.$rel;if(is_file($saved))@copy($saved,$root.'/'.$rel);else @unlink($root.'/'.$rel);}
 fwrite(STDERR,'FALLÓ: '.$e->getMessage().". Se intentó restaurar el código; tabla nueva puede permanecer.\n");exit(2);
}
