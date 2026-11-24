<?php
declare(strict_types=1);
/**
 * Traduccion de textos del metodo de calculo automatico para ERP G&D.
 * No modifica formulas, base de datos, precios, costos ni existencias.
 * Ejecutar desde CLI: php tools/instalar_metodos_precios_es.php --check|--apply
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo linea de comandos.\n"); }
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/instalar_metodos_precios_es.php --check|--apply\n"); exit(2);
}
$file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'erp_cambio_precios.php';
if (!is_file($file)) { fwrite(STDERR, "INCOMPATIBLE: no se encontro public/erp_cambio_precios.php\n"); exit(1); }
$source = file_get_contents($file);
if ($source === false) { fwrite(STDERR, "ERROR: no se pudo leer el archivo.\n"); exit(1); }
$original = <<<'HTML'
<div class="choice"><label>Método de cálculo automático</label><label><input type="radio" name="calculo" value="MARK_UP" checked>Mark up · % sobre costo</label><label><input type="radio" name="calculo" value="MARK_DOWN">Mark down · % del precio de venta</label></div>
HTML;
$translated = <<<'HTML'
<div class="choice"><label>Método para calcular el precio de venta</label><label><input type="radio" name="calculo" value="MARK_UP" checked>Ganancia sobre el costo de compra<small style="display:block;margin:3px 0 8px 23px;color:#587189;font-size:12px;font-weight:400;line-height:1.4">Suma el porcentaje al costo. Ejemplo: si cuesta $100 y eliges 30%, el precio sugerido será $130.00.</small></label><label><input type="radio" name="calculo" value="MARK_DOWN">Margen de ganancia sobre el precio de venta<small style="display:block;margin:3px 0 6px 23px;color:#587189;font-size:12px;font-weight:400;line-height:1.4">La ganancia representa ese porcentaje del precio de venta. Ejemplo: costo $100 y margen de 30% = precio sugerido $142.86.</small></label></div>
HTML;
if (substr_count($source, $translated) === 1 && substr_count($source, $original) === 0) {
    echo "INSTALADO: los textos ya estan en espanol, sin modificar nada.\n";
    exit(0);
}
if (substr_count($source, $original) !== 1) {
    fwrite(STDERR, "INCOMPATIBLE: el bloque original ha cambiado. No se modifico ningun archivo.\n");
    exit(1);
}
if ($mode === '--check') {
    echo "COMPATIBLE: se cambiaran solo los textos explicativos en public/erp_cambio_precios.php.\n";
    exit(0);
}
$changed = str_replace($original, $translated, $source);
if ($changed === $source) { fwrite(STDERR, "ERROR: no hubo cambios.\n"); exit(1); }
$backup = $file . '.bak_metodos_es_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
if (!copy($file, $backup)) { fwrite(STDERR, "ERROR: no pudo crearse respaldo; abortado.\n"); exit(1); }
if (file_put_contents($file, $changed, LOCK_EX) !== strlen($changed)) {
    copy($backup, $file);
    fwrite(STDERR, "ERROR: fallo de escritura; se intento restaurar el respaldo.\n");
    exit(1);
}
echo "CORRECTO: los dos metodos estan en espanol y tienen ejemplos.\n";
echo "Respaldo: $backup\n";
echo "No se modificaron formulas, precios ni existencias. Actualiza navegador con Ctrl + F5.\n";
