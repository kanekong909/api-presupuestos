<?php
// M2 INTERPRETADOR IA — entiende lo que se pide y lo devuelve estructurado (finalidad, grupos, requerimientos, datos que faltan).

final class Interprete
{
    /** @param array|null $anterior  interpretación previa (para «Seguir editando»: solo se reinterpreta el cambio) */
    public static function interpretar(string $texto, ?array $anterior = null, ?string $cambio = null): array
    {
        $sistema = (string) file_get_contents(RAIZ . '/prompts/interprete.txt');
        $sin = Db::filas('SELECT termino, concepto_canonico FROM sinonimos WHERE activo = 1 LIMIT 300');
        if ($sin) {
            $sistema .= "\n\nSINÓNIMOS DE LA EMPRESA (usa el concepto canónico en «concepto» y deja el término en «sinonimos»):\n";
            foreach ($sin as $s) $sistema .= "- {$s['termino']} = {$s['concepto_canonico']}\n";
        }
        $sistema .= Reglas::paraPrompt();
        $usuario = "PEDIDO DEL CLIENTE:\n$texto";
        if ($anterior !== null && $cambio !== null) {
            $usuario .= "\n\nINTERPRETACIÓN ANTERIOR:\n" . json_encode($anterior, JSON_UNESCAPED_UNICODE) . "\n\nCAMBIO DEL USUARIO:\n$cambio";
        }
        $r = Claude::preguntar('interprete', $sistema, $usuario);
        $j = Claude::json($r['texto']);
        if ($j === null) throw new ErrorApi('La IA no ha devuelto una interpretación válida. Inténtalo otra vez.', 502);
        return self::ordenar($j, (array) ($anterior['confirmaciones'] ?? []), $texto . ' ' . ($cambio ?? ''));
    }

    public const AREAS = ['ELE', 'VE', 'FOT', 'CLI', 'DOM', 'SEG', 'PCI', 'ALT', 'GEN'];
    private const AREA_DE_GRUPO = ['electricidad' => 'ELE', 'vehículo eléctrico' => 'VE', 'fotovoltaica' => 'FOT', 'climatización' => 'CLI', 'domótica' => 'DOM', 'seguridad' => 'SEG', 'protección contra incendios' => 'PCI', 'alta tensión' => 'ALT'];

    /** El área del requerimiento (línea de la base de servicios): la que dice la IA o, si no vale, la del grupo principal. */
    public static function area(mixed $a, string $grupo): string
    {
        $a = mb_strtoupper(trim((string) $a));
        return in_array($a, self::AREAS, true) ? $a : (self::AREA_DE_GRUPO[mb_strtolower(trim($grupo))] ?? 'GEN');
    }

    /** Se queda con lo esperado y rellena lo que falte, para que el resto del sistema no tenga que dudar. */
    private static function ordenar(array $j, array $confirmaciones = [], string $textoPedido = ''): array
    {
        $reqs = [];
        foreach ((array) ($j['requerimientos'] ?? []) as $i => $r) {
            if (!is_array($r) || trim((string) ($r['concepto'] ?? '')) === '') continue;
            $cant = $r['cantidad'] ?? null;
            $reqs[] = [
                'id' => count($reqs) + 1,
                'tipo' => ($r['tipo'] ?? '') === 'producto' ? 'producto' : 'servicio',
                'area' => self::area($r['area'] ?? null, (string) ($j['grupo_principal'] ?? '')),
                'concepto' => trim((string) $r['concepto']),
                'busqueda' => mb_substr(trim((string) ($r['busqueda'] ?? '')), 0, 80),
                'cantidad' => is_numeric($cant) && (float) $cant > 0 ? (float) $cant : null,
                'unidad' => ($u = trim((string) ($r['unidad'] ?? ''))) === '' ? null : $u,
                'detalles' => trim((string) ($r['detalles'] ?? '')),
                'sinonimos' => array_values(array_filter(array_map('strval', (array) ($r['sinonimos'] ?? [])))),
            ];
        }
        $faltan = [];
        foreach (self::limpiarPreguntas((array) ($j['datos_faltantes'] ?? [])) as $d) $faltan[] = $d;
        $faltan = array_merge($faltan, self::revisarClimatizacion($reqs, $confirmaciones), self::revisarVariantes($reqs, $confirmaciones), self::revisarSoloMaterial($reqs, $textoPedido, $confirmaciones));
        return [
            'finalidad' => (string) ($j['finalidad'] ?? 'presupuesto'),
            'grupo_principal' => (string) ($j['grupo_principal'] ?? 'General'),
            'grupos_relacionados' => array_values(array_map('strval', (array) ($j['grupos_relacionados'] ?? []))),
            'requerimientos' => $reqs,
            'datos_faltantes' => $faltan,
            'resumen' => trim((string) ($j['resumen'] ?? '')),
            'confirmaciones' => array_values(array_unique(array_map('strval', $confirmaciones))),
        ];
    }

