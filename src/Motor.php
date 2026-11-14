<?php
// M4 MOTOR ECONÓMICO — calcula precios, márgenes, importes, IVA y total. Aquí no interviene la IA: solo reglas y números.
//   · Servicio: precio unitario de la base × cantidad (con el importe mínimo si lo hay). Sin margen (ya es precio de venta).
//   · Producto: coste del proveedor (sin IVA) + margen (configuracion › margen_producto).
//   · IVA general: configuracion › iva_general.

final class Motor
{
    public const SUMAN = ['ACEPTADO', 'REVISION'];

    public static function dinero(float $n): float
    {
        return round($n, 2);
    }

    /** Precios de una línea para un elemento del catálogo y una cantidad. */
    public static function precios(array $item, float $cantidad): array
    {
        if ($item['fuente'] === 'producto') {
            $iva = Ajustes::numero('iva_general');
            $coste = $item['iva_incluido'] ? $item['precio'] / (1 + $iva) : $item['precio'];
            $coste = self::dinero($coste);
            $margen = Ajustes::numero('margen_producto');
            $unit = self::dinero($coste * (1 + $margen));
            return ['coste' => $coste, 'margen' => $margen, 'precio_unitario' => $unit, 'importe' => self::dinero($unit * $cantidad)];
        }
        $unit = self::dinero($item['precio']);
        $importe = self::dinero($unit * $cantidad);
        if (($item['importe_minimo'] ?? 0) > 0) $importe = max($importe, self::dinero($item['importe_minimo']));
        return ['coste' => null, 'margen' => null, 'precio_unitario' => $unit, 'importe' => $importe];
    }

    /** Aviso para el técnico según el modo de precio (regla R011: «DESDE» no es precio definitivo). */
    public static function avisoPrecio(array $item): ?string
    {
        $m = mb_strtoupper((string) ($item['modo_precio'] ?? ''));
        if (str_contains($m, 'DESDE')) return 'Precio «desde»: no es definitivo, puede subir según el trabajo.';
        if (str_contains($m, 'BASE')) return 'Precio base: se suma una parte variable según el trabajo.';
        return null;
    }

    /** Totales a partir de las líneas (solo cuentan las aceptadas y las que están en revisión). */
    public static function totales(array $lineas): array
    {
        $sub = 0.0;
        foreach ($lineas as $l) if (in_array($l['estado'], self::SUMAN, true)) $sub += (float) $l['importe'];
        $sub = self::dinero($sub);
        $iva = self::dinero($sub * Ajustes::numero('iva_general'));
        return ['subtotal' => $sub, 'iva' => $iva, 'total' => self::dinero($sub + $iva)];
    }

    /** Estado del presupuesto: CALCULADO si no queda nada por revisar; si no, REVISION. */
    public static function estadoGlobal(array $lineas, array $datosPendientes): string
    {
        foreach ($lineas as $l) if ($l['estado'] !== 'ACEPTADO' && $l['estado'] !== 'DESCARTADO') return 'REVISION';
        return $datosPendientes ? 'REVISION' : 'CALCULADO';
    }
}
