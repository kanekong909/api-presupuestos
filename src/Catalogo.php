<?php
// CATÁLOGO — busca candidatos en la Base maestra de servicios y en los productos.
// Devuelve siempre la misma forma, venga de donde venga:
//   ['fuente' => 'servicio'|'producto', 'id', 'codigo', 'nombre', 'unidad', 'precio', 'modo_precio', 'importe_minimo', 'iva_incluido', 'proveedor']
//
// Cómo busca (para que la IA tenga entre qué elegir, sin pasarle toda la base):
//   · Cada palabra pesa según lo rara que es: «cable» sale en miles de productos y casi no cuenta; «h07v-k» o «certificado» sí.
//   · Se hacen varias búsquedas (por las palabras clave que da la IA, por el concepto tal cual y por los sinónimos que dio) y se juntan.
//   · En servicios se prefiere el área del pedido (un cuadro de vivienda no se busca entre los de fotovoltaica), pero siempre se
//     añaden algunos de otras áreas: hay servicios que viven en otra (los certificados están en «alta tensión»).

final class Catalogo
{
    private const PARADAS = ['de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos', 'unas', 'para', 'con', 'sin', 'por', 'que', 'en', 'al', 'y', 'o', 'a', 'su', 'se', 'es', 'ud', 'uds'];
    private static array $cachePesos = [];

