<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/rfi_payload/app/helpers/erp_oc_integracion_facturas.php';
$h=[['id'=>4,'producto_id'=>7,'cantidad_facturada'=>100,'aceptadas'=>0,'incidencias_pendientes'=>0]];
$r=[7=>['cantidad'=>70,'lotes_distintos'=>1,'lote'=>'DEMO123','caducidad'=>'2030-09-30']];
function chk(bool $v,string $name):void {if(!$v)throw new RuntimeException('FALLO: '.$name);echo "OK $name\n";}
chk(gyd_rfi_sugerir($h,$r,[])['piezas']===70,'70 buenas sugeridas sin afectar faltantes');
chk(gyd_rfi_sugerir($h,$r,[7=>60])['piezas']===10,'descuenta aceptadas de cualquier factura');
chk(gyd_rfi_sugerir($h,$r,[7=>70])['piezas']===0,'no duplica cantidad ya aceptada');
$r[7]['lotes_distintos']=2;
chk(gyd_rfi_sugerir($h,$r,[])['piezas']===0,'diferentes lotes requieren revisión manual');
$r[7]['lotes_distintos']=1;$r[7]['caducidad']='2020-01-01';
chk(gyd_rfi_sugerir($h,$r,[])['piezas']===0,'caducados excluidos del autollenado');
$r[7]['caducidad']='2030-09-30';
$h[0]['incidencias_pendientes']=95;
chk(gyd_rfi_sugerir($h,$r,[])['piezas']===5,'respeta saldo de factura');
chk(gyd_rfi_neto(100,20,10)===90,'aceptadas netas con ajustes auditados');
try{gyd_rfi_neto(10,20,0);throw new RuntimeException('No falló neto inválido');}catch(RuntimeException $ex){chk(str_contains($ex->getMessage(),'inconsistente'),'rechaza ajustes inconsistentes');}
