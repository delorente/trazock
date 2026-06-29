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
use Trazock\Models\Destino;
use Trazock\Models\Orden;
use Trazock\Models\Zona;

$user = Auth::requierePanel(['admin', 'gestor', 'logistica']); // gestor = Supervisor (solo reportes)
$puedeMarcar = in_array($user['rol'], ['admin', 'logistica'], true); // marcas/edición: gestor solo lectura

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

    $encabezados = ['Lote', 'Nº orden', 'Categoría', 'Nº remito', 'Hoja ruta', 'Transportista', 'F. carga',
                    'F. remito', 'Tipo', 'Cliente', 'Teléfono', 'Provincia', 'Localidad',
                    'm³', 'Valor declarado', 'Ítems', 'Estado', 'F. ingreso'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:R1')->getFont()->setBold(true);

    $fila = 2;
    foreach ($rows as $o) {
        $sheet->setCellValue('A' . $fila, rep_lote($o));
        $sheet->setCellValue('B' . $fila, (string)$o['nro_orden']);
        $sheet->setCellValue('C' . $fila, (string)($o['categoria'] ?? ''));
        $sheet->setCellValue('D' . $fila, (string)($o['nro_remito'] ?? ''));
        $sheet->setCellValueExplicit('E' . $fila, (string)($o['hoja_ruta'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $fila, (string)($o['transportista_nombre'] ?? ''));
        $sheet->setCellValue('G' . $fila, ($o['fecha_carga'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_carga'])) : '');
        $sheet->setCellValue('H' . $fila, (string)($o['fecha_remito'] ?? ''));
        $sheet->setCellValue('I' . $fila, (string)($o['tipo_venta'] ?? ''));
        $sheet->setCellValue('J' . $fila, (string)($o['cliente'] ?? ''));
        $sheet->setCellValueExplicit('K' . $fila, (string)($o['telefonos'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('L' . $fila, trim((string)($o['dest_provincia'] ?? '')));
        $sheet->setCellValue('M' . $fila, trim((string)($o['dest_localidad'] ?? '')));
        $sheet->setCellValue('N' . $fila, (float)($o['m3_total'] ?? 0));
        $sheet->setCellValue('O' . $fila, $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null);
        $sheet->setCellValue('P' . $fila, (int)($o['cant_items'] ?? 0));
        $sheet->setCellValue('Q' . $fila, (string)($o['estado'] ?? ''));
        $sheet->setCellValue('R' . $fila, fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'R') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

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
    $facturas = Orden::facturacionPorTipo($filtros);
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
$zonas       = Zona::todas();
$cargas      = Carga::recientes(100);
$categorias  = Categoria::activas();
$provincias  = Orden::provincias();
$hojasRuta   = Orden::hojasRuta();
$transportistas = Orden::transportistasUsados();
$csrf        = Auth::tokenCSRF();
$paginas     = (int)max(1, ceil($total / $porPagina));

// Destinos sospechosos (fuera de zonas + sin historial): aviso antes de exportar/avisar.
$conocidasProv = Destino::provinciasConocidas();
$provSospechosas = [];
foreach (Orden::conteoPorProvincia($filtros) as $prov => $n) {
    if ($prov === '(sin provincia)') { continue; }
    if (Destino::esSospechosa($prov, $conocidasProv)) { $provSospechosas[$prov] = $n; }
}

// Query string de los filtros (para paginación y export, sin 'pagina').
// Se descartan vacíos y arrays vacíos; http_build_query serializa los arrays (carga[]=…).
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== '' && $v !== []));
// Querystring de retorno (incluye la página) para volver desde el detalle con el filtro intacto.
$volverQS = ($qsBase !== '' ? $qsBase . '&' : '') . 'pagina=' . $pagina;
// Link del banner: filtra el reporte a las provincias sospechosas, conservando el resto de filtros.
$bannerUrl = '';
if ($provSospechosas !== []) {
    $bannerFiltros = $filtros;
    $bannerFiltros['provincia'] = array_keys($provSospechosas);
    $bannerUrl = url('admin/ordenes-reportes.php') . '?' . http_build_query(array_filter($bannerFiltros, static fn($v) => $v !== '' && $v !== []));
}

$acciones =
    ($puedeMarcar
        ? '<button class="btn btn-sm btn-success" id="btnWaOpen" type="button"><i class="bi bi-whatsapp me-1"></i>Avisar entrega (<span id="waCount">0</span>)</button>'
        : '')
    . '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-productos.php') . ($qsBase ? '?' . $qsBase : '')) . '"><i class="bi bi-box-seam me-1"></i>Por productos</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=facturacion') . '"><i class="bi bi-cash-coin me-1"></i>Facturación (Excel)</a>'
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';

panel_header('Reportes', $user, 'reportes', 'Facturación por m³/destino · armado de rutas', $acciones);

// Opciones para los filtros multi.
$cargaOpts = [];
foreach ($cargas as $c) {
    if ($c['estado'] !== 'confirmada') { continue; }
    $cargaOpts[] = [(string)$c['id'], carga_num((int)$c['id'], (string)$c['created_at']) . ' · ' . (int)$c['cantidad_ordenes'] . ' órd.'];
}
$provOpts = array_map(static fn($p) => [$p, $p], $provincias);
$hrOpts   = array_map(static fn($h) => [$h, $h], $hojasRuta);
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
    <div>
      <label class="form-label">Buscar</label>
      <input type="text" class="form-control form-control-sm" name="q" value="<?= h($filtros['q']) ?>" placeholder="Orden, remito, cliente…">
    </div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Buscar</button>
      <?php if ($qsBase !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/ordenes-reportes.php')) ?>">Limpiar</a><?php endif; ?>
    </div>
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

<?php if ($provSospechosas !== []):
  $partes = [];
  foreach ($provSospechosas as $p => $n) { $partes[] = h($p) . ' (' . $n . ')'; }
?>
<a href="<?= h($bannerUrl) ?>" class="alert alert-warning no-print d-flex align-items-start gap-2 text-decoration-none" role="alert" style="font-size:13px" title="Ver solo estas órdenes">
  <i class="bi bi-geo-alt-fill mt-1"></i>
  <div><strong><?= array_sum($provSospechosas) ?> orden(es)</strong> con destino fuera de tus zonas de reparto y sin historial: <?= implode(', ', $partes) ?>. Revisá que no sea un error de carga antes de exportar facturación o avisar a los clientes. <strong><i class="bi bi-funnel-fill me-1"></i>Clic para filtrar estas órdenes.</strong></div>
</a>
<?php endif; ?>

<div class="print-area">
  <div class="rep-print-title" style="display:none">Reportes de órdenes — <?= (int)$total ?> órdenes · <?= number_format($totales['m3'], 2, ',', '.') ?> m³</div>
  <div class="card">
    <div class="tz-table-scroll" style="overflow:auto">
      <table class="table table-hover mb-0">
        <thead><tr>
          <?php if ($puedeMarcar): ?><th class="no-print" style="width:30px"><input type="checkbox" id="waChkAll" title="Seleccionar todas" class="form-check-input"></th><?php endif; ?>
          <th style="width:58px">Marca</th>
          <th>Lote</th><th>Nº orden</th><th>Cliente</th><th>Categoría</th><th style="text-align:center">Ítems</th><th>Provincia</th><th>Localidad</th><th>Teléfono</th><th>m³</th>
          <th>Tipo</th><th>F. remito</th><th>Nº remito</th><th>Hoja ruta</th><th>Transportista</th><th>F. carga</th><th>F. ingreso</th>
          <th>Estado</th><th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php if ($ordenes === []): ?>
          <tr><td colspan="<?= $puedeMarcar ? 20 : 19 ?>" class="text-muted" style="text-align:center;padding:1.5rem">No hay órdenes para los filtros seleccionados.</td></tr>
        <?php else: foreach ($ordenes as $o):
            $tv = (string)($o['tipo_venta'] ?? '');
        ?>
          <?php $marca = (string)($o['marca'] ?? ''); $obs = trim((string)($o['observaciones'] ?? '')); ?>
          <tr>
            <?php if ($puedeMarcar): ?><td class="no-print"><input type="checkbox" class="form-check-input wa-chk" value="<?= (int)$o['id'] ?>" data-nro="<?= h((string)$o['nro_orden']) ?>"></td><?php endif; ?>
            <td style="white-space:nowrap">
              <?php if ($puedeMarcar): ?>
              <button type="button" class="tz-marca-btn <?= $marca === 'no_entregar' ? 'on-ne' : '' ?>" data-id="<?= (int)$o['id'] ?>" data-marca="no_entregar" title="No entregar"><i class="bi bi-x-octagon-fill"></i></button>
              <button type="button" class="tz-marca-btn <?= $marca === 'prioridad' ? 'on-pr' : '' ?>" data-id="<?= (int)$o['id'] ?>" data-marca="prioridad" title="Prioridad"><i class="bi bi-lightning-charge-fill"></i></button>
              <?php else: ?>
              <?php if ($marca === 'no_entregar'): ?><i class="bi bi-x-octagon-fill" style="color:#f87171" title="No entregar"></i><?php elseif ($marca === 'prioridad'): ?><i class="bi bi-lightning-charge-fill" style="color:#fbbf24" title="Prioridad"></i><?php endif; ?>
              <?php endif; ?>
              <?php if ($obs !== ''): ?><i class="bi bi-chat-left-text-fill tz-obs-ic" title="<?= h($obs) ?>"></i><?php endif; ?>
            </td>
            <td class="mono" style="font-size:12px;color:var(--muted)"><?= h(rep_lote($o)) ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)$o['nro_orden']) ?></td>
            <td style="font-size:13px"><?= h((string)($o['cliente'] ?? '') !== '' ? (string)$o['cliente'] : '—') ?></td>
            <td style="font-size:13px"><?= h((string)($o['categoria'] ?? '—')) ?></td>
            <td style="text-align:center"><?= (int)($o['cant_items'] ?? 0) ?></td>
            <td style="font-size:13px"><?= h((string)($o['dest_provincia'] ?? '') !== '' ? (string)$o['dest_provincia'] : '—') ?><?php if (Destino::esSospechosa((string)($o['dest_provincia'] ?? ''), $conocidasProv)): ?> <i class="bi bi-exclamation-triangle-fill text-warning" title="Destino fuera de tus zonas/envíos habituales — revisá"></i><?php endif; ?></td>
            <td style="font-size:13px"><?= h((string)($o['dest_localidad'] ?? '') !== '' ? (string)$o['dest_localidad'] : '—') ?></td>
            <td style="font-size:12px;color:var(--muted)">
              <?= h((string)($o['telefonos'] ?? '') !== '' ? (string)$o['telefonos'] : '—') ?>
              <?php if ((string)($o['telefono_wa'] ?? '') === ''): ?><i class="bi bi-whatsapp text-danger" title="Sin teléfono apto para WhatsApp"></i><?php endif; ?>
            </td>
            <td><?= number_format((float)($o['m3_total'] ?? 0), 2, ',', '.') ?></td>
            <td><?php if ($tv !== ''): ?><span class="badge b-<?= h(strtoupper($tv)) ?>"><?= h(ucfirst($tv)) ?></span><?php else: ?>—<?php endif; ?></td>
            <td style="color:var(--muted)"><?= h(($o['fecha_remito'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_remito'])) : '—') ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)($o['nro_remito'] ?? '—')) ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)($o['hoja_ruta'] ?? '') !== '' ? (string)$o['hoja_ruta'] : '—') ?></td>
            <td style="font-size:12px"><?= h((string)($o['transportista_nombre'] ?? '') !== '' ? (string)$o['transportista_nombre'] : '—') ?></td>
            <td style="color:var(--muted)"><?= h(($o['fecha_carga'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_carga'])) : '—') ?></td>
            <td style="color:var(--muted)"><?= h(fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i')) ?></td>
            <td><?= estado_badge((string)($o['estado'] ?? '')) ?></td>
            <td class="no-print"><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$o['id'] . '&vol=' . rawurlencode($volverQS)) ?>">Ver</a></td>
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
<style>
/* Encabezado de la tabla fijo al hacer scroll (la tabla scrollea dentro del alto disponible). */
.tz-table-scroll{max-height:calc(100vh - 230px)}
.tz-table-scroll thead th{position:sticky;top:0;z-index:3;background:var(--card,#fff);box-shadow:inset 0 -1px 0 var(--border,#dee2e6)}
@media print{.tz-table-scroll{max-height:none!important;overflow:visible!important}.tz-table-scroll thead th{position:static!important;box-shadow:none!important}}
@media print{.rep-print-title{display:block!important}}
.tz-marca-btn{background:none;border:none;padding:0 3px;cursor:pointer;color:#475569;opacity:.45;font-size:15px;line-height:1}
.tz-marca-btn:hover{opacity:.9}
.tz-marca-btn.on-ne{color:#ef4444;opacity:1}
.tz-marca-btn.on-pr{color:#f59e0b;opacity:1}
.tz-obs-ic{color:#60a5fa;font-size:13px;margin-left:3px}
@media print{.tz-marca-btn:not(.on-ne):not(.on-pr){display:none}}
</style>
<?php filtro_multi_script(); ?>
<script>
(function () {
  const URL = <?= json_encode(url('api/orden-marca.php')) ?>, CSRF = <?= json_encode($csrf) ?>;
  document.querySelectorAll('.tz-marca-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      const cell = btn.closest('td');
      const cls  = btn.dataset.marca === 'no_entregar' ? 'on-ne' : 'on-pr';
      const nuevo = btn.classList.contains(cls) ? '' : btn.dataset.marca; // toggle
      try {
        const r = await fetch(URL, {
          method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: CSRF, id: +btn.dataset.id, marca: nuevo })
        });
        const d = await r.json();
        if (!r.ok || !d.ok) throw new Error(d.error || 'No se pudo marcar.');
        cell.querySelectorAll('.tz-marca-btn').forEach(b => b.classList.remove('on-ne', 'on-pr'));
        if (d.marca === 'no_entregar') cell.querySelector('[data-marca="no_entregar"]').classList.add('on-ne');
        else if (d.marca === 'prioridad') cell.querySelector('[data-marca="prioridad"]').classList.add('on-pr');
      } catch (e) { alert(e.message); }
    });
  });
})();
</script>

<?php if ($puedeMarcar): ?>
<!-- Aviso de entrega por WhatsApp a las órdenes seleccionadas -->
<div class="modal fade" id="modalWa" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Avisar entrega por WhatsApp</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Se enviará el aviso a <strong><span id="waModalCount">0</span></strong> orden(es) seleccionada(s), con los botones <em>Confirmar</em> y <em>Reprogramar</em>. Si el cliente elige reprogramar, la orden queda marcada como <em>no entregar</em>.</p>
        <div class="mb-3">
          <label class="form-label" for="waFecha">Fecha de entrega *</label>
          <input type="date" class="form-control" id="waFecha" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label" for="waHorario">Horario</label>
          <input type="text" class="form-control" id="waHorario" maxlength="40" value="8 a 17 hs">
        </div>
        <div id="waResultado" class="small mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-success" id="waEnviar"><i class="bi bi-send me-1"></i>Enviar avisos</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const ENVIAR_URL = <?= json_encode(url('api/confirmacion-enviar.php')) ?>, CSRF = <?= json_encode($csrf) ?>;
  const chkAll = document.getElementById('waChkAll');
  const cuenta = document.getElementById('waCount');
  const modalEl = document.getElementById('modalWa');
  const modal = new bootstrap.Modal(modalEl);

  function seleccionadas() {
    return Array.from(document.querySelectorAll('.wa-chk:checked'));
  }
  function refrescar() {
    if (cuenta) cuenta.textContent = String(seleccionadas().length);
  }
  document.querySelectorAll('.wa-chk').forEach(c => c.addEventListener('change', refrescar));
  if (chkAll) chkAll.addEventListener('change', function () {
    document.querySelectorAll('.wa-chk').forEach(c => { c.checked = chkAll.checked; });
    refrescar();
  });
  refrescar();

  document.getElementById('btnWaOpen').addEventListener('click', function () {
    const n = seleccionadas().length;
    if (n === 0) { alert('Seleccioná al menos una orden (casilla a la izquierda de cada fila).'); return; }
    document.getElementById('waModalCount').textContent = String(n);
    document.getElementById('waResultado').innerHTML = '';
    document.getElementById('waEnviar').disabled = false;
    modal.show();
  });

  document.getElementById('waEnviar').addEventListener('click', async function () {
    const ids = seleccionadas().map(c => +c.value);
    if (ids.length === 0) { alert('No hay órdenes seleccionadas.'); return; }
    const fecha = document.getElementById('waFecha').value;
    const horario = document.getElementById('waHorario').value.trim();
    if (!fecha) { alert('Elegí una fecha de entrega.'); return; }

    const btn = this, res = document.getElementById('waResultado');
    btn.disabled = true;
    res.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Enviando…</span>';
    try {
      const r = await fetch(ENVIAR_URL, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF, ids: ids, fecha: fecha, horario: horario })
      });
      const d = await r.json();
      if (!r.ok || !d.ok) throw new Error(d.error || 'No se pudo enviar.');
      let html = '<div class="alert alert-success py-2 mb-2">Enviados: <strong>' + d.enviadas + '</strong>'
               + (d.errores ? ' · Con error: <strong>' + d.errores + '</strong>' : '') + '</div>';
      const fallidos = (d.detalle || []).filter(x => !x.ok);
      if (fallidos.length) {
        html += '<ul class="mb-0 ps-3">' + fallidos.map(x => '<li>' + x.nro_orden + ': ' + (x.error || 'error') + '</li>').join('') + '</ul>';
      }
      res.innerHTML = html;
    } catch (e) {
      res.innerHTML = '<div class="alert alert-danger py-2 mb-0">' + e.message + '</div>';
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
<?php
panel_footer();
