<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$file = $root . '/public/pos_retiro_ticket.php';
$backupDir = $root . '/tools/backups_ticket_retiro_estilo';
$cmd = $argv[1] ?? '--check';
$marker = 'gyd-ticket-toolbar';
if ($cmd === '--check') {
    if (!is_file($file)) { fwrite(STDERR, "ERROR: no se encontró public/pos_retiro_ticket.php\n"); exit(1); }
    echo "COMPATIBLE\n";
    exit(0);
}
if (!is_file($file)) { fwrite(STDERR, "ERROR: no se encontró public/pos_retiro_ticket.php\n"); exit(1); }
$html = file_get_contents($file);
if ($html === false) { fwrite(STDERR, "ERROR: no fue posible leer el archivo\n"); exit(1); }
if ($cmd === '--apply') {
    @mkdir($backupDir, 0777, true);
    $backup = $backupDir . '/pos_retiro_ticket.php.bak';
    if (!is_file($backup)) copy($file, $backup);

    // Remove previous floating/global nav artifacts if any exist in this file
    $html = preg_replace('~<link rel="stylesheet" href="assets/css/gyd_nav_global\.css\?v=1">\s*<script>window\.GYD_NAV_HOME=.*?</script>\s*<script src="assets/js/gyd_nav_global\.js\?v=1"></script>~s', '', $html);

    $style = <<<'STYLE'
<style>
body{font:12px 'Courier New',monospace;margin:0;color:#111;background:#e9eef3}
.tools.gyd-ticket-toolbar{position:sticky;top:0;z-index:20;background:linear-gradient(180deg,#ffffff 0%,#f5f8fc 100%);padding:16px 18px 12px;text-align:center;border-bottom:1px solid rgba(18,63,114,.10);box-shadow:0 10px 25px rgba(9,30,66,.08)}
.tools.gyd-ticket-toolbar .gyd-tools-row{display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap}
.tools.gyd-ticket-toolbar button,.tools.gyd-ticket-toolbar a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:11px 18px;border-radius:14px;font:700 14px/1.1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;text-decoration:none;transition:all .18s ease}
.tools.gyd-ticket-toolbar button{border:1px solid #0f4fa8;color:#fff;background:linear-gradient(135deg,#0f4fa8,#1e6bd6);box-shadow:0 10px 20px rgba(30,107,214,.22);cursor:pointer}
.tools.gyd-ticket-toolbar button:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(30,107,214,.30)}
.tools.gyd-ticket-toolbar a{border:1px solid rgba(18,63,114,.18);color:#123f72;background:#fff;box-shadow:0 8px 18px rgba(17,24,39,.07)}
.tools.gyd-ticket-toolbar a:hover{transform:translateY(-1px);background:#f8fbff;box-shadow:0 10px 20px rgba(17,24,39,.10)}
.tools.gyd-ticket-toolbar a.gyd-back{background:linear-gradient(135deg,#6d28d9,#7c3aed);color:#fff;border-color:#6d28d9;box-shadow:0 10px 20px rgba(109,40,217,.22)}
.tools.gyd-ticket-toolbar a.gyd-cut{background:linear-gradient(135deg,#0f766e,#149a8b);color:#fff;border-color:#0f766e;box-shadow:0 10px 20px rgba(15,118,110,.22)}
.tools.gyd-ticket-toolbar a.gyd-pos{background:linear-gradient(135deg,#123f72,#1f5ea8);color:#fff;border-color:#123f72;box-shadow:0 10px 20px rgba(18,63,114,.24)}
.tools.gyd-ticket-toolbar p{margin:10px auto 0;max-width:880px;color:#465467;font:600 12px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.paper{width:80mm;background:white;padding:4mm;margin:16px auto;border-radius:18px;box-shadow:0 18px 40px rgba(0,0,0,.08)}
.ticket{text-align:center;padding:3mm 0 6mm;border-bottom:2px dashed black}.ticket:last-child{border-bottom:0}
.left{text-align:left}.right{text-align:right}.big{font-size:17px;font-weight:bold}.sep{border-top:1px dashed black;margin:7px 0}.label{font-weight:700}p{margin:5px 0}.warn{font-size:10px}h2{font-size:14px;margin:6px 0}
@media (max-width: 720px){.tools.gyd-ticket-toolbar{padding-left:12px;padding-right:12px}.tools.gyd-ticket-toolbar button,.tools.gyd-ticket-toolbar a{width:100%}.paper{margin:12px auto}}
@media print{@page{size:80mm auto;margin:1mm}body{background:white}.paper{width:auto;margin:0;padding:1mm;box-shadow:none;border-radius:0}.tools{display:none}.ticket{page-break-after:always;break-after:page}.ticket:last-child{page-break-after:auto;break-after:auto}}
</style>
STYLE;

    $newTools = <<<'TOOLS'
<div class="tools gyd-ticket-toolbar">
  <div class="gyd-tools-row">
    <button onclick="window.print()">🖨️ Imprimir dos copias</button>
    <a class="gyd-back" href="pos_retiros.php?caja_id=<?=(int)$r['caja_id']?>">← Volver a retiros</a>
    <a class="gyd-cut" href="pos_corte_retiros.php?turno_id=<?=(int)$r['turno_id']?>">📋 Ver corte del turno</a>
    <a class="gyd-pos" href="pos.php?caja_id=<?=(int)$r['caja_id']?>">🧾 Regresar al POS</a>
  </div>
  <p>El ticket debe coincidir por folio, fecha, hora e importe con el corte de caja.</p>
</div>
TOOLS;

    $html = preg_replace('~<style>.*?</style>~s', $style, $html, 1, $countStyle);
    $html = preg_replace('~<div class="tools">.*?</div>\s*<div class="paper">~s', $newTools . "\n<div class=\"paper\">", $html, 1, $countTools);
    if ($countStyle < 1 || $countTools < 1) {
        fwrite(STDERR, "ERROR: no se pudo actualizar el diseño del ticket.\n");
        exit(1);
    }
    file_put_contents($file, $html);
    echo "APLICADO\n";
    exit(0);
}
if ($cmd === '--verify') {
    $html = file_get_contents($file) ?: '';
    if (strpos($html, 'gyd-ticket-toolbar') === false || strpos($html, 'Regresar al POS') === false) {
        fwrite(STDERR, "ERROR: la verificación no pasó.\n"); exit(1);
    }
    echo "VERIFICADO\n";
    exit(0);
}
fwrite(STDERR, "Uso: php instalar_estilo_ticket_retiro_pos.php [--check|--apply|--verify]\n");
exit(1);
