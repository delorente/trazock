<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

final class Lote
{
    // -------------------------------------------------------------------------
    // Procesamiento
    // -------------------------------------------------------------------------

    /**
     * Busca un lote por uuid (para idempotencia R1). Bloquea la fila si existe.
     *
     * @return array<string, mixed>|null
     */
    public static function findByUuidForUpdate(string $uuid): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, uuid, tipo FROM lotes WHERE uuid = :uuid LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Busca un lote por uuid sin bloqueo (recovery / consultas).
     *
     * @return array<string, mixed>|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, uuid, tipo, responsable_id FROM lotes WHERE uuid = :uuid LIMIT 1'
        );
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Inserta el encabezado del lote y devuelve su id.
     *
     * @param array<string, mixed> $d Campos del lote ya validados.
     */
    public static function crear(array $d): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO lotes
                (uuid, tipo, categoria_id, proveedor_id, transportista_id, motivo_id,
                 motivo_libre, responsable_id, observaciones, numero_remito,
                 timestamp_apertura, timestamp_cierre, timestamp_sync, dispositivo_info)
             VALUES
                (:uuid, :tipo, :categoria_id, :proveedor_id, :transportista_id, :motivo_id,
                 :motivo_libre, :responsable_id, :observaciones, :numero_remito,
                 :ts_apertura, :ts_cierre, NOW(), :dispositivo)'
        );
        $bindNullableInt = static function (string $key, $val) use ($stmt): void {
            $stmt->bindValue($key, $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        };
        $bindNullableStr = static function (string $key, $val) use ($stmt): void {
            $stmt->bindValue($key, $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        };

        $stmt->bindValue(':uuid', $d['uuid']);
        $stmt->bindValue(':tipo', $d['tipo']);
        $bindNullableInt(':categoria_id', $d['categoria_id']);
        $bindNullableInt(':proveedor_id', $d['proveedor_id']);
        $bindNullableInt(':transportista_id', $d['transportista_id']);
        $bindNullableInt(':motivo_id', $d['motivo_id']);
        $bindNullableStr(':motivo_libre', $d['motivo_libre']);
        $stmt->bindValue(':responsable_id', $d['responsable_id'], PDO::PARAM_INT);
        $bindNullableStr(':observaciones', $d['observaciones']);
        $bindNullableStr(':numero_remito', $d['numero_remito']);
        $bindNullableStr(':ts_apertura', $d['timestamp_apertura']);
        $bindNullableStr(':ts_cierre', $d['timestamp_cierre']);
        $bindNullableStr(':dispositivo', $d['dispositivo_info']);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Inserta un registro en lote_items.
     */
    public static function insertarItem(
        int $loteId,
        string $codigo,
        string $timestampCliente,
        ?int $transicionId,
        string $resultado
    ): void {
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO lote_items (lote_id, codigo_escaneado, timestamp_cliente, transicion_id, resultado)
             VALUES (:lote, :codigo, :ts, :tid, :resultado)'
        );
        $stmt->bindValue(':lote', $loteId, PDO::PARAM_INT);
        $stmt->bindValue(':codigo', $codigo);
        $stmt->bindValue(':ts', $timestampCliente);
        $stmt->bindValue(':tid', $transicionId, $transicionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':resultado', $resultado);
        $stmt->execute();
    }

    /**
     * Reconstruye el resumen de resultado de un lote ya procesado (idempotencia R1
     * y respuesta al cliente). Cuenta por resultado y arma el detalle.
     *
     * @return array<string, mixed>
     */
    public static function resumen(int $loteId): array
    {
        $db = DB::getInstance();

        $stmt = $db->prepare(
            'SELECT li.codigo_escaneado, li.resultado, t.estado_hasta, t.es_conflicto
             FROM lote_items li
             LEFT JOIN transiciones t ON t.id = li.transicion_id
             WHERE li.lote_id = :lote
             ORDER BY li.id ASC'
        );
        $stmt->execute([':lote' => $loteId]);
        $items = $stmt->fetchAll();

        $aplicadas  = 0;
        $ignorados  = 0;
        $conflictos = 0;
        $detalle    = [];

        foreach ($items as $it) {
            $res = $it['resultado'];
            if ($res === 'aplicado' || $res === 'aplicado_con_conflicto') {
                $aplicadas++;
            } else {
                $ignorados++;
            }
            if ((int)($it['es_conflicto'] ?? 0) === 1) {
                $conflictos++;
            }
            $detalle[] = [
                'codigo'            => $it['codigo_escaneado'],
                'resultado'         => $res,
                'estado_resultante' => $it['estado_hasta'],
            ];
        }

        return [
            'lote_id'                => $loteId,
            'items_procesados'       => count($items),
            'transiciones_aplicadas' => $aplicadas,
            'items_ignorados'        => $ignorados,
            'conflictos_generados'   => $conflictos,
            'detalle'                => $detalle,
        ];
    }

    // -------------------------------------------------------------------------
    // Consultas de panel
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT l.*, c.nombre AS categoria_nombre, p.nombre AS proveedor_nombre,
                    tu.nombre_completo AS transportista_nombre,
                    ru.nombre_completo AS responsable_nombre,
                    m.nombre AS motivo_nombre
             FROM lotes l
             LEFT JOIN categorias c ON c.id = l.categoria_id
             LEFT JOIN proveedores p ON p.id = l.proveedor_id
             LEFT JOIN usuarios tu ON tu.id = l.transportista_id
             LEFT JOIN usuarios ru ON ru.id = l.responsable_id
             LEFT JOIN motivos m ON m.id = l.motivo_id
             WHERE l.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Listado filtrable de lotes para el panel.
     *
     * @param array<string, mixed> $f Filtros: tipo, responsable_id, fecha_desde, fecha_hasta, con_conflictos.
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $f = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['tipo'])) {
            $where[] = 'l.tipo = :tipo';
            $params[':tipo'] = $f['tipo'];
        }
        if (!empty($f['responsable_id'])) {
            $where[] = 'l.responsable_id = :resp';
            $params[':resp'] = (int)$f['responsable_id'];
        }
        if (!empty($f['fecha_desde'])) {
            $where[] = 'l.created_at >= :fdesde';
            $params[':fdesde'] = $f['fecha_desde'] . ' 00:00:00';
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'l.created_at <= :fhasta';
            $params[':fhasta'] = $f['fecha_hasta'] . ' 23:59:59';
        }

        $having = '';
        if (isset($f['con_conflictos']) && $f['con_conflictos'] !== '') {
            $having = ((int)$f['con_conflictos'] === 1) ? ' HAVING conflictos > 0' : ' HAVING conflictos = 0';
        }

        $sql = 'SELECT l.id, l.tipo, l.created_at, l.timestamp_cierre,
                       u.nombre_completo AS responsable,
                       (SELECT COUNT(*) FROM lote_items li WHERE li.lote_id = l.id) AS items,
                       (SELECT COUNT(*) FROM transiciones t WHERE t.lote_id = l.id AND t.es_conflicto = 1) AS conflictos
                FROM lotes l
                LEFT JOIN usuarios u ON u.id = l.responsable_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= $having . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 300';

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Items de un lote con su transición/producto asociado (para detalle).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(int $loteId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT li.*, t.estado_desde, t.estado_hasta, t.es_conflicto, t.producto_id,
                    pr.codigo AS producto_codigo
             FROM lote_items li
             LEFT JOIN transiciones t ON t.id = li.transicion_id
             LEFT JOIN productos pr ON pr.id = t.producto_id
             WHERE li.lote_id = :lote
             ORDER BY li.id ASC'
        );
        $stmt->execute([':lote' => $loteId]);
        return $stmt->fetchAll();
    }
}
