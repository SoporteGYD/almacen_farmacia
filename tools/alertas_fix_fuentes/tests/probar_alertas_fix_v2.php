<?php
declare(strict_types=1);
// Prueba de reglas puras, no conecta a MariaDB ni modifica datos.
require_once __DIR__.'/../app/helpers/erp_compras_alertas_auto.php';
$tests=[
 ['SIN_PRECIO',35.,[],0.,true],
 ['SIN_PRECIO',null,[],0.,false],
 ['MARGEN_BAJO',110.,[],100.,false],
 ['MARGEN_BAJO',120.,[],100.,true],
 ['MARGEN_BAJO',120.,['MARGEN_BAJO'],100.,false],
 ['PERDIDA',95.,[],100.,false],
 ['PERDIDA',105.,[],100.,true],
 ['PERDIDA',105.,['PERDIDA'],100.,false],
 ['SUBIDA',200.,[],100.,false],
];
$ok=0;$bad=0;
foreach($tests as $i=>$t){
  [$tipo,$precio,$activos,$costo,$esperado]=$t;
  $res=gdca_auto_valida(['tipo'=>$tipo,'costo'=>$costo],$precio,$activos)!==null;
  $pass=$res===$esperado;
  echo ($pass?'OK':'FALLO').' '.($i+1)." $tipo: ".($res?'corregida':'no comprobada')."\n";
  if($pass)$ok++;else $bad++;
}
echo "RESULTADO: $ok/".count($tests)."\n";
exit($bad?1:0);
