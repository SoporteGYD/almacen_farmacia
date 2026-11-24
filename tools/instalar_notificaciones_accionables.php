<?php
declare(strict_types=1);
/** Parche de navegación y gestión: NO modifica tablas de datos ni ejecuta actualizaciones de stock/precio. */
$root=dirname(__DIR__);$source=__DIR__.'/fuentes';
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
 fwrite(STDERR,"Uso: php tools/instalar_notificaciones_accionables.php --check | --apply\n");exit(2);
}
$pending=[];$errors=[];$notes=[];
$patch=function(string $rel,string $before,string $after,string $label)use($root,&$pending,&$notes,&$errors):void{
 $path=$root.'/'.$rel;
 if(!is_file($path)){$errors[]='No existe '.$rel.'; instala primero los módulos anteriores.';return;}
 $body=$pending[$rel]??file_get_contents($path);
 if($body===false){$errors[]='No se pudo leer '.$rel;return;}
 if(str_contains($body,$after)){$notes[]='YA INSTALADO: '.$label;return;}
 if(substr_count($body,$before)!==1){$errors[]='INCOMPATIBLE: '.$rel.' fue modificado o falta el módulo requerido ('.$label.'). No se sobreescribirá.';return;}
 $pending[$rel]=str_replace($before,$after,$body);$notes[]='ACTUALIZAR: '.$label;
};
$patch('app/helpers/erp_compras_alertas_seguimiento.php',
 "'url'=>'erp_compras_alertas_seguimiento.php?caso='.\$id",
 "'url'=>'erp_compras_alerta_resolver.php?caso='.\$id",
 'ruta de alerta financiera al producto y la resolución');
$patch('public/erp_notificaciones.php',
 '<a class="btn" href="<?=gdnt_escape($item[\'url\'])?>">Abrir trámite</a>',
 '<a class="btn" href="<?=gdnt_escape($item[\'url\'])?>"><?= ($item[\'tipo\']??\'\')===\'ALERTA_COMPRA\'?\'Revisar / corregir\':\'Abrir trámite\' ?></a>',
 'nombre contextual del botón');
$patch('app/helpers/erp_compras_modulos.php',
 "        'erp_compras_alertas_seguimiento.php'=>true,",
 "        'erp_compras_alertas_seguimiento.php'=>true,\n        // GYD_ALERTAS_ACCIONABLES_RUTA_V1\n        'erp_compras_alerta_resolver.php'=>true,",
 'autorizar la nueva ruta exclusivamente a COMPRAS');
// El editor ya valida por sí mismo que COMPRAS/Dirección estén autorizados y que la factura tenga partidas aceptadas.
$modulos=$root.'/app/helpers/erp_compras_modulos.php';
if(is_file($modulos)){
 $c=$pending['app/helpers/erp_compras_modulos.php']??file_get_contents($modulos);
 if(!str_contains((string)$c,"'erp_cambio_precios.php'=>true")){
   $patch('app/helpers/erp_compras_modulos.php',
      "        'erp_compras_alerta_resolver.php'=>true,",
      "        'erp_compras_alerta_resolver.php'=>true,\n        'erp_cambio_precios.php'=>true,",
      'ruta de edición de precios con verificación documental');
 }
}
$patch('public/erp_cambio_precios.php',
 'const DATOS=<?=$json?:\'[]\'?>;',
 "const PRODUCTO_ENFOCADO=<?=max(0,(int)(\$_GET['producto_id']??0))?>;\nconst DATOS=<?=\$json?:'[]'?>;",
 'recibir producto seleccionado desde alerta');
$patch('public/erp_cambio_precios.php',
 '</script></body></html>',
 "// GYD_ALERTAS_ACCIONABLES_FOCUS_V1\nif(PRODUCTO_ENFOCADO>0){const enfocado=DATOS.find(p=>Number(p.producto_id)===PRODUCTO_ENFOCADO);if(enfocado)abrir(enfocado.detalle_id);}\n</script></body></html>",
 'enfocar exclusivamente el producto seleccionado en Cambio de Precios');
foreach(['public/erp_compras_alerta_resolver.php'=>'public/erp_compras_alerta_resolver.php','tests/probar_notificaciones_accionables.php'=>'probar_notificaciones_accionables.php'] as $dest=>$src){
 $sourceFile=$source.'/'.$src;$content=is_file($sourceFile)?file_get_contents($sourceFile):false;
 if($content===false){$errors[]='No existe archivo fuente '.$src;continue;}
 $old=$root.'/'.$dest;
 if(is_file($old)){
    $existing=file_get_contents($old);
    if($existing===$content){$notes[]='YA INSTALADO: '.$dest;continue;}
    $errors[]='INCOMPATIBLE: '.$dest.' ya existe con cambios ajenos al parche. No se sobrescribirá.';continue;
 }
 $pending[$dest]=$content;$notes[]='CREAR: '.$dest;
}
foreach($pending as $rel=>$body){
 if(!str_ends_with($rel,'.php'))continue;
 $tmp=tempnam(sys_get_temp_dir(),'gyd_acc_');
 if($tmp===false){$errors[]='No se pudo crear un temporal para '.$rel;continue;}
 file_put_contents($tmp,$body);$out=[];$exit=0;
 exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$exit);@unlink($tmp);
 if($exit!==0)$errors[]='Error de sintaxis en '.$rel.': '.implode(' ', $out);
}
foreach($notes as $n)echo $n."\n";
if($errors){foreach($errors as $e)fwrite(STDERR,$e."\n");echo "INCOMPATIBLE; no se modificó ningún archivo.\n";exit(3);}
echo "COMPATIBLE: ".count($pending)." archivo(s) para agregar o actualizar. Sin cambios en MariaDB.\n";
if($mode==='--check')exit(0);
if(!$pending){echo "YA INSTALADO.\n";exit(0);}
$backup=$root.'/tools/backups_gyd_alertas_accionables_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!@mkdir($backup,0700,true)){fwrite(STDERR,"No se pudo crear directorio de respaldos.\n");exit(4);}
foreach($pending as $rel=>$content){
 $path=$root.'/'.$rel;if(!is_file($path))continue;
 $bak=$backup.'/'.$rel;if(!is_dir(dirname($bak)))@mkdir(dirname($bak),0700,true);
 if(!@copy($path,$bak)){fwrite(STDERR,"Error al respaldar $rel; sin aplicar cambios.\n");exit(4);}
}
$written=[];
try{
 foreach($pending as $rel=>$content){
  $path=$root.'/'.$rel;if(!is_dir(dirname($path))&&!@mkdir(dirname($path),0755,true))throw new RuntimeException('Carpeta no disponible: '.$rel);
  $tmp=$path.'.gyd_alertas_'.getmypid();
  if(file_put_contents($tmp,$content,LOCK_EX)===false)throw new RuntimeException('No se pudo escribir: '.$rel);
  if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('No se pudo actualizar: '.$rel);}
  $written[]=$rel;
 }
}catch(Throwable $e){
 foreach(array_reverse($written) as $rel){$bak=$backup.'/'.$rel;$dest=$root.'/'.$rel;if(is_file($bak))copy($bak,$dest);else @unlink($dest);}
 fwrite(STDERR,"INSTALACION CANCELADA Y ARCHIVOS REVERTIDOS: ".$e->getMessage()."\n");exit(5);
}
echo "INSTALADO: ".count($written)." archivo(s). Respaldos: $backup\n";
echo "Pruebas: php tests/probar_notificaciones_accionables.php\n";
exit(0);
