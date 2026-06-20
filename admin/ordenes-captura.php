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

$user = Auth::requierePanel(); // admin o gestor
$csrf = Auth::tokenCSRF();

panel_header('Nueva carga', $user, 'captura',
    'Fotografiá o subí las hojas resumen del camión — el sistema extrae las órdenes con OCR');
?>
<div style="max-width:460px">
  <div class="card p-3 mb-3">
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
      <p class="text-muted" style="font-size:12px;text-align:center;margin-top:.75rem;margin-bottom:0">Un camión lleva ≈ 8–10 páginas. Subilas y se procesan una por una.</p>
    </div>

    <div id="sheetWrap" class="d-none" style="border-top:1px solid var(--border)">
      <div class="d-flex align-items-center justify-content-between" style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
        <span style="font-size:13px;font-weight:600"><span id="sheetCount">0</span> hoja(s) · <span id="ordCount">0</span> órdenes</span>
      </div>
      <div id="sheetList"></div>
      <div style="padding:.75rem;border-top:1px solid var(--border)">
        <a id="btnRevisar" class="btn btn-success w-100 fw-bold disabled" style="padding:.7rem;font-size:.95rem" href="#">
          <i class="bi bi-table me-2"></i>Ir a la revisión
        </a>
        <div id="hint" class="text-muted" style="font-size:11px;text-align:center;margin-top:6px">Subí al menos una hoja para continuar.</div>
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

['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag'); }));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('drag'); }));
dropZone.addEventListener('drop', ev => { if (ev.dataTransfer.files.length) procesarVarias(ev.dataTransfer.files); });
fileInput.addEventListener('change', () => { if (fileInput.files.length) procesarVarias(fileInput.files); fileInput.value = ''; });

let sheetN = 0;
async function procesarVarias(files) {
  sheetWrap.classList.remove('d-none');
  for (const f of files) { await procesarHoja(f); }
}

function actualizarContadores() {
  document.getElementById('sheetCount').textContent = sheetN;
  document.getElementById('ordCount').textContent = TZ.totalOrdenes;
  const btn = document.getElementById('btnRevisar');
  if (TZ.cargaId && TZ.totalOrdenes > 0) {
    btn.classList.remove('disabled');
    btn.href = TZ.revision + '?carga=' + TZ.cargaId;
    document.getElementById('hint').textContent = '';
  }
}

async function procesarHoja(file) {
  sheetN++;
  const row = document.createElement('div');
  row.className = 'sheet-item';
  row.innerHTML = `<div class="sheet-thumb"><i class="bi bi-hourglass-split"></i></div>
    <div class="sheet-info"><div class="sheet-name">${esc(file.name)}</div>
      <div class="sheet-meta">Procesando con OCR…</div>
      <div class="s-prog"><div class="s-prog-fill" style="width:40%"></div></div></div>`;
  sheetList.appendChild(row);

  const fd = new FormData();
  fd.append('csrf_token', TZ.csrf);
  fd.append('hoja', file);
  fd.append('tipo_venta', TZ.tipoVenta);
  if (TZ.cargaId) fd.append('carga_id', TZ.cargaId);

  try {
    const r = await fetch(TZ.apiHoja, { method:'POST', credentials:'same-origin', body: fd });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'Error al procesar la hoja.');
    TZ.cargaId = d.carga_id;
    TZ.totalOrdenes = d.total;
    row.querySelector('.sheet-thumb').innerHTML = '<i class="bi bi-check-lg" style="color:var(--green)"></i>';
    row.querySelector('.sheet-meta').innerHTML = `<span style="color:var(--green)">${d.ordenes_hoja} órdenes</span>`;
    row.querySelector('.s-prog').remove();
  } catch (e) {
    row.querySelector('.sheet-thumb').innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:var(--red)"></i>';
    row.querySelector('.sheet-meta').innerHTML = `<span style="color:var(--red)">${esc(e.message)}</span>`;
    const pf = row.querySelector('.s-prog'); if (pf) pf.remove();
    sheetN--; // no contar la hoja fallida
  }
  actualizarContadores();
}

function esc(s){ const d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }
</script>
<?php
panel_footer();
