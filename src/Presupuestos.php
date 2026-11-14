<?php
// PRESUPUESTOS — recorre el flujo de la arquitectura:
//   crear (M1 normalizar → M2 interpretar → borrador) → editar (reinterpreta solo el cambio) → confirmar
//   (M3 clasificar → buscar en servicios/productos → emparejar con IA → M4 calcular → guardar) → ajustar líneas (aprendizaje).

final class Presupuestos
{
    public const ESTADOS = ['BORRADOR', 'REVISION', 'CALCULADO', 'ENVIADO', 'ACEPTADO', 'RECHAZADO'];
    private const ESTADOS_LINEA_MANUALES = ['ACEPTADO', 'REVISION', 'DESCARTADO'];

    // ---------- 1-4: entrada, normalizar, interpretar, confirmación (borrador) ----------

    public static function crear(array $entrada, ?int $usuarioId = null): array
    {
        $n = Normalizador::normalizar($entrada);
        $pago = Pagos::validar($entrada['condiciones_pago'] ?? null);   // antes de gastar la IA: si no suman 100 %, se avisa ya
        self::asegurarDatosCliente();
        $origen = ServiciosClim::origen($entrada['servicio_clim'] ?? null);   // el servicio de Servicios Clim del que sale (si sale de uno)
        $interp = Interprete::interpretar($n['texto']);
        $id = Db::insertar('presupuestos', [
            'cliente' => $n['cliente'], 'telefono' => $n['telefono'], 'email' => $n['email'], 'usuario_id' => $usuarioId,
            ...array_intersect_key($n, array_flip(Clientes::CAMPOS)),
            'entrada_original' => $n['texto'], 'resumen_interpretado' => $interp['resumen'],
            'datos_pendientes' => json_encode($interp['datos_faltantes'], JSON_UNESCAPED_UNICODE),
            'grupo_principal' => $interp['grupo_principal'], 'estado' => 'BORRADOR',
        ]);
        Numeracion::asignar($id);   // PRE26-2000, PRE26-2001… (correlativo; al cambiar de año solo cambia el prefijo)
        if ($pago) Pagos::guardar($id, $pago);
        if ($origen) { ServiciosClim::asegurarColumnas(); Db::actualizar('presupuestos', $id, ['servicio_clim_id' => $origen['id'], 'servicio_clim_codigo' => $origen['codigo']]); }
        if (trim((string) ($entrada['nota'] ?? '')) !== '') Notas::anadir($id, ['texto' => $entrada['nota']]);   // una nota escrita al crear el presupuesto
        Db::insertar('interpretaciones', [
            'presupuesto_id' => $id, 'mensaje_original' => $n['texto'],
            'json_interpretacion' => json_encode($interp, JSON_UNESCAPED_UNICODE), 'resumen' => $interp['resumen'],
        ]);
        return self::obtener($id);
    }

    /** «Seguir editando»: la IA vuelve a interpretar solo ese cambio. Una vez confirmada, la interpretación queda cerrada. */
    public static function editar(int $id, string $cambio): array
    {
        $cambio = Normalizador::limpiar($cambio);
        if ($cambio === '') throw new ErrorApi('Escribe el cambio que quieres hacer.', 400);
        $i = self::interpretacion($id);
        if ((int) $i['confirmado'] === 1) throw new ErrorApi('La interpretación ya está confirmada y no se puede editar.', 409);
        $nueva = Interprete::interpretar((string) $i['mensaje_original'], json_decode((string) $i['json_interpretacion'], true) ?: [], $cambio);
        Db::actualizar('interpretaciones', (int) $i['id'], ['json_interpretacion' => json_encode($nueva, JSON_UNESCAPED_UNICODE), 'resumen' => $nueva['resumen'], 'ediciones' => (int) $i['ediciones'] + 1]);
        Db::actualizar('presupuestos', $id, ['resumen_interpretado' => $nueva['resumen'], 'grupo_principal' => $nueva['grupo_principal'], 'datos_pendientes' => json_encode($nueva['datos_faltantes'], JSON_UNESCAPED_UNICODE)]);
        Db::insertar('correcciones', ['presupuesto_id' => $id, 'tipo' => 'interpretacion', 'valor_anterior' => $i['resumen'], 'valor_nuevo' => $nueva['resumen'], 'comentario' => $cambio]);
        return self::obtener($id);
    }

    /** El técnico confirma una pregunta de configuración (p. ej. «sí, solo la unidad interior»): deja de bloquear y no vuelve a salir. */
    public static function confirmarDato(int $id, string $clave): array
    {
        if (!in_array($clave, Interprete::CONFIRMACIONES, true) && !preg_match('/^variantes_[0-9a-f]{8}$/', $clave)) throw new ErrorApi('Esa confirmación no existe.', 400);
        $i = self::interpretacion($id);
        if ((int) $i['confirmado'] === 1) throw new ErrorApi('La interpretación ya está confirmada.', 409);
        $datos = json_decode((string) $i['json_interpretacion'], true) ?: [];
        $datos['confirmaciones'] = array_values(array_unique(array_merge((array) ($datos['confirmaciones'] ?? []), [$clave])));
        $datos['datos_faltantes'] = array_values(array_filter((array) ($datos['datos_faltantes'] ?? []), fn($d) => ($d['confirmar'] ?? '') !== $clave));
        Db::actualizar('interpretaciones', (int) $i['id'], ['json_interpretacion' => json_encode($datos, JSON_UNESCAPED_UNICODE)]);
        Db::actualizar('presupuestos', $id, ['datos_pendientes' => json_encode($datos['datos_faltantes'], JSON_UNESCAPED_UNICODE)]);
        return self::obtener($id);
    }

    // ---------- 5-9: clasificar, buscar, combinar, calcular ----------

    public static function confirmar(int $id): array
    {
        $i = self::interpretacion($id);
        if ((int) $i['confirmado'] === 1) throw new ErrorApi('Este presupuesto ya está confirmado. Usa «recalcular» si quieres rehacer los precios.', 409);
        foreach ((json_decode((string) $i['json_interpretacion'], true)['datos_faltantes'] ?? []) as $d) {
            if (!empty($d['confirmar'])) throw new ErrorApi('Antes de continuar hay que confirmar o corregir: ' . $d['pregunta'], 409);
        }
        Db::actualizar('interpretaciones', (int) $i['id'], ['confirmado' => 1]);
        return self::calcular($id, json_decode((string) $i['json_interpretacion'], true) ?: []);
    }

