<?php
// M3 CLASIFICADOR — define el flujo: solo producto (Novelec), solo servicio (Base maestra) o producto + servicio.

final class Clasificador
{
    public const SOLO_PRODUCTO = 'SOLO_PRODUCTO';
    public const SOLO_SERVICIO = 'SOLO_SERVICIO';
    public const COMBINADO = 'COMBINADO';

    public static function clasificar(array $interpretacion): array
    {
        $hayP = $hayS = false;
        foreach ($interpretacion['requerimientos'] ?? [] as $r) {
            if (($r['tipo'] ?? '') === 'producto') $hayP = true; else $hayS = true;
        }
        if (!$hayP && !$hayS) throw new ErrorApi('No hay nada que presupuestar en este pedido.', 422);
        return [
            'tipo_presupuesto' => $hayP && $hayS ? self::COMBINADO : ($hayP ? self::SOLO_PRODUCTO : self::SOLO_SERVICIO),
            'grupo_principal' => (string) ($interpretacion['grupo_principal'] ?? 'General'),
            'grupos_relacionados' => $interpretacion['grupos_relacionados'] ?? [],
        ];
    }
}
