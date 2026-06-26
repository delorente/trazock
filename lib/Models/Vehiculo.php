<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Vehículos / unidades del reparto. Catálogo simple: nombre (patente o alias) +
 * observación opcional, con soft-delete. Alimenta el desplegable de la app de
 * escaneo en la SALIDA_REPARTO.
 */
final class Vehiculo
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, observacion, activo, created_at
             FROM vehiculos
             ORDER BY activo DESC, nombre ASC'
        )->fetchAll();
    }

    /**
     * Activos para el catálogo de la app (id, nombre, observacion).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, observacion FROM vehiculos WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
    }

    public static function crear(string $nombre, ?string $observacion): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO vehiculos (nombre, observacion, activo) VALUES (:nombre, :obs, 1)'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':obs'    => $observacion !== '' ? $observacion : null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $nombre, ?string $observacion): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE vehiculos SET nombre = :nombre, observacion = :obs WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':obs'    => $observacion !== '' ? $observacion : null,
            ':id'     => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE vehiculos SET activo = 1 - activo WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
