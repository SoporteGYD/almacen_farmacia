<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_conciliacion_automatica.php';
function prueba(array $over, string $estado, int $recibir, int $pendAplicar):void{
  $base=['solicitadas'=>13938,'recibidas'=>13935,'facturadas'=>13935,'aceptadas'=>13935,'incidencias'=>0,'aplicadas'=>13935,'facturada_actual'=>13935,'aceptada_actual'=>13935,'aplicada_actual'=>13935,'audit_errores'=>0,'destino_errores'=>0];
  $r=gyd_concil_clasificar(array_replace($base,$over),true);
  if($r['estado']!==$estado||$r['por_recibir']!==$recibir||$r['pendiente_aplicar']!==$pendAplicar){fwrite(STDERR,'Falló '.json_encode($r)."\n");exit(1);} }
prueba([],'PENDIENTE',3,0);
prueba(['solicitadas'=>13935],'CONCILIADO',0,0);
prueba(['aplicadas'=>10000,'aplicada_actual'=>10000],'PENDIENTE',3,3935);
prueba(['aplicadas'=>15000],'REVISAR',3,0);
prueba(['audit_errores'=>1],'REVISAR',3,0);
prueba(['destino_errores'=>1],'REVISAR',3,0);
prueba(['aceptadas'=>13936],'REVISAR',3,1);
prueba(['facturadas'=>13939],'REVISAR',3,0);
echo "OK: 8 escenarios comprobados.\n";
$r=gyd_concil_clasificar(['solicitadas'=>10,'recibidas'=>0,'facturadas'=>10,'aceptadas'=>10,'incidencias'=>0,'aplicadas'=>10,'facturada_actual'=>10,'aceptada_actual'=>10,'aplicada_actual'=>10,'audit_errores'=>0,'destino_errores'=>0],true,false);
if($r['estado']!=='CONCILIADO'||$r['por_recibir']!==0){fwrite(STDERR,"Falló modo sin recepción.\n");exit(1);}
if(gyd_concil_csv_seguro('=2+2')!=="'=2+2"){fwrite(STDERR,"Falló protección CSV.\n");exit(1);}
echo "OK: modo sin recepción y celdas CSV comprobados.\n";
