<?php
declare(strict_types=1);
/** Cierre administrativo de OC; nunca modifica productos, stock, facturas o recepción. */
function gcc_e(mixed $value):string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gcc_rol(array $u):string { return strtoupper(trim((string)($u['rol_original']??$u['rol']??''))); }
function gcc_puede_ver(array $u):bool {return (int)($u['id']??0)>0 && in_array(gcc_rol($u),['ADMIN','ADMINISTRADOR','DIRECCION','COMPRAS'],true);}
function gcc_puede_cerrar_total(array $u):bool {return gcc_puede_ver($u);}
function gcc_puede_excepcion(array $u):bool {return (int)($u['id']??0)>0 && in_array(gcc_rol($u),['ADMIN','ADMINISTRADOR','DIRECCION'],true);}
function gcc_clausura(PDO $db,int $oc):?array {
    $q=$db->prepare('SELECT * FROM erp_oc_cierres_controlados WHERE orden_id=? LIMIT 1');$q->execute([$oc]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function gcc_exigir_abierta(PDO $db,int $oc):void {
    if($oc<1)throw new RuntimeException('Folio de OC inválido.');
    $q=$db->prepare("SELECT estado FROM erp_oc_cierres_controlados WHERE orden_id=? LIMIT 1");$q->execute([$oc]);
    if($q->fetchColumn()==='CERRADA')throw new RuntimeException('La OC está cerrada administrativamente. Solicita reapertura autorizada antes de registrar entregas o facturas.');
}
/** Resultado de una partida de la conciliación de OC (sin escribir saldos). */
function gcc_validar_fila(array $r,int $entradasBodega=0):array {
    foreach(['solicitado','recibido','facturado','aceptado','aplicado','incidencia'] as $key){
        if(!isset($r[$key])||!is_numeric($r[$key])||(int)$r[$key]<0)throw new RuntimeException('Cantidades inválidas de conciliación: '.$key);
    }
    $s=(int)$r['solicitado'];$b=(int)$r['recibido'];$f=(int)$r['facturado'];$a=(int)$r['aceptado'];$ap=(int)$r['aplicado']+$entradasBodega;
    $errors=[];
    if($s<1)$errors[]='Partida sin pedido positivo';
    if($b>$s)$errors[]='Piezas buenas mayores que las solicitadas';
    if($f>$s)$errors[]='Facturación mayor que lo solicitado';
    if($a>$f)$errors[]='Aceptado excede factura';
    if($a>$b)$errors[]='Aceptado excede recepción buena';
    if($ap>$a)$errors[]='Aplicación excede mercancía aceptada';
    if($ap!==$a)$errors[]='Quedan piezas aceptadas sin aplicación de inventario';
    if($f!==$a)$errors[]='La factura aún contiene piezas sin verificar o resolver';
    if($b!==$a)$errors[]='Hay piezas buenas recibidas que no terminaron el flujo de factura';
    if(!empty($r['ajuste_invalido']))$errors[]='Ajustes previos inconsistentes';
    foreach(($r['observaciones']??[]) as $observacion)$errors[]=(string)$observacion;
    // Incidencias históricas de recepción no equivalen a existencias vendibles.
    return ['ok'=>!$errors,'errores'=>$errors,'faltante'=>max(0,$s-$b),'s'=>$s,'b'=>$b,'f'=>$f,'a'=>$a,'ap'=>$ap];
}
/** Valida los totales antes de habilitar cualquiera de las modalidades de cierre. */
function gcc_evaluar(array $ci,array $bodega=[],int $incidenciasEspeciales=0):array {
    $all=$ci['partidas']??[];$errors=[];$faltantes=0;$t=['solicitadas'=>0,'recibidas'=>0,'facturadas'=>0,'aceptadas'=>0,'aplicadas'=>0];$partidas=[];
    if(!$all)$errors[]='La orden no contiene partidas de producto';
    if(empty($ci['tiene_recepciones']))$errors[]='No existe recepción física REGISTRADA que permita verificar la entrega';
    foreach($all as $r){
      $pid=(int)($r['producto_id']??0);$line=gcc_validar_fila($r,(int)($bodega[$pid]??0));
      $faltantes+=$line['faltante'];
      foreach(['s'=>'solicitadas','b'=>'recibidas','f'=>'facturadas','a'=>'aceptadas','ap'=>'aplicadas'] as $k=>$v)$t[$v]+=$line[$k];
      foreach($line['errores'] as $e)$errors[]=(string)($r['codigo']??$pid).': '.$e;
      $partidas[]=['producto_id'=>$pid,'codigo'=>(string)($r['codigo']??$pid),'solicitado'=>$line['s'],'recibido'=>$line['b'],'faltante'=>$line['faltante']];
    }
    if(!in_array((string)($ci['orden']['estado']??''),['EMITIDA','PARCIAL'],true))$errors[]='La orden no está en estado emitido';
    $regular=!$errors && $faltantes===0 && $incidenciasEspeciales===0;
    $exception=!$errors && ($faltantes>0 || $incidenciasEspeciales>0);
    return ['total'=>$regular,'excepcion'=>$exception,'errores'=>$errors,'faltantes'=>$faltantes,'incidencias_especiales'=>$incidenciasEspeciales,'totales'=>$t,'partidas'=>$partidas];
}
/** Cuenta entradas clásicas vinculadas solo cuando existen. No confunde existencias de bodega y POS. */
function gcc_entradas_bodega(PDO $db,int $oc,string $destino):array {
    if(!in_array($destino,['HIDALGO_BODEGA','TUXTLA_BODEGA'],true))return [];
    $q=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_oc_entradas_vinculos'");$q->execute();
    if(!(int)$q->fetchColumn())throw new RuntimeException('Falta la tabla de entradas protegidas para Bodega. No se puede certificar el cierre.');
    $q=$db->prepare('SELECT producto_id,SUM(cantidad) qty FROM erp_oc_entradas_vinculos WHERE orden_id=? GROUP BY producto_id');$q->execute([$oc]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(int)$r['producto_id']]=(int)$r['qty'];return $out;
}
/** Los faltantes históricos se evalúan con el saldo de piezas buenas; estas incidencias físicas requieren decisión explícita. */
function gcc_incidencias_especiales(PDO $db,int $oc):int {
    $q=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_oc_recepcion_incidencias'");$q->execute();
    if(!(int)$q->fetchColumn())throw new RuntimeException('Falta la tabla de incidencias de recepción. No se certifica el cierre.');
    $q=$db->prepare("SELECT COALESCE(SUM(i.cantidad_piezas),0) FROM erp_oc_recepcion_incidencias i JOIN erp_oc_recepciones r ON r.id=i.recepcion_id WHERE r.orden_id=? AND r.estado='REGISTRADA' AND i.tipo IN ('DANADA','CADUCADA','EQUIVOCADA','SOBRANTE')");
    $q->execute([$oc]);return (int)$q->fetchColumn();
}
function gcc_cerrar(PDO $db,int $ocId,array $user,string $tipo,string $motivo,string $evidencia):array {
    if(!in_array($tipo,['TOTAL','CON_DIFERENCIAS'],true))throw new RuntimeException('Modalidad inválida.');
    if($tipo==='TOTAL'&&!gcc_puede_cerrar_total($user) || $tipo==='CON_DIFERENCIAS'&&!gcc_puede_excepcion($user))throw new RuntimeException('No tienes permiso para ese tipo de cierre.');
    $motivo=trim($motivo);$evidencia=trim($evidencia);
    if(mb_strlen($motivo)>500||mb_strlen($evidencia)>255)throw new RuntimeException('Observación o referencia demasiado extensa.');
    if($tipo==='CON_DIFERENCIAS' && (mb_strlen($motivo)<10||mb_strlen($evidencia)<4))throw new RuntimeException('El cierre con diferencias requiere motivo y referencia de evidencia.');
    require_once __DIR__.'/erp_conciliacion_integral.php';
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id,estado,destino FROM erp_ordenes_compra WHERE id=? FOR UPDATE');$q->execute([$ocId]);$oc=$q->fetch(PDO::FETCH_ASSOC);
        if(!$oc)throw new RuntimeException('OC no encontrada.');
        $prev=gcc_clausura($db,$ocId);
        if($prev && $prev['estado']==='CERRADA')throw new RuntimeException('Esta OC ya se encuentra cerrada.');
        $ci=ci_detalle($db,$ocId);
        if(!$ci)throw new RuntimeException('No se pudo reconstruir la conciliación.');
        $estructura=ci_estructura($db);
        if(!$estructura['ajustes'])throw new RuntimeException('Falta tabla de ajustes auditados de la conciliación. No se permite el cierre.');
        $cal=gcc_evaluar($ci,gcc_entradas_bodega($db,$ocId,(string)$oc['destino']),gcc_incidencias_especiales($db,$ocId));
        if($cal['errores'])throw new RuntimeException('No se puede cerrar: '.implode(' | ',array_slice($cal['errores'],0,5)));
        if($tipo==='TOTAL'&&!$cal['total'])throw new RuntimeException('No se permite cierre normal: existen '.$cal['faltantes'].' piezas pendientes de recepción buena.');
        if($tipo==='CON_DIFERENCIAS'&&!$cal['excepcion'])throw new RuntimeException('El cierre por diferencias requiere faltantes o incidencias físicas documentadas y el resto del flujo conciliado.');
        $tipoAcc=$tipo==='TOTAL'?'CIERRE_TOTAL':'CIERRE_CON_DIFERENCIAS';
        $t=$cal['totales'];$uid=(int)$user['id'];
        if($prev){
            $cid=(int)$prev['id'];
            $q=$db->prepare("UPDATE erp_oc_cierres_controlados SET estado='CERRADA',tipo=?,motivo=?,evidencia=?,solicitadas=?,recibidas_buenas=?,facturadas=?,aceptadas=?,aplicadas=?,faltantes_definitivas=?,incidencias_especiales=?,cerrado_por=?,cerrado_en=NOW(),reabierto_por=NULL,reabierto_en=NULL,motivo_reapertura=NULL WHERE id=? AND estado='REABIERTA'");
            $q->execute([$tipo,$motivo,$evidencia,$t['solicitadas'],$t['recibidas'],$t['facturadas'],$t['aceptadas'],$t['aplicadas'],$cal['faltantes'],$cal['incidencias_especiales'],$uid,$cid]);
            if($q->rowCount()!==1)throw new RuntimeException('La OC cambió de estado durante el cierre.');
            // No se borran snapshots anteriores: se relacionan con su evento de bitácora.
        }else{
            $q=$db->prepare("INSERT INTO erp_oc_cierres_controlados(orden_id,estado,tipo,motivo,evidencia,solicitadas,recibidas_buenas,facturadas,aceptadas,aplicadas,faltantes_definitivas,incidencias_especiales,cerrado_por) VALUES(?,'CERRADA',?,?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$ocId,$tipo,$motivo,$evidencia,$t['solicitadas'],$t['recibidas'],$t['facturadas'],$t['aceptadas'],$t['aplicadas'],$cal['faltantes'],$cal['incidencias_especiales'],$uid]);$cid=(int)$db->lastInsertId();
        }
        $log=$db->prepare('INSERT INTO erp_oc_cierres_bitacora(orden_id,cierre_id,accion,usuario_id,observaciones,evidencia) VALUES(?,?,?,?,?,?)');
        $log->execute([$ocId,$cid,$tipoAcc,$uid,$motivo?:'Conciliación completa, sin diferencias',$evidencia?:null]);
        $eventoId=(int)$db->lastInsertId();
        $part=$db->prepare('INSERT INTO erp_oc_cierres_partidas(cierre_id,bitacora_id,producto_id,codigo,solicitado,recibido_bueno,faltante_definitivo) VALUES(?,?,?,?,?,?,?)');
        foreach($cal['partidas'] as $p)$part->execute([$cid,$eventoId,$p['producto_id'],$p['codigo'],$p['solicitado'],$p['recibido'],$p['faltante']]);
        $db->commit();return $cal;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function gcc_reabrir(PDO $db,int $ocId,array $user,string $motivo):void {
    if(!gcc_puede_excepcion($user))throw new RuntimeException('La reapertura está reservada a Administración.');
    $motivo=trim($motivo);if(mb_strlen($motivo)<10||mb_strlen($motivo)>500)throw new RuntimeException('Justifica la reapertura con al menos 10 caracteres.');
    $db->beginTransaction();
    try{
      $q=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');$q->execute([$ocId]);if(!$q->fetchColumn())throw new RuntimeException('La orden ya no existe.');
      $prev=gcc_clausura($db,$ocId);if(!$prev||$prev['estado']!=='CERRADA')throw new RuntimeException('La orden no está cerrada.');
      $cid=(int)$prev['id'];$uid=(int)$user['id'];
      $q=$db->prepare("UPDATE erp_oc_cierres_controlados SET estado='REABIERTA',reabierto_por=?,reabierto_en=NOW(),motivo_reapertura=? WHERE id=? AND estado='CERRADA'");$q->execute([$uid,$motivo,$cid]);
      if($q->rowCount()!==1)throw new RuntimeException('La orden cambió de estado.');
      $db->prepare("INSERT INTO erp_oc_cierres_bitacora(orden_id,cierre_id,accion,usuario_id,observaciones) VALUES(?,?,'REAPERTURA',?,?)")->execute([$ocId,$cid,$uid,$motivo]);
      $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
