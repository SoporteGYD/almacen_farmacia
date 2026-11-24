<?php
declare(strict_types=1);
/** Corrección de cortes G&D: Cajero solicita; solo cuatro roles autorizan. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Ejecutar en PowerShell.');}
$modo=(string)($argv[1]??'--check');
if(!in_array($modo,['--check','--check-files','--apply','--verify','--rollback'],true)) {
 fwrite(STDERR,"Uso: php tools/instalar_corte_desde_pos.php --check|--apply|--verify|--rollback\n");exit(2);
}
$root=dirname(__DIR__);$dir=__DIR__;
$ptr=$dir.'/.respaldo_corte_desde_pos.json';
$patches=json_decode((string)file_get_contents($dir.'/patches_corte_autorizado_pos.json'),true,512,JSON_THROW_ON_ERROR);
function fail(string $m):never {throw new RuntimeException($m);}
function fileText(string $name):string {
 if (!is_file($name)) fail('Falta archivo: '.$name);
 $v=file_get_contents($name);if($v===false)fail('No se puede leer: '.$name);return $v;
}
function newPlan(string $root,string $dir,array $patches):array {
 $allowed=['public/pos.php','public/pos_api.php','app/models/PuntoVenta.php','public/pos_corte_retiros.php','public/assets/js/gyd_acceso_previo.js'];
 $plan=[];
 foreach($patches as $p) {
   $rel=(string)$p['file'];if(!in_array($rel,$allowed,true))fail('Ruta de parche no autorizada: '.$rel);
   if(!isset($plan[$rel])){$raw=fileText($root.'/'.$rel);$plan[$rel]=['old'=>$raw,'new'=>$raw];}
   $before=(string)$p['old'];$after=(string)$p['new'];$count=(int)$p['count'];
   $actual=$plan[$rel]['new'];$a=substr_count($actual,$before);$b=substr_count($actual,$after);
   if($b===$count)continue;
   if($a===$count && $b===0) {$plan[$rel]['new']=str_replace($before,$after,$actual);continue;}
   if(!empty($p['optional']) && $a===0 && $b===0)continue;
   fail('El archivo '.$rel.' no corresponde al módulo de cortes revisado (antes '.$a.', nuevo '.$b.'). No se reemplazó.');
 }
 $rel='app/helpers/gyd_cortes_autorizados.php';
 $old=fileText($root.'/'.$rel);$new=fileText($dir.'/payload_corte_pos_autorizado/'.$rel);
 $expected=trim(fileText($dir.'/helper_hash_anterior.txt'));
 if(!hash_equals($expected,hash('sha256',$old)) && !hash_equals(hash('sha256',$new),hash('sha256',$old)))
   fail('La lógica de autorización de cortes difiere del paquete anterior. No se reemplazó.');
 $plan[$rel]=['old'=>$old,'new'=>$new];
 return $plan;
}
function verifyDB(string $root):void {
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();
 $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();$port=(int)$db->query('SELECT @@port')->fetchColumn();
 if($dbName!=='prueba' || $port!==3307)fail('Solo habilitado en la base prueba:3307; conexión detectada: '.$dbName.':'.$port);
 foreach(['gyd_cortes_intentos','gyd_cortes_autorizaciones','pos_cortes_denominaciones','pos_turnos'] as $table){
   $st=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
   $st->execute([$table]);if(!(int)$st->fetchColumn())fail('Falta la tabla '.$table.'. Revisa las instalaciones anteriores.');
 }
 $r=$db->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
 if(!$r || !str_contains((string)$r['Type'],"'CONTABILIDAD'"))fail('Primero instala el perfil CONTABILIDAD del paquete de cortes anterior.');
}
try {
 if($modo==='--rollback'){
   if(!is_file($ptr))fail('No hay respaldo de esta actualización.');
   $info=json_decode(fileText($ptr),true,512,JSON_THROW_ON_ERROR);
   $folder=(string)($info['folder']??'');
   if(!str_starts_with($folder,$root.'/backups/corte_pos_autorizado_') || !is_dir($folder))fail('Ruta de respaldo inválida.');
   foreach($info['files'] as $rel=>$hashes){
     if(!in_array($rel,['public/pos.php','public/pos_api.php','app/models/PuntoVenta.php','public/pos_corte_retiros.php','public/assets/js/gyd_acceso_previo.js','app/helpers/gyd_cortes_autorizados.php'],true))fail('Ruta fuera de alcance.');
     if(hash_file('sha256',$root.'/'.$rel)!==$hashes['after'] || hash_file('sha256',$folder.'/'.$rel)!==$hashes['before'])fail('Hay cambios posteriores o faltan copias de seguridad: '.$rel);
   }
   foreach($info['files'] as $rel=>$hashes){if(!copy($folder.'/'.$rel,$root.'/'.$rel))fail('No se pudo restaurar '.$rel);}
   echo "RESTAURADO. Archivos originales recuperados.\n";exit(0);
 }
 $plan=newPlan($root,$dir,$patches);
 $pend=[];foreach($plan as $rel=>$v)if($v['old']!==$v['new'])$pend[]=$rel;
 if($modo==='--check-files') {echo 'ARCHIVOS COMPATIBLES. Pendientes: '.count($pend)."\n";exit(0);}
 verifyDB($root);
 if($modo==='--verify'){
   if(count($pend))fail('Quedan '.count($pend).' archivos sin actualizar.');
   echo "VERIFICADO: Cajero puede solicitar corte, autorización con rol y contraseña, auditoría y comprobante.\n";exit(0);
 }
 if($modo==='--check'){
   echo "COMPATIBLE: G&D · prueba:3307\n";
   echo 'Archivos por actualizar: '.count($pend)."\n";
   echo "No se modificó nada.\n";exit(0);
 }
 if(!$pend){echo "APLICADO ANTERIORMENTE. Sin cambios pendientes.\n";exit(0);}
 $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
 $folder=$root.'/backups/corte_pos_autorizado_'.$stamp;
 $hashes=[];
 foreach($plan as $rel=>$v){
   $backup=$folder.'/'.$rel;
   if(!is_dir(dirname($backup)) && !mkdir(dirname($backup),0775,true))fail('No se pudo crear respaldo.');
   if(file_put_contents($backup,$v['old'],LOCK_EX)===false)fail('No se pudo respaldar '.$rel);
   $hashes[$rel]=['before'=>hash('sha256',$v['old']),'after'=>hash('sha256',$v['new'])];
 }
 $written=[];
 try {
   foreach($plan as $rel=>$v){
     if($v['old']===$v['new'])continue;
     $dest=$root.'/'.$rel;$tmp=tempnam(dirname($dest),'.corte_pos_');
     if($tmp===false || file_put_contents($tmp,$v['new'],LOCK_EX)===false)fail('Fallo al preparar '.$rel);
     if(!rename($tmp,$dest)) {@unlink($tmp);fail('Fallo al escribir '.$rel);}
     $written[]=$rel;
     if(hash_file('sha256',$dest)!==$hashes[$rel]['after'])fail('La verificación falló en '.$rel);
   }
   if(file_put_contents($ptr,json_encode(['folder'=>$folder,'files'=>$hashes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)fail('No se pudo escribir el puntero de respaldo.');
 } catch(Throwable $e) {
   foreach(array_reverse($written) as $rel)@copy($folder.'/'.$rel,$root.'/'.$rel);
   throw $e;
 }
 echo "APLICADO. Archivos modificados: ".count($written)."\n";
 echo 'Respaldo: '.$folder."\n";
 echo "Ejecuta --verify, inicia Apache y actualiza POS con Ctrl+F5.\n";
} catch(Throwable $e) {fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
