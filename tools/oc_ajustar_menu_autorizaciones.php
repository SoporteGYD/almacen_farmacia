<?php
declare(strict_types=1);
/**
 * Opcional: ajustar solamente la visibilidad del enlace Autorizaciones de Compras.
 * No reemplaza el menú personalizado del ERP.
 * Ejecute desde CLI: php tools/oc_ajustar_menu_autorizaciones.php --check / --apply
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo por linea de comandos.');}
$modo=$argv[1]??'';
if(!in_array($modo,['--check','--apply'],true)){fwrite(STDERR,"Uso: php tools/oc_ajustar_menu_autorizaciones.php --check|--apply\n");exit(2);}
$base=dirname(__DIR__);
$config=$base.'/app/config/erp_oc_politica.php';
$menu=$base.'/app/views/layouts/header.php';
if(!is_file($config)||!is_file($menu)){fwrite(STDERR,"No existe la configuracion o el menu. No se modifico nada.\n");exit(2);}
require $config;
$src=file_get_contents($menu);
if($src===false){fwrite(STDERR,"No se pudo leer el menu.\n");exit(2);}
$old="$"."menuAgregar('🧾 Compras y proveedores', 'Autorizaciones de Compra', 'erp_oc_autorizaciones.php', $".'menuEsAdmin || $menuEsCompras);';
$new="$"."menuAgregar('🧾 Compras y proveedores', 'Autorizaciones de Compra', 'erp_oc_autorizaciones.php', ($".'menuEsAdmin || $menuEsCompras) && (function_exists(\'gd_oc_requiere_autorizacion\') ? gd_oc_requiere_autorizacion() : true));';
$bootstrap="require_once __DIR__ . '/../../config/erp_oc_politica.php';\n";
// Ajuste mínimo en la cabecera para que el menú se sincronice al cambiar la configuración.
$includeAnchor="require_once __DIR__ . '/../../helpers/auth.php';";
if(substr_count($src,$old)===1){$src2=str_replace($old,$new,$src);}elseif(substr_count($src,$new)===1){$src2=$src;}else{fwrite(STDERR,"El menu es diferente al esperado; ajustalo manualmente. No se modifico nada.\n");exit(2);}
if(strpos($src2,$bootstrap)===false){
 if(substr_count($src2,$includeAnchor)!==1){fwrite(STDERR,"No se encontro ancla para incluir la politica. No se modifico nada.\n");exit(2);}
 $src2=str_replace($includeAnchor,$includeAnchor."\n".$bootstrap,$src2);
}
if($modo==='--check'){echo 'Menu encontrado. ', $src2===$src?'No requiere cambios.':'Listo para adaptar sin reemplazar el menu.'," Politica: ",(gd_oc_requiere_autorizacion()?'ACTIVA':'PAUSADA'),"\n";exit(0);}
if($src2===$src){echo "El menu ya esta adaptado.\n";exit(0);}
$backup=$menu.'.backup_oc_'.date('Ymd_His');
if(!copy($menu,$backup)){fwrite(STDERR,"No fue posible respaldar el menu.\n");exit(2);}
if(file_put_contents($menu,$src2,LOCK_EX)===false){copy($backup,$menu);fwrite(STDERR,"Error de escritura. Se intento restaurar el respaldo.\n");exit(2);}
echo "Menu actualizado. Respaldo: $backup\n";
