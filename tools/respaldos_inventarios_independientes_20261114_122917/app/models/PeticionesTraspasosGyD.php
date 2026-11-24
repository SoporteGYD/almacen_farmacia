<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../helpers/pos_inteligente.php';
/** Solicitudes internas, lectura de existencias y ventas. Sin efectos en stock hasta que V23 despache. */
final class PeticionesTraspasosGyD {
 private PDO $db;
 public function __construct(){ $this->db=(new Database())->connect(); }
 public function db():PDO{return $this->db;}
 private function q(string $s,array $p=[]):PDOStatement{$x=$this->db->prepare($s);$x->execute($p);return $x;}
 public static function rol(array $u):string{return strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));}
 public static function admin(array $u):bool{return in_array(self::rol($u),['ADMINISTRADOR','ADMIN','DIRECCION'],true);}
 public static function compras(array $u):bool{return self::admin($u)||self::rol($u)==='COMPRAS';}
 public static function operador(array $u):bool{return in_array(self::rol($u),['GERENTE','ENCARGADO','JEFE_ALMACEN'],true)||self::admin($u);}
 public static function permitido(array $u):bool{return self::compras($u)||self::operador($u);}
 public function sucursal(array $u):int {
  if(self::admin($u))return 0;
  $r=$this->q('SELECT sucursal_id FROM erp_pet_usuario_sucursal WHERE usuario_id=?',[(int)($u['id']??0)])->fetchColumn();
  if($r)return (int)$r;
  $a=(int)($u['almacen_id']??0);
  return $a===1?1:($a===3?2:0);
 }
 public function permitirSucursal(array $u,int $sid):bool{return self::admin($u)||($sid>0 && $this->sucursal($u)===$sid);}
 private function exigirDestino(array $u,int $sid):void {
  if(!self::admin($u)&&self::rol($u)!=='GERENTE')throw new DomainException('Solo un gerente o administrador puede enviar solicitudes.');
  if(!$this->permitirSucursal($u,$sid))throw new DomainException('Tu cuenta no está asignada a la sucursal solicitante. Pide a Administración que configure tu sucursal.');
 }
 private function exigirOrigen(array $u,int $sid):void {
  if(!self::operador($u)||!$this->permitirSucursal($u,$sid))throw new DomainException('Solo la sucursal origen puede generar las órdenes de surtido.');
 }
 public function sucursales():array{return $this->q('SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id')->fetchAll();}
 public function usuariosAsignables():array{return $this->q("SELECT u.id,u.nombre,u.rol,u.almacen_id,ps.sucursal_id FROM usuarios u LEFT JOIN erp_pet_usuario_sucursal ps ON ps.usuario_id=u.id WHERE u.rol IN ('GERENTE','ENCARGADO','JEFE_ALMACEN') ORDER BY u.nombre")->fetchAll();}
 public function asignar(array $u,int $uid,int $sid):void{
  if(!self::admin($u))throw new DomainException('Solo Administración asigna sucursales.');
  if($uid<1 || !in_array($sid,[1,2,3,4],true))throw new DomainException('Usuario o sucursal inválidos.');
  $x=$this->q('SELECT rol FROM usuarios WHERE id=?',[$uid])->fetchColumn();
  if(!in_array(strtoupper((string)$x),['GERENTE','ENCARGADO','JEFE_ALMACEN'],true))throw new DomainException('La cuenta no tiene un rol operativo asignable.');
  $this->q('INSERT INTO erp_pet_usuario_sucursal (usuario_id,sucursal_id,actualizado_por) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sucursal_id=VALUES(sucursal_id),actualizado_por=VALUES(actualizado_por)',[$uid,$sid,(int)$u['id']]);
 }
 public function notificaciones(array $u):int {
  if(self::compras($u))return (int)$this->q("SELECT COUNT(*) FROM erp_pet_cab WHERE estado='PENDIENTE_COMPRAS'")->fetchColumn();
  $s=$this->sucursal($u);if(!$s)return 0;
  return (int)$this->q("SELECT COUNT(*) FROM erp_pet_cab WHERE origen_id=? AND estado='VALIDADA'",[$s])->fetchColumn();
 }
 public function listado(array $u):array{
  $w='';$p=[];
  if(!self::compras($u)){$s=$this->sucursal($u);if($s<1)throw new DomainException('Asigna primero la sucursal de tu cuenta.');$w='WHERE c.destino_id=? OR c.origen_id=?';$p=[$s,$s];}
  return $this->q("SELECT c.*,so.nombre origen,sd.nombre destino,uc.nombre solicitante,uv.nombre validador,
    (SELECT COUNT(*) FROM erp_pet_det d WHERE d.peticion_id=c.id) renglones,
    (SELECT COUNT(*) FROM erp_pet_tr_vinculo l WHERE l.peticion_id=c.id) documentos
    FROM erp_pet_cab c JOIN pos_sucursales so ON so.id=c.origen_id JOIN pos_sucursales sd ON sd.id=c.destino_id JOIN usuarios uc ON uc.id=c.creado_por LEFT JOIN usuarios uv ON uv.id=c.validado_por $w ORDER BY c.id DESC LIMIT 150",$p)->fetchAll();
 }
 public function ver(array $u,int $id):array {
  $r=$this->q('SELECT c.*,so.nombre origen,sd.nombre destino,uc.nombre solicitante,uv.nombre validador FROM erp_pet_cab c JOIN pos_sucursales so ON so.id=c.origen_id JOIN pos_sucursales sd ON sd.id=c.destino_id JOIN usuarios uc ON uc.id=c.creado_por LEFT JOIN usuarios uv ON uv.id=c.validado_por WHERE c.id=?',[$id])->fetch();
  if(!$r)throw new DomainException('Petición no encontrada.');
  if(!self::compras($u)&&!in_array($this->sucursal($u),[(int)$r['origen_id'],(int)$r['destino_id']],true))throw new DomainException('No tienes acceso a esta petición.');
  $r['items']=$this->q('SELECT d.*,p.codigo,p.descripcion,p.codigo_barras FROM erp_pet_det d JOIN productos p ON p.id=d.producto_id WHERE d.peticion_id=? ORDER BY d.origen_tipo,p.descripcion',[$id])->fetchAll();
  $r['documentos']=$this->q('SELECT l.origen_tipo,l.traspaso_id,t.folio,t.estado FROM erp_pet_tr_vinculo l JOIN erp_traspasos t ON t.id=l.traspaso_id WHERE l.peticion_id=? ORDER BY l.origen_tipo',[$id])->fetchAll();
  $r['eventos']=$this->q('SELECT e.*,u.nombre usuario FROM erp_pet_eventos e JOIN usuarios u ON u.id=e.usuario_id WHERE e.peticion_id=? ORDER BY e.id DESC',[$id])->fetchAll();
  return $r;
 }
 private function tipo(int $sid,string $tipo):bool{return in_array($sid,[1,2],true)?in_array($tipo,['ALMACEN','FARMACIA'],true):($tipo==='UNICO'&&in_array($sid,[3,4],true));}
 private function validarPar(int $origen,int $destino,string $destinoTipo):void {
  if($origen===$destino||!in_array($origen,[1,2,3,4],true)||!$this->tipo($destino,$destinoTipo))throw new DomainException('Origen, destino o ubicación inválidos.');
  if(in_array($origen,[3,4],true)&&in_array($destino,[3,4],true))throw new DomainException('Tapachula/Toscana comparten inventario. No se permiten solicitudes entre las dos hasta configurar ubicaciones físicas.');
 }
 private function evento(int $id,int $uid,string $accion,string $texto=''):void{$this->q('INSERT INTO erp_pet_eventos (peticion_id,usuario_id,accion,mensaje) VALUES (?,?,?,?)',[$id,$uid,$accion,$texto]);}
 public function crear(array $u,array $in):int {
  $o=(int)($in['origen_id']??0);$d=(int)($in['destino_id']??0);$dt=strtoupper((string)($in['destino_tipo']??''));
  $this->validarPar($o,$d,$dt);$this->exigirDestino($u,$d);
  $items=json_decode((string)($in['items_json']??''),true);if(!is_array($items)||count($items)<1||count($items)>100)throw new DomainException('Agrega entre 1 y 100 partidas.');
  $obs=trim((string)($in['observaciones']??''));if(strlen($obs)>600)throw new DomainException('Observaciones demasiado largas.');
  $map=[];
  foreach($items as $r){if(!is_array($r))throw new DomainException('Partida inválida.');
   $pid=filter_var($r['producto_id']??null,FILTER_VALIDATE_INT);$n=filter_var($r['cantidad']??null,FILTER_VALIDATE_INT);$ot=strtoupper(trim((string)($r['origen_tipo']??'')));
   if(!$pid||$pid<1||!$n||$n<1||$n>100000||!$this->tipo($o,$ot))throw new DomainException('Cantidad, origen físico o producto inválido.');
   $clave=$pid.':'.$ot;if(isset($map[$clave]))throw new DomainException('Producto y ubicación de origen repetidos.');
   $exists=$this->q('SELECT id FROM productos WHERE id=? AND estado=1',[$pid])->fetchColumn();if(!$exists)throw new DomainException('Hay un producto inactivo o inexistente.');
   $map[$clave]=[$pid,$ot,$n];
  }
  $this->db->beginTransaction();try{
   $this->q('INSERT INTO erp_pet_cab(folio,origen_id,destino_id,destino_tipo,creado_por,observaciones) VALUES (?,?,?,?,?,?)',['TMP-'.bin2hex(random_bytes(8)),$o,$d,$dt,(int)$u['id'],$obs]);
   $id=(int)$this->db->lastInsertId();$folio='PT-'.str_pad((string)$id,7,'0',STR_PAD_LEFT);
   $this->q('UPDATE erp_pet_cab SET folio=? WHERE id=?',[$folio,$id]);
   foreach($map as [$pid,$ot,$n])$this->q('INSERT INTO erp_pet_det (peticion_id,producto_id,origen_tipo,solicitado) VALUES (?,?,?,?)',[$id,$pid,$ot,$n]);
   $this->evento($id,(int)$u['id'],'CREAR','Solicitud enviada a Compras.');$this->db->commit();return $id;
  }catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function decidir(array $u,int $id,array $aprobadas,string $respuesta,bool $rechazar=false):void {
  if(!self::compras($u))throw new DomainException('La validación pertenece a Compras.');
  $respuesta=trim($respuesta);if(strlen($respuesta)>600)throw new DomainException('Comentario demasiado largo.');
  if($rechazar&&strlen($respuesta)<5)throw new DomainException('Explica el motivo de rechazo.');
  $this->db->beginTransaction();try{
   $cab=$this->q('SELECT * FROM erp_pet_cab WHERE id=? FOR UPDATE',[$id])->fetch();if(!$cab||$cab['estado']!=='PENDIENTE_COMPRAS')throw new DomainException('Esta solicitud ya fue atendida o no existe.');
   $items=$this->q('SELECT * FROM erp_pet_det WHERE peticion_id=? FOR UPDATE',[$id])->fetchAll();$total=0;$cambios=false;
   foreach($items as $it){$key=$it['producto_id'].':'.$it['origen_tipo'];$n=$rechazar?0:filter_var($aprobadas[$key]??null,FILTER_VALIDATE_INT);
    if($n===false||$n===null||$n<0||$n>(int)$it['solicitado'])throw new DomainException('Cantidad autorizada inválida para '.$key.'.');
    if($n>0){
      $src=$this->saldo((int)$cab['origen_id'],(int)$it['producto_id']);
      $t=(string)$it['origen_tipo'];$fisico=$src[$t]??null;
      if($fisico===null||$n>(int)$fisico)throw new DomainException('No hay existencia verificada suficiente para producto #'.$it['producto_id'].' en '.$t.'. Ajusta la cantidad aprobada o registra el faltante.');
      if(in_array((int)$cab['origen_id'],[1,2],true)&&$n>(int)($src['GENERAL']??0))throw new DomainException('POS general origen insuficiente para producto #'.$it['producto_id'].'; revisa conciliación antes de aprobar.');
    }
    $total+=$n;if($n!==(int)$it['solicitado'])$cambios=true;
    $this->q('UPDATE erp_pet_det SET aprobado=? WHERE peticion_id=? AND producto_id=? AND origen_tipo=?',[$n,$id,(int)$it['producto_id'],$it['origen_tipo']]);
   }
   if(($cambios||$rechazar)&&strlen($respuesta)<5)throw new DomainException('Justifica cambios, faltantes o rechazos en el comentario de Compras.');
   $estado=$total===0?'RECHAZADA':'VALIDADA';
   $this->q('UPDATE erp_pet_cab SET estado=?,validado_por=?,validada_en=NOW(),respuesta_compras=? WHERE id=?',[$estado,(int)$u['id'],$respuesta,$id]);
   $this->evento($id,(int)$u['id'],$estado,$respuesta);$this->db->commit();
  }catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function emitir(array $u,int $id):void {
  $this->db->beginTransaction();try{
   $cab=$this->q('SELECT * FROM erp_pet_cab WHERE id=? FOR UPDATE',[$id])->fetch();if(!$cab||$cab['estado']!=='VALIDADA')throw new DomainException('Compras debe validar primero la solicitud.');
   $this->exigirOrigen($u,(int)$cab['origen_id']);
   $rows=$this->q('SELECT * FROM erp_pet_det WHERE peticion_id=? AND aprobado>0 ORDER BY origen_tipo,producto_id FOR UPDATE',[$id])->fetchAll();
   if(!$rows)throw new DomainException('No hay artículos autorizados.');$grupos=[];foreach($rows as $r)$grupos[$r['origen_tipo']][]=$r;
   foreach($grupos as $tipo=>$grupo){
    // Se generan solicitudes V23. El descuento se hará después, al confirmar el despacho desde V23.
    $this->q('INSERT INTO erp_traspasos(folio,origen_id,destino_id,origen_tipo,destino_tipo,modalidad,estado,solicitado_por,observaciones) VALUES (?,?,?,?,?,?,?,?,?)',[
     'TMP-'.bin2hex(random_bytes(8)),(int)$cab['origen_id'],(int)$cab['destino_id'],$tipo,$cab['destino_tipo'],'SOLICITUD','SOLICITADO',(int)$cab['creado_por'],'Petición '.$cab['folio'].' validada por Compras.']);
    $tid=(int)$this->db->lastInsertId();$this->q('UPDATE erp_traspasos SET folio=? WHERE id=?',['TR-'.str_pad((string)$tid,8,'0',STR_PAD_LEFT),$tid]);
    foreach($grupo as $it)$this->q('INSERT INTO erp_traspasos_detalle(traspaso_id,producto_id,solicitado) VALUES (?,?,?)',[$tid,(int)$it['producto_id'],(int)$it['aprobado']]);
    $this->q('INSERT INTO erp_traspasos_eventos(traspaso_id,usuario_id,accion,detalle) VALUES (?,?,?,?)',[$tid,(int)$u['id'],'DESDE_PETICION','Solicitud '.$cab['folio'].'; origen '.$tipo.'; validada por Compras.']);
    $this->q('INSERT INTO erp_pet_tr_vinculo(peticion_id,origen_tipo,traspaso_id) VALUES (?,?,?)',[$id,$tipo,$tid]);
   }
   $this->q("UPDATE erp_pet_cab SET estado='EMITIDA',emitido_por=?,emitida_en=NOW() WHERE id=?",[(int)$u['id'],$id]);
   $this->evento($id,(int)$u['id'],'EMITIR','Creadas '.count($grupos).' órdenes de traspaso para preparación física.');$this->db->commit();
  }catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function cancelar(array $u,int $id,string $motivo):void {
  $motivo=trim($motivo);if(strlen($motivo)<5||strlen($motivo)>600)throw new DomainException('Indica motivo de 5 a 600 caracteres.');
  $this->db->beginTransaction();try{$cab=$this->q('SELECT * FROM erp_pet_cab WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$cab||$cab['estado']!=='PENDIENTE_COMPRAS')throw new DomainException('Ya no puede cancelarse; consulta Compras.');
   if(!self::admin($u)&&(int)$cab['creado_por']!==(int)$u['id'])throw new DomainException('Solo el solicitante cancela esta petición.');
   $this->q("UPDATE erp_pet_cab SET estado='CANCELADA' WHERE id=?",[$id]);$this->evento($id,(int)$u['id'],'CANCELAR',$motivo);$this->db->commit();
  }catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 /** Saldo referencial por ubicación: nunca se suma dos veces la existencia compartida 3/4. */
 public function saldo(int $sid,int $pid):array {
  if(in_array($sid,[3,4],true)){
   if(pi_tt_activo($this->db)){$s=pi_ubicacion($this->db,$sid,$pid);return ['UNICO'=>(int)$s['general_disponible'],'advertencia'=>'Saldo compartido activo de Tapachula/Toscana.'];}
   $a=pi_ubicacion($this->db,3,$pid);$b=pi_ubicacion($this->db,4,$pid);
   return ['UNICO'=>max($a['general_disponible'],$b['general_disponible']),'advertencia'=>'Inventario compartido: saldo referencial (máximo reportado, no suma); conciliar existencias.'];
  }
  $s=pi_ubicacion($this->db,$sid,$pid);
  return ['ALMACEN'=>(int)$s['almacen_disponible'],'FARMACIA'=>$s['farmacia']===null?null:max(0,(int)$s['farmacia']),'GENERAL'=>(int)$s['general_disponible']];
 }
 public function buscar(string $q,int $o,int $d,int $pagina=1):array {
  if(strlen($q)>120)throw new DomainException('Búsqueda muy larga.');$q=trim($q);
  $pagina=max(1,min(3000,$pagina));$limit=20;$offset=($pagina-1)*$limit;
  $where='p.estado=1';$params=[];
  if($q!==''){$p='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';$where.=" AND (p.descripcion LIKE ? ESCAPE '!' OR p.codigo LIKE ? ESCAPE '!' OR p.codigo_barras LIKE ? ESCAPE '!')";$params=[$p,$p,$p];}
  $count=(int)$this->q('SELECT COUNT(*) FROM productos p WHERE '.$where,$params)->fetchColumn();
  $rows=$this->q('SELECT p.id,p.codigo,p.codigo_barras,p.descripcion FROM productos p WHERE '.$where.' ORDER BY p.descripcion,p.id LIMIT '.$limit.' OFFSET '.$offset,$params)->fetchAll();
  foreach($rows as &$r){$r['origen_saldo']=$this->saldo($o,(int)$r['id']);$r['destino_saldo']=$this->saldo($d,(int)$r['id']);}unset($r);
  return ['items'=>$rows,'total'=>$count,'pagina'=>$pagina,'paginas'=>(int)ceil($count/$limit)];
 }
 /** Ventas reales pagadas en 30 días (unidades descontadas) y demanda sugerida para 21 días. */
 public function sugerencias(int $o,int $d):array{
  $sids=in_array($d,[3,4],true)?[3,4]:[$d];$in=implode(',',array_fill(0,count($sids),'?'));
  $sql="SELECT vd.producto_id,p.codigo,p.descripcion,COALESCE(SUM(COALESCE(vd.unidades_descontadas,vd.cantidad)),0) unidades
    FROM pos_venta_detalle vd JOIN pos_ventas v ON v.id=vd.venta_id JOIN productos p ON p.id=vd.producto_id
    WHERE v.estado='PAGADA' AND v.sucursal_id IN ($in) AND v.pagada_en>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND p.estado=1
    GROUP BY vd.producto_id,p.codigo,p.descripcion HAVING unidades>0 ORDER BY unidades DESC LIMIT 120";
  $rows=$this->q($sql,$sids)->fetchAll();$out=[];
  foreach($rows as $r){$pid=(int)$r['producto_id'];$mov=(int)$r['unidades'];$dest=$this->saldo($d,$pid);$stock=(int)($dest[in_array($d,[3,4],true)?'UNICO':'GENERAL']??0);
   $prom=$mov/30;$dias=$prom>0?$stock/$prom:9999;$meta=(int)ceil($prom*21);$faltante=max(0,$meta-$stock);
   if($dias>21||$faltante<1)continue;
   $src=$this->saldo($o,$pid);
   // Excluir lo que la sucursal ya pidió en peticiones activas y está pendiente de suministro.
   $pend=(int)$this->q("SELECT COALESCE(SUM(GREATEST(COALESCE(pd.aprobado,pd.solicitado)-COALESCE((SELECT SUM(td.recibido) FROM erp_pet_tr_vinculo l JOIN erp_traspasos_detalle td ON td.traspaso_id=l.traspaso_id AND td.producto_id=pd.producto_id WHERE l.peticion_id=pd.peticion_id AND l.origen_tipo=pd.origen_tipo),0),0)),0)
       FROM erp_pet_det pd JOIN erp_pet_cab c ON c.id=pd.peticion_id WHERE c.origen_id=? AND c.destino_id IN ($in) AND c.estado IN ('PENDIENTE_COMPRAS','VALIDADA','EMITIDA') AND pd.producto_id=?",array_merge([$o],$sids,[$pid]))->fetchColumn();
   $out[]=['producto_id'=>$pid,'codigo'=>$r['codigo'],'descripcion'=>$r['descripcion'],'vendido_30d'=>$mov,'stock_destino'=>$stock,'dias_cobertura'=>round($dias,1),'sugerido'=>max(0,$faltante-$pend),'pendiente'=>$pend,'origen'=>$src];
   if(count($out)>=55)break;
  }
  usort($out,static fn($a,$b)=>($a['dias_cobertura']<=>$b['dias_cobertura']));return $out;
 }
}
