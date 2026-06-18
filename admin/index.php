<?php
declare(strict_types=1);

// =============================================================================
// admin/index.php — Dashboard de stock (admin + gestor).
// Modo normal: HTML. Modo ?ajax=1: JSON para el polling de dashboard.js.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Models\Stats;

$user = Auth::requierePanel();

$kpis  = Stats::kpis();
$tabla = Stats::tablaCategoriaEstado();
$lotes = Stats::ultimosLotes(10);
foreach ($lotes as &$_l) {
    $_l['fecha_fmt'] = fmt_fecha($_l['timestamp_cierre'] ?? $_l['created_at']);
    $_l['num']       = lote_num((int)$_l['id'], $_l['created_at']);
}
unset($_l);

// --- Respuesta JSON para el auto-refresh (no recarga la página) --------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'kpis'    => $kpis,
        'tabla'   => $tabla,
        'lotes'   => $lotes,
        'estados' => Stats::ESTADOS,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/_layout.php';

$acciones = '<span class="text-muted" style="font-size:12px"><i class="bi bi-arrow-clockwise me-1"></i>Auto-refresh en <strong id="tzUltimaAct" style="color:var(--text)">30</strong>s</span>';
panel_header('Dashboard', $user, 'dashboard', 'Resumen operativo', $acciones);

// Totales por columna para la fila Total de la tabla cruzada.
$tot = ['__total' => 0];
foreach (Stats::ESTADOS as $e) { $tot[$e] = 0; }
foreach ($tabla as $f) {
    foreach (Stats::ESTADOS as $e) { $tot[$e] += (int)$f[$e]; }
    $tot['__total'] += (int)$f['total'];
}
?>
<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1.25rem" id="tzKpis">
    <div class="card kpi"><div class="kpi-v" data-kpi="total"><?= (int)$kpis['total'] ?></div><div class="kpi-l"><i class="bi bi-boxes me-1"></i>Total productos</div></div>
    <div class="card kpi"><div class="kpi-v" data-kpi="en_deposito" style="color:#60a5fa"><?= (int)$kpis['en_deposito'] ?></div><div class="kpi-l"><i class="bi bi-building me-1"></i>En depósito</div></div>
    <div class="card kpi"><div class="kpi-v" data-kpi="en_reparto" style="color:#fbbf24"><?= (int)$kpis['en_reparto'] ?></div><div class="kpi-l"><i class="bi bi-truck me-1"></i>En reparto</div></div>
    <div class="card kpi"><div class="kpi-v" data-kpi="entregados_mes" style="color:var(--green)"><?= (int)$kpis['entregados_mes'] ?></div><div class="kpi-l"><i class="bi bi-check-circle-fill me-1"></i>Entregados (30d)</div></div>
    <div class="card kpi"><div class="kpi-v" data-kpi="conflictos" style="color:var(--red)"><?= (int)$kpis['conflictos'] ?></div><div class="kpi-l"><i class="bi bi-exclamation-triangle-fill me-1"></i>Conflictos pend.</div></div>
</div>

<!-- Tabla cruzada categoría × estado -->
<div class="card mb-3">
    <div class="card-header" style="padding:.6rem 1rem">Stock por categoría &amp; estado</div>
    <div style="overflow-x:auto">
        <table class="table table-hover cross mb-0">
            <thead><tr>
                <th>Categoría</th>
                <?php foreach (Stats::ESTADOS as $e): ?><th><?= h($e) ?></th><?php endforeach; ?>
                <th class="tc">Total</th>
            </tr></thead>
            <tbody id="tzTablaCruzada">
            <?php foreach ($tabla as $fila): ?>
                <tr>
                    <td><?= h($fila['categoria']) ?></td>
                    <?php foreach (Stats::ESTADOS as $e): ?><td><?= (int)$fila[$e] ?: '<span class="text-muted">·</span>' ?></td><?php endforeach; ?>
                    <td class="tc"><?= (int)$fila['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($tabla !== []): ?>
                <tr class="tr-total">
                    <td><strong>Total</strong></td>
                    <?php foreach (Stats::ESTADOS as $e): ?><td><strong><?= (int)$tot[$e] ?></strong></td><?php endforeach; ?>
                    <td class="tc"><strong><?= (int)$tot['__total'] ?></strong></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="<?= count(Stats::ESTADOS) + 2 ?>" class="text-center text-muted py-3">Sin productos aún.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Últimos lotes -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" style="padding:.6rem 1rem">
        <span>Últimos 10 lotes</span>
        <a class="text-muted" style="font-size:12px" href="<?= h(url('admin/lotes.php')) ?>">Ver todos <i class="bi bi-arrow-right"></i></a>
    </div>
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Lote</th><th>Cierre</th><th>Tipo</th><th>Responsable</th><th>Items</th><th>Conflictos</th><th></th></tr></thead>
            <tbody id="tzUltimosLotes">
            <?php if ($lotes === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Sin lotes aún.</td></tr>
            <?php endif; ?>
            <?php foreach ($lotes as $l): ?>
                <tr>
                    <td class="mono" style="font-size:12px"><?= h($l['num']) ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h($l['fecha_fmt']) ?></td>
                    <td><?= tipo_lote_badge($l['tipo']) ?></td>
                    <td><?= h($l['responsable'] ?? '—') ?></td>
                    <td><?= (int)$l['items'] ?></td>
                    <td><?= (int)$l['conflictos'] > 0 ? '<span class="badge b-conflict">⚠ ' . (int)$l['conflictos'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/lote-detalle.php?id=' . (int)$l['id'])) ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?= h(asset('assets/js/admin/dashboard.js')) ?>"></script>
<?php
panel_footer();
