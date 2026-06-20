<?php
declare(strict_types=1);

// =============================================================================
// scripts/test-extraccion.php — prueba la extracción OCR de una hoja resumen.
// Uso:  php scripts/test-extraccion.php <ruta-imagen>
// Requiere ANTHROPIC_API_KEY en config/config.php.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\ExtractorOcr;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Uso: php scripts/test-extraccion.php <ruta-imagen>\n");
    exit(1);
}

$bytes = file_get_contents($path);
if ($bytes === false) {
    fwrite(STDERR, "No se pudo leer: {$path}\n");
    exit(1);
}

$t0 = microtime(true);
try {
    $res = ExtractorOcr::extraerHoja($bytes);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
$dt = round(microtime(true) - $t0, 1);

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
fwrite(STDERR, count($res['ordenes']) . " órdenes · {$dt}s\n");
