<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_conciliacion_diaria.php';
$ok=0;$failed=0;
function test(string $label,bool $cond):void{global $ok,$failed;if($cond){$ok++;echo "OK: $label\n";}else{$failed++;echo "FALLO: $label\n";}}
test('DECIMAL con centavos exactos',gdcd_cents('123.45')===12345);
test('Decimal sin centavos',gdcd_cents('100')===10000);
test('Decimal negativo',gdcd_cents('-12.34')===-1234);
try{gdcd_cents('1.999');test('No acepta tercer decimal',false);}catch(DomainException $e){test('No acepta tercer decimal',true);}
test('Formato de importe',gdcd_money(1234567)==='$12,345.67');
$mov=[['id'=>1,'tipo'=>'RETIRO','monto'=>'8000.00','motivo'=>'Retiro exprés','creado_en'=>'2026-10-27 12:00:00'],['id'=>2,'tipo'=>'RETIRO','monto'=>'100.50','motivo'=>'Devolución de PV-10 / partida 2','creado_en'=>'2026-10-27 12:01:00'],['id'=>3,'tipo'=>'RETIRO','monto'=>'500.00','motivo'=>'Retiro manual','creado_en'=>'2026-10-27 12:02:00'],['id'=>4,'tipo'=>'INGRESO','monto'=>'50.00','motivo'=>'Ingreso','creado_en'=>'2026-10-27 12:03:00']];
$r=gdcd_clasificar($mov,[['id'=>25,'movimiento_id'=>1,'importe'=>'8000.00']]);
test('Retiro con comprobante es bóveda', $r['totales']['boveda']===800000 && $r['lineas'][0]['folio']==='RET-00000025');
test('Reembolso no se mezcla con bóveda',$r['totales']['reembolsos']===10050);
test('Otros retiros separados',$r['totales']['otros_retiros']===50000);
test('Todos los retiros sumados una sola vez',$r['totales']['retiros_total']===860050);
test('Ingresos en su propia columna',$r['totales']['ingresos']===5000);
$base=gdcd_calcular(150000,['EFECTIVO'=>2000000,'TARJETA'=>100000,'TRANSFERENCIA'=>50000],['ingresos'=>0,'retiros_total'=>1800000,'reembolsos'=>0],350000,0);
test('Esperado 3500',$base['esperado']===350000);
test('Diferencia cero',$base['diferencia']===0 && $base['estado']==='CONCILIADO');
test('No suma tarjetas al cajón',$base['ventas_efectivo']===2000000);
$gap=gdcd_calcular(150000,['EFECTIVO'=>2000000],['ingresos'=>0,'retiros_total'=>1800000,'reembolsos'=>0],349000,0);
test('Faltante detectado',$gap['diferencia']===-1000 && $gap['estado']==='REVISAR');
$cancel=gdcd_calcular(100000,['EFECTIVO'=>0],['ingresos'=>0,'retiros_total'=>0,'reembolsos'=>0],null,null,[],1);
test('Cancelación exige revisar',$cancel['estado']==='REVISAR');
test('Protege CSV de formulas',gdcd_csv('=SUM(1,1)')==="'=SUM(1,1)");
test('Fecha de filtro validada',gdcd_day('2026-10-28')&&!gdcd_day('2026-13-28'));
$r2=gdcd_clasificar([['id'=>10,'tipo'=>'RETIRO','monto'=>'100.00','motivo'=>'Retiro exprés','creado_en'=>'2026-10-27 12:30:00']],[['id'=>1,'movimiento_id'=>10,'importe'=>'95.00']]);
test('Detecta diferencia ticket vs caja',count($r2['alertas'])===1);
$r3=gdcd_clasificar([], [['id'=>44,'movimiento_id'=>123,'importe'=>'45.00']]);
test('Detecta ticket sin movimiento',count($r3['alertas'])>=1);
$devo=gdcd_calcular(50000,['EFECTIVO'=>300000,'TARJETA'=>0,'TRANSFERENCIA'=>0],['ingresos'=>0,'retiros_total'=>30000,'reembolsos'=>30000],320000,null,[],0,30000);
test('Devolución se descuenta una sola vez', $devo['esperado']===320000);
$pro=gdcd_calcular(150000,['EFECTIVO'=>1800000,'TARJETA'=>0,'TRANSFERENCIA'=>0],['ingresos'=>0,'retiros_total'=>1800000,'reembolsos'=>0],150000,null);
test('Caso mayoreo dos retiros conserva fondo', $pro['esperado']===150000 && $pro['estado']==='CONCILIADO');
echo "RESULTADO: $ok OK / $failed errores (sin conexión a MariaDB).\n";exit($failed?1:0);
