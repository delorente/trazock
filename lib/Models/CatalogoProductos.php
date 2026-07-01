<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;

/**
 * CatalogoProductos — lista de productos distintos (descripción + dimensiones + m³)
 * armada desde el histórico de `productos`, para autocompletar la carga/corrección
 * de ítems (los códigos son largos y tediosos de tipear).
 *
 * Se cachea en disco con TTL largo (~30 días) porque el catálogo es estable; un
 * botón en el panel permite regenerarlo (p. ej. mensualmente) para incorporar los
 * productos nuevos. Best-effort: si no hay directorio escribible, arma en vivo.
 */
final class CatalogoProductos
{
    private const CACHE_TTL = 2592000; // 30 días

    private static function cachePath(): ?string
    {
        $base = sys_get_temp_dir() . '/trazock-cache';
        if (!is_dir($base)) { @mkdir($base, 0777, true); }
        if (!is_dir($base) || !is_writable($base)) { return null; }
        return $base . '/catalogo-prod-' . md5(__DIR__) . '.json';
    }

    /** Borra el cache (para forzar la regeneración desde el panel). */
    public static function invalidarCache(): void
    {
        $path = self::cachePath();
        if ($path !== null && is_file($path)) { @unlink($path); }
    }

    /**
     * Catálogo cacheado.
     *
     * @return array{items: array<int, array{desc:string, dim:string, m3:float|null}>, total:int, generado:int}
     */
    public static function catalogo(bool $refrescar = false): array
    {
        $path = self::cachePath();
        if (!$refrescar && $path !== null && is_file($path)
            && (time() - (int)@filemtime($path)) < self::CACHE_TTL) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $d = json_decode($raw, true);
                if (is_array($d) && isset($d['items']) && is_array($d['items'])) {
                    return $d;
                }
            }
        }

        $d = self::construir();
        if ($path !== null) {
            @file_put_contents($path, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $d;
    }

    /** Regenera el cache ahora y devuelve el catálogo nuevo. */
    public static function regenerar(): array
    {
        self::invalidarCache();
        return self::catalogo(true);
    }

    /**
     * Arma el catálogo desde la base: por cada descripción, sus dimensiones y m³
     * más frecuentes. Ordenado por frecuencia (los más usados primero).
     *
     * @return array{items: array<int, array{desc:string, dim:string, m3:float|null}>, total:int, generado:int}
     */
    private static function construir(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT descripcion AS d, COALESCE(dimensiones,'') AS dim, m3, COUNT(*) AS n
               FROM productos
              WHERE descripcion IS NOT NULL AND descripcion <> ''
              GROUP BY descripcion, dimensiones, m3"
        )->fetchAll();

        // Agrupar por descripción: elegir (dim, m3) más frecuente y sumar el total.
        $acc = []; // desc => ['desc'=>, 'total'=>, 'mejor'=>['dim','m3','n']]
        foreach ($rows as $r) {
            $desc = trim((string)$r['d']);
            if ($desc === '') { continue; }
            $n = (int)$r['n'];
            if (!isset($acc[$desc])) {
                $acc[$desc] = ['desc' => $desc, 'total' => 0, 'mejor' => ['dim' => '', 'm3' => null, 'n' => -1]];
            }
            $acc[$desc]['total'] += $n;
            if ($n > $acc[$desc]['mejor']['n']) {
                $acc[$desc]['mejor'] = [
                    'dim' => trim((string)$r['dim']),
                    'm3'  => $r['m3'] !== null ? (float)$r['m3'] : null,
                    'n'   => $n,
                ];
            }
        }

        // Ordenar por frecuencia total desc, luego alfabético.
        usort($acc, static function ($a, $b) {
            return $b['total'] <=> $a['total'] ?: strcmp($a['desc'], $b['desc']);
        });

        $items = [];
        foreach ($acc as $e) {
            $items[] = ['desc' => $e['desc'], 'dim' => $e['mejor']['dim'], 'm3' => $e['mejor']['m3']];
        }

        return ['items' => $items, 'total' => count($items), 'generado' => time()];
    }
}
