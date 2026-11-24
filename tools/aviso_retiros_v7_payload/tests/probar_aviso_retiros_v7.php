<?php
declare(strict_types=1);
/* Pruebas estáticas: no hay conexión ni escritura a MySQL. */
$raiz=dirname(__DIR__);
$estado=(string)@file_get_contents($raiz.'/public/pos_retiros_estado.php');
$aviso=(string)@file_get_contents($raiz.'/public/assets/js/pos_retiros_aviso.js');
$pos=(string)@file_get_contents($raiz.'/public/pos.php');
$pruebas=[
    'Consulta la misma contabilidad que el retiro efectivo'=>str_contains($estado,'gd_cash_totals($db'),
    'Usa el mismo cálculo de importe automático'=>str_contains($estado,'gr_sugerencia($resumen'),
    'No consulta solicitudes anteriores'=>!str_contains($estado,'erp_retiros_supervisados')&&!str_contains($estado,"estado='SOLICITADO'"),
    'No anuncia retiros cuando el importe es 0'=>str_contains($aviso,'centavos <= 0'),
    'No advierte sobre confirmación inexistente'=>!str_contains($aviso,'sin confirmar')&&!str_contains($aviso,'No entregues efectivo'),
    'Consulta AJAX sin escribir caja'=>!preg_match('/\b(fetch\s*\(.*method\s*:\s*["\']POST)/si',$aviso),
    'Carga versión corregida para evitar caché'=>str_contains($pos,'pos_retiros_aviso.js?v=20261028_7'),
];
$fallos=0;
foreach ($pruebas as $nombre=>$ok) { echo ($ok?'OK':'ERROR').' '.$nombre."\n"; if (!$ok) $fallos++; }
exit($fallos?1:0);
