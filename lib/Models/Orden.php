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
            $where[] = '(o.nro_orden LIKE :q OR o.nro_remito LIKE :q OR o.cliente LIKE :q)';
            $params[':q'] = '%' . $f['q'] . '%';
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
}
