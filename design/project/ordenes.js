'use strict';

/* ═══════════════════════════════════════════
   DATA
═══════════════════════════════════════════ */

var ORDERS = [
  {id:'ORD-0841',remito:'REM-1103',cliente:'García, Marta',    prov:'Córdoba',      loc:'Río Cuarto', tipo:'Online',m3:'8,4', items:3,valor:'45.000',warns:[],        err:null},
  {id:'ORD-0842',remito:'REM-1104',cliente:'López, Carlos',    prov:'Santa Fe',     loc:'Rosario',    tipo:'Local', m3:'12,0',items:4,valor:'62.000',warns:[],        err:null},
  {id:'ORD-0843',remito:'REM-1105',cliente:'Martínez, Juan',   prov:'Buenos Aires', loc:'CABA',       tipo:'Online',m3:'5,3', items:2,valor:'38.000',warns:['m3'],    err:'m3_mismatch'},
  {id:'ORD-0844',remito:'REM-1106',cliente:'Rodríguez, Ana',   prov:'Mendoza',      loc:'Mendoza',    tipo:'Local', m3:'15,8',items:5,valor:'78.000',warns:['remito'],err:null},
  {id:'ORD-0845',remito:'REM-1107',cliente:'Fernández, Sofía', prov:'Tucumán',      loc:'San Miguel', tipo:'Online',m3:'4,2', items:2,valor:'28.000',warns:[],        err:null},
  {id:'ORD-0846',remito:'REM-1108',cliente:'Sánchez, Luis',    prov:'Córdoba',      loc:'Villa María',tipo:'Online',m3:'9,1', items:3,valor:'52.000',warns:['m3'],    err:null},
  {id:'ORD-0847',remito:'REM-1109',cliente:'Torres, Patricia', prov:'Entre Ríos',   loc:'Paraná',     tipo:'Local', m3:'7,6', items:3,valor:'41.000',warns:[],        err:null},
];

var ORDER_ITEMS = {
  'ORD-0843': [
    {cod:'COL-2P-091',desc:'Colchón 2 plazas',dim:'1,90×1,40×0,22',qty:1,m3:'2,2',warn:false},
    {cod:'SOM-2P-047',desc:'Sommier 2 plazas', dim:'1,90×1,40×0,28',qty:1,m3:'3,6',warn:true},
  ]
};

var REPORTS_DATA = [
  {id:'ORD-0841',items:3,destino:'Río Cuarto, Córdoba',   m3:'8,4', tipo:'Online',fRem:'17/06',remito:'REM-1103',fIng:'19/06/2026',estado:'ETIQUETADA'},
  {id:'ORD-0842',items:4,destino:'Rosario, Santa Fe',     m3:'12,0',tipo:'Local', fRem:'17/06',remito:'REM-1104',fIng:'19/06/2026',estado:'CONFIRMADA'},
  {id:'ORD-0843',items:2,destino:'CABA, Buenos Aires',    m3:'5,3', tipo:'Online',fRem:'17/06',remito:'REM-1105',fIng:'19/06/2026',estado:'ETIQUETADA'},
  {id:'ORD-0844',items:5,destino:'Mendoza, Mendoza',      m3:'15,8',tipo:'Local', fRem:'17/06',remito:'REM-1106',fIng:'19/06/2026',estado:'CONFIRMADA'},
  {id:'ORD-0845',items:2,destino:'San Miguel, Tucumán',   m3:'4,2', tipo:'Online',fRem:'17/06',remito:'REM-1107',fIng:'19/06/2026',estado:'INGRESADO'},
  {id:'ORD-0846',items:3,destino:'Villa María, Córdoba',  m3:'9,1', tipo:'Online',fRem:'18/06',remito:'REM-1108',fIng:'19/06/2026',estado:'ETIQUETADA'},
  {id:'ORD-0847',items:3,destino:'Paraná, Entre Ríos',    m3:'7,6', tipo:'Local', fRem:'18/06',remito:'REM-1109',fIng:'19/06/2026',estado:'INGRESADO'},
  {id:'ORD-0832',items:2,destino:'Córdoba, Córdoba',      m3:'6,8', tipo:'Online',fRem:'12/06',remito:'REM-1092',fIng:'14/06/2026',estado:'EN_REPARTO'},
  {id:'ORD-0821',items:4,destino:'Rosario, Santa Fe',     m3:'18,2',tipo:'Local', fRem:'10/06',remito:'REM-1081',fIng:'12/06/2026',estado:'ENTREGADO'},
  {id:'ORD-0815',items:1,destino:'La Plata, Buenos Aires',m3:'3,4', tipo:'Online',fRem:'09/06',remito:'REM-1075',fIng:'11/06/2026',estado:'ENTREGADO'},
];

