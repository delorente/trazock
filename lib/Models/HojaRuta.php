<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * HojaRuta — hoja de ruta de SALIDA A REPARTO (nuestra), armada por logística.
 *
 * Encabezado (conductor/vehículo/ayudantes, del padrón o texto libre), las órdenes
 * del sistema que salen (hoja_ruta_ordenes) y líneas manuales de artículos fuera
 * del sistema (hoja_ruta_manual). El scan de reparto la elige y el lote queda
 * asociado. No toca `ordenes.hoja_ruta` (esa es la del proveedor).
 */
final class HojaRuta
{
    public const ESTADOS = ['abierta', 'emitida'];

    /** Crea una hoja vacía (abierta, fecha de hoy) y le asigna un número correlativo. */
    public static function crear(int $creadoPor): int
    {
        $db = DB::getInstance();
        $db->prepare('INSERT INTO hojas_ruta (fecha, creado_por) VALUES (CURDATE(), :u)')
            ->execute([':u' => $creadoPor]);
        $id = (int)$db->lastInsertId();
        $db->prepare('UPDATE hojas_ruta SET numero = :n WHERE id = :id')
            ->execute([':n' => 'HR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT), ':id' => $id]);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM hojas_ruta WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    /** Guarda el encabezado (solo si está abierta lo permite el llamador). */
    public static function guardarEncabezado(int $id, array $d): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE hojas_ruta SET
                fecha = :fecha,
                conductor_empleado_id = :cid, conductor = :cond,
                vehiculo_id = :vid, vehiculo = :veh,
                ayudantes = :ayud, destino = :dest, observaciones = :obs
             WHERE id = :id'
        );
        $nn = static fn($v) => ($v === null || $v === '') ? null : $v;
        $stmt->bindValue(':fecha', $nn($d['fecha'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':cid', $nn($d['conductor_empleado_id'] ?? null), ($d['conductor_empleado_id'] ?? null) ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $stmt->bindValue(':cond', $nn($d['conductor'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':vid', $nn($d['vehiculo_id'] ?? null), ($d['vehiculo_id'] ?? null) ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $stmt->bindValue(':veh', $nn($d['vehiculo'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':ayud', $nn($d['ayudantes'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':dest', $nn($d['destino'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':obs', $nn($d['observaciones'] ?? null), \PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function emitir(int $id): void
    {
        DB::getInstance()->prepare("UPDATE hojas_ruta SET estado = 'emitida' WHERE id = :id")->execute([':id' => $id]);
    }

    public static function reabrir(int $id): void
    {
        DB::getInstance()->prepare("UPDATE hojas_ruta SET estado = 'abierta' WHERE id = :id")->execute([':id' => $id]);
    }

    public static function eliminar(int $id): void
    {
        DB::getInstance()->prepare('DELETE FROM hojas_ruta WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Listado para el panel: hoja + conteos de órdenes/manuales y si tiene reparto
     * escaneado asociado.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listar(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = DB::getInstance()->query(
            "SELECT h.*,
                    (SELECT COUNT(*) FROM hoja_ruta_ordenes ho WHERE ho.hoja_id = h.id) AS n_ordenes,
                    (SELECT COUNT(*) FROM hoja_ruta_manual hm WHERE hm.hoja_id = h.id) AS n_manual,
                    (SELECT COUNT(*) FROM lotes l WHERE l.hoja_ruta_id = h.id) AS n_lotes
             FROM hojas_ruta h
             ORDER BY h.id DESC LIMIT {$limit}"
        )->fetchAll();
        return $rows;
    }

    /**
     * Hojas ABIERTAS para el desplegable del scan (offline). Solo lo necesario
     * para snapshotear el viaje en el lote.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function abiertasParaScan(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT id, numero, fecha, conductor_empleado_id, conductor, vehiculo_id, vehiculo, ayudantes, destino
             FROM hojas_ruta WHERE estado = 'abierta' ORDER BY id DESC"
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'                    => (int)$r['id'],
            'numero'                => (string)$r['numero'],
            'destino'               => (string)($r['destino'] ?? ''),
            'conductor_empleado_id' => $r['conductor_empleado_id'] !== null ? (int)$r['conductor_empleado_id'] : null,
            'conductor'             => (string)($r['conductor'] ?? ''),
            'vehiculo_id'           => $r['vehiculo_id'] !== null ? (int)$r['vehiculo_id'] : null,
            'vehiculo'              => (string)($r['vehiculo'] ?? ''),
            'ayudantes'             => (string)($r['ayudantes'] ?? ''),
        ], $rows);
    }

    // ------------------------------------------------------------------ órdenes

    /** Agrega una orden a la hoja. Devuelve true si la agregó (false si ya estaba). */
    public static function agregarOrden(int $hojaId, int $ordenId): bool
    {
        $stmt = DB::getInstance()->prepare(
            'INSERT IGNORE INTO hoja_ruta_ordenes (hoja_id, orden_id) VALUES (:h, :o)'
        );
        $stmt->execute([':h' => $hojaId, ':o' => $ordenId]);
        return $stmt->rowCount() > 0;
    }

    public static function quitarOrden(int $hojaId, int $ordenId): void
    {
        DB::getInstance()->prepare('DELETE FROM hoja_ruta_ordenes WHERE hoja_id = :h AND orden_id = :o')
            ->execute([':h' => $hojaId, ':o' => $ordenId]);
    }

    /**
     * Órdenes de la hoja con los datos para el editor y la impresión.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function ordenesDe(int $hojaId): array
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT o.id, o.nro_orden, o.cliente, o.cliente_apellido, o.telefonos,
                    o.dest_localidad, o.dest_provincia, o.m3_total,
                    (SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id) AS bultos,
                    (SELECT cat.nombre FROM cargas cg JOIN categorias cat ON cat.id = cg.categoria_id WHERE cg.id = o.carga_id) AS categoria
             FROM hoja_ruta_ordenes ho JOIN ordenes o ON o.id = ho.orden_id
             WHERE ho.hoja_id = :h
             ORDER BY o.nro_orden"
        );
        $stmt->execute([':h' => $hojaId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------ manuales

    public static function agregarManual(int $hojaId, array $d): int
    {
        $db = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO hoja_ruta_manual
                (hoja_id, cliente_origen, nro_orden, cliente_destino, localidad, bultos, m3, telefono, observacion)
             VALUES (:h, :co, :no, :cd, :loc, :bul, :m3, :tel, :obs)'
        );
        $nn = static fn($v) => ($v === null || $v === '') ? null : $v;
        $stmt->execute([
            ':h'   => $hojaId,
            ':co'  => $nn($d['cliente_origen'] ?? null),
            ':no'  => $nn($d['nro_orden'] ?? null),
            ':cd'  => $nn($d['cliente_destino'] ?? null),
            ':loc' => $nn($d['localidad'] ?? null),
            ':bul' => ($d['bultos'] ?? null) !== null && $d['bultos'] !== '' ? (int)$d['bultos'] : null,
            ':m3'  => ($d['m3'] ?? null) !== null && $d['m3'] !== '' ? (float)$d['m3'] : null,
            ':tel' => $nn($d['telefono'] ?? null),
            ':obs' => $nn($d['observacion'] ?? null),
        ]);
        return (int)$db->lastInsertId();
    }

    public static function quitarManual(int $manualId): void
    {
        DB::getInstance()->prepare('DELETE FROM hoja_ruta_manual WHERE id = :id')->execute([':id' => $manualId]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function manualesDe(int $hojaId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT * FROM hoja_ruta_manual WHERE hoja_id = :h ORDER BY posicion, id'
        );
        $stmt->execute([':h' => $hojaId]);
        return $stmt->fetchAll();
    }

    /** ¿A qué hoja(s) de reparto pertenece una orden? Para el detalle de la orden. */
    public static function porOrden(int $ordenId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT h.id, h.numero, h.fecha, h.conductor, h.vehiculo, h.estado
             FROM hoja_ruta_ordenes ho JOIN hojas_ruta h ON h.id = ho.hoja_id
             WHERE ho.orden_id = :o ORDER BY h.id DESC'
        );
        $stmt->execute([':o' => $ordenId]);
        return $stmt->fetchAll();
    }
}
