<?php
declare(strict_types=1);

// =============================================================================
// api/encuesta-enviar.php — Recibe la encuesta de satisfacción del comprador.
//
// Endpoint PÚBLICO (sin login): lo invoca el flujo embebido en el seguimiento.
// Valida CSRF de sesión (token sembrado por la propia landing), que la orden
// exista y esté ENTREGADO, que no tenga encuesta previa, y que las cuatro
// puntuaciones sean 1-4. Inserta y responde JSON {ok:true}.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Auth;
use Trazock\Models\Encuesta;
use Trazock\Models\Orden;

Api::exigirMetodo('POST');

// Sesión pública: la landing de seguimiento sembró el token CSRF para este envío.
Auth::iniciarSesion();

$data = Api::leerJson();
Api::exigirCSRF($data);

$nro = trim((string)($data['orden'] ?? ''));
if ($nro === '') {
    Api::error('Falta el número de orden.');
}

$orden = Orden::findByNroOrden($nro);
if ($orden === null) {
    Api::error('No encontramos esa orden.', 404);
}
$ordenId = (int)$orden['id'];

// La encuesta solo se responde con el pedido entregado.
if (Orden::estadoProductoDerivado($ordenId) !== 'ENTREGADO') {
    Api::error('La encuesta está disponible cuando tu pedido figura como entregado.', 409);
}

// Una sola encuesta por orden.
if (Encuesta::existeParaOrden($ordenId)) {
    Api::error('Esta orden ya tiene una calificación registrada.', 409);
}

// Validar las cuatro puntuaciones (1-4).
$r = [];
foreach (Encuesta::EJES as $eje) {
    $v = (int)($data[$eje] ?? 0);
    if ($v < 1 || $v > 4) {
        Api::error('Faltan calificaciones por completar.');
    }
    $r[$eje] = $v;
}

$comentario = trim((string)($data['comentario'] ?? ''));
if (mb_strlen($comentario) > 1000) {
    $comentario = mb_substr($comentario, 0, 1000);
}

try {
    Encuesta::crear($ordenId, $r, $comentario);
} catch (Throwable $e) {
    // Carrera contra el UNIQUE (doble envío): lo tratamos como ya registrada.
    if (Encuesta::existeParaOrden($ordenId)) {
        Api::error('Esta orden ya tiene una calificación registrada.', 409);
    }
    error_log('encuesta-enviar.php: ' . $e->getMessage());
    Api::error('No pudimos registrar tu calificación. Probá de nuevo.', 500);
}

Api::json(['ok' => true]);
