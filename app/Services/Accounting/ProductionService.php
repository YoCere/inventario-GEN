<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\BillOfMaterial;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(
        private ProductionCostCalculator $calculator,
        private JournalEntryService $journal,
        private StockService $stock,
    ) {
    }

    public function produce(BillOfMaterial $bom, int $quantity, string $date, int $locationId, int $userId): ProductionOrder
    {
        if ($quantity <= 0) {
            throw new \RuntimeException('La cantidad a producir debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($bom, $quantity, $date, $locationId, $userId) {
            $est = $this->calculator->estimate($bom, $quantity, $locationId);

            foreach ($est['components'] as $c) {
                $needed = (int) $c['quantity'];
                $available = (int) \App\Models\ProductStock::where('product_id', $c['component_product_id'])
                    ->where('location_id', $locationId)
                    ->value('quantity');
                if ($available < $needed) {
                    $prod = Product::find($c['component_product_id']);
                    throw new \RuntimeException("Stock insuficiente de {$prod->name}: requiere {$needed}, hay {$available}.");
                }
            }

            $order = ProductionOrder::create([
                'code'            => $this->generateCode(),
                'product_id'      => $bom->product_id,
                'bom_id'          => $bom->id,
                'quantity'        => $quantity,
                'production_date' => $date,
                'location_id'     => $locationId,
                'material_cost'   => $est['material_cost'],
                'mod_cost'        => $est['mod_cost'],
                'moi_cost'        => $est['moi_cost'],
                'cif_cost'        => $est['cif_cost'],
                'total_cost'      => $est['total_cost'],
                'unit_cost'       => $est['unit_cost'],
                'status'          => 'completed',
                'created_by'      => $userId,
            ]);

            foreach ($est['components'] as $c) {
                $needed = (int) $c['quantity'];
                $this->stock->decrementAt($c['component_product_id'], $locationId, $needed);
                $order->consumptions()->create([
                    'component_product_id' => $c['component_product_id'],
                    'quantity'             => $c['quantity'],
                    'unit_cost'            => $c['unit_cost'],
                    'total_cost'           => $c['total_cost'],
                ]);
            }

            $this->stock->incrementAt($bom->product_id, $locationId, $quantity);

            $pt  = ChartOfAccount::where('code', '1.1.06')->firstOrFail();
            $mp  = ChartOfAccount::where('code', '1.1.04')->firstOrFail();
            $mod = ChartOfAccount::where('code', '5.2')->firstOrFail();
            $moi = ChartOfAccount::where('code', '5.3')->firstOrFail();
            $cif = ChartOfAccount::where('code', '5.4')->firstOrFail();

            $period = AccountingPeriod::resolveOpenForDate($date);

            $lines = [
                ['chart_of_account_id' => $pt->id,  'debit_amount' => $est['total_cost'],    'credit_amount' => 0],
                ['chart_of_account_id' => $mp->id,  'debit_amount' => 0,                     'credit_amount' => $est['material_cost']],
            ];
            if ($est['mod_cost'] > 0) {
                $lines[] = ['chart_of_account_id' => $mod->id, 'debit_amount' => 0, 'credit_amount' => $est['mod_cost']];
            }
            if ($est['moi_cost'] > 0) {
                $lines[] = ['chart_of_account_id' => $moi->id, 'debit_amount' => 0, 'credit_amount' => $est['moi_cost']];
            }
            if ($est['cif_cost'] > 0) {
                $lines[] = ['chart_of_account_id' => $cif->id, 'debit_amount' => 0, 'credit_amount' => $est['cif_cost']];
            }

            $entry = $this->journal->createPostedEntry([
                'entry_date'           => $date,
                'accounting_period_id' => $period->id,
                'description'          => "Producción {$order->code} - {$quantity} u",
                'entry_type'           => 'normal',
                'voucher_type'         => 'traspaso',
                'created_by'           => $userId,
            ], $lines);

            $order->update(['journal_entry_id' => $entry->id]);

            Product::where('id', $bom->product_id)->update(['purchase_price' => $est['unit_cost']]);

            return $order->fresh();
        });
    }

    private function generateCode(): string
    {
        $prefix = 'PRD.' . now()->format('ymd') . '.';
        $last   = ProductionOrder::where('code', 'like', $prefix . '%')->orderByDesc('id')->first();
        $n      = $last ? ((int) substr($last->code, -4)) + 1 : 1;
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
