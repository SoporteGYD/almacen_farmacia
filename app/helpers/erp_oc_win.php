<?php
declare(strict_types=1);
require_once __DIR__.'/../config/erp_oc_politica.php';
/** Complemento de OC; no genera movimientos de inventario ni actualiza precios. */
function ocw_e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ocw_role(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
function ocw_can_edit(array $u): bool {return in_array(ocw_role($u),['ADMIN','ADMINISTRADOR','COMPRAS','DIRECCION'],true);}
function ocw_can_admin(array $u): bool {return in_array(ocw_role($u),['ADMIN','ADMINISTRADOR','DIRECCION'],true);}
function ocw_can_read(PDO $db,array $u,string $destino):bool{
 if(ocw_can_edit($u)) return true;
 if(ocw_role($u)!=='GERENTE')return false;
 $st=$db->prepare("SELECT COUNT(*) FROM erp_oc_win_gerentes g JOIN usuarios u ON u.id=g.usuario_id WHERE g.destino=? AND g.usuario_id=? AND g.activo=1 AND u.estado=1 AND u.rol='GERENTE'");
 $st->execute([$destino,(int)($u['id']??0)]);return (int)$st->fetchColumn()>0;
}
function ocw_guard(array $u):void{
 if(!ocw_can_edit($u)&&ocw_role($u)!=='GERENTE'){http_response_code(403);exit('No tienes acceso a órdenes de compra.');}
}
function ocw_token():string{if(empty($_SESSION['ocw_token']))$_SESSION['ocw_token']=bin2hex(random_bytes(24));return (string)$_SESSION['ocw_token'];}
function ocw_check_token(mixed $t):void{if(!is_string($t)||!hash_equals(ocw_token(),$t))throw new RuntimeException('Sesión vencida. Recarga la página.');}
function ocw_destinos():array{return ['TAPACHULA'=>'Tapachula','TOSCANA'=>'Plaza Toscana','HIDALGO_FARMACIA'=>'Cd Hidalgo · Farmacia','HIDALGO_BODEGA'=>'Cd Hidalgo · Almacén','TUXTLA_FARMACIA'=>'Tuxtla · Farmacia','TUXTLA_BODEGA'=>'Tuxtla · Almacén'];}
function ocw_date(mixed $x):string{$x=(string)$x;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$x);if(!$d||$d->format('Y-m-d')!==$x)throw new RuntimeException('Fecha inválida.');return $x;}
function ocw_number(mixed $v,int $decimals=2,float $max=999999999):float{
 if(!is_scalar($v)||!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',trim((string)$v)))throw new RuntimeException('Hay un importe o porcentaje incorrecto.');
 $n=(float)$v;if($n>$max)throw new RuntimeException('Un importe supera el límite permitido.');return round($n,$decimals);
}
function ocw_pct(mixed $v):float {return ocw_number($v,2,100);}
/** Fuente de verdad del cálculo. Descuentos sucesivos aplicados a cada renglón. IVA/IEPS comerciales estimados. */
function ocw_calcular(array $lineas,array $tasas,float $fpgc=0):array {
 $subtotal=0.;$descuento=0.;$iva=0.;$ieps=0.;$cant=0.;$cajas=0.;$salida=[];
 foreach($lineas as $l){
  $base=round($l['cantidad']*$l['costo'],2);$neto=$base;
  foreach($tasas as $t)$neto=round($neto*(1-$t/100),2);
  $ds=round($base-$neto,2);$taxIeps=round($neto*($l['ieps']/100),2);
  $taxIva=round(($neto+$taxIeps)*($l['iva']/100),2);
  $subtotal+=$base;$descuento+=$ds;$iva+=$taxIva;$ieps+=$taxIeps;$cant+=$l['cantidad'];$cajas+=$l['cajas'];
  $l['importe']=$base;$l['descuento']=$ds;$l['iva_importe']=$taxIva;$l['ieps_importe']=$taxIeps;$salida[]=$l;
 }
 $subtotal=round($subtotal,2);$descuento=round($descuento,2);$iva=round($iva,2);$ieps=round($ieps,2);
 return ['lineas'=>$salida,'subtotal'=>$subtotal,'descuento'=>$descuento,'iva'=>$iva,'ieps'=>$ieps,'fpgc'=>$fpgc,'total'=>round($subtotal-$descuento+$iva+$ieps+$fpgc,2),'piezas'=>$cant,'cajas'=>round($cajas,2),'articulos'=>count($lineas)];
}
function ocw_numero(int $id):string {return str_pad((string)$id,12,'0',STR_PAD_LEFT);}
function ocw_order(PDO $db,int $id,array $user):array {
 $st=$db->prepare("SELECT o.*, p.nombre AS proveedor,p.direccion AS p_direccion,p.telefono AS p_telefono, m.*, a.estado AS autorizacion, a.decidido_por, u.nombre AS autorizo, em.nombre AS emitio FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN erp_oc_win_meta m ON m.orden_id=o.id LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id LEFT JOIN usuarios u ON u.id=a.decidido_por LEFT JOIN usuarios em ON em.id=o.usuario_id WHERE o.id=?");
 $st->execute([$id]);$orden=$st->fetch(PDO::FETCH_ASSOC);
 if(!$orden)throw new RuntimeException('La orden no existe.');
 if(!ocw_can_read($db,$user,(string)$orden['destino'])){http_response_code(403);exit('No autorizado para consultar esta sucursal.');}
 $st=$db->prepare('SELECT d.*, w.unidad, w.tasa_iva, w.tasa_ieps, w.cantidad_cajas FROM erp_ordenes_compra_detalle d LEFT JOIN erp_oc_win_lineas w ON w.detalle_id=d.id WHERE d.orden_id=? ORDER BY d.id');$st->execute([$id]);
 return ['orden'=>$orden,'lineas'=>$st->fetchAll(PDO::FETCH_ASSOC)];
}
