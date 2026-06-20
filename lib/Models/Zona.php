<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

/**
 * Zona — agrupa localidades de destino (provincia + ciudad) para el reparto.
 *
 * Al abrir un lote de SALIDA_REPARTO el operador elige una zona; el escáner valida
 * (offline, contra los catálogos cacheados) que cada QR pertenezca a ella. Una
 * localidad con `ciudad` NULL/'' significa "toda la provincia".
 */
final class Zona
{
    /**
     * Listado para el ABM: activas primero, con la cantidad de localidades.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return DB::getInstance()->query(
            'SELECT z.id, z.nombre, z.activo, z.created_at,
                    (SELECT COUNT(*) FROM zona_localidades l WHERE l.zona_id = z.id) AS cant_localidades
             FROM zonas z
             ORDER BY z.activo DESC, z.nombre ASC'
        )->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM zonas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Localidades de una zona.
     *
     * @return array<int, array{provincia:string, ciudad:string}>
     */
    public static function localidades(int $zonaId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT provincia, ciudad FROM zona_localidades
             WHERE zona_id = :id ORDER BY provincia, ciudad'
        );
        $stmt->execute([':id' => $zonaId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['provincia' => (string)$r['provincia'], 'ciudad' => (string)($r['ciudad'] ?? '')];
        }
        return $out;
    }

    /**
     * Zonas activas con sus localidades, para el paquete de catálogos del escáner.
     *
     * @return array<int, array{id:int, nombre:string, localidades:array<int, array{provincia:string, ciudad:string}>}>
     */
    public static function activasConLocalidades(): array
    {
        $zonas = DB::getInstance()->query(
            'SELECT id, nombre FROM zonas WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
        $out = [];
        foreach ($zonas as $z) {
            $out[] = [
                'id'          => (int)$z['id'],
                'nombre'      => (string)$z['nombre'],
                'localidades' => self::localidades((int)$z['id']),
            ];
        }
        return $out;
    }

    public static function nombreEnUso(string $nombre, ?int $exceptoId = null): bool
    {
        $sql    = 'SELECT 1 FROM zonas WHERE nombre = :nombre';
        $params = [':nombre' => $nombre];
        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptoId;
        }
        $stmt = DB::getInstance()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public static function crear(string $nombre): int
    {
        $db = DB::getInstance();
        $stmt = $db->prepare('INSERT INTO zonas (nombre, activo) VALUES (:nombre, 1)');
        $stmt->execute([':nombre' => $nombre]);
        return (int)$db->lastInsertId();
    }

    public static function actualizar(int $id, string $nombre): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE zonas SET nombre = :nombre WHERE id = :id');
        $stmt->execute([':nombre' => $nombre, ':id' => $id]);
    }

    /** Alterna el flag activo (soft-delete / reactivación). */
    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare('UPDATE zonas SET activo = 1 - activo WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Reemplaza por completo las localidades de una zona (borra y reinserta).
     *
     * @param array<int, array{provincia:string, ciudad:string}> $localidades
     */
    public static function setLocalidades(int $zonaId, array $localidades): void
    {
        $db = DB::getInstance();
        $del = $db->prepare('DELETE FROM zona_localidades WHERE zona_id = :id');
        $del->execute([':id' => $zonaId]);

        $ins = $db->prepare(
            'INSERT INTO zona_localidades (zona_id, provincia, ciudad) VALUES (:z, :p, :c)'
        );
        foreach ($localidades as $l) {
            $prov = trim((string)($l['provincia'] ?? ''));
            if ($prov === '') { continue; } // sin provincia no es una localidad válida
            $ciudad = trim((string)($l['ciudad'] ?? ''));
            $ins->bindValue(':z', $zonaId, PDO::PARAM_INT);
            $ins->bindValue(':p', $prov);
            $ins->bindValue(':c', $ciudad === '' ? null : $ciudad, $ciudad === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $ins->execute();
        }
    }
}
