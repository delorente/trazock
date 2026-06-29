<?php
declare(strict_types=1);

// =============================================================================
// admin/prefijos.php — ABM de prefijos de Nº de orden (solo admin).
//
// El prefijo (lo anterior al primer '-': 0775-123456 → 0775) identifica el
// origen de la venta. Acá se le asigna un nombre interno (para el filtro del
// panel) y un nombre público + token reseteable para el acceso del local a SUS
// órdenes (seguimiento/local.php?t=token).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Prefijo;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/prefijos.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    try {
        if ($accion === 'toggle') {
            Prefijo::toggleActivo($id);
            flash_set('success', 'Estado actualizado.');
        } elseif ($accion === 'gen_token') {
            Prefijo::regenerarToken($id);
            flash_set('success', 'Link público generado. El anterior, si existía, quedó invalidado.');
        } elseif ($accion === 'quitar_token') {
            Prefijo::quitarToken($id);
            flash_set('success', 'Acceso público quitado.');
        } else { // guardar
            $prefijo = trim((string)($_POST['prefijo'] ?? ''));
            $nomInt  = trim((string)($_POST['nombre_interno'] ?? ''));
            $nomPub  = trim((string)($_POST['nombre_publico'] ?? ''));
            if ($prefijo === '' || $nomInt === '') {
                flash_set('danger', 'El prefijo y el nombre interno son obligatorios.');
            } elseif (Prefijo::existsByPrefijo($prefijo, $id)) {
                flash_set('danger', 'Ya existe un prefijo «' . $prefijo . '».');
            } elseif ($id > 0) {
                Prefijo::actualizar($id, $prefijo, $nomInt, $nomPub);
                flash_set('success', 'Prefijo actualizado.');
            } else {
                Prefijo::crear($prefijo, $nomInt, $nomPub);
                flash_set('success', 'Prefijo creado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('prefijos.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/prefijos.php'));
    exit;
}

$prefijos  = Prefijo::todos();
$sugeridos = Prefijo::sugeridos();
$csrf      = Auth::tokenCSRF();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalPref" onclick="prefNuevo()"><i class="bi bi-plus-lg me-1"></i>Nuevo prefijo</button>';
panel_header('Prefijos', $user, 'prefijos', count($prefijos) . ' prefijo(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">El prefijo es la parte del Nº de orden anterior al primer «-» (ej. <span class="mono">0775</span>-123456 → <span class="mono">0775</span>). El <strong>nombre interno</strong> se usa para filtrar en Reportes; el <strong>nombre público</strong> + link con token le da al local acceso de solo lectura a sus órdenes.</p>

<?php if ($sugeridos !== []): ?>
<div class="alert alert-light border small mb-3">
  <i class="bi bi-lightbulb me-1"></i>Prefijos detectados en órdenes sin nombre:
  <?php foreach ($sugeridos as $s): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1 mb-1" onclick="prefNuevo('<?= h(addslashes($s['prefijo'])) ?>')"><?= h($s['prefijo']) ?> <span class="text-muted">(<?= (int)$s['n'] ?>)</span></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Prefijo</th><th>Nombre interno</th><th>Nombre público</th><th>Acceso del local</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if ($prefijos === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No hay prefijos cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($prefijos as $p):
                $tk  = (string)($p['token'] ?? '');
                $url = $tk !== '' ? local_url($tk) : '';
            ?>
                <tr class="<?= $p['activo'] ? '' : 'table-secondary' ?>">
                    <td class="mono"><?= h($p['prefijo']) ?></td>
                    <td><?= h($p['nombre_interno']) ?></td>
                    <td class="text-muted"><?= h((string)($p['nombre_publico'] ?? '') !== '' ? (string)$p['nombre_publico'] : '—') ?></td>
                    <td style="min-width:260px">
                        <?php if ($tk !== ''): ?>
                            <div class="input-group input-group-sm" style="max-width:340px">
                                <input class="form-control form-control-sm mono" style="font-size:11px" value="<?= h($url) ?>" readonly onclick="this.select()">
                                <button class="btn btn-outline-secondary" type="button" title="Copiar" onclick="prefCopiar(this,'<?= h(addslashes($url)) ?>')"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="mt-1 d-flex gap-1">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="accion" value="gen_token">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:11px" onclick="tzConfirm(this.closest('form'), '¿Resetear el link de «<?= h(addslashes($p['nombre_interno'])) ?>»? El link actual dejará de funcionar.')"><i class="bi bi-arrow-repeat me-1"></i>Resetear</button>
                                </form>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="accion" value="quitar_token">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px" onclick="tzConfirm(this.closest('form'), '¿Quitar el acceso público de «<?= h(addslashes($p['nombre_interno'])) ?>»?')"><i class="bi bi-x-lg me-1"></i>Quitar</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="accion" value="gen_token">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:11px"><i class="bi bi-link-45deg me-1"></i>Generar link</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge b-<?= $p['activo'] ? 'activo' : 'inactivo' ?>"><?= $p['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                                data-bs-toggle="modal" data-bs-target="#modalPref"
                                onclick='prefEditar(<?= json_encode([
                                    "id" => (int)$p["id"], "prefijo" => $p["prefijo"],
                                    "nombre_interno" => $p["nombre_interno"], "nombre_publico" => $p["nombre_publico"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-<?= $p['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                                    title="<?= $p['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                                    onclick="tzConfirm(this.closest('form'), '¿<?= $p['activo'] ? 'Inactivar' : 'Reactivar' ?> el prefijo «<?= h(addslashes($p['prefijo'])) ?>»?')">
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

<div class="modal fade" id="modalPref" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="pref_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="pref_titulo">Nuevo prefijo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="pref_prefijo">Prefijo *</label>
          <input class="form-control mono" id="pref_prefijo" name="prefijo" maxlength="40" required placeholder="Ej. 0775">
          <div class="form-text">La parte del Nº de orden anterior al «-».</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="pref_ni">Nombre interno *</label>
          <input class="form-control" id="pref_ni" name="nombre_interno" maxlength="120" required placeholder="Ej. Ventas Online / Local Centro">
          <div class="form-text">Para el filtro de Reportes (lo ves vos).</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="pref_np">Nombre público</label>
          <input class="form-control" id="pref_np" name="nombre_publico" maxlength="150" placeholder="Opcional — lo ve el local en su link">
          <div class="form-text">Título que ve el local en su listado. Si se deja vacío, se usa el nombre interno.</div>
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
function prefNuevo(prefijo) {
    document.getElementById('pref_titulo').textContent = 'Nuevo prefijo';
    document.getElementById('pref_id').value = '';
    document.getElementById('pref_prefijo').value = prefijo || '';
    document.getElementById('pref_ni').value = '';
    document.getElementById('pref_np').value = '';
    if (prefijo) { new bootstrap.Modal(document.getElementById('modalPref')).show(); }
}
function prefEditar(d) {
    document.getElementById('pref_titulo').textContent = 'Editar prefijo';
    document.getElementById('pref_id').value = d.id;
    document.getElementById('pref_prefijo').value = d.prefijo || '';
    document.getElementById('pref_ni').value = d.nombre_interno || '';
    document.getElementById('pref_np').value = d.nombre_publico || '';
}
function prefCopiar(btn, url) {
    navigator.clipboard.writeText(url).then(function () {
        const i = btn.querySelector('i'); const old = i.className;
        i.className = 'bi bi-check-lg'; setTimeout(function(){ i.className = old; }, 1200);
    });
}
</script>
<?php
panel_footer();
