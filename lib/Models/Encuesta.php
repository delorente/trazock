<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Encuesta — calificación de satisfacción del comprador contra entrega.
 *
 * Una por orden (UNIQUE orden_id). El comprador la responde desde el seguimiento
 * público cuando su pedido está ENTREGADO. Cada eje se puntúa 1-4:
 *   1 = Muy malo · 2 = Regular · 3 = Bueno · 4 = Excelente
 * `general` es la experiencia global; `tiempo`/`paquete`/`trato` son los aspectos.
 */
final class Encuesta
{
    /** Ejes que se guardan (además del comentario libre). */
    public const EJES = ['general', 'tiempo', 'paquete', 'trato'];

    /** Etiquetas legibles de cada nivel 1-4 (índice 0 sin uso). */
    public const NIVELES = [1 => 'Muy malo', 2 => 'Regular', 3 => 'Bueno', 4 => 'Excelente'];

    public static function existeParaOrden(int $ordenId): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM encuestas WHERE orden_id = :o LIMIT 1');
        $stmt->execute([':o' => $ordenId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Encuesta de una orden (para mostrar el resumen "ya respondiste").
     *
     * @return array<string, mixed>|null
     */
    public static function findPorOrden(int $ordenId): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM encuestas WHERE orden_id = :o LIMIT 1');
        $stmt->execute([':o' => $ordenId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Crea la encuesta de una orden. Las puntuaciones llegan ya validadas (1-4).
     * Lanza si ya existe (UNIQUE) — el endpoint lo chequea antes igualmente.
     *
     * @param array{general:int, tiempo:int, paquete:int, trato:int} $r
     */
    public static function crear(int $ordenId, array $r, ?string $comentario): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO encuestas (orden_id, general, tiempo, paquete, trato, comentario)
             VALUES (:o, :g, :t, :p, :tr, :c)'
        );
        $stmt->execute([
            ':o'  => $ordenId,
            ':g'  => $r['general'],
            ':t'  => $r['tiempo'],
            ':p'  => $r['paquete'],
            ':tr' => $r['trato'],
            ':c'  => ($comentario === null || $comentario === '') ? null : $comentario,
        ]);
        return (int)$db->lastInsertId();
    }

    /** Total de respuestas (para el badge del sidebar). */
    public static function total(): int
    {
        return (int)DB::getInstance()->query('SELECT COUNT(*) FROM encuestas')->fetchColumn();
    }

    /** Elimina una encuesta por id. Devuelve true si borró una fila. */
    public static function eliminar(int $id): bool
    {
        $stmt = DB::getInstance()->prepare('DELETE FROM encuestas WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // Panel (admin/encuestas.php)
    // -------------------------------------------------------------------------

    /**
     * Subconsulta correlacionada con la FECHA DE ENTREGA de una orden: el primer
     * scan (timestamp_cliente más antiguo, no conflicto) que la pasó a ENTREGADO.
     * Es la misma fecha que datea el paso "Entregado" del seguimiento.
     * $ordenIdExpr es la expresión SQL de la columna orden_id (ej. 'e.orden_id').
     */
    private static function entregaExpr(string $ordenIdExpr): string
    {
        return '(SELECT MIN(t.timestamp_cliente)
                   FROM transiciones t JOIN productos p ON p.id = t.producto_id
                  WHERE p.orden_id = ' . $ordenIdExpr . "
                    AND t.estado_hasta = 'ENTREGADO' AND t.es_conflicto = 0)";
    }

    /**
     * Condiciones de rango por fecha de ENTREGA (no por fecha de respuesta). Se
     * comparte entre las consultas sobre `encuestas` (alias e) y el denominador
     * sobre `ordenes` (alias o), variando la expresión de orden_id.
     *
     * @param array<string, mixed> $f
     * @return array{0:array<int,string>, 1:array<string,mixed>}
     */
    private static function condFecha(array $f, string $ordenIdExpr): array
    {
        $entrega = self::entregaExpr($ordenIdExpr);
        $where   = [];
        $params  = [];
        // :fd y :fh se usan una sola vez cada uno (la subconsulta no lleva placeholders):
        // sin reuso de nombres → sin HY093 con prepares nativos.
        if (!empty($f['fecha_desde'])) {
            $where[] = 'DATE(' . $entrega . ') >= :fd';
            $params[':fd'] = $f['fecha_desde'];
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'DATE(' . $entrega . ') <= :fh';
            $params[':fh'] = $f['fecha_hasta'];
        }
        return [$where, $params];
    }

    /**
     * WHERE de la grilla: rango por fecha de entrega + filtro por carita (la
     * carita solo afecta la grilla, no los KPIs/distribución/tasa).
     *
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function whereFiltros(array $f): array
    {
        [$where, $params] = self::condFecha($f, 'e.orden_id');
        if (!empty($f['cal'])) {
            $where[] = 'e.general = :cal';
            $params[':cal'] = (int)$f['cal'];
        }
        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    /**
     * WHERE solo por rango de fecha de entrega (KPIs, distribución, tasa: ignoran
     * la carita para resumir todo el período).
     *
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function whereFecha(array $f): array
    {
        [$where, $params] = self::condFecha($f, 'e.orden_id');
        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    /**
     * Filas para la grilla del panel (más reciente primero), con el Nº de orden.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT e.*, o.nro_orden, ' . self::entregaExpr('e.orden_id') . ' AS entregada_at
                FROM encuestas e
                JOIN ordenes o ON o.id = e.orden_id'
             . $where
             . " ORDER BY entregada_at DESC, e.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filtros
     */
    public static function contar(array $filtros): int
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM encuestas e' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Métricas agregadas de las encuestas filtradas: cantidad, promedio general,
     * cuántas tienen comentario y la distribución por nivel (1-4) de la general.
     *
     * @param array<string, mixed> $filtros
     * @return array{respuestas:int, promedio:float, con_comentario:int, distribucion:array<int,int>}
     */
    public static function estadisticas(array $filtros): array
    {
        // Resumen del período (por fecha de entrega); la carita no aplica acá.
        [$where, $params] = self::whereFecha($filtros);
        $db = DB::getInstance();

        $stmt = $db->prepare(
            'SELECT COUNT(*) AS respuestas,
                    COALESCE(AVG(e.general), 0) AS promedio,
                    SUM(CASE WHEN e.comentario IS NOT NULL AND e.comentario <> \'\' THEN 1 ELSE 0 END) AS con_comentario
             FROM encuestas e' . $where
        );
        $stmt->execute($params);
        $a = $stmt->fetch() ?: [];

        // Distribución por nivel de la calificación general.
        $stmt2 = $db->prepare('SELECT e.general AS nivel, COUNT(*) AS n FROM encuestas e' . $where . ' GROUP BY e.general');
        $stmt2->execute($params);
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($stmt2->fetchAll() as $r) {
            $nivel = (int)$r['nivel'];
            if (isset($dist[$nivel])) {
                $dist[$nivel] = (int)$r['n'];
            }
        }

        return [
            'respuestas'     => (int)($a['respuestas'] ?? 0),
            'promedio'       => round((float)($a['promedio'] ?? 0), 1),
            'con_comentario' => (int)($a['con_comentario'] ?? 0),
            'distribucion'   => $dist,
        ];
    }

    /**
     * Tasa de respuesta del período = encuestas respondidas / órdenes ENTREGADAS,
     * ambas acotadas por FECHA DE ENTREGA (el scan que pasó la orden a ENTREGADO).
     * Mide, de quienes recibieron el pedido en el rango, cuántos calificaron.
     *
     * @param array<string, mixed> $filtros
     */
    public static function tasaRespuesta(array $filtros): float
    {
        $db = DB::getInstance();

        // Numerador: encuestas (entregadas en el rango). Sin filtro de carita.
        [$wEnc, $pEnc] = self::whereFecha($filtros);
        $stmt = $db->prepare('SELECT COUNT(*) FROM encuestas e' . $wEnc);
        $stmt->execute($pEnc);
        $respuestas = (int)$stmt->fetchColumn();

        // Denominador: órdenes entregadas en el rango (misma fecha de entrega).
        [$wFecha, $pOrd] = self::condFecha($filtros, 'o.id');
        $where = array_merge(["o.estado = 'ENTREGADO'"], $wFecha);
        $stmt = $db->prepare('SELECT COUNT(*) FROM ordenes o WHERE ' . implode(' AND ', $where));
        $stmt->execute($pOrd);
        $entregadas = (int)$stmt->fetchColumn();

        if ($entregadas <= 0) {
            return 0.0;
        }
        return min(100.0, round($respuestas * 100 / $entregadas, 0));
    }
}
