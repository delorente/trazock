<?php
declare(strict_types=1);

namespace Trazock;

use Trazock\Models\AfipEmisor;
use Trazock\Models\Orden;
use Trazock\Models\Tarifa;

/**
 * Arma las "facturas" calculadas (una por marca/proveedor × tipo de venta) a
 * partir de los filtros del reporte: aplica el tarifario (provincia × tipo) a los
 * m³ de cada destino, calcula subtotal/IVA/total y determina el tipo de
 * comprobante (A/B). Lo consumen el export Excel y la pre-factura imprimible.
 */
final class Facturacion
{
    public const TIPO_LABEL = ['online' => 'Online', 'local' => 'Local', '' => 'Sin tipo'];

    /**
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>  Lista ordenada de facturas calculadas.
     */
    public static function calcular(array $filtros): array
    {
        $brutas  = Orden::facturacion($filtros);
        $detalle = Orden::facturacionDetalle($filtros);
        $tarifas = Tarifa::mapa();
        $emisor  = AfipEmisor::get();
        $iva     = (float)($emisor['iva_alicuota'] ?? 21);

        $out = [];
        foreach ($brutas as $key => $f) {
            $tipo     = (string)$f['tipo'];
            $receptor = $f['proveedor'] ?? null;
            $sinMarca = $receptor === null;

            $destinos = [];
            $subtotal = 0.0;
            foreach ($f['destinos'] as $d) {
                $prov   = (string)$d['provincia'];
                $m3     = (float)$d['m3'];
                $tarifa = (float)($tarifas[$prov . '|' . $tipo] ?? 0);
                $imp    = $m3 * $tarifa;
                $subtotal += $imp;
                $destinos[] = ['provincia' => $prov, 'm3' => $m3, 'tarifa' => $tarifa, 'importe' => $imp];
            }

            $ivaMonto = $subtotal * $iva / 100;

            // Comprobante A si el receptor es Responsable Inscripto; si no, B.
            $condReceptor = mb_strtolower(trim((string)($receptor['condicion_iva'] ?? '')));
            $tipoCbte = ($condReceptor === 'responsable inscripto') ? 'A' : 'B';

            $out[] = [
                'key'              => $key,
                'tipo'             => $tipo,
                'tipo_label'       => self::TIPO_LABEL[$tipo] ?? 'Sin tipo',
                'receptor'         => $receptor,
                'sin_marca'        => $sinMarca,
                'tipo_comprobante' => $tipoCbte,
                'destinos'         => $destinos,
                'subtotal'         => $subtotal,
                'iva_alicuota'     => $iva,
                'iva_monto'        => $ivaMonto,
                'total'            => $subtotal + $ivaMonto,
                'total_m3'         => (float)$f['total_m3'],
                'transportistas'   => (string)$f['transportistas'],
                'fechas'           => (string)$f['fechas'],
                'hojas_ruta'       => (string)$f['hojas_ruta'],
                'detalle'          => $detalle[$key] ?? [],
            ];
        }

        // Orden estable: por nombre de receptor (sin marca al final), luego tipo.
        usort($out, static function (array $a, array $b): int {
            $na = $a['sin_marca'] ? "\xff" : mb_strtolower((string)($a['receptor']['nombre'] ?? ''));
            $nb = $b['sin_marca'] ? "\xff" : mb_strtolower((string)($b['receptor']['nombre'] ?? ''));
            return $na <=> $nb ?: ($a['tipo'] <=> $b['tipo']);
        });

        return $out;
    }

    /** Nombre legible del receptor de una factura calculada. */
    public static function receptorNombre(array $factura): string
    {
        $r = $factura['receptor'] ?? null;
        if ($r === null) { return '— sin marca —'; }
        $rs = trim((string)($r['razon_social'] ?? ''));
        return $rs !== '' ? $rs : (string)($r['nombre'] ?? '—');
    }
}
