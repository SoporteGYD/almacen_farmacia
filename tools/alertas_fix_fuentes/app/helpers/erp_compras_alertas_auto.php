<?php
declare(strict_types=1);
/** G&D — Verificación objetiva de alertas financieras (no cambia precios ni inventario). */
function gdca_auto_tipo_verificable(string $tipo): bool {
    return in_array($tipo, ['SIN_PRECIO','MARGEN_BAJO','PERDIDA'], true);
}
function gdca_auto_valida(array $caso, ?float $precio, array $alertasActuales, float $margenMinimo=15.0): ?string {
    $tipo=(string)($caso['tipo']??'');
    if (!gdca_auto_tipo_verificable($tipo) || $precio===null || !is_finite($precio) || $precio<=0) return null;
    if (in_array($tipo,$alertasActuales,true)) return null;
    if ($tipo==='SIN_PRECIO') return 'Ya existe precio público positivo para esta sucursal.';
    $costo=isset($caso['costo']) && is_numeric($caso['costo']) ? (float)$caso['costo'] : null;
    if ($costo===null || !is_finite($costo) || $costo<0) return null;
    $margen=100*($precio-$costo)/$precio;
    if ($tipo==='PERDIDA' && $margen>=0) return 'El precio ya no es inferior al costo facturado. Margen: '.number_format($margen,2,'.','').' %.';
    if ($tipo==='MARGEN_BAJO' && $margen+1e-7 >= $margenMinimo)
      return 'Margen actual '.number_format($margen,2,'.','').'% (mínimo '.number_format($margenMinimo,2,'.','').'%).';
    return null;
}
/** Reevalúa el producto y la sucursal con la última factura VIGENTE elegible, nunca con el costo de una alerta antigua.
    Sin factura reciente no se presume resuelta una condición financiera. */
