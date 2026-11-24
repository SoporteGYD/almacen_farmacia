<?php
declare(strict_types=1);
/** Parche quirúrgico del formulario V23 y su integración V24: sin SQL ni movimientos. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola.');}
$accion=$argv[1]??'--check';
if(!in_array($accion,['--check','--apply'],true)){fwrite(STDERR,"Usa --check o --apply\n");exit(2);}
$raiz=dirname(__DIR__);
$origen=__DIR__.'/_traspasos_catalogo_payload/public';
$pagina=$raiz.'/public/traspasos.php';
$archivos=['traspasos_catalogo.php','traspasos_catalogo.css','traspasos_catalogo.js'];
$marca='id="tr_catalogo_sugerencias"';
function parchear(string $html):string {
    $start='<h3 style="margin-top:16px">Agregar productos</h3>';
    $end='<div class="scroll"><table style="min-width:480px">';
    if(substr_count($html,$start)!==1 || substr_count($html,$end)!==1)throw new RuntimeException('El formulario fue personalizado y no se reconocen los anclajes. No se modificarán archivos.');
    $a=strpos($html,$start);$b=strpos($html,$end,$a);
    if($a===false||$b===false||$a>=$b)throw new RuntimeException('Formulario de productos no reconocible.');
    $nuevo=<<<'HTML'
<h3 style="margin-top:16px">Agregar productos desde el catálogo</h3>
<p class="tc-ayuda">Busca por código, código de barras o descripción. Los saldos se consultan desde la ubicación de origen y se vuelven a verificar al despachar.</p>
<div class="formline"><label>Producto (código, barras o descripción)<input id="codigo" placeholder="Escribe o escanea para buscar..." autocomplete="off" maxlength="150" aria-controls="tr_catalogo_sugerencias"></label><label>Cantidad en piezas<input id="cantidad" type="number" value="1" min="1" max="100000"></label><button type="button" id="agregar">Agregar</button></div>
<div id="tr_catalogo_sugerencias" class="tc-sugerencias" role="listbox" hidden></div>
<div class="actions"><button type="button" class="alt" id="ver_catalogo">Ver catálogo completo</button><span id="tr_producto_elegido" class="tc-ayuda" aria-live="polite">Selecciona un producto del catálogo.</span></div>
<div id="tr_catalogo_overlay" class="tc-overlay" role="dialog" aria-modal="true" aria-label="Catálogo de productos" hidden>
  <div class="tc-panel"><div class="tc-top"><h2>Catálogo de productos</h2><button type="button" class="alt" id="tr_catalogo_cerrar">Cerrar</button></div>
  <label>Buscar artículo<input id="tr_catalogo_q" autocomplete="off" placeholder="Descripción, código o barras"></label>
  <div id="tr_catalogo_resultados" class="tc-results" aria-live="polite"></div>
  <div class="tc-bottom"><span class="tc-ayuda" id="tr_catalogo_pagina"></span><div class="actions" style="margin:0"><button type="button" class="alt" id="tr_catalogo_atras">Anterior</button><button type="button" class="alt" id="tr_catalogo_siguiente">Siguiente</button></div></div>
  <div class="tc-ayuda">Consulta del catálogo real. Las existencias pueden cambiar hasta confirmar el despacho.</div></div>
</div>
HTML;
    $html=substr($html,0,$a).$nuevo."\n".substr($html,$b);
    $opening='<?php if(!$detalle):?><script>';
    $closing='</script><?php endif;?>';
    if(substr_count($html,$opening)!==1 || substr_count($html,$closing)!==1)throw new RuntimeException('No se encontró el bloque JS original del formulario.');
    $x=strpos($html,$opening);$y=strpos($html,$closing,$x);
    if($y===false)throw new RuntimeException('JS del formulario no reconocible.');
    $html=substr($html,0,$x).'<?php if(!$detalle):?><script defer src="traspasos_catalogo.js"></script><?php endif;?>'.substr($html,$y+strlen($closing));
    $anchor='</style></head>';
    if(substr_count($html,$anchor)!==1)throw new RuntimeException('No se encontró un cierre de cabecera único.');
    return str_replace($anchor,'</style><link rel="stylesheet" href="traspasos_catalogo.css"></head>',$html);
}
try {
    if(PHP_VERSION_ID<80100)throw new RuntimeException('Se requiere PHP 8.1 o superior.');
    if(!is_file($pagina)||!is_file($raiz.'/app/models/TraspasosGyD.php')||!is_file($raiz.'/app/helpers/pos_inteligente.php'))throw new RuntimeException('Primero instala Traspasos V23.');
    $contenido=file_get_contents($pagina);if($contenido===false)throw new RuntimeException('No se pudo leer la pantalla Traspasos.');
    $instalado=str_contains($contenido,$marca);
    if($instalado){
        if(!str_contains($contenido,'traspasos_catalogo.js')||!str_contains($contenido,'traspasos_catalogo.css'))throw new RuntimeException('Parche previo incompleto o personalizado; se detuvo para protegerlo.');
        $nuevo=$contenido;
    }else{$nuevo=parchear($contenido);}
    foreach($archivos as $nombre){
        $src=$origen.'/'.$nombre;$dst=$raiz.'/public/'.$nombre;
        if(!is_file($src))throw new RuntimeException('Paquete incompleto: '.$nombre);
        if(is_file($dst)&&hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Existe una versión distinta de '.$nombre.'. No se sobrescribirá.');
    }
    require_once $raiz.'/app/config/database.php';
    $db=(new Database())->connect();
    $base=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($base!=='prueba')throw new RuntimeException('Protección activa: solo se permite database=prueba. Actual: '.$base);
    foreach(['productos'=>['id','codigo','codigo_barras','descripcion','estado'],
       'pos_sucursales'=>['id','nombre','activo'],
       'pos_existencias_farmacia'=>['producto_id','sucursal_id','existencia'],
       'erp_pos_farmacia_control'=>['producto_id','sucursal_id','existencia'],
       'producto_existencias'=>['producto_id','sucursal','ubicacion','existencia'],
       'erp_traspasos'=>['id','folio']] as $tabla=>$cols){
       $st=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
       $st->execute([$tabla]);if((int)$st->fetchColumn()!==1)throw new RuntimeException('Falta la tabla '.$tabla.'.');
       $actual=$db->query('SHOW COLUMNS FROM `'.$tabla.'`')->fetchAll(PDO::FETCH_COLUMN);
       if($d=array_diff($cols,$actual))throw new RuntimeException('Columnas incompatibles en '.$tabla.': '.implode(',',$d));
    }
    $st=$db->query('SELECT id,nombre FROM pos_sucursales WHERE activo=1');$sucs=$st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach([1=>'HIDALGO',2=>'TUXTLA',3=>'TAPACHULA',4=>'TOSCANA'] as $sid=>$palabra){
        if(!isset($sucs[$sid])||stripos((string)$sucs[$sid],$palabra)===false)throw new RuntimeException('Numeración de sucursales incompatible: '.$sid);
    }
    foreach(['traspasos_catalogo.php','traspasos_catalogo.js'] as $f){
        if($f==='traspasos_catalogo.php'){
            $salida=[];$codigo=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($origen.'/'.$f).' 2>&1',$salida,$codigo);
            if($codigo!==0)throw new RuntimeException('Sintaxis inválida en '.$f.': '.implode(' ',$salida));
        }
    }
    echo 'COMPATIBLE: catálogo real de productos y existencias · Base prueba · sin migraciones SQL'."\n";
    if($accion==='--check'){
        echo ($instalado?'El formulario ya contiene el buscador.':'El formulario se puede actualizar conservando el enlace de Carta Porte.')."\n";
        echo "No se modificó ningún archivo ni registro.\n";exit(0);
    }
    $respaldo=$raiz.'/tools/backups_traspasos_catalogo_'.date('Ymd_His');
    if(!is_dir($respaldo)&&!mkdir($respaldo,0775,true))throw new RuntimeException('No se pudo crear el respaldo.');
    if(!copy($pagina,$respaldo.'/traspasos.php'))throw new RuntimeException('No se pudo respaldar el archivo de traspasos.');
    $creados=[];
    try {
        foreach($archivos as $f){
            $dest=$raiz.'/public/'.$f;
            if(is_file($dest))continue;
            $tmp=$dest.'.tmp_'.bin2hex(random_bytes(4));
            if(!copy($origen.'/'.$f,$tmp)||!rename($tmp,$dest))throw new RuntimeException('No se pudo agregar '.$f);
            $creados[]=$dest;echo 'Creado: public/'.$f."\n";
        }
        if(!$instalado){
            $tmp=$pagina.'.tmp_'.bin2hex(random_bytes(4));
            if(file_put_contents($tmp,$nuevo,LOCK_EX)===false||!rename($tmp,$pagina))throw new RuntimeException('No se pudo actualizar Traspasos.');
            echo "Formulario actualizado.\n";
        }
    }catch(Throwable $e){
        foreach($creados as $f)@unlink($f);
        @copy($respaldo.'/traspasos.php',$pagina);
        throw $e;
    }
    echo "INSTALACIÓN COMPLETADA. Recarga public/traspasos.php con Ctrl+F5.\n";
    echo "Sin alteraciones de inventario ni modificaciones SQL. Respaldo: $respaldo\n";
}catch(Throwable $e){fwrite(STDERR,'INCOMPATIBLE: '.$e->getMessage()."\n");exit(1);}
