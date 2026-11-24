<?php
declare(strict_types=1);
/**
 * GYD - Auditoria del Catalogo, clasificacion compatible (v2).
 * Solo ajustes de codigo; NO hay SQL ni escrituras a la base de datos.
 * Conserva cambios existentes en el archivo publico, incluidos permisos COMPRAS.
 * php tools/instalar_clasificacion_auditoria_v2.php --check|--apply
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check','--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_clasificacion_auditoria_v2.php --check|--apply\n"); exit(2);
}
$raiz = dirname(__DIR__);
$helperRuta = 'app/helpers/erp_auditoria_catalogo.php';
$publicRuta = 'public/erp_catalogo_auditoria.php';
$plantilla = __DIR__.'/clasificacion_auditoria_v2/erp_auditoria_catalogo.php';
$archivos = [$helperRuta, $publicRuta];
$errores = []; $plan = []; $mensajes = [];
foreach ($archivos as $ruta) if (!is_file($raiz.'/'.$ruta)) $errores[] = "Falta $ruta. Se necesita el modulo Auditoria del Catalogo.";
if (!is_file($plantilla)) $errores[]='No se encontro la plantilla del helper.';
if ($errores) { foreach($errores as $e) fwrite(STDERR, "INCOMPATIBLE: $e\n"); exit(3); }

// El helper no fue modificado por el parche de permisos COMPRAS; exigimos la version conocida
// o el resultado ya instalado para evitar reemplazar otras mejoras del usuario.
$helperActual = (string)file_get_contents($raiz.'/'.$helperRuta);
$helperNuevo = (string)file_get_contents($plantilla);
$hashOriginal = 'ad5e55ea3cc7b53fa562c75674af3f8911f23e5c5fd60a75c45cb4ce0970fcc0';
if (hash_equals(hash('sha256', $helperNuevo), hash('sha256',$helperActual))) {
    $mensajes[] = "YA INSTALADO: $helperRuta";
} elseif (hash_equals($hashOriginal, hash('sha256', $helperActual))) {
    $plan[$helperRuta] = $helperNuevo;
    $mensajes[] = "ACTUALIZAR: $helperRuta";
} else {
    $errores[] = "El helper $helperRuta tiene modificaciones desconocidas. No se sobrescribira.";
}

// Parche quirurgico de pagina. NO hay verificacion de hash completo: los parches
// de Compras insertan sus propios cambios en esta misma pantalla.
$paginaOriginal = (string)file_get_contents($raiz.'/'.$publicRuta);
$pagina = $paginaOriginal;
if (!str_contains($pagina, 'requireLogin();') ||
    !str_contains($pagina, 'gyd_ac_filtros($_GET,$reglas)') ||
    !str_contains($pagina, 'gyd_ac_contar($pdo,$reglas,$ficha,$filtro)')) {
    $errores[] = 'La pantalla de auditoria tiene una estructura no reconocida; no se tocara.';
}
function gydV2Insertar(string &$src, string $marca, string $ancla, string $sustitucion, string $etiqueta, array &$errs, array &$msgs): void {
    if (str_contains($src, $marca)) { $msgs[]="YA INSTALADO: $etiqueta"; return; }
    $veces=substr_count($src,$ancla);
    if ($veces!==1) { $errs[]="No se identifico un punto unico para $etiqueta (coincidencias: $veces)."; return; }
    $src = str_replace($ancla,$sustitucion,$src);
    $msgs[]="ACTUALIZAR: $etiqueta";
}
$anclaContador = '$cantidad=gyd_ac_contar($pdo,$reglas,$ficha,$filtro);';
$contadores = <<<'PHPINJ'
$cantidad=gyd_ac_contar($pdo,$reglas,$ficha,$filtro);
    /* GYD_CLASIFICACION_AUDITORIA_V2_CONTADORES */
    $gydV2FiltroResumen=$filtro;
    $gydV2FiltroResumen['tipo']='todos';
    $gydV2FiltroResumen['vista']='pendientes';
    $totalPendientes=gyd_ac_contar($pdo,$reglas,$ficha,$gydV2FiltroResumen);
    $gydV2FiltroResumen['vista']='observaciones';
    $totalObservaciones=gyd_ac_contar($pdo,$reglas,$ficha,$gydV2FiltroResumen);
PHPINJ;
gydV2Insertar($pagina,'GYD_CLASIFICACION_AUDITORIA_V2_CONTADORES',$anclaContador,$contadores,'Conteos por clasificacion',$errores,$mensajes);

