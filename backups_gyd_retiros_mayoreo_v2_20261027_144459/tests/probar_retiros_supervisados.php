<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/gyd_retiros.php';
$tests=0;
function okr(bool $test,string $msg):void{global $tests;if(!$test)throw new RuntimeException('FALLO: '.$msg);$tests++;echo 'OK: '.$msg.PHP_EOL;}
okr(gr_sugerencia(299999)===0,'No solicita antes de llegar a $3,000');
okr(gr_sugerencia(300000)===200000,'Retira $2,000 de $3,000 y conserva $1,000');
okr(gr_sugerencia(350000)===250000,'Retira $2,500 de $3,500');
okr(gr_sugerencia(1000000)===900000,'El máximo conserva $1,000');
okr(gr_cents('1000.25')===100025,'Convertir centavos exactos');
okr(gr_pesos(12345)==='123.45','Formatear importes');
gr_validar_monto(350000,350000,250000);okr(true,'Permite retirar monto máximo sugerido');
gr_validar_monto(350000,350000,10000);okr(true,'Permite retiro parcial inferior al máximo');
foreach([['esperado'=>299999,'contado'=>299999,'retiro'=>150000],['esperado'=>300000,'contado'=>300000,'retiro'=>200001],['esperado'=>350000,'contado'=>300000,'retiro'=>250000],['esperado'=>350000,'contado'=>350000,'retiro'=>260000],['esperado'=>350000,'contado'=>350000,'retiro'=>0]] as $case){
 try{gr_validar_monto($case['esperado'],$case['contado'],$case['retiro']);throw new RuntimeException('Aceptó retiro incorrecto');}
 catch(DomainException $e){okr(true,'Rechaza retiro inseguro #'.($tests-7));}
}
echo $tests.' / '.$tests.' pruebas de reglas aprobadas (sin conexión a BD).'.PHP_EOL;
