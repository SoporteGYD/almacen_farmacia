<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_optimizacion.php';
$cases=[
 ['roles administrador',go_role(['id'=>1,'rol'=>'ADMINISTRADOR'])===true],
 ['cajero sin permiso',go_role(['id'=>3,'rol'=>'CAJERO'])===false],
 ['rol sin usuario',go_role(['id'=>0,'rol'=>'ADMINISTRADOR'])===false],
 ['csv sin formula',go_csv_cell('=SOME(1)')==="'=SOME(1)"],
 ['definiciones unicas',count(array_unique(array_column(go_definitions(),'name')))===count(go_definitions())],
];foreach($cases as [$label,$ok]){echo ($ok?'OK':'ERROR').' '.$label.PHP_EOL;if(!$ok)exit(1);}echo "Pruebas estáticas aprobadas.\n";
