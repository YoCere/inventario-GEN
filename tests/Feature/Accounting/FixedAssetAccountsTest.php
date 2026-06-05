<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_asset_accounts_seeded(): void
    {
        $this->seed([ChartOfAccountSeeder::class]);

        $accDep = ChartOfAccount::where('code', '1.2.02')->first();
        $this->assertNotNull($accDep);
        $this->assertEquals('credit', $accDep->normal_balance->value);

        $this->assertEquals('expense', ChartOfAccount::where('code', '6.4')->first()->account_type->value);
        $this->assertNotNull(ChartOfAccount::where('code', '1.2.03')->first());
    }
}
