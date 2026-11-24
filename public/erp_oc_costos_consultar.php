<?php
declare(strict_types=1);
/** ERP G&D: consulta de costos por código, sólo lectura y usuarios de compras. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_win.php';
requireLogin();
$user = currentUser() ?: [];
ocw_guard($user);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function oc_costos_respuesta(int $http, array $payload): void {
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!ocw_can_edit($user)) oc_costos_respuesta(403, ['ok' => false, 'error' => 'No tienes permisos para consultar costos de compra.']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') oc_costos_respuesta(405, ['ok' => false, 'error' => 'Método no permitido.']);
try {
    ocw_check_token($_POST['csrf'] ?? null);
    $ids = json_decode((string)($_POST['ids'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($ids) || count($ids) < 1 || count($ids) > 80 || array_keys($ids) !== range(0, count($ids) - 1)) {
        throw new InvalidArgumentException('Selecciona de 1 a 80 artículos.');
    }
    $idsValidos = [];
    foreach ($ids as $raw) {
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw))) {
            throw new InvalidArgumentException('Se recibió un identificador inválido.');
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id || isset($idsValidos[$id])) throw new InvalidArgumentException('Hay identificadores inválidos o repetidos.');
        $idsValidos[$id] = $id;
    }
    $db = (new Database())->connect();
    $placeholders = implode(',', array_fill(0, count($idsValidos), '?'));
    $st = $db->prepare('SELECT id, codigo, costo_ultimo, costo_promedio FROM productos WHERE estado=1 AND id IN ('.$placeholders.')');
    $st->execute(array_values($idsValidos));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $codigos = [];
    $items = [];
    foreach ($rows as $row) {
        $codigo = trim((string)$row['codigo']);
        if ($codigo === '' || isset($codigos[$codigo])) {
            throw new RuntimeException('Se detectaron códigos vacíos o duplicados en el Catálogo Maestro.');
        }
        $codigos[$codigo] = true;
        $ultimo = (float)$row['costo_ultimo'];
        $promedio = (float)$row['costo_promedio'];
        $items[] = [
            'id' => (int)$row['id'], 'codigo' => $codigo,
            'ultimo' => $ultimo > 0 ? round($ultimo, 4) : null,
            'promedio' => $promedio > 0 ? round($promedio, 4) : null
        ];
    }
    oc_costos_respuesta(200, ['ok'=>true, 'items'=>$items]);
} catch (InvalidArgumentException | JsonException $e) {
    oc_costos_respuesta(422, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('ERP OC consulta comparador: '.$e->getMessage());
    oc_costos_respuesta(500, ['ok'=>false,'error'=>'No se pudieron consultar los costos de referencia.']);
}
