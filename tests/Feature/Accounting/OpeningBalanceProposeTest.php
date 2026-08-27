<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBalanceProposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_propose_derives_ppe_loans_and_capital_plug(): void
    {
        $category = AssetCategory::create([
            'name' => 'Muebles y Enseres',
            'useful_life_months' => 60,
            'annual_rate_pct' => 20,
            'is_deferred' => false,
            'ppe_account_code' => '1.2.01',
            'accumulated_account_code' => '1.2.02',
            'expense_account_code' => '6.2',
            'is_active' => true,
        ]);

        FixedAsset::create([
            'asset_category_id' => $category->id,
            'code' => 'AF1',
            'name' => 'Mueble',
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => 3500000,
            'useful_life_months' => 60,
            'depreciation_start_date' => '2026-01-01',
            'accumulated_depreciation' => 0,
            'is_opening' => true,
        ]);

        Loan::create([
            'lender' => 'Banco X',
            'code' => 'PR1',
            'principal' => 1000000,
            'term_months' => 12,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'is_opening' => true,
            'outstanding_balance' => 1000000,
        ]);

        $data = app(OpeningBalanceService::class)->propose('2026-01-01');

        $this->assertSame(3500000, $data->ppe);
        $this->assertSame(1000000, $data->loans);
        $this->assertSame(2500000, $data->capital); // plug = ppe 3.500.000 - loans 1.000.000
    }
}
