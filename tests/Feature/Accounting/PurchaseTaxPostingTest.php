<?php

namespace Tests\Feature\Accounting;

use App\Enums\PurchaseStatus;
use App\Models\ChartOfAccount;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\PurchaseAccountingService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTaxPostingTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseAccountingService $service;
    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        // ChartOfAccountSeeder ya incluye 1.1.01, 1.1.04, 1.1.05, 2.1.01
        // via updateOrCreate, por lo que no colisiona con lo que siembra la migracion
        // 2026_08_27_000002 (que tambien crea cuentas fiscales de forma idempotente).
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->service = app(PurchaseAccountingService::class);
        $this->user = User::factory()->admin()->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function makePurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'invoice_number' => 'FC.260901.' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id'    => $this->supplier->id,
            'purchase_date'  => now(),
            'status'         => PurchaseStatus::RECEIVED,
            'payment_method' => 'cash',
            'created_by'     => $this->user->id,
            'total'          => 10000,
            'taxable_base'   => 0,
            'iva_amount'     => 0,
            'wants_invoice'  => false,
        ], $overrides));
    }

    private function accountId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    public function test_compra_con_factura_registra_linea_de_credito_fiscal_iva(): void
    {
        $purchase = $this->makePurchase([
            'total'         => 10000, // 100.00
            'iva_amount'    => 1300,  // 13.00
            'wants_invoice' => true,
        ]);

        $entry = $this->service->postPurchase($purchase->fresh(), $this->user->id);

        $this->assertNotNull($entry);

        $lines = $entry->lines;
        $this->assertEquals(
            $lines->sum('debit_amount'),
            $lines->sum('credit_amount'),
            'Asiento debe cuadrar'
        );

        $inventarioLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.04'));
        $this->assertNotNull($inventarioLine, 'Debe existir linea de Inventario (1.1.04)');
        $this->assertEquals(8700, $inventarioLine->debit_amount, 'Inventario = total - iva');

        $cfIvaLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.05'));
        $this->assertNotNull($cfIvaLine, 'Debe existir linea de CF-IVA (1.1.05)');
        $this->assertEquals(1300, $cfIvaLine->debit_amount);

        $cajaLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.01'));
        $this->assertNotNull($cajaLine, 'Debe existir linea de Caja (1.1.01)');
        $this->assertEquals(10000, $cajaLine->credit_amount);

        $itIds = ChartOfAccount::whereIn('code', ['2.1.12', '6.7'])->pluck('id');
        $this->assertSame(
            0,
            $lines->whereIn('chart_of_account_id', $itIds)->count(),
            'No debe haber lineas de IT en compras'
        );
    }

    public function test_compra_sin_factura_mantiene_asiento_de_2_lineas(): void
    {
        $purchase = $this->makePurchase([
            'total'         => 10000,
            'iva_amount'    => 0,
            'wants_invoice' => false,
        ]);

        $entry = $this->service->postPurchase($purchase->fresh(), $this->user->id);

        $this->assertNotNull($entry);

        $lines = $entry->lines;
        $this->assertCount(2, $lines, 'Compra sin factura debe tener exactamente 2 lineas');

        $inventarioLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.04'));
        $this->assertNotNull($inventarioLine);
        $this->assertEquals(10000, $inventarioLine->debit_amount, 'Sin factura, Inventario = total');

        $cajaLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.01'));
        $this->assertNotNull($cajaLine);
        $this->assertEquals(10000, $cajaLine->credit_amount);

        $cfIvaLine = $lines->firstWhere('chart_of_account_id', $this->accountId('1.1.05'));
        $this->assertNull($cfIvaLine, 'No debe existir linea de CF-IVA sin factura');
    }
}
