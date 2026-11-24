<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_oc_seguimiento.php';
$cases=[
  ['bruto'=>100,'ajuste'=>20,'repuesto'=>0,'esperado'=>80],
  ['bruto'=>120,'ajuste'=>20,'repuesto'=>0,'esperado'=>100],
  ['bruto'=>100,'ajuste'=>20,'repuesto'=>20,'esperado'=>100],
  ['bruto'=>80,'ajuste'=>0,'repuesto'=>0,'esperado'=>80],
];
foreach($cases as $c){
  $n=erp_oc_neto_validado($c['bruto'],$c['ajuste'],$c['repuesto']);
  if($n!==$c['esperado']){fwrite(STDERR,"Error caso ".json_encode($c)."\n");exit(1);}
}
$bad=[[100,20,21],[10,20,0],[-1,0,0]];
foreach($bad as $v){
  try{erp_oc_neto_validado(...$v);fwrite(STDERR,"Caso inválido aceptado\n");exit(1);}
  catch(RuntimeException $e){}
}
echo "OK: 4 escenarios de saldos y 3 inválidos rechazados. Sin MySQL.\n";
