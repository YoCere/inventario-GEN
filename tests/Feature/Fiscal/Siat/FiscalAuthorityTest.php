<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalAuthority;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Fiscal\FiscalCufd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_current_cufd_fetches_and_caches(): void
    {
        $authority = app(FiscalAuthority::class);

        $cufd = $authority->currentCufd(0, 0);
        $this->assertNotEmpty($cufd->value);
        $this->assertDatabaseCount('fiscal_cufd', 1);

        $authority->currentCufd(0, 0);
        $this->assertDatabaseCount('fiscal_cufd', 1);
    }

    public function test_expired_cufd_is_refetched(): void
    {
        FiscalCufd::create(['value' => 'OLD', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->subHour()]);

        $cufd = app(FiscalAuthority::class)->currentCufd(0, 0);

        $this->assertNotSame('OLD', $cufd->value);
        $this->assertDatabaseCount('fiscal_cufd', 2);
    }

    public function test_ensure_cuis_creates_when_missing(): void
    {
        app(FiscalAuthority::class)->ensureCuis(0, 0);
        $this->assertDatabaseCount('fiscal_cuis', 1);
    }
}
