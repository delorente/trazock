<?php
declare(strict_types=1);

// =============================================================================
// admin/motivos.php — ABM de motivos (solo admin).
// Un motivo puede aplicar a varios tipos (reingreso/devolucion/baja). Flag editable_libre.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Motivo;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

$tiposLabel = ['reingreso' => 'Reingreso', 'devolucion' => 'Devolución', 'baja' => 'Baja'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/motivos.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Motivo::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado del motivo actualizado.');
        } else {
            $nombre   = trim((string)($_POST['nombre'] ?? ''));
            $tipos    = array_values(array_filter((array)($_POST['tipos'] ?? []), 'is_string'));
            $editable = isset($_POST['editable_libre']);
            $id       = (int)($_POST['id'] ?? 0);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif (array_intersect($tipos, Motivo::TIPOS_VALIDOS) === []) {
                flash_set('danger', 'Elegí al menos un tipo válido.');
            } elseif ($id > 0) {
                Motivo::actualizar($id, $nombre, $tipos, $editable);
                flash_set('success', 'Motivo actualizado.');
            } else {
                Motivo::crear($nombre, $tipos, $editable);
                flash_set('success', 'Motivo creado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('motivos.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/motivos.php'));
    exit;
}

$motivos = Motivo::todos();
$csrf    = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMot" onclick="motNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>';
panel_header('Motivos', $user, 'motivos', count($motivos) . ' motivo(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Un motivo puede aplicar a varios tipos. Los marcados con <span class="badge b-conflict">Texto libre</span> exigen una aclaración al usarse.</p>

<div class="card">
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nombre</th><th>Tipos</th><th>Texto libre</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php if ($motivos === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No hay motivos cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($motivos as $m): $tipos = explode(',', (string)$m['tipo']); ?>
                <tr class="<?= $m['activo'] ? '' : 'opacity-50' ?>">
                    <td><?= h($m['nombre']) ?></td>
                    <td>
                        <?php foreach ($tipos as $t): ?>
                            <span class="badge b-REINGRESO me-1"><?= h($tiposLabel[$t] ?? $t) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= $m['editable_libre'] ? '<i class="bi bi-check-circle-fill" style="color:var(--green)"></i>' : '<span class="text-muted">—</span>' ?></td>
                    <td><span class="badge b-<?= $m['activo'] ? 'activo' : 'inactivo' ?>"><?= $m['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalMot"
                                onclick='motEditar(<?= json_encode([
                                    "id" => (int)$m["id"], "nombre" => $m["nombre"], "tipos" => $tipos,
                                    "editable" => (int)$m["editable_libre"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $m['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $m['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $m['activo'] ? 'Inactivar' : 'Reactivar' ?> el motivo «<?= h(addslashes($m['nombre'])) ?>»?')">
                                <i class="bi bi-toggle-<?= $m['activo'] ? 'on' : 'off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalMot" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="mot_id" value="">
      <div class="modal-header"><h6 class="modal-title fw-bold" id="mot_titulo">Nuevo motivo</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="mot_nombre">Nombre *</label><input class="form-control" id="mot_nombre" name="nombre" maxlength="100" required></div>
        <div class="mb-3">
          <label class="form-label">Aplica a los tipos *</label>
          <div class="form-check"><input class="form-check-input mot-tipo" type="checkbox" name="tipos[]" value="reingreso" id="mot_t_re"><label class="form-check-label" for="mot_t_re">Reingreso</label></div>
          <div class="form-check"><input class="form-check-input mot-tipo" type="checkbox" name="tipos[]" value="devolucion" id="mot_t_de"><label class="form-check-label" for="mot_t_de">Devolución</label></div>
          <div class="form-check"><input class="form-check-input mot-tipo" type="checkbox" name="tipos[]" value="baja" id="mot_t_ba"><label class="form-check-label" for="mot_t_ba">Baja</label></div>
        </div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="mot_editable" name="editable_libre" value="1"><label class="form-check-label" for="mot_editable">Requiere texto libre obligatorio al usarse (ej. "Otros")</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary btn-sm fw-bold">Guardar</button></div>
  </form></div>
</div>

<script>
function motNuevo() {
    document.getElementById('mot_titulo').textContent = 'Nuevo motivo';
    document.getElementById('mot_id').value = '';
    document.getElementById('mot_nombre').value = '';
    document.querySelectorAll('.mot-tipo').forEach(c => c.checked = false);
    document.getElementById('mot_editable').checked = false;
}
function motEditar(d) {
    document.getElementById('mot_titulo').textContent = 'Editar motivo';
    document.getElementById('mot_id').value = d.id;
    document.getElementById('mot_nombre').value = d.nombre || '';
    document.querySelectorAll('.mot-tipo').forEach(c => c.checked = (d.tipos || []).includes(c.value));
    document.getElementById('mot_editable').checked = d.editable == 1;
}
</script>
<?php
panel_footer();
