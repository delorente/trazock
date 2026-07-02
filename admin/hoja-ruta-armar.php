<?php
declare(strict_types=1);

// =============================================================================
// admin/hoja-ruta-armar.php — editor de una hoja de ruta de reparto (admin+log).
// Encabezado (conductor/vehículo del padrón o texto libre), órdenes del sistema
// (agregar/quitar buscando por Nº) y líneas manuales (fuera del sistema).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Acompanante;
use Trazock\Models\HojaRuta;
use Trazock\Models\Orden;
use Trazock\Models\Vehiculo;

$user = Auth::requierePanel(['admin', 'logistica']);

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$hoja = $id > 0 ? HojaRuta::find($id) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/hojas-ruta.php')) . '"><i class="bi bi-arrow-left me-1"></i>Hojas de ruta</a>';

if ($hoja === null) {
    panel_header('Hoja de ruta', $user, 'hojas-ruta', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la hoja de ruta.</div>';
    panel_footer();
    exit;
}

$editable = $hoja['estado'] === 'abierta';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/hoja-ruta-armar.php') . '?id=' . $id);
        exit;
    }
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'emitir') {
        HojaRuta::emitir($id);
        flash_set('success', 'Hoja de ruta emitida. Ya aparece en el scan para salir a reparto.');
    } elseif ($accion === 'reabrir') {
        HojaRuta::reabrir($id);
        flash_set('success', 'Hoja de ruta reabierta para edición.');
    } elseif (!$editable) {
        flash_set('warning', 'La hoja está emitida. Reabrila para editarla.');
    } elseif ($accion === 'guardar') {
        // Conductor: del padrón (id) o texto libre.
        $cid  = (int)($_POST['conductor_empleado_id'] ?? 0);
        $cond = trim((string)($_POST['conductor_texto'] ?? ''));
        if ($cid > 0) { $e = Acompanante::findActivo($cid); if ($e) { $cond = (string)$e['nombre']; } else { $cid = 0; } }
        // Vehículo: del padrón (id) o texto libre.
        $vid = (int)($_POST['vehiculo_id'] ?? 0);
        $veh = trim((string)($_POST['vehiculo_texto'] ?? ''));
        if ($vid > 0) { $v = Vehiculo::findActivo($vid); if ($v) { $veh = (string)$v['nombre']; } else { $vid = 0; } }
        HojaRuta::guardarEncabezado($id, [
            'fecha'                 => trim((string)($_POST['fecha'] ?? '')),
            'conductor_empleado_id' => $cid > 0 ? $cid : null,
            'conductor'             => $cond,
            'vehiculo_id'           => $vid > 0 ? $vid : null,
            'vehiculo'              => $veh,
            'ayudantes'             => trim((string)($_POST['ayudantes'] ?? '')),
            'destino'               => trim((string)($_POST['destino'] ?? '')),
            'observaciones'         => trim((string)($_POST['observaciones'] ?? '')),
        ]);
        flash_set('success', 'Encabezado guardado.');
    } elseif ($accion === 'add_orden') {
        $nro = trim((string)($_POST['nro_orden'] ?? ''));
        $o = $nro !== '' ? Orden::findByNroOrden($nro) : null;
        if ($o === null) {
            flash_set('danger', 'No se encontró la orden «' . $nro . '».');
        } else {
            $agregada = HojaRuta::agregarOrden($id, (int)$o['id']);
            flash_set($agregada ? 'success' : 'warning', $agregada ? 'Orden agregada.' : 'La orden ya estaba en la hoja.');
        }
    } elseif ($accion === 'del_orden') {
        HojaRuta::quitarOrden($id, (int)($_POST['orden_id'] ?? 0));
        flash_set('success', 'Orden quitada.');
    } elseif ($accion === 'add_manual') {
        HojaRuta::agregarManual($id, [
            'cliente_origen'  => trim((string)($_POST['cliente_origen'] ?? '')),
            'nro_orden'       => trim((string)($_POST['m_nro_orden'] ?? '')),
            'cliente_destino' => trim((string)($_POST['cliente_destino'] ?? '')),
            'localidad'       => trim((string)($_POST['localidad'] ?? '')),
            'bultos'          => trim((string)($_POST['bultos'] ?? '')),
            'm3'              => str_replace(',', '.', trim((string)($_POST['m3'] ?? ''))),
            'telefono'        => trim((string)($_POST['telefono'] ?? '')),
            'observacion'     => trim((string)($_POST['observacion'] ?? '')),
        ]);
        flash_set('success', 'Línea manual agregada.');
    } elseif ($accion === 'del_manual') {
        HojaRuta::quitarManual((int)($_POST['manual_id'] ?? 0));
        flash_set('success', 'Línea manual quitada.');
    }
    header('Location: ' . url('admin/hoja-ruta-armar.php') . '?id=' . $id);
    exit;
}

