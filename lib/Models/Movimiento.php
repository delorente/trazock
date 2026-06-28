<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Estadísticas de movimientos: agrega los "viajes" (lotes con vehículo) por
 * vehículo, conductor, ayudante y destino, en un período.
 *
 * Un "viaje" = lote de tipo INGRESO, SALIDA_REPARTO o SALIDA_DEVOLUCION.
 * La fecha del viaje = cierre del lote (o apertura / created_at como respaldo).
 * m³/bultos de un viaje = suma de los productos escaneados en ese lote.
 */
final class Movimiento
{
    public const TIPOS_VIAJE = ['INGRESO', 'SALIDA_REPARTO', 'SALIDA_DEVOLUCION'];

    /** Expresión de la fecha del viaje (no es un placeholder). */
    private const FECHA = 'DATE(COALESCE(l.timestamp_cierre, l.timestamp_apertura, l.created_at))';

    /**
     * WHERE común (tipos de viaje + rango de fechas). Rellena $params.
     *
     * @param array<int, string> $tipos
     * @param array<string, mixed> $params
     */
    private static function baseWhere(string $desde, string $hasta, array $tipos, array &$params): string
    {
        $tipos = array_values(array_intersect($tipos, self::TIPOS_VIAJE)) ?: self::TIPOS_VIAJE;
        $ph = [];
        foreach ($tipos as $i => $t) { $k = ':tp' . $i; $ph[] = $k; $params[$k] = $t; }
        $params[':desde'] = $desde;
        $params[':hasta'] = $hasta;
        return 'WHERE l.tipo IN (' . implode(',', $ph) . ') AND ' . self::FECHA . ' BETWEEN :desde AND :hasta';
    }

    /** Subconsulta por lote: bultos (productos distintos) y m³. */
    private const LOTE_M3 =
        ' LEFT JOIN (SELECT t.lote_id, COUNT(DISTINCT p.id) AS bultos, COALESCE(SUM(p.m3), 0) AS m3
                     FROM transiciones t JOIN productos p ON p.id = t.producto_id
                     GROUP BY t.lote_id) lm ON lm.lote_id = l.id';

    /**
     * @return array{viajes:int, m3:float, bultos:int}
     */
    public static function resumen(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = 'SELECT COUNT(DISTINCT l.id) AS viajes,
                       COALESCE(SUM(lm.m3), 0) AS m3,
                       COALESCE(SUM(lm.bultos), 0) AS bultos
                FROM lotes l' . self::LOTE_M3 . ' ' . $where;
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        return ['viajes' => (int)($r['viajes'] ?? 0), 'm3' => (float)($r['m3'] ?? 0), 'bultos' => (int)($r['bultos'] ?? 0)];
    }

    /**
     * Viajes por vehículo.
     * @return array<int, array<string, mixed>>
     */
    public static function porVehiculo(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = 'SELECT v.id, v.nombre,
                       COUNT(DISTINCT l.id) AS viajes,
                       COALESCE(SUM(lm.m3), 0) AS m3,
                       COALESCE(SUM(lm.bultos), 0) AS bultos
                FROM lotes l
                JOIN vehiculos v ON v.id = l.vehiculo_id' . self::LOTE_M3 . ' ' . $where . '
                GROUP BY v.id, v.nombre
                ORDER BY viajes DESC, v.nombre ASC';
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Viajes por conductor (transportista del lote).
     * @return array<int, array<string, mixed>>
     */
    public static function porConductor(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = 'SELECT u.id, u.nombre_completo AS nombre,
                       COUNT(DISTINCT l.id) AS viajes,
                       COALESCE(SUM(lm.m3), 0) AS m3,
                       COALESCE(SUM(lm.bultos), 0) AS bultos
                FROM lotes l
                JOIN usuarios u ON u.id = l.transportista_id' . self::LOTE_M3 . ' ' . $where . '
                GROUP BY u.id, u.nombre_completo
                ORDER BY viajes DESC, u.nombre_completo ASC';
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Viajes por ayudante (acompañante via pivote lote_ayudantes).
     * @return array<int, array<string, mixed>>
     */
    public static function porAyudante(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = 'SELECT a.id, a.nombre,
                       COUNT(DISTINCT l.id) AS viajes,
                       COALESCE(SUM(lm.m3), 0) AS m3,
                       COALESCE(SUM(lm.bultos), 0) AS bultos
                FROM lote_ayudantes la
                JOIN lotes l ON l.id = la.lote_id
                JOIN acompanantes a ON a.id = la.acompanante_id' . self::LOTE_M3 . ' ' . $where . '
                GROUP BY a.id, a.nombre
                ORDER BY viajes DESC, a.nombre ASC';
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Visitas por destino (provincia · localidad): nº de viajes y m³ a ese destino.
     * @return array<int, array<string, mixed>>
     */
    public static function porDestino(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = "SELECT COALESCE(NULLIF(o.dest_provincia, ''), '(sin provincia)') AS provincia,
                       COALESCE(NULLIF(o.dest_localidad, ''), '') AS localidad,
                       COUNT(DISTINCT l.id) AS viajes,
                       COALESCE(SUM(p.m3), 0) AS m3,
                       COUNT(DISTINCT p.id) AS bultos
                FROM lotes l
                JOIN transiciones t ON t.lote_id = l.id
                JOIN productos p ON p.id = t.producto_id
                JOIN ordenes o ON o.id = p.orden_id " . $where . "
                GROUP BY provincia, localidad
                ORDER BY viajes DESC, m3 DESC";
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Detalle: una fila por viaje, con vehículo, conductor, ayudantes (snapshot),
     * m³, bultos, destinos y categorías. Para drill-down y export.
     * @return array<int, array<string, mixed>>
     */
    public static function viajes(string $desde, string $hasta, array $tipos = self::TIPOS_VIAJE): array
    {
        $params = [];
        $where = self::baseWhere($desde, $hasta, $tipos, $params);
        $sql = "SELECT l.id, l.tipo, " . self::FECHA . " AS fecha,
                       COALESCE(v.nombre, l.vehiculo, '') AS vehiculo,
                       COALESCE(u.nombre_completo, l.chofer, '') AS conductor,
                       l.ayudantes,
                       COUNT(DISTINCT p.id) AS bultos,
                       COALESCE(SUM(p.m3), 0) AS m3,
                       GROUP_CONCAT(DISTINCT NULLIF(TRIM(CONCAT_WS(' · ', o.dest_localidad, o.dest_provincia)), '') ORDER BY o.dest_provincia SEPARATOR ' / ') AS destinos,
                       GROUP_CONCAT(DISTINCT cat.nombre ORDER BY cat.nombre SEPARATOR ', ') AS categorias
                FROM lotes l
                LEFT JOIN vehiculos v ON v.id = l.vehiculo_id
                LEFT JOIN usuarios u ON u.id = l.transportista_id
                LEFT JOIN transiciones t ON t.lote_id = l.id
                LEFT JOIN productos p ON p.id = t.producto_id
                LEFT JOIN ordenes o ON o.id = p.orden_id
                LEFT JOIN cargas cg ON cg.id = o.carga_id
                LEFT JOIN categorias cat ON cat.id = cg.categoria_id " . $where . "
                GROUP BY l.id, l.tipo, fecha, vehiculo, conductor, l.ayudantes
                ORDER BY fecha DESC, l.id DESC";
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
