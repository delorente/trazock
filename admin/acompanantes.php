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
            flash_set('success', 'Estado del empleado actualizado.');
        } else {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $obs    = trim((string)($_POST['observacion'] ?? ''));
            $id     = (int)($_POST['id'] ?? 0);
            $ld = isset($_POST['es_chofer_ld']);
            $cd = isset($_POST['es_chofer_cd']);
            $ay = isset($_POST['es_ayudante']);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif ($id > 0) {
                Acompanante::actualizar($id, $nombre, $obs, $ld, $cd, $ay);
                flash_set('success', 'Empleado actualizado.');
            } else {
                Acompanante::crear($nombre, $obs, $ld, $cd, $ay);
                flash_set('success', 'Empleado creado.');
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
panel_header('Empleados', $user, 'acompanantes', count($acompanantes) . ' empleado(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Padrón único de empleados que salen al reparto: conductores y ayudantes (una misma persona puede cumplir ambos roles). Aparecen en el desplegable de la app de escaneo. Los inactivos conservan su histórico pero no se ofrecen en nuevos repartos. <span class="text-muted">(El encargado de depósito usa el rol Operador; los conductores que entran al sistema se administran además en Usuarios.)</span></p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre</th><th>Observación</th><th class="text-center" title="Chofer larga distancia">Chofer LD</th><th class="text-center" title="Chofer corta distancia">Chofer CD</th><th class="text-center">Ayudante</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($acompanantes === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No hay empleados cargados.</td></tr>
            <?php endif; ?>
            <?php
            $tick = static fn($v) => $v ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">·</span>';
            foreach ($acompanantes as $a): ?>
                <tr class="<?= $a['activo'] ? '' : 'table-secondary' ?>">
                    <td><?= h($a['nombre']) ?></td>
                    <td class="text-muted small"><?= h($a['observacion'] ?? '') ?></td>
                    <td class="text-center"><?= $tick((int)$a['es_chofer_ld']) ?></td>
                    <td class="text-center"><?= $tick((int)$a['es_chofer_cd']) ?></td>
                    <td class="text-center"><?= $tick((int)$a['es_ayudante']) ?></td>
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
                                    "ld" => (int)$a["es_chofer_ld"], "cd" => (int)$a["es_chofer_cd"], "ay" => (int)$a["es_ayudante"],
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
        <h5 class="modal-title" id="acomp_titulo">Nuevo empleado</h5>
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
        <div class="mb-1"><label class="form-label">Roles</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="acomp_ld" name="es_chofer_ld"><label class="form-check-label" for="acomp_ld">Chofer LD (larga distancia) — conductor de la hoja de carga</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="acomp_cd" name="es_chofer_cd"><label class="form-check-label" for="acomp_cd">Chofer CD (corta distancia) — conductor de hojas de reparto</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="acomp_ay" name="es_ayudante"><label class="form-check-label" for="acomp_ay">Ayudante</label></div>
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
    document.getElementById('acomp_titulo').textContent = 'Nuevo empleado';
    document.getElementById('acomp_id').value = '';
    document.getElementById('acomp_nombre').value = '';
    document.getElementById('acomp_obs').value = '';
    document.getElementById('acomp_ld').checked = false;
    document.getElementById('acomp_cd').checked = false;
    document.getElementById('acomp_ay').checked = false;
}
function acompEditar(d) {
    document.getElementById('acomp_titulo').textContent = 'Editar empleado';
    document.getElementById('acomp_id').value = d.id;
    document.getElementById('acomp_nombre').value = d.nombre || '';
    document.getElementById('acomp_obs').value = d.observacion || '';
    document.getElementById('acomp_ld').checked = !!d.ld;
    document.getElementById('acomp_cd').checked = !!d.cd;
    document.getElementById('acomp_ay').checked = !!d.ay;
}
</script>
<?php
panel_footer();
