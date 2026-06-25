/* =============================================================================
 * etiquetas.js — pinta el QR real de cada etiqueta de la hoja imprimible.
 *
 * Cada celda tiene un <div class="lq" data-qr="<payload>">. El payload es
 * autocontenido (nro_orden|sec/total|provincia|apellido) — ver lib/EtiquetaQr.php.
 * Usa qrcode-generator (assets/vendor) en modo byte/UTF-8, ECC nivel M, versión
 * automática. Salida SVG escalable: el tamaño físico lo fija el CSS de impresión.
 * ========================================================================== */
(function () {
  'use strict';
  if (typeof qrcode !== 'function') {
    console.error('etiquetas.js: falta la librería qrcode-generator.');
    return;
  }

  // CRÍTICO: codificar el payload como UTF-8. Por defecto la librería usa una
  // tabla Latin1/SJIS que corrompe los acentos (Córdoba, García, Fernández…) y
  // deja el QR ilegible. Verificado decodificando con jsQR/zxing.
  if (qrcode.stringToBytesFuncs && qrcode.stringToBytesFuncs['UTF-8']) {
    qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
  }

  function pintar(el) {
    var data = el.getAttribute('data-qr') || '';
    if (!data) { return; }
    try {
      var qr = qrcode(0, 'M');      // 0 = versión automática (la menor que entre)
      qr.addData(data);             // sin tipo → modo byte (UTF-8) para acentos
      qr.make();
      // Zona de silencio estándar = 4 módulos en blanco alrededor. El margin está
      // en px y cellSize=2 px/módulo → margin:8 = 4 módulos. Antes estaba en 0, lo
      // que dejaba el QR pegado al texto/borde y los lectores (zxing en iPhone) fallaban.
      el.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 8, scalable: true });
    } catch (e) {
      console.error('etiquetas.js: no se pudo generar el QR para', data, e);
      el.textContent = '⚠';
    }
  }

  function render() {
    var nodos = document.querySelectorAll('.lq[data-qr]');
    Array.prototype.forEach.call(nodos, pintar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
