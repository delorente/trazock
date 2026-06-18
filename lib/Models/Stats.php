<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Consultas agregadas para el dashboard y listados del panel.
 */
final class Stats
{
    /** Orden canónico de estados para la tabla cruzada. */
    public const ESTADOS = ['INGRESADO', 'EN_REPARTO', 'ENTREGADO', 'REINGRESADO', 'DEVUELTO', 'BAJA'];

    /**
     * KPIs del dashboard.
     *
     * @return array<string, int>
     */
    public static function kpis(): array
    {
        $db = DB::getInstance();

        $total      = (int)$db->query('SELECT COUNT(*) FROM productos')->fetchColumn();
        $enDeposito = (int)$db->query(
            "SELECT COUNT(*) FROM productos WHERE estado_actual IN ('INGRESADO','REINGRESADO')"
        )->fetchColumn();
        $enReparto  = (int)$db->query(
            "SELECT COUNT(*) FROM productos WHERE estado_actual = 'EN_REPARTO'"
        )->fetchColumn();
        $entregadosMes = (int)$db->query(
            "SELECT COUNT(DISTINCT producto_id) FROM transiciones
             WHERE estado_hasta = 'ENTREGADO'
               AND timestamp_cliente >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();
        $conflictos = (int)$db->query(
            'SELECT COUNT(*) FROM conflictos_producto WHERE revisado_at IS NULL'
        )->fetchColumn();

        return [
            'total'          => $total,
            'en_deposito'    => $enDeposito,
            'en_reparto'     => $enReparto,
            'entregados_mes' => $entregadosMes,
            'conflictos'     => $conflictos,
        ];
    }

    /**
     * Tabla cruzada categoría × estado. Cada fila: nombre + conteos por estado + total.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tablaCategoriaEstado(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT COALESCE(c.nombre, '(sin categoría)') AS categoria,
                    p.estado_actual AS estado,
                    COUNT(*) AS n
             FROM productos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             GROUP BY categoria, p.estado_actual
             ORDER BY categoria ASC"
        )->fetchAll();

        $tabla = [];
        foreach ($rows as $r) {
            $cat = $r['categoria'];
            if (!isset($tabla[$cat])) {
                $tabla[$cat] = ['categoria' => $cat, 'total' => 0];
                foreach (self::ESTADOS as $e) {
                    $tabla[$cat][$e] = 0;
                }
            }
            $tabla[$cat][$r['estado']] = (int)$r['n'];
            $tabla[$cat]['total']     += (int)$r['n'];
        }

        return array_values($tabla);
    }

    /**
     * Últimos N lotes con conteo de items y de conflictos generados.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ultimosLotes(int $limite = 10): array
    {
        $limite = max(1, min(50, $limite));
        $sql = "SELECT l.id, l.tipo, l.timestamp_cierre, l.created_at,
                       u.nombre_completo AS responsable,
                       (SELECT COUNT(*) FROM lote_items li WHERE li.lote_id = l.id) AS items,
                       (SELECT COUNT(*) FROM transiciones t WHERE t.lote_id = l.id AND t.es_conflicto = 1) AS conflictos
                FROM lotes l
                LEFT JOIN usuarios u ON u.id = l.responsable_id
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT {$limite}";
        return DB::getInstance()->query($sql)->fetchAll();
    }
}
