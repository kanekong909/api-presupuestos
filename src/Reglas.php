<?php
// REGLAS — instrucciones de la empresa escritas en lenguaje normal («cuando pidan X, haz Y»). La tabla `reglas`.
// Las reglas activas se añaden al prompt de la IA (al interpretar y al elegir producto), así se le enseña sin tocar código.
// Las crea y edita un administrador desde Administrar › Reglas.

final class Reglas
{
    /** Las que se le pasan a la IA: activas, por prioridad, con tope para no inflar el prompt. */
    public static function paraPrompt(): string
    {
        $filas = self::activas();
        if (!$filas) return '';
        // Cada regla lleva su NOMBRE entre corchetes: así la IA puede decir cuál ha aplicado en cada elección (se ve en la línea del presupuesto)
        $t = "\n\nREGLAS DE LA EMPRESA (obligatorias cuando su condición se cumple; mandan sobre tus preferencias):\n";
        foreach ($filas as $f) $t .= '- [' . trim($f['nombre']) . '] Cuando: ' . trim($f['condicion']) . ' → ' . trim($f['accion']) . "\n";
        return $t;
    }

    /** Las reglas que se le pasan a la IA ahora mismo (activas, por prioridad, con tope). */
    public static function activas(): array
    {
        return Db::filas('SELECT nombre, condicion, accion FROM reglas WHERE activo = 1 ORDER BY prioridad DESC, id LIMIT 40');
    }

    /** El nombre exacto de una regla activa, si lo que dijo la IA coincide con alguna (sin mayúsculas ni corchetes); null si no (nunca se admite un nombre inventado). */
    public static function nombreValido(mixed $dicho): ?string
    {
        $d = mb_strtolower(trim(trim((string) $dicho), '[] '));
        if ($d === '' || $d === 'null' || $d === 'ninguna') return null;
        foreach (self::activas() as $f) if (mb_strtolower(trim($f['nombre'])) === $d) return trim($f['nombre']);
        return null;
    }

    private static bool $columnasListas = false;
    /** Las columnas con las reglas usadas (presupuesto) y la regla aplicada (línea) se crean solas si faltan. */
    public static function asegurarColumnas(): void
    {
        if (self::$columnasListas) return;
        if (!in_array('reglas_usadas', array_column(Db::filas('SHOW COLUMNS FROM presupuestos'), 'Field'), true)) Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN reglas_usadas TEXT NULL');
        if (!in_array('regla', array_column(Db::filas('SHOW COLUMNS FROM presupuesto_lineas'), 'Field'), true)) Db::ejecutar('ALTER TABLE presupuesto_lineas ADD COLUMN regla VARCHAR(150) NULL');
        self::$columnasListas = true;
    }

    /**
     * Avisa de las reglas con las que choca una regla nueva o editada (contradicciones y duplicadas). No bloquea nada: la persona decide.
     * Si la IA no responde, devuelve «revisado: false» y se puede guardar igualmente.
     */
    public static function revisar(array $d, ?int $excluirId = null): array
    {
        $n = self::validar($d, true);
        $otras = Db::filas('SELECT id, nombre, condicion, accion FROM reglas WHERE activo = 1' . ($excluirId ? ' AND id <> ' . (int) $excluirId : '') . ' ORDER BY prioridad DESC, id LIMIT 60');
        if (!$otras) return ['revisado' => true, 'conflictos' => []];
        $usuario = "REGLA NUEVA:\nCuando: {$n['condicion']} → {$n['accion']}\n\nREGLAS EXISTENTES:\n";
        foreach ($otras as $o) $usuario .= "- id {$o['id']} ({$o['nombre']}): Cuando: {$o['condicion']} → {$o['accion']}\n";
        try {
            $j = Claude::json(Claude::preguntar('reglas', (string) file_get_contents(RAIZ . '/prompts/reglas.txt'), $usuario)['texto']);
        } catch (Throwable $e) {
            return ['revisado' => false, 'conflictos' => []];
        }
        if (!is_array($j)) return ['revisado' => false, 'conflictos' => []];
        $nombres = array_column($otras, null, 'id');
        $out = [];
        foreach ((array) ($j['conflictos'] ?? []) as $c) {
            $id = (int) ($c['regla_id'] ?? 0);
            if (!isset($nombres[$id]) || trim((string) ($c['motivo'] ?? '')) === '') continue;
            $out[] = ['regla_id' => $id, 'nombre' => $nombres[$id]['nombre'], 'condicion' => $nombres[$id]['condicion'], 'accion' => $nombres[$id]['accion'],
                'tipo' => ($c['tipo'] ?? '') === 'duplicada' ? 'duplicada' : 'contradiccion', 'motivo' => mb_substr(trim((string) $c['motivo']), 0, 400)];
        }
        return ['revisado' => true, 'conflictos' => $out];
    }

    public static function listar(): array
    {
        $a = Db::filas('SELECT id, codigo, nombre, grupo, condicion, accion, prioridad, activo FROM reglas ORDER BY activo DESC, prioridad DESC, id');
        foreach ($a as &$r) $r['activo'] = (bool) $r['activo'];
        return $a;
    }

    private static function validar(array $d, bool $todas): array
    {
        $out = [];
        foreach (['nombre' => 255, 'condicion' => 1000, 'accion' => 1000] as $c => $max) {
            if (!array_key_exists($c, $d) && !$todas) continue;
            $v = trim((string) ($d[$c] ?? ''));
            if ($v === '' || mb_strlen($v) > $max) throw new ErrorApi("«{$c}» es obligatorio (máximo {$max} caracteres).", 400);
            $out[$c] = $v;
        }
        if (array_key_exists('grupo', $d)) $out['grupo'] = mb_substr(trim((string) $d['grupo']), 0, 100);
        if (isset($d['prioridad'])) $out['prioridad'] = max(0, min(100, (int) $d['prioridad']));
        if (isset($d['activo'])) $out['activo'] = $d['activo'] ? 1 : 0;
        return $out;
    }

    public static function crear(array $d, array $yo): array
    {
        $n = self::validar($d, true) + ['prioridad' => 10, 'grupo' => ''];
        $id = Db::insertar('reglas', $n + ['codigo' => 'M' . substr((string) time(), -6)]);
        Eventos::anotar($yo, 'regla_creada', 'regla', $id, ['nombre' => $n['nombre']]);
        return self::uno($id);
    }

    public static function actualizar(int $id, array $d, array $yo): array
    {
        $r = self::uno($id);
        $n = self::validar($d, false);
        if (!$n) throw new ErrorApi('No hay nada que cambiar.', 400);
        Db::actualizar('reglas', $id, $n);
        Eventos::anotar($yo, 'regla_modificada', 'regla', $id, ['nombre' => $n['nombre'] ?? $r['nombre']]);
        return self::uno($id);
    }

    public static function eliminar(int $id, array $yo): void
    {
        $r = self::uno($id);
        Db::ejecutar('DELETE FROM reglas WHERE id = ?', [$id]);
        Eventos::anotar($yo, 'regla_eliminada', 'regla', $id, ['nombre' => $r['nombre']]);
    }

    private static function uno(int $id): array
    {
        $r = Db::fila('SELECT id, codigo, nombre, grupo, condicion, accion, prioridad, activo FROM reglas WHERE id = ?', [$id]);
        if (!$r) throw new ErrorApi('No existe esa regla.', 404);
        $r['activo'] = (bool) $r['activo'];
        return $r;
    }
}
