<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_auditoria_cajas_v12.php';
function ok(bool $b,string $msg):void{if(!$b)throw new RuntimeException('Prueba fallida: '.$msg);}
function rechaza(callable $fn):bool{try{$fn();}catch(DomainException $e){return true;}return false;}
ok(gac12_centavos('3000.00')===300000,'centavos');
ok(gac12_centavos('-50.10')===-5010,'importe negativo');
ok(gac12_centavos(null)===null,'importe sin cierre');
ok(gac12_decimal(5012)==='50.12','decimal entero');
ok(gac12_decimal(-5012)==='-50.12','decimal negativo');
ok(gac12_tipo(0)==='EXACTO','exacto');
ok(gac12_tipo(-1)==='FALTANTE','faltante');
ok(gac12_tipo(1)==='SOBRANTE','sobrante');
ok(gac12_tipo(null)==='SIN CONTEO','abierta');
ok(gac12_rol_permitido(['id'=>7,'rol'=>'GERENTE']),'gerente');
ok(gac12_rol_permitido(['id'=>7,'rol_original'=>'DIRECCION','rol'=>'ADMINISTRADOR']),'direccion');
ok(!gac12_rol_permitido(['id'=>1,'rol'=>'CAJERO']),'cajero denegado');
ok(!gac12_rol_permitido(['id'=>0,'rol'=>'ADMINISTRADOR']),'sin sesion');
ok(str_starts_with(gac12_csvtexto('=HYPERLINK("x")'),"'="),'injection Excel');
ok(str_starts_with(gac12_csvtexto('-1+cmd'),"'-"),'injection negativo');
ok(rechaza(fn()=>gac12_periodo('2026-01-01','2026-05-01')),'limite periodo');
ok(rechaza(fn()=>gac12_periodo('2026-02-30','2026-03-01')),'fecha invalida');
ok(gac12_periodo('2026-10-01','2026-10-28')[1]==='2026-10-29 00:00:00','hasta exclusivo');
$helper=file_get_contents(__DIR__.'/../app/helpers/gyd_auditoria_cajas_v12.php');
$pagina=file_get_contents(__DIR__.'/../public/erp_auditoria_cajas_v12.php');
ok(str_contains($pagina,'gac12_rol_permitido($usuario)'),'rol verificable servidor');
ok(str_contains($pagina,"'prueba'"),'proteccion base prueba');
ok(!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+pos_|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE)\b/i',$helper.' '.$pagina),'sin SQL modificador');
echo "OK: 19 comprobaciones V12 de formatos, acceso y consultas de solo lectura.\n";
