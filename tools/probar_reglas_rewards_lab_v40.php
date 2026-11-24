<?php
declare(strict_types=1);
/** Prueba pura de cálculos. No conecta ni escribe a ninguna BD. */
if(PHP_SAPI!=='cli')exit('Sólo CLI');
require_once __DIR__.'/../app/helpers/gyd_rewards_lab_v40.php';
$cases=[
  ['Venta rentable y premio válido',15000,10000,10,1250,1500,true,1250,7],
  ['Margen insuficiente',15000,13500,10,1688,2000,false,null,0],
  ['Sin stock virtual',15000,10000,0,1250,2000,false,1250,7],
  ['Puntos debajo del mínimo',15000,10000,5,1249,2000,false,1250,7],
  ['Cliente con saldo bajo',15000,10000,10,1250,750,false,1250,7],
];
foreach($cases as [$nombre,$pr,$co,$stock,$puntos,$saldo,$apto,$min,$puntosVenta]){
  $r=GydRewardsLabV40::calculo($pr,$co,$stock,$puntos,$saldo);
  if($r['simulacion_posible']!==$apto || $r['minimo_puntos']!==$min || $r['puntos_por_venta_normal']!==$puntosVenta){
    fwrite(STDERR,'FALLO: '.$nombre.' '.json_encode($r)."\n");exit(1);
  }
  echo 'OK: '.$nombre.PHP_EOL;
}
echo "Reglas V40 verificadas. Sin operaciones sobre bases de datos.\n";
