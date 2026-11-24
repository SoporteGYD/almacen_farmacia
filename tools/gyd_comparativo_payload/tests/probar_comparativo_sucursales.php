<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_comparativo_sucursales.php';
function ok(bool $x,string $msg):void{if(!$x){fwrite(STDERR,'FALLÓ '.$msg.PHP_EOL);exit(1);}echo 'OK: '.$msg.PHP_EOL;}
ok(gcs_date('2026-10-29'),'fecha válida');
ok(!gcs_date('2026-02-29'),'fecha inexistente');
ok(gcs_money_c('1500.00')===150000,'centavos positivos');
ok(gcs_money_c('-50.00')===-5000,'centavos negativos');
ok(gcs_money_c('2.3450')===235,'redondeo decimal de costos');
ok(gcs_money_c('2.3449')===234,'redondeo hacia abajo');
ok(str_starts_with(gcs_csv('=HYPERLINK("x")'),"'"),'prevención de fórmulas CSV');
ok(!gcs_role(['id'=>1,'rol'=>'CAJERO']),'cajero bloqueado');
ok(gcs_role(['id'=>1,'rol'=>'DIRECCION']),'dirección autorizada');
ok(gcs_role(['id'=>1,'rol_original'=>'ADMINISTRADOR']),'administración autorizada');
try{gcs_range('2026-01-01','2026-05-01');ok(false,'límite de fechas');}catch(DomainException $e){ok(true,'límite de fechas');}
echo "PRUEBAS UNITARIAS APROBADAS (no ejecutan MySQL).\n";
