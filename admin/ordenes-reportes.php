<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-reportes.php — grilla de órdenes con filtros (facturación por
// m³/destino, armado de rutas). Sumbar (Σ órdenes/ítems/m³), export a Excel,
// e impresión/PDF (CSS print). admin/gestor.
//   ?export=xlsx → descarga el Excel con los filtros aplicados.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\Orden;

$user = Auth::requierePanel(); // admin o gestor

$filtros = [
    'q'           => trim((string)($_GET['q'] ?? '')),
    'provincia'   => trim((string)($_GET['provincia'] ?? '')),
    'estado'      => trim((string)($_GET['estado'] ?? '')),
    'tipo_venta'  => trim((string)($_GET['tipo_venta'] ?? '')),
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
];

/** Destino legible "Localidad · Provincia". */
function rep_destino(array $o): string
{
    $loc  = trim((string)($o['dest_localidad'] ?? ''));
    $prov = trim((string)($o['dest_provincia'] ?? ''));
    $d = $loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov;
    return $d !== '' ? $d : '—';
}

// --- Modo export: stream del xlsx --------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $rows = Orden::buscar($filtros, 5000, 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Órdenes');

    $encabezados = ['Nº orden', 'Nº remito', 'F. remito', 'Tipo', 'Cliente', 'Destino',
                    'm³', 'Valor declarado', 'Ítems', 'Estado', 'F. ingreso'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:K1')->getFont()->setBold(true);

    $fila = 2;
    foreach ($rows as $o) {
        $sheet->setCellValue('A' . $fila, (string)$o['nro_orden']);
        $sheet->setCellValue('B' . $fila, (string)($o['nro_remito'] ?? ''));
        $sheet->setCellValue('C' . $fila, (string)($o['fecha_remito'] ?? ''));
        $sheet->setCellValue('D' . $fila, (string)($o['tipo_venta'] ?? ''));
        $sheet->setCellValue('E' . $fila, (string)($o['cliente'] ?? ''));
        $sheet->setCellValue('F' . $fila, rep_destino($o));
        $sheet->setCellValue('G' . $fila, (float)($o['m3_total'] ?? 0));
        $sheet->setCellValue('H' . $fila, $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null);
        $sheet->setCellValue('I' . $fila, (int)($o['cant_items'] ?? 0));
        $sheet->setCellValue('J' . $fila, (string)($o['estado'] ?? ''));
        $sheet->setCellValue('K' . $fila, fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'K') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ordenes_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo página -------------------------------------------------------------
require __DIR__ . '/_layout.php';

$porPagina = 50;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

$total     = Orden::contar($filtros);
$totales   = Orden::totales($filtros);
$ordenes   = Orden::buscar($filtros, $porPagina, $offset);
$provincias = Orden::provincias();
$paginas   = (int)max(1, ceil($total / $porPagina));

// Query string de los filtros (para paginación y export, sin 'pagina').
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== ''));

$acciones =
    '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Reportes', $user, 'reportes', 'Facturación por m³/destino · armado de rutas', $acciones);
?>
<form method="get" action="<?= h(url('admin/ordenes-reportes.php')) ?>" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.6rem;margin-bottom:.6rem">
    <div>
      <label class="form-label">Destino / prov.</label>
      <select class="form-select form-select-sm" name="provincia">
        <option value="">Todas</option>
        <?php foreach ($provincias as $p): ?>
          <option value="<?= h($p) ?>" <?= $p === $filtros['provincia'] ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Estado</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos</option>
        <?php foreach (Orden::ESTADOS as $e): ?>
          <option value="<?= h($e) ?>" <?= $e === $filtros['estado'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $e)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Tipo de venta</label>
      <select class="form-select form-select-sm" name="tipo_venta">
        <option value="">Todos</option>
        <option value="online" <?= $filtros['tipo_venta'] === 'online' ? 'selected' : '' ?>>Online</option>
        <option value="local"  <?= $filtros['tipo_venta'] === 'local'  ? 'selected' : '' ?>>Local</option>
      </select>
    </div>
    <div><label class="form-label">F. remito desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
    <div><label class="form-label">F. remito hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
  </div>
  <div class="d-flex gap-2 flex-wrap" style="max-width:520px">
    <div class="input-group input-group-sm" style="flex:1;min-width:200px">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" class="form-control" name="q" value="<?= h($filtros['q']) ?>" placeholder="Orden, remito, cliente…">
    </div>
    <button class="btn btn-primary btn-sm px-3" type="submit">Buscar</button>
    <?php if ($qsBase !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/ordenes-reportes.php')) ?>">Limpiar</a><?php endif; ?>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:#60a5fa"><?= number_format($totales['ordenes'], 0, ',', '.') ?></div><div class="sumbar-l">Órdenes filtradas</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= number_format($totales['m3'], 2, ',', '.') ?></div><div class="sumbar-l">m³ total</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= number_format($totales['items'], 0, ',', '.') ?></div><div class="sumbar-l">Ítems</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)">Actualizado <?= h(fmt_fecha(date('Y-m-d H:i:s'), 'd/m/Y · H:i')) ?></div>
</div>

<div class="print-area">
  <div class="rep-print-title" style="display:none">Reportes de órdenes — <?= (int)$total ?> órdenes · <?= number_format($totales['m3'], 2, ',', '.') ?> m³</div>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>Nº orden</th><th style="text-align:center">Ítems</th><th>Destino</th><th>m³</th>
          <th>Tipo</th><th>F. remito</th><th>Nº remito</th><th>F. ingreso</th>
          <th>Estado</th><th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php if ($ordenes === []): ?>
          <tr><td colspan="10" class="text-muted" style="text-align:center;padding:1.5rem">No hay órdenes para los filtros seleccionados.</td></tr>
        <?php else: foreach ($ordenes as $o):
            $tv = (string)($o['tipo_venta'] ?? '');
        ?>
          <tr>
            <td class="mono" style="font-size:12px"><?= h((string)$o['nro_orden']) ?></td>
            <td style="text-align:center"><?= (int)($o['cant_items'] ?? 0) ?></td>
            <td style="font-size:13px"><?= h(rep_destino($o)) ?></td>
            <td><?= number_format((float)($o['m3_total'] ?? 0), 2, ',', '.') ?></td>
            <td><?php if ($tv !== ''): ?><span class="badge b-<?= h(strtoupper($tv)) ?>"><?= h(ucfirst($tv)) ?></span><?php else: ?>—<?php endif; ?></td>
            <td style="color:var(--muted)"><?= h(($o['fecha_remito'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_remito'])) : '—') ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)($o['nro_remito'] ?? '—')) ?></td>
            <td style="color:var(--muted)"><?= h(fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i')) ?></td>
            <td><?= estado_badge((string)($o['estado'] ?? '')) ?></td>
            <td class="no-print"><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$o['id']) ?>">Ver</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($paginas > 1): ?>
    <div class="d-flex align-items-center justify-content-between no-print" style="padding:.5rem 1rem;border-top:1px solid var(--border)">
      <span class="text-muted" style="font-size:12px">Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
      <nav><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-reportes.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina - 1)) ?>">‹</a></li>
        <?php for ($p = 1; $p <= $paginas; $p++): ?>
          <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-reportes.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . $p) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-reportes.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina + 1)) ?>">›</a></li>
      </ul></nav>
    </div>
    <?php endif; ?>
  </div>
</div>
<style>@media print{.rep-print-title{display:block!important}}</style>
<?php
panel_footer();
