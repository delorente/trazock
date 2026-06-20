<?php
declare(strict_types=1);

// =============================================================================
// admin/zonas.php — ABM de zonas de reparto (solo admin). Soft-delete.
// Una zona agrupa localidades (provincia + ciudad opcional). El escáner valida
// cada QR de SALIDA_REPARTO contra la zona elegida. Ciudad vacía = toda la provincia.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Orden;
use Trazock\Models\Zona;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

// --- POST (crear / actualizar / toggle) con CSRF + PRG -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/zonas.php'));
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'toggle') {
            Zona::toggleActivo((int)($_POST['id'] ?? 0));
            flash_set('success', 'Estado de la zona actualizado.');
        } else {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $id     = (int)($_POST['id'] ?? 0);

            // Localidades: arrays paralelos provincia[]/ciudad[].
            $provs   = (array)($_POST['provincia'] ?? []);
            $ciudades = (array)($_POST['ciudad'] ?? []);
            $localidades = [];
            foreach ($provs as $i => $p) {
                $localidades[] = ['provincia' => (string)$p, 'ciudad' => (string)($ciudades[$i] ?? '')];
            }

            if ($nombre === '') {
                flash_set('danger', 'El nombre es obligatorio.');
            } elseif (Zona::nombreEnUso($nombre, $id > 0 ? $id : null)) {
                flash_set('danger', 'Ya existe una zona con ese nombre.');
            } else {
                if ($id > 0) {
                    Zona::actualizar($id, $nombre);
                    flash_set('success', 'Zona actualizada.');
                } else {
                    $id = Zona::crear($nombre);
                    flash_set('success', 'Zona creada.');
                }
                Zona::setLocalidades($id, $localidades);
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo completar la operación.');
        error_log('zonas.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/zonas.php'));
    exit;
}

$zonas = Zona::todas();
$csrf  = Auth::tokenCSRF();

// Localidades por zona (para el editor) + datalist de provincias conocidas.
$locsPorZona = [];
foreach ($zonas as $z) {
    $locsPorZona[(int)$z['id']] = Zona::localidades((int)$z['id']);
}
$provinciasConocidas = Orden::provincias();

$acciones = '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalZona" onclick="zonaNueva()"><i class="bi bi-plus-lg me-1"></i>Nueva</button>';
panel_header('Zonas de reparto', $user, 'zonas', count($zonas) . ' zona(s)', $acciones);
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Una zona agrupa localidades de destino. En Salida a reparto se elige una zona y el escáner solo acepta ítems de esa zona. Dejá la ciudad vacía para incluir <strong>toda la provincia</strong>.</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Nombre</th><th>Localidades</th><th>Estado</th><th class="text-end">Acciones</th></tr>
      </thead>
      <tbody>
      <?php if ($zonas === []): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No hay zonas cargadas.</td></tr>
      <?php endif; ?>
      <?php foreach ($zonas as $z):
          $locs = $locsPorZona[(int)$z['id']];
          $resumen = array_map(static fn($l) => $l['ciudad'] !== '' ? $l['ciudad'] . '/' . $l['provincia'] : 'Toda ' . $l['provincia'], $locs);
      ?>
        <tr class="<?= $z['activo'] ? '' : 'table-secondary' ?>">
          <td class="fw-semibold"><?= h($z['nombre']) ?></td>
          <td class="text-muted small"><?= $resumen === [] ? '<span class="text-warning">Sin localidades</span>' : h(implode(' · ', $resumen)) ?></td>
          <td><?= $z['activo'] ? '<span class="badge b-activo">Activa</span>' : '<span class="badge b-inactivo">Inactiva</span>' ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Editar"
                    data-bs-toggle="modal" data-bs-target="#modalZona"
                    onclick='zonaEditar(<?= (int)$z['id'] ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="accion" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
              <button type="button" class="btn btn-sm btn-outline-<?= $z['activo'] ? 'danger' : 'success' ?> py-0 px-2"
                      title="<?= $z['activo'] ? 'Inactivar' : 'Reactivar' ?>"
                      onclick="tzConfirm(this.closest('form'), '¿<?= $z['activo'] ? 'Inactivar' : 'Reactivar' ?> la zona «<?= h(addslashes($z['nombre'])) ?>»?')">
                <i class="bi bi-toggle-<?= $z['activo'] ? 'on' : 'off' ?>"></i>
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
<div class="modal fade" id="modalZona" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="zona_id" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="zona_titulo">Nueva zona</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="zona_nombre">Nombre *</label>
          <input class="form-control" id="zona_nombre" name="nombre" maxlength="80" required placeholder="p. ej. SUR">
        </div>
        <label class="form-label">Localidades</label>
        <div class="text-muted small mb-2">Provincia obligatoria. Ciudad vacía = toda la provincia.</div>
        <div id="locRows"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="locAgregar()"><i class="bi bi-plus-lg me-1"></i>Agregar localidad</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<datalist id="provList">
  <?php foreach ($provinciasConocidas as $p): ?><option value="<?= h($p) ?>"></option><?php endforeach; ?>
</datalist>

<script>
const ZONAS_LOCS = <?= json_encode($locsPorZona, JSON_UNESCAPED_UNICODE) ?>;

function esc(s){ const d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }

function locRowHtml(prov, ciudad){
  return '<div class="d-flex gap-2 mb-2 loc-row">'
    + '<input class="form-control form-control-sm" name="provincia[]" list="provList" placeholder="Provincia" value="' + esc(prov) + '">'
    + '<input class="form-control form-control-sm" name="ciudad[]" placeholder="Ciudad (vacío = toda la prov.)" value="' + esc(ciudad) + '">'
    + '<button type="button" class="btn btn-sm btn-outline-danger px-2" onclick="this.closest(\'.loc-row\').remove()"><i class="bi bi-x-lg"></i></button>'
    + '</div>';
}
function locAgregar(prov, ciudad){
  document.getElementById('locRows').insertAdjacentHTML('beforeend', locRowHtml(prov||'', ciudad||''));
}
function setLocs(locs){
  const c = document.getElementById('locRows'); c.innerHTML = '';
  (locs && locs.length ? locs : [{provincia:'',ciudad:''}]).forEach(l => locAgregar(l.provincia, l.ciudad));
}
function zonaNueva(){
  document.getElementById('zona_titulo').textContent = 'Nueva zona';
  document.getElementById('zona_id').value = '';
  document.getElementById('zona_nombre').value = '';
  setLocs([]);
}
function zonaEditar(id){
  const tr = [...document.querySelectorAll('tbody tr')].find(r => r.querySelector('[onclick*="zonaEditar('+id+')"]'));
  document.getElementById('zona_titulo').textContent = 'Editar zona';
  document.getElementById('zona_id').value = id;
  document.getElementById('zona_nombre').value = tr ? tr.querySelector('td').textContent.trim() : '';
  setLocs(ZONAS_LOCS[id] || []);
}
</script>
<?php
panel_footer();
