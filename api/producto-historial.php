<?php
declare(strict_types=1);

// =============================================================================
// GET /api/producto-historial.php?codigo=... — producto + historial + conflictos.
// Requiere admin o gestor (consulta de panel).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Models\Conflicto;
use Trazock\Models\Producto;
use Trazock\Models\Transicion;

Api::exigirMetodo('GET');
Api::usuarioConRol(['admin', 'gestor']);

$codigo = trim((string)($_GET['codigo'] ?? ''));
if ($codigo === '') {
    Api::error('Falta el parámetro codigo.', 400);
}

$prod = Producto::findByCodigo($codigo);
if ($prod === null) {
    Api::error('Producto no encontrado.', 404);
}

$pid = (int)$prod['id'];
Api::json([
    'ok'         => true,
    'producto'   => [
        'id'              => $pid,
        'codigo'          => $prod['codigo'],
        'categoria'       => $prod['categoria_nombre'],
        'estado_actual'   => $prod['estado_actual'],
        'tiene_conflicto' => (int)$prod['tiene_conflicto'],
    ],
    'historial'  => Transicion::historialProducto($pid),
    'conflictos' => Conflicto::deProducto($pid, false),
], 200);
