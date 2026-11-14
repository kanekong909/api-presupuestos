<?php
// TRABAJOS TIPO — una partida unificada guardada para reutilizarla en presupuestos futuros («Suministro e instalación de punto de enchufe Schuko»).
// Se guarda su título, su descripción y de qué está hecha (qué productos y servicios, con qué cantidades); al usarla, los precios son los de HOY.
// Solo los administradores los crean, editan y borran; cualquier usuario puede añadirlos a un presupuesto.

final class TrabajosTipo
{
    private static bool $listo = false;

    /** Las tablas (sql/10) se crean solas si faltan: basta con subir el código. */
    public static function asegurar(): void
    {
        if (self::$listo) return;
        Db::ejecutar('CREATE TABLE IF NOT EXISTS trabajos_tipo (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(255) NOT NULL, descripcion TEXT NULL,
            usuario_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_titulo (titulo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        Db::ejecutar("CREATE TABLE IF NOT EXISTS trabajos_tipo_lineas (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, trabajo_id INT UNSIGNED NOT NULL, tipo ENUM('PRODUCTO','SERVICIO') NOT NULL,
            referencia_id INT UNSIGNED NULL, concepto VARCHAR(255) NOT NULL, unidad VARCHAR(20) NULL, cantidad DECIMAL(12,3) NOT NULL DEFAULT 1, precio_unitario DECIMAL(12,2) NULL,
            KEY idx_trabajo (trabajo_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$listo = true;
    }

    /** Guarda una partida unificada de un presupuesto como trabajo tipo. */
    public static function guardar(int $presupuestoId, int $grupoId, array $yo): array
    {
        self::asegurar();
        $g = Db::fila('SELECT * FROM presupuesto_grupos WHERE id = ? AND presupuesto_id = ?', [$grupoId, $presupuestoId]);
        if (!$g) throw new ErrorApi('No existe esa partida unificada.', 404);
        if (Db::fila('SELECT id FROM trabajos_tipo WHERE titulo = ?', [$g['titulo']])) throw new ErrorApi("Ya existe un trabajo tipo llamado «{$g['titulo']}». Cambia el título de la partida o borra el otro.", 409);
        $ls = Db::filas('SELECT * FROM presupuesto_lineas WHERE grupo_id = ? ORDER BY id', [$grupoId]);
        if (count($ls) < 1) throw new ErrorApi('Esa partida no tiene líneas.', 409);
        $id = Db::transaccion(function () use ($g, $ls, $yo) {
            $id = Db::insertar('trabajos_tipo', ['titulo' => $g['titulo'], 'descripcion' => $g['descripcion'], 'usuario_id' => $yo['id'] ?? null]);
            foreach ($ls as $l) {
                Db::insertar('trabajos_tipo_lineas', ['trabajo_id' => $id, 'tipo' => $l['tipo'], 'referencia_id' => $l['referencia_id'], 'concepto' => mb_substr((string) $l['concepto'], 0, 255),
                    'unidad' => $l['unidad'], 'cantidad' => $l['cantidad'], 'precio_unitario' => $l['referencia_id'] ? null : $l['precio_unitario']]);
            }
            return $id;
        });
        Eventos::anotar($yo, 'trabajo_tipo_creado', 'trabajo_tipo', $id, ['titulo' => $g['titulo'], 'lineas' => count($ls)]);
        return self::uno($id);
    }

    /** Con sus componentes: lo que se pediría al catálogo hoy y su importe de hoy. */
    public static function uno(int $id): array
    {
        self::asegurar();
        $t = Db::fila('SELECT t.id, t.titulo, t.descripcion, t.created_at, u.nombre AS creado_por FROM trabajos_tipo t LEFT JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ?', [$id]);
        if (!$t) throw new ErrorApi('No existe ese trabajo tipo.', 404);
        $t['id'] = (int) $t['id'];
        $t['lineas'] = [];
        $t['importe'] = 0.0;
        foreach (Db::filas('SELECT * FROM trabajos_tipo_lineas WHERE trabajo_id = ? ORDER BY id', [$id]) as $l) {
            $cant = (float) $l['cantidad'];
            if ($l['referencia_id']) {
                $fuente = strtolower($l['tipo']);
                $item = Catalogo::obtener($fuente, (int) $l['referencia_id']);
                $importe = $item ? Motor::precios($item, $cant)['importe'] : 0.0;
                $entrada = ['fuente' => $fuente, 'id' => (int) $l['referencia_id'], 'cantidad' => $cant];
                $disponible = (bool) $item;
            } else {
                $importe = Motor::dinero($cant * (float) $l['precio_unitario']);
                $entrada = ['tipo' => $l['tipo'], 'concepto' => $l['concepto'], 'cantidad' => $cant, 'unidad' => $l['unidad'], 'precio_unitario' => (float) $l['precio_unitario']];
                $disponible = true;
            }
            $t['lineas'][] = ['id' => (int) $l['id'], 'tipo' => $l['tipo'], 'concepto' => $l['concepto'], 'cantidad' => $cant, 'unidad' => $l['unidad'], 'libre' => !$l['referencia_id'], 'precio_unitario' => $l['referencia_id'] ? null : (float) $l['precio_unitario'], 'disponible' => $disponible, 'importe' => $importe, 'entrada' => $entrada];
            if ($disponible) $t['importe'] += $importe;
        }
        $t['importe'] = Motor::dinero($t['importe']);
        return $t;
    }

    public static function listar(): array
    {
        self::asegurar();
        return array_map(fn($r) => self::uno((int) $r['id']), Db::filas('SELECT id FROM trabajos_tipo ORDER BY titulo'));
    }

    public static function actualizar(int $id, array $d, array $yo): array
    {
        $t = self::uno($id);
        $nuevo = [];
        if (array_key_exists('titulo', $d)) {
            $v = trim((string) $d['titulo']);
            if ($v === '' || mb_strlen($v) > 200) throw new ErrorApi('El título es obligatorio (hasta 200 caracteres).', 400);
            if (Db::fila('SELECT id FROM trabajos_tipo WHERE titulo = ? AND id <> ?', [$v, $id])) throw new ErrorApi("Ya existe un trabajo tipo llamado «{$v}».", 409);
            $nuevo['titulo'] = $v;
        }
        if (array_key_exists('descripcion', $d)) {
            $v = trim((string) $d['descripcion']);
            if (mb_strlen($v) > 1000) throw new ErrorApi('La descripción es demasiado larga (máximo 1000 caracteres).', 400);
            $nuevo['descripcion'] = $v;
        }
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar: manda titulo o descripcion.', 400);
        Db::actualizar('trabajos_tipo', $id, $nuevo);
        Eventos::anotar($yo, 'trabajo_tipo_modificado', 'trabajo_tipo', $id, ['titulo' => $nuevo['titulo'] ?? $t['titulo']]);
        return self::uno($id);
    }

    // ---------- componentes ----------

    private static function linea(int $trabajoId, int $lineaId): array
    {
        $l = Db::fila('SELECT * FROM trabajos_tipo_lineas WHERE id = ? AND trabajo_id = ?', [$lineaId, $trabajoId]);
        if (!$l) throw new ErrorApi('No existe ese componente en este trabajo tipo.', 404);
        return $l;
    }

    /** Cambia un componente: la cantidad, la unidad, el precio (si es una línea libre) o el producto/servicio elegido. */
    public static function cambiarLinea(int $trabajoId, int $lineaId, array $d, array $yo): array
    {
        $t = self::uno($trabajoId);
        $l = self::linea($trabajoId, $lineaId);
        $nuevo = [];
        if (array_key_exists('cantidad', $d)) {
            if (!is_numeric($d['cantidad']) || (float) $d['cantidad'] <= 0) throw new ErrorApi('La cantidad tiene que ser un número mayor que 0.', 400);
            $nuevo['cantidad'] = (float) $d['cantidad'];
        }
        if (array_key_exists('unidad', $d)) {
            $u = mb_substr(trim((string) $d['unidad']), 0, 20);
            if ($u === '') throw new ErrorApi('La unidad no puede estar vacía.', 400);
            $nuevo['unidad'] = $u;
        }
        if (array_key_exists('precio_unitario', $d)) {
            if ($l['referencia_id']) throw new ErrorApi('El precio de un producto o servicio del catálogo sale del catálogo. Cámbialo allí, o elige otro componente.', 409);
            if (!is_numeric($d['precio_unitario']) || (float) $d['precio_unitario'] < 0) throw new ErrorApi('El precio tiene que ser un número (0 o más).', 400);
            $nuevo['precio_unitario'] = Motor::dinero((float) $d['precio_unitario']);
        }
        if (isset($d['elegir'])) {
            $e = (array) $d['elegir'];
            $fuente = (string) ($e['fuente'] ?? '');
            if (!in_array($fuente, ['producto', 'servicio'], true) || strtoupper($fuente) !== $l['tipo']) throw new ErrorApi('Hay que elegir un ' . ($l['tipo'] === 'PRODUCTO' ? 'producto' : 'servicio') . ' del mismo tipo.', 400);
            $item = Catalogo::obtener($fuente, (int) ($e['id'] ?? 0));
            if (!$item) throw new ErrorApi('No existe ese elemento en el catálogo.', 404);
            $nuevo['referencia_id'] = (int) $item['id'];
            $nuevo['concepto'] = mb_substr((string) $item['nombre'], 0, 255);
            $nuevo['unidad'] = $nuevo['unidad'] ?? $item['unidad'];
            $nuevo['precio_unitario'] = null;
        }
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar: manda cantidad, unidad, precio_unitario o elegir.', 400);
        Db::actualizar('trabajos_tipo_lineas', $lineaId, $nuevo);
        Eventos::anotar($yo, 'trabajo_tipo_modificado', 'trabajo_tipo', $trabajoId, ['titulo' => $t['titulo'], 'componente' => $l['concepto']]);
        return self::uno($trabajoId);
    }

    /** Quita un componente (siempre tiene que quedar al menos uno). */
    public static function quitarLinea(int $trabajoId, int $lineaId, array $yo): array
    {
        $t = self::uno($trabajoId);
        $l = self::linea($trabajoId, $lineaId);
        if (count($t['lineas']) <= 1) throw new ErrorApi('Un trabajo tipo necesita al menos un componente. Si ya no lo quieres, bórralo entero.', 409);
        Db::ejecutar('DELETE FROM trabajos_tipo_lineas WHERE id = ?', [$lineaId]);
        Eventos::anotar($yo, 'trabajo_tipo_modificado', 'trabajo_tipo', $trabajoId, ['titulo' => $t['titulo'], 'quitado' => $l['concepto']]);
        return self::uno($trabajoId);
    }

    /** Añade un componente: un producto o servicio del catálogo ({fuente, id, cantidad?}) o una línea libre ({tipo, concepto, cantidad, unidad, precio_unitario}). */
    public static function anadirLinea(int $trabajoId, array $d, array $yo): array
    {
        $t = self::uno($trabajoId);
        $m = Presupuestos::lineaManual($d);   // valida igual que al añadir una línea a un presupuesto
        Db::insertar('trabajos_tipo_lineas', ['trabajo_id' => $trabajoId, 'tipo' => $m['tipo'], 'referencia_id' => $m['referencia_id'], 'concepto' => mb_substr((string) $m['concepto'], 0, 255),
            'unidad' => $m['unidad'], 'cantidad' => $m['cantidad'], 'precio_unitario' => $m['referencia_id'] ? null : $m['precio_unitario']]);
        Eventos::anotar($yo, 'trabajo_tipo_modificado', 'trabajo_tipo', $trabajoId, ['titulo' => $t['titulo'], 'anadido' => $m['concepto']]);
        return self::uno($trabajoId);
    }

    public static function eliminar(int $id, array $yo): void
    {
        $t = self::uno($id);
        Db::transaccion(function () use ($id) {
            Db::ejecutar('DELETE FROM trabajos_tipo_lineas WHERE trabajo_id = ?', [$id]);
            Db::ejecutar('DELETE FROM trabajos_tipo WHERE id = ?', [$id]);
        });
        Eventos::anotar($yo, 'trabajo_tipo_eliminado', 'trabajo_tipo', $id, ['titulo' => $t['titulo']]);
    }
}
