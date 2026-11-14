<?php
// EVENTOS — registro de actividad: quién hizo qué y cuándo. Nunca guarda contraseñas ni tokens.

final class Eventos
{
    public static function anotar(?array $yo, string $accion, ?string $entidad = null, ?int $entidadId = null, array $detalle = []): void
    {
        try {
            Db::insertar('eventos', [
                'usuario_id' => $yo['id'] ?? null, 'usuario' => $yo['usuario'] ?? ($detalle['usuario'] ?? null), 'accion' => $accion,
                'entidad' => $entidad, 'entidad_id' => $entidadId,
                'detalle' => $detalle ? json_encode(array_diff_key($detalle, ['clave' => 1, 'token' => 1]), JSON_UNESCAPED_UNICODE) : null,
                'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[presupuestos] no se pudo anotar el evento: ' . $e->getMessage());   // el registro nunca tumba la operación
        }
    }

    public static function listar(array $filtros, int $limite = 100): array
    {
        $donde = [];
        $args = [];
        foreach (['usuario' => 'usuario', 'accion' => 'accion', 'entidad' => 'entidad'] as $q => $col) {
            if (!empty($filtros[$q])) { $donde[] = "$col = ?"; $args[] = (string) $filtros[$q]; }
        }
        if (!empty($filtros['entidad_id'])) { $donde[] = 'entidad_id = ?'; $args[] = (int) $filtros['entidad_id']; }
        $sql = 'SELECT * FROM eventos' . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limite));
        return array_map(function ($f) { $f['detalle'] = $f['detalle'] ? json_decode($f['detalle'], true) : null; return $f; }, Db::filas($sql, $args));
    }
}
