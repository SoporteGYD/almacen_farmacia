<?php
declare(strict_types=1);
require_once __DIR__.'/pos_inteligente.php';
function co_esc(mixed $x):string {return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function co_role(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
function co_admin(array $u):bool{return in_array(co_role($u),['ADMINISTRADOR','ADMIN','DIRECCION'],true);}
function co_can_count(array $u,int $sid,string $where):bool {
 if(co_admin($u))return true;
 $alm=(int)($u['almacen_id']??0);
 if($where==='ALMACEN' && in_array(co_role($u),['ENCARGADO','JEFE_ALMACEN'],true)) return ($sid===1 && $alm===1)||($sid===2 && in_array($alm,[2,3],true));
 if($where==='FARMACIA' && co_role($u)==='GERENTE') return ($sid===1 && $alm===1)||($sid===2 && in_array($alm,[2,3],true));
 return false;
}
function co_sid(int $sid):bool{return in_array($sid,[1,2],true);}
function co_producto(PDO $db,int $pid):array {
 $st=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE id=? AND estado=1');$st->execute([$pid]);
 return $st->fetch(PDO::FETCH_ASSOC)?:[];
}
function co_last(PDO $db,int $sid,int $pid,string $where):?array {
 $st=$db->prepare('SELECT * FROM erp_conciliacion_conteos WHERE sucursal_id=? AND producto_id=? AND ubicacion=? ORDER BY id DESC LIMIT 1');
 $st->execute([$sid,$pid,$where]);return $st->fetch(PDO::FETCH_ASSOC)?:null;
}
function co_event(PDO $db,int $id,string $ev,int $user,?int $old,?int $new,string $note):void {
 $st=$db->prepare('INSERT INTO erp_conciliacion_bitacora(ajuste_id,evento,usuario_id,anterior,nuevo,observacion) VALUES (?,?,?,?,?,?)');
 $st->execute([$id,$ev,$user,$old,$new,mb_substr($note,0,500)]);
}
function co_int(mixed $val):int|false {
 return filter_var($val,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000000]]);
}
/** Lock POS stock with INSERT IGNORE followed by SELECT ... FOR UPDATE to serialize with checkout. */
function co_lock_pos(PDO $db,int $sid,int $pid):int {
 $db->prepare('INSERT IGNORE INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES (?,?,0)')->execute([$sid,$pid]);
 $st=$db->prepare('SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=? FOR UPDATE');$st->execute([$sid,$pid]);
 $val=$st->fetchColumn();if($val===false)throw new RuntimeException('No se pudo bloquear el saldo POS.');return (int)$val;
}
function co_count_fresh(?array $r):bool {
 return $r!==null && strtotime((string)$r['registrado_en'])!==false && strtotime((string)$r['registrado_en']) >= time()-24*3600;
}
