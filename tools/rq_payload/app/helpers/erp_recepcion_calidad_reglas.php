<?php
declare(strict_types=1);
/** Funciones puras, sin cambios de inventario, para verificar entregas por partidas. */
function rq_saldo_buenas(int $solicitadas,int $buenasAcumuladas):int {
 if($solicitadas<0 || $buenasAcumuladas<0 || $buenasAcumuladas>$solicitadas)throw new RuntimeException('Saldo de OC inconsistente; requiere revisión.');
 return $solicitadas-$buenasAcumuladas;
}
function rq_validar_partida(int $restante,array $cantidades,string $codigo):void {
 if($restante<0)throw new RuntimeException('Saldo anterior de OC inconsistente.');
 foreach(['buena','danada','faltante','caducada','equivocada','sobrante'] as $campo){
  if(!isset($cantidades[$campo]) || !is_int($cantidades[$campo]) || $cantidades[$campo]<0 || $cantidades[$campo]>999999999)throw new RuntimeException('Cantidad incorrecta de '.$campo.' en '.$codigo);
 }
 if($cantidades['buena']+$cantidades['danada']+$cantidades['faltante']+$cantidades['caducada']+$cantidades['equivocada']>$restante){
  throw new RuntimeException('El desglose de '.$codigo.' supera las '.$restante.' piezas pendientes. Los sobrantes se registran aparte.');
 }
}
