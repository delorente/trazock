<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

final class Categoria
{
    /**
     * Listado completo para el ABM: activas primero (por nombre), inactivas al final.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, notas, activo, created_at
             FROM categorias
             ORDER BY activo DESC, nombre ASC'
        )->fetchAll();
    }

    /**
     * Solo activas, para dropdowns de nuevos lotes / catálogos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activas(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM categorias WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Verdadero si está activa y existe (para validación de integridad referencial).
     */
    public static function existeActiva(int $id): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM categorias WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    public static function nombreEnUso(string $nombre, ?int $exceptoId = null): bool
    {
        $sql    = 'SELECT 1 FROM categorias WHERE nombre = :nombre';
        $params = [':nombre' => $nombre];
        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptoId;
        }
        $stmt = DB::getInstance()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public static function crear(string $nombre, ?string $notas): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO categorias (nombre, notas, activo) VALUES (:nombre, :notas, 1)'
        );
        $stmt->execute([':nombre' => $nombre, ':notas' => $notas !== '' ? $notas : null]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $nombre, ?string $notas): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE categorias SET nombre = :nombre, notas = :notas WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':notas'  => $notas !== '' ? $notas : null,
            ':id'     => $id,
        ]);
    }

    /** Alterna el flag activo (soft-delete / reactivación). */
    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE categorias SET activo = 1 - activo WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
