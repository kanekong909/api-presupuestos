<?php
// Crea un usuario desde la consola, sin pasar la contraseña por ningún chat ni archivo:
//   php herramientas/crear_usuario.php carlos admin
//   php herramientas/crear_usuario.php maria tecnico
// Pide el nombre y la contraseña (esta no se ve al escribirla). Sirve también para el PRIMER administrador.
require dirname(__DIR__) . '/src/arranque.php';

$usuario = $argv[1] ?? '';
$rol = $argv[2] ?? 'tecnico';
if ($usuario === '') { fwrite(STDERR, "Uso: php herramientas/crear_usuario.php <usuario> [admin|tecnico]\n"); exit(1); }

function pedir(string $texto, bool $oculto = false): string
{
    if ($oculto && stripos(PHP_OS, 'WIN') === 0) {
        $ps = '$s = Read-Host -AsSecureString \'' . $texto . '\'; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))';
        return trim((string) shell_exec('powershell -NoProfile -Command "' . str_replace('"', '\"', $ps) . '"'));
    }
    if ($oculto) { echo "$texto: "; system('stty -echo'); $v = trim((string) fgets(STDIN)); system('stty echo'); echo "\n"; return $v; }
    echo "$texto: ";
    return trim((string) fgets(STDIN));
}

try {
    $nombre = pedir('Nombre completo');
    $email = pedir('Email (opcional, Intro para saltar)');
    $clave = pedir('Contraseña (mínimo 8 caracteres)', true);
    if ($clave !== pedir('Repite la contraseña', true)) { fwrite(STDERR, "Las contraseñas no coinciden.\n"); exit(1); }
    $u = Auth::crear(['usuario' => $usuario, 'nombre' => $nombre, 'email' => $email, 'clave' => $clave, 'rol' => $rol]);
    echo "Usuario creado: {$u['usuario']} ({$u['rol']}). Ya puedes entrar con POST /auth/login.\n";
} catch (ErrorApi $e) {
    fwrite(STDERR, 'No se ha podido crear: ' . $e->getMessage() . "\n");
    exit(1);
}
