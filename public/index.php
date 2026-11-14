<?php
// ÚNICA ENTRADA DE LA API. Todas las direcciones pasan por aquí (ver .htaccess). Lista de rutas: API.md.
// Responde siempre JSON: { "ok": true, … } o { "ok": false, "error": "…" } con su código HTTP.

// En tu ordenador, src/ está junto a public/. En Hostinger, la parte privada vive FUERA de public_html:
//   domains/tudominio.com/api-presupuestos/   (src, config, prompts…)   y   public_html/api-presupuesto/index.php (este archivo)
$privada = is_file(dirname(__DIR__) . '/src/arranque.php') ? dirname(__DIR__) : dirname(__DIR__, 2) . '/api-presupuestos';
require $privada . '/src/arranque.php';

@set_time_limit(180);   // interpretar y calcular llaman a la IA y a Novelec: pueden tardar
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Clave');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function responder(int $http, array $datos): never
{
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function cuerpo(): array
{
    $crudo = trim((string) file_get_contents('php://input'));
    if ($crudo === '') return [];
    $d = json_decode($crudo, true);
    if (!is_array($d)) throw new ErrorApi('El cuerpo no es un JSON válido.', 400);
    return $d;
}

/** El token de «Authorization: Bearer …» (algunos servidores lo pasan con otro nombre). */
function tokenDeLaPeticion(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

try {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base = str_ends_with($script, 'index.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
    $ruta = '/' . trim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen($base)), '/');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $m = [];

    // ---- Rutas sin sesión ----
    if ($ruta === '/salud') responder(200, ['ok' => true, 'servicio' => 'api-presupuestos', 'motor' => Ajustes::texto('version_motor'), 'hora' => date('c'), 'necesita_primer_admin' => !Auth::hayUsuarios()]);
    if ($ruta === '/auth/login' && $metodo === 'POST') {
        $d = cuerpo();
        responder(200, ['ok' => true] + Auth::entrar((string) ($d['usuario'] ?? ''), (string) ($d['clave'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    }
    if ($ruta === '/auth/primer-admin' && $metodo === 'POST') {
        // Solo funciona mientras no haya ningún usuario: crea el administrador inicial y se cierra para siempre.
        if (Auth::hayUsuarios()) throw new ErrorApi('Ya hay usuarios: pide a un administrador que te cree uno.', 403);
        responder(201, ['ok' => true, 'usuario' => Auth::crear(['rol' => 'admin'] + cuerpo())]);
    }

    // ---- Quién eres: sesión (token) o, solo para que entren pedidos desde fuera (WhatsApp, web), la clave de la API ----
    $token = tokenDeLaPeticion();
    $yo = Auth::identificar($token);
    if ($yo === null) {
        $clave = (string) Config::get('api_clave', '');
        $esSistema = $clave !== '' && hash_equals($clave, (string) ($_SERVER['HTTP_X_API_CLAVE'] ?? ''));
        if ($esSistema && $ruta === '/presupuestos' && $metodo === 'POST') $yo = ['id' => null, 'usuario' => 'sistema', 'rol' => 'sistema'];
        else throw new ErrorApi('Inicia sesión: falta la cabecera Authorization: Bearer … o ha caducado.', 401);
    }
    $nota = fn(string $accion, ?int $id = null, array $detalle = []) => Eventos::anotar($yo, $accion, 'presupuesto', $id, $detalle);
    $uid = $yo['id'] === null ? null : (int) $yo['id'];

    // ---- Mi cuenta ----
    if ($ruta === '/auth/yo' && $metodo === 'GET') responder(200, ['ok' => true, 'usuario' => Auth::publico($yo)]);
    if ($ruta === '/auth/logout' && $metodo === 'POST') { Auth::salir((string) $token, $yo); responder(200, ['ok' => true]); }
    if ($ruta === '/auth/clave' && $metodo === 'POST') {
        $d = cuerpo();
        Auth::cambiarClave($yo, (string) ($d['actual'] ?? ''), (string) ($d['nueva'] ?? ''), (string) $token);
        responder(200, ['ok' => true]);
    }

    // ---- Usuarios y actividad (solo administradores) ----
    if ($ruta === '/usuarios' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'usuarios' => Auth::listar()]); }
    if ($ruta === '/usuarios' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'usuario' => Auth::crear(cuerpo(), $yo)]); }
    if (preg_match('#^/usuarios/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'usuario' => Auth::actualizar((int) $m[1], cuerpo(), $yo)]); }
    if ($ruta === '/eventos' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'eventos' => Eventos::listar($_GET, (int) ($_GET['limite'] ?? 100))]); }

    // ---- Presupuestos ----
    if ($ruta === '/presupuestos' && $metodo === 'GET') {
        responder(200, ['ok' => true] + Presupuestos::listar($_GET['estado'] ?? null, (int) ($_GET['limite'] ?? 50), (int) ($_GET['pagina'] ?? 1), $_GET['q'] ?? null));
    }
    if ($ruta === '/presupuestos' && $metodo === 'POST') {
        $p = Presupuestos::crear(cuerpo(), $uid);
        $nota('presupuesto_creado', $p['id'], ['canal' => $yo['rol'] === 'sistema' ? 'externo' : 'api']);
        responder(201, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)$#', $ruta, $m) && $metodo === 'GET') responder(200, ['ok' => true, 'presupuesto' => Presupuestos::obtener((int) $m[1])]);
    if (preg_match('#^/presupuestos/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') {   // datos del cliente
        $p = Presupuestos::actualizarCliente((int) $m[1], cuerpo());
        $nota('cliente_editado', $p['id']);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    // ---- Notas y condiciones: la biblioteca (verla, todos; administrarla, solo administradores) y las notas de cada presupuesto ----
    if ($ruta === '/notas' && $metodo === 'GET') responder(200, ['ok' => true, 'notas' => Notas::listar(($_GET['activas'] ?? '') === '1' || ($yo['rol'] ?? '') !== 'admin')]);
    if ($ruta === '/notas' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'nota' => Notas::crear(cuerpo(), $yo)]); }
    if (preg_match('#^/notas/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'nota' => Notas::actualizar((int) $m[1], cuerpo(), $yo)]); }
    if (preg_match('#^/notas/(\d+)$#', $ruta, $m) && $metodo === 'DELETE') { Auth::exigirAdmin($yo); responder(200, ['ok' => true] + Notas::eliminar((int) $m[1], $yo)); }
    if (preg_match('#^/presupuestos/(\d+)/notas$#', $ruta, $m) && $metodo === 'POST') {
        Presupuestos::obtener((int) $m[1]);
        $n = Notas::anadir((int) $m[1], cuerpo());
        $nota('nota_anadida', (int) $m[1]);
        responder(201, ['ok' => true, 'notas' => $n]);
    }
    if (preg_match('#^/presupuestos/(\d+)/notas/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') { Presupuestos::obtener((int) $m[1]); responder(200, ['ok' => true, 'notas' => Notas::cambiar((int) $m[1], (int) $m[2], cuerpo())]); }
    if (preg_match('#^/presupuestos/(\d+)/notas/(\d+)$#', $ruta, $m) && $metodo === 'DELETE') { Presupuestos::obtener((int) $m[1]); responder(200, ['ok' => true, 'notas' => Notas::quitar((int) $m[1], (int) $m[2])]); }
    // ---- Servicios completados de «Servicios Clim» (solo lectura): para empezar un presupuesto a partir de lo que escribió el técnico ----
    if ($ruta === '/servicios-clim' && $metodo === 'GET' && ($_GET['estado'] ?? '') === '1') responder(200, ['ok' => true, 'disponible' => ServiciosClim::disponible()]);   // solo saber si está la conexión
    if ($ruta === '/servicios-clim' && $metodo === 'GET') responder(200, ['ok' => true, 'disponible' => ServiciosClim::disponible()] + ServiciosClim::buscar((string) ($_GET['q'] ?? ''), (int) ($_GET['pagina'] ?? 1)));
    if (preg_match('#^/servicios-clim/(\d+)$#', $ruta, $m) && $metodo === 'GET') responder(200, ['ok' => true, 'servicio' => ServiciosClim::detalle((int) $m[1])]);
    if (preg_match('#^/presupuestos/(\d+)/pago$#', $ruta, $m) && $metodo === 'PUT') {   // condiciones de pago (reparto en plazos)
        Presupuestos::obtener((int) $m[1]);
        Pagos::guardar((int) $m[1], cuerpo());
        $nota('pago_editado', (int) $m[1]);
        responder(200, ['ok' => true, 'presupuesto' => Presupuestos::obtener((int) $m[1])]);
    }
    if (preg_match('#^/presupuestos/(\d+)$#', $ruta, $m) && $metodo === 'DELETE') {
        $r = Presupuestos::eliminar((int) $m[1], $yo);
        $nota('presupuesto_eliminado', $r['id'], ['numero' => $r['numero'], 'estado' => $r['estado']]);
        responder(200, ['ok' => true, 'eliminado' => $r]);
    }
    if ($ruta === '/presupuestos' && $metodo === 'DELETE') {   // DELETE /presupuestos?estado=BORRADOR
        if (($_GET['estado'] ?? '') !== 'BORRADOR') throw new ErrorApi('Solo se pueden borrar en bloque los borradores: usa ?estado=BORRADOR.', 400);
        $n = Presupuestos::eliminarBorradores();
        $nota('borradores_eliminados', null, ['cantidad' => $n]);
        responder(200, ['ok' => true, 'eliminados' => $n]);
    }
    if (preg_match('#^/presupuestos/(\d+)/editar$#', $ruta, $m) && $metodo === 'POST') {
        $p = Presupuestos::editar((int) $m[1], (string) (cuerpo()['cambio'] ?? ''));
        $nota('interpretacion_editada', $p['id']);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/confirmar$#', $ruta, $m) && $metodo === 'POST') {
        $p = Presupuestos::confirmar((int) $m[1]);
        $nota('presupuesto_confirmado', $p['id'], ['estado' => $p['estado'], 'total' => $p['total']]);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/recalcular$#', $ruta, $m) && $metodo === 'POST') {
        $p = Presupuestos::recalcular((int) $m[1]);
        $nota('presupuesto_recalculado', $p['id'], ['total' => $p['total']]);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/unificar/propuesta$#', $ruta, $m) && $metodo === 'POST') {
        responder(200, ['ok' => true] + Presupuestos::proponerUnificacion((int) $m[1], (array) (cuerpo()['lineas'] ?? [])));
    }
    if (preg_match('#^/presupuestos/(\d+)/unificar$#', $ruta, $m) && $metodo === 'POST') {
        $c = cuerpo();
        $p = Presupuestos::unificar((int) $m[1], (array) ($c['lineas'] ?? []), (string) ($c['titulo'] ?? ''), (string) ($c['descripcion'] ?? ''));
        $nota('partidas_unificadas', $p['id'], ['lineas' => array_map('intval', (array) ($c['lineas'] ?? [])), 'titulo' => (string) ($c['titulo'] ?? '')]);
        responder(201, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/grupos/(\d+)$#', $ruta, $m)) {
        if ($metodo === 'PATCH') { $p = Presupuestos::actualizarGrupo((int) $m[1], (int) $m[2], cuerpo()); $nota('partida_unificada_editada', $p['id'], ['grupo' => (int) $m[2]]); responder(200, ['ok' => true, 'presupuesto' => $p]); }
        if ($metodo === 'DELETE') { $p = Presupuestos::separarGrupo((int) $m[1], (int) $m[2]); $nota('partidas_separadas', $p['id'], ['grupo' => (int) $m[2]]); responder(200, ['ok' => true, 'presupuesto' => $p]); }
    }
    if (preg_match('#^/presupuestos/(\d+)/confirmar-dato$#', $ruta, $m) && $metodo === 'POST') {
        $clave = (string) (cuerpo()['clave'] ?? '');
        $p = Presupuestos::confirmarDato((int) $m[1], $clave);
        $nota('dato_confirmado', $p['id'], ['clave' => $clave]);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/estado$#', $ruta, $m) && $metodo === 'POST') {
        $p = Presupuestos::cambiarEstado((int) $m[1], (string) (cuerpo()['estado'] ?? ''));
        $nota('estado_cambiado', $p['id'], ['estado' => $p['estado']]);
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/lineas$#', $ruta, $m) && $metodo === 'POST') {
        $c = cuerpo();
        $p = Presupuestos::agregarLinea((int) $m[1], $c);
        $nota('linea_anadida', $p['id'], array_intersect_key($c, ['fuente' => 1, 'id' => 1, 'tipo' => 1, 'concepto' => 1, 'cantidad' => 1, 'precio_unitario' => 1]));
        responder(201, ['ok' => true, 'presupuesto' => $p]);
    }
    if (preg_match('#^/presupuestos/(\d+)/lineas/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') {
        $c = cuerpo();
        $p = Presupuestos::ajustarLinea((int) $m[1], (int) $m[2], $c);
        $nota('linea_ajustada', $p['id'], ['linea' => (int) $m[2]] + array_intersect_key($c, ['cantidad' => 1, 'precio_unitario' => 1, 'estado' => 1, 'elegir' => 1]));
        responder(200, ['ok' => true, 'presupuesto' => $p]);
    }

    // ---- Catálogo (para buscar a mano y poder corregir líneas) ----
    if (in_array($ruta, ['/servicios', '/productos'], true) && $metodo === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') throw new ErrorApi('Falta ?q= con lo que buscas.', 400);
        $req = ['concepto' => $q, 'busqueda' => $q, 'sinonimos' => []];
        $palabras = Catalogo::palabrasBusqueda($q);   // búsqueda manual: solo sale lo que contiene TODAS las palabras escritas
        if ($ruta === '/servicios') responder(200, ['ok' => true, 'resultados' => Catalogo::busquedaManual('servicio', $q, 30)]);
        // Con ?novelec=1 se busca también en Novelec (más lento: entra en la web con tu usuario); sus productos van primero
        // «origen»: «web» = recién sacado de la web de Novelec (precio de profesional de hoy); «catalogo» = lo que ya estaba guardado
        $web = [];
        if (($_GET['novelec'] ?? '') === '1' && Novelec::disponible()) {
            $web = array_map(fn($c) => $c + ['origen' => 'web'], array_values(array_filter(array_merge(...array_map(fn($consulta) => Novelec::buscar(['concepto' => $consulta, 'busqueda' => $consulta, 'sinonimos' => []], 14), Catalogo::consultasWeb($q))), fn($c) => Catalogo::cumpleTodas($palabras, $c['nombre'] . ' ' . $c['codigo']))));
        }
        $local = array_map(fn($c) => $c + ['origen' => 'catalogo'], Catalogo::busquedaManual('producto', $q, 40));
        $res = [];
        $vistos = [];
        foreach (array_merge($web, $local) as $c) {   // primero lo de la web; un mismo producto no sale dos veces
            $k = $c['fuente'] . ':' . $c['id'];
            if (isset($vistos[$k])) continue;
            $vistos[$k] = true;
            $res[] = $c;
        }
        responder(200, ['ok' => true, 'resultados' => $res]);
    }

    // ---- Ajustes (margen, IVA, umbrales): ver los ajustes, todos; cambiarlos, solo administradores ----
    if ($ruta === '/configuracion' && $metodo === 'GET') responder(200, ['ok' => true, 'ajustes' => Ajustes::todos()]);
    if (preg_match('#^/configuracion/([a-z_]+)$#', $ruta, $m) && $metodo === 'PUT') {
        Auth::exigirAdmin($yo);
        $v = cuerpo()['valor'] ?? null;
        if (!is_scalar($v) || (trim((string) $v) === '' && !in_array($m[1], ['proveedor_producto', 'proveedor_fotovoltaica'], true))) throw new ErrorApi('Falta «valor».', 400);   // las listas de proveedores pueden quedar vacías
        $antes = Ajustes::texto($m[1]);
        Ajustes::guardar($m[1], trim((string) $v));
        Eventos::anotar($yo, 'ajuste_cambiado', 'configuracion', null, ['clave' => $m[1], 'antes' => $antes, 'despues' => trim((string) $v)]);
        responder(200, ['ok' => true, 'ajustes' => Ajustes::todos()]);
    }

    // ---- Proveedores de material (para elegir los preferidos en Ajustes) ----
    if ($ruta === '/proveedores' && $metodo === 'GET') responder(200, ['ok' => true, 'proveedores' => Productos::proveedores()]);
    // Los productos de un proveedor (con el motivo si no se usan en los presupuestos) y traer productos nuevos de la web de Novelec
    if ($ruta === '/productos/catalogo' && $metodo === 'GET') responder(200, ['ok' => true] + Productos::listar((string) ($_GET['proveedor'] ?? ''), (string) ($_GET['q'] ?? ''), (int) ($_GET['pagina'] ?? 1), (int) ($_GET['limite'] ?? 30), ($_GET['foto'] ?? '') === '1'));
    if ($ruta === '/productos/importar-novelec' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(200, ['ok' => true] + Productos::importarNovelec((string) (cuerpo()['q'] ?? ''), $yo)); }

    // ---- Base de servicios: listar, añadir, editar y borrar (administradores) ----
    if ($ruta === '/base/servicios' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true] + BaseServicios::listar($_GET)); }
    if ($ruta === '/base/servicios' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'servicio' => BaseServicios::crear(cuerpo(), $yo)]); }
    if (preg_match('#^/base/servicios/(\d+)$#', $ruta, $m)) {
        Auth::exigirAdmin($yo);
        if ($metodo === 'GET') responder(200, ['ok' => true, 'servicio' => BaseServicios::obtener((int) $m[1])]);
        if ($metodo === 'PATCH') responder(200, ['ok' => true, 'servicio' => BaseServicios::actualizar((int) $m[1], cuerpo(), $yo)]);
        if ($metodo === 'DELETE') responder(200, ['ok' => true, 'eliminado' => BaseServicios::eliminar((int) $m[1], $yo)]);
    }

    // ---- Corregir la unidad de un producto en el catálogo (p. ej. Novelec lo guarda como «ud» y se vende por metro) ----
    if (preg_match('#^/productos/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') {
        Auth::exigirAdmin($yo);
        responder(200, ['ok' => true, 'producto' => Catalogo::cambiarUnidad((int) $m[1], (string) (cuerpo()['unidad'] ?? ''), $yo)]);
    }

    // ---- Clientes de «Servicios Clim» (solo lectura): buscar para rellenar los datos del cliente de un presupuesto ----
    if ($ruta === '/clientes' && $metodo === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        responder(200, ['ok' => true, 'disponible' => Clientes::disponible(), 'clientes' => $q === '' ? [] : Clientes::buscar($q)]);
    }

    // ---- Reglas de la empresa y productos/servicios fijados (solo administradores) ----
    if ($ruta === '/reglas' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'reglas' => Reglas::listar()]); }
    if ($ruta === '/reglas/revisar' && $metodo === 'POST') { Auth::exigirAdmin($yo); $d = cuerpo(); responder(200, ['ok' => true] + Reglas::revisar($d, isset($d['excluir_id']) ? (int) $d['excluir_id'] : null)); }
    if ($ruta === '/reglas' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'regla' => Reglas::crear(cuerpo(), $yo)]); }
    if (preg_match('#^/reglas/(\d+)$#', $ruta, $m)) {
        Auth::exigirAdmin($yo);
        if ($metodo === 'PATCH') responder(200, ['ok' => true, 'regla' => Reglas::actualizar((int) $m[1], cuerpo(), $yo)]);
        if ($metodo === 'DELETE') { Reglas::eliminar((int) $m[1], $yo); responder(200, ['ok' => true]); }
    }
    if ($ruta === '/fijados' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true, 'fijados' => Fijados::listar()]); }
    if (preg_match('#^/fijados/(\d+)$#', $ruta, $m) && $metodo === 'DELETE') { Auth::exigirAdmin($yo); Fijados::eliminar((int) $m[1], $yo); responder(200, ['ok' => true]); }
    if (preg_match('#^/presupuestos/(\d+)/lineas/(\d+)/fijar$#', $ruta, $m) && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'fijado' => Fijados::fijar((int) $m[1], (int) $m[2], $yo)]); }

    // ---- Trabajos tipo: partidas unificadas guardadas (solo los administradores las gestionan) ----
    if ($ruta === '/trabajos-tipo' && $metodo === 'GET') responder(200, ['ok' => true, 'trabajos' => TrabajosTipo::listar()]);
    if (preg_match('#^/trabajos-tipo/(\d+)/lineas(?:/(\d+))?$#', $ruta, $m)) {
        Auth::exigirAdmin($yo);
        if (!isset($m[2]) && $metodo === 'POST') responder(201, ['ok' => true, 'trabajo' => TrabajosTipo::anadirLinea((int) $m[1], cuerpo(), $yo)]);
        if (isset($m[2]) && $metodo === 'PATCH') responder(200, ['ok' => true, 'trabajo' => TrabajosTipo::cambiarLinea((int) $m[1], (int) $m[2], cuerpo(), $yo)]);
        if (isset($m[2]) && $metodo === 'DELETE') responder(200, ['ok' => true, 'trabajo' => TrabajosTipo::quitarLinea((int) $m[1], (int) $m[2], $yo)]);
    }
    if (preg_match('#^/trabajos-tipo/(\d+)$#', $ruta, $m)) {
        Auth::exigirAdmin($yo);
        if ($metodo === 'PATCH') responder(200, ['ok' => true, 'trabajo' => TrabajosTipo::actualizar((int) $m[1], cuerpo(), $yo)]);
        if ($metodo === 'DELETE') { TrabajosTipo::eliminar((int) $m[1], $yo); responder(200, ['ok' => true]); }
    }
    if (preg_match('#^/presupuestos/(\d+)/grupos/(\d+)/guardar-tipo$#', $ruta, $m) && $metodo === 'POST') {
        Auth::exigirAdmin($yo);
        responder(201, ['ok' => true, 'trabajo' => TrabajosTipo::guardar((int) $m[1], (int) $m[2], $yo)]);
    }
    if (preg_match('#^/presupuestos/(\d+)/trabajo-tipo$#', $ruta, $m) && $metodo === 'POST') {
        $tid = (int) (cuerpo()['trabajo_id'] ?? 0);
        $p = Presupuestos::aplicarTrabajoTipo((int) $m[1], $tid);
        $nota('trabajo_tipo_anadido', (int) $m[1], ['trabajo' => $tid]);
        responder(201, ['ok' => true, 'presupuesto' => $p, 'omitidas' => $p['omitidas']]);
    }

    // ---- Sinónimos: a mano, o propuestos por la IA y aprobados por un administrador ----
    if ($ruta === '/sinonimos' && $metodo === 'GET') { Auth::exigirAdmin($yo); responder(200, ['ok' => true] + Sinonimos::listar()); }
    if ($ruta === '/sinonimos' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(201, ['ok' => true, 'sinonimo' => Sinonimos::crear(cuerpo(), $yo)]); }
    if ($ruta === '/sinonimos/sugerir' && $metodo === 'POST') { Auth::exigirAdmin($yo); responder(200, ['ok' => true] + Sinonimos::sugerir($yo)); }
    if (preg_match('#^/sinonimos/(\d+)$#', $ruta, $m)) {
        Auth::exigirAdmin($yo);
        if ($metodo === 'PATCH') responder(200, ['ok' => true, 'sinonimo' => Sinonimos::actualizar((int) $m[1], cuerpo(), $yo)]);
        if ($metodo === 'DELETE') { Sinonimos::eliminar((int) $m[1], $yo); responder(200, ['ok' => true]); }
    }
    if (preg_match('#^/sinonimos/sugeridos/(\d+)/(aprobar|rechazar)$#', $ruta, $m) && $metodo === 'POST') {
        Auth::exigirAdmin($yo);
        if ($m[2] === 'aprobar') responder(200, ['ok' => true, 'sinonimo' => Sinonimos::aprobar((int) $m[1], cuerpo(), $yo)]);
        Sinonimos::rechazar((int) $m[1]);
        responder(200, ['ok' => true]);
    }

    // ---- Aprendizaje: lo que han corregido los técnicos ----
    if ($ruta === '/correcciones' && $metodo === 'GET') {   // solo administradores: es el material para afinar la IA
        Auth::exigirAdmin($yo);
        $filas = Db::filas('SELECT c.*, p.numero FROM correcciones c LEFT JOIN presupuestos p ON p.id = c.presupuesto_id ORDER BY c.id DESC LIMIT ' . max(1, min(500, (int) ($_GET['limite'] ?? 100))));
        $dec = fn(?string $v) => ($v !== null && str_starts_with($v, '{') && is_array($j = json_decode($v, true))) ? $j : $v;
        responder(200, ['ok' => true, 'correcciones' => array_map(fn($c) => ['id' => (int) $c['id'], 'presupuesto_id' => $c['presupuesto_id'] === null ? null : (int) $c['presupuesto_id'], 'numero' => $c['numero'],
            'tipo' => $c['tipo'], 'anterior' => $dec($c['valor_anterior']), 'nuevo' => $dec($c['valor_nuevo']), 'comentario' => $c['comentario'], 'ignorar' => (bool) $c['ignorar'], 'created_at' => $c['created_at']], $filas)]);
    }
    // Marcar correcciones como «no aprender de esta» (p. ej. las hechas probando)
    if ($ruta === '/correcciones/ignorar-todas' && $metodo === 'POST') {
        Auth::exigirAdmin($yo);
        $n = Db::ejecutar('UPDATE correcciones SET ignorar = 1 WHERE ignorar = 0');
        Eventos::anotar($yo, 'correcciones_ignoradas', 'correccion', null, ['cuantas' => $n]);
        responder(200, ['ok' => true, 'ignoradas' => $n]);
    }
    if (preg_match('#^/correcciones/(\d+)$#', $ruta, $m) && $metodo === 'PATCH') {
        Auth::exigirAdmin($yo);
        $d = cuerpo();
        if (!array_key_exists('ignorar', $d)) throw new ErrorApi('Falta «ignorar» (true o false).', 400);
        if (!Db::fila('SELECT id FROM correcciones WHERE id = ?', [(int) $m[1]])) throw new ErrorApi('No existe esa corrección.', 404);
        Db::ejecutar('UPDATE correcciones SET ignorar = ? WHERE id = ?', [$d['ignorar'] ? 1 : 0, (int) $m[1]]);
        responder(200, ['ok' => true]);
    }

    throw new ErrorApi("No existe la ruta $metodo $ruta.", 404);
} catch (ErrorApi $e) {
    responder($e->http, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[presupuestos] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    responder(500, ['ok' => false, 'error' => Config::get('depurar') ? $e->getMessage() : 'Error interno. Revisa el registro del servidor.']);
}
