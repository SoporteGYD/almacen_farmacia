<?php
declare(strict_types=1);
function minPrecio(float $costo,float $margen):float {
 $p=ceil(($costo/(1-$margen/100))*100-1e-8)/100;
 for($i=0;$i<4&&$p>0&& 100*($p-$costo)/$p<$margen-1e-7;$i++)$p+=.01;
 return round($p,2);
}
function ck(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FALLO: '.$name);echo 'OK '.$name."\n";}
ck(abs((115-100)/115*100-13.04347826)<1e-5,'15% MARK UP equivale a margen 13.04%');
ck(minPrecio(100,15)===117.65,'Precio minimo 100 al 15%');
ck(minPrecio(.99,15)===1.17,'Redondeo arriba a centavos');
ck(minPrecio(200,15)===235.30,'Redondeo seguro para 200');
ck(100*(minPrecio(200,15)-200)/minPrecio(200,15)>=15-1e-7,'No queda margen por debajo del limite');
ck(100*(minPrecio(.99,15)-.99)/minPrecio(.99,15)>=15,'Margen sobre venta supera objetivo');
ck(100*(130-100)/130>15,'Margen 130/100 correcto');
ck(100*(110-100)/110<15,'Margen bajo sigue pendiente');
echo "RESULTADO: 8/8\n";
