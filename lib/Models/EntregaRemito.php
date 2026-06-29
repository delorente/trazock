<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * EntregaRemito — metadatos de las fotos de remitos firmados de una entrega.
 *
 * La imagen vive en el filesystem (carpeta REMITOS_DIR); acá guardamos solo la
 * referencia. Se vincula al lote de entrega por `lote_uuid` (la foto y el lote
 * pueden subir en distinto orden desde el celular); `lote_id` se resuelve si el
 * lote ya existe al subir. `foto_uuid` es UNIQUE → la subida es idempotente.
 */
final class EntregaRemito
{
    public static function existe(string $fotoUuid): bool
    {
        $stmt = DB::getInstance()->prepare('SELECT 1 FROM entrega_remitos WHERE foto_uuid = :f LIMIT 1');
        $stmt->execute([':f' => $fotoUuid]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Registra una foto (idempotente por foto_uuid). Resuelve lote_id desde
     * `lotes` por uuid si existe. Devuelve true si insertó, false si ya estaba.
     */
    public static function registrar(
        string $fotoUuid,
        string $loteUuid,
        string $archivo,
        string $mime,
        int $bytes,
        ?string $sha256,
        ?int $subidoPor
    ): bool {
        $db = DB::getInstance();

        // lote_id (si el lote ya llegó). Si no, queda null y se resuelve por join al leer.
        $st = $db->prepare('SELECT id FROM lotes WHERE uuid = :u LIMIT 1');
        $st->execute([':u' => $loteUuid]);
        $lid = $st->fetchColumn();
        $loteId = ($lid !== false && $lid !== null) ? (int)$lid : null;

        $stmt = $db->prepare(
            'INSERT IGNORE INTO entrega_remitos
                (foto_uuid, lote_uuid, lote_id, archivo, mime, bytes, sha256, subido_por)
             VALUES (:f, :lu, :li, :a, :m, :b, :s, :by)'
        );
        $stmt->execute([
            ':f'  => $fotoUuid,
            ':lu' => $loteUuid,
            ':li' => $loteId,
            ':a'  => $archivo,
            ':m'  => $mime,
            ':b'  => $bytes,
            ':s'  => $sha256,
            ':by' => $subidoPor,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $fotoUuid): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM entrega_remitos WHERE foto_uuid = :f LIMIT 1');
        $stmt->execute([':f' => $fotoUuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Remitos de un lote (por uuid). Más reciente primero.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function porLoteUuid(string $loteUuid): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT * FROM entrega_remitos WHERE lote_uuid = :u ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':u' => $loteUuid]);
        return $stmt->fetchAll();
    }

    /**
     * Remitos de las entregas que despacharon una orden (los lotes ENTREGA que
     * tocaron sus ítems). Para el historial de la orden en el panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function porOrden(int $ordenId): array
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT er.* FROM entrega_remitos er
              WHERE er.lote_uuid IN (
                  SELECT DISTINCT l.uuid
                    FROM lotes l
                    JOIN transiciones t ON t.lote_id = l.id
                    JOIN productos p    ON p.id = t.producto_id
                   WHERE p.orden_id = :o AND t.estado_hasta = 'ENTREGADO'
              )
              ORDER BY er.created_at DESC, er.id DESC"
        );
        $stmt->execute([':o' => $ordenId]);
        return $stmt->fetchAll();
    }
}
