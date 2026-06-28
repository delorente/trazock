<?php
declare(strict_types=1);

// =============================================================================
// admin/encuestas.php — Encuestas de satisfacción del comprador (admin + gestor).
//
// Resumen agregado (respuestas, promedio, tasa de respuesta, con comentario),
// distribución por nivel y grilla filtrable por fecha y por carita. Las encuestas
// las responde el comprador desde el seguimiento público cuando su pedido está
// entregado (ver seguimiento/index.php + api/encuesta-enviar.php).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Encuesta;

$user    = Auth::requierePanel(['admin', 'gestor', 'logistica']); // gestor = Supervisor
$esAdmin = $user['rol'] === 'admin';

// POST: eliminar encuesta (solo admin; patrón PRG + CSRF). Conserva los filtros.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esAdmin) {
        flash_set('danger', 'No tenés permiso para eliminar encuestas.');
    } elseif (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } else {
        $eid = (int)($_POST['id'] ?? 0);
        if ($eid > 0 && Encuesta::eliminar($eid)) {
            flash_set('success', 'Encuesta eliminada.');
        } else {
            flash_set('danger', 'No se encontró la encuesta.');
        }
    }
    $qs = trim((string)($_POST['qs'] ?? ''));
    header('Location: ' . url('admin/encuestas.php') . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$filtros = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'cal'         => trim((string)($_GET['cal'] ?? '')),
];

// Niveles 1-4 → emoji, etiqueta y color (de "Muy malo" a "Excelente").
$NIVELES = [
    4 => ['😃', 'Excelente', '#22c55e'],
    3 => ['😊', 'Bueno',     '#3b82f6'],
    2 => ['😐', 'Regular',   '#f59e0b'],
    1 => ['😞', 'Muy malo',  '#ef4444'],
];

/** Celda de calificación: emoji + etiqueta coloreada. */
function enc_cell(int $v): string
{
    global $NIVELES;
    if (!isset($NIVELES[$v])) {
        return '<span class="text-muted">—</span>';
    }
    [$em, $lb, $color] = $NIVELES[$v];
    return '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:' . $color . '">'
        . '<span style="font-size:15px">' . $em . '</span>' . h($lb) . '</span>';
}

$stats     = Encuesta::estadisticas($filtros);
$tasa      = Encuesta::tasaRespuesta($filtros);
$total     = Encuesta::contar($filtros);

$porPagina = 50;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;
$paginas   = (int)max(1, ceil($total / $porPagina));

$encuestas = Encuesta::listar($filtros, $porPagina, $offset);
$ultima    = $encuestas[0]['entregada_at'] ?? null;

// Query string de los filtros (para paginación, sin 'pagina').
$qsBase = http_build_query(array_filter($filtros, static fn($v) => $v !== ''));
$csrf    = Auth::tokenCSRF();
$maxDist = max(1, ...array_values($stats['distribucion']));

$subtitulo = $stats['respuestas'] . ' ' . ($stats['respuestas'] === 1 ? 'respuesta' : 'respuestas')
    . ($ultima ? ' · Última: ' . fmt_fecha((string)$ultima, 'd/m/y') : '');

panel_header('Encuestas de satisfacción', $user, 'encuestas', $subtitulo);
flash_render();
?>
<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1.1rem">
    <div class="card kpi"><div class="kpi-v"><?= (int)$stats['respuestas'] ?></div><div class="kpi-l"><i class="bi bi-emoji-smile me-1"></i>Respuestas</div></div>
    <div class="card kpi"><div class="kpi-v" style="color:var(--green)"><?= number_format($stats['promedio'], 1, ',', '.') ?><span style="font-size:1rem;color:var(--muted)">/4</span></div><div class="kpi-l"><i class="bi bi-star-fill me-1"></i>Puntuación prom.</div></div>
    <div class="card kpi"><div class="kpi-v" style="color:#60a5fa"><?= (int)$tasa ?>%</div><div class="kpi-l"><i class="bi bi-graph-up me-1"></i>Tasa respuesta</div></div>
    <div class="card kpi"><div class="kpi-v"><?= (int)$stats['con_comentario'] ?></div><div class="kpi-l"><i class="bi bi-chat-left-text me-1"></i>Con comentarios</div></div>
</div>

