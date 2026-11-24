<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/gyd_f1_topologia_lib.php';
function a(bool $ok,string $m):void {if(!$ok)throw new RuntimeException('FALLÓ: '.$m);}
a(gydF1Canonico('Tuxtla Gutiérrez')==='TUXTLA GUTIERREZ','normalización acentos');
a(gydF1Canonico('CD HIDALGO')==='CIUDAD HIDALGO','alias Hidalgo');
a(gydF1Canonico('Plaza Toscana')==='PLAZA TOSCANA','Toscana');
$bueno=gydF1AnalizarSucursales([['id'=>1,'nombre'=>'Ciudad Hidalgo'],['id'=>2,'nombre'=>'Tuxtla Gutiérrez'],['id'=>3,'nombre'=>'Tapachula'],['id'=>4,'nombre'=>'Plaza Toscana']]);
a(count($bueno['alertas'])===0,'mapa esperado');
$malo=gydF1AnalizarSucursales([['id'=>9,'nombre'=>'Ciudad Hidalgo'],['id'=>2,'nombre'=>'Tuxtla'],['id'=>3,'nombre'=>'Tapachula']]);
a(count($malo['alertas'])>=2,'detecta ID de bodega y sucursal faltante');
echo "OK: 5 pruebas de mapa y alias aprobadas (sin MySQL).\n";
