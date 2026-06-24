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
use Trazock\Models\Carga;
use Trazock\Models\Categoria;
use Trazock\Models\Orden;
use Trazock\Models\Zona;

$user = Auth::requierePanel(['admin', 'gestor']); // gestor = Supervisor (solo reportes)

$filtros = [
    'q'           => trim((string)($_GET['q'] ?? '')),
    'categoria'   => trim((string)($_GET['categoria'] ?? '')),
    'zona'        => trim((string)($_GET['zona'] ?? '')),
    'carga'       => trim((string)($_GET['carga'] ?? '')),
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

/** Nº de carga/lote de ingreso al que pertenece la orden (índice para agrupar). */
function rep_lote(array $o): string
{
    return $o['carga_id'] ? carga_num((int)$o['carga_id'], (string)($o['fecha_ingreso'] ?? '')) : '—';
}

// --- Modo export: stream del xlsx --------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $rows = Orden::buscar($filtros, 5000, 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Órdenes');

    $encabezados = ['Lote', 'Nº orden', 'Categoría', 'Nº remito', 'F. remito', 'Tipo', 'Cliente', 'Teléfono', 'Destino',
                    'm³', 'Valor declarado', 'Ítems', 'Estado', 'F. ingreso'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:N1')->getFont()->setBold(true);

    $fila = 2;
    foreach ($rows as $o) {
        $sheet->setCellValue('A' . $fila, rep_lote($o));
        $sheet->setCellValue('B' . $fila, (string)$o['nro_orden']);
        $sheet->setCellValue('C' . $fila, (string)($o['categoria'] ?? ''));
        $sheet->setCellValue('D' . $fila, (string)($o['nro_remito'] ?? ''));
        $sheet->setCellValue('E' . $fila, (string)($o['fecha_remito'] ?? ''));
        $sheet->setCellValue('F' . $fila, (string)($o['tipo_venta'] ?? ''));
        $sheet->setCellValue('G' . $fila, (string)($o['cliente'] ?? ''));
        $sheet->setCellValueExplicit('H' . $fila, (string)($o['telefonos'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('I' . $fila, rep_destino($o));
        $sheet->setCellValue('J' . $fila, (float)($o['m3_total'] ?? 0));
        $sheet->setCellValue('K' . $fila, $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null);
        $sheet->setCellValue('L' . $fila, (int)($o['cant_items'] ?? 0));
        $sheet->setCellValue('M' . $fila, (string)($o['estado'] ?? ''));
        $sheet->setCellValue('N' . $fila, fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'N') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ordenes_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo export Facturación: una factura por tipo (m³ por destino) -----------
// Cada tipo (online/local) es una hoja: ítems = m³ por provincia de destino y, al
// pie, transportista(s), fecha(s) de carga y nº(s) de hoja de ruta del/los doc(s).
if (($_GET['export'] ?? '') === 'facturacion') {
    $facturas = Orden::facturacion($filtros);
    $tvLabel  = ['online' => 'Online', 'local' => 'Local', '' => 'Sin tipo'];

    $spreadsheet = new Spreadsheet();

    if ($facturas === []) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturación');
        $sheet->setCellValue('A1', 'No hay órdenes para los filtros seleccionados.');
    } else {
        $primera = true;
        foreach ($facturas as $tipo => $f) {
            $titulo = $tvLabel[$tipo] ?? 'Sin tipo';
            $sheet  = $primera ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $primera = false;
            $sheet->setTitle(mb_substr('Factura ' . $titulo, 0, 31));

            $sheet->setCellValue('A1', 'FACTURA — ' . $titulo);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $sheet->setCellValue('A3', 'Destino');
            $sheet->setCellValue('B3', 'm³');
            $sheet->getStyle('A3:B3')->getFont()->setBold(true);

            $fila = 4;
            foreach ($f['destinos'] as $d) {
                $sheet->setCellValue('A' . $fila, (string)$d['provincia']);
                $sheet->setCellValue('B' . $fila, (float)$d['m3']);
                $fila++;
            }
            $sheet->setCellValue('A' . $fila, 'TOTAL');
            $sheet->setCellValue('B' . $fila, (float)$f['total_m3']);
            $sheet->getStyle('A' . $fila . ':B' . $fila)->getFont()->setBold(true);

            // Pie: transportista(s) / fecha(s) / hoja(s) de ruta.
            $fechasFmt = implode(', ', array_map(
                static fn(string $x): string => date('d/m/Y', strtotime($x)),
                array_values(array_filter(explode(',', (string)$f['fechas']), static fn($x) => trim($x) !== ''))
            ));
            $pie = [
                ['Transportista(s):', $f['transportistas'] !== '' ? $f['transportistas'] : '—'],
                ['Fecha(s) de carga:', $fechasFmt !== '' ? $fechasFmt : '—'],
                ['Hoja(s) de ruta:',  $f['hojas_ruta'] !== '' ? $f['hojas_ruta'] : '—'],
            ];
            $fila += 2;
            foreach ($pie as [$lbl, $val]) {
                $sheet->setCellValue('A' . $fila, $lbl);
                $sheet->getStyle('A' . $fila)->getFont()->setBold(true);
                $sheet->setCellValueExplicit('B' . $fila, (string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $fila++;
            }
            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);
        }
        $spreadsheet->setActiveSheetIndex(0);
    }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="facturacion_' . date('Ymd_His') . '.xlsx"');
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
$zonas      = Zona::todas();
$cargas     = Carga::recientes(100);
$categorias = Categoria::activas();
$paginas    = (int)max(1, ceil($total / $porPagina));

// Query string de los filtros (para paginación y export, sin 'pagina').
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== ''));

$acciones =
    '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-productos.php') . ($qsBase ? '?' . $qsBase : '')) . '"><i class="bi bi-box-seam me-1"></i>Por productos</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=facturacion') . '"><i class="bi bi-cash-coin me-1"></i>Facturación</a>'
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Reportes', $user, 'reportes', 'Facturación por m³/destino · armado de rutas', $acciones);
?>
<form method="get" action="<?= h(url('admin/ordenes-reportes.php')) ?>" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.6rem;margin-bottom:.6rem">
    <div>
      <label class="form-label">Categoría</label>
      <select class="form-select form-select-sm" name="categoria">
        <option value="">Todas</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $filtros['categoria'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Zona de reparto</label>
      <select class="form-select form-select-sm" name="zona">
        <option value="">Todas</option>
        <?php foreach ($zonas as $z): if (!$z['activo']) continue; ?>
          <option value="<?= (int)$z['id'] ?>" <?= (string)$z['id'] === $filtros['zona'] ? 'selected' : '' ?>><?= h($z['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Lote (carga)</label>
      <select class="form-select form-select-sm" name="carga">
        <option value="">Todas</option>
        <?php foreach ($cargas as $c): if ($c['estado'] !== 'confirmada') continue; ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $filtros['carga'] ? 'selected' : '' ?>>
            <?= h(carga_num((int)$c['id'], (string)$c['created_at'])) ?> · <?= (int)$c['cantidad_ordenes'] ?> órd.</option>
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
    <div><label class="form-label">F. carga desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
    <div><label class="form-label">F. carga hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
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
          <th>Lote</th><th>Nº orden</th><th>Categoría</th><th style="text-align:center">Ítems</th><th>Destino</th><th>Teléfono</th><th>m³</th>
          <th>Tipo</th><th>F. remito</th><th>Nº remito</th><th>F. ingreso</th>
          <th>Estado</th><th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php if ($ordenes === []): ?>
          <tr><td colspan="13" class="text-muted" style="text-align:center;padding:1.5rem">No hay órdenes para los filtros seleccionados.</td></tr>
        <?php else: foreach ($ordenes as $o):
            $tv = (string)($o['tipo_venta'] ?? '');
        ?>
          <tr>
            <td class="mono" style="font-size:12px;color:var(--muted)"><?= h(rep_lote($o)) ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)$o['nro_orden']) ?></td>
            <td style="font-size:13px"><?= h((string)($o['categoria'] ?? '—')) ?></td>
            <td style="text-align:center"><?= (int)($o['cant_items'] ?? 0) ?></td>
            <td style="font-size:13px"><?= h(rep_destino($o)) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h((string)($o['telefonos'] ?? '') !== '' ? (string)$o['telefonos'] : '—') ?></td>
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
