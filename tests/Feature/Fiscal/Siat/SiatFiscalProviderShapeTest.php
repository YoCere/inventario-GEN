<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\SiatFiscalProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiatFiscalProviderShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_implements_interface_and_reports_pending_environment(): void
    {
        $provider = new SiatFiscalProvider();

        $this->assertInstanceOf(\App\Fiscal\Siat\FiscalProvider::class, $provider);

        // Sin ambiente piloto configurado, cada llamada debe fallar EXPLÍCITAMENTE
        // (no silenciosamente devolver datos falsos).
        $this->expectException(\RuntimeException::class);
        $provider->obtenerCufd(0, 0);
    }
}
