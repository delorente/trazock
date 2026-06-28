<?php
declare(strict_types=1);

// =============================================================================
// scripts/backfill-viaje-links.php — engancha los viajes (lotes) históricos a
// vehículos/acompañantes por COINCIDENCIA DE NOMBRE, ahora que existen
// lotes.vehiculo_id y la tabla lote_ayudantes (migración 017).
//
// Es best-effort e idempotente: solo completa lo que falta y loguea lo que no
// pudo enganchar (nombres sin match en el ABM). Re-ejecutarlo es seguro.
//
//   php scripts/backfill-viaje-links.php
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;

$db = DB::getInstance();

// --- Vehículos: lotes.vehiculo (nombre) → lotes.vehiculo_id ------------------
$vehMap = [];
foreach ($db->query('SELECT id, nombre FROM vehiculos')->fetchAll() as $v) {
    $vehMap[mb_strtolower(trim((string)$v['nombre']))] = (int)$v['id'];
}

$lotesVeh = $db->query(
    "SELECT id, vehiculo FROM lotes
     WHERE vehiculo IS NOT NULL AND vehiculo <> '' AND vehiculo_id IS NULL"
)->fetchAll();

$updVeh = $db->prepare('UPDATE lotes SET vehiculo_id = :vid WHERE id = :id');
$vehOk = 0; $vehMiss = [];
foreach ($lotesVeh as $l) {
    $key = mb_strtolower(trim((string)$l['vehiculo']));
    if (isset($vehMap[$key])) {
        $updVeh->execute([':vid' => $vehMap[$key], ':id' => (int)$l['id']]);
        $vehOk++;
    } else {
        $vehMiss[(string)$l['vehiculo']] = ((int)($vehMiss[(string)$l['vehiculo']] ?? 0)) + 1;
    }
}

// --- Ayudantes: lotes.ayudantes (nombres por coma) → lote_ayudantes ----------
$acompMap = [];
foreach ($db->query('SELECT id, nombre FROM acompanantes')->fetchAll() as $a) {
    $acompMap[mb_strtolower(trim((string)$a['nombre']))] = (int)$a['id'];
}

$lotesAyu = $db->query(
    "SELECT l.id, l.ayudantes FROM lotes l
     WHERE l.ayudantes IS NOT NULL AND l.ayudantes <> ''
       AND NOT EXISTS (SELECT 1 FROM lote_ayudantes la WHERE la.lote_id = l.id)"
)->fetchAll();

$insAyu = $db->prepare('INSERT IGNORE INTO lote_ayudantes (lote_id, acompanante_id) VALUES (:lote, :acomp)');
$ayuOk = 0; $ayuMiss = [];
foreach ($lotesAyu as $l) {
    foreach (explode(',', (string)$l['ayudantes']) as $nombre) {
        $key = mb_strtolower(trim($nombre));
        if ($key === '') { continue; }
        if (isset($acompMap[$key])) {
            $insAyu->execute([':lote' => (int)$l['id'], ':acomp' => $acompMap[$key]]);
            $ayuOk++;
        } else {
            $ayuMiss[trim($nombre)] = ((int)($ayuMiss[trim($nombre)] ?? 0)) + 1;
        }
    }
}

echo "Vehículos enganchados: $vehOk\n";
if ($vehMiss !== []) {
    echo "  Sin match (revisar / crear en ABM Vehículos):\n";
    foreach ($vehMiss as $n => $c) { echo "   - «{$n}» ({$c} lote/s)\n"; }
}
echo "Ayudantes enganchados: $ayuOk\n";
if ($ayuMiss !== []) {
    echo "  Sin match (revisar / crear en ABM Acompañantes):\n";
    foreach ($ayuMiss as $n => $c) { echo "   - «{$n}» ({$c} vez/veces)\n"; }
}
echo "Listo.\n";
