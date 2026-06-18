<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

final class Conflicto
{
    /**
     * Inserta un conflicto en conflictos_producto. Devuelve su id.
     */
    public static function crear(
        int $productoId,
        int $transicionId,
        ?int $loteId,
        string $tipo,
        string $descripcion
    ): int {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO conflictos_producto
                (producto_id, transicion_id, lote_id, tipo, descripcion)
             VALUES (:pid, :tid, :lote, :tipo, :descripcion)'
        );
        $stmt->bindValue(':pid', $productoId, PDO::PARAM_INT);
        $stmt->bindValue(':tid', $transicionId, PDO::PARAM_INT);
        $stmt->bindValue(':lote', $loteId, $loteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':descripcion', $descripcion);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    /**
     * Cantidad de conflictos pendientes (sin revisar) de un producto.
     */
    public static function pendientesDeProducto(int $productoId): int
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT COUNT(*) FROM conflictos_producto
             WHERE producto_id = :pid AND revisado_at IS NULL'
        );
        $stmt->execute([':pid' => $productoId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM conflictos_producto WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Marca un conflicto como revisado. Devuelve el producto_id afectado, o null.
     */
    public static function marcarRevisado(int $id, int $revisadoPor, ?string $nota): ?int
    {
        $db = DB::getInstance();

        $c = self::find($id);
        if ($c === null || $c['revisado_at'] !== null) {
            return null;
        }

        $stmt = $db->prepare(
            'UPDATE conflictos_producto
             SET revisado_por = :por, revisado_at = NOW(), nota_resolucion = :nota
             WHERE id = :id'
        );
        $stmt->bindValue(':por', $revisadoPor, PDO::PARAM_INT);
        $stmt->bindValue(':nota', $nota, $nota === null || $nota === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$c['producto_id'];
    }

    /** Total de conflictos pendientes de revisar (para el badge del sidebar). */
    public static function totalPendientes(): int
    {
        return (int)DB::getInstance()
            ->query('SELECT COUNT(*) FROM conflictos_producto WHERE revisado_at IS NULL')
            ->fetchColumn();
    }

    /**
     * Conflictos de un producto (para el detalle). Si $soloPendientes, sólo sin revisar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deProducto(int $productoId, bool $soloPendientes = false): array
    {
        $sql = 'SELECT cp.*, ru.nombre_completo AS revisado_por_nombre
                FROM conflictos_producto cp
                LEFT JOIN usuarios ru ON ru.id = cp.revisado_por
                WHERE cp.producto_id = :pid';
        if ($soloPendientes) {
            $sql .= ' AND cp.revisado_at IS NULL';
        }
        $sql .= ' ORDER BY cp.fecha_generacion DESC';
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute([':pid' => $productoId]);
        return $stmt->fetchAll();
    }

    /**
     * Listado de conflictos para el panel.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listar(bool $soloPendientes, array $filtros = []): array
    {
        $where  = [];
        $params = [];

        if ($soloPendientes) {
            $where[] = 'cp.revisado_at IS NULL';
        }
        if (!empty($filtros['tipo'])) {
            $where[] = 'cp.tipo = :tipo';
            $params[':tipo'] = $filtros['tipo'];
        }
        if (!empty($filtros['categoria_id'])) {
            $where[] = 'p.categoria_id = :cat';
            $params[':cat'] = (int)$filtros['categoria_id'];
        }

        $sql = 'SELECT cp.*, p.codigo AS producto_codigo, p.categoria_id,
                       c.nombre AS categoria_nombre,
                       t.estado_desde, t.estado_hasta,
                       ru.nombre_completo AS revisado_por_nombre
                FROM conflictos_producto cp
                JOIN productos p   ON p.id = cp.producto_id
                LEFT JOIN categorias c ON c.id = p.categoria_id
                JOIN transiciones t ON t.id = cp.transicion_id
                LEFT JOIN usuarios ru ON ru.id = cp.revisado_por';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY cp.fecha_generacion DESC LIMIT 500';

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
