<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Solo terminal');
require_once __DIR__.'/../app/helpers/pos_inteligente.php';
$pruebas=[
 'Tapachula y Toscana comparten llave' => pi_tt_clave(3,1126)===pi_tt_clave(4,1126),
 'Hidalgo tiene llave diferente' => pi_tt_clave(1,1126)!==pi_tt_clave(3,1126),
 'Cada producto tiene llave propia' => pi_tt_clave(3,1126)!==pi_tt_clave(3,1394),
 'Hidalgo corresponde a almacen 1' => pi_almacen(1)===1,
 'Tuxtla corresponde a almacen 3' => pi_almacen(2)===3,
 'Tapachula no tiene almacen fisico separado' => pi_almacen(3)===0,
 'Toscana no tiene almacen fisico separado' => pi_almacen(4)===0,
];
foreach($pruebas as $nombre=>$ok){echo ($ok?'OK':'FALLO').' '.$nombre.PHP_EOL;if(!$ok)$error=true;}
exit(empty($error)?0:1);
