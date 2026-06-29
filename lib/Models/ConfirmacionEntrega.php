<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * ConfirmacionEntrega — aviso de entrega al cliente final por WhatsApp y su
 * respuesta (Confirmar / Reprogramar).
 *
 * Una fila por orden (UNIQUE orden_id). Se crea/actualiza al disparar el aviso
 * desde el panel (estado 'enviado' o 'error') y se completa cuando el cliente
 * responde por el webhook (estado 'confirmado' | 'reprogramado'). Reenviar un
 * aviso reescribe la misma fila (upsert), reabriendo el ciclo.
 */
final class ConfirmacionEntrega
{
    /** Estados del ciclo de vida del aviso. */
    public const ESTADOS = ['enviado', 'confirmado', 'reprogramado', 'error'];

    /**
     * Registra el envío de un aviso (upsert por orden). Si ya existía, lo
     * reescribe: nuevo mensaje, estado 'enviado'/'error', respuesta limpia.
     */
    public static function registrarEnviado(
        int $ordenId,
        ?string $fecha,
        ?string $horario,
        ?string $telefono,
        ?string $waMessageId,
        ?string $error,
        int $enviadoPor
    ): void {
        $estado = $error === null ? 'enviado' : 'error';
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO confirmaciones_entrega
                (orden_id, estado, fecha_entrega, horario, telefono, wa_message_id, error, enviado_por, enviado_at)
             VALUES (:o, :e, :f, :h, :tel, :wam, :err, :by, NOW())
             ON DUPLICATE KEY UPDATE
                estado = VALUES(estado), fecha_entrega = VALUES(fecha_entrega),
                horario = VALUES(horario), telefono = VALUES(telefono),
                wa_message_id = VALUES(wa_message_id), error = VALUES(error),
                enviado_por = VALUES(enviado_por), enviado_at = NOW(), respondido_at = NULL'
        );
        $stmt->execute([
            ':o'   => $ordenId,
            ':e'   => $estado,
            ':f'   => ($fecha === '' ? null : $fecha),
            ':h'   => ($horario === '' ? null : $horario),
            ':tel' => ($telefono === '' ? null : $telefono),
            ':wam' => $waMessageId,
            ':err' => $error,
            ':by'  => $enviadoPor,
        ]);
    }

    /**
     * Aplica la respuesta del cliente (llegada por webhook), localizando la fila
     * por el id del mensaje saliente. $respuesta: 'confirmado' | 'reprogramado'.
     * Idempotente: no pisa una respuesta ya registrada. Devuelve el orden_id
     * afectado (para que el webhook aplique la marca), o null si no hubo match.
     */
    public static function marcarRespuesta(string $waMessageId, string $respuesta): ?int
    {
        if (!in_array($respuesta, ['confirmado', 'reprogramado'], true)) {
            return null;
        }
        $db = DB::getInstance();
        $sel = $db->prepare('SELECT id, orden_id FROM confirmaciones_entrega WHERE wa_message_id = :w LIMIT 1');
        $sel->execute([':w' => $waMessageId]);
        $row = $sel->fetch();
        if ($row === false) {
            return null;
        }
        $upd = $db->prepare(
            "UPDATE confirmaciones_entrega
                SET estado = :e, respondido_at = NOW()
              WHERE id = :id AND estado IN ('enviado','error')"
        );
        $upd->execute([':e' => $respuesta, ':id' => (int)$row['id']]);
        return (int)$row['orden_id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porOrden(int $ordenId): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM confirmaciones_entrega WHERE orden_id = :o LIMIT 1');
        $stmt->execute([':o' => $ordenId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Condiciones de filtro de la grilla/resumen (rango por fecha de entrega +
     * estado). Placeholders únicos (prepares nativos: sin reuso de nombres).
     *
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function whereFiltros(array $f): array
    {
        $where  = [];
        $params = [];
        if (!empty($f['fecha_desde'])) {
            $where[] = 'c.fecha_entrega >= :fd';
            $params[':fd'] = $f['fecha_desde'];
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'c.fecha_entrega <= :fh';
            $params[':fh'] = $f['fecha_hasta'];
        }
        if (!empty($f['estado']) && in_array($f['estado'], self::ESTADOS, true)) {
            $where[] = 'c.estado = :est';
            $params[':est'] = $f['estado'];
        }
        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    /**
     * Filas para la grilla del panel (más reciente primero) con datos de la orden.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listar(array $filtros, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT c.*, o.nro_orden, o.cliente, o.cliente_apellido,
                       o.dest_localidad, o.dest_provincia, o.telefonos, o.marca
                FROM confirmaciones_entrega c
                JOIN ordenes o ON o.id = c.orden_id'
             . $where
             . " ORDER BY c.enviado_at DESC, c.id DESC LIMIT {$limit} OFFSET {$offset}";

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
        $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM confirmaciones_entrega c' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Conteo por estado para los KPIs del período.
     *
     * @param array<string, mixed> $filtros
     * @return array{enviado:int, confirmado:int, reprogramado:int, error:int, total:int}
     */
    public static function resumen(array $filtros): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare(
            'SELECT c.estado, COUNT(*) AS n FROM confirmaciones_entrega c' . $where . ' GROUP BY c.estado'
        );
        $stmt->execute($params);
        $out = ['enviado' => 0, 'confirmado' => 0, 'reprogramado' => 0, 'error' => 0, 'total' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $est = (string)$r['estado'];
            if (isset($out[$est])) {
                $out[$est] = (int)$r['n'];
                $out['total'] += (int)$r['n'];
            }
        }
        return $out;
    }
}
