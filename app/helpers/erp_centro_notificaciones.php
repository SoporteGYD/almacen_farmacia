<?php
declare(strict_types=1);
/** Centro de pendientes G&D. Todos los datos se derivan de la BD; sólo se guarda lectura individual. */
require_once __DIR__ . '/erp_oc_recepciones.php';
require_once __DIR__ . '/erp_oc_aviso_estado.php';
function gdnt_role(array $user): string {
    return strtoupper(trim((string)($user['rol_original'] ?? $user['rol'] ?? '')));
}
function gdnt_can_read(array $user): bool {
    return (int)($user['id']??0)>0 && in_array(gdnt_role($user),['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS','ALMACEN','ENCARGADO','GERENTE','CONSULTA'],true);
}
function gdnt_escape(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/**
 * Los avisos de Compras se basan en recepción + factura + aplicación y NO en el campo 'leído'.
 * El reparto cronológico entre varias recepciones es orientativo; no sustituye el vínculo documental.
 */
function gdnt_items(PDO $pdo, array $user): array {
    $rol=gdnt_role($user);
    $isAdmin=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
    $items=[];
    if ($isAdmin || $rol==='COMPRAS') {
        $sql="SELECT a.id,a.orden_id,a.recepcion_id,a.estado,
             r.folio AS recepcion_folio,r.destino,r.fecha_recepcion,o.folio AS oc_folio,
             p.nombre AS proveedor
             FROM erp_oc_avisos_compras a
             JOIN erp_oc_recepciones r ON r.id=a.recepcion_id AND r.estado='REGISTRADA'
             JOIN erp_ordenes_compra o ON o.id=a.orden_id
             JOIN proveedores p ON p.id=o.proveedor_id
             ORDER BY a.id DESC LIMIT 500";
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        // La función de conciliación usa las aplicaciones confirmadas: no consulta ni cambia stock actual.
        $states=oc_status_batch($pdo,$rows);
        foreach ($rows as $r) {
            $s=$states[(int)$r['id']]??[];
            $rec=(int)($s['recibidas']??0);
            $fact=(int)($s['facturadas']??0);
            $ap=(int)($s['aplicadas']??0);
            if ($rec<=0) continue;
            $pendingFact=max(0,$rec-$fact);
            $pendingApply=max(0,$rec-$ap);
            $active=$pendingFact>0 || $pendingApply>0;
            $status=$pendingFact>0?'Pendiente de factura':($pendingApply>0?'Pendiente de aplicar':'Resuelto');
            $items[]=[
              'key'=>'RECEPCION:'.(int)$r['recepcion_id'],'tipo'=>'RECEPCION','source_id'=>(int)$r['recepcion_id'],
              'area'=>'Compras','titulo'=>(string)$r['oc_folio'].' · '.(string)$r['proveedor'],
              'detalle'=>(string)$r['recepcion_folio'].' · '.(string)$r['destino'].' · '.number_format($rec).' recibidas, '.number_format($fact).' facturadas, '.number_format($ap).' aplicadas',
              'estado'=>$status,'prioridad'=>$pendingFact>0?'amarillo':($pendingApply>0?'amarillo':'verde'),
              'pendiente'=>$active,'auto_atendido'=>($pendingFact===0),'url'=>'erp_compras_avisos.php',
              'fecha'=>(string)$r['fecha_recepcion']
            ];
        }
    }
    if ($isAdmin || in_array($rol,['ALMACEN','ENCARGADO','GERENTE'],true)) {
        $acc=erpr_acceso($user);
        $dest=$acc['destino'];
        // Sin asignación de bodega no se muestran pedidos de una bodega ajena.
        if ($dest!=='__SIN_DESTINO__') {
            $rows=erpr_resumen($pdo,$isAdmin?'':(string)$dest,500);
            foreach($rows as $r) {
                if (!in_array((string)$r['destino'],['HIDALGO_BODEGA','TUXTLA_BODEGA'],true))continue;
                $rest=max(0,(int)$r['solicitadas']-(int)$r['recibidas']);
                if($rest<=0)continue;
                $items[]=[
                    'key'=>'OC_BODEGA:'.(int)$r['id'],'tipo'=>'OC_BODEGA','source_id'=>(int)$r['id'],
                    'area'=>'Almacén','titulo'=>(string)$r['folio'].' · '.(string)$r['proveedor'],
                    'detalle'=>(string)$r['destino'].' · '.number_format($rest).' piezas pendientes de recepción',
                    'estado'=>(int)$r['recibidas']>0?'Recepción parcial':'Por recibir','prioridad'=>'amarillo',
                    'pendiente'=>true,'url'=>'erp_recepcion_registrar.php?id='.(int)$r['id'],
                    'fecha'=>(string)$r['fecha']
                ];
            }
        }
    }
    // GYD_SEGUIMIENTO_COMPRAS_NOTIFICACIONES_V1
    // Los avisos financieros sólo llegan a Administración/Compras; no se amplían permisos de Almacén ni POS.
    if ($isAdmin || $rol==='COMPRAS') {
        require_once __DIR__.'/erp_compras_alertas_seguimiento.php';
        try { $items=array_merge($items,gdca_notification_items($pdo,$user)); }
        catch(Throwable $e){error_log('G&D avisos financieros: '.$e->getMessage());}
    }
    $userId=(int)$user['id'];
    $q=$pdo->prepare('SELECT tipo,origen_id,leido_en FROM erp_notificaciones_lecturas WHERE usuario_id=?');
    $q->execute([$userId]);$read=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $l) $read[(string)$l['tipo'].':'.(int)$l['origen_id']]=(string)$l['leido_en'];
    foreach($items as &$item){$item['leido']=isset($read[$item['key']]) || !empty($item['auto_atendido']);}
    unset($item);
    usort($items,static function(array $a,array $b):int {
        if($a['pendiente']!==$b['pendiente'])return $a['pendiente']?-1:1;
        if($a['leido']!==$b['leido'])return $a['leido']?1:-1;
        return strcmp((string)$b['fecha'],(string)$a['fecha']);
    });
    return $items;
}
function gdnt_counts(array $items):array {
    $out=['total'=>0,'sin_leer'=>0,'compras'=>0,'almacen'=>0,'resueltas'=>0];
    foreach($items as $x) {
        if(!$x['pendiente']) {$out['resueltas']++;continue;}
        $out['total']++;
        if(!$x['leido'])$out['sin_leer']++;
        if($x['area']==='Compras')$out['compras']++; else $out['almacen']++;
    }
    return $out;
}
