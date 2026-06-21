<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

/**
 * Carga — el "lote de ingreso" por OCR: una captura/confirmación de hojas resumen.
 *
 * Mientras se revisa, los datos extraídos viven como JSON editable en
 * `datos_extraidos`. Al confirmar, el ProcesadorCarga los materializa en
 * `ordenes` + `productos`, y la carga pasa a estado 'confirmada'.
 */
final class Carga
{
    /** Crea una carga en borrador (con su categoría/línea de producto) y devuelve su id. */
    public static function crear(int $usuarioId, ?int $categoriaId = null): int
    {
        $db = DB::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO cargas (usuario_id, categoria_id, fecha, estado)
             VALUES (:u, :cat, CURDATE(), 'borrador')"
        );
        $stmt->bindValue(':u', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':cat', $categoriaId, $categoriaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.*, u.nombre_completo AS usuario_nombre
             FROM cargas c
             LEFT JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Guarda el JSON de extracción editado durante la revisión. */
    public static function guardarDatos(int $id, string $json): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE cargas SET datos_extraidos = :json WHERE id = :id'
        );
        $stmt->execute([':json' => $json, ':id' => $id]);
    }

    /** Marca la carga como confirmada (al materializar las órdenes). */
    public static function marcarConfirmada(int $id, int $cantidadOrdenes): void
    {
        $stmt = DB::getInstance()->prepare(
            "UPDATE cargas
             SET estado = 'confirmada', confirmada_at = NOW(), cantidad_ordenes = :c
             WHERE id = :id"
        );
        $stmt->execute([':c' => $cantidadOrdenes, ':id' => $id]);
    }

    /**
     * Listado de cargas recientes para el panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recientes(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = DB::getInstance()->prepare(
            "SELECT c.id, c.fecha, c.estado, c.cantidad_ordenes, c.confirmada_at, c.created_at,
                    u.nombre_completo AS usuario_nombre
             FROM cargas c
             LEFT JOIN usuarios u ON u.id = c.usuario_id
             ORDER BY c.id DESC LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
