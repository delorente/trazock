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
use Trazock\Models\HojaRuta;
use Trazock\Models\Orden;
use Trazock\Models\Prefijo;
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
    'prefijo'      => filtro_multi_valores('prefijo'),    // multi (local / origen)
    'transportista'=> trim((string)($_GET['transportista'] ?? '')),
    'estado'       => trim((string)($_GET['estado'] ?? '')),
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
                    'F. remito', 'Cliente', 'Teléfono', 'Provincia', 'Localidad',
                    'm³', 'Valor declarado', 'Ítems', 'Estado'];
    $sheet->fromArray($encabezados, null, 'A1');
    $sheet->getStyle('A1:P1')->getFont()->setBold(true);

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
        $sheet->setCellValue('I' . $fila, (string)($o['cliente'] ?? ''));
        $sheet->setCellValueExplicit('J' . $fila, (string)($o['telefonos'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('K' . $fila, trim((string)($o['dest_provincia'] ?? '')));
        $sheet->setCellValue('L' . $fila, trim((string)($o['dest_localidad'] ?? '')));
        $sheet->setCellValue('M' . $fila, (float)($o['m3_total'] ?? 0));
        $sheet->setCellValue('N' . $fila, $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null);
        $sheet->setCellValue('O' . $fila, (int)($o['cant_items'] ?? 0));
        $sheet->setCellValue('P' . $fila, (string)($o['estado'] ?? ''));
        $fila++;
    }
    foreach (range('A', 'P') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ordenes_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo export Facturación: una hoja con m³ por destino (provincia) ---------
// Sin separar por tipo de venta: la separación (online / resto) se hace filtrando
// por prefijo (Local) y exportando dos veces.
if (($_GET['export'] ?? '') === 'facturacion') {
    $f = Orden::facturacionResumen($filtros);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Facturación');

    if ($f['destinos'] === []) {
        $sheet->setCellValue('A1', 'No hay órdenes para los filtros seleccionados.');
    } else {
        $sheet->setCellValue('A1', 'FACTURACIÓN — m³ por destino');
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

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="facturacion_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo export "Locales": por orden, con el desglose de productos debajo -----
// Encabezado = nombre del local si hay exactamente un prefijo filtrado.
if (($_GET['export'] ?? '') === 'locales') {
    $rows  = Orden::buscar($filtros, 5000, 0);
    $items = Orden::itemsDeOrdenes(array_map(static fn($o) => (int)$o['id'], $rows));
    $titulo = (is_array($filtros['prefijo']) && count($filtros['prefijo']) === 1)
        ? Prefijo::nombreDe((string)$filtros['prefijo'][0]) : '';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Locales');
    $STR = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;

    $fila = 1;
    if ($titulo !== '') {
        $sheet->setCellValue('A' . $fila, 'Órdenes de ' . $titulo);
        $sheet->getStyle('A' . $fila)->getFont()->setBold(true)->setSize(14);
        $fila += 2;
    }
    if ($rows === []) {
        $sheet->setCellValue('A' . $fila, 'No hay órdenes para los filtros seleccionados.');
    }
    foreach ($rows as $o) {
        $oid = (int)$o['id'];
        $sheet->setCellValue('A' . $fila, 'N° orden'); $sheet->setCellValue('B' . $fila, 'Cliente');
        $sheet->setCellValue('C' . $fila, 'Localidad'); $sheet->setCellValue('D' . $fila, 'Provincia');
        $sheet->setCellValue('E' . $fila, 'Ítems');
        $sheet->getStyle('A' . $fila . ':E' . $fila)->getFont()->setBold(true);
        $fila++;
        $sheet->setCellValueExplicit('A' . $fila, (string)$o['nro_orden'], $STR);
        $sheet->setCellValue('B' . $fila, (string)($o['cliente'] ?? ''));
        $sheet->setCellValue('C' . $fila, (string)($o['dest_localidad'] ?? ''));
        $sheet->setCellValue('D' . $fila, (string)($o['dest_provincia'] ?? ''));
        $sheet->setCellValue('E' . $fila, (int)($o['cant_items'] ?? 0));
        $fila++;
        $its = $items[$oid] ?? [];
        if ($its !== []) {
            $sheet->setCellValue('A' . $fila, 'Descripción'); $sheet->setCellValue('B' . $fila, 'Dimensiones');
            $sheet->getStyle('A' . $fila . ':B' . $fila)->getFont()->setBold(true);
            $fila++;
            foreach ($its as $it) {
                $sheet->setCellValueExplicit('A' . $fila, (string)$it['descripcion'], $STR);
                $sheet->setCellValueExplicit('B' . $fila, (string)$it['dimensiones'], $STR);
                $fila++;
            }
        }
        $fila++; // línea en blanco entre órdenes
    }
    foreach (range('A', 'E') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="locales_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo impresión: renderiza el reporte elegido y dispara la impresión ------
$printRep = (string)($_GET['print'] ?? '');
if ($printRep === 'locales' || $printRep === 'facturacion') {
    $titulo = (is_array($filtros['prefijo']) && count($filtros['prefijo']) === 1)
        ? Prefijo::nombreDe((string)$filtros['prefijo'][0]) : '';
    ?>
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($printRep === 'locales' ? ('Órdenes' . ($titulo !== '' ? ' de ' . $titulo : '')) : 'Facturación') ?></title>
<style>
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;color:#111;margin:24px;font-size:13px}
  h1{font-size:18px;margin:0 0 14px}
  table{border-collapse:collapse;width:100%}
  .ord{margin-bottom:14px;break-inside:avoid}
  .ord th{text-align:left;background:#f1f5f9;padding:4px 8px;border:1px solid #cbd5e1;font-size:12px}
  .ord td{padding:4px 8px;border:1px solid #e2e8f0}
  .items td,.items th{font-size:12px}
  .fact td,.fact th{padding:5px 10px;border:1px solid #cbd5e1}
  @media print{ button{display:none} }
</style></head><body>
<?php if ($printRep === 'locales'):
    $rows  = Orden::buscar($filtros, 5000, 0);
    $items = Orden::itemsDeOrdenes(array_map(static fn($o) => (int)$o['id'], $rows));
?>
  <h1>Órdenes<?= $titulo !== '' ? ' de ' . h($titulo) : '' ?></h1>
  <?php if ($rows === []): ?><p>No hay órdenes para los filtros seleccionados.</p><?php endif; ?>
  <?php foreach ($rows as $o): $its = $items[(int)$o['id']] ?? []; ?>
    <table class="ord">
      <thead><tr><th>N° orden</th><th>Cliente</th><th>Localidad</th><th>Provincia</th><th>Ítems</th></tr></thead>
      <tbody><tr>
        <td class="mono"><?= h((string)$o['nro_orden']) ?></td>
        <td><?= h((string)($o['cliente'] ?? '')) ?></td>
        <td><?= h((string)($o['dest_localidad'] ?? '')) ?></td>
        <td><?= h((string)($o['dest_provincia'] ?? '')) ?></td>
        <td><?= (int)($o['cant_items'] ?? 0) ?></td>
      </tr></tbody>
    </table>
    <?php if ($its !== []): ?>
    <table class="ord items" style="margin-top:-10px">
      <thead><tr><th style="width:55%">Descripción</th><th>Dimensiones</th></tr></thead>
      <tbody>
        <?php foreach ($its as $it): ?>
          <tr><td><?= h($it['descripcion']) ?></td><td><?= h($it['dimensiones']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endforeach; ?>
<?php else: // facturacion
    $f = Orden::facturacionResumen($filtros);
?>
  <h1>Facturación — m³ por destino</h1>
  <table class="fact">
    <thead><tr><th>Destino</th><th>m³</th></tr></thead>
    <tbody>
      <?php foreach ($f['destinos'] as $d): ?>
        <tr><td><?= h((string)$d['provincia']) ?></td><td><?= number_format((float)$d['m3'], 2, ',', '.') ?></td></tr>
      <?php endforeach; ?>
      <tr><th>TOTAL</th><th><?= number_format((float)$f['total_m3'], 2, ',', '.') ?></th></tr>
    </tbody>
  </table>
  <p style="margin-top:12px;font-size:12px">
    <strong>Transportista(s):</strong> <?= h($f['transportistas'] !== '' ? $f['transportistas'] : '—') ?><br>
    <strong>Hoja(s) de ruta:</strong> <?= h($f['hojas_ruta'] !== '' ? $f['hojas_ruta'] : '—') ?>
  </p>
<?php endif; ?>
<button onclick="window.print()" style="margin-top:16px;padding:8px 16px">Imprimir</button>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script>
</body></html>
<?php
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
          . '<button class="btn btn-sm btn-outline-primary" id="btnHrToggle" type="button"><i class="bi bi-signpost-split me-1"></i>Hoja de ruta</button>'
        : '')
    . '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-productos.php') . ($qsBase ? '?' . $qsBase : '')) . '"><i class="bi bi-box-seam me-1"></i>Por productos</a>'
    . '<select id="repSel" class="form-select form-select-sm d-inline-block" style="width:auto;vertical-align:middle">'
    . '<option value="completo">Reporte completo</option>'
    . '<option value="locales">Locales (con ítems)</option>'
    . '<option value="facturacion">Facturación (m³)</option>'
    . '</select>'
    . '<button class="btn btn-sm btn-outline-success" id="btnRepExcel" type="button"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>'
    . '<button class="btn btn-sm btn-outline-secondary" id="btnRepPrint" type="button"><i class="bi bi-printer me-1"></i>Imprimir</button>';

panel_header('Reportes', $user, 'reportes', 'Reportes preconfigurados sobre lo filtrado', $acciones);

// Opciones para los filtros multi.
$cargaOpts = [];
foreach ($cargas as $c) {
    if ($c['estado'] !== 'confirmada') { continue; }
    $cargaOpts[] = [(string)$c['id'], carga_num((int)$c['id'], (string)$c['created_at']) . ' · ' . (int)$c['cantidad_ordenes'] . ' órd.'];
}
$provOpts = array_map(static fn($p) => [$p, $p], $provincias);
$hrOpts   = array_map(static fn($h) => [$h, $h], $hojasRuta);
$prefOpts = Prefijo::paraFiltro();
$hojasAbiertas = $puedeMarcar ? HojaRuta::abiertasParaScan() : [];
?>
<?php if ($puedeMarcar): ?>
<div id="hrBar" class="card mb-2 no-print d-none" style="padding:.6rem 1rem;border-color:#60a5fa">
  <div class="d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label mb-1 small">Hoja de ruta destino</label>
      <select id="hrSel" class="form-select form-select-sm" style="min-width:220px">
        <option value="0">➕ Nueva hoja</option>
        <?php foreach ($hojasAbiertas as $hh): ?>
          <option value="<?= (int)$hh['id'] ?>"><?= h((string)$hh['numero'] . ((string)($hh['destino'] ?? '') !== '' ? ' · ' . (string)$hh['destino'] : '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button id="btnHrAdd" type="button" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Agregar a Hoja de Ruta</button>
    <div id="hrResult" class="small text-muted">Tildá las órdenes de la lista y agregalas. Después "Abrir" para completar y emitir.</div>
  </div>
</div>
<?php endif; ?>
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
    <?php if ($prefOpts !== []) filtro_multi_dropdown('Local (origen)', 'prefijo', $prefOpts, $filtros['prefijo']); ?>
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
          <th>Nº orden</th><th>Cliente</th><th>Categoría</th><th style="text-align:center">Ítems</th><th>Provincia</th><th>Localidad</th><th>Teléfono</th><th>m³</th>
          <th>F. remito</th><th>Nº remito</th><th>Hoja ruta</th><th>Transportista</th><th>F. carga</th>
          <th>Estado</th>
        </tr></thead>
        <tbody>
        <?php if ($ordenes === []): ?>
          <tr><td colspan="<?= $puedeMarcar ? 16 : 15 ?>" class="text-muted" style="text-align:center;padding:1.5rem">No hay órdenes para los filtros seleccionados.</td></tr>
        <?php else: foreach ($ordenes as $o): ?>
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
            <td class="mono" style="font-size:12px"><a href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$o['id'] . '&vol=' . rawurlencode($volverQS)) ?>" style="color:#60a5fa;text-decoration:none"><?= h((string)$o['nro_orden']) ?></a></td>
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
            <td style="color:var(--muted)"><?= h(($o['fecha_remito'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_remito'])) : '—') ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)($o['nro_remito'] ?? '—')) ?></td>
            <td class="mono" style="font-size:12px"><?= h((string)($o['hoja_ruta'] ?? '') !== '' ? (string)$o['hoja_ruta'] : '—') ?></td>
            <td style="font-size:12px"><?= h((string)($o['transportista_nombre'] ?? '') !== '' ? (string)$o['transportista_nombre'] : '—') ?></td>
            <td style="color:var(--muted)"><?= h(($o['fecha_carga'] ?? '') ? date('d/m/Y', strtotime((string)$o['fecha_carga'])) : '—') ?></td>
            <td><?= estado_badge((string)($o['estado'] ?? '')) ?></td>
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
<script>
// Ajusta el alto de la tabla al espacio que queda hasta el fondo de la pantalla,
// así la barra de scroll horizontal queda siempre visible al pie (no hay que bajar
// hasta el final del listado para verla).
(function () {
  function fit() {
    var el = document.querySelector('.tz-table-scroll');
    if (!el) return;
    var top = el.getBoundingClientRect().top;
    el.style.maxHeight = Math.max(220, window.innerHeight - top - 16) + 'px';
  }
  window.addEventListener('resize', fit);
  window.addEventListener('load', fit);
  fit();
})();
// Reportes preconfigurados: el selector + Excel/Imprimir actúan sobre lo filtrado.
(function () {
  var base = <?= json_encode(url('admin/ordenes-reportes.php') . ($qsBase ? '?' . $qsBase . '&' : '?')) ?>;
  var sel = document.getElementById('repSel');
  var ex  = document.getElementById('btnRepExcel');
  var pr  = document.getElementById('btnRepPrint');
  function rep() { return sel ? sel.value : 'completo'; }
  if (ex) ex.addEventListener('click', function () {
    var r = rep(); location.href = base + 'export=' + (r === 'completo' ? 'xlsx' : r);
  });
  if (pr) pr.addEventListener('click', function () {
    var r = rep();
    if (r === 'completo') { window.print(); }
    else { window.open(base + 'print=' + r, '_blank'); }
  });
})();
</script>
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
<script>
// Agregar órdenes seleccionadas a una hoja de ruta (nueva o abierta).
(function () {
  const AGREGAR_URL = <?= json_encode(url('api/hoja-ruta-agregar.php')) ?>;
  const ARMAR_BASE  = <?= json_encode(url('admin/hoja-ruta-armar.php')) ?>;
  const CSRF = <?= json_encode($csrf) ?>;
  const bar = document.getElementById('hrBar');
  const btnToggle = document.getElementById('btnHrToggle');
  const btnAdd = document.getElementById('btnHrAdd');
  const sel = document.getElementById('hrSel');
  const res = document.getElementById('hrResult');
  if (btnToggle) btnToggle.addEventListener('click', function () {
    bar.classList.toggle('d-none');
    if (!bar.classList.contains('d-none')) bar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
  if (btnAdd) btnAdd.addEventListener('click', async function () {
    const ids = Array.from(document.querySelectorAll('.wa-chk:checked')).map(c => +c.value);
    if (ids.length === 0) { alert('Tildá al menos una orden (casilla a la izquierda de cada fila).'); return; }
    btnAdd.disabled = true;
    res.className = 'small text-muted';
    res.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Agregando…';
    try {
      const r = await fetch(AGREGAR_URL, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF, ids: ids, hoja_id: +sel.value })
      });
      const d = await r.json();
      if (!r.ok || !d.ok) throw new Error(d.error || 'No se pudo agregar.');
      // Si era "Nueva", sumar la hoja recién creada al selector y dejarla elegida.
      if (+sel.value === 0 && d.hoja_id) {
        const opt = document.createElement('option');
        opt.value = d.hoja_id; opt.textContent = d.numero;
        sel.appendChild(opt); sel.value = String(d.hoja_id);
      }
      res.className = 'small';
      res.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + d.agregadas + ' agregada(s) a <strong>' + d.numero + '</strong>'
        + (d.ya_estaban ? ' · ' + d.ya_estaban + ' ya estaban' : '') + '</span> '
        + '<a class="btn btn-sm btn-outline-primary ms-2 py-0 px-2" href="' + ARMAR_BASE + '?id=' + d.hoja_id + '"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>';
    } catch (e) {
      res.className = 'small text-danger';
      res.textContent = e.message;
    } finally {
      btnAdd.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
<?php
panel_footer();
