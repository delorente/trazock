// =============================================================================
// scanner.js — wrapper de html5-qrcode con antirrebote de 2 s sobre el mismo código.
// Lectura continua de QR y códigos de barras lineales (EAN-13, Code-128, etc).
// Expone window.TZScanner.
// =============================================================================

(function () {
    'use strict';

    const ANTIRREBOTE_MS = 2000;

    let qr = null;            // instancia Html5Qrcode
    let cameras = [];         // lista de cámaras
    let camIndex = 0;         // cámara activa
    let corriendo = false;
    let torchOn = false;

    let lastCode = null;
    let lastTime = 0;
    let onScanCb = null;

    function onSuccess(decodedText) {
        const code = (decodedText || '').trim();
        if (!code) return;
        const now = Date.now();
        // Antirrebote: mismo código dentro de la ventana → ignorar.
        if (code === lastCode && (now - lastTime) < ANTIRREBOTE_MS) {
            return;
        }
        lastCode = code;
        lastTime = now;
        if (onScanCb) onScanCb(code);
    }

    function formatosSoportados() {
        const F = window.Html5QrcodeSupportedFormats;
        if (!F) return undefined; // si no está el enum, html5-qrcode usa todos
        // Solo simbologías con checksum / longitud fija (sin parciales): Code 128 (las
        // etiquetas), retail EAN/UPC, y QR/DataMatrix 2D. Se EXCLUYEN los lineales
        // self-clocking de longitud variable (ITF, Code 39, Code 93, Codabar) porque
        // generan falsos positivos parciales (ej. 14 ó 18 dígitos) sobre el Code 128 real,
        // sobre todo en iOS (ZXing). Code 128 lleva checksum: se lee completo o no se lee.
        return [
            F.QR_CODE, F.CODE_128,
            F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.DATA_MATRIX
        ];
    }

    function config() {
        // Sin qrbox: html5-qrcode no dibuja su caja sombreada (usamos la retícula del
        // diseño como guía). fps alto + BarcodeDetector nativo cuando está disponible.
        return {
            fps: 15,
            aspectRatio: 1.333,
            formatsToSupport: formatosSoportados(),
            experimentalFeatures: { useBarCodeDetectorIfSupported: true }
        };
    }

    // Restricciones de video: alta resolución para poder resolver códigos de barras
    // densos / numéricos largos. SIN advanced.focusMode, que rompía el arranque en
    // iOS/Android (OverconstrainedError y la cámara quedaba en negro).
    function videoConstraints(deviceId) {
        const c = { width: { ideal: 1920 }, height: { ideal: 1080 } };
        if (deviceId) { c.deviceId = { exact: deviceId }; }
        else { c.facingMode = { ideal: 'environment' }; }
        return c;
    }

    const TZScanner = {
        /**
         * Inicia el escáner en el elemento dado, llamando onScan(codigo) por lectura.
         * Devuelve una promesa. Rechaza si no hay permiso/cámara.
         */
        async start(elementId, onScan) {
            onScanCb = onScan;
            lastCode = null; lastTime = 0; torchOn = false;

            if (!qr) {
                qr = new Html5Qrcode(elementId, { verbose: false });
            }

            // Enumerar cámaras (requiere permiso). Si falla, intentamos facingMode.
            try {
                cameras = await Html5Qrcode.getCameras();
            } catch (e) {
                cameras = [];
            }

            const deviceId = cameras.length > 0 ? cameras[camIndex % cameras.length].id : null;

            // Primero intentamos ALTA RESOLUCIÓN (clave para los códigos largos/densos).
            // Si ese arranque falla, recreamos la instancia (para no quedar "en transición")
            // y reintentamos con la cámara trasera en constraints mínimos, que siempre arranca.
            try {
                await qr.start(videoConstraints(deviceId), config(), onSuccess, function () { /* ignorar fallos de frame */ });
            } catch (ePrimario) {
                try { await qr.stop(); } catch (e) { /* noop */ }
                try { qr.clear(); } catch (e) { /* noop */ }
                qr = new Html5Qrcode(elementId, { verbose: false });
                await qr.start({ facingMode: 'environment' }, config(), onSuccess, function () { /* ignorar fallos de frame */ });
            }
            corriendo = true;

            // Foco continuo APLICADO DESPUÉS de arrancar (best-effort). Aplicarlo acá,
            // y no en los constraints iniciales, evita romper el arranque y mejora
            // mucho la lectura de códigos densos/largos (ITF, Code-128) y superficies curvas.
            try { await qr.applyVideoConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) { /* no soportado: noop */ }
        },

        async stop() {
            if (qr && corriendo) {
                try { await qr.stop(); } catch (e) { /* noop */ }
                try { qr.clear(); } catch (e) { /* noop */ }
            }
            corriendo = false;
            torchOn = false;
        },

        /** Alterna la linterna si el dispositivo la soporta. Devuelve el nuevo estado. */
        async toggleTorch() {
            if (!qr || !corriendo) return false;
            torchOn = !torchOn;
            try {
                await qr.applyVideoConstraints({ advanced: [{ torch: torchOn }] });
            } catch (e) {
                torchOn = false;
                return false;
            }
            return torchOn;
        },

        /** Cambia a la siguiente cámara disponible. Devuelve true si cambió. */
        async switchCamera() {
            if (cameras.length < 2) return false;
            camIndex = (camIndex + 1) % cameras.length;
            await this.stop();
            await this.start('scanReader', onScanCb);
            return true;
        },

        hayMultiplesCamaras() {
            return cameras.length > 1;
        }
    };

    window.TZScanner = TZScanner;
})();
