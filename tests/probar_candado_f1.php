<?php
declare(strict_types=1);
require __DIR__.'/../tools/payload/app/helpers/pos_inteligente.php';
function verify(bool $ok,string $txt):void{if(!$ok){fwrite(STDERR,"FALLO: $txt\n");exit(1);}}
verify(pi_f1_clave_candado_stock(3,1126)===pi_f1_clave_candado_stock(4,1126),'candado compartido');
verify(pi_f1_clave_candado_stock(1,1126)!==pi_f1_clave_candado_stock(2,1126),'candados distintos Hgo/Tuxtla');
verify(pi_f1_clave_candado_stock(3,1126)!==pi_f1_clave_candado_stock(3,1394),'candados por producto');
verify(pi_almacen(1)===1&&pi_almacen(2)===3&&pi_almacen(3)===0&&pi_almacen(4)===0,'mapeo actual');
try{pi_f1_clave_candado_stock(0,20);verify(false,'input invalido');}catch(DomainException $e){}
echo "OK: 5 comprobaciones de funciones puras. (No valida MariaDB).\n";
