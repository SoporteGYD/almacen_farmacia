<?php
declare(strict_types=1);
/** Test sin BD: revisa coherencia de las anclas y ejemplos de contabilización, no simula una operación real. */
if(PHP_SAPI!=='cli')exit('Solo CLI.');
$root=dirname(__DIR__);$issues=[];
function rv_assert(bool $ok,string $detail):void {global $issues;if(!$ok)$issues[]=$detail;}
function rv_contains(string $path,array $terms):void{
 global $root;
 $p=$root.'/'.$path;
 if(!is_file($p)){rv_assert(false,'Falta '.$path);return;}
 $s=(string)file_get_contents($p);
 foreach($terms as $term)rv_assert(str_contains($s,$term),$path.' no contiene '.$term);
}
rv_contains('app/models/PosReversiones.php',["$".'saldo+=',"if($".'medio===\'EFECTIVO\')',"'CANCELACION_TOTAL'",'pos_movimientos_caja','operacion_clave']);
rv_contains('app/models/PuntoVenta.php',["grc.turno_reembolso_id=v.turno_id"]);
rv_contains('app/helpers/gyd_retiros_directos.php',["grc.turno_reembolso_id=v.turno_id"]);
rv_contains('app/helpers/gyd_conciliacion_diaria.php',["grc.turno_reembolso_id=v.turno_id"]);
rv_contains('public/erp_control_cajas.php',["grc.turno_reembolso_id=v.turno_id"]);
rv_contains('public/erp_pos_reversion_ticket.php',["FARMACIA G&amp;D",'window.print()']);
// Contabilidad esperada: efectivo original cobrado + ingresos - reembolsos efectivos - otros retiros.
$cases=[
 ['venta en efectivo y cancelación en efectivo',0,10000,0,10000,0],
 ['venta mixta $50 efectivo/$50 tarjeta, reembolso $100 efectivo',0,5000,0,10000,-5000],
 ['venta tarjeta, reembolso $100 en efectivo',0,0,0,10000,-10000],
 ['venta en efectivo, cancelación reembolsada por tarjeta',0,10000,0,0,10000],
 ['venta efectivo $100, devolución parcial $30',0,10000,0,3000,7000],
 ['caja con fondo $500 y retiro a bóveda $100, reembolso $50',50000,10000,0,15000,45000],
];
foreach($cases as [$name,$f,$received,$plus,$withdraw,$expect])rv_assert($f+$received+$plus-$withdraw===$expect,'Error: '.$name);
if($issues){foreach($issues as $issue)fwrite(STDERR,'FALLÓ: '.$issue."\n");exit(1);}
echo "OK: firmas del parche presentes y 6 escenarios aritméticos coherentes.\n";
echo "NOTA: es prueba estática; falta verificar con XAMPP/MariaDB, tickets y conciliación física.\n";
