<?php
// AUTH — usuarios, inicio de sesión y roles. Sesiones con token (cabecera «Authorization: Bearer …»).
// Roles: admin (todo, incluido usuarios, ajustes y registro de actividad) y tecnico (presupuestos).

final class Auth
{
    public const ROLES = ['admin', 'tecnico'];
    private const MAX_FALLOS = 5;      // intentos fallidos…
    private const VENTANA_MIN = 15;    // …en estos minutos bloquean el acceso un rato

    // ---------- Inicio y cierre de sesión ----------

    public static function entrar(string $usuario, string $clave, string $agente = ''): array
    {
        $usuario = mb_strtolower(trim($usuario));
        if ($usuario === '' || $clave === '') throw new ErrorApi('Escribe el usuario y la contraseña.', 400);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $fallos = (int) Db::fila("SELECT COUNT(*) n FROM eventos WHERE accion = 'login_fallido' AND created_at > (NOW() - INTERVAL " . self::VENTANA_MIN . " MINUTE) AND (usuario = ? OR ip = ?)", [$usuario, $ip])['n'];
        if ($fallos >= self::MAX_FALLOS) throw new ErrorApi('Demasiados intentos fallidos. Espera ' . self::VENTANA_MIN . ' minutos.', 429);

        $u = Db::fila('SELECT * FROM usuarios WHERE usuario = ? OR email = ?', [$usuario, $usuario]);
        // Se comprueba siempre un hash (aunque el usuario no exista) para que el tiempo no delate qué usuarios hay
        $valido = password_verify($clave, $u['clave_hash'] ?? '$2y$10$usesomesillystringforsaltabcdefghijklmnopqrstuvwxyz12');
        if (!$u || !$valido || !(int) $u['activo']) {
            Eventos::anotar(null, 'login_fallido', 'usuario', $u ? (int) $u['id'] : null, ['usuario' => $usuario]);
            throw new ErrorApi($u && $valido && !(int) $u['activo'] ? 'Esta cuenta está desactivada.' : 'Usuario o contraseña incorrectos.', 401);
        }
        if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) Db::actualizar('usuarios', (int) $u['id'], ['clave_hash' => password_hash($clave, PASSWORD_DEFAULT)]);

