<?php
declare(strict_types=1);

// =============================================================================
// POST /api/conflicto-resolver.php — marca un conflicto como revisado.
// Requiere admin o gestor + CSRF. Si el producto no tiene más pendientes,
// limpia productos.tiene_conflicto.
// Body: {csrf_token, conflicto_id, nota?}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\DB;
use Trazock\Models\Conflicto;
use Trazock\Models\Producto;

Api::exigirMetodo('POST');
$data = Api::leerJson();
$user = Api::usuarioConRol(['admin', 'gestor']);
Api::exigirCSRF($data);

$conflictoId = (int)($data['conflicto_id'] ?? 0);
$nota        = trim((string)($data['nota'] ?? ''));

if ($conflictoId <= 0) {
    Api::error('Falta el identificador del conflicto.', 400);
}

$db = DB::getInstance();
$db->beginTransaction();
try {
    $productoId = Conflicto::marcarRevisado($conflictoId, (int)$user['id'], $nota !== '' ? $nota : null);
    if ($productoId === null) {
        $db->rollBack();
        Api::error('El conflicto no existe o ya fue revisado.', 404);
    }

    // Si el producto ya no tiene conflictos pendientes, limpiar el flag.
    if (Conflicto::pendientesDeProducto($productoId) === 0) {
        Producto::limpiarConflicto($productoId);
    }

    $db->commit();
    Api::json(['ok' => true, 'conflicto_id' => $conflictoId], 200);
} catch (Throwable $e) {
    $db->rollBack();
    error_log('conflicto-resolver: ' . $e->getMessage());
    Api::error('Error interno al resolver el conflicto.', 500);
}
