<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Sólo CLI');}
require_once __DIR__.'/../app/helpers/gyd_rewards_precanje_v39.php';
if(GydRewardsPrecanjeV39::CANJES_ACTIVOS!==false){fwrite(STDERR,"ERROR: La bandera de canjes debe ser falsa.\n");exit(1);}
try{GydRewardsPrecanjeV39::ejecutarCanje();fwrite(STDERR,"ERROR: La función permitió un canje.\n");exit(1);}
catch(DomainException $e){if(!str_contains($e->getMessage(),'BLOQUEADO')){fwrite(STDERR,"ERROR: Mensaje de guarda inesperado.\n");exit(1);}}
$real=(string)file_get_contents(__DIR__.'/../public/gyd_canjes_api.php');
if(!str_contains($real,"if(\$act==='canjear')reply39") || !str_contains($real,'CANJE REAL INHABILITADO')){
   fwrite(STDERR,"ERROR: Bloqueo del API no encontrado.\n");exit(1);
}
echo "SEGURIDAD V39 OK: canje real deshabilitado; sólo se preparan solicitudes.\n";
