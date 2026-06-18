<?php
declare(strict_types=1);

// =============================================================================
// admin/categorias.php — ABM de categorías (solo admin). Soft-delete.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Categoria;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

// --- POST (crear / actualizar / toggle) con CSRF + PRG -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/categorias.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Categoria::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado de la categoría actualizado.');
        } else {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $notas  = trim((string)($_POST['notas'] ?? ''));
            $id     = (int)($_POST['id'] ?? 0);

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif (Categoria::nombreEnUso($nombre, $id > 0 ? $id : null)) {
                flash_set('danger', 'Ya existe una categoría con ese nombre.');
            } elseif ($id > 0) {
                Categoria::actualizar($id, $nombre, $notas);
                flash_set('success', 'Categoría actualizada.');
            } else {
                Categoria::crear($nombre, $notas);
                flash_set('success', 'Categoría creada.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('categorias.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/categorias.php'));
    exit;
}

$categorias = Categoria::todas();
$csrf       = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCat" onclick="catNuevo()"><i class="bi bi-plus-lg me-1"></i>Nueva</button>';
panel_header('Categorías', $user, 'categorias', count($categorias) . ' categoría(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Las categorías inactivas no aparecen en nuevos lotes, pero conservan su histórico.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre</th><th>Notas</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($categorias === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No hay categorías cargadas.</td></tr>
            <?php endif; ?>
            <?php foreach ($categorias as $c): ?>
                <tr class="<?= $c['activo'] ? '' : 'table-secondary' ?>">
                    <td><?= h($c['nombre']) ?></td>
                    <td class="text-muted small"><?= h($c['notas'] ?? '') ?></td>
                    <td>
                        <?php if ($c['activo']): ?>
                            <span class="badge b-activo">Activa</span>
                        <?php else: ?>
                            <span class="badge b-inactivo">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalCat"
                                onclick='catEditar(<?= json_encode([
                                    "id" => (int)$c["id"], "nombre" => $c["nombre"], "notas" => $c["notas"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $c['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $c['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $c['activo'] ? 'Inactivar' : 'Reactivar' ?> la categoría «<?= h(addslashes($c['nombre'])) ?>»?')">
                                <i class="bi bi-toggle-<?= $c['activo'] ? 'on' : 'off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal crear/editar -->
<div class="modal fade" id="modalCat" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="cat_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="cat_titulo">Nueva categoría</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="cat_nombre">Nombre *</label>
          <input class="form-control" id="cat_nombre" name="nombre" maxlength="100" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cat_notas">Notas</label>
          <textarea class="form-control" id="cat_notas" name="notas" rows="2"></textarea>
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
function catNuevo() {
    document.getElementById('cat_titulo').textContent = 'Nueva categoría';
    document.getElementById('cat_id').value = '';
    document.getElementById('cat_nombre').value = '';
    document.getElementById('cat_notas').value = '';
}
function catEditar(d) {
    document.getElementById('cat_titulo').textContent = 'Editar categoría';
    document.getElementById('cat_id').value = d.id;
    document.getElementById('cat_nombre').value = d.nombre || '';
    document.getElementById('cat_notas').value = d.notas || '';
}
</script>
<?php
panel_footer();
