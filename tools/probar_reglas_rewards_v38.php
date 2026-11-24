<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Sólo CLI');
require_once __DIR__.'/payload_catalogo_rewards_v38/app/helpers/gyd_rewards_catalogo_v38.php';
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FALLÓ: '.$name);echo "OK: $name\n";}
check(GydRewardsCatalogoV38::centavos('99.9999',true)===10000,'costo redondeado hacia arriba');
check(GydRewardsCatalogoV38::centavos('99.9999')===9999,'precio redondeado hacia abajo');
check(GydRewardsCatalogoV38::centavos('-1.00')===null,'importe negativo bloqueado');
$a=GydRewardsCatalogoV38::evaluarImportes(15000,10000);
check($a['apto']&&$a['minimo_puntos']===1250,'artículo costo $100 exige 1250 puntos y margen 33.33%');
check(!GydRewardsCatalogoV38::evaluarImportes(10000,8100)['apto'],'margen 19% no elegible');
check(!GydRewardsCatalogoV38::evaluarImportes(10000,null)['apto'],'sin costo no elegible');
check(!GydRewardsCatalogoV38::evaluarImportes(10000,5000,false)['apto'],'producto inactivo no elegible');
check(GydRewardsCatalogoV38::evaluarImportes(10000,8000)['apto'],'margen 20% exacto permitido');
echo "PRUEBAS PURO CÁLCULO V38 CORRECTAS. No se conectó a la base.\n";
