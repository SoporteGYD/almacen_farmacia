<?php
declare(strict_types=1);
/** Pruebas puras: no lee ni escribe la DB y no ejecuta canjes. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI solamente.');}
require_once __DIR__.'/../app/helpers/gyd_rewards_catalogo_v38.php';
require_once __DIR__.'/../app/helpers/gyd_rewards_lab_v40.php';
$tests=[
 ['Premio viable, costo $100, precio $150, 1,250 puntos',15000,10000,10,1250,1500,true,1250,250,9],
 ['Puntos insuficientes',15000,10000,10,1250,800,false,1250,800,10],
 ['Sin stock virtual',15000,10000,0,1250,1500,false,1250,1500,0],
 ['Margen bajo bloqueado',11000,10000,10,1250,1500,false,null,1500,10],
 ['Coste no cubierto por puntos',15000,10000,10,1000,1500,false,1250,1500,10],
];
$failed=[];
foreach($tests as [$name,$price,$cost,$stock,$points,$saldo,$possible,$minimum,$after,$afterStock]){
    $r=GydRewardsLabV40::calculo($price,$cost,$stock,$points,$saldo);
    if($r['simulacion_posible']!==$possible || $r['minimo_puntos']!==$minimum || $r['saldo_hipotetico']!==$after || $r['stock_hipotetico']!==$afterStock)$failed[]=$name;
}
if($failed){fwrite(STDERR,'FALLARON: '.implode('; ',$failed)."\n");exit(2);}
echo "OK: 5 pruebas de cálculo; sin conexión a DB ni cambios de inventario.\n";
