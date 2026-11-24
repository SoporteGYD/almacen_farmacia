<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_pruebas_integrales.php';
function expectGpi(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);}
expectGpi(gpi_role(['id'=>1,'rol'=>'ADMINISTRADOR']),'Debe permitir administrador');
expectGpi(gpi_role(['id'=>2,'rol_original'=>'DIRECCION','rol'=>'ADMINISTRADOR']),'Debe permitir Dirección');
expectGpi(!gpi_role(['id'=>3,'rol'=>'CAJERO']),'Debe rechazar cajero');
expectGpi(!gpi_role(['id'=>0,'rol'=>'ADMINISTRADOR']),'Debe rechazar sesión inexistente');
expectGpi(gpi_has(['prueba'=>['id'=>true,'usuario'=>true]],'prueba',['id']),'Detectar columna');
expectGpi(!gpi_has(['prueba'=>['id'=>true]],'prueba',['estado']),'Detectar columna ausente');
expectGpi(count(gpi_scenarios())===9,'Debe tener nueve escenarios');
expectGpi(gpi_cell('=A1')==="'=A1",'Evitar fórmula CSV');
expectGpi(str_contains(gpi_h('<script>'),'&lt;script&gt;'),'Escapar HTML');
$helper=(string)file_get_contents(__DIR__.'/../app/helpers/gyd_pruebas_integrales.php');
$section=explode('function gpi_scenarios()',explode('function gpi_run(',$helper,2)[1]??'')[0];
foreach(['INSERT INTO pos_','UPDATE pos_','DELETE FROM pos_','INSERT INTO erp_','UPDATE erp_','DELETE FROM erp_'] as $unsafe)expectGpi(!str_contains(strtoupper($section),strtoupper($unsafe)),'La batería automática tiene escritura de operaciones.');
echo "PRUEBAS UNITARIAS APROBADAS: roles, esquema, escenarios, CSV, HTML y solo lectura.\n";
