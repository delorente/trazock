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
use Trazock\Models\FacturacionDatos;
use Trazock\Models\Orden;
use Trazock\Models\Tarifa;
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
                    'F. remito', 'Tipo', 'Cliente', 'Teléfono', 'Destino',
                    'm³', 'Valor declarado', 'Ítems', 'Estado', 'F. ingreso'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:Q1')->getFont()->setBold(true);

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
        $sheet->setCellValue('L' . $fila, rep_destino($o));
        $sheet->setCellValue('M' . $fila, (float)($o['m3_total'] ?? 0));
        $sheet->setCellValue('N' . $fila, $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null);
        $sheet->setCellValue('O' . $fila, (int)($o['cant_items'] ?? 0));
        $sheet->setCellValue('P' . $fila, (string)($o['estado'] ?? ''));
        $sheet->setCellValue('Q' . $fila, fmt_fecha((string)($o['fecha_ingreso'] ?? ''), 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'Q') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ordenes_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo export Facturación: una factura por tipo (m³ por destino) -----------
// Cada tipo (online/local) es una hoja lista para facturar: encabezado emisor/
// receptor, ítems (m³ × tarifa por provincia = importe), subtotal/IVA/total, pie
// con transportista(s)/fecha(s)/hoja(s) de ruta y un detalle orden por orden.
if (($_GET['export'] ?? '') === 'facturacion') {
    $facturas = Orden::facturacion($filtros);
    $detalle  = Orden::facturacionDetalle($filtros);
    $tarifas  = Tarifa::mapa();
    $fdatos   = FacturacionDatos::get();
    $ivaAlic  = (float)($fdatos['iva_alicuota'] ?? 21);
    $tvLabel  = ['online' => 'Online', 'local' => 'Local', '' => 'Sin tipo'];
    $MON      = '#,##0.00';

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

            $put  = static fn(string $cell, $val) => $sheet->setCellValue($cell, $val);
            $putS = static fn(string $cell, string $val) => $sheet->setCellValueExplicit($cell, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $bold = static fn(string $range) => $sheet->getStyle($range)->getFont()->setBold(true);

            $r = 1;
            $put('A' . $r, 'FACTURA — ' . $titulo); $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(14); $r++;
            $put('A' . $r, 'Fecha de emisión:'); $putS('B' . $r, date('d/m/Y')); $r += 2;

            // Encabezado: emisor y receptor.
            $put('A' . $r, 'EMISOR'); $bold('A' . $r); $r++;
            $putS('A' . $r, (string)($fdatos['emisor_razon_social'] ?? '') ?: '—'); $r++;
            $putS('A' . $r, 'CUIT: ' . (($fdatos['emisor_cuit'] ?? '') ?: '—') . '   ·   IVA: ' . (($fdatos['emisor_iva'] ?? '') ?: '—')); $r++;
            if (($fdatos['emisor_domicilio'] ?? '') !== '') { $putS('A' . $r, (string)$fdatos['emisor_domicilio']); $r++; }
            $r++;
            $put('A' . $r, 'FACTURAR A'); $bold('A' . $r); $r++;
            $putS('A' . $r, (string)($fdatos['receptor_razon_social'] ?? '') ?: '—'); $r++;
            $putS('A' . $r, 'CUIT: ' . (($fdatos['receptor_cuit'] ?? '') ?: '—') . '   ·   IVA: ' . (($fdatos['receptor_iva'] ?? '') ?: '—')); $r++;
            if (($fdatos['receptor_domicilio'] ?? '') !== '') { $putS('A' . $r, (string)$fdatos['receptor_domicilio']); $r++; }
            $r += 2;

            // Ítems: destino · m³ · tarifa · importe.
            $put('A' . $r, 'Destino'); $put('B' . $r, 'm³'); $put('C' . $r, 'Tarifa $/m³'); $put('D' . $r, 'Importe');
            $bold('A' . $r . ':D' . $r); $r++;

            $subtotal = 0.0;
            foreach ($f['destinos'] as $d) {
                $prov   = (string)$d['provincia'];
                $m3     = (float)$d['m3'];
                $tarifa = (float)($tarifas[$prov] ?? 0);
                $imp    = $m3 * $tarifa;
                $subtotal += $imp;
                $put('A' . $r, $prov);
                $put('B' . $r, $m3);
                $put('C' . $r, $tarifa);
                $put('D' . $r, $imp);
                $sheet->getStyle('B' . $r . ':D' . $r)->getNumberFormat()->setFormatCode($MON);
                $r++;
            }

            $ivaMonto = $subtotal * $ivaAlic / 100;
            $total    = $subtotal + $ivaMonto;
            $put('C' . $r, 'Subtotal'); $bold('C' . $r); $put('D' . $r, $subtotal);
            $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode($MON); $r++;
            $put('C' . $r, 'IVA (' . rtrim(rtrim(number_format($ivaAlic, 2, '.', ''), '0'), '.') . '%)'); $bold('C' . $r); $put('D' . $r, $ivaMonto);
            $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode($MON); $r++;
            $put('C' . $r, 'TOTAL'); $put('D' . $r, $total);
            $bold('C' . $r . ':D' . $r);
            $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode($MON); $r += 2;

            // Total m³ del envío (referencia).
            $put('A' . $r, 'Total m³:'); $bold('A' . $r); $put('B' . $r, (float)$f['total_m3']);
            $sheet->getStyle('B' . $r)->getNumberFormat()->setFormatCode($MON); $r += 2;

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
            foreach ($pie as [$lbl, $val]) {
                $put('A' . $r, $lbl); $bold('A' . $r);
                $putS('B' . $r, (string)$val);
                $r++;
            }
            $r += 2;

            // Detalle orden por orden (respaldo).
            $put('A' . $r, 'DETALLE'); $bold('A' . $r); $r++;
            $put('A' . $r, 'Nº orden'); $put('B' . $r, 'Nº remito'); $put('C' . $r, 'Cliente');
            $put('D' . $r, 'Provincia'); $put('E' . $r, 'm³');
            $bold('A' . $r . ':E' . $r); $r++;
            foreach (($detalle[$tipo] ?? []) as $o) {
                $putS('A' . $r, (string)$o['nro_orden']);
                $putS('B' . $r, (string)$o['nro_remito']);
                $put('C' . $r, (string)$o['cliente']);
                $put('D' . $r, (string)$o['provincia']);
                $put('E' . $r, (float)$o['m3']);
                $sheet->getStyle('E' . $r)->getNumberFormat()->setFormatCode($MON);
                $r++;
            }

            foreach (range('A', 'E') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
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

// Query string de los filtros (para paginación y export, sin 'pagina').
// Se descartan vacíos y arrays vacíos; http_build_query serializa los arrays (carga[]=…).
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== '' && $v !== []));

$acciones =
    '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-productos.php') . ($qsBase ? '?' . $qsBase : '')) . '"><i class="bi bi-box-seam me-1"></i>Por productos</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?') . 'export=facturacion') . '"><i class="bi bi-cash-coin me-1"></i>Facturación</a>'
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

