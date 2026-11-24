<?php
declare(strict_types=1);
/** Installer G&D. --check NO escribe. --apply SOLO BD prueba. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Utiliza PHP CLI.');}
$base=dirname(__DIR__);$payload=__DIR__.'/retiros_payload';$apply=in_array('--apply',$argv,true);$check=in_array('--check',$argv,true);
if($apply===$check){fwrite(STDERR,"Uso: php tools/instalar_retiros_arqueos_caja.php --check | --apply\n");exit(2);}
$files=[
 'app/helpers/gyd_retiros.php',
 'public/pos_retiros.php',
 'public/pos_retiros_estado.php',
 'public/erp_retiros_supervision.php',
 'public/erp_retiros_diarios.php',
 'public/assets/js/pos_retiros_aviso.js',
 'tests/probar_retiros_supervisados.php',
];
$patches=[
 'public/pos.php'=>[
  '<div class="headrow">' => '<div style="padding:9px 17px;background:#eff6ff;border:1px solid #c7d8ee;border-radius:8px;margin:10px 0"><a href="pos_retiros.php" style="font-weight:750;color:#155791">💵 Arqueo / Solicitar retiro de efectivo</a><span style="margin-left:8px;color:#486580;font-size:12px">Aviso desde $3,000; conservar $1,000</span></div>' . "\n" . '<div class="headrow">',
  '</body>' => '<script defer src="assets/js/pos_retiros_aviso.js?v=20261027"></script>' . "\n" . '</body>',
 ],
 'public/erp_control_cajas.php'=>[
  '<a href="pos.php">Punto de Venta</a>' => '<a href="pos.php">Punto de Venta</a><a href="erp_retiros_supervision.php">Confirmar retiros</a><a href="erp_retiros_diarios.php">Retiros del día</a>',
  "if(\$tipo==='RETIRO' && \$centavos>\$tot['esperado'])throw new DomainException('El retiro excede el efectivo esperado. Revisa la caja antes de continuar.');" => "if(\$tipo==='RETIRO' && \$centavos>\$tot['esperado'])throw new DomainException('El retiro excede el efectivo esperado. Revisa la caja antes de continuar.');\n                if(\$tipo==='RETIRO' && \$tot['esperado']<300000)throw new DomainException('Los retiros solo proceden al alcanzar $3,000 en caja.');\n                if(\$tipo==='RETIRO' && \$centavos>\$tot['esperado']-100000)throw new DomainException('Un retiro debe dejar al menos $1,000 en la caja.');",
 ],
 'app/models/PuntoVenta.php'=>[
  "if(!\$turno)throw new DomainException('No hay turno abierto.');"=>"if(!\$turno)throw new DomainException('No hay turno abierto.');\n            if((int)\$this->q(\"SELECT COUNT(*) FROM erp_retiros_supervisados WHERE turno_id=? AND estado='SOLICITADO'\",[(int)\$turno['id']])->fetchColumn()>0)throw new DomainException('Existe un retiro pendiente de confirmación; solicite a supervisión aprobarlo o rechazarlo antes de cerrar turno.');",
 ],
];
$errors=[];$changes=[];$newFiles=[];
foreach($files as $p){
 $src=$payload.'/'.$p;$dest=$base.'/'.$p;
 if(!is_file($src)){$errors[]="No existe el payload $p";continue;}
 if(!is_file($dest)){$newFiles[$p]=file_get_contents($src);continue;}
 if(hash_file('sha256',$dest)!==hash_file('sha256',$src)){$errors[]="Existe un archivo diferente y no se sobrescribirá: $p";}
}
foreach($patches as $p=>$rules){
 $dst=$base.'/'.$p;
 if(!is_file($dst)){$errors[]="Archivo no encontrado: $p";continue;}
 $content=file_get_contents($dst);$updated=$content;
 foreach($rules as $anchor=>$replaced){
  if(str_contains($updated,$replaced))continue;
  if(substr_count($updated,$anchor)!==1){$errors[]="INCOMPATIBLE: el marcador de $p no coincide en una única posición.";continue;}
  $updated=str_replace($anchor,$replaced,$updated);
 }
 if($updated!==$content)$changes[$p]=$updated;
}
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"No se han realizado cambios.\n");exit(1);}
if($check){
 echo "COMPATIBLE EN ARCHIVOS. Nuevos: ".count($newFiles)."; a actualizar: ".count($changes).".\n";
 echo "--check no conecta ni modifica la BD. --apply exige la base prueba y comprobará sus tablas POS.\n";
 exit(0);
}
require_once $base.'/app/config/database.php';
try{
 $db=(new Database())->connect();
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new RuntimeException('No está conectado a la base prueba. No se instaló nada.');
 foreach(['pos_turnos','pos_movimientos_caja','pos_pagos','pos_ventas','pos_cajas','pos_usuario_asignaciones'] as $name){
  $exists=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$exists->execute([$name]);
  if(!$exists->fetchColumn())throw new RuntimeException('Falta tabla POS requerida: '.$name);
 }
 $sql=file_get_contents($payload.'/sql/erp_retiros_supervisados.sql');
 $db->exec($sql);
 $backup=$base.'/backups_gyd_retiros_'.date('Ymd_His');
 if($changes && !is_dir($backup) && !mkdir($backup,0775,true) && !is_dir($backup))throw new RuntimeException('No fue posible crear respaldos.');
 foreach($changes as $p=>$content){
  $path=$backup.'/'.$p;@mkdir(dirname($path),0775,true);
  if(!copy($base.'/'.$p,$path))throw new RuntimeException('Falló respaldo de '.$p);
 }
 foreach($newFiles as $p=>$content){$dest=$base.'/'.$p;@mkdir(dirname($dest),0775,true);if(file_put_contents($dest,$content,LOCK_EX)===false)throw new RuntimeException('No pudo crear '.$p);}
 foreach($changes as $p=>$content){if(file_put_contents($base.'/'.$p,$content,LOCK_EX)===false)throw new RuntimeException('No pudo actualizar '.$p);}
 echo "INSTALACIÓN COMPLETADA. Creados: ".count($newFiles).", modificados: ".count($changes).".\n";
 echo "Respaldo: $backup\n";
 echo "POS: public/pos_retiros.php\nSupervisión: public/erp_retiros_supervision.php\nReporte diario: public/erp_retiros_diarios.php\n";
}catch(Throwable $e){fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\nRevisa respaldo si ya había comenzado la copia.\n");exit(1);}
