<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Loans\LoanScheduleTable;
use App\Models\LoanInstallment;
use App\Models\User;
use App\Services\Accounting\LoanService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function makeLoan(int $userId)
    {
        return app(LoanService::class)->registerNew([
            'lender' => 'Banco X', 'code' => 'L-S', 'principal' => 1200000, 'annual_rate_pct' => 12.0, 'term_months' => 12,
            'start_date' => '2026-01-01', 'payment_day' => 5, 'liability_account_code' => '2.2.01', 'interest_account_code' => '6.3', 'payment_account_code' => '1.1.02',
        ], $userId);
    }

    public function test_schedule_table_renders(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $admin = User::factory()->admin()->create();
        $loan = $this->makeLoan($admin->id);
        Livewire::actingAs($admin)->test(LoanScheduleTable::class, ['loan' => $loan->id])->assertOk();
    }

    public function test_pay_installment_action(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $admin = User::factory()->admin()->create();
        $loan = $this->makeLoan($admin->id);
        $first = $loan->installments()->orderBy('number')->first();
        Livewire::actingAs($admin)->test(LoanScheduleTable::class, ['loan' => $loan->id])->call('payInstallment', $first->id);
        $this->assertEquals('paid', LoanInstallment::find($first->id)->status->value);
    }
}
