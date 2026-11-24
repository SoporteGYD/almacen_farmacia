<?php
declare(strict_types=1);
/** ERP G&D: conciliación de caja de SOLO LECTURA. Todos los importes se manejan en centavos enteros. */
function gdcd_cents(mixed $v): int {
    $s = trim((string)$v);
    if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', $s, $m)) throw new DomainException('Importe monetario inválido.');
    $a=(int)$m[2];$b=(int)str_pad($m[3]??'',2,'0');
    if ($a>900000000000) throw new DomainException('Importe fuera de rango.');
    $n=$a*100+$b;return $m[1]==='-'?-$n:$n;
}
function gdcd_money(int $c): string { return ($c<0?'-':'').'$'.number_format(abs($c)/100,2,'.',','); }
function gdcd_h(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gdcd_q(PDO $db,string $sql,array $p=[]): PDOStatement { $q=$db->prepare($sql);$q->execute($p);return $q; }
function gdcd_table(PDO $db,string $table):bool {
    return (bool)gdcd_q($db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$table])->fetchColumn();
}
function gdcd_day(string $s): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d!==false&&$d->format('Y-m-d')===$s; }
function gdcd_csv(mixed $v): string {
    $s=(string)$v;
    return preg_match('/^[\x00-\x20]*[=+@\-]/u',$s)?"'".$s:$s;
}
/**
 * Separa retiros de bóveda, devoluciones de efectivo y movimientos sin clasificación.
 * Ningún importe se descuenta dos veces. Los montos ya están incluidos en pos_movimientos_caja.
 */
