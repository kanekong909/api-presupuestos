<?php
// EMPAREJADOR — la IA elige, entre los candidatos de la base, el que corresponde a cada requerimiento.
// La IA solo ELIGE ids; los precios los pone siempre el motor leyendo la base.

final class Emparejador
{
    /**
     * @param array $reqs        requerimientos de la interpretación
     * @param array $candidatos  [id requerimiento => [candidatos…]]
     * @return array [id requerimiento => ['elegido' => ['fuente','id']|null, 'cantidad', 'confianza', 'motivo', 'faltan' => []]]
     */
    public static function elegir(array $reqs, array $candidatos, ?int $excluirPresupuesto = null): array
    {
        $texto = '';
        $conCandidatos = 0;
        foreach ($reqs as $r) {
            $c = $candidatos[$r['id']] ?? [];
            $texto .= "REQUERIMIENTO {$r['id']} [{$r['tipo']}, área " . ($r['area'] ?? 'GEN') . "]: {$r['concepto']} | cantidad: " . ($r['cantidad'] ?? 'no indicada') . ' | unidad: ' . ($r['unidad'] ?? '-') . " | detalles: {$r['detalles']}\n";
            if (!$c) { $texto .= "  (sin candidatos)\n\n"; continue; }
            $conCandidatos++;
            foreach ($c as $x) {
                $texto .= "  - {$x['fuente']} id={$x['id']} | {$x['nombre']} | " . ($x['fuente'] === 'servicio' ? "código {$x['codigo']} | " : '') . ($x['proveedor'] ? "{$x['proveedor']} | " : '') . "unidad {$x['unidad']} | precio " . number_format($x['precio'], 2, '.', '') . " ({$x['modo_precio']})\n";
            }
            $texto .= "\n";
        }
        $res = [];
        foreach ($reqs as $r) $res[$r['id']] = ['elegido' => null, 'cantidad' => $r['cantidad'], 'confianza' => 0, 'motivo' => 'No hay candidatos en la base.', 'faltan' => [], 'alternativas' => [], 'regla' => null];
        if ($conCandidatos === 0) return $res;

        $sistema = (string) file_get_contents(RAIZ . '/prompts/emparejar.txt');
        if ($pref = Ajustes::lista('proveedor_producto')) $sistema .= "

PROVEEDORES PREFERIDOS DE MATERIAL, por orden de preferencia: " . implode(', ', $pref) . '. Elige del primero que tenga algo que cumpla; si ninguno de ese sirve, del siguiente. Los proveedores que no están en la lista solo si no sirve ninguno de ella.';
        // En fotovoltaica el equipo (módulos, baterías, inversores) lo compra otro proveedor: se vio en el uso real (los técnicos lo cambiaban a mano)
        if ($fv = Ajustes::lista('proveedor_fotovoltaica')) $sistema .= "
Para el material de FOTOVOLTAICA (requerimientos con área FOT) el orden de preferencia es este, en lugar del anterior: " . implode(', ', $fv) . '.';
        // Lo que los técnicos han corregido en pedidos parecidos: la IA aprende de ahí (decisiones de personas, no suyas)
        if ($ej = Aprendizaje::ejemplos($reqs, $excluirPresupuesto)) {
            $sistema .= "\n\nPREFERENCIAS APRENDIDAS. En pedidos parecidos los técnicos cambiaron a mano lo que eligió la IA. Úsalas como guía fuerte (si el candidato que prefirió el técnico está en tu lista, elígelo), pero no como regla absoluta si el pedido actual es distinto:\n" . $ej;
        }
        $sistema .= Reglas::paraPrompt();
        $j = Claude::json(Claude::preguntar('emparejar', $sistema, $texto)['texto']);
        if ($j === null) throw new ErrorApi('La IA no ha devuelto una selección válida. Inténtalo otra vez.', 502);

        foreach ((array) ($j['elecciones'] ?? []) as $e) {
            $id = (int) ($e['requerimiento'] ?? 0);
            if (!isset($res[$id]) || empty($candidatos[$id])) continue;
            $el = $e['elegido'] ?? null;
            // Solo vale un elegido que esté realmente entre los candidatos que se le dieron
            $valido = null;
            if (is_array($el)) foreach ($candidatos[$id] as $c) if ($c['fuente'] === ($el['fuente'] ?? '') && $c['id'] === (int) ($el['id'] ?? 0)) $valido = ['fuente' => $c['fuente'], 'id' => $c['id']];
            $cant = $e['cantidad'] ?? null;
            $res[$id] = [
                'elegido' => $valido,
                'cantidad' => is_numeric($cant) && (float) $cant > 0 ? (float) $cant : null,
                'confianza' => $valido ? max(0, min(100, (float) ($e['confianza'] ?? 0))) : min(40, (float) ($e['confianza'] ?? 0)),
                'motivo' => trim((string) ($e['motivo'] ?? '')),
                'regla' => $valido ? Reglas::nombreValido($e['regla'] ?? null) : null,   // solo vale el nombre de una regla que existe y está activa
                'faltan' => array_values(array_filter(array_map('strval', (array) ($e['faltan'] ?? [])))),
                'alternativas' => self::alternativas((array) ($e['alternativas'] ?? []), $candidatos[$id], $valido),
            ];
        }
        return $res;
    }

    /** Las alternativas que propone la IA, solo si de verdad están entre los candidatos, sin repetir el elegido, hasta 3. */
    private static function alternativas(array $ids, array $candidatos, ?array $elegido): array
    {
        $out = [];
        foreach ($ids as $a) {
            $aid = (int) (is_array($a) ? ($a['id'] ?? 0) : $a);
            foreach ($candidatos as $c) {
                if ($c['id'] !== $aid || ($elegido && $elegido['id'] === $aid && $elegido['fuente'] === $c['fuente'])) continue;
                $out[$c['fuente'] . ':' . $aid] = ['fuente' => $c['fuente'], 'id' => $aid];
            }
            if (count($out) >= 3) break;
        }
        return array_values($out);
    }
}
