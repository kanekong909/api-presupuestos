<?php
// CLIENTES — la base de clientes del sistema «Servicios Clim» (la aplicación de las órdenes de trabajo y la agenda), SOLO PARA LEER.
// Sirve para buscar un cliente al hacer un presupuesto y traer su nombre, teléfono y email sin escribirlos otra vez.
// Se conecta con una segunda conexión a esa base de datos (config.php › clientes). Si no está configurada, la función simplemente no aparece.
// Nunca se escribe en esa base: un presupuesto guarda su propia copia de los datos del cliente.

final class Clientes
{
    private static ?PDO $pdo = null;
    private static bool $forzada = false;   // solo las pruebas: una conexión puesta a mano
    private static ?array $columnasCache = null;

    /** Los datos completos que guarda un presupuesto además del nombre, el teléfono y el email. */
    public const CAMPOS = ['dni', 'calle', 'piso', 'codigo_postal', 'ciudad', 'provincia'];

    private const PROVINCIAS = ['01' => 'Álava', '02' => 'Albacete', '03' => 'Alicante', '04' => 'Almería', '05' => 'Ávila', '06' => 'Badajoz', '07' => 'Illes Balears', '08' => 'Barcelona', '09' => 'Burgos',
        '10' => 'Cáceres', '11' => 'Cádiz', '12' => 'Castellón', '13' => 'Ciudad Real', '14' => 'Córdoba', '15' => 'A Coruña', '16' => 'Cuenca', '17' => 'Girona', '18' => 'Granada', '19' => 'Guadalajara',
        '20' => 'Gipuzkoa', '21' => 'Huelva', '22' => 'Huesca', '23' => 'Jaén', '24' => 'León', '25' => 'Lleida', '26' => 'La Rioja', '27' => 'Lugo', '28' => 'Madrid', '29' => 'Málaga', '30' => 'Murcia',
        '31' => 'Navarra', '32' => 'Ourense', '33' => 'Asturias', '34' => 'Palencia', '35' => 'Las Palmas', '36' => 'Pontevedra', '37' => 'Salamanca', '38' => 'Santa Cruz de Tenerife', '39' => 'Cantabria',
        '40' => 'Segovia', '41' => 'Sevilla', '42' => 'Soria', '43' => 'Tarragona', '44' => 'Teruel', '45' => 'Toledo', '46' => 'Valencia', '47' => 'Valladolid', '48' => 'Bizkaia', '49' => 'Zamora',
        '50' => 'Zaragoza', '51' => 'Ceuta', '52' => 'Melilla'];

    /** La provincia que corresponde a un código postal español (los dos primeros números); vacío si no es uno. */
    public static function provinciaDe(string $cp): string
    {
        return preg_match('/^(\d{2})\d{3}$/', trim($cp), $m) ? (self::PROVINCIAS[$m[1]] ?? '') : '';
    }

    /**
     * Limpia los datos del cliente que llegan del formulario (solo los que vienen): recorta, el DNI/NIE/NIF en mayúsculas y sin espacios ni guiones.
     * Si falta la provincia y el código postal es español, se pone la provincia sola.
     * @return array<string, ?string>
     */
    public static function limpiarDatos(array $d): array
    {
        $out = [];
        foreach (self::CAMPOS as $k) {
            if (!array_key_exists($k, $d)) continue;
            $v = trim((string) $d[$k]);
            if ($k === 'dni') {
                $v = strtoupper(preg_replace('/[\s.\-]+/', '', $v) ?? '');
                if ($v !== '' && !preg_match('/^[A-Z0-9]{7,12}$/', $v)) throw new ErrorApi('El DNI/NIE/NIF no parece válido: escríbelo sin espacios (por ejemplo 12345678Z).', 400);
            }
            if ($k === 'codigo_postal' && $v !== '' && !preg_match('/^[0-9A-Za-z -]{3,10}$/', $v)) throw new ErrorApi('El código postal no parece válido.', 400);
            $out[$k] = $v === '' ? null : mb_substr($v, 0, $k === 'calle' ? 255 : 100);
        }
        if (($out['codigo_postal'] ?? '') !== '' && !array_key_exists('provincia', $d)) {
            $prov = self::provinciaDe((string) $out['codigo_postal']);
            if ($prov !== '') $out['provincia'] = $prov;
        }
        return $out;
    }

    /** Las columnas de la tabla de clientes de Servicios Clim (así un cliente se busca aunque falte alguna). */
    private static function columnas(): array
    {
        if (self::$columnasCache !== null) return self::$columnasCache;
        try {
            return self::$columnasCache = array_column(self::pdo()->query('SHOW COLUMNS FROM `' . self::tabla() . '`')->fetchAll(), 'Field');
        } catch (Throwable $e) {
            return self::$columnasCache = ['calle', 'piso_puerta', 'codigo_postal', 'nit_rfc_rut'];   // si no se pueden leer las columnas, se supone que están todas
        }
    }

