<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/erp_comparador_proveedores.php';
require_once dirname(__DIR__).'/app/helpers/erp_alertas_compras.php';
function ck(bool $v,string $msg):void{if(!$v)throw new RuntimeException('FALLÓ '.$msg);}
function provider(int $id,float $now,?float $prev,int $days,string $name):array{
 return ['id'=>$id,'proveedor'=>$name,'costo'=>$now,'anterior'=>$prev,'antiguo'=>false,'riesgo'=>false,'dias'=>$days,'fecha'=>'2026-10-'.str_pad((string)(23-$days),2,'0',STR_PAD_LEFT),'factura_id'=>$id,'factura'=>'F-'.$id];
}
function product(int $id,?float $price,array $provs):array{
 return ['producto_id'=>$id,'codigo'=>'000'.$id,'descripcion'=>'Producto '.$id,'precio'=>$price,'proveedores'=>$provs];
}
$f=gd_ac_filters([]);
$f['vigencia']=90;
$p1=product(1,100,[1=>provider(1,90,60,1,'Proveedor caro'),2=>provider(2,70,67,2,'Proveedor económico')]);
$p2=product(2,40,[1=>provider(1,60,58,2,'Proveedor A')]);
$p3=product(3,100,[1=>provider(1,90,85,2,'Proveedor A')]);
$p4=product(4,null,[1=>provider(1,50,null,2,'Proveedor A')]);
$all=gd_ac_build([1=>$p1,2=>$p2,3=>$p3,4=>$p4],$f);
$types=array_column($all,'tipo');
ck(in_array('SUBIDA',$types,true),'subida');
ck(in_array('PERDIDA',$types,true),'pérdida');
ck(in_array('MARGEN_BAJO',$types,true),'margen bajo');
ck(in_array('OPORTUNIDAD',$types,true),'posible ahorro');
ck(in_array('SIN_PRECIO',$types,true),'sin precio');
ck(count(gd_ac_type_filter($all,'PERDIDA'))===1,'filtro tipo');
ck(gd_ac_build([1=>product(1,100,[1=>array_merge(provider(1,130,70,1,'Viejo'),['antiguo'=>true])])],$f)===[],'ignora antigüedad');
ck(gd_ac_build([1=>product(1,100,[1=>array_merge(provider(1,130,70,-1,'Futura'),['riesgo'=>true])])],$f)===[],'ignora fecha futura');
ck(gd_ac_num('abc',20,1,100)===20.,'valor predeterminado');
ck(gd_ac_export_safe('=HYPERLINK(...)')==="'=HYPERLINK(...)" ,'protección CSV');
ck($all[0]['nivel']==='ALTA','prioridad alta primero');
echo "OK: 10 casos de lógica y protección aprobados.\n";
