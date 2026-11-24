<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_acl.php';
function afirmar(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FALLO: $msg\n");exit(1);}echo "OK: $msg\n";}
afirmar(count(gyd_acl_catalogo())===11,'11 grupos administrables');
afirmar(gyd_acl_modulo_ruta('pos_api.php')==='pos','POS API cubierto');
afirmar(gyd_acl_modulo_ruta('erp_alertas_gerenciales_api.php')==='alertas','API de alertas mapeada');
afirmar(gyd_acl_modulo_ruta('erp_oc_win.php')==='compras','Compras mapeado');
afirmar(gyd_acl_modulo_ruta('erp_comparativo_sucursales.php')==='reportes','Comparativo mapeado');
afirmar(gyd_acl_modulo_ruta('logout.php')===null,'Logout fuera de restricciones para no bloquear salida');
afirmar(gyd_acl_puede(['id'=>1,'rol'=>'ADMINISTRADOR'],'pos')===true,'Administrador conserva acceso de rescate');
afirmar(gyd_acl_rol_real(['rol'=>'ADMINISTRADOR','rol_original'=>'DIRECCION'])==='DIRECCION','Se respeta rol real de Dirección');
