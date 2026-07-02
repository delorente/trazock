<?php
declare(strict_types=1);

// =============================================================================
// scripts/geocodificar.php — geocodifica (diferido) las direcciones de destino
// que todavía no están en la caché geo_direcciones. FASE 1 de la feature D.
//
//   php scripts/geocodificar.php                 # procesa TODO lo pendiente
//   php scripts/geocodificar.php --limit=50       # solo las primeras 50
//   php scripts/geocodificar.php --pausa=1500     # 1500 ms entre pedidos
//
// Idempotente: lo ya cacheado se saltea, así que re-ejecutarlo es seguro y barato.
// Respeta el rate-limit del proveedor pausando entre pedidos (default 1100 ms,
// acorde a la política de ~1 req/seg de Nominatim). Pensado para correr a mano o
// por cron; NUNCA se llama desde una página (geocodificar en caliente está vetado).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;
use Trazock\Geocoder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limite  = 0;
$pausaMs = 1100;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limite = (int)$m[1];
    } elseif (preg_match('/^--pausa=(\d+)$/', $arg, $m)) {
        $pausaMs = (int)$m[1];
    } else {
        fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
        exit(1);
    }
}

$db = DB::getInstance();

// Direcciones DISTINTAS de las órdenes (con al menos localidad o domicilio). El
// GROUP BY colapsa las repetidas; la caché las deduplica una vez más por clave.
$rows = $db->query(
    "SELECT dest_domicilio, dest_localidad, dest_provincia, dest_cp
       FROM ordenes
      WHERE (dest_localidad IS NOT NULL AND dest_localidad <> '')
         OR (dest_domicilio IS NOT NULL AND dest_domicilio <> '')
      GROUP BY dest_domicilio, dest_localidad, dest_provincia, dest_cp"
)->fetchAll();

$pendientes = [];
foreach ($rows as $r) {
    $clave = Geocoder::clave($r['dest_domicilio'], $r['dest_localidad'], $r['dest_provincia'], $r['dest_cp']);
    if ($clave === '' || isset($pendientes[$clave])) {
        continue;
    }
    if (Geocoder::cache($clave) !== null) {
        continue; // ya geocodificada
    }
    $pendientes[$clave] = $r;
}
$pendientes = array_values($pendientes);
$totalPend  = count($pendientes);

if ($limite > 0) {
    $pendientes = array_slice($pendientes, 0, $limite);
}
$aProcesar = count($pendientes);

echo "Direcciones sin geocodificar: {$totalPend}"
    . ($limite > 0 && $aProcesar < $totalPend ? " (proceso {$aProcesar} en esta corrida)" : '') . "\n";
echo 'Driver: ' . Geocoder::driver()->nombre() . " · pausa {$pausaMs} ms entre pedidos\n";

if ($aProcesar === 0) {
    echo "Nada pendiente. ✓\n";
    exit(0);
}
echo "\n";

$exactas = 0;
$aprox   = 0;
$fallidas = 0;
foreach ($pendientes as $i => $r) {
    $fila = Geocoder::resolver($r['dest_domicilio'], $r['dest_localidad'], $r['dest_provincia'], $r['dest_cp']);
    $prec = (string)($fila['precision'] ?? 'fallida');
    $dir  = (string)($fila['direccion'] ?? '');

    if ($prec === 'exacta') {
        $exactas++;
    } elseif ($prec === 'fallida') {
        $fallidas++;
    } else {
        $aprox++;
    }
    printf("[%d/%d] %-10s %s\n", $i + 1, $aProcesar, $prec, $dir);

    if ($i < $aProcesar - 1 && $pausaMs > 0) {
        usleep($pausaMs * 1000);
    }
}

echo "\nListo. Exactas: {$exactas} · aproximadas: {$aprox} · fallidas: {$fallidas}\n";
