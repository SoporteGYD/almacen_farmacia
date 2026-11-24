<?php
/** Revisiones automáticas de costos de factura: lectura y omisión con auditoría.
 *  SOLO presenta avisos de cambio; no modifica costos, inventario ni precios.
 */
declare(strict_types=1);
require_once __DIR__.'/erp_cambio_precios.php';

function cp_auto_pendientes(PDO $db,int $facturaId): array {
    if($facturaId<1)return [];
    $sql="SELECT d.id AS detalle_id,d.producto_id,d.descripcion_snapshot,d.codigo_snapshot,
           d.costo_unitario AS costo_factura,
           COALESCE(NULLIF(p.costo_ultimo,0),p.precio_compra,0) AS costo_base,
           a.aceptadas
           FROM erp_facturas_detalle d
           INNER JOIN productos p ON p.id=d.producto_id AND p.estado=1
           INNER JOIN (
              SELECT factura_detalle_id,SUM(cantidad) AS aceptadas
              FROM erp_facturas_revision_detalle
              WHERE tipo='ACEPTADA' GROUP BY factura_detalle_id
           ) a ON a.factura_detalle_id=d.id AND a.aceptadas>0
           WHERE d.factura_id=?
           AND NOT EXISTS (SELECT 1 FROM erp_precio_operaciones o WHERE o.factura_detalle_id=d.id)
           AND NOT EXISTS (SELECT 1 FROM erp_precio_omisiones om WHERE om.factura_detalle_id=d.id)
           ORDER BY d.id";
    $st=$db->prepare($sql);$st->execute([$facturaId]);$result=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $p){
        $old=round((float)$p['costo_base'],4);$new=round((float)$p['costo_factura'],4);
        // Sin referencia previa positiva no se afirma que hubo un cambio de costo.
        if($old>0.00001 && $new>0.00001 && abs($old-$new)>=0.0001) $result[]=$p;
    }
    return $result;
}
function cp_auto_facturas_pendientes(PDO $db,int $limite=10):array{
    $rows=$db->query("SELECT DISTINCT f.id,f.folio_interno,f.numero_factura,pv.nombre AS proveedor
       FROM erp_facturas f
       JOIN proveedores pv ON pv.id=f.proveedor_id
       JOIN erp_facturas_detalle d ON d.factura_id=f.id
       JOIN productos p ON p.id=d.producto_id AND p.estado=1
       WHERE COALESCE(NULLIF(p.costo_ultimo,0),p.precio_compra,0)>0
       AND d.costo_unitario>0
       AND ABS(d.costo_unitario-COALESCE(NULLIF(p.costo_ultimo,0),p.precio_compra,0))>=0.0001
       AND EXISTS (SELECT 1 FROM erp_facturas_revision_detalle r WHERE r.factura_detalle_id=d.id AND r.tipo='ACEPTADA' AND r.cantidad>0)
       AND NOT EXISTS (SELECT 1 FROM erp_precio_operaciones o WHERE o.factura_detalle_id=d.id)
       AND NOT EXISTS (SELECT 1 FROM erp_precio_omisiones om WHERE om.factura_detalle_id=d.id)
       ORDER BY f.id DESC LIMIT ".max(1,min(50,$limite)))->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}
function cp_auto_omitir(PDO $db,array $usuario,array $post):array{
    if(!cp_autorizado($usuario)|| (int)($usuario['id']??0)<1)throw new DomainException('Sin permisos para omitir cambios de precios.');
    $fid=filter_var($post['factura_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $did=filter_var($post['detalle_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$fid || !$did)throw new DomainException('Factura o partida no válida.');
    $db->beginTransaction();
    try{
        $d=cp_factura_producto($db,(int)$fid,(int)$did); // Confirma que hay piezas aceptadas y acceso al catálogo.
        $anterior=round((float)$d['costo_anterior'],4);$nuevo=round((float)$d['costo_nuevo'],4);
        if($anterior<=0||$nuevo<=0||abs($anterior-$nuevo)<0.0001)throw new DomainException('No existe un cambio de costo pendiente para esta partida.');
        $chk=$db->prepare('SELECT id FROM erp_precio_operaciones WHERE factura_detalle_id=? LIMIT 1');$chk->execute([$did]);
        if($chk->fetchColumn())throw new DomainException('Esta partida ya tiene cambio de precio registrado.');
        $stmt=$db->prepare('INSERT IGNORE INTO erp_precio_omisiones
          (factura_id,factura_detalle_id,producto_id,costo_referencia,costo_factura,usuario_id,motivo)
          VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$fid,$did,$d['producto_id'],$d['costo_anterior'],$d['costo_nuevo'],(int)$usuario['id'],'Omitido por Compras: mantener precios POS vigentes']);
        $insertado=$stmt->rowCount()===1;
        $db->commit();return ['insertado'=>$insertado];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
