<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_compras_alertas_seguimiento.php';
requireLogin();
$user=currentUser()??[];
if(!gdca_authorized($user)){http_response_code(403);exit('Sin permiso para el seguimiento financiero de Compras.');}
header('Cache-Control: private, no-store');
if(empty($_SESSION['gyd_alertas_gestion_csrf']))$_SESSION['gyd_alertas_gestion_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['gyd_alertas_gestion_csrf'];
$error='';$message='';$rows=[];$users=[];$history=[];$selected=null;$count=0;$pages=1;$page=1;$last='';$stats=[];
$status=is_string($_GET['estado']??null)?strtoupper($_GET['estado']):'ABIERTAS';
if(!in_array($status,['ABIERTAS','PENDIENTE','EN_REVISION','ATENDIDA','DESCARTADA','TODAS'],true))$status='ABIERTAS';
$type=is_string($_GET['tipo']??null)?$_GET['tipo']:'TODAS';
if(!in_array($type,['TODAS','SUBIDA','MARGEN_BAJO','PERDIDA','OPORTUNIDAD','SIN_PRECIO'],true))$type='TODAS';
$level=is_string($_GET['nivel']??null)?$_GET['nivel']:'TODAS';
if(!in_array($level,['TODAS','ALTA','MEDIA'],true))$level='TODAS';
$current=is_string($_GET['vigente']??null)?$_GET['vigente']:'1';
if(!in_array($current,['1','0','TODAS'],true))$current='1';
$assignee=filter_var($_GET['asignado']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$q=is_scalar($_GET['q']??null)?trim((string)$_GET['q']):'';
$q=function_exists('mb_substr')?mb_substr($q,0,90):substr($q,0,90);
$page=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
$caseId=filter_var($_GET['caso']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
try {
    $db=(new Database())->connect();
    if(!gdca_tables_ok($db))throw new RuntimeException('No están instaladas las tablas del Centro de Seguimiento. Ejecuta el instalador.');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
       if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Token de formulario no válido. Vuelve a abrir la página.');
       $action=(string)($_POST['accion']??'');
       if($action==='sincronizar'){
          $result=gdca_sync($db,true);
          $_SESSION['gyd_alertas_gestion_flash']='Detección actualizada: '.(int)($result['total']??0).' incidencias vigentes, '.(int)($result['nuevas']??0).' nuevas.';
          header('Location: erp_compras_alertas_seguimiento.php',true,303);exit;
       }
       if($action==='guardar'){
          $id=filter_var($_POST['caso_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
          if($id<=0)throw new InvalidArgumentException('Indica una incidencia válida.');
          $estado=(string)($_POST['nuevo_estado']??'');
          $asignar=filter_var($_POST['asignado_a']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
          $nota=is_string($_POST['observacion']??null)?$_POST['observacion']:'';
          gdca_edit_case($db,$user,$id,$estado,(int)$asignar,$nota);
          $_SESSION['gyd_alertas_gestion_flash']='Incidencia #'.$id.' actualizada y registrada en el historial.';
          header('Location: erp_compras_alertas_seguimiento.php?caso='.$id,true,303);exit;
       }
       throw new InvalidArgumentException('Acción no permitida.');
    }
    if(isset($_SESSION['gyd_alertas_gestion_flash'])){
       $message=(string)$_SESSION['gyd_alertas_gestion_flash'];unset($_SESSION['gyd_alertas_gestion_flash']);
    }
    try{gdca_sync($db,false);}catch(Throwable $e){error_log('G&D seguimiento sync: '.$e->getMessage());$message.=' La actualización automática no pudo completarse; se muestran registros previamente guardados.';}
    $users=gdca_compras_users($db);
    $last=(string)($db->query("SELECT valor FROM erp_compras_alerta_config WHERE llave='ultima_sync'")->fetchColumn()?:'Nunca');
    $stats=$db->query("SELECT COUNT(*) total,
       COALESCE(SUM(estado='PENDIENTE'),0) pendientes,
       COALESCE(SUM(estado='EN_REVISION'),0) revision,
       COALESCE(SUM(estado='ATENDIDA'),0) atendidas,
       COALESCE(SUM(estado='DESCARTADA'),0) descartadas,
       COALESCE(SUM(nivel='ALTA' AND estado IN ('PENDIENTE','EN_REVISION')),0) altas,
       ROUND(AVG(CASE WHEN cerrado_en IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,creado_en,cerrado_en)/60 END),1) horas_promedio
       FROM erp_compras_alerta_casos WHERE vigente=1")->fetch(PDO::FETCH_ASSOC)?:[];
    $conditions=[];$args=[];
    if($current!=='TODAS'){$conditions[]='c.vigente=:vigente';$args['vigente']=(int)$current;}
    if($status==='ABIERTAS')$conditions[]="c.estado IN ('PENDIENTE','EN_REVISION')";
    elseif($status!=='TODAS'){$conditions[]='c.estado=:estado';$args['estado']=$status;}
    if($type!=='TODAS'){$conditions[]='c.tipo=:tipo';$args['tipo']=$type;}
    if($level!=='TODAS'){$conditions[]='c.nivel=:nivel';$args['nivel']=$level;}
    if($assignee>0){$conditions[]='c.asignado_a=:asignado';$args['asignado']=(int)$assignee;}
    if($q!==''){$conditions[]='(c.codigo LIKE :search_code OR c.descripcion LIKE :search_desc OR c.proveedor LIKE :search_provider)';$args['search_code']='%'.$q.'%';$args['search_desc']='%'.$q.'%';$args['search_provider']='%'.$q.'%';}
    $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
    $c=$db->prepare('SELECT COUNT(*) FROM erp_compras_alerta_casos c'.$where);
    foreach($args as $k=>$v)$c->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $c->execute();$count=(int)$c->fetchColumn();$pages=max(1,(int)ceil($count/40));$page=min($page,$pages);
    $base='SELECT c.*, u.nombre nombre_responsable FROM erp_compras_alerta_casos c LEFT JOIN usuarios u ON u.id=c.asignado_a';
    $order=" ORDER BY CASE WHEN c.estado IN ('PENDIENTE','EN_REVISION') THEN 0 ELSE 1 END,
             CASE WHEN c.nivel='ALTA' THEN 0 ELSE 1 END, c.creado_en DESC,c.id DESC";
    $export=(string)($_GET['formato']??'')==='csv';
    $sql=$base.$where.$order.($export?'':' LIMIT 40 OFFSET '.(($page-1)*40));
    $s=$db->prepare($sql);
    foreach($args as $k=>$v)$s->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $s->execute();$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if($export){
       header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="G_AND_D_seguimiento_alertas_'.date('Ymd_His').'.csv"');
       $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");
       fputcsv($f,['Caso','Código','Producto','Tipo','Prioridad','Sucursal','Proveedor','Factura','Costo','Precio','Variación %','Estado','Vigente','Asignado a','Detectada','Última detección','Cerrada','Observación de alerta']);
       foreach($rows as $r){$x=[$r['id'],$r['codigo'],$r['descripcion'],gd_ac_type_name($r['tipo']),$r['nivel'],$r['sucursal_nombre'],$r['proveedor'],$r['factura_numero'],$r['costo'],$r['precio'],$r['variacion'],$r['estado'],(int)$r['vigente']?'Sí':'No',$r['nombre_responsable'],$r['creado_en'],$r['detectado_en'],$r['cerrado_en'],$r['descripcion_alerta']];fputcsv($f,array_map('gd_cp_csv_text',$x));}
       fclose($f);exit;
    }
    if($caseId>0){
       $st=$db->prepare('SELECT c.*,u.nombre nombre_responsable FROM erp_compras_alerta_casos c LEFT JOIN usuarios u ON u.id=c.asignado_a WHERE c.id=?');$st->execute([$caseId]);
       $selected=$st->fetch(PDO::FETCH_ASSOC)?:null;
       if($selected){$hi=$db->prepare('SELECT h.*,ua.nombre asignado_anterior_nombre,un.nombre asignado_nuevo_nombre FROM erp_compras_alerta_historial h LEFT JOIN usuarios ua ON ua.id=h.asignado_anterior LEFT JOIN usuarios un ON un.id=h.asignado_nuevo WHERE h.caso_id=? ORDER BY h.creado_en DESC,h.id DESC LIMIT 150');$hi->execute([$caseId]);$history=$hi->fetchAll(PDO::FETCH_ASSOC);}
    }
}catch(Throwable $e){error_log('G&D Centro de Seguimiento: '.$e->getMessage());$error=$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible consultar o actualizar el seguimiento. Revisa la instalación y la conexión MySQL.';}
function gdca_href(array $replace=[]):string {
    $raw=$_GET;unset($raw['formato']);return 'erp_compras_alertas_seguimiento.php?'.http_build_query(array_merge($raw,$replace));
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Seguimiento de Alertas de Compras</title><style>
:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;background:#eef3f9;color:#183b5a}*{box-sizing:border-box}body{margin:0}header{background:#153f66;color:white;padding:20px max(15px,calc((100% - 1350px)/2));display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap}h1{font-size:24px;margin:0 0 6px}header p{margin:0;color:#d4e3f3;font-size:13px}a{color:#145b91}header a{color:white}main{max-width:1350px;margin:auto;padding:18px}h2{font-size:19px;margin:0 0 12px}.box{background:white;border:1px solid #dbe5ef;border-radius:12px;padding:17px;margin-bottom:14px}.muted{font-size:12px;color:#647c91}.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:15px}.kpi{background:white;border:1px solid #dbe5ef;border-radius:10px;padding:12px}.kpi strong{font-size:25px;display:block}.kpi span{font-size:12px;color:#59748a}.filters{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:10px}.field{font-size:12px;font-weight:700;display:grid;gap:5px}select,input,textarea{width:100%;font:inherit;border-radius:8px;border:1px solid #bfcfde;padding:9px;min-height:38px}.buttons{display:flex;flex-wrap:wrap;gap:9px;margin:13px 0 0}.btn,button{display:inline-block;cursor:pointer;border:0;border-radius:8px;padding:10px 14px;color:white;background:#1b5c90;text-decoration:none;font-size:13px;font-weight:700}.btn.secondary{background:#e6eff9;color:#225074}.btn.green{background:#13735d}.table-scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:1020px;font-size:12px}th{text-align:left;background:#1c5278;color:#fff;padding:10px}td{padding:11px 9px;border-bottom:1px solid #e3eaf2;vertical-align:top}tr:hover{background:#f7fafd}.tag{display:inline-block;padding:4px 8px;border-radius:15px;font-size:11px;font-weight:700;background:#e5edf5;color:#294e70}.tag.high{background:#fbdfe3;color:#99263f}.tag.mid{background:#fff0cb;color:#885b12}.msg{background:#eaf9ef;padding:13px;border-radius:8px;color:#166a3a;margin:10px 0}.error{background:#fbe4e9;padding:13px;border-radius:8px;color:#98253d;margin:10px 0}.detail{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px}.detail .item{background:#f2f6fa;border-radius:8px;padding:10px;font-size:12px}.detail strong{display:block;font-size:14px;margin-top:4px}.note{line-height:1.5;font-size:13px}.pages{display:flex;justify-content:flex-end;gap:13px;padding-top:12px}.hist{border-left:3px solid #dce6f0;padding:4px 0 12px 12px;margin-bottom:7px}.hist strong{font-size:13px}@media(max-width:800px){.filters,.detail{grid-template-columns:repeat(2,minmax(130px,1fr))}}@media(max-width:500px){.filters,.detail{grid-template-columns:1fr}main{padding:9px}header h1{font-size:20px}}@media print{header,.buttons,.filters,.pages,form{display:none!important}.box{box-shadow:none;border:0;padding:0}.table-scroll{overflow:visible}table{min-width:0;font-size:9px}}
</style></head><body><header><div><h1>Centro de Seguimiento · Compras</h1><p>G&D · Alertas financieras, responsables y trazabilidad</p></div><nav><a href="erp_compras_inicio.php">Área de Compras</a> · <a href="erp_notificaciones.php">Notificaciones</a> · <a href="erp_alertas_compras.php">Análisis de alertas</a></nav></header><main>
<?php if($error):?><div class="error" role="alert"><?=gdca_h($error)?></div><?php endif;?>
<?php if($message):?><div class="msg"><?=gdca_h($message)?></div><?php endif;?>
<div class="box note">Las alertas se revisan <strong>al consultar Compras o Notificaciones</strong>, con actualización limitada a una vez cada 15 minutos; no funcionan en segundo plano si nadie abre el ERP. Las incidencias son referencias a facturas y precios actuales, <strong>no comprueban pérdidas o ahorros reales</strong>. Marcar un aviso como leído no cierra la tarea. La detección no cambia costos, precios ni existencias.</div>
<?php if(!$error):?>
<div class="kpis"><div class="kpi"><strong><?=number_format((int)($stats['pendientes']??0))?></strong><span>Pendientes vigentes</span></div><div class="kpi"><strong><?=number_format((int)($stats['revision']??0))?></strong><span>En revisión</span></div><div class="kpi"><strong><?=number_format((int)($stats['altas']??0))?></strong><span>Prioridad alta abierta</span></div><div class="kpi"><strong><?=number_format((int)($stats['atendidas']??0))?></strong><span>Atendidas</span></div><div class="kpi"><strong><?=number_format((int)($stats['descartadas']??0))?></strong><span>Descartadas</span></div><div class="kpi"><strong><?=gdca_h($stats['horas_promedio']??'—')?></strong><span>Horas promedio hasta cierre</span></div></div>
<section class="box"><h2>Buscar y filtrar casos</h2><form method="get"><div class="filters">
<label class="field">Producto, código o proveedor<input type="search" name="q" value="<?=gdca_h($q)?>" placeholder="Buscar"></label>
<label class="field">Estado<select name="estado"><?php foreach(['ABIERTAS'=>'Pendientes y en revisión','PENDIENTE'=>'Pendiente','EN_REVISION'=>'En revisión','ATENDIDA'=>'Atendida','DESCARTADA'=>'Descartada','TODAS'=>'Todos'] as $k=>$v):?><option value="<?=gdca_h($k)?>" <?=$status===$k?'selected':''?>><?=gdca_h($v)?></option><?php endforeach;?></select></label>
<label class="field">Tipo de alerta<select name="tipo"><?php foreach(['TODAS'=>'Todas','SUBIDA'=>'Aumento de costo','MARGEN_BAJO'=>'Margen bajo','PERDIDA'=>'Venta debajo del costo','OPORTUNIDAD'=>'Posible ahorro','SIN_PRECIO'=>'Sin precio'] as $k=>$v):?><option value="<?=gdca_h($k)?>" <?=$type===$k?'selected':''?>><?=gdca_h($v)?></option><?php endforeach;?></select></label>
<label class="field">Prioridad<select name="nivel"><?php foreach(['TODAS'=>'Todas','ALTA'=>'Alta','MEDIA'=>'Media'] as $k=>$v):?><option value="<?=gdca_h($k)?>" <?=$level===$k?'selected':''?>><?=gdca_h($v)?></option><?php endforeach;?></select></label>
<label class="field">Detección<select name="vigente"><option value="1" <?=$current==='1'?'selected':''?>>Vigentes</option><option value="0" <?=$current==='0'?'selected':''?>>Históricas, ya no detectadas</option><option value="TODAS" <?=$current==='TODAS'?'selected':''?>>Todas</option></select></label>
<label class="field">Responsable<select name="asignado"><option value="0">Todos</option><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=$assignee===(int)$u['id']?'selected':''?>><?=gdca_h($u['nombre'])?></option><?php endforeach;?></select></label>
</div><div class="buttons"><button>Filtrar</button><button class="btn green" name="formato" value="csv">Exportar CSV</button><a class="btn secondary" href="erp_compras_alertas_seguimiento.php">Limpiar</a></div></form>
<form method="post" class="buttons"><input type="hidden" name="csrf" value="<?=gdca_h($csrf)?>"><input type="hidden" name="accion" value="sincronizar"><button type="submit" class="btn secondary">Actualizar detección ahora</button><span class="muted">Última detección: <?=gdca_h($last)?> · parámetros fijos: aumento ≥20%, margen &lt;15%, ahorro ≥8%, facturas de hasta 90 días.</span></form></section>
<section class="box"><h2>Casos encontrados: <?=number_format($count)?></h2><div class="table-scroll"><table><thead><tr><th>Caso</th><th>Producto y sucursal</th><th>Alerta</th><th>Última factura</th><th>Prioridad</th><th>Estado</th><th>Responsable</th><th>Acción</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><b>#<?=(int)$r['id']?></b><div class="muted"><?=gdca_h(substr((string)$r['creado_en'],0,16))?></div></td><td><b><?=gdca_h($r['codigo'])?></b><div class="muted"><?=gdca_h($r['descripcion'])?></div><div class="muted"><?=gdca_h($r['sucursal_nombre'])?></div></td><td><b><?=gdca_h(gd_ac_type_name((string)$r['tipo']))?></b><div class="muted"><?=gdca_h($r['descripcion_alerta'])?></div></td><td><?=gdca_h($r['proveedor'])?><div class="muted"><?=gdca_h($r['fecha_factura'])?> · <?=gdca_h($r['factura_numero'])?></div><div class="muted">Costo <?=gd_cp_money($r['costo'])?> · Precio <?=gd_cp_money($r['precio'])?></div></td><td><span class="tag <?=$r['nivel']==='ALTA'?'high':'mid'?>"><?=gdca_h($r['nivel'])?></span></td><td><span class="tag"><?=gdca_h(str_replace('_',' ',$r['estado']))?></span><?php if(!(int)$r['vigente']):?><div class="muted">Histórica</div><?php endif;?></td><td><?=gdca_h($r['nombre_responsable']?:'Sin asignar')?></td><td><a href="<?=gdca_h(gdca_href(['caso'=>$r['id'],'formato'=>null]))?>">Revisar</a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8">No se encontraron incidencias con estos filtros.</td></tr><?php endif;?></tbody></table></div><div class="pages">Página <?=$page?> de <?=$pages?> <?php if($page>1):?><a href="<?=gdca_h(gdca_href(['pagina'=>$page-1]))?>">Anterior</a><?php endif;?><?php if($page<$pages):?><a href="<?=gdca_h(gdca_href(['pagina'=>$page+1]))?>">Siguiente</a><?php endif;?></div></section>
<?php if($selected):?><section class="box" id="caso"><h2>Seguimiento de incidencia #<?=(int)$selected['id']?> · <?=gdca_h($selected['codigo'])?></h2><div class="detail"><div class="item">Tipo<strong><?=gdca_h(gd_ac_type_name($selected['tipo']))?></strong></div><div class="item">Sucursal<strong><?=gdca_h($selected['sucursal_nombre'])?></strong></div><div class="item">Costo observado<strong><?=gd_cp_money($selected['costo'])?></strong></div><div class="item">Precio público registrado<strong><?=gd_cp_money($selected['precio'])?></strong></div><div class="item">Proveedor<strong><?=gdca_h($selected['proveedor'])?></strong></div><div class="item">Factura<strong><?=gdca_h($selected['factura_numero'])?></strong></div><div class="item">Variación/margen<strong><?=gd_cp_pct_text($selected['variacion']!==null?(float)$selected['variacion']:null)?></strong></div><div class="item">Detección<strong><?=gdca_h($selected['vigente']?'Vigente':'Ya no detectada')?></strong></div></div>
<p class="note"><?=gdca_h($selected['descripcion_alerta'])?></p><div class="buttons"><a class="btn secondary" href="erp_catalogo_historial_costos.php?producto_id=<?=(int)$selected['producto_id']?>">Historial de facturas</a><a class="btn secondary" href="erp_catalogo_comparar_proveedores.php?producto_id=<?=(int)$selected['producto_id']?>">Comparar proveedores</a><a class="btn secondary" href="erp_catalogo_finanzas.php?producto_id=<?=(int)$selected['producto_id']?>">Costos y márgenes</a></div>
<h2 style="margin-top:22px">Asignar, atender o registrar observación</h2><form method="post"><input type="hidden" name="csrf" value="<?=gdca_h($csrf)?>"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="caso_id" value="<?=(int)$selected['id']?>"><div class="filters"><label class="field">Estado<select name="nuevo_estado"><?php foreach(['PENDIENTE'=>'Pendiente','EN_REVISION'=>'En revisión','ATENDIDA'=>'Atendida','DESCARTADA'=>'Descartada'] as $k=>$v):?><option value="<?=gdca_h($k)?>" <?=$selected['estado']===$k?'selected':''?>><?=gdca_h($v)?></option><?php endforeach;?></select></label><label class="field">Responsable del departamento<select name="asignado_a"><option value="0">Sin asignar</option><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=(int)($selected['asignado_a']??0)===(int)$u['id']?'selected':''?>><?=gdca_h($u['nombre'])?></option><?php endforeach;?></select></label></div><label class="field" style="margin-top:12px">Observación, justificación o acción realizada<textarea name="observacion" rows="3" maxlength="1800" placeholder="Observación opcional; el cambio de estado queda registrado en el historial."></textarea></label><div class="buttons"><button>Guardar y registrar movimiento</button></div></form>
<h2 style="margin-top:22px">Historial de seguimiento</h2><?php foreach($history as $entry):?><div class="hist"><strong><?=gdca_h($entry['accion'])?> · <?=gdca_h($entry['usuario_nombre'])?></strong><div class="muted"><?=gdca_h($entry['creado_en'])?> · <?=gdca_h($entry['estado_anterior'])?> → <?=gdca_h($entry['estado_nuevo'])?></div><?php if((int)($entry['asignado_anterior']??0)!==(int)($entry['asignado_nuevo']??0)):?><div class="muted">Responsable: <?=gdca_h($entry['asignado_anterior_nombre']??'Sin asignar')?> → <?=gdca_h($entry['asignado_nuevo_nombre']??'Sin asignar')?></div><?php endif;?><?php if($entry['observacion']!==''):?><p class="note"><?=nl2br(gdca_h($entry['observacion']))?></p><?php endif;?></div><?php endforeach;?><?php if(!$history):?><p class="muted">Todavía no hay movimientos humanos. La detección inicial es automática.</p><?php endif;?></section><?php endif;?>
<?php if($caseId && !$selected):?><div class="box">No se encontró la incidencia #<?=(int)$caseId?>.</div><?php endif;?>
<?php endif;?>
</main>
<script src="assets/js/gyd_excel_reportes.js" defer></script>

<?php /* GYD_NAV_ROLE_V1 */
$__gydNavigationAccount = function_exists('isLoggedIn') && isLoggedIn() && function_exists('currentUser')
    ? (currentUser() ?: []) : [];
$__gydNavigationRole = strtoupper(trim((string)($__gydNavigationAccount['rol_original'] ?? $__gydNavigationAccount['rol'] ?? '')));
$__gydNavigationAllowed = in_array($__gydNavigationRole, ['DIRECCION','ADMINISTRADOR','ADMIN','COMPRAS'], true);
if (!defined('GYD_HIDE_GLOBAL_NAV') && $__gydNavigationAllowed):
$__gydNavigationHome = $__gydNavigationRole === 'COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php';
?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=5">
<script>window.GYD_NAV_ENABLED=true;window.GYD_NAV_HOME=<?=json_encode($__gydNavigationHome, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=5"></script>
<?php endif; unset($__gydNavigationAccount,$__gydNavigationRole,$__gydNavigationAllowed,$__gydNavigationHome); ?>
</body></html>
