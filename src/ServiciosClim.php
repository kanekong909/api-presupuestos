<?php
// SERVICIOS DE «SERVICIOS CLIM» — los servicios ya hechos (visitas técnicas) del otro sistema, SOLO PARA LEER, para empezar un presupuesto a partir de lo que el técnico escribió.
// Solo salen los servicios COMPLETADOS cuya visita tiene los tres campos rellenos: diagnóstico, trabajo realizado y observaciones.
// Usa la misma conexión de solo lectura que la búsqueda de clientes (config › clientes). Nunca se escribe en esa base: el presupuesto guarda su propia referencia (id y código del servicio).
//   Tablas que lee: servicios, servicio_tecnicos, evidencias, usuarios (nombre del técnico) y clientes.

final class ServiciosClim
{
    /** La visita tiene diagnóstico, trabajo realizado y observaciones (no vacíos). */
    private const COMPLETA = "TRIM(COALESCE(st.diagnostico_tecnico, '')) <> '' AND TRIM(COALESCE(st.trabajo_realizado, '')) <> '' AND TRIM(COALESCE(st.observaciones, '')) <> ''";
    private const POR_PAGINA = 12;

    private static bool $columnasListas = false;
    /** Las columnas con el servicio de origen se crean solas en la tabla de presupuestos si faltan. */
    public static function asegurarColumnas(): void
    {
        if (self::$columnasListas) return;
        $tiene = array_column(Db::filas('SHOW COLUMNS FROM presupuestos'), 'Field');
        if (!in_array('servicio_clim_id', $tiene, true)) Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN servicio_clim_id INT UNSIGNED NULL');
        if (!in_array('servicio_clim_codigo', $tiene, true)) Db::ejecutar('ALTER TABLE presupuestos ADD COLUMN servicio_clim_codigo VARCHAR(40) NULL');
        self::$columnasListas = true;
    }

    /** Limpia el servicio de origen que llega al crear un presupuesto: [id, codigo] o null. */
    public static function origen(mixed $d): ?array
    {
        if (!is_array($d) || empty($d['id'])) return null;
        $id = (int) $d['id'];
        if ($id <= 0) throw new ErrorApi('El servicio de origen no es válido.', 400);
        $codigo = mb_substr(trim((string) ($d['codigo'] ?? '')), 0, 40);
        return ['id' => $id, 'codigo' => $codigo === '' ? null : $codigo];
    }

    public static function disponible(): bool
    {
        return Clientes::disponible();
    }

    /** Los presupuestos que ya salieron de cada servicio (para avisar de que ya hay uno). @return array<int, array{id:int, numero:string}[]> */
    private static function presupuestosDe(array $servicioIds): array
    {
        if (!$servicioIds) return [];
        self::asegurarColumnas();
        $out = [];
        foreach (Db::filas('SELECT id, numero, servicio_clim_id FROM presupuestos WHERE servicio_clim_id IN (' . implode(',', array_fill(0, count($servicioIds), '?')) . ') ORDER BY id', array_values($servicioIds)) as $f) {
            $out[(int) $f['servicio_clim_id']][] = ['id' => (int) $f['id'], 'numero' => (string) $f['numero']];
        }
        return $out;
    }

    /** La condición de la búsqueda: cada palabra tiene que estar en el código, la descripción, el cliente, la dirección o lo que escribió el técnico. */
    private static function condicion(array $palabras): array
    {
        $sql = '';
        $args = [];
        foreach ($palabras as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $sql .= ' AND (' . implode(' OR ', array_map(fn($c) => "COALESCE($c, '') LIKE ?", ['s.codigo', 's.descripcion', 's.direccion_visita', 'c.nombre', 'c.email', 'c.telefono', 'st.diagnostico_tecnico', 'st.trabajo_realizado', 'st.observaciones'])) . ')';
            array_push($args, ...array_fill(0, 9, $like));
        }
        return [$sql, $args];
    }

