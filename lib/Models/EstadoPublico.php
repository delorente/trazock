<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * EstadoPublico — traducción editable de cada estado interno a un texto "público"
 * (título + descripción) que ve el comprador en la landing de seguimiento.
 *
 * La tabla `estados_publicos` tiene una fila por cada valor del enum Estado, sembrada
 * por la migración 004. No se crean ni se borran filas desde la app: solo se editan.
 */
final class EstadoPublico
{
    /**
     * Todas las filas para el editor del panel, en orden de camino feliz primero
     * (orden ASC) y el resto por estado.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return DB::getInstance()->query(
            'SELECT estado, titulo, descripcion, visible, orden, updated_at
             FROM estados_publicos
             ORDER BY (orden = 0), orden ASC, estado ASC'
        )->fetchAll();
    }

    /**
     * Mapa estado => fila, para resolver el texto público de un producto sin
     * hacer N consultas.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mapa(): array
    {
        $out = [];
        foreach (self::todas() as $row) {
            $out[(string)$row['estado']] = $row;
        }
        return $out;
    }

    /**
     * Pasos del camino feliz (visibles y con orden > 0), ordenados, para dibujar
     * la línea de tiempo de la landing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pasosVisibles(): array
    {
        return DB::getInstance()->query(
            'SELECT estado, titulo, descripcion, orden
             FROM estados_publicos
             WHERE visible = 1 AND orden > 0
             ORDER BY orden ASC'
        )->fetchAll();
    }

    public static function actualizar(string $estado, string $titulo, string $descripcion, bool $visible, int $orden): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE estados_publicos
             SET titulo = :titulo, descripcion = :descripcion, visible = :visible, orden = :orden
             WHERE estado = :estado'
        );
        $stmt->execute([
            ':titulo'      => $titulo,
            ':descripcion' => $descripcion,
            ':visible'     => $visible ? 1 : 0,
            ':orden'       => $orden,
            ':estado'      => $estado,
        ]);
    }
}
