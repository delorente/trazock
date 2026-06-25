<?php
declare(strict_types=1);

// =============================================================================
// scripts/migrate.php — aplica una migración SQL usando las credenciales que ya
// están en config/config.php (no hace falta tipear la contraseña de la BD).
//
//   Listar las migraciones disponibles:
//     php scripts/migrate.php
//   Aplicar una:
//     php scripts/migrate.php 012_orden_observaciones_marca.sql
//
// Es idempotente ante "columna/índice ya existe": avisa y sale sin error, así que
// re-ejecutarla es seguro. No lleva control de versiones: vos elegís qué archivo.
// =============================================================================

require __DIR__ . '/../config/config.php';

$dir = __DIR__ . '/../sql/migrations';

$file = $argv[1] ?? '';
if ($file === '') {
    fwrite(STDERR, "Uso: php scripts/migrate.php <archivo.sql>\n\nMigraciones disponibles:\n");
    foreach (glob($dir . '/*.sql') ?: [] as $f) {
        fwrite(STDERR, '   - ' . basename($f) . "\n");
    }
    exit(1);
}

$path = $dir . '/' . basename($file); // basename: evita rutas fuera de migrations/
if (!is_file($path)) {
    fwrite(STDERR, "✗ No existe la migración: " . basename($file) . "\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    DB_HOST,
    defined('DB_PORT') ? DB_PORT : 3306,
    DB_NAME,
    defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec((string)file_get_contents($path));
    echo "✓ Migración aplicada: " . basename($file) . "\n";
} catch (PDOException $e) {
    $msg = $e->getMessage();
    // Si ya estaba aplicada (columna/índice/clave duplicada), no es un error real.
    if (preg_match('/Duplicate (column|key)|already exists|Duplicate entry/i', $msg)) {
        echo "• Parece que ya estaba aplicada (" . basename($file) . "): " . $msg . "\n";
        exit(0);
    }
    fwrite(STDERR, "✗ Error aplicando " . basename($file) . ": " . $msg . "\n");
    exit(1);
}