    /** Palabras útiles para buscar (sin artículos, de 2+ letras, o con número y letra como «7kw»). */
    public static function palabras(array $textos): array
    {
        $out = [];
        foreach ($textos as $t) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $t), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                if (in_array($w, self::PARADAS, true) || mb_strlen($w) < 2 || ctype_digit($w) && mb_strlen($w) < 2) continue;
                $out[self::raiz($w)] = true;
            }
        }
        $out = array_keys($out);
        // Con números, las unidades sueltas («mm», «w»…) no aportan: cada nombre las escribe distinto y «mm» solo trae cables de teléfono o de altavoz
        if (array_filter($out, fn($w) => preg_match('/^\d/', $w))) $out = array_values(array_filter($out, fn($w) => !in_array($w, ['mm', 'mm²', 'mm2', 'cm', 'mts', 'kw', 'ma', 'ka', 'kva', 'wp', 'kwp'], true)));
        return array_slice($out, 0, 10);
    }

    /**
     * Quita el plural para buscar: «módulos» → «módulo», «paneles» → «panel», «fotovoltaicos» → «fotovoltaico».
     * Como se busca con LIKE %…%, «módulo» encuentra tanto «módulo» como «módulos». Sin esto, pedir «módulos fotovoltaicos» no encontraba «Montaje de módulo fotovoltaico».
     */
    public static function raiz(string $w): string
    {
        $n = mb_strlen($w);
        if ($n > 5 && str_ends_with($w, 'es') && !preg_match('/[0-9]/', $w)) return mb_substr($w, 0, $n - 2);
        if ($n > 3 && str_ends_with($w, 's') && !preg_match('/[0-9]/', $w)) return mb_substr($w, 0, $n - 1);
        return $w;
    }

    /** Con «busqueda» (las palabras clave que da la IA) se busca por lo esencial; si no la hay, por el concepto y sus sinónimos. */
    public static function palabrasDe(array $req): array
    {
        $b = trim((string) ($req['busqueda'] ?? ''));
        $base = $b !== '' ? self::palabras([$b]) : self::palabras([$req['concepto'], ...($req['sinonimos'] ?? [])]);
        // Además, con el nombre que tiene en la base lo que el cliente llama de otra forma (tabla `sinonimos`)
        $extra = self::palabras([Sinonimos::expandir($req['concepto'] . ' ' . $b)]);
        foreach ($base as $w) foreach (self::EQUIVALENCIAS[self::sinTildes($w)] ?? [] as $e) $extra[] = $e;
        return array_values(array_unique(array_merge(array_slice($base, 0, 7), array_slice($extra, 0, 6))));
    }

    /**
     * Los grupos de palabras con los que se hace una búsqueda cada uno; luego se juntan los resultados:
     *   1. lo esencial (las palabras clave que da la IA),  2. el concepto entero,  3. los otros nombres que dio la IA,
     *   4. los sinónimos de la empresa. Este último va APARTE: si se mezclara con el primero, «magnetotérmico» + «magneto» + «térmico»
     *      contarían tres veces lo mismo y empujarían a los productos que empiezan por esa palabra por encima de los demás.
     */
    private static function gruposDePalabras(array $req): array
    {
        $b = trim((string) ($req['busqueda'] ?? ''));
        $base = $b !== '' ? self::palabras([$b]) : self::palabras([$req['concepto'], ...($req['sinonimos'] ?? [])]);
        $grupos = [
            array_slice($base, 0, 7),
            self::palabras([$req['concepto']]),
            self::palabras($req['sinonimos'] ?? []),
            array_slice(self::palabras([Sinonimos::expandir($req['concepto'] . ' ' . $b)]), 0, 6),
        ];
        $vistos = [];
        $out = [];
        foreach ($grupos as $g) {
            $g = array_values(array_unique($g));
            $clave = implode('|', array_unique($g));
            sort($g);
            $clave = implode('|', $g);
            if ($g && !isset($vistos[$clave])) { $vistos[$clave] = true; $out[] = $g; }
        }
        return $out;
    }

    /** Lo rara que es cada palabra en esa columna (0,3 si sale en casi todo… 3,5 si casi no sale): una sola consulta para todas. */
    private static function pesos(string $tabla, string $columna, string $donde, array $palabras): array
    {
        $clave = "$tabla.$columna|" . implode('|', $palabras);
        if (isset(self::$cachePesos[$clave])) return self::$cachePesos[$clave];
        $sumas = implode(', ', array_map(fn($i) => "SUM($columna LIKE ?) AS c$i", array_keys($palabras)));
        $consulta = "SELECT COUNT(*) AS n, $sumas FROM $tabla WHERE $donde";
        $fila = ($tabla === 'productos' ? Db::cFila($consulta, array_map(fn($w) => "%$w%", $palabras)) : Db::fila($consulta, array_map(fn($w) => "%$w%", $palabras))) ?? [];
        $n = max(1, (int) ($fila['n'] ?? 1));
        $pesos = [];
        foreach (array_values($palabras) as $i => $w) {
            $peso = max(0.3, min(3.5, log10(($n + 1) / ((int) ($fila["c$i"] ?? 0) + 1))));
            if (preg_match('/[0-9]/', $w)) $peso *= 3;   // un valor técnico (16, 25a, 30ma) decide más que una palabra: un 25 A no vale por 16 A
            $pesos[$w] = round($peso, 2);
        }
        return self::$cachePesos[$clave] = $pesos;
    }

    /** Une las listas de candidatos, sin repetir y sin pasar del límite. */
    private static function juntar(array $listas, int $limite, bool $ordenarPorPuntos = false): array
    {
        $out = [];
        foreach ($listas as $lista) foreach ($lista as $c) if (!isset($out[$c['id']])) $out[$c['id']] = $c;
        $out = array_values($out);
        // Con el orden por puntos, lo que más se parece entra seguro aunque venga de otra búsqueda (p. ej. de otra área)
        if ($ordenarPorPuntos) usort($out, fn($a, $b) => $b['puntos'] <=> $a['puntos']);
        return array_slice($out, 0, $limite);
    }

    // ---------------------------------------------------------------- búsqueda manual (la caja de «Buscar» de las pantallas)

    /** Quita tildes y pasa a minúsculas, para comparar sin fijarse en ellas. */
    private static function sinTildes(string $s): string
    {
        return strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /** Las palabras de lo que escribe la persona. TODAS cuentan (números y medidas incluidos), sin plurales: «módulos» encuentra «módulo». */
    public static function palabrasBusqueda(string $q): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}.,"\/+-]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $w = trim($w, '.,-+"/');
            if ($w === '' || in_array($w, self::PARADAS, true) || $w === 'x' || $w === '×') continue;
            $out[] = preg_match('/^\p{L}{4,}$/u', $w) ? self::raiz($w) : $w;
        }
        // Si hay números, las unidades sueltas («mm», «W», «A»…) no se exigen: el número ya dice la medida y cada nombre las escribe distinto («32mm», «Ø32», «2,5MM2»)
        if (array_filter($out, fn($w) => preg_match('/^\d/', $w))) $out = array_values(array_filter($out, fn($w) => !in_array($w, ['mm', 'mm²', 'mm2', 'cm', 'm', 'mts', 'w', 'kw', 'v', 'a', 'ma', 'ka', 'kva', 'wp', 'kwp'], true)));
        return array_values(array_unique(array_slice($out, 0, 8)));
    }

    /** Palabras que en el catálogo se dicen de otra forma (la clave, sin tildes): «tubería de cobre» es «tubo de cobre» en los nombres. */
    private const EQUIVALENCIAS = ['tuberia' => ['tubo'], 'tubo' => ['tuberia'], 'canaleta' => ['canal'], 'canal' => ['canaleta'], 'magnetotermico' => ['automatico', 'pia'], 'pia' => ['magnetotermico'], 'pvc' => ['rigido', 'rigida']];

    /** Las formas con las que puede aparecer una palabra: ella misma, sin la vocal final («frigorífica» encuentra «frigorífico») y sus equivalentes. */
    public static function alternativas(string $w): array
    {
        $alts = [$w];
        if (preg_match('/^\p{L}{6,}[ao]$/u', $w)) $alts[] = mb_substr($w, 0, -1);
        foreach (self::EQUIVALENCIAS[self::sinTildes($w)] ?? [] as $e) $alts[] = $e;
        return array_values(array_unique($alts));
    }

    /** Lo que se le pregunta a la web del proveedor: lo escrito y, si hace falta, la misma búsqueda con otra palabra (PVC → rígido). */
    public static function consultasWeb(string $q): array
    {
        $out = [$q];
        if (preg_match('/\bpvc\b/iu', $q)) $out[] = trim(preg_replace('/\bpvc\b/iu', 'rigido', $q));
        return array_values(array_unique($out));
    }

    /** ¿El texto contiene TODAS las palabras (o sus equivalentes), en cualquier orden? */
    public static function cumpleTodas(array $palabras, string $texto): bool
    {
        $t = self::sinTildes($texto);
        foreach ($palabras as $w) {
            $esta = false;
            foreach (self::alternativas($w) as $a) if (str_contains($t, self::sinTildes($a))) { $esta = true; break; }
            if (!$esta) return false;
        }
        return $palabras !== [];
    }

    /**
     * Búsqueda manual: devuelve SOLO lo que contiene TODAS las palabras escritas (en el nombre, la referencia, la marca u otros datos), aunque estén en
     * distinto orden. «pequeño material eléctrico» no trae lo que solo tiene «material». (El motor de los presupuestos sigue usando su búsqueda por
     * puntos, que es más flexible a propósito: ahí la IA elige entre candidatos.)
     */
    public static function busquedaManual(string $tipo, string $q, int $limite = 40): array
    {
        $palabras = self::palabrasBusqueda($q);
        if (!$palabras) return [];
        $esc = fn(string $w) => '%' . addcslashes($w, '%_\\') . '%';
        $args = [];
        if ($tipo === 'servicio') {
            $donde = "estado = 'ACTIVO' AND codigo <> 'codigo'";
            foreach ($palabras as $w) {
                $alts = self::alternativas($w);
                $donde .= ' AND (' . implode(' OR ', array_fill(0, count($alts), "(nombre LIKE ? OR codigo LIKE ? OR COALESCE(palabras_clave, '') LIKE ? OR COALESCE(descripcion, '') LIKE ? OR COALESCE(familia, '') LIKE ? OR COALESCE(subarea, '') LIKE ?)")) . ')';
                foreach ($alts as $a) array_push($args, ...array_fill(0, 6, $esc($a)));
            }
            return array_map(fn($f) => [
                'fuente' => 'servicio', 'id' => (int) $f['id'], 'codigo' => $f['codigo'], 'nombre' => $f['nombre'], 'descripcion' => mb_substr((string) $f['descripcion'], 0, 160),
                'unidad' => $f['unidad'], 'precio' => (float) $f['precio_unitario'], 'modo_precio' => $f['modo_precio'], 'importe_minimo' => (float) $f['importe_minimo'],
                'iva_incluido' => false, 'proveedor' => null, 'puntos' => 0.0,
            ], Db::filas("SELECT * FROM servicios WHERE $donde ORDER BY CHAR_LENGTH(nombre), precio_unitario LIMIT " . (int) $limite, $args));
        }
        $donde = "importable = 'SI' AND precio_actual > 0 AND estado_producto = 'ACTIVO'";
        foreach ($palabras as $w) {
            $alts = self::alternativas($w);
            $donde .= ' AND (' . implode(' OR ', array_fill(0, count($alts), "(nombre_normalizado LIKE ? OR referencia_proveedor LIKE ? OR COALESCE(marca, '') LIKE ? OR COALESCE(familia, '') LIKE ? OR COALESCE(categoria, '') LIKE ?)")) . ')';
            foreach ($alts as $a) array_push($args, ...array_fill(0, 5, $esc($a)));
        }
        $provs = array_values(array_filter((array) Config::get('productos.proveedores', [])));
        if ($provs) { $donde .= ' AND proveedor IN (' . implode(',', array_fill(0, count($provs), '?')) . ')'; array_push($args, ...$provs); }
        // Un tubo de PVC para instalaciones eléctricas es el RÍGIDO: sale antes que el macarrón (corrugado), salvo que se pida corrugado, macarrón o flexible
        $sin = array_map(fn($w) => self::sinTildes($w), $palabras);
        $rigidoPrimero = (in_array('pvc', $sin, true) || in_array('rigido', $sin, true)) && !array_intersect($sin, ['corrugado', 'macarron', 'flexible', 'espiral']);
        $orden0 = $rigidoPrimero ? "(nombre_normalizado LIKE '%rigid%') DESC, " : '';
        // Primero los proveedores preferidos (los de Ajustes), después lo más corto (suele ser lo más directo) y lo más barato
        $pref = array_slice(Ajustes::lista('proveedor_producto'), 0, 2);
        $orden = $orden0 . ($pref ? 'CASE proveedor' . implode('', array_map(fn($k) => ' WHEN ? THEN ' . $k, array_keys($pref))) . ' ELSE 2 END, ' : '');
        if ($pref) array_push($args, ...$pref);
        return array_map(fn($f) => [
            'fuente' => 'producto', 'id' => (int) $f['id'], 'codigo' => $f['referencia_proveedor'], 'nombre' => mb_substr((string) $f['nombre_normalizado'], 0, 200), 'descripcion' => '',
            'unidad' => self::unidadProducto($f), 'precio' => (float) $f['precio_actual'], 'modo_precio' => 'FIJO', 'importe_minimo' => 0.0,
            'iva_incluido' => (bool) $f['iva_incluido'], 'proveedor' => $f['proveedor'], 'imagen' => $f['imagen_url'] ?? null, 'puntos' => 0.0,
        ], Db::cFilas("SELECT * FROM productos WHERE $donde ORDER BY {$orden}CHAR_LENGTH(nombre_normalizado), precio_actual LIMIT " . (int) $limite, $args));
    }

    // ---------------------------------------------------------------- servicios

    public static function servicios(array $req, int $limite = 14): array
    {
        $grupos = self::gruposDePalabras($req);
        if (!$grupos) return [];
        $area = $req['area'] ?? null;
        $conArea = $area && $area !== 'GEN';
        $listas = [];
        foreach ($grupos as $i => $palabras) {
            $filas = self::buscarServicios($palabras, $conArea ? $area : null, $i === 0 ? 8 : 5);
            if (!$filas && $conArea) $filas = self::buscarServicios($palabras, null, $i === 0 ? 8 : 5);   // en su área no hay nada: se busca en todas
            $listas[] = $filas;
        }
        if ($conArea) $listas[] = self::buscarServicios($grupos[0], null, 4);   // y algunos de fuera de su área (los certificados, por ejemplo, están en otra)
        return self::juntar($listas, $limite, true);
    }

    private static function buscarServicios(array $palabras, ?string $area, int $limite): array
    {
        $pesos = self::pesos('servicios', 'nombre', "estado = 'ACTIVO' AND codigo <> 'codigo'", $palabras);
        $score = [];
        $args = [];
        foreach ($palabras as $w) {
            $p = number_format($pesos[$w], 2, '.', '');
            $score[] = "$p * ((nombre LIKE ?) * 3 + (COALESCE(palabras_clave, '') LIKE ?) * 3 + (COALESCE(descripcion, '') LIKE ?) + (COALESCE(familia, '') LIKE ?) + (COALESCE(subarea, '') LIKE ?))";
            array_push($args, "%$w%", "%$w%", "%$w%", "%$w%", "%$w%");
        }
        $extra = $area ? " AND linea IN (?, 'GEN')" : '';
        if ($area) $args[] = $area;
        $sql = 'SELECT *, (' . implode(' + ', $score) . ") AS puntos FROM servicios
                WHERE estado = 'ACTIVO' AND codigo <> 'codigo'$extra HAVING puntos > 0 ORDER BY puntos DESC, precio_unitario ASC LIMIT " . (int) $limite;
        return array_map(fn($f) => [
            'fuente' => 'servicio', 'id' => (int) $f['id'], 'codigo' => $f['codigo'], 'nombre' => $f['nombre'],
            'descripcion' => mb_substr((string) $f['descripcion'], 0, 160), 'unidad' => $f['unidad'], 'precio' => (float) $f['precio_unitario'],
            'modo_precio' => $f['modo_precio'], 'importe_minimo' => (float) $f['importe_minimo'], 'iva_incluido' => false,
            'proveedor' => null, 'puntos' => round((float) $f['puntos'], 1),
        ], Db::filas($sql, $args));
    }

    // ---------------------------------------------------------------- productos

    public static function productos(array $req, int $limite = 22): array
    {
        $grupos = self::gruposDePalabras($req);
        if (!$grupos) return [];
        $listas = [];
        // Los proveedores preferidos (los de Ajustes, según el área) suben en la búsqueda: si no, el más barato de otro proveedor ocupa los primeros puestos
        $preferidos = Ajustes::lista(($req['area'] ?? '') === 'FOT' && Ajustes::lista('proveedor_fotovoltaica') ? 'proveedor_fotovoltaica' : 'proveedor_producto');
        foreach ($grupos as $i => $palabras) $listas[] = self::buscarProductos($palabras, $i === 0 ? 14 : 8, $preferidos);
        return self::juntar($listas, $limite);
    }

    private static function buscarProductos(array $palabras, int $limite, array $preferidos = []): array
    {
        $base = "importable = 'SI' AND precio_actual > 0 AND estado_producto = 'ACTIVO'";
        $pesos = self::pesos('productos', 'nombre_normalizado', $base, $palabras);
        $score = [];
        $args = [];
        foreach ($palabras as $w) {
            // Además de las coincidencias, un punto fuerte si el nombre EMPIEZA por la palabra: los productos se llaman «Panel Solar AIKO…», «Cable …»,
            // mientras que los accesorios y los kits empiezan por otra cosa («Pareja conector panel solar», «Kit solar…»). Así el accesorio no desplaza al producto.
            $p = number_format($pesos[$w], 2, '.', '');
            $score[] = "$p * ((nombre_normalizado LIKE ?) * 3 + (nombre_normalizado LIKE ?) * 9 + (COALESCE(familia, '') LIKE ?) * 2 + (COALESCE(marca, '') LIKE ?) * 2 + (COALESCE(categoria, '') LIKE ?) + (COALESCE(variante_modelo, '') LIKE ?))";
            array_push($args, "%$w%", "$w%", "%$w%", "%$w%", "%$w%", "%$w%");
        }
        // Bonus por proveedor preferido: el primero de la lista +8, el segundo +4 (los productos de Novelec no traen familia ni categoría y, sin esto, quedarían por debajo)
        $bonus = '';
        foreach (array_slice($preferidos, 0, 2) as $k => $p) { $bonus .= ' WHEN ? THEN ' . (8 - 4 * $k); }
        if ($bonus !== '') { $score[] = 'CASE proveedor' . $bonus . ' ELSE 0 END'; array_push($args, ...array_slice($preferidos, 0, 2)); }
        $filtro = '';
        $provs = array_values(array_filter((array) Config::get('productos.proveedores', [])));
        if ($provs) {
            $filtro = ' AND proveedor IN (' . implode(',', array_fill(0, count($provs), '?')) . ')';
            array_push($args, ...$provs);
        }
        // Un número que se pide («10») tiene que estar como número completo en el nombre: no vale «100» ni «10 KG» de otra cosa. Solo si además hay palabras (cable 10)
        $nums = array_values(array_filter($palabras, fn($w) => preg_match('/^\d+([.,]\d+)?$/', $w)));
        if ($nums && count($nums) < count($palabras)) {
            foreach ($nums as $nu) { $filtro .= " AND nombre_normalizado REGEXP ?"; $args[] = '(^|[^0-9.,])' . preg_quote($nu) . '([^0-9]|$)'; }
        }
        $sql = 'SELECT *, (' . implode(' + ', $score) . ") AS puntos FROM productos
                WHERE $base $filtro HAVING puntos > 0 ORDER BY puntos DESC, precio_actual ASC LIMIT " . (int) $limite;
        return array_map(fn($f) => [
            'fuente' => 'producto', 'id' => (int) $f['id'], 'codigo' => $f['referencia_proveedor'], 'nombre' => mb_substr((string) $f['nombre_normalizado'], 0, 200),
            'descripcion' => '', 'unidad' => self::unidadProducto($f), 'precio' => (float) $f['precio_actual'], 'modo_precio' => 'FIJO',
            'importe_minimo' => 0.0, 'iva_incluido' => (bool) $f['iva_incluido'], 'proveedor' => $f['proveedor'], 'imagen' => $f['imagen_url'] ?? null, 'puntos' => round((float) $f['puntos'], 1),
        ], Db::cFilas($sql, $args));
    }

    /**
     * La unidad de un producto. Novelec no informa de la unidad y se guarda «UD», pero su cable y su manguera se venden (y se cobran) por METRO:
     * se muestran en metros. Los tubos, canaletas y demás van por barra, y otros proveedores pueden vender el cable por rollo, así que no se tocan.
     */
    public static function unidadProducto(array $f): string
    {
        $u = (string) ($f['unidad_normalizada'] ?? '') ?: 'UD';
        $nombre = (string) ($f['nombre_normalizado'] ?? $f['nombre'] ?? '');
        if (mb_strtolower($u) === 'ud' && ($f['proveedor'] ?? '') === 'NOVELEC' && preg_match('/(cable|manguera|cord[oó]n|hilo)/iu', $nombre) && !preg_match('/(tubo|canaleta|bandeja|perfil|barra)/iu', $nombre)) return 'm';
        return $u;
    }

    /** El elemento tal como está en la base, para recalcular precios con datos frescos y no con lo que dijo la IA. */
    /** Corrige la unidad de un producto. Novelec no la informa y se guarda «ud»; las búsquedas posteriores no la pisan. */
    public static function cambiarUnidad(int $id, string $unidad, array $yo): array
    {
        $u = mb_substr(trim($unidad), 0, 20);
        if ($u === '') throw new ErrorApi('Falta la unidad (m, ud, rollo…).', 400);
        $p = Db::cFila('SELECT id, nombre_normalizado, unidad_normalizada FROM productos WHERE id = ?', [$id]);
        if (!$p) throw new ErrorApi('No existe ese producto.', 404);
        Db::cActualizar('productos', $id, ['unidad_normalizada' => $u]);
        Eventos::anotar($yo, 'producto_unidad_corregida', 'producto', $id, ['nombre' => $p['nombre_normalizado'], 'antes' => $p['unidad_normalizada'], 'ahora' => $u]);
        return ['id' => $id, 'unidad' => $u];
    }

    public static function obtener(string $fuente, int $id): ?array
    {
        $f = $fuente === 'servicio'
            ? Db::fila("SELECT * FROM servicios WHERE id = ? AND codigo <> 'codigo'", [$id])
            : Db::cFila("SELECT * FROM productos WHERE id = ? AND importable = 'SI'", [$id]);
        if (!$f) return null;
        return $fuente === 'servicio'
            ? ['fuente' => 'servicio', 'id' => (int) $f['id'], 'codigo' => $f['codigo'], 'nombre' => $f['nombre'], 'descripcion' => (string) $f['descripcion'], 'unidad' => $f['unidad'],
                'precio' => (float) $f['precio_unitario'], 'modo_precio' => $f['modo_precio'], 'importe_minimo' => (float) $f['importe_minimo'], 'iva_incluido' => false, 'proveedor' => null]
            : ['fuente' => 'producto', 'id' => (int) $f['id'], 'codigo' => $f['referencia_proveedor'], 'nombre' => (string) $f['nombre_normalizado'], 'descripcion' => '', 'unidad' => self::unidadProducto($f),
                'precio' => (float) $f['precio_actual'], 'modo_precio' => 'FIJO', 'importe_minimo' => 0.0, 'iva_incluido' => (bool) $f['iva_incluido'], 'proveedor' => $f['proveedor'], 'imagen' => $f['imagen_url'] ?? null];
    }
}
