<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/gyd_retiros.php';
$tests=0;
function okr(bool $check,string $label):void{global $tests;if(!$check)throw new RuntimeException('FALLO: '.$label);$tests++;echo 'OK: '.$label.PHP_EOL;}
function debe_fallar(int $esperado,int $contado,int $retirar,int $fondo,string $name):void{
    try{gr_validar_monto($esperado,$contado,$retirar,$fondo);}catch(DomainException $e){okr(true,$name);return;}throw new RuntimeException('No bloqueó: '.$name);
}
okr(gr_aviso(299999)===false,'Aviso es opcional bajo $3,000');
okr(gr_aviso(300000)===true,'Aviso por acumulación desde $3,000');
okr(gr_sugerencia(250000,50000)===200000,'Se puede retirar antes del umbral: deja fondo $500');
okr(gr_sugerencia(350000,50000)===300000,'Venta mayoreo: retira excedente de $3,500');
okr(gr_sugerencia(2000000,150000)===1850000,'Retiro grande $18,500 con fondo $1,500');
okr(gr_sugerencia(100000,150000)===0,'No consume fondo inicial');
okr(gr_sugerencia(350000,0)===0,'Sin fondo inicial válido no permite vaciar la caja');
okr(gr_cents('1234.56')===123456,'Importes conservan centavos');
okr(gr_pesos(12345)==='123.45','Conversion a pesos');
gr_validar_monto(280000,280000,200000,50000);okr(true,'Retiro parcial sin corte');
gr_validar_monto(350000,350000,300000,50000);okr(true,'Retiro deja fondo exacto');
// Después de confirmar un retiro, el mismo turno puede recibir ventas y volver a retirar:
gr_validar_monto(400000,400000,300000,100000);
gr_validar_monto(1100000,1100000,1000000,100000);okr(true,'Dos retiros consecutivos en mismo turno, fondo intacto');
gr_validar_monto(10000000,10000000,9900000,100000);okr(true,'Cobros mayoristas altos sin tope arbitrario');
debe_fallar(400000,400000,310000,100000,'No permite quitar fondo esperado');
debe_fallar(400000,375000,300000,100000,'No permite quitar fondo físico');
debe_fallar(250000,250000,1,250000,'Si sólo queda fondo no retira');
debe_fallar(200000,200000,0,100000,'Monto nulo inválido');
debe_fallar(200000,-10,1000,100000,'Conteo negativo inválido');
echo $tests.' / '.$tests.' pruebas aprobadas (sin conexión a BD).'.PHP_EOL;
