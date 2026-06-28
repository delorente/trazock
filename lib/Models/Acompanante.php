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
            'SELECT id, nombre, observacion, activo, created_at
             FROM acompanantes
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
            'SELECT id, nombre, observacion FROM acompanantes WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
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

    public static function crear(string $nombre, ?string $observacion): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO acompanantes (nombre, observacion, activo) VALUES (:nombre, :obs, 1)'
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
            'UPDATE acompanantes SET nombre = :nombre, observacion = :obs WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':obs'    => $observacion !== '' ? $observacion : null,
            ':id'     => $id,
        ]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE acompanantes SET activo = 1 - activo WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
