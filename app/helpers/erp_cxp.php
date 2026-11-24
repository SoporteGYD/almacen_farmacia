<?php
declare(strict_types=1);

function cxp_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cxp_rol(array $user): string { return strtoupper(trim((string)($user['rol_original'] ?? $user['rol'] ?? ''))); }
function cxp_admin(array $user): bool { return in_array(cxp_rol($user), ['ADMINISTRADOR','ADMIN','DIRECCION'], true); }
function cxp_compras(array $user): bool { return cxp_admin($user) || cxp_rol($user) === 'COMPRAS'; }
function cxp_csrf(): string {
    if (empty($_SESSION['cxp_csrf'])) $_SESSION['cxp_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['cxp_csrf'];
}
function cxp_check_csrf(): void {
    if (!hash_equals(cxp_csrf(), (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Formulario vencido. Vuelve a cargar la página.');
}
function cxp_form_token(): string {
    if (empty($_SESSION['cxp_form_token'])) $_SESSION['cxp_form_token'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['cxp_form_token'];
}
function cxp_check_form_token(): string {
    $t = (string)($_POST['form_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D', $t) || !hash_equals(cxp_form_token(),$t)) throw new RuntimeException('La acción fue procesada o el formulario venció; recarga esta página.');
    return $t;
}
function cxp_date(string $v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}
function cxp_money_cents(mixed $value): int {
    $text = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,2})?$/D', $text)) throw new RuntimeException('Importe inválido. Usa pesos y hasta dos decimales.');
    [$pesos, $centavos] = array_pad(explode('.', $text, 2), 2, '');
    return (int)$pesos * 100 + (int)str_pad($centavos, 2, '0');
}
function cxp_db_cents(mixed $value): int {
    // MySQL devuelve DECIMAL con dos decimales; evitamos redondeos flotantes.
    $v = (string)($value ?? '0.00');
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $v)) throw new RuntimeException('Importe almacenado incorrectamente.');
    $neg = str_starts_with($v, '-');
    [$pesos,$centavos] = array_pad(explode('.', ltrim($v,'-'), 2), 2, '');
    $n = (int)$pesos * 100 + (int)str_pad($centavos,2,'0');
    return $neg ? -$n : $n;
}
function cxp_sql_money(int $cents): string { return sprintf('%d.%02d', intdiv($cents,100), abs($cents%100)); }
function cxp_fmt(mixed $amount): string { return '$'.number_format(cxp_db_cents($amount) / 100, 2, '.', ','); }
function cxp_auditar(PDO $db,int $doc,?int $sol,string $evt,int $user,string $detalle):void {
    $s=$db->prepare('INSERT INTO erp_cxp_auditoria(documento_id,solicitud_id,evento,usuario_id,detalle) VALUES(?,?,?,?,?)');
    $s->execute([$doc,$sol,$evt,$user,mb_substr($detalle,0,600)]);
}
function cxp_saldo(PDO $db,int $documentoId,bool $bloquear = false):array {
    $st=$db->prepare('SELECT id,total_documento FROM erp_cxp_documentos WHERE id=?'.($bloquear?' FOR UPDATE':''));
    $st->execute([$documentoId]);$d=$st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new RuntimeException('La cuenta por pagar no existe.');
    $st=$db->prepare('SELECT COALESCE(SUM(importe),0) FROM erp_cxp_aplicaciones WHERE documento_id=?');
    $st->execute([$documentoId]);$aplicado=cxp_db_cents($st->fetchColumn());
    $total=cxp_db_cents($d['total_documento']);
    $st=$db->prepare("SELECT COALESCE(SUM(importe),0) FROM erp_cxp_solicitudes WHERE documento_id=? AND estado IN ('PENDIENTE','AUTORIZADA')");
    $st->execute([$documentoId]);$reservado=cxp_db_cents($st->fetchColumn());
    return ['total'=>$total, 'aplicado'=>$aplicado, 'saldo'=>max(0,$total-$aplicado), 'reservado'=>$reservado];
}
function cxp_storage_dir(): string {
    $configured = getenv('GYD_CXP_EVIDENCE_DIR');
    // Fuera de htdocs en una instalación XAMPP normal. Configurable en servidor.
    return ($configured!==false && trim($configured)!=='') ? rtrim($configured, '/\\') : dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'gyd_cxp_evidencias';
}
function cxp_guardar_evidencia(array $file): array {
    if ((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return [null,null];
    if ((int)($file['error']??-1)!==UPLOAD_ERR_OK || (int)($file['size']??0)<1 || (int)$file['size']>5*1024*1024 || !is_uploaded_file((string)($file['tmp_name']??''))) {
        throw new RuntimeException('El comprobante debe ser un archivo válido de hasta 5 MB.');
    }
    $types=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','application/xml'=>'xml','text/xml'=>'xml'];
    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=$finfo->file((string)$file['tmp_name']);
    if (!is_string($mime) || !isset($types[$mime])) throw new RuntimeException('Solo se admiten PDF, JPG, PNG o XML.');
    $dir=cxp_storage_dir();
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta privada para comprobantes. Revisa los permisos del servidor.');
    $name=bin2hex(random_bytes(24)).'.'.$types[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir.DIRECTORY_SEPARATOR.$name)) throw new RuntimeException('No se pudo guardar el comprobante de forma segura.');
    @chmod($dir.DIRECTORY_SEPARATOR.$name,0600);
    return [$name,$mime];
}
function cxp_page_head(string $title): void { ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=cxp_e($title)?> | ERP G&amp;D</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f8;color:#14385a;font:14px system-ui,Segoe UI,Arial,sans-serif}a{color:#155999;text-decoration:none}a:hover{text-decoration:underline}.head{background:linear-gradient(120deg,#15385f,#2263a8);color:white;padding:20px max(16px,calc((100% - 1260px)/2))}.head h1{font-size:24px;margin:0 0 5px}.head p{margin:0;opacity:.88}.nav{max-width:1260px;margin:14px auto;display:flex;flex-wrap:wrap;gap:8px;padding:0 12px}.nav a{background:#fff;border:1px solid #d6e1ee;border-radius:9px;padding:9px 12px;font-weight:650}.wrap{max-width:1260px;margin:12px auto 50px;padding:0 12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.card{background:#fff;border:1px solid #dbe6f2;border-radius:14px;padding:18px;margin-bottom:15px;box-shadow:0 4px 13px #123e5c08}h2{font-size:18px;margin:0 0 13px}h3{font-size:16px;margin:0 0 10px}.metric{border-radius:12px;background:#f1f6fd;padding:15px}.metric span{color:#55718e;display:block;font-size:12px}.metric strong{display:block;margin-top:5px;font-size:24px;color:#123e6b}.muted{color:#647b92}.alert{border-radius:10px;padding:12px 15px;margin:10px 0;background:#fff3dd;border:1px solid #edcc90}.alert.good{background:#e6f7ed;border-color:#9ed6b5}.alert.bad{background:#fff0f1;border-color:#edb2bb}form{margin:0}label{display:block;margin:8px 0 5px;font-weight:700;font-size:12px}input,select,textarea{font:inherit;width:100%;border:1px solid #bbcde0;border-radius:8px;padding:10px;background:#fff;color:#14385a}textarea{min-height:65px}button,.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;color:white;background:#185aa3;padding:10px 13px;font-weight:700;cursor:pointer;text-decoration:none}button:hover,.btn:hover{background:#11487f;color:white;text-decoration:none}.btn.alt{background:#e8f0fa;color:#164b80}.btn.alt:hover{background:#d9e8f7}.btn.warn{background:#9e233c}.actions{display:flex;flex-wrap:wrap;align-items:end;gap:9px}.actions>*{flex:1 1 190px}.small{font-size:12px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:780px}th{background:#174675;color:#fff;text-align:left}th,td{padding:11px 10px;border-bottom:1px solid #e1e9f1;font-size:13px;vertical-align:top}.badge{display:inline-block;border-radius:9px;background:#e9f2ff;padding:4px 9px;font-size:11px;font-weight:800}.money{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.line{border-top:1px solid #e5ebf2;margin:15px 0}.end{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.end>*{margin:0}.nowrap{white-space:nowrap}@media(max-width:850px){.grid,.two{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:570px){.grid,.two{grid-template-columns:1fr}.card{padding:13px}.head h1{font-size:20px}}
</style></head><body><?php
    // Barra ligera de sesión si existe en la instalación actual.
    $barra=__DIR__.'/../views/layouts/erp_barra_sesion.php';
    if(is_file($barra)) require $barra;
?><div class="head"><h1><?=cxp_e($title)?></h1><p>Facturas · Vencimientos · Abonos · Autorizaciones · Notas de crédito</p></div>
<div class="nav"><a href="erp_cuentas_por_pagar.php">Cuentas por pagar</a><a href="erp_facturas.php">Entradas por factura</a><a href="erp_proveedores.php">Proveedores</a><a href="erp_ordenes_compra.php">Órdenes de compra</a><a href="dashboard.php">Inicio</a><a href="logout.php">Cerrar sesión</a></div><main class="wrap">
<?php }
function cxp_page_foot(): void { echo '</main><script src="assets/js/gyd_excel_reportes.js" defer></script></body></html>'; }