var LABEL_DATA = [
  {dest:'Río Cuarto · Córdoba',nombre:'García, Marta',  item:'1 de 3',desc:'Colchón 2 plazas',ord:'ORD-2026-0841'},
  {dest:'Río Cuarto · Córdoba',nombre:'García, Marta',  item:'2 de 3',desc:'Sommier 2 plazas', ord:'ORD-2026-0841'},
  {dest:'Río Cuarto · Córdoba',nombre:'García, Marta',  item:'3 de 3',desc:'Colchón 1 plaza',  ord:'ORD-2026-0841'},
  {dest:'Rosario · Santa Fe',  nombre:'López, Carlos',  item:'1 de 4',desc:'Colchón 2 plazas', ord:'ORD-2026-0842'},
  {dest:'Rosario · Santa Fe',  nombre:'López, Carlos',  item:'2 de 4',desc:'Colchón 2 plazas', ord:'ORD-2026-0842'},
  {dest:'CABA · Buenos Aires', nombre:'Martínez, Juan', item:'1 de 2',desc:'Colchón 2 plazas', ord:'ORD-2026-0843'},
  {dest:'CABA · Buenos Aires', nombre:'Martínez, Juan', item:'2 de 2',desc:'Sommier 2 plazas',  ord:'ORD-2026-0843'},
  {dest:'Mendoza · Mendoza',   nombre:'Rodríguez, Ana', item:'1 de 5',desc:'King Size',         ord:'ORD-2026-0844'},
];

var CAP_STATES = {
  empty: [],
  sheets: [
    {id:1,name:'Hoja_camion_001.jpg',size:'2,1 MB',state:'lista'},
    {id:2,name:'Hoja_camion_002.jpg',size:'1,8 MB',state:'procesando',prog:62},
    {id:3,name:'Hoja_camion_003.jpg',size:'0,3 MB',state:'error',msg:'No se pudo extraer texto — imagen borrosa o fuera de foco'},
    {id:4,name:'Hoja_camion_004.jpg',size:'2,0 MB',state:'lista'},
    {id:5,name:'Hoja_camion_005.jpg',size:'1,9 MB',state:'subida'},
  ],
  processing: [
    {id:1,name:'Hoja_camion_001.jpg',size:'2,1 MB',state:'lista'},
    {id:2,name:'Hoja_camion_002.jpg',size:'1,8 MB',state:'procesando',prog:84},
    {id:3,name:'Hoja_camion_003.jpg',size:'2,3 MB',state:'procesando',prog:33},
    {id:4,name:'Hoja_camion_004.jpg',size:'2,0 MB',state:'procesando',prog:8},
    {id:5,name:'Hoja_camion_005.jpg',size:'1,9 MB',state:'subida'},
    {id:6,name:'Hoja_camion_006.jpg',size:'2,2 MB',state:'subida'},
  ],
  ready: [
    {id:1,name:'Hoja_camion_001.jpg',size:'2,1 MB',state:'lista'},
    {id:2,name:'Hoja_camion_002.jpg',size:'1,8 MB',state:'lista'},
    {id:3,name:'Hoja_camion_003.jpg',size:'2,3 MB',state:'lista'},
    {id:4,name:'Hoja_camion_004.jpg',size:'2,0 MB',state:'lista'},
    {id:5,name:'Hoja_camion_005.jpg',size:'1,9 MB',state:'lista'},
    {id:6,name:'Hoja_camion_006.jpg',size:'2,2 MB',state:'lista'},
    {id:7,name:'Hoja_camion_007.jpg',size:'1,7 MB',state:'lista'},
    {id:8,name:'Hoja_camion_008.jpg',size:'2,1 MB',state:'lista'},
  ],
  error: [
    {id:1,name:'Hoja_camion_001.jpg',size:'2,1 MB',state:'lista'},
    {id:2,name:'Hoja_camion_002.jpg',size:'1,8 MB',state:'lista'},
    {id:3,name:'Hoja_camion_003.jpg',size:'0,3 MB',state:'error',msg:'Imagen muy oscura o fuera de foco — volvé a fotografiar con mejor iluminación'},
    {id:4,name:'Hoja_camion_004.jpg',size:'2,0 MB',state:'lista'},
    {id:5,name:'Hoja_camion_005.jpg',size:'1,9 MB',state:'lista'},
  ],
};

