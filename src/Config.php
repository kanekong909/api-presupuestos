<?php
// CONFIG — lee config/config.php. Config::get('ia.usos.interprete.modelo', 'valor por defecto')

final class Config
{
    private static ?array $datos = null;

    public static function todo(): array
    {
        if (self::$datos === null) {
            $f = RAIZ . '/config/config.php';
            if (!is_file($f)) throw new RuntimeException('Falta config/config.php: copia config.ejemplo.php y rellénalo.');
            self::$datos = require $f;
        }
        return self::$datos;
    }

    public static function get(string $ruta, mixed $defecto = null): mixed
    {
        $v = self::todo();
        foreach (explode('.', $ruta) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $defecto;
            $v = $v[$k];
        }
        return $v;
    }

    /** Para las pruebas: cambia la config solo en esta ejecución. */
    public static function poner(array $datos): void
    {
        self::$datos = $datos;
    }
}
