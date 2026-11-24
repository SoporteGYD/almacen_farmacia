<?php
declare(strict_types=1);
/** ERP G&D - Comparación de tres costos por código; instalador incremental CLI. */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Solo uso por consola.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_oc_tres_costos.php --check|--apply\n");exit(2);
}
$root=dirname(__DIR__);
$pathPage=$root.'/public/erp_oc_win.php';
$pathValidation=$root.'/public/assets/js/erp_oc_validacion.js';
$pathApi=$root.'/public/erp_oc_costos_consultar.php';
$pathJs=$root.'/public/assets/js/erp_oc_comparador_costos.js';
$packageApi=__DIR__.'/../public/erp_oc_costos_consultar.php';
$packageJs=__DIR__.'/../public/assets/js/erp_oc_comparador_costos.js';
$written=[];$backups=[];
try{
 if(!is_file($pathPage)||!is_file($pathValidation))
  throw new RuntimeException('INCOMPATIBLE: instala primero los parches de validación inteligente y validaciones compactas de la orden.');
 $sourcePage=file_get_contents($pathPage);$sourceValidation=file_get_contents($pathValidation);
 $api=file_get_contents($packageApi);$js=file_get_contents($packageJs);
 if(!is_string($sourcePage)||!is_string($sourceValidation)||!is_string($api)||!is_string($js)) throw new RuntimeException('No se pudieron leer archivos del paquete.');
 if(!str_contains($sourcePage,'id="ocForm"')||!str_contains($sourcePage,'id="lineas"')||!str_contains($sourcePage,'id="ocImportFile"')||(!str_contains($sourcePage,'<?php if($editable):?><script>')&&!str_contains($sourcePage,'GYD_OC_TRES_COSTOS_V1')))
  throw new RuntimeException('INCOMPATIBLE: plantilla de órdenes diferente. No se ha modificado nada.');
 if(!str_contains($sourceValidation,'GYD_OC_VALIDACION_COMPACTA_V1')||!str_contains($sourceValidation,'window.gydOcRevisionEvaluar = revisar;'))
  throw new RuntimeException('INCOMPATIBLE: necesitas instalar primero el parche de validaciones compactas.');
 $marker='GYD_OC_TRES_COSTOS_V1';
 $css=<<<'CSS'
/* GYD_OC_TRES_COSTOS_V1: datos históricos SOLO LECTURA */
.oc-costos-compare{margin:9px 0 13px;border:1px solid #c8dae9;background:#f7fbff;border-radius:11px;overflow:hidden}
.oc-costos-compare>summary{list-style:none;cursor:pointer;padding:10px 13px;font-size:12px;color:#255479;font-weight:800;display:flex;align-items:center;justify-content:space-between;gap:9px}
.oc-costos-compare>summary::-webkit-details-marker{display:none}
.oc-costos-compare>summary span{font-size:11px;color:#5e7890;font-weight:650}
.oc-costos-compare[open]>summary{border-bottom:1px solid #dae6ef}
.oc-costos-body{padding:9px 12px 12px}
.oc-costos-table-wrap{width:100%;overflow-x:auto}
.oc-costos-table{width:100%;border-collapse:collapse;min-width:680px;font-size:12px}
.oc-costos-table th{text-align:left;background:#eaf3fb;color:#214967;font-weight:800;padding:8px;border-bottom:1px solid #cfdde9}
.oc-costos-table td{padding:8px;border-bottom:1px solid #e0eaf2;font-variant-numeric:tabular-nums}
.oc-costos-table th:not(:first-child),.oc-costos-table td:not(:first-child){text-align:right}
.oc-costos-table .oc-nuevo{font-weight:800;color:#184d7f}
.oc-costos-table .oc-alza{color:#af3f38;font-weight:800}
.oc-costos-table .oc-baja{color:#187846;font-weight:800}
.oc-costos-table .oc-igual{color:#526b7d}
.oc-costos-help{font-size:11px;line-height:1.45;color:#607b92;margin:8px 0 0}
.oc-costos-state{font-size:11px;color:#647f96;margin:7px 0}
.oc-costos-retry{border:1px solid #8fb1cc;background:#fff;color:#20527a;border-radius:6px;padding:5px 9px;font-size:11px;cursor:pointer}
CSS;
 $html=<<<'HTML'
  <details class="oc-costos-compare" id="ocCostosComparador">
   <summary>Comparar costos por código <span id="ocCostosCuenta">0 artículos</span></summary>
   <div class="oc-costos-body">
    <div class="oc-costos-table-wrap"><table class="oc-costos-table" aria-label="Último, promedio y nuevo costo por código"><thead><tr><th>Código</th><th>Último costo</th><th>Costo promedio</th><th>Costo nuevo</th><th>Vs. último</th><th>Vs. promedio</th></tr></thead><tbody id="ocCostosFilas"></tbody></table></div>
    <p class="oc-costos-state" id="ocCostosEstado" role="status">Agrega productos para consultar los costos.</p>
    <button class="oc-costos-retry" type="button" id="ocCostosReintentar" hidden>Reintentar consulta</button>
    <p class="oc-costos-help">El último costo y el promedio proceden del Catálogo Maestro (productos.costo_ultimo y productos.costo_promedio). El nuevo es el importe capturado en esta orden. Los históricos pueden provenir de entradas o actualizaciones previas; no se modifican al crear la orden. "Sin dato" indica que no hay referencia registrada.</p>
   </div>
  </details>
HTML;
 $scriptTag='<script src="assets/js/erp_oc_comparador_costos.js?v=20261021" defer></script>';
 $pageAlready=str_contains($sourcePage,$marker);
 $validationAlready=str_contains($sourceValidation,'const base = Number(row.dataset.ultimoCosto || 0);');
 if($pageAlready){
  if(substr_count($sourcePage,$marker)!==1||substr_count($sourcePage,'id="ocCostosComparador"')!==1||substr_count($sourcePage,$scriptTag)!==1)
   throw new RuntimeException('INCOMPATIBLE: parte del comparador ya está modificada. Detenido sin sobrescribir.');
  $patchedPage=$sourcePage;
 }else{
  $patchedPage=$sourcePage;
  $rules=[
   ['</style></head>',"\n".$css."\n</style></head>"],
   ['  <div class="mini-help">Las cajas son informativas.', $html."\n".'  <div class="mini-help">Las cajas son informativas.'],
   ['<?php if($editable):?><script>','<?php if($editable):?>'.$scriptTag.'<script>']
  ];
  foreach($rules as [$before,$after]){
   if(substr_count($patchedPage,$before)!==1)throw new RuntimeException('INCOMPATIBLE: no se encontró exactamente una coincidencia para el ajuste de pantalla.');
   $patchedPage=str_replace($before,$after,$patchedPage);
  }
 }
 if($validationAlready){$patchedValidation=$sourceValidation;}
 else{
  $old='const base = Number(row.dataset.catalogCost);';
  if(substr_count($sourceValidation,$old)!==1)throw new RuntimeException('INCOMPATIBLE: validación de costos distinta a la conocida.');
  $patchedValidation=str_replace($old,'const base = Number(row.dataset.ultimoCosto || 0);',$sourceValidation);
  $patchedValidation=str_replace("' vs. referencia '","' vs. último costo '",$patchedValidation);
 }
 if(!str_contains($patchedPage,$marker)||!str_contains($patchedPage,'id="ocCostosFilas"'))throw new RuntimeException('Fallo de integridad en el ajuste de pantalla.');
 foreach([[$pathApi,$api],[$pathJs,$js]] as [$target,$content]){
  if(is_file($target)&&file_get_contents($target)!==$content)
   throw new RuntimeException('INCOMPATIBLE: existe un archivo diferente en '.$target.'. No se sobrescribirá.');
 }
 foreach([[$pathPage,$patchedPage],[$pathValidation,$patchedValidation],[$pathApi,$api]] as [$target,$content]){
  $tmp=tempnam(sys_get_temp_dir(),'gyc_');
  if($tmp===false)throw new RuntimeException('No hay espacio temporal para verificación.');
  try{
   if(file_put_contents($tmp,$content)===false)throw new RuntimeException('Error escribiendo archivo temporal.');
   if(str_ends_with($target,'.php')){
    $out=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$code);
    if($code!==0)throw new RuntimeException('PHP inválido: '.$target.' '.implode(' ',$out));
   }
  }finally{@unlink($tmp);}
 }
 $planned=[[$pathPage,$patchedPage],[$pathValidation,$patchedValidation],[$pathApi,$api],[$pathJs,$js]];
 $pending=[];
 foreach($planned as [$path,$new]) if(!is_file($path)||file_get_contents($path)!==$new)$pending[]=[$path,$new];
 echo count($pending)===0?"YA APLICADO: tres costos por código.\n":"COMPATIBLE: ".count($pending)." archivo(s) pendientes.\n";
 if($mode==='--check'){echo "COMPROBADO, no se modificó ningún archivo.\n";exit(0);}
 if(!$pending)exit(0);
 // Respaldar ANTES de cambiar cualquier archivo existente.
 foreach($pending as [$path,$new]){
  if(is_file($path)){
   $backup=$path.'.backup_tres_costos_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
   if(!copy($path,$backup))throw new RuntimeException('No se pudo respaldar '.$path);
   $backups[$path]=$backup;
  }
 }
 try{
  foreach($pending as [$path,$new]){
   $dir=dirname($path);
   if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear '.$dir);
   $size=file_put_contents($path,$new,LOCK_EX);
   if($size===false||$size!==strlen($new))throw new RuntimeException('Error escribiendo '.$path);
   $written[]=$path;
   if(hash_file('sha256',$path)!==hash('sha256',$new))throw new RuntimeException('Verificación SHA-256 fallida en '.$path);
  }
 }catch(Throwable $e){
  foreach(array_reverse($written) as $path){if(isset($backups[$path]))@copy($backups[$path],$path);else @unlink($path);}
  // Restaurar el archivo iniciado aunque haya fallado la escritura a mitad.
  foreach($pending as [$path,$new])if(isset($backups[$path])&&!in_array($path,$written,true))@copy($backups[$path],$path);
  throw $e;
 }
 foreach($backups as $original=>$backup)echo "RESPALDO: $backup\n";
 echo "INSTALADO: comparación por código. Sin cambios a BD, órdenes previas, IVA, TXT, inventario ni costos históricos.\n";
 echo "Abre erp_oc_win.php y pulsa Ctrl+F5.\n";
}catch(Throwable $e){fwrite(STDERR,"DETENIDO: ".$e->getMessage()."\n");exit(1);}
