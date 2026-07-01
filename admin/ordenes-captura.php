<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-captura.php — "Nueva carga": el gestor sube/fotografía las hojas
// resumen del camión. Cada hoja se procesa con OCR (api/ordenes-hoja.php) y las
// órdenes extraídas se acumulan en una carga borrador. Luego → Revisión.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Categoria;
use Trazock\Models\Usuario;

$user = Auth::requierePanel(['admin', 'logistica']);
$csrf = Auth::tokenCSRF();
$categorias     = Categoria::activas();
$transportistas = Usuario::transportistasActivos();

panel_header('Nueva carga', $user, 'captura',
    'Fotografiá o subí las hojas resumen del camión — el sistema extrae las órdenes con OCR');
?>
<div style="max-width:460px">
  <div class="card p-3 mb-3">
    <div class="mb-3">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Categoría / línea de producto</div>
      <select class="form-select form-select-sm" id="cfgCategoria">
        <option value="">— Sin categoría —</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="text-muted" style="font-size:11px;margin-top:6px">Ej. "Colchones Simmons" o "Café La Morenita". Se aplica a los productos de esta carga (se fija al procesar la 1ª hoja).</div>
    </div>
  </div>

  <div class="card">
    <div style="padding:1.25rem">
      <label class="upload-zone d-block mb-0" for="fileInput" id="dropZone">
        <i class="bi bi-camera-fill uz-icon"></i>
        <div style="font-size:14px;font-weight:600;margin-bottom:.25rem">Tomar foto o subir hoja</div>
        <p class="text-muted" style="font-size:12px;margin:0">JPG · PNG · PDF &nbsp;·&nbsp; podés seleccionar varias</p>
      </label>
      <input type="file" id="fileInput" accept="image/*,application/pdf" multiple class="d-none">
      <p class="text-muted" style="font-size:12px;text-align:center;margin-top:.75rem;margin-bottom:0">Agregá las hojas (de a una o varias). Indicá <strong>transportista</strong> y <strong>fecha de carga</strong> de cada documento; el Nº de hoja de ruta lo extrae el OCR. Cuando estén todas, tocá <strong>Procesar</strong>.</p>
      <?php if ($transportistas === []): ?>
      <div class="alert alert-warning mt-2 mb-0" style="font-size:12px">No hay usuarios con rol <strong>Transportista</strong>. Creá al menos uno en <a href="<?= h(url('admin/usuarios.php')) ?>">Usuarios</a> antes de cargar.</div>
      <?php endif; ?>
    </div>

    <div id="sheetWrap" class="d-none" style="border-top:1px solid var(--border)">
      <div class="d-flex align-items-center justify-content-between" style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
        <span style="font-size:13px;font-weight:600"><span id="sheetCount">0</span> hoja(s) · <span id="ordCount">0</span> órdenes</span>
      </div>
      <div id="sheetList"></div>
      <div style="padding:.75rem;border-top:1px solid var(--border)">
        <button id="btnPrimario" class="btn btn-success w-100 fw-bold" style="padding:.7rem;font-size:.95rem" disabled>
          <i class="bi bi-gear-fill me-2"></i>Procesar
        </button>
        <div id="hint" class="text-muted" style="font-size:11px;text-align:center;margin-top:6px">Agregá al menos una hoja.</div>
      </div>
    </div>
  </div>
</div>

<div id="loadingOverlay" class="load-ov d-none">
  <div class="load-box">
    <div class="spinner-border text-success" role="status" style="width:2.4rem;height:2.4rem"></div>
    <div id="loadMsg" class="load-msg">Abriendo previsualización…</div>
    <div class="load-sub">No cierres esta pantalla.</div>
  </div>
</div>

<style>
  .load-ov{ position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center; z-index:1090; backdrop-filter:blur(2px); }
  .load-box{ background:var(--card,#1a1d24); border:1px solid var(--border,#2a2f3a); border-radius:14px; padding:1.6rem 2.2rem; text-align:center; box-shadow:0 12px 44px rgba(0,0,0,.5); }
  .load-msg{ margin-top:.9rem; font-size:14px; font-weight:600; color:var(--text,#e8eaed); }
  .load-sub{ margin-top:.25rem; font-size:11px; color:var(--muted,#9aa0aa); }
</style>

<script>
const TZ = {
  csrf: <?= json_encode($csrf) ?>,
  apiHoja: <?= json_encode(url('api/ordenes-hoja.php')) ?>,
  revision: <?= json_encode(url('admin/ordenes-revision.php')) ?>,
  cargaId: null,
  totalOrdenes: 0,
  transportistas: <?= json_encode(array_map(static fn($t) => ['id' => (int)$t['id'], 'nombre' => (string)$t['nombre_completo']], $transportistas), JSON_UNESCAPED_UNICODE) ?>,
  hoy: <?= json_encode(date('Y-m-d')) ?>,
};

// <option>s de transportista reutilizables para cada fila de la cola.
const TRANSP_OPTS = '<option value="">— Transportista —</option>' +
  TZ.transportistas.map(t => `<option value="${t.id}">${esc(t.nombre)}</option>`).join('');


const fileInput = document.getElementById('fileInput');
const dropZone  = document.getElementById('dropZone');
const sheetWrap = document.getElementById('sheetWrap');
const sheetList = document.getElementById('sheetList');
const btnPrim   = document.getElementById('btnPrimario');
const hintEl    = document.getElementById('hint');

['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag'); }));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('drag'); }));
dropZone.addEventListener('drop', ev => { if (ev.dataTransfer.files.length) encolar(ev.dataTransfer.files); });
fileInput.addEventListener('change', () => { if (fileInput.files.length) encolar(fileInput.files); fileInput.value = ''; });

