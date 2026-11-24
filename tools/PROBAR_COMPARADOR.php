<?php
require_once __DIR__.'/comparador_proveedores_files/app/helpers/erp_comparador_proveedores.php';
function assertEqual($a,$b,$label){if($a!==$b)throw new RuntimeException("FALLÓ: $label ".var_export($a,true).' != '.var_export($b,true));}
$rows=[];
foreach([
 ['p'=>1,'v'=>12,'c'=>70,'f'=>'2026-10-20','id'=>80],
 ['p'=>1,'v'=>12,'c'=>62,'f'=>'2026-09-20','id'=>70],
 ['p'=>1,'v'=>11,'c'=>65,'f'=>'2026-10-18','id'=>68],
 ['p'=>1,'v'=>11,'c'=>64,'f'=>'2026-08-18','id'=>51],
 ['p'=>2,'v'=>12,'c'=>40,'f'=>'2026-01-10','id'=>40],
 ['p'=>2,'v'=>11,'c'=>50,'f'=>'2026-10-20','id'=>81]
] as $d)$rows[]=['producto_id'=>$d['p'],'codigo'=>'000'.(string)$d['p'],'codigo_barras'=>'0'.(string)$d['p'],'descripcion'=>'Producto',
'costo_ultimo'=>60,'costo_promedio'=>59,'precio_comparacion'=>100,'proveedor_id'=>$d['v'],'proveedor'=>'Proveedor '.$d['v'],
'fecha'=>$d['f'],'numero_factura'=>'F'.$d['id'],'factura_id'=>$d['id'],'costo'=>$d['c'],'piezas'=>4];
$out=gd_cp_group($rows,90,new DateTimeImmutable('2026-10-23'));
assertEqual(count($out),2,'dos productos');
assertEqual($out[1]['mejor_proveedor_id'],11,'mejor proveedor vigente');
assertEqual($out[1]['hay_comparacion'],true,'comparable');
assertEqual($out[1]['diferencia_observada'],5.0,'diferencia en pesos');
assertEqual($out[1]['proveedores'][12]['anterior'],62.0,'costo previo del mismo proveedor');
assertEqual($out[2]['proveedores'][12]['antiguo'],true,'costo antiguo');
assertEqual($out[2]['hay_comparacion'],false,'costo antiguo excluido');
assertEqual(round(gd_cp_percent(70,62),2),12.90,'cambio de costo');
assertEqual(round(gd_cp_margin(70,100),2),30.0,'margen');
assertEqual(gd_cp_csv_text('=SUM(1)'),"'=SUM(1)",'CSV antifórmulas');
assertEqual(gd_cp_date('2026-10-23'),'2026-10-23','fecha');
try{gd_cp_date('2026-02-30');throw new RuntimeException('Fecha no validada');}catch(InvalidArgumentException $e){}
echo "OK: 10 pruebas de lógica financiera y seguridad.\n";
