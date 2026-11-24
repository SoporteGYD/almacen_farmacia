<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$cmd=$argv[1]??'--check';
if(!in_array($cmd,['--check','--apply','--check-files'],true)){fwrite(STDERR,"Uso: --check | --apply\n");exit(2);}
$root=dirname(__DIR__);
$errors=[];$changes=[];$notes=[];
$created=['app/helpers/erp_centro_notificaciones.php','public/erp_notificaciones.php','public/erp_notificaciones_api.php','public/assets/js/erp_notificaciones.js'];
// Archivos requeridos instalados por los parches de 21/22 de octubre.
$deps=['app/helpers/erp_oc_aviso_estado.php','app/helpers/erp_oc_avisos.php','app/helpers/erp_oc_recepciones.php','app/helpers/erp_compras_modulos.php'];
foreach($deps as $rel) if(!is_file($root.'/'.$rel))$errors[]="Falta dependencia: $rel. Instala primero el parche de avisos/usuarios de Compras.";
// El instalador se distribuye en tools/ con los originales de esta actualización en tools/centro_fuentes/.
$pending=[];
foreach($created as $rel){
    $source=__DIR__.'/centro_fuentes/'.$rel;
    $target=$root.'/'.$rel;
    if(!is_file($source)){$errors[]="No existe fuente: $rel";continue;}
    $new=file_get_contents($source);
    if(is_file($target)){
        if(hash('sha256',$new)===hash_file('sha256',$target)){$notes[]="YA INSTALADO: $rel";continue;}
        $errors[]="INCOMPATIBLE: $rel ya existe con contenido diferente; no se sobrescribe.";
        continue;
    }
    $pending[$rel]=$new;$notes[]="NUEVO: $rel";
}
function gdnt_replace_once(string $relative,string $old,string $new,array &$pending,array &$errors,array &$notes,string $root):void{
    $path=$root.'/'.$relative;
    if(!is_file($path)){$errors[]="Falta: $relative";return;}
    $body=$pending[$relative]??file_get_contents($path);
    if(str_contains($body,$new)){$notes[]="YA INTEGRADO: $relative";return;}
    if(substr_count($body,$old)!==1){$errors[]="INCOMPATIBLE: $relative; no se localizó ancla segura.";return;}
    $pending[$relative]=str_replace($old,$new,$body);
    $notes[]="INTEGRAR: $relative";
}
$script='<script defer src="assets/js/erp_notificaciones.js?v=1"></script>';
$targets=['app/views/layouts/footer.php','public/erp_oc_win.php','public/erp_compras_inicio.php','public/erp_compras_avisos.php','public/erp_recepciones_pendientes.php'];
foreach($targets as $rel){
    if(!is_file($root.'/'.$rel)){$notes[]="OPCIONAL NO ENCONTRADO: $rel";continue;}
    $content=$pending[$rel]??file_get_contents($root.'/'.$rel);
    if(str_contains($content,$script)){$notes[]="YA INTEGRADO: $rel";continue;}
    if(substr_count($content,'</body>')!==1){$errors[]="INCOMPATIBLE: $rel no tiene un cierre body único.";continue;}
    $pending[$rel]=str_replace('</body>',$script.'</body>',$content);
    $notes[]="CAMPANA: $rel";
}
$rel='app/helpers/erp_compras_modulos.php';
if(is_file($root.'/'.$rel)){
    $needle="        'session_keepalive.php'=>true,";
    $replacement="        'erp_notificaciones.php'=>true,\n        'erp_notificaciones_api.php'=>true,\n".$needle;
    gdnt_replace_once($rel,$needle,$replacement,$pending,$errors,$notes,$root);
}
$rel='app/helpers/auth.php';
if (is_file($root.'/'.$rel)) {
    $body=$pending[$rel]??file_get_contents($root.'/'.$rel);
    $marker='/* G&D CENTRO AVISOS ENCARGADO V2 */';
    if (str_contains($body,$marker)) {
        $notes[]="YA INTEGRADO: $rel";
    } else {
        // Cambiar SOLO la restricción de ENCARGADO dentro de requireLogin().
        // No dar permisos globales ni crear un acceso que salte la auditoría.
        $begin=strpos($body,'function requireLogin(');
        $next=$begin===false?false:strpos($body,'function currentUser(', $begin);
        if($begin===false || $next===false || $next<=$begin) {
            $errors[]="INCOMPATIBLE: $rel; no se localizó requireLogin() sin ambigüedades.";
        } else {
            $section=substr($body,$begin,$next-$begin);
            // Acepta espacios y saltos de línea diferentes del auth.php anterior.
            // Está anclado en el rol ENCARGADO, no en una línea de str_starts_with.
            $pattern='/if\s*\(\s*\$rolRealActual\s*===\s*([\x27\x22])ENCARGADO\1\s*\)\s*\{/s';
            $matches=[];
            $count=preg_match_all($pattern,$section,$matches,PREG_OFFSET_CAPTURE);
            if($count!==1 || !str_contains($section,'erp_') || !str_contains($section,'$paginaActual')) {
                $errors[]="INCOMPATIBLE: $rel; restricción de ENCARGADO distinta de la revisada. No se alteró.";
            } else {
                $found=$matches[0][0][0];
                $pos=$matches[0][0][1];
                $replacement=<<<'GDNT'
if ($rolRealActual === 'ENCARGADO' && !in_array(
    basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
    ['erp_notificaciones.php', 'erp_notificaciones_api.php'],
    true
)) { /* G&D CENTRO AVISOS ENCARGADO V2 */
GDNT;
                $section=substr_replace($section,$replacement,$pos,strlen($found));
                $pending[$rel]=substr($body,0,$begin).$section.substr($body,$next);
                $notes[]="AUTORIZACIÓN LIMITADA: $rel (sólo centro de avisos, no ERP general)";
            }
        }
    }
} else $errors[]="Falta $rel";
foreach($notes as $n)echo $n."\n";
// Validación de los scripts PHP antes de escribir nada.
foreach($pending as $rel=>$body){
    if(!str_ends_with($rel,'.php'))continue;
    $tmp=tempnam(sys_get_temp_dir(),'gdnt_');
    if($tmp===false){$errors[]='No se pudo obtener archivo temporal';break;}
    file_put_contents($tmp,$body);
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$code);
    @unlink($tmp);
    if($code!==0)$errors[]="Error de sintaxis PHP: $rel (".implode(' ',$output).")";
    $output=[];
}
// Comprobación opcional de archivos para ensayar sin credenciales de MySQL.
if ($cmd==='--check-files') {
    if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");exit(3);}
    echo "ARCHIVOS COMPATIBLES: ".count($pending)." a crear/actualizar. No se consultó MySQL.\n";
    exit(0);
}
// Comprobar conexión y existencia de las tablas base sin usar SHOW TABLES LIKE ? (incompatible con MariaDB en prepared statements).
try{
    if(is_file($root.'/app/config/database.php')){
        require_once $root.'/app/config/database.php';
        $pdo=(new Database())->connect();
        $tables=['erp_oc_avisos_compras','erp_oc_recepciones','erp_oc_recepciones_detalle','erp_ordenes_compra_detalle','erp_ordenes_compra','erp_facturas','erp_facturas_detalle','erp_facturas_stock_aplicaciones','erp_oc_entrada_vinculos'];
        $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach($tables as $name){$st->execute([$name]);if(!(int)$st->fetchColumn())$errors[]="Falta tabla requerida: $name";}
        // No comprobar tabla nueva hasta apply, no debe ser un requisito de --check.
    } else $errors[]='Falta configuración de la BD';
} catch(Throwable $e){$errors[]='Sin conexión a MariaDB: '.$e->getMessage();}
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE: no se modificaron archivos ni base de datos.\n");exit(3);}
echo "COMPATIBLE: ".count($pending)." archivos nuevos/por actualizar.\n";
if($cmd==='--check'){echo "Comprobación terminada. Sin cambios.\n";exit(0);}
$backup=$root.'/tools/respaldos_centro_notificaciones_'.date('Ymd_His');
if(!is_dir($backup)&&!mkdir($backup,0700,true)){fwrite(STDERR,"Sin carpeta de respaldo.\n");exit(4);}
foreach($pending as $rel=>$body){
    if(!is_file($root.'/'.$rel))continue;
    $bk=$backup.'/'.$rel;
    if(!is_dir(dirname($bk))&&!mkdir(dirname($bk),0700,true)){fwrite(STDERR,"No se puede preparar respaldo.\n");exit(4);}
    if(!copy($root.'/'.$rel,$bk)){fwrite(STDERR,"No se pudo respaldar $rel\n");exit(4);}
}
try{
    // Una tabla técnica para lectura personal. No altera tablas ERP ni inventario.
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_notificaciones_lecturas (
       usuario_id INT NOT NULL,
       tipo VARCHAR(24) NOT NULL,
       origen_id BIGINT NOT NULL,
       leido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY(usuario_id,tipo,origen_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $written=[];
    foreach($pending as $rel=>$body){
        $dest=$root.'/'.$rel;
        if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))throw new RuntimeException('Carpeta: '.$rel);
        if(file_put_contents($dest,$body,LOCK_EX)===false)throw new RuntimeException('Escritura: '.$rel);
        $written[]=$rel;
    }
}catch(Throwable $e){
    foreach($written??[] as $rel){$target=$root.'/'.$rel;$bk=$backup.'/'.$rel;if(is_file($bk))copy($bk,$target);else @unlink($target);}
    fwrite(STDERR,"ABORTADO: ".$e->getMessage()."; se intentó restaurar el respaldo. La tabla de lecturas puede permanecer vacía.\n");exit(5);
}
echo "INSTALADO: ".count($pending)." archivos. Respaldos: $backup\n";
echo "Visita public/erp_notificaciones.php; ninguna existencia fue modificada.\n";
