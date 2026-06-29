<?php
declare(strict_types=1);

// =============================================================================
// POST /api/remito-subir.php — recibe la foto de un remito firmado (entrega).
//
// Multipart (campo `foto`) + foto_uuid + lote_uuid. Lo usa la PWA de escaneo
// (sesión, mismo criterio que lote-enviar.php: sin CSRF). Idempotente por
// foto_uuid. Guarda el archivo en REMITOS_DIR (carpeta única) con un nombre
// buscable (fecha + nº de orden + id corto) y registra los metadatos.
//   → {ok, foto_uuid, archivo}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\DB;
use Trazock\Models\EntregaRemito;

Api::exigirMetodo('POST');
$user = Api::usuarioConRol(['admin', 'operador', 'transportista', 'logistica']);

$fotoUuid = trim((string)($_POST['foto_uuid'] ?? ''));
$loteUuid = trim((string)($_POST['lote_uuid'] ?? ''));
$re = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
if (!preg_match($re, $fotoUuid) || !preg_match($re, $loteUuid)) {
    Api::error('Identificadores inválidos.');
}

// Idempotencia: si ya se subió esa foto, listo (reintento del cliente).
if (EntregaRemito::existe($fotoUuid)) {
    Api::json(['ok' => true, 'foto_uuid' => $fotoUuid, 'duplicado' => true]);
}

$f = $_FILES['foto'] ?? null;
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
    Api::error('No llegó la foto o hubo un error en la subida.');
}

$bytes = (int)($f['size'] ?? 0);
if ($bytes <= 0 || $bytes > 12 * 1024 * 1024) {
    Api::error('La foto está vacía o supera el máximo (12 MB).');
}

// Validar que sea una imagen real (no confiar en la extensión).
$tmp  = (string)$f['tmp_name'];
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
$ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
if ($ext === null) {
    Api::error('El archivo no es una imagen válida (JPG/PNG/WebP).');
}

// Nombre buscable: fecha + nº de orden representativo del lote (si ya existe) + id corto.
$nroOrden = '';
try {
    $st = DB::getInstance()->prepare(
        'SELECT o.nro_orden
           FROM lotes l
           JOIN transiciones t ON t.lote_id = l.id
           JOIN productos p    ON p.id = t.producto_id
           JOIN ordenes o      ON o.id = p.orden_id
          WHERE l.uuid = :u LIMIT 1'
    );
    $st->execute([':u' => $loteUuid]);
    $nroOrden = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) { /* nombre sin nº de orden */ }

$slug = $nroOrden !== '' ? preg_replace('/[^A-Za-z0-9_-]+/', '', $nroOrden) : 'entrega';
$nombre = date('Y-m-d') . '_' . substr((string)$slug, 0, 30) . '_' . substr($fotoUuid, 0, 8) . '.' . $ext;

$dir  = remitos_dir();
$dest = $dir . '/' . $nombre;
if (!is_dir($dir) || !is_writable($dir)) {
    error_log('remito-subir.php: carpeta no escribible: ' . $dir);
    Api::error('No se pudo guardar la imagen (almacenamiento no disponible).', 500);
}

$sha = hash_file('sha256', $tmp) ?: null;
if (!move_uploaded_file($tmp, $dest)) {
    Api::error('No se pudo guardar la imagen.', 500);
}

try {
    EntregaRemito::registrar($fotoUuid, $loteUuid, $nombre, $mime, $bytes, $sha, (int)$user['id']);
} catch (Throwable $e) {
    // Carrera contra el UNIQUE (doble subida simultánea): la tratamos como ok.
    if (!EntregaRemito::existe($fotoUuid)) {
        @unlink($dest);
        error_log('remito-subir.php: ' . $e->getMessage());
        Api::error('No se pudo registrar el remito.', 500);
    }
}

Api::json(['ok' => true, 'foto_uuid' => $fotoUuid, 'archivo' => $nombre]);
