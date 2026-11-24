<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_catalogo_iva.php';
foreach([['0.00',0.00],['8.00',8.00],['16.00',16.00],[0,0.00],[16.12,16.12]] as [$input,$expected]){
 if(gyd_catalogo_iva_compra($input,'TEST')!==$expected)throw new RuntimeException('Tasa errónea: '.var_export($input,true));
}
foreach([null,'',-1,101,'foo'] as $bad){
 try {gyd_catalogo_iva_compra($bad,'TEST');throw new Exception('Se aceptó tasa inválida');}
 catch(RuntimeException $e){if($e->getMessage()==='Se aceptó tasa inválida')throw $e;}
}
if(gyd_catalogo_ieps_si_existe(null)!==null)throw new RuntimeException('IEPS NULL debe seguir nullable.');
if(gyd_catalogo_ieps_si_existe('8.00')!==8.0)throw new RuntimeException('IEPS configurado inválido.');
echo "OK: 12 verificaciones de catálogo, NULL, 0% y tasas configuradas.\n";