    /** Para las pruebas: usa esta conexión (por ejemplo una base en memoria) en lugar de la de config. */
    public static function usarConexion(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$forzada = $pdo !== null;
        self::$columnasCache = null;
    }

    public static function conexion(): PDO
    {
        return self::pdo();
    }

    public static function tablaClientes(): string
    {
        return self::tabla();
    }

    public static function disponible(): bool
    {
        if (self::$forzada) return true;
        $c = Config::get('clientes', []);
        return is_array($c) && !empty($c['base']) && !empty($c['usuario']) && !empty($c['clave']) && !str_starts_with((string) $c['clave'], 'PON_AQUI');
    }

    private static function tabla(): string
    {
        $t = (string) (Config::get('clientes', [])['tabla'] ?? 'clientes');
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $t) ? $t : 'clientes';
    }

    private static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = Config::get('clientes');
        try {
            return self::$pdo = new PDO('mysql:host=' . ($c['host'] ?? 'localhost') . ';dbname=' . $c['base'] . ';charset=utf8mb4', (string) $c['usuario'], (string) $c['clave'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 6]);
        } catch (Throwable $e) {
            error_log('[presupuestos] No se ha podido conectar con la base de clientes (' . ($c['base'] ?? '?') . '): ' . $e->getMessage());
            throw new ErrorApi('No se ha podido consultar la base de clientes de Servicios Clim. Inténtalo otra vez en un momento.', 502);
        }
    }

    /** Las palabras de lo escrito (se piden TODAS, en cualquier orden). */
    public static function palabras(string $q): array
    {
        return array_slice(array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), fn($w) => mb_strlen($w) >= 1)), 0, 5);
    }

    /**
     * La condición SQL de la búsqueda: cada palabra tiene que estar en el nombre, el teléfono, el email o la dirección. Los números de teléfono se
     * comparan sin espacios ni guiones («612 345 678» encuentra «+34612345678»).
     * @return array{0:string, 1:array}
     */
    public static function condicion(array $palabras): array
    {
        $sql = '';
        $args = [];
        foreach ($palabras as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $digitos = preg_replace('/\D+/', '', $w);
            $parte = "(nombre LIKE ? OR COALESCE(email, '') LIKE ? OR COALESCE(direccion, '') LIKE ? OR COALESCE(ciudad, '') LIKE ?";
            array_push($args, $like, $like, $like, $like);
            if (strlen($digitos) >= 3) {
                $parte .= " OR REPLACE(REPLACE(REPLACE(COALESCE(telefono, ''), ' ', ''), '-', ''), '+', '') LIKE ?";
                $args[] = '%' . $digitos . '%';
            } else {
                $parte .= " OR COALESCE(telefono, '') LIKE ?";
                $args[] = $like;
            }
            $sql .= ($sql === '' ? '' : ' AND ') . $parte . ')';
        }
        return [$sql, $args];
    }

    /** Hasta $limite clientes que encajan con lo escrito. */
    public static function buscar(string $q, int $limite = 12): array
    {
        $palabras = self::palabras($q);
        if (!self::disponible() || !$palabras) return [];
        [$donde, $args] = self::condicion($palabras);
        $tabla = self::tabla();
        $tiene = self::columnas();
        $extra = implode(', ', array_map(fn($c) => "`$c`", array_values(array_filter(['calle', 'piso_puerta', 'codigo_postal', 'nit_rfc_rut'], fn($c) => in_array($c, $tiene, true)))));
        $st = self::pdo()->prepare("SELECT id, nombre, telefono, email, direccion, ciudad" . ($extra !== '' ? ", $extra" : '') . " FROM `$tabla` WHERE $donde ORDER BY (nombre LIKE ?) DESC, nombre ASC LIMIT " . max(1, min(30, $limite)));
        $st->execute([...$args, addcslashes($palabras[0], '%_\\') . '%']);
        return array_map([self::class, 'dar'], $st->fetchAll());
    }

    /** Una fila de la tabla de clientes de Servicios Clim con la forma que usa el sistema de presupuestos. */
    public static function dar(array $f): array
    {
        $s = fn($k) => trim((string) ($f[$k] ?? ''));
        $cp = $s('codigo_postal');
        // Si el cliente se guardó con la dirección en un solo texto (sin calle separada), ese texto va en «calle»
        return [
            'id' => (int) $f['id'], 'nombre' => $s('nombre'), 'telefono' => $s('telefono'), 'email' => $s('email'),
            'direccion' => $s('direccion') ?: $s('ciudad'),
            'dni' => strtoupper(preg_replace('/[\s.\-]+/', '', $s('nit_rfc_rut')) ?? ''), 'calle' => $s('calle') ?: $s('direccion'), 'piso' => $s('piso_puerta'),
            'codigo_postal' => $cp, 'ciudad' => $s('ciudad'), 'provincia' => self::provinciaDe($cp),
        ];
    }
}
