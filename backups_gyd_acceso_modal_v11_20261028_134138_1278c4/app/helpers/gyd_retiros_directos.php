<?php
declare(strict_types=1);
require_once __DIR__.'/gyd_retiros.php';
require_once __DIR__.'/pos_corte_denominaciones.php';
/** Importes DECIMAL(14,2) -> centavos sin operaciones con float. */
function gd_cents(mixed $s):int { return gr_cents($s); }
function gd_dollars(int $c):string { return gr_pesos($c); }
function gd_signed(int $c):string { return ($c<0?'-':'').gr_money(abs($c)); }
function gd_cash_totals(PDO $db,int $turno):array {
  $t=gr_query($db,'SELECT fondo_inicial,caja_id,usuario_apertura_id,estado FROM pos_turnos WHERE id=?',[$turno])->fetch(PDO::FETCH_ASSOC);
  if(!$t)throw new DomainException('El turno no existe.');
  $cobros=gr_query($db,"SELECT COALESCE(SUM(pg.monto),0) FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND pg.metodo='EFECTIVO' AND pg.estado='CONFIRMADO' AND (v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones grc WHERE grc.venta_id=v.id AND grc.tipo='CANCELACION_TOTAL' AND grc.estado='APLICADA' AND grc.turno_reembolso_id=v.turno_id)))",[$turno])->fetchColumn();
  $movs=gr_query($db,"SELECT tipo,COALESCE(SUM(monto),0) monto FROM pos_movimientos_caja WHERE turno_id=? AND tipo IN ('INGRESO','RETIRO') GROUP BY tipo",[$turno])->fetchAll(PDO::FETCH_ASSOC);
  $mov=['INGRESO'=>0,'RETIRO'=>0];foreach($movs as $m)$mov[$m['tipo']]=gd_cents($m['monto']);
  $fondo=gd_cents($t['fondo_inicial']);$ventas=gd_cents($cobros);
  return ['turno'=>$t,'fondo'=>$fondo,'ventas'=>$ventas,'ingresos'=>$mov['INGRESO'],'retiros'=>$mov['RETIRO'],'esperado'=>$fondo+$ventas+$mov['INGRESO']-$mov['RETIRO']];
}
/** Folio global inequívoco para cotejo con base de datos y recibos. */
function gd_folio(int $id):string {return 'RET-'.str_pad((string)$id,8,'0',STR_PAD_LEFT);}
function gd_can_operate(array $u,array $turno):bool{
  return gr_admin($u) || ((int)$turno['usuario_apertura_id']===(int)($u['id']??0));
}

/** Solo monto: se comprueba el libro POS y se protege el fondo inicial.
 * No se afirma que hubo un conteo físico del cajón en un retiro exprés.
 */
