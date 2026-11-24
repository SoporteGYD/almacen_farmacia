<?php
declare(strict_types=1);
/** Resumen de conciliación G&D (SOLO LECTURA). No actualiza turnos ni movimientos. */
require_once __DIR__.'/gyd_retiros_directos.php';
require_once __DIR__.'/pos_corte_denominaciones.php';

function gcd_q(PDO $db,string $sql,array $params=[]):PDOStatement {
    $st=$db->prepare($sql);$st->execute($params);return $st;
}
/** Convierte importes DECIMAL de consulta, incluidos saldos/diferencias negativos.
 * No cambia gd_cents()/gr_cents() utilizados para validar retiros y cobros.
 */
function gcd_centavos_reporte(mixed $dato,string $campo):int {
    $valor=trim((string)$dato);
    if(!preg_match('/^(-?)([0-9]{1,16})(?:\.([0-9]{1,18}))?$/D',$valor,$m)) {
        throw new DomainException('Formato de importe incompatible en '.$campo.'. Revisa ese dato de caja sin modificar el corte.');
    }
    $dec=$m[3]??'';
    if(strlen($dec)>2 && trim(substr($dec,2),'0')!=='') {
        throw new DomainException('El importe de '.$campo.' tiene más de dos decimales significativos.');
    }
    $entero=(int)$m[2];
    $cent=$entero*100+(int)str_pad(substr($dec,0,2),2,'0');
    return ($m[1]==='-'?-1:1)*$cent;
}
/** Reutiliza el saldo oficial del POS. Sólo si el validador legado rechaza
 * una representación DECIMAL (p.ej. diferencia negativa), reconstruye para
 * CONSULTA el mismo desglose y deja una advertencia de auditoría.
 */
