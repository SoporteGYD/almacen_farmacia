<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/payload/gyd_conciliacion_tt.php';
$cases=0;
function test(bool $ok,string $name):void {global $cases;$cases++;if(!$ok)throw new RuntimeException('Falló: '.$name);}
test(gct_admin(['rol'=>'ADMINISTRADOR']),'administrador autorizado');
test(gct_admin(['rol'=>'ADMINISTRADOR','rol_original'=>'DIRECCION']),'dueño autorizado');
test(!gct_admin(['rol'=>'CAJERO']),'cajero restringido');
test(!gct_admin(['rol'=>'GERENTE']),'gerente restringido');
test(!gct_admin(['rol'=>'COMPRAS']),'compras restringido');
test(gct_sid(3)&&gct_sid(4)&&!gct_sid(1)&&!gct_sid(2),'sucursales separadas');
test(gct_sname(3)==='Tapachula'&&gct_sname(4)==='Plaza Toscana','nombres independientes');
test(gct_count('0')===0,'conteo físico en cero');
test(gct_count('123')===123,'conteo entero');
try{gct_count('-1');throw new RuntimeException('No rechazó negativo');}catch(DomainException $e){$cases++;}
try{gct_count('1.5');throw new RuntimeException('No rechazó decimal');}catch(DomainException $e){$cases++;}
test(gct_h('<script>')==='&lt;script&gt;','escapado HTML');
test(gct_fresh(['creado_en'=>date('Y-m-d H:i:s')]),'conteo reciente');
test(!gct_fresh(['creado_en'=>'2020-01-01 00:00:00']),'conteo vencido');
echo "OK {$cases} comprobaciones de validación sin conexión SQL.\n";
