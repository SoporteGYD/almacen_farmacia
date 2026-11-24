<?php
/**
 * Política operativa de órdenes de compra - Distribución G&D.
 * false: la OC documenta un pedido ya convenido y no necesita autorización.
 * true: restablece el circuito de autorización de Dirección previamente instalado.
 * Cambiar SOLO por decisión de Dirección/Sistemas y después de probar en respaldo.
 * No cambia estados históricos ni modifica existencias.
 */
declare(strict_types=1);
const GD_OC_REQUIERE_AUTORIZACION = false;
function gd_oc_requiere_autorizacion(): bool { return GD_OC_REQUIERE_AUTORIZACION; }
function gd_oc_esta_lista(?string $estado, ?string $autorizacion=null): bool {
    return $estado === 'EMITIDA'
        && (!gd_oc_requiere_autorizacion() || $autorizacion === 'AUTORIZADA');
}
