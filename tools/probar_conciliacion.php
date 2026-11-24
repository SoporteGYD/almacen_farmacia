<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
require_once __DIR__.'/../app/helpers/gyd_pos_conciliacion_detalle.php';
function gc_expect(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('FALLÓ: '.$msg);echo 'OK: '.$msg.PHP_EOL;}
try {
    $r=gcd_conciliar_montos(100000,550000,0,300000,348000);
    gc_expect($r['esperado']===350000,'Fondo + ventas efectivo - retiros = 3,500.00');
    gc_expect($r['diferencia']===-2000,'Faltante 20.00');
    $r=gcd_conciliar_montos(100000,550000,20000,300000,372000);
    gc_expect($r['esperado']===370000&&$r['diferencia']===2000,'Sobrante 20.00 incluyendo ingresos');
    $r=gcd_conciliar_montos(100000,0,0,0,null);
    gc_expect($r['diferencia']===null,'Turno abierto sin efectivo contado');
    $d=PosCorteDenominaciones::calcular(['b1000'=>2,'b500'=>1,'m050'=>3]);
    gc_expect($d['total_centavos']===250150,'Denominaciones suman exactamente 2,501.50');
    gc_expect(str_contains(gcd_ventas_vigentes(),"turno_reembolso_id=v.turno_id"),'Misma regla de cancelación/reembolso que el POS');
    echo "PRUEBAS COMPLETADAS. No se consultó ni modificó la base de datos.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
