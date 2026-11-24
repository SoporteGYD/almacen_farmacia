<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/config/database.php';
requireLogin();
$u = currentUser() ?: [];
$rol = strtoupper(trim((string)($u['rol'] ?? '')));
if (!in_array($rol, ['ADMINISTRADOR','ADMIN','GERENTE','CONSULTA','COMPRAS'], true)) {
    http_response_code(403); exit('Sin permiso para consultar proveedores.');
}
$editar = in_array($rol, ['ADMINISTRADOR','ADMIN','COMPRAS'], true);
function ep_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ep_esInterno(string $n): bool {
    return (bool)preg_match('/\b(TRASPASO|TRASLADO|AJUSTE|INVENTARIO INICIAL|SALIDA A TIENDA)\b/ui', $n);
}
function ep_url(array $changes = []): string {
    return 'erp_proveedores.php?' . http_build_query(array_merge(['q'=>(string)($_GET['q']??''),'estado'=>(string)($_GET['estado']??'todos')],$changes));
}
if (empty($_SESSION['erp_prov_csrf'])) $_SESSION['erp_prov_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['erp_prov_csrf'];
$flash = $_SESSION['erp_prov_flash'] ?? null;
unset($_SESSION['erp_prov_flash']);
$error = null; $pdo = null;
try { $pdo = (new Database())->connect(); } catch(Throwable $e) { error_log('ERP proveedores DB: '.$e->getMessage()); $error='No fue posible conectar con la base de datos.'; }
if ($pdo && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$editar) { http_response_code(403); exit('Solo el administrador puede modificar proveedores.'); }
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) { http_response_code(419); exit('Sesión de formulario vencida. Actualiza la página.'); }
    try {
        $id=max(0,(int)($_POST['id']??0));
        $nombre=preg_replace('/\s+/u',' ',trim((string)($_POST['nombre']??''))) ?: '';
        $telefono=trim((string)($_POST['telefono']??''));
        $correo=trim((string)($_POST['correo']??''));
        $direccion=trim((string)($_POST['direccion']??''));
        if ($nombre==='' || mb_strlen($nombre)>150) throw new RuntimeException('Escribe un nombre válido de hasta 150 caracteres.');
        if (mb_strlen($telefono)>30 || mb_strlen($correo)>150 || mb_strlen($direccion)>255) throw new RuntimeException('Alguno de los campos supera la longitud permitida.');
        if ($correo!=='' && !filter_var($correo,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo electrónico no es válido.');
        if (ep_esInterno($nombre)) throw new RuntimeException('Los movimientos internos (traspasos o ajustes) no deben registrarse como proveedores comerciales.');
        $viejo=null;
        if($id>0) {
            $st=$pdo->prepare('SELECT * FROM proveedores WHERE id = ?'); $st->execute([$id]); $viejo=$st->fetch(PDO::FETCH_ASSOC);
            if(!$viejo) throw new RuntimeException('No se encontró el proveedor.');
            if(ep_esInterno((string)$viejo['nombre'])) throw new RuntimeException('El registro corresponde a un concepto interno y está protegido.');
        }
        $st=$pdo->prepare('SELECT id FROM proveedores WHERE LOWER(TRIM(nombre)) = LOWER(?) AND id <> ? LIMIT 1');
        $st->execute([$nombre,$id]);
        if($st->fetchColumn()) throw new RuntimeException('Ya existe un proveedor con ese nombre.');
        $estado=isset($_POST['estado']) && $_POST['estado']==='0' ? 0:1;
        if($id>0) {
            $st=$pdo->prepare('UPDATE proveedores SET nombre=?,telefono=?,correo=?,direccion=?,estado=? WHERE id=?');
            $st->execute([$nombre,$telefono?:null,$correo?:null,$direccion?:null,$estado,$id]);
        } else {
            $st=$pdo->prepare('INSERT INTO proveedores (nombre,telefono,correo,direccion,estado) VALUES (?,?,?,?,?)');
            $st->execute([$nombre,$telefono?:null,$correo?:null,$direccion?:null,$estado]);
            $id=(int)$pdo->lastInsertId();
        }
        auditLog(['modulo'=>'ERP Proveedores','accion'=>$viejo?'EDITAR_PROVEEDOR':'CREAR_PROVEEDOR','entidad'=>'proveedores','registro_id'=>(string)$id,'descripcion'=>($viejo?'Actualizó':'Registró').' el proveedor '.$nombre.'.','datos_anteriores'=>$viejo,'datos_nuevos'=>['nombre'=>$nombre,'telefono'=>$telefono,'correo'=>$correo,'direccion'=>$direccion,'estado'=>$estado]]);
        $_SESSION['erp_prov_flash']='Proveedor guardado correctamente.';
        header('Location: erp_proveedores.php',true,303); exit;
    } catch(Throwable $e) { $error=$e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar. Consulta el registro de errores.'; error_log('ERP proveedores guardar: '.$e->getMessage()); }
}
$q=mb_substr(trim((string)($_GET['q']??'')),0,120);
$estado=(string)($_GET['estado']??'todos');
if(!in_array($estado,['todos','activos','inactivos'],true)) $estado='todos';
$filas=[];$total=0;$resumen=['total'=>0,'activos'=>0,'inactivos'=>0];
if($pdo) {
    try {
        $resumen=$pdo->query('SELECT COUNT(*) total, COALESCE(SUM(estado=1),0) activos, COALESCE(SUM(estado=0),0) inactivos FROM proveedores')->fetch(PDO::FETCH_ASSOC) ?: $resumen;
        $w=[];$param=[];
        if($q!=='') { $w[]='(nombre LIKE :n OR correo LIKE :c OR telefono LIKE :t)';foreach(['n','c','t'] as $k)$param[$k]='%'.$q.'%'; }
        if($estado!=='todos') $w[]='estado = '.($estado==='activos'?'1':'0');
        $where=$w?' WHERE '.implode(' AND ',$w):'';
        $st=$pdo->prepare('SELECT COUNT(*) FROM proveedores'.$where);$st->execute($param);$total=(int)$st->fetchColumn();
        if(($_GET['export']??'')==='csv') {
            header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="proveedores_gyd.csv"');
            $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Nombre','Telefono','Correo','Direccion','Estado']);
            $st=$pdo->prepare('SELECT nombre,telefono,correo,direccion,estado FROM proveedores'.$where.' ORDER BY nombre');$st->execute($param);
            while($r=$st->fetch(PDO::FETCH_ASSOC)) { $r['estado']=((int)$r['estado']===1?'Activo':'Inactivo'); fputcsv($out,array_values($r)); }
            fclose($out);exit;
        }
        $pagina=max(1,(int)($_GET['pagina']??1));$maxpag=max(1,(int)ceil($total/25));$pagina=min($pagina,$maxpag);
        $st=$pdo->prepare('SELECT id,nombre,telefono,correo,direccion,estado FROM proveedores'.$where.' ORDER BY nombre LIMIT 25 OFFSET '.(($pagina-1)*25));$st->execute($param);$filas=$st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) { error_log('ERP proveedores lista: '.$e->getMessage());$error='No fue posible cargar el catálogo de proveedores.'; }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ERP G&D | Compras y Proveedores</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#173458;background:#edf2f8}*{box-sizing:border-box}body{margin:0}header{background:linear-gradient(110deg,#102b4c,#19538e);color:#fff;border-bottom:4px solid #df283c;padding:20px clamp(15px,4vw,48px);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}header h1{font-size:22px;margin:0}header p{margin:5px 0 0;color:#cfe1f6;font-size:13px}header a{color:#fff;border:1px solid #8baed6;padding:10px 14px;border-radius:10px;text-decoration:none}main{max-width:1320px;margin:auto;padding:22px 16px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.stat,.card{background:#fff;border-radius:14px;padding:19px;box-shadow:0 5px 22px #17345810}.stat b{display:block;font-size:27px;color:#1456a5}.stat span{color:#5e728a;font-size:13px}.card{margin-top:17px}h2{font-size:18px;margin:0 0 14px}.intro{color:#5d6f85;font-size:14px;line-height:1.5}.notice{background:#fff2d9;border:1px solid #ebcf8b;padding:12px;border-radius:10px;margin:12px 0}.success{background:#e4f8eb;border:1px solid #a9dec0}.tools{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.field{display:grid;gap:5px;flex:1;min-width:160px}.field label{font-size:13px;font-weight:700;color:#274669}input,select,textarea{font:inherit;padding:11px;border:1px solid #c6d4e6;border-radius:9px;width:100%;background:white}textarea{min-height:74px;resize:vertical}.btn,button{display:inline-block;padding:11px 15px;border:0;border-radius:9px;background:#1760b5;color:#fff;text-decoration:none;cursor:pointer;font-weight:700;font-size:14px}.btn.light{background:#e8f0fb;color:#174676}.btn.red{background:#b93544}.tablewrap{overflow-x:auto}table{width:100%;min-width:790px;border-collapse:collapse}th{background:#174b80;color:#fff;text-align:left;padding:12px;font-size:13px}td{border-bottom:1px solid #e6edf4;padding:12px;font-size:13px}td:first-child{font-weight:700}td small{display:block;color:#728398;margin-top:3px}.pill{display:inline-block;padding:5px 9px;border-radius:99px;background:#e4f7eb;color:#167040;font-weight:700;font-size:12px}.pill.off{background:#f1eef1;color:#775366}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.pager{display:flex;justify-content:flex-end;gap:9px;align-items:center;margin-top:17px}.pager a{color:#175ba8;text-decoration:none;padding:7px 12px;border:1px solid #c6d4e6;border-radius:8px}dialog{border:0;border-radius:17px;box-shadow:0 20px 75px #14283c44;width:min(590px,calc(100% - 20px));padding:0}dialog::backdrop{background:#12283b9e}.modal-head{background:#143e6e;color:white;padding:18px 22px}.modal-head h2{margin:0}.modal-body{padding:20px 22px}.formgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.hint{color:#708096;font-size:12px}.readonly{color:#845225;font-size:12px}button:focus-visible,a:focus-visible{outline:3px solid #f1be48;outline-offset:3px}@media(max-width:650px){.stats{grid-template-columns:1fr}.formgrid{grid-template-columns:1fr}.full{grid-column:auto}header h1{font-size:18px}main{padding:12px}}
</style></head><body><header><div><h1>ERP G&amp;D · COMPRAS Y PROVEEDORES</h1><p>Primera etapa: administración del catálogo de proveedores</p></div><a href="<?= $rol==='COMPRAS' ? 'erp_compras_inicio.php' : 'dashboard.php' ?>">← Volver al sistema</a></header><main>
<?php if($error):?><div class="notice" role="alert"><?=ep_h($error)?></div><?php endif;?>
<?php if($flash):?><div class="notice success" role="status"><?=ep_h($flash)?></div><?php endif;?>
<p class="intro">Consulta los proveedores que ya utiliza tu sistema de almacén. Los proveedores se comparten con las entradas de mercancía; no se duplican registros ni se modifican existencias al guardar esta información.</p>
<section class="stats"><div class="stat"><b><?=number_format((int)$resumen['total'])?></b><span>Registros de proveedores</span></div><div class="stat"><b><?=number_format((int)$resumen['activos'])?></b><span>Proveedores activos</span></div><div class="stat"><b><?=number_format((int)$resumen['inactivos'])?></b><span>Registros inactivos</span></div></section>
<section class="card"><div class="top"><h2>Buscar proveedores</h2><?php if($editar):?><button type="button" onclick="epNuevo()">＋ Agregar proveedor</button><?php endif;?></div><form class="tools" method="get"><div class="field"><label for="q">Nombre, teléfono o correo</label><input id="q" name="q" value="<?=ep_h($q)?>" placeholder="Ej. NADRO o SAHUAYO"></div><div class="field" style="flex:0 1 200px"><label for="estado">Estado</label><select id="estado" name="estado"><option value="todos" <?=$estado==='todos'?'selected':''?>>Todos</option><option value="activos" <?=$estado==='activos'?'selected':''?>>Activos</option><option value="inactivos" <?=$estado==='inactivos'?'selected':''?>>Inactivos</option></select></div><button>Buscar</button><a class="btn light" href="erp_proveedores.php">Limpiar</a><a class="btn light" href="<?=ep_h(ep_url(['export'=>'csv']))?>">Exportar CSV</a></form><p class="hint"><?=number_format($total)?> resultado(s)</p>
<div class="tablewrap"><table><thead><tr><th>Proveedor</th><th>Teléfono</th><th>Correo</th><th>Dirección</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($filas as $r): $interno=ep_esInterno((string)$r['nombre']); ?><tr><td><?=ep_h($r['nombre'])?><?php if($interno):?><small>Registro interno · protegido</small><?php endif;?></td><td><?=ep_h($r['telefono']?:'Por completar')?></td><td><?=ep_h($r['correo']?:'Por completar')?></td><td><?=ep_h($r['direccion']?:'Por completar')?></td><td><span class="pill <?=((int)$r['estado']===1?'':'off')?>"><?=((int)$r['estado']===1?'Activo':'Inactivo')?></span></td><td><?php if($editar && !$interno):?><button class="btn light" type="button" data-proveedor="<?=ep_h(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT))?>" onclick="epEditar(this)">Editar</button><?php else:?><span class="hint">Solo lectura</span><?php endif;?></td></tr><?php endforeach; if(!$filas):?><tr><td colspan="6">No hay proveedores con estos filtros.</td></tr><?php endif;?></tbody></table></div>
<div class="pager"><?php if(($pagina??1)>1):?><a href="<?=ep_h(ep_url(['pagina'=>$pagina-1]))?>">← Anterior</a><?php endif;?><span><?=($pagina??1)?> / <?=($maxpag??1)?></span><?php if(($pagina??1)<($maxpag??1)):?><a href="<?=ep_h(ep_url(['pagina'=>$pagina+1]))?>">Siguiente →</a><?php endif;?></div></section>
<div class="notice">Esta etapa administra proveedores. <b>Las órdenes de compra y las recepciones vinculadas</b> serán el siguiente desarrollo. No se registran compras ni entradas desde esta pantalla.</div>
</main>
<?php if($editar):?><dialog id="epDialog"><div class="modal-head"><h2 id="epTitle">Agregar proveedor</h2></div><form method="post" class="modal-body" autocomplete="off"><input type="hidden" name="csrf" value="<?=ep_h($csrf)?>"><input type="hidden" id="epId" name="id" value="0"><div class="formgrid"><div class="field full"><label for="epNombre">Nombre del proveedor *</label><input id="epNombre" name="nombre" required maxlength="150"></div><div class="field"><label for="epTelefono">Teléfono</label><input id="epTelefono" name="telefono" maxlength="30"></div><div class="field"><label for="epCorreo">Correo electrónico</label><input id="epCorreo" name="correo" type="email" maxlength="150"></div><div class="field full"><label for="epDireccion">Dirección</label><textarea id="epDireccion" name="direccion" maxlength="255"></textarea></div><div class="field full"><label for="epEstado">Estado</label><select id="epEstado" name="estado"><option value="1">Activo</option><option value="0">Inactivo</option></select></div></div><p class="hint">Desactivar un proveedor no elimina sus compras o movimientos anteriores.</p><div class="actions"><button type="button" class="btn light" onclick="document.getElementById('epDialog').close()">Cancelar</button><button type="submit">Guardar proveedor</button></div></form></dialog>
<script>function epNuevo(){const d=document.getElementById('epDialog'); d.querySelector('form').reset();document.getElementById('epId').value='0';document.getElementById('epTitle').textContent='Agregar proveedor';d.showModal();document.getElementById('epNombre').focus()}
function epEditar(btn){const p=JSON.parse(btn.getAttribute('data-proveedor')); document.getElementById('epId').value=p.id;document.getElementById('epNombre').value=p.nombre||'';document.getElementById('epTelefono').value=p.telefono||'';document.getElementById('epCorreo').value=p.correo||'';document.getElementById('epDireccion').value=p.direccion||'';document.getElementById('epEstado').value=String(p.estado);document.getElementById('epTitle').textContent='Editar proveedor';document.getElementById('epDialog').showModal()}</script><?php endif;?></body></html>
