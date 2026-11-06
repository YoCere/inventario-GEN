<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Fiscal\Invoicing\FiscalReadiness;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_missing_config_and_product_codes(): void
    {
        $unitOk = Unit::factory()->create(['sin_code' => '58']);
        $unitNo = Unit::factory()->create(['sin_code' => null]);
        Product::factory()->create(['sin_code' => '1001', 'unit_id' => $unitOk->id]);
        $bad = Product::factory()->create(['sin_code' => null, 'unit_id' => $unitNo->id]);

        $r = app(FiscalReadiness::class)->report();

        $this->assertContains('falmus_api_key', $r['missing_settings']);
        $this->assertContains('falmus_point_of_sale_id', $r['missing_settings']);
        $this->assertContains('fiscal_default_activity', $r['missing_settings']);
        $this->assertSame([$bad->id], $r['products_missing_sin_code']);
        $this->assertSame([$unitNo->id], $r['units_missing_sin_code']);
        $this->assertFalse($r['ready']);
    }

    public function test_ready_when_everything_is_set(): void
    {
        Setting::set('falmus_api_key', 'flm_test_x');
        Setting::set('falmus_point_of_sale_id', '00000000-0000-4000-8000-000000000022');
        Setting::set('fiscal_default_activity', '477300');
        $u = Unit::factory()->create(['sin_code' => '58']);
        Product::factory()->create(['sin_code' => '1001', 'unit_id' => $u->id]);

        $this->assertTrue(app(FiscalReadiness::class)->report()['ready']);
        $this->artisan('fiscal:readiness')->assertSuccessful();
    }

    public function test_ignores_inactive_products(): void
    {
        $unitNo = Unit::factory()->create(['sin_code' => null]);
        Product::factory()->create(['sin_code' => null, 'unit_id' => $unitNo->id, 'is_active' => false]);

        $r = app(FiscalReadiness::class)->report();

        $this->assertSame([], $r['products_missing_sin_code']);
        $this->assertSame([], $r['units_missing_sin_code']);
    }
}
