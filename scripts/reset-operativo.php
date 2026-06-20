<?php
declare(strict_types=1);

// =============================================================================
// scripts/reset-operativo.php — VACÍA todos los datos operativos para empezar de
// cero, conservando catálogos y configuración.
//
//   BORRA:     cargas, ordenes, productos, lotes, transiciones, lote_items,
//              conflictos_producto  (con AUTO_INCREMENT reseteado a 1)
//   CONSERVA:  usuarios, categorias, proveedores, motivos, zonas,
//              zona_localidades, estados_publicos, intentos_login
//
// Uso (pide confirmación explícita para no borrar por accidente):
//   php scripts/reset-operativo.php --confirm
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;

if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "⚠  Esto BORRA todos los datos operativos (cargas, órdenes, productos,\n");
    fwrite(STDERR, "   lotes, transiciones, conflictos). Conserva usuarios, categorías,\n");
    fwrite(STDERR, "   proveedores, motivos, zonas y textos de seguimiento.\n\n");
    fwrite(STDERR, "   Para ejecutar de verdad:\n");
    fwrite(STDERR, "     php scripts/reset-operativo.php --confirm\n");
    exit(1);
}

$db = DB::getInstance();

// Orden no importa con las FK desactivadas; TRUNCATE además resetea el AUTO_INCREMENT.
$tablas = ['lote_items', 'conflictos_producto', 'transiciones', 'productos', 'ordenes', 'lotes', 'cargas'];

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tablas as $t) {
    $db->exec("TRUNCATE TABLE `{$t}`");
    echo "  vaciada: {$t}\n";
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "✓ Base operativa en cero. Catálogos, zonas y textos de seguimiento intactos.\n";