    /**
     * Solo se deja pasar una pregunta si sirve para ELEGIR el producto, servicio, cantidad o variante del catálogo: la IA tiene que decir qué elección
     * cambia («afecta»), y las preguntas de diagnóstico de averías (olor a quemado, zonas calientes, si vuelve a saltar…) se descartan siempre.
     * @return array<int, array{campo:string, pregunta:string, afecta:string}>
     */
    public static function limpiarPreguntas(array $datos): array
    {
        $out = [];
        foreach ($datos as $d) {
            if (!is_array($d)) continue;
            $pregunta = trim((string) ($d['pregunta'] ?? ''));
            $afecta = trim((string) ($d['afecta'] ?? ''));
            if ($pregunta === '' || $afecta === '') continue;
            if (preg_match('/olor a quemado|huele a quemado|zonas? (muy )?calientes?|vuelve a saltar|vuelto a saltar|ha vuelto a|desde cu[aá]ndo|cu[aá]ndo (empez|ocurri|pas[oó])|qu[eé] (equipos|aparatos|electrodom[eé]sticos) (tiene|hay|estaban|est[aá]n)|enchuf(ado|ar) algo|tormenta|chispas?/iu', $pregunta)) continue;
            $out[] = ['campo' => (string) ($d['campo'] ?? ''), 'pregunta' => $pregunta, 'afecta' => $afecta];
        }
        return $out;
    }

    /** Las confirmaciones que se pueden dar a las preguntas de configuración del aire acondicionado. */
    public const CONFIRMACIONES = ['ac_solo_interior', 'ac_solo_exterior', 'ac_desajuste', 'solo_material'];

