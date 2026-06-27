<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Datos fijos para encabezar la factura: emisor (la Corredora), receptor
 * (a quién se factura) y la alícuota de IVA. Fila única (id = 1).
 */
final class FacturacionDatos
{
    private const CAMPOS = [
        'emisor_razon_social', 'emisor_cuit', 'emisor_iva', 'emisor_domicilio',
        'receptor_razon_social', 'receptor_cuit', 'receptor_iva', 'receptor_domicilio',
    ];

    /**
     * Devuelve la fila de datos (con valores por defecto si aún no existe).
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $row = DB::getInstance()->query(
            'SELECT * FROM facturacion_datos WHERE id = 1 LIMIT 1'
        )->fetch();

        if ($row === false) {
            $base = ['id' => 1, 'iva_alicuota' => 21.00];
            foreach (self::CAMPOS as $c) { $base[$c] = null; }
            return $base;
        }
        return $row;
    }

    /**
     * Guarda (upsert) los datos de facturación.
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
        $iva = (float)($d['iva_alicuota'] ?? 21);
        if ($iva < 0)   { $iva = 0.0; }
        if ($iva > 100) { $iva = 100.0; }

        $cols = array_merge(['id'], self::CAMPOS, ['iva_alicuota']);
        $place = array_map(static fn($c) => ':' . $c, $cols);
        $updates = array_map(static fn($c) => "$c = VALUES($c)", array_merge(self::CAMPOS, ['iva_alicuota']));

        $sql = 'INSERT INTO facturacion_datos (' . implode(', ', $cols) . ')
                VALUES (' . implode(', ', $place) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        $params = [':id' => 1, ':iva_alicuota' => $iva];
        foreach (self::CAMPOS as $c) { $params[':' . $c] = $vals[$c]; }

        DB::getInstance()->prepare($sql)->execute($params);
    }
}