    /** Vuelve a buscar y calcular con la interpretación ya confirmada (p. ej. porque han cambiado los precios o el margen). */
    public static function recalcular(int $id): array
    {
        $i = self::interpretacion($id);
        if ((int) $i['confirmado'] !== 1) throw new ErrorApi('Primero hay que confirmar la interpretación.', 409);
        return self::calcular($id, json_decode((string) $i['json_interpretacion'], true) ?: []);
    }

    private static function calcular(int $id, array $interp): array
    {
        $clase = Clasificador::clasificar($interp);
        $reqs = $interp['requerimientos'];
        foreach ($reqs as &$r) $r['area'] ??= Interprete::area(null, (string) ($interp['grupo_principal'] ?? ''));   // interpretaciones antiguas sin área
        unset($r);

        // Material: primero Novelec (el proveedor de la empresa, con tu usuario: precios de profesional) y, detrás, el catálogo local como respaldo.
        // La IA elige entre todos y prefiere los de Novelec (ver prompts/emparejar.txt). Si Novelec no responde, se sigue solo con el catálogo local.
        self::asegurarColumnaManual();
        Reglas::asegurarColumnas();
        // Las líneas que una persona ya decidió (cambió el producto, la cantidad, el precio…) no se rehacen: ni se buscan de nuevo ni se pregunta a la IA
        self::asegurarGrupos();
        $enGrupo = Db::filas('SELECT requerimiento_id, grupo_id FROM presupuesto_lineas WHERE presupuesto_id = ? AND grupo_id IS NOT NULL AND requerimiento_id IS NOT NULL AND manual = 0', [$id]);
        $mantener = array_flip(array_column(Db::filas('SELECT requerimiento_id FROM presupuesto_lineas WHERE presupuesto_id = ? AND manual = 1 AND requerimiento_id IS NOT NULL', [$id]), 'requerimiento_id'));
        $reqsIA = array_values(array_filter($reqs, fn($r) => !isset($mantener[$r['id']])));
        $candidatos = [];
        foreach ($reqsIA as $r) {
            $candidatos[$r['id']] = $r['tipo'] === 'producto'
                ? array_merge(Novelec::disponible() && Ajustes::usaProveedor('NOVELEC') ? Novelec::buscar($r) : [], Catalogo::productos($r))
                : Catalogo::servicios($r);
        }
        $elecciones = $reqsIA ? Emparejador::elegir($reqsIA, $candidatos) : [];
        // Lo fijado por un administrador gana a lo que haya elegido la IA
        foreach ($reqsIA as $r) {
            $f = Fijados::de($r);
            if (!$f || !Catalogo::obtener($f['tipo'], (int) $f['referencia_id'])) continue;
            $elecciones[$r['id']]['elegido'] = ['fuente' => $f['tipo'], 'id' => (int) $f['referencia_id']];
            $elecciones[$r['id']]['confianza'] = 100;
            $elecciones[$r['id']]['faltan'] = [];
            $elecciones[$r['id']]['motivo'] = 'Fijado por la empresa para este concepto.';
            $elecciones[$r['id']]['regla'] = 'Usar siempre este (fijado)';
        }

        $auto = Ajustes::numero('confianza_automatica');
        $revision = Ajustes::numero('confianza_revision');

        $lineas = [];
        foreach ($reqsIA as $r) {
            $e = $elecciones[$r['id']];
            $esProd = $r['tipo'] === 'producto';
            $item = $e['elegido'] ? Catalogo::obtener($e['elegido']['fuente'], $e['elegido']['id']) : null;
            // Otras opciones para que el técnico elija con un clic: las que propuso la IA y, si no propuso ninguna, las 3 primeras de la lista
            $alts = $e['alternativas'];
            if (!$alts) foreach ($candidatos[$r['id']] ?? [] as $c) { if (count($alts) >= 3) break; if (!$item || $c['id'] !== $item['id'] || $c['fuente'] !== $item['fuente']) $alts[] = ['fuente' => $c['fuente'], 'id' => $c['id']]; }
            $altsJson = $alts ? json_encode($alts) : null;

            if (!$item || $e['confianza'] < $revision) {   // R009 / R010
                $lineas[] = ['requerimiento_id' => $r['id'], 'tipo' => $esProd ? 'PRODUCTO' : 'SERVICIO', 'codigo' => null, 'referencia_id' => null, 'concepto' => $r['concepto'],
                    'descripcion' => $e['motivo'], 'cantidad' => $r['cantidad'] ?? 1, 'unidad' => $r['unidad'], 'coste' => null, 'margen' => null, 'precio_unitario' => 0, 'importe' => 0,
                    'fuente' => null, 'confianza' => $e['confianza'], 'aviso' => ($esProd ? 'No se ha encontrado un producto compatible: revisar.' : 'No se ha encontrado un servicio que encaje: revisar.')
                        . ($item ? " Lo más parecido que había: {$item['nombre']}" . ($item['proveedor'] ? " ({$item['proveedor']})" : '') . '. Con «Cambiar» puedes elegirlo.' : ''),
                    'alternativas' => $altsJson, 'estado' => $esProd ? 'PRODUCTO_NO_ENCONTRADO' : 'SERVICIO_NO_ENCONTRADO'];
                continue;
            }
            $cant = $e['cantidad'] ?? $r['cantidad'];
            // Solo bloquea el importe lo que impide calcularlo: la cantidad. Lo demás (marca, módulos, amperaje…) es una duda: se avisa, pero la línea suma.
            $faltaCantidad = false;
            if ($cant === null) {
                if (in_array(mb_strtolower((string) $item['unidad']), ['ud', 'servicio', 'unidad'], true) || $item['unidad'] === null) $cant = 1.0;
                else $faltaCantidad = true;
            }
            $dudas = $e['faltan'];
            // Se pidió en metros y Novelec solo dice «ud» (no informa de la unidad): su cable y manguera se cobran por metro (los tubos y canaletas, por barra: ahí manda la cantidad de piezas que calcula la IA), así que la línea va en metros
            $unidad = $item['unidad'];
            $enMetros = $esProd && in_array(mb_strtolower(trim((string) ($r['unidad'] ?? ''))), ['m', 'ml', 'metro', 'metros'], true) && in_array(mb_strtolower((string) $unidad), ['ud', 'unidad', '', 'm'], true) && ($item['proveedor'] ?? '') === 'NOVELEC' && preg_match('/(cable|manguera|cord[oó]n|hilo)/iu', (string) $item['nombre']) && !preg_match('/(tubo|canaleta|bandeja|perfil|barra)/iu', (string) $item['nombre']);   // el precio por metro está comprobado en Novelec; en otros proveedores «ud» puede ser un rollo
            if ($enMetros) {
                $unidad = 'm';
                if ($r['cantidad'] !== null && ($cant === null || $cant < (float) $r['cantidad'])) $cant = (float) $r['cantidad'];   // nunca «cajas» en lugar de metros (más metros sí vale: p. ej. un conductor por fase, neutro y tierra)
            }
            $p = Motor::precios($item, $cant ?? 1.0);
            $avisos = array_filter([
                Motor::avisoPrecio($item),
                $faltaCantidad ? "Falta saber la cantidad (en {$item['unidad']}) para calcular el importe." : null,
                $dudas ? 'A confirmar: ' . implode(', ', $dudas) . '.' : null,
                $e['confianza'] < $auto ? 'Revisar: ' . ($e['motivo'] ?: 'la coincidencia no es segura.') : null,
            ]);
            $lineas[] = ['requerimiento_id' => $r['id'], 'tipo' => $esProd ? 'PRODUCTO' : 'SERVICIO', 'codigo' => $item['codigo'], 'referencia_id' => $item['id'], 'concepto' => $item['nombre'],
                'descripcion' => $item['descripcion'] ?: $r['detalles'], 'cantidad' => $cant ?? 1, 'unidad' => $unidad, 'coste' => $p['coste'], 'margen' => $p['margen'],
                'precio_unitario' => $p['precio_unitario'], 'importe' => $faltaCantidad ? 0 : $p['importe'], 'fuente' => $item['proveedor'] ?? 'BASE MAESTRA', 'confianza' => $e['confianza'], 'regla' => $e['regla'] ?? null,
                'aviso' => $avisos ? implode(' ', $avisos) : null, 'alternativas' => $altsJson,
                'estado' => $faltaCantidad ? 'DATO_PENDIENTE' : ($e['confianza'] >= $auto && !$dudas ? 'ACEPTADO' : 'REVISION')];
        }

        Db::transaccion(function () use ($id, $lineas, $clase, $enGrupo) {
            Db::ejecutar('DELETE FROM presupuesto_lineas WHERE presupuesto_id = ? AND requerimiento_id IS NOT NULL AND manual = 0', [$id]);   // se conservan las añadidas a mano y las que decidió una persona
            foreach ($lineas as $l) Db::insertar('presupuesto_lineas', ['presupuesto_id' => $id] + $l);
            // Las líneas rehechas vuelven a su partida unificada (se reconocen por lo que pidió el cliente)
            foreach ($enGrupo as $g) Db::ejecutar('UPDATE presupuesto_lineas SET grupo_id = ? WHERE presupuesto_id = ? AND requerimiento_id = ?', [(int) $g['grupo_id'], $id, (int) $g['requerimiento_id']]);
            self::limpiarGrupos($id);
            Db::actualizar('presupuestos', $id, ['tipo_presupuesto' => $clase['tipo_presupuesto'], 'grupo_principal' => $clase['grupo_principal']]);
            self::guardarTotales($id);
            // Las reglas que se le pasaron a la IA en este cálculo: queda constancia de cuáles eran
            Db::actualizar('presupuestos', $id, ['reglas_usadas' => json_encode(array_map(fn($f) => ['nombre' => trim($f['nombre']), 'condicion' => trim($f['condicion']), 'accion' => trim($f['accion'])], Reglas::activas()), JSON_UNESCAPED_UNICODE)]);
        });
        Notas::aplicarAutomaticas($id);   // la primera vez que se calcula: las notas de su tipo de trabajo
        return self::obtener($id);
    }

