<?php
declare(strict_types=1);
/** Conciliación asistida exclusiva Tapachula (3) / Toscana (4). */
function gct_h(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gct_role(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
function gct_admin(array $u):bool{return in_array(gct_role($u),['ADMIN','ADMINISTRADOR','DIRECCION'],true);}
function gct_sid(int $sid):bool{return in_array($sid,[3,4],true);}
function gct_sname(int $sid):string{return [3=>'Tapachula',4=>'Plaza Toscana'][$sid]??'No definida';}
function gct_exists(PDO $db,string $table):bool{$s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;}
function gct_guard(PDO $db):void{
 $s=$db->query('SELECT DATABASE() AS db,@@port AS port')->fetch(PDO::FETCH_ASSOC);
 if(($s['db']??'')!=='prueba'||(int)($s['port']??0)!==3307)throw new DomainException('Sólo se permite prueba:3307.');
 if(gct_exists($db,'gyd_tt_pool_config')&&(int)$db->query('SELECT COALESCE(MAX(activo),0) FROM gyd_tt_pool_config')->fetchColumn()!==0)throw new DomainException('El inventario compartido Tapachula/Toscana sigue activo. Corrige eso primero.');
}
function gct_audit(PDO $db):bool{
 if(!gct_exists($db,'gyd_stock_auditoria_real'))return false;
 $s=$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('gyd_stock_pg_ai','gyd_stock_pg_au')");
 return (int)$s->fetchColumn()===2;
}
function gct_stock(PDO $db,int $sid,int $pid,bool $lock=false):?int{
 if(!gct_sid($sid)||$pid<1)throw new DomainException('Sucursal o producto inválidos.');
 $s=$db->prepare('SELECT existencia FROM pos_existencias_farmacia WHERE sucursal_id=? AND producto_id=?'.($lock?' FOR UPDATE':''));$s->execute([$sid,$pid]);$v=$s->fetchColumn();return $v===false?null:(int)$v;
}
function gct_row(PDO $db,int $id,bool $lock=false):?array{$s=$db->prepare('SELECT * FROM gyd_tt_conciliacion_solicitudes WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function gct_count(mixed $v):int{$n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000000]]);if($n===false)throw new DomainException('El conteo debe ser entero, entre 0 y 10,000,000.');return (int)$n;}
function gct_note(mixed $v,int $min=12):string{$v=trim((string)$v);if(mb_strlen($v)<$min||mb_strlen($v)>500)throw new DomainException('Observación de '.$min.' a 500 caracteres requerida.');return $v;}
function gct_event(PDO $db,int $id,int $uid,string $action,?int $before,?int $after,string $note):void{
 $s=$db->prepare('INSERT INTO gyd_tt_conciliacion_eventos(solicitud_id,usuario_id,accion,existencia_antes,existencia_despues,detalle) VALUES(?,?,?,?,?,?)');$s->execute([$id,$uid,$action,$before,$after,mb_substr($note,0,500)]);
}
function gct_fresh(array $r):bool{$t=strtotime((string)$r['creado_en']);return $t!==false&&$t>=time()-86400;}
/** Flujo directo G&D: guardar conteo -> confirmar/aplicar (sin segundo autorizador). */
function gct_action(PDO $db,array $user,string $act,array $d):string{
 gct_guard($db);
 $uid=(int)($user['id']??0);
 if(!gct_admin($user)||$uid<1)throw new DomainException('Acceso restringido a Administración y Dirección.');
 if($act!=='contar'&&!in_array($act,['aplicar','cancelar'],true))throw new DomainException('La autorización independiente ya no se utiliza. Actualiza la página.');
 if($act==='contar'){
  $sid=(int)($d['sucursal']??0);$pid=(int)($d['producto']??0);
  $target=gct_count($d['cantidad']??null);$note=gct_note($d['nota']??'');
  if(!gct_sid($sid))throw new DomainException('Sucursal no válida.');
  $s=$db->prepare('SELECT id FROM productos WHERE id=? AND estado=1');$s->execute([$pid]);
  if(!$s->fetchColumn())throw new DomainException('El producto debe estar activo.');
 }else{
  $id=(int)($d['id']??0);
  if($id<1||!($initial=gct_row($db,$id)))throw new DomainException('No existe el conteo.');
  $sid=(int)$initial['sucursal_id'];$pid=(int)$initial['producto_id'];
  if(!gct_sid($sid))throw new DomainException('Sucursal inválida en conteo.');
  if($act==='aplicar'&&($d['confirmado']??'')!=='SI')throw new DomainException('Debes confirmar expresamente el ajuste antes de aplicarlo.');
 }
 // Mismo candado que utiliza POS, para serializar actualizaciones de este producto.
 $key='gyd_pos_pool_tt_'.$pid;$s=$db->prepare('SELECT GET_LOCK(?,8)');$s->execute([$key]);
 if((int)$s->fetchColumn()!==1)throw new DomainException('El producto tiene otro movimiento activo. Intenta nuevamente.');
 try{
  $db->beginTransaction();gct_guard($db);
  if($act==='contar'){
   $s=$db->prepare("SELECT id FROM gyd_tt_conciliacion_solicitudes WHERE sucursal_id=? AND producto_id=? AND estado IN ('CONTEO','SOLICITADO','AUTORIZADO') LIMIT 1 FOR UPDATE");
   $s->execute([$sid,$pid]);if($s->fetchColumn()!==false)throw new DomainException('Ya existe un conteo abierto para este producto. Finalízalo o cancélalo primero.');
   $old=gct_stock($db,$sid,$pid,true);
   $s=$db->prepare("INSERT INTO gyd_tt_conciliacion_solicitudes(sucursal_id,producto_id,estado,existencia_base,cantidad_contada,observacion,solicitado_por) VALUES(?,?,'CONTEO',?,?,?,?)");
   $s->execute([$sid,$pid,$old,$target,$note,$uid]);
   $id=(int)$db->lastInsertId();gct_event($db,$id,$uid,'CONTEO',$old,$target,$note);
   $msg='Conteo #'.$id.' registrado. No se ha modificado el inventario; puedes revisarlo y aplicar directamente.';
  }else{
   $a=gct_row($db,$id,true);
   if(!$a||(int)$a['sucursal_id']!==$sid||(int)$a['producto_id']!==$pid)throw new DomainException('El conteo cambió de identidad.');
   $state=(string)$a['estado'];$old=$a['existencia_base']===null?null:(int)$a['existencia_base'];$target=(int)$a['cantidad_contada'];
   $stock=gct_stock($db,$sid,$pid,true);
   if($act==='cancelar'){
    if(!in_array($state,['CONTEO','SOLICITADO','AUTORIZADO'],true))throw new DomainException('No se puede cancelar un conteo finalizado.');
    $note=gct_note($d['nota']??'',8);
    $db->prepare("UPDATE gyd_tt_conciliacion_solicitudes SET estado='CANCELADO',referencia=? WHERE id=?")->execute([$note,$id]);
    gct_event($db,$id,$uid,'CANCELADO',$old,$target,$note);$msg='Conteo cancelado. Existencias intactas.';
   }elseif($act==='aplicar'){
    // Se admiten solicitudes heredadas, pero jamás se permite aplicar dos veces.
    if(!in_array($state,['CONTEO','SOLICITADO','AUTORIZADO'],true))throw new DomainException('Este conteo ya fue finalizado o aplicado. No se repetirá el ajuste.');
    if(!gct_fresh($a)||$old!==$stock)throw new DomainException('El conteo tiene más de 24 horas o la existencia cambió desde su captura. Cancela y registra un conteo actualizado.');
    if($old!==$target && !gct_audit($db))throw new DomainException('Falta la auditoría transaccional del Kardex. No se modificó ninguna existencia.');
    $folio=sprintf('CONC-TT-%06d',$id);
    if($old!==$target){
     $s=$db->prepare('SET @gyd_stock_user=?,@gyd_stock_modulo=?,@gyd_stock_folio=?,@gyd_stock_request=?');
     $s->execute([$uid,'erp_conciliacion_tt.php',$folio,bin2hex(random_bytes(12))]);
     if($old===null){
      $s=$db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES(?,?,?)');$s->execute([$sid,$pid,$target]);
     }else{
      $s=$db->prepare('UPDATE pos_existencias_farmacia SET existencia=? WHERE sucursal_id=? AND producto_id=? AND existencia=?');
      $s->execute([$target,$sid,$pid,$old]);if($s->rowCount()!==1)throw new DomainException('La existencia cambió; no se aplicó el ajuste.');
     }
    }
    $final=$old===$target?'VERIFICADO':'APLICADO';
    $db->prepare('UPDATE gyd_tt_conciliacion_solicitudes SET estado=?,aplicado_por=?,aplicado_en=NOW(),referencia=? WHERE id=?')->execute([$final,$uid,$folio,$id]);
    gct_event($db,$id,$uid,$final,$old,$target,$folio.' · Confirmado sin autorización de otra cuenta.');
    $msg=$final==='VERIFICADO'?'Conteo #'.$id.' verificado. No hubo cambios de existencia.':'Ajuste aplicado '.$folio.' en '.gct_sname($sid).'. De '.($old===null?'sin registro':$old).' a '.$target.' piezas.';
   }
  }
  $db->commit();return $msg;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 finally{
  try{$db->exec('SET @gyd_stock_user=NULL,@gyd_stock_modulo=NULL,@gyd_stock_folio=NULL,@gyd_stock_request=NULL');}catch(Throwable $e){}
  try{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$key]);}catch(Throwable $e){}
 }
}
function gct_recent(PDO $db,int $sid,int $pid=0,int $limit=100):array{
 $sql='SELECT a.*,p.codigo,p.descripcion,creator.nombre AS creador,auth.nombre AS autorizador,ap.nombre AS aplicador FROM gyd_tt_conciliacion_solicitudes a JOIN productos p ON p.id=a.producto_id LEFT JOIN usuarios creator ON creator.id=a.solicitado_por LEFT JOIN usuarios auth ON auth.id=a.autorizado_por LEFT JOIN usuarios ap ON ap.id=a.aplicado_por WHERE a.sucursal_id=?';
 $args=[$sid];if($pid){$sql.=' AND a.producto_id=?';$args[]=$pid;}$sql.=' ORDER BY a.id DESC LIMIT '.max(1,min(10000,$limit));$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function gct_events(PDO $db,int $id):array{$s=$db->prepare('SELECT e.*,u.nombre usuario FROM gyd_tt_conciliacion_eventos e LEFT JOIN usuarios u ON u.id=e.usuario_id WHERE e.solicitud_id=? ORDER BY e.id');$s->execute([$id]);return $s->fetchAll(PDO::FETCH_ASSOC);}
