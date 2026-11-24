<?php
declare(strict_types=1);
/** Instalador G&D V11: modal previo para retiro/corte (requiere V10). */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Solo PowerShell.\n");}
$modo=$argv[1]??'';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_acceso_modal_previo_v11.php --check|--apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_pos_v11_payload';
try{$ops=json_decode((string)file_get_contents($payload.'/replacements.json'),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){fwrite(STDERR,"Manifest inválido.\n");exit(2);}
$validar=static fn($rel)=>(bool)preg_match('~^(?:app|public)/[A-Za-z0-9_/.-]+\.(?:php|js|css)$~D',$rel)&&!str_contains($rel,'..');
$errores=[];$cambios=[];$nuevos=[];$instalados=0;
foreach($ops as $rel=>$patches){
    if(!$validar($rel)||!is_array($patches)){$errores[]='Ruta inválida '.$rel;continue;}
    $ruta=$root.'/'.$rel;
    if(!is_file($ruta)){$errores[]='INCOMPATIBLE: falta '.$rel;continue;}
    $original=(string)file_get_contents($ruta);$nuevo=$original;
    foreach($patches as $numero=>$patch){
        $before=$patch['old']??null;$after=$patch['new']??null;
        if(!is_string($before)||$before===''||!is_string($after)){$errores[]='Payload inválido '.$rel;continue;}
        if($after!==''&&str_contains($nuevo,$after)){$instalados++;continue;}
        // Las eliminaciones ya aplicadas se reconocen por la presencia del acceso previo.
        if($after===''&&!str_contains($nuevo,$before) && $rel==='public/pos_retiros.php' && str_contains($nuevo,"gyd_gate_validar('retiro'")){$instalados++;continue;}
        if(substr_count($nuevo,$before)!==1){$errores[]='INCOMPATIBLE '.$rel.' marcador #'.($numero+1).' no coincide con V10.';continue;}
        $nuevo=str_replace($before,$after,$nuevo);
    }
    if($nuevo!==$original)$cambios[$rel]=$nuevo;
}
foreach(['app/helpers/gyd_acceso_previo.php','public/assets/js/gyd_acceso_previo.js'] as $rel){
    $ruta=$payload.'/files/'.$rel;
    if(!is_file($ruta)){$errores[]='Payload faltante '.$rel;continue;}
    $data=(string)file_get_contents($ruta);
    if(is_file($root.'/'.$rel)){
      if(hash_equals(hash('sha256',$data),hash_file('sha256',$root.'/'.$rel)))$instalados++;
      else $errores[]='Archivo existente diferente '.$rel;
    }else{$nuevos[$rel]=$data;}
}
// Reconocer versión exacta V10 y evitar una aplicación accidental sobre versiones anteriores.
$dependencias=[
 'app/models/PuntoVenta.php'=>['gd_cash_totals(','gyd_corte_autorizar('],
 'app/helpers/gyd_retiros_directos.php'=>['gd_verificar_clave_cajero(','GYD_RETIRO_AUTO_V6'],
 'public/pos.php'=>['id="cerrar" class="secondary">Corte','gyd-retiro-obligatorio'],
 'public/pos_retiros.php'=>['id="importe"'],
];
foreach($dependencias as $rel=>$must){
  $src=is_file($root.'/'.$rel)?(string)file_get_contents($root.'/'.$rel):'';
  $after=$cambios[$rel]??$src;
  foreach($must as $m){
    if(!str_contains($src,$m)&&!str_contains($after,$m)){
      if($rel==='app/models/PuntoVenta.php' && $m==='gyd_corte_autorizar(' && str_contains($after,"gyd_gate_validar('corte'"))continue;
      $errores[]='INCOMPATIBLE: falta función o marcador V10 '.$rel.' '.$m;
    }
  }
}
if($errores){foreach($errores as $e)fwrite(STDERR,$e.PHP_EOL);fwrite(STDERR,"No se modificó ningún archivo.\n");exit(1);}
foreach(array_merge($cambios,$nuevos) as $rel=>$code){
 if(!str_ends_with($rel,'.php'))continue;
 $tmp=tempnam(sys_get_temp_dir(),'gyd11');
 try{
  file_put_contents($tmp,$code);$o=[];$status=0;
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$o,$status);
  if($status!==0){fwrite(STDERR,"Error sintaxis {$rel}: ".implode(' ',$o)."\n");exit(1);}
 }finally{@unlink($tmp);}
}
if($modo==='--check'){
  echo 'COMPATIBLE: '.count($cambios).' archivos modificables; '.count($nuevos).' archivos nuevos; '.$instalados." marcadores aplicados.\n";
  echo "PHP correcto. No se modificó ningún archivo ni tabla. Requiere V10 instalada.\n";exit(0);
}
if(!$cambios&&!$nuevos){echo "V11 ya estaba instalada, sin cambios.\n";exit(0);}
try{
  require_once $root.'/app/config/database.php';
  $db=(new Database())->connect();
  if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('Solo se permite instalar sobre la BD prueba.');
  $backup=$root.'/backups_gyd_acceso_modal_v11_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
  if(!mkdir($backup,0775,true))throw new RuntimeException('No se pudo preparar el respaldo.');
  $hashes=[];
  foreach($cambios as $rel=>$data){
     $hashes[$rel]=hash_file('sha256',$root.'/'.$rel);
     $destino=$backup.'/'.$rel;
     if(!is_dir(dirname($destino))&&!mkdir(dirname($destino),0775,true))throw new RuntimeException('Error carpeta respaldo');
     if(!copy($root.'/'.$rel,$destino))throw new RuntimeException('Error respaldo '.$rel);
  }
  foreach($hashes as $rel=>$hash)if(hash_file('sha256',$root.'/'.$rel)!==$hash)throw new RuntimeException('Archivo modificado simultáneamente '.$rel);
  $creados=[];$modificados=[];
  try{
    foreach($nuevos as $rel=>$data){
       $destino=$root.'/'.$rel;
       if(file_exists($destino))throw new RuntimeException('Archivo apareció durante instalación '.$rel);
       if(!is_dir(dirname($destino))&&!mkdir(dirname($destino),0775,true))throw new RuntimeException('Falta carpeta '.$rel);
       if(file_put_contents($destino,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Error escritura '.$rel);
       $creados[]=$rel;
    }
    foreach($cambios as $rel=>$data){
       $destino=$root.'/'.$rel;$temp=$destino.'.v11tmp'.bin2hex(random_bytes(3));
       if(file_put_contents($temp,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Error escritura temporal '.$rel);
       if(!rename($temp,$destino)){@unlink($temp);throw new RuntimeException('Error reemplazando '.$rel);}
       $modificados[]=$rel;
    }
  }catch(Throwable $e){
    foreach($modificados as $rel)@copy($backup.'/'.$rel,$root.'/'.$rel);
    foreach($creados as $rel)@unlink($root.'/'.$rel);
    throw $e;
  }
  echo "INSTALACIÓN V11 CORRECTA. Respaldo: {$backup}\n";
  echo "No modifica base de datos, retiros, cortes, saldos ni inventarios. Recarga POS con Ctrl+F5.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN DETENIDA: '.$e->getMessage()."\n");exit(1);}
