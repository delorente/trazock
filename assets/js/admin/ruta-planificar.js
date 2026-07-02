/* =============================================================================
 * ruta-planificar.js — mapa + secuenciación de la pantalla "Planificar recorrido".
 *
 * - Marcadores numerados por posición (verde exacta, amarillo localidad, rojo sin
 *   ubicar) + polilínea del recorrido, con Leaflet (vendorizado) sobre tiles OSM.
 * - Arrastrar un pin corrige su ubicación (POST set_pin) y lo suma al recorrido.
 * - Arrastrar los ítems de la lista reordena las paradas; "Guardar recorrido"
 *   envía el orden actual (input oculto #ordenInput).
 * - Km y tiempo estimado se calculan en el cliente (línea recta, es una estimación).
 * ============================================================================= */
(function () {
  'use strict';

  var R = window.__RUTA__ || { stops: [], velKmh: 32 };
  var mapEl = document.getElementById('route-map');
  if (!mapEl || typeof L === 'undefined') { return; }

  var map = L.map('route-map', { zoomControl: true }).setView([-34.6, -58.4], 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  var markers = {};   // "tipo:ref" -> L.marker
  var polyline = null;
  var depotMarker = null;

  function key(tipo, ref) { return tipo + ':' + ref; }

  function pinClass(precision, placed) {
    if (!placed) { return 'sinubicar'; }
    return precision === 'exacta' ? 'exacta' : 'localidad';
  }

  function pinIcon(num, precision, placed) {
    return L.divIcon({
      className: '',
      iconSize: [26, 26],
      iconAnchor: [13, 13],
      html: '<div class="rp-pin ' + pinClass(precision, placed) + '">' + num + '</div>'
    });
  }

  // ── Crear marcadores. Las paradas sin ubicar arrancan en el centro del mapa
  //    (con leve offset para no superponerse) y quedan fuera del recorrido hasta
  //    que se las arrastra a su lugar. ─────────────────────────────────────────
  var located = [];
  var unlocatedIdx = 0;
  R.stops.forEach(function (s) {
    var placed = !!s.ubicada && s.lat != null && s.lng != null;
    var lat, lng;
    if (placed) {
      lat = s.lat; lng = s.lng;
      located.push([lat, lng]);
    } else {
      var c = map.getCenter();
      lat = c.lat + (unlocatedIdx * 0.01);
      lng = c.lng + (unlocatedIdx * 0.01);
      unlocatedIdx++;
    }
    var m = L.marker([lat, lng], { draggable: true, icon: pinIcon(s.num, s.precision, placed) });
    m.__stop = { tipo: s.tipo, ref: s.ref, precision: s.precision, placed: placed };
    m.bindTooltip('#' + s.num + ' · ' + (s.cliente || '') + (s.localidad ? (' — ' + s.localidad) : ''));
    m.on('dragend', function (e) { onPinDrag(m, e.target.getLatLng()); });
    m.addTo(map);
    markers[key(s.tipo, s.ref)] = m;
  });

  // Depósito propio: origen del recorrido. Pin distinto (casa), no arrastrable.
  if (R.depot && R.depot.lat != null && R.depot.lng != null) {
    depotMarker = L.marker([R.depot.lat, R.depot.lng], {
      icon: L.divIcon({
        className: '', iconSize: [30, 30], iconAnchor: [15, 15],
        html: '<div class="rp-depot"><i class="bi bi-house-door-fill"></i></div>'
      }),
      zIndexOffset: 1000
    }).bindTooltip('Centro de distribución (origen)').addTo(map);
    located.push([R.depot.lat, R.depot.lng]);
  }

  if (located.length > 0) {
    map.fitBounds(located, { padding: [40, 40], maxZoom: 14 });
  }

  // ── Refresco: renumera según el orden del DOM y redibuja polilínea + km ──────
  function refresh() {
    var items = Array.prototype.slice.call(document.querySelectorAll('#stopList .stop-item'));
    var orderCsv = [];
    var pts = [];
    if (depotMarker) { pts.push(depotMarker.getLatLng()); } // el recorrido arranca en el depósito
    var num = 0;
    items.forEach(function (it) {
      num++;
      var numEl = it.querySelector('.stop-num');
      if (numEl) { numEl.textContent = num; }
      var tipo = it.getAttribute('data-tipo');
      var ref = it.getAttribute('data-ref');
      orderCsv.push(tipo + ':' + ref);
      var mk = markers[key(tipo, ref)];
      if (mk) {
        mk.setIcon(pinIcon(num, mk.__stop.precision, mk.__stop.placed));
        if (mk.__stop.placed) { pts.push(mk.getLatLng()); }
      }
    });
    var ordenInput = document.getElementById('ordenInput');
    if (ordenInput) { ordenInput.value = orderCsv.join(','); }

    if (polyline) { map.removeLayer(polyline); polyline = null; }
    if (pts.length > 1) {
      polyline = L.polyline(pts, { color: '#3b82f6', weight: 3, opacity: 0.85 }).addTo(map);
    }
    updateTotales(pts);
  }

  function updateTotales(pts) {
    var kmEl = document.getElementById('tzKm');
    var tEl = document.getElementById('tzTiempo');
    if (pts.length < 2) {
      if (kmEl) { kmEl.textContent = '—'; }
      if (tEl) { tEl.textContent = '—'; }
      return;
    }
    var km = 0;
    for (var i = 1; i < pts.length; i++) {
      km += map.distance(pts[i - 1], pts[i]) / 1000;
    }
    if (kmEl) { kmEl.textContent = km.toFixed(1).replace('.', ','); }
    var mins = Math.round(km / (R.velKmh || 32) * 60);
    var hh = Math.floor(mins / 60);
    var mm = mins % 60;
    if (tEl) { tEl.textContent = (hh > 0 ? hh + 'h ' : '') + mm + 'm'; }
  }

  // ── Pin arrastrado → guardar corrección ─────────────────────────────────────
  function onPinDrag(mk, latlng) {
    var st = mk.__stop;
    var body = new URLSearchParams();
    body.set('ajax', '1');
    body.set('csrf_token', R.csrf);
    body.set('id', R.id);
    body.set('accion', 'set_pin');
    body.set('tipo', st.tipo);
    body.set('ref_id', st.ref);
    body.set('lat', latlng.lat);
    body.set('lng', latlng.lng);

    fetch(R.postUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) { return; }
        st.precision = 'exacta';
        st.placed = true;
        var it = document.querySelector('#stopList .stop-item[data-tipo="' + st.tipo + '"][data-ref="' + st.ref + '"]');
        if (it) {
          it.classList.remove('unlocated');
          it.setAttribute('data-precision', 'exacta');
          it.setAttribute('data-lat', latlng.lat);
          it.setAttribute('data-lng', latlng.lng);
          var badge = it.querySelector('.badge');
          if (badge) {
            badge.className = 'badge b-exacta';
            badge.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Exacta';
          }
          var warn = it.querySelector('.stop-warn');
          if (warn) { warn.parentNode.removeChild(warn); }
          // Agregar / actualizar el link "Cómo llegar" con la coordenada nueva.
          var navUrl = 'https://www.google.com/maps/dir/?api=1&destination=' +
            encodeURIComponent(latlng.lat + ',' + latlng.lng);
          var nav = it.querySelector('.stop-nav');
          if (!nav) {
            nav = document.createElement('a');
            nav.className = 'stop-nav';
            nav.target = '_blank';
            nav.rel = 'noopener';
            nav.innerHTML = '<i class="bi bi-sign-turn-right-fill me-1"></i>Cómo llegar';
            var body = it.querySelector('.stop-body');
            if (body) { body.appendChild(nav); }
          }
          nav.href = navUrl;
        }
        refresh();
      })
      .catch(function () { /* silencioso: el pin queda donde se soltó */ });
  }

  // ── Reordenar la lista con drag & drop (HTML5) ──────────────────────────────
  var dragEl = null;
  var list = document.getElementById('stopList');
  if (list) {
    list.addEventListener('dragstart', function (e) {
      var it = e.target.closest ? e.target.closest('.stop-item') : null;
      if (!it) { return; }
      dragEl = it;
      it.classList.add('dragging');
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; }
    });
    list.addEventListener('dragend', function () {
      if (dragEl) { dragEl.classList.remove('dragging'); }
      var t = list.querySelector('.drop-target');
      if (t) { t.classList.remove('drop-target'); }
      dragEl = null;
      refresh();
    });
    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragEl) { return; }
      var over = e.target.closest ? e.target.closest('.stop-item') : null;
      if (!over || over === dragEl) { return; }
      var rect = over.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;
      if (after) {
        over.parentNode.insertBefore(dragEl, over.nextSibling);
      } else {
        over.parentNode.insertBefore(dragEl, over);
      }
    });

    // Click en una parada → centra el mapa en su pin y la resalta un instante.
    list.addEventListener('click', function (e) {
      if (e.target.closest('a')) { return; } // dejar pasar "Cómo llegar"
      var it = e.target.closest ? e.target.closest('.stop-item') : null;
      if (!it) { return; }
      var mk = markers[key(it.getAttribute('data-tipo'), it.getAttribute('data-ref'))];
      if (!mk) { return; }
      map.setView(mk.getLatLng(), Math.max(map.getZoom(), 14), { animate: true });
      mk.openTooltip();
      var prev = list.querySelector('.stop-item.active');
      if (prev) { prev.classList.remove('active'); }
      it.classList.add('active');
      setTimeout(function () { it.classList.remove('active'); }, 1400);
    });
  }

  // Orden inicial + polilínea + km al cargar.
  refresh();
})();
