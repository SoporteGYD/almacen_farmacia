<?php
declare(strict_types=1);
/** Prueba del predicado real de V4. No utiliza ni modifica la BD. */
if(PHP_SAPI!=='cli')exit('Solo terminal.');
require_once dirname(__DIR__).'/app/helpers/erp_alertas_precio_base_v4.php';
$branches=[0=>'Precio base',1=>'Tapachula',2=>'Toscana',3=>'Ciudad Hidalgo',4=>'Tuxtla'];
$all=[1=>[7=>120.16],2=>[7=>120.16],3=>[7=>120.16],4=>[7=>120.16]];
$cases=[
 ['4 sucursales configuradas, ignorar aviso base',true,$branches,$all,7],
 ['Un precio de sucursal faltante, NO ignorar base',false,$branches,[1=>[7=>120.16],2=>[7=>120.16],3=>[7=>120.16]],7],
 ['Precio cero, NO ignorar base',false,$branches,array_replace($all,[4=>[7=>0]]),7],
 ['Precio negativo, NO ignorar base',false,$branches,array_replace($all,[4=>[7=>-2]]),7],
 ['Producto inexistente, NO ignorar base',false,$branches,$all,8],
 ['Solo precio base, NO ignorar base',false,[0=>'Precio base'],[],7],
 ['Sin producto identificado, NO ignorar base',false,$branches,$all,0],
 ['Precio NaN o no numerico, NO ignorar base',false,$branches,array_replace($all,[2=>[7=>'no precio']]),7],
];
$fails=0;
foreach($cases as [$title,$expected,$branches,$prices,$pid]){
 $got=gdca_base_cubierta_por_sucursales($branches,$prices,$pid);
 echo ($got===$expected?'OK ':'ERROR ').$title."\n";
 if($got!==$expected)$fails++;
}
echo (count($cases)-$fails).'/'.count($cases).' pruebas correctas'."\n";
exit($fails?1:0);
