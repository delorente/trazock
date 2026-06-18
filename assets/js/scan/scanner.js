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
        // QR + los lineales más comunes (incluye ITF/Code128 para códigos numéricos largos).
        return [
            F.QR_CODE, F.CODE_128, F.CODE_39, F.CODE_93, F.CODABAR,
            F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.ITF, F.DATA_MATRIX
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

    // Restricciones de video: mayor resolución y foco continuo mejoran la lectura de
    // códigos de barras densos (numéricos largos).
    function videoConstraints(deviceId) {
        const c = {
            width: { ideal: 1280 },
            height: { ideal: 720 },
            advanced: [{ focusMode: 'continuous' }]
        };
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

            await qr.start(videoConstraints(deviceId), config(), onSuccess, function () { /* ignorar fallos de frame */ });
            corriendo = true;
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
