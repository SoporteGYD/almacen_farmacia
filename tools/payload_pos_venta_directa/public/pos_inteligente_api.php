<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';require_once __DIR__.'/../app/config/database.php';require_once __DIR__.'/../app/helpers/pos_inteligente.php';
requireLogin();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$u=currentUser()?:[];$rol=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));$uid=(int)($u['id']??0);
function ia_response(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
 $db=(new Database())->connect();$accion=(string)($_GET['action']??'');
 if(in_array($accion,['pedir','mis_tickets','ticket'],true))ia_response(['error'=>'El POS ya no genera ni consulta tickets de Almacén. Utiliza la venta normal.'],410);
 if($accion==='alertas'){
  if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION','GERENTE','ENCARGADO','JEFE_ALMACEN','ALMACEN'],true))ia_response(['error'=>'Acceso restringido'],403);
  $almacen=(int)($u['almacen_id']??0);$sucursal=($almacen===1?1:($almacen===3?2:0));
  $sql="SELECT a.sucursal_id,a.producto_id,p.codigo,p.descripcion,a.existencia,a.minimo,s.nombre sucursal FROM erp_pos_alertas a JOIN productos p ON p.id=a.producto_id JOIN pos_sucursales s ON s.id=a.sucursal_id WHERE a.estado='ABIERTA'";
  if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){$sql.=' AND a.sucursal_id='.(int)$sucursal;}
  $sql.=' ORDER BY a.actualizada_en DESC LIMIT 40';ia_response(['alertas'=>$db->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if(!in_array($rol,['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'],true))ia_response(['error'=>'No autorizado'],403);
 require_once __DIR__.'/../app/models/PuntoVenta.php';$pos=new PuntoVenta();
 $caja=(int)($_GET['caja_id']??0);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $data=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  if(!is_array($data)||!hash_equals((string)($_SESSION['pos_csrf']??''),(string)($data['csrf']??'')))ia_response(['error'=>'Token inválido'],403);
  $caja=(int)($data['caja_id']??0);
 }
 $c=$pos->cajaAutorizada($u,$caja);$sid=(int)$c['id'];
 if($accion==='mis_tickets'){
  $st=$db->prepare("SELECT t.id,t.producto_id,t.cantidad,r.folio,r.estado,r.salida_id,rd.cantidad_surtida FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id JOIN resurtido_detalles rd ON rd.resurtido_id=r.id AND rd.producto_id=t.producto_id WHERE t.sucursal_id=? AND t.caja_id=? AND t.usuario_id=? AND t.venta_id IS NULL AND r.estado<>'CANCELADO' ORDER BY t.id DESC LIMIT 30");
  $st->execute([$sid,$caja,$uid]);ia_response(['tickets'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if($accion==='stock'){
  $pid=(int)($_GET['producto_id']??0);if($pid<1)throw new DomainException('Producto inválido.');
  $s=pi_ubicacion($db,$sid,$pid);$qty=max(1,min(100000,(int)($_GET['cantidad']??1)));
  $origen=pi_almacen($sid)===0?'FARMACIA_GENERAL':($qty>$s['general_disponible']?'INSUFICIENTE':($qty>$s['almacen_disponible']?'SIN_BODEGA':'ALMACEN'));
  ia_response(['stock'=>$s,'origen'=>$origen]);
 }
 if($accion==='ticket'){
  $tid=(int)($_GET['ticket_id']??0);
  $st=$db->prepare('SELECT t.id,t.cantidad,t.producto_id,t.venta_id,r.estado,r.folio,r.salida_id,d.cantidad_surtida FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id JOIN resurtido_detalles d ON d.resurtido_id=r.id AND d.producto_id=t.producto_id WHERE t.id=? AND t.sucursal_id=? AND t.caja_id=? AND t.usuario_id=?');
  $st->execute([$tid,$sid,$caja,$uid]);$t=$st->fetch(PDO::FETCH_ASSOC);if(!$t)throw new DomainException('Ticket no disponible para este cajero.');
  $listo=($t['estado']==='SURTIDO'&&(int)$t['salida_id']>0&&(float)$t['cantidad_surtida']>=(int)$t['cantidad']&&$t['venta_id']===null);
  ia_response(['ticket'=>$t,'listo'=>$listo]);
 }
 if($accion==='pedir'&&$_SERVER['REQUEST_METHOD']==='POST'){
  $pid=(int)($data['producto_id']??0);$qty=(int)($data['cantidad']??0);$clave=(string)($data['clave']??'');
  if($pid<=0||$qty<1||$qty>100000||!preg_match('/^[0-9a-f-]{36}$/iD',$clave))throw new DomainException('Datos de ticket inválidos.');
  $q=$db->prepare('SELECT t.id,t.sucursal_id,t.caja_id,t.producto_id,t.cantidad,r.folio,r.estado FROM erp_pos_tickets_almacen t JOIN resurtidos r ON r.id=t.resurtido_id WHERE t.clave=? AND t.usuario_id=?');
  $q->execute([$clave,$uid]);$repetido=$q->fetch(PDO::FETCH_ASSOC);
  if($repetido){
    if((int)$repetido['sucursal_id']!==$sid || (int)$repetido['caja_id']!==$caja || (int)$repetido['producto_id']!==$pid || (int)$repetido['cantidad']!==$qty)throw new DomainException('La clave ya corresponde a una solicitud diferente.');
    ia_response(['id'=>(int)$repetido['id'],'folio'=>$repetido['folio'],'estado'=>$repetido['estado'],'repetido'=>true]);
  }
  // Bloqueo breve compartido con el cobro POS para serializar reservas del mismo producto.
  $bloqueo=pi_bloquear_pos($db,$sid,$pid);
  try {
  // Doble comprobación DENTRO del candado: un reintento concurrente no creará dos tickets.
  $q->execute([$clave,$uid]);$repetido=$q->fetch(PDO::FETCH_ASSOC);
  if($repetido){
    if((int)$repetido['sucursal_id']!==$sid || (int)$repetido['caja_id']!==$caja || (int)$repetido['producto_id']!==$pid || (int)$repetido['cantidad']!==$qty)throw new DomainException('La clave ya corresponde a una solicitud diferente.');
    $respuesta=['id'=>(int)$repetido['id'],'folio'=>$repetido['folio'],'estado'=>$repetido['estado'],'repetido'=>true];
  } else {
  // Ticket desde POS para tablet en Hidalgo/Tuxtla: no depender del conteo ni del mínimo físico.
  if(pi_almacen($sid)===0)throw new DomainException('Esta sucursal no utiliza tickets de Almacén.');
  $s=pi_ubicacion($db,$sid,$pid);
  if($qty>$s['general_disponible'])throw new DomainException('No hay saldo POS disponible para reservar este ticket.');
  if($qty>$s['almacen_disponible'])throw new DomainException('Almacén no tiene piezas disponibles para este ticket. Verifica existencias y solicitudes pendientes.');
  require_once __DIR__.'/../app/models/Resurtido.php';
  $modelo=new Resurtido($db);
  // Usa la MISMA validación/reserva de existencias y la MISMA bandeja de TICKETS de verificadores.
  $folioDoc='POS-'.strtoupper(substr(str_replace('-','',$clave),0,12));
  $r=$modelo->crear(['usuario_id'=>$uid,'almacen_id'=>pi_almacen($sid),'tipo_solicitud'=>($sid===1?'TICKET':'RESURTIDO'),'folio_documento'=>$folioDoc,'observaciones'=>'Solicitud automática desde POS; caja '.$caja.'. Validar y surtir por flujo de verificadores.','productos'=>[['producto_id'=>$pid,'cantidad'=>$qty]]]);
  try{
   $db->prepare('INSERT INTO erp_pos_tickets_almacen(clave,resurtido_id,sucursal_id,caja_id,usuario_id,producto_id,cantidad) VALUES(?,?,?,?,?,?,?)')->execute([$clave,(int)$r['id'],$sid,$caja,$uid,$pid,$qty]);
  }catch(Throwable $e){error_log('Ticket creado sin vincular: resurtido '.$r['id'].' '.$e->getMessage());throw new RuntimeException('El ticket fue creado pero falta su vinculación: '.(string)$r['folio'].'. Solicita revisión antes de repetir.');}
  $respuesta=['id'=>(int)$db->lastInsertId(),'folio'=>$r['folio'],'estado'=>'PENDIENTE'];
  }
  } finally {
    pi_liberar_pos($db,$bloqueo);
  }
  ia_response($respuesta);
 }
 throw new DomainException('Operación no válida.');
}catch(Throwable $e){error_log('POS inteligente API: '.$e->getMessage());ia_response(['error'=>$e instanceof DomainException || $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage():'Error del servidor. Revisa instalación.'],422);}
