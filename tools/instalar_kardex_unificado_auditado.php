<?php
/** Actualización incremental y reversible del Kardex G&D: solo archivos PHP. */
declare(strict_types=1);
$root=dirname(__DIR__);
$page=$root.'/public/erp_kardex_general.php';
$src=__DIR__.'/unificacion_kardex_src/gyd_kardex_unificado_audit.php';
$dest=$root.'/app/helpers/gyd_kardex_unificado_audit.php';
$backups=__DIR__.'/respaldos_kardex_unificado';
$operation=$argv[1]??'--check';
const GYD_KUM_FLAG='GYD_KARDEX_UNIFICADO_AUDITADO_2026';

function gyd_kum_need(bool $ok,string $error):void{if(!$ok)throw new RuntimeException($error);}
function gyd_kum_original(string $path):string{
    gyd_kum_need(is_file($path),'Archivo faltante: '.$path);
    $content=file_get_contents($path);
    gyd_kum_need($content!==false,'No se pudo leer: '.$path);
    return $content;
}
function gyd_kum_build(string $text):string {
    if(str_contains($text,GYD_KUM_FLAG))return $text;
    // Exigir dependencias exactas de las actualizaciones anteriores.
    gyd_kum_need(str_contains($text,"require_once __DIR__.'/../app/helpers/erp_kardex_saldos.php';"),'Falta la actualización de saldos antes/después del Kardex.');
    gyd_kum_need(str_contains($text,'GYD_STOCK_KARDEX_PANEL_2026'),'Falta la auditoría transaccional del Kardex.');
    $replaces=[
       ["'CONTEO'=>'Conteos físicos'", "'CONTEO'=>'Conteos físicos','AUDITORIA'=>'Movimientos auditados'"],
       ["$".'limit=70;' . "$".'count=count($events);',
          "// ".GYD_KUM_FLAG." : incorpora registros físicos confirmados al historial antes de paginar y exportar.\n"
          ."if (!\$error && \$producto && isset(\$db) && \$db instanceof PDO) {\n"
          ."    require_once __DIR__.'/../app/helpers/gyd_kardex_unificado_audit.php';\n"
          ."    \$events=gyd_kum_combinar(\$db,\$events,\$sid,\$pid,\$desde,\$hasta,\$tipo,\$usuarioFiltrado,\$warnings);\n"
          ."}\n\$limit=70;\$count=count(\$events);"],
       ['<?=kg_sa_celda($r,$k)?>','<?=gyd_kum_celda($r,$k)?>'],
       ['<?=kg_h($r[\'origen\'].\' #\'.$r[\'id\'])?>',
          "<?=kg_h(\$r['origen'].' #'.\$r['id'])?>"], // no-op anchor, checked separately
    ];
    foreach($replaces as $i=>$pair) {
        if($i===3) continue;
        [$old,$new]=$pair;
        gyd_kum_need(substr_count($text,$old)===1,'El Kardex difiere del esperado en bloque '.($i+1).'. No se cambió.');
        $text=str_replace($old,$new,$text);
    }
    $old="<?php /* GYD_STOCK_KARDEX_PANEL_2026 */\nif (isset(\$db,\$sid,\$pid,\$desde,\$hasta) && \$db instanceof PDO && (int)\$pid>0) {\n    require_once __DIR__.'/../app/helpers/gyd_stock_kardex_verified.php';\n    gyd_vk_panel(\$db,(int)\$sid,(int)\$pid,(string)\$desde,(string)\$hasta);\n} ?>";
    gyd_kum_need(substr_count($text,$old)===1,'No se reconoce el panel anterior de auditoría. No se cambió nada.');
    $text=str_replace($old,'<?php /* GYD_STOCK_KARDEX_PANEL_2026 integrado en el historial; exportación auditada disponible arriba. */ ?>',$text);
    $needle='<?=kg_h($r[\'estado\'])?>';
    gyd_kum_need(substr_count($text,$needle)===1,'No se encontró la columna Estado/fuente.');
    // Los movimientos auditados y los documentos se distinguen visualmente.
    $text=str_replace($needle, '<span class="badge"><?=($r[\'origen\']??\'\')===\'gyd_stock_auditoria_real\'?\'AUDITADO\':\'DOCUMENTO\'?></span><br>'.$needle,$text);
    // Link a la exportación auditada completa existente, junto al CSV combinado.
    $link=<<<'LINK'
<a class="btn light" href="erp_stock_auditoria_export.php?<?=kg_h(http_build_query(['sucursal'=>$sid,'producto'=>$pid,'desde'=>$desde,'hasta'=>$hasta,'formato'=>'xlsx']))?>">Excel · cambios auditados</a>
LINK;
    // Agregar junto al botón de Exportar CSV; el bloque original continúa intacto.
    $needle="<?php endif;?></div></form>";
    gyd_kum_need(substr_count($text,$needle)===1,'No se encontró el cierre de botones de filtros.');
    $text=str_replace($needle,$link.$needle,$text);
    $paragraph='<p class="muted">Cada registro <b>AUDITADO</b> corresponde a una escritura física en General, Farmacia o una ubicación de Almacén, con identificador propio. Un mismo proceso puede generar más de una escritura física y una referencia documental: <b>no sumes las filas como inventario ni como ventas diferentes</b>. Los históricos sin auditoría siguen identificados como reconstruidos o no verificables.</p>';
    $needle='<section class="card"><h2 class="sectionTitle">Historial de movimientos</h2>';
    gyd_kum_need(substr_count($text,$needle)===1,'No se encontró el encabezado del historial.');
    $text=str_replace($needle,$needle.$paragraph,$text);
    return $text;
}
try {
    gyd_kum_need(in_array($operation,['--check','--apply','--verify','--rollback'],true),'Opciones: --check --apply --verify --rollback');
    $present=gyd_kum_original($page);
    if($operation==='--verify'){
        gyd_kum_need(str_contains($present,GYD_KUM_FLAG)&&is_file($dest),'Falta un componente del Kardex unificado.');
        echo "VERIFICADO: historial único con asientos auditados. No se alteró inventario.\n";exit(0);
    }
    if($operation==='--rollback'){
        $manifest=$backups.'/ultimo.json';
        gyd_kum_need(is_file($manifest),'No existe respaldo para revertir.');
        $m=json_decode(gyd_kum_original($manifest),true);
        gyd_kum_need(is_array($m)&&isset($m['folder'],$m['sha_aplicado']),'Manifest de respaldo inválido.');
        gyd_kum_need(hash_file('sha256',$page)===$m['sha_aplicado'],'Kardex modificado desde la instalación. No se revierte para evitar perder cambios nuevos.');
        $stored=$backups.'/'.$m['folder'].'/erp_kardex_general.php';
        gyd_kum_need(is_file($stored),'Falta respaldo del archivo original.');
        gyd_kum_need(copy($stored,$page),'No se pudo restaurar el Kardex.');
        echo "REVERTIDO: vista del Kardex restaurada. El archivo helper nuevo se conserva sin utilizar; la BD está intacta.\n";exit(0);
    }
    gyd_kum_need(is_file($src),'Falta payload del helper de unificación.');
    gyd_kum_need(is_file($root.'/app/helpers/erp_kardex_saldos.php'),'Falta helper de saldos.');
    gyd_kum_need(is_file($root.'/app/helpers/gyd_stock_kardex_verified.php'),'Falta helper de auditoría real.');
    gyd_kum_need(is_file($root.'/public/erp_stock_auditoria_export.php'),'Falta exportador de auditoría real.');
    $new=gyd_kum_build($present);
    if($operation==='--check'){
        echo $new===$present?"YA INSTALADO: sin cambios.\n":"COMPATIBLE: Kardex ampliado; un solo historial, no modifica inventario ni estructura de la BD.\n";exit(0);
    }
    if($new===$present){echo "YA INSTALADO: actualización omitida.\n";exit(0);}
    $folder=date('Ymd_His').'_'.bin2hex(random_bytes(3));
    $dir=$backups.'/'.$folder;
    gyd_kum_need(is_dir($dir)||mkdir($dir,0770,true),'No se pudo crear el respaldo.');
    gyd_kum_need(copy($page,$dir.'/erp_kardex_general.php'),'No se pudo respaldar el Kardex.');
    if(is_file($dest))gyd_kum_need(copy($dest,$dir.'/gyd_kardex_unificado_audit.php'),'No se pudo respaldar el helper existente.');
    $previousHelper=is_file($dest)?file_get_contents($dest):null;
    try {
        gyd_kum_need(copy($src,$dest),'No se pudo instalar el helper.');
        gyd_kum_need(file_put_contents($page,$new,LOCK_EX)!==false,'No se pudo escribir la página actualizada.');
        $manifest=['folder'=>$folder,'sha_aplicado'=>hash_file('sha256',$page)];
        gyd_kum_need(file_put_contents($backups.'/ultimo.json',json_encode($manifest,JSON_PRETTY_PRINT),LOCK_EX)!==false,'No se pudo registrar el respaldo.');
    } catch(Throwable $error) {
        @copy($dir.'/erp_kardex_general.php',$page);
        if($previousHelper!==null)@file_put_contents($dest,$previousHelper,LOCK_EX);else @unlink($dest);
        throw $error;
    }
    echo "APLICADO: Kardex unificado; respaldo $dir; no se modificaron existencias.\n";
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
