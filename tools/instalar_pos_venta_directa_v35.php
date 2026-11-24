<?php
declare(strict_types=1);
/** Instalador seguro V35 · Solo laboratorio prueba:3307. Sin movimientos de stock. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_pos_venta_directa_v35.php --check|--apply|--verify\n");exit(2);}
$root=realpath(__DIR__.'/..');if(!$root){fwrite(STDERR,"No se localizó el proyecto\n");exit(2);}
$info=json_decode((string)file_get_contents(__DIR__.'/manifest_pos_venta_directa_v35.json'),true,512,JSON_THROW_ON_ERROR);
$payload=__DIR__.'/payload_pos_venta_directa';
$actual=is_file($root.'/public/pos.php')?hash_file('sha256',$root.'/public/pos.php'):'';
$variante='';
foreach($info['pos_versions'] as $v=>$reg){if(in_array($actual,$reg['allowed'],true)||hash_equals($actual,$reg['new'])){$variante=$v;break;}}
if($variante===''){fwrite(STDERR,"INCOMPATIBLE: no se reconoce public/pos.php (se esperaba V33 o V34). No se modificó nada.\n");exit(1);}
$info['files']['public/pos.php']=$info['pos_versions'][$variante];
$fail=[];$pending=[];$estado=[];
foreach($info['files'] as $rel=>$reg){
 $dest=$root.'/'.$rel;$fuente=$payload.'/'.$reg['payload'];
 if(!is_file($fuente)||hash_file('sha256',$fuente)!==$reg['new']){$fail[]='Payload inválido: '.$rel;continue;}
 $h=is_file($dest)?hash_file('sha256',$dest):null;
 if($h===$reg['new']){$estado[$rel]='INSTALADO';}
 elseif($h===null && !$reg['allowed']){$estado[$rel]='CREAR';$pending[$rel]=$reg;}
 elseif(in_array($h,$reg['allowed'],true)){$estado[$rel]='MODIFICAR';$pending[$rel]=$reg;}
 else{$estado[$rel]='INCOMPATIBLE';$fail[]='Archivo existente desconocido: '.$rel;}
}
foreach($estado as $rel=>$st)echo str_pad($st,14).$rel.PHP_EOL;
if($fail){foreach($fail as $m)fwrite(STDERR,'ERROR: '.$m.PHP_EOL);echo "INCOMPATIBLE. No se modificó nada.\n";exit(1);}
if($modo==='--check'){echo 'COMPATIBLE. POS '.$variante.'; '.count($pending)." archivos pendientes. No se modificó nada.\n";exit(0);}
if($modo==='--verify'){
 if($pending){fwrite(STDERR,"PENDIENTE: instala con --apply.\n");exit(1);}echo "VERIFICADO. Sin cambios en MySQL.\n";exit(0);
}
if(!$pending){echo "YA INSTALADO. Sin nuevas modificaciones.\n";exit(0);}
require_once $root.'/app/config/database.php';
try{$db=(new Database())->connect();$dbinfo=$db->query('SELECT DATABASE() base, @@port puerto')->fetch(PDO::FETCH_ASSOC);
 if(strtolower((string)($dbinfo['base']??''))!=='prueba'||(int)($dbinfo['puerto']??0)!==3307)throw new RuntimeException('Solo se permite prueba:3307.');
}catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage().PHP_EOL);exit(2);}
// Ruta XAMPP: C:\xampp\htdocs\almacen-farmacia2 -> C:\xampp\gyd_resguardos_codigo
$backups=dirname(dirname($root)).'/gyd_resguardos_codigo/pos_venta_directa_v35_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if(!mkdir($backups,0770,true)){fwrite(STDERR,"No se pudo crear respaldo externo.\n");exit(2);}
$restaurar=[];
try{
 foreach($pending as $rel=>$reg){
  $src=$root.'/'.$rel;$bak=$backups.'/'.$rel;if(!is_dir(dirname($bak))&&!mkdir(dirname($bak),0770,true))throw new RuntimeException('No se pudo crear ruta de respaldo.');
  if(is_file($src)){if(!copy($src,$bak)||hash_file('sha256',$src)!==hash_file('sha256',$bak))throw new RuntimeException('Respaldo fallido: '.$rel);}
 }
 foreach($pending as $rel=>$reg){
  $src=$payload.'/'.$reg['payload'];$dst=$root.'/'.$rel;
  if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0770,true))throw new RuntimeException('No se pudo crear carpeta: '.$rel);
  $tmp=dirname($dst).'/.gydv35_'.bin2hex(random_bytes(6)).'.tmp';
  if(!copy($src,$tmp)||hash_file('sha256',$tmp)!==$reg['new'])throw new RuntimeException('Error preparando: '.$rel);
  $restaurar[]=$rel;
  if(!@rename($tmp,$dst)){if(!copy($tmp,$dst))throw new RuntimeException('Error reemplazando: '.$rel);@unlink($tmp);}
  if(hash_file('sha256',$dst)!==$reg['new'])throw new RuntimeException('Falló verificación: '.$rel);
 }
 echo "INSTALADO. Respaldo de código: $backups\n";
 echo "No se cambiaron existencias en MySQL. Ejecuta --verify y el diagnóstico antes de probar ventas.\n";
}catch(Throwable $e){
 foreach(array_reverse($restaurar) as $rel){$b=$backups.'/'.$rel;if(is_file($b))@copy($b,$root.'/'.$rel);else @unlink($root.'/'.$rel);}
 fwrite(STDERR,'FALLO: '.$e->getMessage().". Se intentó restaurar la versión anterior.\n");exit(2);
}