function gd_validar_retiro_expres(int $esperado,int $importe,int $fondo):void {
  gr_validar_monto($esperado,$esperado,$importe,$fondo);
}
/** Solo la persona identificada por la sesión puede autorizar su retiro con contraseña. */
function gd_verificar_clave_cajero(PDO $db,array $usuario,string $clave):void {
  $id=(int)($usuario['id']??0);
  if($id<1 || $clave==='' || strlen($clave)>512)throw new DomainException('Ingresa tu contraseña para realizar el retiro.');
  $q=gr_query($db,'SELECT COUNT(*) FROM gyd_retiros_intentos_clave WHERE usuario_id=? AND correcto=0 AND creado_en>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$id]);
  if((int)$q->fetchColumn()>=5)throw new DomainException('Demasiados intentos fallidos. Espera 15 minutos o comunícate con Administración.');
  $r=gr_query($db,'SELECT id,estado,password FROM usuarios WHERE id=? LIMIT 1',[$id])->fetch(PDO::FETCH_ASSOC);
  $ok=is_array($r) && (int)($r['estado']??0)===1 && password_verify($clave,(string)($r['password']??''));
  gr_query($db,'INSERT INTO gyd_retiros_intentos_clave(usuario_id,correcto,ip_hash) VALUES (?,?,?)',[$id,$ok?1:0,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]);
  if(!$ok)throw new DomainException('Contraseña incorrecta. El retiro NO fue registrado.');
}
/** Un folio por retiro, mismo movimiento en POS, tabla de bóveda y corte. */
function gd_guardar_retiro(PDO $db,array $usuario,int $caja,int $importe,string $token,string $claveCajero):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new DomainException('Identificador de operación inválido.');
  gr_test_db($db);gr_permission_caja($db,$usuario,$caja);
  gd_verificar_clave_cajero($db,$usuario,$claveCajero);
  $db->beginTransaction();
  try {
    $t=gr_turno($db,$caja,true);
    if((int)$t['usuario_apertura_id']!==(int)($usuario['id']??0))throw new DomainException('El retiro requiere la contraseña del cajero que abrió este turno.');
    $dup=gr_query($db,'SELECT id,turno_id,importe FROM gyd_retiros_boveda WHERE operacion_clave=?',[$token])->fetch(PDO::FETCH_ASSOC);
    if($dup){if((int)$dup['turno_id']!==(int)$t['id']||gd_cents($dup['importe'])!==$importe)throw new DomainException('La operación ya fue usada con otros datos.');$db->commit();return ['id'=>(int)$dup['id'],'repetido'=>true];}
    $tot=gd_cash_totals($db,(int)$t['id']);
    // GYD_RETIRO_AUTO_V6: verificar dentro de la transaccion el importe automatico exacto.
    $maximoActual=gr_sugerencia($tot['esperado'],$tot['fondo']);
    if($maximoActual<=0)throw new DomainException('No hay excedente para retiro. Se debe conservar el fondo inicial.');
    if($importe!==$maximoActual)throw new DomainException('El saldo cambió desde que se mostró el retiro. El monto actualizado que puedes retirar es '.gr_money($maximoActual).'. Actualiza y vuelve a confirmar.');
    gd_validar_retiro_expres($tot['esperado'],$importe,$tot['fondo']);
    $uid=(int)$usuario['id'];$resto=$tot['esperado']-$importe;
    gr_query($db,"INSERT INTO pos_movimientos_caja(turno_id,usuario_id,tipo,monto,motivo) VALUES (?,?,'RETIRO',?,'Retiro exprés a bóveda (comprobante POS)')",[(int)$t['id'],$uid,gd_dollars($importe)]);
    $mov=(int)$db->lastInsertId();
    // NULL indica que no se realizó un arqueo/conteo; jamás simular efectivo contado.
    gr_query($db,"INSERT INTO erp_retiros_supervisados(turno_id,caja_id,cajero_id,contado_declarado,esperado_solicitud,importe,motivo,estado,movimiento_id,solicitud_en,resolucion_en,referencia) VALUES (?,?,?,NULL,?,?,'Retiro exprés a bóveda','CONFIRMADO',?,NOW(),NOW(),?)",[(int)$t['id'],$caja,$uid,gd_dollars($tot['esperado']),gd_dollars($importe),$mov,'RETIRO EXPRÉS']);
    $anterior=(int)$db->lastInsertId();
    gr_query($db,'INSERT INTO gyd_retiros_boveda(retiro_legacy_id,turno_id,caja_id,cajero_id,movimiento_id,operacion_clave,importe,esperado_antes,efectivo_despues,desglose_json) VALUES (?,?,?,?,?,?,?,?,?,?)',[$anterior,(int)$t['id'],$caja,$uid,$mov,$token,gd_dollars($importe),gd_dollars($tot['esperado']),gd_dollars($resto),'{}']);
    $id=(int)$db->lastInsertId();
    gr_query($db,'UPDATE erp_retiros_supervisados SET referencia=? WHERE id=?',[gd_folio($id),$anterior]);
    $has=gr_query($db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_caja_movimientos_control'")->fetchColumn();
    if($has)gr_query($db,"INSERT INTO erp_caja_movimientos_control(operacion_clave,turno_id,movimiento_id,usuario_id,tipo,monto,motivo,referencia) VALUES (?,?,?,?,'RETIRO',?,?,?)",[$token,(int)$t['id'],$mov,$uid,gd_dollars($importe),'Retiro exprés a bóveda',gd_folio($id)]);
    gr_audit($db,$uid,'RETIRO_BOVEDA_REGISTRADO',['folio'=>gd_folio($id),'turno'=>(int)$t['id'],'movimiento'=>$mov,'importe'=>gd_dollars($importe),'esperado_despues'=>gd_dollars($resto),'modalidad'=>'SIN_ARQUEO']);
    $db->commit();return ['id'=>$id,'repetido'=>false];
  } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function gd_detalle_retiro(PDO $db,int $id):?array {
  return gr_query($db,'SELECT b.*,t.abierto_en,t.estado turno_estado,s.nombre sucursal,c.nombre caja,u.nombre cajero,v.nombre verificador FROM gyd_retiros_boveda b JOIN pos_turnos t ON t.id=b.turno_id JOIN pos_cajas c ON c.id=b.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios u ON u.id=b.cajero_id LEFT JOIN usuarios v ON v.id=b.verificado_por WHERE b.id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;
}
function gd_retiros_turno(PDO $db,int $turnoId):array {
 return gr_query($db,'SELECT b.id,b.importe,b.creado_en,b.boveda_estado,b.verificado_en,b.importe_boveda,COALESCE(u.nombre,\'\') cajero FROM gyd_retiros_boveda b LEFT JOIN usuarios u ON u.id=b.cajero_id WHERE b.turno_id=? ORDER BY b.id ASC',[$turnoId])->fetchAll(PDO::FETCH_ASSOC);
}
function gd_sum_retiros(array $retiros):int{ $s=0;foreach($retiros as $r)$s+=gd_cents($r['importe']);return $s;}
function gd_resumen_corte(array $t,array $s,array $retiros):array {
 $enTickets=gd_sum_retiros($retiros);
 $otros=$s['retiros']-$enTickets;
 if($otros<0)throw new DomainException('Inconsistencia: tickets de retiro superan movimientos del turno.');
 $contado=$t['efectivo_contado']===null?null:gd_cents($t['efectivo_contado']);
 return ['tickets'=>$enTickets,'otros'=>$otros,'retiros_totales'=>$s['retiros'],'esperado'=>$s['esperado'],'contado'=>$contado,'diferencia'=>$contado===null?null:$contado-$s['esperado']];
}
