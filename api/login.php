<?php
declare(strict_types=1);

// =============================================================================
// POST /api/login.php — login de la app de escaneo.
// Body: {usuario, password}. Devuelve {ok, usuario:{id,nombre,rol}, catalogos:{...}}.
// Rate limit 5/15min por IP+usuario (en Auth::login).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Auth;
use Trazock\Catalogos;

Api::exigirMetodo('POST');

$data     = Api::leerJson();
$usuario  = trim((string)($data['usuario'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($usuario === '' || $password === '') {
    Api::error('Ingresá usuario y contraseña.', 400);
}

if (!Auth::login($usuario, $password)) {
    Api::error('Usuario o contraseña incorrectos, o demasiados intentos fallidos.', 401);
}

$user = Auth::usuarioActual();

Api::json([
    'ok'      => true,
    'usuario' => [
        'id'      => (int)$user['id'],
        'nombre'  => $user['nombre_completo'],
        'rol'     => $user['rol'],
    ],
    'csrf'      => Auth::tokenCSRF(),
    'catalogos' => Catalogos::para((string)$user['rol']),
], 200);