// Cola de hojas: {id, file, estado:'pendiente'|'ok'|'error', row}. Cargar NO dispara
// el OCR; las hojas quedan en espera hasta que el usuario toca "Procesar".
let cola = [];
let nextId = 1;
let procesando = false;

function encolar(files) {
  sheetWrap.classList.remove('d-none');
  // Prefill (transportista/fecha) desde la última fila: un viaje suele compartirlos.
  const ult = cola.length ? cola[cola.length - 1] : null;
  const prevTransp = ult ? (ult.row.querySelector('[data-transp]')?.value || '') : '';
  const prevFecha  = ult ? (ult.row.querySelector('[data-fecha]')?.value || '') : '';
  for (const f of files) {
    const esPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name);
    if (!f.type.startsWith('image/') && !esPdf) continue;
    const id = nextId++;
    const row = document.createElement('div');
    row.className = 'sheet-item';
    row.dataset.id = id;
    row.innerHTML = `<div class="sheet-thumb"><i class="bi ${esPdf ? 'bi-file-earmark-pdf' : 'bi-image'}"></i></div>
      <div class="sheet-info" style="min-width:0;flex:1">
        <div class="sheet-name">${esc(f.name)}</div>
        <div class="sheet-meta">En espera</div>
        <div class="d-flex gap-2 mt-1 doc-inputs">
          <select class="form-select form-select-sm" data-transp style="max-width:170px">${TRANSP_OPTS}</select>
          <input type="date" class="form-control form-control-sm" data-fecha max="${TZ.hoy}" style="max-width:150px">
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2" data-del="${id}" title="Quitar"><i class="bi bi-x-lg"></i></button>`;
    sheetList.appendChild(row);
    if (prevTransp) row.querySelector('[data-transp]').value = prevTransp;
    if (prevFecha)  row.querySelector('[data-fecha]').value = prevFecha;
    cola.push({ id, file: f, estado: 'pendiente', row });
  }
  actualizar();
}

// Revalidar el botón Procesar al editar transportista/fecha de cada documento.
sheetList.addEventListener('input', () => { if (!procesando) actualizar(); });
sheetList.addEventListener('change', () => { if (!procesando) actualizar(); });

// Quitar una hoja en espera (antes de procesar).
sheetList.addEventListener('click', e => {
  const btn = e.target.closest('[data-del]'); if (!btn || procesando) return;
  const id = +btn.dataset.del;
  const i = cola.findIndex(c => c.id === id);
  if (i >= 0 && cola[i].estado === 'pendiente') { cola[i].row.remove(); cola.splice(i, 1); actualizar(); }
});

// Overlay de carga reutilizable (procesamiento y apertura de la previsualización).
const overlay = document.getElementById('loadingOverlay');
const loadMsg = document.getElementById('loadMsg');
function showLoading(msg){ if (msg) loadMsg.textContent = msg; overlay.classList.remove('d-none'); }
function hideLoading(){ overlay.classList.add('d-none'); }

async function procesar() {
  if (procesando) return;
  const pendientes = cola.filter(c => c.estado === 'pendiente');
  if (!pendientes.length) return;
  procesando = true;
  btnPrim.disabled = true;
  let i = 0;
  for (const item of pendientes) {
    i++;
    showLoading(pendientes.length > 1
      ? `Procesando hoja ${i} de ${pendientes.length} con OCR…`
      : 'Procesando la hoja con OCR…');
    await procesarHoja(item);
  }
  hideLoading();
  procesando = false;
  actualizar();
}

