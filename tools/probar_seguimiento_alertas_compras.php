<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
require_once dirname(__DIR__).'/app/helpers/erp_compras_alertas_seguimiento.php';
$ok=0;$fail=0;
function check_gdca(bool $cond,string $msg):void{global $ok,$fail;if($cond){$ok++;echo "OK: $msg\n";}else{$fail++;fwrite(STDERR,"FALLO: $msg\n");}}
$event=['tipo'=>'MARGEN_BAJO','producto_id'=>72,'proveedor_id'=>4,'factura_id'=>100,'precio'=>110.,'costo'=>101.];
$a=gdca_fingerprint($event,1);$b=gdca_fingerprint($event,1);
check_gdca(strlen($a)===64&&$a===$b,'Identidad estable: no se duplica por recarga');
$event['precio']=120.;check_gdca(gdca_fingerprint($event,1)!==$a,'Cambio de precio relevante crea caso independiente');
$event['precio']=110.;check_gdca(gdca_fingerprint($event,2)!==$a,'Sucursal diferente crea caso independiente');
$event['factura_id']=101;check_gdca(gdca_fingerprint($event,1)!==$a,'Nueva factura crea caso independiente');
check_gdca(gdca_authorized(['id'=>1,'rol'=>'COMPRAS']),'COMPRAS autorizado');
check_gdca(gdca_authorized(['id'=>1,'rol'=>'ADMINISTRADOR']),'ADMIN autorizado');
check_gdca(!gdca_authorized(['id'=>1,'rol'=>'GERENTE']),'GERENTE no autorizado');
check_gdca(!gdca_authorized(['id'=>1,'rol'=>'CAJERO']),'CAJERO no autorizado');
$defaults=gdca_filter_defaults();
check_gdca($defaults['subida']===20. && $defaults['margen']===15. && $defaults['ahorro']===8. && $defaults['vigencia']===90,'Umbrales canónicos y vigencia');
echo "\nRESULTADO $ok pruebas correctas, $fail fallidas. No se usó MariaDB ni se modificó stock.\n";
exit($fail?1:0);
