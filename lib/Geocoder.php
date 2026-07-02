<?php
declare(strict_types=1);

namespace Trazock;

use Trazock\Models\Destino;
use PDO;

// =============================================================================
// Geocoder — dirección → coordenada (lat/lng), con caché y driver intercambiable.
//
// FASE 1 de la secuenciación de rutas (feature D). Diseño agnóstico: el driver
// activo se elige por config (GEOCODER_DRIVER, default 'nominatim'); sumar Mapbox/
// Google más adelante es agregar una clase que implemente GeocoderDriver, sin
// tocar el resto.
//
// Reglas de uso:
//   - cache()    es seguro en el request del usuario: solo lee geo_direcciones.
//   - resolver() HACE HTTP (geocodifica) → llamarlo SOLO desde el proceso diferido
//     (scripts/geocodificar.php), nunca en caliente. Respeta el rate-limit el
//     llamador (el script pausa entre pedidos).
//
// La caché vive en la tabla geo_direcciones (una fila por dirección normalizada,
// reusando Destino::norm para la clave). `precision` dice con qué granularidad se
// resolvió: exacta (domicilio), localidad/provincia (centroide, fallback) o fallida.
// =============================================================================

/** Contrato de un proveedor de geocoding. */
interface GeocoderDriver
{
    /**
     * Geocodifica una consulta libre. Devuelve la mejor coincidencia o null.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $query): ?array;

    /** Identificador corto del proveedor (se guarda en geo_direcciones.fuente). */
    public function nombre(): string;
}

/**
 * Driver Nominatim / OpenStreetMap (gratis). Su licencia (ODbL) permite almacenar
 * las coordenadas, por eso encaja con nuestra caché sin letra chica. Política de
 * uso: máx ~1 req/seg y un User-Agent identificatorio (lo pausa/setea el llamador
 * y esta clase respectivamente).
 */
final class NominatimDriver implements GeocoderDriver
{
    private string $base;
    private string $userAgent;

    public function __construct()
    {
        $this->base = (defined('GEOCODER_NOMINATIM_URL') && GEOCODER_NOMINATIM_URL !== '')
            ? rtrim((string)GEOCODER_NOMINATIM_URL, '/')
            : 'https://nominatim.openstreetmap.org';
        // Nominatim EXIGE identificarse. Preferimos un UA de config (con mail de
        // contacto); si no, derivamos uno de APP_URL.
        $this->userAgent = (defined('GEOCODER_USER_AGENT') && GEOCODER_USER_AGENT !== '')
            ? (string)GEOCODER_USER_AGENT
            : ('Trazock/1.0 (' . (defined('APP_URL') ? (string)APP_URL : 'trazock') . ')');
    }

    public function nombre(): string
    {
        return 'nominatim';
    }

    public function geocode(string $query): ?array
    {
        $url = $this->base . '/search?' . http_build_query([
            'q'            => $query,
            'format'       => 'jsonv2',
            'limit'        => 1,
            'countrycodes' => 'ar',
            'addressdetails' => 0,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . $this->userAgent,
                'Accept: application/json',
            ],
        ]);
        // Mismo bundle CA que usa el OCR (dev/Windows). En Linux, sin definir, usa
        // el CA del sistema.
        if (defined('ANTHROPIC_CA_BUNDLE') && ANTHROPIC_CA_BUNDLE !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, ANTHROPIC_CA_BUNDLE);
        }
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            return null;
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || $data === [] || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }
}

final class Geocoder
{
    private static ?GeocoderDriver $driver = null;

    /** Driver activo según config (default: nominatim). */
    public static function driver(): GeocoderDriver
    {
        if (self::$driver === null) {
            $name = (defined('GEOCODER_DRIVER') && GEOCODER_DRIVER !== '')
                ? (string)GEOCODER_DRIVER
                : 'nominatim';
            self::$driver = match ($name) {
                // Al sumar Mapbox/Google: agregar el case aquí.
                default => new NominatimDriver(),
            };
        }
        return self::$driver;
    }

    /**
     * Clave de caché normalizada a partir de los componentes de la dirección
     * (reusa Destino::norm, así "Bs As" y "buenos  aires" caen en la misma clave).
     */
    public static function clave(?string $domicilio, ?string $localidad, ?string $provincia, ?string $cp): string
    {
        $partes = array_filter([
            Destino::norm((string)$domicilio),
            Destino::norm((string)$localidad),
            Destino::norm((string)$provincia),
            Destino::norm((string)$cp),
        ], static fn(string $s): bool => $s !== '');
        return implode('|', $partes);
    }

    /** Dirección legible (para geocodificar y para mostrar). */
    public static function texto(?string $domicilio, ?string $localidad, ?string $provincia, ?string $cp): string
    {
        $partes = array_filter([
            trim((string)$domicilio),
            trim((string)$localidad),
            trim((string)$provincia),
            trim((string)$cp),
        ], static fn(string $s): bool => $s !== '');
        return implode(', ', $partes);
    }