// Colocar la navegacion antes del aviso de la ficha fiscal, sin sustituir pantalla.
$anclaPanel = '<?php if(!$ficha):?>';
$panel = <<<'PHPINJ'
<!-- GYD_CLASIFICACION_AUDITORIA_V2_PANEL: observaciones distintas de errores confirmados -->
<section class="panel"><div class="summary"><h2>Estado de la auditoría</h2><span class="muted">Las coincidencias y diferencias financieras necesitan verificación; no se eliminaron productos.</span></div>
<div class="stats">
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'pendientes','pagina'=>1]))?>"><strong><?=number_format($totalPendientes)?></strong><span>Pendientes de corrección de datos</span></a>
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'observaciones','pagina'=>1]))?>"><strong><?=number_format($totalObservaciones)?></strong><span>Observaciones por verificar</span></a>
<a class="stat" href="<?=gyd_ac_h(gyd_ac_link($filtro,['tipo'=>'todos','vista'=>'todas','pagina'=>1]))?>"><strong><?=number_format((int)($totales['productos_con_observaciones']??0))?></strong><span>Todos los hallazgos</span></a>
</div></section>
<?php if(!$ficha):?>
PHPINJ;
gydV2Insertar($pagina,'GYD_CLASIFICACION_AUDITORIA_V2_PANEL',$anclaPanel,$panel,'Panel de clasificacion',$errores,$mensajes);

// Preservar el parametro vista en los filtros/exportaciones.
$anclaForm = '<form method="get" action="erp_catalogo_auditoria.php" id="auditoria">';
$form = $anclaForm.'<!-- GYD_CLASIFICACION_AUDITORIA_V2_FORM --><input type="hidden" name="vista" value="<?=gyd_ac_h($filtro[\'vista\'])?>">';
gydV2Insertar($pagina,'GYD_CLASIFICACION_AUDITORIA_V2_FORM',$anclaForm,$form,'Filtro y exportaciones por vista',$errores,$mensajes);

$anclaTitulo = '<h2>Productos por revisar</h2>';
$titulo = <<<'PHPINJ'
<h2><!-- GYD_CLASIFICACION_AUDITORIA_V2_TITULO --><?= $filtro['tipo']!=='todos' ? 'Productos con la incidencia seleccionada' : ($filtro['vista']==='pendientes'?'Pendientes de corrección':($filtro['vista']==='observaciones'?'Observaciones por verificar':'Todos los hallazgos')) ?></h2>
PHPINJ;
gydV2Insertar($pagina,'GYD_CLASIFICACION_AUDITORIA_V2_TITULO',$anclaTitulo,$titulo,'Titulo de resultados',$errores,$mensajes);

if ($pagina!==$paginaOriginal) $plan[$publicRuta]=$pagina;
else $mensajes[]="YA INSTALADO: $publicRuta";

// Si una parte esta marcada como instalada, todas las otras deben resultar compatibles.
foreach($plan as $ruta=>$codigo) {
    $temporal = tempnam(sys_get_temp_dir(),'gyd_aud_v2_');
    if ($temporal===false) { $errores[]="No se pudo crear temporal para $ruta"; continue; }
    try {
        if (file_put_contents($temporal,$codigo)===false) { $errores[]="No se pudo preparar $ruta"; continue; }
        $salida=[];$estado=1;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temporal).' 2>&1',$salida,$estado);
        if($estado!==0) $errores[]="Sintaxis invalida en $ruta: ".implode(' ',$salida);
    } finally { @unlink($temporal); }
}
foreach($mensajes as $m) echo $m."\n";
if($errores) { foreach($errores as $e) fwrite(STDERR,"INCOMPATIBLE: $e\n"); fwrite(STDERR,"NO SE MODIFICARON ARCHIVOS. Envia ambos archivos si necesitas una adaptacion especifica.\n");exit(3); }
if ($modo==='--check') { echo "COMPATIBLE: ".count($plan)." archivo(s) por actualizar. Base de datos intacta; se respetan cambios previos.\n";exit(0); }
if (!$plan) { echo "YA INSTALADO: sin cambios\n";exit(0); }
$backup=$raiz.'/tools/backups/clasificacion_auditoria_v2_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
foreach ($plan as $ruta=>$codigo) {
    $copia=$backup.'/'.$ruta;
    if (!is_dir(dirname($copia))&&!@mkdir(dirname($copia),0775,true)) { fwrite(STDERR,"No se pudo crear respaldo de $ruta\n");exit(4); }
    if (!@copy($raiz.'/'.$ruta,$copia)) { fwrite(STDERR,"No se pudo respaldar $ruta\n");exit(4); }
}
$cambiados=[];
try {
    foreach($plan as $ruta=>$codigo) {
        $destino=$raiz.'/'.$ruta;
        $tmp=$destino.'.gyd_v2_'.getmypid();
        if(file_put_contents($tmp,$codigo,LOCK_EX)===false) throw new RuntimeException("No se pudo preparar $ruta");
        // Windows a veces no permite reemplazar mediante rename: respaldo ya hecho.
        if(!@rename($tmp,$destino)) {
            if(!@copy($tmp,$destino)) { @unlink($tmp);throw new RuntimeException("No se pudo instalar $ruta"); }
            @unlink($tmp);
        }
        $cambiados[]=$ruta;
    }
} catch (Throwable $e) {
    foreach(array_reverse($cambiados) as $ruta) @copy($backup.'/'.$ruta,$raiz.'/'.$ruta);
    fwrite(STDERR,'Fallo la instalacion: '.$e->getMessage().". Se intento restaurar el respaldo.\n");exit(5);
}
echo 'INSTALADO: '.count($cambiados).' archivos. Respaldo: '.$backup."\n";
echo "Los permisos COMPRAS y las operaciones SQL permanecen intactos.\n";
