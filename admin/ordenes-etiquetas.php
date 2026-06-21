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
use Trazock\Models\Lote;
use Trazock\Models\Orden;
use Trazock\Models\Producto;

$user = Auth::requierePanel(); // admin o gestor

$cargaId = (int)($_GET['carga'] ?? 0);
$ordenId = (int)($_GET['orden'] ?? 0);
$loteId  = (int)($_GET['lote'] ?? 0);

// Tamaño de etiqueta = cuántas entran por hoja A4. El cliente lo elige sin tocar
// código. cols×rows define la grilla; qr/fs escalan el rótulo a ese tamaño.
$LAYOUTS = [
    2  => ['cols' => 1, 'rows' => 2, 'qr' => '46mm', 'fs' => '16px'],
    4  => ['cols' => 2, 'rows' => 2, 'qr' => '42mm', 'fs' => '15px'],
    6  => ['cols' => 2, 'rows' => 3, 'qr' => '34mm', 'fs' => '13px'],
    8  => ['cols' => 2, 'rows' => 4, 'qr' => '32mm', 'fs' => '11px'],
    12 => ['cols' => 3, 'rows' => 4, 'qr' => '25mm', 'fs' => '9.5px'],
    24 => ['cols' => 4, 'rows' => 6, 'qr' => '18mm', 'fs' => '8px'],
];
$por = (int)($_GET['por'] ?? 8);
if (!isset($LAYOUTS[$por])) { $por = 8; }
$L       = $LAYOUTS[$por];
$PERPAGE = $L['cols'] * $L['rows'];

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
    $num        = carga_num((int)($orden['carga_id'] ?? 0), (string)($orden['created_at'] ?? ''));
    $items      = Producto::paraEtiquetasPorOrden($ordenId);
    if ($items !== []) { Producto::marcarEtiquetadasPorOrden($ordenId); }
    $qsBase     = 'orden=' . $ordenId;
    $volverHref = url('admin/ordenes-detalle.php') . '?id=' . $ordenId;
    $volverTxt  = 'Detalle de la orden';
    $activo     = 'reportes';
    $ctxTxt     = 'Orden ' . (string)($orden['nro_orden'] ?? '');
} elseif ($loteId > 0) {
    // Reimpresión de todas las etiquetas de un lote (la carga agrupada).
    $lote = Lote::findById($loteId);
    if ($lote === null) {
        panel_header('Etiquetas', $user, 'lotes', '', $volverCaptura);
        echo '<div class="alert alert-warning">No se encontró el lote a etiquetar.</div>';
        panel_footer();
        exit;
    }
    $items = Producto::paraEtiquetasPorLote($loteId);
    if ($items !== []) { Producto::marcarEtiquetadasPorLote($loteId); }
    // El número del rótulo es el de la carga (un lote de ingreso = una carga).
    $cId        = $items !== [] ? (int)$items[0]['carga_id'] : 0;
    $cRow       = $cId > 0 ? Carga::find($cId) : null;
    $num        = $cRow !== null ? carga_num($cId, (string)($cRow['created_at'] ?? '')) : lote_num($loteId, (string)$lote['created_at']);
    $qsBase     = 'lote=' . $loteId;
    $volverHref = url('admin/lote-detalle.php') . '?id=' . $loteId;
    $volverTxt  = 'Detalle del lote';
    $activo     = 'lotes';
    $ctxTxt     = 'Lote ' . lote_num($loteId, (string)$lote['created_at']);
} else {
    // Toda la carga.
    $carga = $cargaId > 0 ? Carga::find($cargaId) : null;
    if ($carga === null || $carga['estado'] !== 'confirmada') {
        panel_header('Etiquetas', $user, 'captura', '', $volverCaptura);
        echo '<div class="alert alert-warning">No se encontró una carga confirmada para etiquetar.</div>';
        panel_footer();
        exit;
    }
    $num        = carga_num($cargaId, (string)($carga['created_at'] ?? ''));
    $items      = Producto::paraEtiquetasPorCarga($cargaId);
    if ($items !== []) { Producto::marcarEtiquetadasPorCarga($cargaId); }
    $qsBase     = 'carga=' . $cargaId;
    $volverHref = url('admin/ordenes-confirmacion.php') . '?carga=' . $cargaId;
    $volverTxt  = 'Confirmación';
    $activo     = 'captura';
    $ctxTxt     = 'Carga ' . $num;
}

$paginas = array_chunk($items, $PERPAGE);
$totalEt = count($items);
$subtitulo = $ctxTxt . ' · ' . $totalEt . ' etiqueta(s) · ' . $por . ' por hoja · ' . count($paginas) . ' hoja(s)';

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
<div class="no-print d-flex flex-wrap align-items-center gap-2 mb-3">
  <label for="selPor" style="font-size:13px;font-weight:600">Etiquetas por hoja:</label>
  <select id="selPor" class="form-select form-select-sm" style="width:auto"
          onchange="location.href='<?= h(url('admin/ordenes-etiquetas.php') . '?' . $qsBase) ?>&por=' + this.value">
    <?php foreach ($LAYOUTS as $n => $_l): ?>
      <option value="<?= (int)$n ?>" <?= $n === $por ? 'selected' : '' ?>><?= (int)$n ?> por hoja</option>
    <?php endforeach; ?>
  </select>
  <span class="text-muted" style="font-size:12px">Cuantas menos por hoja, más grande la etiqueta.</span>
</div>

<div class="alert no-print" style="background:var(--card);border:1px solid var(--border);font-size:12px;color:var(--muted)" >
  <i class="bi bi-info-circle me-1"></i>Hoja A4, papel autoadhesivo · impresión en blanco y negro.
  Usá <strong>Imprimir / PDF</strong> y elegí "Guardar como PDF" o tu impresora.
</div>

<div class="label-sheet print-area">
<?php foreach ($paginas as $pagina): ?>
  <section class="sheet-page" style="--cols:<?= (int)$L['cols'] ?>;--rows:<?= (int)$L['rows'] ?>;--qr:<?= h($L['qr']) ?>;--fs:<?= h($L['fs']) ?>">
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
