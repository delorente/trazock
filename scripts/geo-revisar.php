<?php
declare(strict_types=1);

// =============================================================================
// scripts/geo-revisar.php — lista las órdenes cuya dirección NO quedó geocodificada
// a nivel calle (amarillas/rojas), con su Nº de orden y la dirección, para poder
// revisarlas y corregir la carga. Solo lectura (no geocodifica).
//
//   php scripts/geo-revisar.php                # todas las no-exactas (amarillas+rojas)
//   php scripts/geo-revisar.php --solo-rojas   # solo las que no ubicó (fallida/sin geocodificar)
//   php scripts/geo-revisar.php --limit=100    # corta el listado
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;
use Trazock\Geocoder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$soloRojas = false;
$limite    = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--solo-rojas') {
        $soloRojas = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limite = (int)$m[1];
    } else {
        fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
        exit(1);
    }
}

$db = DB::getInstance();

// Precisión cacheada por clave (una sola query; luego se cruza en PHP).
$precPorClave = [];
foreach ($db->query("SELECT clave_norm, `precision` FROM geo_direcciones")->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $precPorClave[(string)$g['clave_norm']] = (string)$g['precision'];
}

// Órdenes con dirección (en depósito o no; el geocode es de la dirección).
$ordenes = $db->query(
    "SELECT id, nro_orden, dest_domicilio, dest_localidad, dest_provincia, dest_cp
       FROM ordenes
      WHERE (dest_localidad IS NOT NULL AND dest_localidad <> '')
         OR (dest_domicilio IS NOT NULL AND dest_domicilio <> '')
      ORDER BY dest_provincia, dest_localidad, nro_orden"
)->fetchAll(PDO::FETCH_ASSOC);

// Clasifica cada orden por precisión de su dirección.
$rojas    = []; // fallida o sin geocodificar → no ubicada
$amarillas = []; // solo localidad
foreach ($ordenes as $o) {
    $clave = Geocoder::clave($o['dest_domicilio'], $o['dest_localidad'], $o['dest_provincia'], $o['dest_cp']);
    if ($clave === '') {
        $prec = 'sin-direccion';
    } else {
        $prec = $precPorClave[$clave] ?? 'sin-geocodificar';
    }
    if ($prec === 'exacta') {
        continue;
    }
    $fila = [
        'nro'  => (string)$o['nro_orden'],
        'dir'  => Geocoder::texto($o['dest_domicilio'], $o['dest_localidad'], $o['dest_provincia'], $o['dest_cp']),
        'prec' => $prec,
    ];
    if ($prec === 'localidad') {
        $amarillas[] = $fila;
    } else {
        $rojas[] = $fila;
    }
}

$imprimir = static function (string $titulo, array $filas) use ($limite): void {
    echo "\n=== {$titulo} (" . count($filas) . ") ===\n";
    $n = 0;
    foreach ($filas as $f) {
        if ($limite > 0 && $n >= $limite) {
            echo "  … (" . (count($filas) - $limite) . " más; usá --limit para ver más)\n";
            break;
        }
        printf("  %-16s %-14s %s\n", $f['nro'], '[' . $f['prec'] . ']', $f['dir']);
        $n++;
    }
};

echo "Órdenes con dirección: " . count($ordenes)
    . " · sin ubicar (rojas): " . count($rojas)
    . " · solo localidad (amarillas): " . count($amarillas) . "\n";

$imprimir('SIN UBICAR (revisar dirección: sin número, S/N, por lote, o mal escrita)', $rojas);
if (!$soloRojas) {
    $imprimir('SOLO LOCALIDAD (ubicadas en el centro del pueblo, sin calle+altura exacta)', $amarillas);
}
