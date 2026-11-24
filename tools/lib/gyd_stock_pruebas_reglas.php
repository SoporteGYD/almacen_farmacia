<?php
declare(strict_types=1);
/** Reglas puras de conciliación del POS G&D; SOLO laboratorio `prueba:3307`. */
function gyd_stock_prueba_objetivo(int $sucursal, ?int $general, ?int $farmacia, int $almacen, int $demo=48): array {
    if ($sucursal < 1 || $sucursal > 4 || $almacen < 0 || $demo < 1 || $demo > 10000) {
        throw new DomainException('Sucursal, almacén o existencia DEMO inválida.');
    }
    if ($general !== null && $general < 0 || $farmacia !== null && $farmacia < 0) {
        throw new DomainException('No se permiten existencias negativas.');
    }
    if (in_array($sucursal, [1,2], true)) {
        // Un conteo ya existente de farmacia tiene prioridad; no se reemplaza por cifras ficticias.
        $derivar = $farmacia === null && $general !== null && $general >= $almacen;
        $f = $farmacia ?? ($derivar ? $general - $almacen : $demo);
        $g = $f + $almacen;
        if ($g > 2147483647) throw new DomainException('Existencia fuera del rango INT.');
        return ['farmacia'=>$f, 'general'=>$g,
                'origen'=> $farmacia !== null ? 'FARMACIA_CONTEO_EXISTENTE' : ($derivar ? 'FARMACIA_DERIVADA_POS' : 'FARMACIA_DEMO'),
                'concilia'=> true];
    }
    // Tapachula/Toscana: pool único en la fila sucursal_id=3; NO sumar la 4.
    return ['farmacia'=>null,'general'=>$general ?? $demo,
            'origen'=>$general===null?'POOL_DEMO':'POOL_EXISTENTE','concilia'=>true];
}
/** Selecciona un costo existente antes de crear un costo exclusivamente DEMO. */
function gyd_stock_prueba_costo(array $p, array $precios): array {
    foreach (['costo_ultimo'=>'ULTIMO_EXISTENTE','costo_promedio'=>'PROMEDIO_EXISTENTE','precio_compra'=>'COMPRA_EXISTENTE'] as $campo=>$fuente) {
        if ((float)($p[$campo]??0) > 0) {
            return ['costo'=>number_format((float)$p[$campo],4,'.',''),'fuente'=>$fuente,'crear'=>false];
        }
    }
    $val=array_values(array_filter(array_map('floatval',$precios),static fn($x)=>$x>0));
    if (!$val) return ['costo'=>null,'fuente'=>'SIN_PRECIOS','crear'=>false];
    $cost=max(.01,round(min($val)*.65,4));
    return ['costo'=>number_format($cost,4,'.',''),'fuente'=>'COSTO_SIMULADO_65_PCT','crear'=>true];
}
