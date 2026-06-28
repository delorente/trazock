<?php
declare(strict_types=1);

// =============================================================================
// admin/rentabilidad.php — resultados por cliente y período: lo facturado
// (cantidad × precio configurado) menos costos variables = margen. admin/gestor.
// Es un CÁLCULO (no un comprobante). No toca el reporte de Facturación.
//   ?export=xlsx → descarga el Excel del período.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\ClienteFacturacion;
use Trazock\Rentabilidad;

$user = Auth::requierePanel(['admin', 'gestor', 'contable']);

$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-01');
$hasta = trim((string)($_GET['hasta'] ?? '')) ?: date('Y-m-d');

$res = Rentabilidad::resultados($desde, $hasta);
$nf  = static fn($n) => '$ ' . number_format((float)$n, 2, ',', '.');
$uLabel = static fn($u) => ClienteFacturacion::UNIDADES[$u] ?? $u;
$pct = static function (array $c): string {
    if ((float)$c['ingresos'] <= 0) { return '—'; }
    return number_format($c['margen'] / $c['ingresos'] * 100, 1, ',', '.') . '%';
};

// --- Export Excel ------------------------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $ss = new Spreadsheet(); $primera = true;
    $hoja = function (string $t, array $h, array $rows) use ($ss, &$primera) {
        $s = $primera ? $ss->getActiveSheet() : $ss->createSheet(); $primera = false;
        $s->setTitle(mb_substr($t, 0, 31));
        $s->fromArray($h, null, 'A1'); $s->getStyle('A1:' . chr(64 + count($h)) . '1')->getFont()->setBold(true);
        $s->fromArray($rows, null, 'A2');
        foreach (range('A', chr(64 + count($h))) as $c) { $s->getColumnDimension($c)->setAutoSize(true); }
    };
    $hoja('Resultados', ['Cliente', 'Unidad', 'Ingresos', 'Costos variables', 'Margen', 'Margen %'],
        array_map(static fn($c) => [
            $c['nombre'], ClienteFacturacion::UNIDADES[$c['unidad']] ?? $c['unidad'],
            (float)$c['ingresos'], (float)$c['costos'], (float)$c['margen'],
            (float)$c['ingresos'] > 0 ? round($c['margen'] / $c['ingresos'] * 100, 1) : null,
        ], $res['clientes']));
    $detalle = [];
    foreach ($res['clientes'] as $c) {
        foreach ($c['detalle'] as $d) {
            $detalle[] = [$c['nombre'], $d['provincia'], ClienteFacturacion::UNIDADES[$c['unidad']] ?? $c['unidad'],
                (float)$d['cantidad'], (float)$d['precio'], (float)$d['importe']];
        }
    }
    $hoja('Detalle ingresos', ['Cliente', 'Destino', 'Unidad', 'Cantidad', 'Precio', 'Importe'], $detalle);
    $cf = $res['costos_fijos'];
    $hoja('Resumen', ['Concepto', 'Monto'], [
        ['Ingresos (facturado)', (float)$res['totales']['ingresos']],
        ['Costos variables', -(float)$res['totales']['costos']],
        ['Margen de contribución', (float)$res['totales']['margen']],
        ['Costos fijos: alquileres', -(float)$cf['alquiler']],
        ['Costos fijos: sueldos', -(float)$cf['sueldo']],
        ['Costos fijos: otros', -(float)$cf['otro']],
        ['Costos fijos (total prorrateado)', -(float)$res['totales']['costos_fijos']],
        ['RESULTADO NETO', (float)$res['totales']['resultado_neto']],
    ]);
    $ss->setActiveSheetIndex(0);
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="rentabilidad_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

require __DIR__ . '/_layout.php';

$qs = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$acciones = '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/rentabilidad.php') . '?' . $qs . '&export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>';
panel_header('Resultados', $user, 'rentabilidad', 'Ingresos − costos variables por cliente (cálculo, no comprobante)', $acciones);
?>
<form method="get" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= h($desde) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= h($hasta) ?>"></div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Ver</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/rentabilidad.php')) ?>">Mes actual</a>
    </div>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:#34d399"><?= $nf($res['totales']['ingresos']) ?></div><div class="sumbar-l">Ingresos (facturado)</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:#fbbf24"><?= $nf($res['totales']['costos']) ?></div><div class="sumbar-l">Costos variables</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $nf($res['totales']['margen']) ?></div><div class="sumbar-l">Margen de contribución</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:<?= $res['totales']['resultado_neto'] >= 0 ? '#34d399' : '#f87171' ?>"><?= $nf($res['totales']['resultado_neto']) ?></div><div class="sumbar-l">Resultado neto</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)"><?= h(date('d/m/Y', strtotime($desde))) ?> – <?= h(date('d/m/Y', strtotime($hasta))) ?></div>
