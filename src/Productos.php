<?php
// PRODUCTOS POR PROVEEDOR — ver qué productos tiene guardados cada proveedor (Novelec, Obramat, Solar Fácil…), buscar entre ellos, saber cuáles se usan
// de verdad en los presupuestos y, si no, POR QUÉ no (sin precio, inactivo, no importable). Y traer productos nuevos de la web de Novelec.

final class Productos
{
    /** Los productos entran en las búsquedas del presupuesto solo si cumplen esto (ver Catalogo::buscarProductos). */
    private const UTIL = "importable = 'SI' AND estado_producto = 'ACTIVO' AND precio_actual > 0";

    /** Quita de las listas lo que no es un proveedor: filas vacías y la cabecera de un archivo importado («Proveedor»). */
    private const PROVEEDOR_VALIDO = "proveedor IS NOT NULL AND TRIM(proveedor) <> '' AND LOWER(TRIM(proveedor)) <> 'proveedor'";

    private static function tieneFoto(): bool
    {
        static $r = null;
        return $r ??= (bool) Db::cFila("SHOW COLUMNS FROM productos LIKE 'imagen_url'");
    }

    /** Cada proveedor con cuántos productos tiene guardados, cuántos se usan en los presupuestos y cuántos tienen foto. */
    public static function proveedores(): array
    {
        $foto = self::tieneFoto() ? 'SUM(imagen_url IS NOT NULL)' : '0';
        return array_map(fn($f) => [
            'proveedor' => (string) $f['proveedor'], 'total' => (int) $f['total'], 'utiles' => (int) $f['utiles'], 'con_foto' => (int) $f['con_foto'],
            'productos' => (int) $f['importables'],   // lo que ya contaba la pantalla de Ajustes
        ], Db::cFilas("SELECT TRIM(proveedor) AS proveedor, COUNT(*) AS total, SUM(" . self::UTIL . ") AS utiles, SUM(importable = 'SI') AS importables, $foto AS con_foto
                      FROM productos WHERE " . self::PROVEEDOR_VALIDO . " GROUP BY TRIM(proveedor) ORDER BY total DESC"));
    }

    /** Los productos de un proveedor, con búsqueda por nombre, referencia o marca (se piden todas las palabras), y el motivo si no se usan en los presupuestos. */
    public static function listar(string $proveedor, string $q = '', int $pagina = 1, int $limite = 30, bool $soloFoto = false): array
    {
        $proveedor = trim($proveedor);
        if ($proveedor === '') throw new ErrorApi('Falta el proveedor.', 400);
        $limite = max(1, min(100, $limite));
        $pagina = max(1, $pagina);
        $donde = 'TRIM(proveedor) = ?';
        $args = [$proveedor];
        if ($soloFoto) $donde .= self::tieneFoto() ? " AND imagen_url IS NOT NULL AND imagen_url <> ''" : ' AND 1 = 0';   // solo los que tienen foto (para el visor)
        foreach (Catalogo::palabrasBusqueda($q) as $w) {
            $alts = Catalogo::alternativas($w);
            $donde .= ' AND (' . implode(' OR ', array_fill(0, count($alts), "(nombre_normalizado LIKE ? OR referencia_proveedor LIKE ? OR COALESCE(marca, '') LIKE ?)")) . ')';
            foreach ($alts as $a) { $like = '%' . addcslashes($a, '%_\\') . '%'; array_push($args, $like, $like, $like); }
        }
        $total = (int) (Db::cFila("SELECT COUNT(*) n FROM productos WHERE $donde", $args)['n'] ?? 0);
        $filas = Db::cFilas("SELECT * FROM productos WHERE $donde ORDER BY nombre_normalizado LIMIT $limite OFFSET " . (($pagina - 1) * $limite), $args);
        return [
            'proveedor' => $proveedor, 'total' => $total, 'pagina' => $pagina, 'paginas' => max(1, (int) ceil($total / $limite)),
            'productos' => array_map(function ($f) {
                $motivo = null;
                if (($f['importable'] ?? '') !== 'SI') $motivo = 'No importable';
                elseif (($f['estado_producto'] ?? '') !== 'ACTIVO') $motivo = 'Inactivo';
                elseif ((float) $f['precio_actual'] <= 0) $motivo = 'Sin precio';
                return ['id' => (int) $f['id'], 'proveedor' => (string) $f['proveedor'], 'codigo' => (string) $f['referencia_proveedor'], 'nombre' => mb_substr((string) $f['nombre_normalizado'], 0, 200),
                    'marca' => (string) ($f['marca'] ?? ''), 'precio' => (float) $f['precio_actual'], 'unidad' => Catalogo::unidadProducto($f), 'usable' => $motivo === null, 'motivo' => $motivo,
                    'imagen' => $f['imagen_url'] ?? null];
            }, $filas),
        ];
    }

    /** Trae de la web de Novelec los productos de una búsqueda y los guarda (con su precio de profesional y su foto). */
    public static function importarNovelec(string $q, array $yo): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) throw new ErrorApi('Escribe qué productos quieres traer de Novelec (por ejemplo «magnetotérmico 16 A»).', 400);
        if (!Novelec::disponible()) throw new ErrorApi('Novelec no está configurado: faltan el usuario y la contraseña en la configuración de la API.', 409);
        if (!Novelec::entrar()) throw new ErrorApi('No se ha podido entrar en Novelec con el usuario configurado: revisa la contraseña.', 502);
        $items = Novelec::buscar(['concepto' => $q, 'busqueda' => $q], 30);
        Eventos::anotar($yo, 'novelec_importado', 'producto', null, ['busqueda' => $q, 'productos' => count($items)]);
        if (!$items) throw new ErrorApi("Novelec no ha devuelto productos para «{$q}». Prueba con menos palabras o con otro nombre.", 404);
        return ['busqueda' => $q, 'importados' => count($items), 'productos' => array_map(fn($i) => ['id' => $i['id'], 'nombre' => $i['nombre'], 'codigo' => $i['codigo'], 'precio' => $i['precio']], array_slice($items, 0, 10))];
    }
}
