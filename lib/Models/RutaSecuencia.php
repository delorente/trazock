<?php
declare(strict_types=1);

namespace Trazock\Models;

use Trazock\DB;
use Trazock\Geocoder;
use PDO;

/**
 * RutaSecuencia — orden de las paradas de una hoja de ruta y su coordenada
 * efectiva, para la pantalla de planificación de recorrido (feature D, fase 2).
 *
 * Vive en la tabla nueva ruta_secuencia (una fila por parada: orden del sistema o
 * línea manual), SIN tocar hoja_ruta_ordenes / hoja_ruta_manual. La coordenada de
 * cada parada sale de:
 *   1) el override manual (pin arrastrado) guardado en ruta_secuencia.lat/lng, o
 *   2) la caché de geocoding (geo_direcciones), resuelta por Geocoder::clave.
 * `posicion` es el orden del recorrido; las paradas sin ubicar se muestran al final.
 */
final class RutaSecuencia
{
    /** Velocidad media estimada (km/h) para traducir distancia a tiempo. */
    public const VEL_KMH = 32.0;

    /**
     * Paradas de la hoja, ordenadas (ubicadas por posición, sin ubicar al final),
     * con coordenada, precisión y los datos que muestra la UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paradas(int $hojaId): array
    {
        $db = DB::getInstance();

        // Órdenes del sistema (con domicilio/cp para la clave de geocoding).
        $st = $db->prepare(
            "SELECT o.id, o.nro_orden, o.cliente, o.cliente_apellido, o.telefonos,
                    o.dest_domicilio, o.dest_localidad, o.dest_provincia, o.dest_cp, o.m3_total,
                    (SELECT COUNT(*) FROM productos p WHERE p.orden_id = o.id) AS bultos
               FROM hoja_ruta_ordenes ho JOIN ordenes o ON o.id = ho.orden_id
              WHERE ho.hoja_id = :h"
        );
        $st->execute([':h' => $hojaId]);
        $ordenes = $st->fetchAll(PDO::FETCH_ASSOC);

        // Líneas manuales (solo tienen localidad como dato geográfico).
        $st = $db->prepare('SELECT * FROM hoja_ruta_manual WHERE hoja_id = :h');
        $st->execute([':h' => $hojaId]);
        $manuales = $st->fetchAll(PDO::FETCH_ASSOC);

        // Secuencia guardada + override de pin, indexada por "tipo:ref_id".
        $st = $db->prepare('SELECT tipo, ref_id, posicion, lat, lng, override_manual FROM ruta_secuencia WHERE hoja_id = :h');
        $st->execute([':h' => $hojaId]);
        $seq = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $seq[$s['tipo'] . ':' . (int)$s['ref_id']] = $s;
        }

        $paradas = [];
        foreach ($ordenes as $o) {
            $nombre = trim((string)$o['cliente'] . ' ' . (string)($o['cliente_apellido'] ?? ''));
            $paradas[] = self::armarParada(
                'orden',
                (int)$o['id'],
                $seq,
                $nombre,
                (string)$o['nro_orden'],
                (string)($o['dest_localidad'] ?? ''),
                (int)$o['bultos'],
                (float)($o['m3_total'] ?? 0),
                self::primerTelefono((string)($o['telefonos'] ?? '')),
                Geocoder::clave($o['dest_domicilio'], $o['dest_localidad'], $o['dest_provincia'], $o['dest_cp'])
            );
        }
        foreach ($manuales as $m) {
            $paradas[] = self::armarParada(
                'manual',
                (int)$m['id'],
                $seq,
                (string)($m['cliente_destino'] ?? ''),
                (string)($m['nro_orden'] ?? ''),
                (string)($m['localidad'] ?? ''),
                (int)($m['bultos'] ?? 0),
                (float)($m['m3'] ?? 0),
                (string)($m['telefono'] ?? ''),
                Geocoder::clave(null, $m['localidad'] ?? null, null, null)
            );
        }

        // Ubicadas primero (por posición), sin ubicar al final.
        usort($paradas, static function (array $a, array $b): int {
            if ($a['ubicada'] !== $b['ubicada']) {
                return $a['ubicada'] ? -1 : 1;
            }
            if ($a['posicion'] !== $b['posicion']) {
                return $a['posicion'] <=> $b['posicion'];
            }
            return strcmp((string)$a['nro_orden'], (string)$b['nro_orden']);
        });

        $n = 1;
        foreach ($paradas as &$p) {
            $p['num'] = $n++;
        }
        unset($p);

        return $paradas;
    }

    /**
     * Arma una parada resolviendo su coordenada efectiva (override manual → caché
     * de geocoding) y su precisión.
     *
     * @param array<string, array<string, mixed>> $seq
     * @return array<string, mixed>
     */
    private static function armarParada(
        string $tipo,
        int $refId,
        array $seq,
        string $cliente,
        string $nro,
        string $localidad,
        int $bultos,
        float $m3,
        string $telefono,
        string $clave
    ): array {
        $s = $seq[$tipo . ':' . $refId] ?? null;

        $lat = null;
        $lng = null;
        $precision = 'fallida';

        if ($s !== null && (int)$s['override_manual'] === 1 && $s['lat'] !== null && $s['lng'] !== null) {
            $lat = (float)$s['lat'];
            $lng = (float)$s['lng'];
            $precision = 'exacta';
        } elseif ($clave !== '') {
            $geo = Geocoder::cache($clave);
            if ($geo !== null && $geo['lat'] !== null && $geo['lng'] !== null) {
                $lat = (float)$geo['lat'];
                $lng = (float)$geo['lng'];
                $precision = (string)$geo['precision'];
            }
        }

        $ubicada = $lat !== null && $lng !== null && $precision !== 'fallida';

        return [
            'tipo'      => $tipo,
            'ref_id'    => $refId,
            'cliente'   => $cliente !== '' ? $cliente : '—',
            'nro_orden' => $nro,
            'localidad' => $localidad,
            'bultos'    => $bultos,
            'm3'        => $m3,
            'telefono'  => $telefono,
            'lat'       => $lat,
            'lng'       => $lng,
            'precision' => $precision,
            'ubicada'   => $ubicada,
            'posicion'  => $s !== null ? (int)$s['posicion'] : 0,
        ];
    }

