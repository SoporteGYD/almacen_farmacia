<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_cortes_autorizados.php';
function comprobar(bool $condition,string $nombre):void {if(!$condition)throw new RuntimeException('ERROR: '.$nombre);}
foreach(['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','DUENO','DUEÑO','PROPIETARIO'] as $rol)comprobar(gyd_corte_permitido($rol),'Debe autorizar '.$rol);
foreach(['CAJERO','CONTABILIDAD','ENCARGADO','CONSULTA','COMPRAS'] as $rol)comprobar(!gyd_corte_permitido($rol),'No debe autorizar '.$rol);
comprobar(gyd_corte_resultado(5000)===['tipo'=>'SOBRANTE','importe'=>'50.00'],'sobrante');
comprobar(gyd_corte_resultado(-5000)===['tipo'=>'FALTANTE','importe'=>'50.00'],'faltante');
comprobar(gyd_corte_resultado(0)===['tipo'=>'EXACTO','importe'=>'0.00'],'exacto');
echo "OK: roles y estados de corte excluyentes.\n";
