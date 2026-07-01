// =============================================================================
// catalogo-productos.js — autocompletado de productos (descripción/código) al
// cargar o corregir ítems. La página define window.CATALOGO_PROD (array de
// {desc, dim, m3}) antes de incluir este script.
//
// Uso en el HTML:
//   <input data-cat-desc list="catalogo-prod" ...>   ← descripción/código
//   <input data-cat-dim ...>                          ← dimensiones (se autollenan)
//   <input data-cat-m3 ...>                           ← m³ (se autollena si está vacío)
// Los tres deben estar en la misma fila (<tr>) o contenedor .row / .g-2.
// Para filas agregadas dinámicamente, llamar catalogoWire(nuevaFila).
// =============================================================================

(function (root) {
    'use strict';

    var LISTA = Array.isArray(root.CATALOGO_PROD) ? root.CATALOGO_PROD : [];

    function norm(s) { return String(s == null ? '' : s).trim().toLowerCase(); }

    // Mapa descripción(normalizada) -> {dim, m3} para autollenar.
    var MAPA = {};
    LISTA.forEach(function (it) {
        var k = norm(it.desc);
        if (k && !(k in MAPA)) { MAPA[k] = { dim: it.dim || '', m3: it.m3 }; }
    });

    // Crea el <datalist id="catalogo-prod"> una sola vez.
    function asegurarDatalist() {
        if (document.getElementById('catalogo-prod')) return;
        var dl = document.createElement('datalist');
        dl.id = 'catalogo-prod';
        var frag = document.createDocumentFragment();
        LISTA.forEach(function (it) {
            var op = document.createElement('option');
            op.value = it.desc;
            if (it.dim) op.label = it.dim;
            frag.appendChild(op);
        });
        dl.appendChild(frag);
        document.body.appendChild(dl);
    }

    // Contenedor de la fila (para ubicar dim/m3 hermanos).
    function contenedor(el) {
        return el.closest('tr') || el.closest('.row') || el.closest('.g-2') || el.parentElement;
    }

    function autollenar(descInput) {
        var cont = contenedor(descInput);
        if (!cont) return;
        var e = MAPA[norm(descInput.value)];
        if (!e) return; // no es un producto conocido: no tocar nada
        var dim = cont.querySelector('[data-cat-dim]');
        var m3 = cont.querySelector('[data-cat-m3]');
        if (dim && e.dim) { dim.value = e.dim; disparar(dim); }   // dimensiones: completar
        if (m3 && (m3.value == null || m3.value === '') && e.m3 != null) {
            m3.value = String(e.m3); disparar(m3);                // m³: solo si está vacío
        }
    }

    // Notifica el cambio hecho por código (para grillas que sincronizan con 'input').
    function disparar(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Aplica el list="catalogo-prod" y engancha el autollenado en un ámbito dado.
    function catalogoWire(scope) {
        (scope || document).querySelectorAll('[data-cat-desc]').forEach(function (inp) {
            if (inp.dataset.catReady) return;
            inp.dataset.catReady = '1';
            inp.setAttribute('list', 'catalogo-prod');
        });
    }
    root.catalogoWire = catalogoWire;

    document.addEventListener('DOMContentLoaded', function () {
        if (!LISTA.length) return;
        asegurarDatalist();
        catalogoWire(document);
    });

    // Autollenado al elegir/tipear una descripción conocida (delegado, sirve para
    // filas dinámicas).
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('[data-cat-desc]')) {
            autollenar(e.target);
        }
    });
})(typeof window !== 'undefined' ? window : globalThis);