var PUB_CFG = {
  recibido: {
    kind:'timeline', stage:'blue', icon:'box-seam',
    title:'Recibimos tu producto',
    desc:'Tu producto ya está en nuestro depósito y pronto va a salir hacia tu domicilio.',
    update:'19/06/26 · 10:15',
    steps:[
      {state:'current',icon:'box-seam',title:'Recibimos tu producto',   desc:'Quedó registrado y en preparación para el envío.',date:'19/06/26 · 10:15',chip:true},
      {state:'pending',icon:'truck',      title:'En camino a tu domicilio',desc:'',date:'',chip:false},
      {state:'pending',icon:'house-check',title:'¡Entregado!',           desc:'',date:'',chip:false},
    ]
  },
  transito: {
    kind:'message', stage:'amber', icon:'truck',
    title:'En tránsito al depósito',
    desc:'Tu pedido fue registrado. El producto todavía está en camino a nuestro centro de distribución. Te avisamos cuando llegue.',
    update:'19/06/26 · 09:00',
  },
  reparto: {
    kind:'timeline', stage:'amber', icon:'truck',
    title:'En camino a tu domicilio',
    desc:'Tu producto salió del depósito y está en viaje hacia tu domicilio.',
    update:'19/06/26 · 14:30',
    steps:[
      {state:'done',   icon:'box-seam',   title:'Recibimos tu producto',   desc:'Tu producto llegó y quedó registrado en nuestro depósito.',date:'17/06/26',chip:false},
      {state:'current',icon:'truck',      title:'En camino a tu domicilio',desc:'Un transportista tiene tu pedido en ruta hacia tu domicilio.',date:'19/06/26 · 14:30',chip:true},
      {state:'pending',icon:'house-check',title:'¡Entregado!',             desc:'',date:'',chip:false},
    ]
  },
  entregado: {
    kind:'timeline', stage:'green', icon:'house-check', celebrate:true,
    title:'¡Entregado!',
    desc:'Tu producto fue entregado en tu domicilio. ¡Que lo disfrutes!',
    update:'19/06/26 · 16:45',
    steps:[
      {state:'done',icon:'box-seam',   title:'Recibimos tu producto',   desc:'Tu producto llegó y quedó registrado en nuestro depósito.',date:'17/06/26',chip:false},
      {state:'done',icon:'truck',      title:'En camino a tu domicilio',desc:'Salió del depósito hacia tu domicilio.',date:'19/06/26 · 09:30',chip:false},
      {state:'done',icon:'house-check',title:'¡Entregado!',             desc:'Llegó a destino. ¡Gracias por tu compra!',date:'19/06/26 · 16:45',chip:false},
    ]
  },
};

var PUB_STATE_LABELS = {
  recibido:'Recibido en depósito',
  transito:'En tránsito al depósito',
  reparto:'En camino (reparto)',
  entregado:'Entregado',
  noenc:'Orden no encontrada',
  input:'Pantalla de ingreso',
};

/* ═══════════════════════════════════════════
   NAVIGATION
═══════════════════════════════════════════ */

function go(page) {
  document.querySelectorAll('.as').forEach(function(s) { s.classList.remove('active'); });
  var s = document.getElementById('s-' + page);
  if (s) s.classList.add('active');
  document.querySelectorAll('.ni').forEach(function(a) { a.classList.remove('active'); });
  var lnk = document.querySelector('.ni[data-p="' + page + '"]');
  if (lnk) lnk.classList.add('active');
  closeSidebar();
}

function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebar-overlay').classList.add('show');
}

function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}

function toggleEl(id) {
  var el = document.getElementById(id);
  if (!el) return;
  var isHidden = el.style.display === 'none' || el.style.display === '';
  el.style.display = isHidden ? 'block' : 'none';
  var ic = document.getElementById(id + '-ic');
  if (ic) ic.style.transform = isHidden ? 'rotate(180deg)' : '';
}

function openModal(id) {
  new bootstrap.Modal(document.getElementById(id)).show();
}

/* ═══════════════════════════════════════════
   QR CODE GENERATOR
═══════════════════════════════════════════ */

function makeQR(containerId, M, px) {
  M = M || 21; px = px || 7;
  var cells = Array.from({length: M}, function() { return new Array(M).fill(null); });

  function finder(r0, c0) {
    for (var r = 0; r < 7; r++) {
      for (var c = 0; c < 7; c++) {
        cells[r0+r][c0+c] = Math.max(Math.abs(r-3), Math.abs(c-3)) !== 1;
      }
    }
    for (var i = 0; i <= 7; i++) {
      if (r0+7 < M && c0+i < M) cells[r0+7][c0+i] = false;
      if (c0+7 < M && r0+i < M) cells[r0+i][c0+7] = false;
    }
  }
  finder(0, 0); finder(0, M-7); finder(M-7, 0);

  for (var i = 8; i < M-8; i++) {
    if (cells[6][i] === null) cells[6][i] = i % 2 === 0;
    if (cells[i][6] === null) cells[i][6] = i % 2 === 0;
  }

  // Deterministic seed from containerId
  var seed = containerId.split('').reduce(function(a, c) { return a + c.charCodeAt(0); }, 0x4B1D);
  for (var r = 0; r < M; r++) {
    for (var c = 0; c < M; c++) {
      if (cells[r][c] === null) {
        seed = (seed * 1664525 + 1013904223) >>> 0;
        cells[r][c] = !!(seed & 0x8000);
      }
    }
  }

  var W = M * px;
  var path = '';
  for (var r2 = 0; r2 < M; r2++) {
    for (var c2 = 0; c2 < M; c2++) {
      if (cells[r2][c2]) {
        path += 'M' + (c2*px) + ',' + (r2*px) + 'h' + px + 'v' + px + 'h-' + px + 'z';
      }
    }
  }

  var el = document.getElementById(containerId);
  if (el) {
    el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + W +
      '" viewBox="0 0 ' + W + ' ' + W + '"><rect width="' + W + '" height="' + W +
      '" fill="white"/><path d="' + path + '" fill="black"/></svg>';
  }
}

