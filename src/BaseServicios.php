<?php
// BASE DE SERVICIOS — listar, crear, editar y borrar los servicios (la tabla `servicios`) desde la administración, sin tocar la base a mano.
// Lo que se cambia aquí lo usa el cálculo en cuanto se guarda. Cada cambio queda en el registro de actividad.

final class BaseServicios
{
    public const LINEAS = ['ALT', 'CLI', 'DOM', 'ELE', 'FOT', 'GEN', 'PCI', 'SEG', 'VE'];
    public const MODOS = ['FIJO', 'DESDE', 'BASE', 'BASE + VARIABLE', '€/H', '€/UD', '€/STRING', 'DESDE €/M', 'SOLO MATERIAL'];

    /** @return array{total:int, pagina:int, limite:int, servicios:array} */
    public static function listar(array $f): array
    {
        $donde = ["codigo <> 'codigo'"];   // la fila de cabecera que trajo el volcado
        $args = [];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $donde[] = '(codigo LIKE ? OR nombre LIKE ? OR palabras_clave LIKE ? OR familia LIKE ? OR subarea LIKE ?)';
            array_push($args, ...array_fill(0, 5, "%$q%"));
        }
        if (in_array(strtoupper((string) ($f['linea'] ?? '')), self::LINEAS, true)) { $donde[] = 'linea = ?'; $args[] = strtoupper((string) $f['linea']); }
        $estado = strtoupper((string) ($f['estado'] ?? ''));
        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) { $donde[] = 'estado = ?'; $args[] = $estado; }

        $w = implode(' AND ', $donde);
        $limite = max(1, min(100, (int) ($f['limite'] ?? 40)));
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $total = (int) Db::fila("SELECT COUNT(*) n FROM servicios WHERE $w", $args)['n'];
        $filas = Db::filas("SELECT * FROM servicios WHERE $w ORDER BY linea, codigo LIMIT $limite OFFSET " . (($pagina - 1) * $limite), $args);
        return ['total' => $total, 'pagina' => $pagina, 'limite' => $limite, 'servicios' => array_map([self::class, 'publico'], $filas)];
    }

    public static function obtener(int $id): array
    {
        $f = Db::fila("SELECT * FROM servicios WHERE id = ? AND codigo <> 'codigo'", [$id]);
        if (!$f) throw new ErrorApi('No existe ese servicio.', 404);
        return self::publico($f);
    }

    public static function crear(array $d, array $yo): array
    {
        $v = self::validar($d, null);
        if (Db::fila('SELECT id FROM servicios WHERE codigo = ?', [$v['codigo']])) throw new ErrorApi("Ya existe un servicio con el código {$v['codigo']}.", 409);
        $id = Db::insertar('servicios', $v + ['origen' => 'ADMIN ' . ($yo['usuario'] ?? '')]);
        Eventos::anotar($yo, 'servicio_creado', 'servicio', $id, ['codigo' => $v['codigo'], 'nombre' => $v['nombre'], 'precio' => $v['precio_unitario']]);
        return self::obtener($id);
    }

    public static function actualizar(int $id, array $d, array $yo): array
    {
        $antes = Db::fila("SELECT * FROM servicios WHERE id = ? AND codigo <> 'codigo'", [$id]);
        if (!$antes) throw new ErrorApi('No existe ese servicio.', 404);
        $v = self::validar($d, $antes);
        if ($v['codigo'] !== $antes['codigo'] && Db::fila('SELECT id FROM servicios WHERE codigo = ? AND id <> ?', [$v['codigo'], $id])) {
            throw new ErrorApi("Ya existe otro servicio con el código {$v['codigo']}.", 409);
        }
        $cambios = [];
        foreach ($v as $campo => $nuevo) if ((string) $nuevo !== (string) $antes[$campo]) $cambios[$campo] = [mb_substr((string) $antes[$campo], 0, 80), mb_substr((string) $nuevo, 0, 80)];
        if (!$cambios) return self::publico($antes);
        Db::actualizar('servicios', $id, $v);
        Eventos::anotar($yo, 'servicio_modificado', 'servicio', $id, ['codigo' => $v['codigo'], 'cambios' => $cambios]);
        return self::obtener($id);
    }

    /** Un servicio que ya sale en presupuestos no se borra (rompería su historial): se desactiva y deja de ofrecerse. */
    public static function eliminar(int $id, array $yo): array
    {
        $s = self::obtener($id);
        $usos = (int) Db::fila("SELECT COUNT(*) n FROM presupuesto_lineas WHERE tipo = 'SERVICIO' AND referencia_id = ?", [$id])['n'];
        if ($usos > 0) {
            throw new ErrorApi("Este servicio sale en $usos línea" . ($usos === 1 ? '' : 's') . ' de presupuestos y no se puede borrar. Desactívalo para que deje de ofrecerse.', 409);
        }
        Db::ejecutar('DELETE FROM servicios WHERE id = ?', [$id]);
        Eventos::anotar($yo, 'servicio_eliminado', 'servicio', $id, ['codigo' => $s['codigo'], 'nombre' => $s['nombre']]);
        return ['id' => $id, 'codigo' => $s['codigo']];
    }

    // ---------------------------------------------------------------- validación

    /** Deja los datos listos para guardar; en una edición, lo que no se manda se queda como estaba. */
    private static function validar(array $d, ?array $base): array
    {
        $dato = fn(string $k, mixed $defecto = '') => array_key_exists($k, $d) ? $d[$k] : ($base[$k] ?? $defecto);
        $texto = fn(string $k, int $max, bool $obligatorio = false) => (function () use ($dato, $k, $max, $obligatorio) {
            $v = trim((string) $dato($k));
            if ($obligatorio && $v === '') throw new ErrorApi("Falta «{$k}».", 400);
            if (mb_strlen($v) > $max) throw new ErrorApi("«{$k}» es demasiado largo (máximo $max caracteres).", 400);
            return $v;
        })();
        $numero = function (string $k) use ($dato): float {
            $v = $dato($k, 0);
            $v = is_string($v) ? str_replace(',', '.', trim($v)) : $v;
            if ($v === '' || $v === null) return 0.0;
            if (!is_numeric($v) || (float) $v < 0) throw new ErrorApi("«{$k}» tiene que ser un número de 0 o más.", 400);
            return round((float) $v, 2);
        };

        $codigo = strtoupper($texto('codigo', 100, true));
        if (!preg_match('/^[A-Z0-9._-]{3,100}$/', $codigo)) throw new ErrorApi('El código: de 3 a 100 caracteres, solo letras, números, punto, guion y guion bajo (p. ej. MO-ELE-CUA-002).', 400);
        $linea = strtoupper($texto('linea', 50, true));
        if (!in_array($linea, self::LINEAS, true)) throw new ErrorApi('La línea tiene que ser una de: ' . implode(', ', self::LINEAS) . '.', 400);
        $modo = mb_strtoupper($texto('modo_precio', 50) ?: 'FIJO');
        if (!in_array($modo, self::MODOS, true)) throw new ErrorApi('Modo de precio no válido. Opciones: ' . implode(', ', self::MODOS) . '.', 400);
        $estado = strtoupper($texto('estado', 30) ?: 'ACTIVO');
        if (!in_array($estado, ['ACTIVO', 'INACTIVO'], true)) throw new ErrorApi('El estado es ACTIVO o INACTIVO.', 400);

        return [
            'codigo' => $codigo, 'linea' => $linea, 'area' => strtoupper($texto('area', 100)) ?: $linea,
            'familia' => $texto('familia', 150), 'subarea' => $texto('subarea', 150),
            'nombre' => $texto('nombre', 255, true), 'descripcion' => $texto('descripcion', 2000),
            'unidad' => $texto('unidad', 50) ?: 'ud', 'precio_unitario' => $numero('precio_unitario'), 'importe_minimo' => $numero('importe_minimo'),
            'modo_precio' => $modo, 'palabras_clave' => $texto('palabras_clave', 2000), 'notas_ia' => $texto('notas_ia', 2000),
            'lleva_material' => $dato('lleva_material', 0) ? 1 : 0, 'estado' => $estado,
        ];
    }

    private static function publico(array $f): array
    {
        return [
            'id' => (int) $f['id'], 'codigo' => $f['codigo'], 'linea' => $f['linea'], 'area' => $f['area'], 'familia' => (string) $f['familia'], 'subarea' => (string) $f['subarea'],
            'nombre' => $f['nombre'], 'descripcion' => (string) $f['descripcion'], 'unidad' => (string) $f['unidad'], 'precio_unitario' => (float) $f['precio_unitario'],
            'importe_minimo' => (float) $f['importe_minimo'], 'modo_precio' => $f['modo_precio'], 'palabras_clave' => (string) $f['palabras_clave'], 'notas_ia' => (string) $f['notas_ia'],
            'lleva_material' => (bool) $f['lleva_material'], 'estado' => strtoupper((string) $f['estado']), 'origen' => $f['origen'] ?? null,
        ];
    }
}
