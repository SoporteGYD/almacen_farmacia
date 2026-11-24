<?php
declare(strict_types=1);
/** Alertas derivadas de operaciones POS. No modifica caja, ventas ni inventario. */
function gag_h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gag_q(PDO $db,string $sql,array $params=[]):array{$s=$db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function gag_exists(PDO $db,string $table):bool{return (bool)gag_q($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$table]);}
function gag_admin(array $user):bool{
 $r=strtoupper(trim((string)($user['rol_original']??$user['rol']??'')));
 return in_array($r,['ADMINISTRADOR','ADMIN','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gag_role(array $user):bool{return (int)($user['id']??0)>0&&(gag_admin($user)||strtoupper((string)($user['rol_original']??$user['rol']??''))==='GERENTE');}
/** Los gerentes solo ven sucursales con asignación POS demostrable. Sin asignación se deniega. */
function gag_scope(PDO $db,array $user):?array {
 if(!gag_role($user))throw new DomainException('No tienes acceso al Centro de Alertas Gerenciales.');
 if(gag_admin($user))return null;
 $ids=[];
 if(gag_exists($db,'pos_usuario_asignaciones')){
  foreach(gag_q($db,'SELECT DISTINCT sucursal_id FROM pos_usuario_asignaciones WHERE usuario_id=?',[(int)$user['id']]) as $r){$ids[]=(int)$r['sucursal_id'];}
 }
 if(isset($user['sucursal_id']) && (int)$user['sucursal_id']>0)$ids[]=(int)$user['sucursal_id'];
 $ids=array_values(array_unique(array_filter($ids,static fn($i)=>$i>0)));
 if(!$ids)throw new DomainException('Este gerente no tiene una sucursal POS vinculada. Solicita al administrador que asigne su sucursal antes de consultar alertas.');
 return $ids;
}
function gag_allowed(?array $scope,int $sid,int $selected):bool{
 return ($selected===0||$selected===$sid)&&($scope===null||in_array($sid,$scope,true));
}
function gag_event(string $kind,int $id,int $branch,string $branchName,string $title,string $info,string $date,string $severity,bool $canAck=true,string $url=''):array{
 $key=$kind.':'.$id;
 return ['clave'=>$key,'tipo'=>$kind,'id'=>$id,'sucursal_id'=>$branch,'sucursal'=>$branchName,'titulo'=>$title,'detalle'=>$info,'fecha'=>$date,'nivel'=>$severity,'atendible'=>$canAck,'url'=>$url,'atendida'=>false,'atendida_por'=>'','atendida_en'=>''];
}
function gag_money(mixed $n):string{return '$'.number_format((float)$n,2,'.',',');}
function gag_money_cent(int $n):string{return '$'.number_format($n/100,2,'.',',');}
function gag_timecut(int $days):string{return (new DateTimeImmutable('today'))->modify('-'.max(1,$days-1).' days')->format('Y-m-d 00:00:00');}
/** Requiere una base de pruebas (guardia adicional, no sustituye respaldos). */
function gag_testdb(PDO $db):void{
 $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if($name!=='prueba')throw new DomainException('Este módulo solo está habilitado en la base de pruebas "prueba".');
}
function gag_feed(PDO $db,array $user,int $sucursal=0,int $dias=7):array{
 gag_testdb($db);$scope=gag_scope($db,$user);
 if($dias<1||$dias>30)throw new DomainException('Periodo permitido: 1 a 30 días.');
 if($sucursal<0)throw new DomainException('Sucursal inválida.');
 if($sucursal>0&&$scope!==null&&!in_array($sucursal,$scope,true))throw new DomainException('La sucursal no está autorizada para esta cuenta.');
 foreach(['pos_sucursales','pos_cajas','pos_turnos'] as $table)if(!gag_exists($db,$table))throw new RuntimeException('Falta tabla obligatoria: '.$table);
 $branches=gag_q($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY nombre');
 $branches=array_values(array_filter($branches,fn($r)=>$scope===null||in_array((int)$r['id'],$scope,true)));
 if($sucursal>0&&!in_array($sucursal,array_map(static fn($b)=>(int)$b['id'],$branches),true))throw new DomainException('Sucursal no disponible.');
 $from=gag_timecut($dias);$events=[];$avisos=[];
 $boxes=gag_q($db,"SELECT t.id,t.caja_id,t.usuario_apertura_id,t.abierto_en,c.nombre caja,c.sucursal_id,s.nombre sucursal
  FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id
  WHERE t.estado='ABIERTO' ORDER BY t.id DESC LIMIT 200");
 $cashAvailable=is_file(__DIR__.'/gyd_retiros_directos.php');
 if($cashAvailable){require_once __DIR__.'/gyd_retiros_directos.php';$cashAvailable=function_exists('gd_cash_totals');}
 if(!$cashAvailable)$avisos[]='No se encontró el calculador vigente del efectivo POS: las alertas del límite de $3,000 están deshabilitadas para evitar saldos incorrectos.';
 foreach($boxes as $box){
  $sid=(int)$box['sucursal_id'];if(!gag_allowed($scope,$sid,$sucursal))continue;
  $tid=(int)$box['id'];$branch=(string)$box['sucursal'];$caja=(string)$box['caja'];
  if($cashAvailable){
   try{
    $cash=gd_cash_totals($db,$tid);$c=(int)$cash['esperado'];
    if($c>300000)$events[]=gag_event('LIMITE',$tid,$sid,$branch,'Retiro obligatorio · '.$caja,
      'Efectivo esperado: '.gag_money_cent($c).' (máximo $3,000.00). No se puede iniciar otra venta hasta efectuar el retiro.',
      (string)$box['abierto_en'],'critica',false,'pos.php');
   }catch(Throwable $e){error_log('Alertas POS / efectivo turno '.$tid.': '.$e->getMessage());$avisos[]='No se pudo verificar el efectivo de una caja abierta; revisa el cálculo en el POS.';}
  }
  $hours=(new DateTimeImmutable((string)$box['abierto_en']))->diff(new DateTimeImmutable())->days*24;
  if((new DateTimeImmutable((string)$box['abierto_en']))->getTimestamp()<=time()-12*3600){
   $events[]=gag_event('TURNO',$tid,$sid,$branch,'Turno abierto por más de 12 horas · '.$caja,
      'Apertura: '.(string)$box['abierto_en'].'. Revisar si corresponde realizar el corte.',(string)$box['abierto_en'],'media',true,'erp_auditoria_cajas_v12.php');
  }
 }
 if(count($boxes)===200)$avisos[]='Hay 200 o más turnos abiertos; el resumen puede ser parcial. Revisa el control de cajas.';
 $cut=gag_q($db,"SELECT t.id,t.cerrado_en,t.diferencia_efectivo,c.nombre caja,c.sucursal_id,s.nombre sucursal
  FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id
  WHERE t.estado='CERRADO' AND t.cerrado_en>=? AND t.diferencia_efectivo<>0
  ORDER BY t.cerrado_en DESC,t.id DESC LIMIT 301",[$from]);
 foreach(array_slice($cut,0,300) as $r){
  $sid=(int)$r['sucursal_id'];if(!gag_allowed($scope,$sid,$sucursal))continue;
  $d=(float)$r['diferencia_efectivo'];$isMissing=$d<0;
  $events[]=gag_event('CORTE',(int)$r['id'],$sid,(string)$r['sucursal'],($isMissing?'Faltante':'Sobrante').' de caja · '.(string)$r['caja'],
   ($isMissing?'Faltante: ':'Sobrante: ').gag_money(abs($d)).'. Corte #'.(int)$r['id'],(string)$r['cerrado_en'],$isMissing?'critica':'alta',true,'erp_auditoria_cajas_v12.php');
 }
 if(count($cut)>300)$avisos[]='Más de 300 cortes con diferencias en el periodo. Acorta el intervalo para revisarlos todos.';
 if(gag_exists($db,'pos_reversiones')&&gag_exists($db,'pos_ventas')){
  $rev=gag_q($db,"SELECT r.id,r.tipo,r.importe,r.creado_en,v.sucursal_id,s.nombre sucursal
    FROM pos_reversiones r JOIN pos_ventas v ON v.id=r.venta_id
    JOIN pos_sucursales s ON s.id=v.sucursal_id
    WHERE r.estado='APLICADA' AND r.tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL') AND r.creado_en>=?
    ORDER BY r.creado_en DESC,r.id DESC LIMIT 301",[$from]);
  foreach(array_slice($rev,0,300) as $r){
   $sid=(int)$r['sucursal_id'];if(!gag_allowed($scope,$sid,$sucursal))continue;
   $type=(string)$r['tipo'];$events[]=gag_event('REVERSION',(int)$r['id'],$sid,(string)$r['sucursal'],$type==='CANCELACION_TOTAL'?'Cancelación aplicada':'Devolución aplicada',
    'Movimiento #'.(int)$r['id'].' · Importe '.gag_money($r['importe']),(string)$r['creado_en'],'media',true,'erp_pos_reversiones.php');
  }
  if(count($rev)>300)$avisos[]='Más de 300 devoluciones/cancelaciones en el periodo; se muestra una parte.';
 }else{$avisos[]='Sin tabla de reversiones: no se mostrarán alertas de cancelación y devolución.';}
 // Los intentos de clave no tienen sucursal: SOLO Dirección/Administración, ámbito GLOBAL.
 if($scope===null&&$sucursal===0){
  foreach([['gyd_cortes_intentos','exitoso','CORTE_CLAVE','Corte'],['gyd_retiros_intentos_clave','correcto','RETIRO_CLAVE','Retiro'],['gyd_pos_relevos_intentos','exitoso','RELEVO_CLAVE','Relevo']] as $cfg){
   [$table,$status,$kind,$label]=$cfg;
   if(!gag_exists($db,$table)){$avisos[]='Sin registro de intentos para '.$label.'; la información de seguridad puede estar incompleta.';continue;}
   $fail=gag_q($db,"SELECT id,creado_en FROM `".$table."` WHERE `".$status."`=0 AND creado_en>=? ORDER BY id DESC LIMIT 101",[$from]);
   foreach(array_slice($fail,0,100) as $r)$events[]=gag_event($kind,(int)$r['id'],0,'Global','Intento de contraseña rechazado · '.$label,
    'El intento fue rechazado. Este registro no identifica una sucursal y no almacena contraseñas.',(string)$r['creado_en'],'alta',true,'erp_auditoria_cajas_v12.php');
   if(count($fail)>100)$avisos[]='Más de 100 intentos rechazados para '.$label.'; reduce el periodo.';
  }
 }
 $ids=array_map(static fn($e)=>$e['clave'],$events);
 if($ids&&gag_exists($db,'gyd_alertas_gerenciales_atencion')){
  $marks=gag_q($db,'SELECT a.clave,a.atendido_en,u.nombre autor FROM gyd_alertas_gerenciales_atencion a LEFT JOIN usuarios u ON u.id=a.atendido_por WHERE a.clave IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
  $by=[];foreach($marks as $m)$by[$m['clave']]=$m;
  foreach($events as &$e)if(isset($by[$e['clave']])){$e['atendida']=true;$e['atendida_por']=(string)($by[$e['clave']]['autor']??'Usuario');$e['atendida_en']=(string)$by[$e['clave']]['atendido_en'];}unset($e);
 }
 usort($events,static function($a,$b){$priority=['critica'=>4,'alta'=>3,'media'=>2];return ($b['atendida']<=>$a['atendida'])*-1 ?: (($priority[$b['nivel']]??0)<=>($priority[$a['nivel']]??0)) ?: strcmp((string)$b['fecha'],(string)$a['fecha']);});
 $active=count(array_filter($events,static fn($x)=>!$x['atendida']));
 return ['alertas'=>$events,'pendientes'=>$active,'atendidas'=>count($events)-$active,'sucursales'=>$branches,'avisos'=>array_values(array_unique($avisos)),'consultado_en'=>date('Y-m-d H:i:s')];
}
/** Confirma en servidor que la clave visible corresponde al ámbito autorizado antes de escribir. */
function gag_ack(PDO $db,array $user,string $key,int $branch,int $days):array{
 if(strlen($key)>90||!preg_match('/^[A-Z_]+:[0-9]+$/D',$key))throw new DomainException('Identificador de alerta inválido.');
 if(!gag_exists($db,'gyd_alertas_gerenciales_atencion'))throw new RuntimeException('Falta instalar la tabla del estado de las alertas.');
 $feed=gag_feed($db,$user,$branch,$days);
 $match=null;foreach($feed['alertas'] as $e)if(hash_equals($e['clave'],$key)){$match=$e;break;}
 if(!$match)throw new DomainException('La alerta ya no está disponible o no tienes permiso para atenderla.');
 if(!$match['atendible'])throw new DomainException('Esta alerta se resuelve únicamente al realizar la operación correspondiente.');
 $s=$db->prepare('INSERT INTO gyd_alertas_gerenciales_atencion (clave,atendido_por,atendido_en) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE clave=clave');
 $s->execute([$key,(int)$user['id']]);
 return ['ok'=>true,'clave'=>$key];
}
