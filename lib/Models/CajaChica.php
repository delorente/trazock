<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Caja chica: movimientos de efectivo que maneja el contable.
 * ingreso/rendicion suman; egreso/adelanto_chofer restan.
 */
final class CajaChica
{
    public const TIPOS = [
        'ingreso'        => 'Ingreso',
        'egreso'         => 'Egreso',
        'adelanto_chofer' => 'Adelanto a chofer',
        'rendicion'      => 'Rendición',
    ];

    /** +1 suma al saldo, -1 resta. */
    public static function signo(string $tipo): int
    {
        return in_array($tipo, ['ingreso', 'rendicion'], true) ? 1 : -1;
    }

    /** Saldo de toda la caja (todos los movimientos). */
    public static function saldoActual(): float
    {
        return self::saldoSql('');
    }

    /** Saldo acumulado de los movimientos ANTERIORES a $fecha (para saldo inicial). */
    public static function saldoAnteriorA(string $fecha): float
    {
        return self::saldoSql(' WHERE fecha < :f', [':f' => $fecha]);
    }

    private static function saldoSql(string $where, array $params = []): float
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN tipo IN ('ingreso','rendicion') THEN monto ELSE -monto END), 0)
             FROM caja_chica" . $where
        );
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Movimientos del período (asc por fecha/id) con nombre de chofer y creador.
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT c.id, c.tipo, c.monto, c.fecha, c.concepto, c.chofer_id, c.observacion,
                    u.nombre_completo AS chofer, cr.nombre_completo AS creador
             FROM caja_chica c
             LEFT JOIN usuarios u  ON u.id  = c.chofer_id
             LEFT JOIN usuarios cr ON cr.id = c.creado_por
             WHERE c.fecha BETWEEN :d AND :h
             ORDER BY c.fecha ASC, c.id ASC'
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /**
     * Totales del período: ingresos (suma de los que suman), egresos (los que
     * restan, en positivo) y neto.
     * @return array{ingresos:float, egresos:float, neto:float}
     */
    public static function totales(string $desde, string $hasta): array
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo IN ('ingreso','rendicion') THEN monto ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo IN ('egreso','adelanto_chofer') THEN monto ELSE 0 END), 0) AS egresos
             FROM caja_chica WHERE fecha BETWEEN :d AND :h"
        );
        $stmt->execute([':d' => $desde, ':h' => $hasta]);
        $r = $stmt->fetch() ?: [];
        $ing = (float)($r['ingresos'] ?? 0);
        $egr = (float)($r['egresos'] ?? 0);
        return ['ingresos' => $ing, 'egresos' => $egr, 'neto' => $ing - $egr];
    }

    public static function crear(string $tipo, float $monto, string $fecha, string $concepto, ?int $choferId, ?string $obs, ?int $creadoPor): int
    {
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'egreso'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { $fecha = date('Y-m-d'); }
        // chofer solo aplica a adelanto/rendición.
        if (!in_array($tipo, ['adelanto_chofer', 'rendicion'], true)) { $choferId = null; }

        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO caja_chica (tipo, monto, fecha, concepto, chofer_id, observacion, creado_por)
             VALUES (:t, :m, :f, :c, :ch, :obs, :por)'
        );
        $stmt->bindValue(':t', $tipo);
        $stmt->bindValue(':m', number_format(max(0, $monto), 2, '.', ''));
        $stmt->bindValue(':f', $fecha);
        $stmt->bindValue(':c', $concepto);
        $stmt->bindValue(':ch', $choferId, $choferId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':obs', ($obs !== null && $obs !== '') ? $obs : null, ($obs !== null && $obs !== '') ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':por', $creadoPor, $creadoPor === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    public static function eliminar(int $id): void
    {
        $stmt = DB::getInstance()->prepare('DELETE FROM caja_chica WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
