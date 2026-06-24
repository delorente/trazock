// =============================================================================
// sw.js — Service Worker de Trazock.
//   - Cache-first para /assets/* (Bootstrap, html5-qrcode, idb, css/js propios)
//   - Network-first para navegaciones / HTML de /scan/ y /admin/
//   - Versionado con CACHE_VERSION (bumpear para forzar update)
// Registrado con scope = raíz de la app (requiere header Service-Worker-Allowed).
// =============================================================================

const CACHE_VERSION = 'trazock-v4';

// El SW vive en <base>/scan/sw.js; los assets cuelgan de <base>/assets/.
const ASSETS = [
    '../assets/vendor/bootstrap/bootstrap.min.css',
    '../assets/vendor/bootstrap/bootstrap.bundle.min.js',
    '../assets/vendor/zxing-wasm/reader.iife.js',
    '../assets/vendor/zxing-wasm/zxing_reader.wasm',
    '../assets/vendor/idb/idb.js',
    '../assets/css/app.css',
    '../assets/css/scan.css',
    '../assets/img/logo.jpg',
    '../assets/js/scan/db.js',
    '../assets/js/scan/scanner.js',
    '../assets/js/scan/sync.js',
    '../assets/js/scan/ui.js',
    './',            // scan/ (index)
    './index.php',
    './manifest.json'
].map(p => new URL(p, self.location).toString());

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((claves) =>
            Promise.all(claves.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

function esAsset(url) {
    return url.pathname.includes('/assets/');
}

function esApi(url) {
    return url.pathname.includes('/api/');
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Las llamadas a la API nunca se cachean (datos vivos / sesión).
    if (esApi(url)) return;

    // Cache-first para assets estáticos.
    if (esAsset(url)) {
        event.respondWith(
            caches.match(req).then((hit) => {
                if (hit) return hit;
                return fetch(req).then((resp) => {
                    if (resp && resp.status === 200) {
                        const copia = resp.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(req, copia));
                    }
                    return resp;
                });
            })
        );
        return;
    }

    // Network-first para navegaciones / HTML.
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(req).then((resp) => {
                const copia = resp.clone();
                caches.open(CACHE_VERSION).then((c) => c.put(req, copia));
                return resp;
            }).catch(() => caches.match(req).then((hit) => hit || caches.match(new URL('./index.php', self.location).toString())))
        );
    }
});
