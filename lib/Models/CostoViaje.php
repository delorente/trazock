<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Costos asociados a un viaje (lote): combustible, permiso, viático, otro.
 */
final class CostoViaje
{
    public const TIPOS = [
        'combustible' => 'Combustible',
        'permiso'     => 'Permiso',
        'viatico'     => 'Viático',
        'otro'        => 'Otro',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function porLote(int $loteId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.id, c.tipo, c.importe, c.fecha, c.observacion, c.created_at,
                    u.nombre_completo AS creador
             FROM costos_viaje c
             LEFT JOIN usuarios u ON u.id = c.creado_por
             WHERE c.lote_id = :l
             ORDER BY c.fecha DESC, c.id DESC'
        );
        $stmt->execute([':l' => $loteId]);
        return $stmt->fetchAll();
    }

    public static function totalLote(int $loteId): float
    {
        $stmt = DB::getInstance()->prepare('SELECT COALESCE(SUM(importe),0) FROM costos_viaje WHERE lote_id = :l');
        $stmt->execute([':l' => $loteId]);
        return (float)$stmt->fetchColumn();
    }

    public static function crear(int $loteId, string $tipo, float $importe, ?string $fecha, ?string $obs, ?int $creadoPor): int
    {
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'otro'; }
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO costos_viaje (lote_id, tipo, importe, fecha, observacion, creado_por)
             VALUES (:l, :tipo, :imp, :fecha, :obs, :por)'
        );
        $stmt->bindValue(':l', $loteId, \PDO::PARAM_INT);
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
        $stmt = DB::getInstance()->prepare('DELETE FROM costos_viaje WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Lote al que pertenece un costo (para validar el redirect/borrado). */
    public static function loteDe(int $id): ?int
    {
        $stmt = DB::getInstance()->prepare('SELECT lote_id FROM costos_viaje WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * Totales por tipo en un período (filtra por la fecha del costo).
     * @return array<string, float>
     */
    public static function resumenPorTipo(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT tipo, COALESCE(SUM(importe),0) AS total
             FROM costos_viaje WHERE fecha BETWEEN :d AND :h GROUP BY tipo'
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[(string)$r['tipo']] = (float)$r['total']; }
        return $out;
    }

    public static function total(string $desde, string $hasta): float
    {
        $stmt = DB::getInstance()->prepare('SELECT COALESCE(SUM(importe),0) FROM costos_viaje WHERE fecha BETWEEN :d AND :h');
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Detalle de costos de viaje en un período (con datos del lote).
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.id, c.lote_id, c.tipo, c.importe, c.fecha, c.observacion,
                    l.tipo AS lote_tipo, l.created_at AS lote_creado
             FROM costos_viaje c JOIN lotes l ON l.id = c.lote_id
             WHERE c.fecha BETWEEN :d AND :h
             ORDER BY c.fecha DESC, c.id DESC'
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }
}
