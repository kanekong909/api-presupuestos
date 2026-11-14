<?php
// FIJADOS — «para este concepto, usa siempre este producto/servicio». Se aplican después de que la IA elija y le ganan:
// es la forma más fiable de corregir algo que se repite. Los crea un administrador desde una línea («Usar siempre este»).

final class Fijados
{
    /** Clave del concepto: sus palabras principales, ordenadas (así «cable 6 mm» y «6 mm cable» coinciden). */
    public static function clave(array $req): string
    {
        // Todas las palabras, también los números (6 mm no es 10 mm), sin plurales y ordenadas
        $texto = trim((string) ($req['busqueda'] ?? '')) !== '' ? (string) $req['busqueda'] : (string) ($req['concepto'] ?? '');
        $p = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY) as $w) $p[Catalogo::raiz($w)] = true;
        $p = array_keys($p);
        sort($p);
        return mb_substr(implode(' ', $p), 0, 255);
    }

    /** El fijado de un requerimiento, o null. */
    public static function de(array $req): ?array
    {
        $clave = self::clave($req);
        if ($clave === '') return null;
        return Db::fila('SELECT * FROM fijados WHERE clave = ? AND tipo = ?', [$clave, $req['tipo']]) ?: null;
    }

    public static function fijar(int $presupuestoId, int $lineaId, array $yo): array
    {
        $l = Db::fila('SELECT * FROM presupuesto_lineas WHERE id = ? AND presupuesto_id = ?', [$lineaId, $presupuestoId]);
        if (!$l) throw new ErrorApi('No existe esa línea en este presupuesto.', 404);
        if (!$l['referencia_id']) throw new ErrorApi('Esa línea no tiene un producto o servicio elegido: elige uno primero.', 409);
        $i = Db::fila('SELECT json_interpretacion j FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$presupuestoId]);
        $req = null;
        foreach ((json_decode((string) ($i['j'] ?? ''), true)['requerimientos'] ?? []) as $r) if ((int) $r['id'] === (int) $l['requerimiento_id']) $req = $r;
        if (!$req) throw new ErrorApi('No se sabe qué se pidió en esa línea (presupuesto antiguo).', 409);
        $clave = self::clave($req);
        if ($clave === '') throw new ErrorApi('Ese concepto es demasiado vago para fijarlo.', 409);
        $tipo = strtolower($l['tipo']);
        Db::ejecutar('DELETE FROM fijados WHERE clave = ? AND tipo = ?', [$clave, $tipo]);
        $id = Db::insertar('fijados', ['clave' => $clave, 'concepto' => mb_substr((string) $req['concepto'], 0, 255), 'tipo' => $tipo, 'referencia_id' => (int) $l['referencia_id'],
            'nombre' => mb_substr((string) $l['concepto'], 0, 255), 'usuario_id' => $yo['id'] ?? null]);
        Eventos::anotar($yo, 'fijado_creado', 'fijado', $id, ['concepto' => $req['concepto'], 'nombre' => $l['concepto']]);
        return Db::fila('SELECT * FROM fijados WHERE id = ?', [$id]);
    }

    public static function listar(): array
    {
        return Db::filas('SELECT f.id, f.concepto, f.tipo, f.referencia_id, f.nombre, f.created_at, u.nombre AS creado_por FROM fijados f LEFT JOIN usuarios u ON u.id = f.usuario_id ORDER BY f.id DESC');
    }

    public static function eliminar(int $id, array $yo): void
    {
        $f = Db::fila('SELECT * FROM fijados WHERE id = ?', [$id]);
        if (!$f) throw new ErrorApi('No existe ese fijado.', 404);
        Db::ejecutar('DELETE FROM fijados WHERE id = ?', [$id]);
        Eventos::anotar($yo, 'fijado_eliminado', 'fijado', $id, ['concepto' => $f['concepto']]);
    }
}
