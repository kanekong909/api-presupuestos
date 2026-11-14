<?php
// Prueba con la IA REAL (gasta unos pocos tokens):  php pruebas/real.php "texto del pedido"
// Crea el presupuesto, lo confirma, lo enseña y lo BORRA.
require dirname(__DIR__) . '/src/arranque.php';
if (!in_array('--novelec', $argv, true)) Config::poner(array_replace(Config::todo(), ['productos' => ['fuente' => 'local', 'proveedores' => []]]));   // con --novelec también busca en Novelec
$argv = array_values(array_filter($argv, fn($a) => $a !== '--novelec'));

$texto = $argv[1] ?? 'Hola, quiero instalar un cargador de coche eléctrico de unos 7 kW, preferiblemente V2C, en mi vivienda unifamiliar. Estará a unos 20 m del cuadro. Incluid las protecciones.';
$id = null;
try {
    $p = Presupuestos::crear(['texto' => $texto, 'cliente' => 'Prueba real']);
    $id = $p['id'];
    echo "== INTERPRETACIÓN ==\n{$p['resumen_interpretado']}\n";
    foreach ($p['interpretacion']['datos']['requerimientos'] as $r) echo "  [{$r['tipo']}] {$r['concepto']} · cant " . ($r['cantidad'] ?? '?') . " {$r['unidad']}\n";
    foreach ($p['datos_pendientes'] as $d) echo "  ? {$d['pregunta']}\n";

    $p = Presupuestos::confirmar($id);
    echo "\n== {$p['numero']} · {$p['tipo_presupuesto']} · {$p['estado']} ==\n";
    foreach ($p['lineas'] as $l) {
        printf("%-24s %-9s x%-5s %9.2f €  conf %s\n   %s\n", $l['estado'], $l['tipo'], $l['cantidad'] + 0, $l['importe'], $l['confianza'] + 0, mb_substr($l['concepto'], 0, 90));
        if ($l['aviso']) echo "   ⚠ {$l['aviso']}\n";
    }
    printf("\nSubtotal %.2f · IVA %.2f · TOTAL %.2f\n", $p['subtotal'], $p['iva'], $p['total']);
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
} finally {
    if ($id) foreach (['presupuesto_lineas' => 'presupuesto_id', 'correcciones' => 'presupuesto_id', 'interpretaciones' => 'presupuesto_id', 'presupuestos' => 'id'] as $t => $c) Db::ejecutar("DELETE FROM $t WHERE $c = ?", [$id]);
}
