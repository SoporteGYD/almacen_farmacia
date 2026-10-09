<?php
/**
 * Recupera únicamente el nombre explícito "Proveedor: ..." de la referencia.
 * No infiere el proveedor desde otros campos como laboratorio o documento.
 */
/**
 * Etiquetas de movimientos internos: nunca deben convertirse en proveedores.
 * Comparacion por nombre completo para no bloquear nombres comerciales largos.
 */
function proveedorEsConceptoInterno(?string $nombre): bool
{
    $nombre = trim(preg_replace('/\s+/u', ' ', (string)$nombre) ?: (string)$nombre);
    $clave = function_exists('mb_strtoupper')
        ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);

    return in_array($clave, [
        'TRASPASO', 'TRASPASOS', 'TRANSFERENCIA', 'TRANSFERENCIAS',
        'TRASLADO', 'TRASLADOS', 'ENTRADA', 'AJUSTE',
        'AJUSTE DE ENTRADA', 'INVENTARIO INICIAL',
    ], true);
}

function proveedorHistoricoDesdeReferencia(?string $referencia): ?string
{
    foreach (explode('|', (string)$referencia) as $segmento) {
        $segmento = trim($segmento);
        if (preg_match('/^Proveedor\s*:\s*(.+)$/iu', $segmento, $partes) !== 1) {
            continue;
        }

        $nombre = trim(preg_replace('/\s+/u', ' ', $partes[1]) ?: $partes[1]);
        if ($nombre === '' || proveedorEsConceptoInterno($nombre)
            || preg_match('/^(sin proveedor|no aplica|n\/a|ninguno|s\/p|-)$/iu', $nombre)) {
            return null;
        }
        $longitud = function_exists('mb_strlen')
            ? mb_strlen($nombre, 'UTF-8') : strlen($nombre);
        // La columna proveedores.nombre es varchar(150); no truncar datos históricos.
        return $longitud <= 150 ? $nombre : null;
    }

    return null;
}
