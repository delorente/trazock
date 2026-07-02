<?php
declare(strict_types=1);

// =============================================================================
// GET /api/documento-ver.php?uuid=<uuid> — sirve un documento importado (hoja
// resumen original: imagen/PDF). SOLO con login de panel (admin/gestor/logística).
// Streamea el archivo desde DOCUMENTOS_DIR validando que no salga de la carpeta.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Models\CargaDocumento;

$user = Auth::validarSesion();
if ($user === null || !in_array($user['rol'], ['admin', 'gestor', 'logistica'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No autorizado.';
    exit;
}

$uuid = trim((string)($_GET['uuid'] ?? ''));
$re = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
if (!preg_match($re, $uuid)) {
    http_response_code(400);
    echo 'Identificador inválido.';
    exit;
}

$row = CargaDocumento::find($uuid);
if ($row === null) {
    http_response_code(404);
    echo 'No encontrado.';
    exit;
}

$dir  = documentos_dir();
// basename: el archivo nunca sale de la carpeta de documentos.
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
