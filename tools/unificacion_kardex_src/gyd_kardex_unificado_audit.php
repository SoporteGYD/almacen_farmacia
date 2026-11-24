<?php
/**
 * G&D: combinación en sólo lectura de referencias documentales y asientos
 * transaccionales del Kardex. Nunca escribe existencias ni recalcula saldos.
 */
declare(strict_types=1);

function gyd_kum_clasificar(array $a): string {
    $modulo=strtolower((string)($a['modulo']??''));
    $folio=strtoupper((string)($a['folio']??''));
    if(str_contains($modulo,'conciliacion') || str_starts_with($folio,'CONC-')) return 'AJUSTE';
    if(str_contains($modulo,'reversion') || str_contains($modulo,'devoluc')) return 'DEVOLUCION';
    if(str_contains($modulo,'resurtid')) return 'RESURTIDO';
    if(str_contains($modulo,'factura')) return 'FACTURA';
    if((string)($a['area']??'')==='ALMACEN') return 'ALMACEN';
    if(str_contains($modulo,'pos_') || str_contains($modulo,'pos.php') || str_starts_with($folio,'PV-')) return 'VENTA';
    return 'AUDITORIA';
}
function gyd_kum_saldo(mixed $v): ?int {
    return $v===null || $v==='' || !is_numeric($v)?null:(int)$v;
}
function gyd_kum_row(array $a,int $sid,int $pid): array {
    $area=(string)($a['area']??'');
    $d=match($area){'GENERAL'=>'pos','FARMACIA'=>'farmacia','ALMACEN'=>'almacen',default=>null};
    $sameIdentity=($a['sucursal_antes']===null || (int)$a['sucursal_antes']===$sid)
       && ($a['sucursal_despues']===null || (int)$a['sucursal_despues']===$sid)
       && ($a['producto_antes']===null || (int)$a['producto_antes']===$pid)
       && ($a['producto_despues']===null || (int)$a['producto_despues']===$pid);
    $antes=gyd_kum_saldo($a['existencia_antes']??null);
    $despues=gyd_kum_saldo($a['existencia_despues']??null);
    $cambio=$sameIdentity?gyd_kum_saldo($a['diferencia']??null):null;
    $valid=$sameIdentity && $antes!==null && $despues!==null && $cambio!==null && $antes+$cambio===$despues;
    $where=(string)($a['ubicacion_despues']??$a['ubicacion_antes']??'');
    $areaName=match($area){'GENERAL'=>'General POS','FARMACIA'=>'Farmacia física','ALMACEN'=>'Almacén · ubicación',default=>'Fuente desconocida'};
    $detail='Cambio en '.$areaName.($where!==''?' ('.$where.')':'').' · '.(string)($a['operacion']??'');
    if(!$sameIdentity)$detail.=' · CAMBIÓ LA IDENTIDAD O SUCURSAL DEL REGISTRO';
    if($a['operacion']!=='UPDATE')$detail.=' · alta/baja: cambio total no calculado';
    $cells=[];
    foreach(['pos','farmacia','almacen'] as $k){
        $cells[$k]=['antes'=>null,'despues'=>null,'delta'=>null,'estado'=>'NO_VERIFICABLE','detalle'=>'Este asiento no corresponde a esta fuente'];
    }
    if($d!==null){
        $cells[$d]=['antes'=>$antes,'despues'=>$despues,'delta'=>$cambio,'estado'=>$valid?'REGISTRADO':'NO_VERIFICABLE',
             'detalle'=>$valid?'Antes y después capturados dentro de la transacción SQL':'Datos parciales o identidad modificada: no interpretar como cambio neto'];
    }
    $tipo=gyd_kum_clasificar($a);
    $folio=trim((string)($a['folio']??''));
    return [
        'fecha'=>(string)($a['creado_en']??''),
        'grupo'=>$tipo,
        'tipo'=>'Cambio físico auditado',
        'folio'=>$folio!==''?$folio:'Auditoría #'.(int)($a['id']??0),
        'detalle'=>$detail,
        'usuario'=>(string)($a['responsable']??'Usuario no identificado'),
        'pos'=>$d==='pos'?$cambio:null,
        'farmacia'=>$d==='farmacia'?$cambio:null,
        'almacen'=>$d==='almacen'?$cambio:null,
        'estado'=>$valid?'COMPROBADO':'REVISAR AUDITORÍA',
        'origen'=>'gyd_stock_auditoria_real',
        'id'=>(int)($a['id']??0),
        'audit_area_key'=>$d,
        'saldos'=>$cells,
    ];
}

