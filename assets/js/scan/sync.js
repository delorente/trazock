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
                for (const reg of pendientes) {
                    const res = await procesarUno(reg);
                    if (res === 'auth') break; // sesión expirada: frenar el ciclo
                }
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
