<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-productos.php — reporte "por productos": una fila por ítem físico,
// con su orden, carga (lote), destino y estado. Mismos filtros que Reportes +
// export a Excel e impresión. admin/gestor.
//   ?export=xlsx → descarga el Excel con los filtros aplicados.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\Carga;
use Trazock\Models\Categoria;
use Trazock\Models\Producto;
use Trazock\Models\Zona;

$user = Auth::requierePanel(['admin', 'gestor']); // gestor = Supervisor (solo reportes)

$filtros = [
    'q'            => trim((string)($_GET['q'] ?? '')),
    'categoria'    => trim((string)($_GET['categoria'] ?? '')),
    'zona'         => trim((string)($_GET['zona'] ?? '')),
    'carga'        => filtro_multi_valores('carga'),      // multi (lotes)
    'provincia'    => filtro_multi_valores('provincia'),  // multi (destinos)
    'hoja_ruta'    => filtro_multi_valores('hoja_ruta'),  // multi (hojas de ruta)
    'transportista'=> trim((string)($_GET['transportista'] ?? '')),
    'estado'       => trim((string)($_GET['estado'] ?? '')),
    'tipo_venta'   => trim((string)($_GET['tipo_venta'] ?? '')),
    'fecha_desde'  => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta'  => trim((string)($_GET['fecha_hasta'] ?? '')),
];

/** Destino "Localidad · Provincia". */
function prod_destino(array $p): string
{
    $loc  = trim((string)($p['dest_localidad'] ?? ''));
    $prov = trim((string)($p['dest_provincia'] ?? ''));
    $d = $loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov;
    return $d !== '' ? $d : '—';
}

/** Lote/carga de ingreso del ítem. */
function prod_lote(array $p): string
{
    return $p['carga_id'] ? carga_num((int)$p['carga_id'], (string)($p['fecha_ingreso'] ?? '')) : '—';
}

/** Estado del ítem: ETIQUETADA si tiene etiqueta y sigue INGRESADO. */
function prod_estado(array $p): string
{
    $est = (string)($p['estado_actual'] ?? '');
    return ($p['etiquetada_at'] ?? null) !== null && $est === 'INGRESADO' ? 'ETIQUETADA' : $est;
}

