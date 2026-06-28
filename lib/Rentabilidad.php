<?php
declare(strict_types=1);

namespace Trazock;

use Trazock\Models\ClienteFacturacion;
use Trazock\Models\CostoViaje;
use Trazock\Models\Orden;
use Trazock\Models\Proveedor;

/**
 * Calcula resultados por cliente y período (sin emitir factura):
 *   ingresos (cantidad facturable × precio configurado) − costos variables
 *   (costos de viaje del período, atribuidos a cada cliente por su % de m³) = margen.
 *
 * Nivel período/cliente: no asigna costo a cada m³ (un m³ cruza ≥2 viajes), sino
 * que reparte el costo de cada viaje entre los clientes que movió, por su m³.
 */
final class Rentabilidad
{
    /**
     * @return array{
     *   clientes: array<int, array<string,mixed>>,
     *   sin_asignar_costos: float,
     *   totales: array{ingresos:float, costos:float, margen:float}
     * }
     */
    public static function resultados(string $desde, string $hasta): array
    {
        $ingresos = self::ingresosPorCliente($desde, $hasta);
        [$costos, $sinAsignar] = self::costosVariablesPorCliente($desde, $hasta);

        // Nombres de proveedores (para los que tienen costo pero no config de ingreso).
        $nombres = [];
        foreach (Proveedor::todos() as $p) { $nombres[(int)$p['id']] = (string)$p['nombre']; }

        $ids = array_unique(array_merge(array_keys($ingresos), array_keys($costos)));
        $clientes = [];
        $totIng = 0.0; $totCos = 0.0;
        foreach ($ids as $pid) {
            $ing = $ingresos[$pid] ?? ['nombre' => $nombres[$pid] ?? ('#' . $pid), 'unidad' => 'm3', 'ingresos' => 0.0, 'detalle' => []];
            $cos = (float)($costos[$pid] ?? 0);
            $clientes[$pid] = [
                'proveedor_id' => $pid,
                'nombre'       => $ing['nombre'],
                'unidad'       => $ing['unidad'],
                'ingresos'     => (float)$ing['ingresos'],
                'costos'       => $cos,
                'margen'       => (float)$ing['ingresos'] - $cos,
                'detalle'      => $ing['detalle'],
            ];
            $totIng += (float)$ing['ingresos'];
            $totCos += $cos;
        }
        // Ordenar por margen descendente.
        uasort($clientes, static fn($a, $b) => $b['margen'] <=> $a['margen']);

        $totCos += $sinAsignar;
        return [
            'clientes'           => $clientes,
            'sin_asignar_costos' => $sinAsignar,
            'totales'            => ['ingresos' => $totIng, 'costos' => $totCos, 'margen' => $totIng - $totCos],
        ];
    }

    /**
     * Ingresos por cliente = Σ (cantidad según unidad × precio configurado).
     * @return array<int, array<string,mixed>>  proveedor_id => [nombre, unidad, ingresos, detalle]
     */
    public static function ingresosPorCliente(string $desde, string $hasta): array
    {
        $configs  = ClienteFacturacion::configsActivas();        // proveedor_id => config
        $tarifas  = ClienteFacturacion::mapaTarifasDestino();    // [prov][provincia] => precio
        $cant     = Orden::cantidadesPorProveedorProvincia($desde, $hasta);

        $out = [];
        foreach ($cant as $row) {
            $pid = $row['proveedor_id'];
            if (!isset($configs[$pid])) { continue; } // sin config de facturación → no se calcula ingreso
            $cfg = $configs[$pid];
            $unidad = (string)$cfg['unidad'];
            $cantidad = $unidad === 'm3' ? (float)$row['m3'] : ($unidad === 'bulto' ? (float)$row['bultos'] : 0.0); // peso: sin dato aún
            $precio = ((int)$cfg['por_destino'] === 1)
                ? (float)($tarifas[$pid][$row['provincia']] ?? 0)
                : (float)($cfg['precio_unico'] ?? 0);
            $importe = $cantidad * $precio;

            if (!isset($out[$pid])) {
                $out[$pid] = ['nombre' => (string)$cfg['nombre'], 'unidad' => $unidad, 'ingresos' => 0.0, 'detalle' => []];
            }
            $out[$pid]['ingresos'] += $importe;
            $out[$pid]['detalle'][] = [
                'provincia' => $row['provincia'],
                'cantidad'  => $cantidad,
                'precio'    => $precio,
                'importe'   => $importe,
            ];
        }
        return $out;
    }

    /**
     * Costos variables por cliente: reparte el costo de cada viaje entre los
     * clientes que movió, por su participación de m³. Lo no atribuible va aparte.
     *
     * @return array{0: array<int,float>, 1: float}  [costosPorProveedor, sinAsignar]
     */
    public static function costosVariablesPorCliente(string $desde, string $hasta): array
    {
        $db = DB::getInstance();

        // Costo total por lote en el período.
        $stmt = $db->prepare('SELECT lote_id, SUM(importe) AS total FROM costos_viaje WHERE fecha BETWEEN :d AND :h GROUP BY lote_id');
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        $costoPorLote = [];
        foreach ($stmt->fetchAll() as $r) { $costoPorLote[(int)$r['lote_id']] = (float)$r['total']; }
        if ($costoPorLote === []) { return [[], 0.0]; }

        // m³ por (lote, proveedor) para esos lotes.
        $ids = array_keys($costoPorLote);
        $ph = [];
        $params = [];
        foreach ($ids as $i => $id) { $k = ':l' . $i; $ph[] = $k; $params[$k] = $id; }
        $sql = 'SELECT l.id AS lote_id, cat.proveedor_id AS prov, COALESCE(SUM(p.m3),0) AS m3
                FROM lotes l
                JOIN transiciones t ON t.lote_id = l.id
                JOIN productos p ON p.id = t.producto_id
                JOIN ordenes o ON o.id = p.orden_id
                JOIN cargas cg ON cg.id = o.carga_id
                JOIN categorias cat ON cat.id = cg.categoria_id
                WHERE cat.proveedor_id IS NOT NULL AND l.id IN (' . implode(',', $ph) . ')
                GROUP BY l.id, cat.proveedor_id';
        $stmt2 = $db->prepare($sql);
        $stmt2->execute($params);
        $m3PorLoteProv = [];
        foreach ($stmt2->fetchAll() as $r) {
            $m3PorLoteProv[(int)$r['lote_id']][(int)$r['prov']] = (float)$r['m3'];
        }

        $out = [];
        $sinAsignar = 0.0;
        foreach ($costoPorLote as $loteId => $costo) {
            $shares = $m3PorLoteProv[$loteId] ?? [];
            $tot = array_sum($shares);
            if ($tot > 0) {
                foreach ($shares as $pid => $m3) {
                    $out[$pid] = ($out[$pid] ?? 0) + $costo * ($m3 / $tot);
                }
            } else {
                $sinAsignar += $costo; // viaje sin m³ atribuible (ej. ingreso sin m³ por orden)
            }
        }
        return [$out, $sinAsignar];
    }
}
