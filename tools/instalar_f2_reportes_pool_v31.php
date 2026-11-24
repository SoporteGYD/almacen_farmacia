<?php
declare(strict_types=1);
/** F2 / G&D: actualiza SOLO DOS REPORTES, preservando precios por sucursal. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit('Solo CLI.'); }
$argv=$argv??[];
$acciones=array_values(array_intersect($argv,['--check','--apply','--verify']));
if(count($acciones)!==1){fwrite(STDERR,"Uso: php tools/instalar_f2_reportes_pool_v31.php --check | --apply | --verify\n");exit(2);}
$accion=$acciones[0];
$root=realpath(dirname(__DIR__));
if($root===false){fwrite(STDERR,"No se encuentra el proyecto.\n");exit(2);}
$helper=$root.'/app/helpers/pos_inteligente.php';
if(!is_file($helper) || !str_contains((string)file_get_contents($helper),'function pi_tt_stock_sid(')) {
    fwrite(STDERR,"INCOMPATIBLE: requiere F1 inventario compartido V29; no se modifico nada.\n");exit(2);
}
$manifest=[
  'public/erp_catalogo_finanzas.php'=>[
    'antes'=>'895611bb3f3dd6c10d3d7740e198abfeab11627de6f2c60f4d3085287444a2f9',
    'despues'=>'dcf3d2abb3d5dd7aa2b8a99c9e69d06be6f387faacf1077c8fe296fc08066ea1',
  ],
  'public/erp_oc_inventarios.php'=>[
    'antes'=>'00cb57808dd5fa6e31b8b4b2a8709f83239d5ae7235c14a128f03093a96e0ab2',
    'despues'=>'8370909b508b0d37ec6a6a35b4ce390a446dacecf2e26027c5796afe75e7b45d',
  ],
];
$payload=__DIR__.'/f2_reportes_v31_payload/';
$pendientes=[];
foreach($manifest as $rel=>$h){
  $dest=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
  $fuente=$payload.str_replace('/',DIRECTORY_SEPARATOR,$rel);
  if(!is_file($dest)||!is_file($fuente)||hash_file('sha256',$fuente)!==$h['despues']) {
     fwrite(STDERR,"INCOMPATIBLE: falta archivo o el paquete esta alterado: $rel\n");exit(2);
  }
  $actual=hash_file('sha256',$dest);
  if($actual===$h['despues'])continue;
  if($actual!==$h['antes']){
    fwrite(STDERR,"INCOMPATIBLE: el archivo $rel fue modificado y no corresponde con el original. NO sobrescribiremos tus cambios.\n");exit(2);
  }
  $pendientes[$rel]=['dest'=>$dest,'fuente'=>$fuente];
}
if($accion==='--verify'){
  if($pendientes){fwrite(STDERR,"PENDIENTE DE INSTALAR: ".implode(', ',array_keys($pendientes))."\n");exit(1);}
  echo "VERIFICADO: ambos reportes coinciden con la correccion; MySQL sin modificaciones.\n";exit(0);
}
if(!$pendientes){echo "YA INSTALADO: ambos reportes actualizados. Base de datos sin cambios.\n";exit(0);}
if($accion==='--check'){
  echo "COMPATIBLE: se reconocio la version actual de tu sistema. No se modifico nada.\n";
  foreach($pendientes as $rel=>$_)echo "MODIFICAR: $rel\n";
  echo "Solo corrige reportes; no cambia stock, ventas, precios ni tablas.\n";exit(0);
}
// El respaldo se guarda fuera de htdocs para no hacerlo accesible desde la web.
$htdocs=dirname($root);
$resguardos=dirname($htdocs).DIRECTORY_SEPARATOR.'gyd_resguardos_codigo';
$carpeta=$resguardos.DIRECTORY_SEPARATOR.'f2_reportes_pool_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!@mkdir($carpeta,0700,true) && !is_dir($carpeta)){
  fwrite(STDERR,"BLOQUEADO: no se pudo crear el respaldo fuera de htdocs: $carpeta\n");exit(3);
}
$respaldos=[];
foreach($pendientes as $rel=>$op){
  $respaldo=$carpeta.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
  if(!is_dir(dirname($respaldo)) && !mkdir(dirname($respaldo),0700,true)){
     fwrite(STDERR,"BLOQUEADO: no se puede crear subcarpeta de respaldo; sin cambios.\n");exit(3);
  }
  if(!copy($op['dest'],$respaldo) || hash_file('sha256',$respaldo)!==$manifest[$rel]['antes']){
     fwrite(STDERR,"BLOQUEADO: fallo el respaldo de $rel; sin cambios.\n");exit(3);
  }
  $respaldos[$rel]=$respaldo;
}
$aplicados=[];
foreach($pendientes as $rel=>$op){
  if(!copy($op['fuente'],$op['dest'])||hash_file('sha256',$op['dest'])!==$manifest[$rel]['despues']){
      foreach($aplicados as $r)copy($respaldos[$r],$pendientes[$r]['dest']);
      copy($respaldos[$rel],$op['dest']);
      fwrite(STDERR,"ERROR: se intento restaurar el respaldo; revisa $carpeta.\n");exit(4);
  }
  $aplicados[]=$rel;
}
echo "INSTALACION CORRECTA. Respaldo del codigo en: $carpeta\n";
echo "No se realizaron operaciones sobre MySQL.\n";