    /** Las columnas con los datos completos del cliente (DNI, dirección…) se crean solas si faltan: basta con subir el código. */
    private static bool $datosClienteListos = false;
    public static function asegurarDatosCliente(): void
    {
        if (self::$datosClienteListos) return;
        ServiciosClim::asegurarColumnas();
        $tiene = array_column(Db::filas('SHOW COLUMNS FROM presupuestos'), 'Field');
        foreach (Clientes::CAMPOS as $c) {
            if (!in_array($c, $tiene, true)) Db::ejecutar("ALTER TABLE presupuestos ADD COLUMN `$c` VARCHAR(" . ($c === 'calle' ? 255 : 100) . ') NULL');
        }
        self::$datosClienteListos = true;
    }

    /** La columna `manual` (sql/08) se crea sola si falta: así basta con subir el código, sin ejecutar la migración en el servidor. */
    private static bool $columnaManual = false;
    private static function asegurarColumnaManual(): void
    {
        if (self::$columnaManual) return;
        if (!Db::fila("SHOW COLUMNS FROM presupuesto_lineas LIKE 'manual'")) Db::ejecutar('ALTER TABLE presupuesto_lineas ADD COLUMN manual TINYINT(1) NOT NULL DEFAULT 0');
        self::$columnaManual = true;
    }

