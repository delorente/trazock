<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Datos fiscales del emisor (la Corredora) que encabezan la factura, más la
 * alícuota de IVA general. Fila única (id = 1). Los secretos/IDs de AFIP
 * (certificado, punto de venta) viven en config.php, no acá.
 */
final class AfipEmisor
{
    private const CAMPOS = [
        'razon_social', 'cuit', 'condicion_iva', 'domicilio', 'iibb', 'inicio_actividades',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $row = DB::getInstance()->query(
            'SELECT * FROM afip_emisor WHERE id = 1 LIMIT 1'
        )->fetch();

        if ($row === false) {
            $base = ['id' => 1, 'iva_alicuota' => 21.00, 'condicion_iva' => 'Responsable Inscripto'];
            foreach (self::CAMPOS as $c) { $base[$c] = $base[$c] ?? null; }
            return $base;
        }
        return $row;
    }

    /**
     * Guarda (upsert) los datos del emisor.
     *
     * @param array<string, mixed> $d
     */
    public static function guardar(array $d): void
    {
        $vals = [];
        foreach (self::CAMPOS as $c) {
            $v = trim((string)($d[$c] ?? ''));
            $vals[$c] = $v !== '' ? $v : null;
        }
        // inicio_actividades debe ser fecha válida o null.
        if ($vals['inicio_actividades'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vals['inicio_actividades'])) {
            $vals['inicio_actividades'] = null;
        }
        $iva = (float)($d['iva_alicuota'] ?? 21);
        if ($iva < 0)   { $iva = 0.0; }
        if ($iva > 100) { $iva = 100.0; }

        $cols    = array_merge(['id'], self::CAMPOS, ['iva_alicuota']);
        $place   = array_map(static fn($c) => ':' . $c, $cols);
        $updates = array_map(static fn($c) => "$c = VALUES($c)", array_merge(self::CAMPOS, ['iva_alicuota']));

        $sql = 'INSERT INTO afip_emisor (' . implode(', ', $cols) . ')
                VALUES (' . implode(', ', $place) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        $params = [':id' => 1, ':iva_alicuota' => $iva];
        foreach (self::CAMPOS as $c) { $params[':' . $c] = $vals[$c]; }

        DB::getInstance()->prepare($sql)->execute($params);
    }
}
