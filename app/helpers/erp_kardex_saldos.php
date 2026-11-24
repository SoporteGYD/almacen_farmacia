<?php
declare(strict_types=1);
/**
 * Consulta de saldos del Kardex: no escribe en las tablas del ERP.
 * Importante: lo reconstruido NO es una evidencia de stock histórico.
 */
function kg_sa_key(array $r): string {return (string)($r['origen']??'').'#'.(string)($r['id']??'');}
function kg_sa_dimensions(): array {return ['pos','farmacia','almacen'];}
function kg_sa_nullable_int(mixed $v): ?int {return $v===null||$v===false||$v===''||!is_numeric($v)?null:(int)$v;}
function kg_sa_checkpoints(PDO $db, array $events, array &$warnings): array {
    $ids=[];
    foreach($events as $r) if(($r['origen']??'')==='erp_conciliacion_ajustes' && (int)($r['id']??0)>0) $ids[]=(int)$r['id'];
    $ids=array_values(array_unique($ids));
    if(!$ids||!kg_table($db,'erp_conciliacion_ajustes')) return [];
    try {
        $sql='SELECT id,destino,estado,cantidad_anterior,cantidad_objetivo FROM erp_conciliacion_ajustes WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $st=$db->prepare($sql);$st->execute($ids);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']]=$r;
        return $out;
    }catch(Throwable $e){error_log('Kardex saldos: '.$e->getMessage());$warnings[]='No se pudieron consultar saldos guardados de ajustes.';return [];}
}
/**
 * El recorrido va desde la existencia ACTUAL hasta los eventos históricos.
 * Sólo brinda reconstrucciones sujetas a que todas las modificaciones estén registradas.
 * Un delta null rompe la cadena para esa dimensión (nunca se inventa un saldo).
 */
function kg_sa_calcular(array $filtrados, array $completos, array $snapshot, array $checkpoints=[], bool $hayHuecos=false): array {
    $dimensions=kg_sa_dimensions();
    $saldo=[];
    foreach($dimensions as $d) $saldo[$d]=kg_sa_nullable_int($snapshot[$d]??null);
    $marcas=[];
    // No reconstruir una historia truncada: el orden/deltas puede ser incompleto.
    if($hayHuecos) foreach($dimensions as $d) $saldo[$d]=null;
    foreach($completos as $r){
        $key=kg_sa_key($r);
        $result=[];
        $check=(($r['origen']??'')==='erp_conciliacion_ajustes')?($checkpoints[(int)$r['id']]??null):null;
        $applyCheck=is_array($check)&&($check['estado']??'')==='APLICADO'&&$check['cantidad_anterior']!==null;
        $checkDimension=$applyCheck?match((string)$check['destino']){'POS_GENERAL'=>'pos','FARMACIA'=>'farmacia','ALMACEN'=>'almacen',default=>null}:null;
        foreach($dimensions as $d){
            $delta=$r[$d]??null;
            // Ajustes no aplicados jamás deben descontarse del inventario.
            if($check!==null && !$applyCheck) $delta=($check['estado']==='APLICADO'?null:0);
            if($applyCheck && $checkDimension===$d) $delta=(int)$check['cantidad_objetivo']-(int)$check['cantidad_anterior'];
            if($applyCheck && $checkDimension==='almacen'&&$d==='pos') $delta=null;
            $antes=null;$despues=null;$estado='NO_VERIFICABLE';$diagnostico='';
            if($checkDimension===$d){
                // Valores almacenados en el asiento del ajuste, no reconstruidos.
                $antes=(int)$check['cantidad_anterior'];$despues=(int)$check['cantidad_objetivo'];
                $estado='REGISTRADO';
                if($saldo[$d]!==null && $saldo[$d]!==$despues) {
                    $estado='DIFERENCIA';
                    $diagnostico='El saldo derivado de eventos posteriores no coincide con el valor guardado en este ajuste.';
                }
                $saldo[$d]=$antes;
            }elseif($saldo[$d]!==null && $delta!==null){
                $despues=$saldo[$d];$antes=$despues-(int)$delta;
                if($antes<0){$antes=null;$despues=null;$saldo[$d]=null;$estado='NO_VERIFICABLE';$diagnostico='La reconstrucción produciría una existencia negativa.';}
                else{$saldo[$d]=$antes;$estado='RECONSTRUIDO';}
            }else{
                // Si hay un evento cuyo efecto se desconoce, todos los anteriores
                // quedan sin reconstrucción fiable para esta dimensión.
                if($delta===null)$saldo[$d]=null;
            }
            $result[$d]=['antes'=>$antes,'despues'=>$despues,'delta'=>$delta,'estado'=>$estado,'detalle'=>$diagnostico];
        }
        $marcas[$key]=$result;
    }
    foreach($filtrados as &$r){
        $key=kg_sa_key($r);$r['saldos']=$marcas[$key]??[];
        foreach($dimensions as $d) if(!isset($r['saldos'][$d]))$r['saldos'][$d]=['antes'=>null,'despues'=>null,'delta'=>$r[$d]??null,'estado'=>'NO_VERIFICABLE','detalle'=>'Sin referencia suficiente'];
    }
    unset($r);
    return $filtrados;
}
/** Html de celda de Kardex por cada ubicación. */
function kg_sa_celda(array $r,string $dim):string {
    $s=$r['saldos'][$dim]??[];
    $estado=(string)($s['estado']??'NO_VERIFICABLE');
    $antes=$s['antes']??null;$despues=$s['despues']??null;
    $delta=$s['delta']??($r[$dim]??null);
    $color=match($estado){'REGISTRADO'=>'good','DIFERENCIA'=>'bad','RECONSTRUIDO'=>'estimate',default=>'unknown'};
    $txt=match($estado){'REGISTRADO'=>'Registrado','DIFERENCIA'=>'Revisar diferencia','RECONSTRUIDO'=>'Reconstruido (no verificado)',default=>'No verificable'};
    $html='<div class="kg-saldo kg-'.$color.'">';
    if($antes!==null&&$despues!==null){
        $html.='<span class="kg-before">Antes <b>'.number_format((int)$antes,0,'.',',').'</b></span>';
        $html.='<span class="kg-arrow">→</span>';
        $html.='<span class="kg-after">Después <b>'.number_format((int)$despues,0,'.',',').'</b></span>';
    }else{$html.='<span class="kg-not-verified">— Sin saldo histórico comprobable —</span>';}
    $html.='<span class="kg-delta">Cambio: '.($delta===null?'—':(($delta>0?'+':'').number_format((int)$delta,0,'.',','))).'</span>';
    $html.='<span class="kg-cert">'.kg_h($txt).'</span>';
    if(!empty($s['detalle']))$html.='<span class="kg-detail">'.kg_h($s['detalle']).'</span>';
    return $html.'</div>';
}
function kg_sa_export(array $r,string $dim):array {
    $s=$r['saldos'][$dim]??[];
    return [$s['antes']??'', $s['despues']??'', $s['delta']??'', $s['estado']??'NO_VERIFICABLE'];
}
