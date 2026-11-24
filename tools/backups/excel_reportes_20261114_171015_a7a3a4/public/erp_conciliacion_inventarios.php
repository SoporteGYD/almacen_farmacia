<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/pos_inteligente.php';
requireLogin();
$usuario=currentUser() ?: [];
$rol=strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
if(!in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true)){
    http_response_code(403);exit('No tienes acceso a la conciliación de inventarios.');
}
function ec_h(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ec_num(mixed $v):string {return number_format((int)$v,0,'.',',');}
$sucursal=(int)($_GET['sucursal']??1);
if(!in_array($sucursal,[1,2,3,4],true))$sucursal=1;
$buscar=mb_substr(trim((string)($_GET['q']??'')),0,90);
$pagina=max(1,min(300,(int)($_GET['pagina']??1)));
$limite=30;
$err='';$filas=[];
$sucursales=[1=>'Ciudad Hidalgo',2=>'Tuxtla Gutiérrez',3=>'Tapachula',4=>'Plaza Toscana'];
try {
    $db=(new Database())->connect();
    $almacen=pi_almacen($sucursal);
    $almacenes=$almacen===1?['CIUDAD HIDALGO','CD HIDALGO']:['TUXTLA','TUXTLA GUTIERREZ','TUXTLA GUTIÉRREZ'];
    $condiciones='p.estado=1 AND (g.producto_id IS NOT NULL OR f.producto_id IS NOT NULL';
    $parametros=[$sucursal,$sucursal];
    if($almacen){
        $or=implode(',',array_fill(0,count($almacenes),'?'));
        $condiciones.=" OR EXISTS (SELECT 1 FROM producto_existencias pe WHERE pe.producto_id=p.id AND UPPER(TRIM(pe.sucursal)) COLLATE utf8mb4_general_ci IN ($or) AND pe.existencia<>0)";
        $parametros=array_merge($parametros,$almacenes);
    }
    $condiciones.=')';
    if($buscar!==''){
        $condiciones.=' AND (p.codigo LIKE ? OR p.codigo_barras LIKE ? OR p.descripcion LIKE ?)';
        $p='%'.$buscar.'%';array_push($parametros,$p,$p,$p);
    }
    $sql='SELECT p.id,p.codigo,p.descripcion FROM productos p LEFT JOIN pos_existencias_farmacia g ON g.producto_id=p.id AND g.sucursal_id=? LEFT JOIN erp_pos_farmacia_control f ON f.producto_id=p.id AND f.sucursal_id=? WHERE '.$condiciones.' ORDER BY p.descripcion,p.id LIMIT '.($limite+1).' OFFSET '.(($pagina-1)*$limite);
    $st=$db->prepare($sql);$st->execute($parametros);$prods=$st->fetchAll(PDO::FETCH_ASSOC);
    $siguiente=count($prods)>$limite;if($siguiente)array_pop($prods);
    foreach($prods as $prod){
        $st=pi_ubicacion($db,$sucursal,(int)$prod['id']);
        $pend=pi_pendientes_entrega($db,$sucursal,(int)$prod['id']);
        $fisico=$st['farmacia']===null?null:(int)$st['farmacia']+(int)$st['almacen'];
        $vendidoPendiente=$pend['vendido_pendiente'];
        $fisicoAjustado=$fisico===null?null:$fisico-$vendidoPendiente;
        $diferencia=$fisicoAjustado===null?null:((int)$st['general']-$fisicoAjustado);
        $estado=!$almacen?'UNICO':($fisico===null?'SIN CONTEO':($vendidoPendiente>(int)$st['almacen']?'REVISAR TICKETS':($diferencia===0?'CUADRADO':'DIFERENCIA')));
        $filas[]=['codigo'=>$prod['codigo'],'descripcion'=>$prod['descripcion'],'id'=>(int)$prod['id'],
            'general'=>(int)$st['general'],'farmacia'=>$st['farmacia'],'almacen'=>(int)$st['almacen'],
            'apartado_bodega'=>max(0,(int)$st['almacen']-(int)$st['almacen_disponible']),
            'disponible_bodega'=>(int)$st['almacen_disponible'],
            'sin_cobro'=>$pend['solicitado_sin_cobro'],'cobrado_pendiente'=>$vendidoPendiente,
            'fisico_ajustado'=>$fisicoAjustado,'diferencia'=>$diferencia,'estado'=>$estado];
    }
    if(($_GET['exportar']??'')==='csv'){
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="conciliacion_'. $sucursal .'_pagina_'. $pagina .'.csv"');
        header('Cache-Control: no-store');
        $fp=fopen('php://output','w');fwrite($fp,"\xEF\xBB\xBF");
        fputcsv($fp,['Código','Producto','POS general','Farmacia','Almacén','Reservado almacén','Disponible almacén','POS sin cobrar','POS cobrado pendiente entrega','Físico ajustado','Diferencia POS - físico','Estado'],';');
        foreach($filas as $r){
            // Protección CSV contra fórmulas inyectadas en descripciones o códigos.
            $textos=[];
            foreach([$r['codigo'],$r['descripcion']] as $txt){$txt=(string)$txt;$textos[]=preg_match('/^[\s]*[=+\-@]/u',$txt)?"'".$txt:$txt;}
            fputcsv($fp,array_merge($textos,[$r['general'],$r['farmacia']??'SIN CONTEO',$r['almacen'],$r['apartado_bodega'],$r['disponible_bodega'],$r['sin_cobro'],$r['cobrado_pendiente'],$r['fisico_ajustado']??'',$r['diferencia']??'',$r['estado']]),';');
        }
        fclose($fp);exit;
    }
} catch(Throwable $e){error_log('ERP conciliación: '.$e->getMessage());$err='No se pudo consultar la conciliación. Comprueba las tablas del POS Inteligente y revisa el registro PHP.';$siguiente=false;}
$qs=['sucursal'=>$sucursal,'q'=>$buscar];
$permisos=true;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ERP G&D | Conciliación de inventarios</title>
<style>
:root{font-family:system-ui,Segoe UI,Arial,sans-serif;color:#1b3554;background:#edf3fa}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(110deg,#123963,#1f65b5);padding:23px max(18px,calc((100% - 1260px)/2));color:white}header h1{margin:0 0 4px;font-size:25px}header p{margin:0;opacity:.88}main{max-width:1290px;margin:22px auto;padding:0 16px}.links{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.btn,button{display:inline-block;padding:10px 13px;border-radius:9px;background:#1559a6;color:white;border:0;text-decoration:none;font:600 14px system-ui;cursor:pointer}.btn.light{background:#e2eaf5;color:#194670}.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 5px 19px #12396312;margin-bottom:15px}.muted{color:#58708a;font-size:13px}.alert{padding:14px;border:1px solid #edca88;background:#fff4de;border-radius:10px}.error{border-color:#f1a3a3;background:#fee}.controls{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.controls label{font-size:13px;font-weight:700;display:block;margin-bottom:5px}.controls input,.controls select{padding:11px;border:1px solid #becfe4;border-radius:8px;font:inherit;min-width:180px}.controls input{min-width:min(290px,85vw)}.summary{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0}.metric{background:#f0f5fc;padding:13px;border-radius:12px;flex:1;min-width:150px}.metric b{display:block;font-size:23px}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;min-width:1190px}th,td{text-align:left;border-bottom:1px solid #e5edf5;padding:12px 10px;font-size:13px}th{background:#174b82;color:white;position:sticky;top:0}td.num{text-align:right;font-variant-numeric:tabular-nums}small{font-size:12px;color:#657e97}.pill{display:inline-block;padding:5px 9px;border-radius:999px;background:#eaf1f8;color:#16446f;font-size:11px;font-weight:800;white-space:nowrap}.pill.bad{background:#fce8e9;color:#a01f37}.pill.warn{background:#fff1d7;color:#855600}.pill.good{background:#e4f6ea;color:#187044}.pager{display:flex;justify-content:space-between;align-items:center;gap:9px;flex-wrap:wrap;margin-top:15px}a{color:#1a5eaa}@media(max-width:700px){header h1{font-size:21px}.card{padding:14px}}
</style></head><body>
<header><h1>Conciliación de inventarios · ERP G&amp;D</h1><p>POS general vs. conteos físicos y tickets pendientes · Investigación y gestión de diferencias</p></header>
<main><div class="links"><a class="btn light" href="dashboard.php">← Inicio</a><a class="btn light" href="erp_reservas_almacen.php?sucursal=<?= $sucursal ?>">🔒 Reservas de Almacén</a><a class="btn" href="erp_conciliacion_gestion.php?sucursal=<?= $sucursal ?>">Registrar conteos y gestionar ajustes</a><a class="btn light" href="pos.php">Punto de Venta</a><a class="btn light" href="logout.php">Cerrar sesión</a></div>
<section class="card"><form class="controls" method="get">
<div><label for="sucursal">Sucursal</label><select name="sucursal" id="sucursal"><?php foreach($sucursales as $sid=>$nom):?><option value="<?= $sid ?>" <?= $sid===$sucursal?'selected':'' ?>><?= ec_h($nom) ?></option><?php endforeach ?></select></div>
<div><label for="q">Código o descripción</label><input id="q" name="q" value="<?= ec_h($buscar) ?>" placeholder="Buscar producto"></div>
<button type="submit">Consultar</button><a class="btn light" href="erp_conciliacion_inventarios.php?sucursal=<?= $sucursal ?>">Limpiar</a>
<a class="btn light" href="?<?= ec_h(http_build_query(array_merge($qs,['pagina'=>$pagina,'exportar'=>'csv']))) ?>">↓ CSV de esta página</a>
</form></section>
<section class="card"><div class="alert"><strong>Cómo se interpreta:</strong> el POS general ya descuenta los tickets cobrados aunque Almacén todavía no haya entregado las piezas. Para comparar correctamente se utiliza <b>Farmacia + Almacén − tickets cobrados pendientes de entrega</b>. Las solicitudes sin cobrar solo reservan unidades, no descuentan el POS. Esta tabla no ajusta saldos directamente; abre «Gestionar» para documentar conteos, solicitar autorizaciones y aplicar ajustes seguros. Para Tapachula y Toscana no hay separación física de inventarios.</div>
<?php if($err):?><p class="alert error" role="alert"><?= ec_h($err) ?></p><?php endif; ?>
<?php $cuadrados=0;$diferentes=0;$sinConteo=0;$enTransito=0;foreach($filas as $r){if($r['estado']==='CUADRADO')$cuadrados++;elseif(in_array($r['estado'],['DIFERENCIA','REVISAR TICKETS'],true))$diferentes++;elseif($r['estado']==='SIN CONTEO')$sinConteo++;$enTransito+=$r['cobrado_pendiente'];} ?>
<div class="summary"><div class="metric">Productos en esta página<b><?=count($filas)?></b></div><div class="metric">Cuadrados<b><?= $cuadrados ?></b></div><div class="metric">Con diferencia<b><?= $diferentes ?></b></div><div class="metric">Sin conteo físico<b><?= $sinConteo ?></b></div><div class="metric">Piezas cobradas sin entregar<b><?=ec_num($enTransito)?></b></div></div>
<div class="scroll"><table><thead><tr><th>Código / Producto</th><th>POS general</th><th>Farmacia</th><th>Almacén</th><th>Reservado almacén</th><th>Disponible almacén</th><th>Solicitudes POS sin cobro</th><th>POS cobrado pendiente entrega</th><th>Físico ajustado</th><th>Diferencia</th><th>Diagnóstico</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($filas as $r): ?><tr><td><b><?= ec_h($r['codigo']) ?></b><br><?=ec_h($r['descripcion'])?></td><td class="num"><?=ec_num($r['general'])?></td><td class="num"><?= $r['farmacia']===null?'—':ec_num($r['farmacia']) ?></td><td class="num"><?=ec_num($r['almacen'])?></td><td class="num"><?=ec_num($r['apartado_bodega'])?></td><td class="num"><?=ec_num($r['disponible_bodega'])?></td><td class="num"><?=ec_num($r['sin_cobro'])?></td><td class="num"><?=ec_num($r['cobrado_pendiente'])?></td><td class="num"><?= $r['fisico_ajustado']===null?'—':ec_num($r['fisico_ajustado'])?></td><td class="num"><?= $r['diferencia']===null?'—':(($r['diferencia']>0?'+':'').ec_num($r['diferencia'])) ?></td><td><span class="pill <?=in_array($r['estado'],['DIFERENCIA','REVISAR TICKETS'],true)?'bad':($r['estado']==='SIN CONTEO'?'warn':($r['estado']==='CUADRADO'?'good':''))?>"><?=ec_h($r['estado'])?></span></td><td><a class="btn" href="erp_conciliacion_gestion.php?sucursal=<?= $sucursal ?>&amp;producto=<?=(int)$r['id']?>">Gestionar</a></td></tr><?php endforeach; ?>
<?php if(!$filas):?><tr><td colspan="12">No hay productos para esta consulta o todavía no tienen inventario cargado.</td></tr><?php endif;?></tbody></table></div>
<div class="pager"><span class="muted">Página <?= $pagina ?> · <?=count($filas)?> productos mostrados</span><div class="links"><?php if($pagina>1):?><a class="btn light" href="?<?=ec_h(http_build_query(array_merge($qs,['pagina'=>$pagina-1])))?>">← Anterior</a><?php endif; ?><?php if(!empty($siguiente)):?><a class="btn light" href="?<?=ec_h(http_build_query(array_merge($qs,['pagina'=>$pagina+1])))?>">Siguiente →</a><?php endif; ?></div></div></section>
</main></body></html>
