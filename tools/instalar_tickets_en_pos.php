<?php
declare(strict_types=1);
/** G&D - navegación de venta y retiro sin nueva pestaña. Cambios mínimos y reversibles. */
$root=dirname(__DIR__);
$targets=[
  'public/pos_retiros.php',
  'public/pos.php',
  'public/assets/js/pos_gyd.js',
  'public/pos_retiro_ticket.php',
  'public/assets/js/gyd_tickets_en_pos.js',
];
$src=__DIR__.'/gyd_single_tab_src/gyd_tickets_en_pos.js';
$manifest=__DIR__.'/tickets_en_pos_ultimo_respaldo.json';
$mode=$argv[1]??'--check';
function txt(string $f):string { $v=@file_get_contents($f);if(!is_string($v))throw new RuntimeException('No se puede leer: '.$f);return $v;}
function change(string $relative,string $old):string {
 if($relative==='public/pos_retiros.php') {
   if(!str_contains($old,'gyd_retiro_ticket')) {
     $pattern='~header\(\s*[\'\"]Location:\s*pos_retiro_ticket\.php\?id=[\'\"]\s*\.\s*\$r\[[\'\"]id[\'\"]\]\s*,\s*true\s*,\s*303\s*\)~';
     $replacement="header('Location: pos.php?gyd_retiro_ticket='.(int)\$r['id'].'&caja_id='.(int)\$caja,true,303)";
     $new=preg_replace($pattern,$replacement,$old,1,$n);
     if(!is_string($new)||$n!==1)throw new RuntimeException('No se reconoció redirección del retiro. No se cambiarán archivos.');
     $old=$new;
   }
   // Submit always happens in this tab even when other scripts modify the form target.
   if(!str_contains($old,'GYD_RETIRO_FORM_MISMA_PESTANA')) {
     $needle='HTMLFormElement.prototype.submit.call(f);';
     if(!str_contains($old,$needle))throw new RuntimeException('No se localizó el envío protegido del formulario de retiros.');
     $old=str_replace($needle,"f.target='_self'; /* GYD_RETIRO_FORM_MISMA_PESTANA */ ".$needle,$old);
   }
   if(str_contains($old,'id="retiroForm"') && !str_contains($old,'id="retiroForm" target="_self"')) {
     $old=str_replace('id="retiroForm"','id="retiroForm" target="_self"',$old);
   }
   return $old;
 }
 if($relative==='public/pos.php') {
   if(!str_contains($old,'gyd_tickets_en_pos.js')) {
     $tag='<!-- GYD_TICKETS_EN_POS -->' . "\n" . '<script src="assets/js/gyd_tickets_en_pos.js?v=1"></script>' . "\n";
     $new=preg_replace('~</body\s*>~i',$tag.'</body>',$old,1,$n);
     if(!is_string($new)||$n!==1)throw new RuntimeException('No se encontró </body> en POS.');
     $old=$new;
   }
   return $old;
 }
 if($relative==='public/assets/js/pos_gyd.js') {
   if(!str_contains($old,'GYD_TicketsEnPOS')) {
     $needle="window.open('pos_ticket.php?id='+encodeURIComponent(venta.venta_id)+suffix,'_blank','noopener');";
     $replacement="const ticket='pos_ticket.php?id='+encodeURIComponent(venta.venta_id)+suffix; if(!window.GYD_TicketsEnPOS?.abrir(ticket))window.location.assign(ticket);";
     if(!str_contains($old,$needle))throw new RuntimeException('No se identificó la apertura actual del ticket de venta.');
     $old=str_replace($needle,$replacement,$old);
   }
   // History links are intercepted by the new embedded viewer; remove obsolete tab target.
   $old=str_replace('<a target="_blank" rel="noopener" href="pos_ticket.php?id=', '<a href="pos_ticket.php?id=',$old);
   return $old;
 }
 if($relative==='public/pos_retiro_ticket.php') {
   if(!str_contains($old,'GYD_EMBED_TICKET_GUARD')) {
     $needle="\$nuevo=!empty(\$_SESSION['gyd_retiro_v5_exito_'.\$id]);";
     if(!str_contains($old,$needle))throw new RuntimeException('No se localizó el control de impresión automática del retiro.');
     $old=str_replace($needle,"\$nuevo=!empty(\$_SESSION['gyd_retiro_v5_exito_'.\$id]) && !isset(\$_GET['embed']); /* GYD_EMBED_TICKET_GUARD */",$old);
   }
   return $old;
 }
 return $old;
}
try{
 if(!in_array($mode,['--check','--apply','--verify','--rollback'],true))throw new RuntimeException('Usa --check, --apply, --verify o --rollback.');
 if($mode==='--rollback') {
   if(!is_file($manifest))throw new RuntimeException('No existe respaldo de esta actualización.');
   $m=json_decode(txt($manifest),true);
   if(!is_array($m)||!isset($m['backup'],$m['files'])||!is_array($m['files']))throw new RuntimeException('Respaldo inválido.');
   $ok=0;$skip=0;
   foreach($m['files'] as $entry){
     $path=$root.'/'.$entry['name'];
     if(!is_file($path)||hash_file('sha256',$path)!==$entry['updated_hash']){$skip++;continue;}
     if(!$entry['existed']){$result=unlink($path);}else{$source=$m['backup'].'/'.$entry['name'];$result=is_file($source)&&copy($source,$path);}
     if($result)$ok++;else $skip++;
   }
   if(!$skip)@unlink($manifest);
   echo "Restaurados: $ok; omitidos por cambios posteriores: $skip\n";exit($skip?1:0);
 }
 if(!is_file($src))throw new RuntimeException('Falta archivo JS del instalador.');
 $writes=[];
 foreach($targets as $rel){
   $path=$root.'/'.$rel;
   $exists=is_file($path);
   if(!$exists && $rel!=='public/assets/js/gyd_tickets_en_pos.js')throw new RuntimeException('Falta archivo de ERP: '.$rel);
   $old=$exists?txt($path):'';
   $new=$rel==='public/assets/js/gyd_tickets_en_pos.js'?txt($src):change($rel,$old);
   if($old!==$new)$writes[$rel]=['old'=>$old,'new'=>$new,'exists'=>$exists];
 }
 if($mode==='--check') {
   echo "COMPATIBLE\n";
   echo "Archivos que requieren actualización: ".count($writes)."\n";
   echo "El retiro volverá al POS con visor integrado; la venta abrirá ticket en el POS.\n";
   exit;
 }
 if($mode==='--verify'){
   if($writes)throw new RuntimeException('Faltan modificaciones por aplicar: '.implode(', ',array_keys($writes)));
   $ret=txt($root.'/public/pos_retiros.php');
   $pos=txt($root.'/public/pos.php');
   $sales=txt($root.'/public/assets/js/pos_gyd.js');
   if(!str_contains($ret,'gyd_retiro_ticket')||!str_contains($pos,'gyd_tickets_en_pos.js')||!str_contains($sales,'GYD_TicketsEnPOS'))throw new RuntimeException('No se confirmó el visor integrado.');
   echo "VERIFICADO: tickets de venta y retiros dentro del POS.\n";exit;
 }
 if(!$writes){echo "SIN CAMBIOS: esta corrección ya está aplicada.\n";exit;}
 $backupDir=__DIR__.'/backups_ticket_misma_pestana/'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
 if(!mkdir($backupDir,0770,true))throw new RuntimeException('No se pudo crear respaldo.');
 $records=[];
 foreach($writes as $rel=>$entry){
   if($entry['exists']){
     $dst=$backupDir.'/'.$rel;
     if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0770,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
     if(!copy($root.'/'.$rel,$dst))throw new RuntimeException('No se pudo respaldar '.$rel);
   }
   $records[]=['name'=>$rel,'existed'=>$entry['exists'],'updated_hash'=>hash('sha256',$entry['new'])];
 }
 foreach($writes as $rel=>$entry){
   $dst=$root.'/'.$rel;
   if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0770,true))throw new RuntimeException('No se pudo crear carpeta de destino.');
   $temp=$dst.'.gyd_tickets_tmp';
   if(file_put_contents($temp,$entry['new'],LOCK_EX)===false||!rename($temp,$dst))throw new RuntimeException('No se pudo guardar '.$rel);
 }
 if(file_put_contents($manifest,json_encode(['backup'=>$backupDir,'files'=>$records],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('No se pudo registrar el manifiesto.');
 echo "APLICADO\nArchivos actualizados: ".count($writes)."\nRespaldo: $backupDir\n";
}catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);}
