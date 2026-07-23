<?php

namespace Tests\Feature\Fiscal\Siat;

use App\Models\Fiscal\FiscalCatalogEntry;
use App\Models\Fiscal\FiscalCufd;
use App\Models\Fiscal\FiscalCuis;
use App\Models\Fiscal\FiscalLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_persist(): void
    {
        FiscalCuis::create(['value' => 'C1', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addYear()]);
        FiscalCufd::create(['value' => 'D1', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addDay()]);
        FiscalCatalogEntry::create(['catalog_type' => 'metodo_pago', 'code' => '1', 'description' => 'Efectivo', 'synced_at' => now()]);
        FiscalLog::create(['service' => 'obtenerCufd', 'environment' => 'piloto', 'request' => '{}', 'response' => '{}', 'success' => true]);

        $this->assertDatabaseCount('fiscal_cuis', 1);
        $this->assertDatabaseCount('fiscal_cufd', 1);
        $this->assertDatabaseCount('fiscal_catalog_entries', 1);
        $this->assertDatabaseCount('fiscal_logs', 1);
    }

    public function test_cufd_scope_valid_for(): void
    {
        FiscalCufd::create(['value' => 'OLD', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->subHour()]);
        $current = FiscalCufd::create(['value' => 'NEW', 'sucursal' => 0, 'punto_venta' => 0, 'expires_at' => now()->addHour()]);

        $found = FiscalCufd::validFor(0, 0)->first();
        $this->assertSame($current->id, $found?->id);
    }
}
