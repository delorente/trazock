<?php
declare(strict_types=1);

// =============================================================================
// scripts/test-procesador.php — prueba ProcesadorCarga con un JSON de extracción.
// Crea una carga borrador, la confirma (materializa ordenes+productos), muestra el
// resultado y BORRA los datos de prueba (deja la BD limpia).
//   php scripts/test-procesador.php <json-extraccion>
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;
use Trazock\Models\Carga;
use Trazock\ProcesadorCarga;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Uso: php scripts/test-procesador.php <json-extraccion>\n");
    exit(1);
}
$json = (string)file_get_contents($path);

$db = DB::getInstance();

// Crear carga borrador con el JSON (usuario admin = 1).
$cargaId = Carga::crear(1);
Carga::guardarDatos($cargaId, $json);

$res = ProcesadorCarga::confirmar($cargaId);
echo "Carga #{$cargaId}: creadas={$res['creadas']}  items={$res['items']}  omitidas=" . count($res['omitidas']) . "\n";

// Muestra la primera orden + sus productos.
$ord = $db->query("SELECT id, nro_orden, cliente, cliente_apellido, dest_provincia, m3_total, estado
                   FROM ordenes WHERE carga_id = {$cargaId} ORDER BY id LIMIT 1")->fetch();
echo "Orden:  " . json_encode($ord, JSON_UNESCAPED_UNICODE) . "\n";
$prods = $db->query("SELECT codigo, descripcion, dimensiones, m3, secuencia, estado_actual
                     FROM productos WHERE orden_id = {$ord['id']} ORDER BY secuencia")->fetchAll();
foreach ($prods as $p) {
    echo "  ítem: " . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";
}
$tot = $db->query("SELECT COUNT(*) FROM productos p JOIN ordenes o ON o.id = p.orden_id
                   WHERE o.carga_id = {$cargaId}")->fetchColumn();
echo "Total productos de la carga: {$tot}\n";

// Limpieza: borrar los datos de prueba.
$db->exec("DELETE p FROM productos p JOIN ordenes o ON o.id = p.orden_id WHERE o.carga_id = {$cargaId}");
$db->exec("DELETE FROM ordenes WHERE carga_id = {$cargaId}");
$db->exec("DELETE FROM cargas WHERE id = {$cargaId}");
echo "Limpieza OK (datos de prueba borrados).\n";
