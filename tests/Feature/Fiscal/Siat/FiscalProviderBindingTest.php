<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\LoggingFiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_simulator(): void
    {
        $provider = app(FiscalProvider::class);

        $this->assertInstanceOf(LoggingFiscalProvider::class, $provider);
        $this->assertInstanceOf(SimulatorFiscalProvider::class, $provider->inner());
    }

    public function test_simulator_returns_valid_shaped_cuis_and_cufd(): void
    {
        $sim = new SimulatorFiscalProvider();

        $cuis = $sim->obtenerCuis();
        $this->assertNotEmpty($cuis->value);
        $this->assertTrue($cuis->expiresAt->isFuture());

        $cufd = $sim->obtenerCufd(0, 0);
        $this->assertNotEmpty($cufd->value);
        $this->assertTrue($cufd->expiresAt->isFuture());
    }

    public function test_binding_switches_to_siat_when_setting_says_so(): void
    {
        Setting::set('fiscal_provider', 'siat');
        $provider = app(FiscalProvider::class);

        $this->assertInstanceOf(LoggingFiscalProvider::class, $provider);
        $this->assertInstanceOf(\App\Fiscal\Siat\SiatFiscalProvider::class, $provider->inner());
    }
}
