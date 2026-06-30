<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Orden — una orden/remito (lo que rastrea el cliente final por `nro_orden`).
 *
 * Sus ítems físicos son filas de `productos` (codigo = nro_orden-NN) ligadas por
 * `orden_id`. `estado` es derivado de esos ítems y se guarda denormalizado para
 * filtrar rápido en Reportes; el ProcesadorLote lo recalcula al transicionar.
 */
final class Orden
{
    /** Estados posibles de una orden (derivados de sus ítems), para el filtro de Reportes. */
    public const ESTADOS = ['RECIBIDO', 'EN_REPARTO', 'ENTREGADO', 'REINGRESADO', 'DEVUELTO'];

    /** Marcas operativas EXCLUYENTES de una orden (planilla de Reportes). NULL = sin marca. */
    public const MARCAS = ['no_entregar', 'prioridad'];

    /** Columnas escribibles de una orden (para crear/actualizar desde la carga). */
    private const CAMPOS = [
        'carga_id', 'nro_orden', 'nro_remito', 'hoja_ruta', 'transportista_id', 'fecha_carga',
        'fecha_remito',
        'cliente', 'cliente_apellido', 'telefonos', 'telefono_wa',
        'dest_provincia', 'dest_localidad', 'dest_domicilio', 'dest_cp',
        'valor_declarado', 'observaciones', 'marca', 'm3_total', 'estado',
    ];

