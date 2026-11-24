<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_oc_autollenado_verificacion.php';
$rec=[1=>['cantidad'=>10,'lotes_distintos'=>1,'lote'=>'L1','caducidad'=>'2027-12-31'],2=>['cantidad'=>8,'lotes_distintos'=>2,'lote'=>'X','caducidad'=>'2027-03-31']];
$fact=[['id'=>20,'producto_id'=>1,'cantidad_facturada'=>7,'aceptadas'=>0,'incidencias_pendientes'=>0],['id'=>21,'producto_id'=>2,'cantidad_facturada'=>10,'aceptadas'=>1,'incidencias_pendientes'=>0]];
$r=gyd_oc_disponibles_para_factura($fact,$rec,[1=>5,2=>3]);
assert($r['partidas'][20]['buenas']===5);assert($r['partidas'][20]['lote']==='L1');
assert(!isset($r['partidas'][21]));assert($r['multi_lote']===1);
assert($r['piezas']===5);assert($r['multi_lote']===1);
$rec[1]['caducidad']='2020-01-01';
$r=gyd_oc_disponibles_para_factura($fact,$rec,[1=>5,2=>3]);
assert($r['piezas']===0);assert($r['por_revisar']===2);
$rec[1]['caducidad']='2027-12-31';
// Con el inventario documental ya aceptado no habrá una segunda sugerencia.
$fact[0]['aceptadas']=7;
$r=gyd_oc_disponibles_para_factura($fact,$rec,[1=>10,2=>8]);
assert(empty($r['partidas']));
// Sin recepciones sólo se muestran ceros; jamás se inventa una aceptación.
$r=gyd_oc_disponibles_para_factura($fact,[],[]);assert($r['piezas']===0);
echo "PRUEBAS OK\n";