</div>

<div class="card">
  <div class="card-header">Por cliente</div>
  <div style="overflow-x:auto">
    <table class="table table-hover mb-0">
      <thead><tr><th>Cliente</th><th>Unidad</th><th class="text-end">Ingresos</th><th class="text-end">Costos var.</th><th class="text-end">Margen</th><th class="text-end">Margen %</th></tr></thead>
      <tbody>
      <?php if ($res['clientes'] === []): ?>
        <tr><td colspan="6" class="text-muted text-center py-4">Sin datos. Configurá la <a href="<?= h(url('admin/facturacion-clientes.php')) ?>">facturación por cliente</a> y cargá costos en los viajes.</td></tr>
      <?php endif; ?>
      <?php foreach ($res['clientes'] as $c): ?>
        <tr>
          <td><?= h((string)$c['nombre']) ?></td>
          <td><?= h($uLabel($c['unidad'])) ?></td>
          <td class="text-end"><?= $nf($c['ingresos']) ?></td>
          <td class="text-end"><?= $nf($c['costos']) ?></td>
          <td class="text-end" style="font-weight:600;color:<?= $c['margen'] >= 0 ? '#34d399' : '#f87171' ?>"><?= $nf($c['margen']) ?></td>
          <td class="text-end"><?= $pct($c) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($res['clientes'] !== []): ?>
      <tfoot>
        <tr class="table-light" style="font-weight:700">
          <td colspan="2">Total</td>
          <td class="text-end"><?= $nf($res['totales']['ingresos']) ?></td>
          <td class="text-end"><?= $nf($res['totales']['costos']) ?></td>
          <td class="text-end"><?= $nf($res['totales']['margen']) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($res['sin_asignar_costos'] > 0): ?>
  <div class="card-footer text-muted small">Costos de viaje sin cliente atribuible (sin m³ por orden): <?= $nf($res['sin_asignar_costos']) ?> — incluidos en el total de costos.</div>
  <?php endif; ?>
</div>
<?php $cf = $res['costos_fijos']; $t = $res['totales']; ?>
<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Resultado del período</div>
      <div style="overflow-x:auto">
        <table class="table mb-0">
          <tbody>
            <tr><td>Margen de contribución</td><td class="text-end"><?= $nf($t['margen']) ?></td></tr>
            <tr><td class="ps-4 text-muted">Costos fijos: alquileres</td><td class="text-end text-muted">−<?= $nf($cf['alquiler']) ?></td></tr>
            <tr><td class="ps-4 text-muted">Costos fijos: sueldos</td><td class="text-end text-muted">−<?= $nf($cf['sueldo']) ?></td></tr>
            <tr><td class="ps-4 text-muted">Costos fijos: otros</td><td class="text-end text-muted">−<?= $nf($cf['otro']) ?></td></tr>
            <tr><td>Costos fijos (prorrateados)</td><td class="text-end">−<?= $nf($t['costos_fijos']) ?></td></tr>
            <tr class="table-light" style="font-weight:700"><td>Resultado neto</td><td class="text-end" style="color:<?= $t['resultado_neto'] >= 0 ? '#34d399' : '#f87171' ?>"><?= $nf($t['resultado_neto']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted small">Los costos fijos se cargan en <a href="<?= h(url('admin/costos-fijos.php')) ?>">Costos fijos</a> (mensuales) y se prorratean por días al período.</div>
    </div>
  </div>
</div>
<p class="text-muted small mt-2">Cálculo interno (no es un comprobante). Ingresos = cantidad × precio vigente a la fecha de cada hoja de ruta. Costos variables = costos de viaje del período, repartidos entre clientes por su % de m³. Costos fijos prorrateados por días. El detalle por destino está en el Excel.</p>
<?php
panel_footer();
