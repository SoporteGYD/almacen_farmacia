<?php
declare(strict_types=1);
/** Panel auditoría de cajas. Exclusivamente SELECT, sin escrituras. */
function gac_centavos(mixed $n): int {
    $v=trim((string)$n);
    if(!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D',$v,$m))throw new DomainException('Formato monetario incompatible en un registro.');
    $c=(int)$m[2]*100+(int)str_pad($m[3]??'',2,'0');
    return ($m[1]==='-'?-1:1)*$c;
}
function gac_dinero(?int $n):string {return $n===null?'—':($n<0?'-':'').'$'.number_format(abs($n)/100,2,'.',',');}
function gac_html(mixed $n):string{return htmlspecialchars((string)$n,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gac_query(PDO $db,string $sql,array $param=[]):array{$s=$db->prepare($sql);$s->execute($param);return $s->fetchAll(PDO::FETCH_ASSOC);}
function gac_rol_permitido(string $rol):bool{
    return in_array(strtoupper(trim($rol)),['GERENTE','DIRECCION','ADMINISTRADOR','CONTABILIDAD','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gac_fecha(string $valor):string{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$valor);
    if(!$d || $d->format('Y-m-d')!==$valor)throw new DomainException('Fecha de filtro inválida.');
    return $valor;
}
/** Obtiene los datos del periodo desde el servidor; sólo usa tablas POS existentes. */
function gac_cargar(PDO $db,string $desde,string $hasta,int $sucursal,string $estado):array {
    $a=new DateTimeImmutable(gac_fecha($desde));$b=new DateTimeImmutable(gac_fecha($hasta));
    if($b<$a || (int)$a->diff($b)->days>92)throw new DomainException('El rango permitido es de hasta 93 días.');
    if(!in_array($estado,['TODOS','ABIERTO','CERRADO'],true))throw new DomainException('Estado de filtro no admitido.');
    if($sucursal<0)throw new DomainException('Sucursal inválida.');
    $sucursales=gac_query($db,'SELECT id,nombre FROM pos_sucursales WHERE activo=1 ORDER BY id');
    $validas=array_map(static fn($s)=>(int)$s['id'],$sucursales);
    if($sucursal!==0 && !in_array($sucursal,$validas,true))throw new DomainException('Sucursal no disponible.');
    $filtros=["(t.abierto_en>=? AND t.abierto_en<? OR t.estado='ABIERTO')"];
    $param=[$a->format('Y-m-d').' 00:00:00',$b->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    if($sucursal){$filtros[]='s.id=?';$param[]=$sucursal;}
    if($estado!=='TODOS'){$filtros[]='t.estado=?';$param[]=$estado;}
    $sql="SELECT t.id,t.caja_id,t.estado,t.abierto_en,t.cerrado_en,t.fondo_inicial,t.efectivo_contado,t.diferencia_efectivo,
            t.usuario_apertura_id,c.nombre caja,c.sucursal_id,s.nombre sucursal,op.nombre abierto_por,cl.nombre cerrado_por
        FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id
        JOIN pos_sucursales s ON s.id=c.sucursal_id
        LEFT JOIN usuarios op ON op.id=t.usuario_apertura_id
        LEFT JOIN usuarios cl ON cl.id=t.usuario_cierre_id
        WHERE s.activo=1 AND ".implode(' AND ',$filtros)." ORDER BY (t.estado='ABIERTO') DESC,t.id DESC LIMIT 251";
    $turnos=gac_query($db,$sql,$param);
    $limitado=count($turnos)>250;if($limitado)array_pop($turnos);
    $branches=[];
    foreach($sucursales as $s){if($sucursal && $sucursal!==(int)$s['id'])continue;
        $branches[(int)$s['id']]=['id'=>(int)$s['id'],'nombre'=>$s['nombre'],'turnos'=>0,'abiertos'=>0,'cerrados'=>0,'alertas'=>0,'efectivo'=>0,'tarjeta'=>0,'transferencia'=>0,'retiros'=>0];}
    $summary=['turnos'=>0,'abiertos'=>0,'cerrados'=>0,'alertas'=>0,'cortes_diferencia'=>0,'efectivo'=>0,'tarjeta'=>0,'transferencia'=>0,'retiros'=>0];
    if(!$turnos)return ['turnos'=>[],'sucursales'=>$sucursales,'sucresumen'=>array_values($branches),'resumen'=>$summary,'limitado'=>false];
    $ids=array_map(static fn($t)=>(int)$t['id'],$turnos);
    $place=implode(',',array_fill(0,count($ids),'?'));
    $pagos=[];$movimientos=[];$ventas=[];$reembolsos=[];$bovedas=[];$firmas=[];$conteos=[];
    // Regla idéntica al saldo del cierre: pago confirmado, venta pagada o cancelación reembolsada en ese turno.
    $cond="(v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS
          (SELECT 1 FROM pos_reversiones r WHERE r.venta_id=v.id AND r.tipo='CANCELACION_TOTAL'
           AND r.estado='APLICADA' AND r.turno_reembolso_id=v.turno_id)))";
    foreach(gac_query($db,"SELECT v.turno_id,p.metodo,COALESCE(SUM(p.monto),0) monto FROM pos_pagos p
        JOIN pos_ventas v ON v.id=p.venta_id WHERE v.turno_id IN ($place) AND p.estado='CONFIRMADO'
        AND $cond GROUP BY v.turno_id,p.metodo",$ids) as $p){
        $tipo=(string)$p['metodo'];if(!in_array($tipo,['EFECTIVO','TARJETA','TRANSFERENCIA'],true))continue;
        $pagos[(int)$p['turno_id']][$tipo]=gac_centavos($p['monto']);
    }
    foreach(gac_query($db,"SELECT turno_id,tipo,COALESCE(SUM(monto),0) monto
        FROM pos_movimientos_caja WHERE turno_id IN ($place) GROUP BY turno_id,tipo",$ids) as $v){
        $movimientos[(int)$v['turno_id']][(string)$v['tipo']]=gac_centavos($v['monto']);
    }
    foreach(gac_query($db,"SELECT turno_id,
        SUM(CASE WHEN estado='PAGADA' THEN 1 ELSE 0 END) pagadas,
        SUM(CASE WHEN estado='CANCELADA' THEN 1 ELSE 0 END) canceladas
        FROM pos_ventas WHERE turno_id IN ($place) GROUP BY turno_id",$ids) as $v){$ventas[(int)$v['turno_id']]=$v;}
    foreach(gac_query($db,"SELECT turno_reembolso_id turno_id,medio_reembolso,
        COALESCE(SUM(importe),0) monto,COUNT(*) operaciones FROM pos_reversiones
        WHERE turno_reembolso_id IN ($place) AND estado='APLICADA'
        AND tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL')
        GROUP BY turno_reembolso_id,medio_reembolso",$ids) as $r){
        $reembolsos[(int)$r['turno_id']][(string)$r['medio_reembolso']]=gac_centavos($r['monto']);
    }
    foreach(gac_query($db,"SELECT turno_id,COALESCE(SUM(importe),0) importe,COUNT(*) cantidad
        FROM gyd_retiros_boveda WHERE turno_id IN ($place) GROUP BY turno_id",$ids) as $r){$bovedas[(int)$r['turno_id']]=gac_centavos($r['importe']);}
    foreach(gac_query($db,"SELECT a.turno_id,u.nombre autorizador,a.autorizador_rol,a.cajero_id
        FROM gyd_cortes_autorizaciones a
        JOIN (SELECT turno_id,MAX(id) id FROM gyd_cortes_autorizaciones WHERE turno_id IN ($place) GROUP BY turno_id) ultimo ON ultimo.id=a.id
        LEFT JOIN usuarios u ON u.id=a.autorizador_id",$ids) as $r){$firmas[(int)$r['turno_id']]=$r;}
    foreach(gac_query($db,"SELECT turno_id,total_contado FROM pos_cortes_denominaciones WHERE turno_id IN ($place)",$ids) as $r){$conteos[(int)$r['turno_id']]=gac_centavos($r['total_contado']);}
    foreach($turnos as &$t){
        $id=(int)$t['id'];$sid=(int)$t['sucursal_id'];$pg=$pagos[$id]??[];$mv=$movimientos[$id]??[];
        $ef=$pg['EFECTIVO']??0;$ta=$pg['TARJETA']??0;$tr=$pg['TRANSFERENCIA']??0;
        $ing=$mv['INGRESO']??0;$ret=$mv['RETIRO']??0;$fondo=gac_centavos($t['fondo_inicial']);
        $esperado=$fondo+$ef+$ing-$ret;
        $cerrado=$t['estado']==='CERRADO';
        $contado=$t['efectivo_contado']===null?null:gac_centavos($t['efectivo_contado']);
        $dif=$cerrado&&$contado!==null?$contado-$esperado:null;
        $historico=$t['diferencia_efectivo']===null?null:gac_centavos($t['diferencia_efectivo']);
        $avisos=[];
        if($cerrado&&$contado===null)$avisos[]='Corte sin efectivo contado';
        if($cerrado&&$dif!==null&&$historico!==null&&$dif!==$historico)$avisos[]='Diferencia con el resultado histórico';
        if($cerrado&&$historico===null)$avisos[]='Sin diferencia histórica';
        if($cerrado&&!isset($firmas[$id]))$avisos[]='Sin autorización identificada (posible registro anterior)';
        if($cerrado&&!isset($conteos[$id]))$avisos[]='Sin desglose de billetes y monedas';
        if(isset($conteos[$id])&&$contado!==null&&$conteos[$id]!==$contado)$avisos[]='Conteo por denominaciones distinto al cierre';
        if(($mv['AJUSTE']??0)!==0)$avisos[]='Movimiento de ajuste requiere revisión';
        if(($bovedas[$id]??0)>$ret)$avisos[]='Tickets de retiro mayores que salidas';
        if(($reembolsos[$id]['EFECTIVO']??0)>$ret)$avisos[]='Reembolsos en efectivo mayores que salidas';
        if($dif!==null&&$dif!==0)$summary['cortes_diferencia']++;
        $t['efectivo']=$ef;$t['tarjeta']=$ta;$t['transferencia']=$tr;$t['ingresos']=$ing;$t['retiros']=$ret;
        $t['fondo']=$fondo;$t['esperado']=$esperado;$t['contado']=$contado;$t['diferencia']=$dif;
        $t['ventas_pagadas']=(int)($ventas[$id]['pagadas']??0);$t['ventas_canceladas']=(int)($ventas[$id]['canceladas']??0);
        $t['reembolso_efectivo']=$reembolsos[$id]['EFECTIVO']??0;
        $t['reembolso_tarjeta']=$reembolsos[$id]['TARJETA']??0;
        $t['reembolso_transferencia']=$reembolsos[$id]['TRANSFERENCIA']??0;
        $t['autorizador']=$firmas[$id]['autorizador']??null;$t['avisos']=$avisos;
        foreach(['turnos','efectivo','tarjeta','transferencia','retiros'] as $k){
            $val=match($k){'turnos'=>1,'efectivo'=>$ef,'tarjeta'=>$ta,'transferencia'=>$tr,'retiros'=>$ret};
            $summary[$k]+=$val;$branches[$sid][$k]+=$val;
        }
        $tipo=$cerrado?'cerrados':'abiertos';$summary[$tipo]++;$branches[$sid][$tipo]++;
        if($avisos){$summary['alertas']++;$branches[$sid]['alertas']++;}
    }
    unset($t);
    return ['turnos'=>$turnos,'sucursales'=>$sucursales,'sucresumen'=>array_values($branches),
        'resumen'=>$summary,'limitado'=>$limitado];
}
