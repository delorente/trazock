<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-etiquetas.php — hoja imprimible de etiquetas (A4, 8 por hoja).
// Un rótulo con QR por ítem físico. El QR es autocontenido
// (nro_orden|sec/total|provincia|apellido) para validar el destino offline.
// Al abrir esta hoja, los ítems se marcan como ETIQUETADOS. admin/gestor.
//   ?carga=ID  → toda la carga (post-confirmación)
//   ?orden=ID  → una sola orden (reimpresión desde el detalle)
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\EtiquetaQr;
use Trazock\Models\Carga;
use Trazock\Models\Orden;
use Trazock\Models\Producto;

$user = Auth::requierePanel(); // admin o gestor

$cargaId = (int)($_GET['carga'] ?? 0);
$ordenId = (int)($_GET['orden'] ?? 0);

$volverCaptura = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-captura.php')) . '"><i class="bi bi-plus-lg me-1"></i>Nueva carga</a>';

if ($ordenId > 0) {
    // Reimpresión de una sola orden.
    $orden = Orden::find($ordenId);
    if ($orden === null) {
        panel_header('Etiquetas', $user, 'reportes', '', $volverCaptura);
        echo '<div class="alert alert-warning">No se encontró la orden a etiquetar.</div>';
        panel_footer();
        exit;
    }
    $num   = carga_num((int)($orden['carga_id'] ?? 0), (string)($orden['created_at'] ?? ''));
    $items = Producto::paraEtiquetasPorOrden($ordenId);
    if ($items !== []) {
        Producto::marcarEtiquetadasPorOrden($ordenId);
    }
} else {
    // Toda la carga.
    $carga = $cargaId > 0 ? Carga::find($cargaId) : null;
    if ($carga === null || $carga['estado'] !== 'confirmada') {
        panel_header('Etiquetas', $user, 'captura', '', $volverCaptura);
        echo '<div class="alert alert-warning">No se encontró una carga confirmada para etiquetar.</div>';
        panel_footer();
        exit;
    }
    $num   = carga_num($cargaId, (string)($carga['created_at'] ?? ''));
    $items = Producto::paraEtiquetasPorCarga($cargaId);
    if ($items !== []) {
        Producto::marcarEtiquetadasPorCarga($cargaId);
    }
}

$PERPAGE = 8;
$paginas = array_chunk($items, $PERPAGE);
$totalEt = count($items);

if ($ordenId > 0) {
    $volverHref = url('admin/ordenes-detalle.php') . '?id=' . $ordenId;
    $volverTxt  = 'Detalle de la orden';
    $activo     = 'reportes';
    $subtitulo  = 'Orden ' . (string)($orden['nro_orden'] ?? '') . ' · ' . $totalEt . ' etiqueta(s)';
} else {
    $volverHref = url('admin/ordenes-confirmacion.php') . '?carga=' . $cargaId;
    $volverTxt  = 'Confirmación';
    $activo     = 'captura';
    $subtitulo  = 'Carga ' . $num . ' · A4 · 8 por hoja · ' . count($paginas) . ' hoja(s) · ' . $totalEt . ' etiquetas';
}

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h($volverHref) . '"><i class="bi bi-arrow-left me-1"></i>' . h($volverTxt) . '</a>'
        . '<button class="btn btn-sm btn-primary fw-bold" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Etiquetas', $user, $activo, $subtitulo, $volver);

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
<div class="alert no-print" style="background:var(--card);border:1px solid var(--border);font-size:12px;color:var(--muted)" >
  <i class="bi bi-info-circle me-1"></i>Optimizado para papel autoadhesivo A4 (Avery L7165 o similar) · impresión en blanco y negro.
  Usá <strong>Imprimir / PDF</strong> y elegí "Guardar como PDF" o tu impresora.
</div>

<div class="label-sheet print-area">
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
        $loc     = (string)($it['dest_localidad'] ?? '');
        $desc    = trim((string)($it['descripcion'] ?? ''));
        $payload = EtiquetaQr::payload($nro, $sec, $tot, $prov, $loc, $ape);
    ?>
    <div class="label-cell">
      <div class="lq" data-qr="<?= h($payload) ?>"></div>
      <div class="lb">
        <div class="ld"><?= h(eti_destino($it)) ?></div>
        <div class="ln"><?= h($nombre) ?></div>
        <?php if ($desc !== ''): ?><div class="li"><?= h($desc) ?></div><?php endif; ?>
        <div class="lc"><span><?= h((string)$it['codigo']) ?> · <?= h($num) ?></span><span class="lqty"><?= $sec ?> de <?= $tot ?></span></div>
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
