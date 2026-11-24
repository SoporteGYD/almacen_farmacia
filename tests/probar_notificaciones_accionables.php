<?php
declare(strict_types=1);
/** Tests estáticos sin BD y sin efectos secundarios; requieren que se haya aplicado el parche. */
$root=dirname(__DIR__);
$files=['public/erp_compras_alerta_resolver.php','public/erp_notificaciones.php','app/helpers/erp_compras_alertas_seguimiento.php','app/helpers/erp_compras_modulos.php','public/erp_cambio_precios.php'];
$bad=0;
foreach($files as $file){$path=$root.'/'.$file;if(!is_file($path)){echo "FALLO: falta $file\n";$bad++;continue;}
 $out=[];$code=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1',$out,$code);
 echo ($code===0?'OK':'FALLO').': sintaxis '.$file."\n";if($code!==0)$bad++;
}
if($bad===0){$f=file_get_contents($root.'/public/erp_compras_alerta_resolver.php');foreach(['gdca_authorized($user)','gdca_edit_case(','hash_equals(','DESCARTADA','precioActual','puedePrecio'] as $needle){$ok=str_contains($f,$needle);echo ($ok?'OK':'FALLO').': control '. $needle."\n";if(!$ok)$bad++;}}
exit($bad?1:0);
