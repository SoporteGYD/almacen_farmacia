<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/gyd_validacion_operativa.php';
function check(bool $condition,string $name):void {if(!$condition){fwrite(STDERR,"FALLA: $name\n");exit(1);}echo "OK: $name\n";}
check(gvo_authorized(['id'=>3,'rol'=>'ADMINISTRADOR']),'rol admin');
check(gvo_authorized(['id'=>3,'rol'=>'CAJERO','rol_original'=>'DIRECCION']),'rol dirección');
check(!gvo_authorized(['id'=>1,'rol'=>'CAJERO']),'cajero bloqueado');
check(!gvo_authorized(['id'=>0,'rol'=>'ADMINISTRADOR']),'sesión inválida bloqueada');
check(gvo_has(['pos_turnos'=>['id'=>true,'estado'=>true]],'pos_turnos',['id','estado']),'columnas existentes');
check(!gvo_has(['pos_turnos'=>['id'=>true]],'pos_turnos',['estado']),'columnas faltantes');
check(count(gvo_scenarios())===10,'escenarios completos');
check(gvo_result_label('aprobado')==='Aprobado manualmente','diferenciar prueba manual');
check(gvo_h('<script>')==='&lt;script&gt;','escapar HTML');
echo "Pruebas unitarias completas. NO se ha conectado a MySQL ni ejecutado una venta real.\n";
