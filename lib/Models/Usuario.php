<?php
declare(strict_types=1);

namespace Trazock\Models;

use InvalidArgumentException;
use Trazock\DB;

final class Usuario
{
    /** @var string[] */
    public const ROLES_VALIDOS = ['admin', 'gestor', 'operador', 'transportista'];

    /**
     * Find an active user by username. Returns null if not found or inactive.
     * This is the method Auth::login uses — it enforces activo=1 at the SQL level.
     *
     * @return array<string, mixed>|null
     */
    public static function findByUsuarioActivo(string $usuario): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, usuario, password_hash, nombre_completo, rol, activo
             FROM usuarios
             WHERE usuario = :usuario AND activo = 1
             LIMIT 1'
        );
        $stmt->execute([':usuario' => $usuario]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Listado completo para el ABM: activos primero, luego por usuario.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, usuario, nombre_completo, rol, activo, created_at
             FROM usuarios
             ORDER BY activo DESC, usuario ASC'
        )->fetchAll();
    }

    /**
     * Transportistas activos (para dropdown de SALIDA_REPARTO).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function transportistasActivos(): array
    {
        return DB::getInstance()->query(
            "SELECT id, nombre_completo FROM usuarios
             WHERE rol = 'transportista' AND activo = 1
             ORDER BY nombre_completo ASC"
        )->fetchAll();
    }

    /**
     * Verdadero si el usuario existe, está activo y tiene el rol indicado
     * (para validar integridad referencial, p. ej. transportista_id de un lote).
     */
    public static function existeActivoConRol(int $id, string $rol): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM usuarios WHERE id = :id AND rol = :rol AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':rol' => $rol]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Find a user by ID (active or inactive). Does NOT return password_hash.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, usuario, nombre_completo, rol, activo, created_at
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Check whether a username already exists (any status).
     */
    public static function existsByUsuario(string $usuario): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM usuarios WHERE usuario = :usuario LIMIT 1'
        );
        $stmt->execute([':usuario' => $usuario]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Create a new user. Password is hashed internally — pass the plain password.
     *
     * @throws InvalidArgumentException if $rol is not in ROLES_VALIDOS
     * @return int New user ID (lastInsertId)
     */
    public static function crear(
        string $usuario,
        string $nombreCompleto,
        string $passwordPlain,
        string $rol
    ): int {
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException(
                "Rol inválido: '{$rol}'. Valores permitidos: " . implode(', ', self::ROLES_VALIDOS)
            );
        }

        $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);

        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO usuarios (usuario, password_hash, nombre_completo, rol, activo)
             VALUES (:usuario, :hash, :nombre, :rol, 1)'
        );
        $stmt->execute([
            ':usuario' => $usuario,
            ':hash'    => $hash,
            ':nombre'  => $nombreCompleto,
            ':rol'     => $rol,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Actualiza datos básicos (no la password). Valida el rol.
     *
     * @throws InvalidArgumentException si $rol no es válido.
     */
    public static function actualizar(int $id, string $nombreCompleto, string $rol): void
    {
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException("Rol inválido: '{$rol}'.");
        }
        $stmt = DB::getInstance()->prepare(
            'UPDATE usuarios SET nombre_completo = :nombre, rol = :rol WHERE id = :id'
        );
        $stmt->execute([':nombre' => $nombreCompleto, ':rol' => $rol, ':id' => $id]);
    }

    /** Cambia la password (recibe el plano, hashea internamente). */
    public static function cambiarPassword(int $id, string $passwordPlain): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE usuarios SET password_hash = :hash WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => password_hash($passwordPlain, PASSWORD_DEFAULT),
            ':id'   => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE usuarios SET activo = 1 - activo WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
