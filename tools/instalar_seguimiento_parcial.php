<?php
declare(strict_types=1);
/** Instalador de inserciones puntuales, con respaldo y comprobación de sintaxis. */
if(PHP_SAPI!=='cli')exit(1);
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply'],true)){
    fwrite(STDERR,"Uso: php tools/instalar_seguimiento_parcial.php --check|--apply\n");exit(2);
}
$root=dirname(__DIR__);
$helper=$root.'/app/helpers/erp_oc_seguimiento.php';
if(!is_file($helper)) {fwrite(STDERR,"Falta el helper. Copia las carpetas app y tools.\n");exit(2);}
function changeOnly(string $s,string $old,string $new,string $label):string {
  $n=substr_count($s,$old);
  if($n!==1)throw new RuntimeException("Incompatible ($label): patrón esperado 1 vez, encontrado $n. No reemplazar manualmente.");
  return str_replace($old,$new,$s);
}
try {
  $changed=[];$targets=[];
  // 1) Saldo neto de verificación: resta SOLO eventos de conciliación auditados.
  $p=$root.'/app/helpers/erp_facturas.php';
  if(!is_file($p))throw new RuntimeException('Falta app/helpers/erp_facturas.php');
  $s=file_get_contents($p);
  if(!str_contains($s,'// GYD_OC_NETO_PARCIAL_V2')){
    $s=changeOnly($s,"<?php\ndeclare(strict_types=1);","<?php\ndeclare(strict_types=1);\nrequire_once __DIR__.'/erp_oc_seguimiento.php'; // GYD_OC_NETO_PARCIAL_V2",'require helper facturas');
    $a='function ef_resumen_detalles(PDO $db,int $id): array {';
    $b='function ef_actualizar_estado(PDO $db,int $facturaId): void {';
    if(substr_count($s,$a)!==1||substr_count($s,$b)!==1)throw new RuntimeException('La función de resumen de facturas cambió.');
    $ini=strpos($s,$a);$fin=strpos($s,$b,$ini);
    if($fin===false||$fin<=$ini)throw new RuntimeException('No se pudo aislar la función de resumen.');
    $seccion=substr($s,$ini,$fin-$ini);
    $seccion=changeOnly($seccion,'$st->execute([$id]);return $st->fetchAll(PDO::FETCH_ASSOC);',
     '$st->execute([$id]);return erp_oc_resumen_ajustado($db,$id,$st->fetchAll(PDO::FETCH_ASSOC));',
     'resumen factura');
    $s=substr_replace($s,$seccion,$ini,$fin-$ini);
    $changed[$p]=$s;
  }
  // 2) Adapta el guard ya instalado en Verificación al neto auditado.
  $p=$root.'/app/helpers/erp_facturas_oc_documental.php';
  if(!is_file($p))throw new RuntimeException('Falta helper documental; instala primero GYD_ERP_FACTURAS_PARCIALES_SIN_BLOQUEO.');
  $s=file_get_contents($p);
  if(!str_contains($s,'// GYD_OC_ACEPTACION_NETA_V2')){
    $needle='function ef_oc_proteger_aceptacion(PDO $db,int $ordenId,array $detalleFactura,array $porAceptar): void {';
    if(substr_count($s,$needle)!==1)throw new RuntimeException('Versión de aceptación documental diferente.');
    $pos=strpos($s,$needle);
    // La función anterior es la última de este helper. No modificar el resto.
    $start=substr($s,0,$pos);
    $old=substr($s,$pos);
    if(!str_contains($old,"rd.tipo='ACEPTADA'") || !str_contains($old,"Registra primero la recepción"))
        throw new RuntimeException('La función de control físico difiere; se detiene.');
    $s=$start.<<<'FUNC'
function ef_oc_proteger_aceptacion(PDO $db,int $ordenId,array $detalleFactura,array $porAceptar): void {
    // GYD_OC_ACEPTACION_NETA_V2: considera las conciliaciones auditadas.
    require_once __DIR__.'/erp_oc_seguimiento.php';
    erp_oc_proteger_aceptacion_neta($db,$ordenId,$detalleFactura,$porAceptar);
}
FUNC;
    $s.="\n";
    $changed[$p]=$s;
  }
  // 3) Guardar stock efectivo contra cantidad facturada y físicamente recibida.
  $p=$root.'/public/erp_facturas_aplicar.php';
  if(!is_file($p))throw new RuntimeException('Falta la página de aplicación de facturas al stock.');
  $s=file_get_contents($p);
  if(!str_contains($s,'// GYD_OC_STOCK_NETO_V2')){
    $anchor="require_once __DIR__.'/../app/helpers/erp_facturas.php';";
    $s=changeOnly($s,$anchor,$anchor."\nrequire_once __DIR__.'/../app/helpers/erp_oc_seguimiento.php'; // GYD_OC_STOCK_NETO_V2",'helper aplicación');
    $anchor='SELECT id,sucursal,proveedor_id,folio_interno FROM erp_facturas WHERE id=? FOR UPDATE';
    if(str_contains($s,$anchor))$s=changeOnly($s,$anchor,'SELECT id,sucursal,proveedor_id,folio_interno,orden_id FROM erp_facturas WHERE id=? FOR UPDATE','orden factura');
    elseif(!str_contains($s,'SELECT id,sucursal,proveedor_id,folio_interno,orden_id FROM erp_facturas WHERE id=? FOR UPDATE'))
       throw new RuntimeException('El SELECT de factura cambió.');
    if(!str_contains($s,'// Bloquea primero la OC: serializa validación física y recepción.')){
      $anchor='// Bloquear la factura primero. Serializa aplicaciones concurrentes de este documento.';
      $n=<<<'PHPLOCK'
// Bloquea primero la OC para serializar con la recepción física y la verificación.
        $lkOrder=$db->prepare('SELECT orden_id FROM erp_facturas WHERE id=?');
        $lkOrder->execute([$fid]);$orderId=(int)$lkOrder->fetchColumn();
        if($orderId>0){
            $lock=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');
            $lock->execute([$orderId]);
        }
        // Bloquear la factura primero. Serializa aplicaciones concurrentes de este documento.
PHPLOCK;
      $s=changeOnly($s,$anchor,$n,'lock OC');
    }
$anchor=<<<'ANCHOR'
$qty=(int)$r['cantidad'];$pid=(int)$r['producto_id'];
ANCHOR;
    $s=changeOnly($s,$anchor,$anchor."\n            erp_oc_proteger_aplicacion_neta(\$db,(int)(\$locked['orden_id']??0),\$fid,\$pid,\$qty);",'validación de aplicación');
    $changed[$p]=$s;
  }
  // 4) Vista amigable del neto y las piezas pendientes; no altera modal de precios.
  $p=$root.'/public/erp_facturas_verificacion.php';
  if(!is_file($p))throw new RuntimeException('Falta la página de verificación.');
  $s=file_get_contents($p);
  if(!str_contains($s,'GYD_OC_PROTEGER_ACEPTACION_V1') || substr_count($s,'ef_oc_proteger_aceptacion($db,')<1)
     throw new RuntimeException('Falta el control de aceptación física V1. Instala primero facturas pendientes; no se permiten nuevas aceptaciones sin protección.');
  if(!str_contains($s,'GYD_OC_VISTA_PARCIAL_V2')){
    $anchor='<section class="card"><h2>Verificar físicamente los productos</h2>';
    $panel=<<<'PANEL'
<?php // GYD_OC_VISTA_PARCIAL_V2 - Sólo lectura; historial histórico intacto.
if ($factura && (int)($factura['orden_id']??0)>0):
    $seguimiento=erp_oc_seguimiento_por_factura($db,$fid);
?>
<section class="card" style="border-left:5px solid #1b6aa2">
<h2>Recepciones parciales vinculadas a esta factura</h2>
<p class="muted">Los datos "aceptadas" y "aplicadas" muestran saldos <b>netos</b> después de ajustes auditados. Las verificaciones históricas y la factura original permanecen intactas.</p>
<div class="tablewrap"><table><thead><tr><th>Producto</th><th>Facturadas</th><th>Recibidas (OC)</th><th>Aceptadas netas</th><th>Aplicadas netas</th><th>Por recibir</th><th>Por verificar</th></tr></thead><tbody>
<?php foreach($seguimiento as $r): ?>
<tr><td><?=ef_e($r['producto'])?></td><td class="num"><?=(int)$r['facturadas']?></td><td class="num"><?=(int)$r['recibidas']?></td><td class="num"><?=(int)$r['aceptadas_netas']?></td><td class="num"><?=(int)$r['aplicadas_netas']?></td><td class="num"><?=(int)$r['por_recibir']?></td><td class="num"><?=(int)$r['por_verificar']?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted">Cuando llegue mercancía faltante, primero regístrala en <b>Recepciones por OC</b>, vuelve aquí para verificar sólo el saldo y, al final, utiliza <b>Aplicar al inventario</b>. No registres una segunda factura por las mismas piezas.</p>
<a class="btn alt" href="erp_recepciones_pendientes.php">Ver órdenes pendientes de recepción</a>
</section>
<?php endif; ?>
PANEL;
    $s=changeOnly($s,$anchor,$panel."\n".$anchor,'panel seguimiento');
    $changed[$p]=$s;
  }
  foreach($changed as $path=>$data){
    $tmp=tempnam(sys_get_temp_dir(),'gyd_');file_put_contents($tmp,$data);$out=[];$rc=0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$out,$rc);
    unlink($tmp);if($rc!==0)throw new RuntimeException('Sintaxis inválida '.basename($path).': '.implode(' ',$out));
  }
  if($mode==='--check'){
    foreach($changed as $path=>$data)echo 'COMPATIBLE: '.str_replace($root.'/','',$path)."\n";
    echo 'Listo: '.count($changed)." archivo(s) por actualizar, ninguno modificado.\n";exit(0);
  }
  $backs=[];$stamp=date('Ymd_His');
  foreach($changed as $path=>$data){
    $backup=$path.'.backup_parciales_'.$stamp;
    if(!copy($path,$backup))throw new RuntimeException('No se pudo respaldar '.basename($path));
    $backs[$path]=$backup;
  }
  foreach($changed as $path=>$data){
    $tmp=$path.'.parciales_tmp';
    if(file_put_contents($tmp,$data,LOCK_EX)===false || !rename($tmp,$path)){
       foreach($backs as $target=>$bk)copy($bk,$target);
       throw new RuntimeException('Error de escritura, se intentó restaurar los respaldos.');
    }
    echo 'ACTUALIZADO: '.str_replace($root.'/','',$path).' | respaldo '.$backs[$path]."\n";
  }
  echo "Instalación completada. No se cambiaron datos ni stock.\n";
}catch(Throwable $e){fwrite(STDERR,'DETENIDO: '.$e->getMessage()."\nNo se han aplicado cambios salvo los ya reportados antes de un error.\n");exit(3);}
