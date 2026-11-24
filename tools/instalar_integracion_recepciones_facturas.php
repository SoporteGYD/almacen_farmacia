<?php
declare(strict_types=1);
/** G&D - Puente recepción de OC -> factura -> verificación -> inventario. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_integracion_recepciones_facturas.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/rfi_payload';
$creaciones=['app/helpers/erp_oc_integracion_facturas.php','public/erp_oc_integracion_facturas.php'];
$targets=['public/erp_recepciones_calidad_historial.php','public/erp_facturas_verificacion.php'];
$dependencias=['app/helpers/erp_facturas.php','app/helpers/erp_oc_recepciones.php','app/helpers/erp_oc_seguimiento.php','public/erp_recepcion_registrar.php','public/erp_facturas_aplicar.php'];
foreach(array_merge($targets,$dependencias) as $f)if(!is_file($root.'/'.$f)){fwrite(STDERR,"INCOMPATIBLE: falta $f\n");exit(1);}
foreach($creaciones as $f){
 if(!is_file($payload.'/'.$f)){fwrite(STDERR,"PAQUETE INCOMPLETO: $f\n");exit(1);}
 if(is_file($root.'/'.$f)&&hash_file('sha256',$root.'/'.$f)!==hash_file('sha256',$payload.'/'.$f)){
  fwrite(STDERR,"INCOMPATIBLE: $f ya fue modificado. No se sobrescribirá.\n");exit(1);
 }
}
$original=[];foreach($targets as $f)$original[$f]=(string)file_get_contents($root.'/'.$f);
function gy_reemplazar_una(string $s,string $a,string $b,string $f):string{
 $n=substr_count($s,$a);
 if($n!==1)throw new RuntimeException("INCOMPATIBLE: $f requiere una coincidencia de '$a', se encontraron $n. No se sobrescribirá.");
 return str_replace($a,$b,$s);
}
try{
 $edits=$original;
 $hist='public/erp_recepciones_calidad_historial.php';
 if(!str_contains($edits[$hist],'GYD_RFI_ENLACE_V1')){
  $anchor='<a class="btn" href="erp_conciliacion_integral.php?oc=<?=$ocId?>">Centro de conciliación</a>';
  $edits[$hist]=gy_reemplazar_una($edits[$hist],$anchor,$anchor.' <!-- GYD_RFI_ENLACE_V1 --><a class="btn" href="erp_oc_integracion_facturas.php?oc=<?=$ocId?>">Recepciones y facturas</a>',$hist);
 }
 $ver='public/erp_facturas_verificacion.php';
 if(!str_contains($edits[$ver],'GYD_RFI_ENLACE_V1')){
  $anchor='<section class="card"><h2>Verificar físicamente los productos</h2>';
  $nav='<?php // GYD_RFI_ENLACE_V1: lectura sin alterar movimientos. ?>\n'.
   '<?php if($factura && (int)($factura[\'orden_id\']??0)>0): ?>'.
   '<section class="card" style="border-left:4px solid #276399"><h2>Recepción física vinculada a factura</h2>'.
   '<p class="muted">Las cantidades buenas provienen de folios REC. Confirma físicamente el lote y caducidad antes de guardar la verificación. Las piezas reportadas dañadas o faltantes no se aplican.</p>'.
   '<a class="btn alt" href="erp_oc_integracion_facturas.php?oc=<?=(int)$factura[\'orden_id\']?>">Ver entrega, factura y saldo</a></section>'.
   '<?php endif; ?>'."\n";
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$anchor,$nav.$anchor,$ver);
 }
 // Algunos ERP ya tienen el autollenado de octubre: NO crear una segunda precarga.
 $autoExistente=str_contains($edits[$ver],'GYD_OC_PRECARGA_VERIFICACION_V1')||str_contains($edits[$ver],'gyd_oc_precargar_verificacion(');
 if(!$autoExistente && !str_contains($edits[$ver],'GYD_RFI_PRECARGA_V1')){
  $must=['$ds=ef_resumen_detalles($db,$fid);',
      '<?php foreach($ds as $d):$did=(int)$d[\'id\'];$pen=max(0,(int)$d[\'cantidad_facturada\']-(int)$d[\'aceptadas\']-(int)$d[\'incidencias_pendientes\']);?>',
      'max="<?=$pen?>" value="0" step="1"',
      'name="lote[<?=$did?>]" maxlength="100" placeholder="Lote"',
      'name="caducidad[<?=$did?>]" type="date"'];
  foreach($must as $a)if(substr_count($edits[$ver],$a)!==1)throw new RuntimeException("INCOMPATIBLE: formulario de verificación personalizado ('$a'). No modificar; revisa compatibilidad.");
  $need="require_once __DIR__.'/../app/helpers/erp_facturas.php';";
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$need,$need." // GYD_RFI_PRECARGA_V1\nrequire_once __DIR__.'/../app/helpers/erp_oc_integracion_facturas.php';",$ver);
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$must[0],$must[0]."\n     \$gydRfiAuto=['partidas'=>[],'piezas'=>0,'revisar'=>0];\n     try{ \$gydRfiAuto=gyd_rfi_precarga(\$db,(int)(\$factura['orden_id']??0),\$ds); }catch(Throwable \$precargaError){ error_log('G&D precarga: '.\$precargaError->getMessage()); }",$ver);
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$must[1],"<?php foreach(\$ds as \$d):\$did=(int)\$d['id'];\$pen=max(0,(int)\$d['cantidad_facturada']-(int)\$d['aceptadas']-(int)\$d['incidencias_pendientes']);\$gydRfi=\$gydRfiAuto['partidas'][\$did]??[];?>",$ver);
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$must[2],'max="<?=$pen?>" value="<?=($k===\'aceptada\'?(int)($gydRfi[\'buenas\']??0):0)?>" step="1"',$ver);
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$must[3],'name="lote[<?=$did?>]" maxlength="100" value="<?=ef_e((string)($gydRfi[\'lote\']??\'\'))?>" placeholder="Lote"',$ver);
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$must[4],'name="caducidad[<?=$did?>]" type="date" value="<?=ef_e((string)($gydRfi[\'caducidad\']??\'\'))?>"',$ver);
  $insert='<p class="muted">Captura buenas, faltantes, dañadas, caducadas o equivocadas';
  $info='<?php if(!empty($gydRfiAuto[\'piezas\']) || !empty($gydRfiAuto[\'revisar\'])): ?>'.
      '<div class="alert" style="border-left:4px solid #2c76ad">Precarga orientativa: <b><?=(int)$gydRfiAuto[\'piezas\']?></b> piezas buenas sugeridas. '.
      '<?php if(!empty($gydRfiAuto[\'revisar\'])): ?><?= (int)$gydRfiAuto[\'revisar\'] ?> productos requieren validar lote/caducidad manualmente.<?php endif; ?>'.
      ' Revisa las piezas y confirma antes de guardar; esta precarga no crea inventario.</div><?php endif; ?>'."\n";
  $edits[$ver]=gy_reemplazar_una($edits[$ver],$insert,$info.$insert,$ver);
 }
 $changes=[];foreach($targets as $f)if($original[$f]!==$edits[$f])$changes[]=$f;
 if($modo==='--check'){
  echo "COMPATIBLE · sin modificaciones.\n";
  echo "Páginas nuevas: ".count($creaciones)." · archivos existentes a actualizar: ".count($changes)."\n";
  foreach($changes as $f)echo "  ACTUALIZAR $f\n";
  echo $autoExistente?"Precarga ya instalada: se conserva sin duplicar.\n":"Precarga orientativa incluida (confirmación manual obligatoria).\n";
  exit(0);
 }
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();$nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if(!preg_match('/prueba|_lab|laboratorio|test/i',$nombre))throw new RuntimeException("Base $nombre: instalación bloqueada porque no parece de pruebas.");
 $requiredTables=['erp_ordenes_compra','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_facturas','erp_facturas_detalle','erp_facturas_revision_detalle','erp_facturas_stock_aplicaciones','erp_oc_conciliacion_eventos'];
 $st=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
 foreach($requiredTables as $table){$st->execute([$table]);if(!(int)$st->fetchColumn())throw new RuntimeException("Falta tabla $table; no se realizará ningún cambio.");}
 $backup=__DIR__.'/respaldos_integracion_rfi_'.date('Ymd_His');
 if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No pudo crearse respaldo.');
 $written=[];$created=[];
 try{
  foreach($changes as $f){
   $old=$root.'/'.$f;$safe=$backup.'/'.str_replace('/','__',$f);
   if(!copy($old,$safe))throw new RuntimeException("No se pudo respaldar $f");
   $written[$old]=$safe;
  }
  foreach($creaciones as $f)if(!is_file($root.'/'.$f)){
   $dst=$root.'/'.$f;$tmp=$dst.'.rfitmp'.getmypid();
   if(!copy($payload.'/'.$f,$tmp)||!rename($tmp,$dst))throw new RuntimeException("No pudo crearse $f");
   $created[]=$dst;
  }
  foreach($changes as $f){
   $dst=$root.'/'.$f;$tmp=$dst.'.rfitmp'.getmypid();
   if(file_put_contents($tmp,$edits[$f],LOCK_EX)===false||!rename($tmp,$dst))throw new RuntimeException("No se pudo actualizar $f");
  }
  echo "INSTALADO sin modificar base de datos ni existencias.\nRespaldos: $backup\n";
  echo "Abrir: http://localhost/almacen-farmacia2/public/erp_recepciones_pendientes.php\n";
 }catch(Throwable $e){
  foreach($written as $old=>$save)@copy($save,$old);
  foreach($created as $f)@unlink($f);
  throw $e;
 }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
