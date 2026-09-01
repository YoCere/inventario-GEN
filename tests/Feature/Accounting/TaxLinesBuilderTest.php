<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Services\Accounting\TaxLinesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxLinesBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function seedTaxAccounts(): void
    {
        foreach ([
            ['2.1.11', 'DF-IVA', 'liability', 'credit'], ['1.1.05', 'CF-IVA', 'asset', 'debit'],
            ['2.1.12', 'IT x pagar', 'liability', 'credit'], ['6.7', 'Gasto IT', 'expense', 'debit'],
        ] as [$code, $name, $type, $nb]) {
            ChartOfAccount::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'level' => 3, 'account_type' => $type, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]
            );
        }
    }

    public function test_sale_tax_lines(): void
    {
        $this->seedTaxAccounts();
        $lines = app(TaxLinesBuilder::class)->saleTaxLines(1300, 300); // iva 13.00, it 3.00

        $df = collect($lines)->firstWhere('credit_amount', 1300);
        $this->assertNotNull($df);
        $itDebit = collect($lines)->firstWhere('debit_amount', 300);
        $itCredit = collect($lines)->firstWhere('credit_amount', 300);
        $this->assertNotNull($itDebit);
        $this->assertNotNull($itCredit);
        $this->assertSame(3, count($lines));
    }

    public function test_purchase_tax_lines(): void
    {
        $this->seedTaxAccounts();
        $lines = app(TaxLinesBuilder::class)->purchaseTaxLines(1300);

        $this->assertSame(1, count($lines));
        $this->assertSame(1300, $lines[0]['debit_amount']);
        $this->assertSame(0, $lines[0]['credit_amount']);
    }

    public function test_zero_amounts_produce_no_lines(): void
    {
        $this->seedTaxAccounts();
        $this->assertSame([], app(TaxLinesBuilder::class)->saleTaxLines(0, 0));
        $this->assertSame([], app(TaxLinesBuilder::class)->purchaseTaxLines(0));
    }
}
