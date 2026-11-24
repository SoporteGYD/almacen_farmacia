<?php
declare(strict_types=1);
/** Diagnóstico local de lectura. No imprime claves, hashes ni credenciales. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$root=realpath(__DIR__.'/..');
if(!$root || !is_file($root.'/app/config/database.php') || !is_file($root.'/app/helpers/gyd_auditoria_cajas_v12.php')){
    fwrite(STDERR,"Falta la configuración o el reporte V12 en este proyecto.\n");exit(1);
}
require_once $root.'/app/config/database.php';
require_once $root.'/app/helpers/gyd_auditoria_cajas_v12.php';
$required=[
 'pos_sucursales'=>['id','nombre'],
 'pos_cajas'=>['id','sucursal_id','nombre','activa'],
 'pos_turnos'=>['id','caja_id','estado','abierto_en','cerrado_en','usuario_apertura_id','usuario_cierre_id','fondo_inicial','efectivo_contado','diferencia_efectivo'],
 'usuarios'=>['id','nombre'],
 'pos_movimientos_caja'=>['id','turno_id','usuario_id','tipo','monto','creado_en'],
 'pos_auditoria'=>['id','usuario_id','venta_id','accion','creado_en'],
];
$optional=[
 'pos_cortes_denominaciones'=>['turno_id'],
 'gyd_cortes_autorizaciones'=>['turno_id','autorizador_id','modalidad','resultado'],
 'gyd_retiros_boveda'=>['id','turno_id','caja_id','cajero_id','movimiento_id','importe','creado_en'],
 'gyd_cortes_intentos'=>['id','solicitante_id','exitoso','creado_en'],
 'gyd_retiros_intentos_clave'=>['id','usuario_id','correcto','creado_en'],
];
try{
    $db=(new Database())->connect();
    $bd=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($bd!=='prueba')throw new RuntimeException('La base seleccionada no es prueba; se detuvo el diagnóstico.');
    echo "Conexión: OK; esquema de pruebas confirmado.\n";
    $stmt=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $bad=0;
    foreach($required+$optional as $table=>$cols){
        $stmt->execute([$table]);$present=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if(!$present){
            $requerida=isset($required[$table]);
            echo ($requerida?'ERROR':'AVISO').": tabla $table no encontrada".($requerida?' (obligatoria)':' (opcional)').".\n";
            if($requerida)$bad++;
            continue;
        }
        $missing=array_values(array_diff($cols,$present));
        if($missing){
            echo "ERROR: $table no tiene columnas: ".implode(', ',$missing).".\n";$bad++;
        }else echo "OK: $table\n";
    }
    if(!$bad){
        echo "Probando reporte de los últimos 7 días (solo SELECT)...\n";
        $hoy=(new DateTimeImmutable('today'))->format('Y-m-d');
        $desde=(new DateTimeImmutable('-6 days'))->format('Y-m-d');
        $datos=gac12_reporte($db,$desde,$hoy,0);
        echo "REPORTE OK: ".count($datos['cajas'])." cajas, ".count($datos['turnos'])." turnos y ".count($datos['retiros'])." retiros visibles.\n";
        foreach($datos['avisos'] as $a)echo 'AVISO: '.$a."\n";
    }else echo "No se ejecutó el reporte por diferencias de esquema.\n";
}catch(Throwable $e){
    fwrite(STDERR,"ERROR de diagnóstico: ".$e->getMessage()."\n");exit(1);
}
?>
