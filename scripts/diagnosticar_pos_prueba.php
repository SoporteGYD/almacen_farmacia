<?php
declare(strict_types=1);
/**
 * Diagnóstico de SOLO LECTURA para POS G&D en XAMPP.
 * Ejecutar por consola: php scripts/diagnosticar_pos_prueba.php
 * No inserta, modifica ni elimina datos.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo puede ejecutarse desde terminal.');
}
require_once dirname(__DIR__).'/app/config/database.php';

function line(string $message): void { echo $message.PHP_EOL; }
function failed(string $msg): void { line('[ERROR] '.$msg); }
function ok(string $msg): void { line('[OK] '.$msg); }
function warning(string $msg): void { line('[REVISAR] '.$msg); }

line('DIAGNOSTICO POS G&D - LECTURA UNICAMENTE');
line('----------------------------------------');
try {
    $pdo=(new Database())->connect();
    $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    line('Base conectada: '.$db);
    if($db !== 'prueba') {
        failed('La conexion NO apunta a "prueba". No se continuara.');
        exit(2);
    }
    $required=[
        'pos_sucursales'=>['id','nombre','activo'],
        'pos_cajas'=>['id','sucursal_id','nombre','activa'],
        'pos_turnos'=>['id','caja_id','usuario_apertura_id','fondo_inicial','estado'],
        'pos_precios'=>['sucursal_id','producto_id','precio_publico'],
        'pos_existencias_farmacia'=>['id','sucursal_id','producto_id','existencia'],
        'pos_ventas'=>['id','folio','sucursal_id','caja_id','turno_id','usuario_id','estado','subtotal','total','efectivo_recibido','cambio','idempotency_key','creada_en','pagada_en'],
        'pos_venta_detalle'=>['venta_id','producto_id','codigo_snapshot','descripcion_snapshot','cantidad','precio_unitario','total_renglon'],
        'pos_pagos'=>['venta_id','metodo','monto','referencia','estado','confirmado_en'],
        'pos_auditoria'=>['usuario_id','venta_id','accion','detalle'],
        'pos_movimientos_caja'=>['turno_id','tipo','monto'],
        'pos_precios_historial'=>['sucursal_id','producto_id','precio_nuevo','usuario_id'],
        'productos'=>['id','codigo','descripcion','estado'],
        'usuarios'=>['id','nombre','rol']
    ];
    $stmt=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $errors=0;
    foreach($required as $table=>$cols) {
        $stmt->execute([$db,$table]);
        $found=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$found) { failed('Falta tabla: '.$table); $errors++; continue; }
        $missing=array_values(array_diff($cols,$found));
        if ($missing) { failed('Tabla '.$table.' sin columna(s): '.implode(', ',$missing)); $errors++; }
        else { ok($table); }
    }
    line('----------------------------------------');
    if ($errors===0) ok('Tablas y columnas esenciales presentes.');
    else warning('Se detectaron '.$errors.' tablas con problemas de estructura. NO importes un respaldo completo.');
    foreach([
        'SELECT COUNT(*) FROM pos_cajas WHERE activa=1',
        "SELECT COUNT(*) FROM pos_turnos WHERE estado='ABIERTO'",
        'SELECT COUNT(*) FROM pos_ventas',
        'SELECT COUNT(*) FROM pos_precios WHERE precio_publico>0',
        'SELECT COUNT(*) FROM pos_existencias_farmacia WHERE existencia>0'
    ] as $i=>$sql) {
        $labels=['Cajas activas','Turnos abiertos','Ventas guardadas','Precios positivos','Productos con stock POS'];
        try { line($labels[$i].': '.$pdo->query($sql)->fetchColumn()); }
        catch(Throwable $e) { warning($labels[$i].': consulta fallida ('.$e->getMessage().')'); }
    }
    line('----------------------------------------');
    line('Si todo aparece [OK] y sigue fallando un cobro, revisa el error preciso en:');
    line('C:\\xampp\\apache\\logs\\error.log (buscar "POS API:")');
    line('NO ejecuta ventas ni modifica existencias.');
} catch(Throwable $e) {
    failed('No se pudo completar el diagnostico: '.$e->getMessage());
    exit(1);
}
