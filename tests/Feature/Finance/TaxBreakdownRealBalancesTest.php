<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\SaleAccountingService;
use App\Services\FinancialStatementService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxBreakdownRealBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_breakdown_reads_real_posted_balances_without_double_counting(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);

        $user = User::factory()->admin()->create();

        $sale = Sale::create([
            'invoice_number'  => 'INV.260901.000001',
            'created_by'      => $user->id,
            'sale_date'       => now(),
            'status'          => SaleStatus::COMPLETED,
            'payment_method'  => PaymentMethod::CASH,
            'source'          => 'pos',
            'subtotal'        => 10000,
            'total_discount'  => 0,
            'total'           => 10000,
            'cash_received'   => 10000,
            'change'          => 0,
            'global_discount' => 0,
            'wants_invoice'   => true,
            'iva_amount'      => 1300,
            'it_amount'       => 300,
        ]);

        app(SaleAccountingService::class)->postCompletedSale($sale, $user->id);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $result = app(FinancialStatementService::class)->build($from, $to, true);

        $taxes = $result['estado_resultados']['taxes'];

        // Debe ser el saldo REAL de DF-IVA (2.1.11), no una estimacion (ingreso x 13%).
        $this->assertEquals(1300, $taxes['iva_debito']);
        // IT ya esta contabilizado como gasto (6.7) dentro de expense_total: no se debe restar de nuevo.
        $this->assertEquals(1300, $taxes['total_tax']);
    }
}