function gdcd_clasificar(array $movimientos,array $retiros):array {
    $byMov=[];$incidencias=[];
    foreach($retiros as $r){
        $mid=(int)($r['movimiento_id']??0);
        if($mid<1){$incidencias[]='Retiro a bóveda sin movimiento POS relacionado.';continue;}
        if(isset($byMov[$mid]))$incidencias[]='Dos comprobantes vinculados al mismo movimiento POS #'.$mid.'.';
        $byMov[$mid]=$r;
    }
    $tot=['boveda'=>0,'reembolsos'=>0,'otros_retiros'=>0,'ingresos'=>0,'ajustes'=>0,'retiros_total'=>0,'documentos'=>0];
    $registros=[];$vistos=[];
    foreach($movimientos as $m){
        $id=(int)$m['id'];$tipo=(string)$m['tipo'];$c=gdcd_cents($m['monto']);$motivo=(string)($m['motivo']??'');$clase=$tipo;
        if($tipo==='RETIRO'){
            $tot['retiros_total']+=$c;
            if(isset($byMov[$id])){
                $clase='BÓVEDA';$tot['boveda']+=$c;$tot['documentos']++;
                if(gdcd_cents($byMov[$id]['importe'])!==$c)$incidencias[]='Diferencia entre ticket y movimiento del retiro #'.$id.'.';
                $vistos[$id]=true;
            }elseif(str_starts_with($motivo,'Devolución de ')){$clase='REEMBOLSO EFECTIVO';$tot['reembolsos']+=$c;}
            else{$clase='OTRO RETIRO';$tot['otros_retiros']+=$c;}
        }elseif($tipo==='INGRESO')$tot['ingresos']+=$c;
        elseif($tipo==='AJUSTE')$tot['ajustes']+=$c;
        $registros[]=['id'=>$id,'clase'=>$clase,'tipo'=>$tipo,'monto'=>$c,'fecha'=>$m['creado_en']??'','motivo'=>$motivo,'folio'=>isset($byMov[$id])?'RET-'.str_pad((string)$byMov[$id]['id'],8,'0',STR_PAD_LEFT):''];
    }
    foreach($byMov as $mid=>$r)if(!isset($vistos[$mid]))$incidencias[]='Ticket RET-'.str_pad((string)$r['id'],8,'0',STR_PAD_LEFT).' sin movimiento de caja encontrado.';
    if($tot['otros_retiros']>0)$incidencias[]='Existen otros retiros sin folio de bóveda; se debe revisar el soporte original.';
    if($tot['ajustes']>0)$incidencias[]='Existen movimientos AJUSTE; revisar su respaldo (no se incluyen automáticamente en efectivo esperado).';
    return ['totales'=>$tot,'lineas'=>$registros,'alertas'=>$incidencias];
}
/** Cálculo idéntico al libro POS actual, sin aplicar reembolsos por segunda vez. */
function gdcd_calcular(int $fondo,array $pagos,array $totales,?int $contado,?int $diferenciaGuardada,array $alertas=[],int $cancelaciones=0,int $reembolsosDeclarados=0):array {
    $ef=$pagos['EFECTIVO']??0;$tar=$pagos['TARJETA']??0;$trans=$pagos['TRANSFERENCIA']??0;
    $esperado=$fondo+$ef+$totales['ingresos']-$totales['retiros_total'];
    $diferencia=$contado===null?null:$contado-$esperado;
    if($esperado<0)$alertas[]='El efectivo esperado es negativo: posible salida superior a lo disponible.';
    if($diferencia!==null&&$diferencia!==0)$alertas[]='Diferencia entre efectivo contado y esperado.';
    if($contado!==null&&$diferenciaGuardada!==null&&$diferenciaGuardada!==$diferencia)$alertas[]='La diferencia guardada por POS no coincide con el recálculo.';
    if($cancelaciones>0)$alertas[]='Hay cancelaciones totales: revisar medio y evidencia del reembolso; pueden alterar el total PAGADO que alimenta el corte.';
    if($reembolsosDeclarados>0 && $totales['reembolsos']===0)$alertas[]='Se declaró reembolso en efectivo, pero no se identificó movimiento tipo Devolución en este turno.';
    return ['esperado'=>$esperado,'contado'=>$contado,'diferencia'=>$diferencia,'ventas_efectivo'=>$ef,'ventas_tarjeta'=>$tar,'ventas_transferencia'=>$trans,
      'reembolsos_declarados'=>$reembolsosDeclarados,'alertas'=>array_values(array_unique($alertas)),'estado'=>count($alertas)?'REVISAR':($contado===null?'PENDIENTE':'CONCILIADO')];
}
/** Consulta acotada para un único turno: datos POS y documentos disponibles. Nunca hace INSERT/UPDATE. */
function gdcd_turno(PDO $db,array $t,bool $hasRetiros,bool $hasRev):array {
    $tid=(int)$t['id'];$pagos=['EFECTIVO'=>0,'TARJETA'=>0,'TRANSFERENCIA'=>0];$nPagos=0;
    foreach(gdcd_q($db,"SELECT pg.metodo,pg.estado,pg.monto FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND v.estado='PAGADA'",[$tid])->fetchAll(PDO::FETCH_ASSOC) as $p){
        if($p['estado']==='CONFIRMADO'&&isset($pagos[$p['metodo']]))$pagos[$p['metodo']]+=gdcd_cents($p['monto']);
        elseif($p['estado']==='PENDIENTE')$nPagos++;
    }
    $movs=gdcd_q($db,'SELECT id,tipo,monto,motivo,creado_en FROM pos_movimientos_caja WHERE turno_id=? ORDER BY creado_en,id',[$tid])->fetchAll(PDO::FETCH_ASSOC);
    $retiros=$hasRetiros?gdcd_q($db,'SELECT id,movimiento_id,importe,creado_en FROM gyd_retiros_boveda WHERE turno_id=? ORDER BY id',[$tid])->fetchAll(PDO::FETCH_ASSOC):[];
    $a=gdcd_clasificar($movs,$retiros);$alertas=$a['alertas'];
    if(!$hasRetiros)$alertas[]='No está disponible la tabla de comprobantes de retiro; los retiros requieren revisión documental.';
    if($nPagos)$alertas[]='Existen pagos pendientes de confirmación en ventas pagadas.';
    $cancelaciones=0;$refundCash=0;$refundAll=0;$refundList=[];
    if($hasRev){
        $refundList=gdcd_q($db,"SELECT r.id,r.tipo,r.estado,r.medio_reembolso,r.importe,r.creado_en,v.folio FROM pos_reversiones r JOIN pos_ventas v ON v.id=r.venta_id WHERE (r.turno_reembolso_id=? OR (r.tipo='CANCELACION_TOTAL' AND v.turno_id=?)) AND r.estado='APLICADA' ORDER BY r.id",[$tid,$tid])->fetchAll(PDO::FETCH_ASSOC);
        foreach($refundList as $r){if($r['tipo']==='CANCELACION_TOTAL')$cancelaciones++;if($r['medio_reembolso']==='EFECTIVO')$refundCash+=gdcd_cents($r['importe']);$refundAll+=gdcd_cents($r['importe']);}
        if($a['totales']['reembolsos']!==$refundCash && ($refundCash!==0||$a['totales']['reembolsos']!==0))$alertas[]='Reembolsos de efectivo declarados y movimientos de devolución no coinciden; revisar cronología y turno de origen.';
    }elseif($a['totales']['reembolsos'])$alertas[]='No existe tabla de reversiones para cotejar movimientos de devolución.';
    $contado=$t['efectivo_contado']===null?null:gdcd_cents($t['efectivo_contado']);
    $dGuardada=$t['diferencia_efectivo']===null?null:gdcd_cents($t['diferencia_efectivo']);
    $res=gdcd_calcular(gdcd_cents($t['fondo_inicial']),$pagos,$a['totales'],$contado,$dGuardada,$alertas,$cancelaciones,$refundCash);
    if($t['estado']==='ABIERTO' && $res['estado']==='CONCILIADO')$res['estado']='ABIERTO';
    if($t['estado']==='ABIERTO' && $contado===null && $res['estado']==='PENDIENTE')$res['estado']='ABIERTO';
    return $t+['pagos'=>$pagos,'movimientos'=>$a['lineas'],'retiros'=>$retiros,'reversiones'=>$refundList,'reembolsos_total'=>$refundAll,'cancelaciones'=>$cancelaciones,'otros'=>$a['totales'],'resultado'=>$res];
}
