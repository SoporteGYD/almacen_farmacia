<?php
declare(strict_types=1);
/** Parche limitado a la autorización de retiros. No cambia login global ni datos de caja. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }
$operation = $argv[1] ?? '--check';
if (!in_array($operation, ['--check','--apply','--verify','--rollback'], true)) {
    fwrite(STDERR, "Uso: php instalar_retiros_sin_bloqueo.php --check|--apply|--verify|--rollback\n"); exit(2);
}
$root = dirname(__DIR__);
$manifestFile = __DIR__ . '/reemplazos_retiros_sin_bloqueo.json';
$pointer = __DIR__ . '/ultima_instalacion_retiros_sin_bloqueo.json';
function gydRetFail(string $message): never { fwrite(STDERR, "ERROR: {$message}\n"); exit(1); }
function gydRetHash(string $value): string { return hash('sha256', $value); }
function gydRetWrite(string $path, string $data): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory,0775,true) && !is_dir($directory)) throw new RuntimeException('No se pudo crear carpeta de destino.');
    $temporary = tempnam($directory, '.gyd_retiro_');
    if ($temporary === false) throw new RuntimeException('No se pudo crear archivo temporal.');
    try {
        if (file_put_contents($temporary, $data, LOCK_EX) === false || !rename($temporary, $path)) throw new RuntimeException('No se pudo reemplazar '.basename($path));
    } finally { if (is_file($temporary)) @unlink($temporary); }
}
function gydRetDatabase(string $root): void {
    if (!is_file($root.'/app/config/database.php')) throw new RuntimeException('No existe la configuración de la base de datos.');
    require_once $root.'/app/config/database.php';
    if (!class_exists('Database')) throw new RuntimeException('No se pudo cargar Database.');
    $db=(new Database())->connect();
    if (!$db instanceof PDO) throw new RuntimeException('No se obtuvo conexión PDO.');
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if ($name!=='prueba' || $port!==3307) throw new RuntimeException("Esta actualización solo se aplica a prueba:3307. Conectado: {$name}:{$port}.");
}
try {
    $replacements = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    $allowed=['app/helpers/gyd_retiros_autorizaciones.php'=>'function gd_retiro_credencial(',
              'app/helpers/gyd_retiro_caja_supervision.php'=>'function gd_retiro_credencial_por_caja('];
    if (!is_array($replacements) || count($replacements)!==2) throw new RuntimeException('Manifiesto no válido.');
    $plan=[];
    foreach($replacements as $relative=>$patches) {
        if (!array_key_exists($relative,$allowed)) throw new RuntimeException('Ruta no permitida: '.$relative);
        $file = $root.'/'.$relative;
        if (!is_file($file)) throw new RuntimeException('No existe '.$relative.'. Primero instala Retiros caja o supervisor.');
        $before=(string)file_get_contents($file);
        if (!str_contains($before,$allowed[$relative]) || !str_contains($before,'password_verify(') || !str_contains($before,'gyd_retiros_intentos_autorizacion')) {
            throw new RuntimeException('Código de autorización inesperado en '.$relative.'. No se aplicará el parche.');
        }
        $after=$before;
        $applied=0;
        foreach($patches as $patch) {
            $old=(string)($patch['old']??'');
            $new=(string)($patch['new']??'');
            if ($old==='' || $new!=='') throw new RuntimeException('Parche inesperado en '.$relative);
            $occ=substr_count($after,$old);
            if ($occ===1) { $after=str_replace($old,$new,$after); $applied++; }
            elseif ($occ!==0) throw new RuntimeException('Parche ambiguo en '.$relative);
        }
        if ($applied!==0 && $applied!==count($patches)) throw new RuntimeException('Modificación parcial previa de '.$relative.'. Revisa manualmente.');
        if (str_contains($after,'INTERVAL 15 MINUTE') || str_contains($after,'Demasiados intentos fallidos') || str_contains($after,'bloqueada temporalmente para retiros')) {
            throw new RuntimeException('Quedaron reglas de bloqueo incompatibles en '.$relative);
        }
        $plan[$relative]=['before'=>$before,'after'=>$after,'changed'=>$after!==$before];
    }
    if ($operation==='--rollback') {
        if (!is_file($pointer)) throw new RuntimeException('No hay una instalación registrada para restaurar.');
        $saved=json_decode((string)file_get_contents($pointer),true,512,JSON_THROW_ON_ERROR);
        $backup=(string)($saved['backup']??'');
        if (!str_starts_with($backup,$root.'/backups/retiros_sin_bloqueo_') || !is_dir($backup)) throw new RuntimeException('Ruta de respaldo incorrecta.');
        foreach($saved['files'] as $relative=>$details) {
            if (!isset($allowed[$relative]) || !is_file($root.'/'.$relative) || hash_file('sha256',$root.'/'.$relative)!==$details['after']) throw new RuntimeException('El archivo fue cambiado después: '.$relative);
            if (!is_file($backup.'/'.$relative) || hash_file('sha256',$backup.'/'.$relative)!==$details['before']) throw new RuntimeException('Respaldo inválido: '.$relative);
        }
        foreach($saved['files'] as $relative=>$details) gydRetWrite($root.'/'.$relative,(string)file_get_contents($backup.'/'.$relative));
        unlink($pointer);
        echo "RESTAURADO. La auditoría histórica no se modificó.\n";
        exit(0);
    }
    $pending=array_filter($plan,fn($r)=>$r['changed']);
    if ($operation==='--check') {
        foreach($plan as $relative=>$item) echo ($item['changed']?'PREPARADO: ':'YA ACTUALIZADO: ').$relative."\n";
        echo "COMPATIBLE · El cambio es solo de código, sin borrar intentos históricos.\n";
        exit(0);
    }
    if ($operation==='--verify') {
        if (count($pending)) throw new RuntimeException('Todavía hay cambios pendientes. Ejecuta --apply.');
        echo "VERIFICADO · Sin bloqueo por fallos ni espera de 15 minutos en la autorización de retiros.\n";
        exit(0);
    }
    if (!$pending) { echo "YA INSTALADO · No hay cambios pendientes.\n"; exit(0); }
    if (is_file($pointer)) throw new RuntimeException('Ya existe otra instalación registrada. Ejecuta --verify o revisa --rollback.');
    gydRetDatabase($root);
    $stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
    $backup=$root.'/backups/retiros_sin_bloqueo_'.$stamp;
    $saved=['backup'=>$backup,'files'=>[]];
    foreach($pending as $relative=>$item) {
        $backPath=$backup.'/'.$relative;
        if (!is_dir(dirname($backPath)) && !mkdir(dirname($backPath),0775,true) && !is_dir(dirname($backPath))) throw new RuntimeException('No se pudo crear el respaldo.');
        if (file_put_contents($backPath,$item['before'],LOCK_EX)===false) throw new RuntimeException('No se pudo respaldar '.$relative);
        $saved['files'][$relative]=['before'=>gydRetHash($item['before']),'after'=>gydRetHash($item['after'])];
    }
    $written=[];
    try {
        foreach($pending as $relative=>$item) {
            gydRetWrite($root.'/'.$relative,$item['after']);$written[]=$relative;
            if (hash_file('sha256',$root.'/'.$relative)!==gydRetHash($item['after'])) throw new RuntimeException('No se verificó '.$relative);
        }
        if (file_put_contents($pointer,json_encode($saved,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('No se pudo registrar la instalación.');
    } catch(Throwable $e) {
        foreach(array_reverse($written) as $relative) @copy($backup.'/'.$relative,$root.'/'.$relative);
        throw $e;
    }
    echo "APLICADO · Los datos incorrectos rechazan el retiro sin bloquear ni temporizador.\nRespaldo: {$backup}\nEjecuta --verify.\n";
} catch(Throwable $error) { gydRetFail($error->getMessage()); }
