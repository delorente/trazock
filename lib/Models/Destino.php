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
     * Las 24 jurisdicciones argentinas, en su forma canónica (mayúsculas, con
     * acentos). Son un conjunto CERRADO: sirven de ancla para completar destinos
     * truncados y para separar los "pegados" (localidad+provincia), incluso cuando
     * todavía no hay historial cargado.
     *
     * @return array<int, string>
     */
    public static function provinciasArgentinas(): array
    {
        return [
            'BUENOS AIRES', 'CIUDAD AUTÓNOMA DE BUENOS AIRES', 'CATAMARCA', 'CHACO',
            'CHUBUT', 'CÓRDOBA', 'CORRIENTES', 'ENTRE RÍOS', 'FORMOSA', 'JUJUY',
            'LA PAMPA', 'LA RIOJA', 'MENDOZA', 'MISIONES', 'NEUQUÉN', 'RÍO NEGRO',
            'SALTA', 'SAN JUAN', 'SAN LUIS', 'SANTA CRUZ', 'SANTA FE',
            'SANTIAGO DEL ESTERO', 'TIERRA DEL FUEGO', 'TUCUMÁN',
        ];
    }

    /**
     * Diccionario para autocompletar/separar destinos en la Revisión OCR, armado
     * con datos que ya tenemos: las 24 provincias (∪ variantes tipeadas en la
     * base) y las localidades conocidas (zonas de reparto + histórico de órdenes),
     * cada una con su provincia más frecuente.
     *
     * @return array{provincias: array<int,string>, localidades: array<int, array{localidad:string, provincia:string}>}
     */
    public static function diccionarioDestinos(): array
    {
        $db = DB::getInstance();

        // Provincias: canónicas primero; sumamos variantes de la base que no estén.
        $provs = [];
        foreach (self::provinciasArgentinas() as $p) {
            $provs[self::norm($p)] = $p;
        }
        $rows = $db->query(
            "SELECT provincia AS p FROM zona_localidades WHERE provincia IS NOT NULL AND provincia <> ''
             UNION
             SELECT dest_provincia AS p FROM ordenes WHERE dest_provincia IS NOT NULL AND dest_provincia <> ''"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $p) {
            $n = self::norm((string)$p);
            if ($n !== '' && !isset($provs[$n])) {
                $provs[$n] = (string)$p;
            }
        }

        // Localidades → provincia. Acumulamos frecuencia por (localidad, provincia)
        // para quedarnos con la provincia más habitual de cada localidad. La casing
        // de la provincia se canoniza contra la lista de arriba cuando coincide.
        $acc = []; // normLoc => ['loc'=>canonLoc, 'provs'=>[normProv => ['prov'=>canon,'n'=>peso]]]
        $agregar = static function (string $loc, string $prov, int $peso) use (&$acc, $provs): void {
            $nl = Destino::norm($loc);
            if ($nl === '') { return; }
            if (!isset($acc[$nl])) { $acc[$nl] = ['loc' => trim($loc), 'provs' => []]; }
            $prov = trim($prov);
            if ($prov === '') { return; }
            $npv = Destino::norm($prov);
            $canon = $provs[$npv] ?? $prov; // usa la forma canónica si la provincia es conocida
            if (!isset($acc[$nl]['provs'][$npv])) { $acc[$nl]['provs'][$npv] = ['prov' => $canon, 'n' => 0]; }
            $acc[$nl]['provs'][$npv]['n'] += $peso;
        };

        foreach ($db->query(
            "SELECT ciudad, provincia FROM zona_localidades WHERE ciudad IS NOT NULL AND ciudad <> ''"
        )->fetchAll() as $r) {
            $agregar((string)$r['ciudad'], (string)($r['provincia'] ?? ''), 2); // zonas pesan un poco más
        }
        foreach ($db->query(
            "SELECT dest_localidad AS l, dest_provincia AS p, COUNT(*) AS n
               FROM ordenes
              WHERE dest_localidad IS NOT NULL AND dest_localidad <> ''
              GROUP BY dest_localidad, dest_provincia"
        )->fetchAll() as $r) {
            $agregar((string)$r['l'], (string)($r['p'] ?? ''), (int)$r['n']);
        }

        $localidades = [];
        foreach ($acc as $e) {
            $mejor = '';
            $max = -1;
            foreach ($e['provs'] as $pv) {
                if ($pv['n'] > $max) { $max = $pv['n']; $mejor = $pv['prov']; }
            }
            $localidades[] = ['localidad' => $e['loc'], 'provincia' => $mejor];
        }

        return ['provincias' => array_values($provs), 'localidades' => $localidades];
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
