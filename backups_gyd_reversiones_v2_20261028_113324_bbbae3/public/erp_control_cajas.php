<?php
/**
 * ERP G&D — Control de cajas / arqueos gerenciales, solo en `prueba`.
 * - Usa los movimientos monetarios que el POS YA incorpora en cerrarTurno().
 * - No cambia ventas, pagos o cortes nativos ni realiza reembolsos bancarios.
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';
requireLogin();
$usuario = currentUser() ?: [];
$rol = strtoupper(trim((string) ($usuario['rol_original'] ?? $usuario['rol'] ?? '')));
if (!in_array($rol, ['ADMINISTRADOR','ADMIN','DIRECCION'], true)) {
    http_response_code(403);
    exit('Acceso exclusivo de ADMINISTRADOR y DIRECCIÓN.');
}
$uid = (int) ($usuario['id'] ?? 0);
if ($uid <= 0) { http_response_code(403); exit('Sesión no válida.'); }
function cg_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function cg_money(mixed $v): string { return '$'.number_format((float)$v, 2, '.', ','); }
function cg_cents(string $str): int {
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', trim($str))) throw new DomainException('Importe inválido (máximo dos decimales).');
    [$a,$b] = array_pad(explode('.',trim($str),2),2,'');
    return (int)$a*100 + (int)str_pad($b,2,'0');
}
function cg_dollars(int $cents): string { return number_format($cents/100,2,'.',''); }
function cg_ymd(string $v): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); return $d!==false && $d->format('Y-m-d')===$v; }
function cg_q(PDO $db,string $sql,array $params=[]): PDOStatement { $s=$db->prepare($sql);$s->execute($params);return $s; }
function cg_key(): string { return bin2hex(random_bytes(16)); }
function cg_csv_text(mixed $v): string {
    $s=(string)$v;
    // Evita que un nombre o referencia comience una fórmula al abrir CSV en Excel.
    return preg_match('/^[\x00-\x20]*[=+@\-]/u',$s) ? "'".$s : $s;
}
function cg_audit(PDO $db,int $user,string $accion,array $datos): void {
    cg_q($db,'INSERT INTO pos_auditoria(usuario_id,venta_id,accion,detalle) VALUES (?,NULL,?,?)',[$user,$accion,json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function cg_totales(PDO $db,int $turno): array {
    // MISMA base de cierre que usa PuntoVenta::cerrarTurno(). No incluye devoluciones.
    $pagos = cg_q($db,"SELECT pg.metodo,COALESCE(SUM(pg.monto),0) suma FROM pos_pagos pg INNER JOIN pos_ventas v ON v.id=pg.venta_id WHERE v.turno_id=? AND v.estado='PAGADA' AND pg.estado='CONFIRMADO' GROUP BY pg.metodo",[$turno])->fetchAll(PDO::FETCH_ASSOC);
    $por=['EFECTIVO'=>0,'TARJETA'=>0,'TRANSFERENCIA'=>0];
    foreach($pagos as $p) if(isset($por[$p['metodo']]))$por[$p['metodo']]=(int)round(((float)$p['suma'])*100);
    $movs=cg_q($db,"SELECT tipo,COALESCE(SUM(monto),0) suma FROM pos_movimientos_caja WHERE turno_id=? GROUP BY tipo",[$turno])->fetchAll(PDO::FETCH_ASSOC);
    $mov=['INGRESO'=>0,'RETIRO'=>0,'AJUSTE'=>0];
    foreach($movs as $m) if(isset($mov[$m['tipo']]))$mov[$m['tipo']]=(int)round(((float)$m['suma'])*100);
    $t=cg_q($db,'SELECT fondo_inicial FROM pos_turnos WHERE id=?',[$turno])->fetch(PDO::FETCH_ASSOC);
    $fondo=$t?(int)round((float)$t['fondo_inicial']*100):0;
    return ['pagos'=>$por,'mov'=>$mov,'fondo'=>$fondo,'esperado'=>$fondo+$por['EFECTIVO']+$mov['INGRESO']-$mov['RETIRO']];
}
$hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
$desde = (string)($_GET['desde'] ?? (new DateTimeImmutable('-6 days'))->format('Y-m-d'));
$hasta = (string)($_GET['hasta'] ?? $hoy);
if(!cg_ymd($desde)) $desde=$hoy;
if(!cg_ymd($hasta)) $hasta=$hoy;
$fechaError='';
if($desde>$hasta || (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days>92) {
    $fechaError='Selecciona un periodo válido de máximo 93 días.';
}
$sucursal=(int)($_GET['sucursal']??0);
$seleccionado=(int)($_GET['turno']??0);
$mensaje='';$error='';$cajas=[];$turnos=[];$resumen=[];$det=null;$totales=null;$movimientos=[];$arqueos=[];$revision=null;$reembolsos=0;$desgloseCorte=null;
if(!isset($_SESSION['erp_caja_csrf'])) $_SESSION['erp_caja_csrf']=cg_key();
$csrf=(string)$_SESSION['erp_caja_csrf'];
if(!isset($_SESSION['erp_caja_nonce']))$_SESSION['erp_caja_nonce']=cg_key();
$nonce=(string)$_SESSION['erp_caja_nonce'];
try {
    $db=(new Database())->connect();
    $cajas=cg_q($db,'SELECT s.id,s.nombre FROM pos_sucursales s WHERE s.activo=1 ORDER BY s.id')->fetchAll(PDO::FETCH_ASSOC);
    $validos=array_map(static fn($x)=>(int)$x['id'],$cajas);
    if($sucursal!==0 && !in_array($sucursal,$validos,true)) $sucursal=0;
    // Cambios siempre con CSRF y token único. Nunca permitir modificar un turno ajeno al POS.
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('Formulario vencido. Actualiza la página.');
        $operacion=(string)($_POST['accion']??'');
        // Se deshabilita esta ruta antigua: ningún arqueo puede saltar la autorización secundaria.
        if($operacion==='arqueo')throw new DomainException('Para hacer arqueos utiliza Arqueo protegido desde el POS (contraseña del autorizador).');
        $turnoId=(int)($_POST['turno_id']??0);
        $clave=(string)($_POST['clave']??'');
        if($turnoId<1 || !preg_match('/^[a-f0-9]{32}$/D',$clave) || !hash_equals($nonce,$clave)) throw new DomainException('Acción inválida o ya enviada. Actualiza la página.');
        if(!in_array($operacion,['movimiento','arqueo','revision'],true))throw new DomainException('Operación desconocida.');
        $db->beginTransaction();
        try{
            $t=cg_q($db,'SELECT id,caja_id,estado,usuario_apertura_id,efectivo_contado,diferencia_efectivo FROM pos_turnos WHERE id=? FOR UPDATE',[$turnoId])->fetch(PDO::FETCH_ASSOC);
            if(!$t)throw new DomainException('Turno no encontrado.');
            $observacion=trim((string)($_POST['motivo']??''));
            if(mb_strlen($observacion,'UTF-8')<8 || mb_strlen($observacion,'UTF-8')>500)throw new DomainException('Describe lo sucedido (8 a 500 caracteres).');
            if($operacion==='movimiento'){
                if($t['estado']!=='ABIERTO')throw new DomainException('No puedes mover efectivo de un turno cerrado.');
                $tipo=(string)($_POST['tipo']??'');
                if($tipo==='RETIRO')throw new DomainException('Retiro deshabilitado aquí: utiliza Retiro de efectivo en el POS para generar folio y comprobante de bóveda.');
                if(!in_array($tipo,['INGRESO','RETIRO'],true))throw new DomainException('Tipo de movimiento inválido.');
                if(mb_strlen($observacion,'UTF-8')>255)throw new DomainException('El motivo del movimiento admite 255 caracteres como máximo.');
                $ref=trim((string)($_POST['referencia']??''));
                if(mb_strlen($ref,'UTF-8')>120)throw new DomainException('Referencia muy larga.');
                $centavos=cg_cents((string)($_POST['monto']??''));
                if($centavos<=0 || $centavos>10000000000) throw new DomainException('El importe debe ser positivo y razonable.');
                $tot=cg_totales($db,$turnoId);
                if($tipo==='RETIRO' && $centavos>$tot['esperado'])throw new DomainException('El retiro excede el efectivo esperado. Revisa la caja antes de continuar.');
                if($tipo==='RETIRO'){
                    // El supervisor puede hacer varios retiros parciales sin cerrar turno.
                    $fondoCaja=cg_cents((string)cg_q($db,'SELECT fondo_inicial FROM pos_turnos WHERE id=?',[$turnoId])->fetchColumn());
                    if($centavos>max(0,$tot['esperado']-$fondoCaja))throw new DomainException('El retiro debe conservar íntegro el fondo inicial del turno.');
                    if(cg_q($db,"SELECT 1 FROM erp_retiros_supervisados WHERE turno_id=? AND estado='SOLICITADO' LIMIT 1",[$turnoId])->fetchColumn())throw new DomainException('Hay una entrega de retiro pendiente de confirmar o rechazar; resuélvela primero.');
                }
                // Se controla duplicado sin alterar estructuras nativas.
                if(cg_q($db,'SELECT 1 FROM erp_caja_movimientos_control WHERE operacion_clave=?',[$clave])->fetchColumn())throw new DomainException('Movimiento ya procesado.');
                cg_q($db,'INSERT INTO pos_movimientos_caja(turno_id,usuario_id,tipo,monto,motivo) VALUES (?,?,?,?,?)',[$turnoId,$uid,$tipo,cg_dollars($centavos),$observacion]);
                $movId=(int)$db->lastInsertId();
                cg_q($db,'INSERT INTO erp_caja_movimientos_control(operacion_clave,turno_id,movimiento_id,usuario_id,tipo,monto,motivo,referencia) VALUES (?,?,?,?,?,?,?,?)',[$clave,$turnoId,$movId,$uid,$tipo,cg_dollars($centavos),$observacion,$ref?:null]);
                cg_audit($db,$uid,'ERP_CAJA_MOVIMIENTO',['turno'=>$turnoId,'tipo'=>$tipo,'monto'=>cg_dollars($centavos),'movimiento'=>$movId,'referencia'=>$ref]);
                $mensaje='Movimiento de efectivo registrado en el turno.';
            } elseif($operacion==='arqueo') {
                $n=cg_cents((string)($_POST['contado']??''));
                $total=cg_totales($db,$turnoId);
                $d=$n-$total['esperado'];
                if(cg_q($db,'SELECT 1 FROM erp_caja_arqueos WHERE operacion_clave=?',[$clave])->fetchColumn())throw new DomainException('Arqueo ya registrado.');
                cg_q($db,'INSERT INTO erp_caja_arqueos(operacion_clave,turno_id,usuario_id,esperado_corte,efectivo_contado,diferencia,motivo) VALUES (?,?,?,?,?,?,?)',[$clave,$turnoId,$uid,cg_dollars($total['esperado']),cg_dollars($n),cg_dollars($d),$observacion]);
                cg_audit($db,$uid,'ERP_CAJA_ARQUEO',['turno'=>$turnoId,'contado'=>cg_dollars($n),'esperado'=>cg_dollars($total['esperado']),'diferencia'=>cg_dollars($d)]);
                $mensaje='Arqueo registrado sin modificar el cierre del POS.';
            } else {
                if($t['estado']!=='CERRADO')throw new DomainException('Solo pueden revisarse cierres completados.');
                if((int)$t['usuario_apertura_id']===$uid)throw new DomainException('La revisión debe hacerla alguien distinto de quien abrió el turno.');
                $estado=(string)($_POST['estado_revision']??'');
                if(!in_array($estado,['REVISADO','OBSERVADO'],true))throw new DomainException('Estado de revisión inválido.');
                if(cg_q($db,'SELECT 1 FROM erp_caja_revisiones WHERE turno_id=?',[$turnoId])->fetchColumn())throw new DomainException('Este corte ya fue revisado. No es posible sobrescribirlo.');
                cg_q($db,'INSERT INTO erp_caja_revisiones(turno_id,estado,observaciones,usuario_id) VALUES (?,?,?,?)',[$turnoId,$estado,$observacion,$uid]);
                cg_audit($db,$uid,'ERP_CAJA_REVISION',['turno'=>$turnoId,'estado'=>$estado]);
                $mensaje='Revisión del corte guardada. No se modificó la caja.';
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        // POST-redirect-GET evita el doble envío al actualizar la pantalla.
        $_SESSION['erp_caja_flash']=$mensaje;
        unset($_SESSION['erp_caja_nonce']);
        $qs=http_build_query(['turno'=>$turnoId,'desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal]);
        header('Location: erp_control_cajas.php?'.$qs,true,303);exit;
    }
    $mensaje=(string)($_SESSION['erp_caja_flash']??'');unset($_SESSION['erp_caja_flash']);
    if(!$fechaError){
        $params=[$desde.' 00:00:00',$hasta.' 23:59:59'];
        $where='t.abierto_en BETWEEN ? AND ?';
        if($sucursal>0){$where.=' AND s.id=?';$params[]=$sucursal;}
        $turnos=cg_q($db,"SELECT t.*,c.nombre caja,s.nombre sucursal,s.id sucursal_id,ua.nombre aperturo,uc.nombre cerro,r.estado revision_estado FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios ua ON ua.id=t.usuario_apertura_id LEFT JOIN usuarios uc ON uc.id=t.usuario_cierre_id LEFT JOIN erp_caja_revisiones r ON r.turno_id=t.id WHERE $where ORDER BY t.id DESC LIMIT 150",$params)->fetchAll(PDO::FETCH_ASSOC);
        foreach($turnos as $t){
            $nombre=(string)$t['sucursal'];
            if(!isset($resumen[$nombre]))$resumen[$nombre]=['turnos'=>0,'abiertos'=>0,'cerrados'=>0,'con_diferencia'=>0,'dif_acumulada'=>0.0,'sin_revisar'=>0];
            $resumen[$nombre]['turnos']++;
            if($t['estado']==='ABIERTO')$resumen[$nombre]['abiertos']++;
            else{
                $resumen[$nombre]['cerrados']++;
                $dif=(float)($t['diferencia_efectivo']??0);
                $resumen[$nombre]['dif_acumulada']+=$dif;
                if(abs($dif)>=0.01)$resumen[$nombre]['con_diferencia']++;
                if(!$t['revision_estado'])$resumen[$nombre]['sin_revisar']++;
            }
        }
    }
    if($seleccionado>0){
        $det=cg_q($db,'SELECT t.*,c.nombre caja,s.nombre sucursal,ua.nombre aperturo,uc.nombre cerro FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id JOIN pos_sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios ua ON ua.id=t.usuario_apertura_id LEFT JOIN usuarios uc ON uc.id=t.usuario_cierre_id WHERE t.id=?',[$seleccionado])->fetch(PDO::FETCH_ASSOC);
        if($det){
            $totales=cg_totales($db,$seleccionado);
            $movimientos=cg_q($db,'SELECT m.*,u.nombre responsable,cm.referencia FROM pos_movimientos_caja m LEFT JOIN usuarios u ON u.id=m.usuario_id LEFT JOIN erp_caja_movimientos_control cm ON cm.movimiento_id=m.id WHERE m.turno_id=? ORDER BY m.id DESC',[$seleccionado])->fetchAll(PDO::FETCH_ASSOC);
            $arqueos=cg_q($db,'SELECT a.*,u.nombre responsable,uv.nombre autorizador FROM erp_caja_arqueos a LEFT JOIN usuarios u ON u.id=a.usuario_id LEFT JOIN gyd_arqueos_autorizaciones au ON au.arqueo_id=a.id LEFT JOIN usuarios uv ON uv.id=au.autorizador_id WHERE a.turno_id=? ORDER BY a.id DESC',[$seleccionado])->fetchAll(PDO::FETCH_ASSOC);
            $revision=cg_q($db,'SELECT r.*,u.nombre responsable FROM erp_caja_revisiones r LEFT JOIN usuarios u ON u.id=r.usuario_id WHERE r.turno_id=?',[$seleccionado])->fetch(PDO::FETCH_ASSOC)?:null;
            $desgloseCorte=cg_q($db,'SELECT desglose_json,total_contado,creado_en FROM pos_cortes_denominaciones WHERE turno_id=?',[$seleccionado])->fetch(PDO::FETCH_ASSOC)?:null;
            // Se muestran aparte los reembolsos de efectivo: el cierre POS original NO los descuenta.
            try{
                $reembolsos=(float)cg_q($db,"SELECT COALESCE(SUM(importe),0) FROM pos_reversiones WHERE turno_reembolso_id=? AND estado='APLICADA' AND medio_reembolso='EFECTIVO'",[$seleccionado])->fetchColumn();
            }catch(PDOException $e){$reembolsos=0;}
        }
    }
    if(($_GET['exportar']??'')==='csv' && !$fechaError){
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="erp_gyd_cortes_caja.csv"');
        echo "\xEF\xBB\xBF";
        $out=fopen('php://output','w');
        fputcsv($out,['Turno','Sucursal','Caja','Apertura','Cierre','Estado','Cajero','Fondo','Efectivo contado','Diferencia al cierre','Revisión']);
        foreach($turnos as $t)fputcsv($out,[$t['id'],cg_csv_text($t['sucursal']),cg_csv_text($t['caja']),$t['abierto_en'],$t['cerrado_en'],$t['estado'],cg_csv_text($t['aperturo']),$t['fondo_inicial'],$t['efectivo_contado'],$t['diferencia_efectivo'],$t['revision_estado']??'PENDIENTE']);
        fclose($out);exit;
    }
}catch(Throwable $e){if(isset($db) && $db->inTransaction())$db->rollBack();$error=$e instanceof DomainException?$e->getMessage():'No se pudo consultar el Control de Cajas. Revisa la migración SQL y el registro de PHP.';error_log('ERP cajas: '.$e->getMessage());}
$propia=(int)($det['usuario_apertura_id']??0)===$uid;
$qsBase=['desde'=>$desde,'hasta'=>$hasta,'sucursal'=>$sucursal];
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP G&D | Control de Cajas y Arqueos</title>
<style>
:root{font:15px system-ui,Segoe UI,Arial,sans-serif;color:#193758;background:#edf3fa}*{box-sizing:border-box}body{margin:0}.top{background:linear-gradient(115deg,#15395e,#1b62b7);border-bottom:4px solid #df3760;color:#fff;padding:24px max(18px,calc((100% - 1200px)/2))}.top h1{margin:0 0 5px;font-size:clamp(22px,3vw,30px)}.top p{margin:0;color:#d7e8f9}.layout{max-width:1240px;margin:20px auto;padding:0 16px 48px}.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.space{justify-content:space-between}.card{background:#fff;padding:20px;border:1px solid #e3ebf4;border-radius:16px;margin:14px 0;box-shadow:0 5px 19px #173e6810}.nav a,.btn,button{display:inline-block;border:0;background:#175eaa;color:white;border-radius:9px;padding:11px 14px;text-decoration:none;font:700 14px system-ui;cursor:pointer}.nav a{background:#e9f1fb;color:#194976}.btn.secondary{background:#e6eef8;color:#20486d}.btn.red{background:#ad2943}.btn.outline{background:#fff;border:1px solid #bcd0e6;color:#1c547f}button:disabled{opacity:.5;cursor:not-allowed}.muted{font-size:13px;color:#667d92}.notice{padding:14px;border-radius:10px;background:#fff4df;border:1px solid #f3cc83;margin:12px 0}.success{background:#e7f6ec;border-color:#bde4cd}.error{background:#fce8e9;border-color:#f1b8bf}.form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.form label{display:grid;gap:5px;font-size:13px;font-weight:700}.form input,.form select,.form textarea{padding:11px;border:1px solid #bbcede;border-radius:9px;font:inherit;color:#153b60;min-width:150px;max-width:100%}.form textarea{min-height:75px;width:100%}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:850px}td,th{padding:10px 12px;border-bottom:1px solid #e3eaf3;text-align:left;font-size:13px;vertical-align:top}th{background:#174d80;color:#fff;white-space:nowrap}tr:hover td{background:#f7fbff}.num{text-align:right;font-variant-numeric:tabular-nums}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.stat{padding:14px;border:1px solid #e5eef6;background:#f3f7fc;border-radius:12px}.stat span{display:block;color:#5d7690;font-size:12px}.stat b{display:block;font-size:20px;margin-top:6px;overflow-wrap:anywhere}h2{font-size:19px;margin:0 0 13px}h3{font-size:16px;margin:5px 0 12px}.two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.pill{border-radius:15px;background:#e7eff8;color:#24527e;padding:4px 9px;font-size:12px;font-weight:700}.danger-text{color:#b52c49;font-weight:800}.good-text{color:#147758;font-weight:800}.hint{font-size:12px;color:#6b8194}.divider{border:0;border-top:1px solid #e1eaf2;margin:16px 0}.radio{display:inline-flex;align-items:center;gap:5px;padding:10px}.radio input{min-width:0}@media(max-width:850px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.two{grid-template-columns:1fr}}@media print{.nav,.form button,.actions,.no-print{display:none!important}.card{box-shadow:none}body{background:white}.layout{max-width:none}.top{color:#000;background:#fff}}
</style></head><body>
<header class="top"><h1>Control Gerencial de Cajas · ERP G&amp;D</h1><p>Turnos, arqueos, retiros, depósitos y revisión de diferencias · Base de pruebas</p>
<a href="erp_conciliacion_diaria_cajas.php" style="display:inline-block;margin:6px 0;color:#fff;font-weight:700;text-decoration:underline">Conciliación diaria de cajas</a>
</header>
<main class="layout">
<nav class="row nav no-print" aria-label="Navegación"><a href="dashboard.php">← Inicio</a><a href="pos.php">Punto de Venta</a><a href="erp_cortes_boveda.php">Consultar retiros y cortes</a><a href="erp_cortes_boveda.php">Retiros del día</a><a href="erp_cortes_boveda.php">Cortes / bóveda</a><a href="erp_dashboard_gerencial.php">Dashboard Gerencial</a><a href="erp_eficiencia_cajeros.php">Eficiencia de Cajeros</a><a href="logout.php">Cerrar sesión</a></nav>
<?php if($error):?><div class="notice error" role="alert"><?=cg_h($error)?></div><?php endif;?>
<?php if($fechaError):?><div class="notice error"><?=cg_h($fechaError)?></div><?php endif;?>
<?php if($mensaje):?><div class="notice success" role="status"><?=cg_h($mensaje)?></div><?php endif;?>
<div class="notice"><strong>Control financiero:</strong> los retiros/ingresos registrados aquí afectan el efectivo esperado del turno al cerrarse en POS. Los arqueos y revisiones son documentales; no modifican ventas ni sobrescriben cortes. Los reembolsos bancarios se ejecutan fuera del ERP.</div>
<section class="card"><h2>Buscar turnos</h2><form class="form" method="get">
<label>Desde<input type="date" name="desde" value="<?=cg_h($desde)?>" required></label><label>Hasta<input type="date" name="hasta" value="<?=cg_h($hasta)?>" required></label>
<label>Sucursal<select name="sucursal"><option value="0">Todas las sucursales</option><?php foreach($cajas as $s):?><option value="<?=(int)$s['id']?>" <?=$sucursal===(int)$s['id']?'selected':''?>><?=cg_h($s['nombre'])?></option><?php endforeach;?></select></label><button>Consultar</button><a class="btn secondary" href="erp_control_cajas.php">Limpiar</a><a class="btn outline" href="?<?=cg_h(http_build_query($qsBase+['exportar'=>'csv']))?>">Exportar CSV</a><button type="button" class="btn secondary" onclick="window.print()">Imprimir / PDF</button></form></section>
<?php if($resumen):?><section class="card"><h2>Resumen por sucursal</h2><div class="table-wrap"><table><thead><tr><th>Sucursal</th><th>Turnos</th><th>Abiertos</th><th>Cerrados</th><th>Cortes con diferencia</th><th>Variación neta de diferencias</th><th>Sin revisión</th></tr></thead><tbody><?php foreach($resumen as $sname=>$r):?><tr><td><?=cg_h($sname)?></td><td><?=(int)$r['turnos']?></td><td><?=(int)$r['abiertos']?></td><td><?=(int)$r['cerrados']?></td><td><?=(int)$r['con_diferencia']?></td><td><?=cg_money($r['dif_acumulada'])?></td><td><?=(int)$r['sin_revisar']?></td></tr><?php endforeach;?></tbody></table></div><p class="hint">Resumen de los turnos mostrados (máximo 150). Las diferencias positivas y negativas se compensan en la suma neta: revisa cada corte individualmente.</p></section><?php endif;?>
<section class="card"><h2>Turnos de caja</h2><div class="table-wrap"><table><thead><tr><th>Turno</th><th>Sucursal / caja</th><th>Abierto por</th><th>Apertura</th><th>Cierre</th><th>Estado</th><th>Fondo inicial</th><th>Contado al cierre</th><th>Diferencia al cierre</th><th>Revisión</th><th class="no-print">Acción</th></tr></thead><tbody>
<?php foreach($turnos as $t):?><tr><td>#<?=(int)$t['id']?></td><td><?=cg_h($t['sucursal'])?><div class="muted"><?=cg_h($t['caja'])?></div></td><td><?=cg_h($t['aperturo'])?></td><td><?=cg_h($t['abierto_en'])?></td><td><?=cg_h($t['cerrado_en']?:'—')?></td><td><span class="pill"><?=cg_h($t['estado'])?></span></td><td class="num"><?=cg_money($t['fondo_inicial'])?></td><td class="num"><?=$t['efectivo_contado']!==null?cg_money($t['efectivo_contado']):'—'?></td><td class="num"><?=$t['diferencia_efectivo']!==null?cg_money($t['diferencia_efectivo']):'—'?></td><td><?=cg_h($t['revision_estado']?:'Pendiente')?></td><td class="no-print"><a class="btn secondary" href="?<?=cg_h(http_build_query($qsBase+['turno'=>$t['id']]))?>#detalle">Ver / gestionar</a></td></tr><?php endforeach;?><?php if(!$turnos):?><tr><td colspan="11">No hay turnos en el periodo seleccionado.</td></tr><?php endif;?></tbody></table></div><p class="hint">Máximo 150 turnos por consulta. Para periodos largos, utiliza intervalos de fechas más pequeños.</p></section>
<?php if($det && $totales):?>
<section class="card" id="detalle"><div class="row space"><h2>Turno #<?=(int)$det['id']?> · <?=cg_h($det['sucursal'])?> · <?=cg_h($det['caja'])?></h2><span class="pill"><?=cg_h($det['estado'])?></span></div>
<div class="muted">Abrió: <?=cg_h($det['aperturo'])?> · <?=cg_h($det['abierto_en'])?><?php if($det['cerrado_en']):?> · Cerró: <?=cg_h($det['cerro'])?> · <?=cg_h($det['cerrado_en'])?><?php endif;?></div>
<div class="stat-grid" style="margin-top:14px"><div class="stat"><span>Fondo inicial</span><b><?=cg_money(cg_dollars($totales['fondo']))?></b></div><div class="stat"><span>Ventas cobradas en efectivo</span><b><?=cg_money(cg_dollars($totales['pagos']['EFECTIVO']))?></b></div><div class="stat"><span>Efectivo esperado (base POS)</span><b><?=cg_money(cg_dollars($totales['esperado']))?></b></div><div class="stat"><span>Último efectivo contado en cierre</span><b><?=$det['efectivo_contado']!==null?cg_money($det['efectivo_contado']):'Pendiente'?></b></div></div>
<div class="stat-grid" style="margin-top:10px"><div class="stat"><span>Pagos con tarjeta</span><b><?=cg_money(cg_dollars($totales['pagos']['TARJETA']))?></b></div><div class="stat"><span>Transferencias</span><b><?=cg_money(cg_dollars($totales['pagos']['TRANSFERENCIA']))?></b></div><div class="stat"><span>Ingresos de efectivo</span><b><?=cg_money(cg_dollars($totales['mov']['INGRESO']))?></b></div><div class="stat"><span>Retiros de efectivo</span><b><?=cg_money(cg_dollars($totales['mov']['RETIRO']))?></b></div></div>
<?php if($totales['mov']['AJUSTE']>0):?><p class="notice">Existen movimientos tipo AJUSTE por <?=cg_money(cg_dollars($totales['mov']['AJUSTE']))?>. El cierre POS actual no los incorpora al efectivo esperado: revisar manualmente.</p><?php endif;?>
<?php if($reembolsos>0):?><p class="notice"><strong>Atención:</strong> se registraron <?=cg_money($reembolsos)?> de reembolsos de efectivo en devoluciones para este turno. El cierre POS original no los descuenta automáticamente. Verifica si existe un RETIRO de caja correspondiente antes de cuadrar; no lo registres dos veces.</p><?php endif;?>
<?php if($det['estado']==='CERRADO'):?><p class="notice"><strong>Diferencia guardada por POS al cerrar:</strong> <?=cg_money($det['diferencia_efectivo']??0)?>. La cifra histórica del corte es la referencia; el cálculo actual puede variar por devoluciones o cambios posteriores. No se modifica desde este módulo.</p><?php endif;?>
</section>
<?php if($det['estado']==='CERRADO'):?>
<section class="card"><div class="row space"><h2>Conteo físico por denominaciones</h2><a class="btn secondary no-print" href="erp_caja_desglose_csv.php?turno_id=<?=(int)$det['id']?>">Exportar desglose CSV</a></div>
<?php if($desgloseCorte):$desgloseDatos=json_decode((string)$desgloseCorte['desglose_json'],true);if(!is_array($desgloseDatos))$desgloseDatos=[]; ?>
<p class="muted">Conteo capturado al cierre: <?=cg_money($desgloseCorte['total_contado'])?> · <?=cg_h($desgloseCorte['creado_en'])?>. No modifica el corte.</p>
<div class="table-wrap"><table style="min-width:450px"><thead><tr><th>Billete o moneda</th><th class="num">Cantidad</th><th class="num">Subtotal</th></tr></thead><tbody>
<?php foreach(['b1000'=>100000,'b500'=>50000,'b200'=>20000,'b100'=>10000,'b50'=>5000,'b20'=>2000,'m20'=>2000,'m10'=>1000,'m5'=>500,'m2'=>200,'m1'=>100,'m050'=>50,'m020'=>20,'m010'=>10,'m005'=>5] as $clave=>$valorCent):$n=(int)($desgloseDatos[$clave]??0);if($n<1)continue; ?>
<tr><td><?=cg_h(($clave[0]==='b'?'Billete $':'Moneda $').number_format($valorCent/100,2,'.',','))?></td><td class="num"><?=$n?></td><td class="num"><?=cg_money(($n*$valorCent)/100)?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php else:?><p class="muted">Este turno se cerró antes de instalar el conteo detallado, por lo que no existe un desglose histórico.</p><?php endif;?></section>
<?php endif;?>
<div class="two">
<section class="card"><h2>Movimiento de efectivo</h2><?php if($det['estado']==='ABIERTO'):?><p class="muted">Registrar ingreso o retiro físico. Este movimiento formará parte del cierre del POS.</p><form class="form" method="post" onsubmit="return confirm('¿Registrar este movimiento de efectivo en el turno abierto?')"><input type="hidden" name="csrf" value="<?=cg_h($csrf)?>"><input type="hidden" name="clave" value="<?=cg_h($nonce)?>"><input type="hidden" name="turno_id" value="<?=(int)$det['id']?>"><input type="hidden" name="accion" value="movimiento"><label>Movimiento<select name="tipo" required><option value="RETIRO">Retiro / depósito de efectivo fuera de caja</option><option value="INGRESO">Ingreso de efectivo a caja</option></select></label><label>Monto ($)<input type="number" name="monto" min="0.01" step="0.01" required></label><label>Referencia / comprobante<input name="referencia" maxlength="120" placeholder="Folio o referencia"></label><label style="flex:1 1 100%">Motivo<input name="motivo" minlength="8" maxlength="255" required placeholder="Quién recibe o entrega y por qué"></label><button>Registrar movimiento</button></form><?php else:?><p class="muted">Turno cerrado. No se permiten movimientos retroactivos.</p><?php endif;?></section>
<!-- GYD_FORM_ARQUEO_PROTEGIDO --><section class="card"><h2>Arqueo de caja (protegido)</h2><p class="muted">El arqueo es un conteo independiente. Requiere autorización secundaria de Gerencia, Dirección o Contabilidad. No modifica el efectivo ni los cortes del POS.</p><a class="btn secondary" href="pos.php">Abrir arqueo protegido desde Punto de Venta</a></section>
</div>
<section class="card"><h2>Revisión gerencial de cierre</h2><?php if($revision):?><div class="notice success"><strong><?=cg_h($revision['estado'])?></strong> por <?=cg_h($revision['responsable'])?> el <?=cg_h($revision['revisado_en'])?>. <?=cg_h($revision['observaciones'])?></div><?php elseif($det['estado']==='CERRADO'&&!$propia):?><form class="form" method="post" onsubmit="return confirm('¿Guardar la revisión de este cierre sin modificar sus importes?')"><input type="hidden" name="csrf" value="<?=cg_h($csrf)?>"><input type="hidden" name="clave" value="<?=cg_h($nonce)?>"><input type="hidden" name="turno_id" value="<?=(int)$det['id']?>"><input type="hidden" name="accion" value="revision"><label>Resultado<select name="estado_revision" required><option value="REVISADO">Revisado / sin observaciones pendientes</option><option value="OBSERVADO">Observado / requiere aclaración</option></select></label><label style="flex:1 1 100%">Observación<textarea name="motivo" minlength="8" maxlength="500" required placeholder="Evidencia revisada, faltante/sobrante y seguimiento"></textarea></label><button>Guardar revisión</button></form><?php elseif($det['estado']==='ABIERTO'):?><p class="muted">El turno sigue abierto; puedes registrar arqueos, pero la revisión del corte estará disponible tras cerrarlo en el POS.</p><?php else:?><p class="muted">El mismo usuario que abrió el turno no puede revisar su propio cierre. Solicita a otro ADMINISTRADOR o DIRECCIÓN que lo revise.</p><?php endif;?></section>
<section class="card"><h2>Movimientos de efectivo del turno</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Importe</th><th>Motivo</th><th>Referencia</th><th>Responsable</th></tr></thead><tbody><?php foreach($movimientos as $m):?><tr><td><?=cg_h($m['creado_en'])?></td><td><?=cg_h($m['tipo'])?></td><td><?=cg_money($m['monto'])?></td><td><?=cg_h($m['motivo'])?></td><td><?=cg_h($m['referencia']??'—')?></td><td><?=cg_h($m['responsable']??'—')?></td></tr><?php endforeach;?><?php if(!$movimientos):?><tr><td colspan="6">Todavía no hay movimientos registrados.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>Historial de arqueos</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Esperado al contar</th><th>Contado</th><th>Diferencia</th><th>Responsable</th><th>Autorizó</th><th>Observaciones</th></tr></thead><tbody><?php foreach($arqueos as $a):?><tr><td><?=cg_h($a['creado_en'])?></td><td><?=cg_money($a['esperado_corte'])?></td><td><?=cg_money($a['efectivo_contado'])?></td><td class="<?=((float)$a['diferencia']===0.0?'good-text':'danger-text')?>"><?=cg_money($a['diferencia'])?></td><td><?=cg_h($a['responsable'])?></td><td><?=cg_h($a['autorizador']??'Histórico: sin dato')?></td><td><?=cg_h($a['motivo'])?></td></tr><?php endforeach;?><?php if(!$arqueos):?><tr><td colspan="7">Sin arqueos registrados.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
</main>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>
</body></html>
