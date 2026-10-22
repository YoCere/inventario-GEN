/**
 * Service worker mínimo: existe para que la app se pueda instalar en el
 * celular (PWA). No es una capa de caché agresiva a propósito — la app es
 * dinámica (Livewire) y servir una pantalla vieja sería peor que no servir
 * nada.
 *
 * REGLA DE ORO DE ESTE ARCHIVO: nunca responder algo que no sea una Response.
 *
 * La versión anterior devolvía lo que diera `caches.match` directamente desde
 * el catch. Cuando la red fallaba y el caché no tenía esa página, eso es
 * `undefined`,
 * y responder `undefined` rompe con "Failed to convert value to 'Response'".
 * Peor que el error en sí: dejaba la pantalla en blanco sin decir qué pasó, y
 * en las páginas que SÍ estaban cacheadas seguía mostrando todo bien, con lo
 * cual un servidor caído parecía "una parte del sistema que no anda".
 */
const CACHE = 'app-shell-v2';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Limpia las versiones anteriores: si no, una copia vieja de la app
            // puede sobrevivir a un despliegue y nadie entiende por qué.
            const nombres = await caches.keys();
            await Promise.all(nombres.filter((n) => n !== CACHE).map((n) => caches.delete(n)));
            await self.clients.claim();
        })(),
    );
});

/** Página que se ve cuando no hay red NI copia guardada. Dice qué pasa y qué hacer. */
function paginaSinConexion() {
    return new Response(
        `<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sin conexión</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0;
         min-height: 100vh; display: grid; place-items: center; background: #fafafa; color: #18181b; }
  .caja { max-width: 24rem; padding: 2rem; text-align: center; }
  h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
  p { margin: 0 0 1.5rem; line-height: 1.5; color: #52525b; }
  button { font: inherit; padding: .625rem 1.25rem; border: 0; border-radius: .5rem;
           background: #18181b; color: #fff; cursor: pointer; }
  @media (prefers-color-scheme: dark) {
    body { background: #09090b; color: #fafafa; }
    p { color: #a1a1aa; }
    button { background: #fafafa; color: #18181b; }
  }
</style>
</head>
<body>
  <div class="caja">
    <h1>No se pudo conectar</h1>
    <p>No hay conexión con el servidor. Puede ser tu internet, o el sistema puede estar reiniciándose.</p>
    <button onclick="location.reload()">Reintentar</button>
  </div>
</body>
</html>`,
        {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
        },
    );
}

self.addEventListener('fetch', (event) => {
    const req = event.request;

    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    // Solo lo propio: nada de interceptar pedidos a otros dominios.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Lo que hace trabajo de verdad (Livewire, el panel, la API) no pasa por
    // acá: una respuesta vieja a una de estas rutas puede mostrar datos que ya
    // no son ciertos, y eso es peor que un error.
    if (url.pathname.startsWith('/livewire/') || url.pathname.startsWith('/ajax/')) {
        return;
    }

    event.respondWith(
        (async () => {
            try {
                const res = await fetch(req);

                // Solo se guarda lo que sirve de respaldo. Una respuesta de
                // error no se cachea: si no, el error queda pegado.
                if (res && res.ok && res.type === 'basic') {
                    const copia = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copia)).catch(() => {});
                }

                return res;
            } catch (error) {
                const guardada = await caches.match(req);

                // Acá estaba el bug: sin esta comprobación se devolvía
                // undefined y el navegador tiraba un error ilegible.
                if (guardada) {
                    return guardada;
                }

                return paginaSinConexion();
            }
        })(),
    );
});
