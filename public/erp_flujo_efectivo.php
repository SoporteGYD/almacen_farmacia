<?php
/** ERP G&D - Flujo de efectivo y presupuesto (pruebas). Operaciones bancarias: registro manual y auditado. */
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_cxp.php';
requireLogin();
$finUser = currentUser() ?: [];
$finUid = (int)($finUser['id'] ?? 0);
if ($finUid < 1 || !cxp_admin($finUser)) { http_response_code(403); exit('El control bancario está reservado para ADMINISTRADOR y DIRECCIÓN.'); }
$finDb = (new Database())->connect();
$finToday = date('Y-m-d');
$finMesActual = substr($finToday,0,7);
$finMes = (string)($_GET['mes'] ?? $finMesActual);
if (!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D',$finMes)) $finMes=$finMesActual;
$finInicio = $finMes.'-01';
$finFin = date('Y-m-t',strtotime($finInicio));
$finSucursales = ['GENERAL'=>'General','TAPACHULA'=>'Tapachula','TOSCANA'=>'Plaza Toscana','HIDALGO'=>'Ciudad Hidalgo','TUXTLA'=>'Tuxtla Gutiérrez'];
$finCategoriasIngreso = ['DEPOSITO_VENTAS'=>'Depósito de ventas (POS)','COBRO'=>'Cobro recibido','APORTACION'=>'Aportación de capital','OTRO_INGRESO'=>'Otro ingreso'];
$finCategoriasEgreso = ['PROVEEDORES'=>'Proveedores (vinculado a CxP)','NOMINA'=>'Nómina','OPERACION'=>'Gastos de operación','IMPUESTOS'=>'Impuestos','OTRO_EGRESO'=>'Otro egreso'];
$finCategorias = $finCategoriasIngreso+$finCategoriasEgreso;
$finEsc = static fn(mixed $v):string => cxp_e($v);
$finFmt = static fn(int $c):string => '$'.number_format($c/100,2,'.',',');
$finError='';$finOk=(string)($_SESSION['fin_flash']??'');unset($_SESSION['fin_flash']);
if (empty($_SESSION['fin_csrf'])) $_SESSION['fin_csrf']=bin2hex(random_bytes(24));
if (empty($_SESSION['fin_token'])) $_SESSION['fin_token']=bin2hex(random_bytes(16));

function fin_audit(PDO $db,string $ev,string $entity,int $id,int $uid,string $description):void {
    $q=$db->prepare('INSERT INTO erp_fin_auditoria(evento,entidad,entidad_id,usuario_id,detalle) VALUES(?,?,?,?,?)');
    $q->execute([$ev,$entity,$id,$uid,mb_substr($description,0,600)]);
}
function fin_cuenta(PDO $db,int $id,bool $lock=false):array {
    $s=$db->prepare('SELECT * FROM erp_fin_cuentas WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$v=$s->fetch(PDO::FETCH_ASSOC);
    if (!$v)throw new RuntimeException('No existe esa cuenta bancaria.');
    return $v;
}
function fin_balance(PDO $db,array $cuenta,?string $hasta=null):int {
    $hasta=$hasta??date('Y-m-d');
    $base=cxp_db_cents($cuenta['saldo_inicial']);
    if ($hasta < $cuenta['fecha_corte']) return 0;
    $s=$db->prepare("SELECT tipo,COALESCE(SUM(importe),0) total FROM erp_fin_movimientos WHERE cuenta_id=? AND estado='REGISTRADO' AND fecha_movimiento BETWEEN ? AND ? GROUP BY tipo");
    $s->execute([(int)$cuenta['id'],$cuenta['fecha_corte'],$hasta]);
    foreach($s as $row) $base += ($row['tipo']==='INGRESO'?1:-1)*cxp_db_cents($row['total']);
    return $base;
}
function fin_revisar_fecha(string $s,string $menor,string $mayor):void {
    if(!cxp_date($s)||$s<$menor||$s>$mayor)throw new RuntimeException('Fecha inválida o fuera del periodo permitido.');
}
function fin_sql_signed(int $c):string {
    return ($c<0?'-':'').sprintf('%d.%02d',intdiv(abs($c),100),abs($c)%100);
}
function fin_money_positive(mixed $v):int {
    $n=cxp_money_cents($v);
    if($n<=0 || $n>9999999999999)throw new RuntimeException('El importe debe ser mayor a cero.');
    return $n;
}
function fin_str(mixed $v,int $min,int $max,string $nombre):string {
    $r=trim((string)$v);
    if(mb_strlen($r)<$min||mb_strlen($r)>$max)throw new RuntimeException($nombre.' debe contener entre '.$min.' y '.$max.' caracteres.');
    return $r;
}
function fin_rechazar_periodo_conciliado(PDO $db,int $cuenta,string $fecha):void {
    $s=$db->prepare('SELECT MAX(fecha_estado) FROM erp_fin_conciliaciones WHERE cuenta_id=? AND diferencia=0.00');$s->execute([$cuenta]);$fechaConc=$s->fetchColumn();
    if($fechaConc && $fecha<=$fechaConc)throw new RuntimeException('Hay una conciliación cuadrada (diferencia cero) en esta fecha o después. Registra el movimiento en una fecha posterior, o revisa la conciliación con Dirección.');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals((string)$_SESSION['fin_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Sesión del formulario vencida. Recarga la página.');
        if (!hash_equals((string)$_SESSION['fin_token'],(string)($_POST['token']??''))) throw new RuntimeException('Acción repetida o formulario vencido. Actualiza la página.');
        $action=(string)($_POST['accion']??'');
        $finDb->beginTransaction();
        switch($action) {
            case 'crear_cuenta': {
                $nombre=fin_str($_POST['nombre']??'',3,100,'Nombre de la cuenta');
                $banco=fin_str($_POST['banco']??'',2,100,'Banco');
                $cuatro=trim((string)($_POST['terminacion']??''));
                if(!preg_match('/^\d{4}$/D',$cuatro))throw new RuntimeException('Captura solamente los últimos cuatro dígitos, nunca el número completo de cuenta.');
                $suc=(string)($_POST['sucursal']??'GENERAL');if(!isset($finSucursales[$suc]))throw new RuntimeException('Sucursal inválida.');
                $saldo=cxp_money_cents($_POST['saldo_inicial']??'');
                if($saldo<0)throw new RuntimeException('El saldo inicial no puede ser negativo.');
                $fecha=(string)($_POST['fecha_corte']??'');fin_revisar_fecha($fecha,'2020-01-01',$finToday);
                $q=$finDb->prepare('INSERT INTO erp_fin_cuentas(nombre,banco,terminacion,sucursal,fecha_corte,saldo_inicial,creado_por) VALUES(?,?,?,?,?,?,?)');
                $q->execute([$nombre,$banco,$cuatro,$suc,$fecha,cxp_sql_money($saldo),$finUid]);
                fin_audit($finDb,'CUENTA_CREADA','CUENTA',(int)$finDb->lastInsertId(),$finUid,'Saldo de apertura confirmado por el usuario, a fecha '.$fecha);
                $finOk='Cuenta registrada. El saldo inicial es manual: debe ser el saldo al INICIO de la fecha elegida, antes de movimientos capturados ese día.';
                break;
            }
            case 'movimiento': {
                $cuenta=fin_cuenta($finDb,(int)($_POST['cuenta_id']??0),true);
                if(!(int)$cuenta['activa'])throw new RuntimeException('La cuenta está inactiva.');
                $tipo=(string)($_POST['tipo']??'');if(!in_array($tipo,['INGRESO','EGRESO'],true))throw new RuntimeException('Tipo inválido.');
                $cat=(string)($_POST['categoria']??'');
                if(!isset(($tipo==='INGRESO'?$finCategoriasIngreso:$finCategoriasEgreso)[$cat]))throw new RuntimeException('La categoría no corresponde al tipo de movimiento.');
                $sucursal=(string)($_POST['sucursal']??'GENERAL');if(!isset($finSucursales[$sucursal]))throw new RuntimeException('Sucursal inválida.');
                $valor=fin_money_positive($_POST['importe']??'');
                $fecha=(string)($_POST['fecha_movimiento']??'');fin_revisar_fecha($fecha,$cuenta['fecha_corte'],$finToday);
                fin_rechazar_periodo_conciliado($finDb,(int)$cuenta['id'],$fecha);
                $ref=fin_str($_POST['referencia']??'',3,120,'Referencia');
                $concept=fin_str($_POST['concepto']??'',8,500,'Concepto');
                $appId=(int)($_POST['cxp_aplicacion_id']??0);
                if($cat==='PROVEEDORES' && $appId<=0)throw new RuntimeException('Para proveedores, selecciona un pago que YA está registrado en Cuentas por Pagar.');
                if($appId>0) {
                    if($tipo!=='EGRESO'||$cat!=='PROVEEDORES')throw new RuntimeException('Un pago de Cuentas por Pagar debe vincularse como egreso de proveedores.');
                    $q=$finDb->prepare("SELECT id,importe,fecha_real,medio FROM erp_cxp_aplicaciones WHERE id=? AND tipo='PAGO' FOR UPDATE");$q->execute([$appId]);$app=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$app || !in_array($app['medio'],['TRANSFERENCIA','DEPOSITO','CHEQUE','OTRO'],true))throw new RuntimeException('Elige una aplicación bancaria válida, no efectivo ni nota de crédito.');
                    if(cxp_db_cents($app['importe'])!==$valor)throw new RuntimeException('El importe debe ser exactamente igual al pago aplicado en Cuentas por Pagar.');
                    if($fecha<$app['fecha_real'])throw new RuntimeException('El movimiento bancario no puede ser anterior al pago registrado.');
                    $q=$finDb->prepare('SELECT id FROM erp_fin_movimientos WHERE cxp_aplicacion_id=?');$q->execute([$appId]);
                    if($q->fetchColumn())throw new RuntimeException('Ese pago de proveedor ya está relacionado con un movimiento bancario.');
                }
                $q=$finDb->prepare('INSERT INTO erp_fin_movimientos(cuenta_id,tipo,categoria,sucursal,importe,fecha_movimiento,referencia,concepto,cxp_aplicacion_id,token,solicitado_por) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([(int)$cuenta['id'],$tipo,$cat,$sucursal,cxp_sql_money($valor),$fecha,$ref,$concept,$appId?:null,(string)$_SESSION['fin_token'],$finUid]);
                $id=(int)$finDb->lastInsertId();fin_audit($finDb,'MOV_SOLICITADO','MOVIMIENTO',$id,$finUid,$tipo.' '.$ref.'; sin impacto en saldo');
                $finOk='Movimiento solicitado. No afecta el saldo bancario hasta autorizarlo y confirmar que realmente ocurrió.';
                break;
            }
            case 'autorizar':
            case 'rechazar':
            case 'confirmar':
            case 'cancelar': {
                $id=(int)($_POST['movimiento_id']??0);
                $q=$finDb->prepare('SELECT * FROM erp_fin_movimientos WHERE id=? FOR UPDATE');$q->execute([$id]);$m=$q->fetch(PDO::FETCH_ASSOC);
                if(!$m)throw new RuntimeException('Movimiento no encontrado.');
                $cuenta=fin_cuenta($finDb,(int)$m['cuenta_id'],true);
                $motivo=trim((string)($_POST['motivo']??''));if(mb_strlen($motivo)>500)throw new RuntimeException('Motivo demasiado largo.');
                if($action==='autorizar'||$action==='rechazar') {
                    if($m['estado']!=='PENDIENTE')throw new RuntimeException('El movimiento ya fue revisado.');
                    if((int)$m['solicitado_por']===$finUid)throw new RuntimeException('No puedes aprobar ni rechazar tu propia solicitud. Usa otra cuenta administrativa.');
                    if($action==='rechazar'&&mb_strlen($motivo)<8)throw new RuntimeException('Indica el motivo de rechazo.');
                    $estado=$action==='autorizar'?'AUTORIZADO':'RECHAZADO';
                    $q=$finDb->prepare('UPDATE erp_fin_movimientos SET estado=?,autorizado_por=?,autorizado_en=NOW(),resolucion=?,cxp_aplicacion_id=IF(?=\'RECHAZADO\',NULL,cxp_aplicacion_id) WHERE id=?');
                    $q->execute([$estado,$finUid,$motivo?:null,$estado,$id]);
                    fin_audit($finDb,$estado,'MOVIMIENTO',$id,$finUid,$motivo?:'Revisión de movimiento');
                    $finOk=$estado==='AUTORIZADO'?'Movimiento autorizado. Aún no cambia el saldo; confirma únicamente cuando se refleje en el banco.':'Solicitud rechazada; no afectó el saldo.';
                } elseif($action==='cancelar') {
                    if(!in_array($m['estado'],['PENDIENTE','AUTORIZADO'],true))throw new RuntimeException('Solo pueden cancelarse movimientos todavía no registrados.');
                    if(mb_strlen($motivo)<8)throw new RuntimeException('Describe el motivo de cancelación.');
                    $q=$finDb->prepare("UPDATE erp_fin_movimientos SET estado='CANCELADO',resolucion=?,cxp_aplicacion_id=NULL WHERE id=?");$q->execute([$motivo,$id]);
                    fin_audit($finDb,'CANCELADO','MOVIMIENTO',$id,$finUid,$motivo);
                    $finOk='Movimiento cancelado. No afectó el saldo.';
                } else {
                    if($m['estado']!=='AUTORIZADO')throw new RuntimeException('El movimiento debe estar autorizado antes de registrarse.');
                    if(!(int)$cuenta['activa'])throw new RuntimeException('Cuenta inactiva.');
                    fin_rechazar_periodo_conciliado($finDb,(int)$cuenta['id'],$m['fecha_movimiento']);
                    if($m['fecha_movimiento']>$finToday)throw new RuntimeException('No puedes confirmar movimientos futuros.');
                    if($m['tipo']==='EGRESO' && (fin_balance($finDb,$cuenta)<cxp_db_cents($m['importe']) || fin_balance($finDb,$cuenta,$m['fecha_movimiento'])<cxp_db_cents($m['importe'])))throw new RuntimeException('El saldo del ERP resulta insuficiente en la fecha del egreso o al día de hoy. Concilia antes de confirmar.');
                    $indice=hash('sha256',$cuenta['id'].'|'.$m['fecha_movimiento'].'|'.$m['tipo'].'|'.mb_strtolower(trim((string)$m['referencia'])));
                    $dup=$finDb->prepare('SELECT id FROM erp_fin_movimientos WHERE indice_registro=?');$dup->execute([$indice]);
                    if($dup->fetchColumn())throw new RuntimeException('Ya hay un asiento registrado con esa referencia, tipo y fecha en la misma cuenta. Verifica duplicados.');
                    $q=$finDb->prepare("UPDATE erp_fin_movimientos SET estado='REGISTRADO',indice_registro=?,registrado_por=?,registrado_en=NOW() WHERE id=? AND estado='AUTORIZADO'");$q->execute([$indice,$finUid,$id]);
                    if($q->rowCount()!==1)throw new RuntimeException('La operación ya fue registrada.');
                    fin_audit($finDb,'REGISTRADO','MOVIMIENTO',$id,$finUid,'Confirmado como movimiento real en el banco. Ref '.$m['referencia']);
                    $finOk='Movimiento confirmado: ahora se refleja UNA vez en el saldo del ERP. No realiza transferencias bancarias.';
                }
                break;
            }
            case 'presupuesto': {
                $per=(string)($_POST['periodo']??'');
                if(!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D',$per))throw new RuntimeException('Mes de presupuesto inválido.');
                $suc=(string)($_POST['sucursal']??'');if(!isset($finSucursales[$suc]))throw new RuntimeException('Sucursal inválida.');
                $cat=(string)($_POST['categoria']??'');if(!isset($finCategoriasEgreso[$cat]))throw new RuntimeException('Selecciona una categoría de gasto.');
                $importe=cxp_money_cents($_POST['limite']??'');if($importe<0)throw new RuntimeException('Presupuesto inválido.');
                $q=$finDb->prepare('INSERT INTO erp_fin_presupuestos(periodo,sucursal,categoria,limite,actualizado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE limite=VALUES(limite),actualizado_por=VALUES(actualizado_por)');
                $q->execute([$per,$suc,$cat,cxp_sql_money($importe),$finUid]);
                $q=$finDb->prepare('SELECT id FROM erp_fin_presupuestos WHERE periodo=? AND sucursal=? AND categoria=?');$q->execute([$per,$suc,$cat]);
                fin_audit($finDb,'PRESUPUESTO','PRESUPUESTO',(int)$q->fetchColumn(),$finUid,$per.' '.$suc.' '.$cat.' '.cxp_sql_money($importe));
                $finOk='Presupuesto guardado. Es una referencia de control; no bloquea pagos por sí solo.';
                break;
            }
            case 'pronostico': {
                $cuenta=fin_cuenta($finDb,(int)($_POST['cuenta_id']??0));if(!(int)$cuenta['activa'])throw new RuntimeException('Cuenta inactiva.');
                $tipo=(string)($_POST['tipo']??'');if(!in_array($tipo,['INGRESO','EGRESO'],true))throw new RuntimeException('Tipo inválido.');
                $importe=fin_money_positive($_POST['importe']??'');$fecha=(string)($_POST['fecha_prevista']??'');
                fin_revisar_fecha($fecha,date('Y-m-d',strtotime('+1 day')),date('Y-m-d',strtotime('+1 year')));
                $concept=fin_str($_POST['concepto']??'',8,300,'Concepto');
                $q=$finDb->prepare('INSERT INTO erp_fin_pronosticos(cuenta_id,tipo,fecha_prevista,importe,concepto,token,creado_por) VALUES(?,?,?,?,?,?,?)');
                $q->execute([(int)$cuenta['id'],$tipo,$fecha,cxp_sql_money($importe),$concept,(string)$_SESSION['fin_token'],$finUid]);
                fin_audit($finDb,'PREVISION','PRONOSTICO',(int)$finDb->lastInsertId(),$finUid,$tipo.' '.$fecha.' '.$concept);
                $finOk='Previsión creada; no afecta el saldo real ni genera movimientos en POS/Cuentas por Pagar.';
                break;
            }
            case 'cancelar_pronostico': {
                $id=(int)($_POST['pronostico_id']??0);
                $q=$finDb->prepare("UPDATE erp_fin_pronosticos SET estado='CANCELADO',cancelado_por=?,cancelado_en=NOW() WHERE id=? AND estado='ACTIVO'");$q->execute([$finUid,$id]);
                if($q->rowCount()!==1)throw new RuntimeException('No se puede cancelar esa previsión.');
                fin_audit($finDb,'CANCELADA','PRONOSTICO',$id,$finUid,'Pronóstico retirado');
                $finOk='Previsión cancelada.';break;
            }
            case 'asignar_tes': {
                $programa=(int)($_POST['programacion_id']??0);$cuenta=fin_cuenta($finDb,(int)($_POST['cuenta_id']??0));
                if(!(int)$cuenta['activa'])throw new RuntimeException('Cuenta inactiva.');
                $q=$finDb->prepare('SELECT p.estado,s.tipo,s.estado estado_solicitud FROM erp_tes_programaciones p JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id WHERE p.id=? FOR UPDATE');
                $q->execute([$programa]);$p=$q->fetch(PDO::FETCH_ASSOC);
                if(!$p || $p['estado']!=='AUTORIZADA' || $p['tipo']!=='PAGO' || $p['estado_solicitud']!=='AUTORIZADA')throw new RuntimeException('La programación ya no está autorizada ni pendiente de pago.');
                $q=$finDb->prepare('INSERT INTO erp_fin_tes_cuentas(programacion_id,cuenta_id,asignado_por) VALUES(?,?,?) ON DUPLICATE KEY UPDATE cuenta_id=VALUES(cuenta_id),asignado_por=VALUES(asignado_por),asignado_en=NOW()');
                $q->execute([$programa,(int)$cuenta['id'],$finUid]);
                fin_audit($finDb,'ASIGNACION','TESORERIA',$programa,$finUid,'Cuenta de salida planificada: #'.$cuenta['id']);
                $finOk='Cuenta asignada al compromiso. No se descontó dinero. Solo al confirmar un movimiento bancario se modifica el saldo.';break;
            }
            case 'conciliacion': {
                $cuenta=fin_cuenta($finDb,(int)($_POST['cuenta_id']??0),true);
                $fecha=(string)($_POST['fecha_estado']??'');fin_revisar_fecha($fecha,$cuenta['fecha_corte'],$finToday);
                $texto=trim((string)($_POST['saldo_bancario']??''));
                if(!preg_match('/^-?(?:0|[1-9]\d{0,10})(?:\.\d{1,2})?$/D',$texto))throw new RuntimeException('Saldo bancario inválido.');
                $saldoB=cxp_db_cents($texto);
                $observ=fin_str($_POST['observaciones']??'',8,500,'Observaciones de conciliación');
                $saldoE=fin_balance($finDb,$cuenta,$fecha);
                $q=$finDb->prepare('INSERT INTO erp_fin_conciliaciones(cuenta_id,fecha_estado,saldo_bancario,saldo_erp,diferencia,observaciones,conciliado_por) VALUES(?,?,?,?,?,?,?)');
                $q->execute([(int)$cuenta['id'],$fecha,fin_sql_signed($saldoB),fin_sql_signed($saldoE),fin_sql_signed($saldoB-$saldoE),$observ,$finUid]);
                fin_audit($finDb,'CONCILIACION','CONCILIACION',(int)$finDb->lastInsertId(),$finUid,'Diferencia '.fin_sql_signed($saldoB-$saldoE));
                $finOk='Conciliación guardada como fotografía histórica. Las diferencias NO generan ajustes automáticos. Un periodo queda cerrado solo si la diferencia es cero.';break;
            }
            default: throw new RuntimeException('Acción no reconocida.');
        }
        $finDb->commit();$_SESSION['fin_token']=bin2hex(random_bytes(16));$_SESSION['fin_flash']=$finOk;
        header('Location: erp_flujo_efectivo.php?mes='.rawurlencode($finMes),true,303);exit;
    } catch(Throwable $e) {
        if($finDb->inTransaction())$finDb->rollBack();
        error_log('ERP GYD FINANZAS: '.$e->getMessage());
        $finError=$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar. Revisa el log de PHP y que hayas importado el SQL.';
    }
}

$finCuentas=[];$finMovimientos=[];$finPresupuestos=[];$finPrevisiones=[];$finProgramaciones=[];$finConciliaciones=[];$finPos=[];$finAudit=[];$finApps=[];
$finTotales=['saldos'=>0,'egresos'=>0,'ingresos'=>0,'autorizados'=>0,'proyectados'=>0,'programados'=>0];
$finCompromisos=0;$finProyectado=0;$finAdvertencia='';
try {
    $finCuentas=$finDb->query('SELECT * FROM erp_fin_cuentas ORDER BY activa DESC,nombre,id')->fetchAll(PDO::FETCH_ASSOC);
    foreach($finCuentas as &$c) {$c['saldo_cents']=fin_balance($finDb,$c);$finTotales['saldos']+=$c['saldo_cents'];} unset($c);
    $q=$finDb->prepare('SELECT m.*,c.nombre cuenta,u.nombre solicitante,a.nombre aprobador FROM erp_fin_movimientos m JOIN erp_fin_cuentas c ON c.id=m.cuenta_id LEFT JOIN usuarios u ON u.id=m.solicitado_por LEFT JOIN usuarios a ON a.id=m.autorizado_por ORDER BY m.id DESC LIMIT 200');$q->execute();$finMovimientos=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$finDb->prepare("SELECT tipo,COALESCE(SUM(importe),0) total FROM erp_fin_movimientos WHERE estado='REGISTRADO' AND fecha_movimiento BETWEEN ? AND ? GROUP BY tipo");$q->execute([$finInicio,$finFin]);
    foreach($q as $r) $finTotales[$r['tipo']==='INGRESO'?'ingresos':'egresos']=cxp_db_cents($r['total']);
    $q=$finDb->prepare('SELECT p.*,u.nombre editor FROM erp_fin_presupuestos p LEFT JOIN usuarios u ON u.id=p.actualizado_por WHERE p.periodo=? ORDER BY p.sucursal,p.categoria');$q->execute([$finMes]);$finPresupuestos=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$finDb->prepare("SELECT sucursal,categoria,SUM(importe) gastado FROM erp_fin_movimientos WHERE tipo='EGRESO' AND estado='REGISTRADO' AND fecha_movimiento BETWEEN ? AND ? GROUP BY sucursal,categoria");$q->execute([$finInicio,$finFin]);$finGastado=[];
    foreach($q as $r) $finGastado[$r['sucursal'].'|'.$r['categoria']]=cxp_db_cents($r['gastado']);
    $finPrevisiones=$finDb->query("SELECT p.*,c.nombre cuenta FROM erp_fin_pronosticos p JOIN erp_fin_cuentas c ON c.id=p.cuenta_id WHERE p.fecha_prevista BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 60 DAY) AND p.estado='ACTIVO' ORDER BY p.fecha_prevista,p.id LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $finFinProj=date('Y-m-d',strtotime('+30 days'));
    $q=$finDb->prepare("SELECT tipo,COALESCE(SUM(importe),0) monto FROM erp_fin_pronosticos WHERE estado='ACTIVO' AND fecha_prevista>? AND fecha_prevista<=? GROUP BY tipo");
    $q->execute([$finToday,$finFinProj]);
    foreach($q as $p)$finTotales['proyectados']+=($p['tipo']==='INGRESO'?1:-1)*cxp_db_cents($p['monto']);
    $q=$finDb->query("SELECT t.programacion_id,t.cuenta_id,c.nombre cuenta,p.fecha_programada,p.prioridad,s.importe,s.estado estado_solicitud,d.sucursal,pr.nombre proveedor, f.numero_factura
        FROM erp_fin_tes_cuentas t JOIN erp_tes_programaciones p ON p.id=t.programacion_id JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id
        JOIN erp_cxp_documentos d ON d.id=s.documento_id JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
        JOIN erp_fin_cuentas c ON c.id=t.cuenta_id WHERE p.estado='AUTORIZADA' AND s.estado='AUTORIZADA' AND s.tipo='PAGO' ORDER BY p.fecha_programada LIMIT 250");
    $finProgramaciones=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$finDb->prepare("SELECT COALESCE(SUM(s.importe),0) total FROM erp_fin_tes_cuentas t JOIN erp_tes_programaciones p ON p.id=t.programacion_id JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id WHERE p.estado='AUTORIZADA' AND s.estado='AUTORIZADA' AND s.tipo='PAGO' AND p.fecha_programada<=?");
    $q->execute([$finFinProj]);$finTotales['programados']=cxp_db_cents($q->fetchColumn());
    $q=$finDb->query("SELECT p.id,p.fecha_programada,p.prioridad,s.importe,d.sucursal,pr.nombre proveedor,f.numero_factura
        FROM erp_tes_programaciones p JOIN erp_cxp_solicitudes s ON s.id=p.solicitud_id JOIN erp_cxp_documentos d ON d.id=s.documento_id
        JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
        LEFT JOIN erp_fin_tes_cuentas t ON t.programacion_id=p.id
        WHERE p.estado='AUTORIZADA' AND s.estado='AUTORIZADA' AND s.tipo='PAGO' AND t.programacion_id IS NULL ORDER BY p.fecha_programada LIMIT 60");
    $finSinAsignar=$q->fetchAll(PDO::FETCH_ASSOC);
    $finApps=$finDb->query("SELECT a.id,a.importe,a.fecha_real,a.medio,a.referencia,pr.nombre proveedor,f.numero_factura
        FROM erp_cxp_aplicaciones a JOIN erp_cxp_documentos d ON d.id=a.documento_id
        JOIN erp_facturas f ON f.id=d.factura_id JOIN proveedores pr ON pr.id=d.proveedor_id
        LEFT JOIN erp_fin_movimientos m ON m.cxp_aplicacion_id=a.id
        WHERE a.tipo='PAGO' AND a.medio IN ('TRANSFERENCIA','DEPOSITO','CHEQUE','OTRO') AND m.id IS NULL ORDER BY a.id DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $finConciliaciones=$finDb->query('SELECT r.*,c.nombre cuenta,u.nombre usuario FROM erp_fin_conciliaciones r JOIN erp_fin_cuentas c ON c.id=r.cuenta_id LEFT JOIN usuarios u ON u.id=r.conciliado_por ORDER BY r.id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    $finAudit=$finDb->query('SELECT a.*,u.nombre usuario FROM erp_fin_auditoria a LEFT JOIN usuarios u ON u.id=a.usuario_id ORDER BY a.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    try {
        $q=$finDb->prepare("SELECT v.sucursal_id,p.metodo,SUM(p.monto) total FROM pos_pagos p JOIN pos_ventas v ON v.id=p.venta_id WHERE v.estado='PAGADA' AND p.estado='CONFIRMADO' AND v.pagada_en>=? AND v.pagada_en < DATE_ADD(?,INTERVAL 1 DAY) GROUP BY v.sucursal_id,p.metodo ORDER BY v.sucursal_id,p.metodo");
        $q->execute([$finInicio,$finFin]);$finPos=$q->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable $e){$finAdvertencia='No se pudo leer el resumen POS: puede que su esquema sea anterior. No afecta el control de bancos.';error_log('ERP fin POS: '.$e->getMessage());}
} catch(Throwable $e) {error_log('ERP fin consultas: '.$e->getMessage());$finError=$finError?:'No se pudieron consultar las tablas financieras. Ejecuta sql/erp_flujo_efectivo_bancos.sql después de instalar Cuentas por Pagar y Tesorería.';}
$finProyectado=$finTotales['saldos']+$finTotales['proyectados']-$finTotales['programados'];
if (isset($_GET['csv']) && $_GET['csv']==='movimientos' && !$finError) {
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="gyd_movimientos_bancarios.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['ID','Cuenta','Fecha','Tipo','Categoría','Sucursal','Importe','Estado','Referencia','Concepto','CxP aplicación'],';');
    $protect=static function (mixed $v):string {$s=(string)$v;return preg_match('/^[\s]*[=+@\-\t\r]/u',$s)?"'".$s:$s;};
    foreach($finMovimientos as $m)fputcsv($out,array_map($protect,[$m['id'],$m['cuenta'],$m['fecha_movimiento'],$m['tipo'],$m['categoria'],$m['sucursal'],$m['importe'],$m['estado'],$m['referencia'],$m['concepto'],$m['cxp_aplicacion_id']]),';');
    fclose($out);exit;
}

cxp_page_head('Flujo de Efectivo · Bancos y Presupuestos');
?>
<style>
.fin-note{border:1px solid #bad1e8;background:#edf6ff;border-radius:12px;padding:11px 14px;line-height:1.55;margin-bottom:12px}
.fin-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px}.fin-tabs a{border:1px solid #bfd0e5;border-radius:100px;padding:8px 13px;background:white;font-weight:750;font-size:12px}
.fin-tabs a:hover{background:#e5f0ff}.fin-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.fin-field{min-width:0}details.fin-card>summary{font-weight:800;font-size:16px;cursor:pointer;color:#164c82;list-style:none}details.fin-card>summary:after{content:' ▾'}details.fin-card[open]>summary{margin-bottom:12px}details.fin-card>summary::-webkit-details-marker{display:none}
.fin-badge{padding:4px 8px;border-radius:8px;background:#edf4ff;font-size:11px;font-weight:700;display:inline-block}.fin-badge.PENDIENTE{background:#fff5da;color:#8a5d04}.fin-badge.AUTORIZADO{background:#e2efff;color:#16529b}.fin-badge.REGISTRADO{background:#dcf8e8;color:#16663a}.fin-badge.RECHAZADO,.fin-badge.CANCELADO{background:#fee7e7;color:#8a2636}
.fin-progress{height:9px;border-radius:20px;background:#e4e9f1;overflow:hidden}.fin-progress i{display:block;height:100%;background:#2378ce}.fin-warning{color:#9a2936}.fin-actions{display:flex;gap:5px;flex-wrap:wrap}.fin-actions button{padding:6px 10px;font-size:12px}.fin-actions select{max-width:145px}.fin-inline{display:inline-flex;align-items:center;gap:5px}.fin-inline input{width:auto}.fin-section{scroll-margin-top:110px}
@media(max-width:1000px){.fin-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.fin-grid{grid-template-columns:1fr}.fin-tabs a{flex:1;text-align:center}.fin-actions{flex-direction:column}}
@media print {.fin-tabs,.nav,form,details.fin-card{display:none!important}.card{break-inside:avoid}body{background:white}}
</style>
<div class="fin-note"><strong>Registro financiero independiente · MXN.</strong> Los importes bancarios son manuales: <strong>no son una consulta a bancos reales</strong>. Los cobros del POS no se depositan automáticamente; los pagos programados por Tesorería no descuentan saldo. Registra solo operaciones efectivamente reflejadas y concilia con el banco. No se modifica Almacén ni Cuentas por Pagar.</div>
<div class="fin-tabs"><a href="#cuentas">🏦 Cuentas</a><a href="#movimientos">↔ Movimientos</a><a href="#tesoreria">📅 Pagos previstos</a><a href="#presupuesto">🧮 Presupuestos</a><a href="#proyeccion">📈 Proyección</a><a href="#conciliar">✅ Conciliación</a><a href="erp_tesoreria.php">Tesorería</a><a href="erp_cuentas_por_pagar.php">Cuentas por Pagar</a></div>
<?php if($finError): ?><div class="alert bad"><?= $finEsc($finError) ?></div><?php endif; ?>
<?php if($finOk): ?><div class="alert good"><?= $finEsc($finOk) ?></div><?php endif; ?>
<?php if($finAdvertencia): ?><div class="alert"><?= $finEsc($finAdvertencia) ?></div><?php endif; ?>
<form method="get" class="actions card" style="align-items:center"><label style="flex:0 1 180px">Periodo mostrado<input type="month" name="mes" value="<?= $finEsc($finMes) ?>" required></label><button type="submit" style="flex:0">Filtrar mes</button><a class="btn alt" href="erp_flujo_efectivo.php?mes=<?= $finEsc($finMes) ?>&amp;csv=movimientos" style="flex:0">Exportar últimos 200 movimientos CSV</a><button type="button" class="btn alt" onclick="window.print()" style="flex:0">Imprimir / PDF</button></form>
<div class="grid card">
 <div class="metric"><span>Saldo en cuentas ERP, hoy</span><strong><?= $finFmt($finTotales['saldos']) ?></strong><small class="muted">Apertura + movimientos confirmados</small></div>
 <div class="metric"><span>Ingresos registrados (<?= $finEsc($finMes) ?>)</span><strong><?= $finFmt($finTotales['ingresos']) ?></strong></div>
 <div class="metric"><span>Egresos registrados (<?= $finEsc($finMes) ?>)</span><strong><?= $finFmt($finTotales['egresos']) ?></strong></div>
 <div class="metric"><span>Proyección simple a 30 días</span><strong><?= $finFmt($finProyectado) ?></strong><small class="muted">Saldo + previsiones netas − Tesorería asignada</small></div>
</div>
<section class="card fin-section" id="cuentas"><h2>🏦 Cuentas bancarias y saldos</h2>
<div class="tablewrap"><table><thead><tr><th>Cuenta</th><th>Banco</th><th>Terminación</th><th>Sucursal</th><th>Fecha de apertura</th><th>Saldo según ERP</th></tr></thead><tbody>
<?php foreach($finCuentas as $c): ?><tr><td><?= $finEsc($c['nombre']) ?></td><td><?= $finEsc($c['banco']) ?></td><td>•••• <?= $finEsc($c['terminacion']) ?></td><td><?= $finEsc($finSucursales[$c['sucursal']]??$c['sucursal']) ?></td><td><?= $finEsc($c['fecha_corte']) ?></td><td class="money"><strong><?= $finFmt($c['saldo_cents']) ?></strong></td></tr><?php endforeach; ?>
<?php if(!$finCuentas): ?><tr><td colspan="6" class="muted">Todavía no hay cuentas. Registra la primera para comenzar.</td></tr><?php endif; ?>
</tbody></table></div></section>
<div class="fin-grid">
<details class="card fin-card" open><summary>➕ Registrar cuenta bancaria</summary><form method="post">
<input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="crear_cuenta">
<label>Nombre corto<input required maxlength="100" name="nombre" placeholder="Cuenta operativa G&D"></label>
<label>Banco<input required maxlength="100" name="banco" placeholder="Nombre de la institución"></label>
<label>Últimos 4 dígitos (nunca la cuenta completa)<input required name="terminacion" pattern="[0-9]{4}" maxlength="4" placeholder="1234"></label>
<label>Sucursal<select name="sucursal"><?php foreach($finSucursales as $val=>$txt): ?><option value="<?= $finEsc($val) ?>"><?= $finEsc($txt) ?></option><?php endforeach; ?></select></label>
<label>Fecha del saldo inicial<input type="date" name="fecha_corte" max="<?= $finToday ?>" value="<?= $finToday ?>" required></label>
<label>Saldo de apertura confirmado (MXN)<input type="number" name="saldo_inicial" value="0.00" min="0" step="0.01" required></label>
<p class="small muted">Introduce el saldo al INICIO de la fecha, antes de los movimientos que registres ese día; no uses el saldo al cierre y vuelvas a registrar esos mismos cobros o pagos.</p><button>Crear cuenta</button></form></details>
<details class="card fin-card" open id="movimientos"><summary>↔ Solicitar ingreso o egreso</summary><form method="post">
<input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="movimiento">
<label>Cuenta<select name="cuenta_id" required><option value="">Selecciona</option><?php foreach($finCuentas as $c): if(!(int)$c['activa'])continue; ?><option value="<?= (int)$c['id'] ?>"><?= $finEsc($c['nombre']) ?> · ****<?= $finEsc($c['terminacion']) ?></option><?php endforeach; ?></select></label>
<label>Tipo<select name="tipo" id="finTipo" required><option value="INGRESO">Ingreso confirmado en banco</option><option value="EGRESO">Egreso confirmado en banco</option></select></label>
<label>Categoría<select name="categoria" id="finCat" required><?php foreach($finCategoriasIngreso as $v=>$l): ?><option value="<?= $finEsc($v) ?>"><?= $finEsc($l) ?></option><?php endforeach; ?></select></label>
<label>Sucursal<select name="sucursal"><?php foreach($finSucursales as $val=>$txt): ?><option value="<?= $finEsc($val) ?>"><?= $finEsc($txt) ?></option><?php endforeach; ?></select></label>
<label>Importe (MXN)<input type="number" name="importe" min="0.01" step="0.01" required></label>
<label>Fecha en que figura en banco<input name="fecha_movimiento" type="date" max="<?= $finToday ?>" value="<?= $finToday ?>" required></label>
<label>Folio o referencia real<input name="referencia" maxlength="120" required placeholder="Referencia bancaria"></label>
<label>Concepto (mínimo 8 caracteres)<textarea name="concepto" required minlength="8" maxlength="500"></textarea></label>
<label>Pago aplicado de Cuentas por Pagar (solo para categoría Proveedores)<select name="cxp_aplicacion_id" id="finCxp"><option value="0">Sin vínculo de CxP</option><?php foreach($finApps as $a): ?><option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> · <?= $finEsc($a['proveedor']) ?> · <?= $finEsc($a['numero_factura']) ?> · <?= $finEsc($a['importe']) ?></option><?php endforeach; ?></select></label>
<p class="small muted">Primero solicita; otro administrador autoriza; finalmente confirma cuando el dinero sí aparezca en tu banco. Los pagos de proveedores deben vincularse a una aplicación YA realizada en CxP.</p><button>Solicitar movimiento</button></form></details>
<details class="card fin-card" open><summary>📈 Añadir ingreso o egreso previsto</summary><form method="post">
<input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="pronostico">
<label>Cuenta<select name="cuenta_id" required><option value="">Selecciona</option><?php foreach($finCuentas as $c): if(!(int)$c['activa'])continue; ?><option value="<?= (int)$c['id'] ?>"><?= $finEsc($c['nombre']) ?></option><?php endforeach; ?></select></label>
<label>Tipo<select name="tipo"><option value="INGRESO">Ingreso probable</option><option value="EGRESO">Egreso probable</option></select></label>
<label>Fecha futura<input name="fecha_prevista" type="date" min="<?= $finEsc(date('Y-m-d',strtotime('+1 day'))) ?>" value="<?= $finEsc(date('Y-m-d',strtotime('+7 days'))) ?>" required></label>
<label>Importe<input type="number" name="importe" min="0.01" step="0.01" required></label>
<label>Motivo estimado<textarea name="concepto" minlength="8" maxlength="300" required></textarea></label>
<p class="small muted">No repitas aquí pagos ya programados en Tesorería: se incluyen por separado en la proyección.</p><button>Agregar previsión</button></form></details>
</div>
<section class="card"><h2>Movimientos bancarios · autorización y confirmación</h2>
<div class="fin-note small">Las solicitudes PENDIENTES y AUTORIZADAS <strong>no alteran saldo</strong>. REGISTRADO sí altera saldo una vez. Los movimientos REGISTRADOS son históricos e inmutables: una corrección requiere investigación y un nuevo asiento compensatorio autorizado.</div>
<div class="tablewrap"><table><thead><tr><th>ID / Cuenta</th><th>Fecha</th><th>Tipo / Categoría</th><th>Monto</th><th>Estado</th><th>Referencia / solicitante</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($finMovimientos as $m): ?><tr><td>#<?= (int)$m['id'] ?><br><span class="muted small"><?= $finEsc($m['cuenta']) ?></span></td><td><?= $finEsc($m['fecha_movimiento']) ?></td><td><?= $finEsc($m['tipo']) ?><br><span class="muted small"><?= $finEsc($finCategorias[$m['categoria']]??$m['categoria']) ?></span></td><td class="money"><?= $finEsc(cxp_fmt($m['importe'])) ?></td><td><span class="fin-badge <?= $finEsc($m['estado']) ?>"><?= $finEsc($m['estado']) ?></span></td><td><?= $finEsc($m['referencia']) ?><br><small class="muted"><?= $finEsc($m['solicitante']??'') ?></small></td><td>
<div class="fin-actions">
<?php if($m['estado']==='PENDIENTE' && (int)$m['solicitado_por']!==$finUid): ?>
<form method="post"><input type="hidden" name="accion" value="autorizar"><input type="hidden" name="movimiento_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><button onclick="return confirm('¿Autorizar solicitud? Aún NO impactará el saldo.')">Autorizar</button></form>
<form method="post"><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="movimiento_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input name="motivo" placeholder="Motivo de rechazo" minlength="8" required><button class="btn warn">Rechazar</button></form>
<?php endif; ?>
<?php if($m['estado']==='AUTORIZADO'): ?><form method="post"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="movimiento_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><button onclick="return confirm('¿Comprobaste este movimiento en el estado de cuenta? Al confirmar afectará el saldo del ERP.')">Confirmar reflejado</button></form><?php endif; ?>
<?php if(in_array($m['estado'],['PENDIENTE','AUTORIZADO'],true)): ?><form method="post"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="movimiento_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input name="motivo" minlength="8" placeholder="Motivo" required><button class="btn alt" onclick="return confirm('¿Cancelar solicitud?')">Cancelar</button></form><?php endif; ?>
</div></td></tr><?php endforeach; ?>
<?php if(!$finMovimientos): ?><tr><td colspan="7">Todavía no hay movimientos.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card fin-section" id="tesoreria"><h2>📅 Compromisos autorizados de Tesorería</h2><p class="muted">Elige la cuenta prevista para cada pago autorizado de Cuentas por Pagar. La asignación es solo una proyección y <strong>no registra un egreso</strong>.</p>
<?php if($finSinAsignar): ?><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Proveedor / Factura</th><th>Importe</th><th>Asignar cuenta</th></tr></thead><tbody><?php foreach($finSinAsignar as $p): ?><tr><td><?= $finEsc($p['fecha_programada']) ?></td><td><?= $finEsc($p['proveedor']) ?> · <?= $finEsc($p['numero_factura']) ?></td><td class="money"><?= $finEsc(cxp_fmt($p['importe'])) ?></td><td><form method="post" class="actions"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="asignar_tes"><input type="hidden" name="programacion_id" value="<?= (int)$p['id'] ?>"><select name="cuenta_id" required><option value="">Banco...</option><?php foreach($finCuentas as $c):if(!(int)$c['activa'])continue;?><option value="<?= (int)$c['id'] ?>"><?= $finEsc($c['nombre']) ?></option><?php endforeach; ?></select><button>Asignar</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">No hay programaciones autorizadas pendientes de asignación.</p><?php endif; ?>
<h3>Compromisos asignados pendientes</h3><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Proveedor</th><th>Factura</th><th>Cuenta</th><th>Importe</th></tr></thead><tbody><?php foreach($finProgramaciones as $p): ?><tr><td><?= $finEsc($p['fecha_programada']) ?></td><td><?= $finEsc($p['proveedor']) ?></td><td><?= $finEsc($p['numero_factura']) ?></td><td><?= $finEsc($p['cuenta']) ?></td><td class="money"><?= $finEsc(cxp_fmt($p['importe'])) ?></td></tr><?php endforeach; ?><?php if(!$finProgramaciones): ?><tr><td colspan="5">Sin compromisos pendientes asignados.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card fin-section" id="presupuesto"><h2>🧮 Presupuesto de egresos · <?= $finEsc($finMes) ?></h2><p class="muted">Compara el límite mensual por sucursal y categoría contra movimientos bancarios registrados. Los compromisos previstos se muestran por separado; no se suman aquí.</p>
<div class="tablewrap"><table><thead><tr><th>Sucursal</th><th>Categoría</th><th>Límite</th><th>Gastado</th><th>Disponible</th><th>Consumo</th></tr></thead><tbody>
<?php foreach($finPresupuestos as $p): $lim=cxp_db_cents($p['limite']);$real=$finGastado[$p['sucursal'].'|'.$p['categoria']]??0;$pct=$lim>0?round($real*100/$lim):($real>0?100:0); ?><tr><td><?= $finEsc($finSucursales[$p['sucursal']]??$p['sucursal']) ?></td><td><?= $finEsc($finCategorias[$p['categoria']]??$p['categoria']) ?></td><td class="money"><?= $finFmt($lim) ?></td><td class="money"><?= $finFmt($real) ?></td><td class="money <?= $real>$lim?'fin-warning':'' ?>"><?= $finFmt($lim-$real) ?></td><td style="min-width:130px"><div class="fin-progress"><i style="width:<?= min(100,(int)$pct) ?>%"></i></div><small><?= (int)$pct ?>%</small></td></tr><?php endforeach; ?>
<?php if(!$finPresupuestos): ?><tr><td colspan="6" class="muted">No hay presupuestos para el periodo.</td></tr><?php endif; ?></tbody></table></div></section>
<details class="card fin-card"><summary>➕ Crear o actualizar un presupuesto</summary><form method="post" class="fin-grid"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="presupuesto"><label>Mes<input type="month" name="periodo" value="<?= $finEsc($finMes) ?>" required></label><label>Sucursal<select name="sucursal"><?php foreach($finSucursales as $v=>$l): ?><option value="<?= $finEsc($v) ?>"><?= $finEsc($l) ?></option><?php endforeach; ?></select></label><label>Categoría<select name="categoria"><?php foreach($finCategoriasEgreso as $v=>$l): ?><option value="<?= $finEsc($v) ?>"><?= $finEsc($l) ?></option><?php endforeach; ?></select></label><label>Límite mensual<input name="limite" type="number" min="0" step="0.01" required></label><div><label>&nbsp;</label><button>Guardar presupuesto</button></div></form></details>
<section class="card fin-section" id="proyeccion"><h2>📈 Proyección simple de los próximos 30 días</h2>
<div class="grid"><div class="metric"><span>Saldo actual ERP</span><strong><?= $finFmt($finTotales['saldos']) ?></strong></div><div class="metric"><span>Entradas y salidas manuales previstas, netas</span><strong><?= $finFmt($finTotales['proyectados']) ?></strong></div><div class="metric"><span>Pagos asignados y autorizados por Tesorería</span><strong><?= $finFmt($finTotales['programados']) ?></strong></div><div class="metric"><span>Saldo proyectado aproximado</span><strong><?= $finFmt($finProyectado) ?></strong></div></div>
<p class="muted small">Cálculo: saldo ERP actual + ingresos previstos − egresos previstos − pagos de Tesorería asignados con fecha dentro de 30 días. Pagos vencidos y aún pendientes se incluyen. No incluye facturas sin programación bancaria, pagos no asignados, próximos cobros de POS, comisiones, ni movimientos del banco no capturados. No es una promesa de liquidez.</p>
<h3>Previsiones activas (hasta 60 días)</h3><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Cuenta</th><th>Concepto</th><th>Tipo</th><th>Importe</th><th></th></tr></thead><tbody><?php foreach($finPrevisiones as $p): ?><tr><td><?= $finEsc($p['fecha_prevista']) ?></td><td><?= $finEsc($p['cuenta']) ?></td><td><?= $finEsc($p['concepto']) ?></td><td><?= $finEsc($p['tipo']) ?></td><td class="money"><?= $finEsc(cxp_fmt($p['importe'])) ?></td><td><form method="post"><input type="hidden" name="accion" value="cancelar_pronostico"><input type="hidden" name="pronostico_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><button class="btn alt" onclick="return confirm('¿Cancelar esta previsión?')">Cancelar</button></form></td></tr><?php endforeach; ?><?php if(!$finPrevisiones): ?><tr><td colspan="6">Aún no hay previsiones.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card fin-section" id="conciliar"><h2>✅ Conciliación de saldos bancarios</h2><p class="muted">Captura el saldo del estado de cuenta, comparándolo con el saldo que calcula el ERP a la misma fecha. Se conserva la diferencia para revisión; <strong>no se genera un ajuste automático</strong>.</p>
<form method="post" class="fin-grid"><input type="hidden" name="csrf" value="<?= $finEsc($_SESSION['fin_csrf']) ?>"><input type="hidden" name="token" value="<?= $finEsc($_SESSION['fin_token']) ?>"><input type="hidden" name="accion" value="conciliacion"><label>Cuenta<select name="cuenta_id" required><option value="">Selecciona</option><?php foreach($finCuentas as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $finEsc($c['nombre']) ?></option><?php endforeach; ?></select></label><label>Fecha del estado<input type="date" name="fecha_estado" max="<?= $finToday ?>" value="<?= $finToday ?>" required></label><label>Saldo según el banco (MXN)<input type="number" step="0.01" name="saldo_bancario" required></label><label>Notas de conciliación<textarea name="observaciones" minlength="8" maxlength="500" required></textarea></label><div><label>&nbsp;</label><button>Guardar conciliación</button></div></form>
<h3 style="margin-top:18px">Últimas conciliaciones</h3><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Cuenta</th><th>Saldo ERP</th><th>Saldo del banco</th><th>Diferencia</th><th>Responsable</th></tr></thead><tbody><?php foreach($finConciliaciones as $c): ?><tr><td><?= $finEsc($c['fecha_estado']) ?></td><td><?= $finEsc($c['cuenta']) ?></td><td class="money"><?= $finEsc(cxp_fmt($c['saldo_erp'])) ?></td><td class="money"><?= $finEsc(cxp_fmt($c['saldo_bancario'])) ?></td><td class="money"><?= $finEsc(cxp_fmt($c['diferencia'])) ?></td><td><?= $finEsc($c['usuario']??'') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><h2>🧾 Cobros del POS · <?= $finEsc($finMes) ?> (solo consulta)</h2><p class="muted">Cobros confirmados de ventas PAGADAS según la base del POS. Son ingresos por ventas, <strong>no depósitos bancarios</strong>. Cualquier cancelación o devolución debe revisarse con su registro de reversa correspondiente.</p><div class="tablewrap"><table><thead><tr><th>Id sucursal POS</th><th>Método</th><th>Confirmado</th></tr></thead><tbody><?php foreach($finPos as $p): ?><tr><td><?= (int)$p['sucursal_id'] ?></td><td><?= $finEsc($p['metodo']) ?></td><td class="money"><?= $finEsc(cxp_fmt($p['total'])) ?></td></tr><?php endforeach; ?><?php if(!$finPos): ?><tr><td colspan="3">No hay cobros POS para el periodo consultado.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>🔐 Bitácora financiera reciente</h2><div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody><?php foreach($finAudit as $a): ?><tr><td><?= $finEsc($a['creado_en']) ?></td><td><?= $finEsc($a['evento']) ?></td><td><?= $finEsc($a['usuario']??'') ?></td><td><?= $finEsc($a['detalle']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<script>
(function(){
  const tipo=document.getElementById('finTipo'),cat=document.getElementById('finCat'),cxp=document.getElementById('finCxp');
  const choices={INGRESO:<?= json_encode($finCategoriasIngreso,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>,EGRESO:<?= json_encode($finCategoriasEgreso,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>};
  if(!tipo||!cat)return;
  function actualizar(){cat.replaceChildren();Object.entries(choices[tipo.value]).forEach(([id,nombre])=>{const o=document.createElement('option');o.value=id;o.textContent=nombre;cat.appendChild(o)});if(cxp)cxp.value='0';}
  tipo.addEventListener('change',actualizar);
})();
</script>
<?php cxp_page_foot(); ?>
