<?php
declare(strict_types=1);
/** Actualización de presentación del modal de Habilitar caja. No toca BD. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Solo CLI.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply','--verify'],true)) {fwrite(STDERR,"Usa --check, --apply o --verify.\n");exit(2);}
$raiz=dirname(__DIR__);
try {
 $parches=json_decode((string)file_get_contents(__DIR__.'/parches_modal_caja.json'),true,512,JSON_THROW_ON_ERROR);
 $cssRel='public/assets/css/gyd_apertura_caja_modal.css';
 $cssFuente=__DIR__.'/payload/'.$cssRel;
 if(!is_file($cssFuente)) throw new RuntimeException('Falta CSS del modal.');
 $css=(string)file_get_contents($cssFuente);
 if(strlen($css)<1000)throw new RuntimeException('CSS incompleto.');
 $cambios=[];
 foreach($parches as $rel=>$lista){
  if(!in_array($rel,['public/pos.php','public/assets/js/pos_gyd.js'],true))throw new RuntimeException('Ruta no permitida');
  $ruta=$raiz.'/'.$rel;
  if(!is_file($ruta))throw new RuntimeException('Archivo inexistente: '.$rel);
  $actual=(string)file_get_contents($ruta);$nuevo=$actual;
  foreach($lista as $p){
   $a=$p['old'];$b=$p['new'];
   if(substr_count($nuevo,$b)===1)continue;
   if(substr_count($nuevo,$a)!==1)throw new RuntimeException('Archivo incompatible, no modificaré: '.$rel);
   $nuevo=str_replace($a,$b,$nuevo);
  }
  if($nuevo!==$actual)$cambios[$rel]=$nuevo;
 }
 $cssDestino=$raiz.'/'.$cssRel;
 if(is_file($cssDestino)){
  if(hash_file('sha256',$cssDestino)!==hash('sha256',$css))throw new RuntimeException('Existe un CSS diferente; no se reemplazará.');
 }else $cambios[$cssRel]=$css;
 if($modo==='--check'){
  echo "COMPATIBLE: ".count($cambios)." archivo(s) por instalar. Solo aspecto del modal de habilitación.\n";exit(0);
 }
 if($modo==='--verify'){
  if($cambios)throw new RuntimeException('Faltan '.count($cambios).' archivos por aplicar.');
  echo "VERIFICADO: modal de habilitar caja, sin denominaciones y sin cambios de caja.\n";exit(0);
 }
 if(!$cambios){echo "YA INSTALADO.\n";exit(0);}
 if(!is_dir($raiz.'/public/assets/css'))throw new RuntimeException('No existe la carpeta de estilos.');
 $respaldo=$raiz.'/backups_modal_habilitar_caja_'.date('Ymd_His');
 if(!mkdir($respaldo,0750,true))throw new RuntimeException('No se pudo crear respaldo.');
 foreach($cambios as $rel=>$contenido){
  $origen=$raiz.'/'.$rel;
  if(is_file($origen)){
   $destino=$respaldo.'/'.$rel;
   if(!is_dir(dirname($destino)) && !mkdir(dirname($destino),0750,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
   if(!copy($origen,$destino))throw new RuntimeException('No se pudo respaldar '.$rel);
  }
 }
 $escritos=[];
 try {
  foreach($cambios as $rel=>$contenido){
   $ruta=$raiz.'/'.$rel;$temporal=$ruta.'.gydtmp'.bin2hex(random_bytes(3));
   if(file_put_contents($temporal,$contenido,LOCK_EX)!==strlen($contenido))throw new RuntimeException('Escritura incompleta '.$rel);
   if(!rename($temporal,$ruta)){@unlink($temporal);throw new RuntimeException('Error al reemplazar '.$rel);}
   $escritos[]=$rel;
  }
 }catch(Throwable $e){
  foreach(array_reverse($escritos) as $rel){
   $previo=$respaldo.'/'.$rel;$destino=$raiz.'/'.$rel;
   if(is_file($previo))copy($previo,$destino);else @unlink($destino);
  }
  throw $e;
 }
 echo "APLICADO: ".count($escritos)." archivos. Respaldo: $respaldo\n";
 echo "Reinicia Apache y usa Ctrl+F5 en la página del POS.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
