<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Fiscal\Siat\CatalogSync;
use App\Fiscal\Siat\FiscalProvider;
use App\Fiscal\Siat\SimulatorFiscalProvider;
use App\Models\Fiscal\FiscalCatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FiscalProvider::class, new SimulatorFiscalProvider());
    }

    public function test_sync_populates_mirror(): void
    {
        app(CatalogSync::class)->sync('metodo_pago');

        $this->assertDatabaseHas('fiscal_catalog_entries', ['catalog_type' => 'metodo_pago', 'code' => '7']);
    }

    public function test_resync_updates_without_duplicating(): void
    {
        $sync = app(CatalogSync::class);
        $sync->sync('metodo_pago');
        $sync->sync('metodo_pago');

        $this->assertSame(3, FiscalCatalogEntry::where('catalog_type', 'metodo_pago')->count());
    }
}
