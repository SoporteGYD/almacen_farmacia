<?php
declare(strict_types=1);
// Solo CLI. No acepta modificaciones fuera de la base prueba.
if (PHP_SAPI !== 'cli') {http_response_code(403);exit("Solo CLI\n");}
require_once __DIR__.'/../app/config/database.php';
try {$db=(new Database())->connect();} catch(Throwable $e){fwrite(STDERR,"ERROR CONEXION: ".$e->getMessage()."\n");exit(1);}
$dbname=(string)$db->query('SELECT DATABASE()')->fetchColumn();
if($dbname!=='prueba'){fwrite(STDERR,"BLOQUEADO: conectado a '$dbname', esperado 'prueba'. No se modifico nada.\n");exit(2);}
$checks=['pos_sucursales'=>['id','nombre','activo','ticket_sin_importes'],'pos_cajas'=>['id','sucursal_id','activa'],'pos_turnos'=>['id','caja_id','estado'],'pos_ventas'=>['id','folio','sucursal_id','caja_id','turno_id','usuario_id','efectivo_recibido','cambio','quien_lleva'],'pos_venta_detalle'=>['id','venta_id','producto_id'],'pos_pagos'=>['id','venta_id','metodo'],'pos_precios'=>['id','producto_id','sucursal_id','precio_publico'],'pos_precios_mayoreo'=>['id','producto_id','sucursal_id','cantidad_minima','precio_mayoreo'],'pos_existencias_farmacia'=>['id','producto_id','sucursal_id','existencia'],'pos_auditoria'=>['id','usuario_id'],'usuarios'=>['id','usuario','rol'],'productos'=>['id','codigo','descripcion']];
$mode=in_array('--aplicar',$argv,true)?'APLICACION':'DIAGNOSTICO';
echo "POS G&D - $mode - base $dbname\n";
$issues=0;
foreach($checks as $table=>$cols){
 $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$dbname,$table]);$exists=$q->fetchAll(PDO::FETCH_COLUMN);
 if(!$exists){echo "[ERROR] Falta tabla $table. No se creara automaticamente.\n";$issues++;continue;}
 foreach($cols as $col){if(in_array($col,$exists,true))continue;
  if($table==='pos_sucursales'&&$col==='ticket_sin_importes'){
   if($mode==='APLICACION'){
    try {$db->exec('ALTER TABLE `pos_sucursales` ADD COLUMN `ticket_sin_importes` TINYINT(1) NOT NULL DEFAULT 0');echo "[REPARADO] pos_sucursales.ticket_sin_importes\n";}
    catch(Throwable $e){echo "[ERROR] ALTER: ".$e->getMessage()."\n";$issues++;}
   } else {echo "[REPARABLE] $table.$col\n";$issues++;}
  }else{echo "[ERROR] Falta columna $table.$col (no se modifica automaticamente)\n";$issues++;}
 }
}
if($mode==='APLICACION'){
 try{$db->exec("UPDATE `pos_sucursales` SET `ticket_sin_importes`=1 WHERE UPPER(`nombre`) LIKE '%CIUDAD HIDALGO%'");echo "[OK] Ciudad Hidalgo: nota sin importes.\n";}catch(Throwable $e){echo "[ERROR] Bandera de sucursal: ".$e->getMessage()."\n";$issues++;}
}
if(!$issues)echo "[OK] No se detectan faltantes en las columnas revisadas.\n";
else echo "Pendientes: $issues. NO IMPORTES una base de datos completa encima.\n";
exit($issues?1:0);
