<?php
declare(strict_types=1);
/** Instalador seguro: php tools/instalar_autollenado_verificacion_oc.php --check|--apply */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Sólo CLI'); }
$modo = $argv[1] ?? '--check';
if (!in_array($modo,['--check','--apply'],true)) {fwrite(STDERR,"Usa --check o --apply\n");exit(2);}
$root = dirname(__DIR__);
$target = $root.'/public/erp_facturas_verificacion.php';
$source = $root.'/app/helpers/erp_oc_autollenado_verificacion.php';
if (!is_file($target) || !is_file($source)) { fwrite(STDERR,"FALTAN ARCHIVOS: copia tools y app del ZIP a la raíz de almacen-farmacia2.\n");exit(2); }
$in = file_get_contents($target);
if ($in === false) {fwrite(STDERR,"No pude leer el módulo.\n");exit(2);}
$mark='GYD_OC_PRECARGA_VERIFICACION_V1';
if (str_contains($in,$mark)) {echo "YA INSTALADO: no se modificarán archivos.\n";exit(0);}
function una(string $text, string $needle, string $name): bool {
    $n=substr_count($text,$needle);
    if ($n !== 1) {fwrite(STDERR,"INCOMPATIBLE: {$name}; coincidencias={$n}. No se modificó nada.\n");return false;}
    return true;
}
$rules=[
 ["require_once __DIR__.'/../app/helpers/erp_facturas_oc_documental.php';",'Dependencia verificación'],
 ['$ds=ef_resumen_detalles($db,$fid);','Lectura factura'],
 ["<p class=\"muted\">Captura buenas, faltantes, dañadas, caducadas o equivocadas",'Texto verificación'],
 ['<?php foreach($ds as $d):$did=(int)$d[\'id\'];$pen=max(0,(int)$d[\'cantidad_facturada\']-(int)$d[\'aceptadas\']-(int)$d[\'incidencias_pendientes\']);?>','Filas verificación'],
 ['max="<?=$pen?>" value="0" step="1"','Campos cantidades'],
 ['name="lote[<?=$did?>]" maxlength="100" placeholder="Lote"','Campo lote'],
 ['name="caducidad[<?=$did?>]" type="date"','Campo caducidad'],
];
$ok=true;foreach($rules as $r)if(!una($in,$r[0],$r[1]))$ok=false;
if(!$ok)exit(3);
echo "COMPATIBLE: precarga por OC en factura, sin tocar base de datos, inventario ni validaciones.\n";
if($modo==='--check')exit(0);
$replacements=[
 $rules[0][0]=>$rules[0][0]." // {$mark}\nrequire_once __DIR__.'/../app/helpers/erp_oc_autollenado_verificacion.php';",
 $rules[1][0]=>$rules[1][0]."\n     \$autoOc=gyd_oc_precargar_verificacion(\$db,(int)(\$factura['orden_id']??0),\$ds);",
 $rules[2][0]=>"<?php if(!empty(\$autoOc['piezas']) || !empty(\$autoOc['por_revisar'])): ?><div class=\"alert success\" style=\"padding:9px 12px\"><b>Recepción de Bodega vinculada:</b> <?=number_format((int)\$autoOc['piezas'])?> piezas en <?= (int)\$autoOc['productos'] ?> artículos precargadas desde OC. Verifica diferencias antes de guardar. <?php if(!empty(\$autoOc['por_revisar'])): ?>Hay <?= (int)\$autoOc['por_revisar'] ?> productos con lotes diferentes o caducidad vencida que necesitan revisión manual.<?php endif; ?></div><?php endif; ?>\n".$rules[2][0],
 $rules[3][0]=>"<?php foreach(\$ds as \$d):\$did=(int)\$d['id'];\$pen=max(0,(int)\$d['cantidad_facturada']-(int)\$d['aceptadas']-(int)\$d['incidencias_pendientes']);\$auto=\$autoOc['partidas'][\$did]??[];?>",
 $rules[4][0]=>'max="<?=$pen?>" value="<?=($k===\'aceptada\'?(int)($auto[\'buenas\']??0):0)?>" step="1"',
 $rules[5][0]=>'name="lote[<?=$did?>]" maxlength="100" value="<?=ef_e((string)($auto[\'lote\']??\'\'))?>" placeholder="Lote"',
 $rules[6][0]=>'name="caducidad[<?=$did?>]" type="date" value="<?=ef_e((string)($auto[\'caducidad\']??\'\'))?>"',
];
$out=$in;foreach($replacements as $old=>$new)$out=str_replace($old,$new,$out);
$backup=$target.'.backup_autollenado_'.date('Ymd_His');
if(!copy($target,$backup)) {fwrite(STDERR,"No se pudo crear respaldo; cancelando.\n");exit(4);}
$tmp=$target.'.temp_autollenado_'.getmypid();
if(file_put_contents($tmp,$out,LOCK_EX)===false){fwrite(STDERR,"No se pudo escribir temporal. Respaldo: {$backup}\n");exit(4);}
if(!rename($tmp,$target)){@unlink($tmp);fwrite(STDERR,"No se pudo reemplazar. Respaldo: {$backup}\n");exit(4);}
echo "INSTALADO. Respaldo: {$backup}\n";
