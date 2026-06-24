<?php
declare(strict_types=1);

// =============================================================================
// POST /api/ordenes-confirmar.php — guarda el borrador editado y confirma la carga
// (materializa ordenes + productos vía ProcesadorCarga). Requiere admin/gestor + CSRF.
//   Body JSON: {csrf_token, carga_id, datos}  (datos = JSON {ordenes:[...]})
//   → {ok, creadas, items, omitidas}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Models\Carga;
use Trazock\ProcesadorCarga;

Api::exigirMetodo('POST');
$data = Api::leerJson();
Api::usuarioConRol(['admin', 'gestor']);
Api::exigirCSRF($data);

$cargaId = (int)($data['carga_id'] ?? 0);
$datos   = (string)($data['datos'] ?? '');

if ($cargaId <= 0) {
    Api::error('Carga inválida.', 400);
}
$carga = Carga::find($cargaId);
if ($carga === null) {
    Api::error('Carga no encontrada.', 404);
}
if ($carga['estado'] === 'confirmada') {
    Api::error('La carga ya fue confirmada.', 400);
}

// Validar que el JSON editado sea coherente antes de guardar.
$decoded = json_decode($datos, true);
if (!is_array($decoded) || !isset($decoded['ordenes']) || !is_array($decoded['ordenes'])) {
    Api::error('Los datos a confirmar no son válidos.', 400);
}

// La hoja de ruta es obligatoria por orden (dato clave de ingreso). Defensa en
// el servidor además del bloqueo en la planilla de revisión.
$sinHR = 0;
foreach ($decoded['ordenes'] as $o) {
    if (trim((string)($o['hoja_ruta'] ?? '')) === '') {
        $sinHR++;
    }
}
if ($sinHR > 0) {
    Api::error("Faltan {$sinHR} hoja(s) de ruta. Completá el Nº de hoja de ruta de cada orden antes de confirmar.", 400);
}

Carga::guardarDatos($cargaId, $datos);

try {
    $res = ProcesadorCarga::confirmar($cargaId);
} catch (Throwable $e) {
    error_log('ordenes-confirmar: ' . $e->getMessage());
    Api::error('No se pudo confirmar: ' . $e->getMessage(), 500);
}

Api::json([
    'ok'       => true,
    'creadas'  => $res['creadas'],
    'items'    => $res['items'],
    'omitidas' => $res['omitidas'],
]);
