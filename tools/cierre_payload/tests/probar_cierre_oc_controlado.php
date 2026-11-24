<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/erp_oc_cierre_controlado.php';
function p(string $label,bool $ok):void {if(!$ok){fwrite(STDERR,"FALLO: $label\n");exit(1);} echo "OK: $label\n";}
function row(int $s,int $b,int $f,int $a,int $ap,array $obs=[]):array {
 return ['producto_id'=>1,'codigo'=>'PRUEBA-001','solicitado'=>$s,'recibido'=>$b,'facturado'=>$f,'aceptado'=>$a,'aplicado'=>$ap,'incidencia'=>0,'observaciones'=>$obs,'ajuste_invalido'=>false];
}
function context(array $line,bool $rec=true):array{return ['orden'=>['estado'=>'EMITIDA'],'partidas'=>[$line],'tiene_recepciones'=>$rec];}
p('Cierre total 100/100',gcc_evaluar(context(row(100,100,100,100,100)))['total']);
p('Cierre normal rechaza faltante',!gcc_evaluar(context(row(100,70,70,70,70)))['total']);
p('Administrador puede evaluar cierre con 30 definitivas',gcc_evaluar(context(row(100,70,70,70,70)))['excepcion']);
p('Cierre con diferencias calcula 30 piezas',gcc_evaluar(context(row(100,70,70,70,70)))['faltantes']===30);
p('No puede cerrar si hay factura sin verificar',!gcc_evaluar(context(row(100,70,70,65,65)))['excepcion']);
p('No puede cerrar si hay inventario pendiente',!gcc_evaluar(context(row(100,70,70,70,60)))['excepcion']);
p('No puede cerrar si faltan facturas de mercancía buena',!gcc_evaluar(context(row(100,70,60,60,60)))['excepcion']);
p('No puede cerrar por sobre-recepción',!gcc_evaluar(context(row(100,105,105,105,105)))['total']);
p('No puede cerrar si no hay evidencia física',!gcc_evaluar(context(row(100,100,100,100,100),false))['total']);
p('No puede cerrar si hay observaciones de conciliación',!gcc_evaluar(context(row(100,100,100,100,100,['Diferencia documental'])))['total']);
p('Incidencia especial impide cierre total',!gcc_evaluar(context(row(100,100,100,100,100)),[],5)['total']);
p('Incidencia especial permite cierre documentado',gcc_evaluar(context(row(100,100,100,100,100)),[],5)['excepcion']);
p('Bodega reconoce entradas protegidas parciales',gcc_evaluar(context(row(100,100,100,100,60)),[1=>40])['total']);
p('Bodega no permite doble conteo entre rutas',!gcc_evaluar(context(row(100,100,100,100,80)),[1=>40])['total']);
p('Compras puede cerrar solo total',gcc_puede_cerrar_total(['id'=>3,'rol'=>'COMPRAS'])&&!gcc_puede_excepcion(['id'=>3,'rol'=>'COMPRAS']));
p('Administrador puede cerrar excepciones y reabrir',gcc_puede_excepcion(['id'=>1,'rol'=>'ADMINISTRADOR']));
p('Encargado no puede cerrar',!gcc_puede_ver(['id'=>4,'rol'=>'ENCARGADO']));
echo "17 / 17 pruebas de reglas aprobadas (sin conexión a BD).\n";
