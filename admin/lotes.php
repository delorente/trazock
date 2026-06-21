<?php
declare(strict_types=1);

// =============================================================================
// admin/lotes.php — listado filtrable de lotes recientes (admin + gestor).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Lote;
use Trazock\Models\Usuario;

$user = Auth::requierePanel();

$filtros = [
    'tipo'           => (string)($_GET['tipo'] ?? ''),
    'responsable_id' => (string)($_GET['responsable_id'] ?? ''),
    'fecha_desde'    => (string)($_GET['fecha_desde'] ?? ''),
    'fecha_hasta'    => (string)($_GET['fecha_hasta'] ?? ''),
    'con_conflictos' => (string)($_GET['con_conflictos'] ?? ''),
];

$lotes    = Lote::listar($filtros);
$usuarios = Usuario::todos();
$tipos    = ['INGRESO', 'SALIDA_REPARTO', 'ENTREGA', 'REINGRESO', 'SALIDA_DEVOLUCION', 'BAJA'];

panel_header('Lotes', $user, 'lotes', count($lotes) . ' registrados');
?>
<form class="card card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="f_tipo">Tipo</label>
            <select class="form-select form-select-sm" id="f_tipo" name="tipo">
                <option value="">Todos</option>
                <?php foreach ($tipos as $t): ?>
                    <option value="<?= h($t) ?>" <?= $t === $filtros['tipo'] ? 'selected' : '' ?>><?= h(tipo_lote_label($t)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="f_resp">Responsable</label>
            <select class="form-select form-select-sm" id="f_resp" name="responsable_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === $filtros['responsable_id'] ? 'selected' : '' ?>><?= h($u['nombre_completo']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f_desde">Desde</label>
            <input class="form-control form-control-sm" type="date" id="f_desde" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f_hasta">Hasta</label>
            <input class="form-control form-control-sm" type="date" id="f_hasta" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f_conf">Conflictos</label>
            <select class="form-select form-select-sm" id="f_conf" name="con_conflictos">
                <option value="">Indistinto</option>
                <option value="1" <?= $filtros['con_conflictos'] === '1' ? 'selected' : '' ?>>Con conflictos</option>
                <option value="0" <?= $filtros['con_conflictos'] === '0' ? 'selected' : '' ?>>Sin conflictos</option>
            </select>
        </div>
    </div>
    <div class="mt-2 d-flex gap-2">
        <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('admin/lotes.php')) ?>">Limpiar</a>
    </div>
</form>

<div class="card">
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Lote</th><th>Cierre</th><th>Tipo</th><th>Responsable</th><th>Órdenes</th><th>Items</th><th>Conflictos</th><th></th></tr></thead>
            <tbody>
            <?php if ($lotes === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Sin lotes.</td></tr>
            <?php endif; ?>
            <?php foreach ($lotes as $l): ?>
                <tr>
                    <td class="mono" style="font-size:12px"><?= h(lote_num((int)$l['id'], $l['created_at'])) ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h(fmt_fecha($l['timestamp_cierre'] ?? $l['created_at'])) ?></td>
                    <td><?= tipo_lote_badge($l['tipo']) ?></td>
                    <td><?= h($l['responsable'] ?? '—') ?></td>
                    <td><?= (int)($l['ordenes'] ?? 0) > 0 ? (int)$l['ordenes'] : '<span class="text-muted">—</span>' ?></td>
                    <td><?= (int)$l['items'] ?></td>
                    <td><?= (int)$l['conflictos'] > 0 ? '<span class="badge b-conflict">⚠ ' . (int)$l['conflictos'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/lote-detalle.php?id=' . (int)$l['id'])) ?>">Detalle</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
panel_footer();