    /** La tabla de partidas unificadas y la columna `grupo_id` (sql/09) se crean solas si faltan: basta con subir el código. */
    private static bool $gruposListos = false;
    private static function asegurarGrupos(): void
    {
        if (self::$gruposListos) return;
        Db::ejecutar('CREATE TABLE IF NOT EXISTS presupuesto_grupos (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, presupuesto_id INT UNSIGNED NOT NULL, titulo VARCHAR(255) NOT NULL,
            descripcion TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_presupuesto (presupuesto_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!Db::fila("SHOW COLUMNS FROM presupuesto_lineas LIKE 'grupo_id'")) Db::ejecutar('ALTER TABLE presupuesto_lineas ADD COLUMN grupo_id INT UNSIGNED NULL');
        self::$gruposListos = true;
    }

    private static function guardarTotales(int $id): void
    {
        $lineas = Db::filas('SELECT estado, importe FROM presupuesto_lineas WHERE presupuesto_id = ?', [$id]);
        $p = Db::fila('SELECT datos_pendientes FROM presupuestos WHERE id = ?', [$id]);
        $pend = json_decode((string) ($p['datos_pendientes'] ?? '[]'), true) ?: [];
        Db::actualizar('presupuestos', $id, Motor::totales($lineas) + ['estado' => Motor::estadoGlobal($lineas, $pend)]);
    }

    // ---------- Aprendizaje: lo que corrige el técnico se guarda ----------

    /**
     * El técnico cambia el producto o servicio de una línea por otro que ha buscado a mano («elegir»: {fuente, id}). Se recalculan los precios con los datos
     * de la base, la línea queda como correcta (lo ha decidido una persona) y el cambio se guarda en `correcciones`: es lo más valioso para aprender.
     */
    private static function cambiarElemento(int $id, int $lineaId, array $cambios): array
    {
        $l = Db::fila('SELECT * FROM presupuesto_lineas WHERE id = ? AND presupuesto_id = ?', [$lineaId, $id]);
        if (!$l) throw new ErrorApi('No existe esa línea en este presupuesto.', 404);
        $e = (array) $cambios['elegir'];
        $fuente = (string) ($e['fuente'] ?? '');
        if (!in_array($fuente, ['producto', 'servicio'], true) || strtoupper($fuente) !== $l['tipo']) {
            throw new ErrorApi('Esa línea es de ' . ($l['tipo'] === 'PRODUCTO' ? 'material' : 'servicio') . ': hay que elegir uno del mismo tipo.', 400);
        }
        $item = Catalogo::obtener($fuente, (int) ($e['id'] ?? 0));
        if (!$item) throw new ErrorApi('No existe ese elemento en el catálogo.', 404);
        $cant = isset($cambios['cantidad']) ? (float) $cambios['cantidad'] : (float) $l['cantidad'];
        if (!($cant > 0)) throw new ErrorApi('La cantidad tiene que ser un número mayor que 0.', 400);
        $p = Motor::precios($item, $cant);
        $nuevo = [
            'codigo' => $item['codigo'], 'referencia_id' => $item['id'], 'concepto' => $item['nombre'], 'descripcion' => $item['descripcion'] ?: $l['descripcion'],
            'cantidad' => $cant, 'unidad' => $item['unidad'], 'coste' => $p['coste'], 'margen' => $p['margen'], 'precio_unitario' => $p['precio_unitario'],
            'importe' => $p['importe'], 'fuente' => $item['proveedor'] ?? 'BASE MAESTRA', 'confianza' => 100,
            'aviso' => trim((Motor::avisoPrecio($item) ?? '') . ' Elegido a mano.'), 'estado' => 'ACEPTADO',
        ];
        Db::insertar('correcciones', ['presupuesto_id' => $id, 'tipo' => 'linea_elemento',
            'valor_anterior' => json_encode(['linea' => $lineaId, 'referencia_id' => $l['referencia_id'], 'concepto' => $l['concepto'], 'fuente' => $l['fuente']], JSON_UNESCAPED_UNICODE),
            'valor_nuevo' => json_encode(['referencia_id' => $item['id'], 'concepto' => $item['nombre'], 'fuente' => $nuevo['fuente']], JSON_UNESCAPED_UNICODE),
            'comentario' => isset($cambios['comentario']) ? trim((string) $cambios['comentario']) : null]);
        // Lo que había antes pasa a ser una de las otras opciones: así se puede volver atrás con un clic
        $alts = json_decode((string) $l['alternativas'], true) ?: [];
        if ($l['referencia_id'] && (int) $l['referencia_id'] !== $item['id']) {
            $alts = array_values(array_filter($alts, fn($a) => (int) $a['id'] !== (int) $l['referencia_id']));
            array_unshift($alts, ['fuente' => $fuente, 'id' => (int) $l['referencia_id']]);
        }
        $nuevo['alternativas'] = json_encode(array_slice($alts, 0, 5));
        self::asegurarColumnaManual();
        $nuevo['manual'] = 1;
        Db::actualizar('presupuesto_lineas', $lineaId, $nuevo);
        self::guardarTotales($id);
        return self::obtener($id);
    }

    // ---------- Partidas unificadas ----------

    /** Comprueba que las líneas son de este presupuesto, hay al menos dos, tienen precio y ninguna está ya en otra partida unificada. */
    private static function lineasParaUnificar(int $id, array $ids): array
    {
        self::asegurarGrupos();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) < 2) throw new ErrorApi('Elige al menos dos partidas para unificarlas.', 400);
        $ls = Db::filas('SELECT * FROM presupuesto_lineas WHERE presupuesto_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$id, ...$ids]);
        if (count($ls) !== count($ids)) throw new ErrorApi('Alguna de esas partidas no es de este presupuesto.', 404);
        foreach ($ls as $l) {
            if ($l['grupo_id'] !== null) throw new ErrorApi("«{$l['concepto']}» ya está en una partida unificada: sepárala primero.", 409);
            if (!in_array($l['estado'], Motor::SUMAN, true)) throw new ErrorApi("«{$l['concepto']}» no tiene precio o está descartada: resuélvela antes de unificarla.", 409);
        }
        return $ls;
    }

