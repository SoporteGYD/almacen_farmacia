<?php
declare(strict_types=1);
/**
 * G&D V43: catálogo abundante para CANJES VIRTUALES. Requiere prueba:3307.
 * ÚNICAMENTE INSERT en gyd_rewards_lab_productos_v40 y su auditoría.
 * No cambia precios POS, ventas, inventarios operativos, catálogo comercial V38 ni puntos.
 */
require_once __DIR__.'/gyd_rewards_lab_v41.php';
final class GydRewardsLabV43 {
    private static function run(PDO $db, string $sql, array $params=[]): PDOStatement {
        $st=$db->prepare($sql);$st->execute($params);return $st;
    }
    public static function source(string $escenario): array {
        if($escenario==='V43_COSTO_LOTE')return ['origen_costo'=>'LOTE','costo_simulado'=>false,'advertencia_costo'=>'Costo de lote registrado; referencia, no utilidad neta.'];
        if($escenario==='V43_COSTO_PRODUCTO')return ['origen_costo'=>'CATALOGO','costo_simulado'=>false,'advertencia_costo'=>'Costo del catálogo de productos; validar con contabilidad.'];
        if($escenario==='V43_COSTO_SIMULADO')return ['origen_costo'=>'SIMULADO','costo_simulado'=>true,'advertencia_costo'=>'Costo 60% del precio, INVENTADO sólo para pruebas. No es margen real.'];
        return ['origen_costo'=>'V40/V41','costo_simulado'=>true,'advertencia_costo'=>'Escenario de laboratorio preexistente. Revisa su costo.'];
    }
    public static function premios(PDO $db,int $sucursal):array {
        $items=GydRewardsLabV41::premios($db,$sucursal);
        foreach($items as &$r){
            $r=array_merge($r,self::source((string)$r['escenario']));
            $r['virtual']=true;
        }unset($r);
        usort($items,static function(array $a,array $b):int {
            $av=str_starts_with((string)$a['escenario'],'V43_')?1:0;
            $bv=str_starts_with((string)$b['escenario'],'V43_')?1:0;
            return $bv<=>$av;
        });
        return $items;
    }
    /**
     * Agrega hasta 20 productos NUEVOS por llamada. Nunca sobrescribe configuraciones.
     * Si falta costo en lotes, utiliza el mayor costo positivo del maestro; si tampoco
     * existe, genera costo FICTICIO y lo identifica explícitamente en cada registro.
     */
    public static function agregar(PDO $db,int $sucursal,int $actor,int $cantidad=20):array {
        GydRewardsLabV40::entorno($db);
        if($sucursal<1||$actor<1||$cantidad<1||$cantidad>20)throw new DomainException('Datos inválidos para habilitar premios de prueba.');
        if(!self::run($db,'SELECT id FROM pos_sucursales WHERE id=? AND activo=1',[$sucursal])->fetchColumn())
            throw new DomainException('Sucursal no activa.');
        if($db->inTransaction())throw new LogicException('Se requiere transacción independiente.');
        // El límite del precio favorece premios accesibles a clientes ficticios (80-10,000 puntos).
        // El inventario NO proviene de la farmacia: es un saldo ficticio 10-17 unidades.
        $candidatos=self::run($db,'SELECT p.id,p.codigo,p.descripcion,pp.precio_publico,
           p.costo_ultimo,p.costo_promedio,p.precio_compra,
           (SELECT MAX(l.costo_unitario) FROM lotes l WHERE l.producto_id=p.id AND l.costo_unitario>0) costo_lote
           FROM productos p JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=?
           WHERE p.estado=1 AND pp.precio_publico BETWEEN 8 AND 180
             AND NOT EXISTS (SELECT 1 FROM gyd_rewards_lab_productos_v40 x
                             WHERE x.sucursal_id=? AND x.producto_id=p.id)
           ORDER BY RAND() LIMIT 2500',[$sucursal,$sucursal])->fetchAll(PDO::FETCH_ASSOC);
        $agregados=[];$descartados=0;$fuentes=['LOTE'=>0,'CATALOGO'=>0,'SIMULADO'=>0];
        $db->beginTransaction();
        try {
            foreach($candidatos as $r){
                $precio=GydRewardsCatalogoV38::centavos((string)$r['precio_publico']);
                if($precio===null||$precio<=0){$descartados++;continue;}
                $costos=[];
                $lote=GydRewardsCatalogoV38::centavos($r['costo_lote']===null?null:(string)$r['costo_lote'],true);
                if($lote!==null&&$lote>0)$costos[]=$lote;
                foreach(['costo_ultimo','costo_promedio','precio_compra'] as $campo){
                    $v=GydRewardsCatalogoV38::centavos($r[$campo]===null?null:(string)$r[$campo],true);
                    if($v!==null && $v>0)$costos[]=$v;
                }
                // Si hay costos conocidos, usa el máximo (evita exagerar rentabilidad).
                if($costos){
                    $costo=max($costos);
                    $origen=$lote!==null && $lote>0 && $costo===$lote?'LOTE':'CATALOGO';
                } else {
                    $costo=max(1,intdiv($precio*60+99,100));
                    $origen='SIMULADO';
                }
                $evaluacion=GydRewardsCatalogoV38::evaluarImportes($precio,$costo,true);
                if(!$evaluacion['apto'] || $evaluacion['minimo_puntos']===null || (int)$evaluacion['minimo_puntos']>10000){$descartados++;continue;}
                $puntos=(int)$evaluacion['minimo_puntos'];
                $stock=random_int(10,17);
                $escenario='V43_COSTO_'.$origen;
                $st=self::run($db,'INSERT IGNORE INTO gyd_rewards_lab_productos_v40
                    (sucursal_id,producto_id,precio_centavos,costo_centavos,stock_virtual,puntos_requeridos,escenario,actualizado_por)
                    VALUES (?,?,?,?,?,?,?,?)',[$sucursal,(int)$r['id'],$precio,$costo,$stock,$puntos,$escenario,$actor]);
                if($st->rowCount()!==1)continue;
                $snapshot=['sucursal_id'=>$sucursal,'producto_id'=>(int)$r['id'],'precio_centavos'=>$precio,
                    'costo_centavos'=>$costo,'stock_virtual'=>$stock,'puntos_requeridos'=>$puntos,
                    'escenario'=>$escenario,'virtual'=>true];
                self::run($db,'INSERT INTO gyd_rewards_lab_auditoria_v40
                    (sucursal_id,producto_id,usuario_id,accion,antes_json,despues_json)
                    VALUES (?,?,?,\'V43_ALTA_VIRTUAL\',NULL,?)',[$sucursal,(int)$r['id'],$actor,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                $fuentes[$origen]++;
                $agregados[]=['id'=>(int)$r['id'],'descripcion'=>$r['descripcion'],'puntos'=>$puntos,'costo'=>$origen];
                if(count($agregados)>=$cantidad)break;
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return ['sucursal_id'=>$sucursal,'agregados'=>count($agregados),'productos'=>$agregados,
            'origenes'=>$fuentes,'descartados'=>$descartados,
            'nota'=>count($agregados)<$cantidad?'No había suficientes productos con precio y margen de referencia aptos.':'Premios virtuales listos para canjes de prueba.'];
    }
    /** Idempotente para instalaciones: completa 20 escenarios V43, no agrega 20 cada vez. */
    public static function completar(PDO $db,int $sucursal,int $actor):array {
        GydRewardsLabV40::entorno($db);
        $cantidad=(int)self::run($db,"SELECT COUNT(*) FROM gyd_rewards_lab_productos_v40 WHERE sucursal_id=? AND escenario IN ('V43_COSTO_LOTE','V43_COSTO_PRODUCTO','V43_COSTO_SIMULADO')",[$sucursal])->fetchColumn();
        if($cantidad>=20)return ['sucursal_id'=>$sucursal,'agregados'=>0,'existentes'=>$cantidad,'nota'=>'Ya tiene 20 o más premios de prueba.'];
        return self::agregar($db,$sucursal,$actor,20-$cantidad);
    }
}
