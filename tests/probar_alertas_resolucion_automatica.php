<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/helpers/erp_compras_alertas_auto.php';
$tests=0;$errors=0;
function t(bool $condition,string $name):void{global $tests,$errors;$tests++;if(!$condition){$errors++;echo "FALLO: $name\n";}else echo "OK: $name\n";}
$base=['tipo'=>'MARGEN_BAJO','costo'=>100];
t(gdca_auto_valida($base,120,[])!==null,'margen de 16.67% resuelto');
t(gdca_auto_valida($base,110,[])===null,'margen de 9.09% sigue pendiente');
t(gdca_auto_valida($base,120,['MARGEN_BAJO'])===null,'no cierra si la detección sigue activa');
t(gdca_auto_valida($base,120,['PERDIDA'])===null,'no cierra si apareció pérdida');
t(gdca_auto_valida(['tipo'=>'SIN_PRECIO','costo'=>100],105,[])!==null,'precio positivo resuelve sin precio');
t(gdca_auto_valida(['tipo'=>'SIN_PRECIO','costo'=>100],null,[])===null,'sin precio permanece abierta');
t(gdca_auto_valida(['tipo'=>'PERDIDA','costo'=>100],100,[])!==null,'precio igual al costo deja de ser pérdida');
t(gdca_auto_valida(['tipo'=>'PERDIDA','costo'=>100],100,['MARGEN_BAJO'])!==null,'pérdida se resuelve pero margen bajo abre otro caso');
t(gdca_auto_valida(['tipo'=>'PERDIDA','costo'=>100],90,[])===null,'precio debajo del costo sigue abierto');
t(gdca_auto_valida(['tipo'=>'SUBIDA','costo'=>100],200,[])===null,'aumento histórico requiere decisión humana');
t(gdca_auto_valida(['tipo'=>'OPORTUNIDAD','costo'=>100],200,[])===null,'ahorro histórico requiere decisión humana');
t(gdca_auto_valida($base,0,[])===null,'precio cero no resuelve');
t(gdca_auto_valida(['tipo'=>'MARGEN_BAJO','costo'=>null],120,[])===null,'no asume costos faltantes');
echo "\nRESULTADO: ".($tests-$errors)."/$tests comprobaciones correctas.\n";exit($errors?1:0);
