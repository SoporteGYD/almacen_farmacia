<?php
declare(strict_types=1);
$root=dirname(__DIR__).'/tools/conciliacion_directa_payload/despues';
$h=file_get_contents($root.'/gyd_conciliacion_tt.php');
$p=file_get_contents($root.'/erp_conciliacion_tt.php');
$oper=file_get_contents($root.'/erp_conciliacion_gestion.php');
$checks=[
 'Tapachula/Toscana: sólo dos sucursales'=>str_contains($h,'in_array($sid,[3,4],true)'),
 'TT: ajuste desde CONTEO sin otra cuenta'=>str_contains($h,"['CONTEO','SOLICITADO','AUTORIZADO']"),
 'TT: aplicación no duplicada'=>str_contains($h,"Este conteo ya fue finalizado o aplicado"),
 'TT: saldo anterior debe coincidir'=>str_contains($h,'$old!==$stock'),
 'TT: control de auditoría'=>str_contains($h,'gct_audit($db)'),
 'TT: protección de base'=>str_contains($h,"!==3307"),
 'TT: conteo sin cambios VERIFICADO'=>str_contains($h,'$final=$old===$target?\'VERIFICADO\':\'APLICADO\''),
 'TT: sin botón Solicitar autorización'=>!str_contains($p,'>Solicitar autorización<'),
 'TT: exportación sin autorizador'=>!str_contains($p,"'Autorizado por'"),
 'HT: sin botón de autorización'=>!str_contains($oper,'>Autorizar</button>'),
 'HT: registros de ajuste directo'=>str_contains($oper,'Aplicar ajuste directo'),
 'HT: conserva rack/ubicación'=>str_contains($oper,"'PENDIENTE_OPERACION_ALMACEN'"),
 'HT: sigue validando tickets pendientes'=>str_contains($oper,'vendido_pendiente'),
 'HT: verifica base de pruebas'=>str_contains($oper,"'prueba'"),
 'HT: usa bloqueo antes de aplicar'=>str_contains($oper,'co_lock_pos($db,$sucursal,$producto)'),
];
foreach($checks as $msg=>$pass){echo ($pass?'OK  ':'FAIL')." $msg\n";if(!$pass)exit(1);}
echo "TODAS LAS PRUEBAS ESTÁTICAS PASARON.\n";
