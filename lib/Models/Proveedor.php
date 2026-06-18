<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

final class Proveedor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, contacto, notas, activo, created_at
             FROM proveedores
             ORDER BY activo DESC, nombre ASC'
        )->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function activos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM proveedores WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function existeActivo(int $id): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM proveedores WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    public static function crear(string $nombre, ?string $contacto, ?string $notas): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO proveedores (nombre, contacto, notas, activo)
             VALUES (:nombre, :contacto, :notas, 1)'
        );
        $stmt->execute([
            ':nombre'   => $nombre,
            ':contacto' => $contacto !== '' ? $contacto : null,
            ':notas'    => $notas !== '' ? $notas : null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $nombre, ?string $contacto, ?string $notas): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE proveedores SET nombre = :nombre, contacto = :contacto, notas = :notas
             WHERE id = :id'
        );
        $stmt->execute([
            ':nombre'   => $nombre,
            ':contacto' => $contacto !== '' ? $contacto : null,
            ':notas'    => $notas !== '' ? $notas : null,
            ':id'       => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE proveedores SET activo = 1 - activo WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
