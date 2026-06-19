// =============================================================================
// scanner.js — escáner basado en zxing-cpp (WASM) con pipeline de cámara propio.
//
// Motivo del cambio (vs html5-qrcode/ZXing-js): los códigos de las etiquetas son
// Code 128 largos (36 dígitos, con corridas de ceros) sobre superficie curva, que
// el motor anterior leía mal (truncaba a 14/18 o agregaba ceros) sobre todo en iOS.
// zxing-cpp (C++ compilado a WASM) decodifica mucho mejor estos casos.
//
// zxing-cpp SOLO decodifica; la cámara la manejamos acá: getUserMedia → <video> →
// <canvas> (un cuadro cada ~1/FPS) → readBarcodes(ImageData).
//
// Mantiene EXACTA la interfaz window.TZScanner (start/stop/toggleTorch/switchCamera/
// hayMultiplesCamaras) para no tocar ui.js.
//
// El global del IIFE de zxing-wasm es window.ZXingWASM. El .wasm se ubica con
// setZXingModuleOverrides({locateFile}) apuntando a la URL local (window.TZ_BOOT.zxingWasm).
// =============================================================================

(function () {
    'use strict';

    const ANTIRREBOTE_MS = 2000;
    const FPS = 8; // cuadros por segundo a decodificar

    // Solo simbologías con checksum / longitud fija (sin parciales): Code 128 (las
    // etiquetas), retail EAN/UPC, y QR/DataMatrix 2D. Se excluyen los lineales
    // self-clocking de longitud variable (ITF, Code 39/93, Codabar), que producían
    // falsos positivos parciales sobre el Code 128 real.
    const FORMATOS = ['Code128', 'EAN-13', 'EAN-8', 'UPC-A', 'UPC-E', 'QRCode', 'DataMatrix'];

    let stream = null;
    let video = null;
    let canvas = null;
    let ctx = null;
    let decodeTimer = null;
    let decoding = false;     // evita solapar decodificaciones
    let corriendo = false;
    let onScanCb = null;
    let elId = null;
    let track = null;         // video track activo (para linterna / stop)
    let torchOn = false;
    let camIndex = 0;
    let videoInputs = [];     // cámaras de video disponibles (para "cambiar cámara")
    let wasmListo = false;

    let lastCode = null;
    let lastTime = 0;

    // Configura una sola vez dónde está el .wasm local (servido junto al glue).
    function configurarWasm() {
        if (wasmListo || !window.ZXingWASM) return;
        const wasmUrl = (window.TZ_BOOT && window.TZ_BOOT.zxingWasm) || '';
        if (wasmUrl) {
            window.ZXingWASM.setZXingModuleOverrides({
                locateFile: function (path) {
                    return path && path.endsWith('.wasm') ? wasmUrl : path;
                }
            });
        }
        wasmListo = true;
    }

    // Restricciones de cámara: alta resolución (clave para códigos densos/largos) y
    // cámara trasera. En el primer arranque no hay lista de cámaras todavía (sin
    // permiso), así que usamos facingMode; al cambiar de cámara usamos deviceId.
    function videoConstraints() {
        const c = { width: { ideal: 1920 }, height: { ideal: 1080 } };
        const dev = videoInputs.length > 0 ? videoInputs[camIndex % videoInputs.length] : null;
        if (dev && dev.deviceId) {
            c.deviceId = { exact: dev.deviceId };
        } else {
            c.facingMode = { ideal: 'environment' };
        }
        return c;
    }

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

    // Decodifica un cuadro del video. No solapa: si el anterior sigue corriendo, salta.
    async function tick() {
        if (!corriendo || decoding || !video) return;
        if (video.readyState < 2 || !video.videoWidth || !video.videoHeight) return;
        decoding = true;
        try {
            const w = video.videoWidth, h = video.videoHeight;
            if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
            ctx.drawImage(video, 0, 0, w, h);
            const img = ctx.getImageData(0, 0, w, h);
            const resultados = await window.ZXingWASM.readBarcodes(img, {
                formats: FORMATOS,
                tryHarder: true,
                maxNumberOfSymbols: 1
            });
            if (corriendo && resultados && resultados.length) {
                onSuccess(resultados[0].text);
            }
        } catch (e) {
            /* cuadro no decodificado: ignorar */
        } finally {
            decoding = false;
        }
    }

    const TZScanner = {
        /**
         * Inicia el escáner en el elemento dado, llamando onScan(codigo) por lectura.
         * Devuelve una promesa. Rechaza si no hay permiso/cámara (igual que antes).
         */
        async start(elementId, onScan) {
            onScanCb = onScan;
            elId = elementId;
            lastCode = null; lastTime = 0; torchOn = false;

            configurarWasm();

            // 1) Abrir la cámara (esto dispara el permiso la primera vez).
            stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraints() });
            track = stream.getVideoTracks()[0] || null;

            // 2) Enumerar cámaras ahora que hay permiso (para el botón "cambiar cámara").
            try {
                const devs = await navigator.mediaDevices.enumerateDevices();
                videoInputs = devs.filter(d => d.kind === 'videoinput');
            } catch (e) {
                videoInputs = [];
            }

            // 3) Montar / reutilizar el <video> dentro del contenedor (estilado por scan.css).
            const cont = document.getElementById(elId);
            video = cont ? cont.querySelector('video') : null;
            if (!video && cont) {
                video = document.createElement('video');
                video.setAttribute('playsinline', '');
                video.setAttribute('muted', '');
                video.muted = true;
                cont.appendChild(video);
            }
            video.srcObject = stream;
            try { await video.play(); } catch (e) { /* algunos navegadores resuelven igual */ }

            // 4) Canvas oculto para extraer cuadros.
            if (!canvas) {
                canvas = document.createElement('canvas');
                ctx = canvas.getContext('2d', { willReadFrequently: true });
            }

            corriendo = true;
            if (decodeTimer) clearInterval(decodeTimer);
            decodeTimer = setInterval(tick, Math.round(1000 / FPS));
        },

        async stop() {
            corriendo = false;
            if (decodeTimer) { clearInterval(decodeTimer); decodeTimer = null; }
            if (stream) {
                stream.getTracks().forEach(t => { try { t.stop(); } catch (e) { /* noop */ } });
                stream = null;
            }
            if (video) {
                try { video.pause(); } catch (e) { /* noop */ }
                try { video.srcObject = null; } catch (e) { /* noop */ }
            }
            track = null;
            torchOn = false;
            decoding = false;
        },

        /** Alterna la linterna si el dispositivo la soporta. Devuelve el nuevo estado. */
        async toggleTorch() {
            if (!track || !corriendo) return false;
            torchOn = !torchOn;
            try {
                await track.applyConstraints({ advanced: [{ torch: torchOn }] });
            } catch (e) {
                torchOn = false;
                return false;
            }
            return torchOn;
        },

        /** Cambia a la siguiente cámara disponible. Devuelve true si cambió. */
        async switchCamera() {
            if (videoInputs.length < 2) return false;
            camIndex = (camIndex + 1) % videoInputs.length;
            await this.stop();
            await this.start(elId, onScanCb);
            return true;
        },

        hayMultiplesCamaras() {
            return videoInputs.length > 1;
        }
    };

    window.TZScanner = TZScanner;
})();
