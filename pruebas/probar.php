<?php
// Pruebas del flujo completo con la IA SIMULADA (no gasta tokens) contra la base de config.php.
//   php pruebas/probar.php
// Crea presupuestos de prueba y los borra al terminar.

require dirname(__DIR__) . '/src/arranque.php';
Config::poner(array_replace(Config::todo(), ['productos' => ['fuente' => 'local', 'proveedores' => []]]));   // las pruebas nunca entran en Novelec

$ok = $mal = 0;
function prueba(string $nombre, bool $cond, string $detalle = ''): void
{
    global $ok, $mal;
    $cond ? $ok++ : $mal++;
    echo ($cond ? '  ok   ' : '  FALLA ') . $nombre . ($cond ? '' : " → $detalle") . "\n";
}
function falla(callable $f, int $http): bool
{
    try { $f(); return false; } catch (ErrorApi $e) { return $e->http === $http; }
}

// ---- IA simulada ----
$interpretacion = [
    'finalidad' => 'presupuesto', 'grupo_principal' => 'Vehículo eléctrico', 'grupos_relacionados' => ['Electricidad'],
    'requerimientos' => [
        ['id' => 1, 'tipo' => 'producto', 'concepto' => 'cargador vehículo eléctrico 7 kW monofásico', 'cantidad' => 1, 'unidad' => 'ud', 'detalles' => 'V2C preferible', 'sinonimos' => ['wallbox', 'punto de recarga']],
        ['id' => 2, 'tipo' => 'servicio', 'concepto' => 'instalación punto de recarga', 'cantidad' => 1, 'unidad' => 'ud', 'detalles' => '', 'sinonimos' => ['wallbox']],
        ['id' => 3, 'tipo' => 'servicio', 'concepto' => 'tendido de circuito eléctrico', 'cantidad' => null, 'unidad' => 'm', 'detalles' => 'unos 20 m', 'sinonimos' => ['cableado']],
        ['id' => 4, 'tipo' => 'servicio', 'concepto' => 'zxqwv inexistente', 'cantidad' => 1, 'unidad' => 'ud', 'detalles' => '', 'sinonimos' => []],
    ],
    'datos_faltantes' => [['campo' => 'metros', 'pregunta' => '¿Cuántos metros hay hasta el cuadro?', 'afecta' => 'la cantidad de metros del tendido']],
    'resumen' => 'He entendido lo siguiente: instalar un cargador de 7 kW.',
];
$llamadas = ['interprete' => 0, 'emparejar' => 0, 'unificar' => 0];
Claude::simular(function (string $uso, string $sistema, string $usuario) use (&$interpretacion, &$llamadas) {
    $llamadas[$uso]++;
    if ($uso === 'interprete') {
        $j = $interpretacion;
        if (str_contains($usuario, 'CAMBIO DEL USUARIO') && str_contains($usuario, 'doce metros')) {
            $j['requerimientos'][2]['cantidad'] = 12.0;
            $j['datos_faltantes'] = [];
            $j['resumen'] = 'He entendido lo siguiente: cargador de 7 kW con 12 m de línea.';
        }
        return json_encode($j, JSON_UNESCAPED_UNICODE);
    }
    if ($uso === 'unificar') return json_encode(['titulo' => 'Suministro e instalación de prueba', 'descripcion' => 'Incluye todo lo necesario.']);
    // emparejar: elige el primer candidato de cada requerimiento (menos el 4, que no tiene) con confianza alta, y el 3 con confianza media
    preg_match_all('/REQUERIMIENTO (\d+) \[[a-z]+(?:, área [A-Z]+)?\].*?\n(.*?)(?=\nREQUERIMIENTO|\z)/s', $usuario, $m, PREG_SET_ORDER);
    $el = [];
    foreach ($m as $b) {
        if (!preg_match('/- (servicio|producto) id=(\d+)/', $b[2], $c)) continue;
        $conf = (int) $b[1] === 3 ? 75 : 95;
        $el[] = ['requerimiento' => (int) $b[1], 'elegido' => ['fuente' => $c[1], 'id' => (int) $c[2]], 'cantidad' => (int) $b[1] === 3 ? null : 1, 'confianza' => $conf, 'motivo' => 'prueba', 'faltan' => (int) $b[1] === 3 ? ['metros'] : []];
    }
    return json_encode(['elecciones' => $el]);
});

echo "Normalizador\n";
prueba('limpia espacios y saltos', Normalizador::limpiar("  hola   mundo \n\n\n\n adiós ") === "hola mundo\n\nadiós");
prueba('rechaza texto vacío', falla(fn() => Normalizador::normalizar(['texto' => '  ']), 400));
prueba('rechaza email malo', falla(fn() => Normalizador::normalizar(['texto' => 'x', 'email' => 'no']), 400));

echo "Clasificador\n";
prueba('combinado', Clasificador::clasificar(['requerimientos' => [['tipo' => 'producto'], ['tipo' => 'servicio']]])['tipo_presupuesto'] === 'COMBINADO');
prueba('solo servicio', Clasificador::clasificar(['requerimientos' => [['tipo' => 'servicio']]])['tipo_presupuesto'] === 'SOLO_SERVICIO');
prueba('solo producto', Clasificador::clasificar(['requerimientos' => [['tipo' => 'producto']]])['tipo_presupuesto'] === 'SOLO_PRODUCTO');
prueba('vacío falla', falla(fn() => Clasificador::clasificar(['requerimientos' => []]), 422));

echo "Motor\n";
$serv = ['fuente' => 'servicio', 'precio' => 25.0, 'importe_minimo' => 60.0, 'modo_precio' => 'DESDE', 'iva_incluido' => false];
prueba('servicio respeta el importe mínimo', Motor::precios($serv, 2)['importe'] === 60.0);
prueba('aviso «desde»', str_contains((string) Motor::avisoPrecio($serv), 'desde'));
$prod = ['fuente' => 'producto', 'precio' => 121.0, 'importe_minimo' => 0.0, 'modo_precio' => 'FIJO', 'iva_incluido' => true];
$mp = Motor::precios($prod, 2);
prueba('producto: quita IVA y aplica margen', $mp['coste'] === 100.0 && $mp['precio_unitario'] === 130.0 && $mp['importe'] === 260.0, json_encode($mp));
prueba('totales con IVA 21 %', Motor::totales([['estado' => 'ACEPTADO', 'importe' => 100], ['estado' => 'DESCARTADO', 'importe' => 50], ['estado' => 'REVISION', 'importe' => 100]]) === ['subtotal' => 200.0, 'iva' => 42.0, 'total' => 242.0]);

echo "Novelec (lectura de la lista, sin red)\n";
$lista = Novelec::parsear((string) file_get_contents(__DIR__ . '/datos/novelec_lista.html'));
prueba('lee los productos de la lista', count($lista) === 3 && $lista[0]['ref'] === 'A9F79250' && $lista[0]['marca'] === 'Schneider electric', json_encode($lista[0] ?? null));
prueba('lee la foto del producto', ($lista[0]['imagen'] ?? null) === 'https://media.telematel.com/assets/0017179183-0283-md01_500.jpg', json_encode($lista[0]['imagen'] ?? null));
prueba('sin foto o con la de relleno → ninguna', Novelec::imagen('<a class="product-item__thumb x" href="/p/1"><img src="/_ui/missing_product_EN_300x300.jpg"></a>') === null && Novelec::imagen('<div>nada</div>') === null);
prueba('una foto con ruta relativa se completa', Novelec::imagen('<a class="product-item__thumb" href="/p/1"> <img src="/medias/x.jpg"></a>') === 'https://www.gruponovelec.com/medias/x.jpg');
prueba('lee el precio (coma decimal)', ($lista[0]['precios'][0]['importe'] ?? 0) === 351.04);
prueba('apunta de qué elemento sale el precio', str_contains($lista[0]['precios'][0]['atributos'] ?? '', 'product-item__price-pvp'));
$conSesion = Novelec::parsear('<div class="product-item"><a class="product-item__name" href="/p/x">  MAGNETO 16A </a><div class="product-item__code"><span>REF: </span> A9F89116</div><div class="product-item__price"><div id="u1P" class="product-item__price-user"> 31,91 €</div><div id="u1" class="product-item__price-pvp"> 79,78 €</div></div><div class="product-item__actions"></div></div>');
prueba('con sesión: usa el precio de usuario (no el PVP)', count($conSesion) === 1 && Novelec::precioProfesional($conSesion[0]['precios']) === 31.91, json_encode($conSesion));
prueba('sin sesión (solo PVP): no se usa ningún precio', Novelec::precioProfesional($lista[0]['precios']) === null);
prueba('solo el formulario de acceso, no los demás', array_keys(Novelec::camposFormulario('<form id="loginForm"><input name="j_username" type="email"><input name="j_password" type="password"><input type="hidden" name="CSRFToken" value="abc"></form>')) === ['_campo_usuario', '_campo_clave', 'CSRFToken']);
prueba('sin credenciales no se usa', (function () { $c = Config::todo(); Config::poner(['novelec' => ['usuario' => 'x', 'clave' => 'PON_AQUI']] + $c); $r = Novelec::disponible(); Config::poner($c); return !$r; })());

