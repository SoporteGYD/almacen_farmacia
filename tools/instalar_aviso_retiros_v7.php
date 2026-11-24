<?php
declare(strict_types=1);
/**
 * G&D | Instalador aviso retiros V7. Compatible con retiro automático V6.
 * Conserva los movimientos y filas históricas. No ejecuta SQL de escritura.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Sólo consola.'); }
$verificar=in_array('--check', $argv, true);
$instalar=in_array('--apply', $argv, true);
if ($verificar === $instalar) {
    fwrite(STDERR, "Uso: php tools/instalar_aviso_retiros_v7.php --check | --apply\n");
    exit(2);
}
$raiz=dirname(__DIR__);
$origen=__DIR__.'/aviso_retiros_v7_payload';
$archivos=[
    'public/pos_retiros_estado.php',
    'public/assets/js/pos_retiros_aviso.js',
];
$errores=[];$cambios=[];$ya=0;
foreach ($archivos as $rel) {
    $destino=$raiz.'/'.$rel;$fuente=$origen.'/'.$rel;
    if (!is_file($destino) || !is_file($fuente)) { $errores[]='FALTA: '.$rel; continue; }
    $anterior=(string)file_get_contents($destino);
    $nuevo=(string)file_get_contents($fuente);
    if ($anterior === $nuevo) { $ya++; continue; }
    // Sólo reemplazar las variantes antiguas cuyo mecanismo está identificado.
    $reconocido=($rel==='public/pos_retiros_estado.php')
        ? str_contains($anterior,'erp_retiros_supervisados') && str_contains($anterior,'SOLICITADO') && str_contains($anterior,'gr_expected(')
        : str_contains($anterior,'pos_retiros_estado.php') && str_contains($anterior,'Hay un retiro solicitado sin confirmar.');
    if (!$reconocido) { $errores[]='INCOMPATIBLE: '.$rel.' presenta otra versión; no se sobrescribirá.'; continue; }
    $cambios[$rel]=$nuevo;
}
foreach (['public/pos_retiros.php','app/helpers/gyd_retiros_directos.php','app/helpers/gyd_retiros.php','app/helpers/auth.php','app/config/database.php'] as $rel) {
    if (!is_file($raiz.'/'.$rel)) $errores[]='Falta módulo requerido: '.$rel;
}
$posRel='public/pos.php';$posPath=$raiz.'/'.$posRel;
if (!is_file($posPath)) { $errores[]='Falta el archivo del Punto de Venta: '.$posRel; }
else {
    $codigo=(string)file_get_contents($posPath);
    $regex='~(src\s*=\s*["\'])assets/js/pos_retiros_aviso\.js(?:\?v=[^"\']*)?(["\'])~i';
    if (preg_match_all($regex,$codigo,$coincidencias)!==1) $errores[]='INCOMPATIBLE: pos.php no tiene una única carga del aviso de retiros.';
    else {
        $codigoNuevo=preg_replace($regex,'${1}assets/js/pos_retiros_aviso.js?v=20261028_7${2}',$codigo,1);
        if ($codigoNuevo===null) $errores[]='No pudo actualizarse la versión JS del POS.';
        elseif ($codigoNuevo!==$codigo) $cambios[$posRel]=$codigoNuevo;
        else $ya++;
    }
}
$relPrueba='tests/probar_aviso_retiros_v7.php';$rutaPrueba=$raiz.'/'.$relPrueba;$fuentePrueba=$origen.'/'.$relPrueba;
if (!is_file($fuentePrueba)) $errores[]='Falta prueba de verificación en el ZIP.';
elseif(is_file($rutaPrueba) && hash_file('sha256',$rutaPrueba)!==hash_file('sha256',$fuentePrueba))
    $errores[]='INCOMPATIBLE: '.$relPrueba.' ya existe con contenido ajeno.';
$nuevaPrueba=!is_file($rutaPrueba);
if ($errores) {
    foreach ($errores as $error) fwrite(STDERR,$error."\n");
    fwrite(STDERR,"NO SE MODIFICÓ NINGÚN ARCHIVO.\n");
    exit(1);
}
if ($verificar) {
    echo (($cambios || $nuevaPrueba)?'COMPATIBLE':'YA INSTALADO')." | Archivos por corregir: ".count($cambios)." | Prueba nueva: ".($nuevaPrueba?'sí':'no')."\n";
    echo "Elimina el aviso obsoleto de confirmación sin modificar la base prueba ni retiros registrados.\n";
    exit(0);
}
if (!$cambios && !$nuevaPrueba) {echo "YA INSTALADO.\n";exit(0);}
try {
    require_once $raiz.'/app/config/database.php';
    $db=(new Database())->connect();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!=='prueba') throw new RuntimeException('Únicamente se permite aplicar a la BD prueba.');
    $respaldo=$raiz.'/backups_gyd_aviso_retiros_v7_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if (!mkdir($respaldo,0775,true) && !is_dir($respaldo)) throw new RuntimeException('No se pudo crear carpeta de respaldo.');
    foreach ($cambios as $rel=>$contenido) {
        $archivoRespaldo=$respaldo.'/'.$rel;
        if (!is_dir(dirname($archivoRespaldo)) && !mkdir(dirname($archivoRespaldo),0775,true)) throw new RuntimeException('No se pudo preparar respaldo de '.$rel);
        if (!copy($raiz.'/'.$rel,$archivoRespaldo)) throw new RuntimeException('No se pudo respaldar '.$rel);
    }
    $aplicados=[];$pruebaCreada=false;
    try {
        foreach ($cambios as $rel=>$contenido) {
            $destino=$raiz.'/'.$rel;
            if (file_put_contents($destino,$contenido,LOCK_EX)===false) throw new RuntimeException('Error de escritura: '.$rel);
            $aplicados[]=$rel;
        }
        if ($nuevaPrueba) {
            if (!is_dir($raiz.'/tests') && !mkdir($raiz.'/tests',0775,true)) throw new RuntimeException('No se pudo crear tests.');
            if (!copy($fuentePrueba,$rutaPrueba)) throw new RuntimeException('No se pudo instalar prueba.');
            $pruebaCreada=true;
        }
        $salida=[];$status=0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($rutaPrueba).' 2>&1',$salida,$status);
        if ($status!==0) throw new RuntimeException("Falló la prueba posterior a la instalación:\n".implode("\n",$salida));
    } catch (Throwable $e) {
        foreach (array_reverse($aplicados) as $rel) copy($respaldo.'/'.$rel,$raiz.'/'.$rel);
        if ($pruebaCreada) @unlink($rutaPrueba);
        throw $e;
    }
    echo "INSTALADO CORRECTAMENTE | ".count($cambios)." archivos actualizados.\n";
    echo "Respaldo: $respaldo\n";
    echo "Si monto de retiro = $0.00, no se mostrará alerta. La tabla histórica permanece intacta.\n";
    echo "Presiona Ctrl+F5 en el navegador para renovar la pantalla del POS.\n";
} catch(Throwable $e) {fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\n");exit(1);}
