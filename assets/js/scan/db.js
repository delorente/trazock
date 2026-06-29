// =============================================================================
// db.js — wrapper de IndexedDB (idb v8) para la app de escaneo.
// Stores:
//   - catalogos    : keys manuales (categorias, proveedores, motivos, transportistas,
//                    tipos_permitidos, last_updated)
//   - lote_actual  : key fija 'current' (lote en curso, si hay uno abierto)
//   - cola_lotes   : keyPath 'uuid' (lotes cerrados pendientes/sincronizados/error)
// Expone window.TZDB.
// =============================================================================

(function () {
    'use strict';

    const DB_NAME = 'trazock';
    const DB_VERSION = 1;

    const dbPromise = idb.openDB(DB_NAME, DB_VERSION, {
        upgrade(db) {
            if (!db.objectStoreNames.contains('catalogos')) {
                db.createObjectStore('catalogos'); // keys manuales
            }
            if (!db.objectStoreNames.contains('lote_actual')) {
                db.createObjectStore('lote_actual'); // key manual 'current'
            }
            if (!db.objectStoreNames.contains('cola_lotes')) {
                db.createObjectStore('cola_lotes', { keyPath: 'uuid' });
            }
        }
    });

    const TZDB = {
        // ----- catálogos ------------------------------------------------------
        async guardarCatalogos(cat) {
            const db = await dbPromise;
            const tx = db.transaction('catalogos', 'readwrite');
            const claves = ['categorias', 'proveedores', 'motivos', 'transportistas', 'tipos_permitidos'];
            for (const k of claves) {
                await tx.store.put(cat[k] !== undefined ? cat[k] : null, k);
            }
            await tx.store.put(cat.last_updated || new Date().toISOString(), 'last_updated');
            await tx.done;
        },

        async leerCatalogos() {
            const db = await dbPromise;
            const tx = db.transaction('catalogos', 'readonly');
            const out = {};
            const claves = ['categorias', 'proveedores', 'motivos', 'transportistas', 'tipos_permitidos', 'last_updated'];
            for (const k of claves) {
                out[k] = await tx.store.get(k);
            }
            await tx.done;
            return out.last_updated ? out : null;
        },

        // ----- lote en curso --------------------------------------------------
        async guardarLoteActual(lote) {
            const db = await dbPromise;
            await db.put('lote_actual', lote, 'current');
        },
        async leerLoteActual() {
            const db = await dbPromise;
            return (await db.get('lote_actual', 'current')) || null;
        },
        async borrarLoteActual() {
            const db = await dbPromise;
            await db.delete('lote_actual', 'current');
        },

        // ----- cola de lotes --------------------------------------------------
        async encolarLote(registro) {
            const db = await dbPromise;
            await db.put('cola_lotes', registro);
        },
        async actualizarLote(uuid, patch) {
            const db = await dbPromise;
            const actual = await db.get('cola_lotes', uuid);
            if (!actual) return null;
            const nuevo = Object.assign({}, actual, patch);
            await db.put('cola_lotes', nuevo);
            return nuevo;
        },
        async obtenerLote(uuid) {
            const db = await dbPromise;
            return (await db.get('cola_lotes', uuid)) || null;
        },
        async colaTodos() {
            const db = await dbPromise;
            return await db.getAll('cola_lotes');
        },
        async colaPorEstado(estado) {
            return (await this.colaTodos()).filter(r => r.estado === estado);
        },
        async contarPendientes() {
            return (await this.colaTodos()).filter(
                r => r.estado === 'pendiente_sync' || r.estado === 'sincronizando' ||
                     (r.remitos || []).some(x => x.estado !== 'subido' && x.estado !== 'error' && x.blob)
            ).length;
        },
        /** Reactiva lotes en error_auth → pendiente_sync (tras re-loguear). */
        async reactivarErrorAuth() {
            const db = await dbPromise;
            const tx = db.transaction('cola_lotes', 'readwrite');
            const all = await tx.store.getAll();
            for (const r of all) {
                if (r.estado === 'error_auth') {
                    r.estado = 'pendiente_sync';
                    await tx.store.put(r);
                }
            }
            await tx.done;
        },
        /** Purga lotes sincronizados hace más de 7 días. */
        async purgarViejos() {
            const db = await dbPromise;
            const limite = Date.now() - 7 * 24 * 3600 * 1000;
            const tx = db.transaction('cola_lotes', 'readwrite');
            const all = await tx.store.getAll();
            for (const r of all) {
                if (r.estado === 'sincronizado' && r.sincronizado_at && new Date(r.sincronizado_at).getTime() < limite) {
                    // No purgar si todavía quedan fotos de remito sin subir.
                    const fotosPend = (r.remitos || []).some(x => x.estado !== 'subido' && x.estado !== 'error' && x.blob);
                    if (!fotosPend) { await tx.store.delete(r.uuid); }
                }
            }
            await tx.done;
        }
    };

    window.TZDB = TZDB;
})();
