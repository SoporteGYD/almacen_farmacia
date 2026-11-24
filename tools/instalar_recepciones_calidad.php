<?php
declare(strict_types=1);
/** Instalación incremental para recepción de OC con calidad y faltantes. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Ejecuta este instalador desde PowerShell.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php instalar_recepciones_calidad.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/rq_payload';
$requisitos=['app/helpers/erp_oc_recepciones.php','public/erp_recepciones_pendientes.php','public/erp_recepcion_registrar.php','public/erp_recepciones_historial.php','app/config/database.php'];
$creaciones=['app/helpers/erp_recepcion_calidad_reglas.php','public/erp_recepcion_calidad.php','public/erp_recepciones_calidad_historial.php'];
foreach($requisitos as $ruta){if(!is_file($root.'/'.$ruta)){fwrite(STDERR,"INCOMPATIBLE: falta $ruta\n");exit(1);}}
$helper=file_get_contents($root.'/app/helpers/erp_oc_recepciones.php');
foreach(['function erpr_detalles','function erpr_acceso','function erpr_puede_registrar_destino'] as $nombre){if(!str_contains($helper,$nombre)){fwrite(STDERR,"INCOMPATIBLE: falta $nombre\n");exit(1);}}
foreach($creaciones as $ruta){
 if(!is_file($payload.'/'.$ruta)){fwrite(STDERR,"PAQUETE INCOMPLETO: $ruta\n");exit(1);}
 if(is_file($root.'/'.$ruta) && hash_file('sha256',$root.'/'.$ruta)!==hash_file('sha256',$payload.'/'.$ruta)){
  fwrite(STDERR,"INCOMPATIBLE: $ruta ya tiene modificaciones locales. No se sobrescribirá.\n");exit(1);
 }
}
require_once $root.'/app/config/database.php';
try{
 $db=(new Database())->connect();$nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 $baseNecesaria=['erp_ordenes_compra','erp_ordenes_compra_detalle','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_oc_autorizaciones'];
 $stmt=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
 foreach($baseNecesaria as $nombreTabla){$stmt->execute([$nombreTabla]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Falta tabla '.$nombreTabla.'. Instala antes los módulos de OC y recepciones.');}
 $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_oc_recepcion_incidencias'")->fetchAll(PDO::FETCH_COLUMN);
 if($cols && count(array_diff(['recepcion_id','orden_detalle_id','producto_id','tipo','cantidad_piezas','observaciones','codigo_reportado','descripcion_reportada'],$cols))>0)throw new RuntimeException('Existe erp_oc_recepcion_incidencias pero su estructura es incompatible.');
}catch(Throwable $e){fwrite(STDERR,"INCOMPATIBLE con MariaDB: ".$e->getMessage()."\n");exit(1);}
$parches=[];$advertencias=[];
$dest=$root.'/public/erp_recepciones_pendientes.php';$texto=file_get_contents($dest);
if(!str_contains($texto,'erp_recepcion_calidad.php?')){
 $viejo='<a class="btn" href="erp_recepcion_registrar.php?id=<?=(int)$r[\'id\']?>">Registrar recepción</a>';
 $nuevo=$viejo.' <a class="btn secondary" href="erp_recepcion_calidad.php?id=<?=(int)$r[\'id\']?>">Registrar buenas y diferencias</a>';
 if(str_contains($texto,$viejo))$parches[$dest]=str_replace($viejo,$nuevo,$texto);
 else $advertencias[]='No se encontró el botón original en Recepciones pendientes. La nueva pantalla funcionará con su URL directa.';
}
$dest2=$root.'/public/erp_recepcion_registrar.php';$texto2=file_get_contents($dest2);
if(!str_contains($texto2,'erp_recepcion_calidad.php?')){
 $viejo='href="erp_recepciones_pendientes.php">← Pendientes</a>';
 $nuevo=$viejo.' <a href="erp_recepcion_calidad.php?id=<?=$ordenId?>">Registrar diferencias (buenas/dañadas/faltantes)</a>';
 if(str_contains($texto2,$viejo))$parches[$dest2]=str_replace($viejo,$nuevo,$texto2);
 else $advertencias[]='No se encontró el enlace del receptor antiguo. Accede desde Recepciones pendientes.';
}
$dest3=$root.'/public/erp_recepciones_historial.php';$texto3=file_get_contents($dest3);
if(!str_contains($texto3,'erp_recepciones_calidad_historial.php?')){
 $viejo='<h2>Detalle de <?=erpr_html($detalle[\'folio\'])?></h2>';
 $nuevo=$viejo.'<p><a class="btn secondary" href="erp_recepciones_calidad_historial.php?rec=<?=(int)$detalle[\'id\']?>">Ver incidencias y entregas complementarias</a></p>';
 if(str_contains($texto3,$viejo))$parches[$dest3]=str_replace($viejo,$nuevo,$texto3);
 else $advertencias[]='No se encontró el encabezado de historial tradicional. Accede desde la nueva recepción.';
}
foreach($advertencias as $warning)echo "AVISO: $warning\n";
echo "Base seleccionada: $nombre\n";
echo "Nuevas pantallas: ".count($creaciones)." · enlaces: ".count($parches)."\n";
if($modo==='--check'){echo "COMPATIBLE (comprobación sin escrituras).\n";exit(0);}
if(!preg_match('/prueba|_lab|laboratorio|test/i',$nombre)){
 fwrite(STDERR,"BLOQUEADO: La base '$nombre' no parece de pruebas. Este paquete está autorizado exclusivamente para laboratorio.\n");exit(1);
}
$backupDir=__DIR__.'/respaldos_calidad_'.date('Ymd_His');
if(!is_dir($backupDir) && !mkdir($backupDir,0775,true))throw new RuntimeException('No fue posible crear directorio de respaldo.');
$restaurar=[];$nuevos=[];
try{
 // Esta DDL es independiente y no actualiza filas de productos ni existencias.
 $sql=file_get_contents($payload.'/sql/recepciones_calidad.sql');
 $sql=preg_replace('/^\s*--[^\n]*$/m','',$sql);
 $db->exec(trim($sql));
 $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_oc_recepcion_incidencias'")->fetchAll(PDO::FETCH_COLUMN);
 if(count(array_diff(['recepcion_id','orden_detalle_id','producto_id','tipo','cantidad_piezas','observaciones'], $cols))>0)throw new RuntimeException('La tabla de calidad no se creó correctamente.');
 foreach($creaciones as $ruta){
  if(is_file($root.'/'.$ruta))continue;
  $tmp=$root.'/'.$ruta.'.tmp'.getmypid();
  if(!copy($payload.'/'.$ruta,$tmp)||!rename($tmp,$root.'/'.$ruta))throw new RuntimeException('No se pudo crear '.$ruta);
  $nuevos[]=$root.'/'.$ruta;
 }
 foreach($parches as $original=>$nuevoTexto){
  $ruta=str_replace($root.'/','',$original);$backup=$backupDir.'/'.str_replace('/','__',$ruta);
  if(!copy($original,$backup))throw new RuntimeException('No se pudo respaldar '.$ruta);
  $restaurar[$original]=$backup;
  $tmp=$original.'.tmp'.getmypid();
  if(file_put_contents($tmp,$nuevoTexto)===false||!rename($tmp,$original))throw new RuntimeException('No se pudo actualizar '.$ruta);
 }
 echo "INSTALADO. Respaldos: $backupDir\n";
 echo "Abre: http://localhost/almacen-farmacia2/public/erp_recepciones_pendientes.php\n";
}catch(Throwable $e){
 foreach($restaurar as $original=>$backup)copy($backup,$original);
 foreach($nuevos as $f)@unlink($f);
 fwrite(STDERR,"ERROR: ".$e->getMessage().". Se restauraron archivos; la tabla vacía puede permanecer.\n");exit(1);
}
