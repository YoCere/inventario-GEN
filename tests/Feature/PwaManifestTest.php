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
}
