<?php

namespace Tests\Feature\Finance;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_settings_seeded(): void
    {
        $this->assertSame('unipersonal', Setting::get('company_entity_type'));
        $this->assertSame('25', Setting::get('tax_iue_rate'));
        $this->assertSame('5', Setting::get('legal_reserve_rate'));
        $this->assertSame('50', Setting::get('legal_reserve_cap_pct'));
        $this->assertSame('3.4', Setting::get('accounting_legal_reserve_code'));
    }
}
