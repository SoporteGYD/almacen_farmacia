<?php
declare(strict_types=1);
/** Instalador incremental G&D: roles de corte; solo base de laboratorio prueba:3307. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo disponible desde la terminal.'); }
$modo = (string)($argv[1] ?? '--check');
if (!in_array($modo,['--check','--apply','--verify','--check-files'],true)) { fwrite(STDERR,"Uso: php tools/instalar_cortes_roles.php --check|--apply|--verify\n"); exit(2); }
$raiz=dirname(__DIR__);
$base=__DIR__;
$patches=json_decode((string)file_get_contents($base.'/patches_cortes_roles.json'),true,512,JSON_THROW_ON_ERROR);
if (!is_array($patches)) throw new RuntimeException('Falta manifiesto de cambios.');
function leerTexto(string $path): string {
    if (!is_file($path)) throw new RuntimeException('Falta archivo: '.$path);
    $txt=file_get_contents($path);
    if ($txt===false) throw new RuntimeException('No se pudo leer: '.$path);
    return $txt;
}
function planCambios(string $raiz,array $patches):array {
    $porRuta=[];
    foreach($patches as $p) {
        if (!in_array($p['file'],[
            'app/models/PuntoVenta.php','public/pos_api.php','public/pos.php','public/pos_corte_retiros.php',
            'public/erp_cortes_boveda.php','app/controllers/UsuarioController.php','public/usuario.php','app/helpers/auth.php'],true))
            throw new RuntimeException('Ruta no autorizada en manifiesto.');
        $porRuta[$p['file']][]=$p;
    }
    $resultado=[];
    foreach($porRuta as $rel=>$cambios) {
        $old=leerTexto($raiz.'/'.$rel);$nuevo=$old;
        foreach($cambios as $cambio) {
            $antes=(string)$cambio['old'];$despues=(string)$cambio['new'];$esperado=(int)$cambio['count'];
            $a=substr_count($nuevo,$antes);$b=substr_count($nuevo,$despues);
            if($b===$esperado) { /* Cambio ya aplicado (algunos parches conservan el texto anterior). */ }
            elseif ($a===$esperado && $b===0) $nuevo=str_replace($antes,$despues,$nuevo);
            else throw new RuntimeException("No coincide el archivo {$rel}. Encontrado antes={$a}, despues={$b}; se detuvo para no sobrescribir cambios.");
        }
        $resultado[$rel]=['original'=>$old,'nuevo'=>$nuevo];
    }
    $archivoHelper='app/helpers/gyd_cortes_autorizados.php';
    $helperViejo=$raiz.'/'.$archivoHelper;
    $helperActual=leerTexto($helperViejo);
    $hashViejo='148d1ae9f940cc76c13a3f97ed777b30b4b0d0c2941584a56ddb9c12bdaf82e4';
    $helperNuevo=leerTexto(__DIR__.'/payload/'.$archivoHelper);
    if (!hash_equals($hashViejo,hash('sha256',$helperActual)) && !hash_equals(hash('sha256',$helperNuevo),hash('sha256',$helperActual)))
        throw new RuntimeException('El autorizador de cortes difiere del proyecto revisado. No se reemplazó; comparte el archivo para integrarlo.');
    $resultado[$archivoHelper]=['original'=>$helperActual,'nuevo'=>$helperNuevo];
    $nuevoModulo='public/gyd_cortes_caja.php';
    $destino=$raiz.'/'.$nuevoModulo;
    $contenido=leerTexto(__DIR__.'/payload/'.$nuevoModulo);
    if (is_file($destino) && leerTexto($destino)!==$contenido)
        throw new RuntimeException('Ya existe una pantalla de cortes diferente. Se detuvo por seguridad.');
    $resultado[$nuevoModulo]=['original'=>is_file($destino)?$contenido:null,'nuevo'=>$contenido];
    return $resultado;
}
function dbLaboratorio(string $raiz): PDO {
    require_once $raiz.'/app/config/database.php';
    $db=(new Database())->connect();
    $nom=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $puerto=(int)$db->query('SELECT @@port')->fetchColumn();
    if($nom!=='prueba'||$puerto!==3307) throw new RuntimeException('Solo se puede aplicar sobre la base prueba en MariaDB puerto 3307. Conexión actual: '.$nom.':'.$puerto);
    return $db;
}
function rolDb(PDO $db):bool {
    $st=$db->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !preg_match('/^enum\((.*)\)$/i',(string)$r['Type'],$m))throw new RuntimeException('La columna usuarios.rol ya no tiene el tipo ENUM esperado.');
    preg_match_all("/'([^']*)'/",$m[1],$mm);
    $valores=$mm[1];
    if(!in_array('CAJERO',$valores,true)||!in_array('ADMINISTRADOR',$valores,true)||!in_array('GERENTE',$valores,true)||!in_array('DIRECCION',$valores,true))
        throw new RuntimeException('El ENUM de usuarios no coincide con los roles básicos existentes.');
    if(in_array('CONTABILIDAD',$valores,true))return true;
    return false;
}
function migrarRol(PDO $db):void {
    if(rolDb($db))return;
    $r=$db->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
    preg_match('/^enum\((.*)\)$/i',(string)$r['Type'],$m);
    preg_match_all("/'([^']*)'/",$m[1],$mm);
    $roles=$mm[1];$roles[]='CONTABILIDAD';
    $lista=implode(',',array_map(static fn($v)=>"'".str_replace("'","''",$v)."'",$roles));
    $default=in_array((string)($r['Default']??''),$roles,true)?(string)$r['Default']:'CONSULTA';
    $sql='ALTER TABLE `usuarios` MODIFY COLUMN `rol` ENUM('.$lista.") NOT NULL DEFAULT '".$default."'";
    $db->exec($sql);
    if(!rolDb($db)) throw new RuntimeException('No se pudo habilitar CONTABILIDAD en usuarios.rol.');
}
try {
    $plan=planCambios($raiz,$patches);
    $pendientes=[];foreach($plan as $rel=>$v)if($v['original']!==$v['nuevo'])$pendientes[]=$rel;
    if($modo==='--check-files') { echo 'Estructura compatible. Cambios pendientes en '.count($pendientes).' archivo(s).'.PHP_EOL;exit(0); }
    $db=dbLaboratorio($raiz);
    $rolHabilitado=rolDb($db);
    if($modo==='--check') {
        echo 'COMPATIBLE: ERP G&D y base prueba:3307'.PHP_EOL;
        echo 'Archivos por actualizar: '.count($pendientes).PHP_EOL;
        echo 'Rol CONTABILIDAD: '.($rolHabilitado?'ya habilitado':'se agregará al ENUM durante --apply').PHP_EOL;
        echo 'No se han modificado archivos ni bases de datos.'.PHP_EOL;
        exit(0);
    }
    if($modo==='--verify') {
        if($pendientes || !$rolHabilitado)throw new RuntimeException('Quedan archivos pendientes o no se creó el rol CONTABILIDAD.');
        echo 'VERIFICADO: permisos por rol, corte restringido, pantalla y rol CONTABILIDAD disponibles.'.PHP_EOL;
        exit(0);
    }
    $carpeta=$raiz.'/backups/cortes_roles_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!is_dir($carpeta) && !mkdir($carpeta,0775,true))throw new RuntimeException('No se puede crear directorio de respaldo.');
    foreach($plan as $rel=>$v) {
        if($v['original']===null)continue;
        $f=$carpeta.'/'.$rel;
        if(!is_dir(dirname($f))&&!mkdir(dirname($f),0775,true))throw new RuntimeException('No se puede respaldar '.$rel);
        if(file_put_contents($f,$v['original'])===false)throw new RuntimeException('Error al respaldar '.$rel);
    }
    file_put_contents($carpeta.'/MANIFIESTO.txt',"Respaldo automático antes de modificar permisos de cortes.\nNo restablece la ampliación ENUM de CONTABILIDAD si ya se utilizó.\n");
    // La migración de ENUM no borra cuentas ni cambia sus roles existentes.
    migrarRol($db);
    $modificados=[];
    try {
        foreach($plan as $rel=>$v) {
            if($v['original']===$v['nuevo'])continue;
            $path=$raiz.'/'.$rel;
            if(!is_dir(dirname($path))&&!mkdir(dirname($path),0775,true))throw new RuntimeException('No se puede crear ruta de destino.');
            if(file_put_contents($path,$v['nuevo'],LOCK_EX)===false)throw new RuntimeException('No se pudo escribir '.$rel);
            $modificados[]=$rel;
        }
    } catch(Throwable $e) {
        foreach(array_reverse($modificados) as $rel) {
            $v=$plan[$rel];$path=$raiz.'/'.$rel;
            if($v['original']===null)@unlink($path);else @file_put_contents($path,$v['original'],LOCK_EX);
        }
        throw $e;
    }
    echo 'APLICADO. Archivos actualizados: '.count($modificados).PHP_EOL;
    echo 'Respaldo en: '.$carpeta.PHP_EOL;
    echo 'Rol CONTABILIDAD habilitado (sin asignarlo a ninguna cuenta).'.PHP_EOL;
    echo 'Ejecuta --verify y luego prueba con un Cajero y una cuenta de Gerencia.'.PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
