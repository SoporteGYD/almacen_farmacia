<?php
declare(strict_types=1);
/**
 * G&D V37 · Puente conservador de reversos de puntos, exclusivamente prueba:3307.
 * No cambia ventas, cobros, devoluciones ni existencias. Canjes inhabilitados.
 * La devolución parcial requiere integración de detalle verificado de PosReversiones.php.
 */
final class GydRewardsReversionesV37 {
    private static function q(PDO $db,string $sql,array $p=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($p);return $s;
    }
    public static function asegurarLaboratorio(PDO $db):void {
        $r=$db->query('SELECT DATABASE() AS base, @@port AS puerto')->fetch(PDO::FETCH_ASSOC);
        if(strtolower((string)($r['base']??''))!=='prueba'||(int)($r['puerto']??0)!==3307)
            throw new RuntimeException('Operación limitada a base prueba:3307.');
    }
    public static function verificarEsquema(PDO $db):void {
        self::asegurarLaboratorio($db);
        foreach([
            'gyd_clientes_ventas'=>['venta_id','cliente_id','puntos_otorgados'],
            'gyd_puntos_movimientos'=>['origen_clave','cliente_id','venta_id','tipo','puntos','valor_reserva_centavos'],
            'pos_ventas'=>['id','estado'],
            'pos_reversiones'=>['id','venta_id','tipo','estado'],
            'gyd_rewards_revision_v37'=>['reversion_id','venta_id','cliente_id','tipo_reversion','estado_revision']
        ] as $tabla=>$requeridos){
            $cols=self::q($db,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$tabla])->fetchAll(PDO::FETCH_COLUMN);
            foreach($requeridos as $c)if(!in_array($c,$cols,true))throw new RuntimeException('Falta columna '.$tabla.'.'.$c);
        }
    }
    /** Lectura sin efectos laterales: no muestra como utilizables puntos de ventas anuladas. */
    public static function saldoVisible(PDO $db,int $clienteId):int {
        $n=self::q($db,"SELECT COALESCE(SUM(CASE WHEN m.venta_id IS NOT NULL AND
             (v.id IS NULL OR v.estado <> 'PAGADA' OR EXISTS (
                SELECT 1 FROM pos_reversiones pr WHERE pr.venta_id=m.venta_id
                AND pr.estado='APLICADA' AND pr.tipo='CANCELACION_TOTAL'))
                THEN 0 ELSE m.puntos END),0)
            FROM gyd_puntos_movimientos m
            LEFT JOIN pos_ventas v ON v.id=m.venta_id WHERE m.cliente_id=?",[$clienteId])->fetchColumn();
        return max(0,(int)$n);
    }
    /** Devuelve true cuando hay una devolución aplicada NO total que aún necesita mapeo por renglón. */
    public static function hayParcialesPendientes(PDO $db,int $clienteId):bool {
        return (bool)self::q($db,"SELECT 1 FROM gyd_clientes_ventas cv
            JOIN pos_ventas v ON v.id=cv.venta_id
            JOIN pos_reversiones pr ON pr.venta_id=cv.venta_id
            WHERE cv.cliente_id=? AND v.estado='PAGADA' AND pr.estado='APLICADA'
            AND pr.tipo<>'CANCELACION_TOTAL'
            AND NOT EXISTS (SELECT 1 FROM pos_reversiones pt
              WHERE pt.venta_id=cv.venta_id AND pt.tipo='CANCELACION_TOTAL' AND pt.estado='APLICADA')
            LIMIT 1",[$clienteId])->fetchColumn();
    }
    /** Acceso a nivel de venta para mostrar aviso en historial. */
    public static function hayParcialesVenta(PDO $db,int $ventaId):bool {
        return (bool)self::q($db,"SELECT 1 FROM pos_reversiones pr JOIN pos_ventas v ON v.id=pr.venta_id
            WHERE pr.venta_id=? AND v.estado='PAGADA' AND pr.estado='APLICADA'
            AND pr.tipo<>'CANCELACION_TOTAL' AND NOT EXISTS(
              SELECT 1 FROM pos_reversiones pt WHERE pt.venta_id=pr.venta_id AND pt.estado='APLICADA' AND pt.tipo='CANCELACION_TOTAL') LIMIT 1",[$ventaId])->fetchColumn();
    }
    /** Transacción propia: revierte una cancelación total con origen único por venta. */
    public static function registrarCancelacion(PDO $db,int $ventaId):string {
        if($db->inTransaction())throw new LogicException('registrarCancelacion debe invocarse fuera de otra transacción.');
        $db->beginTransaction();
        try {
            $r=self::q($db,"SELECT cv.venta_id,cv.cliente_id,cv.puntos_otorgados,v.estado
                FROM gyd_clientes_ventas cv JOIN pos_ventas v ON v.id=cv.venta_id
                WHERE cv.venta_id=? FOR UPDATE",[$ventaId])->fetch(PDO::FETCH_ASSOC);
            if(!$r){$db->commit();return 'OMITIDA_SIN_CLIENTE';}
            $total=((string)$r['estado']==='CANCELADA') || (bool)self::q($db,"SELECT 1 FROM pos_reversiones
                WHERE venta_id=? AND estado='APLICADA' AND tipo='CANCELACION_TOTAL' LIMIT 1",[$ventaId])->fetchColumn();
            if(!$total){$db->commit();return 'OMITIDA_NO_CANCELADA';}
            $clave='V37-CANCELACION-'.$ventaId;
            $ya=self::q($db,'SELECT id FROM gyd_puntos_movimientos WHERE origen_clave=? FOR UPDATE',[$clave])->fetchColumn();
            $puntos=(int)$r['puntos_otorgados'];
            if(!$ya && $puntos>0){
                self::q($db,"INSERT INTO gyd_puntos_movimientos
                    (cliente_id,venta_id,origen_clave,tipo,puntos,valor_reserva_centavos,detalle)
                    VALUES(?,?,?,'REVERSO',?,?,?)",[
                    (int)$r['cliente_id'],$ventaId,$clave,-$puntos,-$puntos*10,
                    'Reverso auditado V37 de cancelación total; no modifica el POS'
                ]);
            }
            self::q($db,"UPDATE gyd_rewards_revision_v37 SET estado_revision='CUBIERTA_CANCELACION'
                WHERE venta_id=? AND estado_revision='PENDIENTE'",[$ventaId]);
            $db->commit();
            return $ya?'YA_REGISTRADA':($puntos>0?'REVERSADA':'SIN_PUNTOS');
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Captura evidencia de devoluciones parciales, SIN estimar puntos con datos incompletos. */
    public static function registrarPendientes(PDO $db,?int $ventaId=null):int {
        $where=$ventaId!==null?' AND cv.venta_id=?':'';
        $rows=self::q($db,"SELECT pr.id,pr.venta_id,cv.cliente_id,pr.tipo
            FROM pos_reversiones pr JOIN gyd_clientes_ventas cv ON cv.venta_id=pr.venta_id
            JOIN pos_ventas v ON v.id=cv.venta_id
            WHERE v.estado='PAGADA' AND pr.estado='APLICADA'
              AND pr.tipo<>'CANCELACION_TOTAL'
              AND NOT EXISTS(SELECT 1 FROM pos_reversiones pt WHERE pt.venta_id=pr.venta_id
                 AND pt.tipo='CANCELACION_TOTAL' AND pt.estado='APLICADA')".$where.' ORDER BY pr.id',
            $ventaId===null?[]:[$ventaId])->fetchAll(PDO::FETCH_ASSOC);
        $n=0;
        foreach($rows as $r){
            $s=self::q($db,"INSERT INTO gyd_rewards_revision_v37
                (reversion_id,venta_id,cliente_id,tipo_reversion,estado_revision)
                VALUES (?,?,?,?,'PENDIENTE')
                ON DUPLICATE KEY UPDATE tipo_reversion=VALUES(tipo_reversion)",[
                (int)$r['id'],(int)$r['venta_id'],(int)$r['cliente_id'],(string)$r['tipo']
            ]);
            if($s->rowCount()===1)$n++;
        }
        return $n;
    }
    public static function ventasCanceladas(PDO $db,?int $ventaId=null):array {
        $where=$ventaId!==null?' AND cv.venta_id=?':'';
        return array_map('intval',self::q($db,"SELECT DISTINCT cv.venta_id
          FROM gyd_clientes_ventas cv JOIN pos_ventas v ON v.id=cv.venta_id
          WHERE (v.estado='CANCELADA' OR EXISTS(SELECT 1 FROM pos_reversiones pr
             WHERE pr.venta_id=cv.venta_id AND pr.tipo='CANCELACION_TOTAL' AND pr.estado='APLICADA'))".$where.' ORDER BY cv.venta_id',
          $ventaId===null?[]:[$ventaId])->fetchAll(PDO::FETCH_COLUMN));
    }
    public static function pendientes(PDO $db,int $limit=20):array {
        $limit=max(1,min(100,$limit));
        return self::q($db,"SELECT r.reversion_id,r.venta_id,r.tipo_reversion,r.estado_revision
            FROM gyd_rewards_revision_v37 r
            WHERE r.estado_revision='PENDIENTE'
            ORDER BY r.reversion_id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    }
}
