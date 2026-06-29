<?php
declare(strict_types=1);

// =============================================================================
// admin/confirmaciones.php — Avisos de entrega por WhatsApp y sus respuestas.
//
// Resumen del período (enviadas / confirmadas / reprogramadas / con error) y
// grilla filtrable por fecha de entrega y estado. Los avisos se disparan desde
// Reportes (admin/ordenes-reportes.php → api/confirmacion-enviar.php) y las
// respuestas (botones Confirmar/Reprogramar) las recibe api/whatsapp-webhook.php.
// admin/gestor (Supervisor, solo lectura)/logística.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\ConfirmacionEntrega;

$user = Auth::requierePanel(['admin', 'gestor', 'logistica']); // gestor = Supervisor

$filtros = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'estado'      => trim((string)($_GET['estado'] ?? '')),
];

// Estado del aviso → etiqueta + color/ícono.
$EST = [
    'enviado'      => ['Sin responder', '#64748b', 'hourglass-split'],
    'confirmado'   => ['Confirmado',    '#22c55e', 'check-circle-fill'],
    'reprogramado' => ['Reprogramado',  '#f59e0b', 'arrow-repeat'],
    'error'        => ['Error de envío', '#ef4444', 'exclamation-triangle-fill'],
];

/** Badge del estado del aviso. */
function confent_badge(string $estado): string
{
    global $EST;
    if (!isset($EST[$estado])) {
        return '<span class="text-muted">—</span>';
    }
    [$lb, $color, $ic] = $EST[$estado];
    return '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:' . $color . '">'
        . '<i class="bi bi-' . $ic . '"></i>' . h($lb) . '</span>';
}

$resumen = ConfirmacionEntrega::resumen($filtros);
$total   = ConfirmacionEntrega::contar($filtros);

$porPagina = 50;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;
$paginas   = (int)max(1, ceil($total / $porPagina));

$filas  = ConfirmacionEntrega::listar($filtros, $porPagina, $offset);
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== ''));

$subtitulo = $resumen['total'] . ' ' . ($resumen['total'] === 1 ? 'aviso' : 'avisos');
panel_header('Avisos de entrega', $user, 'confirmaciones', $subtitulo);
flash_render();
?>
<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1.1rem">
    <div class="card kpi"><div class="kpi-v"><?= (int)$resumen['total'] ?></div><div class="kpi-l"><i class="bi bi-whatsapp me-1"></i>Avisos enviados</div></div>
    <div class="card kpi"><div class="kpi-v" style="color:var(--green)"><?= (int)$resumen['confirmado'] ?></div><div class="kpi-l"><i class="bi bi-check-circle-fill me-1"></i>Confirmados</div></div>
    <div class="card kpi"><div class="kpi-v" style="color:#f59e0b"><?= (int)$resumen['reprogramado'] ?></div><div class="kpi-l"><i class="bi bi-arrow-repeat me-1"></i>Reprogramados</div></div>
    <div class="card kpi"><div class="kpi-v" style="color:#64748b"><?= (int)$resumen['enviado'] ?></div><div class="kpi-l"><i class="bi bi-hourglass-split me-1"></i>Sin responder</div></div>
</div>

<!-- Filtros -->
<form method="get" action="<?= h(url('admin/confirmaciones.php')) ?>" class="card mb-3" style="padding:.85rem 1rem">
  <div class="d-flex gap-3 flex-wrap align-items-end">
    <div><label class="form-label">Entrega desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
    <div><label class="form-label">Entrega hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
    <div>
      <label class="form-label">Estado</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos</option>
        <?php foreach ($EST as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary btn-sm px-3" type="submit">Filtrar</button>
    <?php if ($qsBase !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/confirmaciones.php')) ?>">Limpiar</a><?php endif; ?>
  </div>
</form>

<!-- Grilla -->
<div class="card">
  <div style="overflow-x:auto">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Orden</th><th>Cliente</th><th>Destino</th><th>Teléfono</th><th>Entrega</th><th>Estado</th><th>Enviado</th><th>Respondió</th>
      </tr></thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center;padding:1.5rem">No hay avisos para los filtros seleccionados.</td></tr>
      <?php else: foreach ($filas as $f):
        $cliente = trim((string)($f['cliente'] ?? '') . ' ' . (string)($f['cliente_apellido'] ?? ''));
        $dest    = trim((string)($f['dest_localidad'] ?? '') . (($f['dest_localidad'] ?? '') && ($f['dest_provincia'] ?? '') ? ' · ' : '') . (string)($f['dest_provincia'] ?? ''));
      ?>
        <tr>
          <td class="mono" style="font-size:12px"><a href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$f['orden_id']) ?>" style="color:inherit;text-decoration:none"><?= h((string)$f['nro_orden']) ?></a></td>
          <td style="font-size:13px"><?= $cliente !== '' ? h($cliente) : '<span class="text-muted">—</span>' ?></td>
          <td style="font-size:13px"><?= $dest !== '' ? h($dest) : '<span class="text-muted">—</span>' ?></td>
          <td class="mono" style="font-size:12px;color:var(--muted)"><?= h((string)($f['telefono'] ?? $f['telefonos'] ?? '') !== '' ? (string)($f['telefono'] ?? $f['telefonos']) : '—') ?></td>
          <td style="font-size:12px"><?= $f['fecha_entrega'] ? h(date('d/m/Y', strtotime((string)$f['fecha_entrega']))) . ' · ' . h((string)($f['horario'] ?? '')) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <?= confent_badge((string)$f['estado']) ?>
            <?php if ((string)$f['estado'] === 'error' && !empty($f['error'])): ?>
              <i class="bi bi-info-circle text-danger" title="<?= h((string)$f['error']) ?>"></i>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:12px"><?= $f['enviado_at'] ? h(fmt_fecha((string)$f['enviado_at'], 'd/m/y H:i')) : '—' ?></td>
          <td class="text-muted" style="font-size:12px"><?= $f['respondido_at'] ? h(fmt_fecha((string)$f['respondido_at'], 'd/m/y H:i')) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="d-flex align-items-center justify-content-between" style="padding:.5rem 1rem;border-top:1px solid var(--border)">
    <span class="text-muted" style="font-size:12px">Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
    <nav><ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/confirmaciones.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina - 1)) ?>">‹</a></li>
      <?php for ($p = 1; $p <= $paginas; $p++): ?>
        <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= h(url('admin/confirmaciones.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . $p) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/confirmaciones.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina + 1)) ?>">›</a></li>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>
<?php
panel_footer();
