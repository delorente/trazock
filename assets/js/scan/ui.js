// =============================================================================
// ui.js — controlador de la app de escaneo (Fase 6: offline-tolerant).
// Vistas, login/logout, configuración de lote, escáner, y envío vía cola en IDB
// con sincronización en segundo plano (TZSync) y cache de catálogos (TZDB).
// =============================================================================

(function () {
    'use strict';

    const BOOT = window.TZ_BOOT || {};
    const API = BOOT.apiBase;

    const TIPO_LABEL = {
        INGRESO: 'Ingreso', SALIDA_REPARTO: 'Salida a reparto', ENTREGA: 'Entrega',
        REINGRESO: 'Reingreso', SALIDA_DEVOLUCION: 'Devolución a proveedor', BAJA: 'Baja'
    };
    const ESTADO_COLA_LABEL = {
        pendiente_sync: 'Pendiente', sincronizando: 'Sincronizando…', sincronizado: 'Sincronizado',
        error_auth: 'Sesión expirada', error_datos: 'Error de datos'
    };

    const estado = {
        usuario: BOOT.usuario || null,
        catalogos: BOOT.catalogos || null,
        csrf: BOOT.csrf || null,
        lote: null,
        beep: true   // sonido de éxito activado por defecto
    };

    const INACTIVIDAD_MS = 30000; // pausa la cámara tras 30 s sin escanear
    let audioCtx = null;
    let inactividadTimer = null;

    const $ = (id) => document.getElementById(id);
    const nowISO = () => new Date().toISOString();

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    function vibrar(p) { if (navigator.vibrate) { try { navigator.vibrate(p); } catch (e) {} } }

    // AudioContext persistente; debe inicializarse en un gesto del usuario (botón).
    function initAudio() {
        try {
            if (!audioCtx) { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
            if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume(); }
        } catch (e) {}
    }
    function beep(freq) {
        if (!estado.beep || !audioCtx) return;
        try {
            const o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.frequency.value = freq || 1400; o.connect(g); g.connect(audioCtx.destination);
            g.gain.value = 0.12; o.start(); o.stop(audioCtx.currentTime + 0.07);
        } catch (e) {}
    }

    // Flash de borde en la cámara (verde ok / amarillo duplicado).
    function flashCam(clase) {
        const w = document.querySelector('.tz-cam-wrap');
        if (!w) return;
        w.classList.remove('flash-ok', 'flash-dup');
        void w.offsetWidth; // reinicia la animación
        w.classList.add(clase);
        setTimeout(() => w.classList.remove(clase), 500);
    }
    function mostrarVista(id) {
        document.querySelectorAll('.tz-view').forEach(v => v.classList.add('d-none'));
        $(id).classList.remove('d-none');
    }
    function overlay(on, msg) { $('tzOverlay').classList.toggle('d-none', !on); if (msg) $('tzOverlayMsg').textContent = msg; }

    // Toast breve que se cierra solo (no requiere aceptar).
    function showToast(msg, clase, ms) {
        const t = document.createElement('div');
        t.className = 'tz-toast ' + (clase || '');
        t.innerHTML = '<i class="bi bi-check-circle-fill" style="color:var(--green)"></i><span></span>';
        t.querySelector('span').textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, ms || 2500);
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    // ----- QR de Trazock: parseo y validación de zona -------------------------
    // Payload: nro_orden|sec/total|provincia|ciudad|apellido  (ver lib/EtiquetaQr).
    // La clave del ítem en la BD es codigo = nro_orden-NN (NN = sec, 2 dígitos).
    function parseQR(raw) {
        const parts = String(raw == null ? '' : raw).trim().split('|');
        if (parts.length < 2) return null;
        const nro = (parts[0] || '').trim();
        const m = /^(\d+)\/(\d+)$/.exec((parts[1] || '').trim());
        if (!nro || !m) return null;
        const sec = parseInt(m[1], 10), total = parseInt(m[2], 10);
        return {
            nro_orden: nro, secuencia: sec, total: total,
            codigo: nro + '-' + String(sec).padStart(2, '0'),
            provincia: (parts[2] || '').trim(),
            ciudad: (parts[3] || '').trim(),
            apellido: (parts[4] || '').trim()
        };
    }
    // Normaliza para comparar destinos: minúsculas, sin acentos, espacios colapsados.
    var RE_DIACRITICOS = new RegExp('[\\u0300-\\u036f]', 'g'); // marcas combinantes (acentos)
    function normLoc(s) {
        return String(s == null ? '' : s).trim().toLowerCase()
            .normalize('NFD').replace(RE_DIACRITICOS, '').replace(/\s+/g, ' ');
    }
    // ¿(provincia, ciudad) pertenece a la zona? Localidad con ciudad vacía = toda la prov.
    function enZona(prov, ciu, localidades) {
        if (!localidades || !localidades.length) return false;
        const P = normLoc(prov), C = normLoc(ciu);
        return localidades.some(function (l) {
            if (normLoc(l.provincia) !== P) return false;
            const lc = normLoc(l.ciudad);
            return lc === '' || lc === C;
        });
    }
    // Órdenes del lote con ítems sin escanear (según sec/total del QR).
    function ordenesIncompletas(l) {
        const m = {};
        (l.items || []).forEach(function (it) {
            if (!it.nro_orden || !it.total) return; // ítems legacy (sin QR) no se controlan
            if (!m[it.nro_orden]) m[it.nro_orden] = { total: it.total, secs: {} };
            if (it.total > m[it.nro_orden].total) m[it.nro_orden].total = it.total;
            if (it.secuencia) m[it.nro_orden].secs[it.secuencia] = true;
        });
        const inc = [];
        Object.keys(m).forEach(function (no) {
            const escaneados = Object.keys(m[no].secs).length;
            if (escaneados < m[no].total) inc.push({ nro_orden: no, escaneados: escaneados, total: m[no].total });
        });
        return inc;
    }

    // ----- ARRANQUE -----------------------------------------------------------
    async function arrancar() {
        TZSync.init({ apiBase: API, onChange: refrescarBadge, onAuthError: sesionExpirada });

        // Catálogos: si vinieron del server (online), persistir; si no, leer de IDB.
        try {
            if (estado.catalogos) { await TZDB.guardarCatalogos(estado.catalogos); }
            else { const c = await TZDB.leerCatalogos(); if (c) estado.catalogos = c; }
        } catch (e) { /* IDB no disponible: seguimos con lo que haya en memoria */ }

        // Retomar lote en curso si quedó uno abierto.
        try {
            const la = await TZDB.leerLoteActual();
            if (estado.usuario && la) estado.lote = la;
        } catch (e) {}

        if (estado.usuario) {
            estado.lote ? irScan(true) : irSelector();
            TZSync.start();
            await refrescarBadge();
            if (navigator.onLine) refrescarCatalogos();
        } else {
            mostrarVista('view-login');
        }

        actualizarConn();
        window.addEventListener('online', function () { actualizarConn(); refrescarCatalogos(); TZSync.syncAhora(); });
        window.addEventListener('offline', actualizarConn);
    }

    function actualizarConn() {
        const online = navigator.onLine;
        const cls = online ? 'online' : 'offline';
        const ci = $('connInfo');
        if (ci) { ci.className = 'conn ' + cls; ci.innerHTML = '<span class="dot ' + cls + '"></span>' + (online ? 'Online' : 'Sin conexión'); }
        const sc = $('scanConn');
        if (sc) { sc.className = 'conn ' + cls; sc.innerHTML = '<span class="dot ' + cls + '"></span>'; }
    }

    async function refrescarCatalogos() {
        if (!estado.usuario || !navigator.onLine) return;
        try {
            const r = await fetch(API + '/catalogos.php', { credentials: 'same-origin' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.ok) { estado.catalogos = d.catalogos; await TZDB.guardarCatalogos(d.catalogos); mostrarCatalogInfo(); }
        } catch (e) {}
    }

    function mostrarCatalogInfo() {
        const el = $('catalogInfo'); if (!el || !estado.catalogos) return;
        const lu = estado.catalogos.last_updated;
        if (!lu) { el.textContent = ''; return; }
        const horas = Math.floor((Date.now() - new Date(lu).getTime()) / 3600000);
        let txt = 'Catálogos: actualizados hace ' + (horas <= 0 ? 'menos de 1' : horas) + ' h';
        el.textContent = (horas >= 24 ? '⚠ ' : '') + txt;
        el.classList.toggle('text-warning', horas >= 24);
    }

    // ----- LOGIN --------------------------------------------------------------
    $('loginForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const err = $('loginError'); err.classList.add('d-none');
        const usuario = $('loginUsuario').value.trim();
        const password = $('loginPassword').value;
        if (!usuario || !password) return;
        $('loginBtn').disabled = true;
        fetch(API + '/login.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario, password })
        }).then(r => r.json().then(d => ({ ok: r.ok, d })))
          .then(async ({ ok, d }) => {
              if (ok && d.ok) {
                  estado.usuario = d.usuario; estado.catalogos = d.catalogos; estado.csrf = d.csrf;
                  $('loginPassword').value = '';
                  try { await TZDB.guardarCatalogos(d.catalogos); } catch (e) {}
                  await TZSync.trasRelogin();   // reactiva lotes en error_auth
                  irSelector();
                  TZSync.start();
                  await refrescarBadge();
                  mostrarCatalogInfo();
              } else {
                  err.textContent = d.error || 'No se pudo iniciar sesión.'; err.classList.remove('d-none');
              }
          }).catch(() => { err.textContent = 'Error de red.'; err.classList.remove('d-none'); })
          .finally(() => { $('loginBtn').disabled = false; });
    });

    // Mostrar/ocultar contraseña (botón con data-toggle-pass="idInput").
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-toggle-pass]');
        if (!btn) return;
        const inp = $(btn.getAttribute('data-toggle-pass'));
        if (!inp) return;
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        const ic = btn.querySelector('i');
        if (ic) ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    $('btnLogout').addEventListener('click', function () {
        fetch(API + '/logout.php', { method: 'POST', credentials: 'same-origin' })
            .finally(() => { estado.usuario = null; estado.lote = null; mostrarVista('view-login'); });
    });

    // ----- SELECTOR -----------------------------------------------------------
    function irSelector() {
        $('selUsuario').textContent = estado.usuario.nombre + ' (' + estado.usuario.rol + ')';
        mostrarVista('view-selector');
        mostrarCatalogInfo();
    }
    $('btnNuevoLote').addEventListener('click', irConfig);

    async function refrescarBadge() {
        let n = 0;
        try { n = await TZDB.contarPendientes(); } catch (e) {}
        const b = $('colaBadge');
        if (b) { b.textContent = n; b.classList.toggle('d-none', n === 0); }
        const r = $('colaResumen');
        if (r) r.textContent = n === 0 ? 'Sin pendientes' : (n + ' lote(s) pendiente(s)');
    }

    // ----- CONFIG -------------------------------------------------------------
    function irConfig() {
        if (!estado.catalogos) { alert('No hay catálogos disponibles. Conectate al menos una vez.'); return; }
        const sel = $('cfgTipo'); sel.innerHTML = '';
        (estado.catalogos.tipos_permitidos || []).forEach(t => {
            const o = document.createElement('option'); o.value = t; o.textContent = TIPO_LABEL[t] || t; sel.appendChild(o);
        });
        $('configError').classList.add('d-none'); $('cfgObs').value = '';
        renderCampos(sel.value);
        mostrarVista('view-config');
    }
    $('cfgTipo').addEventListener('change', function () { renderCampos(this.value); });
    $('btnConfigVolver').addEventListener('click', irSelector);

    function optionList(arr, valKey, textKey) {
        return (arr || []).map(x => '<option value="' + esc(x[valKey]) + '" data-editable="' +
            (x.editable_libre ? 1 : 0) + '">' + esc(x[textKey]) + '</option>').join('');
    }
    function renderCampos(tipo) {
        const c = estado.catalogos; let html = '';
        if (tipo === 'INGRESO') {
            html += campoSelect('cfgCategoria', 'Categoría', optionList(c.categorias, 'id', 'nombre'), true);
            html += campoSelect('cfgProveedor', 'Proveedor (opcional)', '<option value="">—</option>' + optionList(c.proveedores, 'id', 'nombre'), false);
            html += campoSelect('cfgConductor', 'Conductor (opcional)', '<option value="">—</option>' + optionList(c.acompanantes, 'id', 'nombre'), false);
            html += campoTexto('cfgRemito', 'N° remito (opcional)');
            // Datos del viaje que trajo la mercadería (opcionales).
            html += campoSelect('cfgVehiculo', 'Vehículo (opcional)', '<option value="">—</option>' + optionList(c.vehiculos, 'id', 'nombre'), false);
            html += campoChecks('cfgAyudantes', 'Ayudante(s) (opcional)', c.acompanantes,
                'No hay empleados cargados. Pedí al admin que los cargue (panel → Empleados).');
        } else if (tipo === 'SALIDA_REPARTO') {
            // La hoja de ruta la arma logística en el panel; acá se elige. El conductor,
            // vehículo y ayudantes vienen de la hoja (no se cargan en el scan).
            const hojas = c.hojas_ruta || [];
            if (hojas.length) {
                const opts = '<option value="">— elegí —</option>' + hojas.map(function (h) {
                    return '<option value="' + h.id + '">' + esc(h.numero) + (h.destino ? ' · ' + esc(h.destino) : '') + '</option>';
                }).join('');
                html += campoSelect('cfgHojaRuta', 'Hoja de ruta', opts, true);
            } else {
                html += '<div class="alert alert-warning py-2 small">No hay hojas de ruta abiertas. Pedí a logística que arme una (panel → Hojas de ruta).</div>';
            }
            const zonas = c.zonas || [];
            if (zonas.length) {
                html += campoSelect('cfgZona', 'Zona de reparto', optionList(zonas, 'id', 'nombre'), true);
            } else {
                html += '<div class="alert alert-warning py-2 small">No hay zonas de reparto cargadas. Pedí al admin que cree al menos una (panel → Zonas).</div>';
            }
        } else if (tipo === 'ENTREGA') {
            html += '<div class="alert alert-info py-2 small">El transportista sos vos (' + esc(estado.usuario.nombre) + ').</div>';
        } else if (tipo === 'REINGRESO') {
            html += campoSelect('cfgMotivo', 'Motivo', optionList(c.motivos.reingreso, 'id', 'nombre'), true);
            html += campoTexto('cfgMotivoLibre', 'Aclaración', true);
        } else if (tipo === 'SALIDA_DEVOLUCION') {
            html += campoSelect('cfgProveedor', 'Proveedor', optionList(c.proveedores, 'id', 'nombre'), true);
            html += campoSelect('cfgMotivo', 'Motivo', optionList(c.motivos.devolucion, 'id', 'nombre'), true);
            html += campoTexto('cfgRemito', 'N° remito (opcional)');
            html += campoTexto('cfgMotivoLibre', 'Aclaración', true);
            // Datos del viaje (opcionales).
            html += campoSelect('cfgConductor', 'Conductor (opcional)', '<option value="">—</option>' + optionList(c.acompanantes, 'id', 'nombre'), false);
            html += campoSelect('cfgVehiculo', 'Vehículo (opcional)', '<option value="">—</option>' + optionList(c.vehiculos, 'id', 'nombre'), false);
            html += campoChecks('cfgAyudantes', 'Ayudante(s) (opcional)', c.acompanantes,
                'No hay empleados cargados. Pedí al admin que los cargue (panel → Empleados).');
        } else if (tipo === 'BAJA') {
            html += campoSelect('cfgMotivo', 'Motivo', optionList(c.motivos.baja, 'id', 'nombre'), true);
            html += campoTexto('cfgMotivoLibre', 'Aclaración', true);
        }
        $('cfgCampos').innerHTML = html;
        const motivo = $('cfgMotivo'), libre = $('cfgMotivoLibre');
        if (motivo && libre) {
            const wrap = libre.closest('.mb-3');
            const sync = () => {
                const opt = motivo.options[motivo.selectedIndex];
                const ed = opt && opt.getAttribute('data-editable') === '1';
                wrap.classList.toggle('d-none', !ed); libre.required = !!ed;
            };
            motivo.addEventListener('change', sync); sync();
        }
    }
    function campoSelect(id, label, options, required) {
        return '<div class="mb-3"><label class="form-label" for="' + id + '">' + esc(label) +
            '</label><select class="form-select" id="' + id + '"' + (required ? ' required' : '') + '>' + options + '</select></div>';
    }
    function campoTexto(id, label, required) {
        return '<div class="mb-3"><label class="form-label" for="' + id + '">' + esc(label) +
            '</label><input class="form-control" id="' + id + '"' + (required ? ' required' : '') + '></div>';
    }
    // Lista de checkboxes (selección múltiple) cuyo VALUE es el ID.
    function campoChecks(id, label, arr, vacioMsg) {
        if (!arr || !arr.length) {
            return '<div class="mb-3"><label class="form-label">' + esc(label) +
                '</label><div class="form-text">' + esc(vacioMsg || 'Sin opciones.') + '</div></div>';
        }
        const items = arr.map(x => {
            const obs = x.observacion ? ' <span class="text-muted">· ' + esc(x.observacion) + '</span>' : '';
            const cid = id + '_' + x.id;
            return '<div class="form-check"><input class="form-check-input" type="checkbox" name="' + id +
                '" value="' + esc(x.id) + '" id="' + cid + '">' +
                '<label class="form-check-label" for="' + cid + '">' + esc(x.nombre) + obs + '</label></div>';
        }).join('');
        return '<div class="mb-3"><label class="form-label">' + esc(label) +
            '</label><div style="max-height:170px;overflow-y:auto;border:1px solid #d4d9e0;border-radius:8px;padding:.5rem .75rem">' +
            items + '</div></div>';
    }
    // IDs tildados en una lista de checkboxes (array de enteros; vacío si ninguno).
    function checkIdsOf(name) {
        const els = document.querySelectorAll('input[name="' + name + '"]:checked');
        return Array.from(els).map(e => parseInt(e.value, 10)).filter(n => !isNaN(n));
    }

    $('configForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        initAudio(); // habilitar sonido dentro del gesto del usuario
        const err = $('configError'); err.classList.add('d-none');
        const tipo = $('cfgTipo').value;
        const lote = {
            uuid: uuid(), tipo: tipo,
            categoria_id: valOf('cfgCategoria'), proveedor_id: valOf('cfgProveedor'),
            conductor_empleado_id: valOf('cfgConductor'), hoja_ruta_id: valOf('cfgHojaRuta'), motivo_id: valOf('cfgMotivo'),
            motivo_libre: textOf('cfgMotivoLibre'), numero_remito: textOf('cfgRemito'),
            vehiculo_id: valOf('cfgVehiculo'), ayudante_ids: checkIdsOf('cfgAyudantes'),
            observaciones: $('cfgObs').value.trim() || null,
            timestamp_apertura: nowISO(), timestamp_cierre: null,
            dispositivo_info: navigator.userAgent, items: []
        };
        // Salida a reparto: guardar la zona elegida + un snapshot de sus localidades
        // (para validar cada QR aun sin conexión, sin depender del catálogo vigente).
        if (tipo === 'SALIDA_REPARTO') {
            lote.zona_id = valOf('cfgZona');
            const z = ((estado.catalogos && estado.catalogos.zonas) || []).find(x => +x.id === +lote.zona_id);
            lote.zona_nombre = z ? z.nombre : null;
            lote.zona_localidades = z ? (z.localidades || []) : [];
        }
        const faltante = validarConfig(tipo, lote);
        if (faltante) { err.textContent = faltante; err.classList.remove('d-none'); return; }
        // Reparto/entrega: refrescar la lista "no entregar" (best-effort, si hay conexión)
        // para que el aviso al escanear use datos al día.
        if ((tipo === 'SALIDA_REPARTO' || tipo === 'ENTREGA') && navigator.onLine) {
            try { await refrescarCatalogos(); } catch (e) {}
        }
        estado.lote = lote;
        try { await TZDB.guardarLoteActual(lote); } catch (e) {}
        irScan(false);
    });
    function valOf(id) { const el = $(id); return el && el.value ? parseInt(el.value, 10) : null; }
    function textOf(id) { const el = $(id); return el && el.value.trim() ? el.value.trim() : null; }
    function validarConfig(tipo, l) {
        if (tipo === 'INGRESO' && !l.categoria_id) return 'Elegí una categoría.';
        if (tipo === 'SALIDA_REPARTO' && !l.hoja_ruta_id) return 'Elegí una hoja de ruta.';
        if (tipo === 'SALIDA_REPARTO' && !l.zona_id) return 'Elegí una zona de reparto.';
        if (tipo === 'SALIDA_REPARTO' && (!l.zona_localidades || l.zona_localidades.length === 0)) return 'La zona elegida no tiene localidades cargadas.';
        if ((tipo === 'REINGRESO' || tipo === 'BAJA') && !l.motivo_id) return 'Elegí un motivo.';
        if (tipo === 'SALIDA_DEVOLUCION' && (!l.proveedor_id || !l.motivo_id)) return 'Proveedor y motivo son obligatorios.';
        const libre = $('cfgMotivoLibre');
        if (libre && libre.required && !l.motivo_libre) return 'Este motivo requiere una aclaración.';
        return null;
    }

    // ----- SCANNER ------------------------------------------------------------
    function irScan(resume) {
        const l = estado.lote;
        const tipoEl = $('scanTipo');
        tipoEl.className = 'badge b-' + l.tipo;
        tipoEl.textContent = TIPO_LABEL[l.tipo] || l.tipo;
        $('scanResumen').textContent = resumenLote(l);
        $('scanContador').textContent = l.items.length + ' items';
        // Foto del remito: solo en ENTREGA.
        const remitoBox = $('remitoBox');
        if (remitoBox) {
            const esEntrega = l.tipo === 'ENTREGA';
            remitoBox.classList.toggle('d-none', !esEntrega);
            if (esEntrega) renderRemitos(l);
        }
        $('scanLista').innerHTML = '';
        if (resume) { l.items.slice(-5).reverse().forEach(i => agregarALista(i.codigo, i.fuera_zona)); }
        $('btnBeep').classList.toggle('active', estado.beep);
        const pausa = $('scanPausa'); if (pausa) pausa.classList.add('d-none');
        mostrarVista('view-scan');
        TZScanner.start('scanReader', onScan).then(() => {
            $('btnCambiarCam').disabled = !TZScanner.hayMultiplesCamaras();
            reiniciarInactividad();
        }).catch(() => { feedback('error', '⚠ No se pudo acceder a la cámara. Revisá permisos.'); });
    }
    function resumenLote(l) {
        const c = estado.catalogos;
        const nombreDe = (arr, id, k) => { const x = (arr || []).find(o => +o.id === +id); return x ? x[k] : ''; };
        if (l.tipo === 'INGRESO') return nombreDe(c.categorias, l.categoria_id, 'nombre');
        if (l.tipo === 'SALIDA_REPARTO') {
            const h = (c.hojas_ruta || []).find(x => +x.id === +l.hoja_ruta_id);
            const t = h ? ('Hoja ' + h.numero) : '';
            return l.zona_nombre ? (t + ' · Zona ' + l.zona_nombre) : t;
        }
        if (l.tipo === 'ENTREGA') {
            const n = (l.remitos || []).length;
            return n ? (n + (n === 1 ? ' foto de remito' : ' fotos de remito')) : 'Sin foto de remito';
        }
        return '';
    }

    // ----- REMITO (foto firmada de la entrega) --------------------------------
    function renderRemitos(l) {
        const cont = $('remitoThumbs'); if (!cont) return;
        cont.innerHTML = '';
        (l.remitos || []).forEach(function (r, idx) {
            const url = URL.createObjectURL(r.blob);
            const div = document.createElement('div');
            div.style.position = 'relative';
            div.innerHTML =
                '<img src="' + url + '" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #d4d9e0">' +
                '<button type="button" class="btn btn-danger" style="position:absolute;top:-7px;right:-7px;border-radius:50%;width:22px;height:22px;padding:0;line-height:20px;font-size:14px">&times;</button>';
            div.querySelector('button').addEventListener('click', async function () {
                l.remitos.splice(idx, 1);
                try { await TZDB.guardarLoteActual(l); } catch (e) {}
                renderRemitos(l);
                $('scanResumen').textContent = resumenLote(l);
            });
            cont.appendChild(div);
        });
    }

    async function onRemitoElegido(e) {
        const files = Array.from(e.target.files || []);
        e.target.value = '';
        const l = estado.lote; if (!l) return;
        if (!l.remitos) l.remitos = [];
        for (const file of files) {
            if (!file.type || file.type.indexOf('image/') !== 0) continue;
            overlay(true, 'Procesando foto…');
            try {
                const blob = await comprimirImagen(file);
                l.remitos.push({ foto_uuid: uuid(), blob: blob, mime: 'image/jpeg', estado: 'pendiente' });
            } catch (err) {
                // Si falla la compresión, guardar el original (mejor eso que perder la foto).
                l.remitos.push({ foto_uuid: uuid(), blob: file, mime: file.type || 'image/jpeg', estado: 'pendiente' });
            } finally { overlay(false); }
        }
        try { await TZDB.guardarLoteActual(l); } catch (e) {}
        renderRemitos(l);
        $('scanResumen').textContent = resumenLote(l);
    }

    // Reescala a máx 1600px y comprime a JPEG ~70% para no inflar el almacenamiento.
    async function comprimirImagen(file) {
        const MAX = 1600, Q = 0.7;
        let bitmap = null;
        try { bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
        catch (e) { try { bitmap = await createImageBitmap(file); } catch (e2) { bitmap = null; } }

        let source, w, h;
        if (bitmap) { source = bitmap; w = bitmap.width; h = bitmap.height; }
        else {
            source = await new Promise(function (res, rej) {
                const im = new Image();
                im.onload = function () { res(im); };
                im.onerror = rej;
                im.src = URL.createObjectURL(file);
            });
            w = source.naturalWidth; h = source.naturalHeight;
        }
        let nw = w, nh = h;
        if (w >= h && w > MAX) { nw = MAX; nh = Math.round(h * MAX / w); }
        else if (h > w && h > MAX) { nh = MAX; nw = Math.round(w * MAX / h); }
        const canvas = document.createElement('canvas');
        canvas.width = nw; canvas.height = nh;
        canvas.getContext('2d').drawImage(source, 0, 0, nw, nh);
        if (bitmap && bitmap.close) bitmap.close();
        return await new Promise(function (res) {
            canvas.toBlob(function (b) { res(b || file); }, 'image/jpeg', Q);
        });
    }
    let confirmandoZona = false; // evita procesar escaneos mientras se confirma fuera-de-zona
    async function onScan(raw) {
        const l = estado.lote; if (!l) return;
        if (confirmandoZona) return; // hay un aviso de zona abierto: ignorar lecturas
        reiniciarInactividad();

        // El scanner ya garantiza el patrón básico; acá parseamos el payload completo.
        const p = parseQR(raw);
        if (!p) { feedback('error', '⚠ QR no reconocido'); flashCam('flash-dup'); vibrar([200]); beep(380); return; }
        const codigo = p.codigo;

        if (l.items.some(i => i.codigo === codigo)) {
            feedback('dup', '⚠ Ítem ya escaneado en este lote');
            flashCam('flash-dup'); vibrar([120, 60, 120]);
            return;
        }

        // Reparto/entrega: si la orden está marcada NO ENTREGAR, avisar y pedir confirmación.
        if (l.tipo === 'SALIDA_REPARTO' || l.tipo === 'ENTREGA') {
            const ne = ((estado.catalogos && estado.catalogos.ordenes_no_entregar) || [])
                .find(o => o.nro_orden === p.nro_orden);
            if (ne) {
                flashCam('flash-dup'); vibrar([300, 90, 300]); beep(330);
                confirmandoZona = true;
                const continuar = await modalNoEntregar(p.nro_orden, ne.observaciones || '');
                confirmandoZona = false;
                if (!continuar) { feedback('dup', 'NO ENTREGAR — ítem no agregado'); return; }
            }
        }

        // Salida a reparto: si el ítem es de OTRA zona, avisar fuerte pero NO bloquear:
        // el operador puede decidir llevarlo igual. Nunca se detiene la operación.
        let fueraZona = false;
        if (l.tipo === 'SALIDA_REPARTO' && !enZona(p.provincia, p.ciudad, l.zona_localidades)) {
            const dest = (p.ciudad || '') + (p.ciudad && p.provincia ? ' · ' : '') + (p.provincia || '—');
            flashCam('flash-dup'); vibrar([300, 90, 300]); beep(330);
            confirmandoZona = true;
            const agregar = await modalFueraZona(dest, l.zona_nombre);
            confirmandoZona = false;
            if (!agregar) { feedback('dup', 'Ítem de otra zona — no agregado'); return; }
            fueraZona = true;
        }

        const item = {
            codigo: codigo, timestamp_cliente: nowISO(),
            nro_orden: p.nro_orden, secuencia: p.secuencia, total: p.total,
            provincia: p.provincia, ciudad: p.ciudad
        };
        if (fueraZona) { item.fuera_zona = true; }
        l.items.push(item);
        feedback(fueraZona ? 'dup' : 'ok', (fueraZona ? '⚠ Fuera de zona: ' : '✓ ') + codigo, fueraZona ? 2000 : undefined);
        flashCam(fueraZona ? 'flash-dup' : 'flash-ok'); vibrar(60); beep();
        $('scanContador').textContent = l.items.length + ' items';
        agregarALista(codigo, fueraZona);
        try { await TZDB.guardarLoteActual(l); } catch (e) {}
    }

    // Aviso vehemente (no bloqueante) de ítem fuera de la zona del lote. Devuelve
    // promesa que resuelve true si el operador decide llevarlo igual.
    function modalFueraZona(destino, zonaNombre) {
        return new Promise(function (resolve) {
            const el = document.createElement('div');
            el.className = 'modal fade'; el.tabIndex = -1;
            el.setAttribute('data-bs-backdrop', 'static'); el.setAttribute('data-bs-keyboard', 'false');
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header bg-danger text-white"><h5 class="modal-title">' +
                '<i class="bi bi-exclamation-octagon-fill me-2"></i>¡Ítem de otra zona!</h5></div>' +
                '<div class="modal-body"><p class="mb-2">Este ítem va a <strong>' + esc(destino) + '</strong>, ' +
                'que <strong>no pertenece</strong> a la zona <strong>' + esc(zonaNombre || '') + '</strong> de este reparto.</p>' +
                '<p class="mb-0 text-danger">Si lo cargás igual, asegurate de que realmente sale en este viaje.</p></div>' +
                '<div class="modal-footer">' +
                '<button type="button" class="btn btn-secondary" data-act="no">No cargar</button>' +
                '<button type="button" class="btn btn-danger" data-act="si">Llevar igual</button>' +
                '</div></div></div>';
            document.body.appendChild(el);
            const m = bootstrap.Modal.getOrCreateInstance(el);
            let result = false;
            el.addEventListener('click', function (e) {
                const b = e.target.closest('[data-act]'); if (!b) return;
                result = b.getAttribute('data-act') === 'si';
                m.hide();
            });
            el.addEventListener('hidden.bs.modal', function () { el.remove(); resolve(result); });
            m.show();
        });
    }

    // Aviso de orden marcada NO ENTREGAR (reparto/entrega). Devuelve promesa que
    // resuelve true si el operador decide continuar igual.
    function modalNoEntregar(nroOrden, obs) {
        return new Promise(function (resolve) {
            const el = document.createElement('div');
            el.className = 'modal fade'; el.tabIndex = -1;
            el.setAttribute('data-bs-backdrop', 'static'); el.setAttribute('data-bs-keyboard', 'false');
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header bg-danger text-white"><h5 class="modal-title">' +
                '<i class="bi bi-x-octagon-fill me-2"></i>Orden marcada NO ENTREGAR</h5></div>' +
                '<div class="modal-body"><p class="mb-2">La orden <strong>' + esc(nroOrden) + '</strong> está marcada como <strong>NO ENTREGAR</strong>.</p>' +
                (obs ? '<p class="mb-2"><strong>Observación:</strong> ' + esc(obs) + '</p>' : '') +
                '<p class="mb-0 text-danger">¿Desea continuar igual?</p></div>' +
                '<div class="modal-footer">' +
                '<button type="button" class="btn btn-secondary" data-act="no">Cancelar</button>' +
                '<button type="button" class="btn btn-danger" data-act="si">Continuar igual</button>' +
                '</div></div></div>';
            document.body.appendChild(el);
            const m = bootstrap.Modal.getOrCreateInstance(el);
            let result = false;
            el.addEventListener('click', function (e) {
                const b = e.target.closest('[data-act]'); if (!b) return;
                result = b.getAttribute('data-act') === 'si';
                m.hide();
            });
            el.addEventListener('hidden.bs.modal', function () { el.remove(); resolve(result); });
            m.show();
        });
    }

    // ----- Inactividad: pausar la cámara y ofrecer reanudar --------------------
    function reiniciarInactividad() {
        clearTimeout(inactividadTimer);
        inactividadTimer = setTimeout(pausarLectura, INACTIVIDAD_MS);
    }
    function pausarLectura() {
        clearTimeout(inactividadTimer);
        TZScanner.stop().finally(() => {
            const p = $('scanPausa'); if (p) p.classList.remove('d-none');
        });
    }
    function reanudarLectura() {
        const p = $('scanPausa'); if (p) p.classList.add('d-none');
        TZScanner.start('scanReader', onScan).then(() => {
            $('btnCambiarCam').disabled = !TZScanner.hayMultiplesCamaras();
            reiniciarInactividad();
        }).catch(() => feedback('error', '⚠ No se pudo reanudar la cámara.'));
    }
    let feedbackTimer = null;
    function feedback(clase, texto, ms) {
        const el = $('scanFeedback'); el.className = 'tz-scan-feedback ' + clase; el.textContent = texto;
        clearTimeout(feedbackTimer);
        feedbackTimer = setTimeout(() => { el.className = 'tz-scan-feedback'; el.textContent = ''; }, ms || 1200);
    }
    function agregarALista(codigo, fueraZona) {
        const ul = $('scanLista');
        const el = document.createElement('div');
        el.className = 'si new';
        const ic = fueraZona
            ? '<i class="bi bi-exclamation-triangle-fill" style="color:var(--yellow);flex-shrink:0"></i>'
            : '<i class="bi bi-check-circle-fill" style="color:var(--green);flex-shrink:0"></i>';
        const tag = fueraZona ? '<span style="font-size:10px;color:var(--yellow);font-weight:700">FUERA DE ZONA</span> ' : '';
        el.innerHTML = ic
            + '<span class="mono" style="font-size:13px;flex:1">' + esc(codigo) + '</span>'
            + tag + '<span style="font-size:11px;color:var(--muted)">' + esc(new Date().toLocaleTimeString()) + '</span>';
        ul.insertBefore(el, ul.firstChild);
        while (ul.children.length > 6) ul.removeChild(ul.lastChild);
    }
    $('btnLinterna').addEventListener('click', async function () {
        const on = await TZScanner.toggleTorch();
        this.classList.toggle('active', on);
    });
    $('btnCambiarCam').addEventListener('click', function () { TZScanner.switchCamera(); });
    $('btnBeep').addEventListener('click', function () { estado.beep = !estado.beep; if (estado.beep) initAudio(); this.classList.toggle('active', estado.beep); });
    $('btnReanudar').addEventListener('click', reanudarLectura);
    $('btnCancelarLote').addEventListener('click', function () {
        if (!confirm('¿Cancelar el lote en curso? Se perderán los items escaneados.')) return;
        clearTimeout(inactividadTimer);
        TZScanner.stop().finally(async () => { estado.lote = null; try { await TZDB.borrarLoteActual(); } catch (e) {} irSelector(); });
    });

    // ----- REMITO: cámara -----------------------------------------------------
    (function () {
        const btn = $('btnFotoRemito'), input = $('inputRemito');
        if (btn && input) {
            btn.addEventListener('click', function () { input.click(); });
            input.addEventListener('change', onRemitoElegido);
        }
    })();

    // ----- CERRAR Y ENCOLAR ---------------------------------------------------
    $('btnEnviarLote').addEventListener('click', cerrarLote);
    async function cerrarLote() {
        const l = estado.lote; if (!l) return;
        if (l.items.length === 0) { feedback('dup', 'El lote no tiene items.'); return; }

        // Control de órdenes con ítems sin escanear (según sec/total del QR).
        const inc = ordenesIncompletas(l);
        if (inc.length > 0) {
            if (l.tipo === 'ENTREGA') {
                // En una entrega se exigen TODOS los ítems de la orden: no se cierra.
                await modalIncompletas(inc, 'bloqueo');
                return;
            }
            if (l.tipo === 'SALIDA_REPARTO') {
                // Aviso explícito: deja cerrar pero exige confirmar que se vio el aviso.
                const seguir = await modalIncompletas(inc, 'aviso');
                if (!seguir) return;
            }
        }

        l.timestamp_cierre = nowISO();
        const esReparto = l.tipo === 'SALIDA_REPARTO';
        const loteUuid  = l.uuid;
        overlay(true, 'Guardando lote…');
        // El payload JSON del lote NO lleva las fotos (van por multipart aparte).
        const payload = Object.assign({}, l);
        delete payload.remitos;
        const remitos = (l.remitos || []).map(function (r) {
            return { foto_uuid: r.foto_uuid, blob: r.blob, mime: r.mime || 'image/jpeg', estado: 'pendiente' };
        });
        const registro = {
            uuid: l.uuid, tipo: l.tipo, payload: payload, remitos: remitos, estado: 'pendiente_sync',
            creado_at: nowISO(), items_count: l.items.length
        };
        try {
            await TZDB.encolarLote(registro);
            await TZDB.borrarLoteActual();
        } catch (e) { overlay(false); alert('No se pudo guardar el lote localmente.'); return; }
        estado.lote = null;
        clearTimeout(inactividadTimer);
        await TZScanner.stop();
        overlay(false);
        await refrescarBadge();
        if (navigator.onLine) { TZSync.syncAhora(); showToast('Lote enviado', 'ok'); }
        else { showToast('Lote guardado — se enviará al reconectar', 'ok'); }
        irSelector();
        // La hoja de ruta se imprime desde el panel (logística). El scan ya no la ofrece.
    }

    // Ofrece imprimir la hoja de ruta del reparto recién enviado (página del panel,
    // en pestaña nueva). El uuid resuelve el lote aunque aún no se haya sincronizado
    // (la página avisa si todavía no está disponible).
    function modalHojaRuta(uuid) {
        return new Promise(function (resolve) {
            const appBase = (API || '').replace(/\/api$/, '');
            const url = appBase + '/admin/hoja-ruta.php?uuid=' + encodeURIComponent(uuid);
            const el = document.createElement('div');
            el.className = 'modal fade'; el.tabIndex = -1;
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title"><i class="bi bi-printer me-2"></i>Hoja de ruta</h5></div>' +
                '<div class="modal-body"><p class="mb-0">El reparto se envió. Imprimí la hoja de ruta con el vehículo, el chofer y los ayudantes.</p></div>' +
                '<div class="modal-footer">' +
                '<button type="button" class="btn btn-secondary" data-act="no">Ahora no</button>' +
                '<a class="btn btn-primary" href="' + url + '" target="_blank" rel="noopener" data-act="si">Imprimir hoja de ruta</a>' +
                '</div></div></div>';
            document.body.appendChild(el);
            const m = bootstrap.Modal.getOrCreateInstance(el);
            el.addEventListener('click', function (e) { if (e.target.closest('[data-act]')) m.hide(); });
            el.addEventListener('hidden.bs.modal', function () { el.remove(); resolve(); });
            m.show();
        });
    }

    // Modal de órdenes incompletas. modo='aviso' (reparto: deja cerrar con
    // confirmación explícita) | 'bloqueo' (entrega: no deja cerrar). Devuelve una
    // promesa que resuelve true solo si el operador confirma "Cerrar igual".
    function modalIncompletas(incompletas, modo) {
        return new Promise(function (resolve) {
            const bloqueo = modo === 'bloqueo';
            const faltan = incompletas.reduce((s, o) => s + (o.total - o.escaneados), 0);
            const lista = incompletas.map(o =>
                '<li><span class="mono">' + esc(o.nro_orden) + '</span> — ' + o.escaneados + ' de ' + o.total +
                ' (faltan <strong>' + (o.total - o.escaneados) + '</strong>)</li>').join('');

            const el = document.createElement('div');
            el.className = 'modal fade'; el.tabIndex = -1;
            el.setAttribute('data-bs-backdrop', 'static'); el.setAttribute('data-bs-keyboard', 'false');
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title text-' + (bloqueo ? 'danger' : 'warning') + '">' +
                '<i class="bi bi-exclamation-triangle-fill me-2"></i>' +
                (bloqueo ? 'Faltan ítems de la orden' : 'Órdenes incompletas') + '</h5></div>' +
                '<div class="modal-body"><p class="mb-2">' +
                (bloqueo
                    ? 'En una <strong>entrega</strong> tenés que escanear <strong>todos</strong> los ítems de cada orden. Faltan ' + faltan + ' ítem(s):'
                    : 'Hay ' + incompletas.length + ' orden(es) con ítems sin escanear (' + faltan + ' en total):') +
                '</p><ul class="mb-0">' + lista + '</ul>' +
                (bloqueo ? '' :
                    '<div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="ackInc">' +
                    '<label class="form-check-label" for="ackInc">Recibí el aviso y quiero <strong>cerrar igual</strong> el lote.</label></div>') +
                '</div><div class="modal-footer">' +
                (bloqueo
                    ? '<button type="button" class="btn btn-primary" data-act="cancel">Seguir escaneando</button>'
                    : '<button type="button" class="btn btn-secondary" data-act="cancel">Volver</button>' +
                      '<button type="button" class="btn btn-danger" data-act="ok" id="btnCerrarIgual" disabled>Cerrar igual</button>') +
                '</div></div></div>';
            document.body.appendChild(el);

            const m = bootstrap.Modal.getOrCreateInstance(el);
            let result = false;
            if (!bloqueo) {
                const chk = el.querySelector('#ackInc'), btn = el.querySelector('#btnCerrarIgual');
                chk.addEventListener('change', () => { btn.disabled = !chk.checked; });
            }
            el.addEventListener('click', function (e) {
                const b = e.target.closest('[data-act]'); if (!b) return;
                result = (b.getAttribute('data-act') === 'ok');
                m.hide();
            });
            el.addEventListener('hidden.bs.modal', function () { el.remove(); resolve(result); });
            m.show();
        });
    }

    // ----- COLA (modal) -------------------------------------------------------
    $('btnVerCola').addEventListener('click', abrirCola);
    $('btnSyncTodo').addEventListener('click', function () { TZSync.syncAhora(); setTimeout(abrirCola, 400); });

    async function abrirCola() {
        const cont = $('colaLista');
        let lotes = [];
        try { lotes = await TZDB.colaTodos(); } catch (e) {}
        lotes.sort((a, b) => (b.creado_at || '').localeCompare(a.creado_at || ''));
        if (lotes.length === 0) { cont.innerHTML = '<p class="text-muted mb-0">Sin lotes en cola.</p>'; }
        else {
            cont.innerHTML = lotes.map(function (r) {
                const badge = {
                    pendiente_sync: 'secondary', sincronizando: 'info', sincronizado: 'success',
                    error_auth: 'warning', error_datos: 'danger'
                }[r.estado] || 'secondary';
                let extra = '';
                if (r.estado === 'sincronizado' && r.respuesta) {
                    extra = '<div class="small text-success">Aplicados: ' + (r.respuesta.transiciones_aplicadas || 0) +
                        ' · Conflictos: ' + (r.respuesta.conflictos_generados || 0) + ' · Ignorados: ' + (r.respuesta.items_ignorados || 0) + '</div>';
                }
                if (r.estado === 'error_datos' && r.error_detalle) {
                    extra = '<div class="small text-danger">' + esc(r.error_detalle) + '</div>';
                }
                const reintento = (r.estado === 'error_datos' || r.estado === 'error_auth')
                    ? '<button class="btn btn-sm btn-outline-primary" onclick="window.__tzReintentar(\'' + esc(r.uuid) + '\')">Reintentar</button>' : '';
                return '<div class="border rounded p-2 mb-2">' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                    '<span>' + esc(TIPO_LABEL[r.tipo] || r.tipo) + ' · ' + (r.items_count || 0) + ' items</span>' +
                    '<span class="badge text-bg-' + badge + '">' + esc(ESTADO_COLA_LABEL[r.estado] || r.estado) + '</span></div>' +
                    '<div class="small text-muted">' + esc(r.creado_at) + '</div>' + extra +
                    (reintento ? '<div class="mt-1">' + reintento + '</div>' : '') + '</div>';
            }).join('');
        }
        const m = bootstrap.Modal.getOrCreateInstance($('modalCola')); m.show();
    }
    window.__tzReintentar = function (uuid) { TZSync.reintentar(uuid); setTimeout(abrirCola, 400); };

    // ----- SESIÓN EXPIRADA ----------------------------------------------------
    function sesionExpirada() {
        bootstrap.Modal.getOrCreateInstance($('modalSesion')).show();
    }
    $('btnReloginCola').addEventListener('click', function () {
        estado.usuario = null; mostrarVista('view-login');
    });

    arrancar();
})();
