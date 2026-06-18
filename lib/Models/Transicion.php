<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

final class Transicion
{
    // -------------------------------------------------------------------------
    // Métodos del ProcesadorLote (timeline + inserción)
    // -------------------------------------------------------------------------

    /**
     * Estado del producto "en el momento de $ts": estado_hasta de la transición
     * más reciente con timestamp_cliente <= $ts. Devuelve null si no hay ninguna.
     *
     * Se usa <= (no <) y debe llamarse ANTES de insertar la transición actual: así,
     * cuando dos lotes secuenciales comparten el mismo timestamp_cliente, el que se
     * procesa después encadena correctamente desde el anterior (que ya está insertado,
     * con id menor). El desempate por `id DESC` respeta el orden de llegada (R10).
     */
    public static function estadoEnTimestamp(int $productoId, string $ts): ?string
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT estado_hasta FROM transiciones
             WHERE producto_id = :pid AND timestamp_cliente <= :ts
             ORDER BY timestamp_cliente DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([':pid' => $productoId, ':ts' => $ts]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string)$val;
    }

    /**
     * ¿Existe alguna transición del producto con timestamp_cliente estrictamente
     * posterior a $ts? Si la hay, la transición en $ts NO es la más reciente y no
     * debe actualizar productos.estado_actual (R4).
     */
    public static function existeMasReciente(int $productoId, string $ts): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM transiciones
             WHERE producto_id = :pid AND timestamp_cliente > :ts
             LIMIT 1'
        );
        $stmt->execute([':pid' => $productoId, ':ts' => $ts]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Inserta una transición y devuelve su id.
     */
    public static function insertar(
        int $productoId,
        ?int $loteId,
        ?string $estadoDesde,
        string $estadoHasta,
        string $timestampCliente,
        bool $esConflicto,
        ?string $motivoConflicto,
        bool $esAjusteManual = false,
        ?int $ajustadoPor = null
    ): int {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO transiciones
                (producto_id, lote_id, estado_desde, estado_hasta, timestamp_cliente,
                 es_conflicto, motivo_conflicto, es_ajuste_manual, ajustado_por)
             VALUES
                (:pid, :lote, :desde, :hasta, :ts, :conf, :motivo, :manual, :ajustado)'
        );
        $stmt->bindValue(':pid', $productoId, PDO::PARAM_INT);
        $stmt->bindValue(':lote', $loteId, $loteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':desde', $estadoDesde, $estadoDesde === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':hasta', $estadoHasta);
        $stmt->bindValue(':ts', $timestampCliente);
        $stmt->bindValue(':conf', $esConflicto ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':motivo', $motivoConflicto, $motivoConflicto === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':manual', $esAjusteManual ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':ajustado', $ajustadoPor, $ajustadoPor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Consultas de panel
    // -------------------------------------------------------------------------

    /**
     * Historial completo de un producto (más nueva primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historialProducto(int $productoId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT t.*, l.tipo AS lote_tipo, l.uuid AS lote_uuid, l.created_at AS lote_created,
                    l.observaciones AS lote_observaciones,
                    ru.nombre_completo AS responsable_nombre,
                    tu.nombre_completo AS transportista_nombre,
                    au.nombre_completo AS ajustado_por_nombre,
                    cp.nota_resolucion AS conflicto_nota, cp.revisado_at AS conflicto_revisado_at,
                    cpu.nombre_completo AS conflicto_revisado_por
             FROM transiciones t
             LEFT JOIN lotes l     ON l.id = t.lote_id
             LEFT JOIN usuarios ru ON ru.id = l.responsable_id
             LEFT JOIN usuarios tu ON tu.id = l.transportista_id
             LEFT JOIN usuarios au ON au.id = t.ajustado_por
             LEFT JOIN conflictos_producto cp ON cp.transicion_id = t.id
             LEFT JOIN usuarios cpu ON cpu.id = cp.revisado_por
             WHERE t.producto_id = :pid
             ORDER BY t.timestamp_cliente DESC, t.id DESC'
        );
        $stmt->execute([':pid' => $productoId]);
        return $stmt->fetchAll();
    }

    /**
     * Primera vez (timestamp_cliente más antiguo) que el producto alcanzó cada
     * estado. Se usa en la landing pública para fechar cada paso de la línea de
     * tiempo. Se ignoran las transiciones marcadas como conflicto para no datar
     * un paso con un escaneo erróneo.
     *
     * @return array<string, string> estado => timestamp_cliente (UTC)
     */
    public static function fechasPorEstado(int $productoId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT estado_hasta, MIN(timestamp_cliente) AS fecha
             FROM transiciones
             WHERE producto_id = :pid AND es_conflicto = 0
             GROUP BY estado_hasta'
        );
        $stmt->execute([':pid' => $productoId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['estado_hasta']] = (string)$row['fecha'];
        }
        return $out;
    }
}
