<?php
// NOTAS Y CONDICIONES — los textos que fijan el alcance del trabajo, lo que no incluye y las condiciones del servicio, y que salen en el PDF.
//   · BIBLIOTECA (tabla `notas`): textos guardados para reutilizar. Cada uno es «automático» (se añade solo a los presupuestos de esas áreas) o
//     «manual» (se ofrece para añadirlo con un clic). Se administra en Admin › Notas.
//   · NOTAS DE UN PRESUPUESTO (tabla `presupuesto_notas`): una COPIA del texto. Así, cambiar la biblioteca después no altera un presupuesto ya enviado.
//     Se pueden editar, quitar y añadir notas libres antes de entregarlo.
// Las tablas se crean solas si faltan (basta con subir el código).

final class Notas
{
    public const MODOS = ['manual', 'auto'];

    private static bool $lista = false;
    public static function asegurar(): void
    {
        if (self::$lista) return;
        Db::ejecutar('CREATE TABLE IF NOT EXISTS notas (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(150) NOT NULL, texto TEXT NOT NULL,
            modo VARCHAR(10) NOT NULL DEFAULT \'manual\', areas VARCHAR(80) NULL, activa TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        Db::ejecutar('CREATE TABLE IF NOT EXISTS presupuesto_notas (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, presupuesto_id INT UNSIGNED NOT NULL, nota_id INT UNSIGNED NULL,
            texto TEXT NOT NULL, origen VARCHAR(12) NOT NULL DEFAULT \'libre\', orden INT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_presupuesto (presupuesto_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!Db::fila("SHOW COLUMNS FROM presupuestos LIKE 'notas_auto'")) Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN notas_auto TINYINT(1) NOT NULL DEFAULT 0');
        self::$lista = true;
        // La primera vez, unos ejemplos (manuales, para que no salga nada en un PDF sin que alguien lo elija) que se pueden editar o borrar
        if ((int) (Db::fila('SELECT COUNT(*) n FROM notas')['n'] ?? 0) === 0) {
            $i = 0;
            foreach ([
                ['Trabajos no incluidos', 'Cualquier trabajo no indicado expresamente en este presupuesto no está incluido y se presupuestará aparte.'],
                ['Albañilería y acabados', 'No se incluyen trabajos de albañilería (rozas, apertura de huecos, remates) ni de pintura o acabados, salvo que se indique.'],
                ['Cambios sobre lo presupuestado', 'Si durante la ejecución se detectan circunstancias que no se podían conocer antes (estado de la instalación existente, accesos…), se comunicará al cliente antes de continuar.'],
                ['Plazo de ejecución', 'El plazo de ejecución se acordará con el cliente una vez aceptado el presupuesto, según la disponibilidad de material y de agenda.'],
            ] as [$t, $x]) Db::insertar('notas', ['titulo' => $t, 'texto' => $x, 'modo' => 'manual', 'areas' => null, 'activa' => 1, 'orden' => $i++]);
        }
    }

    // ---------- Biblioteca ----------

    private static function areasTexto(mixed $a): ?string
    {
        $l = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim((string) $x)), is_array($a) ? $a : explode(',', (string) $a)), fn($x) => in_array($x, Interprete::AREAS, true))));
        return $l ? implode(',', $l) : null;
    }

    private static function fila(array $f): array
    {
        return ['id' => (int) $f['id'], 'titulo' => (string) $f['titulo'], 'texto' => (string) $f['texto'], 'modo' => (string) $f['modo'],
            'areas' => $f['areas'] ? explode(',', (string) $f['areas']) : [], 'activa' => (bool) $f['activa']];
    }

    /** La biblioteca. Con $soloActivas solo las que se pueden usar. */
    public static function listar(bool $soloActivas = false): array
    {
        self::asegurar();
        return array_map([self::class, 'fila'], Db::filas('SELECT * FROM notas' . ($soloActivas ? ' WHERE activa = 1' : '') . ' ORDER BY orden, id'));
    }

    private static function datos(array $d, ?array $actual = null): array
    {
        $titulo = mb_substr(trim((string) ($d['titulo'] ?? $actual['titulo'] ?? '')), 0, 150);
        $texto = mb_substr(trim((string) ($d['texto'] ?? $actual['texto'] ?? '')), 0, 2000);
        if ($titulo === '') throw new ErrorApi('Ponle un título corto a la nota (por ejemplo «Trabajos no incluidos»).', 400);
        if ($texto === '') throw new ErrorApi('Escribe el texto de la nota.', 400);
        $modo = (string) ($d['modo'] ?? $actual['modo'] ?? 'manual');
        if (!in_array($modo, self::MODOS, true)) throw new ErrorApi('El modo de la nota tiene que ser «automática» o «manual».', 400);
        $areas = array_key_exists('areas', $d) ? self::areasTexto($d['areas']) : ($actual ? ($actual['areas'] ? implode(',', $actual['areas']) : null) : null);
        return ['titulo' => $titulo, 'texto' => $texto, 'modo' => $modo, 'areas' => $areas, 'activa' => array_key_exists('activa', $d) ? (int) (bool) $d['activa'] : (int) ($actual['activa'] ?? 1)];
    }

    public static function crear(array $d, array $yo): array
    {
        self::asegurar();
        $x = self::datos($d);
        $x['orden'] = (int) (Db::fila('SELECT COALESCE(MAX(orden), 0) + 1 n FROM notas')['n'] ?? 1);
        $id = Db::insertar('notas', $x);
        Eventos::anotar($yo, 'nota_creada', 'nota', $id, ['titulo' => $x['titulo']]);
        return self::una($id);
    }

    public static function una(int $id): array
    {
        self::asegurar();
        $f = Db::fila('SELECT * FROM notas WHERE id = ?', [$id]);
        if (!$f) throw new ErrorApi('No existe esa nota.', 404);
        return self::fila($f);
    }

    public static function actualizar(int $id, array $d, array $yo): array
    {
        $a = self::una($id);
        $x = self::datos($d, $a);
        Db::actualizar('notas', $id, $x);
        Eventos::anotar($yo, 'nota_modificada', 'nota', $id, ['titulo' => $x['titulo']]);
        return self::una($id);
    }

    public static function eliminar(int $id, array $yo): array
    {
        $a = self::una($id);
        Db::ejecutar('DELETE FROM notas WHERE id = ?', [$id]);   // los presupuestos que ya la tienen conservan su copia
        Eventos::anotar($yo, 'nota_eliminada', 'nota', $id, ['titulo' => $a['titulo']]);
        return ['id' => $id];
    }

    // ---------- Notas de un presupuesto ----------

    public static function de(int $presupuestoId): array
    {
        self::asegurar();
        return array_map(fn($f) => ['id' => (int) $f['id'], 'nota_id' => $f['nota_id'] === null ? null : (int) $f['nota_id'], 'texto' => (string) $f['texto'], 'origen' => (string) $f['origen']],
            Db::filas('SELECT * FROM presupuesto_notas WHERE presupuesto_id = ? ORDER BY orden, id', [$presupuestoId]));
    }

    private static function siguienteOrden(int $presupuestoId): int
    {
        return (int) (Db::fila('SELECT COALESCE(MAX(orden), 0) + 1 n FROM presupuesto_notas WHERE presupuesto_id = ?', [$presupuestoId])['n'] ?? 1);
    }

    private static function textoLibre(mixed $t): string
    {
        $t = mb_substr(trim((string) $t), 0, 2000);
        if ($t === '') throw new ErrorApi('Escribe el texto de la nota.', 400);
        return $t;
    }

    /** Añade una nota: de la biblioteca (nota_id) o libre (texto). Una de la biblioteca no se repite. */
    public static function anadir(int $presupuestoId, array $d): array
    {
        self::asegurar();
        if (!empty($d['nota_id'])) {
            $n = self::una((int) $d['nota_id']);
            if (Db::fila('SELECT id FROM presupuesto_notas WHERE presupuesto_id = ? AND nota_id = ?', [$presupuestoId, $n['id']])) throw new ErrorApi('Esa nota ya está en el presupuesto.', 409);
            Db::insertar('presupuesto_notas', ['presupuesto_id' => $presupuestoId, 'nota_id' => $n['id'], 'texto' => $n['texto'], 'origen' => 'biblioteca', 'orden' => self::siguienteOrden($presupuestoId)]);
        } else {
            Db::insertar('presupuesto_notas', ['presupuesto_id' => $presupuestoId, 'nota_id' => null, 'texto' => self::textoLibre($d['texto'] ?? ''), 'origen' => 'libre', 'orden' => self::siguienteOrden($presupuestoId)]);
        }
        return self::de($presupuestoId);
    }

    private static function deEste(int $presupuestoId, int $notaPresId): array
    {
        $f = Db::fila('SELECT * FROM presupuesto_notas WHERE id = ? AND presupuesto_id = ?', [$notaPresId, $presupuestoId]);
        if (!$f) throw new ErrorApi('Esa nota no está en este presupuesto.', 404);
        return $f;
    }

    public static function cambiar(int $presupuestoId, int $notaPresId, array $d): array
    {
        self::asegurar();
        self::deEste($presupuestoId, $notaPresId);
        Db::actualizar('presupuesto_notas', $notaPresId, ['texto' => self::textoLibre($d['texto'] ?? '')]);   // solo cambia ESTE presupuesto; la biblioteca queda igual
        return self::de($presupuestoId);
    }

    public static function quitar(int $presupuestoId, int $notaPresId): array
    {
        self::asegurar();
        self::deEste($presupuestoId, $notaPresId);
        Db::ejecutar('DELETE FROM presupuesto_notas WHERE id = ?', [$notaPresId]);
        return self::de($presupuestoId);
    }

    /** Las áreas del presupuesto: las de sus requerimientos y la de su grupo principal (para elegir qué notas automáticas le tocan). */
    public static function areasDe(int $presupuestoId): array
    {
        $p = Db::fila('SELECT grupo_principal FROM presupuestos WHERE id = ?', [$presupuestoId]);
        $i = Db::fila('SELECT json_interpretacion FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$presupuestoId]);
        $j = $i ? (json_decode((string) $i['json_interpretacion'], true) ?: []) : [];
        $grupo = (string) ($p['grupo_principal'] ?? '');
        $areas = [Interprete::area(null, $grupo)];
        foreach ((array) ($j['requerimientos'] ?? []) as $r) $areas[] = Interprete::area($r['area'] ?? null, $grupo);
        return array_values(array_unique($areas));
    }

    /**
     * Añade las notas automáticas que le tocan al presupuesto (las de sus áreas, o las de «todas las áreas»). Se hace UNA sola vez, al calcular por primera vez:
     * si después alguien quita una, no vuelve a aparecer al recalcular.
     */
    public static function aplicarAutomaticas(int $presupuestoId): void
    {
        self::asegurar();
        $p = Db::fila('SELECT notas_auto FROM presupuestos WHERE id = ?', [$presupuestoId]);
        if (!$p || (int) $p['notas_auto'] === 1) return;
        $areas = self::areasDe($presupuestoId);
        foreach (self::listar(true) as $n) {
            if ($n['modo'] !== 'auto') continue;
            if ($n['areas'] && !array_intersect($n['areas'], $areas)) continue;
            if (Db::fila('SELECT id FROM presupuesto_notas WHERE presupuesto_id = ? AND nota_id = ?', [$presupuestoId, $n['id']])) continue;
            Db::insertar('presupuesto_notas', ['presupuesto_id' => $presupuestoId, 'nota_id' => $n['id'], 'texto' => $n['texto'], 'origen' => 'auto', 'orden' => self::siguienteOrden($presupuestoId)]);
        }
        Db::actualizar('presupuestos', $presupuestoId, ['notas_auto' => 1]);
    }

    public static function borrarDe(int $presupuestoId): void
    {
        self::asegurar();
        Db::ejecutar('DELETE FROM presupuesto_notas WHERE presupuesto_id = ?', [$presupuestoId]);
    }
}
