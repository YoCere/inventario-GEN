<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Purchase;
use Illuminate\Support\Str;
use App\Models\FinanceCategory;
use App\Enums\FinanceCategoryType;
use App\Models\FinanceTransaction;
use Illuminate\Support\Facades\DB;
use App\DTOs\FinanceTransactionData;
use Illuminate\Support\Facades\Auth;
use App\Exceptions\FinanceTransactionException;

class FinanceTransactionService
{
    /**
     * Record income from a completed sale.
     */
    public function recordIncomeFromSale(Sale $sale): void
    {
        $category = $this->getOrCreateCategory('Ventas de Productos', FinanceCategoryType::Income);

        FinanceTransaction::updateOrCreate(
            [
                'reference_type' => Sale::class,
                'reference_id' => $sale->id,
            ],
            [
                'code' => $this->generateTransactionCode('INC'),
                'transaction_date' => $sale->sale_date,
                'finance_category_id' => $category->id,
                'amount' => $sale->total,
                'description' => 'Factura Venta: ' . $sale->invoice_number . ' - ' . ($sale->customer->name ?? 'Invitado'),
                'external_reference' => $sale->invoice_number,
                'created_by' => $sale->created_by ?? Auth::id() ?? 1,
            ]
        );
    }

    /**
     * Record expense from a paid purchase.
     */
    public function recordExpenseFromPurchase(Purchase $purchase): void
    {
        $category = $this->getOrCreateCategory('Compras de Productos', FinanceCategoryType::Expense);

        FinanceTransaction::updateOrCreate(
            [
                'reference_type' => Purchase::class,
                'reference_id' => $purchase->id,
            ],
            [
                'code' => $this->generateTransactionCode('EXP'),
                'transaction_date' => $purchase->purchase_date,
                'finance_category_id' => $category->id,
                'amount' => $purchase->total,
                'description' => 'Factura Compra: ' . $purchase->invoice_number . ' - ' . ($purchase->supplier->name ?? 'Desconocido'),
                'external_reference' => $purchase->invoice_number,
                'created_by' => $purchase->created_by ?? Auth::id() ?? 1,
            ]
        );
    }

    /**
     * Void (delete) a transaction when the source is cancelled or deleted.
     */
    public function voidTransaction($model): void
    {
        FinanceTransaction::where('reference_type', get_class($model))
            ->where('reference_id', $model->id)
            ->delete();
    }

    /**
     * Create a manual finance transaction.
     */
    public function createTransaction(FinanceTransactionData $data): FinanceTransaction
    {
        try {
            return DB::transaction(function () use ($data) {
                return FinanceTransaction::create([
                    'code' => $this->generateTransactionCode(),
                    'transaction_date' => $data->transaction_date,
                    'finance_category_id' => $data->finance_category_id,
                    'amount' => $data->amount,
                    'description' => $data->description,
                    'external_reference' => $data->external_reference,
                    'created_by' => $data->created_by,
                ]);
            });
        } catch (\Exception $e) {
            throw new FinanceTransactionException('Error al crear transacción: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing manual finance transaction.
     */
    public function updateTransaction(FinanceTransaction $transaction, FinanceTransactionData $data): FinanceTransaction
    {
        if ($transaction->reference_type) {
            throw new FinanceTransactionException('No se puede actualizar transacción generada por el sistema (Ventas/Compras).');
        }

        try {
            return DB::transaction(function () use ($transaction, $data) {
                $transaction->update([
                    'transaction_date' => $data->transaction_date,
                    'finance_category_id' => $data->finance_category_id,
                    'amount' => $data->amount,
                    'description' => $data->description,
                    'external_reference' => $data->external_reference,
                ]);
                return $transaction;
            });
        } catch (\Exception $e) {
            throw new FinanceTransactionException('Error al actualizar transacción: ' . $e->getMessage());
        }
    }

    /**
     * Delete a finance transaction directly.
     */
    public function deleteTransaction(FinanceTransaction $transaction): void
    {
        if ($transaction->reference_type) {
            throw new FinanceTransactionException('No se puede eliminar transacción generada por el sistema (Ventas/Compras). Favor cancela la fuente en su lugar.');
        }

        if ($transaction->category && in_array($transaction->category->name, ['Ventas de Productos', 'Compras de Productos'])) {
            throw new FinanceTransactionException('No se puede eliminar transacciones de categorías protegidas (Ventas de Productos/Compras de Productos).');
        }

        try {
            $transaction->delete();
        } catch (\Exception $e) {
            throw new FinanceTransactionException('Error al eliminar transacción: ' . $e->getMessage());
        }
    }

    private function getOrCreateCategory(string $name, FinanceCategoryType $type): FinanceCategory
    {
        return FinanceCategory::firstOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'slug' => Str::slug($name),
            ]
        );
    }

    private function generateTransactionCode(string $prefix = 'TRX'): string
    {
        return $prefix . '.' . now()->format('ymd') . '.' . strtoupper(Str::random(4));
    }
}