    /**
     * Crea una orden. $d es un array asociativo con claves de self::CAMPOS.
     * Devuelve el nuevo id.
     *
     * @param array<string, mixed> $d
     */
    public static function crear(array $d): int
    {
        $cols = [];
        $phs  = [];
        $vals = [];
        foreach (self::CAMPOS as $c) {
            if (array_key_exists($c, $d)) {
                $cols[]      = "`{$c}`";
                $phs[]       = ":{$c}";
                $vals[":{$c}"] = $d[$c] === '' ? null : $d[$c];
            }
        }
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO ordenes (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $phs) . ')'
        );
        $stmt->execute($vals);
        return (int)$db->lastInsertId();
    }

    /** Campos editables de una orden desde el detalle (subconjunto de CAMPOS). */
    private const EDITABLES = [
        'nro_remito', 'hoja_ruta', 'transportista_id', 'fecha_carga', 'fecha_remito',
        'cliente', 'cliente_apellido',
        'telefonos', 'telefono_wa', 'dest_provincia', 'dest_localidad', 'dest_domicilio', 'dest_cp',
        'valor_declarado', 'observaciones', 'marca',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM ordenes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Actualiza los datos editables de una orden (desde el detalle). Solo toca las
     * claves presentes en $d que estén en EDITABLES; '' se guarda como NULL.
     *
     * @param array<string, mixed> $d
     */
    public static function actualizarDatos(int $id, array $d): void
    {
        $sets = [];
        $vals = [':id' => $id];
        foreach (self::EDITABLES as $c) {
            if (array_key_exists($c, $d)) {
                $sets[]        = "`{$c}` = :{$c}";
                $vals[":{$c}"] = ($d[$c] === '' || $d[$c] === null) ? null : $d[$c];
            }
        }
        if ($sets === []) {
            return;
        }
        $stmt = DB::getInstance()->prepare('UPDATE ordenes SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($vals);
    }

    /**
     * Edición EN BLOQUE de los datos de ingreso de toda una carga (corrección de
     * errores de import): fija transportista y fecha de carga en TODAS las órdenes
     * de la carga. Devuelve cuántas órdenes actualizó.
     */
    public static function actualizarDatosCarga(int $cargaId, ?int $transportistaId, ?string $fechaCarga): int
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE ordenes SET transportista_id = :t, fecha_carga = :f WHERE carga_id = :c'
        );
        $stmt->bindValue(':t', $transportistaId, $transportistaId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':f', ($fechaCarga === null || $fechaCarga === '') ? null : $fechaCarga, ($fechaCarga === null || $fechaCarga === '') ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':c', $cargaId, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Datos de ingreso representativos de una carga (de su primera orden), para
     * precargar la edición en bloque: transportista_id, fecha_carga.
     *
     * @return array<string,mixed>|null
     */
    public static function datosCarga(int $cargaId): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT transportista_id, fecha_carga FROM ordenes WHERE carga_id = :c ORDER BY id LIMIT 1'
        );
        $stmt->execute([':c' => $cargaId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Edición EN BLOQUE por HOJA DE RUTA (corrección de datos viejos): aplica los
     * cambios a TODAS las órdenes cuyo `hoja_ruta` coincide. Solo toca las claves
     * presentes en $cambios (subconjunto de transportista_id, fecha_carga, hoja_ruta;
     * esta última renombra la hoja). Devuelve cuántas órdenes tenía la hoja.
     *
     * @param array<string,mixed> $cambios
     */
    public static function actualizarPorHojaRuta(string $hojaRuta, array $cambios): int
    {
        $permitidos = ['transportista_id', 'fecha_carga', 'hoja_ruta'];
        $sets = [];
        $vals = [];
        foreach ($permitidos as $c) {
            if (array_key_exists($c, $cambios)) {
                $sets[]     = "`{$c}` = :{$c}";
                $vals[$c]   = $cambios[$c];
            }
        }
        if ($sets === [] || $hojaRuta === '') {
            return 0;
        }
        $db = DB::getInstance();
        // Cuántas órdenes tiene la hoja (antes de un eventual renombrado).
        $cnt = $db->prepare('SELECT COUNT(*) FROM ordenes WHERE hoja_ruta = :hr');
        $cnt->execute([':hr' => $hojaRuta]);
        $matched = (int)$cnt->fetchColumn();
        if ($matched === 0) {
            return 0;
        }
        $stmt = $db->prepare('UPDATE ordenes SET ' . implode(', ', $sets) . ' WHERE hoja_ruta = :hr');
        foreach ($vals as $k => $v) {
            if ($k === 'transportista_id') {
                $stmt->bindValue(':' . $k, $v === null ? null : (int)$v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $vacio = ($v === null || $v === '');
                $stmt->bindValue(':' . $k, $vacio ? null : $v, $vacio ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            }
        }
        $stmt->bindValue(':hr', $hojaRuta, \PDO::PARAM_STR);
        $stmt->execute();
        return $matched;
    }

    /** Recalcula ordenes.m3_total como la suma de los m³ de sus productos. */
    public static function recalcularM3(int $ordenId): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE ordenes SET m3_total = (SELECT COALESCE(SUM(m3), 0) FROM productos WHERE orden_id = :a)
             WHERE id = :b'
        );
        $stmt->execute([':a' => $ordenId, ':b' => $ordenId]);
    }

    /**
     * Agrega $cantidad ítems a una orden (caso típico: una orden que vino cortada
     * en la hoja y le faltan productos). Cada ítem nuevo se crea INGRESADO, con la
     * categoría de la carga, su transición de ingreso y, si la orden ya tenía un
     * lote de INGRESO, se suma a ese lote. Recalcula el m³ total. Devuelve cuántos.
     */
    public static function agregarItems(int $ordenId, ?string $descripcion, ?string $dimensiones, ?float $m3Total, int $cantidad): int
    {
        $orden = self::find($ordenId);
        if ($orden === null) {
            throw new \RuntimeException('Orden no encontrada.');
        }
        $cantidad = max(1, min(99, $cantidad));
        $db = DB::getInstance();

        // Categoría heredada de la carga.
        $categoriaId = null;
        if (($orden['carga_id'] ?? null) !== null) {
            $st = $db->prepare('SELECT categoria_id FROM cargas WHERE id = :c LIMIT 1');
            $st->execute([':c' => (int)$orden['carga_id']]);
            $cat = $st->fetchColumn();
            $categoriaId = ($cat !== false && $cat !== null) ? (int)$cat : null;
        }

        // Lote de INGRESO de la orden (para sumar los ítems al mismo lote, si existe).
        $st = $db->prepare(
            "SELECT t.lote_id FROM transiciones t JOIN productos p ON p.id = t.producto_id
             WHERE p.orden_id = :o AND t.estado_hasta = 'INGRESADO' AND t.lote_id IS NOT NULL LIMIT 1"
        );
        $st->execute([':o' => $ordenId]);
        $lid    = $st->fetchColumn();
        $loteId = ($lid !== false && $lid !== null) ? (int)$lid : null;

        $maxSec = (int)$db->query('SELECT COALESCE(MAX(secuencia), 0) FROM productos WHERE orden_id = ' . $ordenId)->fetchColumn();
        $now    = gmdate('Y-m-d H:i:s');
        $nro    = (string)$orden['nro_orden'];
        $m3Unit = ($m3Total !== null && $cantidad > 0) ? round($m3Total / $cantidad, 3) : null;

        $db->beginTransaction();
        try {
            for ($i = 1; $i <= $cantidad; $i++) {
                $sec = $maxSec + $i;
                $cod = $nro . '-' . str_pad((string)$sec, 2, '0', STR_PAD_LEFT);
                $pid = Producto::crearItem($cod, $ordenId, $categoriaId, $descripcion, $dimensiones, $m3Unit, $sec);
                $tid = Transicion::insertar($pid, $loteId, null, 'INGRESADO', $now, false, null);
                Producto::fijarEstadoActual($pid, 'INGRESADO', $tid);
                if ($loteId !== null) {
                    Lote::insertarItem($loteId, $cod, $now, $tid, 'aplicado');
                }
            }
            self::recalcularM3($ordenId);
            $db->commit();
            return $cantidad;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Elimina una orden y todos sus ítems. Solo si ningún ítem fue despachado
     * (todos siguen INGRESADO) — para corregir cargas mal ingresadas. Devuelve
     * 'ok' | 'no_existe' | 'despachada'.
     */
    public static function eliminar(int $ordenId): string
    {
        $db = DB::getInstance();
        if (self::find($ordenId) === null) {
            return 'no_existe';
        }
        $despachados = (int)$db->query(
            "SELECT COUNT(*) FROM productos WHERE orden_id = {$ordenId} AND estado_actual <> 'INGRESADO'"
        )->fetchColumn();
        if ($despachados > 0) {
            return 'despachada';
        }
        $pids = $db->query('SELECT id FROM productos WHERE orden_id = ' . $ordenId)->fetchAll(\PDO::FETCH_COLUMN);

        $db->beginTransaction();
        try {
            foreach ($pids as $pid) {
                Producto::borrarFK($db, (int)$pid);
            }
            $db->prepare('DELETE FROM ordenes WHERE id = :id')->execute([':id' => $ordenId]);
            $db->commit();
            return 'ok';
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Historial de eventos de la orden (para el detalle): ingreso, etiquetas y
     * los cambios de estado de sus ítems. Más reciente primero.
     *
     * @return array<int, array{titulo:string, fecha:string, detalle:string}>
     */
    public static function historial(int $ordenId): array
    {
        $o = self::find($ordenId);
        if ($o === null) {
            return [];
        }
        $db = DB::getInstance();
        $eventos = [];

        // Ingreso (creación de la orden) y etiquetas impresas.
        $eventos[] = ['titulo' => 'Ingresada en depósito', 'fecha' => (string)$o['created_at'], 'detalle' => 'Por OCR'];

        $etq = $db->prepare('SELECT MIN(etiquetada_at) FROM productos WHERE orden_id = :id AND etiquetada_at IS NOT NULL');
        $etq->execute([':id' => $ordenId]);
        $fEtq = $etq->fetchColumn();
        if ($fEtq) {
            $eventos[] = ['titulo' => 'Etiquetas impresas', 'fecha' => (string)$fEtq, 'detalle' => ''];
        }

        // Cambios de estado de los ítems (primer ítem que alcanzó cada estado).
        $labels = ['EN_REPARTO' => 'Salió a reparto', 'ENTREGADO' => 'Entregada',
                   'REINGRESADO' => 'Reingresada', 'DEVUELTO' => 'Devuelta', 'BAJA' => 'Baja'];
        $stmt = $db->prepare(
            "SELECT t.estado_hasta, MIN(t.timestamp_server) AS fecha, COUNT(DISTINCT t.producto_id) AS n
             FROM transiciones t JOIN productos p ON p.id = t.producto_id
             WHERE p.orden_id = :id AND t.estado_hasta <> 'INGRESADO'
             GROUP BY t.estado_hasta"
        );
        $stmt->execute([':id' => $ordenId]);
        $totalItems = (int)$db->query('SELECT COUNT(*) FROM productos WHERE orden_id = ' . (int)$ordenId)->fetchColumn();
        foreach ($stmt->fetchAll() as $r) {
            $est = (string)$r['estado_hasta'];
            $eventos[] = [
                'titulo'  => $labels[$est] ?? $est,
                'fecha'   => (string)$r['fecha'],
                'detalle' => (int)$r['n'] . ' de ' . $totalItems . ' ítem(s)',
            ];
        }

        // Orden cronológico inverso (más reciente primero).
        usort($eventos, static fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));
        return $eventos;
    }

    /**
     * Busca una orden por su número público (usado por el seguimiento).
     *
     * @return array<string, mixed>|null
     */
    public static function findByNroOrden(string $nroOrden): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM ordenes WHERE nro_orden = :n LIMIT 1');
        $stmt->execute([':n' => $nroOrden]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function existeNroOrden(string $nroOrden): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM ordenes WHERE nro_orden = :n LIMIT 1');
        $stmt->execute([':n' => $nroOrden]);
        return (bool)$stmt->fetchColumn();
    }

    /** Fija el estado derivado de la orden (recalculado al transicionar ítems). */
    public static function actualizarEstado(int $id, string $estado): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE ordenes SET estado = :e WHERE id = :id');
        $stmt->execute([':e' => $estado, ':id' => $id]);
    }

    /**
     * Estado "de producto" representativo de la orden, derivado de sus ítems
     * (INGRESADO/EN_REPARTO/ENTREGADO/REINGRESADO/DEVUELTO), o null si no tiene ítems.
     * Lo usa el seguimiento público para indexar `estados_publicos`.
     */
    public static function estadoProductoDerivado(int $ordenId): ?string
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT estado_actual, COUNT(*) AS c FROM productos WHERE orden_id = :id GROUP BY estado_actual'
        );
        $stmt->execute([':id' => $ordenId]);
        $cnt = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $r) {
            $cnt[(string)$r['estado_actual']] = (int)$r['c'];
            $total += (int)$r['c'];
        }
        if ($total === 0) {
            return null;
        }
        $g = static fn(string $k): int => $cnt[$k] ?? 0;

        // Reglas (de "más avanzado" a "menos"): todos entregados / devueltos; si hay
        // alguno en reparto o ya entregado parcialmente → en reparto; reingreso; resto recibido.
        if ($g('ENTREGADO') === $total)  { return 'ENTREGADO'; }
        if ($g('DEVUELTO')  === $total)  { return 'DEVUELTO'; }
        if ($g('EN_REPARTO') > 0 || $g('ENTREGADO') > 0) { return 'EN_REPARTO'; }
        if ($g('REINGRESADO') > 0)       { return 'REINGRESADO'; }
        return 'INGRESADO';
    }

    /**
     * Primera vez (timestamp_cliente más antiguo) que la orden alcanzó cada estado,
     * agregando sobre todos sus ítems e ignorando transiciones marcadas como
     * conflicto. Lo usa el seguimiento público para fechar cada paso del timeline.
     *
     * @return array<string, string> estado => timestamp_cliente (UTC)
     */
    public static function fechasPorEstado(int $ordenId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT t.estado_hasta, MIN(t.timestamp_cliente) AS fecha
             FROM transiciones t JOIN productos p ON p.id = t.producto_id
             WHERE p.orden_id = :id AND t.es_conflicto = 0
             GROUP BY t.estado_hasta'
        );
        $stmt->execute([':id' => $ordenId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['estado_hasta']] = (string)$row['fecha'];
        }
        return $out;
    }

    /**
     * Recalcula y persiste `ordenes.estado` (vocabulario de orden: RECIBIDO en vez
     * de INGRESADO) a partir de los ítems. Lo invoca el ProcesadorLote al transicionar
     * productos de una orden.
     */
    public static function recalcularEstado(int $ordenId): void
    {
        $p = self::estadoProductoDerivado($ordenId);
        if ($p === null) {
            return; // orden sin ítems: no se toca
        }
        self::actualizarEstado($ordenId, $p === 'INGRESADO' ? 'RECIBIDO' : $p);
    }

    /**
     * Corrección manual del estado de una orden (para arreglar errores). Aplica un
     * AJUSTE MANUAL (transición es_ajuste_manual=1, sin conflicto) a TODOS los ítems
     * que no estén ya en $estadoProducto, y recalcula el estado de la orden. Devuelve
     * cuántos ítems cambió. $estadoProducto es un valor del enum Estado (vocabulario
     * de producto: INGRESADO para "RECIBIDO").
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function corregirEstado(int $ordenId, string $estadoProducto, string $motivo, int $usuarioId): int
    {
        $estado = \Trazock\Estado::tryFrom($estadoProducto);
        if ($estado === null) {
            throw new \InvalidArgumentException('Estado destino inválido.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \InvalidArgumentException('El motivo es obligatorio.');
        }

        $db = DB::getInstance();
        $stmt = $db->prepare('SELECT id, estado_actual FROM productos WHERE orden_id = :o');
        $stmt->execute([':o' => $ordenId]);
        $items = $stmt->fetchAll();
        if ($items === []) {
            throw new \RuntimeException('La orden no tiene ítems.');
        }

        $ahora     = gmdate('Y-m-d H:i:s');
        $motivo50  = mb_substr($motivo, 0, 50);
        $cambiados = 0;

        $db->beginTransaction();
        try {
            foreach ($items as $it) {
                $desde = (string)$it['estado_actual'];
                if ($desde === $estado->value) {
                    continue; // ya está en el estado destino
                }
                $pid = (int)$it['id'];
                $tid = Transicion::insertar($pid, null, $desde, $estado->value, $ahora, false, $motivo50, true, $usuarioId);
                // Solo fija el estado actual si no hay una transición más reciente (timestamps futuros).
                if (!Transicion::existeMasReciente($pid, $ahora)) {
                    Producto::fijarEstadoActual($pid, $estado->value, $tid);
                }
                $cambiados++;
            }
            self::recalcularEstado($ordenId);
            $db->commit();
            return $cambiados;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Backfill de estado con FECHA HISTÓRICA: fija el estado destino en TODOS los
     * ítems de la orden, datando la transición en $timestampUtc (no "ahora"). A
     * diferencia de corregirEstado(), fuerza el estado actual aunque exista una
     * transición más reciente (sirve para cargar órdenes viejas ya entregadas:
     * el ingreso quedó con fecha de hoy, pero el estado real es del pasado).
     * Devuelve cuántos ítems cambió. $estadoProducto es del enum Estado.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function fijarEstadoHistorico(int $ordenId, string $estadoProducto, string $timestampUtc, string $motivo, int $usuarioId): int
    {
        $estado = \Trazock\Estado::tryFrom($estadoProducto);
        if ($estado === null) {
            throw new \InvalidArgumentException('Estado destino inválido.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestampUtc)) {
            throw new \InvalidArgumentException('Fecha/hora inválida.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            $motivo = 'Carga histórica';
        }

        $db   = DB::getInstance();
        $stmt = $db->prepare('SELECT id, estado_actual FROM productos WHERE orden_id = :o');
        $stmt->execute([':o' => $ordenId]);
        $items = $stmt->fetchAll();
        if ($items === []) {
            throw new \RuntimeException('La orden no tiene ítems.');
        }

        $motivo50  = mb_substr($motivo, 0, 50);
        $cambiados = 0;

        $db->beginTransaction();
        try {
            foreach ($items as $it) {
                $desde = (string)$it['estado_actual'];
                if ($desde === $estado->value) {
                    continue; // ya está en el estado destino
                }
                $pid = (int)$it['id'];
                $tid = Transicion::insertar($pid, null, $desde, $estado->value, $timestampUtc, false, $motivo50, true, $usuarioId);
                // Backfill: forzamos el estado actual (no se chequea existeMasReciente).
                Producto::fijarEstadoActual($pid, $estado->value, $tid);
                $cambiados++;
            }
            self::recalcularEstado($ordenId);
            $db->commit();
            return $cambiados;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Resumen agregado de una carga confirmada (para la pantalla de confirmación
     * y la de etiquetas): nº de órdenes, ítems, m³ total y cuántos ítems ya tienen
     * su etiqueta impresa.
     *
     * @return array{ordenes:int, items:int, m3:float, etiquetados:int}
     */
    public static function resumenCarga(int $cargaId): array
    {
        // Placeholders distintos por subconsulta: con prepares nativos (EMULATE
        // OFF) no se puede reusar un mismo nombre en varias posiciones.
        $stmt = DB::getInstance()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM ordenes WHERE carga_id = :c1) AS ordenes,
                (SELECT COALESCE(SUM(m3_total), 0) FROM ordenes WHERE carga_id = :c2) AS m3,
                (SELECT COUNT(*) FROM productos p JOIN ordenes o ON o.id = p.orden_id
                   WHERE o.carga_id = :c3) AS items,
                (SELECT COUNT(*) FROM productos p JOIN ordenes o ON o.id = p.orden_id
                   WHERE o.carga_id = :c4 AND p.etiquetada_at IS NOT NULL) AS etiquetados'
        );
        $stmt->execute([':c1' => $cargaId, ':c2' => $cargaId, ':c3' => $cargaId, ':c4' => $cargaId]);
        $r = $stmt->fetch() ?: [];
        return [
            'ordenes'     => (int)($r['ordenes'] ?? 0),
            'items'       => (int)($r['items'] ?? 0),
            'm3'          => (float)($r['m3'] ?? 0),
            'etiquetados' => (int)($r['etiquetados'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Reportes (grilla con filtros)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    /**
     * Arma una cláusula `col IN (:pfx0, :pfx1, …)` para un filtro multi-valor, con
     * placeholders únicos (prepares nativos no permiten reusar nombres). Devuelve
     * null si no quedan valores. Agrega los binds a $params por referencia.
     *
     * @param array<int,mixed> $vals
     * @param array<string,mixed> $params
     */
    private static function inClause(string $col, array $vals, string $prefijo, array &$params): ?string
    {
        $vals = array_values(array_unique(array_filter(
            array_map(static fn($v) => trim((string)$v), $vals),
            static fn(string $v): bool => $v !== ''
        )));
        if ($vals === []) {
            return null;
        }
        $ph = [];
        foreach ($vals as $i => $v) {
            $k = ':' . $prefijo . $i;
            $ph[] = $k;
            $params[$k] = $v;
        }
        return $col . ' IN (' . implode(', ', $ph) . ')';
    }

    private static function whereFiltros(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['q'])) {
            // Placeholders distintos: con prepares nativos no se puede reusar :q.
            $where[] = '(o.nro_orden LIKE :q1 OR o.nro_remito LIKE :q2 OR o.cliente LIKE :q3)';
            $like = '%' . $f['q'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        // Multi-valor: destino (provincia), lote (carga) y hoja de ruta.
        if (($c = self::inClause('o.dest_provincia', (array)($f['provincia'] ?? []), 'prov', $params)) !== null) {
            $where[] = $c;
        }
        if (($c = self::inClause('o.carga_id', (array)($f['carga'] ?? []), 'carga', $params)) !== null) {
            $where[] = $c;
        }
        if (($c = self::inClause('o.hoja_ruta', (array)($f['hoja_ruta'] ?? []), 'hr', $params)) !== null) {
            $where[] = $c;
        }
        // Prefijo del Nº de orden (lo anterior al primer '-'): identifica el local/origen.
        if (($c = self::inClause("SUBSTRING_INDEX(o.nro_orden, '-', 1)", (array)($f['prefijo'] ?? []), 'pref', $params)) !== null) {
            $where[] = $c;
        }
        if (!empty($f['transportista'])) {
            $where[] = 'o.transportista_id = :transp';
            $params[':transp'] = (int)$f['transportista'];
        }
        if (!empty($f['categoria'])) {
            // La categoría es de la carga; la orden la hereda por su carga_id.
            $where[] = 'o.carga_id IN (SELECT cc.id FROM cargas cc WHERE cc.categoria_id = :cat)';
            $params[':cat'] = (int)$f['categoria'];
        }
        if (!empty($f['zona'])) {
            // La orden pertenece a la zona si su (provincia, localidad) está en sus
            // localidades. La collation unicode_ci compara sin distinguir mayúsculas
            // ni acentos; ciudad NULL/'' en la zona = toda la provincia.
            $where[] = 'EXISTS (SELECT 1 FROM zona_localidades zl
                               WHERE zl.zona_id = :zona
                                 AND zl.provincia = o.dest_provincia
                                 AND (zl.ciudad IS NULL OR zl.ciudad = \'\' OR zl.ciudad = o.dest_localidad))';
            $params[':zona'] = (int)$f['zona'];
        }
        if (!empty($f['estado'])) {
            $where[] = 'o.estado = :estado';
            $params[':estado'] = $f['estado'];
        }
        // Fecha de CARGA del documento (la que se ingresa al importar; columna DATE).
        if (!empty($f['fecha_desde'])) {
            $where[] = 'o.fecha_carga >= :fd';
            $params[':fd'] = $f['fecha_desde'];
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'o.fecha_carga <= :fh';
            $params[':fh'] = $f['fecha_hasta'];
        }

        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    /**
     * Filas para la grilla de Reportes: una por orden, con la cantidad de ítems
     * y la fecha de ingreso (created_at de la orden).
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function buscar(array $filtros, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT o.id, o.carga_id, o.nro_orden, o.nro_remito, o.fecha_remito,
                       o.hoja_ruta, o.transportista_id, o.fecha_carga,
                       o.cliente, o.telefonos, o.telefono_wa, o.dest_provincia, o.dest_localidad, o.m3_total,
                       o.valor_declarado, o.observaciones, o.marca, o.estado, o.created_at AS fecha_ingreso,
                       (SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id) AS cant_items,
                       (SELECT u.nombre_completo FROM usuarios u WHERE u.id = o.transportista_id) AS transportista_nombre,
                       (SELECT cat.nombre FROM cargas cg JOIN categorias cat ON cat.id = cg.categoria_id
                          WHERE cg.id = o.carga_id) AS categoria
                FROM ordenes o'
             . $where
             . " ORDER BY o.created_at DESC, o.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filtros
     */
    public static function contar(array $filtros): int
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM ordenes o' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Totales de la grilla filtrada (para el sumbar de Reportes): nº de órdenes,
     * Σ m³ y Σ de ítems físicos.
     *
     * @param array<string, mixed> $filtros
     * @return array{ordenes:int, items:int, m3:float}
     */
    public static function totales(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $db = DB::getInstance();

        $stmt = $db->prepare('SELECT COUNT(*) AS ordenes, COALESCE(SUM(o.m3_total), 0) AS m3 FROM ordenes o' . $where);
        $stmt->execute($params);
        $a = $stmt->fetch() ?: [];

        // Σ ítems = productos ligados a las órdenes filtradas (no se puede sumar en
        // la consulta anterior sin multiplicar m3_total por la cantidad de ítems).
        $stmt2 = $db->prepare('SELECT COUNT(*) FROM productos p JOIN ordenes o ON o.id = p.orden_id' . $where);
        $stmt2->execute($params);

        return [
            'ordenes' => (int)($a['ordenes'] ?? 0),
            'items'   => (int)$stmt2->fetchColumn(),
            'm3'      => (float)($a['m3'] ?? 0),
        ];
    }

    /**
     * Datos para el reporte de Facturación: una "factura" por (marca/proveedor,
     * tipo de venta). La marca de la orden surge de su categoría
     * (orden → carga → categoría → proveedor). Por cada factura, los m³ agregados
     * por destino (provincia) como ítems, y al pie transportista(s), fecha(s) de
     * carga y número(s) de hoja de ruta.
     *
     * @param array<string, mixed> $filtros
     * @return array<string, array{
     *     proveedor_id: int|null, proveedor: array<string,mixed>|null, tipo: string,
     *     destinos: array<int, array{provincia:string, m3:float}>,
     *     total_m3: float, transportistas: string, fechas: string, hojas_ruta: string
     * }>  Clave = "<proveedorId|0>|<tipo>".
     */
    public static function facturacion(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $db = DB::getInstance();

        $joinMarca = ' LEFT JOIN cargas cg ON cg.id = o.carga_id
                       LEFT JOIN categorias cat ON cat.id = cg.categoria_id';

        // Ítems: m³ por (proveedor, tipo, provincia).
        $stmtD = $db->prepare(
            "SELECT cat.proveedor_id AS prov_id,
                    COALESCE(o.tipo_venta, '')                 AS tipo,
                    COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS provincia,
                    COALESCE(SUM(o.m3_total), 0)               AS m3
             FROM ordenes o" . $joinMarca . $where . "
             GROUP BY prov_id, tipo, provincia
             ORDER BY prov_id ASC, tipo ASC, provincia ASC"
        );
        $stmtD->execute($params);

        $key = static fn($provId, string $tipo): string => (($provId === null) ? '0' : (string)(int)$provId) . '|' . $tipo;

        $out = [];
        foreach ($stmtD->fetchAll() as $r) {
            $provId = $r['prov_id'] !== null ? (int)$r['prov_id'] : null;
            $tipo   = (string)$r['tipo'];
            $k      = $key($provId, $tipo);
            if (!isset($out[$k])) {
                $out[$k] = ['proveedor_id' => $provId, 'proveedor' => null, 'tipo' => $tipo,
                            'destinos' => [], 'total_m3' => 0.0,
                            'transportistas' => '', 'fechas' => '', 'hojas_ruta' => ''];
            }
            $m3 = (float)$r['m3'];
            $out[$k]['destinos'][] = ['provincia' => (string)$r['provincia'], 'm3' => $m3];
            $out[$k]['total_m3']  += $m3;
        }

        // Pie por (proveedor, tipo): transportista(s), fecha(s), hoja(s) de ruta.
        $stmtF = $db->prepare(
            "SELECT cat.proveedor_id AS prov_id, COALESCE(o.tipo_venta, '') AS tipo,
                    GROUP_CONCAT(DISTINCT u.nombre_completo ORDER BY u.nombre_completo SEPARATOR ', ') AS transportistas,
                    GROUP_CONCAT(DISTINCT o.fecha_carga ORDER BY o.fecha_carga SEPARATOR ',')          AS fechas,
                    GROUP_CONCAT(DISTINCT o.hoja_ruta ORDER BY o.hoja_ruta SEPARATOR ', ')             AS hojas_ruta
             FROM ordenes o" . $joinMarca . "
             LEFT JOIN usuarios u ON u.id = o.transportista_id" . $where . "
             GROUP BY prov_id, tipo"
        );
        $stmtF->execute($params);
        foreach ($stmtF->fetchAll() as $r) {
            $provId = $r['prov_id'] !== null ? (int)$r['prov_id'] : null;
            $k = $key($provId, (string)$r['tipo']);
            if (!isset($out[$k])) { continue; }
            $out[$k]['transportistas'] = (string)($r['transportistas'] ?? '');
            $out[$k]['fechas']         = (string)($r['fechas'] ?? '');
            $out[$k]['hojas_ruta']     = (string)($r['hojas_ruta'] ?? '');
        }

        // Datos fiscales de cada proveedor presente.
        $ids = array_values(array_filter(array_map(static fn($f) => $f['proveedor_id'], $out), static fn($v) => $v !== null));
        if ($ids !== []) {
            $in = [];
            foreach (array_unique($ids) as $i => $id) { $ph = ':pid' . $i; $in[] = $ph; $params2[$ph] = $id; }
            $stmtP = $db->prepare(
                'SELECT id, nombre, razon_social, cuit, condicion_iva, domicilio
                 FROM proveedores WHERE id IN (' . implode(',', $in) . ')'
            );
            $stmtP->execute($params2 ?? []);
            $prov = [];
            foreach ($stmtP->fetchAll() as $p) { $prov[(int)$p['id']] = $p; }
            foreach ($out as $k => $f) {
                if ($f['proveedor_id'] !== null && isset($prov[$f['proveedor_id']])) {
                    $out[$k]['proveedor'] = $prov[$f['proveedor_id']];
                }
            }
        }

        return $out;
    }

    /**
     * Facturación para el Excel: m³ por destino (provincia) del set filtrado, con
     * transportista(s), fecha(s) de carga y hoja(s) de ruta al pie. Sin marca,
     * importes ni separación por tipo (la separación online/resto se hace filtrando
     * por prefijo y exportando dos veces).
     *
     * @param array<string, mixed> $filtros
     * @return array{destinos: array<int, array{provincia:string, m3:float}>,
     *               total_m3: float, transportistas: string, fechas: string, hojas_ruta: string}
     */
    public static function facturacionResumen(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $db = DB::getInstance();

        $stmtD = $db->prepare(
            "SELECT COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS provincia,
                    COALESCE(SUM(o.m3_total), 0) AS m3
             FROM ordenes o" . $where . "
             GROUP BY provincia ORDER BY provincia ASC"
        );
        $stmtD->execute($params);

        $out = ['destinos' => [], 'total_m3' => 0.0, 'transportistas' => '', 'fechas' => '', 'hojas_ruta' => ''];
        foreach ($stmtD->fetchAll() as $r) {
            $m3 = (float)$r['m3'];
            $out['destinos'][] = ['provincia' => (string)$r['provincia'], 'm3' => $m3];
            $out['total_m3']  += $m3;
        }

        $stmtF = $db->prepare(
            "SELECT GROUP_CONCAT(DISTINCT u.nombre_completo ORDER BY u.nombre_completo SEPARATOR ', ') AS transportistas,
                    GROUP_CONCAT(DISTINCT o.fecha_carga ORDER BY o.fecha_carga SEPARATOR ',')          AS fechas,
                    GROUP_CONCAT(DISTINCT o.hoja_ruta ORDER BY o.hoja_ruta SEPARATOR ', ')             AS hojas_ruta
             FROM ordenes o
             LEFT JOIN usuarios u ON u.id = o.transportista_id" . $where
        );
        $stmtF->execute($params);
        $f = $stmtF->fetch() ?: [];
        $out['transportistas'] = (string)($f['transportistas'] ?? '');
        $out['fechas']         = (string)($f['fechas'] ?? '');
        $out['hojas_ruta']     = (string)($f['hojas_ruta'] ?? '');

        return $out;
    }

    /**
     * Cantidades facturables por (proveedor/marca, provincia, fecha de carga) en
     * un período: m³ y bultos. Agrupar por fecha permite aplicar a cada hoja de
     * ruta el precio vigente a su fecha. Solo órdenes con proveedor definido.
     *
     * @return array<int, array{proveedor_id:int, provincia:string, fecha:string, m3:float, bultos:int}>
     */
    public static function cantidadesPorProveedorProvinciaFecha(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT cat.proveedor_id AS proveedor_id,
                    COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS provincia,
                    o.fecha_carga AS fecha,
                    COALESCE(SUM(o.m3_total), 0) AS m3,
                    COALESCE(SUM((SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id)), 0) AS bultos
             FROM ordenes o
             JOIN cargas cg ON cg.id = o.carga_id
             JOIN categorias cat ON cat.id = cg.categoria_id
             WHERE cat.proveedor_id IS NOT NULL
               AND o.fecha_carga BETWEEN :d AND :h
             GROUP BY cat.proveedor_id, provincia, o.fecha_carga
             ORDER BY cat.proveedor_id, provincia, o.fecha_carga"
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return array_map(static fn($r) => [
            'proveedor_id' => (int)$r['proveedor_id'],
            'provincia'    => (string)$r['provincia'],
            'fecha'        => (string)$r['fecha'],
            'm3'           => (float)$r['m3'],
            'bultos'       => (int)$r['bultos'],
        ], $stmt->fetchAll());
    }

    /**
     * Detalle orden por orden para el respaldo de la factura, agrupado por
     * (marca/proveedor, tipo). Mismos filtros que facturacion().
     *
     * @param array<string, mixed> $filtros
     * @return array<string, array<int, array{nro_orden:string, nro_remito:string,
     *     cliente:string, provincia:string, m3:float}>>  Clave = "<proveedorId|0>|<tipo>".
     */
    public static function facturacionDetalle(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare(
            "SELECT cat.proveedor_id AS prov_id, COALESCE(o.tipo_venta, '') AS tipo,
                    o.nro_orden, o.nro_remito, o.cliente,
                    COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS provincia,
                    COALESCE(o.m3_total, 0) AS m3
             FROM ordenes o
             LEFT JOIN cargas cg ON cg.id = o.carga_id
             LEFT JOIN categorias cat ON cat.id = cg.categoria_id" . $where . "
             ORDER BY prov_id ASC, tipo ASC, provincia ASC, o.nro_orden ASC"
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $provId = $r['prov_id'] !== null ? (int)$r['prov_id'] : null;
            $k = (($provId === null) ? '0' : (string)$provId) . '|' . (string)$r['tipo'];
            $out[$k][] = [
                'nro_orden'  => (string)$r['nro_orden'],
                'nro_remito' => (string)($r['nro_remito'] ?? ''),
                'cliente'    => (string)($r['cliente'] ?? ''),
                'provincia'  => (string)$r['provincia'],
                'm3'         => (float)$r['m3'],
            ];
        }
        return $out;
    }

    /**
     * Provincias de destino distintas presentes en las órdenes (para el filtro).
     *
     * @return array<int, string>
     */
    public static function provincias(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT DISTINCT dest_provincia FROM ordenes
             WHERE dest_provincia IS NOT NULL AND dest_provincia <> ''
             ORDER BY dest_provincia"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /**
     * Números de hoja de ruta distintos presentes en las órdenes (para el filtro).
     *
     * @return array<int, string>
     */
    public static function hojasRuta(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT DISTINCT hoja_ruta FROM ordenes
             WHERE hoja_ruta IS NOT NULL AND hoja_ruta <> ''
             ORDER BY hoja_ruta"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /**
     * Transportistas que figuran en alguna orden (id + nombre), para el filtro.
     *
     * @return array<int, array{id:int, nombre:string}>
     */
    public static function transportistasUsados(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT DISTINCT u.id, u.nombre_completo
             FROM ordenes o JOIN usuarios u ON u.id = o.transportista_id
             WHERE o.transportista_id IS NOT NULL
             ORDER BY u.nombre_completo"
        )->fetchAll();
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre_completo']], $rows);
    }

    /**
     * Cantidad de órdenes por provincia de destino en el set filtrado (para el
     * banner de "destino sospechoso" en Reportes). Clave = provincia (o
     * '(sin provincia)'), valor = cantidad. Ordenado de mayor a menor.
     *
     * @param array<string, mixed> $filtros
     * @return array<string, int>
     */
    public static function conteoPorProvincia(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare(
            "SELECT COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS prov, COUNT(*) AS n
               FROM ordenes o" . $where . '
              GROUP BY prov ORDER BY n DESC'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string)$r['prov']] = (int)$r['n'];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Listado público por prefijo (acceso por token del local)
    // -------------------------------------------------------------------------

    /**
     * WHERE del listado público de un local (por prefijo). Filtros: fecha de
     * recepción (created_at), estado y búsqueda por Nº de orden.
     *
     * @param array<string,mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function wherePublico(string $prefijo, array $f): array
    {
        $where  = ["SUBSTRING_INDEX(o.nro_orden, '-', 1) = :pref"];
        $params = [':pref' => $prefijo];
        if (!empty($f['fecha_desde'])) { $where[] = 'DATE(o.created_at) >= :fd'; $params[':fd'] = $f['fecha_desde']; }
        if (!empty($f['fecha_hasta'])) { $where[] = 'DATE(o.created_at) <= :fh'; $params[':fh'] = $f['fecha_hasta']; }
        if (!empty($f['estado']) && in_array($f['estado'], self::ESTADOS, true)) {
            $where[] = 'o.estado = :est'; $params[':est'] = $f['estado'];
        }
        if (!empty($f['q'])) { $where[] = 'o.nro_orden LIKE :q'; $params[':q'] = '%' . $f['q'] . '%'; }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /** Expresión SQL de la fecha/hora en que la orden alcanzó su estado actual. */
    private static function fechaEstadoExpr(): string
    {
        return "(SELECT MAX(t.timestamp_cliente)
                   FROM transiciones t JOIN productos p2 ON p2.id = t.producto_id
                  WHERE p2.orden_id = o.id AND t.es_conflicto = 0
                    AND t.estado_hasta = CASE WHEN o.estado = 'RECIBIDO' THEN 'INGRESADO' ELSE o.estado END)";
    }

    /**
     * Órdenes de un local (prefijo) para la página pública. Nº orden, estado,
     * cantidad de ítems y fecha/hora del estado actual.
     *
     * @param array<string,mixed> $f
     * @return array<int, array<string,mixed>>
     */
    public static function listarPorPrefijo(string $prefijo, array $f, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::wherePublico($prefijo, $f);
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT o.id, o.nro_orden, o.estado, o.created_at,
                       (SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id) AS cant_items,
                       ' . self::fechaEstadoExpr() . ' AS fecha_estado
                FROM ordenes o' . $where
             . " ORDER BY o.created_at DESC, o.id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $f */
    public static function contarPorPrefijo(string $prefijo, array $f): int
    {
        [$where, $params] = self::wherePublico($prefijo, $f);
        $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM ordenes o' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Ítems agrupados (cantidad por descripción+dimensiones) de un conjunto de
     * órdenes, para el detalle expandible del listado público.
     *
     * @param array<int,int> $ordenIds
     * @return array<int, array<int, array{cantidad:int, descripcion:string, dimensiones:string}>>
     */
    public static function itemsAgrupadosDeOrdenes(array $ordenIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ordenIds), static fn($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = [];
        $params = [];
        foreach ($ids as $i => $id) { $k = ':o' . $i; $ph[] = $k; $params[$k] = $id; }
        $stmt = DB::getInstance()->prepare(
            "SELECT orden_id,
                    COALESCE(descripcion, '') AS descripcion,
                    COALESCE(dimensiones, '') AS dimensiones,
                    COUNT(*) AS cantidad
               FROM productos
              WHERE orden_id IN (" . implode(', ', $ph) . ")
              GROUP BY orden_id, descripcion, dimensiones
              ORDER BY orden_id, descripcion"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['orden_id']][] = [
                'cantidad'    => (int)$r['cantidad'],
                'descripcion' => (string)$r['descripcion'],
                'dimensiones' => (string)$r['dimensiones'],
            ];
        }
        return $out;
    }

    /** Fija (o limpia con null) la marca operativa de una orden. */
    public static function setMarca(int $id, ?string $marca): void
    {
        $marca = ($marca !== null && in_array($marca, self::MARCAS, true)) ? $marca : null;
        $stmt = DB::getInstance()->prepare('UPDATE ordenes SET marca = :m WHERE id = :id');
        $stmt->bindValue(':m', $marca, $marca === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Órdenes marcadas 'no_entregar' (nro_orden + observación) para que el escáner
     * avise al intentar escanearlas en reparto/entrega. Lista chica (solo marcadas).
     *
     * @return array<int, array{nro_orden:string, observaciones:string}>
     */
    public static function noEntregar(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT nro_orden, COALESCE(observaciones, '') AS observaciones
             FROM ordenes WHERE marca = 'no_entregar' ORDER BY nro_orden"
        )->fetchAll();
        return array_map(
            static fn($r) => ['nro_orden' => (string)$r['nro_orden'], 'observaciones' => (string)$r['observaciones']],
            $rows
        );
    }
}
