<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_accounts_seeded(): void
    {
        $this->seed([ChartOfAccountSeeder::class]);
        $pt = ChartOfAccount::where('code', '1.1.06')->first();
        $cif = ChartOfAccount::where('code', '5.4')->first();
        $this->assertNotNull($pt);
        $this->assertEquals('asset', $pt->account_type->value);
        $this->assertNotNull($cif);
        $this->assertEquals('cost', $cif->account_type->value);
    }
}
