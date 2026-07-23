<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDailyCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_daily_cycle_ensures_cufd_catalogs_and_is_idempotent(): void
    {
        $this->artisan('fiscal:daily-cycle')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_cufd', 1);
        $this->assertDatabaseHas('fiscal_catalog_entries', ['catalog_type' => 'metodo_pago']);

        $this->artisan('fiscal:daily-cycle')->assertSuccessful();
        $this->assertDatabaseCount('fiscal_cufd', 1);
    }
}
