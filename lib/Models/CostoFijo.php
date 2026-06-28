<?php
declare(strict_types=1);

namespace Trazock\Models;

use DateTimeImmutable;
use Trazock\DB;

/**
 * Costos fijos mensuales (alquileres, sueldos, otros). Se cargan por mes y el
 * reporte de Resultados los prorratea por días al rango consultado.
 */
final class CostoFijo
{
    public const TIPOS = ['alquiler' => 'Alquiler', 'sueldo' => 'Sueldo', 'otro' => 'Otro'];

    /**
     * Costos cuyos meses tocan el rango [desde, hasta].
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.id, c.tipo, c.concepto, c.importe, c.periodo, c.observacion,
                    u.nombre_completo AS creador
             FROM costos_fijos c LEFT JOIN usuarios u ON u.id = c.creado_por
             WHERE c.periodo BETWEEN :dm AND :hm
             ORDER BY c.periodo DESC, c.tipo ASC, c.id DESC'
        );
        $stmt->execute([':dm' => substr($desde, 0, 7), ':hm' => substr($hasta, 0, 7)]);
        return $stmt->fetchAll();
    }

    public static function crear(string $tipo, string $concepto, float $importe, string $periodo, ?string $obs, ?int $creadoPor): int
    {
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'otro'; }
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) { $periodo = date('Y-m'); }
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO costos_fijos (tipo, concepto, importe, periodo, observacion, creado_por)
             VALUES (:t, :c, :imp, :per, :obs, :por)'
        );
        $stmt->bindValue(':t', $tipo);
        $stmt->bindValue(':c', $concepto);
        $stmt->bindValue(':imp', number_format(max(0, $importe), 2, '.', ''));
        $stmt->bindValue(':per', $periodo);
        $stmt->bindValue(':obs', ($obs !== null && $obs !== '') ? $obs : null, ($obs !== null && $obs !== '') ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':por', $creadoPor, $creadoPor === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    public static function eliminar(int $id): void
    {
        $stmt = DB::getInstance()->prepare('DELETE FROM costos_fijos WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Prorratea por días al rango [desde, hasta], agrupado por tipo.
     * @return array{alquiler:float, sueldo:float, otro:float, total:float}
     */
    public static function prorrateoPorTipo(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT periodo, tipo, COALESCE(SUM(importe),0) AS total
             FROM costos_fijos WHERE periodo BETWEEN :dm AND :hm GROUP BY periodo, tipo'
        );
        $stmt->execute([':dm' => substr($desde, 0, 7), ':hm' => substr($hasta, 0, 7)]);

        $out = ['alquiler' => 0.0, 'sueldo' => 0.0, 'otro' => 0.0, 'total' => 0.0];
        foreach ($stmt->fetchAll() as $r) {
            $factor = self::factor((string)$r['periodo'], $desde, $hasta);
            $monto  = (float)$r['total'] * $factor;
            $tipo   = isset($out[$r['tipo']]) ? (string)$r['tipo'] : 'otro';
            $out[$tipo] += $monto;
            $out['total'] += $monto;
        }
        return $out;
    }

    /** Fracción del mes `periodo` que cae dentro de [desde, hasta] (por días). */
    private static function factor(string $periodo, string $desde, string $hasta): float
    {
        $mStart = new DateTimeImmutable($periodo . '-01');
        $mEnd   = $mStart->modify('last day of this month');
        $d = new DateTimeImmutable($desde);
        $h = new DateTimeImmutable($hasta);
        $os = $d > $mStart ? $d : $mStart;
        $oe = $h < $mEnd ? $h : $mEnd;
        if ($os > $oe) { return 0.0; }
        $diasMes = (int)$mStart->format('t');
        $overlap = (int)$os->diff($oe)->days + 1;
        return $diasMes > 0 ? $overlap / $diasMes : 0.0;
    }
}
