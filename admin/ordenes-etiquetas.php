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

// Tamaño FÍSICO de la etiqueta (ancho × alto en mm). El cliente elige el tamaño
// y la hoja A4 acomoda sola las que entran; el resto pasa a la hoja siguiente.
// qr/fs escalan el rótulo a ese tamaño. (Área útil A4 con márgenes 8/6mm.)
$GAP_MM    = 2;
$USABLE_W  = 198; // 210 - 2×6
$USABLE_H  = 281; // 297 - 2×8
$TAMANOS = [
    'grande'  => ['w' => 98, 'h' => 137, 'qr' => '47mm', 'fs' => '16px', 'nom' => 'Grande'],
    'mediana' => ['w' => 98, 'h' => 67,  'qr' => '36mm', 'fs' => '11px', 'nom' => 'Mediana'],
    'chica'   => ['w' => 64, 'h' => 49,  'qr' => '26mm', 'fs' => '9px',  'nom' => 'Chica'],
    'mini'    => ['w' => 48, 'h' => 33,  'qr' => '18mm', 'fs' => '7.5px','nom' => 'Mini'],
];

/** Cuántas columnas/filas/etiquetas de un tamaño entran en la hoja A4. */
$fitGrid = static function (array $t) use ($GAP_MM, $USABLE_W, $USABLE_H): array {
    $cols = max(1, (int)floor(($USABLE_W + $GAP_MM) / ($t['w'] + $GAP_MM)));
    $rows = max(1, (int)floor(($USABLE_H + $GAP_MM) / ($t['h'] + $GAP_MM)));
    return ['cols' => $cols, 'rows' => $rows, 'perpage' => $cols * $rows];
};

$tam = (string)($_GET['tam'] ?? 'mediana');
if (!isset($TAMANOS[$tam])) { $tam = 'mediana'; }
$T       = $TAMANOS[$tam];
$grid    = $fitGrid($T);
$PERPAGE = $grid['perpage'];

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
$cmTxt   = number_format($T['w'] / 10, 1, ',', '') . ' × ' . number_format($T['h'] / 10, 1, ',', '') . ' cm';
$subtitulo = $ctxTxt . ' · ' . $totalEt . ' etiqueta(s) · ' . $T['nom'] . ' (' . $cmTxt . ', ' . $PERPAGE . '/hoja) · ' . count($paginas) . ' hoja(s)';

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
  <label for="selTam" style="font-size:13px;font-weight:600">Tamaño de etiqueta:</label>
  <select id="selTam" class="form-select form-select-sm" style="width:auto"
          onchange="location.href='<?= h(url('admin/ordenes-etiquetas.php') . '?' . $qsBase) ?>&tam=' + this.value">
    <?php foreach ($TAMANOS as $k => $t):
        $g  = $fitGrid($t);
        $cm = number_format($t['w'] / 10, 1, ',', '') . ' × ' . number_format($t['h'] / 10, 1, ',', '') . ' cm';
    ?>
      <option value="<?= h($k) ?>" <?= $k === $tam ? 'selected' : '' ?>><?= h($t['nom']) ?> — <?= h($cm) ?> (<?= (int)$g['perpage'] ?>/hoja)</option>
    <?php endforeach; ?>
  </select>
  <span class="text-muted" style="font-size:12px">La hoja A4 acomoda las que entran; el resto pasa a la hoja siguiente.</span>
</div>

<div class="alert no-print" style="background:var(--card);border:1px solid var(--border);font-size:12px;color:var(--muted)" >
  <i class="bi bi-info-circle me-1"></i>Hoja A4, papel autoadhesivo · impresión en blanco y negro.
  Usá <strong>Imprimir / PDF</strong> y elegí "Guardar como PDF" o tu impresora.
</div>

<div class="label-sheet print-area">
<?php foreach ($paginas as $pagina): ?>
  <section class="sheet-page" style="--cols:<?= (int)$grid['cols'] ?>;--cellw:<?= (int)$T['w'] ?>mm;--cellh:<?= (int)$T['h'] ?>mm;--qr:<?= h($T['qr']) ?>;--fs:<?= h($T['fs']) ?>">
    <?php foreach ($pagina as $it):
        $nro     = (string)$it['nro_orden'];
        $sec     = (int)$it['secuencia'];                       // para el QR/código (no renumera)
        $pos     = (int)($it['posicion'] ?? $it['secuencia']);  // "X de N" visible (contiguo)
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
        <div class="lc"><span><?= h((string)$it['codigo']) ?></span><span class="lqty"><?= $pos ?> de <?= $tot ?></span></div>
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
