<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/gyd_diagnostico.php';
function t(bool $good,string $name):void {if(!$good)throw new RuntimeException('Falló: '.$name);echo 'OK '.$name.PHP_EOL;}
$u=['id'=>2,'rol'=>'ADMINISTRADOR'];t(gdiag_role($u),'administrador permitido');
t(gdiag_role(['id'=>2,'rol'=>'DIRECCION']),'dirección permitida');
t(!gdiag_role(['id'=>2,'rol'=>'CAJERO']),'cajero bloqueado');
t(!gdiag_role(['id'=>0,'rol'=>'DIRECCION']),'sin cuenta bloqueado');
$a=[];gdiag_add($a,'Cajas','Prueba','problema','Una diferencia');
t(count($a)===1 && $a[0]['estado']==='problema','clasificación de error');
try{gdiag_add($a,'Cajas','Prueba','nada','no');throw new RuntimeException('No rechazó un estado inválido.');}catch(DomainException $e){echo 'OK estado inválido bloqueado'.PHP_EOL;}
t(gdiag_h('<script>')==='&lt;script&gt;','escapado HTML');
$fake=dirname(__DIR__,4).'/sin_backups_inventados_'.bin2hex(random_bytes(4));
$test=gdiag_backup_info($fake);
t(in_array($test[0],['atencion','no_disponible'],true),'respaldo ausente no marcado correcto');
