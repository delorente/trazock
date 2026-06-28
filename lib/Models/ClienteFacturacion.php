<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Configuración de facturación por cliente (marca/proveedor): unidad de cobro
 * (m³/bulto/peso), si cobra por destino o precio único, y los precios CON
 * VIGENCIA por fecha (historial). El precio aplicado a una hoja de ruta es el
 * vigente a su fecha; cambiar el precio no altera lo pasado.
 *
 * En `cliente_precio`, provincia = '' representa el precio único (todos los destinos).
 */
final class ClienteFacturacion
{
    public const UNIDADES = ['m3' => 'm³', 'bulto' => 'Bulto', 'peso' => 'Peso (kg)'];

    /** Config de un proveedor (con defaults si no existe). @return array<string, mixed> */
    public static function get(int $proveedorId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT proveedor_id, unidad, por_destino, activo FROM cliente_facturacion WHERE proveedor_id = :p LIMIT 1');
        $stmt->execute([':p' => $proveedorId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['proveedor_id' => $proveedorId, 'unidad' => 'm3', 'por_destino' => 1, 'activo' => 0];
        }
        return $row;
    }

    public static function guardar(int $proveedorId, string $unidad, bool $porDestino): void
    {
        if (!isset(self::UNIDADES[$unidad])) { $unidad = 'm3'; }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO cliente_facturacion (proveedor_id, unidad, por_destino, activo)
             VALUES (:p, :u, :pd, 1)
             ON DUPLICATE KEY UPDATE unidad = VALUES(unidad), por_destino = VALUES(por_destino), activo = 1'
        );
        $stmt->execute([':p' => $proveedorId, ':u' => $unidad, ':pd' => $porDestino ? 1 : 0]);
    }

    // -------------------------------------------------------------------------
    // Precios con vigencia
    // -------------------------------------------------------------------------

    /**
     * Historial de precios de un proveedor (provincia '' = único).
     * @return array<int, array<string, mixed>>
     */
    public static function precios(int $proveedorId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, provincia, precio, vigente_desde FROM cliente_precio
             WHERE proveedor_id = :p ORDER BY provincia ASC, vigente_desde DESC'
        );
        $stmt->execute([':p' => $proveedorId]);
        return $stmt->fetchAll();
    }

    public static function agregarPrecio(int $proveedorId, string $provincia, float $precio, string $vigenteDesde): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigenteDesde)) { $vigenteDesde = date('Y-m-d'); }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO cliente_precio (proveedor_id, provincia, precio, vigente_desde)
             VALUES (:p, :prov, :pre, :vd)
             ON DUPLICATE KEY UPDATE precio = VALUES(precio)'
        );
        $stmt->execute([
            ':p' => $proveedorId, ':prov' => trim($provincia),
            ':pre' => number_format(max(0, $precio), 2, '.', ''), ':vd' => $vigenteDesde,
        ]);
    }

    public static function eliminarPrecio(int $id, int $proveedorId): void
    {
        $stmt = DB::getInstance()->prepare('DELETE FROM cliente_precio WHERE id = :id AND proveedor_id = :p');
        $stmt->execute([':id' => $id, ':p' => $proveedorId]);
    }

    /**
     * Configs activas (con nombre de proveedor), para el motor de rentabilidad.
     * @return array<int, array<string, mixed>>  Indexado por proveedor_id.
     */
    public static function configsActivas(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT cf.proveedor_id, cf.unidad, cf.por_destino, p.nombre
             FROM cliente_facturacion cf JOIN proveedores p ON p.id = cf.proveedor_id
             WHERE cf.activo = 1'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['proveedor_id']] = $r; }
        return $out;
    }

    /**
     * Todos los precios → [proveedor_id][provincia] = lista [ [vigente_desde, precio], ... ]
     * ordenada por fecha DESC (para elegir el vigente a una fecha dada).
     * @return array<int, array<string, array<int, array{0:string,1:float}>>>
     */
    public static function mapaPrecios(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT proveedor_id, provincia, precio, vigente_desde FROM cliente_precio
             ORDER BY proveedor_id, provincia, vigente_desde DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['proveedor_id']][(string)$r['provincia']][] = [(string)$r['vigente_desde'], (float)$r['precio']];
        }
        return $out;
    }

    /**
     * Precio vigente a una fecha dada, de una lista [ [vigente_desde, precio], ... ]
     * ordenada DESC. Devuelve 0 si no hay ninguno vigente a esa fecha.
     *
     * @param array<int, array{0:string,1:float}> $lista
     */
    public static function precioVigente(array $lista, string $fecha): float
    {
        foreach ($lista as [$desde, $precio]) {
            if ($desde <= $fecha) { return $precio; }
        }
        return 0.0;
    }
}
