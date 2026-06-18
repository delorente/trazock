<?php
declare(strict_types=1);

// =============================================================================
// POST /api/lote-enviar.php — recibe un lote completo y lo procesa server-side.
// Requiere sesión. Valida rol vs tipo. Idempotente por uuid (R1).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\LoteException;
use Trazock\ProcesadorLote;

Api::exigirMetodo('POST');

$user     = Api::usuarioAutenticado();
$loteData = Api::leerJson();

try {
    $resultado = ProcesadorLote::procesarLote($loteData, $user);
    Api::json($resultado, 200);
} catch (LoteException $e) {
    Api::error($e->getMessage(), $e->httpStatus);
}