    /** La IA propone título y descripción para una partida que unifica estas líneas (se pueden editar antes de confirmar). */
    public static function proponerUnificacion(int $id, array $ids): array
    {
        $ls = self::lineasParaUnificar($id, $ids);
        $total = Motor::dinero(array_sum(array_map(fn($l) => (float) $l['importe'], $ls)));
        $texto = '';
        foreach ($ls as $l) $texto .= '- ' . ($l['tipo'] === 'PRODUCTO' ? 'Material' : 'Mano de obra') . ': ' . $l['concepto'] . ' (' . rtrim(rtrim(number_format((float) $l['cantidad'], 2, '.', ''), '0'), '.') . ' ' . $l['unidad'] . ")\n";
        try {
            $j = Claude::json(Claude::preguntar('unificar', (string) file_get_contents(RAIZ . '/prompts/unificar.txt'), "PARTIDAS A UNIFICAR:\n$texto")['texto']);
        } catch (Throwable $e) {
            $j = null;
        }
        $titulo = is_array($j) ? trim((string) ($j['titulo'] ?? '')) : '';
        $desc = is_array($j) ? trim((string) ($j['descripcion'] ?? '')) : '';
        if ($titulo === '') {   // sin IA: algo editable es mejor que nada
            $titulo = mb_substr((string) $ls[0]['concepto'], 0, 90);
            $desc = 'Incluye: ' . implode('; ', array_map(fn($l) => $l['concepto'], $ls)) . '.';
        }
        return ['titulo' => mb_substr($titulo, 0, 200), 'descripcion' => mb_substr($desc, 0, 1000), 'importe' => $total];
    }

    public static function unificar(int $id, array $ids, string $titulo, string $descripcion): array
    {
        $ls = self::lineasParaUnificar($id, $ids);
        $titulo = trim($titulo);
        if ($titulo === '' || mb_strlen($titulo) > 200) throw new ErrorApi('El título es obligatorio (hasta 200 caracteres).', 400);
        $descripcion = trim($descripcion);
        if (mb_strlen($descripcion) > 1000) throw new ErrorApi('La descripción es demasiado larga (máximo 1000 caracteres).', 400);
        Db::transaccion(function () use ($id, $ls, $titulo, $descripcion) {
            $gid = Db::insertar('presupuesto_grupos', ['presupuesto_id' => $id, 'titulo' => $titulo, 'descripcion' => $descripcion]);
            foreach ($ls as $l) Db::ejecutar('UPDATE presupuesto_lineas SET grupo_id = ? WHERE id = ?', [$gid, $l['id']]);
        });
        return self::obtener($id);
    }

    public static function actualizarGrupo(int $id, int $grupoId, array $d): array
    {
        self::asegurarGrupos();
        if (!Db::fila('SELECT id FROM presupuesto_grupos WHERE id = ? AND presupuesto_id = ?', [$grupoId, $id])) throw new ErrorApi('No existe esa partida unificada.', 404);
        $nuevo = [];
        if (array_key_exists('titulo', $d)) {
            $t = trim((string) $d['titulo']);
            if ($t === '' || mb_strlen($t) > 200) throw new ErrorApi('El título es obligatorio (hasta 200 caracteres).', 400);
            $nuevo['titulo'] = $t;
        }
        if (array_key_exists('descripcion', $d)) {
            $t = trim((string) $d['descripcion']);
            if (mb_strlen($t) > 1000) throw new ErrorApi('La descripción es demasiado larga (máximo 1000 caracteres).', 400);
            $nuevo['descripcion'] = $t;
        }
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar: manda titulo o descripcion.', 400);
        Db::actualizar('presupuesto_grupos', $grupoId, $nuevo);
        return self::obtener($id);
    }

    /** Separa una partida unificada: las líneas vuelven a verse por separado. No se pierde ningún dato. */
    public static function separarGrupo(int $id, int $grupoId): array
    {
        self::asegurarGrupos();
        if (!Db::fila('SELECT id FROM presupuesto_grupos WHERE id = ? AND presupuesto_id = ?', [$grupoId, $id])) throw new ErrorApi('No existe esa partida unificada.', 404);
        Db::transaccion(function () use ($grupoId) {
            Db::ejecutar('UPDATE presupuesto_lineas SET grupo_id = NULL WHERE grupo_id = ?', [$grupoId]);
            Db::ejecutar('DELETE FROM presupuesto_grupos WHERE id = ?', [$grupoId]);
        });
        return self::obtener($id);
    }

    private static function limpiarGrupos(int $id): void
    {
        Db::ejecutar('DELETE FROM presupuesto_grupos WHERE presupuesto_id = ? AND id NOT IN (SELECT DISTINCT grupo_id FROM presupuesto_lineas WHERE presupuesto_id = ? AND grupo_id IS NOT NULL)', [$id, $id]);
    }

    /**
     * Añade una línea a mano: un producto o servicio del catálogo ({fuente, id, cantidad?}) o una línea libre
     * ({tipo, concepto, cantidad, unidad, precio_unitario}) para lo que no está en el catálogo. Queda como correcta: la ha decidido una persona.
     */
    public static function agregarLinea(int $id, array $d): array
    {
        self::obtener($id);
        $l = self::lineaManual($d) + ['presupuesto_id' => $id, 'requerimiento_id' => null, 'confianza' => 100, 'alternativas' => null, 'estado' => 'ACEPTADO'];
        Db::insertar('presupuesto_lineas', $l);
        self::guardarTotales($id);
        return self::obtener($id);
    }

