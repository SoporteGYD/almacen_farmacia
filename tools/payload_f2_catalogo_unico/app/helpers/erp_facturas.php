<?php
declare(strict_types=1);
require_once __DIR__.'/erp_oc_seguimiento.php'; // GYD_OC_NETO_PARCIAL_V2
/** Reglas de facturas/recepciones ERP. La aplicación al inventario se confirma aparte. */
function ef_e(mixed $x): string { return htmlspecialchars((string)$x, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function ef_rol(array $u): string { return strtoupper(trim((string)($u['rol_original'] ?? $u['rol'] ?? ''))); }
function ef_sucursales(): array { return ['TAPACHULA'=>'Tapachula','TOSCANA'=>'Plaza Toscana','HIDALGO'=>'Ciudad Hidalgo','TUXTLA'=>'Tuxtla Gutiérrez']; }
function ef_es_admin(array $u): bool { return in_array(ef_rol($u),['ADMINISTRADOR','ADMIN','DIRECCION'],true); }
function ef_puede_capturar(array $u): bool { return ef_es_admin($u) || ef_rol($u)==='COMPRAS'; }
function ef_puede_verificar(array $u,string $sucursal): bool {
    if (ef_es_admin($u)) return true;
    if (!in_array(ef_rol($u),['ENCARGADO','JEFE_ALMACEN'],true)) return false;
    $alm=(int)($u['almacen_id']??0);
    return ($sucursal==='HIDALGO' && $alm===1) || ($sucursal==='TUXTLA' && in_array($alm,[2,3],true));
}
function ef_puede_ver(array $u,string $sucursal): bool {
    return ef_puede_capturar($u) || ef_puede_verificar($u,$sucursal) ||
       (ef_rol($u)==='GERENTE' && (($sucursal==='HIDALGO' && (int)($u['almacen_id']??0)===1) || ($sucursal==='TUXTLA' && in_array((int)($u['almacen_id']??0),[2,3],true))));
}
function ef_token(string $key): string {
    if (empty($_SESSION[$key])) $_SESSION[$key]=bin2hex(random_bytes(16));
    return (string)$_SESSION[$key];
}
function ef_csrf(): string {return ef_token('erp_factura_csrf');}
function ef_verify_csrf(string $got): void {
    if (!hash_equals(ef_csrf(),$got)) throw new RuntimeException('Sesión o formulario vencido. Actualiza la página.');
}
function ef_fecha(string $s): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
    return $d!==false&&$d->format('Y-m-d')===$s;
}
function ef_natural($v,int $max=10000000): int {
    if(!is_scalar($v)||!preg_match('/^\d{1,9}$/D',trim((string)$v))) throw new RuntimeException('Revisa las cantidades: utiliza números enteros, no negativos.');
    $n=(int)$v;
    if($n>$max) throw new RuntimeException('Cantidad fuera del límite permitido.');
    return $n;
}
function ef_costo($v): string {
    $v=trim((string)$v);
    if(!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',$v)) throw new RuntimeException('El costo debe ser un importe positivo con hasta cuatro decimales.');
    return number_format((float)$v,4,'.','');
}
function ef_sucursal_oc(string $dest): string {
    if(str_starts_with($dest,'HIDALGO'))return 'HIDALGO';
    if(str_starts_with($dest,'TUXTLA'))return 'TUXTLA';
    return $dest;
}
/** Las reposiciones recibidas dejan de consumir cantidad como incidencia: pasan a ACEPTADA. */
function ef_resumen_detalles(PDO $db,int $id): array {
    $sql="SELECT d.*, COALESCE(SUM(CASE WHEN rd.tipo='ACEPTADA' THEN rd.cantidad ELSE 0 END),0) aceptadas,
      COALESCE(SUM(CASE WHEN rd.tipo<>'ACEPTADA' AND (rd.resolucion IS NULL OR rd.resolucion<>'REPOSICION_RECIBIDA') THEN rd.cantidad ELSE 0 END),0) incidencias_pendientes,
      COALESCE(SUM(CASE WHEN rd.tipo<>'ACEPTADA' AND rd.resolucion IS NULL THEN rd.cantidad ELSE 0 END),0) incidencias_abiertas
     FROM erp_facturas_detalle d
     LEFT JOIN erp_facturas_revision_detalle rd ON rd.factura_detalle_id=d.id
     WHERE d.factura_id=? GROUP BY d.id ORDER BY d.id";
    $st=$db->prepare($sql);$st->execute([$id]);return erp_oc_resumen_ajustado($db,$id,$st->fetchAll(PDO::FETCH_ASSOC));
}
function ef_actualizar_estado(PDO $db,int $facturaId): void {
    $ds=ef_resumen_detalles($db,$facturaId);
    $total=0;$cubierto=0;$incidencias=0;
    foreach($ds as $d){$total+=(int)$d['cantidad_facturada'];$cubierto+=(int)$d['aceptadas']+(int)$d['incidencias_pendientes'];$incidencias+=(int)$d['incidencias_abiertas'];}
    $st=$db->prepare('SELECT COUNT(*) FROM erp_facturas_incidencias_adicionales WHERE factura_id=? AND estado=\'ABIERTA\'');$st->execute([$facturaId]);
    $incidencias+=(int)$st->fetchColumn();
    $estado=$cubierto===0?($incidencias>0?'CON_INCIDENCIAS':'REGISTRADA'):($cubierto<$total?'EN_REVISION':($incidencias>0?'CON_INCIDENCIAS':'VERIFICADA'));
    $st=$db->prepare('UPDATE erp_facturas SET estado=? WHERE id=?');$st->execute([$estado,$facturaId]);
}
function ef_header(string $title,string $subtitle=''): void { ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=ef_e($title)?> · ERP G&amp;D</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#153954;font:15px system-ui,Segoe UI,sans-serif}a{color:#165b9b}header.ef-head{background:#123e6b;color:#fff;padding:22px max(18px,calc((100% - 1150px)/2));border-bottom:4px solid #dc364e}header h1{margin:0 0 6px;font-size:23px}header p{margin:0;color:#e2effb}main{max-width:1160px;padding:18px 14px;margin:auto}.card{background:#fff;border:1px solid #dce7f1;border-radius:13px;padding:19px;margin-bottom:16px;box-shadow:0 3px 14px #143b5d0a}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.twocol{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.tools{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:13px}.btn,button{background:#155a9d;border:0;color:#fff;border-radius:8px;padding:10px 13px;cursor:pointer;text-decoration:none;display:inline-block;font-weight:650;font:inherit}.btn.alt{background:#e6f0fa;color:#16426d}.btn.danger,button.danger{background:#b43348}.btn:disabled,button:disabled{opacity:.5;cursor:not-allowed}label{display:block;font-size:13px;font-weight:650;margin-bottom:4px}input,select,textarea{font:inherit;width:100%;padding:10px;border:1px solid #bfd0de;border-radius:8px;background:#fff;min-width:0}textarea{min-height:75px}fieldset{border:1px solid #d2dfec;border-radius:9px;margin:12px 0;padding:13px}legend{font-weight:700}.field{margin:8px 0}.tablewrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:750px}th,td{padding:9px 10px;border-bottom:1px solid #e2eaf1;text-align:left;font-size:13px;vertical-align:top}th{background:#184b7b;color:white}small,.muted{color:#657c90}.alert{background:#ffefd2;border:1px solid #edcb85;padding:12px;border-radius:8px;margin-bottom:12px}.error{background:#ffe6e8;border-color:#efaeb7}.success{background:#def5e8;border-color:#9ad7b6}.metric{background:#eef6ff;padding:12px;border-radius:9px}.metric strong{font-size:24px;display:block}.num{text-align:right;font-variant-numeric:tabular-nums}.badge{display:inline-block;border-radius:7px;padding:4px 9px;background:#e8eff9;color:#244d78;font-size:12px;font-weight:700}input[type=number]{min-width:70px}@media(max-width:710px){.grid,.twocol{grid-template-columns:1fr}.card{padding:13px}header h1{font-size:20px}}
</style></head><body><?php require __DIR__.'/../views/layouts/erp_barra_sesion.php'; ?><header class="ef-head"><h1><?=ef_e($title)?></h1><p><?=ef_e($subtitle)?></p></header><main>
<div class="tools"><a class="btn alt" href="erp_facturas.php">Entradas por factura</a><a class="btn alt" href="erp_facturas_verificacion.php">Verificación y diferencias</a><a class="btn alt" href="erp_ordenes_compra.php">Órdenes de Compra</a><a class="btn alt" href="erp_recepciones_pendientes.php">Recepción por OC (anterior)</a></div>
<div class="alert"><b>Control de pruebas:</b> la captura de factura NO suma stock. Sólo al confirmar <b>Aplicar aceptadas al inventario</b> se agregan las piezas verificadas al POS (Tapachula/Toscana) o Almacén (Hidalgo/Tuxtla). <b>La captura no cambia precios ni costos.</b> Los precios públicos y las piezas por caja se administran desde Catálogo Maestro, no al aplicar facturas. No registres la misma llegada también en «Recepción por OC» o Entradas clásicas.</div>
<?php }
function ef_footer():void {echo '</main></body></html>';}
