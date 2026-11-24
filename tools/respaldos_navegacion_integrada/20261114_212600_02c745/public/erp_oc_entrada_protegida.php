<?php
declare(strict_types=1);
/** Entrada de Bodega vinculada a una RECEPCIÓN física, con cupo transaccional compartido con Facturas. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_recepciones.php';
require_once __DIR__.'/../app/models/Movimiento.php';
requireLogin();
$user=currentUser()?:[];
$acceso=erpr_acceso($user);
$rec=(int)($_POST['rec']??$_GET['rec']??0);
if($rec<1){http_response_code(400);exit('Indica una recepción (rec) válida.');}
if(empty($_SESSION['gyd_oc_entrada_csrf']))$_SESSION['gyd_oc_entrada_csrf']=bin2hex(random_bytes(32));
if(empty($_SESSION['gyd_oc_entrada_token']))$_SESSION['gyd_oc_entrada_token']=bin2hex(random_bytes(16));
$csrf=(string)$_SESSION['gyd_oc_entrada_csrf'];$token=(string)$_SESSION['gyd_oc_entrada_token'];
$error='';$ok=(string)($_SESSION['gyd_oc_entrada_ok']??'');unset($_SESSION['gyd_oc_entrada_ok']);
$db=(new Database())->connect();
$q=$db->prepare("SELECT r.id,r.folio,r.orden_id,r.destino,r.estado,r.fecha_recepcion,o.folio oc_folio,o.estado oc_estado,p.nombre proveedor
    FROM erp_oc_recepciones r JOIN erp_ordenes_compra o ON o.id=r.orden_id JOIN proveedores p ON p.id=o.proveedor_id WHERE r.id=?");
$q->execute([$rec]);$cab=$q->fetch(PDO::FETCH_ASSOC);
if(!$cab || $cab['estado']!=='REGISTRADA'){http_response_code(404);exit('Recepción inexistente o anulada.');}
if(!erpr_puede_ver_destino($acceso,(string)$cab['destino'])){http_response_code(403);exit('Sin acceso a este destino.');}
$map=['HIDALGO_BODEGA'=>1,'TUXTLA_BODEGA'=>3];
$alm=$map[$cab['destino']]??0;
if(!$alm){http_response_code(403);exit('El destino es Farmacia/POS. Usa verificación y aplicación de factura, no Entradas de Bodega.');}
$puede=erpr_puede_registrar_destino($acceso,(string)$cab['destino']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
    if(!$puede)throw new RuntimeException('Tu usuario no puede registrar entradas para esta bodega.');
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))||!hash_equals($token,(string)($_POST['token']??'')))throw new RuntimeException('Formulario caducado o reutilizado. Actualiza la página.');
    if(($_POST['confirmar']??'')!=='1')throw new RuntimeException('Confirma la entrada física al inventario.');
    $rdid=filter_var($_POST['rdid']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $qty=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000000]]);
    $ubicacion=mb_strtoupper(trim((string)($_POST['ubicacion']??'')));
    $costText=trim((string)($_POST['costo']??''));
    if(!$rdid||!$qty)throw new RuntimeException('Selecciona una partida y cantidad positiva.');
    if($ubicacion===''||mb_strlen($ubicacion)>100||!preg_match('/^[\p{L}0-9 \-]+$/uD',$ubicacion))throw new RuntimeException('Ubicación inválida, ejemplo R1N1Z01.');
    if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$costText))throw new RuntimeException('Costo inválido. Utiliza un importe entre 0 y 99,999,999.99.');
    $q=$db->prepare('SELECT rd.id,rd.producto_id,rd.cantidad_piezas,rd.lote,rd.caducidad,od.orden_id FROM erp_oc_recepciones_detalle rd JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id WHERE rd.id=? AND rd.recepcion_id=?');
    $q->execute([$rdid,$rec]);$line=$q->fetch(PDO::FETCH_ASSOC);
    if(!$line || (int)$line['orden_id']!==(int)$cab['orden_id'])throw new RuntimeException('La partida no pertenece a esta recepción.');
    if($line['caducidad'] && $line['caducidad']<=date('Y-m-d'))throw new RuntimeException('No puede aplicarse mercancía caducada.');
    $mov=new Movimiento();
    $folio=$mov->generarFolioEntrada($alm);
    $resultado=$mov->crearMovimiento([
      'folio'=>$folio,'tipo_movimiento'=>'ENTRADA','fecha'=>date('Y-m-d H:i:s'),
      'almacen_id'=>$alm,'usuario_id'=>(int)$acceso['uid'],
      'proveedor_id'=>null,'proveedor_nombre'=>(string)$cab['proveedor'],
      'referencia'=>'ERP_OC_REC: '.$cab['folio'].' · '.$cab['oc_folio'],
      'observaciones'=>'Entrada protegida desde recepción '.$cab['folio'].' (únicamente piezas buenas)',
      'gyd_recepcion_detalle_id'=>$rdid,'gyd_solicitud_token'=>$token,
    ],[[
      'producto_id'=>(int)$line['producto_id'],'cantidad'=>$qty,
      'costo_unitario'=>(float)$costText,'precio_unitario'=>0,
      'numero_lote'=>(string)($line['lote']??''),
      'fecha_caducidad'=>(string)($line['caducidad']??''),
      'ubicacion'=>$ubicacion,'almacen_id'=>$alm,
    ]]);
    if(empty($resultado['success']))throw new RuntimeException((string)($resultado['message']??'No se pudo registrar.'));
    unset($_SESSION['gyd_oc_entrada_token']);
    $_SESSION['gyd_oc_entrada_ok']='Se registraron '.$qty.' piezas buenas en la entrada '.$resultado['folio'].'.';
    header('Location: erp_oc_entrada_protegida.php?rec='.$rec,true,303);exit;
 }catch(Throwable $e){$error=$e->getMessage();error_log('Entrada OC protegida: '.$error);}
}
$q=$db->prepare("SELECT rd.id,rd.producto_id,rd.cantidad_piezas AS buenas,od.codigo_snapshot,od.descripcion_snapshot,rd.lote,rd.caducidad,p.costo_ultimo,
  COALESCE((SELECT SUM(v.cantidad) FROM erp_oc_entradas_vinculos v WHERE v.recepcion_detalle_id=rd.id),0) AS aplicada_linea,
  COALESCE((SELECT SUM(v.cantidad) FROM erp_oc_entradas_vinculos v WHERE v.orden_id=? AND v.producto_id=rd.producto_id AND v.almacen_id=?),0) AS manual_total,
  COALESCE((SELECT SUM(a.cantidad) FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id WHERE f.orden_id=? AND a.producto_id=rd.producto_id AND a.destino='ALMACEN' AND a.almacen_id=?),0) AS factura_total,
  COALESCE((SELECT SUM(s.cantidad_piezas) FROM erp_oc_recepciones_detalle s JOIN erp_oc_recepciones rr ON rr.id=s.recepcion_id WHERE rr.orden_id=? AND rr.estado='REGISTRADA' AND s.producto_id=rd.producto_id),0) AS buenas_total
  FROM erp_oc_recepciones_detalle rd JOIN erp_ordenes_compra_detalle od ON od.id=rd.orden_detalle_id
  JOIN productos p ON p.id=rd.producto_id WHERE rd.recepcion_id=? ORDER BY rd.id");
$q->execute([(int)$cab['orden_id'],$alm,(int)$cab['orden_id'],$alm,(int)$cab['orden_id'],$rec]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D · Entrada OC protegida</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;font:14px system-ui,sans-serif;color:#163956}header{background:#123e69;color:#fff;padding:22px 5%}main{max-width:1250px;margin:20px auto;padding:0 14px}.card{background:white;border:1px solid #d4e1ef;border-radius:12px;padding:20px;margin:15px 0}.warn{background:#fff3d7;padding:14px;border:1px solid #edcd8f;border-radius:9px}.err{background:#ffe5e5;border-color:#ec9c9c}.ok{background:#e0f6e7;border:1px solid #a8d6b3}table{border-collapse:collapse;width:100%;min-width:970px}td,th{padding:12px;border-bottom:1px solid #dde7f0;text-align:left}th{background:#194877;color:white}.scroll{overflow:auto}input{width:100%;padding:10px;border:1px solid #bac9d9;border-radius:8px}.qty{width:100px}.btn,button{background:#1760a6;color:white;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.secondary{background:#e7eff7;color:#194978}.tag{font-size:12px;color:#58718a}form{display:flex;gap:9px;align-items:center;min-width:500px}form input[name=ubicacion]{width:115px}form input[name=costo]{width:104px}form .qty{width:80px}button:disabled{opacity:.5}
</style></head><body><header><h1>Entrada protegida desde recepción OC</h1><p><?=$e($cab['oc_folio'])?> · <?=$e($cab['folio'])?> · <?=$e($cab['proveedor'])?></p></header><main><a class="btn secondary" href="erp_recepciones_calidad_historial.php?rec=<?=$rec?>">Volver a la recepción</a><div class="card warn"><b>Solo para Bodega:</b> este proceso SÍ aumenta existencias al confirmar. Se descuenta del cupo compartido con las aplicaciones por factura. Las piezas dañadas, faltantes y sobrantes no se incluyen. No captures la misma entrega en «Entradas» clásica.</div>
<?php if($error):?><div class="card warn err"><?=$e($error)?></div><?php endif;?>
<?php if($ok):?><div class="card warn ok"><?=$e($ok)?></div><?php endif;?>
<div class="card"><h2>Buenas y aplicaciones de esta recepción</h2><div class="scroll"><table><thead><tr><th>Producto</th><th>Buenas en REC</th><th>Entrada de esta partida</th><th>Facturas aplicadas (OC)</th><th>Disponible global</th><th>Registrar entrada</th></tr></thead><tbody>
<?php foreach($rows as $r):$restLinea=max(0,(int)$r['buenas']-(int)$r['aplicada_linea']);$global=max(0,(int)$r['buenas_total']-(int)$r['manual_total']-(int)$r['factura_total']);$permitido=min($restLinea,$global);?><tr><td><b><?=$e($r['descripcion_snapshot'])?></b><div class="tag"><?=$e($r['codigo_snapshot'])?> · lote <?=$e($r['lote']?:'No indicado')?></div></td><td><?=number_format((int)$r['buenas'])?></td><td><?=number_format((int)$r['aplicada_linea'])?></td><td><?=number_format((int)$r['factura_total'])?></td><td><b><?=number_format($permitido)?></b></td><td>
<?php if($puede&&$permitido>0):?><form method="post" onsubmit="return confirm('Se agregará esta mercancía al inventario de Bodega. ¿Confirmas?')"><input type="hidden" name="rec" value="<?=$rec?>"><input type="hidden" name="rdid" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf" value="<?=$e($csrf)?>"><input type="hidden" name="token" value="<?=$e($token)?>"><input type="hidden" name="confirmar" value="1"><input class="qty" type="number" name="cantidad" min="1" max="<?=$permitido?>" value="<?=$permitido?>" required aria-label="Piezas"><input name="ubicacion" placeholder="R1N1Z01" maxlength="100" required aria-label="Ubicación"><input name="costo" type="number" min="0" max="99999999.99" step="0.01" value="<?=$e(number_format(max(0,(float)$r['costo_ultimo']),2,'.',''))?>" required aria-label="Costo unitario"><button type="submit">Aplicar</button></form><?php else:?><span class="tag"><?=$permitido===0?'Sin saldo disponible':'Consulta únicamente'?></span><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></div><p class="tag">Una aplicación parcial adicional crea otra entrada con su propio movimiento y queda vinculada a la misma partida REC. Los totales se revalidan con bloqueo de OC en el servidor.</p></main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=1">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=1"></script>
<?php endif; ?>
</body></html>
