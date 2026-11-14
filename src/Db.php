<?php
// DB — conexiones PDO. Siempre consultas preparadas (los «?»).
//   «bd»       → la base de los presupuestos, usuarios, reglas, notas… (la de siempre)
//   «catalogo» → la base de la tabla `productos`. Si en config.php no hay bloque «catalogo», es la MISMA conexión que «bd» (todo en una base).
// Con el catálogo en otra base de datos (y otro usuario de MySQL) las consultas a `productos` van por Db::cFilas / cFila / cEjecutar / cInsertar / cActualizar.

final class Db
{
    /** @var array<string, PDO> */
    private static array $conexiones = [];

    /** ¿Está el catálogo de productos en otra base de datos? (hay un bloque «catalogo» con su nombre en config.php) */
    public static function separado(): bool
    {
        $c = Config::get('catalogo');
        return is_array($c) && !empty($c['nombre']);
    }

    /** Qué conexión se usa de verdad: el catálogo, si no está separado, va por la de siempre. */
    private static function clave(string $cual): string
    {
        return $cual === 'catalogo' && self::separado() ? 'catalogo' : 'bd';
    }

    private static function datos(string $clave): array
    {
        $c = (array) Config::get($clave);
        if ($clave === 'catalogo') $c += ['host' => Config::get('bd.host'), 'puerto' => Config::get('bd.puerto', 3306)];   // el servidor es el mismo si no se dice otro
        return $c;
    }

    public static function pdo(string $cual = 'bd'): PDO
    {
        $k = self::clave($cual);
        if (!isset(self::$conexiones[$k])) {
            $c = self::datos($k);
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['puerto'] ?? 3306, $c['nombre']);
            try {
                // Un corte puntual al conectar (2002/2006/2013) se reintenta un par de veces antes de dar error
                for ($intento = 1; ; $intento++) {
                    try {
                        $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]);
                        // La misma collation que las tablas (si no, en Hostinger sale «Illegal mix of collations»)
                        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
                        self::$conexiones[$k] = $pdo;
                        break;
                    } catch (PDOException $e) {
                        if ($intento >= 3 || !in_array((int) ($e->errorInfo[1] ?? 0), [2002, 2006, 2013], true)) throw $e;
                        usleep(400_000 * $intento);
                    }
                }
            } catch (PDOException $e) {
                // El motivo real (sin contraseña) va al registro del servidor: ahí se ve si es usuario, base, host o la collation.
                error_log(sprintf('[presupuestos] MySQL «%s» (host=%s, base=%s, usuario=%s): %s', $k, $c['host'], $c['nombre'], $c['usuario'], $e->getMessage()));
                throw new RuntimeException('No se ha podido conectar con MySQL: revisa «' . $k . '» en config/config.php. (' . $e->getCode() . ')');
            }
        }
        return self::$conexiones[$k];
    }

    /** Ejecuta una consulta; si el servidor ha cerrado la conexión por inactividad (pasa durante las llamadas largas a la IA), reconecta y repite una vez. */
    private static function consulta(string $sql, array $p, string $cual = 'bd'): PDOStatement
    {
        for ($i = 0; ; $i++) {
            try {
                $s = self::pdo($cual)->prepare($sql);
                $s->execute($p);
                return $s;
            } catch (PDOException $e) {
                if ($i === 0 && !self::$enTransaccion && in_array((int) ($e->errorInfo[1] ?? 0), [2006, 2013], true)) { unset(self::$conexiones[self::clave($cual)]); continue; }
                throw $e;
            }
        }
    }

    private static bool $enTransaccion = false;

    public static function transaccion(callable $fn): mixed
    {
        self::consulta('SELECT 1', []);   // si la conexión se ha cerrado por inactividad, se rehace aquí y no a mitad de la transacción
        $pdo = self::pdo();
        $pdo->beginTransaction();
        self::$enTransaccion = true;
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            self::$enTransaccion = false;
        }
    }

    public static function filas(string $sql, array $p = []): array
    {
        return self::consulta($sql, $p)->fetchAll();
    }

    public static function fila(string $sql, array $p = []): ?array
    {
        return self::filas($sql, $p)[0] ?? null;
    }

    public static function ejecutar(string $sql, array $p = []): int
    {
        return self::consulta($sql, $p)->rowCount();
    }

    private static function insertarEn(string $cual, string $tabla, array $datos): int
    {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($datos)));
        self::consulta("INSERT INTO `$tabla` ($cols) VALUES (" . implode(',', array_fill(0, count($datos), '?')) . ')', array_values($datos), $cual);
        return (int) self::pdo($cual)->lastInsertId();
    }

    private static function actualizarEn(string $cual, string $tabla, int $id, array $datos): void
    {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($datos)));
        self::consulta("UPDATE `$tabla` SET $set WHERE id=?", [...array_values($datos), $id], $cual);
    }

    public static function insertar(string $tabla, array $datos): int
    {
        return self::insertarEn('bd', $tabla, $datos);
    }

    public static function actualizar(string $tabla, int $id, array $datos): void
    {
        self::actualizarEn('bd', $tabla, $id, $datos);
    }

    // ---- La base del catálogo de productos (la misma conexión que «bd» si no está separada) ----

    public static function cFilas(string $sql, array $p = []): array
    {
        return self::consulta($sql, $p, 'catalogo')->fetchAll();
    }

    public static function cFila(string $sql, array $p = []): ?array
    {
        return self::cFilas($sql, $p)[0] ?? null;
    }

    public static function cEjecutar(string $sql, array $p = []): int
    {
        return self::consulta($sql, $p, 'catalogo')->rowCount();
    }

    public static function cActualizar(string $tabla, int $id, array $datos): void
    {
        self::actualizarEn('catalogo', $tabla, $id, $datos);
    }
}
