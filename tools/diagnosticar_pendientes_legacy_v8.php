<?php
declare(strict_types=1);
/** Opcional: diagnóstico SOLO LECTURA de solicitudes antiguas de retiros. */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Solo consola.');}
$root=dirname(__DIR__);
try {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba') throw new RuntimeException('Únicamente base prueba.');
    $exists=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='erp_retiros_supervisados'")->fetchColumn();
    if (!(int)$exists) {echo "No existe la tabla de solicitudes antiguas.\n";exit(0);}
    $q=$db->query("SELECT r.turno_id, t.estado AS estado_turno, COUNT(*) AS solicitudes_legacy, COALESCE(SUM(r.importe),0) AS importe_solicitado, SUM(CASE WHEN r.movimiento_id IS NULL THEN 0 ELSE 1 END) AS con_movimiento FROM erp_retiros_supervisados r JOIN pos_turnos t ON t.id=r.turno_id WHERE r.estado='SOLICITADO' GROUP BY r.turno_id,t.estado ORDER BY r.turno_id DESC LIMIT 50");
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {echo "Sin solicitudes heredadas en estado SOLICITADO.\n";exit(0);}
    echo "DIAGNOSTICO SOLO LECTURA - solicitudes antiguas; NO son retiros confirmados.\n";
    foreach ($rows as $r) {
        echo 'Turno '.(int)$r['turno_id'].' ('.$r['estado_turno'].'): '.(int)$r['solicitudes_legacy'].' solicitud(es), $'.number_format((float)$r['importe_solicitado'],2,'.',',').', con movimiento='.((int)$r['con_movimiento'])."\n";
    }
    echo "Importante: revisar manualmente solicitudes con movimiento; esta herramienta NO confirma ni borra.\n";
} catch(Throwable $e) {fwrite(STDERR,"No se pudo diagnosticar: ".$e->getMessage()."\n");exit(1);}
