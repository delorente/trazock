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

$user = Auth::requierePanel(); // admin o gestor
$csrf = Auth::tokenCSRF();
$categorias = Categoria::activas();

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
    <div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Tipo de venta</div>
      <div class="btn-group btn-group-sm" id="tipoVenta">
        <button type="button" class="btn btn-primary btn-sm" data-tv="online">Online</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-tv="local">Local</button>
      </div>
      <div class="text-muted" style="font-size:11px;margin-top:6px">Aplica a las órdenes de esta carga. Se puede ajustar por orden en la revisión.</div>
    </div>
  </div>

  <div class="card">
    <div style="padding:1.25rem">
      <label class="upload-zone d-block mb-0" for="fileInput" id="dropZone">
        <i class="bi bi-camera-fill uz-icon"></i>
        <div style="font-size:14px;font-weight:600;margin-bottom:.25rem">Tomar foto o subir hoja</div>
        <p class="text-muted" style="font-size:12px;margin:0">JPG · PNG &nbsp;·&nbsp; podés seleccionar varias</p>
      </label>
      <input type="file" id="fileInput" accept="image/*" capture="environment" multiple class="d-none">
      <p class="text-muted" style="font-size:12px;text-align:center;margin-top:.75rem;margin-bottom:0">Agregá las hojas (de a una o varias). Cuando estén todas, tocá <strong>Procesar</strong>.</p>
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

<script>
const TZ = {
  csrf: <?= json_encode($csrf) ?>,
  apiHoja: <?= json_encode(url('api/ordenes-hoja.php')) ?>,
  revision: <?= json_encode(url('admin/ordenes-revision.php')) ?>,
  cargaId: null,
  tipoVenta: 'online',
  totalOrdenes: 0,
};

// Toggle tipo de venta
document.querySelectorAll('#tipoVenta [data-tv]').forEach(b => b.addEventListener('click', () => {
  TZ.tipoVenta = b.getAttribute('data-tv');
  document.querySelectorAll('#tipoVenta [data-tv]').forEach(x => {
    x.classList.toggle('btn-primary', x === b);
    x.classList.toggle('btn-outline-secondary', x !== b);
  });
}));

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
  for (const f of files) {
    if (!f.type.startsWith('image/')) continue;
    const id = nextId++;
    const row = document.createElement('div');
    row.className = 'sheet-item';
    row.dataset.id = id;
    row.innerHTML = `<div class="sheet-thumb"><i class="bi bi-image"></i></div>
      <div class="sheet-info"><div class="sheet-name">${esc(f.name)}</div>
        <div class="sheet-meta">En espera</div></div>
      <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2" data-del="${id}" title="Quitar"><i class="bi bi-x-lg"></i></button>`;
    sheetList.appendChild(row);
    cola.push({ id, file: f, estado: 'pendiente', row });
  }
  actualizar();
}

// Quitar una hoja en espera (antes de procesar).
sheetList.addEventListener('click', e => {
  const btn = e.target.closest('[data-del]'); if (!btn || procesando) return;
  const id = +btn.dataset.del;
  const i = cola.findIndex(c => c.id === id);
  if (i >= 0 && cola[i].estado === 'pendiente') { cola[i].row.remove(); cola.splice(i, 1); actualizar(); }
});

async function procesar() {
  if (procesando) return;
  const pendientes = cola.filter(c => c.estado === 'pendiente');
  if (!pendientes.length) return;
  procesando = true;
  btnPrim.disabled = true;
  for (const item of pendientes) {
    await procesarHoja(item);
  }
  procesando = false;
  actualizar();
}

async function procesarHoja(item) {
  const meta  = item.row.querySelector('.sheet-meta');
  const thumb = item.row.querySelector('.sheet-thumb');
  const del   = item.row.querySelector('[data-del]'); if (del) del.remove();
  thumb.innerHTML = '<i class="bi bi-hourglass-split"></i>';
  meta.innerHTML = 'Procesando con OCR…<div class="s-prog"><div class="s-prog-fill" style="width:40%"></div></div>';

  const fd = new FormData();
  fd.append('csrf_token', TZ.csrf);
  fd.append('hoja', item.file);
  fd.append('tipo_venta', TZ.tipoVenta);
  fd.append('categoria_id', document.getElementById('cfgCategoria').value || '');
  if (TZ.cargaId) fd.append('carga_id', TZ.cargaId);

  try {
    const r = await fetch(TZ.apiHoja, { method:'POST', credentials:'same-origin', body: fd });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'Error al procesar la hoja.');
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
  if (btnPrim.dataset.modo === 'revisar') { location.href = TZ.revision + '?carga=' + TZ.cargaId; }
  else { procesar(); }
});

function actualizar() {
  const pendientes = cola.filter(c => c.estado === 'pendiente').length;
  document.getElementById('sheetCount').textContent = cola.length;
  document.getElementById('ordCount').textContent = TZ.totalOrdenes;

  if (procesando) { return; } // el loop maneja el botón

  if (pendientes > 0) {
    btnPrim.dataset.modo = 'procesar';
    btnPrim.disabled = false;
    btnPrim.innerHTML = `<i class="bi bi-gear-fill me-2"></i>Procesar ${pendientes} hoja${pendientes > 1 ? 's' : ''}`;
    hintEl.textContent = TZ.totalOrdenes > 0 ? 'Hay hojas nuevas sin procesar.' : 'Tocá Procesar para extraer las órdenes con OCR.';
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
