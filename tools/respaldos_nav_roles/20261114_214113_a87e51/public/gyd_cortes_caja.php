<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_cortes_autorizados.php';
require_once __DIR__.'/../app/helpers/pos_corte_denominaciones.php';
require_once __DIR__.'/../app/models/PuntoVenta.php';
header('Cache-Control: no-store, private');
$usuario = currentUser() ?? [];
try { gyd_corte_exigir($usuario); }
catch (DomainException $e) { http_response_code(403); exit('No tienes autorización para realizar cortes de caja.'); }
if (empty($_SESSION['gyd_cortes_csrf'])) $_SESSION['gyd_cortes_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['gyd_cortes_csrf'];
function gc_h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$error=''; $abiertos=[];
try {
    $db = (new Database())->connect();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'prueba') throw new DomainException('Este módulo solo funciona en la base de pruebas.');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new DomainException('La sesión expiró. Recarga la página.'); }
        $caja = (int)($_POST['caja_id'] ?? 0);
        gyd_corte_caja_permitida($db,$usuario,$caja);
        $st = $db->prepare("SELECT id FROM pos_turnos WHERE caja_id=? AND estado='ABIERTO' ORDER BY id DESC LIMIT 1");
        $st->execute([$caja]);$turnoId=(int)$st->fetchColumn();
        if ($turnoId < 1) throw new DomainException('Esta caja no tiene turno abierto.');
        $denom = (array)($_POST['denominaciones'] ?? []);
        $conteo = PosCorteDenominaciones::calcular($denom);
        $contado = number_format($conteo['total_centavos']/100,2,'.','');
        $autorizador = gyd_corte_autorizar($db,$usuario,$caja,'',(string)($_POST['contrasena'] ?? ''));
        // El permiso queda vinculado a este usuario, caja y turno; se consume al cerrar.
        gyd_gate_guardar('corte',$usuario,$caja,$turnoId,$autorizador);
        try { $resultado = (new PuntoVenta())->cerrarTurno($usuario,$caja,$contado,$denom); }
        catch(Throwable $ex) { gyd_gate_limpiar('corte'); throw $ex; }
        $_SESSION['gyd_corte_exito'] = 'Corte registrado correctamente. Folio del turno #'.(int)$resultado['turno_id'].'.';
        header('Location: pos_corte_retiros.php?turno_id='.(int)$resultado['turno_id'],true,303);exit;
    }
    $abiertos = $db->query("SELECT t.id,t.caja_id,t.abierto_en,s.nombre sucursal,c.nombre caja,u.nombre cajero
        FROM pos_turnos t JOIN pos_cajas c ON c.id=t.caja_id
        JOIN pos_sucursales s ON s.id=c.sucursal_id
        JOIN usuarios u ON u.id=t.usuario_apertura_id
        WHERE t.estado='ABIERTO' AND c.activa=1 AND s.activo=1 ORDER BY s.nombre,c.nombre,t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { $error = $e instanceof DomainException ? $e->getMessage() : 'Error al cargar o registrar el corte. Revisa el log del servidor.'; error_log('G&D corte restringido: '.$e->getMessage()); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Cortes de caja</title>
<style>body{margin:0;background:#eef3f8;font-family:system-ui,Arial,sans-serif;color:#11395b}header{background:linear-gradient(115deg,#103a65,#1d609a);color:#fff;padding:20px max(20px,calc((100% - 1060px)/2))}header a{color:#fff}main{max-width:1060px;margin:26px auto;padding:0 16px}.card{background:white;border:1px solid #d2e1ef;border-radius:14px;padding:24px;box-shadow:0 4px 20px #173b5d0b}h1{margin:5px 0 8px}h2{margin:0 0 10px}.subtitle{opacity:.9}.alert{padding:12px 16px;background:#fff0e1;border-radius:10px;color:#8a4515;margin-bottom:18px}.field{display:flex;flex-direction:column;gap:8px;font-weight:700;margin:16px 0}select,input{font:inherit;padding:11px;border:1px solid #afc6de;border-radius:8px;background:#fff;max-width:100%}.denoms{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-top:12px}.denoms label{display:flex;flex-direction:column;gap:6px;font-size:14px;font-weight:600}.denoms input{width:100%;box-sizing:border-box}.total{background:#e6f1fc;border-radius:10px;padding:16px;margin-top:20px;display:flex;justify-content:space-between;font-size:19px}.primary{background:#145da0;color:white;padding:13px 20px;border:0;border-radius:8px;font-weight:700;cursor:pointer;margin-top:18px}.help{font-size:13px;color:#4c6580}.muted{color:#58728a}input:focus,select:focus{outline:2px solid #3c87bf}button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:600px){header{padding:18px}.card{padding:15px}} </style></head><body>
<header><div><strong>G&D · FARMACIA</strong><h1>Cortes de caja</h1><span class="subtitle">Acceso restringido a Gerencia, Dirección, Contabilidad y Administración</span></div></header>
<main><p><a href="dashboard.php">← Regresar al inicio</a> &nbsp; <a href="pos.php">Punto de Venta (si tienes acceso)</a></p>
<?php if($error):?><div class="alert" role="alert"><?=gc_h($error)?></div><?php endif;?>
<div class="card"><h2>Realizar corte de caja</h2><p class="muted">Selecciona una caja con turno abierto y cuenta el efectivo por denominación. Este conteo es ciego: no muestra el esperado antes de confirmar. El corte cerrará el turno y se registrará con tu usuario.</p>
<?php if(!$abiertos):?><div class="alert">No hay cajas con turnos abiertos disponibles.</div><?php else:?>
<form method="post" autocomplete="off" id="corte-form"><input type="hidden" name="csrf" value="<?=gc_h($csrf)?>">
<label class="field">Caja con turno abierto<select name="caja_id" required><?php foreach($abiertos as $t):?><option value="<?=(int)$t['caja_id']?>"><?=gc_h($t['sucursal'].' · '.$t['caja'].' · Cajero: '.$t['cajero'].' · Turno #'.$t['id'])?></option><?php endforeach;?></select></label>
<h2>Conteo físico</h2><div class="denoms">
<?php foreach(PosCorteDenominaciones::VALORES as $codigo=>$valor):?>
<label><?=gc_h(($codigo[0]==='b'?'Billete':'Moneda').' $'.number_format($valor/100,($valor%100?2:0),'.',','))?><input type="number" name="denominaciones[<?=gc_h($codigo)?>]" value="0" min="0" max="99999" step="1" inputmode="numeric" required data-valor="<?=$valor?>"></label>
<?php endforeach;?></div>
<div class="total"><span>Total contado</span><strong id="total-contado">$0.00</strong></div>
<label class="field">Confirma con la contraseña de tu propia cuenta<input type="password" name="contrasena" required maxlength="512" autocomplete="current-password"></label>
<p class="help">No se permiten claves de otras personas. El cajero de apertura queda registrado por separado. La contraseña no se almacena en la bitácora.</p>
<button class="primary" id="confirmar" type="submit">Confirmar corte y cerrar turno</button>
</form><?php endif;?></div></main>
<script>document.querySelectorAll('[data-valor]').forEach(i=>i.addEventListener('input',()=>{let c=0;document.querySelectorAll('[data-valor]').forEach(el=>{c+=(Number.parseInt(el.value,10)||0)*Number(el.dataset.valor)});document.getElementById('total-contado').textContent=(c/100).toLocaleString('es-MX',{style:'currency',currency:'MXN'});}));const f=document.getElementById('corte-form');if(f)f.addEventListener('submit',e=>{if(!confirm('¿Confirmas el cierre definitivo del turno y el efectivo contado?'))e.preventDefault();});</script>

<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
