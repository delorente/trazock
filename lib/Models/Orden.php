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

    /** Columnas escribibles de una orden (para crear/actualizar desde la carga). */
    private const CAMPOS = [
        'carga_id', 'nro_orden', 'nro_remito', 'fecha_remito', 'tipo_venta',
        'cliente', 'cliente_apellido', 'telefonos',
        'dest_provincia', 'dest_localidad', 'dest_domicilio', 'dest_cp',
        'valor_declarado', 'm3_total', 'estado',
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
        'nro_remito', 'fecha_remito', 'tipo_venta', 'cliente', 'cliente_apellido',
        'telefonos', 'dest_provincia', 'dest_localidad', 'dest_domicilio', 'dest_cp',
        'valor_declarado',
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
        if (!empty($f['provincia'])) {
            $where[] = 'o.dest_provincia = :prov';
            $params[':prov'] = $f['provincia'];
        }
        if (!empty($f['estado'])) {
            $where[] = 'o.estado = :estado';
            $params[':estado'] = $f['estado'];
        }
        if (!empty($f['tipo_venta'])) {
            $where[] = 'o.tipo_venta = :tv';
            $params[':tv'] = $f['tipo_venta'];
        }
        if (!empty($f['fecha_desde'])) {
            $where[] = 'o.fecha_remito >= :fd';
            $params[':fd'] = $f['fecha_desde'];
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'o.fecha_remito <= :fh';
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

        $sql = 'SELECT o.id, o.nro_orden, o.nro_remito, o.fecha_remito, o.tipo_venta,
                       o.cliente, o.dest_provincia, o.dest_localidad, o.m3_total,
                       o.valor_declarado, o.estado, o.created_at AS fecha_ingreso,
                       (SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id) AS cant_items
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
}
