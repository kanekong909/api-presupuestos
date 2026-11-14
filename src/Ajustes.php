<?php
// AJUSTES — los valores de la tabla `configuracion` (margen, IVA, umbrales de confianza…). Se cambian sin tocar código.

final class Ajustes
{
    private static ?array $cache = null;

    private const DEFECTO = [
        'margen_producto' => '0.30', 'confianza_automatica' => '85', 'confianza_revision' => '70',
        'iva_general' => '0.21', 'proveedor_producto' => 'NOVELEC', 'proveedor_fotovoltaica' => 'Solar Fácil', 'version_motor' => 'V0.1',
    ];

    public static function todos(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFECTO;
            foreach (Db::filas('SELECT clave, valor FROM configuracion') as $f) self::$cache[$f['clave']] = $f['valor'];
        }
        return self::$cache;
    }

    public static function texto(string $clave): string
    {
        return (string) (self::todos()[$clave] ?? '');
    }

    public static function numero(string $clave): float
    {
        return (float) str_replace(',', '.', self::texto($clave));
    }

    /** Un ajuste que es una lista separada por comas («NOVELEC,OBRAMAT»), en su orden. */
    public static function lista(string $clave): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::texto($clave)))));
    }

    /** ¿Se usa este proveedor? Sí si está en alguna de las listas de preferidos, o si no se ha elegido ninguno. */
    public static function usaProveedor(string $proveedor): bool
    {
        $todos = array_merge(self::lista('proveedor_producto'), self::lista('proveedor_fotovoltaica'));
        if (!$todos) return true;
        foreach ($todos as $p) if (mb_strtolower($p) === mb_strtolower($proveedor)) return true;
        return false;
    }

    public static function guardar(string $clave, string $valor): void
    {
        if (!array_key_exists($clave, self::todos())) throw new ErrorApi("No existe el ajuste «{$clave}».", 404);
        Db::ejecutar('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, $valor]);
        self::$cache = null;
    }
}
