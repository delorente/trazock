// =============================================================================
// sync.js — envía los lotes de cola_lotes al server. Corre cada 15 s y también
// a demanda. Maneja 200 / 401 / 4xx / 5xx según la spec. Expone window.TZSync.
// =============================================================================

(function () {
    'use strict';

    const INTERVALO_MS = 15000;

    let cfg = { apiBase: '', onChange: function () {}, onAuthError: function () {} };
    let corriendo = false;   // evita ciclos concurrentes
    let timer = null;

    async function procesarUno(reg) {
        await TZDB.actualizarLote(reg.uuid, { estado: 'sincronizando' });
        cfg.onChange();

        let resp;
        try {
            resp = await fetch(cfg.apiBase + '/lote-enviar.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(reg.payload)
            });
        } catch (e) {
            // Falla de red → vuelve a pendiente, reintenta en el próximo ciclo.
            await TZDB.actualizarLote(reg.uuid, { estado: 'pendiente_sync' });
            cfg.onChange();
            return 'red';
        }

        let data = {};
        try { data = await resp.json(); } catch (e) { data = {}; }

        if (resp.ok && data.ok) {
            await TZDB.actualizarLote(reg.uuid, {
                estado: 'sincronizado',
                respuesta: data,
                sincronizado_at: new Date().toISOString()
            });
            cfg.onChange();
            return 'ok';
        }

        if (resp.status === 401) {
            await TZDB.actualizarLote(reg.uuid, { estado: 'error_auth' });
            cfg.onChange();
            cfg.onAuthError();
            return 'auth';
        }

        if (resp.status >= 400 && resp.status < 500) {
            await TZDB.actualizarLote(reg.uuid, {
                estado: 'error_datos',
                error_detalle: data.error || ('Error ' + resp.status)
            });
            cfg.onChange();
            return 'datos';
        }

        // 5xx → reintentar luego.
        await TZDB.actualizarLote(reg.uuid, { estado: 'pendiente_sync' });
        cfg.onChange();
        return 'server';
    }

    // Sube una foto de remito (multipart). Devuelve 'ok'|'red'|'auth'|'datos'|'server'.
    async function subirRemito(loteUuid, r) {
        if (!r.blob) return 'ok';
        const fd = new FormData();
        fd.append('foto_uuid', r.foto_uuid);
        fd.append('lote_uuid', loteUuid);
        fd.append('foto', r.blob, 'remito.jpg');
        let resp;
        try {
            resp = await fetch(cfg.apiBase + '/remito-subir.php', {
                method: 'POST', credentials: 'same-origin', body: fd
            });
        } catch (e) {
            return 'red';
        }
        let data = {};
        try { data = await resp.json(); } catch (e) { data = {}; }
        if (resp.ok && data.ok) return 'ok';
        if (resp.status === 401) return 'auth';
        if (resp.status >= 400 && resp.status < 500) return 'datos';
        return 'server';
    }

    // Recorre la cola y sube las fotos pendientes (independiente del estado del lote;
    // el server las vincula por uuid). Se corre después de enviar los lotes.
    async function subirRemitosPendientes() {
        const all = await TZDB.colaTodos();
        for (const reg of all) {
            if (!reg.remitos || !reg.remitos.length) continue;
            let cambiado = false;
            for (const r of reg.remitos) {
                if (r.estado === 'subido') continue;
                const res = await subirRemito(reg.uuid, r);
                if (res === 'ok')        { r.estado = 'subido'; r.blob = null; cambiado = true; }
                else if (res === 'datos') { r.estado = 'error'; cambiado = true; }
                else if (res === 'auth')  {
                    if (cambiado) await TZDB.actualizarLote(reg.uuid, { remitos: reg.remitos });
                    cfg.onAuthError();
                    return 'auth';
                }
                // 'red'/'server' → queda pendiente para el próximo ciclo
            }
            if (cambiado) { await TZDB.actualizarLote(reg.uuid, { remitos: reg.remitos }); cfg.onChange(); }
        }
        return 'ok';
    }

    const TZSync = {
        init(opciones) {
            cfg = Object.assign(cfg, opciones || {});
        },

        start() {
            if (timer) return;
            timer = setInterval(() => this.syncAhora(), INTERVALO_MS);
            this.syncAhora();
        },

        stop() {
            if (timer) { clearInterval(timer); timer = null; }
        },

        /** Procesa una pasada de la cola. No-op si ya hay una corriendo o no hay red. */
        async syncAhora() {
            if (corriendo) return;
            if (!navigator.onLine) return;
            corriendo = true;
            try {
                await TZDB.purgarViejos();
                const pendientes = await TZDB.colaPorEstado('pendiente_sync');
                let auth = false;
                for (const reg of pendientes) {
                    const res = await procesarUno(reg);
                    if (res === 'auth') { auth = true; break; } // sesión expirada: frenar el ciclo
                }
                // Subida de fotos de remito (entregas), tras enviar los lotes.
                if (!auth) { await subirRemitosPendientes(); }
            } finally {
                corriendo = false;
            }
        },

        /** Reintenta un lote en error (datos/red) volviéndolo a pendiente. */
        async reintentar(uuid) {
            await TZDB.actualizarLote(uuid, { estado: 'pendiente_sync', error_detalle: null });
            cfg.onChange();
            this.syncAhora();
        },

        /** Tras re-loguear: reactiva los error_auth y dispara sync. */
        async trasRelogin() {
            await TZDB.reactivarErrorAuth();
            cfg.onChange();
            this.syncAhora();
        }
    };

    window.TZSync = TZSync;
})();
