<?php
declare(strict_types=1);
require_once __DIR__.'/../config/erp_oc_politica.php';
/** Reglas compartidas del ERP: recepciones de órdenes (sin movimiento de inventario). */
function erpr_html(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function erpr_destinos(): array {
    return [
        'TAPACHULA'=>'Tapachula · Farmacia',
        'TOSCANA'=>'Toscana · Farmacia',
        'HIDALGO_FARMACIA'=>'Ciudad Hidalgo · Farmacia',
        'HIDALGO_BODEGA'=>'Ciudad Hidalgo · Bodega',
        'TUXTLA_FARMACIA'=>'Tuxtla Gutiérrez · Farmacia',
        'TUXTLA_BODEGA'=>'Tuxtla Gutiérrez · Bodega',
    ];
}
function erpr_acceso(array $user): array {
    $rol = strtoupper(trim((string)($user['rol'] ?? '')));
    $id = (int)($user['id'] ?? 0);
    if ($id < 1 || !in_array($rol, ['ADMINISTRADOR','ADMIN','COMPRAS','DIRECCION','GERENTE','ENCARGADO','ALMACEN','JEFE_ALMACEN','CONSULTA'], true)) {
        http_response_code(403);
        exit('No tienes permiso para consultar recepciones.');
    }
    // Solo ADMIN puede registrar recepciones de farmacias sin una asociación verificable.
    $puedeRegistrar = in_array($rol, ['ADMINISTRADOR','ADMIN','GERENTE','ENCARGADO','ALMACEN','JEFE_ALMACEN'], true);
    $destinoForzado = null;
    if (in_array($rol, ['GERENTE','ENCARGADO','ALMACEN','JEFE_ALMACEN'], true)) {
        $almacenId = (int)($user['almacen_id'] ?? 0);
        $destinoForzado = [1=>'HIDALGO_BODEGA', 3=>'TUXTLA_BODEGA'][$almacenId] ?? '__SIN_DESTINO__';
        if ($destinoForzado === '__SIN_DESTINO__') $puedeRegistrar = false;
    }
    return ['rol'=>$rol, 'uid'=>$id, 'registrar'=>$puedeRegistrar, 'destino'=>$destinoForzado];
}
function erpr_puede_ver_destino(array $acceso, string $destino): bool {
    $forzado = $acceso['destino'];
    return $forzado === null || ($forzado !== '__SIN_DESTINO__' && $forzado === $destino);
}
function erpr_puede_registrar_destino(array $acceso, string $destino): bool {
    return $acceso['registrar'] && erpr_puede_ver_destino($acceso,$destino);
}
function erpr_fecha_valida(string $fecha): bool {
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
    return $dt!==false && $dt->format('Y-m-d')===$fecha;
}
function erpr_detalles(PDO $pdo, int $ordenId): array {
    $sql = "SELECT d.id, d.orden_id, d.producto_id, d.codigo_snapshot, d.descripcion_snapshot,
                   d.cantidad_piezas AS solicitadas,
                   COALESCE(rec.recibidas,0) AS recibidas
            FROM erp_ordenes_compra_detalle d
            LEFT JOIN (
              SELECT rd.orden_detalle_id, SUM(rd.cantidad_piezas) recibidas
              FROM erp_oc_recepciones_detalle rd
              JOIN erp_oc_recepciones r ON r.id=rd.recepcion_id AND r.estado='REGISTRADA'
              GROUP BY rd.orden_detalle_id
            ) rec ON rec.orden_detalle_id=d.id
            WHERE d.orden_id=? ORDER BY d.id";
    $st=$pdo->prepare($sql);$st->execute([$ordenId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function erpr_resumen(PDO $pdo, string $destino = '', int $limite=300): array {
    $filtroAuth=gd_oc_requiere_autorizacion()?"JOIN erp_oc_autorizaciones a ON a.orden_id=o.id AND a.estado='AUTORIZADA'":"";
    $sql="SELECT o.id,o.folio,o.fecha,o.destino,o.estado,o.subtotal,p.nombre AS proveedor,
         COALESCE(pedido.solicitadas,0) AS solicitadas,
         COALESCE(rec.recibidas,0) AS recibidas,
         COALESCE(pedido.renglones,0) AS renglones
         FROM erp_ordenes_compra o
         JOIN proveedores p ON p.id=o.proveedor_id
         ".$filtroAuth."
         LEFT JOIN (SELECT orden_id,SUM(cantidad_piezas) solicitadas,COUNT(*) renglones
                    FROM erp_ordenes_compra_detalle GROUP BY orden_id) pedido ON pedido.orden_id=o.id
         LEFT JOIN (SELECT r.orden_id,SUM(rd.cantidad_piezas) recibidas
                    FROM erp_oc_recepciones r JOIN erp_oc_recepciones_detalle rd ON rd.recepcion_id=r.id
                    WHERE r.estado='REGISTRADA' GROUP BY r.orden_id) rec ON rec.orden_id=o.id
         WHERE o.estado='EMITIDA' AND NOT EXISTS (SELECT 1 FROM erp_oc_cierres_controlados cx WHERE cx.orden_id=o.id AND cx.estado='CERRADA') /* GYD_GCC_BANDEJA_V1 */";
    $args=[];
    if ($destino !== '') {$sql.=" AND o.destino=:destino";$args['destino']=$destino;}
    $sql.=" AND COALESCE(pedido.solicitadas,0) > COALESCE(rec.recibidas,0) ORDER BY o.fecha DESC,o.id DESC LIMIT ".max(1,min(500,$limite));
    $st=$pdo->prepare($sql);$st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
