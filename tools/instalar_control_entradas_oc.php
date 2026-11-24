<?php
declare(strict_types=1);
/** Instalador G&D: vínculo transaccional contra doble entrada en OC. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--check-archivos','--apply'],true)){fwrite(STDERR,"Uso: php instalar_control_entradas_oc.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/payload';
$required=[
 'app/config/database.php','app/helpers/erp_oc_recepciones.php','app/models/Movimiento.php',
 'public/erp_facturas_aplicar.php','public/erp_recepciones_calidad_historial.php','public/entradas.php',
 'public/erp_recepcion_calidad.php'
];
$creates=['app/helpers/gyd_oc_stock_guard.php','public/erp_oc_entrada_protegida.php','tests/probar_control_entradas_oc.php'];
foreach($required as $p)if(!is_file($root.'/'.$p)){fwrite(STDERR,"INCOMPATIBLE: falta $p\n");exit(1);}
foreach($creates as $p){
 if(!is_file($payload.'/'.$p)){fwrite(STDERR,"PAQUETE INCOMPLETO: $p\n");exit(1);}
 if(is_file($root.'/'.$p)&&hash_file('sha256',$root.'/'.$p)!==hash_file('sha256',$payload.'/'.$p)){
  fwrite(STDERR,"INCOMPATIBLE: $p tiene cambios locales; no será reemplazado.\n");exit(1);
 }
}
function gyd_modificar_metodo(string $text,string $method,string $needle,string $inject,string $marker):string{
 $where=strpos($text,'function '.$method.'(');
 if($where===false)throw new RuntimeException('No se encontró método '.$method);
 $limit=strpos($text,'public function ',$where+strlen('function '.$method.'('));
 if($limit===false)$limit=strlen($text);
 $seg=substr($text,$where,$limit-$where);
 if(str_contains($seg,$marker))return $text;
 if(substr_count($seg,$needle)!==1)throw new RuntimeException('Anclaje de '.$method.' incompatible: no se modificará el método.');
 $modified=str_replace($needle,$needle.$inject,$seg);
 return substr($text,0,$where).$modified.substr($text,$limit);
}
$mods=[];
try{
 $model=(string)file_get_contents($root.'/app/models/Movimiento.php');
 $head="require_once __DIR__ . '/Proveedor.php';";
 if(!str_contains($model,'gyd_oc_stock_guard.php')){
  if(substr_count($model,$head)!==1)throw new RuntimeException('No se reconoce la cabecera de Movimiento.php');
  $model=str_replace($head,$head."\nrequire_once __DIR__ . '/../helpers/gyd_oc_stock_guard.php';",$model);
 }
 $model=gyd_modificar_metodo($model,'crearMovimiento',
  '$movimientoId = (int)$this->conn->lastInsertId();',
  "\n\n            // ERP G&D: registrar vínculo y validar cupo en esta misma transacción, ANTES del stock.\n            gyd_oc_stock_entrada(\$this->conn,\$data,\$detalle,\$movimientoId);",
  'gyd_oc_stock_entrada(');
 $model=gyd_modificar_metodo($model,'cancelarEntrada',
  '$this->conn->beginTransaction();',
  "\n            gyd_oc_stock_bloquear_edicion(\$this->conn,\$movimientoId); // Entrada ligada a OC",
  'gyd_oc_stock_bloquear_edicion(');
 $model=gyd_modificar_metodo($model,'actualizarMovimiento',
  '$this->conn->beginTransaction();',
  "\n        gyd_oc_stock_bloquear_edicion(\$this->conn,\$movimientoId); // Entrada ligada a OC",
  'gyd_oc_stock_bloquear_edicion(');
 if($model!==file_get_contents($root.'/app/models/Movimiento.php'))$mods['app/models/Movimiento.php']=$model;
 $fact=(string)file_get_contents($root.'/public/erp_facturas_aplicar.php');
 if(!str_contains($fact,'gyd_oc_stock_guard.php')){
  $needle="require_once __DIR__.'/../app/helpers/erp_oc_seguimiento.php';";
  if(substr_count($fact,$needle)!==1)throw new RuntimeException('Cabecera de facturas_aplicar.php distinta.');
  $fact=str_replace($needle,$needle."\nrequire_once __DIR__.'/../app/helpers/gyd_oc_stock_guard.php';",$fact);
 }
 if(!str_contains($fact,'gyd_oc_stock_factura(')){
  $needle="erp_oc_proteger_aplicacion_neta(\$db,(int)(\$locked['orden_id']??0),\$fid,\$pid,\$qty);";
  if(substr_count($fact,$needle)!==1)throw new RuntimeException('No se reconoce la protección de aplicación en Facturas.');
  $fact=str_replace($needle,$needle."\n            // ERP G&D: valida también las Entradas clásicas vinculadas de la misma OC/bodega.\n            if(\$target['tipo']==='ALMACEN')gyd_oc_stock_factura(\$db,(int)(\$locked['orden_id']??0),\$pid,\$qty,(int)\$target['almacen']);",$fact);
 }
 if($fact!==file_get_contents($root.'/public/erp_facturas_aplicar.php'))$mods['public/erp_facturas_aplicar.php']=$fact;
 $history=(string)file_get_contents($root.'/public/erp_recepciones_calidad_historial.php');
 $history=str_replace('href="entradas.php?oc_recepcion=<?=$rid?>"','href="erp_oc_entrada_protegida.php?rec=<?=$rid?>"',$history);
 $history=str_replace('Preparar Entrada de Almacén (solo buenas)','Registrar Entrada protegida (solo buenas)',$history);
 if($history!==file_get_contents($root.'/public/erp_recepciones_calidad_historial.php'))$mods['public/erp_recepciones_calidad_historial.php']=$history;
 $entradas=(string)file_get_contents($root.'/public/entradas.php');
 if(!str_contains($entradas,'G&D_RUTA_OC_PROTEGIDA')){
  $needle='requireLogin();';
  if(substr_count($entradas,$needle)!==1)throw new RuntimeException('Entradas.php tiene una cabecera distinta.');
  $entradas=str_replace($needle,$needle."\n// G&D_RUTA_OC_PROTEGIDA: enlaces antiguos hacia Entrada clásica se remiten al control OC.\nif(isset(\$_GET['oc_recepcion'])){\n    header('Location: erp_oc_entrada_protegida.php?rec='.(int)\$_GET['oc_recepcion'],true,303);exit;\n}",$entradas);
 }
 if($entradas!==file_get_contents($root.'/public/entradas.php'))$mods['public/entradas.php']=$entradas;
 if($modo==='--check-archivos'){echo 'COMPATIBLE ARCHIVOS (sin conexión a BD ni escrituras). Cambios: '.count($mods)."\n";exit(0);}
 // Comprobar compatibilidad de tablas existentes, sin ejecutar DDL en --check.
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 $needTables=['erp_ordenes_compra','erp_ordenes_compra_detalle','erp_oc_recepciones','erp_oc_recepciones_detalle',
 'erp_facturas','erp_facturas_stock_aplicaciones','movimientos','movimiento_detalle','productos','almacenes'];
 $tables=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
 foreach($needTables as $tbl){$tables->execute([$tbl]);if(!(int)$tables->fetchColumn())throw new RuntimeException('Falta tabla '.$tbl);}
 $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_oc_entradas_vinculos'")->fetchAll(PDO::FETCH_COLUMN);
 if($cols && array_diff(['orden_id','recepcion_id','recepcion_detalle_id','producto_id','movimiento_id','almacen_id','cantidad','usuario_id','solicitud_token'],$cols))throw new RuntimeException('Tabla erp_oc_entradas_vinculos incompatible con esta versión.');
 echo 'Base: '.$nombre."\n";
 echo 'Código a modificar: '.count($mods).' archivos; nuevos: '.count($creates)."\n";
 if($modo==='--check'){echo "COMPATIBLE (sin cambios).\n";exit(0);}
 if(!preg_match('/prueba|_lab|laboratorio|test/i',$nombre))throw new RuntimeException('Por seguridad solo se permite instalar en bases de PRUEBA; seleccionada: '.$nombre);
 if(!is_file($payload.'/sql/erp_oc_entradas_vinculos.sql'))throw new RuntimeException('Falta SQL del paquete.');
 // Primero crear tabla técnica (sin tocar inventario); el resto de archivos se instala con respaldos.
 $db->exec((string)file_get_contents($payload.'/sql/erp_oc_entradas_vinculos.sql'));
 $backups=__DIR__.'/respaldos_guard_'.date('Ymd_His');
 if(!is_dir($backups)&&!mkdir($backups,0775,true))throw new RuntimeException('No se pudo crear respaldo.');
 $restaurar=[];$nuevos=[];
 try{
  foreach($mods as $rel=>$text){$to=$root.'/'.$rel;$bak=$backups.'/'.str_replace('/','__',$rel);if(!copy($to,$bak))throw new RuntimeException('No se pudo respaldar '.$rel);$restaurar[$to]=$bak;}
  foreach($creates as $rel){if(is_file($root.'/'.$rel))continue;$to=$root.'/'.$rel;if(!is_dir(dirname($to))&&!mkdir(dirname($to),0775,true))throw new RuntimeException('No se creó carpeta '.dirname($to));$temp=$to.'.tmp.'.getmypid();if(!copy($payload.'/'.$rel,$temp)||!rename($temp,$to))throw new RuntimeException('Error copiando '.$rel);$nuevos[]=$to;}
  foreach($mods as $rel=>$text){$to=$root.'/'.$rel;$temp=$to.'.tmp.'.getmypid();if(file_put_contents($temp,$text,LOCK_EX)===false||!rename($temp,$to))throw new RuntimeException('Error escribiendo '.$rel);}
  echo 'INSTALADO. Respaldos: '.$backups."\n";
  echo "Abrir una recepción de Bodega e ingresar por «Registrar Entrada protegida».\n";
 }catch(Throwable $e){foreach($restaurar as $to=>$bak)@copy($bak,$to);foreach($nuevos as $f)@unlink($f);throw $e;}
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE / ERROR: '.$e->getMessage()."\n");exit(1);}
