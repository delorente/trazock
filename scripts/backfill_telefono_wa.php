<?php
declare(strict_types=1);

// =============================================================================
// scripts/backfill_telefono_wa.php — completa ordenes.telefono_wa en las órdenes
// existentes, derivándolo del teléfono literal (tel_e164). No pisa los que ya
// tienen valor. Idempotente: re-ejecutarlo es seguro.
//
//   php scripts/backfill_telefono_wa.php          (aplica)
//   php scripts/backfill_telefono_wa.php --dry     (solo informa, no escribe)
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\DB;

$dry = in_array('--dry', $argv, true);
$db  = DB::getInstance();

// Solo las que no tienen telefono_wa todavía.
$rows = $db->query(
    "SELECT id, telefonos FROM ordenes
     WHERE (telefono_wa IS NULL OR telefono_wa = '')
       AND telefonos IS NOT NULL AND telefonos <> ''"
)->fetchAll();

$total     = count($rows);
$derivados = 0;
$invalidos = 0;

$upd = $db->prepare('UPDATE ordenes SET telefono_wa = :w WHERE id = :id');

foreach ($rows as $r) {
    $wa = tel_e164((string)$r['telefonos']);
    if ($wa === null) {
        $invalidos++;
        continue;
    }
    $derivados++;
    if (!$dry) {
        $upd->execute([':w' => $wa, ':id' => (int)$r['id']]);
    }
}

echo ($dry ? "[DRY-RUN] " : "")
   . "Órdenes sin telefono_wa con teléfono literal: {$total}\n"
   . "  · derivadas a E.164: {$derivados}\n"
   . "  · sin móvil válido (revisar a mano): {$invalidos}\n"
   . ($dry ? "No se escribió nada.\n" : "Backfill aplicado.\n");
