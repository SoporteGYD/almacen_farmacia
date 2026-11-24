<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/erp_recepcion_calidad_reglas.php';
function t(bool $ok,string $s):void {if(!$ok){fwrite(STDERR,"FALLO: $s\n");exit(1);}echo "OK $s\n";}
$pedido=100;
$primera=['buena'=>70,'danada'=>5,'faltante'=>25,'caducada'=>0,'equivocada'=>0,'sobrante'=>3];
rq_validar_partida(rq_saldo_buenas(100,0),$primera,'DEMO');
t(rq_saldo_buenas($pedido,70)===30,'70 buenas +5 dañadas +25 faltantes = saldo 30 buenas; 3 sobrantes fuera del conteo');
$segunda=['buena'=>20,'danada'=>0,'faltante'=>0,'caducada'=>0,'equivocada'=>0,'sobrante'=>0];
rq_validar_partida(30,$segunda,'DEMO');t(rq_saldo_buenas($pedido,90)===10,'Complemento posterior 20 buenas, quedan 10');
$tercera=['buena'=>10,'danada'=>0,'faltante'=>0,'caducada'=>0,'equivocada'=>0,'sobrante'=>0];
rq_validar_partida(10,$tercera,'DEMO');t(rq_saldo_buenas($pedido,100)===0,'Última entrega completa el pedido sin falsear inventario');
$soloInc=['buena'=>0,'danada'=>4,'faltante'=>6,'caducada'=>0,'equivocada'=>0,'sobrante'=>1];
rq_validar_partida(10,$soloInc,'DEMO');t(rq_saldo_buenas(10,0)===10,'Recibo sin buenas no disminuye el pendiente');
$invalido=['buena'=>8,'danada'=>5,'faltante'=>0,'caducada'=>0,'equivocada'=>0,'sobrante'=>0];
try{rq_validar_partida(10,$invalido,'DEMO');t(false,'Exceso debe bloquearse');}catch(RuntimeException $e){t(true,'Bloqueo de cantidades que exceden lo pendiente');}
$invalido=['buena'=>-1,'danada'=>0,'faltante'=>0,'caducada'=>0,'equivocada'=>0,'sobrante'=>0];
try{rq_validar_partida(10,$invalido,'DEMO');t(false,'Cantidad negativa debe bloquearse');}catch(RuntimeException $e){t(true,'Bloqueo de valores negativos');}
