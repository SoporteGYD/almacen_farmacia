<?php
declare(strict_types=1);
/** Instala enlace en Verificación sin sustituir lógica de facturas ni inventarios. */
$mode=$argv[1]??'--help';
$target=dirname(__DIR__).'/public/erp_facturas_verificacion.php';
$needle='<section class="card"><h2>Resumen por cantidades</h2>';
$marker='<!-- GYD_CAMBIO_PRECIOS_V1 -->';
$addition='<!-- GYD_CAMBIO_PRECIOS_V1 -->' . "\n" .
  '<?php if(ef_puede_capturar($usr) && $aceptadas>0): ?>' . "\n" .
  '<section class="card"><h2>Revisar costo y precios de venta</h2><p class="muted">Si el proveedor modificó un costo, Compras puede revisar el precio antes de decidir si cambia el de las sucursales. No altera inventario.</p><a class="btn alt" href="erp_cambio_precios.php?factura_id=<?= (int)$fid ?>&amp;abrir=1">💲 Abrir cambio de precio</a></section>' . "\n" .
  '<?php endif; ?>' . "\n";
if(!in_array($mode,['--check','--apply'],true)){echo "Uso: php tools/instalar_enlace_cambio_precios.php --check | --apply\n";exit(1);}
if(!is_file($target)){fwrite(STDERR,"No se encuentra erp_facturas_verificacion.php; instala primero el módulo de Entradas Generales.\n");exit(2);}
$s=file_get_contents($target);
if($s===false){fwrite(STDERR,"No se pudo leer el archivo.\n");exit(2);}
if(strpos($s,$marker)!==false){echo "Ya está instalado. No se realizó ningún cambio.\n";exit(0);}
if(substr_count($s,$needle)!==1||strpos($s,'$aceptadas')===false||strpos($s,'ef_puede_capturar')===false){fwrite(STDERR,"Versión distinta de la prevista. No se modificó nada; integra el enlace manualmente siguiendo LEEME.\n");exit(3);}
if($mode==='--check'){echo "OK: se puede insertar el acceso sin cambiar la lógica existente. Ejecuta --apply tras respaldar.\n";exit(0);}
$backup=$target.'.backup_precio_'.date('Ymd_His');
if(!copy($target,$backup)){fwrite(STDERR,"No se pudo crear el respaldo. No se aplicó el parche.\n");exit(4);}
$r=str_replace($needle,$addition.$needle,$s);
if(file_put_contents($target,$r,LOCK_EX)!==strlen($r)){
    copy($backup,$target);fwrite(STDERR,"Error escribiendo: se restauró el respaldo.\n");exit(5);
}
echo "Acceso agregado. Respaldo: $backup\n";