async function procesarHoja(item) {
  const meta  = item.row.querySelector('.sheet-meta');
  const thumb = item.row.querySelector('.sheet-thumb');
  const del   = item.row.querySelector('[data-del]'); if (del) del.remove();
  const selT  = item.row.querySelector('[data-transp]');
  const inpF  = item.row.querySelector('[data-fecha]');
  const transportistaId = selT ? selT.value : '';
  const fechaCarga      = inpF ? inpF.value : '';
  if (selT) selT.disabled = true;
  if (inpF) inpF.disabled = true;
  thumb.innerHTML = '<i class="bi bi-hourglass-split"></i>';
  meta.innerHTML = 'Procesando con OCR…<div class="s-prog"><div class="s-prog-fill" style="width:40%"></div></div>';

  const fd = new FormData();
  fd.append('csrf_token', TZ.csrf);
  fd.append('hoja', item.file);
  fd.append('categoria_id', document.getElementById('cfgCategoria').value || '');
  fd.append('transportista_id', transportistaId);
  fd.append('fecha_carga', fechaCarga);
  if (TZ.cargaId) fd.append('carga_id', TZ.cargaId);

  try {
    const r = await fetch(TZ.apiHoja, { method:'POST', credentials:'same-origin', body: fd });
    // El servidor puede cortar con una página HTML (503/504/502) si el OCR del PDF
    // supera su límite de tiempo. Parsear defensivo para mostrar algo útil.
    const raw = await r.text();
    let d = {};
    try { d = JSON.parse(raw); } catch (_) {}
    if (!r.ok || !d.ok) {
      let msg = d.error;
      if (!msg) {
        msg = (r.status === 503 || r.status === 504 || r.status === 502)
          ? 'El servidor cortó la conexión procesando este archivo (timeout). Si es un PDF grande/multipágina, probá subir las hojas como imágenes (JPG/PNG) o un PDF de menos páginas.'
          : 'Error al procesar la hoja (HTTP ' + r.status + ').';
      }
      throw new Error(msg);
    }
    TZ.cargaId = d.carga_id;
    TZ.totalOrdenes = d.total;
    // La categoría se fijó al crear la carga; ya no se puede cambiar.
    document.getElementById('cfgCategoria').disabled = true;
    item.estado = 'ok';
    thumb.innerHTML = '<i class="bi bi-check-lg" style="color:var(--green)"></i>';
    meta.innerHTML = `<span style="color:var(--green)">${d.ordenes_hoja} órdenes</span>`;
  } catch (e) {
    item.estado = 'error';
    thumb.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:var(--red)"></i>';
    meta.innerHTML = `<span style="color:var(--red)">${esc(e.message)}</span>`;
  }
}

// Botón verde: "Procesar N" mientras haya pendientes; luego "Ir a la revisión".
btnPrim.addEventListener('click', () => {
  if (btnPrim.dataset.modo === 'revisar') {
    showLoading('Abriendo previsualización…');
    location.href = TZ.revision + '?carga=' + TZ.cargaId;
  } else { procesar(); }
});

// Una fila está lista para procesar si tiene transportista y fecha (≤ hoy).
function filaValida(item) {
  const t = item.row.querySelector('[data-transp]')?.value || '';
  const f = item.row.querySelector('[data-fecha]')?.value || '';
  return t !== '' && f !== '' && f <= TZ.hoy;
}

function actualizar() {
  const pend = cola.filter(c => c.estado === 'pendiente');
  const pendientes = pend.length;
  const incompletas = pend.filter(c => !filaValida(c)).length;
  document.getElementById('sheetCount').textContent = cola.length;
  document.getElementById('ordCount').textContent = TZ.totalOrdenes;

  if (procesando) { return; } // el loop maneja el botón

  if (pendientes > 0) {
    btnPrim.dataset.modo = 'procesar';
    btnPrim.disabled = incompletas > 0;
    btnPrim.innerHTML = `<i class="bi bi-gear-fill me-2"></i>Procesar ${pendientes} hoja${pendientes > 1 ? 's' : ''}`;
    hintEl.textContent = incompletas > 0
      ? `Completá transportista y fecha de carga en ${incompletas} documento${incompletas > 1 ? 's' : ''}.`
      : (TZ.totalOrdenes > 0 ? 'Hay hojas nuevas sin procesar.' : 'Tocá Procesar para extraer las órdenes con OCR.');
  } else if (TZ.totalOrdenes > 0) {
    btnPrim.dataset.modo = 'revisar';
    btnPrim.disabled = false;
    btnPrim.innerHTML = `<i class="bi bi-table me-2"></i>Ir a la revisión (${TZ.totalOrdenes} órdenes)`;
    hintEl.textContent = 'Podés agregar más hojas antes de revisar.';
  } else {
    btnPrim.dataset.modo = 'procesar';
    btnPrim.disabled = true;
    btnPrim.innerHTML = '<i class="bi bi-gear-fill me-2"></i>Procesar';
    hintEl.textContent = 'Agregá al menos una hoja.';
  }
}

function esc(s){ const d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }
</script>
<?php
panel_footer();
