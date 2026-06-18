<?php
declare(strict_types=1);

// =============================================================================
// admin/productos.php — listado paginado con filtros siempre visibles que se
// aplican automáticamente (la búsqueda por código es parcial y en vivo).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Categoria;
use Trazock\Models\Producto;
use Trazock\Models\Stats;

$user = Auth::requierePanel();

$filtros = [
    'codigo'          => trim((string)($_GET['codigo'] ?? '')),
    'categoria_id'    => (string)($_GET['categoria_id'] ?? ''),
    'estado'          => (string)($_GET['estado'] ?? ''),
    'tiene_conflicto' => (string)($_GET['tiene_conflicto'] ?? ''),
    'fecha_desde'     => (string)($_GET['fecha_desde'] ?? ''),
    'fecha_hasta'     => (string)($_GET['fecha_hasta'] ?? ''),
];

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$total   = Producto::contar($filtros);
$pages   = (int)max(1, ceil($total / $perPage));
$page    = min($page, $pages);
$rows    = Producto::buscar($filtros, $perPage, ($page - 1) * $perPage);

$categorias = Categoria::activas();
$qsFiltros  = http_build_query(array_filter($filtros, static fn($v) => $v !== ''));

$acciones = '<a class="btn btn-outline-secondary btn-sm" href="' . h(url('admin/exportar.php?' . $qsFiltros)) . '"><i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel</a>';
panel_header('Productos', $user, 'productos', $total . ' registros', $acciones);
?>
<!-- Filtros siempre visibles; se aplican solos al cambiar -->
<form class="card card-body mb-3" method="get" id="filtros">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem">
        <div style="grid-column:1/-1">
            <label class="form-label">Buscar por código (parcial)</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="codigo" id="fCodigo" value="<?= h($filtros['codigo']) ?>" autocomplete="off" autofocus placeholder="Escribí parte del código…">
            </div>
        </div>
        <div><label class="form-label">Categoría</label>
            <select class="form-select form-select-sm tz-auto" name="categoria_id">
                <option value="">Todas</option>
                <?php foreach ($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $filtros['categoria_id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label">Estado</label>
            <select class="form-select form-select-sm tz-auto" name="estado">
                <option value="">Todos</option>
                <?php foreach (Stats::ESTADOS as $e): ?><option value="<?= h($e) ?>" <?= $e === $filtros['estado'] ? 'selected' : '' ?>><?= h($e) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label">Conflicto</label>
            <select class="form-select form-select-sm tz-auto" name="tiene_conflicto">
                <option value="">Indistinto</option>
                <option value="1" <?= $filtros['tiene_conflicto'] === '1' ? 'selected' : '' ?>>Con conflicto</option>
                <option value="0" <?= $filtros['tiene_conflicto'] === '0' ? 'selected' : '' ?>>Sin conflicto</option>
            </select>
        </div>
        <div><label class="form-label">Desde</label><input type="date" class="form-control form-control-sm tz-auto" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
        <div><label class="form-label">Hasta</label><input type="date" class="form-control form-control-sm tz-auto" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
    </div>
    <div class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('admin/productos.php')) ?>">Limpiar filtros</a></div>
</form>

<div class="card">
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Código</th><th>Categoría</th><th>Estado</th><th>Último cambio</th><th>⚠</th><th></th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sin resultados.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $p): ?>
                <tr>
                    <td><a class="mono" href="<?= h(url('admin/producto-detalle.php?codigo=' . urlencode($p['codigo']))) ?>"><?= h($p['codigo']) ?></a></td>
                    <td><?= h($p['categoria_nombre'] ?? '(sin categoría)') ?></td>
                    <td><?= estado_badge($p['estado_actual']) ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h(fmt_fecha($p['updated_at'])) ?></td>
                    <td><?= (int)$p['tiene_conflicto'] === 1 ? '<i class="bi bi-exclamation-triangle-fill" style="color:var(--yellow)"></i>' : '' ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/producto-detalle.php?codigo=' . urlencode($p['codigo']))) ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex align-items-center justify-content-between" style="padding:.5rem 1rem;border-top:1px solid var(--border)">
        <span class="text-muted" style="font-size:12px"><?= $total > 0 ? (($page - 1) * $perPage + 1) . '–' . min($page * $perPage, $total) : 0 ?> de <?= (int)$total ?></span>
        <?php if ($pages > 1): ?>
        <nav><ul class="pagination pagination-sm mb-0 flex-wrap">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= h($qsFiltros . ($qsFiltros ? '&' : '') . 'page=' . $i) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
    </div>
</div>

<script>
const filtros = document.getElementById('filtros');
// Selects y fechas: aplican al instante.
filtros.querySelectorAll('.tz-auto').forEach(el => el.addEventListener('change', () => filtros.submit()));
// Código: búsqueda parcial en vivo, con debounce.
let t = null;
document.getElementById('fCodigo').addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(() => filtros.submit(), 400);
});
// Restaurar el cursor al final tras recargar (mantiene la escritura fluida).
window.addEventListener('load', function () {
    const c = document.getElementById('fCodigo');
    if (c && c.value) { c.focus(); c.setSelectionRange(c.value.length, c.value.length); }
});
</script>
<?php
panel_footer();