function gcd_saldo_lectura(PDO $db,int $turnoId,array &$avisos):array {
    try { return gd_cash_totals($db,$turnoId); }
    catch(DomainException $ex) {
        if($ex->getMessage()!=='Importe inválido; utiliza hasta dos decimales.') throw $ex;
    }
    $t=gcd_q($db,'SELECT fondo_inicial,caja_id,usuario_apertura_id,estado FROM pos_turnos WHERE id=?',[$turnoId])->fetch(PDO::FETCH_ASSOC);
    if(!$t)throw new DomainException('Turno no encontrado.');
    $cobros=gcd_q($db,"SELECT COALESCE(SUM(pg.monto),0) FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND pg.metodo='EFECTIVO' AND pg.estado='CONFIRMADO' AND (v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS (SELECT 1 FROM pos_reversiones grc WHERE grc.venta_id=v.id AND grc.tipo='CANCELACION_TOTAL' AND grc.estado='APLICADA' AND grc.turno_reembolso_id=v.turno_id)))",[$turnoId])->fetchColumn();
    $movs=gcd_q($db,"SELECT tipo,COALESCE(SUM(monto),0) monto FROM pos_movimientos_caja WHERE turno_id=? AND tipo IN ('INGRESO','RETIRO') GROUP BY tipo",[$turnoId])->fetchAll(PDO::FETCH_ASSOC);
    $mov=['INGRESO'=>0,'RETIRO'=>0];
    foreach($movs as $item) $mov[$item['tipo']]=gcd_centavos_reporte($item['monto'],'movimientos de caja ('.$item['tipo'].')');
    $fondo=gcd_centavos_reporte($t['fondo_inicial'],'fondo inicial');
    $ventas=gcd_centavos_reporte($cobros,'cobros en efectivo');
    $avisos[]='La validación monetaria del módulo de retiros rechazó un formato del saldo. Este resumen se calculó en modo de solo lectura con los mismos movimientos; revisa el dato de origen antes del cierre.';
    return ['turno'=>$t,'fondo'=>$fondo,'ventas'=>$ventas,'ingresos'=>$mov['INGRESO'],'retiros'=>$mov['RETIRO'],'esperado'=>$fondo+$ventas+$mov['INGRESO']-$mov['RETIRO']];
}
function gcd_money(int $centavos):string {
    return ($centavos<0?'-':'').'$'.number_format(abs($centavos)/100,2,'.',',');
}
function gcd_h(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
/** Utilizada también por las pruebas unitarias, sin consultar una base de datos. */
function gcd_conciliar_montos(int $fondo,int $efectivo,int $ingresos,int $retiros,?int $contado):array {
    $esperado=$fondo+$efectivo+$ingresos-$retiros;
    return ['fondo'=>$fondo,'efectivo'=>$efectivo,'ingresos'=>$ingresos,'retiros'=>$retiros,
        'esperado'=>$esperado,'contado'=>$contado,'diferencia'=>$contado===null?null:$contado-$esperado];
}
/** Incluye ventas canceladas SOLO cuando su reembolso consta en el mismo turno.
 * Mantiene la misma regla que gd_cash_totals() y cerrarTurno() actuales.
 */
function gcd_ventas_vigentes():string {
    return "(v.estado='PAGADA' OR (v.estado='CANCELADA' AND EXISTS 
       (SELECT 1 FROM pos_reversiones r WHERE r.venta_id=v.id AND r.tipo='CANCELACION_TOTAL' AND r.estado='APLICADA' AND r.turno_reembolso_id=v.turno_id)))";
}
function gcd_detalle_turno(PDO $db,int $turnoId):array {
    if($turnoId<1)throw new DomainException('Turno inválido.');
    $turno=gcd_q($db,"SELECT t.*,c.nombre AS caja,s.nombre AS sucursal,
            ini.nombre AS abierto_por,fin.nombre AS cerrado_por
        FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id
        JOIN pos_sucursales s ON s.id=c.sucursal_id
        JOIN usuarios ini ON ini.id=t.usuario_apertura_id
        LEFT JOIN usuarios fin ON fin.id=t.usuario_cierre_id
        WHERE t.id=? LIMIT 1",[$turnoId])->fetch(PDO::FETCH_ASSOC);
    if(!$turno)throw new DomainException('El turno no existe.');
    $avisos=[];
    $s=gcd_saldo_lectura($db,$turnoId,$avisos); // Mismo cálculo de caja; lectura tolerante ante formato legado.
    $q=gcd_q($db,"SELECT pg.metodo,COALESCE(SUM(pg.monto),0) monto,COUNT(*) operaciones
        FROM pos_pagos pg JOIN pos_ventas v ON v.id=pg.venta_id
        WHERE v.turno_id=? AND pg.estado='CONFIRMADO' AND ".gcd_ventas_vigentes()."
        GROUP BY pg.metodo",[$turnoId]);
    $pagos=['EFECTIVO'=>0,'TARJETA'=>0,'TRANSFERENCIA'=>0];
    $registrosPago=['EFECTIVO'=>0,'TARJETA'=>0,'TRANSFERENCIA'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(!array_key_exists($r['metodo'],$pagos))throw new DomainException('Método de cobro desconocido en el turno.');
        $pagos[$r['metodo']]=gcd_centavos_reporte($r['monto'],'pagos con '.$r['metodo']);$registrosPago[$r['metodo']]=(int)$r['operaciones'];
    }
    $ventas=(int)gcd_q($db,"SELECT COUNT(*) FROM pos_ventas v WHERE v.turno_id=? AND ".gcd_ventas_vigentes(),[$turnoId])->fetchColumn();
    $reembolsos=['EFECTIVO'=>0,'TARJETA'=>0,'TRANSFERENCIA'=>0,'OTRO'=>0];
    foreach(gcd_q($db,"SELECT medio_reembolso medio,COALESCE(SUM(importe),0) monto
        FROM pos_reversiones WHERE turno_reembolso_id=? AND estado='APLICADA'
        AND tipo IN ('CANCELACION_TOTAL','DEVOLUCION_PARCIAL') GROUP BY medio_reembolso",[$turnoId])->fetchAll(PDO::FETCH_ASSOC) as $r){
        $medio=(string)($r['medio']??'');
        if(array_key_exists($medio,$reembolsos))$reembolsos[$medio]=gcd_centavos_reporte($r['monto'],'reembolsos '.$medio);
    }
    $boveda=gd_retiros_turno($db,$turnoId);
    $totalBoveda=0;
    foreach($boveda as $retiro) $totalBoveda+=gcd_centavos_reporte($retiro['importe'],'retiro a bóveda');
    $movimientos=gcd_q($db,"SELECT tipo,COALESCE(SUM(monto),0) monto,COUNT(*) cantidad
        FROM pos_movimientos_caja WHERE turno_id=? GROUP BY tipo",[$turnoId])->fetchAll(PDO::FETCH_ASSOC);
    $mov=['INGRESO'=>0,'RETIRO'=>0,'AJUSTE'=>0];
    foreach($movimientos as $r){if(isset($mov[$r['tipo']]))$mov[$r['tipo']]=gcd_centavos_reporte($r['monto'],'movimientos de caja '.$r['tipo']);}
    $contado=$turno['efectivo_contado']===null?null:gcd_centavos_reporte($turno['efectivo_contado'],'efectivo contado');
    $calc=gcd_conciliar_montos($s['fondo'],$pagos['EFECTIVO'],$s['ingresos'],$s['retiros'],$contado);
    if($calc['esperado']!==$s['esperado'])$avisos[]='El desglose de efectivo no coincide con el cálculo de control; consulta Administración antes de cerrar.';
    if($mov['AJUSTE']!==0)$avisos[]='Existen movimientos AJUSTE que no forman parte del saldo de corte automático. Requieren revisión manual.';
    if($totalBoveda>$s['retiros'])$avisos[]='Los comprobantes de bóveda superan los retiros del libro de caja.';
    if($reembolsos['EFECTIVO']>$s['retiros'])$avisos[]='Los reembolsos en efectivo superan el total de salidas del libro; revisa duplicados o registros antiguos.';
    $denom=[];$desglose=null;$totalDenom=null;$operadorConteo=null;
    $d=gcd_q($db,'SELECT d.desglose_json,d.total_contado,d.usuario_id,d.creado_en,u.nombre operador
       FROM pos_cortes_denominaciones d LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE d.turno_id=? LIMIT 1',[$turnoId])->fetch(PDO::FETCH_ASSOC);
    if($d){
        $operadorConteo=(string)($d['operador']??'');
        $decoded=json_decode((string)$d['desglose_json'],true);
        if(!is_array($decoded))$avisos[]='El desglose de billetes y monedas guardado no tiene formato válido.';
        else {
            try{$r=PosCorteDenominaciones::calcular($decoded);$desglose=$r['detalle'];$totalDenom=$r['total_centavos'];}
            catch(Throwable $e){$avisos[]='El conteo guardado contiene denominaciones inválidas.';}
        }
        if($totalDenom!==null && $totalDenom!==gcd_centavos_reporte($d['total_contado'],'total de denominaciones'))
            $avisos[]='El conteo por denominaciones no coincide con el total registrado en la tabla del corte.';
        if($contado!==null && $totalDenom!==null && $totalDenom!==$contado)
            $avisos[]='El conteo por denominaciones no coincide con el efectivo contado del turno.';
    } elseif($turno['estado']==='CERRADO')$avisos[]='Este turno cerrado no tiene desglose por denominaciones guardado.';
    if($contado!==null && $turno['diferencia_efectivo']!==null && $calc['diferencia']!==gcd_centavos_reporte($turno['diferencia_efectivo'],'diferencia histórica del corte'))
        $avisos[]='El resultado calculado hoy difiere del importe histórico almacenado al cierre. No se ha modificado el cierre.';
    $firma=gcd_q($db,'SELECT a.autorizador_rol,a.modalidad,sol.nombre solicitante,aut.nombre autorizador
       FROM gyd_cortes_autorizaciones a
       LEFT JOIN usuarios sol ON sol.id=a.cajero_id
       LEFT JOIN usuarios aut ON aut.id=a.autorizador_id
       WHERE a.turno_id=? ORDER BY a.turno_id DESC LIMIT 1',[$turnoId])->fetch(PDO::FETCH_ASSOC)?:[];
    $canceladas=(int)gcd_q($db,"SELECT COUNT(*) FROM pos_reversiones WHERE estado='APLICADA' AND tipo='CANCELACION_TOTAL' AND turno_reembolso_id=?",[$turnoId])->fetchColumn();
    $parciales=(int)gcd_q($db,"SELECT COUNT(*) FROM pos_reversiones WHERE estado='APLICADA' AND tipo='DEVOLUCION_PARCIAL' AND turno_reembolso_id=?",[$turnoId])->fetchColumn();
    return ['turno'=>$turno,'saldo'=>$s,'calc'=>$calc,'pagos'=>$pagos,'registrosPago'=>$registrosPago,
        'ventas'=>$ventas,'reembolsos'=>$reembolsos,'movimientos'=>$mov,'boveda'=>$boveda,
        'bovedaTotal'=>$totalBoveda,'denominaciones'=>$desglose,'operadorConteo'=>$operadorConteo,
        'totalDenom'=>$totalDenom,'firma'=>$firma,'canceladas'=>$canceladas,
        'parciales'=>$parciales,'avisos'=>$avisos];
}