// --- Modo export: stream del xlsx --------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $rows = Producto::reporte($filtros, 5000, 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Productos');

    $encabezados = ['Lote', 'Nº orden', 'Categoría', 'Código', 'Descripción', 'Dimensiones', 'm³',
                    'Ítem', 'Estado', 'Destino', 'Cliente', 'Tipo', 'F. ingreso'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:M1')->getFont()->setBold(true);

    $fila = 2;
    foreach ($rows as $p) {
        $sheet->setCellValue('A' . $fila, prod_lote($p));
        $sheet->setCellValue('B' . $fila, (string)$p['nro_orden']);
        $sheet->setCellValue('C' . $fila, (string)($p['categoria'] ?? ''));
        $sheet->setCellValue('D' . $fila, (string)$p['codigo']);
        $sheet->setCellValue('E' . $fila, (string)($p['descripcion'] ?? ''));
        $sheet->setCellValue('F' . $fila, (string)($p['dimensiones'] ?? ''));
        $sheet->setCellValue('G' . $fila, $p['m3'] !== null ? (float)$p['m3'] : null);
        $sheet->setCellValue('H' . $fila, (int)$p['secuencia']);
        $sheet->setCellValue('I' . $fila, prod_estado($p));
        $sheet->setCellValue('J' . $fila, prod_destino($p));
        $sheet->setCellValue('K' . $fila, (string)($p['cliente'] ?? ''));
        $sheet->setCellValue('L' . $fila, (string)($p['tipo_venta'] ?? ''));
        $sheet->setCellValue('M' . $fila, fmt_fecha((string)($p['fecha_ingreso'] ?? ''), 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'M') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="productos_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo página -------------------------------------------------------------
require __DIR__ . '/_layout.php';

$porPagina = 100;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

$total     = Producto::reporteContar($filtros);
$rows      = Producto::reporte($filtros, $porPagina, $offset);
$zonas       = Zona::todas();
$cargas      = Carga::recientes(100);
$categorias  = Categoria::activas();
$provincias  = \Trazock\Models\Orden::provincias();
$hojasRuta   = \Trazock\Models\Orden::hojasRuta();
$transportistas = \Trazock\Models\Orden::transportistasUsados();
$paginas     = (int)max(1, ceil($total / $porPagina));

// Opciones de los filtros multi (mismas que el reporte por órdenes).
$cargaOpts = [];
foreach ($cargas as $c) {
    if ($c['estado'] !== 'confirmada') { continue; }
    $cargaOpts[] = [(string)$c['id'], carga_num((int)$c['id'], (string)$c['created_at']) . ' · ' . (int)$c['cantidad_ordenes'] . ' órd.'];
}
$provOpts = array_map(static fn($p) => [$p, $p], $provincias);
$hrOpts   = array_map(static fn($h) => [$h, $h], $hojasRuta);

$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== '' && $v !== []));

$acciones =
    '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase : '')) . '"><i class="bi bi-list-ul me-1"></i>Por órdenes</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-productos.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Reporte por productos', $user, 'reportes', 'Un ítem físico por fila', $acciones);
?>
<form method="get" action="<?= h(url('admin/ordenes-productos.php')) ?>" class="card mb-3 no-print" style="padding:.85rem 1rem">
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
    <?php filtro_multi_dropdown('Lote (carga)', 'carga', $cargaOpts, $filtros['carga']); ?>
    <?php filtro_multi_dropdown('Destino (provincia)', 'provincia', $provOpts, $filtros['provincia']); ?>
    <?php filtro_multi_dropdown('Hoja de ruta', 'hoja_ruta', $hrOpts, $filtros['hoja_ruta']); ?>
    <div>
      <label class="form-label">Transportista</label>
      <select class="form-select form-select-sm" name="transportista">
        <option value="">Todos</option>
        <?php foreach ($transportistas as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= (string)$t['id'] === $filtros['transportista'] ? 'selected' : '' ?>><?= h($t['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Estado</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos</option>
        <?php foreach (Producto::ESTADOS as $e): ?>
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
      <input type="text" class="form-control" name="q" value="<?= h($filtros['q']) ?>" placeholder="Código, orden, cliente…">
    </div>
    <button class="btn btn-primary btn-sm px-3" type="submit">Buscar</button>
    <?php if ($qsBase !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/ordenes-productos.php')) ?>">Limpiar</a><?php endif; ?>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:#60a5fa"><?= number_format($total, 0, ',', '.') ?></div><div class="sumbar-l">Productos filtrados</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)">Actualizado <?= h(fmt_fecha(date('Y-m-d H:i:s'), 'd/m/Y · H:i')) ?></div>
</div>

<div class="print-area">
  <div class="rep-print-title" style="display:none">Reporte por productos — <?= (int)$total ?> ítems</div>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>Lote</th><th>Nº orden</th><th>Categoría</th><th>Código</th><th>Descripción</th><th>Dimensiones</th>
          <th>m³</th><th>Ítem</th><th>Estado</th><th>Destino</th><th>F. ingreso</th>
        </tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="11" class="text-muted" style="text-align:center;padding:1.5rem">No hay productos para los filtros seleccionados.</td></tr>
        <?php else: foreach ($rows as $p): ?>
          <tr>
            <td class="mono" style="font-size:12px;color:var(--muted)"><?= h(prod_lote($p)) ?></td>
            <td class="mono" style="font-size:12px"><a style="color:#60a5fa;text-decoration:none" href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$p['orden_id']) ?>"><?= h((string)$p['nro_orden']) ?></a></td>
            <td style="font-size:13px"><?= h((string)($p['categoria'] ?? '—')) ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)$p['codigo']) ?></td>
            <td><?= h((string)($p['descripcion'] ?? '—')) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h((string)($p['dimensiones'] ?? '—')) ?></td>
            <td><?= $p['m3'] !== null ? number_format((float)$p['m3'], 3, ',', '.') : '—' ?></td>
            <td style="color:var(--muted)"><?= (int)$p['secuencia'] ?></td>
            <td><?= estado_badge(prod_estado($p)) ?></td>
            <td style="font-size:13px"><?= h(prod_destino($p)) ?></td>
            <td style="color:var(--muted)"><?= h(fmt_fecha((string)($p['fecha_ingreso'] ?? ''), 'd/m/Y H:i')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($paginas > 1): ?>
    <div class="d-flex align-items-center justify-content-between no-print" style="padding:.5rem 1rem;border-top:1px solid var(--border)">
      <span class="text-muted" style="font-size:12px">Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
      <nav><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-productos.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina - 1)) ?>">‹</a></li>
        <?php for ($p2 = 1; $p2 <= $paginas; $p2++): ?>
          <li class="page-item <?= $p2 === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-productos.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . $p2) ?>"><?= $p2 ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/ordenes-productos.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina + 1)) ?>">›</a></li>
      </ul></nav>
    </div>
    <?php endif; ?>
  </div>
</div>
<style>@media print{.rep-print-title{display:block!important}}</style>
<?php filtro_multi_script(); ?>
<?php
panel_footer();
