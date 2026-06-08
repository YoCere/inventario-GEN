<?php

namespace App\Services\Accounting;

use App\Models\BillOfMaterial;
use App\Services\KardexService;

class ProductionCostCalculator
{
    public function __construct(private KardexService $kardex)
    {
    }

    /**
     * Estimate production cost for a given BOM and quantity.
     *
     * Raw-material cost uses the Kardex moving-average unit cost.
     * MOD/MOI/CIF are applied as per-unit cent rates from the BOM.
     *
     * @return array{material_cost:int, mod_cost:int, moi_cost:int, cif_cost:int,
     *   total_cost:int, unit_cost:int, components: array<int, array{component_product_id:int,
     *   quantity:float, unit_cost:int, total_cost:int}>}
     */
    public function estimate(BillOfMaterial $bom, int $quantity, ?int $locationId = null): array
    {
        $components   = [];
        $materialCost = 0;

        foreach ($bom->components as $component) {
            $qty      = (float) $component->quantity_per_unit * $quantity;
            $unitCost = $this->kardex->averageUnitCost($component->component_product_id, null, $locationId);
            $total    = (int) round($qty * $unitCost);
            $materialCost += $total;
            $components[] = [
                'component_product_id' => (int) $component->component_product_id,
                'quantity'             => $qty,
                'unit_cost'            => $unitCost,
                'total_cost'           => $total,
            ];
        }

        $modCost   = $quantity * (int) $bom->mod_rate;
        $moiCost   = $quantity * (int) $bom->moi_rate;
        $cifCost   = $quantity * (int) $bom->cif_rate;
        $totalCost = $materialCost + $modCost + $moiCost + $cifCost;
        $unitCost  = $quantity > 0 ? intdiv($totalCost, $quantity) : 0;

        return [
            'material_cost' => $materialCost,
            'mod_cost'      => $modCost,
            'moi_cost'      => $moiCost,
            'cif_cost'      => $cifCost,
            'total_cost'    => $totalCost,
            'unit_cost'     => $unitCost,
            'components'    => $components,
        ];
    }
}
