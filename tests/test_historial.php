<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/erp_historial_precios.php';
$assert=function(bool $b,string $m){if(!$b)throw new RuntimeException('FALLA: '.$m);};
$f=hp_filtros(['desde'=>'2026-10-01','hasta'=>'2026-10-20','q'=>'  NEUROBION  ','usuario'=>'2','sucursal'=>'3','tipo'=>'APLICADO','pag'=>'3']);
$assert($f['q']==='NEUROBION'&&$f['usuario']===2&&$f['sucursal']===3&&$f['pag']===3,'filtros');
[$query,$args]=hp_consulta($f,true);
$assert(str_contains($query,'UNION ALL')&&str_contains($query,'h.sucursal_id = :sucursal')&&$args['q_desc']==='%NEUROBION%','consulta completa');
[$q2]=hp_consulta($f,false);$assert(!str_contains($q2,'UNION ALL'),'consulta sin omisiones');
$assert(hp_csv_seguro('=WEBSERVICE("http://x")')==="'=WEBSERVICE(\"http://x\")",'protección CSV');
$assert(hp_csv_seguro('  +SUM(A1)')==="'  +SUM(A1)",'CSV con espacios');
$assert(hp_csv_seguro('ABC123')==='ABC123','CSV normal');
$assert(hp_esc('<script>')==='&lt;script&gt;','XSS html');
$assert(hp_formato_importe(null)==='—'&&hp_formato_importe(32.295,2)==='$32.30','formato importes');
try{hp_filtros(['desde'=>'2026-02-31']);throw new RuntimeException('fecha inválida permitida');}catch(InvalidArgumentException $e){}
try{hp_filtros(['desde'=>'2026-10-20','hasta'=>'2026-10-01']);throw new RuntimeException('rango inválido permitido');}catch(InvalidArgumentException $e){}
echo "OK: filtros, parámetros SQL, escape CSV/HTML, importes y fechas.\n";
