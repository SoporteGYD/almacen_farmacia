<?php
declare(strict_types=1);
/** IVA de compra de ficha maestra: 0 configurado es distinto de NULL/no configurado. */
function gyd_catalogo_iva_compra(mixed $raw, string $codigo=''): float {
 if ($raw === null || trim((string)$raw) === '') {
   // IVA 0% provisional sólo para órdenes de compra; no cambia la ficha maestra.
   return 0.0;
 }
 if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > 100) {
   throw new RuntimeException('IVA de compra no válido en Catálogo Maestro para '.$codigo.'.');
 }
 return round((float)$raw, 2);
}
/** IEPS: si está parametrizado es vinculante; si no, conserva la captura actual de la orden. */
function gyd_catalogo_ieps_si_existe(mixed $raw): ?float {
 if ($raw === null || trim((string)$raw) === '') return null;
 if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > 100) {
   throw new RuntimeException('IEPS no válido en Catálogo Maestro.');
 }
 return round((float)$raw, 2);
}
