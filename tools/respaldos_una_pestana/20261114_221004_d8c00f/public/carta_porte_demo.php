<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
require_once __DIR__.'/../app/models/CartaPorteDemoGyD.php';
$user=currentUser()?:[];
if(!CartaPorteDemoGyD::permitted($user)){http_response_code(403);exit('No tienes permiso para utilizar Carta Porte DEMO.');}
if(session_status()!==PHP_SESSION_ACTIVE){http_response_code(500);exit('La sesión no está disponible.');}
if(!isset($_SESSION['cp_demo_csrf']))$_SESSION['cp_demo_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['cp_demo_csrf'];
function cpd_h(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$model=new CartaPorteDemoGyD();$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$error='';$notice=(string)($_SESSION['cp_demo_notice']??'');unset($_SESSION['cp_demo_notice']);
$traspaso=null;$hist=[];$comp=null;$data=null;$lista=[];
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new DomainException('La sesión del formulario expiró. Recarga la pantalla.');
        if((string)($_POST['accion']??'')!=='generar')throw new DomainException('Acción desconocida.');
        $row=$model->generar($user,$id,$_POST);
        $_SESSION['cp_demo_notice']='Simulación registrada. No se realizó timbrado fiscal ni se cambió inventario.';
        header('Location: carta_porte_demo.php?id='.$id.'&comprobante='.(int)$row['id'],true,303);exit;
    }
    if($id>0){
        $traspaso=$model->traspaso($user,$id);
        $hist=$model->historial($user,$id);
        $registro=max(0,(int)($_GET['comprobante']??0));
        if($registro){
            $comp=$model->comprobante($user,$id,$registro);
            $data=json_decode((string)$comp['resumen_json'],true,512,JSON_THROW_ON_ERROR);
        }
        if($comp&&isset($_GET['descargar'])){
            $format=(string)$_GET['descargar'];
            if($format==='json'){
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="'.$comp['folio_demo'].'.json"');
                header('X-Content-Type-Options: nosniff');
                echo json_encode(['ADVERTENCIA'=>'DOCUMENTO DEMO - SIN VALIDEZ FISCAL','folio'=>$comp['folio_demo'],'resultado'=>$comp['resultado'],'datos'=>$data],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
            }
            if($format==='xml'){
                // Intencionalmente NO se usa la estructura oficial cfdi:Comprobante.
                $folioXml=htmlspecialchars((string)$comp['folio_demo'],ENT_XML1|ENT_QUOTES,'UTF-8');
                $estadoXml=htmlspecialchars((string)$comp['resultado'],ENT_XML1|ENT_QUOTES,'UTF-8');
                $datosXml=htmlspecialchars((string)$comp['resumen_json'],ENT_XML1|ENT_QUOTES,'UTF-8');
                $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".
                  '<SimulacionCartaPorte Ambiente="DEMO" ValidezFiscal="NINGUNA" NoEsCFDI="true">'."\n".
                  '  <Leyenda>SIN VALIDEZ FISCAL - NO TIMBRADO POR EL SAT</Leyenda>'."\n".
                  '  <FolioDemo>'.$folioXml.'</FolioDemo>'."\n".
                  '  <EstadoSimulado>'.$estadoXml.'</EstadoSimulado>'."\n".
                  '  <DatosReferencia>'.$datosXml.'</DatosReferencia>'."\n".
                  '</SimulacionCartaPorte>'."\n";
                header('Content-Type: application/xml; charset=UTF-8');
                header('Content-Disposition: attachment; filename="'.$comp['folio_demo'].'.demo.xml"');
                header('X-Content-Type-Options: nosniff');echo $xml;exit;
            }
            throw new DomainException('Formato de descarga no admitido.');
        }
    }else{$lista=$model->listado($user);}
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Carta Porte demo: '.$e->getMessage());$error='No se pudo completar la operación. Verifica el instalador y los registros del ERP.';}
$puedeGenerar=$traspaso && in_array($traspaso['estado'],['SOLICITADO','POR_PREPARAR','PREPARADO'],true)
    &&(TraspasosGyD::global($user)||TraspasosGyD::sucursalUsuario($user)===(int)$traspaso['origen_id']);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>G&D · Carta Porte de Prueba</title>
<style>
:root{font-family:system-ui,-apple-system,'Segoe UI',Arial,sans-serif;color:#15304e;background:#eef3f8}*{box-sizing:border-box}body{margin:0}header{background:#153c64;color:white;padding:23px 4vw}header h1{font-size:26px;margin:0}header p{opacity:.88;margin:5px 0 0}.wrap{max-width:1150px;margin:auto;padding:22px 14px}.card{background:white;padding:22px;border:1px solid #d8e2ed;box-shadow:0 2px 13px #12345612;border-radius:14px;margin-bottom:16px}h2{margin:0 0 15px;font-size:19px}h3{font-size:16px;margin:18px 0 10px}.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:13px}label{display:block;font-size:13px;font-weight:700;margin:0 0 5px}input,select,textarea{width:100%;padding:10px 11px;border:1px solid #b8cada;border-radius:8px;font:inherit;color:#1b3655;background:white}textarea{min-height:62px}button,.btn{display:inline-block;border:0;border-radius:8px;padding:11px 14px;background:#145eac;color:#fff;font-weight:700;font-size:14px;text-decoration:none;cursor:pointer}.btn.alt,button.alt{background:#e8f0f8;color:#174d7d}.muted{color:#60758a;font-size:13px}.alert{border-radius:10px;padding:13px 15px;background:#fff4da;border:1px solid #e5cb86;margin-bottom:15px;color:#754f05}.alert.good{background:#e6f7ed;border-color:#a7d8bc;color:#145a35}.alert.bad{background:#fde9e9;border-color:#e9b1b1;color:#903333}.hero{background:#b4233d;color:white;border-radius:10px;padding:19px;text-align:center;font-weight:900;font-size:24px;letter-spacing:.045em;margin-bottom:15px}table{width:100%;border-collapse:collapse;min-width:540px}th{background:#164a7a;color:white;text-align:left}td,th{padding:10px;border-bottom:1px solid #dde5ee;font-size:13px;vertical-align:top}.overflow{overflow:auto}code{overflow-wrap:anywhere}.status{border-radius:20px;background:#fff1d7;color:#916508;padding:4px 9px;font-size:12px;font-weight:700}dl{display:grid;grid-template-columns:160px 1fr;gap:7px;margin:0;font-size:14px}dt{color:#56718c}dd{margin:0;overflow-wrap:anywhere}.section-label{color:#245984;font-weight:800;font-size:14px;padding-bottom:8px;border-bottom:1px solid #dde5ee;margin-bottom:12px}@media print{header,.noprint,form{display:none!important}body{background:#fff}.wrap{max-width:none;padding:0}.card{box-shadow:none;border:1px solid #bbb;break-inside:avoid}.hero{font-size:25px}a{color:#000}}
</style></head><body>
<header><h1>Carta Porte · Entorno de Pruebas</h1><p>Simulación de respuesta fiscal para transporte con camiones propios · Farmacia G&D</p></header>
<main class="wrap">
<div class="hero">DEMO — SIN VALIDEZ FISCAL</div>
<div class="row noprint" style="margin-bottom:15px"><a class="btn alt" href="traspasos.php">← Traspasos</a><?php if($traspaso):?><a class="btn alt" href="carta_porte_demo.php">Otros traspasos</a><a class="btn alt" href="traspasos.php?id=<?=(int)$traspaso['id']?>">Ver traspaso <?=cpd_h($traspaso['folio'])?></a><?php endif;?></div>
<div class="alert">Este módulo <strong>no se comunica con el SAT ni con un PAC</strong>. El resultado «aceptado» es inventado por el simulador. Un documento DEMO nunca debe acompañar un traslado real como si fuera un CFDI. Tampoco modifica las existencias ni habilita automáticamente el despacho.</div>
<?php if($notice):?><div class="alert good"><?=cpd_h($notice)?></div><?php endif;?>
<?php if($error):?><div class="alert bad"><?=cpd_h($error)?></div><?php endif;?>
<?php if(!$traspaso):?>
<section class="card"><h2>Selecciona un traspaso existente</h2><div class="overflow"><table><thead><tr><th>Folio</th><th>Origen</th><th>Destino</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($lista as $r):?><tr><td><?=cpd_h($r['folio'])?></td><td><?=cpd_h($r['origen'])?></td><td><?=cpd_h($r['destino'])?></td><td><?=cpd_h($r['estado'])?></td><td><a class="btn alt" href="?id=<?=(int)$r['id']?>">Abrir DEMO</a></td></tr><?php endforeach;?>
<?php if(!$lista):?><tr><td colspan="5">No se encontraron traspasos.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:?>
<section class="card"><h2>Traspaso <?=cpd_h($traspaso['folio'])?></h2><div class="grid"><div><span class="muted">Origen</span><div><b><?=cpd_h($traspaso['origen'])?></b></div></div><div><span class="muted">Destino</span><div><b><?=cpd_h($traspaso['destino'])?></b></div></div><div><span class="muted">Estado operativo</span><div><b><?=cpd_h($traspaso['estado'])?></b></div></div></div></section>
<?php if($comp && is_array($data)):?>
<section class="card">
<div class="hero">DOCUMENTO FICTICIO · NO ES UN CFDI</div>
<div class="row noprint" style="margin-bottom:15px"><button onclick="window.print()">Imprimir / Guardar como PDF</button><a class="btn alt" href="?id=<?=$id?>&amp;comprobante=<?=(int)$comp['id']?>&amp;descargar=json">Descargar JSON DEMO</a><a class="btn alt" href="?id=<?=$id?>&amp;comprobante=<?=(int)$comp['id']?>&amp;descargar=xml">Descargar XML DEMO (no CFDI)</a><a class="btn alt" href="?id=<?=$id?>">Volver al formulario</a></div>
<h2>Acuse de simulación</h2>
<dl><dt>Folio no fiscal</dt><dd><strong><?=cpd_h($comp['folio_demo'])?></strong></dd><dt>Resultado ficticio</dt><dd><?=cpd_h($comp['resultado'])?></dd><dt>Fecha de prueba</dt><dd><?=cpd_h($comp['fecha_registro'])?></dd><dt>Mensaje</dt><dd><?=cpd_h($comp['mensaje'])?></dd><dt>Huella SHA256 de datos</dt><dd><code><?=cpd_h($comp['huella_sha256'])?></code></dd><dt>UUID fiscal</dt><dd>NO EXISTE</dd><dt>IdCCP fiscal</dt><dd>NO EXISTE</dd><dt>Sellos digitales</dt><dd>NO EXISTEN</dd></dl>
<h3>Datos de referencia de la empresa</h3><dl><dt>Razón social</dt><dd><?=cpd_h($data['emisor_receptor']['nombre']??'')?></dd><dt>RFC emisor y receptor</dt><dd><?=cpd_h($data['emisor_receptor']['rfc']??'')?></dd><dt>Régimen</dt><dd><?=cpd_h($data['emisor_receptor']['regimen_fiscal']??'')?></dd><dt>CP fiscal</dt><dd><?=cpd_h($data['emisor_receptor']['cp_domicilio_fiscal']??'')?></dd></dl>
<h3>Trayecto simulado</h3><dl><dt>Origen</dt><dd><?=cpd_h($data['origen']['nombre']??'')?> — <?=cpd_h($data['origen']['domicilio']??'')?> (<?=cpd_h($data['origen']['cp']??'')?>)</dd><dt>Destino</dt><dd><?=cpd_h($data['destino']['nombre']??'')?> — <?=cpd_h($data['destino']['domicilio']??'')?> (<?=cpd_h($data['destino']['cp']??'')?>)</dd><dt>Salida programada</dt><dd><?=cpd_h($data['viaje']['fecha_salida']??'')?></dd><dt>Kilómetros</dt><dd><?=cpd_h($data['viaje']['distancia_km']??'')?></dd><dt>Peso total kg</dt><dd><?=cpd_h($data['viaje']['peso_total_kg']??'')?></dd><dt>Camión</dt><dd><?=cpd_h($data['vehiculo']['placas']??'')?> / <?=cpd_h($data['vehiculo']['configuracion_vehicular']??'')?></dd><dt>Chofer</dt><dd><?=cpd_h($data['operador']['nombre']??'')?> (RFC <?=cpd_h($data['operador']['rfc']??'')?>)</dd></dl>
<h3>Mercancía</h3><div class="overflow"><table><thead><tr><th>Producto</th><th>Piezas</th><th>Clave SAT declarada (sin validar)</th><th>Unidad</th><th>Peso kg</th></tr></thead><tbody><?php foreach(($data['mercancias']??[]) as $r):?><tr><td><?=cpd_h($r['codigo_interno']??'')?> — <?=cpd_h($r['descripcion']??'')?></td><td><?=cpd_h($r['piezas']??'')?></td><td><?=cpd_h($r['clave_bienes_transp_declarada']??'')?></td><td><?=cpd_h($r['clave_unidad_declarada']??'')?></td><td><?=cpd_h($r['peso_partida_kg']??'')?></td></tr><?php endforeach;?></tbody></table></div><p class="alert">Sin validez fiscal. No demuestra que los datos cumplan con todos los catálogos o validaciones del SAT. No tiene folio fiscal, certificado ni timbrado.</p>
</section>
<?php endif;?>
<?php if($puedeGenerar):?>
<section class="card noprint">
<h2>Generar respuesta de PAC ficticio</h2>
<p class="muted">El emisor fiscal se precarga desde la constancia. Los datos de ruta, camión y operador se capturan por viaje. Para probar, puedes introducir información ficticia; nunca servirá para un CFDI real.</p>
<div class="grid" style="margin-bottom:18px"><div><label>Razón social</label><input readonly value="<?=cpd_h(CartaPorteDemoGyD::NOMBRE_EMISOR)?>"></div><div><label>RFC emisor y receptor</label><input readonly value="<?=cpd_h(CartaPorteDemoGyD::RFC_EMISOR)?>"></div><div><label>Régimen y CP fiscal</label><input readonly value="<?=cpd_h(CartaPorteDemoGyD::REGIMEN_EMISOR)?> · <?=cpd_h(CartaPorteDemoGyD::CP_FISCAL)?>"></div></div>
<form method="post" action="?id=<?=$id?>" id="simular"><input type="hidden" name="csrf" value="<?=cpd_h($csrf)?>"><input type="hidden" name="token" value="<?=bin2hex(random_bytes(16))?>"><input type="hidden" name="accion" value="generar"><input type="hidden" name="id" value="<?=$id?>">
<div class="section-label">Ruta y salida</div>
<div class="grid"><div><label>Código postal origen (físico)</label><input name="cp_origen" pattern="[0-9]{5}" maxlength="5" required placeholder="CP punto de salida"></div><div><label>Domicilio real origen</label><input name="domicilio_origen" maxlength="200" required placeholder="Calle, número, colonia y municipio"></div><div><label>Código postal destino (físico)</label><input name="cp_destino" pattern="[0-9]{5}" maxlength="5" required></div><div><label>Domicilio real destino</label><input name="domicilio_destino" maxlength="200" required></div><div><label>Fecha y hora salida programada</label><input name="salida" type="datetime-local" required value="<?=date('Y-m-d\TH:i')?>"></div><div><label>Distancia total (km)</label><input name="km" type="number" step="0.01" min="0.01" max="99999" required></div></div>
<h3>Camión propio</h3><div class="grid"><div><label>Placas</label><input name="placas" required maxlength="20" placeholder="DEM-123-A (ejemplo)"></div><div><label>Configuración vehicular</label><input name="configuracion" required maxlength="20" placeholder="Clave de catálogo, ej. C2"></div><div><label>Tipo de permiso SICT</label><input name="permiso_sict" required maxlength="40" placeholder="Clave del permiso"></div><div><label>Número de permiso</label><input name="numero_permiso" required maxlength="60"></div><div><label>Aseguradora</label><input name="aseguradora" required maxlength="100"></div><div><label>Póliza de responsabilidad civil</label><input name="poliza" required maxlength="70"></div></div>
<h3>Operador</h3><div class="grid"><div><label>Nombre completo</label><input name="conductor" maxlength="150" required></div><div><label>RFC</label><input name="rfc_operador" maxlength="13" required placeholder="Solo DEMO: XAXX010101000"></div><div><label>Número de licencia</label><input name="licencia" maxlength="40" required></div></div>
<h3>Datos de mercancías para la simulación</h3><p class="muted">Los productos y cantidades vienen del traspaso. Las claves y el peso se capturan por partida, sin validarse contra catálogos fiscales.</p>
<div class="overflow"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Clave bienes transportados (8 dígitos)</th><th>Unidad SAT</th><th>Peso total de partida (kg)</th></tr></thead><tbody><?php foreach($traspaso['items'] as $r):$pid=(int)$r['producto_id'];?><tr><td><?=cpd_h($r['codigo'])?><div class="muted"><?=cpd_h($r['descripcion'])?></div></td><td><?=(int)$r['solicitado']?></td><td><input required name="mercancias[<?=$pid?>][clave_sat]" pattern="[0-9]{8}" maxlength="8" placeholder="Clave SAT"></td><td><input required name="mercancias[<?=$pid?>][unidad_sat]" maxlength="5" placeholder="H87"></td><td><input required name="mercancias[<?=$pid?>][peso_kg]" type="number" min="0.001" step="0.001" placeholder="0.000"></td></tr><?php endforeach;?></tbody></table></div>
<h3>Respuesta que queremos simular</h3><div class="grid"><div><label>Resultado ficticio</label><select name="resultado"><option value="ACEPTADO_DEMO">Aceptación simulada</option><option value="RECHAZADO_DEMO">Rechazo simulado</option><option value="SIN_RESPUESTA_DEMO">PAC sin respuesta (simulado)</option></select></div></div>
<div class="row" style="margin-top:15px"><button type="submit">Generar documento DEMO</button><button type="button" class="alt" id="rellenar">Rellenar ejemplo ficticio (sin ruta)</button></div></form>
</section>
<script>
(()=>{const b=document.getElementById('rellenar');const f=document.getElementById('simular');if(!b||!f)return;
b.addEventListener('click',()=>{const ejemplos={placas:'DEM-123-A',configuracion:'C2',permiso_sict:'TPAF01',numero_permiso:'PERMISO-DEMO',aseguradora:'ASEGURADORA DEMO',poliza:'POLIZA-DEMO',conductor:'OPERADOR DE PRUEBA',rfc_operador:'XAXX010101000',licencia:'LICENCIA-DEMO'};for(const [k,v] of Object.entries(ejemplos)){const el=f.elements.namedItem(k);if(el)el.value=v;}});
})();
</script>
<?php else:?><section class="card noprint"><p class="muted">Este traspaso ya fue despachado, cancelado o no corresponde a tu sucursal de origen. Puedes consultar su historial DEMO, pero no generar nuevos intentos.</p></section><?php endif;?>
<section class="card noprint"><h2>Historial de simulaciones</h2><div class="overflow"><table><thead><tr><th>Fecha</th><th>Folio ficticio</th><th>Resultado</th><th>Documento</th></tr></thead><tbody><?php foreach($hist as $r):?><tr><td><?=cpd_h($r['fecha_registro'])?></td><td><code><?=cpd_h($r['folio_demo'])?></code></td><td><span class="status"><?=cpd_h($r['resultado'])?></span></td><td><a class="btn alt" href="?id=<?=$id?>&amp;comprobante=<?=(int)$r['id']?>">Ver comprobante DEMO</a></td></tr><?php endforeach;?><?php if(!$hist):?><tr><td colspan="4">Sin simulaciones registradas.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
</main>
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
