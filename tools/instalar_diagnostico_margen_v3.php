<?php
declare(strict_types=1);
/** G&D V3: solo añade diagnóstico y aclaraciones; sin tocar fórmulas de precio ni datos. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/instalar_diagnostico_margen_v3.php --check | --apply\n");exit(2);}
$root=dirname(__DIR__);$src=__DIR__.'/margen_v3_fuentes';$changes=[];$err=[];$notes=[];
$read=function(string $rel)use($root,&$changes,&$err):?string{
 if(isset($changes[$rel]))return $changes[$rel];$path=$root.'/'.$rel;
 if(!is_file($path)){$err[]='Falta modulo requerido: '.$rel;return null;}
 $c=file_get_contents($path);if($c===false){$err[]='No se pudo leer: '.$rel;return null;}return $c;
};
$insertAfter=function(string $file,string $anchor,string $addition,string $flag)use($read,&$changes,&$err,&$notes):void{
 $c=$read($file);if($c===null)return;
 if(str_contains($c,$flag)){$notes[]='YA INSTALADO: '.$file;return;}
 if(substr_count($c,$anchor)!==1){$err[]='INCOMPATIBLE: cambió el punto esperado de '.$file.' ('.substr($flag,0,24).'). No sobrescribir.';return;}
 $changes[$file]=str_replace($anchor,$anchor.$addition,$c);$notes[]='ACTUALIZAR: '.$file;
};
$replaceOnce=function(string $file,string $old,string $new,string $flag)use($read,&$changes,&$err,&$notes):void{
 $c=$read($file);if($c===null)return;
 if(str_contains($c,$flag)){$notes[]='YA INSTALADO: '.$file;return;}
 if(substr_count($c,$old)!==1){$err[]='INCOMPATIBLE: punto de cambio no encontrado en '.$file.' ('.substr($flag,0,28).').';return;}
 $changes[$file]=str_replace($old,$new,$c);$notes[]='ACTUALIZAR: '.$file;
};
$anchor='<a class="btn secondary" href="erp_compras_alerta_resolver.php?caso=<?=$id?>&amp;comprobar=1">Comprobar de nuevo</a>';
$extra=<<<'HTML'

<?php /* GYD_MARGEN_V3_DETALLE */ if($caso['tipo']==='MARGEN_BAJO' && !empty($estadoActual) && $estadoActual['costo']!==null):
    $cm=(float)$estadoActual['costo'];$minp=ceil(($cm/.85)*100-1e-8)/100;
    for($j=0;$j<4 && $minp>0 && 100*($minp-$cm)/$minp<15-1e-7;$j++)$minp+=.01;
?>
<p class="warning"><strong>Para quitar el aviso de margen bajo en <?=$h($caso['sucursal_nombre']?:'precio base')?>:</strong>
con el último costo facturado elegible <?=$h($money($cm))?> se requiere un precio público de al menos <strong><?=$h($money($minp))?></strong>
(margen real del 15% sobre venta). El precio sugerido de «MARK UP» en Cambio de Precios es una GANANCIA SOBRE COSTO, no el margen real sobre venta.
<?php if($estadoActual['margen']!==null && $estadoActual['margen']>=15):?>
<strong>El margen actual ya cumple:</strong> pulsa «Comprobar de nuevo» para sincronizar el estado. Si continúa pendiente, ejecuta el diagnóstico por terminal.
<?php endif; ?></p>
<?php endif; ?>
HTML;
$insertAfter('public/erp_compras_alerta_resolver.php',$anchor,$extra,'GYD_MARGEN_V3_DETALLE');
$old='<p class="sub">El margen sugerido usa el precio vigente frente al costo anterior, si ambos existen. Puedes cambiarlo. Los precios no se aplican al guardar una factura: únicamente al confirmar aquí.</p>';
$new='<p class="sub">El porcentaje sugerido viene del precio vigente y costo anterior; <strong>MARK UP calcula ganancia sobre COSTO, no margen real sobre VENTA.</strong> Para evitar la alerta de margen bajo, verifica que el precio nuevo alcance un margen real del 15% respecto al ultimo costo facturado. Los cambios solo se aplican al confirmar.</p><!-- GYD_MARGEN_V3_EDITOR -->';
$replaceOnce('public/erp_cambio_precios.php',$old,$new,'GYD_MARGEN_V3_EDITOR');
// Añadir explicación matemática por sucursal en el cuadro modal, sin sustituir las funciones actuales.
$priceOld="   const prevLabel=elemento('div');prevLabel.append(elemento('small','Resultado automático: '));const sug=elemento('b','—');prevLabel.append(sug);";
$priceNew=<<<'JS'
   const prevLabel=elemento('div');prevLabel.append(elemento('small','Resultado automático: '));const sug=elemento('b','—');prevLabel.append(sug);
   // GYD_MARGEN_V3_MARGEN_REAL: margen sobre venta, precio minimo y sucursal (sin cambiar el precio por sí solo).
   const margenReal=elemento('div',undefined,'sub');prevLabel.append(margenReal);
