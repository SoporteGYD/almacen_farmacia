<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/pos_corte_denominaciones.php';
function test_corte(bool $ok,string $texto):void{if(!$ok)throw new RuntimeException('FALLÓ: '.$texto);echo "OK: $texto\n";}
function test_falla(array $v):bool{try{PosCorteDenominaciones::calcular($v);return false;}catch(DomainException){return true;}}
$casos=0;
$z=PosCorteDenominaciones::calcular([]); test_corte($z['total_centavos']===0,'Conteo vacío equivale a cero');$casos++;
$v=PosCorteDenominaciones::calcular(['b500'=>'2','m10'=>'3','m050'=>'1']);test_corte($v['total_centavos']===103050,'Billetes y monedas suman 1,030.50');$casos++;
$v=PosCorteDenominaciones::calcular(['b20'=>1,'m20'=>1]);test_corte($v['total_centavos']===4000,'Billete y moneda de $20 independientes');$casos++;
$v=PosCorteDenominaciones::calcular(['m005'=>1,'m010'=>1,'m020'=>1,'m050'=>1]);test_corte($v['total_centavos']===85,'Centavos exactos, sin float');$casos++;
foreach ([['b100'=>-1],['b100'=>'1.5'],['b100'=>'abc'],['b100'=>100000],['extra'=>1],['b100'=>null],['b100'=>true],['b100'=>1.2],['b100'=>'1e3']] as $i=>$caso){test_corte(test_falla($caso),'Rechaza cantidad inválida '.($i+1));$casos++;}
test_corte(count($z['detalle'])===count(PosCorteDenominaciones::VALORES),'Todas las denominaciones presentes');$casos++;
echo "APROBADOS: $casos/$casos (sin modificar base de datos).\n";
