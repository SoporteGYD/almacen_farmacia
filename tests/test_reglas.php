<?php
declare(strict_types=1);
function permitido(int $rec,int $net,int $nuevo):bool{return $net>=0 && $nuevo>0 && $net+$nuevo<=$rec;}
$tests=[[80,100,1,false],[80,80,20,false],[100,80,20,true],[80,60,20,true],[100,100,1,false]];
foreach($tests as [$r,$n,$v,$expected]){
 if(permitido($r,$n,$v)!==$expected){fwrite(STDERR,"FALLO\n");exit(1);}
}
echo "OK: limites de recepción y stock neto.\n";
