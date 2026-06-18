<?php
declare(strict_types=1);

namespace Trazock\Models;

use InvalidArgumentException;
use Trazock\DB;

final class Motivo
{
    /** @var string[] Tipos válidos según la spec. */
    public const TIPOS_VALIDOS = ['reingreso', 'devolucion', 'baja'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return DB::getInstance()->query(
            'SELECT id, nombre, tipo, editable_libre, activo
             FROM motivos
             ORDER BY activo DESC, tipo ASC, nombre ASC'
        )->fetchAll();
    }

    /**
     * Activos que aplican a un tipo dado (para dropdowns de configuración de lote).
     * tipo es un SET, así que se usa FIND_IN_SET.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activosPorTipo(string $tipo): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, nombre, editable_libre
             FROM motivos
             WHERE FIND_IN_SET(:tipo, tipo) AND activo = 1
             ORDER BY nombre ASC'
        );
        $stmt->execute([':tipo' => $tipo]);
        return $stmt->fetchAll();
    }

    /**
     * Todos los activos agrupados por tipo. Un motivo con varios tipos aparece en cada grupo.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function activosAgrupados(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT id, nombre, tipo, editable_libre
             FROM motivos WHERE activo = 1
             ORDER BY nombre ASC'
        )->fetchAll();

        $out = ['reingreso' => [], 'devolucion' => [], 'baja' => []];
        foreach ($rows as $r) {
            foreach (explode(',', (string)$r['tipo']) as $t) {
                if (isset($out[$t])) {
                    $out[$t][] = $r;
                }
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM motivos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function existeActivo(int $id): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT 1 FROM motivos WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Normaliza y valida una lista de tipos; devuelve el string para el SET.
     *
     * @param string[] $tipos
     */
    private static function tiposToSet(array $tipos): string
    {
        $limpios = array_values(array_unique(array_filter($tipos, static fn($t) => in_array($t, self::TIPOS_VALIDOS, true))));
        if ($limpios === []) {
            throw new InvalidArgumentException('Un motivo requiere al menos un tipo válido.');
        }
        return implode(',', $limpios);
    }

    /**
     * @param string[] $tipos
     */
    public static function crear(string $nombre, array $tipos, bool $editableLibre): int
    {
        $set  = self::tiposToSet($tipos);
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO motivos (nombre, tipo, editable_libre, activo)
             VALUES (:nombre, :tipo, :editable, 1)'
        );
        $stmt->execute([':nombre' => $nombre, ':tipo' => $set, ':editable' => (int)$editableLibre]);
        return (int)$db->lastInsertId();
    }

    /**
     * @param string[] $tipos
     */
    public static function actualizar(int $id, string $nombre, array $tipos, bool $editableLibre): void
    {
        $set  = self::tiposToSet($tipos);
        $stmt = DB::getInstance()->prepare(
            'UPDATE motivos SET nombre = :nombre, tipo = :tipo, editable_libre = :editable WHERE id = :id'
        );
        $stmt->execute([':nombre' => $nombre, ':tipo' => $set, ':editable' => (int)$editableLibre, ':id' => $id]);
    }

    public static function toggleActivo(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE motivos SET activo = 1 - activo WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
