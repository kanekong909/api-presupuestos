<?php
// COMPARAR MODELOS DE IA — hace los MISMOS pedidos de ejemplo con distintos modelos y compara lo que entienden, lo que eligen, el precio, el tiempo y lo que cuesta.
//   php herramientas/comparar_modelos.php [modelo1,modelo2,…] [numeros_de_ejemplo]      (por defecto: haiku, sonnet y opus; todos los ejemplos)
//   php herramientas/comparar_modelos.php claude-haiku-5-5,claude-sonnet-5-5 1,2
// Cada prueba crea un presupuesto, lo calcula y lo BORRA; el contador de números se deja como estaba. Nunca entra en Novelec (solo el catálogo guardado).
// Gasta dinero real de la cuenta de Anthropic: unos céntimos por prueba. El resultado completo queda en comparar_modelos_resultado.json.

require dirname(__DIR__) . '/src/arranque.php';

const PRECIOS = [   // dólares por millón de tokens (entrada, salida), de la lista de precios de Anthropic
    'claude-haiku-5-5' => [0.10, 0.50], 'claude-sonnet-5-5' => [2.00, 10.00], 'claude-opus-5-5' => [4.00, 20.00], 'claude-fable-5-2' => [8.00, 40.00],
];
const EJEMPLOS = [
    1 => 'Instalar un cargador de coche de 7 kW, monofásico, el cuadro está a 15 metros',
    2 => 'Cambiar el cuadro eléctrico de un piso: poner un diferencial de 40 A y 4 magnetotérmicos de 16 A',
    3 => 'Instalación de aire acondicionado split de 3.500 frigorías en el salón, con unos 4 metros de tubería entre unidades',
    4 => 'Instalación fotovoltaica de unos 5 kW para una vivienda, con 10 paneles y un inversor, sobre tejado',
    5 => 'Necesito una luz nueva en el garaje y un enchufe para la lavadora',
];

$modelos = array_values(array_filter(explode(',', $argv[1] ?? 'claude-haiku-5-5,claude-sonnet-5-5,claude-opus-5-5')));
$numeros = isset($argv[2]) ? array_map('intval', explode(',', $argv[2])) : array_keys(EJEMPLOS);
$base = Config::todo();
$adm = ['id' => null, 'usuario' => 'comparar', 'rol' => 'admin'];
$corr0 = Numeracion::ultimo();
$resultados = [];

foreach ($numeros as $n) {
    foreach ($modelos as $modelo) {
        // Solo cambia el modelo (los dos usos) y se evita Novelec en directo: lo demás es idéntico para todos
        Config::poner(array_replace_recursive($base, ['productos' => ['fuente' => 'local'], 'ia' => ['usos' => ['interprete' => ['modelo' => $modelo], 'emparejar' => ['modelo' => $modelo]]]]));
        Claude::$consumo = [];
        $id = null;
        $r = ['ejemplo' => $n, 'modelo' => $modelo, 'pedido' => EJEMPLOS[$n]];
        $t0 = microtime(true);
        try {
            $p = Presupuestos::crear(['texto' => EJEMPLOS[$n], 'cliente' => 'ZZ comparar']);
            $id = $p['id'];
            $r['entendido'] = $p['resumen_interpretado'];
            $r['preguntas'] = array_map(fn($x) => is_array($x) ? ($x['pregunta'] ?? json_encode($x, JSON_UNESCAPED_UNICODE)) : (string) $x, $p['datos_pendientes']);
            $p = Presupuestos::confirmar($id);
            $r['lineas'] = array_map(fn($l) => ['pedido' => $l['concepto'], 'tipo' => $l['tipo'], 'cantidad' => $l['cantidad'], 'unidad' => $l['unidad'], 'importe' => $l['importe'], 'estado' => $l['estado'], 'confianza' => $l['confianza'], 'fuente' => $l['fuente'], 'codigo' => $l['codigo'], 'regla' => $l['regla'] ?? null], $p['lineas']);
            $r['total'] = $p['total'];
            $r['sin_precio'] = count(array_filter($p['lineas'], fn($l) => $l['importe'] <= 0));
        } catch (Throwable $e) {
            $r['error'] = $e->getMessage();
        }
        $r['segundos'] = round(microtime(true) - $t0, 1);
        $r['llamadas'] = count(Claude::$consumo);
        $r['tokens_entrada'] = array_sum(array_column(Claude::$consumo, 'entrada'));
        $r['tokens_salida'] = array_sum(array_column(Claude::$consumo, 'salida'));
        [$pe, $ps] = PRECIOS[$modelo] ?? [0, 0];
        $r['coste_usd'] = round(($r['tokens_entrada'] * $pe + $r['tokens_salida'] * $ps) / 1_000_000, 4);
        if ($id) { try { Presupuestos::eliminar($id, $adm); } catch (Throwable $e) { fwrite(STDERR, "No se pudo borrar el presupuesto de prueba $id: " . $e->getMessage() . "\n"); } }
        $resultados[] = $r;
        printf("Ejemplo %d · %-18s %5.1fs · %2d llamadas · %6d+%5d tokens · %.3f USD · total %s%s\n", $n, $modelo, $r['segundos'], $r['llamadas'], $r['tokens_entrada'], $r['tokens_salida'], $r['coste_usd'], isset($r['total']) ? number_format($r['total'], 2) . ' €' : '—', isset($r['error']) ? ' · ERROR: ' . $r['error'] : '');
        file_put_contents(__DIR__ . '/comparar_modelos_resultado.json', json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
Config::poner($base);
Numeracion::restaurar($corr0);
// Limpieza por si algo se quedó (presupuestos de la prueba con el cliente marcado)
foreach (Db::filas("SELECT id FROM presupuestos WHERE cliente = 'ZZ comparar'") as $f) { try { Presupuestos::eliminar((int) $f['id'], $adm); } catch (Throwable $e) {} }
echo "\nListo. Presupuestos de prueba borrados y contador de números como estaba (" . Numeracion::ultimo() . ").\n";