JS;
$replaceOnce('public/erp_cambio_precios.php',$priceOld,$priceNew,'GYD_MARGEN_V3_MARGEN_REAL');
$oldUpd="   function update(){const m=Number(margen.value),c=f(item.costo_nuevo);const t=calc==='MARK_DOWN'?(m>=100?NaN:c/(1-m/100)):c*(1+m/100);sug.textContent=Number.isFinite(t)&&t>0?moneda(t):'—';if(metodo==='AUTOMATICO')nuevo.value=Number.isFinite(t)&&t>0?t.toFixed(2):'';}";
$newUpd=<<<'JS'
   function update(){const m=Number(margen.value),c=f(item.costo_nuevo);const t=calc==='MARK_DOWN'?(m>=100?NaN:c/(1-m/100)):c*(1+m/100);sug.textContent=Number.isFinite(t)&&t>0?moneda(t):'—';if(metodo==='AUTOMATICO')nuevo.value=Number.isFinite(t)&&t>0?t.toFixed(2):'';
      // GYD_MARGEN_V3_CALCULO: redondeo conservador hacia arriba, no modifica inputs.
      const elegido=Number(nuevo.value);const real=c>0&&elegido>0?100*(elegido-c)/elegido:null;
      const minimo=c>0?Math.ceil((c/.85)*100-1e-8)/100:null;
      margenReal.textContent=(real===null?'Margen real: sin datos':'Margen real sobre venta: '+real.toFixed(2)+'%')+
        (minimo===null?'':' · Precio minimo para 15% real: '+moneda(minimo))+
        (real!==null&&real<15?' · Este precio MANTIENE la alerta.':'');
   }
JS;
$replaceOnce('public/erp_cambio_precios.php',$oldUpd,$newUpd,'GYD_MARGEN_V3_CALCULO');
$oldEvents="   margen.addEventListener('input',update);nuevo.readOnly=metodo==='AUTOMATICO';update();wrap.append(sel,name,exp,pctLabel,newLabel,prevLabel);dest.append(wrap);";
$newEvents="   margen.addEventListener('input',update);nuevo.addEventListener('input',update);/* GYD_MARGEN_V3_INPUT */nuevo.readOnly=metodo==='AUTOMATICO';update();wrap.append(sel,name,exp,pctLabel,newLabel,prevLabel);dest.append(wrap);";
$replaceOnce('public/erp_cambio_precios.php',$oldEvents,$newEvents,'GYD_MARGEN_V3_INPUT');
foreach(['tools/diagnosticar_alertas_margen_v3.php'=>'tools/diagnosticar_alertas_margen_v3.php','tests/probar_diagnostico_margen_v3.php'=>'tests/probar_diagnostico_margen_v3.php'] as $dst=>$from){
 $file=$src.'/'.$from;$body=is_file($file)?file_get_contents($file):false;
 if($body===false){$err[]='Fuente ausente '.$from;continue;}
 $dest=$root.'/'.$dst;
 if(is_file($dest)){if(file_get_contents($dest)!==$body)$err[]='INCOMPATIBLE: '.$dst.' ya existe con otra versión.';else $notes[]='YA INSTALADO: '.$dst;}
 else {$changes[$dst]=$body;$notes[]='CREAR: '.$dst;}
}
foreach($changes as $rel=>$body){if(!str_ends_with($rel,'.php'))continue;$tmp=tempnam(sys_get_temp_dir(),'gdmargin_');file_put_contents($tmp,$body);$s=1;$log=[];exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$log,$s);unlink($tmp);if($s!==0)$err[]='Error PHP '.$rel.': '.implode(' ',$log);}
foreach($notes as $n)echo $n."\n";
if($err){foreach($err as $e)fwrite(STDERR,$e."\n");fwrite(STDERR,"INCOMPATIBLE. No se ha modificado nada.\n");exit(3);}
echo 'COMPATIBLE: '.count($changes)." archivos por crear/actualizar. No modifica la BD ni existencias.\n";
if($mode==='--check')exit(0);
if(!$changes){echo "YA INSTALADO.\n";exit(0);}
$backup=$root.'/tools/backups_gyd_margen_v3_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backup,0700,true))throw new RuntimeException('No se pudo crear carpeta de respaldo.');
foreach($changes as $rel=>$body){$file=$root.'/'.$rel;if(!is_file($file))continue;$dst=$backup.'/'.$rel;if(!is_dir(dirname($dst)))mkdir(dirname($dst),0700,true);if(!copy($file,$dst))throw new RuntimeException('No se pudo respaldar '.$rel);}
$written=[];
try{foreach($changes as $rel=>$body){$file=$root.'/'.$rel;if(!is_dir(dirname($file)))mkdir(dirname($file),0755,true);$temp=$file.'.v3_'.getmypid();if(file_put_contents($temp,$body,LOCK_EX)===false||!rename($temp,$file))throw new RuntimeException('No se pudo escribir '.$rel);$written[]=$rel;}}
catch(Throwable $e){foreach(array_reverse($written) as $rel){$old=$backup.'/'.$rel;$f=$root.'/'.$rel;if(is_file($old))copy($old,$f);else @unlink($f);}fwrite(STDERR,'Rollback completo: '.$e->getMessage()."\n");exit(4);}
echo "INSTALADO. Respaldos: $backup\nPrueba: php tests/probar_diagnostico_margen_v3.php\n";
