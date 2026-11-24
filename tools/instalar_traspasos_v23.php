<?php
declare(strict_types=1);
/** Instalador aditivo y verificable. Nunca sobreescribe un módulo de otro proyecto. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Uso exclusivo de consola.');}
$accion=$argv[1]??'--check';if(!in_array($accion,['--check','--apply'],true)){fwrite(STDERR,"Usa --check o --apply\n");exit(2);}
$base=__DIR__.'/_traspasos_payload';$raiz=dirname(__DIR__);
$map=[
 'app/models/TraspasosGyD.php'=>'app/models/TraspasosGyD.php',
 'public/traspasos.php'=>'public/traspasos.php',
];
$header=$raiz.'/app/views/layouts/header.php';
$marker="// GYD_TRANSFERENCIAS_INTER_SUCURSALES";
$menu="\n".$marker."\n".'$menuAgregar(\'📦 Operación de almacén\', \'Traspasos de Mercancía\', \'traspasos.php\', $menuEsAdmin || $esGerente || $esEncargado);'."\n";
$ancla="// Existencias y conteos.";
try {
    if(!is_file($header))throw new RuntimeException('No se encontró app/views/layouts/header.php.');
    $h=file_get_contents($header);if($h===false)throw new RuntimeException('No fue posible leer el menú.');
    $tieneMarker=str_contains($h,$marker);if(!$tieneMarker&&substr_count($h,$ancla)!==1)throw new RuntimeException('El menú fue modificado: no se encontró el punto único de inserción.');
    foreach($map as $src=>$dst){
        $a=$base.'/'.$src;$b=$raiz.'/'.$dst;
        if(!is_file($a))throw new RuntimeException('Paquete incompleto: '.$src);
        if(is_file($b) && hash_file('sha256',$a)!==hash_file('sha256',$b))throw new RuntimeException('El archivo destino ya existe con modificaciones diferentes: '.$dst.'. No se sobrescribirá.');
    }
    $sql=file_get_contents($base.'/sql/instalar_traspasos.sql');if($sql===false||!str_contains($sql,'erp_traspasos_detalle'))throw new RuntimeException('Archivo SQL incompleto.');
    require_once $raiz.'/app/config/database.php';$db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($nombre!=='prueba')throw new RuntimeException('Se requiere database=prueba, se encontró: '.$nombre.'. No se instalará en otra base.');
    $requeridos=[
      'pos_sucursales'=>['id','nombre','activo'],
      'pos_existencias_farmacia'=>['sucursal_id','producto_id','existencia'],
      'erp_pos_farmacia_control'=>['sucursal_id','producto_id','existencia','minimo'],
      'producto_existencias'=>['id','producto_id','sucursal','ubicacion','existencia'],
      'productos'=>['id','codigo','codigo_barras','descripcion','estado'],
      'usuarios'=>['id','rol','almacen_id'],
      'resurtidos'=>['id','almacen_id','estado'],
      'resurtido_detalles'=>['resurtido_id','producto_id','cantidad_solicitada','cantidad_surtida'],
      'erp_pos_tickets_almacen'=>['resurtido_id','sucursal_id','producto_id','venta_id','cantidad'],
      'erp_pos_alertas'=>['sucursal_id','producto_id','estado','existencia','minimo'],
    ];
    foreach($requeridos as $tabla=>$campos){
       $s=$db->query("SHOW COLUMNS FROM `".$tabla."`")->fetchAll(PDO::FETCH_COLUMN);
       $faltan=array_diff($campos,$s);if($faltan)throw new RuntimeException('Faltan columnas en '.$tabla.': '.implode(',',$faltan));
    }
    if(PHP_VERSION_ID<80100)throw new RuntimeException('Se requiere PHP 8.1 o superior.');
    // La reserva, salida y recepción se efectúan en una sola transacción:
    // no se permite un motor no transaccional en las tablas afectadas.
    foreach(['pos_existencias_farmacia','producto_existencias','erp_pos_farmacia_control'] as $tabla){
        $st=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$tabla]);
        if(strtoupper((string)$st->fetchColumn())!=='INNODB')throw new RuntimeException('Se requiere InnoDB en '.$tabla.' para garantizar rollback.');
    }
    $indices=$db->query("SHOW INDEX FROM pos_existencias_farmacia")->fetchAll(PDO::FETCH_ASSOC);
    $pares=[];foreach($indices as $idx){
      if((int)$idx['Non_unique']!==0)continue;
      $k=(string)$idx['Key_name'];$pares[$k][(int)$idx['Seq_in_index']]=(string)$idx['Column_name'];
    }
    $unico=false;foreach($pares as $p){ksort($p);if(array_values($p)===['sucursal_id','producto_id'])$unico=true;}
    if(!$unico)throw new RuntimeException('Falta un índice único (sucursal_id, producto_id) en pos_existencias_farmacia; no es seguro acreditar traslados.');
    // Comprobar instalaciones parciales incluso en modo --check.
    $esperadas=[
      'erp_traspasos'=>['id','folio','origen_id','destino_id','origen_tipo','destino_tipo','modalidad','estado','solicitado_por'],
      'erp_traspasos_detalle'=>['traspaso_id','producto_id','solicitado','enviado','recibido','ubicacion_origen_id'],
      'erp_traspasos_recepciones'=>['id','traspaso_id','token','usuario_id'],
      'erp_traspasos_recepciones_detalle'=>['recepcion_id','producto_id','cantidad'],
      'erp_traspasos_eventos'=>['id','traspaso_id','usuario_id','accion'],
    ];
    foreach($esperadas as $tabla=>$campos){
      $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
      $st->execute([$tabla]);if((int)$st->fetchColumn()===0)continue;
      $cols=$db->query('SHOW COLUMNS FROM `'.$tabla.'`')->fetchAll(PDO::FETCH_COLUMN);
      $faltan=array_diff($campos,$cols);
      if($faltan)throw new RuntimeException('Tabla existente incompleta: '.$tabla.' ('.implode(',',$faltan).').');
    }
    $suc=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach([1=>'CIUDAD HIDALGO',2=>'TUXTLA',3=>'TAPACHULA',4=>'TOSCANA'] as $id=>$nom){
        if(!isset($suc[$id])||!str_contains(strtoupper((string)$suc[$id]),$nom))throw new RuntimeException("Asignación POS de la sucursal $id no coincide con el esquema revisado. Contacta a Sistemas antes de aplicar.");
    }
    echo "COMPATIBLE · Base: prueba · POS (4 sucursales) · Archivos y menú reconocidos.\n";
    if($accion==='--check'){echo "Verificación de solo lectura: no se modificó ningún archivo ni tabla.\n";exit(0);}
    // SQL DDL de tablas NUEVAS. Los comentarios se eliminan antes de separar sentencias.
    $limpio=preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m','',$sql);
    $sentencias=array_filter(array_map('trim',explode(';',$limpio)),static fn($x)=>$x!=='');
    foreach($sentencias as $st){if(!preg_match('/^CREATE TABLE IF NOT EXISTS erp_traspasos(?:_detalle|_recepciones|_recepciones_detalle|_eventos)?\b/i',$st))throw new RuntimeException('SQL inesperado. Revisa el paquete antes de instalar.');}
    foreach($sentencias as $st)$db->exec($st);
    // Luego de crear tablas, validar esquema actual: puede existir una instalación parcial antigua.
    foreach($esperadas as $tabla=>$campos){$cols=$db->query('SHOW COLUMNS FROM `'.$tabla.'`')->fetchAll(PDO::FETCH_COLUMN);$faltan=array_diff($campos,$cols);if($faltan)throw new RuntimeException('La tabla '.$tabla.' está incompleta; faltan: '.implode(',',$faltan));}
    $respaldo=$raiz.'/tools/backups_traspasos_'.date('Ymd_His');if(!is_dir($respaldo)&&!mkdir($respaldo,0775,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
    foreach($map as $src=>$dst){$origen=$base.'/'.$src;$destino=$raiz.'/'.$dst;
        if(is_file($destino))continue;
        if(!is_dir(dirname($destino))||!copy($origen,$destino))throw new RuntimeException('No se pudo instalar: '.$dst);
        echo 'Creado: '.$dst."\n";
    }
    if(!$tieneMarker){
        if(!copy($header,$respaldo.'/header.php'))throw new RuntimeException('No se pudo respaldar el menú.');
        $nuevo=str_replace($ancla,$menu.$ancla,$h);
        // El menú compacto de ENCARGADO selecciona una lista blanca de rutas.
        $listaEncargado="'salidas.php', 'devoluciones.php',";
        if(str_contains($nuevo,$listaEncargado))$nuevo=str_replace($listaEncargado,"'salidas.php', 'devoluciones.php', 'traspasos.php',",$nuevo);
        if(file_put_contents($header,$nuevo,LOCK_EX)===false)throw new RuntimeException('No se pudo agregar el enlace al menú.');
        echo "Menú actualizado; respaldo en tools/backups_traspasos_*.\n";
    }
    echo "INSTALACIÓN COMPLETADA. Abre public/traspasos.php.\n";
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: '.$e->getMessage()."\n");exit(1);}
