<?php
declare(strict_types=1);
/** G&D: perfil de caja limitado. No altera SQL, ventas ni estructuras de datos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Ejecutar desde consola.'); }
$accion=$argv[1]??'';
if(!in_array($accion,['--check','--apply','--verify','--rollback'],true)){
    fwrite(STDERR,"Uso: php instalar_perfil_cajero.php --check|--apply|--verify|--rollback\n"); exit(2);
}
$base=dirname(__DIR__);
$files=['app/helpers/auth.php','public/pos.php','public/pos_api.php','public/gyd_clientes.php'];
$pointer=__DIR__.'/.gyd_perfil_cajero_respaldo.json';
function abortInstall(string $error):never { fwrite(STDERR,"ERROR: $error\n"); exit(1); }
function oneStr(string $s,string $needle,string $replacement,string $description):string {
    if(substr_count($s,$needle)!==1) throw new RuntimeException('No encontré un único anclaje seguro: '.$description);
    return str_replace($needle,$replacement,$s);
}
function oneRegex(string $s,string $pattern,callable $replacer,string $description):string {
    $matches=[]; $qty=preg_match_all($pattern,$s,$matches,PREG_SET_ORDER);
    if($qty!==1)throw new RuntimeException('No encontré un único bloque: '.$description.' ('.($qty===false?'regex':$qty).')');
    $res=preg_replace_callback($pattern,$replacer,$s,1);
    if($res===null)throw new RuntimeException('No fue posible actualizar: '.$description);
    return $res;
}
function transform(string $rel,string $s):string {
    switch($rel){
    case 'app/helpers/auth.php':
        if(str_contains($s,'GYD_PERFIL_CAJERO_RUTAS'))return $s;
        $old="\$permitidos = ['pos.php','pos_api.php','pos_ticket.php','pos_historial.php','logout.php','session_keepalive.php'];";
        $new="/* GYD_PERFIL_CAJERO_RUTAS: exclusivamente operación POS y alta/búsqueda de clientes. */\n    \$permitidos = [\n        'pos.php','pos_api.php','pos_ticket.php','pos_historial.php',\n        'pos_retiros.php','pos_retiro_ticket.php','pos_retiros_estado.php',\n        'pos_corte_retiros.php','pos_eficiencia_api.php',\n        'gyd_clientes.php','gyd_clientes_api.php',\n        'logout.php','session_keepalive.php'\n    ];";
        return oneStr($s,$old,$new,'lista de rutas de CAJERO');
    case 'public/pos_api.php':
        if(str_contains($s,'GYD_PERFIL_CAJERO_SOLO_PRECIOS'))return $s;
        return oneStr($s,"\$out=\$model->catalogo((string)(\$_GET['q']??''),(int)\$c['id'],(int)(\$_GET['page']??1),(\$_GET['solo_precio']??'1')!=='0');",
            "// GYD_PERFIL_CAJERO_SOLO_PRECIOS: una URL manipulada no muestra productos sin precio al cajero.\n            \$soloPrecio=(strtoupper((string)(\$u['rol']??''))==='CAJERO') || ((\$_GET['solo_precio']??'1')!=='0');\n            \$out=\$model->catalogo((string)(\$_GET['q']??''),(int)\$c['id'],(int)(\$_GET['page']??1),\$soloPrecio);",
            'catálogo POS / solo precio');
    case 'public/gyd_clientes.php':
        if(str_contains($s,'GYD_PERFIL_CAJERO_CLIENTES'))return $s;
        $s=oneRegex($s,'~<a href="gyd_canjes\.php"[^>]*>[^<]*</a>~u',
          static fn(array $m):string=>'<?php /* GYD_PERFIL_CAJERO_CLIENTES */ if($admin): ?>'.$m[0].'<?php endif; ?>',
          'acceso al laboratorio desde Clientes');
        // Conservar la interfaz sin identificadores de versiones visibles.
        $s=str_replace(' · V37</title>','</title>',$s);
        $s=str_replace(' <small style="font-size:12px">V38</small>','',$s);
        $s=str_replace('Auditoría de devoluciones · V37','Auditoría de devoluciones',$s);
        return $s;
    case 'public/pos.php':
        if(str_contains($s,'GYD_PERFIL_CAJERO_VISTA'))return $s;
        $s=oneStr($s,'$u=currentUser()??[];',"\$u=currentUser()??[];\n/* GYD_PERFIL_CAJERO_VISTA: permisos sólo desde sesión validada, no desde parámetros del navegador. */\n\$posEsAdmin=in_array(strtoupper((string)(\$u['rol']??'')),['ADMINISTRADOR','DIRECCION'],true);",'rol del POS');
        $s=oneRegex($s,'~<main class="app"><div class="notice">.*?</div>~s',static function(array $m):string {
            $notice=substr($m[0],strlen('<main class="app">'));
            return '<main class="app"><?php if($posEsAdmin): ?>'.$notice.
                '<?php else: ?><div class="notice"><strong>ENTORNO DE PRUEBAS</strong><span>Operación de caja de demostración. Las ventas se registran únicamente en la base de pruebas.</span></div><?php endif; ?>';
        },'aviso de pruebas');
        $s=oneRegex($s,'~<div class="gyd-operaciones-efectivo">.*?</div>~s',static function(array $m):string {
            return '<?php if($posEsAdmin): ?>'.$m[0].'<?php else: ?>'.
                '<a id="gyd-retiro-link" href="pos_retiros.php" hidden style="display:none" tabindex="-1" aria-hidden="true"></a>'.
                '<?php endif; ?>';
        },'accesos de Administración en el POS');
        // El cuadro de arqueo sólo se presenta a Administración; el script existente tolera su ausencia.
        $s=oneRegex($s,'~<div id="gyd-arqueo-overlay" hidden.*?</form>\s*</div>\s*</div>~s',
            static fn(array $m):string=>'<?php if($posEsAdmin): ?>'.$m[0].'<?php endif; ?>',
            'modal de arqueo gerencial');
        $s=oneRegex($s,'~<a href="gyd_clientes\.php" target="_blank" rel="noopener">[^<]*</a>~u',
            static fn(array $m):string=>'<?php if($posEsAdmin): ?>'.$m[0].'<?php endif; ?>',
            'catálogo completo de clientes en el modal');
        $s=oneStr($s,'<label class="catalog-toggle"><input type="checkbox" id="catalog_all">',
            '<label class="catalog-toggle" <?= $posEsAdmin ? \'\' : \'hidden style="display:none"\' ?>><input type="checkbox" id="catalog_all">',
            'opción de productos sin precio');
        // El cajero puede elegir autoimpresión, pero no necesita ver instrucciones de configuración de Chrome.
        $s=oneRegex($s,'~<div class="small">Para imprimir sin diálogo.*?</div>~s',
            static fn(array $m):string=>'<?php if($posEsAdmin): ?>'.$m[0].'<?php endif; ?>',
            'texto de configuración de impresión');
        return $s;
    default:throw new RuntimeException('Archivo fuera de alcance: '.$rel);
    }
}
function inspectPatched(string $rel,string $text):bool {
    $markers=['app/helpers/auth.php'=>'GYD_PERFIL_CAJERO_RUTAS','public/pos.php'=>'GYD_PERFIL_CAJERO_VISTA','public/pos_api.php'=>'GYD_PERFIL_CAJERO_SOLO_PRECIOS','public/gyd_clientes.php'=>'GYD_PERFIL_CAJERO_CLIENTES'];
    if(!str_contains($text,$markers[$rel]))return false;
    if($rel==='app/helpers/auth.php')return str_contains($text,"'gyd_clientes_api.php'") && str_contains($text,"'pos_retiros.php'") && !str_contains($text,"'gyd_canjes.php','gyd_canjes_api.php'");
    if($rel==='public/pos.php')return str_contains($text,'<?php if($posEsAdmin): ?>') && str_contains($text,'id="gyd-retiro-link" href="pos_retiros.php" hidden style="display:none"') && str_contains($text,'id="gyd_cliente_buscar"') && str_contains($text,'id="gyd_cliente_nuevo"');
    if($rel==='public/pos_api.php')return str_contains($text,"\$soloPrecio=(strtoupper((string)(\$u['rol']??''))==='CAJERO')");
    if($rel==='public/gyd_clientes.php')return str_contains($text,'if($admin): ?>');
    return true;
}
try {
    $old=[];$new=[];$already=true;
    foreach($files as $rel){
        $path=$base.'/'.$rel;
        if(!is_file($path))throw new RuntimeException('Falta '.$rel.'. Instala primero la versión operativa del ERP.');
        $old[$rel]=(string)file_get_contents($path);
        $new[$rel]=transform($rel,$old[$rel]);
        if(!inspectPatched($rel,$new[$rel]))throw new RuntimeException('Las validaciones de permisos no pasaron en '.$rel);
        if($new[$rel]!==$old[$rel])$already=false;
    }
    if($accion==='--verify'){
        if(!$already)throw new RuntimeException('La actualización no está aplicada en todos los archivos.');
        echo "VERIFICADO · Perfil cajero limitado, altas y búsquedas permitidas, configuración restringida.\n";exit(0);
    }
    if($accion==='--rollback'){
        if(!is_file($pointer))throw new RuntimeException('No hay respaldo registrado para restaurar.');
        $p=json_decode((string)file_get_contents($pointer),true,512,JSON_THROW_ON_ERROR);
        $folder=(string)($p['folder']??'');
        if(!str_starts_with($folder,$base.'/backups/perfil_cajero_') || !is_dir($folder))throw new RuntimeException('Respaldo inválido.');
        foreach($files as $rel){
            if(hash('sha256',$old[$rel])!==($p['after'][$rel]??''))throw new RuntimeException('Hay cambios posteriores en '.$rel.'; no se restaurará automáticamente.');
            $src=$folder.'/'.$rel;
            if(!is_file($src)||hash_file('sha256',$src)!==($p['before'][$rel]??''))throw new RuntimeException('Respaldo incompleto: '.$rel);
        }
        foreach($files as $rel){if(!copy($folder.'/'.$rel,$base.'/'.$rel))throw new RuntimeException('No se pudo restaurar '.$rel);}
        echo "RESTAURADO · Se recuperó el código anterior.\n";exit(0);
    }
    if($already){echo "APLICADO ANTERIORMENTE · No hay cambios pendientes.\n";exit(0);}
    if($accion==='--check'){
        echo "COMPATIBLE · Los 4 archivos se pueden actualizar sin sustituir el POS ni tocar MySQL.\n";
        foreach($files as $rel) echo '  '.($old[$rel]===$new[$rel]?'YA PREPARADO: ':'LISTO: ').$rel."\n";
        exit(0);
    }
    // Se recomienda detener Apache antes de llegar a este punto.
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $folder=$base.'/backups/perfil_cajero_'.$stamp;
    foreach($files as $rel){
        $dst=$folder.'/'.$rel;
        if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0775,true)&&!is_dir(dirname($dst)))throw new RuntimeException('No se pudo crear respaldo de '.$rel);
        if(file_put_contents($dst,$old[$rel],LOCK_EX)===false)throw new RuntimeException('No se pudo respaldar '.$rel);
    }
    $changed=[];
    try {
        foreach($files as $rel){
            if($old[$rel]===$new[$rel])continue;
            $target=$base.'/'.$rel;
            $tmp=tempnam(dirname($target),'.gyd_cajero_');
            if($tmp===false||file_put_contents($tmp,$new[$rel],LOCK_EX)===false)throw new RuntimeException('No se pudo preparar '.$rel);
            if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('No se pudo actualizar '.$rel);}
            $changed[]=$rel;
            if(hash_file('sha256',$target)!==hash('sha256',$new[$rel]))throw new RuntimeException('Fallo al verificar '.$rel);
        }
        $meta=['folder'=>$folder,'before'=>[],'after'=>[]];
        foreach($files as $rel){$meta['before'][$rel]=hash('sha256',$old[$rel]);$meta['after'][$rel]=hash('sha256',$new[$rel]);}
        if(file_put_contents($pointer,json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('No se pudo registrar el respaldo.');
    }catch(Throwable $e){
        foreach(array_reverse($changed) as $rel)@copy($folder.'/'.$rel,$base.'/'.$rel);
        throw new RuntimeException($e->getMessage().'. Se intentó restaurar el estado anterior.');
    }
    echo "APLICADO · Permisos de cajero y vista simplificada instalados. Respaldo: ".$folder."\n";
    echo "Ejecuta --verify; después reinicia Apache y actualiza con Ctrl+F5.\n";
}catch(Throwable $e){abortInstall($e->getMessage());}
