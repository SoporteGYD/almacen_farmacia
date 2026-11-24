<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_cambio_precios.php';
function eq(string $a,string $b,string $test):void{if($a!==$b)throw new RuntimeException("$test: esperado $b, obtenido $a");echo "OK $test\n";}
eq(cp_precio_sugerido('35.0000','MARK_UP','15.71'),'40.50','markup sobre costo');
eq(cp_precio_sugerido('35.0000','MARK_DOWN','20'),'43.75','margen sobre precio venta');
eq(cp_precio_sugerido('100.0000','MARK_UP','0'),'100.00','sin margen');
eq(cp_decimal('40.50'),'40.50','precio manual');
try{cp_decimal('1,000.00');throw new RuntimeException('Debió rechazar comas');}catch(DomainException $e){echo "OK rechazar miles con coma\n";}
try{cp_precio_sugerido('35.00','MARK_DOWN','100');throw new RuntimeException('Debió rechazar 100%');}catch(DomainException $e){echo "OK margin 100% inválido\n";}