    /**
     * Regla general del aire acondicionado: todo equipo (split, conductos, cassette…) lleva, como mínimo, una unidad exterior y una interior compatibles,
     * y una exterior puede llevar varias interiores (2×1, 3×1…). Si el pedido tiene solo un lado, o los números no cuadran, devuelve una pregunta
     * que el técnico debe confirmar (o corregir) antes de continuar. Una pregunta ya confirmada no vuelve a salir.
     * @return array<int, array{campo:string, pregunta:string, confirmar:string, corregir:string}>
     */
    public static function revisarClimatizacion(array $reqs, array $confirmaciones = []): array
    {
        $exteriores = 0.0;
        $interiores = 0.0;
        $texto = '';
        foreach ($reqs as $r) {
            if (($r['tipo'] ?? '') !== 'producto') continue;
            $t = mb_strtolower(($r['concepto'] ?? '') . ' ' . ($r['busqueda'] ?? ''));
            $esEquipo = preg_match('/aire acondicionado|climatizador|multi ?split|\bsplit\b|cass?ette|casete|unidad (interior|exterior)|evaporadora|condensadora/u', $t)
                || (preg_match('/conductos/u', $t) && preg_match('/unidad|equipo|aire/u', $t));
            if (!$esEquipo || preg_match('/soporte|bancada|tuber|cable|mando|bomba de cond|kit|rejilla|difusor|pl[eé]num|desag|canaleta|embellecedor|manguera|aislante|flexible|filtro|goma|antivibr/u', $t)) continue;
            $cant = ($r['cantidad'] ?? null) !== null ? (float) $r['cantidad'] : 1.0;
            $ext = (bool) preg_match('/exterior|condensadora/u', $t);
            $int = (bool) preg_match('/interior|evaporadora/u', $t);
            if ($ext && !$int) $exteriores += $cant;
            elseif ($int && !$ext) $interiores += $cant;
            else { $exteriores += $cant; $interiores += $cant; }   // «equipo split» sin más: se entiende completo
            $texto .= ' ' . $t;
        }
        if ($exteriores <= 0 && $interiores <= 0) return [];

        $pregunta = null;
        if ($interiores > 0 && $exteriores <= 0) {
            $pregunta = ['ac_solo_interior', 'Has pedido solo la unidad interior. Un equipo de aire acondicionado se forma con una unidad exterior y una interior compatibles. ¿Quieres presupuestar únicamente la unidad interior (por ejemplo, para sustituir una que ya tienes)?', 'Añade también la unidad exterior compatible con la unidad interior'];
        } elseif ($exteriores > 0 && $interiores <= 0) {
            $pregunta = ['ac_solo_exterior', 'Has pedido solo la unidad exterior. Un equipo de aire acondicionado se forma con una unidad exterior y una interior compatibles. ¿Quieres presupuestar únicamente la unidad exterior (por ejemplo, para sustituir una que ya tienes)?', 'Añade también la unidad interior compatible con la unidad exterior'];
        } elseif ($interiores < $exteriores) {
            $pregunta = ['ac_desajuste', 'Hay ' . self::n($exteriores) . ' unidades exteriores y solo ' . self::n($interiores) . ' interiores: cada unidad exterior necesita al menos una interior compatible. ¿Es correcto?', 'Añade las unidades interiores que faltan para que cada unidad exterior tenga al menos una'];
        } elseif (preg_match('/(\d)\s*[x×]\s*1\b/u', $texto, $m) && (int) $m[1] > 1 && abs($interiores - (int) $m[1] * $exteriores) > 0.001) {
            $pregunta = ['ac_desajuste', 'Se indica una configuración ' . $m[1] . '×1 (una unidad exterior con ' . $m[1] . ' interiores) pero hay ' . self::n($exteriores) . ' exteriores y ' . self::n($interiores) . ' interiores. ¿Es correcto?', 'Ajusta las unidades: una exterior por cada ' . $m[1] . ' interiores'];
        }
        if (!$pregunta || in_array($pregunta[0], $confirmaciones, true)) return [];
        return [['campo' => 'configuracion_aire', 'pregunta' => $pregunta[1], 'confirmar' => $pregunta[0], 'corregir' => $pregunta[2]]];
    }

