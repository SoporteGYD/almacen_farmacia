<?php
/**
 * Actualización G&D F1: permisos de respaldos y verificación del destino.
 * Uso CLI --check / --apply. No ejecuta SQL ni modifica datos.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--apply'],true)) { fwrite(STDERR,"Uso: php tools/instalar_nucleo_f1_primera_prioridad.php --check|--apply\n");exit(2); }
$root=dirname(__DIR__);
$target=$root.'/public/respaldos.php';
$helper=$root.'/app/helpers/gyd_f1_objetivo_respaldo.php';
$source=__DIR__.'/_f1_payload/app/helpers/gyd_f1_objetivo_respaldo.php';
$marca='GYD_F1_RESPALDO_BD_VERIFICADA_20261102';
try {
    if (!is_file($target)||!is_file($root.'/app/config/database.php')||!is_file($source)) throw new RuntimeException('Faltan archivos esperados del ERP o del paquete.');
    $original=file_get_contents($target);
    if ($original===false || !str_contains($original, 'requireLogin();')) throw new RuntimeException('No se reconoce el archivo respaldos.php actual.');
    if (str_contains($original,$marca)) {
        if (!is_file($helper)) throw new RuntimeException('El parche aparece en respaldos.php pero falta el helper; no se modifica automáticamente.');
        echo "YA INSTALADO: protección de respaldos F1 presente.\n";
        exit(0);
    }
    if (is_file($helper)) throw new RuntimeException('Existe un archivo de protección con el mismo nombre; revisión manual necesaria.');
    $role='/if\s*\(\s*!in_array\(\s*\$rol\s*,\s*\[\s*[\'\"]ADMINISTRADOR[\'\"]\s*,\s*[\'\"]ENCARGADO[\'\"]\s*\]\s*,\s*true\s*\)\s*\)/';
    if (preg_match($role,$original)!==1) throw new RuntimeException('La restricción de respaldos es distinta de la referencia; no se modificará por seguridad.');
    if (preg_match('/\$dbName\s*=\s*getenv\(\s*[\'\"]GD_DB_NAME[\'\"]\s*\)\s*\?:\s*[\'\"]prueba[\'\"]\s*;/',$original)!==1) throw new RuntimeException('Configuración de base de respaldos no reconocida.');
    $needle="    } elseif (\n        !\$esWindows\n        && !is_file(\$scriptRespaldoLinux)\n    ) {";
    if (substr_count($original,$needle)!==1) throw new RuntimeException('Estructura del flujo de respaldo no reconocida; solicita adaptación sobre tu archivo actual.');
    if (substr_count($original,'requireLogin();')!==1) throw new RuntimeException('Hay más de una declaración de inicio de sesión.');
    $patched=preg_replace($role,"if (!in_array(\$rol, ['ADMINISTRADOR', 'DIRECCION'], true))",$original,1,$counts);
    if ($counts!==1) throw new RuntimeException('No se pudo ajustar el rol de respaldo.');
    $patched=str_replace('requireLogin();',"requireLogin();\n// {$marca}\nrequire_once __DIR__ . '/../app/helpers/gyd_f1_objetivo_respaldo.php';",$patched);
    $patched=str_replace($needle,"    } elseif ((\$gydF1Error = gyd_f1_validar_destino_respaldo(\$dbName, \$dbPort)) !== null) {\n        \$error = \$gydF1Error;\n".$needle,$patched);
    if (!str_contains($patched,$marca)||!str_contains($patched,'gyd_f1_validar_destino_respaldo')) throw new RuntimeException('Verificación interna de parche fallida.');
    $tmp=tempnam(sys_get_temp_dir(),'gyd_f1_php_');
    if ($tmp===false || file_put_contents($tmp,$patched)===false) throw new RuntimeException('No se pudo crear archivo de revisión.');
    $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1';
    exec($cmd,$out,$code);
    unlink($tmp);
    if($code!==0) throw new RuntimeException('La sintaxis PHP no es compatible: '.implode(' ',$out));
    if($mode==='--check') {
        echo "COMPATIBLE: se modificarán SOLO permisos y verificación del respaldo en public/respaldos.php; se añadirá un helper.\n";
        echo "NO se ejecutará SQL ni se alterarán cajas, ventas o inventarios.\n";
        exit(0);
    }
    // Mantener copias del código fuera de htdocs en una carpeta privada.
    $backupDir=dirname(dirname($root)).'/gyd_resguardos_codigo/f1';
    if(!is_dir($backupDir) && !mkdir($backupDir,0700,true) && !is_dir($backupDir)) throw new RuntimeException('No se pudo crear carpeta privada de resguardo de código.');
    $name='respaldos.php.'.date('Ymd_His').'.'.bin2hex(random_bytes(4)).'.bak';
    $backup=$backupDir.'/'.$name;
    if(!copy($target,$backup)) throw new RuntimeException('No se pudo respaldar respaldos.php; sin cambios.');
    $hashOriginal=hash_file('sha256',$target);
    if($hashOriginal!==hash('sha256',$original)) throw new RuntimeException('El archivo respaldos.php cambió entre check y apply; sin cambios.');
    $wroteHelper=false;
    try {
        if(!copy($source,$helper)) throw new RuntimeException('No se pudo instalar el helper.');
        $wroteHelper=true;
        if(file_put_contents($target,$patched,LOCK_EX)===false) throw new RuntimeException('No se pudo guardar la actualización en respaldos.php.');
        if(hash_file('sha256',$target)!==hash('sha256',$patched)) throw new RuntimeException('Verificación de contenido falló.');
        echo "INSTALADO: protección del módulo Respaldos F1.\n";
        echo "Respaldo privado del código: {$backup}\n";
        echo "No se modificó la base de datos.\n";
    } catch (Throwable $e) {
        copy($backup,$target);
        if($wroteHelper && is_file($helper)) unlink($helper);
        throw new RuntimeException('Se revirtió la instalación: '.$e->getMessage(),0,$e);
    }
} catch(Throwable $e) { fwrite(STDERR,"INCOMPATIBLE: ".$e->getMessage()."\n");exit(1); }