    /** Los datos de una línea añadida a mano (del catálogo o libre), validados. */
    public static function lineaManual(array $d, string $nota = 'Añadida a mano.'): array
    {
        $cant = isset($d['cantidad']) && $d['cantidad'] !== '' ? $d['cantidad'] : 1;
        if (!is_numeric($cant) || (float) $cant <= 0) throw new ErrorApi('La cantidad tiene que ser un número mayor que 0.', 400);
        $cant = (float) $cant;

        if (isset($d['fuente'])) {
            $fuente = (string) $d['fuente'];
            if (!in_array($fuente, ['producto', 'servicio'], true)) throw new ErrorApi('«fuente» tiene que ser producto o servicio.', 400);
            $item = Catalogo::obtener($fuente, (int) ($d['id'] ?? 0));
            if (!$item) throw new ErrorApi('No existe ese elemento en el catálogo.', 404);
            $p = Motor::precios($item, $cant);
            return ['tipo' => strtoupper($fuente), 'codigo' => $item['codigo'], 'referencia_id' => $item['id'], 'concepto' => $item['nombre'], 'descripcion' => $item['descripcion'] ?: null,
                'cantidad' => $cant, 'unidad' => $item['unidad'], 'coste' => $p['coste'], 'margen' => $p['margen'], 'precio_unitario' => $p['precio_unitario'], 'importe' => $p['importe'],
                'fuente' => $item['proveedor'] ?? 'BASE MAESTRA', 'aviso' => trim((Motor::avisoPrecio($item) ?? '') . ' ' . $nota)];
        }
        $tipo = strtoupper((string) ($d['tipo'] ?? ''));
        if (!in_array($tipo, ['PRODUCTO', 'SERVICIO'], true)) throw new ErrorApi('«tipo» tiene que ser PRODUCTO o SERVICIO.', 400);
        $concepto = trim((string) ($d['concepto'] ?? ''));
        if ($concepto === '' || mb_strlen($concepto) > 250) throw new ErrorApi('Escribe qué es (hasta 250 caracteres).', 400);
        if (!isset($d['precio_unitario']) || !is_numeric($d['precio_unitario']) || (float) $d['precio_unitario'] < 0) throw new ErrorApi('El precio tiene que ser un número (0 o más).', 400);
        $unidad = mb_substr(trim((string) ($d['unidad'] ?? '')), 0, 20) ?: 'ud';
        $precio = Motor::dinero((float) $d['precio_unitario']);
        return ['tipo' => $tipo, 'codigo' => null, 'referencia_id' => null, 'concepto' => $concepto, 'descripcion' => null, 'cantidad' => $cant, 'unidad' => $unidad,
            'coste' => null, 'margen' => null, 'precio_unitario' => $precio, 'importe' => Motor::dinero($cant * $precio), 'fuente' => 'MANUAL', 'aviso' => 'Línea añadida a mano: el precio no sale de la base de precios.'];
    }

    /**
     * Añade un trabajo tipo al presupuesto: sus líneas (con los precios de hoy) ya unificadas en una partida con su título y descripción.
     * Lo que ya no exista en el catálogo se salta y se avisa en «omitidas».
     */
    public static function aplicarTrabajoTipo(int $id, int $trabajoId): array
    {
        self::asegurarGrupos();
        self::obtener($id);
        $t = TrabajosTipo::uno($trabajoId);
        $nuevas = [];
        $omitidas = [];
        Db::transaccion(function () use ($id, $t, &$nuevas, &$omitidas) {
            foreach ($t['lineas'] as $tl) {
                try {
                    $l = self::lineaManual($tl['entrada'], 'Añadida desde el trabajo tipo «' . $t['titulo'] . '».');
                } catch (ErrorApi $e) {
                    $omitidas[] = $tl['concepto'];
                    continue;
                }
                $nuevas[] = Db::insertar('presupuesto_lineas', $l + ['presupuesto_id' => $id, 'requerimiento_id' => null, 'confianza' => 100, 'alternativas' => null, 'estado' => 'ACEPTADO']);
            }
            if (count($nuevas) >= 2) {
                $gid = Db::insertar('presupuesto_grupos', ['presupuesto_id' => $id, 'titulo' => $t['titulo'], 'descripcion' => $t['descripcion']]);
                foreach ($nuevas as $lid) Db::ejecutar('UPDATE presupuesto_lineas SET grupo_id = ? WHERE id = ?', [$gid, $lid]);
            }
        });
        if (!$nuevas) throw new ErrorApi('Ninguno de los componentes de este trabajo tipo existe ya en el catálogo.', 409);
        self::guardarTotales($id);
        return self::obtener($id) + ['omitidas' => $omitidas];
    }

    public static function ajustarLinea(int $id, int $lineaId, array $cambios): array
    {
        if (isset($cambios['elegir'])) return self::cambiarElemento($id, $lineaId, $cambios);
        $l = Db::fila('SELECT * FROM presupuesto_lineas WHERE id = ? AND presupuesto_id = ?', [$lineaId, $id]);
        if (!$l) throw new ErrorApi('No existe esa línea en este presupuesto.', 404);
        $coment = isset($cambios['comentario']) ? trim((string) $cambios['comentario']) : null;
        $nuevo = [];

        if (array_key_exists('cantidad', $cambios)) {
            if (!is_numeric($cambios['cantidad']) || (float) $cambios['cantidad'] <= 0) throw new ErrorApi('La cantidad tiene que ser un número mayor que 0.', 400);
            $nuevo['cantidad'] = (float) $cambios['cantidad'];
        }
        if (array_key_exists('precio_unitario', $cambios)) {
            if (!is_numeric($cambios['precio_unitario']) || (float) $cambios['precio_unitario'] < 0) throw new ErrorApi('El precio tiene que ser un número (0 o más).', 400);
            $nuevo['precio_unitario'] = Motor::dinero((float) $cambios['precio_unitario']);
        }
        if (isset($cambios['unidad'])) {
            $u = trim((string) $cambios['unidad']);
            if ($u === '' || mb_strlen($u) > 20) throw new ErrorApi('La unidad tiene que tener entre 1 y 20 caracteres (m, ud, rollo…).', 400);
            $nuevo['unidad'] = $u;
        }
        if (isset($cambios['estado'])) {
            if (!in_array($cambios['estado'], self::ESTADOS_LINEA_MANUALES, true)) throw new ErrorApi('Estado de línea no válido: ' . implode(', ', self::ESTADOS_LINEA_MANUALES) . '.', 400);
            $nuevo['estado'] = $cambios['estado'];
        }
        self::asegurarColumnaManual();
        if (!empty($cambios['liberar'])) $nuevo['manual'] = 0;   // «que se recalcule»: la próxima vez que se recalcule, esta línea se rehace
        elseif ($nuevo && (isset($nuevo['cantidad']) || isset($nuevo['precio_unitario']) || isset($nuevo['unidad']) || ($nuevo['estado'] ?? '') === 'DESCARTADO')) $nuevo['manual'] = 1;
        if (!$nuevo) throw new ErrorApi('No hay nada que cambiar: manda cantidad, precio_unitario, unidad o estado.', 400);

        $cant = $nuevo['cantidad'] ?? (float) $l['cantidad'];
        $unit = $nuevo['precio_unitario'] ?? (float) $l['precio_unitario'];
        $minimo = 0.0;
        if ($l['referencia_id'] && $l['tipo'] === 'SERVICIO') $minimo = (float) (Catalogo::obtener('servicio', (int) $l['referencia_id'])['importe_minimo'] ?? 0);
        $importe = Motor::dinero($cant * $unit);
        if ($minimo > 0 && !isset($nuevo['precio_unitario'])) $importe = max($importe, $minimo);
        $nuevo['importe'] = $importe;
        // Si había un dato pendiente y ya hay cantidad, la línea deja de estar pendiente
        if (!isset($nuevo['estado']) && $l['estado'] === 'DATO_PENDIENTE' && isset($nuevo['cantidad'])) $nuevo['estado'] = 'REVISION';
        if (!isset($nuevo['estado']) && in_array($l['estado'], ['SERVICIO_NO_ENCONTRADO', 'PRODUCTO_NO_ENCONTRADO'], true) && isset($nuevo['precio_unitario'])) $nuevo['estado'] = 'REVISION';
        if (isset($nuevo['precio_unitario']) && $l['tipo'] === 'PRODUCTO') { $nuevo['coste'] = null; $nuevo['margen'] = null; }
        // El aviso automático ya no vale una vez que el técnico ha completado la línea
        if ($l['estado'] === 'DATO_PENDIENTE' && isset($nuevo['cantidad'])) $nuevo['aviso'] = null;
        if (in_array($l['estado'], ['SERVICIO_NO_ENCONTRADO', 'PRODUCTO_NO_ENCONTRADO'], true) && isset($nuevo['precio_unitario'])) $nuevo['aviso'] = 'Precio puesto a mano: no sale de la base de precios.';

        foreach (['cantidad', 'precio_unitario', 'estado'] as $c) {
            if (isset($nuevo[$c]) && (string) $nuevo[$c] !== (string) $l[$c]) {
                Db::insertar('correcciones', ['presupuesto_id' => $id, 'tipo' => "linea_$c", 'valor_anterior' => json_encode(['linea' => $lineaId, 'concepto' => $l['concepto'], 'valor' => $l[$c]], JSON_UNESCAPED_UNICODE),
                    'valor_nuevo' => (string) $nuevo[$c], 'comentario' => $coment]);
            }
        }
        Db::actualizar('presupuesto_lineas', $lineaId, $nuevo);
        self::guardarTotales($id);
        return self::obtener($id);
    }

