<?php
/** Instalador conservador: solo inserta complementos aislados en anclas conocidas. */
declare(strict_types=1);
$modo=$argv[1]??'--help';
if(!in_array($modo,['--check','--apply'],true)){
    echo "Uso: php tools/instalar_modal_costo_automatico.php --check | --apply\n";exit(1);
}
$raiz=dirname(__DIR__);
$archivos=[
 ['destino'=>$raiz.'/public/erp_facturas_verificacion.php','parche'=>__DIR__.'/patches/en_verificacion.phpinc',
  'ancla'=>'<section class="card"><h2>Resumen por cantidades</h2>','marca'=>'<!-- GYD_CP_AUTO_COSTO_V2 -->','obligatorio'=>true],
 ['destino'=>$raiz.'/public/erp_facturas.php','parche'=>__DIR__.'/patches/en_facturas.phpinc',
  'ancla'=>'<section class="card"><h2>Registrar factura</h2>','marca'=>'<!-- GYD_CP_PENDIENTES_COMPRAS_V2 -->','obligatorio'=>false],
];
$pendientes=[];
foreach($archivos as $x){
 $f=$x['destino'];
 if(!is_file($f)){
  if($x['obligatorio']){fwrite(STDERR,"FALTA: $f. Instala el módulo de facturas primero.\n");exit(2);}
  echo "Aviso: no existe $f; se omitirá aviso en pantalla principal.\n";continue;
 }
 $original=file_get_contents($f);
 if($original===false){fwrite(STDERR,"No se pudo leer $f\n");exit(2);}
 if(str_contains($original,$x['marca'])){echo "YA INSTALADO: ".basename($f)."\n";continue;}
 if(substr_count($original,$x['ancla'])!==1){
  if($x['obligatorio']){fwrite(STDERR,"VERSION DISTINTA: no se encontró ancla exacta de ".basename($f).". Sin modificar archivos.\n");exit(3);}
  echo "Aviso: versión distinta en ".basename($f).". Se omitirá el aviso opcional en listado.\n";continue;
 }
 $parche=file_get_contents($x['parche']);
 if($parche===false||!str_contains($parche,$x['marca'])){fwrite(STDERR,"Archivo del parche inválido.\n");exit(4);}
 $nuevo=str_replace($x['ancla'],$parche."\n".$x['ancla'],$original);
 // Verificar nuevo código antes de guardar (si PHP CLI existe).
 $tmp=tempnam(sys_get_temp_dir(),'gyd_php_');
 file_put_contents($tmp,$nuevo);
 $php=PHP_BINARY;
 $out=[];$ret=0;
 exec(escapeshellarg($php).' -l '.escapeshellarg($tmp).' 2>&1',$out,$ret);
 @unlink($tmp);
 if($ret!==0){fwrite(STDERR,"Sintaxis inválida al preparar ".basename($f).": ".implode(' ', $out)."\n");exit(5);}
 $pendientes[]=['ruta'=>$f,'datos'=>$nuevo];
 echo "OK: compatible ".basename($f)."\n";
}
if($modo==='--check'){echo "Revisión terminada. Pendientes de aplicar: ".count($pendientes).".\n";exit(0);}
$backups=[];
foreach($pendientes as $x){
 $f=$x['ruta'];$bak=$f.'.backup_costo_auto_'.date('Ymd_His');
 if(!copy($f,$bak)){fwrite(STDERR,"No se pudo respaldar $f. Nada más debe aplicarse.\n");exit(6);}
 $backups[$f]=$bak;
 $len=file_put_contents($f,$x['datos'],LOCK_EX);
 if($len===false||$len!==strlen($x['datos'])){
   foreach($backups as $dst=>$bk)copy($bk,$dst);
   fwrite(STDERR,"Error al guardar. Se restauraron los respaldos.\n");exit(7);
 }
 echo "ACTUALIZADO: ".basename($f)." | Respaldo: $bak\n";
}
echo "Instalación terminada. Sin cambios en inventario ni facturas.\n";
