<?php
declare(strict_types=1);
/** Instalador aditivo para G&D. Solo agrega registros de DEMO, no toca inventarios. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$action=$argv[1]??'--check';
if(!in_array($action,['--check','--apply'],true)){fwrite(STDERR,"Modo de uso: --check o --apply\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/_cartaporte_demo_payload';
$map=[
    'app/models/CartaPorteDemoGyD.php'=>'app/models/CartaPorteDemoGyD.php',
    'public/carta_porte_demo.php'=>'public/carta_porte_demo.php',
];
$transfer=$root.'/public/traspasos.php';
$anchor='<a class="btn alt noprint" href="traspasos.php">Volver</a>';
$addon='<!-- GYD_CARTA_PORTE_DEMO --> <a class="btn alt noprint" href="carta_porte_demo.php?id=<?=(int)$detalle[\'id\']?>">Carta Porte DEMO</a>';
$marker='<!-- GYD_CARTA_PORTE_DEMO -->';
try {
    if(PHP_VERSION_ID<80100)throw new RuntimeException('Se requiere PHP 8.1 o superior.');
    if(!is_file($root.'/app/models/TraspasosGyD.php')||!is_file($transfer))throw new RuntimeException('Primero debe estar instalado el módulo Traspasos de Mercancía.');
    $header=file_get_contents($transfer);if($header===false)throw new RuntimeException('No puede leerse el módulo de traspasos.');
    $linkPresent=str_contains($header,$marker);
    $linkPossible=substr_count($header,$anchor)===1;
    foreach($map as $source=>$destination){
        $a=$payload.'/'.$source;$b=$root.'/'.$destination;
        if(!is_file($a))throw new RuntimeException('Falta parte del ZIP: '.$source);
        if(is_file($b)&&hash_file('sha256',$a)!==hash_file('sha256',$b))throw new RuntimeException('El archivo '.$destination.' ya tiene cambios distintos. No se sobrescribirá.');
    }
    $sqlFile=$payload.'/sql/instalar_carta_porte_demo.sql';
    $sql=file_get_contents($sqlFile);
    if($sql===false)throw new RuntimeException('No se encontró archivo SQL.');
    $clean=preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m','',$sql);
    $stmt=trim((string)$clean);$stmt=rtrim($stmt,';');
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS erp_carta_porte_demo\s*\(/i',$stmt)
        ||substr_count($stmt,';')!==0||!str_contains($stmt,'es_fiscal'))throw new RuntimeException('SQL no corresponde a la tabla DEMO autorizada.');
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($database!=='prueba')throw new RuntimeException('Protección: esta actualización solo está habilitada en database=prueba. Base actual: '.$database);
    $required=[
        'erp_traspasos'=>['id','folio','origen_id','destino_id','estado'],
        'erp_traspasos_detalle'=>['traspaso_id','producto_id','solicitado'],
        'productos'=>['id','codigo','descripcion'],
        'usuarios'=>['id','rol','almacen_id'],
    ];
    foreach($required as $table=>$cols){
        $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->execute([$table]);
        if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta tabla requerida: '.$table);
        $present=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
        if($diff=array_diff($cols,$present))throw new RuntimeException('Columnas ausentes en '.$table.': '.implode(', ',$diff));
    }
    $exists=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute(['erp_carta_porte_demo']);
    if((int)$exists->fetchColumn()===1){
        $cols=$db->query('SHOW COLUMNS FROM `erp_carta_porte_demo`')->fetchAll(PDO::FETCH_COLUMN);
        $expected=['id','traspaso_id','token','folio_demo','resultado','mensaje','resumen_json','huella_sha256','operador_id','fecha_registro','ambiente','es_fiscal'];
        if($diff=array_diff($expected,$cols))throw new RuntimeException('Existe tabla DEMO incompatible, faltan: '.implode(', ',$diff));
        $incompat=(int)$db->query("SELECT COUNT(*) FROM erp_carta_porte_demo WHERE ambiente<>'DEMO' OR es_fiscal<>0")->fetchColumn();
        if($incompat!==0)throw new RuntimeException('La tabla DEMO tiene registros incompatibles con el aislamiento fiscal; se detuvo la instalación.');
    }
    echo "COMPATIBLE · Módulo Traspasos detectado · Base prueba · Archivos reconocidos.\n";
    if(!$linkPresent&&!$linkPossible)echo "AVISO: el menú de Traspasos fue personalizado. El nuevo módulo se abrirá mediante URL directa.\n";
    if($action==='--check'){echo "Verificación de solo lectura. No se realizó ninguna modificación.\n";exit(0);}
    // En MySQL/MariaDB CREATE TABLE es DDL y no puede revertirse con rollback.
    $db->exec($stmt);
    $backup=$root.'/tools/backups_carta_porte_demo_'.date('Ymd_His');
    if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    foreach($map as $src=>$dst){
        $a=$payload.'/'.$src;$b=$root.'/'.$dst;
        if(is_file($b)){echo 'Ya instalado: '.$dst."\n";continue;}
        $temp=$b.'.cpdemo_'.bin2hex(random_bytes(4)).'.tmp';
        if(!copy($a,$temp)||!rename($temp,$b))throw new RuntimeException('No se pudo agregar '.$dst);
        echo 'Creado: '.$dst."\n";
    }
    if(!$linkPresent&&$linkPossible){
        if(!copy($transfer,$backup.'/traspasos.php'))throw new RuntimeException('No se pudo respaldar la pantalla de traspasos.');
        $new=str_replace($anchor,$anchor.' '.$addon,$header);
        if(file_put_contents($transfer,$new,LOCK_EX)===false)throw new RuntimeException('No se pudo agregar el enlace de Carta Porte DEMO.');
        echo 'Enlace integrado al detalle del traspaso. Respaldo: '.$backup."\n";
    }
    echo "INSTALACIÓN COMPLETADA · Solo agrega tabla de simulaciones y vistas DEMO. No modifica existencias, compras, ventas, despacho ni timbrados reales.\n";
} catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: '.$e->getMessage()."\n");exit(1);}
