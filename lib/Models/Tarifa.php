<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Tarifario: precio por m³ según provincia de destino + tipo de venta
 * (online/local pueden diferir). Lo usa el reporte de Facturación para calcular
 * el importe de cada destino (m³ × precio).
 */
final class Tarifa
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return DB::getInstance()->query(
            'SELECT id, provincia, tipo_venta, precio_m3 FROM tarifas ORDER BY provincia ASC, tipo_venta ASC'
        )->fetchAll();
    }

    /**
     * Mapa "provincia|tipo" => precio_m3 (float), para aplicar al reporte.
     *
     * @return array<string, float>
     */
    public static function mapa(): array
    {
        $out = [];
        foreach (self::todas() as $r) {
            $out[$r['provincia'] . '|' . $r['tipo_venta']] = (float)$r['precio_m3'];
        }
        return $out;
    }

    /**
     * Precio para una (provincia, tipo). 0 si no hay tarifa cargada.
     */
    public static function precio(string $provincia, string $tipo): float
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT precio_m3 FROM tarifas WHERE provincia = :p AND tipo_venta = :t LIMIT 1'
        );
        $stmt->execute([':p' => $provincia, ':t' => $tipo]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : (float)$v;
    }

    /**
     * Upsert de una tarifa por (provincia, tipo). Precio < 0 se normaliza a 0.
     */
    public static function guardar(string $provincia, string $tipo, float $precio): void
    {
        $provincia = trim($provincia);
        if ($provincia === '' || !in_array($tipo, ['online', 'local'], true)) { return; }
        if ($precio < 0) { $precio = 0.0; }
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO tarifas (provincia, tipo_venta, precio_m3) VALUES (:p, :t, :m)
             ON DUPLICATE KEY UPDATE precio_m3 = VALUES(precio_m3)'
        );
        $stmt->execute([':p' => $provincia, ':t' => $tipo, ':m' => $precio]);
    }
}
