<?php
// Prueba la conexión con Novelec con TU usuario (config › novelec) y enseña lo que encuentra:
//     php herramientas/probar_novelec.php "magnetotérmico 16A"
//
// Hace: 1) entra en la web, 2) busca ese texto, 3) enseña cada producto con TODOS los precios que ha visto y de qué elemento salen
// (así se comprueba cuál es el precio de profesional). NO guarda nada en la base y NO imprime la contraseña.
// Guarda la página de resultados en herramientas/novelec_busqueda.html por si hay que afinar la lectura.
require dirname(__DIR__) . '/src/arranque.php';

$q = $argv[1] ?? 'magnetotérmico 16A';
if (!Novelec::disponible()) { fwrite(STDERR, "Novelec no está configurado: rellena config › novelec (usuario y clave) y productos › fuente.\n"); exit(1); }

echo "1) Inicio de sesión… ";
$ok = Novelec::entrar();
echo $ok ? "OK (sesión iniciada)\n" : "NO ha funcionado: usuario o contraseña incorrectos, o la web ha cambiado.\n";
if (!$ok) {
    $p = Novelec::peticion('https://www.gruponovelec.com/login');
    echo "   La página de acceso responde HTTP {$p['http']}; campos: " . implode(', ', array_keys(Novelec::camposFormulario($p['cuerpo']))) . "\n";
    exit(1);
}

echo "2) Buscando «{$q}»…\n";
$r = Novelec::peticion('https://www.gruponovelec.com/search?text=' . rawurlencode($q));
file_put_contents(__DIR__ . '/novelec_busqueda.html', $r['cuerpo']);
echo "   HTTP {$r['http']}, " . strlen($r['cuerpo']) . " bytes\n";

$items = Novelec::parsear($r['cuerpo']);
echo '3) Productos leídos: ' . count($items) . "\n\n";
foreach (array_slice($items, 0, 8) as $i => $p) {
    echo ($i + 1) . '. ' . $p['ref'] . ' · ' . $p['marca'] . ' · ' . mb_substr($p['nombre'], 0, 70) . "\n";
    if (!$p['precios']) echo "     (sin precio visible)\n";
    echo '     → se usaría: ' . (($u = Novelec::precioProfesional($p['precios'])) === null ? 'NADA (no hay precio de usuario)' : number_format($u, 2, ',', '.') . ' €') . "
";
    foreach ($p['precios'] as $pr) echo '     ' . str_pad($pr['texto'], 12) . ' ← ' . ($pr['atributos'] ?: '(sin atributos)') . "\n";
}
echo "\nSi los precios de profesional salen distintos a los de la web sin sesión, está bien. Si solo sale «product-item__price-pvp», mándame esta salida.\n";
