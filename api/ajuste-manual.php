<?php
declare(strict_types=1);

// =============================================================================
// POST /api/ajuste-manual.php — cambio manual de estado desde el panel.
// Requiere admin o gestor + CSRF. Crea una transición es_ajuste_manual=1 (sin conflicto).
// Body: {csrf_token, codigo, nuevo_estado, motivo}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\DB;
use Trazock\Estado;
use Trazock\Models\Producto;
use Trazock\Models\Transicion;

Api::exigirMetodo('POST');
$data = Api::leerJson();
$user = Api::usuarioConRol(['admin', 'logistica']);
Api::exigirCSRF($data);

$codigo  = trim((string)($data['codigo'] ?? ''));
$estadoStr = (string)($data['nuevo_estado'] ?? '');
$motivo  = trim((string)($data['motivo'] ?? ''));

if ($codigo === '') {
    Api::error('Falta el código del producto.', 400);
}
$nuevoEstado = Estado::tryFrom($estadoStr);
if ($nuevoEstado === null) {
    Api::error('Estado destino inválido.', 400);
}
if ($motivo === '') {
    Api::error('El motivo es obligatorio.', 400);
}

$db = DB::getInstance();
$db->beginTransaction();
try {
    $prod = Producto::findByCodigoForUpdate($codigo);
    if ($prod === null) {
        $db->rollBack();
        Api::error('El producto no existe.', 404);
    }

    $pid      = (int)$prod['id'];
    $desde    = (string)$prod['estado_actual'];
    if ($desde === $nuevoEstado->value) {
        $db->rollBack();
        Api::error('El producto ya está en ese estado.', 400);
    }

    $ahora = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    // Guardamos el motivo manual en motivo_conflicto (no marca conflicto; es_ajuste_manual=1).
    $tid = Transicion::insertar(
        $pid, null, $desde, $nuevoEstado->value, $ahora,
        false, mb_substr($motivo, 0, 50), true, (int)$user['id']
    );

    // El ajuste manual usa "ahora": es la transición más reciente salvo timestamps futuros.
    if (!Transicion::existeMasReciente($pid, $ahora)) {
        Producto::fijarEstadoActual($pid, $nuevoEstado->value, $tid);
    }

    $db->commit();
    Api::json(['ok' => true, 'codigo' => $codigo, 'estado' => $nuevoEstado->value], 200);
} catch (Throwable $e) {
    $db->rollBack();
    error_log('ajuste-manual: ' . $e->getMessage());
    Api::error('Error interno al aplicar el ajuste.', 500);
}
