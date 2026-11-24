<?php
declare(strict_types=1);
/**
 * G&D · Resolución verificable de alertas del Catálogo/Compras.
 * Sólo escribe estado e historial de alertas. NUNCA edita productos, precios, stock ni facturas.
 * Se ejecuta bajo la transacción de gdca_sync y con su advisory lock.
 */
function gdca_auto_tipo_verificable(string $tipo):bool {
    return in_array($tipo, ['SIN_PRECIO','MARGEN_BAJO','PERDIDA'], true);
}
function gdca_auto_valida(array $caso, ?float $precio, array $alertasActuales, float $margenMinimo=15.0):?string {
    $tipo=(string)($caso['tipo']??'');
    if(!gdca_auto_tipo_verificable($tipo) || $precio===null || !is_finite($precio) || $precio<=0) return null;
    if(in_array($tipo, $alertasActuales, true)) return null;
    if($tipo==='SIN_PRECIO')return 'Se registró un precio público positivo para la sucursal afectada.';
    // Una pérdida puede resolverse aunque posteriormente exista una alerta distinta de margen bajo.
    if($tipo==='MARGEN_BAJO' && (in_array('PERDIDA',$alertasActuales,true) || in_array('MARGEN_BAJO',$alertasActuales,true))) return null;
    $costo=isset($caso['costo'])?(float)$caso['costo']:0.0;
    if(!is_finite($costo)||$costo<=0)return null;
    $margen=100.0*($precio-$costo)/$precio;
    if($tipo==='PERDIDA' && $margen>=0)return 'El precio público ya no es inferior al costo de referencia. Margen: '.number_format($margen,2,'.','').' %.';
    if($tipo==='MARGEN_BAJO' && $margen+1.0e-7 >= $margenMinimo)
        return 'Se comprobó margen estimado de '.number_format($margen,2,'.','').'% (mínimo '.number_format($margenMinimo,2,'.','').'%).';
    return null;
}
function gdca_auto_ruta_caso(PDO $db,array $c):string {
    $pid=(int)($c['producto_id']??0);$id=(int)($c['id']??0);
    $fid=(int)($c['factura_id']??0);$sid=(int)($c['sucursal_id']??0);
    $t=(string)($c['tipo']??'');
    if($t==='SUBIDA')return 'erp_catalogo_historial_costos.php?'.http_build_query(['producto_id'=>$pid]);
    if($t==='OPORTUNIDAD')return 'erp_catalogo_comparar_proveedores.php?'.http_build_query(['producto_id'=>$pid]);
    if(in_array($t,['MARGEN_BAJO','PERDIDA','SIN_PRECIO'],true)){
        if($fid>0 && gd_cp_table($db,'erp_facturas_revision_detalle')){
            $q=$db->prepare("SELECT COUNT(*) FROM erp_facturas_detalle d JOIN erp_facturas_revision_detalle r ON r.factura_detalle_id=d.id WHERE d.factura_id=? AND d.producto_id=? AND r.tipo='ACEPTADA' AND r.cantidad>0");
            $q->execute([$fid,$pid]);
            if((int)$q->fetchColumn()>0)
                return 'erp_cambio_precios.php?'.http_build_query(['factura_id'=>$fid,'producto_id'=>$pid,'alerta_caso'=>$id]);
        }
        // Sin factura aceptada, primero se debe validar documento (no habilitar cambios de precio sin respaldo).
        if($fid>0)return 'erp_facturas_verificacion.php?'.http_build_query(['id'=>$fid]);
        return 'erp_catalogo_finanzas.php?'.http_build_query(['producto_id'=>$pid,'sucursal'=>$sid]);
    }
    return 'erp_compras_alertas_seguimiento.php?'.http_build_query(['caso'=>$id]);
}
function gdca_auto_sincronizar(PDO $db,array $eventos):array {
    $vigentes=[];
    foreach($eventos as $e){
        $key=(int)$e['producto_id'].':'.(int)$e['sucursal_id'];
        $vigentes[$key][(string)$e['tipo']]=true;
    }
    $insert=$db->prepare('INSERT INTO erp_compras_alerta_historial(caso_id,usuario_id,usuario_nombre,accion,estado_anterior,estado_nuevo,asignado_anterior,asignado_nuevo,observacion,creado_en) VALUES (?,0,\'Sistema G&D\',?,?,?,?,?,?,NOW())');
    // Si vuelve a presentarse exactamente una alerta que cerró el sistema, reabrirla.
    $autoCerradas=$db->query("SELECT id,estado,asignado_a FROM erp_compras_alerta_casos WHERE vigente=1 AND estado='ATENDIDA' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    $last=$db->prepare('SELECT accion FROM erp_compras_alerta_historial WHERE caso_id=? ORDER BY id DESC LIMIT 1');
    $reabiertas=0;
    $reopen=$db->prepare("UPDATE erp_compras_alerta_casos SET estado='PENDIENTE',cerrado_en=NULL,actualizado_en=NOW() WHERE id=? AND estado='ATENDIDA'");
    foreach($autoCerradas as $c){
        $last->execute([(int)$c['id']]);
        if($last->fetchColumn()!=='AUTO_ATENDIDA')continue;
        $reopen->execute([(int)$c['id']]);if($reopen->rowCount()===0)continue;
        $a=(int)($c['asignado_a']??0);
        $insert->execute([(int)$c['id'],'AUTO_REAPERTURA','ATENDIDA','PENDIENTE',$a?:null,$a?:null,'Se volvió a detectar automáticamente la misma condición.']);
        $reabiertas++;
    }
    $casos=$db->query("SELECT id,producto_id,tipo,sucursal_id,costo,precio,estado,asignado_a,factura_id FROM erp_compras_alerta_casos WHERE vigente=0 AND estado IN ('PENDIENTE','EN_REVISION') AND tipo IN ('MARGEN_BAJO','PERDIDA','SIN_PRECIO') FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    $actualizaciones=0;$precios=[];$fuentes=[];
    $hasPos=gd_cp_table($db,'pos_precios');
    $buscarBase=$db->prepare('SELECT precio_venta FROM productos WHERE id=? LIMIT 1');
    $buscarSucursal=$hasPos?$db->prepare('SELECT precio_publico FROM pos_precios WHERE producto_id=? AND sucursal_id=? LIMIT 1'):null;
    $factura=$db->prepare('SELECT fecha_factura,estado FROM erp_facturas WHERE id=? LIMIT 1');
    $cerrar=$db->prepare("UPDATE erp_compras_alerta_casos SET estado='ATENDIDA',cerrado_en=NOW(),actualizado_en=NOW() WHERE id=? AND vigente=0 AND estado IN ('PENDIENTE','EN_REVISION')");
    foreach($casos as $c){
        $key=(int)$c['producto_id'].':'.(int)$c['sucursal_id'];
        $fid=(int)$c['factura_id'];
        // Un documento que ya no participa en la evaluación (por antigüedad o anulación)
        // no demuestra que se haya corregido la alerta: conservar el caso histórico.
        if($fid<=0)continue;
        if(!array_key_exists($fid,$fuentes)){
            $factura->execute([$fid]);$f=$factura->fetch(PDO::FETCH_ASSOC);
            $vigente=$f && in_array((string)$f['estado'],['REGISTRADA','EN_REVISION','VERIFICADA','CON_INCIDENCIAS'],true);
            $days=$f?((int)floor((strtotime('today')-strtotime((string)$f['fecha_factura']))/86400)):-1;
            $fuentes[$fid]= $vigente && $days>=0 && $days<=90;
        }
        if(!$fuentes[$fid])continue;
        if(!array_key_exists($key,$precios)){
            if((int)$c['sucursal_id']>0){
                $v=false;
                if($buscarSucursal){$buscarSucursal->execute([(int)$c['producto_id'],(int)$c['sucursal_id']]);$v=$buscarSucursal->fetchColumn();}
            }else{$buscarBase->execute([(int)$c['producto_id']]);$v=$buscarBase->fetchColumn();}
            $precios[$key]=($v===false||$v===null)?null:(float)$v;
        }
        $razon=gdca_auto_valida($c,$precios[$key],array_keys($vigentes[$key]??[]));
        if($razon===null)continue;
        $cerrar->execute([(int)$c['id']]);if($cerrar->rowCount()===0)continue;
        $old=(string)$c['estado'];$a=(int)($c['asignado_a']??0);
        $insert->execute([(int)$c['id'],'AUTO_ATENDIDA',$old,'ATENDIDA',$a?:null,$a?:null,'Cierre automático por verificación de datos. '.$razon]);
        $actualizaciones++;
    }
    return ['atendidas'=>$actualizaciones,'reabiertas'=>$reabiertas];
}
