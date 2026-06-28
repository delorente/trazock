<?php
declare(strict_types=1);

// =============================================================================
// admin/conflictos.php — cola de conflictos (admin + gestor).
// Pendientes por defecto; toggle "ver resueltos". Acciones inline.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Categoria;
use Trazock\Models\Conflicto;

$user = Auth::requierePanel(['admin', 'gestor', 'logistica']);
$puedeEditar = in_array($user['rol'], ['admin', 'logistica'], true); // gestor = solo lectura

$verResueltos = ($_GET['resueltos'] ?? '') === '1';
$filtros = [
    'tipo'         => (string)($_GET['tipo'] ?? ''),
    'categoria_id' => (string)($_GET['categoria_id'] ?? ''),
];

$conflictos = Conflicto::listar(!$verResueltos, $filtros);
$categorias = Categoria::activas();
$csrf       = Auth::tokenCSRF();

$tipos = ['producto_inexistente_en_no_ingreso' => 'Producto inexistente', 'transicion_ilegal' => 'Transición ilegal'];

$acciones = '<div class="btn-group btn-group-sm">'
    . '<a class="btn ' . ($verResueltos ? 'btn-outline-secondary' : 'btn-primary') . '" href="?' . h(http_build_query(array_merge($filtros, ['resueltos' => '0']))) . '">Pendientes</a>'
    . '<a class="btn ' . ($verResueltos ? 'btn-primary' : 'btn-outline-secondary') . '" href="?' . h(http_build_query(array_merge($filtros, ['resueltos' => '1']))) . '">Todos</a>'
    . '</div>';

panel_header('Conflictos', $user, 'conflictos', '', $acciones);
?>
<form class="card card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="f_tipo">Tipo</label>
            <select class="form-select form-select-sm" id="f_tipo" name="tipo">
                <option value="">Todos</option>
                <?php foreach ($tipos as $k => $v): ?>
                    <option value="<?= h($k) ?>" <?= $k === $filtros['tipo'] ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="f_cat">Categoría</label>
            <select class="form-select form-select-sm" id="f_cat" name="categoria_id">
                <option value="">Todas</option>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $filtros['categoria_id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6 d-flex align-items-end gap-2">
            <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
            <input type="hidden" name="resueltos" value="<?= $verResueltos ? '1' : '0' ?>">
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <?= $verResueltos ? 'Conflictos resueltos' : 'Conflictos pendientes' ?>
        (<?= count($conflictos) ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Producto</th><th>Tipo</th><th>Descripción</th><th>Fecha</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($conflictos === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No hay conflictos <?= $verResueltos ? 'resueltos' : 'pendientes' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($conflictos as $c): ?>
                <tr data-conflicto="<?= (int)$c['id'] ?>">
                    <td>
                        <a class="mono" href="<?= h(url('admin/producto-detalle.php?codigo=' . urlencode($c['producto_codigo']))) ?>"><?= h($c['producto_codigo']) ?></a>
                        <div class="small text-muted"><?= h($c['categoria_nombre'] ?? '(sin categoría)') ?></div>
                    </td>
                    <td><?= conflicto_badge($c['tipo']) ?></td>
                    <td class="small"><?= h($c['descripcion']) ?></td>
                    <td class="small text-muted"><?= h(fmt_fecha($c['fecha_generacion'])) ?></td>
                    <td class="text-end">
                        <?php if ($c['revisado_at'] === null && $puedeEditar): ?>
                            <div class="input-group input-group-sm" style="min-width:260px">
                                <input class="form-control" placeholder="Nota (opcional)" data-nota="<?= (int)$c['id'] ?>">
                                <button class="btn btn-outline-success" onclick="resolver(<?= (int)$c['id'] ?>)">Revisado</button>
                                <a class="btn btn-outline-secondary" href="<?= h(url('admin/producto-detalle.php?codigo=' . urlencode($c['producto_codigo']))) ?>">Ajustar</a>
                            </div>
                        <?php elseif ($c['revisado_at'] === null): ?>
                            <span class="badge b-PENDIENTE">Pendiente</span>
                        <?php else: ?>
                            <span class="badge b-resolved">✓ Revisado</span>
                            <div class="small text-muted">
                                <?= h($c['revisado_por_nombre'] ?? '') ?> · <?= h(fmt_fecha($c['revisado_at'])) ?>
                                <?php if (!empty($c['nota_resolucion'])): ?><br><?= h($c['nota_resolucion']) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const TZ_CSRF = <?= json_encode($csrf) ?>;
const TZ_API = <?= json_encode(url('api/conflicto-resolver.php')) ?>;
function resolver(id) {
    const nota = (document.querySelector('[data-nota="' + id + '"]') || {}).value || '';
    fetch(TZ_API, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: TZ_CSRF, conflicto_id: id, nota: nota })
    }).then(r => r.json().then(d => ({ ok: r.ok, d })))
      .then(({ ok, d }) => {
          if (ok && d.ok) {
              const row = document.querySelector('[data-conflicto="' + id + '"]');
              if (row) row.remove();
          } else { alert(d.error || 'No se pudo marcar el conflicto.'); }
      }).catch(() => alert('Error de red.'));
}
</script>
<?php
panel_footer();
