<?php
declare(strict_types=1);
/**
 * G&D - V8. Elimina exclusivamente el bloqueo heredado de aprobación de retiros
 * del cierre de turno. NO modifica datos, saldos, permisos ni movimientos.
 * Sólo permitido sobre BD prueba, ejecutado en CLI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }
$check=in_array('--check',$argv,true);
$apply=in_array('--apply',$argv,true);
if ($check === $apply || count($argv)!==2) {
    fwrite(STDERR,"Uso: php tools/instalar_cierre_retiros_v8.php --check | --apply\n"); exit(2);
}
$root=dirname(__DIR__);
$relative='app/models/PuntoVenta.php';
$path=$root.'/'.$relative;
if (!is_file($path)) { fwrite(STDERR,"INCOMPATIBLE: Falta {$relative}. No se cambió nada.\n"); exit(1); }
$source=file_get_contents($path);
if ($source===false) { fwrite(STDERR,"No pudo leerse {$relative}.\n");exit(1); }
$begin=strpos($source,'public function cerrarTurno(');
$end=$begin===false?false:strpos($source,'public function cobrar(',$begin);
if ($begin===false || $end===false || $begin >= $end) {
    fwrite(STDERR,"INCOMPATIBLE: No se identificó un único bloque de cierre de caja. No se cambió nada.\n"); exit(1);
}
$method=substr($source,$begin,$end-$begin);
// Este literal coincide con el bloqueo insertado por el antiguo parche de retiros supervisados.
$legacy='if((int)$this->q("SELECT COUNT(*) FROM erp_retiros_supervisados WHERE turno_id=? AND estado=\'SOLICITADO\'",[(int)$turno[\'id\']])->fetchColumn()>0)throw new DomainException(\'Existe un retiro pendiente de confirmación; solicite a supervisión aprobarlo o rechazarlo antes de cerrar turno.\');';
$regex='~^[ \t]*'.preg_quote($legacy,'~').'[ \t]*\R~m';
$count=preg_match_all($regex,$method);
if ($count===false || $count>1 || substr_count($source,$legacy)>1) {
    fwrite(STDERR,"INCOMPATIBLE: Validación repetida o no identificable; no se cambió nada.\n");exit(1);
}
if (!$count) {
    if (preg_match('~erp_retiros_supervisados|retiro pendiente de confirmaci[oó]n|estado\s*=\s*[\'\"]SOLICITADO~iu',$method)) {
        fwrite(STDERR,"INCOMPATIBLE: Detectada una validación de retiros diferente. No se cambiará automáticamente.\n");exit(1);
    }
    echo "YA CORREGIDO: No existe bloqueo heredado en cerrarTurno().\n";
    exit(0);
}
$updatedMethod=preg_replace($regex,'',$method,1,$replacements);
if ($updatedMethod===null || $replacements!==1) {fwrite(STDERR,"No pudo construirse la corrección.\n");exit(1);}
$updated=substr($source,0,$begin).$updatedMethod.substr($source,$end);
if (str_contains($updatedMethod,'erp_retiros_supervisados') || !str_contains($updatedMethod,"pos_turnos SET estado='CERRADO'")) {
    fwrite(STDERR,"INCOMPATIBLE: La estructura del cierre es inesperada.\n");exit(1);
}
if ($check) {
    echo "COMPATIBLE. Se quitará sólo 1 bloqueo obsoleto de confirmación en {$relative}.\n";
    echo "No se cambian bases de datos, importes, autorizaciones de usuarios, cortes ni retiros históricos.\n";
    exit(0);
}
try {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $dbname=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($dbname!=='prueba') throw new RuntimeException("Seguridad: sólo puede ejecutarse conectado a la base de pruebas 'prueba'. Actual: ".$dbname);
    // Bloquear reescrituras concurrentes entre --check y --apply.
    if (hash_file('sha256',$path)!==hash('sha256',$source)) throw new RuntimeException('PuntoVenta.php cambió durante la revisión. Reintenta --check.');
    $backupDir=$root.'/backups_gyd_cierre_retiros_v8_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $backupFile=$backupDir.'/'.$relative;
    if (!mkdir(dirname($backupFile),0775,true) && !is_dir(dirname($backupFile))) throw new RuntimeException('No se pudo preparar el respaldo.');
    if (!copy($path,$backupFile)) throw new RuntimeException('No se pudo respaldar PuntoVenta.php.');
    $tmp=tempnam(dirname($path),'gyd_v8_');
    if ($tmp===false) throw new RuntimeException('No se pudo generar archivo temporal.');
    try {
        if (file_put_contents($tmp,$updated,LOCK_EX)!==strlen($updated)) throw new RuntimeException('Fallo al preparar código actualizado.');
        // Si exec está habilitado, comprobamos sintaxis antes de sustituir archivo operativo.
        if (function_exists('exec') && is_callable('exec')) {
            $output=[];$status=0;
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$status);
            if ($status!==0) throw new RuntimeException('Error de sintaxis PHP: '.implode(' ',$output));
        }
        if (hash_file('sha256',$path)!==hash('sha256',$source)) throw new RuntimeException('El archivo fue modificado por otro proceso; no se aplicó.');
        if (file_put_contents($path,$updated,LOCK_EX)!==strlen($updated)) {
            copy($backupFile,$path);
            throw new RuntimeException('No se pudo escribir la actualización; se restauró respaldo.');
        }
        if (hash_file('sha256',$path)!==hash('sha256',$updated)) {
            copy($backupFile,$path);throw new RuntimeException('No coincidió la escritura; se restauró respaldo.');
        }
    } finally {if (is_file($tmp)) @unlink($tmp);}
    echo "INSTALADO. Se eliminó únicamente el bloqueo de aprobación de retiros en el cierre POS.\n";
    echo "RESPALDO: {$backupFile}\n";
    echo "No se actualizó ningún registro de MySQL ni se modificaron importes de caja.\n";
} catch(Throwable $e) {
    fwrite(STDERR,"INSTALACIÓN DETENIDA: ".$e->getMessage()."\n");exit(1);
}
