<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_public_and_uses_store_name(): void
    {
        Setting::set('store_name', 'Mi Tienda');

        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJsonPath('name', 'Mi Tienda');
        $response->assertJsonPath('display', 'standalone');
        $response->assertJsonCount(3, 'icons');
    }

    public function test_manifest_falls_back_when_no_store_name(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $response->assertJsonPath('name', 'Inventario');
    }

    /**
     * El service worker nunca puede contestar algo que no sea una Response.
     *
     * Pasó en producción: con la red caída y sin copia guardada,
     * `caches.match()` devuelve undefined, y responder undefined deja la
     * pantalla en blanco con "Failed to convert value to 'Response'". Peor
     * todavía, las páginas que sí estaban cacheadas seguían viéndose bien, así
     * que un servidor caído parecía "una parte del sistema que no anda".
     */
    public function test_el_service_worker_siempre_tiene_una_respuesta_que_dar(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString(
            '.catch(() => caches.match(req))',
            $sw,
            'Ese atajo devuelve undefined cuando el caché está vacío y rompe la página.'
        );
        $this->assertStringContainsString(
            'paginaSinConexion',
            $sw,
            'Tiene que existir una respuesta de último recurso que explique qué pasó.'
        );
    }

    /**
     * Lo que hace trabajo de verdad no se cachea: una respuesta vieja de
     * Livewire puede mostrar datos que ya no son ciertos, y eso es peor que un
     * error visible.
     */
    public function test_el_service_worker_no_se_mete_con_livewire(): void
    {
        $this->assertStringContainsString("startsWith('/livewire/')", file_get_contents(public_path('sw.js')));
    }
}
