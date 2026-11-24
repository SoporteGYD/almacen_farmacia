<?php
declare(strict_types=1);
/**
 * Parche G&D: retira topes arbitrarios de partidas, usa JSON compacto al guardar,
 * pagina formularios largos e importa por lotes.
 * Solo CLI: php tools/instalar_erp_sin_topes.php --check|--apply
 * No consulta ni modifica MySQL: copia de seguridad antes de aplicar.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo línea de comandos.\n"); }
$mode=$argv[1]??'--check';
if (!in_array($mode,['--check','--apply'],true)) {fwrite(STDERR,"Uso: --check o --apply\n");exit(2);}
$root=realpath(dirname(__DIR__));
$manifestPath=__DIR__.'/patches/erp_sin_limites_manifest.json';
if (!$root||!is_file($manifestPath)){fwrite(STDERR,"Faltan archivos del instalador.\n");exit(2);}
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
if (($manifest['version']??'')!=='GYD_SIN_LIMITES_PARTIDAS_V1') {fwrite(STDERR,"Versión de manifiesto desconocida.\n");exit(2);}
// V2: adaptaciones conservadoras para los tres puntos que cambiaron con otros parches.
function adaptacionV2(string $rel, int $numero, string $source): ?string {
    // 3: quitar exclusivamente el máximo fijo de artículos de la búsqueda manual.
    if ($rel==='public/erp_facturas.php' && $numero===3) {
        $begin=strpos($source,'async function buscar()');
        if ($begin===false) return null;
        $end=strpos($source,"document.getElementById('buscarBoton')",$begin);
        if ($end===false) $end=strpos($source,'form.addEventListener', $begin);
        if ($end===false) return null;
        $body=substr($source,$begin,$end-$begin);
        $pattern='~if\s*\(\s*productos\.size\s*>=\s*\d+\s*&&\s*!\s*productos\.has\s*\(\s*String\s*\(\s*p\.id\s*\)\s*\)\s*\)\s*(?:return\s+)?alert\s*\(\s*([\x27\x22])[^\r\n]*?\1\s*\)\s*;~u';
        $count=0;
        $newBody=preg_replace($pattern,'/* GYD_BUSQUEDA_FACTURA_SIN_TOPE_V1 */',$body, -1,$count);
        if ($newBody===null || $count>1) return null;
        if ($count===1) return substr_replace($source,$newBody,$begin,strlen($body));
        // Ya no hay tope explícito en la búsqueda de productos; no borrar lógica ajena.
        if (str_contains($body,'productos.set(String(p.id)') && !preg_match('~productos\.size\s*(?:>=|>)\s*\d+~',$body)){
            $count=0;
            $newBody=str_replace('productos.set(String(p.id)','/* GYD_BUSQUEDA_FACTURA_SIN_TOPE_V1 */productos.set(String(p.id)',$body,$count);
            if($count===1)return substr_replace($source,$newBody,$begin,strlen($body));
        }
        return null;
    }
    // 8: el manejador personalizado de importación se conserva. Se antepone
    // una captura controlada para aplicar TODAS las filas seleccionadas, no sólo las visibles.
    if ($rel==='public/erp_oc_win.php' && $numero===8) {
        $mark='GYD_IMPORTACION_SIN_TOPE_CAPTURA_V2';
        if (str_contains($source,$mark))return $source;
        $start=strpos($source,"$('ocImportApply').addEventListener('click'");
        if($start===false)return null;
        $end=strpos($source,"\n})();",$start);
        if($end===false)return null;
        $scope=substr($source,0,$end);
        foreach(['const $=','let raw=null,items','importacionEnLote','function render(','function agregar(','const lines='] as $required){
            if(!str_contains($scope,$required))return null;
        }
        $js=<<<'JS'

 // GYD_IMPORTACION_SIN_TOPE_CAPTURA_V2: actúa antes del listener anterior
 // y no elimina las validaciones ni los ajustes existentes del importador.
 $('ocImportApply').addEventListener('click',e=>{
  e.stopImmediatePropagation();
  let imported=0,duplicates=0;
  const previous=importacionEnLote;
  importacionEnLote=true;
  try {
   for (const p of items){
    if(!p.ok||p.selected===false||!p.product||!p.product.id)continue;
    const id=String(p.product.id);
    if(catalog.has(id)){duplicates++;continue;}
    agregar(p.product);
    const tr=[...lines.querySelectorAll('tr[data-id]')].find(x=>x.dataset.id===id);
    if(!tr)continue;
    const set=(n,v)=>{const el=tr.querySelector('[name="'+n+'[]"]');if(el&&v!==undefined&&v!==null){el.value=String(v);el.dispatchEvent(new Event('input',{bubbles:true}));}};
    set('cantidad',p.quantity);set('costo',p.cost);set('cajas',p.cajas);set('iva',p.iva);set('ieps',p.ieps);
    imported++;p.selected=false;
   }
  } finally {importacionEnLote=previous;if(!previous)recalcular();}
  render({ready:items.filter(p=>p.ok).length,review:items.filter(p=>!p.ok).length});
  tell(`${imported} artículos agregados.${duplicates?' '+duplicates+' ya estaban incluidos.':''} Revisa y guarda la orden.`,imported===0);
 },true);
JS;
        return substr_replace($source,$js,$end,0);
    }
    // 9: no reemplazar el submit de otros parches; añadir serialización DESPUÉS
    // de todas las validaciones existentes, y respetar preventDefault.
    if ($rel==='public/erp_oc_win.php' && $numero===9) {
        $mark='GYD_OC_FORM_JSON_RESPETAR_VALIDACIONES_V2';
        if(str_contains($source,$mark))return $source;
        $start=strpos($source,"document.querySelector('#ocForm').addEventListener('submit'");
        if($start===false)return null;
        $end=strpos($source,'</script>',$start);
        if($end===false || !str_contains(substr($source,$start,$end-$start),'e.preventDefault'))return null;
        $js=<<<'JS'

// GYD_OC_FORM_JSON_RESPETAR_VALIDACIONES_V2
// Registrar las partidas después de que termine la validación inteligente ya instalada.
(()=>{const form=document.querySelector('#ocForm');if(!form)return;
 form.addEventListener('submit',e=>{
  if(e.defaultPrevented)return;
  const rows=[...document.querySelectorAll('#lineas tr[data-id]')];
  if(!rows.length)return;
  const fields=['cantidad','costo','iva','ieps','cajas'];
  const data=[];
  for(const tr of rows){
   const r={id:tr.dataset.id};
   for(const f of fields){const el=tr.querySelector('[name="'+f+'[]"]');
    if(!el){e.preventDefault();alert('Hay una partida incompleta. Revisa la orden.');return;}
    r[f]=el.value;
   }
   data.push(r);
  }
  let input=form.querySelector('[name="partidas_json"]');
  if(!input){input=document.createElement('input');input.type='hidden';input.name='partidas_json';form.append(input);}
  input.value=JSON.stringify(data);
  for(const tr of rows){tr.querySelectorAll('input[name$="[]"]').forEach(el=>el.removeAttribute('name'));}
 });
})();
JS;
        return substr_replace($source,$js,$end,0);
    }
    return null;
}

