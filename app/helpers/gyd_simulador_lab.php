<?php
declare(strict_types=1);
/** Simulador G&D. Nunca conecta al esquema operativo. */
const GYD_SIM_DB = 'gyd_lab_simulador';
const GYD_SIM_MARKER = 'GYD_SIMULADOR_SOLO_LABORATORIO_2026';

function gyd_sim_connection(): PDO {
    $host = getenv('GYD_SIM_HOST') ?: '127.0.0.1';
    $port = getenv('GYD_SIM_PORT') ?: '3307';
    $username = getenv('GYD_SIM_USER') ?: 'root';
    $password = getenv('GYD_SIM_PASS');
    if ($password === false) $password = '';
    if (!preg_match('/^\d{2,5}$/', $port)) throw new RuntimeException('Puerto de laboratorio inválido.');
    $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.GYD_SIM_DB.';charset=utf8mb4';
    $db = new PDO($dsn,$username,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5
    ]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== GYD_SIM_DB)
        throw new RuntimeException('Bloqueado: no se obtuvo la base aislada autorizada.');
    return $db;
}
function gyd_sim_guard(PDO $db, bool $requireMarker=true): void {
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== GYD_SIM_DB)
        throw new RuntimeException('Bloqueado: base no es laboratorio.');
    $s=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    $tables=$s->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table) if(!str_starts_with((string)$table,'sim_'))
        throw new RuntimeException('Bloqueado: el esquema contiene tablas ajenas al simulador.');
    if($requireMarker) {
        if(!in_array('sim_control',$tables,true)) throw new RuntimeException('Laboratorio no inicializado. Ejecuta --setup.');
        $s=$db->prepare('SELECT marker FROM sim_control WHERE id=1');$s->execute();
        if($s->fetchColumn()!==GYD_SIM_MARKER)throw new RuntimeException('Bloqueado: el marcador de laboratorio no coincide.');
    }
}
function gyd_sim_h(mixed $s):string{return htmlspecialchars((string)($s??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gyd_sim_role(array $u):bool{
    $role=strtoupper(trim((string)($u['rol_original']??$u['rol']??'')));
    return (int)($u['id']??0)>0 && in_array($role,['ADMIN','ADMINISTRADOR','DIRECCION','DUEÑO','DUENO','PROPIETARIO'],true);
}
function gyd_sim_scenarios():array{
    return [
        'venta_unica'=>'Evitar doble registro de una venta con la misma clave',
        'stock_concurrente'=>'Dos cobros contra una sola pieza en existencia',
        'retiro_concurrente'=>'Dos retiros simultáneos respetando fondo inicial',
        'corte_unico'=>'Dos solicitudes de cierre sobre el mismo turno',
        'factura_unica'=>'Dos recepciones con la misma referencia de factura',
        'devolucion_unica'=>'Evitar dos devoluciones sobre una misma venta',
    ];
}
