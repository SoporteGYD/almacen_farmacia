<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_centro_notificaciones.php';
requireLogin();
$user=currentUser()?:[];
if(!gdnt_can_read($user)){http_response_code(403);exit('Sin permiso para el centro de notificaciones.');}
header('Cache-Control: private, no-store');
if(empty($_SESSION['gyd_notif_csrf']))$_SESSION['gyd_notif_csrf']=bin2hex(random_bytes(32));
$token=(string)$_SESSION['gyd_notif_csrf'];
$error='';$items=[];$counts=[];$mensaje='';
try {
    $db=(new Database())->connect();
    $items=gdnt_items($db,$user);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new RuntimeException('La sesión del formulario expiró. Actualiza la página.');
        $key=(string)($_POST['key']??'');
        $permitidas=array_column($items,'key');
        if(!in_array($key,$permitidas,true))throw new RuntimeException('El aviso no corresponde a tu usuario o ya no existe.');
        if(!preg_match('/^(RECEPCION|OC_BODEGA|ALERTA_COMPRA):([1-9][0-9]*)$/D',$key,$parts))throw new RuntimeException('Aviso inválido.');
        $s=$db->prepare('INSERT INTO erp_notificaciones_lecturas(usuario_id,tipo,origen_id,leido_en) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE leido_en=VALUES(leido_en)');
        $s->execute([(int)$user['id'],$parts[1],(int)$parts[2]]);
        header('Location: erp_notificaciones.php?leido=1',true,303);exit;
    }
    $counts=gdnt_counts($items);
} catch(Throwable $e) {
    error_log('G&D Notificaciones: '.$e->getMessage());
    $error=$e instanceof RuntimeException?$e->getMessage():'No fue posible consultar los avisos. Verifica el instalador y la conexión.';
}
$filter=trim((string)($_GET['filtro']??'pendientes'));
if(!in_array($filter,['pendientes','sin_leer','todos'],true))$filter='pendientes';
$visible=array_values(array_filter($items,static function(array $i)use($filter){return $filter==='todos'||($filter==='sin_leer'?($i['pendiente']&&!$i['leido']):$i['pendiente']);}));
$back=gdnt_role($user)==='COMPRAS'?'erp_compras_inicio.php':'dashboard.php';
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Centro de notificaciones</title>
<style>
:root{font-family:system-ui,Segoe UI,Arial,sans-serif;color:#173a5c;background:#f1f5fa}*{box-sizing:border-box}body{margin:0}header{background:#153f6b;color:white;padding:23px max(16px,calc((100% - 1190px)/2))}header h1{font-size:23px;margin:0 0 8px}header a{color:white}main{max-width:1190px;margin:20px auto;padding:0 16px 45px}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.card{border:1px solid #dce7f2;background:white;border-radius:13px;padding:18px;margin:12px 0}.stat strong{display:block;font-size:27px;color:#13548e}.stat span{font-size:13px;color:#58718b}.flex{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.pill{display:inline-block;padding:5px 9px;border-radius:17px;font-size:11px;font-weight:700}.amarillo{background:#fff1cf;color:#765009}.verde{background:#dcf8e5;color:#166534}.rojo{background:#ffdddd;color:#952537}.leido{background:#e9f0f6;color:#4d657e}.btn{display:inline-block;background:#125a9d;color:white;text-decoration:none;border:0;border-radius:8px;padding:9px 13px;font:inherit;font-size:13px;cursor:pointer}.btn.secondary{color:#144a7d;background:#e8f1fa}.notice{border-left:4px solid #deb056}.notice.resuelta{border-left-color:#2b9c66}.note{color:#566c83;font-size:13px;line-height:1.5}.meta{font-size:12px;color:#667b91;margin:5px 0 10px}.actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}h2{font-size:17px;margin:0}.error{background:#ffebeb;color:#922;border:1px solid #eaa;border-radius:10px;padding:12px}button:focus-visible,a:focus-visible{outline:3px solid #f5bc45;outline-offset:3px}@media(max-width:500px){header h1{font-size:19px}.card{padding:14px}}
</style></head><body>
<header><h1>🔔 Centro de notificaciones G&D</h1><a href="<?=gdnt_escape($back)?>">← Volver a mi inicio</a></header><main>
<?php if($error):?><p class="error" role="alert"><?=gdnt_escape($error)?></p><?php endif;?>
<?php if(isset($_GET['leido'])):?><p class="card">Aviso marcado como leído para tu usuario. La tarea permanece pendiente hasta completarse.</p><?php endif;?>
<div class="stats"><div class="card stat"><strong><?= (int)($counts['total']??0) ?></strong><span>Tareas pendientes</span></div><div class="card stat"><strong><?= (int)($counts['sin_leer']??0) ?></strong><span>Avisos sin leer</span></div><div class="card stat"><strong><?= (int)($counts['compras']??0) ?></strong><span>Compras</span></div><div class="card stat"><strong><?= (int)($counts['almacen']??0) ?></strong><span>Almacén</span></div></div>
<div class="card"><div class="flex"><b>Mostrar</b><div class="actions"><a class="btn <?= $filter==='pendientes'?'':'secondary' ?>" href="?filtro=pendientes">Pendientes</a><a class="btn <?= $filter==='sin_leer'?'':'secondary' ?>" href="?filtro=sin_leer">Sin leer</a><a class="btn <?= $filter==='todos'?'':'secondary' ?>" href="?filtro=todos">Historial</a></div></div><p class="note">Las tareas se derivan de órdenes, recepciones, facturas y movimientos confirmados. Leer una notificación no cambia existencias ni elimina el pendiente. Tickets y Resurtidos conservan sus indicadores propios.</p></div>
<?php foreach($visible as $item):?><article class="card notice <?= $item['pendiente']?'':'resuelta' ?>"><div class="flex"><div><div class="meta"><?=gdnt_escape($item['area'])?> · <?=gdnt_escape($item['fecha'])?></div><h2><?=gdnt_escape($item['titulo'])?></h2></div><div class="actions"><span class="pill <?=gdnt_escape($item['prioridad'])?>"><?=gdnt_escape($item['estado'])?></span><span class="pill <?= $item['leido']?'leido':'amarillo' ?>"><?= $item['leido']?'Leído':'Sin leer' ?></span></div></div><p class="note"><?=gdnt_escape($item['detalle'])?></p><div class="actions"><a class="btn" href="<?=gdnt_escape($item['url'])?>">Abrir trámite</a><?php if(!$item['leido']):?><form method="post"><input type="hidden" name="csrf" value="<?=gdnt_escape($token)?>"><input type="hidden" name="key" value="<?=gdnt_escape($item['key'])?>"><button type="submit" class="btn secondary">Marcar leído</button></form><?php endif;?></div></article><?php endforeach;?>
<?php if(!$visible && !$error):?><div class="card">No tienes avisos en esta vista.</div><?php endif;?>
<p class="note">Una notificación de recepción cerrada puede permanecer en Historial. En varias recepciones de un mismo código, la distribución de factura/aplicación es orientativa y requiere comprobar el folio correspondiente.</p>
</main></body></html>
