<?php
declare(strict_types=1);
/** G&D | Cálculo exacto del arqueo por denominaciones en centavos. */
final class PosCorteDenominaciones {
    public const VALORES = [
        'b1000'=>100000, 'b500'=>50000, 'b200'=>20000, 'b100'=>10000,
        'b50'=>5000, 'b20'=>2000,
        'm20'=>2000, 'm10'=>1000, 'm5'=>500, 'm2'=>200, 'm1'=>100,
        'm050'=>50, 'm020'=>20, 'm010'=>10, 'm005'=>5,
    ];
    public static function calcular(array $captura): array {
        if (array_diff(array_keys($captura), array_keys(self::VALORES))) {
            throw new DomainException('El desglose contiene denominaciones no reconocidas.');
        }
        $resultado = []; $centavos = 0;
        foreach (self::VALORES as $tipo => $valor) {
            $cantidad = array_key_exists($tipo,$captura) ? $captura[$tipo] : 0;
            if (!is_int($cantidad) && !(is_string($cantidad) && preg_match('/^\d{1,6}$/D', $cantidad))) {
                throw new DomainException('Cantidad inválida para la denominación '.$tipo.'.');
            }
            $cantidad = (int)$cantidad;
            if ($cantidad < 0 || $cantidad > 99999) throw new DomainException('Cantidad fuera de rango para '.$tipo.'.');
            $resultado[$tipo] = $cantidad;
            $centavos += $cantidad * $valor;
        }
        if ($centavos > 99999999999) throw new DomainException('El total contado es demasiado alto.');
        return ['detalle' => $resultado, 'total_centavos' => $centavos];
    }
}
