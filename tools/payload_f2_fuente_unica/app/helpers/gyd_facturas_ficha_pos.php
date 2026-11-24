<?php
declare(strict_types=1);
/**
 * G&D F2 · Ficha comercial POS vinculada a la aplicación de una factura ya verificada.
 * Este helper NO mueve existencias: eso sigue exclusivamente en erp_facturas_aplicar.php.
 * Todas sus escrituras deben ejecutarse DENTRO de la transacción de aplicación.
 */

function gyd_ff_precio_normalizado(mixed $value, bool $obligatorio = true): ?string {
    if ($value === null || trim((string)$value) === '') {
        if ($obligatorio) throw new RuntimeException('El precio público debe ser mayor que cero.');
        return null;
    }
    $v = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $v)) {
        throw new RuntimeException('El precio debe ser positivo, sin separadores y con máximo dos decimales.');
    }
    $n = (float)$v;
    if ($n <= 0 || $n > 99999999.99) throw new RuntimeException('Precio público fuera del rango permitido.');
    return number_format($n, 2, '.', '');
}

function gyd_ff_aplicar_ficha(PDO $db, int $sucursalId, int $productoId, int $usuarioId, string $referencia, array $data): bool {
    if (!$db->inTransaction()) throw new LogicException('La ficha debe actualizarse en la misma transacción que la entrada.');
    if ($sucursalId < 1 || $productoId < 1 || $usuarioId < 1) throw new RuntimeException('Identificadores comerciales inválidos.');
    $nuevoPrecio = gyd_ff_precio_normalizado($data['precio'] ?? '');
    $esperadoPrecio = gyd_ff_precio_normalizado($data['precio_original'] ?? '', false);
    $modo = strtoupper(trim((string)($data['modalidad'] ?? '')));
    if (!in_array($modo, ['PIEZA', 'CAJA', 'AMBAS'], true)) throw new RuntimeException('Presentación inválida.');
    $piezas = trim((string)($data['piezas_por_caja'] ?? ''));
    if (!preg_match('/^[1-9][0-9]{0,5}$/D', $piezas) || (int)$piezas > 100000) throw new RuntimeException('Piezas por caja debe ser un entero entre 1 y 100000.');
    $factor = (int)$piezas;
    $modoAnterior = strtoupper(trim((string)($data['modalidad_original'] ?? '')));
    $factorAnterior = (int)($data['piezas_original'] ?? 0);

    $st = $db->prepare('SELECT precio_publico FROM pos_precios WHERE sucursal_id=? AND producto_id=? FOR UPDATE');
    $st->execute([$sucursalId,$productoId]);
    $precioBD = gyd_ff_precio_normalizado($st->fetchColumn() ?: '', false);
    if ($precioBD !== $esperadoPrecio) {
        throw new RuntimeException('El precio del producto #'.$productoId.' cambió desde que abriste la factura. Recarga y revisa antes de confirmar.');
    }
    $st = $db->prepare('SELECT modalidad_presentacion,piezas_por_caja FROM pos_presentaciones WHERE sucursal_id=? AND producto_id=? FOR UPDATE');
    $st->execute([$sucursalId,$productoId]);
    $previa = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $modoBD = strtoupper((string)($previa['modalidad_presentacion'] ?? ''));
    $factorBD = (int)($previa['piezas_por_caja'] ?? 0);
    if ($modoBD !== $modoAnterior || $factorBD !== $factorAnterior) {
        throw new RuntimeException('La presentación del producto #'.$productoId.' cambió desde que abriste la factura. Recarga antes de confirmar.');
    }
    $cambioPrecio = $nuevoPrecio !== $precioBD;
    $cambioPresentacion = $modo !== $modoBD || $factor !== $factorBD;
    if (!$cambioPrecio && !$cambioPresentacion) return false;

    if ($cambioPrecio) {
        $st = $db->prepare('INSERT INTO pos_precios(sucursal_id,producto_id,precio_publico,actualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE precio_publico=VALUES(precio_publico),actualizado_por=VALUES(actualizado_por),vigente_desde=NOW()');
        $st->execute([$sucursalId,$productoId,$nuevoPrecio,$usuarioId]);
        $st = $db->prepare('INSERT INTO pos_precios_historial(sucursal_id,producto_id,precio_anterior,precio_nuevo,usuario_id,motivo) VALUES(?,?,?,?,?,?)');
        $st->execute([$sucursalId,$productoId,$precioBD,$nuevoPrecio,$usuarioId,mb_substr($referencia,0,250)]);
    }
    if ($cambioPresentacion) {
        $st = $db->prepare('INSERT INTO pos_presentaciones(sucursal_id,producto_id,modalidad_presentacion,piezas_por_caja,precio_caja,actualizado_por) VALUES(?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE modalidad_presentacion=VALUES(modalidad_presentacion),piezas_por_caja=VALUES(piezas_por_caja),precio_caja=NULL,actualizado_por=VALUES(actualizado_por)');
        $st->execute([$sucursalId,$productoId,$modo,$factor,$usuarioId]);
    }
    $detalle = json_encode([
        'fuente'=>'ENTRADA_POR_FACTURA','referencia'=>$referencia,'producto_id'=>$productoId,'sucursal_id'=>$sucursalId,
        'precio_anterior'=>$precioBD,'precio_nuevo'=>$nuevoPrecio,'presentacion_anterior'=>$modoBD,
        'presentacion_nueva'=>$modo,'piezas_anterior'=>$factorBD,'piezas_nuevas'=>$factor
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st = $db->prepare('INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES(?,NULL,?,?)');
    $st->execute([$usuarioId,'ACTUALIZAR_FICHA_DESDE_FACTURA',$detalle]);
    return true;
}
