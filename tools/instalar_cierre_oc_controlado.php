<?php
declare(strict_types=1);
/** Instalación incremental de cierre administrativo OC. Bloqueos transaccionales sin editar stock. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: --check o --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/cierre_payload';$changes=[];$notes=[];$errors=[];
$add=function(string $path,string $source)use($root,&$changes,&$notes,&$errors){
 if(!is_file($source)){$errors[]="Falta payload $path";return;}
 $dest=$root.'/'.$path;$data=(string)file_get_contents($source);
 if(!is_file($dest)){$changes[$path]=$data;$notes[]="CREAR $path";}
 elseif(hash_equals(hash('sha256',$data),hash_file('sha256',$dest)))$notes[]="YA INSTALADO $path";
 else $errors[]="INCOMPATIBLE: $path ya existe con cambios; no se sobrescribe";
};
foreach(['app/helpers/erp_oc_cierre_controlado.php','public/erp_oc_cierre_controlado.php'] as $path)$add($path,$payload.'/'.$path);
$replace=function(string $path,string $needle,string $text,string $marker,bool $mandatory=true)use($root,&$changes,&$notes,&$errors){
 $dest=$root.'/'.$path;
 if(!is_file($dest)){if($mandatory)$errors[]="Falta $path";else $notes[]="OPCIONAL sin archivo $path";return;}
 $src=$changes[$path]??(string)file_get_contents($dest);
 if(str_contains($src,$marker)){$notes[]="YA INSTALADO control $path";return;}
 if(substr_count($src,$needle)!==1){
  if($mandatory)$errors[]="INCOMPATIBLE: no se reconoce punto de integración seguro en $path";
  else $notes[]="ACCESO OPCIONAL OMITIDO $path";
  return;
 }
 $changes[$path]=str_replace($needle,$text,$src);$notes[]="INTEGRAR control en $path";
};
$inc="require_once __DIR__.'/../app/helpers/erp_oc_cierre_controlado.php'; // GYD_GCC_REQUIRE_V1\n";
$guard="gcc_exigir_abierta(\$db,\$id); // GYD_GCC_GUARD_RECEPCION_V1\n";
// La recepción con incidencias es la ruta canónica, incluyendo la URL original delegada.
$replace('public/erp_recepcion_calidad.php',"requireLogin();",$inc."requireLogin();",'GYD_GCC_REQUIRE_V1',true);
$replace('public/erp_recepcion_calidad.php',"  if(!erpr_puede_registrar_destino(\$acceso,(string)\$oc['destino']))", "  $guard  if(!erpr_puede_registrar_destino(\$acceso,(string)\$oc['destino']))",'GYD_GCC_GUARD_RECEPCION_V1',true);
// Para instalaciones sin la delegación de la recepción original; si el archivo está delegado,
// se protege por la ruta canónica anterior y se omite el ancla antigua.
$legacy='public/erp_recepcion_registrar.php';
if(is_file($root.'/'.$legacy)){
 $old=(string)file_get_contents($root.'/'.$legacy);
 if(!str_contains($old,'ERP_GYD_RECEPCION_UNIFICADA_V1')){
   $replace($legacy,'requireLogin();',$inc.'requireLogin();','GYD_GCC_REQUIRE_V1',true);
   $replace($legacy,"        if(!erpr_puede_registrar_destino(\$acceso,(string)\$ordenBloqueada['destino']))", "        gcc_exigir_abierta(\$pdo,\$ordenId); // GYD_GCC_GUARD_RECEPCION_LEGACY_V1\n        if(!erpr_puede_registrar_destino(\$acceso,(string)\$ordenBloqueada['destino']))",'GYD_GCC_GUARD_RECEPCION_LEGACY_V1',true);
 }else $notes[]='RUTA ORIGINAL DELEGADA: utiliza ya el guard de recepción única';
}
// La factura debe respetar el cierre al precargar y al guardar (ambos casos).
$replace('public/erp_facturas.php','requireLogin();',$inc.'requireLogin();','GYD_GCC_REQUIRE_V1',true);
$replace('public/erp_facturas.php',"     \$dest=ef_sucursal_oc((string)\$ocRow['destino']);", "     gcc_exigir_abierta(\$db,(int)\$ocid); // GYD_GCC_PREVIEW_FACTURAS_V1\n     \$dest=ef_sucursal_oc((string)\$ocRow['destino']);",'GYD_GCC_PREVIEW_FACTURAS_V1',true);
$replace('public/erp_facturas.php',"   if(\$oc>0){\n     if(!\$o", "   if(\$oc>0){\n     gcc_exigir_abierta(\$db,\$oc); // GYD_GCC_GUARD_FACTURA_V1\n     if(!\$o",'GYD_GCC_GUARD_FACTURA_V1',true);
// La verificación y resolución de incidencias de facturas también alteran el saldo documental.
$replace('public/erp_facturas_verificacion.php','requireLogin();',$inc.'requireLogin();','GYD_GCC_REQUIRE_V1',true);
$replace('public/erp_facturas_verificacion.php',"        \$lkOC->execute([(int)\$factura['orden_id']]);", "        \$lkOC->execute([(int)\$factura['orden_id']]);\n        gcc_exigir_abierta(\$db,(int)\$factura['orden_id']); // GYD_GCC_GUARD_VERIFICACION_V1",'GYD_GCC_GUARD_VERIFICACION_V1',true);
// No se pueden aplicar otras partidas a una factura ligada a OC cerrada.
$replace('public/erp_facturas_aplicar.php','requireLogin();',$inc.'requireLogin();','GYD_GCC_REQUIRE_V1',true);
$replace('public/erp_facturas_aplicar.php',"            \$lock->execute([\$orderId]);", "            \$lock->execute([\$orderId]);\n            gcc_exigir_abierta(\$db,\$orderId); // GYD_GCC_APLICAR_FACTURA_V1",'GYD_GCC_APLICAR_FACTURA_V1',true);
// Guard de las Entradas OC protegidas (se ejecuta con bloqueo de fila de orden).
$replace('app/helpers/gyd_oc_stock_guard.php',"    if(!\$oc)throw new RuntimeException('La OC vinculada ya no existe.');", "    if(!\$oc)throw new RuntimeException('La OC vinculada ya no existe.');\n    require_once __DIR__.'/erp_oc_cierre_controlado.php';\n    gcc_exigir_abierta(\$db,\$ocId); // GYD_GCC_GUARD_ENTRADAS_V1",'GYD_GCC_GUARD_ENTRADAS_V1',true);
// Ocultar los pedidos cerrados de bandeja de recepción; no cambia estados históricos de la OC.
$replace('app/helpers/erp_oc_recepciones.php',"WHERE o.estado='EMITIDA'", "WHERE o.estado='EMITIDA' AND NOT EXISTS (SELECT 1 FROM erp_oc_cierres_controlados cx WHERE cx.orden_id=o.id AND cx.estado='CERRADA') /* GYD_GCC_BANDEJA_V1 */",'GYD_GCC_BANDEJA_V1',true);
// Regla de acceso del rol COMPRAS (si hay lista blanca instalada).
$modules='app/helpers/erp_compras_modulos.php';
if(is_file($root.'/'.$modules)){
 $replace($modules,"        'erp_compras_inicio.php'=>true,", "        'erp_compras_inicio.php'=>true,\n        'erp_oc_cierre_controlado.php'=>true, // GYD_GCC_RUTA_COMPRAS_V1",'GYD_GCC_RUTA_COMPRAS_V1',true);
 $replace($modules,"        'Reportes, costos y alertas' => [", "        'Reportes, costos y alertas' => [\n            ['titulo'=>'Cierre controlado de OC', 'ruta'=>'erp_oc_cierre_controlado.php', 'detalle'=>'Cierre total, faltantes definitivos y reapertura auditada'], // GYD_GCC_MENU_COMPRAS_V1",'GYD_GCC_MENU_COMPRAS_V1',false);
}
// Menús opcionales: enlaces a módulo separado, sin reescribir tablas de estados existentes.
$replace('public/erp_oc_win.php','<a class="nav-action" href="erp_analisis_compras.php">Análisis de compras</a>', '<a class="nav-action" href="erp_analisis_compras.php">Análisis de compras</a> <a class="nav-action" href="erp_oc_cierre_controlado.php">Cierre de OC</a><!-- GYD_GCC_MENU_OC_V1 -->','GYD_GCC_MENU_OC_V1',false);
$replace('public/erp_conciliacion_integral.php','<a class="btn alt" href="erp_oc_win.php?ver=<?=(int)$o[\'id\']?>">Ver orden</a>', '<a class="btn alt" href="erp_oc_win.php?ver=<?=(int)$o[\'id\']?>">Ver orden</a> <a class="btn alt" href="erp_oc_cierre_controlado.php?oc=<?=(int)$o[\'id\']?>">Cierre controlado</a><!-- GYD_GCC_MENU_CI_V1 -->','GYD_GCC_MENU_CI_V1',false);
foreach($changes as $path=>$data){if(!str_ends_with($path,'.php'))continue;$tmp=tempnam(sys_get_temp_dir(),'gcc_');file_put_contents($tmp,$data);$out=[];$rc=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$rc);@unlink($tmp);if($rc!==0)$errors[]="Sintaxis $path: ".implode(' ',$out);}
foreach($notes as $msg)echo $msg."\n";
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"SIN CAMBIOS. Resuelve la incompatibilidad; no fuerces la instalación.\n");exit(3);}
echo "COMPATIBLE: ".count($changes)." archivo(s); migración de 3 tablas de cierre sin modificar stock.\n";
if($mode==='--check')exit(0);
if(!$changes){echo "YA INSTALADO.\n";exit(0);}
// Respaldar ANTES de escribir SQL o archivos.
$backup=$root.'/tools/respaldos_cierre_oc_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(4);}
foreach($changes as $path=>$data){$old=$root.'/'.$path;if(!is_file($old))continue;$out=$backup.'/'.$path;if(!is_dir(dirname($out)))mkdir(dirname($out),0700,true);if(!copy($old,$out)){fwrite(STDERR,"Fallo al respaldar $path.\n");exit(4);}}
// Solo 3 CREATE TABLE IF NOT EXISTS. Instalación no borra ni cambia órdenes existentes.
try{
 require_once $root.'/app/config/database.php';
 $db=(new Database())->connect();
 $base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($base!=='prueba')throw new RuntimeException('Protección: conectado a '.($base?:'(sin base)').'. Este paquete está autorizado únicamente para la base prueba.');
 echo "BASE SELECCIONADA: $base\n";
 $sql=(string)file_get_contents($payload.'/sql/erp_oc_cierres_controlados.sql');
 foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $query){$query=trim(preg_replace('/^\s*--[^\n]*\n/m','',$query));if($query!=='')$db->exec($query);}
 $st=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('erp_oc_cierres_controlados','erp_oc_cierres_partidas','erp_oc_cierres_bitacora')");
 if((int)$st->fetchColumn()!==3)throw new RuntimeException('No se confirmaron las 3 tablas.');
}catch(Throwable $ex){fwrite(STDERR,"SQL NO INSTALADO: ".$ex->getMessage().". No se alteraron archivos PHP.\n");exit(5);}
$written=[];
try{
 foreach($changes as $path=>$data){$dest=$root.'/'.$path;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0755,true);$tmp=$dest.'.gcc_tmp_'.getmypid();if(file_put_contents($tmp,$data,LOCK_EX)===false || !rename($tmp,$dest))throw new RuntimeException('No se escribió '.$path);$written[]=$path;}
}catch(Throwable $ex){foreach(array_reverse($written) as $path){$before=$backup.'/'.$path;if(is_file($before))copy($before,$root.'/'.$path);else @unlink($root.'/'.$path);}fwrite(STDERR,'Error en archivos: '.$ex->getMessage().". Restaurados archivos cuando fue posible.\n");exit(6);}
echo "INSTALADO. Respaldo: $backup\n";
echo "Revisar: http://localhost/almacen-farmacia2/public/erp_oc_cierre_controlado.php\n";
