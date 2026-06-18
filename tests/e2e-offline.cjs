// =============================================================================
// tests/e2e-offline.cjs — prueba E2E del flujo offline de la Fase 6.
// Ejercita el código real TZDB (IndexedDB) + TZSync (worker de sync) contra el
// server WAMP, usando Chrome vía puppeteer-core. NO usa la cámara: inyecta lotes
// directamente en la cola, que es la maquinaria crítica de offline.
//
// Uso: node tests/e2e-offline.cjs
// =============================================================================

const puppeteer = require('puppeteer-core');

const BASE = 'http://localhost/proyectos/trazock';
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
let CAT_ID = 1; // se reemplaza por una categoría activa real tras el login

let fallos = 0;
function check(desc, cond, extra) {
    if (cond) { console.log('[OK]   ' + desc); }
    else { fallos++; console.log('[FAIL] ' + desc + (extra ? ' — ' + extra : '')); }
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME, headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage']
    });
    const page = await browser.newPage();
    page.on('pageerror', e => console.log('  [pageerror] ' + e.message));

    // 1) Cargar la app y loguear (online) vía la API; recargar para bootear sesión.
    await page.goto(BASE + '/scan/index.php', { waitUntil: 'networkidle2' });
    const login = await page.evaluate(async (base) => {
        const r = await fetch(base + '/api/login.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario: 'operador1', password: 'oper123' })
        });
        return { status: r.status, body: await r.json() };
    }, BASE);
    check('login operador1 (200)', login.status === 200 && login.body.ok, 'status=' + login.status);

    await page.goto(BASE + '/scan/index.php', { waitUntil: 'networkidle2' });
    await sleep(500);
    const boot = await page.evaluate(() => !!(window.TZDB && window.TZSync && window.TZ_BOOT.usuario));
    check('TZDB + TZSync inicializados y sesión en boot', boot);

    // Tomar una categoría ACTIVA real (las pruebas manuales pueden haber inactivado la 1).
    CAT_ID = await page.evaluate(() => {
        const cats = (window.TZ_BOOT.catalogos && window.TZ_BOOT.catalogos.categorias) || [];
        return cats.length ? parseInt(cats[0].id, 10) : 1;
    });

    // Helper en el browser para crear un registro de cola.
    await page.evaluate(() => {
        window.__mkLote = function (codes, catId) {
            const uuid = crypto.randomUUID();
            const items = codes.map((c, i) => ({ codigo: c, timestamp_cliente: new Date(Date.now() + i * 1000).toISOString() }));
            return {
                uuid, tipo: 'INGRESO',
                payload: {
                    uuid, tipo: 'INGRESO', categoria_id: catId,
                    timestamp_apertura: new Date().toISOString(), timestamp_cierre: new Date().toISOString(),
                    dispositivo_info: 'e2e', items
                },
                estado: 'pendiente_sync', creado_at: new Date().toISOString(), items_count: items.length
            };
        };
    });

    // 2) OFFLINE: encolar un lote de 30 items. Debe quedar pendiente.
    await page.setOfflineMode(true);
    const reg1 = await page.evaluate(async (catId) => {
        const codes = Array.from({ length: 30 }, (_, i) => 'OFF-' + i);
        const reg = window.__mkLote(codes, catId);
        await window.TZDB.encolarLote(reg);
        await window.TZSync.syncAhora(); // offline → no-op
        const back = await window.TZDB.obtenerLote(reg.uuid);
        return { uuid: reg.uuid, estado: back.estado, pendientes: await window.TZDB.contarPendientes() };
    }, CAT_ID);
    check('offline: lote de 30 items queda pendiente_sync', reg1.estado === 'pendiente_sync' && reg1.pendientes === 1, 'estado=' + reg1.estado);

    // 3) RECONECTAR: sync envía la cola; el lote queda sincronizado y el server lo tiene.
    await page.setOfflineMode(false);
    await page.evaluate(async () => { await window.TZSync.syncAhora(); });
    await sleep(800);
    const sync1 = await page.evaluate(async (base, uuid) => {
        const back = await window.TZDB.obtenerLote(uuid);
        const r = await fetch(base + '/api/lotes-pendientes.php?uuid=' + uuid, { credentials: 'same-origin' });
        const d = await r.json();
        return { estado: back.estado, aplicadas: back.respuesta && back.respuesta.transiciones_aplicadas, procesado: d.procesado, serverAplicadas: d.transiciones_aplicadas };
    }, BASE, reg1.uuid);
    check('reconexión: lote sincronizado', sync1.estado === 'sincronizado', 'estado=' + sync1.estado);
    check('server recibió las 30 transiciones', sync1.procesado === true && sync1.serverAplicadas === 30, 'aplicadas=' + sync1.serverAplicadas);

    // 4) 5 lotes offline distintos → reconectar → todos sincronizan.
    await page.setOfflineMode(true);
    const uuids = await page.evaluate(async (catId) => {
        const us = [];
        for (let n = 0; n < 5; n++) {
            const reg = window.__mkLote(['L' + n + '-A', 'L' + n + '-B'], catId);
            await window.TZDB.encolarLote(reg); us.push(reg.uuid);
        }
        return us;
    }, CAT_ID);
    const pend5 = await page.evaluate(async () => await window.TZDB.contarPendientes());
    check('offline: 5 lotes encolados (pendientes=5)', pend5 === 5, 'pendientes=' + pend5);

    await page.setOfflineMode(false);
    await page.evaluate(async () => { await window.TZSync.syncAhora(); });
    await sleep(1200);
    const sinc5 = await page.evaluate(async (uuids) => {
        let ok = 0;
        for (const u of uuids) { const r = await window.TZDB.obtenerLote(u); if (r.estado === 'sincronizado') ok++; }
        return ok;
    }, uuids);
    check('reconexión: los 5 lotes sincronizan', sinc5 === 5, 'sincronizados=' + sinc5);

    // 5) Idempotencia: reenviar el primer lote (mismo uuid) → server idempotente.
    const idem = await page.evaluate(async (base, uuid) => {
        const reg = await window.TZDB.obtenerLote(uuid);
        const r = await fetch(base + '/api/lote-enviar.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(reg.payload)
        });
        return await r.json();
    }, BASE, reg1.uuid);
    check('idempotencia: reenvío del lote devuelve idempotente=true', idem.idempotente === true && idem.transiciones_aplicadas === 30);

    // 6) Sesión expirada: borrar cookie, encolar y sync → error_auth.
    const cookies = await page.cookies();
    await page.deleteCookie(...cookies);
    const auth = await page.evaluate(async (catId) => {
        const reg = window.__mkLote(['AUTH-1'], catId);
        await window.TZDB.encolarLote(reg);
        await window.TZSync.syncAhora();
        const back = await window.TZDB.obtenerLote(reg.uuid);
        return back.estado;
    }, CAT_ID);
    check('sesión expirada (sin cookie): lote queda error_auth', auth === 'error_auth', 'estado=' + auth);

    await browser.close();
    console.log('\n' + (fallos === 0 ? '=== E2E OFFLINE OK ===' : '=== ' + fallos + ' FALLO(S) ==='));
    process.exit(fallos === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