    /**
     * Red de seguridad de la regla «una referencia = un requerimiento»: si un solo requerimiento menciona varios valores distintos de la misma
     * magnitud (10 A, 20 A y 16 A; 20 mm y 25 mm; 1P y 2P…), lo normal es que sean productos diferentes que la IA ha juntado. Se pregunta
     * antes de continuar: separarlos (la IA vuelve a interpretar) o confirmar que es un solo producto.
     * @return array<int, array{campo:string, pregunta:string, confirmar:string, corregir:string, confirmar_texto:string}>
     */
    public static function revisarVariantes(array $reqs, array $confirmaciones = []): array
    {
        // Los metros («60 m», «55 m») son la CANTIDAD de cable o tubo, no una variante del producto: nunca cuentan (dos mediciones de lo mismo no son dos productos)
        $out = [];
        foreach ($reqs as $r) {
            if (($r['tipo'] ?? '') !== 'producto') continue;
            // Los detalles pueden listar OPCIONES por decidir («sensibilidad: 30 mA o 300 mA», «(10 A o 16 A)», «a confirmar…»): no son productos distintos
            $detalles = preg_replace('/\([^)]*\)/u', ' ', (string) ($r['detalles'] ?? ''));
            $detalles = implode('. ', array_filter(preg_split('/(?<=[.;])\s+/u', $detalles), fn($f) => !preg_match('/sensibilidad|por definir|por decidir|pendiente|a confirmar|a elegir|opci[oó]n|opciones|por ejemplo|ej\.|u otra|o bien|\bsin (indicar|especificar)\b|no (se )?(indica|especifica|dice)/iu', $f)));
            $texto = ($r['concepto'] ?? '') . ' ' . $detalles;
            $valores = [];   // unidad → [valor => true]
            if (preg_match_all('/(?<![\p{L}\d])(\d+(?:[.,]\d+)?)\s*(kva|kwp|kw|wp|ma|ka|mm²|mm2|mm|cm|kg|w|v)(?![\p{L}\d])/iu', $texto, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) $valores[str_replace('mm2', 'mm²', mb_strtolower($x[2]))]['v' . (float) str_replace(',', '.', $x[1])] = $x[1] . ' ' . $x[2];
            }
            if (preg_match_all('/(?<![\p{L}\d])(\d+(?:[.,]\d+)?)\s*A(?![\p{L}\d])/u', $texto, $m, PREG_SET_ORDER)) {   // amperios: «A» en mayúscula (para no confundir con la preposición «a»)
                foreach ($m as $x) $valores['A']['v' . (float) str_replace(',', '.', $x[1])] = $x[1] . ' A';
            }
            if (preg_match_all('/(?<![\p{L}\d])([1-4])\s*P(?![\p{L}\d])/u', $texto, $m, PREG_SET_ORDER)) {   // polos
                foreach ($m as $x) $valores['P']['v' . (int) $x[1]] = $x[1] . 'P';
            }
            $distintos = [];
            foreach ($valores as $vs) if (count($vs) >= 2) $distintos = array_merge($distintos, array_values($vs));
            if (!$distintos) continue;
            $clave = 'variantes_' . substr(md5(mb_strtolower(trim((string) ($r['concepto'] ?? '')))), 0, 8);
            if (in_array($clave, $confirmaciones, true)) continue;
            $out[] = [
                'campo' => 'variantes_juntas',
                'pregunta' => 'En «' . trim((string) $r['concepto']) . '» aparecen varios valores distintos (' . implode(', ', $distintos) . '). Si son productos diferentes, cada uno necesita su propia partida y su propia búsqueda. ¿Los separo?',
                'confirmar' => $clave,
                'confirmar_texto' => 'No, es un solo producto',
                'corregir' => 'Separa «' . trim((string) $r['concepto']) . '» en partidas independientes: una por cada valor distinto (' . implode(', ', $distintos) . '), cada una con su cantidad',
            ];
        }
        return $out;
    }

    /**
     * Un pedido que solo tiene material y ningún servicio: lo normal es que falte la instalación (mano de obra), y quien no es técnico no lo advierte.
     * Se pregunta antes de seguir, salvo que el pedido ya diga que es solo material / suministro.
     * @return array<int, array{campo:string, pregunta:string, confirmar:string, corregir:string, confirmar_texto:string}>
     */
    public static function revisarSoloMaterial(array $reqs, string $textoPedido = '', array $confirmaciones = []): array
    {
        $hayProducto = false;
        foreach ($reqs as $r) {
            if (($r['tipo'] ?? '') === 'servicio') return [];
            if (($r['tipo'] ?? '') === 'producto') $hayProducto = true;
        }
        if (!$hayProducto || in_array('solo_material', $confirmaciones, true)) return [];
        if (preg_match('/solo (el )?(material|suministro|equipo)|solamente (el )?(material|suministro)|[úu]nicamente (el )?(material|suministro)|sin (la )?(instalaci[oó]n|mano de obra|montaje)|suministro de|ya (tengo|tenemos|tiene) (el|la|los|las)|no (hace falta|necesito|incluye|incluir) (la )?(instalaci[oó]n|mano de obra)/iu', $textoPedido)) return [];
        return [[
            'campo' => 'solo_material',
            'pregunta' => 'El presupuesto solo incluye material, sin mano de obra. ¿Quieres añadir también la instalación (montaje, conexión, puesta en marcha…)?',
            'confirmar' => 'solo_material',
            'confirmar_texto' => 'No, solo material',
            'corregir' => 'Añade también los servicios de instalación, montaje y conexión necesarios para este material',
        ]];
    }

    private static function n(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    }
}
