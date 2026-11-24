<?php
declare(strict_types=1);
/**
 * G&D — Retirar módulo redundante «Movimientos Internos» agregado por
 * instalar_movimientos_internos.php. No toca Resurtidos ni el inventario.
 *
 * Uso: --check | --apply | --verify
 * --apply preserva la tabla si hay registros históricos; si está vacía y la
 * instalación original tiene su manifest, elimina SOLO esa tabla específica.
 */
$op = $argv[1] ?? '--check';
$root = dirname(__DIR__);
$tools = __DIR__;
$backupRoot = $tools . '/respaldos_retirada_movimientos_internos';
$originalManifest = $tools . '/respaldos_movimientos_internos/manifest.json';
$ownManifest = $backupRoot . '/retiro.json';
$mainFiles = [
 'pagina' => ['public/erp_movimientos_internos.php', '35c6f875281d4659da3f7414d689e0f6c2eae874b2b289303906ddd91e524503'],
 'helper' => ['app/helpers/gyd_movimientos_internos.php', 'f89cb2c15d35a7596683120e34c57e9a22a50d418766939153e7bde996510ec9'],
];
$optionalFiles = [
 'instalador_antiguo' => ['tools/instalar_movimientos_internos.php', '6894049c7aa5ff4234019935d6789d358e5ab161a8d4fd8cf45db153d2ff12bc'],
 'fuente_pagina' => ['tools/movimientos_internos_src/public/erp_movimientos_internos.php', '35c6f875281d4659da3f7414d689e0f6c2eae874b2b289303906ddd91e524503'],
 'fuente_helper' => ['tools/movimientos_internos_src/app/helpers/gyd_movimientos_internos.php', 'f89cb2c15d35a7596683120e34c57e9a22a50d418766939153e7bde996510ec9'],
 'pruebas_antiguas' => ['tools/movimientos_internos_src/tests/test_movimientos_internos.php', 'bac2fc19cc0ef94c3c76d27042a9434947b1b7ef0c1edbc986d2b444ab64bf4b'],
];
$navFiles = [
 'enlace_kardex' => 'public/erp_kardex_general.php',
 'enlace_conciliacion' => 'public/erp_conciliacion_gestion.php',
];
const TARGET_LINK = '<a class="btn light" href="erp_movimientos_internos.php">Movimientos internos</a>';
const TARGET_TABLE = 'gyd_movimientos_internos';
function rmi_file(string $path): string {
    $data = @file_get_contents($path);
    if ($data === false) throw new RuntimeException('No fue posible leer ' . $path);
    return $data;
}
function rmi_write(string $path, string $data): void {
    $bytes = @file_put_contents($path, $data, LOCK_EX);
    if ($bytes !== strlen($data)) throw new RuntimeException('No fue posible escribir ' . $path);
}
function rmi_db(string $root): PDO {
    if (!is_file($root.'/app/config/database.php')) throw new RuntimeException('No existe configuración de BD en el ERP.');
    require_once $root.'/app/config/database.php';
    $pdo = (new Database())->connect();
    if (!($pdo instanceof PDO)) throw new RuntimeException('Conexión PDO no disponible.');
    $row = $pdo->query('SELECT DATABASE() AS db, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (($row['db'] ?? '') !== 'prueba' || (int)($row['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Protección: exige base prueba en puerto 3307. No se cambia ningún archivo ni tabla.');
    }
    return $pdo;
}
function rmi_table(PDO $db): array {
    $s=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_movimientos_internos'");
    $engine=$s->fetchColumn();
    if ($engine===false) return ['exists'=>false,'rows'=>0,'engine'=>''];
    if (strtoupper((string)$engine)!=='INNODB') throw new RuntimeException('Tabla de movimientos internos con estructura inesperada: no se eliminará.');
    $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gyd_movimientos_internos'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id','clave','folio','sucursal_id','producto_id','cantidad','farmacia_antes','farmacia_despues','almacen_antes','almacen_despues','general_antes','general_despues'] as $c) {
        if (!in_array($c,$columns,true)) throw new RuntimeException('Tabla del módulo personalizada: no se eliminará. Falta '.$c);
    }
    $n=(int)$db->query('SELECT COUNT(*) FROM gyd_movimientos_internos')->fetchColumn();
    return ['exists'=>true,'rows'=>$n,'engine'=>'InnoDB'];
}
function rmi_has_references(PDO $db): bool {
    $s=$db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?");
    $s->execute([TARGET_TABLE]);
    return (int)$s->fetchColumn()>0;
}
function rmi_preflight(string $root,array $main,array $optional,array $nav): array {
    $remove=[];$update=[];$warnings=[];
    foreach ($main as $label=>[$relative,$sha]) {
        $p=$root.'/'.$relative;
        if (!is_file($p)) continue;
        if (hash_file('sha256',$p)!==$sha) throw new RuntimeException('Archivo del módulo personalizado: '.$relative.'. Para protegerlo no se eliminará nada; comparte ese archivo.');
        $remove[$label]=$p;
    }
    foreach ($optional as $label=>[$relative,$sha]) {
        $p=$root.'/'.$relative;
        if (!is_file($p)) continue;
        if (hash_file('sha256',$p)!==$sha) { $warnings[]='Se conservará el archivo de herramientas modificado: '.$relative;continue; }
        $remove[$label]=$p;
    }
    foreach ($nav as $label=>$relative) {
        $p=$root.'/'.$relative;
        if (!is_file($p)) continue;
        $orig=rmi_file($p);
        $count=substr_count($orig,TARGET_LINK);
        if ($count>1) throw new RuntimeException('Enlace duplicado en '.$relative.'. Revisar manualmente.');
        $after=$count===1?str_replace(TARGET_LINK,'',$orig):$orig;
        if (str_contains($after,'erp_movimientos_internos.php')) {
            throw new RuntimeException('Se detectó una referencia personalizada al módulo en '.$relative.'; no se modifica nada automáticamente.');
        }
        if ($count===1) $update[$label]=['path'=>$p,'original'=>$orig,'updated'=>$after];
    }
    return ['remove'=>$remove,'update'=>$update,'warnings'=>$warnings];
}
try {
    if (!in_array($op,['--check','--apply','--verify'],true)) throw new RuntimeException('Uso: --check | --apply | --verify');
    if (!is_dir($root.'/public')||!is_dir($root.'/app')) throw new RuntimeException('Coloca este archivo en la carpeta tools del ERP G&D.');
    // La verificación del destino se hace antes de cualquier modificación.
    $db=rmi_db($root);
    $table=rmi_table($db);
    $plan=rmi_preflight($root,$mainFiles,$optionalFiles,$navFiles);
    if ($op==='--verify') {
        if (is_file($root.'/public/erp_movimientos_internos.php')||is_file($root.'/app/helpers/gyd_movimientos_internos.php')) throw new RuntimeException('Aún quedan archivos operativos del módulo.');
        foreach ($navFiles as $p) if (is_file($root.'/'.$p)&&str_contains(rmi_file($root.'/'.$p),'erp_movimientos_internos.php')) throw new RuntimeException('Permanece un enlace del módulo en '.$p);
        echo "VERIFICADO: módulo duplicado y enlaces deshabilitados. Resurtidos y auditoría intactos.\n";
        if ($table['exists']) echo "HISTORIAL CONSERVADO: tabla propia con {$table['rows']} filas (sin pantalla operativa).\n";
        else echo "Tabla del módulo redundante ausente.\n";
        exit(0);
    }
    $countFiles=count($plan['remove']);$countLinks=count($plan['update']);
    $canDrop=$table['exists']&&$table['rows']===0&&is_file($originalManifest)&&!rmi_has_references($db);
    if ($op==='--check') {
        echo "COMPATIBLE: comprobado destino prueba:3307. No se han realizado cambios.\n";
        echo "Archivos del módulo a retirar: $countFiles. Enlaces a retirar: $countLinks.\n";
        if (!$table['exists']) echo "Tabla independiente: no existe.\n";
        else if ($table['rows']>0) echo "Tabla con {$table['rows']} movimientos: SE CONSERVARÁ el historial.\n";
        else echo $canDrop?"Tabla sin movimientos y con instalación reconocida: se eliminará.\n":"Tabla sin movimientos: se conservará por precaución (sin manifiesto o con dependencias).\n";
        foreach($plan['warnings'] as $w) echo "AVISO: $w\n";
        exit(0);
    }
    // --apply. Crear respaldo completo antes de borrar o editar cualquiera de los archivos.
    if (is_file($ownManifest)) {
        // Si fue aplicado previamente, permitir verificar sin sobrescribir los respaldos.
        if (!$countFiles&&!$countLinks) {
            echo "YA RETIRADO: no hay pantalla ni enlaces adicionales por eliminar.\n";
            echo "La base de movimientos/Resurtidos no se ha modificado.\n";
            exit(0);
        }
        throw new RuntimeException('Ya existe un respaldo de desinstalación, pero se encontraron archivos nuevos. No se sobreescribirá.');
    }
    if (!is_dir($backupRoot)&&!@mkdir($backupRoot,0770,true)) throw new RuntimeException('No se pudo crear respaldo de retirada.');
    // Revisar conflictos de respaldos ANTES de guardar el primero.
    foreach(array_keys($plan['remove']+$plan['update']) as $key) {
        if(is_file($backupRoot.'/'.$key.'.bak')) throw new RuntimeException('Ya existe respaldo previo de '.$key.'. Nada se modificó.');
    }
    $backed=[];
    foreach($plan['remove'] as $key=>$p) {
        $bak=$backupRoot.'/'.$key.'.bak';
        if (is_file($bak)) throw new RuntimeException('Ya existe respaldo antiguo de '.$key.'. Nada se modificó.');
        rmi_write($bak,rmi_file($p));$backed[$key]=['path'=>$p,'backup'=>$bak,'original_sha'=>hash_file('sha256',$p),'action'=>'remove'];
    }
    foreach($plan['update'] as $key=>$item) {
        $bak=$backupRoot.'/'.$key.'.bak';
        if (is_file($bak)) throw new RuntimeException('Ya existe respaldo antiguo de '.$key.'. Nada se modificó.');
        rmi_write($bak,$item['original']);$backed[$key]=['path'=>$item['path'],'backup'=>$bak,'original_sha'=>hash('sha256',$item['original']),'action'=>'update'];
    }
    $done=[];
    try {
        foreach($plan['update'] as $key=>$item) {
            rmi_write($item['path'],$item['updated']);$done[]=$key;
        }
        foreach($plan['remove'] as $key=>$p) {
            if(!@unlink($p)) throw new RuntimeException('No fue posible retirar '.$p);
            $done[]=$key;
        }
        // Para preservar trazabilidad, la tabla de movimientos solo se elimina si está vacía,
        // la instalación original dejó manifiesto y nadie tiene FK hacia ella.
        $dropped=false;
        if ($canDrop) {
            $now=rmi_table($db);
            if ($now['exists']&&$now['rows']===0&&!rmi_has_references($db)) {
                $db->exec('DROP TABLE gyd_movimientos_internos');$dropped=true;
            } else throw new RuntimeException('La tabla cambió desde la revisión; no se eliminará.');
        }
        $meta=['created_at'=>date(DATE_ATOM),'files'=>$backed,'table_removed_empty'=>$dropped,'table_had_rows'=>$table['rows']];
        rmi_write($ownManifest,json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    } catch(Throwable $ex) {
        foreach (array_reverse($done) as $key) {
            $p=$backed[$key]['path'];$b=$backed[$key]['backup'];
            rmi_write($p,rmi_file($b));
        }
        throw $ex;
    }
    // Quitar directorios originales solo si ya quedaron vacíos.
    foreach (['/tools/movimientos_internos_src/tests','/tools/movimientos_internos_src/public','/tools/movimientos_internos_src/app/helpers','/tools/movimientos_internos_src/app','/tools/movimientos_internos_src'] as $dir) @rmdir($root.$dir);
    echo "ELIMINADO: pantalla Movimientos Internos, helper, enlaces y archivos intactos del instalador anterior.\n";
    if($dropped)echo "ELIMINADA: tabla exclusiva del módulo, porque estaba vacía y sin dependencias.\n";
    else if($table['exists'])echo "CONSERVADA: tabla histórica del módulo; {$table['rows']} registros, para no perder evidencia.\n";
    foreach($plan['warnings'] as $w)echo "AVISO: $w\n";
    echo "INTACTOS: Resurtidos, Kardex, auditoría transaccional, productos, existencias y sucursales.\n";
    echo "Respaldos de los archivos retirados en: $backupRoot\n";
} catch(Throwable $e) {
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);
}