/* ═══════════════════════════════════════════
   CAPTURE SCREEN
═══════════════════════════════════════════ */

var currentCapState = 'sheets';
var nextSheetId = 9;
var SHEET_THUMB = '<svg width="16" height="20" viewBox="0 0 16 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x=".75" y=".75" width="14.5" height="18.5" rx="1.5" stroke="currentColor" stroke-width="1.2"/><line x1="3.5" y1="6.5" x2="12.5" y2="6.5" stroke="currentColor" stroke-width=".9"/><line x1="3.5" y1="9.5" x2="12.5" y2="9.5" stroke="currentColor" stroke-width=".9"/><line x1="3.5" y1="12.5" x2="9" y2="12.5" stroke="currentColor" stroke-width=".9"/></svg>';

function setCap(state) {
  currentCapState = state;
  var emptyV  = document.getElementById('cap-empty-view');
  var sheetsV = document.getElementById('cap-sheets-view');
  var progBar = document.getElementById('cap-progress-bar');

  if (state === 'empty') {
    emptyV.style.display = 'block';
    sheetsV.style.display = 'none';
    return;
  }
  emptyV.style.display = 'none';
  sheetsV.style.display = 'block';
  progBar.style.display = state === 'processing' ? 'block' : 'none';

  var data = CAP_STATES[state] || [];
  renderSheets(data);

  var cnt  = document.getElementById('cap-count-lbl');
  var btn  = document.getElementById('procesar-btn');
  var hint = document.getElementById('procesar-hint');
  var lbl  = document.getElementById('procesar-lbl');

  if (cnt) cnt.textContent = data.length + ' hoja' + (data.length !== 1 ? 's' : '');

  hint.style.color = 'var(--muted)';

  if (state === 'processing') {
    var fill = document.getElementById('prog-fill');
    var pct  = document.getElementById('prog-pct');
    if (fill) fill.style.width = '38%';
    if (pct) pct.textContent = '38%';
    btn.disabled = true;
    btn.className = 'btn btn-secondary w-100 fw-bold';
    btn.style.padding = '.7rem'; btn.style.fontSize = '.95rem';
    if (lbl) lbl.textContent = 'Procesando…';
    hint.textContent = 'Aguardá a que todas las hojas finalicen el procesado OCR.';
  } else if (state === 'ready') {
    btn.disabled = false;
    btn.className = 'btn btn-success w-100 fw-bold';
    btn.style.padding = '.7rem'; btn.style.fontSize = '.95rem';
    if (lbl) lbl.textContent = 'Ir a revisión →';
    hint.textContent = '✓ 8 hojas procesadas — 12 órdenes encontradas.';
    hint.style.color = '#4ade80';
  } else if (state === 'error') {
    btn.disabled = true;
    btn.className = 'btn btn-secondary w-100 fw-bold';
    btn.style.padding = '.7rem'; btn.style.fontSize = '.95rem';
    if (lbl) lbl.textContent = 'Procesar hojas';
    hint.textContent = '1 hoja con error. Volvé a subirla antes de procesar.';
    hint.style.color = '#f87171';
  } else { // sheets
    btn.disabled = false;
    btn.className = 'btn btn-success w-100 fw-bold';
    btn.style.padding = '.7rem'; btn.style.fontSize = '.95rem';
    if (lbl) lbl.textContent = 'Procesar hojas';
    hint.textContent = '';
  }

  // Sync demo buttons visual state
  document.querySelectorAll('#s-captura .d-flex .btn').forEach(function(b) {
    var oc = b.getAttribute('onclick') || '';
    if (oc.indexOf('setCap(') === 0) {
      var match = oc.match(/'([^']+)'/);
      var isActive = match && match[1] === state;
      b.className = 'btn btn-sm ' + (isActive ? 'btn-primary' : 'btn-outline-secondary');
    }
  });
}

