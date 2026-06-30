/**
 * Service worker mínimo para habilitar instalación PWA (Android/Chrome).
 * Estrategia network-first: la app es dinámica (Livewire), no cacheamos
 * agresivamente para no servir contenido viejo; el cache solo da un
 * respaldo cuando no hay red.
 */
const CACHE = 'app-shell-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    event.respondWith(
        fetch(req)
            .then((res) => {
                // Guardar copia de respuestas OK para respaldo offline.
                if (res && res.status === 200 && res.type === 'basic') {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
                }
                return res;
            })
            .catch(() => caches.match(req)),
    );
});
