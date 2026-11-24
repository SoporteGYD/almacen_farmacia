<?php
/** G&D ERP: elimina obligatoriedad de una segunda cuenta para conciliar.
 *  Parches conservadores, solo para prueba:3307; sin escrituras en inventario.
 */
declare(strict_types=1);
$action=$argv[1]??'--check';
$root=dirname(__DIR__);
$payload=__DIR__.'/conciliacion_directa_payload';
$backup=__DIR__.'/respaldos_conciliacion_directa';
$manifest=$backup.'/manifest.json';
$files=[
 'tt_helper'=>['path'=>$root.'/app/helpers/gyd_conciliacion_tt.php','file'=>'gyd_conciliacion_tt.php','required'=>true],
 'tt_page'=>['path'=>$root.'/public/erp_conciliacion_tt.php','file'=>'erp_conciliacion_tt.php','required'=>true],
 'ht_page'=>['path'=>$root.'/public/erp_conciliacion_gestion.php','file'=>'erp_conciliacion_gestion.php','required'=>false],
];
function gyd_cd_read(string $path):string{$v=@file_get_contents($path);if($v===false)throw new RuntimeException('No se pudo leer: '.$path);return $v;}
function gyd_cd_status(string $live,string $before,string $after):string{
 if(!is_file($live))return 'missing';
 $sha=hash_file('sha256',$live);
 if($sha===hash_file('sha256',$after))return 'updated';
 if($sha===hash_file('sha256',$before))return 'original';
 return 'custom';
}
function gyd_cd_db(string $root):void{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();
 $s=$db->query('SELECT DATABASE() AS db,@@port AS port')->fetch(PDO::FETCH_ASSOC);
 if(($s['db']??'')!=='prueba'||(int)($s['port']??0)!==3307)throw new RuntimeException('Protección: sólo base prueba, puerto 3307. No se cambió nada.');
 foreach(['gyd_tt_conciliacion_solicitudes','gyd_tt_conciliacion_eventos'] as $table){
  $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $q->execute([$table]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Falta tabla '.$table.'. Instala primero la conciliación asistida.');
 }
 $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_tt_pool_config'");
 if((int)$q->fetchColumn()===1&&(int)$db->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config')->fetchColumn()!==0)throw new RuntimeException('Inventario compartido antiguo Tapachula/Toscana todavía activo. No se modifica nada.');
}
function gyd_cd_write(string $path,string $bytes):void{
 if(@file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('No fue posible escribir '.basename($path));
}
try{
 if(!in_array($action,['--check','--apply','--verify','--rollback'],true))throw new RuntimeException('Uso: --check | --apply | --verify | --rollback');
 if($action==='--rollback'){
  if(!is_file($manifest))throw new RuntimeException('No existe el respaldo de esta actualización.');
  $m=json_decode(gyd_cd_read($manifest),true,512,JSON_THROW_ON_ERROR);
  foreach($m['changed']??[] as $key=>$meta){
   if(!isset($files[$key]))continue;
   $path=$files[$key]['path'];$bak=$backup.'/'.$key.'.bak';
   if(!is_file($path)||hash_file('sha256',$path)!==($meta['new_sha']??'')){echo 'NO RESTAURADO (archivo modificado): '.basename($path)."\n";continue;}
   if(!is_file($bak)||hash_file('sha256',$bak)!==($meta['old_sha']??'')){echo 'NO RESTAURADO (respaldo no coincide): '.basename($path)."\n";continue;}
   gyd_cd_write($path,gyd_cd_read($bak));echo 'RESTAURADO: '.basename($path)."\n";
  }
  echo "No se modifica ninguna tabla ni se deshacen inventarios por restaurar archivos.\n";exit(0);
 }
 gyd_cd_db($root);
 $statuses=[];
 foreach($files as $key=>$def){
  $old=$payload.'/antes/'.$def['file'];$new=$payload.'/despues/'.$def['file'];
  if(!is_file($old)||!is_file($new))throw new RuntimeException('Falta archivo interno del paquete: '.$def['file']);
  $status=gyd_cd_status($def['path'],$old,$new);$statuses[$key]=$status;
  if($def['required']&&!in_array($status,['original','updated'],true))throw new RuntimeException('La conciliación Tapachula/Toscana tiene cambios desconocidos o no está instalada: '.basename($def['path']).'. No se sobrescribe.');
 }
 if($action==='--check'){
  echo "COMPATIBLE: entorno prueba:3307 y conciliación Tapachula/Toscana verificados.\n";
  foreach($files as $key=>$def){echo basename($def['path']).': '.($statuses[$key]==='original'?'Listo para actualizar':($statuses[$key]==='updated'?'Ya actualizado':'Personalizado/no encontrado: se omitirá'))."\n";}
  echo "No se cambió ningún archivo, tabla ni existencia.\n";exit(0);
 }
 if($action==='--verify'){
  foreach(['tt_helper','tt_page'] as $key)if($statuses[$key]!=='updated')throw new RuntimeException('Aún no se actualizó '.basename($files[$key]['path']));
  echo "VERIFICADO: Tapachula/Toscana sin autorizador obligatorio, sin cambios en datos.\n";
  echo $statuses['ht_page']==='updated'?"VERIFICADO: Hidalgo/Tuxtla también sin segunda autorización.\n":"Hidalgo/Tuxtla no actualizado (archivo original personalizado o ausente).\n";
  exit(0);
 }
 if(is_file($manifest)){
  $m=json_decode(gyd_cd_read($manifest),true,512,JSON_THROW_ON_ERROR);
  foreach($m['changed']??[] as $key=>$meta){
   if(hash_file('sha256',$files[$key]['path'])!==($meta['new_sha']??''))throw new RuntimeException('Se encontró un archivo modificado después de instalar; no se sobrescribe: '.$key);
  }
  echo "YA INSTALADO. No se vuelven a reemplazar archivos ni respaldos.\n";exit(0);
 }
 $changes=[];
 foreach($files as $key=>$def){
  if($statuses[$key]==='original'){$changes[$key]=['path'=>$def['path'],'bytes'=>gyd_cd_read($payload.'/despues/'.$def['file'])];}
  elseif($statuses[$key]!=='updated'&&!$def['required'])echo 'OMITIDO POR PRECAUCIÓN: '.basename($def['path']).' (personalizado o faltante).'."\n";
 }
 if(!$changes){echo "YA ACTUALIZADO. No hubo cambios.\n";exit(0);}
 if(is_dir($backup))throw new RuntimeException('Ya existe una carpeta de respaldo sin manifiesto. Revísala para evitar perder el respaldo anterior.');
 if(!mkdir($backup,0770,true))throw new RuntimeException('No se pudo crear respaldo.');
 $changed=[];
 foreach($changes as $key=>$x){
  $src=$x['path'];$bak=$backup.'/'.$key.'.bak';
  if(!copy($src,$bak))throw new RuntimeException('No se pudo respaldar '.$src);
  $changed[$key]=['old_sha'=>hash_file('sha256',$src),'new_sha'=>hash('sha256',$x['bytes'])];
 }
 $written=[];
 try{
  foreach($changes as $key=>$x){gyd_cd_write($x['path'],$x['bytes']);$written[]=$key;}
  foreach($changed as $key=>$meta){if(hash_file('sha256',$files[$key]['path'])!==$meta['new_sha'])throw new RuntimeException('La verificación de escritura falló para '.$key);}
  gyd_cd_write($manifest,json_encode(['changed'=>$changed,'applied_at'=>date(DATE_ATOM),'db'=>'prueba:3307'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 }catch(Throwable $e){foreach($written as $key)@copy($backup.'/'.$key.'.bak',$files[$key]['path']);throw $e;}
 echo "APLICADO: eliminada la autorización obligatoria; sólo se modificaron archivos PHP.\n";
 echo "Tapachula/Toscana: conteo -> aplicar/confirmar (incluido conteo #1 si sigue vigente).\n";
 echo in_array('ht_page',array_keys($changed),true)?"Hidalgo/Tuxtla: ajustes directos; Almacén sigue por rack.\n":"Hidalgo/Tuxtla: sin cambios por compatibilidad o ya actualizado.\n";
 echo "El instalador no modificó saldos, ventas, movimientos ni tablas.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
