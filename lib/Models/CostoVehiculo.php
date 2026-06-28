<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Costos asociados a un vehículo (no a un viaje): mantenimiento, reparación, otro.
 */
final class CostoVehiculo
{
    public const TIPOS = [
        'mantenimiento' => 'Mantenimiento',
        'reparacion'    => 'Reparación',
        'otro'          => 'Otro',
    ];

    /**
     * Lista costos de vehículo en un período (con nombre de vehículo y creador).
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.id, c.vehiculo_id, c.tipo, c.importe, c.fecha, c.observacion, c.created_at,
                    v.nombre AS vehiculo, u.nombre_completo AS creador
             FROM costos_vehiculo c
             JOIN vehiculos v ON v.id = c.vehiculo_id
             LEFT JOIN usuarios u ON u.id = c.creado_por
             WHERE c.fecha BETWEEN :d AND :h
             ORDER BY c.fecha DESC, c.id DESC'
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    public static function crear(int $vehiculoId, string $tipo, float $importe, ?string $fecha, ?string $obs, ?int $creadoPor): int
    {
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'otro'; }
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO costos_vehiculo (vehiculo_id, tipo, importe, fecha, observacion, creado_por)
             VALUES (:v, :tipo, :imp, :fecha, :obs, :por)'
        );
        $stmt->bindValue(':v', $vehiculoId, \PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':imp', number_format(max(0, $importe), 2, '.', ''));
        $stmt->bindValue(':fecha', ($fecha !== null && $fecha !== '') ? $fecha : null, ($fecha !== null && $fecha !== '') ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':obs', ($obs !== null && $obs !== '') ? $obs : null, ($obs !== null && $obs !== '') ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':por', $creadoPor, $creadoPor === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    public static function eliminar(int $id): void
    {
        $stmt = DB::getInstance()->prepare('DELETE FROM costos_vehiculo WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function total(string $desde, string $hasta): float
    {
        $stmt = DB::getInstance()->prepare('SELECT COALESCE(SUM(importe),0) FROM costos_vehiculo WHERE fecha BETWEEN :d AND :h');
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Totales por vehículo en un período.
     * @return array<int, array<string, mixed>>
     */
    public static function porVehiculo(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT v.id, v.nombre, COALESCE(SUM(c.importe),0) AS total, COUNT(*) AS items
             FROM costos_vehiculo c JOIN vehiculos v ON v.id = c.vehiculo_id
             WHERE c.fecha BETWEEN :d AND :h
             GROUP BY v.id, v.nombre ORDER BY total DESC'
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }
}
