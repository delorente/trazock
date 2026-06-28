<?php
declare(strict_types=1);

// =============================================================================
// admin/costos-fijos.php — costos fijos mensuales (alquileres, sueldos, otros).
// Se cargan por mes; el reporte de Resultados los prorratea por días. admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\CostoFijo;

$user = Auth::requierePanel(['admin', 'gestor', 'contable']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qs = trim((string)($_POST['qs'] ?? ''));
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (!in_array($user['rol'], ['admin', 'contable'], true)) {
        flash_set('danger', 'No tenés permiso para editar costos fijos.');
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'add') {
            $tipo     = (string)($_POST['tipo'] ?? 'otro');
            $concepto = trim((string)($_POST['concepto'] ?? ''));
            $imp      = (float)str_replace(',', '.', (string)($_POST['importe'] ?? '0'));
            $periodo  = trim((string)($_POST['periodo'] ?? '')) ?: date('Y-m');
            $obs      = trim((string)($_POST['observacion'] ?? ''));
            if ($concepto === '' || $imp <= 0) {
                flash_set('danger', 'Concepto e importe (mayor a 0) son obligatorios.');
            } else {
                CostoFijo::crear($tipo, $concepto, $imp, $periodo, $obs, (int)$user['id']);
                flash_set('success', 'Costo fijo agregado.');
            }
        } elseif ($accion === 'del') {
            CostoFijo::eliminar((int)($_POST['id'] ?? 0));
            flash_set('success', 'Costo fijo eliminado.');
        }
    }
    header('Location: ' . url('admin/costos-fijos.php') . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$desde = trim((string)($_GET['desde'] ?? '')) ?: date('Y-m-01');
$hasta = trim((string)($_GET['hasta'] ?? '')) ?: date('Y-m-d');
$lista = CostoFijo::listar($desde, $hasta);
$prr   = CostoFijo::prorrateoPorTipo($desde, $hasta);
$nf    = static fn($n) => '$ ' . number_format((float)$n, 2, ',', '.');
$qs    = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$csrf  = Auth::tokenCSRF();
$puedeEditar = in_array($user['rol'], ['admin', 'contable'], true); // gestor: solo lectura

panel_header('Costos fijos', $user, 'costos-fijos', 'Alquileres, sueldos y otros (mensuales)');
flash_render();
?>
<form method="get" class="card mb-3 no-print" style="padding:.85rem 1rem">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= h($desde) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= h($hasta) ?>"></div>
    <div style="display:flex;align-items:flex-end;gap:.4rem">
      <button class="btn btn-primary btn-sm px-3" type="submit">Ver</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/costos-fijos.php')) ?>">Mes actual</a>
    </div>
  </div>
</form>

<div class="sumbar no-print">
  <div><div class="sumbar-n"><?= $nf($prr['alquiler']) ?></div><div class="sumbar-l">Alquileres (prorr.)</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $nf($prr['sueldo']) ?></div><div class="sumbar-l">Sueldos (prorr.)</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n"><?= $nf($prr['otro']) ?></div><div class="sumbar-l">Otros (prorr.)</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" style="color:#fbbf24"><?= $nf($prr['total']) ?></div><div class="sumbar-l">Total prorrateado</div></div>
</div>

<div class="card">
  <div class="card-header">Costos fijos cargados</div>
  <?php if ($puedeEditar): ?>
  <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="add">
      <input type="hidden" name="qs" value="<?= h($qs) ?>">
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Tipo</label>
        <select class="form-select form-select-sm" name="tipo">
          <?php foreach (CostoFijo::TIPOS as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" style="font-size:12px">Concepto</label>
        <input type="text" class="form-control form-control-sm" name="concepto" maxlength="150" required placeholder="Ej. Depósito, Sueldo Juan">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Importe</label>
        <div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" name="importe" required></div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" style="font-size:12px">Mes</label>
        <input type="month" class="form-control form-control-sm" name="periodo" value="<?= h(date('Y-m')) ?>">
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
      <thead><tr><th>Mes</th><th>Tipo</th><th>Concepto</th><th class="text-end">Importe</th><th>Observación</th><th></th></tr></thead>
      <tbody>
      <?php if ($lista === []): ?><tr><td colspan="6" class="text-muted text-center py-3">Sin costos fijos en el período.</td></tr><?php endif; ?>
      <?php foreach ($lista as $c): ?>
        <tr>
          <td><?= h((string)$c['periodo']) ?></td>
          <td><?= h(CostoFijo::TIPOS[$c['tipo']] ?? (string)$c['tipo']) ?></td>
          <td><?= h((string)$c['concepto']) ?></td>
          <td class="text-end"><?= $nf($c['importe']) ?></td>
          <td style="font-size:13px"><?= h((string)($c['observacion'] ?? '')) ?></td>
          <td class="text-end">
            <?php if ($puedeEditar): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="accion" value="del">
              <input type="hidden" name="qs" value="<?= h($qs) ?>">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar" onclick="tzConfirm(this.closest('form'), '¿Eliminar este costo fijo?')"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer text-muted small">Se cargan por mes completo; el reporte de <a href="<?= h(url('admin/rentabilidad.php')) ?>">Resultados</a> los prorratea por días al rango consultado.</div>
</div>
<?php
panel_footer();
