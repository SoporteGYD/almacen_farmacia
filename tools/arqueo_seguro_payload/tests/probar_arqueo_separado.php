<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/gyd_arqueos.php';
$ok=0;$tests=[];
function t(bool $pass,string $label):void {global $ok;$ok+=(int)$pass;echo ($pass?'OK: ':'FALLO: ').$label.PHP_EOL;if(!$pass)exit(1);}
t(arq_rol_valido('GERENTE'),'Gerente autoriza');
t(arq_rol_valido('DIRECCION'),'Dirección autoriza');
t(arq_rol_valido('ADMINISTRADOR'),'Administrador/Dueño autoriza');
t(arq_rol_valido('CONTABILIDAD'),'Contabilidad autoriza cuando exista');
t(!arq_rol_valido('CAJERO'),'Cajero no autoriza');
t(!arq_rol_valido('ENCARGADO'),'Encargado no autoriza');
t(arq_total_denominaciones(['b500'=>'2','m20'=>'3','m050'=>'4'])['centavos']===106200,'Suma billetes y monedas sin confundir $20');
t(arq_total_denominaciones([])['centavos']===0,'Conteo con cero efectivo permitido');
try{arq_total_denominaciones(['b500'=>'-1']);t(false,'Niega negativos');}catch(DomainException $e){t(true,'Niega negativos');}
try{arq_total_denominaciones(['b1000'=>'1.5']);t(false,'Niega cantidades fraccionarias');}catch(DomainException $e){t(true,'Niega cantidades fraccionarias');}
try{arq_total_denominaciones(['m1'=>'100001']);t(false,'Niega cantidades excesivas');}catch(DomainException $e){t(true,'Niega cantidades excesivas');}
$g=['solicitante'=>20,'caja'=>1,'turno'=>50,'autorizador'=>9,'rol'=>'GERENTE','vence'=>1000];
t(arq_grant_valido($g,20,1,50,950),'Permiso correcto por cajero/caja/turno');
t(!arq_grant_valido($g,20,1,50,1001),'Permiso expira');
t(!arq_grant_valido($g,21,1,50,950),'Permiso ajeno se rechaza');
t(!arq_grant_valido($g,20,2,50,950),'Otra caja se rechaza');
t(!arq_grant_valido($g,20,1,51,950),'Otro turno se rechaza');
$g['rol']='CAJERO';t(!arq_grant_valido($g,20,1,50,950),'Permiso para cajero autorizador inválido');
echo "$ok / 17 pruebas aprobadas. Lógica sin conexión ni escrituras a BD.\n";
