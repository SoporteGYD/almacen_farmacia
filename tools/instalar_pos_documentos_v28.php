<?php
declare(strict_types=1);
/** Instalador conservador. --check no modifica archivos ni tablas. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo terminal.');}
$action=$argv[1]??'--help';
if(!in_array($action,['--check','--apply'],true)){
    echo "Uso: php tools/instalar_pos_documentos_v28.php --check|--apply\n";exit(2);
}
$root=dirname(__DIR__);$payload=__DIR__.'/gyd_pos_modulo_payload';
$files=[
    'public/pos.php',
    'public/pos_api.php',
    'public/assets/js/pos_gyd.js',
    'app/models/PuntoVenta.php',
    'app/helpers/auth.php',
    'app/config/database.php'
];
try {
    foreach($files as $file){if(!is_file($root.'/'.$file))throw new RuntimeException('No se encontró: '.$file);}
    foreach(['app/helpers/gyd_pos_documentos.php','public/assets/js/gyd_pos_documentos.js','public/assets/css/gyd_pos_documentos.css','sql/gyd_pos_documentos.sql'] as $file){
        if(!is_file($payload.'/'.$file))throw new RuntimeException('Falta en paquete: '.$file);
    }
    $page=file_get_contents($root.'/public/pos.php');$api=file_get_contents($root.'/public/pos_api.php');
    $js=file_get_contents($root.'/public/assets/js/pos_gyd.js');$posModel=file_get_contents($root.'/app/models/PuntoVenta.php');
    if($page===false||$api===false||$js===false||$posModel===false)throw new RuntimeException('No se pudieron leer archivos.');
    foreach(['let cart=new Map()','function add(','function rowCart(','async function fetchCatalog('] as $needle){
        if(!str_contains($js,$needle))throw new RuntimeException('La versión del carrito no es reconocida: '.$needle.'. No se modificó nada.');
    }
    foreach(['function cajaAutorizada(','function ticket('] as $needle){
        if(!str_contains($posModel,$needle))throw new RuntimeException('El modelo PuntoVenta no contiene '.$needle.'. No se modificó nada.');
    }
    $htmlInstalled=str_contains($page,'gyd_pos_documentos.js');
    $apiInstalled=str_contains($api,'GydPosDocumentos');
    if($htmlInstalled xor $apiInstalled)throw new RuntimeException('Instalación parcial previa: revisa pos.php y pos_api.php. No se modificó nada.');
    if(!$htmlInstalled){
        if(substr_count($page,'</head>')!==1||substr_count($page,'</body>')!==1)throw new RuntimeException('No se reconoce la estructura HTML de pos.php.');
        $page=str_replace('</head>','<link rel="stylesheet" href="assets/css/gyd_pos_documentos.css"></head>',$page);
        $page=str_replace('</body>','<script src="assets/js/gyd_pos_documentos.js"></script></body>',$page);
    }
    if(!$apiInstalled){
        $include="require_once __DIR__.'/../app/models/PuntoVenta.php';";
        $beforeGet="if(\$accion==='sucursales'){";
        $beforePost='switch($accion){';
        foreach([$include,$beforeGet,$beforePost] as $mark){
            if(substr_count($api,$mark)!==1)throw new RuntimeException('No se reconoce el punto de integración de pos_api.php: '.$mark.'. No se modificó nada.');
        }
        $api=str_replace($include,$include."\nrequire_once __DIR__.'/../app/helpers/gyd_pos_documentos.php';",$api);
        $get="if(\$accion==='documentos_listar'){\$out=(new GydPosDocumentos(\$u,\$model))->listar(\$caja);}\n"
            ."        elseif(\$accion==='documentos_detalle'){\$out=(new GydPosDocumentos(\$u,\$model))->detalle(\$caja,(int)(\$_GET['documento_id']??0));}\n"
            ."        elseif(\$accion==='documentos_repetir'){\$out=(new GydPosDocumentos(\$u,\$model))->repetir(\$caja,(int)(\$_GET['venta_id']??0));}\n"
            ."        elseif(\$accion==='sucursales'){";
        $api=str_replace($beforeGet,$get,$api);
        $post="switch(\$accion){\n"
            ."            case 'documentos_guardar': \$out=(new GydPosDocumentos(\$u,\$model))->guardar(\$caja,\$post);break;\n"
            ."            case 'documentos_eliminar': \$out=(new GydPosDocumentos(\$u,\$model))->eliminar(\$caja,(int)(\$post['documento_id']??0));break;";
        $api=str_replace($beforePost,$post,$api);
    }
    $targetHelper=$root.'/app/helpers/gyd_pos_documentos.php';
    $targetJS=$root.'/public/assets/js/gyd_pos_documentos.js';
    $targetCSS=$root.'/public/assets/css/gyd_pos_documentos.css';
    $originals=[$root.'/public/pos.php'=>$page,$root.'/public/pos_api.php'=>$api,
        $targetHelper=>file_get_contents($payload.'/app/helpers/gyd_pos_documentos.php'),
        $targetJS=>file_get_contents($payload.'/public/assets/js/gyd_pos_documentos.js'),
        $targetCSS=>file_get_contents($payload.'/public/assets/css/gyd_pos_documentos.css')];
    foreach([$targetHelper,$targetJS,$targetCSS] as $f){
        if(is_file($f)&&!hash_equals(hash_file('sha256',$f),hash('sha256',$originals[$f])))throw new RuntimeException('El archivo '.$f.' ya existe con cambios distintos. No se sobrescribirá.');
    }
    // Conectar a la BD real a través de la configuración actual, sin inventar credenciales.
    require_once $root.'/app/config/database.php';
    $pdo=(new Database())->connect();
    $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if($db!=='prueba')throw new RuntimeException('La actualización solo está autorizada para la base de pruebas "prueba" (actual: '.$db.').');
    $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $q->execute([$db,'gyd_pos_documentos']);
    $got=$q->fetchAll(PDO::FETCH_COLUMN);
    $needed=['id','usuario_id','caja_id','tipo','titulo','cliente_nombre','quien_lleva','items_json','creado_en','actualizado_en'];
    if($got && array_diff($needed,$got))throw new RuntimeException('La tabla gyd_pos_documentos existe con una estructura distinta. No se modificó.');
    if($action==='--check'){
        echo 'COMPATIBLE: archivos reconocidos; base prueba disponible; tabla '.($got?'compatible':'pendiente de crear').'; interfaz '.($htmlInstalled?'ya instalada':'por instalar').".\n";
        echo "Este modo no cambia archivos ni datos. Haz respaldo de archivos y SQL antes de --apply.\n";
        exit(0);
    }
    $backup=$root.'/tools/backups_pos_documentos/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!is_dir($backup)&&!mkdir($backup,0775,true))throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
    $previous=[];$modified=[];
    try {
        foreach($originals as $path=>$content){
            $relative=substr($path,strlen($root)+1);
            $previous[$path]=is_file($path)?file_get_contents($path):null;
            if($previous[$path]!==null){
                $dest=$backup.'/'.$relative;
                if(!is_dir(dirname($dest)))mkdir(dirname($dest),0775,true);
                if(file_put_contents($dest,$previous[$path])===false)throw new RuntimeException('Falló un respaldo: '.$relative);
            }
        }
        if(!$got){
            $sql=file_get_contents($payload.'/sql/gyd_pos_documentos.sql');
            $pdo->exec($sql); // Sentencia única: no se divide por ; para evitar errores.
        }
        foreach($originals as $path=>$content){
            if($previous[$path]!==$content){
                if(!is_dir(dirname($path)))mkdir(dirname($path),0775,true);
                if(file_put_contents($path,$content,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$path);
                $modified[]=$path;
            }
        }
    }catch(Throwable $ex){
        foreach(array_reverse($modified) as $path){
            if($previous[$path]===null)@unlink($path);else @file_put_contents($path,$previous[$path],LOCK_EX);
        }
        throw new RuntimeException('Instalación detenida y archivos restaurados. La tabla auxiliar puede quedar creada sin registros: '.$ex->getMessage(),0,$ex);
    }
    echo "INSTALADO: pendientes, cotizaciones y repetir ticket.\nRespaldos: $backup\n";
    echo "Abre public/pos.php y recarga con Ctrl+F5. No se cambió el cobro ni se descontó inventario.\n";
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: '.$e->getMessage()."\n");exit(1);}