    /**
     * Lee la caché. SEGURO en el request del usuario (no geocodifica).
     *
     * @return array<string, mixed>|null
     */
    public static function cache(string $clave): ?array
    {
        if ($clave === '') {
            return null;
        }
        $st = DB::getInstance()->prepare('SELECT * FROM geo_direcciones WHERE clave_norm = :c LIMIT 1');
        $st->execute([':c' => $clave]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Resuelve una dirección: si está en caché la devuelve; si no, la GEOCODIFICA
     * (HTTP) con fallback en cascada (domicilio → localidad → provincia) y la
     * cachea. ¡Hace red! Usar solo desde el proceso diferido.
     *
     * @return array<string, mixed> Fila de geo_direcciones (con lat/lng/precision).
     */
    public static function resolver(?string $domicilio, ?string $localidad, ?string $provincia, ?string $cp): array
    {
        $clave = self::clave($domicilio, $localidad, $provincia, $cp);
        $cached = self::cache($clave);
        if ($cached !== null) {
            return $cached;
        }

        $driver   = self::driver();
        $intentos = [];
        if (Destino::norm((string)$domicilio) !== '' && Destino::norm((string)$localidad) !== '') {
            $intentos[] = ['exacta', self::texto($domicilio, $localidad, $provincia, $cp)];
        }
        if (Destino::norm((string)$localidad) !== '') {
            $intentos[] = ['localidad', self::texto(null, $localidad, $provincia, null)];
        }
        if (Destino::norm((string)$provincia) !== '') {
            $intentos[] = ['provincia', self::texto(null, null, $provincia, null)];
        }

        $lat = null;
        $lng = null;
        $precision = 'fallida';
        foreach ($intentos as [$prec, $q]) {
            $res = $driver->geocode($q . ', Argentina');
            if ($res !== null) {
                $lat = $res['lat'];
                $lng = $res['lng'];
                $precision = $prec;
                break;
            }
        }

        return self::guardar(
            $clave,
            self::texto($domicilio, $localidad, $provincia, $cp),
            $lat,
            $lng,
            $precision,
            $driver->nombre()
        );
    }

    /**
     * Upsert de una fila de caché. También lo usa la corrección manual del pin
     * (Fase 2), con precision='exacta' y fuente='manual'.
     *
     * @return array<string, mixed>
     */
    public static function guardar(
        string $clave,
        string $direccion,
        ?float $lat,
        ?float $lng,
        string $precision,
        string $fuente
    ): array {
        $st = DB::getInstance()->prepare(
            'INSERT INTO geo_direcciones (clave_norm, direccion, lat, lng, `precision`, fuente)
                  VALUES (:c, :d, :lat, :lng, :p, :f)
             ON DUPLICATE KEY UPDATE
                  direccion = VALUES(direccion), lat = VALUES(lat), lng = VALUES(lng),
                  `precision` = VALUES(`precision`), fuente = VALUES(fuente),
                  geocoded_at = CURRENT_TIMESTAMP'
        );
        $st->execute([
            ':c'   => $clave,
            ':d'   => $direccion,
            ':lat' => $lat,
            ':lng' => $lng,
            ':p'   => $precision,
            ':f'   => $fuente,
        ]);
        return self::cache($clave) ?? [];
    }

    /**
     * Direcciones DISTINTAS de las órdenes que todavía no están cacheadas (clave
     * vacía o repetida se descartan). Fuente única de "qué falta geocodificar",
     * usada por el script CLI y por el botón manual del panel.
     *
     * @return array<int, array<string, mixed>> filas con dest_domicilio/localidad/provincia/cp
     */
    public static function pendientes(): array
    {
        $rows = DB::getInstance()->query(
            "SELECT dest_domicilio, dest_localidad, dest_provincia, dest_cp
               FROM ordenes
              WHERE (dest_localidad IS NOT NULL AND dest_localidad <> '')
                 OR (dest_domicilio IS NOT NULL AND dest_domicilio <> '')
              GROUP BY dest_domicilio, dest_localidad, dest_provincia, dest_cp"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pend = [];
        foreach ($rows as $r) {
            $clave = self::clave($r['dest_domicilio'], $r['dest_localidad'], $r['dest_provincia'], $r['dest_cp']);
            if ($clave === '' || isset($pend[$clave])) {
                continue;
            }
            if (self::cache($clave) !== null) {
                continue; // ya geocodificada
            }
            $pend[$clave] = $r;
        }
        return array_values($pend);
    }

    /**
     * Geocodifica hasta $limite direcciones pendientes (0 = todas), pausando
     * $pausaMs entre pedidos (respeta el rate-limit). HACE RED. Devuelve stats.
     * El botón del panel lo llama con un tope chico para no colgar el request.
     *
     * @return array{pendientes:int, procesadas:int, exactas:int, aprox:int, fallidas:int, restantes:int}
     */
    public static function procesarPendientes(int $limite = 0, int $pausaMs = 1100): array
    {
        $pend       = self::pendientes();
        $pendientes = count($pend);
        $lote       = $limite > 0 ? array_slice($pend, 0, $limite) : $pend;
        $n          = count($lote);

        $exactas = 0;
        $aprox   = 0;
        $fallidas = 0;
        foreach ($lote as $i => $r) {
            $fila = self::resolver($r['dest_domicilio'], $r['dest_localidad'], $r['dest_provincia'], $r['dest_cp']);
            $prec = (string)($fila['precision'] ?? 'fallida');
            if ($prec === 'exacta') {
                $exactas++;
            } elseif ($prec === 'fallida') {
                $fallidas++;
            } else {
                $aprox++;
            }
            if ($i < $n - 1 && $pausaMs > 0) {
                usleep($pausaMs * 1000);
            }
        }

        return [
            'pendientes' => $pendientes,
            'procesadas' => $n,
            'exactas'    => $exactas,
            'aprox'      => $aprox,
            'fallidas'   => $fallidas,
            'restantes'  => max(0, $pendientes - $n),
        ];
    }
}
