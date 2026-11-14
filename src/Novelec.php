<?php
// NOVELEC — la fuente de productos de la arquitectura (gruponovelec.com). Entra con TU usuario (config › novelec) porque con
// sesión iniciada salen los precios para profesionales; sin ella solo sale el PVP, más alto, y NO se usa.
//
// Cómo funciona: se busca en /search?text=… , se leen los productos de la lista, y cada uno se guarda en la tabla `productos`
// (proveedor «NOVELEC») para que el resto del sistema lo trate como cualquier otro producto (id, histórico de precio…).
// Se llama solo cuando el catálogo local no ha encontrado nada que sirva (ver Presupuestos::calcular).
//
// La web es de tipo SAP Hybris (formulario /j_spring_security_check con CSRFToken). Para ver cómo responde y afinar:
//     php herramientas/probar_novelec.php "magnetotérmico 16A"

final class Novelec
{
    private const BASE = 'https://www.gruponovelec.com';
    private static ?string $galletas = null;
    private static ?bool $autenticado = null;

    /** ¿Está configurado el usuario de Novelec y permitido usarlo? */
    public static function disponible(): bool
    {
        $c = Config::get('novelec');
        return $c && !empty($c['usuario']) && !str_starts_with((string) ($c['clave'] ?? 'PON_AQUI'), 'PON_AQUI') && Config::get('productos.fuente', 'local_y_novelec') !== 'local';
    }

    /**
     * Busca los productos del requerimiento en Novelec y los devuelve en la forma de Catalogo (ya guardados en `productos`).
     * Si no se puede entrar o no responde, devuelve [] y el presupuesto sigue con lo que haya.
     */
    public static function buscar(array $req, int $limite = 14): array
    {
        try {
            if (!self::disponible() || !self::entrar()) return [];
            $palabras = trim((string) ($req['busqueda'] ?? '')) ?: implode(' ', array_slice(Catalogo::palabras([$req['concepto'] ?? '']), 0, 4));
            if ($palabras === '') return [];
            $r = self::peticion(self::BASE . '/search?text=' . rawurlencode($palabras));
            if ($r['http'] >= 400) return [];
            $items = [];
            foreach (array_slice(self::parsear($r['cuerpo']), 0, $limite) as $p) {
                $precio = self::precioProfesional($p['precios']);
                if ($precio === null) continue;
                $id = self::guardar($p, $precio);
                if ($id && ($item = Catalogo::obtener('producto', $id))) $items[] = $item;
            }
            return $items;
        } catch (Throwable $e) {
            error_log('[presupuestos] Novelec: ' . $e->getMessage());
            return [];
        }
    }

    // ---------------------------------------------------------------- sesión

    /** Inicia sesión si hace falta (las cookies se guardan en un archivo temporal para no entrar en cada búsqueda). */
    public static function entrar(): bool
    {
        if (self::$autenticado === true) return true;
        if (self::sesionActiva()) return self::$autenticado = true;

        $c = Config::get('novelec');
        $pagina = self::peticion($c['url_login'] ?? (self::BASE . '/login'));
        $formulario = preg_match('/<form[^>]*id="loginForm".*?<\/form>/is', $pagina['cuerpo'], $fm) ? $fm[0] : $pagina['cuerpo'];   // solo ese formulario (la página tiene más)
        $campos = self::camposFormulario($formulario);
        $usuario = $campos['_campo_usuario'] ?? 'j_username';
        $clave = $campos['_campo_clave'] ?? 'j_password';
        unset($campos['_campo_usuario'], $campos['_campo_clave']);
        $campos[$usuario] = $c['usuario'];
        $campos[$clave] = $c['clave'];
        $accion = preg_match('/<form[^>]*id="loginForm"[^>]*action="([^"]+)"/i', $pagina['cuerpo'], $m) ? $m[1] : '/j_spring_security_check';
        self::peticion(str_starts_with($accion, 'http') ? $accion : self::BASE . $accion, $campos);
        return self::$autenticado = self::sesionActiva();
    }

    /** Con sesión iniciada, «mi cuenta» abre; sin ella, te manda al login. */
    public static function sesionActiva(): bool
    {
        $r = self::peticion(self::BASE . '/my-account');
        return $r['http'] < 400 && !str_contains($r['url'], 'login');
    }

    // ---------------------------------------------------------------- lectura de la lista de productos

    /**
     * @return array<int, array{nombre:string, ref:string, url:string, marca:string, precios:array<int, array{importe:float, texto:string, atributos:string}>}>
     */
    public static function parsear(string $html): array
    {
        $out = [];
        foreach (array_slice(preg_split('/<div class="product-item">/i', $html), 1) as $bloque) {
            $bloque = preg_replace('#<script\b.*?</script>#is', '', $bloque) ?? $bloque;
            $bloque = substr($bloque, 0, 6000);
            if (!preg_match('/<a class="product-item__name" href="([^"]+)">\s*(.*?)\s*<\/a>/is', $bloque, $n)) continue;
            $ref = preg_match('/product-item__code">\s*<span>\s*REF:\s*<\/span>\s*([^<]+)/i', $bloque, $r) ? trim(html_entity_decode($r[1])) : '';
            if ($ref === '') continue;
            $precios = [];
            $zonaPrecio = preg_match('/class="product-item__price".*?(?=product-item__actions|$)/is', $bloque, $z) ? $z[0] : $bloque;
            if (preg_match_all('/<([a-z0-9]+)([^>]*)>\s*([\d.]+,\d{1,2})\s*(?:€|&euro;)/iu', $zonaPrecio, $pp, PREG_SET_ORDER)) {
                foreach ($pp as $p) $precios[] = ['importe' => self::numero($p[3]), 'texto' => $p[3] . ' €', 'atributos' => trim(preg_replace('/\s+/', ' ', $p[2]))];
            }
            $out[] = [
                'nombre' => trim(html_entity_decode(strip_tags($n[2]))), 'ref' => $ref, 'url' => $n[1],
                'marca' => preg_match('/class="plp-brand-logo"[^>]*alt="([^"]*)"/i', $bloque, $b) ? html_entity_decode($b[1]) : '',
                'imagen' => self::imagen($bloque),
                'precios' => $precios,
            ];
        }
        return $out;
    }

