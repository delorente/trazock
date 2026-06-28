<?php
declare(strict_types=1);

// =============================================================================
// admin/costos.php — hub de costos: alta de costos de vehículo (mantenimiento)
// y reporte consolidado del período (costos de viaje + de vehículo). admin/gestor.
// Los costos de cada viaje se cargan en el detalle del lote.
//   ?export=xlsx → descarga el Excel del período.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\CostoVehiculo;
use Trazock\Models\CostoViaje;
use Trazock\Models\Vehiculo;

$user = Auth::requierePanel(['admin', 'gestor', 'contable']);

// POST: alta/baja de costos de vehículo (PRG + CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qs = trim((string)($_POST['qs'] ?? ''));
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (!in_array($user['rol'], ['admin', 'contable'], true)) {
        flash_set('danger', 'No tenés permiso para editar costos.');
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'veh_add') {
            $vid   = (int)($_POST['vehiculo_id'] ?? 0);
            $tipo  = (string)($_POST['tipo'] ?? 'otro');
            $imp   = (float)str_replace(',', '.', (string)($_POST['importe'] ?? '0'));
            $fecha = trim((string)($_POST['fecha'] ?? '')) ?: date('Y-m-d');
            $obs   = trim((string)($_POST['observacion'] ?? ''));
            if ($vid <= 0 || Vehiculo::findActivo($vid) === null) {
                flash_set('danger', 'Elegí un vehículo válido.');
            } elseif ($imp <= 0) {
                flash_set('danger', 'El importe debe ser mayor a 0.');
            } else {
                CostoVehiculo::crear($vid, $tipo, $imp, $fecha, $obs, (int)$user['id']);
                flash_set('success', 'Costo de vehículo agregado.');
            }
        } elseif ($accion === 'veh_del') {
            CostoVehiculo::eliminar((int)($_POST['costo_id'] ?? 0));
            flash_set('success', 'Costo eliminado.');
        }
    }
    header('Location: ' . url('admin/costos.php') . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-01');
$hasta = trim((string)($_GET['hasta'] ?? '')) ?: date('Y-m-d');

$viajePorTipo = CostoViaje::resumenPorTipo($desde, $hasta);
$viajeTotal   = CostoViaje::total($desde, $hasta);
$viajeDetalle = CostoViaje::listar($desde, $hasta);
$vehList      = CostoVehiculo::listar($desde, $hasta);
$vehPorVeh    = CostoVehiculo::porVehiculo($desde, $hasta);
$vehTotal     = CostoVehiculo::total($desde, $hasta);
$granTotal    = $viajeTotal + $vehTotal;

$nf = static fn($n) => '$ ' . number_format((float)$n, 2, ',', '.');

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
    $hoja('Costos viaje', ['Fecha', 'Lote', 'Tipo', 'Importe', 'Observación'],
        array_map(static fn($r) => [
            ($r['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$r['fecha'])) : '',
            lote_num((int)$r['lote_id'], (string)$r['lote_creado']),
            CostoViaje::TIPOS[$r['tipo']] ?? $r['tipo'], (float)$r['importe'], (string)($r['observacion'] ?? ''),
        ], $viajeDetalle));
    $hoja('Costos vehículo', ['Fecha', 'Vehículo', 'Tipo', 'Importe', 'Observación'],
        array_map(static fn($r) => [
            ($r['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$r['fecha'])) : '',
            (string)$r['vehiculo'], CostoVehiculo::TIPOS[$r['tipo']] ?? $r['tipo'], (float)$r['importe'], (string)($r['observacion'] ?? ''),
        ], $vehList));
    $ss->setActiveSheetIndex(0);
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="costos_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

require __DIR__ . '/_layout.php';

$qs   = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$csrf = Auth::tokenCSRF();
$vehiculos = Vehiculo::activos();
$puedeEditar = in_array($user['rol'], ['admin', 'contable'], true); // gestor/logística: solo lectura

$acciones = '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/costos.php') . '?' . $qs . '&export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>';
panel_header('Costos', $user, 'costos', 'Costos de viajes y de vehículos', $acciones);
flash_render();
?>
<form method="get" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= h($desde) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= h($hasta) ?>"></div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Ver</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/costos.php')) ?>">Mes actual</a>
    </div>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:#fbbf24"><?= $nf($viajeTotal) ?></div><div class="sumbar-l">Costos de viajes</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:#fbbf24"><?= $nf($vehTotal) ?></div><div class="sumbar-l">Costos de vehículos</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $nf($granTotal) ?></div><div class="sumbar-l">Total del período</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)"><?= h(date('d/m/Y', strtotime($desde))) ?> – <?= h(date('d/m/Y', strtotime($hasta))) ?></div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Costos de viajes por tipo</div>
      <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
          <thead><tr><th>Tipo</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach (CostoViaje::TIPOS as $k => $v): ?>
            <tr><td><?= h($v) ?></td><td class="text-end"><?= $nf($viajePorTipo[$k] ?? 0) ?></td></tr>
          <?php endforeach; ?>
          <tr class="table-light"><td><strong>Total</strong></td><td class="text-end"><strong><?= $nf($viajeTotal) ?></strong></td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted small">Los costos de cada viaje se cargan en el detalle del lote (Lotes → Ver).</div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Costos de vehículos por unidad</div>
      <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
          <thead><tr><th>Vehículo</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php if ($vehPorVeh === []): ?><tr><td colspan="2" class="text-muted text-center py-3">Sin datos.</td></tr><?php endif; ?>
          <?php foreach ($vehPorVeh as $r): ?>
            <tr><td><?= h((string)$r['nombre']) ?></td><td class="text-end"><?= $nf($r['total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Costos de vehículos (mantenimiento)</div>
      <?php if ($puedeEditar): ?>
      <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="accion" value="veh_add">
          <input type="hidden" name="qs" value="<?= h($qs) ?>">
          <div class="col-6 col-md-3">
            <label class="form-label" style="font-size:12px">Vehículo</label>
            <select class="form-select form-select-sm" name="vehiculo_id" required>
              <option value="">—</option>
              <?php foreach ($vehiculos as $v): ?><option value="<?= (int)$v['id'] ?>"><?= h($v['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:12px">Tipo</label>
            <select class="form-select form-select-sm" name="tipo">
              <?php foreach (CostoVehiculo::TIPOS as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:12px">Importe</label>
            <div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" name="importe" required></div>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label" style="font-size:12px">Fecha</label>
            <input type="date" class="form-control form-control-sm" name="fecha" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="col-9 col-md-2">
            <label class="form-label" style="font-size:12px">Observación</label>
            <input type="text" class="form-control form-control-sm" name="observacion" maxlength="255" placeholder="Opcional">
          </div>
          <div class="col-3 col-md-1"><button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-plus-lg"></i></button></div>
        </form>
      </div>
      <?php endif; ?>
      <div style="overflow-x:auto">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Fecha</th><th>Vehículo</th><th>Tipo</th><th class="text-end">Importe</th><th>Observación</th><th></th></tr></thead>
          <tbody>
          <?php if ($vehList === []): ?><tr><td colspan="6" class="text-muted text-center py-3">Sin costos en el período.</td></tr><?php endif; ?>
          <?php foreach ($vehList as $c): ?>
            <tr>
              <td class="text-muted" style="font-size:12px"><?= h(($c['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$c['fecha'])) : '—') ?></td>
              <td><?= h((string)$c['vehiculo']) ?></td>
              <td><?= h(CostoVehiculo::TIPOS[$c['tipo']] ?? (string)$c['tipo']) ?></td>
              <td class="text-end"><?= $nf($c['importe']) ?></td>
              <td style="font-size:13px"><?= h((string)($c['observacion'] ?? '')) ?></td>
              <td class="text-end">
                <?php if ($puedeEditar): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="accion" value="veh_del">
                  <input type="hidden" name="qs" value="<?= h($qs) ?>">
                  <input type="hidden" name="costo_id" value="<?= (int)$c['id'] ?>">
                  <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar" onclick="tzConfirm(this.closest('form'), '¿Eliminar este costo?')"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
panel_footer();
