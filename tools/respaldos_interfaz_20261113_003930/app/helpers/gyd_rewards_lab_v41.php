<?php
declare(strict_types=1);
/**
 * G&D V41 · canjes EXCLUSIVAMENTE VIRTUALES en base prueba:3307.
 * No interactúa con ventas POS, cortes, stock físico, puntos reales ni V39.
 */
require_once __DIR__.'/gyd_rewards_lab_v40.php';
final class GydRewardsLabV41 {
    private static function q(PDO $db,string $sql,array $params=[]):PDOStatement {
        $st=$db->prepare($sql);$st->execute($params);return $st;
    }
    private static function guard(PDO $db):void {GydRewardsLabV40::entorno($db);}
    private static function positive(mixed $v,string $name):int {
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        if($n===false)throw new DomainException('Identificador inválido: '.$name.'.');
        return (int)$n;
    }
    public static function saldo(PDO $db,int $clienteId):int {
        self::guard($db);
        $base=max(0,(int)GydClientesRewards::saldo($db,$clienteId));
        $usados=(int)self::q($db,'SELECT COALESCE(SUM(puntos_gastados),0) FROM gyd_rewards_lab_canjes_v41 WHERE cliente_id=?',[$clienteId])->fetchColumn();
        return max(0,$base-$usados);
    }
    public static function clientes(PDO $db):array {
        self::guard($db);
        $rows=GydRewardsLabV40::clientes($db);
        foreach($rows as &$r){
            $r['puntos_originales']=(int)$r['puntos'];
            $r['puntos']=self::saldo($db,(int)$r['id']);
        }unset($r);
        return $rows;
    }
    public static function premios(PDO $db,int $sid):array {
        self::guard($db);
        $sid=self::positive($sid,'sucursal');
        $rows=self::q($db,'SELECT l.sucursal_id,l.producto_id,l.precio_centavos,l.costo_centavos,l.stock_virtual,l.puntos_requeridos,l.escenario,
            p.descripcion,p.codigo,s.nombre AS sucursal
            FROM gyd_rewards_lab_productos_v40 l
            JOIN productos p ON p.id=l.producto_id AND p.estado=1
            JOIN pos_sucursales s ON s.id=l.sucursal_id AND s.activo=1
            WHERE l.sucursal_id=? ORDER BY (l.escenario=\'CANJE_ALEATORIO_V41\') DESC,l.escenario,l.producto_id LIMIT 250',[$sid])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){
            $e=GydRewardsLabV40::calculo((int)$row['precio_centavos'],(int)$row['costo_centavos'],(int)$row['stock_virtual'],(int)$row['puntos_requeridos'],2000000);
            $row['margen_pct']=$e['margen_bruto_pct'];
            $row['minimo_puntos']=$e['minimo_puntos'];
            $row['apto']=((bool)$e['simulacion_posible']);
            $row['motivo']=$e['razon'];
        }unset($row);
        return $rows;
    }
    /** Agrega sin reemplazar hasta N productos existentes aleatorios y elegibles por sucursal.
     * Costo y precio son referencias REALES de la base prueba; stock es VIRTUAL.
     */
    public static function agregarAleatorios(PDO $db,int $sid,int $actor,int $cantidad=12):array {
        self::guard($db);
        $sid=self::positive($sid,'sucursal');
        if($actor<1)throw new DomainException('Sesión sin usuario identificado.');
        if($cantidad<1||$cantidad>12)throw new DomainException('Máximo 12 premios por lote.');
        $s=self::q($db,'SELECT id FROM pos_sucursales WHERE id=? AND activo=1',[$sid])->fetchColumn();
        if(!$s)throw new DomainException('Sucursal no disponible.');
        // RAND sólo selecciona candidatos: NO altera el catálogo de productos ni existencias.
        // Se omiten productos ya configurados para respetar todos los escenarios existentes.
        $candidatos=self::q($db,'SELECT p.id,p.codigo,p.descripcion,pp.precio_publico,
          (SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=p.id AND l.costo_unitario>0) costo_maximo
          FROM productos p JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=?
          WHERE p.estado=1 AND pp.precio_publico>0
          AND NOT EXISTS(SELECT 1 FROM gyd_rewards_lab_productos_v40 z WHERE z.sucursal_id=? AND z.producto_id=p.id)
          ORDER BY RAND() LIMIT 1200',[$sid,$sid])->fetchAll(PDO::FETCH_ASSOC);
        $agregados=[];$omitidos=0;
        $db->beginTransaction();
        try {
            foreach($candidatos as $r){
                $precio=GydRewardsCatalogoV38::centavos((string)$r['precio_publico']);
                $costo=GydRewardsCatalogoV38::centavos($r['costo_maximo']===null?null:(string)$r['costo_maximo'],true);
                if($precio===null||$costo===null||$precio<=0||$costo<=0||$precio>50000000||$costo>50000000){$omitidos++;continue;}
                $eval=GydRewardsCatalogoV38::evaluarImportes($precio,$costo,true);
                if(!$eval['apto'] || $eval['minimo_puntos']===null || (int)$eval['minimo_puntos']>10000){$omitidos++;continue;}
                $pts=(int)$eval['minimo_puntos'];
                $stock=random_int(6,14);
                $st=self::q($db,'INSERT IGNORE INTO gyd_rewards_lab_productos_v40(sucursal_id,producto_id,precio_centavos,costo_centavos,stock_virtual,puntos_requeridos,escenario,actualizado_por)
                    VALUES(?,?,?,?,?,? ,\'CANJE_ALEATORIO_V41\',?)',[$sid,(int)$r['id'],$precio,$costo,$stock,$pts,$actor]);
                if($st->rowCount()!==1)continue;
                $nuevo=['sucursal_id'=>$sid,'producto_id'=>(int)$r['id'],'precio_centavos'=>$precio,'costo_centavos'=>$costo,'stock_virtual'=>$stock,'puntos_requeridos'=>$pts,'escenario'=>'CANJE_ALEATORIO_V41'];
                self::q($db,'INSERT INTO gyd_rewards_lab_auditoria_v40(sucursal_id,producto_id,usuario_id,accion,antes_json,despues_json) VALUES(?,?,?,\'V41_SEMILLA\',NULL,?)',[$sid,(int)$r['id'],$actor,json_encode($nuevo,JSON_UNESCAPED_UNICODE)]);
                $agregados[]=['producto_id'=>(int)$r['id'],'descripcion'=>$r['descripcion'],'puntos'=>$pts];
                if(count($agregados)>=$cantidad)break;
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return ['sucursal_id'=>$sid,'agregados'=>count($agregados),'productos'=>$agregados,'candidatos_descartados'=>$omitidos,
            'nota'=>count($agregados)<$cantidad?'No se encontraron más productos elegibles con precio, costo, margen >=20% y máximo 10,000 puntos. Puedes agregar otros manualmente en V40.':'Nuevos productos aleatorios disponibles para canjes de laboratorio.'];
    }
    public static function totalCanjes(PDO $db):int {
        self::guard($db);
        return (int)self::q($db,'SELECT COUNT(*) FROM gyd_rewards_lab_canjes_v41')->fetchColumn();
    }
    public static function historial(PDO $db,int $limite=50):array {
        self::guard($db);$limite=min(100,max(1,$limite));
        return self::q($db,'SELECT x.id,x.clave,x.sucursal_id,x.producto_id,x.cliente_id,x.puntos_gastados,x.costo_centavos,
          x.precio_centavos,x.saldo_antes,x.saldo_despues,x.stock_antes,x.stock_despues,x.creado_en,
          c.nombre AS cliente,p.descripcion AS producto,s.nombre AS sucursal
          FROM gyd_rewards_lab_canjes_v41 x
          JOIN gyd_clientes c ON c.id=x.cliente_id JOIN productos p ON p.id=x.producto_id
          JOIN pos_sucursales s ON s.id=x.sucursal_id ORDER BY x.id DESC LIMIT '.$limite)->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Descuenta puntos y existencia VIRTUALES en una sola transacción con clave única. */
    public static function canjear(PDO $db,array $data,int $actor):array {
        self::guard($db);
        if($actor<1)throw new DomainException('Usuario no identificado.');
        $sid=self::positive($data['sucursal_id']??null,'sucursal');
        $cid=self::positive($data['cliente_id']??null,'cliente');
        $pid=self::positive($data['producto_id']??null,'producto');
        $clave=(string)($data['clave']??'');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',$clave))throw new DomainException('Identificador de canje inválido.');
        if($db->inTransaction())throw new RuntimeException('No se admiten transacciones externas.');
        $db->beginTransaction();
        try {
            // La PRIMERA consulta debe ser un bloqueo (current-read): bajo REPEATABLE READ,
            // una consulta normal aquí fijaría una foto obsoleta del saldo al esperar otro canje.
            // Serializar todos los gastos de este cliente bloqueando su ficha.
            $cliente=self::q($db,'SELECT c.id,c.nombre,c.codigo,c.activo,c.acepta_programa FROM gyd_clientes c
              JOIN gyd_rewards_lab_clientes_v40 l ON l.cliente_id=c.id WHERE c.id=? FOR UPDATE',[$cid])->fetch(PDO::FETCH_ASSOC);
            if(!$cliente || !str_starts_with((string)$cliente['codigo'],'LAB40-') || (int)$cliente['activo']!==1 || (int)$cliente['acepta_programa']!==1)
                throw new DomainException('Sólo se permiten clientes ficticios LAB40 activos.');
            // Comprobación idempotente DESPUÉS del bloqueo, para dos POST concurrentes con la misma clave.
            $prev=self::q($db,'SELECT * FROM gyd_rewards_lab_canjes_v41 WHERE clave=?',[$clave])->fetch(PDO::FETCH_ASSOC);
            if($prev){
                if((int)$prev['cliente_id']!==$cid||(int)$prev['sucursal_id']!==$sid||(int)$prev['producto_id']!==$pid)throw new DomainException('Clave de canje ya utilizada.');
                $db->commit();return ['canje_id'=>(int)$prev['id'],'duplicado'=>true,'puntos_descontados'=>(int)$prev['puntos_gastados'],'saldo_despues'=>(int)$prev['saldo_despues'],'stock_despues'=>(int)$prev['stock_despues'],'virtual'=>true];
            }
            if(GydRewardsReversionesV37::hayParcialesPendientes($db,$cid))throw new DomainException('Cliente con devoluciones parciales pendientes; prueba bloqueada.');
            $sucursal=self::q($db,'SELECT id FROM pos_sucursales WHERE id=? AND activo=1',[$sid])->fetchColumn();
            if(!$sucursal)throw new DomainException('Sucursal inactiva.');
            $producto=self::q($db,'SELECT l.*,p.descripcion FROM gyd_rewards_lab_productos_v40 l JOIN productos p ON p.id=l.producto_id AND p.estado=1 WHERE l.sucursal_id=? AND l.producto_id=? FOR UPDATE',[$sid,$pid])->fetch(PDO::FETCH_ASSOC);
            if(!$producto)throw new DomainException('Producto virtual no disponible.');
            $saldo=self::saldo($db,$cid);
            $evaluacion=GydRewardsLabV40::calculo((int)$producto['precio_centavos'],(int)$producto['costo_centavos'],(int)$producto['stock_virtual'],(int)$producto['puntos_requeridos'],$saldo);
            if(!$evaluacion['simulacion_posible'])throw new DomainException('Canje de laboratorio no permitido: '.$evaluacion['razon']);
            $puntos=(int)$producto['puntos_requeridos'];
            $stockAntes=(int)$producto['stock_virtual'];
            $st=self::q($db,'UPDATE gyd_rewards_lab_productos_v40 SET stock_virtual=stock_virtual-1,actualizado_por=? WHERE sucursal_id=? AND producto_id=? AND stock_virtual>0',[$actor,$sid,$pid]);
            if($st->rowCount()!==1)throw new DomainException('Sin existencia virtual disponible.');
            self::q($db,'INSERT INTO gyd_rewards_lab_canjes_v41(clave,sucursal_id,producto_id,cliente_id,puntos_gastados,costo_centavos,precio_centavos,saldo_antes,saldo_despues,stock_antes,stock_despues,usuario_id)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$clave,$sid,$pid,$cid,$puntos,(int)$producto['costo_centavos'],(int)$producto['precio_centavos'],$saldo,$saldo-$puntos,$stockAntes,$stockAntes-1,$actor]);
            $id=(int)$db->lastInsertId();
            $db->commit();
            return ['canje_id'=>$id,'duplicado'=>false,'puntos_descontados'=>$puntos,'saldo_antes'=>$saldo,'saldo_despues'=>$saldo-$puntos,'stock_antes'=>$stockAntes,'stock_despues'=>$stockAntes-1,'costo_premio_centavos'=>(int)$producto['costo_centavos'],'virtual'=>true];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
