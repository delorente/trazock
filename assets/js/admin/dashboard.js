// =============================================================================
// dashboard.js — auto-refresh del dashboard cada 30 s con cuenta regresiva.
// Consulta index.php?ajax=1 (JSON) y actualiza KPIs, tabla cruzada y últimos lotes.
// =============================================================================

(function () {
    'use strict';

    const INTERVALO = 30; // segundos

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    const TIPO_LABEL = {
        INGRESO: 'Ingreso', SALIDA_REPARTO: 'Salida a reparto', ENTREGA: 'Entrega',
        REINGRESO: 'Reingreso', SALIDA_DEVOLUCION: 'Devolución a proveedor', BAJA: 'Baja'
    };

    function actualizarKpis(kpis) {
        document.querySelectorAll('[data-kpi]').forEach(function (el) {
            const k = el.getAttribute('data-kpi');
            if (kpis[k] !== undefined) el.textContent = kpis[k];
        });
    }

    function actualizarTabla(tabla, estados) {
        const tbody = document.getElementById('tzTablaCruzada');
        if (!tbody) return;
        if (!tabla.length) {
            tbody.innerHTML = '<tr><td colspan="' + (estados.length + 2) +
                '" class="text-center text-muted py-3">Sin productos aún.</td></tr>';
            return;
        }
        const tot = {}; estados.forEach(e => tot[e] = 0); let totAll = 0;
        let html = '';
        tabla.forEach(function (fila) {
            html += '<tr><td>' + esc(fila.categoria) + '</td>';
            estados.forEach(function (e) {
                const n = parseInt(fila[e], 10) || 0; tot[e] += n;
                html += '<td>' + (n ? n : '<span class="text-muted">·</span>') + '</td>';
            });
            const t = parseInt(fila.total, 10) || 0; totAll += t;
            html += '<td class="tc">' + t + '</td></tr>';
        });
        html += '<tr class="tr-total"><td><strong>Total</strong></td>';
        estados.forEach(e => html += '<td><strong>' + tot[e] + '</strong></td>');
        html += '<td class="tc"><strong>' + totAll + '</strong></td></tr>';
        tbody.innerHTML = html;
    }

    function actualizarLotes(lotes) {
        const tbody = document.getElementById('tzUltimosLotes');
        if (!tbody) return;
        if (!lotes.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Sin lotes aún.</td></tr>';
            return;
        }
        let html = '';
        lotes.forEach(function (l) {
            const conf = parseInt(l.conflictos, 10) || 0;
            html += '<tr>' +
                '<td class="mono" style="font-size:12px">' + esc(l.num || '') + '</td>' +
                '<td class="text-muted" style="font-size:12px">' + esc(l.fecha_fmt || '') + '</td>' +
                '<td><span class="badge b-' + esc(l.tipo) + '">' + esc(TIPO_LABEL[l.tipo] || l.tipo) + '</span></td>' +
                '<td>' + esc(l.responsable || '—') + '</td>' +
                '<td>' + (parseInt(l.items, 10) || 0) + '</td>' +
                '<td>' + (conf > 0 ? '<span class="badge b-conflict">⚠ ' + conf + '</span>' : '<span class="text-muted">—</span>') + '</td>' +
                '<td><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="lote-detalle.php?id=' +
                    encodeURIComponent(l.id) + '">Ver</a></td></tr>';
        });
        tbody.innerHTML = html;
    }

    function refrescar() {
        return fetch('index.php?ajax=1', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                actualizarKpis(data.kpis || {});
                actualizarTabla(data.tabla || [], data.estados || []);
                actualizarLotes(data.lotes || []);
            })
            .catch(function () { /* silencioso */ });
    }

    // Cuenta regresiva en el header.
    let restante = INTERVALO;
    setInterval(function () {
        restante--;
        if (restante <= 0) { restante = INTERVALO; refrescar(); }
        const el = document.getElementById('tzUltimaAct');
        if (el) el.textContent = restante;
    }, 1000);
})();
