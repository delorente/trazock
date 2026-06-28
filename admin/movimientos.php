<?php
declare(strict_types=1);

// =============================================================================
// admin/movimientos.php — estadísticas de movimientos (vehículos / staff).
// Viajes por vehículo, conductor, ayudante y destino en un período. admin/gestor.
//   ?export=xlsx → descarga el Excel con los filtros aplicados.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\Movimiento;

$user = Auth::requierePanel(['admin', 'gestor']);

// Período: por defecto, mes en curso.
$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-01');
$hasta = trim((string)($_GET['hasta'] ?? '')) ?: date('Y-m-d');
$tipoF = trim((string)($_GET['tipo'] ?? ''));
$tipos = in_array($tipoF, Movimiento::TIPOS_VIAJE, true) ? [$tipoF] : Movimiento::TIPOS_VIAJE;

$tipoLabel = ['INGRESO' => 'Ingreso', 'SALIDA_REPARTO' => 'Salida a reparto', 'SALIDA_DEVOLUCION' => 'Devolución'];

$resumen     = Movimiento::resumen($desde, $hasta, $tipos);
$porVehiculo = Movimiento::porVehiculo($desde, $hasta, $tipos);
$porConductor= Movimiento::porConductor($desde, $hasta, $tipos);
$porAyudante = Movimiento::porAyudante($desde, $hasta, $tipos);
$porDestino  = Movimiento::porDestino($desde, $hasta, $tipos);

$nf  = static fn($n) => number_format((float)$n, 2, ',', '.');
$ni  = static fn($n) => number_format((int)$n, 0, ',', '.');

