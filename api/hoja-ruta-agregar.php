<?php
declare(strict_types=1);

// =============================================================================
// POST /api/hoja-ruta-agregar.php — agrega órdenes seleccionadas en Reportes a una
// hoja de ruta (nueva o abierta). admin/logística + CSRF.
//   Body JSON: {csrf_token, ids:[int,...], hoja_id:int(0=nueva)}
//   → {ok, hoja_id, numero, agregadas, ya_estaban}
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

$hojaId = (int)($data['hoja_id'] ?? 0);
if ($hojaId > 0) {
    $hoja = HojaRuta::find($hojaId);
    if ($hoja === null) {
        Api::error('La hoja de ruta no existe.', 404);
    }
    if ($hoja['estado'] !== 'abierta') {
        Api::error('La hoja está emitida; reabrila para agregarle órdenes.', 409);
    }
} else {
    $hojaId = HojaRuta::crear((int)$user['id']);
    $hoja   = HojaRuta::find($hojaId);
}

$agregadas = 0;
$ya        = 0;
foreach ($ids as $id) {
    if (Orden::find($id) === null) {
        continue;
    }
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
]);
