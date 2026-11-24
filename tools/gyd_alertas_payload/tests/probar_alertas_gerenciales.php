<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_alertas_gerenciales.php';
function t(bool $x,string $msg):void{if(!$x)throw new RuntimeException('Falló: '.$msg);}
t(gag_role(['id'=>1,'rol'=>'ADMINISTRADOR']),'admin permitido');
t(gag_role(['id'=>1,'rol'=>'GERENTE']),'gerente permitido');
t(gag_role(['id'=>1,'rol_original'=>'DIRECCION','rol'=>'ADMINISTRADOR']),'dirección permitida');
t(!gag_role(['id'=>1,'rol'=>'CAJERO']),'cajero excluido');
t(!gag_allowed([2],1,0),'gerente no ve sucursales ajenas');
t(gag_allowed([2],2,2),'gerente ve propia');
t(!gag_allowed(null,1,2),'filtro de sucursal restringe');
$e=gag_event('CORTE',25,1,'Tapachula','Faltante','Monto','2026-10-28 18:00:00','alta');
t($e['clave']==='CORTE:25'&&$e['atendible']&&$e['nivel']==='alta','identidad evento');
t(gag_money_cent(300001)==='$3,000.01','umbral centavos');
t(gag_money_cent(300000)==='$3,000.00','limite exacto');
echo "PRUEBAS ESTÁTICAS APROBADAS\n";
