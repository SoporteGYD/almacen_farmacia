<?php
declare(strict_types=1);
/** Cambios de precios por sucursal. NO modifica costo ni existencias. */
function cp_html($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function cp_autorizado(array $u):bool{
    $r=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return in_array($r,['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS'],true);
}
function cp_decimal($v,int $maxDecimals=2,bool $mayorCero=true):string{
    if(!is_scalar($v))throw new DomainException('Importe inválido.');
    $s=trim((string)$v);
    if(!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,'.$maxDecimals.'})?$/D',$s))throw new DomainException('Captura importes positivos con hasta '.$maxDecimals.' decimales, sin comas.');
    $n=(float)$s;
    if(($mayorCero&&$n<=0)||$n>99999999.99)throw new DomainException('Precio fuera de rango.');
    return number_format($n,2,'.','');
}
function cp_monto(float $importe):string{
    if(!is_finite($importe)||$importe<=0||$importe>99999999.99)throw new DomainException('El precio calculado no es válido.');
    $v=number_format(round($importe,2,PHP_ROUND_HALF_UP),2,'.','');
    if((float)$v<=0)throw new DomainException('El precio calculado debe ser positivo.');
    return $v;
}
function cp_precio_sugerido(string $costo,string $modo,string $margen):string{
    if(!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D',$costo))throw new DomainException('Costo no válido en factura.');
    $c=(float)$costo;
    if(!preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D',trim($margen)))throw new DomainException('Porcentaje inválido (0 a 99.99).');
    $m=(float)$margen;
    if($m>99.99)throw new DomainException('El porcentaje supera el máximo.');
    if($modo==='MARK_UP')return cp_monto($c*(1+$m/100));
    if($modo==='MARK_DOWN')return cp_monto($c/(1-$m/100));
    throw new DomainException('Método de cálculo inválido.');
}
function cp_factura_producto(PDO $db,int $facturaId,int $detalleId):array{
    $st=$db->prepare("SELECT d.id AS detalle_id,d.producto_id,d.costo_unitario AS costo_nuevo,
       d.descripcion_snapshot,d.codigo_snapshot,f.folio_interno,f.numero_factura,f.sucursal,
       p.codigo,p.descripcion,COALESCE(NULLIF(p.costo_ultimo,0),p.precio_compra,0) AS costo_anterior,
       COALESCE((SELECT SUM(r.cantidad) FROM erp_facturas_revision_detalle r
          WHERE r.factura_detalle_id=d.id AND r.tipo='ACEPTADA'),0) AS aceptadas
       FROM erp_facturas_detalle d JOIN erp_facturas f ON f.id=d.factura_id
       JOIN productos p ON p.id=d.producto_id AND p.estado=1
       WHERE d.factura_id=? AND d.id=? LIMIT 1");
    $st->execute([$facturaId,$detalleId]);$r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r||(int)$r['aceptadas']<1)throw new DomainException('Solo se pueden revisar precios de partidas con piezas físicamente aceptadas.');
    return $r;
}
function cp_sucursales_precios(PDO $db,int $producto):array{
    $st=$db->prepare('SELECT s.id,s.nombre,pp.precio_publico FROM pos_sucursales s LEFT JOIN pos_precios pp ON pp.sucursal_id=s.id AND pp.producto_id=? WHERE s.activo=1 ORDER BY s.nombre');
    $st->execute([$producto]);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cp_guardar(PDO $db,array $usr,array $post):array{
    if(!cp_autorizado($usr)||($usr['id']??0)<1)throw new DomainException('No tienes permisos para modificar precios.');
    $uid=(int)$usr['id'];
    $fid=filter_var($post['factura_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $did=filter_var($post['detalle_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$fid||!$did)throw new DomainException('Factura o partida incorrecta.');
    $token=trim((string)($post['operacion_token']??''));
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new DomainException('Vuelve a cargar el formulario antes de guardar.');
    $razon=trim((string)($post['motivo']??''));
    if(mb_strlen($razon)<6||mb_strlen($razon)>250)throw new DomainException('Escribe un motivo de 6 a 250 caracteres.');
    $modo=(string)($post['modo']??'MANUAL');$calc=(string)($post['calculo']??'MARK_UP');
    if(!in_array($modo,['MANUAL','AUTOMATICO'],true)||!in_array($calc,['MARK_UP','MARK_DOWN'],true))throw new DomainException('Método no permitido.');
    $elegidas=$post['sucursales']??[];$esperados=$post['precio_anterior']??[];
    $manual=$post['precio_nuevo']??[];$margenes=$post['margen']??[];
    if(!is_array($elegidas)||!is_array($esperados)||!is_array($manual)||!is_array($margenes)||count($elegidas)<1||count($elegidas)>20)throw new DomainException('Selecciona las sucursales que recibirán el cambio.');
    $ids=[];
    foreach($elegidas as $v){$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id||isset($ids[$id]))throw new DomainException('Sucursales duplicadas o incorrectas.');$ids[$id]=true;}
    $db->beginTransaction();
    try{
        $dup=$db->prepare('SELECT id FROM erp_precio_operaciones WHERE token=? LIMIT 1');$dup->execute([$token]);
        if($dup->fetchColumn()){$db->commit();return ['duplicada'=>true,'cambios'=>0];}
        $d=cp_factura_producto($db,(int)$fid,(int)$did);
        $validas=[];
        $lockSucursal=$db->prepare('SELECT id FROM pos_sucursales WHERE id=? AND activo=1 LIMIT 1');
        $lockPrecio=$db->prepare('SELECT precio_publico FROM pos_precios WHERE sucursal_id=? AND producto_id=? FOR UPDATE');
        // Bloquear y validar todo antes de alterar cualquier precio.
        foreach(array_keys($ids) as $sid){
            $lockSucursal->execute([$sid]);if(!$lockSucursal->fetchColumn())throw new DomainException('Sucursal no activa: '.$sid);
            $lockPrecio->execute([$sid,(int)$d['producto_id']]);$actual=$lockPrecio->fetchColumn();
            $anterior=$actual===false?null:number_format((float)$actual,2,'.','');
            $exp=(string)($esperados[$sid]??'');
            if(($anterior??'')!==$exp)throw new DomainException('El precio cambió mientras estabas capturando. Actualiza la página y revisa la sucursal.');
            if($modo==='MANUAL')$nuevo=cp_decimal($manual[$sid]??null);
            else $nuevo=cp_precio_sugerido((string)$d['costo_nuevo'],$calc,(string)($margenes[$sid]??''));
            if($anterior!==null&&$nuevo===$anterior)continue;
            $validas[]=['sucursal'=>$sid,'anterior'=>$anterior,'nuevo'=>$nuevo,'margen'=>(string)($margenes[$sid]??'')];
        }
        if(!$validas)throw new DomainException('No hay diferencias de precio para guardar.');
        $st=$db->prepare('INSERT INTO erp_precio_operaciones(token,factura_id,factura_detalle_id,producto_id,costo_antes,costo_factura,modo,calculo,motivo,usuario_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$token,$fid,$did,$d['producto_id'],$d['costo_anterior'],$d['costo_nuevo'],$modo,$modo==='MANUAL'?null:$calc,$razon,$uid]);
        $op=(int)$db->lastInsertId();
        $upd=$db->prepare('INSERT INTO pos_precios(sucursal_id,producto_id,precio_publico,actualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE precio_publico=VALUES(precio_publico),actualizado_por=VALUES(actualizado_por),vigente_desde=NOW()');
        $hist=$db->prepare('INSERT INTO pos_precios_historial(sucursal_id,producto_id,precio_anterior,precio_nuevo,usuario_id,motivo) VALUES (?,?,?,?,?,?)');
        $audit=$db->prepare('INSERT INTO erp_precio_operacion_detalle(operacion_id,sucursal_id,precio_anterior,precio_nuevo) VALUES (?,?,?,?)');
        foreach($validas as $r){
            $upd->execute([$r['sucursal'],$d['producto_id'],$r['nuevo'],$uid]);
            $hist->execute([$r['sucursal'],$d['producto_id'],$r['anterior'],$r['nuevo'],$uid,mb_substr('Factura '.$d['folio_interno'].' · '.$razon,0,250)]);
            $audit->execute([$op,$r['sucursal'],$r['anterior'],$r['nuevo']]);
        }
        $db->commit();return ['duplicada'=>false,'cambios'=>count($validas),'producto'=>$d['descripcion_snapshot']];
    }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
