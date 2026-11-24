<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
$ok=0;$fail=0;
function prueba(bool $v,string $titulo):void{global $ok,$fail;if($v){$ok++;echo "OK: $titulo\n";}else{$fail++;fwrite(STDERR,"FALLO: $titulo\n");}}
function rechaza(callable $f):bool{try{$f();return false;}catch(DomainException){return true;}}
prueba(gd_cents('250000.75')===25000075,'Importes de mayoreo en centavos exactos');
prueba(gd_dollars(25000075)==='250000.75','Centavos reversibles a DECIMAL');
prueba(rechaza(fn()=>gd_cents('20.001')),'Se rechazan más de dos decimales');
prueba(rechaza(fn()=>gd_cents('1e5')),'Se rechaza notación científica');
prueba(gd_folio(27)==='RET-00000027','Folio único formateado');
prueba(gd_can_operate(['id'=>7,'rol'=>'CAJERO'],['usuario_apertura_id'=>7]),'Cajero del turno autorizado');
prueba(!gd_can_operate(['id'=>7,'rol'=>'CAJERO'],['usuario_apertura_id'=>8]),'Cajero ajeno bloqueado');
prueba(gd_can_operate(['id'=>8,'rol'=>'ADMINISTRADOR'],['usuario_apertura_id'=>7]),'Admin consulta turno');
prueba((function(){gd_validar_retiro(25000000,25000000,24500000,500000);return true;})(),'Retiro mayoreo conserva exactamente fondo');
prueba(rechaza(fn()=>gd_validar_retiro(25000000,25000000,24500001,500000)),'Bloqueo por un centavo que consume fondo');
prueba(rechaza(fn()=>gd_validar_retiro(25000000,24999900,100000,500000)),'No permite esconder faltante en retiro');
prueba(rechaza(fn()=>gd_validar_retiro(25000000,25000100,100000,500000)),'No permite esconder sobrante en retiro');
prueba(rechaza(fn()=>gd_validar_retiro(100000,100000,50000,0)),'Fondo cero exige regularización');
prueba(gd_sum_retiros([['importe'=>'2500.75'],['importe'=>'70000.25']])===7250100,'Suma exacta de varios retiros');
$s=['fondo'=>150000,'ventas'=>10000000,'ingresos'=>0,'retiros'=>5000000,'esperado'=>5150000];
$t=['efectivo_contado'=>'51500.00'];$r=[['importe'=>'10000.00'],['importe'=>'40000.00']];$c=gd_resumen_corte($t,$s,$r);
prueba($c['tickets']===5000000&&$c['otros']===0&&$c['diferencia']===0,'Tickets + contado cuadran con libro POS');
$s['retiros']=5001000;$s['esperado']=5149000;$c=gd_resumen_corte($t,$s,$r);
prueba($c['otros']===1000&&$c['diferencia']===1000,'Movimiento histórico no desaparece ni se descuenta dos veces');
prueba(rechaza(fn()=>gd_resumen_corte($t,['retiros'=>100,'esperado'=>0],$r)),'Detecta tickets superiores al total de movimientos');
prueba(gd_signed(-125)==='-$1.25','Diferencia negativa no se oculta');
prueba(PosCorteDenominaciones::calcular(['b500'=>2,'m050'=>3])['total_centavos']===100150,'Desglose de efectivo suma en centavos');
prueba(rechaza(fn()=>PosCorteDenominaciones::calcular(['b500'=>-1])),'Rechaza cantidad negativa por denominación');
prueba(rechaza(fn()=>PosCorteDenominaciones::calcular(['m999'=>1])),'Rechaza denominaciones inventadas');
prueba(PosCorteDenominaciones::calcular([])['total_centavos']===0,'Desglose vacío no suma dinero');
echo "RESULTADO: $ok aprobadas, $fail fallidas (sin conexión a BD).\n";exit($fail?1:0);
