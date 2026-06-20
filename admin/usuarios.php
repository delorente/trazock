<?php
declare(strict_types=1);

// =============================================================================
// admin/usuarios.php — ABM de usuarios (solo admin). Los 4 roles. Soft-delete.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Usuario;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

$rolesLabel = [
    'admin'         => 'Administrador',
    'gestor'        => 'Supervisor',   // solo Reportes
    'operador'      => 'Operador',
    'transportista' => 'Transportista',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/usuarios.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    // El superadmin protegido (config: SUPERADMIN_USER) no se toca desde la UI.
    $objetivo = $id > 0 ? Usuario::findById($id) : null;
    $esProtegido = $objetivo !== null && Auth::esSuperadmin((string)$objetivo['usuario']);

    try {
        if ($esProtegido) {
            flash_set('warning', 'Ese usuario está protegido y no se puede modificar desde el panel.');
        } elseif ($accion === 'toggle') {
            if ($id === (int)$user['id']) {
                flash_set('warning', 'No podés desactivar tu propia cuenta.');
            } else {
                Usuario::toggleActivo($id);
                flash_set('success', 'Estado del usuario actualizado.');
            }
        } else {
            $usuario  = trim((string)($_POST['usuario'] ?? ''));
            $nombre   = trim((string)($_POST['nombre_completo'] ?? ''));
            $rol      = (string)($_POST['rol'] ?? '');
            $password = (string)($_POST['password'] ?? '');

            if ($nombre === '' || $rol === '') {
                flash_set('danger', 'Nombre y rol son obligatorios.');
            } elseif (!in_array($rol, Usuario::ROLES_VALIDOS, true)) {
                flash_set('danger', 'Rol inválido.');
            } elseif ($id > 0) {
                // --- Edición ---
                Usuario::actualizar($id, $nombre, $rol);
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        flash_set('warning', 'Usuario actualizado, pero la contraseña no se cambió (mínimo 6 caracteres).');
                    } else {
                        Usuario::cambiarPassword($id, $password);
                        flash_set('success', 'Usuario y contraseña actualizados.');
                    }
                } else {
                    flash_set('success', 'Usuario actualizado.');
                }
            } else {
                // --- Alta ---
                if ($usuario === '') {
                    flash_set('danger', 'El nombre de usuario es obligatorio.');
                } elseif (strlen($password) < 6) {
                    flash_set('danger', 'La contraseña debe tener al menos 6 caracteres.');
                } elseif (Usuario::existsByUsuario($usuario)) {
                    flash_set('danger', 'Ya existe un usuario con ese nombre.');
                } else {
                    Usuario::crear($usuario, $nombre, $password, $rol);
                    flash_set('success', 'Usuario creado.');
                }
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('usuarios.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/usuarios.php'));
    exit;
}

$usuarios = Usuario::todos();
$csrf     = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUsr" onclick="usrNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo usuario</button>';
panel_header('Usuarios', $user, 'usuarios', count($usuarios) . ' usuario(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Los usuarios inactivos no pueden iniciar sesión, pero conservan su histórico de lotes.</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Usuario</th><th>Nombre completo</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr class="<?= $u['activo'] ? '' : 'table-secondary' ?>">
                    <td><span class="mono"><?= h($u['usuario']) ?></span><?= (int)$u['id'] === (int)$user['id'] ? ' <span class="badge b-inactivo">vos</span>' : '' ?></td>
                    <td><?= h($u['nombre_completo']) ?></td>
                    <td><?= rol_badge($u['rol']) ?></td>
                    <td>
                        <span class="badge b-<?= $u['activo'] ? 'activo' : 'inactivo' ?>">
                            <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if (Auth::esSuperadmin((string)$u['usuario'])): ?>
                        <span class="badge b-inactivo" title="Acceso de emergencia: no se puede editar ni desactivar desde el panel"><i class="bi bi-shield-lock me-1"></i>protegido</span>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalUsr"
                                onclick='usrEditar(<?= json_encode([
                                    "id" => (int)$u["id"], "usuario" => $u["usuario"],
                                    "nombre" => $u["nombre_completo"], "rol" => $u["rol"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $u['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $u['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $u['activo'] ? 'Inactivar' : 'Reactivar' ?> al usuario «<?= h(addslashes($u['usuario'])) ?>»?')">
                                <i class="bi bi-person-<?= $u['activo'] ? 'dash' : 'check' ?>"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; /* superadmin */ ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalUsr" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="usr_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="usr_titulo">Nuevo usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3" id="usr_usuario_wrap">
          <label class="form-label" for="usr_usuario">Usuario *</label>
          <input class="form-control" id="usr_usuario" name="usuario" maxlength="50" autocomplete="off">
          <div class="form-text" id="usr_usuario_hint"></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="usr_nombre">Nombre completo *</label>
          <input class="form-control" id="usr_nombre" name="nombre_completo" maxlength="150" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="usr_rol">Rol *</label>
          <select class="form-select" id="usr_rol" name="rol" required>
            <option value="admin">Administrador (acceso total)</option>
            <option value="gestor">Supervisor (solo Reportes)</option>
            <option value="operador">Operador (escáner)</option>
            <option value="transportista">Transportista (escáner)</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="usr_password">Contraseña <span id="usr_pass_req">*</span></label>
          <div class="input-group">
            <input class="form-control" type="password" id="usr_password" name="password" autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" data-toggle-pass="usr_password" title="Mostrar/ocultar"><i class="bi bi-eye"></i></button>
          </div>
          <div class="form-text" id="usr_pass_hint">Mínimo 6 caracteres.</div>
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
function usrNuevo() {
    document.getElementById('usr_titulo').textContent = 'Nuevo usuario';
    document.getElementById('usr_id').value = '';
    document.getElementById('usr_usuario').value = '';
    document.getElementById('usr_usuario').removeAttribute('disabled');
    document.getElementById('usr_usuario_hint').textContent = '';
    document.getElementById('usr_nombre').value = '';
    document.getElementById('usr_rol').value = 'operador';
    document.getElementById('usr_password').value = '';
    document.getElementById('usr_pass_req').style.display = 'inline';
    document.getElementById('usr_pass_hint').textContent = 'Mínimo 6 caracteres.';
}
function usrEditar(d) {
    document.getElementById('usr_titulo').textContent = 'Editar usuario';
    document.getElementById('usr_id').value = d.id;
    // El nombre de usuario no se edita (es la identidad de login).
    document.getElementById('usr_usuario').value = d.usuario || '';
    document.getElementById('usr_usuario').setAttribute('disabled', 'disabled');
    document.getElementById('usr_usuario_hint').textContent = 'El nombre de usuario no se puede cambiar.';
    document.getElementById('usr_nombre').value = d.nombre || '';
    document.getElementById('usr_rol').value = d.rol || 'operador';
    document.getElementById('usr_password').value = '';
    document.getElementById('usr_pass_req').style.display = 'none';
    document.getElementById('usr_pass_hint').textContent = 'Dejar en blanco para no cambiarla.';
}
</script>
<?php
panel_footer();
