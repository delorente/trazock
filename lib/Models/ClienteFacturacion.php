<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Configuración de facturación por cliente (marca/proveedor): unidad de cobro
 * (m³/bulto/peso), si cobra por destino o precio único, y los precios unitarios.
 */
final class ClienteFacturacion
{
    public const UNIDADES = ['m3' => 'm³', 'bulto' => 'Bulto', 'peso' => 'Peso (kg)'];

    /**
     * Config de un proveedor (con defaults si no existe).
     * @return array<string, mixed>
     */
    public static function get(int $proveedorId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM cliente_facturacion WHERE proveedor_id = :p LIMIT 1');
        $stmt->execute([':p' => $proveedorId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['proveedor_id' => $proveedorId, 'unidad' => 'm3', 'por_destino' => 1, 'precio_unico' => null, 'activo' => 0];
        }
        return $row;
    }

    /** Mapa provincia => precio para un proveedor. @return array<string, float> */
    public static function tarifasDestino(int $proveedorId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT provincia, precio FROM cliente_tarifa_destino WHERE proveedor_id = :p');
        $stmt->execute([':p' => $proveedorId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) { $out[(string)$r['provincia']] = (float)$r['precio']; }
        return $out;
    }

    public static function guardar(int $proveedorId, string $unidad, bool $porDestino, ?float $precioUnico): void
    {
        if (!isset(self::UNIDADES[$unidad])) { $unidad = 'm3'; }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO cliente_facturacion (proveedor_id, unidad, por_destino, precio_unico, activo)
             VALUES (:p, :u, :pd, :pu, 1)
             ON DUPLICATE KEY UPDATE unidad = VALUES(unidad), por_destino = VALUES(por_destino),
                                     precio_unico = VALUES(precio_unico), activo = 1'
        );
        $stmt->bindValue(':p', $proveedorId, \PDO::PARAM_INT);
        $stmt->bindValue(':u', $unidad);
        $stmt->bindValue(':pd', $porDestino ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':pu', ($precioUnico !== null) ? number_format(max(0, $precioUnico), 2, '.', '') : null,
            $precioUnico !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->execute();
    }

    public static function guardarTarifaDestino(int $proveedorId, string $provincia, float $precio): void
    {
        $provincia = trim($provincia);
        if ($provincia === '') { return; }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO cliente_tarifa_destino (proveedor_id, provincia, precio) VALUES (:p, :prov, :pre)
             ON DUPLICATE KEY UPDATE precio = VALUES(precio)'
        );
        $stmt->execute([':p' => $proveedorId, ':prov' => $provincia, ':pre' => number_format(max(0, $precio), 2, '.', '')]);
    }

    /**
     * Configs activas (con nombre de proveedor), para el motor de rentabilidad.
     * @return array<int, array<string, mixed>>  Indexado por proveedor_id.
     */
    public static function configsActivas(): array
    {
        $rows = DB::getInstance()->query(
            'SELECT cf.proveedor_id, cf.unidad, cf.por_destino, cf.precio_unico, p.nombre
             FROM cliente_facturacion cf JOIN proveedores p ON p.id = cf.proveedor_id
             WHERE cf.activo = 1'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['proveedor_id']] = $r; }
        return $out;
    }

    /** Todos los precios por destino. @return array<int, array<string, float>> [proveedor_id][provincia]=>precio */
    public static function mapaTarifasDestino(): array
    {
        $rows = DB::getInstance()->query('SELECT proveedor_id, provincia, precio FROM cliente_tarifa_destino')->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['proveedor_id']][(string)$r['provincia']] = (float)$r['precio']; }
        return $out;
    }
}
