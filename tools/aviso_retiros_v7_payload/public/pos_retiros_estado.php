<?php
declare(strict_types=1);
/**
 * G&D | V7 | Estado del retiro automático en el POS (consulta sin efectos).
 * Única fuente de saldo: gd_cash_totals, idéntica a la pantalla pos_retiros.php.
 * No se consulta SOLICITADO: es un estado heredado de retiros con autorización.
 */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_retiros_directos.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$u=currentUser()?:[];
if (!in_array(gr_role($u), ['CAJERO','ADMINISTRADOR','ADMIN','DIRECCION'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $db=(new Database())->connect();
    gr_test_db($db);
    $caja=(int)($_GET['caja_id']??0);
    if ($caja < 1) throw new DomainException('Selecciona una caja válida.');
    gr_permission_caja($db, $u, $caja);
    $turno=gr_query($db,
        "SELECT id, usuario_apertura_id FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1",
        [$caja]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$turno) {
        echo json_encode(['abierto'=>false, 'alerta'=>false, 'pendiente'=>false, 'sugeridoCentavos'=>0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!gd_can_operate($u, $turno)) throw new DomainException('Este turno pertenece a otro cajero.');
    $resumen=gd_cash_totals($db, (int)$turno['id']);
    $sugerido=gr_sugerencia($resumen['esperado'], $resumen['fondo']);
    $sugerido=max(0, (int)$sugerido);
    echo json_encode([
        'abierto'=>true,
        'alerta'=>$sugerido > 0,
        'pendiente'=>false, // Compatibilidad de respuesta; ya no se solicitan confirmaciones.
        'turnoId'=>(int)$turno['id'],
        'sugeridoCentavos'=>$sugerido,
        'sugerido'=>gd_dollars($sugerido),
        'fondo'=>gd_dollars($resumen['fondo']),
        'esperado'=>gd_dollars($resumen['esperado']),
    ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code($e instanceof DomainException?400:500);
    error_log('G&D aviso retiro V7: '.$e->getMessage());
    echo json_encode(['error'=>$e instanceof DomainException?$e->getMessage():'No se pudo consultar el saldo de caja.'], JSON_UNESCAPED_UNICODE);
}
