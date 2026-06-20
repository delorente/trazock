<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-etiquetas.php — hoja imprimible de etiquetas (A4, 8 por hoja).
// Un rótulo con QR por ítem físico de la carga. El QR es autocontenido
// (nro_orden|sec/total|provincia|apellido) para validar el destino offline.
// Al abrir esta hoja, los ítems se marcan como ETIQUETADOS. admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\EtiquetaQr;
use Trazock\Models\Carga;
use Trazock\Models\Producto;

$user = Auth::requierePanel(); // admin o gestor

$cargaId = (int)($_GET['carga'] ?? 0);
$carga   = $cargaId > 0 ? Carga::find($cargaId) : null;

$volverCaptura = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-captura.php')) . '"><i class="bi bi-plus-lg me-1"></i>Nueva carga</a>';

if ($carga === null || $carga['estado'] !== 'confirmada') {
    panel_header('Etiquetas', $user, 'captura', '', $volverCaptura);
    echo '<div class="alert alert-warning">No se encontró una carga confirmada para etiquetar.</div>';
    panel_footer();
    exit;
}

$num   = carga_num($cargaId, (string)($carga['created_at'] ?? ''));
$items = Producto::paraEtiquetasPorCarga($cargaId);

// Generar la hoja = imprimir los rótulos: marcar los ítems como etiquetados.
if ($items !== []) {
    Producto::marcarEtiquetadasPorCarga($cargaId);
}

$PERPAGE = 8;
$paginas = array_chunk($items, $PERPAGE);
$totalEt = count($items);

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-confirmacion.php') . '?carga=' . $cargaId) . '"><i class="bi bi-arrow-left me-1"></i>Confirmación</a>'
        . '<button class="btn btn-sm btn-primary fw-bold" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Etiquetas', $user, 'captura',
    'Carga ' . $num . ' · A4 · 8 por hoja · ' . count($paginas) . ' hoja(s) · ' . $totalEt . ' etiquetas', $volver);

if ($items === []) {
    echo '<div class="alert alert-warning">La carga no tiene ítems para etiquetar.</div>';
    panel_footer();
    exit;
}

/** Línea de destino "Localidad · Provincia" (sin separador si falta una parte). */
function eti_destino(array $it): string
{
    $loc  = trim((string)($it['dest_localidad'] ?? ''));
    $prov = trim((string)($it['dest_provincia'] ?? ''));
    $d = $loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov;
    return $d !== '' ? $d : '—';
}
?>
<div class="alert" style="background:var(--card);border:1px solid var(--border);font-size:12px;color:var(--muted)" >
  <i class="bi bi-info-circle me-1"></i>Optimizado para papel autoadhesivo A4 (Avery L7165 o similar) · impresión en blanco y negro.
  Usá <strong>Imprimir / PDF</strong> y elegí "Guardar como PDF" o tu impresora.
</div>

<div class="label-sheet">
<?php foreach ($paginas as $pagina): ?>
  <section class="sheet-page">
    <?php foreach ($pagina as $it):
        $nro     = (string)$it['nro_orden'];
        $sec     = (int)$it['secuencia'];
        $tot     = (int)$it['total_items'];
        $prov    = (string)($it['dest_provincia'] ?? '');
        $ape     = (string)($it['cliente_apellido'] ?? '');
        // Línea legible: nombre completo (nombre antes del apellido). El QR sigue
        // llevando solo el apellido (payload compacto para validar destino offline).
        $cli     = trim((string)($it['cliente'] ?? ''));
        $nombre  = $cli !== '' ? $cli : ($ape !== '' ? $ape : '—');
        $desc    = trim((string)($it['descripcion'] ?? ''));
        $payload = EtiquetaQr::payload($nro, $sec, $tot, $prov, $ape);
    ?>
    <div class="label-cell">
      <div class="lq" data-qr="<?= h($payload) ?>"></div>
      <div class="lb">
        <div class="ld"><?= h(eti_destino($it)) ?></div>
        <div class="ln"><?= h($nombre) ?></div>
        <div class="li">Ítem <?= $sec ?> de <?= $tot ?><?= $desc !== '' ? ' · ' . h($desc) : '' ?></div>
        <div class="lc"><?= h((string)$it['codigo']) ?> · <?= h($num) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
</div>

<script src="<?= h(asset('assets/vendor/qrcode-generator/qrcode.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/etiquetas.js')) ?>"></script>
<?php
panel_footer();
