<?php
declare(strict_types=1);
/** ERP G&D / Aplicación controlada de partidas verificadas al inventario operativo.
 * Sólo usar en BD de PRUEBAS, después de respaldo y revisión de existencias.
 * No registra entradas contables nuevas ni resurtidos automáticos.
 */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_facturas.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php'; // GYD_F1_POOL_TT_20261103
require_once __DIR__.'/../app/helpers/gyd_facturas_ficha_pos.php'; // GYD_F2_FACTURA_FICHA_POS
require_once __DIR__.'/../app/helpers/erp_oc_seguimiento.php';
require_once __DIR__.'/../app/helpers/gyd_oc_stock_guard.php'; // GYD_OC_STOCK_NETO_V2
require_once __DIR__.'/../app/helpers/erp_oc_cierre_controlado.php'; // GYD_GCC_REQUIRE_V1
requireLogin();
$usr=currentUser()?:[];
$uid=(int)($usr['id']??0);
if($uid<1 || !in_array(ef_rol($usr),['ADMINISTRADOR','ADMIN','DIRECCION','ENCARGADO','JEFE_ALMACEN'],true)){
    http_response_code(403);exit('No cuentas con permisos para aplicar entradas.');
}
$fid=(int)($_GET['id']??$_POST['factura_id']??0);
if($fid<1){header('Location: erp_facturas_verificacion.php',true,303);exit;}
$error='';$success=$_SESSION['ef_stock_flash']??'';unset($_SESSION['ef_stock_flash']);
$csrf=ef_csrf();$tkKey='ef_stock_token_'.$fid;
$token=ef_token($tkKey);
$factura=null;$partidas=[];$aplicaciones=[];$totalPendiente=0;$totalAplicado=0;
$destinos=[
 'TAPACHULA'=>['tipo'=>'FARMACIA','nombre'=>'Farmacia Tapachula','pos'=>'TAPACHULA','almacen'=>null,'sucursal'=>null],
 'TOSCANA'=>['tipo'=>'FARMACIA','nombre'=>'Farmacia Plaza Toscana','pos'=>'PLAZA TOSCANA','almacen'=>null,'sucursal'=>null],
 'HIDALGO'=>['tipo'=>'ALMACEN','nombre'=>'Almacén Ciudad Hidalgo','pos'=>'CIUDAD HIDALGO','almacen'=>1,'sucursal'=>'CIUDAD HIDALGO'],
 'TUXTLA'=>['tipo'=>'ALMACEN','nombre'=>'Almacén Tuxtla Gutiérrez','pos'=>'TUXTLA GUTIERREZ','almacen'=>3,'sucursal'=>'TUXTLA'],
];
try {
    $db=(new Database())->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $st=$db->prepare('SELECT f.*,p.nombre AS proveedor FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id WHERE f.id=?');
    $st->execute([$fid]);$factura=$st->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$factura || !isset($destinos[$factura['sucursal']])){http_response_code(404);exit('Factura inexistente o destino no reconocido.');}
    if(!ef_puede_verificar($usr,(string)$factura['sucursal'])){http_response_code(403);exit('No estás autorizado para confirmar mercancía en esta sucursal.');}
    $target=$destinos[$factura['sucursal']];
    // La ficha comercial corresponde a la sucursal de la factura, incluso si las piezas entran primero a Bodega.
    $posBranch=$db->prepare('SELECT id FROM pos_sucursales WHERE UPPER(TRIM(nombre))=? AND activo=1');
    $posBranch->execute([(string)$target['pos']]);
    $posPrecioId=(int)$posBranch->fetchColumn();
    if($posPrecioId<1)throw new RuntimeException('No existe la sucursal POS activa para esta factura.');
    $puedeFicha=ef_es_admin($usr);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        ef_verify_csrf((string)($_POST['csrf']??''));
        $got=(string)($_POST['aplicar_token']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$got)||!hash_equals($token,$got))throw new RuntimeException('Formulario expirado o repetido. Vuelve a cargar.');
        if(($_POST['confirmar']??'')!=='1')throw new RuntimeException('Confirma la actualización de inventario.');
        $ids=$_POST['aplicar']??[];$locations=$_POST['ubicacion']??[];
        $fichas=[];
        foreach((array)$ids as $idRaw){
            $id=(int)$idRaw;
            if(isset($_POST['ficha'][$id])){
                $fichas[$id]=[
                    'aplicar'=>true,
                    'precio'=>$_POST['precio_publico'][$id]??'',
                    'precio_original'=>$_POST['precio_original'][$id]??'',
                    'modalidad'=>$_POST['modalidad'][$id]??'',
                    'modalidad_original'=>$_POST['modalidad_original'][$id]??'',
                    'piezas_por_caja'=>$_POST['piezas_por_caja'][$id]??'',
                    'piezas_original'=>$_POST['piezas_original'][$id]??'',
                ];
            }
        }
        // GYD_APLICACION_SIN_TOPE_PARTIDAS_V1
        if(array_key_exists('aplicar_json',$_POST)){
          if(!is_string($_POST['aplicar_json']))throw new RuntimeException('Selección de partidas inválida.');
          $payload=json_decode($_POST['aplicar_json'],true,16,JSON_THROW_ON_ERROR);
          if(!is_array($payload)||!array_is_list($payload))throw new RuntimeException('La selección de partidas no es válida.');
          $ids=[];$locations=[];$fichas=[];
          foreach($payload as $row){
            if(!is_array($row)||!isset($row['id']))throw new RuntimeException('Partida incompleta.');
            $ids[]=$row['id'];$locations[(int)$row['id']]=$row['ubicacion']??'';
            if(!empty($row['ficha'])){
                $fichas[(int)$row['id']]=[
                    'aplicar'=>true,
                    'precio'=>$row['precio']??'',
                    'precio_original'=>$row['precio_original']??'',
                    'modalidad'=>$row['modalidad']??'',
                    'modalidad_original'=>$row['modalidad_original']??'',
                    'piezas_por_caja'=>$row['piezas_por_caja']??'',
                    'piezas_original'=>$row['piezas_original']??'',
                ];
            }
          }
        }
        if(!is_array($ids)||!is_array($locations)||!count($ids))throw new RuntimeException('Selecciona al menos una partida aceptada.');
        $ids=array_map('intval',$ids);
        if(min($ids)<1 || count(array_unique($ids))!==count($ids))throw new RuntimeException('Hay partidas duplicadas o inválidas.');
        if(!$puedeFicha && $fichas)throw new RuntimeException('Solo Administración o Dirección pueden actualizar precios y presentación desde factura.');
        $db->beginTransaction();
        // Bloquea primero la OC para serializar con la recepción física y la verificación.
        $lkOrder=$db->prepare('SELECT orden_id FROM erp_facturas WHERE id=?');
        $lkOrder->execute([$fid]);$orderId=(int)$lkOrder->fetchColumn();
        if($orderId>0){
            $lock=$db->prepare('SELECT id FROM erp_ordenes_compra WHERE id=? FOR UPDATE');
            $lock->execute([$orderId]);
            gcc_exigir_abierta($db,$orderId); // GYD_GCC_APLICAR_FACTURA_V1
        }
        // OCAV_FACTURA_ALMACEN_GUARD_V1: no registrar dos veces las mismas piezas.
        if($orderId>0 && $target['tipo']==='ALMACEN'){
            $chk=$db->prepare("SELECT COUNT(*) FROM erp_oc_entrada_vinculos v JOIN erp_oc_recepciones r ON r.id=v.recepcion_id WHERE r.orden_id=?");
            $chk->execute([$orderId]);
            if((int)$chk->fetchColumn()>0)throw new RuntimeException('Esta OC ya tiene una entrada física de Bodega vinculada. Registrar factura es documental; aplicar otra entrada al Almacén duplicaría stock. Revisa la conciliación.');
        }
        // Bloquear la factura primero. Serializa aplicaciones concurrentes de este documento.
        $st=$db->prepare('SELECT id,sucursal,proveedor_id,folio_interno,orden_id FROM erp_facturas WHERE id=? FOR UPDATE');
        $st->execute([$fid]);$locked=$st->fetch(PDO::FETCH_ASSOC);
        if(!$locked||$locked['sucursal']!==$factura['sucursal'])throw new RuntimeException('Factura no disponible.');
        $read=$db->prepare("SELECT rd.id,rd.cantidad,rd.lote,rd.caducidad,rd.tipo,fd.producto_id,fd.costo_unitario,fd.descripcion_snapshot,
           a.id AS aplicado FROM erp_facturas_revision_detalle rd
           JOIN erp_facturas_revisiones rv ON rv.id=rd.revision_id
           JOIN erp_facturas_detalle fd ON fd.id=rd.factura_detalle_id
           LEFT JOIN erp_facturas_stock_aplicaciones a ON a.revision_detalle_id=rd.id
           WHERE rv.factura_id=? AND rd.id=? FOR UPDATE");
        $getPos=$db->prepare('SELECT id FROM pos_sucursales WHERE UPPER(TRIM(nombre))=? AND activo=1');
        $stockWarehouse=$db->prepare('INSERT INTO producto_existencias(producto_id,sucursal,ubicacion,existencia) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE existencia=existencia+VALUES(existencia), updated_at=CURRENT_TIMESTAMP');
        $stockPos=$db->prepare('INSERT INTO pos_existencias_farmacia(sucursal_id,producto_id,existencia) VALUES (?,?,?) ON DUPLICATE KEY UPDATE existencia=existencia+VALUES(existencia),actualizada_en=CURRENT_TIMESTAMP');
        $mv=$db->prepare("INSERT INTO movimientos(folio,tipo_movimiento,fecha,almacen_id,usuario_id,proveedor_id,referencia,tipo_operacion,observaciones)
          VALUES (?,'ENTRADA',NOW(),?,?,?,?,?,?)");
        $md=$db->prepare('INSERT INTO movimiento_detalle(movimiento_id,producto_id,lote_id,cantidad,costo_unitario,precio_unitario,ubicacion) VALUES (?,?,?,?,?,0,?)');
        $lot=$db->prepare('INSERT INTO lotes(producto_id,numero_lote,fecha_caducidad,existencia,costo_unitario,almacen_id,ubicacion,estado) VALUES (?,?,?,?,?,?,?,1)');
        $audit=$db->prepare('INSERT INTO erp_facturas_stock_aplicaciones(factura_id,revision_detalle_id,producto_id,cantidad,destino,ubicacion,almacen_id,pos_sucursal_id,movimiento_id,usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $count=0;$pieces=0;$fichasModificadas=0;$productosFichaAplicada=[];
        $posId=null;
        if($target['tipo']==='FARMACIA'){
            $getPos->execute([$target['pos']]);$posId=(int)$getPos->fetchColumn();
            if($posId<1)throw new RuntimeException('No está configurado el inventario POS de '.$target['nombre'].'.');
        }else{
            $st=$db->prepare('SELECT id FROM almacenes WHERE id=? AND estado=1');$st->execute([$target['almacen']]);
            if(!$st->fetchColumn())throw new RuntimeException('El almacén destino no se encuentra activo.');
        }
        foreach($ids as $id){
            $read->execute([$fid,$id]);$r=$read->fetch(PDO::FETCH_ASSOC);
            if(!$r||$r['tipo']!=='ACEPTADA'||$r['aplicado']!==null)throw new RuntimeException('Una partida ya está aplicada o no fue aceptada físicamente. Actualiza la página.');
            $qty=(int)$r['cantidad'];$pid=(int)$r['producto_id'];
            erp_oc_proteger_aplicacion_neta($db,(int)($locked['orden_id']??0),$fid,$pid,$qty);
            // ERP G&D: valida también las Entradas clásicas vinculadas de la misma OC/bodega.
            if($target['tipo']==='ALMACEN')gyd_oc_stock_factura($db,(int)($locked['orden_id']??0),$pid,$qty,(int)$target['almacen']);
            if($qty<1 || $pid<1)throw new RuntimeException('Partida con datos inválidos.');
            $cad=(string)($r['caducidad']??'');
            if($cad!=='' && $cad<date('Y-m-d'))throw new RuntimeException('El producto '.(string)$r['descripcion_snapshot'].' está caducado. No se puede liberar.');
            $costo=(float)$r['costo_unitario'];
            if($costo>99999999.99 || $costo<0)throw new RuntimeException('Costo fuera de rango para el registro del movimiento.');
            $loc=null;$moveId=null;
            if($target['tipo']==='ALMACEN'){
                $loc=mb_strtoupper(trim((string)($locations[$id]??'')));
                if($loc===''||mb_strlen($loc)>100||!preg_match('/^[\p{L}0-9 \-]+$/uD',$loc))throw new RuntimeException('Escribe una ubicación válida (por ejemplo R1N1Z01) para '.(string)$r['descripcion_snapshot']);
                // Folio único por partida de recepción: imposible repetir el mismo movimiento.
                $folioMov='EFI-'.$id;
                $mv->execute([$folioMov,(int)$target['almacen'],$uid,(int)$locked['proveedor_id'],'Factura '.$locked['folio_interno'].' · revisión #'.$id,'ERP_FACTURA','Entrada de mercancía verificada desde ERP G&D']);
                $moveId=(int)$db->lastInsertId();$lotId=null;
                if(trim((string)$r['lote'])!==''){
                    $lot->execute([$pid,$r['lote'],$r['caducidad']?:null,$qty,number_format($costo,2,'.',''),(int)$target['almacen'],$loc]);
                    $lotId=(int)$db->lastInsertId();
                }
                $md->execute([$moveId,$pid,$lotId,$qty,number_format($costo,2,'.',''),$loc]);
                $stockWarehouse->execute([$pid,$target['sucursal'],$loc,$qty]);
            }else{
                // Farmacias con inventario único. La asignación desde Almacén Hidalgo/Tuxtla
                // NO se hace aquí: se hará por resurtido/transferencia real, para no duplicar.
                $stockPos->execute([pi_tt_stock_sid($db,$posId),$pid,$qty]);
            }
            // El precio y presentación se registran EN ESTA MISMA transacción de entrada.
            // Si hay varias partidas del mismo producto, la ficha se aplica una sola vez.
            if(isset($fichas[$id])){
                $data=$fichas[$id];
                $firma=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                if(isset($productosFichaAplicada[$pid])){
                    if($productosFichaAplicada[$pid]!==$firma)throw new RuntimeException('El producto #'.$pid.' tiene fichas comerciales distintas en esta factura. Unifícalas antes de aplicar.');
                }else{
                    $productosFichaAplicada[$pid]=$firma;
                    if(gyd_ff_aplicar_ficha($db,$posPrecioId,$pid,$uid,'Factura '.$locked['folio_interno'].' · verificación #'.$fid,$data))$fichasModificadas++;
                }
            }
            $audit->execute([$fid,$id,$pid,$qty,$target['tipo'],$loc,$target['almacen'],$posId,$moveId,$uid]);
            $count++;$pieces+=$qty;
        }
        $db->commit();unset($_SESSION[$tkKey]);
        $_SESSION['ef_stock_flash']='Se aplicaron '.$pieces.' piezas de '.$count.' partidas al inventario de '.$target['nombre'].'. Fichas comerciales modificadas: '.$fichasModificadas.'. No se crearon resurtidos automáticos.';
        header('Location: erp_facturas_aplicar.php?id='.$fid,true,303);exit;
    }
    $st=$db->prepare("SELECT rd.id,rd.tipo,rd.cantidad,rd.lote,rd.caducidad,fd.producto_id,fd.codigo_snapshot,fd.descripcion_snapshot,
       pp.precio_publico AS precio_pos, pr.modalidad_presentacion AS modalidad_pos, pr.piezas_por_caja AS piezas_caja_pos,
       a.id AS aplicacion_id,a.ubicacion AS ubicacion_aplicada,a.creado_en AS aplicado_en,u.nombre AS aplicado_por
       FROM erp_facturas_revision_detalle rd
       JOIN erp_facturas_revisiones r ON r.id=rd.revision_id
       JOIN erp_facturas_detalle fd ON fd.id=rd.factura_detalle_id
       LEFT JOIN erp_facturas_stock_aplicaciones a ON a.revision_detalle_id=rd.id
       LEFT JOIN usuarios u ON u.id=a.usuario_id
       LEFT JOIN pos_precios pp ON pp.producto_id=fd.producto_id AND pp.sucursal_id=?
       LEFT JOIN pos_presentaciones pr ON pr.producto_id=fd.producto_id AND pr.sucursal_id=?
       WHERE r.factura_id=? AND rd.tipo='ACEPTADA'
       ORDER BY rd.id DESC");
    $st->execute([$posPrecioId,$posPrecioId,$fid]);$partidas=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($partidas as $p){if($p['aplicacion_id'])$totalAplicado+=(int)$p['cantidad'];else $totalPendiente+=(int)$p['cantidad'];}
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    error_log('ERP stock factura: '.$e->getMessage());
    $error=$e instanceof RuntimeException?$e->getMessage():'No fue posible consultar o aplicar el inventario. Comprueba la migración SQL.';
}
ef_header('ERP G&D · Aplicación al inventario','Sólo mercancía aceptada físicamente · Stock operativo · Kardex de entradas por almacén');
if($error):?><div class="alert error" role="alert"><?=ef_e($error)?></div><?php endif;
if($success):?><div class="alert success"><?=ef_e($success)?></div><?php endif;
if($factura):$target=$destinos[$factura['sucursal']];?>
<section class="card">
 <h2><?=ef_e($factura['folio_interno'])?> · <?=ef_e($factura['numero_factura'])?></h2>
 <div class="grid"><div class="metric"><small>Destino operativo</small><b><?=ef_e($target['nombre'])?></b></div>
 <div class="metric"><small>Aceptadas pendientes de aplicar</small><strong><?=number_format($totalPendiente)?></strong></div>
 <div class="metric"><small>Ya aplicadas</small><strong><?=number_format($totalAplicado)?></strong></div></div>
 <p class="muted">Proveedor: <?=ef_e($factura['proveedor'])?> · Sucursal: <?=ef_e($factura['sucursal'])?> · Estado de verificación: <?=ef_e($factura['estado'])?></p>
 <div class="alert"><b>Antes de confirmar:</b> revisa que estas piezas <b>no se registraron ya por Entradas/Recepciones ni por importaciones anteriores</b>. Esta operación suma stock real una sola vez por partida. Administración/Dirección pueden configurar el precio público, modalidad y piezas por caja para esta sucursal en el mismo registro. <b>El costo de compra viene de la factura; no se modifica aquí.</b> Las incidencias no se aplican.</div>
 <a class="btn alt" href="erp_facturas_verificacion.php?id=<?=$fid?>">← Volver a Verificación</a> <a class="btn alt" href="erp_facturas_conciliacion.php?id=<?=$fid?>">Conciliar factura y OC</a> <!-- GYD_CONCILIACION_LINK_V1 -->
</section>
<section class="card"><h2>Partidas aceptadas por Almacén</h2>
<?php if(!$partidas):?><p>Aún no existen piezas aceptadas físicamente.</p><?php else:?>
<?php if($puedeFicha):?><div class="alert success"><b>Configuración desde factura:</b> el precio y presentación de cada producto se guardarán junto con la entrada aceptada. Si no quieres cambiar la ficha, desmarca «Actualizar ficha». Las existencias no se escriben manualmente.</div><?php endif;?>
<form method="post" onsubmit="return confirm('¿CONFIRMAS la entrada real al inventario? Comprueba que estas cantidades NO se aplicaron antes por otro módulo.');">
<input type="hidden" name="csrf" value="<?=ef_e($csrf)?>"><input type="hidden" name="aplicar_token" value="<?=ef_e($token)?>"><input type="hidden" name="factura_id" value="<?=$fid?>">
<div class="tablewrap"><table><thead><tr><th>Aplicar</th><th>Producto</th><th>Cantidad</th><th>Lote / caducidad</th><th>Ubicación</th><th>Ficha comercial POS</th><th>Estado</th></tr></thead><tbody>
<?php foreach($partidas as $p):$aplicada=$p['aplicacion_id']!==null;$cad=(string)($p['caducidad']??'');$caducada=$cad!==''&&$cad<date('Y-m-d');?><tr>
 <td><?php if(!$aplicada&&!$caducada):?><input type="checkbox" name="aplicar[]" value="<?=(int)$p['id']?>" checked style="width:auto"><?php else:?>—<?php endif;?></td>
 <td><b><?=ef_e($p['descripcion_snapshot'])?></b><br><small><?=ef_e($p['codigo_snapshot'])?></small></td>
 <td class="num"><?=(int)$p['cantidad']?> piezas</td><td><?=ef_e($p['lote']??'—')?> <small><?=ef_e($cad)?></small></td>
 <td><?php if($aplicada):?><?=ef_e($p['ubicacion_aplicada']??'Farmacia')?><?php elseif($target['tipo']==='ALMACEN'):?><input name="ubicacion[<?=(int)$p['id']?>]" maxlength="100" placeholder="Ej. R1N1Z01" value="" required><?php else:?>Farmacia (inventario único)<?php endif;?></td>
 <td><?php if(!$aplicada&&!$caducada&&$puedeFicha):
   $rowId=(int)$p['id'];$actualPrecio=$p['precio_pos']===null?'':number_format((float)$p['precio_pos'],2,'.','');
   $modoActual=(string)($p['modalidad_pos']??'');$factorActual=(int)($p['piezas_caja_pos']??0);
   $modoMostrar=in_array($modoActual,['PIEZA','CAJA','AMBAS'],true)?$modoActual:'PIEZA';$factorMostrar=$factorActual>0?$factorActual:1;
 ?><details open style="min-width:220px"><summary style="cursor:pointer;font-weight:700">Precio y presentación</summary>
   <label style="display:flex;align-items:center;gap:7px;margin:7px 0"><input type="checkbox" name="ficha[<?=$rowId?>]" value="1" checked style="width:auto"> Actualizar ficha con esta entrada</label>
   <label>Precio público (por pieza, MXN)<input type="number" min="0.01" max="99999999.99" step="0.01" name="precio_publico[<?=$rowId?>]" value="<?=ef_e($actualPrecio)?>"></label>
   <label>Presentación<select name="modalidad[<?=$rowId?>]"><?php foreach(['PIEZA'=>'Solo pieza','CAJA'=>'Solo caja','AMBAS'=>'Pieza y caja'] as $mode=>$label):?><option value="<?=$mode?>" <?=$modoMostrar===$mode?'selected':''?>><?=ef_e($label)?></option><?php endforeach;?></select></label>
   <label>Piezas por caja<input type="number" min="1" max="100000" step="1" name="piezas_por_caja[<?=$rowId?>]" value="<?=$factorMostrar?>" required></label>
   <input type="hidden" name="precio_original[<?=$rowId?>]" value="<?=ef_e($actualPrecio)?>">
   <input type="hidden" name="modalidad_original[<?=$rowId?>]" value="<?=ef_e($modoActual)?>">
   <input type="hidden" name="piezas_original[<?=$rowId?>]" value="<?=$factorActual?>">
   <small>Precio caja = precio pieza × piezas por caja. No se duplica el stock.</small>
  </details>
 <?php else:?><?php if($p['precio_pos']!==null):?><b>$<?=number_format((float)$p['precio_pos'],2)?></b> / pieza<?php else:?>Sin precio configurado<?php endif;?><br><small><?=ef_e((string)($p['modalidad_pos']??'PIEZA'))?> · <?=(int)($p['piezas_caja_pos']?:1)?> pzas/caja</small><?php endif;?></td>
 <td><?php if($aplicada):?><b>APLICADA</b><br><small><?=ef_e($p['aplicado_en'])?> · <?=ef_e($p['aplicado_por']??'')?></small><?php elseif($caducada):?><b>CADUCADA — BLOQUEADA</b><?php else:?><span class="badge">PENDIENTE</span><?php endif;?></td>
 </tr><?php endforeach;?></tbody></table></div>
<?php if($totalPendiente>0):?><p><label style="display:inline-flex;align-items:center;gap:8px"><input type="checkbox" name="confirmar" value="1" required style="width:auto"> He revisado existencias y confirmo que no existe una entrada duplicada.</label></p><button type="submit">Confirmar y aplicar partidas seleccionadas</button><?php endif;?></form>
<script>
// GYD_APLICACION_JSON_V1: ningún lote pierde líneas por max_input_vars.
(()=>{const form=document.querySelector('input[name="aplicar_token"]')?.form;if(!form)return;
 form.addEventListener('submit',e=>{
  if(e.defaultPrevented)return;
  const selected=[...form.querySelectorAll('input[name="aplicar[]"]:checked')];
  const rows=selected.map(el=>{
    const id=el.value,tr=el.closest('tr');
    const field=(n)=>tr.querySelector('[name="'+n+'['+id+']"]')?.value??'';
    return {id,ubicacion:field('ubicacion'),ficha:!!tr.querySelector('[name="ficha['+id+']"]:checked'),precio:field('precio_publico'),
      precio_original:field('precio_original'),modalidad:field('modalidad'),modalidad_original:field('modalidad_original'),
      piezas_por_caja:field('piezas_por_caja'),piezas_original:field('piezas_original')};
  });
  let hidden=form.querySelector('input[name="aplicar_json"]');if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name='aplicar_json';form.append(hidden);}hidden.value=JSON.stringify(rows);
  form.querySelectorAll('input[name="aplicar[]"],input[name^="ubicacion["],input[name^="ficha["],input[name^="precio_publico["],input[name^="precio_original["],input[name^="modalidad["],input[name^="modalidad_original["],input[name^="piezas_por_caja["],input[name^="piezas_original["]').forEach(el=>el.removeAttribute('name'));
 });
})();
</script>
<?php endif;?></section>
<section class="card"><h2>Qué sigue después</h2><p>En Hidalgo y Tuxtla la entrada se suma <b>sólo al Almacén</b>. El gerente solicita el resurtido y la entrega a Farmacia deberá registrarse mediante una <b>transferencia</b>: salida del Almacén y entrada al POS. Esa sincronización todavía no forma parte de este ZIP. En Tapachula y Toscana se suma directamente al POS, al tener inventario único.</p></section>
<?php endif;ef_footer();
