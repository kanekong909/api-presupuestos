<?php
// Pruebas de usuarios, inicio de sesión, roles y registro de actividad por HTTP (arranca su propio servidor PHP).
//   php pruebas/auth.php          (no llama a la IA y borra todo lo que crea)

require dirname(__DIR__) . '/src/arranque.php';

$puerto = 8098;
$url = "http://127.0.0.1:$puerto";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', RAIZ . '/public', RAIZ . '/public/index.php'], [1 => ['file', sys_get_temp_dir() . '/srv_auth_out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/srv_auth.log', 'w']], $pipes);
usleep(1_500_000);

$ok = $mal = 0;
function prueba(string $n, bool $c, string $d = ''): void { global $ok, $mal; $c ? $ok++ : $mal++; echo ($c ? '  ok   ' : '  FALLA ') . $n . ($c ? '' : " → $d") . "\n"; }
function http(string $metodo, string $ruta, ?array $cuerpo = null, array $cab = []): array
{
    global $url;
    $ch = curl_init($url . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cab),
        CURLOPT_POSTFIELDS => $cuerpo === null ? null : json_encode($cuerpo), CURLOPT_TIMEOUT => 20]);
    $r = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, json_decode($r, true) ?? ['crudo' => $r]];
}
$bearer = fn(string $t) => ["Authorization: Bearer $t"];
$limpiar = function () {
    Db::ejecutar("DELETE t FROM tokens t JOIN usuarios u ON u.id = t.usuario_id WHERE u.usuario LIKE 'zz\\_test\\_%'");
    Db::ejecutar("DELETE FROM eventos WHERE usuario LIKE 'zz\\_test\\_%'");
    Db::ejecutar("DELETE FROM eventos WHERE accion IN ('usuario_creado','usuario_modificado','clave_cambiada') AND detalle LIKE '%zz_test_%'");
    Db::ejecutar("DELETE FROM usuarios WHERE usuario LIKE 'zz\\_test\\_%'");
};
$limpiar();

