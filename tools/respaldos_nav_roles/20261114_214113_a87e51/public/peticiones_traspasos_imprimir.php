<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';requireLogin();
require_once __DIR__.'/../app/models/PeticionesTraspasosGyD.php';
$u=currentUser()?:[];
if(!PeticionesTraspasosGyD::permitido($u)){http_response_code(403);exit('Sin acceso.');}
$m=new PeticionesTraspasosGyD();$id=(int)($_GET['id']??0);$tipo=strtoupper((string)($_GET['tipo']??'TODOS'));
if(!in_array($tipo,['TODOS','ALMACEN','FARMACIA','UNICO'],true)){http_response_code(422);exit('Documento inválido.');}
try{$cab=$m->ver($u,$id);}catch(Throwable $e){http_response_code(403);exit('Documento no disponible.');}
$items=array_values(array_filter($cab['items'],static fn($r)=>$tipo==='TODOS'||$r['origen_tipo']===$tipo));
if(!$items){http_response_code(404);exit('No hay partidas en esta ubicación.');}
function ph($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$titulo=$tipo==='TODOS'?'CONSOLIDADO DE PETICIÓN DE TRASPASO':'PETICIÓN DE TRASPASO · '.$tipo;
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title><?=ph($cab['folio'].' · '.$titulo)?></title><style>
@page{size:A4;margin:16mm}*{box-sizing:border-box}body{font:11px Arial,sans-serif;max-width:900px;margin:18px auto;color:#15191d}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #343434;padding-bottom:12px}.top img{width:76px;height:auto}.brand{display:flex;align-items:center;gap:12px}.brand b{font-size:13px}.title{text-align:right}.title h2{font-size:13px;margin:3px 0}.meta{margin:17px 0 12px;display:grid;grid-template-columns:1fr 1fr;gap:9px}.meta div{border-bottom:1px solid #ddd;padding:3px}.meta strong{display:inline-block;min-width:90px}.info{padding:7px 0;border-top:1px solid #aaa}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border:1px solid #c2c2c2;padding:6px;text-align:left}th{background:#e7e8e9}.warn{border:1px solid #666;padding:8px;margin-top:14px;font-size:10px}.firma{margin-top:70px;display:grid;grid-template-columns:repeat(3,1fr);gap:20px;text-align:center}.firma div{border-top:1px solid #222;padding:8px 0}.noprint{margin-bottom:14px}@media print{.noprint{display:none}}
</style></head><body><button class="noprint" onclick="window.print()">🖨️ Imprimir documento</button><div class="top"><div class="brand"><img src="assets/img/logo2.png" alt="Logo G&D"><div><b>DISTRIBUCIÓN G&D</b><div>Sistema interno de traspasos de mercancía</div><div>RFC: DGD151211PP5</div></div></div><div class="title"><h2><?=ph($titulo)?></h2><div><b>Folio: <?=ph($cab['folio'])?></b></div><div>Fecha: <?=ph($cab['creada_en'])?></div><div>Estado: <?=ph(str_replace('_',' ',$cab['estado']))?></div></div></div>
<div class="meta"><div><strong>Solicita:</strong><?=ph($cab['destino'])?> · <?=ph($cab['destino_tipo'])?></div><div><strong>Surte:</strong><?=ph($cab['origen'])?></div><div><strong>Gerente:</strong><?=ph($cab['solicitante'])?></div><div><strong>Validado por:</strong><?=ph($cab['validador']??'Pendiente Compras')?></div><div><strong>Documento:</strong>PETICIÓN / TRASPASO INTERNO</div><div><strong>Sección:</strong><?=ph($tipo)?></div></div>
<div class="info"><b>Observaciones:</b> <?=ph($cab['observaciones']??'')?> <?php if(!empty($cab['respuesta_compras'])):?> · <b>Compras:</b> <?=ph($cab['respuesta_compras'])?><?php endif;?></div>
<table><thead><tr><th>Cantidad solicitada</th><th>Clave</th><th>Descripción</th><th>Origen físico</th><th>Autorizadas</th><th>Ubicación de rack</th></tr></thead><tbody><?php $total=0;foreach($items as $r):$total+=(int)$r['solicitado'];?><tr><td><?=(int)$r['solicitado']?></td><td><?=ph($r['codigo'])?></td><td><?=ph($r['descripcion'])?></td><td><b><?=ph($r['origen_tipo'])?></b></td><td><?=($r['aprobado']===null?'Pendiente':(int)$r['aprobado'])?></td><td>________________</td></tr><?php endforeach;?></tbody><tfoot><tr><th>Total: <?=$total?> piezas</th><td colspan="5"><?=count($items)?> partidas</td></tr></tfoot></table>
<?php if($cab['documentos']):?><p><b>Órdenes operativas relacionadas:</b> <?php foreach($cab['documentos'] as $d):?><?=ph($d['origen_tipo'].': '.$d['folio'].' ('.$d['estado'].')')?> · <?php endforeach;?></p><?php endif;?>
<div class="warn"><b>DOCUMENTO INTERNO / NO FISCAL.</b> Esta petición no comprueba la salida física, no acredita existencias ni sustituye el CFDI con Complemento Carta Porte cuando aplique. Las cantidades reales se determinan en la orden de traspaso, el despacho y la recepción.</div>
<div class="firma"><div>Gerente que solicita</div><div>Compras valida</div><div>Almacén / Farmacia surte</div></div>
<?php if (!defined('GYD_HIDE_GLOBAL_NAV')): ?>
<link rel="stylesheet" href="assets/css/gyd_nav_global.css?v=4">
<script>window.GYD_NAV_HOME='index.php';window.GYD_NAV_POS='pos.php';</script>
<script src="assets/js/gyd_nav_global.js?v=4"></script>
<?php endif; ?>
</body></html>