// --- Export Excel ------------------------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $viajes = Movimiento::viajes($desde, $hasta, $tipos);
    $ss = new Spreadsheet();
    $primera = true;

    $hoja = function (string $titulo, array $headers, array $rows) use ($ss, &$primera) {
        $sheet = $primera ? $ss->getActiveSheet() : $ss->createSheet();
        $primera = false;
        $sheet->setTitle(mb_substr($titulo, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . chr(64 + count($headers)) . '1')->getFont()->setBold(true);
        $sheet->fromArray($rows, null, 'A2');
        foreach (range('A', chr(64 + count($headers))) as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    };

    $hoja('Por vehículo', ['Vehículo', 'Viajes', 'm³', 'Bultos'],
        array_map(static fn($r) => [$r['nombre'], (int)$r['viajes'], (float)$r['m3'], (int)$r['bultos']], $porVehiculo));
    $hoja('Por conductor', ['Conductor', 'Viajes', 'm³', 'Bultos'],
        array_map(static fn($r) => [$r['nombre'], (int)$r['viajes'], (float)$r['m3'], (int)$r['bultos']], $porConductor));
    $hoja('Por ayudante', ['Ayudante', 'Viajes', 'm³', 'Bultos'],
        array_map(static fn($r) => [$r['nombre'], (int)$r['viajes'], (float)$r['m3'], (int)$r['bultos']], $porAyudante));
    $hoja('Por destino', ['Provincia', 'Localidad', 'Viajes', 'm³', 'Bultos'],
        array_map(static fn($r) => [$r['provincia'], $r['localidad'], (int)$r['viajes'], (float)$r['m3'], (int)$r['bultos']], $porDestino));
    $hoja('Viajes (detalle)', ['Fecha', 'Tipo', 'Vehículo', 'Conductor', 'Ayudantes', 'm³', 'Bultos', 'Destinos', 'Categorías'],
        array_map(static fn($r) => [
            ($r['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$r['fecha'])) : '',
            $tipoLabel[$r['tipo']] ?? $r['tipo'],
            (string)$r['vehiculo'], (string)$r['conductor'], (string)($r['ayudantes'] ?? ''),
            (float)$r['m3'], (int)$r['bultos'], (string)($r['destinos'] ?? ''), (string)($r['categorias'] ?? ''),
        ], $viajes));

    $ss->setActiveSheetIndex(0);
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="movimientos_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

require __DIR__ . '/_layout.php';

$qs = http_build_query(array_filter(['desde' => $desde, 'hasta' => $hasta, 'tipo' => $tipoF], static fn($v) => $v !== ''));
$acciones = '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/movimientos.php') . '?' . $qs . '&export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>';
panel_header('Movimientos', $user, 'movimientos', 'Viajes por vehículo, conductor, ayudante y destino', $acciones);

/** Render de una tabla de agregación (nombre + viajes/m³/bultos). */
function mov_tabla(string $titulo, string $col1, array $rows, callable $nf, callable $ni): void
{
    ?>
    <div class="card mb-3">
        <div class="card-header"><?= h($titulo) ?></div>
        <div style="overflow-x:auto">
            <table class="table table-hover mb-0">
                <thead><tr><th><?= h($col1) ?></th><th class="text-end">Viajes</th><th class="text-end">m³</th><th class="text-end">Bultos</th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">Sin datos en el período.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string)($r['nombre'] ?? '')) ?></td>
                        <td class="text-end"><?= $ni($r['viajes']) ?></td>
                        <td class="text-end"><?= $nf($r['m3']) ?></td>
                        <td class="text-end"><?= $ni($r['bultos']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
?>
<form method="get" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= h($desde) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= h($hasta) ?>"></div>
    <div>
      <label class="form-label">Tipo de viaje</label>
      <select class="form-select form-select-sm" name="tipo">
        <option value="">Todos</option>
        <?php foreach ($tipoLabel as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $tipoF === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Ver</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/movimientos.php')) ?>">Mes actual</a>
    </div>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:#60a5fa"><?= $ni($resumen['viajes']) ?></div><div class="sumbar-l">Viajes</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $nf($resumen['m3']) ?></div><div class="sumbar-l">m³ movidos</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $ni($resumen['bultos']) ?></div><div class="sumbar-l">Bultos</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)"><?= h(date('d/m/Y', strtotime($desde))) ?> – <?= h(date('d/m/Y', strtotime($hasta))) ?></div>
</div>

<div class="print-area">
  <div class="row g-3">
    <div class="col-lg-6"><?php mov_tabla('Por vehículo', 'Vehículo', $porVehiculo, $nf, $ni); ?></div>
    <div class="col-lg-6"><?php mov_tabla('Por conductor', 'Conductor', $porConductor, $nf, $ni); ?></div>
    <div class="col-lg-6"><?php mov_tabla('Por ayudante', 'Ayudante', $porAyudante, $nf, $ni); ?></div>
    <div class="col-lg-6">
      <div class="card mb-3">
        <div class="card-header">Por destino (visitas)</div>
        <div style="overflow-x:auto">
          <table class="table table-hover mb-0">
            <thead><tr><th>Destino</th><th class="text-end">Viajes</th><th class="text-end">m³</th><th class="text-end">Bultos</th></tr></thead>
            <tbody>
            <?php if ($porDestino === []): ?>
              <tr><td colspan="4" class="text-muted text-center py-3">Sin datos en el período.</td></tr>
            <?php endif; ?>
            <?php foreach ($porDestino as $r): ?>
              <tr>
                <td><?= h(trim(((string)$r['localidad'] !== '' ? $r['localidad'] . ' · ' : '') . (string)$r['provincia'])) ?></td>
                <td class="text-end"><?= $ni($r['viajes']) ?></td>
                <td class="text-end"><?= $nf($r['m3']) ?></td>
                <td class="text-end"><?= $ni($r['bultos']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <p class="text-muted small">El detalle viaje por viaje (con destinos y categorías de cada uno) está en el <strong>Excel</strong>. Un "viaje" es un lote de ingreso, salida a reparto o devolución.</p>
</div>
<?php
panel_footer();
