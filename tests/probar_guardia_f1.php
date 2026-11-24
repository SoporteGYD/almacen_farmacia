<?php
declare(strict_types=1);
require_once __DIR__.'/../tools/_f1_payload/app/helpers/gyd_f1_objetivo_respaldo.php';
$pruebas = [
 ['prueba',3307,'prueba',3307,true],
 ['prueba',3307,'almacen_farmacia',3307,false],
 ['prueba',3306,'prueba',3307,false],
 ['',3307,'prueba',3307,false],
 ['prueba',3307,'base;DROP',3307,false],
];
foreach ($pruebas as $p) {
 [$a,$ap,$d,$dp,$permitido]=$p;
 $r=gyd_f1_comparar_destino_respaldo($a,$ap,$d,$dp);
 if (($r===null)!==$permitido) { fwrite(STDERR,"FALLÓ comparador F1\n"); exit(1); }
}
echo "OK: 5 casos de base correcta, distinta, puerto distinto y nombre inválido.\n";