<!-- Distribución -->
<div class="card mb-3">
    <div class="card-header" style="padding:.6rem 1rem">Distribución</div>
    <div style="padding:1rem 1.1rem;display:flex;flex-direction:column;gap:.7rem">
        <?php foreach ($NIVELES as $nivel => [$em, $lb, $color]):
            $n   = (int)$stats['distribucion'][$nivel];
            $pct = (int)round($n * 100 / $maxDist);
        ?>
        <div style="display:flex;align-items:center;gap:.8rem">
            <div style="width:110px;flex-shrink:0;font-size:13px;display:flex;align-items:center;gap:6px"><span style="font-size:16px"><?= $em ?></span><?= h($lb) ?></div>
            <div style="flex:1;height:14px;background:rgba(148,163,184,.15);border-radius:7px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:7px;transition:width .3s"></div>
            </div>
            <div style="width:34px;text-align:right;font-weight:700;font-size:13px"><?= $n ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Filtros -->
<form method="get" action="<?= h(url('admin/encuestas.php')) ?>" class="card mb-3" style="padding:.85rem 1rem">
  <div class="d-flex gap-3 flex-wrap align-items-end">
    <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
    <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
    <div>
      <label class="form-label d-block">Calificación general</label>
      <div class="btn-group btn-group-sm" role="group">
        <?php
        // Mantener fechas al cambiar de carita.
        $qsFechas = http_build_query(array_filter(['fecha_desde' => $filtros['fecha_desde'], 'fecha_hasta' => $filtros['fecha_hasta']], static fn($v) => $v !== ''));
        $hrefCal  = static fn(string $cal): string => url('admin/encuestas.php') . '?' . ($qsFechas ? $qsFechas . '&' : '') . ($cal !== '' ? 'cal=' . $cal : '');
        ?>
        <a class="btn btn-outline-secondary <?= $filtros['cal'] === '' ? 'active' : '' ?>" href="<?= h($hrefCal('')) ?>">Todas</a>
        <?php foreach ($NIVELES as $nivel => [$em, $lb, $color]): ?>
          <a class="btn btn-outline-secondary <?= $filtros['cal'] === (string)$nivel ? 'active' : '' ?>" href="<?= h($hrefCal((string)$nivel)) ?>" title="<?= h($lb) ?>" style="font-size:15px;line-height:1"><?= $em ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn btn-primary btn-sm px-3" type="submit">Filtrar</button>
    <?php if ($qsBase !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h(url('admin/encuestas.php')) ?>">Limpiar</a><?php endif; ?>
  </div>
</form>

<!-- Grilla -->
<div class="card">
  <div style="overflow-x:auto">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Operación</th><th>Entrega</th><th>General</th><th>Tiempo</th><th>Paquete</th><th>Trato</th><th>Comentario</th><?php if ($esAdmin): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if ($encuestas === []): ?>
        <tr><td colspan="<?= $esAdmin ? 8 : 7 ?>" class="text-muted" style="text-align:center;padding:1.5rem">No hay encuestas para los filtros seleccionados.</td></tr>
      <?php else: foreach ($encuestas as $e): ?>
        <tr>
          <td class="mono" style="font-size:12px"><a href="<?= h(url('admin/ordenes-detalle.php') . '?id=' . (int)$e['orden_id']) ?>" style="color:inherit;text-decoration:none"><?= h((string)$e['nro_orden']) ?></a></td>
          <td class="text-muted" style="font-size:12px"><?= h(fmt_fecha((string)($e['entregada_at'] ?? $e['created_at']), 'd/m/y H:i')) ?></td>
          <td><?= enc_cell((int)$e['general']) ?></td>
          <td><?= enc_cell((int)$e['tiempo']) ?></td>
          <td><?= enc_cell((int)$e['paquete']) ?></td>
          <td><?= enc_cell((int)$e['trato']) ?></td>
          <td style="font-size:13px;max-width:280px">
            <?php if (!empty($e['comentario'])): ?>
              <?= h((string)$e['comentario']) ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <?php if ($esAdmin): ?>
          <td style="text-align:right">
            <form method="post" action="<?= h(url('admin/encuestas.php')) ?>" style="display:inline" onsubmit="return confirm('¿Eliminar esta encuesta? No se puede deshacer.')">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="qs" value="<?= h($qsBase) ?>">
              <button class="btn btn-sm btn-link text-danger p-0" title="Eliminar encuesta"><i class="bi bi-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="d-flex align-items-center justify-content-between" style="padding:.5rem 1rem;border-top:1px solid var(--border)">
    <span class="text-muted" style="font-size:12px">Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
    <nav><ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/encuestas.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina - 1)) ?>">‹</a></li>
      <?php for ($p = 1; $p <= $paginas; $p++): ?>
        <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= h(url('admin/encuestas.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . $p) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(url('admin/encuestas.php') . '?' . ($qsBase ? $qsBase . '&' : '') . 'pagina=' . ($pagina + 1)) ?>">›</a></li>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>
<?php
panel_footer();
