<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalConnectivity;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Fiscal\FiscalLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalConnectivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_flag_set_when_comms_down(): void
    {
        $sim = new SimulatorFiscalProvider();
        $sim->online = false;
        $this->app->instance(FiscalProvider::class, $sim);

        $ok = app(FiscalConnectivity::class)->check('recepcionFactura');

        $this->assertFalse($ok);
        $this->assertSame('1', Setting::get('fiscal_offline'));
    }

    public function test_offline_flag_cleared_when_comms_ok(): void
    {
        Setting::set('fiscal_offline', '1');
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());

        $ok = app(FiscalConnectivity::class)->check('recepcionFactura');

        $this->assertTrue($ok);
        $this->assertSame('0', Setting::get('fiscal_offline'));
    }

    public function test_real_binding_logs_calls_to_fiscal_logs(): void
    {
        $provider = app(FiscalProvider::class);

        $provider->obtenerCufd(0, 0);

        $this->assertDatabaseHas('fiscal_logs', [
            'service' => 'obtenerCufd',
            'success' => true,
        ]);
    }
}
