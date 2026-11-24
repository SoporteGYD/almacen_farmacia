<?php
declare(strict_types=1);
/** Auditoría F1 de sucursales y almacenes. Únicamente CLI y consultas SELECT. */
if(PHP_SAPI !== 'cli'){http_response_code(403);exit('Solo terminal.');}
require_once __DIR__.'/gyd_f1_topologia_lib.php';
$args=array_slice($argv,1);
$check=in_array('--check',$args,true);
$run=in_array('--run',$args,true);
$json=in_array('--json',$args,true);
$esperada='prueba';
foreach($args as $arg){if(str_starts_with($arg,'--expect='))$esperada=substr($arg,9);}
if(!$check&&!$run){fwrite(STDERR,"Uso: php tools/gyd_f1_verificar_sucursales_20261103.php --check | --run [--expect=prueba] [--json]\n");exit(2);}
if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$esperada)){fwrite(STDERR,"Nombre de base inválido.\n");exit(2);}
$root=dirname(__DIR__);
$req=['app/config/database.php','app/helpers/pos_inteligente.php','app/models/PuntoVenta.php'];
$faltan=array_values(array_filter($req,static fn($r)=>!is_file($root.'/'.$r)));
if($faltan){fwrite(STDERR,"INCOMPATIBLE: faltan archivos de ERP: ".implode(', ',$faltan)."\n");exit(2);}
if($check){echo "COMPATIBLE: archivos base presentes. No se consultó ni modificó la base.\n";echo "Para evaluar sucursales y almacenes ejecuta --run --expect=prueba\n";exit(0);}
try {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $reporte=gydF1Ejecutar($db,$esperada);
    if($json){echo json_encode($reporte,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n";exit(0);}
    echo "G&D | F1 Sucursales y almacenes | SOLO LECTURA\n";
    echo "Base: ".$reporte['base']." | ".$reporte['fecha']."\n";
    echo str_repeat('-',68)."\n";
    foreach($reporte['hallazgos'] as $x)echo '['.$x['estado'].'] '.$x['clave'].' — '.$x['detalle']."\n";
    echo str_repeat('-',68)."\n";
    echo "Conteo por estado: ".json_encode($reporte['resumen'],JSON_UNESCAPED_UNICODE)."\n";
    echo $reporte['conclusion']."\n";
    echo "Para incluir muestras y tablas utiliza --json.\n";
} catch(Throwable $e) {
    // Mensaje sin credenciales; la causa detallada queda en el log de PHP.
    error_log('G&D auditoría topología F1: '.$e->getMessage());
    fwrite(STDERR,"ERROR: no se pudo verificar la estructura o el destino. No se modificó nada.\n");
    exit(2);
}