function renderSheets(data) {
  var list = document.getElementById('sheet-list');
  if (!list) return;
  list.innerHTML = data.map(function(s) {
    var statusHtml;
    if (s.state === 'procesando') {
      statusHtml = '<div style="display:flex;align-items:center;gap:5px;margin-top:3px">' +
        '<i class="bi bi-arrow-repeat" style="color:var(--yellow);font-size:12px;animation:spin 1s linear infinite"></i>' +
        '<span style="font-size:11px;color:var(--yellow)">Procesando…</span></div>' +
        '<div class="s-prog"><div class="s-prog-fill" style="width:' + (s.prog||0) + '%"></div></div>';
    } else if (s.state === 'error') {
      statusHtml = '<div style="display:flex;align-items:center;gap:5px;margin-top:3px">' +
        '<i class="bi bi-exclamation-circle-fill" style="color:var(--red);font-size:12px"></i>' +
        '<span style="font-size:11px;color:#f87171">Error</span>' +
        '<a style="font-size:11px;color:#60a5fa;cursor:pointer;margin-left:4px">Reintentar</a></div>' +
        (s.msg ? '<div style="font-size:10px;color:var(--muted);margin-top:2px;line-height:1.4">' + s.msg + '</div>' : '');
    } else if (s.state === 'lista') {
      statusHtml = '<div style="display:flex;align-items:center;gap:5px;margin-top:3px"><i class="bi bi-check-circle-fill" style="color:var(--green);font-size:12px"></i><span style="font-size:11px;color:#4ade80">Lista</span></div>';
    } else {
      statusHtml = '<div style="display:flex;align-items:center;gap:5px;margin-top:3px"><i class="bi bi-clock" style="color:var(--muted);font-size:12px"></i><span style="font-size:11px;color:var(--muted)">Subida</span></div>';
    }
    return '<div class="sheet-item">' +
      '<div class="sheet-thumb" style="color:var(--muted)">' + SHEET_THUMB + '</div>' +
      '<div class="sheet-info">' +
        '<div class="sheet-name">' + s.name + '</div>' +
        '<div class="sheet-meta">' + s.size + '</div>' +
        statusHtml +
      '</div>' +
      '<button onclick="this.closest(\'.sheet-item\').remove()" style="border:none;background:none;color:var(--muted);cursor:pointer;font-size:14px;padding:4px;flex-shrink:0;margin-left:auto" title="Quitar hoja"><i class="bi bi-x-lg"></i></button>' +
    '</div>';
  }).join('');
}

function addSheet() {
  var data = CAP_STATES[currentCapState];
  if (!data) return;
  var n = nextSheetId++;
  data.push({id:n, name:'Hoja_camion_0' + (n < 10 ? '0' : '') + n + '.jpg', size:(Math.floor(Math.random()*14+8)/10).toFixed(1).replace('.',',') + ' MB', state:'subida'});
  renderSheets(data);
  var cnt = document.getElementById('cap-count-lbl');
  if (cnt) cnt.textContent = data.length + ' hojas';
}

function setTipoVenta(v, btn) {
  document.querySelectorAll('#tipo-venta .btn').forEach(function(b) {
    b.classList.remove('btn-primary');
    b.classList.add('btn-outline-secondary');
  });
  if (btn) { btn.classList.add('btn-primary'); btn.classList.remove('btn-outline-secondary'); }
}

/* ═══════════════════════════════════════════
   REVISION SCREEN
═══════════════════════════════════════════ */

var expandedRows = {};
var currentRevState = 'alertas';

function setRev(state, btn) {
  currentRevState = state;
  if (btn) {
    document.querySelectorAll('#rev-toggle .btn').forEach(function(b) {
      b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary');
    });
    btn.classList.add('btn-primary'); btn.classList.remove('btn-outline-secondary');
  }
  renderRevision(state);
}

