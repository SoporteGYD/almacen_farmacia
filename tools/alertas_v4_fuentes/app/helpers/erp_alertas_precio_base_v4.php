<?php
declare(strict_types=1);
/**
 * G&D V4 – Descarta la alerta financiera adicional del precio BASE únicamente
 * si todas las sucursales POS configuradas tienen precio de venta propio > 0.
 * No descarta las alertas particulares de ninguna sucursal ni altera precios.
 */
function gdca_base_cubierta_por_sucursales(array $sucursales, array $precios, int $productoId): bool {
    if ($productoId<=0) return false;
    $total = 0;
    foreach ($sucursales as $sid => $nombre) {
        $sid = (int)$sid;
        if ($sid <= 0) continue; // 0 representa precio base; no una sucursal.
        $total++;
        $precio = $precios[$sid][$productoId] ?? null;
        if ($precio === null || !is_numeric($precio) || !is_finite((float)$precio) || (float)$precio <= 0) {
            return false; // Sucursal incompleta: mantener el aviso de precio base.
        }
    }
    return $total > 0;
}
