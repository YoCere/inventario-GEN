<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_alerts_when_production_runs_on_simulator(): void
    {
        Queue::fake();
        Setting::set('telegram_admin_chat_id', '123');
        Setting::set('siat_environment', 'produccion');
        Setting::set('fiscal_provider', 'simulator'); // producción pero sin cambiar a siat

        // No bloquea el ciclo, pero avisa.
        $this->artisan('fiscal:daily-cycle')->assertSuccessful();

        Queue::assertPushed(SendTelegramMessage::class);
    }

    public function test_happy_path_does_not_alert(): void
    {
        Queue::fake();
        Setting::set('telegram_admin_chat_id', '123');

        $this->artisan('fiscal:daily-cycle')->assertSuccessful();

        Queue::assertNotPushed(SendTelegramMessage::class);
    }
}
