<?php
declare(strict_types=1);
/** Control de efectivo supervisado, sólo laboratorio prueba. */
const GYD_RETIRO_UMBRAL_CENTAVOS = 300000;   // $3,000.00 en el cajón
const GYD_RETIRO_RESERVA_CENTAVOS = 100000;  // $1,000.00 para cambio
function gr_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gr_money(int $n):string{return '$'.number_format($n/100,2,'.',',');}
function gr_pesos(int $n):string{return number_format($n/100,2,'.','');}
function gr_cents(mixed $v):int{
    $s=trim((string)$v);
    if(!preg_match('/^\\d{1,9}(?:\\.\\d{1,2})?$/D',$s)) throw new DomainException('Importe inválido; utiliza hasta dos decimales.');
    [$p,$d]=array_pad(explode('.',$s,2),2,'');return (int)$p*100+(int)str_pad($d,2,'0');
}
function gr_query(PDO $db,string $sql,array $values=[]):PDOStatement{
    $st=$db->prepare($sql);$st->execute($values);return $st;
}
function gr_role(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
function gr_admin(array $u):bool{return in_array(gr_role($u),['ADMINISTRADOR','ADMIN','DIRECCION'],true);}
function gr_test_db(PDO $db):void{
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba')throw new DomainException('Sólo se permite registrar retiros en la base prueba.');
}
/** Misma regla contable que cerrarTurno: fondo + pagos efectivos confirmados + ingresos - retiros. */
function gr_expected(PDO $db,int $turnoId):array{
    $t=gr_query($db,'SELECT fondo_inicial FROM pos_turnos WHERE id=?',[$turnoId])->fetch(PDO::FETCH_ASSOC);
    if(!$t) throw new DomainException('Turno no encontrado.');
    $v=gr_query($db,"SELECT COALESCE(SUM(pg.monto),0) FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND pg.metodo='EFECTIVO' AND pg.estado='CONFIRMADO' AND v.estado='PAGADA'",[$turnoId])->fetchColumn();
    $mov=gr_query($db,"SELECT COALESCE(SUM(CASE WHEN tipo='INGRESO' THEN monto WHEN tipo='RETIRO' THEN -monto ELSE 0 END),0) FROM pos_movimientos_caja WHERE turno_id=?",[$turnoId])->fetchColumn();
    $f=(int)round((float)$t['fondo_inicial']*100);$ventas=(int)round((float)$v*100);$ajustes=(int)round((float)$mov*100);
    return ['fondo'=>$f,'ventas_efectivo'=>$ventas,'movimientos_netos'=>$ajustes,'esperado'=>$f+$ventas+$ajustes];
}
function gr_sugerencia(int $esperado):int{
    if($esperado<GYD_RETIRO_UMBRAL_CENTAVOS)return 0;
    return max(0,$esperado-GYD_RETIRO_RESERVA_CENTAVOS);
}
function gr_audit(PDO $db,int $uid,string $accion,array $detalle):void{
    gr_query($db,'INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES (?,NULL,?,?)',[$uid,$accion,json_encode($detalle,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function gr_csrf():string{
    if(empty($_SESSION['gyd_retiro_csrf']))$_SESSION['gyd_retiro_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['gyd_retiro_csrf'];
}
function gr_post_guard():void{
    if(!hash_equals(gr_csrf(),(string)($_POST['csrf']??'')))throw new DomainException('Token de seguridad inválido; actualiza la página.');
}
function gr_permission_caja(PDO $db,array $u,int $cajaId):array{
    $r=gr_query($db,'SELECT c.id,c.nombre caja,s.nombre sucursal,s.id sucursal_id FROM pos_cajas c JOIN pos_sucursales s ON s.id=c.sucursal_id WHERE c.id=? AND c.activa=1 AND s.activo=1',[$cajaId])->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new DomainException('Caja no disponible.');
    if(!gr_admin($u)){
        $as=gr_query($db,'SELECT 1 FROM pos_usuario_asignaciones WHERE usuario_id=? AND sucursal_id=? AND caja_id=?',[(int)$u['id'],(int)$r['sucursal_id'],$cajaId])->fetchColumn();
        if(!$as)throw new DomainException('Esta caja no está asignada a tu usuario.');
    }
    return $r;
}
function gr_turno(PDO $db,int $cajaId,bool $lock=false):array{
    $sql="SELECT id,caja_id,usuario_apertura_id,estado,abierto_en FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':'');
    $t=gr_query($db,$sql,[$cajaId])->fetch(PDO::FETCH_ASSOC);
    if(!$t)throw new DomainException('Primero abre un turno de caja.');
    return $t;
}
function gr_validar_monto(int $esperado,int $declarado,int $retiro):void{
    if($esperado<GYD_RETIRO_UMBRAL_CENTAVOS)throw new DomainException('Aún no se alcanza el límite de $3,000.00 para solicitar retiro.');
    if($retiro<=0)throw new DomainException('El retiro debe ser positivo.');
    if($declarado<0)throw new DomainException('Conteo inválido.');
    if($retiro>max(0,$declarado-GYD_RETIRO_RESERVA_CENTAVOS)) throw new DomainException('El importe dejaría menos de $1,000.00 del efectivo contado.');
    if($retiro>max(0,$esperado-GYD_RETIRO_RESERVA_CENTAVOS)) throw new DomainException('El importe dejaría menos de $1,000.00 del efectivo esperado.');
}
