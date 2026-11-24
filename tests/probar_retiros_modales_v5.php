<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_retiros.php';
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
$cases=[
 ['Fondo 1500; esperado 10000; retiro 8500',1000000,850000,150000,true],
 ['No puede consumir el fondo',1000000,850001,150000,false],
 ['No retiro cero',1000000,0,150000,false],
 ['Permite monto pequeño',155000,5000,150000,true],
 ['Con fondo cero bloquea retiro',100000,50000,0,false],
 ['Saldo igual al fondo',150000,100,150000,false],
 ['Límite exacto por centavo',150001,1,150000,true],
 ['Un centavo de más bloqueado',150001,2,150000,false],
 ['Mayoreo alto',95000000,90000000,150000,true],
 ['Retiro acumulado simulado',400000,100000,150000,true]
];
$ok=0;
foreach($cases as [$name,$esp,$imp,$fondo,$allowed]){
 $result=true;try{gd_validar_retiro_expres($esp,$imp,$fondo);}catch(DomainException $e){$result=false;}
 if($result!==$allowed){fwrite(STDERR,"FALLÓ: $name\n");exit(1);}echo "OK: $name\n";$ok++;
}
$root=dirname(__DIR__);
$files=['public/pos_retiros.php','public/pos_corte_retiros.php','public/erp_cortes_boveda.php'];
foreach($files as $file){$src=file_get_contents($root.'/'.$file);if(strpos($src,'Verificación bóveda')!==false || strpos($src,'SIN_VERIFICAR')!==false){fwrite(STDERR,"FALLÓ: indicador de verificación visible en $file\n");exit(1);}echo "OK: sin verificación en $file\n";$ok++;}
$legacy=file_get_contents($root.'/public/erp_boveda_verificacion.php');
if(strpos($legacy,'405')===false || strpos($legacy,'UPDATE gyd_retiros_boveda')!==false){fwrite(STDERR,"FALLÓ: ruta de verificación aún activa\n");exit(1);}echo "OK: verificación electrónica deshabilitada\n";$ok++;
$supervision=file_get_contents($root.'/public/erp_retiros_supervision.php');
if(strpos($supervision,'405')===false || strpos($supervision,'UPDATE erp_retiros_supervisados')!==false){fwrite(STDERR,"FALLÓ: antigua supervisión todavía activa\n");exit(1);}
echo "OK: ruta antigua de supervisión deshabilitada\n";$ok++;
$diario=file_get_contents($root.'/public/erp_retiros_diarios.php');
if(strpos($diario,'405')!==false && strpos($diario,'UPDATE erp_retiros_supervisados')===false){
 echo "OK: el reporte diario antiguo está redirigido y bloquea POST\n";$ok++;
} else {
 echo "AVISO NO VALIDADO: reporte diario personalizado conservado. Sus formularios y POST deben revisarse por separado.\n";
}
$asset=file_get_contents($root.'/public/assets/js/gyd_feedback_modal.js');
if(strpos($asset,'GydModalSuccessFake')!==false || strpos($asset,'data-gyd-modal-type')===false){exit(1);}echo "OK: componente compartido disponible\n";$ok++;
echo "$ok comprobaciones satisfactorias; reporte diario personalizado no validado si se muestra aviso (sin conexión a BD ni impresora).\n";
