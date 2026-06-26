<?php
declare(strict_types=1);

// =============================================================================
// admin/acompanantes.php — ABM de acompañantes/ayudantes del reparto (solo admin).
// Nombre + observación, soft-delete. Alimenta el desplegable de la app de escaneo.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Acompanante;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/acompanantes.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Acompanante::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado del acompañante actualizado.');
        } else {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $obs    = trim((string)($_POST['observacion'] ?? ''));
            $id     = (int)($_POST['id'] ?? 0);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif ($id > 0) {
                Acompanante::actualizar($id, $nombre, $obs);
                flash_set('success', 'Acompañante actualizado.');
            } else {
                Acompanante::crear($nombre, $obs);
                flash_set('success', 'Acompañante creado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('acompanantes.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/acompanantes.php'));
    exit;
}

$acompanantes = Acompanante::todos();
$csrf         = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAcomp" onclick="acompNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>';
panel_header('Acompañantes', $user, 'acompanantes', count($acompanantes) . ' acompañante(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Son los ayudantes que pueden salir al reparto. Aparecen en el desplegable de la app de escaneo al iniciar una salida a reparto. Los inactivos conservan su histórico pero no se ofrecen en nuevos repartos.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre</th><th>Observación</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($acompanantes === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No hay acompañantes cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($acompanantes as $a): ?>
                <tr class="<?= $a['activo'] ? '' : 'table-secondary' ?>">
                    <td><?= h($a['nombre']) ?></td>
                    <td class="text-muted small"><?= h($a['observacion'] ?? '') ?></td>
                    <td>
                        <span class="badge b-<?= $a['activo'] ? 'activo' : 'inactivo' ?>">
                            <?= $a['activo'] ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalAcomp"
                                onclick='acompEditar(<?= json_encode([
                                    "id" => (int)$a["id"], "nombre" => $a["nombre"],
                                    "observacion" => $a["observacion"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $a['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $a['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $a['activo'] ? 'Inactivar' : 'Reactivar' ?> a «<?= h(addslashes($a['nombre'])) ?>»?')">
                                <i class="bi bi-toggle-<?= $a['activo'] ? 'on' : 'off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalAcomp" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="acomp_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="acomp_titulo">Nuevo acompañante</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="acomp_nombre">Nombre *</label>
          <input class="form-control" id="acomp_nombre" name="nombre" maxlength="120" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="acomp_obs">Observación</label>
          <input class="form-control" id="acomp_obs" name="observacion" maxlength="255" placeholder="Opcional (ej. teléfono, turno)">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function acompNuevo() {
    document.getElementById('acomp_titulo').textContent = 'Nuevo acompañante';
    document.getElementById('acomp_id').value = '';
    document.getElementById('acomp_nombre').value = '';
    document.getElementById('acomp_obs').value = '';
}
function acompEditar(d) {
    document.getElementById('acomp_titulo').textContent = 'Editar acompañante';
    document.getElementById('acomp_id').value = d.id;
    document.getElementById('acomp_nombre').value = d.nombre || '';
    document.getElementById('acomp_obs').value = d.observacion || '';
}
</script>
<?php
panel_footer();
