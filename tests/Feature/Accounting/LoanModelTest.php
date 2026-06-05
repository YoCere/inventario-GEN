<?php

namespace Tests\Feature\Accounting;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_defaults(): void
    {
        $loan = Loan::create([
            'lender' => 'Banco X', 'code' => 'L-001', 'principal' => 18065228,
            'annual_rate_pct' => 5.5, 'term_months' => 60, 'start_date' => '2026-01-01', 'payment_day' => 5,
        ]);

        $this->assertEquals(LoanStatus::Active, $loan->status);
        $this->assertFalse($loan->is_opening);
        $this->assertEquals('2.2.01', $loan->liability_account_code);
        $this->assertEquals('6.3', $loan->interest_account_code);
        $this->assertEquals('1.1.02', $loan->payment_account_code);
    }
}
