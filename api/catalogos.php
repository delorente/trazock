<?php
declare(strict_types=1);

// =============================================================================
// GET /api/catalogos.php — categorías, proveedores, motivos (por tipo) y
// transportistas activos + tipos de lote permitidos para el rol. Requiere sesión.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Catalogos;

Api::exigirMetodo('GET');

$user = Api::usuarioAutenticado();

Api::json([
    'ok'        => true,
    'catalogos' => Catalogos::para((string)$user['rol']),
], 200);
