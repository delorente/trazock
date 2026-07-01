<?php
declare(strict_types=1);

// =============================================================================
// POST /api/hoja-ruta-agregar.php — agrega órdenes seleccionadas en Reportes a una
// hoja de ruta (nueva o abierta). admin/logística + CSRF.
//   Body JSON: {csrf_token, ids:[int,...], hoja_id:int(0=nueva), forzar:bool}
//   → {ok, hoja_id, numero, agregadas, ya_estaban, pendientes:[{id,nro,estado}]}
//
// Control: solo se agregan libremente órdenes EN DEPÓSITO (estado RECIBIDO o
// REINGRESADO). Las demás vuelven en `pendientes` y solo se agregan con forzar=true
// (para armar hojas de ruta viejas y dejarlas asentadas).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Models\HojaRuta;
use Trazock\Models\Orden;

Api::exigirMetodo('POST');
$data = Api::leerJson();
$user = Api::usuarioConRol(['admin', 'logistica']);
Api::exigirCSRF($data);

$ids = array_values(array_unique(array_filter(
    array_map(static fn($v) => (int)$v, (array)($data['ids'] ?? [])),
    static fn(int $v): bool => $v > 0
)));
if ($ids === []) {
    Api::error('No seleccionaste ninguna orden.');
}
if (count($ids) > 500) {
    Api::error('Demasiadas órdenes en una sola tanda (máximo 500).');
}

$forzar     = !empty($data['forzar']);
$hojaIdIn   = (int)($data['hoja_id'] ?? 0);
$hoja       = null;

if ($hojaIdIn > 0) {
    $hoja = HojaRuta::find($hojaIdIn);
    if ($hoja === null) {
        Api::error('La hoja de ruta no existe.', 404);
    }
    if ($hoja['estado'] !== 'abierta') {
        Api::error('La hoja está emitida; reabrila para agregarle órdenes.', 409);
    }
}

// Clasificar: en depósito (se agregan) vs fuera de depósito (piden confirmación).
$enDeposito = ['RECIBIDO', 'REINGRESADO'];
$aAgregar   = [];
$pendientes = [];
foreach ($ids as $id) {
    $o = Orden::find($id);
    if ($o === null) {
        continue;
    }
    if ($forzar || in_array((string)$o['estado'], $enDeposito, true)) {
        $aAgregar[] = $id;
    } else {
        $pendientes[] = ['id' => $id, 'nro' => (string)$o['nro_orden'], 'estado' => (string)$o['estado']];
    }
}

// Si no hay nada para agregar ahora (todo pendiente), no creamos la hoja todavía.
if ($aAgregar === [] && !$forzar) {
    Api::json([
        'ok'         => true,
        'hoja_id'    => $hojaIdIn > 0 ? $hojaIdIn : 0,
        'numero'     => $hoja !== null ? (string)$hoja['numero'] : '',
        'agregadas'  => 0,
        'ya_estaban' => 0,
        'pendientes' => $pendientes,
    ]);
}

// Resolver/crear la hoja.
if ($hoja === null) {
    $hojaId = HojaRuta::crear((int)$user['id']);
    $hoja   = HojaRuta::find($hojaId);
} else {
    $hojaId = (int)$hoja['id'];
}

$agregadas = 0;
$ya        = 0;
foreach ($aAgregar as $id) {
    if (HojaRuta::agregarOrden($hojaId, $id)) {
        $agregadas++;
    } else {
        $ya++;
    }
}

Api::json([
    'ok'         => true,
    'hoja_id'    => $hojaId,
    'numero'     => (string)($hoja['numero'] ?? ''),
    'agregadas'  => $agregadas,
    'ya_estaban' => $ya,
    'pendientes' => $pendientes,
]);
