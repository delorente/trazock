<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-revision.php — planilla de revisión del borrador de una carga.
// Grilla editable de órdenes + ítems, con validación; "Confirmar carga" materializa
// (api/ordenes-confirmar.php → ProcesadorCarga). admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Carga;

$user = Auth::requierePanel();
$csrf = Auth::tokenCSRF();

$cargaId = (int)($_GET['carga'] ?? 0);
$carga   = $cargaId > 0 ? Carga::find($cargaId) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-captura.php')) . '"><i class="bi bi-arrow-left me-1"></i>Nueva carga</a>';

if ($carga === null) {
    panel_header('Revisión OCR', $user, 'captura', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la carga.</div>';
    panel_footer();
    exit;
}

// Carga ya confirmada → la pantalla canónica es la de confirmación (resumen +
// generación de etiquetas).
if ($carga['estado'] === 'confirmada') {
    header('Location: ' . url('admin/ordenes-confirmacion.php') . '?carga=' . $cargaId);
    exit;
}

$datos   = json_decode((string)($carga['datos_extraidos'] ?? ''), true);
$ordenes = (is_array($datos) && isset($datos['ordenes']) && is_array($datos['ordenes'])) ? $datos['ordenes'] : [];

panel_header('Revisión OCR', $user, 'captura',
    'Verificá los datos extraídos · campos en amarillo = baja confianza · rojos = error bloqueante', $volver);
?>
<div class="sumbar">
  <div><div class="sumbar-n" id="sb-ord">0</div><div class="sumbar-l">Órdenes</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" id="sb-items">0</div><div class="sumbar-l">Ítems</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" id="sb-m3">0</div><div class="sumbar-l">m³ total</div></div>
  <div class="sumbar-div"></div>
  <div><div class="sumbar-n" id="sb-valor">$0</div><div class="sumbar-l">Valor declarado</div></div>
  <div id="sb-alert" style="margin-left:auto;font-size:12px;color:#fbbf24"></div>
</div>

<div class="card mb-3" style="overflow:hidden">
  <div style="overflow-x:auto">
    <table class="table mb-0" id="tabla">
      <thead><tr>
        <th style="width:26px"></th>
        <th>Nº orden</th><th>Nº remito</th><th>Cliente</th><th>Destino</th>
        <th style="width:96px">Tipo</th><th style="width:70px">m³</th><th style="width:54px">Ítems</th><th style="width:110px">Valor</th><th style="width:34px"></th>
      </tr></thead>
      <tbody id="tbody"></tbody>
    </table>
  </div>
</div>

<div id="cta" class="d-flex justify-content-end gap-2"></div>

<script>
const TZ = {
  csrf: <?= json_encode($csrf) ?>,
  cargaId: <?= $cargaId ?>,
  apiConfirmar: <?= json_encode(url('api/ordenes-confirmar.php')) ?>,
  captura: <?= json_encode(url('admin/ordenes-captura.php')) ?>,
  confirmacion: <?= json_encode(url('admin/ordenes-confirmacion.php')) ?>,
};
let ORD = <?= json_encode($ordenes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const expandido = new Set();

function num(v){ const n = parseFloat(String(v).replace(',','.')); return isFinite(n) ? n : 0; }
function m3DeOrden(o){ return (o.items||[]).reduce((s,it)=> s + num(it.m3), 0); }
function itemsDeOrden(o){ return (o.items||[]).reduce((s,it)=> s + Math.max(1, parseInt(it.cantidad)||1), 0); }
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('es-AR'); }

function inp(i, f, val, cls){
  return `<input class="cell-edit ${cls||''}" data-i="${i}" data-f="${f}" value="${esc(val)}">`;
}

function render(){
  const tb = document.getElementById('tbody');
  tb.innerHTML = '';
  ORD.forEach((o, i) => {
    const errOrden = !String(o.nro_orden||'').trim();
    const warnDest = !String(o.dest_provincia||'').trim();
    const sinItems = (o.items||[]).length === 0;

    const tr = document.createElement('tr');
    if (errOrden) tr.className = 'row-err';
    const tv = o.tipo_venta || '';
    tr.innerHTML = `
      <td><button class="xbtn ${expandido.has(i)?'open':''}" data-exp="${i}"><i class="bi bi-chevron-${expandido.has(i)?'down':'right'}"></i></button></td>
      <td>${inp(i,'nro_orden',o.nro_orden||'', errOrden?'cell-err':'')}</td>
      <td>${inp(i,'nro_remito',o.nro_remito||'')}</td>
      <td>${inp(i,'cliente',o.cliente||'')}</td>
      <td><div class="d-flex flex-column gap-1">${inp(i,'dest_localidad',o.dest_localidad||'')}${inp(i,'dest_provincia',o.dest_provincia||'', warnDest?'cell-warn':'')}</div></td>
      <td><select class="cell-edit" data-i="${i}" data-f="tipo_venta">
          <option value="" ${tv===''?'selected':''}>—</option>
          <option value="online" ${tv==='online'?'selected':''}>Online</option>
          <option value="local" ${tv==='local'?'selected':''}>Local</option></select></td>
      <td class="mono ${sinItems?'':''}" style="font-size:12px">${fmt(m3DeOrden(o))}</td>
      <td style="text-align:center">${itemsDeOrden(o)}</td>
      <td>${inp(i,'valor_declarado',o.valor_declarado??'')}</td>
      <td><button class="xbtn" data-del="${i}" title="Quitar orden"><i class="bi bi-trash"></i></button></td>`;
    tb.appendChild(tr);

    if (expandido.has(i)) {
      const sub = document.createElement('tr');
      const items = (o.items||[]).map((it,j) => `
        <tr>
          <td class="sub-td"><input class="cell-edit" data-i="${i}" data-j="${j}" data-f="codigo" value="${esc(it.codigo||'')}"></td>
          <td class="sub-td"><input class="cell-edit" data-i="${i}" data-j="${j}" data-f="dimensiones" value="${esc(it.dimensiones||'')}"></td>
          <td class="sub-td" style="width:70px"><input class="cell-edit" data-i="${i}" data-j="${j}" data-f="cantidad" value="${esc(it.cantidad??1)}"></td>
          <td class="sub-td" style="width:80px"><input class="cell-edit" data-i="${i}" data-j="${j}" data-f="m3" value="${esc(it.m3??'')}"></td>
          <td class="sub-td" style="width:30px"><button class="xbtn" data-deli="${i}:${j}"><i class="bi bi-x-lg"></i></button></td>
        </tr>`).join('');
      sub.innerHTML = `<td></td><td colspan="9" style="padding:0!important">
        <table class="table mb-0" style="background:transparent">
          <thead><tr><th class="sub-th">Código</th><th class="sub-th">Dimensiones</th><th class="sub-th">Cant.</th><th class="sub-th">m³</th><th class="sub-th"></th></tr></thead>
          <tbody>${items || '<tr><td class="sub-td text-muted" colspan="5">Sin ítems.</td></tr>'}</tbody>
        </table>
        <div class="sub-td"><button class="btn btn-sm btn-outline-secondary py-0 px-2" data-addi="${i}" style="font-size:11px"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button></div></td>`;
      tb.appendChild(sub);
    }
  });
  resumen();
}

function resumen(){
  let ord=ORD.length, items=0, m3=0, valor=0, errs=0;
  ORD.forEach(o => {
    items += itemsDeOrden(o); m3 += m3DeOrden(o); valor += num(o.valor_declarado);
    if (!String(o.nro_orden||'').trim()) errs++;
  });
  document.getElementById('sb-ord').textContent = ord;
  document.getElementById('sb-items').textContent = items;
  document.getElementById('sb-m3').textContent = fmt(m3);
  document.getElementById('sb-valor').textContent = '$' + fmt(valor);
  document.getElementById('sb-alert').innerHTML = errs
    ? `<i class="bi bi-exclamation-triangle-fill me-1"></i>${errs} orden(es) sin Nº de orden`
    : `<span style="color:#4ade80"><i class="bi bi-check-circle me-1"></i>Sin errores bloqueantes</span>`;

  const cta = document.getElementById('cta');
  cta.innerHTML = `<button class="btn btn-success fw-bold" id="btnConfirmar" ${errs?'disabled':''}>
    <i class="bi bi-check-circle me-2"></i>Confirmar carga (${ord} órdenes)</button>`;
  document.getElementById('btnConfirmar').addEventListener('click', confirmar);
}

// Edición in-place
document.getElementById('tabla').addEventListener('input', e => {
  const el = e.target; if (!el.dataset.f) return;
  const i = +el.dataset.i, f = el.dataset.f;
  if (el.dataset.j !== undefined) { ORD[i].items[+el.dataset.j][f] = el.value; }
  else { ORD[i][f] = el.value; }
  // recomputar resumen y marcas sin perder el foco (no re-render completo)
  resumen();
});
document.getElementById('tabla').addEventListener('click', e => {
  const t = e.target.closest('[data-exp],[data-del],[data-deli],[data-addi]'); if (!t) return;
  if (t.dataset.exp !== undefined){ const i=+t.dataset.exp; expandido.has(i)?expandido.delete(i):expandido.add(i); render(); }
  else if (t.dataset.del !== undefined){ if(confirm('¿Quitar esta orden de la carga?')){ ORD.splice(+t.dataset.del,1); render(); } }
  else if (t.dataset.deli !== undefined){ const [i,j]=t.dataset.deli.split(':').map(Number); ORD[i].items.splice(j,1); render(); }
  else if (t.dataset.addi !== undefined){ const i=+t.dataset.addi; (ORD[i].items=ORD[i].items||[]).push({codigo:'',dimensiones:'',cantidad:1,m3:null}); render(); }
});

async function confirmar(){
  const btn = document.getElementById('btnConfirmar');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Confirmando…';
  try {
    const r = await fetch(TZ.apiConfirmar, {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ csrf_token: TZ.csrf, carga_id: TZ.cargaId, datos: JSON.stringify({ordenes:ORD}) })
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'No se pudo confirmar.');
    location.href = TZ.confirmacion + '?carga=' + TZ.cargaId;
  } catch(e){
    alert(e.message);
    btn.disabled = false; resumen();
  }
}

render();
</script>
<?php
panel_footer();