function gdca_auto_contexto(PDO $db,array $c):array {
    $pid=(int)($c['producto_id']??0);$sid=(int)($c['sucursal_id']??0);
    $ctx=['precio'=>null,'costo'=>null,'margen'=>null,'factura_id'=>0,'fecha'=>null,'motivo'=>''];
    if($pid<=0){$ctx['motivo']='Producto sin identificador válido.';return $ctx;}
    if($sid>0){
        if(!gd_cp_table($db,'pos_precios')){$ctx['motivo']='No está disponible la tabla de precios por sucursal.';return $ctx;}
        $p=$db->prepare('SELECT precio_publico FROM pos_precios WHERE producto_id=? AND sucursal_id=? LIMIT 1');
        $p->execute([$pid,$sid]);$v=$p->fetchColumn();
    }else{
        $p=$db->prepare('SELECT precio_venta FROM productos WHERE id=? LIMIT 1');
        $p->execute([$pid]);$v=$p->fetchColumn();
    }
    $ctx['precio']=$v===false?null:gd_cp_price($v);
    $q=$db->prepare("SELECT f.id,f.fecha_factura,d.costo_unitario
      FROM erp_facturas_detalle d JOIN erp_facturas f ON f.id=d.factura_id
      JOIN proveedores pr ON pr.id=f.proveedor_id
      WHERE d.producto_id=? AND d.cantidad_facturada>0 AND d.costo_unitario>=0
      AND f.estado IN ('REGISTRADA','EN_REVISION','VERIFICADA','CON_INCIDENCIAS')
      AND f.fecha_factura>=DATE_SUB(CURRENT_DATE(),INTERVAL 90 DAY) AND f.fecha_factura<DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY)
      ORDER BY f.fecha_factura DESC,f.id DESC,d.id DESC LIMIT 1");
    $q->execute([$pid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row){$ctx['motivo']='No hay una factura reciente válida para comprobar el margen automáticamente.';return $ctx;}
    $ctx['factura_id']=(int)$row['id'];$ctx['fecha']=(string)$row['fecha_factura'];
    $ctx['costo']=(float)$row['costo_unitario'];
    if($ctx['precio']!==null){$ctx['margen']=100.0*($ctx['precio']-$ctx['costo'])/$ctx['precio'];}
    return $ctx;
}
function gdca_auto_ruta_caso(PDO $db,array $c):string {
    $pid=(int)($c['producto_id']??0);$id=(int)($c['id']??0);
    $fid=(int)($c['factura_id']??0);$sid=(int)($c['sucursal_id']??0);
    $t=(string)($c['tipo']??'');
    if($t==='SUBIDA')return 'erp_catalogo_historial_costos.php?'.http_build_query(['producto_id'=>$pid]);
    if($t==='OPORTUNIDAD')return 'erp_catalogo_comparar_proveedores.php?'.http_build_query(['producto_id'=>$pid]);
    if(in_array($t,['MARGEN_BAJO','PERDIDA','SIN_PRECIO'],true)){
        // A partir de ahora el caso puede recuperar su factura incluso si la alerta se creó antes del arreglo de IDs.
        $ctx=gdca_auto_contexto($db,$c);
        if((int)$ctx['factura_id']>0)$fid=(int)$ctx['factura_id'];
        if($fid>0 && gd_cp_table($db,'erp_facturas_revision_detalle')){
            $q=$db->prepare("SELECT COUNT(*) FROM erp_facturas_detalle d JOIN erp_facturas_revision_detalle r ON r.factura_detalle_id=d.id WHERE d.factura_id=? AND d.producto_id=? AND r.tipo='ACEPTADA' AND r.cantidad>0");
            $q->execute([$fid,$pid]);
            if((int)$q->fetchColumn()>0)
                return 'erp_cambio_precios.php?'.http_build_query(['factura_id'=>$fid,'producto_id'=>$pid,'alerta_caso'=>$id]);
        }
        if($fid>0)return 'erp_facturas_verificacion.php?'.http_build_query(['id'=>$fid]);
        return 'erp_catalogo_finanzas.php?'.http_build_query(['producto_id'=>$pid,'sucursal'=>$sid]);
    }
    return 'erp_compras_alertas_seguimiento.php?'.http_build_query(['caso'=>$id]);
}
/** Se llama DENTRO de la transacción del sincronizador existente; también mantiene el historial de decisiones. */
function gdca_auto_sincronizar(PDO $db,array $eventos):array {
    $vigentes=[];
    foreach($eventos as $e){
        $key=(int)$e['producto_id'].':'.(int)$e['sucursal_id'];
        $vigentes[$key][(string)$e['tipo']]=true;
    }
    $insert=$db->prepare("INSERT INTO erp_compras_alerta_historial
        (caso_id,usuario_id,usuario_nombre,accion,estado_anterior,estado_nuevo,asignado_anterior,asignado_nuevo,observacion,creado_en)
        VALUES (?,0,'Sistema G&D',?,?,?,?,?,?,NOW())");
    // Reabrir sólo si vuelve a existir exactamente la misma condición de un caso que cerró automáticamente el sistema.
    $cerradas=$db->query("SELECT id,estado,asignado_a FROM erp_compras_alerta_casos WHERE vigente=1 AND estado='ATENDIDA' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    $last=$db->prepare('SELECT accion FROM erp_compras_alerta_historial WHERE caso_id=? ORDER BY id DESC LIMIT 1');
    $reopen=$db->prepare("UPDATE erp_compras_alerta_casos SET estado='PENDIENTE',cerrado_en=NULL,actualizado_en=NOW() WHERE id=? AND estado='ATENDIDA'");
    $reabiertas=0;
    foreach($cerradas as $c){
        $last->execute([(int)$c['id']]);if($last->fetchColumn()!=='AUTO_ATENDIDA')continue;
        $reopen->execute([(int)$c['id']]);if($reopen->rowCount()===0)continue;
        $a=(int)($c['asignado_a']??0);
        $insert->execute([(int)$c['id'],'AUTO_REAPERTURA','ATENDIDA','PENDIENTE',$a?:null,$a?:null,'Volvió a detectarse la misma condición.']);
        $reabiertas++;
    }
    $casos=$db->query("SELECT id,producto_id,tipo,sucursal_id,estado,asignado_a,factura_id
        FROM erp_compras_alerta_casos
        WHERE vigente=0 AND estado IN ('PENDIENTE','EN_REVISION')
        AND tipo IN ('MARGEN_BAJO','PERDIDA','SIN_PRECIO') FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    $close=$db->prepare("UPDATE erp_compras_alerta_casos SET estado='ATENDIDA',cerrado_en=NOW(),actualizado_en=NOW()
      WHERE id=? AND vigente=0 AND estado IN ('PENDIENTE','EN_REVISION')");
    $ctxCache=[];$atendidas=0;
    foreach($casos as $c){
        $key=(int)$c['producto_id'].':'.(int)$c['sucursal_id'];$tipo=(string)$c['tipo'];
        if(isset($vigentes[$key][$tipo]))continue; // Todavía ocurre: NUNCA cerrar por haber guardado un cambio.
        if($tipo==='MARGEN_BAJO' && isset($vigentes[$key]['PERDIDA']))continue;
        if(!array_key_exists($key,$ctxCache))$ctxCache[$key]=gdca_auto_contexto($db,$c);
        $ctx=$ctxCache[$key];
        if(!$ctx['factura_id'])continue; // No hay evidencia reciente: nunca asumir resuelto.
        $casoActual=['tipo'=>$tipo,'costo'=>$ctx['costo']];
        $razon=gdca_auto_valida($casoActual,$ctx['precio'],array_keys($vigentes[$key]??[]));
        if($razon===null)continue;
        $close->execute([(int)$c['id']]);if($close->rowCount()===0)continue;
        $prev=(string)$c['estado'];$a=(int)($c['asignado_a']??0);
        $insert->execute([(int)$c['id'],'AUTO_ATENDIDA',$prev,'ATENDIDA',$a?:null,$a?:null,
            'Corrección comprobada con factura reciente #'.$ctx['factura_id'].'. '.$razon]);
        $atendidas++;
    }
    return ['atendidas'=>$atendidas,'reabiertas'=>$reabiertas];
}
