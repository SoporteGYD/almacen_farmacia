<?php
declare(strict_types=1);
/** Prueba de reglas sin operaciones SQL ni movimientos de caja. */
$project=dirname(__DIR__);
require_once $project.'/app/helpers/gyd_retiros.php';
$casos=[
 ['fondo'=>150000,'saldo'=>150000,'retiro'=>0],
 ['fondo'=>150000,'saldo'=>350000,'retiro'=>200000],
 ['fondo'=>150000,'saldo'=>955000,'retiro'=>805000],
 ['fondo'=>50000,'saldo'=>51001,'retiro'=>1001],
 ['fondo'=>100000,'saldo'=>100001,'retiro'=>1],
 ['fondo'=>100000,'saldo'=>90000,'retiro'=>0],
 ['fondo'=>0,'saldo'=>100000,'retiro'=>0],
 ['fondo'=>150000,'saldo'=>46000000,'retiro'=>45850000],
];
$ok=0;$fail=0;
foreach($casos as $n=>$t){$result=gr_sugerencia($t['saldo'],$t['fondo']);$pass=$result===$t['retiro'];
 echo ($pass?'OK':'ERROR').' '.($n+1).' sugerencia: '.gr_money($result).' | esperada: '.gr_money($t['retiro'])."\n";
 if($pass)$ok++;else $fail++;
}
$txt=(string)file_get_contents($project.'/public/pos_retiros.php');
$helper=(string)file_get_contents($project.'/app/helpers/gyd_retiros_directos.php');
$checks=[
 ['monto de solo lectura',str_contains($txt,'aria-readonly="true"')],
 ['importe precargado servidor',str_contains($txt,'value="<?=gr_pesos($maximo)?>"')],
 ['consulta de saldo autenticada',str_contains($txt,"isset(\$_GET['saldo_automatico'])")],
 ['verificación antes de registrar',str_contains($txt,'const cambio=await consultarSaldo();')],
 ['verificación bajo bloqueo transaccional',str_contains($helper,'$maximoActual=gr_sugerencia(')],
 ['fondo inicial protegido',str_contains($helper,'gd_validar_retiro_expres(')],
 ['folio de retiro conservado',str_contains($helper,'function gd_folio(')],
];
foreach($checks as [$title,$pass]){echo ($pass?'OK':'ERROR').' '.$title."\n";if($pass)$ok++;else $fail++;}
echo "RESULTADO: $ok correctas / $fail errores (sin MariaDB).\n";exit($fail?1:0);
