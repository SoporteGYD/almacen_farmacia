<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_rendimiento_cajeros.php';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FALLÓ: '.$msg);echo 'OK '.$msg.PHP_EOL;}
check(grd_centavos('193.45')===19345,'Conversión de importes');
check(grd_centavos('-9.50')===-950,'Importes negativos');
check(grd_tiempo(null)==='Sin medición','Sin tiempo no se inventa un promedio');
check(grd_tiempo(90)==='1:30','Formato del tiempo');
check(grd_fecha('2026-10-29')&&!grd_fecha('2026-02-30'),'Validación de fecha');
check(grd_permitido(['id'=>1,'rol_original'=>'DIRECCION']),'Acceso Dirección');
check(!grd_permitido(['id'=>2,'rol'=>'CAJERO']),'Cajero sin acceso');
check(str_starts_with(grd_csv_text('=SUM(A1:A4)'),"'"),'Protección CSV');
try{grd_periodo('2026-01-01','2026-12-31');throw new RuntimeException('No bloqueó periodo excesivo');}
catch(DomainException $e){echo "OK límite de fechas\n";}
echo "PRUEBAS COMPLETADAS (no requieren MySQL).\n";
