<?php
declare(strict_types=1);
/** G&D: gestión documental de alertas de Compras. No escribe productos, precios, OC, facturas ni stock. */
require_once __DIR__.'/erp_alertas_compras.php';
// GYD_AUTO_ALERTAS_20261026
require_once __DIR__.'/erp_compras_alertas_auto.php';
function gdca_role(array $u):string { return strtoupper(trim((string)($u['rol_original']??$u['rol']??''))); }
function gdca_authorized(array $u):bool { return (int)($u['id']??0)>0 && in_array(gdca_role($u),['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true); }
function gdca_h(mixed $x):string { return htmlspecialchars((string)($x??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gdca_tables_ok(PDO $db):bool {return gd_cp_table($db,'erp_compras_alerta_casos')&&gd_cp_table($db,'erp_compras_alerta_historial')&&gd_cp_table($db,'erp_compras_alerta_config');}
function gdca_filter_defaults():array {
    $f=gd_ac_filters([]);
    // Parámetros canónicos: las preferencias personales de filtros no generan incidentes diferentes.
    $f['desde']=(new DateTimeImmutable('today'))->modify('-12 months')->format('Y-m-d');
    $f['hasta']=(new DateTimeImmutable('today'))->format('Y-m-d');
    $f['vigencia']=90;$f['q']='';$f['proveedor']=0;$f['sucursal']=0;$f['producto_id']=0;
    $f['subida']=20.;$f['margen']=15.;$f['ahorro']=8.;$f['pagina']=1;
    return $f;
}
function gdca_fingerprint(array $event,int $branch):string {
    // Facturas nuevas o cambios significativos de precio crean una incidencia nueva.
    $type=(string)$event['tipo'];
    $price=in_array($type,['SUBIDA','OPORTUNIDAD'],true)?'no-aplica':($event['precio']===null?'sin-precio':number_format((float)$event['precio'],4,'.',''));
    $components=['GDCA1',$type,(string)(int)$event['producto_id'],(string)$branch,
      (string)(int)($event['proveedor_id']??0),(string)(int)($event['factura_id']??0),
      number_format((float)($event['costo']??0),4,'.',''),$price];
    return hash('sha256',implode('|',$components));
}
function gdca_scan(PDO $db):array {
    foreach(['erp_facturas','erp_facturas_detalle','productos','proveedores'] as $table)
       if(!gd_cp_table($db,$table))throw new RuntimeException('Falta tabla para análisis: '.$table);
    $hasPos=gd_cp_table($db,'pos_precios')&&gd_cp_table($db,'pos_sucursales');
    $f=gdca_filter_defaults();
    [$sql,$args]=gd_cp_query($f,false);
    $at=strripos($sql,' ORDER BY ');
    if($at===false)throw new RuntimeException('Consulta de facturas incompatible.');
    $sql=substr($sql,0,$at)." AND f.estado IN ('REGISTRADA','EN_REVISION','VERIFICADA','CON_INCIDENCIAS')".substr($sql,$at);
    $grouped=gd_cp_group(gd_cp_fetch($db,$sql,$args),90);
    $branches=[0=>'Precio base'];$branchPrices=[];
    if($hasPos){
       $names=$db->query('SELECT id,nombre FROM pos_sucursales ORDER BY id LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
       foreach($names as $n)$branches[(int)$n['id']]=(string)$n['nombre'];
       $pids=array_keys($grouped);
       // Precios por sucursal. No sustituimos un precio ausente por el precio base.
       if($pids){
          foreach(array_chunk($pids,600) as $chunk){
             $in=implode(',',array_fill(0,count($chunk),'?'));
             $st=$db->prepare("SELECT producto_id,sucursal_id,precio_publico FROM pos_precios WHERE producto_id IN ($in)");
             $st->execute(array_map('intval',$chunk));
             foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $branchPrices[(int)$r['sucursal_id']][(int)$r['producto_id']]=gd_cp_price($r['precio_publico']);
          }
       }
    }
    $result=[];
    foreach($branches as $branch=>$name){
      $subset=$grouped;
      if($branch!==0)foreach($subset as $pid=>&$p){$p['precio']=$branchPrices[$branch][$pid]??null;}
      unset($p);
      foreach(gd_ac_build($subset,$f) as $event){
         if($branch!==0&&in_array($event['tipo'],['SUBIDA','OPORTUNIDAD'],true))continue;
         $event['sucursal_id']=$branch;$event['sucursal_nombre']=$name;
         $event['huella']=gdca_fingerprint($event,$branch);
         $result[$event['huella']]=$event;
      }
    }
    return array_values($result);
}
/** Ejecutar como máximo cada 15 minutos al visitar Compras/Notificaciones; no hay cron ni push. */
function gdca_sync(PDO $db,bool $force=false):array {
    if(!gdca_tables_ok($db))throw new RuntimeException('Instala primero las tablas de seguimiento de alertas.');
    $lockName='gyd_compras_alertas_sync_v1';
    $lock=$db->prepare('SELECT GET_LOCK(?, 0)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)return ['estado'=>'ocupado','nuevas'=>0,'total'=>0];
    try{
      $st=$db->prepare("SELECT valor FROM erp_compras_alerta_config WHERE llave='ultima_sync' LIMIT 1");$st->execute();
      $last=(string)($st->fetchColumn()?:'');
      if(!$force && $last!=='' && strtotime($last)!==false && time()-strtotime($last)<900)
           return ['estado'=>'vigente','ultima'=>$last,'nuevas'=>0,'total'=>0];
      $events=gdca_scan($db);$new=0;
      // Adquirir todas las lecturas antes de la transacción para evitar lecturas prolongadas bloqueadas.
      $db->beginTransaction();
      try{
         $db->exec('UPDATE erp_compras_alerta_casos SET vigente=0 WHERE vigente=1');
         $st=$db->prepare('INSERT INTO erp_compras_alerta_casos
            (huella,producto_id,codigo,descripcion,tipo,nivel,sucursal_id,sucursal_nombre,proveedor_id,proveedor,
             factura_id,factura_numero,fecha_factura,costo,precio,variacion,descripcion_alerta,vigente,creado_en,detectado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())
            ON DUPLICATE KEY UPDATE vigente=1,detectado_en=NOW(),descripcion_alerta=VALUES(descripcion_alerta),nivel=VALUES(nivel)');
         foreach($events as $a){
           $st->execute([$a['huella'],(int)$a['producto_id'],(string)$a['codigo'],(string)$a['descripcion'],
             (string)$a['tipo'],(string)$a['nivel'],(int)$a['sucursal_id'],(string)$a['sucursal_nombre'],
             (int)($a['proveedor_id']??0),(string)$a['proveedor'],(int)($a['factura_id']??0),(string)$a['factura'],
             ($a['fecha']??'')?:null,$a['costo'],$a['precio'],$a['variacion'],(string)$a['texto']]);
           if($st->rowCount()===1)$new++;
         }
         // GYD_AUTO_ALERTAS_SINCRONIZAR: cerrar verificadas o reabrir problemas recurrentes.
         $autoStats=gdca_auto_sincronizar($db,$events);
         $db->exec("INSERT INTO erp_compras_alerta_config(llave,valor) VALUES ('ultima_sync',NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
         $db->commit();
      }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
      return ['estado'=>'actualizado','nuevas'=>$new,'total'=>count($events),'ultima'=>date('Y-m-d H:i:s')];
    }finally{
       try{$unlock=$db->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$lockName]);}catch(Throwable $ignored){}
    }
}
function gdca_compras_users(PDO $db):array {
    return $db->query("SELECT id,nombre FROM usuarios WHERE UPPER(rol)='COMPRAS' AND estado=1 ORDER BY nombre,id LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
}
function gdca_edit_case(PDO $db,array $actor,int $caseId,string $status,int $assignee,string $note):void {
  if(!gdca_authorized($actor))throw new RuntimeException('Sin permisos para seguimiento de alertas.');
  if(!in_array($status,['PENDIENTE','EN_REVISION','ATENDIDA','DESCARTADA'],true))throw new InvalidArgumentException('Estado no permitido.');
  $note=trim($note);if(strlen($note)>1800)throw new InvalidArgumentException('La observación supera 1800 bytes.');
  if($assignee>0){$q=$db->prepare("SELECT COUNT(*) FROM usuarios WHERE id=? AND UPPER(rol)='COMPRAS' AND estado=1");$q->execute([$assignee]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Asigna únicamente a un usuario activo de COMPRAS.');}
  $db->beginTransaction();
  try{
    $q=$db->prepare('SELECT id,estado,asignado_a FROM erp_compras_alerta_casos WHERE id=? FOR UPDATE');$q->execute([$caseId]);
    $old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)throw new InvalidArgumentException('La incidencia ya no existe.');
    $prev=(string)$old['estado'];$oldAssignee=(int)($old['asignado_a']??0);
    $stateChanged=$status!==$prev;$assigneeChanged=$assignee!==$oldAssignee;
    if(($stateChanged&&in_array($status,['ATENDIDA','DESCARTADA'],true))&&$note==='')
      throw new InvalidArgumentException('Para atender o descartar una alerta debes indicar el motivo.');
    if(!$stateChanged&&!$assigneeChanged&&$note==='')throw new InvalidArgumentException('No hay cambios ni observaciones que guardar.');
    $u=$db->prepare('UPDATE erp_compras_alerta_casos SET estado=?,asignado_a=?,cerrado_en=CASE WHEN ? IN (\'ATENDIDA\',\'DESCARTADA\') THEN COALESCE(cerrado_en,NOW()) ELSE NULL END,actualizado_en=NOW() WHERE id=?');
    $u->execute([$status,$assignee?:null,$status,$caseId]);
    $action=$stateChanged?'CAMBIO_ESTADO':($assigneeChanged?'ASIGNACION':'OBSERVACION');
    $s=$db->prepare('INSERT INTO erp_compras_alerta_historial(caso_id,usuario_id,usuario_nombre,accion,estado_anterior,estado_nuevo,asignado_anterior,asignado_nuevo,observacion,creado_en) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
    $s->execute([$caseId,(int)$actor['id'],(string)($actor['nombre']??$actor['usuario']??'Usuario'),$action,$prev,$status,$oldAssignee?:null,$assignee?:null,$note]);
    $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Esta función es llamada SOLO por el centro de notificaciones para los perfiles autorizados. */
function gdca_notification_items(PDO $db,array $user):array {
    if(!gdca_authorized($user) || !gdca_tables_ok($db))return [];
    try {gdca_sync($db,false);}catch(Throwable $e){error_log('G&D seguimiento auto-sync: '.$e->getMessage());}
    $isAdmin=gdca_role($user)!=='COMPRAS';
    $sql="SELECT id,codigo,descripcion,tipo,nivel,sucursal_nombre,proveedor,estado,asignado_a,creado_en
           FROM erp_compras_alerta_casos WHERE vigente=1 AND estado IN ('PENDIENTE','EN_REVISION')";
    if(!$isAdmin)$sql.=' AND (asignado_a IS NULL OR asignado_a=:uid)';
    $sql.=" ORDER BY CASE nivel WHEN 'ALTA' THEN 0 ELSE 1 END, creado_en DESC,id DESC LIMIT 150";
    $q=$db->prepare($sql);if(!$isAdmin)$q->bindValue(':uid',(int)$user['id'],PDO::PARAM_INT);
    $q->execute();$items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $id=(int)$r['id'];
        $items[]=['key'=>'ALERTA_COMPRA:'.$id,'tipo'=>'ALERTA_COMPRA','source_id'=>$id,
          'area'=>'Compras','titulo'=>gd_ac_type_name((string)$r['tipo']).' · '.(string)$r['codigo'],
          'detalle'=>(string)$r['descripcion'].' · '.(string)$r['sucursal_nombre'].' · '.(string)$r['proveedor'],
          'estado'=>$r['estado']==='EN_REVISION'?'En revisión':'Pendiente',
          'prioridad'=>$r['nivel']==='ALTA'?'rojo':'amarillo','pendiente'=>true,
          'url'=>'erp_compras_alerta_resolver.php?caso='.$id,'fecha'=>(string)$r['creado_en']];
    }
    return $items;
}
