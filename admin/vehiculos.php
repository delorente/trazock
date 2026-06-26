<?php
declare(strict_types=1);

// =============================================================================
// admin/vehiculos.php — ABM de vehículos/unidades del reparto (solo admin).
// Nombre (patente o alias) + observación, soft-delete. Alimenta el desplegable
// de la app de escaneo.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Vehiculo;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/vehiculos.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Vehiculo::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado del vehículo actualizado.');
        } else {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $obs    = trim((string)($_POST['observacion'] ?? ''));
            $id     = (int)($_POST['id'] ?? 0);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif ($id > 0) {
                Vehiculo::actualizar($id, $nombre, $obs);
                flash_set('success', 'Vehículo actualizado.');
            } else {
                Vehiculo::crear($nombre, $obs);
                flash_set('success', 'Vehículo creado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('vehiculos.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/vehiculos.php'));
    exit;
}

$vehiculos = Vehiculo::todos();
$csrf      = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalVeh" onclick="vehNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>';
panel_header('Vehículos', $user, 'vehiculos', count($vehiculos) . ' vehículo(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Unidades disponibles para el reparto. Aparecen en el desplegable de la app de escaneo al iniciar una salida a reparto. Los inactivos conservan su histórico pero no se ofrecen en nuevos repartos.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre / Patente</th><th>Observación</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($vehiculos === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No hay vehículos cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehiculos as $v): ?>
                <tr class="<?= $v['activo'] ? '' : 'table-secondary' ?>">
                    <td><?= h($v['nombre']) ?></td>
                    <td class="text-muted small"><?= h($v['observacion'] ?? '') ?></td>
                    <td>
                        <span class="badge b-<?= $v['activo'] ? 'activo' : 'inactivo' ?>">
                            <?= $v['activo'] ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalVeh"
                                onclick='vehEditar(<?= json_encode([
                                    "id" => (int)$v["id"], "nombre" => $v["nombre"],
                                    "observacion" => $v["observacion"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $v['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $v['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $v['activo'] ? 'Inactivar' : 'Reactivar' ?> la unidad «<?= h(addslashes($v['nombre'])) ?>»?')">
                                <i class="bi bi-toggle-<?= $v['activo'] ? 'on' : 'off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalVeh" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="veh_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="veh_titulo">Nuevo vehículo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="veh_nombre">Nombre / Patente *</label>
          <input class="form-control" id="veh_nombre" name="nombre" maxlength="120" required placeholder="Ej. Iveco Daily — AB123CD">
        </div>
        <div class="mb-3">
          <label class="form-label" for="veh_obs">Observación</label>
          <input class="form-control" id="veh_obs" name="observacion" maxlength="255" placeholder="Opcional (ej. capacidad, estado)">
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
function vehNuevo() {
    document.getElementById('veh_titulo').textContent = 'Nuevo vehículo';
    document.getElementById('veh_id').value = '';
    document.getElementById('veh_nombre').value = '';
    document.getElementById('veh_obs').value = '';
}
function vehEditar(d) {
    document.getElementById('veh_titulo').textContent = 'Editar vehículo';
    document.getElementById('veh_id').value = d.id;
    document.getElementById('veh_nombre').value = d.nombre || '';
    document.getElementById('veh_obs').value = d.observacion || '';
}
</script>
<?php
panel_footer();
