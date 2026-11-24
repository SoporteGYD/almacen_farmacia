<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_conciliacion_tt.php';
requireLogin();
$u=currentUser()?:[];
if(!gct_admin($u)){http_response_code(403);exit('Acceso denegado. Sólo Administración y Dirección pueden conciliar.');}
header('Cache-Control: no-store, private');
if(empty($_SESSION['gct_csrf']))$_SESSION['gct_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gct_csrf'];
$sid=(int)($_POST['sucursal']??$_GET['sucursal']??3);if(!gct_sid($sid))$sid=3;
$pid=max(0,(int)($_POST['producto']??$_GET['producto']??0));
$q=mb_substr(trim((string)($_GET['q']??'')),0,80);
$show=max(0,(int)($_GET['solicitud']??0));
$msg='';$error='';$search=[];$product=null;$stock=null;$rows=[];$events=[];
try{
 $db=(new Database())->connect();gct_guard($db);
 foreach(['gyd_tt_conciliacion_solicitudes','gyd_tt_conciliacion_eventos'] as $t)if(!gct_exists($db,$t))throw new RuntimeException('Instala primero el módulo de conciliación asistida. Falta '.$t);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Formulario vencido. Actualiza la página.');
  $act=(string)($_POST['accion']??'');
  $msg=gct_action($db,$u,$act,$_POST);
  $_SESSION['gct_flash']=$msg;
  $redirect='erp_conciliacion_tt.php?'.http_build_query(['sucursal'=>$sid,'producto'=>$pid]);
  header('Location: '.$redirect, true,303);exit;
 }
 $msg=(string)($_SESSION['gct_flash']??'');unset($_SESSION['gct_flash']);
 if($q!==''){
  $s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE estado=1 AND (codigo LIKE ? OR descripcion LIKE ?) ORDER BY descripcion LIMIT 35');
  $like='%'.$q.'%';$s->execute([$like,$like]);$search=$s->fetchAll(PDO::FETCH_ASSOC);
 }
 if($pid>0){$s=$db->prepare('SELECT id,codigo,descripcion FROM productos WHERE id=? AND estado=1');$s->execute([$pid]);$product=$s->fetch(PDO::FETCH_ASSOC)?:null;if($product)$stock=gct_stock($db,$sid,$pid);}
 $rows=gct_recent($db,$sid,$pid);
 if($show>0)$events=gct_events($db,$show);
 if(($_GET['exportar']??'')==='xlsx'){
  $filter='SELECT COUNT(*) FROM gyd_tt_conciliacion_solicitudes WHERE sucursal_id=?';
  $filterArgs=[$sid];
  if($pid>0){$filter.=' AND producto_id=?';$filterArgs[]=$pid;}
  $totalStmt=$db->prepare($filter);$totalStmt->execute($filterArgs);
  if((int)$totalStmt->fetchColumn()>10000)throw new DomainException('Demasiadas solicitudes para un solo Excel. Selecciona un producto para obtener un reporte completo.');
  $rows=gct_recent($db,$sid,$pid,10000);
  if(!is_file(__DIR__.'/../vendor/autoload.php'))throw new RuntimeException('PhpSpreadsheet no está instalado.');
  require_once __DIR__.'/../vendor/autoload.php';
  $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sh=$book->getActiveSheet();$sh->setTitle('Conciliaciones');
  $heads=['Folio','Sucursal','Producto ID','Código','Producto','Estado','Antes','Contado','Diferencia','Capturado por','Autorizado por','Aplicado por','Fecha conteo','Fecha aplicado','Justificación'];
  foreach($heads as $i=>$label)$sh->setCellValue([$i+1,1],$label);
  foreach($rows as $index=>$r){$ri=$index+2;
   $values=[(int)$r['id'],gct_sname((int)$r['sucursal_id']),(int)$r['producto_id'],(string)$r['codigo'],(string)$r['descripcion'],(string)$r['estado'],$r['existencia_base']===null?'SIN REGISTRO':(int)$r['existencia_base'],(int)$r['cantidad_contada'],$r['existencia_base']===null?'':((int)$r['cantidad_contada']-(int)$r['existencia_base']),$r['creador']??'', $r['autorizador']??'', $r['aplicador']??'', $r['creado_en'],$r['aplicado_en']??'', $r['observacion']];
   foreach($values as $i=>$value){if(is_string($value))$sh->setCellValueExplicit([$i+1,$ri],$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);else $sh->setCellValue([$i+1,$ri],$value);}
  }
  $sh->getStyle('A1:O1')->getFont()->setBold(true);$sh->setAutoFilter('A1:O'.max(2,count($rows)+1));$sh->freezePane('A2');
  foreach(range('A','O') as $column)$sh->getColumnDimension($column)->setWidth(in_array($column,['E','O'],true)?46:19);
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="G_D_conciliacion_'.($sid===3?'tapachula':'toscana').'.xlsx"');
  header('Cache-Control: no-store');(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');exit;
 }
}catch(Throwable $e){error_log('G&D conciliación TT: '.$e->getMessage());$error=$e instanceof DomainException?$e->getMessage():'No fue posible consultar el módulo. Comprueba la instalación y revisa el registro PHP.';}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Conciliación asistida</title>
<style>
:root{font:15px system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#163755;background:#edf3f9}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(110deg,#133c67,#2367b0);color:#fff;padding:24px max(18px,calc((100% - 1200px)/2))}h1{margin:0 0 5px;font-size:26px}header p{margin:0;font-size:13px;color:#e6f0fc}main{max-width:1240px;margin:0 auto;padding:18px 16px 55px}.nav,.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.nav{margin-bottom:15px}.card{background:white;border-radius:15px;box-shadow:0 9px 22px #204a7012;padding:19px;margin-bottom:16px}h2{margin:0 0 12px;font-size:20px}.sub{color:#59738c;font-size:13px}.note{padding:13px 14px;border-radius:12px;background:#fff6e7;border:1px solid #e4cc9a;margin:12px 0;font-size:13px}.error{background:#fff0f0;border-color:#efa5a5}.success{background:#eaf8f1;border-color:#9bd4b9}.btn,button{display:inline-flex;align-items:center;justify-content:center;border:0;background:#1c62a3;color:white;font:700 13px system-ui;text-decoration:none;border-radius:10px;padding:10px 15px;cursor:pointer}.btn.alt{background:#eaf1f9;color:#174b76}.btn.green{background:#13805d}.btn.danger,button.danger{background:#9c2840}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}label{display:block;font-size:12px;font-weight:750;margin:0 0 6px}input,select,textarea{width:100%;padding:10px;border:1px solid #b7cbe0;border-radius:9px;font:inherit}textarea{min-height:82px}form{margin:0}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1000px}th,td{border-bottom:1px solid #e3ebf4;text-align:left;vertical-align:top;padding:10px;font-size:12px}th{background:#154775;color:#fff}tr:nth-child(even){background:#f8fafd}.numeric{text-align:right;font-variant-numeric:tabular-nums}.pill{font-size:11px;font-weight:800;border-radius:50px;background:#e7eef8;padding:4px 9px;white-space:nowrap}.pill.APLICADO{background:#d8f3e5;color:#116544}.pill.SOLICITADO,.pill.AUTORIZADO{background:#fff0cc;color:#8a5b00}.pill.RECHAZADO,.pill.CANCELADO{background:#f9e1e2;color:#9b243a}.mini{display:inline-flex;gap:6px;align-items:center;margin:0 3px 5px 0}.mini input{min-width:110px;padding:6px 8px;font-size:12px}.mini button{font-size:11px;padding:8px}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.tab{border:1px solid #d1e0ed;background:#fff;color:#1a558a;border-radius:11px;padding:10px 16px;text-decoration:none;font-weight:750}.tab.active{background:#165d9b;color:#fff}.stat{background:#ecf4fc;border-radius:11px;padding:14px}.stat span{display:block;font-size:12px;color:#587692}.stat strong{font-size:27px}@media(max-width:720px){.grid,.grid3{grid-template-columns:1fr}header{padding:18px}.card{padding:15px}}
</style></head><body>
<header><h1>Conciliación asistida de inventarios</h1><p>Farmacia G&D · Conteos verificados, autorización y ajustes por sucursal</p></header>
<main>
<nav class="nav" aria-label="Módulos de inventario"><a class="btn alt" href="dashboard.php">Inicio ERP</a><a class="btn alt" href="erp_alertas_inventario.php">Alertas de inventario</a><a class="btn alt" href="erp_kardex_general.php">Kardex General</a><a class="btn alt" href="erp_conciliacion_gestion.php">Hidalgo / Tuxtla</a></nav>
<div class="note"><b>Proceso seguro:</b> conteo físico → solicitud → autorización por <b>otra cuenta</b> → aplicación. La captura y la autorización NO cambian inventario. Sólo la aplicación modifica existencias POS dentro de la sucursal seleccionada. <b>No se mezclan Tapachula ni Toscana.</b> Para Almacén, Ciudad Hidalgo o Tuxtla utiliza su conciliación operativa correspondiente.</div>
<?php if($error):?><div class="note error" role="alert"><?=gct_h($error)?></div><?php endif;?><?php if($msg):?><div class="note success" role="status"><?=gct_h($msg)?></div><?php endif;?>
<div class="tabs"><a class="tab <?=$sid===3?'active':''?>" href="?sucursal=3">Tapachula</a><a class="tab <?=$sid===4?'active':''?>" href="?sucursal=4">Plaza Toscana</a></div>
<section class="card"><h2>Seleccionar producto · <?=gct_h(gct_sname($sid))?></h2><form method="get" class="grid"><input type="hidden" name="sucursal" value="<?=$sid?>"><div><label for="q">Buscar por código o descripción</label><input id="q" name="q" value="<?=gct_h($q)?>" placeholder="Nombre o código del producto"></div><div class="actions" style="align-items:end"><button type="submit">Buscar</button><a class="btn alt" href="?sucursal=<?=$sid?>">Limpiar</a></div></form>
<?php if($search):?><div class="scroll" style="margin-top:14px"><table><thead><tr><th>Código</th><th>Producto</th><th>Acción</th></tr></thead><tbody><?php foreach($search as $p):?><tr><td><?=gct_h($p['codigo'])?></td><td><?=gct_h($p['descripcion'])?></td><td><a class="btn alt" href="?<?=gct_h(http_build_query(['sucursal'=>$sid,'producto'=>$p['id']]))?>">Seleccionar</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php if($product):?>
<section class="card"><h2><?=gct_h($product['descripcion'])?></h2><p class="sub">Código <?=gct_h($product['codigo'])?> · <?=gct_h(gct_sname($sid))?></p>
<div class="grid3"><div class="stat"><span>Existencia registrada</span><strong><?=$stock===null?'Sin registro':number_format($stock)?></strong></div><div class="stat"><span>Ubicación</span><strong>POS</strong></div><div class="stat"><span>Conteo solicitado</span><strong>Físico</strong></div></div>
<form method="post" style="margin-top:17px"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="contar"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=(int)$product['id']?>"><div class="grid"><div><label for="cantidad">Cantidad física contada (piezas)</label><input type="number" id="cantidad" name="cantidad" min="0" max="10000000" step="1" required></div><div><label>Motivo / evidencia (12 a 500 caracteres)</label><textarea name="nota" minlength="12" maxlength="500" placeholder="Referencia del conteo, fecha, área y responsable" required></textarea></div></div><button type="submit">Guardar conteo (sin ajustar)</button></form>
</section>
<?php endif;?>
<section class="card"><div class="actions" style="justify-content:space-between"><div><h2>Solicitudes y autorizaciones</h2><p class="sub">Últimos 100 registros de <?=gct_h(gct_sname($sid))?>. El Excel incluye hasta 10,000 solicitudes según los filtros.</p></div><a class="btn green" href="?<?=gct_h(http_build_query(['sucursal'=>$sid,'producto'=>$pid,'exportar'=>'xlsx']))?>">Exportar Excel (.xlsx)</a></div>
<div class="scroll"><table><thead><tr><th>Solicitud / Producto</th><th>Antes → Contado</th><th>Estado</th><th>Responsables</th><th>Observación</th><th>Operaciones</th></tr></thead><tbody>
<?php foreach($rows as $r):$state=(string)$r['estado'];$id=(int)$r['id'];$created=(int)$r['solicitado_por']===(int)($u['id']??0);?>
<tr><td><b>#<?=$id?></b> · <?=gct_h($r['codigo'])?><br><?=gct_h($r['descripcion'])?><br><span class="sub"><?=gct_h($r['creado_en'])?></span></td>
<td><b><?= $r['existencia_base']===null?'Sin registro':(int)$r['existencia_base']?> → <?=(int)$r['cantidad_contada']?></b><br><span class="sub">Variación <?= $r['existencia_base']===null?'—':((int)$r['cantidad_contada']-(int)$r['existencia_base']) ?></span></td>
<td><span class="pill <?=gct_h($state)?>"><?=gct_h($state)?></span></td>
<td><span class="sub">Capturó: <?=gct_h($r['creador']??'—')?><br>Autorizó: <?=gct_h($r['autorizador']??'—')?><br>Aplicó: <?=gct_h($r['aplicador']??'—')?></span></td>
<td><?=gct_h($r['observacion'])?></td>
<td>
<?php if($state==='CONTEO'):?><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=$pid?>"><button>Solicitar autorización</button></form><?php endif;?>
<?php if($state==='SOLICITADO'&&!$created):?><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="autorizar"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=$pid?>"><button onclick="return confirm('¿Autorizar conteo físico para aplicación posterior?')">Autorizar</button></form><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=$pid?>"><input name="nota" placeholder="Motivo de rechazo" minlength="8" maxlength="500" required><button class="danger">Rechazar</button></form><?php endif;?>
<?php if($state==='SOLICITADO'&&$created):?><div class="sub">Otro administrador o dueño debe autorizar.</div><?php endif;?>
<?php if($state==='AUTORIZADO'):?><form method="post" class="mini" onsubmit="return confirm('Esta acción MODIFICARÁ la existencia POS de <?=gct_h(gct_sname($sid))?>. ¿Confirmas que el conteo es correcto?')"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=$pid?>"><button>Aplicar ajuste autorizado</button></form><?php endif;?>
<?php if(in_array($state,['CONTEO','SOLICITADO','AUTORIZADO'],true)):?><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=gct_h($csrf)?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="sucursal" value="<?=$sid?>"><input type="hidden" name="producto" value="<?=$pid?>"><input name="nota" placeholder="Motivo para cancelar" minlength="8" maxlength="500" required><button class="danger">Cancelar</button></form><?php endif;?>
<a href="?<?=gct_h(http_build_query(['sucursal'=>$sid,'producto'=>$pid,'solicitud'=>$id]))?>">Bitácora</a>
</td></tr>
<?php endforeach;?><?php if(!$rows):?><tr><td colspan="6">No hay solicitudes para este filtro.</td></tr><?php endif;?></tbody></table></div></section>
<?php if($show>0):?><section class="card"><h2>Bitácora de solicitud #<?=$show?></h2><div class="scroll"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Antes</th><th>Después</th><th>Detalle</th></tr></thead><tbody><?php foreach($events as $e):?><tr><td><?=gct_h($e['creado_en'])?></td><td><?=gct_h($e['accion'])?></td><td><?=gct_h($e['usuario'])?></td><td><?=$e['existencia_antes']===null?'—':(int)$e['existencia_antes']?></td><td><?=$e['existencia_despues']===null?'—':(int)$e['existencia_despues']?></td><td><?=gct_h($e['detalle'])?></td></tr><?php endforeach;?><?php if(!$events):?><tr><td colspan="6">Sin eventos disponibles.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?>
</main></body></html>
