<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Prefijo — nombre asignado al prefijo del Nº de orden (la parte anterior al
 * primer '-': 0775-123456 → 0775) y acceso público por local mediante token.
 *
 * `nombre_interno` se usa en el filtro del panel; `nombre_publico` es el que ve
 * el local en su listado por token (reseteable: regenerar invalida el anterior).
 */
final class Prefijo
{
    /** Prefijo de un Nº de orden: lo anterior al primer '-'. */
    public static function de(string $nroOrden): string
    {
        $p = explode('-', trim($nroOrden), 2)[0] ?? '';
        return trim($p);
    }

    /** @return array<int, array<string,mixed>> */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT * FROM prefijos ORDER BY activo DESC, nombre_interno'
        )->fetchAll();
    }

    /** Activos, para el filtro: [['prefijo','nombre_interno'], ...]. @return array<int,array{0:string,1:string}> */
    public static function paraFiltro(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT prefijo, nombre_interno FROM prefijos WHERE activo = 1 ORDER BY nombre_interno"
        )->fetchAll();
        return array_map(static fn($r) => [(string)$r['prefijo'], (string)$r['nombre_interno']], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM prefijos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = DB::getInstance()->prepare('SELECT * FROM prefijos WHERE token = :t AND activo = 1 LIMIT 1');
        $stmt->execute([':t' => $token]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    public static function existsByPrefijo(string $prefijo, int $exceptoId = 0): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM prefijos WHERE prefijo = :p AND id <> :id LIMIT 1');
        $stmt->execute([':p' => $prefijo, ':id' => $exceptoId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function crear(string $prefijo, string $nombreInterno, ?string $nombrePublico): int
    {
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO prefijos (prefijo, nombre_interno, nombre_publico)
             VALUES (:p, :ni, :np)'
        );
        $stmt->execute([
            ':p'  => $prefijo,
            ':ni' => $nombreInterno,
            ':np' => ($nombrePublico === null || $nombrePublico === '') ? null : $nombrePublico,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $prefijo, string $nombreInterno, ?string $nombrePublico): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE prefijos SET prefijo = :p, nombre_interno = :ni, nombre_publico = :np WHERE id = :id'
        );
        $stmt->execute([
            ':p'  => $prefijo,
            ':ni' => $nombreInterno,
            ':np' => ($nombrePublico === null || $nombrePublico === '') ? null : $nombrePublico,
            ':id' => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        DB::getInstance()->prepare('UPDATE prefijos SET activo = 1 - activo WHERE id = :id')->execute([':id' => $id]);
    }

    /** Genera (o regenera) el token; invalida el anterior. Devuelve el nuevo token. */
    public static function regenerarToken(int $id): string
    {
        $token = bin2hex(random_bytes(16)); // 32 hex
        DB::getInstance()->prepare('UPDATE prefijos SET token = :t WHERE id = :id')
            ->execute([':t' => $token, ':id' => $id]);
        return $token;
    }

    /** Quita el acceso público (token a NULL). */
    public static function quitarToken(int $id): void
    {
        DB::getInstance()->prepare('UPDATE prefijos SET token = NULL WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Prefijos presentes en órdenes con su cantidad (para sugerir altas). Excluye
     * los que ya tienen nombre cargado.
     *
     * @return array<int, array{prefijo:string, n:int}>
     */
    public static function sugeridos(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT SUBSTRING_INDEX(o.nro_orden, '-', 1) AS prefijo, COUNT(*) AS n
               FROM ordenes o
              WHERE o.nro_orden IS NOT NULL AND o.nro_orden <> ''
                AND SUBSTRING_INDEX(o.nro_orden, '-', 1) NOT IN (SELECT prefijo FROM prefijos)
              GROUP BY prefijo
              ORDER BY n DESC
              LIMIT 50"
        )->fetchAll();
        return array_map(static fn($r) => ['prefijo' => (string)$r['prefijo'], 'n' => (int)$r['n']], $rows);
    }
}
