<?php
declare(strict_types=1);
/** ERP G&D | Permisos de lectura financiera para Compras | Incremental y reversible. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check','--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_finanzas_para_compras.php --check | --apply\n"); exit(2);
}
$raiz = dirname(__DIR__);
$errores = [];
$plan = [];
$mensajes = [];
$archivos = [
    'app/helpers/erp_compras_modulos.php',
    'app/helpers/erp_catalogo_reportes.php',
    'app/helpers/erp_catalogo_finanzas.php',
    'public/erp_catalogo_reportes.php',
    'public/erp_catalogo_auditoria.php',
    'public/erp_catalogo_finanzas.php',
    'public/erp_catalogo_historial_costos.php',
    'public/erp_catalogo_comparar_proveedores.php',
    'public/erp_alertas_compras.php',
    'public/erp_catalogo_maestro.php',
    'app/views/layouts/header.php',
];
foreach ($archivos as $ruta) if (!is_file($raiz.'/'.$ruta)) $errores[] = "Falta $ruta. Instala antes Reportes, Auditoría, Finanzas, Historial, Comparador, Alertas y Área Compras.";
if ($errores) { foreach ($errores as $e) fwrite(STDERR, "INCOMPATIBLE: $e\n"); exit(3); }
$src = [];
foreach ($archivos as $ruta) $src[$ruta] = (string)file_get_contents($raiz.'/'.$ruta);
$next = $src;
function cambiarUna(string &$contenido, string $antiguo, string $nuevo, string $ruta, string $desc, array &$errores, array &$mensajes): void {
    if ($antiguo === $nuevo) return;
    $nueva = substr_count($contenido, $nuevo);
    if ($nueva === 1) { $mensajes[] = "YA INSTALADO: $desc ($ruta)"; return; }
    $vieja = substr_count($contenido, $antiguo);
    if ($vieja !== 1 || $nueva !== 0) { $errores[] = "No se encontró punto seguro en $ruta ($desc, antiguo=$vieja, nuevo=$nueva)."; return; }
    $contenido = str_replace($antiguo, $nuevo, $contenido);
    $mensajes[] = "ACTUALIZAR: $desc ($ruta)";
}
// 1) Lista cerrada de rutas COMPRAS. No se toca auth.php, que ya consulta este helper.
$p = 'app/helpers/erp_compras_modulos.php';
if (!str_contains($next[$p], 'function gyd_compras_ruta_permitida(')) $errores[] = 'El control de rutas del área Compras es incompatible.';
$seccion = <<<'PHPSECTION'
        // GYD_COMPRAS_FINANZAS_MENU_V1: herramientas financieras de consulta.
        'Reportes, costos y alertas' => [
            ['titulo'=>'Reportes del Catálogo Maestro', 'ruta'=>'erp_catalogo_reportes.php', 'detalle'=>'Filtrar columnas y exportar productos con costos'],
            ['titulo'=>'Auditoría del Catálogo', 'ruta'=>'erp_catalogo_auditoria.php', 'detalle'=>'Revisar datos incompletos, IVA e incidencias de costos'],
            ['titulo'=>'Costos y rentabilidad', 'ruta'=>'erp_catalogo_finanzas.php', 'detalle'=>'Último costo, promedio, precio y márgenes por sucursal'],
            ['titulo'=>'Historial de costos y facturas', 'ruta'=>'erp_catalogo_historial_costos.php', 'detalle'=>'Evolución de costos por código, fecha y proveedor'],
            ['titulo'=>'Comparar proveedores', 'ruta'=>'erp_catalogo_comparar_proveedores.php', 'detalle'=>'Costos documentados, variaciones y márgenes'],
            ['titulo'=>'Alertas inteligentes', 'ruta'=>'erp_alertas_compras.php', 'detalle'=>'Aumentos, margen bajo, pérdidas y oportunidades de ahorro'],
        ],
PHPSECTION;
cambiarUna($next[$p], "        'Pedidos y productos' => [", $seccion."\n        'Pedidos y productos' => [", $p, 'Sección financiera en inicio de Compras', $errores, $mensajes);
$rutas = <<<'PHPROUTES'
        // GYD_COMPRAS_FINANZAS_RUTAS_V1: únicamente páginas de consulta (GET).
        'erp_catalogo_reportes.php'=>true,
        'erp_catalogo_auditoria.php'=>true,
        'erp_catalogo_finanzas.php'=>true,
        'erp_catalogo_historial_costos.php'=>true,
        'erp_catalogo_comparar_proveedores.php'=>true,
        'erp_alertas_compras.php'=>true,
PHPROUTES;
cambiarUna($next[$p], "        'erp_compras_inicio.php'=>true,", "        'erp_compras_inicio.php'=>true,\n".$rutas, $p, 'Lista cerrada de rutas permitidas a Compras', $errores, $mensajes);
// 2) Permisos financieros del servidor, no solamente enlaces de menú.
$p = 'app/helpers/erp_catalogo_reportes.php';
cambiarUna($next[$p], "return in_array(strtoupper(trim(\$rol)), ['ADMINISTRADOR','ADMIN'], true);", "return in_array(strtoupper(trim(\$rol)), ['ADMINISTRADOR','ADMIN','COMPRAS'], true);", $p, 'Costos en exportaciones y auditoría', $errores, $mensajes);
$p = 'app/helpers/erp_catalogo_finanzas.php';
cambiarUna($next[$p], "function gd_fc_admin(string \$rol):bool {return in_array(strtoupper(trim(\$rol)),['ADMINISTRADOR','ADMIN'],true);}", "function gd_fc_admin(string \$rol):bool {return in_array(strtoupper(trim(\$rol)),['ADMINISTRADOR','ADMIN','COMPRAS'],true);}", $p, 'Lectura de costos y rentabilidad', $errores, $mensajes);
$p = 'public/erp_catalogo_historial_costos.php';
cambiarUna($next[$p], "['ADMIN','ADMINISTRADOR'],true)", "['ADMIN','ADMINISTRADOR','COMPRAS'],true)", $p, 'Historial por proveedor y factura', $errores, $mensajes);
$p = 'public/erp_catalogo_comparar_proveedores.php';
cambiarUna($next[$p], "if(!in_array(\$role,['ADMIN','ADMINISTRADOR'],true))", "if(!in_array(\$role,['ADMIN','ADMINISTRADOR','COMPRAS'],true))", $p, 'Comparador de proveedores', $errores, $mensajes);
$p = 'public/erp_alertas_compras.php';
cambiarUna($next[$p], "if(!in_array(\$role,['ADMIN','ADMINISTRADOR'],true))", "if(!in_array(\$role,['ADMIN','ADMINISTRADOR','COMPRAS'],true))", $p, 'Alertas inteligentes', $errores, $mensajes);
// Seguridad conservada: no se cambia $puedeEditar del Catálogo Maestro ni rutas de escritura.
$p = 'public/erp_catalogo_finanzas.php';
cambiarUna($next[$p], 'El análisis de costos y rentabilidad está reservado a Administración.', 'El análisis de costos y rentabilidad está disponible para Administración y Compras.', $p, 'Texto explicativo', $errores, $mensajes);
$p = 'public/erp_catalogo_reportes.php';
cambiarUna($next[$p], 'Los importes solo aparecen para administradores autorizados.', 'Los importes están disponibles para Administración y Compras autorizados.', $p, 'Texto de costos en reportes', $errores, $mensajes);
$p = 'public/erp_catalogo_auditoria.php';
cambiarUna($next[$p], 'Los importes y alertas de costos se muestran únicamente a administradores autorizados.', 'Los importes y alertas de costos se muestran a Administración y Compras autorizados.', $p, 'Texto de auditoría financiera', $errores, $mensajes);
// 3) Enlace visible desde Catálogo Maestro solo al rol COMPRAS; enlaces existentes de admin se conservan.
$p = 'public/erp_catalogo_maestro.php';
$link = <<<'PHPLINK'
<?php /* GYD_COMPRAS_FINANZAS_CATALOGO_V1 */ if($rol==='COMPRAS'):?><nav aria-label="Herramientas de Compras" style="display:flex;gap:7px;flex-wrap:wrap"><a href="erp_catalogo_reportes.php" style="background:#fff;color:#17456d">Reportes</a><a href="erp_catalogo_finanzas.php" style="background:#fff;color:#17456d">Costos y márgenes</a><a href="erp_alertas_compras.php" style="background:#fff;color:#17456d">Alertas</a></nav><?php endif;?>
PHPLINK;
if (str_contains($next[$p], 'GYD_COMPRAS_FINANZAS_CATALOGO_V1')) $mensajes[] = "YA INSTALADO: accesos desde Catálogo Maestro";
elseif (substr_count($next[$p], '</header>') !== 1) $errores[] = "Encabezado de $p incompatible.";
else { $next[$p] = str_replace('</header>', $link.'</header>', $next[$p]); $mensajes[] = "ACTUALIZAR: accesos desde Catálogo Maestro"; }
// 4) Menú global. Se usa como complemento del panel; nunca amplía los permisos del servidor.
$p = 'app/views/layouts/header.php';
if (str_contains($next[$p], 'GYD_COMPRAS_FINANZAS_HEADER_V1')) $mensajes[] = 'YA INSTALADO: menú global de Compras';
else {
    $anchor = "\$menuAgregar('🧾 Compras y proveedores', 'Tesorería y Calendario de Pagos', 'erp_tesoreria.php', \$menuEsAdmin || \$menuEsCompras);";
    if (substr_count($next[$p], $anchor) === 1) {
        $extra = <<<'PHPMENU'
// GYD_COMPRAS_FINANZAS_HEADER_V1: solo lectura, mantiene rutas restringidas a Compras.
$menuAgregar('🧾 Compras y proveedores', 'Reportes del Catálogo', 'erp_catalogo_reportes.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Auditoría del Catálogo', 'erp_catalogo_auditoria.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Costos y Rentabilidad', 'erp_catalogo_finanzas.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Historial de Costos', 'erp_catalogo_historial_costos.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Comparador de Proveedores', 'erp_catalogo_comparar_proveedores.php', $menuEsCompras || $menuEsAdmin);
$menuAgregar('🧾 Compras y proveedores', 'Alertas Inteligentes', 'erp_alertas_compras.php', $menuEsCompras || $menuEsAdmin);
PHPMENU;
        $next[$p] = str_replace($anchor, $anchor."\n".$extra, $next[$p]);
        $mensajes[]='ACTUALIZAR: menú global de Compras';
    } else {
        $mensajes[]='AVISO: no se encontró el punto del menú global; el panel de Compras tendrá los seis enlaces igualmente.';
    }
}
// Todas las páginas deben seguir usando autenticación y las operaciones financieras solo SELECT.
foreach (['public/erp_catalogo_finanzas.php','public/erp_catalogo_historial_costos.php','public/erp_catalogo_comparar_proveedores.php','public/erp_alertas_compras.php','public/erp_catalogo_reportes.php','public/erp_catalogo_auditoria.php'] as $ruta) {
 if (!str_contains($next[$ruta], 'requireLogin();')) $errores[] = "La página $ruta no tiene la comprobación de inicio de sesión esperada.";
}
foreach ($src as $ruta=>$anterior) if ($next[$ruta] !== $anterior) $plan[$ruta] = $next[$ruta];
foreach ($plan as $ruta=>$contenido) {
    $temporal = tempnam(sys_get_temp_dir(), 'gyd-compras-perm-');
    if ($temporal === false) { $errores[] = "Sin espacio temporal para $ruta"; continue; }
    file_put_contents($temporal, $contenido);
    $out=[]; $exitCode=1;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporal).' 2>&1', $out, $exitCode);
    @unlink($temporal);
    if ($exitCode!==0) $errores[] = "Sintaxis PHP inválida en $ruta: ".implode(' ', $out);
}
foreach ($mensajes as $m) echo $m."\n";
if ($errores) { foreach ($errores as $e) fwrite(STDERR,"INCOMPATIBLE: $e\n"); fwrite(STDERR,"No se modificó ningún archivo.\n"); exit(3); }
echo "COMPATIBLE: ".count($plan)." archivos por actualizar. Solo permisos de consulta y enlaces; sin SQL.\n";
if ($modo==='--check') exit(0);
if (!$plan) { echo "YA INSTALADO: no hay cambios.\n"; exit(0); }
$bk = $raiz.'/tools/respaldos_compras_financieros_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if (!@mkdir($bk,0700,true)) { fwrite(STDERR,"No se pudo crear carpeta de respaldo.\n"); exit(4); }
foreach ($plan as $ruta=>$contenido) {
  $a=$bk.'/'.$ruta;
  if (!is_dir(dirname($a))&&!@mkdir(dirname($a),0700,true)) { fwrite(STDERR,"No se pudo preparar respaldo.\n");exit(4); }
  if (!copy($raiz.'/'.$ruta,$a)) { fwrite(STDERR,"No se pudo respaldar $ruta.\n");exit(4); }
}
$escritos=[];
try {
 foreach ($plan as $ruta=>$contenido) {
   $dest=$raiz.'/'.$ruta;
   $tmp=$dest.'.gyd_fin_temp_'.getmypid();
   if (file_put_contents($tmp,$contenido,LOCK_EX)===false) throw new RuntimeException("No se pudo escribir temporal $ruta");
   if (!rename($tmp,$dest)) { @unlink($tmp); throw new RuntimeException("No se pudo instalar $ruta"); }
   $escritos[]=$ruta;
 }
} catch(Throwable $e) {
 foreach (array_reverse($escritos) as $ruta) @copy($bk.'/'.$ruta,$raiz.'/'.$ruta);
 fwrite(STDERR,"ERROR: ".$e->getMessage().". Se restauraron los archivos modificados.\n");exit(5);
}
echo "INSTALADO: ".count($plan)." archivos. Respaldo: $bk\n";
echo "Ingresa con COMPRAS a public/erp_compras_inicio.php y abre Reportes, Costos y Alertas.\n";