$ordenes  = HojaRuta::ordenesDe($id);
$manuales = HojaRuta::manualesDe($id);
$empleados = Acompanante::porRol('cd');       // conductores de reparto (corta distancia)
$ayudantesPad = Acompanante::porRol('ayudante');
$vehiculos = Vehiculo::activos();
$csrf = Auth::tokenCSRF();

// Totales.
$totBultos = 0; $totM3 = 0.0;
foreach ($ordenes as $o)  { $totBultos += (int)$o['bultos']; $totM3 += (float)$o['m3_total']; }
foreach ($manuales as $m) { $totBultos += (int)($m['bultos'] ?? 0); $totM3 += (float)($m['m3'] ?? 0); }

$acciones = $volver
    . '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/hoja-ruta.php') . '?hoja=' . $id) . '" target="_blank"><i class="bi bi-printer me-1"></i>Imprimir</a>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ruta-planificar.php') . '?id=' . $id) . '"><i class="bi bi-signpost-2 me-1"></i>Planificar recorrido</a>';
if ($editable) {
    $acciones .= '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="emitir"><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Emitir</button></form>';
} else {
    $acciones .= '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="accion" value="reabrir"><button class="btn btn-sm btn-warning"><i class="bi bi-unlock me-1"></i>Reabrir</button></form>';
}

panel_header('Hoja ' . (string)$hoja['numero'], $user, 'hojas-ruta',
    ($editable ? 'Abierta' : 'Emitida') . ' · ' . count($ordenes) . ' órden(es) · ' . $totBultos . ' bulto(s)', $acciones);
flash_render();
?>
<?php if (!$editable): ?><div class="alert alert-info py-2"><i class="bi bi-lock me-1"></i>Hoja <strong>emitida</strong> (solo lectura). Reabrila para editar.</div><?php endif; ?>