<div class="print-area">
  <div class="rep-print-title" style="display:none">Reportes de órdenes — <?= (int)$total ?> órdenes · <?= number_format($totales['m3'], 2, ',', '.') ?> m³</div>
  <div class="card">
    <div style="overflow-x:auto">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th style="width:58px">Marca</th>
          <th>Lote</th><th>Nº orden</th><th>Categoría</th><th style="text-align:center">Ítems</th><th>Destino</th><th>Teléfono</th><th>m³</th>
          <th>Tipo</th><th>F. remito</th><th>Nº remito</th><th>Hoja ruta</th><th>Transportista</th><th>F. carga</th><th>F. ingreso</th>
          <th>Estado</th><th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php if ($ordenes === []): ?>
          <tr><td colspan="17" class="text-muted" style="text-align:center;padding:1.5rem">No hay órdenes para los filtros seleccionados.</td></tr>
        <?php else: foreach ($ordenes as $o):
            $tv = (string)($o['tipo_venta'] ?? '');
        ?>
          <?php $marca = (string)($o['marca'] ?? ''); $obs = trim((string)($o['observaciones'] ?? '')); ?>
          <tr>
            <td style="white-space:nowrap">
              <button type="button" class="tz-marca-btn <?= $marca === 'no_entregar' ? 'on-ne' : '' ?>" data-id="<?= (int)$o['id'] ?>" data-marca="no_entregar" title="No entregar"><i class="bi bi-x-octagon-fill"></i></button>
              <button type="button" class="tz-marca-btn <?= $marca === 'prioridad' ? 'on-pr' : '' ?>" data-id="<?= (int)$o['id'] ?>" data-marca="prioridad" title="Prioridad"><i class="bi bi-lightning-charge-fill"></i></button>
              <?php if ($obs !== ''): ?><i class="bi bi-chat-left-text-fill tz-obs-ic" title="<?= h($obs) ?>"></i><?php endif; ?>
            </td>
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
            <td class="mono" style="font-size:12px"><?= h((string)($o['hoja_ruta'] ?? '') !== '' ? (string)$o['hoja_ruta'] : '—') ?></td>
            <td style="font-size:12px"><?= h((string)($o['transportista_nombre'] ?? '') !== '' ? (string)$o['transportista_nombre'] : '—') ?></td>
            <td style="color:var(--muted)"><?= h(($o['fecha_carga'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_carga'])) : '—') ?></td>
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
<style>
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
<?php
panel_footer();