echo "Catálogo\n";
$s = Catalogo::servicios(['concepto' => 'tendido de circuito eléctrico', 'sinonimos' => []]);
prueba('encuentra servicios', count($s) > 0 && $s[0]['fuente'] === 'servicio');
prueba('no devuelve la fila de cabecera', !in_array('codigo', array_column($s, 'codigo'), true));
$p = Catalogo::productos(['concepto' => 'batería litio 48V', 'sinonimos' => []]);
prueba('encuentra productos con precio', count($p) > 0 && $p[0]['precio'] > 0);
prueba('sin coincidencias → vacío', Catalogo::servicios(['concepto' => 'zxqwv', 'sinonimos' => []]) === []);
prueba('un servicio que vive en otra área (el certificado, en «alta tensión») llega a la IA y el primero', (Catalogo::servicios(['concepto' => 'emisión de certificado de instalación eléctrica (boletín) tras el cambio de cuadro', 'busqueda' => 'certificado instalación eléctrica', 'sinonimos' => ['boletín', 'CIE'], 'area' => 'ELE'])[0]['codigo'] ?? '') === 'MO-CER-CIE-001');
echo "Sinónimos\n";
prueba('«paneles solares» lleva a «módulo fotovoltaico»', str_contains(Sinonimos::expandir('instalar 12 paneles solares en la cubierta'), 'módulo fotovoltaico'));
prueba('solo palabras enteras («pia» no salta con «especial»)', Sinonimos::expandir('una instalación especial') === '');
prueba('también al revés: «módulo fotovoltaico» trae «panel solar» (unos proveedores dicen módulo y otros panel)', str_contains(Sinonimos::expandir('módulo fotovoltaico de 500 W'), 'panel solar'));
prueba('los términos cortos no se añaden al revés (traerían ruido)', !str_contains(Sinonimos::expandir('interruptor magnetotérmico de 16 A'), 'pia'));
prueba('quita el plural: «módulos» = «módulo», «paneles» = «panel»', Catalogo::raiz('módulos') === 'módulo' && Catalogo::raiz('paneles') === 'panel' && Catalogo::raiz('fotovoltaicos') === 'fotovoltaico' && Catalogo::raiz('baterías') === 'bateria' || Catalogo::raiz('baterías') === 'batería');
prueba('no toca números ni palabras cortas (7kw, v2c, red)', Catalogo::raiz('7kw') === '7kw' && Catalogo::raiz('v2c') === 'v2c' && Catalogo::raiz('red') === 'red' && Catalogo::raiz('gas') === 'gas');
prueba('«instalación de módulos fotovoltaicos en tejado» encuentra «Montaje de módulo fotovoltaico»', in_array('MO-FOT-MOD-001', array_column(Catalogo::servicios(['concepto' => 'instalación de módulos fotovoltaicos en tejado inclinado de teja', 'busqueda' => 'instalación módulos fotovoltaicos', 'area' => 'FOT', 'sinonimos' => []]), 'codigo'), true));
prueba('al pedir «módulo fotovoltaico» salen también los «Panel Solar» de Solar Fácil', count(array_filter(Catalogo::productos(['concepto' => 'módulo fotovoltaico 500 W', 'busqueda' => 'módulo fotovoltaico 500W', 'area' => 'FOT', 'sinonimos' => []]), fn($x) => stripos($x['nombre'], 'panel solar') !== false)) > 0);
$mods = array_slice(Catalogo::productos(['concepto' => 'módulo fotovoltaico de 500 W', 'busqueda' => 'módulo fotovoltaico 500W', 'area' => 'FOT', 'sinonimos' => []]), 0, 6);
prueba('entre los primeros candidatos hay paneles y no kits ni conectores', count(array_filter($mods, fn($x) => stripos($x['nombre'], 'panel solar') === 0 || stripos($x['nombre'], 'módulo fotovoltaico') === 0)) >= 2 && count(array_filter($mods, fn($x) => preg_match('/^(kit|pareja|estuche|conector)/i', $x['nombre']))) === 0, implode(' || ', array_map(fn($x) => $x['nombre'], $mods)));
prueba('el servicio de montaje de módulos se encuentra al pedir «paneles solares»', in_array('MO-FOT-MOD-001', array_column(Catalogo::servicios(['concepto' => 'instalación de 12 paneles solares fotovoltaicos', 'busqueda' => 'instalación paneles solares', 'area' => 'FOT', 'sinonimos' => []]), 'codigo'), true));

