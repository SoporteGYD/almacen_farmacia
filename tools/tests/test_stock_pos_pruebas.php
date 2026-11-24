<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/gyd_stock_pruebas_reglas.php';
function eq($actual,$esperado,$mensaje): void {if($actual!==$esperado)throw new RuntimeException($mensaje.' esperado: '.json_encode($esperado).' recibido '.json_encode($actual));}
eq(gyd_stock_prueba_objetivo(1,5893,415,21600)['general'],22015,'Hidalgo - ejemplo Barmicil');
eq(gyd_stock_prueba_objetivo(2,null,null,0)['general'],48,'Tuxtla - sin almacén');
eq(gyd_stock_prueba_objetivo(2,null,null,30)['general'],78,'Tuxtla - con almacén');
eq(gyd_stock_prueba_objetivo(1,2995,null,0)['general'],2995,'Conservar General previo');
eq(gyd_stock_prueba_objetivo(1,2995,null,0)['farmacia'],2995,'Derivar farmacia de General anterior');
eq(gyd_stock_prueba_objetivo(1,0,null,0)['general'],0,'Conservar cero explícito');
eq(gyd_stock_prueba_objetivo(1,null,0,15)['general'],15,'Conservar conteo cero');
eq(gyd_stock_prueba_objetivo(3,4041,null,0)['general'],4041,'Pool existente');
eq(gyd_stock_prueba_objetivo(4,4041,null,0)['general'],4041,'Pool Toscana misma existencia');
eq(gyd_stock_prueba_costo(['costo_ultimo'=>'20.7100'],[35])['crear'],false,'Conserva costo');
eq(gyd_stock_prueba_costo(['costo_ultimo'=>0,'costo_promedio'=>0,'precio_compra'=>0],[35])['costo'],'22.7500','Costo DEMO');
try {gyd_stock_prueba_objetivo(1,0,0,-1); throw new RuntimeException('No rechazó stock negativo');} catch(DomainException $e){}
echo "OK: reglas verificadas\n";