function renderRevision(state) {
  var tbody = document.getElementById('rev-tbody');
  var alertBar  = document.getElementById('rev-alert-bar');
  var alertChip = document.getElementById('rev-alert-chip');
  var cta = document.getElementById('rev-cta');
  if (!tbody) return;

  var rows = '';
  ORDERS.forEach(function(o) {
    var showWarn = state === 'alertas' && o.warns.length > 0;
    var showErr  = state === 'alertas' && !!o.err;
    var rowCls   = showErr ? ' class="row-err"' : '';

    // m³ cell
    var m3Cell = (showErr
      ? '<span class="cell-err"><i class="bi bi-exclamation-triangle-fill" style="font-size:10px;margin-right:3px"></i>' + o.m3 + '</span>'
      : (showWarn && o.warns.indexOf('m3') >= 0
        ? '<span class="cell-warn"><i class="bi bi-exclamation-circle" style="font-size:10px;margin-right:3px"></i>' + o.m3 + '</span>'
        : o.m3));

    // remito cell
    var remCell = (showWarn && o.warns.indexOf('remito') >= 0
      ? '<span class="cell-warn"><i class="bi bi-exclamation-circle" style="font-size:10px;margin-right:3px"></i>' + o.remito + '</span>'
      : '<span class="mono" style="font-size:12px">' + o.remito + '</span>');

    var tipoBadge = '<span class="badge b-' + o.tipo.toUpperCase() + '">' + o.tipo + '</span>';
    var isExpanded = !!expandedRows[o.id];
    var xbtn = '<button class="xbtn' + (isExpanded ? ' open' : '') + '" id="xb-' + o.id + '" onclick="toggleExpand(\'' + o.id + '\')" title="Ver ítems">' + (isExpanded ? '▾' : '▸') + '</button>';

    var editIcon = state !== 'confirmada'
      ? '<button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" onclick="go(\'orden-detalle\')">Ver</button>'
      : '<span style="font-size:11px;color:var(--muted)">—</span>';

    rows += '<tr' + rowCls + '>' +
      '<td style="padding:6px 8px">' + xbtn + '</td>' +
      '<td><span class="mono" style="font-size:12px">' + o.id + '</span></td>' +
      '<td>' + remCell + '</td>' +
      '<td>' + o.cliente + '</td>' +
      '<td style="font-size:13px">' + o.loc + '<span style="color:var(--muted);font-size:11px"> · ' + o.prov.split(' ')[0] + '</span></td>' +
      '<td>' + tipoBadge + '</td>' +
      '<td>' + m3Cell + '</td>' +
      '<td>' + o.items + '</td>' +
      '<td style="color:var(--muted)">$' + o.valor + '</td>' +
      '<td>' + editIcon + '</td>' +
    '</tr>';

    // Validation error row
    if (showErr) {
      rows += '<tr><td colspan="10" style="padding:3px 12px 7px 32px!important;background:rgba(239,68,68,.07)!important;border-top:none!important;border-color:rgba(239,68,68,.15)!important">' +
        '<i class="bi bi-exclamation-triangle-fill me-1" style="color:#f87171;font-size:11px"></i>' +
        '<span style="font-size:12px;color:#f87171">Error de validación: la suma de m³ de los ítems (5,8) no coincide con el total declarado (5,3). Corregí el campo antes de confirmar.</span>' +
      '</td></tr>';
    }

    // Expanded items sub-table
    if (isExpanded && ORDER_ITEMS[o.id]) {
      var itRows = ORDER_ITEMS[o.id].map(function(it) {
        var itM3 = it.warn
          ? '<span class="cell-warn"><i class="bi bi-exclamation-circle" style="font-size:10px;margin-right:3px"></i>' + it.m3 + '</span>'
          : it.m3;
        return '<tr>' +
          '<td class="sub-td mono" style="font-size:11px">' + it.cod + '</td>' +
          '<td class="sub-td">' + it.desc + '</td>' +
          '<td class="sub-td" style="color:var(--muted)">' + it.dim + '</td>' +
          '<td class="sub-td">' + it.qty + '</td>' +
          '<td class="sub-td">' + itM3 + '</td>' +
          '<td class="sub-td"><button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:10px" title="Quitar ítem"><i class="bi bi-trash3"></i></button></td>' +
        '</tr>';
      }).join('');
      rows += '<tr><td colspan="10" style="padding:0!important;border-top:none!important">' +
        '<table class="table mb-0" style="margin:0"><thead><tr>' +
        '<th class="sub-th">Código</th><th class="sub-th">Descripción</th>' +
        '<th class="sub-th">Dimensiones</th><th class="sub-th">Cant.</th>' +
        '<th class="sub-th">m³</th><th class="sub-th"></th>' +
        '</tr></thead><tbody>' + itRows + '</tbody></table>' +
        '<div style="padding:5px 12px 6px 30px;background:rgba(0,0,0,.2);border-top:1px solid var(--border)">' +
        '<button class="btn btn-sm" style="font-size:11px;background:rgba(59,130,246,.1);color:#60a5fa;border:1px solid rgba(59,130,246,.2)" onclick="openModal(\'m-add-item\')"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button>' +
        '</div>' +
      '</td></tr>';
    }
  });
  tbody.innerHTML = rows;

  // Alert bar
  if (state === 'alertas') {
    alertBar.innerHTML = '<div class="alert alert-warning py-2 mb-3 d-flex align-items-start gap-2" style="font-size:13px">' +
      '<i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="margin-top:2px"></i>' +
      '<div><strong>Revisá antes de confirmar:</strong> 3 campos de baja confianza OCR <span style="background:rgba(234,179,8,.18);padding:1px 5px;border-radius:3px">en amarillo</span> y ' +
      '<strong>1 error bloqueante</strong> <span style="background:rgba(239,68,68,.14);padding:1px 5px;border-radius:3px">en rojo</span>. Expandí la orden ORD-0843 para corregir.</div>' +
    '</div>';
    alertChip.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i><strong>3 alertas</strong>&nbsp;·&nbsp;<span style="color:#f87171">1 error bloqueante</span>';
    cta.innerHTML = '<span style="font-size:12px;color:var(--muted);align-self:center;margin-right:.5rem"><i class="bi bi-lock-fill me-1"></i>Errores pendientes</span>' +
      '<button class="btn btn-secondary fw-bold" disabled title="Hay errores bloqueantes"><i class="bi bi-check-lg me-2"></i>Confirmar carga</button>';
  } else if (state === 'ok') {
    alertBar.innerHTML = '<div class="alert alert-success py-2 mb-3 d-flex align-items-center gap-2" style="font-size:13px">' +
      '<i class="bi bi-check-circle-fill flex-shrink-0"></i>Sin alertas ni errores — la carga está lista para confirmar.</div>';
    alertChip.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#4ade80"></i><strong style="color:#4ade80">Sin alertas</strong>';
    cta.innerHTML = '<button class="btn btn-success fw-bold px-4" onclick="openModal(\'m-confirm-carga\')">' +
      '<i class="bi bi-check-lg me-2"></i>Confirmar carga</button>';
  } else {
    alertBar.innerHTML = '<div class="alert alert-info py-2 mb-3 d-flex align-items-center gap-2" style="font-size:13px">' +
      '<i class="bi bi-check-circle-fill flex-shrink-0"></i>Carga confirmada el 19/06/2026 · 12:05 — datos de sólo lectura.</div>';
    alertChip.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#4ade80"></i><strong style="color:#4ade80">Confirmada</strong>';
    cta.innerHTML = '<button class="btn btn-primary fw-bold" onclick="go(\'confirmacion\')">' +
      '<i class="bi bi-tag me-2"></i>Ver etiquetas →</button>';
  }
}