try {
    $hay = Auth::hayUsuarios();
    echo "Acceso\n";
    [$c, $j] = http('GET', '/salud');
    prueba('/salud abierta y dice si falta el primer admin', $c === 200 && $j['necesita_primer_admin'] === !$hay);
    [$c] = http('GET', '/presupuestos');
    prueba('sin sesión → 401', $c === 401);
    [$c] = http('GET', '/presupuestos', null, $bearer(str_repeat('a', 64)));
    prueba('token inventado → 401', $c === 401);

    $admin = Auth::crear(['usuario' => 'zz_test_admin', 'nombre' => 'Admin Prueba', 'clave' => 'clave-segura-1', 'rol' => 'admin', 'email' => 'zz_test_admin@example.com']);
    prueba('crear usuario no devuelve el hash', !isset($admin['clave_hash']) && $admin['rol'] === 'admin');
    [$c] = http('POST', '/auth/primer-admin', ['usuario' => 'zz_test_otro', 'nombre' => 'x', 'clave' => '12345678']);
    prueba('primer-admin se cierra cuando ya hay usuarios', $c === 403);

    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'zz_test_admin', 'clave' => 'mala']);
    prueba('clave mala → 401', $c === 401 && !isset($j['token']));
    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'ZZ_test_admin ', 'clave' => 'clave-segura-1']);
    prueba('login correcto (sin importar mayúsculas) da token', $c === 200 && strlen($j['token'] ?? '') === 64 && $j['usuario']['usuario'] === 'zz_test_admin');
    $tAdmin = $j['token'];
    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'zz_test_admin@example.com', 'clave' => 'clave-segura-1']);
    prueba('también se entra con el email', $c === 200);
    prueba('solo se guarda el hash del token', (int) Db::fila('SELECT COUNT(*) n FROM tokens WHERE token_hash = ?', [$tAdmin])['n'] === 0 && (int) Db::fila('SELECT COUNT(*) n FROM tokens WHERE token_hash = ?', [hash('sha256', $tAdmin)])['n'] === 1);
    [$c, $j] = http('GET', '/auth/yo', null, $bearer($tAdmin));
    prueba('/auth/yo devuelve mi usuario sin hash', $c === 200 && $j['usuario']['nombre'] === 'Admin Prueba' && !isset($j['usuario']['clave_hash']));

    echo "Roles\n";
    [$c, $j] = http('POST', '/usuarios', ['usuario' => 'zz_test_tec', 'nombre' => 'Técnico Prueba', 'clave' => 'otra-clave-123'], $bearer($tAdmin));
    prueba('admin crea un técnico (rol por defecto)', $c === 201 && $j['usuario']['rol'] === 'tecnico');
    $idTec = $j['usuario']['id'];
    [$c] = http('POST', '/usuarios', ['usuario' => 'zz_test_tec', 'nombre' => 'Repe', 'clave' => 'otra-clave-123'], $bearer($tAdmin));
    prueba('usuario repetido → 409', $c === 409);
    [$c] = http('POST', '/usuarios', ['usuario' => 'zz_test_x', 'nombre' => 'X', 'clave' => 'corta'], $bearer($tAdmin));
    prueba('contraseña corta → 400', $c === 400);
    [$c] = http('POST', '/usuarios', ['usuario' => 'zz_test_x', 'nombre' => 'X', 'clave' => '12345678', 'rol' => 'jefe'], $bearer($tAdmin));
    prueba('rol inventado → 400', $c === 400);

    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'otra-clave-123']);
    $tTec = $j['token'] ?? '';
    prueba('el técnico entra', $c === 200);
    [$c] = http('GET', '/presupuestos', null, $bearer($tTec));
    prueba('técnico ve presupuestos', $c === 200);
    [$c] = http('GET', '/usuarios', null, $bearer($tTec));
    prueba('técnico no ve usuarios → 403', $c === 403);
    [$c] = http('GET', '/eventos', null, $bearer($tTec));
    prueba('técnico no ve el registro → 403', $c === 403);
    [$c] = http('PUT', '/configuracion/margen_producto', ['valor' => '0.5'], $bearer($tTec));
    prueba('técnico no cambia ajustes → 403', $c === 403);
    [$c] = http('POST', '/presupuestos', ['texto' => ''], $bearer($tTec));
    prueba('técnico pasa la autenticación en /presupuestos (falla por falta de texto)', $c === 400);

    echo "Clave de la API (entradas externas)\n";
    $claveApi = (string) Config::get('api_clave');
    [$c] = http('POST', '/presupuestos', ['texto' => ''], ['X-Api-Clave: ' . $claveApi]);
    prueba('la clave de la API vale para crear pedidos', $c === 400);
    [$c] = http('GET', '/presupuestos', null, ['X-Api-Clave: ' . $claveApi]);
    prueba('…pero no para ver nada más → 401', $c === 401);
    [$c] = http('POST', '/presupuestos', ['texto' => 'x'], ['X-Api-Clave: mala']);
    prueba('clave de la API mala → 401', $c === 401);

    echo "Gestión de usuarios\n";
    [$c, $j] = http('PATCH', "/usuarios/$idTec", ['nombre' => 'Técnico Nuevo', 'rol' => 'admin'], $bearer($tAdmin));
    prueba('admin edita nombre y rol', $c === 200 && $j['usuario']['rol'] === 'admin');
    http('PATCH', "/usuarios/$idTec", ['rol' => 'tecnico'], $bearer($tAdmin));
    [$c] = http('PATCH', "/usuarios/{$admin['id']}", ['activo' => false], $bearer($tAdmin));
    $otrosAdmins = (int) Db::fila("SELECT COUNT(*) n FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?", [$admin['id']])['n'];
    prueba('no se puede quitar al último administrador', $otrosAdmins > 0 || $c === 409);
    [$c] = http('PATCH', "/usuarios/$idTec", ['activo' => false], $bearer($tAdmin));
    [$c2] = http('GET', '/presupuestos', null, $bearer($tTec));
    prueba('desactivar cierra sus sesiones', $c === 200 && $c2 === 401);
    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'otra-clave-123']);
    prueba('desactivado no puede entrar', $c === 401);
    http('PATCH', "/usuarios/$idTec", ['activo' => true, 'clave' => 'clave-reset-999'], $bearer($tAdmin));
    [$c, $j] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'clave-reset-999']);
    prueba('admin restablece la contraseña', $c === 200);
    $tTec = $j['token'];

    echo "Cambiar mi contraseña\n";
    [$c] = http('POST', '/auth/clave', ['actual' => 'mala', 'nueva' => 'nueva-clave-456'], $bearer($tTec));
    prueba('con la actual mala → 400', $c === 400);
    [$c1, $j1] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'clave-reset-999']);   // otra sesión
    [$c] = http('POST', '/auth/clave', ['actual' => 'clave-reset-999', 'nueva' => 'nueva-clave-456'], $bearer($tTec));
    [$cOtra] = http('GET', '/auth/yo', null, $bearer($j1['token']));
    [$cMia] = http('GET', '/auth/yo', null, $bearer($tTec));
    prueba('cambia la clave, cierra las otras sesiones y mantiene esta', $c === 200 && $cOtra === 401 && $cMia === 200);
    [$c] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'nueva-clave-456']);
    prueba('entra con la nueva', $c === 200);

    echo "Cierre de sesión y bloqueo\n";
    [$c] = http('POST', '/auth/logout', null, $bearer($tTec));
    [$c2] = http('GET', '/auth/yo', null, $bearer($tTec));
    prueba('logout invalida el token', $c === 200 && $c2 === 401);
    for ($i = 0; $i < 5; $i++) http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'mala']);
    [$c] = http('POST', '/auth/login', ['usuario' => 'zz_test_tec', 'clave' => 'nueva-clave-456']);
    prueba('tras 5 fallos se bloquea unos minutos → 429', $c === 429);

    echo "Registro de actividad\n";
    Db::ejecutar("DELETE FROM eventos WHERE accion = 'login_fallido' AND usuario = 'zz_test_tec'");   // para poder seguir
    [$c, $j] = http('GET', '/eventos?usuario=zz_test_admin', null, $bearer($tAdmin));
    $acciones = array_column($j['eventos'] ?? [], 'accion');
    prueba('el admin ve los eventos (logins)', $c === 200 && in_array('login', $acciones, true));
    [$c, $j] = http('GET', '/eventos?accion=usuario_creado&limite=20', null, $bearer($tAdmin));
    prueba('quedan anotadas las altas de usuarios', $c === 200 && count($j['eventos']) > 0);
    prueba('los eventos no guardan contraseñas ni tokens', !str_contains((string) json_encode($j), 'clave-segura') && !str_contains((string) json_encode(Db::filas("SELECT detalle FROM eventos WHERE usuario LIKE 'zz\\_test\\_%'")), 'clave-'));
} finally {
    $limpiar();
    proc_terminate($proc);
    proc_close($proc);
}
echo "\n$ok bien, $mal mal\n";
exit($mal ? 1 : 0);
