/* =============================================================================
 * reportes-mapa.js — pestaña "Mapa" del reporte de órdenes.
 *
 * Muestra las órdenes filtradas (de la página actual, con geocode) como pines.
 * Click en un pin ↔ tilda la MISMA casilla .wa-chk de la lista, así el flujo
 * existente "Agregar a Hoja de Ruta" (y "Avisar entrega") funciona sin cambios.
 * El mapa se inicializa recién al abrir la pestaña (Leaflet necesita el contenedor
 * visible; luego invalidateSize).
 * ============================================================================= */
(function () {
  'use strict';

  var D = window.__REP_MAPA__;
  var mapEl = document.getElementById('route-map');
  if (!mapEl || typeof L === 'undefined' || !D) { return; }

  var stops = D.stops || [];
  var byId = {};
  stops.forEach(function (s) { byId[s.id] = s; });

  var map = null;
  var markers = {};
  var initialized = false;

  function chk(id) { return document.querySelector('.wa-chk[value="' + id + '"]'); }

  function pinIcon(selected, precision) {
    var cls = selected ? 'sel' : (precision === 'exacta' ? 'exacta' : 'localidad');
    return L.divIcon({
      className: '', iconSize: [22, 22], iconAnchor: [11, 11],
      html: '<div class="rep-pin ' + cls + '"></div>'
    });
  }

  function refreshPin(id) {
    var m = markers[id];
    if (!m) { return; }
    var c = chk(id);
    var s = byId[id];
    m.setIcon(pinIcon(c ? c.checked : false, s ? s.precision : 'localidad'));
  }

  function togglePin(id) {
    var c = chk(id);
    if (!c) { return; } // sin permiso de marcar (gestor) → no hay casilla
    c.checked = !c.checked;
    c.dispatchEvent(new Event('change', { bubbles: true })); // dispara contadores existentes
    refreshPin(id);
  }

  function initMap() {
    if (initialized) { return; }
    initialized = true;
    map = L.map('route-map').setView([-26.8, -65.2], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    var bounds = [];
    stops.forEach(function (s) {
      var c = chk(s.id);
      var m = L.marker([s.lat, s.lng], { icon: pinIcon(c ? c.checked : false, s.precision) });
      m.bindTooltip('#' + s.nro + ' · ' + (s.cliente || '') + (s.localidad ? (' — ' + s.localidad) : ''));
      m.on('click', function () { togglePin(s.id); });
      m.addTo(map);
      markers[s.id] = m;
      bounds.push([s.lat, s.lng]);
    });
    if (bounds.length) { map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 }); }
  }

  // La lista y el mapa comparten estado: si se tilda desde la tabla, refrescar el pin.
  document.querySelectorAll('.wa-chk').forEach(function (c) {
    c.addEventListener('change', function () { refreshPin(+c.value); });
  });
  var chkAll = document.getElementById('waChkAll');
  if (chkAll) {
    chkAll.addEventListener('change', function () {
      setTimeout(function () { stops.forEach(function (s) { refreshPin(s.id); }); }, 0);
    });
  }

  // Pestañas Listado / Mapa.
  var tabList = document.getElementById('tabListado');
  var tabMap = document.getElementById('tabMapa');
  var vList = document.getElementById('repListado');
  var vMap = document.getElementById('repMapa');
  function show(mapa) {
    if (vMap) { vMap.style.display = mapa ? 'block' : 'none'; }
    if (vList) { vList.style.display = mapa ? 'none' : 'block'; }
    if (tabMap) { tabMap.classList.toggle('active', mapa); }
    if (tabList) { tabList.classList.toggle('active', !mapa); }
    if (mapa) {
      initMap();
      setTimeout(function () { if (map) { map.invalidateSize(); } }, 60);
    }
  }
  if (tabList && tabMap) {
    tabList.addEventListener('click', function () { show(false); });
    tabMap.addEventListener('click', function () { show(true); });
  }
})();
