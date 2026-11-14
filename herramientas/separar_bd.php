<?php
// SEPARAR LAS BASES DE DATOS — deja en la base de CATÁLOGO solo la tabla `productos` y copia todo lo demás (presupuestos, líneas, interpretaciones,
// correcciones, usuarios, reglas, notas, actividad…) a una base NUEVA solo para el sistema de presupuestos, que puede tener su PROPIO usuario de MySQL.
//
// PASOS (en tu PC, con config/config.php):
//   1. Añade en config.php un bloque TEMPORAL con los datos de la base nueva (al lado de «bd», que sigue apuntando a la de siempre):
//        'bd_nueva' => array('nombre' => 'u…_presupuestos', 'usuario' => 'u…_karlos', 'clave' => 'LA_CLAVE_DE_ESE_USUARIO'),
//      (el servidor «host» y el «puerto» son los de «bd» si no los pones.)
//   2. php herramientas/separar_bd.php            → ensayo: comprueba las dos bases y dice qué copiaría, sin tocar nada
//   3. php herramientas/separar_bd.php --copiar   → crea las tablas en la base nueva y copia los datos (la original NO se toca) y compara las filas
//   4. Cambia config.php: «bd» pasa a ser la base NUEVA (la de bd_nueva) y la de siempre pasa a un bloque «catalogo» (con SU usuario y clave).
//      Quita «bd_nueva». Prueba el sistema.
//   5. php herramientas/separar_bd.php --limpiar  → (solo cuando todo funciona) quita de la base de siempre las tablas ya copiadas. Deja solo `productos`.

require dirname(__DIR__) . '/src/arranque.php';

$orden = $argv[1] ?? '';
if (!in_array($orden, ['', '--copiar', '--limpiar'], true)) { fwrite(STDERR, "Uso: php herramientas/separar_bd.php [--copiar | --limpiar]\n"); exit(2); }

const SE_QUEDA = ['productos'];   // lo único que se queda en la base del catálogo
$ok = fn(string $t) => print("  OK    $t\n");
$mal = function (string $t): never { fwrite(STDERR, "  ERROR $t\n"); exit(1); };

/** Una conexión PDO con los datos de un bloque de config (el servidor y el puerto, los de «bd» si no se dicen). */
function conectar(array $c, string $nombreBloque): PDO
{
    $c += ['host' => Config::get('bd.host'), 'puerto' => Config::get('bd.puerto', 3306)];
    foreach (['nombre', 'usuario', 'clave'] as $k) if (empty($c[$k])) { fwrite(STDERR, "  ERROR Falta «$k» en el bloque «{$nombreBloque}» de config/config.php.\n"); exit(1); }
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['puerto'], $c['nombre']), $c['usuario'], $c['clave'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        return $pdo;
    } catch (PDOException $e) {
        fwrite(STDERR, "  ERROR No se puede entrar en «{$c['nombre']}» con el usuario «{$c['usuario']}» (bloque «{$nombreBloque}»): " . $e->getMessage() . "\n");
        exit(1);
    }
}

