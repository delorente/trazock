<?php
declare(strict_types=1);

// =============================================================================
// GET /api/remito-ver.php?uuid=<foto_uuid> — sirve la foto de un remito firmado.
//
// SOLO con login de panel (admin/gestor/logística): los remitos tienen firma y
// datos del cliente, no se exponen por URL pública. Streamea el archivo desde
// REMITOS_DIR validando que la ruta quede dentro de esa carpeta.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Models\EntregaRemito;

$user = Auth::validarSesion();
if ($user === null || !in_array($user['rol'], ['admin', 'gestor', 'logistica'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No autorizado.';
    exit;
}

$fotoUuid = trim((string)($_GET['uuid'] ?? ''));
$re = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
if (!preg_match($re, $fotoUuid)) {
    http_response_code(400);
    echo 'Identificador inválido.';
    exit;
}

$row = EntregaRemito::find($fotoUuid);
if ($row === null) {
    http_response_code(404);
    echo 'No encontrado.';
    exit;
}

$dir  = remitos_dir();
// basename: el archivo nunca sale de la carpeta de remitos.
$path = $dir . '/' . basename((string)$row['archivo']);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Archivo no disponible.';
    exit;
}

$mime = (string)($row['mime'] ?? 'application/octet-stream');
if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . basename((string)$row['archivo']) . '"');
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
