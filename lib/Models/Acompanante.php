<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Acompañantes (ayudantes) que salen al reparto. Catálogo simple: nombre +
 * observación opcional, con soft-delete. Alimenta el desplegable de la app de
 * escaneo en la SALIDA_REPARTO.
 */
final class Acompanante
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, observacion, es_chofer_ld, es_chofer_cd, es_ayudante, activo, created_at
             FROM acompanantes
             ORDER BY activo DESC, nombre ASC'
        )->fetchAll();
    }

    /**
     * Activos para el catálogo de la app (incluye los flags de rol para filtrar
     * los desplegables: LD para la carga, CD/ayudante para el reparto).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activos(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT id, nombre, observacion, es_chofer_ld, es_chofer_cd, es_ayudante
             FROM acompanantes WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'           => (int)$r['id'],
            'nombre'       => (string)$r['nombre'],
            'observacion'  => $r['observacion'],
            'es_chofer_ld' => (int)$r['es_chofer_ld'],
            'es_chofer_cd' => (int)$r['es_chofer_cd'],
            'es_ayudante'  => (int)$r['es_ayudante'],
        ], $rows);
    }

    /**
     * Activos con un rol dado ('ld'|'cd'|'ayudante'), para los desplegables.
     *
     * @return array<int, array{id:int, nombre:string}>
     */
    public static function porRol(string $rol): array
    {
        $col = ['ld' => 'es_chofer_ld', 'cd' => 'es_chofer_cd', 'ayudante' => 'es_ayudante'][$rol] ?? null;
        if ($col === null) { return []; }
        $rows = DB::getInstance()->query(
            "SELECT id, nombre FROM acompanantes WHERE activo = 1 AND {$col} = 1 ORDER BY nombre ASC"
        )->fetchAll();
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']], $rows);
    }

    /**
     * Devuelve [id => nombre] de los acompañantes activos cuyos ids estén en $ids.
     * Ignora ids inexistentes/inactivos. Preserva el orden de $ids.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public static function activosPorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) { return []; }
        $ph = [];
        $params = [];
        foreach ($ids as $i => $id) { $k = ':a' . $i; $ph[] = $k; $params[$k] = $id; }
        $stmt = DB::getInstance()->prepare(
            'SELECT id, nombre FROM acompanantes WHERE activo = 1 AND id IN (' . implode(',', $ph) . ')'
        );
        $stmt->execute($params);
        $byId = [];
        foreach ($stmt->fetchAll() as $r) { $byId[(int)$r['id']] = (string)$r['nombre']; }
        // Reordenar según $ids (y descartar los no activos).
        $out = [];
        foreach ($ids as $id) { if (isset($byId[$id])) { $out[$id] = $byId[$id]; } }
        return $out;
    }

    /**
     * @return array<string, mixed>|null  Empleado activo (id, nombre) o null.
     */
    public static function findActivo(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, nombre FROM acompanantes WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function crear(string $nombre, ?string $observacion, bool $ld = false, bool $cd = false, bool $ay = false): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO acompanantes (nombre, observacion, es_chofer_ld, es_chofer_cd, es_ayudante, activo)
             VALUES (:nombre, :obs, :ld, :cd, :ay, 1)'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':obs'    => $observacion !== '' ? $observacion : null,
            ':ld'     => $ld ? 1 : 0,
            ':cd'     => $cd ? 1 : 0,
            ':ay'     => $ay ? 1 : 0,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $nombre, ?string $observacion, bool $ld = false, bool $cd = false, bool $ay = false): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE acompanantes SET nombre = :nombre, observacion = :obs,
                    es_chofer_ld = :ld, es_chofer_cd = :cd, es_ayudante = :ay WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':obs'    => $observacion !== '' ? $observacion : null,
            ':ld'     => $ld ? 1 : 0,
            ':cd'     => $cd ? 1 : 0,
            ':ay'     => $ay ? 1 : 0,
            ':id'     => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE acompanantes SET activo = 1 - activo WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
