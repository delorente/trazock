<?php
declare(strict_types=1);

// =============================================================================
// admin/caja-chica.php — caja chica (la maneja el contable). Saldo, movimientos
// (ingresos/egresos/adelantos a choferes/rendiciones) y export. admin/contable
// editan; supervisor (gestor) solo lectura.
//   ?export=xlsx → descarga el Excel del período.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\CajaChica;
use Trazock\Models\Usuario;

$user = Auth::requierePanel(['admin', 'gestor', 'contable']);
$puedeEditar = in_array($user['rol'], ['admin', 'contable'], true); // gestor: solo lectura

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qs = trim((string)($_POST['qs'] ?? ''));
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (!$puedeEditar) {
        flash_set('danger', 'No tenés permiso para editar la caja.');
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'add') {
            $tipo     = (string)($_POST['tipo'] ?? 'egreso');
            $monto    = (float)str_replace(',', '.', (string)($_POST['monto'] ?? '0'));
            $fecha    = trim((string)($_POST['fecha'] ?? '')) ?: date('Y-m-d');
            $concepto = trim((string)($_POST['concepto'] ?? ''));
            $chofer   = (int)($_POST['chofer_id'] ?? 0) ?: null;
            $obs      = trim((string)($_POST['observacion'] ?? ''));
            if ($concepto === '' || $monto <= 0) {
                flash_set('danger', 'Concepto e importe (mayor a 0) son obligatorios.');
            } else {
                CajaChica::crear($tipo, $monto, $fecha, $concepto, $chofer, $obs, (int)$user['id']);
                flash_set('success', 'Movimiento registrado.');
            }
        } elseif ($accion === 'del') {
            CajaChica::eliminar((int)($_POST['id'] ?? 0));
            flash_set('success', 'Movimiento eliminado.');
        }
    }
    header('Location: ' . url('admin/caja-chica.php') . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-01');
$hasta = trim((string)($_GET['hasta'] ?? '')) ?: date('Y-m-d');

$saldoActual  = CajaChica::saldoActual();
$saldoInicial = CajaChica::saldoAnteriorA($desde);
$movs         = CajaChica::listar($desde, $hasta);
$tot          = CajaChica::totales($desde, $hasta);
$nf  = static fn($n) => '$ ' . number_format((float)$n, 2, ',', '.');

// --- Export Excel ------------------------------------------------------------
if (($_GET['export'] ?? '') === 'xlsx') {
    $ss = new Spreadsheet(); $sheet = $ss->getActiveSheet(); $sheet->setTitle('Caja chica');
    $sheet->fromArray(['Fecha', 'Tipo', 'Concepto', 'Chofer', 'Ingreso', 'Egreso', 'Saldo', 'Observación'], null, 'A1');
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    $fila = 2; $saldo = $saldoInicial;
    foreach ($movs as $m) {
        $signo = CajaChica::signo((string)$m['tipo']);
        $saldo += $signo * (float)$m['monto'];
        $sheet->setCellValue('A' . $fila, ($m['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$m['fecha'])) : '');
        $sheet->setCellValue('B' . $fila, CajaChica::TIPOS[$m['tipo']] ?? $m['tipo']);
        $sheet->setCellValue('C' . $fila, (string)$m['concepto']);
        $sheet->setCellValue('D' . $fila, (string)($m['chofer'] ?? ''));
        $sheet->setCellValue('E' . $fila, $signo > 0 ? (float)$m['monto'] : null);
        $sheet->setCellValue('F' . $fila, $signo < 0 ? (float)$m['monto'] : null);
        $sheet->setCellValue('G' . $fila, $saldo);
        $sheet->setCellValue('H' . $fila, (string)($m['observacion'] ?? ''));
        $fila++;
    }
    foreach (range('A', 'H') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="caja_chica_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

require __DIR__ . '/_layout.php';

$qs   = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$csrf = Auth::tokenCSRF();
$choferes = Usuario::transportistasActivos();

$acciones = '<a class="btn btn-sm btn-outline-success" href="' . h(url('admin/caja-chica.php') . '?' . $qs . '&export=xlsx') . '"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>';
panel_header('Caja chica', $user, 'caja-chica', 'Efectivo: ingresos, egresos y adelantos a choferes', $acciones);
flash_render();
?>
<form method="get" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= h($desde) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= h($hasta) ?>"></div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Ver</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/caja-chica.php')) ?>">Mes actual</a>
    </div>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n" style="color:<?= $saldoActual >= 0 ? '#34d399' : '#f87171' ?>"><?= $nf($saldoActual) ?></div><div class="sumbar-l">Saldo actual</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:#34d399"><?= $nf($tot['ingresos']) ?></div><div class="sumbar-l">Ingresos del período</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:#fbbf24"><?= $nf($tot['egresos']) ?></div><div class="sumbar-l">Egresos del período</div></div>
  <div style="margin-left:auto;font-size:12px;color:var(--muted)"><?= h(date('d/m/Y', strtotime($desde))) ?> – <?= h(date('d/m/Y', strtotime($hasta))) ?></div>
</div>

<div class="card">
  <div class="card-header">Movimientos</div>
  <?php if ($puedeEditar): ?>
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="add">
      <input type="hidden" name="qs" value="<?= h($qs) ?>">
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Tipo</label>
        <select class="form-select form-select-sm" name="tipo" id="ccTipo">
          <?php foreach (CajaChica::TIPOS as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Importe</label>
        <div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" name="monto" required></div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Fecha</label>
        <input type="date" class="form-control form-control-sm" name="fecha" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" style="font-size:12px">Concepto</label>
        <input type="text" class="form-control form-control-sm" name="concepto" maxlength="150" required>
      </div>
      <div class="col-6 col-md-2" id="ccChoferWrap">
        <label class="form-label" style="font-size:12px">Chofer</label>
        <select class="form-select form-select-sm" name="chofer_id">
          <option value="">—</option>
          <?php foreach ($choferes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nombre_completo']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-1"><button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-plus-lg"></i></button></div>
    </form>
  </div>
  <?php endif; ?>
  <div style="overflow-x:auto">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Concepto</th><th>Chofer</th><th class="text-end">Ingreso</th><th class="text-end">Egreso</th><th class="text-end">Saldo</th><th></th></tr></thead>
      <tbody>
      <?php if ($movs === []): ?><tr><td colspan="8" class="text-muted text-center py-3">Sin movimientos en el período.</td></tr><?php endif; ?>
      <?php $saldo = $saldoInicial; foreach ($movs as $m): $signo = CajaChica::signo((string)$m['tipo']); $saldo += $signo * (float)$m['monto']; ?>
        <tr>
          <td class="text-muted" style="font-size:12px"><?= h(($m['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$m['fecha'])) : '—') ?></td>
          <td><?= h(CajaChica::TIPOS[$m['tipo']] ?? (string)$m['tipo']) ?></td>
          <td style="font-size:13px"><?= h((string)$m['concepto']) ?><?php if (($m['observacion'] ?? '') !== ''): ?> <i class="bi bi-chat-left-text-fill text-muted" title="<?= h((string)$m['observacion']) ?>"></i><?php endif; ?></td>
          <td style="font-size:13px"><?= h((string)($m['chofer'] ?? '')) ?></td>
          <td class="text-end" style="color:#34d399"><?= $signo > 0 ? $nf($m['monto']) : '' ?></td>
          <td class="text-end" style="color:#f87171"><?= $signo < 0 ? $nf($m['monto']) : '' ?></td>
          <td class="text-end" style="font-weight:600"><?= $nf($saldo) ?></td>
          <td class="text-end">
            <?php if ($puedeEditar): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="accion" value="del">
              <input type="hidden" name="qs" value="<?= h($qs) ?>">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar" onclick="tzConfirm(this.closest('form'), '¿Eliminar este movimiento?')"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer text-muted small">Saldo inicial del período: <?= $nf($saldoInicial) ?>. Ingreso/rendición suman; egreso/adelanto restan.</div>
</div>
<?php
panel_footer();