function tablas(PDO $pdo): array
{
    $base = $pdo->query('SELECT DATABASE() d')->fetch()['d'];
    $st = $pdo->prepare("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $st->execute([$base]);
    return array_column($st->fetchAll(), 't');
}
function filas(PDO $pdo, string $t): int { return (int) $pdo->query("SELECT COUNT(*) n FROM `$t`")->fetch()['n']; }

// ---------------------------------------------------------------------------------------------------------------------------------
if ($orden === '--limpiar') {
    // Ya con la configuración cambiada: «bd» es la base nueva y «catalogo» la de productos de siempre (de donde se quitan las tablas copiadas)
    if (!is_array(Config::get('catalogo')) || Config::get('bd_nueva') !== null) $mal('Para --limpiar, config.php tiene que tener «bd» = la base NUEVA, «catalogo» = la de productos de siempre, y ya no «bd_nueva».');
    $nueva = conectar((array) Config::get('bd'), 'bd');
    $vieja = conectar((array) Config::get('catalogo'), 'catalogo');
    $mover = array_values(array_diff(tablas($vieja), SE_QUEDA));
    if (!$mover) { echo "No queda nada que quitar: la base del catálogo ya solo tiene `productos`.\n"; exit(0); }
    echo "Comprobando que cada tabla está completa en la base nueva antes de quitarla de la de siempre…\n";
    $enNueva = tablas($nueva);
    foreach ($mover as $t) {
        if (!in_array($t, $enNueva, true)) $mal("La tabla «$t» no está en la base nueva: no quito nada.");
        $a = filas($vieja, $t); $b = filas($nueva, $t);
        if ($b < $a) $mal("«$t» tiene $a filas en la de siempre y solo $b en la nueva: no quito nada.");
    }
    echo "Todo está completo en la base nueva (" . count($mover) . " tablas).\n";
    $vieja->exec('SET FOREIGN_KEY_CHECKS = 0');
    try { foreach ($mover as $t) { $vieja->exec("DROP TABLE `$t`"); $ok("quitada de la base de siempre: $t"); } }
    finally { $vieja->exec('SET FOREIGN_KEY_CHECKS = 1'); }
    echo "\nListo: la base del catálogo ya solo tiene `productos`.\n";
    exit(0);
}

// ---- Ensayo y copia ----
$bdNueva = Config::get('bd_nueva');
if (!is_array($bdNueva)) $mal("Falta el bloque temporal «bd_nueva» en config/config.php (nombre, usuario y clave de la base nueva). Mira las instrucciones al principio de este archivo.");
if (is_array(Config::get('catalogo'))) $mal('config.php ya tiene un bloque «catalogo»: parece que las bases ya están separadas.');
if (($bdNueva['nombre'] ?? '') === Config::get('bd.nombre')) $mal('La base nueva no puede ser la misma que la actual.');

$origen = conectar((array) Config::get('bd'), 'bd');
$destino = conectar($bdNueva, 'bd_nueva');
echo "Base de siempre (catálogo): " . Config::get('bd.nombre') . "\nBase nueva (presupuestos): " . ($bdNueva['nombre']) . "\n\n";

$tablas = tablas($origen);
if (!in_array('productos', $tablas, true)) $mal('En la base actual no hay tabla `productos`: ¿es la base correcta?');
$ok('La tabla `productos` existe en la base de siempre (' . filas($origen, 'productos') . ' productos)');
$mover = array_values(array_diff($tablas, SE_QUEDA));
$ok(count($mover) . ' tablas para copiar');
if (tablas($destino)) $mal('La base nueva ya tiene tablas (' . implode(', ', tablas($destino)) . '): tiene que estar vacía para no mezclar nada.');
$ok('La base nueva es accesible con su usuario y está vacía');

$crear = [];
foreach ($mover as $t) {
    $c = array_values($origen->query("SHOW CREATE TABLE `$t`")->fetch())[1];
    if (stripos($c, 'GENERATED ALWAYS') !== false) $mal("La tabla «$t» tiene columnas generadas: este script no las copia. Avísame.");
    $crear[$t] = $c;
}
$cuentas = [];
foreach ($mover as $t) $cuentas[$t] = filas($origen, $t);

if ($orden === '') {
    echo "\nENSAYO (no se ha tocado nada). Se copiarían estas tablas:\n";
    foreach ($cuentas as $t => $n) echo sprintf("  %-24s %6d filas\n", $t, $n);
    echo "\nSi todo está bien: php herramientas/separar_bd.php --copiar\n";
    exit(0);
}

// ---- Copiar ----
echo "\nCopiando…\n";
$destino->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($mover as $t) {
        $destino->exec($crear[$t]);   // la misma definición: columnas, índices y claves foráneas
        $st = $origen->query("SELECT * FROM `$t`");
        $lote = [];
        $volcar = function () use (&$lote, $destino, $t) {
            if (!$lote) return;
            $cols = '`' . implode('`,`', array_keys($lote[0])) . '`';
            $uno = '(' . implode(',', array_fill(0, count($lote[0]), '?')) . ')';
            $ins = $destino->prepare("INSERT INTO `$t` ($cols) VALUES " . implode(',', array_fill(0, count($lote), $uno)));
            $ins->execute(array_merge(...array_map('array_values', $lote)));
            $lote = [];
        };
        while ($f = $st->fetch()) { $lote[] = $f; if (count($lote) >= 200) $volcar(); }
        $volcar();
        $auto = $origen->query("SELECT AUTO_INCREMENT a FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $origen->quote($t))->fetch()['a'] ?? null;
        if ($auto) $destino->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . (int) $auto);   // los números de identificación siguen donde iban
        $ok(sprintf('%-24s %d filas', $t, $cuentas[$t]));
    }
} finally {
    $destino->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "\nComprobando…\n";
$fallos = 0;
foreach ($cuentas as $t => $n) {
    $nn = filas($destino, $t);
    echo sprintf("  %-24s original %6d   copia %6d   %s\n", $t, $n, $nn, $n === $nn ? 'igual' : 'DISTINTO');
    if ($n !== $nn) $fallos++;
}
if ($fallos) { fwrite(STDERR, "\n$fallos tabla(s) no coinciden. No cambies la configuración; avísame.\n"); exit(1); }
echo "\nCopia terminada y comprobada. La base de siempre NO se ha tocado.\n";
echo "Siguiente paso: en config/config.php, «bd» pasa a ser la base nueva (los datos de «bd_nueva»), la de siempre pasa a un bloque «catalogo» con su usuario y clave, y se quita «bd_nueva». Prueba el sistema y, cuando todo funcione, --limpiar.\n";
