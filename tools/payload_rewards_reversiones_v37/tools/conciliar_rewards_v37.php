<?php
declare(strict_types=1);
/** G&D V37: conciliación independiente de puntos, sólo laboratorio. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--report';
if(!in_array($modo,['--report','--sync'],true)){
    fwrite(STDERR,"Uso: php tools/conciliar_rewards_v37.php --report|--sync [--venta=N]\n");exit(2);
}
$venta=null;
foreach(array_slice($argv,2) as $arg){
    if(preg_match('/^--venta=([1-9][0-9]*)$/',$arg,$m))$venta=(int)$m[1];
    else{fwrite(STDERR,"Argumento inválido: $arg\n");exit(2);}
}
$root=realpath(__DIR__.'/..');
require_once $root.'/app/config/database.php';
require_once $root.'/app/helpers/gyd_rewards_reversiones_v37.php';
try {
    $db=(new Database())->connect();
    GydRewardsReversionesV37::verificarEsquema($db);
    $ventas=GydRewardsReversionesV37::ventasCanceladas($db,$venta);
    echo "G&D V37 · laboratorio prueba:3307 | modo $modo\n";
    echo 'Ventas con cancelación total detectadas: '.count($ventas)."\n";
    if($modo==='--sync'){
        $res=[];
        foreach($ventas as $v){
            $st=GydRewardsReversionesV37::registrarCancelacion($db,$v);
            $res[$st]=($res[$st]??0)+1;
        }
        $n=GydRewardsReversionesV37::registrarPendientes($db,$venta);
        foreach($res as $st=>$cnt)echo "$st: $cnt\n";
        echo "Devoluciones no totales incorporadas a revisión: $n\n";
    }
    if($venta!==null){
        $pend=GydRewardsReversionesV37::hayParcialesVenta($db,$venta);
        echo 'Venta '.$venta.': '.($pend?'DEVOLUCIÓN PARCIAL EN REVISIÓN':'sin devolución parcial pendiente')."\n";
    }
    $pendientes=GydRewardsReversionesV37::pendientes($db,20);
    echo "Pendientes registrados (máximo 20 visibles): ".count($pendientes)."\n";
    foreach($pendientes as $p){
        echo 'Reversión #'.$p['reversion_id'].' · Venta #'.$p['venta_id'].' · '. $p['tipo_reversion']." [SIN AJUSTE AUTOMÁTICO]\n";
    }
    if($modo==='--report')echo "SOLO LECTURA. Ejecuta --sync para asientos de cancelación total y registro de pendientes.\n";
    echo "Canje continúa DESHABILITADO.\n";
} catch(Throwable $e){fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");exit(1);}