function toggleExpand(id) {
  expandedRows[id] = !expandedRows[id];
  renderRevision(currentRevState);
}

/* ═══════════════════════════════════════════
   REPORTS TABLE
═══════════════════════════════════════════ */

function renderReports() {
  var t = document.getElementById('t-reportes');
  if (!t) return;
  var badgeCls = {ETIQUETADA:'b-ETIQUETADA',CONFIRMADA:'b-CONFIRMADA',INGRESADO:'b-INGRESADO',EN_REPARTO:'b-EN_REPARTO',ENTREGADO:'b-ENTREGADO',REVISANDO:'b-REVISANDO'};
  var badge = function(e) { return '<span class="badge ' + (badgeCls[e]||'b-PENDIENTE') + '">' + e.replace('_',' ') + '</span>'; };
  t.innerHTML = '<thead><tr>' +
    '<th>Nº orden</th><th>Ítems</th><th>Destino</th><th>m³</th>' +
    '<th>Tipo</th><th>F. remito</th><th>Nº remito</th><th>F. ingreso</th>' +
    '<th>Estado</th><th></th>' +
  '</tr></thead><tbody>' +
  REPORTS_DATA.map(function(r) {
    return '<tr>' +
      '<td class="mono" style="font-size:12px">' + r.id + '</td>' +
      '<td>' + r.items + '</td>' +
      '<td style="font-size:13px">' + r.destino + '</td>' +
      '<td>' + r.m3 + '</td>' +
      '<td><span class="badge b-' + r.tipo.toUpperCase() + '">' + r.tipo + '</span></td>' +
      '<td style="color:var(--muted)">' + r.fRem + '</td>' +
      '<td class="mono" style="font-size:12px">' + r.remito + '</td>' +
      '<td style="color:var(--muted)">' + r.fIng + '</td>' +
      '<td>' + badge(r.estado) + '</td>' +
      '<td><button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" onclick="go(\'orden-detalle\')">Ver</button></td>' +
    '</tr>';
  }).join('') + '</tbody>';
}

/* ═══════════════════════════════════════════
   LABEL SHEET
═══════════════════════════════════════════ */

