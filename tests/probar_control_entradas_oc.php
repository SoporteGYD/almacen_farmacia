<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_oc_stock_guard.php';
$tests=0;
function expect(bool $ok,string $text):void{global $tests;$tests++;if(!$ok){fwrite(STDERR,"FALLO: $text\n");exit(1);}echo "OK: $text\n";}
expect(gyd_oc_stock_cupo(100,0,0,70)===30,'100 buenas: aplica 70, deja 30');
expect(gyd_oc_stock_cupo(100,70,0,20)===10,'Complemento de 20 deja 10');
expect(gyd_oc_stock_cupo(100,90,0,10)===0,'Últimas 10 completan');
expect(gyd_oc_stock_cupo(100,0,70,30)===0,'Aplicación por factura + almacén sin exceder');
expect(gyd_oc_stock_cupo(100,25,65,10)===0,'Suma de ambas rutas igual a buenas');
foreach([[100,70,0,70],[100,60,40,1],[50,51,0,1],[100,0,101,1],[100,0,0,-1]] as $v){
 try{gyd_oc_stock_cupo(...$v);expect(false,'Rechazo de exceso '.json_encode($v));}
 catch(RuntimeException $e){expect(true,'Rechazo de exceso '.json_encode($v));}
}
try{gyd_oc_stock_validar_destino('HIDALGO_FARMACIA',1);expect(false,'Destino Farmacia/Almacén');}catch(RuntimeException $e){expect(true,'Destino Farmacia/Almacén bloqueado');}
gyd_oc_stock_validar_destino('HIDALGO_BODEGA',1);expect(true,'Destino Hidalgo Bodega');
gyd_oc_stock_validar_destino('TUXTLA_BODEGA',3);expect(true,'Destino Tuxtla Bodega');
echo "APROBADO: $tests/$tests pruebas. No se consultó ni modificó MariaDB.\n";