    /** Servicios completados con los tres campos rellenos, los más recientes primero. @return array{total:int, pagina:int, paginas:int, servicios:list<array>} */
    public static function buscar(string $q = '', int $pagina = 1): array
    {
        if (!self::disponible()) return ['total' => 0, 'pagina' => 1, 'paginas' => 1, 'servicios' => []];
        $pagina = max(1, $pagina);
        [$cond, $args] = self::condicion(Clientes::palabras($q));
        $tc = Clientes::tablaClientes();
        $desde = "FROM servicios s JOIN servicio_tecnicos st ON st.servicio_id = s.id LEFT JOIN `$tc` c ON c.id = s.cliente_id WHERE s.estado = 'completado' AND " . self::COMPLETA . $cond;
        $pdo = Clientes::conexion();
        $n = $pdo->prepare("SELECT COUNT(DISTINCT s.id) n $desde");
        $n->execute($args);
        $total = (int) ($n->fetch()['n'] ?? 0);
        $st = $pdo->prepare("SELECT s.id, s.codigo, s.descripcion, s.direccion_visita, s.fecha_programada, c.nombre AS cliente, c.telefono, MIN(st.diagnostico_tecnico) AS diagnostico
                             $desde GROUP BY s.id, s.codigo, s.descripcion, s.direccion_visita, s.fecha_programada, c.nombre, c.telefono
                             ORDER BY s.fecha_programada DESC, s.id DESC LIMIT " . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA));
        $st->execute($args);
        $filas = $st->fetchAll();
        $previos = self::presupuestosDe(array_map(fn($f) => (int) $f['id'], $filas));
        return [
            'total' => $total, 'pagina' => $pagina, 'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            'servicios' => array_map(fn($f) => [
                'id' => (int) $f['id'], 'codigo' => (string) $f['codigo'], 'fecha' => $f['fecha_programada'] ? substr((string) $f['fecha_programada'], 0, 10) : null,
                'cliente' => trim((string) ($f['cliente'] ?? '')), 'telefono' => trim((string) ($f['telefono'] ?? '')),
                'direccion' => trim((string) ($f['direccion_visita'] ?? '')), 'descripcion' => mb_substr(trim((string) $f['descripcion']), 0, 200),
                'diagnostico' => mb_substr(trim((string) $f['diagnostico']), 0, 220), 'presupuestos' => $previos[(int) $f['id']] ?? [],
            ], $filas),
        ];
    }

    /** La dirección de la foto o el vídeo: absoluta si ya lo es; si no, con la dirección de archivos de Servicios Clim (config › clientes › archivos_url). null si no se sabe. */
    private static function urlArchivo(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (preg_match('#^https?://#i', $url)) return $url;
        $base = trim((string) (Config::get('clientes', [])['archivos_url'] ?? ''));
        return preg_match('#^https?://#i', $base) ? rtrim($base, '/') . '/' . ltrim($url, '/') : null;
    }

    /** Un servicio con el cliente, lo que escribió cada técnico y sus evidencias. */
    public static function detalle(int $id): array
    {
        if (!self::disponible()) throw new ErrorApi('La conexión con Servicios Clim no está configurada.', 409);
        $pdo = Clientes::conexion();
        $tc = Clientes::tablaClientes();
        $s = $pdo->prepare("SELECT s.id, s.codigo, s.descripcion, s.direccion_visita, s.fecha_programada, s.cliente_id FROM servicios s WHERE s.id = ? AND s.estado = 'completado'");
        $s->execute([$id]);
        $f = $s->fetch();
        if (!$f) throw new ErrorApi('No existe ese servicio completado en Servicios Clim.', 404);

        $cliente = null;
        if ($f['cliente_id']) {
            $c = $pdo->prepare("SELECT * FROM `$tc` WHERE id = ?");
            $c->execute([(int) $f['cliente_id']]);
            if ($fc = $c->fetch()) $cliente = Clientes::dar($fc);
        }

        $v = $pdo->prepare('SELECT st.id, st.tecnico_id, st.diagnostico_tecnico, st.trabajo_realizado, st.observaciones, st.hora_fin FROM servicio_tecnicos st WHERE st.servicio_id = ? AND ' . self::COMPLETA . ' ORDER BY st.id');
        $v->execute([$id]);
        $visitas = $v->fetchAll();
        if (!$visitas) throw new ErrorApi('Ese servicio no tiene diagnóstico, trabajo realizado y observaciones.', 404);

        // El nombre del técnico y las evidencias son un extra: si algo falla al leerlos, el servicio se muestra igual
        $nombres = [];
        try {
            $ids = array_values(array_unique(array_map(fn($x) => (int) $x['tecnico_id'], $visitas)));
            $q = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute($ids);
            foreach ($q->fetchAll() as $u) $nombres[(int) $u['id']] = trim((string) $u['nombre']);
        } catch (Throwable $e) { error_log('[presupuestos] Servicios Clim: no se han podido leer los técnicos: ' . $e->getMessage()); }
        $evid = [];
        try {
            $vids = array_map(fn($x) => (int) $x['id'], $visitas);
            $q = $pdo->prepare('SELECT servicio_tecnico_id, tipo, url FROM evidencias WHERE servicio_tecnico_id IN (' . implode(',', array_fill(0, count($vids), '?')) . ') ORDER BY created_at, id');
            $q->execute($vids);
            foreach ($q->fetchAll() as $e) $evid[(int) $e['servicio_tecnico_id']][] = ['tipo' => ($e['tipo'] ?? '') === 'video' ? 'video' : 'foto', 'url' => self::urlArchivo((string) $e['url'])];
        } catch (Throwable $e) { error_log('[presupuestos] Servicios Clim: no se han podido leer las evidencias: ' . $e->getMessage()); }

        return [
            'id' => (int) $f['id'], 'codigo' => (string) $f['codigo'], 'fecha' => $f['fecha_programada'] ? substr((string) $f['fecha_programada'], 0, 10) : null,
            'descripcion' => trim((string) $f['descripcion']), 'direccion' => trim((string) ($f['direccion_visita'] ?? '')), 'cliente' => $cliente,
            'visitas' => array_map(fn($x) => [
                'tecnico' => $nombres[(int) $x['tecnico_id']] ?? '', 'fecha' => $x['hora_fin'] ? substr((string) $x['hora_fin'], 0, 10) : null,
                'diagnostico' => trim((string) $x['diagnostico_tecnico']), 'trabajo' => trim((string) $x['trabajo_realizado']), 'observaciones' => trim((string) $x['observaciones']),
                'evidencias' => $evid[(int) $x['id']] ?? [],
            ], $visitas),
            'presupuestos' => self::presupuestosDe([$id])[$id] ?? [],
        ];
    }
}
