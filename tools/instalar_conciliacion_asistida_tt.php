<?php
/** G&D — Instalador incremental, exclusivamente prueba:3307. */
declare(strict_types=1);
$root=dirname(__DIR__);$source=__DIR__.'/payload';
$dbfile=$root.'/app/config/database.php';
$helper=$root.'/app/helpers/gyd_conciliacion_tt.php';
$page=$root.'/public/erp_conciliacion_tt.php';
$alerts=$root.'/public/erp_alertas_inventario.php';
$backup=$root.'/tools/respaldos_conciliacion_tt';$manifest=$backup.'/manifest.json';
$action=$argv[1]??'--check';$marker='GYD_CONCILIACION_TT_LINK_2026';
function gcti_read(string $p):string{$s=@file_get_contents($p);if($s===false)throw new RuntimeException('No se pudo leer: '.$p);return $s;}
function gcti_patch(string $s):string{
 if(str_contains($s,'GYD_CONCILIACION_TT_LINK_2026'))return $s;
 if(!str_contains($s,'Centro de Alertas de Inventario')||!str_contains($s,'gai_branches'))throw new RuntimeException('No se reconoce la pantalla de alertas instalada; no sobrescribir cambios desconocidos.');
 $needle='<nav class="nav" aria-label="Navegación del módulo">';
 if(substr_count($s,$needle)!==1)throw new RuntimeException('No se encontró la navegación original del Centro de Alertas.');
 $button='<!-- GYD_CONCILIACION_TT_LINK_2026 --><a class="btn alt" href="erp_conciliacion_tt.php">Conciliación asistida: Tapachula y Toscana</a>';
 return str_replace($needle,$needle.$button,$s);
}
function gcti_connect(string $file):PDO{
 require_once $file;$db=(new Database())->connect();
 $s=$db->query('SELECT DATABASE() AS nombre,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
 if(($s['nombre']??'')!=='prueba'||(int)($s['puerto']??0)!==3307)throw new RuntimeException('Sólo se permite base prueba, puerto 3307.');
 $needed=['productos','usuarios','pos_sucursales','pos_existencias_farmacia'];
 foreach($needed as $t){$q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$t]);if(strtoupper((string)$q->fetchColumn())!=='INNODB')throw new RuntimeException('Falta tabla InnoDB requerida: '.$t);}
 $rows=$db->query('SELECT id FROM pos_sucursales WHERE id IN (3,4)')->fetchAll(PDO::FETCH_COLUMN);
 foreach([3,4] as $sid)if(!in_array($sid,array_map('intval',$rows),true))throw new RuntimeException('Falta sucursal '.$sid);
 $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_tt_pool_config'");
 if((int)$q->fetchColumn()===1&&(int)$db->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config')->fetchColumn()!==0)throw new RuntimeException('Inventario compartido Tapachula/Toscana todavía activo. No se instala.');
 return $db;
}
function gcti_schema(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS gyd_tt_conciliacion_solicitudes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sucursal_id INT NOT NULL,
 producto_id INT NOT NULL,
 estado VARCHAR(16) NOT NULL DEFAULT 'CONTEO',
 existencia_base INT NULL,
 cantidad_contada INT NOT NULL,
 observacion VARCHAR(500) NOT NULL,
 solicitado_por INT NOT NULL,
 autorizado_por INT NULL,
 aplicado_por INT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 solicitado_en DATETIME NULL,
 autorizado_en DATETIME NULL,
 aplicado_en DATETIME NULL,
 referencia VARCHAR(500) NULL,
 INDEX idx_gct_sid_prod (sucursal_id,producto_id,id),
 INDEX idx_gct_estado (estado,creado_en)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS gyd_tt_conciliacion_eventos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 solicitud_id BIGINT UNSIGNED NOT NULL,
 usuario_id INT NOT NULL,
 accion VARCHAR(24) NOT NULL,
 existencia_antes INT NULL,
 existencia_despues INT NULL,
 detalle VARCHAR(500) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_gct_eventos (solicitud_id,id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function gcti_verify_schema(PDO $db):void{
 foreach(['gyd_tt_conciliacion_solicitudes'=>['id','sucursal_id','producto_id','estado','existencia_base','cantidad_contada','observacion','solicitado_por','autorizado_por','aplicado_por','creado_en','solicitado_en','autorizado_en','aplicado_en','referencia'],
 'gyd_tt_conciliacion_eventos'=>['id','solicitud_id','usuario_id','accion','existencia_antes','existencia_despues','detalle','creado_en']] as $t=>$expected){
  $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$t]);$cols=$q->fetchAll(PDO::FETCH_COLUMN);
  foreach($expected as $key)if(!in_array($key,$cols,true))throw new RuntimeException('Tabla de conciliación incompatible: '.$t.' carece de '.$key);
 }
}
function gcti_write(string $p,string $data):void{
 if(file_put_contents($p,$data,LOCK_EX)===false)throw new RuntimeException('No pudo escribir '.basename($p));
}
try{
 if(!in_array($action,['--check','--apply','--verify','--rollback'],true))throw new RuntimeException('Uso: --check | --apply | --verify | --rollback');
 $db=gcti_connect($dbfile);
 $helperData=gcti_read($source.'/gyd_conciliacion_tt.php');$pageData=gcti_read($source.'/erp_conciliacion_tt.php');
 $oldAlerts=gcti_read($alerts);$newAlerts=gcti_patch($oldAlerts);
 foreach([[$helper,'Conciliación asistida exclusiva'],[$page,'Conciliación asistida de inventarios']] as [$target,$text]){
  if(is_file($target)&&!str_contains(gcti_read($target),$text))throw new RuntimeException('Archivo preexistente personalizado, no se sobrescribe: '.basename($target));
 }
 if($action==='--check'){
  if(gcti_has_table($db,'gyd_tt_conciliacion_solicitudes')||gcti_has_table($db,'gyd_tt_conciliacion_eventos'))gcti_verify_schema($db);
  echo "COMPATIBLE: prueba:3307, sucursales independientes y Centro de Alertas encontrado. No se cambió nada.\n";exit(0);
 }
 if($action==='--verify'){
  gcti_verify_schema($db);
  foreach([$helper,$page] as $p)if(!is_file($p))throw new RuntimeException('Falta '.basename($p));
  if(!str_contains(gcti_read($alerts),$marker))throw new RuntimeException('Falta enlace en Centro de Alertas.');
  echo "VERIFICADO: conteos, autorizaciones y enlace. Aplicación sólo en prueba:3307.\n";exit(0);
 }
 if($action==='--rollback'){
  if(!is_file($manifest))throw new RuntimeException('No hay manifiesto de instalación.');
  $m=json_decode(gcti_read($manifest),true,512,JSON_THROW_ON_ERROR);$restored=[];$blocked=[];
  foreach(['helper'=>$helper,'page'=>$page,'alerts'=>$alerts] as $key=>$file){
   $x=$m[$key]??null;if(!$x)continue;
   if(!is_file($file)||hash_file('sha256',$file)!==($x['instalado']??'')){$blocked[]=$key;continue;}
   if(($x['creado']??false)){unlink($file);$restored[]=$key;}
   elseif(is_file($backup.'/'.$key.'.bak')){copy($backup.'/'.$key.'.bak',$file);$restored[]=$key;}
  }
  echo 'Archivos restaurados: '.implode(',',$restored)."\n";
  if($blocked)echo 'No se tocaron archivos modificados posteriormente: '.implode(',',$blocked)."\n";
  echo "Tablas y movimientos registrados CONSERVADOS deliberadamente.\n";exit(0);
 }
 if(is_file($manifest)){
  $m=json_decode(gcti_read($manifest),true,512,JSON_THROW_ON_ERROR);
  foreach(['helper'=>$helper,'page'=>$page,'alerts'=>$alerts] as $key=>$file)
   if(!is_file($file)||hash_file('sha256',$file)!==($m[$key]['instalado']??''))throw new RuntimeException('Hay cambios posteriores a instalación. No se sobrescriben.');
  echo "YA INSTALADO: no se repite ni se sobreescriben respaldos.\n";exit(0);
 }
 if(!is_dir($backup)&&!mkdir($backup,0770,true))throw new RuntimeException('No pudo crear carpeta de respaldos.');
 $files=['helper'=>[$helper,$helperData],'page'=>[$page,$pageData],'alerts'=>[$alerts,$newAlerts]];$map=[];
 foreach($files as $key=>[$file,$content]){
  $exists=is_file($file);if($exists&&!copy($file,$backup.'/'.$key.'.bak'))throw new RuntimeException('No pudo respaldar '.$key);
  $map[$key]=['creado'=>!$exists,'instalado'=>hash('sha256',$content)];
 }
 gcti_schema($db);gcti_verify_schema($db);
 $done=[];
 try{
  foreach($files as $key=>[$file,$content]){if(!is_dir(dirname($file)))mkdir(dirname($file),0770,true);gcti_write($file,$content);$done[]=$key;}
  gcti_write($manifest,json_encode($map,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 }catch(Throwable $e){foreach($done as $key){[$file,$content]=$files[$key];if($map[$key]['creado'])@unlink($file);elseif(is_file($backup.'/'.$key.'.bak'))@copy($backup.'/'.$key.'.bak',$file);}throw $e;}
 echo "APLICADO: conciliación asistida para Tapachula y Toscana. Tablas vacías creadas; no se modificó inventario.\n";
 echo "Al aplicar un ajuste autorizado desde la pantalla, se exige auditoría y doble cuenta.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
function gcti_has_table(PDO $db,string $name):bool{$q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$name]);return (int)$q->fetchColumn()===1;}
