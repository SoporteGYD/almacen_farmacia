<?php
declare(strict_types=1);
/**
 * Rewards por partida: guarda asignación de puntos al cobrar y revierte
 * únicamente lo correspondiente a devoluciones documentadas.
 * Requiere una transacción de venta/devolución ya iniciada por el POS.
 */
final class GydRewardsLineasV47 {
    private static function q(PDO $db, string $sql, array $params=[]): PDOStatement {
        $s=$db->prepare($sql); $s->execute($params); return $s;
    }
    /** Distribución determinista que conserva exactamente todos los puntos. */
    public static function distribuir(array $lineas, int $puntos): array {
        if($puntos<0)throw new DomainException('Puntos negativos.');
        $total=0;
        foreach($lineas as $l){
            $m=(int)($l['importe_elegible_centavos']??0);
            if($m<0)throw new DomainException('Importe elegible inválido.');
            $total+=$m;
        }
        if($puntos>0 && $total<1)throw new DomainException('La venta tiene puntos sin partidas elegibles.');
        $acumulado=0; $resultado=[];
        foreach($lineas as $l){
            $m=(int)($l['importe_elegible_centavos']??0);
            $asignados=($total>0 && $puntos>0)
               ? intdiv(($acumulado+$m)*$puntos,$total)-intdiv($acumulado*$puntos,$total)
               : 0;
            $acumulado+=$m;
            $l['puntos_asignados']=$asignados;
            $resultado[]=$l;
        }
        if(array_sum(array_column($resultado,'puntos_asignados'))!==$puntos)
            throw new LogicException('No cuadran los puntos asignados.');
        return $resultado;
    }
    public static function registrarVenta(PDO $db,int $cliente,int $venta,array $lineas,int $puntos):void {
        if(!$db->inTransaction())throw new LogicException('La asignación requiere transacción de cobro.');
        $l=self::distribuir($lineas,$puntos);
        $r=self::q($db,'SELECT id,producto_id,presentacion,cantidad FROM pos_venta_detalle WHERE venta_id=? ORDER BY id',[$venta])->fetchAll(PDO::FETCH_ASSOC);
        if(count($r)!==count($l))throw new DomainException('El detalle del cobro no coincide con los puntos. No se registró la venta.');
        foreach($r as $i=>$d){
            $a=$l[$i];
            if((int)$d['producto_id']!==(int)($a['producto_id']??0) ||
               strtoupper((string)$d['presentacion'])!==strtoupper((string)($a['presentacion']??'')) ||
               (int)$d['cantidad']!==(int)($a['cantidad']??0))
                throw new DomainException('Partida inconsistente en el cálculo de Rewards.');
            self::q($db,'INSERT INTO gyd_rewards_lineas_v47
                (venta_id,detalle_id,cliente_id,cantidad_presentaciones,ingreso_elegible_centavos,puntos_asignados)
                VALUES (?,?,?,?,?,?)',[
                $venta,(int)$d['id'],$cliente,(int)$d['cantidad'],
                (int)$a['importe_elegible_centavos'],(int)$a['puntos_asignados']
            ]);
        }
    }
    /** Aplica el movimiento de puntos en la MISMA transacción de la devolución. */
    public static function aplicarReversion(PDO $db,int $reversion,int $venta,string $tipo,?int $detalle,int $usuario):void {
        if(!$db->inTransaction())throw new LogicException('La reversión requiere transacción.');
        $cv=self::q($db,'SELECT cliente_id,puntos_otorgados FROM gyd_clientes_ventas WHERE venta_id=? FOR UPDATE',[$venta])->fetch(PDO::FETCH_ASSOC);
        if(!$cv)return; // venta de mostrador o sin cliente
        $cliente=(int)$cv['cliente_id']; $puntosVenta=(int)$cv['puntos_otorgados'];
        if($tipo==='CANCELACION_TOTAL'){
            // V37 usa esta clave; no se duplica la reversión en la conciliación posterior.
            $clave='V37-CANCELACION-'.$venta;
            $ya=self::q($db,'SELECT id FROM gyd_puntos_movimientos WHERE origen_clave=? FOR UPDATE',[$clave])->fetchColumn();
            if(!$ya && $puntosVenta>0){
                self::q($db,"INSERT INTO gyd_puntos_movimientos
                 (cliente_id,venta_id,origen_clave,tipo,puntos,valor_reserva_centavos,detalle,creado_por)
                 VALUES (?,?,?,'REVERSO',?,?,?,?)",[
                  $cliente,$venta,$clave,-$puntosVenta,-10*$puntosVenta,
                  'Anulación de puntos por cancelación total de venta',$usuario
                ]);
            }
            self::q($db,"UPDATE gyd_rewards_revision_v37 SET estado_revision='CUBIERTA_CANCELACION',revisada_en=NOW()
                WHERE venta_id=? AND estado_revision='PENDIENTE'",[$venta]);
            return;
        }
        if($tipo!=='DEVOLUCION_PARCIAL')return;
        if(!$detalle)throw new DomainException('La devolución parcial necesita una partida.');
        $l=self::q($db,'SELECT * FROM gyd_rewards_lineas_v47 WHERE venta_id=? AND detalle_id=? FOR UPDATE',[$venta,$detalle])->fetch(PDO::FETCH_ASSOC);
        if(!$l){
            if($puntosVenta===0){
                // Sin puntos que descontar; se registra igualmente la trazabilidad.
                self::q($db,"INSERT INTO gyd_rewards_devoluciones_v47
                    (reversion_id,venta_id,detalle_id,cliente_id,puntos_revertidos,estado)
                    VALUES (?,?,?,?,0,'APLICADA')",[$reversion,$venta,$detalle,$cliente]);
            } else {
                // Venta histórica sin desglose: no inventar proporciones.
                self::q($db,"INSERT INTO gyd_rewards_revision_v37
                   (reversion_id,venta_id,cliente_id,tipo_reversion,estado_revision)
                   VALUES (?,?,?,'DEVOLUCION_PARCIAL','PENDIENTE')
                   ON DUPLICATE KEY UPDATE tipo_reversion=VALUES(tipo_reversion)",[$reversion,$venta,$cliente]);
            }
            return;
        }
        $vendidas=(int)$l['cantidad_presentaciones'];$asignados=(int)$l['puntos_asignados'];
        if($vendidas<1||$asignados<0)throw new DomainException('Asignación de puntos inválida.');
        $totalDev=(int)self::q($db,"SELECT COALESCE(SUM(cantidad_presentaciones),0)
           FROM pos_reversiones WHERE venta_id=? AND detalle_id=?
           AND tipo='DEVOLUCION_PARCIAL' AND estado='APLICADA'",[$venta,$detalle])->fetchColumn();
        if($totalDev<1||$totalDev>$vendidas)throw new DomainException('Devoluciones acumuladas fuera del saldo vendido.');
        $ya=(int)self::q($db,'SELECT COALESCE(SUM(puntos_revertidos),0)
             FROM gyd_rewards_devoluciones_v47 WHERE detalle_id=? AND estado=\'APLICADA\'',[$detalle])->fetchColumn();
        $objetivo=intdiv($asignados*$totalDev,$vendidas);
        $reverso=$objetivo-$ya;
        if($reverso<0 || $reverso>$asignados)throw new DomainException('Desfase en las reversiones de puntos. Requiere revisión.');
        self::q($db,"INSERT INTO gyd_rewards_devoluciones_v47
            (reversion_id,venta_id,detalle_id,cliente_id,puntos_revertidos,estado)
            VALUES (?,?,?,?,?,'APLICADA')",[$reversion,$venta,$detalle,$cliente,$reverso]);
        if($reverso>0){
            self::q($db,"INSERT INTO gyd_puntos_movimientos
               (cliente_id,venta_id,origen_clave,tipo,puntos,valor_reserva_centavos,detalle,creado_por)
               VALUES (?,?,?,'REVERSO',?,?,?,?)",[
                 $cliente,$venta,'DEVOLUCION-'.$reversion,-$reverso,-10*$reverso,
                 'Reversa proporcional por devolución de partida '.$detalle,$usuario
            ]);
        }
    }
}
