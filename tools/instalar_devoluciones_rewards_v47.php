<?php
declare(strict_types=1);
/** Instalador de reversiones de Rewards por partida. SOLO prueba:3307. */
$root=dirname(__DIR__);
$patchesFile=__DIR__.'/patches_devoluciones_rewards.json';
$action=$argv[1]??'--check';
if(!in_array($action,['--check','--apply','--verify'],true)){
  fwrite(STDERR,"Uso: php instalar_devoluciones_rewards_v47.php --check|--apply|--verify\n");exit(1);
}
function fail(string $s):never {fwrite(STDERR,"ERROR: ".$s."\n");exit(1);}
function dbCheck(string $root):PDO {
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  $info=$db->query('SELECT DATABASE() base,@@port puerto')->fetch(PDO::FETCH_ASSOC);
  if(strtolower((string)($info['base']??''))!=='prueba'||(int)($info['puerto']??0)!==3307)
      throw new DomainException('Se requiere exactamente la base prueba, puerto 3307. No se modificará otra base.');
  return $db;
}
function independientes(string $root):void {
  $p=@file_get_contents($root.'/app/helpers/pos_inteligente.php')?:'';
  if(!preg_match('/function\s+pi_tt_stock_sid\s*\([^)]*\)\s*:\s*int\s*\{\s*return\s+\$sid\s*;/',$p))
    throw new DomainException('No se detectó inventario independiente para Tapachula/Toscana. Instala primero la corrección de inventarios separados; no se aplicó este paquete.');
}
function patchedFiles(string $root,array $patches):array {
  $result=[];$already=0;
  foreach($patches as $rel=>$changes){
    if(str_contains($rel,'..')||str_starts_with($rel,'/'))throw new DomainException('Ruta insegura en manifiesto.');
    $path=$root.'/'.$rel;
    if(!is_file($path))throw new DomainException('Falta archivo necesario: '.$rel);
    $original=file_get_contents($path);
    if($original===false)throw new DomainException('No se pudo leer '.$rel);
    $content=$original;
    foreach($changes as $change){
      $old=(string)$change['old'];$new=(string)$change['new'];
      $want=($rel==='app/helpers/gyd_rewards_reversiones_v37.php')?3:1;
      $installed=substr_count($content,$new);
      $cnt=substr_count($content,$old);
      if($installed===$want){$already++;continue;}
      if($installed!==0)throw new DomainException('Parche aplicado parcialmente en '.$rel.'. No sobrescribas tus cambios.');
      if($cnt===$want){$content=str_replace($old,$new,$content);}
      else throw new DomainException('Archivo personalizado o parche incompatible: '.$rel.'. No sobrescribas tus cambios.');
    }
    $result[$rel]=['from'=>$original,'to'=>$content];
  }
  return $result;
}
function createTables(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS gyd_rewards_lineas_v47 (
    detalle_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    cantidad_presentaciones INT UNSIGNED NOT NULL,
    ingreso_elegible_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
    puntos_asignados INT UNSIGNED NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_venta (venta_id), KEY idx_cliente (cliente_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $db->exec("CREATE TABLE IF NOT EXISTS gyd_rewards_devoluciones_v47 (
    reversion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venta_id BIGINT UNSIGNED NOT NULL,
    detalle_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    puntos_revertidos INT UNSIGNED NOT NULL DEFAULT 0,
    estado VARCHAR(20) NOT NULL DEFAULT 'APLICADA',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_detalle (detalle_id), KEY idx_venta (venta_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
try {
  if(!is_file($patchesFile))throw new DomainException('Falta manifiesto de actualización.');
  $patches=json_decode((string)file_get_contents($patchesFile),true,512,JSON_THROW_ON_ERROR);
  if(!is_array($patches))throw new DomainException('Manifiesto inválido.');
  independientes($root);
  if($action==='--verify'){
    $db=dbCheck($root);
    foreach(['gyd_rewards_lineas_v47','gyd_rewards_devoluciones_v47'] as $tbl){
      $q=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $q->execute([$tbl]);if(!(int)$q->fetchColumn())throw new DomainException('No existe tabla '.$tbl);
    }
    foreach(['app/helpers/gyd_rewards_lineas_v47.php','public/gyd_rewards_devoluciones.php'] as $rel)
      if(!is_file($root.'/'.$rel))throw new DomainException('Falta '.$rel);
    $files=patchedFiles($root,$patches);
    foreach($files as $rel=>$f)if($f['from']!==$f['to'])throw new DomainException('No se aplicaron todos los cambios de '.$rel);
    echo "VERIFICADO: estructura, archivos y navegación de auditoría.\n";exit(0);
  }
  $files=patchedFiles($root,$patches); // prevalidación sin tocar nada
  $db=dbCheck($root);
  if($action==='--check'){
    echo "COMPATIBLE. Base prueba:3307, inventarios independientes detectados.\n";
    echo "Archivos revisados: ".count($files).". Clientes y devoluciones conservan su lógica original.\n";
    exit(0);
  }
  foreach(['app/helpers/gyd_rewards_lineas_v47.php'=>'gyd_rewards_lineas_v47.php',
           'public/gyd_rewards_devoluciones.php'=>'gyd_rewards_devoluciones.php'] as $target=>$src){
      if(!is_file(__DIR__.'/'.$src))throw new DomainException('Falta archivo '.$src);
      if(is_file($root.'/'.$target) && hash_file('sha256',$root.'/'.$target)!==hash_file('sha256',__DIR__.'/'.$src))
          throw new DomainException('El archivo '.$target.' ya está personalizado. No será sobrescrito.');
  }
  // DDL no forma parte de la transacción; crear tablas vacías antes de publicar el código.
  createTables($db);
  $backup=$root.'/tools/respaldos_devoluciones_rewards_'.date('Ymd_His');
  if(!mkdir($backup,0700,true))throw new DomainException('No se pudo crear el respaldo.');
  foreach($files as $rel=>$f){
    $out=$backup.'/'.str_replace('/','__',$rel).'.bak';
    if(file_put_contents($out,$f['from'])===false)throw new DomainException('No se pudo respaldar '.$rel);
  }
  $written=[];
  try {
    foreach($files as $rel=>$f)if($f['from']!==$f['to']){
      if(file_put_contents($root.'/'.$rel,$f['to'],LOCK_EX)===false)throw new DomainException('No se pudo actualizar '.$rel);
      $written[]=$rel;
    }
    foreach(['app/helpers/gyd_rewards_lineas_v47.php'=>'gyd_rewards_lineas_v47.php',
             'public/gyd_rewards_devoluciones.php'=>'gyd_rewards_devoluciones.php'] as $target=>$src){
       if(!is_file($root.'/'.$target)){
          if(!copy(__DIR__.'/'.$src,$root.'/'.$target))throw new DomainException('No se pudo instalar '.$target);
          $written[]=$target;
       }
    }
  }catch(Throwable $e){
     foreach($written as $rel){
       if(isset($files[$rel]))file_put_contents($root.'/'.$rel,$files[$rel]['from']);
       else @unlink($root.'/'.$rel);
     }
     throw $e;
  }
  echo "APLICADO. Archivos actualizados: ".count($written).". Respaldo: $backup\n";
  echo "Ejecuta --verify. Prueba una devolución sólo en prueba:3307.\n";
}catch(Throwable $e){fail($e->getMessage());}
