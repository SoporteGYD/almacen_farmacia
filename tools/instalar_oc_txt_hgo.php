<?php
declare(strict_types=1);
/** Instalador incremental, sin acceso a MySQL. Requiere ejecución CLI. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo ejecutable desde terminal.'); }
$action = $argv[1] ?? '';
if (!in_array($action, ['--check','--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_oc_txt_hgo.php --check|--apply\n"); exit(2);
}
$root = dirname(__DIR__);
$planPath = __DIR__.'/oc_txt_hgo_plan.json';
$plan = json_decode((string)@file_get_contents($planPath), true);
if (!is_array($plan) || !$plan) { fwrite(STDERR, "Falta tools/oc_txt_hgo_plan.json\n"); exit(2); }
$edits = [];
try {
    if(!is_file($root.'/public/erp_facturas_importar.php'))throw new RuntimeException('Copia tambien public/erp_facturas_importar.php desde el ZIP, sin reemplazar las demas pantallas.');
    foreach ($plan as $relative=>$rules) {
        if (!preg_match('~^(app/helpers|public)/[a-z_]+[.]php$~D', $relative)) throw new RuntimeException('Ruta de archivo no permitida.');
        $file = $root.'/'.$relative;
        if (!is_file($file)) throw new RuntimeException('No existe '.$relative.' (comprueba la ruta de instalación).');
        $beforeContents = (string)file_get_contents($file);
        $afterContents = $beforeContents;
        $count = 0;
        foreach ($rules as $rule) {
            $before = $rule['before'] ?? '';
            $after = $rule['after'] ?? '';
            if (!is_string($before)||!is_string($after)||$before===''||$after==='') throw new RuntimeException('Parche incompleto.');
            if (substr_count($afterContents,$after) === 1) continue; // regla aplicada previamente
            if (substr_count($afterContents,$before) !== 1) throw new RuntimeException('INCOMPATIBLE: '.$relative.'. Firma del código distinta. No reemplazar el archivo: contacta para integrar tu versión actual.');
            $afterContents = str_replace($before, $after, $afterContents);
            $count++;
        }
        $edits[] = ['file'=>$file,'relative'=>$relative,'before'=>$beforeContents,'after'=>$afterContents,'count'=>$count];
    }
    foreach ($edits as $e) echo ($e['count'] ? 'PENDIENTE' : 'YA INSTALADO').": {$e['relative']} ({$e['count']} cambios)\n";
    if ($action === '--check') { echo "COMPROBACION COMPLETADA: sin escrituras ni cambios de inventario.\n"; exit(0); }
    // Antes de alterar el proyecto, validar TODOS los cambios propuestos.
    $temps=[];
    try {
        foreach ($edits as $e) {
            if (!$e['count']) continue;
            $tmp=tempnam(dirname($e['file']), '.oc_hgo_');
            if ($tmp===false) throw new RuntimeException('No se pudo crear archivo de validación.');
            $temps[]=$tmp;
            if (file_put_contents($tmp,$e['after'])===false) throw new RuntimeException('No se pudo escribir temporal.');
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$output,$exitCode);
            if ($exitCode !== 0) throw new RuntimeException('Falla de sintaxis en '.$e['relative'].': '.implode(' ',$output));
            $output=[];
        }
        $backups=[];
        try {
            foreach ($edits as $e) {
                if (!$e['count']) continue;
                $backup=$e['file'].'.backup_oc_hgo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
                if (!copy($e['file'],$backup)) throw new RuntimeException('No se pudo crear respaldo de '.$e['relative']);
                $backups[]=['file'=>$e['file'],'backup'=>$backup];
                if (file_put_contents($e['file'],$e['after'],LOCK_EX)===false) throw new RuntimeException('Falla escribiendo '.$e['relative']);
                echo "ACTUALIZADO: {$e['relative']} | respaldo: {$backup}\n";
            }
        } catch(Throwable $failure) {
            foreach(array_reverse($backups) as $entry) @copy($entry['backup'],$entry['file']);
            throw $failure;
        }
    } finally { foreach($temps??[] as $tmp) @unlink($tmp); }
    echo "LISTO: importador G&D codigo,cantidad,costo sin encabezados. NO se modifico ninguna base de datos.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'DETENIDO: '.$error->getMessage()."\nSin cambios de datos; revisa la compatibilidad y los respaldos antes de repetir.\n");
    exit(1);
}
