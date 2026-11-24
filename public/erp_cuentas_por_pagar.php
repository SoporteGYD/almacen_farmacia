<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cxp.php';
requireLogin();
$user=currentUser()??[];
$uid=(int)($user['id']??0);
if (!$uid || !cxp_compras($user)) { http_response_code(403);exit('Acceso permitido únicamente a Compras, Administrador y Dirección.'); }
$admin=cxp_admin($user);
$db=(new Database())->connect();
$error='';$info=(string)($_SESSION['cxp_flash']??'');unset($_SESSION['cxp_flash']);
$docId=(int)($_GET['id']??0);
$accion='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $newfile=null;
    try {
        cxp_check_csrf();
        $token=cxp_check_form_token();
        $accion=(string)($_POST['accion']??'');
        $docId=(int)($_POST['documento_id']??0);
        if (!in_array($accion,['vincular','solicitar','autorizar','rechazar','cancelar','aplicar'],true)) throw new RuntimeException('Acción no reconocida.');
        $db->beginTransaction();
        if ($accion==='vincular') {
            $facturaId=(int)($_POST['factura_id']??0);
            $total=cxp_money_cents($_POST['total_documento']??'');
            $venc=trim((string)($_POST['fecha_vencimiento']??''));
            $dias=(int)($_POST['dias_credito']??0);
            $obs=trim((string)($_POST['observaciones']??''));
            if ($facturaId<1 || $total<=0 || $total>9999999999999 || !cxp_date($venc) || $dias<0 || $dias>3650 || mb_strlen($obs)>500) throw new RuntimeException('Revisa factura, importe, vencimiento y observaciones.');
            $st=$db->prepare('SELECT f.id,f.proveedor_id,f.sucursal,f.numero_factura,f.fecha_factura FROM erp_facturas f WHERE f.id=? FOR UPDATE');
            $st->execute([$facturaId]);$fact=$st->fetch();
            if (!$fact) throw new RuntimeException('La factura no existe en Compras.');
            if ($venc < $fact['fecha_factura']) throw new RuntimeException('El vencimiento no puede ser anterior a la fecha de factura.');
            $st=$db->prepare('INSERT INTO erp_cxp_documentos(factura_id,proveedor_id,sucursal,total_documento,fecha_vencimiento,dias_credito,observaciones,registro_token,registrado_por) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$facturaId,$fact['proveedor_id'],$fact['sucursal'],cxp_sql_money($total),$venc,$dias,$obs?:null,$token,$uid]);
            $docId=(int)$db->lastInsertId();
            cxp_auditar($db,$docId,null,'ALTA',$uid,'Deuda registrada desde factura '.$fact['numero_factura'].' por '.cxp_sql_money($total).' MXN');
            $mensaje='Cuenta por pagar registrada. Verifica sus datos antes de solicitar abonos.';
        } else {
            if ($docId<1) throw new RuntimeException('Selecciona una cuenta por pagar.');
            $saldo=cxp_saldo($db,$docId,true);
            if ($accion==='solicitar') {
                $tipo=(string)($_POST['tipo']??'');
                if (!in_array($tipo,['PAGO','NOTA_CREDITO'],true)) throw new RuntimeException('Tipo de solicitud inválido.');
                $importe=cxp_money_cents($_POST['importe']??'');
                $motivo=trim((string)($_POST['motivo']??''));
                if ($importe<=0 || $importe>$saldo['saldo']-$saldo['reservado']) throw new RuntimeException('El importe supera el saldo sin solicitudes pendientes.');
                if (mb_strlen($motivo)<8 || mb_strlen($motivo)>500) throw new RuntimeException('Explica el motivo de la solicitud (8 a 500 caracteres).');
                $st=$db->prepare("INSERT INTO erp_cxp_solicitudes(documento_id,tipo,importe,motivo,solicitud_token,solicitado_por) VALUES(?,?,?,?,?,?)");
                $st->execute([$docId,$tipo,cxp_sql_money($importe),$motivo,$token,$uid]);
                $sid=(int)$db->lastInsertId();
                cxp_auditar($db,$docId,$sid,'SOLICITUD',$uid,'Solicita '.$tipo.' de '.cxp_sql_money($importe).' MXN');
                $mensaje='Solicitud enviada para autorización de Dirección.';
            } else {
                $sid=(int)($_POST['solicitud_id']??0);
                $st=$db->prepare('SELECT * FROM erp_cxp_solicitudes WHERE id=? AND documento_id=? FOR UPDATE');
                $st->execute([$sid,$docId]);$sol=$st->fetch();
                if (!$sol) throw new RuntimeException('La solicitud no pertenece a esta cuenta.');
                if ($accion==='autorizar' || $accion==='rechazar') {
                    if (!$admin) throw new RuntimeException('Solo Dirección o Administración pueden autorizar o rechazar.');
                    if ($sol['estado']!=='PENDIENTE') throw new RuntimeException('La solicitud ya fue revisada.');
                    if ((int)$sol['solicitado_por']===$uid) throw new RuntimeException('No puedes autorizar o rechazar tu propia solicitud. Debe revisarla otra persona autorizada.');
                    $coment=trim((string)($_POST['resolucion']??''));
                    if ($accion==='rechazar' && mb_strlen($coment)<8) throw new RuntimeException('El motivo de rechazo debe tener al menos ocho caracteres.');
                    if (mb_strlen($coment)>500) throw new RuntimeException('El comentario es demasiado largo.');
                    $estado=$accion==='autorizar'?'AUTORIZADA':'RECHAZADA';
                    $st=$db->prepare('UPDATE erp_cxp_solicitudes SET estado=?, autorizado_por=?,autorizado_en=NOW(),resolucion=? WHERE id=?');
                    $st->execute([$estado,$uid,$coment?:null,$sid]);
                    cxp_auditar($db,$docId,$sid,$accion==='autorizar'?'AUTORIZACION':'RECHAZO',$uid,$estado.' · '.$coment);
                    $mensaje='Solicitud '.$estado.'. No se ha registrado ningún movimiento de dinero.';
                } elseif ($accion==='cancelar') {
                    if (!in_array($sol['estado'],['PENDIENTE','AUTORIZADA'],true)) throw new RuntimeException('Esta solicitud ya no puede cancelarse.');
                    if (!$admin && (int)$sol['solicitado_por']!==$uid) throw new RuntimeException('No tienes permiso para cancelar esta solicitud.');
                    if ($sol['estado']==='AUTORIZADA' && !$admin) throw new RuntimeException('Solo Dirección puede cancelar una autorización.');
                    $st=$db->prepare("UPDATE erp_cxp_solicitudes SET estado='CANCELADA' WHERE id=?");$st->execute([$sid]);
                    cxp_auditar($db,$docId,$sid,'CANCELACION',$uid,'Solicitud cancelada antes de aplicarse');
                    $mensaje='Solicitud cancelada. Se liberó el importe comprometido.';
                } elseif ($accion==='aplicar') {
                    if (!$admin) throw new RuntimeException('Solo Dirección o Administración pueden registrar pagos y notas de crédito ya realizados.');
                    if ($sol['estado']!=='AUTORIZADA') throw new RuntimeException('Primero debe autorizarse la solicitud.');
                    $amount=cxp_db_cents($sol['importe']);
                    if ($amount<=0 || $amount>$saldo['saldo']) throw new RuntimeException('El saldo cambió. No se puede aplicar esta solicitud; revisa la cuenta.');
                    $fecha=trim((string)($_POST['fecha_real']??''));
                    $medio=(string)($_POST['medio']??'');
                    $referencia=trim((string)($_POST['referencia']??''));
                    $obs=trim((string)($_POST['observaciones']??''));
                    $credito=$sol['tipo']==='NOTA_CREDITO';
                    if (!cxp_date($fecha) || $fecha>date('Y-m-d')) throw new RuntimeException('Captura la fecha real de pago o emisión de la nota.');
                    $allowed=$credito?['NOTA_CREDITO']:['EFECTIVO','TRANSFERENCIA','DEPOSITO','CHEQUE','OTRO'];
                    if (!in_array($medio,$allowed,true)) throw new RuntimeException('Forma de aplicación inválida para esta solicitud.');
                    if (mb_strlen($referencia)<3 || mb_strlen($referencia)>120 || mb_strlen($obs)>500) throw new RuntimeException('Indica el folio/comprobante real (3 a 120 caracteres) y revisa las observaciones.');
                    if ($credito && empty($_FILES['evidencia']['name'])) throw new RuntimeException('Adjunta la nota de crédito del proveedor (PDF o XML).');
                    if (!$credito && $medio!=='EFECTIVO' && empty($_FILES['evidencia']['name'])) throw new RuntimeException('Adjunta el comprobante real del pago bancario.');
                    [$newfile,$mime]=cxp_guardar_evidencia($_FILES['evidencia']??[]);
                    $st=$db->prepare('INSERT INTO erp_cxp_aplicaciones(solicitud_id,documento_id,tipo,importe,fecha_real,medio,referencia,observaciones,evidencia_archivo,evidencia_mime,aplicado_por) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                    $st->execute([$sid,$docId,$sol['tipo'],$sol['importe'],$fecha,$medio,$referencia,$obs?:null,$newfile,$mime,$uid]);
                    $st=$db->prepare("UPDATE erp_cxp_solicitudes SET estado='APLICADA' WHERE id=? AND estado='AUTORIZADA'");$st->execute([$sid]);
                    if ($st->rowCount()!==1) throw new RuntimeException('La solicitud ya cambió de estado.');
                    cxp_auditar($db,$docId,$sid,'APLICACION',$uid,$sol['tipo'].' aplicado: '.cxp_sql_money($amount).' MXN, ref. '.$referencia);
                    $mensaje=$credito?'Nota de crédito registrada y descontada del saldo. No se modificó el inventario.':'Pago realizado registrado. El ERP no transfiere dinero; conserva el registro y el comprobante.';
                }
            }
        }
        $db->commit();unset($_SESSION['cxp_form_token']);
        $_SESSION['cxp_flash']=$mensaje;
        header('Location: erp_cuentas_por_pagar.php'.($docId>0?'?id='.$docId:''),true,303);exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($newfile) @unlink(cxp_storage_dir().DIRECTORY_SEPARATOR.$newfile);
        error_log('CXP G&D: '.$e->getMessage());
        $error=($e instanceof RuntimeException || $e instanceof InvalidArgumentException)?$e->getMessage():'No se pudo guardar la operación. Consulta el registro PHP.';
    }
}

// Consultas de solo lectura: la suma ya aplicada cambia el saldo; solicitudes en espera no lo hacen.
$proveedorFiltro=(int)($_GET['proveedor']??0);
$sucursalFiltro=(string)($_GET['sucursal']??'');
$estadoFiltro=(string)($_GET['estado']??'TODAS');
$permitSuc=['TAPACHULA','TOSCANA','HIDALGO','TUXTLA'];
if (!in_array($sucursalFiltro,$permitSuc,true)) $sucursalFiltro='';
if (!in_array($estadoFiltro,['TODAS','PENDIENTE','PARCIAL','PAGADA','VENCIDA'],true)) $estadoFiltro='TODAS';
$proveedores=[];$facturasDisponibles=[];$documentos=[];$detalle=null;$solicitudes=[];$aplicaciones=[];$auditoria=[];
try {
    $proveedores=$db->query('SELECT id,nombre FROM proveedores ORDER BY nombre')->fetchAll();
    $facturasDisponibles=$db->query("SELECT f.id,f.numero_factura,f.fecha_factura,f.sucursal,p.nombre AS proveedor,COALESCE(fd.subtotal_referencia,0) AS subtotal_referencia
         FROM erp_facturas f JOIN proveedores p ON p.id=f.proveedor_id
         LEFT JOIN (SELECT factura_id, SUM(cantidad_facturada*costo_unitario) AS subtotal_referencia FROM erp_facturas_detalle GROUP BY factura_id) fd ON fd.factura_id=f.id
         LEFT JOIN erp_cxp_documentos c ON c.factura_id=f.id
         WHERE c.id IS NULL ORDER BY f.id DESC LIMIT 200")->fetchAll();
    $sql="SELECT c.*,f.numero_factura,f.fecha_factura,f.folio_interno,f.uuid,p.nombre proveedor,
        COALESCE(a.aplicado,0) aplicado,
        COALESCE(s.reservado,0) reservado
        FROM erp_cxp_documentos c JOIN erp_facturas f ON f.id=c.factura_id JOIN proveedores p ON p.id=c.proveedor_id
        LEFT JOIN (SELECT documento_id,SUM(importe) aplicado FROM erp_cxp_aplicaciones GROUP BY documento_id) a ON a.documento_id=c.id
        LEFT JOIN (SELECT documento_id,SUM(importe) reservado FROM erp_cxp_solicitudes WHERE estado IN ('PENDIENTE','AUTORIZADA') GROUP BY documento_id) s ON s.documento_id=c.id
        WHERE 1=1";
    $args=[];
    if ($proveedorFiltro>0){$sql.=' AND c.proveedor_id=?';$args[]=$proveedorFiltro;}
    if ($sucursalFiltro!==''){$sql.=' AND c.sucursal=?';$args[]=$sucursalFiltro;}
    if ($estadoFiltro==='PAGADA')$sql.=' AND c.total_documento<=COALESCE(a.aplicado,0)';
    elseif($estadoFiltro==='VENCIDA')$sql.=' AND c.fecha_vencimiento<CURDATE() AND c.total_documento>COALESCE(a.aplicado,0)';
    elseif($estadoFiltro==='PENDIENTE')$sql.=' AND COALESCE(a.aplicado,0)=0 AND c.total_documento>COALESCE(a.aplicado,0)';
    elseif($estadoFiltro==='PARCIAL')$sql.=' AND COALESCE(a.aplicado,0)>0 AND c.total_documento>COALESCE(a.aplicado,0)';
    $sql.=' ORDER BY c.fecha_vencimiento ASC,c.id DESC LIMIT 400';
    $st=$db->prepare($sql);$st->execute($args);$documentos=$st->fetchAll();
    if ($docId>0){
       $st=$db->prepare("SELECT c.*,f.numero_factura,f.fecha_factura,f.folio_interno,f.uuid,p.nombre proveedor FROM erp_cxp_documentos c JOIN erp_facturas f ON f.id=c.factura_id JOIN proveedores p ON p.id=c.proveedor_id WHERE c.id=?");$st->execute([$docId]);$detalle=$st->fetch();
       if ($detalle) {
         $st=$db->prepare('SELECT s.*,u.nombre AS solicitante, v.nombre AS revisor FROM erp_cxp_solicitudes s LEFT JOIN usuarios u ON u.id=s.solicitado_por LEFT JOIN usuarios v ON v.id=s.autorizado_por WHERE s.documento_id=? ORDER BY s.id DESC');$st->execute([$docId]);$solicitudes=$st->fetchAll();
         $st=$db->prepare('SELECT a.*,u.nombre AS responsable FROM erp_cxp_aplicaciones a LEFT JOIN usuarios u ON u.id=a.aplicado_por WHERE a.documento_id=? ORDER BY a.id DESC');$st->execute([$docId]);$aplicaciones=$st->fetchAll();
         $st=$db->prepare('SELECT a.*,u.nombre responsable FROM erp_cxp_auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id WHERE a.documento_id=? ORDER BY a.id DESC LIMIT 100');$st->execute([$docId]);$auditoria=$st->fetchAll();
       }
    }
} catch(Throwable $e){error_log('CXP consultas: '.$e->getMessage());$error=$error?:'No fue posible consultar cuentas por pagar. Ejecuta primero la migración SQL y verifica las tablas de facturas.';}

if (($_GET['export']??'')==='csv') {
    header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename="GYD_Cuentas_Por_Pagar.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Factura','Proveedor','Sucursal','Vence','Total','Aplicado','Saldo','Comprometido','Estado']);
    foreach($documentos as $d){$s=cxp_db_cents($d['total_documento'])-cxp_db_cents($d['aplicado']);$estado=$s<=0?'PAGADA':($d['fecha_vencimiento']<date('Y-m-d')?'VENCIDA':(cxp_db_cents($d['aplicado'])>0?'PARCIAL':'PENDIENTE'));
        $safe=static function($v){$t=(string)$v;return preg_match('/^[=+@\-\t\r]/u',$t)?"'".$t:$t;};
        fputcsv($out,[$safe($d['numero_factura']),$safe($d['proveedor']),$d['sucursal'],$d['fecha_vencimiento'],$d['total_documento'],$d['aplicado'],cxp_sql_money($s),$d['reservado'],$estado]);
    }fclose($out);exit;
}
$sumTotal=$sumPagado=$sumSaldo=$sumVencido=$sumReservado=0;
foreach($documentos as $d){$t=cxp_db_cents($d['total_documento']);$ap=cxp_db_cents($d['aplicado']);$s=max(0,$t-$ap);$sumTotal+=$t;$sumPagado+=$ap;$sumSaldo+=$s;$sumReservado+=cxp_db_cents($d['reservado']);if($s>0&&$d['fecha_vencimiento']<date('Y-m-d'))$sumVencido+=$s;}
$money=static fn(int $c):string=>'$'.number_format($c/100,2,'.',',');
$token=cxp_form_token();$csrf=cxp_csrf();
$hidden='<input type="hidden" name="csrf" value="'.cxp_e($csrf).'"><input type="hidden" name="form_token" value="'.cxp_e($token).'">';
cxp_page_head('Cuentas por pagar · ERP G&D');
?>
<?php if ($error):?><div class="alert bad" role="alert"><?=cxp_e($error)?></div><?php endif; ?>
<?php if ($info):?><div class="alert good" role="status"><?=cxp_e($info)?></div><?php endif; ?>
<div class="alert"><b>Control financiero:</b> los totales de deuda se capturan desde la factura fiscal; los costos de las partidas NO incluyen necesariamente impuestos y descuentos. Autorizar un pago no lo ejecuta. Registrar una nota de crédito no mueve existencias de inventario.</div>
<div class="grid" aria-label="Indicadores del filtro actual">
  <div class="metric"><span>Total facturado</span><strong><?=$money($sumTotal)?></strong></div>
  <div class="metric"><span>Abonos y créditos aplicados</span><strong><?=$money($sumPagado)?></strong></div>
  <div class="metric"><span>Saldo pendiente</span><strong><?=$money($sumSaldo)?></strong></div>
  <div class="metric"><span>Saldo vencido</span><strong><?=$money($sumVencido)?></strong></div>
</div>
<p class="muted small">Indicadores calculados sobre los documentos mostrados por el filtro (hasta 400). Solicitudes pendientes/autorizadas: <?=$money($sumReservado)?>; no se restan del saldo hasta su aplicación.</p>
<?php if ($facturasDisponibles): ?>
<section class="card"><h2>Crear cuenta a partir de una factura registrada</h2>
<form method="post" class="two"><?=$hidden?><input type="hidden" name="accion" value="vincular">
 <div><label>Factura de Compras</label><select name="factura_id" required><option value="">Seleccionar factura...</option><?php foreach($facturasDisponibles as $f):?><option value="<?=(int)$f['id']?>"><?=cxp_e($f['numero_factura'].' · '.$f['proveedor'].' · '.$f['sucursal'].' (base de partidas: '.'$'.number_format((float)$f['subtotal_referencia'],2,'.',',').')')?></option><?php endforeach;?></select><p class="muted small">La base de partidas es orientativa. Introduce el TOTAL que realmente debe pagarse según el documento.</p></div>
 <div><label>Total fiscal a pagar (MXN, incluye impuestos)</label><input name="total_documento" type="number" step="0.01" min="0.01" required placeholder="15000.00"></div>
 <div><label>Fecha de vencimiento</label><input name="fecha_vencimiento" type="date" required value="<?=date('Y-m-d',strtotime('+30 days'))?>"></div>
 <div><label>Días de crédito acordados (referencia)</label><input name="dias_credito" type="number" min="0" max="3650" value="30"></div>
 <div><label>Observaciones</label><textarea name="observaciones" maxlength="500" placeholder="Condiciones pactadas, folio o referencia"></textarea></div>
 <div style="align-self:end"><button type="submit" onclick="return confirm('¿El total y el vencimiento coinciden con la factura real?')">Registrar cuenta por pagar</button></div>
</form></section><?php endif; ?>
<section class="card"><div class="end"><h2>Facturas y saldos</h2><a class="btn alt" href="?<?=cxp_e(http_build_query(array_filter(['proveedor'=>$proveedorFiltro,'sucursal'=>$sucursalFiltro,'estado'=>$estadoFiltro,'export'=>'csv'], fn($v)=>$v!==''&&$v!==0)))?>">Exportar CSV</a></div>
<form class="actions" method="get">
<div><label>Proveedor</label><select name="proveedor"><option value="0">Todos</option><?php foreach($proveedores as $p):?><option value="<?=(int)$p['id']?>" <?=$proveedorFiltro===(int)$p['id']?'selected':''?>><?=cxp_e($p['nombre'])?></option><?php endforeach;?></select></div>
<div><label>Sucursal</label><select name="sucursal"><option value="">Todas</option><?php foreach(['TAPACHULA'=>'Tapachula','TOSCANA'=>'Toscana','HIDALGO'=>'Ciudad Hidalgo','TUXTLA'=>'Tuxtla'] as $s=>$v):?><option value="<?=$s?>" <?=$sucursalFiltro===$s?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div><label>Estado</label><select name="estado"><?php foreach(['TODAS'=>'Todas','PENDIENTE'=>'Pendiente','PARCIAL'=>'Pago parcial','PAGADA'=>'Liquidada','VENCIDA'=>'Vencida'] as $v=>$txt):?><option value="<?=$v?>" <?=$estadoFiltro===$v?'selected':''?>><?=$txt?></option><?php endforeach;?></select></div>
<div style="flex:0 0 auto"><button>Consultar</button></div>
</form>
<div class="tablewrap"><table><thead><tr><th>Factura</th><th>Proveedor</th><th>Sucursal</th><th>Vencimiento</th><th class="money">Total</th><th class="money">Aplicado</th><th class="money">Saldo</th><th class="money">Solicitado</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($documentos as $d):$saldoD=max(0,cxp_db_cents($d['total_documento'])-cxp_db_cents($d['aplicado']));$estD=$saldoD===0?'PAGADA':($d['fecha_vencimiento']<date('Y-m-d')?'VENCIDA':(cxp_db_cents($d['aplicado'])>0?'PARCIAL':'PENDIENTE'));?>
<tr><td><b><?=cxp_e($d['numero_factura'])?></b><div class="muted small"><?=cxp_e($d['folio_interno'])?></div></td><td><?=cxp_e($d['proveedor'])?></td><td><?=cxp_e($d['sucursal'])?></td><td><?=cxp_e($d['fecha_vencimiento'])?></td><td class="money"><?=cxp_fmt($d['total_documento'])?></td><td class="money"><?=cxp_fmt($d['aplicado'])?></td><td class="money"><?=$money($saldoD)?></td><td class="money"><?=cxp_fmt($d['reservado'])?></td><td><span class="badge"><?=$estD?></span></td><td><a class="btn alt" href="?id=<?=(int)$d['id']?>">Gestionar</a></td></tr>
<?php endforeach;?><?php if (!$documentos):?><tr><td colspan="10">No hay documentos para el filtro.</td></tr><?php endif;?></tbody></table></div></section>
<?php if ($detalle):$saldoDet=cxp_saldo($db,(int)$detalle['id']);?>
<section class="card"><div class="end"><div><h2>Factura <?=cxp_e($detalle['numero_factura'])?> · <?=cxp_e($detalle['proveedor'])?></h2><p class="muted">Vence: <?=cxp_e($detalle['fecha_vencimiento'])?> · <?=cxp_e($detalle['sucursal'])?> · Saldo: <b><?=$money($saldoDet['saldo'])?></b> · Comprometido: <?=$money($saldoDet['reservado'])?></p></div><a class="btn alt" href="erp_facturas_verificacion.php?id=<?=(int)$detalle['factura_id']?>">Consultar factura</a></div>
<?php if($saldoDet['saldo']-$saldoDet['reservado']>0):?>
<h3>Solicitar abono o nota de crédito</h3><form method="post" class="two"><?=$hidden?><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="documento_id" value="<?=(int)$detalle['id']?>">
<div><label>Operación</label><select name="tipo"><option value="PAGO">Solicitud de pago/abono</option><option value="NOTA_CREDITO">Aplicar nota de crédito del proveedor</option></select></div>
<div><label>Importe (hasta <?=$money($saldoDet['saldo']-$saldoDet['reservado'])?>)</label><input name="importe" type="number" min="0.01" step="0.01" max="<?=cxp_sql_money($saldoDet['saldo']-$saldoDet['reservado'])?>" required></div>
<div><label>Motivo o referencia de solicitud</label><textarea name="motivo" minlength="8" maxlength="500" required placeholder="Motivo y documento que lo respalda"></textarea></div>
<div style="align-self:end"><button type="submit">Solicitar autorización</button></div></form><div class="line"></div>
<?php endif; ?>
<h3>Solicitudes y autorizaciones</h3><div class="tablewrap"><table><thead><tr><th>#</th><th>Tipo</th><th>Importe</th><th>Solicitante</th><th>Estado</th><th>Revisión</th><th>Acción</th></tr></thead><tbody>
<?php foreach($solicitudes as $s):?><tr><td><?=(int)$s['id']?></td><td><?=cxp_e($s['tipo'])?><div class="muted small"><?=cxp_e($s['motivo'])?></div></td><td><?=cxp_fmt($s['importe'])?></td><td><?=cxp_e($s['solicitante']??$s['solicitado_por'])?></td><td><span class="badge"><?=cxp_e($s['estado'])?></span></td><td><?=cxp_e($s['revisor']??'—')?><div class="muted small"><?=cxp_e($s['resolucion']??'')?></div></td><td>
<?php if ($s['estado']==='PENDIENTE' && $admin && (int)$s['solicitado_por']!==$uid):?>
<form method="post" style="margin-bottom:5px"><?=$hidden?><input type="hidden" name="accion" value="autorizar"><input type="hidden" name="documento_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="solicitud_id" value="<?=(int)$s['id']?>"><input name="resolucion" maxlength="500" placeholder="Observación (opcional)"><button style="margin-top:5px" type="submit" onclick="return confirm('¿Autorizar esta solicitud? No registra aún un pago.')">Autorizar</button></form>
<form method="post"><?=$hidden?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="documento_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="solicitud_id" value="<?=(int)$s['id']?>"><input name="resolucion" minlength="8" maxlength="500" required placeholder="Motivo obligatorio"><button class="btn warn" style="margin-top:5px">Rechazar</button></form>
<?php endif; ?>
<?php if ($s['estado']==='AUTORIZADA' && $admin):?>
<details><summary style="cursor:pointer;font-weight:700">Registrar pago/nota realizada</summary>
<form method="post" enctype="multipart/form-data" style="min-width:230px;margin-top:10px"><?=$hidden?><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="documento_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="solicitud_id" value="<?=(int)$s['id']?>">
<label>Fecha real</label><input name="fecha_real" type="date" max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required>
<label>Medio</label><select name="medio"><?php if($s['tipo']==='NOTA_CREDITO'):?><option value="NOTA_CREDITO">Nota de crédito</option><?php else:?><?php foreach(['TRANSFERENCIA'=>'Transferencia','DEPOSITO'=>'Depósito','EFECTIVO'=>'Efectivo','CHEQUE'=>'Cheque','OTRO'=>'Otro'] as $m=>$txt):?><option value="<?=$m?>"><?=$txt?></option><?php endforeach;?><?php endif;?></select>
<label>Referencia/folio comprobable</label><input name="referencia" maxlength="120" required minlength="3" placeholder="Folio bancario o folio de nota">
<label>Archivo de evidencia (PDF, imagen o XML, hasta 5 MB)</label><input type="file" name="evidencia" accept=".pdf,.jpg,.jpeg,.png,.xml,application/pdf,image/*,application/xml,text/xml"><p class="muted small">Obligatorio para transferencias, depósitos, cheques, otros pagos y notas de crédito.</p>
<label>Observaciones (opcional)</label><textarea name="observaciones" maxlength="500"></textarea><button type="submit" onclick="return confirm('Confirma que el dinero efectivamente se pagó o que existe la nota de crédito válida.')">Registrar operación realizada</button></form></details>
<?php endif; ?>
<?php if (in_array($s['estado'],['PENDIENTE','AUTORIZADA'],true) && ($admin || (int)$s['solicitado_por']===$uid)):?>
<form method="post" style="margin-top:5px"><?=$hidden?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="documento_id" value="<?=(int)$detalle['id']?>"><input type="hidden" name="solicitud_id" value="<?=(int)$s['id']?>"><button class="btn alt" type="submit" onclick="return confirm('¿Cancelar esta solicitud aún no aplicada?')">Cancelar solicitud</button></form>
<?php endif; ?>
</td></tr><?php endforeach;?><?php if (!$solicitudes):?><tr><td colspan="7">Todavía no hay solicitudes.</td></tr><?php endif;?></tbody></table></div>
<h3 style="margin-top:20px">Pagos y notas aplicados</h3><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Operación</th><th>Importe</th><th>Medio</th><th>Referencia</th><th>Registró</th><th>Comprobante</th></tr></thead><tbody>
<?php foreach($aplicaciones as $ap):?><tr><td><?=cxp_e($ap['fecha_real'])?></td><td><?=cxp_e($ap['tipo'])?></td><td><?=cxp_fmt($ap['importe'])?></td><td><?=cxp_e($ap['medio'])?></td><td><?=cxp_e($ap['referencia'])?></td><td><?=cxp_e($ap['responsable']??'')?></td><td><?php if($ap['evidencia_archivo']):?><a href="erp_cxp_archivo.php?id=<?=(int)$ap['id']?>" target="_blank" rel="noopener">Abrir archivo</a><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$aplicaciones):?><tr><td colspan="7">No hay aplicaciones registradas.</td></tr><?php endif;?></tbody></table></div>
<details style="margin-top:15px"><summary style="cursor:pointer;font-weight:700">Ver bitácora de auditoría (<?=count($auditoria)?>)</summary><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody><?php foreach($auditoria as $a):?><tr><td><?=cxp_e($a['creado_en'])?></td><td><?=cxp_e($a['evento'])?></td><td><?=cxp_e($a['responsable']??$a['usuario_id'])?></td><td><?=cxp_e($a['detalle'])?></td></tr><?php endforeach;?></tbody></table></div></details>
</section><?php endif;
cxp_page_foot();