<!-- Encabezado -->
<form method="post" class="card p-3 mb-3">
  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="accion" value="guardar">
  <div class="row g-2">
    <div class="col-md-3"><label class="form-label">Fecha</label><input type="date" class="form-control form-control-sm" name="fecha" value="<?= h((string)($hoja['fecha'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></div>
    <div class="col-md-5"><label class="form-label">Destino / zona</label><input class="form-control form-control-sm" name="destino" maxlength="150" value="<?= h((string)($hoja['destino'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></div>
    <div class="col-md-4 d-flex align-items-end justify-content-end">
      <?php if ($editable): ?><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Guardar encabezado</button><?php endif; ?>
    </div>
    <div class="col-md-4">
      <label class="form-label">Unidad</label>
      <select class="form-select form-select-sm mb-1" name="vehiculo_id" onchange="if(this.value)document.getElementById('vehiculo_texto').value=''" <?= $editable ? '' : 'disabled' ?>>
        <option value="">— del padrón —</option>
        <?php foreach ($vehiculos as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= (int)($hoja['vehiculo_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm" id="vehiculo_texto" name="vehiculo_texto" placeholder="…o escribir a mano" value="<?= h((int)($hoja['vehiculo_id'] ?? 0) === 0 ? (string)($hoja['vehiculo'] ?? '') : '') ?>" <?= $editable ? '' : 'disabled' ?>>
    </div>
    <div class="col-md-4">
      <label class="form-label">Chofer</label>
      <select class="form-select form-select-sm mb-1" name="conductor_empleado_id" onchange="if(this.value)document.getElementById('conductor_texto').value=''" <?= $editable ? '' : 'disabled' ?>>
        <option value="">— del padrón —</option>
        <?php foreach ($empleados as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= (int)($hoja['conductor_empleado_id'] ?? 0) === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm" id="conductor_texto" name="conductor_texto" placeholder="…o escribir a mano" value="<?= h((int)($hoja['conductor_empleado_id'] ?? 0) === 0 ? (string)($hoja['conductor'] ?? '') : '') ?>" <?= $editable ? '' : 'disabled' ?>>
    </div>
    <div class="col-md-4">
      <label class="form-label">Ayudante(s)</label>
      <?php if ($editable && $ayudantesPad !== []): ?>
      <select class="form-select form-select-sm mb-1" id="hr_ay_sel">
        <option value="">— agregar del padrón —</option>
        <?php foreach ($ayudantesPad as $ay): ?>
          <option value="<?= h((string)$ay['nombre']) ?>"><?= h($ay['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if ($editable): ?>
      <div class="input-group input-group-sm mb-1">
        <input class="form-control form-control-sm" id="hr_ay_txt" placeholder="…o escribir a mano">
        <button type="button" class="btn btn-outline-secondary" onclick="hrAyAddText()"><i class="bi bi-plus-lg"></i></button>
      </div>
      <?php endif; ?>
      <div id="hr_ay_chips" class="d-flex flex-wrap gap-1"></div>
      <input type="hidden" id="hr_ayudantes" name="ayudantes" value="<?= h((string)($hoja['ayudantes'] ?? '')) ?>">
    </div>
    <div class="col-12"><label class="form-label">Observaciones</label><input class="form-control form-control-sm" name="observaciones" maxlength="600" value="<?= h((string)($hoja['observaciones'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></div>
  </div>
</form>

<!-- Órdenes del sistema -->
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center" style="padding:.6rem 1rem">
    <span><i class="bi bi-list-ol me-1"></i>Órdenes (<?= count($ordenes) ?>)</span>
    <?php if ($editable): ?>
    <form method="post" class="d-flex gap-1">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="add_orden">
      <input class="form-control form-control-sm mono" name="nro_orden" placeholder="Nº de orden" style="width:180px" required>
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i></button>
    </form>
    <?php endif; ?>
  </div>
  <div style="overflow-x:auto">
    <table class="table table-hover mb-0" style="font-size:13px">
      <thead class="table-light"><tr><th>Categoría</th><th>Nº orden</th><th>Cliente</th><th>Localidad</th><th class="text-center">Btos</th><th class="text-end">m³</th><th>Teléfono</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if ($ordenes === []): ?>
        <tr><td colspan="<?= $editable ? 8 : 7 ?>" class="text-center text-muted py-3">Sin órdenes. Agregá por Nº arriba.</td></tr>
      <?php else: foreach ($ordenes as $o):
        $cli = trim((string)($o['cliente_apellido'] ?? '')) !== '' ? (string)$o['cliente_apellido'] : (string)($o['cliente'] ?? '');
        $loc = trim((string)($o['dest_localidad'] ?? '') . (($o['dest_localidad'] ?? '') && ($o['dest_provincia'] ?? '') ? ' · ' : '') . (string)($o['dest_provincia'] ?? ''));
      ?>
        <tr>
          <td><?= h((string)($o['categoria'] ?? '') !== '' ? (string)$o['categoria'] : '—') ?></td>
          <td class="mono"><?= h((string)$o['nro_orden']) ?></td>
          <td><?= h($cli !== '' ? $cli : '—') ?></td>
          <td><?= h($loc !== '' ? $loc : '—') ?></td>
          <td class="text-center"><?= (int)$o['bultos'] ?></td>
          <td class="text-end"><?= number_format((float)$o['m3_total'], 2, ',', '.') ?></td>
          <td class="text-muted"><?= h((string)($o['telefonos'] ?? '') !== '' ? (string)$o['telefonos'] : '—') ?></td>
          <?php if ($editable): ?>
          <td class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="accion" value="del_orden"><input type="hidden" name="orden_id" value="<?= (int)$o['id'] ?>">
              <button class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="bi bi-x-lg"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Líneas manuales -->
<div class="card mb-3">
  <div class="card-header" style="padding:.6rem 1rem"><i class="bi bi-pencil-square me-1"></i>Artículos fuera del sistema (<?= count($manuales) ?>)</div>
  <div style="overflow-x:auto">
    <table class="table table-hover mb-0" style="font-size:13px">
      <thead class="table-light"><tr><th>Cliente origen</th><th>Nº orden</th><th>Cliente destino</th><th>Localidad</th><th class="text-center">Btos</th><th class="text-end">m³</th><th>Teléfono</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($manuales as $m): ?>
        <tr>
          <td><?= h((string)($m['cliente_origen'] ?? '') !== '' ? (string)$m['cliente_origen'] : '—') ?></td>
          <td class="mono"><?= h((string)($m['nro_orden'] ?? '') !== '' ? (string)$m['nro_orden'] : '—') ?></td>
          <td><?= h((string)($m['cliente_destino'] ?? '') !== '' ? (string)$m['cliente_destino'] : '—') ?></td>
          <td><?= h((string)($m['localidad'] ?? '') !== '' ? (string)$m['localidad'] : '—') ?></td>
          <td class="text-center"><?= $m['bultos'] !== null ? (int)$m['bultos'] : '—' ?></td>
          <td class="text-end"><?= $m['m3'] !== null ? number_format((float)$m['m3'], 2, ',', '.') : '—' ?></td>
          <td class="text-muted"><?= h((string)($m['telefono'] ?? '') !== '' ? (string)$m['telefono'] : '—') ?></td>
          <?php if ($editable): ?>
          <td class="text-end">
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="accion" value="del_manual"><input type="hidden" name="manual_id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="bi bi-x-lg"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($editable): ?>
  <form method="post" class="p-2" style="border-top:1px solid var(--border)">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="add_manual">
    <div class="row g-1 align-items-end">
      <div class="col"><input class="form-control form-control-sm" name="cliente_origen" placeholder="Cliente origen"></div>
      <div class="col"><input class="form-control form-control-sm" name="m_nro_orden" placeholder="Nº orden"></div>
      <div class="col"><input class="form-control form-control-sm" name="cliente_destino" placeholder="Cliente destino"></div>
      <div class="col"><input class="form-control form-control-sm" name="localidad" placeholder="Localidad"></div>
      <div style="width:70px"><input class="form-control form-control-sm" name="bultos" placeholder="Btos" inputmode="numeric"></div>
      <div style="width:80px"><input class="form-control form-control-sm" name="m3" placeholder="m³" inputmode="decimal"></div>
      <div class="col"><input class="form-control form-control-sm" name="telefono" placeholder="Teléfono"></div>
      <div style="width:44px"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-lg"></i></button></div>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="text-muted small">Total: <strong><?= $totBultos ?></strong> bulto(s) · <strong><?= number_format($totM3, 2, ',', '.') ?></strong> m³</div>
<style>
  .hr-ay-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(96,165,250,.15);border:1px solid rgba(96,165,250,.5);color:var(--text,#e8eaed);border-radius:999px;padding:1px 4px 1px 10px;font-size:12px;line-height:1.6}
  .hr-ay-chip button{background:none;border:none;color:inherit;cursor:pointer;font-size:14px;line-height:1;padding:0 4px;opacity:.7}
  .hr-ay-chip button:hover{opacity:1}
</style>
<script>
// Ayudantes: el desplegable (o el texto libre) SUMA nombres; se muestran como
// chips y se guardan en el campo oculto "ayudantes" (coma-separados). Hay que
// Guardar el encabezado para que queden registrados.
var HR_AY_EDITABLE = <?= $editable ? 'true' : 'false' ?>;
function hrAyList() {
  var h = document.getElementById('hr_ayudantes');
  return h ? h.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
}
function hrAySet(arr) {
  var seen = [];
  arr.forEach(function (n) { if (n && seen.indexOf(n) === -1) seen.push(n); }); // sin duplicar
  var h = document.getElementById('hr_ayudantes');
  if (h) h.value = seen.join(', ');
  hrAyRender();
}
function hrAyAdd(nombre) { if (nombre) { var l = hrAyList(); l.push(nombre); hrAySet(l); } }
function hrAyAddText() {
  var t = document.getElementById('hr_ay_txt');
  if (t && t.value.trim()) { hrAyAdd(t.value.trim()); t.value = ''; t.focus(); }
}
function hrAyRender() {
  var cont = document.getElementById('hr_ay_chips');
  if (!cont) return;
  cont.innerHTML = '';
  hrAyList().forEach(function (n) {
    var chip = document.createElement('span');
    chip.className = 'hr-ay-chip';
    chip.appendChild(document.createTextNode(n));
    if (HR_AY_EDITABLE) {
      var x = document.createElement('button');
      x.type = 'button'; x.title = 'Quitar'; x.textContent = '×';
      x.onclick = function () { hrAySet(hrAyList().filter(function (m) { return m !== n; })); };
      chip.appendChild(x);
    }
    cont.appendChild(chip);
  });
}
document.addEventListener('DOMContentLoaded', function () {
  hrAyRender();
  var sel = document.getElementById('hr_ay_sel');
  if (sel) sel.addEventListener('change', function () { if (this.value) { hrAyAdd(this.value); this.value = ''; } });
  var txt = document.getElementById('hr_ay_txt');
  if (txt) txt.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); hrAyAddText(); } });
});
</script>
<?php
panel_footer();