    /** La foto del producto en la lista: la imagen dentro del enlace «product-item__thumb» (la «missing_product» es la foto de relleno de la web). */
    public static function imagen(string $bloque): ?string
    {
        if (!preg_match('/class="product-item__thumb[^"]*"[^>]*>\s*<img[^>]*?\ssrc="([^"]+)"/is', $bloque, $m)) return null;
        $u = html_entity_decode(trim($m[1]));
        if ($u === '' || str_contains($u, 'missing_product')) return null;
        if (str_starts_with($u, '//')) $u = 'https:' . $u;
        elseif (str_starts_with($u, '/')) $u = self::BASE . $u;
        return str_starts_with($u, 'https://') ? mb_substr($u, 0, 500) : null;
    }

    /**
     * El precio para profesionales: con sesión iniciada la web lo enseña en «product-item__price-user» (el PVP va en «…-pvp», mucho más alto).
     * Si no sale el precio de usuario (sesión caída), el producto NO se usa: no se presupuesta con el PVP.
     */
    public static function precioProfesional(array $precios): ?float
    {
        foreach ($precios as $p) if (str_contains($p['atributos'], 'price-user') && $p['importe'] > 0) return $p['importe'];
        return null;
    }

    private static function numero(string $s): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', $s));
    }

    /** Guarda o actualiza el producto en `productos` (proveedor NOVELEC); si cambia el precio, el anterior queda en precio_anterior. */
    private static bool $imagenListo = false;

    /** La columna `imagen_url` (sql/11) se crea sola si falta: basta con subir el código. */
    private static function asegurarImagen(): void
    {
        if (self::$imagenListo) return;
        if (!Db::cFila("SHOW COLUMNS FROM productos LIKE 'imagen_url'")) Db::cEjecutar('ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(500) NULL');
        self::$imagenListo = true;
    }

    private static function guardar(array $p, float $precio): int
    {
        self::asegurarImagen();
        $nombre = mb_substr($p['nombre'], 0, 500);
        Db::cEjecutar(
            "INSERT INTO productos (proveedor, referencia_proveedor, clave_proveedor_referencia, id_registro, nombre_original, nombre_normalizado, marca, precio_actual,
                                    unidad_normalizada, moneda, iva_incluido, origen_precio, estado_producto, estado_datos, importable, fuente, imagen_url, fecha_importacion)
             VALUES ('NOVELEC', ?, ?, ?, ?, ?, ?, ?, 'UD', 'EUR', 0, 'NOVELEC profesional', 'ACTIVO', 'OK', 'SI', ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE precio_anterior = IF(precio_actual <> VALUES(precio_actual), precio_actual, precio_anterior), precio_actual = VALUES(precio_actual),
                                     nombre_normalizado = VALUES(nombre_normalizado), marca = VALUES(marca), estado_producto = 'ACTIVO', importable = 'SI', fuente = VALUES(fuente), imagen_url = COALESCE(VALUES(imagen_url), imagen_url), fecha_importacion = CURDATE()",
            [$p['ref'], 'NOVELEC|' . $p['ref'], 'NOV-' . $p['ref'], $nombre, $nombre, mb_substr($p['marca'], 0, 150), $precio, mb_substr(self::BASE . $p['url'], 0, 500), $p['imagen'] ?? null]
        );
        return (int) (Db::cFila("SELECT id FROM productos WHERE proveedor = 'NOVELEC' AND referencia_proveedor = ?", [$p['ref']])['id'] ?? 0);
    }

    // ---------------------------------------------------------------- HTTP

    /** Campos ocultos del formulario de acceso (token CSRF, etc.) y cuál es el del usuario y el de la contraseña. */
    public static function camposFormulario(string $html): array
    {
        $campos = [];
        if (preg_match_all('/<input\b[^>]*>/i', $html, $m)) {
            foreach ($m[0] as $in) {
                preg_match('/name=["\']([^"\']+)["\']/i', $in, $n);
                preg_match('/type=["\']([^"\']+)["\']/i', $in, $t);
                preg_match('/value=["\']([^"\']*)["\']/i', $in, $v);
                if (empty($n[1])) continue;
                $tipo = strtolower($t[1] ?? 'text');
                if ($tipo === 'hidden' && !isset($campos[$n[1]])) $campos[$n[1]] = html_entity_decode($v[1] ?? '');
                elseif ($tipo === 'password') $campos['_campo_clave'] ??= $n[1];
                elseif (in_array($tipo, ['email', 'text'], true) && str_contains($n[1], 'username')) $campos['_campo_usuario'] ??= $n[1];
            }
        }
        return $campos;
    }

    public static function peticion(string $url, ?array $post = null): array
    {
        self::$galletas ??= sys_get_temp_dir() . '/novelec_' . md5((string) Config::get('novelec.usuario')) . '.txt';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEJAR => self::$galletas, CURLOPT_COOKIEFILE => self::$galletas, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ClimPresupuestos/1.0)',
            CURLOPT_ENCODING => '',
        ]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $cuerpo = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return ['http' => $http, 'cuerpo' => $cuerpo, 'url' => $final];
    }
}
