<?php
declare(strict_types=1);
/** ERP G&D / F2 Fuente única: Factura -> POS. Solo para DB prueba, puerto 3307. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }
$root = realpath(__DIR__.'/..');
if (!$root || !is_dir($root.'/app') || !is_dir($root.'/public')) { fwrite(STDERR, "Proyecto ERP incorrecto.\n"); exit(2); }
$payload = __DIR__.'/payload_f2_fuente_unica';
$files = [
    'app/helpers/gyd_facturas_ficha_pos.php'=>['old'=>null,'new'=>'e3572e240d72062fd5a3218f818b787bfd1b4735615ff2b901f1419c1adad245'],
    'app/helpers/erp_facturas.php'=>['old'=>'9c3867f79ec17cc107ef6bbc020e819c621e625078ae28d053e65bd19d73c20b','new'=>'d3fe8ba23bba8363470e93af027b0480d12c1bc12b5f0dee0d7fb225a418c6b9'],
    'public/erp_facturas_aplicar.php'=>['old'=>'04b10e1b17da45d30deef198d215b6c1f357547b382e6fddd9fa1f31ffd198b3','new'=>'c02d342d7bdca2f591c349233a70222c935c68970413b9ac07a6363dc7f102bc'],
    'public/pos.php'=>['old'=>'692cc26fb2135d8d68c018e69ee0b6f1b5d9d9d06bf9f01d31bc97513c1956fa','new'=>'b7c712b83397fa0fe0573ed41c2b9c4fb4d1b226902816313d7f159b5343a199'],
    'app/views/layouts/header.php'=>['old'=>'1885d4b325ee03d616bb22441e2de1e8cea0e19f89c587f5a7fa7c68e93613bf','new'=>'afabd6381d90dfa91fe9c722a452a965f009a9507f97c36bb97bc599b4366c21'],
    'public/pos_api.php'=>['old'=>'c195b6336aa24681653134917961f3eaaf44ab47b34e8aba3daf301b4509d9bd','new'=>'695099ff26b77a7b710403e80e73ccd9921fdc06b0d120cd08e829df93dc0ec1'],
    'public/pos_configurar.php'=>['old'=>'35b99e7d9e7c114b52e4e03664a5075a43291ee1d2ef7e71fec22fe2601d1803','new'=>'899f5d98d3e3deb6cbd43bafae11199abc406abebd841ecf7ecad8ee2a3867b7'],
];
$mode = $argv[1] ?? '--help';
if (!in_array($mode,['--check','--apply','--verify'],true)) {
    echo "Uso: php tools/instalar_f2_fuente_unica_facturas.php --check | --apply | --verify\n";
    exit(2);
}
$status=[];$compatible=true;$pendientes=0;
foreach ($files as $rel=>$hash) {
    $dest=$root.'/'.$rel;
    $curr=is_file($dest)?hash_file('sha256',$dest):null;
    $new=$hash['new'];$old=$hash['old'];
    if ($curr === $new) $state='INSTALADO';
    elseif ($curr === $old) { $state='MODIFICAR'; $pendientes++; }
    else { $state='INCOMPATIBLE'; $compatible=false; }
    if (!is_file($payload.'/'.$rel) || hash_file('sha256',$payload.'/'.$rel)!==$new) { $state='PAYLOAD_INVALIDO'; $compatible=false; }
    $status[$rel]=$state;
    echo $state.': '.$rel.PHP_EOL;
}
if (!$compatible) {fwrite(STDERR,"Hay archivos distintos de los auditados. No se modificó nada. Envía el resultado de --check para adaptar el parche.\n");exit(3);}
if ($mode==='--verify') {
    if ($pendientes) {fwrite(STDERR,"Faltan $pendientes archivos por instalar.\n");exit(4);}
    echo "VERIFICADO: 7 archivos actualizados. Ninguna modificación SQL.\n";
    exit(0);
}
if ($mode==='--check') {
    echo "COMPATIBLE: $pendientes archivos pendientes. No se modificó nada.\n";
    exit(0);
}
if (!$pendientes) {echo "INSTALADO: nada que cambiar.\n";exit(0);}
// Protección de laboratorio: se lee la conexión real, sin alterar la base.
try {
    require_once $root.'/app/config/database.php';
    $db=(new Database())->connect();
    $nombre=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$db->query('SELECT @@port')->fetchColumn();
    if ($nombre!=='prueba' || $port!==3307) throw new RuntimeException('Esta instalación exige DB prueba en puerto 3307 (detectado: '.$nombre.':'.$port.').');
} catch(Throwable $e) {fwrite(STDERR,'NO INSTALADO: '.$e->getMessage()."\n");exit(5);}
$base=dirname(dirname($root)).'/gyd_resguardos_codigo';
$backup=$base.'/f2_fuente_unica_facturas_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
if (!is_dir($backup) && !mkdir($backup,0700,true)) {fwrite(STDERR,"No se pudo crear respaldo fuera de htdocs.\n");exit(6);}
$changed=[];
try {
    foreach ($files as $rel=>$hash) {
        if ($status[$rel]!=='MODIFICAR') continue;
        $dest=$root.'/'.$rel;$orig=$hash['old'];
        if (!is_dir(dirname($dest))) throw new RuntimeException('Falta carpeta del proyecto: '.dirname($dest));
        if ($orig!==null) {
            $bpath=$backup.'/'.$rel;
            if (!is_dir(dirname($bpath)) && !mkdir(dirname($bpath),0700,true)) throw new RuntimeException('No pudo crear carpeta de respaldo.');
            if (!copy($dest,$bpath) || hash_file('sha256',$bpath)!==$orig) throw new RuntimeException('No pudo respaldar '.$rel);
        }
    }
    $meta=['origen'=>$root,'creado'=>date(DATE_ATOM),'archivos'=>$files];
    if (file_put_contents($backup.'/MANIFIESTO.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('No se guardó manifiesto del respaldo.');
    foreach ($files as $rel=>$hash) {
        if ($status[$rel]!=='MODIFICAR') continue;
        $dest=$root.'/'.$rel;
        // Copia solo desde payload verificado; en Windows copy sobrescribe sin depender de rename.
        $changed[]=$rel; // Registrar antes de sobrescribir para restaurar incluso un fallo de copia parcial.
        if (!copy($payload.'/'.$rel,$dest) || hash_file('sha256',$dest)!==$hash['new']) throw new RuntimeException('Fallo al instalar '.$rel);
    }
    echo "CODIGO ACTUALIZADO. MYSQL NO MODIFICADO.\n";
    echo 'Respaldo: '.$backup.PHP_EOL;
    echo "Ejecuta --verify y luego prueba Entradas por factura.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\nRestaurando archivos modificados...\n");
    foreach (array_reverse($changed) as $rel) {
        if ($files[$rel]['old']===null) @unlink($root.'/'.$rel);
        else @copy($backup.'/'.$rel,$root.'/'.$rel);
    }
    fwrite(STDERR,'Respaldo: '.$backup.PHP_EOL);
    exit(7);
}
