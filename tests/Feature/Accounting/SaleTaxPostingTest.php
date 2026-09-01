<?php

namespace Tests\Feature\Accounting;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\SaleAccountingService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTaxPostingTest extends TestCase
{
    use RefreshDatabase;

    private SaleAccountingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // ChartOfAccountSeeder ya incluye 1.1.01, 4.1, 5.1, 1.1.04, 2.1.11, 2.1.12 y 6.7
        // via updateOrCreate, por lo que no colisiona con lo que siembra la migracion
        // 2026_08_27_000002 (que tambien crea 6.7 de forma idempotente).
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->service = app(SaleAccountingService::class);
        $this->user = User::factory()->admin()->create();
    }

    private function makeSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'invoice_number'  => 'INV.260901.' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
            'created_by'      => $this->user->id,
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
            'iva_amount'      => 0,
            'it_amount'       => 0,
            'wants_invoice'   => false,
        ], $overrides));
    }

    private function addItem(Sale $sale, int $costCents = 8700, int $priceCents = 10000): SaleItem
    {
        $product = Product::factory()->create();

        return SaleItem::create([
            'sale_id'     => $sale->id,
            'product_id'  => $product->id,
            'quantity'    => 1,
            'cost_price'  => $costCents,
            'unit_price'  => $priceCents,
            'discount'    => 0,
            'final_price' => $priceCents,
            'subtotal'    => $priceCents,
        ]);
    }

    private function accountId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    public function test_venta_con_factura_registra_lineas_de_iva_e_it(): void
    {
        $sale = $this->makeSale([
            'total'         => 10000, // 100.00
            'iva_amount'    => 1300,  // 13.00
            'it_amount'     => 300,   // 3.00
            'wants_invoice' => true,
        ]);
        $this->addItem($sale, 8700, 10000); // costo 87.00

        $entry = $this->service->postCompletedSale($sale->fresh(), $this->user->id);

        $this->assertNotNull($entry);

        $lines = $entry->lines;
        $this->assertEquals(
            $lines->sum('debit_amount'),
            $lines->sum('credit_amount'),
            'Asiento debe cuadrar'
        );

        $ventasLine = $lines->firstWhere('chart_of_account_id', $this->accountId('4.1'));
        $this->assertNotNull($ventasLine, 'Debe existir linea de Ventas (4.1)');
        $this->assertEquals(8700, $ventasLine->credit_amount, 'Ventas = total - iva');

        $dfIvaLine = $lines->firstWhere('chart_of_account_id', $this->accountId('2.1.11'));
        $this->assertNotNull($dfIvaLine, 'Debe existir linea de DF-IVA (2.1.11)');
        $this->assertEquals(1300, $dfIvaLine->credit_amount);

        $itGastoLine = $lines->firstWhere('chart_of_account_id', $this->accountId('6.7'));
        $this->assertNotNull($itGastoLine, 'Debe existir linea de Gasto IT (6.7)');
        $this->assertEquals(300, $itGastoLine->debit_amount);

        $itPagarLine = $lines->firstWhere('chart_of_account_id', $this->accountId('2.1.12'));
        $this->assertNotNull($itPagarLine, 'Debe existir linea de IT x Pagar (2.1.12)');
        $this->assertEquals(300, $itPagarLine->credit_amount);
    }

    public function test_venta_rapida_sin_factura_mantiene_asiento_de_4_lineas(): void
    {
        $sale = $this->makeSale([
            'total'         => 10000,
            'iva_amount'    => 0,
            'it_amount'     => 0,
            'wants_invoice' => false,
        ]);
        $this->addItem($sale, 8700, 10000);

        $entry = $this->service->postCompletedSale($sale->fresh(), $this->user->id);

        $this->assertNotNull($entry);

        $lines = $entry->lines;
        $this->assertCount(4, $lines, 'Venta rapida sin factura debe tener exactamente 4 lineas');

        $fiscalIds = ChartOfAccount::whereIn('code', ['2.1.11', '2.1.12', '6.7'])->pluck('id');
        $this->assertSame(
            0,
            $lines->whereIn('chart_of_account_id', $fiscalIds)->count(),
            'No debe haber lineas fiscales en venta sin factura'
        );

        $ventasLine = $lines->firstWhere('chart_of_account_id', $this->accountId('4.1'));
        $this->assertNotNull($ventasLine);
        $this->assertEquals(10000, $ventasLine->credit_amount, 'Sin factura, Ventas = total');
    }
}
