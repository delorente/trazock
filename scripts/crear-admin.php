<?php
declare(strict_types=1);

// =============================================================================
// scripts/crear-admin.php — alta de usuario desde la línea de comandos.
//
// Útil para crear el primer admin (o recuperarse si se pierde acceso al panel).
//
// Uso:
//   php scripts/crear-admin.php <usuario> <nombre> <password> <rol>
//
// Ejemplo:
//   php scripts/crear-admin.php juan "Juan Pérez" "Cl4veFuerte!" admin
//
// Roles válidos: admin, gestor, operador, transportista
// =============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Models\Usuario;

$args = array_slice($argv, 1);

if (count($args) !== 4) {
    fwrite(STDERR, "Uso: php scripts/crear-admin.php <usuario> <nombre> <password> <rol>\n");
    fwrite(STDERR, "Roles válidos: " . implode(', ', Usuario::ROLES_VALIDOS) . "\n");
    exit(1);
}

[$usuario, $nombre, $password, $rol] = $args;

$usuario = trim($usuario);
$nombre  = trim($nombre);

// --- Validaciones ------------------------------------------------------------

if ($usuario === '' || $nombre === '' || $password === '') {
    fwrite(STDERR, "Error: usuario, nombre y password no pueden estar vacíos.\n");
    exit(1);
}

if (!in_array($rol, Usuario::ROLES_VALIDOS, true)) {
    fwrite(STDERR, "Error: rol inválido '{$rol}'. Válidos: " . implode(', ', Usuario::ROLES_VALIDOS) . "\n");
    exit(1);
}

if (strlen($password) < 6) {
    fwrite(STDERR, "Error: la contraseña debe tener al menos 6 caracteres.\n");
    exit(1);
}

if (Usuario::existsByUsuario($usuario)) {
    fwrite(STDERR, "Error: el usuario '{$usuario}' ya existe.\n");
    exit(1);
}

// --- Alta --------------------------------------------------------------------

try {
    $id = Usuario::crear($usuario, $nombre, $password, $rol);
} catch (Throwable $e) {
    fwrite(STDERR, "Error al crear el usuario: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Usuario creado correctamente.\n");
fwrite(STDOUT, "  id:      {$id}\n");
fwrite(STDOUT, "  usuario: {$usuario}\n");
fwrite(STDOUT, "  rol:     {$rol}\n");
exit(0);