    public static function cambiarEstado(int $id, string $estado): array
    {
        if (!in_array($estado, self::ESTADOS, true)) throw new ErrorApi('Estado no válido. Opciones: ' . implode(', ', self::ESTADOS) . '.', 400);
        self::obtener($id);
        Db::actualizar('presupuestos', $id, ['estado' => $estado]);
        return self::obtener($id);
    }

    /** Datos del cliente (nombre, DNI, teléfono, email y dirección): se pueden corregir en cualquier momento y salen en los documentos. */
    public static function actualizarCliente(int $id, array $d): array
    {
        self::obtener($id);
        $t = fn(string $k, int $max) => ($v = mb_substr(trim((string) ($d[$k] ?? '')), 0, $max)) === '' ? null : $v;
        $email = $t('email', 255);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErrorApi('El email no es válido.', 400);
        if (!array_intersect(['cliente', 'telefono', 'email', ...Clientes::CAMPOS], array_keys($d))) throw new ErrorApi('No hay nada que cambiar: manda cliente, telefono, email o los datos del cliente.', 400);
        // Solo se tocan los campos que llegan: mandar solo el teléfono no borra la dirección
        $cambios = [];
        foreach (['cliente' => 255, 'telefono' => 50] as $k => $max) if (array_key_exists($k, $d)) $cambios[$k] = $t($k, $max);
        if (array_key_exists('email', $d)) $cambios['email'] = $email;
        $cambios += Clientes::limpiarDatos(array_intersect_key($d, array_flip(Clientes::CAMPOS)));
        Db::actualizar('presupuestos', $id, $cambios);
        return self::obtener($id);
    }

    // ---------- Borrar ----------

    /** Borra un presupuesto con sus líneas e interpretación. Las correcciones se quedan (sirven para aprender). Uno enviado o aceptado solo lo borra un administrador. */
    public static function eliminar(int $id, array $yo): array
    {
        $p = self::obtener($id);
        if (in_array($p['estado'], ['ENVIADO', 'ACEPTADO'], true) && ($yo['rol'] ?? '') !== 'admin') {
            throw new ErrorApi('Un presupuesto enviado o aceptado solo lo puede borrar un administrador.', 403);
        }
        Db::transaccion(function () use ($id) {
            Db::ejecutar('DELETE FROM interpretaciones WHERE presupuesto_id = ?', [$id]);
            self::asegurarGrupos();
            Db::ejecutar('DELETE FROM presupuesto_grupos WHERE presupuesto_id = ?', [$id]);
            Notas::borrarDe($id);
            Db::ejecutar('DELETE FROM presupuestos WHERE id = ?', [$id]);   // las líneas caen solas (ON DELETE CASCADE)
        });
        return ['id' => $id, 'numero' => $p['numero'], 'estado' => $p['estado']];
    }

    /** Borra todos los borradores (los que nadie ha confirmado). Devuelve cuántos. */
    public static function eliminarBorradores(): int
    {
        return Db::transaccion(function () {
            Notas::asegurar();
            Db::ejecutar("DELETE FROM presupuesto_notas WHERE presupuesto_id IN (SELECT id FROM presupuestos WHERE estado = 'BORRADOR')");
            Db::ejecutar("DELETE FROM interpretaciones WHERE presupuesto_id IN (SELECT id FROM presupuestos WHERE estado = 'BORRADOR')");
            return Db::ejecutar("DELETE FROM presupuestos WHERE estado = 'BORRADOR'");
        });
    }

    // ---------- Lectura ----------

