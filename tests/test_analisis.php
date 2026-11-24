<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_analisis_compras.php';
function assertOk(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FALLO: $m\n");exit(1);}echo "OK: $m\n";}
$f=ac_filtros(['fuente'=>'FACTURAS','q'=>'PROXALIN','sucursal'=>'TUXTLA','umbral'=>'6']);
[$sql,$params]=ac_sql($f);
assertOk(str_contains($sql,'erp_facturas_detalle'),'Fuente facturas correctamente configurada');
assertOk(str_contains($sql,'p.codigo_barras'),'Búsqueda incluye código de barras');
assertOk(!str_contains($sql,'UPDATE'),'Reporte solo lectura');
assertOk($params['sucursal']==='TUXTLA','Filtro de sucursal facturas');
[$sql2,$p2]=ac_sql(ac_filtros(['fuente'=>'ORDENES','sucursal'=>'HIDALGO']));
assertOk(str_contains($sql2,"o.estado='EMITIDA'")&&$p2['sucursal']==='HIDALGO%','Solo OC emitidas; dest. Hidalgo');
$records=[
 ['documento_id'=>5,'folio'=>'FAC5','referencia'=>'F5','fecha'=>'2026-10-18','sucursal'=>'TUXTLA','estado'=>'VERIFICADA','proveedor_id'=>1,'proveedor'=>'Prov A','producto_id'=>5,'codigo'=>'777','descripcion'=>'Producto de prueba','cantidad'=>10,'costo'=>35],
 ['documento_id'=>2,'folio'=>'FAC2','referencia'=>'F2','fecha'=>'2026-10-01','sucursal'=>'TUXTLA','estado'=>'VERIFICADA','proveedor_id'=>1,'proveedor'=>'Prov A','producto_id'=>5,'codigo'=>'777','descripcion'=>'Producto de prueba','cantidad'=>20,'costo'=>30],
 ['documento_id'=>8,'folio'=>'FAC8','referencia'=>'F8','fecha'=>'2026-10-17','sucursal'=>'TUXTLA','estado'=>'REGISTRADA','proveedor_id'=>2,'proveedor'=>'Prov B','producto_id'=>5,'codigo'=>'777','descripcion'=>'Producto de prueba','cantidad'=>15,'costo'=>32],
 ['documento_id'=>3,'folio'=>'FAC3','referencia'=>'F3','fecha'=>'2026-10-17','sucursal'=>'TUXTLA','estado'=>'REGISTRADA','proveedor_id'=>2,'proveedor'=>'Prov B','producto_id'=>6,'codigo'=>'888','descripcion'=>'Producto solo B','cantidad'=>1,'costo'=>19],
];
$r=ac_resumir($records,5.0);
assertOk($r['kpi']['documentos']===4&&$r['kpi']['proveedores']===2&&$r['kpi']['productos']===2,'Documentos y productos sin duplicados');
assertOk($r['kpi']['comparables']===1,'Distingue productos comparables');
assertOk(abs($r['productos'][0]['menor']-32)<.0001 && abs($r['productos'][0]['mayor']-35)<.0001,'Mínimos de últimos costos por proveedor');
assertOk(abs($r['pares']['5:1']['promedio']-31.6666667)<.0002,'Costo promedio ponderado por piezas');
assertOk(count($r['alertas'])===1&&abs($r['alertas'][0]['variacion']-16.6666667)<.0002,'Detecta aumentos de un mismo proveedor');
assertOk(abs($r['kpi']['importe']-(350+600+480+19))<.0001,'Importe bruto independiente del inventario');
assertOk(str_starts_with(ac_csv(' =2+2'),"'"),'CSV con protección contra fórmulas');
try{ac_filtros(['desde'=>'2026-11-01','hasta'=>'2026-10-01']);assertOk(false,'Validación fechas');}catch(DomainException $e){assertOk(true,'Validación de rango de fechas');}
try{ac_resumir(array_fill(0,12001,$records[0]));assertOk(false,'Límite registros');}catch(DomainException $e){assertOk(true,'Límite evita truncamiento silencioso');}
echo "PRUEBAS PASADAS\n";
