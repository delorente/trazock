<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * Destino — heurística para detectar destinos "raros" (posible error de OCR) antes
 * de cargar o de enviar/exportar. Una provincia se considera CONOCIDA si:
 *   - está en alguna zona de reparto activa (donde efectivamente entregamos), o
 *   - aparece en al menos $min órdenes históricas (destino habitual aunque no
 *     esté zonificado todavía).
 * Lo que no entra en ese conjunto se marca como sospechoso (sin bloquear).
 */
final class Destino
{
    /** Normaliza para comparar sin distinguir mayúsculas/acentos/espacios. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Conjunto de provincias conocidas, normalizadas. Clave = provincia normalizada,
     * valor = true (para chequeo O(1)).
     *
     * @return array<string, true>
     */
    public static function provinciasConocidas(int $min = 3): array
    {
        $db  = DB::getInstance();
        $out = [];

        // Provincias cubiertas por alguna zona de reparto activa.
        $rows = $db->query(
            "SELECT DISTINCT zl.provincia
               FROM zona_localidades zl
               JOIN zonas z ON z.id = zl.zona_id
              WHERE z.activo = 1 AND zl.provincia IS NOT NULL AND zl.provincia <> ''"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $p) {
            $n = self::norm((string)$p);
            if ($n !== '') { $out[$n] = true; }
        }

        // Provincias con suficiente historial de órdenes.
        $stmt = $db->prepare(
            "SELECT dest_provincia AS prov, COUNT(*) AS n
               FROM ordenes
              WHERE dest_provincia IS NOT NULL AND dest_provincia <> ''
              GROUP BY dest_provincia
             HAVING n >= :m"
        );
        $stmt->execute([':m' => $min]);
        foreach ($stmt->fetchAll() as $r) {
            $n = self::norm((string)$r['prov']);
            if ($n !== '') { $out[$n] = true; }
        }

        return $out;
    }

    /**
     * ¿La provincia es sospechosa? (no vacía y fuera del conjunto conocido).
     * La provincia vacía la avisa otra validación, acá no se marca.
     *
     * @param array<string, true> $conocidas
     */
    public static function esSospechosa(?string $provincia, array $conocidas): bool
    {
        $p = trim((string)$provincia);
        if ($p === '') {
            return false;
        }
        return !isset($conocidas[self::norm($p)]);
    }
}
