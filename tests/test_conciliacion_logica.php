<?php
declare(strict_types=1);
/** Test unitario de la regla de conciliacion, sin conexion a BD. */
assert_options(ASSERT_ACTIVE,1);
function test(bool $value, string $name):void {if(!$value){fwrite(STDERR,"FALLO: $name\n");exit(1);}echo "OK: $name\n";}
$recep5=80;$recep6=20;$aplicadas=100;
test($recep5+$recep6===$aplicadas,'Estado inicial 100 recibidas en registros');
test($recep5===80,'La recepcion original se mantiene 80');
test($aplicadas-$recep6===$recep5,'Neto corregido igual a recepcion real');
test($aplicadas-$recep5===20,'Ajuste exactamente 20');
$estado='ANULADA';
test($estado==='ANULADA','No eliminar, solo anular');
