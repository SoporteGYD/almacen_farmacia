<?php
declare(strict_types=1);
/** G&D 2026-10-26 — reparar resolución automática y hacer observaciones opcionales.
 *  Seguro: --check previo, respaldos y rollback de archivos, sin SQL de cambios de stock.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI.');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_alertas_fix_v2.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$src=__DIR__.'/alertas_fix_fuentes';
$changes=[];$notes=[];$errors=[];
$read=function(string $rel)use($root,&$changes,&$errors):?string{
    if(isset($changes[$rel]))return $changes[$rel];
    $f=$root.'/'.$rel;
    if(!is_file($f)){$errors[]='Falta módulo requerido: '.$rel;return null;}
    $c=file_get_contents($f);
    if($c===false){$errors[]='No se puede leer: '.$rel;return null;}
    return $c;
};
$set=function(string $rel,string $c,string $note)use(&$changes,&$notes):void{$changes[$rel]=$c;$notes[]=$note;};
$replace=function(string $rel,string $old,string $new,string $reason)use($read,$set,&$notes,&$errors):void {
    $c=$read($rel);if($c===null)return;
    if(str_contains($c,$new)){$notes[]='YA INSTALADO: '.$reason;return;}
    if(substr_count($c,$old)!==1){$errors[]='INCOMPATIBLE: '.$rel.' difiere en '.$reason.'. No sobrescribir.';return;}
    $set($rel,str_replace($old,$new,$c),'ACTUALIZAR: '.$reason);
};
// 1. Origen documental para generar alertas. Sin esto, factura_id siempre queda en 0.
$replace('app/helpers/erp_alertas_compras.php',
 "      'costo'=>\$provider['costo']??null,'precio'=>\$p['precio'],'variacion'=>\$delta,'texto'=>\$message];",
 "      'costo'=>\$provider['costo']??null,'precio'=>\$p['precio'],\n      // GYD_ALERTAS_FIX_V2_IDS: preservar factura y proveedor de la compra consultada.\n      'factura_id'=>(int)(\$provider['factura_id']??0),'proveedor_id'=>(int)(\$provider['id']??0),\n      'variacion'=>\$delta,'texto'=>\$message];",
 'enlazar alertas con factura y proveedor');
// 2. Conservar huella histórica original, aunque ahora recibamos los IDs (sin generar casos nuevos en masa).
$replace('app/helpers/erp_compras_alertas_seguimiento.php',
 "      (string)(int)(\$event['proveedor_id']??0),(string)(int)(\$event['factura_id']??0),",
 "      // GYD_ALERTAS_FIX_V2_HUELLA: conservar huellas originales (antes los IDs eran cero).\n      '0','0',",
 'compatibilidad con alertas existentes');
$replace('app/helpers/erp_compras_alertas_seguimiento.php',
 "ON DUPLICATE KEY UPDATE vigente=1,detectado_en=NOW(),descripcion_alerta=VALUES(descripcion_alerta),nivel=VALUES(nivel)",
 "ON DUPLICATE KEY UPDATE vigente=1,detectado_en=NOW(),descripcion_alerta=VALUES(descripcion_alerta),nivel=VALUES(nivel),factura_id=VALUES(factura_id),proveedor_id=VALUES(proveedor_id)",
 'actualizar factura y proveedor de alertas existentes');
$replace('app/helpers/erp_compras_alertas_seguimiento.php',
 "    if((\$stateChanged&&in_array(\$status,['ATENDIDA','DESCARTADA'],true))&&\$note==='')\n      throw new InvalidArgumentException('Para atender o descartar una alerta debes indicar el motivo.');",
 "    // GYD_ALERTAS_FIX_V2_NOTA_OPCIONAL: los cambios de estado quedan auditados aun sin texto.",
 'observación opcional en seguimiento');
// El Centro de Seguimiento tampoco debe indicar que la observación es obligatoria.
$replace('public/erp_compras_alertas_seguimiento.php',
 'Especifica la revisión. Es obligatorio para Atendida o Descartada.',
 'Observación opcional; el cambio de estado queda registrado en el historial.',
 'ayuda del seguimiento con nota opcional');
// 3. Actualizar únicamente la función de verificación de la revisión anterior, conservando otras personalizaciones.
$dest='app/helpers/erp_compras_alertas_auto.php';
$c=$read($dest);$legacy=@file_get_contents($src.'/legacy_erp_compras_alertas_auto.php');
$next=@file_get_contents($src.'/app/helpers/erp_compras_alertas_auto.php');
if($c!==null){
  if($next===false||$legacy===false)$errors[]='Archivos fuente de verificación no encontrados.';
  elseif($c===$next)$notes[]='YA INSTALADO: validador automático V2';
  elseif($c!==$legacy)$errors[]='INCOMPATIBLE: '.$dest.' tiene cambios propios. No se reemplazará el validador.';
  else $set($dest,$next,'ACTUALIZAR: cálculo y validación con factura reciente, independiente del ID histórico');
}
// 4. Formulario de decisión y navegación; no obligatorio escribir notas.
$replace('public/erp_compras_alerta_resolver.php',
 "    if(strlen(\$nota)<8 || strlen(\$nota)>1800)throw new InvalidArgumentException('Describe el motivo o la acción realizada (de 8 a 1800 caracteres).');",
 "    // GYD_ALERTAS_FIX_V2_NOTA_SERVIDOR: la explicación es opcional.\n    if(strlen(\$nota)>1800)throw new InvalidArgumentException('La observación supera 1800 caracteres.');",
 'observación opcional del formulario');
$replace('public/erp_compras_alerta_resolver.php',
 "    gdca_edit_case(\$db,\$user,\$id,\$nuevo,(int)(\$caso['asignado_a']??0),\$nota);",
 "    // GYD_ALERTAS_FIX_V2_NO_DUPLICAR_REVISION: abrir otra vez sin crear un movimiento EN_REVISION -> EN_REVISION.\n    if(\$nuevo==='EN_REVISION' && (string)\$caso['estado']==='EN_REVISION' && \$nota===''){\n        header('Location: '.gdca_auto_ruta_caso(\$db,\$caso),true,303);exit;\n    }\n    gdca_edit_case(\$db,\$user,\$id,\$nuevo,(int)(\$caso['asignado_a']??0),\$nota);",
 'no duplicar movimientos de revisión sin observación');
$replace('public/erp_compras_alerta_resolver.php',
 ' minlength="8" maxlength="1800" required',
 ' maxlength="1800"',
 'formularios con nota opcional');
$replace('public/erp_compras_alerta_resolver.php',
 'o descártala con justificación.',
 'o descártala si no procede.',
 'mensaje de cierre manual compatible con notas opcionales');
// La sustitución anterior afecta a tres textareas; el parche aplica reemplazo de todas tras verificar cantidad.
// Si la reemplazadora anterior rechazó por 3 coincidencias, resolverlo explícitamente de modo seguro.
$rel='public/erp_compras_alerta_resolver.php';$text=$read($rel);
if($text!==null){
 $old='minlength="8" maxlength="1800" required';$new='maxlength="1800"';
 $cnt=substr_count($text,$old);
 if($cnt===3){$set($rel,str_replace($old,$new,$text),'ACTUALIZAR: tres observaciones no obligatorias');
   $errors=array_values(array_filter($errors,static fn($e)=>!str_contains($e,'formularios con nota opcional')));
 }elseif($cnt===0 && substr_count($text,$new)>=3){$notes[]='YA INSTALADO: tres observaciones no obligatorias';
   $errors=array_values(array_filter($errors,static fn($e)=>!str_contains($e,'formularios con nota opcional')));
 }else{$errors[]='INCOMPATIBLE: número inesperado de campos de observación ('.$cnt.').';}
}
$replace('public/erp_compras_alerta_resolver.php',
 'Ambas decisiones requieren motivo y quedan auditadas.',
 'Las observaciones son opcionales; los cambios de estado quedan en el historial de auditoría.',
 'texto explicativo de observaciones');
// Revisión manual con un botón GET de revalidación y visualización del margen actual.
$replace('public/erp_compras_alerta_resolver.php',
 " if(!gdca_tables_ok(\$db))throw new RuntimeException('Instala primero el Centro de Seguimiento de Alertas de Compras.');",
 " if(!gdca_tables_ok(\$db))throw new RuntimeException('Instala primero el Centro de Seguimiento de Alertas de Compras.');\n // GYD_ALERTAS_FIX_V2_FORZAR_COMPROBACION: permite revisar inmediatamente sin esperar sincronización.\n if(isset(\$_GET['comprobar'])) gdca_sync(\$db,true);",
 'botón para comprobar el estado actualizado');
$replace('public/erp_compras_alerta_resolver.php',
 " \$st=\$db->prepare('SELECT usuario_nombre,accion,estado_anterior,estado_nuevo,observacion,creado_en FROM erp_compras_alerta_historial WHERE caso_id=? ORDER BY id DESC LIMIT 15');",
 " // GYD_ALERTAS_FIX_V2_ESTADO: muestra margen comprobable, no el costo de catálogo usado como referencia histórica.\n \$estadoActual=gdca_auto_tipo_verificable((string)\$caso['tipo'])?gdca_auto_contexto(\$db,\$caso):null;\n \$st=\$db->prepare('SELECT usuario_nombre,accion,estado_anterior,estado_nuevo,observacion,creado_en FROM erp_compras_alerta_historial WHERE caso_id=? ORDER BY id DESC LIMIT 15');",
 'informar margen calculado y motivo de alerta vigente');
$replace('public/erp_compras_alerta_resolver.php',
 '<p class="muted"><?=$h($caso[\'descripcion_alerta\'])?></p>',
 <<<'MARKUP'
<p class="muted"><?=$h($caso['descripcion_alerta'])?></p>
<?php /* GYD_ALERTAS_FIX_V2_DIAGNOSTICO_VISUAL */ if(!empty($estadoActual) && $estadoActual['factura_id']>0): ?>
<p class="warning">Comprobación actual: costo de última factura reciente <strong><?=$h($money($estadoActual['costo']))?></strong>;
precio público de esta sucursal <strong><?=$h($money($estadoActual['precio']))?></strong>;
margen estimado <strong><?=$estadoActual['margen']===null?'Sin precio':$h(number_format($estadoActual['margen'],2,'.','').'%')?></strong>.
<?php if($caso['tipo']==='MARGEN_BAJO'): ?>La alerta seguirá pendiente mientras el margen sea inferior al 15%.<?php endif; ?>
<?php if($caso['tipo']==='PERDIDA'): ?>La alerta seguirá pendiente mientras el precio sea menor al costo facturado.<?php endif; ?></p>
<?php elseif(!empty($estadoActual)): ?><p class="warning"><?=$h($estadoActual['motivo']?:'No hay datos suficientes para verificar automáticamente esta alerta.')?></p><?php endif; ?>
<a class="btn secondary" href="erp_compras_alerta_resolver.php?caso=<?=$id?>&amp;comprobar=1">Comprobar de nuevo</a>
MARKUP,
 'mostrar diagnóstico actualizado y revalidación manual');