function renderLabelSheet() {
  var sheet = document.getElementById('label-sheet');
  if (!sheet) return;
  sheet.innerHTML = LABEL_DATA.map(function(l, i) {
    var qid = 'lqr' + i;
    return '<div class="label-sm">' +
      '<div class="lq"><div id="' + qid + '" style="width:82px;height:82px"></div></div>' +
      '<div class="lb">' +
        '<div class="ld">' + l.dest + '</div>' +
        '<div class="ln">' + l.nombre + '</div>' +
        '<div class="li">Ítem ' + l.item + ' · ' + l.desc + '</div>' +
        '<div class="lc">' + l.ord + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
  setTimeout(function() {
    LABEL_DATA.forEach(function(_, i) { makeQR('lqr' + i, 21, 3); });
  }, 20);
}

/* ═══════════════════════════════════════════
   PUBLIC TRACKING
═══════════════════════════════════════════ */

function showPublic(view) {
  document.getElementById('page-public').style.display = 'block';
  document.body.style.overflow = 'hidden';
  // Build state buttons
  var btns = document.getElementById('pub-state-btns');
  if (btns && !btns.children.length) {
    btns.innerHTML = Object.keys(PUB_STATE_LABELS).map(function(k) {
      return '<button id="psbtn-' + k + '" onclick="setPubState(\'' + k + '\')" style="' +
        'font-size:11px;padding:3px 10px;border-radius:4px;border:1px solid #d0cac2;' +
        'background:none;color:var(--tz-ink-soft);cursor:pointer;font-family:Inter,sans-serif">' +
        PUB_STATE_LABELS[k] + '</button>';
    }).join('');
  }
  setPubState(view || 'recibido');
}

function hidePublic() {
  document.getElementById('page-public').style.display = 'none';
  document.body.style.overflow = '';
}

function setPubState(state) {
  // Update buttons
  Object.keys(PUB_STATE_LABELS).forEach(function(k) {
    var b = document.getElementById('psbtn-' + k);
    if (!b) return;
    if (k === state) { b.style.background = 'var(--tz-blue)'; b.style.color = '#fff'; b.style.borderColor = 'var(--tz-blue)'; }
    else { b.style.background = 'none'; b.style.color = 'var(--tz-ink-soft)'; b.style.borderColor = '#d0cac2'; }
  });

  ['pv-input','pv-status','pv-notfound'].forEach(function(id) {
    var el = document.getElementById(id); if (el) el.style.display = 'none';
  });

  if (state === 'input') {
    document.getElementById('pv-input').style.display = 'flex';
  } else if (state === 'noenc') {
    document.getElementById('pv-notfound').style.display = 'flex';
  } else {
    document.getElementById('pv-status').style.display = 'flex';
    var cfg = PUB_CFG[state];
    if (cfg) renderPubCard(cfg);
  }
}

function renderPubCard(cfg) {
  var SC = ['blue','amber','green'];
  var update = cfg.update
    ? '<div class="tz-update"><i class="bi bi-clock-history"></i>Última actualización: ' + cfg.update + '</div>'
    : '';
  var html;

  if (cfg.kind === 'message') {
    html = '<div class="tz-card tz-card-ctr tz-st-' + cfg.stage + '">' +
      '<div class="tz-hero-ic"><i class="bi bi-' + cfg.icon + '"></i></div>' +
      '<h1 class="tz-hero-title">' + cfg.title + '</h1>' +
      '<p class="tz-hero-desc">' + cfg.desc + '</p>' + update +
    '</div>';
  } else {
    var celebrate = cfg.celebrate ? ' tz-card-celebrate' : '';
    var hero = '<div class="tz-st-' + cfg.stage + '">' +
      '<div class="tz-hero-ic"><i class="bi bi-' + cfg.icon + '"></i></div>' +
      '<h1 class="tz-hero-title">' + cfg.title + '</h1>' +
      '<p class="tz-hero-desc">' + cfg.desc + '</p>' + update + '</div>';

    var steps = (cfg.steps || []).map(function(s, i) {
      var icon = s.state === 'done' ? 'check-lg' : s.icon;
      var conn = i < cfg.steps.length-1 ? '<div class="tz-conn' + (s.state === 'done' ? ' filled' : '') + '"></div>' : '';
      var chip = s.chip ? '<span class="tz-chip">En curso</span>' : '';
      var date = s.date ? '<div class="tz-step-date"><i class="bi bi-calendar3"></i>' + s.date + '</div>' : '';
      return '<div class="tz-step ' + s.state + ' tz-st-' + (SC[i]||'blue') + '">' +
        '<div class="tz-rail"><div class="tz-node ' + s.state + '"><i class="bi bi-' + icon + '"></i></div>' + conn + '</div>' +
        '<div class="tz-body">' +
          '<div class="tz-step-titlerow"><span class="tz-step-title">' + s.title + '</span>' + chip + '</div>' +
          '<div class="tz-step-desc">' + s.desc + '</div>' + date +
        '</div>' +
      '</div>';
    }).join('');

    html = '<div class="tz-card' + celebrate + '">' + hero + '<div class="tz-div"></div><div class="tz-tl">' + steps + '</div></div>';
  }

  var c = document.getElementById('pub-card');
  if (c) c.innerHTML = html;
}

/* ═══════════════════════════════════════════
   GLOBAL CSS INJECTION (animation)
═══════════════════════════════════════════ */

(function() {
  var s = document.createElement('style');
  s.textContent = '@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
  document.head.appendChild(s);
})();

/* ═══════════════════════════════════════════
   INIT
═══════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function() {
  // Hash routing: Trazock.html sidebar links deep-link with #screen
  var initialPage = (location.hash || '#captura').replace('#', '');
  var validPages = ['captura','revision','confirmacion','etiqueta','reportes','orden-detalle'];
  go(validPages.indexOf(initialPage) >= 0 ? initialPage : 'captura');
  setCap('sheets');
  renderRevision('alertas');
  renderReports();
  setTimeout(function() {
    makeQR('qr-conf', 21, 7);
    makeQR('qr-det',  21, 6);
    renderLabelSheet();
  }, 100);
});
