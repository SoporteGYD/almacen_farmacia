<?php
declare(strict_types=1);
/** Retiro directo, ticket + bóveda + corte; preserva Arqueo protegido. SOLO BD prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo PHP CLI.');}
$check=in_array('--check',$argv,true);$apply=in_array('--apply',$argv,true);
if($check===$apply){fwrite(STDERR,"Uso: php tools/instalar_retiros_directos_boveda_v3.php --check | --apply\n");exit(2);}
$base=dirname(__DIR__);$payload=__DIR__.'/retiros_directos_payload';
$changes=[];$newFiles=[];$errors=[];$already=0;
function gd_once(string $src,string $old,string $new,string $name,array &$errors):string {
 if(str_contains($src,$new))return $src;
 if(substr_count($src,$old)!==1){$errors[]="INCOMPATIBLE: $name. Marcador ausente o repetido.";return $src;}
 return str_replace($old,$new,$src);
}
// Los archivos previos de retiros V2 y Arqueos separados deben existir; no se sobrescriben copias desconocidas.
$old1Archive=$payload.'/hash_pos_retiros_admitidos.json';
$approved=json_decode((string)file_get_contents($old1Archive),true,512,JSON_THROW_ON_ERROR);
$newPaths=[
 'app/helpers/gyd_retiros_directos.php',
 'public/pos_retiro_ticket.php',
 'public/pos_corte_retiros.php',
 'public/erp_boveda_verificacion.php',
 'public/erp_cortes_boveda.php',
 'tests/probar_retiros_directos.php',
 'sql/gyd_retiros_boveda.sql',
 'sql/DIAGNOSTICO_RETIROS_BOVEDA_SOLO_LECTURA.sql',
];
foreach($newPaths as $rel){
 $src=$payload.'/'.$rel;$target=$base.'/'.$rel;
 if(!is_file($src)){$errors[]='Falta el payload '.$rel;continue;}
 if(!is_file($target))$newFiles[$rel]=(string)file_get_contents($src);
 elseif(hash_file('sha256',$target)!==hash_file('sha256',$src))$errors[]='INCOMPATIBLE: archivo preexistente modificado '.$rel;
 else $already++;
}
$replace='public/pos_retiros.php';$dst=$base.'/'.$replace;
if(!is_file($dst))$errors[]='Falta recepción de retiros anterior: '.$replace;
else{
 $old=(string)file_get_contents($dst);$up=(string)file_get_contents($payload.'/'.$replace);
 $sha=hash('sha256',$old);
 if($old===$up)$already++;
 elseif(in_array($sha,$approved,true))$changes[$replace]=$up;
 else $errors[]='INCOMPATIBLE: pos_retiros.php fue modificado fuera de la versión anterior. Se conservará para no perder cambios.';
}
$patch=[
 'public/pos.php'=>[
 ['<a class="gyd-retiro-btn" id="gyd-retiro-link" href="pos_retiros.php">💵 Retiro de efectivo</a>','<a class="gyd-retiro-btn" id="gyd-retiro-link" href="pos_retiros.php">💵 Retiro de efectivo</a><a class="gyd-retiro-btn" href="pos_corte_retiros.php">🧾 Corte y tickets de retiros</a>'],
 ],
 'app/models/PuntoVenta.php'=>[
 ["return ['esperado'=>self::pesos(\$esperado),'diferencia'=>self::pesos(\$dif)];","return ['esperado'=>self::pesos(\$esperado),'diferencia'=>self::pesos(\$dif),'turno_id'=>(int)\$turno['id']];"],
 ],
 'public/assets/js/pos_gyd.js'=>[
 ["if(action==='cerrar')notify('Corte registrado · Efectivo esperado: $'+res.esperado+' · Diferencia: $'+res.diferencia);", "if(action==='cerrar'){notify('Corte registrado · Efectivo esperado: $'+res.esperado+' · Diferencia: $'+res.diferencia);await state();window.location.href='pos_corte_retiros.php?turno_id='+encodeURIComponent(String(res.turno_id));return;}"],
 ],
 'public/erp_control_cajas.php'=>[
 ["\$tipo=(string)(\$_POST['tipo']??'');","\$tipo=(string)(\$_POST['tipo']??'');\n                if(\$tipo==='RETIRO')throw new DomainException('Retiro deshabilitado aquí: utiliza Retiro de efectivo en el POS para generar folio y comprobante de bóveda.');"],
 ['<option value="RETIRO">Retiro / depósito de efectivo fuera de caja</option><option value="INGRESO">Ingreso de efectivo a caja</option>','<option value="INGRESO">Ingreso de efectivo a caja</option>'],
 ['<a href="erp_retiros_diarios.php">Retiros del día</a>','<a href="erp_retiros_diarios.php">Retiros del día</a><a href="erp_cortes_boveda.php">Cortes / bóveda</a>'],
 ],
 'public/erp_retiros_diarios.php'=>[
 ['Los retiros aquí mostrados son únicamente los <strong>confirmados y recibidos por supervisión</strong>.','Los retiros nuevos son registrados inmediatamente por el cajero; bóveda los verifica posteriormente. Aquí también aparecen los retiros históricos confirmados por un supervisor.'],
 ['Retiros entregados a supervisión','Retiros registrados en el libro POS'],
 ['<a href="erp_retiros_supervision.php">Confirmar retiros</a>','<a href="erp_cortes_boveda.php">Cortes detallados por turno</a> · <a href="erp_boveda_verificacion.php">Verificar bóveda</a>'],
 ],
];
foreach($patch as $rel=>$items){
 $dst=$base.'/'.$rel;if(!is_file($dst)){$errors[]='Falta '.$rel;continue;}
 $before=(string)file_get_contents($dst);$after=$before;
 foreach($items as [$a,$b])$after=gd_once($after,$a,$b,$rel,$errors);
 if($after===$before)$already++;else $changes[$rel]=$after;
}
// Requiere arqueos independientes y retiro anterior; jamás elimina credenciales de arqueo.
foreach(['app/helpers/gyd_retiros.php','public/pos_arqueos.php','public/pos_retiros_estado.php','public/erp_retiros_supervision.php','app/helpers/pos_corte_denominaciones.php'] as $rel)if(!is_file($base.'/'.$rel))$errors[]='Falta módulo previo: '.$rel;
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");exit(1);}
if($check){echo (count($changes)+count($newFiles)?'COMPATIBLE':'YA INSTALADO').'. Modificados: '.count($changes).'; nuevos: '.count($newFiles).'; iguales: '.$already.".\n--check no modifica BD ni archivos. --apply exige la base prueba.\n";exit(0);}
try {
 require_once $base.'/app/config/database.php';$db=(new Database())->connect();$dbname=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($dbname!=='prueba')throw new RuntimeException('Base conectada: '.$dbname.'. Solo se permite instalar en prueba.');
 foreach(['pos_turnos','pos_movimientos_caja','pos_pagos','pos_ventas','pos_cajas','pos_usuario_asignaciones','erp_retiros_supervisados','pos_auditoria','usuarios'] as $table){
  $s=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Falta tabla de datos: '.$table);
 }
 // No se escribe en estructuras ajenas a los retiros ni se alteran cantidades de caja.
 $db->exec((string)file_get_contents($payload.'/sql/gyd_retiros_boveda.sql'));
 $columns=$db->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gyd_retiros_boveda'")->fetchAll(PDO::FETCH_COLUMN);
 foreach(['id','turno_id','movimiento_id','operacion_clave','importe','esperado_antes','efectivo_despues','desglose_json','boveda_estado','importe_boveda'] as $col)if(!in_array($col,$columns,true))throw new RuntimeException('Esquema de bóveda incompatible, falta: '.$col);
 $toCreate=array_merge($changes,$newFiles);
 if(!$toCreate){echo "YA INSTALADO. No se modificaron archivos ni movimientos.\n";exit(0);}
 $backup=$base.'/backups_gyd_retiros_boveda_v3_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!@mkdir($backup,0775,true)&&!is_dir($backup))throw new RuntimeException('No se pudo crear respaldo.');
 foreach($changes as $rel=>$code){$f=$backup.'/'.$rel;@mkdir(dirname($f),0775,true);if(!copy($base.'/'.$rel,$f))throw new RuntimeException('Error al respaldar: '.$rel);}
 $written=[];
 try{
  foreach($toCreate as $rel=>$code){$f=$base.'/'.$rel;@mkdir(dirname($f),0775,true);if(file_put_contents($f,$code,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);$written[]=$rel;}
 }catch(Throwable $e){foreach(array_reverse($written) as $rel){if(isset($newFiles[$rel]))@unlink($base.'/'.$rel);else copy($backup.'/'.$rel,$base.'/'.$rel);}throw $e;}
 echo 'INSTALADO. Archivos nuevos: '.count($newFiles).'; modificados: '.count($changes).".\nRespaldo: $backup\nNo se alteraron ventas, precios ni existencias.\n";
}catch(Throwable $e){fwrite(STDERR,'INSTALACIÓN DETENIDA: '.$e->getMessage()."\n");exit(1);}
