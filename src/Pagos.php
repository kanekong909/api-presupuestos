<?php
// CONDICIONES DE PAGO — cómo paga el cliente un presupuesto: de una vez, en dos o tres plazos, o como se acuerde.
// Se guarda solo el reparto en porcentajes y CUÁNDO toca cada pago; los importes en euros se calculan siempre con el total actual
// del presupuesto (si el total cambia al recalcular, los pagos se ajustan solos). Los porcentajes tienen que sumar 100 %.

final class Pagos
{
    /** Cuándo toca cada pago. */
    public const CUANDO = [
        'aceptacion' => 'Al aceptar el presupuesto',
        'inicio' => 'Al iniciar los trabajos',
        'avance' => 'Durante la obra (según avance)',
        'fin' => 'Al finalizar los trabajos',
    ];
    public const MODALIDADES = ['contado', 'dos_60_40', 'dos_50_50', 'tres', 'personalizado'];
    private const MAX_PLAZOS = 6;

    private static bool $lista = false;
    public static function asegurar(): void
    {
        if (self::$lista) return;
        if (!Db::fila("SHOW COLUMNS FROM presupuestos LIKE 'condiciones_pago'")) Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN condiciones_pago TEXT NULL');
        self::$lista = true;
    }

    /**
     * Comprueba y deja limpias unas condiciones de pago. Devuelve null si no hay ninguna (se quitan).
     * @return array{modalidad:string, plazos:list<array{porcentaje:float, cuando:string}>, nota:?string}|null
     */
    public static function validar(mixed $d): ?array
    {
        if ($d === null || $d === '' || $d === [] || (is_array($d) && empty($d['plazos']))) return null;
        if (!is_array($d)) throw new ErrorApi('Las condiciones de pago no son válidas.', 400);
        $modalidad = in_array($d['modalidad'] ?? '', self::MODALIDADES, true) ? $d['modalidad'] : 'personalizado';
        $plazos = array_values((array) $d['plazos']);
        if (count($plazos) < 1 || count($plazos) > self::MAX_PLAZOS) throw new ErrorApi('Pon entre 1 y ' . self::MAX_PLAZOS . ' pagos.', 400);
        $out = [];
        $suma = 0.0;
        foreach ($plazos as $i => $p) {
            $pct = round((float) str_replace(',', '.', (string) ($p['porcentaje'] ?? 0)), 2);
            if ($pct <= 0 || $pct > 100) throw new ErrorApi('El pago ' . ($i + 1) . ' tiene que ser de más de 0 % y como mucho 100 %.', 400);
            $cuando = (string) ($p['cuando'] ?? '');
            if (!isset(self::CUANDO[$cuando])) throw new ErrorApi('Indica cuándo toca el pago ' . ($i + 1) . '.', 400);
            $out[] = ['porcentaje' => $pct, 'cuando' => $cuando];
            $suma += $pct;
        }
        if (abs($suma - 100) > 0.005) throw new ErrorApi('Los porcentajes suman ' . rtrim(rtrim(number_format($suma, 2, ',', ''), '0'), ',') . ' % y tienen que sumar 100 %.', 400);
        $nota = mb_substr(trim((string) ($d['nota'] ?? '')), 0, 300);
        return ['modalidad' => $modalidad, 'plazos' => $out, 'nota' => $nota === '' ? null : $nota];
    }

    /** Los pagos con su importe en euros según el total. El último se lleva el redondeo, así la suma es siempre exacta. */
    public static function conImportes(?array $cond, float $total): ?array
    {
        if (!$cond) return null;
        $resto = round($total, 2);
        $n = count($cond['plazos']);
        foreach ($cond['plazos'] as $i => &$p) {
            $p['importe'] = $i === $n - 1 ? $resto : round($total * $p['porcentaje'] / 100, 2);
            $resto = round($resto - $p['importe'], 2);
            $p['texto'] = self::CUANDO[$p['cuando']];
        }
        return $cond;
    }

    /** Las condiciones guardadas de un presupuesto (ya con importes), o null. */
    public static function de(array $presupuesto): ?array
    {
        $j = $presupuesto['condiciones_pago'] ?? null;
        $c = is_string($j) && $j !== '' ? json_decode($j, true) : null;
        return is_array($c) ? self::conImportes($c, (float) ($presupuesto['total'] ?? 0)) : null;
    }

    public static function guardar(int $id, mixed $datos): ?array
    {
        self::asegurar();
        $c = self::validar($datos);
        Db::actualizar('presupuestos', $id, ['condiciones_pago' => $c ? json_encode($c, JSON_UNESCAPED_UNICODE) : null]);
        return $c;
    }
}
