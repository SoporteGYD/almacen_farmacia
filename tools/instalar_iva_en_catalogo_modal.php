<?php
/** Instalador no destructivo del editor fiscal, G&D ERP. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo terminal CLI.'); }
$mode = $argv[1] ?? '--check';
if (!in_array($mode,['--check','--apply'],true)) {fwrite(STDERR,"Usa --check o --apply\n");exit(2);}
$root = dirname(__DIR__);
$target = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'erp_catalogo_maestro.php';
$planfile = __DIR__ . DIRECTORY_SEPARATOR . 'iva_modal_parches.json';
if (!is_file($target) || !is_readable($target)) {fwrite(STDERR,"DETENIDO: no existe el Catálogo Maestro en $target\n");exit(1);}
$plan = json_decode((string)file_get_contents($planfile),true);
if (!is_array($plan) || count($plan)<15) {fwrite(STDERR,"DETENIDO: plan de actualización no válido.\n");exit(1);}
$orig = file_get_contents($target);if($orig===false)exit(1);
$new=$orig;$changed=[];$present=[];
// Instalación repetida: validar señales concretas sin reescribir contenido.
$installed = str_contains($orig, 'function gydFiscalPorcentaje(')
  && str_contains($orig, 'id="editarIvaCompra"')
  && str_contains($orig, 'id="editarIeps"')
  && str_contains($orig, 'INSERT INTO erp_producto_ficha (producto_id,iva_compra,iva_venta,ieps')
  && str_contains($orig, "'impuestos_catalogo'");
if($installed){echo "YA INSTALADO. Ningún archivo ni dato modificado.\n";exit(0);}
if(str_contains($orig,'function gydFiscalPorcentaje(')){
 fwrite(STDERR,"DETENIDO: actualización aparentemente incompleta; revisa el respaldo antes de continuar.\n");exit(1);
}
foreach ($plan as $step){
    $before=$step['before'];$after=$step['after'];$label=$step['label'];
    $count=substr_count($new,$before);
    if ($count === 1){$new=str_replace($before,$after,$new);$changed[]=$label;continue;}
    if ($count === 0 && substr_count($new,$after) === 1){$present[]=$label;continue;}
    fwrite(STDERR,"DETENIDO: la versión actual no coincide con el parche «".$label."» ($count coincidencias). No se modificó nada.\n");exit(1);
}
echo "Editor de IVA en Catálogo Maestro G&D\nArchivo: $target\nParches nuevos: ".count($changed).". Ya presentes: ".count($present).".\n";
if ($mode==='--check'){ echo "VALIDACIÓN TERMINADA. No se modificaron archivos ni datos.\n";exit(0); }
if (!$changed){echo "No hay cambios pendientes.\n";exit(0);}
$temporary=$target.'.tmp_iva_modal_'.getmypid().'.php';
if(file_put_contents($temporary,$new,LOCK_EX)===false){fwrite(STDERR,"No se pudo escribir archivo temporal.\n");exit(1);}
$cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporary).' 2>&1';
$output=[];$code=1;exec($cmd,$output,$code);
if($code!==0){@unlink($temporary);fwrite(STDERR,"DETENIDO: PHP no pasó revisión de sintaxis:\n".implode("\n",$output)."\n");exit(1);}
$backup=$target.'.backup_iva_modal_'.date('Ymd_His');
if(!copy($target,$backup)){@unlink($temporary);fwrite(STDERR,"DETENIDO: no fue posible respaldar el archivo original.\n");exit(1);}
if(!copy($temporary,$target)){
 copy($backup,$target);@unlink($temporary);fwrite(STDERR,"DETENIDO: falló el reemplazo; se intentó restaurar el respaldo: $backup\n");exit(1);
}
@unlink($temporary);
echo "ACTUALIZADO correctamente. Respaldo: $backup\nNo se modificaron precios, costos, existencias ni facturas.\n";
