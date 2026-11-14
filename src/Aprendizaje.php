<?php
// APRENDIZAJE — convierte lo que los técnicos corrigen a mano en ejemplos que se le enseñan a la IA al elegir.
// Cada vez que alguien cambia el producto o servicio que eligió la IA, queda en `correcciones` («pedían X, la IA eligió Y, el técnico puso Z»).
// Aquí se buscan los cambios de pedidos PARECIDOS al actual (comparten palabras raras) y se le pasan como ejemplos. Nada se «entrena»: es
// memoria de decisiones de personas, visible en Administrar › Correcciones, y se puede borrar una corrección si estuvo mal.

final class Aprendizaje
{
    /**
     * @param array $reqs                  los requerimientos del pedido actual
     * @param int|null $excluirPresupuesto no usar las correcciones de este presupuesto (para evaluar el motor sin hacer trampa)
     * @return string líneas de ejemplo, o '' si no hay ninguna parecida
     */
    public static function ejemplos(array $reqs, ?int $excluirPresupuesto = null, int $max = 8): string
    {
        $filas = Db::filas("SELECT c.presupuesto_id, c.valor_anterior a, c.valor_nuevo n, l.requerimiento_id
                            FROM correcciones c LEFT JOIN presupuesto_lineas l ON l.id = CAST(JSON_EXTRACT(c.valor_anterior, '$.linea') AS UNSIGNED)
                            WHERE c.tipo = 'linea_elemento' AND c.ignorar = 0 AND c.presupuesto_id IS NOT NULL ORDER BY c.id DESC LIMIT 80");
        if (!$filas) return '';

        $actuales = [];
        foreach ($reqs as $r) $actuales[$r['id']] = self::palabras((string) $r['concepto'] . ' ' . ($r['busqueda'] ?? ''));

        $interps = [];
        $vistos = [];
        $out = [];
        foreach ($filas as $f) {
            $pid = (int) $f['presupuesto_id'];
            if ($pid === $excluirPresupuesto || !$f['requerimiento_id']) continue;
            if (!isset($interps[$pid])) $interps[$pid] = json_decode((string) (Db::fila('SELECT json_interpretacion j FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$pid])['j'] ?? ''), true)['requerimientos'] ?? [];
            $req = null;
            foreach ($interps[$pid] as $r) if ((int) $r['id'] === (int) $f['requerimiento_id']) $req = $r;
            $a = json_decode((string) $f['a'], true);
            $n = json_decode((string) $f['n'], true);
            if (!$req || !is_array($a) || !is_array($n) || empty($n['concepto'])) continue;

            // ¿Se parece a algo del pedido actual? (comparten alguna palabra de 4 o más letras)
            $suyas = self::palabras((string) $req['concepto'] . ' ' . ($req['busqueda'] ?? ''));
            $parecido = false;
            foreach ($actuales as $mias) if (array_intersect($suyas, $mias)) { $parecido = true; break; }
            if (!$parecido) continue;

            $clave = mb_strtolower($req['concepto']);   // de un mismo pedido, vale lo último que hizo el técnico
            if (isset($vistos[$clave])) continue;
            $vistos[$clave] = true;
            $antes = trim((string) ($a['concepto'] ?? ''));
            $noEncontro = $antes === '' || mb_stripos((string) $req['concepto'], mb_substr($antes, 0, 30)) !== false;   // la línea guardaba el pedido, no un producto
            $out[] = '- Pedido «' . mb_substr($req['concepto'], 0, 90) . '»: ' . (!$noEncontro && $antes !== $n['concepto'] ? 'la IA propuso «' . mb_substr($antes, 0, 80) . '» y el técnico lo cambió por' : 'la IA no encontró nada y el técnico eligió')
                . ' «' . mb_substr((string) $n['concepto'], 0, 90) . '»' . (!empty($n['fuente']) ? ' (' . $n['fuente'] . ')' : '') . '.';
            if (count($out) >= $max) break;
        }
        return implode("\n", $out);
    }

    /** Las palabras con carga (4 o más letras, sin plural) para comparar si dos pedidos hablan de lo mismo. */
    private static function palabras(string $texto): array
    {
        return array_values(array_filter(Catalogo::palabras([$texto]), fn($w) => mb_strlen($w) >= 4));
    }
}
