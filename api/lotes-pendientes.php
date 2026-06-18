<?php
declare(strict_types=1);

// =============================================================================
// GET /api/lotes-pendientes.php?uuid=... — recovery.
// El cliente que sospecha haber perdido la respuesta de un lote consulta por uuid
// si ya fue procesado y con qué resultado. Si no existe, procesado=false.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Models\Lote;

Api::exigirMetodo('GET');

$user = Api::usuarioAutenticado();
$uuid = trim((string)($_GET['uuid'] ?? ''));

if ($uuid === '') {
    Api::error('Falta el parámetro uuid.', 400);
}

$lote = Lote::findByUuid($uuid);

if ($lote === null) {
    Api::json(['ok' => true, 'procesado' => false, 'uuid' => $uuid], 200);
}

$resumen = Lote::resumen((int)$lote['id']);
Api::json(['ok' => true, 'procesado' => true, 'uuid' => $uuid] + $resumen, 200);