// 5. Test de lógica puro en nuevo archivo.
$test='tests/probar_alertas_fix_v2.php';$testSrc=@file_get_contents($src.'/tests/probar_alertas_fix_v2.php');
if($testSrc===false)$errors[]='No se encontró el archivo de pruebas.';
else{
  $existing=$root.'/'.$test;
  if(is_file($existing)){if(file_get_contents($existing)!==$testSrc)$errors[]='INCOMPATIBLE: ya existe '.$test.' con otros contenidos.';
    else $notes[]='YA INSTALADO: '.$test;
  }else $set($test,$testSrc,'CREAR: '.$test);
}
// Validar sintaxis de cada archivo resultante antes de tocar nada.
foreach($changes as $rel=>$body){
  if(!str_ends_with($rel,'.php'))continue;
  $temp=tempnam(sys_get_temp_dir(),'gdav2_');if($temp===false){$errors[]='No se pudo crear un temporal.';continue;}
  file_put_contents($temp,$body);$log=[];$status=0;
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1',$log,$status);unlink($temp);
  if($status!==0)$errors[]='PHP inválido '.$rel.': '.implode(' ',$log);
}
foreach($notes as $note)echo $note."\n";
if($errors){foreach($errors as $error)fwrite(STDERR,$error."\n");fwrite(STDERR,"INCOMPATIBLE: no se modificó ningún archivo.\n");exit(3);}
echo 'COMPATIBLE: '.count($changes)." archivos nuevos o por actualizar; ninguna tabla ni existencia se modifica durante la instalación.\n";
if($mode==='--check')exit(0);
if(!$changes){echo "YA INSTALADO.\n";exit(0);}
$back=$root.'/tools/backups_gyd_alertas_fix_v2_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($back,0700,true)){fwrite(STDERR,"Sin permiso para respaldos.\n");exit(4);}
foreach($changes as $rel=>$body){
  $file=$root.'/'.$rel;if(!is_file($file))continue;
  $dst=$back.'/'.$rel;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0700,true);
  if(!copy($file,$dst)){fwrite(STDERR,'No se pudo respaldar '.$rel."\n");exit(4);}
}
$written=[];
try{
 foreach($changes as $rel=>$body){
  $file=$root.'/'.$rel;if(!is_dir(dirname($file)))mkdir(dirname($file),0755,true);
  $tmp=$file.'.gydalertv2_'.getmypid();
  if(file_put_contents($tmp,$body,LOCK_EX)===false || !rename($tmp,$file))throw new RuntimeException('No se pudo escribir '.$rel);
  $written[]=$rel;
 }
}catch(Throwable $e){foreach(array_reverse($written) as $rel){$old=$back.'/'.$rel;$dst=$root.'/'.$rel;if(is_file($old))copy($old,$dst);else @unlink($dst);}fwrite(STDERR,'Se revirtieron los cambios: '.$e->getMessage()."\n");exit(5);}
echo "INSTALADO. Respaldos: $back\nPruebas: php tests/probar_alertas_fix_v2.php\n";
