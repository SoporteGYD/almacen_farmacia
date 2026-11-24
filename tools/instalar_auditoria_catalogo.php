<?php
declare(strict_types=1);
/** Instalador compatible sin modificación de tablas: --check / --apply */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$modo=$argv[1]??'--check';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_auditoria_catalogo.php --check | --apply\n");exit(2);}
$raiz=dirname(__DIR__);
$plantillas=__DIR__.'/auditoria_catalogo_files';
$archivos=['app/helpers/erp_auditoria_catalogo.php','public/erp_catalogo_auditoria.php'];
$pendientes=[];$mensajes=[];$errores=[];
foreach($archivos as $archivo){
    $origen=$plantillas.'/'.$archivo;$destino=$raiz.'/'.$archivo;
    if(!is_file($origen)){$errores[]="Falta plantilla $archivo";continue;}
    $contenido=(string)file_get_contents($origen);
    if(is_file($destino)){
        if(!hash_equals(hash('sha256',$contenido),(string)hash_file('sha256',$destino)))$errores[]="INCOMPATIBLE: $archivo existe con contenido distinto";
        else $mensajes[]="YA INSTALADO: $archivo";
    } else {$pendientes[$archivo]=$contenido;$mensajes[]="NUEVO: $archivo";}
}
$catalogo='public/erp_catalogo_maestro.php';
if(is_file($raiz.'/'.$catalogo)){
    $actual=(string)file_get_contents($raiz.'/'.$catalogo);
    if(str_contains($actual,'href="erp_catalogo_auditoria.php"'))$mensajes[]="YA INSTALADO: enlace en $catalogo";
    else{
        $patron='~(<a\s+href="erp_catalogo_reportes\.php"[^>]*>.*?</a>)~s';
        if(preg_match_all($patron,$actual,$m)!==1)$errores[]="INCOMPATIBLE: no se encontró el enlace único de Reportes en $catalogo";
        else{
            $nuevo=preg_replace($patron,'$1<a href="erp_catalogo_auditoria.php" style="background:#e4f6e9;color:#155b3a">🔎 Auditoría del catálogo</a>',$actual,1);
            if($nuevo===null)$errores[]="Error de reemplazo en $catalogo";
            else{$pendientes[$catalogo]=$nuevo;$mensajes[]="ACTUALIZAR: botón Auditoría en $catalogo";}
        }
    }
}else $errores[]="No existe $catalogo";
$reportes='public/erp_catalogo_reportes.php';
if(is_file($raiz.'/'.$reportes)){
    $actual=(string)file_get_contents($raiz.'/'.$reportes);
    if(str_contains($actual,'href="erp_catalogo_auditoria.php"'))$mensajes[]="YA INSTALADO: enlace en $reportes";
    elseif(substr_count($actual,'</header>')!==1)$errores[]="INCOMPATIBLE: encabezado cambiado en $reportes";
    else{
        $pendientes[$reportes]=str_replace('</header>','<a href="erp_catalogo_auditoria.php">🔎 Auditoría del catálogo</a></header>',$actual);
        $mensajes[]="ACTUALIZAR: acceso desde reportes";
    }
}else $errores[]="No existe $reportes; instala primero Reportes del Catálogo";
$modulos='app/helpers/erp_compras_modulos.php';
if(is_file($raiz.'/'.$modulos)){
    $actual=(string)file_get_contents($raiz.'/'.$modulos);
    if(str_contains($actual,"'erp_catalogo_auditoria.php'=>true"))$mensajes[]='YA INSTALADO: permiso COMPRAS';
    else{
        $ancla="'erp_catalogo_reportes.php'=>true,";
        if(substr_count($actual,$ancla)!==1)$errores[]='INCOMPATIBLE: no está el permiso esperado de Reportes en COMPRAS';
        else{
            $pendientes[$modulos]=str_replace($ancla,"'erp_catalogo_auditoria.php'=>true,\n        ".$ancla,$actual);
            $mensajes[]='ACTUALIZAR: permiso solo lectura de COMPRAS';
        }
    }
}else{
    $auth='app/helpers/auth.php';
    if(!is_file($raiz.'/'.$auth))$errores[]='Falta autenticación';
    else{
        $actual=(string)file_get_contents($raiz.'/'.$auth);
        if(str_contains($actual,"'erp_catalogo_auditoria.php'"))$mensajes[]='YA INSTALADO: ruta de COMPRAS';
        else{
            $ancla="'erp_catalogo_reportes.php'";
            if(substr_count($actual,$ancla)!==1)$errores[]='INCOMPATIBLE: no se localizó acceso de Compras para Reportes';
            else{
                $pendientes[$auth]=str_replace($ancla,"'erp_catalogo_auditoria.php',".$ancla,$actual);
                $mensajes[]='ACTUALIZAR: permiso COMPRAS en autenticación';
            }
        }
    }
}
foreach($pendientes as $ruta=>$contenido){
    if(!str_ends_with($ruta,'.php'))continue;
    $tmp=tempnam(sys_get_temp_dir(),'gd-audit-');
    if($tmp===false){$errores[]='No fue posible crear temporal';continue;}
    file_put_contents($tmp,$contenido);
    $out=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$code);unlink($tmp);
    if($code!==0)$errores[]='PHP inválido en '.$ruta.': '.implode(' ',$out);
}
foreach($mensajes as $txt)echo $txt."\n";
if($errores){foreach($errores as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE: no se modificaron archivos.\n");exit(3);}
if($modo==='--check'){echo 'COMPATIBLE: '.count($pendientes).' archivos por crear o actualizar; NO se cambió la base de datos.' . "\n";exit(0);}
if(!$pendientes){echo "YA INSTALADO: nada que actualizar.\n";exit(0);}
$backup=$raiz.'/tools/backups/auditoria_catalogo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!@mkdir($backup,0775,true)) {fwrite(STDERR,"No se pudo crear respaldo\n");exit(4);}
foreach($pendientes as $ruta=>$contenido){
    $dest=$raiz.'/'.$ruta;
    if(!is_file($dest))continue;
    $bak=$backup.'/'.$ruta;
    if(!is_dir(dirname($bak))&&!@mkdir(dirname($bak),0775,true)){fwrite(STDERR,"No se pudo preparar respaldo $ruta\n");exit(4);}
    if(!@copy($dest,$bak)){fwrite(STDERR,"No se pudo respaldar $ruta\n");exit(4);}
}
$completados=[];
try{
    foreach($pendientes as $ruta=>$contenido){
        $dest=$raiz.'/'.$ruta;
        if(!is_dir(dirname($dest))&&!@mkdir(dirname($dest),0775,true))throw new RuntimeException('No se pudo crear directorio '.$ruta);
        $tmp=$dest.'.new_'.getmypid();
        if(file_put_contents($tmp,$contenido,LOCK_EX)===false)throw new RuntimeException('Error al escribir '.$ruta);
        if(!@rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('Error al reemplazar '.$ruta);}
        $completados[]=$ruta;
    }
}catch(Throwable $e){
    foreach(array_reverse($completados) as $ruta){$b=$backup.'/'.$ruta;$dest=$raiz.'/'.$ruta;if(is_file($b))@copy($b,$dest);else @unlink($dest);}
    fwrite(STDERR,'ABORTADO: '.$e->getMessage().". Se intentó restaurar todo.\n");exit(5);
}
echo 'INSTALADO: '.count($completados).' archivos. Respaldo: '.$backup."\n";
echo "Abrir: http://localhost/almacen-farmacia2/public/erp_catalogo_auditoria.php\n";
echo "SOLO LECTURA: sin ALTER, INSERT, UPDATE ni DELETE de catálogo o existencias.\n";
