<?php
// SINÓNIMOS — la tabla `sinonimos`: cómo lo dice el cliente → cómo se llama en la base («paneles solares» → «módulo fotovoltaico»).
//   · La IA los usa para entender el pedido y el buscador del catálogo, para encontrar lo que se pide con otras palabras.
//   · Se editan a mano, o la IA PROPONE nuevos mirando lo que se corrige en los presupuestos (sugerir) y un administrador aprueba o rechaza.
//     Nunca se activan solos: un sinónimo malo afectaría a todos los presupuestos futuros.

final class Sinonimos
{
    private static ?array $filas = null;

    public static function todos(): array
    {
        return self::$filas ??= Db::filas('SELECT termino, concepto_canonico FROM sinonimos WHERE activo = 1');
    }

    /**
     * Las palabras equivalentes de lo que aparece en el texto, en los DOS sentidos (porque unos proveedores dicen «módulo» y otros «panel»):
     * si sale el término, se añade el concepto del catálogo; si sale el concepto, se añaden los términos. Solo palabras enteras (para que
     * «pia» no salte con «especial»), y los términos cortos (menos de 5 letras) no se añaden al revés para no traer ruido.
     */
    public static function expandir(string $texto): string
    {
        $esta = fn(string $frase) => (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote(trim($frase), '/') . '(?![\p{L}\p{N}])/iu', $texto);
        $extra = [];
        foreach (self::todos() as $s) {
            if ($esta($s['termino'])) $extra[] = $s['concepto_canonico'];
            elseif (mb_strlen(trim($s['termino'])) >= 5 && $esta($s['concepto_canonico'])) $extra[] = $s['termino'];
        }
        return implode(' ', array_unique($extra));
    }

    // ---------------------------------------------------------------- administración

    public static function listar(): array
    {
        $a = Db::filas('SELECT id, grupo, termino, concepto_canonico, activo FROM sinonimos ORDER BY grupo, termino');
        $s = Db::filas("SELECT id, grupo, termino, concepto_canonico, motivo, created_at FROM sinonimos_sugeridos WHERE estado = 'PENDIENTE' ORDER BY id DESC");
        return [
            'activos' => array_map(fn($f) => ['id' => (int) $f['id'], 'grupo' => $f['grupo'], 'termino' => $f['termino'], 'concepto_canonico' => $f['concepto_canonico'], 'activo' => (bool) $f['activo']], $a),
            'sugeridos' => array_map(fn($f) => ['id' => (int) $f['id']] + $f, $s),
        ];
    }

    public static function crear(array $d, array $yo): array
    {
        [$termino, $canonico, $grupo] = self::validar($d);
        if (Db::fila('SELECT id FROM sinonimos WHERE termino = ?', [$termino])) throw new ErrorApi("Ya existe un sinónimo para «{$termino}».", 409);
        $id = Db::insertar('sinonimos', ['grupo' => $grupo, 'termino' => $termino, 'concepto_canonico' => $canonico, 'activo' => 1]);
        self::$filas = null;
        Eventos::anotar($yo, 'sinonimo_creado', 'sinonimo', $id, ['termino' => $termino, 'concepto' => $canonico]);
        return self::uno($id);
    }

    public static function actualizar(int $id, array $d, array $yo): array
    {
        $a = self::uno($id);
        $nuevo = [];
        if (isset($d['termino']) || isset($d['concepto_canonico']) || array_key_exists('grupo', $d)) {
            [$t, $c, $g] = self::validar($d + ['termino' => $a['termino'], 'concepto_canonico' => $a['concepto_canonico'], 'grupo' => $a['grupo']]);
            if ($t !== $a['termino'] && Db::fila('SELECT id FROM sinonimos WHERE termino = ? AND id <> ?', [$t, $id])) throw new ErrorApi("Ya existe un sinónimo para «{$t}».", 409);
            $nuevo += ['termino' => $t, 'concepto_canonico' => $c, 'grupo' => $g];
        }
        if (isset($d['activo'])) $nuevo['activo'] = $d['activo'] ? 1 : 0;
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar.', 400);
        Db::actualizar('sinonimos', $id, $nuevo);
        self::$filas = null;
        Eventos::anotar($yo, 'sinonimo_modificado', 'sinonimo', $id, ['termino' => $nuevo['termino'] ?? $a['termino']]);
        return self::uno($id);
    }

    public static function eliminar(int $id, array $yo): void
    {
        $a = self::uno($id);
        Db::ejecutar('DELETE FROM sinonimos WHERE id = ?', [$id]);
        self::$filas = null;
        Eventos::anotar($yo, 'sinonimo_eliminado', 'sinonimo', $id, ['termino' => $a['termino']]);
    }

    // ---------------------------------------------------------------- la IA propone

