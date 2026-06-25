<?php
declare(strict_types=1);

// =============================================================================
// POST /api/orden-marca.php — fija/limpia la marca operativa de una orden desde
// la planilla de Reportes. Requiere admin/gestor + CSRF.
//   Body JSON: {csrf_token, id, marca}  (marca: 'no_entregar'|'prioridad'|'' )
//   → {ok, id, marca}
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Models\Orden;

Api::exigirMetodo('POST');
$data = Api::leerJson();
Api::usuarioConRol(['admin', 'gestor']);
Api::exigirCSRF($data);

$id = (int)($data['id'] ?? 0);
if ($id <= 0 || Orden::find($id) === null) {
    Api::error('Orden no encontrada.', 404);
}

// '' o cualquier valor no válido → sin marca (null).
$marca = (string)($data['marca'] ?? '');
$marca = in_array($marca, Orden::MARCAS, true) ? $marca : null;

Orden::setMarca($id, $marca);
Api::json(['ok' => true, 'id' => $id, 'marca' => $marca]);
