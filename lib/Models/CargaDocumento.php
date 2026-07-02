<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * CargaDocumento — metadatos de los documentos originales (hojas resumen) que se
 * importan por OCR. El archivo (imagen/PDF) vive en el filesystem (DOCUMENTOS_DIR);
 * acá guardamos solo la referencia, ligada a la carga. `uuid` es UNIQUE → idempotente.
 */
final class CargaDocumento
{
    /** Registra un documento de una carga. Devuelve true si insertó. */
    public static function registrar(
        int $cargaId,
        string $uuid,
        string $archivo,
        string $mime,
        int $bytes,
        ?string $sha256,
        ?int $subidoPor
    ): bool {
        $stmt = DB::getInstance()->prepare(
            'INSERT IGNORE INTO carga_documentos
                (carga_id, uuid, archivo, mime, bytes, sha256, subido_por)
             VALUES (:c, :u, :a, :m, :b, :s, :by)'
        );
        $stmt->execute([
            ':c'  => $cargaId,
            ':u'  => $uuid,
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
    public static function find(string $uuid): ?array
    {
        $stmt = DB::getInstance()->prepare('SELECT * FROM carga_documentos WHERE uuid = :u LIMIT 1');
        $stmt->execute([':u' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Documentos de una carga (más antiguo primero: el orden en que se subieron).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function porCarga(int $cargaId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT * FROM carga_documentos WHERE carga_id = :c ORDER BY id ASC'
        );
        $stmt->execute([':c' => $cargaId]);
        return $stmt->fetchAll();
    }

    /**
     * Nombres de archivo de los documentos de una carga (para borrarlos del disco
     * al descartar el borrador; las filas se van por ON DELETE CASCADE).
     *
     * @return array<int, string>
     */
    public static function archivosDeCarga(int $cargaId): array
    {
        $stmt = DB::getInstance()->prepare('SELECT archivo FROM carga_documentos WHERE carga_id = :c');
        $stmt->execute([':c' => $cargaId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
