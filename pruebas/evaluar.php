<?php
// EVALUAR EL MOTOR con lo que ya ha pasado de verdad: cada vez que un técnico cambió a mano el producto o servicio que eligió la IA,
// ¿habría acertado ahora el motor? Vuelve a buscar y a elegir con la IA REAL (gasta unos pocos tokens) y compara con lo que puso el técnico.
//   php pruebas/evaluar.php            (no escribe nada en la base y no entra en Novelec)
//
// Mide dos cosas, por separado:
//   · BÚSQUEDA: ¿lo que eligió el técnico estaba entre los candidatos que se le dan a la IA?  (si no está, la IA no puede acertar)
//   · ELECCIÓN: entre los candidatos, ¿elige lo mismo que el técnico?
// Los técnicos no siempre tienen razón ni siempre eligen lo mismo, así que no se busca el 100 %: se busca que suba.

require dirname(__DIR__) . '/src/arranque.php';
Config::poner(array_replace(Config::todo(), ['productos' => ['fuente' => 'local', 'proveedores' => []]]));   // sin Novelec: sus productos ya guardados están en `productos`

$lineas = [];   // la elección FINAL del técnico en cada línea cambiada
foreach (Db::filas("SELECT DISTINCT json_extract(valor_anterior, '$.linea') AS linea FROM correcciones WHERE tipo = 'linea_elemento' AND presupuesto_id IS NOT NULL") as $c) {
    $l = Db::fila('SELECT * FROM presupuesto_lineas WHERE id = ?', [(int) $c['linea']]);
    if ($l && $l['referencia_id'] && $l['requerimiento_id']) $lineas[(int) $l['presupuesto_id']][] = $l;
}
if (!$lineas) { echo "No hay cambios hechos a mano de los que aprender todavía.\n"; exit; }

$tot = ['casos' => 0, 'en_candidatos' => 0, 'acierta' => 0];
$filas = [];
$fallos = [];
foreach ($lineas as $presId => $swaps) {
    $p = Db::fila('SELECT numero FROM presupuestos WHERE id = ?', [$presId]);
    $i = Db::fila('SELECT json_interpretacion j FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$presId]);
    $interp = json_decode((string) ($i['j'] ?? ''), true);
    if (!is_array($interp) || empty($interp['requerimientos'])) continue;
    $reqs = $interp['requerimientos'];
    foreach ($reqs as &$r) $r['area'] ??= Interprete::area(null, (string) ($interp['grupo_principal'] ?? ''));
    unset($r);

    $cand = [];
    foreach ($reqs as $r) $cand[$r['id']] = $r['tipo'] === 'producto' ? Catalogo::productos($r) : Catalogo::servicios($r);
    $elec = Emparejador::elegir($reqs, $cand, $presId);   // $presId: no se le enseñan las correcciones de este mismo presupuesto (sería hacer trampa)

    foreach ($swaps as $l) {
        $req = null;
        foreach ($reqs as $r) if ((int) $r['id'] === (int) $l['requerimiento_id']) $req = $r;
        if (!$req) continue;
        $fuente = $l['tipo'] === 'PRODUCTO' ? 'producto' : 'servicio';
        $pos = null;
        foreach ($cand[$req['id']] as $k => $c) if ($c['id'] === (int) $l['referencia_id'] && $c['fuente'] === $fuente) $pos = $k + 1;
        $pick = $elec[$req['id']]['elegido'] ?? null;
        $ok = $pick && $pick['id'] === (int) $l['referencia_id'] && $pick['fuente'] === $fuente;
        $nombrePick = $pick ? (Catalogo::obtener($pick['fuente'], $pick['id'])['nombre'] ?? '?') : '(nada)';
        $tot['casos']++; $tot['en_candidatos'] += $pos ? 1 : 0; $tot['acierta'] += $ok ? 1 : 0;
        $filas[] = [$p['numero'], mb_substr($req['concepto'], 0, 38), mb_substr($l['concepto'], 0, 38), $pos ? "#$pos" : 'NO', mb_substr($nombrePick, 0, 38), $ok ? 'SÍ' : ($pos ? 'no' : '—')];
        if (!$ok) $fallos[] = "{$p['numero']} · pedía «" . mb_substr($req['concepto'], 0, 60) . "» · técnico: «" . mb_substr($l['concepto'], 0, 50) . "» · " . ($pos ? "estaba en el puesto #$pos" : 'no estaba entre los candidatos') . " · confianza IA " . ($elec[$req['id']]['confianza'] ?? '0') . " · motivo: " . mb_substr((string) ($elec[$req['id']]['motivo'] ?? ''), 0, 260);
    }
}

printf("%-15s %-39s %-39s %-5s %-39s %s\n", 'presupuesto', 'lo que se pedía', 'lo que puso el técnico', 'cand.', 'lo que elige ahora la IA', '¿igual?');
foreach ($filas as $f) printf("%-15s %-39s %-39s %-5s %-39s %s\n", ...$f);
if (in_array('--detalle', $argv, true)) { echo "
Por qué no coincide:
"; foreach ($fallos as $x) echo " · $x
"; }
$pc = fn($a, $b) => $b ? round(100 * $a / $b) . ' %' : '-';
echo "\nCasos: {$tot['casos']}\n";
echo "BÚSQUEDA: lo del técnico estaba entre los candidatos en {$tot['en_candidatos']} de {$tot['casos']} (" . $pc($tot['en_candidatos'], $tot['casos']) . ")\n";
echo "ELECCIÓN: la IA elige lo mismo que el técnico en {$tot['acierta']} de {$tot['casos']} (" . $pc($tot['acierta'], $tot['casos']) . ") · sobre los que sí estaban entre los candidatos: " . $pc($tot['acierta'], $tot['en_candidatos']) . "\n";
