<?php
declare(strict_types=1);

// =============================================================================
// POST /api/ordenes-hoja.php — procesa UNA hoja resumen subida (multipart).
// Extrae las órdenes con OCR y las acumula en la carga borrador. Crea la carga
// si no viene carga_id. Requiere admin/gestor + CSRF.
//   Form: csrf_token, hoja (file), tipo_venta (local|online), carga_id (opt)
//   → {ok, carga_id, ordenes_hoja, total}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Auth;
use Trazock\ExtractorOcr;
use Trazock\Models\Carga;
use Trazock\Models\Categoria;
use Trazock\Models\Usuario;

Api::exigirMetodo('POST');
$user = Api::usuarioConRol(['admin', 'gestor']);

if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
    Api::error('Token CSRF inválido. Recargá la página.', 403);
}

// La extracción por hoja puede tardar ~40s; evitar que PHP corte antes.
@set_time_limit(200);

if (!isset($_FILES['hoja']) || ($_FILES['hoja']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Api::error('No se recibió la imagen de la hoja (¿supera el límite de subida del servidor?).', 400);
}
$bytes = file_get_contents($_FILES['hoja']['tmp_name']);
if ($bytes === false || $bytes === '') {
    Api::error('La imagen está vacía.', 400);
}

$tipoVenta = in_array(($_POST['tipo_venta'] ?? ''), ['local', 'online'], true) ? (string)$_POST['tipo_venta'] : null;
$cargaId   = (int)($_POST['carga_id'] ?? 0);

// Datos clave POR DOCUMENTO (obligatorios): transportista que trajo la mercadería
// y fecha de carga (≤ hoy). El HR lo extrae el OCR; estos dos los ingresa el usuario.
$transportistaId = (int)($_POST['transportista_id'] ?? 0);
if ($transportistaId <= 0 || !Usuario::existeActivoConRol($transportistaId, 'transportista')) {
    Api::error('Elegí un transportista válido para este documento.', 400);
}
$fechaCarga = trim((string)($_POST['fecha_carga'] ?? ''));
$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaCarga);
if ($d === false || $d->format('Y-m-d') !== $fechaCarga) {
    Api::error('Fecha de carga inválida.', 400);
}
if ($fechaCarga > date('Y-m-d')) {
    Api::error('La fecha de carga no puede ser posterior a hoy.', 400);
}

// Categoría (línea de producto) de la carga: solo se fija al crearla.
$categoriaId = (int)($_POST['categoria_id'] ?? 0);
if ($categoriaId > 0 && !Categoria::existeActiva($categoriaId)) {
    $categoriaId = 0;
}

// Carga: crear si no viene; validar que exista y no esté confirmada.
if ($cargaId <= 0) {
    $cargaId = Carga::crear((int)$user['id'], $categoriaId > 0 ? $categoriaId : null);
} else {
    $c = Carga::find($cargaId);
    if ($c === null || $c['estado'] === 'confirmada') {
        Api::error('Carga inválida o ya confirmada.', 400);
    }
}

// Extracción OCR de la hoja.
try {
    $res = ExtractorOcr::extraerHoja($bytes);
} catch (Throwable $e) {
    error_log('ordenes-hoja: ' . $e->getMessage());
    Api::error('No se pudo extraer la hoja: ' . $e->getMessage(), 500);
}
$nuevas = is_array($res['ordenes'] ?? null) ? $res['ordenes'] : [];

// HR del documento (OCR) — puede venir null; el usuario lo completa en revisión.
$hojaRuta = isset($res['hoja_ruta']) && $res['hoja_ruta'] !== null ? trim((string)$res['hoja_ruta']) : '';

// Estampar en cada orden del documento: tipo de venta de la carga + los datos
// clave de ESTE documento (HR, transportista, fecha). Todos editables luego.
foreach ($nuevas as &$o) {
    if ($tipoVenta !== null) { $o['tipo_venta'] = $tipoVenta; }
    $o['hoja_ruta']        = $hojaRuta;
    $o['transportista_id'] = $transportistaId;
    $o['fecha_carga']      = $fechaCarga;
}
unset($o);

// Acumular en el borrador de la carga.
$c     = Carga::find($cargaId);
$datos = json_decode((string)($c['datos_extraidos'] ?? ''), true);
if (!is_array($datos) || !isset($datos['ordenes']) || !is_array($datos['ordenes'])) {
    $datos = ['ordenes' => []];
}
$datos['ordenes'] = array_merge($datos['ordenes'], $nuevas);
Carga::guardarDatos($cargaId, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

Api::json([
    'ok'           => true,
    'carga_id'     => $cargaId,
    'ordenes_hoja' => count($nuevas),
    'total'        => count($datos['ordenes']),
]);
