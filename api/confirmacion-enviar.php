<?php
declare(strict_types=1);

// =============================================================================
// POST /api/confirmacion-enviar.php — dispara el aviso de entrega por WhatsApp a
// las órdenes elegidas en Reportes. admin/logística + CSRF.
//   Body JSON: {csrf_token, ids:[int,...], fecha:'YYYY-MM-DD', horario:'8 a 17 hs'}
//   → {ok, enviadas, errores, detalle:[{id, nro_orden, ok, error?}]}
//
// Por cada orden: normaliza el teléfono (E.164), arma las 3 variables de la
// plantilla (producto/marca, fecha legible, horario) y la envía por la Cloud API.
// El resultado de cada envío se registra en `confirmaciones_entrega` (upsert).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\DB;
use Trazock\Whatsapp;
use Trazock\Models\Orden;
use Trazock\Models\ConfirmacionEntrega;

Api::exigirMetodo('POST');
$data = Api::leerJson();
$user = Api::usuarioConRol(['admin', 'logistica']);
Api::exigirCSRF($data);

if (!Whatsapp::configurado()) {
    Api::error('WhatsApp no está configurado todavía. Cargá las credenciales de Meta en config.php (ver docs/WHATSAPP.md).', 409);
}

$ids = array_values(array_unique(array_filter(
    array_map(static fn($v) => (int)$v, (array)($data['ids'] ?? [])),
    static fn(int $v): bool => $v > 0
)));
if ($ids === []) {
    Api::error('No seleccionaste ninguna orden.');
}
if (count($ids) > 200) {
    Api::error('Demasiadas órdenes en un solo envío (máximo 200).');
}

$fecha = trim((string)($data['fecha'] ?? ''));
if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    Api::error('Elegí una fecha de entrega válida.');
}
$horario = trim((string)($data['horario'] ?? ''));
if ($horario === '') {
    $horario = '8 a 17 hs';
}
if (mb_strlen($horario) > 40) {
    $horario = mb_substr($horario, 0, 40);
}

/** Fecha legible en español: "lunes 29" (sin librería intl). */
function confent_fecha_legible(string $ymd): string
{
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return $ymd;
    }
    $dias = ['Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes',
             'Wednesday' => 'miércoles', 'Thursday' => 'jueves', 'Friday' => 'viernes',
             'Saturday' => 'sábado'];
    $dia = $dias[date('l', $ts)] ?? '';
    return trim($dia . ' ' . (int)date('j', $ts));
}

/** Marca/producto de la orden (proveedor o categoría), para el {{1}} de la plantilla. */
function confent_producto(int $ordenId): string
{
    $stmt = DB::getInstance()->prepare(
        'SELECT COALESCE(pr.nombre, cat.nombre, \'\') AS marca
           FROM ordenes o
           LEFT JOIN cargas cg     ON cg.id = o.carga_id
           LEFT JOIN categorias cat ON cat.id = cg.categoria_id
           LEFT JOIN proveedores pr ON pr.id = cat.proveedor_id
          WHERE o.id = :o LIMIT 1'
    );
    $stmt->execute([':o' => $ordenId]);
    return (string)($stmt->fetchColumn() ?: '');
}

$fechaLegible = confent_fecha_legible($fecha);

$detalle  = [];
$enviadas = 0;
$errores  = 0;

foreach ($ids as $id) {
    $orden = Orden::find($id);
    if ($orden === null) {
        $detalle[] = ['id' => $id, 'nro_orden' => (string)$id, 'ok' => false, 'error' => 'Orden inexistente'];
        $errores++;
        continue;
    }
    $nro = (string)$orden['nro_orden'];
    $tel = tel_e164((string)($orden['telefonos'] ?? ''));
    if ($tel === null) {
        ConfirmacionEntrega::registrarEnviado($id, $fecha, $horario, null, null, 'Teléfono inválido o ausente', (int)$user['id']);
        $detalle[] = ['id' => $id, 'nro_orden' => $nro, 'ok' => false, 'error' => 'Teléfono inválido o ausente'];
        $errores++;
        continue;
    }

    $producto = confent_producto($id);
    try {
        $wamid = Whatsapp::enviarPlantilla($tel, [$producto, $fechaLegible, $horario]);
        ConfirmacionEntrega::registrarEnviado($id, $fecha, $horario, $tel, $wamid, null, (int)$user['id']);
        $detalle[] = ['id' => $id, 'nro_orden' => $nro, 'ok' => true];
        $enviadas++;
    } catch (Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 200);
        ConfirmacionEntrega::registrarEnviado($id, $fecha, $horario, $tel, null, $msg, (int)$user['id']);
        $detalle[] = ['id' => $id, 'nro_orden' => $nro, 'ok' => false, 'error' => $msg];
        $errores++;
        error_log('confirmacion-enviar.php orden ' . $id . ': ' . $e->getMessage());
    }
}

Api::json([
    'ok'       => true,
    'enviadas' => $enviadas,
    'errores'  => $errores,
    'detalle'  => $detalle,
]);