$prepared=[];$errors=[];
foreach ($manifest['files'] as $rel=>$operations) {
    if (str_contains($rel,'..')||str_starts_with($rel,'/')) {$errors[]="Ruta no permitida: $rel";continue;}
    $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
    if (!is_file($path)||!is_readable($path)||!is_writable($path)){$errors[]="No se puede leer/escribir: $rel";continue;}
    $original=file_get_contents($path);
    if (!is_string($original)) {$errors[]="Lectura fallida: $rel";continue;}
    $modified=$original;$changes=0;$already=0;
    foreach($operations as $i=>$operation) {
        $new=$operation['new'];
        // Identificar primero el parche ya instalado, incluso si contiene una parte del texto original.
        if ($new!=='' && substr_count($modified,$new)===1) {$already++;continue;}
        $variants=[];
        foreach($operation['old'] as $old) {
            if ($old!=='' && substr_count($modified,$old)===1) $variants[]=$old;
        }
        if (count($variants)!==1) {
            $adaptada=adaptacionV2($rel,$i+1,$modified);
            if($adaptada!==null && $adaptada!==$modified){
                $modified=$adaptada;$changes++;continue;
            }
            if($adaptada===$modified){$already++;continue;}
            $errors[]="INCOMPATIBLE: $rel / regla ".($i+1).". No se aplicará ningún cambio; entrega los archivos para revisión.";
            continue;
        }
        $modified=str_replace($variants[0],$new,$modified);
        $changes++;
    }
    $prepared[$rel]=['path'=>$path,'old'=>$original,'new'=>$modified];
    echo "$rel: $changes por aplicar, $already ya instaladas\n";
}
if ($errors) {
  echo "INCOMPATIBLE; no se ha modificado ningún archivo.\n";
  foreach($errors as $e)echo " - $e\n";
  exit(1);
}
if ($mode==='--check'){echo "COMPATIBLE. Usa --apply para instalar (con respaldo automático).\n";exit(0);}
$changes=array_filter($prepared,static fn($a)=>$a['old']!==$a['new']);
if (!$changes) {echo "YA INSTALADO. No se modificaron archivos.\n";exit(0);}
$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));
$backupDir=$root.'/tools/respaldos_erp_sin_topes/'.$stamp;
foreach($changes as $rel=>$item) {
  $target=$backupDir.'/'.$rel;
  if (!is_dir(dirname($target)) && !mkdir(dirname($target),0775,true) && !is_dir(dirname($target))) {
    fwrite(STDERR,"No se pudo preparar respaldo. Ningún archivo del ERP fue modificado.\n");exit(1);
  }
  if (file_put_contents($target,$item['old'],LOCK_EX)!==strlen($item['old'])) {
    fwrite(STDERR,"Error creando respaldo de $rel. ERP intacto.\n");exit(1);
  }
}
$applied=[];
try {
 foreach($changes as $rel=>$item) {
   $tmp=$item['path'].'.temp_'.$stamp;
   if(file_put_contents($tmp,$item['new'],LOCK_EX)!==strlen($item['new'])){ @unlink($tmp);throw new RuntimeException("No se pudo crear temporal: $rel"); }
   if(!rename($tmp,$item['path'])){@unlink($tmp);throw new RuntimeException("No se pudo actualizar: $rel");}
   $applied[$rel]=$item;
 }
} catch(Throwable $e) {
 foreach($applied as $rel=>$item) {
   $backup=$backupDir.'/'.$rel;
   if(!copy($backup,$item['path']))fwrite(STDERR,"RESTAURACIÓN MANUAL: copiar $backup a $rel\n");
 }
 fwrite(STDERR,"Error. Intenté restaurar lo aplicado: ".$e->getMessage()."\n");exit(1);
}
echo "INSTALACIÓN TERMINADA. Archivos modificados: ".count($applied)."\n";
echo "RESPALDO: $backupDir\n";
echo "Nota: PHP/MySQL mantienen límites físicos de memoria y tiempo; no hay tope fijo de renglones en estos flujos.\n";