function gyd_kum_combinar(PDO $db,array $documentos,int $sid,int $pid,string $desde,string $hasta,string $tipo,int $uid,array &$warnings):array {
    try {
        $check=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_stock_auditoria_real'");
        if(!(int)$check->fetchColumn()){
            $warnings[]='No se encontró la tabla de auditoría real. Solo se muestran referencias documentales; consulta la instalación del Kardex.';
            return $documentos;
        }
        $where=' WHERE (a.sucursal_antes=? OR a.sucursal_despues=?) AND (a.producto_antes=? OR a.producto_despues=?) AND a.creado_en>=? AND a.creado_en<?';
        $args=[$sid,$sid,$pid,$pid,$desde.' 00:00:00',(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
        if($uid>0){$where.=' AND a.usuario_id=?';$args[]=$uid;}
        $st=$db->prepare('SELECT a.*,u.nombre AS responsable FROM gyd_stock_auditoria_real a LEFT JOIN usuarios u ON u.id=a.usuario_id'.$where.' ORDER BY a.creado_en DESC,a.id DESC LIMIT 1201');
        $st->execute($args);
        $audits=$st->fetchAll(PDO::FETCH_ASSOC);
        if(count($audits)>1200){$warnings[]='Auditoría: se alcanzó el límite de 1,200 cambios del rango; acorta fechas para evitar omisiones.';$audits=array_slice($audits,0,1200);}
        $seen=[];
        foreach($documentos as $d) if(($d['origen']??'')==='gyd_stock_auditoria_real') $seen[(int)$d['id']]=true;
        foreach($audits as $a){
            $id=(int)($a['id']??0);if($id<1||isset($seen[$id]))continue;
            $converted=gyd_kum_row($a,$sid,$pid);
            if($tipo!=='TODOS'&&$tipo!=='AUDITORIA'&&$tipo!==$converted['grupo'])continue;
            $seen[$id]=true;
            $documentos[]=$converted;
        }
        usort($documentos,static fn(array $a,array $b):int=>strcmp((string)$b['fecha'],(string)$a['fecha'])?:strcmp((string)$b['origen'],(string)$a['origen'])?:((int)$b['id']<=>(int)$a['id']));
        return $documentos;
    }catch(Throwable $e){
        error_log('G&D Kardex unificación: '.$e->getMessage());
        $warnings[]='No se pudieron combinar los asientos auditados. Los movimientos documentales siguen disponibles; revisa el error PHP.';
        return $documentos;
    }
}
function gyd_kum_celda(array $r,string $dimension):string {
    if(($r['origen']??'')!=='gyd_stock_auditoria_real')return kg_sa_celda($r,$dimension);
    if(($r['audit_area_key']??null)!==$dimension)return '<span class="muted">No corresponde a este asiento</span>';
    $s=$r['saldos'][$dimension]??[];
    $old=$s['antes']??null;$new=$s['despues']??null;$delta=$s['delta']??null;
    $form=static fn($v)=>$v===null?'—':number_format((int)$v,0,'.',',');
    $safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $good=($s['estado']??'')==='REGISTRADO';
    return '<div class="kg-saldo '.($good?'kg-good':'kg-unknown').'">'
      .'<span class="kg-before">Antes <b>'.$form($old).'</b></span><span class="kg-arrow">→</span>'
      .'<span class="kg-after">Después <b>'.$form($new).'</b></span>'
      .'<span class="kg-delta">Cambio: '.($delta!==null&&$delta>0?'+':'').$form($delta).'</span>'
      .'<span class="kg-cert">'.($good?'Registrado en SQL':'No verificable').'</span>'
      .(!empty($s['detalle'])?'<span class="kg-details">'.$safe($s['detalle']).'</span>':'').'</div>';
}
