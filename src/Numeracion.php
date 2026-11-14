<?php
// NUMERACIÓN DE PRESUPUESTOS — PRE26-2000, PRE26-2001, PRE26-2002…
//   «PRE» identifica el documento, «26» es el año de emisión (dos cifras) y el número es CORRELATIVO: empieza en 2000 y sigue sumando de uno en uno.
//   Al cambiar de año solo cambia el prefijo (PRE27-…): el número NO se reinicia, así que nunca hay dos presupuestos con el mismo número.
//   Un número usado no se reutiliza aunque se borre el presupuesto. Los presupuestos anteriores (PRES-2026-0273…) conservan su número.
// El contador se guarda en la tabla `configuracion` y se reparte con un bloqueo para que dos personas creando a la vez nunca reciban el mismo.

final class Numeracion
{
    public const PRIMERO = 2000;
    private const CLAVE = 'presupuesto_correlativo';

    private static bool $lista = false;
    private static function asegurar(): void
    {
        if (self::$lista) return;
        if (!Db::fila("SHOW COLUMNS FROM presupuestos LIKE 'correlativo'")) {
            Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN correlativo INT UNSIGNED NULL');
        }
        self::$lista = true;
    }

    /** El texto del número: PRE + año de dos cifras + guion + correlativo. */
    public static function formato(int $correlativo, ?int $anio = null): string
    {
        return sprintf('PRE%02d-%d', (($anio ?? (int) date('Y')) % 100), $correlativo);
    }

    /** El último número repartido (el mayor entre el contador guardado y los presupuestos que ya lo tienen). */
    public static function ultimo(): int
    {
        self::asegurar();
        $guardado = (int) (Db::fila('SELECT valor FROM configuracion WHERE clave = ?', [self::CLAVE])['valor'] ?? 0);
        $maximo = (int) (Db::fila('SELECT COALESCE(MAX(correlativo), 0) m FROM presupuestos')['m'] ?? 0);
        return max($guardado, $maximo, self::PRIMERO - 1);
    }

    private static function guardar(int $n): void
    {
        Db::ejecutar('INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [self::CLAVE, (string) $n, 'Último número correlativo de presupuesto repartido (PRE26-2000, 2001…)']);
    }

    /** Reparte el siguiente número al presupuesto y lo deja anotado en él. */
    public static function asignar(int $presupuestoId): string
    {
        self::asegurar();
        $bloqueo = (int) (Db::fila("SELECT GET_LOCK('presupuestos_numero', 10) l")['l'] ?? 0);
        if ($bloqueo !== 1) throw new ErrorApi('No se ha podido numerar el presupuesto: había mucha gente creando a la vez. Inténtalo otra vez.', 503);
        try {
            $n = self::ultimo() + 1;
            $numero = self::formato($n);
            self::guardar($n);
            Db::actualizar('presupuestos', $presupuestoId, ['numero' => $numero, 'correlativo' => $n]);
            return $numero;
        } finally {
            Db::fila("SELECT RELEASE_LOCK('presupuestos_numero') l");
        }
    }

    /** Para las pruebas: deja el contador como estaba (sin bajar nunca de lo que ya tienen los presupuestos reales). */
    public static function restaurar(int $antes): void
    {
        self::asegurar();
        $maximo = (int) (Db::fila('SELECT COALESCE(MAX(correlativo), 0) m FROM presupuestos')['m'] ?? 0);
        self::guardar(max($antes, $maximo, self::PRIMERO - 1));
    }
}
