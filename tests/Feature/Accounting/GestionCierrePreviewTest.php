<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Setting;
use App\Services\Accounting\GestionCierreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestionCierrePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_devuelve_claves(): void
    {
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
        Setting::set('company_entity_type', 'srl');

        $p = app(GestionCierreService::class)->preview(2026);

        $this->assertArrayHasKey('utilidad_antes_impuestos', $p);
        $this->assertArrayHasKey('iue', $p);
        $this->assertArrayHasKey('reserva_legal', $p);
        $this->assertArrayHasKey('lines', $p);
        $this->assertArrayHasKey('ya_cerrada', $p);
        $this->assertFalse($p['ya_cerrada']);
        $this->assertIsArray($p['lines']);
    }
}
