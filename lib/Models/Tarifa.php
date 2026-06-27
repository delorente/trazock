<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Tarifario: precio por m³ según provincia de destino. Lo usa el reporte de
 * Facturación para calcular el importe de cada destino (m³ × precio).
 */
final class Tarifa
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return DB::getInstance()->query(
            'SELECT id, provincia, precio_m3 FROM tarifas ORDER BY provincia ASC'
        )->fetchAll();
    }

    /**
     * Mapa provincia => precio_m3 (float), para aplicar al reporte.
     *
     * @return array<string, float>
     */
    public static function mapa(): array
    {
        $out = [];
        foreach (self::todas() as $r) {
            $out[(string)$r['provincia']] = (float)$r['precio_m3'];
        }
        return $out;
    }

    /**
     * Upsert de una tarifa por provincia. Precio < 0 se normaliza a 0.
     */
    public static function guardar(string $provincia, float $precio): void
    {
        $provincia = trim($provincia);
        if ($provincia === '') { return; }
        if ($precio < 0) { $precio = 0.0; }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO tarifas (provincia, precio_m3) VALUES (:p, :m)
             ON DUPLICATE KEY UPDATE precio_m3 = VALUES(precio_m3)'
        );
        $stmt->execute([':p' => $provincia, ':m' => $precio]);
    }
}