    public static function obtener(int $id): array
    {
        self::asegurarGrupos();
        self::asegurarDatosCliente();
        Pagos::asegurar();
        Reglas::asegurarColumnas();
        $p = Db::fila('SELECT p.*, u.nombre AS creado_por FROM presupuestos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?', [$id]);
        if (!$p) throw new ErrorApi('No existe ese presupuesto.', 404);
        $i = Db::fila('SELECT * FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        $p['datos_pendientes'] = json_decode((string) ($p['datos_pendientes'] ?? '[]'), true) ?: [];
        foreach (['subtotal', 'iva', 'total'] as $k) $p[$k] = (float) $p[$k];
        $p['condiciones_pago'] = Pagos::de($p);
        $p['notas'] = Notas::de($id);
        $ru = json_decode((string) ($p['reglas_usadas'] ?? ''), true);
        $p['reglas_usadas'] = is_array($ru) ? $ru : null;   // null = presupuesto anterior o sin calcular: no guardó cuáles
        $p['interpretacion'] = $i ? ['confirmado' => (bool) $i['confirmado'], 'ediciones' => (int) $i['ediciones'], 'datos' => json_decode((string) $i['json_interpretacion'], true)] : null;
        $p['lineas'] = array_map(function ($l) {
            foreach (['cantidad', 'coste', 'margen', 'precio_unitario', 'importe', 'confianza'] as $k) if ($l[$k] !== null) $l[$k] = (float) $l[$k];
            $l['alternativas'] = self::alternativasConPrecio($l);
            $l['manual'] = !empty($l['manual']);
            $l['grupo_id'] = $l['grupo_id'] === null ? null : (int) $l['grupo_id'];
            return $l;
        }, Db::filas('SELECT * FROM presupuesto_lineas WHERE presupuesto_id = ? ORDER BY (requerimiento_id IS NULL), requerimiento_id, id', [$id]));
        // La foto de los productos elegidos (solo Novelec la tiene; si la columna aún no existe, no hay fotos y no pasa nada)
        $imagenes = [];
        $ids = array_values(array_unique(array_filter(array_map(fn($l) => $l['tipo'] === 'PRODUCTO' ? $l['referencia_id'] : null, $p['lineas']))));
        if ($ids) {
            try {
                foreach (Db::cFilas('SELECT id, imagen_url FROM productos WHERE imagen_url IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $f) $imagenes[(int) $f['id']] = $f['imagen_url'];
            } catch (Throwable $e) { /* sin columna todavía */ }
        }
        foreach ($p['lineas'] as &$l) $l['imagen'] = $l['tipo'] === 'PRODUCTO' ? ($imagenes[(int) $l['referencia_id']] ?? null) : null;
        unset($l);
        $p['grupos'] = [];
        foreach (Db::filas('SELECT id, titulo, descripcion FROM presupuesto_grupos WHERE presupuesto_id = ? ORDER BY id', [$id]) as $g) {
            $ls = array_values(array_filter($p['lineas'], fn($l) => $l['grupo_id'] === (int) $g['id']));
            $suma = 0.0;
            foreach ($ls as $l) if (in_array($l['estado'], Motor::SUMAN, true)) $suma += $l['importe'];
            $p['grupos'][] = ['id' => (int) $g['id'], 'titulo' => $g['titulo'], 'descripcion' => (string) $g['descripcion'], 'lineas' => array_column($ls, 'id'), 'importe' => Motor::dinero($suma)];
        }
        return $p;
    }

    /** Las otras opciones de una línea, con su nombre y el precio de venta de una unidad (para compararlas con el de la línea). */
    private static function alternativasConPrecio(array $l): array
    {
        $out = [];
        foreach ((json_decode((string) ($l['alternativas'] ?? ''), true) ?: []) as $a) {
            if ((int) $a['id'] === (int) $l['referencia_id'] && $a['fuente'] === strtolower($l['tipo'])) continue;   // la elegida no es una alternativa
            $it = Catalogo::obtener((string) $a['fuente'], (int) $a['id']);
            if (!$it) continue;
            $out[] = ['fuente' => $it['fuente'], 'id' => $it['id'], 'nombre' => $it['nombre'], 'codigo' => $it['codigo'], 'proveedor' => $it['proveedor'], 'unidad' => $it['unidad'], 'imagen' => $it['imagen'] ?? null, 'precio_unitario' => Motor::precios($it, 1.0)['precio_unitario']];
        }
        return $out;
    }

    /**
     * La lista de presupuestos, con filtro por estado y búsqueda por texto (número, nombre, teléfono o email del cliente: se piden TODAS las palabras).
     * Devuelve también cuántos hay en cada estado y el importe de los que están por revisar (con la misma búsqueda), para las tarjetas y los filtros.
     */
    public static function listar(?string $estado, int $limite = 50, int $pagina = 1, ?string $q = null): array
    {
        $limite = max(1, min(200, $limite));
        $condiciones = [];
        $args = [];
        foreach (array_slice(array_values(array_filter(preg_split('/\s+/u', trim((string) $q)), fn($w) => $w !== '')), 0, 6) as $w) {
            $condiciones[] = "(numero LIKE ? OR COALESCE(cliente, '') LIKE ? OR COALESCE(telefono, '') LIKE ? OR COALESCE(email, '') LIKE ?)";
            $like = '%' . addcslashes($w, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $base = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
        $conteos = [];
        foreach (Db::filas("SELECT estado, COUNT(*) n, SUM(total) suma FROM presupuestos $base GROUP BY estado", $args) as $f) $conteos[$f['estado']] = ['n' => (int) $f['n'], 'suma' => (float) $f['suma']];
        $donde = $estado ? ($base ? "$base AND estado = ?" : 'WHERE estado = ?') : $base;
        $filtro = $estado ? [...$args, $estado] : $args;
        $total = (int) Db::fila("SELECT COUNT(*) n FROM presupuestos $donde", $filtro)['n'];
        $filas = Db::filas("SELECT id, numero, cliente, grupo_principal, tipo_presupuesto, estado, total, created_at FROM presupuestos $donde ORDER BY id DESC LIMIT $limite OFFSET " . (max(1, $pagina) - 1) * $limite, $filtro);
        return ['total' => $total, 'presupuestos' => $filas, 'conteos' => array_map(fn($c) => $c['n'], $conteos), 'importe_revision' => Motor::dinero($conteos['REVISION']['suma'] ?? 0.0)];
    }

    private static function interpretacion(int $id): array
    {
        self::obtener($id);
        $i = Db::fila('SELECT * FROM interpretaciones WHERE presupuesto_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        if (!$i) throw new ErrorApi('Este presupuesto no tiene interpretación.', 409);
        return $i;
    }
}
