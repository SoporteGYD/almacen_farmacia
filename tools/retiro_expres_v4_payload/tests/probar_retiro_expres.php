<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
$ok=0;$fail=0;
function t(bool $result,string $name):void{global $ok,$fail; if($result){$ok++;echo "OK: $name\n";}else{$fail++;echo "ERROR: $name\n";}}
function must_fail(callable $fn):bool {try{$fn();return false;}catch(DomainException){return true;}}
t(gd_cents('15000.75')===1500075,'Monto con centavos exactos');
t(gd_dollars(1500075)==='15000.75','Importe a texto decimal');
t(must_fail(fn()=>gd_cents('15.123')),'No permite mas de dos decimales');
t(must_fail(fn()=>gd_cents('-4')),'No permite cantidades negativas');
t(must_fail(fn()=>gd_cents('1e3')),'No permite notacion cientifica');
t(gd_folio(7)==='RET-00000007','Folio normalizado');
t((function(){gd_validar_retiro_expres(350000,250000,50000);return true;})(),'Retiro de 2500 deja fondo 500');
t((function(){gd_validar_retiro_expres(950000,800000,50000);return true;})(),'Otro retiro dentro del turno');
t(must_fail(fn()=>gd_validar_retiro_expres(100000,100001,50000)),'No consume el fondo inicial');
t(must_fail(fn()=>gd_validar_retiro_expres(100000,0,50000)),'Importe cero bloqueado');
t(must_fail(fn()=>gd_validar_retiro_expres(100000,50000,0)),'Fondo inicial cero bloqueado');
t(gd_sum_retiros([['importe'=>'8000.00'],['importe'=>'2000.20']])===1000020,'Suma retiros con centavos');
$t=['efectivo_contado'=>'500.00'];$s=['retiros'=>1000020,'esperado'=>50000];$r=[['importe'=>'8000.00'],['importe'=>'2000.20']];
t(gd_resumen_corte($t,$s,$r)['diferencia']===0,'Corte sin diferencia');
t(gd_resumen_corte($t,$s,$r)['otros']===0,'No doble resta de retiros');
echo "RESULTADO: $ok OK / $fail errores (sin MariaDB)\n";exit($fail?1:0);
