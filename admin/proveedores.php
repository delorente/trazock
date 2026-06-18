<?php
declare(strict_types=1);

// =============================================================================
// admin/proveedores.php — ABM de proveedores (solo admin). Soft-delete.
// Sin unicidad estricta de nombre (puede haber dos "Simmons" con datos distintos).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Proveedor;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/proveedores.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Proveedor::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado del proveedor actualizado.');
        } else {
            $nombre   = trim((string)($_POST['nombre'] ?? ''));
            $contacto = trim((string)($_POST['contacto'] ?? ''));
            $notas    = trim((string)($_POST['notas'] ?? ''));
            $id       = (int)($_POST['id'] ?? 0);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif ($id > 0) {
                Proveedor::actualizar($id, $nombre, $contacto, $notas);
                flash_set('success', 'Proveedor actualizado.');
            } else {
                Proveedor::crear($nombre, $contacto, $notas);
                flash_set('success', 'Proveedor creado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('proveedores.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/proveedores.php'));
    exit;
}

$proveedores = Proveedor::todos();
$csrf        = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalProv" onclick="provNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>';
panel_header('Proveedores', $user, 'proveedores', count($proveedores) . ' proveedor(es)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Los proveedores inactivos no aparecen en nuevos lotes, pero conservan su histórico.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre</th><th>Contacto</th><th>Notas</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($proveedores === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No hay proveedores cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($proveedores as $p): ?>
                <tr class="<?= $p['activo'] ? '' : 'table-secondary' ?>">
                    <td><?= h($p['nombre']) ?></td>
                    <td class="small"><?= h($p['contacto'] ?? '') ?></td>
                    <td class="text-muted small"><?= h($p['notas'] ?? '') ?></td>
                    <td>
                        <span class="badge b-<?= $p['activo'] ? 'activo' : 'inactivo' ?>">
                            <?= $p['activo'] ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalProv"
                                onclick='provEditar(<?= json_encode([
                                    "id" => (int)$p["id"], "nombre" => $p["nombre"],
                                    "contacto" => $p["contacto"], "notas" => $p["notas"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $p['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $p['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $p['activo'] ? 'Inactivar' : 'Reactivar' ?> el proveedor «<?= h(addslashes($p['nombre'])) ?>»?')">
                                <i class="bi bi-toggle-<?= $p['activo'] ? 'on' : 'off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalProv" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="prov_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="prov_titulo">Nuevo proveedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="prov_nombre">Nombre *</label>
          <input class="form-control" id="prov_nombre" name="nombre" maxlength="150" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="prov_contacto">Contacto</label>
          <input class="form-control" id="prov_contacto" name="contacto" maxlength="150">
        </div>
        <div class="mb-3">
          <label class="form-label" for="prov_notas">Notas</label>
          <textarea class="form-control" id="prov_notas" name="notas" rows="2"></textarea>
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
function provNuevo() {
    document.getElementById('prov_titulo').textContent = 'Nuevo proveedor';
    document.getElementById('prov_id').value = '';
    document.getElementById('prov_nombre').value = '';
    document.getElementById('prov_contacto').value = '';
    document.getElementById('prov_notas').value = '';
}
function provEditar(d) {
    document.getElementById('prov_titulo').textContent = 'Editar proveedor';
    document.getElementById('prov_id').value = d.id;
    document.getElementById('prov_nombre').value = d.nombre || '';
    document.getElementById('prov_contacto').value = d.contacto || '';
    document.getElementById('prov_notas').value = d.notas || '';
}
</script>
<?php
panel_footer();