        Db::ejecutar('DELETE FROM tokens WHERE expira < NOW()');
        $token = bin2hex(random_bytes(32));
        $dias = max(1, (int) Config::get('sesion_dias', 30));
        Db::ejecutar('INSERT INTO tokens (usuario_id, token_hash, expira, agente) VALUES (?, ?, NOW() + INTERVAL ' . $dias . ' DAY, ?)', [(int) $u['id'], hash('sha256', $token), mb_substr($agente, 0, 255)]);
        Db::actualizar('usuarios', (int) $u['id'], ['ultimo_acceso' => date('Y-m-d H:i:s')]);
        Eventos::anotar($u, 'login', 'usuario', (int) $u['id']);
        return ['token' => $token, 'expira_en_dias' => $dias, 'usuario' => self::publico($u)];
    }

    public static function salir(string $token, array $yo): void
    {
        Db::ejecutar('DELETE FROM tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        Eventos::anotar($yo, 'logout', 'usuario', (int) $yo['id']);
    }

    /** El usuario dueño de un token válido (y activo), o null. */
    public static function identificar(?string $token): ?array
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $u = Db::fila('SELECT u.*, t.id AS token_id FROM tokens t JOIN usuarios u ON u.id = t.usuario_id WHERE t.token_hash = ? AND t.expira > NOW() AND u.activo = 1', [hash('sha256', $token)]);
        if ($u) Db::ejecutar('UPDATE tokens SET ultimo_uso = NOW() WHERE id = ?', [(int) $u['token_id']]);
        return $u;
    }

    public static function exigirAdmin(array $yo): void
    {
        if (($yo['rol'] ?? '') !== 'admin') throw new ErrorApi('Solo un administrador puede hacer esto.', 403);
    }

    // ---------- Usuarios ----------

    public static function hayUsuarios(): bool
    {
        return (int) Db::fila('SELECT COUNT(*) n FROM usuarios')['n'] > 0;
    }

    /** Crea un usuario. $por = quién lo crea (null en el primer administrador). */
    public static function crear(array $d, ?array $por = null): array
    {
        $usuario = mb_strtolower(trim((string) ($d['usuario'] ?? '')));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) throw new ErrorApi('El usuario: de 3 a 40 caracteres, solo letras, números, punto, guion y guion bajo.', 400);
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 120) throw new ErrorApi('Falta el nombre (máximo 120 caracteres).', 400);
        $rol = (string) ($d['rol'] ?? 'tecnico');
        if (!in_array($rol, self::ROLES, true)) throw new ErrorApi('Rol no válido. Opciones: ' . implode(', ', self::ROLES) . '.', 400);
        $email = self::email($d['email'] ?? null);
        self::validarClave((string) ($d['clave'] ?? ''));
        if (Db::fila('SELECT id FROM usuarios WHERE usuario = ?', [$usuario])) throw new ErrorApi('Ya existe un usuario con ese nombre.', 409);
        if ($email !== null && Db::fila('SELECT id FROM usuarios WHERE email = ?', [$email])) throw new ErrorApi('Ya existe un usuario con ese email.', 409);

        $id = Db::insertar('usuarios', ['usuario' => $usuario, 'nombre' => $nombre, 'email' => $email, 'rol' => $rol, 'clave_hash' => password_hash((string) $d['clave'], PASSWORD_DEFAULT)]);
        Eventos::anotar($por, 'usuario_creado', 'usuario', $id, ['usuario' => $usuario, 'rol' => $rol]);
        return self::publico(Db::fila('SELECT * FROM usuarios WHERE id = ?', [$id]));
    }

    public static function actualizar(int $id, array $c, array $yo): array
    {
        $u = Db::fila('SELECT * FROM usuarios WHERE id = ?', [$id]);
        if (!$u) throw new ErrorApi('No existe ese usuario.', 404);
        $nuevo = [];
        if (isset($c['nombre'])) { $n = trim((string) $c['nombre']); if ($n === '') throw new ErrorApi('El nombre no puede estar vacío.', 400); $nuevo['nombre'] = $n; }
        if (array_key_exists('email', $c)) {
            $nuevo['email'] = self::email($c['email']);
            if ($nuevo['email'] !== null && Db::fila('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$nuevo['email'], $id])) throw new ErrorApi('Ya existe un usuario con ese email.', 409);
        }
        if (isset($c['rol'])) { if (!in_array($c['rol'], self::ROLES, true)) throw new ErrorApi('Rol no válido.', 400); $nuevo['rol'] = $c['rol']; }
        if (isset($c['activo'])) $nuevo['activo'] = $c['activo'] ? 1 : 0;
        if (isset($c['clave'])) { self::validarClave((string) $c['clave']); $nuevo['clave_hash'] = password_hash((string) $c['clave'], PASSWORD_DEFAULT); }
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar.', 400);

        // Siempre tiene que quedar al menos un administrador activo
        $dejaDeSerAdmin = $u['rol'] === 'admin' && (int) $u['activo'] === 1 && (($nuevo['rol'] ?? 'admin') !== 'admin' || ($nuevo['activo'] ?? 1) === 0);
        if ($dejaDeSerAdmin && (int) Db::fila("SELECT COUNT(*) n FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?", [$id])['n'] === 0) {
            throw new ErrorApi('Tiene que quedar al menos un administrador activo.', 409);
        }
        Db::actualizar('usuarios', $id, $nuevo);
        if (isset($nuevo['clave_hash']) || ($nuevo['activo'] ?? 1) === 0) Db::ejecutar('DELETE FROM tokens WHERE usuario_id = ?', [$id]);   // se cierran sus sesiones
        Eventos::anotar($yo, 'usuario_modificado', 'usuario', $id, ['cambios' => array_merge(array_keys(array_diff_key($nuevo, ['clave_hash' => 1])), isset($nuevo['clave_hash']) ? ['clave'] : [])]);
        return self::publico(Db::fila('SELECT * FROM usuarios WHERE id = ?', [$id]));
    }

    /** Cambio de contraseña por el propio usuario: pide la actual y cierra sus otras sesiones. */
    public static function cambiarClave(array $yo, string $actual, string $nueva, string $tokenActual): void
    {
        if (!password_verify($actual, (string) $yo['clave_hash'])) throw new ErrorApi('La contraseña actual no es correcta.', 400);
        self::validarClave($nueva);
        Db::actualizar('usuarios', (int) $yo['id'], ['clave_hash' => password_hash($nueva, PASSWORD_DEFAULT)]);
        Db::ejecutar('DELETE FROM tokens WHERE usuario_id = ? AND token_hash <> ?', [(int) $yo['id'], hash('sha256', $tokenActual)]);
        Eventos::anotar($yo, 'clave_cambiada', 'usuario', (int) $yo['id']);
    }

    public static function listar(): array
    {
        return array_map([self::class, 'publico'], Db::filas('SELECT * FROM usuarios ORDER BY nombre'));
    }

    /** Lo que se puede enseñar de un usuario (nunca el hash). */
    public static function publico(array $u): array
    {
        return ['id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol'], 'activo' => (bool) $u['activo'], 'ultimo_acceso' => $u['ultimo_acceso']];
    }

    private static function validarClave(string $c): void
    {
        if (mb_strlen($c) < 8) throw new ErrorApi('La contraseña tiene que tener al menos 8 caracteres.', 400);
        if (mb_strlen($c) > 200) throw new ErrorApi('La contraseña es demasiado larga.', 400);
    }

    private static function email(mixed $e): ?string
    {
        $e = mb_strtolower(trim((string) $e));
        if ($e === '') return null;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new ErrorApi('El email no es válido.', 400);
        return $e;
    }
}