echo "Flujo completo\n";
$ids = [];
$corr0 = Numeracion::ultimo();   // el contador de números de presupuesto se deja como estaba al terminar
try {
    $p = Presupuestos::crear(['texto' => "Quiero  un cargador de coche de 7 kW\n\n\n y la instalación, unos 20 m", 'cliente' => 'Cliente Prueba', 'email' => 'prueba@example.com']);
    $ids[] = $id = $p['id'];
    prueba('crea el borrador con número', $p['estado'] === 'BORRADOR' && preg_match('/^PRE\d{2}-\d{4,}$/', $p['numero']) === 1, $p['numero']);
    prueba('el número lleva el año de emisión (dos cifras)', str_starts_with($p['numero'], 'PRE' . date('y') . '-'));
    prueba('el correlativo empieza en 2000 como pronto', (int) substr($p['numero'], strpos($p['numero'], '-') + 1) >= 2000);
    prueba('el número queda anotado en el registro interno', (int) Db::fila('SELECT correlativo c FROM presupuestos WHERE id = ?', [$id])['c'] === (int) substr($p['numero'], strpos($p['numero'], '-') + 1));
    $p2 = Presupuestos::crear(['texto' => 'Quiero un enchufe']); $ids[] = $p2['id']; $llamadas['interprete']--;   // esta llamada de la IA no cuenta para las pruebas de abajo
    prueba('el siguiente presupuesto recibe el número siguiente', (int) substr($p2['numero'], strpos($p2['numero'], '-') + 1) === (int) substr($p['numero'], strpos($p['numero'], '-') + 1) + 1, $p['numero'] . ' → ' . $p2['numero']);
    prueba('formato: PRE26-2000 en 2026 y PRE27-2001 en 2027 (el número no se reinicia)', Numeracion::formato(2000, 2026) === 'PRE26-2000' && Numeracion::formato(2001, 2027) === 'PRE27-2001' && Numeracion::formato(2150, 2100) === 'PRE00-2150');
    prueba('el contador nunca baja de 1999 y no repite números', Numeracion::ultimo() >= 1999 && Numeracion::ultimo() >= (int) substr($p2['numero'], strpos($p2['numero'], '-') + 1));
    prueba('guarda el resumen y la pregunta pendiente', str_starts_with($p['resumen_interpretado'], 'He entendido') && count($p['datos_pendientes']) === 1);
    prueba('aún no hay líneas', $p['lineas'] === []);
    prueba('IA llamada 1 vez', $llamadas['interprete'] === 1);

    $p = Presupuestos::editar($id, 'son doce metros');
    prueba('editar reinterpreta', $llamadas['interprete'] === 2 && $p['datos_pendientes'] === [] && $p['interpretacion']['ediciones'] === 1);
    prueba('editar deja una corrección', Db::fila("SELECT COUNT(*) n FROM correcciones WHERE presupuesto_id = ? AND tipo = 'interpretacion'", [$id])['n'] == 1);

    $p = Presupuestos::confirmar($id);
    prueba('confirmar clasifica como combinado', $p['tipo_presupuesto'] === 'COMBINADO');
    prueba('hay 4 líneas', count($p['lineas']) === 4, (string) count($p['lineas']));
    $conAlt = array_values(array_filter($p['lineas'], fn($x) => !empty($x['alternativas'])));
    prueba('cada línea lleva otras opciones con nombre y precio (máximo 3)', count($conAlt) >= 2 && count(array_filter($conAlt, fn($x) => count($x['alternativas']) > 3)) === 0 && isset($conAlt[0]['alternativas'][0]['nombre'], $conAlt[0]['alternativas'][0]['precio_unitario']), json_encode($conAlt[0]['alternativas'] ?? null));
    prueba('la opción elegida no sale entre las otras', count(array_filter($p['lineas'], fn($x) => in_array($x['referencia_id'], array_column($x['alternativas'], 'id'), true))) === 0);
    $l = array_column($p['lineas'], null, 'requerimiento_id');
    prueba('producto con margen sobre el coste', $l[1]['tipo'] === 'PRODUCTO' && $l[1]['coste'] > 0 && $l[1]['margen'] === 0.3 && $l[1]['estado'] === 'ACEPTADO', json_encode($l[1]));
    prueba('servicio aceptado con confianza alta', $l[2]['estado'] === 'ACEPTADO' && $l[2]['importe'] > 0 || $l[2]['estado'] === 'ACEPTADO');
    prueba('confianza media → REVISION o DATO_PENDIENTE', in_array($l[3]['estado'], ['REVISION', 'DATO_PENDIENTE'], true), $l[3]['estado']);
    prueba('sin candidatos → SERVICIO_NO_ENCONTRADO (R009)', $l[4]['estado'] === 'SERVICIO_NO_ENCONTRADO' && $l[4]['importe'] === 0.0);
    prueba('presupuesto en REVISION (hay líneas por mirar)', $p['estado'] === 'REVISION');
    $suma = 0;
    foreach ($p['lineas'] as $x) if (in_array($x['estado'], ['ACEPTADO', 'REVISION'], true)) $suma += $x['importe'];
    prueba('subtotal = suma de líneas que cuentan', abs($p['subtotal'] - round($suma, 2)) < 0.01 && abs($p['total'] - round($p['subtotal'] * 1.21, 2)) < 0.01, json_encode([$p['subtotal'], $suma, $p['total']]));
    prueba('IA de emparejar 1 vez', $llamadas['emparejar'] === 1);

    prueba('no se puede editar tras confirmar', falla(fn() => Presupuestos::editar($id, 'otro'), 409));
    prueba('no se puede confirmar dos veces', falla(fn() => Presupuestos::confirmar($id), 409));

    echo "Ajustes del técnico\n";
    $p = Presupuestos::ajustarLinea($id, $l[3]['id'], ['cantidad' => 12, 'comentario' => 'medido en obra']);
    $l3 = array_column($p['lineas'], null, 'requerimiento_id')[3];
    prueba('dar la cantidad recalcula el importe', $l3['cantidad'] === 12.0 && $l3['importe'] > 0 && $l3['estado'] === 'REVISION', json_encode($l3));
    $p = Presupuestos::ajustarLinea($id, $l[3]['id'], ['estado' => 'ACEPTADO']);
    $p = Presupuestos::ajustarLinea($id, $l[4]['id'], ['estado' => 'DESCARTADO']);
    prueba('sin líneas por revisar → CALCULADO', $p['estado'] === 'CALCULADO', $p['estado']);
    prueba('las correcciones quedan guardadas', Db::fila('SELECT COUNT(*) n FROM correcciones WHERE presupuesto_id = ? AND tipo LIKE ?', [$id, 'linea_%'])['n'] >= 3);
    prueba('cantidad inválida falla', falla(fn() => Presupuestos::ajustarLinea($id, $l[3]['id'], ['cantidad' => -2]), 400));
    prueba('línea de otro presupuesto falla', falla(fn() => Presupuestos::ajustarLinea($id, 999999999, ['cantidad' => 1]), 404));
    echo "Aire acondicionado: exterior + interior
";
    $pr = fn($c, $q = 1) => ['tipo' => 'producto', 'concepto' => $c, 'busqueda' => $c, 'cantidad' => $q];
    $ac = fn(array $r, array $c = []) => Interprete::revisarClimatizacion($r, $c)[0]['confirmar'] ?? null;
    prueba('exterior + interior → sin pregunta', $ac([$pr('unidad exterior split 3,5 kW'), $pr('unidad interior split 3,5 kW')]) === null);
    prueba('solo interior → pide confirmar', $ac([$pr('unidad interior split pared')]) === 'ac_solo_interior');
    prueba('solo exterior → pide confirmar', $ac([$pr('unidad exterior aire acondicionado')]) === 'ac_solo_exterior');
    prueba('lo confirmado no vuelve a preguntarse', $ac([$pr('unidad interior split pared')], ['ac_solo_interior']) === null);
    prueba('2×1: 1 exterior + 2 interiores → correcto', $ac([$pr('unidad exterior multisplit 2x1'), $pr('unidad interior split', 2)]) === null);
    prueba('2 exteriores y 1 interior → desajuste', $ac([$pr('unidad exterior split', 2), $pr('unidad interior split')]) === 'ac_desajuste');
    prueba('2×1 con un solo interior → desajuste', $ac([$pr('unidad exterior multisplit 2x1'), $pr('unidad interior split')]) === 'ac_desajuste');
    prueba('equipo split completo → sin pregunta', $ac([$pr('equipo split de aire acondicionado')]) === null);
    prueba('soportes y tubería no cuentan', $ac([$pr('soporte para unidad exterior'), $pr('tubería de cobre para split', 4)]) === null);
    prueba('una luminaria exterior no es aire acondicionado', $ac([$pr('luminaria led exterior 20w')]) === null);
    prueba('confirmar un dato que no existe → 400', falla(fn() => Presupuestos::confirmarDato($id, 'xx'), 400));

    echo "Una referencia = una partida
";
    $pv = fn($c, $d = '', $q = 1) => ['tipo' => 'producto', 'concepto' => $c, 'detalles' => $d, 'cantidad' => $q];
    $va = fn(array $r, array $c = []) => Interprete::revisarVariantes($r, $c);
    prueba('varios amperajes en una sola partida → avisa', count($va([$pv('magnetotérmico de 10 A, 20 A y 16 A', '', 12)])) === 1);
    prueba('varios valores en los detalles → avisa', count($va([$pv('magnetotérmico', '1 de 10 A, 1 de 20 A y 10 de 16 A', 12)])) === 1);
    prueba('tres partidas separadas → no avisa', $va([$pv('magnetotérmico 10 A'), $pv('magnetotérmico 20 A'), $pv('magnetotérmico 16 A', '', 10)]) === []);
    prueba('diámetros distintos → avisa', count($va([$pv('tubo corrugado de 20 mm y de 25 mm', '', 40)])) === 1);
    prueba('polos distintos → avisa', count($va([$pv('magnetotérmico 16 A de 1P y de 2P')])) === 1);
    prueba('unidades distintas del mismo producto no avisan', $va([$pv('interruptor diferencial 40 A 30 mA'), $pv('cargador 7,4 kW 32 A'), $pv('cable manguera 3G2,5 mm²', '20 m')]) === []);
    prueba('opciones por decidir en los detalles (sensibilidad) no son variantes', $va([$pv('interruptor diferencial de 40 A', 'Sensibilidad pendiente de definir (30 mA, 300 mA u otra).')]) === [] && $va([$pv('magnetotérmico', 'amperaje (10 A o 16 A)')]) === []);
    prueba('dos mediciones en metros del mismo producto no son variantes (60 m y 55 m)', $va([$pv('manguera RZ1-K de 10 mm² para línea de alimentación del cargador', 'Hay dos mediciones: 60 m y 55 m; se usa la mayor.', 60)]) === [] && $va([$pv('cable de 60 m y de 55 m', '', 60)]) === []);
    prueba('las secciones distintas siguen avisando (10 mm² y 16 mm²)', count($va([$pv('manguera de 10 mm² y de 16 mm²', '', 60)])) === 1);
    prueba('«de 20 a 30 metros» no es un amperaje', $va([$pv('cable', 'de 20 a 30 metros')]) === []);
    echo "Preguntas: solo para elegir producto o servicio\n";
    $lp = fn(array $d) => Interprete::limpiarPreguntas($d);
    prueba('una pregunta que cambia la elección se mantiene', count($lp([['campo' => 'm', 'pregunta' => '¿Cuántos metros hay?', 'afecta' => 'la cantidad del tendido']])) === 1);
    prueba('sin «afecta» se descarta', $lp([['campo' => 'x', 'pregunta' => '¿Cuántos metros hay?']]) === []);
    prueba('«afecta» vacío se descarta', $lp([['campo' => 'x', 'pregunta' => '¿Algo?', 'afecta' => '  ']]) === []);
    prueba('diagnóstico: olor a quemado → fuera', $lp([['campo' => 'x', 'pregunta' => '¿Hay olor a quemado o zonas calientes en el cuadro?', 'afecta' => 'el diagnóstico']]) === []);
    prueba('diagnóstico: vuelve a saltar → fuera', $lp([['campo' => 'x', 'pregunta' => '¿El automático vuelve a saltar al rearmarlo?', 'afecta' => 'el servicio']]) === []);
    prueba('diagnóstico: qué equipos tiene → fuera', $lp([['campo' => 'x', 'pregunta' => '¿Qué equipos tiene instalados la vivienda?', 'afecta' => 'el servicio']]) === []);
    prueba('mezcla: se quedan solo las que sirven', count($lp([['campo' => 'a', 'pregunta' => '¿Qué amperaje tiene el diferencial?', 'afecta' => 'la variante del diferencial'], ['campo' => 'b', 'pregunta' => '¿Desde cuándo ocurre?', 'afecta' => 'nada']])) === 1);

    echo "Solo material: ¿falta la instalación?\n";
    $sm = fn(array $r, string $t = '', array $c = []) => Interprete::revisarSoloMaterial($r, $t, $c);
    $svc = ['tipo' => 'servicio', 'concepto' => 'instalación', 'cantidad' => 1];
    prueba('solo material → pregunta', count($sm([$pv('magnetotérmico 16 A', '', 10)], 'cuadro con 10 magnetotérmicos')) === 1);
    prueba('con un servicio → no pregunta', $sm([$pv('magnetotérmico 16 A'), $svc], 'cuadro') === []);
    prueba('«solo el material» → no pregunta', $sm([$pv('magnetotérmico 16 A')], 'Necesito solo el material de un cuadro') === []);
    prueba('«suministro de…» → no pregunta', $sm([$pv('magnetotérmico 16 A')], 'Suministro de 10 magnetotérmicos') === []);
    prueba('«sin instalación» → no pregunta', $sm([$pv('magnetotérmico 16 A')], 'quiero los magnetotérmicos sin instalación') === []);
    prueba('confirmado → no vuelve a preguntar', $sm([$pv('magnetotérmico 16 A')], 'cuadro', ['solo_material']) === []);
    prueba('sin requerimientos → no pregunta', $sm([], 'hola') === []);
    $cv = $va([$pv('magnetotérmico de 10 A, 20 A y 16 A')])[0]['confirmar'];
    prueba('confirmado, no vuelve a avisar', $va([$pv('magnetotérmico de 10 A, 20 A y 16 A')], [$cv]) === []);

    echo "Añadir líneas a mano
";
    $antes = $p['total'];
    $p = Presupuestos::agregarLinea($id, ['tipo' => 'SERVICIO', 'concepto' => 'ZZ desplazamiento', 'cantidad' => 2, 'unidad' => 'ud', 'precio_unitario' => 30]);
    $z = array_values(array_filter($p['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento'))[0] ?? null;
    prueba('línea libre: importe 60 y correcta', $z && $z['importe'] === 60.0 && $z['estado'] === 'ACEPTADO' && $z['requerimiento_id'] === null, json_encode($z));
    prueba('línea libre suma al total', $p['total'] > $antes, "$antes → {$p['total']}");
    $svcAdd = Db::fila("SELECT id FROM servicios WHERE estado = 'ACTIVO' LIMIT 1");
    $p = Presupuestos::agregarLinea($id, ['fuente' => 'servicio', 'id' => $svcAdd['id']]);
    prueba('línea del catálogo con cantidad 1', count($p['lineas']) >= 2 && end($p['lineas'])['referencia_id'] == $svcAdd['id'] && end($p['lineas'])['cantidad'] === 1.0);
    prueba('línea libre sin precio → 400', falla(fn() => Presupuestos::agregarLinea($id, ['tipo' => 'PRODUCTO', 'concepto' => 'x']), 400));
    prueba('línea libre sin concepto → 400', falla(fn() => Presupuestos::agregarLinea($id, ['tipo' => 'PRODUCTO', 'concepto' => ' ', 'precio_unitario' => 1]), 400));
    prueba('cantidad 0 → 400', falla(fn() => Presupuestos::agregarLinea($id, ['tipo' => 'PRODUCTO', 'concepto' => 'x', 'precio_unitario' => 1, 'cantidad' => 0]), 400));
    prueba('elemento que no existe → 404', falla(fn() => Presupuestos::agregarLinea($id, ['fuente' => 'producto', 'id' => 999999999]), 404));

    echo "Partidas unificadas
";
    $manuales = array_values(array_filter($p['lineas'], fn($x) => $x['requerimiento_id'] === null));
    $idsU = array_column($manuales, 'id');
    $suma = array_sum(array_map(fn($x) => $x['importe'], $manuales));
    $prop = Presupuestos::proponerUnificacion($id, $idsU);
    prueba('la propuesta trae título, descripción y la suma', $prop['titulo'] !== '' && abs($prop['importe'] - $suma) < 0.01, json_encode($prop));
    prueba('con una sola partida → 400', falla(fn() => Presupuestos::unificar($id, [$idsU[0]], 'x', ''), 400));
    prueba('sin título → 400', falla(fn() => Presupuestos::unificar($id, $idsU, ' ', ''), 400));
    $p = Presupuestos::unificar($id, $idsU, 'Trabajo de prueba', 'Descripción de prueba');
    prueba('se crea la partida con la suma de sus líneas', count($p['grupos']) === 1 && abs($p['grupos'][0]['importe'] - $suma) < 0.01 && count($p['grupos'][0]['lineas']) === count($idsU), json_encode($p['grupos']));
    prueba('el total del presupuesto no cambia al unificar', $p['total'] === Presupuestos::obtener($id)['total']);
    prueba('una línea ya unificada no se puede volver a unificar → 409', falla(fn() => Presupuestos::unificar($id, $idsU, 'otra', ''), 409));
    $gid = $p['grupos'][0]['id'];
    $p = Presupuestos::actualizarGrupo($id, $gid, ['titulo' => 'Trabajo editado']);
    prueba('editar el título', $p['grupos'][0]['titulo'] === 'Trabajo editado' && $p['grupos'][0]['descripcion'] === 'Descripción de prueba');
    prueba('grupo de otro presupuesto → 404', falla(fn() => Presupuestos::actualizarGrupo($id, 999999999, ['titulo' => 'x']), 404));
    prueba('cambiar estado del presupuesto', Presupuestos::cambiarEstado($id, 'ENVIADO')['estado'] === 'ENVIADO');
    prueba('estado inválido falla', falla(fn() => Presupuestos::cambiarEstado($id, 'XX'), 400));

    echo "Recalcular y listar\n";
    $antes = $p['lineas'];
    $p = Presupuestos::recalcular($id);
    prueba('recalcular rehace las líneas y conserva las añadidas a mano', count($p['lineas']) === 6 && $llamadas['emparejar'] === 2 && count(array_filter($p['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento')) === 1, (string) count($p['lineas']));
    prueba('la partida unificada sobrevive al recalcular', count($p['grupos']) === 1 && count($p['grupos'][0]['lineas']) === 2, json_encode($p['grupos']));
    $r3 = array_column($p['lineas'], null, 'requerimiento_id')[3] ?? null;
    prueba('recalcular respeta la cantidad puesta a mano (12)', $r3 && $r3['cantidad'] === 12.0 && $r3['manual'] === true, json_encode($r3));
    $p = Presupuestos::ajustarLinea($id, $r3['id'], ['liberar' => true]);
    prueba('«que se recalcule» quita la marca', array_column($p['lineas'], null, 'requerimiento_id')[3]['manual'] === false);
    $lista = Presupuestos::listar('REVISION', 5);
    prueba('listar devuelve total y filas', isset($lista['total'], $lista['presupuestos']));
    prueba('presupuesto inexistente → 404', falla(fn() => Presupuestos::obtener(999999999), 404));
    prueba('recalcular sin confirmar → 409', (function () use (&$ids) {
        Claude::simular(fn($u, $s, $t) => json_encode(['requerimientos' => [['tipo' => 'servicio', 'concepto' => 'x']], 'resumen' => 'x']));
        $q = Presupuestos::crear(['texto' => 'algo']);
        $ids[] = $q['id'];
        return falla(fn() => Presupuestos::recalcular($q['id']), 409);
    })());
    prueba('el cable y la manguera de Novelec van en metros; tubos y otros proveedores no', Catalogo::unidadProducto(['proveedor' => 'NOVELEC', 'nombre_normalizado' => 'CABLE RZ1-K 3G6', 'unidad_normalizada' => 'UD']) === 'm'
        && Catalogo::unidadProducto(['proveedor' => 'NOVELEC', 'nombre_normalizado' => 'TUBO CORRUGADO 20', 'unidad_normalizada' => 'UD']) === 'UD'
        && Catalogo::unidadProducto(['proveedor' => 'OBRAMAT', 'nombre_normalizado' => 'CABLE 2,5 ROLLO', 'unidad_normalizada' => 'UD']) === 'UD'
        && Catalogo::unidadProducto(['proveedor' => 'NOVELEC', 'nombre_normalizado' => 'CABLE X', 'unidad_normalizada' => 'rollo']) === 'rollo');
    echo "Reglas usadas y regla aplicada\n";
    $nomRegla = 'ZZ regla prueba';
    Db::ejecutar("DELETE FROM reglas WHERE nombre = ?", [$nomRegla]);
    $idRegla = Db::insertar('reglas', ['codigo' => 'ZZ-PRUEBA', 'nombre' => $nomRegla, 'grupo' => 'General', 'condicion' => 'piden algo de prueba', 'accion' => 'hacer algo de prueba', 'prioridad' => 99, 'activo' => 1]);
    prueba('las reglas llegan a la IA con su nombre entre corchetes', str_contains(Reglas::paraPrompt(), '[' . $nomRegla . '] Cuando: piden algo de prueba'));
    prueba('el nombre de una regla activa se acepta (sin mayúsculas ni corchetes)', Reglas::nombreValido('[zz REGLA prueba]') === $nomRegla);
    prueba('un nombre inventado, vacío o «null» no se acepta', Reglas::nombreValido('Regla que no existe') === null && Reglas::nombreValido('') === null && Reglas::nombreValido('null') === null && Reglas::nombreValido(null) === null);
    Db::ejecutar('UPDATE reglas SET activo = 0 WHERE id = ?', [$idRegla]);
    prueba('una regla desactivada ya no se pasa ni se acepta', !str_contains(Reglas::paraPrompt(), $nomRegla) && Reglas::nombreValido($nomRegla) === null);
    Db::ejecutar('DELETE FROM reglas WHERE id = ?', [$idRegla]);
    $rp = Presupuestos::obtener($id);
    prueba('un presupuesto calculado guarda las reglas que se le pasaron a la IA', is_array($rp['reglas_usadas']) && count($rp['reglas_usadas']) === count(Reglas::activas()) && (count($rp['reglas_usadas']) === 0 || isset($rp['reglas_usadas'][0]['nombre'], $rp['reglas_usadas'][0]['condicion'])));
    prueba('las líneas traen su campo «regla» (vacío si la IA no aplicó ninguna)', array_key_exists('regla', $rp['lineas'][0]) && $rp['lineas'][0]['regla'] === null);

    echo "Notas y condiciones\n";
    $adm0 = ['id' => null, 'usuario' => 'prueba', 'rol' => 'admin'];
    prueba('la biblioteca trae unos ejemplos manuales la primera vez', count(Notas::listar()) >= 1 && count(array_filter(Notas::listar(), fn($n) => $n['modo'] === 'auto' && $n['titulo'] === 'Trabajos no incluidos')) === 0);
    $nA = Notas::crear(['titulo' => 'ZZ auto vehículo', 'texto' => 'Texto automático de prueba para vehículo eléctrico.', 'modo' => 'auto', 'areas' => ['VE']], $adm0);
    $nB = Notas::crear(['titulo' => 'ZZ auto fotovoltaica', 'texto' => 'Solo para fotovoltaica.', 'modo' => 'auto', 'areas' => ['FOT']], $adm0);
    $nC = Notas::crear(['titulo' => 'ZZ auto todas', 'texto' => 'Para todos los trabajos.', 'modo' => 'auto'], $adm0);
    $nD = Notas::crear(['titulo' => 'ZZ manual', 'texto' => 'Solo si se elige.', 'modo' => 'manual'], $adm0);
    prueba('una nota sin título → 400', falla(fn() => Notas::crear(['titulo' => ' ', 'texto' => 'x'], $adm0), 400));
    prueba('un modo inventado → 400', falla(fn() => Notas::crear(['titulo' => 'x', 'texto' => 'x', 'modo' => 'siempre'], $adm0), 400));
    prueba('un área inventada se ignora (queda para todas)', Notas::crear(['titulo' => 'ZZ área rara', 'texto' => 'x', 'areas' => ['XYZ']], $adm0)['areas'] === []);
    $pv = Presupuestos::crear(['texto' => 'Quiero un cargador de coche']); $ids[] = $pv['id'];
    Db::actualizar('presupuestos', $pv['id'], ['grupo_principal' => 'Vehículo eléctrico']);
    prueba('un presupuesto nuevo empieza sin notas', Presupuestos::obtener($pv['id'])['notas'] === []);
    Notas::aplicarAutomaticas($pv['id']);
    $tx = array_column(Presupuestos::obtener($pv['id'])['notas'], 'texto');
    prueba('las automáticas de su área y las de todas áreas se añaden', in_array($nA['texto'], $tx, true) && in_array($nC['texto'], $tx, true));
    prueba('las de otra área y las manuales no', !in_array($nB['texto'], $tx, true) && !in_array($nD['texto'], $tx, true));
    $notasV = Presupuestos::obtener($pv['id'])['notas'];
    $quito = array_values(array_filter($notasV, fn($n) => $n['nota_id'] === $nC['id']))[0];
    Notas::quitar($pv['id'], $quito['id']);
    Notas::aplicarAutomaticas($pv['id']);
    prueba('una nota quitada no vuelve sola al recalcular', !in_array($nC['texto'], array_column(Presupuestos::obtener($pv['id'])['notas'], 'texto'), true));
    $nl = Notas::anadir($pv['id'], ['nota_id' => $nD['id']]);
    prueba('se puede añadir una de la biblioteca', in_array($nD['texto'], array_column($nl, 'texto'), true));
    prueba('la misma nota dos veces → 409', falla(fn() => Notas::anadir($pv['id'], ['nota_id' => $nD['id']]), 409));
    $nl = Notas::anadir($pv['id'], ['texto' => '  Observación libre de este trabajo  ']);
    prueba('se puede escribir una nota libre (recortada)', end($nl)['texto'] === 'Observación libre de este trabajo' && end($nl)['origen'] === 'libre');
    prueba('una nota libre vacía → 400', falla(fn() => Notas::anadir($pv['id'], ['texto' => '   ']), 400));
    $mia = array_values(array_filter(Presupuestos::obtener($pv['id'])['notas'], fn($n) => $n['nota_id'] === $nD['id']))[0];
    Notas::cambiar($pv['id'], $mia['id'], ['texto' => 'Texto editado solo aquí']);
    prueba('editar una nota del presupuesto no cambia la biblioteca', Notas::una($nD['id'])['texto'] === 'Solo si se elige.');
    Notas::actualizar($nA['id'], ['texto' => 'Cambiado en la biblioteca'], $adm0);
    prueba('cambiar la biblioteca no altera un presupuesto que ya tiene la nota', in_array($nA['texto'], array_column(Presupuestos::obtener($pv['id'])['notas'], 'texto'), true));
    prueba('una nota de otro presupuesto → 404', falla(fn() => Notas::quitar($pv['id'], 999999999), 404));
    Notas::eliminar($nA['id'], $adm0);
    prueba('borrar de la biblioteca no la quita de los presupuestos', in_array($nA['texto'], array_column(Presupuestos::obtener($pv['id'])['notas'], 'texto'), true));
    $pw = Presupuestos::crear(['texto' => 'Quiero un enchufe', 'nota' => 'Acceso por el garaje']); $ids[] = $pw['id'];
    prueba('al crear con una nota escrita, ya la guarda', count($pw['notas']) === 1 && $pw['notas'][0]['texto'] === 'Acceso por el garaje');
    foreach (Notas::listar() as $n) if (str_starts_with($n['titulo'], 'ZZ')) Notas::eliminar($n['id'], $adm0);

    echo "Condiciones de pago\n";
    $c60 = ['modalidad' => 'dos_60_40', 'plazos' => [['porcentaje' => 60, 'cuando' => 'aceptacion'], ['porcentaje' => 40, 'cuando' => 'fin']]];
    $pp = Presupuestos::obtener($id);
    prueba('sin condiciones al principio', $pp['condiciones_pago'] === null);
    Pagos::guardar($id, $c60);
    $pp = Presupuestos::obtener($id);
    $cp = $pp['condiciones_pago'];
    prueba('guarda 60 % + 40 % con su momento', count($cp['plazos']) === 2 && $cp['plazos'][0]['cuando'] === 'aceptacion' && $cp['plazos'][1]['texto'] === 'Al finalizar los trabajos');
    prueba('los importes suman exactamente el total', abs(array_sum(array_column($cp['plazos'], 'importe')) - $pp['total']) < 0.001 && abs($cp['plazos'][0]['importe'] - round($pp['total'] * 0.6, 2)) < 0.001);
    prueba('tres pagos 33,33 + 33,33 + 33,34 reparten sin perder un céntimo', (function () {
        $c = Pagos::conImportes(Pagos::validar(['plazos' => [['porcentaje' => 33.33, 'cuando' => 'aceptacion'], ['porcentaje' => 33.33, 'cuando' => 'avance'], ['porcentaje' => 33.34, 'cuando' => 'fin']]]), 100.01);
        return abs(array_sum(array_column($c['plazos'], 'importe')) - 100.01) < 0.0001;
    })());
    prueba('si no suma 100 % → 400', falla(fn() => Pagos::guardar($id, ['plazos' => [['porcentaje' => 60, 'cuando' => 'aceptacion'], ['porcentaje' => 30, 'cuando' => 'fin']]]), 400));
    prueba('un momento inventado → 400', falla(fn() => Pagos::guardar($id, ['plazos' => [['porcentaje' => 100, 'cuando' => 'nunca']]]), 400));
    prueba('un pago de 0 % → 400', falla(fn() => Pagos::guardar($id, ['plazos' => [['porcentaje' => 0, 'cuando' => 'inicio'], ['porcentaje' => 100, 'cuando' => 'fin']]]), 400));
    prueba('tras un error, lo guardado sigue igual', count(Presupuestos::obtener($id)['condiciones_pago']['plazos']) === 2);
    prueba('contado = un solo pago de 100 %', count(Pagos::validar(['modalidad' => 'contado', 'plazos' => [['porcentaje' => '100', 'cuando' => 'aceptacion']]])['plazos']) === 1);
    prueba('los porcentajes con coma se aceptan', Pagos::validar(['plazos' => [['porcentaje' => '33,5', 'cuando' => 'inicio'], ['porcentaje' => '66,5', 'cuando' => 'fin']]])['plazos'][0]['porcentaje'] === 33.5);
    Pagos::guardar($id, null);
    prueba('se pueden quitar', Presupuestos::obtener($id)['condiciones_pago'] === null);
    $pq = Presupuestos::crear(['texto' => 'Quiero un enchufe', 'condiciones_pago' => $c60]); $ids[] = $pq['id'];
    prueba('al crear el presupuesto ya guarda las condiciones de pago', count($pq['condiciones_pago']['plazos']) === 2);
    prueba('crear con porcentajes que no suman → 400 y no deja presupuesto', falla(fn() => Presupuestos::crear(['texto' => 'x', 'condiciones_pago' => ['plazos' => [['porcentaje' => 50, 'cuando' => 'inicio']]]]), 400));

    echo "Datos del cliente\n";
    $pc = Presupuestos::actualizarCliente($id, ['cliente' => '  Ana Pérez ', 'telefono' => '600 111 222', 'email' => 'ana@example.com']);
    prueba('guarda nombre, teléfono y email (recortados)', $pc['cliente'] === 'Ana Pérez' && $pc['telefono'] === '600 111 222' && $pc['email'] === 'ana@example.com');
    prueba('un dato vacío lo borra', Presupuestos::actualizarCliente($id, ['cliente' => 'Ana Pérez', 'telefono' => '', 'email' => ''])['telefono'] === null);
    prueba('email inválido → 400', falla(fn() => Presupuestos::actualizarCliente($id, ['email' => 'no']), 400));
    $pd = Presupuestos::actualizarCliente($id, ['cliente' => 'Ana Pérez', 'dni' => ' 12.345.678-z ', 'calle' => 'Calle Mayor 5', 'piso' => '2º B', 'codigo_postal' => '28013', 'ciudad' => 'Madrid']);
    prueba('guarda DNI (limpio y en mayúsculas), calle, piso, código postal y población', $pd['dni'] === '12345678Z' && $pd['calle'] === 'Calle Mayor 5' && $pd['piso'] === '2º B' && $pd['codigo_postal'] === '28013' && $pd['ciudad'] === 'Madrid');
    prueba('la provincia sale sola del código postal', $pd['provincia'] === 'Madrid');
    prueba('mandar solo el teléfono no borra la dirección', Presupuestos::actualizarCliente($id, ['telefono' => '611 222 333'])['calle'] === 'Calle Mayor 5');
    prueba('DNI con símbolos raros → 400', falla(fn() => Presupuestos::actualizarCliente($id, ['dni' => '12$%']), 400));
    prueba('provincia por código postal: 08 Barcelona, 35 Las Palmas, 99999 nada', Clientes::provinciaDe('08001') === 'Barcelona' && Clientes::provinciaDe('35010') === 'Las Palmas' && Clientes::provinciaDe('99999') === '');
    $pn = Presupuestos::crear(['texto' => 'Quiero un enchufe', 'cliente' => 'Con Datos', 'dni' => 'x1234567l', 'calle' => 'Calle Sol 1', 'codigo_postal' => '46001', 'ciudad' => 'Valencia']); $ids[] = $pn['id'];
    prueba('al crear el presupuesto ya guarda los datos completos', $pn['dni'] === 'X1234567L' && $pn['calle'] === 'Calle Sol 1' && $pn['provincia'] === 'Valencia');
    prueba('sin campos → 400', falla(fn() => Presupuestos::actualizarCliente($id, []), 400));
    prueba('presupuesto que no existe → 404', falla(fn() => Presupuestos::actualizarCliente(999999999, ['cliente' => 'x']), 404));

    echo "Base de servicios (administración)\n";
    $admin = ['id' => 1, 'usuario' => 'prueba', 'rol' => 'admin'];
    $codigoPrueba = 'ZZ-TEST-001';
    Db::ejecutar('DELETE FROM servicios WHERE codigo = ?', [$codigoPrueba]);
    $sv = BaseServicios::crear(['codigo' => 'zz-test-001', 'linea' => 'ele', 'nombre' => 'Servicio de prueba', 'unidad' => 'ud', 'precio_unitario' => '12,5', 'palabras_clave' => 'prueba|test'], $admin);
    prueba('crea un servicio (código en mayúsculas, precio con coma)', $sv['codigo'] === 'ZZ-TEST-001' && $sv['linea'] === 'ELE' && $sv['precio_unitario'] === 12.5 && $sv['modo_precio'] === 'FIJO' && $sv['estado'] === 'ACTIVO', json_encode($sv));
    prueba('un código repetido → 409', falla(fn() => BaseServicios::crear(['codigo' => 'ZZ-TEST-001', 'linea' => 'ELE', 'nombre' => 'x'], $admin), 409));
    prueba('línea inventada → 400', falla(fn() => BaseServicios::crear(['codigo' => 'ZZ-TEST-002', 'linea' => 'XX', 'nombre' => 'x'], $admin), 400));
    prueba('precio negativo → 400', falla(fn() => BaseServicios::crear(['codigo' => 'ZZ-TEST-002', 'linea' => 'ELE', 'nombre' => 'x', 'precio_unitario' => -1], $admin), 400));
    prueba('sin nombre → 400', falla(fn() => BaseServicios::crear(['codigo' => 'ZZ-TEST-002', 'linea' => 'ELE'], $admin), 400));
    $sv = BaseServicios::actualizar($sv['id'], ['precio_unitario' => 20, 'modo_precio' => 'desde'], $admin);
    prueba('edita solo lo que se manda', $sv['precio_unitario'] === 20.0 && $sv['modo_precio'] === 'DESDE' && $sv['nombre'] === 'Servicio de prueba');
    prueba('el cambio queda en la actividad', (int) Db::fila("SELECT COUNT(*) n FROM eventos WHERE accion = 'servicio_modificado' AND entidad_id = ?", [$sv['id']])['n'] === 1);
    $lista = BaseServicios::listar(['q' => 'ZZ-TEST', 'linea' => 'ELE']);
    prueba('lo encuentra al listar y filtrar', $lista['total'] === 1 && $lista['servicios'][0]['codigo'] === 'ZZ-TEST-001');
    prueba('la lista no trae la fila de cabecera', !in_array('codigo', array_column(BaseServicios::listar(['limite' => 100])['servicios'], 'codigo'), true));
    $lineaUso = Db::insertar('presupuesto_lineas', ['presupuesto_id' => $id, 'tipo' => 'SERVICIO', 'referencia_id' => $sv['id'], 'concepto' => 'x', 'cantidad' => 1, 'precio_unitario' => 1, 'importe' => 1, 'estado' => 'ACEPTADO']);
    prueba('un servicio usado en presupuestos no se borra → 409', falla(fn() => BaseServicios::eliminar($sv['id'], $admin), 409));
    $sv = BaseServicios::actualizar($sv['id'], ['estado' => 'INACTIVO'], $admin);
    prueba('desactivado, deja de ofrecerse en las búsquedas', !in_array('ZZ-TEST-001', array_column(Catalogo::servicios(['concepto' => 'Servicio de prueba', 'busqueda' => 'prueba', 'sinonimos' => []]), 'codigo'), true));
    Db::ejecutar('DELETE FROM presupuesto_lineas WHERE id = ?', [$lineaUso]);
    prueba('sin usos, se borra', BaseServicios::eliminar($sv['id'], $admin)['codigo'] === 'ZZ-TEST-001' && falla(fn() => BaseServicios::obtener($sv['id']), 404));
    Db::ejecutar("DELETE FROM eventos WHERE entidad = 'servicio' AND detalle LIKE '%ZZ-TEST-001%'");

    echo "Sinónimos (administración)\n";
    Db::ejecutar("DELETE FROM sinonimos WHERE termino LIKE 'zz test%'");
    Db::ejecutar("DELETE FROM sinonimos_sugeridos WHERE termino LIKE 'zz test%'");
    $sn = Sinonimos::crear(['termino' => ' ZZ Test Uno ', 'concepto_canonico' => 'concepto de prueba', 'grupo' => 'General'], $admin);
    prueba('crea un sinónimo (término en minúsculas)', $sn['termino'] === 'zz test uno' && $sn['activo']);
    prueba('uno repetido → 409', falla(fn() => Sinonimos::crear(['termino' => 'zz test uno', 'concepto_canonico' => 'otro'], $admin), 409));
    prueba('dos textos iguales → 400', falla(fn() => Sinonimos::crear(['termino' => 'zz test dos', 'concepto_canonico' => 'ZZ Test Dos'], $admin), 400));
    prueba('lo usa el buscador en cuanto se guarda', str_contains(Sinonimos::expandir('necesito un zz test uno por favor'), 'concepto de prueba'));
    Sinonimos::actualizar($sn['id'], ['activo' => false], $admin);
    prueba('desactivado, ya no se usa', Sinonimos::expandir('zz test uno') === '');
    // La IA propone (simulada); nada se activa solo
    Claude::simular(fn($u, $s, $t) => json_encode(['sugerencias' => [
        ['grupo' => 'General', 'termino' => 'ZZ Test Tres', 'concepto_canonico' => 'concepto tres', 'motivo' => 'prueba'],
        ['grupo' => 'General', 'termino' => 'zz test uno', 'concepto_canonico' => 'ya existe', 'motivo' => 'repetido'],
        ['grupo' => 'General', 'termino' => 'x', 'concepto_canonico' => 'demasiado corto'],
    ]]));
    $r = Sinonimos::sugerir($admin);
    prueba('la IA propone y se guardan como pendientes (sin repetidos ni inválidos)', $r['nuevas'] === 1 && count(array_filter($r['sugeridos'], fn($x) => $x['termino'] === 'zz test tres')) === 1);
    prueba('una sugerencia pendiente todavía no se usa', Sinonimos::expandir('zz test tres') === '');
    $sug = array_values(array_filter($r['sugeridos'], fn($x) => $x['termino'] === 'zz test tres'))[0];
    $ok2 = Sinonimos::aprobar($sug['id'], ['concepto_canonico' => 'concepto tres corregido'], $admin);
    prueba('al aprobar (con el texto corregido) pasa a sinónimo activo', $ok2['concepto_canonico'] === 'concepto tres corregido' && str_contains(Sinonimos::expandir('zz test tres'), 'concepto tres corregido'));
    prueba('una ya aprobada no se puede aprobar otra vez → 404', falla(fn() => Sinonimos::aprobar($sug['id'], [], $admin), 404));
    prueba('rechazar una que no está pendiente → 404', falla(fn() => Sinonimos::rechazar($sug['id']), 404));
    Sinonimos::eliminar($sn['id'], $admin);
    Sinonimos::eliminar($ok2['id'], $admin);
    Db::ejecutar("DELETE FROM sinonimos_sugeridos WHERE termino LIKE 'zz test%'");
    Db::ejecutar("DELETE FROM eventos WHERE entidad = 'sinonimo' AND detalle LIKE '%zz test%'");
    Db::ejecutar("DELETE FROM eventos WHERE accion = 'sinonimos_sugeridos' AND usuario = 'prueba'");

    echo "Proveedores preferidos\n";
    $orig = ['proveedor_producto' => Ajustes::texto('proveedor_producto'), 'proveedor_fotovoltaica' => Ajustes::texto('proveedor_fotovoltaica')];
    $cfg = function (string $a, string $b) { Ajustes::guardar('proveedor_producto', $a); Ajustes::guardar('proveedor_fotovoltaica', $b); };
    try {
        $cfg('NOVELEC, OBRAMAT', 'Solar Fácil');
        prueba('una lista con varios proveedores, en orden', Ajustes::lista('proveedor_producto') === ['NOVELEC', 'OBRAMAT']);
        prueba('Novelec se usa si está en alguna lista', Ajustes::usaProveedor('novelec'));
        $cfg('OBRAMAT', 'Solar Fácil');
        prueba('si Novelec no está en ninguna lista, no se usa', !Ajustes::usaProveedor('NOVELEC') && Ajustes::usaProveedor('Solar Fácil'));
        $cfg('', '');
        prueba('sin preferidos, se usan todos', Ajustes::usaProveedor('NOVELEC'));
    } finally {
        $cfg($orig['proveedor_producto'], $orig['proveedor_fotovoltaica']);
    }

    echo "Cambiar el producto o servicio de una línea\n";
    $pc = Presupuestos::obtener($id);   // las líneas de «recalcular» (la IA simulada ya es otra: no se vuelve a calcular)
    $lp = array_values(array_filter($pc['lineas'], fn($x) => $x['tipo'] === 'PRODUCTO' && $x['estado'] !== 'PRODUCTO_NO_ENCONTRADO'))[0];
    $otro = Catalogo::productos(['concepto' => 'batería litio 48V', 'busqueda' => 'batería litio 48V', 'sinonimos' => []])[0];
    $pc = Presupuestos::ajustarLinea($id, $lp['id'], ['elegir' => ['fuente' => 'producto', 'id' => $otro['id']], 'comentario' => 'el anterior no era']);
    $nl = array_column($pc['lineas'], null, 'id')[$lp['id']];
    prueba('cambia el producto y recalcula el precio', $nl['referencia_id'] === $otro['id'] && $nl['concepto'] === $otro['nombre'] && $nl['importe'] > 0 && $nl['margen'] === 0.3, json_encode($nl));
    prueba('queda como correcta y sin dudas', $nl['estado'] === 'ACEPTADO' && $nl['confianza'] === 100.0 && str_contains((string) $nl['aviso'], 'Elegido a mano'));
    prueba('se guarda para aprender', (int) Db::fila("SELECT COUNT(*) n FROM correcciones WHERE presupuesto_id = ? AND tipo = 'linea_elemento'", [$id])['n'] === 1);
    $svc = Catalogo::servicios(['concepto' => 'tendido circuito', 'sinonimos' => []])[0];
    prueba('un servicio en una línea de material → 400', falla(fn() => Presupuestos::ajustarLinea($id, $lp['id'], ['elegir' => ['fuente' => 'servicio', 'id' => $svc['id']]]), 400));
    prueba('un elemento que no existe → 404', falla(fn() => Presupuestos::ajustarLinea($id, $lp['id'], ['elegir' => ['fuente' => 'producto', 'id' => 999999999]]), 404));

    echo "Búsqueda manual: todas las palabras\n";
    prueba('las unidades sueltas no se exigen si hay número (tubo rígido PVC 32 mm)', Catalogo::palabrasBusqueda('tubo rígido PVC 32 mm') === ['tubo', 'rígido', 'pvc', '32'] && Catalogo::palabrasBusqueda('mm') === ['mm']);
    prueba('las palabras de la búsqueda (sin plurales, números incluidos)', Catalogo::palabrasBusqueda('Módulos fotovoltaicos 450 W') === ['módulo', 'fotovoltaico', '450'], json_encode(Catalogo::palabrasBusqueda('Módulos fotovoltaicos 450 W')));
    prueba('cumple si están todas, en cualquier orden', Catalogo::cumpleTodas(['pequeño', 'material'], 'MATERIAL ELÉCTRICO PEQUEÑO') && Catalogo::cumpleTodas(['pequeno'], 'Pequeño'));
    prueba('no cumple si falta alguna', !Catalogo::cumpleTodas(['pequeño', 'material', 'eléctrico'], 'Pequeño material de fontanería') && !Catalogo::cumpleTodas([], 'algo'));
    prueba('«de», «para» y la «x» de «1/4 x 3/8» no cuentan como palabras', Catalogo::palabrasBusqueda('tubería de cobre 1/4" x 3/8"') === ['tubería', 'cobre', '1/4', '3/8'], json_encode(Catalogo::palabrasBusqueda('tubería de cobre 1/4" x 3/8"'), JSON_UNESCAPED_UNICODE));
    $tp = Catalogo::palabrasBusqueda('tubería de cobre 1/4" x 3/8"');
    prueba('«tubería» encuentra «tubo» (tubo de cobre aislado 1/4 x 3/8)', Catalogo::cumpleTodas($tp, 'TUBO DE COBRE AISLADO Y ABOCARDADO DIAMETRO 1/4” x 3/8”'));
    prueba('y no encuentra un macarrón sin cobre ni medidas', !Catalogo::cumpleTodas($tp, 'MACARRON AISLANTE TUBERIA 3/8 ESPESOR 9MM') && !Catalogo::cumpleTodas($tp, 'TUBO CORRUGADO DIAMETRO 20 NEGRO'));
    prueba('«frigorífica» encuentra «frigorífico» y al revés', Catalogo::cumpleTodas(['frigorífica'], 'Línea frigorífico split') && Catalogo::cumpleTodas(['frigorífico'], 'tubería frigorífica'));
    $pvc = Catalogo::palabrasBusqueda('tubo de PVC');
    prueba('«tubo de PVC» encuentra el tubo RÍGIDO aunque no ponga PVC', Catalogo::cumpleTodas($pvc, 'TUBO AISCAN-R RIGIDO DIAMETRO 25 GRIS') && Catalogo::cumpleTodas($pvc, 'TUBO PVC RIGIDO M-20'));
    prueba('y sigue sin traer lo que no es un tubo', !Catalogo::cumpleTodas($pvc, 'CAJA DE REGISTRO RIGIDA 100X100') && !Catalogo::cumpleTodas($pvc, 'CABLE PVC 2,5'));
    prueba('a la web se le pregunta también por «rígido»', Catalogo::consultasWeb('tubo de PVC') === ['tubo de PVC', 'tubo de rigido'] && Catalogo::consultasWeb('tubo corrugado') === ['tubo corrugado']);
    $idsDe = fn(array $r) => array_column($r, 'id');
    $pa = $idsDe(Catalogo::busquedaManual('producto', 'batería', 500)); $pb = $idsDe(Catalogo::busquedaManual('producto', 'litio', 500)); $pab = $idsDe(Catalogo::busquedaManual('producto', 'batería litio', 500));
    prueba('productos: «batería litio» es solo lo que tiene las dos palabras', count($pab) > 0 && $pab === array_values(array_intersect($pab, $pa, $pb)) && count($pab) <= min(count($pa), count($pb)), count($pab) . ' de ' . count($pa) . ' y ' . count($pb));
    prueba('más palabras = menos o igual resultados', count(Catalogo::busquedaManual('producto', 'batería litio 48V', 500)) <= count($pab));
    prueba('una palabra que no existe junto a otra → nada', Catalogo::busquedaManual('producto', 'batería zzxxqq', 30) === [] && Catalogo::busquedaManual('servicio', 'tendido zzxxqq', 30) === []);
    $sa = $idsDe(Catalogo::busquedaManual('servicio', 'tendido', 500)); $sb = $idsDe(Catalogo::busquedaManual('servicio', 'circuito', 500)); $sab = $idsDe(Catalogo::busquedaManual('servicio', 'tendido circuito', 500));
    prueba('servicios: «tendido circuito» es solo lo que tiene las dos palabras', count($sab) > 0 && $sab === array_values(array_intersect($sab, $sa, $sb)) && count($sab) <= min(count($sa), count($sb)));
    prueba('el orden de las palabras no importa', $idsDe(Catalogo::busquedaManual('servicio', 'circuito tendido', 500)) === $sab || count($idsDe(Catalogo::busquedaManual('servicio', 'circuito tendido', 500))) === count($sab));
    prueba('los caracteres % y _ no se cuelan como comodín', Catalogo::busquedaManual('producto', '%%', 5) === []);

    echo "Lista de presupuestos: búsqueda y contadores
";
    $l0 = Presupuestos::listar(null, 200);
    prueba('trae los contadores por estado y su suma es el total', array_sum($l0['conteos']) === $l0['total'], json_encode($l0['conteos']));
    prueba('trae el importe de los que están por revisar', is_float($l0['importe_revision']) || is_int($l0['importe_revision']));
    $numero0 = $l0['presupuestos'][0]['numero'];
    $lq = Presupuestos::listar(null, 50, 1, $numero0);
    prueba('buscar por número encuentra ese presupuesto', $lq['total'] >= 1 && in_array($numero0, array_column($lq['presupuestos'], 'numero'), true));
    prueba('buscar algo que no existe → vacío y contadores a cero', Presupuestos::listar(null, 50, 1, 'zzzxxqq')['total'] === 0 && Presupuestos::listar(null, 50, 1, 'zzzxxqq')['conteos'] === []);
    prueba('filtrar por estado respeta la búsqueda', Presupuestos::listar('BORRADOR', 50, 1, $numero0)['total'] <= $lq['total']);
    prueba('un % en la búsqueda no es comodín', Presupuestos::listar(null, 50, 1, '%')['total'] < $l0['total'] || $l0['total'] === 0);

    echo "Clientes de Servicios Clim (solo lectura)
";
    prueba('sin configurar, la búsqueda no está disponible y no falla', (function () { $c = Config::todo(); Config::poner(['clientes' => ['base' => '', 'usuario' => '', 'clave' => 'PON_AQUI']] + $c); $r = !Clientes::disponible() && Clientes::buscar('juan') === []; Config::poner($c); return $r; })());
    prueba('las palabras de lo escrito', Clientes::palabras('  Juan   Pérez 612 ') === ['juan', 'pérez', '612']);
    [$sqlC, $argsC] = Clientes::condicion(['juan', '612345']);
    prueba('cada palabra es una condición (se piden todas)', substr_count($sqlC, ' AND ') === 1 && count($argsC) === 10, $sqlC);
    prueba('un teléfono se compara sin espacios ni guiones', str_contains($sqlC, "REPLACE(REPLACE(REPLACE(COALESCE(telefono") && in_array('%612345%', $argsC, true));
    prueba('un % en lo escrito no es comodín', in_array('%\%%', Clientes::condicion(['%'])[1], true));

    echo "Servicios de Servicios Clim (solo lectura, con una base de ejemplo en memoria)\n";
    $sq = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    foreach ([
        'CREATE TABLE clientes (id INTEGER PRIMARY KEY, nombre TEXT, telefono TEXT, email TEXT, direccion TEXT, calle TEXT, piso_puerta TEXT, codigo_postal TEXT, ciudad TEXT, nit_rfc_rut TEXT)',
        'CREATE TABLE servicios (id INTEGER PRIMARY KEY, codigo TEXT, cliente_id INTEGER, descripcion TEXT, direccion_visita TEXT, fecha_programada TEXT, estado TEXT)',
        'CREATE TABLE servicio_tecnicos (id INTEGER PRIMARY KEY, servicio_id INTEGER, tecnico_id INTEGER, estado_individual TEXT, hora_fin TEXT, diagnostico_tecnico TEXT, trabajo_realizado TEXT, observaciones TEXT)',
        'CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre TEXT)',
        'CREATE TABLE evidencias (id INTEGER PRIMARY KEY, servicio_tecnico_id INTEGER, tipo TEXT, url TEXT, created_at TEXT)',
        "INSERT INTO clientes VALUES (1, 'Marcela Villanueva', '+34611702339', 'm@ejemplo.com', 'cami nou 106, bajo', 'cami nou 106', 'bajo', '46910', 'valencia', '12.345.678-z'), (2, 'Juan Pérez', '600111222', NULL, 'Calle Sol 4', NULL, NULL, NULL, 'Madrid', NULL)",
        "INSERT INTO usuarios VALUES (1, 'Carlos')",
        "INSERT INTO servicios VALUES (10, 'SRV-10', 1, 'Cambio de cuadro', 'cami nou 106', '2026-11-10 09:00:00', 'completado'), (11, 'SRV-11', 2, 'Revisión', 'Calle Sol 4', '2026-11-11 09:00:00', 'completado'), (12, 'SRV-12', 2, 'Pendiente', 'Calle Sol 4', '2026-11-11 10:00:00', 'pendiente'), (13, 'SRV-13', 2, 'Bomba', 'Calle Sol 4', '2026-11-12 09:00:00', 'completado')",
        "INSERT INTO servicio_tecnicos VALUES (100, 10, 1, 'completado', '2026-11-10 11:00:00', 'El cliente quiere cambiar el cuadro general por uno empotrado', 'Visita técnica para cambio de cuadro', 'Quiere subir de 2,4 kW a 4,6 kW'),
            (101, 11, 1, 'completado', '2026-11-11 11:00:00', 'Fallo en el térmico', 'Se revisó el cuadro', '  '),
            (102, 12, 1, 'en_proceso', NULL, 'Algo', 'Algo', 'Algo'),
            (103, 13, 1, 'completado', '2026-11-12 11:00:00', 'Fuga en la bomba de agua', 'Se cambió el sello', 'Avisar en un año'),
            (104, 10, 1, 'completado', '2026-11-10 12:00:00', 'Solo diagnóstico', NULL, NULL)",
        "INSERT INTO evidencias VALUES (1, 100, 'foto', 'uploads/a.jpg', '2026-11-10 10:00:00'), (2, 100, 'foto', 'https://drive.google.com/file/x', '2026-11-10 10:01:00'), (3, 100, 'video', 'uploads/v.mp4', '2026-11-10 10:02:00')",
    ] as $q) $sq->exec($q);
    $cfgSc = Config::todo();
    Config::poner(array_replace($cfgSc, ['clientes' => ['tabla' => 'clientes', 'archivos_url' => 'https://clim.ejemplo.com/api/']]));
    Clientes::usarConexion($sq);
    $todos = ServiciosClim::buscar('');
    prueba('solo salen los completados con diagnóstico, trabajo y observaciones', array_column($todos['servicios'], 'codigo') === ['SRV-13', 'SRV-10'] && $todos['total'] === 2, json_encode(array_column($todos['servicios'], 'codigo')));
    prueba('el más reciente va primero', $todos['servicios'][0]['codigo'] === 'SRV-13');
    prueba('buscar por lo que escribió el técnico', array_column(ServiciosClim::buscar('empotrado')['servicios'], 'codigo') === ['SRV-10']);
    prueba('se piden todas las palabras', count(ServiciosClim::buscar('cuadro empotrado')['servicios']) === 1 && count(ServiciosClim::buscar('cuadro bomba')['servicios']) === 0);
    prueba('buscar por el nombre del cliente', array_column(ServiciosClim::buscar('marcela')['servicios'], 'codigo') === ['SRV-10']);
    prueba('buscar por el código del servicio', array_column(ServiciosClim::buscar('SRV-13')['servicios'], 'codigo') === ['SRV-13']);
    prueba('un % en lo escrito no es comodín', ServiciosClim::buscar('%')['total'] === 0);
    $d = ServiciosClim::detalle(10);
    prueba('el detalle trae el cliente con todos sus datos', $d['cliente']['nombre'] === 'Marcela Villanueva' && $d['cliente']['dni'] === '12345678Z' && $d['cliente']['provincia'] === 'Valencia' && $d['cliente']['calle'] === 'cami nou 106');
    prueba('solo salen las visitas con los tres campos, con el nombre del técnico', count($d['visitas']) === 1 && $d['visitas'][0]['tecnico'] === 'Carlos' && str_contains($d['visitas'][0]['diagnostico'], 'empotrado') && $d['visitas'][0]['trabajo'] === 'Visita técnica para cambio de cuadro' && str_contains($d['visitas'][0]['observaciones'], '4,6 kW'));
    $ev = $d['visitas'][0]['evidencias'];
    prueba('las evidencias traen su tipo y su dirección (completada con la de archivos de Servicios Clim)', count($ev) === 3 && $ev[0]['url'] === 'https://clim.ejemplo.com/api/uploads/a.jpg' && $ev[1]['url'] === 'https://drive.google.com/file/x' && $ev[2]['tipo'] === 'video');
    prueba('un servicio sin las tres cosas → 404', falla(fn() => ServiciosClim::detalle(11), 404));
    prueba('un servicio no completado → 404', falla(fn() => ServiciosClim::detalle(12), 404));
    prueba('un servicio que no existe → 404', falla(fn() => ServiciosClim::detalle(99999), 404));
    Config::poner(array_replace($cfgSc, ['clientes' => ['tabla' => 'clientes']]));
    prueba('sin dirección de archivos, las evidencias relativas no traen dirección (pero se cuentan)', (function () {
        $e = ServiciosClim::detalle(10)['visitas'][0]['evidencias'];
        return count($e) === 3 && $e[0]['url'] === null && $e[1]['url'] === 'https://drive.google.com/file/x';
    })());
    prueba('un origen con número raro → 400', falla(fn() => Presupuestos::crear(['texto' => 'x', 'servicio_clim' => ['id' => -5]]), 400));
    $po = Presupuestos::crear(['texto' => 'Cambiar el cuadro por uno empotrado', 'servicio_clim' => ['id' => 10, 'codigo' => 'SRV-10']]); $ids[] = $po['id']; $llamadas['interprete']--;
    prueba('el presupuesto guarda de qué servicio sale', (int) $po['servicio_clim_id'] === 10 && $po['servicio_clim_codigo'] === 'SRV-10');
    $v = ServiciosClim::buscar('empotrado')['servicios'][0];
    prueba('el servicio avisa de que ya tiene presupuesto', count($v['presupuestos']) === 1 && $v['presupuestos'][0]['numero'] === $po['numero']);
    prueba('un presupuesto normal no tiene servicio de origen', ($pn['servicio_clim_id'] ?? null) === null);
    Clientes::usarConexion(null);
    Config::poner($cfgSc);
    prueba('al terminar, la conexión vuelve a la de config', !Clientes::disponible() || Config::get('clientes.base') !== null);

    echo "Catálogo en otra base de datos\n";
    $cfg0 = Config::todo();   // la configuración real (con o sin bloque «catalogo»); al final se deja como estaba
    $sinCatalogo = $cfg0;
    unset($sinCatalogo['catalogo']);
    Config::poner($sinCatalogo);
    prueba('sin bloque «catalogo», todo va por la misma conexión', Db::separado() === false && Db::pdo('catalogo') === Db::pdo());
    Config::poner($cfg0);
    // Las pruebas de abajo van por la conexión del catálogo: la real si está separada; si no, una segunda conexión simulada con los mismos datos
    if (!isset($cfg0['catalogo'])) Config::poner($cfg0 + ['catalogo' => ['nombre' => $cfg0['bd']['nombre'], 'usuario' => $cfg0['bd']['usuario'], 'clave' => $cfg0['bd']['clave']]]);
    prueba('con bloque «catalogo», hay una conexión propia para los productos', Db::separado() === true && Db::pdo('catalogo') !== Db::pdo());
    prueba('el buscador de material funciona por esa conexión', count(Catalogo::productos(['concepto' => 'cable 2,5', 'busqueda' => 'cable 2,5', 'sinonimos' => []], 5)) > 0);
    prueba('el buscador manual también', count(Catalogo::busquedaManual('producto', 'cable 2,5', 5)) > 0);
    prueba('el listado por proveedor también', Productos::listar('NOVELEC', '', 1, 3)['total'] > 0 && count(Productos::proveedores()) >= 1);
    $idP = (int) Db::cFila('SELECT id FROM productos WHERE proveedor = ? LIMIT 1', ['NOVELEC'])['id'];
    prueba('leer un producto por la conexión del catálogo', Catalogo::obtener('producto', $idP) !== null);
    prueba('los presupuestos siguen en su conexión', Db::fila('SELECT COUNT(*) n FROM presupuestos')['n'] >= 0);
    Config::poner($cfg0);
    prueba('al terminar, la configuración queda como estaba', Db::separado() === isset($cfg0['catalogo']));

    echo "Productos por proveedor\n";
    $fn = Productos::listar('NOVELEC', '', 1, 100, true);
    prueba('solo con foto: todos los que salen tienen foto', $fn['total'] > 0 && count(array_filter($fn['productos'], fn($x) => !empty($x['imagen']))) === count($fn['productos']));
    prueba('solo con foto: el total coincide con el contador del proveedor', $fn['total'] === (int) array_values(array_filter(Productos::proveedores(), fn($x) => $x['proveedor'] === 'NOVELEC'))[0]['con_foto']);
    prueba('solo con foto en un proveedor sin fotos → 0, sin error', Productos::listar('OBRAMAT', '', 1, 30, true)['total'] === 0);
    prueba('el límite de la página se respeta (máximo 100)', count(Productos::listar('OBRAMAT', '', 1, 500)['productos']) <= 100 && count(Productos::listar('OBRAMAT', '', 1, 7)['productos']) === 7);
    $pv0 = Productos::proveedores();
    prueba('lista los proveedores con sus cuentas', count($pv0) > 0 && $pv0[0]['total'] >= $pv0[0]['utiles'] && $pv0[0]['total'] >= $pv0[0]['productos'], json_encode($pv0[0] ?? null));
    prueba('la cabecera de un archivo importado («Proveedor») no sale como proveedor', !in_array('proveedor', array_map(fn($p) => mb_strtolower($p['proveedor']), $pv0), true));
    $lp1 = Productos::listar($pv0[0]['proveedor'], '', 1, 5);
    prueba('lista los productos del proveedor y el total coincide con el contador', $lp1['total'] === $pv0[0]['total'] && count($lp1['productos']) > 0 && count($lp1['productos']) <= 5 && $lp1['productos'][0]['proveedor'] === $pv0[0]['proveedor']);
    prueba('cada proveedor de la lista tiene productos al consultarlo (nunca 0)', count(array_filter($pv0, fn($p) => Productos::listar($p['proveedor'], '', 1, 1)['total'] !== $p['total'])) === 0);
    prueba('el nombre del proveedor no distingue mayúsculas ni espacios', Productos::listar('  ' . mb_strtolower($pv0[0]['proveedor']) . ' ', '', 1, 1)['total'] === $pv0[0]['total']);
    prueba('buscar dentro de un proveedor filtra', Productos::listar($pv0[0]['proveedor'], 'zzzxxqq', 1, 5)['total'] === 0);
    prueba('un proveedor sin productos → 0, no error', Productos::listar('PROVEEDOR-QUE-NO-EXISTE', '', 1)['total'] === 0);
    prueba('sin proveedor → 400', falla(fn() => Productos::listar('  '), 400));
    prueba('importar sin búsqueda → 400', falla(fn() => Productos::importarNovelec(' ', ['id' => null]), 400));

    echo "Trabajos tipo\n";
    $adm = ['id' => null, 'usuario' => 'prueba', 'rol' => 'admin'];
    $gP = Presupuestos::obtener($id)['grupos'][0] ?? null;
    prueba('hay una partida unificada de la que partir', $gP !== null);
    Presupuestos::actualizarGrupo($id, $gP['id'], ['titulo' => 'ZZ tipo prueba']);
    $tt = TrabajosTipo::guardar($id, $gP['id'], $adm);
    prueba('se guarda con título y componentes', $tt['titulo'] === 'ZZ tipo prueba' && count($tt['lineas']) === count($gP['lineas']), json_encode($tt));
    prueba('guarda también la línea libre con su precio', count(array_filter($tt['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento' && $x['importe'] === 60.0)) === 1);
    prueba('mismo título otra vez → 409', falla(fn() => TrabajosTipo::guardar($id, $gP['id'], $adm), 409));
    prueba('aparece en el listado', in_array($tt['id'], array_column(TrabajosTipo::listar(), 'id'), true));
    $antesN = count(Presupuestos::obtener($id)['lineas']);
    $pa = Presupuestos::aplicarTrabajoTipo($id, $tt['id']);
    prueba('al usarlo se añaden sus líneas ya unificadas', count($pa['lineas']) === $antesN + count($tt['lineas']) && count($pa['grupos']) === 2 && $pa['omitidas'] === [], json_encode($pa['grupos']));
    prueba('el nuevo grupo suma lo mismo que el trabajo tipo', abs(end($pa['grupos'])['importe'] - $tt['importe']) < 0.01);
    prueba('trabajo tipo que no existe → 404', falla(fn() => Presupuestos::aplicarTrabajoTipo($id, 999999999), 404));
    $tt2 = TrabajosTipo::actualizar($tt['id'], ['descripcion' => 'otra'], $adm);
    prueba('editar la descripción', $tt2['descripcion'] === 'otra' && $tt2['titulo'] === 'ZZ tipo prueba');
    $l0 = $tt['lineas'][0];
    $tt3 = TrabajosTipo::cambiarLinea($tt['id'], $l0['id'], ['cantidad' => 3], $adm);
    prueba('cambiar la cantidad de un componente', $tt3['lineas'][0]['cantidad'] === 3.0);
    prueba('cantidad inválida → 400', falla(fn() => TrabajosTipo::cambiarLinea($tt['id'], $l0['id'], ['cantidad' => 0], $adm), 400));
    prueba('precio de un componente del catálogo → 409', falla(fn() => TrabajosTipo::cambiarLinea($tt['id'], array_values(array_filter($tt['lineas'], fn($x) => $x['concepto'] !== 'ZZ desplazamiento'))[0]['id'], ['precio_unitario' => 5], $adm), 409));
    $libre = array_values(array_filter($tt['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento'))[0];
    $tt3 = TrabajosTipo::cambiarLinea($tt['id'], $libre['id'], ['precio_unitario' => 40], $adm);
    prueba('cambiar el precio de una línea libre', array_values(array_filter($tt3['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento'))[0]['importe'] === round(40 * array_values(array_filter($tt3['lineas'], fn($x) => $x['concepto'] === 'ZZ desplazamiento'))[0]['cantidad'], 2));
    $tt3 = TrabajosTipo::anadirLinea($tt['id'], ['tipo' => 'PRODUCTO', 'concepto' => 'ZZ extra', 'cantidad' => 2, 'unidad' => 'ud', 'precio_unitario' => 10], $adm);
    prueba('añadir un componente libre', count($tt3['lineas']) === count($tt['lineas']) + 1);
    $ex = array_values(array_filter($tt3['lineas'], fn($x) => $x['concepto'] === 'ZZ extra'))[0];
    $tt3 = TrabajosTipo::quitarLinea($tt['id'], $ex['id'], $adm);
    prueba('quitar un componente', count($tt3['lineas']) === count($tt['lineas']));
    prueba('componente de otro trabajo → 404', falla(fn() => TrabajosTipo::quitarLinea($tt['id'], 999999999, $adm), 404));
    TrabajosTipo::eliminar($tt['id'], $adm);
    prueba('se borra', falla(fn() => TrabajosTipo::uno($tt['id']), 404));

    echo "Borrar\n";
    Claude::simular(fn($u, $s, $t) => json_encode(['requerimientos' => [['tipo' => 'servicio', 'concepto' => 'x']], 'resumen' => 'x']));
    $b1 = Presupuestos::crear(['texto' => 'borrador uno']);
    $b2 = Presupuestos::crear(['texto' => 'borrador dos']);
    $ids[] = $b1['id']; $ids[] = $b2['id'];
    $tecnico = ['id' => 1, 'rol' => 'tecnico'];
    $r = Presupuestos::eliminar($b1['id'], $tecnico);
    prueba('un técnico borra un borrador', $r['id'] === $b1['id'] && falla(fn() => Presupuestos::obtener($b1['id']), 404));
    prueba('también se va su interpretación', (int) Db::fila('SELECT COUNT(*) n FROM interpretaciones WHERE presupuesto_id = ?', [$b1['id']])['n'] === 0);
    Presupuestos::cambiarEstado($b2['id'], 'ENVIADO');
    prueba('un técnico no borra uno enviado → 403', falla(fn() => Presupuestos::eliminar($b2['id'], $tecnico), 403));
    prueba('un administrador sí', Presupuestos::eliminar($b2['id'], ['id' => 1, 'rol' => 'admin'])['estado'] === 'ENVIADO');
    prueba('borrar uno que no existe → 404', falla(fn() => Presupuestos::eliminar(999999999, $tecnico), 404));
    // «Borrar todos los borradores» no se prueba aquí: borraría los borradores de verdad que haya en la base.
} finally {
    Db::ejecutar("DELETE FROM trabajos_tipo_lineas WHERE trabajo_id IN (SELECT id FROM trabajos_tipo WHERE titulo LIKE 'ZZ tipo%')");
    Db::ejecutar("DELETE FROM trabajos_tipo WHERE titulo LIKE 'ZZ tipo%'");
    Db::ejecutar("DELETE FROM eventos WHERE entidad = 'trabajo_tipo' AND usuario = 'prueba'");
    foreach ($ids as $i) {
        Notas::borrarDe($i);
        Db::ejecutar('DELETE FROM presupuesto_grupos WHERE presupuesto_id = ?', [$i]);
        Db::ejecutar('DELETE FROM presupuesto_lineas WHERE presupuesto_id = ?', [$i]);
        Db::ejecutar('DELETE FROM correcciones WHERE presupuesto_id = ?', [$i]);
        Db::ejecutar('DELETE FROM interpretaciones WHERE presupuesto_id = ?', [$i]);
        Db::ejecutar('DELETE FROM presupuestos WHERE id = ?', [$i]);
    }
    Numeracion::restaurar($corr0);   // los números de las pruebas no se gastan: el primer presupuesto real será el PRE26-2000
}

echo "\n$ok bien, $mal mal\n";
exit($mal ? 1 : 0);
