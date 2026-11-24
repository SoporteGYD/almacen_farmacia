<?php
declare(strict_types=1);
/** G&D V40. Instalación CLI estricta, exclusivamente prueba:3307. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify','--seed'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_rewards_laboratorio_v40.php --check|--apply|--verify|--seed\n");exit(2);
}
try {
  $root=realpath(__DIR__.'/..');
  if(!$root)throw new RuntimeException('No se localiza la carpeta raíz del proyecto.');
  $manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_rewards_laboratorio_v40.json'),true,512,JSON_THROW_ON_ERROR);
  $payload=__DIR__.'/payload_rewards_lab_v40';$pendientes=[];$fallos=[];
  foreach($manifest['files'] as $ruta=>$info){
      if(str_contains($ruta,'..')||str_starts_with($ruta,'/'))throw new RuntimeException('Ruta insegura en el paquete.');
      $src=$payload.'/'.$info['payload'];$dest=$root.'/'.$ruta;
      if(!is_file($src)||hash_file('sha256',$src)!==$info['new'])throw new RuntimeException('Payload alterado: '.$ruta);
      $hash=is_file($dest)?hash_file('sha256',$dest):null;
      if($hash===$info['new'])$estado='INSTALADO';
      elseif($hash===null && empty($info['allowed'])){$estado='CREAR';$pendientes[$ruta]=$info;}
      elseif($hash!==null && in_array($hash,$info['allowed'],true)){$estado='ACTUALIZAR';$pendientes[$ruta]=$info;}
      else {$estado='INCOMPATIBLE';$fallos[]=$ruta.' no coincide con V39 o ya tiene cambios propios.';}
      echo str_pad($estado,15).$ruta.PHP_EOL;
  }
  foreach(['app/config/database.php','app/helpers/auth.php','app/helpers/gyd_clientes_rewards.php',
      'app/helpers/gyd_rewards_catalogo_v38.php','app/helpers/gyd_rewards_precanje_v39.php',
      'public/gyd_canjes_api.php'] as $dep)if(!is_file($root.'/'.$dep))$fallos[]='Falta dependencia '.$dep;
  if($fallos)throw new RuntimeException("Instalación BLOQUEADA: \n- ".implode("\n- ",$fallos));
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  $r=$db->query('SELECT DATABASE() AS base,@@port AS puerto')->fetch(PDO::FETCH_ASSOC);
  if(strtolower((string)($r['base']??''))!=='prueba'||(int)($r['puerto']??0)!==3307)
      throw new RuntimeException('Operación BLOQUEADA. Solo base prueba, MariaDB puerto 3307.');
  foreach([
     'gyd_clientes'=>['id','codigo','nombre','acepta_programa','activo','consentimiento_en'],
     'gyd_puntos_movimientos'=>['cliente_id','origen_clave','tipo','puntos'],
     'gyd_rewards_catalogo_v38'=>['id','puntos_requeridos'],
     'gyd_rewards_solicitudes_v39'=>['id','clave'],
     'productos'=>['id','codigo','descripcion','estado'],
     'pos_sucursales'=>['id','nombre','activo'],
     'pos_precios'=>['sucursal_id','producto_id','precio_publico'],
     'lotes'=>['producto_id','costo_unitario']
  ] as $tabla=>$columns){
      $s=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$tabla]);$have=$s->fetchAll(PDO::FETCH_COLUMN);
      foreach($columns as $c)if(!in_array($c,$have,true))throw new RuntimeException('Incompatible: no existe '.$tabla.'.'.$c);
  }
  function comprobarV40(PDO $db,bool $exigir):void {
      $required=[
        'gyd_rewards_lab_clientes_v40'=>['cliente_id','codigo','escenario'],
        'gyd_rewards_lab_productos_v40'=>['sucursal_id','producto_id','precio_centavos','costo_centavos','stock_virtual','puntos_requeridos','escenario'],
        'gyd_rewards_lab_auditoria_v40'=>['id','sucursal_id','producto_id','accion','antes_json','despues_json']
      ];
      foreach($required as $tabla=>$cols){
          $s=$db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$tabla]);$have=$s->fetchAll(PDO::FETCH_COLUMN);
          if(!$have && !$exigir)continue;
          foreach($cols as $c)if(!in_array($c,$have,true))throw new RuntimeException('Tabla V40 incompleta: '.$tabla.'.'.$c);
          $s=$db->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$tabla]);
          if(strtoupper((string)$s->fetchColumn())!=='INNODB')throw new RuntimeException('Se requiere InnoDB: '.$tabla);
      }
  }
  comprobarV40($db,$modo==='--verify'||$modo==='--seed');
  if($modo==='--check'){
      echo 'COMPATIBLE V39 + prueba:3307. Archivos pendientes: '.count($pendientes).'. SIN cambios.'.PHP_EOL;exit(0);
  }
  if($modo==='--verify'){
      if($pendientes)throw new RuntimeException('V40 no está completamente instalada.');
      $c=(int)$db->query('SELECT COUNT(*) FROM gyd_rewards_lab_clientes_v40')->fetchColumn();
      $p=(int)$db->query('SELECT COUNT(*) FROM gyd_rewards_lab_productos_v40')->fetchColumn();
      echo "V40 VERIFICADA. Clientes lab: $c. Escenarios virtuales: $p. Ningún canje real habilitado.\n";exit(0);
  }
  if($modo==='--seed'){
      if($pendientes)throw new RuntimeException('Primero ejecuta --apply.');
      require_once $root.'/app/helpers/gyd_rewards_lab_v40.php';
      echo json_encode(GydRewardsLabV40::sembrar($db),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit(0);
  }
  // --apply: respaldo del código previo, SQL idempotente, datos ficticios y despliegue del código.
  $backup=dirname(dirname($root)).'/gyd_resguardos_codigo/rewards_laboratorio_v40_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
  if(!mkdir($backup,0770,true))throw new RuntimeException('No se pudo abrir respaldo de código.');
  foreach($pendientes as $ruta=>$e){
      $src=$root.'/'.$ruta; if(!is_file($src))continue;
      $dst=$backup.'/'.$ruta;if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0770,true))throw new RuntimeException('Respaldo no pudo crear carpetas.');
      if(!copy($src,$dst)||hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Respaldo incompleto: '.$ruta);
  }
  $sql=(string)file_get_contents(__DIR__.'/esquema_rewards_laboratorio_v40.sql');
  $sql=(string)preg_replace('/^\s*--[^\r\n]*\r?\n/m','',$sql);
  foreach(explode(';',$sql) as $cmd){$cmd=trim($cmd);if($cmd!=='')$db->exec($cmd);}
  comprobarV40($db,true);
  $aplicados=[];
  try {
    foreach($pendientes as $ruta=>$e){
      $src=$payload.'/'.$e['payload'];$dest=$root.'/'.$ruta;
      if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0770,true))throw new RuntimeException('No pudo crearse el directorio: '.$ruta);
      $tmp=dirname($dest).'/.gyd_v40_'.bin2hex(random_bytes(5)).'.tmp';
      if(!copy($src,$tmp)||hash_file('sha256',$tmp)!==$e['new'])throw new RuntimeException('Copia alterada: '.$ruta);
      $aplicados[]=$ruta;
      if(!rename($tmp,$dest)){if(!copy($tmp,$dest))throw new RuntimeException('Falla al reemplazar '.$ruta);@unlink($tmp);}
      if(hash_file('sha256',$dest)!==$e['new'])throw new RuntimeException('Hash final incorrecto: '.$ruta);
    }
    // La clase se carga desde el destino final para resolver dependencias del ERP.
    require_once $root.'/app/helpers/gyd_rewards_lab_v40.php';
    $semilla=GydRewardsLabV40::sembrar($db);
    echo "V40 INSTALADA en prueba:3307. Respaldo de código: $backup\n";
    echo 'Datos de muestra: '.json_encode($semilla,JSON_UNESCAPED_UNICODE)."\n";
    echo "Abre public/gyd_lab_v40.php y ejecuta --verify. CANJES REALES BLOQUEADOS.\n";
  } catch(Throwable $e){
    foreach(array_reverse($aplicados) as $ruta){$bk=$backup.'/'.$ruta;if(is_file($bk))@copy($bk,$root.'/'.$ruta);else @unlink($root.'/'.$ruta);}
    throw new RuntimeException('Fallo copiando código; se intentó restaurar. Las tablas/datos demo pueden permanecer: '.$e->getMessage(),0,$e);
  }
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO V40: '.$e->getMessage()."\n");exit(2);}