    /** Pide a la IA sinónimos nuevos a partir de lo que se ha corregido. Los guarda como PENDIENTES; no activa ninguno. */
    public static function sugerir(array $yo): array
    {
        $cambios = [];
        foreach (Db::filas("SELECT valor_anterior a, valor_nuevo n FROM correcciones WHERE tipo = 'linea_elemento' AND ignorar = 0 ORDER BY id DESC LIMIT 40") as $c) {
            $a = json_decode((string) $c['a'], true);
            $n = json_decode((string) $c['n'], true);
            if (is_array($a) && is_array($n)) $cambios[] = '- pedido/IA: «' . mb_substr((string) ($a['concepto'] ?? ''), 0, 90) . '» → técnico: «' . mb_substr((string) ($n['concepto'] ?? ''), 0, 90) . '»';
        }
        $noEncontrados = array_unique(array_column(Db::filas("SELECT concepto FROM presupuesto_lineas WHERE estado LIKE '%NO_ENCONTRADO' ORDER BY id DESC LIMIT 40"), 'concepto'));
        $correcciones = array_filter(array_column(Db::filas("SELECT comentario FROM correcciones WHERE tipo = 'interpretacion' AND ignorar = 0 ORDER BY id DESC LIMIT 30"), 'comentario'));
        if (!$cambios && !$noEncontrados && !$correcciones) throw new ErrorApi('Todavía no hay correcciones de las que aprender: usa el sistema unos días y vuelve a probar.', 409);

        $existen = array_map(fn($s) => $s['termino'] . ' = ' . $s['concepto_canonico'], Db::filas('SELECT termino, concepto_canonico FROM sinonimos'));
        $usuario = "CAMBIOS:\n" . ($cambios ? implode("\n", $cambios) : '(ninguno)')
            . "\n\nNO ENCONTRADOS:\n" . ($noEncontrados ? '- ' . implode("\n- ", array_map(fn($x) => mb_substr((string) $x, 0, 100), $noEncontrados)) : '(ninguno)')
            . "\n\nCORRECCIONES:\n" . ($correcciones ? '- ' . implode("\n- ", array_map(fn($x) => mb_substr((string) $x, 0, 160), $correcciones)) : '(ninguna)')
            . "\n\nYA EXISTEN:\n" . ($existen ? implode("\n", $existen) : '(ninguno)');

        $j = Claude::json(Claude::preguntar('sinonimos', (string) file_get_contents(RAIZ . '/prompts/sinonimos.txt'), $usuario)['texto']);
        if ($j === null) throw new ErrorApi('La IA no ha devuelto sugerencias válidas. Inténtalo otra vez.', 502);

        $nuevas = 0;
        foreach ((array) ($j['sugerencias'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $t = mb_strtolower(trim((string) ($s['termino'] ?? '')));
            $c = trim((string) ($s['concepto_canonico'] ?? ''));
            if (mb_strlen($t) < 2 || mb_strlen($t) > 255 || mb_strlen($c) < 2 || mb_strlen($c) > 255 || $t === mb_strtolower($c)) continue;
            if (Db::fila('SELECT id FROM sinonimos WHERE termino = ?', [$t]) || Db::fila('SELECT id FROM sinonimos_sugeridos WHERE termino = ? AND concepto_canonico = ?', [$t, $c])) continue;
            Db::insertar('sinonimos_sugeridos', ['grupo' => mb_substr(trim((string) ($s['grupo'] ?? '')), 0, 100) ?: null, 'termino' => $t, 'concepto_canonico' => $c, 'motivo' => mb_substr(trim((string) ($s['motivo'] ?? '')), 0, 500) ?: null]);
            $nuevas++;
        }
        Eventos::anotar($yo, 'sinonimos_sugeridos', 'sinonimo', null, ['nuevas' => $nuevas]);
        return ['nuevas' => $nuevas] + self::listar();
    }

    /** Aprueba una sugerencia (con el texto que el administrador haya corregido) y la pasa a sinónimos activos. */
    public static function aprobar(int $id, array $d, array $yo): array
    {
        $s = Db::fila("SELECT * FROM sinonimos_sugeridos WHERE id = ? AND estado = 'PENDIENTE'", [$id]);
        if (!$s) throw new ErrorApi('Esa sugerencia ya no está pendiente.', 404);
        $nuevo = self::crear(['termino' => $d['termino'] ?? $s['termino'], 'concepto_canonico' => $d['concepto_canonico'] ?? $s['concepto_canonico'], 'grupo' => $d['grupo'] ?? $s['grupo']], $yo);
        Db::actualizar('sinonimos_sugeridos', $id, ['estado' => 'APROBADA']);
        return $nuevo;
    }

    public static function rechazar(int $id): void
    {
        if (!Db::ejecutar("UPDATE sinonimos_sugeridos SET estado = 'RECHAZADA' WHERE id = ? AND estado = 'PENDIENTE'", [$id])) throw new ErrorApi('Esa sugerencia ya no está pendiente.', 404);
    }

    // ---------------------------------------------------------------- interno

    private static function uno(int $id): array
    {
        $f = Db::fila('SELECT id, grupo, termino, concepto_canonico, activo FROM sinonimos WHERE id = ?', [$id]);
        if (!$f) throw new ErrorApi('No existe ese sinónimo.', 404);
        return ['id' => (int) $f['id'], 'grupo' => $f['grupo'], 'termino' => $f['termino'], 'concepto_canonico' => $f['concepto_canonico'], 'activo' => (bool) $f['activo']];
    }

    /** @return array{0:string,1:string,2:?string} término (minúsculas), concepto y grupo, ya validados */
    private static function validar(array $d): array
    {
        $t = mb_strtolower(trim((string) ($d['termino'] ?? '')));
        $c = trim((string) ($d['concepto_canonico'] ?? ''));
        if (mb_strlen($t) < 2 || mb_strlen($t) > 255) throw new ErrorApi('«Cómo lo dicen» tiene que tener entre 2 y 255 caracteres.', 400);
        if (mb_strlen($c) < 2 || mb_strlen($c) > 255) throw new ErrorApi('«Cómo se llama en el catálogo» tiene que tener entre 2 y 255 caracteres.', 400);
        if ($t === mb_strtolower($c)) throw new ErrorApi('Los dos textos son iguales: no hace falta un sinónimo.', 400);
        $g = trim((string) ($d['grupo'] ?? ''));
        return [$t, $c, $g === '' ? null : mb_substr($g, 0, 100)];
    }
}