    /** Primer teléfono de una lista separada por coma/;// */
    private static function primerTelefono(string $tels): string
    {
        $parts = preg_split('/[,;\/]+/', $tels) ?: [];
        return trim((string)($parts[0] ?? ''));
    }

    /**
     * Guarda el orden de las paradas. Solo escribe `posicion` (preserva lat/lng y
     * override del pin). Cada item es "tipo:ref_id" en el orden deseado.
     *
     * @param array<int, string> $orden
     */
    public static function guardarOrden(int $hojaId, array $orden): void
    {
        $st = DB::getInstance()->prepare(
            'INSERT INTO ruta_secuencia (hoja_id, tipo, ref_id, posicion)
                  VALUES (:h, :t, :r, :p)
             ON DUPLICATE KEY UPDATE posicion = VALUES(posicion)'
        );
        $pos = 1;
        foreach ($orden as $item) {
            [$tipo, $ref] = array_pad(explode(':', (string)$item, 2), 2, '');
            $tipo = $tipo === 'manual' ? 'manual' : 'orden';
            $refId = (int)$ref;
            if ($refId <= 0) {
                continue;
            }
            $st->execute([':h' => $hojaId, ':t' => $tipo, ':r' => $refId, ':p' => $pos]);
            $pos++;
        }
    }

    /**
     * Corrige la coordenada de una parada (pin arrastrado a mano). Marca
     * override_manual=1 para que no se re-geocodifique ni se pise con la caché.
     */
    public static function setPin(int $hojaId, string $tipo, int $refId, float $lat, float $lng): void
    {
        $tipo = $tipo === 'manual' ? 'manual' : 'orden';
        DB::getInstance()->prepare(
            'INSERT INTO ruta_secuencia (hoja_id, tipo, ref_id, lat, lng, override_manual)
                  VALUES (:h, :t, :r, :lat, :lng, 1)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), override_manual = 1'
        )->execute([':h' => $hojaId, ':t' => $tipo, ':r' => $refId, ':lat' => $lat, ':lng' => $lng]);
    }

    /**
     * Sugerencia de orden: heurística nearest-neighbor desde el depósito (config
     * DEPOT_LAT/DEPOT_LNG) o, si no está configurado, desde la primera parada
     * ubicada. Las paradas sin ubicar quedan al final. Persiste el orden.
     */
    public static function optimizar(int $hojaId): void
    {
        $paradas   = self::paradas($hojaId);
        $ubicadas  = array_values(array_filter($paradas, static fn(array $p): bool => (bool)$p['ubicada']));
        $sinUbicar = array_values(array_filter($paradas, static fn(array $p): bool => !$p['ubicada']));

        if ($ubicadas === []) {
            return; // nada geolocalizado que ordenar
        }

        if (defined('DEPOT_LAT') && defined('DEPOT_LNG') && DEPOT_LAT !== '' && DEPOT_LNG !== '') {
            $curLat = (float)DEPOT_LAT;
            $curLng = (float)DEPOT_LNG;
        } else {
            $curLat = (float)$ubicadas[0]['lat'];
            $curLng = (float)$ubicadas[0]['lng'];
        }

        $ordenadas = [];
        $pool = $ubicadas;
        while ($pool !== []) {
            $mejor  = 0;
            $mejorD = PHP_FLOAT_MAX;
            foreach ($pool as $i => $p) {
                $d = self::haversine($curLat, $curLng, (float)$p['lat'], (float)$p['lng']);
                if ($d < $mejorD) {
                    $mejorD = $d;
                    $mejor  = $i;
                }
            }
            $sel = $pool[$mejor];
            $ordenadas[] = $sel;
            $curLat = (float)$sel['lat'];
            $curLng = (float)$sel['lng'];
            array_splice($pool, $mejor, 1);
        }

        $final = array_merge($ordenadas, $sinUbicar);
        $orden = array_map(static fn(array $p): string => $p['tipo'] . ':' . $p['ref_id'], $final);
        self::guardarOrden($hojaId, $orden);
    }

    /** Distancia en km entre dos coordenadas (Haversine). */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
