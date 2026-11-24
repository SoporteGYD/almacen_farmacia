<?php
declare(strict_types=1);
/** Instalador incremental: estados automáticos de avisos de Compras. Sólo CLI. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo terminal.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_avisos_estados_automaticos.php --check | --apply\n");exit(2);}
$root=realpath(__DIR__.'/..');
if(!$root || !is_file($root.'/app/helpers/auth.php')){fwrite(STDERR,"Carpeta de ERP inválida.\n");exit(2);}
$manifest=json_decode((string)file_get_contents(__DIR__.'/avisos_estados_manifest.json'),true,512,JSON_THROW_ON_ERROR);
$updates=[];$problems=[];
foreach($manifest as $m){
  $rel=$m['file'];
  if(str_contains($rel,'..') || !preg_match('~^(public|app/helpers)/[A-Za-z0-9_/.]+\.php$~D',$rel)){$problems[]="Ruta inválida: $rel";continue;}
  $dest=$root.'/'.$rel;$source=__DIR__.'/avisos_estados_files/'.$rel;
  if(!is_file($source) || hash_file('sha256',$source)!==$m['nuevo']){$problems[]="Archivo distribuido corrupto: $rel";continue;}
  if(is_file($dest)){
    $hash=hash_file('sha256',$dest);
    if($hash===$m['nuevo'])continue;
    if($hash!==$m['anterior']){$problems[]="INCOMPATIBLE: $rel (modificado fuera de la versión esperada). No se sobrescribirá.";continue;}
  }elseif($m['anterior']!==null){$problems[]="Falta $rel";continue;}
  $updates[]=['dest'=>$dest,'source'=>$source,'rel'=>$rel];
}
if($problems){foreach($problems as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"ABORTADO sin modificar archivos.\n");exit(3);}
// Comprobar el esquema MySQL antes de editar páginas.
try{
 require_once $root.'/app/config/database.php';$db=(new Database())->connect();
 $needed=['erp_oc_avisos_compras','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_ordenes_compra_detalle','erp_facturas','erp_facturas_detalle','erp_facturas_stock_aplicaciones','erp_oc_entrada_vinculos'];
 $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 foreach($needed as $table){$st->execute([$table]);if((int)$st->fetchColumn()===0)throw new RuntimeException('Falta tabla '.$table);$st->closeCursor();}
}catch(Throwable $ex){fwrite(STDERR,"INCOMPATIBLE: ".$ex->getMessage()."\nNo se modificaron archivos ni existencias.\n");exit(4);}
foreach($updates as $it){
 $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($it['source']).' 2>&1';exec($cmd,$out,$code);
 if($code!==0){fwrite(STDERR,'Sintaxis inválida: '.$it['rel']."\n");exit(5);}
}
fwrite(STDOUT,"COMPATIBLE: ".count($updates)." archivos por instalar. BD comprobada; no requiere migración SQL.\n");
if($mode==='--check')exit(0);
$backups=[];$created=[];
try{
 foreach($updates as $it){
   $dir=dirname($it['dest']);if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir))throw new RuntimeException('No se pudo crear '.$dir);
   if(is_file($it['dest'])){
     $bk=$it['dest'].'.bak_avisos_estados_'.date('Ymd_His');
     if(!copy($it['dest'],$bk))throw new RuntimeException('No se pudo respaldar '.$it['rel']);
     $backups[$it['dest']]=$bk;
   }else{$created[]=$it['dest'];}
   if(!copy($it['source'],$it['dest']))throw new RuntimeException('No se pudo instalar '.$it['rel']);
   $out=[];$status=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($it['dest']).' 2>&1',$out,$status);
   if($status!==0)throw new RuntimeException('Error de sintaxis en '.$it['rel']);
 }
 fwrite(STDOUT,"INSTALADO: avisos de Compras con estados documentado/aplicado automáticos. Recarga con Ctrl+F5.\n");
 fwrite(STDOUT,"No se modificaron saldos, aplicaciones, usuarios ni tablas.\n");
}catch(Throwable $ex){foreach($backups as $dest=>$bk){copy($bk,$dest);}foreach($created as $p){if(is_file($p))unlink($p);}fwrite(STDERR,"ABORTADO: ".$ex->getMessage()."\nSe restauraron los archivos.\n");exit(6);}
