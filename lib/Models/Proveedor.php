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
            'SELECT id, nombre, razon_social, cuit, condicion_iva, domicilio,
                    contacto, notas, activo, created_at
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

    /**
     * @param array<string, mixed> $fiscal Claves opcionales: razon_social, cuit,
     *        condicion_iva, domicilio (datos del receptor para facturar).
     */
    public static function crear(string $nombre, ?string $contacto, ?string $notas, array $fiscal = []): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO proveedores (nombre, razon_social, cuit, condicion_iva, domicilio, contacto, notas, activo)
             VALUES (:nombre, :razon_social, :cuit, :condicion_iva, :domicilio, :contacto, :notas, 1)'
        );
        $stmt->execute([
            ':nombre'        => $nombre,
            ':razon_social'  => self::n($fiscal['razon_social'] ?? null),
            ':cuit'          => self::n($fiscal['cuit'] ?? null),
            ':condicion_iva' => self::n($fiscal['condicion_iva'] ?? null),
            ':domicilio'     => self::n($fiscal['domicilio'] ?? null),
            ':contacto'      => $contacto !== '' ? $contacto : null,
            ':notas'         => $notas !== '' ? $notas : null,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fiscal Claves opcionales: razon_social, cuit,
     *        condicion_iva, domicilio.
     */
    public static function actualizar(int $id, string $nombre, ?string $contacto, ?string $notas, array $fiscal = []): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE proveedores SET nombre = :nombre, razon_social = :razon_social, cuit = :cuit,
                    condicion_iva = :condicion_iva, domicilio = :domicilio, contacto = :contacto, notas = :notas
             WHERE id = :id'
        );
        $stmt->execute([
            ':nombre'        => $nombre,
            ':razon_social'  => self::n($fiscal['razon_social'] ?? null),
            ':cuit'          => self::n($fiscal['cuit'] ?? null),
            ':condicion_iva' => self::n($fiscal['condicion_iva'] ?? null),
            ':domicilio'     => self::n($fiscal['domicilio'] ?? null),
            ':contacto'      => $contacto !== '' ? $contacto : null,
            ':notas'         => $notas !== '' ? $notas : null,
            ':id'            => $id,
        ]);
    }

    /** Normaliza string vacío/espacios a null. */
    private static function n($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v !== '' ? $v : null;
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE proveedores SET activo = 1 - activo WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
